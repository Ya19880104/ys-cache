<?php

namespace YangSheep\Cache\Import;

use Throwable;
use WP_Error;
use YangSheep\Cache\YSCache;
use YangSheep\Cache\YSCacheProviders;

defined('ABSPATH') || exit;

final class YSCacheImport
{
    private const CORE = 'ys-cache/ys-cache.php';
    private const RECOVERY_EVENT = 'ys_cache_rocket_import_recover';
    private const LEGACY_RESTORE = 'ys_cache_rocket_import_restore';
    private static bool $sourceTouched = false;

    public static function register(string $file): void
    {
        $companion = plugin_basename($file);
        // The activated_plugin hook runs after WordPress has saved its active plugin list.
        add_action('activated_plugin', static function (string $plugin, bool $network) use ($companion): void {
            if ($plugin !== $companion || $network || !self::authorized() || is_multisite()) {
                return;
            }
            if ((self::lastResult()['state'] ?? '') === 'success') {
                $sourcePlugin = self::findSourcePlugin();
                if ($sourcePlugin === '' || !is_plugin_active($sourcePlugin)) {
                    return;
                }
            }
            self::migrate();
        }, 30, 2);
        add_action(self::RECOVERY_EVENT, [self::class, 'recoverAutomatically']);
        add_action('admin_init', static function (): void {
            if (self::authorized()) {
                self::recoverAutomatically();
            }
        }, 1);
    }

    public static function coreInstalled(): bool
    {
        return is_readable(WP_PLUGIN_DIR . '/' . self::CORE);
    }

