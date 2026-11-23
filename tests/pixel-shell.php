<?php

namespace PixelYourSite {
    final class EventsManager {
        public string $noscript = '';
        public function outputNoScriptData(): void {
            echo $this->noscript;
        }
    }
    final class TestPixelYourSite {
        public EventsManager $manager;
        public function __construct() {
            $this->manager = new EventsManager();
        }
        public function getOption( string $name ): bool {
            return in_array( $name, [ 'server_static_event_use_ajax', 'server_event_use_ajax' ], true );
        }
        public function getEventsManager(): EventsManager {
            return $this->manager;
        }
        public function is_user_agent_bot(): bool {
            return false;
        }
        public function isCachePreload(): bool {
            return false;
        }
    }
    function PYS(): TestPixelYourSite {
        static $plugin;
        return $plugin ??= new TestPixelYourSite();
    }
}

namespace YangSheep\Cache {
    final class YSCachePixelWarm {
        public static bool $verified = false;
        public static function isVerified(): bool {
            return self::$verified;
        }
    }
    final class YSCachePixelTransport {
        public static function sendFallback(): void {
            ++$GLOBALS['fallbacks'];
            http_response_code( 200 );
            echo 'native fallback script';
        }
    }
    final class YSCacheIntegrations {
        public static function hasOnlyAttributionSession(): bool {
            return count( $_SESSION ) === 4;
        }
        public static function cacheableHtml( string $html ): bool {
            return stripos( $html, 'pysOptions' ) === false;
        }
    }
    function session_status(): int {
        return YSCachePixelWarm::$verified ? PHP_SESSION_NONE : PHP_SESSION_ACTIVE;
    }
    function header( string $header, bool $replace, int $code = 0 ): void {
        $GLOBALS['headers'][] = $header;
        if ( $code ) {
            $GLOBALS['status'] = $code;
        }
    }
    function http_response_code( int $code ): void {
        $GLOBALS['status'] = $code;
    }
}

