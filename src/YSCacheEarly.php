<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** Owns the WordPress drop-in only for sites with explicitly selected public paths. */
final class YSCacheEarly {
    private const MARK = "<?php\n// YS CACHE guest reader v1";
    private static ?string $directory = null;

    public static function observeChanges(): void {
        foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
            add_action( $hook, static function ( string $option ): void {
                if ( preg_match( '/^(?:active_plugins$|template$|stylesheet$|home$|siteurl$|blog_public$|'
                    . 'pys_(?:core|facebook|ga|google_ads|consent|pbid_secret)$|xoo-el-|cpfence|'
                    . 'woocommerce_(?:coming_soon|store_pages_only|cart_page_id|checkout_page_id|myaccount_page_id)$|trp_settings$|ys_tp_settings$)/i', $option ) ) {
                    self::invalidate();
                }
            } );
        }
        add_action( 'admin_init', static function (): void {
            // Current tracking settings are written to the provider's own table.
            $action = $_POST['action'] ?? '';
            if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && current_user_can( 'manage_options' )
                && ( isset( $_POST['pys'] ) || ( is_string( $action ) && str_starts_with( $action, 'pys_save_' ) ) ) ) {
                add_action( 'shutdown', static function (): void { self::invalidate(); }, PHP_INT_MAX );
            }
        } );
        add_action( 'upgrader_process_complete', static function (): void { self::invalidate(); }, 10, 0 );
        add_action( 'post_updated', static function ( $id, $after, $before ): void {
            if ( ( $after->post_password !== $before->post_password || $after->post_status !== $before->post_status )
                && is_post_type_viewable( $after->post_type ) ) {
                self::invalidate();
            }
        }, 10, 3 );
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

    public static function sync( array $settings ): bool {
        $paths = self::paths( $settings['early_paths'] ?? '' );
        if ( ! $paths || ! YSCache::usesLocalHtml( $settings ) || YSCache::hasEdgeHtml( $settings ) ) {
            return self::remove();
        }
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE || is_multisite() || self::host() === '' || YSCache::blockingCache( $settings ) !== '' ) {
            return false;
        }
        $directory = self::directory();
        if ( $directory === '' || ! YSCacheEarlyStore::initialize( $directory ) ) {
            return false;
        }
        $pixel = ! empty( $settings['early_pixel_shell'] ) && in_array( '/', $paths, true );
        if ( $pixel && ! YSCachePixelTransport::provision( $directory ) ) {
            return false;
        }
        $file = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( is_link( $file ) || ( file_exists( $file ) && ! self::owned() && filesize( $file ) !== 0 ) ) {
            return false;
        }
        $reader = realpath( YS_CACHE_DIR . 'templates/early-reader.php' );
        if ( ! $reader ) {
            return false;
        }
        // A host can replace its generated security policy without a WordPress option update.
        $policy = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cpfence.php';
        $digest = is_file( $policy ) ? hash_file( 'sha256', $policy ) : '';
        if ( $digest === false ) {
            return false;
        }
        $restoreEmpty = is_file( $file ) && ( filesize( $file ) === 0 || str_contains( (string) file_get_contents( $file ), "// Restore empty drop-in\n" ) );
        $agents = preg_split( '/\R/', $settings['exclude_agents'] ?? '', -1, PREG_SPLIT_NO_EMPTY );
        $export = static fn( $value ) => var_export( $value, true );
        $code = self::MARK . "\n" . ( $restoreEmpty ? "// Restore empty drop-in\n" : '' )
            . "if ( ! defined( 'ABSPATH' ) || defined( 'MULTISITE' ) && MULTISITE ) return;\n"
            . '$ysReader = ' . $export( $reader ) . ";\n"
            . "if ( ! is_file( \$ysReader ) ) return;\n"
            . 'if ( ' . ( $pixel ? '! isset($_GET[\'ys_cache_pixel\']) && ! isset($_GET[\'ys_cache_pixel_fallback\']) && ' : '' )
            . '! in_array( $_SERVER[\'REQUEST_URI\'] ?? \'\', ' . $export( $paths ) . ", true ) ) return;\n"
            . '$ysPolicy = ' . $export( $policy ) . ";\n"
            . 'if ( (is_file($ysPolicy) ? @hash_file(\'sha256\', $ysPolicy) : \'\') !== ' . $export( $digest ) . " ) return;\n"
            . '$ysRead = require $ysReader;' . "\n"
            . 'if ( is_callable( $ysRead ) ) $ysRead(' . $export( $directory ) . ', ' . $export( self::host() ) . ', ' . $export( $agents ) . ', ' . $export( $pixel ) . ");\n";
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

    public static function remove(): bool {
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
        $directory = self::directory();
        if ( $directory !== '' && ( $path === null ? YSCacheEarlyStore::invalidateAll( $directory ) : YSCacheEarlyStore::invalidatePath( $directory, $path ) ) ) {
            return true;
        }
        return self::remove();
    }
}
