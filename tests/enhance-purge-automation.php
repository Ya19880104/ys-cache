<?php

namespace YangSheep\Cache {
    final class YSCache {
        public static function settings(): array {
            return [ 'providers' => $GLOBALS['provider_settings'], 'auto_purge' => $GLOBALS['master_auto_purge'] ];
        }
    }

    final class YSCacheProviders {
        public static function autoPurgePolicy( array $layer ): string {
            return in_array( $layer['auto_purge'] ?? 'inherit', [ 'off', false ], true ) ? 'off' : 'inherit';
        }
        public static function purge( array $providers, string $scope = 'all' ): array {
            \verify( $scope === $GLOBALS['purge_scope'], 'automatic dispatch uses the expected scope' );
            \verify( $providers === ( $GLOBALS['purge_settings'] ?? $GLOBALS['provider_settings'] ), 'uses current provider settings' );
            $pending = \YangSheep\Cache\Database\YSCacheSettingsRepo::readPurgeRecord( 'enhance_purge_pending' );
            \verify( ! empty( $pending['change'] ) && ! empty( $pending['target'] ), 'pending recorded before dispatch' );
            if ( ! $GLOBALS['running_worker'] ) {
                \verify( ! empty( $GLOBALS['scheduled'] ), 'worker scheduled before live dispatch' );
            }
            $GLOBALS['dispatched'][] = $pending['change'];
            if ( $GLOBALS['on_dispatch'] ) {
                ( $GLOBALS['on_dispatch'] )();
            }
            $response = array_shift( $GLOBALS['responses'] );
            return isset( $response['status'] ) ? [ $response ] : $response;
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

        public function prepare( string $query, string $parameter ): array {
            return [ $query, $parameter ];
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

    function wp_next_scheduled( string $hook ): int|false {
        return $GLOBALS['scheduled'][ $hook ] ?? false;
    }

    function wp_schedule_single_event( int $when, string $hook ): bool {
        $GLOBALS['scheduled'][ $hook ] = $when;
        return true;
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
        $GLOBALS['scheduled'] = $GLOBALS['responses'] = $GLOBALS['dispatched'] = [];
        $GLOBALS['uuid'] = 0;
        $GLOBALS['on_dispatch'] = null;
        $GLOBALS['running_worker'] = false;
        $GLOBALS['master_auto_purge'] = true;
        $GLOBALS['purge_scope'] = 'enhance';
        $GLOBALS['purge_settings'] = null;
        return $GLOBALS['provider_settings'] = [ 'enhance' => array_replace( [
            'enabled'    => true,
            'url'        => 'https://panel.example.test',
            'domain_id'  => 'c3100bbd-93c1-405f-8e69-88a57b24512e',
            'token'      => 'test-token',
        ], $overrides ) ];
    }

    function pending(): array {
        return YSCacheSettingsRepo::readPurgeRecord( 'enhance_purge_pending' );
    }

    function content_change( array $providers ): void {
        YSCacheEnhancePurge::onContentChange( [ 'providers' => $providers, 'auto_purge' => $GLOBALS['master_auto_purge'] ] );
    }

    function run_worker(): void {
        $hook = 'ys_cache_retry_enhance_purge';
        verify( isset( $GLOBALS['scheduled'][ $hook ] ), 'scheduled worker is registered' );
        unset( $GLOBALS['scheduled'][ $hook ] );
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
    verify( $dispatched === [ 'mutation-1' ] && ! pending(), 'successful purge clears pending record' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'ok', 'successful purge records status' );
    run_worker();
    verify( count( $dispatched ) === 1, 'pre-scheduled worker is a no-op after success' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 503 ], [ 'status' => 'ok', 'http' => 204 ] ];
    content_change( $settings );
    verify( ( pending()['attempt'] ?? null ) === 1 && count( $dispatched ) === 1, 'retryable error increments pending attempt' );
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'retry', 'transient failure records retry state' );
    run_worker();
    verify( $dispatched === [ 'mutation-1', 'mutation-1' ] && ! pending(), 'scheduled retry recovers the same mutation' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 503 ] ];
    content_change( $settings );
    $master_auto_purge = false;
    run_worker();
    verify( $dispatched === [ 'mutation-1' ] && ! pending(), 'disabling automatic purges cancels a pending retry' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 403 ] ];
    content_change( $settings );
    verify( ! pending() && YSCacheEnhancePurge::lastResult()['status'] === 'error', 'HTTP 403 is terminal' );
    run_worker();
    verify( count( $dispatched ) === 1, 'HTTP 403 does not dispatch again' );

    $settings = prepare_fixture();
    $responses = [ [ 'status' => 'error', 'http' => 429 ] ];
    content_change( $settings );
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
    verify( YSCacheEnhancePurge::lastResult()['status'] === 'retry', 'temporary CDN failure retries the ordered chain' );
    verify( array_column( YSCacheEnhancePurge::lastResult()['layers'], 'layer' ) === [ 'Enhance Nginx', 'Bunny CDN' ], 'result includes only attempted layers in order' );
    verify( ! str_contains( json_encode( $wpdb->rows ), 'bunny-secret' ), 'CDN credentials stay outside job state' );
    run_worker();
    verify( $dispatched === [ 'mutation-1', 'mutation-1' ] && ! pending(), 'automatic CDN retry succeeds' );
    verify( YSCacheEnhancePurge::lastResult()['layers'] === [ $origin_ok, $bunny_ok, $cloudflare_ok ], 'success records all three layers in original order' );

    $responses = [ [ $origin_ok, array_replace( $bunny_ok, [ 'status' => 'error', 'http' => 503 ] ) ] ];
    content_change( $provider_settings );
    $provider_settings['cdn'] = array_reverse( $provider_settings['cdn'] );
    run_worker();
    verify( count( $dispatched ) === 3 && ! pending(), 'changed CDN order discards obsolete retry' );

    echo "Automatic origin and CDN purge checks passed.\n";
}
