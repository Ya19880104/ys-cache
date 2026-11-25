<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * 內容變更時決定清除範圍。
 *
 * - 文章、商品、圖片、變體、價格與縮圖 meta、留言、分類關係：只清受影響的網址（本身與變更前網址、首頁、
 *   彙整與商店頁、所屬公開分類與上層、文章的作者與日期彙整）。
 * - 選單、小工具、佈景、範本、外觀選項、分類 term 本身、折扣規則等版面共用內容：清除全站。
 * - 請求期間只收集，shutdown 時一次送出；同時有全站清除時以全站為準，網址超過 300 個也改為全站清除。
 */
final class YSCacheInvalidation {
    /** 版面共用的文章類型：內容會出現在任何頁面，變更時清除全站。可用 ys_cache_global_post_types 增減。 */
    private const GLOBAL_POST_TYPES = [
        'custom_css', 'nav_menu_item', 'wp_block', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part',
        'wpcf7_contact_form',
        // 頁面產生器的頁首、頁尾、彈窗與列表範本（Elementor、JetThemeCore／JetEngine、Blocksy 內容區塊等）。
        'elementor_library', 'jet-theme-core', 'jet-engine', 'jet-popup', 'ct_content_block', 'bricks_template',
        'et_pb_layout', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'fl-builder-template',
        'kadence_element', 'gp_elements',
    ];
    /** 沒有自己頁面的類型：變更時清除所屬文章。 */
    private const CHILD_POST_TYPES = [ 'attachment', 'product_variation' ];
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
    /** 一次請求最多逐頁清除的網址數，超過就改為全站清除。 */
    private const MAX_URLS = 300;
    /** Woo Discount Rules 後台 AJAX（wdr_ajax）中會改變規則的方法。 */
    private const DISCOUNT_RULE_METHODS = '/save|status|delete|duplicate|import|bulk/i';

    private \Closure $invalidate;
    private ?\Closure $invalidatePages;
    private ?array $globalTypes = null;
    /** 立即算出的網址（變更前網址、已刪除文章、分類縮圖）。 */
    private array $urls = [];
    /** shutdown 時再計算網址的文章 ID（取得最終的網址與分類）。 */
    private array $posts = [];
    /** 關係有變動的 term_taxonomy_id => taxonomy。 */
    private array $terms = [];
    private array $stockProducts = [];
    private array $deferredPriceWrites = [];
    private bool $dirty = false;
    private bool $flushed = false;

    /**
     * @param callable      $invalidate      清除全站。
     * @param callable|null $invalidatePages 立即清除這些網址：( array $urls, bool $content )。$urls 為本站的絕對網址；
     *                                       $content 為 false 表示只有庫存變更，true 表示包含內容變更。
     */
    public function __construct( callable $invalidate, ?callable $invalidatePages = null ) {
        $this->invalidate = \Closure::fromCallable( $invalidate );
        $this->invalidatePages = $invalidatePages === null ? null : \Closure::fromCallable( $invalidatePages );
    }

