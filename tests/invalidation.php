<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

use YangSheep\Cache\YSCacheInvalidation;

define( 'ABSPATH', __DIR__ );

final class WP_Post {
    public function __construct(
        public int $ID,
        public string $post_type,
        public string $post_status,
        public int $post_parent = 0,
        public string $post_name = '',
        public int $post_author = 0,
        public string $post_date = '0000-00-00 00:00:00'
    ) {}
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
        'post_tag' => [ 'post' ],
        'product_visibility', 'product_cat', 'product_tag' => [ 'product' ],
        default => [ 'user' ],
    } ];
}

function is_taxonomy_viewable( string $taxonomy ): bool {
    return in_array( $taxonomy, [ 'category', 'post_tag', 'product_cat', 'product_tag' ], true );
}

function get_object_taxonomies( string $type ): array {
    return match ( $type ) {
        'post' => [ 'category', 'post_tag' ],
        'product' => [ 'product_cat', 'product_tag', 'product_visibility' ],
        default => [],
    };
}

function get_the_terms( WP_Post $post, string $taxonomy ): array|false {
    $terms = [];
    foreach ( $GLOBALS['object_terms'][ $post->ID ] ?? [] as $id ) {
        if ( $GLOBALS['terms'][ $id ]->taxonomy === $taxonomy ) {
            $terms[] = $GLOBALS['terms'][ $id ];
        }
    }
    return $terms ?: false;
}

function get_term( int $id ): ?WP_Term {
    return $GLOBALS['terms'][ $id ] ?? null;
}

function get_term_by( string $field, int $value, string $taxonomy ): WP_Term|false {
    $term = $field === 'term_taxonomy_id' ? ( $GLOBALS['terms'][ $value ] ?? null ) : null;
    return $term && $term->taxonomy === $taxonomy ? $term : false;
}

function wc_get_product_visibility_term_ids(): array {
    return [ 'outofstock' => 101, 'featured' => 102 ];
}

function wc_get_page_permalink( string $page ): string {
    return home_url( '/' . $page . '/' );
}

function get_permalink( int|WP_Post $post ): string|false {
    $GLOBALS['permalink_calls'] = ( $GLOBALS['permalink_calls'] ?? 0 ) + 1;
    $post = $post instanceof WP_Post ? $post : ( $GLOBALS['posts'][ $post ] ?? null );
    if ( ! $post ) {
        return false;
    }
    if ( in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft', 'future' ], true ) ) {
        return home_url( '/?p=' . $post->ID );
    }
    return match ( $post->post_type ) {
        'page' => home_url( '/' . $post->post_name . '/' ),
        'post' => home_url( '/blog/' . $post->post_name . '/' ),
        'product' => home_url( '/product/' . $post->post_name . '/' ),
        default => home_url( '/?p=' . $post->ID ),
    };
}

function get_post_type_archive_link( string $type ): string|false {
    return match ( $type ) {
        'product' => home_url( '/shop/' ),
        'post' => home_url( '/blog/' ),
        default => false,
    };
}

function get_option( string $name, $default = false ) {
    return $name === 'page_for_posts' ? 30 : $default;
}

function get_author_posts_url( int $author ): string {
    return home_url( '/author/' . $author . '/' );
}

function get_year_link( int $year ): string {
    return home_url( '/' . $year . '/' );
}

function get_month_link( int $year, int $month ): string {
    return home_url( sprintf( '/%d/%02d/', $year, $month ) );
}

function get_day_link( int $year, int $month, int $day ): string {
    return home_url( sprintf( '/%d/%02d/%02d/', $year, $month, $day ) );
}

function home_url( string $path ): string {
    return 'https://store.example.test' . $path;
}

function wp_get_object_terms( array $ids, array $taxonomies ): array {
    $GLOBALS['catalog_term_queries'][] = $ids;
    return array_filter( $GLOBALS['terms'], static fn ( WP_Term $term ): bool => in_array( $term->taxonomy, $taxonomies, true ) && in_array( $term->term_id, [ 9, 12 ], true ) );
}

