<?php

namespace YangSheep\Cache\Database {
    final class YSCacheSettingsRepo {
        public static array $generation = [ 'initial', 'home' ];
        public static function pageGenerations( string $path ): array {
            return self::$generation;
        }
    }
}

namespace PixelYourSite {
    function PYS(): void {}
}

namespace YangSheep\Cache {
    final class YSCacheGuestTemplate {
        public static bool $stored = false;
        public static function isPublicRoute( string $path ): bool {
            return in_array( $path, [ '/shop/category/', '/shop/other/' ], true );
        }
        public static function hasEntry( array $settings, string $path, array $profile ): bool {
            return self::$stored;
        }
    }
    final class YSCache {
        public static function settings( bool $fresh = false ): array { return $GLOBALS['settings']; }
        public static function usesLocalHtml( array $settings ): bool { return ! empty( $settings['local'] ); }
        public static function blockingCache( array $settings ): string { return $settings['blocking'] ?? ''; }
    }
    final class YSCacheEarly {
        public static function host(): string { return $GLOBALS['host']; }
        public static function paths( string $paths ): array { return explode( "\n", $paths ); }
        public static function directory(): string { return $GLOBALS['directory']; }
        public static function owned(): bool { return $GLOBALS['owned']; }
    }
}

namespace {
    use YangSheep\Cache\Database\YSCacheSettingsRepo;
    use YangSheep\Cache\YSCacheEarlyStore;
    use YangSheep\Cache\YSCachePixelWarm;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    function expect( $expected, $actual, string $label ): void {
        if ( $expected !== $actual ) {
            throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
        }
    }

