<?php
/**
 * Standalone provider checks. The HTTP transport is mocked throughout.
 *
 * php tests/providers.php
 * php tests/providers.php /path/to/wordpress/wp-includes
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');
define('WP_ROCKET_CF_API_KEY', 'unit-rocket-constant');
$core = $argv[1] ?? '';
if ($core !== '') {
    foreach (['class-wp-token-map.php', 'utf8.php'] as $file) {
        if (is_file($core . '/' . $file)) {
            require $core . '/' . $file;
        }
    }
    $html_api = is_dir($core . '/html-api') ? $core . '/html-api' : $core;
    foreach ([
        'html5-named-character-references.php',
        'class-wp-html-decoder.php',
        'class-wp-html-span.php',
        'class-wp-html-attribute-token.php',
        'class-wp-html-doctype-info.php',
        'class-wp-html-text-replacement.php',
        'class-wp-html-tag-processor.php',
    ] as $file) {
        if (is_file($html_api . '/' . $file)) {
            require $html_api . '/' . $file;
        }
    }
}

function __($value, $domain) { return $value; }
function _n($single, $plural, $number, $domain) { return $number === 1 ? $single : $plural; }
function wp_kses_uri_attributes() { return ['href','src']; }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url($text) { return esc_attr($text); }
function sanitize_email($text) { return filter_var($text, FILTER_VALIDATE_EMAIL) ?: ''; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_get_upload_dir() { return ['baseurl' => 'https://dev.example.com/wp-content/uploads']; }
function home_url($path) { return 'https://dev.example.com' . $path; }
function content_url($path) { return 'https://dev.example.com/wp-content' . $path; }
function includes_url() { return 'https://dev.example.com/wp-includes/'; }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['http'][] = [$url, $args];
    if (!empty($GLOBALS['transport_error'][$url])) {
        return new stdClass();
    }
    $status = $args['method'] === 'GET' ? 200 : (str_contains($url, 'gcore.com') ? 201 : (str_ends_with($url, '/purgeCache') ? 204 : 200));
    $body = $args['method'] === 'GET'
        ? ($GLOBALS['resource_reply'][$url] ?? $GLOBALS['bunny_zone'][$url] ?? '')
        : (str_contains($url, 'cloudflare.com') ? ($GLOBALS['cloudflare_reply'] ?? '{"success":true}') : '');
    if ($args['method'] === 'POST' && str_contains($url, 'gcore.com')) {
        $body = $GLOBALS['gcore_reply'] ?? $args['body'];
    }
    return [
        'status' => $GLOBALS['http_status'][$url] ?? $status,
        'body' => $body,
        'content_type' => $GLOBALS['response_type'] ?? 'application/json',
    ];
}
function is_wp_error($response) { return $response instanceof stdClass; }
function wp_json_encode($data) { return json_encode($data); }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_header($response, $header) { return $response['content_type']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function check($actual, $expected, $label) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true));
    }
}

final class FakeNativePurge {
    public static function purge(array $urls): array {
        $GLOBALS['http'][] = ['litespeed-origin', $urls];
        return ['layer' => 'LiteSpeed Cache', 'status' => $GLOBALS['litespeed_status'] ?? 'ok'];
    }
}
class_alias(FakeNativePurge::class, 'YangSheep\Cache\YSCacheLiteSpeed');

final class FakeNginxPurge {
    public static function purge(array $urls): array {
        $GLOBALS['http'][] = ['nginx-origin', $urls];
        return ['layer' => 'Nginx', 'status' => $GLOBALS['nginx_status'] ?? 'ok'];
    }
}
class_alias(FakeNginxPurge::class, 'YangSheep\Cache\YSCacheNginx');

require dirname(__DIR__) . '/src/YSCacheEarlyStore.php';
require dirname(__DIR__) . '/src/YSCacheProviders.php';
use YangSheep\Cache\YSCacheProviders as Providers;

$settings = Providers::sanitize([
    'enhance' => ['enabled' => true, 'url' => 'https://enhance.example.net/api/', 'domain_id' => 'e2f3837f-314e-403b-aa6b-8d523513deed', 'token' => 'unit-enhance'],
    'cdn' => [
        ['provider' => 'bunny', 'enabled' => true, 'zone_id' => '9128', 'hostname' => 'images.example.com', 'role' => 'images', 'rewrite_images' => true, 'token' => 'unit-bunny'],
        ['provider' => 'gcore', 'enabled' => true, 'zone_id' => '4912', 'token' => 'unit-gcore$foo'],
        ['provider' => 'cloudflare', 'enabled' => true, 'zone_id' => '0123456789abcdef0123456789abcdef', 'token_constant' => 'WP_ROCKET_CF_API_KEY'],
    ],
]);
if ($core !== '') {
    $rendered = Providers::rewriteHtml(
        '<html><img src="/wp-content/uploads/2026/11/img.jpg" srcset="/wp-content/uploads/2026/11/img.jpg 1x, https://dev.example.com/wp-content/uploads/2026/11/img-big.webp 2x">' .
        '<img srcset="/wp-content/uploads/2026/11/img.jpg, /wp-content/uploads/2026/11/img-big.webp">' .
        '<img src="https://unrelated.example.com/wp-content/uploads/img.jpg"><img src="/wp-content/uploads/img.jpg?token=unit">' .
        '<img src="/wp-content/uploads/2026/11/%2e%2e/private/img.jpg"><img src="/wp-content/uploads/woocommerce_uploads/img.jpg">' .
        '<link rel="preload" as="image" href="/wp-content/uploads/p.webp"><link rel="stylesheet" href="/wp-content/uploads/a.webp">' .
        '<script>const fixture = "<img src=/wp-content/uploads/script.jpg>";</script></html>',
        $settings
    );
    check(substr_count($rendered, 'https://images.example.com/'), 6, 'Expected upload references only');
    check(str_contains($rendered, '/wp-content/uploads/img.jpg?token=unit'), true, 'Signed URL unchanged');
    check(str_contains($rendered, '/wp-content/uploads/woocommerce_uploads/img.jpg'), true, 'Protected upload unchanged');
    check(str_contains($rendered, '/wp-content/uploads/2026/11/%2e%2e/private/img.jpg'), true, 'Escaped path unchanged');
    check(str_contains($rendered, 'src=/wp-content/uploads/script.jpg'), true, 'Script source preserved');

    $fixture = '<html lang="zh-TW"><link rel="canonical" href="https://dev.example.com/zh/card/">' .
        '<script src="/wp-includes/js/jquery/jquery.min.js?ver=3.7.1"></script>' .
        '<link rel="stylesheet" href="/wp-content/themes/example/style.css?ver=1.2">' .
        '<link rel="preload" as="font" href="/wp-content/themes/example/font.woff2" crossorigin>' .
        '<link rel="preload" as="image" href="/wp-content/uploads/zh/preload.webp">' .
        '<picture><source srcset="/wp-content/uploads/zh/large.webp 2x"><img src="/wp-content/uploads/zh/hero.jpg" alt="翻譯後的圖片"></picture>' .
        '<img data-src="/wp-content/plugins/ys-cart/assets/cart.svg" src="/wp-content/uploads/zh/fallback.png">' .
        '<video preload="none" src="/wp-content/uploads/media/video.mp4"></video>' .
        '<a href="/zh/account/">會員</a><a href="/wp-content/uploads/zh/download.jpg">Download</a>' .
        '<img src="https://elsewhere.example.net/wp-content/uploads/third-party.jpg">' .
        '<img src="/wp-content/uploads/zh/verify.jpg?token=unit">' .
        '<img src="/wp-content/uploads/ys-cart-files/restricted.jpg">' .
        '<img src="/wp-content/uploads/zh/%2e%2e/private/verify.jpg">' .
        '<img src="/wp-content/uploads/zh/split%2fverify.jpg">' .
        '<img src="https://dev.example.com:8443/wp-content/uploads/zh/different-port.jpg">' .
        '<img src="/wp-content/uploads/zh/verify.jpg" crossorigin="use-credentials">' .
        '<script src="/wp-content/themes/example/verified.js" integrity="sha256-test"></script>' .
        '<script src="/wp-content/themes/example/signed.js?ver=1&amp;signature=test"></script>' .
        '<link rel="preload" as="fetch" href="/wp-json/example/list/">' .
        '<div style="background:url(/wp-content/uploads/zh/inline.jpg)"></div></html>';

    foreach (['all' => 9, 'html' => 0, 'code' => 2, 'static' => 9, 'html_code' => 2] as $scope => $count) {
        $config = Providers::sanitize(['cdn' => [
            ['provider' => 'cloudflare', 'hostname' => 'assets.example.com', 'scope' => $scope, 'rewrite_urls' => true],
        ]]);
        $rendered = Providers::rewriteHtml($fixture, $config);
        check(substr_count($rendered, 'https://assets.example.com/'), $count, 'Asset URLs for ' . $scope);
        check(str_contains($rendered, 'href="https://dev.example.com/zh/card/"'), true, 'Canonical URL retained');
        check(str_contains($rendered, 'href="/zh/account/"'), true, 'Account navigation retained');
        check(str_contains($rendered, 'href="/wp-content/uploads/zh/download.jpg"'), true, 'Download link retained');
        check(str_contains($rendered, 'alt="翻譯後的圖片"'), true, 'Translated caption retained');
        check(str_contains($rendered, 'src="/wp-content/uploads/ys-cart-files/restricted.jpg"'), true, 'Private commerce uploads retained');
        check(str_contains($rendered, 'src="/wp-content/themes/example/verified.js"'), true, 'Integrity source retained');
        check(str_contains($rendered, 'src="/wp-content/themes/example/signed.js?ver=1&amp;signature=test"'), true, 'Signed code URL retained');
        check(Providers::rewriteHtml($rendered, $config), $rendered, 'Rewriting is idempotent');
    }

    $split = Providers::sanitize(['cdn' => [
        ['provider' => 'cname', 'hostname' => 'code.example.com', 'scope' => 'code', 'rewrite_urls' => true],
        ['provider' => 'cname', 'hostname' => 'media.example.com', 'scope' => 'static', 'rewrite_urls' => true],
        ['provider' => 'cname', 'hostname' => 'last.example.com', 'scope' => 'static', 'rewrite_urls' => true],
    ]]);
    $rendered = Providers::rewriteHtml($fixture, $split);
    check(substr_count($rendered, 'https://code.example.com/'), 2, 'Code goes through the first applicable CNAME');
    check(substr_count($rendered, 'https://media.example.com/'), 7, 'Static images, fonts and video go through their CNAME');
    check(substr_count($rendered, 'https://last.example.com/'), 0, 'Only the first applicable CNAME rewrites a resource');
    echo "Native WordPress HTML Tag Processor checks passed.\n";
}

$bunnyZone = 'https://api.bunny.net/pullzone/9128';
$assetBunny = '{"Id":9128,"IgnoreQueryStrings":false,"Hostnames":[{"Value":"images.example.com"}]}';
$GLOBALS['bunny_zone'][$bunnyZone] = $assetBunny;
$GLOBALS['http'] = [];
$results = Providers::purge($settings);
check(array_column($results, 'status'), ['ok','ok','ok','ok'], 'Full purge results');
check(array_column($GLOBALS['http'], 0), [
    'https://enhance.example.net/api/v2/domains/e2f3837f-314e-403b-aa6b-8d523513deed/nginx_fastcgi',
    $bunnyZone,
    'https://api.bunny.net/pullzone/9128/purgeCache',
    'https://api.gcore.com/cdn/resources/4912/purge',
    'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache',
], 'Origin-to-edge call order');
check($GLOBALS['http'][0][1]['method'], 'DELETE', 'Enhance method');
check($GLOBALS['http'][0][1]['redirection'], 0, 'Enhance refuses redirects');
check($GLOBALS['http'][1][1]['method'], 'GET', 'Bunny Pull Zone checks the configured hostname');
check($GLOBALS['http'][2][1]['body'], '{}', 'Bunny pull-zone purge requires an empty JSON object');
check($GLOBALS['http'][3][1]['headers']['Authorization'], 'APIKey unit-gcore$foo', 'Gcore auth');
check(json_decode($GLOBALS['http'][3][1]['body'], true), ['paths' => []], 'Gcore full payload');
check($GLOBALS['http'][4][1]['headers']['Authorization'], 'Bearer unit-rocket-constant', 'CF imported token resolution');
check(json_decode($GLOBALS['http'][4][1]['body'], true), ['hosts' => ['dev.example.com']], 'Cloudflare host purge');

$enhance_endpoint = $GLOBALS['http'][0][0];
$bunny_endpoint = $GLOBALS['http'][2][0];
$gcore_endpoint = $GLOBALS['http'][3][0];
$GLOBALS['http'] = [];
$GLOBALS['http_status'] = [$enhance_endpoint => 503];
check(array_column(Providers::purge($settings), 'status'), ['error'], 'Failed Enhance purge stops the chain');
check(array_column($GLOBALS['http'], 0), [$enhance_endpoint], 'No CDN call after a failed origin cache purge');
$GLOBALS['http'] = [];
$GLOBALS['http_status'] = [$gcore_endpoint => 429];
check(array_column(Providers::purge($settings), 'status'), ['ok', 'ok', 'error'], 'Failure stops the chain at the affected CDN');
check(array_column($GLOBALS['http'], 0), [$enhance_endpoint, $bunnyZone, $bunny_endpoint, $gcore_endpoint], 'Outer CDN skipped after inner CDN failure');
unset($GLOBALS['http_status']);

$liteSpeed = $settings;
$liteSpeed['litespeed'] = ['enabled' => true];
$cloudflare_endpoint = 'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache';
$GLOBALS['http'] = [];
check(array_column(Providers::purge($liteSpeed), 'status'), ['ok', 'ok', 'ok', 'ok', 'ok'], 'Full chain accepts native origin dispatch before the API layers');
check(array_column($GLOBALS['http'], 0), ['litespeed-origin', $enhance_endpoint, $bunnyZone, $bunny_endpoint, $gcore_endpoint, $cloudflare_endpoint], 'LiteSpeed is the first origin stage');
check($GLOBALS['http'][0][1], [], 'Full purge requests the complete LiteSpeed site');

$GLOBALS['http'] = [];
$GLOBALS['litespeed_status'] = 'error';
check(array_column(Providers::purge($liteSpeed), 'status'), ['error'], 'Unconfirmed LiteSpeed dispatch holds all outer layers');
check(array_column($GLOBALS['http'], 0), ['litespeed-origin'], 'No remote API call when LiteSpeed dispatch is unconfirmed');
unset($GLOBALS['litespeed_status']);

$GLOBALS['http'] = [];
$liteSpeed['enhance']['enabled'] = false;
$page = 'https://dev.example.com/zh/hello/';
check(array_column(Providers::purge($liteSpeed, 'urls', [$page]), 'status'), ['ok', 'skipped', 'ok', 'ok'], 'Single translated page targets LiteSpeed before the HTML CDN layers');
check(array_column($GLOBALS['http'], 0), ['litespeed-origin', $gcore_endpoint, $cloudflare_endpoint], 'Translated page leaves the image-only layer alone');
check($GLOBALS['http'][0][1], [$page], 'Native purge receives the original page URL');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['files' => [$page]], 'Cloudflare receives the translated page URL');
check(Providers::purge($liteSpeed, 'urls'), [], 'An empty URL list never widens to a full-site LiteSpeed purge');
check(Providers::sanitize([], $liteSpeed)['litespeed']['enabled'], true, 'An omitted LiteSpeed control preserves its stored setting');

$nginx = $settings;
$nginx['enhance']['enabled'] = false;
$nginx['nginx'] = ['enabled' => true, 'rules_verified' => true];
check(Providers::sanitize([], $nginx)['nginx'], $nginx['nginx'], 'Existing Nginx capability is retained when omitted');
check(Providers::targetBatchLimit($nginx), 5, 'Nginx serial page purges bound the background batch');
$GLOBALS['http'] = [];
check(array_column(Providers::purge($nginx), 'status'), ['ok', 'ok', 'ok', 'ok'], 'Full Nginx integration confirms origin and CDN clearing');
check(array_column($GLOBALS['http'], 0), ['nginx-origin', $bunnyZone, $bunny_endpoint, $gcore_endpoint, $cloudflare_endpoint], 'Full Nginx clearing precedes every CDN request');
check($GLOBALS['http'][0][1], [], 'Site clearing is explicit');
$GLOBALS['http'] = [];
check(array_column(Providers::purge($nginx, 'urls', [$page]), 'status'), ['ok', 'skipped', 'ok', 'ok'], 'Nginx clears a translated page before HTML CDN layers');
check($GLOBALS['http'][0], ['nginx-origin', [$page]], 'Nginx receives the exact translated page only');
$GLOBALS['http'] = [];
$GLOBALS['nginx_status'] = 'error';
check(array_column(Providers::purge($nginx, 'urls', [$page]), 'status'), ['error'], 'Unconfirmed Nginx clearing stops URL and CDN forwarding');
check(array_column($GLOBALS['http'], 0), ['nginx-origin'], 'A failed Nginx stage leaves every edge untouched');
unset($GLOBALS['nginx_status']);

$auto = Providers::sanitize([
    'enhance' => ['enabled' => true, 'delegate_html' => true, 'rules_verified' => true],
    'cdn' => [
        ['provider' => 'bunny', 'enabled' => true],
        ['provider' => 'gcore', 'enabled' => false, 'auto_purge' => 'off', 'zone_id' => '2'],
        ['provider' => 'cname', 'enabled' => true, 'auto_purge' => true, 'hostname' => 'static.example.com'],
    ],
]);
check($auto['enhance']['delegate_html'], true, 'Delegated HTML flag is stored');
check(isset($auto['enhance']['auto_purge']), false, 'Enhance inherits the site setting');
check($auto['enhance']['rules_verified'], true, 'Verified exclusion flag is stored');
check(array_column($auto['cdn'], 'auto_purge'), ['inherit', 'off', 'inherit'], 'CDN layers can follow or turn off automatic purge independently');
check(Providers::autoPurgePolicy(['auto_purge' => false]), 'off', 'Legacy explicit opt-out is preserved');
check(Providers::scope($settings['cdn'][0]), 'static', 'Legacy image delivery uses static asset scope');
check($settings['cdn'][0]['rewrite_images'] && !$settings['cdn'][0]['rewrite_urls'], true, 'A legacy image row does not start rewriting code');
check(Providers::scope(['provider' => 'cname', 'scope' => 'html']), 'static', 'CNAME cannot register an HTML-proxy scope');
check(Providers::scopeSupportsHtml(['scope' => 'html_code']), true, 'HTML and JS/CSS scope can serve documents');
check(Providers::scopeSupportsHtml(['scope' => 'static']), false, 'Static CDN scope never serves documents');

$html_row = ['provider' => 'bunny', 'zone_id' => '123', 'token' => 'unit-bunny', 'enabled' => true, 'html_cache_verified' => true];
$verified = Providers::sanitize(['cdn' => [
    $html_row + ['scope' => 'html_code', 'auto_purge' => 'off'],
    $html_row + ['scope' => 'all', 'hostname' => 'dev.example.com'],
    $html_row + ['scope' => 'static'],
    $html_row + ['scope' => 'html', 'hostname' => 'assets.example.com'],
    $html_row + ['scope' => 'html', 'hostname' => 'https://dev.example.com/path/'],
]]);
check(array_column($verified['cdn'], 'html_cache_verified'), [true, true, false, false, false], 'Verified HTML requires a matching hostname + eligible scope');
check(array_column($verified['cdn'], 'auto_purge'), ['off', 'inherit', 'inherit', 'inherit', 'inherit'], 'Verified HTML respects the CDN purge policy');
$missingToken = Providers::sanitize(['cdn' => [array_replace($html_row, ['token' => '', 'scope' => 'html'])]]);
check($missingToken['cdn'][0]['html_cache_verified'], false, 'Verified HTML requires provider credentials');

$GLOBALS['http'] = [];
$urls = ['/hello/', '/wp-content/uploads/2026/11/img.jpg', 'https://outside.example.org/must-skip', '/archive/%2a'];
$results = Providers::purge($settings, 'urls', $urls);
check(array_column($results, 'status'), ['ok','ok','ok'], 'Targeted results');
check($GLOBALS['http'][1][0], 'https://api.bunny.net/purge?url=https%3A%2F%2Fimages.example.com%2Fwp-content%2Fuploads%2F2026%2F11%2Fimg.jpg&exactPath=true&async=false', 'Image layer targeted URL');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['urls' => ['/hello/', '/wp-content/uploads/2026/11/img.jpg']], 'Gcore targeted paths');
check(json_decode($GLOBALS['http'][3][1]['body'], true), ['files' => ['https://dev.example.com/hello/', 'https://dev.example.com/wp-content/uploads/2026/11/img.jpg']], 'Cloudflare targeted site URLs');

$bunnyPage = Providers::sanitize(['cdn' => [[
    'provider' => 'bunny', 'enabled' => true, 'zone_id' => '9128', 'token' => 'unit-bunny', 'scope' => 'html',
]]]);
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":true,"Hostnames":[{"Value":"dev.example.com"}]}';
$GLOBALS['http'] = [];
check(Providers::purge($bunnyPage, 'urls', ['/hello/'])[0]['status'], 'error', 'Bunny cannot widen a slash URL to a wildcard purge');
check(array_column($GLOBALS['http'], 0), [$bunnyZone], 'No purge is sent unless exact-path support is confirmed');
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":false,"Hostnames":[{"Value":"dev.example.com"}]}';
$GLOBALS['http'] = [];
check(Providers::purge($bunnyPage, 'urls', ['/hello/'])[0]['status'], 'ok', 'Bunny purges a known exact slash URL');
check(array_column($GLOBALS['http'], 0), [$bunnyZone, 'https://api.bunny.net/purge?url=https%3A%2F%2Fdev.example.com%2Fhello%2F&exactPath=true&async=false'], 'Verified zone then exact purge');
$GLOBALS['bunny_zone'][$bunnyZone] = $assetBunny;

$scoped = Providers::sanitize(['cdn' => [
    ['provider' => 'bunny', 'enabled' => true, 'hostname' => 'code.example.com', 'scope' => 'code'],
    ['provider' => 'gcore', 'enabled' => true, 'hostname' => 'images.example.com', 'scope' => 'static', 'zone_id' => '123', 'token' => 'unit-gcore'],
    ['provider' => 'cloudflare', 'enabled' => true, 'scope' => 'html', 'zone_id' => '0123456789abcdef0123456789abcdef', 'token' => 'unit-cloudflare'],
]]);
$GLOBALS['http'] = [];
check(array_column(Providers::purge($scoped, 'urls', ['/zh/hello/']), 'status'), ['skipped', 'skipped', 'ok'], 'Asset-only layers do not block page purge');
check(count($GLOBALS['http']), 1, 'Only the HTML CDN receives the page purge');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['files' => ['https://dev.example.com/zh/hello/']], 'HTML purge uses the canonical website host');

$GLOBALS['http'] = [];
$scoped['cdn'][0]['enabled'] = false;
$results = Providers::purge($scoped, 'urls', ['/wp-content/uploads/zh/hero.jpg', '/wp-includes/js/jquery/jquery.min.js?ver=3.7.1']);
check(array_column($results, 'status'), ['ok', 'skipped'], 'Static assets skip the HTML-only layer');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['urls' => [
    '/wp-content/uploads/zh/hero.jpg', '/wp-includes/js/jquery/jquery.min.js?ver=3.7.1',
]], 'Static purge includes images and versioned JavaScript');

$cfZoneA = '0123456789abcdef0123456789abcdef';
$cfZoneB = '1123456789abcdef0123456789abcdef';
$cfResourceA = 'https://api.cloudflare.com/client/v4/zones/' . $cfZoneA;
$cfResourceB = 'https://api.cloudflare.com/client/v4/zones/' . $cfZoneB;
$GLOBALS['resource_reply'][$cfResourceA] = json_encode(['success' => true, 'result' => ['id' => $cfZoneA, 'name' => 'example.com', 'status' => 'active']]);
$GLOBALS['resource_reply'][$cfResourceB] = json_encode(['success' => true, 'result' => ['id' => $cfZoneB, 'name' => 'example.net', 'status' => 'active']]);
$cf = ['provider' => 'cloudflare', 'enabled' => true, 'scope' => 'html', 'token' => 'unit-cloudflare', 'zone_id' => $cfZoneA];
$aliasConfig = Providers::sanitize([
    'alias_hosts' => "WWW.Example.COM.\nwww.example.com, dev.example.com\nhttps://invalid.example.com/path\n127.0.0.1\n*.example.com",
    'cdn' => [$cf],
]);
check($aliasConfig['alias_hosts'], ['www.example.com', 'dev.example.com'], 'Normalized, unambiguous DNS hostnames');
check(Providers::sanitize([], $aliasConfig)['alias_hosts'], $aliasConfig['alias_hosts'], 'An absent alias input retains configured aliases');
check(Providers::htmlHostsForLayer($aliasConfig, $aliasConfig['cdn'][0]), ['dev.example.com', 'www.example.com'], 'Canonical host deduplicated in alias groups');
$GLOBALS['http'] = [];
$_SERVER['HTTP_HOST'] = 'unconfigured.example.org';
check(array_column(Providers::purge($aliasConfig, 'urls', ['/zh/product/', 'https://www.example.com/zh/product/']), 'status'), ['ok'], 'Cloudflare accepts both deliberate aliases');
check(array_column($GLOBALS['http'], 0), [$cfResourceA, $cloudflare_endpoint], 'Cloudflare verifies the configured zone and submits its files');
check(json_decode($GLOBALS['http'][1][1]['body'], true), ['files' => [
    'https://dev.example.com/zh/product/', 'https://www.example.com/zh/product/',
]], 'Canonical and alias paths deduplicated, untrusted request host ignored');
unset($_SERVER['HTTP_HOST']);
$GLOBALS['http'] = [];
check(Providers::purge($aliasConfig, 'cdn')[0]['status'], 'ok', 'Default full purge remains hostname-scoped');
check(json_decode($GLOBALS['http'][1][1]['body'], true), ['hosts' => ['dev.example.com', 'www.example.com']], 'Full Cloudflare exact host list');
$full = $aliasConfig;
$full['cdn'][0]['purge_entire_zone'] = true;
$GLOBALS['http'] = [];
check(Providers::purge($full, 'cdn')[0]['status'], 'ok', 'Explicit entire-zone Cloudflare setting remains supported');
check(json_decode($GLOBALS['http'][1][1]['body'], true), ['purge_everything' => true], 'Full Cloudflare zone only when selected');

$crossZone = Providers::sanitize([
    'alias_hosts' => ['shop.example.net'],
    'cdn' => [$cf, array_replace($cf, ['zone_id' => $cfZoneB, 'hostname' => 'shop.example.net'])],
]);
$GLOBALS['http'] = [];
check(array_column(Providers::purge($crossZone, 'urls', ['/shop/item/']), 'status'), ['ok', 'ok'], 'An alias assigned to a different zone does not block the canonical zone');
check(array_column($GLOBALS['http'], 0), [$cloudflare_endpoint, $cfResourceB, $cfResourceB . '/purge_cache'], 'Dedicated alias is sent only to its own Cloudflare resource');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['files' => ['https://dev.example.com/shop/item/']], 'Canonical layer omits alias assigned elsewhere');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['files' => ['https://shop.example.net/shop/item/']], 'Dedicated layer purges the configured alias');
$legacyDedicated = $crossZone;
unset($legacyDedicated['alias_hosts']);
$GLOBALS['http'] = [];
check(array_column(Providers::purge($legacyDedicated, 'cdn'), 'status'), ['ok', 'ok'], 'Previously configured dedicated HTML hostname works without a separate alias entry');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['hosts' => ['dev.example.com']], 'Legacy dedicated hostname stays out of the canonical purge');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['hosts' => ['shop.example.net']], 'Legacy dedicated hostname still receives a verified full purge');
$onlyAutomatic = $crossZone;
$onlyAutomatic['cdn_topology'] = $crossZone['cdn'];
$onlyAutomatic['cdn'] = [$crossZone['cdn'][0]];
$GLOBALS['http'] = [];
check(Providers::purge($onlyAutomatic, 'urls', ['/shop/item/'])[0]['status'], 'ok', 'A manual-only dedicated alias remains outside the automatic canonical layer');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['files' => ['https://dev.example.com/shop/item/']], 'Retain alias ownership across a filtered automatic CDN list');
$crossZone['cdn'][] = array_replace($crossZone['cdn'][1], ['zone_id' => $cfZoneA]);
$GLOBALS['http'] = [];
check(Providers::purge($crossZone, 'cdn')[0]['status'], 'error', 'Conflicting CDNs for an alias fail before any remote purge');
check(count($GLOBALS['http']), 0, 'No API call for an ambiguous alias assignment');

$badZone = $aliasConfig;
$badZone['alias_hosts'] = ['shop.example.net'];
$GLOBALS['http'] = [];
check(Providers::purge($badZone, 'urls', ['/shop/item/'])[0]['status'], 'error', 'Cloudflare cross-zone alias without a dedicated row is rejected');
check(array_column($GLOBALS['http'], 0), [$cfResourceA], 'No mutation for unverified alias zone');
$GLOBALS['http_status'][$cfResourceA] = 429;
$GLOBALS['http'] = [];
check(Providers::purge($aliasConfig, 'urls', ['/shop/item/'])[0]['http'], 429, 'Alias resource preflight preserves retryable HTTP status');
unset($GLOBALS['http_status']);
$GLOBALS['transport_error'][$cfResourceA] = true;
check(Providers::purge($aliasConfig, 'urls', ['/shop/item/'])[0]['retryable'], true, 'Alias resource connection failure remains retryable');
unset($GLOBALS['transport_error']);

$assetCloudflare = Providers::sanitize(['alias_hosts' => ['www.example.com'], 'cdn' => [
    array_replace($cf, ['hostname' => 'assets.example.com', 'scope' => 'all', 'rewrite_urls' => true]),
]]);
$GLOBALS['http'] = [];
check(Providers::purge($assetCloudflare, 'urls', ['/shop/item/'])[0]['status'], 'skipped', 'An assets CNAME does not purge same-named web pages');
check(Providers::purge($assetCloudflare, 'cdn')[0]['status'], 'ok', 'Existing Cloudflare assets configuration retains exact host purge');
check(json_decode($GLOBALS['http'][0][1]['body'], true), ['hosts' => ['assets.example.com']], 'Site aliases do not widen a full assets host purge');

$bunnyAliases = $bunnyPage;
$bunnyAliases['alias_hosts'] = ['www.example.com', 'shop.example.com'];
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":false,"Hostnames":[{"Value":"dev.example.com"},{"Value":"www.example.com"}]}';
$GLOBALS['http'] = [];
check(Providers::purge($bunnyAliases, 'urls', ['/hello/'])[0]['status'], 'error', 'Missing Bunny Pull Zone alias fails before the first purge');
check(array_column($GLOBALS['http'], 0), [$bunnyZone], 'No partial Bunny mutation from a mismatched hostname');
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":false,"Hostnames":[{"Value":"dev.example.com"},{"Value":"www.example.com"},{"Value":"shop.example.com"}]}';
check(Providers::targetBatchLimit($bunnyAliases), 6, 'Worker batch takes three Bunny hosts per canonical URL into account');
$GLOBALS['http'] = [];
check(Providers::purge($bunnyAliases, 'urls', ['/hello/'])[0]['status'], 'ok', 'Three verified Bunny targets accepted');
check(count($GLOBALS['http']), 4, 'Bunny GET plus one synchronous purge per hostname');
$secondBunnyPurge = $GLOBALS['http'][2][0];
$bunnyAliases['cdn'][] = $cf;
$GLOBALS['http_status'][$secondBunnyPurge] = 503;
$GLOBALS['http'] = [];
$partial = Providers::purge($bunnyAliases, 'urls', ['/hello/']);
check(array_column($partial, 'status'), ['error'], 'Partial Bunny failure stops the edge chain');
check($partial[0]['http'], 503, 'Transient failure retained on a partial purge');
check(str_contains($partial[0]['message'], '1 of 3'), true, 'Partial success is reported explicitly');
check(count($GLOBALS['http']), 3, 'No third URL or outer CDN after second Bunny failure');
unset($GLOBALS['http_status']);
$GLOBALS['bunny_zone'][$bunnyZone] = $assetBunny;

$gcoreAliases = Providers::sanitize(['alias_hosts' => ['www.example.com'], 'cdn' => [[
    'provider' => 'gcore', 'enabled' => true, 'zone_id' => '4912', 'scope' => 'html', 'token' => 'unit-gcore',
]]]);
$gcoreResource = 'https://api.gcore.com/cdn/resources/4912';
$GLOBALS['resource_reply'][$gcoreResource] = '{"id":4912,"cname":"dev.example.com","secondaryHostnames":["www.example.com"]}';
$GLOBALS['http'] = [];
check(Providers::purge($gcoreAliases, 'urls', ['/shop/item/'])[0]['status'], 'ok', 'Gcore validates the configured domain and its alias');
check(array_column($GLOBALS['http'], 0), [$gcoreResource, $gcore_endpoint], 'A Gcore layer submits to the selected resource only');
$GLOBALS['gcore_reply'] = '{"urls":["/shop/item/"]}';
check(Providers::purge($gcoreAliases, 'urls', ['/shop/item/', '/shop/another/'])[0]['status'], 'error', 'An accepted Gcore request must echo every submitted path');
unset($GLOBALS['gcore_reply']);
check(json_decode($GLOBALS['http'][1][1]['body'], true), ['urls' => ['/shop/item/']], 'Gcore sends each cache path only once per resource');
// Gcore 的 API 規格只把 201 定為成功、沒有定義回應內容（官方 SDK 也不讀）：沒有回傳清單的 201 算已受理。
$GLOBALS['gcore_reply'] = '';
check(Providers::purge($gcoreAliases, 'urls', ['/shop/item/'])[0]['status'], 'ok', 'A Gcore 201 without a response body is accepted');
check(Providers::purge($gcoreAliases, 'all')[0]['status'] ?? null, 'ok', 'A Gcore purge-all 201 without a response body is accepted');
// 回傳清單時才核對，不分順序與百分比編碼大小寫（Gcore 可能把 %e6 寫成 %E6）。
$GLOBALS['gcore_reply'] = '{"urls":["/%E6%89%8B/","/shop/item/"]}';
check(Providers::purge($gcoreAliases, 'urls', ['/shop/item/', '/%e6%89%8b/'])[0]['status'], 'ok', 'Gcore echoes match regardless of order and percent-encoding case');
unset($GLOBALS['gcore_reply']);
// 自 2026-11-25 起，路徑含 |、換行、Tab 或不完整的 % 編碼時 Gcore 整批回 400：只送出合格路徑，並註明略過幾個。
$GLOBALS['http'] = [];
$mixed = Providers::purge($gcoreAliases, 'urls', ['/shop/item/', '/shop/?filter=red|blue', '/sale-100%-off/']);
check($mixed[0]['status'], 'ok', 'Valid paths are still purged when Gcore would reject others');
check(json_decode(end($GLOBALS['http'])[1]['body'], true), ['urls' => ['/shop/item/']], 'Only paths Gcore accepts are submitted');
check(str_contains($mixed[0]['message'], '2 paths') && str_contains($mixed[0]['message'], 'left out'), true, 'Report how many paths were left out');
$GLOBALS['http'] = [];
$rejected = Providers::purge($gcoreAliases, 'urls', ['/shop/?filter=red|blue']);
check($rejected[0]['status'], 'skipped', 'A purge made only of rejected paths is skipped, so outer layers still run');
check(array_values(array_filter(array_column($GLOBALS['http'], 0), static fn (string $url): bool => str_ends_with($url, '/purge'))), [], 'Nothing is sent to Gcore when every path would be rejected');
unset($GLOBALS['resource_reply']);

$form = $settings;
unset($form['cdn'][0]['token'], $form['cdn'][2]['token_constant'], $form['enhance']['token']);
$edited = Providers::sanitize($form, $settings);
check($edited['cdn'][0]['token'], 'unit-bunny', 'Empty secret kept on matching CDN row');
check($edited['cdn'][2]['token_constant'], 'WP_ROCKET_CF_API_KEY', 'Constant reference kept on matching row');
check($edited['enhance']['token'], 'unit-enhance', 'Empty secret kept for same Enhance destination');

$form['cdn'][0]['clear_token'] = true;
$form['cdn'][2]['zone_id'] = '1123456789abcdef0123456789abcdef';
$form['enhance']['url'] = 'https://altered.example.net';
$edited = Providers::sanitize($form, $settings);
check($edited['cdn'][0]['token'], '', 'Secret cleared explicitly');
check($edited['cdn'][2]['token_constant'] ?? '', '', 'Changed Cloudflare zone has no inherited secret');
check($edited['enhance']['token'], '', 'Changed Enhance host has no inherited secret');

$invalid = Providers::sanitize(['enhance' => ['enabled' => 1, 'url' => 'https://127.0.0.1', 'domain_id' => 'e2f3837f-314e-403b-aa6b-8d523513deed', 'token' => "fixture\r\ninjected"]]);
check($invalid['enhance']['url'], '', 'Enhance does not accept literal addresses');
check($invalid['enhance']['token'], '', 'Credentials cannot carry a newline');
$GLOBALS['http'] = [];
check(Providers::purge($invalid, 'enhance')[0]['status'], 'error', 'Layer reports invalid configuration');
check(count($GLOBALS['http']), 0, 'No outbound request for invalid configuration');

$GLOBALS['cloudflare_reply'] = '{"success":false}';
check(Providers::purge($settings, 'cdn')[2]['status'], 'error', 'Cloudflare rejection cannot pass on HTTP 200');
$GLOBALS['cloudflare_reply'] = '{"success":true,"errors":[{"message":"Partial failure"}]}';
check(Providers::purge($settings, 'cdn')[2]['status'], 'error', 'Cloudflare errors cannot be silently accepted on imperfect success');
unset($GLOBALS['cloudflare_reply']);

$GLOBALS['response_type'] = 'text/html; charset=UTF-8';
check(Providers::purge($settings, 'enhance')[0]['status'], 'error', 'A login HTML response does not count as a successful purge');
unset($GLOBALS['response_type']);

echo "Provider transport and credential handling checks passed.\n";
