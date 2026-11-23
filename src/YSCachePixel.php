<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Splits the reviewed PixelYourSite direct-home profile into a shell and private requests.
 * The caller must authorize those requests and restore the original homepage context before dispatch.
 */
final class YSCachePixel {
    public const SCRIPT_URL = '/__ys-cache-pixel-script__';
    public const PIXEL_URL = '/__ys-cache-pixel-image__';

    private const PYS_VERSION = '12.6.8';
    private const EVENT_NAMES = [
        'internal_link', 'outbound_link', 'video', 'tel_link', 'email_link',
        'form', 'download', 'comment', 'adsense',
    ];
    private const FACTORIES = [
        'PixelYourSite\\EventsAutomatic', 'PixelYourSite\\EventsCustom', 'PixelYourSite\\EventsEdd',
        'PixelYourSite\\EventsFdp', 'PixelYourSite\\EventsWcf', 'PixelYourSite\\EventsWoo',
        'PixelYourSite\\AwinSaleEvents',
    ];
    private const META_FRAGMENT = '~<noscript>\s*<img height="1" width="1" style="display: none;" src="([^"]+)" alt="">\s*</noscript>~i';
    private const SITE_KIT_FRAGMENT = '~\A\s*<iframe src="https://www\.googletagmanager\.com/ns\.html\?id=GTM-[A-Z0-9]+" height="0" width="0" style="display\s*:\s*none;\s*visibility\s*:\s*hidden;?"></iframe>\s*\z~iD';
    private const XOO_FORMS = [
        'login' => [ 'xoo-el-username' => 'text', 'xoo-el-password' => 'password', 'xoo-el-rememberme' => 'checkbox',
            '_xoo_el_form' => 'hidden', 'xoo_el_redirect' => 'hidden' ],
        'register' => [ 'xoo_el_reg_username' => 'text', 'xoo_el_reg_email' => 'email', 'xoo_el_reg_fname' => 'text',
            'xoo_el_reg_pass' => 'password', 'xoo_el_reg_pass_again' => 'password', '_xoo_el_form' => 'hidden',
            'xoo_el_redirect' => 'hidden' ],
        'lostpw' => [ 'user_login' => 'text', '_xoo_el_form' => 'hidden', '_wp_http_referer' => 'hidden' ],
    ];

