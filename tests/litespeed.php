<?php

namespace LiteSpeed {
    // Local transport fixtures. No live server or CDN is exercised here.
    final class Base {
        public const O_GUEST = 'guest';
        public const O_CACHE_FORCE_URI = 'cache-force_uri';
        public const O_CACHE_FORCE_PUB_URI = 'cache-force_pub_uri';
    }

    final class Root {
        public static function cls( string $name ): object {
            \verify( $name === 'Control', 'read options from the native Control object' );
            return new Control();
        }
    }

    final class Control {
        public function conf( string $name ) {
            return $GLOBALS['fixture']['native_options'][ $name ] ?? null;
        }

        public static function set_nocache_hard( string $reason ): void {
            $GLOBALS['events'][] = [ 'native_hard_no_cache', $reason ];
        }

        public function get_ttl(): int {
            return $GLOBALS['fixture']['ttl'] ?? 28800;
        }

        public static function set_custom_ttl( int $ttl, string $reason ): void {
            $GLOBALS['events'][] = [ 'native_ttl', $ttl ];
        }
    }

    final class Purge {
        public const DB_QUEUE = 'queue';

        public static function name( string $name ): string {
            return 'litespeed.purge.' . $name;
        }
    }

    final class Tag {
        public static function get_uri_tag( string $path ): string {
            return 'page-' . md5( $path );
        }
    }

    final class Core {
        public static function kick_purge_queue(): bool {
            $fixture = $GLOBALS['fixture'];
            ++$GLOBALS['kicks'];
            $url = $fixture['ajax'];
            if ( $fixture['race'] ) {
                $GLOBALS['queue'] = 'X-LiteSpeed-Purge: public,abc_unrelated';
            }
            $GLOBALS['http_args'] = \apply_filters( 'http_request_args', [ 'method' => 'GET', 'redirection' => 5 ], $url );
            if ( $fixture['transport_throws'] ) {
                throw new \RuntimeException( 'Simulated vendor transport error' );
            }
            if ( $fixture['consume'] ) {
                $GLOBALS['queue'] = '-1';
            }
            if ( $fixture['see_response'] ) {
                \apply_filters( 'http_response', [ 'code' => $fixture['code'], 'body' => $fixture['body'] ], $GLOBALS['http_args'], $url );
            }
            return $fixture['code'] > 0 && $fixture['code'] < 500;
        }
    }
}

namespace {
    use YangSheep\Cache\YSCacheLiteSpeed;

    define( 'ABSPATH', __DIR__ . '/' );
    define( 'LSCWP_V', '7.9.3' );
    define( 'LSWCP_TAG_PREFIX', 'abc' );
    define( 'LITESPEED_ON', true );

    function __( $message, $domain ) { return $message; }
    function get_option( $name, $default ) { return $name === 'active_plugins' ? $GLOBALS['fixture']['plugins'] : $default; }
    function has_action( $hook, $callback = null ) { return $GLOBALS['fixture']['hooks'] ? 10 : false; }
    function is_multisite() { return $GLOBALS['fixture']['multisite']; }
    function admin_url( $path ) { return $GLOBALS['fixture']['ajax']; }
    function home_url( $path ) { return 'https://shop.example.com/store/'; }
    function wp_parse_url( $url ) { return parse_url( $url ); }
    function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
    function wp_remote_retrieve_body( $response ) { return $response['body']; }

    function add_filter( $hook, $callback, $priority, $args = 1 ): void {
        $GLOBALS['filters'][ $hook ][] = [ $callback, $args ];
    }

    function remove_filter( $hook, $callback, $priority ): void {
        $GLOBALS['filters'][ $hook ] = array_values( array_filter( $GLOBALS['filters'][ $hook ] ?? [],
            static fn ( array $entry ): bool => $entry[0] !== $callback ) );
    }

    function apply_filters( $hook, $value, ...$args ) {
        foreach ( $GLOBALS['filters'][ $hook ] ?? [] as [ $callback, $accepted ] ) {
            $value = $callback( $value, ...array_slice( $args, 0, $accepted - 1 ) );
        }
        return $value;
    }

