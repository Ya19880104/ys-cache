<?php

/** Runs without a WordPress database. Pass a WordPress installation to use its HTML tag parser. */

namespace YangSheep\Cache {
    final class YSCache {
        public static function settings(): array {
            return [ 'assets' => [ 'css_minify' => true, 'js_minify' => true, 'js_defer' => true,
                'css_excludes' => "/wp-content/themes/example/styles/skip.css", 'js_excludes' => 'excluded' ] ];
        }
    }

    final class YSCacheIntegrations {
        public static function bypass(): bool { return false; }
        public static function cookiePrefixes(): array { return [ 'wordpress_logged_in_', 'ys_ec_session' ]; }
    }
}

namespace {
    use YangSheep\Cache\YSCacheAssetOptimization as Optimizer;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }
    $core = rtrim( $argv[1] ?? '', "/\\" ) . '/wp-includes/';
    if ( ! is_file( $core . 'html-api/class-wp-html-tag-processor.php' ) ) {
        fwrite( STDERR, "Usage: php tests/asset-optimization.php /path/to/wordpress [--private]\n" );
        exit( 1 );
    }
    $site = sys_get_temp_dir() . '/ys-cache-assets-test-' . bin2hex( random_bytes( 6 ) );
    define( 'ABSPATH', $site . '/' );
    define( 'WP_CONTENT_DIR', $site . '/wp-content' );
    define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
    define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
    define( 'WPINC', 'wp-includes' );
    define( 'YS_CACHE_VERSION', 'test' );
    define( 'YS_CACHE_DIR', dirname( __DIR__ ) . '/' );
    define( 'PRIVATE_REQUEST', ( $argv[2] ?? '' ) === '--private' );

    foreach ( [ 'class-wp-token-map.php', 'utf8.php', 'html-api/html5-named-character-references.php',
        'html-api/class-wp-html-span.php', 'html-api/class-wp-html-attribute-token.php',
        'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-doctype-info.php',
        'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php' ] as $file ) {
        if ( is_file( $core . $file ) ) {
            require_once $core . $file;
        }
    }
    require YS_CACHE_DIR . 'src/YSCacheAssetOptimization.php';

    function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_url( $value ) { return esc_attr( $value ); }
    function wp_kses_uri_attributes() { return [ 'href', 'src' ]; }
    function wp_convert_hr_to_bytes( $value ) { return -1; }
    function add_action( $name, $callback, $priority ) { $GLOBALS['hooks'][ $name ] = $callback; }
    function add_filter( $name, $callback, $priority, $count ) { $GLOBALS['hooks'][ $name ] = $callback; }
    function is_admin() { return false; }
    function wp_doing_ajax() { return false; }
    function is_user_logged_in() { return false; }
    function is_feed() { return false; }
    function is_preview() { return false; }
    function is_search() { return false; }
    function is_404() { return false; }
    function is_customize_preview() { return false; }
    function is_singular() { return false; }
    function home_url( $path ) { return 'https://example.test' . $path; }
    function plugins_url( $path ) { return home_url( '/wp-content/plugins' . $path ); }
    function content_url( $path ) { return home_url( '/wp-content' . $path ); }
    function includes_url() { return home_url( '/wp-includes/' ); }
    function wp_get_upload_dir() { return [ 'baseurl' => content_url( '/uploads' ), 'basedir' => WP_CONTENT_DIR . '/uploads' ]; }
    function wp_parse_url( $url ) { return parse_url( $url ); }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function wp_scripts() { return $GLOBALS['scripts']; }
    function wp_script_add_data( $handle, $name, $value ) { $GLOBALS['strategy'][ $handle ] = [ $name, $value ]; }

    function verify( bool $ok, string $message ): void {
        if ( ! $ok ) {
            throw new RuntimeException( $message );
        }
    }

    function removeTestFiles( string $directory ): void {
        foreach ( new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS ) as $entry ) {
            if ( $entry->isDir() && ! $entry->isLink() ) {
                removeTestFiles( $entry->getPathname() );
            } else {
                unlink( $entry->getPathname() );
            }
        }
        rmdir( $directory );
    }

    $styles = WP_CONTENT_DIR . '/themes/example/styles';
    $scripts = WP_CONTENT_DIR . '/themes/example/scripts';
    mkdir( $styles, 0755, true );
    mkdir( $scripts, 0755, true );
    mkdir( WP_CONTENT_DIR . '/uploads' );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_COOKIE = PRIVATE_REQUEST ? [ 'ys_ec_session_guest' => 'fixture' ] : [];
    try {
        $defaults = Optimizer::sanitize( [] );
        verify( ! $defaults['css_minify'] && ! $defaults['js_minify'] && ! $defaults['js_defer'], 'Optimizer is off by default.' );
        verify( Optimizer::sanitize( [ 'js_excludes' => "one\r\n/wp-content/test*.js\none\n<script>" ] )['js_excludes'] ===
            "one\n/wp-content/test*.js", 'Rules normalize literal handles and paths.' );
        Optimizer::register();
        verify( count( $GLOBALS['hooks'] ) === 4, 'Normal head/footer and tag filters are registered.' );

        $css = "/*! Copyright Fixture */\n@import 'partials/other.css' screen;\n"
            . str_repeat( "\n  ", 60 ) . '.item :hover { width: calc(100% - 1rem); '
            . "background: url(../images/bg.png); content: 'url(leave-me.png)'; }\n";
        file_put_contents( $styles . '/raw.css', $css );
        file_put_contents( $styles . '/skip.css', $css );
        file_put_contents( $styles . '/complex.css', $css . '.other {background: image-set("foo.png" 1x)}' );
        $styleUrl = 'https://example.test/wp-content/themes/example/styles/raw.css?ver=1.2';
        $style = '<link rel="stylesheet" href="' . $styleUrl . '">';
        $optimizedStyle = Optimizer::minifyStyle( $style, 'example-style', $styleUrl );
        if ( PRIVATE_REQUEST ) {
            verify( $optimizedStyle === $style && ! is_dir( WP_CONTENT_DIR . '/uploads/ys-cache-assets' ), 'Stateful visitors keep original assets.' );
            echo "Stateful asset request checks passed.\n";
            return;
        }
        preg_match( '~/ys-cache-assets/([a-f0-9]+\.css)~', $optimizedStyle, $output );
        verify( ! empty( $output[1] ), 'Eligible CSS is emitted at its immutable asset URL.' );
        $directory = WP_CONTENT_DIR . '/uploads/ys-cache-assets';
        $target = $directory . '/' . $output[1];
        $minified = file_get_contents( $target );
        verify( strlen( $minified ) < strlen( $css ) && str_contains( $minified, '.item :hover' )
            && str_contains( $minified, 'calc(100% - 1rem)' ), 'CSS shrinks without changing selectors or calculated values.' );
        verify( str_contains( $minified, '@import "https://example.test/wp-content/themes/example/styles/partials/other.css"' ) &&
            str_contains( $minified, 'url("https://example.test/wp-content/themes/example/styles/../images/bg.png")' ) &&
            str_contains( $minified, "content: 'url(leave-me.png)'" ) && str_contains( $minified, 'Copyright Fixture' ),
            'CSS imports, relative images, quoted literals, and copyright survive relocation.' );
        $reuse = new ReflectionMethod( Optimizer::class, 'reuse' );
        if ( PHP_VERSION_ID < 80100 ) {
            $reuse->setAccessible( true );
        }
        touch( $target, time() - 13 * 86400 );
        clearstatcache( true, $target );
        verify( $reuse->invoke( null, $directory, $target ), 'Old live assets can be reused.' );
        clearstatcache( true, $target );
        verify( time() - filemtime( $target ) < 10, 'Live reuse refreshes retention before a later prune.' );

        foreach ( [ 'skip.css', 'complex.css', 'raw.css?token=private', 'raw.css?ver=a%26token%3Dprivate' ] as $path ) {
            $url = 'https://example.test/wp-content/themes/example/styles/' . $path;
            $original = '<link rel="stylesheet" href="' . $url . '">';
            verify( Optimizer::minifyStyle( $original, 'example-style', $url ) === $original, 'Unsafe or excluded stylesheet must remain original.' );
        }
        $integrity = '<link rel="stylesheet" integrity="sha256-example" href="' . $styleUrl . '">';
        verify( Optimizer::minifyStyle( $integrity, 'example-style', $styleUrl ) === $integrity, 'Integrity-checked resource is preserved.' );

        $js = "/*! Copyright Fixture */\n" . str_repeat( "\n  ", 50 ) . "if (true) { window.ysResult = /x + y/.test('x   y'); }\n";
        file_put_contents( $scripts . '/raw.js', $js );
        $src = '/wp-content/themes/example/scripts/raw.js';
        $tag = '<script>window.inlineConfig = { token: "fixture" };</script><script src="' . $src . '"></script>';
        $optimized = Optimizer::minifyScript( $tag, 'example-script', $src );
        if ( extension_loaded( 'mbstring' ) ) {
            preg_match( '~/ys-cache-assets/([a-f0-9]+\.js)~', $optimized, $output );
            verify( ! empty( $output[1] ), 'Eligible JS is emitted at its immutable asset URL.' );
            $minified = file_get_contents( $directory . '/' . $output[1] );
            verify( str_contains( $minified, '/x + y/' ) && str_contains( $minified, 'Copyright Fixture' ) &&
                ! str_contains( $minified, 'inlineConfig' ) && str_contains( $optimized, 'inlineConfig' ),
                'JavaScript semantics and copyright are preserved, without copying inline settings.' );
        }
        $GLOBALS['scripts'] = new class( $src ) {
            public array $queue = [ 'example-script', 'excluded', 'jquery-core', 'with-inline', 'preconfigured' ];
            public array $registered = [];
            public array $done = [];
            public function __construct( $src ) {
                foreach ( $this->queue as $handle ) {
                    $this->registered[ $handle ] = (object) [ 'src' => $src ];
                }
            }
            public function get_data( $handle, $key ) {
                return ( $handle === 'with-inline' && $key === 'after' ) || ( $handle === 'preconfigured' && $key === 'strategy' );
            }
        };
        Optimizer::deferScripts();
        verify( $GLOBALS['strategy'] === [ 'example-script' => [ 'strategy', 'defer' ] ], 'Defer leaves critical, excluded, inline, and already configured scripts alone.' );
        echo "Asset minification, retention, and deferred enqueue checks passed.\n";
    } finally {
        removeTestFiles( $site );
    }
}
