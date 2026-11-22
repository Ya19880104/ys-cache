<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

use YangSheep\Cache\YSCacheInvalidation;

define( 'ABSPATH', __DIR__ );

final class WP_Post {
    public function __construct( public int $ID, public string $post_type, public string $post_status, public int $post_parent = 0 ) {}
}

final class WP_Comment {
    public function __construct( public int $comment_post_ID, public string $comment_approved ) {}
}

final class WP_Term {
    public function __construct( public string $taxonomy, public int $term_id = 0 ) {}
}

final class WC_Product {
    public function __construct( private int $id ) {}

    public function get_id(): int {
        return $this->id;
    }
}

function add_action( string $name, callable $callback, int $priority = 10, int $accepted = 1 ): void {
    $GLOBALS['actions'][ $name ][ $priority ][] = [ $callback, $accepted ];
}

function fire( string $name, ...$args ): void {
    $actions = $GLOBALS['actions'][ $name ] ?? [];
    ksort( $actions );
    $GLOBALS['action_stack'][] = $name;
    try {
        foreach ( $actions as $callbacks ) {
            foreach ( $callbacks as [ $callback, $accepted ] ) {
                $callback( ...array_slice( $args, 0, $accepted ) );
            }
        }
    } finally {
        array_pop( $GLOBALS['action_stack'] );
    }
}

function doing_action( string $name ): bool {
    return in_array( $name, $GLOBALS['action_stack'], true );
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
    return (object) [ 'object_type' => match ( $taxonomy ) {
        'category' => [ 'page', 'post' ],
        'product_visibility', 'product_cat', 'product_tag' => [ 'product' ],
        default => [ 'user' ],
    } ];
}

function is_taxonomy_viewable( string $taxonomy ): bool {
    return in_array( $taxonomy, [ 'category', 'product_cat' ], true );
}

function get_term( int $id ): ?WP_Term {
    return $GLOBALS['terms'][ $id ] ?? null;
}

function wc_get_product_visibility_term_ids(): array {
    return [ 'outofstock' => 101, 'featured' => 102 ];
}

function wc_get_page_permalink( string $page ): string {
    return home_url( '/' . $page . '/' );
}

function get_permalink( int $id ): string|false {
    return $GLOBALS['product_urls'][ $id ] ?? false;
}

function home_url( string $path ): string {
    return 'https://store.example.test' . $path;
}

function wp_get_object_terms( array $ids, array $taxonomies ): array {
    $GLOBALS['catalog_term_queries'][] = $ids;
    return array_filter( $GLOBALS['terms'], static fn ( WP_Term $term ): bool => in_array( $term->taxonomy, $taxonomies, true ) && in_array( $term->term_id, [ 9, 12 ], true ) );
}

function get_ancestors( int $termId, string $taxonomy, string $type ): array {
    return $termId === 9 ? [ 11 ] : [];
}

function get_term_link( int|WP_Term $term, string $taxonomy = '' ): string {
    $term = $term instanceof WP_Term ? $term : $GLOBALS['terms'][ $term ];
    return home_url( '/' . $term->taxonomy . '/' . $term->term_id . '/' );
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
    $GLOBALS['actions'] = $GLOBALS['action_stack'] = [];
    $GLOBALS['purges'] = 0;
    $GLOBALS['scoped_purges'] = $GLOBALS['catalog_term_queries'] = [];
    ( new YSCacheInvalidation( static function (): void {
        ++$GLOBALS['purges'];
    }, static function ( array $urls ): void {
        $GLOBALS['scoped_purges'][] = $urls;
    } ) )->register();
}

function expect_change( string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    assert_count( 0, $hook . ' before shutdown' );
    fire( 'shutdown' );
    assert_count( 1, $hook . ' after shutdown' );
    assert_scoped( [], $hook );
}

function assert_scoped( array $expected, string $label ): void {
    if ( $expected !== $GLOBALS['scoped_purges'] ) {
        throw new RuntimeException( $label . ': unexpected targeted URLs ' . json_encode( $GLOBALS['scoped_purges'] ) );
    }
}

