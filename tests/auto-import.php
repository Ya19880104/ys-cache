<?php

namespace YangSheep\Cache {
    final class YSCache
    {
        public static function settings(bool $fresh = false): array
        {
            return $GLOBALS['cache_settings'];
        }

        public static function enable(array $settings, ?string $migrationId = null): bool
        {
            $GLOBALS['events'][] = 'enable-cache';
            if (!$GLOBALS['can_enable']) {
                return false;
            }

            $GLOBALS['cache_settings'] = array_replace($settings, [
                'enabled' => true,
                'rocket_migration_id' => $migrationId,
            ]);
            return true;
        }
    }
}

namespace {
    use YangSheep\Cache\Migration\YSCacheImport;
    use YangSheep\Cache\Migration\YSCacheMigrationState;
    use YangSheep\Cache\Migration\YSCacheImportSettings;

    if (PHP_SAPI !== 'cli') {
        exit;
    }

    define('ABSPATH', sys_get_temp_dir() . '/ys-cache-import-' . bin2hex(random_bytes(8)) . '/');
    define('DB_NAME', 'ys-cache-test');
    define('WP_CONTENT_DIR', ABSPATH);
    define('WP_PLUGIN_DIR', ABSPATH . 'plugins');
    define('WP_ROCKET_CF_API_KEY', 'fixture-secret');
    define('CORE_FILE', 'ys-cache/ys-cache.php');
    define('SOURCE_PLUGIN_FILE', 'wp-rocket-stable/wp-rocket.php');
    define('COMPANION_FILE', 'ys-cache-rocket-import/ys-cache-rocket-import.php');
    define('RECOVERY_EVENT', 'ys_cache_rocket_import_recover');

