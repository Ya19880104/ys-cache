<?php
/** Run with php tests/enhance-integration.php. Network transport is mocked. */

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'YS_ECOMMERCE_VERSION', '3.0.1' );

$state = [ 'enabled' => true, 'paths' => [ '^/custom-protected/', '/shop-area/wp-admin' ] ];
$http = [];
function __( $value, $domain ) { return $value; }
function home_url( $path ) { return 'https://store.example.net/shop-area' . $path; }
function site_url( $path ) { return home_url( $path ); }
function wp_login_url() { return home_url( '/wp-login.php' ); }
function rest_url() { return home_url( '/wp-json/' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wc_get_page_id( $name ) { return [ 'cart' => 10, 'checkout' => 11, 'myaccount' => 12 ][ $name ]; }
function WC() {
    return (object) [ 'query' => new class {
        public function get_query_vars() { return [ 'orders' => 'purchase-history', 'order-pay' => 'pay', 'add-payment-method' => '' ]; }
    } ];
}
function wc_get_endpoint_url( $name, $value, $page ) {
    return rtrim( $page, '/' ) . '/' . WC()->query->get_query_vars()[ $name ] . '/';
}
function get_permalink( $id ) {
    return [
        10 => home_url( '/basket/' ), 11 => home_url( '/checkout/' ), 12 => home_url( '/account/' ),
        21 => home_url( '/ys-cart/' ), 22 => home_url( '/?page_id=22' ), 23 => 'https://unrelated.example.net/unsafe/',
    ][ $id ] ?? false;
}
function get_option( $name, $fallback ) {
    return [
        'trp_settings' => [ 'publish-languages' => [ 'de_DE' ] ],
        'ys_ec_page_cart_id' => 21, 'ys_ec_page_login_id' => 22, 'ys_ec_page_register_id' => 23,
    ][ $name ] ?? $fallback;
}
function apply_filters( $name, $value, $settings ) {
    return $name === 'ys_cache_enhance_exclusion_paths' ? [ '/form-intake/', '/escaped/../other', 123 ] : $value;
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $response ) { return $response instanceof RuntimeException; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['type']; }
function wp_safe_remote_request( $url, $args ) {
    global $http, $state;
    $http[] = [ $url, $args ];
    $response = [ 'code' => 200, 'type' => 'application/json', 'body' => '' ];
    if ( str_ends_with( $url, '/nginx_fastcgi' ) ) {
        $response['code'] = $state['status_code'] ?? 200;
        $response['type'] = $state['status_type'] ?? 'application/json';
        $response['body'] = json_encode( $state['enabled'] );
        return $response;
    }
    if ( $args['method'] === 'GET' ) {
        if ( ! empty( $state['after_post'] ) && ! empty( $state['readback_failure'] ) ) {
            $response['code'] = 503;
        }
        $response['body'] = $state['unexpected_paths'] ?? json_encode( $state['paths'] );
        return $response;
    }
    $state['after_post'] = true;
    $path = json_decode( $args['body'] );
    if ( is_string( $path ) && $path !== ( $state['refuse'] ?? '' ) ) {
        $state['paths'][] = $path;
    } else {
        $response['code'] = 400;
    }
    return $response;
}

class TRP_Translate_Press {
    public static function get_trp_instance() { return new self(); }
    public function get_component( $name ) { return $name === 'url_converter' ? $this : null; }
    public function get_url_for_language( $language, $url, $marker ) {
        return str_replace( [ '/shop-area/', '/basket/' ], [ '/shop-area/de/', '/warenkorb/' ], $url );
    }
}

function check( $actual, $expected, $label ) {
    if ( $actual !== $expected ) {
        throw new RuntimeException( $label . ': ' . var_export( [ $actual, $expected ], true ) );
    }
}

require dirname( __DIR__ ) . '/src/YSCacheProviders.php';
require dirname( __DIR__ ) . '/src/YSCacheIntegrations.php';
require dirname( __DIR__ ) . '/src/YSCacheEnhanceIntegration.php';
use YangSheep\Cache\YSCacheEnhanceIntegration as Enhance;

$providers = [ 'enhance' => [
    'enabled' => false, 'url' => 'https://panel.example.net', 'domain_id' => '138f82d8-4ca5-43b1-8c24-4fb7016a6cbf',
    'org_id' => 'e30bb195-de15-410f-a18f-69bcc8529206', 'token' => 'unit-only',
] ];
$changed = $providers['enhance'];
$changed['token'] = 'another-token';
check( Enhance::fingerprint( $changed ), Enhance::fingerprint( $providers['enhance'] ), 'Fingerprint excludes credentials' );
$changed['domain_id'] = '138f82d8-4ca5-43b1-8c24-4fb7016a6cb0';
check( Enhance::fingerprint( $changed ) !== Enhance::fingerprint( $providers['enhance'] ), true, 'Fingerprint binds the target domain' );
check( Enhance::status( [] )['status'], 'skipped', 'Missing credentials disable probing' );
check( count( $http ), 0, 'Missing credentials make no HTTP call' );
check( Enhance::status( $providers )['enabled'], true, 'Read FastCGI even with purge initially disabled' );
$base = 'https://panel.example.net/api/v2/domains/138f82d8-4ca5-43b1-8c24-4fb7016a6cbf';
check( $http[0][0], $base . '/nginx_fastcgi', 'Documented state endpoint' );
check( $http[0][1]['headers']['orgId'], $providers['enhance']['org_id'], 'Configured organization' );
check( $http[0][1]['redirection'], 0, 'No credential forwarding on redirects' );
$state['enabled'] = false;
check( Enhance::status( $providers )['enabled'], false, 'Distinguish disabled from failure' );
$state['enabled'] = [ 'enabled' => true ];
check( Enhance::status( $providers )['enabled'], null, 'Reject object instead of literal boolean' );
$state['enabled'] = true;
$state['status_type'] = 'text/html; charset=utf-8';
check( Enhance::status( $providers )['status'], 'error', 'Reject HTML even with valid-looking body' );
unset( $state['status_type'] );
$state['status_code'] = 403;
$http = [];
check( Enhance::sync( $providers )['status'], 'error', 'Forbidden status prevents sync' );
check( array_column( array_column( $http, 1 ), 'method' ), [ 'GET' ], 'Never write when authorization fails' );
unset( $state['status_code'] );

$settings = [ 'exclude_paths' => "/legacy-page\n/checkout*\n/invalid?secret=1\nhttps://other.example.net/path" ];
$preview = Enhance::preview( $providers, $settings );
check( $preview['existing'], $state['paths'], 'Retain existing regular expressions verbatim' );
check( $preview['status'], 'ok', 'Preview reads the rule collection' );
foreach ( [ '/shop-area/wp-admin/', '/shop-area/wp-admin/admin-ajax.php', '/shop-area/wp-json', '/shop-area/basket',
    '/shop-area/checkout/pay/', '/shop-area/account/purchase-history/', '/shop-area/ys-cart', '/shop-area/de/warenkorb/',
    '/shop-area/de/ys-cart', '/legacy-page/', '/form-intake/' ] as $path ) {
    check( in_array( $path, $preview['missing'], true ), true, 'Suggest specific protected path ' . $path );
}
foreach ( [ '/shop-area/wp-admin', '/shop-area/', '/escaped/../other', '/unsafe/', '/checkout*', '/shop-area/account/add-payment-method/' ] as $path ) {
    check( in_array( $path, $preview['missing'], true ), false, 'Leave unsupported or existing path ' . $path );
}
$state['unexpected_paths'] = '{"path":"/should-be-an-array"}';
check( Enhance::preview( $providers )['status'], 'error', 'Reject malformed path collection' );
unset( $state['unexpected_paths'] );

$http = [];
$state['refuse'] = '/legacy-page/';
$sync = Enhance::sync( $providers, $settings );
$posts = array_values( array_filter( $http, fn( $request ) => $request[1]['method'] === 'POST' ) );
check( count( $posts ), count( $preview['missing'] ), 'Only attempt missing exclusions' );
check( $posts[0][0], $base . '/nginx_fastcgi_excluded_paths', 'Documented add endpoint' );
check( json_decode( $posts[0][1]['body'] ), $preview['missing'][0], 'Post a single JSON string' );
check( $sync['failed'], [ '/legacy-page/' ], 'Report path not confirmed by readback' );
check( $sync['status'], 'error', 'Expose partial sync failure' );
check( $sync['existing'][0], '^/custom-protected/', 'Preserve unrelated existing rules' );
unset( $state['refuse'] );
$state['readback_failure'] = true;
$state['after_post'] = false;
$sync = Enhance::sync( $providers, $settings );
check( $sync['added'], [], 'Avoid claiming success when readback fails' );
check( $sync['failed'], [ '/legacy-page/' ], 'Identify unverified attempted rule' );
unset( $state['readback_failure'] );
$http = [];
$sync = Enhance::sync( $providers, $settings );
check( $sync['missing'], [], 'Repeat operation reads back the applied rule' );
check( array_column( array_column( $http, 1 ), 'method' ), [ 'GET', 'GET' ], 'Repeat operation performs no writes' );

echo "Enhance status, exclusion preview and sync checks passed.\n";
