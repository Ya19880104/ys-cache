<?php

namespace YangSheep\Cache\Migration;

use Throwable;
use WP_Error;
use YangSheep\Cache\YSCache;

defined('ABSPATH') || exit;

final class YSCacheImport
{
    private const COMPANION = 'ys-cache-rocket-import/ys-cache-rocket-import.php';
    private const RECOVERY_EVENT = 'ys_cache_rocket_import_recover';
    private const LEGACY_RESTORE = 'ys_cache_rocket_import_restore';
    private static bool $sourceTouched = false;
    private static string $plugin;

    public static function register(string $file): void
    {
        self::$plugin = plugin_basename($file);
        // The activated_plugin hook runs after WordPress has saved its active plugin list.
        add_action('activated_plugin', static function (string $plugin, bool $network): void {
            if ($plugin !== self::$plugin || $network || !self::authorized() || is_multisite() || self::pending()) {
                return;
            }
            self::retireCompanion();
            $source = self::findSourcePlugin();
            if ($source !== '' && is_plugin_active($source)) {
                self::migrate(true);
            }
        }, 30, 2);
        add_action(self::RECOVERY_EVENT, [self::class, 'recoverAutomatically']);
        add_action('admin_init', static function (): void {
            if (is_multisite() || !self::authorized()) {
                return;
            }
            self::recoverAutomatically();
            if (!self::pending()) {
                self::retireCompanion();
            }
        }, 2);
    }

