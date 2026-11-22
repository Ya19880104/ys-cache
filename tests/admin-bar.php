<?php

namespace YangSheep\Cache {
    final class YSCache {
        public static array $calls = [];

        public static function purge( string $scope ): array {
            self::$calls[] = [ 'site', $scope ];
            return [];
        }

        public static function purgePage( string $url ): array {
            self::$calls[] = [ 'page', $url ];
            return [];
        }
    }
}

namespace {
    use YangSheep\Cache\Admin\YSCacheAdminBar;
    use YangSheep\Cache\YSCache;

    define( 'ABSPATH', __DIR__ );
    define( 'YS_CACHE_FILE', 'ys-cache/ys-cache.php' );
    define( 'YS_CACHE_VERSION', 'test' );

    final class WP_Admin_Bar {
        public array $nodes = [];
        public function add_node( array $node ): void { $this->nodes[ $node['id'] ] = $node; }
    }

    final class WP_Post {}

    final class AjaxResponse extends RuntimeException {
        public function __construct( public int $status ) {}
    }

    function add_action( $hook, $callback ) { $GLOBALS['toolbar_hooks'][ $hook ] = $callback; }
    function is_admin_bar_showing() { return $GLOBALS['bar_visible']; }
    function current_user_can( $capability ) { return $capability === 'manage_options' && $GLOBALS['can_manage']; }
    function __( $text, $domain ) { return $text; }
    function esc_html__( $text, $domain ) { return $text; }
    function wp_enqueue_script( ...$args ) { $GLOBALS['enqueued'] = $args; }
    function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localization'] = $data; }
    function wp_add_inline_style( $handle, $style ) {}
    function plugin_dir_url( $file ) { return 'https://example.test/plugins/ys-cache/'; }
    function admin_url( $path ) { return 'https://example.test/site/wp-admin/' . $path; }
    function wp_create_nonce( $action ) { return 'toolbar-test-nonce'; }
    function home_url( $path ) { return 'https://example.test/site/'; }
    function is_admin() { return $GLOBALS['admin']; }
    function get_current_screen() { return $GLOBALS['screen']; }
    function get_post() { return new WP_Post(); }
    function is_post_publicly_viewable( $post ) { return $GLOBALS['public_post']; }
    function wp_get_canonical_url( $post ) { return 'https://example.test/site/example-post/'; }
    function is_preview() { return false; }
    function is_search() { return false; }
    function is_404() { return false; }
    function is_feed() { return false; }
    function wp_unslash( $text ) { return stripslashes( $text ); }
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
    function check_ajax_referer( $action, $field, $stop ) {
        return $action === 'ys_cache_toolbar_purge' && ( $_POST[ $field ] ?? '' ) === 'toolbar-test-nonce';
    }
    function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
    function wp_send_json_success( $value ) { throw new AjaxResponse( 200 ); }
    function wp_send_json_error( $value, $status ) { throw new AjaxResponse( $status ); }

    function expect( $condition, string $description ): void {
        if ( ! $condition ) {
            throw new RuntimeException( $description );
        }
    }

    function submit( $scope, string $nonce, string $method = 'POST', string $url = '' ): int {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = compact( 'scope', 'nonce', 'url' );
        try {
            YSCacheAdminBar::purge();
        } catch ( AjaxResponse $response ) {
            return $response->status;
        }
        throw new RuntimeException( 'Expected a JSON response.' );
    }

    require dirname( __DIR__ ) . '/src/Admin/YSCacheAdminBar.php';
    YSCacheAdminBar::register();
    expect( isset( $GLOBALS['toolbar_hooks']['admin_bar_menu'], $GLOBALS['toolbar_hooks']['admin_enqueue_scripts'],
        $GLOBALS['toolbar_hooks']['wp_enqueue_scripts'], $GLOBALS['toolbar_hooks']['wp_ajax_ys_cache_toolbar_purge'] ), 'Frontend, backend, and authenticated Ajax hooks are registered.' );

    $GLOBALS['bar_visible'] = true;
    $GLOBALS['can_manage'] = false;
    $bar = new WP_Admin_Bar();
    YSCacheAdminBar::menu( $bar );
    YSCacheAdminBar::enqueue();
    expect( $bar->nodes === [] && ! isset( $GLOBALS['enqueued'] ), 'Non-admins see no purge links or assets.' );
    expect( submit( 'site', 'toolbar-test-nonce' ) === 403, 'Non-admins cannot purge.' );

    $GLOBALS['can_manage'] = true;
    $GLOBALS['admin'] = false;
    $_GET = [ 'utm_source' => 'test' ];
    $_SERVER['REQUEST_URI'] = '/site/example-page/?utm_source=test';
    YSCacheAdminBar::menu( $bar );
    YSCacheAdminBar::enqueue();
    expect( $bar->nodes['ys-cache-purge-page']['parent'] === 'ys-cache-purge' &&
        $bar->nodes['ys-cache-purge-site']['parent'] === 'ys-cache-purge', 'The page and site actions share a toolbar menu.' );
    expect( $bar->nodes['ys-cache-purge']['meta']['tabindex'] === 0, 'The menu without a destination is keyboard reachable.' );
    expect( $GLOBALS['localization']['page'] === 'https://example.test/site/example-page/', 'Frontend uses the public URL without tracking parameters.' );
    $_GET = [ 'p' => '123' ];
    $_SERVER['REQUEST_URI'] = '/site/?p=123';
    YSCacheAdminBar::enqueue();
    expect( $GLOBALS['localization']['page'] === '', 'Query-based routes do not silently select the homepage.' );

    $GLOBALS['admin'] = true;
    $GLOBALS['public_post'] = true;
    $GLOBALS['screen'] = (object) [ 'base' => 'post', 'action' => '' ];
    YSCacheAdminBar::enqueue();
    expect( $GLOBALS['localization']['page'] === 'https://example.test/site/example-post/', 'The editor uses the published canonical permalink.' );
    $GLOBALS['screen'] = (object) [ 'base' => 'dashboard', 'action' => '' ];
    YSCacheAdminBar::enqueue();
    expect( $GLOBALS['localization']['page'] === '', 'Dashboard requires explicit page selection.' );

    expect( submit( 'site', 'toolbar-test-nonce', 'GET' ) === 403, 'GET must never purge.' );
    expect( submit( 'site', 'invalid' ) === 403, 'Ajax requires a valid nonce.' );
    expect( submit( 'page', 'toolbar-test-nonce' ) === 400, 'A missing URL never requests a site purge.' );
    expect( submit( 'unexpected', 'toolbar-test-nonce' ) === 400, 'Unknown scopes must fail.' );
    expect( submit( 'page', 'toolbar-test-nonce', 'POST', 'https://example.test/site/example-page/' ) === 200, 'An authorized page purge is accepted.' );
    expect( submit( 'site', 'toolbar-test-nonce' ) === 200, 'An authorized site purge is accepted.' );
    expect( YSCache::$calls === [ [ 'page', 'https://example.test/site/example-page/' ], [ 'site', 'all' ] ], 'Page and site scopes call distinct core methods.' );
    echo "Admin bar checks passed.\n";
}
