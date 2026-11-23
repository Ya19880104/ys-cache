<?php

namespace YangSheep\Cache\Admin;

use YangSheep\Cache\Migration\YSCacheImport;

defined('ABSPATH') || exit;

final class YSCacheImportAdmin
{
    private const PAGE = 'ys-cache-import';
    private const ACTION = 'ys_cache_import_settings';

    public static function register(string $plugin): void
    {
        add_filter('plugin_action_links_' . plugin_basename($plugin), static function (array $links): array {
            $links[] = '<a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">'
                . esc_html__('Import results', 'ys-cache') . '</a>';
            return $links;
        });

        add_action('admin_menu', static function () use ($plugin): void {
            $hook = add_management_page(
                __('YS CACHE Auto Import', 'ys-cache'),
                __('YS CACHE Auto Import', 'ys-cache'),
                'manage_options',
                self::PAGE,
                [self::class, 'render']
            );

            add_action('admin_enqueue_scripts', static function (string $currentHook) use ($hook, $plugin): void {
                if ($currentHook === $hook) {
                    wp_enqueue_script(self::PAGE, plugins_url('assets/js/ys-cache-import.js', $plugin), [], YS_CACHE_VERSION, true);
                    wp_localize_script(self::PAGE, 'ysCacheImport', [
                        'requestFailed' => __('Could not verify the import result. Check which plugins are active before retrying.', 'ys-cache'),
                    ]);
                }
            });
        });

        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sourcePlugin = YSCacheImport::findSourcePlugin();
        $sourceActive = $sourcePlugin !== '' && is_plugin_active($sourcePlugin);
        $pending = YSCacheImport::pending();
        $result = YSCacheImport::lastResult();
        $state = $result['state'] ?? '';
        $completed = $state === 'success' && !$sourceActive;
        $canSubmit = current_user_can('activate_plugins') && !is_multisite()
            && ($pending || ($sourcePlugin !== '' && !$completed));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('YS CACHE Auto Import', 'ys-cache'); ?></h1>
            <p><?php esc_html_e('When you activate YS CACHE, it checks the active cache plugin and imports supported settings before enabling HTML caching.', 'ys-cache'); ?></p>
            <p>
                <?php
                if ($sourcePlugin === '') {
                    esc_html_e('No supported cache plugin is installed.', 'ys-cache');
                } elseif ($sourceActive) {
                    esc_html_e('A supported cache plugin is active.', 'ys-cache');
                } else {
                    esc_html_e('A supported cache plugin is installed and inactive.', 'ys-cache');
                }
                ?>
            </p>
            <?php if (is_multisite()) : ?>
                <p><?php esc_html_e('Run the importer on a standalone WordPress site.', 'ys-cache'); ?></p>
            <?php endif; ?>
            <?php if ($canSubmit) : ?>
                <p><?php
                    if ($pending) {
                        esc_html_e('Rollback of the last import has not been confirmed.', 'ys-cache');
                    } else {
                        esc_html_e('Run a manual import of the cache lifetime, exclusion rules, and Cloudflare settings.', 'ys-cache');
                    }
                ?></p>
                <form id="ys-cache-import-form" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <input type="hidden" name="stage" value="<?php echo $pending ? 'restore' : 'migrate'; ?>">
                    <?php wp_nonce_field(self::ACTION, '_ajax_nonce', false); ?>
                    <?php submit_button($pending ? __('Restore previous cache plugin', 'ys-cache') : __('Import and enable HTML caching', 'ys-cache')); ?>
                </form>
            <?php endif; ?>
            <div id="ys-cache-import-result" aria-live="polite" role="status">
                <?php if ($result) : ?>
                    <?php
                    $noticeClass = match ($state) {
                        'success', 'restored' => 'notice-success',
                        'error' => 'notice-error',
                        'restoring' => 'notice-warning',
                        default => 'notice-info',
                    };
                    ?>
                    <div class="notice <?php echo esc_attr($noticeClass); ?> inline">
                        <p><?php echo esc_html($result['message']); ?></p>
                        <?php if (!empty($result['warnings'])) : ?>
                            <ul>
                                <?php foreach ($result['warnings'] as $warning) : ?>
                                    <li><?php echo esc_html($warning); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function ajax(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !check_ajax_referer(self::ACTION, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('The import form has expired. Reload the page and try again.', 'ys-cache')], 403);
        }

        $restore = ($_POST['stage'] ?? '') === 'restore';
        $result = $restore ? YSCacheImport::restore() : YSCacheImport::migrate();
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'restore' => !$restore && $result->get_error_code() === 'restore',
            ], 409);
        }

        wp_send_json_success($result);
    }
}
