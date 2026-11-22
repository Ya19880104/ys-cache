<?php
/** Run php tests/delegated-html.php to check real PHP output buffers and response headers locally. */

if ( PHP_SAPI === 'cli-server' && getenv( 'YS_CACHE_TEST_STORAGE' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    define( 'YS_CACHE_DIR', ABSPATH );
    define( 'YS_CACHE_VERSION', 'test' );
    define( 'YS_CACHE_STORAGE_ROOT', getenv( 'YS_CACHE_TEST_STORAGE' ) );
    define( 'MINUTE_IN_SECONDS', 60 );
    define( 'DAY_IN_SECONDS', 86400 );

    function is_admin() { return false; }
    function is_ssl() { return false; }
    function is_user_logged_in() { return false; }
    function is_404() { return false; }
    function is_search() { return false; }
    function is_feed() { return false; }
    function is_preview() { return false; }
    function is_trackback() { return false; }
    function is_embed() { return false; }
    function is_customize_preview() { return false; }
    function is_singular() { return false; }
    function add_action( ...$args ) {}
    function add_filter( ...$args ) {}
    function apply_filters( $name, $value ) {
        if ( $name === 'nonce_life' ) {
            return 2 * ( time() + ( $_SERVER['REQUEST_URI'] === '/nonce-expiring/' ? 2 : 3600 ) );
        }
        return $value;
    }
    function get_option( $name, $default = false ) { return $default; }
    function get_site_option( $name, $default = false ) { return $default; }
    function wp_parse_url( $url ) { return parse_url( $url ); }
    function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
    function wp_salt( $scope ) { return 'local-test'; }
    function get_current_blog_id() { return 1; }
    function determine_locale() { return 'en_US'; }
    function get_queried_object() { return null; }
    function wp_nonce_tick( $action = -1 ) { return 1234; }
    function home_url() { return 'http://' . $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']; }
    function wp_get_upload_dir() { return [ 'baseurl' => home_url() . '/wp-content/uploads' ]; }
    function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function wp_check_invalid_utf8( $value ) { return $value; }
    function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
    function __( $message, $domain = '' ) { return $message; }

    $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    $wpdb = new class( $path ) {
        public string $prefix = 'wp_';
        private string $path;
        public function __construct( string $path ) { $this->path = $path; }
        private function database(): array {
            $file = YS_CACHE_STORAGE_ROOT . '/generations.json';
            return is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : [ 'generation' => '1' ];
        }
        public function get_var( string $query ): string {
            if ( str_contains( $query, "setting_name = 'generation'" ) ) {
                return $this->database()['generation'];
            }
            $edge = str_starts_with( $this->path, '/edge' );
            $local = $edge || str_starts_with( $this->path, '/local' ) || str_ends_with( $this->path, '-purge/' );
            return json_encode( [ 'enabled' => true, 'ttl' => 3, 'auto_purge' => $this->path !== '/auto-off-purge/', 'exclude_paths' => '/protected/', 'providers' => [
                'enhance' => [ 'enabled' => ! $local, 'delegate_html' => ! $local, 'rules_verified' => $this->path !== '/unverified/' ],
                'opcache' => [ 'enabled' => false ],
                'cdn' => $edge ? [[ 'provider' => 'cloudflare', 'enabled' => true, 'scope' => $this->path === '/edge-static/' ? 'static' : 'html',
                    'html_cache_verified' => $this->path !== '/edge-unverified/' ]] : [],
            ] ] );
        }
        public function prepare( string $query, ...$values ): string {
            return vsprintf( str_replace( '%s', "'%s'", $query ), $values );
        }
        public function get_results( string $query ): array {
            preg_match_all( "/'(generation|page:[0-9a-f]+)'/", $query, $matches );
            $rows = [];
            foreach ( array_intersect_key( $this->database(), array_flip( $matches[1] ) ) as $name => $value ) {
                $rows[] = (object) [ 'setting_name' => $name, 'setting_value' => $value ];
            }
            return $rows;
        }
        public function replace( string $table, array $row ): int {
            $data = $this->database();
            $data[$row['setting_name']] = $row['setting_value'];
            return (int) file_put_contents( YS_CACHE_STORAGE_ROOT . '/generations.json', json_encode( $data ) );
        }
        public function query( string $query ): int { return 0; }
    };

    foreach ( [ 'Admin/YSCacheAdminBar', 'Database/YSCacheSettingsRepo', 'YSCache', 'YSCacheAssetOptimization', 'YSCacheEnhancePurge', 'YSCacheIntegrations', 'YSCacheInvalidation', 'YSCacheProviders', 'YSCacheStorage' ] as $class ) {
        require ABSPATH . 'src/' . $class . '.php';
    }
    $cache = YangSheep\Cache\YSCache::instance();
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && str_ends_with( $path, '-purge/' ) ) {
        header( 'Content-Type: application/json' );
        if ( $path === '/settings-roundtrip-purge/' ) {
            $saved = $cache::save( [ 'ttl_seconds' => 61, 'ttl_unit' => 'min', 'auto_purge' => false ] );
            echo json_encode( [ 'saved' => $saved ] + array_intersect_key( $cache::settings(), array_flip( [ 'ttl_seconds', 'ttl_unit', 'auto_purge' ] ) ) );
            return;
        }
        if ( str_starts_with( $path, '/auto-' ) ) {
            $cache->invalidateOnChange();
            echo json_encode( [ 'generation' => YangSheep\Cache\Database\YSCacheSettingsRepo::generation() ] );
            return;
        }
        $results = $path === '/site-purge/' ? $cache::purge( 'all' ) : $cache::purgePage( home_url() . '/local' );
        echo json_encode( $results );
        return;
    }
    $cache->holdExternalHtml();
    $cache->captureGeneration();
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( $path === '/nonce-header/' ? 'Content-Security-Policy: script-src \'nonce-fixture\'' : 'Content-Security-Policy: upgrade-insecure-requests' );
    header( 'Strict-Transport-Security: max-age=63072000' );
    header( 'Permissions-Policy: camera=()' );
    header( 'Cross-Origin-Embedder-Policy: unsafe-none' );
    header( 'Cross-Origin-Opener-Policy: unsafe-none' );
    header( 'Cross-Origin-Resource-Policy: cross-origin' );
    if ( $path === '/already-private/' ) {
        header( 'Cache-Control: private' );
    }
    $cache->protectPrivateResponse();
    if ( $cache->serveOrCapture( 'test-template' ) !== 'test-template' ) {
        $cache->renderCachedPage();
        return;
    }
    if ( $path === '/late-cookie/' ) {
        header( 'Set-Cookie: fixture=private; HttpOnly' );
    }
    if ( $path === '/guest-token/' ) {
        echo '<!doctype html><html><script>example = {"guestToken":"fixture"};</script></html>';
    } else {
        echo '<!doctype html><html><p>fixture</p></html>';
    }
    return;
}

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

function check( $actual, $expected, $label ) {
    if ( $actual !== $expected ) {
        throw new RuntimeException( $label . ': ' . var_export( [ $actual, $expected ], true ) );
    }
}

function request( string $address, string $path, array $headers = [], string $method = 'GET' ): array {
    $context = stream_context_create( [ 'http' => [ 'method' => $method, 'header' => $headers, 'timeout' => 5 ] ] );
    $body = @file_get_contents( 'http://' . $address . $path, false, $context );
    $received = [];
    $lines = function_exists( 'http_get_last_response_headers' ) ? http_get_last_response_headers() : ( $http_response_header ?? [] );
    foreach ( $lines ?? [] as $line ) {
        $header = explode( ':', $line, 2 );
        if ( count( $header ) === 2 ) {
            $received[ strtolower( $header[0] ) ] = trim( $header[1] );
        }
    }
    return [ $body, $received ];
}

$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
if ( ! $socket ) {
    throw new RuntimeException( 'Unable to reserve a local port.' );
}
$address = stream_socket_get_name( $socket, false );
fclose( $socket );
$root = sys_get_temp_dir() . '/ys-cache-delegated-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
$log = $root . '/server.log';
$environment = array_merge( getenv(), [ 'YS_CACHE_TEST_STORAGE' => $root ] );
$process = proc_open( [ PHP_BINARY, '-S', $address, __FILE__ ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $log, 'a' ], 2 => [ 'file', $log, 'a' ] ], $pipes, dirname( __DIR__ ), $environment );

