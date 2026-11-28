<?php

use YangSheep\Cache\YSCacheEarlyRequest;
use YangSheep\Cache\YSCacheEarlyStore;

if ( ! defined( 'ABSPATH' ) ) {
    return null;
}

require_once dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
require_once dirname( __DIR__ ) . '/src/YSCacheEarlyRequest.php';

/**
 * WordPress 載入前執行的整頁快取讀取器（由 wp-content/advanced-cache.php 呼叫）。
 *
 * 任何公開網址只要符合 YSCacheEarlyRequest 的送出規則、且儲存區有對應裝置的有效項目，就直接送出並結束；
 * 否則加上診斷標頭 X-YS-Cache-Early 後交回 WordPress。
 */
return static function ( $config = null ): void {
    // 0.1 版的 drop-in 以 (目錄, 主機, ...) 呼叫；外掛更新後到重新同步前一律交回 WordPress。
    if ( ! is_array( $config ) || ! isset( $config['host'], $config['directory'] ) ) {
        return;
    }
    if ( PHP_SAPI === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) || headers_sent() ) {
        return;
    }
    // 交給 WordPress 渲染、可能寫入共用快取的請求：之後開啟的 PHP session 不送 session.cache_limiter 的禁止快取標頭，
    // 寫入端看到的 Cache-Control、Expires、Pragma 就一定是外掛自己送的（不論與 session_start() 的先後）。
    // 觸發渲染的訪客照樣收到 YS CACHE 自己的 private 標頭。
    $quietSession = static function (): void {
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
            session_cache_limiter( '' );
        }
    };
    if ( array_key_exists( YSCacheEarlyRequest::PRELOAD_HEADER, $_SERVER ) ) {
        $quietSession();
        return;
    }
    if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
        ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ||
        ( defined( 'SHORTINIT' ) && SHORTINIT ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
        return;
    }
    $status = http_response_code();
    if ( $_POST || ! empty( $_SESSION ) || ( $status !== false && $status !== 200 ) ||
        ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) ||
        array_diff( headers_list(), [ 'X-Powered-By: PHP/' . PHP_VERSION ] ) ) {
        return;
    }

    $request = YSCacheEarlyRequest::evaluate( $_SERVER, $_COOKIE, $config );
    if ( ! is_array( $request ) ) {
        header( 'X-YS-Cache-Early: ' . $request );
        return;
    }
    $entry = YSCacheEarlyStore::read( (string) ( $config['directory'] ?? '' ), (string) $config['host'], $request['path'], $request['variant'] );
    if ( $entry === null ) {
        $quietSession();
        header( 'X-YS-Cache-Early: miss' );
        return;
    }
    // 重播的安全標頭必須來自目前的主機安全政策：每份項目記錄寫入時的政策檔雜湊，政策改變後舊項目交回 WordPress 重新產生，
    // 新渲染的頁面帶著新的標頭與雜湊，立刻可以送出，不必等下次同步設定。
    $policy = (string) ( $config['policy_file'] ?? '' );
    $digest = $policy !== '' && is_file( $policy ) ? (string) @hash_file( 'sha256', $policy ) : '';
    if ( $digest !== $entry['policy'] ) {
        $quietSession();
        header( 'X-YS-Cache-Early: policy' );
        return;
    }

    $body = $entry['body'];
    $gzip = false;
    if ( $entry['encoding'] === 'gzip' ) {
        $handlers = function_exists( 'ob_list_handlers' ) ? ob_list_handlers() : [];
        $gzip = YSCacheEarlyRequest::acceptsGzip( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) )
            && ! filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN )
            && ! array_intersect( $handlers, [ 'ob_gzhandler', 'zlib output compression' ] );
        if ( ! $gzip ) {
            $body = @gzdecode( $body );
            if ( ! is_string( $body ) || $body === '' ) {
                $quietSession();
                header( 'X-YS-Cache-Early: miss' );
                return;
            }
        }
    }

    $etag = '"' . $entry['etag'] . '"';
    $hasVary = false;
    foreach ( $entry['headers'] as $header ) {
        header( $header, false );
        $hasVary = $hasVary || stripos( $header, 'Vary:' ) === 0;
    }
    if ( ! $hasVary ) {
        header( 'Vary: Accept-Encoding', false );
    }
    // 公開 HTML 只要求每次重新驗證，不送 no-store，瀏覽器的上一頁快取（bfcache）才能運作。
    header( 'Cache-Control: private, no-cache', true );
    header( 'X-Accel-Expires: 0', true );
    header( 'X-YS-Cache: HIT', true );
    header( 'X-YS-Cache-Engine: early', true );
    header( 'ETag: ' . $etag, true );
    header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $entry['created'] ) . ' GMT', true );

    $match = (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' );
    $since = (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' );
    $notModified = $match !== ''
        ? in_array( $etag, array_map( static fn ( string $tag ): string => preg_replace( '~\AW/~', '', trim( $tag ) ), explode( ',', $match ) ), true ) || trim( $match ) === '*'
        : ( $since !== '' && ( $time = strtotime( $since ) ) !== false && $time >= $entry['created'] );
    if ( $notModified ) {
        http_response_code( 304 );
        exit;
    }
    if ( $gzip ) {
        header( 'Content-Encoding: gzip', true );
    }
    header( 'Content-Length: ' . strlen( $body ), true );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'GET' ) {
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    exit;
};
