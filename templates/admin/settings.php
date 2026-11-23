<?php

defined( 'ABSPATH' ) || exit;

$enhance = $providers['enhance'] ?? [];
$opcache = $providers['opcache'] ?? [];
$opcacheReady = \YangSheep\Cache\YSCacheProviders::opcacheAvailable();
$storefrontReady = get_template() === 'blocksy' && defined( 'PYS_VERSION' ) &&
    defined( 'WP_CACHE' ) && WP_CACHE && ! is_multisite();
$opcacheUnavailable = __( 'This PHP runtime must have OPcache enabled and allow WordPress to reset it.', 'ys-cache' );
$nginxSettings = $providers['nginx'] ?? [];
$nginxReady = ! empty( $nginx['available'] );
$nginxSupportsAll = $nginxReady && ! empty( $nginx['supports_all'] );
$nginxMessage = $nginx['message'] ?? __( 'A verified Nginx purge connection is required.', 'ys-cache' );
$nginxEnableToPurge = __( 'Enable and save the Nginx integration before purging.', 'ys-cache' );
$nginxPurgeReady = $nginxSupportsAll && ! empty( $nginxSettings['enabled'] );
$nginxPurgeReason = $nginxSupportsAll ? $nginxEnableToPurge : $nginxMessage;
$liteSpeedSettings = $providers['litespeed'] ?? [];
$liteSpeedReady = ! empty( $liteSpeed['available'] );
$liteSpeedPurgeReady = $liteSpeedReady && ! empty( $liteSpeedSettings['enabled'] );
$liteSpeedEnableToPurge = __( 'Enable and save the LiteSpeed Cache integration before purging.', 'ys-cache' );
$cloudConnectionNeeded = __( 'Save the YS CLOUD connection with its control panel URL, domain ID, and API token.', 'ys-cache' );
$cloudEnableToPurge = __( 'Enable and save the YS CLOUD integration before purging.', 'ys-cache' );
$cloudPurgeReady = $hasEnhanceConnection && ! empty( $enhance['enabled'] );
$cdnRows = $providers['cdn'] ?? [];
$cdnPurgeReady = (bool) array_filter( $cdnRows, [ \YangSheep\Cache\YSCacheProviders::class, 'cdnPurgeReady' ] );
$cdnPurgeUnavailable = __( 'Save a CDN layer with API purging enabled, a resource ID, and a valid credential configuration.', 'ys-cache' );
$localPurgeReady = \YangSheep\Cache\YSCache::usesLocalHtml( $settings );
$fullPurgeReady = $localPurgeReady || $nginxPurgeReady || $liteSpeedPurgeReady || $cloudPurgeReady || $cdnPurgeReady
    || ( $opcacheReady && ! empty( $opcache['enabled'] ) );
