<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** Authorizes the fixed first-party resources referenced by an anonymous cached homepage. */
final class YSCachePixelTransport {
    public const SCRIPT_PLACEHOLDER = '/__ys-cache-pixel-script__';
    public const IMAGE_PLACEHOLDER = '/__ys-cache-pixel-image__';
    public const PARAMETER = 'ys_cache_pixel';
    public const COOKIE = '__Host-YSCachePixel';
    private const FALLBACK = 'ys_cache_pixel_fallback';
    private const KEY_FILE = '/.pixel-key';
    private const LIFETIME = 45;
    private const SESSION = 'PHPSESSID';

    public static function provision( string $directory ): bool {
        $directory = self::protectedDirectory( $directory );
        if ( $directory === null || YSCacheEarlyStore::snapshot( $directory, '/' ) === null ) {
            return false;
        }
        if ( self::readKey( $directory ) !== null ) {
            return true;
        }
        $path = $directory . self::KEY_FILE;
        try {
            $secret = random_bytes( 32 );
        } catch ( \Exception $exception ) {
            return false;
        }

        // The fixed-size key is unreadable until complete. Exclusive creation never replaces an older key.
        $file = @fopen( $path, 'x+b' );
        if ( $file === false ) {
            return self::readKey( $directory ) !== null;
        }
        $stored = @chmod( $path, 0600 ) && @fwrite( $file, $secret ) === 32 && @fflush( $file );
        fclose( $file );
        if ( ! $stored ) {
            @unlink( $path );
        }
        return $stored && self::readKey( $directory ) !== null;
    }

    public static function hasProtectedHeaders( array $server ): bool {
        foreach ( [
            'HTTP_X_YS_GUEST_TOKEN', 'HTTP_CART_TOKEN', 'HTTP_X_CART_TOKEN', 'HTTP_X_WC_STORE_API_NONCE',
            'HTTP_X_WC_STORE_API_TOKEN', 'HTTP_WC_STORE_API_NONCE', 'HTTP_NONCE', 'HTTP_X_WOOCOMMERCE_CART_TOKEN',
            'HTTP_X_WP_LOCALE', 'HTTP_X_WP_LANGUAGE', 'HTTP_X_LANGUAGE', 'HTTP_X_LOCALE', 'HTTP_X_OVERRIDE_LOCALE',
            'HTTP_X_TRP_LANGUAGE', 'HTTP_X_TRANSLATEPRESS_LANGUAGE', 'HTTP_X_WPML_LANGUAGE', 'HTTP_X_FORWARDED_LANGUAGE',
            'HTTP_X_ORIGINAL_URL', 'HTTP_X_ORIGINAL_URI', 'HTTP_X_REWRITE_URL',
        ] as $name ) {
            if ( isset( $server[ $name ] ) && $server[ $name ] !== '' ) {
                return true;
            }
        }
        return array_key_exists( 'HTTP_X_YS_CACHE_PIXEL_WARM', $server );
    }

    public static function shellEligible( array $server, array $cookies, string $host ): bool {
        // WebKit can omit Sec-Fetch-User on a direct navigation. Its exact UA is checked by ticketVariant.
        $gesture = ( $server['HTTP_SEC_FETCH_USER'] ?? '' ) === '?1' ||
            ( ! array_key_exists( 'HTTP_SEC_FETCH_USER', $server ) &&
                str_contains( (string) ( $server['HTTP_USER_AGENT'] ?? '' ), ') Version/' ) );
        return ! self::hasProtectedHeaders( $server )
            && YSCacheEarlyStore::requestPath( $server, $cookies, $host ) === '/'
            && ( $server['REQUEST_METHOD'] ?? '' ) === 'GET'
            && ( $server['HTTP_REFERER'] ?? '' ) === ''
            && ( $server['HTTP_SEC_FETCH_SITE'] ?? '' ) === 'none'
            && ( $server['HTTP_SEC_FETCH_MODE'] ?? '' ) === 'navigate'
            && ( $server['HTTP_SEC_FETCH_DEST'] ?? '' ) === 'document'
            && $gesture
            && ( $server['HTTP_SEC_PURPOSE'] ?? '' ) === ''
            && ( $server['HTTP_PURPOSE'] ?? '' ) === ''
            && self::isBrowserAgent( $server )
            && self::ticketVariant( $server ) !== null;
    }

