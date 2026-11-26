<?php
/**
 * Plugin Name: YS CACHE
 * Plugin URI: https://yangsheep.com.tw
 * Description: HTML page cache, layered cache purging, and automatic settings import for WordPress.
 * Version: 0.2.0-beta.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com.tw
 * License: GPL-2.0-or-later
 * Text Domain: ys-cache
 * Domain Path: /languages
 * Update URI: https://yangsheep.com.tw/ys-cache
 */

defined( 'ABSPATH' ) || exit;

define( 'YS_CACHE_VERSION', '0.2.0-beta.3' );
define( 'YS_CACHE_FILE', __FILE__ );
define( 'YS_CACHE_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'YangSheep\\Cache\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $file = YS_CACHE_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    if ( is_file( $file ) ) {
        require_once $file;
    }
} );

if ( is_file( YS_CACHE_DIR . 'vendor/autoload.php' ) ) {
    require_once YS_CACHE_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, static function ( bool $network ): void {
    if ( $network ) {
        wp_die( esc_html__( 'Activate YS CACHE on each site separately.', 'ys-cache' ) );
    }
    \YangSheep\Cache\Database\YSCacheSettingsRepo::install();
} );
register_deactivation_hook( __FILE__, static function (): void {
    \YangSheep\Cache\YSCache::disable();
    \YangSheep\Cache\YSCachePreload::cancel();
    \YangSheep\Cache\YSCacheEnhancePurge::cancel();
} );

\YangSheep\Cache\Migration\YSCacheImport::register( __FILE__ );
if ( is_admin() ) {
    \YangSheep\Cache\Admin\YSCacheImportAdmin::register( __FILE__ );
}

$ysCacheTranslations = static function (): void {
    load_plugin_textdomain( 'ys-cache', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
};
if ( did_action( 'init' ) ) {
    $ysCacheTranslations();
} else {
    add_action( 'init', $ysCacheTranslations );
}

add_action( 'plugins_loaded', static function (): void {
    if ( class_exists( '\YangSheep\PluginHubClient\YSPluginHubClient' ) ) {
        \YangSheep\PluginHubClient\YSPluginHubClient::register( [
            'slug'        => 'ys-cache',
            'version'     => YS_CACHE_VERSION,
            'plugin_file' => YS_CACHE_FILE,
            'name'        => 'YS CACHE',
        ] );
    }
}, 5 );

add_action( 'plugins_loaded', [ \YangSheep\Cache\YSCache::class, 'instance' ], 11 );