$assetSettings = $settings['assets'] ?? [];
$credentialMissing = __( 'Not configured', 'ys-cache' );
$credentialSaved = __( 'Saved. Leave blank to keep it.', 'ys-cache' );
$ttlUnits = [ 's' => [ 'S', 1 ], 'min' => [ 'MIN', 60 ], 'hr' => [ 'HR', 3600 ], 'day' => [ 'DAY', 86400 ] ];
$ttlUnit = $settings['ttl_unit'] ?? 's';
if ( ! is_string( $ttlUnit ) || ! isset( $ttlUnits[ $ttlUnit ] ) ) {
    $ttlUnit = 's';
}
$ttlFactor = $ttlUnits[ $ttlUnit ][1];
$ttlValue = rtrim( rtrim( sprintf( '%.16F', $settings['ttl_seconds'] / $ttlFactor ), '0' ), '.' );
$sections = [
    'settings' => [ __( 'Cache settings', 'ys-cache' ), 'admin-settings', [
        'html' => __( 'HTML cache', 'ys-cache' ),
        'exclusions' => __( 'Exclusions', 'ys-cache' ),
        'opcache' => 'OPcache',
    ] ],
    'optimization' => [ __( 'Asset optimization', 'ys-cache' ), 'performance', [
        'css' => 'CSS',
        'javascript' => 'JavaScript',
    ] ],
    'integrations' => [ __( 'Integrations', 'ys-cache' ), 'admin-network', [
        'detection' => __( 'Automatic detection', 'ys-cache' ),
        'cdn' => __( 'CDN layers', 'ys-cache' ),
        'ys-cloud' => __( 'YS CLOUD Integration', 'ys-cache' ),
        'nginx' => __( 'Nginx Cache (Beta)', 'ys-cache' ),
        'litespeed' => __( 'LiteSpeed Cache (Beta)', 'ys-cache' ),
    ] ],
    'help' => [ __( 'Help', 'ys-cache' ), 'book-alt', [
        'connections' => __( 'Connection setup', 'ys-cache' ),
        'exclusion-guide' => __( 'Exclusion guide', 'ys-cache' ),
        'import' => __( 'Automatic import', 'ys-cache' ),
    ] ],
];
$renderRow = static function ( array $row, ?int $index = null ) use ( $credentialMissing, $credentialSaved ): void {
    $type = $row['provider'] ?? 'cloudflare';
    $scope = $row['scope'] ?? ( ( $row['role'] ?? '' ) === 'images' || $type === 'cname' ? 'static' : 'all' );
    $purgePolicy = \YangSheep\Cache\YSCacheProviders::autoPurgePolicy( $row );
    $hasCredential = $index !== null && \YangSheep\Cache\YSCacheProviders::cdnHasCredential( $row );
    $lookupNoteId = $index === null ? '' : 'ys-cache-cdn-lookup-note-' . $index;
    ?>
    <fieldset class="ys-cache-cdn" data-cdn-row data-saved-index="<?php echo esc_attr( $index === null ? '' : (string) $index ); ?>" data-has-credential="<?php echo $hasCredential ? '1' : '0'; ?>">
        <legend><?php esc_html_e( 'CDN layers', 'ys-cache' ); ?></legend>
        <button type="button" class="ys-cache-cdn-remove" data-cdn-remove aria-label="<?php esc_attr_e( 'Remove CDN layer', 'ys-cache' ); ?>" title="<?php esc_attr_e( 'Remove CDN layer', 'ys-cache' ); ?>">
            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
        </button>
        <div class="ys-cache-grid">
            <label><?php esc_html_e( 'Provider', 'ys-cache' ); ?>
                <select data-key="provider">
                    <?php foreach ( [ 'cloudflare' => 'Cloudflare', 'gcore' => 'Gcore', 'bunny' => 'Bunny CDN', 'cname' => 'CNAME' ] as $id => $name ) : ?>
                        <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $type ); ?>><?php echo esc_html( $name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'CDN delivery scope', 'ys-cache' ); ?>
                <select data-key="scope">
                    <option value="all" <?php selected( 'all', $scope ); ?>><?php esc_html_e( 'Entire site', 'ys-cache' ); ?></option>
                    <option value="html" <?php selected( 'html', $scope ); ?>><?php esc_html_e( 'HTML only', 'ys-cache' ); ?></option>
                    <option value="code" <?php selected( 'code', $scope ); ?>><?php esc_html_e( 'JavaScript and CSS', 'ys-cache' ); ?></option>
                    <option value="static" <?php selected( 'static', $scope ); ?>><?php esc_html_e( 'Static assets (JavaScript, CSS, images, and more)', 'ys-cache' ); ?></option>
                    <option value="html_code" <?php selected( 'html_code', $scope ); ?>><?php esc_html_e( 'HTML, JavaScript, and CSS', 'ys-cache' ); ?></option>
                </select>
            </label>
            <label><?php esc_html_e( 'Hostname', 'ys-cache' ); ?>
                <input type="text" data-key="hostname" value="<?php echo esc_attr( $row['hostname'] ?? '' ); ?>" placeholder="cdn.example.com">
            </label>
            <label data-api-only><?php esc_html_e( 'Zone / Resource ID', 'ys-cache' ); ?>
                <input type="text" data-key="zone_id" value="<?php echo esc_attr( $row['zone_id'] ?? '' ); ?>">
            </label>
            <label data-api-only><?php esc_html_e( 'API Token / Key', 'ys-cache' ); ?>
                <input type="password" data-key="token" autocomplete="new-password" placeholder="<?php echo esc_attr( $hasCredential ? $credentialSaved : $credentialMissing ); ?>">
            </label>
            <label data-api-only data-cloudflare-only><?php esc_html_e( 'Cloudflare account email (Global API Key)', 'ys-cache' ); ?>
                <input type="email" data-key="auth_email" value="<?php echo esc_attr( $row['auth_email'] ?? '' ); ?>">
            </label>
            <label data-api-only><?php esc_html_e( 'Automatic purge policy', 'ys-cache' ); ?>
                <select data-key="auto_purge">
                    <option value="inherit" <?php selected( $purgePolicy, 'inherit' ); ?>><?php esc_html_e( 'Follow site settings', 'ys-cache' ); ?></option>
                    <option value="off" <?php selected( $purgePolicy, 'off' ); ?>><?php esc_html_e( 'Manual only', 'ys-cache' ); ?></option>
                </select>
            </label>
        </div>
        <div class="ys-cache-options">
            <label data-api-only><input type="checkbox" data-key="enabled" <?php checked( ! empty( $row['enabled'] ) ); ?>> <?php esc_html_e( 'Enable API purging', 'ys-cache' ); ?></label>
            <label data-html-only><input type="checkbox" data-key="html_cache_verified" <?php checked( ! empty( $row['html_cache_verified'] ) ); ?>> <?php esc_html_e( 'I verified the CDN bypass rules for stateful requests.', 'ys-cache' ); ?></label>
            <label data-rewrite-option><input type="checkbox" data-key="rewrite_urls" <?php checked( ! empty( $row['rewrite_urls'] ) ); ?>> <?php esc_html_e( 'Rewrite public asset URLs to this CDN hostname', 'ys-cache' ); ?></label>
            <?php if ( ! empty( $row['rewrite_images'] ) ) : ?>
                <label data-rewrite-option><input type="checkbox" data-key="rewrite_images" checked> <?php esc_html_e( 'Keep legacy Media Library image rewriting', 'ys-cache' ); ?></label>
            <?php endif; ?>
            <label data-api-only data-cloudflare-only data-full-zone-only><input type="checkbox" data-key="purge_entire_zone" <?php checked( ! empty( $row['purge_entire_zone'] ) ); ?>> <?php esc_html_e( 'Purge the entire Cloudflare zone', 'ys-cache' ); ?></label>
            <label data-api-only><input type="checkbox" data-key="clear_token"> <?php esc_html_e( 'Remove stored credential', 'ys-cache' ); ?></label>
        </div>
        <div class="description" data-cdn-provider-notes>
            <p data-cdn-provider-note="cloudflare" hidden><?php esc_html_e( 'Look up an existing Cloudflare zone by its hostname, or enter the zone ID to verify it. Use a Cloudflare API token with access to that zone.', 'ys-cache' ); ?></p>
            <p data-cdn-provider-note="bunny" hidden><?php esc_html_e( 'Look up an existing Bunny CDN Pull Zone by its hostname, or enter the Pull Zone ID to verify it. Use your Bunny account API key.', 'ys-cache' ); ?></p>
            <p data-cdn-provider-note="gcore" hidden><?php esc_html_e( 'Look up an existing Gcore CDN resource by its hostname, or enter the resource ID to verify it. Use your Gcore API token.', 'ys-cache' ); ?></p>
            <p data-cdn-provider-note="cname" hidden><?php esc_html_e( 'CNAME mode rewrites eligible URLs to an existing CDN hostname that already works in DNS. Use a provider layer for API lookup and purging.', 'ys-cache' ); ?></p>
        </div>
        <div class="ys-cache-actions">
            <button type="button" class="button" data-cdn-discover data-api-only data-find="<?php esc_attr_e( 'Find resource ID', 'ys-cache' ); ?>" data-verify="<?php esc_attr_e( 'Verify resource', 'ys-cache' ); ?>" <?php if ( $lookupNoteId !== '' ) : ?>aria-describedby="<?php echo esc_attr( $lookupNoteId ); ?>"<?php endif; ?> disabled><?php esc_html_e( 'Find resource ID', 'ys-cache' ); ?></button>
            <button type="button" class="button" data-cdn-up><?php esc_html_e( 'Move up', 'ys-cache' ); ?></button>
            <button type="button" class="button" data-cdn-down><?php esc_html_e( 'Move down', 'ys-cache' ); ?></button>
        </div>
        <p class="description" <?php if ( $lookupNoteId !== '' ) : ?>id="<?php echo esc_attr( $lookupNoteId ); ?>"<?php endif; ?> data-cdn-lookup-note data-api-only hidden><?php esc_html_e( 'Enter a valid hostname or resource ID and an API credential to look up an existing resource. Asset-only layers also need a hostname.', 'ys-cache' ); ?></p>
        <p class="description" data-cdn-enable-note data-api-only hidden><?php esc_html_e( 'An existing resource ID and API credential are needed to enable purging. Asset-only layers also need a hostname.', 'ys-cache' ); ?></p>
        <p data-cdn-status class="description" aria-live="polite"></p>
    </fieldset>
    <?php
};
?>
<div class="wrap ys-cache-admin" id="ys-cache-admin"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'ys_cache_admin' ) ); ?>"
    data-error="<?php esc_attr_e( 'The request failed. Try again.', 'ys-cache' ); ?>"
    data-confirm-cdn-removal="<?php esc_attr_e( 'Remove this CDN layer? The change will apply when you save your settings.', 'ys-cache' ); ?>"
    data-credential-missing="<?php echo esc_attr( $credentialMissing ); ?>"
    data-credential-saved="<?php echo esc_attr( $credentialSaved ); ?>"
    data-has-enhance="<?php echo $hasEnhanceConnection ? '1' : '0'; ?>"
    data-enhance-enabled="<?php echo ! empty( $enhance['enabled'] ) ? '1' : '0'; ?>"
    data-nginx-enabled="<?php echo ! empty( $nginxSettings['enabled'] ) ? '1' : '0'; ?>"
    data-nginx-supports-all="<?php echo $nginxSupportsAll ? '1' : '0'; ?>"
    data-litespeed-enabled="<?php echo ! empty( $liteSpeedSettings['enabled'] ) ? '1' : '0'; ?>"
    data-litespeed-available="<?php echo $liteSpeedReady ? '1' : '0'; ?>"
    data-opcache-available="<?php echo $opcacheReady ? '1' : '0'; ?>"
    data-opcache-enabled="<?php echo ! empty( $opcache['enabled'] ) ? '1' : '0'; ?>"
    data-local-enabled="<?php echo $localPurgeReady ? '1' : '0'; ?>"
    data-cdn-purge-ready="<?php echo $cdnPurgeReady ? '1' : '0'; ?>"
    data-max-layers="<?php echo (int) \YangSheep\Cache\YSCacheProviders::MAX_CDN_LAYERS; ?>">
    <header class="ys-cache-header">
        <h1><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Cache Management', 'ys-cache' ); ?></h1>
        <p><?php esc_html_e( 'Manage HTML caching, exclusions, and purge integrations for this site.', 'ys-cache' ); ?></p>
    </header>
    <div class="ys-cache-layout">
    <aside class="ys-cache-sidebar" aria-labelledby="ys-cache-purge-heading">
        <h2 id="ys-cache-purge-heading"><?php esc_html_e( 'Purge cache', 'ys-cache' ); ?></h2>
        <div class="ys-cache-purge-actions">
            <?php foreach ( [
                'all' => [ __( 'Purge entire site', 'ys-cache' ), $fullPurgeReady, __( 'Enable and save at least one available cache layer for a whole-site purge.', 'ys-cache' ) ],
                'local' => [ __( 'Purge HTML cache', 'ys-cache' ), $localPurgeReady, __( 'Enable and save local HTML caching to clear it here.', 'ys-cache' ) ],
                'nginx' => [ __( 'Purge Nginx cache', 'ys-cache' ), $nginxPurgeReady, $nginxPurgeReason ],
                'cdn' => [ __( 'Purge CDN', 'ys-cache' ), $cdnPurgeReady, $cdnPurgeUnavailable ],
                'opcache' => [ __( 'Reset OPcache', 'ys-cache' ), $opcacheReady, $opcacheUnavailable ],
            ] as $scope => [ $label, $available, $reason ] ) : ?>
                <div class="ys-cache-purge-item">
                    <button type="button" class="button<?php echo $scope === 'all' ? ' ys-cache-purge-main' : ''; ?>" data-purge="<?php echo esc_attr( $scope ); ?>" <?php disabled( ! $available ); ?>
                        aria-describedby="ys-cache-purge-reason-<?php echo esc_attr( $scope ); ?>"><?php echo esc_html( $label ); ?></button>
                    <p id="ys-cache-purge-reason-<?php echo esc_attr( $scope ); ?>" class="description" data-purge-reason="<?php echo esc_attr( $scope ); ?>" <?php echo $available ? 'hidden' : ''; ?>><?php echo esc_html( $reason ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ys-cache-status" data-purge-status role="status" aria-live="polite"></div>
        <ul class="ys-cache-results ys-cache-purge-results" aria-live="polite"></ul>
    </aside>
    <div class="ys-cache-main">
    <div class="ys-cache-status" data-settings-status role="status" aria-live="polite"></div>
    <?php if ( '' !== $blocking ) : ?>
        <p class="notice notice-warning"><?php esc_html_e( 'Another page cache is active. Disable it before enabling YS CACHE.', 'ys-cache' ); ?></p>
    <?php endif; ?>
    <?php if ( ! $storageReady ) : ?>
        <p class="notice notice-error"><?php esc_html_e( 'The HTML cache directory is not writable. Set YS_CACHE_STORAGE_ROOT to a directory outside the site root.', 'ys-cache' ); ?></p>
    <?php endif; ?>

    <div class="ys-cache-primary-tabs" role="group" aria-label="<?php esc_attr_e( 'Settings sections', 'ys-cache' ); ?>">
        <?php foreach ( $sections as $key => [ $label, $icon ] ) : ?>
            <button type="button" data-tab-section="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo $key === 'settings' ? 'true' : 'false'; ?>">
                <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $label ); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php foreach ( $sections as $key => [ $label, , $tabs ] ) : ?>
        <div class="ys-cache-secondary-tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>" data-tab-group="<?php echo esc_attr( $key ); ?>" <?php echo $key !== 'settings' ? 'hidden' : ''; ?>>
            <?php foreach ( $tabs as $tab => $title ) : ?>
                <button type="button" role="tab" id="ys-cache-tab-<?php echo esc_attr( $tab ); ?>" data-tab="<?php echo esc_attr( $tab ); ?>"
                    aria-selected="<?php echo $tab === 'html' ? 'true' : 'false'; ?>" aria-controls="ys-cache-panel-<?php echo esc_attr( $tab ); ?>" tabindex="<?php echo $tab === 'html' ? '0' : '-1'; ?>"><?php echo esc_html( $title ); ?></button>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <form id="ys-cache-settings" class="ys-cache-form">
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="html" id="ys-cache-panel-html" aria-labelledby="ys-cache-tab-html">
            <h2><?php esc_html_e( 'HTML cache', 'ys-cache' ); ?></h2>
            <?php
            $owner = $delegated ? 'external' : ( ! empty( $nginxSettings['enabled'] ) ? 'nginx' : ( ! empty( $liteSpeedSettings['enabled'] ) ? 'litespeed' : ( ! empty( $settings['enabled'] ) ? 'local' : 'disabled' ) ) );
            $ownership = [
                'external' => __( 'YS CLOUD manages the HTML cache.', 'ys-cache' ),
                'nginx' => __( 'Nginx manages the HTML cache.', 'ys-cache' ),
                'litespeed' => __( 'LiteSpeed Cache manages the HTML cache.', 'ys-cache' ),
                'local' => __( 'YS CACHE manages the HTML cache.', 'ys-cache' ),
                'disabled' => __( 'HTML caching is disabled.', 'ys-cache' ),
            ];
            ?>
            <p data-cache-owner data-external="<?php echo esc_attr( $ownership['external'] ); ?>" data-nginx="<?php echo esc_attr( $ownership['nginx'] ); ?>" data-litespeed="<?php echo esc_attr( $ownership['litespeed'] ); ?>" data-local="<?php echo esc_attr( $ownership['local'] ); ?>" data-disabled="<?php echo esc_attr( $ownership['disabled'] ); ?>"><?php echo esc_html( $ownership[ $owner ] ); ?></p>
            <label class="ys-cache-switch">
                <input name="enabled" type="checkbox" role="switch" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Enable guest HTML caching when using YS CACHE', 'ys-cache' ); ?>
            </label>
            <div class="ys-cache-lifetime" role="group" aria-labelledby="ys-cache-lifetime-label">
                <label id="ys-cache-lifetime-label" for="ys-cache-lifetime"><?php esc_html_e( 'Cache lifetime', 'ys-cache' ); ?></label>
                <div class="ys-cache-lifetime-fields">
                    <input id="ys-cache-lifetime" name="ttl_value" type="number" min="0" max="<?php echo esc_attr( (string) intdiv( 604800, $ttlFactor ) ); ?>" step="any"
                        value="<?php echo esc_attr( $ttlValue ); ?>" aria-describedby="ys-cache-lifetime-help"
                        data-invalid="<?php esc_attr_e( 'Enter a lifetime of 1 second to 7 days that converts to whole seconds.', 'ys-cache' ); ?>" required>
                    <select name="ttl_unit" aria-label="<?php esc_attr_e( 'Time unit', 'ys-cache' ); ?>">
                        <?php foreach ( $ttlUnits as $value => [ $label, $seconds ] ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" data-seconds="<?php echo (int) $seconds; ?>" <?php selected( $value, $ttlUnit ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="description" id="ys-cache-lifetime-help"><?php esc_html_e( 'Choose a lifetime from 1 second through 7 days. Cached HTML can expire sooner when a guest security token is due to change.', 'ys-cache' ); ?></p>
            <label for="ys-cache-early-paths"><?php esc_html_e( 'Early guest HTML cache paths', 'ys-cache' ); ?></label>
            <textarea id="ys-cache-early-paths" name="early_paths" rows="2" maxlength="12000" placeholder="/"><?php echo esc_textarea( $settings['early_paths'] ?? '' ); ?></textarea>
            <p class="description"><?php esc_html_e( 'Enter one exact public path per line. Eligible HTTPS requests can serve HTML before WordPress starts. Choose pages with the same public content for anonymous visitors. Requires WP_CACHE.', 'ys-cache' ); ?></p>
            <label class="ys-cache-switch">
                <input name="early_pixel_shell" type="checkbox" role="switch" <?php checked( ! empty( $settings['early_pixel_shell'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Load private PixelYourSite tracking separately on supported direct homepage visits (beta)', 'ys-cache' ); ?>
            </label>
            <label class="ys-cache-switch">
                <input name="guest_template" type="checkbox" role="switch" <?php checked( ! empty( $settings['guest_template'] ) ); ?> <?php disabled( ! $storefrontReady ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Cache public Blocksy storefront content with PixelYourSite (beta)', 'ys-cache' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'On compatible guest visits, WordPress reuses the public page content and renders the header, footer, and tracking for the current visitor. Cart, checkout, and account pages stay private.', 'ys-cache' ); ?></p>
            <?php if ( ! $storefrontReady ) : ?>
                <p class="description"><?php esc_html_e( 'Requires Blocksy, PixelYourSite, and WP_CACHE on a single-site installation.', 'ys-cache' ); ?></p>
            <?php endif; ?>
            <h3><?php esc_html_e( 'Automatic purge', 'ys-cache' ); ?></h3>
            <label class="ys-cache-switch">
                <input name="auto_purge" type="checkbox" role="switch" <?php checked( ! empty( $settings['auto_purge'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Purge caches when published content changes', 'ys-cache' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Server and CDN clearing runs in the background. CDN layers set to Manual only follow manual purge actions.', 'ys-cache' ); ?></p>
            <?php if ( ! empty( $lastAutoPurge['time'] ) ) : ?>
                <?php
                $purgeState = [
                    'ok'      => __( 'Succeeded', 'ys-cache' ),
                    'queued'  => __( 'Queued', 'ys-cache' ),
                    'retry'   => __( 'Waiting to retry', 'ys-cache' ),
                    'error'   => __( 'Failed', 'ys-cache' ),
                    'skipped' => __( 'Skipped', 'ys-cache' ),
                ][ $lastAutoPurge['status'] ?? '' ] ?? '';
                ?>
                <p class="description"><?php
                    echo esc_html( sprintf(
                        /* translators: 1: purge status, 2: date and time. */
                        __( 'Last automatic purge: %1$s (%2$s)', 'ys-cache' ),
                        $purgeState,
                        wp_date( 'Y-m-d H:i:s', (int) $lastAutoPurge['time'] )
                    ) );
                ?></p>
                <?php if ( ! empty( $lastAutoPurge['next_attempt'] ) ) : ?>
                    <p class="description"><?php
                        /* translators: %s: scheduled date and time. */
                        echo esc_html( sprintf( __( 'Next background attempt: %s', 'ys-cache' ), wp_date( 'Y-m-d H:i:s', (int) $lastAutoPurge['next_attempt'] ) ) );
                    ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $lastAutoPurge['layers'] ) ) : ?>
                    <ul class="ys-cache-results">
                        <?php foreach ( $lastAutoPurge['layers'] as $result ) : ?>
                            <li data-status="<?php echo esc_attr( $result['status'] ); ?>"><?php echo esc_html( str_replace( [ 'Enhance Nginx', 'Enhance' ], 'YS CLOUD', $result['layer'] . ' — ' . $result['message'] ) ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="exclusions" id="ys-cache-panel-exclusions" aria-labelledby="ys-cache-tab-exclusions" hidden>
            <h2><?php esc_html_e( 'Exclusions', 'ys-cache' ); ?></h2>
            <?php if ( $forms ) : ?>
                <p class="description"><?php
                    echo esc_html( sprintf(
                        /* translators: %s: list of active form plugins. */
                        __( 'Detected form plugins (%s). Form pages are excluded by default.', 'ys-cache' ),
                        implode( wp_get_list_item_separator(), $forms )
                    ) );
                ?></p>
            <?php endif; ?>
            <h3><?php esc_html_e( 'Manual exclusions', 'ys-cache' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Enter one rule per line. Path rules support * wildcards. Cookie rules match name prefixes, and User-Agent rules match substrings.', 'ys-cache' ); ?></p>
            <p class="description"><?php
                printf(
                    /* translators: %s: example URL path. */
                    esc_html__( 'For a path rule, copy only the path from the public URL, such as %s. Keep the language prefix and any public subdirectory. Leave out the domain, query string, and fragment.', 'ys-cache' ),
                    '<code>/en/contact/</code>'
                );
            ?></p>
            <div class="ys-cache-grid">
                <?php foreach ( [ 'exclude_paths' => [ __( 'Paths', 'ys-cache' ), '/members/*' ], 'exclude_cookies' => [ 'Cookie', 'private_session_' ], 'exclude_agents' => [ 'User-Agent', 'ExampleBot' ] ] as $key => [ $label, $example ] ) : ?>
                    <label><?php echo esc_html( $label ); ?>
                        <textarea name="<?php echo esc_attr( $key ); ?>" rows="5" maxlength="12000" placeholder="<?php echo esc_attr( $example ); ?>"><?php echo esc_textarea( $settings[ $key ] ); ?></textarea>
                    </label>
                <?php endforeach; ?>
            </div>
            <h3><?php esc_html_e( 'Path lifetime and form caching', 'ys-cache' ); ?></h3>
            <div class="ys-cache-grid">
                <label><?php esc_html_e( 'Shorter HTML lifetime by path', 'ys-cache' ); ?>
                    <textarea name="ttl_paths" rows="4" maxlength="12000" placeholder="/news/* 300" aria-describedby="ys-cache-ttl-paths-help"><?php echo esc_textarea( $settings['ttl_paths'] ); ?></textarea>
                    <span class="description" id="ys-cache-ttl-paths-help"><?php esc_html_e( 'Enter a path and seconds per line. The shortest matching lifetime applies to eligible HTML.', 'ys-cache' ); ?></span>
                </label>
                <label><?php esc_html_e( 'Public form paths (Beta)', 'ys-cache' ); ?>
                    <textarea name="public_cf7_paths" rows="4" maxlength="12000" placeholder="/en/contact/" aria-describedby="ys-cache-cf7-help"><?php echo esc_textarea( $settings['public_cf7_paths'] ); ?></textarea>
                    <span class="description" id="ys-cache-cf7-help"><?php esc_html_e( 'Enter one public path per line, including each translation. Eligible pages use local HTML for up to five minutes.', 'ys-cache' ); ?></span>
                </label>
            </div>
            <label class="ys-cache-switch">
                <input type="checkbox" name="public_form_fragments" <?php checked( ! empty( $settings['public_form_fragments'] ) ); ?>>
                <span><?php esc_html_e( 'Also render supported WPForms Lite, Fluent Forms and JetFormBuilder forms per visitor on these paths', 'ys-cache' ); ?></span>
            </label>
            <p class="description"><?php esc_html_e( 'For a supported simple form, WPForms Lite, Fluent Forms, and JetFormBuilder render fresh fields for every visitor. Contact Form 7 can reuse eligible public markup. Keep private, prefilled, payment, and CAPTCHA forms excluded.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="opcache" id="ys-cache-panel-opcache" aria-labelledby="ys-cache-tab-opcache" hidden>
            <h2>OPcache</h2>
            <p class="description"><?php esc_html_e( 'OPcache stores compiled PHP on the origin server. Enable this option to reset it before a full-site purge.', 'ys-cache' ); ?></p>
            <label class="ys-cache-switch">
                <input type="checkbox" name="opcache_enabled" role="switch" aria-describedby="ys-cache-opcache-unavailable" <?php checked( ! empty( $opcache['enabled'] ) ); ?> <?php disabled( ! $opcacheReady && empty( $opcache['enabled'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Reset OPcache when purging all layers', 'ys-cache' ); ?>
            </label>
            <p id="ys-cache-opcache-unavailable" class="description" <?php echo $opcacheReady ? 'hidden' : ''; ?>><?php echo esc_html( $opcacheUnavailable ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="css" id="ys-cache-panel-css" aria-labelledby="ys-cache-tab-css" hidden>
            <h2><?php esc_html_e( 'CSS optimization', 'ys-cache' ); ?></h2>
            <label class="ys-cache-switch">
                <input name="css_minify" type="checkbox" role="switch" <?php checked( ! empty( $assetSettings['css_minify'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Minify CSS', 'ys-cache' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Reduce the size of eligible local stylesheets.', 'ys-cache' ); ?></p>
            <div class="ys-cache-grid ys-cache-asset-exclusions">
                <label><?php esc_html_e( 'CSS exclusions', 'ys-cache' ); ?>
                    <textarea name="css_excludes" rows="5" maxlength="12000" aria-describedby="ys-cache-css-exclusions-help"><?php echo esc_textarea( $assetSettings['css_excludes'] ?? '' ); ?></textarea>
                </label>
            </div>
            <p class="description" id="ys-cache-css-exclusions-help"><?php esc_html_e( 'Enter one asset handle or path per line. Use * as a wildcard in paths.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="javascript" id="ys-cache-panel-javascript" aria-labelledby="ys-cache-tab-javascript" hidden>
            <h2><?php esc_html_e( 'JavaScript optimization', 'ys-cache' ); ?></h2>
            <div class="ys-cache-switches">
                <label class="ys-cache-switch">
                    <input name="js_minify" type="checkbox" role="switch" <?php checked( ! empty( $assetSettings['js_minify'] ) ); ?>>
                    <span class="ys-cache-switch-track" aria-hidden="true"></span>
                    <?php esc_html_e( 'Minify JavaScript', 'ys-cache' ); ?>
                </label>
                <label class="ys-cache-switch">
                    <input name="js_defer" type="checkbox" role="switch" <?php checked( ! empty( $assetSettings['js_defer'] ) ); ?>>
                    <span class="ys-cache-switch-track" aria-hidden="true"></span>
                    <?php esc_html_e( 'Defer JavaScript', 'ys-cache' ); ?>
                </label>
            </div>
            <p class="description"><?php esc_html_e( 'Minify reduces eligible local script size. Defer lets compatible scripts run after the browser parses the page.', 'ys-cache' ); ?></p>
            <div class="ys-cache-grid ys-cache-asset-exclusions">
                <label><?php esc_html_e( 'Script handles to defer', 'ys-cache' ); ?>
                    <textarea name="js_defer_include" rows="4" maxlength="12000" aria-describedby="ys-cache-js-defer-help"><?php echo esc_textarea( $assetSettings['js_defer_include'] ?? '' ); ?></textarea>
                </label>
            </div>
            <p class="description" id="ys-cache-js-defer-help"><?php esc_html_e( 'Enter one exact, registered WordPress script handle per line. Defer applies only to listed handles. Exclusions take precedence.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'Test affected interactions after enabling defer. Keep scripts that rely on inline initialization or strict execution order in the exclusions.', 'ys-cache' ); ?></p>
            <div class="ys-cache-grid ys-cache-asset-exclusions">
                <label><?php esc_html_e( 'JavaScript exclusions', 'ys-cache' ); ?>
                    <textarea name="js_excludes" rows="5" maxlength="12000" aria-describedby="ys-cache-js-exclusions-help"><?php echo esc_textarea( $assetSettings['js_excludes'] ?? '' ); ?></textarea>
                </label>
            </div>
            <p class="description" id="ys-cache-js-exclusions-help"><?php esc_html_e( 'These exclusions apply to both options. Enter one asset handle or path per line. Use * as a wildcard in paths.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="detection" id="ys-cache-panel-detection" aria-labelledby="ys-cache-tab-detection" hidden>
            <h2><?php esc_html_e( 'Automatic detection', 'ys-cache' ); ?></h2>
            <label class="ys-cache-switch">
                <input name="auto_detect" type="checkbox" role="switch" <?php checked( ! empty( $settings['auto_detect'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Detect compatible server integrations', 'ys-cache' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Detect OPcache and server cache integrations already available for this site. Application exclusions remain active.', 'ys-cache' ); ?></p>
            <dl class="ys-cache-detection-types">
                <div><dt><?php esc_html_e( 'Local HTML and OPcache', 'ys-cache' ); ?></dt><dd><?php esc_html_e( 'These layers can be purged without API credentials.', 'ys-cache' ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Server and CDN integrations', 'ys-cache' ); ?></dt><dd><?php esc_html_e( 'A supported server integration or connected provider is needed to purge those caches.', 'ys-cache' ); ?></dd></div>
            </dl>
            <?php if ( $integrations ) : ?>
                <h3><?php esc_html_e( 'Detected application integrations', 'ys-cache' ); ?></h3>
                <ul class="ys-cache-integrations">
                    <?php foreach ( $integrations as $integration ) : ?>
                        <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( $integration ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="cdn" id="ys-cache-panel-cdn" aria-labelledby="ys-cache-tab-cdn" hidden>
            <h2><?php esc_html_e( 'CDN layers', 'ys-cache' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Order layers from origin to edge. Each enabled CDN layer can follow the site’s automatic purge setting or be set to manual only.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'To deliver HTML through a CDN, route the site’s canonical hostname through the provider in DNS. For static files, enter a dedicated CDN hostname and enable URL rewriting. Signed, private, and logged-in asset URLs keep their original host.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'Before allowing CDN HTML caching, verify bypass rules for cookies, query strings, authorization, and requests other than GET and HEAD.', 'ys-cache' ); ?></p>
            <div class="ys-cache-grid ys-cache-asset-exclusions">
                <label for="ys-cache-cdn-aliases"><?php esc_html_e( 'Website alias hostnames', 'ys-cache' ); ?>
                    <textarea id="ys-cache-cdn-aliases" name="alias_hosts" rows="3" aria-describedby="ys-cache-cdn-aliases-help" placeholder="www.example.com"><?php echo esc_textarea( implode( "\n", $providers['alias_hosts'] ?? [] ) ); ?></textarea>
                </label>
            </div>
            <p class="description" id="ys-cache-cdn-aliases-help"><?php esc_html_e( 'Enter one hostname per line for alternate domains serving the same pages. Add a separate CDN layer when an alias uses a different CDN zone or resource.', 'ys-cache' ); ?></p>
            <div id="ys-cache-cdn-list"><?php foreach ( $cdnRows as $index => $row ) { $renderRow( $row, $index ); } ?></div>
            <template id="ys-cache-cdn-template"><?php $renderRow( [] ); ?></template>
            <p><button type="button" class="button" data-cdn-add><?php esc_html_e( 'Add CDN layer', 'ys-cache' ); ?></button></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="ys-cloud" id="ys-cache-panel-ys-cloud" aria-labelledby="ys-cache-tab-ys-cloud" hidden>
            <h2><?php esc_html_e( 'YS CLOUD Integration', 'ys-cache' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Connect the server cache for your YS CLOUD site using the credentials provided for this site.', 'ys-cache' ); ?></p>
            <div class="ys-cache-options">
                <label><input type="checkbox" name="enhance_enabled" <?php checked( ! empty( $enhance['enabled'] ) ); ?>> <?php esc_html_e( 'Enable YS CLOUD server cache purging', 'ys-cache' ); ?></label>
                <label><input type="checkbox" name="enhance_clear_token"> <?php esc_html_e( 'Remove saved API token', 'ys-cache' ); ?></label>
            </div>
            <div class="ys-cache-grid">
                <label><?php esc_html_e( 'YS CLOUD control panel URL', 'ys-cache' ); ?>
                    <input type="url" name="enhance_url" placeholder="https://panel.example.com" value="<?php echo esc_attr( $enhance['url'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'Domain ID', 'ys-cache' ); ?>
                    <input type="text" name="enhance_domain_id" value="<?php echo esc_attr( $enhance['domain_id'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'Organization ID (optional)', 'ys-cache' ); ?>
                    <input type="text" name="enhance_org_id" value="<?php echo esc_attr( $enhance['org_id'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'YS CLOUD API token', 'ys-cache' ); ?>
                    <input type="password" name="enhance_token" autocomplete="new-password" placeholder="<?php echo esc_attr( $hasEnhanceConnection ? $credentialSaved : $credentialMissing ); ?>">
                </label>
            </div>
            <h3><?php esc_html_e( 'Server HTML cache', 'ys-cache' ); ?></h3>
            <p class="description"><?php esc_html_e( 'YS CLOUD can manage the HTML cache. In this mode, YS CACHE stops using local HTML files and follows the site’s automatic purge setting.', 'ys-cache' ); ?></p>
            <div class="ys-cache-actions">
                <button type="button" class="button" data-enhance="status" aria-describedby="ys-cache-cloud-connection-required" <?php disabled( ! $hasEnhanceConnection ); ?>><?php esc_html_e( 'Check server cache status', 'ys-cache' ); ?></button>
                <button type="button" class="button" data-enhance="preview" aria-describedby="ys-cache-cloud-connection-required" <?php disabled( ! $hasEnhanceConnection ); ?>><?php esc_html_e( 'Preview exclusions for saved settings', 'ys-cache' ); ?></button>
                <button type="button" class="button" data-enhance="sync" aria-describedby="ys-cache-cloud-connection-required" <?php disabled( ! $hasEnhanceConnection ); ?>><?php esc_html_e( 'Sync suggested exclusions', 'ys-cache' ); ?></button>
                <button type="button" class="button" data-purge="enhance" aria-describedby="ys-cache-cloud-connection-required ys-cache-cloud-purge-required" <?php disabled( ! $cloudPurgeReady ); ?>><?php esc_html_e( 'Purge YS CLOUD cache', 'ys-cache' ); ?></button>
            </div>
            <p id="ys-cache-cloud-connection-required" class="description" data-enhance-missing <?php echo $hasEnhanceConnection ? 'hidden' : ''; ?>><?php echo esc_html( $cloudConnectionNeeded ); ?></p>
            <p id="ys-cache-cloud-purge-required" class="description" data-enhance-enable-to-purge <?php echo $hasEnhanceConnection && ! $cloudPurgeReady ? '' : 'hidden'; ?>><?php echo esc_html( $cloudEnableToPurge ); ?></p>
            <p data-enhance-status class="description" aria-live="polite" data-enabled="<?php esc_attr_e( 'The server cache is enabled. Verify bypass rules before using it for HTML.', 'ys-cache' ); ?>" data-disabled="<?php esc_attr_e( 'The server cache is disabled.', 'ys-cache' ); ?>"></p>
            <div class="ys-cache-status" data-tab-purge-status role="status" aria-live="polite"></div>
            <ul class="ys-cache-results" data-tab-purge-results aria-live="polite"></ul>
            <ul data-enhance-paths data-missing="<?php esc_attr_e( 'Ready to sync', 'ys-cache' ); ?>" data-added="<?php esc_attr_e( 'Added', 'ys-cache' ); ?>" aria-live="polite"></ul>
            <div class="ys-cache-options">
                <label><input type="checkbox" name="enhance_delegate_html" <?php checked( $delegated ); ?>> <?php esc_html_e( 'Use YS CLOUD for HTML caching', 'ys-cache' ); ?></label>
                <label><input type="checkbox" name="enhance_rules_verified" <?php checked( ! empty( $enhance['rules_verified'] ) ); ?>> <?php esc_html_e( 'I verified that the server bypasses the cache for stateful requests.', 'ys-cache' ); ?></label>
            </div>
            <p class="description"><?php esc_html_e( 'Sync known storefront and translated paths. Verify the server rules for cookies outside the allowlist, authorization headers, query strings, methods other than GET and HEAD, forms, and request-dependent languages.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="nginx" id="ys-cache-panel-nginx" aria-labelledby="ys-cache-tab-nginx" hidden>
            <h2><?php esc_html_e( 'Nginx Cache (Beta)', 'ys-cache' ); ?></h2>
            <p class="description"><?php esc_html_e( 'This integration requires a host-configured Nginx cache purge connection. An Nginx server header only identifies the web server.', 'ys-cache' ); ?></p>
            <p><a href="https://github.com/Ya19880104/ys-cache/blob/main/docs/nginx-cache.md"><?php esc_html_e( 'Nginx cache setup for hosting administrators', 'ys-cache' ); ?></a></p>
            <p class="description" id="ys-cache-nginx-status"><?php echo esc_html( $nginxMessage ); ?></p>
            <div class="ys-cache-switches">
                <label class="ys-cache-switch">
                    <input name="nginx_enabled" type="checkbox" role="switch" aria-describedby="ys-cache-nginx-status ys-cache-nginx-rules-help" <?php checked( ! empty( $nginxSettings['enabled'] ) ); ?> <?php disabled( ( ! $nginxSupportsAll || empty( $nginxSettings['rules_verified'] ) ) && empty( $nginxSettings['enabled'] ) ); ?>>
                    <span class="ys-cache-switch-track" aria-hidden="true"></span>
                    <?php esc_html_e( 'Use Nginx Cache for HTML and origin purges', 'ys-cache' ); ?>
                </label>
                <label>
                    <input name="nginx_rules_verified" type="checkbox" aria-describedby="ys-cache-nginx-rules-help" <?php checked( ! empty( $nginxSettings['rules_verified'] ) ); ?> <?php disabled( ! $nginxReady && empty( $nginxSettings['rules_verified'] ) ); ?>>
                    <?php esc_html_e( 'I verified that the server bypasses the cache for stateful requests.', 'ys-cache' ); ?>
                </label>
            </div>
            <p class="description" id="ys-cache-nginx-rules-help"><?php esc_html_e( 'The server can serve native cache hits before WordPress runs. Verify cache bypass for logins, carts and checkout, forms with visitor-specific data, authorization, and other stateful requests. Check that language URLs are isolated and request-dependent languages bypass the native cache.', 'ys-cache' ); ?></p>
            <p class="ys-cache-actions"><button type="button" class="button" data-purge="nginx" aria-describedby="ys-cache-nginx-status<?php echo $nginxSupportsAll ? ' ys-cache-nginx-purge-required' : ''; ?>" <?php disabled( ! $nginxPurgeReady ); ?>><?php esc_html_e( 'Purge Nginx cache', 'ys-cache' ); ?></button></p>
            <?php if ( $nginxSupportsAll ) : ?>
                <p id="ys-cache-nginx-purge-required" class="description" data-purge-reason="nginx" <?php echo $nginxPurgeReady ? 'hidden' : ''; ?>><?php echo esc_html( $nginxEnableToPurge ); ?></p>
            <?php endif; ?>
            <div class="ys-cache-status" data-tab-purge-status role="status" aria-live="polite"></div>
            <ul class="ys-cache-results" data-tab-purge-results aria-live="polite"></ul>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="litespeed" id="ys-cache-panel-litespeed" aria-labelledby="ys-cache-tab-litespeed" hidden>
            <h2><?php esc_html_e( 'LiteSpeed Cache (Beta)', 'ys-cache' ); ?></h2>
            <p class="description"><?php esc_html_e( 'This integration requests cache purges through LiteSpeed Cache on supported servers. Live LiteSpeed server verification is pending.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'When this integration is enabled, CDN clearing continues after the server receives the origin purge request.', 'ys-cache' ); ?></p>
            <?php if ( ! empty( $liteSpeed['message'] ) ) : ?>
                <p id="ys-cache-litespeed-status" class="description"><?php echo esc_html( $liteSpeed['message'] ); ?></p>
            <?php endif; ?>
            <div class="ys-cache-switches">
                <label class="ys-cache-switch">
                    <input name="litespeed_enabled" type="checkbox" role="switch" aria-describedby="ys-cache-litespeed-status ys-cache-litespeed-rules-help" <?php checked( ! empty( $liteSpeedSettings['enabled'] ) ); ?> <?php disabled( ( ! $liteSpeedReady || empty( $liteSpeedSettings['rules_verified'] ) ) && empty( $liteSpeedSettings['enabled'] ) ); ?>>
                    <span class="ys-cache-switch-track" aria-hidden="true"></span>
                    <?php esc_html_e( 'Use LiteSpeed Cache for HTML and origin purges', 'ys-cache' ); ?>
                </label>
                <label>
                    <input name="litespeed_rules_verified" type="checkbox" aria-describedby="ys-cache-litespeed-rules-help ys-cache-litespeed-prerequisites" <?php checked( ! empty( $liteSpeedSettings['rules_verified'] ) ); ?> <?php disabled( ! $liteSpeedReady && empty( $liteSpeedSettings['rules_verified'] ) ); ?>>
                    <?php esc_html_e( 'I verified that the server bypasses the cache for stateful requests.', 'ys-cache' ); ?>
                </label>
            </div>
            <p class="description" id="ys-cache-litespeed-rules-help"><?php esc_html_e( 'The server can serve native cache hits before WordPress runs. Verify cache bypass for logins, carts and checkout, forms with visitor-specific data, authorization, and other stateful requests. Check that language URLs are isolated and request-dependent languages bypass the native cache.', 'ys-cache' ); ?></p>
            <p class="description" id="ys-cache-litespeed-prerequisites"><?php esc_html_e( 'Disable LiteSpeed Guest Mode and forced cache rules before enabling the integration.', 'ys-cache' ); ?></p>
            <p class="ys-cache-actions"><button type="button" class="button" data-purge="litespeed" aria-describedby="ys-cache-litespeed-status ys-cache-litespeed-purge-required" <?php disabled( ! $liteSpeedPurgeReady ); ?>><?php esc_html_e( 'Purge LiteSpeed Cache', 'ys-cache' ); ?></button></p>
            <?php if ( $liteSpeedReady ) : ?><p id="ys-cache-litespeed-purge-required" class="description" data-purge-reason="litespeed" <?php echo $liteSpeedPurgeReady ? 'hidden' : ''; ?>><?php echo esc_html( $liteSpeedEnableToPurge ); ?></p><?php endif; ?>
            <div class="ys-cache-status" data-tab-purge-status role="status" aria-live="polite"></div>
            <ul class="ys-cache-results" data-tab-purge-results aria-live="polite"></ul>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="connections" id="ys-cache-panel-connections" aria-labelledby="ys-cache-tab-connections" hidden>
            <h2><?php esc_html_e( 'Connection setup', 'ys-cache' ); ?></h2>
            <p><?php esc_html_e( 'Connect an existing CDN resource using its delivery hostname or its ID from the provider dashboard. Use an API credential that can read the resource and purge its cache. Resource lookup checks your connection to a resource and its hostname.', 'ys-cache' ); ?></p>
            <h3>Cloudflare</h3>
            <p><?php esc_html_e( 'Use a user API token scoped to the existing zone. Include Zone Read for hostname lookup and Cache Purge for clearing. Enter the zone hostname to look up its ID, or enter the Zone ID to verify it.', 'ys-cache' ); ?></p>
            <h3>Bunny CDN</h3>
            <p><?php esc_html_e( 'In the bunny.net dashboard, open Account → API Key. Copy the account-level key into the Bunny CDN layer. Enter the Pull Zone hostname to find its ID, or enter the Pull Zone ID directly.', 'ys-cache' ); ?></p>
            <p><a href="https://bunny.net/docs/account/api-keys"><?php esc_html_e( 'Bunny CDN API key instructions', 'ys-cache' ); ?></a></p>
            <h3>Gcore</h3>
            <p><?php esc_html_e( 'In the Gcore Customer Portal, open Profile → API tokens → Create token. Select a role that can read CDN resources and purge their cache. Copy the token from the creation dialog and paste it into the Gcore layer.', 'ys-cache' ); ?></p>
            <p><a href="https://docs.gcore.com/developer-tools/rest-api/authentication"><?php esc_html_e( 'Gcore API token instructions', 'ys-cache' ); ?></a></p>
            <h3>CNAME</h3>
            <p><?php esc_html_e( 'CNAME mode rewrites eligible URLs to an existing CDN hostname that already works in DNS. Use a provider layer for API lookup and purging.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="exclusion-guide" id="ys-cache-panel-exclusion-guide" aria-labelledby="ys-cache-tab-exclusion-guide" hidden>
            <h2><?php esc_html_e( 'Exclusion guide', 'ys-cache' ); ?></h2>
            <p><?php esc_html_e( 'Enter one rule per line in Cache settings → Exclusions.', 'ys-cache' ); ?></p>
            <h3><?php esc_html_e( 'Paths', 'ys-cache' ); ?></h3>
            <p><?php
                printf(
                    /* translators: %1$s: example public URL. %2$s: its URL path. */
                    esc_html__( 'Copy the path starting with a slash. Include the language prefix and any public subdirectory. Leave out the domain, query string, and fragment. For %1$s, enter %2$s.', 'ys-cache' ),
                    '<code>https://example.com/site/en/contact/?source=email#form</code>',
                    '<code>/site/en/contact/</code>'
                );
            ?></p>
            <ul>
                <li><?php
                    printf(
                        /* translators: %1$s and %2$s: exact path without and with its final slash. */
                        esc_html__( 'The exact rule %1$s matches %1$s and %2$s. A rule entered with the final slash matches the slash version.', 'ys-cache' ),
                        '<code>/en/contact</code>',
                        '<code>/en/contact/</code>'
                    );
                ?></li>
                <li><?php
                    printf(
                        /* translators: %1$s: wildcard path rule. %2$s: base path. %3$s: example nested path. */
                        esc_html__( 'Use %1$s to exclude %2$s and its nested pages, such as %3$s.', 'ys-cache' ),
                        '<code>/members/*</code>',
                        '<code>/members/</code>',
                        '<code>/members/profile/</code>'
                    );
                ?></li>
            </ul>
            <h3>Cookie</h3>
            <p><?php
                printf(
                    /* translators: %1$s: cookie name prefix. %2$s: example cookie name. */
                    esc_html__( 'Enter a cookie name prefix. For example, %1$s matches the cookie named %2$s.', 'ys-cache' ),
                    '<code>private_session_</code>',
                    '<code>private_session_123</code>'
                );
            ?></p>
            <h3>User-Agent</h3>
            <p><?php
                printf(
                    /* translators: %1$s: substring rule. %2$s: example User-Agent value. */
                    esc_html__( 'Enter a part of the User-Agent header. For example, %1$s matches %2$s.', 'ys-cache' ),
                    '<code>ExampleBot</code>',
                    '<code>ExampleBot/1.0</code>'
                );
            ?></p>
            <h3><?php esc_html_e( 'Check a front-end cache hit', 'ys-cache' ); ?></h3>
            <p><?php esc_html_e( 'In a private browser window, open Developer Tools → Network and reload the page twice. Select the main document and check X-YS-Cache in Response Headers. HIT means YS CACHE served HTML. X-FastCGI-Cache describes the server cache separately.', 'ys-cache' ); ?></p>
            <h3><?php esc_html_e( 'Load a form separately', 'ys-cache' ); ?></h3>
            <p><?php esc_html_e( 'Place the form on a separate page on this site, add its path under Paths, then embed that page in an iframe on the public page. The form loads with its own scripts and visitor state.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="import" id="ys-cache-panel-import" aria-labelledby="ys-cache-tab-import" hidden>
            <h2><?php esc_html_e( 'Automatic import', 'ys-cache' ); ?></h2>
            <p><?php esc_html_e( 'When you activate YS CACHE, it checks the active cache plugin and imports supported settings before enabling HTML caching.', 'ys-cache' ); ?></p>
            <p><a href="<?php echo esc_url( admin_url( 'tools.php?page=ys-cache-import' ) ); ?>"><?php esc_html_e( 'Import results', 'ys-cache' ); ?></a></p>
            <p><a href="https://yangsheep.com.tw/wordpress-plugins/ys-cache/"><?php esc_html_e( 'YS CACHE installation and downloads', 'ys-cache' ); ?></a></p>
        </section>
        <div class="ys-cache-form-footer"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ys-cache' ); ?></button></div>
    </form>
    </div>
    </div>
</div>
