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

    public static function sanitize(array $input, array $previous = []): array
    {
        $submitted_enhance = (array) ($input['enhance'] ?? []);
        $previous_enhance = (array) ($previous['enhance'] ?? []);
        $enhance_enabled = !empty($submitted_enhance['enabled']);
        $delegate_html = $enhance_enabled && !empty($submitted_enhance['delegate_html']);
        $enhance = [
            'enabled' => $enhance_enabled,
            'auto_purge' => $enhance_enabled && ($delegate_html || !empty($submitted_enhance['auto_purge'])),
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
                'auto_purge' => $name !== 'cname' && !empty($row['enabled']) && !empty($row['auto_purge']),
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
            $layer['html_cache_verified'] = !empty($row['html_cache_verified']) && self::canVerifyHtml($layer, $row['hostname'] ?? '');
            $layer['auto_purge'] = $layer['auto_purge'] || $layer['html_cache_verified'];

            if ($layer['enabled'] || $layer['zone_id'] !== '' || $hostname !== '') {
                $cdn[] = $layer;
            }
        }

        return [
            'opcache' => ['enabled' => !empty($input['opcache']['enabled'])],
            'enhance' => $enhance,
            'cdn' => $cdn,
        ];
    }

    private static function canVerifyHtml(array $layer, $submittedHostname): bool
    {
        if (!$layer['enabled'] || $layer['zone_id'] === '' || !self::scopeSupportsHtml($layer) || self::cdnToken($layer) === '') {
            return false;
        }
        $site = self::urlParts(home_url('/'), []);
        return $site !== [] && ($layer['hostname'] === $site['host']
            || ($layer['hostname'] === '' && self::text($submittedHostname) === ''));
    }

    public static function purge(array $settings, string $scope = 'all', array $urls = []): array
    {
        if (!in_array($scope, ['all', 'urls', 'opcache', 'enhance', 'cdn'], true)) {
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

        if (($scope === 'all' || $scope === 'enhance') && !empty($settings['enhance']['enabled'])) {
            $result = self::purgeEnhance((array) $settings['enhance']);
            $results[] = $result;
            if ($result['status'] === 'error') {
                return $results;
            }
        }

        if ($scope === 'enhance' && empty($results)) {
            $results[] = self::result('Enhance Nginx', 'skipped', __('Enhance purge is disabled.', 'ys-cache'));
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
        foreach ((array) ($settings['cdn'] ?? []) as $layer) {
            if (!is_array($layer) || empty($layer['enabled'])) {
                continue;
            }
            $result = self::purgeCdn($layer, $site, $sources, $targeted, $urls);
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
        $label = 'Enhance Nginx';
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

    private static function purgeCdn(array $layer, array $site, array $sources, bool $targeted, array $urls): array
    {
        $provider = $layer['provider'] ?? '';
        if (!is_string($provider) || !isset(self::PROVIDER_LABELS[$provider]) || $provider === 'cname') {
            return self::result('CDN', 'error', __('Unknown CDN provider.', 'ys-cache'));
        }

        $hostname = self::hostname($layer['hostname'] ?? '');
        $label = self::PROVIDER_LABELS[$provider] . ($hostname !== '' ? ' (' . $hostname . ')' : '');
        $files = $targeted ? self::layerUrls($urls, $site, $sources, $hostname, $layer) : [];
        if ($targeted && $files === []) {
            return self::result($label, 'skipped', __('No URLs apply to this CDN layer.', 'ys-cache'));
        }
        $zone = self::zoneId($provider, $layer['zone_id'] ?? '');
        $token = self::cdnToken($layer);
        if ($zone === '' || $token === '' || (!self::scopeSupportsHtml($layer) && $hostname === '')) {
            return self::result($label, 'error', __('CDN purge needs a valid resource ID, credentials, and a hostname for asset-only layers.', 'ys-cache'));
        }
        $limit = $provider === 'bunny' ? 5 : 100;
        if (count($files) > $limit) {
            return self::result($label, 'error', sprintf(__('This layer supports up to %d URLs per purge. Submit smaller batches.', 'ys-cache'), $limit));
        }

        if ($provider === 'cloudflare') {
            $email = sanitize_email(self::text($layer['auth_email'] ?? ''));
            $headers = $email === ''
                ? ['Authorization' => 'Bearer ' . $token]
                : ['X-Auth-Key' => $token, 'X-Auth-Email' => $email];
            if ($targeted) {
                $payload = ['files' => $files];
            } elseif (!empty($layer['purge_entire_zone'])) {
                $payload = ['purge_everything' => true];
            } else {
                $payload = ['hosts' => [$hostname !== '' ? $hostname : strtolower($site['host'])]];
            }
            return self::request($label, 'POST', 'https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache', $headers, $payload, true);
        }

        if ($provider === 'gcore') {
            $payload = $targeted ? ['urls' => array_values(array_unique(array_map([self::class, 'pathAndQuery'], $files)))] : ['paths' => []];
            return self::request(
                $label,
                'POST',
                'https://api.gcore.com/cdn/resources/' . $zone . '/purge',
                ['Authorization' => 'APIKey ' . $token],
                $payload
            );
        }

        $headers = ['AccessKey' => $token];
        if (!$targeted) {
            return self::request($label, 'POST', 'https://api.bunny.net/pullzone/' . $zone . '/purgeCache', $headers, []);
        }

        // Bunny takes one URL per API call.
        foreach ($files as $url) {
            $query = http_build_query(['url' => $url, 'exactPath' => 'true'], '', '&', PHP_QUERY_RFC3986);
            $result = self::request($label, 'POST', 'https://api.bunny.net/purge?' . $query, $headers);
            if ($result['status'] === 'error') {
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

    private static function request(string $label, string $method, string $url, array $headers, ?array $payload = null, bool $cloudflare = false): array
    {
        $headers['Accept'] = 'application/json';
        $args = [
            'method' => $method,
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 32768,
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
        if ($status < 200 || $status >= 300 || stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === 0) {
            return self::result($label, 'error', sprintf(__('Purge returned HTTP %d.', 'ys-cache'), $status), $status);
        }
        if ($cloudflare) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (($body['success'] ?? false) !== true) {
                return self::result($label, 'error', __('Cloudflare did not accept the purge.', 'ys-cache'), $status);
            }
        }
        return self::result($label, 'ok', __('The provider accepted the purge.', 'ys-cache'), $status);
    }

    private static function result(string $layer, string $status, string $message, int $http = 0): array
    {
        return compact('layer', 'status', 'message', 'http');
    }

    private static function layerUrls(array $urls, array $site, array $sources, string $hostname, array $layer): array
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
            $is_cdn = $hostname !== '' && $parts['host'] === $hostname && $parts['port'] === 443;
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
            } elseif ((!self::sameOrigin($parts, $site) && !$is_cdn)
                || ($kind === null && !$supportsHtml)
                || ($kind !== null && !self::scopeAllowsAsset($scope, $kind))
                || ($kind !== null && !$supportsHtml && self::assetKind($parts['path'], $sources['roots']) === null)) {
                continue;
            }

            $origin = $kind === null ? self::origin($site) : ($hostname === '' ? self::origin($parts) : 'https://' . $hostname);
            $files[] = $origin . self::pathWithQuery($parts);
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
