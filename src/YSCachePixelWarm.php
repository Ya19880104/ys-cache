<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

/** Coalesces opt-in, credential-free PixelYourSite shell renders. */
final class YSCachePixelWarm {
    public const HOOK = 'ys_cache_pixel_warm_home';
    public const HEADER = 'HTTP_X_YS_CACHE_PIXEL_WARM';

    private const STATE = '/.pixel-warm.json';
    private const ACCEPT = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8';
    private const COOLDOWN = 120;
    private const PENDING_TTL = 300;
    private const SIGNATURE_TTL = 45;
    private static bool $verified = false;
    private static bool $finishQueued = false;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        if ( self::headerPresent() ) {
            add_action( 'wp', [ self::class, 'prepare' ], PHP_INT_MIN );
        }
    }

    public static function headerPresent(): bool {
        return array_key_exists( self::HEADER, $_SERVER );
    }

    public static function isVerified(): bool {
        return self::$verified;
    }

    public static function prepare(): void {
        if ( self::verified( YSCache::settings( true ) ) ) {
            add_filter( 'pys_skip_session', [ self::class, 'skipSession' ] );
        }
    }

    public static function skipSession( $skip ): bool {
        return self::$verified || (bool) $skip;
    }

    /** The caller has already established that PixelYourSite is the sole cache exclusion. */
    public static function schedule( array $settings ): bool {
        if ( self::headerPresent() || ! self::directHome( false ) ) {
            return false;
        }
        $context = self::context( $settings, self::profile( $_SERVER ) );
        if ( $context === null ) {
            return false;
        }
        $directory = $context['directory'];
        $queued = self::lock( $directory, static function () use ( $context, $directory ): bool {
            $now = time();
            if ( self::onCooldown( self::readState( $directory ), $now ) || self::hasEntry( $context ) ) {
                return false;
            }
            $state = [
                'until' => $now + self::COOLDOWN,
                'pending' => [
                    'expires' => $now + self::PENDING_TTL,
                    'stamp' => $context['stamp'],
                    'profile' => $context['profile'],
                ],
            ];
            if ( ! self::writeState( $directory, $state ) ) {
                return false;
            }
            if ( wp_next_scheduled( self::HOOK ) || wp_schedule_single_event( $now + 5, self::HOOK ) ) {
                return true;
            }
            @unlink( $directory . self::STATE );
            return false;
        } );
        if ( $queued && function_exists( 'fastcgi_finish_request' ) ) {
            self::$finishQueued = true;
            add_action( 'shutdown', [ self::class, 'finishAndRun' ], PHP_INT_MAX );
        }
        return $queued;
    }

    public static function run(): void {
        if ( wp_doing_cron() || self::isCli() ) {
            self::runPending( 8 );
        }
    }

    public static function finishAndRun(): void {
        if ( ! self::$finishQueued ) {
            return;
        }
        self::$finishQueued = false;
        if ( ! function_exists( 'fastcgi_finish_request' ) || ! fastcgi_finish_request() ) {
            return;
        }
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }
        self::runPending( 3 );
    }

    private static function runPending( int $timeout ): void {
        $directory = YSCacheEarly::directory();
        $pending = $directory === '' ? null : ( self::readState( $directory )['pending'] ?? null );
        if ( ! is_array( $pending ) || ( $pending['expires'] ?? 0 ) < time() ) {
            return;
        }
        $context = self::context( YSCache::settings( true ), $pending['profile'] ?? null );
        if ( $context !== null && ( $pending['stamp'] ?? '' ) === $context['stamp'] ) {
            self::dispatch( $context, $pending, $timeout );
        }
    }

    /** WP CLI can prime a synthetic browser variant with the same validation and cooldown. */
    public static function primeForCli( array $settings, array $profile ): bool {
        if ( ! self::isCli() ) {
            return false;
        }
        $context = self::context( $settings, $profile );
        return $context !== null && ( self::hasEntry( $context ) || self::dispatch( $context, null ) );
    }

    public static function cancel(): void {
        $directory = YSCacheEarly::directory();
        wp_clear_scheduled_hook( self::HOOK );
        if ( $directory !== '' ) {
            self::lock( $directory, static fn (): bool => ! file_exists( $directory . self::STATE ) ||
                @unlink( $directory . self::STATE ) );
        }
    }

    public static function verified( array $settings ): bool {
        if ( self::$verified ) {
            return true;
        }
        $token = $_SERVER[ self::HEADER ] ?? '';
        if ( ! is_string( $token ) ||
            ! preg_match( '~\A([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})\z~D', $token, $parts ) ||
            (int) $parts[1] < time() || (int) $parts[1] > time() + self::SIGNATURE_TTL ||
            ( $_SERVER['HTTP_ACCEPT'] ?? '' ) !== self::ACCEPT || ! self::directHome( true ) ||
            session_status() === PHP_SESSION_ACTIVE || ! empty( $_SESSION ) ) {
            return false;
        }
        $context = self::context( $settings, self::profile( $_SERVER ) );
        if ( $context === null || ! hash_equals( self::sign( $context, $parts[1], $parts[2] ), $parts[3] ) ) {
            return false;
        }
        $directory = $context['directory'];
        self::$verified = self::lock( $directory, static function () use ( $parts, $context, $directory ): bool {
            $state = self::readState( $directory );
            $active = $state['active'] ?? null;
            if ( $active !== [ $parts[1], $parts[2], $context['stamp'] ] || (int) $parts[1] < time() ) {
                return false;
            }
            unset( $state['active'] );
            return self::writeState( $directory, $state );
        } );
        return self::$verified;
    }

    /** Limit queued variants to standard desktop browser headers, without arbitrary strings. */
    public static function profile( array $server ): ?array {
        $agent = $server['HTTP_USER_AGENT'] ?? '';
        $language = $server['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $mobile = $server['HTTP_SEC_CH_UA_MOBILE'] ?? '';
        if ( ! is_string( $agent ) || ! is_string( $language ) || ! is_string( $mobile ) ||
            strlen( $agent ) > 192 || strlen( $language ) > 96 || ! in_array( $mobile, [ '', '?0' ], true ) ) {
            return null;
        }
        $platform = '(?:Windows NT 10\.0; Win64; x64|Macintosh; Intel Mac OS X 10_15_7|X11; Linux x86_64)';
        $chrome = '~\AMozilla/5\.0 \(' . $platform . '\) AppleWebKit/537\.36 \(KHTML, like Gecko\) ' .
            'Chrome/[0-9]{2,3}\.0\.0\.0 Safari/537\.36(?: Edg/[0-9]{2,3}\.0\.0\.0)?\z~D';
        $firefox = '~\AMozilla/5\.0 \(' . $platform . '; rv:([0-9]{2,3}\.0)\) Gecko/20100101 Firefox/\1\z~D';
        $safari = '~\AMozilla/5\.0 \(Macintosh; Intel Mac OS X 10_15_7\) AppleWebKit/605\.1\.15 ' .
            '\(KHTML, like Gecko\) Version/[0-9]{1,2}\.[0-9]{1,2}(?:\.[0-9]{1,2})? Safari/605\.1\.15\z~D';
        $lang = '[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}(?:;q=(?:0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?';
        $language = strtolower( trim( $language, ' ' ) );
        if ( ( ! preg_match( $chrome, $agent ) && ! preg_match( $firefox, $agent ) && ! preg_match( $safari, $agent ) ) ||
            ( $language !== '' && ! preg_match( '~\A' . $lang . '(?:, ?' . $lang . '){0,5}\z~D', $language ) ) ) {
            return null;
        }
        return [ 'HTTP_USER_AGENT' => $agent, 'HTTP_ACCEPT_LANGUAGE' => $language, 'HTTP_SEC_CH_UA_MOBILE' => $mobile ];
    }

    private static function directHome( bool $warm ): bool {
        $host = YSCacheEarly::host();
        if ( $host === '' || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || ! empty( $_SERVER['HTTP_REFERER'] ) ||
            ! empty( $_SERVER['HTTP_X_FORWARDED_USER'] ) || ! empty( $_SERVER['HTTP_PROXY_AUTHORIZATION'] ) ||
            $_GET || $_POST || is_user_logged_in() || ! is_front_page() || ! is_singular() || is_preview() ||
            post_password_required() || wp_is_mobile() ) {
            return false;
        }
        if ( ! $warm ) {
            return YSCachePixelTransport::shellEligible( $_SERVER, $_COOKIE, $host );
        }
        $headers = array_diff_key( $_SERVER, [ self::HEADER => true ] );
        return ! YSCachePixelTransport::hasProtectedHeaders( $headers ) &&
            YSCacheEarlyStore::requestPath( $headers, $_COOKIE, $host ) === '/';
    }

    private static function context( array $settings, ?array $profile ): ?array {
        if ( empty( $settings['early_pixel_shell'] ) || ! YSCache::usesLocalHtml( $settings ) ||
            ! in_array( '/', YSCacheEarly::paths( $settings['early_paths'] ?? '' ), true ) ||
            ! defined( 'WP_CACHE' ) || ! WP_CACHE || ! YSCacheEarly::owned() || is_multisite() ||
            ! defined( 'PYS_VERSION' ) || ! function_exists( 'PixelYourSite\\PYS' ) ||
            YSCache::blockingCache( $settings ) !== '' || $profile === null || self::profile( $profile ) !== $profile ) {
            return null;
        }
        $url = home_url( '/' );
        $host = YSCacheEarly::host();
        if ( ! preg_match( '~\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z~D', $host ) ||
            $url !== 'https://' . $host . '/' ) {
            return null;
        }
        $directory = YSCacheEarly::directory();
        $generation = $directory === '' ? null : YSCacheEarlyStore::snapshot( $directory, '/' );
        $variant = YSCacheEarlyStore::requestVariant( $profile );
        if ( $generation === null || $variant === null ) {
            return null;
        }
        $stamp = hash( 'sha256', serialize( [ $url, $variant, $generation, YSCacheSettingsRepo::pageGenerations( '/' ) ] ) );
        return compact( 'url', 'host', 'directory', 'profile', 'variant', 'stamp' );
    }

    private static function dispatch( array $context, ?array $pending, int $timeout = 8 ): bool {
        $expires = (string) ( time() + self::SIGNATURE_TTL );
        try {
            $nonce = bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $failure ) {
            return false;
        }
        $directory = $context['directory'];
        if ( ! self::lock( $directory, static function () use ( $context, $pending, $expires, $nonce, $directory ): bool {
            $now = time();
            $state = self::readState( $directory );
            if ( self::hasEntry( $context ) ||
                ( $pending === null ? self::onCooldown( $state, $now ) : ( $state['pending'] ?? null ) !== $pending ) ||
                ( $pending !== null && $pending['expires'] < $now ) ) {
                return false;
            }
            return self::writeState( $directory, [
                'until' => $now + self::COOLDOWN,
                'active' => [ $expires, $nonce, $context['stamp'] ],
            ] );
        } ) ) {
            return false;
        }
        $profile = $context['profile'];
        $headers = [
            'Accept' => self::ACCEPT,
            'Accept-Encoding' => 'identity',
            'X-YS-Cache-Pixel-Warm' => $expires . '.' . $nonce . '.' . self::sign( $context, $expires, $nonce ),
        ];
        if ( $profile['HTTP_ACCEPT_LANGUAGE'] !== '' ) {
            $headers['Accept-Language'] = $profile['HTTP_ACCEPT_LANGUAGE'];
        }
        if ( $profile['HTTP_SEC_CH_UA_MOBILE'] !== '' ) {
            $headers['Sec-CH-UA-Mobile'] = $profile['HTTP_SEC_CH_UA_MOBILE'];
        }
        $response = wp_safe_remote_get( $context['url'], [
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'user-agent' => $profile['HTTP_USER_AGENT'],
            'headers' => $headers,
            'cookies' => [],
            'limit_response_size' => 1048576,
        ] );
        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 && self::hasEntry( $context );
    }

    private static function sign( array $context, string $expires, string $nonce ): string {
        $message = "ys-cache-pixel-warm/v1\n" . $context['stamp'] . "\n" . $expires . "\n" . $nonce;
        return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
    }

    private static function hasEntry( array $context ): bool {
        return YSCacheEarlyStore::read( $context['directory'], $context['host'], '/', $context['variant'] ) !== null;
    }

    private static function onCooldown( array $state, int $now ): bool {
        return ( $state['until'] ?? 0 ) > $now;
    }

    private static function readState( string $directory ): array {
        $path = $directory . self::STATE;
        clearstatcache( true, $path );
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return [];
        }
        $json = @file_get_contents( $path, false, null, 0, 4096 );
        $state = is_string( $json ) && strlen( $json ) < 4096 ? json_decode( $json, true ) : null;
        return is_array( $state ) ? $state : [];
    }

    /** Only call with the per-site lock held. */
    private static function writeState( string $directory, array $state ): bool {
        $path = $directory . self::STATE;
        $json = json_encode( $state );
        $temporary = is_string( $json ) && ! is_link( $path ) ? @tempnam( $directory, '.ys-pixel-warm-' ) : false;
        if ( $temporary === false ) {
            return false;
        }
        try {
            return dirname( $temporary ) === realpath( $directory ) &&
                @file_put_contents( $temporary, $json ) === strlen( $json ) &&
                @chmod( $temporary, 0600 ) && @rename( $temporary, $path );
        } finally {
            if ( is_file( $temporary ) ) {
                @unlink( $temporary );
            }
        }
    }

    private static function lock( string $directory, callable $callback ): bool {
        $path = $directory . '/.pixel-warm.lock';
        $handle = is_link( $path ) ? false : @fopen( $path, 'c+b' );
        if ( $handle === false ) {
            return false;
        }
        try {
            return flock( $handle, LOCK_EX | LOCK_NB ) && $callback();
        } finally {
            fclose( $handle );
        }
    }

    private static function isCli(): bool {
        return PHP_SAPI === 'cli' && defined( 'WP_CLI' ) && WP_CLI;
    }
}
