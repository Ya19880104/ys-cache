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
    $status = str_contains($url, 'gcore.com') ? 201 : (str_ends_with($url, '/purgeCache') ? 204 : 200);
    return [
        'status' => $GLOBALS['http_status'][$url] ?? $status,
        'body' => str_contains($url, 'cloudflare.com') ? ($GLOBALS['cloudflare_reply'] ?? '{"success":true}') : ($GLOBALS['bunny_zone'][$url] ?? ''),
        'content_type' => $GLOBALS['response_type'] ?? 'application/json',
    ];
}
function is_wp_error($response) { return false; }
function wp_json_encode($data) { return json_encode($data); }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_header($response, $header) { return $response['content_type']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function check($actual, $expected, $label) {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true));
    }
}

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

$GLOBALS['http'] = [];
$results = Providers::purge($settings);
check(array_column($results, 'status'), ['ok','ok','ok','ok'], 'Full purge results');
check(array_column($GLOBALS['http'], 0), [
    'https://enhance.example.net/api/v2/domains/e2f3837f-314e-403b-aa6b-8d523513deed/nginx_fastcgi',
    'https://api.bunny.net/pullzone/9128/purgeCache',
    'https://api.gcore.com/cdn/resources/4912/purge',
    'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache',
], 'Origin-to-edge call order');
check($GLOBALS['http'][0][1]['method'], 'DELETE', 'Enhance method');
check($GLOBALS['http'][0][1]['redirection'], 0, 'Enhance refuses redirects');
check($GLOBALS['http'][1][1]['body'], '{}', 'Bunny pull-zone purge requires an empty JSON object');
check($GLOBALS['http'][2][1]['headers']['Authorization'], 'APIKey unit-gcore$foo', 'Gcore auth');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['paths' => []], 'Gcore full payload');
check($GLOBALS['http'][3][1]['headers']['Authorization'], 'Bearer unit-rocket-constant', 'CF imported token resolution');
check(json_decode($GLOBALS['http'][3][1]['body'], true), ['hosts' => ['dev.example.com']], 'Cloudflare host purge');

$enhance_endpoint = $GLOBALS['http'][0][0];
$bunny_endpoint = $GLOBALS['http'][1][0];
$gcore_endpoint = $GLOBALS['http'][2][0];
$GLOBALS['http'] = [];
$GLOBALS['http_status'] = [$enhance_endpoint => 503];
check(array_column(Providers::purge($settings), 'status'), ['error'], 'Failed Enhance purge stops the chain');
check(array_column($GLOBALS['http'], 0), [$enhance_endpoint], 'No CDN call after a failed origin cache purge');
$GLOBALS['http'] = [];
$GLOBALS['http_status'] = [$gcore_endpoint => 429];
check(array_column(Providers::purge($settings), 'status'), ['ok', 'ok', 'error'], 'Failure stops the chain at the affected CDN');
check(array_column($GLOBALS['http'], 0), [$enhance_endpoint, $bunny_endpoint, $gcore_endpoint], 'Outer CDN skipped after inner CDN failure');
unset($GLOBALS['http_status']);

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
check($GLOBALS['http'][0][0], 'https://api.bunny.net/purge?url=https%3A%2F%2Fimages.example.com%2Fwp-content%2Fuploads%2F2026%2F11%2Fimg.jpg&exactPath=true', 'Image layer targeted URL');
check(json_decode($GLOBALS['http'][1][1]['body'], true), ['urls' => ['/hello/', '/wp-content/uploads/2026/11/img.jpg']], 'Gcore targeted paths');
check(json_decode($GLOBALS['http'][2][1]['body'], true), ['files' => ['https://dev.example.com/hello/', 'https://dev.example.com/wp-content/uploads/2026/11/img.jpg']], 'Cloudflare targeted site URLs');

$bunnyPage = Providers::sanitize(['cdn' => [[
    'provider' => 'bunny', 'enabled' => true, 'zone_id' => '9128', 'token' => 'unit-bunny', 'scope' => 'html',
]]]);
$bunnyZone = 'https://api.bunny.net/pullzone/9128';
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":true,"Hostnames":[{"Value":"dev.example.com"}]}';
$GLOBALS['http'] = [];
check(Providers::purge($bunnyPage, 'urls', ['/hello/'])[0]['status'], 'error', 'Bunny cannot widen a slash URL to a wildcard purge');
check(array_column($GLOBALS['http'], 0), [$bunnyZone], 'No purge is sent unless exact-path support is confirmed');
$GLOBALS['bunny_zone'][$bunnyZone] = '{"Id":9128,"IgnoreQueryStrings":false,"Hostnames":[{"Value":"dev.example.com"}]}';
$GLOBALS['http'] = [];
check(Providers::purge($bunnyPage, 'urls', ['/hello/'])[0]['status'], 'ok', 'Bunny purges a known exact slash URL');
check(array_column($GLOBALS['http'], 0), [$bunnyZone, 'https://api.bunny.net/purge?url=https%3A%2F%2Fdev.example.com%2Fhello%2F&exactPath=true'], 'Verified zone then exact purge');
unset($GLOBALS['bunny_zone']);

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
unset($GLOBALS['cloudflare_reply']);

$GLOBALS['response_type'] = 'text/html; charset=UTF-8';
check(Providers::purge($settings, 'enhance')[0]['status'], 'error', 'A login HTML response does not count as a successful purge');
unset($GLOBALS['response_type']);

echo "Provider transport and credential handling checks passed.\n";
