<?php

namespace YangSheep\Cache {
    final class YSCache {
        public static function usesLocalHtml( array $settings ): bool {
            return $settings['enabled'];
        }

        public static function hasEdgeHtml( array $settings ): bool {
            return false;
        }

        public static function blockingCache( array $settings ): string {
            return '';
        }

        public static function pathLifetime( int $lifetime, string $rules, string $path ): int {
            return $lifetime;
        }
    }
}

namespace {
    use YangSheep\Cache\YSCacheEarly as Early;
    use YangSheep\Cache\YSCacheEarlyStore as Store;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    function home_url( string $path ): string {
        return 'https://store.example.test' . $path;
    }

    function is_multisite(): bool {
        return false;
    }

    function wp_parse_url( string $url ): array {
        return parse_url( $url );
    }

    function wp_normalize_path( string $path ): string {
        return str_replace( '\\', '/', $path );
    }

    function get_current_blog_id(): int {
        return 1;
    }

    function wp_salt( string $scope ): string {
        return 'early-lifecycle-fixture-' . $scope;
    }

    function apply_filters( string $hook, $value, ...$args ) {
        return $value;
    }

    function get_option( string $name, $default = false ) {
        return $default;
    }

    function expect_same( $expected, $actual, string $message ): void {
        if ( $expected !== $actual ) {
            throw new RuntimeException( $message );
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-lifecycle-test-' . bin2hex( random_bytes( 8 ) );
    define( 'ABSPATH', $root . '/public/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    define( 'YS_CACHE_STORAGE_ROOT', $root . '/storage' );
    define( 'YS_CACHE_DIR', dirname( __DIR__ ) . '/' );
    define( 'WP_CACHE', true );
    $_SERVER['DOCUMENT_ROOT'] = ABSPATH;
    if ( ! mkdir( WP_CONTENT_DIR, 0700, true ) || ! mkdir( YS_CACHE_STORAGE_ROOT, 0700 ) ) {
        throw new RuntimeException( 'Could not set up isolated WordPress fixture directories.' );
    }

    try {
        require YS_CACHE_DIR . 'src/YSCacheStorage.php';
        require YS_CACHE_DIR . 'src/YSCacheEarlyStore.php';
        require YS_CACHE_DIR . 'src/YSCacheEarlyRequest.php';
        require YS_CACHE_DIR . 'src/YSCacheEarly.php';
        define( 'DAY_IN_SECONDS', 86400 );

        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        $settings = [ 'early' => true, 'enabled' => true, 'exclude_agents' => "ExampleBot
", 'exclude_cookies' => "private_session_*
", 'ignore_params' => "campaign_ref
", 'ttl_seconds' => 36000, 'ttl_paths' => '' ];
        $path = '/guest/';
        $host = Early::host();
        $variant = 'd';
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $previous = '<!doctype html><html><body>Before the content edit</body></html>';
        $current = '<!doctype html><html><body>After the content edit</body></html>';

        expect_same( 0, file_put_contents( $dropin, '' ), 'Create an existing empty host drop-in' );
        expect_same( true, Early::sync( $settings ), 'Attach the early reader' );
        expect_same( true, Early::owned(), 'Identify the installed reader as ours' );
        $directory = Early::directory();
        $old = Store::snapshot( $directory, $path );
        expect_same( true, is_array( $old ), 'Read the attached generation' );
        expect_same( true, Store::write( $directory, $host, $path, $old, $headers, $previous, 300, $variant ), 'Seed a valid fresh response' );
        expect_same( $previous, gzdecode( Store::read( $directory, $host, $path, $variant )['body'] ?? '' ), 'The original response is eligible while attached' );
        $code = file_get_contents( $dropin );
        expect_same( false, str_contains( $code, 'REQUEST_URI' ), 'The drop-in no longer limits early serving to a path list' );
        expect_same( true, str_contains( $code, "'private_session_'" ) && str_contains( $code, "'wordpress_logged_in_'" ) && str_contains( $code, "'woocommerce_items_in_cart'" ), 'The drop-in denies configured, login and cart cookies' );
        expect_same( true, str_contains( $code, "'campaign_ref'" ) && str_contains( $code, "'ExampleBot'" ), 'The drop-in carries extra ignored parameters and excluded agents' );
        expect_same( false, str_contains( $code, "'woocommerce_items_in_cart'" ) && Early::sync( array_replace( $settings, [ 'serve_cart_visitors' => true ] ) ) && str_contains( (string) file_get_contents( $dropin ), "'woocommerce_items_in_cart'" ), 'Serving cart visitors removes the cart cookies from the deny list' );
        expect_same( true, Early::sync( $settings ), 'Restore the default cart policy' );
        expect_same( 36000, Early::ttl( $settings, $path ), 'Keep the configured 10-hour lifetime' );
        expect_same( 42900, Early::ttl( array_replace( $settings, [ 'ttl_seconds' => 604800 ] ), $path ), 'Cap lifetimes inside the nonce window' );
        expect_same( true, Early::sync( $settings ), 'Repeated sync on the same installed gate is successful' );
        expect_same( $old, Store::snapshot( $directory, $path ), 'Do not invalidate when an identical gate is already attached' );

        expect_same( true, Early::sync( array_replace( $settings, [ 'enabled' => false ] ) ), 'Detach on disabling local HTML' );
        expect_same( false, Early::owned(), 'Clear ownership while detached' );
        expect_same( '', file_get_contents( $dropin ), 'Restore the previous empty host drop-in' );
        expect_same( true, Early::invalidate( $path ), 'Accept a content-change signal while no early gate is attached' );
        expect_same( false, Early::owned(), 'An invalidation never reattaches the gate' );

        expect_same( true, Early::sync( $settings ), 'Reattach the reader' );
        $fresh = Store::snapshot( $directory, $path );
        expect_same( true, is_array( $fresh ) && $old !== $fresh, 'Reattachment must retire the previous generation' );
        expect_same( null, Store::read( $directory, $host, $path, $variant ), 'Do not serve the pre-detachment cached response' );
        expect_same( false, Store::write( $directory, $host, $path, $old, $headers, $previous, 300, $variant ), 'An in-flight response cannot publish under the retired snapshot' );
        expect_same( true, Store::write( $directory, $host, $path, $fresh, $headers, $current, 300, $variant ), 'The reattached reader can cache new content' );
        expect_same( $current, gzdecode( Store::read( $directory, $host, $path, $variant )['body'] ?? '' ), 'The new generation serves only the fresh content' );

        expect_same( true, Early::sync( array_replace( $settings, [ 'early' => false ] ) ), 'Turning the early setting off detaches' );
        expect_same( false, Early::owned(), 'No owned reader remains when early serving is off' );
        $thirdParty = "<?php\n// Another plugin owns this fixture.\n";
        expect_same( strlen( $thirdParty ), file_put_contents( $dropin, $thirdParty ), 'Create the third-party fixture' );
        expect_same( false, Early::sync( $settings ), 'Leave an independently owned drop-in installed' );
        expect_same( true, Early::remove(), 'Do not remove independently owned drop-ins on disable' );
        expect_same( $thirdParty, file_get_contents( $dropin ), 'Third-party drop-in bytes stay intact' );

        echo "Early reader attachment lifecycle checks passed.\n";
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
