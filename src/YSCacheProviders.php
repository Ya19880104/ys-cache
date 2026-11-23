<?php
/**
 * Cache purging and CDN asset routing.
 *
 * @package YangSheep\Cache
 */

namespace YangSheep\Cache;

defined('ABSPATH') || exit;

final class YSCacheProviders
{
    public const MAX_CDN_LAYERS = 5;

    private const PROVIDER_LABELS = [
        'cloudflare' => 'Cloudflare',
        'gcore' => 'Gcore',
        'bunny' => 'Bunny CDN',
        'cname' => 'CNAME',
    ];

    private const SCOPES = ['all', 'html', 'code', 'static', 'html_code'];
    private const MAX_BUNNY_URLS = 20;
    private const MAX_PURGE_BATCHES = 20;

    private const ASSET_EXTENSIONS = [
        'code' => ['css', 'js', 'mjs'],
        'image' => ['avif', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'svg', 'webp'],
        'font' => ['eot', 'otf', 'ttf', 'woff', 'woff2'],
        'media' => ['m4a', 'm4v', 'mp3', 'mp4', 'ogg', 'ogv', 'vtt', 'wav', 'webm'],
    ];

    public static function scope(array $layer): string
    {
        $scope = $layer['scope'] ?? '';
        if (($layer['provider'] ?? '') === 'cname') {
            return $scope === 'code' ? 'code' : 'static';
        }
        if (in_array($scope, self::SCOPES, true)) {
            return $scope;
        }
        return ($layer['role'] ?? '') === 'images' ? 'static' : 'all';
    }

    public static function scopeSupportsHtml(array $layer): bool
    {
        return in_array(self::scope($layer), ['all', 'html', 'html_code'], true);
    }

    public static function normalizeAliasHosts($raw): array
    {
        $values = is_string($raw) ? preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) : (is_array($raw) ? $raw : []);
        $hosts = [];
        foreach ($values as $value) {
            $host = is_string($value) ? self::hostname(rtrim(trim($value), '.')) : '';
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }

    public static function htmlHostsForLayer(array $settings, array $layer): array
    {
        $site = self::urlParts(home_url('/'), []);
        if ($site === []) {
            return [];
        }
        [$aliases, $dedicated] = self::aliasTopology($settings, $site);
        return self::htmlHosts($layer, $site, $aliases, $dedicated);
    }

    public static function targetBatchLimit(array $settings): int
    {
        $limit = empty($settings['nginx']['enabled']) ? 100 : 5;
        foreach ((array) ($settings['cdn'] ?? []) as $layer) {
            if (is_array($layer) && !empty($layer['enabled']) && ($layer['provider'] ?? '') === 'bunny') {
                $fanout = max(1, count(self::htmlHostsForLayer($settings, $layer)));
                $limit = min($limit, max(1, intdiv(self::MAX_BUNNY_URLS, $fanout)));
            }
        }
        return $limit;
    }

    public static function hasUrlRewriting(array $settings): bool
    {
        return self::routingHosts($settings) !== [];
    }

    public static function opcacheAvailable(): bool
    {
        static $available;
        if ($available === null) {
            $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
            $available = !empty($status['opcache_enabled']) && function_exists('opcache_reset');
        }
        return $available;
    }

    public static function autoPurgePolicy(array $layer): string
    {
        return in_array($layer['auto_purge'] ?? 'inherit', ['off', false, 0, '0'], true) ? 'off' : 'inherit';
    }

    public static function cdnHasCredential(array $layer): bool
    {
        return self::cdnToken($layer) !== '';
    }

    public static function cdnPurgeReady(array $layer): bool
    {
        $provider = $layer['provider'] ?? '';
        return !empty($layer['enabled']) && is_string($provider) && isset(self::PROVIDER_LABELS[$provider]) && $provider !== 'cname'
            && self::zoneId($provider, $layer['zone_id'] ?? '') !== '' && self::cdnHasCredential($layer)
            && (self::scopeSupportsHtml($layer) || self::hostname($layer['hostname'] ?? '') !== '');
    }

