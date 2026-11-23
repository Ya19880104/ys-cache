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
        require YS_CACHE_DIR . 'src/YSCacheEarly.php';

        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        $settings = [ 'early_paths' => '/guest/', 'enabled' => true, 'early_pixel_shell' => false, 'exclude_agents' => '' ];
        $path = '/guest/';
        $host = Early::host();
        $variant = Store::requestVariant( [ 'HTTP_USER_AGENT' => 'Lifecycle test browser' ] );
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
        expect_same( $previous, Store::read( $directory, $host, $path, $variant )['html'] ?? null, 'The original response is eligible while attached' );
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
        expect_same( $current, Store::read( $directory, $host, $path, $variant )['html'] ?? null, 'The new generation serves only the fresh content' );

        expect_same( true, Early::remove(), 'Detach again before replacing the host fixture' );
        $templateOnly = array_replace( $settings, [ 'early_paths' => '', 'guest_template' => true ] );
        expect_same( true, Early::sync( $templateOnly ), 'Template-only mode can provision protected warm storage' );
        expect_same( true, Early::owned(), 'Reader with an empty allowlist cannot serve complete HTML' );
        expect_same( true, Early::sync( array_replace( $templateOnly, [ 'guest_template' => false ] ) ), 'Disabling the last reader mode detaches' );
        expect_same( false, Early::owned(), 'Empty route list with both modes disabled leaves no owned reader' );
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
