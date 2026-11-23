<?php
/** Responsive image deferral for the single-slide YS Swiper hero. */

namespace YangSheep\Cache;

defined( 'ABSPATH' ) || exit;

final class YSCacheCarousel {
    private const ATTRS = "(?:[^<>\"']|\"[^\"]*\"|'[^']*')*";
    private const RAW = "<!--.*?-->|<(?<raw>script|style|noscript|textarea|template)\b" . self::ATTRS . ">.*?</\k<raw>\s*>";
    private const IMAGE_ATTRS = [ 'src', 'class', 'alt', 'srcset', 'sizes', 'width', 'height', 'decoding', 'loading', 'fetchpriority' ];

    public static function rewriteHtml( string $html ): string {
        if ( ! str_contains( $html, 'ysslide-swiper' ) || str_contains( $html, 'data-ys-cache-carousel' ) ||
            ! class_exists( '\WP_HTML_Tag_Processor' ) || ! self::allowsAssets( $html ) ) {
            return $html;
        }

        $script = self::asset( 'assets/js/ys-cache-carousel.js' );
        $style = self::asset( 'assets/css/ys-cache-carousel.css' );
        if ( $script === '' || $style === '' ) {
            return $html;
        }

        $carousel = '<div\s+' . self::classAttr( 'swiper ysslide-swiper', 'ysslide-swiper swiper' ) . '\s*>';
        $track = '<div\s+' . self::classAttr( 'swiper-wrapper' ) . '\s*>';
        $dots = '<div\s+' . self::classAttr( 'ysslidengdots' ) . '\s*>';
        $pattern = '~(?:' . self::RAW . ')(*SKIP)(*F)|(?<carousel>' . $carousel . ')(?<track>\s*' . $track .
            ')(?<slides>.*?)(?<end></div>\s*)(?=' . $dots . ')~is';
        $rewritten = preg_replace_callback( $pattern, static function ( array $found ): string {
            $slides = self::rewriteSlides( $found['slides'] );
            if ( $slides === null ) {
                return $found[0];
            }
            return substr( $found['carousel'], 0, -1 ) . ' data-ys-cache-carousel>' . $found['track'] . $slides . $found['end'];
        }, $html );
        if ( $rewritten === null || $rewritten === $html ) {
            return $html;
        }

        $assets = '<link rel="stylesheet" id="ys-cache-carousel-css" href="' . esc_attr( $style ) . '">' .
            '<script defer id="ys-cache-carousel-js" src="' . esc_attr( $script ) . '"></script>';
        $output = preg_replace_callback( '~(?:' . self::RAW . ')(*SKIP)(*F)|</head\s*>~is',
            static fn ( array $match ): string => $assets . $match[0], $rewritten, 1, $count );
        return $count === 1 && is_string( $output ) ? $output : $html;
    }

    private static function rewriteSlides( string $slides ): ?string {
        $item = '<div\s+' . self::classAttr( 'swiper-slide' ) . '\s*>';
        $frame = '<div\s+' . self::classAttr( 'ysslidediv' ) . '\s*>';
        $image = '<img\b' . self::ATTRS . '>';
        $pattern = '~(?<open>' . $item . '\s*' . $frame . '\s*(?<link><a\b' . self::ATTRS . '>\s*)?)' .
            '(?<first>' . $image . ')\s*(?<second>' . $image . ')' .
            '(?<close>\s*(?(link)</a>\s*)</div>\s*</div>)~is';
        if ( trim( preg_replace( $pattern, '', $slides ) ?? $slides ) !== '' ) {
            return null;
        }

        $index = 0;
        $usable = true;
        $output = preg_replace_callback( $pattern, static function ( array $found ) use ( &$index, &$usable ): string {
            $first = self::image( $found['first'] );
            $second = self::image( $found['second'] );
            if ( ! $first || ! $second || $first['class'] === $second['class'] ) {
                $usable = false;
                return $found[0];
            }
            [ $desktop, $mobile ] = $first['class'] === 'ysslidepcimg' ? [ $first, $second ] : [ $second, $first ];
            if ( $index++ === 0 ) {
                return $found['open'] . self::picture( $desktop, $mobile, false, true ) . $found['close'];
            }
            $deferred = self::picture( $desktop, $mobile, true, false );
            $fallback = '<noscript>' . self::picture( $desktop, $mobile, false, false ) . '</noscript>';
            return $found['open'] . $deferred . $fallback . $found['close'];
        }, $slides );
        return $usable && $index >= 2 && $index <= 20 ? $output : null;
    }

