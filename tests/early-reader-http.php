<?php

use YangSheep\Cache\YSCacheEarlyStore;

if ( PHP_SAPI !== 'cli' ) {
    exit;
}
if ( ! function_exists( 'curl_init' ) || ! function_exists( 'proc_open' ) ) {
    echo "Early reader HTTP checks skipped (curl or proc_open unavailable).\n";
    exit( 0 );
}

function expect_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-cache-reader-http-' . bin2hex( random_bytes( 6 ) );
define( 'ABSPATH', $root . '/public/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
mkdir( WP_CONTENT_DIR, 0700, true );
require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';

$port = 0;
$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
$port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
fclose( $probe );
$host = '127.0.0.1:' . $port;
$directory = $root . '/storage';
$policy = $root . '/policy.php';
file_put_contents( $policy, "<?php // security policy fixture\n" );
$html = '<!doctype html><html><head><title>t</title></head><body>' . str_repeat( '<p>Cached product list</p>', 200 ) . '</body></html>';
$headers = [ 'Content-Type: text/html; charset=UTF-8', 'Strict-Transport-Security: max-age=63072000', 'X-Frame-Options: SAMEORIGIN' ];

$server = null;
try {
    expect_same( true, YSCacheEarlyStore::initialize( $directory ), 'Initialize the store' );
    expect_same( true, YSCacheEarlyStore::write( $directory, $host, '/hello/', YSCacheEarlyStore::snapshot( $directory, '/hello/' ), $headers, $html, 600, 'd' ), 'Seed the desktop entry' );

    $env = array_merge( getenv(), [
        'YS_TEST_ABSPATH' => ABSPATH, 'YS_TEST_DIRECTORY' => $directory, 'YS_TEST_HOST' => $host,
        'YS_TEST_POLICY' => $policy, 'YS_TEST_DIGEST' => hash_file( 'sha256', $policy ),
    ] );
    $command = [ PHP_BINARY, '-S', $host, __DIR__ . '/fixtures/early-router.php' ];
    $server = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $root . '/server.log', 'a' ], 2 => [ 'file', $root . '/server.log', 'a' ] ], $pipes, __DIR__, $env );
    $ready = false;
    for ( $i = 0; $i < 50 && ! $ready; $i++ ) {
        usleep( 100000 );
        $socket = @fsockopen( '127.0.0.1', $port, $errno, $error, 0.2 );
        if ( $socket ) {
            fclose( $socket );
            $ready = true;
        }
    }
    expect_same( true, $ready, 'Start the PHP built-in server' );

    $fetch = static function ( string $path, array $extra = [], bool $head = false ) use ( $host ): array {
        $curl = curl_init( 'http://' . $host . $path );
        curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => $head,
            CURLOPT_HTTPHEADER => array_merge( [ 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/148.0', 'Accept: text/html' ], $extra ),
            CURLOPT_ENCODING => null,
        ] );
        $response = (string) curl_exec( $curl );
        $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $size = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
        curl_close( $curl );
        $headers = [];
        foreach ( explode( "\r\n", substr( $response, 0, $size ) ) as $line ) {
            if ( str_contains( $line, ':' ) ) {
                [ $name, $value ] = explode( ':', $line, 2 );
                $headers[ strtolower( trim( $name ) ) ][] = trim( $value );
            }
        }
        return [ 'status' => $status, 'headers' => $headers, 'body' => substr( $response, $size ) ];
    };
    $header = static fn ( array $response, string $name ): ?string => $response['headers'][ $name ][0] ?? null;

    $gzip = $fetch( '/hello/', [ 'Accept-Encoding: gzip, deflate, br' ] );
    expect_same( 200, $gzip['status'], 'Serve the cached page' );
    expect_same( 'HIT', $header( $gzip, 'x-ys-cache' ), 'Mark the early HIT' );
    expect_same( 'early', $header( $gzip, 'x-ys-cache-engine' ), 'Name the early engine' );
    expect_same( 'gzip', $header( $gzip, 'content-encoding' ), 'Send stored gzip to gzip clients' );
    expect_same( $html, gzdecode( $gzip['body'] ), 'The gzip body decodes to the exact HTML' );
    expect_same( 'private, no-cache', $header( $gzip, 'cache-control' ), 'Allow bfcache: no no-store on public HTML' );
    expect_same( 'max-age=63072000', $header( $gzip, 'strict-transport-security' ), 'Replay security headers' );
    expect_same( 'SAMEORIGIN', $header( $gzip, 'x-frame-options' ), 'Replay frame policy' );
    expect_same( 'Accept-Encoding', $header( $gzip, 'vary' ), 'Vary on Accept-Encoding' );
    expect_same( true, $header( $gzip, 'etag' ) !== null && $header( $gzip, 'last-modified' ) !== null, 'Send validators' );

    $plain = $fetch( '/hello/' );
    expect_same( null, $header( $plain, 'content-encoding' ), 'Decompress for clients without gzip' );
    expect_same( $html, $plain['body'], 'Plain body is the exact HTML' );

    $etag = (string) $header( $gzip, 'etag' );
    $revalidated = $fetch( '/hello/', [ 'If-None-Match: ' . $etag, 'Cache-Control: max-age=0' ] );
    expect_same( 304, $revalidated['status'], 'Answer a reload with a matching ETag with 304' );
    expect_same( '', $revalidated['body'], '304 has no body' );
    expect_same( 200, $fetch( '/hello/', [ 'If-None-Match: "other"' ] )['status'], 'A different ETag gets the full page' );

    $head = $fetch( '/hello/', [], true );
    expect_same( 200, $head['status'], 'Serve HEAD from cache' );
    expect_same( (string) strlen( $html ), $header( $head, 'content-length' ), 'HEAD reports the body length' );

    $tracked = $fetch( '/hello/?utm_source=fb&fbclid=abc', [ 'Cookie: _ga=GA1.1.1.1; PHPSESSID=abc; pys_first_visit=1' ] );
    expect_same( 'HIT', $header( $tracked, 'x-ys-cache' ), 'Serve ad landings with analytics cookies' );

    $loggedIn = $fetch( '/hello/', [ 'Cookie: wordpress_logged_in_abc=1' ] );
    expect_same( 'WORDPRESS', $loggedIn['body'], 'Logged-in users go to WordPress' );
    expect_same( 'cookie', $header( $loggedIn, 'x-ys-cache-early' ), 'Explain the bypass' );
    expect_same( 'miss', $header( $fetch( '/missing/' ), 'x-ys-cache-early' ), 'Report a miss for unknown pages' );
    expect_same( 'miss', $header( $fetch( '/hello/', [ 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) Mobile/15E148' ] ), 'x-ys-cache-early' ), 'Phones need their own entry' );
    expect_same( 'query', $header( $fetch( '/hello/?add-to-cart=12' ), 'x-ys-cache-early' ), 'Functional queries go to WordPress' );

    file_put_contents( $policy, "<?php // changed security policy\n" );
    $changed = $fetch( '/hello/' );
    expect_same( 'policy', $header( $changed, 'x-ys-cache-early' ), 'Stop replaying headers after the security policy changes' );
    expect_same( 'WORDPRESS', $changed['body'], 'Fall back to WordPress on policy change' );

    echo "Early reader HTTP checks passed.\n";
} finally {
    if ( is_resource( $server ) ) {
        $status = proc_get_status( $server );
        if ( ! empty( $status['pid'] ) ) {
            DIRECTORY_SEPARATOR === '\\' ? exec( 'taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL' ) : posix_kill( (int) $status['pid'], 15 );
        }
        proc_terminate( $server );
        proc_close( $server );
    }
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $entry ) {
        $entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
    }
    @rmdir( $root );
}
