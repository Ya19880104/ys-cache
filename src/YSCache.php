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
    /** 早期快取寫入候選：YSCacheEarlyRequest::writerPath() 回傳的路徑與變體。 */
    private ?array $earlyRequest = null;
    private ?array $earlySnapshot = null;
    /** TranslatePress 非預設語言：等 trp_translated_html 輸出翻譯結果後再寫入。 */
    private ?array $pendingEarly = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        if ( is_admin() ) {
            YSCacheAdmin::register();
        }
        YSCacheAdminBar::register();
        YSCacheAssetOptimization::register();
        YSCachePreload::register();
        YSCacheEarly::observeChanges();
        // 外掛更新後，舊版 drop-in 仍以舊格式呼叫讀取器；maybeUpgrade() 只在 drop-in 屬於 YS CACHE 時重新同步。
        add_action( 'init', static function (): void {
            YSCacheEarly::maybeUpgrade( self::settings() );
        }, 1, 0 );

        add_action( 'init', [ $this, 'holdExternalHtml' ], 0, 0 );
        add_action( 'parse_request', [ $this, 'captureGeneration' ], 0, 0 );
        add_action( 'wp', [ $this, 'protectCartHeaders' ], PHP_INT_MAX );
        add_action( 'template_redirect', [ $this, 'protectPrivateResponse' ], PHP_INT_MAX );
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

    /**
     * 預取購物車、結帳或帳戶頁可能建立 WooCommerce session，之後的瀏覽都會略過快取。
     * 其他公開頁面（首頁、商品、分類、標籤、商店）都可以預取，命中快取時也不會啟動 WordPress。
     */
    public function excludeTrackedNavigations( array $paths ): array {
        if ( ! self::usesLocalHtml( self::settings() ) || ! function_exists( 'wc_get_page_id' ) ) {
            return $paths;
        }
        $homePath = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
        foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
            $id = wc_get_page_id( $page );
            $path = $id > 0 ? wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) : null;
            if ( is_string( $path ) && $path !== $homePath && str_starts_with( $path, $homePath ) ) {
                $relative = '/' . rtrim( substr( $path, strlen( $homePath ) ), '/' );
                $paths[] = $relative;
                $paths[] = $relative . '/*';
            }
        }
        return array_values( array_unique( $paths ) );
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
        if ( ! YSCacheSettingsRepo::invalidate() ) {
            return false;
        }
        // 早期快取預設開啟；主機無法接上讀取器時（沒有 WP_CACHE、不是 HTTPS、多站台等）仍要能儲存設定，
        // 網站改用 WordPress 內的本機快取。先卸下舊的 drop-in，避免它繼續以舊規則送出頁面。
        if ( ! YSCacheEarly::sync( $settings ) && ! YSCacheEarly::remove() ) {
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
            'early'           => ! empty( $settings['early'] ),
            'serve_cart_visitors' => ! empty( $settings['serve_cart_visitors'] ),
            'preload'         => ! empty( $settings['preload'] ),
            'ignore_params'   => self::lines( $settings['ignore_params'] ?? '' ),
            // 0.1 版的路徑清單、pixel shell 與 guest template 已停用，只保留欄位供遷移判斷。
            'early_paths'     => '',
            'early_pixel_shell' => false,
            'guest_template'  => false,
            'exclude_paths'   => self::lines( $settings['exclude_paths'] ),
            'exclude_cookies' => self::lines( $settings['exclude_cookies'] ),
            'exclude_agents'  => self::lines( $settings['exclude_agents'] ),
            'ttl_paths'       => self::lines( $settings['ttl_paths'] ),
            'public_cf7_paths' => self::lines( $settings['public_cf7_paths'] ),
            'public_form_fragments' => ! empty( $settings['public_form_fragments'] ),
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

    /**
     * 立即清除這些頁面：本機快取同步清除（同時通知預載重新產生），CDN 與主機層照舊排入背景佇列。
     * $content 為 true 表示包含庫存以外的內容變更；YS CLOUD 只能整站清除，這時改清全站。
     */
    public function invalidateStockOnChange( array $urls, bool $content = false ): void {
        $settings = self::settings();
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        if ( $content && self::delegatesToCloud( $settings ) ) {
            $this->invalidateOnChange();
            return;
        }
        $aliases = (array) ( $settings['providers']['alias_hosts'] ?? [] );
        $urls = array_values( array_filter( (array) apply_filters( 'ys_cache_stock_purge_urls', $urls ), 'is_string' ) );
        $targets = [];
        $paths = [];
        foreach ( self::translatedPurgeUrls( $urls ) as $url ) {
            $target = self::pagePurgeTarget( $url, $aliases );
            if ( $target !== null ) {
                $targets[ $target['url'] ] = true;
                $paths[ $target['path'] ] = true;
            }
        }
        if ( $targets === [] ) {
            return;
        }
        if ( self::usesLocalHtml( $settings ) ) {
            // 早期儲存區清除失敗時 YSCacheEarly 會卸下 drop-in，背景工作的 purgeStockPages() 便會再清一次本機頁面。
            foreach ( array_keys( $paths ) as $path ) {
                YSCacheSettingsRepo::invalidatePage( $path );
            }
        }
        YSCacheEnhancePurge::onStockChange( $settings, array_keys( $targets ) );
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
            // 早期快取的頁面已在變更當下由 invalidateStockOnChange() 同步清除；再清一次只會丟掉預載剛補上的頁面。
            foreach ( YSCacheEarly::active( $settings ) ? [] : array_keys( $paths ) as $path ) {
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
        if ( YSCacheEarly::active( $settings ) ) {
            // 只有乾淨請求（無查詢字串、無 session 或狀態 cookie）的渲染可以寫入早期快取。
            $request = YSCacheEarlyRequest::writerPath( $_SERVER, $_COOKIE, YSCacheEarly::config( $settings ) );
            $snapshot = ( $request === null || is_user_logged_in() ) ? null : YSCacheEarlyStore::snapshot( YSCacheEarly::directory(), $request['path'] );
            if ( $snapshot !== null ) {
                $this->earlyRequest = $request;
                $this->earlySnapshot = $snapshot;
                // 這次渲染會給所有訪客共用：不讓 PixelYourSite 建立 PHP session（命中時本來就不會執行 PHP，行為一致）。
                add_filter( 'pys_skip_session', '__return_true', PHP_INT_MAX );
            }
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
        if ( ! $this->liteSpeedHtml || $this->edgeHtml || $this->privateResponse ) {
            // Shared HTML caching needs an explicit eligibility decision.
            $control = 'Cache-Control: private, no-cache, no-store, must-revalidate';
            if ( ! $this->privateResponse && $this->earlySnapshot !== null ) {
                // 早期快取候選與命中時一樣不送 no-store（保留上一頁快取）；hold 權杖讓寫入端分辨自己的標頭與外掛送出的標頭。
                $this->originCacheControl = 'Cache-Control: private, no-cache, must-revalidate, ys-cache-hold="' . bin2hex( random_bytes( 8 ) ) . '"';
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

    private static function cachePath( array $settings, bool &$localContactForm ): ?string {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
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
                ( ! in_array( $name, $allowed, true ) && ! YSCacheIntegrations::isPublicCookie( $name, $value ) ) ||
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
        $fragments = ! empty( $settings['public_form_fragments'] );
        $localContactForm = $paths !== '' && self::usesLocalHtml( $settings ) &&
            ( self::match( $paths, $uri, 'path' ) || self::match( $paths, $decoded, 'path' ) ) &&
            ( $fragments ? YSCachePublicForms::fingerprint() !== '' : YSCacheIntegrations::supportsPublicContactForms() );
        if ( $localContactForm && ! $fragments ) {
            $post = get_queried_object();
            if ( ! $post instanceof \WP_Post || ! YSCachePublicForms::dedicatedContactContent( $post->post_content ) ) {
                return null;
            }
        }
        if ( ! self::hasCanonicalAuthority() ||
            YSCacheIntegrations::bypass( $localContactForm, $fragments ) || ! apply_filters( 'ys_cache_allow_request', true, $uri ) ) {
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
            $this->releaseEarlyHold();
            return $template;
        }

        $this->frontendTemplate = true;
        if ( YSCacheEarly::active( $settings ) ) {
            // 早期讀取器啟用時不讀寫舊的本機快取：只有符合條件的乾淨渲染會寫入早期儲存區。
            if ( $this->earlyEligible( $settings ) ) {
                header( 'X-YS-Cache: MISS', true );
                $this->captureEarly( $settings );
                return $template;
            }
            $this->releaseEarlyHold();
            if ( ! self::publicFormPath( $settings ) ) {
                $this->excludeLiteSpeedResponse();
                $this->captureAssets( $settings['providers'] );
                return $template;
            }
            // 公開表單頁不進早期快取，沿用 WordPress 內的本機快取：每位訪客仍取得各自的新表單欄位。
        }

        $localContactForm = false;
        $path = self::cachePath( $settings, $localContactForm );
        if ( $path === null || $this->privateResponse || is_user_logged_in() || is_404() || is_search() ||
            ( is_singular() && post_password_required() ) ) {
            $this->excludeLiteSpeedResponse();
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        $settings['ttl_seconds'] = self::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'], $path );
        if ( $localContactForm ) {
            $settings['ttl_seconds'] = min( $settings['ttl_seconds'], 300 );
            $this->edgeHtml = false;
            $this->excludeLiteSpeedResponse();
            self::sendPrivateNoStore();
        }
        $delegated = self::delegatesHtml( $settings );
        $local = self::usesLocalHtml( $settings );
        $externalLanguageSafe = ( $delegated || $this->edgeHtml || $this->liteSpeedHtml ) && YSCacheIntegrations::allowsExternalHtml();
        $sharedSafe = $this->externalHold && $externalLanguageSafe;
        $fastcgi = $sharedSafe && $delegated && ( self::usesNginx( $settings )
            ? ! empty( $settings['providers']['nginx']['rules_verified'] ) && YSCacheNginx::configured()
            : ! empty( $settings['providers']['enhance']['rules_verified'] ) );
        $this->edgeHtml = $sharedSafe && $this->edgeHtml && ( ! $delegated || $fastcgi );
        $this->liteSpeedCandidate = ! $localContactForm && $this->liteSpeedHtml && $externalLanguageSafe && ! $delegated &&
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
            $localContactForm ? ( ! empty( $settings['public_form_fragments'] ) ? YSCachePublicForms::fingerprint() : 'local-cf7/2:' . WPCF7_VERSION ) : '',
        ] ) );
        $remaining = null;
        $html = YSCacheStorage::read( $directory, $key, $settings['ttl_seconds'], $remaining );
        if ( $html !== null && $localContactForm ) {
            $beforeForm = $this->htmlResponseHeaders();
            try {
                $html = YSCachePublicForms::unpack( $html );
            } catch ( \Throwable $failure ) {
                $html = null;
            }
            if ( $beforeForm !== $this->htmlResponseHeaders() || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
                $html = null;
            }
            if ( $html === null ) {
                // A visitor-specific replay can change the output. Never store that native fallback as a new shared entry.
                $this->excludeLiteSpeedResponse();
                $this->captureAssets( $settings['providers'] );
                return $template;
            }
        }
        if ( $html !== null ) {
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

    /**
     * 這次渲染能否寫入早期快取。請求本身已由 YSCacheEarlyRequest::writerPath() 確認是乾淨請求，
     * 這裡檢查只有 WordPress 才知道的條件：頁面類型、排除規則、購物車、通知、即將推出模式與 nonce 身分。
     */
    private function earlyEligible( array $settings ): bool {
        if ( $this->earlyRequest === null || $this->originCacheControl === null || $this->privateResponse ||
            ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || is_user_logged_in() || is_404() || is_search() ||
            ( is_singular() && post_password_required() ) ) {
            return false;
        }
        $path = $this->earlyRequest['path'];
        $decoded = rawurldecode( $path );
        $excluded = (string) $settings['exclude_paths'];
        $forms = (string) $settings['public_cf7_paths'];
        // 公開表單頁需要每位訪客各自的表單權杖，不放進早期快取。
        if ( self::match( $excluded, $path, 'path' ) || self::match( $excluded, $decoded, 'path' ) ||
            ( $forms !== '' && ( self::match( $forms, $path, 'path' ) || self::match( $forms, $decoded, 'path' ) ) ) ||
            YSCacheIntegrations::bypass() || ! apply_filters( 'ys_cache_allow_request', true, $path ) || self::comingSoon() ||
            self::variesByLocation() ) {
            return false;
        }
        $woocommerce = function_exists( 'WC' ) ? WC() : null;
        if ( is_object( $woocommerce ) ) {
            if ( function_exists( 'wc_notice_count' ) && is_object( $woocommerce->session ) && (int) wc_notice_count() !== 0 ) {
                return false;
            }
            $cart = $woocommerce->cart;
            if ( is_object( $cart ) && ( ! is_callable( [ $cart, 'is_empty' ] ) || ! $cart->is_empty() ) ) {
                return false;
            }
        }
        return YSCacheIntegrations::nonceVariant() !== null && YSCacheIntegrations::languageVariant() !== null &&
            self::blockingCache( $settings ) === '';
    }

    /** 這次請求的路徑是否列在「公開表單路徑」（不含查詢字串，原始與解碼後都比對）。 */
    private static function publicFormPath( array $settings ): bool {
        $forms = (string) ( $settings['public_cf7_paths'] ?? '' );
        if ( $forms === '' ) {
            return false;
        }
        $path = (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
        return self::match( $forms, $path, 'path' ) || self::match( $forms, rawurldecode( $path ), 'path' );
    }

    /**
     * 依訪客所在地改變價格或幣別時，沒有 cookie 的渲染也會因訪客而不同，不能共用：
     * WooCommerce 依地理定位計稅，或啟用了依國家定價／自動切換幣別的外掛。
     */
    private static function variesByLocation(): bool {
        $varies = false;
        if ( function_exists( 'WC' ) ) {
            $varies = get_option( 'woocommerce_calc_taxes' ) === 'yes' &&
                in_array( get_option( 'woocommerce_default_customer_address' ), [ 'geolocation', 'geolocation_ajax' ], true );
            foreach ( [ 'WOOCS', 'WOOMULTI_CURRENCY', 'WOOMULTI_CURRENCY_F', 'WC_Aelia_CurrencySwitcher', 'WCPBC_Pricing_Zones', 'WC_Product_Price_Based_Country' ] as $class ) {
                $varies = $varies || class_exists( $class, false );
            }
            $varies = $varies || ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() );
        }
        return (bool) apply_filters( 'ys_cache_varies_by_location', $varies );
    }

    /** WooCommerce「即將推出」模式會以同一網址輸出不同頁面；作用在這次請求時不寫入。 */
    private static function comingSoon(): bool {
        if ( ! function_exists( 'WC' ) || get_option( 'woocommerce_coming_soon' ) !== 'yes' ) {
            return false;
        }
        if ( get_option( 'woocommerce_store_pages_only' ) !== 'yes' ) {
            return true;
        }
        foreach ( [ 'is_woocommerce', 'is_shop', 'is_product', 'is_product_taxonomy' ] as $storePage ) {
            if ( function_exists( $storePage ) && $storePage() ) {
                return true;
            }
        }
        return false;
    }

    /** 早期快取 MISS：最終輸出時確認是完整、未個人化的公開回應，才寫入共用儲存區。 */
    private function captureEarly( array $settings ): void {
        $baseline = $this->htmlResponseHeaders();
        $complete = true;
        ob_start( function ( string $html, int $phase ) use ( &$complete, $baseline, $settings ): string {
            if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
                $complete = false;
                return $html;
            }
            $translated = ! $this->rewriteAssetsHere();
            if ( ! $translated ) {
                // 公開版本補上輪播首張圖的 srcset（再改寫資源網址），當次回應與快取內容一致。
                $html = YSCacheCarousel::completeHeroSources( $html );
            }
            $responseHtml = $this->rewriteAssets( $html, $settings['providers'] );
            $status = http_response_code();
            $headers = $complete && ! headers_sent() ? $this->earlyHeaders( $baseline ) : null;
            if ( $headers === null || ( $status !== false && $status !== 200 ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
                ! $this->earlyHtmlAllowed( $html ) ) {
                $this->abandonEarly();
                return $responseHtml;
            }
            if ( $translated ) {
                // TranslatePress 非預設語言：等 trp_translated_html 輸出翻譯後的 HTML 再寫入。
                $this->pendingEarly = [ 'settings' => $settings, 'baseline' => $baseline ];
            } else {
                $this->storeEarly( $settings, $responseHtml, $headers );
            }
            return $responseHtml;
        } );
    }

    private function earlyHtmlAllowed( string $html ): bool {
        return self::isCompleteHtml( $html ) && YSCacheIntegrations::cacheableHtml( $html ) &&
            ! self::trackingSuppressed( $html ) && apply_filters( 'ys_cache_allow_html', true, $html );
    }

    /**
     * PixelYourSite 判定為機器人或快取預載的請求可能不輸出追蹤程式碼；
     * 這樣的渲染（沒有 pysOptions）不能給一般訪客共用。
     */
    private static function trackingSuppressed( string $html ): bool {
        if ( ! defined( 'PYS_VERSION' ) || ! function_exists( 'PixelYourSite\\PYS' ) || stripos( $html, 'pysOptions' ) !== false ) {
            return false;
        }
        $plugin = \PixelYourSite\PYS();
        foreach ( [ 'is_user_agent_bot', 'isCachePreload' ] as $method ) {
            if ( is_object( $plugin ) && is_callable( [ $plugin, $method ] ) && $plugin->$method() ) {
                return true;
            }
        }
        return false;
    }

    /** 去掉可略過的 Set-Cookie 後，回應標頭必須與開始擷取時完全相同；其他 Set-Cookie 一律不寫入。 */
    private function earlyHeaders( array $baseline ): ?array {
        $names = self::ignorableResponseCookies();
        $before = YSCacheIntegrations::withoutIgnorableCookies( $baseline, $names );
        $after = YSCacheIntegrations::withoutIgnorableCookies( $this->htmlResponseHeaders(), $names );
        return $before !== null && $before === $after ? $after : null;
    }

    /** 可略過的回應 cookie 名稱；其他外掛可用 ys_cache_ignorable_response_cookies 篩選器加入。 */
    private static function ignorableResponseCookies(): array {
        $names = (array) apply_filters( 'ys_cache_ignorable_response_cookies', YSCacheIntegrations::IGNORABLE_RESPONSE_COOKIES );
        return array_values( array_filter( $names, static fn ( $name ): bool => is_string( $name ) && $name !== '' ) );
    }

    /** 這次回應不會寫入早期快取。 */
    private function abandonEarly(): void {
        if ( ! headers_sent() ) {
            header( 'X-YS-Cache: BYPASS', true );
        }
        $this->releaseEarlyHold();
    }

    /** 不寫入早期快取的回應恢復 no-store，與其他不可共用的回應一致（只替換自己送出的 hold 標頭）。 */
    private function releaseEarlyHold(): void {
        if ( $this->originCacheControl !== null && ! headers_sent() && in_array( $this->originCacheControl, headers_list(), true ) ) {
            self::sendPrivateNoStore();
        }
    }

    /** 寫入早期儲存區。$headers 已由 earlyHeaders() 確認與開始擷取時相同。 */
    private function storeEarly( array $settings, string $html, array $headers ): void {
        if ( headers_sent() || $this->earlyRequest === null || $this->earlySnapshot === null || $this->originCacheControl === null ) {
            return;
        }
        // 只能忽略自己送出的 hold 標頭；外掛另外送出的 private、no-cache 或 no-store 一律不寫入。
        $hold = array_search( $this->originCacheControl, $headers, true );
        if ( $hold !== false ) {
            unset( $headers[ $hold ] );
            $headers = YSCacheIntegrations::withoutIgnorableCookies( array_values( $headers ), self::ignorableResponseCookies() );
        }
        $path = $this->earlyRequest['path'];
        $ttl = YSCacheEarly::ttl( $settings, $path );
        if ( $hold === false || $headers === null || ! self::headersAllowCache( $headers ) || self::headersProhibitSharedCache( $headers ) ||
            $ttl <= 0 || is_user_logged_in() ) {
            $this->abandonEarly();
            return;
        }
        // 渲染期間這個頁面被清除過：不寫入，交給下一次請求重新產生。
        if ( $this->requestGenerationUnchanged() ) {
            YSCacheEarlyStore::write( YSCacheEarly::directory(), YSCacheEarly::host(), $path, $this->earlySnapshot, $headers,
                $html, $ttl, $this->earlyRequest['variant'] );
        }
    }

    private function captureHtml( array $settings, string $nonce, ?string $directory, ?string $key, bool $fastcgi = false, bool $localContactForm = false ): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) {
            return;
        }
        $baseline = $this->htmlResponseHeaders();
        $fragments = $localContactForm;
        if ( $fragments ) {
            YSCachePublicForms::capture();
        }
        $shared = $fastcgi || $this->edgeHtml ? $this->sharedHtmlState( $nonce, $settings['ttl_seconds'], $fastcgi ) : null;
        $complete = true;
        ob_start( function ( string $html, int $phase ) use ( &$complete, $baseline, $directory, $key, $settings, $shared, $localContactForm, $fragments ): string {
            if ( !( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
                $complete = false;
                return $html;
            }
            $responseHtml = $this->rewriteAssets( $html, $settings['providers'] );
            $record = $html;
            if ( $fragments ) {
                try {
                    $record = YSCachePublicForms::pack( $html );
                } catch ( \Throwable $failure ) {
                    $record = null;
                }
            }
            $status = http_response_code();
            if ( ! $complete || headers_sent() || ( $status !== false && $status !== 200 ) ||
                $this->htmlResponseHeaders() !== $baseline || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
                ! self::isCompleteHtml( $html ) ||
                preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ||
                $record === null || ( ! $fragments && ! YSCacheIntegrations::cacheableHtml( $html, $localContactForm ) ) ||
                ! apply_filters( 'ys_cache_allow_html', true, $html ) ) {
                if ( $directory !== null && ! headers_sent() ) {
                    header( 'X-YS-Cache: BYPASS', true );
                }
                return $responseHtml;
            }

            if ( $directory !== null ) {
                YSCacheStorage::write( $directory, $key, $record );
            }
            if ( $shared !== null ) {
                $this->releaseSharedHtml( $html, $shared );
            }
            return $responseHtml;
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
        if ( $this->pendingEarly !== null ) {
            $html = YSCacheCarousel::completeHeroSources( $html );
        }
        $html = YSCacheProviders::rewriteHtml( $html, self::settings()['providers'] );
        if ( str_contains( $html, 'ysslide-swiper' ) ) {
            $html = YSCacheCarousel::rewriteHtml( $html );
        }
        if ( $this->pendingSharedHtml !== null ) {
            $this->releaseSharedHtml( $html, $this->pendingSharedHtml, true );
            $this->pendingSharedHtml = null;
        }
        if ( $this->pendingEarly !== null ) {
            // 寫入翻譯完成的 HTML；標頭與內容檢查都以翻譯後的版本重新確認。
            $pending = $this->pendingEarly;
            $this->pendingEarly = null;
            $headers = headers_sent() ? null : $this->earlyHeaders( $pending['baseline'] );
            if ( $headers !== null && $this->earlyHtmlAllowed( $html ) ) {
                $this->storeEarly( $pending['settings'], $html, $headers );
            } else {
                $this->abandonEarly();
            }
        }
        return $html;
    }
}
