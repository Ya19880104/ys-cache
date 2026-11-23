<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/** A local HTML entry can omit a reviewed form and its localized configuration. */
final class YSCachePublicForms {
    private const RECORD = "ys-public-form/2\n";
    private const CONTACT_RECORD = "ys-public-cf7/1\n";
    private const FORM = '<!--ys-cache-private-form-->';
    private const DATA = '/*ys-cache-private-form-config*/';
    private const VERSIONS = [
        'wpforms' => [ 'WPFORMS_VERSION', '2.0.5.1' ],
        'fluentform' => [ 'FLUENTFORM_VERSION', '6.2.17' ],
        'jet_fb_form' => [ 'JET_FORM_BUILDER_VERSION', '3.6.8.2' ],
    ];
    private const HANDLES = [
        'fluentform' => 'fluent-form-submission',
        'jet_fb_form' => 'jet-form-builder-frontend-forms',
    ];
    private static array $captured = [];
    private static ?array $enqueueStart = null;
    private static bool $enqueuePrinted = false;
    private static bool $nativeContent = false;

    /** Only the installed native renderer and its reviewed frontend hooks may supply a fragment. */
    private static function nativeRenderer( string $tag ): bool {
        global $shortcode_tags, $wp_filter;
        if ( ! self::reviewedContentFilters() ) {
            return false;
        }
        $roots = [ 'wpforms' => 'WPFORMS_PLUGIN_DIR', 'fluentform' => 'FLUENTFORM_DIR_PATH', 'jet_fb_form' => 'JET_FORM_BUILDER_PATH' ];
        $patterns = [
            'wpforms' => '~^wpforms_(?:frontend_|display_|field_properties|render_)~',
            'fluentform' => '~^fluentform[/_](?:render|form_vars|form_settings|form_fields|load_form_instance)~',
            'jet_fb_form' => '~^jet-form-builder/(?:form-render|render|field-template|frontend|fields/)~',
        ];
        $root = defined( $roots[ $tag ] ) ? realpath( constant( $roots[ $tag ] ) ) : false;
        if ( ! $root || empty( $shortcode_tags[ $tag ] ) ) {
            return false;
        }
        $callbacks = [ $shortcode_tags[ $tag ] ];
        foreach ( $wp_filter as $name => $hook ) {
            if ( ! preg_match( $patterns[ $tag ], $name ) ) {
                continue;
            }
            foreach ( $hook->callbacks ?? [] as $group ) {
                foreach ( $group as $entry ) {
                    if ( $tag === 'wpforms' && $entry['function'] === '__return_true' && preg_match(
                        '~^wpforms_frontend_modern_is_field_requires_fieldset_(?:payment-checkbox|payment-multiple|radio|checkbox)$~D', $name
                    ) ) {
                        continue;
                    }
                    $callbacks[] = $entry['function'];
                }
            }
        }
        foreach ( $callbacks as $callback ) {
            try {
                if ( is_array( $callback ) ) {
                    $reflection = new \ReflectionMethod( $callback[0], $callback[1] );
                } elseif ( is_object( $callback ) && ! $callback instanceof \Closure ) {
                    $reflection = new \ReflectionMethod( $callback, '__invoke' );
                } else {
                    $reflection = new \ReflectionFunction( $callback );
                }
                $file = $reflection->getFileName();
            } catch ( \Throwable $error ) {
                return false;
            }
            if ( ! $file || ! str_starts_with( wp_normalize_path( $file ), wp_normalize_path( $root ) . '/' ) ) {
                return false;
            }
        }
        return true;
    }