function expect_scoped( array $urls, string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    assert_scoped( [], $hook . ' before shutdown' );
    fire( 'shutdown' );
    assert_count( 0, $hook . ' never purges the whole site' );
    assert_scoped( [ $urls ], $hook . ' after shutdown' );
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
$product = new WP_Post( 21, 'product', 'publish' );
$variation = new WP_Post( 22, 'product_variation', 'publish', 21 );
$privateVariation = new WP_Post( 23, 'product_variation', 'private', 21 );
$productDraft = new WP_Post( 24, 'product', 'draft' );
$GLOBALS['posts'] = [ 10 => $page, 16 => $private, 17 => $order, 18 => $media, 21 => $product, 22 => $variation, 23 => $privateVariation, 24 => $productDraft ];
$productUrl = home_url( '/product/linen-shirt/' );
$GLOBALS['product_urls'] = [ $product->ID => $productUrl ];
$GLOBALS['comments'] = [
    1 => new WP_Comment( 10, '1' ),
    2 => new WP_Comment( 10, '0' ),
    3 => new WP_Comment( 16, '1' ),
];
$GLOBALS['terms'] = [
    9  => new WP_Term( 'product_cat', 9 ),
    11 => new WP_Term( 'product_cat', 11 ),
    12 => new WP_Term( 'product_tag', 12 ),
];
$availabilityUrls = [
    $productUrl, home_url( '/' ), home_url( '/shop/' ),
    home_url( '/product_cat/9/' ), home_url( '/product_cat/11/' ), home_url( '/product_tag/12/' ),
];

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
expect_change( 'updated_post_meta', 1, $product->ID, '_price' );
expect_change( 'added_term_relationship', $product->ID, 102, 'product_visibility' );
expect_change( 'deleted_term_relationships', $product->ID, [ 101, 102 ], 'product_visibility' );
expect_change( 'ys_ec_product_saved', 1, [ 'price' => 34, 'stock_qty' => 2 ], true );
expect_change( 'ys_ec_product_deleted', 1, 'trash' );
expect_change( 'ys_ec_category_saved', 1, [], true );
expect_change( 'ys_cache_content_changed' );

expect_scoped( [ $productUrl ], 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
if ( $GLOBALS['catalog_term_queries'] ) {
    throw new RuntimeException( 'A stock amount change should not query catalog membership.' );
}
expect_scoped( [ $productUrl ], 'woocommerce_variation_set_stock', new WC_Product( $variation->ID ) );
expect_scoped( [ $productUrl ], 'woocommerce_updated_product_stock', $product->ID );
expect_scoped( [ $productUrl ], 'updated_post_meta', 3, $variation->ID, '_stock' );
expect_scoped( $availabilityUrls, 'woocommerce_product_set_stock_status', $product->ID, 'outofstock', new WC_Product( $product->ID ) );
expect_scoped( $availabilityUrls, 'woocommerce_variation_set_stock_status', $variation->ID, 'instock', new WC_Product( $variation->ID ) );
expect_scoped( $availabilityUrls, 'updated_post_meta', 3, $product->ID, '_stock_status' );
expect_scoped( $availabilityUrls, 'added_term_relationship', $product->ID, 101, 'product_visibility' );
expect_scoped( $availabilityUrls, 'deleted_term_relationships', $product->ID, [ 101 ], 'product_visibility' );

request();
fire( 'woocommerce_variation_set_stock', new WC_Product( $variation->ID ) );
add_action( 'shutdown', static function () use ( $product ): void {
    foreach ( [ '_price', '_sale_price', '_regular_price' ] as $metaKey ) {
        fire( 'deleted_post_meta', [ 1 ], $product->ID, $metaKey );
    }
    fire( 'added_post_meta', 2, $product->ID, '_price' );
    fire( 'woocommerce_updated_product_price', $product->ID );
}, 10, 0 );
fire( 'shutdown' );
assert_count( 0, 'deferred Woo parent price rebuild after variation stock write' );
assert_scoped( [ [ $productUrl ] ], 'derived parent price changes remain scoped' );

request();
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'updated_post_meta', 2, $product->ID, '_price' );
fire( 'shutdown' );
assert_count( 1, 'explicit price edit on a product with a stock change' );
assert_scoped( [], 'explicit price edit subsumes stock changes' );

request();
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
add_action( 'shutdown', static function () use ( $product ): void {
    fire( 'updated_post_meta', 2, $product->ID, '_price' );
}, 10, 0 );
fire( 'shutdown' );
assert_count( 1, 'shutdown price edit without a Woo sync signal' );

request();
fire( 'woocommerce_updated_product_stock', $product->ID );
fire( 'woocommerce_variation_set_stock', new WC_Product( $variation->ID ) );
fire( 'updated_post_meta', 3, $product->ID, '_stock_status' );
fire( 'deleted_term_relationships', $product->ID, [ 101 ], 'product_visibility' );
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'shutdown' );
fire( 'shutdown' );
assert_count( 0, 'combined stock signals' );
assert_scoped( [ $availabilityUrls ], 'combined stock changes and duplicate shutdown' );
if ( $GLOBALS['catalog_term_queries'] !== [ [ $product->ID ] ] ) {
    throw new RuntimeException( 'Catalog membership should be loaded once for stock status changes.' );
}

request();
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'updated_post_meta', 1, $page->ID, '_thumbnail_id' );
fire( 'deleted_option', 'widget_block' );
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'shutdown' );
fire( 'shutdown' );
assert_count( 1, 'duplicate mutations and shutdown' );
assert_scoped( [], 'actual content edits subsume simultaneous stock changes' );

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
fire( 'ys_ec_product_saved', 1, [ 'stock_qty' => 2, 'updated_at' => '2026-11-23 00:00:00' ], true );
fire( 'ys_ec_product_saved', 1, [ 'stock_reserved' => 5, 'low_stock_threshold' => 4 ], true );
fire( 'woocommerce_updated_product_stock', $productDraft->ID );
fire( 'woocommerce_product_set_stock', new WC_Product( $productDraft->ID ) );
fire( 'woocommerce_variation_set_stock', new WC_Product( $privateVariation->ID ) );
fire( 'woocommerce_product_set_stock_status', 0, 'outofstock', new WC_Product( 0 ) );
fire( 'updated_post_meta', 3, $productDraft->ID, '_stock_status' );
fire( 'shutdown' );
assert_count( 0, 'unpublished, administrative, view and cart writes' );
assert_scoped( [], 'unpublished stock and YS CART inventory-only writes' );

echo "Content invalidation checks passed.\n";
