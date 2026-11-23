<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** Verifies and purges the site's explicitly configured Nginx loopback listener. */
final class YSCacheNginx {
    private const ROOT = '/_ys-cache-nginx/';
    private const MARKERS = [ 'x-ys-nginx-purge', 'x-ys-nginx-site', 'x-ys-nginx-mode' ];
    private const MAX_PAGES = 20;
    private const MAX_RESPONSE_BYTES = 8192;
    private const TIMEOUT_MS = 3000;

    public static function configured(): bool {
        return self::hasCurl() && self::connection() !== null;
    }

    public static function fingerprint(): string {
        $connection = self::connection();
        return $connection === null ? '' : hash( 'sha256', serialize( [ $connection['port'], $connection['site'] ] ) );
    }

    public static function status(): array {
        $probe = self::probe( self::connection() );
        return [
            'available'    => $probe['mode'] !== '',
            'message'      => $probe['message'],
            'supports_all' => $probe['mode'] !== '',
        ];
    }

    public static function purge( array $urls = [] ): array {
        if ( count( $urls ) > self::MAX_PAGES ) {
            return self::result( 'error', sprintf( __( 'Clear up to %d Nginx page URLs per request.', 'ys-cache' ), self::MAX_PAGES ) );
        }
        $connection = self::connection();
        $paths = [];
        if ( $connection !== null ) {
            foreach ( $urls as $url ) {
                $path = self::pagePath( $url, $connection['site'] );
                if ( $path === null ) {
                    return self::result( 'error', __( 'Use a valid same-site page URL to clear Nginx Cache.', 'ys-cache' ) );
                }
                $paths[ $path ] = true;
            }
        }

        $probe = self::probe( $connection );
        $mode = $probe['mode'];
        if ( $mode === '' ) {
            return self::result( 'error', $probe['message'], $probe['http'], $probe['retryable'] );
        }

        $http = 0;
        foreach ( $urls === [] ? [ null ] : array_keys( $paths ) as $path ) {
            $response = self::request( $connection, 'PURGE', $path === null ? 'site' : 'page', $path );
            $http = $response['http'];
            $success = $mode === 'plus' ? $http === 204 : ( $http === 200 || ( $path !== null && $http === 412 ) );
            if ( ! $success || self::responseMode( $response, $connection['site']['host'] ) !== $mode ) {
                return self::result( 'error', __( 'The Nginx listener did not confirm cache clearing.', 'ys-cache' ), $http, $response['retryable'] );
            }
        }

        return self::result( 'ok', __( 'Nginx confirmed cache clearing.', 'ys-cache' ), $http );
    }

    private static function credentials(): ?array {
        $port = defined( 'YS_CACHE_NGINX_PURGE_PORT' ) ? YS_CACHE_NGINX_PURGE_PORT : null;
        $secret = defined( 'YS_CACHE_NGINX_PURGE_SECRET' ) ? YS_CACHE_NGINX_PURGE_SECRET : null;
        if ( is_string( $port ) && preg_match( '/\A[1-9][0-9]{3,4}\z/', $port ) ) {
            $port = (int) $port;
        }
        if ( ! is_int( $port ) || $port < 1024 || $port > 65535 || ! is_string( $secret ) ||
            ! preg_match( '/\A[A-Za-z0-9_-]{32,128}\z/', $secret ) ) {
            return null;
        }
        return compact( 'port', 'secret' );
    }