    /** A custom content callback can print per-visitor content outside the native form. */
    private static function reviewedContentFilters(): bool {
        global $wp_filter;
        $core = [ 'apply_block_hooks_to_content_from_post_object', 'do_blocks', 'wptexturize', 'wpautop',
            'shortcode_unautop', 'prepend_attachment', 'wp_replace_insecure_home_url', 'capital_P_dangit',
            'do_shortcode', 'wp_filter_content_tags', 'convert_smilies' ];
        $methods = [
            'WP_Embed' => [ 'run_shortcode', 'autoembed' ],
            'WPForms\Frontend\EmbedContext\Hosts' => [ 'start_content', 'end_content' ],
            'JFB_Modules\Onboarding\Preview' => [ 'render_form_preview' ],
            'TRP_Translation_Render' => [ 'wrap_with_post_id' ],
        ];
        foreach ( $wp_filter['the_content']->callbacks ?? [] as $group ) {
            foreach ( $group as $entry ) {
                $callback = $entry['function'];
                if ( $callback === [ self::class, 'reviewContent' ] || ( is_string( $callback ) && in_array( $callback, $core, true ) ) ) {
                    continue;
                }
                if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ||
                    ! in_array( $callback[1], $methods[ get_class( $callback[0] ) ] ?? [], true ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function available( string $tag ): bool {
        $spec = self::VERSIONS[ $tag ] ?? null;
        if ( ! $spec || ! defined( $spec[0] ) || constant( $spec[0] ) !== $spec[1] || ! shortcode_exists( $tag ) ) {
            return false;
        }
        if ( $tag === 'wpforms' && function_exists( 'wpforms' ) && wpforms()->is_pro() ) {
            return false;
        }
        return $tag !== 'fluentform' || ! defined( 'FLUENTFORMPRO' );
    }

    public static function fingerprint(): string {
        $versions = [];
        foreach ( self::VERSIONS as $tag => $spec ) {
            if ( self::available( $tag ) ) {
                $versions[] = $tag . '/' . $spec[1];
            }
        }
        if ( YSCacheIntegrations::supportsPublicContactForms() ) {
            $versions[] = 'cf7/' . WPCF7_VERSION;
        }
        return $versions ? 'local-form/2:' . implode( ',', $versions ) : '';
    }

    public static function capture(): void {
        self::$captured = [];
        self::$nativeContent = false;
        self::$enqueueStart = null;
        self::$enqueuePrinted = did_action( 'wp_enqueue_scripts' ) > 0;
        add_filter( 'do_shortcode_tag', [ self::class, 'remember' ], PHP_INT_MAX, 3 );
        add_filter( 'the_content', [ self::class, 'reviewContent' ], PHP_INT_MAX );
        add_action( 'wp_enqueue_scripts', [ self::class, 'observeEnqueueStart' ], PHP_INT_MIN );
        add_action( 'wp_enqueue_scripts', [ self::class, 'observeEnqueueEnd' ], PHP_INT_MAX );
    }

    public static function observeEnqueueStart(): void {
        self::$enqueueStart = [ ob_get_level(), ob_get_length() ];
    }

    public static function observeEnqueueEnd(): void {
        self::$enqueuePrinted = self::$enqueuePrinted || self::$enqueueStart !== [ ob_get_level(), ob_get_length() ];
    }

    private static function ownsShortcode( string $tag, int $id ): bool {
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post || ! is_singular() ||
            ! preg_match_all( '/' . get_shortcode_regex( [ $tag ] ) . '/s', $post->post_content, $matches, PREG_SET_ORDER ) ||
            count( $matches ) !== 1 || $matches[0][1] !== '' || $matches[0][6] !== '' ) {
            return false;
        }
        $attrs = shortcode_parse_atts( $matches[0][3] );
        if ( $attrs !== [ ( $tag === 'jet_fb_form' ? 'form_id' : 'id' ) => (string) $id ] ) {
            return false;
        }
        return self::sourceShortcode( $post->post_content ) === $matches[0][0];
    }

    private static function sourceShortcode( string $content ): string {
        $content = trim( $content );
        if ( preg_match( '~\A<!--\s+wp:shortcode\s+-->\s*(.*?)\s*<!--\s+/wp:shortcode\s+-->\z~sD', $content, $block ) ) {
            return trim( $block[1] );
        }
        return $content;
    }

    public static function dedicatedContactContent( string $content ): bool {
        return preg_match( '~\A\[(?:contact-form-7|contact-form)(?=[\s/\]])[^\[\]<>]*\]\z~D', self::sourceShortcode( $content ) ) === 1;
    }

    public static function reviewContent( string $output ): string {
        if ( ! in_the_loop() || get_the_ID() !== get_queried_object_id() || count( self::$captured ) !== 1 ) {
            return $output;
        }
        $form = self::$captured[0];
        $content = trim( $output );
        self::$nativeContent = $content === trim( $form['output'] ) || ( $form['tag'] === 'wpforms' &&
            $content === trim( self::decodeSpinner( $form['output'] ) ?? '' ) );
        return $output;
    }

    public static function remember( string $output, string $tag, $attrs ): string {
        if ( ! is_array( $attrs ) || $output === '' || ! doing_filter( 'the_content' ) ||
            ! in_the_loop() || get_the_ID() !== get_queried_object_id() ) {
            return $output;
        }
        if ( in_array( $tag, [ 'contact-form-7', 'contact-form' ], true ) ) {
            self::$captured[] = [ 'tag' => $tag, 'output' => $output ];
            return $output;
        }
        if ( ! self::available( $tag ) ) {
            return $output;
        }
        $idKey = $tag === 'jet_fb_form' ? 'form_id' : 'id';
        if ( array_diff( array_keys( $attrs ), [ $idKey ] ) || ! ctype_digit( (string) ( $attrs[ $idKey ] ?? '' ) ) ) {
            return $output;
        }
        self::$captured[] = [ 'tag' => $tag, 'id' => (int) $attrs[ $idKey ], 'output' => $output ];
        return $output;
    }

    /** Return a storage-only record. The current visitor always receives the original native HTML. */
    public static function pack( string $html ): ?string {
        global $wp_filter;
        $final = $wp_filter['the_content']->callbacks[PHP_INT_MAX] ?? [];
        $last = end( $final );
        $nativeContent = self::$nativeContent && ( $last['function'] ?? null ) === [ self::class, 'reviewContent' ];
        remove_filter( 'do_shortcode_tag', [ self::class, 'remember' ], PHP_INT_MAX );
        remove_filter( 'the_content', [ self::class, 'reviewContent' ], PHP_INT_MAX );
        remove_action( 'wp_enqueue_scripts', [ self::class, 'observeEnqueueStart' ], PHP_INT_MIN );
        remove_action( 'wp_enqueue_scripts', [ self::class, 'observeEnqueueEnd' ], PHP_INT_MAX );
        if ( self::$enqueuePrinted || strlen( $html ) > 1048576 || str_contains( $html, self::FORM ) || str_contains( $html, self::DATA ) ) {
            return null;
        }
        $forms = self::$captured;
        if ( ! $nativeContent || count( $forms ) !== 1 ) {
            return null;
        }
        $form = $forms[0];
        if ( in_array( $form['tag'], [ 'contact-form-7', 'contact-form' ], true ) ) {
            return self::publicContactDocument( $html ) ? self::CONTACT_RECORD . $html : null;
        }
        if ( ! self::ownsShortcode( $form['tag'], $form['id'] ) || ! self::nativeRenderer( $form['tag'] ) ) {
            return null;
        }
        $digest = self::definition( $form['tag'], $form['id'] );
        $form['decodeSpinner'] = false;
        if ( $form['tag'] === 'wpforms' && substr_count( $html, $form['output'] ) === 0 ) {
            // Core can add asynchronous decoding to the submit spinner after the shortcode runs.
            $processed = self::decodeSpinner( $form['output'] );
            if ( $processed !== null && substr_count( $html, $processed ) === 1 ) {
                $form['decodeSpinner'] = true;
                $form['output'] = $processed;
            }
        }
        if ( $digest === null || substr_count( $html, $form['output'] ) !== 1 ||
            ! self::simpleMarkup( $form['output'], $form['tag'], $form['id'] ) ) {
            return null;
        }
        $shell = str_replace( $form['output'], self::FORM, $html );
        $shell = self::stripConfig( $shell, $form['tag'] );
        if ( $shell === null || ! YSCacheIntegrations::cacheableHtml( $shell ) ) {
            return null;
        }
        unset( $form['output'] );
        return self::RECORD . wp_json_encode( [ $form, $digest, $shell ], JSON_UNESCAPED_SLASHES );
    }

    /** Render fresh vendor fields, nonce, timestamp, referrer, and frontend configuration for this request. */
    public static function unpack( string $data ): ?string {
        if ( str_starts_with( $data, self::CONTACT_RECORD ) ) {
            $document = substr( $data, strlen( self::CONTACT_RECORD ) );
            return self::publicContactDocument( $document ) && self::prepareScripts() ? $document : null;
        }
        if ( ! str_starts_with( $data, self::RECORD ) ) {
            return null;
        }
        $record = json_decode( substr( $data, strlen( self::RECORD ) ), true );
        if ( ! is_array( $record ) || count( $record ) !== 3 ) {
            return null;
        }
        [ $form, $digest, $shell ] = $record;
        $tag = $form['tag'] ?? '';
        $id = $form['id'] ?? 0;
        if ( ! is_string( $tag ) || ! is_int( $id ) || ! self::ownsShortcode( $tag, $id ) ||
            ! is_string( $digest ) || ! is_string( $shell ) || ! self::available( $tag ) || ! self::nativeRenderer( $tag ) ||
            self::definition( $tag, $id ) !== $digest || substr_count( $shell, self::FORM ) !== 1 ||
            substr_count( $shell, self::DATA ) !== 1 ) {
            return null;
        }
        if ( ! self::prepareScripts() ) {
            return null;
        }
        ob_start();
        try {
            $rendered = do_shortcode( '[' . $tag . ' ' . ( $tag === 'jet_fb_form' ? 'form_id' : 'id' ) . '="' . $id . '"]' );
        } finally {
            $unexpected = ob_get_clean();
        }
        if ( trim( (string) $unexpected ) !== '' || ! self::simpleMarkup( $rendered, $tag, $id ) ) {
            return null;
        }
        if ( ! empty( $form['decodeSpinner'] ) ) {
            $rendered = self::decodeSpinner( $rendered );
            if ( $rendered === null ) {
                return null;
            }
        }
        $config = self::freshConfig( $tag );
        if ( $config === null || stripos( $config, '</script' ) !== false ) {
            return null;
        }
        return strtr( $shell, [ self::FORM => $rendered, self::DATA => $config ] );
    }

    private static function prepareScripts(): bool {
        if ( ! did_action( 'wp_enqueue_scripts' ) ) {
            ob_start();
            try {
                wp_enqueue_scripts();
            } finally {
                $printed = (string) ob_get_clean();
            }
            remove_action( 'wp_head', 'wp_enqueue_scripts', 1 );
            if ( $printed !== '' ) {
                // These bytes belong to this visitor. Replay them once where WordPress normally runs the enqueue action.
                add_action( 'wp_head', static function () use ( $printed ): void {
                    echo $printed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }, 1 );
                return false;
            }
        }
        return true;
    }

    private static function publicContactDocument( string $html ): bool {
        if ( ! str_contains( $html, 'wpcf7-form' ) || ! YSCacheIntegrations::supportsPublicContactForms() ||
            ! preg_match( '~^\s*(?:<!doctype\s+html\s*>\s*)?<html(?:\s|>)~i', $html ) ||
            ! preg_match( '~</html\s*>\s*(?:<!--(?:(?!-->).)*-->\s*)*\z~is', substr( $html, -8192 ) ) ) {
            return false;
        }
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post || ! self::dedicatedContactContent( $post->post_content ) || ! self::reviewedContentFilters() ) {
            return false;
        }
        $tags = new \WP_HTML_Tag_Processor( $html );
        return $tags->next_tag( [ 'tag_name' => 'FORM', 'class_name' => 'wpcf7-form' ] ) &&
            YSCacheIntegrations::cacheableHtml( $html, true );
    }

    private static function decodeSpinner( string $markup ): ?string {
        $tags = new \WP_HTML_Tag_Processor( $markup );
        if ( ! $tags->next_tag( [ 'tag_name' => 'IMG', 'class_name' => 'wpforms-submit-spinner' ] ) ||
            $tags->get_attribute( 'decoding' ) !== null ) {
            return null;
        }
        $tags->set_attribute( 'decoding', 'async' );
        return $tags->get_updated_html();
    }

    private static function stripConfig( string $html, string $tag ): ?string {
        $config = self::freshConfig( $tag );
        if ( ! is_string( $config ) || $config === '' || stripos( $config, '</script' ) !== false ||
            substr_count( $html, $config ) !== 1 ) {
            return null;
        }
        $needle = $tag === 'wpforms' ? '<script type=\'text/javascript\'>' : '<script id="' . self::HANDLES[ $tag ] . '-js-extra">';
        $offset = strpos( $html, $config );
        $start = strrpos( substr( $html, 0, $offset ), '<script' );
        if ( $start === false || substr( $html, $start, strlen( $needle ) ) !== $needle ||
            str_contains( substr( $html, $start, $offset - $start ), '</script' ) ) {
            return null;
        }
        return substr_replace( $html, self::DATA, $offset, strlen( $config ) );
    }

    private static function freshConfig( string $tag ): ?string {
        if ( $tag === 'wpforms' ) {
            $renderer = function_exists( 'wpforms' ) ? wpforms()->frontend : null;
            if ( ! is_callable( [ $renderer, 'get_strings' ] ) ) {
                return null;
            }
            $json = wp_json_encode( $renderer->get_strings() );
            return is_string( $json ) ? 'var wpforms_settings = ' . $json : null;
        }
        $data = wp_scripts()->get_data( self::HANDLES[ $tag ], 'data' );
        return is_string( $data ) && $data !== '' ? $data : null;
    }

    private static function simpleMarkup( string $markup, string $tag, int $id ): bool {
        $classes = [ 'wpforms' => 'wpforms-form', 'fluentform' => 'frm-fluent-form', 'jet_fb_form' => 'jet-form-builder' ];
        $ids = [ 'wpforms' => 'data-formid', 'fluentform' => 'data-form_id', 'jet_fb_form' => 'data-form-id' ];
        $hidden = [
            'wpforms' => [ 'wpforms[id]', 'wpforms[time_token]', 'page_title', 'page_url', 'url_referer', 'page_id', 'wpforms[post_id]' ],
            'fluentform' => [ '__fluent_form_embded_post_id', '_fluentform_' . $id . '_fluentformnonce', '_wp_http_referer' ],
            'jet_fb_form' => [ '_wpnonce', '_wp_http_referer', '_jfb_current_render_states[]', '_jet_engine_booking_form_id',
                '_jet_engine_refer', '__queried_post_id', '__queried_post_signature' ],
        ];
        if ( preg_match( '~(?:g-recaptcha|h-captcha|cf-turnstile|data-sitekey)~i', $markup ) ) {
            return false;
        }
        if ( preg_match_all( '~<textarea\b[^<>]*>(.*?)</textarea\s*>~is', $markup, $textareas ) ) {
            foreach ( $textareas[1] as $value ) {
                if ( trim( $value ) !== '' ) {
                    return false;
                }
            }
        }
        if ( $tag === 'fluentform' && preg_match(
            '~<script type="text/javascript">\s*window\.fluent_form_(ff_form_instance_' . $id . '_[1-9][0-9]*) = (\{[^<>]*\});\s*</script>~s',
            $markup, $script
        ) ) {
            $config = json_decode( $script[2], true );
            if ( ! is_array( $config ) || (int) ( $config['id'] ?? 0 ) !== $id ||
                ( $config['form_instance'] ?? '' ) !== $script[1] || ! empty( $config['conditionals'] ) ) {
                return false;
            }
            $markup = str_replace( $script[0], '', $markup );
        }
        $formCount = 0;
        $tags = new \WP_HTML_Tag_Processor( $markup );
        while ( $tags->next_tag() ) {
            $name = $tags->get_tag();
            if ( $name === 'FORM' ) {
                if ( ! $tags->has_class( $classes[ $tag ] ) || (string) $tags->get_attribute( $ids[ $tag ] ) !== (string) $id ||
                    strtolower( (string) $tags->get_attribute( 'method' ) ) !== 'post' ) {
                    return false;
                }
                ++$formCount;
            } elseif ( $name === 'INPUT' ) {
                $kind = strtolower( (string) $tags->get_attribute( 'type' ) );
                if ( ! in_array( $kind, [ '', 'text', 'email', 'hidden', 'submit' ], true ) ||
                    ( $kind === 'hidden' && ! in_array( $tags->get_attribute( 'name' ), $hidden[ $tag ], true ) ) ||
                    ( in_array( $kind, [ '', 'text', 'email' ], true ) && (string) $tags->get_attribute( 'value' ) !== '' ) ) {
                    return false;
                }
            } elseif ( in_array( $name, [ 'IFRAME', 'SELECT', 'OBJECT', 'SCRIPT' ], true ) ||
                ( $name === 'TEXTAREA' && $tags->get_attribute( 'value' ) !== null ) ) {
                return false;
            }
        }
        return $formCount === 1;
    }

    private static function definition( string $tag, int $id ): ?string {
        if ( $id < 1 || ! is_singular() || get_queried_object_id() <= 0 || post_password_required() ) {
            return null;
        }
        if ( $tag === 'fluentform' ) {
            if ( ! class_exists( '\FluentForm\App\Models\Form' ) || ! class_exists( '\FluentForm\App\Models\FormMeta' ) ) {
                return null;
            }
            $row = \FluentForm\App\Models\Form::find( $id );
            if ( ! $row || $row->status !== 'published' || ! empty( $row->has_payment ) || ! in_array( $row->type, [ 'form', null, '' ], true ) ) {
                return null;
            }
            $fields = json_decode( (string) $row->form_fields, true )['fields'] ?? [];
            if ( ! $fields || array_diff( array_column( $fields, 'element' ), [ 'input_text', 'input_name', 'input_email', 'textarea' ] ) ) {
                return null;
            }
            $metadata = \FluentForm\App\Models\FormMeta::where( 'form_id', $id )->orderBy( 'id' )->get()->toArray();
            return hash( 'sha256', serialize( [ $row->toArray(), $metadata ] ) );
        }
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '' ) {
            return null;
        }
        if ( $tag === 'wpforms' && $post->post_type === 'wpforms' ) {
            $def = json_decode( $post->post_content, true );
            $fields = $def['fields'] ?? [];
            if ( ! $fields || array_diff( array_column( $fields, 'type' ), [ 'text', 'name', 'email', 'textarea' ] ) ||
                array_intersect( array_keys( $def ), [ 'payments', 'user_registration', 'form_locker', 'save_resume' ] ) ) {
                return null;
            }
            foreach ( $fields as $field ) {
                if ( ! empty( $field['default_value'] ) || ! empty( $field['conditional_logic'] ) ) {
                    return null;
                }
            }
        } elseif ( $tag === 'jet_fb_form' && $post->post_type === 'jet-form-builder' ) {
            $blocks = parse_blocks( $post->post_content );
            foreach ( $blocks as $block ) {
                if ( $block['blockName'] === null && trim( $block['innerHTML'] ) === '' ) {
                    continue;
                }
                if ( ! in_array( $block['blockName'], [ 'jet-forms/text-field', 'jet-forms/textarea-field', 'jet-forms/submit-field' ], true ) ||
                    ! empty( $block['innerBlocks'] ) || array_diff( array_keys( $block['attrs'] ), [ 'name', 'required', 'label', 'placeholder' ] ) ) {
                    return null;
                }
            }
            $actions = json_decode( (string) get_post_meta( $id, '_jf_actions', true ), true );
            if ( ! is_array( $actions ) || ( $actions && ( count( $actions ) !== 1 || array_column( $actions, 'type' ) !== [ 'send_email' ] ) ) ) {
                return null;
            }
            $gateways = get_post_meta( $id, '_jf_gateways', true );
            if ( ! in_array( $gateways, [ '', '{}', '[]' ], true ) && json_decode( $gateways, true ) !== [ 'protect_price_field' => true ] ) {
                return null;
            }
            if ( ! in_array( get_post_meta( $id, '_jf_preset', true ), [ '', '{}', '[]' ], true ) ) {
                return null;
            }
        } else {
            return null;
        }
        $meta = get_post_meta( $id );
        if ( $tag === 'wpforms' ) {
            // Native usage statistics change on a successful submission without altering the form.
            unset( $meta['wpforms_entries_count'], $meta['wpforms_form_locations'] );
        }
        return hash( 'sha256', serialize( [ $post->post_content, $meta ] ) );
    }
}
