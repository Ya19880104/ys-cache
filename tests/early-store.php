<?php

use YangSheep\Cache\YSCacheEarlyStore;

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

function expect_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
    }
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
    $directory = $root . '/storage';
    $host = 'store.example.test';
    $path = '/guest-page/';
    $request = [
        'REQUEST_METHOD' => 'GET',
        'HTTPS' => 'on',
        'HTTP_HOST' => $host,
        'REQUEST_URI' => $path,
        'HTTP_USER_AGENT' => 'Example Desktop',
        'HTTP_ACCEPT_LANGUAGE' => 'en-US;q=0.9, zh-TW;q=0.5',
        'HTTP_SEC_CH_UA_MOBILE' => '?0',
        'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ];
    expect_same( false, YSCacheEarlyStore::initialize( ABSPATH . 'cache' ), 'Reject storage within the document root' );
    expect_same( false, YSCacheEarlyStore::initialize( $root . '/missing-parent/cache' ), 'Reject an unverified nonexistent parent' );
    expect_same( true, YSCacheEarlyStore::initialize( $directory ), 'Create an isolated directory and initial generation' );
    expect_same( $path, YSCacheEarlyStore::requestPath( $request, [], $host ), 'Allow clean HTTPS GET' );
    expect_same( $path, YSCacheEarlyStore::requestPath( array_replace( $request, [ 'REQUEST_METHOD' => 'HEAD' ] ), [], $host ), 'Allow clean HTTPS HEAD' );
    expect_same( $path, YSCacheEarlyStore::requestPath( array_replace( $request, [ 'HTTP_HOST' => $host . ':8443' ] ), [], $host . ':8443' ), 'Match a configured non-default port exactly' );
    expect_same( null, YSCacheEarlyStore::requestPath( $request, [ 'session' => 'opaque-test-value' ], $host ), 'Bypass parsed cookies' );
    expect_same( null, YSCacheEarlyStore::requestPath( $request, [], 'alias.example.test' ), 'Bypass an authority alias' );
    foreach ( [
        [ 'HTTP_HOST' => $host . ':443' ],
        [ 'REQUEST_METHOD' => 'POST' ],
        [ 'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https' ],
        [ 'HTTP_COOKIE' => 'synthetic-cookie=1' ],
        [ 'REMOTE_USER' => 'synthetic-user' ],
        [ 'HTTP_AUTHORIZATION' => 'synthetic-value' ],
        [ 'PHP_AUTH_USER' => '' ],
        [ 'HTTP_X_WP_NONCE' => 'synthetic-value' ],
        [ 'CONTENT_LENGTH' => '16' ],
        [ 'HTTP_RANGE' => 'bytes=0-10' ],
        [ 'HTTP_IF_NONE_MATCH' => '"synthetic"' ],
        [ 'HTTP_CACHE_CONTROL' => 'no-cache' ],
        [ 'HTTP_PRAGMA' => 'no-cache' ],
        [ 'HTTP_ACCEPT' => 'application/json' ],
        [ 'QUERY_STRING' => 'preview=1' ],
        [ 'REQUEST_URI' => '/guest-page/?' ],
        [ 'REQUEST_URI' => '/guest-page/#fragment' ],
        [ 'REQUEST_URI' => '/foo/%2f' ],
        [ 'REQUEST_URI' => '/foo/%' ],
        [ 'REQUEST_URI' => '/foo/%2e%2e/bar' ],
        [ 'REQUEST_URI' => '/foo/%3bparam' ],
        [ 'REQUEST_URI' => '/wp-json/example/v1' ],
        [ 'REQUEST_URI' => '/wp-login.php' ],
    ] as $override ) {
        expect_same( null, YSCacheEarlyStore::requestPath( array_replace( $request, $override ), [], $host ), 'Bypass unsafe or noncanonical requests' );
    }

    $variant = YSCacheEarlyStore::requestVariant( $request );
    $mobile = YSCacheEarlyStore::requestVariant( array_replace( $request, [ 'HTTP_SEC_CH_UA_MOBILE' => '?1' ] ) );
    $otherLanguage = YSCacheEarlyStore::requestVariant( array_replace( $request, [ 'HTTP_ACCEPT_LANGUAGE' => 'zh-TW' ] ) );
    $otherAgent = YSCacheEarlyStore::requestVariant( array_replace( $request, [ 'HTTP_USER_AGENT' => 'Example Mobile' ] ) );
    expect_same( 4, count( array_unique( [ $variant, $mobile, $otherLanguage, $otherAgent ] ) ), 'Separate mobile, language and UA variants' );
    expect_same( $variant, YSCacheEarlyStore::requestVariant( array_replace( $request, [ 'HTTP_ACCEPT_LANGUAGE' => 'en-us;q=0.9, zh-tw;q=0.5' ] ) ), 'Normalize language tag case' );
    expect_same( null, YSCacheEarlyStore::requestVariant( [ 'HTTP_USER_AGENT' => str_repeat( 'A', 513 ) ] ), 'Reject oversized UA' );
    expect_same( null, YSCacheEarlyStore::requestVariant( [ 'HTTP_ACCEPT_LANGUAGE' => str_repeat( 'a', 257 ) ] ), 'Reject oversized language header' );
    expect_same( null, YSCacheEarlyStore::requestVariant( [ 'HTTP_SEC_CH_UA_MOBILE' => 'other' ] ), 'Reject unknown device hint' );

    $html = '<!doctype html><html><body><h1>Static fixture</h1></body></html>';
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
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ ...$headers, 'X-Unrelated-Application: fixture' ], $html, 60, $variant ), 'Write a safe finished response' );
    expect_same( [ 'headers' => $headers, 'html' => $html ], YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Replay the exact HTML and allowlisted security headers only' );
    expect_same( false, YSCacheEarlyStore::claimRender( $directory, $host, $path, $variant ), 'Do not force a late render when the early entry is already eligible' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, trim( $path, '/' ), $variant ), 'Deny a noncanonical alternate path' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, rtrim( $path, '/' ), $variant ), 'Never reuse the body for the trailing slash alias' );
    expect_same( null, YSCacheEarlyStore::read( $directory, 'other.example.test', $path, $variant ), 'Never reuse the body for a second host' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $mobile ), 'Never reuse the body for a second mobile variant' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, $html, 60, $mobile ), 'Write a second variant for the same path' );
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
        expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ ...$headers, $header ], $html, 60, $variant ), 'Reject unsafe response headers' );
    }
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, '<html><script nonce="synthetic"></script></html>', 60, $variant ), 'Reject response-specific HTML nonce' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, [ 'Content-Type: application/json' ], $html, 60, $variant ), 'Reject non-HTML response' );

    $claimPath = '/new-visitor-variant/';
    expect_same( true, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $variant ), 'Allow one render attempt for a missing variant' );
    expect_same( false, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $variant ), 'Fall back on a second request during the hold' );
    expect_same( true, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $mobile ), 'Independently claim a missing variant in a second slot' );
    $claimKey = hash( 'sha256', $host . "\n" . $claimPath . "\n" . $variant );
    $marker = $directory . '/' . $claimKey[0] . '/' . substr( $claimKey, 1, 2 ) . '.render';
    expect_same( true, touch( $marker, time() - 121 ), 'Simulate the end of the render hold' );
    expect_same( true, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $variant ), 'Retry after an expired failed attempt' );
    expect_same( false, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $variant ), 'Restart the hold for the retried attempt' );
    $claimSnapshot = YSCacheEarlyStore::snapshot( $directory, $claimPath );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $claimPath, $claimSnapshot, $headers, $html, 60, $variant ), 'Store a completed variant from the claimed render' );
    expect_same( true, touch( $marker, time() - 121 ), 'Expire the hold while the early entry is current' );
    expect_same( false, YSCacheEarlyStore::claimRender( $directory, $host, $claimPath, $variant ), 'Continue to use the now-valid early entry after the hold expires' );

    $cartPath = '/guest-cart-shell/';
    $cartSnapshot = YSCacheEarlyStore::snapshot( $directory, $cartPath );
    $guestCartNonce = 'e2c1b5d7aa';
    $cartDiv = '<div class="ys-ec-mini-cart" id="ys-ec-mini-cart" data-nonce="' . $guestCartNonce . '"' . "\n" . ' ></div>';
    $cartHtml = str_replace( '</body>', $cartDiv . '</body>', $html );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $cartPath, $cartSnapshot, $headers, $cartHtml, 60, $variant ), 'Reject the guest cart nonce without WordPress verification' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $cartPath, $cartSnapshot, $headers, $cartHtml, 60, $variant, '1234567890' ), 'Reject a nonce differing from the current native guest nonce' );
    foreach ( [
        str_replace( $cartDiv, $cartDiv . $cartDiv, $cartHtml ),
        str_replace( 'id="ys-ec-mini-cart"', 'id="other-cart"', $cartHtml ),
        str_replace( 'data-nonce=', 'data-ys-ec-partition-key="synthetic" data-nonce=', $cartHtml ),
        str_replace( '</body>', '<div data-other_nonce="fixture"></div></body>', $cartHtml ),
        str_replace( '</body>', '<style nonce="synthetic"></style></body>', $cartHtml ),
    ] as $unsafeCart ) {
        expect_same( false, YSCacheEarlyStore::write( $directory, $host, $cartPath, $cartSnapshot, $headers, $unsafeCart, 60, $variant, $guestCartNonce ), 'Accept only one exact unpartitioned guest cart and no other nonce markers' );
    }
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $cartPath, $cartSnapshot, $headers, $cartHtml, 60, $variant, $guestCartNonce ), 'Accept a cart whose only nonce matched native WordPress' );
    expect_same( $cartHtml, YSCacheEarlyStore::read( $directory, $host, $cartPath, $variant )['html'] ?? null, 'Replay the verified original guest cart HTML unchanged' );

    $otherPath = '/another-page/';
    $otherSnapshot = YSCacheEarlyStore::snapshot( $directory, $otherPath );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $otherPath, $otherSnapshot, $headers, $html, 60, $variant ), 'Write a second path' );
    expect_same( true, YSCacheEarlyStore::invalidatePath( $directory, rtrim( $path, '/' ) ), 'Purge a normalized path' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Purge the main variant' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $mobile ), 'Purge the mobile variant at the same time' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $path, $snapshot, $headers, $html, 60, $variant ), 'Do not publish an in-flight response from before the path purge' );
    expect_same( $html, YSCacheEarlyStore::read( $directory, $host, $otherPath, $variant )['html'] ?? null, 'Preserve unrelated paths on a page purge' );
    expect_same( true, YSCacheEarlyStore::invalidateAll( $directory ), 'Rotate the site generation atomically' );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $otherPath, $variant ), 'Global purge invalidates all paths' );
    expect_same( false, YSCacheEarlyStore::write( $directory, $host, $otherPath, $otherSnapshot, $headers, $html, 60, $variant ), 'Do not publish stale content after a global purge' );

    $fresh = YSCacheEarlyStore::snapshot( $directory, $path );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, $path, $fresh, $headers, $html, 60, $variant ), 'Fill a page under fresh generations' );
    $key = hash( 'sha256', $host . "\n" . $path . "\n" . $variant );
    $file = $directory . '/' . $key[0] . '/' . $key . '.page';
    $record = file_get_contents( $file );
    [ $metadata, $body ] = explode( "\n", $record, 2 );
    $decoded = json_decode( $metadata, true, 8 );
    $decoded['expires'] = time() - 1;
    file_put_contents( $file, json_encode( $decoded ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Do not serve an expired record' );
    $decoded = json_decode( $metadata, true, 8 );
    $decoded['path'] = '/wrong-path/';
    file_put_contents( $file, json_encode( $decoded ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Verify the embedded path even when the filename matches' );
    $decoded['path'] = $path;
    $decoded['headers'][] = 'Set-Cookie: synthetic=1';
    file_put_contents( $file, json_encode( $decoded ) . "\n" . $body );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Revalidate security headers on disk before replay' );
    file_put_contents( $file, $metadata . "\n" . str_replace( 'fixture', 'corruption', $body ) );
    expect_same( null, YSCacheEarlyStore::read( $directory, $host, $path, $variant ), 'Do not serve corrupted HTML' );
    file_put_contents( $file, $record );

    $_SERVER = array_replace( $_SERVER, $request );
    $gate = require dirname( __DIR__ ) . '/templates/early-reader.php';
    $gate( $directory, $host );
    expect_same( $html, YSCacheEarlyStore::read( $directory, $host, $path, $variant )['html'] ?? null, 'Running the reader in CLI returns without serving or removing the entry' );

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
