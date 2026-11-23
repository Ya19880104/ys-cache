<?php
/**
 * Standalone CDN discovery checks using mocked vendor HTTP responses.
 *
 * php tests/cdn-discovery.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');
define('WP_ROCKET_CF_API_KEY', 'test-rocket-constant');

function __($text, $domain) { return $text; }
function sanitize_email($text) { return filter_var($text, FILTER_VALIDATE_EMAIL) ?: ''; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function home_url($path) { return 'https://shop.example.com' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }

function wp_safe_remote_request($url, $args) {
    $GLOBALS['requests'][] = [$url, $args];
    if ($GLOBALS['responses'] === []) {
        throw new RuntimeException('Unexpected HTTP lookup: ' . $url);
    }
    $response = array_shift($GLOBALS['responses']);
    return ['code' => $response['code'], 'body' => json_encode($response['body'])];
}

function check($actual, $expected, $label) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true));
    }
}

function reply(array $body, int $code = 200): array {
    return ['code' => $code, 'body' => $body];
}

require dirname(__DIR__) . '/src/YSCacheProviders.php';
require dirname(__DIR__) . '/src/YSCacheCdnDiscovery.php';

use YangSheep\Cache\YSCacheCdnDiscovery as Discovery;
use YangSheep\Cache\YSCacheProviders as Providers;

$cloudflare_id = '01d8db9c88bf45820000000000000000';
$cloudflare_zone = ['id' => $cloudflare_id, 'name' => 'example.com', 'status' => 'active'];
$GLOBALS['requests'] = [];
$GLOBALS['responses'] = [
    reply(['success' => true, 'result' => []]),
    reply(['success' => true, 'result' => [$cloudflare_zone]]),
];
$result = Discovery::discover(['provider' => 'cloudflare', 'token' => 'test-cloudflare']);
check($result['resource'], ['id' => $cloudflare_id, 'name' => 'example.com', 'hostname' => 'shop.example.com'], 'CF exact parent-zone lookup');
check(array_column($GLOBALS['requests'], 0), [
    'https://api.cloudflare.com/client/v4/zones?name=shop.example.com&status=active&per_page=50',
    'https://api.cloudflare.com/client/v4/zones?name=example.com&status=active&per_page=50',
], 'CF searches most specific first');
foreach ($GLOBALS['requests'] as [, $args]) {
    check($args['method'], 'GET', 'Discovery uses GET');
    check($args['redirection'], 0, 'Discovery refuses redirects');
    check($args['sslverify'], true, 'Discovery validates TLS');
    check($args['headers']['Authorization'], 'Bearer test-cloudflare', 'CF Bearer token header');
}

$GLOBALS['requests'] = [];
$manualSite = ['provider' => 'cloudflare', 'zone_id' => $cloudflare_id, 'token' => 'test-cloudflare'];
$GLOBALS['responses'] = [reply(['success' => true, 'result' => $cloudflare_zone])];
check(Discovery::discover($manualSite)['resource']['hostname'], 'shop.example.com', 'Manual ID defaults to the site hostname for HTML layers');
check($GLOBALS['requests'][0][0], 'https://api.cloudflare.com/client/v4/zones/' . $cloudflare_id, 'Manual site ID uses the detail endpoint');
$GLOBALS['responses'] = [reply(['success' => true, 'result' => array_replace($cloudflare_zone, ['name' => 'unrelated.example.net'])])];
check(Discovery::discover($manualSite)['status'], 'error', 'A manual ID cannot validate an unrelated site zone with an empty hostname');
$GLOBALS['responses'] = [reply(['success' => true, 'result' => $cloudflare_zone, 'errors' => [['code' => 1001]]])];
check(Discovery::discover($manualSite)['status'], 'error', 'CF detail rejects a successful envelope containing API errors');
$GLOBALS['responses'] = [reply(['success' => true, 'result' => [$cloudflare_zone], 'errors' => [['code' => 1001]]])];
check(Discovery::discover(['provider' => 'cloudflare', 'hostname' => 'example.com', 'token' => 'test-cloudflare'])['status'], 'error', 'CF list rejects a successful envelope containing API errors');
$GLOBALS['requests'] = [];
check(Discovery::discover($manualSite + ['scope' => 'code'])['message'], 'Enter the CDN delivery hostname for this asset layer.', 'Asset ID lookup still requires its actual delivery hostname');
check($GLOBALS['requests'], [], 'Missing asset hostname issues no provider request');

$row = ['provider' => 'cloudflare', 'zone_id' => $cloudflare_id, 'hostname' => 'shop.example.com', 'auth_email' => 'test@example.com'];
$saved = $row + ['token_constant' => 'WP_ROCKET_CF_API_KEY'];
$GLOBALS['requests'] = [];
$GLOBALS['responses'] = [reply(['success' => true, 'result' => $cloudflare_zone])];
$result = Discovery::discover($row, $saved);
check($result['status'], 'ok', 'CF manual ID and saved Rocket key');
check($GLOBALS['requests'][0][1]['headers']['X-Auth-Key'], 'test-rocket-constant', 'CF uses imported legacy global key');
check($GLOBALS['requests'][0][1]['headers']['X-Auth-Email'], 'test@example.com', 'CF legacy email header');
check(str_contains(json_encode($result), 'test-rocket-constant'), false, 'Saved credential is never in result');
$GLOBALS['requests'] = [];
check(Discovery::discover(array_replace($row, ['hostname' => 'other.example.com']), $saved)['status'], 'error', 'Changed host requires fresh credential');
check(Discovery::discover($row + ['token_constant' => 'WP_ROCKET_CF_API_KEY'])['status'], 'error', 'Untrusted input cannot select Rocket key');
check(Discovery::discover($row + ['clear_token' => true], $saved)['status'], 'error', 'Explicit clear prevents stored credential use');
check($GLOBALS['requests'], [], 'Missing credentials never issue provider requests');

$bunny_zone = ['Id' => 47, 'Name' => 'Images', 'Enabled' => true, 'Hostnames' => [['Value' => 'images.example.com']]];
$row = ['provider' => 'bunny', 'role' => 'images', 'hostname' => 'images.example.com', 'token' => 'test-bunny'];
$GLOBALS['requests'] = [];
$GLOBALS['responses'] = [
    reply(['Items' => [['Id' => 46, 'OriginUrl' => 'https://images.example.com']], 'HasMoreItems' => true]),
    reply(['Items' => [$bunny_zone], 'HasMoreItems' => false]),
];
$result = Discovery::discover($row);
check($result['resource'], ['id' => '47', 'name' => 'Images', 'hostname' => 'images.example.com'], 'Bunny exact hostname across pages');
check(array_column($GLOBALS['requests'], 0), [
    'https://api.bunny.net/pullzone?page=1&perPage=50',
    'https://api.bunny.net/pullzone?page=2&perPage=50',
], 'Bunny follows bounded vendor pagination');
check($GLOBALS['requests'][0][1]['headers']['AccessKey'], 'test-bunny', 'Bunny access key header');
$GLOBALS['responses'] = [reply(['Items' => [$bunny_zone, array_replace($bunny_zone, ['Id' => 48])], 'HasMoreItems' => false])];
check(Discovery::discover($row)['status'], 'error', 'Bunny multiple zones require manual selection');
$GLOBALS['responses'] = [reply($bunny_zone)];
check(Discovery::discover($row + ['zone_id' => '48'])['status'], 'error', 'Bunny rejects mismatched detail response');
$GLOBALS['responses'] = [reply($bunny_zone)];
check(Discovery::discover(array_replace($row, ['hostname' => 'other.example.com', 'zone_id' => '47']))['status'], 'error', 'Bunny rejects unrelated resource ID');

$gcore_zone = [
    'id' => 9223372036854775807,
    'cname' => 'static.example.org',
    'active' => true,
    'secondaryHostnames' => ['static.example.net'],
];
$row = ['provider' => 'gcore', 'role' => 'images', 'hostname' => 'static.example.net', 'token' => 'test-gcore'];
$GLOBALS['requests'] = [];
$GLOBALS['responses'] = [reply(['count' => 1, 'next' => null, 'results' => [$gcore_zone]])];
$result = Discovery::discover($row);
check($result['resource'], ['id' => '9223372036854775807', 'name' => 'static.example.org', 'hostname' => 'static.example.net'], 'Gcore finds an exact secondary delivery hostname');
check($GLOBALS['requests'][0][0], 'https://api.gcore.com/cdn/resources?deleted=false&limit=50&offset=0', 'Gcore paginated resource list URL');
check($GLOBALS['requests'][0][1]['headers']['Authorization'], 'APIKey test-gcore', 'Gcore customer key header');
$GLOBALS['responses'] = [reply(['message' => 'Unexpected error with test-gcore'], 403)];
$result = Discovery::discover($row);
check($result['http'], 403, 'Gcore access error returned to admin');
check(str_contains(json_encode($result), 'test-gcore'), false, 'Provider error bodies stay private');

$GLOBALS['requests'] = [];
$GLOBALS['responses'] = [reply($gcore_zone)];
$result = Discovery::discover($row + ['zone_id' => '9223372036854775807']);
check($result['status'], 'ok', 'Gcore validates the supplied resource ID and secondary hostname');
check($GLOBALS['requests'][0][0], 'https://api.gcore.com/cdn/resources/9223372036854775807', 'Known ID uses a single resource detail lookup');

$GLOBALS['requests'] = [];
$cname = [
    'provider' => 'cname',
    'scope' => 'static',
    'hostname' => 'assets.example.com',
    'enabled' => true,
    'zone_id' => '47',
    'token' => 'unused-cname-key',
    'rewrite_urls' => true,
];
check(Discovery::discover($cname)['status'], 'error', 'CNAME has no provider resource discovery');
$cname_settings = Providers::sanitize(['cdn' => [$cname]]);
check($cname_settings['cdn'][0]['enabled'], false, 'CNAME purge cannot be enabled');
check($cname_settings['cdn'][0]['token'], '', 'CNAME does not store an API credential');
check(Providers::hasUrlRewriting($cname_settings), true, 'CNAME asset rewriting remains available');
check(Providers::purge($cname_settings, 'cdn')[0]['status'], 'skipped', 'CNAME-only setup skips API purge');
check($GLOBALS['requests'], [], 'CNAME setup and purge do not contact a CDN API');
check($GLOBALS['responses'], [], 'All expected requests consumed');

echo "CDN discovery checks passed.\n";
