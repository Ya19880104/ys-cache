<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * 早期讀取器、寫入端與預載共用的請求判斷，不依賴 WordPress。
 *
 * 送出採 WP Rocket 式排除清單：只有會改變頁面內容的 cookie 才略過，追蹤參數不影響命中。
 * 寫入則更嚴格：只接受不帶 session 或狀態 cookie 的乾淨請求，避免把某位訪客的狀態寫進共用快取。
 */
final class YSCacheEarlyRequest {
    public const PRELOAD_HEADER = 'HTTP_X_YS_CACHE_PRELOAD';

    /** 不影響頁面內容的追蹤參數（取自 WP Rocket 3.24 dynamic-lists，再補上常見廣告平台）。 */
    public const IGNORED_PARAMS = [
        '_bta_c', '_bta_tid', '_ga', '_gl', '_hsenc', '_hsmi', '_ke', '_kx', '_sc', '_se', 'adgroupid', 'adid',
        'campaignid', 'dclid', 'dm_i', 'ef_id', 'epik', 'fb_action_ids', 'fb_action_types', 'fb_source', 'fbadid', 'fbclid',
        'gad', 'gad_campaignid', 'gad_source', 'gadid', 'gbraid', 'gclid', 'gclsrc', 'gdffi', 'gdfms', 'gdftrk', 'igshid',
        'irclickid', 'li_fat_id', 'mc_cid', 'mc_eid', 'mibextid', 'mkwid', 'msclkid', 'pcrid', 'rdt_cid', 'redirect_log_mongo_id',
        'redirect_mongo_id', 's_kwcid', 'sb_referer_host', 'sccid', 'srsltid', 'sscid', 'ssp_iaba', 'ssp_iabi', 'ttclid',
        'twclid', 'usqp', 'vgo_ee', 'wbraid', 'wickedid', 'yclid',
    ];
    /** 以這些前綴開頭的參數一律視為追蹤參數。 */
    public const IGNORED_PARAM_PREFIXES = [ 'utm_', 'hsa_', 'mtm_', 'pk_', 'sfmc_', 'trk_' ];

    /** 會改變頁面內容或代表私有狀態的 cookie 名稱前綴：帶有就不送快取。 */
    public const DENY_COOKIES = [
        'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'wordpressuser_', 'wordpresspass_',
        'ys_ec_session', 'ys_ec_tks_', '__Host-ys_ec_coupon_handoff',
        'blc_products_wish_list', 'yith_wcwl_session_', 'tinv_wishlistkey',
        'woocommerce_current_currency', 'wmc_current_currency', 'woocs_current_currency', 'aelia_cs_selected_currency',
        'wcml_client_currency', 'trp_language', 'woo-share',
    ];
    /** 非空購物車與 WooCommerce session。設定允許時改由 WooCommerce fragments 更新迷你購物車。 */
    public const CART_COOKIES = [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ];

    /** 寫入端可接受的分析 cookie：只供 JavaScript 或伺服器事件讀取，渲染頁面時不會用到。 */
    public const ANALYTICS_COOKIES = [
        '_ga', '_gid', '_gat', '_gcl_', '_fbp', '_fbc', '_ttp', '_clck', '_clsk', '_uet', '_pin_', 'sbjs_',
        'pys_', 'last_pys', 'pysTrafficSource', 'last_pysTrafficSource', 'pbid', 'select_prod_list', 'productlist',
    ];

    /** 會改變回應意義的請求標頭：帶有就交給 WordPress。 */
    private const UNSAFE_HEADERS = [
        'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_DIGEST', 'AUTH_TYPE', 'REMOTE_USER', 'REDIRECT_REMOTE_USER',
        'HTTP_X_AUTH_TOKEN', 'HTTP_X_API_KEY', 'HTTP_X_WP_NONCE', 'HTTP_ORIGIN',
        'HTTP_RANGE', 'HTTP_IF_RANGE', 'HTTP_IF_MATCH', 'HTTP_IF_UNMODIFIED_SINCE',
        'HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_X_METHOD_OVERRIDE', 'HTTP_TRANSFER_ENCODING', 'TRANSFER_ENCODING',
        'HTTP_X_FORWARDED_USER', 'HTTP_PROXY_AUTHORIZATION',
        'HTTP_X_YS_GUEST_TOKEN', 'HTTP_CART_TOKEN', 'HTTP_X_CART_TOKEN', 'HTTP_X_WC_STORE_API_NONCE',
        'HTTP_X_WC_STORE_API_TOKEN', 'HTTP_WC_STORE_API_NONCE', 'HTTP_NONCE', 'HTTP_X_WOOCOMMERCE_CART_TOKEN',
        'HTTP_X_WP_LOCALE', 'HTTP_X_WP_LANGUAGE', 'HTTP_X_LANGUAGE', 'HTTP_X_LOCALE', 'HTTP_X_OVERRIDE_LOCALE',
        'HTTP_X_TRP_LANGUAGE', 'HTTP_X_TRANSLATEPRESS_LANGUAGE', 'HTTP_X_WPML_LANGUAGE', 'HTTP_X_FORWARDED_LANGUAGE',
        'HTTP_X_ORIGINAL_URL', 'HTTP_X_ORIGINAL_URI', 'HTTP_X_REWRITE_URL',
    ];

