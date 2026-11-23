<?php
/** Run php tests/nginx.php to verify the fixed Nginx loopback contract offline. */

namespace YangSheep\Cache {
    function curl_init( string $url ): object {
        return (object) [ 'url' => $url, 'options' => [], 'response' => 0 ];
    }

    function curl_setopt_array( object $handle, array $options ): bool {
        $handle->options = $options;
        return true;
    }

    function curl_exec( object $handle ): bool {
        $GLOBALS['requests'][] = $handle;
        $response = array_shift( $GLOBALS['responses'] );
        if ( $response === null ) {
            throw new \RuntimeException( 'An unplanned request was made.' );
        }
        $handle->response = $response['http'];
        foreach ( $response['lines'] as $line ) {
            if ( $handle->options[CURLOPT_HEADERFUNCTION]( $handle, $line ) !== strlen( $line ) ) {
                return false;
            }
        }
        $body = $response['body'];
        return $handle->options[CURLOPT_WRITEFUNCTION]( $handle, $body ) === strlen( $body ) && $response['ok'];
    }

    function curl_getinfo( object $handle, int $option ): int {
        return $handle->response;
    }
}

namespace {
    use YangSheep\Cache\YSCacheNginx as Nginx;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }
    if ( ! extension_loaded( 'curl' ) ) {
        fwrite( STDERR, "The PHP cURL extension is required for this offline fixture.\n" );
        exit( 1 );
    }

    define( 'ABSPATH', __DIR__ . '/' );
    require dirname( __DIR__ ) . '/src/YSCacheNginx.php';

    function __( string $message ): string { return $message; }
    function home_url( string $path ): string { return $GLOBALS['site'] . $path; }
    function wp_parse_url( string $url ): array|false { return parse_url( $url ); }

    function check( $actual, $expected, string $message ): void {
        if ( $actual !== $expected ) {
            throw new RuntimeException( $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
        }
    }

    function response( int $http, string $mode = 'plus', string $site = 'example.test' ): array {
        return [ 'http' => $http, 'ok' => true, 'body' => '', 'lines' => [
            "HTTP/1.1 {$http} Fixture\r\n", "X-Ys-Nginx-Purge: v1\r\n", "X-YS-Nginx-Site: {$site}\r\n", "X-YS-Nginx-Mode: {$mode}\r\n", "\r\n",
        ] ];
    }

    function prepare( array $responses ): void {
        $GLOBALS['responses'] = $responses;
        $GLOBALS['requests'] = [];
    }

    $GLOBALS['site'] = 'https://Example.Test/wp';
    prepare( [] );
    check( Nginx::configured(), false, 'An undefined port and secret do not enable the adapter' );
    check( Nginx::status()['available'], false, 'Unconfigured status is unavailable' );
    check( Nginx::purge()['status'], 'error', 'Unconfigured purge fails' );
    check( $GLOBALS['requests'], [], 'Unconfigured integration never connects' );

    if ( in_array( '--invalid', $argv, true ) ) {
        define( 'YS_CACHE_NGINX_PURGE_PORT', '19081@remote.test' );
        define( 'YS_CACHE_NGINX_PURGE_SECRET', "invalid\r\nheader" );
        check( Nginx::configured(), false, 'Credentials cannot supply a host, malformed port, or unsafe header' );
        check( Nginx::status()['available'], false, 'Malformed configuration has no listener' );
        check( Nginx::purge()['status'], 'error', 'Malformed configuration cannot purge' );
        check( $GLOBALS['requests'], [], 'Malformed configuration never connects' );
        echo "Nginx invalid configuration checks passed.\n";
        exit;
    }

    define( 'YS_CACHE_NGINX_PURGE_PORT', '19081' );
    define( 'YS_CACHE_NGINX_PURGE_SECRET', str_repeat( 'Ab-_', 8 ) );
    check( Nginx::configured(), true, 'Valid settings enable a probe without a connection' );
    $target = Nginx::fingerprint();
    check( strlen( $target ), 64, 'The queue fingerprint identifies the listener and site' );
    $GLOBALS['site'] = 'https://example.test/sibling';
    check( Nginx::fingerprint() === $target, false, 'Changing the WordPress base path identifies a different queue target' );
    $GLOBALS['site'] = 'https://bad_host.test/wp';
    check( Nginx::configured(), false, 'A valid site is required before visitor HTML can use Nginx' );
    check( Nginx::status()['available'], false, 'An invalid WordPress hostname cannot connect' );
    check( $GLOBALS['requests'], [], 'Invalid site or a fingerprint lookup does not connect' );
    $GLOBALS['site'] = 'https://Example.Test/wp';

    prepare( [ response( 204 ) ] );
    $status = Nginx::status();
    check( $status['available'] && $status['supports_all'], true, 'Verified markers grant page and site capability' );
    $request = $GLOBALS['requests'][0];
    check( $request->url, 'http://127.0.0.1:19081/_ys-cache-nginx/status', 'Status uses the fixed numeric loopback endpoint' );
    check( $request->options[CURLOPT_CUSTOMREQUEST], 'GET', 'Status is read-only' );
    check( $request->options[CURLOPT_HTTPHEADER], [ 'X-YS-Nginx-Secret: ' . YS_CACHE_NGINX_PURGE_SECRET, 'Cache-Control: no-store' ], 'Only the local status request carries the configured secret' );
    check( $request->options[CURLOPT_PROXY], '', 'Environment proxies are disabled' );
    check( $request->options[CURLOPT_FOLLOWLOCATION], false, 'Redirects are disabled' );
    check( $request->options[CURLOPT_CONNECTTIMEOUT_MS] < $request->options[CURLOPT_TIMEOUT_MS], true, 'Connect and transfer timeouts are bounded' );

    foreach ( [ 'https://other.test/wp/contact/', 'http://example.test/wp/contact/', 'https://example.test:444/wp/contact/',
        'https://example.test/wordpress/contact/', 'https://example.test/wp/../secret', 'https://example.test/wp/%2e%2e/secret',
        'https://example.test/wp/contact/*', 'https://example.test/wp/contact/%2A', 'https://example.test/wp/%252a',
        'https://example.test/wp/%2fcontact', 'https://example.test/wp/%5ccontact', 'https://example.test/wp//contact',
        'https://example.test/wp/%0d%0a', 'https://example.test/wp/聯絡/', 'https://example.test/wp/contact/?',
        'https://example.test/wp/contact/#', 'https://name@example.test/wp/contact/', '/wp/contact/', 12,
    ] as $bad ) {
        prepare( [] );
        check( Nginx::purge( [ 'https://example.test/wp/valid/', $bad ] )['status'], 'error', 'Invalid exact purge path fails before a partial dispatch' );
        check( $GLOBALS['requests'], [], 'An invalid member prevents all outbound requests' );
    }
    check( Nginx::purge( array_fill( 0, 21, 'https://example.test/wp/contact/' ) )['status'], 'error', 'A page batch has a hard request limit' );
    check( $GLOBALS['requests'], [], 'An oversized batch never connects' );

    prepare( [ response( 204 ), response( 204 ) ] );
    $encoded = 'https://example.test/wp/%E8%81%AF%E7%B5%A1/';
    check( Nginx::purge( [ $encoded, $encoded ] )['status'], 'ok', 'Plus deduplicates paths and retains literal percent-encoded bytes' );
    check( count( $GLOBALS['requests'] ), 2, 'Only one authenticated purge follows status' );
    $request = $GLOBALS['requests'][1];
    check( $request->url, 'http://127.0.0.1:19081/_ys-cache-nginx/page', 'Exact target cannot change the transport URL' );
    check( $request->options[CURLOPT_CUSTOMREQUEST], 'PURGE', 'Clearing uses the PURGE method' );
    check( end( $request->options[CURLOPT_HTTPHEADER] ), 'X-YS-Nginx-Path: /wp/%E8%81%AF%E7%B5%A1/', 'The path header retains bytes required by the native cache key' );

    prepare( [ response( 204, 'oss' ), response( 200, 'oss' ) ] );
    check( Nginx::purge()['status'], 'ok', 'OSS synchronous site-wide sweep confirms success' );
    $request = $GLOBALS['requests'][1];
    check( $request->url, 'http://127.0.0.1:19081/_ys-cache-nginx/site', 'Full clearing route is site-bound by the listener' );
    check( count( $request->options[CURLOPT_HTTPHEADER] ), 2, 'Wildcard key is fixed server-side and is never a supplied header' );

    prepare( [ response( 204, 'oss' ), response( 412, 'oss' ) ] );
    check( Nginx::purge( [ 'https://example.test/wp/contact/' ] )['status'], 'ok', 'OSS exact missing key is an idempotent success with markers' );
    prepare( [ response( 204, 'oss' ), response( 412, 'oss' ) ] );
    check( Nginx::purge()['status'], 'error', 'OSS 412 is exact-only' );
    prepare( [ response( 204, 'oss' ), response( 202, 'oss' ) ] );
    check( Nginx::purge()['status'], 'error', 'A queued native sweep does not confirm completion' );

    foreach ( [ [ 200, 'plus', 'example.test' ], [ 204, 'oss', 'example.test' ], [ 204, 'plus', 'other.test' ],
        [ 302, 'plus', 'example.test' ], [ 404, 'plus', 'example.test' ], [ 403, 'plus', 'example.test' ],
    ] as [ $http, $mode, $responseSite ] ) {
        prepare( [ response( 204 ), response( $http, $mode, $responseSite ) ] );
        $result = Nginx::purge();
        check( [ $result['status'], $result['retryable'] ], [ 'error', false ], 'Redirects, access errors, and mismatched origin or mode fail closed' );
    }
    foreach ( [ 408, 429, 500, 503 ] as $http ) {
        prepare( [ response( 204 ), response( $http ) ] );
        $result = Nginx::purge();
        check( [ $result['status'], $result['http'], $result['retryable'] ], [ 'error', $http, true ], 'Transient status is eligible for the queue to retry' );
    }

    $failedTransport = [ 'http' => 0, 'lines' => [], 'body' => '', 'ok' => false ];
    prepare( [ $failedTransport ] );
    check( Nginx::purge()['retryable'], true, 'A failed loopback connection is retryable' );
    check( count( $GLOBALS['requests'] ), 1, 'A failed handshake stops before any mutation' );

    $duplicate = response( 204 );
    array_splice( $duplicate['lines'], 2, 0, "x-ys-nginx-purge: v1\r\n" );
    prepare( [ $duplicate ] );
    check( Nginx::status()['available'], false, 'Ambiguous response markers are rejected even when the values agree' );

    $interim = response( 204 );
    $interim['lines'] = array_merge( response( 103 )['lines'], [ "HTTP/1.1 204 Fixture\r\n", "\r\n" ] );
    prepare( [ $interim ] );
    check( Nginx::status()['available'], false, 'Early response markers cannot authenticate the final response' );

    $oversize = response( 204 );
    $oversize['body'] = str_repeat( 'x', 8193 );
    prepare( [ $oversize ] );
    $result = Nginx::purge();
    check( [ $result['status'], $result['retryable'] ], [ 'error', false ], 'Oversized responses are rejected without retrying an invalid protocol response' );
    $oversize = response( 204 );
    array_splice( $oversize['lines'], 2, 0, array_fill( 0, 5, 'X-Fixture: ' . str_repeat( 'x', 2000 ) . "\r\n" ) );
    prepare( [ $oversize ] );
    check( Nginx::status()['available'], false, 'Response headers have a cumulative size limit' );

    echo "Nginx loopback protocol checks passed.\n";
}