    public static function sanitize(array $input, array $previous = []): array
    {
        $alias_hosts = self::normalizeAliasHosts($input['alias_hosts'] ?? $previous['alias_hosts'] ?? []);
        $submitted_enhance = (array) ($input['enhance'] ?? []);
        $previous_enhance = (array) ($previous['enhance'] ?? []);
        $enhance_enabled = !empty($submitted_enhance['enabled']);
        $delegate_html = $enhance_enabled && !empty($submitted_enhance['delegate_html']);
        $enhance = [
            'enabled' => $enhance_enabled,
            'delegate_html' => $delegate_html,
            'rules_verified' => $enhance_enabled && !empty($submitted_enhance['rules_verified']),
            'url' => self::enhanceBase($submitted_enhance['url'] ?? ''),
            'domain_id' => self::uuid($submitted_enhance['domain_id'] ?? ''),
            'org_id' => self::uuid($submitted_enhance['org_id'] ?? ''),
        ];

        // Require fresh credentials when the destination or account changes.
        $same_enhance = $enhance['url'] === ($previous_enhance['url'] ?? '')
            && $enhance['domain_id'] === ($previous_enhance['domain_id'] ?? '')
            && $enhance['org_id'] === ($previous_enhance['org_id'] ?? '');
        $enhance['token'] = self::savedToken(
            $submitted_enhance,
            $same_enhance ? ($previous_enhance['token'] ?? '') : ''
        );

        $previous_layers = [];
        foreach ((array) ($previous['cdn'] ?? []) as $row) {
            if (is_array($row)) {
                $previous_layers[self::credentialKey($row)] = $row;
            }
        }

        $cdn = [];
        foreach (array_slice((array) ($input['cdn'] ?? []), 0, self::MAX_CDN_LAYERS) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = self::text($row['provider'] ?? '');
            if (!isset(self::PROVIDER_LABELS[$name])) {
                continue;
            }

            $hostname = self::hostname($row['hostname'] ?? '');
            $email = $name === 'cloudflare' ? sanitize_email(self::text($row['auth_email'] ?? '')) : '';
            $scope = self::scope($row);
            $layer = [
                'provider' => $name,
                'enabled' => $name !== 'cname' && !empty($row['enabled']),
                'auto_purge' => self::autoPurgePolicy($row),
                'zone_id' => self::zoneId($name, $row['zone_id'] ?? ''),
                'auth_email' => $email,
                'hostname' => $hostname,
                'scope' => $scope,
                'role' => self::scopeSupportsHtml($row) ? 'site' : 'images',
                'rewrite_urls' => $hostname !== '' && $scope !== 'html' && !empty($row['rewrite_urls']),
                'rewrite_images' => $hostname !== '' && !empty($row['rewrite_images']),
                'purge_entire_zone' => $name === 'cloudflare' && !empty($row['purge_entire_zone']),
            ];
            $previous_layer = $previous_layers[self::credentialKey($layer)] ?? [];
            $layer['token'] = $name === 'cname' ? '' : self::savedToken($row, $previous_layer['token'] ?? '');
            if ($name === 'cloudflare' && $layer['token'] === '' && empty($row['clear_token'])
                && ($row['token_constant'] ?? $previous_layer['token_constant'] ?? '') === 'WP_ROCKET_CF_API_KEY') {
                $layer['token_constant'] = 'WP_ROCKET_CF_API_KEY';
            }
            $layer['html_cache_verified'] = !empty($row['html_cache_verified']) && self::canVerifyHtml($layer, $row['hostname'] ?? '', $alias_hosts);

            if ($layer['enabled'] || $layer['zone_id'] !== '' || $hostname !== '') {
                $cdn[] = $layer;
            }
        }

        $litespeed = (array) ($input['litespeed'] ?? $previous['litespeed'] ?? []);
        $nginx = (array) ($input['nginx'] ?? $previous['nginx'] ?? []);
        return [
            'opcache' => ['enabled' => !empty($input['opcache']['enabled'])],
            'enhance' => $enhance,
            'litespeed' => ['enabled' => !empty($litespeed['enabled']), 'rules_verified' => !empty($litespeed['rules_verified'])],
            'nginx' => ['enabled' => !empty($nginx['enabled']), 'rules_verified' => !empty($nginx['rules_verified'])],
            'alias_hosts' => $alias_hosts,
            'cdn' => $cdn,
        ];
    }

    private static function canVerifyHtml(array $layer, $submittedHostname, array $alias_hosts): bool
    {
        if (!$layer['enabled'] || $layer['zone_id'] === '' || !self::scopeSupportsHtml($layer) || self::cdnToken($layer) === '') {
            return false;
        }
        $site = self::urlParts(home_url('/'), []);
        return $site !== [] && ($layer['hostname'] === $site['host'] || in_array($layer['hostname'], $alias_hosts, true)
            || ($layer['hostname'] === '' && self::text($submittedHostname) === ''));
    }

