<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** Admin-side support for Enhance's FastCGI state and additive path exclusions. */
final class YSCacheEnhanceIntegration {
    private const CACHE_ROUTE = '/nginx_fastcgi';
    private const PATHS_ROUTE = '/nginx_fastcgi_excluded_paths';
    private const SYNC_SECONDS = 12;

    public static function fingerprint( array $enhance ): string {
        $connection = YSCacheProviders::enhanceConnection( $enhance );
        return $connection === null ? '' : hash( 'sha256', $connection['endpoint'] . '|' . ( $connection['headers']['orgId'] ?? '' ) );
    }

    public static function status( array $providers ): array {
        $connection = self::connection( $providers );
        if ( $connection === null ) {
            return [ 'status' => 'skipped', 'enabled' => null, 'http' => 0,
                'message' => __( 'Configure the Enhance panel, domain UUID and API token to check FastCGI.', 'ys-cache' ) ];
        }

        $response = self::request( $connection, 'GET', self::CACHE_ROUTE );
        if ( $response['ok'] && is_bool( $response['body'] ) ) {
            $enabled = $response['body'];
            return [ 'status' => 'ok', 'enabled' => $enabled, 'http' => $response['http'],
                'message' => $enabled ? __( 'Enhance FastCGI cache is enabled.', 'ys-cache' )
                    : __( 'Enhance FastCGI cache is disabled.', 'ys-cache' ) ];
        }

        return [ 'status' => 'error', 'enabled' => null, 'http' => $response['http'],
            'message' => __( 'Unable to verify FastCGI status from Enhance.', 'ys-cache' ) ];
    }

    public static function preview( array $providers, array $settings = [] ): array {
        $preview = array_merge( self::status( $providers ), [
            'existing'    => [],
            'suggested'   => self::suggestedPaths( $settings ),
            'missing'     => [],
            'limitations' => self::limitations(),
        ] );
        if ( $preview['status'] !== 'ok' ) {
            return $preview;
        }

        $existing = self::readPaths( self::connection( $providers ) );
        if ( ! $existing['ok'] ) {
            return array_replace( $preview, [ 'status' => 'error', 'http' => $existing['http'],
                'message' => __( 'Unable to read the existing Enhance cache exclusions.', 'ys-cache' ) ] );
        }

        $preview['existing'] = $existing['body'];
        $preview['missing'] = array_values( array_diff( $preview['suggested'], $preview['existing'] ) );
        return $preview;
    }

    public static function sync( array $providers, array $settings = [] ): array {
        $preview = array_merge( self::preview( $providers, $settings ), [ 'added' => [], 'failed' => [] ] );
        if ( $preview['status'] !== 'ok' || ! $preview['missing'] ) {
            return $preview;
        }

        $connection = self::connection( $providers );
        $attempted = [];
        $deadline = microtime( true ) + self::SYNC_SECONDS;
        foreach ( $preview['missing'] as $path ) {
            if ( microtime( true ) >= $deadline ) {
                break;
            }
            $attempted[] = $path;
            $response = self::request( $connection, 'POST', self::PATHS_ROUTE, $path );
            if ( ! $response['ok'] && ( $response['http'] === 0 || in_array( $response['http'], [ 401, 403, 404, 408, 429 ], true ) || $response['http'] >= 500 ) ) {
                break;
            }
        }

        $readback = self::readPaths( $connection );
        if ( ! $readback['ok'] ) {
            return array_replace( $preview, [ 'status' => 'error', 'http' => $readback['http'], 'failed' => $attempted,
                'message' => __( 'Enhance received exclusion requests. Their final state could not be verified.', 'ys-cache' ) ] );
        }

        $added = array_values( array_intersect( $preview['missing'], $readback['body'] ) );
        $missing = array_values( array_diff( $preview['suggested'], $readback['body'] ) );
        $failed = array_values( array_intersect( $attempted, $missing ) );
        return array_replace( $preview, [
            'status'   => $missing ? 'error' : 'ok',
            'http'     => $readback['http'],
            'existing' => $readback['body'],
            'added'    => $added,
            'missing'  => $missing,
            'failed'   => $failed,
            'message'  => $missing ? __( 'Some suggested paths are still missing. Review the remaining paths before using FastCGI as the HTML cache.', 'ys-cache' )
                : __( 'The suggested paths are present in Enhance. Existing exclusions were preserved.', 'ys-cache' ),
        ] );
    }

    public static function suggestedPaths( array $settings = [] ): array {
        $urls = [ site_url( '/wp-admin/' ), site_url( '/wp-login.php' ), site_url( '/wp-admin/admin-ajax.php' ), wp_login_url(), rest_url() ];
        $commerce = [];
        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( [ 'cart', 'checkout', 'myaccount' ] as $name ) {
                $commerce[ $name ] = self::pageUrl( wc_get_page_id( $name ) );
            }
            $commerce = array_merge( $commerce, self::wooEndpointUrls( $commerce ) );
        }
        if ( defined( 'YS_ECOMMERCE_VERSION' ) ) {
            foreach ( YSCacheIntegrations::YS_CART_PAGES as $name ) {
                $commerce[] = self::pageUrl( get_option( 'ys_ec_page_' . $name . '_id', 0 ) );
            }
        }