function get_ancestors( int $termId, string $taxonomy, string $type ): array {
    return match ( $termId ) {
        9 => [ 11 ],
        5 => [ 4 ],
        default => [],
    };
}

function get_term_link( int|WP_Term $term, string $taxonomy = '' ): string {
    $term = $term instanceof WP_Term ? $term : $GLOBALS['terms'][ $term ];
    return home_url( '/' . $term->taxonomy . '/' . $term->term_id . '/' );
}

function apply_filters( string $hook, $value, ...$args ) {
    return isset( $GLOBALS['filter_overrides'][ $hook ] ) ? $GLOBALS['filter_overrides'][ $hook ]( $value, ...$args ) : $value;
}

function current_user_can( string $capability ): bool {
    return $capability === 'manage_woocommerce' && $GLOBALS['can_manage'];
}

function assert_count( int $expected, string $context ): void {
    if ( $expected !== $GLOBALS['purges'] ) {
        throw new RuntimeException( $context . ': expected ' . $expected . ' site-wide purge, got ' . $GLOBALS['purges'] );
    }
}

function request( bool $scoped = true ): void {
    $GLOBALS['actions'] = $GLOBALS['action_stack'] = [];
    $GLOBALS['purges'] = 0;
    $GLOBALS['scoped_purges'] = $GLOBALS['scoped_content'] = $GLOBALS['catalog_term_queries'] = [];
    $GLOBALS['filter_overrides'] = [];
    $GLOBALS['can_manage'] = false;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ( new YSCacheInvalidation( static function (): void {
        ++$GLOBALS['purges'];
    }, $scoped ? static function ( array $urls, bool $content ): void {
        $GLOBALS['scoped_purges'][] = $urls;
        $GLOBALS['scoped_content'][] = $content;
    } : null ) )->register();
}

function expect_global( string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    assert_count( 0, $hook . ' before shutdown' );
    fire( 'shutdown' );
    assert_count( 1, $hook . ' after shutdown' );
    assert_scoped( [], $hook );
}

function assert_scoped( array $expected, string $label ): void {
    if ( $expected !== $GLOBALS['scoped_purges'] ) {
        throw new RuntimeException( $label . ': unexpected targeted URLs ' . json_encode( $GLOBALS['scoped_purges'], JSON_UNESCAPED_SLASHES ) );
    }
}

function assert_content( array $expected, string $label ): void {
    if ( $expected !== $GLOBALS['scoped_content'] ) {
        throw new RuntimeException( $label . ': unexpected content flag ' . json_encode( $GLOBALS['scoped_content'] ) );
    }
}

function expect_scoped( array $urls, string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    assert_scoped( [], $hook . ' before shutdown' );
    fire( 'shutdown' );
    assert_count( 0, $hook . ' never purges the whole site' );
    assert_scoped( [ $urls ], $hook . ' after shutdown' );
    assert_content( [ true ], $hook . ' is a content change' );
}

/** 只有庫存變更：回呼的第二個參數為 false，只能整站清除的層可以略過。 */
function expect_stock_scoped( array $urls, string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    fire( 'shutdown' );
    assert_count( 0, $hook . ' never purges the whole site' );
    assert_scoped( [ $urls ], $hook . ' after shutdown' );
    assert_content( [ false ], $hook . ' is a stock-only change' );
}

function expect_nothing( string $hook, ...$args ): void {
    request();
    fire( $hook, ...$args );
    fire( 'shutdown' );
    assert_count( 0, $hook . ' does not purge the site' );
    assert_scoped( [], $hook . ' does not purge pages' );
}

function urls( string ...$paths ): array {
    return array_map( 'home_url', $paths );
}

require dirname( __DIR__ ) . '/src/YSCacheInvalidation.php';

