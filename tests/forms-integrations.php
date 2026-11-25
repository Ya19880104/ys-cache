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
require dirname( __DIR__ ) . '/src/YSCachePublicForms.php';

function shortcode_exists( string $tag ): bool {
    return in_array( $tag, [ 'wpforms', 'fluentform', 'jet_fb_form' ], true );
}

function remove_filter( ...$args ): bool { return false; }
function remove_action( ...$args ): bool { return false; }

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

final class WPCF7_ContactForm {
    public static string $option = '';
    public static string $type = 'text';
    public static bool $membersOnly = false;

    public static function get_instance( int $id ): ?self { return $id === 8 ? new self() : null; }
    public function is_true( string $name ): bool { return $name === 'subscribers_only' && self::$membersOnly; }
    public function in_demo_mode(): bool { return false; }
    public function scan_form_tags(): array {
        return [ new class( self::$type ) {
            public function __construct( public string $basetype ) {}
            public function has_option( string $name ): bool { return $name === WPCF7_ContactForm::$option; }
        } ];
    }
}

$_SERVER['REQUEST_URI'] = '/en/contact/';
$unitTag = 'wpcf7-f8-p12-o1';
$publicForm = '<form class="wpcf7-form init" method="post" action="/en/contact/#' . $unitTag . '" data-status="init">';
foreach ( [ '_wpcf7' => '8', '_wpcf7_version' => WPCF7_VERSION, '_wpcf7_locale' => 'en_US', '_wpcf7_unit_tag' => $unitTag,
    '_wpcf7_container_post' => '12', '_wpcf7_posted_data_hash' => '' ] as $name => $value ) {
    $publicForm .= '<input type="hidden" name="' . $name . '" value="' . $value . '">';
}
$publicForm .= '<input type="text" name="visitor-name"></form>';
foreach ( [
    'wpcf7_form_elements' => 'wpcf7_turnstile_prepend_widget',
    'wpcf7_form_tag_data_option' => 'wpcf7_listo',
    'wpcf7_form_hidden_fields' => 'wpcf7_recaptcha_add_hidden_fields',
    'wpcf7_form_class_attr' => 'wpcf7_acceptance_form_class_attr',
    'wpcf7_form_enctype' => 'wpcf7_file_form_enctype_filter',
] as $hook => $nativeCallback ) {
    $wp_filter[ $hook ] = (object) [ 'callbacks' => [ [ [ 'function' => $nativeCallback ] ] ] ];
}
expect_same( false, YSCacheIntegrations::cacheableHtml( $publicForm ), 'Public CF7 is opt-in' );
expect_same( true, YSCacheIntegrations::cacheableHtml( $publicForm, true ), 'Plain public CF7 with verified hidden fields' );
expect_same( false, YSCacheIntegrations::formContentRequiresBypass( '[contact-form-7 id="8"]', true ), 'Opt-in defers CF7 inspection until rendering' );
expect_same( true, YSCacheIntegrations::formContentRequiresBypass( '[contact-form-7 id="8"][jet_fb_form form_id="4"]', true ), 'Opt-in protects mixed plugin shortcodes' );
expect_same( true, YSCacheIntegrations::formContentRequiresBypass( '[fluentform_payment_view]', true ), 'Payment views stay private' );
expect_same( true, YSCacheIntegrations::formContentRequiresBypass( '[jet_fb_form form_id="4"]', true ), 'Existing CF7 opt-in does not enable other vendors' );
expect_same( false, YSCacheIntegrations::formContentRequiresBypass( '[jet_fb_form form_id="4"]', true, true ), 'Reviewed shortcode is inspected after rendering' );
$simpleMarkup = new ReflectionMethod( \YangSheep\Cache\YSCachePublicForms::class, 'simpleMarkup' );
foreach ( [
    '[contact-form-7 id="f02a" title="Contact"]' => true,
    '<!-- wp:shortcode -->[contact-form-7 id="8"]<!-- /wp:shortcode -->' => true,
    '[contact-form-7 id="8"][dynamic_message]' => false,
    '[contact-form-7 id="8"]<!-- wp:example/private /-->' => false,
    '<aside>[contact-form-7 id="8"]</aside>' => false,
] as $source => $eligible ) {
    expect_same( $eligible, \YangSheep\Cache\YSCachePublicForms::dedicatedContactContent( $source ), $source );
}
foreach ( [ "ys-public-form/1\n[]", "ys-public-form/99\n[]", "ys-public-form/2\n", 'ordinary text', '<html>wpcf7-form</html>',
    "ys-public-cf7/1\n<html>wpcf7-form</html>", "ys-public-cf7/1\n<html>" . $publicForm ] as $record ) {
    expect_same( null, \YangSheep\Cache\YSCachePublicForms::unpack( $record ), 'Reject malformed or unrecognized public form record' );
}
if ( PHP_VERSION_ID < 80100 ) {
    $simpleMarkup->setAccessible( true );
}
$wpForm = '<form method="post" class="wpforms-form" data-formid="5">%s</form>';
foreach ( [
    '<input type="text" name="email">' => true,
    '<textarea name="message"></textarea>' => true,
    '<textarea name="message">Private default</textarea>' => false,
    '<input type="text" value="customer">' => false,
    '<input type="hidden" name="custom_session">' => false,
    '<input type="file">' => false,
    '<div class="cf-turnstile"></div>' => false,
    '<script>customScript();</script>' => false,
] as $field => $expected ) {
    expect_same( $expected, $simpleMarkup->invoke( null, sprintf( $wpForm, $field ), 'wpforms', 5 ), $field );
}
ob_start();
\YangSheep\Cache\YSCachePublicForms::observeEnqueueStart();
echo '<meta name="test-visitor" content="native">';
\YangSheep\Cache\YSCachePublicForms::observeEnqueueEnd();
$nativeOutput = ob_get_clean();
expect_same( '<meta name="test-visitor" content="native">', $nativeOutput, 'Native enqueue output is preserved' );
expect_same( null, \YangSheep\Cache\YSCachePublicForms::pack( '<html>public</html>' ), 'Direct enqueue output prevents local storage' );
foreach ( [
    str_replace( 'data-status="init"', 'data-status="sent"', $publicForm ),
    str_replace( 'value=""', 'value="post-data"', $publicForm ),
    str_replace( '/en/contact/#', '/other/#', $publicForm ),
    str_replace( '</form>', '<input type="hidden" name="_wpnonce" value="nonce"></form>', $publicForm ),
    str_replace( '</form>', '<input type="hidden" name="_wpcf7_recaptcha_response" value=""></form>', $publicForm ),
    str_replace( '</form>', '<div class="cf-turnstile" data-sitekey="public"></div></form>', $publicForm ),
    substr( $publicForm, 0, -7 ),
    $publicForm . '<form class="wpforms-form"></form>',
    $publicForm . '<form class="frm-fluent-form"></form>',
    $publicForm . '<form class="jet-form-builder"></form>',
    '<p class="wpcf7-subscribers-only">Login required</p>',
] as $unsafeForm ) {
    expect_same( false, YSCacheIntegrations::cacheableHtml( $unsafeForm, true ), 'A private, incomplete or mixed form remains excluded' );
}
WPCF7_ContactForm::$option = 'default';
expect_same( false, YSCacheIntegrations::cacheableHtml( $publicForm, true ), 'Contextual default values bypass' );
WPCF7_ContactForm::$option = '';
WPCF7_ContactForm::$type = 'file';
expect_same( false, YSCacheIntegrations::cacheableHtml( $publicForm, true ), 'Unapproved field types bypass' );
WPCF7_ContactForm::$type = 'text';
WPCF7_ContactForm::$membersOnly = true;
expect_same( false, YSCacheIntegrations::cacheableHtml( $publicForm, true ), 'Members-only forms bypass' );
WPCF7_ContactForm::$membersOnly = false;
final class WPCF7_RECAPTCHA {
    public static bool $enabled = false;
    public static function get_instance(): self { return new self(); }
    public function is_active(): bool { return self::$enabled; }
}
WPCF7_RECAPTCHA::$enabled = true;
expect_same( false, YSCacheIntegrations::supportsPublicContactForms(), 'Enabling CAPTCHA also prevents existing cached pages from being read' );
WPCF7_RECAPTCHA::$enabled = false;
$wp_filter['wpcf7_form_elements'] = (object) [ 'callbacks' => [ [ [ 'function' => static fn ( $html ) => $html ] ] ] ];
expect_same( false, YSCacheIntegrations::cacheableHtml( $publicForm, true ), 'Unknown form output hooks bypass' );
unset( $wp_filter['wpcf7_form_elements'], $_SERVER['REQUEST_URI'] );

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

$GLOBALS['nonce_lifetimes'] = [ -1 => PHP_INT_MAX, 'wp_rest' => PHP_INT_MAX, 'ajax-event-nonce' => PHP_INT_MAX ];
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
expect_same( 5, count( explode( ':', YSCacheIntegrations::nonceVariant() ) ), 'Local and delegated cache track the same nonce actions, including the PixelYourSite AJAX event nonce' );
$GLOBALS['nonce_lifetimes']['ys_ec_cart_nonce'] = PHP_INT_MAX;
$GLOBALS['nonce_lifetimes']['ys_ec_auth'] = PHP_INT_MAX;
$GLOBALS['nonce_lifetimes']['ajax-event-nonce'] = 4;
expect_same( 0, YSCacheIntegrations::externalHtmlTtl( 90 ), 'The PixelYourSite AJAX event nonce filter caps shared HTML TTL' );

echo "Form, language, and nonce integrations passed.\n";