    private static function classAttr( string ...$classes ): string {
        $values = implode( '|', array_map( static fn ( string $class ): string => preg_quote( $class, '~' ), $classes ) );
        return 'class\s*=\s*(?:"(?:' . $values . ')"|\'(?:' . $values . ')\')';
    }

    private static function image( string $html ): array {
        $image = new \WP_HTML_Tag_Processor( $html );
        if ( ! $image->next_tag( 'IMG' ) || array_diff( $image->get_attribute_names_with_prefix( '' ), self::IMAGE_ATTRS ) ) {
            return [];
        }
        $attrs = [];
        foreach ( self::IMAGE_ATTRS as $name ) {
            $value = $image->get_attribute( $name );
            if ( is_string( $value ) ) {
                $attrs[ $name ] = $value;
            }
        }
        if ( ! in_array( $attrs['class'] ?? '', [ 'ysslidepcimg', 'ysslidembimg' ], true ) ||
            preg_match( '~\A(?:https?://|//|/)[^\s]~i', $attrs['src'] ?? '' ) !== 1 ) {
            return [];
        }
        return $attrs;
    }

    private static function picture( array $desktop, array $mobile, bool $deferred, bool $initial ): string {
        $sources = [ 'media' => '(min-width: 1000px)' ];
        $sources[ $deferred ? 'data-ys-cache-carousel-srcset' : 'srcset' ] = $desktop['srcset'] ?? $desktop['src'];
        $sources += array_intersect_key( $desktop, array_flip( [ 'sizes', 'width', 'height' ] ) );
        $image = array_intersect_key( $mobile, array_flip( [ 'sizes', 'width', 'height', 'decoding' ] ) );
        $image += [
            'class' => 'ysslidepcimg ysslidembimg ys-cache-carousel-image',
            'alt'   => ( $mobile['alt'] ?? '' ) ?: ( $desktop['alt'] ?? '' ),
            'loading' => $initial || $deferred ? 'eager' : 'lazy',
            'fetchpriority' => $initial ? 'high' : 'low',
        ];
        if ( isset( $mobile['srcset'] ) ) {
            $image[ $deferred ? 'data-ys-cache-carousel-srcset' : 'srcset' ] = $mobile['srcset'];
        }
        $image[ $deferred ? 'data-ys-cache-carousel-src' : 'src' ] = $mobile['src'];
        return '<picture class="ys-cache-carousel-picture"' . ( $deferred ? ' hidden data-ys-cache-carousel-deferred' : '' ) . '>' .
            '<source' . self::attributes( $sources ) . '><img' . self::attributes( $image ) . '></picture>';
    }

    private static function attributes( array $attrs ): string {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
        }
        return $html;
    }

    private static function allowsAssets( string $html ): bool {
        if ( preg_match( '~<meta\b[^>]*\bcontent-security-policy\b~i', $html ) ) {
            return false;
        }
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Security-Policy:' ) === 0 &&
                preg_match( '~(?:[:;,]\s*)(?:default-src|script-src(?:-elem)?|style-src(?:-elem)?)(?:\s|$)~i', $header ) ) {
                return false;
            }
        }
        return true;
    }

    private static function asset( string $file ): string {
        $url = plugins_url( $file, YS_CACHE_FILE );
        $asset = wp_parse_url( $url );
        $home = wp_parse_url( home_url() );
        if ( ! isset( $asset['scheme'], $asset['host'], $home['scheme'], $home['host'] ) ||
            $asset['scheme'] !== $home['scheme'] || strcasecmp( $asset['host'], $home['host'] ) !== 0 ||
            ( $asset['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
            return '';
        }
        return add_query_arg( 'ver', YS_CACHE_VERSION, $url );
    }
}