    public static function purge(array $settings, string $scope = 'all', array $urls = []): array
    {
        if (!in_array($scope, ['all', 'urls', 'opcache', 'enhance', 'litespeed', 'nginx', 'cdn'], true) || ($scope === 'urls' && $urls === [])) {
            return [];
        }

        $results = [];
        if ($scope === 'opcache' || ($scope === 'all' && !empty($settings['opcache']['enabled']))) {
            $reset = function_exists('opcache_reset') && @opcache_reset();
            $results[] = self::result('OPcache', $reset ? 'ok' : 'error', $reset
                ? __('This PHP runtime accepted the OPcache reset.', 'ys-cache')
                : __('OPcache reset is unavailable in this PHP runtime.', 'ys-cache'));
            if (!$reset) {
                return $results;
            }
        }

        if (in_array($scope, ['all', 'litespeed', 'urls'], true) && !empty($settings['litespeed']['enabled'])) {
            $result = YSCacheLiteSpeed::purge($scope === 'urls' ? $urls : []);
            $results[] = $result;
            if ($result['status'] !== 'ok') {
                return $results;
            }
        }
        if ($scope === 'litespeed' && empty($results)) {
            $results[] = self::result('LiteSpeed Cache', 'skipped', __('LiteSpeed Cache integration is disabled.', 'ys-cache'));
        }

        if (in_array($scope, ['all', 'nginx', 'urls'], true) && !empty($settings['nginx']['enabled'])) {
            $result = YSCacheNginx::purge($scope === 'urls' ? $urls : []);
            $results[] = $result;
            if ($result['status'] !== 'ok') {
                return $results;
            }
        }
        if ($scope === 'nginx' && empty($results)) {
            $results[] = self::result('Nginx', 'skipped', __('Nginx cache integration is disabled.', 'ys-cache'));
        }

        if (($scope === 'all' || $scope === 'enhance') && !empty($settings['enhance']['enabled'])) {
            $result = self::purgeEnhance((array) $settings['enhance']);
            $results[] = $result;
            if ($result['status'] === 'error') {
                return $results;
            }
        }

        if ($scope === 'enhance' && empty($results)) {
            $results[] = self::result('YS CLOUD', 'skipped', __('YS CLOUD purge is disabled.', 'ys-cache'));
        }

        if (!in_array($scope, ['all', 'urls', 'cdn'], true)) {
            return $results;
        }

        $site = self::urlParts(home_url('/'), []);
        if ($site === []) {
            return $results;
        }
        $targeted = $scope === 'urls' || ($scope === 'cdn' && $urls !== []);
        $sources = $targeted ? self::assetSources($site) : [];
        [$aliases, $dedicated, $conflict] = self::aliasTopology($settings, $site);
        if ($conflict) {
            $results[] = self::result('CDN', 'error', __('A site alias is assigned to more than one resource at the same CDN provider.', 'ys-cache'));
            return $results;
        }
        foreach ((array) ($settings['cdn'] ?? []) as $layer) {
            if (!is_array($layer) || empty($layer['enabled'])) {
                continue;
            }
            $html_hosts = self::htmlHosts($layer, $site, $aliases, $dedicated);
            $result = self::purgeCdn($layer, $site, $sources, $targeted, $urls, array_keys($aliases), $html_hosts);
            $results[] = $result;
            if ($result['status'] === 'error') {
                break;
            }
        }

        if ($scope === 'cdn' && empty($results)) {
            $results[] = self::result('CDN', 'skipped', __('No CDN purge integrations are enabled.', 'ys-cache'));
        }
        return $results;
    }

    private static function aliasTopology(array $settings, array $site): array
    {
        $aliases = array_fill_keys(self::normalizeAliasHosts($settings['alias_hosts'] ?? []), true);
        unset($aliases[$site['host']]);
        $dedicated = [];
        $conflict = false;
        foreach ((array) ($settings['cdn_topology'] ?? $settings['cdn'] ?? []) as $layer) {
            if (!is_array($layer) || empty($layer['enabled']) || !self::scopeSupportsHtml($layer)) {
                continue;
            }
            $host = self::hostname($layer['hostname'] ?? '');
            $provider = self::text($layer['provider'] ?? '');
            if ($host === '' || $host === $site['host'] || !isset(self::PROVIDER_LABELS[$provider]) || $provider === 'cname') {
                continue;
            }
            // Previously configured explicit HTML layers own their hostname without a separate alias entry.
            if (!self::isAliasLayer($layer, $aliases[$host] ?? false)) {
                continue;
            }
            $aliases[$host] ??= false;
            $resource = self::zoneId($provider, $layer['zone_id'] ?? '');
            if (isset($dedicated[$host][$provider]) && $dedicated[$host][$provider] !== $resource) {
                $conflict = true;
            }
            $dedicated[$host][$provider] = $resource;
        }
        return [$aliases, array_keys($dedicated), $conflict];
    }

    private static function htmlHosts(array $layer, array $site, array $aliases, array $dedicated): array
    {
        if (!self::scopeSupportsHtml($layer)) {
            return [];
        }
        $host = self::hostname($layer['hostname'] ?? '');
        if ($host === '' || $host === $site['host']) {
            return array_merge([$site['host']], array_values(array_diff(array_keys($aliases), $dedicated)));
        }
        return array_key_exists($host, $aliases) && self::isAliasLayer($layer, $aliases[$host]) ? [$host] : [];
    }

    private static function isAliasLayer(array $layer, bool $configured): bool
    {
        return $configured || (empty($layer['rewrite_urls']) && empty($layer['rewrite_images']));
    }

