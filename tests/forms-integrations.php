<?php

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

use YangSheep\Cache\YSCacheIntegrations;

$includes = rtrim( $argv[1] ?? '', '/\\' ) . '/wp-includes/';
if ( ! is_file( $includes . 'html-api/class-wp-html-tag-processor.php' ) ) {
    fwrite( STDERR, "Usage: php tests/forms-integrations.php <WordPress 6.4+ directory>\n" );
    exit( 1 );
}

define( 'ABSPATH', dirname( $includes ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
foreach ( [
    'class-wp-token-map.php',
    'utf8.php',
    'html-api/html5-named-character-references.php',
    'html-api/class-wp-html-attribute-token.php',
    'html-api/class-wp-html-span.php',
    'html-api/class-wp-html-doctype-info.php',
    'html-api/class-wp-html-decoder.php',
    'html-api/class-wp-html-tag-processor.php',
] as $file ) {
    if ( is_file( $includes . $file ) ) {
        require_once $includes . $file;
    }
}

require dirname( __DIR__ ) . '/src/YSCacheIntegrations.php';

final class WP_Post {
    public function __construct( public string $post_content ) {}
}

final class TestTranslatePress {}

final class TestTranslatePressAutomaticDetection {}

final class TestTranslatePressModuleSettings {
    public static bool $autoDetect = false;

    public static function is_enabled( string $module ): bool {
        return 'auto_detect' === $module && self::$autoDetect;
    }
}

function get_queried_object(): ?WP_Post {
    return $GLOBALS['queried_post'] ?? null;
}

function get_option( string $option, $default ) {
    return $option === 'trp_settings' ? [ 'default-language' => 'en_US', 'publish-languages' => [ 'en_US', 'de_DE' ] ] : $default;
}

function sanitize_text_field( string $text ): string {
    return trim( $text );
}

function wp_unslash( string $text ): string {
    return stripslashes( $text );
}

function apply_filters( string $hook, $value, $context ) {
    return $hook === 'nonce_life' ? ( $GLOBALS['nonce_lifetimes'][ $context ] ?? $value ) : $value;
}

function wp_nonce_tick( $action = -1 ): float {
    return ceil( time() / ( apply_filters( 'nonce_life', DAY_IN_SECONDS, $action ) / 2 ) );
}

function expect_same( $expected, $actual, string $description ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $description . ' returned ' . var_export( $actual, true ) );
    }
}

expect_same( [], YSCacheIntegrations::formPlugins(), 'No form plugins are loaded' );
expect_same( false, YSCacheIntegrations::formContentRequiresBypass( '[wpforms id="10"]' ), 'Inactive shortcode' );
expect_same( true, YSCacheIntegrations::cacheableHtml( '<form class="wpforms-form"></form>' ), 'Inactive form markup' );

define( 'WPFORMS_VERSION', '2.0.5.1' );
define( 'FLUENTFORM_VERSION', '6.2.17' );
define( 'JET_FORM_BUILDER_VERSION', '3.6.8.2' );
define( 'WPCF7_VERSION', '6.2.5' );

expect_same(
    [ 'wpforms' => 'WPForms', 'fluentform' => 'Fluent Forms', 'jetformbuilder' => 'JetFormBuilder', 'contact-form-7' => 'Contact Form 7' ],
    YSCacheIntegrations::formPlugins(),
    'All supported plugins are detected'
);

foreach ( [
    '[wpforms id="10"]',
    '<!-- wp:wpforms/form-selector {"formId":"10"} /-->',
    '[fluentform id="7"]',
    '<!-- wp:fluentfom/guten-block {"formId":"7"} /-->',
    '[fluentform_payment_view]',
    '[jet_fb_form form_id="4" /]',
    '<!-- wp:jet-forms/form-block {"form_id":4} /-->',
    '[contact-form id="8"]',
    '[contact-form-7 id="8"]',
    '<!-- wp:contact-form-7/contact-form-selector {"id":8} /-->',
] as $content ) {
    $GLOBALS['queried_post'] = new WP_Post( $content );
    expect_same( true, YSCacheIntegrations::bypass(), 'Direct form embed ' . $content );
}

foreach ( [
    'A page with no form.',
    '[[wpforms id="10"]]',
    '[wpforms_related id="10"]',
    '<!-- wp:contact-form-7/contact-form-selector-demo /-->',
] as $content ) {
    $GLOBALS['queried_post'] = new WP_Post( $content );
    expect_same( false, YSCacheIntegrations::bypass(), 'Ordinary content ' . $content );
}

