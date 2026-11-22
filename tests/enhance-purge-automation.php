<?php

namespace YangSheep\Cache {
    function time(): int {
        return $GLOBALS['now'];
    }

    final class YSCache {
        public static function settings( bool $fresh = false ): array {
            return [ 'providers' => $GLOBALS['provider_settings'], 'auto_purge' => $GLOBALS['master_auto_purge'], 'enabled' => $GLOBALS['local_cache'] ];
        }

        public static function purgeStockPages( array $urls, array $providers ): array {
            \verify( $GLOBALS['running_worker'], 'stock dispatch only runs in the worker' );
            \verify( count( $urls ) <= 20, 'stock worker respects its per-dispatch URL limit' );
            $GLOBALS['stock_dispatched'][] = [ $urls, $providers ];
            return \test_provider_response();
        }
    }

    final class YSCacheProviders {
        public static function targetBatchLimit( array $providers ): int {
            return $GLOBALS['stock_batch_limit'];
        }

        public static function autoPurgePolicy( array $layer ): string {
            return in_array( $layer['auto_purge'] ?? 'inherit', [ 'off', false ], true ) ? 'off' : 'inherit';
        }
        public static function purge( array $providers, string $scope = 'all' ): array {
            \verify( $GLOBALS['running_worker'], 'provider dispatch only runs in the worker' );
            \verify( $scope === $GLOBALS['purge_scope'], 'automatic dispatch uses the expected scope' );
            \verify( $providers === ( $GLOBALS['purge_settings'] ?? $GLOBALS['provider_settings'] ), 'uses current provider settings' );
            $pending = \YangSheep\Cache\Database\YSCacheSettingsRepo::readPurgeRecord( 'enhance_purge_pending' );
            \verify( ! empty( $pending['change'] ) && ! empty( $pending['target'] ), 'pending recorded before dispatch' );
            \verify( $pending['next_run'] > time(), 'recovery recorded before dispatch' );
            $GLOBALS['dispatched'][] = $pending['change'];
            return \test_provider_response();
        }
    }
}

namespace {
    use YangSheep\Cache\Database\YSCacheSettingsRepo;
    use YangSheep\Cache\YSCacheEnhancePurge;

    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    define( 'ABSPATH', __DIR__ );
    define( 'DB_NAME', 'enhance-purge-test' );

    final class TestPurgeDatabase {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public array $rows = [];
        public bool $locked = false;
        public bool $rejectWrites = false;
        public $beforeInsert = null;

        public function prepare( string $query, string ...$parameters ): array {
            return [ $query, ...$parameters ];
        }

        public function query( array $query ): int|false {
            [ $sql, $name, $value ] = $query;
            verify( str_starts_with( $sql, 'INSERT IGNORE INTO wp_ys_cache_settings' ), 'queue insert only touches the settings table' );
            if ( $this->beforeInsert ) {
                ( $this->beforeInsert )();
            }
            if ( $this->rejectWrites ) {
                return false;
            }
            if ( isset( $this->rows[ $name ] ) ) {
                return 0;
            }
            $this->rows[ $name ] = $value;
            return 1;
        }

        public function get_var( array $query ): ?string {
            [ $sql, $argument ] = $query;
            if ( str_contains( $sql, 'GET_LOCK' ) ) {
                if ( $this->locked ) {
                    return '0';
                }
                $this->locked = true;
                return '1';
            }
            if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
                $this->locked = false;
                return '1';
            }
            return $this->rows[ $argument ] ?? null;
        }

        public function replace( string $table, array $row ): int|false {
            verify( $table === 'wp_ys_cache_settings', 'writes stay in the settings table' );
            if ( $this->rejectWrites ) {
                return false;
            }
            $this->rows[ $row['setting_name'] ] = $row['setting_value'];
            return 1;
        }

        public function delete( string $table, array $where ): int {
            verify( $table === 'wp_ys_cache_settings', 'conditional delete stays in the settings table' );
            $name = $where['setting_name'];
            if ( ( $this->rows[ $name ] ?? null ) !== $where['setting_value'] ) {
                return 0;
            }
            unset( $this->rows[ $name ] );
            return 1;
        }

