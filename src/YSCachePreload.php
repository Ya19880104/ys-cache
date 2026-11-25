<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * 早期快取預載：每分鐘的 WP-Cron 依序以桌機與手機身分渲染公開網址，訪客第一次造訪就能早期命中。
 *
 * - 佇列存在早期儲存區目錄（網站目錄外）的 preload.json（0600），讀改寫以 preload.lock 保護；
 *   執行期間另以 preload-run.lock（非阻塞）防止兩個 cron 同時預載。
 * - 預載請求不帶 cookie，以 HMAC 簽章的 X-YS-Cache-Preload 標頭證明身分；早期讀取器一律把這類請求交給 WordPress。
 * - 全站清除後排入全部網址，單頁清除後只排入該路徑（優先處理）；每小時補上快過期的頁面並清理過期項目。
 */
final class YSCachePreload {
    public const HOOK = 'ys_cache_preload';
    public const SCHEDULE = 'ys_cache_minute';
    public const HEADER = 'X-YS-Cache-Preload';
    /** 與 wp_is_mobile() 判斷一致：桌機 UA 不含行動裝置關鍵字，手機 UA 含 Mobile。 */
    public const DESKTOP_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 YSCachePreload/1';
    public const PHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1 YSCachePreload/1';
    public const MAX_PENDING = 4000;
    public const MAX_PATHS = 2000;
    private const DEVICES = [ 'd', 'm' ];
    private const RECENT_POSTS = 50;
    private const BUDGET_SECONDS = 40.0;
    private const TIMEOUT = 20;
    private const SLOW_SECONDS = 10.0;
    /** 剩餘壽命低於 90 分鐘就重新產生；大於每小時補件的間隔，項目到期前一定會被換新，不會出現空窗。 */
    private const FRESH_SECONDS = 5400;
    private const REFRESH_SECONDS = 3600;
    /** 兩個裝置都無法寫入（排除、不可快取、轉址）的路徑，這段時間內不再由整批預載排入。 */
    private const COOLDOWN_SECONDS = 21600;
    private const TOKEN_WINDOW = 300;
    private const MAX_ATTEMPTS = 2;
    private const MAX_PATH_LENGTH = 512;
    private const MAX_STATE_BYTES = 4194304;
    private const MAX_ERROR_LENGTH = 300;
    private const STATE = '/preload.json';
    private const LOCK = '/preload.lock';
    private const RUN_LOCK = '/preload-run.lock';

    private static ?bool $preloadRequest = null;
    private static bool $sessionSkipped = false;
    /** 這次請求排入、shutdown 才寫入的路徑。 */
    private static array $queued = [];
    private static bool $queuedAll = false;
    private static bool $persistScheduled = false;

    public static function register(): void {
        add_filter( 'cron_schedules', [ self::class, 'cronSchedules' ] );
        add_action( self::HOOK, [ self::class, 'run' ], 10, 0 );
        add_action( 'init', [ self::class, 'maintainSchedule' ], 20, 0 );
        add_action( 'ys_cache_purged_all', [ self::class, 'enqueueAll' ], 10, 0 );
        add_action( 'ys_cache_purged_page', [ self::class, 'purgedPage' ], 10, 1 );
        // 預載請求不能建立 PixelYourSite session（命中時也不會執行 PHP）；可插拔函式尚未載入時延到 plugins_loaded。
        add_action( 'plugins_loaded', [ self::class, 'skipTrackingSession' ], 0, 0 );
        self::skipTrackingSession();
    }

    public static function skipTrackingSession(): void {
        if ( ! self::$sessionSkipped && self::isPreloadRequest() ) {
            self::$sessionSkipped = true;
            add_filter( 'pys_skip_session', '__return_true', PHP_INT_MAX );
        }
    }

    public static function cronSchedules( $schedules ): array {
        $schedules = is_array( $schedules ) ? $schedules : [];
        $schedules[ self::SCHEDULE ] ??= [ 'interval' => 60, 'display' => 'YS CACHE' ];
        return $schedules;
    }