foreach ( [
    '<footer><form class="wpforms-validate wpforms-form" /></footer>',
    '<form class="custom"><input type="hidden" name="wpforms[id]" value="10"></form>',
    '<form class="frm-fluent-form fluent_form_7"></form>',
    '<form><input TYPE=HIDDEN name=_fluentform_7_fluentformnonce></form>',
    '<div class="frm-fluent-form ff_conv_app ff_conv_app_frame"></div>',
    '<form class="jet-form-builder layout-column submit-type-ajax"></form>',
    '<form><input type="hidden" name="_jet_engine_booking_form_id" value="4"></form>',
    '<FORM CLASS=\'wpcf7-form init\'></FORM>',
    '<form><input name="_wpcf7" type="hidden" value="8"></form>',
] as $html ) {
    expect_same( false, YSCacheIntegrations::cacheableHtml( $html ), 'Rendered form ' . $html );
}

foreach ( [
    '<form class="search-form"><input type="search" name="s"></form>',
    '<form class="wpforms-form-styled"><input type="text" name="_wpcf7"></form>',
    '<div class="frm-fluent-form">Plain container</div>',
    '<p>CSS class wpforms-form</p><style>.wpcf7-form{display:none}</style>',
    '<!-- <form class="jet-form-builder"></form> -->',
    '<script>const example = \'<form class="wpcf7-form"><input name="_wpcf7" type="hidden"></form>\';</script>',
] as $html ) {
    expect_same( true, YSCacheIntegrations::cacheableHtml( $html ), 'Public markup ' . $html );
}

expect_same( true, YSCacheIntegrations::allowsExternalHtml(), 'No TranslatePress installation' );
class_alias( TestTranslatePress::class, 'TRP_Translate_Press' );
class_alias( TestTranslatePressModuleSettings::class, 'YangSheep\TPAddons\Support\YSTPAddonsModules' );
expect_same( true, YSCacheIntegrations::allowsExternalHtml(), 'Languages selected by URL' );
expect_same( 'en_US', YSCacheIntegrations::languageVariant(), 'Base language variant' );

TestTranslatePressModuleSettings::$autoDetect = true;
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,en-US;q=0.8';
expect_same( false, YSCacheIntegrations::allowsExternalHtml(), 'YS automatic language suggestion' );
expect_same( 'en_US:de_DE', YSCacheIntegrations::languageVariant(), 'YS HTML cache varies by suggestion' );

TestTranslatePressModuleSettings::$autoDetect = false;
class_alias( TestTranslatePressAutomaticDetection::class, 'TRP_IN_Automatic_Language_Detection' );
expect_same( false, YSCacheIntegrations::allowsExternalHtml(), 'TranslatePress paid automatic language detection' );
expect_same( null, YSCacheIntegrations::languageVariant(), 'IP-based suggestions cannot share the local HTML key' );

$GLOBALS['nonce_lifetimes'] = [ -1 => PHP_INT_MAX, 'wp_rest' => PHP_INT_MAX ];
expect_same( 90, YSCacheIntegrations::externalHtmlTtl( 90 ), 'The requested expiry is never increased' );
expect_same( 0, YSCacheIntegrations::externalHtmlTtl( -1 ), 'An invalid configured expiry stays private' );

$GLOBALS['nonce_lifetimes']['wp_rest'] = 4;
expect_same( 0, YSCacheIntegrations::externalHtmlTtl( 90 ), 'An expiring REST nonce prevents caching' );
$GLOBALS['nonce_lifetimes']['wp_rest'] = 24;
$ttl = YSCacheIntegrations::externalHtmlTtl( 90 );
expect_same( true, $ttl >= 0 && $ttl <= 9, 'The REST nonce filter caps FastCGI TTL' );

define( 'YS_ECOMMERCE_VERSION', '3.0.1' );
$GLOBALS['nonce_lifetimes']['ys_ec_cart_nonce'] = 4;
expect_same( 0, YSCacheIntegrations::externalHtmlTtl( 90 ), 'The YS CART nonce filter caps FastCGI TTL' );
expect_same( 4, count( explode( ':', YSCacheIntegrations::nonceVariant() ) ), 'Local and delegated cache track the same nonce actions' );

echo "Form, language, and nonce integrations passed.\n";