    private static function isBrowserAgent( array $server ): bool {
        $agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
        return stripos( $agent, 'Mozilla/' ) === 0 &&
            preg_match( '~bot|crawl|spider|headless|lighthouse|facebookexternalhit|preview|monitor~i', $agent ) === 0;
    }

    /** Detect any marker so an incomplete shell cannot escape substitution. */
    public static function hasPlaceholders( string $html ): bool {
        return str_contains( $html, self::SCRIPT_PLACEHOLDER ) || str_contains( $html, self::IMAGE_PLACEHOLDER );
    }

    /** The caller supplies only a validated, visitor-neutral homepage shell. */
    public static function prepareShell( string $directory, string $host, string $html ): ?string {
        if ( headers_sent() || $_GET || $_POST || ! self::shellEligible( $_SERVER, $_COOKIE, $host ) ||
            ! self::hasSource( $html, 'script', self::SCRIPT_PLACEHOLDER ) ||
            ! self::hasSource( $html, 'img', self::IMAGE_PLACEHOLDER ) ) {
            return null;
        }
        $key = self::readKey( $directory );
        $variant = self::ticketVariant( $_SERVER );
        if ( $key === null || $variant === null ) {
            return null;
        }
        try {
            $nonce = bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $exception ) {
            return null;
        }
        $now = time();
        $claim = '1.' . $now . '.' . $nonce;
        $token = $claim . '.' . self::sign( $key, $host, $variant, $claim );
        if ( ! setcookie( self::COOKIE, $token, [
            'expires' => $now + self::LIFETIME,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ] ) ) {
            return null;
        }
        self::privateHeaders();
        return strtr( $html, [
            self::SCRIPT_PLACEHOLDER => '/?' . self::PARAMETER . '=script',
            self::IMAGE_PLACEHOLDER => '/?' . self::PARAMETER . '=image',
        ] );
    }

    private static function hasSource( string $html, string $tag, string $placeholder ): bool {
        return substr_count( $html, $placeholder ) === 1 &&
            preg_match( '~<' . $tag . '\b[^<>]*\ssrc\s*=\s*(["\'])' . preg_quote( $placeholder, '~' ) . '\1(?:\s|/?>)~i', $html ) === 1;
    }

    public static function isEndpoint( array $server, array $get ): bool {
        return array_key_exists( self::PARAMETER, $get ) ||
            preg_match( '~[?&]' . self::PARAMETER . '(?:[=&]|$)~', (string) ( $server['REQUEST_URI'] ?? '' ) ) === 1;
    }

    public static function isFallback( array $server, array $get ): bool {
        return ( $server['REQUEST_URI'] ?? '' ) === '/?' . self::FALLBACK . '=1' &&
            ( $server['QUERY_STRING'] ?? '' ) === self::FALLBACK . '=1' && $get === [ self::FALLBACK => '1' ];
    }

