<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheStorage {
    private const MAX_BYTES = 1048576;
    private const MAX_FILES_PER_BUCKET = 16;

    public static function directory(): string {
        $base = defined( 'YS_CACHE_STORAGE_ROOT' ) ? YS_CACHE_STORAGE_ROOT : sys_get_temp_dir();
        $root = realpath( $base );
        $documents = realpath( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ?: realpath( ABSPATH );
        if ( ! $root || ! is_writable( $root ) || ! $documents ) {
            return '';
        }

        $name = 'ys-cache-' . hash_hmac( 'sha256', realpath( ABSPATH ) . '|' . get_current_blog_id(), wp_salt( 'auth' ) );
        $directory = $root . DIRECTORY_SEPARATOR . $name;
        $normal = static fn( string $path ): string => rtrim( strtolower( wp_normalize_path( $path ) ), '/' );
        if ( strpos( $normal( $directory ) . '/', $normal( $documents ) . '/' ) === 0 ||
             strpos( $normal( $directory ) . '/', $normal( WP_CONTENT_DIR ) . '/' ) === 0 ) {
            return '';
        }
        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700 ) && ! is_dir( $directory ) ) {
            return '';
        }
        clearstatcache( true, $directory );
        $perms = fileperms( $directory );
        if ( is_link( $directory ) || ! is_writable( $directory ) || false === $perms ||
            ( DIRECTORY_SEPARATOR === '/' && ( $perms & 0077 ) !== 0 ) ||
            ( function_exists( 'posix_geteuid' ) && fileowner( $directory ) !== posix_geteuid() ) ) {
            return '';
        }
        return $directory;
    }

    private static function bucket( string $directory, string $key, bool $create ): string {
        // One hexadecimal digit limits the maximum disk use across this site.
        $bucket = $directory . DIRECTORY_SEPARATOR . $key[0];
        if ( $create && ! is_dir( $bucket ) ) {
            @mkdir( $bucket, 0700 );
        }
        return is_dir( $bucket ) && ! is_link( $bucket ) ? $bucket : '';
    }

    public static function read( string $directory, string $key, int $ttl, ?int &$remaining = null ): ?string {
        $bucket = self::bucket( $directory, $key, false );
        if ( $bucket === '' ) {
            return null;
        }
        $file = $bucket . DIRECTORY_SEPARATOR . $key . '.html';
        clearstatcache( true, $file );
        $modified = is_link( $file ) ? false : @filemtime( $file );
        if ( ! $modified || time() - $modified >= $ttl ) {
            return null;
        }
        $html = @file_get_contents( $file, false, null, 0, self::MAX_BYTES + 1 );
        $remaining = max( 0, $ttl - ( time() - $modified ) );
        return is_string( $html ) && strlen( $html ) <= self::MAX_BYTES && $html !== '' ? $html : null;
    }

    public static function write( string $directory, string $key, string $html ): void {
        if ( strlen( $html ) > self::MAX_BYTES || $html === '' ) {
            return;
        }
        $bucket = self::bucket( $directory, $key, true );
        if ( $bucket === '' ) {
            return;
        }
        $lock_path = $bucket . DIRECTORY_SEPARATOR . '.lock';
        $lock = is_link( $lock_path ) ? false : @fopen( $lock_path, 'c' );
        if ( ! $lock ) {
            return;
        }
        try {
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                return;
            }
            $target = $bucket . DIRECTORY_SEPARATOR . $key . '.html';
            $files = glob( $bucket . '/*.html' ) ?: [];
            if ( ! in_array( $target, $files, true ) && count( $files ) >= self::MAX_FILES_PER_BUCKET ) {
                usort( $files, static fn( string $a, string $b ): int => (int) @filemtime( $a ) <=> (int) @filemtime( $b ) );
                if ( ! @unlink( $files[0] ) ) {
                    return;
                }
            }
            $temp = @tempnam( $bucket, '.ys-' );
            if ( false === $temp ) {
                return;
            }
            if ( dirname( $temp ) !== $bucket || @file_put_contents( $temp, $html ) !== strlen( $html ) ||
                ! @rename( $temp, $target ) ) {
                @unlink( $temp );
            }
        } finally {
            fclose( $lock );
        }
    }
}
