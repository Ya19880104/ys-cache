<?php

namespace YangSheep\Cache\Admin;

use YangSheep\Cache\YSCache;

defined( 'ABSPATH' ) || exit;

final class YSCacheAdminBar {
    private const ACTION = 'ys_cache_toolbar_purge';
    private const HANDLE = 'ys-cache-admin-bar';

    public static function register(): void {
        add_action( 'admin_bar_menu', [ self::class, 'menu' ], 90 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'purge' ] );
    }

    private static function canShow(): bool {
        return is_admin_bar_showing() && current_user_can( 'manage_options' );
    }

    public static function menu( \WP_Admin_Bar $bar ): void {
        if ( ! self::canShow() ) {
            return;
        }
        $bar->add_node( [
            'id'    => 'ys-cache-purge',
            'title' => esc_html__( 'Purge cache', 'ys-cache' ),
            'meta'  => [ 'tabindex' => 0 ],
        ] );
        $bar->add_node( [
            'id'     => 'ys-cache-purge-page',
            'parent' => 'ys-cache-purge',
            'title'  => esc_html__( 'Purge this page', 'ys-cache' ),
            'href'   => '#',
        ] );
        $bar->add_node( [
            'id'     => 'ys-cache-purge-site',
            'parent' => 'ys-cache-purge',
            'title'  => esc_html__( 'Purge entire site', 'ys-cache' ),
            'href'   => '#',
        ] );
    }

    public static function enqueue(): void {
        if ( ! self::canShow() ) {
            return;
        }
        wp_enqueue_script( self::HANDLE, plugin_dir_url( YS_CACHE_FILE ) . 'assets/js/ys-cache-admin-bar.js', [ 'wp-a11y' ], YS_CACHE_VERSION, true );
        wp_localize_script( self::HANDLE, 'ysCacheToolbar', [
            'endpoint' => admin_url( 'admin-ajax.php' ),
            'action'   => self::ACTION,
            'nonce'    => wp_create_nonce( self::ACTION ),
            'page'     => self::currentPage(),
            'home'     => home_url( '/' ),
            'prompt'   => __( 'Enter the URL of the page you want to purge.', 'ys-cache' ),
            'invalid'  => __( 'Enter a valid URL on this site.', 'ys-cache' ),
            'pending'  => __( 'Purging cache…', 'ys-cache' ),
            'complete' => __( 'Cache purge finished.', 'ys-cache' ),
            'failed'   => __( 'Cache purge failed. Reload the page and try again.', 'ys-cache' ),
        ] );
        wp_add_inline_style( 'admin-bar', '@media screen and (max-width: 782px) { #wpadminbar li#wp-admin-bar-ys-cache-purge { display: block; } }' );
    }

    private static function currentPage(): string {
        if ( is_admin() ) {
            $screen = get_current_screen();
            if ( ! $screen || $screen->base !== 'post' || $screen->action === 'add' ) {
                return '';
            }
            $post = get_post();
            $url = $post instanceof \WP_Post && is_post_publicly_viewable( $post ) ? wp_get_canonical_url( $post ) : false;
            return is_string( $url ) && wp_parse_url( $url, PHP_URL_QUERY ) === null ? $url : '';
        }
        if ( is_preview() || is_search() || is_404() || is_feed() ||
            array_intersect( [ 'p', 'page_id', 'attachment_id', 'cat', 'tag', 'author', 'post_type', 'lang' ], array_keys( $_GET ) ) ) {
            return '';
        }
        $request = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( $request === '' || strlen( $request ) > 4096 || ! str_starts_with( $request, '/' ) ||
            str_starts_with( $request, '//' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $request ) ) {
            return '';
        }
        $path = wp_parse_url( $request, PHP_URL_PATH );
        $home = wp_parse_url( home_url( '/' ) );
        if ( ! is_string( $path ) || ! isset( $home['scheme'], $home['host'] ) ) {
            return '';
        }
        $base = rtrim( $home['path'] ?? '', '/' );
        if ( $path !== $base && ! str_starts_with( $path, $base . '/' ) ) {
            return '';
        }
        $port = isset( $home['port'] ) ? ':' . $home['port'] : '';
        return $home['scheme'] . '://' . $home['host'] . $port . $path;
    }

    public static function purge(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! current_user_can( 'manage_options' ) ||
            ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Access or verification failed. Reload the page.', 'ys-cache' ) ], 403 );
        }
        $scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : '';
        if ( $scope === 'site' ) {
            wp_send_json_success( [ 'results' => YSCache::purge( 'all' ) ] );
        }
        if ( $scope === 'page' ) {
            $url = $_POST['url'] ?? null;
            if ( ! is_string( $url ) || strlen( $url ) > 2048 || trim( $url ) === '' ) {
                wp_send_json_error( [ 'message' => __( 'Enter a valid URL on this site.', 'ys-cache' ) ], 400 );
            }
            wp_send_json_success( [ 'results' => YSCache::purgePage( wp_unslash( $url ) ) ] );
        }
        wp_send_json_error( [ 'message' => __( 'Invalid purge scope.', 'ys-cache' ) ], 400 );
    }
}