    mkdir(ABSPATH . 'wp-admin/includes', 0700, true);
    touch(ABSPATH . 'wp-admin/includes/plugin.php');
    touch(ABSPATH . 'wp-admin/includes/upgrade.php');
    mkdir(WP_PLUGIN_DIR . '/ys-cache', 0700, true);
    register_shutdown_function(static function (): void {
        foreach ([ABSPATH . 'advanced-cache.php', WP_PLUGIN_DIR . '/' . CORE_FILE] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir(WP_PLUGIN_DIR . '/ys-cache');
        rmdir(WP_PLUGIN_DIR);
        unlink(ABSPATH . 'wp-admin/includes/upgrade.php');
        unlink(ABSPATH . 'wp-admin/includes/plugin.php');
        rmdir(ABSPATH . 'wp-admin/includes');
        rmdir(ABSPATH . 'wp-admin');
        rmdir(ABSPATH);
    });

    class WP_Error
    {
        public function __construct(public string $code, public string $message = '') {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }

    function __($text, $domain) { return $text; }
    function sanitize_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) ?: ''; }
    function wp_parse_url($url, $part) { return parse_url($url, $part); }
    function wp_json_encode($data) { return json_encode($data); }
    function wp_generate_uuid4() { return 'test-' . bin2hex(random_bytes(16)); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function current_user_can($capability, ...$plugin) { return $GLOBALS['authorized']; }
    function is_multisite() { return false; }
    function get_plugins() { return $GLOBALS['installed']; }
    function is_plugin_active($file) { return in_array($file, $GLOBALS['active'], true); }
    function get_option($name) { return $GLOBALS['options'][$name] ?? false; }
    function delete_option($name) { unset($GLOBALS['options'][$name]); }
    function plugin_basename($file) { return substr($file, strlen(WP_PLUGIN_DIR) + 1); }
    function add_filter($name, $callback, $priority) { $GLOBALS['filters'][$name] = $callback; }
    function remove_filter($name, $callback, $priority) { unset($GLOBALS['filters'][$name]); }

    function add_action($name, $callback, $priority = 10, $acceptedArgs = 1): void
    {
        $GLOBALS['actions'][$name][$priority][] = [$callback, $acceptedArgs];
    }

    function do_action($name, ...$args): void
    {
        $callbacks = $GLOBALS['actions'][$name] ?? [];
        ksort($callbacks);
        foreach ($callbacks as $group) {
            foreach ($group as [$callback, $acceptedArgs]) {
                $callback(...array_slice($args, 0, $acceptedArgs));
            }
        }
    }

    function wp_next_scheduled($name) { return $GLOBALS['cron'][$name] ?? false; }
    function wp_clear_scheduled_hook($name) { unset($GLOBALS['cron'][$name]); }

    function wp_schedule_single_event($when, $name): bool
    {
        if (!$GLOBALS['can_schedule']) {
            return false;
        }

        $GLOBALS['cron'][$name] = $when;
        $GLOBALS['events'][] = 'schedule-recovery';
        return true;
    }

    function activate_plugin($file, $redirect = '', $network = false, $silent = false): ?WP_Error
    {
        if (is_plugin_active($file)) {
            return null;
        }
        if ($file === CORE_FILE && !$GLOBALS['can_activate_core']) {
            return new WP_Error('mock_activate');
        }
        $GLOBALS['events'][] = 'activate:' . $file;
        $GLOBALS['active'][] = $file;
        if (!$silent) {
            // WordPress writes the updated list before firing this action.
            do_action('activated_plugin', $file, $network);
        }
        return null;
    }

    function deactivate_plugins($file, $silent, $network): void
    {
        $GLOBALS['events'][] = 'deactivate:' . $file;
        $GLOBALS['active'] = array_values(array_diff($GLOBALS['active'], [$file]));
        if ($file === SOURCE_PLUGIN_FILE) {
            verify(YSCacheImport::pending() && wp_next_scheduled(RECOVERY_EVENT), 'recovery is scheduled before stopping the source plugin');
            $GLOBALS['config_preserved'] = ($GLOBALS['filters']['rocket_set_wp_cache_constant'] ?? '') === '__return_false';
            file_put_contents(ABSPATH . 'advanced-cache.php', '');
        }
    }

    function dbDelta($sql): void
    {
        verify(str_contains($sql, 'wp_ys_cache_migration'), 'migration uses its own database table');
        $GLOBALS['wpdb']->tableExists = true;
    }

    function verify($passed, string $label): void
    {
        if (!$passed) {
            throw new RuntimeException('Failed: ' . $label);
        }
    }

    $wpdb = new class {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public bool $tableExists = false;
        public array $records = [];

        public function prepare($query, $value) { return [$query, $value]; }
        public function esc_like($value) { return addcslashes($value, '_%\\'); }
        public function get_charset_collate() { return ''; }

        public function get_var($statement)
        {
            [$query, $value] = $statement;
            if ($query === 'SHOW TABLES LIKE %s') {
                return $this->tableExists ? 'wp_ys_cache_migration' : null;
            }
            if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
                return 1;
            }
            verify(str_contains($query, 'FROM wp_ys_cache_migration'), 'migration state read uses its own table');
            return $this->records[$value] ?? null;
        }

        public function replace($table, $record): int
        {
            verify($table === 'wp_ys_cache_migration' && $this->tableExists, 'migration state write uses its own table');
            $this->records[$record['record_name']] = $record['record_value'];
            return 1;
        }

        public function delete($table, $where): int
        {
            verify($table === 'wp_ys_cache_migration', 'migration state delete uses its own table');
            unset($this->records[$where['record_name']]);
            return 1;
        }
    };
    require dirname(__DIR__) . '/src/YSCacheProviders.php';
    require dirname(__DIR__) . '/src/Migration/YSCacheImportSettings.php';
    require dirname(__DIR__) . '/src/Migration/YSCacheMigrationState.php';
    require dirname(__DIR__) . '/src/Migration/YSCacheImport.php';

    $rocketSettings = [
        'cache_reject_uri' => ['/saved/(.*)', '/%E7%B6%B2%E9%A0%81/', 'https://sample.test/catalog/'],
        'cache_reject_cookies' => ['fixture_login'],
        'cache_reject_ua' => ['(.*)Spider(.*)'],
        'purge_cron_interval' => 2,
        'purge_cron_unit' => 'DAY_IN_SECONDS',
        'do_cloudflare' => 1,
        'cloudflare_zone_id' => str_repeat('a', 32),
        'cloudflare_email' => 'owner@example.com',
        'cdn' => 1,
        'cdn_cnames' => ['https://images.example.com/'],
        'cdn_zone' => ['images'],
    ];