    public static function rewriteHtml(string $html, array $settings): string
    {
        $routes = self::routingHosts($settings);
        $site = self::urlParts(home_url('/'), []);
        if ($routes === [] || $site === [] || !class_exists('\WP_HTML_Tag_Processor')) {
            return $html;
        }

        $sources = self::assetSources($site);
        $rewrite = static function (string $url, string $kind) use ($routes, $site, $sources): string {
            $route = $routes[$kind];
            $parts = self::urlParts($url, $site);
            if ($parts === [] || $parts['host'] === $route['host'] || isset($parts['fragment'])) {
                return $url;
            }
            if (isset($parts['query']) && ($kind !== 'code' || !self::isVersionQuery($parts['query']))) {
                return $url;
            }
            $upload = $sources['upload'];
            $public = $route['legacy']
                ? $upload !== [] && self::sameOrigin($parts, $upload) && self::imagePath($parts['path'], $upload)
                : self::sameOrigin($parts, $site) && self::assetKind($parts['path'], $sources['roots']) === $kind;
            return $public ? 'https://' . $route['host'] . self::pathWithQuery($parts) : $url;
        };

        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_tag()) {
            $attributes = self::assetAttributes($processor);
            if ($attributes === [] || $processor->get_attribute('integrity') !== null
                || strtolower(trim((string) $processor->get_attribute('crossorigin'))) === 'use-credentials') {
                continue;
            }

            foreach ($attributes as $attribute => $kind) {
                if (!isset($routes[$kind])) {
                    continue;
                }
                $value = $processor->get_attribute($attribute);
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $updated = substr($attribute, -6) === 'srcset'
                    ? self::rewriteSrcset($value, static fn (string $url): string => $rewrite($url, $kind))
                    : $rewrite($value, $kind);
                if ($updated !== $value) {
                    $processor->set_attribute($attribute, $updated);
                }
            }
        }
        return $processor->get_updated_html();
    }

    private static function routingHosts(array $settings): array
    {
        $routes = [];
        foreach ((array) ($settings['cdn'] ?? []) as $layer) {
            if (!is_array($layer) || (empty($layer['rewrite_urls']) && empty($layer['rewrite_images']))) {
                continue;
            }
            $host = self::hostname($layer['hostname'] ?? '');
            if ($host === '') {
                continue;
            }
            $scope = self::scope($layer);
            foreach (array_keys(self::ASSET_EXTENSIONS) as $kind) {
                if (isset($routes[$kind]) || !self::scopeAllowsAsset($scope, $kind)) {
                    continue;
                }
                $legacy = empty($layer['rewrite_urls']);
                if (!$legacy || $kind === 'image') {
                    $routes[$kind] = ['host' => $host, 'legacy' => $legacy];
                }
            }
        }
        return $routes;
    }

    private static function scopeAllowsAsset(string $scope, string $kind): bool
    {
        return in_array($scope, ['all', 'static'], true)
            || ($kind === 'code' && in_array($scope, ['code', 'html_code'], true));
    }

    private static function assetAttributes(\WP_HTML_Tag_Processor $tag): array
    {
        return match ($tag->get_tag()) {
            'IMG' => array_fill_keys(['src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'data-original'], 'image'),
            'SOURCE' => ['srcset' => 'image', 'data-srcset' => 'image', 'data-lazy-srcset' => 'image', 'src' => 'media'],
            'SCRIPT' => ['src' => 'code'],
            'VIDEO' => ['src' => 'media', 'poster' => 'image'],
            'AUDIO', 'TRACK' => ['src' => 'media'],
            'LINK' => self::linkAttributes($tag),
            default => [],
        };
    }

    private static function linkAttributes(\WP_HTML_Tag_Processor $tag): array
    {
        $relations = preg_split('/\s+/', strtolower(trim((string) $tag->get_attribute('rel'))));
        if (array_intersect($relations, ['stylesheet', 'modulepreload'])) {
            return ['href' => 'code'];
        }
        if (array_intersect($relations, ['icon', 'apple-touch-icon', 'mask-icon'])) {
            return ['href' => 'image'];
        }
        if (!in_array('preload', $relations, true)) {
            return [];
        }
        return match (strtolower((string) $tag->get_attribute('as'))) {
            'image' => ['href' => 'image', 'imagesrcset' => 'image'],
            'style', 'script' => ['href' => 'code'],
            'font' => ['href' => 'font'],
            'audio', 'video' => ['href' => 'media'],
            default => [],
        };
    }

    private static function purgeEnhance(array $settings): array
    {
        $label = 'YS CLOUD';
        $connection = self::enhanceConnection($settings);
        if ($connection === null) {
            return self::result($label, 'error', __('Enter an HTTPS control panel URL, a domain UUID, and an API token.', 'ys-cache'));
        }

        return self::request($label, 'DELETE', $connection['endpoint'] . '/nginx_fastcgi', $connection['headers']);
    }

    /** @internal Returns request credentials for the configured domain. */
    public static function enhanceConnection(array $settings): ?array
    {
        if (defined('YS_CACHE_ENHANCE_TOKEN')) {
            // Bind config-managed credentials to a config-managed panel and domain.
            $base = defined('YS_CACHE_ENHANCE_URL') ? self::enhanceBase(YS_CACHE_ENHANCE_URL) : '';
            $domain = defined('YS_CACHE_ENHANCE_DOMAIN_ID') ? self::uuid(YS_CACHE_ENHANCE_DOMAIN_ID) : '';
            $org = defined('YS_CACHE_ENHANCE_ORG_ID') ? self::uuid(YS_CACHE_ENHANCE_ORG_ID) : '';
            $token = self::token(YS_CACHE_ENHANCE_TOKEN);
        } else {
            $base = self::enhanceBase($settings['url'] ?? '');
            $domain = self::uuid($settings['domain_id'] ?? '');
            $org = self::uuid($settings['org_id'] ?? '');
            $token = self::token($settings['token'] ?? '');
            if (defined('YS_CACHE_ENHANCE_URL') && $base !== self::enhanceBase(YS_CACHE_ENHANCE_URL)) {
                $token = '';
            }
        }
        if ($base === '' || $domain === '' || $token === '') {
            return null;
        }

        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($org !== '') {
            $headers['orgId'] = $org;
        }
        return ['endpoint' => $base . '/v2/domains/' . $domain, 'headers' => $headers];
    }

    private static function purgeCdn(array $layer, array $site, array $sources, bool $targeted, array $urls, array $aliases, array $html_hosts): array
    {
        $provider = $layer['provider'] ?? '';
        if (!is_string($provider) || !isset(self::PROVIDER_LABELS[$provider]) || $provider === 'cname') {
            return self::result('CDN', 'error', __('Unknown CDN provider.', 'ys-cache'));
        }

        $hostname = self::hostname($layer['hostname'] ?? '');
        $label = self::PROVIDER_LABELS[$provider] . ($hostname !== '' ? ' (' . $hostname . ')' : '');
        if (self::scopeSupportsHtml($layer) && $html_hosts === [] && (self::scope($layer) === 'html'
            || (empty($layer['rewrite_urls']) && empty($layer['rewrite_images'])))) {
            return self::result($label, 'error', __('Add the CDN website hostname to the site aliases before purging this layer.', 'ys-cache'));
        }
        $files = $targeted ? self::layerUrls($urls, $site, $sources, $hostname, $layer, $aliases, $html_hosts) : [];
        if ($targeted && $files === []) {
            return self::result($label, 'skipped', __('No URLs apply to this CDN layer.', 'ys-cache'));
        }
        $hosts = $targeted
            ? array_values(array_unique(array_map(static fn (string $url): string => strtolower((string) wp_parse_url($url, PHP_URL_HOST)), $files)))
            : ($html_hosts !== [] ? $html_hosts : ($hostname !== '' ? [$hostname] : []));
        $zone = self::zoneId($provider, $layer['zone_id'] ?? '');
        $token = self::cdnToken($layer);
        if ($zone === '' || $token === '' || $hosts === []) {
            return self::result($label, 'error', __('CDN purge needs a valid resource ID, credentials, and a hostname for asset-only layers.', 'ys-cache'));
        }
        $includes_alias = array_intersect($hosts, $aliases) !== [];

        if ($provider === 'cloudflare') {
            $email = sanitize_email(self::text($layer['auth_email'] ?? ''));
            $headers = $email === ''
                ? ['Authorization' => 'Bearer ' . $token]
                : ['X-Auth-Key' => $token, 'X-Auth-Email' => $email];
            $resource_url = 'https://api.cloudflare.com/client/v4/zones/' . $zone;
            if ($includes_alias) {
                $resource = self::readCdnResource($label, $resource_url, $headers);
                if ($resource['status'] === 'error') {
                    return $resource;
                }
                $document = $resource['document'];
                $zone_info = is_array($document['result'] ?? null) ? $document['result'] : [];
                $zone_name = self::hostname($zone_info['name'] ?? '');
                if (($document['success'] ?? false) !== true || !empty($document['errors']) || ($zone_info['id'] ?? '') !== $zone
                    || ($zone_info['status'] ?? '') !== 'active' || $zone_name === ''
                    || array_filter($hosts, static fn (string $host): bool => $host !== $zone_name && !str_ends_with($host, '.' . $zone_name))) {
                    return self::result($label, 'error', __('Cloudflare could not verify every configured alias in this zone. Use a separate CDN layer for aliases in another zone.', 'ys-cache'));
                }
            }
            $endpoint = $resource_url . '/purge_cache';
            if (!$targeted && !empty($layer['purge_entire_zone'])) {
                return self::request($label, 'POST', $endpoint, $headers, ['purge_everything' => true], 200, $provider);
            }
            $key = $targeted ? 'files' : 'hosts';
            return self::purgeBatches($label, $targeted ? $files : $hosts, 100,
                static fn (array $batch): array => self::request($label, 'POST', $endpoint, $headers, [$key => $batch], 200, $provider));
        }

        if ($provider === 'gcore') {
            $headers = ['Authorization' => 'APIKey ' . $token];
            $resource_url = 'https://api.gcore.com/cdn/resources/' . $zone;
            if ($includes_alias) {
                $resource = self::readCdnResource($label, $resource_url, $headers);
                if ($resource['status'] === 'error') {
                    return $resource;
                }
                $document = $resource['document'];
                $configured = self::normalizeAliasHosts(array_merge([$document['cname'] ?? ''], (array) ($document['secondaryHostnames'] ?? [])));
                if (self::text($document['id'] ?? '') !== $zone || !empty($document['deleted']) || !empty($document['suspended'])
                    || ($document['active'] ?? true) === false || ($document['enabled'] ?? true) === false
                    || ($document['status'] ?? 'active') !== 'active' || array_diff($hosts, $configured) !== []) {
                    return self::result($label, 'error', __('Gcore could not verify every configured alias on this CDN resource.', 'ys-cache'));
                }
            }
            $endpoint = $resource_url . '/purge';
            if (!$targeted) {
                return self::request($label, 'POST', $endpoint, $headers, ['paths' => []], 201, $provider);
            }
            $paths = array_values(array_unique(array_map([self::class, 'pathAndQuery'], $files)));
            return self::purgeBatches($label, $paths, 100,
                static fn (array $batch): array => self::request($label, 'POST', $endpoint, $headers, ['urls' => $batch], 201, $provider));
        }

        $headers = ['AccessKey' => $token];
        if (count($files) > self::MAX_BUNNY_URLS) {
            return self::result($label, 'error', sprintf(__('This layer supports up to %d URLs per purge. Submit smaller batches.', 'ys-cache'), self::MAX_BUNNY_URLS));
        }
        $resource_url = 'https://api.bunny.net/pullzone/' . $zone;
        $resource = self::readCdnResource($label, $resource_url, $headers);
        if ($resource['status'] === 'error') {
            return $resource;
        }
        $document = $resource['document'];
        $configured = self::normalizeAliasHosts(array_column((array) ($document['Hostnames'] ?? []), 'Value'));
        if (self::text($document['Id'] ?? '') !== $zone || ($document['Enabled'] ?? true) === false
            || !empty($document['Suspended']) || array_diff($hosts, $configured) !== []) {
            return self::result($label, 'error', __('Bunny CDN could not verify every requested hostname on this Pull Zone.', 'ys-cache'));
        }
        if (!$targeted) {
            return self::request($label, 'POST', $resource_url . '/purgeCache', $headers, [], 204);
        }

        $directoryUrls = array_filter($files, static fn (string $url): bool => str_ends_with((string) wp_parse_url($url, PHP_URL_PATH), '/'));
        // With IgnoreQueryStrings enabled, Bunny can broaden a slash URL to a wildcard purge.
        if ($directoryUrls !== [] && ($document['IgnoreQueryStrings'] ?? null) !== false) {
            return self::result($label, 'error', __('Bunny CDN could not verify an exact purge for this URL. Check its Pull Zone hostname and Ignore Query Strings setting.', 'ys-cache'));
        }

        // Bunny takes one URL per API call.
        return self::purgeBatches($label, $files, 1, static function (array $batch) use ($label, $headers): array {
            $query = http_build_query(['url' => $batch[0], 'exactPath' => 'true', 'async' => 'false'], '', '&', PHP_QUERY_RFC3986);
            return self::request($label, 'POST', 'https://api.bunny.net/purge?' . $query, $headers, null, 200);
        });
    }

    private static function readCdnResource(string $label, string $url, array $headers): array
    {
        $response = wp_safe_remote_request($url, [
            'method' => 'GET',
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 524288,
            'headers' => $headers + ['Accept' => 'application/json'],
        ]);
        $message = __('The CDN resource could not be verified. Check the configured resource and its read permission.', 'ys-cache');
        if (is_wp_error($response)) {
            return self::result($label, 'error', $message) + ['retryable' => true];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $document = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($document)
            || stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === 0) {
            return self::result($label, 'error', $message, $status);
        }
        return ['status' => 'ok', 'document' => $document];
    }

    private static function purgeBatches(string $label, array $items, int $size, callable $send): array
    {
        if ($items === []) {
            return self::result($label, 'skipped', __('No URLs apply to this CDN layer.', 'ys-cache'));
        }
        $batches = array_chunk($items, $size);
        if (count($batches) > self::MAX_PURGE_BATCHES) {
            return self::result($label, 'error', __('This CDN purge requires too many requests. Submit smaller batches.', 'ys-cache'));
        }
        foreach ($batches as $index => $batch) {
            $result = $send($batch);
            if ($result['status'] !== 'ok') {
                if ($index > 0) {
                    $result['message'] = sprintf(__('CDN purge stopped after %1$d of %2$d requests. %3$s', 'ys-cache'), $index, count($batches), $result['message']);
                }
                return $result;
            }
        }
        return $result;
    }

    private static function cdnToken(array $layer): string
    {
        $token = self::token($layer['token'] ?? '');
        if (($layer['provider'] ?? '') === 'cloudflare' && $token === ''
            && ($layer['token_constant'] ?? '') === 'WP_ROCKET_CF_API_KEY' && defined('WP_ROCKET_CF_API_KEY')) {
            return self::token(WP_ROCKET_CF_API_KEY);
        }
        return $token;
    }

    private static function request(string $label, string $method, string $url, array $headers, ?array $payload = null, ?int $expected = null, string $provider = ''): array
    {
        $headers['Accept'] = 'application/json';
        $args = [
            'method' => $method,
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => $provider === 'gcore' ? 524288 : 32768,
            'headers' => $headers,
        ];
        if ($payload !== null) {
            $body = wp_json_encode((object) $payload);
            if (!is_string($body)) {
                return self::result($label, 'error', __('Unable to encode the purge request.', 'ys-cache'));
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $body;
        }

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            return self::result($label, 'error', __('The purge request could not reach the provider.', 'ys-cache'))
                + ['retryable' => true];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300 || ($expected !== null && $status !== $expected)
            || stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === 0) {
            return self::result($label, 'error', sprintf(__('Purge returned HTTP %d.', 'ys-cache'), $status), $status);
        }
        if ($provider === 'cloudflare') {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (($body['success'] ?? false) !== true || !empty($body['errors'])) {
                return self::result($label, 'error', __('Cloudflare did not accept the purge.', 'ys-cache'), $status);
            }
        } elseif ($provider === 'gcore') {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $key = array_key_first($payload ?? []);
            $reported = $body[$key] ?? null;
            $submitted = $payload[$key] ?? [];
            if (is_array($reported)) {
                sort($reported);
                sort($submitted);
            }
            if ($reported !== $submitted) {
                return self::result($label, 'error', __('Gcore did not confirm every requested purge path.', 'ys-cache'), $status);
            }
        }
        return self::result($label, 'ok', __('The provider accepted the purge.', 'ys-cache'), $status);
    }

    private static function result(string $layer, string $status, string $message, int $http = 0): array
    {
        return compact('layer', 'status', 'message', 'http');
    }

    private static function layerUrls(array $urls, array $site, array $sources, string $hostname, array $layer, array $aliases, array $html_hosts): array
    {
        $files = [];
        $scope = self::scope($layer);
        $supportsHtml = self::scopeSupportsHtml($layer);
        $legacyImages = !isset($layer['scope']) && ($layer['role'] ?? '') === 'images';
        foreach ($urls as $url) {
            $parts = self::urlParts(self::text($url), $site);
            if ($parts === [] || strpos(rawurldecode($parts['path'] . ($parts['query'] ?? '')), '*') !== false) {
                continue;
            }
            $is_cdn = $hostname !== '' && $parts['host'] === $hostname && $parts['scheme'] === 'https' && $parts['port'] === 443;
            $site_origin = self::sameOrigin($parts, $site) || (in_array($parts['host'], $aliases, true)
                && $parts['scheme'] === $site['scheme'] && $parts['port'] === $site['port']);
            $decoded = self::publicPath($parts['path']);
            if ($decoded === null) {
                continue;
            }
            $kind = self::extensionKind($decoded);
            if ($legacyImages) {
                $upload = $sources['upload'];
                if ($upload === [] || (!self::sameHost($parts, $upload) && !$is_cdn)
                    || !self::imagePath($parts['path'], $upload)) {
                    continue;
                }
            } elseif ((!$site_origin && !$is_cdn)
                || ($kind === null && (!$site_origin || $html_hosts === []))
                || ($kind !== null && !self::scopeAllowsAsset($scope, $kind))
                || ($kind !== null && (!$supportsHtml || $html_hosts === []) && self::assetKind($parts['path'], $sources['roots']) === null)) {
                continue;
            }

            if ($kind === null || ($site_origin && $html_hosts !== [] && ($hostname === '' || in_array($hostname, $html_hosts, true)))) {
                foreach ($html_hosts as $host) {
                    $files[] = self::origin(array_replace($site, ['host' => $host])) . self::pathWithQuery($parts);
                }
            } else {
                $origin = $hostname === '' ? self::origin($parts) : 'https://' . $hostname;
                $files[] = $origin . self::pathWithQuery($parts);
            }
        }
        return array_values(array_unique($files));
    }

    private static function pathAndQuery(string $url): string
    {
        $parts = wp_parse_url($url);
        return self::pathWithQuery($parts ?: []);
    }

    private static function pathWithQuery(array $parts): string
    {
        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function origin(array $parts): string
    {
        $port = $parts['port'] !== self::defaultPort($parts['scheme']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    private static function uploadSource(): array
    {
        $uploads = wp_get_upload_dir();
        return self::urlParts(self::text($uploads['baseurl'] ?? ''), []);
    }

    private static function assetSources(array $site): array
    {
        $upload = self::uploadSource();
        $sources = [$upload];
        if (function_exists('content_url')) {
            foreach (['plugins', 'themes', 'mu-plugins'] as $directory) {
                $sources[] = self::urlParts(content_url('/' . $directory . '/'), []);
            }
        }
        if (function_exists('includes_url')) {
            $sources[] = self::urlParts(includes_url(), []);
        }
        $roots = [];
        foreach ($sources as $source) {
            if ($source !== [] && self::sameOrigin($source, $site)) {
                $path = rtrim(rawurldecode($source['path']), '/');
                if ($path !== '') {
                    $roots[] = ['path' => $path . '/', 'upload' => $source === $upload];
                }
            }
        }
        return compact('upload', 'roots');
    }

    private static function assetKind(string $path, array $roots): ?string
    {
        $decoded = self::publicPath($path);
        if ($decoded === null) {
            return null;
        }
        foreach ($roots as $root) {
            if (str_starts_with($decoded, $root['path'])) {
                if ($root['upload'] && self::privateUpload(substr($decoded, strlen($root['path'])))) {
                    return null;
                }
                return self::extensionKind($decoded);
            }
        }
        return null;
    }

    private static function extensionKind(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach (self::ASSET_EXTENSIONS as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }
        return null;
    }

    private static function publicPath(string $path): ?string
    {
        $decoded = rawurldecode($path);
        if (preg_match('~%(?:2f|5c|25)~i', $path) || preg_match('~//|[\x00-\x1f\x7f\\\\]|/(?:\.[^/]*|private|protected|woocommerce_uploads|wc-logs)(?:/|$)~i', $decoded)) {
            return null;
        }
        return $decoded;
    }

    private static function isVersionQuery(string $query): bool
    {
        return str_starts_with($query, 'ver=') && (bool) preg_match('/\A[A-Za-z0-9._~+-]{1,128}\z/D', rawurldecode(substr($query, 4)));
    }

    private static function urlParts(string $url, array $site): array
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return [];
        }

        if (strpos($url, '//') === 0 && !empty($site['scheme'])) {
            $url = $site['scheme'] . ':' . $url;
        } elseif ($url[0] === '/' && !empty($site['host']) && !empty($site['scheme'])) {
            $url = $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return [];
        }

        $parts['scheme'] = strtolower($parts['scheme'] ?? '');
        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return [];
        }
        $parts['host'] = strtolower($parts['host']);
        $parts['path'] = $parts['path'] ?? '/';
        $parts['port'] = $parts['port'] ?? self::defaultPort($parts['scheme']);
        return $parts;
    }

    private static function sameHost(array $first, array $second): bool
    {
        return $first['host'] === strtolower($second['host'])
            && $first['port'] === ($second['port'] ?? self::defaultPort($second['scheme'] ?? ''));
    }

    private static function sameOrigin(array $first, array $second): bool
    {
        return self::sameHost($first, $second) && $first['scheme'] === $second['scheme'];
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function imagePath(string $path, array $upload): bool
    {
        $base = rtrim(rawurldecode($upload['path']), '/') . '/';
        $decoded = self::publicPath($path);
        if ($decoded === null || !str_starts_with($decoded, $base)) {
            return false;
        }

        $relative = substr($decoded, strlen($base));
        return !self::privateUpload($relative) && (bool) preg_match('/\.(?:avif|gif|ico|jpe?g|png|webp)$/iD', $relative);
    }

    private static function privateUpload(string $relative): bool
    {
        return (bool) preg_match('~(?:^|/)(?:ys-cart[^/]*|ys-ecommerce[^/]*|backup|backups)(?:/|$)~i', $relative);
    }

    private static function rewriteSrcset(string $srcset, callable $rewrite): string
    {
        // Support ordinary WordPress candidates while preserving data URI and other complex srcsets intact.
        if (preg_match('/(?:^|,)\s*data:/i', $srcset)) {
            return $srcset;
        }

        $candidates = [];
        foreach (explode(',', $srcset) as $candidate) {
            if (!preg_match('/^(\s*)(\S+)(\s+(?:[0-9]+w|[0-9]*\.?[0-9]+x))?(\s*)$/D', $candidate, $parts)) {
                return $srcset;
            }
            $candidates[] = $parts[1] . $rewrite($parts[2]) . ($parts[3] ?? '') . $parts[4];
        }
        return implode(',', $candidates);
    }

    private static function credentialKey(array $layer): string
    {
        return implode('|', [
            self::text($layer['provider'] ?? ''),
            self::text($layer['zone_id'] ?? ''),
            self::text($layer['auth_email'] ?? ''),
            self::text($layer['hostname'] ?? ''),
        ]);
    }

    private static function savedToken(array $submitted, $previous): string
    {
        if (!empty($submitted['clear_token'])) {
            return '';
        }
        $token = self::text($submitted['token'] ?? '');
        return $token === '' ? self::token($previous) : self::token($token);
    }

    private static function token($value): string
    {
        $value = self::text($value);
        return preg_match('/^[\x21-\x7e]{1,2048}$/D', $value) ? $value : '';
    }

    private static function zoneId(string $provider, $value): string
    {
        $value = self::text($value);
        if ($provider === 'cloudflare') {
            return preg_match('/^[a-f0-9]{32}$/iD', $value) ? strtolower($value) : '';
        }
        return in_array($provider, ['gcore', 'bunny'], true) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) ? $value : '';
    }

    private static function uuid($value): string
    {
        $value = self::text($value);
        return preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value) ? strtolower($value) : '';
    }

    private static function enhanceBase($value): string
    {
        $value = self::text($value);
        if (!preg_match('~^https://([^/]+)(?:/api)?/?$~iD', $value, $parts)) {
            return '';
        }

        $hostname = self::hostname($parts[1]);
        return $hostname === '' ? '' : 'https://' . $hostname . '/api';
    }

    private static function hostname($value): string
    {
        $value = strtolower(self::text($value));
        if (strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP)
            || !preg_match('/\.[a-z][a-z0-9-]*$/D', $value)
            || !filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return '';
        }
        return $value;
    }

    private static function text($value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
