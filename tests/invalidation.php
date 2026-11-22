<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

use YangSheep\Cache\YSCacheInvalidation;

define( 'ABSPATH', __DIR__ );

final class WP_Post {
    public function __construct( public int $ID, public string $post_type, public string $post_status ) {}
}

final class WP_Comment {
    public function __construct( public int $comment_post_ID, public string $comment_approved ) {}
}

final class WP_Term {
    public function __construct( public string $taxonomy ) {}
}

function add_action( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void {
    $GLOBALS['actions'][ $name ][ $priority ][] = [ $callback, $accepted ];
}

function fire( string $name, ...$args ): void {
    $actions = $GLOBALS['actions'][ $name ] ?? [];
    ksort( $actions );
    foreach ( $actions as $callbacks ) {
        foreach ( $callbacks as [ $callback, $accepted ] ) {
            $callback( ...array_slice( $args, 0, $accepted ) );
        }
    }
}

function get_post( int $id ): ?WP_Post {
    return $GLOBALS['posts'][ $id ] ?? null;
}

function get_post_type_object( string $type ): object {
    return (object) [ 'public' => in_array( $type, [ 'page', 'post', 'product' ], true ) ];
}

function is_post_type_viewable( object $type ): bool {
    return $type->public;
}

function is_post_status_viewable( string $status ): bool {
    return $status === 'publish';
}

function get_comment( int $id ): ?WP_Comment {
    return $GLOBALS['comments'][ $id ] ?? null;
}

function get_taxonomy( string $taxonomy ): object {
    return (object) [ 'object_type' => $taxonomy === 'category' ? [ 'page', 'post' ] : [ 'user' ] ];
}

function is_taxonomy_viewable( string $taxonomy ): bool {
    return in_array( $taxonomy, [ 'category', 'product_cat' ], true );
}

function get_term( int $id ): ?WP_Term {
    return $GLOBALS['terms'][ $id ] ?? null;
}

function apply_filters( string $hook, $value, ...$args ) {
    return $value;
}

function assert_count( int $expected, string $context ): void {
    if ( $expected !== $GLOBALS['purges'] ) {
        throw new RuntimeException( $context . ': expected ' . $expected . ' purge' );
    }
}

function request(): void {
    $GLOBALS['actions'] = [];
    $GLOBALS['purges'] = 0;
    ( new YSCacheInvalidation( static function (): void {
        ++$GLOBALS['purges'];
    } ) )->register();
}

function expect_change( string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    assert_count( 0, $hook . ' before shutdown' );
    fire( 'shutdown' );
    assert_count( 1, $hook . ' after shutdown' );
}

require dirname( __DIR__ ) . '/src/YSCacheInvalidation.php';

$page = new WP_Post( 10, 'page', 'publish' );
$draft = new WP_Post( 10, 'page', 'draft' );
$future = new WP_Post( 11, 'post', 'future' );
$revision = new WP_Post( 15, 'revision', 'inherit' );
$private = new WP_Post( 16, 'post', 'private' );
$order = new WP_Post( 17, 'shop_order', 'publish' );
$media = new WP_Post( 18, 'attachment', 'inherit' );
$template = new WP_Post( 19, 'wp_template', 'publish' );
$GLOBALS['posts'] = [ 10 => $page, 16 => $private, 17 => $order, 18 => $media ];
$GLOBALS['comments'] = [
    1 => new WP_Comment( 10, '1' ),
    2 => new WP_Comment( 10, '0' ),
    3 => new WP_Comment( 16, '1' ),
];
$GLOBALS['terms'] = [ 9 => new WP_Term( 'product_cat' ) ];

expect_change( 'wp_after_insert_post', $page->ID, $page, true, $page );
expect_change( 'wp_after_insert_post', $draft->ID, $draft, true, $page );
expect_change( 'transition_post_status', 'publish', 'future', $future );
expect_change( 'transition_post_status', 'trash', 'publish', $page );
expect_change( 'deleted_post', $page->ID, $page );
expect_change( 'deleted_post', $media->ID, $media );
expect_change( 'wp_after_insert_post', $template->ID, $template, true, $template );
expect_change( 'updated_post_meta', 1, $media->ID, '_wp_attachment_metadata' );
expect_change( 'deleted_post_meta', [ 2 ], $page->ID, '_thumbnail_id' );
expect_change( 'edited_term', 1, 2, 'category' );
expect_change( 'added_term_relationship', $page->ID, 2, 'category' );
expect_change( 'deleted_term_relationships', $page->ID, [ 2 ], 'category' );
expect_change( 'updated_term_meta', 3, 9, 'thumbnail_id' );
expect_change( 'wp_insert_comment', 1, $GLOBALS['comments'][1] );
expect_change( 'edit_comment', 1 );
expect_change( 'transition_comment_status', 'unapproved', 'approved', $GLOBALS['comments'][1] );
expect_change( 'deleted_comment', 1, $GLOBALS['comments'][1] );
expect_change( 'added_option', 'widget_block', [] );
expect_change( 'updated_option', 'show_on_front', 'posts', 'page' );
expect_change( 'deleted_option', 'theme_mods_blocksy' );
expect_change( 'wp_update_nav_menu_item', 1, 2, [] );
expect_change( 'wp_delete_nav_menu', 1 );
expect_change( 'woocommerce_product_set_stock', new stdClass() );
expect_change( 'ys_ec_product_saved', 1, [], true );
expect_change( 'ys_ec_product_deleted', 1, 'trash' );
expect_change( 'ys_ec_category_saved', 1, [], true );
expect_change( 'ys_cache_content_changed' );

request();
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'updated_post_meta', 1, $page->ID, '_thumbnail_id' );
fire( 'deleted_option', 'widget_block' );
fire( 'shutdown' );
fire( 'shutdown' );
assert_count( 1, 'duplicate mutations and shutdown' );

request();
fire( 'clean_post_cache', $page->ID, $page );
fire( 'wp_after_insert_post', $draft->ID, $draft, true, $draft );
fire( 'wp_after_insert_post', $revision->ID, $revision, false, null );
fire( 'wp_after_insert_post', $private->ID, $private, false, null );
fire( 'wp_after_insert_post', $order->ID, $order, true, $order );
fire( 'transition_post_status', 'publish', 'publish', $page );
fire( 'updated_post_meta', 1, $page->ID, 'product_view_count' );
fire( 'wp_insert_comment', 2, $GLOBALS['comments'][2] );
fire( 'wp_insert_comment', 3, $GLOBALS['comments'][3] );
fire( 'edit_comment', 2 );
fire( 'added_term_relationship', $page->ID, 2, 'user_group' );
fire( 'updated_option', 'cron' );
fire( 'ys_ec_cart_updated', 9 );
fire( 'shutdown' );
assert_count( 0, 'unpublished, administrative, view and cart writes' );

echo "Content invalidation checks passed.\n";
