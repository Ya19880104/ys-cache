<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** Files shared by the early drop-in and WordPress's completed-response writer. */
final class YSCacheEarlyStore {
    private const FORMAT = 2;
    private const MAX_BODY = 1048576;
    private const MAX_METADATA = 16384;
    private const MAX_TTL = 604800;
    private const RENDER_INTERVAL = 120;
    private const MAX_PAGES_PER_BUCKET = 16;
    private const MAX_MARKERS_PER_BUCKET = 64;
    private const FIRST_PAGE_GENERATION = '00000000000000000000000000000000';
    private const GUEST_CART_TAG = '~<div class="ys-ec-mini-cart" id="ys-ec-mini-cart" data-nonce="([a-f0-9]{10})"\s*>~';

    private const PUBLIC_HEADERS = [
        'content-type'                        => 'Content-Type',
        'content-language'                    => 'Content-Language',
        'content-security-policy'             => 'Content-Security-Policy',
        'content-security-policy-report-only' => 'Content-Security-Policy-Report-Only',
        'cross-origin-embedder-policy'        => 'Cross-Origin-Embedder-Policy',
        'cross-origin-opener-policy'          => 'Cross-Origin-Opener-Policy',
        'cross-origin-resource-policy'        => 'Cross-Origin-Resource-Policy',
        'permissions-policy'                 => 'Permissions-Policy',
        'referrer-policy'                    => 'Referrer-Policy',
        'strict-transport-security'          => 'Strict-Transport-Security',
        'vary'                               => 'Vary',
        'x-content-type-options'             => 'X-Content-Type-Options',
        'x-frame-options'                    => 'X-Frame-Options',
        'x-permitted-cross-domain-policies'  => 'X-Permitted-Cross-Domain-Policies',
        'x-robots-tag'                       => 'X-Robots-Tag',
    ];