$page = new WP_Post( 10, 'page', 'publish', 0, 'about' );
$draft = new WP_Post( 10, 'page', 'draft', 0, 'about' );
$renamed = new WP_Post( 10, 'page', 'publish', 0, 'about-us' );
$scheduled = new WP_Post( 11, 'post', 'publish', 0, 'hello', 3, '2026-11-25 10:00:00' );
$news = new WP_Post( 12, 'post', 'publish', 0, 'news', 3, '2026-11-20 08:00:00' );
$revision = new WP_Post( 15, 'revision', 'inherit' );
$private = new WP_Post( 16, 'post', 'private' );
$order = new WP_Post( 17, 'shop_order', 'publish' );
$media = new WP_Post( 18, 'attachment', 'inherit' );
$template = new WP_Post( 19, 'wp_template', 'publish' );
$productImage = new WP_Post( 20, 'attachment', 'inherit', 21 );
$product = new WP_Post( 21, 'product', 'publish', 0, 'linen-shirt' );
$variation = new WP_Post( 22, 'product_variation', 'publish', 21 );
$privateVariation = new WP_Post( 23, 'product_variation', 'private', 21 );
$productDraft = new WP_Post( 24, 'product', 'draft', 0, 'coming-soon' );
$header = new WP_Post( 25, 'elementor_library', 'publish', 0, 'header' );
$layout = new WP_Post( 26, 'my_layout', 'publish', 0, 'promo' );
$blog = new WP_Post( 30, 'page', 'publish', 0, 'blog' );
$menuItem = new WP_Post( 40, 'nav_menu_item', 'publish' );
$fixturePosts = [ 10 => $page, 11 => $scheduled, 12 => $news, 16 => $private, 17 => $order, 18 => $media, 20 => $productImage, 21 => $product,
    22 => $variation, 23 => $privateVariation, 24 => $productDraft, 25 => $header, 26 => $layout, 30 => $blog, 40 => $menuItem ];
$GLOBALS['posts'] = $fixturePosts;
$productUrl = home_url( '/product/linen-shirt/' );
$GLOBALS['comments'] = [
    1 => new WP_Comment( 10, '1' ),
    2 => new WP_Comment( 10, '0' ),
    3 => new WP_Comment( 16, '1' ),
    4 => new WP_Comment( 20, '1' ),
];
$GLOBALS['terms'] = [
    2  => new WP_Term( 'category', 2 ),
    4  => new WP_Term( 'category', 4 ),
    5  => new WP_Term( 'category', 5 ),
    6  => new WP_Term( 'post_tag', 6 ),
    9  => new WP_Term( 'product_cat', 9 ),
    11 => new WP_Term( 'product_cat', 11 ),
    12 => new WP_Term( 'product_tag', 12 ),
];
$GLOBALS['object_terms'] = [ 12 => [ 5, 6 ], 21 => [ 9, 12 ] ];
$availabilityUrls = [
    $productUrl, home_url( '/' ), home_url( '/shop/' ),
    home_url( '/product_cat/9/' ), home_url( '/product_cat/11/' ), home_url( '/product_tag/12/' ),
];
$productUrls = $availabilityUrls;
$pageUrls = urls( '/about/', '/' );
$newsUrls = urls( '/blog/news/', '/', '/blog/', '/author/3/', '/2026/', '/2026/11/', '/2026/11/20/', '/category/5/', '/category/4/', '/post_tag/6/' );