namespace {
    use YangSheep\Cache\YSCachePixel;
    use YangSheep\Cache\YSCachePixelWarm;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }
    define( 'ABSPATH', __DIR__ );
    define( 'PYS_VERSION', '12.6.8' );
    define( 'PYS_URL', 'https://store.example.test/wp-content/plugins/pixelyoursite-pro' );
    define( 'XOO_EL_VERSION', '4.0.4' );
    require dirname( __DIR__ ) . '/src/YSCachePixel.php';

    function is_front_page(): bool { return true; }
    function is_singular(): bool { return true; }
    function is_user_logged_in(): bool { return false; }
    function is_preview(): bool { return false; }
    function post_password_required(): bool { return false; }
    function wp_is_mobile(): bool { return false; }
    function is_ssl(): bool { return true; }
    function home_url( string $path ): string { return 'https://store.example.test' . $path; }
    function admin_url( string $path ): string { return home_url( '/wp-admin/' . $path ); }
    function did_action( string $name ): int { return $name === 'wp' ? 1 : $GLOBALS['enqueue']; }
    function has_action( string $name, array $callback ): int {
        return [ 'enqueueScripts' => 10, 'setupEventsParams' => 14, 'outputData' => 15, 'outputNoScriptData' => 10 ][ $callback[1] ];
    }
    function has_filter( string $name ): bool { return isset( $GLOBALS['wp_filter'][ $name ] ); }
    function wp_enqueue_scripts(): void { ++$GLOBALS['enqueue']; }
    function wp_scripts(): object {
        return new class {
            public function get_data( string $handle, string $type ): string {
                return $GLOBALS['optionsJs'];
            }
        };
    }
    function wp_print_inline_script_tag( string $data, array $attributes ): void {
        $GLOBALS['inline'][] = [ $data, $attributes ];
    }
    function expect( bool $ok, string $message ): void {
        if ( ! $ok ) {
            throw new \RuntimeException( $message );
        }
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_HOST'] = 'store.example.test';
    unset( $_SERVER['HTTP_REFERER'], $_SERVER['QUERY_STRING'] );
    $_GET = [];
    $_SESSION = [
        'TrafficSource' => 'direct',
        'LandingPage' => home_url( '/' ),
        'TrafficUtms' => [],
        'TrafficUtmsId' => [],
    ];
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['enqueue'] = 0;
    $GLOBALS['status'] = 200;
    $GLOBALS['headers'] = [];
    $GLOBALS['fallbacks'] = 0;
    $GLOBALS['inline'] = [];

    function id( int $suffix ): string {
        return sprintf( '00000000-0000-4000-8000-%012u', $suffix );
    }
    $event = [
        'name' => 'PageView',
        'eventID' => id( 1 ),
        'pixelIds' => [ '123456789' ],
        'params' => [
            'event_url' => home_url( '/' ),
            'page_title' => 'Store home',
            'plugin' => 'PixelYourSite',
            'post_id' => 1,
            'post_type' => 'page',
            'user_role' => 'guest',
        ],
    ];
    $dynamic = [];
    foreach ( [ 'internal_link', 'outbound_link', 'video', 'tel_link', 'email_link', 'form', 'download', 'comment', 'adsense' ] as $i => $name ) {
        $dynamic[ 'automatic_event_' . $name ] = array_fill_keys( [ 'facebook', 'ga', 'google_ads' ], [ 'eventID' => id( $i + 2 ) ] );
    }
    $data = [
        'staticEvents' => [ 'facebook' => [ 'init_event' => [ $event ] ] ],
        'dynamicEvents' => $dynamic,
        'triggerEvents' => [],
        'ajaxForServerStaticEvent' => '1',
        'ajaxForServerEvent' => '1',
        'user_id' => '0',
        'facebook' => [ 'advancedMatchingEnabled' => false, 'advancedMatching' => [] ],
        'tracking_analytics' => [ 'userData' => [ 'emails' => [], 'phones' => [], 'addresses' => [] ] ],
    ];
    $optionsJs = 'var pysOptions = ' . json_encode( $data ) . ';';
    $source = 'https://www.facebook.com/tr?' . http_build_query( [
        'id' => $event['pixelIds'][0],
        'eid' => $event['pixelIds'][0] . $event['eventID'],
        'ev' => 'PageView',
        'noscript' => 1,
        'cd' => $event['params'],
    ] );
    $fragment = '<noscript><img height="1" width="1" style="display: none;" src="' .
        htmlspecialchars( $source, ENT_QUOTES ) . '" alt=""></noscript>';
    $html = '<!doctype html><html><head></head><body><main>Public home</main>' .
        '<script id="pys-js-extra">' . $optionsJs . '</script>' . $fragment . '</body></html>';

    expect( YSCachePixel::eligible(), 'Known direct post-query runtime' );
    expect( YSCachePixel::shell( $html ) === null, 'A normal visitor response cannot seed a shell' );
    YSCachePixelWarm::$verified = true;
    $nativeSession = $_SESSION;
    $_SESSION = [];
    $GLOBALS['wp_filter']['pys_skip_session'] = (object) [ 'callbacks' => [
        10 => [ [ 'function' => [ YSCachePixelWarm::class, 'skipSession' ] ] ],
    ] ];
    $shell = YSCachePixel::shell( $html );
    expect( is_string( $shell ) && str_contains( $shell, YSCachePixel::SCRIPT_URL ) &&
        str_contains( $shell, YSCachePixel::PIXEL_URL ), 'Generated shell has both redirect slots' );
    expect( ! str_contains( $shell, $event['eventID'] ) && ! str_contains( $shell, 'pysOptions' ), 'No native event data remains' );
    $native = '<script id="pys-js" defer src="' . PYS_URL . '/dist/scripts/public.js?ver=' . PYS_VERSION . '"></script>';
    $deferred = '<script id="ys-cache-pys-bootstrap" defer ';
    foreach ( [ $native, str_replace( 'public.js', 'public.min.js', $native ) ] as $consumer ) {
        $pair = YSCachePixel::shell( str_replace( $fragment, "\n" . $consumer . $fragment, $html ) );
        expect( is_string( $pair ) && strpos( $pair, $deferred ) !== false &&
            strpos( $pair, $deferred ) < strpos( $pair, $consumer ), 'Native deferred PYS receives private data in order' );
    }
    foreach ( [
        '',
        str_replace( ' defer ', ' ', $native ),
        str_replace( ' defer ', ' async ', $native ),
        str_replace( ' defer ', ' defer async ', $native ),
        str_replace( PYS_URL, 'https://cdn.example.test/other', $native ),
        str_replace( 'public.js', 'custom.js', $native ),
        '<script>window.beforePixel=true;</script>' . $native,
        '<!-- wrapper -->' . $native,
    ] as $consumer ) {
        $custom = YSCachePixel::shell( str_replace( $fragment, $consumer . $fragment, $html ) );
        expect( is_string( $custom ) && ! str_contains( $custom, $deferred ), 'Changed PYS order or strategy keeps synchronous localization' );
    }
    $reader = YSCachePixel::shell( str_replace( $fragment, $native . '<script>use(pysOptions);</script>' . $fragment, $html ) );
    expect( $reader === null || ! str_contains( $reader, $deferred ), 'A later inline options reader preserves synchronous availability' );
    expect( YSCachePixel::shell( str_replace( '</main>', '<form action=""></form></main>', $html ) ) === null,
        'Unknown forms veto the shell' );
    expect( YSCachePixel::shell( str_replace( '</main>', '<script>var pys_late_event = {};</script></main>', $html ) ) === null,
        'Late PYS markup vetoes the shell' );
    expect( is_string( YSCachePixel::shell( str_replace( '</main>',
        "<script>if(node && document.getElementById('pys_late_event')) { run(); }</script></main>", $html ) ) ),
        'The native inactive observer is preserved when it has no private holder' );
    expect( YSCachePixel::shell( str_replace( '</main>', '<div id="pys_late_event" dir="[]"></div></main>', $html ) ) === null,
        'Native late event holders remain private' );
    expect( YSCachePixel::shell( str_replace( 'facebook.com/tr', 'example.test/tr', $html ) ) === null,
        'Unrecognized pixel is never redirected' );
    expect( YSCachePixel::shell( str_replace( 'PageView&amp;', 'Other&amp;', $html ) ) === null,
        'Unexpected noscript event vetoes the shell' );
    expect( YSCachePixel::shell( str_replace( $fragment, $fragment . $fragment, $html ) ) === null,
        'Duplicate tracking fragments veto the shell' );
    expect( YSCachePixel::shell( str_replace( $optionsJs, 'var pysOptions = {"staticEvents":true};', $html ) ) === null,
        'Malformed localized structure fails closed' );
    $siteKit = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-EXAMPLE" height="0" width="0" ' .
        'style="display:none;visibility:hidden"></iframe></noscript>';
    $blocksy = '<noscript><link rel="stylesheet" href="' .
        home_url( '/wp-content/themes/blocksy/static/bundle/no-scripts.min.css' ) . '" type="text/css" /></noscript>';
    $woo = '<noscript><style>.woocommerce-product-gallery{ opacity: 1 !important; }</style></noscript>';
    expect( is_string( YSCachePixel::shell( str_replace( '</head>', $siteKit . $blocksy . $woo . '</head>', $html ) ) ),
        'Known public non-PYS fallbacks are preserved' );
    expect( YSCachePixel::shell( str_replace( '</head>', '<noscript><style>.foo{background:url(//example.com)}</style></noscript></head>', $html ) ) === null,
        'Unreviewed no-script CSS is rejected' );
    $xooLogin = '<form class="xoo-el-action-form xoo-el-form-login">'
        . '<input type="text" name="xoo-el-username" value="">'
        . '<input type="password" name="xoo-el-password" value="">'
        . '<input type="checkbox" name="xoo-el-rememberme" value="forever">'
        . '<input type="hidden" name="_xoo_el_form" value="login">'
        . '<input type="hidden" name="xoo_el_redirect" value="/"><button type="submit">Log in</button></form>';
    $xooOptions = [
        'adminurl' => admin_url( 'admin-ajax.php' ),
        'redirectDelay' => '300',
        'html' => [ 'spinner' => '', 'editField' => '', 'notice' => [ 'error' => '', 'success' => '' ] ],
        'autoOpenPopup' => 'no',
        'autoOpenPopupOnce' => '',
        'aoDelay' => '',
        'loginClass' => '',
        'registerClass' => '',
        'errorLog' => '',
        'resetPwPattern' => '',
        'resend_wait' => '90',
        'preventClosing' => '',
        'hasCodeForms' => '',
        'isLoggedIn' => 'no',
        'checkout' => [ 'loginEnabled' => '', 'loginRedirect' => '/' ],
    ];
    $xooFields = [
        'adminurl' => admin_url( 'admin-ajax.php' ),
        'password_strength' => [ 'min_password_strength' => 3, 'i18n_password_error' => '', 'i18n_password_hint' => '' ],
    ];
    $xooScripts = '<script id="xoo-el-js-js-extra">var xoo_el_localize = ' . json_encode( $xooOptions ) . ';</script>'
        . '<script id="xoo-aff-js-js-extra">var xoo_aff_localize = ' . json_encode( $xooFields ) . ';</script>';
    $xooHtml = str_replace( '</body>', $xooLogin . $xooScripts . '</body>', $html );
    expect( is_string( YSCachePixel::shell( $xooHtml ) ), 'Known blank XOO guest login and its native scripts remain in the shell' );
    expect( YSCachePixel::shell( str_replace( 'name="xoo-el-username" value=""', 'name="xoo-el-username" value="visitor"', $xooHtml ) ) === null,
        'A filled identity disqualifies even the known XOO form' );
    expect( YSCachePixel::shell( str_replace( 'name="xoo_el_redirect" value="/"', 'name="xoo_el_redirect" value="https://other.example/"', $xooHtml ) ) === null,
        'XOO cannot redirect outside the canonical URL' );
    expect( YSCachePixel::shell( str_replace( 'name="_xoo_el_form" value="login"', 'name="_xoo_el_form" value="resetPassword"', $xooHtml ) ) === null,
        'Password recovery state never enters a shell' );
    expect( YSCachePixel::shell( str_replace( '"password_strength":', '"nonce":"guest","password_strength":', $xooHtml ) ) === null,
        'Unreviewed XOO script nonces block storage' );
    YSCachePixelWarm::$verified = false;
    $_SESSION = $nativeSession;
    unset( $GLOBALS['wp_filter']['pys_skip_session'] );

    $_SERVER['HTTP_REFERER'] = 'https://referrer.example.test/';
    expect( ! YSCachePixel::eligible(), 'Referred users stay in native documents' );
    unset( $_SERVER['HTTP_REFERER'] );
    $GLOBALS['wp_filter']['pys_disable_by_gdpr'] = new \stdClass();
    expect( ! YSCachePixel::eligible(), 'Unreviewed consent callbacks veto the shell' );
    unset( $GLOBALS['wp_filter']['pys_disable_by_gdpr'] );
    $GLOBALS['wp_filter']['pys_event_factory'] = (object) [
        'callbacks' => [ 10 => [ [ 'function' => static fn() => [] ] ] ],
    ];
    expect( ! YSCachePixel::eligible(), 'Unreviewed event factories veto the shell' );
    unset( $GLOBALS['wp_filter']['pys_event_factory'] );

    ob_start();
    YSCachePixel::dynamicResponse( 'script' );
    $out = ob_get_clean();
    expect( $out === $optionsJs && $GLOBALS['enqueue'] === 1 && in_array(
        'Content-Type: application/javascript; charset=UTF-8', $GLOBALS['headers'], true
    ), 'Script is fresh native localization and generated once' );
    expect( in_array( 'Cache-Control: private, no-cache, no-store, must-revalidate', $GLOBALS['headers'], true ) &&
        in_array( 'Cross-Origin-Resource-Policy: same-origin', $GLOBALS['headers'], true ), 'Private script response is no-store and same-origin' );
    ob_start();
    YSCachePixel::dynamicResponse( 'script' );
    expect( ob_get_clean() === 'native fallback script' && $GLOBALS['status'] === 200 &&
        $GLOBALS['enqueue'] === 1 && $GLOBALS['fallbacks'] === 1, 'Repeated native enqueue returns the normal-page script' );

    $validOptionsJs = $optionsJs;
    $optionsJs = 'var pysOptions = {"staticEvents":true};';
    $GLOBALS['enqueue'] = 0;
    ob_start();
    YSCachePixel::dynamicResponse( 'script' );
    expect( ob_get_clean() === 'native fallback script' && $GLOBALS['status'] === 200 &&
        $GLOBALS['enqueue'] === 1 && $GLOBALS['fallbacks'] === 2, 'Unsupported native localization returns the normal-page script' );
    $optionsJs = $validOptionsJs;

    $GLOBALS['enqueue'] = 0;
    \PixelYourSite\PYS()->manager->noscript = $fragment;
    YSCachePixel::dynamicResponse( 'image' );
    expect( $GLOBALS['status'] === 302 && in_array( 'Location: ' . $source, $GLOBALS['headers'], true ),
        'Pixel is the fresh native, strictly whitelisted destination' );
    $GLOBALS['enqueue'] = 0;
    \PixelYourSite\PYS()->manager->noscript = '';
    YSCachePixel::dynamicResponse( 'image' );
    expect( $GLOBALS['status'] === 204, 'Native denial leaves the pixel empty' );

    YSCachePixel::restoreBrowserHome();
    expect( $GLOBALS['inline'] === [], 'Normal pages do not modify the browser URL' );
    define( 'YS_CACHE_PIXEL_FALLBACK', true );
    YSCachePixel::restoreBrowserHome();
    [ $script, $attributes ] = $GLOBALS['inline'][0];
    expect( str_contains( $script, "location.search==='?ys_cache_pixel_fallback=1'" ) &&
        str_contains( $script, "history.replaceState(history.state,'','/'+location.hash)" ) &&
        $attributes['id'] === 'ys-cache-pixel-fallback-url', 'The trusted fallback restores the browser landing URL before PYS' );

    echo "PixelYourSite direct-home shell and native private response tests passed.\n";
}
