<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

final class YSCacheEnhancePurge {
    private const HOOK = 'ys_cache_retry_enhance_purge';
    private const PENDING = 'enhance_purge_pending';
    private const RESULT = 'enhance_purge_result';
    private const RETRY_DELAYS = [ 30, 120, 300 ];

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    public static function onContentChange( array $settings ): void {
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        $providers = $settings['providers'];
        $target = self::target( $providers, self::automaticCdnLayers( $providers ) );
        if ( $target === '' ) {
            return;
        }
        if ( ! YSCacheSettingsRepo::writePurgeRecord( self::PENDING, [
            'change'  => wp_generate_uuid4(),
            'target'  => $target,
            'attempt' => 0,
        ] ) ) {
            return;
        }

        // Record the retry before dispatch so an interrupted request still has a worker.
        self::schedule( self::RETRY_DELAYS[0] );
        self::run();
    }

    public static function lastResult(): array {
        return YSCacheSettingsRepo::readPurgeRecord( self::RESULT );
    }

    public static function run(): void {
        global $wpdb;
        $pending = YSCacheSettingsRepo::readPurgeRecord( self::PENDING );
        if ( empty( $pending['change'] ) || (int) ( $pending['attempt'] ?? 0 ) > count( self::RETRY_DELAYS ) ) {
            return;
        }
        $lock = 'ys_cache_enhance_' . substr( hash( 'sha256', DB_NAME . '/' . $wpdb->options ), 0, 44 );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== '1' ) {
            self::schedule( self::RETRY_DELAYS[0] );
            return;
        }
        try {
            $pending = YSCacheSettingsRepo::readPurgeRecord( self::PENDING );
            if ( empty( $pending['change'] ) || (int) ( $pending['attempt'] ?? 0 ) > count( self::RETRY_DELAYS ) ) {
                return;
            }
            $settings = YSCache::settings();
            if ( empty( $settings['auto_purge'] ) ) {
                YSCacheSettingsRepo::changePurgeRecord( self::PENDING, $pending, null );
                return;
            }
            $providers = $settings['providers'];
            $cdn = self::automaticCdnLayers( $providers );
            if ( self::target( $providers, $cdn ) !== ( $pending['target'] ?? '' ) ) {
                YSCacheSettingsRepo::changePurgeRecord( self::PENDING, $pending, null );
                return;
            }

            $attempt = (int) $pending['attempt'];
            $claimed = array_replace( $pending, [ 'attempt' => $attempt + 1 ] );
            if ( ! YSCacheSettingsRepo::changePurgeRecord( self::PENDING, $pending, $claimed ) ) {
                return;
            }
            // The currently executing Cron event may already have been removed by WP.
            if ( isset( self::RETRY_DELAYS[ $attempt ] ) ) {
                self::schedule( self::RETRY_DELAYS[ $attempt ] );
            }
            $results = $cdn === []
                ? YSCacheProviders::purge( $providers, 'enhance' )
                : YSCacheProviders::purge( [ 'enhance' => $providers['enhance'] ?? [], 'cdn' => $cdn ] );
            $result = end( $results ) ?: [];
            $success = ( $result['status'] ?? '' ) === 'ok';
            $http = (int) ( $result['http'] ?? 0 );
            $retry = ! $success && isset( self::RETRY_DELAYS[ $attempt ] ) &&
                ( ! empty( $result['retryable'] ) || $http === 408 || $http === 429 || $http >= 500 );
            // A newer save can replace the row while the previous generation is purging.
            $current = $retry
                ? YSCacheSettingsRepo::readPurgeRecord( self::PENDING ) === $claimed
                : YSCacheSettingsRepo::changePurgeRecord( self::PENDING, $claimed, null );
            if ( ! $current ) {
                return;
            }
            YSCacheSettingsRepo::writePurgeRecord( self::RESULT, [
                'status' => $success ? 'ok' : ( $retry ? 'retry' : 'error' ),
                'http'   => $http,
                'time'   => time(),
                'layers' => $results,
            ] );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function automaticCdnLayers( array $providers ): array {
        return array_values( array_filter( (array) ( $providers['cdn'] ?? [] ), static function ( $layer ): bool {
            return is_array( $layer ) && ! empty( $layer['enabled'] ) && YSCacheProviders::autoPurgePolicy( $layer ) === 'inherit';
        } ) );
    }

    private static function target( array $providers, array $cdn ): string {
        $enhance = $providers['enhance'] ?? [];
        $enhance_enabled = ! empty( $enhance['enabled'] );
        if ( $cdn === [] && ! $enhance_enabled ) {
            return '';
        }
        $url = $enhance['url'] ?? '';
        $domain = $enhance['domain_id'] ?? '';
        $org = $enhance['org_id'] ?? '';
        $token = $enhance['token'] ?? '';
        if ( defined( 'YS_CACHE_ENHANCE_TOKEN' ) ) {
            $url = defined( 'YS_CACHE_ENHANCE_URL' ) ? YS_CACHE_ENHANCE_URL : '';
            $domain = defined( 'YS_CACHE_ENHANCE_DOMAIN_ID' ) ? YS_CACHE_ENHANCE_DOMAIN_ID : '';
            $org = defined( 'YS_CACHE_ENHANCE_ORG_ID' ) ? YS_CACHE_ENHANCE_ORG_ID : '';
            $token = YS_CACHE_ENHANCE_TOKEN;
        }
        if ( $cdn === [] ) {
            return ! $url || ! $domain || ! $token
                ? ''
                : hash( 'sha256', (string) wp_json_encode( [ $url, $domain, $org ] ) );
        }

        $identity = [
            'enhance' => $enhance_enabled ? [ $url, $domain, $org ] : [],
            'cdn'     => array_map( static function ( array $layer ): array {
                return array_intersect_key( $layer, array_flip( [
                    'provider', 'zone_id', 'auth_email', 'hostname', 'role', 'purge_entire_zone',
                ] ) );
            }, $cdn ),
        ];
        return hash( 'sha256', (string) wp_json_encode( $identity ) );
    }

    private static function schedule( int $delay ): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_single_event( time() + $delay, self::HOOK );
        }
    }
}
