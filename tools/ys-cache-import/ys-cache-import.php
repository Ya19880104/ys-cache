<?php
/**
 * Plugin Name: YS CACHE Auto Import
 * Description: Automatically import compatible cache settings into YS CACHE.
 * Version: 1.0.1-beta.2
 * Requires PHP: 8.0
 * Author: YANGSHEEP CLOUD
 * License: GPL-2.0-or-later
 * Text Domain: ys-cache-rocket-import
 * Domain Path: /languages
 * Update URI: https://yangsheep.com.tw/ys-cache-rocket-import/
 */

namespace YangSheep\Cache\Import;

defined('ABSPATH') || exit;

const YS_CACHE_IMPORT_VERSION = '1.0.1-beta.2';

$loadTranslations = static function (): void {
    load_plugin_textdomain('ys-cache-rocket-import', false, dirname(plugin_basename(__FILE__)) . '/languages');
};

if (did_action('init')) {
    $loadTranslations();
} else {
    add_action('init', $loadTranslations);
}

require_once __DIR__ . '/src/YSCacheImportSettings.php';
require_once __DIR__ . '/src/YSCacheMigrationState.php';
require_once __DIR__ . '/src/YSCacheImport.php';
require_once __DIR__ . '/src/YSCacheImportAdmin.php';

YSCacheImport::register(__FILE__);
YSCacheImportAdmin::register(__FILE__);