    function fresh_request(): void
    {
        foreach ([[YSCacheImport::class, 'sourceTouched', false], [YSCacheMigrationState::class, 'exists', []]] as [$class, $name, $initial]) {
            $property = new ReflectionProperty($class, $name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $property->setValue(null, $initial);
        }
    }

    function fixture(bool $coreActive = true): void
    {
        global $wpdb, $rocketSettings, $active, $events, $filters, $cron, $options, $installed, $cache_settings;
        global $authorized, $can_enable, $can_activate_core, $can_schedule, $config_preserved;
        fresh_request();
        $active = $coreActive ? [SOURCE_PLUGIN_FILE, CORE_FILE] : [SOURCE_PLUGIN_FILE];
        $installed = [SOURCE_PLUGIN_FILE => ['Name' => 'WP Rocket']];
        $events = $filters = $cron = [];
        $options = ['wp_rocket_settings' => $rocketSettings];
        $cache_settings = ['enabled' => false];
        $authorized = $can_enable = $can_activate_core = $can_schedule = true;
        $config_preserved = false;
        $wpdb->tableExists = false;
        $wpdb->records = [];
        touch(WP_PLUGIN_DIR . '/' . CORE_FILE);
        file_put_contents(ABSPATH . 'advanced-cache.php', '<?php use WP_Rocket\Buffer\Cache; define("WP_ROCKET_ADVANCED_CACHE", true);');
    }

    $actions = [];
    YSCacheImport::register(WP_PLUGIN_DIR . '/' . CORE_FILE);

    fixture(false);
    activate_plugin('unrelated/example.php');
    verify($events === ['activate:unrelated/example.php'] && is_plugin_active(SOURCE_PLUGIN_FILE), 'unrelated activation leaves the source plugin active');
    $events = [];
    activate_plugin(CORE_FILE);
    verify($events === ['activate:' . CORE_FILE, 'schedule-recovery', 'deactivate:' . SOURCE_PLUGIN_FILE, 'enable-cache'], 'main activation triggers cutover after registration');
    verify(is_plugin_active(CORE_FILE) && !is_plugin_active(SOURCE_PLUGIN_FILE) && $cache_settings['enabled'], 'correct active set on successful cutover');
    verify($config_preserved && !$filters && !$cron && !YSCacheImport::pending(), 'configuration flag and recovery record after success');
    $result = YSCacheImport::lastResult();
    verify($result['state'] === 'success' && $cache_settings['ttl_seconds'] === 172800 && !isset($cache_settings['ttl']) && !$result['warnings'], 'persisted result and second-based lifetime');
    verify($cache_settings['exclude_paths'] === "/saved/*\n/網頁/\n/catalog/", 'path conversion');
    verify($cache_settings['exclude_cookies'] === 'fixture_login', 'cookie conversion');
    verify($cache_settings['exclude_agents'] === 'Spider', 'user agent conversion');
    $cdn = $cache_settings['providers']['cdn'];
    verify($cdn[0]['token_constant'] === 'WP_ROCKET_CF_API_KEY' && $cdn[0]['token'] === '', 'Cloudflare key held in config');
    verify($cdn[1]['rewrite_images'] && $cdn[1]['hostname'] === 'images.example.com', 'image CNAME conversion');
    verify(array_keys($options) === ['wp_rocket_settings'] && !str_contains(json_encode($wpdb->records), WP_ROCKET_CF_API_KEY), 'migration state avoids WP options and credentials');

    fixture(false);
    $cache_settings['exclude_paths'] = '/existing/';
    $active[] = COMPANION_FILE;
    activate_plugin(CORE_FILE);
    verify($events === ['activate:' . CORE_FILE, 'deactivate:' . COMPANION_FILE, 'schedule-recovery', 'deactivate:' . SOURCE_PLUGIN_FILE, 'enable-cache'], 'activation retires the obsolete add-on and switches once');
    verify(str_starts_with($cache_settings['exclude_paths'], "/existing/\n"), 'stored settings merge after main activation');

    fixture(false);
    activate_plugin(CORE_FILE, '', false, true);
    $active[] = COMPANION_FILE;
    do_action('admin_init');
    verify($events === ['activate:' . CORE_FILE, 'deactivate:' . COMPANION_FILE] && is_plugin_active(SOURCE_PLUGIN_FILE), 'silent update retires the add-on and keeps cache configuration untouched');

    fixture();
    $installed = $active = [];
    activate_plugin(CORE_FILE);
    verify($events === ['activate:' . CORE_FILE] && !YSCacheImport::lastResult(), 'no supported source plugin is an idle activation');

    fixture(false);
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'core' && !$events && is_plugin_active(SOURCE_PLUGIN_FILE), 'inactive core stops before deactivation');

    fixture(false);
    $active = [];
    activate_plugin(CORE_FILE);
    verify($events === ['activate:' . CORE_FILE] && !$cache_settings['enabled'], 'an inactive source is not automatically imported');