        public function update( string $table, array $row, array $where ): int {
            verify( $table === 'wp_ys_cache_settings', 'conditional retry stays in the settings table' );
            $name = $where['setting_name'];
            if ( ( $this->rows[ $name ] ?? null ) !== $where['setting_value'] ) {
                return 0;
            }
            $this->rows[ $name ] = $row['setting_value'];
            return 1;
        }
    }

    function add_action( string $hook, callable $callback ): void {
        $GLOBALS['hooks'][ $hook ] = $callback;
    }

    function add_filter( string $hook, callable $callback ): void {
        $GLOBALS['filters'][ $hook ] = $callback;
    }

    function wp_next_scheduled( string $hook, array $args = [] ): int|false {
        return $GLOBALS['scheduled'][ event_key( $hook, $args ) ]['when'] ?? false;
    }

    function wp_schedule_single_event( int $when, string $hook, array $args ): bool {
        $GLOBALS['scheduled'][ event_key( $hook, $args ) ] = [ 'when' => $when, 'recurs' => 0 ];
        return true;
    }

    function wp_schedule_event( int $when, string $recurrence, string $hook, array $args ): bool {
        $schedule = ( $GLOBALS['filters']['cron_schedules'] )( [] );
        $GLOBALS['scheduled'][ event_key( $hook, $args ) ] = [ 'when' => $when, 'recurs' => $schedule[ $recurrence ]['interval'] ];
        return true;
    }

    function wp_unschedule_hook( string $hook ): bool {
        foreach ( array_keys( $GLOBALS['scheduled'] ) as $key ) {
            if ( str_starts_with( $key, $hook . '|' ) ) {
                unset( $GLOBALS['scheduled'][ $key ] );
            }
        }
        return true;
    }

    function event_key( string $hook, array $args ): string {
        return $hook . '|' . serialize( $args );
    }

    function wp_generate_uuid4(): string {
        return 'mutation-' . ++$GLOBALS['uuid'];
    }

    function wp_json_encode( array $record ): string {
        return json_encode( $record, JSON_THROW_ON_ERROR );
    }

    function verify( bool $condition, string $label ): void {
        if ( ! $condition ) {
            throw new RuntimeException( 'Failed: ' . $label );
        }
    }

    function prepare_fixture( array $overrides = [] ): array {
        $GLOBALS['wpdb'] = new TestPurgeDatabase();
        $GLOBALS['scheduled'] = $GLOBALS['responses'] = $GLOBALS['dispatched'] = $GLOBALS['stock_dispatched'] = [];
        $GLOBALS['now'] = 1700000000;
        $GLOBALS['uuid'] = 0;
        $GLOBALS['on_dispatch'] = null;
        $GLOBALS['running_worker'] = false;
        $GLOBALS['master_auto_purge'] = true;
        $GLOBALS['local_cache'] = false;
        $GLOBALS['stock_batch_limit'] = 100;
        $GLOBALS['purge_scope'] = 'enhance';
        $GLOBALS['purge_settings'] = null;
        return $GLOBALS['provider_settings'] = [ 'enhance' => array_replace( [
            'enabled'    => true,
            'url'        => 'https://panel.example.test',
            'domain_id'  => 'c3100bbd-93c1-405f-8e69-88a57b24512e',
            'token'      => 'test-token',
        ], $overrides ) ];
    }

    function pending( bool $stock = false ): array {
        return YSCacheSettingsRepo::readPurgeRecord( $stock ? 'stock_purge_pending' : 'enhance_purge_pending' );
    }

    function content_change( array $providers ): void {
        YSCacheEnhancePurge::onContentChange( [ 'providers' => $providers, 'auto_purge' => $GLOBALS['master_auto_purge'] ] );
    }

    function test_provider_response(): array {
        if ( $GLOBALS['on_dispatch'] ) {
            ( $GLOBALS['on_dispatch'] )();
        }
        $response = array_shift( $GLOBALS['responses'] );
        return isset( $response['status'] ) ? [ $response ] : $response;
    }

    function stock_change( array $urls ): void {
        YSCacheEnhancePurge::onStockChange( \YangSheep\Cache\YSCache::settings(), $urls );
    }

    function run_worker( ?int $at = null ): void {
        $hook = 'ys_cache_retry_enhance_purge';
        if ( $at === null ) {
            verify( count( $GLOBALS['scheduled'] ) > 0, 'scheduled worker is registered' );
            $at = min( array_column( $GLOBALS['scheduled'], 'when' ) );
        }
        $GLOBALS['now'] = max( $GLOBALS['now'], $at );
        foreach ( $GLOBALS['scheduled'] as $key => $event ) {
            if ( $event['when'] <= $GLOBALS['now'] ) {
                if ( $event['recurs'] > 0 ) {
                    $GLOBALS['scheduled'][ $key ]['when'] = $GLOBALS['now'] + $event['recurs'];
                } else {
                    unset( $GLOBALS['scheduled'][ $key ] );
                }
            }
        }
        $GLOBALS['running_worker'] = true;
        try {
            ( $GLOBALS['hooks'][ $hook ] )();
        } finally {
            $GLOBALS['running_worker'] = false;
        }
    }

    require dirname( __DIR__ ) . '/src/Database/YSCacheSettingsRepo.php';
    require dirname( __DIR__ ) . '/src/YSCacheEnhancePurge.php';
    YSCacheEnhancePurge::register();

    foreach ( [ [ 'enabled' => false ], [ 'token' => '' ] ] as $overrides ) {
        content_change( prepare_fixture( $overrides ) );
        verify( ! $dispatched && ! $scheduled && ! pending(), 'disabled or incomplete credentials do not dispatch' );
    }

    $settings = prepare_fixture();
    $master_auto_purge = false;
    content_change( $settings );
    verify( ! $dispatched && ! $scheduled && ! pending(), 'master off prevents all provider dispatch' );

    $settings = prepare_fixture();
    $wpdb->rejectWrites = true;
    content_change( $settings );
    verify( ! $dispatched && ! $scheduled, 'failed durable write prevents dispatch' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'ok', 'http' => 204 ] ];
    content_change( $settings );
    $first_deadline = pending()['next_run'];
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'queued' && YSCacheEnhancePurge::lastResult()['next_attempt'] === $first_deadline, 'status reports the first queued job before provider dispatch' );
    $now += 7;
    content_change( $settings );
    verify( $dispatched === [] && pending()['next_run'] === $first_deadline && pending()['change'] === 'mutation-2', 'content edits merge without dispatch or postponing the deadline' );
    run_worker( $first_deadline - 1 );
    verify( $dispatched === [], 'early worker execution keeps the first deadline' );
    run_worker();
    verify( $dispatched === [ 'mutation-2' ] && ! pending(), 'merged edits perform one successful purge in the worker' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'ok', 'successful purge records status' );
    run_worker();
    verify( count( $dispatched ) === 1, 'pre-scheduled worker is a no-op after success' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 503 ], [ 'status' => 'ok', 'http' => 204 ] ];
    content_change( $settings );
    run_worker();
    verify( ( pending()['attempt'] ?? null ) === 1 && count( $dispatched ) === 1, 'retryable error increments pending attempt' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'retry', 'transient failure records retry state' );
    $retry_due = pending()['next_run'];
    content_change( $settings );
    verify( pending()['next_run'] === $retry_due && pending()['attempt'] === 1 && count( $dispatched ) === 1, 'new edits keep the active backoff' );
    run_worker( $retry_due );
    verify( $dispatched === [ 'mutation-1', 'mutation-2' ] && ! pending(), 'scheduled retry covers all edits accumulated during backoff' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 503 ] ];
    content_change( $settings );
    run_worker();
    $master_auto_purge = false;
    run_worker();
    verify( $dispatched === [ 'mutation-1' ] && ! pending(), 'disabling automatic purges cancels a pending retry' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 403 ] ];
    content_change( $settings );
    run_worker();
    verify( ! pending() && YSCacheEnhancePurge::lastResult()['status'] === 'error', 'HTTP 403 is terminal' );
    run_worker();
    verify( count( $dispatched ) === 1, 'HTTP 403 does not dispatch again' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 429 ] ];
    content_change( $settings );
    run_worker();
    $provider_settings['enhance']['domain_id'] = 'c9dc5866-52cf-4ce6-a91d-f7d06eb64692';
    run_worker();
    verify( count( $dispatched ) === 1 && ! pending(), 'obsolete target is discarded instead of clearing a different site' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'ok', 'http' => 204 ], [ 'status' => 'ok', 'http' => 204 ] ];
    $on_dispatch = static function () use ( $settings ): void {
        $GLOBALS['on_dispatch'] = null;
        content_change( $settings );
    };
    content_change( $settings );
    run_worker();
    verify( $dispatched === [ 'mutation-1' ] && ( pending()['change'] ?? '' ) === 'mutation-2', 'older completion preserves the newer generation' );
    verify( ! str_contains( json_encode( $wpdb->rows ), 'test-token' ), 'pending and result records do not store credentials' );
    run_worker();
    verify( $dispatched === [ 'mutation-1', 'mutation-2' ] && ! pending(), 'worker dispatches the newer generation' );

    prepare_fixture();
    $provider_settings['cdn'] = [
        [ 'provider' => 'bunny', 'enabled' => true, 'zone_id' => '7', 'token' => 'bunny-secret' ],
        [ 'provider' => 'gcore', 'enabled' => true, 'auto_purge' => 'off', 'zone_id' => '8', 'token' => 'gcore-secret' ],
        [ 'provider' => 'cloudflare', 'enabled' => true, 'auto_purge' => 'inherit', 'zone_id' => 'cf-zone', 'token' => 'cf-secret' ],
    ];
    $provider_settings['opcache'] = [ 'enabled' => true ];
    $purge_scope = 'all';
    $purge_settings = [
        'enhance' => $provider_settings['enhance'],
        'cdn' => [ $provider_settings['cdn'][0], $provider_settings['cdn'][2] ],
    ];
    $origin_ok = [ 'layer' => 'Enhance Nginx', 'status' => 'ok', 'http' => 204 ];
    $bunny_ok = [ 'layer' => 'Bunny CDN', 'status' => 'ok', 'http' => 204 ];
    $cloudflare_ok = [ 'layer' => 'Cloudflare', 'status' => 'ok', 'http' => 200 ];
    $responses = [
        [ $origin_ok, array_replace( $bunny_ok, [ 'status' => 'error', 'http' => 429 ] ) ],
        [ $origin_ok, $bunny_ok, $cloudflare_ok ],
    ];
    content_change( $provider_settings );
    run_worker();
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'retry', 'temporary CDN failure retries the ordered chain' );
    verify( array_column( YSCacheEnhancePurge::lastResult()['layers'], 'layer' ) === [ 'Enhance Nginx', 'Bunny CDN' ], 'result includes only attempted layers in order' );
    verify( ! str_contains( json_encode( $wpdb->rows ), 'bunny-secret' ), 'CDN credentials stay outside job state' );
    run_worker();
    verify( $dispatched === [ 'mutation-1', 'mutation-1' ] && ! pending(), 'automatic CDN retry succeeds' );
    verify( YSCacheEnhancePurge::lastResult()['layers'] === [ $origin_ok, $bunny_ok, $cloudflare_ok ], 'success records all three layers in original order' );

    $responses = [ [ $origin_ok, array_replace( $bunny_ok, [ 'status' => 'error', 'http' => 503 ] ) ] ];
    content_change( $provider_settings );
    run_worker( pending()['next_run'] );
    $provider_settings['cdn'] = array_reverse( $provider_settings['cdn'] );
    run_worker();
    verify( count( $dispatched ) === 3 && ! pending(), 'changed CDN order discards obsolete retry' );

    prepare_fixture( [ 'enabled' => false ] );
    $provider_settings['litespeed'] = [ 'enabled' => true ];
    $provider_settings['cdn'] = [
        [ 'provider' => 'bunny', 'enabled' => true, 'zone_id' => '7' ],
        [ 'provider' => 'gcore', 'enabled' => true, 'auto_purge' => 'off', 'zone_id' => '8' ],
    ];
    $purge_scope = 'all';
    $purge_settings = [
        'enhance' => $provider_settings['enhance'],
        'cdn' => [ $provider_settings['cdn'][0] ],
        'litespeed' => $provider_settings['litespeed'],
    ];
    $native_ok = [ 'layer' => 'LiteSpeed Cache', 'status' => 'ok', 'http' => 400 ];
    $native_unconfirmed = [ 'layer' => 'LiteSpeed Cache', 'status' => 'error', 'http' => 400, 'retryable' => true ];
    $responses = [ [ $native_unconfirmed ], [ $native_ok, $bunny_ok ], [ $native_unconfirmed ] ];
    content_change( $provider_settings );
    run_worker();
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'retry', 'unconfirmed native dispatch remains eligible for retry' );
    verify( YSCacheEnhancePurge::lastResult()['layers'] === [ $native_unconfirmed ], 'automatic job reports no CDN attempts while native dispatch is unconfirmed' );
    run_worker();
    verify( YSCacheEnhancePurge::lastResult()['layers'] === [ $native_ok, $bunny_ok ], 'retry clears LiteSpeed before CDN layers that follow the main setting' );
    content_change( $provider_settings );
    run_worker( pending()['next_run'] );
    $provider_settings['litespeed']['enabled'] = false;
    run_worker();
    verify( count( $dispatched ) === 3 && ! pending(), 'changing the origin owner discards the previous retry' );

    $settings = prepare_fixture();
    $wpdb->beforeInsert = static function () use ( $settings ): void {
        $GLOBALS['wpdb']->beforeInsert = null;
        $GLOBALS['now'] += 1;
        content_change( $settings );
    };
    $responses = [ $origin_ok ];
    content_change( $settings );
    verify( pending()['next_run'] === $now + 15, 'concurrent insert preserves the first committed deadline' );
    verify( count( array_filter( $scheduled, static fn ( array $event ): bool => ! $event['recurs'] ) ) === 1, 'concurrent saves schedule one first wake-up' );
    $scheduled = array_filter( $scheduled, static fn ( array $event ): bool => $event['recurs'] > 0 );
    run_worker();
    verify( count( $dispatched ) === 1 && ! pending(), 'recovery watchdog dispatches when a wake-up was lost' );

    $settings = prepare_fixture();
    $legacy_target = hash( 'sha256', wp_json_encode( [ $settings['enhance']['url'], $settings['enhance']['domain_id'], '' ] ) );
    YSCacheSettingsRepo::writePurgeRecord( 'enhance_purge_pending', [ 'target' => $legacy_target, 'change' => 'legacy', 'attempt' => 1 ] );
    $responses = [ $origin_ok ];
    $hooks['admin_init']();
    run_worker();
    verify( $dispatched === [ 'legacy' ] && ! pending(), 'older durable jobs are recovered without a schema change' );

    $settings = prepare_fixture();
    $responses = array_fill( 0, 24, [ 'status' => 'error', 'http' => 503 ] );
    content_change( $settings );
    $expires = $now + 86400;
    $delays = [];
    while ( pending() ) {
        $due = pending()['next_run'];
        run_worker( $due );
        if ( pending() ) {
            $delays[] = pending()['next_run'] - $due;
        }
    }
    verify( $now === $expires && count( $dispatched ) > 8, 'retryable outages get a final attempt at the full-day window' );
    verify( array_slice( $delays, 0, 3 ) === [ 30, 120, 300 ] && in_array( 14400, $delays, true ), 'backoff reaches hours without polling provider on each page view' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'error' && YSCacheEnhancePurge::lastResult()['next_attempt'] === 0, 'expiry reports a terminal error and no due retry' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 503 ] ];
    content_change( $settings );
    $expiry = $now + 86400;
    run_worker( pending()['next_run'] );
    run_worker( $expiry + 1 );
    verify( count( $dispatched ) === 1 && ! pending(), 'late Cron never retries beyond the outage window' );

    $old_settings = prepare_fixture();
    content_change( $old_settings );
    $provider_settings['enhance']['domain_id'] = 'c9dc5866-52cf-4ce6-a91d-f7d06eb64692';
    content_change( $provider_settings );
    $new_target = pending();
    content_change( $old_settings );
    verify( pending() === $new_target, 'a stale settings request cannot replace a newer provider target' );
    YSCacheEnhancePurge::cancel();
    verify( ! pending() && $scheduled === [], 'cancel removes the durable job and all wake-ups' );

    prepare_fixture( [ 'enabled' => false ] );
    $local_cache = true;
    $responses = array_fill( 0, 4, [ 'status' => 'skipped', 'http' => 0 ] );
    $stock_urls = array_map( static fn ( int $index ): string => "https://shop.example.test/products/example-{$index}/", range( 1, 45 ) );
    stock_change( array_slice( $stock_urls, 0, 30 ) );
    $stock_due = pending( true )['next_run'];
    $now += 12;
    stock_change( array_slice( $stock_urls, 20 ) );
    verify( ! pending() && count( pending( true )['urls'] ) === 45 && pending( true )['next_run'] === $stock_due && ! $stock_dispatched, 'stock only accumulates unique URLs in its own fixed window' );
    $on_dispatch = static function () use ( $stock_urls ): void {
        $GLOBALS['on_dispatch'] = null;
        stock_change( [ $stock_urls[0] ] );
    };
    run_worker( $stock_due );
    verify( count( pending( true )['urls'] ) === 26 && pending( true )['next_run'] === $stock_due + 60, 'stock keeps the unprocessed URLs and a URL edited during dispatch' );
    run_worker( pending( true )['next_run'] );
    run_worker( pending( true )['next_run'] );
    verify( array_column( $stock_dispatched, 1 ) === array_fill( 0, 3, [ 'enhance' => $provider_settings['enhance'], 'cdn' => [] ] ), 'stock worker does not enable an unrelated provider' );
    verify( count( array_unique( array_merge( ...array_column( $stock_dispatched, 0 ) ) ) ) === 45 && ! pending( true ) && ! $dispatched, 'stock completes every target without a full-site purge' );
    verify( YSCacheEnhancePurge::lastResult()['scope'] === 'stock' && YSCacheEnhancePurge::lastResult()['status'] === 'skipped', 'benign scoped skips report completion as skipped' );

    prepare_fixture( [ 'enabled' => false ] );
    $local_cache = true;
    $stock_batch_limit = 3;
    $responses = array_fill( 0, 2, [ 'status' => 'ok', 'http' => 200 ] );
    stock_change( array_slice( $stock_urls, 0, 5 ) );
    run_worker( pending( true )['next_run'] );
    verify( count( $stock_dispatched[0][0] ) === 3 && count( pending( true )['urls'] ) === 2, 'provider fanout limits the full job, not just the payload' );
    run_worker( pending( true )['next_run'] );
    verify( count( $stock_dispatched[1][0] ) === 2 && ! pending( true ), 'remaining targets run in the next stock window' );

    prepare_fixture( [ 'enabled' => false ] );
    $provider_settings['alias_hosts'] = [ 'de.shop.example.test' ];
    $provider_settings['cdn'] = [
        [ 'provider' => 'cloudflare', 'enabled' => true, 'zone_id' => 'canonical-zone' ],
        [ 'provider' => 'cloudflare', 'enabled' => true, 'zone_id' => 'alias-zone', 'hostname' => 'de.shop.example.test', 'auto_purge' => 'off', 'token' => 'alias-secret' ],
    ];
    $purge_settings = [
        'enhance' => $provider_settings['enhance'],
        'cdn' => [ $provider_settings['cdn'][0] ],
        'alias_hosts' => $provider_settings['alias_hosts'],
        'cdn_topology' => $provider_settings['cdn'],
    ];
    $purge_scope = 'all';
    $responses = [ $cloudflare_ok, [] ];
    content_change( $provider_settings );
    stock_change( [ $stock_urls[0] ] );
    verify( pending( true )['next_run'] - pending()['next_run'] === 45, 'whole-site and stock queues have independent deadlines' );
    run_worker( pending()['next_run'] );
    run_worker( pending( true )['next_run'] );
    verify( count( $dispatched ) === 1 && count( $stock_dispatched ) === 1 && $stock_dispatched[0][1] === $purge_settings, 'stock and full receive complete target topology and only inherited purge layers' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'skipped' && ! str_contains( json_encode( $wpdb->rows ), 'alias-secret' ), 'empty targets are benign and credentials are not persisted in queue state' );
    stock_change( [ $stock_urls[0] ] );
    $provider_settings['cdn'][1]['zone_id'] = 'different-manual-zone';
    run_worker( pending( true )['next_run'] );
    verify( count( $stock_dispatched ) === 1 && ! pending( true ), 'a changed manual alias route invalidates an older job target' );

    echo "Automatic origin and CDN purge checks passed.\n";
}
