<?php
/** Compatibility rules for storefront, account, translation, and form plugins. */

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheIntegrations {

	public const YS_CART_PAGES = [ 'cart', 'checkout', 'thankyou', 'dashboard', 'login', 'register', 'order_lookup', 'coupon_center' ];
	/** 不影響頁面內容的回應 cookie：命中時本來就不會送出，寫入共用快取時可以略過。 */
	public const IGNORABLE_RESPONSE_COOKIES = [ 'woocommerce_recently_viewed', 'select_prod_list', 'productlist' ];
	/** Meta 進階比對會帶入的個人資料欄位。 */
	/** 真正的個人資料欄位。external_id 是 PixelYourSite 以 cookie 產生的識別碼，瀏覽器端會換成訪客自己的值，不在此列。 */
	private const PERSONAL_MATCHING_KEYS = [ 'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'db', 'ge', 'country' ];
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

	/**
	 * 這次請求不能共用快取。
	 *
	 * @param bool $cacheFirst 提前快取寫入端（先快取）：頁面上的表單不再讓整頁略過；
	 *                         這次渲染才建立的 WooCommerce 工作階段改在輸出時判斷（購物車是空的就照常共用）。
	 */
	public static function bypass( bool $localContactForm = false, bool $fragments = false, bool $cacheFirst = false ): bool {
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
			if ( ! $cacheFirst && is_object( $session ) && is_callable( [ $session, 'has_session' ] ) && $session->has_session() ) {
				return true;
			}
		}

		$post = get_queried_object();
		if ( ! $cacheFirst && $post instanceof \WP_Post && self::formContentRequiresBypass( $post->post_content, $localContactForm, $fragments ) ) {
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
			'PixelYourSite' => defined( 'PYS_VERSION' ) && function_exists( 'PixelYourSite\\PYS' ),
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
	public static function formContentRequiresBypass( string $content, bool $localContactForm = false, bool $fragments = false ): bool {
		if ( ! str_contains( $content, '[' ) && ! str_contains( $content, '<!--' ) ) {
			return false;
		}

		$seen = false;
		foreach ( self::FORM_PLUGINS as $slug => $plugin ) {
			if ( ! defined( $plugin['version'] ) ) {
				continue;
			}

			$pattern = '~(?<!\[)\[(?:' . $plugin['shortcodes'] . ')(?=[\s/\]])'
				. '|<!--\s+wp:(?:' . $plugin['blocks'] . ')(?=[\s/])~';
			if ( preg_match( $pattern, $content ) === 1 ) {
				if ( ! $localContactForm || $seen ) {
					return true;
				}
				$seen = true;
				if ( $slug === 'contact-form-7' ) {
					if ( ! self::supportsPublicContactForms() ) {
						return true;
					}
				} elseif ( ! $fragments || ! YSCachePublicForms::available( $slug === 'jetformbuilder' ? 'jet_fb_form' : $slug ) ||
					( $slug === 'fluentform' && preg_match( '~(?<!\[)\[fluentform_(?:payments|payment_view)(?=[\s/\]])~', $content ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function hasRenderedDynamicForm( string $html, bool $localContactForm ): bool {
		$form_classes = [];
		$form_fields = [];
		foreach ( self::FORM_PLUGINS as $slug => $plugin ) {
			if ( ! defined( $plugin['version'] ) || ( $localContactForm && $slug === 'contact-form-7' ) ) {
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

	public static function supportsPublicContactForms(): bool {
		if ( ! defined( 'WPCF7_VERSION' ) || ! is_callable( [ '\WPCF7_ContactForm', 'get_instance' ] ) ||
			preg_match( '/\A6\.2(?:\.[0-9]+)?\z/', WPCF7_VERSION ) !== 1 ) {
			return false;
		}
		foreach ( [ '\WPCF7_RECAPTCHA', '\WPCF7_Turnstile' ] as $service ) {
			if ( is_callable( [ $service, 'get_instance' ] ) && $service::get_instance()->is_active() ) {
				return false;
			}
		}

		global $wp_filter;
		$trusted = [
			'wpcf7_form_elements' => [ 'wpcf7_turnstile_prepend_widget' ],
			'wpcf7_form_tag_data_option' => [ 'wpcf7_listo' ],
			'wpcf7_form_hidden_fields' => [ 'wpcf7_recaptcha_add_hidden_fields' ],
			'wpcf7_form_class_attr' => [ 'wpcf7_acceptance_form_class_attr' ],
			'wpcf7_form_enctype' => [ 'wpcf7_file_form_enctype_filter' ],
			'wpcf7_form_tag' => [], 'wpcf7_form_action_url' => [], 'wpcf7_form_additional_atts' => [],
			'wpcf7_form_id_attr' => [], 'wpcf7_form_name_attr' => [], 'wpcf7_form_title_attr' => [],
			'wpcf7_form_autocomplete' => [], 'wpcf7_form_response_output' => [],
			'wpcf7_contact_form_properties' => [], 'wpcf7_contact_form_property_form' => [], 'wpcf7_form_tag_date_option' => [],
		];
		foreach ( $trusted as $hook => $functions ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! in_array( $callback['function'], $functions, true ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	private static function hasPrivateContactForms( string $html ): bool {
		if ( stripos( $html, 'wpcf7' ) === false ) {
			return false;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		$hidden = null;
		$unitTag = '';
		$expected = [ '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpcf7_posted_data_hash' ];
		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
			$tag = $tags->get_tag();
			if ( $tags->has_class( 'wpcf7-subscribers-only' ) ) {
				return true;
			}
			if ( $tag === 'FORM' ) {
				if ( $tags->is_tag_closer() ) {
					if ( $hidden !== null && ! self::verifiedContactForm( $hidden, $expected, $unitTag ) ) {
						return true;
					}
					$hidden = null;
				} elseif ( $hidden !== null ) {
					return true;
				} elseif ( $tags->has_class( 'wpcf7-form' ) ) {
					$action = $tags->get_attribute( 'action' );
					$request = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
					if ( $tags->get_attribute( 'data-status' ) !== 'init' || $tags->get_attribute( 'method' ) !== 'post' ||
						! is_string( $action ) || ! str_starts_with( $action, $request . '#wpcf7-' ) ) {
						return true;
					}
					$hidden = [];
					$unitTag = substr( $action, strlen( $request ) + 1 );
				}
			} elseif ( $tag === 'INPUT' ) {
				$name = $tags->get_attribute( 'name' );
				$type = strtolower( (string) $tags->get_attribute( 'type' ) );
				if ( $hidden === null ) {
					if ( $name === '_wpcf7' ) {
						return true;
					}
				} elseif ( $type === 'hidden' ) {
					$value = $tags->get_attribute( 'value' );
					if ( ! in_array( $name, $expected, true ) || ! is_string( $value ) || isset( $hidden[ $name ] ) ) {
						return true;
					}
					$hidden[ $name ] = $value;
				} elseif ( in_array( $type, [ 'file', 'password' ], true ) ) {
					return true;
				}
			} elseif ( $hidden !== null && ( $tags->has_class( 'cf-turnstile' ) || $tags->has_class( 'wpcf7-turnstile' ) ||
				$tags->get_attribute( 'data-sitekey' ) !== null ) ) {
				return true;
			}
		}
		return $hidden !== null;
	}

	private static function verifiedContactForm( array $hidden, array $expected, string $unitTag ): bool {
		if ( count( $hidden ) !== count( $expected ) || $hidden['_wpcf7_version'] !== WPCF7_VERSION ||
			$hidden['_wpcf7_posted_data_hash'] !== '' || ! preg_match( '/\A[1-9][0-9]{0,10}\z/', $hidden['_wpcf7'] ) ||
			$hidden['_wpcf7_unit_tag'] !== $unitTag || ! preg_match( '/\Awpcf7-f' . $hidden['_wpcf7'] . '(?:-p[0-9]+)?-o[0-9]+\z/', $unitTag ) ) {
			return false;
		}
		$form = \WPCF7_ContactForm::get_instance( (int) $hidden['_wpcf7'] );
		if ( ! $form || $form->is_true( 'subscribers_only' ) || $form->in_demo_mode() ) {
			return false;
		}
		$fields = $form->scan_form_tags();
		foreach ( $fields as $field ) {
			if ( ! in_array( $field->basetype, [ 'text', 'email', 'url', 'tel', 'textarea', 'number', 'range', 'date', 'select', 'checkbox', 'radio', 'acceptance', 'submit' ], true ) ||
				$field->has_option( 'default' ) || $field->has_option( 'data' ) ) {
				return false;
			}
		}
		return $fields !== [];
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
		// PixelYourSite 把 AJAX 事件 nonce（pysOptions.ajax_event）印在共用 HTML 裡。
		$actions = [ -1, 'wp_rest', 'ajax-event-nonce' ];
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

	/**
	 * PixelYourSite 的輸出是否帶有某位訪客的資料（會員編號、進階比對個資、購買事件或 late event）。
	 *
	 * 快取和 WP Rocket 一樣照常共用含 PixelYourSite 的頁面，不看它的任何設定；寫入端只用乾淨請求渲染，
	 * 這些欄位對一般訪客永遠是空的。這裡只是最後一道保險：真的出現個資時不寫入。無法解析時視為沒有訪客資料。
	 */
	public static function pixelYourSiteVisitorData( string $html ): bool {
		if ( preg_match( '~\bid\s*=\s*["\']?pys_late_event\b~i', $html ) === 1 ) {
			return true;
		}
		if ( stripos( $html, 'pysOptions' ) === false ||
			! preg_match( '~<script\b[^>]*\bid\s*=\s*(?:"pys-js-extra"|\'pys-js-extra\'|pys-js-extra(?=[\s/>]))[^>]*>(.*?)</script\s*>~is', $html, $extra ) ||
			! preg_match( '~\bvar\s+pysOptions\s*=\s*(\{[^\r\n]*\})\s*;~', $extra[1], $json ) ) {
			return false;
		}
		$options = json_decode( $json[1], true );
		if ( ! is_array( $options ) ) {
			return false;
		}
		if ( array_key_exists( 'user_id', $options ) && ! in_array( $options['user_id'], [ '0', 0, '', null ], true ) ) {
			return true;
		}
		foreach ( $options as $pixel ) {
			if ( is_array( $pixel ) && ( self::hasPersonalMatching( $pixel['advancedMatching'] ?? null ) ||
				self::hasPersonalMatching( $pixel['advanced_matching'] ?? null ) ) ) {
				return true;
			}
		}
		$analytics = $options['tracking_analytics'] ?? [];
		$user = is_array( $analytics ) ? ( $analytics['userData'] ?? [] ) : [];
		if ( is_array( $user )
			? ( self::filled( $user['emails'] ?? null ) || self::filled( $user['phones'] ?? null ) || self::filled( $user['addresses'] ?? null ) )
			: self::filled( $user ) ) {
			return true;
		}
		foreach ( is_array( $options['staticEvents'] ?? null ) ? $options['staticEvents'] : [] as $pixel => $list ) {
			foreach ( [ $pixel, ...( is_array( $list ) ? array_keys( $list ) : [] ) ] as $name ) {
				if ( is_string( $name ) && stripos( $name, 'purchase' ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/** 進階比對資料（任一層）是否帶有非空的個人資料欄位。 */
	private static function hasPersonalMatching( $matching ): bool {
		if ( ! is_array( $matching ) ) {
			return self::filled( $matching );
		}
		foreach ( $matching as $key => $value ) {
			if ( ( in_array( strtolower( (string) $key ), self::PERSONAL_MATCHING_KEYS, true ) && self::filled( $value ) ) ||
				( is_array( $value ) && self::hasPersonalMatching( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function filled( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::filled( $item ) ) {
					return true;
				}
			}
			return false;
		}
		return $value !== null && $value !== '' && $value !== false;
	}

	/**
	 * 去掉可略過的 Set-Cookie 後的回應標頭；仍有其他 Set-Cookie 時回傳 null（不能寫入共用快取）。
	 *
	 * 可略過：$names 列出的名稱，以及刪除 cookie 的標頭（Max-Age 小於等於 0，或沒有 Max-Age 且 Expires 已過）。
	 */
	public static function withoutIgnorableCookies( array $headers, array $names = self::IGNORABLE_RESPONSE_COOKIES, ?int $now = null ): ?array {
		$now ??= time();
		$kept = [];
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) ) {
				return null;
			}
			if ( ! preg_match( '~\ASet-Cookie\s*:(.*)\z~is', $header, $cookie ) ) {
				$kept[] = $header;
				continue;
			}
			$attributes = explode( ';', $cookie[1] );
			$pair = explode( '=', (string) array_shift( $attributes ), 2 );
			$name = trim( $pair[0] );
			if ( count( $pair ) !== 2 || $name === '' ) {
				return null;
			}
			if ( in_array( $name, $names, true ) ) {
				continue;
			}
			$maxAge = null;
			$expires = null;
			foreach ( $attributes as $attribute ) {
				[ $key, $value ] = array_pad( explode( '=', $attribute, 2 ), 2, '' );
				$key = strtolower( trim( $key ) );
				if ( $key === 'max-age' ) {
					$maxAge = trim( $value );
				} elseif ( $key === 'expires' ) {
					$expires = trim( $value );
				}
			}
			$deleted = $maxAge !== null
				? preg_match( '~\A-?[0-9]{1,12}\z~', $maxAge ) === 1 && (int) $maxAge <= 0
				: $expires !== null && ( $time = strtotime( $expires ) ) !== false && $time < $now;
			if ( ! $deleted ) {
				return null;
			}
		}
		return $kept;
	}

	/**
	 * 先快取的回應標頭規則（提前快取寫入端）。
	 *
	 * Set-Cookie 一律不存入共用副本：觸發渲染的訪客照常收到，之後的訪客拿到的副本不帶任何 cookie。
	 * 只有代表這次渲染已經個人化的 cookie 才讓回應不寫入：
	 * - 名稱符合 $state 前綴（登入、購物車內容、幣別、語言、願望清單或設定的 cookie 排除），刪除 cookie 的標頭除外；
	 * - cookie 的值（12 個字元以上）出現在 HTML 裡，表示頁面帶著這位訪客的識別碼。
	 *
	 * @param array    $headers 回應標頭（headers_list() 格式）。
	 * @param string   $html    這次渲染的完整 HTML。
	 * @param string[] $state   代表已個人化的 cookie 名稱前綴。
	 * @return array|null 可共用的標頭（已移除 Set-Cookie）；不可共用時回傳 null。
	 */
	public static function shareableResponseHeaders( array $headers, string $html, array $state, ?int $now = null ): ?array {
		$now ??= time();
		$kept = [];
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) ) {
				return null;
			}
			if ( ! preg_match( '~\ASet-Cookie\s*:(.*)\z~is', $header, $cookie ) ) {
				$kept[] = $header;
				continue;
			}
			$attributes = explode( ';', $cookie[1] );
			$pair = explode( '=', (string) array_shift( $attributes ), 2 );
			$name = trim( $pair[0] );
			if ( count( $pair ) !== 2 || $name === '' ) {
				return null;
			}
			if ( self::deletesCookie( $attributes, $now ) ) {
				continue;
			}
			foreach ( $state as $prefix ) {
				if ( is_string( $prefix ) && $prefix !== '' && str_starts_with( $name, $prefix ) ) {
					return null;
				}
			}
			$value = trim( trim( $pair[1] ), '"' );
			foreach ( array_unique( [ $value, rawurldecode( $value ) ] ) as $candidate ) {
				if ( strlen( $candidate ) >= 12 && str_contains( $html, $candidate ) ) {
					return null;
				}
			}
		}
		return $kept;
	}

	/** Set-Cookie 的屬性表示刪除 cookie：Max-Age 小於等於 0，或沒有 Max-Age 且 Expires 已過。 */
	private static function deletesCookie( array $attributes, int $now ): bool {
		$maxAge = null;
		$expires = null;
		foreach ( $attributes as $attribute ) {
			[ $key, $value ] = array_pad( explode( '=', $attribute, 2 ), 2, '' );
			$key = strtolower( trim( $key ) );
			if ( $key === 'max-age' ) {
				$maxAge = trim( $value );
			} elseif ( $key === 'expires' ) {
				$expires = trim( $value );
			}
		}
		return $maxAge !== null
			? preg_match( '~\A-?[0-9]{1,12}\z~', $maxAge ) === 1 && (int) $maxAge <= 0
			: $expires !== null && ( $time = strtotime( $expires ) ) !== false && $time < $now;
	}

	/**
	 * 先快取的 HTML 規則（提前快取寫入端）：只有頁面帶著某位訪客的資料時才不共用。
	 *
	 * 表單、登入視窗與 WordPress 訪客 nonce 都照常快取：未登入訪客的 nonce 人人相同，
	 * 而提前快取的壽命上限是 nonce 有效期的一半，送出時仍然有效。WPForms 的防垃圾權杖依時間產生，也是共用的。
	 */
	public static function shareableHtml( string $html ): bool {
		return ! self::pixelYourSiteVisitorData( $html ) && preg_match( '/"guestToken"\s*:\s*"[^"]+/i', $html ) !== 1;
	}

	public static function cacheableHtml( string $html, bool $localContactForm = false ): bool {
		$localContactForm = $localContactForm && self::supportsPublicContactForms();
		if ( self::pixelYourSiteVisitorData( $html )
			|| preg_match( '/"guestToken"\s*:\s*"[^"]+/i', $html ) || self::hasRenderedDynamicForm( $html, $localContactForm ) ||
			( $localContactForm && self::hasPrivateContactForms( $html ) ) ) {
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
