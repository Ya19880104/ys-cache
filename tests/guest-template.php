<?php

namespace YangSheep\Cache\Database {
    final class YSCacheSettingsRepo {
        public static array $generation = [ 'site1', 'page1' ];
        public static function pageGenerations( string $path ): array { return self::$generation; }
    }
}

namespace YangSheep\Cache {
    final class YSCache { public static function pathLifetime( $ttl, $rules, $path ): int { return $rules ? min( 30, $ttl ) : $ttl; } }
    final class YSCacheEarly { public static function host(): string { return 'cache.test'; } }
    final class YSCacheEarlyStore { public static function requestVariant( array $profile ): string { return hash( 'sha256', serialize( $profile ) ); } }
    final class YSCacheStorage {
        public static array $rows = [];
        public static int $lastTtl = 0;
        public static function directory(): string { return '/private-test'; }
        public static function write( $dir, $key, $body ): void { self::$rows[ $key ] = $body; }
        public static function read( $dir, $key, $ttl ): ?string { self::$lastTtl = $ttl; return self::$rows[ $key ] ?? null; }
    }
    final class YSCachePixelWarm {
        public static bool $warm = false;
        public static int $scheduled = 0;
        public static function isTemplateWarm(): bool { return self::$warm; }
        public static function scheduleTemplate( array $settings, string $path ): bool { ++self::$scheduled; return true; }
    }
    final class YSCacheIntegrations {
        public static function isElementorAttributionCookie( $name ): bool { return in_array( $name, [ 'PHPSESSID', 'pys_landing_page' ], true ); }
        public static function languageVariant(): string { return 'en'; }
        public static function hasOnlyAttributionSession(): bool { return ! array_diff( array_keys( $_SESSION ?? [] ), [ 'TrafficSource' ] ); }
    }
    final class YSCachePixelTransport { public const COOKIE = '__Host-YSCachePixel'; }
}

namespace {
    use YangSheep\Cache\YSCacheGuestTemplate as Template;
    use YangSheep\Cache\YSCacheStorage as Store;
    use YangSheep\Cache\YSCachePixelWarm as Warmer;
    use YangSheep\Cache\Database\YSCacheSettingsRepo as Generation;

    define( 'ABSPATH', __DIR__ );
    define( 'YS_CACHE_VERSION', 'test' );
    define( 'YS_CACHE_DIR', dirname( __DIR__ ) . '/' );
    require YS_CACHE_DIR . 'src/YSCacheGuestTemplate.php';

