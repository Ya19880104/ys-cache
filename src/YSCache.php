<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Admin\YSCacheAdmin;
use YangSheep\Cache\Admin\YSCacheAdminBar;
use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

final class YSCache {
    private static ?self $instance = null;
    private bool $privateResponse = false;
    private ?string $originCacheControl = null;
    private ?string $cachedHtml = null;
    private bool $frontendTemplate = false;
    private ?string $requestGeneration = null;
    private ?array $requestPageGenerations = null;
    private bool $externalHold = false;
    private bool $edgeHtml = false;
    private bool $liteSpeedHtml = false;
    private bool $liteSpeedCandidate = false;
    private ?string $liteSpeedNonce = null;
    private int $liteSpeedExpiry = 0;
    private ?array $pendingSharedHtml = null;
    private ?array $earlySnapshot = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        if ( is_admin() ) {
            YSCacheAdmin::register();
        }
        YSCacheAdminBar::register();
        YSCacheAssetOptimization::register();
        YSCachePixelWarm::register();
        YSCacheEarly::observeChanges();
        if ( defined( 'YS_CACHE_PIXEL_FALLBACK' ) ) {
            add_action( 'wp_head', [ YSCachePixel::class, 'restoreBrowserHome' ], PHP_INT_MIN );
        }
        if ( defined( 'YS_CACHE_PIXEL_KIND' ) ) {
            // Reassert the transport's privacy guard before the native template callbacks.
            add_action( 'send_headers', static fn() => self::sendPrivateNoStore(), PHP_INT_MAX );
        }

        add_action( 'init', [ $this, 'holdExternalHtml' ], 0, 0 );
        add_action( 'parse_request', [ $this, 'captureGeneration' ], 0, 0 );
        add_action( 'wp', [ $this, 'protectCartHeaders' ], PHP_INT_MAX );
        add_action( 'template_redirect', [ $this, 'protectPrivateResponse' ], PHP_INT_MAX );
        // PixelYourSite adds its enqueue and no-script hooks during template_redirect.
        add_action( 'template_redirect', [ $this, 'pixelResponse' ], PHP_INT_MAX );
        add_filter( 'template_include', [ $this, 'serveOrCapture' ], PHP_INT_MAX );
        add_filter( 'wp_speculation_rules_href_exclude_paths', [ $this, 'excludeTrackedNavigations' ] );
        add_filter( 'trp_translated_html', [ $this, 'rewriteTranslatedHtml' ], PHP_INT_MAX, 4 );
        add_filter( 'litespeed_buffer_before', [ $this, 'protectLiteSpeedBody' ], PHP_INT_MAX );
        add_filter( 'litespeed_buffer_finalize', [ $this, 'protectLiteSpeedBody' ], PHP_INT_MAX );
        add_action( 'litespeed_control_finalize', [ $this, 'limitLiteSpeedTtl' ], PHP_INT_MAX, 0 );

