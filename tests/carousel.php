<?php

namespace YangSheep\Cache {
    function headers_list(): array {
        return $GLOBALS['carousel_headers'] ?? [];
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }

    use YangSheep\Cache\YSCacheCarousel;

    $includes = rtrim( $argv[1] ?? '', '/\\' ) . '/wp-includes/';
    if ( ! is_file( $includes . 'html-api/class-wp-html-tag-processor.php' ) ) {
        fwrite( STDERR, "Usage: php tests/carousel.php <WordPress 6.4+ directory>\n" );
        exit( 1 );
    }
    define( 'ABSPATH', dirname( $includes ) . '/' );
    define( 'YS_CACHE_FILE', dirname( __DIR__ ) . '/ys-cache.php' );
    define( 'YS_CACHE_VERSION', 'test' );
    foreach ( [
        'class-wp-token-map.php', 'utf8.php',
        'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php',
        'html-api/class-wp-html-span.php', 'html-api/class-wp-html-doctype-info.php',
        'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php',
    ] as $file ) {
        if ( is_file( $includes . $file ) ) {
            require_once $includes . $file;
        }
    }
    require dirname( __DIR__ ) . '/src/YSCacheCarousel.php';

    function home_url(): string { return 'https://store.example.test'; }

    function plugins_url( string $file, string $plugin ): string {
        return ( $GLOBALS['carousel_asset_host'] ?? home_url() ) . '/wp-content/plugins/ys-cache/' . $file;
    }

    function wp_parse_url( string $value ): array|false { return parse_url( $value ); }

    function add_query_arg( string $name, string $value, string $url ): string { return $url . '?' . $name . '=' . $value; }

