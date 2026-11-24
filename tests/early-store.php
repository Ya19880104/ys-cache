<?php

use YangSheep\Cache\YSCacheEarlyRequest;
use YangSheep\Cache\YSCacheEarlyStore;

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

function expect_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
    }
}

function stored_html( ?array $entry ): ?string {
    return $entry === null ? null : gzdecode( $entry['body'] );
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-early-test-' . bin2hex( random_bytes( 8 ) );
define( 'ABSPATH', $root . '/public/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
if ( ! mkdir( WP_CONTENT_DIR, 0700, true ) ) {
    throw new RuntimeException( 'Could not create the fixture document root.' );
}

try {
    require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
    require dirname( __DIR__ ) . '/src/YSCacheEarlyRequest.php';
    $directory = $root . '/storage';
    $host = 'store.example.test';
    $path = '/guest-page/';

    expect_same( false, YSCacheEarlyStore::initialize( ABSPATH . 'cache' ), 'Reject storage within the document root' );
    expect_same( false, YSCacheEarlyStore::initialize( $root . '/missing-parent/cache' ), 'Reject an unverified nonexistent parent' );
    expect_same( true, YSCacheEarlyStore::initialize( $directory ), 'Create an isolated directory and initial generation' );

    $html = '<!doctype html><html><body><h1>Static fixture</h1><script>var awdr_params={"nonce":"f00ba4"};</script>'
        . '<button data-nonce="abc123" data-wishlist-nonce="def456">Like</button></body></html>';
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Content-Security-Policy: upgrade-insecure-requests;',
        'Permissions-Policy: camera=(), geolocation=(self)',
        'Strict-Transport-Security: max-age=63072000',
        'Permissions-Policy: microphone=(), payment=(self)',
        "Content-Security-Policy: base-uri 'self';",
        'X-Frame-Options: SAMEORIGIN',
    ];
    $snapshot = YSCacheEarlyStore::snapshot( $directory, $path );
    expect_same( true, is_array( $snapshot ), 'Capture a new page generation before rendering' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ ...$headers, 'X-Unrelated-Application: fixture', 'X-YS-Cache-Early: miss' ], $html, 600, 'd' ), 'Write a safe finished response with guest nonces' );
    $entry = YSCacheEarlyStore::read( $directory, $host, $path, 'd' );
    expect_same( $headers, $entry['headers'] ?? null, 'Replay only allowlisted security headers' );
    expect_same( $html, stored_html( $entry ), 'Store the exact HTML gzip-compressed' );
    expect_same( 'gzip', $entry['encoding'], 'Mark the encoding' );
    expect_same( substr( hash( 'sha256', $html ), 0, 32 ), $entry['etag'], 'Derive a stable ETag from the HTML' );
    expect_same( true, $entry['expires'] - time() > 590 && $entry['expires'] - time() <= 600, 'Keep the requested lifetime' );
    expect_same( true, YSCacheEarlyStore::fresh( $directory, $host, $path, 'd', 300 ), 'Report a fresh entry for preload' );
    expect_same( false, YSCacheEarlyStore::fresh( $directory, $host, $path, 'd', 900 ), 'Report entries close to expiry as not fresh' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, trim( $path, '/' ), 'd' ), 'Deny a noncanonical alternate path' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, rtrim( $path, '/' ), 'd' ), 'Never reuse the body for the trailing slash alias' );
    expect_same( null, YSCacheEarlyStore::read( $directory, 'other.example.test', $path, 'd' ), 'Never reuse the body for a second host' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, 'm' ), 'Never reuse the desktop body for phones' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, $html, 600, 'm' ), 'Write the phone variant' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, $html, 999999, 'd' ), 'Accept an over-long lifetime' );
    expect_same( true, YSCacheEarlyStore::read( $directory, $host, $path, 'd' )['expires'] - time() <= YSCacheEarlyStore::MAX_TTL, 'Clamp lifetimes to the 12-hour nonce window' );
    expect_same( true, YSCacheEarlyStore::initialize( $directory ), 'Repeated initialization keeps existing generations' );
    expect_same( $snapshot, YSCacheEarlyStore::snapshot( $directory, $path ), 'Do not rotate the generation on initialization' );

    foreach ( [
        'Set-Cookie: synthetic-session=1',
        'Pragma: no-cache',
        'Vary: Cookie',
        "Content-Security-Policy-Report-Only: script-src 'nonce-synthetic'",
        'Location: /elsewhere/',
        "X-Frame-Options: SAMEORIGIN\r\nSet-Cookie: synthetic=1",
    ] as $header ) {
        expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ ...$headers, $header ], $html, 60, 'd' ), 'Reject unsafe response headers: ' . $header );
    }
    foreach ( [
        '<html><script nonce="synthetic"></script></html>',
        '<!doctype html><html><body><style nonce="x"></style></body></html>',
        '<!doctype html><html><body><link rel="preload" nonce="x"></body></html>',
        '<!doctype html><html><body><div data-ys-ec-partition-key="abc"></div></body></html>',
        '<!doctype html><html><body>truncated',
    ] as $unsafe ) {
        expect_same( false, YSCacheEarlyStore::write( $directory, $host, '/unsafe/', YSCacheEarlyStore::snapshot( $directory, '/unsafe/' ), $headers, $unsafe, 60, 'd' ), 'Reject response-specific or incomplete HTML' );
    }
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ 'Content-Type: application/json' ], $html, 60, 'd' ), 'Reject non-HTML response' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, $html, 60, 'D/../x' ), 'Reject malformed variants' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, '/wp-admin/', YSCacheEarlyStore::snapshot( $directory, '/wp-admin/' ) ?? [], $headers, $html, 60, 'd' ), 'Reject private paths' );

    // 群組世代：清除分類時連同分頁與兩種裝置一起失效，其他路徑不受影響。
    $category = '/product-category/soap/';
    $page2 = '/product-category/soap/page/2/';
    foreach ( [ $category, $page2 ] as $route ) {
        foreach ( [ 'd', 'm' ] as $device ) {
            expect_same( true, YSCacheEarlyStore::write( $directory, $host, $route, YSCacheEarlyStore::snapshot( $directory, $route ), $headers, $html, 600, $device ), 'Seed ' . $route . ' ' . $device );
        }
    }
    $other = '/another-page/';
    $otherSnapshot = YSCacheEarlyStore::snapshot( $directory, $other );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $other, $otherSnapshot, $headers, $html, 600, 'd' ), 'Write an unrelated path' );
    expect_same( '/product-category/soap', YSCacheEarlyStore::groupPath( $page2 ), 'Pagination shares the archive group' );
    expect_same( '/', YSCacheEarlyStore::groupPath( '/page/3/' ), 'Home pagination shares the home group' );
    $before = YSCacheEarlyStore::snapshot( $directory, $category );
    expect_same( true, YSCacheEarlyStore::invalidatePath( $directory, '/product-category/soap' ), 'Purge a normalized archive path' );
    foreach ( [ $category, $page2 ] as $route ) {
        foreach ( [ 'd', 'm' ] as $device ) {
            expect_same( null, YSCacheEarlyStore::read( $directory, $host, $route, $device ), 'Purge archive pages and both devices: ' . $route . ' ' . $device );
        }
    }
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $category, $before, $headers, $html, 600, 'd' ), 'Do not publish an in-flight response from before the purge' );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $other, 'd' ) ), 'Preserve unrelated paths on a scoped purge' );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $path, 'm' ) ), 'Preserve other cached pages on a scoped purge' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $category, YSCacheEarlyStore::snapshot( $directory, $category ), $headers, $html, 600, 'd' ), 'Refill after a scoped purge' );

    expect_same( true, YSCacheEarlyStore::invalidateAll( $directory ), 'Rotate the site generation atomically' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $other, 'd' ), 'Global purge invalidates all paths' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $category, 'd' ), 'Global purge invalidates refilled paths' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $other, $otherSnapshot, $headers, $html, 600, 'd' ), 'Do not publish stale content after a global purge' );

    // 容量：每桶 32 份、256 桶，足以容納整個商店目錄的桌機與手機版。
    $written = 0;
    for ( $i = 0; $i < 600; $i++ ) {
        $route = '/product/item-' . $i . '/';
        $written += YSCacheEarlyStore::write( $directory, $host, $route, YSCacheEarlyStore::snapshot( $directory, $route ), $headers, $html, 600, $i % 2 ? 'm' : 'd' ) ? 1 : 0;
    }
    expect_same( 600, $written, 'Hold a full catalog without eviction failures' );
    expect_same( 600, YSCacheEarlyStore::stats( $directory )['entries'] - 0 >= 600 ? 600 : -1, 'Count stored entries' );
    $readable = 0;
    for ( $i = 0; $i < 600; $i++ ) {
        $readable += YSCacheEarlyStore::read( $directory, $host, '/product/item-' . $i . '/', $i % 2 ? 'm' : 'd' ) !== null ? 1 : 0;
    }
    expect_same( 600, $readable, 'All catalog entries stay readable (no 256-entry cap)' );

    // 完整性：磁碟內容遭竄改或過期時不送出。
    $fresh = YSCacheEarlyStore::snapshot( $directory, $path );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $fresh, $headers, $html, 600, 'd' ), 'Fill a page under fresh generations' );
    $key = hash( 'sha256', $host . "\n" . $path . "\nd" );
    $file = $directory . '/' . substr( $key, 0, 2 ) . '/' . $key . '.page';
    $record = file_get_contents( $file );
    [ $metadata, $body ] = explode( "\n", $record, 2 );
    $decoded = json_decode( $metadata, true, 8 );
    $decoded['expires'] = time() - 1;
    file_put_contents( $file, json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, 'd' ), 'Do not serve an expired record' );
    $decoded = json_decode( $metadata, true, 8 );
    $decoded['path'] = '/wrong-path/';
    file_put_contents( $file, json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, 'd' ), 'Verify the embedded path even when the filename matches' );
    $decoded['path'] = $path;
    $decoded['headers'][] = 'Set-Cookie: synthetic=1';
    file_put_contents( $file, json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, 'd' ), 'Revalidate security headers on disk before replay' );
    file_put_contents( $file, $metadata . "\n" . gzencode( str_replace( 'fixture', 'corruption', $html ) ) );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, 'd' ), 'Do not serve corrupted HTML' );
    file_put_contents( $file, $record );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $path, 'd' ) ), 'The restored record is served again' );

    // 清理：過期與世代失效的項目會被刪除，有效項目保留。
    $decoded = json_decode( $metadata, true, 8 );
    $expiredKey = hash( 'sha256', $host . "\n/product/item-1/\nm" );
    $expiredFile = $directory . '/' . substr( $expiredKey, 0, 2 ) . '/' . $expiredKey . '.page';
    expect_same( true, is_file( $expiredFile ), 'Locate a catalog entry file' );
    [ $expiredMeta, $expiredBody ] = explode( "\n", file_get_contents( $expiredFile ), 2 );
    $expiredData = json_decode( $expiredMeta, true, 8 );
    $expiredData['expires'] = time() - 5;
    file_put_contents( $expiredFile, json_encode( $expiredData, JSON_UNESCAPED_SLASHES ) . "\n" . $expiredBody );
    $removed = YSCacheEarlyStore::prune( $directory, 10.0 );
    expect_same( true, $removed >= 1, 'Prune removes expired and stale entries' );
    expect_same( false, is_file( $expiredFile ), 'The expired entry file is gone' );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $path, 'd' ) ), 'Prune keeps valid entries' );

    // 舊版（格式 2）檔案會被清掉。
    mkdir( $directory . '/a', 0700 );
    file_put_contents( $directory . '/a/legacy.page', 'old' );
    file_put_contents( $directory . '/.pixel-warm.json', '{}' );
    @chmod( $directory . '/a/legacy.page', 0600 );
    @chmod( $directory . '/.pixel-warm.json', 0600 );
    YSCacheEarlyStore::purgeLegacy( $directory );
    expect_same( false, is_dir( $directory . '/a' ), 'Remove legacy single-character buckets' );
    expect_same( false, is_file( $directory . '/.pixel-warm.json' ), 'Remove legacy pixel warm state' );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $path, 'd' ) ), 'Legacy cleanup keeps current entries' );

    // 在 CLI 執行讀取器會直接返回，不送出也不刪除項目。
    $_SERVER = array_replace( $_SERVER, [ 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => $host, 'REQUEST_URI' => $path ] );
    $gate = require dirname( __DIR__ ) . '/templates/early-reader.php';
    $gate( [ 'directory' => $directory, 'host' => $host ] );
    expect_same( $html, stored_html( YSCacheEarlyStore::read( $directory, $host, $path, 'd' ) ), 'Running the reader in CLI returns without serving or removing the entry' );

    echo "Early HTML store checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $entry ) {
        if ( $entry->isDir() && ! $entry->isLink() ) {
            rmdir( $entry->getPathname() );
        } else {
            unlink( $entry->getPathname() );
        }
    }
    rmdir( $root );
}
