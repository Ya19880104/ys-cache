<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheInvalidation {
    private const SHARED_POST_TYPES = [
        'attachment', 'custom_css', 'nav_menu_item', 'product_variation',
        'wp_block', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part',
    ];
    private const RENDERED_POST_META = [
        '_thumbnail_id', '_wp_page_template', '_wp_attached_file', '_wp_attachment_image_alt',
        '_wp_attachment_metadata', '_regular_price', '_sale_price', '_price', '_stock',
        '_stock_status', '_product_image_gallery', '_wc_average_rating', '_wc_review_count',
    ];
    private const APPEARANCE_OPTIONS = [
        'blogdescription', 'blogname', 'category_base', 'date_format', 'home',
        'page_for_posts', 'page_on_front', 'permalink_structure', 'posts_per_page',
        'show_on_front', 'sidebars_widgets', 'site_icon', 'siteurl', 'sticky_posts', 'tag_base',
        'time_format', 'timezone_string', 'gmt_offset', 'WPLANG',
    ];

    private \Closure $invalidate;
    private bool $dirty = false;
    private bool $flushed = false;

    public function __construct( callable $invalidate ) {
        $this->invalidate = \Closure::fromCallable( $invalidate );
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
        foreach ( [
            'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_update_nav_menu_item',
            'wp_delete_nav_menu', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete',
            'ys_tp_settings_saved', 'trp_save_editor_translations_regular_strings',
            'trp_save_editor_translations_gettext_strings', 'ys_cache_content_changed',
            'ys_ec_product_saved', 'ys_ec_product_deleted', 'ys_ec_product_auto_published',
            'ys_ec_product_auto_unpublished', 'ys_ec_category_saved', 'ys_ec_category_deleted',
            'woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
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
        if ( $this->dirty && ! $this->flushed ) {
            $this->flushed = true;
            ( $this->invalidate )();
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
        if ( $post instanceof \WP_Post && self::publicPost( $post ) &&
            apply_filters( 'ys_cache_rendered_post_meta', in_array( $key, self::RENDERED_POST_META, true ), $key, $post ) ) {
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
        if ( $post instanceof \WP_Post && $type && in_array( $post->post_type, $type->object_type, true ) && self::publicPost( $post ) ) {
            $this->mark();
        }
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