        ( new YSCacheInvalidation( [ $this, 'invalidateOnChange' ], [ $this, 'invalidateStockOnChange' ] ) )->register();
        YSCacheEnhancePurge::register();
    }

    public static function settings( bool $fresh = false ): array {
        $settings = YSCacheSettingsRepo::get( $fresh );
        if ( ! empty( $settings['auto_detect'] ) ) {
            if ( ! isset( $settings['providers']['opcache'] ) ) {
                $settings['providers']['opcache'] = [ 'enabled' => YSCacheProviders::opcacheAvailable() ];
            }
            if ( ! isset( $settings['providers']['enhance'] ) && YSCacheProviders::enhanceConnection( [] ) !== null ) {
                $settings['providers']['enhance'] = [ 'enabled' => true ];
            }
        }
        return $settings;
    }

    /** A speculative storefront render starts a tracking session before the visitor opens the link. */
    public function excludeTrackedNavigations( array $paths ): array {
        $settings = self::settings();
        if ( ( empty( $settings['early_pixel_shell'] ) && empty( $settings['guest_template'] ) ) ||
            ! self::usesLocalHtml( $settings ) || ! YSCacheIntegrations::hasVisitorEventRenderer() ) {
            return $paths;
        }
        $paths[] = '/';
        if ( function_exists( 'wc_get_permalink_structure' ) ) {
            $structure = wc_get_permalink_structure();
            foreach ( [ 'product_rewrite_slug', 'category_rewrite_slug', 'tag_rewrite_slug' ] as $key ) {
                $base = trim( (string) ( $structure[ $key ] ?? '' ), '/' );
                if ( $base !== '' && preg_match( '~^[\w/-]+$~uD', $base ) ) {
                    $paths[] = '/' . $base . '/*';
                }
            }
        }
        if ( function_exists( 'wc_get_page_id' ) ) {
            $homePath = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
            foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $page ) {
                $id = wc_get_page_id( $page );
                $path = $id > 0 ? wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) : null;
                if ( is_string( $path ) && $path !== $homePath && str_starts_with( $path, $homePath ) ) {
                    $relative = '/' . rtrim( substr( $path, strlen( $homePath ) ), '/' );
                    $paths[] = $relative;
                    $paths[] = $relative . '/*';
                }
            }
        }
        return array_values( array_unique( $paths ) );
    }

    public function pixelResponse(): void {
        if ( ! defined( 'YS_CACHE_PIXEL_KIND' ) ) {
            if ( array_key_exists( 'ys_cache_pixel', $_GET ) ) {
                nocache_headers();
                status_header( 404 );
                exit;
            }
            return;
        }
        $settings = self::settings();
        if ( empty( $settings['early_pixel_shell'] ) || ! self::usesLocalHtml( $settings )
            || ! in_array( '/', YSCacheEarly::paths( $settings['early_paths'] ?? '' ), true )
            || is_user_logged_in() || YSCacheIntegrations::bypass() || ! is_front_page() || is_404() ) {
            nocache_headers();
            status_header( 403 );
            exit;
        }
        YSCachePixel::dynamicResponse( YS_CACHE_PIXEL_KIND );
        exit;
    }

    public static function enable( ?array $settings = null, ?string $rocketMigrationId = null ): bool {
        $before = self::settings();
        $settings = self::sanitize( $settings ?? $before, $before );
        $settings['enabled'] = true;
        if ( $rocketMigrationId !== null ) {
            $settings['rocket_migration_id'] = $rocketMigrationId;
        }

        return self::blockingCache( $settings ) === '' && ( ! self::usesLocalHtml( $settings ) || YSCacheStorage::directory() !== '' ) &&
            self::persist( $settings, $before );
    }

    public static function disable(): bool {
        $settings = self::settings();
        $settings['enabled'] = false;
        return YSCacheEarly::remove() && YSCacheSettingsRepo::save( $settings );
    }

    public static function save( array $input ): bool {
        $before = self::settings();
        $settings = self::sanitize( $input, $before );
        if ( self::usesLocalHtml( $settings ) && ! self::usesLocalHtml( $before ) &&
            ( self::blockingCache( $settings ) !== '' || YSCacheStorage::directory() === '' ) ) {
            return false;
        }
        return self::persist( $settings, $before );
    }

    private static function persist( array $settings, array $before ): bool {
        if ( ! YSCacheSettingsRepo::invalidate() || ! YSCacheEarly::sync( $settings ) ) {
            return false;
        }
        if ( YSCacheSettingsRepo::save( $settings ) ) {
            return true;
        }
        YSCacheEarly::sync( $before );
        return false;
    }

    public static function delegatesHtml( array $settings ): bool {
        return self::delegatesToCloud( $settings ) || self::usesNginx( $settings );
    }

    public static function delegatesToCloud( array $settings ): bool {
        $enhance = $settings['providers']['enhance'] ?? [];
        return ! empty( $enhance['enabled'] ) && ! empty( $enhance['delegate_html'] );
    }

    public static function usesNginx( array $settings ): bool {
        return ! empty( $settings['providers']['nginx']['enabled'] );
    }

    public static function usesLocalHtml( array $settings ): bool {
        return ! empty( $settings['enabled'] ) && ! self::delegatesHtml( $settings ) && ! self::usesLiteSpeed( $settings );
    }

    public static function usesLiteSpeed( array $settings ): bool {
        return ! empty( $settings['providers']['litespeed']['enabled'] );
    }

    private static function sanitize( array $input, array $before ): array {
        $settings = array_replace( $before, $input );
        $ttl = isset( $input['ttl'] ) ? YSCacheSettingsRepo::legacyTtlToSeconds( $input['ttl'] ) : (int) $settings['ttl_seconds'];
        return [
            'enabled'         => ! empty( $settings['enabled'] ),
            'ttl_seconds'     => max( 1, min( YSCacheSettingsRepo::MAX_TTL_SECONDS, $ttl ) ),
            'ttl_unit'        => in_array( $settings['ttl_unit'], [ 's', 'min', 'hr', 'day' ], true ) ? $settings['ttl_unit'] : 's',
            'auto_purge'      => ! empty( $settings['auto_purge'] ),
            'auto_detect'     => ! empty( $settings['auto_detect'] ),
            'early_paths'     => implode( "\n", YSCacheEarly::paths( self::lines( $settings['early_paths'] ?? '' ) ) ),
            'early_pixel_shell' => ! empty( $settings['early_pixel_shell'] ),
            'guest_template' => ! empty( $settings['guest_template'] ),
            'exclude_paths'   => self::lines( $settings['exclude_paths'] ),
            'exclude_cookies' => self::lines( $settings['exclude_cookies'] ),
            'exclude_agents'  => self::lines( $settings['exclude_agents'] ),
            'ttl_paths'       => self::lines( $settings['ttl_paths'] ),
            'public_cf7_paths' => self::lines( $settings['public_cf7_paths'] ),
            'assets'          => YSCacheAssetOptimization::sanitize( (array) $settings['assets'] ),
            'providers'       => YSCacheProviders::sanitize(
                is_array( $settings['providers'] ) ? $settings['providers'] : [],
                is_array( $before['providers'] ) ? $before['providers'] : []
            ),
        ];
    }

    private static function lines( $value ): string {
        if ( is_array( $value ) ) {
            $value = implode( "\n", array_filter( $value, 'is_string' ) );
        }
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = wp_strip_all_tags( wp_check_invalid_utf8( substr( $value, 0, 12000 ) ) );
        return trim( preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value ) ?? '' );
    }

    public static function blockingCache( ?array $settings = null ): string {
        $settings ??= self::settings();
        $active = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        foreach ( $active as $plugin ) {
            $slug = explode( '/', $plugin, 2 )[0];
            if ( $slug === 'litespeed-cache' && self::usesLiteSpeed( $settings ) ) {
                continue;
            }
            if ( in_array( $slug, [ 'wp-rocket', 'wp-super-cache', 'w3-total-cache', 'litespeed-cache', 'cache-enabler', 'wp-fastest-cache', 'breeze', 'flying-press', 'swift-performance', 'swift-performance-lite', 'siteground-optimizer', 'comet-cache' ], true ) ) {
                return $slug;
            }
        }
        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( defined( 'WP_CACHE' ) && WP_CACHE && is_file( $dropin ) && filesize( $dropin ) > 0 && ! YSCacheEarly::owned() ) {
            return 'advanced-cache.php';
        }
        return '';
    }

    public function invalidateOnChange(): void {
        $settings = self::settings();
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        // Clear the lower layer first so a refilled origin cannot reuse older HTML.
        if ( ( self::usesLocalHtml( $settings ) || self::delegatesHtml( $settings ) || self::usesLiteSpeed( $settings ) || self::hasEdgeHtml( $settings ) ) &&
            ! YSCacheSettingsRepo::invalidate() ) {
            return;
        }
        YSCacheEnhancePurge::onContentChange( $settings );
    }

    public function invalidateStockOnChange( array $urls ): void {
        $settings = self::settings();
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        $aliases = (array) ( $settings['providers']['alias_hosts'] ?? [] );
        $urls = array_values( array_filter( (array) apply_filters( 'ys_cache_stock_purge_urls', $urls ), 'is_string' ) );
        $targets = [];
        foreach ( self::translatedPurgeUrls( $urls ) as $url ) {
            $target = self::pagePurgeTarget( $url, $aliases );
            if ( $target !== null ) {
                $targets[ $target['url'] ] = true;
            }
        }
        if ( $targets !== [] ) {
            YSCacheEnhancePurge::onStockChange( $settings, array_keys( $targets ) );
        }
    }

    /** Called by the background worker. Keep inventory purges limited to known pages. */
    public static function purgeStockPages( array $urls, array $providers ): array {
        $settings = self::settings();
        $targets = [];
        $paths = [];
        foreach ( $urls as $url ) {
            $target = self::pagePurgeTarget( $url );
            if ( $target !== null ) {
                $targets[ $target['url'] ] = true;
                $paths[ $target['path'] ] = true;
            }
        }
        if ( $targets === [] ) {
            return [];
        }

        $results = [];
        if ( self::usesLocalHtml( $settings ) || self::usesLiteSpeed( $settings ) || self::usesNginx( $settings ) || self::hasEdgeHtml( $settings ) ) {
            foreach ( array_keys( $paths ) as $path ) {
                if ( ! YSCacheSettingsRepo::invalidatePage( $path ) ) {
                    return [ self::pagePurgeResult( 'YS CACHE', 'error', __( 'The page cache generation could not be updated.', 'ys-cache' ) ) + [ 'retryable' => true ] ];
                }
            }
            $results[] = self::pagePurgeResult( 'YS CACHE', 'ok', __( 'Inventory page cache generations updated.', 'ys-cache' ) );
        }
        if ( ! empty( $providers['enhance']['enabled'] ) ) {
            $state = self::delegatesToCloud( $settings ) ? true : YSCacheEnhanceIntegration::status( $providers )['enabled'];
            if ( $state === true ) {
                $results[] = self::pagePurgeResult( 'YS CLOUD', 'skipped', __( 'YS CLOUD supports site-wide clearing only. Inventory changes use page-level clearing.', 'ys-cache' ) );
                return $results;
            }
            if ( $state === null ) {
                $results[] = self::pagePurgeResult( 'YS CLOUD', 'error', __( 'Unable to verify the YS CLOUD origin cache status. CDN purge was paused.', 'ys-cache' ) ) + [ 'retryable' => true ];
                return $results;
            }
        }
        return array_merge( $results, YSCacheProviders::purge( $providers, 'urls', array_keys( $targets ) ) );
    }

    private static function translatedPurgeUrls( array $urls ): array {
        if ( ! is_callable( [ '\TRP_Translate_Press', 'get_trp_instance' ] ) ) {
            return $urls;
        }
        $translate = \TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
        if ( ! is_callable( [ $translate, 'get_url_for_language' ] ) ) {
            return $urls;
        }
        $translated = $urls;
        $settings = (array) get_option( 'trp_settings', [] );
        foreach ( (array) ( $settings['publish-languages'] ?? [] ) as $language ) {
            if ( ! is_string( $language ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $language ) ) {
                continue;
            }
            foreach ( $urls as $url ) {
                $variant = $translate->get_url_for_language( $language, $url, '' );
                if ( is_string( $variant ) ) {
                    $translated[] = $variant;
                }
            }
        }
        return $translated;
    }

    public function captureGeneration(): void {
        $settings = self::settings();
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        if ( ! empty( $settings['early_paths'] ) && self::usesLocalHtml( $settings ) && YSCacheEarly::owned()
            && in_array( $uri, YSCacheEarly::paths( $settings['early_paths'] ), true )
            && YSCacheEarlyStore::requestPath( $_SERVER, $_COOKIE, YSCacheEarly::host() ) === $uri ) {
            $this->earlySnapshot = YSCacheEarlyStore::snapshot( YSCacheEarly::directory(), $uri );
        }
        if ( in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) && empty( $_SERVER['QUERY_STRING'] ) ) {
            // A render that began before a save can never repopulate the new generation.
            if ( self::delegatesToCloud( $settings ) ) {
                $this->requestGeneration = YSCacheSettingsRepo::generation();
            } elseif ( self::usesLocalHtml( $settings ) || self::usesLiteSpeed( $settings ) || self::usesNginx( $settings ) || self::hasEdgeHtml( $settings ) ) {
                $this->requestPageGenerations = YSCacheSettingsRepo::pageGenerations( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
            }
        }
    }

    public static function hasEdgeHtml( array $settings ): bool {
        foreach ( (array) ( $settings['providers']['cdn'] ?? [] ) as $layer ) {
            if ( ! is_array( $layer ) || empty( $layer['enabled'] ) || empty( $layer['html_cache_verified'] ) ||
                ! YSCacheProviders::scopeSupportsHtml( $layer ) ) {
                continue;
            }
            $hostname = $layer['hostname'] ?? '';
            if ( $hostname === '' || $hostname === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function purge( string $scope ): array {
        $settings = self::settings();
        $providers = $settings['providers'];
        if ( $scope === 'opcache' ) {
            return YSCacheProviders::purge( $providers, 'opcache' );
        }
        $results = [];
        if ( $scope === 'all' && ! empty( $providers['opcache']['enabled'] ) ) {
            $results = YSCacheProviders::opcacheAvailable() ? YSCacheProviders::purge( $providers, 'opcache' ) :
                [ self::pagePurgeResult( 'OPcache', 'skipped', __( 'OPcache reset is unavailable in this PHP runtime.', 'ys-cache' ) ) ];
            if ( ( $results[0]['status'] ?? '' ) === 'error' ) {
                return $results;
            }
        }
        if ( in_array( $scope, [ 'local', 'all', 'enhance', 'litespeed', 'nginx', 'cdn' ], true ) ) {
            $nativeOwner = self::usesLiteSpeed( $settings );
            $delegated = $nativeOwner || self::delegatesHtml( $settings );
            $ok = $delegated && $scope === 'local' ? true : YSCacheSettingsRepo::invalidate();
            $results[] = [
                'layer'   => 'YS CACHE',
                'status'  => ! $ok ? 'error' : ( $delegated ? 'skipped' : 'ok' ),
                'message' => ! $ok ? __( 'The cache generation could not be updated.', 'ys-cache' ) :
                    ( $delegated ? __( 'The server integration manages HTML caching.', 'ys-cache' ) : __( 'HTML cache cleared.', 'ys-cache' ) ),
                'http'    => 0,
            ];
            if ( ! $ok ) {
                return $results;
            }
        }
        if ( $scope !== 'local' ) {
            $providers['opcache']['enabled'] = false;
            $providerScope = $scope === 'cdn' && ( ! empty( $providers['enhance']['enabled'] ) || self::usesLiteSpeed( $settings ) || self::usesNginx( $settings ) ) ? 'all' : $scope;
            $results = array_merge( $results, YSCacheProviders::purge( $providers, $providerScope ) );
        }
        return $results;
    }

    public static function purgePage( string $url ): array {
        $settings = self::settings();
        $providers = $settings['providers'];
        $target = self::pagePurgeTarget( $url, (array) ( $providers['alias_hosts'] ?? [] ) );
        if ( $target === null ) {
            return [ self::pagePurgeResult( 'YS CACHE', 'error', __( 'Enter a public page URL on this site without a query string or fragment.', 'ys-cache' ) ) ];
        }

        $local = self::usesLocalHtml( $settings );
        $cleared = ( ! $local && ! self::hasEdgeHtml( $settings ) && ! self::usesLiteSpeed( $settings ) && ! self::usesNginx( $settings ) ) ||
            YSCacheSettingsRepo::invalidatePage( $target['path'] );
        $results = [ self::pagePurgeResult( 'YS CACHE', $cleared ? ( $local ? 'ok' : 'skipped' ) : 'error', $cleared
            ? ( $local ? __( 'This page’s HTML cache was cleared.', 'ys-cache' ) : __( 'The local HTML cache is disabled.', 'ys-cache' ) )
            : __( 'The page cache generation could not be updated.', 'ys-cache' ) ) ];
        if ( ! $cleared ) {
            return $results;
        }

        if ( ! empty( $providers['enhance']['enabled'] ) ) {
            $state = self::delegatesToCloud( $settings ) ? true : YSCacheEnhanceIntegration::status( $providers )['enabled'];
            if ( $state !== false ) {
                $results[] = self::pagePurgeResult( 'YS CLOUD', 'error', $state === true
                    ? __( 'YS CLOUD uses entire-site purges. Use Purge entire site.', 'ys-cache' )
                    : __( 'Unable to verify the YS CLOUD origin cache status. CDN purge was paused.', 'ys-cache' ) );
                return $results;
            }
        }

        return array_merge( $results, YSCacheProviders::purge( $providers, 'urls', [ $target['url'] ] ) );
    }

    private static function pagePurgeResult( string $layer, string $status, string $message ): array {
        return compact( 'layer', 'status', 'message' ) + [ 'http' => 0 ];
    }

    private static function pagePurgeTarget( string $url, array $aliases = [] ): ?array {
        $url = trim( $url );
        if ( strlen( $url ) > 2300 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return null;
        }
        $site = wp_parse_url( home_url( '/' ) );
        $target = wp_parse_url( $url );
        if ( ! isset( $site['scheme'], $site['host'], $target['scheme'], $target['host'] ) ||
            ! in_array( $site['scheme'], [ 'http', 'https' ], true ) || $site['scheme'] !== strtolower( $target['scheme'] ) ||
            ( strcasecmp( $site['host'], $target['host'] ) !== 0 && ! in_array( strtolower( $target['host'] ), $aliases, true ) ) ||
            count( array_intersect( [ 'user', 'pass', 'query', 'fragment' ], array_keys( $target ) ) ) ) {
            return null;
        }

        $defaultPort = $site['scheme'] === 'https' ? 443 : 80;
        $path = $target['path'] ?? '/';
        $decoded = rawurldecode( $path );
        $sitePath = rtrim( rawurldecode( $site['path'] ?? '/' ), '/' );
        if ( ( $site['port'] ?? $defaultPort ) !== ( $target['port'] ?? $defaultPort ) ||
            strlen( $path ) > 2048 || ! str_starts_with( $path, '/' ) || str_contains( $path, '//' ) ||
            preg_match( '~%(?:2f|5c|25)|%(?![0-9a-f]{2})~i', $path ) ||
            preg_match( '~[\x00-\x20\x7f?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ||
            ( $decoded !== $sitePath && ! str_starts_with( $decoded, $sitePath . '/' ) ) ) {
            return null;
        }

        $authority = $site['scheme'] . '://' . $site['host'];
        if ( isset( $site['port'] ) && $site['port'] !== $defaultPort ) {
            $authority .= ':' . $site['port'];
        }
        return [ 'url' => $authority . $path, 'path' => $path ];
    }

    public function holdExternalHtml(): void {
        $settings = self::settings();
        if ( ( ! self::delegatesHtml( $settings ) && ! self::hasEdgeHtml( $settings ) ) || headers_sent() ) {
            return;
        }
        $this->externalHold = ! array_filter( headers_list(), static fn ( string $header ): bool => stripos( $header, 'X-Accel-Expires:' ) === 0 );
        header( 'X-Accel-Expires: 0', true );
    }

    public function protectCartHeaders(): void {
        if ( headers_sent() ) {
            return;
        }
        $settings = self::settings();
        if ( ( ! self::usesLocalHtml( $settings ) && ! self::delegatesHtml( $settings ) &&
            ! self::hasEdgeHtml( $settings ) && ! self::usesLiteSpeed( $settings ) ) || ! self::isCartOrCheckout() ) {
            return;
        }
        self::sendPrivateNoStore();
        if ( self::usesLiteSpeed( $settings ) ) {
            YSCacheLiteSpeed::noCache();
        }
        // A vendor template can replace Cache-Control and exit before later WordPress hooks.
        // Reassert it at the first output flush without replacing an existing PHP header callback.
        ob_start( static function ( string $html ): string {
            self::sendPrivateNoStore();
            return $html;
        }, 8192 );
    }

    private static function isCartOrCheckout(): bool {
        if ( function_exists( 'WC' ) && ( is_cart() || is_checkout() ) ) {
            return true;
        }
        if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
            return false;
        }
        if ( get_query_var( 'ys_ec_checkout_partition', '' ) !== '' ) {
            return true;
        }
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        foreach ( [ 'cart', 'checkout' ] as $page ) {
            if ( (int) $post->ID === (int) get_option( 'ys_ec_page_' . $page . '_id', 0 ) ||
                has_shortcode( $post->post_content, 'ys_ec_' . $page ) ) {
                return true;
            }
        }
        return false;
    }

    private static function sendPrivateNoStore(): void {
        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
            header( 'X-Accel-Expires: 0', true );
        }
    }

    public function protectPrivateResponse(): void {
        $settings = self::settings();
        $this->edgeHtml = self::hasEdgeHtml( $settings );
        $this->liteSpeedHtml = self::usesLiteSpeed( $settings );
        if ( ( ! self::usesLocalHtml( $settings ) && ! self::delegatesHtml( $settings ) && ! $this->edgeHtml && ! $this->liteSpeedHtml ) || headers_sent() ) {
            return;
        }
        if ( self::delegatesHtml( $settings ) && ! in_array( 'X-Accel-Expires: 0', headers_list(), true ) ) {
            $this->externalHold = false;
            header( 'X-Accel-Expires: 0', true );
        }
        foreach ( headers_list() as $header ) {
            if ( preg_match( '/^(?:Cache-Control:.*(?:private|no-cache|no-store|max-age\s*=\s*0\b)|Pragma:\s*no-cache)/i', $header ) ) {
                $this->privateResponse = true;
                break;
            }
        }
        // The event renderer can generate new IDs even on a request without a PHP session.
        if ( defined( 'YS_CACHE_PIXEL_FALLBACK' ) || ( YSCacheIntegrations::hasVisitorEventRenderer() && ! YSCachePixelWarm::isVerified() ) ) {
            $this->privateResponse = true;
        }
        if ( ! $this->liteSpeedHtml || $this->edgeHtml || $this->privateResponse ) {
            // Shared HTML caching needs an explicit eligibility decision.
            $control = 'Cache-Control: private, no-cache, no-store, must-revalidate';
            if ( ! $this->privateResponse && $this->earlySnapshot !== null ) {
                // Only our own hold can be ignored when publishing to protected origin storage.
                $this->originCacheControl = $control . ', ys-cache-hold="' . bin2hex( random_bytes( 8 ) ) . '"';
                $control = $this->originCacheControl;
            }
            header( $control, true );
        }
        header( 'X-YS-Cache: BYPASS', true );
    }

    private function excludeLiteSpeedResponse(): void {
        if ( $this->liteSpeedHtml ) {
            $this->liteSpeedCandidate = false;
            YSCacheLiteSpeed::noCache();
            if ( ! headers_sent() ) {
                header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
                if ( defined( 'LSCWP_V' ) ) {
                    // LiteSpeed may have prepared its native header before the final body filter runs.
                    $esi = array_filter( headers_list(), static fn ( string $header ): bool =>
                        stripos( $header, 'X-LiteSpeed-Cache-Control:' ) === 0 && preg_match( '/,\s*esi=on\b/i', $header ) );
                    header( 'X-LiteSpeed-Cache-Control: no-cache' . ( $esi ? ',esi=on' : '' ), true );
                }
            }
        }
    }

    public function protectLiteSpeedBody( string $html ): string {
        if ( ! $this->liteSpeedHtml ) {
            return $html;
        }
        $status = http_response_code();
        $headers = headers_list();
        if ( ! $this->liteSpeedCandidate || headers_sent() || ( $status !== false && $status !== 200 ) ||
            ! self::isCompleteHtml( $html ) || ! YSCacheIntegrations::cacheableHtml( $html ) ||
            preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) || ! self::headersAllowCache( $headers ) ||
            self::headersProhibitSharedCache( $headers ) || ! $this->requestGenerationUnchanged() ||
            $this->liteSpeedExpiry <= time() || $this->liteSpeedNonce !== YSCacheIntegrations::nonceVariant() ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || ! apply_filters( 'ys_cache_allow_html', true, $html ) ) {
            $this->excludeLiteSpeedResponse();
        }
        return $html;
    }

    public function limitLiteSpeedTtl(): void {
        if ( $this->liteSpeedCandidate && ! YSCacheLiteSpeed::limitTtl( $this->liteSpeedExpiry - time() ) ) {
            $this->excludeLiteSpeedResponse();
        }
    }

    private static function match( string $list, string $subject, string $mode ): bool {
        foreach ( preg_split( '/\R/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $item ) {
            $item = trim( $item );
            if ( $item === '' ) {
                continue;
            }
            if ( $mode === 'contains' && stripos( $subject, $item ) !== false ) {
                return true;
            }
            if ( $mode === 'prefix' && ( $item === '*' || stripos( $subject, rtrim( $item, '*' ) ) === 0 ) ) {
                return true;
            }
            if ( $mode === 'path' ) {
                if ( str_ends_with( $item, '/*' ) && strcasecmp( substr( $item, 0, -2 ), rtrim( $subject, '/' ) ) === 0 ) {
                    return true;
                }
                $pattern = str_replace( '\*', '.*', preg_quote( $item, '~' ) );
                if ( preg_match( '~\A' . $pattern . '/?\z~iD', $subject ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function pathLifetime( int $lifetime, string $rules, string $path ): int {
        $decoded = rawurldecode( $path );
        foreach ( preg_split( '/\R/', $rules, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
            if ( preg_match( '~\A(/[^\s?#\\\\]*)\s+([1-9][0-9]{0,5})\z~', trim( $line ), $parts ) &&
                ( self::match( $parts[1], $path, 'path' ) || self::match( $parts[1], $decoded, 'path' ) ) ) {
                $lifetime = min( $lifetime, (int) $parts[2] );
            }
        }
        return $lifetime;
    }

    private static function cachePath( array $settings, bool &$localContactForm, bool $nativeElementor = false, bool $guestTemplate = false ): ?string {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ( $guestTemplate ) {
            $headers = $_SERVER;
            // The native template validates the remaining cookies and PHP session before lookup.
            unset( $headers['HTTP_COOKIE'] );
            if ( YSCachePixelWarm::isTemplateWarm() ) {
                unset( $headers[ YSCachePixelWarm::HEADER ] );
            }
            if ( YSCachePixelTransport::hasProtectedHeaders( $headers ) || ! empty( $headers['HTTP_X_FORWARDED_USER'] ) ||
                ! empty( $headers['HTTP_PROXY_AUTHORIZATION'] ) || ! empty( $headers['HTTP_SEC_PURPOSE'] ) ||
                ! empty( $headers['HTTP_PURPOSE'] ) || YSCacheEarlyStore::requestPath( $headers, [], YSCacheEarly::host() ) !== $uri ) {
                return null;
            }
        }
        if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || empty( $uri ) || strlen( $uri ) > 2048 ||
            strpos( $uri, '/' ) !== 0 || strpos( $uri, '//' ) !== false || strpbrk( $uri, "?#\\\r\n" ) !== false ||
            ! empty( $_GET ) || ! empty( $_SERVER['QUERY_STRING'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ||
            ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || isset( $_SERVER['PHP_AUTH_USER'] ) ||
            ! empty( $_SERVER['HTTP_RANGE'] ) || ! empty( $_SERVER['HTTP_ORIGIN'] ) ||
            ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
            return null;
        }
        $decoded = rawurldecode( $uri );
        if ( preg_match( '~[\x00-\x20\x7f?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ||
            preg_match( '~%(?:2f|5c|25)~i', $uri ) ||
            self::match( $settings['exclude_paths'], $uri, 'path' ) ||
            self::match( $settings['exclude_paths'], $decoded, 'path' ) ||
            self::match( $settings['exclude_agents'], (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 'contains' ) ) {
            return null;
        }

        // Unknown cookies can carry personalization. Allowlisting is exact and opt-in for site integrations.
        $allowed = (array) apply_filters( 'ys_cache_public_cookie_names', [] );
        foreach ( $_COOKIE as $name => $value ) {
            if ( ! is_string( $name ) ||
                ( ! in_array( $name, $allowed, true ) && ! YSCacheIntegrations::isPublicCookie( $name, $value )
                    && ! ( $nativeElementor && YSCacheIntegrations::isElementorAttributionCookie( $name ) )
                    && ! ( $guestTemplate && YSCacheGuestTemplate::allowsAttributionCookie( $name, $value ) ) ) ||
                self::match( $settings['exclude_cookies'], $name, 'prefix' ) ) {
                return null;
            }
            foreach ( YSCacheIntegrations::cookiePrefixes() as $prefix ) {
                if ( str_starts_with( $name, $prefix ) ) {
                    return null;
                }
            }
        }
        if ( empty( $_COOKIE ) && ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
            return null;
        }

        $paths = $settings['public_cf7_paths'];
        $localContactForm = $paths !== '' && self::usesLocalHtml( $settings ) && YSCacheIntegrations::supportsPublicContactForms() &&
            ( self::match( $paths, $uri, 'path' ) || self::match( $paths, $decoded, 'path' ) );
        if ( ! self::hasCanonicalAuthority() ||
            YSCacheIntegrations::bypass( $localContactForm ) || ! apply_filters( 'ys_cache_allow_request', true, $uri ) ) {
            return null;
        }
        return $uri;
    }

    private static function hasCanonicalAuthority(): bool {
        $authority = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
        if ( ! preg_match( '/\A(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::[0-9]{1,5})?\z/iD', $authority ) ) {
            return false;
        }
        $scheme = is_ssl() ? 'https' : 'http';
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $expected = wp_parse_url( home_url() );
        $actual = wp_parse_url( $scheme . '://' . $authority );
        return isset( $expected['host'], $actual['host'] ) &&
            strcasecmp( $expected['host'], $actual['host'] ) === 0 &&
            ( $expected['port'] ?? $defaultPort ) === ( $actual['port'] ?? $defaultPort );
    }

    private static function headersAllowCache( array $headers ): bool {
        foreach ( $headers as $header ) {
            [ $name, $value ] = array_pad( explode( ':', $header, 2 ), 2, '' );
            $name = strtolower( trim( $name ) );
            if ( in_array( $name, [ 'set-cookie', 'location', 'refresh', 'content-disposition', 'www-authenticate', 'x-accel-redirect', 'x-sendfile', 'content-length', 'content-encoding', 'etag', 'last-modified' ], true ) ||
                ( $name === 'content-type' && ! preg_match( '~^\s*text/html(?:;|$)~i', $value ) ) ||
                ( strpos( $name, 'content-security-policy' ) === 0 && stripos( $value, 'nonce-' ) !== false ) ) {
                return false;
            }
            if ( $name === 'vary' && array_diff( preg_split( '/\s*,\s*/', strtolower( trim( $value ) ) ), [ 'accept-encoding' ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function headersProhibitSharedCache( array $headers ): bool {
        foreach ( $headers as $header ) {
            if ( preg_match( '/^Pragma:\s*no-cache\b/i', $header ) ) {
                return true;
            }
            if ( stripos( $header, 'Cache-Control:' ) !== 0 ) {
                continue;
            }
            $directives = substr( $header, strlen( 'Cache-Control:' ) );
            if ( preg_match( '/(?:^|,)\s*(?:private|no-cache|no-store|s-maxage\s*=\s*0)\s*(?:[,=]|$)/i', $directives ) ||
                ( preg_match( '/(?:^|,)\s*max-age\s*=\s*0\s*(?:,|$)/i', $directives ) &&
                    ! preg_match( '/(?:^|,)\s*s-maxage\s*=\s*[1-9]\d*\s*(?:,|$)/i', $directives ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function isCompleteHtml( string $html ): bool {
        return preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) ) === 1 &&
            preg_match( '~</html\s*>~i', substr( $html, -8192 ) ) === 1;
    }

    public function serveOrCapture( string $template ): string {
        $settings = self::settings();
        if ( headers_sent() || is_feed() || is_preview() || is_trackback() || is_embed() || is_customize_preview() ) {
            $this->excludeLiteSpeedResponse();
            return $template;
        }

        $this->frontendTemplate = true;
        $localContactForm = false;
        if ( ! empty( $settings['early_pixel_shell'] ) && $this->privateResponse && self::usesLocalHtml( $settings )
            && $this->earlySnapshot !== null && YSCacheIntegrations::hasVisitorEventRenderer() && YSCachePixel::eligible() ) {
            YSCachePixelWarm::schedule( $settings );
        }
        if ( ! empty( $settings['guest_template'] ) && self::usesLocalHtml( $settings ) &&
            ( YSCachePixelWarm::isTemplateWarm() || ( ! YSCachePixelWarm::headerPresent() && $this->privateResponse ) ) &&
            ! defined( 'YS_CACHE_PIXEL_FALLBACK' ) && ! defined( 'YS_CACHE_PIXEL_KIND' ) &&
            ! is_user_logged_in() && ! is_404() && ! is_search() && ( ! is_singular() || ! post_password_required() ) &&
            YSCacheIntegrations::hasVisitorEventRenderer() && YSCacheIntegrations::hasOnlyAttributionSession() &&
            self::blockingCache( $settings ) === '' ) {
            $profile = YSCachePixelWarm::profile( $_SERVER );
            $path = $profile === null ? null : self::cachePath( $settings, $localContactForm, false, true );
            if ( $path !== null && ! $localContactForm && YSCacheGuestTemplate::isPublicRoute( $path ) ) {
                $this->excludeLiteSpeedResponse();
                // The surrounding response remains private on both hits and neutral builds.
                self::sendPrivateNoStore();
                $delegatedTemplate = YSCacheGuestTemplate::route( $template, $settings, $path, $profile, YSCachePixelWarm::isTemplateWarm() );
                if ( $delegatedTemplate !== $template ) {
                    $this->captureAssets( $settings['providers'] );
                    return $delegatedTemplate;
                }
            }
        }
        if ( YSCachePixelWarm::isVerified() ) {
            $path = self::cachePath( $settings, $localContactForm );
            if ( $path === '/' && ! $this->privateResponse && $this->earlySnapshot !== null && ! $localContactForm
                && self::usesLocalHtml( $settings ) && ! empty( $settings['early_pixel_shell'] ) && YSCachePixel::eligible()
                && ! is_user_logged_in() && ! is_404() && $this->rewriteAssetsHere()
                && self::blockingCache( $settings ) === '' && self::headersAllowCache( headers_list() )
                && YSCacheIntegrations::nonceVariant() !== null ) {
                $settings['ttl_seconds'] = self::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'], '/' );
                $this->capturePixelShell( $settings );
            }
            // A neutral build must never populate a normal page cache or release upstream HTML.
            $this->excludeLiteSpeedResponse();
            return $template;
        }
        // Elementor already renders its dynamic widgets on every request. We can restrict
        // its existing document cache on session pages without sharing the full response.
        $nativeElementor = $this->privateResponse && defined( 'ELEMENTOR_VERSION' ) && self::usesLocalHtml( $settings )
            && is_singular() && ! is_user_logged_in() && ! post_password_required()
            && ! is_404() && ! is_search() && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'GET'
            && YSCacheIntegrations::hasOnlyAttributionSession();
        $path = self::cachePath( $settings, $localContactForm, $nativeElementor );
        if ( $path === null || $this->privateResponse || is_user_logged_in() || is_404() || is_search() ||
            ( is_singular() && post_password_required() ) ) {
            $this->excludeLiteSpeedResponse();
            if ( $nativeElementor && $path !== null && ! $localContactForm && self::blockingCache( $settings ) === '' ) {
                $post = get_queried_object();
                $language = YSCacheIntegrations::languageVariant();
                if ( $post instanceof \WP_Post && $post->post_status === 'publish' && $post->post_password === '' && $language !== null ) {
                    YSCacheElementor::register(
                        (int) $post->ID, $path, $language, $this->requestPageGenerations ?? YSCacheSettingsRepo::pageGenerations( $path ),
                        self::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'], $path )
                    );
                }
            }
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        $settings['ttl_seconds'] = self::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'], $path );
        if ( $localContactForm ) {
            $settings['ttl_seconds'] = min( $settings['ttl_seconds'], 300 );
            $this->edgeHtml = false;
        }
        $delegated = self::delegatesHtml( $settings );
        $local = self::usesLocalHtml( $settings );
        $externalLanguageSafe = ( $delegated || $this->edgeHtml || $this->liteSpeedHtml ) && YSCacheIntegrations::allowsExternalHtml();
        $sharedSafe = $this->externalHold && $externalLanguageSafe;
        $fastcgi = $sharedSafe && $delegated && ( self::usesNginx( $settings )
            ? ! empty( $settings['providers']['nginx']['rules_verified'] ) && YSCacheNginx::configured()
            : ! empty( $settings['providers']['enhance']['rules_verified'] ) );
        $this->edgeHtml = $sharedSafe && $this->edgeHtml && ( ! $delegated || $fastcgi );
        $this->liteSpeedCandidate = $this->liteSpeedHtml && $externalLanguageSafe && ! $delegated &&
            ! empty( $settings['providers']['litespeed']['rules_verified'] );
        if ( $this->liteSpeedHtml && ! $this->liteSpeedCandidate ) {
            $this->excludeLiteSpeedResponse();
            $this->edgeHtml = false;
        }
        if ( ( ! $local && ! $fastcgi && ! $this->edgeHtml && ! $this->liteSpeedCandidate ) || self::blockingCache( $settings ) !== '' ) {
            $this->excludeLiteSpeedResponse();
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        $language = YSCacheIntegrations::languageVariant();
        $nonce = $language === null ? null : YSCacheIntegrations::nonceVariant();
        $status = http_response_code();
        if ( $nonce === null || ( $status !== false && $status !== 200 ) ||
            ! self::headersAllowCache( headers_list() ) ) {
            $this->excludeLiteSpeedResponse();
            $this->captureAssets( $settings['providers'] );
            return $template;
        }
        if ( $this->liteSpeedCandidate ) {
            $this->liteSpeedNonce = $nonce;
            $this->liteSpeedExpiry = time() + YSCacheIntegrations::externalHtmlTtl( $settings['ttl_seconds'] );
        }

        if ( ! $local ) {
            if ( $fastcgi || $this->edgeHtml ) {
                $this->captureHtml( $settings, $nonce, null, null, $fastcgi );
            } else {
                $this->captureAssets( $settings['providers'] );
            }
            return $template;
        }

        $directory = YSCacheStorage::directory();
        if ( $directory === '' ) {
            $this->captureAssets( $settings['providers'] );
            return $template;
        }
        $this->requestPageGenerations ??= YSCacheSettingsRepo::pageGenerations( $path );
        $key = hash( 'sha256', implode( "\n", [
            YS_CACHE_VERSION, home_url(), is_ssl() ? 'https' : 'http', $path, determine_locale(), $language, $nonce,
            implode( ':', $this->requestPageGenerations ), hash( 'sha256', serialize( $settings ) ),
            $localContactForm ? (string) WPCF7_VERSION : '',
        ] ) );
        $remaining = null;
        $html = YSCacheStorage::read( $directory, $key, $settings['ttl_seconds'], $remaining );
        if ( $html !== null ) {
            if ( $this->earlySnapshot !== null && $this->originCacheControl !== null && ! $localContactForm
                && $_SERVER['REQUEST_METHOD'] === 'GET' && $this->rewriteAssetsHere()
                && ! has_filter( 'ys_cache_allow_request' ) && ! has_filter( 'ys_cache_allow_html' ) ) {
                $variant = YSCacheEarlyStore::requestVariant( $_SERVER );
                if ( $variant !== null && YSCacheEarlyStore::claimRender( YSCacheEarly::directory(), YSCacheEarly::host(), $path, $variant ) ) {
                    header( 'X-YS-Cache: MISS', true );
                    $this->captureHtml( $settings, $nonce, $directory, $key, false, false, true );
                    return $template;
                }
            }
            header( 'X-YS-Cache: HIT', true );
            $this->cachedHtml = $html;
            if ( $this->edgeHtml && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                $this->releaseSharedHtml( $html, $this->sharedHtmlState( $nonce, $remaining ?? 0, false ) );
            }
            return YS_CACHE_DIR . 'templates/cached-page.php';
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'HEAD' ) {
            return $template;
        }
        header( 'X-YS-Cache: MISS', true );
        $this->captureHtml( $settings, $nonce, $directory, $key, false, $localContactForm );
        return $template;
    }

    private function captureHtml( array $settings, string $nonce, ?string $directory, ?string $key, bool $fastcgi = false, bool $localContactForm = false, bool $earlyOnly = false ): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) {
            return;
        }
        $baseline = $this->htmlResponseHeaders();
        $shared = $fastcgi || $this->edgeHtml ? $this->sharedHtmlState( $nonce, $settings['ttl_seconds'], $fastcgi ) : null;
        $complete = true;
        ob_start( function ( string $html, int $phase ) use ( &$complete, $baseline, $directory, $key, $settings, $shared, $localContactForm, $earlyOnly ): string {
            if ( !( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
                $complete = false;
                return $html;
            }
            $responseHtml = $this->rewriteAssets( $html, $settings['providers'] );
            $status = http_response_code();
            if ( ! $complete || headers_sent() || ( $status !== false && $status !== 200 ) ||
                $this->htmlResponseHeaders() !== $baseline || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
                ! self::isCompleteHtml( $html ) ||
                preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ||
                ! YSCacheIntegrations::cacheableHtml( $html, $localContactForm ) ||
                ! apply_filters( 'ys_cache_allow_html', true, $html ) ) {
                if ( $directory !== null && ! headers_sent() ) {
                    header( 'X-YS-Cache: BYPASS', true );
                }
                return $responseHtml;
            }

            if ( $directory !== null ) {
                if ( ! $earlyOnly ) {
                    YSCacheStorage::write( $directory, $key, $html );
                }
                if ( ! $localContactForm && $this->rewriteAssetsHere() ) {
                    $this->storeEarly( $settings, $responseHtml, $baseline );
                }
            }
            if ( $shared !== null ) {
                $this->releaseSharedHtml( $html, $shared );
            }
            return $responseHtml;
        } );
    }

    private function storeEarly( array $settings, string $html, array $headers ): void {
        if ( headers_sent() || $this->earlySnapshot === null || ! $this->requestGenerationUnchanged() || has_filter( 'ys_cache_allow_request' )
            || has_filter( 'ys_cache_allow_html' ) || is_user_logged_in() || ! self::headersAllowCache( $headers ) ) {
            return;
        }
        if ( $this->originCacheControl === null || ! in_array( $this->originCacheControl, $headers, true ) ) {
            return;
        }
        $headers = array_values( array_diff( $headers, [ $this->originCacheControl ] ) );
        if ( self::headersProhibitSharedCache( $headers ) ) {
            return;
        }
        $variant = YSCacheEarlyStore::requestVariant( $_SERVER );
        $path = YSCacheEarlyStore::requestPath( $_SERVER, $_COOKIE, YSCacheEarly::host() );
        $ttl = YSCacheIntegrations::externalHtmlTtl( $settings['ttl_seconds'] );
        if ( $variant !== null && $path !== null && $ttl > 0 ) {
            $cartNonce = defined( 'YS_ECOMMERCE_VERSION' ) && YSCacheIntegrations::nonceVariant() !== null
                ? wp_create_nonce( 'ys_ec_cart_nonce' ) : null;
            YSCacheEarlyStore::write( YSCacheEarly::directory(), YSCacheEarly::host(), $path, $this->earlySnapshot, $headers, $html, $ttl, $variant, $cartNonce );
        }
    }

    private function capturePixelShell( array $settings ): void {
        $baseline = $this->htmlResponseHeaders();
        $complete = true;
        ob_start( function ( string $html, int $phase ) use ( &$complete, $baseline, $settings ): string {
            if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
                $complete = false;
                return $html;
            }
            $status = http_response_code();
            if ( ! $complete || headers_sent() || ( $status !== false && $status !== 200 ) || $this->htmlResponseHeaders() !== $baseline
                || ! self::isCompleteHtml( $html ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE )
                || preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ) {
                return $html;
            }
            $shell = YSCachePixel::shell( $html );
            if ( $shell !== null && YSCacheIntegrations::cacheableHtml( $shell ) && apply_filters( 'ys_cache_allow_html', true, $shell ) ) {
                $shell = YSCacheCarousel::completeHeroSources( $shell );
                $this->storeEarly( $settings, $this->rewriteAssets( $shell, $settings['providers'] ), $baseline );
            }
            return $html;
        } );
    }

    private function sharedHtmlState( string $nonce, int $ttl, bool $fastcgi ): array {
        return [ 'nonce' => $nonce, 'expiry' => time() + YSCacheIntegrations::externalHtmlTtl( $ttl ),
            'fastcgi' => $fastcgi, 'headers' => $this->htmlResponseHeaders() ];
    }

    private function htmlResponseHeaders(): array {
        $headers = headers_list();
        if ( ! $this->liteSpeedCandidate ) {
            return $headers;
        }
        // Native cache metadata is written at shutdown, before WordPress flushes its output buffers.
        return array_values( array_filter( $headers, static fn ( string $header ): bool =>
            ! preg_match( '/^X-LiteSpeed-(?:Cache-Control:\s*public(?:,|$)|Tag:|Purge:)/i', $header ) ) );
    }

    private function releaseSharedHtml( string $html, array $state, bool $translated = false ): void {
        if ( ! $translated && ! $this->rewriteAssetsHere() ) {
            // TranslatePress must finish replacing media and markup before an outer cache stores the response.
            $this->pendingSharedHtml = $state;
            return;
        }
        $remaining = $state['expiry'] - time();
        if ( $remaining <= 0 || headers_sent() || $this->htmlResponseHeaders() !== $state['headers'] ||
            ! YSCacheIntegrations::cacheableHtml( $html ) || preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || $state['nonce'] !== YSCacheIntegrations::nonceVariant() ) {
            return;
        }
        if ( ! $this->requestGenerationUnchanged() || $this->htmlResponseHeaders() !== $state['headers'] ) {
            return;
        }
        header( $state['fastcgi'] ? 'X-Accel-Expires: @' . $state['expiry'] : 'X-Accel-Expires: 0', true );
        header( 'Cache-Control: public, max-age=0' . ( $this->edgeHtml ? ', s-maxage=' . $remaining : '' ) . ', must-revalidate', true );
        if ( $state['fastcgi'] ) {
            header( 'X-YS-Cache: DELEGATED', true );
        }
    }

    private function requestGenerationUnchanged(): bool {
        return $this->requestPageGenerations !== null
            ? $this->requestPageGenerations === YSCacheSettingsRepo::pageGenerations( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) )
            : $this->requestGeneration === YSCacheSettingsRepo::generation();
    }

    public function renderCachedPage(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'HEAD' ) {
            // This is the complete guest HTML response rendered by WordPress.
            echo $this->rewriteAssets( $this->cachedHtml ?? '', self::settings()['providers'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    private function captureAssets( array $providers ): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || ! $this->rewriteAssetsHere() ||
            ( ! YSCacheProviders::hasUrlRewriting( $providers ) && ( ! function_exists( 'is_front_page' ) || ! is_front_page() ) ) ) {
            return;
        }
        ob_start( function ( string $html, int $phase ) use ( $providers ): string {
            return ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && !( $phase & PHP_OUTPUT_HANDLER_CLEAN )
                ? $this->rewriteAssets( $html, $providers ) : $html;
        } );
    }

    private function rewriteAssetsHere(): bool {
        global $TRP_LANGUAGE;
        if ( ! class_exists( '\TRP_Translate_Press' ) ) {
            return true;
        }
        $settings = get_option( 'trp_settings', [] );
        return empty( $TRP_LANGUAGE ) || $TRP_LANGUAGE === ( $settings['default-language'] ?? '' );
    }

    private function rewriteAssets( string $html, array $providers ): string {
        if ( ! $this->rewriteAssetsHere() || ! preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) ) ) {
            return $html;
        }
        $html = YSCacheProviders::rewriteHtml( $html, $providers );
        return str_contains( $html, 'ysslide-swiper' ) ? YSCacheCarousel::rewriteHtml( $html ) : $html;
    }

    public function rewriteTranslatedHtml( string $html, $language, $source, $preview ): string {
        if ( ! $this->frontendTemplate || ! empty( $preview ) ) {
            return $html;
        }
        $html = YSCacheProviders::rewriteHtml( $html, self::settings()['providers'] );
        if ( str_contains( $html, 'ysslide-swiper' ) ) {
            $html = YSCacheCarousel::rewriteHtml( $html );
        }
        if ( $this->pendingSharedHtml !== null ) {
            $this->releaseSharedHtml( $html, $this->pendingSharedHtml, true );
            $this->pendingSharedHtml = null;
        }
        return $html;
    }
}