    public static function findSourcePlugin(): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $first = '';
        foreach (get_plugins() as $file => $headers) {
            if (self::sourceKind($headers) !== '') {
                if (is_plugin_active($file)) {
                    return $file;
                }
                $first = $first ?: $file;
            }
        }
        return $first;
    }

    private static function sourceKind(array $headers): string
    {
        return match (strtolower($headers['Name'] ?? '')) {
            'wp rocket' => 'rocket',
            'flyingpress' => 'flyingpress',
            default => '',
        };
    }

    public static function migrate(bool $activeSourceOnly = false): array|WP_Error
    {
        if (!self::authorized()) {
            return self::permissionError();
        }
        return self::withLock(static function () use ($activeSourceOnly) {
            if (!YSCacheMigrationState::install() || !self::adoptLegacy()) {
                return self::stateError();
            }
            $result = self::cutover($activeSourceOnly);
            if (self::remember($result, 'success') && !is_wp_error($result)) {
                self::clearRecovery();
            }
            return $result;
        });
    }

    public static function restore(): array|WP_Error
    {
        return self::authorized() ? self::recover() : self::permissionError();
    }

    public static function recoverAutomatically(): void
    {
        // Only a recorded, previously authorized switch can be rolled back.
        if (!self::$sourceTouched && !is_multisite() && self::pending()) {
            $result = self::recover();
            if (is_wp_error($result) && $result->get_error_code() === 'lock' && !wp_next_scheduled(self::RECOVERY_EVENT)) {
                wp_schedule_single_event(time() + 60, self::RECOVERY_EVENT);
            }
        }
    }

    public static function lastResult(): array
    {
        return YSCacheMigrationState::read('result');
    }

    public static function pending(): bool
    {
        return (bool) YSCacheMigrationState::read('restore') || (bool) get_option(self::LEGACY_RESTORE);
    }

    private static function authorized(): bool
    {
        return current_user_can('manage_options') && current_user_can('activate_plugins');
    }

    private static function retireCompanion(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active(self::COMPANION) && current_user_can('deactivate_plugin', self::COMPANION)) {
            deactivate_plugins(self::COMPANION, true, false);
        }
    }

    private static function permissionError(): WP_Error
    {
        return new WP_Error('permission', __('Your account does not have permission to run the import.', 'ys-cache'));
    }

    private static function stateError(): WP_Error
    {
        return new WP_Error('migration_state', __('Could not save the import status. Check database permissions.', 'ys-cache'));
    }

    private static function adoptLegacy(): bool
    {
        $legacy = get_option(self::LEGACY_RESTORE);
        if (!$legacy) {
            return true;
        }
        if (!YSCacheMigrationState::read('restore') && !YSCacheMigrationState::write('restore', [
            'plugin' => is_array($legacy) ? ($legacy['plugin'] ?? '') : '',
            'rocket_active' => true,
            'legacy_enabled' => !empty($legacy['enabled']),
        ])) {
            return false;
        }
        delete_option(self::LEGACY_RESTORE);
        return true;
    }

    private static function remember(array|WP_Error $result, string $success): bool
    {
        if (is_wp_error($result)) {
            $states = ['source_plugin' => 'idle', 'restore' => 'restoring'];
            return YSCacheMigrationState::write('result', [
                'state' => $states[$result->get_error_code()] ?? 'error',
                'message' => $result->get_error_message(),
                'warnings' => [],
            ]);
        }
        return YSCacheMigrationState::write('result', [
            'state' => $result['state'] ?? $success,
            'message' => $result['message'],
            'warnings' => $result['warnings'] ?? [],
        ]);
    }

    private static function recover(): array|WP_Error
    {
        if (self::$sourceTouched) {
            return new WP_Error('restore', __('Waiting for the next WordPress request to restore the previous plugin.', 'ys-cache'));
        }
        return self::withLock(static function () {
            if (!YSCacheMigrationState::install() || !self::adoptLegacy()) {
                return self::stateError();
            }
            if (!YSCacheMigrationState::read('restore')) {
                $last = self::lastResult();
                if (in_array($last['state'] ?? '', ['restored', 'success'], true)) {
                    return $last;
                }
                return new WP_Error('restore_failed', __('There is no import to roll back.', 'ys-cache'));
            }
            $result = self::reactivate();
            if (self::remember($result, 'restored') && !is_wp_error($result)) {
                self::clearRecovery();
            }
            return $result;
        });
    }

    private static function withLock(callable $action): array|WP_Error
    {
        global $wpdb;
        if (is_multisite()) {
            return new WP_Error('multisite', __('Run the importer on a standalone WordPress site.', 'ys-cache'));
        }
        $lock = 'ys_rocket_' . substr(hash('sha256', DB_NAME . '/' . $wpdb->options), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            return new WP_Error('lock', __('Could not lock the import. Please try again shortly.', 'ys-cache'));
        }
        try {
            return $action();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function cutover(bool $activeSourceOnly): array|WP_Error
    {
        if (self::pending()) {
            return new WP_Error('restore', __('An unfinished import was found. Restoring the previous plugin state.', 'ys-cache'));
        }
        $sourcePlugin = self::findSourcePlugin();
        if ($sourcePlugin === '' || ($activeSourceOnly && !is_plugin_active($sourcePlugin))) {
            return new WP_Error('source_plugin', __('No supported cache plugin is installed.', 'ys-cache'));
        }
        if (!is_plugin_active(self::$plugin)) {
            return new WP_Error('core', __('Could not load YS CACHE. Check the plugin version and installation.', 'ys-cache'));
        }

        $active = is_plugin_active($sourcePlugin);
        if ($active && !current_user_can('deactivate_plugin', $sourcePlugin)) {
            return self::permissionError();
        }
        $plugins = get_plugins();
        $kind = self::sourceKind($plugins[$sourcePlugin]);
        if ($kind === 'flyingpress' && ($plugins[$sourcePlugin]['Version'] ?? '') !== '4.16.4') {
            return new WP_Error('version', __('This source plugin version has not been verified for automatic import.', 'ys-cache'));
        }
        foreach ($plugins as $file => $headers) {
            if ($file !== $sourcePlugin && self::sourceKind($headers) !== '' && is_plugin_active($file)) {
                return new WP_Error('source_conflict', __('Multiple supported cache plugins are active. Select one active source before importing.', 'ys-cache'));
            }
        }
        if ($active && !self::canDeactivate($sourcePlugin, $kind)) {
            return new WP_Error('dropin', __('Could not verify which plugin owns advanced-cache.php. Check your site\'s active cache plugin.', 'ys-cache'));
        }

        if ($active && $kind === 'flyingpress'
            && (has_action('deactivate_' . $sourcePlugin, ['FlyingPress\AdvancedCache', 'remove_advanced_cache']) === false
                || has_action('deactivate_' . $sourcePlugin, ['FlyingPress\WPCache', 'remove_constant']) === false)) {
            return new WP_Error('source_hooks', __('The active source plugin must be loaded to run its native cleanup.', 'ys-cache'));
        }
        $source = get_option($kind === 'flyingpress' ? 'FLYING_PRESS_CONFIG' : 'wp_rocket_settings');
        if (!is_array($source) || !$source) {
            return new WP_Error('settings', __('No compatible cache settings were found to import.', 'ys-cache'));
        }
        $migration = $kind === 'flyingpress'
            ? YSCacheImportSettings::fromFlyingPress($source, YSCache::settings(true))
            : YSCacheImportSettings::convert($source, YSCache::settings(true));
        if ($active && $kind === 'flyingpress' && self::customPageRules()) {
            $migration['unsupported'][] = 'custom_page_rules';
        }
        if ($migration['unsupported']) {
            if ($kind === 'flyingpress') {
                return new WP_Error('unsupported', sprintf(
                    /* translators: %s lists fields with unsupported exclusion rules. */
                    __('Could not preserve these source exclusions (%s). Review the URL keywords and cookie name expressions before switching.', 'ys-cache'),
                    implode(', ', $migration['unsupported'])
                ));
            }
            return new WP_Error('unsupported', sprintf(
                /* translators: %s is a list of settings field keys. */
                __('Some exclusion rules could not be converted (%s). Use literal values, URL paths, or the (.*) wildcard format and try again.', 'ys-cache'),
                implode(', ', $migration['unsupported'])
            ));
        }

        $record = [
            'plugin' => $sourcePlugin,
            'source_kind' => $kind,
            'source_active' => $active,
            'core_active' => true,
            'migration_id' => wp_generate_uuid4(),
        ];
        if (!YSCacheMigrationState::write('restore', $record)) {
            return self::stateError();
        }
        if (!wp_next_scheduled(self::RECOVERY_EVENT) && !wp_schedule_single_event(time() + 60, self::RECOVERY_EVENT)) {
            YSCacheMigrationState::delete('restore');
            return new WP_Error('schedule', __('Could not schedule automatic rollback. Plugin states were kept unchanged.', 'ys-cache'));
        }

        if ($active) {
            self::$sourceTouched = true;
            // Preserve the on-disk flag so a fresh request can reactivate the source plugin.
            if ($kind === 'flyingpress') {
                $hook = 'deactivate_' . $sourcePlugin;
                $callback = ['FlyingPress\WPCache', 'remove_constant'];
                $priority = has_action($hook, $callback);
                remove_action($hook, $callback, $priority);
            } else {
                add_filter('rocket_set_wp_cache_constant', '__return_false', PHP_INT_MAX);
            }
            try {
                deactivate_plugins($sourcePlugin, false, false);
            } catch (Throwable $error) {
                return new WP_Error('restore', __('Deactivation of the previous cache plugin was interrupted. Checking rollback status.', 'ys-cache'));
            } finally {
                if ($kind === 'flyingpress') {
                    add_action($hook, $callback, $priority, 0);
                } else {
                    remove_filter('rocket_set_wp_cache_constant', '__return_false', PHP_INT_MAX);
                }
            }
            if (is_plugin_active($sourcePlugin)) {
                return new WP_Error('restore', __('Could not verify that the previous cache plugin was deactivated. Checking rollback status.', 'ys-cache'));
            }
            clearstatcache(true, WP_CONTENT_DIR . '/advanced-cache.php');
        }

        try {
            if (YSCache::enable($migration['settings'], $record['migration_id'])) {
                return [
                    'message' => __('Settings imported. YS CACHE HTML caching is enabled.', 'ys-cache'),
                    'warnings' => $migration['warnings'],
                ];
            }
        } catch (Throwable $error) {
            // The recorded recovery runs in a new request.
        }
        return new WP_Error('restore', __('Could not enable YS CACHE HTML caching. Restoring the previous plugin state.', 'ys-cache'));
    }

    private static function clearRecovery(): void
    {
        YSCacheMigrationState::delete('restore');
        wp_clear_scheduled_hook(self::RECOVERY_EVENT);
    }

    private static function reactivate(): array|WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $record = YSCacheMigrationState::read('restore');
        $sourcePlugin = $record['plugin'] ?? '';
        $headers = is_string($sourcePlugin) && $sourcePlugin !== '' ? (get_plugins()[$sourcePlugin] ?? []) : [];
        $kind = $record['source_kind'] ?? 'rocket';
        if (self::sourceKind($headers) !== $kind || ($kind === 'flyingpress' && ($headers['Version'] ?? '') !== '4.16.4')) {
            return new WP_Error('restore_failed', __('Could not find the previous cache plugin. Check the Plugins screen and try again.', 'ys-cache'));
        }
        $settings = is_plugin_active(self::$plugin) ? YSCache::settings(true) : [];
        $id = $record['migration_id'] ?? null;
        $saved = $settings['rocket_migration_id'] ?? null;
        $legacySuccess = array_key_exists('legacy_enabled', $record) && !$record['legacy_enabled'];
        if (!is_plugin_active($sourcePlugin) && !empty($settings['enabled']) && (($id !== null && $id === $saved) || $legacySuccess)) {
            return ['state' => 'success', 'message' => __('YS CACHE HTML caching is enabled.', 'ys-cache')];
        }

        $restoreSource = ($record['source_active'] ?? $record['rocket_active'] ?? false) && !is_plugin_active($sourcePlugin);
        if ($restoreSource && !self::canDeactivate($sourcePlugin, $kind)) {
            return new WP_Error('dropin', __('Could not verify which plugin owns advanced-cache.php. Check your site\'s active cache plugin.', 'ys-cache'));
        }
        try {
            if ($restoreSource && (is_wp_error(activate_plugin($sourcePlugin)) || !is_plugin_active($sourcePlugin))) {
                return new WP_Error('restore_failed', __('Could not verify that the previous cache plugin was restored. Check the Plugins screen.', 'ys-cache'));
            }
        } catch (Throwable $error) {
            return new WP_Error('restore_failed', __('Could not restore the previous plugin state. Check the Plugins screen.', 'ys-cache'));
        }

        return ['message' => __('The import did not finish. The previous plugin state has been restored.', 'ys-cache')];
    }

    private static function customPageRules(): bool
    {
        global $wp_filter;
        $native = [
            'flying_press_is_cacheable' => ['FlyingPress\Integrations\WooCommerce', 'is_cacheable'],
            'flying_press_request_uri' => ['FlyingPress\Integrations\I18n\TranslatePress', 'convert_request_uri'],
            'flying_press_cache_file_path' => ['FlyingPress\Integrations\I18n\WeGlot', 'add_language_code'],
            'flying_press_cache_file_name' => null,
        ];
        if (defined('WEGLOT_VERSION') || apply_filters('flying_press_cache_mobile', false)) {
            return true;
        }
        foreach ($native as $hook => $callback) {
            foreach ($wp_filter[$hook]->callbacks ?? [] as $group) {
                foreach ($group as $entry) {
                    if ($callback === null || $entry['function'] !== $callback) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function canDeactivate(string $sourcePlugin, string $kind): bool
    {
        $dropIn = WP_CONTENT_DIR . '/advanced-cache.php';
        if ($kind === 'flyingpress' && (class_exists('Atomic_Persistent_Data') || file_exists(WP_CONTENT_DIR . '/flying-press-advanced-cache.php'))) {
            return false;
        }
        if (is_link($dropIn)) {
            return false;
        }
        if (!file_exists($dropIn)) {
            return true;
        }
        if (!is_file($dropIn) || !is_readable($dropIn)) {
            return false;
        }

        if ($kind === 'flyingpress') {
            $templateFile = WP_PLUGIN_DIR . '/' . dirname($sourcePlugin) . '/assets/advanced-cache.php';
            if (!is_file($templateFile) || !is_readable($templateFile) || is_link($templateFile) || filesize($dropIn) > 1048576) {
                return false;
            }
            $actual = file_get_contents($dropIn);
            $template = file_get_contents($templateFile);
            if ($actual === false || $template === false || substr_count($template, 'CONFIG_TO_REPLACE') !== 1) {
                return false;
            }
            if (trim($actual) === '') {
                return true;
            }
            // The vendor only substitutes its option array into this installed template.
            [$before, $after] = explode('CONFIG_TO_REPLACE', $template);
            if ($before === '' || $after === '' || !str_starts_with($actual, $before . 'array (') || !str_ends_with($actual, ')' . $after)) {
                return false;
            }
            $literal = substr($actual, strlen($before), -strlen($after));
            foreach (array_slice(token_get_all('<?php ' . $literal), 1) as $token) {
                if (is_array($token)) {
                    if (!in_array($token[0], [T_ARRAY, T_DOUBLE_ARROW, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_WHITESPACE], true)
                        && !($token[0] === T_STRING && in_array(strtolower($token[1]), ['true', 'false', 'null'], true))) {
                        return false;
                    }
                } elseif (!in_array($token, ['(', ')', ',', '-'], true)) {
                    return false;
                }
            }
            return true;
        }

        $prefix = file_get_contents($dropIn, false, null, 0, 8192);
        return $prefix !== false && (trim($prefix) === '' || (
            strpos($prefix, 'WP_ROCKET_ADVANCED_CACHE') !== false &&
            strpos($prefix, 'WP_Rocket\Buffer\Cache') !== false
        ));
    }
}