    final class WP_Post { public $post_status = 'publish'; }
    final class WP_Term { public $taxonomy = 'product_cat'; }
    final class Registry {
        public array $queue = [];
        public array $registered = [];
        public function enqueue( array $handles ): void { $this->queue = array_unique( array_merge( $this->queue, $handles ) ); }
    }
    function get_template() { return 'blocksy'; }
    function get_stylesheet() { return 'blocksy-child'; }
    function wp_get_theme() { return new class { public function get( $key ) { return '1.0'; } }; }
    function get_template_directory() { return YS_CACHE_DIR . 'tests'; }
    function locate_template( $names ) { return get_template_directory() . '/' . $names[1]; }
    function get_option( $name, $default = false ) { return $default; }
    function is_front_page() { return true; }
    function is_singular( $type = '' ) { return $type === '' || $type === ( $GLOBALS['kind'] ?? 'page' ); }
    function is_tax( $type ) { return false; }
    function is_404() { return false; }
    function is_search() { return false; }
    function is_paged() { return false; }
    function is_preview() { return false; }
    function is_feed() { return false; }
    function is_embed() { return false; }
    function is_trackback() { return false; }
    function is_customize_preview() { return false; }
    function is_user_logged_in() { return $GLOBALS['logged']; }
    function post_password_required() { return false; }
    function get_queried_object() { return new WP_Post; }
    function get_queried_object_id() { return 1; }
    function home_url( $path = '/' ) { return 'https://cache.test' . $path; }
    function wp_parse_url( $url ) { return parse_url( $url ); }
    function wp_unslash( $value ) { return stripslashes( $value ); }
    function get_locale() { return 'en_US'; }
    function add_action( ...$args ) {}
    function add_filter( ...$args ) {}
    function did_action( $hook ) { return 0; }
    function has_filter( $hook ) { return false; }
    function wp_json_encode( $data ) { return json_encode( $data ); }
    function wp_scripts() { return $GLOBALS['scripts']; }
    function wp_styles() { return $GLOBALS['styles']; }
    function get_header( $name = null ) { echo '<header>' . $GLOBALS['visitor'] . '</header>'; }
    function get_footer( $name = null ) { echo '<footer>' . $GLOBALS['visitor'] . '</footer>'; }
    function check( $condition, string $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

    $GLOBALS['scripts'] = new Registry;
    $GLOBALS['styles'] = new Registry;
    $GLOBALS['logged'] = false;
    $GLOBALS['visitor'] = 'first';
    $_SERVER = [ 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET' ];
    $_GET = $_POST = $_COOKIE = $_SESSION = [];
    $profile = [ 'HTTP_ACCEPT_LANGUAGE' => 'en' ];
    $settings = [ 'ttl_seconds' => 3600, 'ttl_paths' => '/ 30' ];
    $testDir = sys_get_temp_dir() . '/ys-cache-template-test-' . bin2hex( random_bytes( 6 ) );
    mkdir( $testDir );
    // Use a real template file for the mtime component of the receipt.
    define( 'ELEMENTOR_PATH', $testDir . '/' );
    mkdir( $testDir . '/modules/page-templates/templates', 0700, true );
    $original = $testDir . '/modules/page-templates/templates/header-footer.php';
    touch( $original );
    try {
        $review = \Closure::bind( static fn( $html ) => Template::reviewBody( $html, '/' ), null, Template::class );
        $markup = '<article><div class="woocommerce-notices-wrapper"><div class="woocommerce-notices-wrapper"></div></div><h1>Catalog</h1></article>';
        check( $review( $markup ) === [], 'Public nested empty notices and headings must be allowed.' );
        check( $review( '<form class="cart" method="post" action="https://cache.test/"><input name="quantity"></form>' ) === [], 'Public native add-to-cart control.' );
        foreach ( [ '<script>secret()</script>', '<form class="wpforms-form"></form>', '<div data-user-token="123">a</div>',
            '<input type="hidden" name="_wpnonce">', '<button class="ct-wishlist-button-single" data-button-state="active"></button>',
            '<section class="woocommerce-notices-wrapper">An order failed</section>', '<section class="recently-viewed"></section>',
            '<form class="variations_form cart" method="POST"><select name="attribute_size"></select></form>',
            '<div data-widget_type="personalized.default"></div>' ] as $sensitive ) {
            check( $review( $sensitive ) === null, 'Private or unsupported fragment passed review.' );
        }
        foreach ( [ 'wp_woocommerce_session_abc', 'woocommerce_items_in_cart', 'wordpress_logged_in_abc',
            'trp_language', 'blocksy_cookies_consent', 'yith_wcwl_session', 'private_currency' ] as $name ) {
            check( ! Template::allowsAttributionCookie( $name, 'value' ), 'Shopper preferences must stay private.' );
        }
        check( Template::allowsAttributionCookie( 'sbjs_current', 'source' ) && Template::allowsAttributionCookie( '_ga_ABC123', 'example' ), 'Attribution cookies.' );
        check( Template::allowsAttributionCookie( 'pys_cd', 'cache.test' ) && ! Template::allowsAttributionCookie( 'pys_cd', 'test' ), 'Scope the Pixel domain signal.' );
        check( ! Template::allowsAttributionCookie( 'pys_event_referrer', 'https://user@example.org/' ), 'Foreign referrer is private.' );
        $static = \Closure::bind( static fn( $nodes ) => Template::staticHomeElements( $nodes ), null, Template::class );
        $widget = [ 'elType' => 'widget', 'widgetType' => 'jet-woo-products', 'settings' => [ 'products_query' => [ 'all' ] ] ];
        check( $static( [ $widget ] ), 'Catalog with explicit public query.' );
        foreach ( [ [ 'products_query' => [ 'viewed' ] ], [ 'query_id' => 'member-products' ],
            [ 'use_current_query' => 'yes' ], [ '__dynamic__' => [ 'x' => 'state' ] ], [ 'title' => '[private_products]' ] ] as $unsafe ) {
            $widget['settings'] = $unsafe;
            check( ! $static( [ $widget ] ), 'Dynamic widgets cannot qualify for history-bearing visitors.' );
        }
        $GLOBALS['kind'] = 'product';
        $_SERVER['HTTP_REFERER'] = home_url( '/catalog/' );
        $list = [ 'list_name' => 'Catalog', 'list_id' => 'catalog', 'url' => $_SERVER['HTTP_REFERER'] ];
        check( Template::allowsAttributionCookie( 'select_prod_list', addslashes( json_encode( $list ) ) ), 'Native escaped product-list metadata.' );
        $list['url'] = 'https://other.example/';
        check( ! Template::allowsAttributionCookie( 'select_prod_list', json_encode( $list ) ), 'A foreign product list is private.' );
        $GLOBALS['kind'] = 'page';
        unset( $_SERVER['HTTP_REFERER'] );
        check( Template::route( $original, $settings, '/', $profile, false ) === $original && Warmer::$scheduled === 1, 'A real first visitor schedules a neutral build.' );
        check( ! Template::hasEntry( $settings, '/', $profile ), 'No real user wrote a cache record.' );
        Warmer::$warm = true;
        Template::route( $original, $settings, '/', $profile, true );
        Template::onHeader( null, [] );
        ob_start();
        Template::afterTemplate( get_template_directory() . '/header.php' );
        echo $markup;
        Template::onFooter( null, [] );
        Template::afterTemplate( get_template_directory() . '/footer.php' );
        check( ob_get_clean() === $markup, 'Neutral document must remain intact.' );
        check( Template::hasEntry( $settings, '/', $profile ) && Store::$lastTtl === 30, 'Neutral region stored with per-page lifetime.' );
        Warmer::$warm = false;
        $_SERVER['HTTP_REFERER'] = home_url( '/catalog/' );
        $_COOKIE['pys_landing_page'] = 'private-attribution';
        foreach ( [ 'A', 'B' ] as $visitor ) {
            $GLOBALS['visitor'] = $visitor;
            $delegate = Template::route( $original, $settings, '/', $profile, false );
            check( $delegate === YS_CACHE_DIR . 'templates/guest-template.php', 'Second independent public visitor must hit.' );
            ob_start(); include $delegate; $doc = ob_get_clean();
            check( $doc === '<header>' . $visitor . '</header>' . $markup . '<footer>' . $visitor . '</footer>', 'Private native overlays on shared HTML.' );
        }
        check( ! str_contains( implode( '', Store::$rows ), 'private-attribution' ), 'A visitor identifier reached shared storage.' );
        $_COOKIE['woocommerce_recently_viewed'] = '11|22';
        check( Template::route( $original, $settings, '/', $profile, false ) === $original, 'An unreviewed homepage must stay native with browsing history.' );
        unset( $_COOKIE['woocommerce_recently_viewed'] );
        $_COOKIE['woocommerce_items_in_cart'] = '1';
        check( Template::route( $original, $settings, '/', $profile, false ) === $original, 'Guest with cart must keep native response.' );
        unset( $_COOKIE['woocommerce_items_in_cart'] );
        $_SERVER['HTTP_REFERER'] = 'https://cache.test:444/';
        check( Template::route( $original, $settings, '/', $profile, false ) === $original, 'Foreign port must bypass.' );
        unset( $_SERVER['HTTP_REFERER'] );
        $GLOBALS['logged'] = true;
        check( Template::route( $original, $settings, '/', $profile, false ) === $original, 'Signed-in visitor must bypass.' );
        $GLOBALS['logged'] = false;
        check( ! Template::hasEntry( $settings, '/', [ 'HTTP_ACCEPT_LANGUAGE' => 'fr' ] ), 'Variants do not cross.' );
        Generation::$generation[1] = 'new';
        check( ! Template::hasEntry( $settings, '/', $profile ), 'Single-page purge detaches old markup.' );
        check( ! Template::isPublicRoute( '/checkout/' ) && ! Template::isPublicRoute( '/cart/' ), 'Private route must bypass.' );
        echo "Native guest template checks passed.\n";
    } finally {
        unlink( $original ); rmdir( dirname( $original ) ); rmdir( dirname( $original, 2 ) ); rmdir( dirname( $original, 3 ) ); rmdir( $testDir );
    }
}
