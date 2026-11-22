<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Database\YSCacheSettingsRepo;

defined( 'ABSPATH' ) || exit;

final class YSCacheEnhancePurge {
    private const HOOK = 'ys_cache_retry_enhance_purge';
    private const PENDING = 'enhance_purge_pending';
    private const STOCK_PENDING = 'stock_purge_pending';
    private const RESULT = 'enhance_purge_result';
    private const RECOVERY = 'ys_cache_purge_recovery';
    private const COALESCE_SECONDS = 15;
    private const STOCK_COALESCE_SECONDS = 60;
    private const RECOVERY_SECONDS = 300;
    private const RETRY_WINDOW = 86400;
    private const RETRY_DELAYS = [ 30, 120, 300, 900, 1800, 3600, 7200, 14400 ];
    private const STOCK_BATCH = 20;
    private const WRITE_ATTEMPTS = 8;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'run' ], 10, 0 );
        add_action( 'admin_init', [ self::class, 'recoverSchedule' ] );
        add_filter( 'cron_schedules', [ self::class, 'cronSchedules' ] );
    }

    public static function onContentChange( array $settings ): void {
        self::enqueue( self::PENDING, $settings, [] );
    }

    public static function onStockChange( array $settings, array $urls ): void {
        $urls = array_values( array_filter( $urls, static fn ( $url ): bool => is_string( $url ) && $url !== '' && strlen( $url ) <= 2048 ) );
        if ( $urls !== [] ) {
            self::enqueue( self::STOCK_PENDING, $settings, $urls );
        }
    }

    private static function enqueue( string $name, array $settings, array $urls ): void {
        if ( empty( $settings['auto_purge'] ) ) {
            return;
        }
        $stock = $name === self::STOCK_PENDING;
        $providers = (array) ( $settings['providers'] ?? [] );
        $target = self::target( $providers, self::automaticCdnLayers( $providers ), $stock, ! empty( $settings['enabled'] ) );
        if ( $target === '' ) {
            return;
        }
        $change = wp_generate_uuid4();
        $now = time();
        for ( $try = 0; $try < self::WRITE_ATTEMPTS; $try++ ) {
            $previous = YSCacheSettingsRepo::readPurgeRecord( $name );
            $sameTarget = ( $previous['target'] ?? '' ) === $target;
            if ( $previous !== [] && ! $sameTarget ) {
                $current = YSCache::settings( true );
                $currentProviders = (array) ( $current['providers'] ?? [] );
                if ( empty( $current['auto_purge'] ) || self::target( $currentProviders, self::automaticCdnLayers( $currentProviders ), $stock, ! empty( $current['enabled'] ) ) !== $target ) {
                    // A request using older settings must not replace a newer target.
                    return;
                }
            }
            $pending = $sameTarget ? $previous : [];
            $pending['change'] = $change;
            $pending['target'] = $target;
            $pending['updated_at'] = max( (int) ( $pending['updated_at'] ?? 0 ), $now );
            $pending['next_run'] ??= $now + ( $stock ? self::STOCK_COALESCE_SECONDS : self::COALESCE_SECONDS );
            $pending['attempt'] ??= 0;
            if ( $stock ) {
                $pending['urls'] = array_replace( (array) ( $pending['urls'] ?? [] ), array_fill_keys( $urls, $change ) );
            }
            // A competing save keeps its first deadline, and a worker keeps its backoff.
            if ( YSCacheSettingsRepo::changePurgeRecord( $name, $previous ?: null, $pending ) ) {
                self::ensureRecovery();
                self::schedule( (int) $pending['next_run'] );
                return;
            }
        }
    }

    public static function lastResult(): array {
        $result = YSCacheSettingsRepo::readPurgeRecord( self::RESULT );
        $next = 0;
        $queued = [];
        foreach ( [ self::PENDING => 'all', self::STOCK_PENDING => 'stock' ] as $name => $scope ) {
            $pending = YSCacheSettingsRepo::readPurgeRecord( $name );
            if ( ! empty( $pending['change'] ) ) {
                $due = (int) ( $pending['next_run'] ?? time() );
                if ( $next === 0 || $due < $next ) {
                    $next = $due;
                    $queued = [ 'status' => 'queued', 'time' => (int) ( $pending['updated_at'] ?? time() ), 'scope' => $scope ];
                }
            }
        }
        if ( $result === [] && $queued === [] ) {
            return [];
        }
        $result = $result ?: $queued;
        if ( $next === 0 && ( $result['status'] ?? '' ) === 'retry' ) {
            $result['status'] = 'skipped';
        }
        $result['next_attempt'] = $next;
        return $result;
    }

    public static function cancel(): void {
        foreach ( [ self::PENDING, self::STOCK_PENDING ] as $name ) {
            for ( $try = 0; $try < self::WRITE_ATTEMPTS; $try++ ) {
                $pending = YSCacheSettingsRepo::readPurgeRecord( $name );
                if ( $pending === [] || YSCacheSettingsRepo::changePurgeRecord( $name, $pending, null ) ) {
                    break;
                }
            }
        }
        wp_unschedule_hook( self::HOOK );
    }

    public static function cronSchedules( array $schedules ): array {
        $schedules[self::RECOVERY] = [ 'interval' => self::RECOVERY_SECONDS, 'display' => 'YS CACHE' ];
        return $schedules;
    }

    public static function recoverSchedule(): void {
        if ( wp_next_scheduled( self::HOOK, [ self::RECOVERY ] ) ) {
            return;
        }
        foreach ( [ self::PENDING, self::STOCK_PENDING ] as $name ) {
            $pending = YSCacheSettingsRepo::readPurgeRecord( $name );
            if ( ! empty( $pending['change'] ) ) {
                self::ensureRecovery();
                self::schedule( (int) ( $pending['next_run'] ?? time() ) );
            }
        }
    }

    public static function run(): void {
        global $wpdb;
        $lock = 'ys_cache_enhance_' . substr( hash( 'sha256', DB_NAME . '/' . $wpdb->options ), 0, 44 );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== '1' ) {
            self::schedule( time() + self::COALESCE_SECONDS );
            return;
        }
        try {
            self::runJob( self::PENDING );
            self::runJob( self::STOCK_PENDING );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function runJob( string $name ): void {
        $pending = YSCacheSettingsRepo::readPurgeRecord( $name );
        if ( empty( $pending['change'] ) ) {
            return;
        }
        $settings = YSCache::settings( true );
        $stock = $name === self::STOCK_PENDING;
        $providers = (array) ( $settings['providers'] ?? [] );
        $cdn = self::automaticCdnLayers( $providers );
        if ( empty( $settings['auto_purge'] ) || self::target( $providers, $cdn, $stock, ! empty( $settings['enabled'] ) ) !== ( $pending['target'] ?? '' ) ) {
            YSCacheSettingsRepo::changePurgeRecord( $name, $pending, null );
            return;
        }
        $now = time();
        $due = (int) ( $pending['next_run'] ?? $now );
        if ( $due > $now ) {
            self::schedule( $due );
            return;
        }
        $dispatchProviders = self::dispatchProviders( $providers, $cdn );
        $batchLimit = $stock ? min( self::STOCK_BATCH, YSCacheProviders::targetBatchLimit( $dispatchProviders ) ) : 0;
        $batch = $stock ? array_slice( (array) ( $pending['urls'] ?? [] ), 0, $batchLimit, true ) : [];
        $attempt = max( 0, (int) ( $pending['attempt'] ?? 0 ) );
        $updated = (int) ( $pending['updated_at'] ?? $now );
        $expires = $updated + self::RETRY_WINDOW;
        if ( ( $stock && $batch === [] ) || ( $attempt > 0 && $now > $expires ) ) {
            self::finish( $name, $pending, $batch, $stock && $batch === [] ? 'skipped' : 'error', false, 0, [] );
            return;
        }

        $delay = self::RETRY_DELAYS[ min( $attempt, count( self::RETRY_DELAYS ) - 1 ) ];
        $claimed = array_replace( $pending, [
            'attempt'    => $attempt + 1,
            'updated_at' => $updated,
            'next_run'   => $now < $expires ? min( $now + $delay, $expires ) : $now + $delay,
        ] );
        if ( ! YSCacheSettingsRepo::changePurgeRecord( $name, $pending, $claimed ) ) {
            return;
        }
        // Reserve recovery before external calls. If PHP stops, the row still has a due time.
        self::ensureRecovery();
        self::schedule( $claimed['next_run'] );
        try {
            $results = $stock ? YSCache::purgeStockPages( array_keys( $batch ), $dispatchProviders ) :
                ( $cdn === [] && ! isset( $dispatchProviders['litespeed'] )
                    ? YSCacheProviders::purge( $providers, 'enhance' ) : YSCacheProviders::purge( $dispatchProviders ) );
            $result = end( $results ) ?: [ 'status' => 'skipped' ];
        } catch ( \Throwable $error ) {
            $results = [];
            $result = [ 'retryable' => true ];
        }
        $status = $result['status'] ?? 'error';
        if ( $status === 'skipped' && in_array( 'ok', array_column( $results, 'status' ), true ) ) {
            $status = 'ok';
        }
        $http = (int) ( $result['http'] ?? 0 );
        $retryable = $status === 'error' && ( ! empty( $result['retryable'] ) || $http === 408 || $http === 429 || $http >= 500 );
        self::finish( $name, $claimed, $batch, $status, $retryable, $http, $results );
    }

    private static function dispatchProviders( array $providers, array $cdn ): array {
        $origins = [ 'enhance' => $providers['enhance'] ?? [], 'cdn' => $cdn ];
        if ( ! empty( $providers['litespeed']['enabled'] ) ) {
            $origins['litespeed'] = $providers['litespeed'];
        }
        if ( ! empty( $providers['alias_hosts'] ) ) {
            $origins['alias_hosts'] = $providers['alias_hosts'];
            $origins['cdn_topology'] = $providers['cdn'] ?? [];
        }
        return $origins;
    }

    private static function finish( string $name, array $claimed, array $batch, string $status, bool $retryable, int $http, array $results ): void {
        $stock = $name === self::STOCK_PENDING;
        $now = time();
        for ( $try = 0; $try < self::WRITE_ATTEMPTS; $try++ ) {
            $pending = YSCacheSettingsRepo::readPurgeRecord( $name );
            if ( ( $pending['target'] ?? '' ) !== $claimed['target'] ) {
                return;
            }
            $retry = $retryable && $now < ( (int) $pending['updated_at'] + self::RETRY_WINDOW );
            $next = $pending;
            if ( ! $retry ) {
                if ( $stock ) {
                    foreach ( $batch as $url => $change ) {
                        if ( ( $next['urls'][ $url ] ?? null ) === $change ) {
                            unset( $next['urls'][ $url ] );
                        }
                    }
                    $more = ! empty( $next['urls'] );
                } else {
                    $more = $next['change'] !== $claimed['change'];
                }
                $delay = $stock ? self::STOCK_COALESCE_SECONDS : self::COALESCE_SECONDS;
                $next = $more ? array_replace( $next, [ 'attempt' => 0, 'next_run' => $now + $delay ] ) : null;
                if ( ! YSCacheSettingsRepo::changePurgeRecord( $name, $pending, $next ) ) {
                    continue;
                }
            }
            if ( $next !== null ) {
                self::schedule( (int) $next['next_run'] );
            }
            YSCacheSettingsRepo::writePurgeRecord( self::RESULT, [
                'status'       => $retry ? 'retry' : $status,
                'http'         => $http,
                'time'         => $now,
                'layers'       => $results,
                'scope'        => $stock ? 'stock' : 'all',
                'next_attempt' => (int) ( $next['next_run'] ?? 0 ),
            ] );
            return;
        }
    }

    private static function automaticCdnLayers( array $providers ): array {
        return array_values( array_filter( (array) ( $providers['cdn'] ?? [] ), static function ( $layer ): bool {
            return is_array( $layer ) && ! empty( $layer['enabled'] ) && YSCacheProviders::autoPurgePolicy( $layer ) === 'inherit';
        } ) );
    }

    private static function target( array $providers, array $cdn, bool $stock, bool $local ): string {
        $enhance = $providers['enhance'] ?? [];
        $enhance_enabled = ! empty( $enhance['enabled'] );
        $litespeed_enabled = ! empty( $providers['litespeed']['enabled'] );
        if ( $cdn === [] && ! $enhance_enabled && ! $litespeed_enabled && ! ( $stock && $local ) ) {
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
        $aliases = array_values( (array) ( $providers['alias_hosts'] ?? [] ) );
        if ( $cdn === [] && ! $litespeed_enabled && ! ( $stock && $local ) ) {
            if ( ! $url || ! $domain || ! $token ) {
                return '';
            }
            if ( ! $stock && $aliases === [] ) {
                // Preserve the identity of pending jobs created by older versions.
                return hash( 'sha256', (string) wp_json_encode( [ $url, $domain, $org ] ) );
            }
        }

        $layerKeys = [ 'provider', 'zone_id', 'auth_email', 'hostname', 'role', 'purge_entire_zone' ];
        $identity = [
            'enhance' => $enhance_enabled ? [ $url, $domain, $org ] : [],
            'cdn'     => array_map( static fn ( array $layer ): array => array_intersect_key( $layer, array_flip( $layerKeys ) ), $cdn ),
        ];
        if ( $litespeed_enabled ) {
            $identity['litespeed'] = true;
        }
        if ( $aliases !== [] ) {
            $identity['aliases'] = $aliases;
            $topologyKeys = array_merge( $layerKeys, [ 'scope', 'enabled', 'auto_purge' ] );
            $topology = array_values( array_filter( (array) ( $providers['cdn'] ?? [] ), 'is_array' ) );
            $identity['topology'] = array_map( static fn ( array $layer ): array => array_intersect_key( $layer, array_flip( $topologyKeys ) ), $topology );
        }
        if ( $stock ) {
            $identity['local'] = $local;
            $identity['scopes'] = array_column( $cdn, 'scope' );
        }
        return hash( 'sha256', (string) wp_json_encode( $identity ) );
    }

    private static function ensureRecovery(): void {
        if ( ! wp_next_scheduled( self::HOOK, [ self::RECOVERY ] ) ) {
            wp_schedule_event( time() + self::RECOVERY_SECONDS, self::RECOVERY, self::HOOK, [ self::RECOVERY ] );
        }
    }

    private static function schedule( int $due ): void {
        // Distinct due times avoid WordPress's ten-minute same-arguments duplicate window.
        $args = [ $due ];
        if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
            wp_schedule_single_event( max( time(), $due ), self::HOOK, $args );
        }
    }
}
