<?php

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * 早期 drop-in 與 WordPress 寫入端共用的頁面儲存區（格式 3）。
 *
 * - 位於網站目錄外，目錄 0700、檔案 0600，並檢查擁有者與硬連結數。
 * - 每個 (主機, 路徑, 變體) 一份 gzip 壓縮的完整 HTML，連同允許重播的安全標頭與完整性雜湊。
 * - 全站世代（.epoch）與「群組」世代標記：群組是去掉結尾 /page/N/ 的路徑，清除分類時連同分頁一起失效。
 * - 讀取不加鎖（所有寫入都以 rename 原子替換）；寫入與清除使用排他鎖，寫入前重新比對渲染前的世代快照。
 */
final class YSCacheEarlyStore {
    public const MAX_TTL = 43200;
    private const FORMAT = 3;
    private const MAX_HTML = 2097152;
    private const MAX_STORED = 1048576;
    private const MAX_METADATA = 16384;
    private const MAX_PAGES_PER_BUCKET = 32;
    private const MAX_MARKERS_PER_BUCKET = 64;
    private const FIRST_PAGE_GENERATION = '00000000000000000000000000000000';

    private const PUBLIC_HEADERS = [
        'content-type'                        => 'Content-Type',
        'content-language'                    => 'Content-Language',
        'content-security-policy'             => 'Content-Security-Policy',
        'content-security-policy-report-only' => 'Content-Security-Policy-Report-Only',
        'cross-origin-embedder-policy'        => 'Cross-Origin-Embedder-Policy',
        'cross-origin-opener-policy'          => 'Cross-Origin-Opener-Policy',
        'cross-origin-resource-policy'        => 'Cross-Origin-Resource-Policy',
        'permissions-policy'                  => 'Permissions-Policy',
        'referrer-policy'                     => 'Referrer-Policy',
        'strict-transport-security'           => 'Strict-Transport-Security',
        'vary'                                => 'Vary',
        'x-content-type-options'              => 'X-Content-Type-Options',
        'x-frame-options'                     => 'X-Frame-Options',
        'x-permitted-cross-domain-policies'   => 'X-Permitted-Cross-Domain-Policies',
        'x-robots-tag'                        => 'X-Robots-Tag',
    ];

    public static function validRequestPath( string $path ): bool {
        return strlen( $path ) <= 2048 && preg_match( '#\A/[A-Za-z0-9._~%/-]*\z#', $path )
            && self::validPathForInvalidation( $path )
            && ! preg_match( '~(?:^|/)(?:wp-admin|wp-json|wp-includes|wp-content|feed|trackback)(?:/|$)|\.php(?:/|$)~i', rawurldecode( $path ) );
    }

    /** 這個路徑可能有快取項目（能被清除）；不合格的路徑從來不會被寫入。 */
    public static function purgeablePath( string $path ): bool {
        return self::validPathForInvalidation( $path );
    }

    private static function validPathForInvalidation( string $path ): bool {
        return str_starts_with( $path, '/' ) && strlen( $path ) <= 2048 && ! str_contains( $path, '//' )
            && ! preg_match( '~%(?:2f|5c|25|(?![a-f0-9]{2}))~i', $path )
            && ! preg_match( '~[\x00-\x20\x7f?#\\\\;]|(?:^|/)\.{1,2}(?:/|$)~', rawurldecode( $path ) );
    }

    public static function initialize( string $directory ): bool {
        $directory = rtrim( $directory, '/\\' );
        if ( self::directory( $directory ) === null ) {
            $parent = realpath( dirname( $directory ) );
            if ( $directory === '' || $parent === false ||
                ! self::samePath( $directory, $parent . DIRECTORY_SEPARATOR . basename( $directory ) ) ||
                ! self::outsideWordPress( $directory ) || ! @mkdir( $directory, 0700 ) ) {
                return false;
            }
        }
        $directory = self::directory( $directory );
        if ( $directory === null ) {
            return false;
        }
        $lockPath = $directory . '/.lock';
        if ( self::stat( $lockPath ) === false ) {
            $lock = @fopen( $lockPath, 'x+b' );
            if ( $lock !== false ) {
                @chmod( $lockPath, 0600 );
                fclose( $lock );
            }
        }
        return self::locked( $directory, LOCK_EX, static function ( string $directory ): bool {
            $marker = $directory . '/.epoch';
            return self::token( $marker ) !== null ||
                ( self::stat( $marker ) === false && self::rotateEpoch( $directory ) );
        } ) === true;
    }

