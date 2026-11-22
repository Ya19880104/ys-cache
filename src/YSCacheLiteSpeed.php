<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheLiteSpeed {
    private const PLUGIN = 'litespeed-cache/litespeed-cache.php';
    private const MIN_VERSION = '7.9.2';
    private const HEADER = 'X-LiteSpeed-Purge: public,';

    public static function status(): array {
        if ( ! defined( 'LSCWP_V' ) || ! in_array( self::PLUGIN, (array) get_option( 'active_plugins', [] ), true ) ) {
            return [ 'available' => false, 'message' => __( 'Activate the LiteSpeed Cache plugin on this site.', 'ys-cache' ) ];
        }
        if ( is_multisite() ) {
            return [ 'available' => false, 'message' => __( 'LiteSpeed integration is unavailable on WordPress multisite.', 'ys-cache' ) ];
        }
        if ( ! is_string( LSCWP_V ) || version_compare( LSCWP_V, self::MIN_VERSION, '<' ) ||
            ! is_callable( [ '\LiteSpeed\Core', 'kick_purge_queue' ] ) ||
            ! is_callable( [ '\LiteSpeed\Purge', 'name' ] ) ||
            ! is_callable( [ '\LiteSpeed\Tag', 'get_uri_tag' ] ) ||
            ! is_callable( [ '\LiteSpeed\Root', 'cls' ] ) ||
            ! is_callable( [ '\LiteSpeed\Control', 'set_nocache_hard' ] ) ||
            ! defined( 'LiteSpeed\Purge::DB_QUEUE' ) ||
            ! defined( 'LiteSpeed\Base::O_GUEST' ) || ! defined( 'LiteSpeed\Base::O_CACHE_FORCE_URI' ) ||
            ! defined( 'LiteSpeed\Base::O_CACHE_FORCE_PUB_URI' ) ||
            ! defined( 'LSWCP_TAG_PREFIX' ) || ! is_string( LSWCP_TAG_PREFIX ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/D', LSWCP_TAG_PREFIX ) ||
            false === has_action( 'litespeed_purge', 'LiteSpeed\Purge::add' ) ||
            false === has_action( 'litespeed_purge_url' ) ) {
            return [ 'available' => false, 'message' => __( 'LiteSpeed Cache 7.9.2 or newer with its native purge hooks is required.', 'ys-cache' ) ];
        }

        // Do not use HTTP_X_LSCACHE or ADC markers supplied by an HTTP client.
        $software = (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' );
        $edition = (string) ( $_SERVER['LSWS_EDITION'] ?? '' );
        $nativeServer = strcasecmp( $software, 'LiteSpeed' ) === 0 ||
            stripos( $software, 'OpenLiteSpeed' ) === 0 || stripos( $edition, 'OpenLiteSpeed' ) === 0;
        if ( ! $nativeServer || empty( $_SERVER['X-LSCACHE'] ) || ! defined( 'LITESPEED_ON' ) || ! LITESPEED_ON ||
            ( defined( 'LITESPEED_DISABLE_ALL' ) && LITESPEED_DISABLE_ALL ) ||
            ( defined( 'LSWCP_EMPTYCACHE' ) && LSWCP_EMPTYCACHE ) || defined( 'LITESPEED_CLI' ) ) {
            return [ 'available' => false, 'message' => __( 'The LiteSpeed server cache was not verified on this request.', 'ys-cache' ) ];
        }

        if ( ! self::safeNativeOptions() ) {
            return [ 'available' => false, 'message' => __( 'Disable LiteSpeed Guest Mode and forced cache rules before enabling the integration.', 'ys-cache' ) ];
        }

        return [ 'available' => true, 'message' => __( 'LiteSpeed server cache and native purge support were detected.', 'ys-cache' ) ];
    }

    public static function noCache(): void {
        if ( ! defined( 'LSCWP_V' ) ) {
            return;
        }
        if ( is_callable( [ '\LiteSpeed\Control', 'set_nocache_hard' ] ) ) {
            \LiteSpeed\Control::set_nocache_hard( 'YS CACHE dynamic response' );
        } elseif ( false !== has_action( 'litespeed_control_set_nocache' ) ) {
            do_action( 'litespeed_control_set_nocache', 'YS CACHE dynamic response' );
        }
    }

    private static function safeNativeOptions(): bool {
        try {
            $control = \LiteSpeed\Root::cls( 'Control' );
            if ( ! is_callable( [ $control, 'conf' ] ) ) {
                return false;
            }
            foreach ( [ \LiteSpeed\Base::O_GUEST, \LiteSpeed\Base::O_CACHE_FORCE_URI, \LiteSpeed\Base::O_CACHE_FORCE_PUB_URI ] as $option ) {
                if ( ! in_array( $control->conf( $option ), [ false, 0, '0', '', [] ], true ) ) {
                    return false;
                }
            }
            return true;
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    public static function purge( array $urls = [] ): array {
        $state = self::status();
        if ( ! $state['available'] ) {
            return self::result( 'error', $state['message'] );
        }

        $endpoint = admin_url( 'admin-ajax.php' );
        $site = wp_parse_url( home_url( '/' ) );
        if ( ! self::sameSite( $site, wp_parse_url( $endpoint ) ) ) {
            return self::result( 'error', __( 'LiteSpeed purge requires a same-origin WordPress admin loopback.', 'ys-cache' ) );
        }
        $tags = self::requestedTags( $urls, $site );
        if ( $tags === [] ) {
            return self::result( 'error', __( 'Use a valid same-site page URL to clear LiteSpeed Cache.', 'ys-cache' ) );
        }

        $option = \LiteSpeed\Purge::name( \LiteSpeed\Purge::DB_QUEUE );
        // An existing queue may belong to a different request. Leave it to LiteSpeed.
        if ( self::readQueue( $option ) !== '-1' ) {
            return self::pending();
        }

        $delayPurge = static fn (): bool => true;
        add_filter( 'litespeed_delay_purge', $delayPurge, PHP_INT_MAX );
        try {
            if ( $urls === [] ) {
                do_action( 'litespeed_purge', '*' );
            } else {
                foreach ( $urls as $url ) {
                    do_action( 'litespeed_purge_url', $url );
                }
            }
        } catch ( \Throwable $error ) {
            return self::pending();
        } finally {
            remove_filter( 'litespeed_delay_purge', $delayPurge, PHP_INT_MAX );
        }

        $queued = self::readQueue( $option );
        if ( ! self::containsPublicTags( $queued, $tags ) ) {
            return self::pending();
        }

        // The vendor treats any HTTP response below 500 as successful. Check that
        // WordPress actually answered the expected, unauthenticated admin-ajax request.
        $requestSeen = false;
        $wordpressResponse = false;
        $http = 0;
        $requestFilter = static function ( array $args, string $url ) use ( $endpoint, $option, $queued, &$requestSeen ): array {
            if ( $url === $endpoint ) {
                $requestSeen = self::readQueue( $option ) === $queued;
                $args['redirection'] = 0;
                $args['timeout'] = 3;
                $args['blocking'] = true;
                $args['limit_response_size'] = 128;
                $args['headers'] = array_merge( (array) ( $args['headers'] ?? [] ), [ 'Cache-Control' => 'no-cache, no-store' ] );
            }
            return $args;
        };
        $responseFilter = static function ( array $response, array $args, string $url ) use ( $endpoint, &$wordpressResponse, &$http ): array {
            if ( $url === $endpoint ) {
                $http = (int) wp_remote_retrieve_response_code( $response );
                $wordpressResponse = $http === 400 && wp_remote_retrieve_body( $response ) === '0';
            }
            return $response;
        };
        add_filter( 'http_request_args', $requestFilter, PHP_INT_MAX, 2 );
        add_filter( 'http_response', $responseFilter, PHP_INT_MAX, 3 );
        try {
            $kicked = \LiteSpeed\Core::kick_purge_queue();
        } catch ( \Throwable $error ) {
            return self::pending( $http );
        } finally {
            remove_filter( 'http_response', $responseFilter, PHP_INT_MAX );
            remove_filter( 'http_request_args', $requestFilter, PHP_INT_MAX );
        }

        if ( ! $kicked || ! $requestSeen || ! $wordpressResponse || self::readQueue( $option ) !== '-1' ) {
            return self::pending( $http );
        }

        return self::result( 'ok', __( 'LiteSpeed origin purge dispatch confirmed.', 'ys-cache' ), $http );
    }

    public static function limitTtl( int $remaining ): bool {
        if ( $remaining < 1 || ! is_callable( [ '\LiteSpeed\Root', 'cls' ] ) ||
            ! is_callable( [ '\LiteSpeed\Control', 'set_custom_ttl' ] ) ) {
            return false;
        }
        $control = \LiteSpeed\Root::cls( 'Control' );
        if ( ! is_callable( [ $control, 'get_ttl' ] ) ) {
            return false;
        }
        $ttl = (int) $control->get_ttl();
        if ( $ttl < 1 ) {
            return false;
        }
        if ( $ttl > $remaining ) {
            \LiteSpeed\Control::set_custom_ttl( $remaining, 'YS CACHE HTML lifetime' );
        }
        return true;
    }

    private static function requestedTags( array $urls, array $site ): array {
        $prefix = LSWCP_TAG_PREFIX . '_';
        if ( $urls === [] ) {
            return [ $prefix ];
        }

        $base = rtrim( $site['path'] ?? '/', '/' );
        $tags = [];
        foreach ( $urls as $url ) {
            if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '~[\x00-\x20\x7f]~', $url ) ) {
                return [];
            }
            $parts = wp_parse_url( $url );
            if ( ! self::sameSite( $site, $parts ) ) {
                return [];
            }
            $path = $parts['path'] ?? '/';
            if ( ( $path !== $base && ! str_starts_with( $path, $base . '/' ) ) ||
                preg_match( '~[\x00-\x20\x7f,;\\\\]|(?:^|/)\.{1,2}(?:/|$)~', rawurldecode( $path ) ) ) {
                return [];
            }
            $tag = \LiteSpeed\Tag::get_uri_tag( $path );
            if ( ! is_string( $tag ) || $tag === '' ) {
                return [];
            }
            $tags[] = $prefix . $tag;
        }
        return array_unique( $tags );
    }

    private static function sameSite( $site, $target ): bool {
        if ( ! is_array( $site ) || ! is_array( $target ) || ! isset( $site['host'], $site['scheme'], $target['host'], $target['scheme'] ) ||
            isset( $target['user'] ) || isset( $target['pass'] ) ||
            isset( $target['query'] ) || isset( $target['fragment'] ) ||
            ! in_array( $site['scheme'], [ 'http', 'https' ], true ) || $target['scheme'] !== $site['scheme'] ||
            0 !== strcasecmp( $target['host'], $site['host'] ) ) {
            return false;
        }
        $default = $site['scheme'] === 'https' ? 443 : 80;
        return ( $target['port'] ?? $default ) === ( $site['port'] ?? $default );
    }

    private static function readQueue( string $option ): ?string {
        global $wpdb;
        // A different HTTP request clears the queue. WordPress's object cache in
        // the caller may still have the value it wrote before the loopback.
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
        return is_string( $value ) ? $value : null;
    }

    private static function containsPublicTags( ?string $queue, array $tags ): bool {
        if ( $queue === null || ! str_starts_with( $queue, self::HEADER ) ) {
            return false;
        }
        $section = explode( ';', substr( $queue, strlen( self::HEADER ) ), 2 )[0];
        $queuedTags = explode( ',', $section );
        return ! in_array( 'stale', $queuedTags, true ) && array_diff( $tags, $queuedTags ) === [];
    }

    private static function pending( int $http = 0 ): array {
        return self::result( 'error', __( 'LiteSpeed did not confirm origin purge dispatch.', 'ys-cache' ), $http, true );
    }

    private static function result( string $status, string $message, int $http = 0, bool $retryable = false ): array {
        return [ 'layer' => 'LiteSpeed Cache', 'status' => $status, 'message' => $message, 'http' => $http, 'retryable' => $retryable ];
    }
}
