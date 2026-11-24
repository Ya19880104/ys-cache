<?php
// 供 tests/early-reader-http.php 使用：以 PHP 內建伺服器模擬 advanced-cache.php 呼叫早期讀取器。
define( 'ABSPATH', (string) getenv( 'YS_TEST_ABSPATH' ) );
$_SERVER['HTTPS'] = 'on';
$read = require dirname( __DIR__, 2 ) . '/templates/early-reader.php';
$read( [
    'directory'     => (string) getenv( 'YS_TEST_DIRECTORY' ),
    'host'          => (string) getenv( 'YS_TEST_HOST' ),
    'policy_file'   => (string) getenv( 'YS_TEST_POLICY' ),
    'policy_digest' => (string) getenv( 'YS_TEST_DIGEST' ),
] );
header( 'Content-Type: text/plain' );
echo 'WORDPRESS';