// 版面共用內容、分類 term、外觀設定、外掛與折扣規則：清除全站。
expect_global( 'wp_after_insert_post', $template->ID, $template, true, $template );
expect_global( 'wp_after_insert_post', $header->ID, $header, true, $header );
expect_global( 'deleted_post', $menuItem->ID, $menuItem );
expect_global( 'edited_term', 1, 2, 'category' );
expect_global( 'created_term', 7, 7, 'product_cat' );
expect_global( 'delete_term', 12, 12, 'product_tag' );
expect_global( 'added_option', 'widget_block', [] );
expect_global( 'updated_option', 'show_on_front', 'posts', 'page' );
expect_global( 'deleted_option', 'theme_mods_blocksy' );
expect_global( 'wp_update_nav_menu_item', 1, 2, [] );
expect_global( 'wp_delete_nav_menu', 1 );
expect_global( 'ys_ec_product_saved', 1, [ 'price' => 34, 'stock_qty' => 2 ], true );
expect_global( 'ys_ec_product_deleted', 1, 'trash' );
expect_global( 'ys_ec_category_saved', 1, [], true );
expect_global( 'ys_cache_content_changed' );
expect_global( 'advanced_woo_discount_rules_after_save_rule', 3, [] );
expect_global( 'advanced_woo_discount_rules_after_delete_rule', 3 );
expect_global( 'advanced_woo_discount_rules_after_delete_rules' );
expect_global( 'elementor/core/files/clear_cache' );
// 刪除圖片時核心以 object_id 0 一次移除所有使用它的精選圖片。
expect_global( 'deleted_post_meta', [ 81, 82 ], 0, '_thumbnail_id', 20 );
expect_nothing( 'deleted_post_meta', [ 83 ], 0, '_product_image_gallery', 20 );

// Woo Discount Rules 後台 AJAX：只有管理商店的使用者以 POST 改變規則時才清除全站。
foreach ( [ 'save_rule', 'manage_status', 'delete_rule', 'duplicate_rule', 'import_rules', 'bulk_action' ] as $method ) {
    request();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [ 'action' => 'wdr_ajax', 'method' => $method ];
    $GLOBALS['can_manage'] = true;
    fire( 'admin_init' );
    fire( 'shutdown' );
    assert_count( 1, 'Woo Discount Rules change via ' . $method );
}
foreach ( [
    [ 'POST', 'wdr_ajax', 'get_products', true ],
    [ 'POST', 'wdr_ajax', 'save_rule', false ],
    [ 'GET', 'wdr_ajax', 'save_rule', true ],
    [ 'POST', 'other_ajax', 'save_rule', true ],
] as [ $method, $action, $operation, $manager ] ) {
    request();
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = [ 'action' => $action, 'method' => $operation ];
    $GLOBALS['can_manage'] = $manager;
    fire( 'admin_init' );
    fire( 'shutdown' );
    assert_count( 0, 'Ignore read-only or unauthorized Woo Discount Rules request ' . json_encode( [ $method, $action, $operation, $manager ] ) );
}

// 篩選器可以把其他版面範本類型加入全站清除。
request();
$GLOBALS['filter_overrides']['ys_cache_global_post_types'] = static fn ( array $types ): array => [ ...$types, 'my_layout' ];
fire( 'wp_after_insert_post', $layout->ID, $layout, true, $layout );
fire( 'shutdown' );
assert_count( 1, 'Filtered layout post type purges the whole site' );

