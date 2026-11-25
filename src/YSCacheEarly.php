<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * 管理 wp-content/advanced-cache.php：WordPress 載入前，替所有公開網址送出已快取的完整 HTML。
 *
 * 送出規則與 WP Rocket 相同（cookie 排除清單、忽略追蹤參數、桌機與手機兩種變體）；
 * 寫入規則較嚴格，見 YSCacheEarlyRequest::writerPath() 與 YSCache 的寫入檢查。
 */
final class YSCacheEarly {
    private const MARK = "<?php\n// YS CACHE guest reader v1";
    private const NONCE_MARGIN = 300;
    private static ?string $directory = null;
    private static ?array $config = null;

    public static function observeChanges(): void {
        foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
            add_action( $hook, static function ( string $option ): void {
                if ( preg_match( '/^(?:active_plugins$|template$|stylesheet$|home$|siteurl$|blog_public$|'
                    . 'pys_(?:core|facebook|ga|google_ads|consent|pbid_secret)$|xoo-el-|cpfence|'
                    . 'woocommerce_(?:coming_soon|store_pages_only|cart_page_id|checkout_page_id|myaccount_page_id|default_customer_address|currency)$|'
                    . 'trp_settings$|ys_tp_settings$)/i', $option ) ) {
                    self::invalidate();
                }
            } );
        }
        add_action( 'admin_init', static function (): void {
            // PixelYourSite 12 的設定寫在自己的資料表，從管理請求判斷。
            $action = $_POST['action'] ?? '';
            if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && current_user_can( 'manage_options' )
                && ( isset( $_POST['pys'] ) || ( is_string( $action ) && str_starts_with( $action, 'pys_save_' ) ) ) ) {
                add_action( 'shutdown', static function (): void { self::invalidate(); }, PHP_INT_MAX );
            }
        } );
        add_action( 'upgrader_process_complete', static function (): void { self::invalidate(); }, 10, 0 );
        // 文章上架、下架、改密碼由 YSCacheInvalidation 依範圍清除（含變更前的網址、彙整與分類），這裡不再清全站。
    }

    public static function owned(): bool {
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        return ! is_link( $file ) && is_file( $file )
            && @file_get_contents( $file, false, null, 0, strlen( self::MARK ) ) === self::MARK;
    }

    public static function directory(): string {
        if ( self::$directory === null ) {
            $base = YSCacheStorage::directory();
            self::$directory = $base === '' ? '' : $base . '/early';
        }
        return self::$directory;
    }

    public static function host(): string {
        $parts = wp_parse_url( home_url( '/' ) );
        return ( $parts['scheme'] ?? '' ) === 'https' ? strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
    }

    /** 舊版的路徑清單格式；0.2 起所有公開網址都送早期快取，只在遷移時讀取。 */
    public static function paths( string $input ): array {
        $paths = [];
        foreach ( preg_split( '/\R/', $input, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '#\A/[a-zA-Z0-9_%./~-]*\z#D', $line ) && ! str_contains( $line, '..' )
                && ! str_contains( $line, '//' ) && strlen( $line ) <= 2048 ) {
                $paths[] = $line;
            }
        }
        return array_values( array_unique( $paths ) );
    }

    /** 設定是否要求早期快取（本機 HTML 模式，且沒有交給 CDN 快取 HTML）。 */
    public static function wanted( array $settings ): bool {
        return ! empty( $settings['early'] ) && YSCache::usesLocalHtml( $settings ) && ! YSCache::hasEdgeHtml( $settings )
            && ! self::automaticLanguageRedirect();
    }

    /** 早期讀取器已安裝，且這次請求可以使用寫入端。 */
    public static function active( array $settings ): bool {
        return self::wanted( $settings ) && self::owned() && self::directory() !== '' && self::host() !== '';
    }

    /** TranslatePress 官方自動偵測會依瀏覽器轉址，同一網址對不同訪客意義不同，不能共用。 */
    private static function automaticLanguageRedirect(): bool {
        return class_exists( '\TRP_IN_Automatic_Language_Detection' ) || class_exists( '\TRP_Automatic_Language_Detection' );
    }

    /**
     * 讀取器與寫入端共用的設定。
     *
     * @return array{directory:string,host:string,deny_cookies:array,deny_agents:array,ignore_params:array,languages:array,writer_cookies:array,session_name:string}
     */
    public static function config( array $settings, bool $fresh = false ): array {
        if ( self::$config !== null && ! $fresh ) {
            return self::$config;
        }
        $deny = YSCacheEarlyRequest::DENY_COOKIES;
        if ( empty( $settings['serve_cart_visitors'] ) ) {
            array_push( $deny, ...YSCacheEarlyRequest::CART_COOKIES );
        }
        foreach ( [ 'LOGGED_IN_COOKIE', 'SECURE_AUTH_COOKIE', 'AUTH_COOKIE', 'PASS_COOKIE', 'USER_COOKIE' ] as $constant ) {
            if ( defined( $constant ) && is_string( constant( $constant ) ) && constant( $constant ) !== '' ) {
                $deny[] = constant( $constant );
            }
        }
        foreach ( self::lines( $settings['exclude_cookies'] ?? '' ) as $name ) {
            if ( preg_match( '~\A[\x21-\x7e]{1,128}\z~', $name ) && ! str_contains( $name, '=' ) ) {
                $deny[] = rtrim( $name, '*' );
            }
        }
        $ignore = [];
        foreach ( self::lines( $settings['ignore_params'] ?? '' ) as $name ) {
            if ( preg_match( '~\A[a-zA-Z0-9_.\-\[\]]{1,64}\z~', $name ) ) {
                $ignore[] = strtolower( $name );
            }
        }
        $writer = (array) apply_filters( 'ys_cache_writer_cookie_prefixes', YSCacheEarlyRequest::ANALYTICS_COOKIES );
        $session = function_exists( 'session_name' ) ? (string) session_name() : '';
        $languages = self::suggestionLanguages();
        [ $slugs, $default ] = $languages ? self::languagePaths( $languages ) : [ [], '' ];
        return self::$config = [
            'directory'        => self::directory(),
            'host'             => self::host(),
            'deny_cookies'     => array_values( array_unique( array_filter( $deny, 'is_string' ) ) ),
            'deny_agents'      => self::lines( $settings['exclude_agents'] ?? '' ),
            'ignore_params'    => array_values( array_unique( $ignore ) ),
            'languages'        => $languages,
            'language_slugs'   => $slugs,
            'default_language' => $default,
            'writer_cookies'   => array_values( array_filter( $writer, static fn ( $prefix ): bool => is_string( $prefix ) && $prefix !== '' ) ),
            'session_name'     => $session !== '' ? $session : 'PHPSESSID',
        ];
    }

    /**
     * TranslatePress 的網址語言前綴（小寫 slug => 語言代碼）與預設語言，用來判斷頁面語言。
     *
     * @return array{0:array<string,string>,1:string}
     */
    private static function languagePaths( array $languages ): array {
        $settings = (array) get_option( 'trp_settings', [] );
        $default = is_string( $settings['default-language'] ?? null ) ? $settings['default-language'] : '';
        $prefixDefault = ( $settings['add-subdirectory-to-default-language'] ?? 'no' ) === 'yes';
        $slugs = [];
        foreach ( $languages as $code ) {
            $slug = $settings['url-slugs'][ $code ] ?? '';
            if ( is_string( $slug ) && $slug !== '' && preg_match( '~\A[a-zA-Z0-9_-]{1,32}\z~', $slug ) && ( $code !== $default || $prefixDefault ) ) {
                $slugs[ strtolower( $slug ) ] = $code;
            }
        }
        return [ $slugs, $default ];
    }

    /** YS TranslatePress Addons 依 Accept-Language 顯示語言建議時，以建議語言區分變體。 */
    private static function suggestionLanguages(): array {
        if ( ! class_exists( '\YangSheep\TPAddons\Support\YSTPAddonsModules' ) || ! class_exists( '\TRP_Translate_Press' )
            || ! \YangSheep\TPAddons\Support\YSTPAddonsModules::is_enabled( 'auto_detect' ) ) {
            return [];
        }
        $settings = get_option( 'trp_settings', [] );
        return array_values( array_filter( (array) ( $settings['publish-languages'] ?? [] ),
            static fn ( $code ): bool => is_string( $code ) && preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $code ) === 1 ) );
    }

    /**
     * 這個路徑的項目壽命：套用路徑規則，並確保頁面裡的訪客 nonce 在壽命內一直有效。
     * WordPress 接受前一個 tick 的 nonce，所以只要壽命不超過 nonce_life 的一半就安全。
     */
    public static function ttl( array $settings, string $path ): int {
        $ttl = YSCache::pathLifetime( (int) ( $settings['ttl_seconds'] ?? 0 ), (string) ( $settings['ttl_paths'] ?? '' ), $path );
        $cap = YSCacheEarlyStore::MAX_TTL;
        foreach ( [ -1, 'wp_rest', 'ajax-event-nonce', 'ys_ec_auth', 'ys_ec_cart_nonce' ] as $action ) {
            $life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
            if ( ! is_numeric( $life ) || ! is_finite( (float) $life ) || $life <= 0 ) {
                return 0;
            }
            $cap = min( $cap, (int) floor( $life / 2 ) - self::NONCE_MARGIN );
        }
        return max( 0, min( $ttl, $cap ) );
    }

    public static function sync( array $settings ): bool {
        self::$config = null;
        if ( ! self::wanted( $settings ) ) {
            return self::remove();
        }
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE || is_multisite() || self::host() === '' || YSCache::blockingCache( $settings ) !== '' ) {
            return false;
        }
        $directory = self::directory();
        if ( $directory === '' || ! YSCacheEarlyStore::initialize( $directory ) ) {
            return false;
        }
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( is_link( $file ) || ( file_exists( $file ) && ! self::owned() && filesize( $file ) !== 0 ) ) {
            return false;
        }
        // drop-in 不在時內容與設定都可能改變，重新接上前先換一個世代，舊檔案不會再被送出。
        if ( ! self::owned() && ! YSCacheEarlyStore::invalidateAll( $directory ) ) {
            return false;
        }
        $reader = realpath( YS_CACHE_DIR . 'templates/early-reader.php' );
        if ( ! $reader ) {
            return false;
        }
        // 主機可以不經 WordPress 選項直接替換安全政策檔；命中時比對雜湊，確保重播的安全標頭仍然正確。
        $policy = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cpfence.php';
        $digest = is_file( $policy ) ? hash_file( 'sha256', $policy ) : '';
        if ( $digest === false ) {
            return false;
        }
        $config = self::config( $settings, true );
        $reader_config = [
            'directory'     => $config['directory'],
            'host'          => $config['host'],
            'deny_cookies'  => $config['deny_cookies'],
            'deny_agents'   => $config['deny_agents'],
            'ignore_params' => $config['ignore_params'],
            'languages'     => $config['languages'],
            'language_slugs' => $config['language_slugs'],
            'default_language' => $config['default_language'],
            'policy_file'   => $policy,
            'policy_digest' => $digest,
        ];
        $restoreEmpty = is_file( $file ) && ( filesize( $file ) === 0 || str_contains( (string) file_get_contents( $file ), "// Restore empty drop-in\n" ) );
        $code = self::MARK . "\n// YS CACHE early cache v3\n" . ( $restoreEmpty ? "// Restore empty drop-in\n" : '' )
            . "if ( ! defined( 'ABSPATH' ) || defined( 'MULTISITE' ) && MULTISITE ) return;\n"
            . '$ysReader = ' . var_export( $reader, true ) . ";\n"
            . "if ( ! is_file( \$ysReader ) ) return;\n"
            . '$ysRead = require $ysReader;' . "\n"
            . 'if ( is_callable( $ysRead ) ) $ysRead( ' . var_export( $reader_config, true ) . " );\n";
        YSCacheEarlyStore::purgeLegacy( $directory );
        if ( self::owned() && file_get_contents( $file ) === $code ) {
            return true;
        }
        $tmp = @tempnam( WP_CONTENT_DIR, '.ys-early-' );
        if ( ! $tmp ) {
            return false;
        }
        try {
            if ( realpath( dirname( $tmp ) ) !== realpath( WP_CONTENT_DIR ) || file_put_contents( $tmp, $code ) !== strlen( $code ) ) {
                return false;
            }
            @chmod( $tmp, 0644 );
            if ( is_link( $file ) || ( is_file( $file ) && filesize( $file ) > 0 && ! self::owned() ) ) {
                return false;
            }
            if ( ! @rename( $tmp, $file ) ) {
                return false;
            }
            if ( function_exists( 'opcache_invalidate' ) ) {
                @opcache_invalidate( $file, true );
            }
            return true;
        } finally {
            if ( is_file( $tmp ) ) {
                @unlink( $tmp );
            }
        }
    }

    /** 外掛更新後，舊版 drop-in 仍以舊格式呼叫讀取器；偵測到時重新同步一次。 */
    public static function maybeUpgrade( array $settings ): void {
        if ( ! self::owned() ) {
            return;
        }
        $head = (string) @file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php', false, null, 0, 160 );
        if ( ! str_contains( $head, '// YS CACHE early cache v3' ) ) {
            self::sync( $settings );
        }
    }

    public static function remove(): bool {
        self::$config = null;
        if ( ! self::owned() ) {
            return true;
        }
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        $empty = str_contains( (string) file_get_contents( $file ), "// Restore empty drop-in\n" );
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $file, true );
        }
        return $empty ? file_put_contents( $file, '', LOCK_EX ) === 0 : @unlink( $file );
    }

    public static function invalidate( ?string $path = null ): bool {
        if ( ! self::owned() ) {
            return true;
        }
        // 儲存區不會存放這種路徑（例如含 ; 或 %2f），沒有項目需要清除；不要因此卸下 drop-in。
        if ( $path !== null && ! YSCacheEarlyStore::purgeablePath( $path ) ) {
            return true;
        }
        $directory = self::directory();
        if ( $directory !== '' && ( $path === null ? YSCacheEarlyStore::invalidateAll( $directory ) : YSCacheEarlyStore::invalidatePath( $directory, $path ) ) ) {
            return true;
        }
        return self::remove();
    }

    private static function lines( $value ): array {
        if ( ! is_string( $value ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ),
            static fn ( string $line ): bool => $line !== '' ) );
    }
}