    public static function findSourcePlugin(): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $first = '';
        foreach (get_plugins() as $file => $headers) {
            if (strcasecmp($headers['Name'] ?? '', 'WP Rocket') === 0) {
                if (is_plugin_active($file)) {
                    return $file;
                }
                $first = $first ?: $file;
            }
        }
        return $first;
    }

    public static function migrate(): array|WP_Error
    {
        if (!self::authorized()) {
            return self::permissionError();
        }
        return self::withLock(static function () {
            if (!YSCacheMigrationState::install() || !self::adoptLegacy()) {
                return self::stateError();
            }
            $result = self::cutover();
            self::remember($result, 'success');
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

    private static function permissionError(): WP_Error
    {
        return new WP_Error('permission', __('Your account does not have permission to run the import.', 'ys-cache-rocket-import'));
    }

    private static function stateError(): WP_Error
    {
        return new WP_Error('migration_state', __('Could not save the import status. Check database permissions.', 'ys-cache-rocket-import'));
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
            'core_active' => true,
            'legacy_enabled' => !empty($legacy['enabled']),
        ])) {
            return false;
        }
        delete_option(self::LEGACY_RESTORE);
        return true;
    }

    private static function remember(array|WP_Error $result, string $success): void
    {
        if (is_wp_error($result)) {
            $states = ['source_plugin' => 'idle', 'restore' => 'restoring'];
            YSCacheMigrationState::write('result', [
                'state' => $states[$result->get_error_code()] ?? 'error',
                'message' => $result->get_error_message(),
                'warnings' => [],
            ]);
            return;
        }
        YSCacheMigrationState::write('result', [
            'state' => $result['state'] ?? $success,
            'message' => $result['message'],
            'warnings' => $result['warnings'] ?? [],
        ]);
    }

    private static function recover(): array|WP_Error
    {
        if (self::$sourceTouched) {
            return new WP_Error('restore', __('Waiting for the next WordPress request to restore the previous plugin.', 'ys-cache-rocket-import'));
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
                return new WP_Error('restore_failed', __('There is no import to roll back.', 'ys-cache-rocket-import'));
            }
            $result = self::reactivate();
            self::remember($result, 'restored');
            return $result;
        });
    }

    private static function withLock(callable $action): array|WP_Error
    {
        global $wpdb;
        if (is_multisite()) {
            return new WP_Error('multisite', __('Run the importer on a standalone WordPress site.', 'ys-cache-rocket-import'));
        }
        $lock = 'ys_rocket_' . substr(hash('sha256', DB_NAME . '/' . $wpdb->options), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            return new WP_Error('lock', __('Could not lock the import. Please try again shortly.', 'ys-cache-rocket-import'));
        }
        try {
            return $action();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function cutover(): array|WP_Error
    {
        if (self::pending()) {
            return new WP_Error('restore', __('An unfinished import was found. Restoring the previous plugin state.', 'ys-cache-rocket-import'));
        }
        $sourcePlugin = self::findSourcePlugin();
        if ($sourcePlugin === '') {
            return new WP_Error('source_plugin', __('No supported cache plugin is installed.', 'ys-cache-rocket-import'));
        }
        if (!self::coreInstalled()) {
            return new WP_Error('core', __('Install YS CACHE first.', 'ys-cache-rocket-import'));
        }
        $coreActive = is_plugin_active(self::CORE);
        if (!$coreActive && !current_user_can('activate_plugin', self::CORE)) {
            return self::permissionError();
        }
        if ($coreActive && !class_exists(YSCache::class)) {
            return new WP_Error('core', __('Could not load YS CACHE. Check the plugin version and installation.', 'ys-cache-rocket-import'));
        }

        $active = is_plugin_active($sourcePlugin);
        if ($active && !current_user_can('deactivate_plugin', $sourcePlugin)) {
            return self::permissionError();
        }
        if ($active && !self::canDeactivate()) {
            return new WP_Error('dropin', __('Could not verify which plugin owns advanced-cache.php. Check your site\'s active cache plugin.', 'ys-cache-rocket-import'));
        }

        $source = get_option('wp_rocket_settings');
        if (!is_array($source) || !$source) {
            return new WP_Error('settings', __('No compatible cache settings were found to import.', 'ys-cache-rocket-import'));
        }
        if (!class_exists(YSCacheProviders::class)) {
            $providerFile = WP_PLUGIN_DIR . '/ys-cache/src/YSCacheProviders.php';
            if (!is_readable($providerFile)) {
                return new WP_Error('core', __('The YS CACHE installation is incomplete.', 'ys-cache-rocket-import'));
            }
            require_once $providerFile;
        }
        $migration = YSCacheImportSettings::convert($source, $coreActive ? YSCache::settings() : []);
        if ($migration['unsupported']) {
            return new WP_Error('unsupported', sprintf(
                /* translators: %s is a list of settings field keys. */
                __('Some exclusion rules could not be converted (%s). Use literal values, URL paths, or the (.*) wildcard format and try again.', 'ys-cache-rocket-import'),
                implode(', ', $migration['unsupported'])
            ));
        }

        $record = [
            'plugin' => $sourcePlugin,
            'rocket_active' => $active,
            'core_active' => $coreActive,
            'migration_id' => wp_generate_uuid4(),
        ];
        if (!YSCacheMigrationState::write('restore', $record)) {
            return self::stateError();
        }
        if (!wp_next_scheduled(self::RECOVERY_EVENT) && !wp_schedule_single_event(time() + 60, self::RECOVERY_EVENT)) {
            YSCacheMigrationState::delete('restore');
            return new WP_Error('schedule', __('Could not schedule automatic rollback. Plugin states were kept unchanged.', 'ys-cache-rocket-import'));
        }

        if ($active) {
            self::$sourceTouched = true;
            // Preserve the on-disk flag so a fresh request can reactivate the source plugin.
            add_filter('rocket_set_wp_cache_constant', '__return_false', PHP_INT_MAX);
            try {
                deactivate_plugins($sourcePlugin, false, false);
            } catch (Throwable $error) {
                return new WP_Error('restore', __('Deactivation of the previous cache plugin was interrupted. Checking rollback status.', 'ys-cache-rocket-import'));
            } finally {
                remove_filter('rocket_set_wp_cache_constant', '__return_false', PHP_INT_MAX);
            }
            if (is_plugin_active($sourcePlugin)) {
                return new WP_Error('restore', __('Could not verify that the previous cache plugin was deactivated. Checking rollback status.', 'ys-cache-rocket-import'));
            }
            clearstatcache(true, WP_CONTENT_DIR . '/advanced-cache.php');
        }

        try {
            if (!$coreActive) {
                $activation = activate_plugin(self::CORE);
                if (is_wp_error($activation) || !is_plugin_active(self::CORE) || !class_exists(YSCache::class)) {
                    return new WP_Error('restore', __('Could not activate YS CACHE. Restoring the previous plugin state.', 'ys-cache-rocket-import'));
                }
                $migration = YSCacheImportSettings::convert($source, YSCache::settings());
                if ($migration['unsupported']) {
                    return new WP_Error('restore', __('The combined exclusion rules could not be converted. Restoring the previous plugin state.', 'ys-cache-rocket-import'));
                }
            }
            if (YSCache::enable($migration['settings'], $record['migration_id'])) {
                self::clearRecovery();
                return [
                    'message' => __('Settings imported. YS CACHE HTML caching is enabled.', 'ys-cache-rocket-import'),
                    'warnings' => $migration['warnings'],
                ];
            }
        } catch (Throwable $error) {
            // The recorded recovery runs in a new request.
        }
        return new WP_Error('restore', __('Could not enable YS CACHE HTML caching. Restoring the previous plugin state.', 'ys-cache-rocket-import'));
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
        if (strcasecmp($headers['Name'] ?? '', 'WP Rocket') !== 0) {
            return new WP_Error('restore_failed', __('Could not find the previous cache plugin. Check the Plugins screen and try again.', 'ys-cache-rocket-import'));
        }
        $coreActive = is_plugin_active(self::CORE);
        $settings = $coreActive && class_exists(YSCache::class) ? YSCache::settings() : [];
        $id = $record['migration_id'] ?? null;
        $saved = $settings['rocket_migration_id'] ?? null;
        $legacySuccess = array_key_exists('legacy_enabled', $record) && !$record['legacy_enabled'];
        if (!is_plugin_active($sourcePlugin) && !empty($settings['enabled']) && (($id !== null && $id === $saved) || $legacySuccess)) {
            self::clearRecovery();
            return ['state' => 'success', 'message' => __('YS CACHE HTML caching is enabled.', 'ys-cache-rocket-import')];
        }

        $restoreSource = !empty($record['rocket_active']) && !is_plugin_active($sourcePlugin);
        if ($restoreSource && !self::canDeactivate()) {
            return new WP_Error('dropin', __('Could not verify which plugin owns advanced-cache.php. Check your site\'s active cache plugin.', 'ys-cache-rocket-import'));
        }
        try {
            if ($coreActive && empty($record['core_active'])) {
                deactivate_plugins(self::CORE, false, false);
                if (is_plugin_active(self::CORE)) {
                    return new WP_Error('restore_failed', __('Could not restore the previous YS CACHE activation state.', 'ys-cache-rocket-import'));
                }
            }
            if ($restoreSource && (is_wp_error(activate_plugin($sourcePlugin)) || !is_plugin_active($sourcePlugin))) {
                return new WP_Error('restore_failed', __('Could not verify that the previous cache plugin was restored. Check the Plugins screen.', 'ys-cache-rocket-import'));
            }
        } catch (Throwable $error) {
            return new WP_Error('restore_failed', __('Could not restore the previous plugin state. Check the Plugins screen.', 'ys-cache-rocket-import'));
        }

        self::clearRecovery();
        return ['message' => __('The import did not finish. The previous plugin state has been restored.', 'ys-cache-rocket-import')];
    }

    private static function canDeactivate(): bool
    {
        $dropIn = WP_CONTENT_DIR . '/advanced-cache.php';
        if (is_link($dropIn)) {
            return false;
        }
        if (!file_exists($dropIn)) {
            return true;
        }
        if (!is_file($dropIn) || !is_readable($dropIn)) {
            return false;
        }

        $prefix = file_get_contents($dropIn, false, null, 0, 8192);
        return $prefix !== false && (trim($prefix) === '' || (
            strpos($prefix, 'WP_ROCKET_ADVANCED_CACHE') !== false &&
            strpos($prefix, 'WP_Rocket\Buffer\Cache') !== false
        ));
    }
}
