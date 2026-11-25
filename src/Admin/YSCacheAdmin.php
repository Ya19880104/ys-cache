<?php

namespace YangSheep\Cache\Admin;

use YangSheep\Cache\YSCache;
use YangSheep\Cache\YSCacheCdnDiscovery;
use YangSheep\Cache\YSCacheEarly;
use YangSheep\Cache\YSCacheEarlyStore;
use YangSheep\Cache\YSCacheEnhanceIntegration;
use YangSheep\Cache\YSCacheEnhancePurge;
use YangSheep\Cache\YSCacheIntegrations;
use YangSheep\Cache\YSCacheLiteSpeed;
use YangSheep\Cache\YSCacheNginx;
use YangSheep\Cache\YSCachePreload;
use YangSheep\Cache\YSCacheProviders;
use YangSheep\Cache\YSCacheStorage;

defined( 'ABSPATH' ) || exit;

final class YSCacheAdmin {
    public static function register(): void {
        add_action( 'admin_menu', [ self::class, 'menu' ], 25 );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'wp_ajax_ys_cache_save', [ self::class, 'save' ] );
        add_action( 'wp_ajax_ys_cache_purge', [ self::class, 'purge' ] );
        add_action( 'wp_ajax_ys_cache_enhance', [ self::class, 'enhance' ] );
        add_action( 'wp_ajax_ys_cache_cdn_discover', [ self::class, 'discoverCdn' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( YS_CACHE_FILE ), static function ( array $links ): array {
            array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ys-cache' ) . '</a>' );
            return $links;
        } );
    }

    private static function url(): string {
        return admin_url( 'admin.php?page=ys-cache' );
    }

    public static function menu(): void {
        $label = __( 'Cache Management', 'ys-cache' );
        add_menu_page( $label, $label, 'manage_options', 'ys-cache', [ self::class, 'render' ], 'dashicons-performance', 2.1 );
    }