    /** 同步時未提供清單的預設排除 cookie（包含購物車）。 */
    public static function defaultDenyCookies(): array {
        return [ ...self::DENY_COOKIES, ...self::CART_COOKIES ];
    }

    /**
     * 判斷能否送出早期快取。
     *
     * @return array{path:string,variant:string}|string 可送出時回傳路徑與變體；否則回傳略過原因。
     */
    public static function evaluate( array $server, array $cookies, array $config ): array|string {
        $path = self::common( $server, $config );
        if ( ! is_array( $path ) ) {
            return $path;
        }
        $deny = (array) ( $config['deny_cookies'] ?? self::defaultDenyCookies() );
        foreach ( array_keys( $cookies ) as $name ) {
            if ( ! is_string( $name ) || self::matches( $name, $deny ) ) {
                return 'cookie';
            }
        }
        if ( ! $cookies && trim( (string) ( $server['HTTP_COOKIE'] ?? '' ) ) !== '' ) {
            return 'cookie';
        }
        $variant = self::variant( $server, $config );
        return $variant === null ? 'header' : [ 'path' => $path['path'], 'variant' => $variant ];
    }

    /**
     * 判斷這次 WordPress 渲染能否寫入共用快取：不能帶查詢字串、session 或狀態 cookie。
     *
     * @return array{path:string,variant:string}|null
     */
    public static function writerPath( array $server, array $cookies, array $config ): ?array {
        $uri = (string) ( $server['REQUEST_URI'] ?? '' );
        if ( str_contains( $uri, '?' ) || ( isset( $server['QUERY_STRING'] ) && $server['QUERY_STRING'] !== '' ) ) {
            return null;
        }
        $request = self::evaluate( $server, $cookies, $config );
        if ( ! is_array( $request ) ) {
            return null;
        }
        $allowed = (array) ( $config['writer_cookies'] ?? self::ANALYTICS_COOKIES );
        $session = (string) ( $config['session_name'] ?? 'PHPSESSID' );
        foreach ( array_keys( $cookies ) as $name ) {
            if ( (string) $name === $session || ! self::matches( (string) $name, $allowed ) ) {
                return null;
            }
        }
        return $request;
    }

    /**
     * 方法、主機、路徑、查詢字串與標頭檢查。
     *
     * @return array{path:string}|string 通過時回傳標準路徑；否則回傳略過原因。
     */
    private static function common( array $server, array $config ): array|string {
        $method = (string) ( $server['REQUEST_METHOD'] ?? '' );
        if ( $method !== 'GET' && $method !== 'HEAD' ) {
            return 'method';
        }
        $host = (string) ( $config['host'] ?? '' );
        if ( $host === '' || ! in_array( strtolower( (string) ( $server['HTTPS'] ?? '' ) ), [ 'on', '1' ], true ) ||
            strcasecmp( (string) ( $server['HTTP_HOST'] ?? '' ), $host ) !== 0 ) {
            return 'host';
        }
        $uri = (string) ( $server['REQUEST_URI'] ?? '' );
        $mark = strpos( $uri, '?' );
        $path = $mark === false ? $uri : substr( $uri, 0, $mark );
        if ( ! YSCacheEarlyStore::validRequestPath( $path ) ) {
            return 'path';
        }
        if ( $mark !== false && ! self::onlyIgnoredParams( substr( $uri, $mark + 1 ), (array) ( $config['ignore_params'] ?? [] ) ) ) {
            return 'query';
        }
        foreach ( self::UNSAFE_HEADERS as $name ) {
            if ( isset( $server[ $name ] ) && $server[ $name ] !== '' ) {
                return 'header';
            }
        }
        // Android 內建瀏覽器（LINE、Facebook、Instagram）會以 X-Requested-With 帶上 App 套件名稱，只有 AJAX 才略過。
        if ( strcasecmp( trim( (string) ( $server['HTTP_X_REQUESTED_WITH'] ?? '' ) ), 'XMLHttpRequest' ) === 0 ) {
            return 'header';
        }
        if ( isset( $server['PHP_AUTH_USER'] ) || isset( $server['PHP_AUTH_PW'] ) ||
            ( isset( $server['CONTENT_LENGTH'] ) && $server['CONTENT_LENGTH'] !== '' && (string) $server['CONTENT_LENGTH'] !== '0' ) ) {
            return 'header';
        }
        $accept = (string) ( $server['HTTP_ACCEPT'] ?? '' );
        if ( $accept !== '' && ! preg_match( '~(?:^|,)\s*(?:text/html|\*/\*)(?:\s*[;,]|$)~i', $accept ) ) {
            return 'header';
        }
        $agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
        foreach ( (array) ( $config['deny_agents'] ?? [] ) as $excluded ) {
            if ( is_string( $excluded ) && trim( $excluded ) !== '' && stripos( $agent, trim( $excluded ) ) !== false ) {
                return 'agent';
            }
        }
        return [ 'path' => $path ];
    }