    /** 渲染前擷取世代，讓同時發生的清除不會被稍後寫入的舊 HTML 蓋回去。 */
    public static function snapshot( string $directory, string $path ): ?array {
        $directory = self::directory( $directory );
        return $directory === null || ! self::validRequestPath( $path ) ? null : self::generations( $directory, $path );
    }

    public static function write( string $directory, string $host, string $path, array $snapshot, array $headers, string $html, int $ttl, string $variant ): bool {
        if ( ! self::validAuthority( $host ) || ! self::validRequestPath( $path ) || ! self::validVariant( $variant ) ||
            $ttl < 1 || ! self::validHtml( $html ) ) {
            return false;
        }
        $headers = self::safeHeaders( $headers );
        if ( $headers === null ) {
            return false;
        }
        $body = gzencode( $html, 6 );
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_STORED ) {
            return false;
        }
        $host = strtolower( $host );
        $key = self::entryKey( $host, $path, $variant );
        $now = time();
        $metadata = json_encode( [
            'format' => self::FORMAT,
            'host' => $host,
            'path' => $path,
            'variant' => $variant,
            'generation' => $snapshot,
            'created' => $now,
            'expires' => $now + min( $ttl, self::MAX_TTL ),
            'headers' => $headers,
            'encoding' => 'gzip',
            'etag' => substr( hash( 'sha256', $html ), 0, 32 ),
            'body_hash' => hash( 'sha256', $body ),
        ], JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $metadata ) || strlen( $metadata ) > self::MAX_METADATA ) {
            return false;
        }
        return self::locked( $directory, LOCK_EX | LOCK_NB, static function ( string $directory ) use ( $path, $snapshot, $key, $metadata, $body ): bool {
            if ( self::generations( $directory, $path ) !== $snapshot ) {
                return false;
            }
            $bucket = $directory . '/' . substr( $key, 0, 2 );
            if ( ! self::createBucket( $bucket ) ) {
                return false;
            }
            $target = $bucket . '/' . $key . '.page';
            $files = glob( $bucket . '/*.page' ) ?: [];
            if ( ! in_array( $target, $files, true ) && count( $files ) >= self::MAX_PAGES_PER_BUCKET ) {
                usort( $files, static fn ( string $left, string $right ): int => (int) @filemtime( $left ) <=> (int) @filemtime( $right ) );
                if ( ! self::safeEntry( $files[0], 0100000 ) || ! @unlink( $files[0] ) ) {
                    return false;
                }
            }
            return self::replace( $target, $metadata . "\n" . $body );
        } ) === true;
    }

    /**
     * 讀取可送出的項目。
     *
     * @return array{headers:array,body:string,encoding:string,etag:string,created:int,expires:int}|null
     */
    public static function read( string $directory, string $host, string $path, string $variant ): ?array {
        if ( ! self::validAuthority( $host ) || ! self::validRequestPath( $path ) || ! self::validVariant( $variant ) ) {
            return null;
        }
        $directory = self::directory( $directory );
        if ( $directory === null ) {
            return null;
        }
        $host = strtolower( $host );
        return self::readEntry( $directory, $host, $path, $variant, self::entryKey( $host, $path, $variant ) );
    }

    /** 預載用：已有剩餘壽命至少 $minRemaining 秒的有效項目時回傳 true。 */
    public static function fresh( string $directory, string $host, string $path, string $variant, int $minRemaining ): bool {
        $entry = self::read( $directory, $host, $path, $variant );
        return $entry !== null && $entry['expires'] - time() >= $minRemaining;
    }

    private static function readEntry( string $directory, string $host, string $path, string $variant, string $key ): ?array {
        $bucket = $directory . '/' . substr( $key, 0, 2 );
        $record = self::readFile( $bucket . '/' . $key . '.page', self::MAX_METADATA + self::MAX_STORED + 1 );
        $newline = $record === null ? false : strpos( $record, "\n" );
        if ( $newline === false || $newline > self::MAX_METADATA ) {
            return null;
        }
        $data = json_decode( substr( $record, 0, $newline ), true, 8 );
        $now = time();
        if ( ! is_array( $data ) || ( $data['format'] ?? null ) !== self::FORMAT ||
            ( $data['host'] ?? null ) !== $host || ( $data['path'] ?? null ) !== $path || ( $data['variant'] ?? null ) !== $variant ||
            ! is_int( $data['created'] ?? null ) || ! is_int( $data['expires'] ?? null ) ||
            $data['expires'] <= $now || $data['expires'] > $now + self::MAX_TTL || $data['created'] > $now + 60 ||
            ! is_array( $data['generation'] ?? null ) || $data['generation'] !== self::generations( $directory, $path ) ||
            ! is_array( $data['headers'] ?? null ) || self::safeHeaders( $data['headers'] ) !== $data['headers'] ||
            ! in_array( $data['encoding'] ?? null, [ 'gzip' ], true ) ||
            ! is_string( $data['etag'] ?? null ) || ! preg_match( '~\A[a-f0-9]{32}\z~', $data['etag'] ) ||
            ! is_string( $data['body_hash'] ?? null ) ) {
            return null;
        }
        $body = substr( $record, $newline + 1 );
        if ( $body === '' || ! hash_equals( $data['body_hash'], hash( 'sha256', $body ) ) ) {
            return null;
        }
        return [
            'headers' => $data['headers'],
            'body' => $body,
            'encoding' => $data['encoding'],
            'etag' => $data['etag'],
            'created' => $data['created'],
            'expires' => $data['expires'],
        ];
    }

    public static function invalidateAll( string $directory ): bool {
        return self::locked( $directory, LOCK_EX, [ self::class, 'rotateEpoch' ] ) === true;
    }

    /** 清除一個路徑群組（含該路徑的所有分頁與裝置變體）。 */
    public static function invalidatePath( string $directory, string $path ): bool {
        if ( ! self::validPathForInvalidation( $path ) ) {
            return false;
        }
        $key = self::groupKey( $path );
        return self::locked( $directory, LOCK_EX, static function ( string $directory ) use ( $key ): bool {
            $bucket = $directory . '/' . substr( $key, 0, 2 );
            if ( ! self::createBucket( $bucket ) ) {
                return self::rotateEpoch( $directory );
            }
            $marker = $bucket . '/' . $key . '.generation';
            if ( self::stat( $marker ) === false && count( glob( $bucket . '/*.generation' ) ?: [] ) >= self::MAX_MARKERS_PER_BUCKET ) {
                // 舊標記不能直接遺忘（可能仍有對應的項目或寫入中的回應），改為全站換代後再清空。
                if ( ! self::rotateEpoch( $directory ) ) {
                    return false;
                }
                foreach ( glob( $bucket . '/*.generation' ) ?: [] as $old ) {
                    if ( ! self::safeEntry( $old, 0100000 ) || ! @unlink( $old ) ) {
                        return false;
                    }
                }
                return true;
            }
            return self::replace( $marker, bin2hex( random_bytes( 16 ) ) ) || self::rotateEpoch( $directory );
        } ) === true;
    }

    /** 刪除過期或世代失效的項目，以及殘留的暫存檔；在時間預算內盡量完成。 */
    public static function prune( string $directory, float $budgetSeconds = 5.0 ): int {
        $directory = self::directory( $directory );
        if ( $directory === null ) {
            return 0;
        }
        $deadline = microtime( true ) + max( 0.1, $budgetSeconds );
        $removed = 0;
        foreach ( glob( $directory . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR ) ?: [] as $bucket ) {
            if ( ! self::safeEntry( $bucket, 0040000 ) ) {
                continue;
            }
            $pending = $bucket . '/.pending';
            $info = self::stat( $pending );
            if ( $info !== false && $info['mtime'] < time() - 3600 && self::safeEntry( $pending, 0100000 ) && @unlink( $pending ) ) {
                $removed++;
            }
            foreach ( glob( $bucket . '/*.page' ) ?: [] as $file ) {
                if ( microtime( true ) > $deadline ) {
                    return $removed;
                }
                $head = self::readHead( $file );
                $data = $head === null ? null : json_decode( $head, true, 8 );
                $stale = ! is_array( $data ) || ( $data['format'] ?? null ) !== self::FORMAT ||
                    ! is_int( $data['expires'] ?? null ) || $data['expires'] <= time() ||
                    ! is_string( $data['path'] ?? null ) || ! self::validRequestPath( $data['path'] ) ||
                    $data['generation'] !== self::generations( $directory, $data['path'] );
                if ( $stale && self::safeEntry( $file, 0100000 ) && @unlink( $file ) ) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /** 移除 0.1 版留下的單字元桶、render 標記與 pixel 狀態檔（格式 2 永遠不會再被讀取）。 */
    public static function purgeLegacy( string $directory ): void {
        $directory = self::directory( $directory );
        if ( $directory === null ) {
            return;
        }
        foreach ( [ '/.pixel-key', '/.pixel-warm.json', '/.pixel-warm.lock' ] as $name ) {
            if ( self::safeEntry( $directory . $name, 0100000 ) ) {
                @unlink( $directory . $name );
            }
        }
        foreach ( glob( $directory . '/[0-9a-f]', GLOB_ONLYDIR ) ?: [] as $bucket ) {
            if ( ! self::safeEntry( $bucket, 0040000 ) ) {
                continue;
            }
            foreach ( [ '*.page', '*.generation', '*.render', '.pending' ] as $pattern ) {
                foreach ( glob( $bucket . '/' . $pattern ) ?: [] as $file ) {
                    if ( self::safeEntry( $file, 0100000 ) ) {
                        @unlink( $file );
                    }
                }
            }
            @rmdir( $bucket );
        }
    }

    /** 管理介面用的概況：項目數與佔用位元組（不驗證內容）。 */
    public static function stats( string $directory ): array {
        $directory = self::directory( $directory );
        $entries = 0;
        $bytes = 0;
        if ( $directory !== null ) {
            foreach ( glob( $directory . '/[0-9a-f][0-9a-f]/*.page' ) ?: [] as $file ) {
                $entries++;
                $bytes += (int) @filesize( $file );
            }
        }
        return [ 'entries' => $entries, 'bytes' => $bytes ];
    }

    private static function generations( string $directory, string $path ): ?array {
        $key = self::groupKey( $path );
        $bucket = $directory . '/' . substr( $key, 0, 2 );
        if ( self::stat( $bucket ) !== false && ! self::safeEntry( $bucket, 0040000 ) ) {
            return null;
        }
        $epoch = self::token( $directory . '/.epoch' );
        $page = self::token( $bucket . '/' . $key . '.generation', self::FIRST_PAGE_GENERATION );
        return $epoch === null || $page === null ? null : [ 'epoch' => $epoch, 'page' => $page ];
    }

    private static function token( string $file, ?string $absent = null ): ?string {
        if ( self::stat( $file ) === false ) {
            return $absent;
        }
        $value = self::readFile( $file, 32 );
        return $value !== null && preg_match( '~\A[a-f0-9]{32}\z~', $value ) ? $value : null;
    }

    private static function rotateEpoch( string $directory ): bool {
        return self::replace( $directory . '/.epoch', bin2hex( random_bytes( 16 ) ) );
    }

    private static function entryKey( string $host, string $path, string $variant ): string {
        return hash( 'sha256', $host . "\n" . $path . "\n" . $variant );
    }

    /** 群組：解碼後去掉結尾斜線與 /page/N，分頁與主頁共用同一個世代。 */
    public static function groupPath( string $path ): string {
        $path = rtrim( rawurldecode( $path ), '/' );
        $path = (string) preg_replace( '~/page/[0-9]+\z~', '', $path );
        return $path === '' ? '/' : $path;
    }

    private static function groupKey( string $path ): string {
        return hash( 'sha256', self::groupPath( $path ) );
    }

    private static function validAuthority( string $host ): bool {
        return preg_match( '~\A(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::([1-9][0-9]{0,4}))?\z~i', $host, $parts )
            && ( ! isset( $parts[1] ) || (int) $parts[1] <= 65535 );
    }

    private static function validVariant( string $variant ): bool {
        return preg_match( '~\A[a-z0-9_-]{1,48}\z~', $variant ) === 1;
    }

    /**
     * 只存完整 HTML 文件。CSP nonce 屬於單次回應，不能共用；YS CART 分區屬性屬於單一訪客。
     * data-*nonce 屬性允許共用：寫入端只接受無 session 請求，這些都是所有訪客相同的 uid 0 nonce，壽命也低於 12 小時。
     */
    private static function validHtml( string $html ): bool {
        return strlen( $html ) <= self::MAX_HTML &&
            preg_match( '~<!doctype\s+html|<html(?:\s|>)~i', substr( $html, 0, 4096 ) ) === 1 &&
            preg_match( '~</html\s*>~i', substr( $html, -8192 ) ) === 1 &&
            preg_match( '~<(?:script|style|link)\b[^>]*\snonce\s*=~i', $html ) === 0 &&
            preg_match( '~\sdata-ys-ec-partition-(?:key|generation)\s*=~i', $html ) === 0;
    }

    private static function safeHeaders( array $headers ): ?array {
        $safe = [];
        $seen = [];
        foreach ( $headers as $line ) {
            if ( ! is_string( $line ) || ! preg_match( '~\A([a-z0-9-]{1,64}):([^\x00-\x1f\x7f]*)\z~i', $line, $parts ) ) {
                return null;
            }
            $name = strtolower( $parts[1] );
            $value = trim( $parts[2] );
            if ( in_array( $name, [
                'set-cookie', 'set-cookie2', 'clear-site-data', 'location', 'refresh', 'content-disposition',
                'www-authenticate', 'proxy-authenticate', 'authentication-info', 'proxy-authentication-info',
                'x-accel-redirect', 'x-sendfile', 'content-encoding', 'content-length', 'content-range',
            ], true ) || ( $name === 'pragma' && stripos( $value, 'no-cache' ) !== false ) ||
                ( $name === 'vary' && strcasecmp( $value, 'Accept-Encoding' ) !== 0 ) ||
                ( $name === 'content-type' && ! preg_match( '~\Atext/html(?:\s*;\s*charset\s*=\s*(?:"[a-z0-9._-]+"|[a-z0-9._-]+))?\z~i', $value ) ) ||
                ( str_starts_with( $name, 'content-security-policy' ) && stripos( $value, 'nonce-' ) !== false ) ) {
                return null;
            }
            if ( ! isset( self::PUBLIC_HEADERS[ $name ] ) ) {
                continue;
            }
            if ( $value === '' || strlen( $value ) > 8192 ||
                ( isset( $seen[ $name ] ) && ! in_array( $name, [ 'content-security-policy', 'content-security-policy-report-only', 'permissions-policy' ], true ) ) ) {
                return null;
            }
            $safe[] = self::PUBLIC_HEADERS[ $name ] . ': ' . $value;
            $seen[ $name ] = true;
        }
        return isset( $seen['content-type'] ) ? $safe : null;
    }

    private static function locked( string $directory, int $flags, callable $action ): mixed {
        $directory = self::directory( $directory );
        if ( $directory === null || ! self::safeEntry( $directory . '/.lock', 0100000 ) ) {
            return null;
        }
        $handle = @fopen( $directory . '/.lock', 'r+b' );
        if ( $handle === false ) {
            return null;
        }
        try {
            return @flock( $handle, $flags ) ? $action( $directory ) : null;
        } finally {
            fclose( $handle );
        }
    }

    private static function directory( string $directory ): ?string {
        $directory = rtrim( $directory, '/\\' );
        if ( $directory === '' || ! self::safeEntry( $directory, 0040000 ) ) {
            return null;
        }
        $resolved = realpath( $directory );
        return $resolved !== false && self::samePath( $resolved, $directory ) && self::outsideWordPress( $resolved )
            ? $resolved : null;
    }

    private static function samePath( string $left, string $right ): bool {
        return self::normalizePath( $left ) === self::normalizePath( $right );
    }

    private static function normalizePath( string $path ): string {
        $path = rtrim( str_replace( '\\', '/', $path ), '/' );
        return DIRECTORY_SEPARATOR === '/' ? $path : strtolower( $path );
    }

    private static function outsideWordPress( string $directory ): bool {
        $directory = self::normalizePath( $directory ) . '/';
        if ( realpath( ABSPATH ) === false ) {
            return false;
        }
        foreach ( [ ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ] as $webRoot ) {
            $root = $webRoot === '' ? false : realpath( $webRoot );
            if ( $root !== false && str_starts_with( $directory, self::normalizePath( $root ) . '/' ) ) {
                return false;
            }
        }
        return true;
    }

    private static function createBucket( string $directory ): bool {
        if ( self::stat( $directory ) === false ) {
            @mkdir( $directory, 0700 );
        }
        return self::safeEntry( $directory, 0040000 );
    }

    private static function stat( string $path ): array|false {
        clearstatcache( true, $path );
        return @lstat( $path );
    }

    private static function safeEntry( string $path, int $type ): bool {
        $stat = self::stat( $path );
        $permissions = $type === 0040000 ? 0700 : 0600;
        return $stat !== false && ( $stat['mode'] & 0170000 ) === $type &&
            ( DIRECTORY_SEPARATOR !== '/' || ( ( $stat['mode'] & 0777 ) === $permissions && ( $type !== 0100000 || $stat['nlink'] === 1 ) ) ) &&
            ( ! function_exists( 'posix_geteuid' ) || $stat['uid'] === posix_geteuid() );
    }

    private static function readFile( string $file, int $limit ): ?string {
        if ( ! self::safeEntry( $file, 0100000 ) ) {
            return null;
        }
        $value = @file_get_contents( $file, false, null, 0, $limit + 1 );
        return is_string( $value ) && strlen( $value ) <= $limit ? $value : null;
    }

    private static function readHead( string $file ): ?string {
        if ( ! self::safeEntry( $file, 0100000 ) ) {
            return null;
        }
        $handle = @fopen( $file, 'rb' );
        if ( $handle === false ) {
            return null;
        }
        $line = fgets( $handle, self::MAX_METADATA + 2 );
        fclose( $handle );
        return is_string( $line ) ? rtrim( $line, "\n" ) : null;
    }

    /** 所有呼叫端都持有同一把排他鎖，因此每個目錄只需一個暫存檔。 */
    private static function replace( string $target, string $value ): bool {
        $stage = dirname( $target ) . '/.pending';
        if ( ( self::stat( $target ) !== false && ! self::safeEntry( $target, 0100000 ) ) ||
            ( self::stat( $stage ) !== false && ( ! self::safeEntry( $stage, 0100000 ) || ! @unlink( $stage ) ) ) ) {
            return false;
        }
        $handle = @fopen( $stage, 'x+b' );
        if ( $handle === false ) {
            return false;
        }
        $written = @chmod( $stage, 0600 ) && @fwrite( $handle, $value ) === strlen( $value ) && @fflush( $handle );
        fclose( $handle );
        if ( $written && @rename( $stage, $target ) ) {
            return true;
        }
        @unlink( $stage );
        return false;
    }
}