        $paths = [];
        foreach ( array_filter( array_merge( $urls, $commerce ) ) as $url ) {
            self::addPath( $paths, self::sameSitePath( $url ) );
        }
        $manual = is_string( $settings['exclude_paths'] ?? null ) ? preg_split( '/\R/', $settings['exclude_paths'] ) : [];
        foreach ( $manual as $path ) {
            self::addPath( $paths, trim( $path ) );
        }
        // Integration extensions can supply already known public routes without a database scan.
        foreach ( (array) apply_filters( 'ys_cache_enhance_exclusion_paths', [], $settings ) as $path ) {
            if ( is_string( $path ) ) {
                self::addPath( $paths, $path );
            }
        }

        self::addTranslatedPaths( $paths, array_filter( $commerce ) );
        return array_keys( $paths );
    }

    private static function wooEndpointUrls( array $pages ): array {
        $query = function_exists( 'WC' ) ? ( WC()->query ?? null ) : null;
        if ( ! is_callable( [ $query, 'get_query_vars' ] ) || ! function_exists( 'wc_get_endpoint_url' ) ) {
            return [];
        }
        $urls = [];
        foreach ( $query->get_query_vars() as $name => $slug ) {
            $page = in_array( $name, [ 'order-pay', 'order-received' ], true ) ? $pages['checkout'] : $pages['myaccount'];
            if ( $page !== '' && is_string( $name ) && is_string( $slug ) && $slug !== '' ) {
                $urls[] = wc_get_endpoint_url( $name, '', $page );
            }
        }
        return $urls;
    }

    private static function addTranslatedPaths( array &$paths, array $urls ): void {
        if ( ! $urls || ! is_callable( [ '\\TRP_Translate_Press', 'get_trp_instance' ] ) ) {
            return;
        }
        $settings = get_option( 'trp_settings', [] );
        $published = is_array( $settings['publish-languages'] ?? null ) ? $settings['publish-languages'] : [];
        $trp = \TRP_Translate_Press::get_trp_instance();
        $converter = is_callable( [ $trp, 'get_component' ] ) ? $trp->get_component( 'url_converter' ) : null;
        if ( ! is_callable( [ $converter, 'get_url_for_language' ] ) ) {
            return;
        }
        foreach ( $published as $language ) {
            if ( ! is_string( $language ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $language ) ) {
                continue;
            }
            foreach ( $urls as $url ) {
                // TranslatePress and YS Addons can rewrite both the language prefix and the permalink slugs.
                $translated = $converter->get_url_for_language( $language, $url, '' );
                if ( is_string( $translated ) ) {
                    self::addPath( $paths, self::sameSitePath( $translated ) );
                }
            }
        }
    }

    private static function pageUrl( $id ): string {
        return (int) $id > 0 ? (string) get_permalink( (int) $id ) : '';
    }

    private static function sameSitePath( string $url ): string {
        $site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $parts = wp_parse_url( $url );
        $host = $parts['host'] ?? '';
        return is_string( $site ) && strcasecmp( $site, $host ) === 0 && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
            && in_array( $parts['scheme'] ?? '', [ 'https', 'http' ], true ) ? ( $parts['path'] ?? '' ) : '';
    }

    private static function addPath( array &$paths, string $path ): void {
        if ( strlen( $path ) > 512 || ! preg_match( '~\A/(?:[a-zA-Z0-9_][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9_][a-zA-Z0-9_.-]*/?\z~', $path ) ) {
            return;
        }
        $paths[ $path ] = true;
        $trimmed = rtrim( $path, '/' );
        if ( ! str_contains( basename( $trimmed ), '.' ) ) {
            $paths[ $trimmed ] = true;
            $paths[ $trimmed . '/' ] = true;
        }
    }

    private static function limitations(): array {
        return [
            __( 'Verify route descendants, translated aliases, non-ASCII paths, and wildcard rules in Nginx separately.', 'ys-cache' ),
            __( 'Cookies, Authorization, query strings, private/form requests, and language selection require a server-side bypass or cache key rules before the FastCGI cache lookup.', 'ys-cache' ),
        ];
    }

    private static function connection( array $providers ): ?array {
        return YSCacheProviders::enhanceConnection( (array) ( $providers['enhance'] ?? [] ) );
    }

    private static function readPaths( array $connection ): array {
        $response = self::request( $connection, 'GET', self::PATHS_ROUTE );
        $paths = $response['body'];
        $response['ok'] = $response['ok'] && is_array( $paths ) && count( array_filter( $paths, 'is_string' ) ) === count( $paths );
        if ( $response['ok'] ) {
            $response['body'] = array_values( array_unique( $paths ) );
        }
        return $response;
    }

    private static function request( array $connection, string $method, string $route, ?string $path = null ): array {
        $args = [
            'method'              => $method,
            'timeout'             => 4,
            'redirection'         => 0,
            'sslverify'           => true,
            'limit_response_size' => 32768,
            'headers'             => array_merge( $connection['headers'], [ 'Accept' => 'application/json' ] ),
        ];
        if ( $path !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $path );
        }
        $response = wp_safe_remote_request( $connection['endpoint'] . $route, $args );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'http' => 0, 'body' => null ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $html = stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'text/html' ) !== false;
        return [
            'ok'   => ! $html && $code === 200,
            'http' => $code,
            'body' => json_decode( wp_remote_retrieve_body( $response ) ),
        ];
    }
}