// 一般內容只清受影響的網址。
expect_scoped( $pageUrls, 'wp_after_insert_post', $page->ID, $page, true, $page );
expect_scoped( $pageUrls, 'wp_after_insert_post', $draft->ID, $draft, true, $page );
expect_scoped( urls( '/blog/hello/', '/', '/blog/', '/author/3/', '/2026/', '/2026/11/', '/2026/11/25/' ), 'transition_post_status', 'publish', 'future', $scheduled );
expect_scoped( $pageUrls, 'transition_post_status', 'trash', 'publish', $page );
expect_scoped( $pageUrls, 'deleted_post', $page->ID, $page );
expect_scoped( $newsUrls, 'before_delete_post', $news->ID, $news );
expect_scoped( $newsUrls, 'wp_after_insert_post', $news->ID, $news, true, $news );
expect_scoped( $productUrls, 'wp_after_insert_post', $product->ID, $product, true, $product );
expect_scoped( $productUrls, 'wp_after_insert_post', $variation->ID, $variation, true, $variation );
expect_scoped( $productUrls, 'wp_after_insert_post', $productImage->ID, $productImage, true, $productImage );
expect_scoped( $productUrls, 'delete_attachment', $productImage->ID, $productImage );
expect_scoped( $productUrls, 'updated_post_meta', 1, $productImage->ID, '_wp_attachment_image_alt' );
expect_scoped( $productUrls, 'updated_post_meta', 1, $product->ID, '_price' );
expect_scoped( $productUrls, 'updated_post_meta', 1, $variation->ID, '_regular_price' );
expect_scoped( $productUrls, 'updated_post_meta', 1, $product->ID, '_thumbnail_id' );
expect_scoped( $productUrls, 'wp_insert_comment', 4, $GLOBALS['comments'][4] );
expect_scoped( $pageUrls, 'deleted_post_meta', [ 2 ], $page->ID, '_thumbnail_id' );
expect_scoped( [ ...$pageUrls, home_url( '/category/2/' ) ], 'added_term_relationship', $page->ID, 2, 'category' );
expect_scoped( [ ...$pageUrls, home_url( '/category/2/' ) ], 'deleted_term_relationships', $page->ID, [ 2 ], 'category' );
expect_scoped( $newsUrls, 'added_term_relationship', $news->ID, 6, 'post_tag' );
expect_scoped( [ ...$newsUrls, home_url( '/category/2/' ) ], 'deleted_term_relationships', $news->ID, [ 2 ], 'category' );
expect_scoped( urls( '/product_cat/9/', '/product_cat/11/', '/', '/shop/' ), 'updated_term_meta', 3, 9, 'thumbnail_id' );
expect_scoped( $pageUrls, 'wp_insert_comment', 1, $GLOBALS['comments'][1] );
expect_scoped( $pageUrls, 'edit_comment', 1 );
expect_scoped( $pageUrls, 'transition_comment_status', 'unapproved', 'approved', $GLOBALS['comments'][1] );
expect_scoped( $pageUrls, 'deleted_comment', 1, $GLOBALS['comments'][1] );
// 精選商品（product_visibility 非缺貨）只清該商品的相關網址；缺貨標記沿用庫存範圍。
expect_scoped( $productUrls, 'added_term_relationship', $product->ID, 102, 'product_visibility' );
expect_scoped( $availabilityUrls, 'deleted_term_relationships', $product->ID, [ 101, 102 ], 'product_visibility' );
// 沒有公開上層文章的圖片不清任何頁面。
expect_nothing( 'deleted_post', $media->ID, $media );
expect_nothing( 'updated_post_meta', 1, $media->ID, '_wp_attachment_metadata' );
expect_nothing( 'updated_post_meta', 1, $privateVariation->ID, '_price' );

// 網址變更：在寫入前記下舊網址，舊網址與新網址都清除。
request();
fire( 'pre_post_update', $page->ID, [ 'post_name' => 'about-us', 'post_parent' => 0, 'post_status' => 'publish' ] );
$GLOBALS['posts'][10] = $renamed;
fire( 'transition_post_status', 'publish', 'publish', $renamed );
fire( 'wp_after_insert_post', $renamed->ID, $renamed, true, $page );
fire( 'shutdown' );
assert_count( 0, 'slug change' );
assert_scoped( [ urls( '/about/', '/', '/about-us/' ) ], 'slug change purges the previous and the new address' );
$GLOBALS['posts'] = $fixturePosts;

// 下架或移到回收桶：寫入前就記下目前網址與所屬分類（之後網址會變成 ?p= 或 __trashed）。
request();
fire( 'pre_post_update', $news->ID, [ 'post_name' => 'news__trashed', 'post_parent' => 0, 'post_status' => 'trash' ] );
$GLOBALS['object_terms'][12] = [];
$GLOBALS['posts'][12] = new WP_Post( 12, 'post', 'trash', 0, 'news__trashed', 3, '2026-11-20 08:00:00' );
fire( 'transition_post_status', 'trash', 'publish', $GLOBALS['posts'][12] );
fire( 'shutdown' );
assert_count( 0, 'trashing a post' );
assert_scoped( [ [ ...$newsUrls, home_url( '/blog/news__trashed/' ) ] ], 'trashing captures the published address and categories' );
$GLOBALS['posts'] = $fixturePosts;
$GLOBALS['object_terms'] = [ 12 => [ 5, 6 ], 21 => [ 9, 12 ] ];