    /** 依設定排程或取消（只在狀態需要改變時才檢查 drop-in）。 */
    public static function maintainSchedule(): void {
        $settings = YSCache::settings();
        $next = wp_next_scheduled( self::HOOK );
        if ( empty( $settings['preload'] ) || ! YSCacheEarly::wanted( $settings ) ) {
            if ( $next ) {
                wp_clear_scheduled_hook( self::HOOK );
            }
            return;
        }
        if ( ! $next && YSCacheEarly::active( $settings ) ) {
            wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
        }
    }

    public static function cancel(): void {
        self::$queued = [];
        self::$queuedAll = false;
        wp_clear_scheduled_hook( self::HOOK );
    }

    /** 排入全部公開網址（下一次 cron 才展開清單，只有過期的變體會真的渲染）。 */
    public static function enqueueAll(): void {
        self::$queuedAll = true;
        self::schedulePersist();
    }

    /** 排入指定的網站路徑（例如 /product/soap/），優先於整批預載處理。 */
    public static function enqueuePaths( array $paths ): void {
        foreach ( $paths as $path ) {
            if ( is_string( $path ) && self::validPath( $path ) ) {
                self::$queued[ $path ] = true;
            }
        }
        if ( self::$queued ) {
            self::schedulePersist();
        }
    }

    public static function purgedPage( $path ): void {
        if ( is_string( $path ) ) {
            self::enqueuePaths( [ $path ] );
        }
    }

    /**
     * 失效通常在 YSCacheInvalidation 的 shutdown（PHP_INT_MAX）才送出，之後才加的同優先序 shutdown 不會執行，
     * 所以改用 PHP 的 shutdown 函式，在 WordPress 的 shutdown 全部跑完後才寫入一次。
     */
    private static function schedulePersist(): void {
        if ( ! self::$persistScheduled ) {
            self::$persistScheduled = true;
            register_shutdown_function( [ self::class, 'persist' ] );
        }
    }

    /** 把這次請求排入的路徑寫入佇列（shutdown 呼叫；測試可直接呼叫）。 */
    public static function persist(): void {
        $paths = array_map( 'strval', array_keys( self::$queued ) );
        $full = self::$queuedAll;
        self::$queued = [];
        self::$queuedAll = false;
        // 之後的 shutdown 函式若又排入路徑，會再登記一次。
        self::$persistScheduled = false;
        if ( $paths === [] && ! $full ) {
            return;
        }
        $settings = YSCache::settings();
        $directory = YSCacheEarly::directory();
        if ( empty( $settings['preload'] ) || ! YSCacheEarly::wanted( $settings ) || ! self::directoryReady( $directory ) ) {
            return;
        }
        self::writeQueue( $directory, $paths, $full );
    }

    /**
     * 佇列概況。pending 為待處理路徑數；full 表示已要求整批預載、下一次執行才展開。
     *
     * @return array{pending:int,last_run:?int,last_rendered:int,last_error:string,full:bool,last_full:?int,total:int}
     */
    public static function status(): array {
        $state = self::readState( YSCacheEarly::directory() );
        foreach ( array_keys( self::$queued ) as $path ) {
            $state['pending'][ (string) $path ] ??= 0;
        }
        $state['full'] = $state['full'] || self::$queuedAll;
        return self::summary( $state );
    }

    public static function summary( array $state ): array {
        return [
            'pending'       => count( $state['pending'] ),
            'last_run'      => $state['last_run'] > 0 ? $state['last_run'] : null,
            'last_rendered' => $state['last_rendered'],
            'last_error'    => $state['last_error'],
            'full'          => $state['full'],
            'last_full'     => $state['last_full'] > 0 ? $state['last_full'] : null,
            'total'         => $state['total'],
        ];
    }

    /** 這次請求是否為帶有效簽章的預載（每個請求只判斷一次）。 */
    public static function isPreloadRequest(): bool {
        if ( self::$preloadRequest !== null ) {
            return self::$preloadRequest;
        }
        if ( ! isset( $_SERVER[ YSCacheEarlyRequest::PRELOAD_HEADER ] ) ) {
            return self::$preloadRequest = false;
        }
        if ( ! function_exists( 'wp_salt' ) ) {
            return false;
        }
        return self::$preloadRequest = self::verify( $_SERVER, wp_salt( 'auth' ), time() );
    }

    /** 簽章：<unix>.<HMAC-SHA256("ys-cache-preload|<unix>|<path>")>。 */
    public static function sign( string $path, int $time, string $key ): string {
        return $time . '.' . hash_hmac( 'sha256', 'ys-cache-preload|' . $time . '|' . $path, $key );
    }

