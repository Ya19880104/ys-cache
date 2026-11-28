<?php
/**
 * php tests/early-writer.php
 *
 * 早期快取寫入端：PixelYourSite 共用防護、noscript eid 移除與 Set-Cookie 規則的純函式檢查，
 * 以及用 PHP 內建伺服器跑完整流程（早期讀取器 MISS → WordPress 渲染並寫入 → 早期讀取器命中）。
 */

namespace PixelYourSite {
    /** 測試用的 PixelYourSite 主程式：只提供寫入端會詢問的機器人與快取預載判斷。 */
    function PYS(): object {
        return $GLOBALS['fixture_pys'];
    }
}

namespace {
    use YangSheep\Cache\YSCacheIntegrations as Integrations;

    const FIXTURE_HOST = 'store.example.test';
    const FIXTURE_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
    const FIXTURE_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1';
    const FIXTURE_EVENT_ID = 'abcdef01-2345-4678-9abc-def012345678';

    /** PixelYourSite Pro 12.6.8 在 AJAX 伺服器事件模式下替訪客輸出的設定（節錄）。 */
    function pixel_options( array $overrides = [] ): array {
        return array_replace_recursive( [
            'staticEvents' => [ 'facebook' => [ 'init_event' => [ [
                'name' => 'PageView', 'eventID' => FIXTURE_EVENT_ID, 'pixelIds' => [ '123456789012345' ],
                'params' => [ 'page_title' => 'Fixture', 'post_type' => 'page', 'plugin' => 'PixelYourSite', 'user_role' => 'guest' ],
            ] ] ] ],
            'dynamicEvents' => [], 'triggerEvents' => [], 'triggerEventTypes' => [],
            'facebook' => [ 'pixelIds' => [ '123456789012345' ], 'advancedMatching' => [], 'advancedMatchingEnabled' => false ],
            'ga' => [ 'trackingIds' => [ 'G-FIXTURE' ] ],
            'ajaxUrl' => 'https://' . FIXTURE_HOST . '/wp-admin/admin-ajax.php',
            'ajax_event' => 'a1b2c3d4e5',
            'ajaxForServerEvent' => '1', 'ajaxForServerStaticEvent' => '1', 'send_external_id' => '1',
            'tracking_analytics' => [ 'TrafficSource' => 'direct', 'userDataEnable' => true,
                'userData' => [ 'emails' => [], 'phones' => [], 'addresses' => [] ] ],
            'user_id' => '0',
        ], $overrides );
    }

    function pixel_markup( array $options, string $eid = '123456789012345' . FIXTURE_EVENT_ID ): string {
        return '<script id="pys-js-extra">' . "\nvar pysOptions = " . json_encode( $options ) . ";\n//# sourceURL=pys-js-extra\n</script>\n"
            . '<script id="pys-js" defer src="https://' . FIXTURE_HOST . '/wp-content/plugins/pixelyoursite-pro/dist/scripts/public.js?ver=12.6.8"></script>'
            // PixelYourSite 每頁都印這段靜態觀察程式，即使沒有 late event。
            . "<script>document.addEventListener('DOMContentLoaded',function(){window.pysLate=document.getElementById('pys_late_event');});</script>"
            . '<noscript><img height="1" width="1" style="display: none;" src="https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1&cd%5Bpage_title%5D=Fixture&eid=' . $eid . '" alt=""></noscript>';
    }

    function fixture_page( string $body ): string {
        return '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8"><title>Fixture</title></head><body>' . $body . '</body></html>';
    }