    fixture();
    file_put_contents(ABSPATH . 'advanced-cache.php', '<?php // managed by a different plugin');
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'dropin' && !$events && is_plugin_active(SOURCE_PLUGIN_FILE), 'foreign drop-in prevents deactivation');

    fixture();
    $options['wp_rocket_settings']['cache_reject_uri'] = ['/anything/(one|two)/'];
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'unsupported' && !$events && is_plugin_active(SOURCE_PLUGIN_FILE), 'unsupported exclusions prevent deactivation');

    fixture();
    $authorized = false;
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'permission' && !$events && is_plugin_active(SOURCE_PLUGIN_FILE), 'capabilities gate mutations');

    fixture();
    $can_schedule = false;
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'schedule' && !$events && !YSCacheImport::pending() && is_plugin_active(SOURCE_PLUGIN_FILE), 'unavailable recovery scheduling prevents deactivation');

    fixture(false);
    $can_enable = false;
    activate_plugin(CORE_FILE);
    verify(YSCacheImport::pending() && wp_next_scheduled(RECOVERY_EVENT) && !is_plugin_active(SOURCE_PLUGIN_FILE) && is_plugin_active(CORE_FILE), 'failed enable schedules recovery of the original active plugin set');
    $authorized = false;
    do_action(RECOVERY_EVENT);
    verify(!is_plugin_active(SOURCE_PLUGIN_FILE), 'source plugin functions unload before automatic recovery');
    fresh_request();
    do_action('admin_init');
    verify(!is_plugin_active(SOURCE_PLUGIN_FILE), 'unauthorized admin request cannot recover');
    do_action(RECOVERY_EVENT);
    verify(is_plugin_active(CORE_FILE) && is_plugin_active(SOURCE_PLUGIN_FILE) && !$cache_settings['enabled'], 'cron restores the source without needing a logged-in user or stopping the main plugin');
    verify(!YSCacheImport::pending() && !$cron && YSCacheImport::lastResult()['state'] === 'restored', 'successful cron recovery clears record and schedule');
    $events = [];
    do_action(RECOVERY_EVENT);
    verify(!$events, 'repeated recovery cannot mutate after clearing the record');

    fixture();
    $can_enable = false;
    $result = YSCacheImport::migrate();
    verify(is_wp_error($result) && $result->code === 'restore' && YSCacheImport::pending(), 'a failed switch leaves a recovery record');
    fresh_request();
    file_put_contents(ABSPATH . 'advanced-cache.php', '<?php // another owner appeared before recovery');
    do_action(RECOVERY_EVENT);
    verify(YSCacheImport::pending() && !is_plugin_active(SOURCE_PLUGIN_FILE), 'foreign drop-in blocks recovery and keeps record');
    $authorized = false;
    $result = YSCacheImport::restore();
    verify(is_wp_error($result) && $result->code === 'permission', 'manual restore requires authorization');
    $authorized = true;
    file_put_contents(ABSPATH . 'advanced-cache.php', '');
    $result = YSCacheImport::restore();
    verify(is_array($result) && is_plugin_active(SOURCE_PLUGIN_FILE) && !YSCacheImport::pending(), 'authorized manual restore finishes when conflict clears');

    fixture();
    $can_enable = false;
    YSCacheImport::migrate();
    $record = YSCacheMigrationState::read('restore');
    $cache_settings = ['enabled' => true, 'rocket_migration_id' => $record['migration_id']];
    fresh_request();
    do_action(RECOVERY_EVENT);
    verify(!is_plugin_active(SOURCE_PLUGIN_FILE) && is_plugin_active(CORE_FILE) && !YSCacheImport::pending(), 'recovery recognizes a committed migration');

    $existing = ['providers' => ['opcache' => ['enabled' => true]], 'exclude_paths' => '/existing/'];
    $result = YSCacheImportSettings::convert(['cache_reject_uri' => ['/new/(.*)']], $existing);
    verify($result['settings']['exclude_paths'] === "/existing/\n/new/*" && $result['settings']['providers']['opcache']['enabled'], 'existing exclusions and providers survive');

    $result = YSCacheImportSettings::convert(['purge_cron_interval' => '0.25', 'purge_cron_unit' => 'MINUTE_IN_SECONDS'], []);
    verify($result['settings']['ttl_seconds'] === 15, 'fractional minutes are preserved to the second');
    $result = YSCacheImportSettings::convert(['purge_cron_interval' => '0.001', 'purge_cron_unit' => 'SECOND_IN_SECONDS'], []);
    verify($result['settings']['ttl_seconds'] === 1, 'positive subsecond lifetimes retain a one-second minimum');
    $result = YSCacheImportSettings::convert(['purge_cron_interval' => 8, 'purge_cron_unit' => 'DAY_IN_SECONDS'], []);
    verify($result['settings']['ttl_seconds'] === 604800 && count($result['warnings']) === 1, 'long lifetimes are capped at seven days');
    $result = YSCacheImportSettings::convert(['purge_cron_interval' => 0], ['ttl' => 37]);
    verify($result['settings']['ttl_seconds'] === 2220 && !isset($result['settings']['ttl']), 'legacy lifetime survives an indefinite source value');

    echo "Automatic import regression checks passed.\n";
}
