<?php

namespace YangSheep\Cache\Import;

use YangSheep\Cache\YSCacheProviders;

defined('ABSPATH') || exit;

final class YSCacheImportSettings
{
    private const MAX_LIFETIME_SECONDS = 604800;

    public static function convert(array $source, array $current): array
    {
        $warnings = [];
        $unsupported = [];
        $rules = [
            'cache_reject_uri' => 'exclude_paths',
            'cache_reject_cookies' => 'exclude_cookies',
            'cache_reject_ua' => 'exclude_agents',
        ];

        foreach ($rules as $field => $target) {
            $lines = self::lines($current[$target] ?? '');
            foreach (self::lines($source[$field] ?? []) as $line) {
                $converted = self::rule($line, $target);
                if ($converted === null) {
                    $unsupported[$field] = true;
                    continue;
                }
                $lines[] = $converted;
            }
            $current[$target] = implode("\n", array_unique($lines));
            if (strlen($current[$target]) > 12000) {
                $unsupported[$field] = true;
            }
        }

        if (!isset($current['ttl_seconds']) && is_numeric($current['ttl'] ?? null)) {
            $current['ttl_seconds'] = self::lifetime((float) $current['ttl'] * 60);
        }
        unset($current['ttl']);
        $interval = $source['purge_cron_interval'] ?? 10;
        $unit = $source['purge_cron_unit'] ?? 'HOUR_IN_SECONDS';
        $units = ['SECOND_IN_SECONDS' => 1, 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800];
        if (is_numeric($interval) && $interval > 0 && is_string($unit) && isset($units[$unit])) {
            $seconds = (float) $interval * $units[$unit];
            $current['ttl_seconds'] = self::lifetime($seconds);
            if ($seconds > self::MAX_LIFETIME_SECONDS) {
                $warnings[] = __('The imported cache lifetime exceeded seven days. YS CACHE applied its seven-day limit.', 'ys-cache-rocket-import');
            }
        } else {
            $warnings[] = __('The previous cache had no expiry. The existing YS CACHE lifetime was kept.', 'ys-cache-rocket-import');
        }

        $providers = is_array($current['providers'] ?? null) ? $current['providers'] : [];
        $cdn = is_array($providers['cdn'] ?? null) ? $providers['cdn'] : [];
        if (!empty($source['do_cloudflare'])) {
            self::cloudflare($source, $cdn, $warnings);
        }
        if (!empty($source['cdn'])) {
            self::imageCname($source, $cdn, $warnings);
        }
        $providers['cdn'] = array_values($cdn);
        $current['providers'] = $providers;

        if (!empty($source['cache_query_strings']) || !empty($source['cache_logged_user'])) {
            $warnings[] = __('YS CACHE applies its public-page rules to requests with query parameters and signed-in visitors.', 'ys-cache-rocket-import');
        }

        return ['settings' => $current, 'warnings' => $warnings, 'unsupported' => array_keys($unsupported)];
    }

    private static function lifetime(float $seconds): int
    {
        return (int) max(1, min(self::MAX_LIFETIME_SECONDS, $seconds));
    }

    private static function rule(string $line, string $target): ?string
    {
        if ($target === 'exclude_cookies') {
            return preg_match('/^[a-zA-Z0-9_-]+$/D', $line) ? $line : null;
        }

        $line = str_replace(['(.*)', '(.+)', '(*)', '.*'], '*', $line);
        if ($target === 'exclude_paths') {
            if (preg_match('#^https?://#i', $line)) {
                $line = (string) wp_parse_url($line, PHP_URL_PATH);
            }
            $line = rawurldecode($line);

            return preg_match('#^/(?!/)[^\x00-\x20\x7f\\\\?\#\[\]{}()|^$+<>]*$#uD', $line) ? $line : null;
        }

        $line = trim(str_replace('\\ ', ' ', $line), ' *');
        return preg_match('#^[a-zA-Z0-9 _./-]+$#D', $line) ? $line : null;
    }

    private static function lines($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', array_filter($value, 'is_string')), 'strlen'));
    }

    private static function cloudflare(array $source, array &$cdn, array &$warnings): void
    {
        $zone = strtolower(self::text($source['cloudflare_zone_id'] ?? ''));
        $email = sanitize_email(self::text($source['cloudflare_email'] ?? ''));
        $fromConfig = defined('WP_ROCKET_CF_API_KEY') && self::text(WP_ROCKET_CF_API_KEY) !== '';
        $token = $fromConfig ? '' : self::text($source['cloudflare_api_key'] ?? '');

        if (!preg_match('/^[0-9a-f]{32}$/D', $zone) || !$email || (!$fromConfig && $token === '')) {
            $warnings[] = __('The source Cloudflare zone or credentials were incomplete. Configure the integration in YS CACHE to enable purging.', 'ys-cache-rocket-import');
            return;
        }

        foreach ($cdn as $row) {
            if (($row['provider'] ?? '') === 'cloudflare' && ($row['zone_id'] ?? '') === $zone) {
                $warnings[] = __('This Cloudflare zone is already configured. The existing YS CACHE settings were kept.', 'ys-cache-rocket-import');
                return;
            }
        }
        if (count($cdn) >= YSCacheProviders::MAX_CDN_LAYERS) {
            $warnings[] = __('YS CACHE reached its CDN layer limit. The source Cloudflare zone was skipped.', 'ys-cache-rocket-import');
            return;
        }

        $cdn[] = [
            'provider' => 'cloudflare',
            'role' => 'site',
            'enabled' => true,
            'zone_id' => $zone,
            'token' => $token,
            'token_constant' => $fromConfig ? 'WP_ROCKET_CF_API_KEY' : '',
            'hostname' => '',
            'rewrite_images' => false,
            'auth_email' => $email,
        ];
    }

    private static function imageCname(array $source, array &$cdn, array &$warnings): void
    {
        foreach ($cdn as $row) {
            if (!empty($row['rewrite_images'])) {
                $warnings[] = __('An image CDN is already configured in YS CACHE. Its settings were kept.', 'ys-cache-rocket-import');
                return;
            }
        }
        if (count($cdn) >= YSCacheProviders::MAX_CDN_LAYERS) {
            $warnings[] = __('YS CACHE reached its CDN layer limit. The source image CNAME was skipped.', 'ys-cache-rocket-import');
            return;
        }

        if (!empty($source['cdn_reject_files']) || !empty($source['cdn_reject_pages'])) {
            $warnings[] = __('The source CDN had file or page exclusions. Review the rules before enabling the image CDN in YS CACHE.', 'ys-cache-rocket-import');
            return;
        }

        $hosts = (array) ($source['cdn_cnames'] ?? []);
        $zones = (array) ($source['cdn_zone'] ?? []);
        foreach ($hosts as $index => $raw) {
            if (!in_array($zones[$index] ?? 'all', ['images', 'all'], true)) {
                continue;
            }

            $hostname = strtolower(self::text($raw));
            $hostname = preg_replace('#^(https?:)?//#i', '', $hostname);
            $hostname = rtrim($hostname, '/');
            if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $hostname) || strpos($hostname, '.') === false) {
                continue;
            }

            $cdn[] = ['provider' => 'cname', 'role' => 'images', 'enabled' => true, 'hostname' => $hostname, 'rewrite_images' => true];
            return;
        }

        $warnings[] = __('No compatible image CNAME was found in the source CDN settings.', 'ys-cache-rocket-import');
    }

    private static function text($value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
