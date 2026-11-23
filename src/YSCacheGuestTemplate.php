<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

/** Reuses the public Blocksy main region. WordPress still renders the header, footer and tracking for this visitor. */
final class YSCacheGuestTemplate {
    private static array $capture = [];
    private static array $replay = [];

    public static function allowsAttributionCookie( string $name, $value ): bool {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
            return false;
        }
        if ( $name === YSCachePixelTransport::COOKIE ) {
            return preg_match( '/\A[A-Za-z0-9.-]{1,128}\z/D', $value ) === 1;
        }
        if ( $name === 'woocommerce_recently_viewed' ) {
            // The reader must additionally match a reviewed home document. No browsing history enters storage.
            return is_front_page() && is_singular( 'page' ) && preg_match( '/\A[1-9][0-9]{0,10}(?:\|[1-9][0-9]{0,10}){0,29}\z/D', $value ) === 1;
        }
        if ( $name === 'pys_cd' ) {
            $host = YSCacheEarly::host();
            return in_array( strtolower( $value ), [ 'host-only', $host, preg_replace( '/^www\./', '', $host ) ], true );
        }
        if ( $name === 'pys_event_referrer' ) {
            if ( $value === '' ) {
                return true;
            }
            $ref = wp_parse_url( $value );
            return is_array( $ref ) && ( $ref['scheme'] ?? '' ) === 'https' &&
                strtolower( $ref['host'] ?? '' ) === YSCacheEarly::host() && ! isset( $ref['user'] ) &&
                ! isset( $ref['pass'] ) && ! isset( $ref['port'] ) && ! isset( $ref['query'] ) && ! isset( $ref['fragment'] );
        }
        if ( $name === 'select_prod_list' ) {
            if ( ! is_singular( 'product' ) ) {
                return false;
            }
            $list = json_decode( wp_unslash( $value ), true );
            if ( ! is_array( $list ) || array_diff( array_keys( $list ), [ 'list_name', 'list_id', 'url' ] ) ||
                ! is_string( $list['url'] ?? null ) || strlen( $list['url'] ) > 1024 ) {
                return false;
            }
            $source = wp_parse_url( $list['url'] );
            $path = $source['path'] ?? '/';
            if ( ! is_array( $source ) || ( $source['scheme'] ?? '' ) !== 'https' || strtolower( $source['host'] ?? '' ) !== YSCacheEarly::host() ||
                isset( $source['user'] ) || isset( $source['pass'] ) || isset( $source['port'] ) || isset( $source['query'] ) || isset( $source['fragment'] ) ||
                ! is_string( $path ) || str_contains( $path, '//' ) || preg_match( '~[?#\\\\\x00-\x20]|(?:^|/)\.{1,2}(?:/|$)~', rawurldecode( $path ) ) ||
                $list['url'] !== ( $_SERVER['HTTP_REFERER'] ?? '' ) ) {
                return false;
            }
            foreach ( [ 'list_name', 'list_id' ] as $field ) {
                if ( isset( $list[ $field ] ) && ( ! is_string( $list[ $field ] ) || strlen( $list[ $field ] ) > 150 ||
                    ! preg_match( '~\A[\pL\pN\pM .,&_()/-]*\z~uD', $list[ $field ] ) ) ) {
                    return false;
                }
            }
            return true;
        }
        return YSCacheIntegrations::isElementorAttributionCookie( $name ) ||
            in_array( $name, [ '_ga', '_gid', '_gcl_au', 'sbjs_migrations', 'sbjs_current_add', 'sbjs_first_add',
                'sbjs_current', 'sbjs_first', 'sbjs_udata', 'sbjs_session' ], true ) ||
            preg_match( '/\A_ga_[A-Z0-9]{4,20}\z/D', $name ) === 1;
    }

    /** A synthetic renderer has no browsing history. Native shoppers still run both vendors' normal callbacks. */
    public static function neutralProductView(): void {
        if ( ! YSCachePixelWarm::isTemplateWarm() || ! is_singular( 'product' ) || $_COOKIE || ! empty( $_SESSION ) ) {
            return;
        }
        remove_action( 'template_redirect', 'wc_track_product_view', 20 );
        global $wp_filter;
        foreach ( $wp_filter['template_redirect']->callbacks[20] ?? [] as $entry ) {
            $callback = $entry['function'] ?? null;
            $known = [ 'Blocksy\RecentlyViewedProducts' => 'track_product_view', 'Jet_Woo_Builder_Woocommerce' => 'set_track_product_view' ];
            if ( is_array( $callback ) && is_object( $callback[0] ?? null ) &&
                ( $known[ get_class( $callback[0] ) ] ?? null ) === ( $callback[1] ?? '' ) ) {
                remove_action( 'template_redirect', $callback, 20 );
            }
        }
    }

    public static function isPublicRoute( string $path ): bool {
        if ( get_template() !== 'blocksy' || is_404() || is_search() || is_paged() || is_preview() ||
            is_feed() || is_embed() || is_trackback() || is_customize_preview() || is_user_logged_in() || post_password_required() ) {
            return false;
        }
        $object = get_queried_object();
        if ( is_front_page() && is_singular( 'page' ) && $object instanceof \WP_Post && $object->post_status === 'publish' ) {
            $url = home_url( '/' );
        } elseif ( is_singular( 'product' ) && $object instanceof \WP_Post && $object->post_status === 'publish' &&
            function_exists( 'wc_get_product' ) && ( $product = wc_get_product( $object->ID ) ) && $product->is_visible() ) {
            $url = get_permalink( $object );
        } elseif ( is_tax( 'product_cat' ) && $object instanceof \WP_Term && $object->taxonomy === 'product_cat' ) {
            $url = get_term_link( $object );
        } else {
            return false;
        }
        return is_string( $url ) && rawurldecode( $url ) === rawurldecode( home_url( $path ) ) &&
            $path === ( $_SERVER['REQUEST_URI'] ?? '' ) && $path !== '' && $path[0] === '/' &&
            ! preg_match( '~[?#\\\\\x00-\x20]|//|(?:^|/)\.{1,2}(?:/|$)|%(?:2f|5c|25)~i', $path );
    }

    private static function publicContext(): ?array {
        $language = YSCacheIntegrations::languageVariant();
        if ( $language === null || ! YSCacheIntegrations::hasOnlyAttributionSession() || $_GET || $_POST ||
            ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || ! empty( $_SERVER['QUERY_STRING'] ) ) {
            return null;
        }
        $referer = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
        if ( $referer !== '' ) {
            $url = wp_parse_url( $referer );
            if ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https' ||
                strtolower( $url['host'] ?? '' ) !== YSCacheEarly::host() || ! empty( $url['user'] ) ||
                isset( $url['port'] ) || isset( $url['pass'] ) || ! empty( $url['query'] ) ) {
                return null;
            }
        }
        foreach ( $_COOKIE as $name => $value ) {
            if ( ! is_string( $name ) || ! self::allowsAttributionCookie( $name, $value ) ) {
                return null;
            }
        }
        if ( ! $_COOKIE && ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
            return null;
        }
        $woocommerce = function_exists( 'WC' ) ? WC() : null;
        if ( $woocommerce && ( ! function_exists( 'wc_notice_count' ) || ! function_exists( 'get_woocommerce_currency' ) ||
            ! in_array( get_option( 'woocommerce_default_customer_address', 'base' ), [ '', 'base' ], true ) ||
            ( $woocommerce->session && ( ! is_callable( [ $woocommerce->session, 'has_session' ] ) || $woocommerce->session->has_session() ) ) ||
            ( $woocommerce->cart && ( ! is_callable( [ $woocommerce->cart, 'is_empty' ] ) || ! $woocommerce->cart->is_empty() ) ) || wc_notice_count() ||
            get_woocommerce_currency() !== get_option( 'woocommerce_currency' ) ) ) {
            return null;
        }
        // Country/currency integrations can change anonymous catalog output before WordPress sets a session cookie.
        if ( defined( 'WOOCS_VERSION' ) || defined( 'WCML_VERSION' ) || defined( 'AELIA_CS_VERSION' ) ||
            defined( 'WOOMULTI_CURRENCY_VERSION' ) || defined( 'WC_PRICE_BASED_COUNTRY_VERSION' ) ) {
            return null;
        }
        $customer = $woocommerce ? $woocommerce->customer : null;
        $address = $customer ? [ $customer->get_billing_country(), $customer->get_shipping_country(),
            $customer->get_billing_state(), $customer->get_shipping_state() ] : [];
        if ( $customer && ( $customer->get_id() || $customer->get_billing_email() || $customer->get_billing_postcode() ||
            $customer->get_shipping_postcode() || $customer->get_billing_city() || $customer->get_shipping_city() ) ) {
            return null;
        }
        $countries = [];
        foreach ( [ 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE' ] as $key ) {
            if ( isset( $_SERVER[ $key ] ) ) {
                if ( ! is_string( $_SERVER[ $key ] ) || ! preg_match( '/\A[A-Z0-9]{2}\z/D', $_SERVER[ $key ] ) ) {
                    return null;
                }
                $countries[ $key ] = $_SERVER[ $key ];
            }
        }
        return [ $language, get_locale(), $woocommerce ? get_woocommerce_currency() : '', $address, $countries ];
    }

    private static function key( string $path, array $profile ): string {
        return hash( 'sha256', serialize( [ 'native-main-v3', YS_CACHE_VERSION, $path,
            YSCacheEarlyStore::requestVariant( $profile ), YSCacheSettingsRepo::pageGenerations( $path ),
            get_stylesheet(), wp_get_theme()->get( 'Version' ), get_option( 'active_plugins', [] ),
            defined( 'WC_VERSION' ) ? WC_VERSION : '', defined( 'PYS_VERSION' ) ? PYS_VERSION : '',
            defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ] ) );
    }

    public static function hasEntry( array $settings, string $path, array $profile ): bool {
        $directory = YSCacheStorage::directory();
        $ttl = YSCache::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'] ?? '', $path );
        $key = self::key( $path, $profile );
        $raw = $directory === '' ? null : YSCacheStorage::read( $directory, $key, $ttl );
        return is_string( $raw ) && self::validRecord( json_decode( $raw, true ) ) &&
            YSCacheStorage::read( $directory, hash( 'sha256', 'review:' . $key ), min( 600, $ttl ) ) !== hash( 'sha256', $raw );
    }

    private static function supportedTemplate( string $template ): bool {
        $parent = get_template_directory();
        $allowed = [ $parent . '/single.php', $parent . '/page.php' ];
        if ( defined( 'WC_ABSPATH' ) ) {
            $allowed[] = WC_ABSPATH . 'templates/archive-product.php';
            $allowed[] = WC_ABSPATH . 'templates/single-product.php';
            if ( function_exists( 'wc_locate_template' ) && wc_locate_template( 'archive-product.php' ) === WC_ABSPATH . 'templates/archive-product.php' ) {
                $allowed[] = WC_ABSPATH . 'templates/taxonomy-product-cat.php';
            }
        }
        if ( defined( 'ELEMENTOR_PATH' ) ) {
            $allowed[] = ELEMENTOR_PATH . 'modules/page-templates/templates/header-footer.php';
        }
        return in_array( $template, $allowed, true ) &&
            locate_template( [ 'header-shop.php', 'header.php' ] ) === $parent . '/header.php' &&
            locate_template( [ 'footer-shop.php', 'footer.php' ] ) === $parent . '/footer.php';
    }

    /** Call at template_include only after the application's path, cookie, header and page exclusion checks. */
    public static function route( string $template, array $settings, string $path, array $profile, bool $neutral ): string {
        if ( has_filter( 'ys_cache_allow_html' ) || ! self::isPublicRoute( $path ) || ! self::supportedTemplate( $template ) ||
            ( $neutral && ( ! YSCachePixelWarm::isTemplateWarm() || $_COOKIE || ! empty( $_SESSION ) || session_status() === PHP_SESSION_ACTIVE ) ) ) {
            return $template;
        }
        $context = self::publicContext();
        $directory = YSCacheStorage::directory();
        if ( $context === null || $directory === '' ) {
            return $template;
        }
        $key = self::key( $path, $profile );
        $stamp = [ $context, $template, filemtime( $template ), get_queried_object_id() ];
        if ( $neutral ) {
            self::$capture = compact( 'directory', 'key', 'path', 'profile', 'stamp' );
            add_action( 'get_header', [ self::class, 'onHeader' ], PHP_INT_MIN, 2 );
            add_action( 'wp_after_load_template', [ self::class, 'afterTemplate' ], PHP_INT_MAX, 1 );
            add_action( 'get_footer', [ self::class, 'onFooter' ], PHP_INT_MIN, 2 );
            add_filter( 'pre_do_shortcode_tag', [ self::class, 'observeShortcode' ], PHP_INT_MIN, 3 );
        } else {
            $ttl = YSCache::pathLifetime( $settings['ttl_seconds'], $settings['ttl_paths'] ?? '', $path );
            $cached = YSCacheStorage::read( $directory, $key, $ttl );
            $record = is_string( $cached ) ? json_decode( $cached, true ) : null;
            $history = isset( $_COOKIE['woocommerce_recently_viewed'] );
            if ( self::validRecord( $record ) && $record['stamp'] === $stamp && ( ! $history ||
                ( is_string( $record['history'] ?? null ) && $record['history'] === self::historySignature( false ) ) ) ) {
                if ( self::catalogPrices( array_keys( $record['prices'] ) ) === $record['prices'] ) {
                    self::$replay = $record;
                    header( 'X-YS-Cache: HIT' );
                    header( 'X-YS-Cache-Engine: native-template' );
                    header( 'X-YS-Cache-Scope: public-main' );
                    header( 'Cache-Control: private, no-store, max-age=0' );
                    header( 'CDN-Cache-Control: no-store' );
                    return YS_CACHE_DIR . 'templates/guest-template.php';
                }
                // A public catalog entry may change outside the normal stock hooks. Record only the old receipt digest.
                // A visitor-specific price cannot populate the new entry or start a remote call inside this request.
                $hint = hash( 'sha256', 'review:' . $key );
                if ( YSCacheStorage::read( $directory, $hint, min( 600, $ttl ) ) === null ) {
                    YSCacheStorage::write( $directory, $hint, hash( 'sha256', $cached ) );
                }
            }
            YSCachePixelWarm::scheduleTemplate( $settings, $path );
        }
        return $template;
    }

    public static function onHeader( $name, array $args ): void {
        if ( ! self::$capture || isset( self::$capture['header'] ) || $args || ! in_array( $name, [ null, '', 'shop' ], true ) ) {
            self::$capture = [];
            return;
        }
        self::$capture['header'] = (string) $name;
    }

    public static function observeShortcode( $value, string $tag, $args ) {
        if ( ! isset( self::$capture['level'] ) || isset( self::$capture['record'] ) || self::$capture['path'] !== '/' ) {
            return $value;
        }
        global $shortcode_tags;
        $callback = $shortcode_tags[ $tag ] ?? null;
        // Elementor embeds uncached widgets as elementor-element and verifies its own key during dispatch.
        // Inspect the actual widget as well as the stored document before qualifying the body for browsing history.
        if ( $tag === 'elementor-element' && defined( 'ELEMENTOR_PATH' ) && $callback instanceof \Closure &&
            ( new \ReflectionFunction( $callback ) )->getFileName() === ELEMENTOR_PATH . 'modules/element-cache/module.php' &&
            is_array( $args ) && is_string( $args['data'] ?? null ) && strlen( $args['data'] ) <= 260000 ) {
            $raw = base64_decode( $args['data'], true );
            $element = is_string( $raw ) ? json_decode( $raw, true ) : null;
            if ( is_array( $element ) && self::staticHomeElements( [ $element ] ) ) {
                return $value;
            }
        }
        if ( ! isset( self::$capture['shortcode'] ) ) {
            self::$capture['shortcode'] = true;
        }
        return $value;
    }

    /** Only a reviewed static Elementor home may reuse public markup while the header/footer see real history. */
    private static function historySignature( bool $review ): ?string {
        if ( ! is_front_page() || ! is_singular( 'page' ) || get_queried_object_id() !== (int) get_option( 'page_on_front' ) ) {
            return null;
        }
        global $wp_filter;
        foreach ( $wp_filter as $hook => $callbacks ) {
            if ( str_starts_with( $hook, 'jet-woo-builder/shortcodes/' ) && str_ends_with( $hook, 'query-args' ) && has_filter( $hook ) ) {
                return null;
            }
        }
        $id = get_queried_object_id();
        $json = get_post_meta( $id, '_elementor_data', true );
        if ( ! is_string( $json ) || $json === '' || strlen( $json ) > 700000 ) {
            return null;
        }
        $source = (string) get_post_field( 'post_content', $id );
        if ( $review ) {
            $nodes = json_decode( $json, true );
            if ( ! is_array( $nodes ) || ! $nodes || preg_match( '/\[\/?[\w-]+\b[^\]]*\]/u', $source ) ||
                ! self::staticHomeElements( $nodes ) ) {
                return null;
            }
        }
        return hash( 'sha256', $json . "\n" . $source );
    }

    private static function staticHomeElements( array $nodes ): bool {
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) || ! in_array( $node['elType'] ?? '', [ 'section', 'column', 'container', 'widget' ], true ) ) {
                return false;
            }
            $widget = $node['widgetType'] ?? '';
            if ( $node['elType'] === 'widget' && ! in_array( $widget, [ 'image', 'divider', 'heading', 'call-to-action', 'spacer', 'text-editor', 'jet-woo-products' ], true ) ) {
                return false;
            }
            $settings = $node['settings'] ?? [];
            if ( ! is_array( $settings ) ) {
                return false;
            }
            $dynamic = false;
            array_walk_recursive( $settings, static function ( $value, $key ) use ( &$dynamic ) {
                if ( ( preg_match( '/__dynamic__|display_conditions|jet[_-](?:engine|visibility)|query[_-]builder/i', (string) $key ) && $value ) ||
                    ( is_string( $value ) && preg_match( '/recently[-_ ]viewed|\[\/?[a-z_][a-z0-9_-]*\b[^\]]*\]|%(?![a-f0-9]{2}%)[a-z_][a-z0-9_]+%/i', $value ) ) ) {
                    $dynamic = true;
                }
            } );
            if ( $dynamic || ! empty( $settings['__dynamic__'] ) || ! empty( $settings['display_conditions'] ) ) {
                return false;
            }
            if ( $widget === 'jet-woo-products' ) {
                $query = $settings['products_query'] ?? [ 'all' ];
                if ( ! in_array( $query, [ 'all', [ 'all' ] ], true ) || ! empty( $settings['query_id'] ) ||
                    ! empty( $settings['custom_query_id'] ) || ( $settings['use_current_query'] ?? '' ) === 'yes' ||
                    ( $settings['use_custom_query'] ?? '' ) === 'yes' ) {
                    return false;
                }
            }
            if ( ! empty( $node['elements'] ) && ( ! is_array( $node['elements'] ) || ! self::staticHomeElements( $node['elements'] ) ) ) {
                return false;
            }
        }
        return true;
    }

    private static function schema(): array {
        return function_exists( 'WC' ) && is_callable( [ WC()->structured_data, 'get_data' ] ) ? WC()->structured_data->get_data() : [];
    }

    private static function validRecord( $record ): bool {
        if ( ! is_array( $record ) || ! is_array( $record['stamp'] ?? null ) ||
            ! in_array( $record['header'] ?? false, [ '', 'shop' ], true ) || ! is_string( $record['main'] ?? null ) ||
            ! is_array( $record['prices'] ?? null ) || count( $record['prices'] ) > 64 ||
            ! is_array( $record['schema'] ?? null ) || ! is_array( $record['fonts'] ?? null ) ||
            ! is_bool( $record['elementor'] ?? null ) || ! is_array( $record['assets']['scripts'] ?? null ) ||
            ! is_array( $record['assets']['styles'] ?? null ) || ! is_array( $record['definitions']['scripts'] ?? null ) ||
            ! is_array( $record['definitions']['styles'] ?? null ) || $record['main'] === '' ) {
            return false;
        }
        foreach ( $record['prices'] as $id => $digest ) {
            if ( ! is_int( $id ) || $id <= 0 || ! is_string( $digest ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) ) {
                return false;
            }
        }
        foreach ( array_merge( $record['assets']['scripts'], $record['assets']['styles'], $record['fonts'] ) as $name ) {
            if ( ! is_string( $name ) || ! preg_match( '/\A[\w .-]{1,100}\z/uD', $name ) ) {
                return false;
            }
        }
        foreach ( $record['definitions'] as $definitions ) {
            if ( ! is_array( $definitions ) || count( $definitions ) > 100 ) {
                return false;
            }
            foreach ( $definitions as $handle => $spec ) {
                if ( ! is_string( $handle ) || ! preg_match( '/\A[\w.-]{1,100}\z/D', $handle ) ||
                    ! is_array( $spec ) || array_keys( $spec ) !== [ 0, 1, 2, 3, 4 ] ||
                    self::staticDefinition( (object) array_combine( [ 'src', 'deps', 'ver', 'args', 'extra' ], $spec ) ) !== $spec ) {
                    return false;
                }
            }
        }
        foreach ( $record['schema'] as $node ) {
            if ( ! in_array( $node, [ 'Product', 'WebSite' ], true ) && ! self::isBreadcrumb( $node ) ) {
                return false;
            }
        }
        return true;
    }

    /** Changes to an already registered inline payload need their original renderer on every visit. */
    private static function inlineSignatures( $registry ): array {
        $signatures = [];
        foreach ( $registry->registered as $handle => $asset ) {
            $signatures[ $handle ] = hash( 'sha256', serialize( array_intersect_key( (array) $asset->extra,
                [ 'data' => true, 'before' => true, 'after' => true ] ) ) );
        }
        return $signatures;
    }

    private static function staticDefinition( $asset ): ?array {
        $source = $asset->src;
        if ( ! is_string( $source ) || ! preg_match( '#\A(?:https://[a-z0-9.-]+/|/(?!/))[\w./%+@~-]+\.(?:js|css)\z#iD', $source ) ||
            ( $asset->ver !== false && $asset->ver !== null && ( ! is_scalar( $asset->ver ) || ! preg_match( '/\A[\w.+-]{1,80}\z/D', (string) $asset->ver ) ) ) ||
            ( $asset->args !== null && $asset->args !== false && $asset->args !== true &&
                ( ! is_string( $asset->args ) || ! preg_match( '/\A[a-z0-9 ,()-]{0,80}\z/iD', $asset->args ) ) ) ||
            ! is_array( $asset->extra ) ||
            array_diff( array_keys( (array) $asset->extra ), [ 'group', 'strategy', 'rtl', 'suffix', 'conditional' ] ) ||
            ! is_array( $asset->deps ) || count( $asset->deps ) > 50 ) {
            return null;
        }
        foreach ( $asset->extra as $value ) {
            if ( ! is_scalar( $value ) || strlen( (string) $value ) > 100 || preg_match( '/[\x00-\x1f<>]/', (string) $value ) ) {
                return null;
            }
        }
        foreach ( $asset->deps as $dep ) {
            if ( ! is_string( $dep ) || ! preg_match( '/\A[\w.-]{1,100}\z/D', $dep ) ) {
                return null;
            }
        }
        return [ $source, $asset->deps, $asset->ver, $asset->args, $asset->extra ];
    }

    private static function isBreadcrumb( $node ): bool {
        if ( ! is_array( $node ) || ( $node['@type'] ?? null ) !== 'BreadcrumbList' || ! is_array( $node['itemListElement'] ?? null ) ||
            array_diff( array_keys( $node ), [ '@type', 'itemListElement' ] ) || count( $node['itemListElement'] ) > 50 ) {
            return false;
        }
        foreach ( $node['itemListElement'] as $part ) {
            $item = $part['item'] ?? null;
            if ( ! is_array( $part ) || ( $part['@type'] ?? null ) !== 'ListItem' || ! is_int( $part['position'] ?? null ) ||
                array_diff( array_keys( $part ), [ '@type', 'position', 'item' ] ) || ! is_array( $item ) ||
                array_diff( array_keys( $item ), [ '@id', 'name' ] ) || ! is_string( $item['name'] ?? null ) ||
                ( isset( $item['@id'] ) && ( ! is_string( $item['@id'] ) || ! str_starts_with( $item['@id'], home_url( '/' ) ) ||
                    strpbrk( $item['@id'], '?#' ) !== false ) ) ) {
                return false;
            }
        }
        return true;
    }

    public static function afterTemplate( string $file ): void {
        $state = &self::$capture;
        if ( ! $state ) {
            return;
        }
        $parent = get_template_directory();
        if ( $file === $parent . '/header.php' && isset( $state['header'] ) && ! isset( $state['level'] ) ) {
            $state['before'] = [ 'scripts' => wp_scripts()->queue, 'styles' => wp_styles()->queue,
                'inline' => [ 'scripts' => self::inlineSignatures( wp_scripts() ), 'styles' => self::inlineSignatures( wp_styles() ) ],
                'schema' => count( self::schema() ), 'sidebars' => did_action( 'dynamic_sidebar_before' ) ];
            ob_start();
            $state['level'] = ob_get_level();
        } elseif ( $file === $parent . '/footer.php' && isset( $state['record'] ) ) {
            $status = http_response_code();
            if ( ( $status === false || $status === 200 ) && ! $_COOKIE && empty( $_SESSION ) &&
                session_status() !== PHP_SESSION_ACTIVE && self::publicContext() === $state['stamp'][0] &&
                self::key( $state['path'], $state['profile'] ) === $state['key'] ) {
                foreach ( headers_list() as $header ) {
                    if ( preg_match( '~^(?:set-cookie|location|refresh|www-authenticate):~i', $header ) ) {
                        $state = [];
                        return;
                    }
                }
                $data = wp_json_encode( $state['record'] );
                if ( is_string( $data ) ) {
                    YSCacheStorage::write( $state['directory'], $state['key'], $data );
                }
            }
            $state = [];
        }
    }

    public static function onFooter( $name, array $args ): void {
        $state = &self::$capture;
        if ( ! isset( $state['level'] ) || ob_get_level() !== $state['level'] ) {
            $state = [];
            return;
        }
        $main = ob_get_clean();
        echo $main; // This is the original output of the site's own template.
        if ( $args || (string) $name !== $state['header'] ) {
            $state = [];
            return;
        }
        $ids = self::reviewBody( $main, $state['path'] );
        if ( $ids === null ) {
            $state = [];
            return;
        }
        if ( $state['path'] === '/' && str_contains( $main, 'ysslide-swiper' ) &&
            is_callable( [ YSCacheCarousel::class, 'completeHeroSources' ] ) ) {
            $main = YSCacheCarousel::completeHeroSources( $main );
        }
        if ( is_singular( 'product' ) ) {
            $ids[] = get_queried_object_id();
        }
        $prices = self::catalogPrices( $ids );
        if ( $prices === null ) {
            $state = [];
            return;
        }
        $schema = [];
        foreach ( array_slice( self::schema(), $state['before']['schema'] ) as $node ) {
            $type = $node['@type'] ?? null;
            if ( $type === 'Product' || $type === 'WebSite' ) {
                $schema[] = $type;
            } elseif ( self::isBreadcrumb( $node ) ) {
                $schema[] = $node;
            } else {
                $state = [];
                return;
            }
        }
        $assets = [];
        $definitions = [ 'styles' => [], 'scripts' => [] ];
        // JetWooBuilder creates its per-request localization and the grid controller during widget rendering.
        $jetGrid = preg_match( '/\bdata-widget_type=(["\'])jet-woo-products\.default\1/i', $main ) === 1;
        if ( $jetGrid ) {
            $script = wp_scripts()->registered['jet-woo-builder'] ?? null;
            $after = $script->extra['after'] ?? [];
            $after = is_array( $after ) ? array_values( array_filter( $after, static fn( $chunk ) =>
                $chunk !== false && $chunk !== null && $chunk !== '' ) ) : [];
            if ( ! function_exists( 'jet_woo_builder_assets' ) ||
                ! is_callable( [ jet_woo_builder_assets(), 'add_inline_widget_script' ] ) ||
                ! is_array( $after ) || count( $after ) !== 1 || ! is_string( $after[0] ) ||
                ! str_starts_with( ltrim( $after[0] ), '/* JetWooBuilder widget script: jet-woo-products.default */' ) ) {
                $state = [];
                return;
            }
        }
        foreach ( [ 'scripts' => wp_scripts(), 'styles' => wp_styles() ] as $kind => $registry ) {
            $before = $state['before']['inline'][ $kind ];
            $after = self::inlineSignatures( $registry );
            if ( $jetGrid && $kind === 'scripts' ) {
                unset( $after['jet-woo-builder'] );
            }
            if ( array_diff_assoc( array_intersect_key( $after, $before ), $before ) ) {
                $state = [];
                return;
            }
            $assets[ $kind ] = array_values( array_diff( $registry->queue, $state['before'][ $kind ] ) );
            $pending = $assets[ $kind ];
            while ( $pending ) {
                $handle = array_pop( $pending );
                if ( ! preg_match( '/\A[a-zA-Z0-9_.-]{1,100}\z/D', $handle ) || ! isset( $registry->registered[ $handle ] ) ) {
                    $state = [];
                    return;
                }
                if ( $jetGrid && $kind === 'scripts' && $handle === 'jet-woo-builder' ) {
                    continue;
                }
                if ( ! isset( $before[ $handle ] ) && ! isset( $definitions[ $kind ][ $handle ] ) ) {
                    $spec = self::staticDefinition( $registry->registered[ $handle ] );
                    if ( $spec === null || count( $definitions[ $kind ] ) >= 100 ) {
                        $state = [];
                        return;
                    }
                    $definitions[ $kind ][ $handle ] = $spec;
                    $pending = array_merge( $pending, $spec[1] );
                }
            }
        }
        $frontend = class_exists( '\Elementor\Plugin' ) ? ( \Elementor\Plugin::$instance->frontend ?? null ) : null;
        $fonts = [];
        $elementor = str_contains( $main, 'data-elementor-type=' );
        if ( $elementor ) {
            foreach ( [ 'has_elementor_in_page', 'get_list_of_google_fonts_by_type', 'enqueue_font', 'enqueue_styles', 'enqueue_scripts', 'print_fonts_links' ] as $method ) {
                if ( ! is_callable( [ $frontend, $method ] ) ) {
                    $state = [];
                    return;
                }
            }
            if ( ! $frontend->has_elementor_in_page() ) {
                $state = [];
                return;
            }
            foreach ( $frontend->get_list_of_google_fonts_by_type() as $families ) {
                foreach ( $families as $family ) {
                    if ( ! is_string( $family ) || ! preg_match( '/\A[\w -]{1,80}\z/u', $family ) ) {
                        $state = [];
                        return;
                    }
                    $fonts[] = $family;
                }
            }
        }
        $history = null;
        if ( $elementor && $state['path'] === '/' && empty( $state['shortcode'] ) &&
            did_action( 'dynamic_sidebar_before' ) === $state['before']['sidebars'] &&
            preg_match_all( '/\bdata-elementor-id=["\']([0-9]+)["\']/', $main, $documents ) &&
            array_unique( $documents[1] ) === [ (string) get_queried_object_id() ] ) {
            $history = self::historySignature( true );
        }
        $state['record'] = [ 'stamp' => $state['stamp'], 'built' => microtime(), 'header' => $state['header'], 'main' => $main, 'history' => $history,
            'schema' => $schema, 'assets' => $assets, 'definitions' => $definitions, 'prices' => $prices,
            'elementor' => (bool) $elementor, 'fonts' => $fonts, 'jet_grid' => $jetGrid ];
    }

    /** Inspect only a server-signed synthetic render. No dynamic markup, scripts, token-bearing forms or active shopper controls. */
    private static function reviewBody( string $html, string $path ): ?array {
        if ( strlen( $html ) > 600000 || trim( $html ) === '' || ! class_exists( '\DOMDocument' ) ||
            preg_match( '~\b(?:nonce|_wpnonce|csrf|token|sessionid|guestToken|pysOptions|PHPSESSID|eventID|user_id|customer_id)\b|'
                . '<(?:script|style|iframe|template|textarea|object|noscript)\b|recently[-_]viewed|(?:mini[-_]cart|cart[-_]contents|account[-_]modal)|'
                . '(?:yith[-_]|tinvwl)~i', $html ) ) {
            return null;
        }
        $dom = new \DOMDocument();
        if ( ! @$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="ys-main-fragment">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
            return null;
        }
        $ids = [];
        foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
            $class = $node->getAttribute( 'class' );
            if ( str_contains( $class, 'woocommerce-notices-wrapper' ) && trim( $node->textContent ) !== '' ) {
                return null;
            }
            if ( $node->hasAttribute( 'data-widget_type' ) && ! in_array( $node->getAttribute( 'data-widget_type' ),
                [ 'heading.default', 'call-to-action.default', 'image.default', 'divider.default', 'jet-woo-products.default', 'text-editor.default', 'spacer.default' ], true ) ) {
                return null;
            }
            if ( $node->tagName === 'form' ) {
                $order = $class === 'woocommerce-ordering' && strtolower( $node->getAttribute( 'method' ) ) === 'get';
                $cart = $class === 'cart' && strtolower( $node->getAttribute( 'method' ) ) === 'post' &&
                    $node->getAttribute( 'action' ) === home_url( $path );
                if ( ! $order && ! $cart ) {
                    return null;
                }
            }
            if ( $node->tagName === 'input' && ! in_array( $node->getAttribute( 'name' ), [ 'quantity', 'paged' ], true ) ) {
                return null;
            }
            if ( str_contains( $class, 'wishlist' ) && ! ( $node->tagName === 'button' &&
                preg_match( '/(?:^| )ct-wishlist-button-(?:single|archive)(?: |$)/', $class ) &&
                $node->hasAttribute( 'data-button-state' ) && $node->getAttribute( 'data-button-state' ) === '' ) ) {
                return null;
            }
            foreach ( $node->attributes as $attribute ) {
                if ( preg_match( '~^(?:on[a-z]|data-[\w-]*(?:nonce|token|session|customer|user-id)|nonce$)~i', $attribute->name ) ||
                    preg_match( '#(?:^|\s)(?:javascript:|data:text/html)#i', $attribute->value ) ) {
                    return null;
                }
            }
            $id = $node->getAttribute( 'data-product_id' ) ?: $node->getAttribute( 'data-product-id' );
            if ( ctype_digit( $id ) && (int) $id > 0 ) {
                $ids[] = (int) $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /** Detect changed stock, purchasability and visitor-dependent pricing before serving the guest catalog. */
    private static function catalogPrices( array $ids ): ?array {
        if ( count( $ids ) > 64 || ( $ids && ! function_exists( 'wc_get_product' ) ) ) {
            return null;
        }
        $prices = [];
        foreach ( $ids as $id ) {
            $product = wc_get_product( (int) $id );
            if ( ! $product || $product->get_status() !== 'publish' ) {
                return null;
            }
            $prices[ (int) $id ] = hash( 'sha256', serialize( [ $product->get_price_html(), $product->get_stock_status(),
                $product->get_stock_quantity(), $product->is_purchasable(), $product->is_visible() ] ) );
        }
        return $prices;
    }

    /** Called by the delegated WordPress template. All visitor-specific header and footer hooks execute normally. */
    public static function render(): void {
        $record = self::$replay;
        self::$replay = [];
        $name = $record['header'] === '' ? null : $record['header'];
        get_header( $name );
        if ( is_singular( 'product' ) && have_posts() ) {
            the_post();
        }
        if ( ! empty( $record['jet_grid'] ) ) {
            jet_woo_builder_assets()->add_inline_widget_script( 'jet-woo-products.default' );
        }
        foreach ( [ 'styles' => wp_styles(), 'scripts' => wp_scripts() ] as $kind => $registry ) {
            foreach ( $record['definitions'][ $kind ] as $handle => $spec ) {
                if ( ! isset( $registry->registered[ $handle ] ) ) {
                    $registry->add( $handle, $spec[0], $spec[1], $spec[2], $spec[3] );
                    foreach ( $spec[4] as $meta => $value ) {
                        $registry->add_data( $handle, $meta, $value );
                    }
                }
            }
            $registry->enqueue( $record['assets'][ $kind ] );
        }
        if ( $record['elementor'] ) {
            add_action( 'wp_footer', static function () use ( $record ) {
                $frontend = \Elementor\Plugin::$instance->frontend;
                if ( ! $frontend->has_elementor_in_page() ) {
                    foreach ( $record['fonts'] as $family ) {
                        $frontend->enqueue_font( $family );
                    }
                    $frontend->enqueue_styles();
                    $frontend->enqueue_scripts();
                    $frontend->print_fonts_links();
                }
            }, 9 );
        }
        foreach ( $record['schema'] as $node ) {
            if ( $node === 'Product' ) {
                WC()->structured_data->generate_product_data( wc_get_product( get_queried_object_id() ) );
            } elseif ( $node === 'WebSite' ) {
                WC()->structured_data->generate_website_data();
            } else {
                WC()->structured_data->set_data( $node );
            }
        }
        echo $record['main']; // Private, generation-bound output from the validated neutral template.
        get_footer( $name );
    }
}