    /** 查詢字串只含追蹤參數時回傳 true；空查詢字串（`/?`）視為沒有參數。 */
    public static function onlyIgnoredParams( string $query, array $extra = [] ): bool {
        if ( strlen( $query ) > 4096 ) {
            return false;
        }
        foreach ( explode( '&', $query ) as $pair ) {
            if ( $pair === '' ) {
                continue;
            }
            $name = strtolower( rawurldecode( str_replace( '+', ' ', explode( '=', $pair, 2 )[0] ) ) );
            if ( $name === '' || ( ! in_array( $name, self::IGNORED_PARAMS, true ) && ! in_array( $name, $extra, true )
                && ! self::matches( $name, self::IGNORED_PARAM_PREFIXES ) ) ) {
                return false;
            }
        }
        return true;
    }

    /** 變體只分裝置（與 wp_is_mobile() 一致），加上可選的語言建議。 */
    public static function variant( array $server, array $config ): ?string {
        $agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
        $hint = (string) ( $server['HTTP_SEC_CH_UA_MOBILE'] ?? '' );
        if ( strlen( $agent ) > 2048 || strlen( $hint ) > 8 ) {
            return null;
        }
        $variant = self::isMobile( $server ) ? 'm' : 'd';
        $languages = $config['languages'] ?? null;
        if ( is_array( $languages ) && $languages ) {
            $suggested = self::suggestedLanguage( (string) ( $server['HTTP_ACCEPT_LANGUAGE'] ?? '' ), $languages );
            if ( $suggested === null ) {
                return null;
            }
            if ( $suggested !== '' ) {
                $variant .= '-' . strtolower( $suggested );
            }
        }
        return $variant;
    }

    /** 與 WordPress 核心 wp_is_mobile() 相同的判斷（JetElements 等外掛直接依此輸出不同內容）。 */
    public static function isMobile( array $server ): bool {
        if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
            return $server['HTTP_SEC_CH_UA_MOBILE'] === '?1';
        }
        $agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
        if ( $agent === '' ) {
            return false;
        }
        foreach ( [ 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ] as $token ) {
            if ( str_contains( $agent, $token ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 依 Accept-Language 對照已發布語言，與 YS TranslatePress Addons 自動偵測相同。
     *
     * @return string|null 沒有建議時回傳空字串；標頭異常時回傳 null。
     */
    public static function suggestedLanguage( string $header, array $published ): ?string {
        if ( strlen( $header ) > 1024 || preg_match( '~[^a-zA-Z0-9;=,. *_-]~', $header ) ) {
            return null;
        }
        $locales = [];
        $primary = [];
        foreach ( $published as $code ) {
            if ( is_string( $code ) && preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $code ) ) {
                $normalized = strtolower( $code );
                $locales[ $normalized ] ??= $code;
                $primary[ (string) strtok( $normalized, '_' ) ] ??= $code;
            }
        }
        $preferences = [];
        foreach ( explode( ',', $header ) as $part ) {
            $part = trim( $part );
            if ( $part === '' ) {
                continue;
            }
            [ $code, $quality ] = array_pad( explode( ';q=', $part, 2 ), 2, '1' );
            $preferences[] = [ str_replace( '-', '_', strtolower( trim( $code ) ) ), (float) $quality ];
        }
        usort( $preferences, static fn ( array $a, array $b ): int => $b[1] <=> $a[1] );
        foreach ( $preferences as [ $code ] ) {
            $matched = $locales[ $code ] ?? $primary[ (string) strtok( $code, '_' ) ] ?? '';
            if ( $matched !== '' ) {
                return $matched;
            }
        }
        return '';
    }

    /** Accept-Encoding 是否接受 gzip（含 x-gzip 與 *，q=0 表示拒絕）。 */
    public static function acceptsGzip( string $header ): bool {
        $accepted = false;
        foreach ( explode( ',', strtolower( $header ) ) as $part ) {
            $pieces = array_map( 'trim', explode( ';', $part ) );
            $coding = array_shift( $pieces );
            $quality = 1.0;
            foreach ( $pieces as $parameter ) {
                if ( str_starts_with( $parameter, 'q=' ) ) {
                    $quality = (float) substr( $parameter, 2 );
                }
            }
            if ( $coding === 'gzip' || $coding === 'x-gzip' ) {
                return $quality > 0;
            }
            if ( $coding === '*' ) {
                $accepted = $quality > 0;
            }
        }
        return $accepted;
    }

    private static function matches( string $name, array $prefixes ): bool {
        foreach ( $prefixes as $prefix ) {
            if ( is_string( $prefix ) && $prefix !== '' && str_starts_with( $name, $prefix ) ) {
                return true;
            }
        }
        return false;
    }
}
