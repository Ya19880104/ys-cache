<?php
/** Limit Elementor's native document cache while WordPress renders a private response. */

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheElementor {
    private const META = '_elementor_element_cache';
    private bool $reading = false;
    private bool $expectingWrite = false;

    private function __construct( private int $postId, private string $directory, private string $key, private int $ttl ) {}

    public static function register( int $postId, string $path, string $language, array $generations, int $ttl ): void {
        $document = '\Elementor\Core\Base\Document';
        if ( ! defined( $document . '::CACHE_META_KEY' ) || $document::CACHE_META_KEY !== self::META
            || get_post_meta( $postId, '_elementor_edit_mode', true ) !== 'builder'
            || get_option( 'elementor_element_cache_ttl' ) === 'disable' ) {
            return;
        }
        $directory = YSCacheStorage::directory();
        if ( $directory === '' ) {
            return;
        }
        $key = hash( 'sha256', implode( "\n", [
            'elementor', YS_CACHE_VERSION, ELEMENTOR_VERSION, $postId, home_url(), $path,
            determine_locale(), $language, ...$generations,
        ] ) );
        $cache = new self( $postId, $directory, $key, $ttl );
        add_filter( 'get_post_metadata', [ $cache, 'read' ], PHP_INT_MAX, 4 );
        add_filter( 'update_post_metadata', [ $cache, 'observeWrite' ], PHP_INT_MAX, 4 );
    }

    public function read( $override, int $id, string $key, bool $single ) {
        if ( $key !== self::META || $id !== $this->postId || ! $single || $override !== null || $this->reading ) {
            return $override;
        }
        // Restrict the veto to the builder's HTML render. Other consumers of this metadata
        // (including the editor, cache invalidation and WordPress metadata internals) keep their own behavior.
        $fromDocument = false;
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 ) as $frame ) {
            if ( ( $frame['class'] ?? '' ) === 'Elementor\Core\Base\Document' && $frame['function'] === 'get_document_cache' ) {
                $fromDocument = true;
                break;
            }
        }
        if ( ! $fromDocument ) {
            return $override;
        }
        $receipt = YSCacheStorage::read( $this->directory, $this->key, $this->ttl );
        if ( $receipt !== null ) {
            $this->reading = true;
            try {
                $native = get_post_meta( $id, self::META, true );
            } finally {
                $this->reading = false;
            }
            if ( is_string( $native ) && hash_equals( $receipt, hash( 'sha256', $native ) ) ) {
                $data = json_decode( $native, true );
                if ( is_int( $data['timeout'] ?? null ) && $data['timeout'] >= current_time( 'timestamp' )
                    && ! empty( $data['value']['content'] ) ) {
                    if ( ! headers_sent() ) {
                        header( 'X-YS-Elementor-Cache: HIT', true );
                    }
                    return $native;
                }
            }
        }
        $this->expectingWrite = true;
        if ( ! headers_sent() ) {
            header( 'X-YS-Elementor-Cache: MISS', true );
        }
        return '';
    }

    public function observeWrite( $override, int $id, string $key, $value ) {
        if ( $key !== self::META || $id !== $this->postId || ! $this->expectingWrite || $override !== null || ! is_string( $value ) ) {
            return $override;
        }
        $this->expectingWrite = false;
        $data = json_decode( $value, true );
        $html = $data['value']['content'] ?? null;
        if ( ! is_string( $html ) || $html === '' || ! YSCacheIntegrations::cacheableHtml( $html )
            || preg_match( '~<form\b|<(?:script|style)\s[^>]*\bnonce\s*=~i', $html ) ) {
            return $override;
        }
        // Only the digest is stored. A failed update, native deletion or concurrent render
        // cannot serve old markup because every hit also requires the matching native record.
        YSCacheStorage::write( $this->directory, $this->key, hash( 'sha256', $value ) );
        return $override;
    }
}