request();
fire( 'pre_post_update', $page->ID, [ 'post_name' => 'about', 'post_parent' => 0, 'post_status' => 'publish' ] );
fire( 'pre_post_update', $revision->ID, [ 'post_name' => 'x', 'post_status' => 'inherit' ] );
fire( 'shutdown' );
assert_count( 0, 'unchanged address' );
assert_scoped( [], 'an update that keeps the address waits for the save hooks' );

// 超過 300 個網址時改為全站清除；剛好 300 個仍逐頁清除。
foreach ( [ 299 => [ 0, 1 ], 300 => [ 1, 0 ] ] as $count => [ $global, $scopedCalls ] ) {
    request();
    for ( $i = 0; $i < $count; $i++ ) {
        $bulk = new WP_Post( 1000 + $i, 'page', 'publish', 0, 'bulk-' . $i );
        $GLOBALS['posts'][ $bulk->ID ] = $bulk;
        fire( 'wp_after_insert_post', $bulk->ID, $bulk, true, $bulk );
    }
    fire( 'shutdown' );
    assert_count( $global, $count . ' changed pages' );
    if ( count( $GLOBALS['scoped_purges'] ) !== $scopedCalls || ( $scopedCalls && count( $GLOBALS['scoped_purges'][0] ) !== $count + 1 ) ) {
        throw new RuntimeException( $count . ' changed pages: unexpected scoped purge ' . count( $GLOBALS['scoped_purges'] ) );
    }
}
$GLOBALS['posts'] = $fixturePosts;

// 大量變更（超過 300 篇）直接清除全站，不逐篇計算網址。
request();
for ( $i = 0; $i < 301; $i++ ) {
    $bulk = new WP_Post( 2000 + $i, 'product', 'publish', 0, 'bulk-' . $i );
    $GLOBALS['posts'][ $bulk->ID ] = $bulk;
    fire( 'transition_post_status', 'publish', 'draft', $bulk );
}
$GLOBALS['permalink_calls'] = 0;
fire( 'shutdown' );
assert_count( 1, 'bulk publish of 301 products' );
if ( $GLOBALS['permalink_calls'] !== 0 ) {
    throw new RuntimeException( 'A bulk change should not compute every permalink at shutdown.' );
}
$GLOBALS['posts'] = $fixturePosts;

// 同一次請求裡有全站清除時，以全站為準。
request();
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'updated_post_meta', 1, $product->ID, '_price' );
fire( 'updated_option', 'sidebars_widgets', [], [] );
fire( 'shutdown' );
assert_count( 1, 'global wins over page purges' );
assert_scoped( [], 'no page purge when the whole site is purged' );

// 沒有逐頁清除的回呼時，一般內容改為全站清除；只有庫存變更時維持不清除。
request( false );
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'shutdown' );
assert_count( 1, 'scoped change without a page callback' );
request( false );
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'shutdown' );
assert_count( 0, 'stock change without a page callback' );

