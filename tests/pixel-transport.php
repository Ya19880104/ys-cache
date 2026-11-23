<?php

namespace YangSheep\Cache {
    function time(): int {
        return $GLOBALS['pixel_test_now'];
    }

    function headers_sent(): bool {
        return false;
    }

    function setcookie( string $name, string $value, array $options ): bool {
        $GLOBALS['pixel_test_cookie'] = [ $name, $value, $options ];
        return true;
    }

    function header( string $line, bool $replace ): void {
        [ $name, $value ] = explode( ': ', $line, 2 );
        $GLOBALS['pixel_test_headers'][ $name ] = $value;
    }

    function header_remove( string $name ): void {
        unset( $GLOBALS['pixel_test_headers'][ $name ] );
    }
}

namespace {
    use YangSheep\Cache\YSCacheEarlyStore as Store;
    use YangSheep\Cache\YSCachePixelTransport as Transport;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    function expect_same( $expected, $actual, string $message ): void {
        if ( $expected !== $actual ) {
            throw new \RuntimeException( $message );
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-transport-test-' . bin2hex( random_bytes( 8 ) );
    define( 'ABSPATH', $root . '/public/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    if ( ! mkdir( WP_CONTENT_DIR, 0700, true ) ) {
        throw new \RuntimeException( 'Could not create the fixture document root.' );
    }

    try {
        require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
        require dirname( __DIR__ ) . '/src/YSCachePixelWarm.php';
        require dirname( __DIR__ ) . '/src/YSCachePixelTransport.php';
        $directory = $root . '/store';
        $host = 'store.example.test';
        $GLOBALS['pixel_test_now'] = 1790524231;
        $GLOBALS['pixel_test_headers'] = [];
        $request = [
            'DOCUMENT_ROOT' => ABSPATH,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTPS' => 'on',
            'HTTP_HOST' => $host,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'HTTP_ACCEPT_LANGUAGE' => 'zh-TW,en;q=0.9',
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
            'HTTP_SEC_FETCH_SITE' => 'none',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_USER' => '?1',
        ];
        $_SERVER = $request;
        $_GET = $_POST = $_COOKIE = [];
        $html = '<!doctype html><html><body><script src="' . Transport::SCRIPT_PLACEHOLDER . '"></script>' .
            '<noscript><img src="' . Transport::IMAGE_PLACEHOLDER . '"></noscript></body></html>';
        expect_same( false, Transport::provision( $directory ), 'Only provision within a verified early store' );
        expect_same( true, Store::initialize( $directory ), 'Create the early store' );
        expect_same( null, Transport::prepareShell( $directory, $host, $html ), 'Fall through when the key is absent' );
        expect_same( true, Transport::provision( $directory ), 'Provision the private signing key' );
        expect_same( true, Transport::shellEligible( $request, [], $host ), 'Accept a direct cookieless homepage navigation' );
        expect_same( false, Transport::shellEligible( $request, [ 'synthetic' => 'test' ], $host ), 'Reject a returning cookie' );
        foreach ( [
            [ 'HTTP_REFERER' => 'https://elsewhere.example.test/' ],
            [ 'HTTP_SEC_FETCH_SITE' => 'same-origin' ],
            [ 'HTTP_SEC_FETCH_USER' => '' ],
            [ 'HTTP_SEC_FETCH_MODE' => '' ],
            [ 'HTTP_SEC_FETCH_DEST' => 'iframe' ],
            [ 'HTTP_SEC_PURPOSE' => 'prefetch' ],
            [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome-Lighthouse' ],
            [ 'HTTP_USER_AGENT' => 'curl/8.14.1' ],
            [ 'HTTP_SEC_CH_UA_MOBILE' => '?1' ],
            [ 'REQUEST_METHOD' => 'HEAD' ],
            [ 'REQUEST_URI' => '/other/' ],
            [ 'QUERY_STRING' => 'a=1' ],
            [ 'HTTP_X_YS_CACHE_PIXEL_WARM' => '' ],
            [ 'HTTP_X_YS_GUEST_TOKEN' => 'synthetic' ],
            [ 'HTTP_CART_TOKEN' => 'synthetic' ],
            [ 'HTTP_X_WP_LOCALE' => 'en_US' ],
            [ 'HTTP_X_ORIGINAL_URI' => '/other/' ],
            [ 'HTTP_COOKIE' => 'synthetic=value' ],
            [ 'HTTP_AUTHORIZATION' => 'synthetic' ],
        ] as $override ) {
            expect_same( false, Transport::shellEligible( array_replace( $request, $override ), [], $host ), 'Reject a non-direct or stateful navigation' );
        }
        expect_same( null, Transport::prepareShell( $directory, $host, str_replace( Transport::IMAGE_PLACEHOLDER, '/incomplete', $html ) ), 'Reject incomplete shell data' );
        expect_same( null, Transport::prepareShell( $directory, $host, $html . Transport::SCRIPT_PLACEHOLDER ), 'Reject duplicate shell markers' );

        $rewritten = Transport::prepareShell( $directory, $host, $html );
        expect_same( true, is_string( $rewritten ) && str_contains( $rewritten, '/?ys_cache_pixel=script' ) &&
            str_contains( $rewritten, '/?ys_cache_pixel=image' ) && ! Transport::hasPlaceholders( $rewritten ), 'Replace markers with fixed same-origin URLs' );
        [ $name, $first, $options ] = $GLOBALS['pixel_test_cookie'];
        expect_same( Transport::COOKIE, $name, 'Issue the dedicated host-only cookie' );
        expect_same( [
            'expires' => $GLOBALS['pixel_test_now'] + 45,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ], $options, 'Keep the token secure, host-only and short lived' );
        expect_same( true, str_contains( $GLOBALS['pixel_test_headers']['Cache-Control'] ?? '', 'no-store' ), 'Do not share the personalized shell response headers' );
        expect_same( false, str_contains( $rewritten, $first ), 'Do not expose the HttpOnly token in HTML' );
        expect_same( true, Transport::provision( $directory ), 'Provision is idempotent' );
        expect_same( true, Transport::validToken( $directory, $host, $request, $first ), 'Use the issued token' );
        $withoutMobileHint = array_diff_key( $request, [ 'HTTP_SEC_CH_UA_MOBILE' => true ] );
        expect_same( true, Transport::validToken( $directory, $host, $withoutMobileHint, $first ), 'Allow supported desktop noscript image loads with missing client hint' );
        expect_same( false, Store::requestVariant( $request ) === Store::requestVariant( $withoutMobileHint ), 'Do not coalesce HTTP cache cohorts when validating a pixel ticket' );
        expect_same( false, Transport::validToken( $directory, $host, array_replace( $request, [ 'HTTP_SEC_CH_UA_MOBILE' => '?1' ] ), $first ), 'Reject mobile client hints on a desktop ticket' );
        expect_same( false, Transport::validToken( $directory, 'other.example.test', $request, $first ), 'Bind the token to the site' );
        expect_same( false, Transport::validToken( $directory, $host, array_replace( $request, [ 'HTTP_USER_AGENT' => str_replace( 'Chrome/147', 'Chrome/146', $request['HTTP_USER_AGENT'] ) ] ), $first ), 'Bind the token to the browser variant' );
        expect_same( false, Transport::validToken( $directory, $host, $request, substr( $first, 0, -1 ) . ( substr( $first, -1 ) === '0' ? '1' : '0' ) ), 'Reject a modified token' );
        Transport::prepareShell( $directory, $host, $html );
        $second = $GLOBALS['pixel_test_cookie'][1];
        expect_same( true, $first !== $second, 'Generate an independent capability per view' );
        $GLOBALS['pixel_test_now'] += 45;
        expect_same( false, Transport::validToken( $directory, $host, $request, $second ), 'Reject the token at expiry' );
        $GLOBALS['pixel_test_now'] -= 46;
        expect_same( false, Transport::validToken( $directory, $host, $request, $second ), 'Reject future issuance times' );
        $GLOBALS['pixel_test_now'] += 1;

        $cookie = [ Transport::COOKIE => $second, 'PHPSESSID' => 'GuestSessionValue123456789' ];
        $rawCookie = Transport::COOKIE . '=' . $second . '; PHPSESSID=' . $cookie['PHPSESSID'];
        $get = [ Transport::PARAMETER => 'script' ];
        $resource = array_replace( $request, [
            'REQUEST_URI' => '/?ys_cache_pixel=script',
            'QUERY_STRING' => 'ys_cache_pixel=script',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_MODE' => 'no-cors',
            'HTTP_SEC_FETCH_DEST' => 'script',
            'HTTP_SEC_FETCH_USER' => '',
            'HTTP_REFERER' => 'https://' . $host . '/',
            'HTTP_COOKIE' => $rawCookie,
            'HTTP_ACCEPT' => 'application/javascript',
        ] );
        $sessionHeader = 'PHPSESSID=' . $cookie['PHPSESSID'];
        expect_same( [ 'kind' => 'script', 'token' => $second, 'cookie_header' => $sessionHeader ], Transport::endpointContext( $host, $resource, $cookie, $get ), 'Verify the direct bootstrap request and optional guest session cookie' );
        expect_same( [ 'kind' => 'script', 'token' => '', 'cookie_header' => '' ], Transport::endpointContext( $host, array_replace( $resource, [ 'HTTP_COOKIE' => '' ] ), [], $get ), 'Represent a missing token only as eligible for fixed fallback' );
        expect_same( null, Transport::endpointContext( $host, $resource, $cookie, array_merge( $get, [ 'extra' => '1' ] ) ), 'Reject extra query values' );
        foreach ( [
            [ 'REQUEST_URI' => '/?ys_cache_pixel=script&ys_cache_pixel=script' ],
            [ 'QUERY_STRING' => 'ys_cache_pixel=script&preview=1' ],
            [ 'HTTP_SEC_FETCH_SITE' => 'same-site' ],
            [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ],
            [ 'HTTP_SEC_FETCH_MODE' => 'cors' ],
            [ 'HTTP_SEC_FETCH_DEST' => 'image' ],
            [ 'HTTP_SEC_FETCH_DEST' => '' ],
            [ 'HTTP_REFERER' => 'https://' . $host . '/another-page/' ],
            [ 'HTTP_REFERER' => 'https://elsewhere.example.test/' ],
            [ 'HTTP_ORIGIN' => 'https://elsewhere.example.test' ],
            [ 'HTTP_CART_TOKEN' => 'synthetic' ],
            [ 'HTTP_X_YS_GUEST_TOKEN' => 'synthetic' ],
            [ 'HTTP_AUTHORIZATION' => 'synthetic' ],
            [ 'HTTP_COOKIE' => $rawCookie . '; wordpress_logged_in_test=member' ],
            [ 'HTTP_COOKIE' => $rawCookie . '; woocommerce_cart_hash=cart' ],
            [ 'HTTP_COOKIE' => $rawCookie . '; unknown=value' ],
            [ 'HTTP_COOKIE' => $rawCookie . '; ' . Transport::COOKIE . '=' . $second ],
        ] as $override ) {
            expect_same( null, Transport::endpointContext( $host, array_replace( $resource, $override ), $cookie, $get ), 'Reject mismatched resource metadata or private state' );
        }
        $image = array_replace( $resource, [
            'REQUEST_URI' => '/?ys_cache_pixel=image',
            'QUERY_STRING' => 'ys_cache_pixel=image',
            'HTTP_SEC_FETCH_DEST' => 'image',
        ] );
        expect_same( [ 'kind' => 'image', 'token' => $second, 'cookie_header' => $sessionHeader ], Transport::endpointContext( $host, $image, $cookie, [ Transport::PARAMETER => 'image' ] ), 'Allow the paired same-origin image scope' );
        expect_same( [ 'kind' => 'image', 'token' => $second, 'cookie_header' => $sessionHeader ], Transport::endpointContext( $host, array_diff_key( $image, [ 'HTTP_SEC_CH_UA_MOBILE' => true ] ), $cookie, [ Transport::PARAMETER => 'image' ] ), 'Recognize the genuine Chrome noscript image request' );
        expect_same( true, Transport::isEndpoint( [ 'REQUEST_URI' => '/?foo=1&ys_cache_pixel=invalid' ], [] ), 'Catch malformed candidates for closed response' );
        expect_same( false, Transport::isEndpoint( [ 'REQUEST_URI' => '/?ys_cache_pixel_fallback=1' ], [] ), 'Do not intercept the regular-page fallback' );

        $trackers = [
            'pbid' => str_repeat( 'a', 64 ),
            '_fbp' => 'fb.1.1790524231000.123456789',
            'pys_first_visit' => 'true',
            'pysTrafficSource' => 'direct',
            'pys_landing_page' => 'https://' . $host . '/',
            'pys_cd' => 'host-only',
            '_ga' => 'GA1.1.123456789.1790524231',
            '_ga_EXAMPLE123' => 'GS2.1.s1790524231$o1$g0$t1790524231$j60$l0$h0',
            '_gcl_au' => '1.1.234567891.1790524231',
        ];
        $trackingHeader = implode( '; ', array_map( static fn ( string $name, string $value ): string => $name . '=' . rawurlencode( $value ), array_keys( $trackers ), $trackers ) );
        $_SERVER = array_replace( $resource, [ 'HTTP_COOKIE' => $rawCookie . '; ' . $trackingHeader ] );
        $_COOKIE = array_merge( $cookie, $trackers );
        $_GET = $get;
        $_REQUEST = array_merge( $get, $_COOKIE );
        expect_same( true, Transport::endpointContext( $host, array_replace( $_SERVER, [
            'HTTP_COOKIE' => str_replace( '%24', '$', $_SERVER['HTTP_COOKIE'] ),
        ] ), $_COOKIE, $get ) !== null, 'Allow the browser GA4 cookie with raw dollar signs' );
        expect_same( false, Transport::shellEligible( $request, $trackers, $host ), 'Never allow tracking cookies on the shared shell request' );
        foreach ( [
            'pys_consent=granted',
            'pys_advanced_form_data=person',
            'pys_utm_source=external',
            '_fbc=fb.1.1790524231000.click',
            'pys_session_entry_referrer=external',
            '_ga=unbounded-value',
            '_ga_short=GS2.1.s1790524231$o1',
            '_ga_EXAMPLE123=GS2.1.s1790524231%24xmail',
            '_gcl_au=non-analytics',
        ] as $privateCookie ) {
            [ $key, $value ] = explode( '=', $privateCookie, 2 );
            expect_same( null, Transport::endpointContext( $host, array_replace( $resource, [ 'HTTP_COOKIE' => $rawCookie . '; ' . $privateCookie ] ),
                array_merge( $cookie, [ $key => $value ] ), $get ), 'Deny consent, user data and non-direct campaign state even in private dispatch' );
        }
        $proof = \Closure::bind( static fn ( ...$args ) => Transport::sameOriginKind( ...$args ), null, Transport::class );
        expect_same( 'script', $proof( $host, array_replace( $resource, [ 'HTTP_COOKIE' => 'unknown=1' ] ), $get ),
            'A genuine same-origin script with unknown state receives a fixed fallback only' );
        expect_same( null, $proof( $host, array_replace( $resource, [ 'HTTP_SEC_FETCH_SITE' => 'same-site' ] ), $get ),
            'Do not send executable fallback to sibling or foreign origins' );
        $GLOBALS['pixel_test_headers']['Access-Control-Allow-Origin'] = '*';
        Transport::dispatch( $directory, $host );
        expect_same( 'script', YS_CACHE_PIXEL_KIND, 'Set a server-only verified resource kind' );
        expect_same( '/', $_SERVER['REQUEST_URI'], 'Restore the canonical page' );
        expect_same( '', $_SERVER['QUERY_STRING'], 'Strip the transport query' );
        expect_same( false, array_key_exists( 'HTTP_REFERER', $_SERVER ), 'Preserve the original direct-visit referrer semantics' );
        expect_same( [], $_GET, 'Remove the transport parameter from the application GET' );
        expect_same( array_merge( [ 'PHPSESSID' => $cookie['PHPSESSID'] ], $trackers ), $_COOKIE, 'Keep exactly the original guest session and anonymous native tracking cookies' );
        expect_same( $sessionHeader . '; ' . $trackingHeader, $_SERVER['HTTP_COOKIE'], 'Do not forward transport cookie as application state' );
        expect_same( array_merge( [ 'PHPSESSID' => $cookie['PHPSESSID'] ], $trackers ), $_REQUEST, 'Do not forward transport parameters as application state' );
        expect_same( 'same-origin', $GLOBALS['pixel_test_headers']['Cross-Origin-Resource-Policy'] ?? null, 'Restrict the resource to the origin' );
        expect_same( false, array_key_exists( 'Access-Control-Allow-Origin', $GLOBALS['pixel_test_headers'] ), 'Do not expose the resource via CORS' );

        $_GET = [ 'ys_cache_pixel_fallback' => '1' ];
        $_COOKIE = [ 'sbjs_current' => 'guest', 'site_cart_session' => 'native' ];
        $_REQUEST = $_GET;
        $fallback = array_replace( $request, [
            'REQUEST_URI' => '/?ys_cache_pixel_fallback=1',
            'QUERY_STRING' => 'ys_cache_pixel_fallback=1',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_USER' => '?1',
            'HTTP_REFERER' => 'https://' . $host . '/',
            'HTTP_COOKIE' => 'sbjs_current=guest; site_cart_session=native',
        ] );
        $_SERVER = array_replace( $fallback, [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ] );
        Transport::resumeFallback( $host );
        expect_same( false, defined( 'YS_CACHE_PIXEL_FALLBACK' ), 'A cross-origin fallback URL stays an ordinary WordPress query' );
        $_SERVER = $fallback;
        Transport::resumeFallback( $host );
        expect_same( true, YS_CACHE_PIXEL_FALLBACK, 'Mark only trusted fallbacks' );
        expect_same( true, DONOTCACHEPAGE, 'Always render the normal fallback dynamically' );
        expect_same( 'sbjs_current=guest; site_cart_session=native', $_SERVER['HTTP_COOKIE'], 'Keep application cookies on a private fallback navigation' );
        expect_same( '/', $_SERVER['REQUEST_URI'], 'Restore the home route before native tracking' );
        expect_same( '', $_SERVER['QUERY_STRING'], 'Remove the native PHP fallback marker' );
        expect_same( false, isset( $_SERVER['HTTP_REFERER'] ), 'Do not attribute the fallback to the cached shell navigation' );
        expect_same( [], $_REQUEST, 'Do not forward the fallback marker to the application' );

        echo "Pixel transport checks passed.\n";
    } finally {
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $entry ) {
            if ( $entry->isDir() && ! $entry->isLink() ) {
                rmdir( $entry->getPathname() );
            } else {
                unlink( $entry->getPathname() );
            }
        }
        rmdir( $root );
    }
}