    public function register(): void {
        add_action( 'pre_post_update', [ $this, 'postUpdating' ], 10, 2 );
        add_action( 'wp_after_insert_post', [ $this, 'postSaved' ], 10, 4 );
        add_action( 'transition_post_status', [ $this, 'postStatusChanged' ], 10, 3 );
        add_action( 'before_delete_post', [ $this, 'postDeleting' ], 10, 2 );
        add_action( 'delete_attachment', [ $this, 'postDeleting' ], 10, 2 );
        add_action( 'deleted_post', [ $this, 'postDeleting' ], 10, 2 );

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
            'advanced_woo_discount_rules_after_save_rule', 'advanced_woo_discount_rules_after_delete_rule',
            'advanced_woo_discount_rules_after_delete_rules', 'elementor/core/files/clear_cache',
        ] as $hook ) {
            add_action( $hook, [ $this, 'mark' ], 10, 0 );
        }
        // Woo Discount Rules 可以只切換規則狀態而不觸發儲存 hook，從後台 AJAX 請求判斷。
        add_action( 'admin_init', [ $this, 'discountRulesRequest' ], 10, 0 );

        // 等這次請求寫完分類、meta 與設定後才送出。
        add_action( 'shutdown', [ $this, 'flush' ], PHP_INT_MAX );
    }

    /** 要求清除全站。 */
    public function mark(): void {
        $this->dirty = true;
    }

    public function flush(): void {
        if ( $this->flushed || ( ! $this->dirty && ! $this->urls && ! $this->posts && ! $this->terms
            && ! $this->stockProducts && ! $this->deferredPriceWrites ) ) {
            return;
        }
        $this->flushed = true;
        // 每篇文章至少有自己的網址：大量變更（批次編輯、匯入）直接清除全站，不必逐篇計算。
        if ( $this->dirty || count( $this->posts ) + count( $this->deferredPriceWrites ) > self::MAX_URLS ) {
            ( $this->invalidate )();
            return;
        }
        // 第二個參數告訴回呼這批網址是否包含庫存以外的內容變更（只能整站清除的層需要改清全站）。
        $content = $this->urls || $this->posts || $this->terms || $this->deferredPriceWrites;
        $urls = $this->pageUrls();
        if ( $urls === [] ) {
            return;
        }
        if ( $this->invalidatePages === null || count( $urls ) > self::MAX_URLS ) {
            ( $this->invalidate )();
            return;
        }
        ( $this->invalidatePages )( $urls, $content );
    }

    /** 彙整這次請求要清除的網址（去除重複、保留順序）。 */
    private function pageUrls(): array {
        // 庫存寫入期間的價格變更若沒有 Woo 衍生價格同步訊號，視為實際改價。
        foreach ( array_keys( $this->deferredPriceWrites ) as $productId ) {
            $this->posts[ $productId ] = true;
        }
        $urls = array_keys( $this->urls );
        foreach ( array_keys( $this->posts ) as $postId ) {
            $post = get_post( $postId );
            if ( $post instanceof \WP_Post ) {
                foreach ( self::postUrls( $post ) as $url ) {
                    $urls[] = $url;
                }
            }
        }
        foreach ( $this->terms as $termTaxonomyId => $taxonomy ) {
            $term = get_term_by( 'term_taxonomy_id', $termTaxonomyId, $taxonomy );
            if ( $term instanceof \WP_Term ) {
                foreach ( self::termUrls( $term ) as $url ) {
                    $urls[] = $url;
                }
            }
        }
        if ( $this->stockProducts ) {
            foreach ( $this->wooStockUrls() as $url ) {
                $urls[] = $url;
            }
        }
        return self::unique( $urls );
    }

    private static function unique( array $urls ): array {
        $unique = [];
        foreach ( $urls as $url ) {
            if ( is_string( $url ) && ( $url = trim( $url ) ) !== '' && strlen( $url ) <= 2048 ) {
                $unique[ $url ] = true;
            }
        }
        return array_map( 'strval', array_keys( $unique ) );
    }

    private function addUrls( array $urls ): void {
        foreach ( self::unique( $urls ) as $url ) {
            $this->urls[ $url ] = true;
        }
    }

    /**
     * 一篇公開文章會影響的網址：本身、首頁、彙整（商品另含商店頁）、所屬公開分類與上層；
     * 一般文章另含文章頁、作者與年月日彙整。
     */
    private static function postUrls( \WP_Post $post ): array {
        $type = $post->post_type;
        $urls = [ get_permalink( $post ), home_url( '/' ), get_post_type_archive_link( $type ) ];
        if ( $type === 'product' && function_exists( 'wc_get_page_permalink' ) ) {
            $urls[] = wc_get_page_permalink( 'shop' );
        }
        if ( $type === 'post' ) {
            $postsPage = (int) get_option( 'page_for_posts' );
            if ( $postsPage > 0 ) {
                $urls[] = get_permalink( $postsPage );
            }
            if ( (int) $post->post_author > 0 ) {
                $urls[] = get_author_posts_url( (int) $post->post_author );
            }
            if ( preg_match( '~\A([0-9]{4})-([0-9]{2})-([0-9]{2})~', (string) $post->post_date, $date ) && (int) $date[1] >= 1970 ) {
                $urls[] = get_year_link( (int) $date[1] );
                $urls[] = get_month_link( (int) $date[1], (int) $date[2] );
                $urls[] = get_day_link( (int) $date[1], (int) $date[2], (int) $date[3] );
            }
        }
        foreach ( get_object_taxonomies( $type ) as $taxonomy ) {
            if ( ! is_string( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $post, $taxonomy );
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                if ( $term instanceof \WP_Term ) {
                    foreach ( self::termUrls( $term ) as $url ) {
                        $urls[] = $url;
                    }
                }
            }
        }
        return $urls;
    }

    /** 分類彙整與所有上層分類（Woo 上層分類頁也會列出子分類的商品與縮圖）。 */
    private static function termUrls( \WP_Term $term ): array {
        $urls = [ get_term_link( $term ) ];
        foreach ( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) as $ancestor ) {
            $urls[] = get_term_link( (int) $ancestor, $term->taxonomy );
        }
        return $urls;
    }

    private function isGlobalType( string $type ): bool {
        $this->globalTypes ??= array_values( array_filter(
            (array) apply_filters( 'ys_cache_global_post_types', self::GLOBAL_POST_TYPES ), 'is_string'
        ) );
        return in_array( $type, $this->globalTypes, true );
    }

    /** 有自己公開網址的一般類型（不是版面共用、圖片或變體）。 */
    private function ownAddress( \WP_Post $post ): bool {
        return ! in_array( $post->post_type, self::CHILD_POST_TYPES, true ) && ! $this->isGlobalType( $post->post_type );
    }

    private function publicPost( \WP_Post $post, ?string $status = null ): bool {
        if ( $post->post_type === 'revision' ) {
            return false;
        }
        $status ??= $post->post_status;
        if ( $status === 'inherit' ) {
            return $post->post_type === 'attachment';
        }
        if ( ! is_post_status_viewable( $status ) ) {
            return false;
        }
        if ( in_array( $post->post_type, self::CHILD_POST_TYPES, true ) || $this->isGlobalType( $post->post_type ) ) {
            return true;
        }
        $type = get_post_type_object( $post->post_type );
        return $type && is_post_type_viewable( $type );
    }

    /** 依類型轉成清除範圍：版面共用清全站；圖片與變體清所屬的公開文章；其他在 shutdown 計算本身的相關網址。 */
    private function postChanged( \WP_Post $post, int $depth = 0 ): void {
        if ( $this->isGlobalType( $post->post_type ) ) {
            $this->mark();
            return;
        }
        if ( in_array( $post->post_type, self::CHILD_POST_TYPES, true ) ) {
            $parent = $post->post_parent > 0 && $depth < 2 ? get_post( (int) $post->post_parent ) : null;
            if ( $parent instanceof \WP_Post && $parent->post_type !== $post->post_type && $this->publicPost( $parent ) ) {
                $this->postChanged( $parent, $depth + 1 );
            }
            return;
        }
        $this->posts[ (int) $post->ID ] = true;
    }

    /** 更新寫入資料庫前：網址即將改變或文章即將下架時，記下變更前的網址與所屬分類。 */
    public function postUpdating( $postId, $data ): void {
        $before = get_post( (int) $postId );
        if ( ! $before instanceof \WP_Post || ! is_array( $data ) || ! $this->publicPost( $before ) || ! $this->ownAddress( $before ) ) {
            return;
        }
        $status = is_string( $data['post_status'] ?? null ) ? $data['post_status'] : $before->post_status;
        if ( (string) ( $data['post_name'] ?? $before->post_name ) !== (string) $before->post_name
            || (int) ( $data['post_parent'] ?? $before->post_parent ) !== (int) $before->post_parent
            || ! $this->publicPost( $before, $status ) ) {
            $this->addUrls( self::postUrls( $before ) );
        }
    }

    public function postSaved( $id, $post, $update = false, $before = null ): void {
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        $public = $this->publicPost( $post );
        $wasPublic = $before instanceof \WP_Post && $this->publicPost( $before );
        if ( ! $public && ! $wasPublic ) {
            return;
        }
        // 後備：沒有經過 pre_post_update 的更新也要清除舊網址。
        if ( $wasPublic && $this->ownAddress( $before ) && ( ! $public || (string) $before->post_name !== (string) $post->post_name
            || (int) $before->post_parent !== (int) $post->post_parent ) ) {
            $this->addUrls( self::postUrls( $before ) );
        }
        $this->postChanged( $post );
    }

    public function postStatusChanged( $new, $old, $post ): void {
        if ( ! $post instanceof \WP_Post || $new === $old ) {
            return;
        }
        if ( $this->publicPost( $post, (string) $new ) || $this->publicPost( $post, (string) $old ) ) {
            $this->postChanged( $post );
        }
    }

    /** 刪除前（分類關係仍在）立即計算；刪除後文章已查不到。 */
    public function postDeleting( $id, $post = null ): void {
        $post = $post instanceof \WP_Post ? $post : get_post( (int) $id );
        if ( ! $post instanceof \WP_Post || ! $this->publicPost( $post ) ) {
            return;
        }
        if ( $this->ownAddress( $post ) ) {
            $this->addUrls( self::postUrls( $post ) );
            return;
        }
        $this->postChanged( $post );
    }

    public function postMetaChanged( $metaId, $postId, $key ): void {
        $postId = (int) $postId;
        $key = (string) $key;
        if ( $postId <= 0 ) {
            // 刪除圖片時核心一次移除所有文章的精選圖片（object_id 為 0），無法得知是哪些文章。
            if ( $key === '_thumbnail_id' && ! empty( $metaId ) ) {
                $this->mark();
            }
            return;
        }
        $post = get_post( $postId );
        if ( ! ( $post instanceof \WP_Post ) || ! $this->publicPost( $post ) ) {
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
            $this->postChanged( $post );
        }
    }

    public function wooQuantityChanged( $product ): void {
        $productId = $product instanceof \WC_Product ? $product->get_id() : $product;
        if ( is_int( $productId ) && $productId > 0 ) {
            $this->markWooStock( get_post( $productId ), false );
        }
    }

    public function wooAvailabilityChanged( $productId ): void {
        if ( is_numeric( $productId ) && (int) $productId > 0 ) {
            $this->markWooStock( get_post( (int) $productId ), true );
        }
    }

    public function wooParentPricesSynced( $productId ): void {
        unset( $this->deferredPriceWrites[ (int) $productId ] );
    }

    private function markWooStock( ?\WP_Post $post, bool $availability ): void {
        if ( ! $this->invalidatePages || ! $post || ! $this->publicPost( $post ) ) {
            return;
        }
        if ( $post->post_type === 'product_variation' ) {
            $post = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
        }
        if ( $post && $post->post_type === 'product' && $this->publicPost( $post ) ) {
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
        return self::unique( $urls );
    }

    public function ysCartProductSaved( $productId, $changes, $update ): void {
        // Checkout stock writes use YS CART's stock service. Stock-only catalog
        // imports can also emit this generic product event.
        if ( ! $update || ! is_array( $changes )
            || array_diff( array_keys( $changes ), [ 'stock_qty', 'stock_reserved', 'low_stock_threshold', 'updated_at' ] ) ) {
            $this->mark();
        }
    }

    /** 分類名稱會出現在選單、麵包屑與篩選器，新增、編輯或刪除時清除全站。 */
    public function termChanged( $termId, $taxonomyId, $taxonomy ): void {
        if ( is_string( $taxonomy ) && ( is_taxonomy_viewable( $taxonomy ) || $taxonomy === 'product_visibility' ) ) {
            $this->mark();
        }
    }

    /** 文章加入或移出分類：清除文章本身的相關網址，加上異動的分類彙整。 */
    public function termRelationshipChanged( $postId, $taxonomyIds, $taxonomy ): void {
        $post = get_post( (int) $postId );
        $type = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
        if ( ! ( $post instanceof \WP_Post ) || ! $type || ! in_array( $post->post_type, (array) $type->object_type, true ) || ! $this->publicPost( $post ) ) {
            return;
        }
        $ids = array_values( array_filter( array_map( 'intval', (array) $taxonomyIds ) ) );
        if ( $taxonomy === 'product_visibility' && function_exists( 'wc_get_product_visibility_term_ids' ) ) {
            $outOfStockId = (int) ( wc_get_product_visibility_term_ids()['outofstock'] ?? 0 );
            if ( $outOfStockId && in_array( $outOfStockId, $ids, true ) ) {
                $this->markWooStock( $post, true );
                if ( ! array_diff( $ids, [ $outOfStockId ] ) ) {
                    return;
                }
            }
        }
        $this->postChanged( $post );
        if ( is_taxonomy_viewable( $taxonomy ) ) {
            foreach ( $ids as $termTaxonomyId ) {
                $this->terms[ $termTaxonomyId ] = $taxonomy;
            }
        }
    }

    /** 分類縮圖等會顯示的 term meta：清除該分類（含上層）、首頁與商店頁。 */
    public function termMetaChanged( $metaId, $termId, $key ): void {
        $term = get_term( (int) $termId );
        if ( $term instanceof \WP_Term && is_taxonomy_viewable( $term->taxonomy ) &&
            apply_filters( 'ys_cache_rendered_term_meta', $key === 'thumbnail_id', $key, $term ) ) {
            $urls = self::termUrls( $term );
            $urls[] = home_url( '/' );
            if ( function_exists( 'wc_get_page_permalink' ) ) {
                $urls[] = wc_get_page_permalink( 'shop' );
            }
            $this->addUrls( $urls );
        }
    }

    public function commentEdited( $id ): void {
        $comment = get_comment( (int) $id );
        if ( $comment instanceof \WP_Comment ) {
            $this->commentSaved( (int) $id, $comment );
        }
    }

    public function commentStatusChanged( $new, $old, $comment ): void {
        if ( $comment instanceof \WP_Comment && $new !== $old && in_array( 'approved', [ $new, $old ], true ) ) {
            $this->publicCommentChanged( $comment );
        }
    }

    public function commentSaved( $id, $comment ): void {
        if ( $comment instanceof \WP_Comment && (string) $comment->comment_approved === '1' ) {
            $this->publicCommentChanged( $comment );
        }
    }

    private function publicCommentChanged( \WP_Comment $comment ): void {
        $post = get_post( (int) $comment->comment_post_ID );
        if ( $post instanceof \WP_Post && $this->publicPost( $post ) ) {
            $this->postChanged( $post );
        }
    }

    public function optionChanged( $option ): void {
        if ( is_string( $option ) && ( preg_match( '/^(?:theme_mods_|widget_|trp_|ys_trp_|ys_tp_|ys_ec_page_|woocommerce_(?:cart|checkout|myaccount|shop)_page_id$)/', $option ) ||
            in_array( $option, self::APPEARANCE_OPTIONS, true ) ) ) {
            $this->mark();
        }
    }

    /** Woo Discount Rules 的規則存在自己的資料表；管理員透過 wdr_ajax 儲存、切換、刪除、複製或匯入規則時清除全站。 */
    public function discountRulesRequest(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- 只決定是否清除快取，規則異動由 Woo Discount Rules 自行驗證。
        $action = $_POST['action'] ?? '';
        $method = $_POST['method'] ?? '';
        // phpcs:enable
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && $action === 'wdr_ajax' && is_string( $method )
            && preg_match( self::DISCOUNT_RULE_METHODS, $method ) && current_user_can( 'manage_woocommerce' ) ) {
            $this->mark();
        }
    }
}