    public static function resumeFallback( string $host ): void {
        if ( ! self::isFallback( $_SERVER, $_GET ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ||
            ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) !== 'same-origin' ||
            ( $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '' ) !== 'navigate' ||
            ( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' ) !== 'document' ||
            ! in_array( $_SERVER['HTTP_SEC_FETCH_USER'] ?? '', [ '', '?1' ], true ) ||
            ( $_SERVER['HTTP_REFERER'] ?? '' ) !== self::homeUrl( $host ) ||
            self::hasProtectedHeaders( $_SERVER ) || ! self::isBrowserAgent( $_SERVER ) ||
            ! self::guestResourceRequest( $host, $_SERVER ) ) {
            return;
        }
        // This is a full private WordPress navigation. Preserve its normal session and attribution
        // cookies, including ones created by other scripts after the shell was delivered.
        $parts = array_filter( explode( ';', (string) ( $_SERVER['HTTP_COOKIE'] ?? '' ) ),
            static fn( string $part ): bool => trim( $part ) !== '' && trim( explode( '=', $part, 2 )[0] ) !== self::COOKIE );
        define( 'YS_CACHE_PIXEL_FALLBACK', true );
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        self::privateHeaders();
        self::restoreHome( self::FALLBACK, implode( '; ', array_map( 'trim', $parts ) ) );
    }

    public static function dispatch( string $directory, string $host ): void {
        if ( ! self::isEndpoint( $_SERVER, $_GET ) ) {
            return;
        }
        $context = self::endpointContext( $host, $_SERVER, $_COOKIE, $_GET );
        if ( defined( 'YS_CACHE_PIXEL_KIND' ) ) {
            self::deny();
        }
        if ( $context === null || $context['token'] === '' || ! self::validToken( $directory, $host, $_SERVER, $context['token'] ) ) {
            self::rejectEndpoint( $host );
        }

        define( 'YS_CACHE_PIXEL_KIND', $context['kind'] );
        self::privateHeaders();
        self::restrictResource();
        self::restoreHome( self::PARAMETER, $context['cookie_header'] );
    }

    private static function restoreHome( string $parameter, string $cookieHeader ): void {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['QUERY_STRING'] = '';
        unset( $_SERVER['HTTP_REFERER'], $_SERVER['REDIRECT_QUERY_STRING'] );
        $_GET = [];
        unset( $_REQUEST[ $parameter ], $_REQUEST[ self::COOKIE ], $_COOKIE[ self::COOKIE ] );
        if ( $cookieHeader !== '' ) {
            $_SERVER['HTTP_COOKIE'] = $cookieHeader;
        } else {
            unset( $_SERVER['HTTP_COOKIE'] );
        }
    }

    /** The only denial that executes script is an exact same-origin load of the homepage bootstrap. */
    public static function rejectEndpoint( string $host ): void {
        if ( self::sameOriginKind( $host, $_SERVER, $_GET ) === 'script' ) {
            self::sendFallback();
        }
        self::deny();
    }

    private static function sameOriginKind( string $host, array $server, array $get ): ?string {
        $uri = $server['REQUEST_URI'] ?? '';
        $kind = $get[ self::PARAMETER ] ?? '';
        if ( ! in_array( $kind, [ 'script', 'image' ], true ) || $get !== [ self::PARAMETER => $kind ] ||
            $uri !== '/?' . self::PARAMETER . '=' . $kind ||
            ( $server['QUERY_STRING'] ?? '' ) !== self::PARAMETER . '=' . $kind ||
            ( $server['REQUEST_METHOD'] ?? '' ) !== 'GET' ||
            ! in_array( strtolower( (string) ( $server['HTTPS'] ?? '' ) ), [ 'on', '1' ], true ) ||
            strcasecmp( (string) ( $server['HTTP_HOST'] ?? '' ), $host ) !== 0 ||
            ( $server['HTTP_SEC_FETCH_SITE'] ?? '' ) !== 'same-origin' ||
            ( $server['HTTP_SEC_FETCH_MODE'] ?? '' ) !== 'no-cors' ||
            ( $server['HTTP_SEC_FETCH_DEST'] ?? '' ) !== $kind ||
            ( $server['HTTP_SEC_FETCH_USER'] ?? '' ) !== '' || ( $server['HTTP_SEC_PURPOSE'] ?? '' ) !== '' ||
            ( $server['HTTP_PURPOSE'] ?? '' ) !== '' ||
            ( $server['HTTP_REFERER'] ?? '' ) !== self::homeUrl( $host ) ||
            ( $server['HTTP_ORIGIN'] ?? '' ) !== '' ) {
            return null;
        }
        return $kind;
    }

    /** Origin proof alone never grants access to the live native renderer. */
    public static function endpointContext( string $host, array $server, array $cookies, array $get ): ?array {
        $kind = self::sameOriginKind( $host, $server, $get );
        if ( $kind === null || self::hasProtectedHeaders( $server ) || self::ticketVariant( $server ) === null ||
            ! self::guestResourceRequest( $host, $server ) ) {
            return null;
        }

        $rawCookies = self::endpointCookies( (string) ( $server['HTTP_COOKIE'] ?? '' ), $cookies, $host );
        return $rawCookies === null ? null : [
            'kind' => $kind, 'token' => $rawCookies['token'], 'cookie_header' => $rawCookies['header'],
        ];
    }

    private static function guestResourceRequest( string $host, array $server ): bool {
        $base = array_replace( $server, [
            'REQUEST_URI' => '/',
            'QUERY_STRING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_ACCEPT' => '',
        ] );
        return YSCacheEarlyStore::requestPath( $base, [], $host ) === '/' && YSCacheEarlyStore::requestVariant( $server ) !== null;
    }

    private static function endpointCookies( string $raw, array $cookies, string $host ): ?array {
        if ( strlen( $raw ) > 2048 ) {
            return null;
        }
        $parsed = [];
        $forward = [];
        foreach ( $raw === '' ? [] : explode( ';', $raw ) as $part ) {
            [ $name, $value ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
            $decoded = urldecode( $value );
            if ( isset( $parsed[ $name ] ) || ! preg_match( '~\A[a-zA-Z0-9$%,./:_-]{1,512}\z~', $value ) ||
                ! self::privateCookie( $name, $decoded, $host ) ) {
                return null;
            }
            $parsed[ $name ] = $decoded;
            if ( $name !== self::COOKIE ) {
                $forward[] = $name . '=' . $value;
            }
        }
        ksort( $parsed );
        ksort( $cookies );
        return $parsed === $cookies ? [ 'token' => $parsed[ self::COOKIE ] ?? '', 'header' => implode( '; ', $forward ) ] : null;
    }

    /** Only anonymous values PixelYourSite can set for the direct visit. Never used for a shell hit. */
    private static function privateCookie( string $name, string $value, string $host ): bool {
        return match ( $name ) {
            self::COOKIE => preg_match( '~\A[a-zA-Z0-9.-]{1,128}\z~', $value ) === 1,
            self::SESSION => function_exists( 'session_name' ) && session_name() === self::SESSION &&
                preg_match( '~\A[a-zA-Z0-9,-]{16,128}\z~', $value ) === 1,
            'pbid' => preg_match( '~\A[0-9a-f]{64}\z~i', $value ) === 1,
            '_fbp' => preg_match( '~\Afb\.[0-9]{1,2}\.[0-9]{10,13}\.[0-9]{1,20}\z~', $value ) === 1,
            'pys_first_visit', 'pys_start_session', 'pys_session_limit' => $value === 'true',
            'pysTrafficSource', 'last_pysTrafficSource' => $value === 'direct',
            'pys_landing_page', 'last_pys_landing_page' => $value === self::homeUrl( $host ),
            'pys_cd' => self::validCookieDomain( $value, $host ),
            '_ga' => preg_match( '~\AGA[12]\.[1-9][0-9]?\.[0-9]{1,20}\.[0-9]{9,13}\z~', $value ) === 1,
            '_gcl_au' => preg_match( '~\A1\.[1-9][0-9]?\.[0-9]{1,20}\.[0-9]{9,13}\z~', $value ) === 1,
            default => preg_match( '~\A_ga_[A-Z0-9]{10}\z~', $name ) === 1 &&
                preg_match( '~\AGS2\.[1-9][0-9]?\.s[0-9]{9,13}(?:\$[ogtjlh][0-9]{1,20}){1,10}\z~', $value ) === 1,
        };
    }

    private static function validCookieDomain( string $value, string $host ): bool {
        $hostname = (string) parse_url( self::homeUrl( $host ), PHP_URL_HOST );
        return $value === 'host-only' || $value === $hostname ||
            ( str_starts_with( $hostname, 'www.' ) && $value === substr( $hostname, 4 ) );
    }

    private static function homeUrl( string $host ): string {
        return 'https://' . strtolower( $host ) . '/';
    }

    public static function validToken( string $directory, string $host, array $server, string $token ): bool {
        if ( ! preg_match( '~\A(1\.([1-9][0-9]{9})\.[0-9a-f]{32})\.([0-9a-f]{64})\z~', $token, $parts ) ) {
            return false;
        }
        $age = time() - (int) $parts[2];
        $variant = self::ticketVariant( $server );
        if ( $age < 0 || $age >= self::LIFETIME || $variant === null ) {
            return false;
        }
        $key = self::readKey( $directory );
        return $key !== null && hash_equals( self::sign( $key, $host, $variant, $parts[1] ), $parts[3] );
    }

    /** A resource may omit the device hint. The validated UA stays bound to the private ticket. */
    private static function ticketVariant( array $server ): ?string {
        $profile = YSCachePixelWarm::profile( $server );
        if ( $profile === null ) {
            return null;
        }
        $profile['HTTP_SEC_CH_UA_MOBILE'] = str_contains( $profile['HTTP_USER_AGENT'], ' Mobile' ) ? '?1' : '?0';
        return YSCacheEarlyStore::requestVariant( $profile );
    }

    private static function sign( string $key, string $host, string $variant, string $claim ): string {
        return hash_hmac( 'sha256', "ys-cache-pixel/v1\nhttps://" . strtolower( $host ) . "/\n" . $variant . "\n" . $claim, $key );
    }

    private static function privateHeaders(): void {
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
        header( 'X-Accel-Expires: 0', true );
    }

    private static function restrictResource(): void {
        header_remove( 'Access-Control-Allow-Origin' );
        header_remove( 'Access-Control-Allow-Credentials' );
        header( 'Cross-Origin-Resource-Policy: same-origin', true );
        header( 'X-Content-Type-Options: nosniff', true );
        header( 'X-Robots-Tag: noindex', true );
    }

    public static function deny(): void {
        http_response_code( 403 );
        self::privateHeaders();
        self::restrictResource();
        header( 'Content-Type: text/plain; charset=UTF-8', true );
        exit;
    }

    public static function sendFallback(): void {
        http_response_code( 200 );
        self::privateHeaders();
        self::restrictResource();
        header( 'Content-Type: application/javascript; charset=UTF-8', true );
        echo "if (window.top === window && location.pathname === '/' && location.search === '') { location.replace('/?ys_cache_pixel_fallback=1'); }";
        exit;
    }

    private static function readKey( string $directory ): ?string {
        $directory = self::protectedDirectory( $directory );
        if ( $directory === null ) {
            return null;
        }
        $file = $directory . self::KEY_FILE;
        clearstatcache( true, $file );
        $info = @lstat( $file );
        if ( ! self::secureEntry( $info, 0100000, 0600 ) || $info['size'] !== 32 ||
            ( DIRECTORY_SEPARATOR === '/' && $info['nlink'] !== 1 ) ) {
            return null;
        }
        $data = @file_get_contents( $file, false, null, 0, 33 );
        return is_string( $data ) && strlen( $data ) === 32 ? $data : null;
    }

    private static function protectedDirectory( string $directory ): ?string {
        if ( $directory === '' ) {
            return null;
        }
        $directory = rtrim( $directory, '/\\' );
        clearstatcache( true, $directory );
        $info = @lstat( $directory );
        $resolved = realpath( $directory );
        if ( ! self::secureEntry( $info, 0040000, 0700 ) || $resolved === false ||
            self::normalizedPath( $resolved ) !== self::normalizedPath( $directory ) || realpath( ABSPATH ) === false ) {
            return null;
        }
        foreach ( [ ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ] as $root ) {
            $root = $root === '' ? false : realpath( $root );
            if ( $root !== false && str_starts_with( self::normalizedPath( $resolved ), self::normalizedPath( $root ) ) ) {
                return null;
            }
        }
        return $resolved;
    }

    private static function secureEntry( array|false $info, int $type, int $mode ): bool {
        return $info !== false && ( $info['mode'] & 0170000 ) === $type &&
            ( DIRECTORY_SEPARATOR !== '/' || ( $info['mode'] & 0777 ) === $mode ) &&
            ( ! function_exists( 'posix_geteuid' ) || $info['uid'] === posix_geteuid() );
    }

    private static function normalizedPath( string $path ): string {
        $path = rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
        return DIRECTORY_SEPARATOR === '/' ? $path : strtolower( $path );
    }
}