    function home_url( string $path ): string { return $GLOBALS['home'] . $path; }
    function is_front_page(): bool { return true; }
    function is_singular(): bool { return true; }
    function is_preview(): bool { return false; }
    function post_password_required(): bool { return false; }
    function is_user_logged_in(): bool { return $GLOBALS['logged_in']; }
    function is_multisite(): bool { return false; }
    function wp_is_mobile(): bool { return ( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '' ) === '?1'; }
    function wp_salt( string $scheme ): string { return 'fixture-signing-key:' . $scheme; }
    function wp_doing_cron(): bool { return $GLOBALS['cron']; }
    function fastcgi_finish_request(): bool {
        ++$GLOBALS['finish_calls'];
        return $GLOBALS['finish_successful'];
    }
    function wp_next_scheduled( string $hook ) { return $GLOBALS['events'][ $hook ] ?? false; }
    function wp_schedule_single_event( int $time, string $hook ): bool {
        $GLOBALS['events'][ $hook ] = $time;
        return true;
    }
    function wp_clear_scheduled_hook( string $hook ): int {
        unset( $GLOBALS['events'][ $hook ] );
        return 1;
    }
    function add_action( string $name, callable $callback, int $priority = 10 ): void {
        $GLOBALS['actions'][ $name ][ $priority ] = $callback;
    }
    function add_filter( string $name, callable $callback, int $priority = 10 ): void {
        $GLOBALS['filters'][ $name ][ $priority ] = $callback;
    }
    function is_wp_error( array $response ): bool { return false; }
    function wp_remote_retrieve_response_code( array $response ): int { return $response['response']['code']; }
    function wp_safe_remote_get( string $url, array $args ): array {
        ++$GLOBALS['requests'];
        expect( $GLOBALS['home'] . ( $GLOBALS['expected_path'] ?? '/' ), $url, 'Only the canonical signed URL is requested' );
        expect( 0, $args['redirection'], 'Transport cannot redirect' );
        expect( true, $args['sslverify'], 'Transport validates TLS' );
        expect( [], $args['cookies'], 'Transport sends no guest cookies' );
        expect( $GLOBALS['expected_timeout'], $args['timeout'], 'Transport has a bounded timeout' );
        if ( $args['timeout'] === 3 ) {
            expect( true, $GLOBALS['finish_calls'] >= 2, 'After-response transport starts only after PHP-FPM finishes' );
        }
        expect( 1048576, $args['limit_response_size'], 'Transport caps response size' );
        expect( [ 'Accept', 'Accept-Encoding', 'X-YS-Cache-Pixel-Warm', 'Accept-Language', 'Sec-CH-UA-Mobile' ],
            array_keys( $args['headers'] ), 'Only the dedicated signature and known public variant headers are sent' );
        expect( 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', $args['headers']['Accept'], 'Dedicated Accept header' );
        expect( 'identity', $args['headers']['Accept-Encoding'], 'Stable content encoding' );
        expect( $GLOBALS['profile']['HTTP_USER_AGENT'], $args['user-agent'], 'Transport keeps the exact desktop variant' );
        expect( $GLOBALS['profile']['HTTP_ACCEPT_LANGUAGE'], $args['headers']['Accept-Language'], 'Language variant preserved' );
        expect( $GLOBALS['profile']['HTTP_SEC_CH_UA_MOBILE'], $args['headers']['Sec-CH-UA-Mobile'], 'Device hint preserved' );
        ( $GLOBALS['loopback'] )( $args['headers']['X-YS-Cache-Pixel-Warm'], $args['headers']['Accept'] );
        return [ 'response' => [ 'code' => 200 ] ];
    }

    $root = sys_get_temp_dir() . '/ys-cache-pixel-warm-test-' . bin2hex( random_bytes( 8 ) );
    $directory = $root . '/private';
    define( 'ABSPATH', $root . '/public/' );
    define( 'WP_CACHE', true );
    define( 'PYS_VERSION', '12.6.8' );
    mkdir( ABSPATH, 0700, true );
    $_SERVER['DOCUMENT_ROOT'] = ABSPATH;
    require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
    require dirname( __DIR__ ) . '/src/YSCachePixelTransport.php';
    require dirname( __DIR__ ) . '/src/YSCachePixelWarm.php';

    try {
        expect( true, YSCacheEarlyStore::initialize( $directory ), 'Prepare the actual private early store' );
        $settings = [ 'local' => true, 'early_paths' => '/', 'early_pixel_shell' => true ];
        $owned = true;
        $host = 'shop.example.test';
        $home = 'https://' . $host;
        $base = [
            'DOCUMENT_ROOT' => ABSPATH,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTPS' => 'on',
            'HTTP_HOST' => $host,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'HTTP_ACCEPT_LANGUAGE' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
        ];
        $guest = $base + [
            'HTTP_SEC_FETCH_SITE' => 'none',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_USER' => '?1',
        ];
        $_SERVER = $guest;
        $_COOKIE = $_GET = $_POST = [];
        $logged_in = $cron = false;
        $events = $actions = $filters = [];
        $requests = 0;
        $finish_calls = 0;
        $finish_successful = false;
        $expected_timeout = 8;
        $profile = YSCachePixelWarm::profile( $base );
        expect( 'zh-tw,zh;q=0.9,en-us;q=0.8,en;q=0.7', $profile['HTTP_ACCEPT_LANGUAGE'], 'Public language case normalizes to the early variant' );
        expect( YSCacheEarlyStore::requestVariant( $base ), YSCacheEarlyStore::requestVariant( $profile ), 'Profile matches the exact early-store variant' );
        foreach ( [
            [ 'HTTP_USER_AGENT' => $base['HTTP_USER_AGENT'] . ' secret-token' ],
            [ 'HTTP_ACCEPT_LANGUAGE' => "zh-TW\r\nCookie: synthetic=1" ],
            [ 'HTTP_ACCEPT_LANGUAGE' => str_repeat( 'a', 97 ) ],
            [ 'HTTP_SEC_CH_UA_MOBILE' => '?1' ],
        ] as $override ) {
            expect( null, YSCachePixelWarm::profile( array_replace( $base, $override ) ), 'Reject unbounded or conflicting profiles' );
        }
        foreach ( [
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
        ] as $phone ) {
            $mobile = array_replace( $base, [ 'HTTP_USER_AGENT' => $phone, 'HTTP_SEC_CH_UA_MOBILE' => '?1' ] );
            expect( $phone, YSCachePixelWarm::profile( $mobile )['HTTP_USER_AGENT'], 'Mobile variant preserves exact UA' );
            $mobile['HTTP_SEC_CH_UA_MOBILE'] = '';
            expect( '', YSCachePixelWarm::profile( $mobile )['HTTP_SEC_CH_UA_MOBILE'], 'Resource without a device hint' );
            $mobile['HTTP_SEC_CH_UA_MOBILE'] = '?0';
            expect( null, YSCachePixelWarm::profile( $mobile ), 'Reject phone claiming a desktop device hint' );
        }

        expect( false, YSCachePixelWarm::primeForCli( $settings, $profile ), 'CLI API requires the WordPress CLI runtime' );
        expect( false, YSCachePixelWarm::schedule( array_replace( $settings, [ 'early_pixel_shell' => false ] ) ), 'Requires opt-in' );
        expect( false, YSCachePixelWarm::schedule( array_replace( $settings, [ 'early_paths' => '/a/' ] ) ), 'Requires exact home allowlist' );
        expect( false, YSCachePixelWarm::schedule( array_replace( $settings, [ 'local' => false ] ) ), 'Requires local mode' );
        expect( false, YSCachePixelWarm::schedule( array_replace( $settings, [ 'blocking' => 'other-plugin' ] ) ), 'Reject competing page cache' );
        $owned = false;
        expect( false, YSCachePixelWarm::schedule( $settings ), 'Reject unowned drop-in' );
        $owned = true;
        foreach ( [
            [ 'HTTP_REFERER' => 'https://some.example.test/' ],
            [ 'HTTP_HOST' => 'other.example.test' ],
            [ 'HTTPS' => 'off' ],
            [ 'REQUEST_URI' => '/shop/' ],
            [ 'HTTP_COOKIE' => 'session=synthetic' ],
            [ 'HTTP_AUTHORIZATION' => 'synthetic' ],
            [ 'HTTP_X_FORWARDED_USER' => 'synthetic' ],
            [ 'HTTP_X_WP_LOCALE' => 'en-US' ],
            [ 'REQUEST_METHOD' => 'HEAD' ],
            [ 'QUERY_STRING' => 'preview=1' ],
            [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ],
            [ 'HTTP_SEC_FETCH_USER' => '' ],
            [ 'HTTP_SEC_PURPOSE' => 'prefetch' ],
            [ YSCachePixelWarm::HEADER => '' ],
        ] as $override ) {
            $_SERVER = array_replace( $guest, $override );
            expect( false, YSCachePixelWarm::schedule( $settings ), 'Reject non-direct or marked guests' );
        }
        $_SERVER = $base;
        expect( false, YSCachePixelWarm::schedule( $settings ), 'Only a supported browser navigation can queue the job' );
        $_SERVER = $guest;
        $logged_in = true;
        expect( false, YSCachePixelWarm::schedule( $settings ), 'Reject signed-in users' );
        $logged_in = false;
        expect( true, YSCachePixelWarm::schedule( $settings ), 'Queue a configured, exact direct home variant' );
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US';
        expect( false, YSCachePixelWarm::schedule( $settings ), 'Coalesce a second variant under the website cooldown' );
        expect( 1, count( $events ), 'One global cron task' );
        expect( 0, $requests, 'Enqueuing performs no HTTP request' );
        expect( true, isset( $actions['shutdown'][ PHP_INT_MAX ] ), 'Only a newly queued request sets up the shutdown kick' );
        $state = json_decode( file_get_contents( $directory . '/.pixel-warm.json' ), true );
        expect( [ 'recent', 'pending' ], array_keys( $state ), 'Pending state stays in the private early cache directory' );
        expect( true, $state['pending']['expires'] <= time() + 300, 'A pending variant has a bounded lifetime' );
        ( $actions['shutdown'][ PHP_INT_MAX ] )();
        expect( 0, $requests, 'Failed completion of the visitor response leaves the request to Cron' );
        YSCachePixelWarm::run();
        expect( 0, $requests, 'Foreground cannot run the warm hook' );
        $cron = true;
        YSCacheSettingsRepo::$generation = [ 'changed', 'home' ];
        YSCachePixelWarm::run();
        expect( 0, $requests, 'A queued task from before a purge cannot render' );
        YSCachePixelWarm::cancel();

        $_SERVER = $guest;
        expect( true, YSCachePixelWarm::schedule( $settings ), 'Queue the new generation' );
        $loopback = static function ( string $token, string $accept ) use ( $base, $settings, $directory, $host ): void {
            $_SERVER = array_replace( $base, [ YSCachePixelWarm::HEADER => $token, 'HTTP_ACCEPT' => $accept ] );
            $signed = $_SERVER;
            $parts = explode( '.', $token );
            expect( 3, count( $parts ), 'Bounded timestamp, random challenge and HMAC' );
            expect( true, (int) $parts[0] - time() <= 45, 'Short-lived signature' );
            expect( false, str_contains( file_get_contents( $directory . '/.pixel-warm.json' ), $parts[2] ), 'HMAC is never persisted' );
            expect( true, YSCachePixelWarm::headerPresent(), 'Detect marked request' );
            expect( false, YSCachePixelWarm::schedule( $settings ), 'Never requeue a signed warm request' );
            foreach ( [
                [ YSCachePixelWarm::HEADER => ( time() - 1 ) . '.' . $parts[1] . '.' . $parts[2] ],
                [ YSCachePixelWarm::HEADER => ( time() + 300 ) . '.' . $parts[1] . '.' . $parts[2] ],
                [ YSCachePixelWarm::HEADER => $parts[0] . '.' . $parts[1] . '.' . str_repeat( '0', 64 ) ],
                [ 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ],
                [ 'HTTP_USER_AGENT' => str_replace( '/145.', '/146.', $base['HTTP_USER_AGENT'] ) ],
                [ 'HTTP_SEC_CH_UA_MOBILE' => '' ],
                [ 'HTTP_ACCEPT' => '*/*' ],
                [ 'HTTP_COOKIE' => 'foo=bar' ],
                [ 'HTTP_X_WP_LOCALE' => 'en-US' ],
                [ 'HTTP_REFERER' => 'https://other.example.test/' ],
                [ 'REQUEST_URI' => '/?hello=1' ],
            ] as $override ) {
                $_SERVER = array_replace( $signed, $override );
                expect( false, YSCachePixelWarm::verified( $settings ), 'Deny the wrong signature, variant or request context' );
            }
            $_SERVER = $signed;
            $before = YSCacheSettingsRepo::$generation;
            YSCacheSettingsRepo::$generation = [ 'further-change', 'home' ];
            expect( false, YSCachePixelWarm::verified( $settings ), 'Deny a token from before a generation change' );
            YSCacheSettingsRepo::$generation = $before;
            $_SESSION = [ 'synthetic' => 1 ];
            expect( false, YSCachePixelWarm::verified( $settings ), 'Deny an existing nonempty session' );
            unset( $_SESSION );
            expect( false, YSCachePixelWarm::skipSession( false ), 'Unverified visitors keep the native session policy' );
            YSCachePixelWarm::register();
            expect( true, isset( $GLOBALS['actions']['wp'][ PHP_INT_MIN ] ), 'Verify before the native PYS wp hook' );
            ( $GLOBALS['actions']['wp'][ PHP_INT_MIN ] )();
            expect( true, YSCachePixelWarm::isVerified(), 'Accept the canonical signed warm request' );
            expect( [ YSCachePixelWarm::class, 'skipSession' ], $GLOBALS['filters']['pys_skip_session'][10], 'Install only the exact session skip callback' );
            expect( true, YSCachePixelWarm::skipSession( false ), 'Skip PYS session initialization for the verified warm only' );

            $variant = YSCacheEarlyStore::requestVariant( $_SERVER );
            $snapshot = YSCacheEarlyStore::snapshot( $directory, '/' );
            $body = '<!DOCTYPE html><html><body>Neutral example</body></html>';
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            foreach ( [ 'Pragma: no-cache', 'Set-Cookie: fixture=1' ] as $header ) {
                expect( false, YSCacheEarlyStore::write( $directory, $host, '/', $snapshot, [ ...$headers, $header ], $body, 60, $variant ), 'Private response headers prevent shared shell storage' );
            }
            expect( true, YSCacheEarlyStore::write( $directory, $host, '/', $snapshot, $headers, $body, 60, $variant ), 'Store a simulated neutral response' );

            $reset = new ReflectionProperty( YSCachePixelWarm::class, 'verified' );
            if ( PHP_VERSION_ID < 80100 ) {
                $reset->setAccessible( true );
            }
            $reset->setValue( null, false );
            unset( $GLOBALS['filters']['pys_skip_session'] );
            expect( false, YSCachePixelWarm::verified( $settings ), 'Token reuse fails in a fresh request' );
        };
        YSCachePixelWarm::run();
        YSCachePixelWarm::run();
        expect( 1, $requests, 'One async loopback even on repeated cron hook invocation' );
        YSCachePixelWarm::cancel();
        $_SERVER = $guest;
        expect( [], $events, 'Cancellation drops the pending cron event' );
        expect( false, file_exists( $directory . '/.pixel-warm.json' ), 'Cancellation drops pending request metadata' );
        expect( false, YSCachePixelWarm::schedule( $settings ), 'An existing early artifact avoids another warm job' );

        define( 'WP_CLI', true );
        $cron = false;
        expect( true, YSCachePixelWarm::primeForCli( $settings, $profile ), 'WP CLI recognizes an existing exact variant' );
        expect( true, YSCacheEarlyStore::invalidatePath( $directory, '/' ), 'Clear the fixture for WP CLI priming' );
        expect( true, YSCachePixelWarm::primeForCli( $settings, $profile ), 'WP CLI primes with the same transport and proof' );
        expect( 2, $requests, 'One request from cron and one explicit CLI warm' );

        YSCachePixelWarm::cancel();
        $_SERVER = $guest;
        expect( true, YSCacheEarlyStore::invalidatePath( $directory, '/' ), 'Clear the fixture for shutdown priming' );
        expect( true, YSCachePixelWarm::schedule( $settings ), 'Queue the same variant for shutdown after a new miss' );
        $finish_successful = true;
        $expected_timeout = 3;
        ( $actions['shutdown'][ PHP_INT_MAX ] )();
        YSCachePixelWarm::run();
        YSCachePixelWarm::finishAndRun();
        expect( 3, $requests, 'The post-response worker and Cron claim the same task at most once' );
        expect( 2, $finish_calls, 'PHP-FPM completion runs only for a queued response' );
        YSCachePixelWarm::cancel();
        $expected_timeout = 8;
        $expected_path = '/shop/category/';
        $settings['guest_template'] = true;
        $loopback = static function ( string $token, string $accept ) use ( $settings, $base, $expected_path ): void {
            expect( true, str_starts_with( $token, 't.' ), 'A template job uses a distinct proof' );
            $_SERVER = array_replace( $base, [ YSCachePixelWarm::HEADER => $token, 'HTTP_ACCEPT' => $accept, 'REQUEST_URI' => '/shop/other/' ] );
            expect( false, YSCachePixelWarm::verified( $settings ), 'Cannot transfer proof to a different public route' );
            $_SERVER['REQUEST_URI'] = $expected_path;
            $_SERVER[ YSCachePixelWarm::HEADER ] = substr( $token, 2 );
            expect( false, YSCachePixelWarm::verified( $settings ), 'Cannot exchange template proof for a full homepage' );
            $_SERVER[ YSCachePixelWarm::HEADER ] = $token;
            expect( true, YSCachePixelWarm::verified( $settings ), 'Accept intended catalog route' );
            expect( true, YSCachePixelWarm::isTemplateWarm(), 'Only the verified catalog build is neutral' );
            \YangSheep\Cache\YSCacheGuestTemplate::$stored = true;
        };
        expect( true, YSCachePixelWarm::primeForCli( $settings, $profile, $expected_path, true ), 'Public template artifact produced' );
        expect( false, YSCachePixelWarm::primeForCli( $settings, $profile, '//outside.example/', true ), 'Never warm a network-path reference' );
        echo "Pixel warm scope, single-use proof, async coalescing, and sterile storage guards passed.\n";
    } finally {
        $entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $entries as $entry ) {
            $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
        }
        rmdir( $root );
    }
}
