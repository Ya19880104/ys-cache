<?php

namespace Elementor\Core\Base {
    final class Document {
        public const CACHE_META_KEY = '_elementor_element_cache';

        public function __construct( private int $id ) {}

        public function get_document_cache(): ?array {
            $raw = get_post_meta( $this->id, self::CACHE_META_KEY, true );
            $record = json_decode( $raw, true );
            return ( $record['timeout'] ?? 0 ) >= current_time( 'timestamp' ) ? $record['value'] : null;
        }

        public function set_document_cache( string $native ): bool {
            return update_post_meta( $this->id, self::CACHE_META_KEY, $native );
        }
    }
}

namespace PixelYourSite {
    final class EventsManager {
        public function outputData(): void {}

        public function otherHook(): void {}
    }
}

namespace YangSheep\Cache {
    function headers_sent(): bool {
        return false;
    }

    function header( string $value, bool $replace ): void {
        [ $name, $contents ] = explode( ': ', $value, 2 );
        $GLOBALS['response_headers'][ $name ] = $contents;
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    use Elementor\Core\Base\Document;
    use PixelYourSite\EventsManager;
    use YangSheep\Cache\YSCacheElementor;
    use YangSheep\Cache\YSCacheIntegrations;
    use YangSheep\Cache\YSCacheStorage;

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-elementor-test-' . bin2hex( random_bytes( 8 ) );
    define( 'ABSPATH', $root . '/public/' );
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
    define( 'YS_CACHE_STORAGE_ROOT', $root . '/storage' );
    define( 'YS_CACHE_VERSION', '1.0.0-test' );
    define( 'ELEMENTOR_VERSION', '4.0.0-test' );

    if ( ! mkdir( WP_CONTENT_DIR, 0700, true ) || ! mkdir( YS_CACHE_STORAGE_ROOT, 0700 ) ) {
        throw new \RuntimeException( 'Could not create isolated fixture directories.' );
    }
    $_SERVER['DOCUMENT_ROOT'] = ABSPATH;

    $GLOBALS['native_now'] = 1700000000;
    $GLOBALS['metadata'] = [
        21 => [ '_elementor_edit_mode' => 'builder' ],
        22 => [ '_elementor_edit_mode' => 'builder' ],
    ];
    $GLOBALS['native_writes_allowed'] = true;
    $GLOBALS['elementor_ttl'] = '1';

    function home_url(): string {
        return 'https://store.example.test';
    }

    function determine_locale(): string {
        return 'zh_TW';
    }

    function get_current_blog_id(): int {
        return 1;
    }

    function wp_salt( string $scheme ): string {
        return 'isolated-test-' . $scheme;
    }

    function wp_normalize_path( string $path ): string {
        return str_replace( '\\', '/', $path );
    }

    function current_time( string $type ): int {
        expect_same( 'timestamp', $type, 'Elementor uses the WordPress local timestamp' );
        return $GLOBALS['native_now'];
    }

    function get_option( string $name ) {
        return $name === 'elementor_element_cache_ttl' ? $GLOBALS['elementor_ttl'] : false;
    }

    function add_filter( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void {
        $hook = $GLOBALS['wp_filter'][ $name ] ??= (object) [ 'callbacks' => [] ];
        $hook->callbacks[ $priority ][] = [ 'function' => $callback, 'accepted_args' => $accepted ];
    }

    function apply_filters( string $name, $value, ...$arguments ) {
        $callbacks = $GLOBALS['wp_filter'][ $name ]->callbacks ?? [];
        ksort( $callbacks );
        foreach ( $callbacks as $entries ) {
            foreach ( $entries as $entry ) {
                $value = $entry['function']( ...array_slice( [ $value, ...$arguments ], 0, $entry['accepted_args'] ) );
            }
        }
        return $value;
    }

    function get_post_meta( int $id, string $key, bool $single = false ) {
        $override = apply_filters( 'get_post_metadata', null, $id, $key, $single );
        if ( $override !== null ) {
            return $override;
        }
        $record = $GLOBALS['metadata'][ $id ][ $key ] ?? '';
        return $single ? $record : [ $record ];
    }

    function update_post_meta( int $id, string $key, string $value ): bool {
        $override = apply_filters( 'update_post_metadata', null, $id, $key, $value );
        if ( $override !== null ) {
            return (bool) $override;
        }
        if ( ! $GLOBALS['native_writes_allowed'] ) {
            return false;
        }
        $GLOBALS['metadata'][ $id ][ $key ] = $value;
        return true;
    }

    function expect_same( $expected, $actual, string $description ): void {
        if ( $expected !== $actual ) {
            throw new \RuntimeException( $description . ' returned ' . var_export( $actual, true ) );
        }
    }

    function native_record( string $markup, int $timeout ): string {
        return json_encode( [
            'timeout' => $timeout,
            'value'   => [ 'content' => $markup ],
        ], JSON_THROW_ON_ERROR );
    }

    function start_request( string $path = '/products/', string $language = 'zh_TW', array $generations = [ 'site:1', 'path:1' ] ): void {
        $GLOBALS['wp_filter'] = $GLOBALS['response_headers'] = [];
        YSCacheElementor::register( 21, $path, $language, $generations, 60 );
    }

    function expect_cache_status( ?string $expected, string $description ): void {
        expect_same( $expected, $GLOBALS['response_headers']['X-YS-Elementor-Cache'] ?? null, $description );
    }

    require dirname( __DIR__ ) . '/src/YSCacheStorage.php';
    require dirname( __DIR__ ) . '/src/YSCacheElementor.php';
    require dirname( __DIR__ ) . '/src/YSCacheIntegrations.php';

    try {
        $mark = '<div>Fixture document content</div>';
        $otherMark = '<div>Other fixture document</div>';
        $native = native_record( $mark, $GLOBALS['native_now'] + 90 );
        $otherNative = native_record( $otherMark, $GLOBALS['native_now'] + 90 );
        $GLOBALS['metadata'][21][ Document::CACHE_META_KEY ] = $native;
        $GLOBALS['metadata'][22][ Document::CACHE_META_KEY ] = $otherNative;
        $document = new Document( 21 );
        $directory = YSCacheStorage::directory();
        expect_same( true, is_dir( $directory ), 'Private receipt directory created' );

        start_request();
        expect_same( $native, get_post_meta( 21, Document::CACHE_META_KEY, true ), 'Editor/native metadata reads remain intact' );
        expect_same( [ $native ], get_post_meta( 21, Document::CACHE_META_KEY ), 'Multi-value metadata reads remain intact' );
        expect_same( [ 'content' => $otherMark ], ( new Document( 22 ) )->get_document_cache(), 'Other documents stay native' );
        expect_cache_status( null, 'Editor and other document reads never use the receipt' );
        expect_same( true, update_post_meta( 21, Document::CACHE_META_KEY, $native ), 'Unrelated native writes still work' );
        expect_same( [], glob( $directory . '/*/*.html' ), 'A write before a render MISS does not create a receipt' );
        expect_same( null, $document->get_document_cache(), 'Native record without a receipt misses' );
        expect_cache_status( 'MISS', 'First Elementor render marks a MISS' );
        expect_same( $native, get_post_meta( 21, Document::CACHE_META_KEY, true ), 'A render MISS does not remove Elementor metadata' );
        expect_same( true, $document->set_document_cache( $native ), 'Elementor still saves its own native record' );
        $receipts = glob( $directory . '/*/*.html' );
        expect_same( 1, count( $receipts ), 'Native save results in one receipt' );
        $receipt = $receipts[0];
        expect_same( hash( 'sha256', $native ), file_get_contents( $receipt ), 'Receipt stores digest only, with no raw markup or JSON' );
        expect_same( $native, get_post_meta( 21, Document::CACHE_META_KEY, true ), 'Native post meta keeps original serialized data' );

        start_request();
        expect_same( [ 'content' => $mark ], $document->get_document_cache(), 'Next request uses matching Elementor native data' );
        expect_cache_status( 'HIT', 'Matching cache receipt is a HIT' );

        $GLOBALS['native_now'] += 91;
        start_request();
        expect_same( null, $document->get_document_cache(), 'Fresh receipt cannot extend native Elementor expiry' );
        expect_cache_status( 'MISS', 'Expired native data is never reported as a HIT' );
        $GLOBALS['native_now'] -= 91;

        expect_same( true, touch( $receipt, time() - 61 ), 'Make only the receipt expire' );
        start_request();
        expect_same( null, $document->get_document_cache(), 'YS CACHE receipt age can expire before native Elementor data' );
        expect_cache_status( 'MISS', 'Expired YS CACHE receipt is a MISS' );
        expect_same( true, $document->set_document_cache( $native ), 'A normal Elementor write refreshes an expired receipt' );

        foreach ( [
            [ '/other-products/', 'zh_TW', [ 'site:1', 'path:1' ], 'Path change' ],
            [ '/products/', 'en_US', [ 'site:1', 'path:1' ], 'Language change' ],
            [ '/products/', 'zh_TW', [ 'site:2', 'path:1' ], 'Site generation change' ],
            [ '/products/', 'zh_TW', [ 'site:1', 'path:2' ], 'Path generation change' ],
        ] as [ $path, $language, $generations, $label ] ) {
            start_request( $path, $language, $generations );
            expect_same( null, $document->get_document_cache(), $label . ' cannot reuse the original receipt' );
            expect_cache_status( 'MISS', $label . ' is a MISS' );
        }

        start_request();
        expect_same( [ 'content' => $mark ], $document->get_document_cache(), 'The original context still reuses its matching receipt' );
        expect_cache_status( 'HIT', 'Original context remains a HIT' );
        $GLOBALS['metadata'][21][ Document::CACHE_META_KEY ] = $otherNative;
        start_request();
        expect_same( null, $document->get_document_cache(), 'Changed native data does not match the old digest' );
        expect_cache_status( 'MISS', 'Changed Elementor metadata is a MISS' );
        expect_same( $otherNative, get_post_meta( 21, Document::CACHE_META_KEY, true ), 'Builder veto does not hide changed data from other callers' );
        $GLOBALS['native_writes_allowed'] = false;
        expect_same( false, $document->set_document_cache( $native ), 'Metadata can fail to persist after the update filter runs' );
        $GLOBALS['native_writes_allowed'] = true;
        start_request();
        expect_same( null, $document->get_document_cache(), 'A failed database write cannot supply stale content' );
        expect_cache_status( 'MISS', 'Failed native write still misses on the next request' );
        expect_same( true, $document->set_document_cache( $otherNative ), 'Elementor later saves its changed data' );
        start_request();
        expect_same( [ 'content' => $otherMark ], $document->get_document_cache(), 'Changed data can seed its own receipt' );
        expect_cache_status( 'HIT', 'Changed data becomes a HIT only after its native save' );

        $GLOBALS['elementor_ttl'] = 'disable';
        start_request();
        expect_same( [ 'content' => $otherMark ], $document->get_document_cache(), 'Elementor cache disabled option leaves the metadata path unchanged' );
        expect_cache_status( null, 'Elementor disabled option installs no guard' );
        $GLOBALS['elementor_ttl'] = '1';
        $GLOBALS['metadata'][21]['_elementor_edit_mode'] = '';
        start_request();
        expect_same( [ 'content' => $otherMark ], $document->get_document_cache(), 'Non-builder documents keep native behavior' );
        expect_cache_status( null, 'Non-builder document installs no guard' );

        $_SESSION = [ 'TrafficSource' => 'Direct' ];
        expect_same( false, YSCacheIntegrations::hasVisitorEventRenderer(), 'PixelYourSite absent' );
        expect_same( false, YSCacheIntegrations::hasOnlyAttributionSession(), 'A session is private without PixelYourSite' );
        expect_same( false, YSCacheIntegrations::isElementorAttributionCookie( '_fbp' ), 'A tracking cookie is not trusted without PixelYourSite' );
        define( 'PYS_VERSION', '11.2.2-test' );
        $_SESSION += [
            'LandingPage'   => '/products/',
            'TrafficUtms'   => [ 'source' => 'fixture' ],
            'TrafficUtmsId' => 'fixture',
        ];
        expect_same( true, YSCacheIntegrations::hasOnlyAttributionSession(), 'Known PixelYourSite attribution session is allowed on a private response' );
        $_SESSION['cart_data'] = [];
        expect_same( false, YSCacheIntegrations::hasOnlyAttributionSession(), 'An unknown session key vetoes a fragment, even beside attribution data' );
        unset( $_SESSION );
        expect_same( true, YSCacheIntegrations::hasOnlyAttributionSession(), 'No PHP session is eligible' );
        expect_same( true, YSCacheIntegrations::isElementorAttributionCookie( '_fbp' ), 'Known attribution cookie may accompany a private fragment request' );
        foreach ( [ 'private_session', 'pys_custom_session', 'wp_woocommerce_session_fixture' ] as $cookie ) {
            expect_same( false, YSCacheIntegrations::isElementorAttributionCookie( $cookie ), 'Unknown or cart cookie cannot use the attribution exception' );
        }

        $manager = new EventsManager();
        add_filter( 'wp_enqueue_scripts', [ $manager, 'otherHook' ] );
        expect_same( false, YSCacheIntegrations::hasVisitorEventRenderer(), 'PixelYourSite without its browser event output' );
        add_filter( 'wp_enqueue_scripts', [ $manager, 'outputData' ] );
        expect_same( true, YSCacheIntegrations::hasVisitorEventRenderer(), 'Registered PixelYourSite renderer vetoes shared HTML' );
        $eventMarkup = [
            "<script>var pysOptions = {'events': []};</script>",
            '<script id="pys-js-extra">window.trackingFixture();</script>',
            "<script ID='pys_late_event'>window.trackingFixture();</script>",
        ];
        foreach ( $eventMarkup as $markup ) {
            expect_same( false, YSCacheIntegrations::cacheableHtml( $markup ), 'PixelYourSite dynamic event markup vetoes shared HTML' );
        }
        expect_same( true, YSCacheIntegrations::cacheableHtml( '<p>PixelYourSite and pysOptions documentation</p>' ), 'Plain tracking references do not disable caching' );

        $GLOBALS['metadata'][21]['_elementor_edit_mode'] = 'builder';
        foreach ( [
            ...$eventMarkup,
            '<form action="/products/"><input name="field"></form>',
            '<script nonce="test-nonce">window.fragmentFixture();</script>',
            '<style nonce="test-nonce">.test{color:blue}</style>',
        ] as $index => $markup ) {
            $path = '/unsafe-fragment-' . $index . '/';
            $privateRecord = native_record( $markup, $GLOBALS['native_now'] + 90 );
            start_request( $path );
            expect_same( null, $document->get_document_cache(), 'New context starts without a receipt' );
            expect_same( true, $document->set_document_cache( $privateRecord ), 'Elementor may still save the native metadata itself' );
            expect_same( $privateRecord, get_post_meta( 21, Document::CACHE_META_KEY, true ), 'Native metadata is unchanged by the fragment guard' );
            expect_same( [ $receipt ], glob( $directory . '/*/*.html' ), 'Event payloads, forms and CSP nonces never receive a receipt' );
            start_request( $path );
            expect_same( null, $document->get_document_cache(), 'Dynamic native markup remains a fragment MISS' );
            expect_cache_status( 'MISS', 'Unsafe native markup is never reported as a HIT' );
        }
    } finally {
        // Only remove regular receipt files in the uniquely named test storage directory.
        $storage = YSCacheStorage::directory();
        if ( $storage !== '' && dirname( $storage ) === realpath( YS_CACHE_STORAGE_ROOT ) ) {
            foreach ( glob( $storage . '/*', GLOB_ONLYDIR ) as $bucket ) {
                foreach ( glob( $bucket . '/*.html' ) as $path ) {
                    unlink( $path );
                }
                if ( is_file( $bucket . '/.lock' ) ) {
                    unlink( $bucket . '/.lock' );
                }
                rmdir( $bucket );
            }
            rmdir( $storage );
        }
        rmdir( YS_CACHE_STORAGE_ROOT );
        rmdir( WP_CONTENT_DIR );
        rmdir( ABSPATH );
        rmdir( $root );
    }

    echo "Elementor native fragment and PixelYourSite cache integrations passed.\n";
}
