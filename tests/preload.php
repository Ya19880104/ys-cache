<?php

namespace YangSheep\Cache {
    final class YSCache {
        public static array $settings = [ 'early' => true, 'preload' => true, 'exclude_paths' => '' ];

        public static function settings( bool $fresh = false ): array {
            return self::$settings;
        }
    }

    final class YSCacheEarly {
        public static string $directory = '';

        public static function directory(): string {
            return self::$directory;
        }

        public static function host(): string {
            return 'store.example.test';
        }

        public static function wanted( array $settings ): bool {
            return ! empty( $settings['early'] );
        }

        public static function active( array $settings ): bool {
            return self::wanted( $settings ) && self::$directory !== '';
        }

        public static function config( array $settings ): array {
            return [ 'host' => self::host() ];
        }

        public static function ttl( array $settings, string $path ): int {
            return (int) ( $settings['ttl_seconds'] ?? 36000 );
        }
    }
}

namespace PixelYourSite {
    function PYS(): object {
        return new class {
            public function getOption( string $key ) {
                return $GLOBALS['pys_options'][ $key ] ?? null;
            }
        };
    }
}

namespace {
    use YangSheep\Cache\YSCache;
    use YangSheep\Cache\YSCacheEarly;
    use YangSheep\Cache\YSCacheEarlyRequest as Request;
    use YangSheep\Cache\YSCacheEarlyStore as Store;
    use YangSheep\Cache\YSCachePreload as Preload;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    final class WP_Term {
        public function __construct( public string $taxonomy, public string $slug ) {}
    }

    final class WP_Error {
        public function __construct( private string $message ) {}

        public function get_error_message(): string {
            return $this->message;
        }
    }

    function expect_same( $expected, $actual, string $message ): void {
        if ( $expected !== $actual ) {
            throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
        }
    }

    function apply_filters( string $hook, $value, ...$args ) {
        return array_key_exists( $hook, $GLOBALS['filters'] ?? [] ) ? $GLOBALS['filters'][ $hook ] : $value;
    }

    function add_filter( string $hook, $callback, int $priority = 10, int $accepted = 1 ): void {
        $GLOBALS['hooks'][] = [ 'filter', $hook, $callback, $priority ];
    }

    function add_action( string $hook, $callback, int $priority = 10, int $accepted = 1 ): void {
        $GLOBALS['hooks'][] = [ 'action', $hook, $callback, $priority ];
    }

    function wp_salt( string $scope ): string {
        return 'preload-fixture-' . $scope;
    }

    function wp_next_scheduled( string $hook ) {
        return $GLOBALS['scheduled'][ $hook ] ?? false;
    }

    function wp_schedule_event( int $time, string $recurrence, string $hook ): bool {
        $GLOBALS['scheduled'][ $hook ] = $time;
        $GLOBALS['recurrence'][ $hook ] = $recurrence;
        return true;
    }

    function wp_clear_scheduled_hook( string $hook ): int {
        unset( $GLOBALS['scheduled'][ $hook ] );
        return 1;
    }

    function wp_doing_cron(): bool {
        return $GLOBALS['doing_cron'] ?? false;
    }

    function get_locale(): string {
        return 'zh_TW';
    }

    function home_url( string $path = '' ): string {
        return 'https://store.example.test' . $path;
    }

    function wc_get_page_permalink( string $page ): string {
        return home_url( '/' . $page . '/' );
    }

    function wc_get_page_id( string $page ): int {
        return [ 'cart' => 5, 'checkout' => 6, 'myaccount' => 7 ][ $page ] ?? -1;
    }

    function get_option( string $name, $default = false ) {
        return [ 'page_for_posts' => 8, 'show_on_front' => 'page' ][ $name ] ?? $default;
    }

    function get_permalink( $post ) {
        return $GLOBALS['permalinks'][ is_object( $post ) ? $post->ID : (int) $post ] ?? false;
    }