    private static function site(): ?array {
        $parts = wp_parse_url( home_url( '/' ) );
        $host = strtolower( $parts['host'] ?? '' );
        $scheme = strtolower( $parts['scheme'] ?? '' );
        $path = self::decodedPath( $parts['path'] ?? '/' );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ||
            ! preg_match( '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $host ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || $path === null ) {
            return null;
        }
        return [ 'scheme' => $scheme, 'host' => $host, 'port' => $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 ),
            'path' => rtrim( $path, '/' ) ];
    }

    private static function connection(): ?array {
        $credentials = self::credentials();
        $site = $credentials === null ? null : self::site();
        return $site === null ? null : $credentials + [ 'site' => $site ];
    }

    private static function pagePath( $url, array $site ): ?string {
        if ( ! is_string( $url ) || strlen( $url ) > 2300 || preg_match( '/[^\x21-\x7e]/', $url ) ) {
            return null;
        }
        $parts = wp_parse_url( $url );
        $scheme = strtolower( $parts['scheme'] ?? '' );
        if ( $scheme !== $site['scheme'] || strcasecmp( $parts['host'] ?? '', $site['host'] ) !== 0 ||
            ( $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 ) ) !== $site['port'] ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $decoded = self::decodedPath( $path );
        if ( $decoded === null ||
            ( $decoded !== $site['path'] && ! str_starts_with( $decoded, $site['path'] . '/' ) ) ) {
            return null;
        }
        return $path;
    }

    private static function decodedPath( string $path ): ?string {
        if ( strlen( $path ) > 2048 || ! preg_match( '/\A\/[a-zA-Z0-9._!$&\'()+,;=:@%~\/-]*\z/', $path ) ||
            str_contains( $path, '//' ) || preg_match( '/%(?:2a|2f|5c|25)|%(?![0-9a-f]{2})/i', $path ) ) {
            return null;
        }
        $decoded = rawurldecode( $path );
        return preg_match( '~[\x00-\x20\x7f*?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ? null : $decoded;
    }

    private static function hasCurl(): bool {
        return function_exists( 'curl_init' ) && function_exists( 'curl_exec' ) &&
            function_exists( 'curl_setopt_array' ) && function_exists( 'curl_getinfo' );
    }

    private static function probe( ?array $connection ): array {
        $failed = [ 'mode' => '', 'http' => 0, 'retryable' => false ];
        if ( $connection === null ) {
            return $failed + [ 'message' => __( 'Configure the Nginx loopback port, secret and WordPress site hostname.', 'ys-cache' ) ];
        }
        if ( ! self::hasCurl() ) {
            return $failed + [ 'message' => __( 'The Nginx loopback integration requires PHP cURL.', 'ys-cache' ) ];
        }
        $response = self::request( $connection, 'GET', 'status' );
        $mode = $response['http'] === 204 ? self::responseMode( $response, $connection['site']['host'] ) : '';
        return [ 'mode' => $mode, 'http' => $response['http'], 'retryable' => $response['retryable'],
            'message' => $mode !== '' ? __( 'The Nginx loopback listener is configured for page and site clearing.', 'ys-cache' )
                : __( 'Unable to verify the Nginx loopback listener for this site.', 'ys-cache' ) ];
    }

    private static function responseMode( array $response, string $host ): string {
        $headers = $response['headers'];
        $mode = $headers['x-ys-nginx-mode'] ?? '';
        return $response['ok'] && ( $headers['x-ys-nginx-purge'] ?? '' ) === 'v1' &&
            ( $headers['x-ys-nginx-site'] ?? '' ) === $host && in_array( $mode, [ 'plus', 'oss' ], true ) ? $mode : '';
    }

    private static function request( array $connection, string $method, string $route, ?string $path = null ): array {
        $curl = curl_init( 'http://127.0.0.1:' . $connection['port'] . self::ROOT . $route );
        if ( $curl === false ) {
            return [ 'ok' => false, 'http' => 0, 'headers' => [], 'retryable' => true ];
        }
        $requestHeaders = [ 'X-YS-Nginx-Secret: ' . $connection['secret'], 'Cache-Control: no-store' ];
        if ( $path !== null ) {
            $requestHeaders[] = 'X-YS-Nginx-Path: ' . $path;
        }

        $headers = [];
        $readingHeaders = false;
        $headerBytes = 0;
        $bodyBytes = 0;
        $overflow = false;
        $configured = curl_setopt_array( $curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$headers, &$readingHeaders, &$headerBytes, &$overflow ): int {
                $length = strlen( $line );
                $headerBytes += $length;
                if ( $headerBytes > self::MAX_RESPONSE_BYTES ) {
                    $overflow = true;
                    return 0;
                }
                if ( str_starts_with( $line, 'HTTP/' ) ) {
                    $readingHeaders = true;
                    $headers = [];
                } elseif ( trim( $line ) === '' ) {
                    $readingHeaders = false;
                } elseif ( $readingHeaders ) {
                    [ $name, $value ] = array_pad( explode( ':', $line, 2 ), 2, '' );
                    $name = strtolower( $name );
                    if ( in_array( $name, self::MARKERS, true ) ) {
                        $headers[ $name ] = array_key_exists( $name, $headers ) ? '' : trim( $value, "\r\n \t" );
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ( $handle, string $chunk ) use ( &$bodyBytes, &$overflow ): int {
                $length = strlen( $chunk );
                $bodyBytes += $length;
                if ( $bodyBytes > self::MAX_RESPONSE_BYTES ) {
                    $overflow = true;
                    return 0;
                }
                return $length;
            },
        ] );
        $ok = $configured && curl_exec( $curl ) !== false;
        $http = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        return [ 'ok' => $ok, 'http' => $http, 'headers' => $headers,
            'retryable' => $configured && ! $overflow && ( ! $ok || $http === 408 || $http === 429 || $http >= 500 ) ];
    }

    private static function result( string $status, string $message, int $http = 0, bool $retryable = false ): array {
        return [ 'layer' => 'Nginx', 'status' => $status, 'message' => $message, 'http' => $http, 'retryable' => $retryable ];
    }
}
