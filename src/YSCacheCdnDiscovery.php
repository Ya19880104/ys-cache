<?php
/**
 * Read-only discovery of existing CDN resources.
 *
 * @package YangSheep\Cache
 */

namespace YangSheep\Cache;

defined('ABSPATH') || exit;

final class YSCacheCdnDiscovery
{
    private const BASE_URLS = [
        'cloudflare' => 'https://api.cloudflare.com/client/v4/zones',
        'bunny' => 'https://api.bunny.net/pullzone',
        'gcore' => 'https://api.gcore.com/cdn/resources',
    ];

    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 4;

    public static function discover(array $row, array $saved = []): array
    {
        $provider = $row['provider'] ?? '';
        if (!is_string($provider) || !isset(self::BASE_URLS[$provider])) {
            return self::error(__('Select Cloudflare, Bunny CDN, or Gcore to look up an existing resource.', 'ys-cache'));
        }

        // Only a previously imported, matching settings row can reference the Rocket key.
        unset($row['token_constant']);
        $row['enabled'] = true;
        $settings = YSCacheProviders::sanitize(['cdn' => [$row]], ['cdn' => [$saved]]);
        $layer = $settings['cdn'][0];
        $fields = $provider === 'cloudflare' ? ['zone_id', 'hostname', 'auth_email'] : ['zone_id', 'hostname'];
        foreach ($fields as $field) {
            $value = $row[$field] ?? '';
            if ((!is_string($value) || trim($value) !== '') && $layer[$field] === '') {
                return self::error(__('Check the resource ID, CDN hostname, and Cloudflare email format.', 'ys-cache'));
            }
        }

        $token = $layer['token'];
        $submitted_token = $row['token'] ?? '';
        if ($token === '' && (!is_string($submitted_token) || trim($submitted_token) !== '')) {
            return self::error(__('Enter the provider API credential to look up this resource.', 'ys-cache'));
        }
        if ($token === '' && ($layer['token_constant'] ?? '') === 'WP_ROCKET_CF_API_KEY' && defined('WP_ROCKET_CF_API_KEY')) {
            $token = constant('WP_ROCKET_CF_API_KEY');
        }
        if (!is_string($token) || !preg_match('/^[\x21-\x7e]{1,2048}$/D', $token)) {
            return self::error(__('Enter the provider API credential to look up this resource.', 'ys-cache'));
        }

        $headers = match ($provider) {
            'bunny' => ['AccessKey' => $token],
            'gcore' => ['Authorization' => 'APIKey ' . $token],
            default => $layer['auth_email'] === ''
                ? ['Authorization' => 'Bearer ' . $token]
                : ['X-Auth-Email' => $layer['auth_email'], 'X-Auth-Key' => $token],
        };

        $hostname = $layer['hostname'];
        $id = $layer['zone_id'];
        if ($hostname === '' && $layer['role'] === 'images') {
            return self::error(__('Enter the CDN delivery hostname for this asset layer.', 'ys-cache'));
        }
        if ($hostname === '' && $layer['role'] === 'site') {
            $hostname = self::hostname(wp_parse_url(home_url('/'), PHP_URL_HOST));
        }
        if ($hostname === '') {
            return self::error(__('Enter a valid CDN delivery hostname.', 'ys-cache'));
        }
        if ($id !== '') {
            return self::byId($provider, $id, $hostname, $headers);
        }

        return $provider === 'cloudflare'
            ? self::cloudflareByName($hostname, $headers)
            : self::byDeliveryHostname($provider, $hostname, $headers);
    }

    private static function byId(string $provider, string $id, string $hostname, array $headers): array
    {
        $reply = self::request(self::BASE_URLS[$provider] . '/' . $id, $headers);
        if ($reply['status'] !== 'ok') {
            return $reply;
        }

        $item = $reply['body'];
        if ($provider === 'cloudflare') {
            if (($item['success'] ?? false) !== true || !empty($item['errors']) || !is_array($item['result'] ?? null)) {
                return self::invalidReply($reply['http']);
            }
            $item = $item['result'];
        }
        $resource = self::matchResource($provider, $item, $hostname);
        if ($resource === null || $resource['id'] !== $id) {
            return self::noMatch();
        }
        return self::success($resource);
    }

    private static function cloudflareByName(string $hostname, array $headers): array
    {
        $name = $hostname;
        for ($attempt = 0; $attempt < 5 && str_contains($name, '.'); $attempt++) {
            $url = self::BASE_URLS['cloudflare'] . '?' . http_build_query(['name' => $name, 'status' => 'active', 'per_page' => 50]);
            $reply = self::request($url, $headers);
            if ($reply['status'] !== 'ok') {
                return $reply;
            }
            $body = $reply['body'];
            if (($body['success'] ?? false) !== true || !empty($body['errors']) || !is_array($body['result'] ?? null)) {
                return self::invalidReply($reply['http']);
            }

            $matches = [];
            foreach ($body['result'] as $item) {
                if (!is_array($item) || self::hostname($item['name'] ?? '') !== $name) {
                    continue;
                }
                $resource = self::matchResource('cloudflare', $item, $hostname);
                if ($resource !== null) {
                    $matches[$resource['id']] = $resource;
                }
            }
            if (count($matches) > 1 || (int) ($body['result_info']['total_pages'] ?? 0) > 1) {
                return self::ambiguous();
            }
            if ($matches !== []) {
                return self::success(reset($matches));
            }
            $name = substr($name, strpos($name, '.') + 1);
        }
        return self::noMatch();
    }

