<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Admin\YSCacheAdmin;
use YangSheep\Cache\Admin\YSCacheAdminBar;
use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

final class YSCache {
    private static ?self $instance = null;
    private bool $privateResponse = false;
    private ?string $cachedHtml = null;
    private bool $frontendTemplate = false;
    private ?string $requestGeneration = null;
    private ?array $requestPageGenerations = null;
    private bool $externalHold = false;
    private bool $edgeHtml = false;
    private ?array $pendingSharedHtml = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        if ( is_admin() ) {
            YSCacheAdmin::register();
        }
        YSCacheAdminBar::register();
        YSCacheAssetOptimization::register();

        add_action( 'init', [ $this, 'holdExternalHtml' ], 0, 0 );
        add_action( 'parse_request', [ $this, 'captureGeneration' ], 0, 0 );
        add_action( 'template_redirect', [ $this, 'protectPrivateResponse' ], PHP_INT_MAX );
        add_filter( 'template_include', [ $this, 'serveOrCapture' ], PHP_INT_MAX );
        add_filter( 'trp_translated_html', [ $this, 'rewriteTranslatedHtml' ], PHP_INT_MAX, 4 );

        ( new YSCacheInvalidation( [ $this, 'invalidateOnChange' ] ) )->register();
        YSCacheEnhancePurge::register();
    }

    public static function settings(): array {
        $settings = YSCacheSettingsRepo::get();
        if ( ! empty( $settings['auto_detect'] ) ) {
            if ( ! isset( $settings['providers']['opcache'] ) ) {
                $settings['providers']['opcache'] = [ 'enabled' => YSCacheProviders::opcacheAvailable() ];
            }
            if ( ! isset( $settings['providers']['enhance'] ) && YSCacheProviders::enhanceConnection( [] ) !== null ) {
                $settings['providers']['enhance'] = [ 'enabled' => true ];
            }
        }
        return $settings;
    }

    public static function enable( ?array $settings = null, ?string $rocketMigrationId = null ): bool {
        $before = self::settings();
        $settings = self::sanitize( $settings ?? $before, $before );
        $settings['enabled'] = true;
        if ( $rocketMigrationId !== null ) {
            $settings['rocket_migration_id'] = $rocketMigrationId;
        }

        return self::blockingCache() === '' && ( ! self::usesLocalHtml( $settings ) || YSCacheStorage::directory() !== '' ) &&
            YSCacheSettingsRepo::invalidate() && YSCacheSettingsRepo::save( $settings );
    }

    public static function disable(): bool {
        $settings = self::settings();
        $settings['enabled'] = false;
        return YSCacheSettingsRepo::save( $settings );
    }

    public static function save( array $input ): bool {
        $before = self::settings();
        $settings = self::sanitize( $input, $before );
        if ( self::usesLocalHtml( $settings ) && ! self::usesLocalHtml( $before ) &&
            ( self::blockingCache() !== '' || YSCacheStorage::directory() === '' ) ) {
            return false;
        }
        return YSCacheSettingsRepo::invalidate() && YSCacheSettingsRepo::save( $settings );
    }

    public static function delegatesHtml( array $settings ): bool {
        $enhance = $settings['providers']['enhance'] ?? [];
        return ! empty( $enhance['enabled'] ) && ! empty( $enhance['delegate_html'] );
    }

    public static function usesLocalHtml( array $settings ): bool {
        return ! empty( $settings['enabled'] ) && ! self::delegatesHtml( $settings );
    }

    private static function sanitize( array $input, array $before ): array {
        $settings = array_replace( $before, $input );
        $ttl = isset( $input['ttl'] ) ? YSCacheSettingsRepo::legacyTtlToSeconds( $input['ttl'] ) : (int) $settings['ttl_seconds'];
        return [
            'enabled'         => ! empty( $settings['enabled'] ),
            'ttl_seconds'     => max( 1, min( YSCacheSettingsRepo::MAX_TTL_SECONDS, $ttl ) ),
            'ttl_unit'        => in_array( $settings['ttl_unit'], [ 's', 'min', 'hr', 'day' ], true ) ? $settings['ttl_unit'] : 's',
            'auto_purge'      => ! empty( $settings['auto_purge'] ),
            'auto_detect'     => ! empty( $settings['auto_detect'] ),
            'exclude_paths'   => self::lines( $settings['exclude_paths'] ),
            'exclude_cookies' => self::lines( $settings['exclude_cookies'] ),
            'exclude_agents'  => self::lines( $settings['exclude_agents'] ),
            'assets'          => YSCacheAssetOptimization::sanitize( (array) $settings['assets'] ),
            'providers'       => YSCacheProviders::sanitize(
                is_array( $settings['providers'] ) ? $settings['providers'] : [],
                is_array( $before['providers'] ) ? $before['providers'] : []
            ),
        ];
    }

    private static function lines( $value ): string {
        if ( is_array( $value ) ) {
            $value = implode( "\n", array_filter( $value, 'is_string' ) );
        }
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = wp_strip_all_tags( wp_check_invalid_utf8( substr( $value, 0, 12000 ) ) );
        return trim( preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value ) ?? '' );
    }

    public static function blockingCache(): string {
        $active = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        foreach ( $active as $plugin ) {
            $slug = explode( '/', $plugin, 2 )[0];
            if ( in_array( $slug, [ 'wp-rocket', 'wp-super-cache', 'w3-total-cache', 'litespeed-cache', 'cache-enabler', 'wp-fastest-cache', 'breeze', 'flying-press', 'swift-performance', 'swift-performance-lite', 'siteground-optimizer', 'comet-cache' ], true ) ) {
                return $slug;
            }
        }
        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        if ( defined( 'WP_CACHE' ) && WP_CACHE && is_file( $dropin ) && filesize( $dropin ) > 0 ) {
            return 'advanced-cache.php';
        }
        return '';
    }

    public function invalidateOnChange(): void {
        $settings = self::settings();
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        // Clear the lower layer first so a refilled origin cannot reuse older HTML.
        if ( ( self::usesLocalHtml( $settings ) || self::delegatesHtml( $settings ) || self::hasEdgeHtml( $settings ) ) && ! YSCacheSettingsRepo::invalidate() ) {
            return;
        }
        YSCacheEnhancePurge::onContentChange( $settings );
    }

    public function captureGeneration(): void {
        $settings = self::settings();
        if ( in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) && empty( $_SERVER['QUERY_STRING'] ) ) {
            // A render that began before a save can never repopulate the new generation.
            if ( self::delegatesHtml( $settings ) ) {
                $this->requestGeneration = YSCacheSettingsRepo::generation();
            } elseif ( self::usesLocalHtml( $settings ) || self::hasEdgeHtml( $settings ) ) {
                $this->requestPageGenerations = YSCacheSettingsRepo::pageGenerations( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
            }
        }
    }

    private static function hasEdgeHtml( array $settings ): bool {
        foreach ( (array) ( $settings['providers']['cdn'] ?? [] ) as $layer ) {
            if ( ! is_array( $layer ) || empty( $layer['enabled'] ) || empty( $layer['html_cache_verified'] ) ||
                ! YSCacheProviders::scopeSupportsHtml( $layer ) ) {
                continue;
            }
            $hostname = $layer['hostname'] ?? '';
            if ( $hostname === '' || $hostname === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function purge( string $scope ): array {
        $settings = self::settings();
        $providers = $settings['providers'];
        if ( $scope === 'opcache' ) {
            return YSCacheProviders::purge( $providers, 'opcache' );
        }
        $results = [];
        if ( $scope === 'all' && ! empty( $providers['opcache']['enabled'] ) ) {
            $results = YSCacheProviders::purge( $providers, 'opcache' );
            if ( ( $results[0]['status'] ?? '' ) !== 'ok' ) {
                return $results;
            }
        }
        if ( in_array( $scope, [ 'local', 'all', 'enhance', 'cdn' ], true ) ) {
            $delegated = self::delegatesHtml( $settings );
            $ok = $delegated && $scope === 'local' ? true : YSCacheSettingsRepo::invalidate();
            $results[] = [
                'layer'   => 'YS CACHE',
                'status'  => ! $ok ? 'error' : ( $delegated ? 'skipped' : 'ok' ),
                'message' => ! $ok ? __( 'The cache generation could not be updated.', 'ys-cache' ) :
                    ( $delegated ? __( 'Enhance is the HTML cache owner.', 'ys-cache' ) : __( 'HTML cache cleared.', 'ys-cache' ) ),
                'http'    => 0,
            ];
            if ( ! $ok ) {
                return $results;
            }
        }
        if ( $scope !== 'local' ) {
            $providers['opcache']['enabled'] = false;
            $providerScope = $scope === 'cdn' && ! empty( $providers['enhance']['enabled'] ) ? 'all' : $scope;
            $results = array_merge( $results, YSCacheProviders::purge( $providers, $providerScope ) );
        }
        return $results;
    }

    public static function purgePage( string $url ): array {
        $target = self::pagePurgeTarget( $url );
        if ( $target === null ) {
            return [ self::pagePurgeResult( 'YS CACHE', 'error', __( 'Enter a public page URL on this site without a query string or fragment.', 'ys-cache' ) ) ];
        }

        $settings = self::settings();
        $providers = $settings['providers'];
        $local = self::usesLocalHtml( $settings );
        $cleared = ( ! $local && ! self::hasEdgeHtml( $settings ) ) || YSCacheSettingsRepo::invalidatePage( $target['path'] );
        $results = [ self::pagePurgeResult( 'YS CACHE', $cleared ? ( $local ? 'ok' : 'skipped' ) : 'error', $cleared
            ? ( $local ? __( 'This page’s HTML cache was cleared.', 'ys-cache' ) : __( 'The local HTML cache is disabled.', 'ys-cache' ) )
            : __( 'The page cache generation could not be updated.', 'ys-cache' ) ) ];
        if ( ! $cleared ) {
            return $results;
        }

        if ( ! empty( $providers['enhance']['enabled'] ) ) {
            $state = self::delegatesHtml( $settings ) ? true : YSCacheEnhanceIntegration::status( $providers )['enabled'];
            if ( $state !== false ) {
                $results[] = self::pagePurgeResult( 'Enhance Nginx', 'error', $state === true
                    ? __( 'Enhance FastCGI supports entire-site purges. Use Purge entire site.', 'ys-cache' )
                    : __( 'Unable to verify whether Enhance FastCGI is active. CDN purge was paused.', 'ys-cache' ) );
                return $results;
            }
        }

        return array_merge( $results, YSCacheProviders::purge( $providers, 'urls', [ $target['url'] ] ) );
    }

    private static function pagePurgeResult( string $layer, string $status, string $message ): array {
        return compact( 'layer', 'status', 'message' ) + [ 'http' => 0 ];
    }

    private static function pagePurgeTarget( string $url ): ?array {
        $url = trim( $url );
        if ( strlen( $url ) > 2300 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return null;
        }
        $site = wp_parse_url( home_url( '/' ) );
        $target = wp_parse_url( $url );
        if ( ! isset( $site['scheme'], $site['host'], $target['scheme'], $target['host'] ) ||
            ! in_array( $site['scheme'], [ 'http', 'https' ], true ) || $site['scheme'] !== strtolower( $target['scheme'] ) ||
            strcasecmp( $site['host'], $target['host'] ) !== 0 || count( array_intersect( [ 'user', 'pass', 'query', 'fragment' ], array_keys( $target ) ) ) ) {
            return null;
        }

        $defaultPort = $site['scheme'] === 'https' ? 443 : 80;
        $path = $target['path'] ?? '/';
        $decoded = rawurldecode( $path );
        $sitePath = rtrim( rawurldecode( $site['path'] ?? '/' ), '/' );
        if ( ( $site['port'] ?? $defaultPort ) !== ( $target['port'] ?? $defaultPort ) ||
            strlen( $path ) > 2048 || ! str_starts_with( $path, '/' ) || str_contains( $path, '//' ) ||
            preg_match( '~%(?:2f|5c|25)|%(?![0-9a-f]{2})~i', $path ) ||
            preg_match( '~[\x00-\x20\x7f?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ||
            ( $decoded !== $sitePath && ! str_starts_with( $decoded, $sitePath . '/' ) ) ) {
            return null;
        }

        $authority = $site['scheme'] . '://' . $site['host'];
        if ( isset( $site['port'] ) && $site['port'] !== $defaultPort ) {
            $authority .= ':' . $site['port'];
        }
        return [ 'url' => $authority . $path, 'path' => $path ];
    }

    public function holdExternalHtml(): void {
        $settings = self::settings();
        if ( ( ! self::delegatesHtml( $settings ) && ! self::hasEdgeHtml( $settings ) ) || headers_sent() ) {
            return;
        }
        $this->externalHold = ! array_filter( headers_list(), static fn ( string $header ): bool => stripos( $header, 'X-Accel-Expires:' ) === 0 );
        header( 'X-Accel-Expires: 0', true );
    }

    public function protectPrivateResponse(): void {
        $settings = self::settings();
        $this->edgeHtml = self::hasEdgeHtml( $settings );
        if ( ( ! self::usesLocalHtml( $settings ) && ! self::delegatesHtml( $settings ) && ! $this->edgeHtml ) || headers_sent() ) {
            return;
        }
        if ( self::delegatesHtml( $settings ) && ! in_array( 'X-Accel-Expires: 0', headers_list(), true ) ) {
            $this->externalHold = false;
            header( 'X-Accel-Expires: 0', true );
        }
        foreach ( headers_list() as $header ) {
            if ( preg_match( '/^(?:Cache-Control:.*(?:private|no-cache|no-store|max-age\s*=\s*0\b)|Pragma:\s*no-cache)/i', $header ) ) {
                $this->privateResponse = true;
                break;
            }
        }
        // Force shared caches to defer to WordPress for HTML.
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
        header( 'X-YS-Cache: BYPASS', true );
    }

    private static function match( string $list, string $subject, string $mode ): bool {
        foreach ( preg_split( '/\R/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $item ) {
            $item = trim( $item );
            if ( $item === '' ) {
                continue;
            }
            if ( $mode === 'contains' && stripos( $subject, $item ) !== false ) {
                return true;
            }
            if ( $mode === 'prefix' && ( $item === '*' || stripos( $subject, rtrim( $item, '*' ) ) === 0 ) ) {
                return true;
            }
            if ( $mode === 'path' ) {
                if ( str_ends_with( $item, '/*' ) && strcasecmp( substr( $item, 0, -2 ), rtrim( $subject, '/' ) ) === 0 ) {
                    return true;
                }
                $pattern = str_replace( '\*', '.*', preg_quote( $item, '~' ) );
                if ( preg_match( '~\A' . $pattern . '/?\z~iD', $subject ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function cachePath( array $settings ): ?string {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || empty( $uri ) || strlen( $uri ) > 2048 ||
            strpos( $uri, '/' ) !== 0 || strpos( $uri, '//' ) !== false || strpbrk( $uri, "?#\\\r\n" ) !== false ||
            ! empty( $_GET ) || ! empty( $_SERVER['QUERY_STRING'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ||
            ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || isset( $_SERVER['PHP_AUTH_USER'] ) ||
            ! empty( $_SERVER['HTTP_RANGE'] ) || ! empty( $_SERVER['HTTP_ORIGIN'] ) ||
            ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
            return null;
        }
        $decoded = rawurldecode( $uri );
        if ( preg_match( '~[\x00-\x20\x7f?#\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ||
            preg_match( '~%(?:2f|5c|25)~i', $uri ) ||
            self::match( $settings['exclude_paths'], $uri, 'path' ) ||
            self::match( $settings['exclude_paths'], $decoded, 'path' ) ||
            self::match( $settings['exclude_agents'], (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 'contains' ) ) {
            return null;
        }

        // Unknown cookies can carry personalization. Allowlisting is exact and opt-in for site integrations.
        $allowed = (array) apply_filters( 'ys_cache_public_cookie_names', [] );
        foreach ( $_COOKIE as $name => $value ) {
            if ( ! is_string( $name ) ||
                ( ! in_array( $name, $allowed, true ) && ! YSCacheIntegrations::isPublicCookie( $name, $value ) ) ||
                self::match( $settings['exclude_cookies'], $name, 'prefix' ) ) {
                return null;
            }
            foreach ( YSCacheIntegrations::cookiePrefixes() as $prefix ) {
                if ( str_starts_with( $name, $prefix ) ) {
                    return null;
                }
            }
        }
        if ( empty( $_COOKIE ) && ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
            return null;
        }

        if ( ! self::hasCanonicalAuthority() ||
            YSCacheIntegrations::bypass() || ! apply_filters( 'ys_cache_allow_request', true, $uri ) ) {
            return null;
        }
        return $uri;
    }

    private static function hasCanonicalAuthority(): bool {
        $authority = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
        if ( ! preg_match( '/\A(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::[0-9]{1,5})?\z/iD', $authority ) ) {
            return false;
        }
        $scheme = is_ssl() ? 'https' : 'http';
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $expected = wp_parse_url( home_url() );
        $actual = wp_parse_url( $scheme . '://' . $authority );
        return isset( $expected['host'], $actual['host'] ) &&
            strcasecmp( $expected['host'], $actual['host'] ) === 0 &&
            ( $expected['port'] ?? $defaultPort ) === ( $actual['port'] ?? $defaultPort );
    }

    private static function headersAllowCache( array $headers ): bool {
        foreach ( $headers as $header ) {
            [ $name, $value ] = array_pad( explode( ':', $header, 2 ), 2, '' );
            $name = strtolower( trim( $name ) );
            if ( in_array( $name, [ 'set-cookie', 'location', 'refresh', 'content-disposition', 'www-authenticate', 'x-accel-redirect', 'x-sendfile', 'content-length', 'content-encoding', 'etag', 'last-modified' ], true ) ||
                ( $name === 'content-type' && ! preg_match( '~^\s*text/html(?:;|$)~i', $value ) ) ||
                ( strpos( $name, 'content-security-policy' ) === 0 && stripos( $value, 'nonce-' ) !== false ) ) {
                return false;
            }
            if ( $name === 'vary' && array_diff( preg_split( '/\s*,\s*/', strtolower( trim( $value ) ) ), [ 'accept-encoding' ] ) ) {
                return false;
            }
        }
        return true;
    }

    public function serveOrCapture( string $template ): string {
        $settings = self::settings();
        if ( headers_sent() || is_feed() || is_preview() || is_trackback() || is_embed() || is_customize_preview() ) {
            return $template;
        }

        $this->frontendTemplate = true;
        $path = self::cachePath( $settings );
        if ( $path === null || $this->privateResponse || is_user_logged_in() || is_404() || is_search() ||
            ( is_singular() && post_password_required() ) ) {
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        $delegated = self::delegatesHtml( $settings );
        $local = self::usesLocalHtml( $settings );
        $sharedSafe = $this->externalHold && ( $delegated || $this->edgeHtml ) && YSCacheIntegrations::allowsExternalHtml();
        $fastcgi = $sharedSafe && $delegated && ! empty( $settings['providers']['enhance']['rules_verified'] );
        $this->edgeHtml = $sharedSafe && $this->edgeHtml && ( ! $delegated || $fastcgi );
        if ( ( ! $local && ! $fastcgi && ! $this->edgeHtml ) || self::blockingCache() !== '' ) {
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        $language = YSCacheIntegrations::languageVariant();
        $nonce = $language === null ? null : YSCacheIntegrations::nonceVariant();
        $status = http_response_code();
        if ( $nonce === null || ( $status !== false && $status !== 200 ) ||
            ! self::headersAllowCache( headers_list() ) ) {
            $this->captureAssets( $settings['providers'] );
            return $template;
        }

        if ( ! $local ) {
            $this->captureHtml( $settings, $nonce, null, null, $fastcgi );
            return $template;
        }

        $directory = YSCacheStorage::directory();
        if ( $directory === '' ) {
            $this->captureAssets( $settings['providers'] );
            return $template;
        }
        $this->requestPageGenerations ??= YSCacheSettingsRepo::pageGenerations( $path );
        $key = hash( 'sha256', implode( "\n", [
            YS_CACHE_VERSION, home_url(), is_ssl() ? 'https' : 'http', $path, determine_locale(), $language, $nonce,
            implode( ':', $this->requestPageGenerations ), hash( 'sha256', serialize( $settings ) ),
        ] ) );
        $remaining = null;
        $html = YSCacheStorage::read( $directory, $key, $settings['ttl_seconds'], $remaining );
        if ( $html !== null ) {
            header( 'X-YS-Cache: HIT', true );
            $this->cachedHtml = $html;
            if ( $this->edgeHtml && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                $this->releaseSharedHtml( $html, $this->sharedHtmlState( $nonce, $remaining ?? 0, false ) );
            }
            return YS_CACHE_DIR . 'templates/cached-page.php';
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'HEAD' ) {
            return $template;
        }
        header( 'X-YS-Cache: MISS', true );
        $this->captureHtml( $settings, $nonce, $directory, $key );
        return $template;
    }

    private function captureHtml( array $settings, string $nonce, ?string $directory, ?string $key, bool $fastcgi = false ): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) {
            return;
        }
        $baseline = headers_list();
        $shared = $fastcgi || $this->edgeHtml ? $this->sharedHtmlState( $nonce, $settings['ttl_seconds'], $fastcgi ) : null;
        $complete = true;
        ob_start( function ( string $html, int $phase ) use ( &$complete, $baseline, $directory, $key, $settings, $shared ): string {
            if ( !( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
                $complete = false;
                return $html;
            }
            $responseHtml = $this->rewriteAssets( $html, $settings['providers'] );
            $status = http_response_code();
            if ( ! $complete || headers_sent() || ( $status !== false && $status !== 200 ) ||
                headers_list() !== $baseline || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
                ! preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) ) ||
                ! preg_match( '~</html\s*>~i', substr( $html, -8192 ) ) ||
                preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ||
                ! YSCacheIntegrations::cacheableHtml( $html ) ||
                ! apply_filters( 'ys_cache_allow_html', true, $html ) ) {
                return $responseHtml;
            }

            if ( $directory !== null ) {
                YSCacheStorage::write( $directory, $key, $html );
            }
            if ( $shared !== null ) {
                $this->releaseSharedHtml( $html, $shared );
            }
            return $responseHtml;
        } );
    }

    private function sharedHtmlState( string $nonce, int $ttl, bool $fastcgi ): array {
        return [ 'nonce' => $nonce, 'expiry' => time() + YSCacheIntegrations::externalHtmlTtl( $ttl ),
            'fastcgi' => $fastcgi, 'headers' => headers_list() ];
    }

    private function releaseSharedHtml( string $html, array $state, bool $translated = false ): void {
        if ( ! $translated && ! $this->rewriteAssetsHere() ) {
            // TranslatePress must finish replacing media and markup before an outer cache stores the response.
            $this->pendingSharedHtml = $state;
            return;
        }
        $remaining = $state['expiry'] - time();
        if ( $remaining <= 0 || headers_sent() || headers_list() !== $state['headers'] ||
            ! YSCacheIntegrations::cacheableHtml( $html ) || preg_match( '~<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || $state['nonce'] !== YSCacheIntegrations::nonceVariant() ) {
            return;
        }
        $unchanged = $this->requestPageGenerations !== null
            ? $this->requestPageGenerations === YSCacheSettingsRepo::pageGenerations( (string) $_SERVER['REQUEST_URI'] )
            : $this->requestGeneration === YSCacheSettingsRepo::generation();
        if ( ! $unchanged || headers_list() !== $state['headers'] ) {
            return;
        }
        header( $state['fastcgi'] ? 'X-Accel-Expires: @' . $state['expiry'] : 'X-Accel-Expires: 0', true );
        header( 'Cache-Control: public, max-age=0' . ( $this->edgeHtml ? ', s-maxage=' . $remaining : '' ) . ', must-revalidate', true );
        if ( $state['fastcgi'] ) {
            header( 'X-YS-Cache: DELEGATED', true );
        }
    }

    public function renderCachedPage(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'HEAD' ) {
            // This is the complete guest HTML response rendered by WordPress.
            echo $this->rewriteAssets( $this->cachedHtml ?? '', self::settings()['providers'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    private function captureAssets( array $providers ): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || ! $this->rewriteAssetsHere() ||
            ! YSCacheProviders::hasUrlRewriting( $providers ) ) {
            return;
        }
        ob_start( function ( string $html, int $phase ) use ( $providers ): string {
            return ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && !( $phase & PHP_OUTPUT_HANDLER_CLEAN )
                ? $this->rewriteAssets( $html, $providers ) : $html;
        } );
    }

    private function rewriteAssetsHere(): bool {
        global $TRP_LANGUAGE;
        if ( ! class_exists( '\TRP_Translate_Press' ) ) {
            return true;
        }
        $settings = get_option( 'trp_settings', [] );
        return empty( $TRP_LANGUAGE ) || $TRP_LANGUAGE === ( $settings['default-language'] ?? '' );
    }

    private function rewriteAssets( string $html, array $providers ): string {
        return $this->rewriteAssetsHere() && preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) )
            ? YSCacheProviders::rewriteHtml( $html, $providers ) : $html;
    }

    public function rewriteTranslatedHtml( string $html, $language, $source, $preview ): string {
        if ( ! $this->frontendTemplate || ! empty( $preview ) ) {
            return $html;
        }
        $html = YSCacheProviders::rewriteHtml( $html, self::settings()['providers'] );
        if ( $this->pendingSharedHtml !== null ) {
            $this->releaseSharedHtml( $html, $this->pendingSharedHtml, true );
            $this->pendingSharedHtml = null;
        }
        return $html;
    }
}