// 庫存。
expect_stock_scoped( [ $productUrl ], 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
if ( $GLOBALS['catalog_term_queries'] ) {
    throw new RuntimeException( 'A stock amount change should not query catalog membership.' );
}
expect_stock_scoped( [ $productUrl ], 'woocommerce_variation_set_stock', new WC_Product( $variation->ID ) );
expect_stock_scoped( [ $productUrl ], 'woocommerce_updated_product_stock', $product->ID );
expect_stock_scoped( [ $productUrl ], 'updated_post_meta', 3, $variation->ID, '_stock' );
expect_stock_scoped( $availabilityUrls, 'woocommerce_product_set_stock_status', $product->ID, 'outofstock', new WC_Product( $product->ID ) );
expect_stock_scoped( $availabilityUrls, 'woocommerce_variation_set_stock_status', $variation->ID, 'instock', new WC_Product( $variation->ID ) );
expect_stock_scoped( $availabilityUrls, 'updated_post_meta', 3, $product->ID, '_stock_status' );
expect_stock_scoped( $availabilityUrls, 'added_term_relationship', $product->ID, 101, 'product_visibility' );
expect_stock_scoped( $availabilityUrls, 'deleted_term_relationships', $product->ID, [ 101 ], 'product_visibility' );

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
assert_content( [ false ], 'derived parent prices are part of the stock change' );

request();
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'updated_post_meta', 2, $product->ID, '_price' );
fire( 'shutdown' );
assert_count( 0, 'explicit price edit on a product with a stock change' );
assert_scoped( [ $productUrls ], 'explicit price edit purges the product, its catalog pages and the stock page' );

request();
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
add_action( 'shutdown', static function () use ( $product ): void {
    fire( 'updated_post_meta', 2, $product->ID, '_price' );
}, 10, 0 );
fire( 'shutdown' );
assert_count( 0, 'shutdown price edit without a Woo sync signal' );
assert_scoped( [ $productUrls ], 'an unsynchronized shutdown price edit is treated as a real price change' );
assert_content( [ true ], 'an unsynchronized price edit is a content change' );

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
assert_content( [ false ], 'combined stock changes stay stock-only' );
if ( $GLOBALS['catalog_term_queries'] !== [ [ $product->ID ] ] ) {
    throw new RuntimeException( 'Catalog membership should be loaded once for stock status changes.' );
}

request();
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'updated_post_meta', 1, $page->ID, '_thumbnail_id' );
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'shutdown' );
fire( 'shutdown' );
assert_count( 0, 'content and stock changes together' );
assert_scoped( [ [ ...$pageUrls, $productUrl ] ], 'content edits and stock changes are purged together once' );
assert_content( [ true ], 'content edits mark a mixed batch as content' );

request();
fire( 'wp_after_insert_post', $page->ID, $page, true, $page );
fire( 'updated_post_meta', 1, $page->ID, '_thumbnail_id' );
fire( 'deleted_option', 'widget_block' );
fire( 'woocommerce_product_set_stock', new WC_Product( $product->ID ) );
fire( 'shutdown' );
fire( 'shutdown' );
assert_count( 1, 'duplicate mutations and shutdown' );
assert_scoped( [], 'a site-wide change subsumes simultaneous stock changes' );

request();
fire( 'clean_post_cache', $page->ID, $page );
fire( 'wp_after_insert_post', $draft->ID, $draft, true, $draft );
fire( 'wp_after_insert_post', $revision->ID, $revision, false, null );
fire( 'wp_after_insert_post', $private->ID, $private, false, null );
fire( 'wp_after_insert_post', $order->ID, $order, true, $order );
fire( 'wp_after_insert_post', $media->ID, $media, true, $media );
fire( 'transition_post_status', 'publish', 'publish', $page );
fire( 'transition_post_status', 'draft', 'auto-draft', $productDraft );
fire( 'before_delete_post', $private->ID, $private );
fire( 'updated_post_meta', 1, $page->ID, 'product_view_count' );
fire( 'updated_post_meta', 1, $productDraft->ID, '_price' );
fire( 'wp_insert_comment', 2, $GLOBALS['comments'][2] );
fire( 'wp_insert_comment', 3, $GLOBALS['comments'][3] );
fire( 'edit_comment', 2 );
fire( 'added_term_relationship', $page->ID, 2, 'user_group' );
fire( 'added_term_relationship', $productDraft->ID, 9, 'product_cat' );
fire( 'updated_term_meta', 3, 9, 'product_count_product_cat' );
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