    /** 驗證預載標頭：300 秒內、GET 或 HEAD、無查詢字串，且簽章對應這次的請求路徑。 */
    public static function verify( array $server, string $key, int $now ): bool {
        $token = $server[ YSCacheEarlyRequest::PRELOAD_HEADER ] ?? null;
        $path = (string) ( $server['REQUEST_URI'] ?? '' );
        if ( ! is_string( $token ) || $key === '' || ! preg_match( '~\A([0-9]{1,12})\.([a-f0-9]{64})\z~D', $token, $parts ) ||
            abs( $now - (int) $parts[1] ) > self::TOKEN_WINDOW ||
            ! in_array( $server['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) || ! self::validPath( $path ) ) {
            return false;
        }
        return hash_equals( self::sign( $path, (int) $parts[1], $key ), $token );
    }

    /** 預載請求參數（不帶 cookie、不跟隨轉址）。 */
    public static function requestArgs( string $device, string $token, string $locale ): array {
        $server = self::server( $device, $locale );
        return [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
            'cookies'     => [],
            'user-agent'  => $server['HTTP_USER_AGENT'],
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => $server['HTTP_ACCEPT_LANGUAGE'],
                'Sec-CH-UA-Mobile' => $server['HTTP_SEC_CH_UA_MOBILE'],
                self::HEADER      => $token,
            ],
        ];
    }

    /** 預載請求在伺服器端看到的標頭，用來計算與讀取器、寫入端相同的變體。 */
    public static function server( string $device, string $locale ): array {
        $phone = $device === 'm';
        return [
            'HTTP_USER_AGENT'       => $phone ? self::PHONE_AGENT : self::DESKTOP_AGENT,
            'HTTP_SEC_CH_UA_MOBILE' => $phone ? '?1' : '?0',
            'HTTP_ACCEPT_LANGUAGE'  => self::acceptLanguage( $locale ),
        ];
    }

    /** 由網站語系產生 Accept-Language，例如 zh_TW → zh-TW,zh;q=0.9。 */
    public static function acceptLanguage( string $locale ): string {
        if ( ! preg_match( '~\A([a-zA-Z]{2,3})(?:[_-]([a-zA-Z0-9]{2,8}))?(?:[_-]|\z)~', $locale, $parts ) ) {
            return 'en-US,en;q=0.9';
        }
        $primary = strtolower( $parts[1] );
        return isset( $parts[2] ) ? $primary . '-' . $parts[2] . ',' . $primary . ';q=0.9' : $primary;
    }

    /** WP-Cron 回呼：處理佇列，最多約 40 秒。 */
    public static function run(): void {
        if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        $settings = YSCache::settings();
        if ( empty( $settings['preload'] ) || ! YSCacheEarly::active( $settings ) ) {
            // 停止每分鐘喚醒；早期快取恢復作用後由 maintainSchedule() 重新排程。
            wp_clear_scheduled_hook( self::HOOK );
            return;
        }
        $directory = YSCacheEarly::directory();
        $host = YSCacheEarly::host();
        $runLock = self::directoryReady( $directory ) ? self::openLock( $directory . self::RUN_LOCK ) : null;
        if ( $runLock === null ) {
            return;
        }
        try {
            if ( ! flock( $runLock, LOCK_EX | LOCK_NB ) ) {
                return;
            }
            $started = microtime( true );
            $limit = (int) ini_get( 'max_execution_time' );
            if ( $limit > 0 && $limit < 120 && function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            if ( self::trackingRendersServerEvents() ) {
                // 寫入端不會共用這類頁面，每次渲染卻會替 PixelYourSite 送出一筆伺服器端瀏覽事件。
                $paused = 'Paused: PixelYourSite sends server events while rendering, so preloading would record fake visits. Pages are still cached from visitor renders.';
                if ( self::readState( $directory )['last_error'] !== $paused ) {
                    self::mutate( $directory, static function ( array $state ) use ( $paused ): array {
                        $state['last_error'] = $paused;
                        return $state;
                    } );
                }
                return;
            }
            $snapshot = YSCacheEarlyStore::snapshot( $directory, '/' );
            $epoch = is_array( $snapshot ) ? (string) $snapshot['epoch'] : '';
            if ( self::fullDue( self::readState( $directory ), time(), $epoch ) ) {
                $paths = self::paths();
                self::mutate( $directory, static fn ( array $state ): array => self::withFull( $state, $paths, time(), $epoch ) );
                YSCacheEarlyStore::prune( $directory, 2.0 );
            }
            $config = YSCacheEarly::config( $settings );
            $locale = (string) get_locale();
            $fresh = static function ( string $path, string $device ) use ( $directory, $host, $config, $locale ): bool {
                $variant = YSCacheEarlyRequest::variant( self::server( $device, $locale ), $config );
                return $variant !== null && YSCacheEarlyStore::fresh( $directory, $host, $path, $variant, self::FRESH_SECONDS );
            };
            $fetch = static function ( string $path, string $device ) use ( $host, $locale ): array {
                $started = microtime( true );
                $response = wp_remote_get( 'https://' . $host . $path, self::requestArgs( $device, self::sign( $path, time(), wp_salt( 'auth' ) ), $locale ) );
                $seconds = microtime( true ) - $started;
                if ( is_wp_error( $response ) ) {
                    return [ 'status' => 0, 'error' => $response->get_error_message(), 'seconds' => $seconds ];
                }
                return [ 'status' => (int) wp_remote_retrieve_response_code( $response ), 'error' => '', 'seconds' => $seconds ];
            };
            self::process( $directory, $fetch, $fresh, self::BUDGET_SECONDS - ( microtime( true ) - $started ) );
        } finally {
            flock( $runLock, LOCK_UN );
            fclose( $runLock );
        }
    }

    /**
     * PixelYourSite 沒有同時開啟兩個 Ajax 伺服器事件選項時，會在渲染當下送出伺服器事件；
     * 預載每次渲染都會變成一筆假的瀏覽，所以暫停預載（快取仍由一般訪客的渲染寫入，不受影響）。
     */
    private static function trackingRendersServerEvents(): bool {
        if ( ! defined( 'PYS_VERSION' ) || ! function_exists( 'PixelYourSite\\PYS' ) ) {
            return false;
        }
        $plugin = \PixelYourSite\PYS();
        return is_object( $plugin ) && is_callable( [ $plugin, 'getOption' ] )
            && ( ! $plugin->getOption( 'server_event_use_ajax' ) || ! $plugin->getOption( 'server_static_event_use_ajax' ) );
    }

    /** 已要求整批預載、距離上次整批超過一小時，或儲存區整體換代（全站清除）時，重新排入全部網址。 */
    public static function fullDue( array $state, int $now, string $epoch ): bool {
        return $state['full'] || $now - $state['last_full'] >= self::REFRESH_SECONDS || ( $epoch !== '' && $epoch !== $state['epoch'] );
    }

    /**
     * 依序處理佇列（$fetch 與 $fresh 可由測試替換）。
     *
     * 每個路徑處理桌機與手機：已有剩餘 30 分鐘以上的新鮮項目就跳過，否則送一次預載請求。
     * 兩個裝置都新鮮或都已嘗試兩次就移出佇列；有兩個回應超過 10 秒、連線失敗或伺服器忙碌時提早結束。
     *
     * @param callable(string,string):array{status:int,error:string,seconds:float} $fetch
     * @param callable(string,string):bool                                       $fresh
     * @return array{checked:int,requests:int,rendered:int,error:string,stopped:string}
     */
    public static function process( string $directory, callable $fetch, callable $fresh, float $budget ): array {
        $started = microtime( true );
        $state = self::readState( $directory );
        $summary = [ 'checked' => 0, 'requests' => 0, 'rendered' => 0, 'error' => '', 'stopped' => '' ];
        $results = [];
        $slow = 0;
        foreach ( $state['pending'] as $path => $seq ) {
            if ( microtime( true ) - $started >= $budget ) {
                $summary['stopped'] = 'budget';
                break;
            }
            $path = (string) $path;
            $summary['checked']++;
            $attempts = $state['attempts'][ $path ] ?? [ 0, 0 ];
            $done = [ false, false ];
            $failed = false;
            $stop = '';
            foreach ( self::DEVICES as $index => $device ) {
                if ( $fresh( $path, $device ) ) {
                    $done[ $index ] = true;
                    continue;
                }
                if ( $attempts[ $index ] >= self::MAX_ATTEMPTS ) {
                    continue;
                }
                if ( microtime( true ) - $started >= $budget ) {
                    $stop = 'budget';
                    break;
                }
                if ( $results ) {
                    // 送出請求前先存下進度，程序被中止時不會重做已完成的路徑。
                    self::applyResults( $directory, $results );
                    $results = [];
                }
                $attempts[ $index ]++;
                $response = $fetch( $path, $device );
                $summary['requests']++;
                $status = (int) ( $response['status'] ?? 0 );
                if ( (float) ( $response['seconds'] ?? 0 ) > self::SLOW_SECONDS && ++$slow >= 2 ) {
                    $stop = 'slow';
                }
                if ( $status === 200 && $fresh( $path, $device ) ) {
                    $done[ $index ] = true;
                    $summary['rendered']++;
                } else {
                    $failed = true;
                    $summary['error'] = self::describe( $path, $device, $status, (string) ( $response['error'] ?? '' ) );
                    if ( in_array( $status, [ 301, 302, 303, 307, 308, 404, 410 ], true ) ) {
                        // 轉址與不存在的頁面重試也不會改變。
                        $attempts[ $index ] = self::MAX_ATTEMPTS;
                    } elseif ( $status === 0 || in_array( $status, [ 401, 403, 429, 502, 503, 504 ], true ) ) {
                        $stop = $stop !== '' ? $stop : 'error';
                    }
                }
                if ( $stop !== '' ) {
                    break;
                }
            }
            $finished = ( $done[0] || $attempts[0] >= self::MAX_ATTEMPTS ) && ( $done[1] || $attempts[1] >= self::MAX_ATTEMPTS );
            $results[] = [ $path, (int) $seq, $attempts, $finished, $finished && ! $done[0] && ! $done[1], $failed ];
            if ( $stop !== '' ) {
                $summary['stopped'] = $stop;
                break;
            }
        }
        self::applyResults( $directory, $results, $summary['checked'] > 0 ? $summary : null );
        return $summary;
    }

    private static function applyResults( string $directory, array $results, ?array $summary = null ): void {
        if ( $results === [] && $summary === null ) {
            return;
        }
        self::mutate( $directory, static function ( array $state ) use ( $results, $summary ): array {
            $now = time();
            foreach ( $results as [ $path, $seq, $attempts, $finished, $gaveUp, $failed ] ) {
                $state = self::withResult( $state, $path, $seq, $attempts, $finished, $gaveUp, $failed, $now );
            }
            if ( $summary !== null ) {
                $state['last_run'] = $now;
                $state['last_rendered'] = $summary['rendered'];
                $state['last_error'] = self::message( $summary['error'] );
            }
            return $state;
        } );
    }

    private static function describe( string $path, string $device, int $status, string $error ): string {
        $reason = $status === 0 ? ( $error !== '' ? $error : 'request failed' ) : 'HTTP ' . $status . ( $status === 200 ? ', not cached' : '' );
        return self::message( $path . ' (' . ( $device === 'm' ? 'phone' : 'desktop' ) . '): ' . $reason );
    }

    private static function message( string $message ): string {
        $message = trim( (string) preg_replace( '~[\x00-\x1f\x7f]+~', ' ', $message ) );
        return strlen( $message ) > self::MAX_ERROR_LENGTH ? substr( $message, 0, self::MAX_ERROR_LENGTH ) : $message;
    }

    /**
     * 要預載的網站路徑：首頁、商店頁、文章頁、非空的商品分類與標籤、頁面（排除購物車、結帳、帳戶、YS CART 頁面）、
     * 最近 50 篇文章與已發布商品；去除排除路徑後最多 2,000 個，可用 ys_cache_preload_paths 調整。
     */
    public static function paths(): array {
        $settings = YSCache::settings();
        $host = YSCacheEarly::host();
        $urls = [ home_url( '/' ) ];
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            $urls[] = wc_get_page_permalink( 'shop' );
        }
        $postsPage = (int) get_option( 'page_for_posts' );
        if ( $postsPage > 0 && get_option( 'show_on_front' ) === 'page' ) {
            $urls[] = get_permalink( $postsPage );
        }
        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_terms( [
                'taxonomy'               => $taxonomy,
                'hide_empty'             => true,
                'number'                 => self::MAX_PATHS,
                'orderby'                => 'count',
                'order'                  => 'DESC',
                'update_term_meta_cache' => false,
            ] );
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                if ( $term instanceof \WP_Term ) {
                    $urls[] = get_term_link( $term );
                }
            }
        }
        foreach ( [
            [ 'page', self::MAX_PATHS, self::privatePages(), 'menu_order', 'ASC' ],
            [ 'post', self::RECENT_POSTS, [], 'date', 'DESC' ],
            [ 'product', self::MAX_PATHS, [], 'date', 'DESC' ],
        ] as [ $type, $limit, $exclude, $orderby, $order ] ) {
            if ( ! post_type_exists( $type ) ) {
                continue;
            }
            $posts = get_posts( [
                'post_type'              => $type,
                'post_status'            => 'publish',
                'numberposts'            => $limit,
                'has_password'           => false,
                'exclude'                => $exclude,
                'orderby'                => $orderby,
                'order'                  => $order,
                'update_post_meta_cache' => false,
            ] );
            foreach ( is_array( $posts ) ? $posts : [] as $post ) {
                $urls[] = get_permalink( $post );
            }
        }
        $exclude = (string) ( $settings['exclude_paths'] ?? '' );
        $paths = apply_filters( 'ys_cache_preload_paths', self::sitePaths( $urls, $host, $exclude, self::MAX_PATHS ) );
        return self::sitePaths( is_array( $paths ) ? $paths : [], $host, $exclude, self::MAX_PATHS );
    }

    /** WooCommerce 購物車、結帳、帳戶頁與 YS CART 的私有頁（ys_ec_page_*_id）。 */
    private static function privatePages(): array {
        global $wpdb;
        $ids = [];
        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
                $ids[] = (int) wc_get_page_id( $page );
            }
        }
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            $values = $wpdb->get_col( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'ys_ec_page_' ) . '%' . $wpdb->esc_like( '_id' )
            ) );
            foreach ( is_array( $values ) ? $values : [] as $value ) {
                $ids[] = (int) $value;
            }
        }
        return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
    }

    /**
     * 把網址或路徑轉成本站可早期送出的路徑：只接受 https、同主機、無查詢字串，並排除設定的路徑。
     */
    public static function sitePaths( array $items, string $host, string $excludePaths, int $limit ): array {
        $paths = [];
        foreach ( $items as $item ) {
            if ( count( $paths ) >= $limit ) {
                break;
            }
            if ( ! is_string( $item ) || $item === '' ) {
                continue;
            }
            $path = $item[0] === '/' ? $item : self::sitePath( $item, $host );
            if ( $path !== null && self::validPath( $path ) && ! isset( $paths[ $path ] ) && ! self::excluded( $excludePaths, $path ) ) {
                $paths[ $path ] = true;
            }
        }
        return array_map( 'strval', array_keys( $paths ) );
    }

    public static function sitePath( string $url, string $host ): ?string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || $host === '' ||
            array_intersect( [ 'user', 'pass', 'query', 'fragment' ], array_keys( $parts ) ) ) {
            return null;
        }
        $authority = strtolower( (string) ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        $path = (string) ( $parts['path'] ?? '/' );
        return $authority === strtolower( $host ) && self::validPath( $path ) ? $path : null;
    }

    /** 與 YSCache 的 exclude_paths 比對相同：* 為萬用字元，/path/* 也排除 /path 本身，原始與解碼後的路徑都比對。 */
    public static function excluded( string $rules, string $path ): bool {
        $decoded = rawurldecode( $path );
        foreach ( preg_split( '/\R/', $rules, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $item ) {
            $item = trim( $item );
            if ( $item === '' ) {
                continue;
            }
            $pattern = '~\A' . str_replace( '\*', '.*', preg_quote( $item, '~' ) ) . '/?\z~iD';
            foreach ( [ $path, $decoded ] as $subject ) {
                if ( ( str_ends_with( $item, '/*' ) && strcasecmp( substr( $item, 0, -2 ), rtrim( $subject, '/' ) ) === 0 ) ||
                    preg_match( $pattern, $subject ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function validPath( string $path ): bool {
        return strlen( $path ) <= self::MAX_PATH_LENGTH && YSCacheEarlyStore::validRequestPath( $path );
    }

    /** 把路徑寫入佇列：指定路徑放在最前面並重設嘗試次數；$full 要求下一次執行展開全部網址。 */
    public static function writeQueue( string $directory, array $paths, bool $full ): bool {
        return self::directoryReady( $directory )
            && self::mutate( $directory, static fn ( array $state ): array => self::withQueued( $state, $paths, $full ) );
    }

    public static function withQueued( array $state, array $paths, bool $full ): array {
        $front = [];
        foreach ( $paths as $path ) {
            if ( is_string( $path ) && self::validPath( $path ) ) {
                $front[ $path ] = true;
            }
        }
        if ( $front ) {
            $seq = ++$state['seq'];
            foreach ( array_keys( $front ) as $path ) {
                unset( $state['pending'][ $path ], $state['attempts'][ $path ], $state['cooldown'][ $path ] );
            }
            $state['pending'] = array_fill_keys( array_map( 'strval', array_keys( $front ) ), $seq ) + $state['pending'];
            $state = self::capped( $state );
        }
        if ( $full ) {
            $state['full'] = true;
        }
        return $state;
    }

    /** 整批預載：把尚未排入、且不在冷卻期的路徑接在佇列後面。 */
    public static function withFull( array $state, array $paths, int $now, string $epoch ): array {
        $seq = ++$state['seq'];
        foreach ( $paths as $path ) {
            if ( count( $state['pending'] ) >= self::MAX_PENDING ) {
                break;
            }
            if ( is_string( $path ) && self::validPath( $path ) && ! isset( $state['pending'][ $path ] ) && ( $state['cooldown'][ $path ] ?? 0 ) <= $now ) {
                $state['pending'][ $path ] = $seq;
            }
        }
        $state['full'] = false;
        $state['last_full'] = $now;
        $state['total'] = count( $paths );
        $state['epoch'] = $epoch;
        return $state;
    }

    /**
     * 記錄一個路徑的處理結果。路徑在處理期間被重新排入（序號不同）時保留新的排程。
     * 完成就移出（兩個裝置都失敗時進入冷卻）；有失敗但未完成就移到最後；只是時間用完則留在原位。
     */
    public static function withResult( array $state, string $path, int $seq, array $attempts, bool $finished, bool $gaveUp, bool $failed, int $now ): array {
        if ( ( $state['pending'][ $path ] ?? null ) !== $seq ) {
            return $state;
        }
        $attempts = [ self::attempts( $attempts[0] ?? 0 ), self::attempts( $attempts[1] ?? 0 ) ];
        if ( $finished ) {
            unset( $state['pending'][ $path ], $state['attempts'][ $path ] );
            if ( $gaveUp ) {
                $state['cooldown'][ $path ] = $now + self::COOLDOWN_SECONDS;
            }
            return $state;
        }
        if ( $failed ) {
            unset( $state['pending'][ $path ] );
            $state['pending'][ $path ] = $seq;
        }
        if ( $attempts === [ 0, 0 ] ) {
            unset( $state['attempts'][ $path ] );
        } else {
            $state['attempts'][ $path ] = $attempts;
        }
        return $state;
    }

    private static function capped( array $state ): array {
        if ( count( $state['pending'] ) > self::MAX_PENDING ) {
            $state['pending'] = array_slice( $state['pending'], 0, self::MAX_PENDING, true );
        }
        $state['attempts'] = array_intersect_key( $state['attempts'], $state['pending'] );
        return $state;
    }

    /** 讀取佇列（寫入一律以 rename 原子替換，不需要加鎖）。 */
    public static function readState( string $directory ): array {
        if ( ! self::directoryReady( $directory ) ) {
            return self::normalize( null, time() );
        }
        $file = $directory . self::STATE;
        clearstatcache( true, $file );
        $json = ! is_link( $file ) && is_file( $file ) ? @file_get_contents( $file, false, null, 0, self::MAX_STATE_BYTES + 1 ) : false;
        $data = is_string( $json ) && strlen( $json ) <= self::MAX_STATE_BYTES ? json_decode( $json, true, 8 ) : null;
        return self::normalize( $data, time() );
    }

    public static function normalize( $data, int $now ): array {
        $data = is_array( $data ) ? $data : [];
        $state = [
            'pending'       => [],
            'attempts'      => [],
            'cooldown'      => [],
            'seq'           => self::number( $data['seq'] ?? 0 ),
            'full'          => ( $data['full'] ?? false ) === true,
            'last_full'     => self::number( $data['last_full'] ?? 0 ),
            'last_run'      => self::number( $data['last_run'] ?? 0 ),
            'last_rendered' => self::number( $data['last_rendered'] ?? 0 ),
            'last_error'    => is_string( $data['last_error'] ?? null ) ? self::message( $data['last_error'] ) : '',
            'total'         => self::number( $data['total'] ?? 0 ),
            'epoch'         => is_string( $data['epoch'] ?? null ) && preg_match( '~\A[a-f0-9]{32}\z~', $data['epoch'] ) ? $data['epoch'] : '',
        ];
        foreach ( is_array( $data['pending'] ?? null ) ? $data['pending'] : [] as $path => $seq ) {
            if ( count( $state['pending'] ) >= self::MAX_PENDING ) {
                break;
            }
            if ( is_string( $path ) && is_int( $seq ) && $seq >= 0 && self::validPath( $path ) ) {
                $state['pending'][ $path ] = $seq;
            }
        }
        foreach ( is_array( $data['attempts'] ?? null ) ? $data['attempts'] : [] as $path => $counts ) {
            if ( is_string( $path ) && isset( $state['pending'][ $path ] ) && is_array( $counts ) ) {
                $pair = [ self::attempts( $counts[0] ?? 0 ), self::attempts( $counts[1] ?? 0 ) ];
                if ( $pair !== [ 0, 0 ] ) {
                    $state['attempts'][ $path ] = $pair;
                }
            }
        }
        foreach ( is_array( $data['cooldown'] ?? null ) ? $data['cooldown'] : [] as $path => $until ) {
            if ( count( $state['cooldown'] ) >= self::MAX_PENDING ) {
                break;
            }
            if ( is_string( $path ) && is_int( $until ) && $until > $now && self::validPath( $path ) ) {
                $state['cooldown'][ $path ] = $until;
            }
        }
        return $state;
    }

    private static function number( $value ): int {
        return is_int( $value ) && $value > 0 ? $value : 0;
    }

    private static function attempts( $value ): int {
        return is_int( $value ) ? max( 0, min( self::MAX_ATTEMPTS, $value ) ) : 0;
    }

    /** 在 preload.lock 排他鎖內讀取、修改並寫回佇列。 */
    private static function mutate( string $directory, callable $change ): bool {
        $lock = self::openLock( $directory . self::LOCK );
        if ( $lock === null ) {
            return false;
        }
        try {
            return flock( $lock, LOCK_EX ) && self::writeState( $directory, $change( self::readState( $directory ) ) );
        } finally {
            fclose( $lock );
        }
    }

    private static function writeState( string $directory, array $state ): bool {
        $json = json_encode( $state, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_STATE_BYTES ) {
            return false;
        }
        try {
            $stage = $directory . '/.preload-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
        } catch ( \Exception $failure ) {
            return false;
        }
        $handle = @fopen( $stage, 'xb' );
        if ( $handle === false ) {
            return false;
        }
        @chmod( $stage, 0600 );
        $written = @fwrite( $handle, $json ) === strlen( $json ) && @fflush( $handle );
        fclose( $handle );
        if ( $written && @rename( $stage, $directory . self::STATE ) ) {
            return true;
        }
        @unlink( $stage );
        return false;
    }

    /** @return resource|null */
    private static function openLock( string $file ) {
        clearstatcache( true, $file );
        if ( is_link( $file ) ) {
            return null;
        }
        $created = ! file_exists( $file );
        $handle = @fopen( $file, 'c+b' );
        if ( $handle === false ) {
            return null;
        }
        if ( $created ) {
            @chmod( $file, 0600 );
        }
        return $handle;
    }

    private static function directoryReady( string $directory ): bool {
        if ( $directory === '' ) {
            return false;
        }
        clearstatcache( true, $directory );
        return is_dir( $directory ) && ! is_link( $directory );
    }
}