    function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }

    function expect( bool $pass, string $description ): void {
        if ( ! $pass ) {
            throw new \RuntimeException( $description );
        }
    }

    function fixture_hero( string $suffix ): string {
        $items = '';
        foreach ( range( 1, 5 ) as $index ) {
            $items .= '<div class="swiper-slide"><div class="ysslidediv"><a href="/de/shop-' . $index . '/" aria-label="Shop banner">' .
                '<img class="ysslidepcimg" alt="Banner" src="https://cdn.example.test/de/desk-' . $index . $suffix . '.jpg" ' .
                'srcset="https://cdn.example.test/de/desk-detail-' . $index . $suffix . '.jpg 1280w" sizes="100vw" width="1920" height="750">' .
                '<img decoding="async" src="/mob-' . $index . $suffix . '.jpg" alt="Banner" class="ysslidembimg" ' .
                'srcset="/mob-detail-' . $index . $suffix . '.jpg 500w" sizes="500px" width="1000" height="1000">' .
                '</a></div></div>' . "\n";
        }
        return '<div class="ysslide-wrapper"><div class="swiper ysslide-swiper"><div class="swiper-wrapper">' .
            $items . '</div><div class="ysslidengdots"><div class="swiper-pagination ysslidedots"></div></div></div></div>';
    }

    $otherCarousel = '<div class="swiper"><div class="swiper-wrapper"><div class="swiper-slide"><img src="/other.jpg"></div></div></div>';
    $otherFallback = '<noscript><img src="https://analytics.example.test/first-party-fallback"></noscript>';
    $quoteInScript = '<script>const custom = "</head>";</script>';
    $body = fixture_hero( '' ) . $otherCarousel . $otherFallback . fixture_hero( '-second' );
    $page = '<!doctype html><html><head>' . $quoteInScript . '</head><body>' . $body . '</body></html>';
    $rendered = YSCacheCarousel::rewriteHtml( $page );

    expect( str_contains( $rendered, $quoteInScript ), 'Script text is unmodified' );
    expect( str_contains( $rendered, $otherCarousel ), 'Unrelated product carousel is unmodified' );
    expect( str_contains( $rendered, $otherFallback ), 'Unrelated noscript analytics is unmodified' );
    expect( substr_count( $rendered, 'data-ys-cache-carousel>' ) === 2, 'Both recognized hero instances are marked' );
    expect( substr_count( $rendered, 'id="ys-cache-carousel-js"' ) === 1 &&
        substr_count( $rendered, 'id="ys-cache-carousel-css"' ) === 1, 'External assets are inserted only once' );
    expect( str_contains( $rendered, 'ys-cache-carousel.js?ver=test"></script></head>' ), 'Assets are attached to the real head' );
    expect( substr_count( $rendered, 'fetchpriority="high"' ) === 2, 'Only the first slide of each hero gets high priority' );
    expect( substr_count( $rendered, '<noscript><picture ' ) === 8 &&
        substr_count( $rendered, 'hidden data-ys-cache-carousel-deferred' ) === 8, 'Inactive slides preserve a separate noscript fallback' );
    expect( str_contains( $rendered, 'media="(min-width: 1000px)" srcset="https://cdn.example.test/de/desk-detail-1.jpg 1280w"' ) &&
        str_contains( $rendered, 'sizes="500px" width="1000" height="1000" decoding="async"' ) &&
        str_contains( $rendered, 'srcset="/mob-detail-1.jpg 500w" src="/mob-1.jpg"' ), 'First slide preserves responsive metadata and translated CDN URLs' );
    expect( str_contains( $rendered, 'data-ys-cache-carousel-srcset="https://cdn.example.test/de/desk-detail-2.jpg 1280w"' ) &&
        str_contains( $rendered, 'data-ys-cache-carousel-src="/mob-2.jpg"' ), 'Inactive image sources stay inert before navigation' );
    expect( str_contains( $rendered, '<a href="/de/shop-4/" aria-label="Shop banner"><picture ' ), 'Links and accessible names are preserved' );
    expect( YSCacheCarousel::rewriteHtml( $rendered ) === $rendered, 'Already-optimized HTML is unchanged' );

    $markup = '<!doctype html><html><head></head><body>' . fixture_hero( '' ) . '</body></html>';
    foreach ( [
        str_replace( 'class="ysslidepcimg"', 'class="ysslidepcimg" data-lazy-src="/unknown.jpg"', $markup ),
        str_replace( '</head>', '<meta http-equiv="Content-Security-Policy" content="script-src \'none\'"></head>', $markup ),
        '<!doctype html><html><head></head><body><template>' . fixture_hero( '' ) . '</template></body></html>',
        '<!doctype html><html><head></head><body><!-- ' . fixture_hero( '' ) . ' --></body></html>',
        str_replace( 'class="ysslidengdots"', 'class="slider-controls"', $markup ),
    ] as $unsupported ) {
        expect( YSCacheCarousel::rewriteHtml( $unsupported ) === $unsupported, 'Unverified or inert carousel markup is left alone' );
    }
    $GLOBALS['carousel_headers'] = [ "Content-Security-Policy: script-src 'none'" ];
    expect( YSCacheCarousel::rewriteHtml( $markup ) === $markup, 'Script restrictions prevent optimization' );
    $GLOBALS['carousel_headers'] = [ 'Content-Security-Policy: upgrade-insecure-requests' ];
    expect( str_contains( YSCacheCarousel::rewriteHtml( $markup ), 'data-ys-cache-carousel' ), 'Upgrade-only CSP remains compatible' );
    $GLOBALS['carousel_asset_host'] = 'https://unverified.example.test';
    expect( YSCacheCarousel::rewriteHtml( $markup ) === $markup, 'Helpers must use the same origin' );
    unset( $GLOBALS['carousel_headers'], $GLOBALS['carousel_asset_host'] );

    if ( isset( $argv[2] ) ) {
        $input = file_get_contents( $argv[2] );
        $output = is_string( $input ) ? YSCacheCarousel::rewriteHtml( $input ) : null;
        expect( is_string( $output ) && $output !== $input, 'Browser fixture was not transformed' );
        echo $output;
    } else {
        echo "Carousel responsive image and defer regressions passed.\n";
    }
}