    private static function byDeliveryHostname(string $provider, string $hostname, array $headers): array
    {
        $matches = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $offset = ($page - 1) * self::PAGE_SIZE;
            $query = $provider === 'bunny'
                ? ['page' => $page, 'perPage' => self::PAGE_SIZE]
                : ['deleted' => 'false', 'limit' => self::PAGE_SIZE, 'offset' => $offset];
            $reply = self::request(self::BASE_URLS[$provider] . '?' . http_build_query($query), $headers);
            if ($reply['status'] !== 'ok') {
                return $reply;
            }

            $body = $reply['body'];
            $items = $provider === 'bunny' ? ($body['Items'] ?? null) : ($body['results'] ?? null);
            $more = $provider === 'bunny' ? ($body['HasMoreItems'] ?? null) : null;
            if ($provider === 'gcore' && is_array($items) && is_int($body['count'] ?? null)) {
                $more = $offset + count($items) < $body['count'];
            } elseif ($provider === 'gcore' && array_values($body) === $body) {
                $items = $body;
                $more = count($items) === self::PAGE_SIZE;
            }
            if (!is_array($items) || !is_bool($more)) {
                return self::invalidReply($reply['http']);
            }

            foreach ($items as $item) {
                $resource = self::matchResource($provider, $item, $hostname);
                if ($resource !== null) {
                    $matches[$resource['id']] = $resource;
                }
            }
            if (count($matches) > 1) {
                return self::ambiguous();
            }
            if (!$more) {
                return $matches === [] ? self::noMatch() : self::success(reset($matches));
            }
        }

        return self::error(__('The provider has too many resources to look up automatically. Enter the resource ID and verify it directly.', 'ys-cache'));
    }

    private static function matchResource(string $provider, $item, string $hostname): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $raw_id = $item[$provider === 'bunny' ? 'Id' : 'id'] ?? '';
        $id = is_string($raw_id) || is_int($raw_id) ? (string) $raw_id : '';
        $valid_id = $provider === 'cloudflare'
            ? preg_match('/^[a-f0-9]{32}$/iD', $id)
            : preg_match('/^[1-9][0-9]{0,18}$/D', $id);
        if (!$valid_id) {
            return null;
        }

        if ($provider === 'cloudflare') {
            $name = self::hostname($item['name'] ?? '');
            if ($name === '' || ($item['status'] ?? '') !== 'active'
                || ($hostname !== '' && $hostname !== $name && !str_ends_with($hostname, '.' . $name))) {
                return null;
            }
        } elseif ($provider === 'bunny') {
            $name = $item['Name'] ?? '';
            $hosts = [];
            foreach ((array) ($item['Hostnames'] ?? []) as $host) {
                if (is_array($host)) {
                    $hosts[] = self::hostname($host['Value'] ?? '');
                }
            }
            if (($item['Enabled'] ?? true) === false || !empty($item['Suspended'])
                || ($hostname !== '' && !in_array($hostname, $hosts, true))) {
                return null;
            }
        } else {
            $name = $item['name'] ?? $item['cname'] ?? '';
            $hosts = array_map([self::class, 'hostname'], (array) ($item['secondaryHostnames'] ?? []));
            $hosts[] = self::hostname($item['cname'] ?? '');
            if (!empty($item['deleted']) || !empty($item['suspended'])
                || ($item['active'] ?? true) === false || ($item['enabled'] ?? true) === false
                || ($item['status'] ?? 'active') !== 'active'
                || ($hostname !== '' && !in_array($hostname, $hosts, true))) {
                return null;
            }
        }

        return [
            'id' => strtolower($id),
            'name' => is_string($name) ? sanitize_text_field(substr($name, 0, 200)) : '',
            'hostname' => $hostname,
        ];
    }

    private static function hostname($value): string
    {
        $value = is_string($value) ? strtolower($value) : '';
        return strlen($value) <= 253 && preg_match('/\.[a-z][a-z0-9-]*$/D', $value)
            && !filter_var($value, FILTER_VALIDATE_IP) && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? $value
            : '';
    }

    private static function request(string $url, array $headers): array
    {
        $response = wp_safe_remote_request($url, [
            'method' => 'GET',
            'headers' => $headers + ['Accept' => 'application/json'],
            'timeout' => 4,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 2097152,
        ]);
        if (is_wp_error($response)) {
            return self::error(__('The CDN API could not be reached.', 'ys-cache'));
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        if ($http === 401 || $http === 403) {
            return self::error(__('The CDN API denied access. Check the credential and its resource-read permission.', 'ys-cache'), $http);
        }
        if ($http === 404) {
            return self::error(__('The CDN API could not find that resource.', 'ys-cache'), $http);
        }
        if ($http < 200 || $http >= 300) {
            return self::error(__('The CDN API did not accept the lookup.', 'ys-cache'), $http);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true, 40, JSON_BIGINT_AS_STRING);
        if (!is_array($body)) {
            return self::invalidReply($http);
        }
        return ['status' => 'ok', 'http' => $http, 'body' => $body];
    }

    private static function noMatch(): array
    {
        return self::error(__('No matching active CDN resource was found. Check the hostname, resource ID, and credential scope.', 'ys-cache'));
    }

    private static function ambiguous(): array
    {
        return self::error(__('More than one CDN resource matches. Choose the resource in the provider dashboard and enter its ID.', 'ys-cache'));
    }

    private static function invalidReply(int $http): array
    {
        return self::error(__('The CDN API returned an unrecognized response.', 'ys-cache'), $http);
    }

    private static function error(string $message, int $http = 0): array
    {
        return ['status' => 'error', 'message' => $message, 'http' => $http];
    }

    private static function success(array $resource): array
    {
        return [
            'status' => 'ok',
            'message' => __('CDN resource found. Save the settings to apply this connection.', 'ys-cache'),
            'http' => 200,
            'resource' => $resource,
        ];
    }
}
