<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheInvalidation {
    private const SHARED_POST_TYPES = [
        'attachment', 'custom_css', 'nav_menu_item', 'product_variation',
        'wp_block', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wpcf7_contact_form',
    ];
    private const PRODUCT_PRICES = [ '_regular_price', '_sale_price', '_price' ];
    private const RENDERED_POST_META = [
        '_thumbnail_id', '_wp_page_template', '_wp_attached_file', '_wp_attachment_image_alt',
        '_wp_attachment_metadata', ...self::PRODUCT_PRICES,
        '_product_image_gallery', '_wc_average_rating', '_wc_review_count',
    ];
    private const APPEARANCE_OPTIONS = [
        'blogdescription', 'blogname', 'category_base', 'date_format', 'home',
        'page_for_posts', 'page_on_front', 'permalink_structure', 'posts_per_page',
        'show_on_front', 'sidebars_widgets', 'site_icon', 'siteurl', 'sticky_posts', 'tag_base',
        'time_format', 'timezone_string', 'gmt_offset', 'WPLANG', 'wpcf7',
    ];

    private \Closure $invalidate;
    private ?\Closure $invalidateStock;
    private array $stockProducts = [];
    private array $deferredPriceWrites = [];
    private bool $dirty = false;
    private bool $flushed = false;

    public function __construct( callable $invalidate, ?callable $invalidateStock = null ) {
        $this->invalidate = \Closure::fromCallable( $invalidate );
        $this->invalidateStock = $invalidateStock === null ? null : \Closure::fromCallable( $invalidateStock );
    }

    public function register(): void {
        add_action( 'wp_after_insert_post', [ $this, 'postSaved' ], 10, 4 );
        add_action( 'transition_post_status', [ $this, 'postStatusChanged' ], 10, 3 );
        add_action( 'deleted_post', [ $this, 'postDeleted' ], 10, 2 );

        foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
            add_action( $hook, [ $this, 'postMetaChanged' ], 10, 3 );
        }
        foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
            add_action( $hook, [ $this, 'termChanged' ], 10, 3 );
        }
        foreach ( [ 'added_term_relationship', 'deleted_term_relationships' ] as $hook ) {
            add_action( $hook, [ $this, 'termRelationshipChanged' ], 10, 3 );
        }
        foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
            add_action( $hook, [ $this, 'termMetaChanged' ], 10, 3 );
        }

        add_action( 'wp_insert_comment', [ $this, 'commentSaved' ], 10, 2 );
        add_action( 'edit_comment', [ $this, 'commentEdited' ] );
        add_action( 'transition_comment_status', [ $this, 'commentStatusChanged' ], 10, 3 );
        add_action( 'deleted_comment', [ $this, 'commentSaved' ], 10, 2 );

        foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
            add_action( $hook, [ $this, 'optionChanged' ] );
        }
        foreach ( [ 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_updated_product_stock' ] as $hook ) {
            add_action( $hook, [ $this, 'wooQuantityChanged' ] );
        }
        foreach ( [ 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ] as $hook ) {
            add_action( $hook, [ $this, 'wooAvailabilityChanged' ] );
        }
        add_action( 'woocommerce_updated_product_price', [ $this, 'wooParentPricesSynced' ] );
        add_action( 'ys_ec_product_saved', [ $this, 'ysCartProductSaved' ], 10, 3 );
        foreach ( [
            'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_update_nav_menu_item',
            'wp_delete_nav_menu', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete',
            'ys_tp_settings_saved', 'trp_save_editor_translations_regular_strings',
            'trp_save_editor_translations_gettext_strings', 'ys_cache_content_changed', 'wpcf7_after_save',
            'ys_ec_product_deleted', 'ys_ec_product_auto_published',
            'ys_ec_product_auto_unpublished', 'ys_ec_category_saved', 'ys_ec_category_deleted',
        ] as $hook ) {
            add_action( $hook, [ $this, 'mark' ], 10, 0 );
        }

        // Flush after the request has finished writing related terms, metadata and settings.
        add_action( 'shutdown', [ $this, 'flush' ], PHP_INT_MAX );
    }

    public function mark(): void {
        $this->dirty = true;
    }

    public function flush(): void {
        if ( $this->flushed || ( ! $this->dirty && ! $this->stockProducts ) ) {
            return;
        }
        $this->flushed = true;
        if ( $this->dirty || $this->deferredPriceWrites ) {
            ( $this->invalidate )();
        } elseif ( $this->invalidateStock && ( $urls = $this->wooStockUrls() ) ) {
            ( $this->invalidateStock )( $urls );
        }
    }

    private static function publicPost( \WP_Post $post, ?string $status = null ): bool {
        if ( $post->post_type === 'revision' ) {
            return false;
        }
        $status ??= $post->post_status;
        if ( $status === 'inherit' ) {
            return $post->post_type === 'attachment';
        }
        $type = get_post_type_object( $post->post_type );
        return ( is_post_status_viewable( $status ) &&
            ( ( $type && is_post_type_viewable( $type ) ) || in_array( $post->post_type, self::SHARED_POST_TYPES, true ) ) );
    }

    public function postSaved( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( self::publicPost( $post ) || ( $before && self::publicPost( $before ) ) ) {
            $this->mark();
        }
    }

    public function postStatusChanged( string $new, string $old, \WP_Post $post ): void {
        if ( $new !== $old && ( self::publicPost( $post, $new ) || self::publicPost( $post, $old ) ) ) {
            $this->mark();
        }
    }

    public function postDeleted( int $id, \WP_Post $post ): void {
        if ( self::publicPost( $post ) ) {
            $this->mark();
        }
    }

    public function postMetaChanged( $metaId, int $postId, string $key ): void {
        $post = get_post( $postId );
        if ( ! ( $post instanceof \WP_Post ) || ! self::publicPost( $post ) ) {
            return;
        }
        if ( in_array( $key, [ '_stock', '_stock_status' ], true ) && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            $this->markWooStock( $post, $key === '_stock_status' );
            return;
        }
        if ( isset( $this->stockProducts[ $postId ] ) && doing_action( 'shutdown' ) && in_array( $key, self::PRODUCT_PRICES, true ) ) {
            // A child stock write can make Woo rebuild the parent's derived prices at shutdown.
            $this->deferredPriceWrites[ $postId ] = true;
            return;
        }
        if ( apply_filters( 'ys_cache_rendered_post_meta', in_array( $key, self::RENDERED_POST_META, true ), $key, $post ) ) {
            $this->mark();
        }
    }

    public function wooQuantityChanged( $product ): void {
        $productId = $product instanceof \WC_Product ? $product->get_id() : $product;
        if ( is_int( $productId ) && $productId > 0 ) {
            $this->markWooStock( get_post( $productId ), false );
        }
    }

    public function wooAvailabilityChanged( int $productId ): void {
        if ( $productId > 0 ) {
            $this->markWooStock( get_post( $productId ), true );
        }
    }

    public function wooParentPricesSynced( int $productId ): void {
        unset( $this->deferredPriceWrites[ $productId ] );
    }

    private function markWooStock( ?\WP_Post $post, bool $availability ): void {
        if ( ! $this->invalidateStock || ! $post || ! self::publicPost( $post ) ) {
            return;
        }
        if ( $post->post_type === 'product_variation' ) {
            $post = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
        }
        if ( $post && $post->post_type === 'product' && self::publicPost( $post ) ) {
            $this->stockProducts[ $post->ID ] = $availability || ( $this->stockProducts[ $post->ID ] ?? false );
        }
    }

    private function wooStockUrls(): array {
        $urls = array_map( 'get_permalink', array_keys( $this->stockProducts ) );
        $catalogProducts = array_keys( array_filter( $this->stockProducts ) );
        if ( $catalogProducts ) {
            $urls[] = home_url( '/' );
            if ( function_exists( 'wc_get_page_permalink' ) ) {
                $urls[] = wc_get_page_permalink( 'shop' );
            }
            $terms = wp_get_object_terms( $catalogProducts, [ 'product_cat', 'product_tag' ] );
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                if ( ! ( $term instanceof \WP_Term ) ) {
                    continue;
                }
                $urls[] = get_term_link( $term );
                if ( $term->taxonomy === 'product_cat' ) {
                    foreach ( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) as $ancestor ) {
                        $urls[] = get_term_link( (int) $ancestor, $term->taxonomy );
                    }
                }
            }
        }
        return array_values( array_unique( array_filter( $urls, static fn ( $url ): bool => is_string( $url ) && $url !== '' ) ) );
    }

    public function ysCartProductSaved( int $productId, array $changes, bool $update ): void {
        // Checkout stock writes use YS CART's stock service. Stock-only catalog
        // imports can also emit this generic product event.
        if ( ! $update || array_diff( array_keys( $changes ), [ 'stock_qty', 'stock_reserved', 'low_stock_threshold', 'updated_at' ] ) ) {
            $this->mark();
        }
    }

    public function termChanged( int $termId, int $taxonomyId, string $taxonomy ): void {
        if ( is_taxonomy_viewable( $taxonomy ) || $taxonomy === 'product_visibility' ) {
            $this->mark();
        }
    }

    public function termRelationshipChanged( int $postId, $taxonomyIds, string $taxonomy ): void {
        $post = get_post( $postId );
        $type = get_taxonomy( $taxonomy );
        if ( ! ( $post instanceof \WP_Post ) || ! $type || ! in_array( $post->post_type, $type->object_type, true ) || ! self::publicPost( $post ) ) {
            return;
        }
        if ( $taxonomy === 'product_visibility' && function_exists( 'wc_get_product_visibility_term_ids' ) ) {
            $ids = array_map( 'intval', (array) $taxonomyIds );
            $outOfStockId = (int) ( wc_get_product_visibility_term_ids()['outofstock'] ?? 0 );
            if ( $outOfStockId && in_array( $outOfStockId, $ids, true ) ) {
                $this->markWooStock( $post, true );
                if ( ! array_diff( $ids, [ $outOfStockId ] ) ) {
                    return;
                }
            }
        }
        $this->mark();
    }

    public function termMetaChanged( $metaId, int $termId, string $key ): void {
        $term = get_term( $termId );
        if ( $term instanceof \WP_Term && is_taxonomy_viewable( $term->taxonomy ) &&
            apply_filters( 'ys_cache_rendered_term_meta', $key === 'thumbnail_id', $key, $term ) ) {
            $this->mark();
        }
    }

    public function commentEdited( int $id ): void {
        $comment = get_comment( $id );
        if ( $comment instanceof \WP_Comment ) {
            $this->commentSaved( $id, $comment );
        }
    }

    public function commentStatusChanged( string $new, string $old, \WP_Comment $comment ): void {
        if ( $new !== $old && in_array( 'approved', [ $new, $old ], true ) ) {
            $this->publicCommentChanged( $comment );
        }
    }

    public function commentSaved( int $id, \WP_Comment $comment ): void {
        if ( (string) $comment->comment_approved === '1' ) {
            $this->publicCommentChanged( $comment );
        }
    }

    private function publicCommentChanged( \WP_Comment $comment ): void {
        $post = get_post( (int) $comment->comment_post_ID );
        if ( $post instanceof \WP_Post && self::publicPost( $post ) ) {
            $this->mark();
        }
    }

    public function optionChanged( string $option ): void {
        if ( preg_match( '/^(?:theme_mods_|widget_|trp_|ys_trp_|ys_tp_|ys_ec_page_|woocommerce_(?:cart|checkout|myaccount|shop)_page_id$)/', $option ) ||
            in_array( $option, self::APPEARANCE_OPTIONS, true ) ) {
            $this->mark();
        }
    }
}