    function taxonomy_exists( string $taxonomy ): bool {
        return in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true );
    }

    function get_terms( array $args ): array {
        $GLOBALS['term_queries'][] = $args;
        return $GLOBALS['fixture_terms'][ $args['taxonomy'] ] ?? [];
    }

    function get_term_link( WP_Term $term ): string {
        return home_url( '/' . $term->taxonomy . '/' . $term->slug . '/' );
    }

    function post_type_exists( string $type ): bool {
        return in_array( $type, [ 'page', 'post', 'product' ], true );
    }

    function get_posts( array $args ): array {
        $GLOBALS['post_queries'][ $args['post_type'] ] = $args;
        return $GLOBALS['fixture_posts'][ $args['post_type'] ] ?? [];
    }

    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }

    function wp_remote_retrieve_response_code( $response ) {
        return $response['response']['code'] ?? '';
    }

    /**
     * 模擬本機回送：伺服器端驗證簽章，寫入端只在乾淨請求時依請求的裝置寫入早期儲存區。
     * 與 WordPress 的 HTTP 函式庫（Requests 的 IRI 正規化）相同，送出的網址百分比編碼一律是大寫。
     */
    function wp_remote_get( string $url, array $args ) {
        $GLOBALS['remote'][] = [ $url, $args ];
        $parts = parse_url( $url );
        $sent = (string) preg_replace_callback( '~%[0-9a-f]{2}~i', static fn ( array $octet ): string => strtoupper( $octet[0] ), $parts['path'] );
        $server = [
            'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => $parts['host'], 'REQUEST_URI' => $sent,
            'HTTP_USER_AGENT' => $args['user-agent'], 'HTTP_ACCEPT' => $args['headers']['Accept'],
            'HTTP_ACCEPT_LANGUAGE' => $args['headers']['Accept-Language'], 'HTTP_SEC_CH_UA_MOBILE' => $args['headers']['Sec-CH-UA-Mobile'],
            Request::PRELOAD_HEADER => $args['headers']['X-YS-Cache-Preload'],
        ];
        if ( ! Preload::verify( $server, wp_salt( 'auth' ), time() ) ) {
            return [ 'response' => [ 'code' => 403 ] ];
        }
        $writer = Request::writerPath( $server, $args['cookies'], [ 'host' => YSCacheEarly::host() ] );
        if ( $writer !== null && ! in_array( $writer['path'], $GLOBALS['uncacheable'] ?? [], true ) ) {
            Store::write( YSCacheEarly::$directory, YSCacheEarly::host(), $writer['path'], Store::snapshot( YSCacheEarly::$directory, $writer['path'] ),
                [ 'Content-Type: text/html; charset=UTF-8' ], '<!doctype html><html><body>' . $writer['path'] . '</body></html>', 36000, $writer['variant'] );
        }
        return [ 'response' => [ 'code' => 200 ] ];
    }

    function registered( string $type, string $hook ): array {
        return array_values( array_filter( $GLOBALS['hooks'] ?? [], static fn ( array $entry ): bool => $entry[0] === $type && $entry[1] === $hook ) );
    }

    $wpdb = new class {
        public string $options = 'wp_options';

        public function esc_like( string $text ): string {
            return addcslashes( $text, '_%\\' );
        }

        public function prepare( string $query, ...$args ): string {
            $GLOBALS['wpdb_like'] = $args[0];
            return $query;
        }

        public function get_col( string $query ): array {
            return [ '41', '0', 'not-a-number' ];
        }
    };

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-preload-test-' . bin2hex( random_bytes( 8 ) );
    define( 'ABSPATH', $root . '/public/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    $_SERVER['DOCUMENT_ROOT'] = ABSPATH;
    if ( ! mkdir( WP_CONTENT_DIR, 0700, true ) ) {
        throw new RuntimeException( 'Could not create the fixture document root.' );
    }

    try {
        require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
        require dirname( __DIR__ ) . '/src/YSCacheEarlyRequest.php';
        require dirname( __DIR__ ) . '/src/YSCachePreload.php';
        $host = YSCacheEarly::host();
        $key = wp_salt( 'auth' );

        // 簽章：<unix>.<HMAC>，綁定路徑，300 秒內有效。
        $now = 1764000000;
        $token = Preload::sign( '/product/soap/', $now, $key );
        expect_same( 1, preg_match( '~\A1764000000\.[a-f0-9]{64}\z~', $token ), 'Token format' );
        expect_same( hash_hmac( 'sha256', 'ys-cache-preload|1764000000|/product/soap/', $key ), substr( $token, 11 ), 'Token message layout' );
        $signed = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/product/soap/', Request::PRELOAD_HEADER => $token ];
        expect_same( true, Preload::verify( $signed, $key, $now ), 'Accept a valid token' );
        expect_same( true, Preload::verify( $signed, $key, $now + 300 ), 'Accept a token at the edge of the window' );
        expect_same( true, Preload::verify( array_replace( $signed, [ 'REQUEST_METHOD' => 'HEAD' ] ), $key, $now ), 'Accept HEAD' );
        expect_same( false, Preload::verify( $signed, $key, $now + 301 ), 'Reject an expired token' );
        expect_same( false, Preload::verify( $signed, $key, $now - 301 ), 'Reject a token from the future' );
        expect_same( false, Preload::verify( array_replace( $signed, [ 'REQUEST_URI' => '/product/other/' ] ), $key, $now ), 'Reject a token for another path' );
        expect_same( false, Preload::verify( array_replace( $signed, [ 'REQUEST_URI' => '/product/soap/?add-to-cart=12' ] ), $key, $now ), 'Reject a query string' );
        expect_same( false, Preload::verify( array_replace( $signed, [ 'REQUEST_METHOD' => 'POST' ] ), $key, $now ), 'Reject POST' );
        $tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === '0' ? '1' : '0' );
        expect_same( false, Preload::verify( array_replace( $signed, [ Request::PRELOAD_HEADER => $tampered ] ), $key, $now ), 'Reject a tampered signature' );
        expect_same( false, Preload::verify( array_replace( $signed, [ Request::PRELOAD_HEADER => ( $now + 1 ) . substr( $token, 10 ) ] ), $key, $now ), 'Reject a moved timestamp' );
        expect_same( false, Preload::verify( array_replace( $signed, [ Request::PRELOAD_HEADER => strtoupper( $token ) ] ), $key, $now ), 'Reject a malformed token' );
        expect_same( false, Preload::verify( array_replace( $signed, [ Request::PRELOAD_HEADER => [ $token ] ] ), $key, $now ), 'Reject a non-string header' );
        expect_same( false, Preload::verify( $signed, 'another-site-salt', $now ), 'Reject a token signed with another key' );
        expect_same( false, Preload::verify( $signed, '', $now ), 'Reject when no key is available' );
        expect_same( false, Preload::verify( array_diff_key( $signed, [ Request::PRELOAD_HEADER => true ] ), $key, $now ), 'Reject a request without the header' );
        // WordPress 的 HTTP 函式庫（Requests）送出前把百分比編碼改成大寫：以小寫固定網址簽章的預載仍要通過驗證。
        $lowerPath = '/product-category/%e6%89%8b%e5%b7%a5%e7%9a%82/';
        $upperPath = '/product-category/%E6%89%8B%E5%B7%A5%E7%9A%82/';
        $loopback = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $upperPath, Request::PRELOAD_HEADER => Preload::sign( $lowerPath, $now, $key ) ];
        expect_same( true, Preload::verify( $loopback, $key, $now ), 'Accept a loopback whose percent-encoding the HTTP library uppercased' );
        expect_same( Preload::sign( $lowerPath, $now, $key ), Preload::sign( $upperPath, $now, $key ), 'Sign the canonical path' );
        expect_same( false, Preload::verify( array_replace( $signed, [ 'REQUEST_URI' => '/Product/soap/' ] ), $key, $now ), 'Letter case outside percent-encoding is still another path' );

        // register()：掛上排程、清除事件，並讓有效的預載請求不建立 PixelYourSite session（同一請求只判斷一次）。
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/shop/';
        $_SERVER[ Request::PRELOAD_HEADER ] = Preload::sign( '/shop/', time(), $key );
        Preload::register();
        expect_same( true, Preload::isPreloadRequest(), 'Recognize the signed loopback request' );
        $skip = registered( 'filter', 'pys_skip_session' );
        expect_same( 1, count( $skip ), 'Skip the tracking session for preload renders' );
        expect_same( [ '__return_true', PHP_INT_MAX ], [ $skip[0][2], $skip[0][3] ], 'Skip the tracking session with the last word' );
        Preload::skipTrackingSession();
        expect_same( 1, count( registered( 'filter', 'pys_skip_session' ) ), 'Add the session filter once' );
        unset( $_SERVER[ Request::PRELOAD_HEADER ] );
        expect_same( true, Preload::isPreloadRequest(), 'Cache the decision for the request' );
        expect_same( [ Preload::class, 'run' ], registered( 'action', 'ys_cache_preload' )[0][2] ?? null, 'Run on the cron event' );
        expect_same( [ Preload::class, 'enqueueAll' ], registered( 'action', 'ys_cache_purged_all' )[0][2] ?? null, 'Queue all pages after a site purge' );
        expect_same( [ Preload::class, 'purgedPage' ], registered( 'action', 'ys_cache_purged_page' )[0][2] ?? null, 'Queue a page after a page purge' );
        expect_same( 0, registered( 'action', 'plugins_loaded' )[0][3] ?? null, 'Re-check the session skip early when loaded before pluggable functions' );
        expect_same( 1, count( registered( 'action', 'init' ) ), 'Maintain the schedule on init' );
        expect_same( [ 'existing' => [ 'interval' => 300 ], 'ys_cache_minute' => [ 'interval' => 60, 'display' => 'YS CACHE' ] ],
            Preload::cronSchedules( [ 'existing' => [ 'interval' => 300 ] ] ), 'Add a one-minute schedule' );

        // 預載的兩種身分：與 wp_is_mobile() 相同地對應到桌機 d 與手機 m。
        foreach ( [ 'd' => '?0', 'm' => '?1' ] as $device => $hint ) {
            $args = Preload::requestArgs( $device, 'signed-token', 'zh_TW' );
            expect_same( $device === 'm' ? Preload::PHONE_AGENT : Preload::DESKTOP_AGENT, $args['user-agent'], 'Use the device user agent: ' . $device );
            expect_same( true, str_ends_with( $args['user-agent'], ' YSCachePreload/1' ), 'Identify the preloader in the user agent' );
            expect_same( $device, Request::variant( Preload::server( $device, 'zh_TW' ), [] ), 'Map the preload identity to the variant: ' . $device );
            expect_same( $device, Request::variant( [ 'HTTP_USER_AGENT' => $args['user-agent'] ], [] ), 'The user agent alone selects the same variant: ' . $device );
            expect_same( $device === 'm', Request::isMobile( [ 'HTTP_USER_AGENT' => $args['user-agent'] ] ), 'Match wp_is_mobile(): ' . $device );
            expect_same( $device . '-zh_tw', Request::variant( Preload::server( $device, 'zh_TW' ), [ 'languages' => [ 'en_US', 'zh_TW' ] ] ), 'Fill the site-language suggestion variant: ' . $device );
            expect_same( [
                'timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'cookies' => [], 'user-agent' => $args['user-agent'],
                'headers' => [ 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'zh-TW,zh;q=0.9', 'Sec-CH-UA-Mobile' => $hint, 'X-YS-Cache-Preload' => 'signed-token' ],
            ], $args, 'Send a cookie-free, non-following request: ' . $device );
            $loopback = [ 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => $host, 'REQUEST_URI' => '/shop/', 'HTTP_ACCEPT' => $args['headers']['Accept'],
                Request::PRELOAD_HEADER => 'signed-token' ] + Preload::server( $device, 'zh_TW' );
            expect_same( [ 'path' => '/shop/', 'variant' => $device ], Request::writerPath( $loopback, [], [ 'host' => $host ] ), 'The loopback qualifies as a clean writer request: ' . $device );
        }
        $GLOBALS['filters']['https_local_ssl_verify'] = true;
        expect_same( true, Preload::requestArgs( 'd', 't', 'en_US' )['sslverify'], 'Honor https_local_ssl_verify' );
        unset( $GLOBALS['filters']['https_local_ssl_verify'] );
        foreach ( [ 'zh_TW' => 'zh-TW,zh;q=0.9', 'en_US' => 'en-US,en;q=0.9', 'ja' => 'ja', 'de_DE_formal' => 'de-DE,de;q=0.9', 'es_419' => 'es-419,es;q=0.9', '' => 'en-US,en;q=0.9', '../x' => 'en-US,en;q=0.9' ] as $locale => $header ) {
            expect_same( $header, Preload::acceptLanguage( (string) $locale ), 'Accept-Language for ' . $locale );
        }

        // 網址轉成本站路徑。
        expect_same( '/product/soap/', Preload::sitePath( 'https://store.example.test/product/soap/', $host ), 'Accept a same-site https URL' );
        expect_same( '/', Preload::sitePath( 'https://STORE.example.test', $host ), 'Treat a bare host as the home path' );
        expect_same( '/x/', Preload::sitePath( 'https://store.example.test:8443/x/', 'store.example.test:8443' ), 'Match an explicit port' );
        foreach ( [
            'http://store.example.test/x/', 'https://other.example.test/x/', 'https://store.example.test/?p=12', 'https://store.example.test/x/#top',
            'https://user:secret@store.example.test/x/', 'https://store.example.test:8443/x/', 'https://store.example.test/wp-admin/',
            'https://store.example.test/feed/', 'https://store.example.test/index.php', 'https://store.example.test/a//b/', 'not a url',
        ] as $url ) {
            expect_same( null, Preload::sitePath( $url, $host ), 'Reject ' . $url );
        }
        $encoded = '/%E6%89%8B%E5%B7%A5%E7%9A%82/';
        $exclude = "/members/*\n/private-page/\n/sale-*\n/手工皂/禮盒/*";
        // 與 YSCache 相同：/private-page/ 不排除沒有結尾斜線的 /private-page（WordPress 會轉址到有斜線的網址）。
        // 百分比編碼統一成小寫的標準路徑，大小寫不同的同一頁只排入一次。
        expect_same( [ '/', '/shop/', '/product/soap/', '/private-page', strtolower( $encoded ) ], Preload::sitePaths( [
            'https://store.example.test/', 'https://store.example.test/shop/', 'https://store.example.test/shop/', '/product/soap/',
            'https://store.example.test/members', 'https://store.example.test/members/vip/', 'https://store.example.test/private-page/',
            'https://store.example.test/private-page', 'https://store.example.test/sale-2026/', 'https://store.example.test' . $encoded,
            strtolower( $encoded ),
            'https://store.example.test' . $encoded . '%E7%A6%AE%E7%9B%92/', 'https://store.example.test/cart/?x=1', false, null, 12, '',
            '//evil.example/x/', '/wp-json/wc/store/', '/' . str_repeat( 'a', 600 ) . '/',
        ], $host, $exclude, 2000 ), 'Keep unique, servable, non-excluded paths' );
        expect_same( [ '/', '/shop/' ], Preload::sitePaths( [ '/', '/shop/', '/about/' ], $host, '', 2 ), 'Cap the number of paths' );
        expect_same( true, Preload::excluded( '/Members/*', '/members' ), 'Match exclusions case-insensitively like YSCache' );
        expect_same( false, Preload::excluded( '/members/*', '/membership/' ), 'Do not over-match a wildcard base' );

        // paths()：首頁、商店頁、文章頁、非空的商品分類與標籤、頁面（排除私有頁）、最近文章與商品。
        $directory = $root . '/storage';
        expect_same( true, Store::initialize( $directory ), 'Create the isolated early store' );
        YSCacheEarly::$directory = $directory;
        YSCache::$settings['exclude_paths'] = "/members/*";
        $GLOBALS['permalinks'] = [
            8 => home_url( '/blog/' ), 20 => home_url( '/about/' ), 21 => home_url( '/members/' ), 22 => home_url( '/about/' ),
            30 => home_url( '/2026/11/hello/' ), 31 => home_url( '/?p=31' ), 40 => home_url( '/product/soap/' ), 41 => 'https://other.example.test/x/',
        ];
        $GLOBALS['fixture_terms'] = [ 'product_cat' => [ new WP_Term( 'product_cat', 'soap' ) ], 'product_tag' => [ new WP_Term( 'product_tag', 'gift' ) ] ];
        $GLOBALS['fixture_posts'] = [
            'page' => [ (object) [ 'ID' => 20 ], (object) [ 'ID' => 21 ], (object) [ 'ID' => 22 ] ],
            'post' => [ (object) [ 'ID' => 30 ], (object) [ 'ID' => 31 ] ],
            'product' => [ (object) [ 'ID' => 40 ], (object) [ 'ID' => 41 ] ],
        ];
        $sitePaths = [ '/', '/shop/', '/blog/', '/product_cat/soap/', '/product_tag/gift/', '/about/', '/2026/11/hello/', '/product/soap/' ];
        expect_same( $sitePaths, Preload::paths(), 'Collect the public pages to warm' );
        expect_same( [ 5, 6, 7, 41 ], $GLOBALS['post_queries']['page']['exclude'], 'Exclude cart, checkout, account and YS CART pages' );
        expect_same( 'ys\_ec\_page\_%\_id', $GLOBALS['wpdb_like'], 'Find every YS CART page option' );
        foreach ( [ 'page' => 2000, 'post' => 50, 'product' => 2000 ] as $type => $limit ) {
            $query = $GLOBALS['post_queries'][ $type ];
            expect_same( [ 'publish', false, $limit ], [ $query['post_status'], $query['has_password'], $query['numberposts'] ], 'Query published, unprotected ' . $type );
        }
        expect_same( [ true, true ], [ $GLOBALS['term_queries'][0]['hide_empty'], $GLOBALS['term_queries'][1]['hide_empty'] ], 'Warm only non-empty product terms' );
        $GLOBALS['filters']['ys_cache_preload_paths'] = [ '/', '/landing/', '/wp-admin/', 'https://store.example.test/campaign/', '/members/vip/', '/landing/' ];
        expect_same( [ '/', '/landing/', '/campaign/' ], Preload::paths(), 'Revalidate filtered paths' );
        unset( $GLOBALS['filters']['ys_cache_preload_paths'] );

        // 佇列檔：位於早期儲存區（網站目錄外），0600，去除重複並依序排列。
        expect_same( [ 'pending' => 0, 'last_run' => null, 'last_rendered' => 0, 'last_error' => '', 'full' => false, 'last_full' => null, 'total' => 0 ],
            Preload::status(), 'Start with an empty queue' );
        expect_same( true, Preload::writeQueue( $directory, [ '/a/', '/b/', '/a/', '/wp-admin/', 'https://store.example.test/c/' ], false ), 'Write the queue' );
        $state = Preload::readState( $directory );
        expect_same( [ '/a/' => 1, '/b/' => 1 ], $state['pending'], 'Store valid unique paths in order' );
        expect_same( false, file_exists( ABSPATH . 'preload.json' ) || file_exists( WP_CONTENT_DIR . '/preload.json' ), 'Never write the queue into the web root' );
        if ( DIRECTORY_SEPARATOR === '/' ) {
            expect_same( 0600, fileperms( $directory . '/preload.json' ) & 0777, 'Restrict the queue file' );
            expect_same( 0600, fileperms( $directory . '/preload.lock' ) & 0777, 'Restrict the lock file' );
        }
        $moved = Preload::withResult( $state, '/a/', 1, [ 1, 0 ], false, false, true, $now );
        expect_same( [ '/b/', '/a/' ], array_keys( $moved['pending'] ), 'Move a failed path behind the others' );
        expect_same( [ '/a/' => [ 1, 0 ] ], $moved['attempts'], 'Remember the attempts' );
        $requeued = Preload::withQueued( $moved, [ '/a/' ], false );
        expect_same( [ '/a/' => 2, '/b/' => 1 ], $requeued['pending'], 'A purge moves the path to the front with a new sequence' );
        expect_same( [], $requeued['attempts'], 'A purge resets the attempts' );
        expect_same( $moved, Preload::withResult( $moved, '/a/', 99, [ 2, 2 ], true, true, true, $now ), 'Ignore a result for a superseded sequence' );

        expect_same( true, Preload::writeQueue( $directory, array_map( static fn ( int $i ): string => '/p' . $i . '/', range( 1, 4100 ) ), false ), 'Queue a large batch' );
        $state = Preload::readState( $directory );
        expect_same( 4000, count( $state['pending'] ), 'Cap the queue at 4,000 paths' );
        expect_same( [ '/p1/', '/p4000/' ], [ array_key_first( $state['pending'] ), array_key_last( $state['pending'] ) ], 'Keep the newest explicit paths first' );
        expect_same( false, isset( $state['pending']['/a/'] ), 'Drop the oldest entries beyond the cap' );

        Preload::enqueuePaths( [ '/fresh-news/', '/wp-login.php', 5, '/fresh-news/' ] );
        Preload::enqueueAll();
        expect_same( [ 4001, true ], [ Preload::status()['pending'], Preload::status()['full'] ], 'Report queued work before shutdown' );
        Preload::persist();
        $state = Preload::readState( $directory );
        expect_same( [ '/fresh-news/', 4000, true ], [ array_key_first( $state['pending'] ), count( $state['pending'] ), $state['full'] ], 'Persist the request queue once at shutdown' );
        YSCache::$settings['preload'] = false;
        Preload::enqueuePaths( [ '/ignored/' ] );
        Preload::persist();
        expect_same( false, isset( Preload::readState( $directory )['pending']['/ignored/'] ), 'Do not queue while preloading is off' );
        YSCache::$settings['preload'] = true;
        Preload::enqueuePaths( [ '/cancelled/' ] );
        Preload::cancel();
        Preload::persist();
        expect_same( false, isset( Preload::readState( $directory )['pending']['/cancelled/'] ), 'Cancel drops unsaved work' );

        file_put_contents( $directory . '/preload.json', '{"pending":{"/ok/":3,"/../x/":4,"/neg/":-1},"attempts":{"/ok/":[7,"x"]},"seq":3,"full":"yes","last_error":"bad\u0001byte"}' );
        $state = Preload::readState( $directory );
        expect_same( [ [ '/ok/' => 3 ], [ '/ok/' => [ 2, 0 ] ], false, 'bad byte' ], [ $state['pending'], $state['attempts'], $state['full'], $state['last_error'] ], 'Sanitize a damaged queue file' );
        file_put_contents( $directory . '/preload.json', '{not json' );
        expect_same( 0, Preload::status()['pending'], 'Treat an unreadable queue as empty' );
        YSCacheEarly::$directory = '';
        expect_same( 0, Preload::status()['pending'], 'Report an empty queue without storage' );
        YSCacheEarly::$directory = $directory;

        // 整批預載與排程時機。
        $epoch = str_repeat( 'a', 32 );
        $state = Preload::normalize( [ 'pending' => [ '/x/' => 3 ], 'seq' => 3, 'full' => true,
            'cooldown' => [ '/cool/' => $now + 100, '/expired/' => $now - 1 ] ], $now );
        expect_same( [ '/cool/' => $now + 100 ], $state['cooldown'], 'Forget expired cooldowns' );
        $full = Preload::withFull( $state, [ '/', '/x/', '/cool/', '/y/', '/wp-admin/' ], $now, $epoch );
        expect_same( [ '/x/' => 3, '/' => 4, '/y/' => 4 ], $full['pending'], 'Append unqueued paths outside their cooldown' );
        expect_same( [ false, $now, 5, $epoch ], [ $full['full'], $full['last_full'], $full['total'], $full['epoch'] ], 'Record the full refresh' );
        expect_same( false, Preload::fullDue( $full, $now + 3599, $epoch ), 'Wait an hour between full refreshes' );
        expect_same( true, Preload::fullDue( $full, $now + 3600, $epoch ), 'Refresh every hour' );
        expect_same( true, Preload::fullDue( $full, $now + 10, str_repeat( 'b', 32 ) ), 'Refresh after a site-wide generation change' );
        expect_same( false, Preload::fullDue( $full, $now + 10, '' ), 'An unreadable generation does not force a refresh' );
        expect_same( true, Preload::fullDue( Preload::withQueued( $full, [], true ), $now + 10, $epoch ), 'Refresh when requested' );
        expect_same( [ 3600, 5400 ], Preload::timing( 36000 ), 'A 10-hour lifetime refreshes hourly and renews pages with less than 90 minutes left' );
        $multilingual = [ 'languages' => [ 'en_US', 'zh_TW' ], 'language_slugs' => [ 'zh-tw' => 'zh_TW' ], 'default_language' => 'en_US' ];
        expect_same( 'zh_TW', Preload::pathLocale( '/zh-tw/shop/', $multilingual, 'en_US' ), 'Preload translated pages in their own language' );
        expect_same( 'en_US', Preload::pathLocale( '/shop/', $multilingual, 'zh_TW' ), 'Preload default-language pages in the default language' );
        expect_same( 'zh_TW', Preload::pathLocale( '/shop/', [], 'zh_TW' ), 'Single-language sites use the site locale' );
        $preloadVariant = static fn ( string $path, string $device ): ?string => YangSheep\Cache\YSCacheEarlyRequest::variant(
            Preload::server( $device, Preload::pathLocale( $path, $multilingual, 'en_US' ) ) + [ 'REQUEST_URI' => $path ], $multilingual );
        expect_same( [ 'd', 'm', 'd', 'm' ], [ $preloadVariant( '/shop/', 'd' ), $preloadVariant( '/shop/', 'm' ), $preloadVariant( '/zh-tw/shop/', 'd' ), $preloadVariant( '/zh-tw/shop/', 'm' ) ],
            'Preloaded pages are the shared no-suggestion variants that same-language visitors hit' );
        expect_same( [ 450, 675 ], Preload::timing( 900 ), 'A 15-minute lifetime shortens both so pages never expire between refreshes' );
        expect_same( [ 300, 90 ], Preload::timing( 120 ), 'Very short lifetimes wait 5 minutes between full refreshes and count a fresh render as fresh' );
        expect_same( [ 900, 1350 ], Preload::timing( 1800 ), 'A 30-minute path rule gets its own threshold' );
        expect_same( true, Preload::fullDue( $full, $now + 450, $epoch, 450 ), 'Short lifetimes refresh sooner' );
        $crowded = Preload::withFull( Preload::normalize( [], $now ), array_map( static fn ( int $i ): string => '/q' . $i . '/', range( 1, 4100 ) ), $now, '' );
        expect_same( 4000, count( $crowded['pending'] ), 'Cap the full refresh' );

        // process()：每個路徑處理桌機與手機，跳過新鮮項目，失敗兩次或轉址就放棄並進入冷卻。
        $config = [ 'host' => $host ];
        $variant = static fn ( string $device ): string => Request::variant( Preload::server( $device, 'zh_TW' ), $config );
        $fresh = static fn ( string $path, string $device ): bool => Store::fresh( $directory, $host, $path, $variant( $device ), 1800 );
        $store = static function ( string $path, string $device, int $ttl = 3600 ) use ( $directory, $host, $variant ): void {
            expect_same( true, Store::write( $directory, $host, $path, Store::snapshot( $directory, $path ), [ 'Content-Type: text/html; charset=UTF-8' ],
                '<!doctype html><html><body>' . $path . $device . '</body></html>', $ttl, $variant( $device ) ), 'Seed ' . $path . ' ' . $device );
        };
        $calls = [];
        $fetch = static function ( string $path, string $device ) use ( &$calls, $store ): array {
            $calls[] = $path . ' ' . $device;
            if ( $path === '/redirect/' ) {
                return [ 'status' => 301, 'error' => '', 'seconds' => 0.1 ];
            }
            if ( $path === '/uncacheable/' ) {
                return [ 'status' => 200, 'error' => '', 'seconds' => 0.1 ];
            }
            $store( $path, $device );
            return [ 'status' => 200, 'error' => '', 'seconds' => 0.1 ];
        };
        $reset = static function ( array $paths ) use ( $directory ): void {
            @unlink( $directory . '/preload.json' );
            expect_same( true, Preload::writeQueue( $directory, $paths, false ), 'Reset the queue' );
        };
        $store( '/fresh/', 'd' );
        $store( '/fresh/', 'm' );
        $store( '/stale/', 'd', 600 );
        $reset( [ '/fresh/', '/render/', '/redirect/', '/uncacheable/', '/stale/' ] );
        $summary = Preload::process( $directory, $fetch, $fresh, 40.0 );
        expect_same( [ '/render/ d', '/render/ m', '/redirect/ d', '/redirect/ m', '/uncacheable/ d', '/uncacheable/ m', '/stale/ d', '/stale/ m' ], $calls, 'Render only stale variants' );
        expect_same( [ 5, 8, 4, '' ], [ $summary['checked'], $summary['requests'], $summary['rendered'], $summary['stopped'] ], 'Summarize the run' );
        $state = Preload::readState( $directory );
        expect_same( [ '/uncacheable/' => 1 ], $state['pending'], 'Remove finished paths and keep the failed one' );
        expect_same( [ '/uncacheable/' => [ 1, 1 ] ], $state['attempts'], 'Count one attempt per device' );
        expect_same( [ true, false ], [ isset( $state['cooldown']['/redirect/'] ), isset( $state['cooldown']['/uncacheable/'] ) ], 'Give up on redirects immediately' );
        $status = Preload::status();
        expect_same( [ 1, 4, '/uncacheable/ (phone): HTTP 200, not cached' ], [ $status['pending'], $status['last_rendered'], $status['last_error'] ], 'Record the run result' );
        expect_same( true, abs( $status['last_run'] - time() ) <= 2, 'Record the run time' );
        expect_same( true, $fresh( '/render/', 'd' ) && $fresh( '/render/', 'm' ) && $fresh( '/stale/', 'd' ), 'Fill both devices' );

        $calls = [];
        Preload::process( $directory, $fetch, $fresh, 40.0 );
        expect_same( [ '/uncacheable/ d', '/uncacheable/ m' ], $calls, 'Retry a failed path once' );
        $state = Preload::readState( $directory );
        expect_same( [ [], [], true ], [ $state['pending'], $state['attempts'], isset( $state['cooldown']['/uncacheable/'] ) ], 'Give up after two attempts per device' );
        expect_same( [ '/fresh/' => $state['seq'] + 1 ], Preload::withFull( $state, [ '/fresh/', '/redirect/', '/uncacheable/' ], time(), '' )['pending'], 'Skip cooled-down paths in a full refresh' );
        expect_same( true, isset( Preload::withQueued( $state, [ '/redirect/' ], false )['pending']['/redirect/'] ) && ! isset( Preload::withQueued( $state, [ '/redirect/' ], false )['cooldown']['/redirect/'] ), 'A purge clears the cooldown' );

        $calls = [];
        $reset( [ '/fresh/', '/render/' ] );
        $summary = Preload::process( $directory, $fetch, $fresh, 40.0 );
        expect_same( [ [], 0, 0, [] ], [ $calls, $summary['requests'], $summary['rendered'], Preload::readState( $directory )['pending'] ], 'Drop already fresh paths without requests' );

        $calls = [];
        $reset( [ '/s1/', '/s2/', '/s3/' ] );
        $slow = static function ( string $path, string $device ) use ( &$calls, $store ): array {
            $calls[] = $path . ' ' . $device;
            $store( $path, $device );
            return [ 'status' => 200, 'error' => '', 'seconds' => 11.0 ];
        };
        $summary = Preload::process( $directory, $slow, $fresh, 40.0 );
        expect_same( [ [ '/s1/ d', '/s1/ m' ], 'slow' ], [ $calls, $summary['stopped'] ], 'Stop after two slow responses' );
        expect_same( [ '/s2/' => 1, '/s3/' => 1 ], Preload::readState( $directory )['pending'], 'Keep the remaining paths untouched' );

        $calls = [];
        $reset( [ '/e1/', '/e2/' ] );
        $broken = static function ( string $path, string $device ) use ( &$calls ): array {
            $calls[] = $path . ' ' . $device;
            return [ 'status' => 0, 'error' => "cURL error 7: Failed to connect\n", 'seconds' => 0.01 ];
        };
        $summary = Preload::process( $directory, $broken, $fresh, 40.0 );
        $state = Preload::readState( $directory );
        expect_same( [ [ '/e1/ d' ], 'error', [ '/e2/', '/e1/' ], [ '/e1/' => [ 1, 0 ] ] ], [ $calls, $summary['stopped'], array_keys( $state['pending'] ), $state['attempts'] ], 'Stop at a connection failure and rotate the path' );
        expect_same( '/e1/ (desktop): cURL error 7: Failed to connect', $state['last_error'], 'Record a readable error' );

        foreach ( [ 404 => '', 503 => 'error', 500 => '' ] as $code => $stopped ) {
            $calls = [];
            $reset( [ '/status/', '/next/' ] );
            $summary = Preload::process( $directory, static function ( string $path, string $device ) use ( &$calls, $code, $store ): array {
                $calls[] = $path . ' ' . $device;
                if ( $path === '/next/' ) {
                    $store( $path, $device );
                    return [ 'status' => 200, 'error' => '', 'seconds' => 0.1 ];
                }
                return [ 'status' => $code, 'error' => '', 'seconds' => 0.1 ];
            }, $fresh, 40.0 );
            expect_same( $stopped, $summary['stopped'], 'Stop decision for HTTP ' . $code );
            $state = Preload::readState( $directory );
            if ( $code === 404 ) {
                expect_same( [ [], true ], [ $state['pending'], isset( $state['cooldown']['/status/'] ) ], 'Give up on missing pages at once' );
            } elseif ( $code === 503 ) {
                expect_same( [ [ '/status/ d' ], [ '/next/', '/status/' ] ], [ $calls, array_keys( $state['pending'] ) ], 'Back off while the server is busy' );
            } else {
                expect_same( [ [ '/status/ d', '/status/ m', '/next/ d', '/next/ m' ], [ '/status/' ] ], [ $calls, array_keys( $state['pending'] ) ], 'Continue after a page error' );
            }
            Store::invalidatePath( $directory, '/next/' );
        }

        $reset( [ '/race/' ] );
        $racing = static function ( string $path, string $device ) use ( $directory, $store ): array {
            $store( $path, $device );
            if ( $device === 'm' ) {
                // 渲染期間另一個請求清除並重新排入同一路徑。
                Preload::writeQueue( $directory, [ $path ], false );
            }
            return [ 'status' => 200, 'error' => '', 'seconds' => 0.1 ];
        };
        Preload::process( $directory, $racing, $fresh, 40.0 );
        $state = Preload::readState( $directory );
        expect_same( [ [ '/race/' => 2 ], [] ], [ $state['pending'], $state['attempts'] ], 'Keep a path that was queued again during its render' );

        $reset( [ '/later/' ] );
        $before = Preload::readState( $directory );
        $summary = Preload::process( $directory, $fetch, $fresh, 0.0 );
        expect_same( [ 0, 0, 'budget' ], [ $summary['checked'], $summary['requests'], $summary['stopped'] ], 'Respect the time budget' );
        expect_same( $before, Preload::readState( $directory ), 'Leave the queue untouched when no time remains' );

        // 排程：設定開啟且早期快取作用中時每分鐘執行，關閉時取消。
        $GLOBALS['scheduled'] = [];
        Preload::maintainSchedule();
        expect_same( [ true, 'ys_cache_minute' ], [ isset( $GLOBALS['scheduled']['ys_cache_preload'] ), $GLOBALS['recurrence']['ys_cache_preload'] ?? null ], 'Schedule the minute preload' );
        YSCache::$settings['preload'] = false;
        Preload::maintainSchedule();
        expect_same( false, isset( $GLOBALS['scheduled']['ys_cache_preload'] ), 'Unschedule when preloading is off' );
        YSCache::$settings['preload'] = true;
        YSCache::$settings['early'] = false;
        $GLOBALS['scheduled']['ys_cache_preload'] = time();
        Preload::maintainSchedule();
        expect_same( false, isset( $GLOBALS['scheduled']['ys_cache_preload'] ), 'Unschedule when early serving is off' );
        YSCache::$settings['early'] = true;
        $GLOBALS['scheduled']['ys_cache_preload'] = time();
        Preload::cancel();
        expect_same( false, isset( $GLOBALS['scheduled']['ys_cache_preload'] ), 'Cancel clears the schedule' );

        // run()：只在 cron 執行；第一次展開全部網址並以兩種身分渲染，之後只處理清除過的頁面。
        @unlink( $directory . '/preload.json' );
        $GLOBALS['filters']['ys_cache_preload_paths'] = [ '/', '/about/', '/product/soap/' ];
        $GLOBALS['remote'] = [];
        Preload::run();
        expect_same( [], $GLOBALS['remote'], 'Do not preload outside cron' );
        $GLOBALS['doing_cron'] = true;
        Preload::run();
        expect_same( 6, count( $GLOBALS['remote'] ), 'Render every path for both devices' );
        expect_same( 'https://store.example.test/about/', $GLOBALS['remote'][2][0], 'Request the site URL for the path' );
        expect_same( [ Preload::DESKTOP_AGENT, Preload::PHONE_AGENT ], [ $GLOBALS['remote'][2][1]['user-agent'], $GLOBALS['remote'][3][1]['user-agent'] ], 'Alternate desktop and phone' );
        foreach ( [ '/', '/about/', '/product/soap/' ] as $path ) {
            expect_same( true, $fresh( $path, 'd' ) && $fresh( $path, 'm' ), 'Warm both devices for ' . $path );
        }
        $status = Preload::status();
        expect_same( [ 0, 6, '', 3 ], [ $status['pending'], $status['last_rendered'], $status['last_error'], $status['total'] ], 'Record the full preload' );
        $GLOBALS['remote'] = [];
        Preload::run();
        expect_same( [], $GLOBALS['remote'], 'Do nothing while every page is fresh' );
        Store::invalidatePath( $directory, '/about/' );
        Preload::purgedPage( '/about/' );
        Preload::persist();
        Preload::run();
        expect_same( [ 'https://store.example.test/about/', 'https://store.example.test/about/' ], array_column( $GLOBALS['remote'], 0 ), 'Re-render only the purged page' );
        $GLOBALS['remote'] = [];
        Store::invalidateAll( $directory );
        Preload::run();
        expect_same( 6, count( $GLOBALS['remote'] ), 'Refill everything after a site-wide generation change' );
        // 中文網址：WordPress 固定網址是小寫百分比編碼，回送時被 HTTP 函式庫改成大寫；仍要寫入，並由預載確認完成。
        $GLOBALS['remote'] = [];
        $chinese = '/%e6%89%8b%e5%b7%a5%e7%9a%82/';
        Preload::enqueuePaths( [ '/%E6%89%8B%E5%B7%A5%E7%9A%82/' ] );
        Preload::persist();
        expect_same( [ $chinese ], array_keys( Preload::readState( $directory )['pending'] ), 'Queue the canonical path' );
        Preload::run();
        expect_same( 2, count( $GLOBALS['remote'] ), 'Render a Chinese page once per device' );
        expect_same( true, $fresh( $chinese, 'd' ) && $fresh( $chinese, 'm' ), 'Store the Chinese page where visitors and preload look for it' );
        expect_same( [ 0, '' ], [ Preload::status()['pending'], Preload::status()['last_error'] ], 'No "not cached" report for percent-encoded paths' );
        $GLOBALS['remote'] = [];
        $GLOBALS['uncacheable'] = [ '/product/soap/' ];
        Store::invalidatePath( $directory, '/product/soap/' );
        Preload::enqueuePaths( [ '/product/soap/' ] );
        Preload::persist();
        Preload::run();
        expect_same( '/product/soap/ (phone): HTTP 200, not cached', Preload::status()['last_error'], 'Report a page the writer refused' );
        $handle = fopen( $directory . '/preload-run.lock', 'c+b' );
        flock( $handle, LOCK_EX );
        $GLOBALS['remote'] = [];
        Preload::run();
        flock( $handle, LOCK_UN );
        fclose( $handle );
        expect_same( [], $GLOBALS['remote'], 'Skip while another preload run holds the lock' );
        YSCache::$settings['preload'] = false;
        $GLOBALS['scheduled']['ys_cache_preload'] = time();
        Preload::run();
        expect_same( false, isset( $GLOBALS['scheduled']['ys_cache_preload'] ), 'A disabled preload unschedules itself' );

        // PixelYourSite 在渲染時送出伺服器事件（未同時開兩個 Ajax 選項）：暫停預載並說明原因，佇列保留。
        YSCache::$settings['preload'] = true;
        $GLOBALS['uncacheable'] = [];
        define( 'PYS_VERSION', '12.6.8' );
        $GLOBALS['pys_options'] = [ 'server_event_use_ajax' => true, 'server_static_event_use_ajax' => false ];
        Store::invalidatePath( $directory, '/' );
        Preload::enqueuePaths( [ '/' ] );
        Preload::persist();
        $GLOBALS['remote'] = [];
        Preload::run();
        $status = Preload::status();
        // 佇列另有先前被寫入端拒絕、尚未用完嘗試次數的 /product/soap/。
        expect_same( [ [], 2 ], [ $GLOBALS['remote'], $status['pending'] ], 'Pause while PixelYourSite sends server events during renders' );
        expect_same( true, str_starts_with( $status['last_error'], 'Paused: PixelYourSite sends server events while rendering' ), 'Explain the pause' );
        $GLOBALS['pys_options']['server_static_event_use_ajax'] = true;
        Preload::run();
        expect_same( [ 'https://store.example.test/', 'https://store.example.test/', 'https://store.example.test/product/soap/', 'https://store.example.test/product/soap/' ],
            array_column( $GLOBALS['remote'], 0 ), 'Resume once both Ajax options are on, purged page first' );
        expect_same( [ 0, '' ], [ Preload::status()['pending'], Preload::status()['last_error'] ], 'Clear the pause message after a clean run' );

        echo "Preload checks passed.\n";
    } finally {
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $entry ) {
            if ( $entry->isDir() && ! $entry->isLink() ) {
                rmdir( $entry->getPathname() );
            } else {
                unlink( $entry->getPathname() );
            }
        }
        rmdir( $root );
    }
}
