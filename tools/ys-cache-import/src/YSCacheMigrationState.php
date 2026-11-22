<?php

namespace YangSheep\Cache\Import;

defined('ABSPATH') || exit;

final class YSCacheMigrationState
{
    private static array $exists = [];

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ys_cache_migration';
    }

    private static function exists(): bool
    {
        global $wpdb;
        $table = self::table();
        if (!array_key_exists($table, self::$exists)) {
            self::$exists[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        }
        return self::$exists[$table];
    }

    public static function install(): bool
    {
        if (self::exists()) {
            return true;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            record_name varchar(32) NOT NULL,
            record_value longtext NOT NULL,
            PRIMARY KEY  (record_name)
        ) {$collation};");
        unset(self::$exists[$table]);
        return self::exists();
    }

    public static function read(string $name): array
    {
        if (!self::exists()) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        $value = $wpdb->get_var($wpdb->prepare("SELECT record_value FROM {$table} WHERE record_name = %s", $name));
        $record = is_string($value) ? json_decode($value, true) : null;
        return is_array($record) ? $record : [];
    }

    public static function write(string $name, array $value): bool
    {
        global $wpdb;
        $json = wp_json_encode($value);
        return is_string($json) && false !== $wpdb->replace(self::table(), [
            'record_name' => $name,
            'record_value' => $json,
        ]);
    }

    public static function delete(string $name): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['record_name' => $name]);
    }
}