    function do_action( $hook, $value ): void {
        $GLOBALS['events'][] = [ $hook, $value ];
        if ( $hook === 'litespeed_control_set_nocache' ) {
            return;
        }
        verify( apply_filters( 'litespeed_delay_purge', false ), 'the adapter requests the persisted native queue' );
        if ( $GLOBALS['fixture']['hook_throws'] ) {
            throw new RuntimeException( 'Simulated native hook error' );
        }
        if ( ! $GLOBALS['fixture']['write_queue'] ) {
            return;
        }
        $tag = $hook === 'litespeed_purge' ? 'abc_' : 'abc_' . \LiteSpeed\Tag::get_uri_tag( wp_parse_url( $value )['path'] );
        $GLOBALS['tags'][] = $GLOBALS['fixture']['alter_tag'] ? 'abc_other' : $tag;
        $GLOBALS['queue'] = 'X-LiteSpeed-Purge: public,' . ( $GLOBALS['fixture']['stale'] ? 'stale,' : '' ) . implode( ',', $GLOBALS['tags'] );
    }

    function verify( $passed, string $label ): void {
        if ( ! $passed ) {
            throw new RuntimeException( 'Failed: ' . $label );
        }
    }

    function reset_fixture( array $overrides = [] ): void {
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        $_SERVER['X-LSCACHE'] = 'on';
        unset( $_SERVER['LSWS_EDITION'] );
        $GLOBALS['fixture'] = array_replace( [
            'ajax' => 'https://shop.example.com/wp/admin-ajax.php',
            'hooks' => true,
            'plugins' => [ 'litespeed-cache/litespeed-cache.php' ],
            'multisite' => false,
            'native_options' => [ 'guest' => false, 'cache-force_uri' => [], 'cache-force_pub_uri' => [] ],
            'write_queue' => true,
            'alter_tag' => false,
            'race' => false,
            'hook_throws' => false,
            'transport_throws' => false,
            'stale' => false,
            'code' => 400,
            'body' => '0',
            'consume' => true,
            'see_response' => true,
        ], $overrides );
        $GLOBALS['queue'] = '-1';
        $GLOBALS['events'] = [];
        $GLOBALS['tags'] = [];
        $GLOBALS['filters'] = [];
        $GLOBALS['kicks'] = 0;
        $GLOBALS['http_args'] = [];
    }

    $wpdb = new class() {
        public string $options = 'wp_options';

        public function prepare( string $query, string $option ): string {
            verify( str_starts_with( $query, 'SELECT option_value FROM wp_options' ), 'read vendor queue directly without WordPress cache' );
            return $option;
        }

        public function get_var( string $option ): ?string {
            verify( $option === 'litespeed.purge.queue', 'read only the public purge queue' );
            return $GLOBALS['queue'];
        }
    };

    require dirname( __DIR__ ) . '/src/YSCacheLiteSpeed.php';

    reset_fixture();
    verify( YSCacheLiteSpeed::status()['available'], 'verified web-server setup' );
    $result = YSCacheLiteSpeed::purge();
    verify( $result['status'] === 'ok' && $kicks === 1 && $queue === '-1', 'public-tag dispatch observed before success' );
    verify( $events === [ [ 'litespeed_purge', '*' ] ], 'does not dispatch the broader vendor purge-all hook' );
    verify( $http_args['redirection'] === 0 && $http_args['blocking'] && $http_args['timeout'] === 3, 'bounded loopback transport' );
    verify( ! array_filter( $filters ), 'temporary HTTP and native filters are removed' );

    reset_fixture();
    $_SERVER['SERVER_SOFTWARE'] = 'OpenLiteSpeed';
    $url = 'https://shop.example.com/store/en/page/';
    verify( YSCacheLiteSpeed::purge( [ $url ] )['status'] === 'ok', 'OpenLiteSpeed targeted tag dispatch' );
    verify( $events === [ [ 'litespeed_purge_url', $url ] ], 'only the requested page is sent to the origin' );