try {
    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Unable to start PHP for native headers.' );
    }
    for ( $i = 0; $i < 50; $i++ ) {
        $ready = @stream_socket_client( 'tcp://' . $address, $code, $error, 0.1 );
        if ( $ready ) {
            fclose( $ready );
            break;
        }
        usleep( 20000 );
    }

    $before = time();
    [ $body, $headers ] = request( $address, '/guest/' );
    check( $body, '<!doctype html><html><p>fixture</p></html>', 'Successful public guest render' );
    check( $headers['x-ys-cache'] ?? '', 'DELEGATED', 'Only safe guest HTML is delegated' );
    $expiry = $headers['x-accel-expires'] ?? '';
    check( preg_match( '/\A@(\d+)\z/', $expiry, $expires ) === 1 && (int) $expires[1] >= $before + 180
        && (int) $expires[1] <= time() + 180, true, 'FastCGI gets the configured absolute TTL after full render' );
    check( $headers['content-security-policy'] ?? '', 'upgrade-insecure-requests', 'CSP remains' );
    check( $headers['strict-transport-security'] ?? '', 'max-age=63072000', 'HSTS remains' );
    check( $headers['permissions-policy'] ?? '', 'camera=()', 'Permissions Policy remains' );
    check( $headers['cross-origin-embedder-policy'] ?? '', 'unsafe-none', 'COEP remains' );
    check( $headers['cross-origin-opener-policy'] ?? '', 'unsafe-none', 'COOP remains' );
    check( $headers['cross-origin-resource-policy'] ?? '', 'cross-origin', 'CORP remains' );

    foreach ( [
        [ '/guest/', [ 'Cookie: wordpress_logged_in_mock=fixture' ] ],
        [ '/guest/', [ 'Cookie: wp_woocommerce_session_mock=fixture' ] ],
        [ '/guest/', [ 'Authorization: Bearer local-fixture' ] ],
        [ '/guest/?test=1', [] ], [ '/guest/', [], 'HEAD' ],
        [ '/protected/', [] ], [ '/nonce-header/', [] ], [ '/already-private/', [] ],
        [ '/late-cookie/', [] ], [ '/guest-token/', [] ], [ '/unverified/', [] ], [ '/nonce-expiring/', [] ],
    ] as $case ) {
        [ $path, $sent ] = $case;
        [ , $headers ] = request( $address, $path, $sent, $case[2] ?? 'GET' );
        check( $headers['x-ys-cache'] ?? '', 'BYPASS', 'WordPress keeps the response private for ' . $path );
        check( $headers['x-accel-expires'] ?? '', '0', 'Do not seed FastCGI cache for ' . $path );
        check( str_contains( $headers['cache-control'] ?? '', 'no-store' ), true, 'Do not seed CDN for ' . $path );
    }
    check( glob( $root . '/ys-cache-*' ), [], 'Delegated mode did not even create a local cache directory' );
    [ , $headers ] = request( $address, '/local/' );
    check( $headers['x-ys-cache'] ?? '', 'MISS', 'Local cache is available after switching back' );
    check( count( glob( $root . '/ys-cache-*/*/*.html' ) ), 1, 'Local cache writes a file in local mode' );
    [ , $headers ] = request( $address, '/local/' );
    check( $headers['x-ys-cache'] ?? '', 'HIT', 'Local cache can serve a hit after switching back' );
    request( $address, '/local-other/' );
    [ $results ] = request( $address, '/single-purge/', [], 'POST' );
    check( json_decode( $results, true )[0]['status'] ?? '', 'ok', 'Single-page purge succeeded' );
    [ , $headers ] = request( $address, '/local/' );
    check( $headers['x-ys-cache'] ?? '', 'MISS', 'Page purge invalidates the slash variant of the target' );
    [ , $headers ] = request( $address, '/local-other/' );
    check( $headers['x-ys-cache'] ?? '', 'HIT', 'Page purge preserves the unrelated warm page' );
    [ $offGeneration ] = request( $address, '/auto-off-purge/', [], 'POST' );
    check( json_decode( $offGeneration, true )['generation'] ?? '', '1', 'Master off preserves local files on content edits' );
    [ , $headers ] = request( $address, '/local-other/' );
    check( $headers['x-ys-cache'] ?? '', 'HIT', 'A content edit leaves HTML intact when automatic purge is off' );
    [ $onGeneration ] = request( $address, '/auto-on-purge/', [], 'POST' );
    check( json_decode( $onGeneration, true )['generation'] !== '1', true, 'Master on invalidates local HTML on content edits' );
    [ , $headers ] = request( $address, '/local-other/' );
    check( $headers['x-ys-cache'] ?? '', 'MISS', 'Content edits invalidate HTML when automatic purge is on' );
    [ $results ] = request( $address, '/site-purge/', [], 'POST' );
    check( json_decode( $results, true )[0]['status'] ?? '', 'ok', 'Whole-site purge succeeded' );
    foreach ( [ '/local/', '/local-other/' ] as $path ) {
        [ , $headers ] = request( $address, $path );
        check( $headers['x-ys-cache'] ?? '', 'MISS', 'Whole-site purge invalidates ' . $path );
    }

    foreach ( [ 'MISS', 'HIT' ] as $expected ) {
        [ , $headers ] = request( $address, '/edge/' );
        check( $headers['x-ys-cache'] ?? '', $expected, 'Confirmed HTML CDN scope preserves local cache ' . $expected );
        check( preg_match( '/^public, max-age=0, s-maxage=\d+, must-revalidate$/', $headers['cache-control'] ?? '' ), 1, 'Eligible HTML can enter the CDN cache' );
        check( $headers['x-accel-expires'] ?? '', '0', 'FastCGI remains bypassed when cache storage is local' );
    }
    foreach ( [ [ '/edge/', [ 'Cookie: wordpress_logged_in_mock=fixture' ] ], [ '/edge-static/', [] ], [ '/edge-unverified/', [] ] ] as [ $path, $sent ] ) {
        [ , $headers ] = request( $address, $path, $sent );
        check( str_contains( $headers['cache-control'] ?? '', 'no-store' ), true, 'Ineligible edge HTML remains private for ' . $path );
    }
    [ $roundtrip ] = request( $address, '/settings-roundtrip-purge/', [], 'POST' );
    check( json_decode( $roundtrip, true ), [ 'saved' => true, 'ttl_seconds' => 61, 'ttl_unit' => 'min', 'auto_purge' => false ], 'Store an exact lifetime, display unit, and master auto purge choice' );
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" . (string) @file_get_contents( $log ) );
    $failure = true;
} finally {
    if ( is_resource( $process ) ) {
        fclose( $pipes[0] );
        proc_terminate( $process );
        proc_close( $process );
    }
    @unlink( $log );
    @unlink( $root . '/generations.json' );
    foreach ( glob( $root . '/ys-cache-*/*', GLOB_ONLYDIR ) as $bucket ) {
        foreach ( glob( $bucket . '/*.html' ) as $file ) {
            unlink( $file );
        }
        @unlink( $bucket . '/.lock' );
        rmdir( $bucket );
    }
    foreach ( glob( $root . '/ys-cache-*', GLOB_ONLYDIR ) as $directory ) {
        rmdir( $directory );
    }
    rmdir( $root );
}

if ( ! empty( $failure ) ) {
    exit( 1 );
}
echo "Delegated HTML native header and disk checks passed.\n";
