<?php

defined( 'ABSPATH' ) || exit;

$enhance = $providers['enhance'] ?? [];
$opcache = $providers['opcache'] ?? [];
$cdnRows = $providers['cdn'] ?? [];
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
        'enhance' => 'Enhance',
        'cdn' => __( 'CDN layers', 'ys-cache' ),
    ] ],
    'help' => [ __( 'Help', 'ys-cache' ), 'book-alt', [
        'connections' => __( 'Connection setup', 'ys-cache' ),
        'import' => __( 'Automatic import', 'ys-cache' ),
    ] ],
];
$renderRow = static function ( array $row, ?int $index = null ) use ( $credentialMissing, $credentialSaved ): void {
    $type = $row['provider'] ?? 'cloudflare';
    $scope = $row['scope'] ?? ( ( $row['role'] ?? '' ) === 'images' || $type === 'cname' ? 'static' : 'all' );
    $purgePolicy = \YangSheep\Cache\YSCacheProviders::autoPurgePolicy( $row );
    ?>
    <fieldset class="ys-cache-cdn" data-cdn-row data-saved-index="<?php echo esc_attr( $index === null ? '' : (string) $index ); ?>">
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
                <input type="password" data-key="token" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $row['token'] ) && empty( $row['token_constant'] ) ? $credentialMissing : $credentialSaved ); ?>">
            </label>
            <label data-cloudflare-only><?php esc_html_e( 'Cloudflare account email (Global API Key)', 'ys-cache' ); ?>
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
            <label data-cloudflare-only data-full-zone-only><input type="checkbox" data-key="purge_entire_zone" <?php checked( ! empty( $row['purge_entire_zone'] ) ); ?>> <?php esc_html_e( 'Purge the entire Cloudflare zone', 'ys-cache' ); ?></label>
            <label data-api-only><input type="checkbox" data-key="clear_token"> <?php esc_html_e( 'Remove stored credential', 'ys-cache' ); ?></label>
        </div>
        <div class="ys-cache-actions">
            <button type="button" class="button" data-cdn-discover data-api-only><?php esc_html_e( 'Find resource ID', 'ys-cache' ); ?></button>
            <button type="button" class="button" data-cdn-up><?php esc_html_e( 'Move up', 'ys-cache' ); ?></button>
            <button type="button" class="button" data-cdn-down><?php esc_html_e( 'Move down', 'ys-cache' ); ?></button>
        </div>
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
    data-max-layers="<?php echo (int) \YangSheep\Cache\YSCacheProviders::MAX_CDN_LAYERS; ?>">
    <header class="ys-cache-header">
        <h1><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Cache Management', 'ys-cache' ); ?></h1>
        <p><?php esc_html_e( 'Manage HTML caching, exclusions, and purge integrations for this site.', 'ys-cache' ); ?></p>
    </header>
    <div class="ys-cache-layout">
    <aside class="ys-cache-sidebar" aria-labelledby="ys-cache-purge-heading">
        <h2 id="ys-cache-purge-heading"><?php esc_html_e( 'Purge cache', 'ys-cache' ); ?></h2>
        <div class="ys-cache-purge-actions">
            <button type="button" class="button ys-cache-purge-main" data-purge="all"><?php esc_html_e( 'Purge entire site', 'ys-cache' ); ?></button>
            <?php foreach ( [ 'local' => __( 'Purge HTML cache', 'ys-cache' ), 'enhance' => __( 'Purge Enhance Nginx', 'ys-cache' ), 'cdn' => __( 'Purge CDN', 'ys-cache' ), 'opcache' => __( 'Reset OPcache', 'ys-cache' ) ] as $scope => $label ) : ?>
                <button type="button" class="button" data-purge="<?php echo esc_attr( $scope ); ?>"><?php echo esc_html( $label ); ?></button>
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
            $owner = $delegated ? 'external' : ( ! empty( $settings['enabled'] ) ? 'local' : 'disabled' );
            $ownership = [
                'external' => __( 'Enhance manages the HTML cache.', 'ys-cache' ),
                'local' => __( 'YS CACHE manages the HTML cache.', 'ys-cache' ),
                'disabled' => __( 'HTML caching is disabled.', 'ys-cache' ),
            ];
            ?>
            <p data-cache-owner data-external="<?php echo esc_attr( $ownership['external'] ); ?>" data-local="<?php echo esc_attr( $ownership['local'] ); ?>" data-disabled="<?php echo esc_attr( $ownership['disabled'] ); ?>"><?php echo esc_html( $ownership[ $owner ] ); ?></p>
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
            <h3><?php esc_html_e( 'Automatic purge', 'ys-cache' ); ?></h3>
            <label class="ys-cache-switch">
                <input name="auto_purge" type="checkbox" role="switch" <?php checked( ! empty( $settings['auto_purge'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Purge caches when published content changes', 'ys-cache' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Enhance and enabled CDN integrations follow this setting. CDN layers set to Manual only are cleared through manual purge actions.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="exclusions" id="ys-cache-panel-exclusions" aria-labelledby="ys-cache-tab-exclusions" hidden>
            <h2><?php esc_html_e( 'Exclusions', 'ys-cache' ); ?></h2>
            <?php if ( $forms ) : ?>
                <p class="description"><?php
                    echo esc_html( sprintf(
                        /* translators: %s: list of active form plugins. */
                        __( 'Detected form plugins (%s). Pages with supported forms are automatically excluded.', 'ys-cache' ),
                        implode( wp_get_list_item_separator(), $forms )
                    ) );
                ?></p>
            <?php endif; ?>
            <h3><?php esc_html_e( 'Manual exclusions', 'ys-cache' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Enter one rule per line. Path rules support * wildcards. Cookie rules match name prefixes, and User-Agent rules match substrings.', 'ys-cache' ); ?></p>
            <div class="ys-cache-grid">
                <?php foreach ( [ 'exclude_paths' => [ __( 'Paths', 'ys-cache' ), '/members/*' ], 'exclude_cookies' => [ 'Cookie', 'private_session_' ], 'exclude_agents' => [ 'User-Agent', 'ExampleBot' ] ] as $key => [ $label, $example ] ) : ?>
                    <label><?php echo esc_html( $label ); ?>
                        <textarea name="<?php echo esc_attr( $key ); ?>" rows="5" maxlength="12000" placeholder="<?php echo esc_attr( $example ); ?>"><?php echo esc_textarea( $settings[ $key ] ); ?></textarea>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="opcache" id="ys-cache-panel-opcache" aria-labelledby="ys-cache-tab-opcache" hidden>
            <h2>OPcache</h2>
            <p class="description"><?php esc_html_e( 'OPcache stores compiled PHP on the origin server. Enable this option to reset it before a full-site purge.', 'ys-cache' ); ?></p>
            <label class="ys-cache-switch">
                <input type="checkbox" name="opcache_enabled" role="switch" <?php checked( ! empty( $opcache['enabled'] ) ); ?>>
                <span class="ys-cache-switch-track" aria-hidden="true"></span>
                <?php esc_html_e( 'Reset OPcache when purging all layers', 'ys-cache' ); ?>
            </label>
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
            <p class="description"><?php esc_html_e( 'Detect OPcache and any Enhance connection preconfigured by the host. Application exclusions remain active.', 'ys-cache' ); ?></p>
            <dl class="ys-cache-detection-types">
                <div><dt><?php esc_html_e( 'Local HTML and OPcache', 'ys-cache' ); ?></dt><dd><?php esc_html_e( 'These layers can be purged without API credentials.', 'ys-cache' ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Enhance and CDN', 'ys-cache' ); ?></dt><dd><?php esc_html_e( 'These services use provider API credentials. Ask your host to provision Enhance for this site. Enhance purging is available after the connection is configured.', 'ys-cache' ); ?></dd></div>
            </dl>
            <details class="ys-cache-host-setup">
                <summary><?php esc_html_e( 'Host-managed Enhance configuration', 'ys-cache' ); ?></summary>
                <p class="description"><?php
                    printf(
                        /* translators: %s: site configuration filename. */
                        esc_html__( 'Your host can set these constants in %s for this site. You can also enter the credentials in the Enhance tab.', 'ys-cache' ),
                        '<code>wp-config.php</code>'
                    );
                ?></p>
                <ul>
                    <li><code>YS_CACHE_ENHANCE_URL</code></li>
                    <li><code>YS_CACHE_ENHANCE_DOMAIN_ID</code></li>
                    <li><code>YS_CACHE_ENHANCE_TOKEN</code></li>
                    <li><code>YS_CACHE_ENHANCE_ORG_ID</code> <?php esc_html_e( '(optional)', 'ys-cache' ); ?></li>
                </ul>
            </details>
            <?php if ( $integrations ) : ?>
                <h3><?php esc_html_e( 'Detected application integrations', 'ys-cache' ); ?></h3>
                <ul class="ys-cache-integrations">
                    <?php foreach ( $integrations as $integration ) : ?>
                        <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( $integration ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="enhance" id="ys-cache-panel-enhance" aria-labelledby="ys-cache-tab-enhance" hidden>
            <h2>Enhance</h2>
            <div class="ys-cache-options">
                <label><input type="checkbox" name="enhance_enabled" <?php checked( ! empty( $enhance['enabled'] ) ); ?>> <?php esc_html_e( 'Enable Enhance Nginx purging', 'ys-cache' ); ?></label>
                <label><input type="checkbox" name="enhance_clear_token"> <?php esc_html_e( 'Remove Enhance token', 'ys-cache' ); ?></label>
            </div>
            <?php if ( ! empty( $lastAutoPurge['time'] ) ) : ?>
                <?php
                $purgeState = [
                    'ok'    => __( 'Succeeded', 'ys-cache' ),
                    'retry' => __( 'Waiting to retry', 'ys-cache' ),
                    'error' => __( 'Failed', 'ys-cache' ),
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
                <?php if ( ! empty( $lastAutoPurge['layers'] ) ) : ?>
                    <ul class="ys-cache-results">
                        <?php foreach ( $lastAutoPurge['layers'] as $result ) : ?>
                            <li data-status="<?php echo esc_attr( $result['status'] ); ?>"><?php echo esc_html( $result['layer'] . ' — ' . $result['message'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <div class="ys-cache-grid">
                <label><?php esc_html_e( 'Enhance control panel URL', 'ys-cache' ); ?>
                    <input type="url" name="enhance_url" placeholder="https://panel.example.com" value="<?php echo esc_attr( $enhance['url'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'Domain ID', 'ys-cache' ); ?>
                    <input type="text" name="enhance_domain_id" value="<?php echo esc_attr( $enhance['domain_id'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'Organization ID (optional)', 'ys-cache' ); ?>
                    <input type="text" name="enhance_org_id" value="<?php echo esc_attr( $enhance['org_id'] ?? '' ); ?>">
                </label>
                <label><?php esc_html_e( 'Enhance API Token', 'ys-cache' ); ?>
                    <input type="password" name="enhance_token" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $enhance['token'] ) ? $credentialMissing : $credentialSaved ); ?>">
                </label>
            </div>
            <h3><?php esc_html_e( 'Enhance HTML integration', 'ys-cache' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Use Enhance to cache HTML. YS CACHE stops using local HTML files. Enhance follows the site’s automatic purge setting.', 'ys-cache' ); ?></p>
            <div class="ys-cache-actions">
                <button type="button" class="button" data-enhance="status"><?php esc_html_e( 'Check FastCGI status', 'ys-cache' ); ?></button>
                <button type="button" class="button" data-enhance="preview"><?php esc_html_e( 'Preview exclusions for saved settings', 'ys-cache' ); ?></button>
                <button type="button" class="button" data-enhance="sync"><?php esc_html_e( 'Sync suggested exclusions', 'ys-cache' ); ?></button>
            </div>
            <p data-enhance-status class="description" aria-live="polite" data-enabled="<?php esc_attr_e( 'Enhance FastCGI is enabled. Verify the Nginx bypass rules before enabling HTML integration.', 'ys-cache' ); ?>" data-disabled="<?php esc_attr_e( 'Enhance FastCGI is disabled.', 'ys-cache' ); ?>"></p>
            <ul data-enhance-paths data-missing="<?php esc_attr_e( 'Ready to sync', 'ys-cache' ); ?>" data-added="<?php esc_attr_e( 'Added', 'ys-cache' ); ?>" aria-live="polite"></ul>
            <div class="ys-cache-options">
                <label><input type="checkbox" name="enhance_delegate_html" <?php checked( $delegated ); ?>> <?php esc_html_e( 'Use Enhance for HTML caching', 'ys-cache' ); ?></label>
                <label><input type="checkbox" name="enhance_rules_verified" <?php checked( ! empty( $enhance['rules_verified'] ) ); ?>> <?php esc_html_e( 'I verified that Nginx bypasses the cache for stateful requests.', 'ys-cache' ); ?></label>
            </div>
            <p class="description"><?php esc_html_e( 'Sync known storefront and translated paths. Verify that Nginx also handles cookies outside the allowlist, authorization headers, query strings, methods other than GET and HEAD, forms, and request-dependent languages.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="cdn" id="ys-cache-panel-cdn" aria-labelledby="ys-cache-tab-cdn" hidden>
            <h2><?php esc_html_e( 'CDN layers', 'ys-cache' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Order layers from origin to edge. Each enabled CDN layer can follow the site’s automatic purge setting or be set to manual only.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'To deliver HTML through a CDN, route the site’s canonical hostname through the provider in DNS. For static files, enter a dedicated CDN hostname and enable URL rewriting. Signed, private, and logged-in asset URLs keep their original host.', 'ys-cache' ); ?></p>
            <p class="description"><?php esc_html_e( 'Before allowing CDN HTML caching, verify bypass rules for cookies, query strings, authorization, and requests other than GET and HEAD.', 'ys-cache' ); ?></p>
            <div id="ys-cache-cdn-list"><?php foreach ( $cdnRows as $index => $row ) { $renderRow( $row, $index ); } ?></div>
            <template id="ys-cache-cdn-template"><?php $renderRow( [] ); ?></template>
            <p><button type="button" class="button" data-cdn-add><?php esc_html_e( 'Add CDN layer', 'ys-cache' ); ?></button></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="connections" id="ys-cache-panel-connections" aria-labelledby="ys-cache-tab-connections" hidden>
            <h2><?php esc_html_e( 'Connection setup', 'ys-cache' ); ?></h2>
            <h3>Enhance</h3>
            <p><?php esc_html_e( 'If your hosting provider manages Enhance, ask them to provision the connection for this site. API token creation is handled by a hosting or control-panel administrator.', 'ys-cache' ); ?></p>
            <h4><?php esc_html_e( 'For hosting administrators', 'ys-cache' ); ?></h4>
            <ol>
                <li><?php esc_html_e( 'In the appropriate Enhance organization, open Settings, then Access Tokens. Create a token with permission to read this site’s domain and manage its FastCGI cache. Set an expiration date and IP restrictions where available.', 'ys-cache' ); ?></li>
                <li><?php esc_html_e( 'Copy the token and organization ID. Look up the site domain in the website domains endpoint and copy its domain ID. The website ID identifies the parent website.', 'ys-cache' ); ?> <code>GET /api/orgs/{org_id}/websites/{website_id}/domains</code></li>
                <li><?php esc_html_e( 'Enter the HTTPS panel URL, domain ID, organization ID, and token in the Enhance tab. Check FastCGI status before enabling HTML integration.', 'ys-cache' ); ?></li>
            </ol>
            <p><a href="https://enhance.com/docs/website-tools/nginx/fastcgi-caching"><?php esc_html_e( 'Enhance FastCGI documentation', 'ys-cache' ); ?></a> · <a href="https://apidocs.enhance.com/spec/oas3-api.yaml"><?php esc_html_e( 'Enhance API reference', 'ys-cache' ); ?></a></p>
            <h3><?php esc_html_e( 'CDN providers', 'ys-cache' ); ?></h3>
            <p><?php esc_html_e( 'Create a credential with access to read the resource and purge its cache. Enter the delivery hostname and use Find resource ID, or enter the resource ID from your provider dashboard.', 'ys-cache' ); ?></p>
        </section>
        <section class="ys-cache-card" role="tabpanel" data-tab-panel="import" id="ys-cache-panel-import" aria-labelledby="ys-cache-tab-import" hidden>
            <h2><?php esc_html_e( 'Automatic import', 'ys-cache' ); ?></h2>
            <p><?php esc_html_e( 'Activate the optional YS CACHE Import plugin with compatible cache settings on the same site. It detects supported settings automatically and reports the outcome in the Tools menu.', 'ys-cache' ); ?></p>
            <p><a href="https://yangsheep.com.tw/wordpress-plugins/ys-cache/"><?php esc_html_e( 'YS CACHE installation and downloads', 'ys-cache' ); ?></a></p>
        </section>
        <div class="ys-cache-form-footer"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ys-cache' ); ?></button></div>
    </form>
    </div>
    </div>
</div>