    foreach ( [
        [ 'code' => 302 ], [ 'code' => 403 ], [ 'code' => 500 ], [ 'code' => 0 ], [ 'body' => 'HTML' ],
        [ 'consume' => false ], [ 'see_response' => false ], [ 'race' => true ], [ 'write_queue' => false ],
        [ 'alter_tag' => true ], [ 'stale' => true ], [ 'hook_throws' => true ], [ 'transport_throws' => true ],
    ] as $overrides ) {
        reset_fixture( $overrides );
        $result = YSCacheLiteSpeed::purge();
        verify( $result['status'] === 'error' && $result['retryable'], 'unconfirmed dispatch never returns success' );
        verify( ! array_filter( $filters ), 'temporary filters removed on failure' );
    }

    reset_fixture();
    $queue = 'X-LiteSpeed-Purge: public,abc_other';
    verify( YSCacheLiteSpeed::purge()['status'] === 'error' && ! $events && ! $kicks, 'does not replace another native queue' );
    foreach ( [ null, '' ] as $missing ) {
        reset_fixture();
        $queue = $missing;
        verify( YSCacheLiteSpeed::purge()['status'] === 'error' && ! $events, 'unknown database or queue state fails closed' );
    }

    foreach ( [ [ 'plugins' => [] ], [ 'multisite' => true ], [ 'hooks' => false ] ] as $overrides ) {
        reset_fixture( $overrides );
        verify( ! YSCacheLiteSpeed::status()['available'] && YSCacheLiteSpeed::purge()['status'] === 'error' && ! $events, 'unavailable integration cannot purge' );
    }
    foreach ( [ 'guest' => true, 'cache-force_uri' => [ '/member/' ], 'cache-force_pub_uri' => [ '/cart/' ] ] as $option => $value ) {
        reset_fixture();
        $fixture['native_options'][ $option ] = $value;
        verify( ! YSCacheLiteSpeed::status()['available'] && YSCacheLiteSpeed::purge()['status'] === 'error' && ! $events, 'Guest Mode and either force option disable integration' );
    }
    reset_fixture();
    unset( $fixture['native_options']['guest'] );
    verify( ! YSCacheLiteSpeed::status()['available'], 'unknown effective cache settings disable integration' );
    reset_fixture();
    $_SERVER['SERVER_SOFTWARE'] = 'Apache';
    $_SERVER['HTTP_X_LSCACHE'] = 'enabled';
    verify( ! YSCacheLiteSpeed::status()['available'], 'a proxy or client header is not native engine evidence' );

    reset_fixture();
    foreach ( [ 'https://other.example.com/store/page/', 'https://shop.example.com/other-install/', 'https://shop.example.com/store/a%0ab/' ] as $url ) {
        verify( YSCacheLiteSpeed::purge( [ $url ] )['status'] === 'error' && ! $events, 'reject an unsafe or out-of-site target' );
    }
    reset_fixture( [ 'ajax' => 'https://other.example.com/wp-admin/admin-ajax.php' ] );
    verify( YSCacheLiteSpeed::purge()['status'] === 'error' && ! $events, 'no external loopback' );

    reset_fixture();
    YSCacheLiteSpeed::noCache();
    verify( $events === [ [ 'native_hard_no_cache', 'YS CACHE dynamic response' ] ], 'native hard dynamic response guard' );

    reset_fixture();
    verify( YSCacheLiteSpeed::limitTtl( 3600 ) && $events === [ [ 'native_ttl', 3600 ] ], 'shortens native TTL to the remaining guest window' );
    reset_fixture( [ 'ttl' => 900 ] );
    verify( YSCacheLiteSpeed::limitTtl( 3600 ) && ! $events, 'preserves the shorter server TTL' );
    verify( ! YSCacheLiteSpeed::limitTtl( 0 ), 'rejects expired guest content' );

    echo "LiteSpeed status and confirmed-dispatch checks passed.\n";
}