    public static function eligible(): bool {
        $warm = class_exists( YSCachePixelWarm::class ) && YSCachePixelWarm::isVerified();
        if ( ! defined( 'PYS_VERSION' ) || PYS_VERSION !== self::PYS_VERSION || ! function_exists( 'PixelYourSite\\PYS' ) ||
            did_action( 'wp' ) !== 1 || ! is_front_page() || ! is_singular() || is_user_logged_in() ||
            is_preview() || post_password_required() || wp_is_mobile() || ! is_ssl() ||
            ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || ( $_SERVER['REQUEST_URI'] ?? '' ) !== '/' ||
            ( $_SERVER['HTTP_HOST'] ?? '' ) !== parse_url( home_url( '/' ), PHP_URL_HOST ) ||
            ! empty( $_SERVER['HTTP_REFERER'] ) || ! empty( $_GET ) || ! empty( $_SERVER['QUERY_STRING'] ) ||
            ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ||
            isset( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['HTTP_RANGE'] ) ||
            ( $warm ? ! empty( $_SESSION ) || session_status() === PHP_SESSION_ACTIVE :
                session_status() !== PHP_SESSION_ACTIVE || ! YSCacheIntegrations::hasOnlyAttributionSession() ||
                ( $_SESSION['TrafficSource'] ?? '' ) !== 'direct' || ( $_SESSION['LandingPage'] ?? '' ) !== home_url( '/' ) ||
                ( $_SESSION['TrafficUtms'] ?? null ) !== [] || ( $_SESSION['TrafficUtmsId'] ?? null ) !== [] ) ) {
            return false;
        }

        $plugin = \PixelYourSite\PYS();
        $manager = $plugin->getEventsManager();
        if ( ! $plugin->getOption( 'server_static_event_use_ajax' ) || ! $plugin->getOption( 'server_event_use_ajax' ) ||
            $plugin->getOption( 'fetch_user_data_via_rest' ) || $plugin->getOption( 'session_disable' ) ||
            ( $plugin->getOption( 'custom_events_enabled' ) &&
                ( ! function_exists( 'PixelYourSite\\EventsCustom' ) || \PixelYourSite\EventsCustom()->getEvents() ) ) ||
            $plugin->is_user_agent_bot() || $plugin->isCachePreload() ||
            has_action( 'wp_enqueue_scripts', [ $manager, 'enqueueScripts' ] ) !== 10 ||
            has_action( 'wp_enqueue_scripts', [ $manager, 'setupEventsParams' ] ) !== 14 ||
            has_action( 'wp_enqueue_scripts', [ $manager, 'outputData' ] ) !== 15 ||
            has_action( 'wp_footer', [ $manager, 'outputNoScriptData' ] ) !== 10 ) {
            return false;
        }

        global $wp_filter;
        foreach ( $wp_filter as $name => $hook ) {
            if ( preg_match( '~\A(?:cm_google_consent_mode|pys_(?:disable_.*|disabled_.*|.*_mode|.*advanced_matching|'
                . 'pbid_fingerprint_parts|render_visitor_pii_in_html|dynamic_user_data_keys|skip_session|record_visit_data))\z~D', $name )
                && has_filter( $name ) ) {
                $priorities = array_values( $hook->callbacks ?? [] );
                $warmCallback = $warm && $name === 'pys_skip_session' && count( $priorities ) === 1 &&
                    count( $priorities[0] ) === 1 &&
                    array_values( $priorities[0] )[0]['function'] === [ YSCachePixelWarm::class, 'skipSession' ];
                if ( ! $warmCallback ) {
                    return false;
                }
            }
        }
        foreach ( $wp_filter['pys_event_factory']->callbacks ?? [] as $callbacks ) {
            foreach ( $callbacks as $entry ) {
                $handler = $entry['function'];
                if ( ! is_array( $handler ) || ! is_object( $handler[0] ?? null ) ||
                    ( $handler[1] ?? '' ) !== 'register' || ! in_array( get_class( $handler[0] ), self::FACTORIES, true ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Admit a completed sterile origin render and retain only the visitor-neutral shell. */
    public static function shell( string $html ): ?string {
        // PYS prints this static observer even when no late event payload was emitted.
        $trackingMarkup = str_replace( "document.getElementById('pys_late_event')", '', $html );
        if ( ! class_exists( YSCachePixelWarm::class ) || ! YSCachePixelWarm::isVerified() ||
            ! self::eligible() || strlen( $html ) > 1048576 ||
            stripos( $html, self::SCRIPT_URL ) !== false || stripos( $html, self::PIXEL_URL ) !== false ||
            ! preg_match( '~\A\s*(?:<!doctype\s+html>\s*)?<html\b~i', $html ) ||
            ! preg_match( '~</html\s*>\s*\z~iD', $html ) ||
            preg_match( '~<(?:script|style)\b[^>]*\bnonce\s*=|\b(?:pys_late_event|pysWooProductData|ajaxPageInit)\b~i', $trackingMarkup ) ||
            ( preg_match( '~<form\b~i', $html ) && ! self::knownForms( $html ) ) ) {
            return null;
        }

        $script = '~<script id="pys-js-extra">([^<]*)</script>~i';
        if ( preg_match_all( $script, $html, $scripts ) !== 1 ||
            preg_match_all( self::META_FRAGMENT, $html, $pixels ) !== 1 ) {
            return null;
        }
        $options = self::options( $scripts[1][0] );
        if ( $options === null || self::metaDestination( $pixels[1][0], $options ) === null ) {
            return null;
        }

        $shell = str_replace( [ $scripts[0][0], $pixels[0][0] ], [
            '<script id="ys-cache-pys-bootstrap" src="' . self::SCRIPT_URL . '" referrerpolicy="strict-origin-when-cross-origin"></script>',
            '<noscript><img height="1" width="1" style="display: none;" src="' . self::PIXEL_URL .
                '" referrerpolicy="strict-origin-when-cross-origin" alt=""></noscript>',
        ], $html );
        if ( ! YSCacheIntegrations::cacheableHtml( $shell ) ||
            preg_match( '~\b(?:pysOptions|pys_late_event|userData|guestToken)\s*[:=]|'
                . '\b(?:external_id|visitor_id|client_id|pys_visitor_key)\s*["\']?\s*[:=]~i', $shell ) ||
            preg_match( '~\bid\s*=\s*["\']pys-js-extra["\']~i', $shell ) ||
            preg_match_all( '~\bid\s*=\s*["\']ys-cache-pys-bootstrap["\']~i', $shell ) !== 1 ||
            preg_match_all( '~<noscript\b[^>]*>(.*?)</noscript>~is', $shell, $noScripts ) === false ) {
            return null;
        }
        foreach ( $noScripts[1] as $fragment ) {
            if ( ! str_contains( $fragment, self::PIXEL_URL ) && ! self::knownNoScript( $fragment ) ) {
                return null;
            }
        }
        return $shell;
    }

    /**
     * Run the native enqueue actions exactly once on a credentialed homepage request.
     * The caller exits after dispatch.
     */
    public static function dynamicResponse( string $kind ): void {
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
        header( 'X-Accel-Expires: 0', true );
        header( 'Cross-Origin-Resource-Policy: same-origin', true );
        header( 'X-Content-Type-Options: nosniff', true );

        if ( ! in_array( $kind, [ 'script', 'image' ], true ) || ! self::eligible() || did_action( 'wp_enqueue_scripts' ) ||
            ( class_exists( YSCachePixelWarm::class ) && YSCachePixelWarm::isVerified() ) ) {
            self::unavailable( $kind );
            return;
        }
        ob_start();
        try {
            wp_enqueue_scripts();
        } finally {
            $output = ob_get_clean();
        }
        $data = wp_scripts()->get_data( 'pys', 'data' );
        $options = is_string( $data ) ? self::options( $data ) : null;
        if ( ! is_string( $output ) || trim( $output ) !== '' || $options === null ) {
            self::unavailable( $kind );
            return;
        }
        if ( $kind === 'script' ) {
            header( 'Content-Type: application/javascript; charset=UTF-8', true );
            echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native validated JSON assignment.
            return;
        }

        ob_start();
        try {
            \PixelYourSite\PYS()->getEventsManager()->outputNoScriptData();
        } finally {
            $html = trim( ob_get_clean() );
        }
        if ( $html === '' ) {
            http_response_code( 204 );
            return;
        }
        if ( ! preg_match( self::META_FRAGMENT, $html, $pixel ) || $pixel[0] !== $html ||
            ( $destination = self::metaDestination( $pixel[1], $options ) ) === null ) {
            http_response_code( 409 );
            return;
        }
        header( 'Location: ' . $destination, true, 302 );
    }

    private static function unavailable( string $kind ): void {
        if ( $kind === 'script' ) {
            // This method is reached only after the same-origin endpoint authorization.
            YSCachePixelTransport::sendFallback();
            return;
        }
        http_response_code( 409 );
    }

    public static function restoreBrowserHome(): void {
        if ( ! defined( 'YS_CACHE_PIXEL_FALLBACK' ) || ! YS_CACHE_PIXEL_FALLBACK ) {
            return;
        }
        wp_print_inline_script_tag(
            "if(location.pathname==='/'&&location.search==='?ys_cache_pixel_fallback=1'){history.replaceState(history.state,'','/'+location.hash);}",
            [ 'id' => 'ys-cache-pixel-fallback-url' ]
        );
    }

    private static function options( string $text ): ?array {
        if ( strlen( $text ) > 65536 || ! preg_match(
            '~\A\s*var pysOptions\s*=\s*({[^<]*});\s*(?://# sourceURL=pys-js-extra\s*)?\z~D',
            $text,
            $parts
        ) ) {
            return null;
        }
        $data = json_decode( $parts[1], true );
        if ( ! is_array( $data ) || ! self::hasKeys( $data['staticEvents'] ?? null, [ 'facebook' ] ) ||
            ! self::hasKeys( $data['staticEvents']['facebook'], [ 'init_event' ] ) ||
            ! self::hasKeys( $data['staticEvents']['facebook']['init_event'], [ 0 ] ) ||
            ( $data['ajaxForServerStaticEvent'] ?? '' ) !== '1' || ( $data['ajaxForServerEvent'] ?? '' ) !== '1' ||
            ( $data['user_id'] ?? '' ) !== '0' || ! empty( $data['dynamicDataUrl'] ) || ! empty( $data['triggerEvents'] ) ||
            ! empty( $data['facebook']['advancedMatchingEnabled'] ) || ( $data['facebook']['advancedMatching'] ?? null ) !== [] ||
            ( $data['tracking_analytics']['userData'] ?? null ) !== [ 'emails' => [], 'phones' => [], 'addresses' => [] ] ||
            ! self::hasKeys( $data['dynamicEvents'] ?? null, array_map(
                static fn( string $name ): string => 'automatic_event_' . $name,
                self::EVENT_NAMES
            ) ) ) {
            return null;
        }
        $pageView = $data['staticEvents']['facebook']['init_event'][0];
        if ( ( $pageView['name'] ?? '' ) !== 'PageView' || ! self::validUuid( $pageView['eventID'] ?? null ) ) {
            return null;
        }
        $ids = [ $pageView['eventID'] ];
        foreach ( $data['dynamicEvents'] as $event ) {
            if ( ! self::hasKeys( $event, [ 'facebook', 'ga', 'google_ads' ] ) ) {
                return null;
            }
            $id = $event['facebook']['eventID'] ?? null;
            if ( ! self::validUuid( $id ) || in_array( $id, $ids, true ) ||
                ( $event['ga']['eventID'] ?? null ) !== $id || ( $event['google_ads']['eventID'] ?? null ) !== $id ) {
                return null;
            }
            $ids[] = $id;
        }
        return $data;
    }

    private static function hasKeys( $data, array $keys ): bool {
        return is_array( $data ) && array_keys( $data ) === $keys;
    }

    private static function knownNoScript( string $fragment ): bool {
        $blocksyCss = preg_quote( home_url( '/wp-content/themes/blocksy/static/bundle/no-scripts.min.css' ), '~' );
        return preg_match( self::SITE_KIT_FRAGMENT, $fragment ) ||
            preg_match( '~\A\s*<link rel=[\'"]stylesheet[\'"] href=[\'"]' . $blocksyCss .
                '[\'"] type=[\'"]text/css[\'"]\s*/?>\s*\z~D', $fragment ) ||
            preg_match( '~\A\s*<style>\s*\.woocommerce-product-gallery\s*\{\s*opacity:\s*1\s*!important;\s*}\s*</style>\s*\z~D', $fragment );
    }

    private static function knownForms( string $html ): bool {
        if ( ! defined( 'XOO_EL_VERSION' ) || XOO_EL_VERSION !== '4.0.4' ||
            ! class_exists( '\\DOMDocument' ) || has_filter( 'xoo_el_localize_data' ) ||
            ! preg_match_all( '~<form\b[^>]*>.*?</form\s*>~is', $html, $forms ) ||
            preg_match_all( '~<form\b~i', $html ) !== count( $forms[0] ) || count( $forms[0] ) > 6 ) {
            return false;
        }
        foreach ( $forms[0] as $markup ) {
            $document = new \DOMDocument();
            if ( ! $document->loadHTML( '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $markup .
                '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ||
                $document->getElementsByTagName( 'form' )->length !== 1 ) {
                return false;
            }
            $form = $document->getElementsByTagName( 'form' )->item( 0 );
            if ( $form->attributes->length !== 1 || ! preg_match(
                '~\Axoo-el-action-form xoo-el-form-(login|register|lostpw)\z~D',
                $form->getAttribute( 'class' ),
                $type
            ) ) {
                return false;
            }
            $fields = self::XOO_FORMS[ $type[1] ];
            foreach ( $form->getElementsByTagName( '*' ) as $tag ) {
                if ( ! in_array( $tag->tagName, [ 'div', 'label', 'span', 'a', 'input', 'button', 'strong', 'em', 'i', 'small', 'br', 'p' ], true ) ) {
                    return false;
                }
                foreach ( $tag->attributes as $attribute ) {
                    if ( ! in_array( $attribute->name, [ 'class', 'id', 'for', 'href', 'rel', 'name', 'value', 'type',
                        'placeholder', 'required', 'autocomplete', 'minlength', 'maxlength', 'aria-label', 'aria-hidden',
                    ], true ) ) {
                        return false;
                    }
                }
                if ( $tag->tagName !== 'input' ) {
                    if ( $tag->hasAttribute( 'name' ) || $tag->hasAttribute( 'value' ) ||
                        ( $tag->hasAttribute( 'href' ) && ! in_array( $tag->getAttribute( 'href' ), [ '', '#', '/', home_url( '/' ) ], true ) ) ) {
                        return false;
                    }
                    continue;
                }
                $name = $tag->getAttribute( 'name' );
                if ( ! isset( $fields[ $name ] ) || $tag->getAttribute( 'type' ) !== $fields[ $name ] ) {
                    return false;
                }
                $value = $tag->getAttribute( 'value' );
                $expected = match ( $name ) {
                    '_xoo_el_form' => [ $type[1] === 'lostpw' ? 'lostPassword' : $type[1] ],
                    '_wp_http_referer' => [ '/' ],
                    'xoo_el_redirect' => [ '/', home_url( '/' ) ],
                    'xoo-el-rememberme' => [ 'forever' ],
                    default => [ '' ],
                };
                if ( ! in_array( $value, $expected, true ) || $tag->hasAttribute( 'checked' ) ) {
                    return false;
                }
                unset( $fields[ $name ] );
            }
            if ( $fields ) {
                return false;
            }
        }
        return self::knownFormScripts( $html );
    }

    private static function knownFormScripts( string $html ): bool {
        $contracts = [
            'xoo-el-js-js-extra' => [ 'xoo_el_localize', [
                'adminurl', 'redirectDelay', 'html', 'autoOpenPopup', 'autoOpenPopupOnce', 'aoDelay', 'loginClass',
                'registerClass', 'errorLog', 'resetPwPattern', 'resend_wait', 'preventClosing', 'hasCodeForms', 'isLoggedIn', 'checkout',
            ] ],
            'xoo-aff-js-js-extra' => [ 'xoo_aff_localize', [ 'adminurl', 'password_strength' ] ],
        ];
        foreach ( $contracts as $id => [ $variable, $keys ] ) {
            if ( preg_match_all( '~<script id="' . $id . '">\s*var ' . $variable .
                '\s*=\s*({[^\r\n]*?});\s*(?://# sourceURL=' . $id . '\s*)?</script>~', $html, $scripts ) !== 1 ) {
                return false;
            }
            $data = json_decode( $scripts[1][0], true );
            if ( ! self::hasKeys( $data, $keys ) || $data['adminurl'] !== admin_url( 'admin-ajax.php' ) ||
                preg_match( '~"(?:[^"]*nonce|[^"]*token|[^"]*session|user_?id)"\s*:~i', $scripts[1][0] ) ) {
                return false;
            }
            if ( $id === 'xoo-el-js-js-extra' &&
                ( $data['isLoggedIn'] !== 'no' || ! in_array( $data['hasCodeForms'], [ '', '0', false ], true ) ||
                    ! self::hasKeys( $data['html'], [ 'spinner', 'editField', 'notice' ] ) ||
                    ! self::hasKeys( $data['html']['notice'], [ 'error', 'success' ] ) ||
                    ! self::hasKeys( $data['checkout'], [ 'loginEnabled', 'loginRedirect' ] ) ||
                    $data['checkout']['loginRedirect'] !== '/' ) ) {
                return false;
            }
            if ( $id === 'xoo-aff-js-js-extra' && ! self::hasKeys( $data['password_strength'], [
                'min_password_strength', 'i18n_password_error', 'i18n_password_hint',
            ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function validUuid( $id ): bool {
        return is_string( $id ) && preg_match( '~\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z~iD', $id ) === 1;
    }

    private static function metaDestination( string $encoded, array $options ): ?string {
        $url = html_entity_decode( $encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $prefix = 'https://www.facebook.com/tr?';
        if ( ! str_starts_with( $url, $prefix ) || preg_match( '~[\x00-\x20\x7f#\\\\]~', $url ) ) {
            return null;
        }
        $query = [];
        foreach ( explode( '&', substr( $url, strlen( $prefix ) ) ) as $parameter ) {
            $pair = explode( '=', $parameter, 2 );
            $key = urldecode( $pair[0] );
            if ( count( $pair ) !== 2 || isset( $query[ $key ] ) ) {
                return null;
            }
            $query[ $key ] = urldecode( $pair[1] );
        }
        $event = $options['staticEvents']['facebook']['init_event'][0];
        $id = $query['id'] ?? '';
        if ( ! preg_match( '~\A[0-9]{4,30}\z~D', $id ) ||
            ! is_array( $event['pixelIds'] ?? null ) || count( $event['pixelIds'] ) !== 1 ||
            ! is_scalar( array_values( $event['pixelIds'] )[0] ) ||
            (string) array_values( $event['pixelIds'] )[0] !== $id || ( $query['ev'] ?? '' ) !== 'PageView' ||
            ( $query['noscript'] ?? '' ) !== '1' || ( $query['eid'] ?? '' ) !== $id . $event['eventID'] ) {
            return null;
        }
        $fields = [ 'event_url', 'page_title', 'plugin', 'post_id', 'post_type', 'user_role' ];
        $params = $event['params'] ?? [];
        if ( count( $query ) !== 4 + count( $fields ) || ! is_array( $params ) || count( $params ) !== count( $fields ) ) {
            return null;
        }
        foreach ( $fields as $field ) {
            $value = $query[ 'cd[' . $field . ']' ] ?? null;
            if ( ! is_string( $value ) || ! is_scalar( $params[ $field ] ?? null ) ||
                ( $value !== (string) $params[ $field ] && urldecode( $value ) !== (string) $params[ $field ] ) ) {
                return null;
            }
        }
        return $url;
    }
}
