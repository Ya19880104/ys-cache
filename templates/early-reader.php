<?php

use YangSheep\Cache\YSCacheEarlyStore;
use YangSheep\Cache\YSCachePixelTransport;

if ( ! defined( 'ABSPATH' ) ) {
    return null;
}

require_once dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
require_once dirname( __DIR__ ) . '/src/YSCachePixelTransport.php';

return static function ( string $directory, string $host, array $excludedAgents = [], bool $pixelEnabled = false ): void {
    if ( PHP_SAPI === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ||
        array_key_exists( 'HTTP_X_YS_CACHE_PIXEL_WARM', $_SERVER ) ) {
        return;
    }
    if ( $pixelEnabled ) {
        require_once dirname( __DIR__ ) . '/src/YSCachePixelWarm.php';
    }
    $pixelEndpoint = $pixelEnabled && YSCachePixelTransport::isEndpoint( $_SERVER, $_GET );
    $pixelFallback = $pixelEnabled && YSCachePixelTransport::isFallback( $_SERVER, $_GET );
    $status = http_response_code();
    $queuedHeaders = array_diff( headers_list(), [ 'X-Powered-By: PHP/' . PHP_VERSION ] );
    if ( headers_sent() ) {
        return;
    }
    if ( $queuedHeaders || $_POST || ! empty( $_SESSION ) ||
        ( $status !== false && $status !== 200 ) ||
        ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ||
        ( defined( 'SHORTINIT' ) && SHORTINIT ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ||
        ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
        ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) ) {
        if ( $pixelEndpoint ) {
            YSCachePixelTransport::deny();
        }
        return;
    }

    $userAgent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    foreach ( $excludedAgents as $excluded ) {
        if ( is_string( $excluded ) && trim( $excluded ) !== '' && stripos( $userAgent, trim( $excluded ) ) !== false ) {
            if ( $pixelEndpoint ) {
                YSCachePixelTransport::rejectEndpoint( $host );
            }
            return;
        }
    }
    if ( $pixelEndpoint ) {
        YSCachePixelTransport::dispatch( $directory, $host );
        return;
    }
    if ( $pixelFallback ) {
        YSCachePixelTransport::resumeFallback( $host );
        return;
    }
    if ( $_GET || YSCachePixelTransport::hasProtectedHeaders( $_SERVER ) ) {
        return;
    }
    $path = YSCacheEarlyStore::requestPath( $_SERVER, $_COOKIE, $host );
    $variant = $path === null ? null : YSCacheEarlyStore::requestVariant( $_SERVER );
    $entry = $variant === null ? null : YSCacheEarlyStore::read( $directory, $host, $path, $variant );
    if ( $entry === null ) {
        return;
    }
    $html = $entry['html'];
    if ( YSCachePixelTransport::hasPlaceholders( $html ) ) {
        $html = $pixelEnabled ? YSCachePixelTransport::prepareShell( $directory, $host, $html ) : null;
        if ( $html === null ) {
            return;
        }
    }

    foreach ( $entry['headers'] as $header ) {
        header( $header, false );
    }
    header( 'Cache-Control: private, no-cache, no-store, must-revalidate', true );
    header( 'X-Accel-Expires: 0', true );
    header( 'X-YS-Cache: HIT', true );
    header( 'X-YS-Cache-Engine: early', true );

    if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    exit;
};
