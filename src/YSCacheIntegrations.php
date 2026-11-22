<?php
/** Compatibility rules for storefront, account, translation, and form plugins. */

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheIntegrations {

	public const YS_CART_PAGES = [ 'cart', 'checkout', 'thankyou', 'dashboard', 'login', 'register', 'order_lookup', 'coupon_center' ];
	private const WC_DYNAMIC_SHORTCODES = [ 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'woocommerce_order_tracking' ];
	private const WC_DYNAMIC_BLOCKS = [ 'woocommerce/cart', 'woocommerce/checkout', 'woocommerce/order-confirmation-status' ];
	private const FORM_PLUGINS = [
		'wpforms' => [
			'version'    => 'WPFORMS_VERSION',
			'label'      => 'WPForms',
			'shortcodes' => 'wpforms',
			'blocks'     => 'wpforms/(?:form-selector|divi-form-selector)',
			'form_class' => 'wpforms-form',
			'field'      => 'wpforms[id]',
		],
		'fluentform' => [
			'version'    => 'FLUENTFORM_VERSION',
			'label'      => 'Fluent Forms',
			'shortcodes' => 'fluentform|fluentform_payments|fluentform_payment_view',
			'blocks'     => 'fluentfom/guten-block',
			'form_class' => 'frm-fluent-form',
			'field'      => '_fluentformnonce',
		],
		'jetformbuilder' => [
			'version'    => 'JET_FORM_BUILDER_VERSION',
			'label'      => 'JetFormBuilder',
			'shortcodes' => 'jet_fb_form',
			'blocks'     => 'jet-forms/form-block',
			'form_class' => 'jet-form-builder',
			'field'      => '_jet_engine_booking_form_id',
		],
		'contact-form-7' => [
			'version'    => 'WPCF7_VERSION',
			'label'      => 'Contact Form 7',
			'shortcodes' => 'contact-form-7|contact-form',
			'blocks'     => 'contact-form-7/contact-form-selector',
			'form_class' => 'wpcf7-form',
			'field'      => '_wpcf7',
		],
	];

	public static function cookiePrefixes(): array {
		return [
			'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_',
			'wp_woocommerce_session_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash',
			'ys_ec_session', 'ys_ec_tks_', '__Host-ys_ec_coupon_handoff', 'trp_language',
		];
	}

	public static function isPublicCookie( string $name, $value ): bool {
		// YS TranslatePress Addons uses this pair only in JavaScript to remember dismissal.
		return $name === 'ys_tp_ald' && $value === '1'
			&& class_exists( '\YangSheep\TPAddons\Support\YSTPAddonsModules' );
	}

	public static function bypass(): bool {
		// YS CART localizes this header's value into storefront scripts during an HTML render.
		if ( ! empty( $_SERVER['HTTP_X_YS_GUEST_TOKEN'] )
			|| ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) || ! empty( $_SERVER['HTTP_CART_TOKEN'] ) ) {
			return true;
		}

		foreach ( [ 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ] as $cookie ) {
			if ( defined( $cookie ) && isset( $_COOKIE[ constant( $cookie ) ] ) ) {
				return true;
			}
		}

		if ( function_exists( 'WC' ) ) {
			if ( is_cart() || is_checkout() || is_account_page() || is_wc_endpoint_url() ) {
				return true;
			}
			$session = WC()->session;
			if ( is_object( $session ) && is_callable( [ $session, 'has_session' ] ) && $session->has_session() ) {
				return true;
			}
		}

		$post = get_queried_object();
		if ( $post instanceof \WP_Post && self::formContentRequiresBypass( $post->post_content ) ) {
			return true;
		}

		if ( defined( 'YS_ECOMMERCE_VERSION' ) ) {
			if ( get_query_var( 'ys_ec_checkout_partition', '' ) !== '' ) {
				return true;
			}
			if ( $post instanceof \WP_Post ) {
				foreach ( self::YS_CART_PAGES as $page ) {
					if ( (int) $post->ID === (int) get_option( 'ys_ec_page_' . $page . '_id', 0 )
						|| has_shortcode( $post->post_content, 'ys_ec_' . $page ) ) {
						return true;
					}
				}
			}
		}

		if ( $post instanceof \WP_Post && function_exists( 'WC' ) ) {
			foreach ( self::WC_DYNAMIC_SHORTCODES as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					return true;
				}
			}
			foreach ( self::WC_DYNAMIC_BLOCKS as $block ) {
				if ( has_block( $block, $post ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** Return the slugs and display names of loaded form plugins. */
	public static function formPlugins(): array {
		$active = [];
		foreach ( self::FORM_PLUGINS as $slug => $plugin ) {
			if ( defined( $plugin['version'] ) ) {
				$active[ $slug ] = $plugin['label'];
			}
		}
		return $active;
	}

	public static function activeModules(): array {
		$modules = self::formPlugins();
		foreach ( [
			'WooCommerce' => function_exists( 'WC' ),
			'YS CART' => defined( 'YS_ECOMMERCE_VERSION' ),
			'Blocksy' => function_exists( 'get_template' ) && get_template() === 'blocksy',
			'TranslatePress' => class_exists( '\TRP_Translate_Press' ),
			'YS TranslatePress Addons' => class_exists( '\YangSheep\TPAddons\Support\YSTPAddonsModules' ),
		] as $module => $active ) {
			if ( $active ) {
				$modules[] = $module;
			}
		}
		return array_values( $modules );
	}

	/** Check a post's own content without running shortcodes or fetching associated records. */
	public static function formContentRequiresBypass( string $content ): bool {
		if ( ! str_contains( $content, '[' ) && ! str_contains( $content, '<!--' ) ) {
			return false;
		}

		foreach ( self::FORM_PLUGINS as $plugin ) {
			if ( ! defined( $plugin['version'] ) ) {
				continue;
			}

			$pattern = '~(?<!\[)\[(?:' . $plugin['shortcodes'] . ')(?=[\s/\]])'
				. '|<!--\s+wp:(?:' . $plugin['blocks'] . ')(?=[\s/])~';
			if ( preg_match( $pattern, $content ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	private static function hasRenderedDynamicForm( string $html ): bool {
		$form_classes = [];
		$form_fields = [];
		foreach ( self::FORM_PLUGINS as $plugin ) {
			if ( ! defined( $plugin['version'] ) ) {
				continue;
			}

			if ( str_contains( $html, $plugin['form_class'] ) ) {
				$form_classes[] = $plugin['form_class'];
			}
			if ( str_contains( $html, $plugin['field'] ) ) {
				$form_fields[ $plugin['field'] ] = true;
			}
		}
		if ( ! $form_classes && ! $form_fields ) {
			return false;
		}

		// Confirm actual markup so mentions in JavaScript, CSS, or text do not disable caching.
		$tags = new \WP_HTML_Tag_Processor( $html );
		$fluent_conversational = defined( 'FLUENTFORM_VERSION' ) && str_contains( $html, 'ff_conv_app' );
		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			if ( 'FORM' === $tag ) {
				foreach ( $form_classes as $form_class ) {
					if ( $tags->has_class( $form_class ) ) {
						return true;
					}
				}
			} elseif ( 'DIV' === $tag && $fluent_conversational && $tags->has_class( 'ff_conv_app' )
				&& $tags->has_class( 'frm-fluent-form' ) ) {
				// Fluent Forms can mount the entire conversational form with JavaScript.
				return true;
			} elseif ( 'INPUT' === $tag && $form_fields && strtolower( (string) $tags->get_attribute( 'type' ) ) === 'hidden' ) {
				$name = $tags->get_attribute( 'name' );
				if ( is_string( $name ) && ( isset( $form_fields[ $name ] ) || (
					isset( $form_fields['_fluentformnonce'] ) && preg_match( '/\A_fluentform_[0-9]+_fluentformnonce\z/', $name ) === 1
				) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** A per-URL shared cache cannot distinguish language detection by browser or IP. */
	public static function allowsExternalHtml(): bool {
		return ! class_exists( '\TRP_Translate_Press' ) || (
			! self::usesTranslatePressAutomaticDetection() && ! self::usesYsAutomaticDetection()
		);
	}

	private static function usesTranslatePressAutomaticDetection(): bool {
		return class_exists( '\TRP_IN_Automatic_Language_Detection' ) || class_exists( '\TRP_Automatic_Language_Detection' );
	}

	private static function usesYsAutomaticDetection(): bool {
		return class_exists( '\YangSheep\TPAddons\Support\YSTPAddonsModules' )
			&& \YangSheep\TPAddons\Support\YSTPAddonsModules::is_enabled( 'auto_detect' );
	}

	/** Return a bounded variation for the URL's language and the Addons language suggestion. */
	public static function languageVariant(): ?string {
		if ( ! class_exists( '\TRP_Translate_Press' ) ) {
			return '';
		}
		if ( self::usesTranslatePressAutomaticDetection() ) {
			return null;
		}


		global $TRP_LANGUAGE;
		$settings = get_option( 'trp_settings', [] );
		$language = $TRP_LANGUAGE ?: ( $settings['default-language'] ?? '' );
		if ( ! is_string( $language ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $language ) ) {
			return null;
		}

		if ( ! self::usesYsAutomaticDetection() ) {
			return $language;
		}

		$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
		if ( ! is_string( $header ) || strlen( $header ) > 1024 ) {
			return null;
		}
		$published = $settings['publish-languages'] ?? [];
		if ( ! is_array( $published ) ) {
			return null;
		}
		$suggested = self::suggestedLanguage( sanitize_text_field( wp_unslash( $header ) ), $published );
		return $suggested === '' || $suggested === $language ? $language : $language . ':' . $suggested;
	}

	/** Match the YS Addons AutoDetect locale selection against configured languages only. */
	private static function suggestedLanguage( string $header, array $published ): string {
		$locales = [];
		$primary = [];
		foreach ( $published as $code ) {
			if ( is_string( $code ) && preg_match( '/\A[a-zA-Z0-9_-]{1,32}\z/', $code ) ) {
				$normalized = strtolower( $code );
				$locales[ $normalized ] ??= $code;
				$primary[ (string) strtok( $normalized, '_' ) ] ??= $code;
			}
		}

		$preferences = [];
		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			[ $code, $quality ] = array_pad( explode( ';q=', $part, 2 ), 2, '1' );
			$preferences[] = [ str_replace( '-', '_', strtolower( trim( $code ) ) ), (float) $quality ];
		}
		usort( $preferences, static fn ( array $a, array $b ): int => $b[1] <=> $a[1] );
		foreach ( $preferences as [ $code ] ) {
			$matched = $locales[ $code ] ?? $primary[ (string) strtok( $code, '_' ) ] ?? '';
			if ( '' !== $matched ) {
				return $matched;
			}
		}
		return '';
	}

	/** Rotate the guest HTML key before known storefront nonces expire. */
	public static function nonceVariant(): ?string {
		if ( self::hasCustomGuestNonceIdentity() ) {
			return null;
		}

		return implode( ':', array_map( 'wp_nonce_tick', self::nonceActions() ) );
	}

	/** Expire Nginx HTML before the earliest known nonce action enters the next tick. */
	public static function externalHtmlTtl( int $configuredSeconds ): int {
		$now = time();
		$ttl = max( 0, $configuredSeconds );
		foreach ( self::nonceActions() as $action ) {
			$lifetime = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
			if ( ! is_numeric( $lifetime ) || ! is_finite( (float) $lifetime ) || $lifetime <= 0 ) {
				return 0;
			}
			$half_lifetime = $lifetime / 2;
			if ( $half_lifetime <= 2 ) {
				return 0;
			}
			$remaining = ceil( $now / $half_lifetime ) * $half_lifetime - $now;
			// Allow two seconds for the response to reach FastCGI and for whole-second expiry.
			$ttl = (int) min( $ttl, max( 0, floor( $remaining - 2 ) ) );
		}
		return $ttl;
	}

	private static function nonceActions(): array {
		$actions = [ -1, 'wp_rest' ];
		if ( defined( 'YS_ECOMMERCE_VERSION' ) ) {
			$actions[] = 'ys_ec_auth';
			$actions[] = 'ys_ec_cart_nonce';
		}
		return $actions;
	}

	private static function hasCustomGuestNonceIdentity(): bool {
		global $wp_filter;
		foreach ( $wp_filter['nonce_user_logged_out']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$handler = $callback['function'];
				// Woo's stock callback only scopes Woo nonces when a session exists.
				if ( ! is_array( $handler ) || ! is_object( $handler[0] )
					|| get_class( $handler[0] ) !== 'WC_Session_Handler' || 'maybe_update_nonce_user_logged_out' !== $handler[1] ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function cacheableHtml( string $html ): bool {
		if ( preg_match( '/"guestToken"\s*:\s*"[^"]+/i', $html ) || self::hasRenderedDynamicForm( $html ) ) {
			return false;
		}

		// Modern Blocksy forms use dynamic AJAX. Keep injected third-party form tokens out of shared HTML.
		$account_markup = '/(?:<template\b[^>]*\bid=[\'"]ct-account-modal-template[\'"][^>]*>.*?<\/template>|'
			. '<form\b[^>]*\bid=[\'"](?:loginform|registerform|lostpasswordform)[\'"][^>]*>.*?<\/form>)/is';
		if ( preg_match_all( $account_markup, $html, $matches ) ) {
			foreach ( $matches[0] as $form ) {
				if ( preg_match( '/(?:\bname\s*=\s*[\'"][^\'"\s>]*(?:nonce|token)[^\'"\s>]*[\'"]|\bdata-[\w-]*(?:nonce|token)\s*=)/i', $form ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