    if ( PHP_SAPI === 'cli-server' && getenv( 'YS_EARLY_WRITER_ROOT' ) ) {
        $root = (string) getenv( 'YS_EARLY_WRITER_ROOT' );
        define( 'ABSPATH', $root . '/public/' );
        define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
        define( 'YS_CACHE_DIR', dirname( __DIR__ ) . '/' );
        define( 'YS_CACHE_VERSION', 'test' );
        define( 'YS_CACHE_STORAGE_ROOT', $root . '/storage' );
        define( 'WP_CACHE', true );
        define( 'DAY_IN_SECONDS', 86400 );
        define( 'HOUR_IN_SECONDS', 3600 );
        define( 'MINUTE_IN_SECONDS', 60 );
        $_SERVER['DOCUMENT_ROOT'] = ABSPATH;
        // TLS 由前端代理終止。
        $_SERVER['HTTPS'] = 'on';
        $path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

        // wp-settings.php 在 WordPress 載入前執行 advanced-cache.php。
        if ( ! str_starts_with( $path, '/__' ) && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
            include WP_CONTENT_DIR . '/advanced-cache.php';
        }

        function fixture_log( string $line ): void {
            file_put_contents( getenv( 'YS_EARLY_WRITER_ROOT' ) . '/events.log', $line . "\n", FILE_APPEND | LOCK_EX );
        }
        function is_admin() { return false; }
        function is_multisite() { return false; }
        function add_action( ...$args ) { return true; }
        function add_filter( string $hook, $callback, ...$args ) {
            if ( $hook === 'pys_skip_session' ) {
                header( 'X-Fixture-Pys-Skip-Session: ' . ( $callback === '__return_true' ? 'yes' : 'other' ) );
            }
            return true;
        }
        function has_filter( ...$args ) { return false; }
        function apply_filters( string $hook, $value, ...$args ) {
            if ( $hook === 'nonce_life' ) {
                return $GLOBALS['fixture_nonce_life'] ?? $value;
            }
            if ( $hook === 'ys_cache_allow_request' ) {
                return $GLOBALS['fixture_allow_request'] ?? $value;
            }
            if ( $hook === 'ys_cache_ignorable_response_cookies' ) {
                return array_merge( $value, [ 'fixture_consent' ] );
            }
            return $value;
        }
        function do_action( string $hook, ...$args ) {
            if ( $hook === 'ys_cache_purged_page' ) {
                fixture_log( 'purged ' . $args[0] );
            } elseif ( $hook === 'ys_cache_purged_all' ) {
                fixture_log( 'purged all' );
            }
        }
        function is_user_logged_in() { return $GLOBALS['path'] === '/logged-in/'; }
        function is_404() { return $GLOBALS['path'] === '/missing/'; }
        function is_search() { return false; }
        function is_feed() { return false; }
        function is_preview() { return false; }
        function is_trackback() { return false; }
        function is_embed() { return false; }
        function is_customize_preview() { return false; }
        function is_singular() { return false; }
        function is_front_page() { return false; }
        function post_password_required() { return false; }
        function get_option( string $name, $default = false ) { return $GLOBALS['fixture_options'][ $name ] ?? $default; }
        function get_site_option( string $name, $default = false ) { return $default; }
        function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
        function home_url( string $path = '' ) { return 'https://' . FIXTURE_HOST . $path; }
        function wp_normalize_path( string $path ) { return str_replace( '\\', '/', $path ); }
        function wp_salt( string $scheme = 'auth' ) { return 'early-writer-fixture-' . $scheme; }
        function get_current_blog_id() { return 1; }
        function get_queried_object() { return null; }
        function wp_nonce_tick( $action = -1 ) { return 1234; }
        function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
        function wp_json_encode( $value ) { return json_encode( $value ); }
        function wp_check_invalid_utf8( $value ) { return $value; }
        function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
        function sanitize_email( $value ) { return $value; }
        function wp_get_upload_dir() { return [ 'baseurl' => home_url( '/wp-content/uploads' ) ]; }
        function is_ssl() { return true; }
        function determine_locale() { return 'zh_TW'; }
        function __( $text, $domain = '' ) { return $text; }

        $wpdb = new class {
            public string $prefix = 'wp_';
            private function file(): string { return (string) getenv( 'YS_EARLY_WRITER_ROOT' ) . '/db.json'; }
            private function rows(): array {
                $rows = is_file( $this->file() ) ? json_decode( (string) file_get_contents( $this->file() ), true ) : null;
                return is_array( $rows ) ? $rows : [];
            }
            public function prepare( string $query, ...$values ): string { return vsprintf( str_replace( '%s', "'%s'", $query ), $values ); }
            public function get_var( string $query ) {
                return preg_match( "/setting_name = '([^']+)'/", $query, $match ) ? ( $this->rows()[ $match[1] ] ?? null ) : null;
            }
            public function get_results( string $query ): array {
                preg_match_all( "/'(generation|page:[0-9a-f]+)'/", $query, $matches );
                $rows = $this->rows();
                $found = [];
                foreach ( $matches[1] as $name ) {
                    if ( isset( $rows[ $name ] ) ) {
                        $found[] = (object) [ 'setting_name' => $name, 'setting_value' => $rows[ $name ] ];
                    }
                }
                return $found;
            }
            public function replace( string $table, array $row ): int {
                $rows = $this->rows();
                $rows[ $row['setting_name'] ] = $row['setting_value'];
                file_put_contents( $this->file(), json_encode( $rows ), LOCK_EX );
                return 1;
            }
            public function query( string $query ): int { return 0; }
        };

        // 預載、失效收集與 CDN 佇列各有自己的測試；這裡只記錄寫入端如何呼叫它們。
        final class FixturePreload {
            public static function register(): void {}
            // 測試中只有簽章值 fixture 視為有效的預載請求。
            public static function isPreloadRequest(): bool {
                return ( $_SERVER['HTTP_X_YS_CACHE_PRELOAD'] ?? '' ) === 'fixture';
            }
        }
        class_alias( FixturePreload::class, 'YangSheep\Cache\YSCachePreload' );
        final class FixtureInvalidation {
            public function __construct( callable $invalidateAll, ?callable $invalidatePages = null ) {}
            public function register(): void {}
        }
        class_alias( FixtureInvalidation::class, 'YangSheep\Cache\YSCacheInvalidation' );
        final class FixtureEnhancePurge {
            public static function register(): void {}
            public static function onContentChange( array $settings ): void { fixture_log( 'content' ); }
            public static function onStockChange( array $settings, array $urls ): void { fixture_log( 'stock ' . implode( ' ', $urls ) ); }
        }
        class_alias( FixtureEnhancePurge::class, 'YangSheep\Cache\YSCacheEnhancePurge' );
        spl_autoload_register( static function ( string $class ): void {
            $prefix = 'YangSheep\\Cache\\';
            $file = YS_CACHE_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
            if ( str_starts_with( $class, $prefix ) && is_file( $file ) ) {
                require_once $file;
            }
        } );

        $GLOBALS['fixture_options'] = [];
        if ( $path === '/__setup/' ) {
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'synced' => YangSheep\Cache\YSCacheEarly::sync( YangSheep\Cache\YSCache::settings() ) ] );
            return;
        }
        if ( $path === '/__inspect/' ) {
            $entry = YangSheep\Cache\YSCacheEarlyStore::read( YangSheep\Cache\YSCacheEarly::directory(), YangSheep\Cache\YSCacheEarly::host(),
                (string) $_GET['path'], (string) ( $_GET['variant'] ?? 'd' ) );
            header( 'Content-Type: application/json' );
            echo json_encode( $entry === null ? null : [ 'headers' => $entry['headers'], 'html' => gzdecode( $entry['body'] ),
                'ttl' => $entry['expires'] - $entry['created'] ] );
            return;
        }
        if ( $path === '/__stock/' ) {
            YangSheep\Cache\YSCache::instance()->invalidateStockOnChange( [ home_url( '/stock-item/' ) ] );
            echo 'ok';
            return;
        }
        if ( $path === '/__purge-stock/' ) {
            header( 'Content-Type: application/json' );
            echo json_encode( YangSheep\Cache\YSCache::purgeStockPages( [ home_url( '/stock-item/' ) ], [] ) );
            return;
        }
        if ( $path === '/__save/' ) {
            $saved = YangSheep\Cache\YSCache::save( [ 'early' => '1', 'serve_cart_visitors' => '', 'preload' => 0,
                'ignore_params' => "campaign_ref\n<b>partner</b>", 'early_paths' => '/', 'early_pixel_shell' => true, 'guest_template' => true ] );
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'saved' => $saved ] + array_intersect_key( YangSheep\Cache\YSCache::settings( true ), array_flip( [
                'early', 'serve_cart_visitors', 'preload', 'ignore_params', 'early_paths', 'early_pixel_shell', 'guest_template' ] ) ) );
            return;
        }
        if ( $path === '/__save-blocked/' ) {
            // 另一個快取外掛啟用時讀取器無法依新設定接上：卸下舊 drop-in，設定照常儲存。
            $GLOBALS['fixture_options']['active_plugins'] = [ 'wp-rocket/wp-rocket.php' ];
            $saved = YangSheep\Cache\YSCache::save( [ 'ttl_seconds' => 30000 ] );
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'saved' => $saved, 'dropin' => is_file( WP_CONTENT_DIR . '/advanced-cache.php' ),
                'ttl' => YangSheep\Cache\YSCache::settings( true )['ttl_seconds'] ] );
            return;
        }
        if ( $path === '/__delegated-change/' ) {
            // YS CLOUD 管理 HTML 時只能整站清除：內容變更改清全站，純庫存變更照舊排入佇列。
            $settings = YangSheep\Cache\YSCache::settings();
            $settings['providers']['enhance'] = [ 'enabled' => true, 'delegate_html' => true ];
            YangSheep\Cache\Database\YSCacheSettingsRepo::save( $settings );
            YangSheep\Cache\YSCache::instance()->invalidateStockOnChange( [ home_url( '/stock-item/' ) ], true );
            YangSheep\Cache\YSCache::instance()->invalidateStockOnChange( [ home_url( '/stock-item/' ) ], false );
            echo 'ok';
            return;
        }
        if ( $path === '/__speculation-config/' ) {
            header( 'Content-Type: application/json' );
            $cache = YangSheep\Cache\YSCache::instance();
            echo json_encode( [
                'guest' => $cache->speculationConfiguration( [ 'mode' => 'auto', 'eagerness' => 'auto' ] ),
                'disabled' => $cache->speculationConfiguration( null ),
            ] );
            return;
        }
        if ( $path === '/__speculation/' ) {
            function wc_get_page_id( string $page ) { return [ 'shop' => 5, 'cart' => 6, 'checkout' => 7, 'myaccount' => 8 ][ $page ] ?? -1; }
            function get_permalink( int $id ) { return home_url( [ 5 => '/shop/', 6 => '/cart/', 7 => '/checkout/', 8 => '/my-account/' ][ $id ] ); }
            header( 'Content-Type: application/json' );
            echo json_encode( YangSheep\Cache\YSCache::instance()->excludeTrackedNavigations( [ '/wp-admin/*' ] ) );
            return;
        }

        // WordPress 在 shutdown 動作（wp_ob_end_flush_all）清空輸出緩衝，早於 PHP 結束時釋放全域物件。
        register_shutdown_function( static function (): void {
            for ( $levels = ob_get_level(); $levels > 0; $levels-- ) {
                ob_end_flush();
            }
        } );
        if ( str_starts_with( $path, '/woo-' ) || str_starts_with( $path, '/coming-soon' ) ) {
            final class FixtureWooCart {
                public function __construct( private bool $empty ) {}
                public function is_empty(): bool { return $this->empty; }
            }
            final class FixtureWooSession {
                public function has_session(): bool { return false; }
            }
            function WC() {
                static $woocommerce;
                return $woocommerce ??= (object) [ 'cart' => new FixtureWooCart( $GLOBALS['path'] !== '/woo-cart/' ), 'session' => new FixtureWooSession() ];
            }
            function wc_notice_count() { return $GLOBALS['path'] === '/woo-notice/' ? 1 : 0; }
            function is_cart() { return false; }
            function is_checkout() { return false; }
            function is_account_page() { return false; }
            function is_wc_endpoint_url() { return false; }
            function is_woocommerce() { return $GLOBALS['path'] === '/coming-soon-store/'; }
            function is_shop() { return false; }
            function is_product() { return $GLOBALS['path'] === '/coming-soon-store/'; }
            function is_product_taxonomy() { return false; }
            if ( str_starts_with( $path, '/coming-soon' ) ) {
                $GLOBALS['fixture_options']['woocommerce_coming_soon'] = 'yes';
                $GLOBALS['fixture_options']['woocommerce_store_pages_only'] = $path === '/coming-soon/' ? 'no' : 'yes';
            }
        }
        if ( str_starts_with( $path, '/pixel-' ) ) {
            define( 'PYS_VERSION', '12.6.8' );
            $GLOBALS['fixture_pys'] = new class( str_starts_with( $path, '/pixel-bot' ) ) {
                public function __construct( private bool $bot ) {}
                public function is_user_agent_bot(): bool { return $this->bot; }
                public function isCachePreload(): bool { return false; }
            };
        }
        if ( str_starts_with( $path, '/en/' ) ) {
            final class TRP_Translate_Press {}
            $GLOBALS['fixture_options']['trp_settings'] = [ 'default-language' => 'zh_TW', 'publish-languages' => [ 'zh_TW', 'en_US' ] ];
            $TRP_LANGUAGE = 'en_US';
            // TranslatePress 在 init 開始自己的輸出緩衝，翻譯後套用 trp_translated_html。
            ob_start( static function ( string $html, int $phase ): string {
                return $phase & PHP_OUTPUT_HANDLER_FINAL
                    ? YangSheep\Cache\YSCache::instance()->rewriteTranslatedHtml( str_replace( '原始內容', 'Translated content', $html ), 'en_US', 'zh_TW', false )
                    : $html;
            } );
        }

        if ( $path === '/wpforms-page/' ) {
            define( 'WPFORMS_VERSION', '1.10.1.1' );
        }
        if ( str_starts_with( $path, '/ys-cart-session' ) ) {
            // YS CART：唯讀查詢購物車數量，不會建立新的訪客身分。
            final class FixtureYsCartHandler {
                public static function get_instance(): self { return new self(); }
                public function peek_cart_count(): int { return $GLOBALS['path'] === '/ys-cart-session-items/' ? 2 : 0; }
            }
            class_alias( FixtureYsCartHandler::class, 'YangSheep\Ecommerce\Handlers\YSCartHandler' );
        }

        $cache = YangSheep\Cache\YSCache::instance();
        $cache->captureGeneration();
        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'Strict-Transport-Security: max-age=63072000' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        if ( $path === '/missing/' ) {
            http_response_code( 404 );
        }
        if ( $path === '/plugin-private/' ) {
            header( 'Cache-Control: no-cache, must-revalidate' );
        }
        if ( $path === '/recently-viewed/' ) {
            // WooCommerce 在 template_redirect 記錄瀏覽紀錄。
            header( 'Set-Cookie: woocommerce_recently_viewed=12%7C13; path=/; secure; HttpOnly', false );
        }
        if ( $path === '/vary-cookie/' ) {
            header( 'Vary: Accept-Encoding, Cookie' );
        }
        if ( $path === '/redirect-header/' ) {
            header( 'Location: https://' . FIXTURE_HOST . '/clean/' );
            http_response_code( 200 );
        }
        $GLOBALS['fixture_allow_request'] = $path === '/allow-request-false/' ? false : null;
        $GLOBALS['fixture_nonce_life'] = $path === '/nonce-expiring/' ? 600 : null;
        $cache->protectPrivateResponse();
        $cache->serveOrCapture( 'fixture-template.php' );

        // 以下模擬樣板輸出期間。
        if ( $path === '/session-cookie/' ) {
            header( 'Set-Cookie: PHPSESSID=fixture; path=/; HttpOnly', false );
        }
        if ( $path === '/pys-cleanup/' ) {
            header( 'Set-Cookie: select_prod_list=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/', false );
        }
        if ( $path === '/consent-cookie/' ) {
            header( 'Set-Cookie: fixture_consent=1; path=/', false );
        }
        if ( $path === '/reflected-cookie/' ) {
            header( 'Set-Cookie: fixture_visitor=9f8e7d6c5b4a39281706; path=/', false );
        }
        if ( $path === '/state-cookie/' ) {
            header( 'Set-Cookie: woocommerce_items_in_cart=1; path=/', false );
        }
        if ( str_starts_with( $path, '/ys-cart-session' ) ) {
            header( 'Set-Cookie: ys_ec_session=0f0e0d0c0b0a09080706050403020100; path=/; HttpOnly', false );
        }
        if ( str_starts_with( $path, '/woo-minted-' ) ) {
            header( 'Set-Cookie: wp_woocommerce_session_fixture=t_4d5e6f%7C%7C1800000000%7C%7C1799990000%7C%7Cabc; path=/; HttpOnly', false );
        }
        if ( $path === '/late-private/' ) {
            header( 'Cache-Control: private, no-store', true );
        }
        if ( $path === '/purged-during-render/' ) {
            YangSheep\Cache\Database\YSCacheSettingsRepo::invalidatePage( $path );
        }
        if ( in_array( $path, [ '/pixel-ajax/', '/pixel-bot-tracked/' ], true ) ) {
            echo fixture_page( '<p>原始內容</p>' . pixel_markup( pixel_options() ) );
        } elseif ( $path === '/pixel-server/' ) {
            echo fixture_page( '<p>原始內容</p>' . pixel_markup( pixel_options( [ 'ajaxForServerEvent' => '0' ] ) ) );
        } elseif ( $path === '/pixel-bot/' ) {
            echo fixture_page( '<p>PixelYourSite skipped its tracking code for this crawler.</p>' );
        } elseif ( $path === '/reflected-cookie/' ) {
            echo fixture_page( '<input type="hidden" name="visitor" value="9f8e7d6c5b4a39281706">' );
        } elseif ( $path === '/wpforms-page/' ) {
            echo fixture_page( '<form id="wpforms-form-6" class="wpforms-validate wpforms-form" data-formid="6" method="post" data-token="53a4719a6f6b748701a53cebbac09451" data-token-time="1795843668">'
                . '<input type="hidden" name="wpforms[id]" value="6"><input type="hidden" name="url_referer" value=""><button type="submit">送出</button></form>' );
        } elseif ( $path === '/woo-minted-nonce/' ) {
            echo fixture_page( '<form class="woocommerce-form-login"><input type="hidden" name="woocommerce-login-nonce" value="a1b2c3d4e5"></form>' );
        } else {
            echo fixture_page( '<p>原始內容 ' . htmlspecialchars( $path ) . '</p>' );
        }
        return;
    }

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    function expect_same( $expected, $actual, string $message ): void {
        if ( $expected !== $actual ) {
            throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
        }
    }

    define( 'ABSPATH', sys_get_temp_dir() . '/ys-cache-early-writer-unit/' );
    require dirname( __DIR__ ) . '/src/YSCacheIntegrations.php';

    // PixelYourSite：和 WP Rocket 一樣照常共用，不看它的設定；只有真的出現某位訪客的資料時才不寫入。
    $allowed = fixture_page( '<p>Products</p>' . pixel_markup( pixel_options() ) );
    expect_same( false, Integrations::pixelYourSiteVisitorData( $allowed ), 'AJAX-mode guest HTML has no visitor data' );
    expect_same( true, Integrations::cacheableHtml( $allowed ), 'cacheableHtml shares PixelYourSite pages' );
    foreach ( [
        'server-side events' => [ 'ajaxForServerEvent' => '0' ],
        'server-side static events' => [ 'ajaxForServerStaticEvent' => false ],
        'boolean and integer switches' => [ 'ajaxForServerEvent' => true, 'ajaxForServerStaticEvent' => 1 ],
        'integer guest id' => [ 'user_id' => 0 ],
        'a PixelYourSite identifier' => [ 'facebook' => [ 'advancedMatching' => [ 'external_id' => 'pbid-1234' ] ] ],
        'empty matching fields' => [ 'facebook' => [ 'advancedMatching' => [ 'external_id' => '', 'em' => null, 'ph' => [], 'lang' => 'zh_TW' ] ] ],
        'other static events' => [ 'staticEvents' => [ 'facebook' => [ 'woo_view_content' => [ [ 'name' => 'ViewContent' ] ] ] ] ],
        'JSON string containing a statement end' => [ 'staticEvents' => [ 'facebook' => [ 'init_event' => [ [ 'params' => [ 'page_title' => 'Soap};var x=1;' ] ] ] ] ] ],
    ] as $label => $overrides ) {
        $page = fixture_page( pixel_markup( pixel_options( $overrides ) ) );
        expect_same( false, Integrations::pixelYourSiteVisitorData( $page ), 'No visitor data with ' . $label );
        expect_same( true, Integrations::cacheableHtml( $page ), 'Share pages with ' . $label );
    }
    $withoutUser = pixel_options();
    unset( $withoutUser['user_id'], $withoutUser['ajaxForServerStaticEvent'] );
    expect_same( false, Integrations::pixelYourSiteVisitorData( fixture_page( pixel_markup( $withoutUser ) ) ), 'Missing fields are not visitor data' );
    $legacy = '<script type="text/javascript" id="pys-js-extra">' . "\n/* <![CDATA[ */\nvar pysOptions = " . json_encode( pixel_options( [ 'user_id' => '17' ] ) ) . ";\n/* ]]> */\n</script>";
    expect_same( true, Integrations::pixelYourSiteVisitorData( fixture_page( $legacy ) ), 'Parse CDATA-wrapped localization from older themes' );
    foreach ( [
        'pages without PixelYourSite' => fixture_page( '<p>No tracking plugin</p>' ),
        'plain tracking references' => '<p>PixelYourSite and pysOptions documentation</p>',
        'broken JSON' => '<script id="pys-js-extra">var pysOptions = {"user_id":"5",};</script>',
        'an assignment outside the localization script' => "<script>var pysOptions = {'user_id': 5};</script>",
        'a localization script without options' => '<script id="pys-js-extra">window.trackingFixture();</script>',
    ] as $label => $page ) {
        expect_same( false, Integrations::pixelYourSiteVisitorData( $page ), 'Unparseable or absent output is not treated as visitor data: ' . $label );
    }

    // 出現某位訪客的資料時不寫入（乾淨請求不會發生，這是最後一道保險）。
    foreach ( [
        'a customer id' => pixel_options( [ 'user_id' => '17' ] ),
        'an integer customer id' => pixel_options( [ 'user_id' => 17 ] ),
        'a hashed email' => pixel_options( [ 'facebook' => [ 'advancedMatching' => [ 'em' => 'f1e2d3c4' ] ] ] ),
        'a phone list' => pixel_options( [ 'facebook' => [ 'advancedMatching' => [ 'ph' => [ '886912345678' ] ] ] ] ),
        'nested matching data' => pixel_options( [ 'facebook' => [ 'advancedMatching' => [ '123456789012345' => [ 'fn' => 'Ming' ] ] ] ] ),
        'another pixel with matching data' => pixel_options( [ 'pinterest' => [ 'advancedMatching' => [ 'ct' => 'taipei' ] ] ] ),
        'snake-case matching data' => pixel_options( [ 'tiktok' => [ 'advanced_matching' => [ 'country' => 'tw' ] ] ] ),
        'a non-empty matching scalar' => pixel_options( [ 'facebook' => [ 'advancedMatching' => 'em=f1e2' ] ] ),
        'visitor email data' => pixel_options( [ 'tracking_analytics' => [ 'userData' => [ 'emails' => [ 'buyer@example.test' ] ] ] ] ),
        'visitor address data' => pixel_options( [ 'tracking_analytics' => [ 'userData' => [ 'addresses' => [ [ 'city' => 'Taipei' ] ] ] ] ] ),
        'a WooCommerce purchase' => pixel_options( [ 'staticEvents' => [ 'facebook' => [ 'woo_purchase' => [ [ 'name' => 'Purchase' ] ] ] ] ] ),
        'an EDD purchase' => pixel_options( [ 'staticEvents' => [ 'ga' => [ 'edd_purchase' => [ [ 'name' => 'purchase' ] ] ] ] ] ),
    ] as $label => $options ) {
        $page = fixture_page( pixel_markup( $options ) );
        expect_same( true, Integrations::pixelYourSiteVisitorData( $page ), 'Detect ' . $label );
        expect_same( false, Integrations::cacheableHtml( $page ), 'cacheableHtml refuses ' . $label );
    }
    foreach ( [
        'a late event payload' => fixture_page( pixel_markup( pixel_options() ) . '<script id="pys_late_event">window.pysLateEvent = {};</script>' ),
        'a late event without options' => "<script ID='pys_late_event'>window.trackingFixture();</script>",
    ] as $label => $page ) {
        expect_same( true, Integrations::pixelYourSiteVisitorData( $page ), 'Detect ' . $label );
    }

    // Set-Cookie：只允許可略過的名稱與刪除 cookie 的標頭。
    $now = 1800000000;
    $base = [ 'Content-Type: text/html; charset=UTF-8', 'X-Frame-Options: SAMEORIGIN' ];
    $without = static fn ( array $headers, array $names = Integrations::IGNORABLE_RESPONSE_COOKIES ): ?array =>
        Integrations::withoutIgnorableCookies( $headers, $names, $now );
    expect_same( $base, $without( $base ), 'Headers without cookies stay unchanged' );
    foreach ( [
        'Set-Cookie: woocommerce_recently_viewed=12%7C13; path=/; secure; HttpOnly',
        'set-cookie: productlist=12; path=/',
        'Set-Cookie: select_prod_list=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/',
        'Set-Cookie: banner_state=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0; path=/',
        'Set-Cookie: banner_state=1; expires=Thu, 01 Jan 2026 00:00:00 GMT; path=/',
        'Set-Cookie: banner_state=1; Max-Age=0',
        'Set-Cookie: banner_state=1; max-age=-5',
    ] as $cookie ) {
        expect_same( $base, $without( [ $base[0], $cookie, $base[1] ] ), 'Drop ignorable cookie header: ' . $cookie );
    }
    foreach ( [
        'Set-Cookie: PHPSESSID=abc; path=/; HttpOnly',
        'Set-Cookie: wp_woocommerce_session_abc=1; expires=Fri, 01 Jan 2038 00:00:00 GMT; path=/',
        'Set-Cookie: banner_state=1; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=3600',
        'Set-Cookie: banner_state=1; Max-Age=soon',
        'Set-Cookie: banner_state=1; expires=someday',
        'Set-Cookie: =anonymous',
        'Set-Cookie: no-value',
    ] as $cookie ) {
        expect_same( null, $without( [ $base[0], $cookie ] ), 'Refuse cookie header: ' . $cookie );
    }
    expect_same( [], $without( [ 'Set-Cookie: fixture_consent=1; path=/' ], [ 'fixture_consent' ] ), 'Filter-added names are ignorable' );
    expect_same( null, $without( [ 'Set-Cookie: woocommerce_recently_viewed=1' ], [ 'fixture_consent' ] ), 'Only the given names are ignorable' );
    expect_same( null, $without( [ 'Content-Type: text/html', 42 ] ), 'Reject malformed header lists' );

    // 先快取：回應 cookie 一律不存入共用副本，只有代表這次渲染已個人化的 cookie 才讓回應不寫入。
    $state = [ 'wordpress_logged_in_', 'woocommerce_items_in_cart', 'fixture_private_' ];
    $plain = fixture_page( '<p>Shared page</p>' );
    $shareable = static fn ( array $headers, string $html = '' ): ?array =>
        Integrations::shareableResponseHeaders( $headers, $html === '' ? $plain : $html, $state, $now );
    expect_same( $base, $shareable( [ $base[0], 'Set-Cookie: PHPSESSID=abc123; path=/; HttpOnly', $base[1] ] ), 'A new PHP session cookie is left out of the shared copy' );
    expect_same( $base, $shareable( [ $base[0], 'Set-Cookie: ys_ec_session=0f0e0d0c0b0a09080706050403020100; path=/', $base[1] ] ), 'An unrelated session identity is left out' );
    expect_same( $base, $shareable( [ $base[0], 'Set-Cookie: woocommerce_items_in_cart=deleted; Max-Age=0; path=/', $base[1] ] ), 'Deleting a state cookie is harmless' );
    expect_same( null, $shareable( [ 'Set-Cookie: woocommerce_items_in_cart=1; path=/' ] ), 'A cart with items makes the render personal' );
    expect_same( null, $shareable( [ 'Set-Cookie: wordpress_logged_in_abc=user; path=/' ] ), 'A login cookie makes the render personal' );
    expect_same( null, $shareable( [ 'Set-Cookie: fixture_private_flag=1; path=/' ] ), 'Configured cookie exclusions make the render personal' );
    $reflected = fixture_page( '<input type="hidden" name="visitor" value="9f8e7d6c5b4a39281706">' );
    expect_same( null, $shareable( [ 'Set-Cookie: fixture_visitor=9f8e7d6c5b4a39281706; path=/' ], $reflected ), 'A cookie value printed in the page makes it personal' );
    expect_same( null, $shareable( [ 'Set-Cookie: fixture_visitor=9f8e7d6c5b4a%3D39281706; path=/' ], fixture_page( '<p>9f8e7d6c5b4a=39281706</p>' ) ), 'Encoded cookie values are matched after decoding' );
    expect_same( [], $shareable( [ 'Set-Cookie: fixture_flag=yes; path=/' ], fixture_page( '<p>yes</p>' ) ), 'Short values are too common to count as a visitor identifier' );
    expect_same( null, $shareable( [ 'Set-Cookie: no-value' ] ), 'Malformed cookie headers are not shared' );
    expect_same( null, $shareable( [ 'Content-Type: text/html', 42 ] ), 'Malformed header lists are not shared' );

    // 先快取：表單、登入視窗與訪客 nonce 照常快取，只有某位訪客的資料才不共用。
    $wpforms = fixture_page( '<form id="wpforms-form-6" class="wpforms-validate wpforms-form" data-formid="6" method="post" data-token="53a4719a6f6b748701a53cebbac09451" data-token-time="1795843668">'
        . '<input type="hidden" name="wpforms[id]" value="6"><input type="hidden" name="url_referer" value=""></form>' );
    expect_same( true, Integrations::shareableHtml( $wpforms ), 'A WPForms form with its time-based token is shared' );
    expect_same( true, Integrations::shareableHtml( fixture_page( '<form class="frm-fluent-form"><input type="hidden" name="_fluentform_3_fluentformnonce" value="a1b2c3d4e5"></form>' ) ), 'A guest nonce is the same for every visitor' );
    expect_same( true, Integrations::shareableHtml( fixture_page( '<template id="ct-account-modal-template"><form id="loginform"><input type="hidden" name="woocommerce-login-nonce" value="a1b2c3"></form></template>' ) ), 'A login modal with guest nonces is shared' );
    expect_same( false, Integrations::shareableHtml( fixture_page( '<script>var ysEc = {"guestToken":"abc123"};</script>' ) ), 'A YS CART guest token belongs to one visitor' );
    expect_same( false, Integrations::shareableHtml( fixture_page( pixel_markup( pixel_options( [ 'user_id' => '17' ] ) ) ) ), 'PixelYourSite customer data belongs to one visitor' );

    echo "Early writer guard checks passed.\n";

    if ( ! function_exists( 'curl_init' ) || ! function_exists( 'proc_open' ) ) {
        echo "Early writer HTTP checks skipped (curl or proc_open unavailable).\n";
        exit( 0 );
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-early-writer-' . bin2hex( random_bytes( 6 ) );
    mkdir( $root . '/public/wp-content', 0700, true );
    mkdir( $root . '/storage', 0700 );
    file_put_contents( $root . '/db.json', json_encode( [ 'generation' => '1', 'settings' => json_encode( [
        'enabled' => true, 'early' => true, 'auto_detect' => false, 'auto_purge' => true, 'ttl_seconds' => 36000,
        'exclude_paths' => '/private-page/', 'public_cf7_paths' => '/contact/', 'providers' => [], 'assets' => [],
    ] ) ] ) );
    $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
    $address = stream_socket_get_name( $probe, false );
    fclose( $probe );
    $log = $root . '/server.log';
    $server = proc_open( [ PHP_BINARY, '-S', $address, __FILE__ ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $log, 'a' ], 2 => [ 'file', $log, 'a' ] ],
        $pipes, $root . '/public', array_merge( getenv(), [ 'YS_EARLY_WRITER_ROOT' => $root ] ) );

    $fetch = static function ( string $path, array $extra = [], bool $head = false ) use ( $address ): array {
        $curl = curl_init( 'http://' . $address . $path );
        curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => $head,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge( [ 'Host: ' . FIXTURE_HOST, 'User-Agent: ' . FIXTURE_DESKTOP,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ], $extra ),
        ] );
        $response = (string) curl_exec( $curl );
        $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $size = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
        $headers = [];
        foreach ( explode( "\r\n", substr( $response, 0, $size ) ) as $line ) {
            if ( str_contains( $line, ':' ) ) {
                [ $name, $value ] = explode( ':', $line, 2 );
                $headers[ strtolower( trim( $name ) ) ][] = trim( $value );
            }
        }
        return [ 'status' => $status, 'headers' => $headers, 'body' => substr( $response, $size ) ];
    };
    $header = static fn ( array $response, string $name ): string => implode( ', ', $response['headers'][ $name ] ?? [] );
    $stored = static function ( string $path, string $variant = 'd' ) use ( $fetch ): ?array {
        return json_decode( $fetch( '/__inspect/?' . http_build_query( [ 'path' => $path, 'variant' => $variant ] ) )['body'], true );
    };
    $bypassed = static function ( array $response, string $label ) use ( $header ): void {
        expect_same( 'BYPASS', $header( $response, 'x-ys-cache' ), $label . ' is not written' );
        expect_same( true, str_contains( $header( $response, 'cache-control' ), 'no-store' ), $label . ' keeps no-store' );
    };

    try {
        $ready = false;
        for ( $i = 0; $i < 50 && ! $ready; $i++ ) {
            usleep( 100000 );
            $socket = @stream_socket_client( 'tcp://' . $address, $errno, $error, 0.2 );
            if ( $socket ) {
                fclose( $socket );
                $ready = true;
            }
        }
        expect_same( true, $ready, 'Start the PHP built-in server' );
        expect_same( [ 'synced' => true ], json_decode( $fetch( '/__setup/' )['body'], true ), 'Attach the early reader' );
        // 清除（含接上讀取器時的新世代）之後，同一秒內開始的請求可能讀到清除前的設定，寫入端會拒絕；等到下一秒再測寫入。
        $nextSecond = static function (): void {
            $now = microtime( true );
            usleep( (int) ( ( floor( $now ) + 1 - $now ) * 1000000 ) + 20000 );
        };
        $nextSecond();

        // 乾淨請求：MISS 時寫入，下一次由早期讀取器直接送出。
        $miss = $fetch( '/clean/' );
        expect_same( 200, $miss['status'], 'Render the first request in WordPress' );
        expect_same( 'MISS', $header( $miss, 'x-ys-cache' ), 'A clean request is an early cache candidate' );
        expect_same( 'miss', $header( $miss, 'x-ys-cache-early' ), 'The reader reported the missing entry' );
        expect_same( 1, preg_match( '~\Aprivate, no-cache, must-revalidate, ys-cache-hold="[0-9a-f]{16}"\z~', $header( $miss, 'cache-control' ) ), 'The candidate keeps bfcache with the hold header' );
        expect_same( 'yes', $header( $miss, 'x-fixture-pys-skip-session' ), 'PixelYourSite does not start a session for a shared render' );
        $entry = $stored( '/clean/' );
        expect_same( true, is_array( $entry ) && $entry['ttl'] === 36000, 'Store the page with the configured lifetime' );
        expect_same( [ 'Content-Type: text/html; charset=UTF-8', 'Strict-Transport-Security: max-age=63072000', 'X-Frame-Options: SAMEORIGIN' ], $entry['headers'], 'Store only the replayable public headers' );
        $hit = $fetch( '/clean/' );
        expect_same( 'HIT', $header( $hit, 'x-ys-cache' ), 'Serve the second request before WordPress' );
        expect_same( 'early', $header( $hit, 'x-ys-cache-engine' ), 'The early reader served the hit' );
        expect_same( 'private, no-cache', $header( $hit, 'cache-control' ), 'Hits revalidate without no-store' );
        expect_same( 'max-age=63072000', $header( $hit, 'strict-transport-security' ), 'Replay HSTS' );
        expect_same( 'SAMEORIGIN', $header( $hit, 'x-frame-options' ), 'Replay frame options' );
        expect_same( $miss['body'], $hit['body'], 'The hit body equals the rendered page' );
        expect_same( 304, $fetch( '/clean/', [ 'If-None-Match: ' . $header( $hit, 'etag' ) ] )['status'], 'Conditional reloads are answered early' );
        expect_same( 'HIT', $header( $fetch( '/clean/?utm_source=newsletter&fbclid=abc' ), 'x-ys-cache' ), 'Tracking parameters share the clean entry' );

        // 手機與桌機分開存放。
        expect_same( 'MISS', $header( $fetch( '/clean/', [ 'User-Agent: ' . FIXTURE_PHONE ] ), 'x-ys-cache' ), 'The phone variant renders separately' );
        expect_same( true, is_array( $stored( '/clean/', 'm' ) ), 'Store the phone variant' );
        expect_same( 'HIT', $header( $fetch( '/clean/', [ 'User-Agent: ' . FIXTURE_PHONE ] ), 'x-ys-cache' ), 'Serve the phone variant early' );

        // 百分比編碼大小寫不同仍是同一頁：Google、通訊軟體與 WordPress 的 HTTP 函式庫常送大寫，WordPress 的連結是小寫。
        $upperEncoded = '/%E6%89%8B%E5%B7%A5%E7%9A%82/';
        $lowerEncoded = '/%e6%89%8b%e5%b7%a5%e7%9a%82/';
        expect_same( 'MISS', $header( $fetch( $upperEncoded ), 'x-ys-cache' ), 'An uppercase-encoded request renders as a candidate' );
        expect_same( true, is_array( $stored( $lowerEncoded ) ), 'Store it under the lowercase permalink path' );
        expect_same( 'HIT', $header( $fetch( $lowerEncoded ), 'x-ys-cache' ), 'A visitor following the WordPress link hits the same entry' );
        expect_same( 'HIT', $header( $fetch( $upperEncoded ), 'x-ys-cache' ), 'The uppercase address hits the same entry' );

        // 不乾淨的請求只讀不寫。
        $session = $fetch( '/session-visitor/', [ 'Cookie: PHPSESSID=abc; _ga=GA1.1.1' ] );
        $bypassed( $session, 'A PHP session request' );
        expect_same( '', $header( $session, 'x-fixture-pys-skip-session' ), 'Only shared renders skip the PixelYourSite session' );
        expect_same( null, $stored( '/session-visitor/' ), 'Nothing was stored from the session request' );
        expect_same( 'MISS', $header( $fetch( '/session-visitor/', [ 'Cookie: _ga=GA1.1.1; _fbp=fb.1.2' ] ), 'x-ys-cache' ), 'Analytics cookies may write' );
        expect_same( 'HIT', $header( $fetch( '/session-visitor/', [ 'Cookie: PHPSESSID=abc' ] ), 'x-ys-cache' ), 'A session cookie still receives the shared page' );
        $query = $fetch( '/tracked-query/?orderby=price' );
        expect_same( 'query', $header( $query, 'x-ys-cache-early' ), 'Functional queries bypass the reader' );
        $bypassed( $query, 'A functional query' );
        expect_same( null, $stored( '/tracked-query/' ), 'A query render is never stored for the clean URL' );
        $loggedIn = $fetch( '/logged-in/' );
        $bypassed( $loggedIn, 'A logged-in render' );
        expect_same( null, $stored( '/logged-in/' ), 'Logged-in renders are not stored' );
        $headOnly = $fetch( '/head-only/', [], true );
        expect_same( 'BYPASS', $header( $headOnly, 'x-ys-cache' ), 'HEAD renders are not stored' );
        expect_same( null, $stored( '/head-only/' ), 'HEAD stores nothing' );

        // 回應 cookie。
        $viewed = $fetch( '/recently-viewed/' );
        expect_same( 'MISS', $header( $viewed, 'x-ys-cache' ), 'Recently viewed history is ignorable' );
        expect_same( true, str_starts_with( $header( $viewed, 'set-cookie' ), 'woocommerce_recently_viewed=' ), 'The visitor still receives the history cookie' );
        $viewedHit = $fetch( '/recently-viewed/' );
        expect_same( 'HIT', $header( $viewedHit, 'x-ys-cache' ), 'Serve the page early' );
        expect_same( '', $header( $viewedHit, 'set-cookie' ), 'The shared entry carries no cookie' );
        foreach ( [ '/pys-cleanup/' => 'A PixelYourSite list cookie deletion', '/consent-cookie/' => 'A filter-approved cookie' ] as $path => $label ) {
            expect_same( 'MISS', $header( $fetch( $path ), 'x-ys-cache' ), $label . ' renders as a candidate' );
            expect_same( true, is_array( $stored( $path ) ), $label . ' does not block the write' );
        }
        // 先快取：渲染時才開的工作階段不影響頁面，cookie 只給觸發渲染的訪客，不存入共用副本。
        $sessionCookie = $fetch( '/session-cookie/' );
        expect_same( 'MISS', $header( $sessionCookie, 'x-ys-cache' ), 'A render that starts a PHP session is still a candidate' );
        expect_same( true, str_starts_with( $header( $sessionCookie, 'set-cookie' ), 'PHPSESSID=' ), 'The rendering visitor still receives the session cookie' );
        expect_same( true, is_array( $stored( '/session-cookie/' ) ), 'A new session cookie no longer blocks the write' );
        $sessionHit = $fetch( '/session-cookie/' );
        expect_same( 'HIT', $header( $sessionHit, 'x-ys-cache' ), 'Later visitors get the shared page' );
        expect_same( '', $header( $sessionHit, 'set-cookie' ), 'The shared copy never hands out the session cookie' );
        foreach ( [
            '/wpforms-page/' => 'A page with a WPForms form',
            '/ys-cart-session/' => 'A YS CART session minted with an empty cart',
            '/woo-minted-session/' => 'A WooCommerce session minted with an empty cart',
        ] as $path => $label ) {
            expect_same( 'MISS', $header( $fetch( $path ), 'x-ys-cache' ), $label . ' renders as a candidate' );
            expect_same( true, is_array( $stored( $path ) ), $label . ' is cached' );
            expect_same( 'HIT', $header( $fetch( $path ), 'x-ys-cache' ), $label . ' is served early' );
        }
        foreach ( [
            '/reflected-cookie/' => 'A page that prints the new cookie value',
            '/state-cookie/' => 'A render that fills the cart',
            '/ys-cart-session-items/' => 'A YS CART session that already has items',
            '/woo-minted-nonce/' => 'A WooCommerce nonce bound to the new session',
        ] as $path => $label ) {
            $bypassed( $fetch( $path ), $label );
            expect_same( null, $stored( $path ), $label . ' is not stored' );
        }

        // 外掛自行宣告不可快取、頁面類型與排除規則。
        $latePrivate = $fetch( '/late-private/' );
        expect_same( 'BYPASS', $header( $latePrivate, 'x-ys-cache' ), 'A late private header blocks the write' );
        expect_same( 'private, no-store', $header( $latePrivate, 'cache-control' ), 'The plugin header is preserved' );
        expect_same( null, $stored( '/late-private/' ), 'Late private pages are not stored' );
        foreach ( [
            '/plugin-private/' => 'A response the plugin marked private',
            '/missing/' => 'A 404 page',
            '/private-page/' => 'An excluded path',
            '/allow-request-false/' => 'A request refused by ys_cache_allow_request',
            '/woo-cart/' => 'A WooCommerce cart with items',
            '/woo-notice/' => 'A pending WooCommerce notice',
            '/coming-soon/' => 'A site in coming-soon mode',
            '/coming-soon-store/' => 'A store page in store-only coming-soon mode',
            '/pixel-bot/' => 'A crawler render without tracking code',
        ] as $path => $label ) {
            $response = $fetch( $path );
            $bypassed( $response, $label );
            expect_same( null, $stored( $path ), $label . ' is not stored' );
        }
        expect_same( 404, $fetch( '/missing/' )['status'], 'The 404 status is preserved' );
        foreach ( [ '/woo-clean/' => 'An empty WooCommerce cart', '/coming-soon-blog/' => 'A non-store page in store-only coming-soon mode',
            '/pixel-bot-tracked/' => 'A crawler render that kept the tracking code' ] as $path => $label ) {
            expect_same( 'MISS', $header( $fetch( $path ), 'x-ys-cache' ), $label . ' is a candidate' );
            expect_same( true, is_array( $stored( $path ) ), $label . ' is stored' );
        }
        $bypassed( $fetch( '/nonce-expiring/' ), 'A page whose nonces would expire within the lifetime' );
        expect_same( null, $stored( '/nonce-expiring/' ), 'Never store a page whose nonces would expire first' );
        foreach ( [ '/vary-cookie/' => 'A page that varies by cookie', '/redirect-header/' => 'A response with a Location header' ] as $path => $label ) {
            $bypassed( $fetch( $path ), $label );
            expect_same( null, $stored( $path ), $label . ' is not stored' );
        }
        expect_same( 'MISS', $header( $fetch( '/purged-during-render/' ), 'x-ys-cache' ), 'A purge during the render' );
        expect_same( null, $stored( '/purged-during-render/' ), 'A render that started before a purge is discarded' );

        // PixelYourSite：共用版本移除 noscript eid，當次回應保留。
        $pixelMiss = $fetch( '/pixel-ajax/' );
        expect_same( 'MISS', $header( $pixelMiss, 'x-ys-cache' ), 'AJAX-mode PixelYourSite pages are candidates' );
        expect_same( true, str_contains( $pixelMiss['body'], '&eid=123456789012345' ), 'The visitor who triggered the render keeps the event id' );
        $pixelHit = $fetch( '/pixel-ajax/' );
        expect_same( 'HIT', $header( $pixelHit, 'x-ys-cache' ), 'Serve the PixelYourSite page early' );
        expect_same( true, str_contains( $pixelHit['body'], '&eid=123456789012345' ), 'The shared copy keeps the PixelYourSite output unchanged' );
        expect_same( true, str_contains( $pixelHit['body'], 'var pysOptions' ), 'The shared copy keeps the browser event configuration' );

        // PixelYourSite 不論設定都照常快取；公開表單頁不進早期快取，交給 WordPress 內的本機快取。
        expect_same( 'MISS', $header( $fetch( '/pixel-server/' ), 'x-ys-cache' ), 'Render-time server-event pages are candidates' );
        expect_same( true, is_array( $stored( '/pixel-server/' ) ), 'PixelYourSite settings do not block caching' );
        $contact = $fetch( '/contact/' );
        expect_same( 200, $contact['status'], 'The public form page renders' );
        expect_same( null, $stored( '/contact/' ), 'A public form path is never written to the early store' );

        // TranslatePress 非預設語言寫入翻譯後的 HTML。
        $translated = $fetch( '/en/translated/' );
        expect_same( 'MISS', $header( $translated, 'x-ys-cache' ), 'Translated pages are candidates' );
        expect_same( true, str_contains( $translated['body'], 'Translated content' ), 'The visitor receives the translation' );
        $translatedHit = $fetch( '/en/translated/' );
        expect_same( 'HIT', $header( $translatedHit, 'x-ys-cache' ), 'Serve the translated page early' );
        expect_same( true, str_contains( $translatedHit['body'], 'Translated content' ) && ! str_contains( $translatedHit['body'], '原始內容' ), 'Store the translated HTML, not the source language' );

        // 預載請求一律交給 WordPress 重新渲染並寫入。
        $preload = $fetch( '/preloaded/', [ 'X-YS-Cache-Preload: fixture' ] );
        expect_same( '', $header( $preload, 'x-ys-cache-early' ), 'The reader leaves preload requests to WordPress' );
        expect_same( 'MISS', $header( $preload, 'x-ys-cache' ), 'A preload render is a candidate' );
        expect_same( 'HIT', $header( $fetch( '/preloaded/' ), 'x-ys-cache' ), 'Visitors receive the preloaded page' );
        $forged = $fetch( '/forged-preload/', [ 'X-YS-Cache-Preload: forged' ] );
        expect_same( '', $header( $forged, 'x-ys-cache-early' ), 'A forged preload header still skips the reader' );
        expect_same( null, $stored( '/forged-preload/' ), 'A forged preload header cannot write or overwrite shared entries' );
        $upper = $fetch( '/Clean-Upper/' );
        expect_same( 200, $upper['status'], 'Mixed-case aliases still render' );
        expect_same( null, $stored( '/Clean-Upper/' ), 'Mixed-case aliases are not stored as separate copies' );

        // 範圍清除：本機頁面同步清除並通知預載，CDN 照舊排入佇列。
        $fetch( '/stock-item/' );
        expect_same( 'HIT', $header( $fetch( '/stock-item/' ), 'x-ys-cache' ), 'Warm the stock fixture' );
        @unlink( $root . '/events.log' );
        expect_same( 'ok', $fetch( '/__stock/' )['body'], 'Run a scoped purge' );
        expect_same( "purged /stock-item/\nstock https://" . FIXTURE_HOST . "/stock-item/\n", (string) @file_get_contents( $root . '/events.log' ), 'Clear the page now, notify the preloader, then queue CDN work' );
        expect_same( null, $stored( '/stock-item/' ), 'The scoped purge removed the early entry immediately' );
        $nextSecond();
        expect_same( 'MISS', $header( $fetch( '/stock-item/' ), 'x-ys-cache' ), 'The next visitor renders fresh content' );
        @unlink( $root . '/events.log' );
        $worker = json_decode( $fetch( '/__purge-stock/' )['body'], true );
        expect_same( 'ok', $worker[0]['status'] ?? null, 'The background stock job reports success' );
        expect_same( 'HIT', $header( $fetch( '/stock-item/' ), 'x-ys-cache' ), 'The background job keeps the refilled early entry' );
        expect_same( '', (string) @file_get_contents( $root . '/events.log' ), 'The background job does not purge the page twice' );

        // 推測式預取只排除購物車、結帳與帳戶頁。
        expect_same( [ '/wp-admin/*', '/cart', '/cart/*', '/checkout', '/checkout/*', '/my-account', '/my-account/*' ],
            json_decode( $fetch( '/__speculation/' )['body'], true ), 'Catalog pages stay prefetchable' );
        expect_same( [ 'guest' => [ 'mode' => 'prefetch', 'eagerness' => 'moderate' ], 'disabled' => null ],
            json_decode( $fetch( '/__speculation-config/' )['body'], true ), 'Prefetch on hover while the early cache runs; keep WordPress disabling it for logged-in users' );

        // 設定：新欄位、停用的舊欄位，以及儲存後同步讀取器設定。
        expect_same( [ 'saved' => true, 'early' => true, 'serve_cart_visitors' => false, 'preload' => false, 'ignore_params' => "campaign_ref\npartner",
            'early_paths' => '', 'early_pixel_shell' => false, 'guest_template' => false ], json_decode( $fetch( '/__save/' )['body'], true ), 'Sanitize and save the 0.2 settings' );
        $nextSecond();
        expect_same( 'MISS', $header( $fetch( '/clean/' ), 'x-ys-cache' ), 'Saving settings clears the early cache' );
        expect_same( 'HIT', $header( $fetch( '/clean/?campaign_ref=spring' ), 'x-ys-cache' ), 'The reader honors the newly ignored parameter' );

        // 讀取器無法重新接上時，設定仍可儲存，舊的 drop-in 不會繼續以舊規則送出頁面。
        expect_same( [ 'saved' => true, 'dropin' => false, 'ttl' => 30000 ], json_decode( $fetch( '/__save-blocked/' )['body'], true ),
            'Save settings and detach the stale reader when it cannot be re-attached' );
        $detached = $fetch( '/clean/' );
        expect_same( '', $header( $detached, 'x-ys-cache-early' ) . $header( $detached, 'x-ys-cache-engine' ), 'No early reader runs after detaching' );
        expect_same( 'MISS', $header( $detached, 'x-ys-cache' ), 'The site falls back to the in-WordPress local cache' );

        // 最後切換成 YS CLOUD 管理 HTML（之後的請求都會是這個設定）。
        @unlink( $root . '/events.log' );
        expect_same( 'ok', $fetch( '/__delegated-change/' )['body'], 'Run scoped purges with YS CLOUD managing HTML' );
        expect_same( "purged all\ncontent\nstock https://" . FIXTURE_HOST . "/stock-item/\n", (string) @file_get_contents( $root . '/events.log' ),
            'A content change purges the whole site when only site-wide clearing exists; a stock change stays scoped' );

        echo "Early writer HTTP checks passed.\n";
    } catch ( Throwable $error ) {
        fwrite( STDERR, $error->getMessage() . "\n" . (string) @file_get_contents( $log ) );
        $failure = true;
    } finally {
        if ( is_resource( $server ) ) {
            fclose( $pipes[0] );
            proc_terminate( $server );
            proc_close( $server );
        }
        $entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $entries as $entry ) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
        }
        @rmdir( $root );
    }
    if ( ! empty( $failure ) ) {
        exit( 1 );
    }
}
