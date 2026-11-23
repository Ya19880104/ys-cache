<?php

namespace YangSheep\Cache\Database;

defined( 'ABSPATH' ) || exit;

final class YSCacheSettingsRepo {
    public const DEFAULT_TTL_SECONDS = 28800;
    public const MAX_TTL_SECONDS = 604800;
    private const PAGE_PREFIX = 'page:';
    private const MAX_HTML_AGE = self::MAX_TTL_SECONDS + 86400;
    private static array $settings = [];

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ys_cache_settings';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            setting_name varchar(64) NOT NULL,
            setting_value longtext NOT NULL,
            PRIMARY KEY  (setting_name)
        ) {$collation};" );
    }

    public static function defaults(): array {
        return [
            'enabled'         => false,
            'ttl_seconds'     => self::DEFAULT_TTL_SECONDS,
            'ttl_unit'        => 's',
            'auto_purge'      => true,
            'auto_detect'     => true,
            'early_paths'     => '',
            'early_pixel_shell' => false,
            'guest_template' => false,
            'exclude_paths'   => '',
            'exclude_cookies' => '',
            'exclude_agents'  => '',
            'ttl_paths'       => '',
            'public_cf7_paths' => '',
            'providers'       => [],
            'assets'          => [],
        ];
    }

    public static function get( bool $fresh = false ): array {
        global $wpdb;
        $table = self::table();
        if ( $fresh || ! isset( self::$settings[ $table ] ) ) {
            $value = $wpdb->get_var( "SELECT setting_value FROM {$table} WHERE setting_name = 'settings'" );
            $stored = is_string( $value ) ? json_decode( $value, true ) : null;
            $stored = is_array( $stored ) ? $stored : [];
            if ( ! isset( $stored['ttl_seconds'] ) && isset( $stored['ttl'] ) ) {
                $stored['ttl_seconds'] = self::legacyTtlToSeconds( $stored['ttl'] );
            }
            unset( $stored['ttl'] );
            self::$settings[ $table ] = array_replace( self::defaults(), $stored );
        }
        return self::$settings[ $table ];
    }

    public static function legacyTtlToSeconds( $minutes ): int {
        return max( 1, min( 1440, (int) $minutes ) ) * 60;
    }

    public static function save( array $settings ): bool {
        global $wpdb;
        $encoded = wp_json_encode( $settings );
        if ( ! is_string( $encoded ) ) {
            return false;
        }
        $table = self::table();
        $result = $wpdb->replace( $table, [ 'setting_name' => 'settings', 'setting_value' => $encoded ] );
        if ( false === $result ) {
            return false;
        }
        self::$settings[ $table ] = $settings;
        return true;
    }

    public static function generation(): string {
        global $wpdb;
        $table = self::table();
        $value = $wpdb->get_var( "SELECT setting_value FROM {$table} WHERE setting_name = 'generation'" );
        return is_string( $value ) ? $value : '0';
    }

    public static function invalidate(): bool {
        global $wpdb;
        if ( class_exists( '\YangSheep\Cache\YSCacheEarly' ) && ! \YangSheep\Cache\YSCacheEarly::invalidate() ) {
            return false;
        }
        return false !== $wpdb->replace( self::table(), [
            'setting_name'  => 'generation',
            'setting_value' => wp_generate_uuid4(),
        ] );
    }

    /** Return the site and page generations in one indexed query. */
    public static function pageGenerations( string $path ): array {
        global $wpdb;
        $table = self::table();
        $key = self::pageKey( $path );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT setting_name, setting_value FROM {$table} WHERE setting_name IN ('generation', %s)",
            $key
        ) );
        $generations = [ 'generation' => '0', $key => '0' ];
        foreach ( (array) $rows as $row ) {
            $generations[ $row->setting_name ] = $row->setting_value;
        }
        return [ $generations['generation'], $generations[ $key ] ];
    }

    public static function invalidatePage( string $path ): bool {
        global $wpdb;
        if ( class_exists( '\YangSheep\Cache\YSCacheEarly' ) && ! \YangSheep\Cache\YSCacheEarly::invalidate( $path ) ) {
            return false;
        }
        $table = self::table();
        if ( false === $wpdb->replace( $table, [
            'setting_name'  => self::pageKey( $path ),
            'setting_value' => sprintf( '%010d:%s', time(), wp_generate_uuid4() ),
        ] ) ) {
            return false;
        }
        // A generation is safe to forget after every file it superseded has expired.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE setting_name LIKE %s AND setting_value < %s LIMIT 100",
            self::PAGE_PREFIX . '%',
            sprintf( '%010d:', time() - self::MAX_HTML_AGE )
        ) );
        return true;
    }

    private static function pageKey( string $path ): string {
        $path = rtrim( rawurldecode( $path ), '/' ) ?: '/';
        return self::PAGE_PREFIX . substr( hash( 'sha256', $path ), 0, 64 - strlen( self::PAGE_PREFIX ) );
    }

    public static function readPurgeRecord( string $name ): array {
        global $wpdb;
        $table = self::table();
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM {$table} WHERE setting_name = %s",
            $name
        ) );
        $record = is_string( $value ) ? json_decode( $value, true ) : null;
        return is_array( $record ) ? $record : [];
    }

    public static function writePurgeRecord( string $name, array $record ): bool {
        global $wpdb;
        $value = wp_json_encode( $record );
        return is_string( $value ) && false !== $wpdb->replace( self::table(), [
            'setting_name' => $name,
            'setting_value' => $value,
        ] );
    }

    public static function changePurgeRecord( string $name, ?array $previous, ?array $next ): bool {
        global $wpdb;
        if ( $previous === null ) {
            $value = $next === null ? false : wp_json_encode( $next );
            if ( ! is_string( $value ) ) {
                return false;
            }
            $table = self::table();
            return 1 === $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (setting_name, setting_value) VALUES (%s, %s)",
                $name,
                $value
            ) );
        }
        $expected = [ 'setting_name' => $name, 'setting_value' => wp_json_encode( $previous ) ];
        if ( $next === null ) {
            return 1 === $wpdb->delete( self::table(), $expected );
        }
        return 1 === $wpdb->update( self::table(), [ 'setting_value' => wp_json_encode( $next ) ], $expected );
    }
}