    public static function requestPath( array $server, array $cookies, string $host ): ?string {
        if ( ! self::validAuthority( $host ) ||
            ! in_array( $server['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ||
            ! in_array( strtolower( (string) ( $server['HTTPS'] ?? '' ) ), [ 'on', '1' ], true ) ||
            strcasecmp( (string) ( $server['HTTP_HOST'] ?? '' ), $host ) !== 0 ||
            $cookies || isset( $server['PHP_AUTH_USER'] ) || isset( $server['PHP_AUTH_PW'] ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
            return null;
        }

        foreach ( [
            'QUERY_STRING', 'HTTP_COOKIE', 'HTTP_COOKIE2', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
            'PHP_AUTH_DIGEST', 'AUTH_TYPE', 'REMOTE_USER', 'REDIRECT_REMOTE_USER',
            'HTTP_X_AUTH_TOKEN', 'HTTP_X_API_KEY', 'HTTP_ORIGIN', 'HTTP_X_REQUESTED_WITH', 'HTTP_X_WP_NONCE',
            'HTTP_RANGE', 'HTTP_IF_RANGE', 'HTTP_IF_MATCH', 'HTTP_IF_NONE_MATCH', 'HTTP_IF_MODIFIED_SINCE',
            'HTTP_IF_UNMODIFIED_SINCE', 'HTTP_CACHE_CONTROL', 'HTTP_PRAGMA', 'HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_X_METHOD_OVERRIDE',
            'HTTP_TRANSFER_ENCODING', 'TRANSFER_ENCODING',
        ] as $key ) {
            if ( isset( $server[ $key ] ) && $server[ $key ] !== '' ) {
                return null;
            }
        }
        $length = $server['CONTENT_LENGTH'] ?? '';
        $accept = (string) ( $server['HTTP_ACCEPT'] ?? '' );
        if ( ( $length !== '' && (string) $length !== '0' ) ||
            ( $accept !== '' && ! preg_match( '~(?:^|,)\s*(?:text/html|\*/\*)(?:\s*[;,]|$)~i', $accept ) ) ) {
            return null;
        }

        $path = (string) ( $server['REQUEST_URI'] ?? '' );
        return self::validRequestPath( $path ) ? $path : null;
    }

    public static function requestVariant( array $server ): ?string {
        $userAgent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
        $language = strtolower( trim( (string) ( $server['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ) );
        $mobile = (string) ( $server['HTTP_SEC_CH_UA_MOBILE'] ?? '' );
        if ( strlen( $userAgent ) > 512 || preg_match( '~[^\x20-\x7e]~', $userAgent ) !== 0 ||
            strlen( $language ) > 256 || preg_match( '~[^a-z0-9;=,. *-]~', $language ) !== 0 ||
            ! in_array( $mobile, [ '', '?0', '?1' ], true ) ) {
            return null;
        }
        return hash( 'sha256', $userAgent . "\n" . $language . "\n" . $mobile );
    }

    private static function validAuthority( string $host ): bool {
        return preg_match( '~\A(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::([1-9][0-9]{0,4}))?\z~i', $host, $parts )
            && ( ! isset( $parts[1] ) || (int) $parts[1] <= 65535 );
    }

    private static function validRequestPath( string $path ): bool {
        return strlen( $path ) <= 2048 && preg_match( '#\A/[A-Za-z0-9._~%/-]*\z#', $path )
            && self::validPathForInvalidation( $path )
            && ! preg_match( '~(?:^|/)(?:wp-admin|wp-json|wp-includes|wp-content|feed|trackback)(?:/|$)|\.php(?:/|$)~i', rawurldecode( $path ) );
    }

    private static function validPathForInvalidation( string $path ): bool {
        return str_starts_with( $path, '/' ) && strlen( $path ) <= 2048 && ! str_contains( $path, '//' )
            && ! preg_match( '~%(?:2f|5c|25|(?![a-f0-9]{2}))~i', $path )
            && ! preg_match( '~[\x00-\x20\x7f?#\\\\;]|(?:^|/)\.{1,2}(?:/|$)~', rawurldecode( $path ) );
    }

    public static function initialize( string $directory ): bool {
        $directory = rtrim( $directory, '/\\' );
        if ( self::directory( $directory ) === null ) {
            $parent = realpath( dirname( $directory ) );
            if ( $directory === '' || $parent === false ||
                ! self::samePath( $directory, $parent . DIRECTORY_SEPARATOR . basename( $directory ) ) ||
                ! self::outsideWordPress( $directory ) || ! @mkdir( $directory, 0700 ) ) {
                return false;
            }
        }
        $directory = self::directory( $directory );
        if ( $directory === null ) {
            return false;
        }
        $lockPath = $directory . '/.lock';
        if ( self::stat( $lockPath ) === false ) {
            $lock = @fopen( $lockPath, 'x+b' );
            if ( $lock !== false ) {
                @chmod( $lockPath, 0600 );
                fclose( $lock );
            }
        }
        return self::locked( $directory, LOCK_EX, static function ( string $directory ): bool {
            $marker = $directory . '/.epoch';
            return self::token( $marker ) !== null ||
                ( self::stat( $marker ) === false && self::rotateEpoch( $directory ) );
        } ) === true;
    }

    /** Capture these generations before rendering so a concurrent purge cannot publish stale HTML. */
    public static function snapshot( string $directory, string $path ): ?array {
        if ( ! self::validRequestPath( $path ) ) {
            return null;
        }
        return self::locked( $directory, LOCK_SH | LOCK_NB,
            static fn ( string $directory ): ?array => self::generations( $directory, $path ) );
    }

    public static function write( string $directory, string $host, string $path, array $snapshot, array $headers, string $html, int $ttl, string $variant, ?string $guestCartNonce = null ): bool {
        $verifiedCart = $guestCartNonce !== null && preg_match( self::GUEST_CART_TAG, $html ) === 1;
        if ( ! self::validAuthority( $host ) || ! self::validRequestPath( $path ) ||
            ! self::validVariant( $variant ) || $ttl < 1 || $ttl > self::MAX_TTL || ! self::validHtml( $html, $verifiedCart, $guestCartNonce ) ) {
            return false;
        }
        $headers = self::safeHeaders( $headers );
        if ( $headers === null ) {
            return false;
        }

        $host = strtolower( $host );
        $key = self::entryKey( $host, $path, $variant );
        return self::locked( $directory, LOCK_EX | LOCK_NB, static function ( string $directory ) use ( $host, $path, $snapshot, $headers, $html, $ttl, $variant, $verifiedCart, $key ): bool {
            if ( self::generations( $directory, $path ) !== $snapshot ) {
                return false;
            }
            $bucket = $directory . '/' . $key[0];
            if ( ! self::createBucket( $bucket ) ) {
                return false;
            }
            $metadata = json_encode( [
                'format' => self::FORMAT,
                'host' => $host,
                'path' => $path,
                'variant' => $variant,
                'generation' => $snapshot,
                'expires' => time() + $ttl,
                'headers' => $headers,
                'guest_cart' => $verifiedCart,
                'body_hash' => hash( 'sha256', $html ),
            ] );
            if ( ! is_string( $metadata ) || strlen( $metadata ) > self::MAX_METADATA ) {
                return false;
            }
            $target = $bucket . '/' . $key . '.page';
            $files = glob( $bucket . '/*.page' ) ?: [];
            if ( ! in_array( $target, $files, true ) && count( $files ) >= self::MAX_PAGES_PER_BUCKET ) {
                usort( $files, static fn ( string $left, string $right ): int => (int) @filemtime( $left ) <=> (int) @filemtime( $right ) );
                if ( ! self::safeEntry( $files[0], 0100000 ) || ! @unlink( $files[0] ) ) {
                    return false;
                }
            }
            return self::replace( $target, $metadata . "\n" . $html );
        } ) === true;
    }

    public static function read( string $directory, string $host, string $path, string $variant ): ?array {
        if ( ! self::validAuthority( $host ) || ! self::validRequestPath( $path ) || ! self::validVariant( $variant ) ) {
            return null;
        }
        $host = strtolower( $host );
        $key = self::entryKey( $host, $path, $variant );
        return self::locked( $directory, LOCK_SH | LOCK_NB,
            static fn ( string $directory ): ?array => self::readEntry( $directory, $host, $path, $variant, $key ) );
    }

    /** Let one eligible late-cache GET attempt a native render for a missing early variant. */
    public static function claimRender( string $directory, string $host, string $path, string $variant ): bool {
        if ( ! self::validAuthority( $host ) || ! self::validRequestPath( $path ) || ! self::validVariant( $variant ) ) {
            return false;
        }
        $host = strtolower( $host );
        $key = self::entryKey( $host, $path, $variant );
        return self::locked( $directory, LOCK_EX | LOCK_NB, static function ( string $directory ) use ( $host, $path, $variant, $key ): bool {
            $bucket = $directory . '/' . $key[0];
            if ( ! self::createBucket( $bucket ) ) {
                return false;
            }
            // Keep 256 slots in each of the 16 buckets. A live collision falls back to the late cache.
            $marker = $bucket . '/' . substr( $key, 1, 2 ) . '.render';
            $info = self::stat( $marker );
            if ( $info !== false && ( ! self::safeEntry( $marker, 0100000 ) || $info['mtime'] > time() - self::RENDER_INTERVAL ) ) {
                return false;
            }
            return self::generations( $directory, $path ) !== null &&
                self::readEntry( $directory, $host, $path, $variant, $key ) === null && self::replace( $marker, '1' );
        } ) === true;
    }

    /** The caller already holds the store lock. */
    private static function readEntry( string $directory, string $host, string $path, string $variant, string $key ): ?array {
        $bucket = $directory . '/' . $key[0];
        if ( ! self::safeEntry( $bucket, 0040000 ) ) {
            return null;
        }
        $record = self::readFile( $bucket . '/' . $key . '.page', self::MAX_METADATA + self::MAX_BODY + 1 );
        $newline = $record === null ? false : strpos( $record, "\n" );
        if ( $newline === false || $newline > self::MAX_METADATA ) {
            return null;
        }
        $data = json_decode( substr( $record, 0, $newline ), true, 8 );
        $now = time();
        if ( ! is_array( $data ) || ( $data['format'] ?? null ) !== self::FORMAT ||
            ( $data['host'] ?? null ) !== $host || ( $data['path'] ?? null ) !== $path || ( $data['variant'] ?? null ) !== $variant ||
            ! is_int( $data['expires'] ?? null ) || $data['expires'] <= $now || $data['expires'] > $now + self::MAX_TTL ||
            ! is_array( $data['generation'] ?? null ) || $data['generation'] !== self::generations( $directory, $path ) ||
            ! is_array( $data['headers'] ?? null ) || self::safeHeaders( $data['headers'] ) !== $data['headers'] ) {
            return null;
        }

        $html = substr( $record, $newline + 1 );
        if ( ! is_string( $data['body_hash'] ?? null ) || ! is_bool( $data['guest_cart'] ?? null ) ||
            ! self::validHtml( $html, $data['guest_cart'] ) ||
            ! hash_equals( $data['body_hash'], hash( 'sha256', $html ) ) ) {
            return null;
        }
        return [ 'headers' => $data['headers'], 'html' => $html ];
    }

    public static function invalidateAll( string $directory ): bool {
        return self::locked( $directory, LOCK_EX, [ self::class, 'rotateEpoch' ] ) === true;
    }

    public static function invalidatePath( string $directory, string $path ): bool {
        if ( ! self::validPathForInvalidation( $path ) ) {
            return false;
        }
        $key = self::pathKey( $path );
        return self::locked( $directory, LOCK_EX, static function ( string $directory ) use ( $key ): bool {
            $bucket = $directory . '/' . $key[0];
            if ( ! self::createBucket( $bucket ) ) {
                return self::rotateEpoch( $directory );
            }
            $marker = $bucket . '/' . $key . '.generation';
            if ( self::stat( $marker ) === false && count( glob( $bucket . '/*.generation' ) ?: [] ) >= self::MAX_MARKERS_PER_BUCKET ) {
                // Old page markers cannot simply be forgotten while matching artifacts or writes may exist.
                if ( ! self::rotateEpoch( $directory ) ) {
                    return false;
                }
                foreach ( glob( $bucket . '/*.generation' ) ?: [] as $old ) {
                    if ( ! self::safeEntry( $old, 0100000 ) || ! @unlink( $old ) ) {
                        return false;
                    }
                }
                return true;
            }
            return self::replace( $marker, bin2hex( random_bytes( 16 ) ) ) || self::rotateEpoch( $directory );
        } ) === true;
    }

    private static function generations( string $directory, string $path ): ?array {
        $key = self::pathKey( $path );
        $bucket = $directory . '/' . $key[0];
        if ( self::stat( $bucket ) !== false && ! self::safeEntry( $bucket, 0040000 ) ) {
            return null;
        }
        $epoch = self::token( $directory . '/.epoch' );
        $page = self::token( $bucket . '/' . $key . '.generation', self::FIRST_PAGE_GENERATION );
        return $epoch === null || $page === null ? null : [ 'epoch' => $epoch, 'page' => $page ];
    }

    private static function token( string $file, ?string $absent = null ): ?string {
        if ( self::stat( $file ) === false ) {
            return $absent;
        }
        $value = self::readFile( $file, 32 );
        return $value !== null && preg_match( '~\A[a-f0-9]{32}\z~', $value ) ? $value : null;
    }

    private static function rotateEpoch( string $directory ): bool {
        return self::replace( $directory . '/.epoch', bin2hex( random_bytes( 16 ) ) );
    }

    private static function entryKey( string $host, string $path, string $variant ): string {
        return hash( 'sha256', $host . "\n" . $path . "\n" . $variant );
    }

    private static function validVariant( string $variant ): bool {
        return preg_match( '~\A[a-f0-9]{64}\z~', $variant ) === 1;
    }

    private static function pathKey( string $path ): string {
        return hash( 'sha256', rtrim( rawurldecode( $path ), '/' ) ?: '/' );
    }

    private static function validHtml( string $html, bool $verifiedCart, ?string $guestCartNonce = null ): bool {
        if ( strlen( $html ) > self::MAX_BODY ||
            preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) ) !== 1 ||
            preg_match( '~</html\s*>~i', substr( $html, -8192 ) ) !== 1 ) {
            return false;
        }
        if ( $verifiedCart ) {
            if ( preg_match_all( self::GUEST_CART_TAG, $html, $matches, PREG_OFFSET_CAPTURE ) !== 1 ||
                ( $guestCartNonce !== null && ! hash_equals( $guestCartNonce, $matches[1][0][0] ) ) ) {
                return false;
            }
            // Inspect the rest without the one verified, unpartitioned built-in guest cart opening tag.
            $html = substr_replace( $html, '', $matches[0][0][1], strlen( $matches[0][0][0] ) );
        }
        return preg_match( '~<[a-z][a-z0-9:-]*\s[^>]*\b(?:nonce|data-[a-z0-9_:-]*nonce|data-ys-ec-partition-(?:key|generation))\s*=~i', $html ) === 0;
    }

    private static function safeHeaders( array $headers ): ?array {
        $safe = [];
        $seen = [];
        foreach ( $headers as $line ) {
            if ( ! is_string( $line ) || ! preg_match( '~\A([a-z0-9-]{1,64}):([^\x00-\x1f\x7f]*)\z~i', $line, $parts ) ) {
                return null;
            }
            $name = strtolower( $parts[1] );
            $value = trim( $parts[2] );
            if ( in_array( $name, [
                'set-cookie', 'set-cookie2', 'clear-site-data', 'location', 'refresh', 'content-disposition',
                'www-authenticate', 'proxy-authenticate', 'authentication-info', 'proxy-authentication-info',
                'x-accel-redirect', 'x-sendfile', 'content-encoding', 'content-length', 'content-range',
            ], true ) || ( $name === 'pragma' && stripos( $value, 'no-cache' ) !== false ) ||
                ( $name === 'vary' && strcasecmp( $value, 'Accept-Encoding' ) !== 0 ) ||
                ( $name === 'content-type' && ! preg_match( '~\Atext/html(?:\s*;\s*charset\s*=\s*(?:"[a-z0-9._-]+"|[a-z0-9._-]+))?\z~i', $value ) ) ||
                ( str_starts_with( $name, 'content-security-policy' ) && stripos( $value, 'nonce-' ) !== false ) ) {
                return null;
            }
            if ( ! isset( self::PUBLIC_HEADERS[ $name ] ) ) {
                continue;
            }
            if ( $value === '' || strlen( $value ) > 8192 ||
                ( isset( $seen[ $name ] ) && ! in_array( $name, [ 'content-security-policy', 'content-security-policy-report-only', 'permissions-policy' ], true ) ) ) {
                return null;
            }
            $safe[] = self::PUBLIC_HEADERS[ $name ] . ': ' . $value;
            $seen[ $name ] = true;
        }
        return isset( $seen['content-type'] ) ? $safe : null;
    }

    private static function locked( string $directory, int $flags, callable $action ): mixed {
        $directory = self::directory( $directory );
        if ( $directory === null || ! self::safeEntry( $directory . '/.lock', 0100000 ) ) {
            return null;
        }
        $handle = @fopen( $directory . '/.lock', 'r+b' );
        if ( $handle === false ) {
            return null;
        }
        try {
            return @flock( $handle, $flags ) ? $action( $directory ) : null;
        } finally {
            fclose( $handle );
        }
    }

    private static function directory( string $directory ): ?string {
        $directory = rtrim( $directory, '/\\' );
        if ( $directory === '' || ! self::safeEntry( $directory, 0040000 ) ) {
            return null;
        }
        $resolved = realpath( $directory );
        return $resolved !== false && self::samePath( $resolved, $directory ) && self::outsideWordPress( $resolved )
            ? $resolved : null;
    }

    private static function samePath( string $left, string $right ): bool {
        return self::normalizePath( $left ) === self::normalizePath( $right );
    }

    private static function normalizePath( string $path ): string {
        $path = rtrim( str_replace( '\\', '/', $path ), '/' );
        return DIRECTORY_SEPARATOR === '/' ? $path : strtolower( $path );
    }

    private static function outsideWordPress( string $directory ): bool {
        $directory = self::normalizePath( $directory ) . '/';
        if ( realpath( ABSPATH ) === false ) {
            return false;
        }
        foreach ( [ ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ] as $webRoot ) {
            $root = $webRoot === '' ? false : realpath( $webRoot );
            if ( $root !== false && str_starts_with( $directory, self::normalizePath( $root ) . '/' ) ) {
                return false;
            }
        }
        return true;
    }

    private static function createBucket( string $directory ): bool {
        if ( self::stat( $directory ) === false ) {
            @mkdir( $directory, 0700 );
        }
        return self::safeEntry( $directory, 0040000 );
    }

    private static function stat( string $path ): array|false {
        clearstatcache( true, $path );
        return @lstat( $path );
    }

    private static function safeEntry( string $path, int $type ): bool {
        $stat = self::stat( $path );
        $permissions = $type === 0040000 ? 0700 : 0600;
        return $stat !== false && ( $stat['mode'] & 0170000 ) === $type &&
            ( DIRECTORY_SEPARATOR !== '/' || ( ( $stat['mode'] & 0777 ) === $permissions && ( $type !== 0100000 || $stat['nlink'] === 1 ) ) ) &&
            ( ! function_exists( 'posix_geteuid' ) || $stat['uid'] === posix_geteuid() );
    }

    private static function readFile( string $file, int $limit ): ?string {
        if ( ! self::safeEntry( $file, 0100000 ) ) {
            return null;
        }
        $value = @file_get_contents( $file, false, null, 0, $limit + 1 );
        return is_string( $value ) && strlen( $value ) <= $limit ? $value : null;
    }

    /** All callers hold the same exclusive lock, allowing a single staged file per directory. */
    private static function replace( string $target, string $value ): bool {
        $stage = dirname( $target ) . '/.pending';
        if ( ( self::stat( $target ) !== false && ! self::safeEntry( $target, 0100000 ) ) ||
            ( self::stat( $stage ) !== false && ( ! self::safeEntry( $stage, 0100000 ) || ! @unlink( $stage ) ) ) ) {
            return false;
        }
        $handle = @fopen( $stage, 'x+b' );
        if ( $handle === false ) {
            return false;
        }
        $written = @chmod( $stage, 0600 ) && @fwrite( $handle, $value ) === strlen( $value ) && @fflush( $handle );
        fclose( $handle );
        if ( $written && @rename( $stage, $target ) ) {
            return true;
        }
        @unlink( $stage );
        return false;
    }
}