    public static function enqueue(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'toplevel_page_ys-cache' ) {
            return;
        }
        $url = plugin_dir_url( YS_CACHE_FILE );
        wp_enqueue_style( 'ys-cache-admin', $url . 'assets/css/ys-cache-admin.css', [], YS_CACHE_VERSION );
        wp_enqueue_script( 'ys-cache-admin', $url . 'assets/js/ys-cache-admin.js', [], YS_CACHE_VERSION, true );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = YSCache::settings();
        $blocking = YSCache::blockingCache();
        $providers = $settings['providers'];
        $delegated = YSCache::delegatesToCloud( $settings );
        $liteSpeed = YSCacheLiteSpeed::status();
        $nginx = YSCacheNginx::status();
        $storageReady = YSCache::delegatesHtml( $settings ) || YSCache::usesLiteSpeed( $settings ) || YSCacheStorage::directory() !== '';
        $hasEnhanceConnection = YSCacheProviders::enhanceConnection( $providers['enhance'] ?? [] ) !== null;
        $lastAutoPurge = YSCacheEnhancePurge::lastResult();
        $forms = YSCacheIntegrations::formPlugins();
        $integrations = YSCacheIntegrations::activeModules();
        $earlyActive = YSCacheEarly::active( $settings );
        $earlyStats = $earlyActive ? YSCacheEarlyStore::stats( YSCacheEarly::directory() ) : null;
        $preloadStatus = ! empty( $settings['preload'] ) && self::preloadSupports( 'status' ) ? YSCachePreload::status() : null;
        require YS_CACHE_DIR . 'templates/admin/settings.php';
    }

    /** 預載類別可能尚未載入或版本不同，呼叫前先確認方法存在。 */
    private static function preloadSupports( string $method ): bool {
        return class_exists( YSCachePreload::class ) && method_exists( YSCachePreload::class, $method );
    }

    private static function authorize(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! current_user_can( 'manage_options' ) ||
            ! check_ajax_referer( 'ys_cache_admin', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Access or verification failed. Reload the settings page.', 'ys-cache' ) ], 403 );
        }
    }

    public static function save(): void {
        self::authorize();
        $settings = self::postedObject( 'settings', 80000 );
        $before = YSCache::settings();
        $candidate = YSCacheProviders::sanitize( (array) ( $settings['providers'] ?? $before['providers'] ), $before['providers'] );
        $candidateSettings = [ 'providers' => $candidate ];
        $wasDelegated = YSCache::delegatesToCloud( $before );
        $delegated = YSCache::delegatesToCloud( $candidateSettings );
        $wasNginx = YSCache::usesNginx( $before );
        $usesNginx = YSCache::usesNginx( $candidateSettings );
        $enablingNginx = $usesNginx && ! $wasNginx;
        $usesLiteSpeed = YSCache::usesLiteSpeed( $candidateSettings );
        $enablingLiteSpeed = $usesLiteSpeed && ! YSCache::usesLiteSpeed( $before );
        $beforeEnhance = $before['providers']['enhance'] ?? [];
        $enhanceChanged = $candidate['enhance'] !== array_diff_key( $beforeEnhance, [ 'auto_purge' => true ] );
        $blocking = YSCache::blockingCache( $candidateSettings );
        if ( ( ! empty( $settings['enabled'] ) || $delegated || $usesLiteSpeed || $usesNginx ) && $blocking !== '' ) {
            wp_send_json_error( [ 'message' => __( 'Another page cache is active. Disable it before enabling YS CACHE.', 'ys-cache' ) ], 409 );
        }
        if ( ( $delegated && $usesLiteSpeed ) || ( $usesNginx && ( $usesLiteSpeed || ! empty( $candidate['enhance']['enabled'] ) ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Choose one server integration to manage HTML caching.', 'ys-cache' ) ], 409 );
        }
        if ( $usesLiteSpeed && empty( $candidate['litespeed']['rules_verified'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Verify the LiteSpeed server bypass rules for stateful requests before enabling the integration.', 'ys-cache' ) ], 409 );
        }
        if ( $enablingLiteSpeed ) {
            $state = YSCacheLiteSpeed::status();
            if ( ! $state['available'] ) {
                wp_send_json_error( [ 'message' => $state['message'] ], 409 );
            }
        }
        if ( $usesNginx && empty( $candidate['nginx']['rules_verified'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Verify the Nginx server bypass rules for stateful requests before enabling the integration.', 'ys-cache' ) ], 409 );
        }
        if ( $enablingNginx ) {
            $state = YSCacheNginx::status();
            if ( ! $state['available'] || ! $state['supports_all'] ) {
                wp_send_json_error( [ 'message' => $state['message'] ], 409 );
            }
        }
        if ( $delegated ) {
            $enhance = $candidate['enhance'];
            if ( empty( $enhance['rules_verified'] ) ) {
                wp_send_json_error( [ 'message' => __( 'Verify that Nginx bypasses its cache before looking up login, cart, form, and other stateful requests.', 'ys-cache' ) ], 409 );
            }
            if ( ! $wasDelegated || $enhanceChanged ) {
                $state = YSCacheEnhanceIntegration::status( $candidate );
                if ( $state['enabled'] !== true ) {
                    wp_send_json_error( [ 'message' => __( 'YS CLOUD must confirm that the server cache is enabled for this domain before it can manage HTML.', 'ys-cache' ) ], 409 );
                }
            }
        }
        if ( ! YSCache::save( $settings ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to save settings. Check database table and cache directory permissions.', 'ys-cache' ) ], 500 );
        }
        $saved = YSCache::settings();
        $autoPurge = ! empty( $saved['auto_purge'] );
        if ( ! $autoPurge && ! empty( $before['auto_purge'] ) ) {
            YSCacheEnhancePurge::cancel();
        }
        $cdnChanged = ( $candidate['alias_hosts'] ?? [] ) !== ( $before['providers']['alias_hosts'] ?? [] ) ||
            $candidate['cdn'] !== ( $before['providers']['cdn'] ?? [] );
        // 會改變哪些請求可以快取的設定：變更後一併清除伺服器與 CDN 層。
        $requestRules = array_flip( [ 'enabled', 'ttl_seconds', 'early', 'serve_cart_visitors', 'ignore_params', 'ttl_paths', 'public_cf7_paths', 'public_form_fragments', 'exclude_paths', 'exclude_cookies', 'exclude_agents' ] );
        $rulesChanged = array_intersect_key( $saved, $requestRules ) !== array_intersect_key( $before, $requestRules );
        $results = $wasDelegated && ( ! $delegated ||
            YSCacheEnhanceIntegration::fingerprint( $beforeEnhance ) !==
            YSCacheEnhanceIntegration::fingerprint( $candidate['enhance'] ) )
            ? YSCacheProviders::purge( $before['providers'], 'enhance' ) : [];
        if ( $wasNginx && ! $usesNginx ) {
            $results = array_merge( $results, YSCacheProviders::purge( $before['providers'], 'nginx' ) );
        }
        if ( ( $delegated || $usesLiteSpeed || $usesNginx || $wasNginx || $cdnChanged || $rulesChanged ) && ! in_array( 'error', array_column( $results, 'status' ), true ) ) {
            if ( $autoPurge ) {
                YSCacheEnhancePurge::onContentChange( $saved );
            } elseif ( $enablingLiteSpeed ) {
                $results = array_merge( $results, YSCacheProviders::purge( $candidate, 'litespeed' ) );
            } elseif ( $enablingNginx ) {
                $results = array_merge( $results, YSCacheProviders::purge( $candidate, 'nginx' ) );
            } elseif ( $delegated && $enhanceChanged ) {
                $results = array_merge( $results, YSCacheProviders::purge( $candidate, 'enhance' ) );
            }
        }
        wp_send_json_success( [
            'message' => __( 'Settings saved. The existing YS CACHE HTML cache was invalidated.', 'ys-cache' ),
            'delegated' => $delegated,
            'nginx_enabled' => $usesNginx,
            'litespeed_enabled' => $usesLiteSpeed,
            'local_enabled' => YSCache::usesLocalHtml( $saved ),
            'opcache_enabled' => ! empty( $candidate['opcache']['enabled'] ),
            'enhance_enabled' => ! empty( $candidate['enhance']['enabled'] ),
            'has_enhance_connection' => YSCacheProviders::enhanceConnection( $candidate['enhance'] ) !== null,
            'results' => $results,
            'alias_hosts' => $candidate['alias_hosts'] ?? [],
            'cdn' => array_map( static fn ( array $row ): array => array_intersect_key( $row, array_flip( [ 'provider', 'zone_id', 'auth_email', 'hostname', 'auto_purge', 'html_cache_verified', 'enabled' ] ) ) + [
                'has_credential' => YSCacheProviders::cdnHasCredential( $row ),
                'purge_ready' => YSCacheProviders::cdnPurgeReady( $row ),
            ], $candidate['cdn'] ),
        ] );
    }

    private static function postedObject( string $name, int $limit ): array {
        $input = $_POST[ $name ] ?? null;
        $value = is_string( $input ) && strlen( $input ) <= $limit ? json_decode( wp_unslash( $input ), true ) : null;
        if ( ! is_array( $value ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid settings format.', 'ys-cache' ) ], 400 );
        }
        return $value;
    }

    public static function enhance(): void {
        self::authorize();
        $operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
        $settings = YSCache::settings();
        $providers = $settings['providers'];
        if ( $operation === 'status' ) {
            $enhance = self::postedObject( 'connection', 5000 );
            $providers = YSCacheProviders::sanitize( [ 'enhance' => $enhance ], $providers );
            wp_send_json_success( YSCacheEnhanceIntegration::status( $providers ) );
        }
        if ( $operation === 'preview' ) {
            wp_send_json_success( YSCacheEnhanceIntegration::preview( $providers, $settings ) );
        }
        if ( $operation === 'sync' ) {
            $result = YSCacheEnhanceIntegration::sync( $providers, $settings );
            if ( ! empty( $result['added'] ) && YSCache::delegatesToCloud( $settings ) ) {
                if ( ! empty( $settings['auto_purge'] ) ) {
                    YSCache::instance()->invalidateOnChange();
                } else {
                    $result['purge'] = YSCache::purge( 'enhance' );
                }
            }
            wp_send_json_success( $result );
        }
        wp_send_json_error( [ 'message' => __( 'Invalid YS CLOUD operation.', 'ys-cache' ) ], 400 );
    }

    public static function discoverCdn(): void {
        self::authorize();
        $row = self::postedObject( 'row', 6000 );
        $index = filter_var( $_POST['index'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0, 'max_range' => YSCacheProviders::MAX_CDN_LAYERS - 1 ] ] );
        $saved = $index !== false ? ( YSCache::settings()['providers']['cdn'][ $index ] ?? [] ) : [];
        $result = YSCacheCdnDiscovery::discover( $row, $saved );
        if ( $result['status'] !== 'ok' ) {
            wp_send_json_error( $result, 422 );
        }
        wp_send_json_success( $result );
    }

    public static function purge(): void {
        self::authorize();
        $scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : '';
        if ( ! in_array( $scope, [ 'local', 'all', 'opcache', 'enhance', 'litespeed', 'nginx', 'cdn' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid purge scope.', 'ys-cache' ) ], 400 );
        }
        wp_send_json_success( [ 'results' => YSCache::purge( $scope ) ] );
    }
}
