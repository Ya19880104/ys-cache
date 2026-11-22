<?php

namespace YangSheep\Cache;

use YangSheep\Cache\Vendor\Peast\Formatter\Compact;
use YangSheep\Cache\Vendor\Peast\Peast;

defined( 'ABSPATH' ) || exit;

final class YSCacheAssetOptimization {
    private const MAX_BYTES = 524288;
    private const MAX_JS_BYTES = 131072;
    private const MAX_FILES = 256;
    private const RETENTION = 14 * 86400;
    private const REFRESH_AFTER = 12 * 3600;
    private const PRIVATE_PATH = '~(?:^|/)(?:private|protected|woocommerce_uploads|wc-logs|node_modules|tests?|fixtures?|backups?|\.[^/]+)(?:/|$)~i';

    public static function register(): void {
        add_filter( 'style_loader_tag', [ self::class, 'minifyStyle' ], PHP_INT_MAX, 3 );
        add_filter( 'script_loader_tag', [ self::class, 'minifyScript' ], PHP_INT_MAX, 3 );
        add_action( 'wp_print_scripts', [ self::class, 'deferScripts' ], PHP_INT_MAX );
        add_action( 'wp_print_footer_scripts', [ self::class, 'deferScripts' ], 1 );
    }

    public static function sanitize( array $options ): array {
        return [
            'css_minify' => ! empty( $options['css_minify'] ),
            'js_minify'  => ! empty( $options['js_minify'] ),
            'js_defer'   => ! empty( $options['js_defer'] ),
            'css_excludes' => self::sanitizeRules( $options['css_excludes'] ?? '' ),
            'js_excludes'  => self::sanitizeRules( $options['js_excludes'] ?? '' ),
        ];
    }

    private static function sanitizeRules( $input ): string {
        if ( ! is_string( $input ) ) {
            return '';
        }
        $rules = [];
        foreach ( preg_split( '/\R/', substr( $input, 0, 12000 ) ) as $rule ) {
            $rule = trim( $rule );
            if ( preg_match( '~\A[A-Za-z0-9_.@%/*-]{1,512}\z~D', $rule ) ) {
                $rules[ $rule ] = true;
            }
        }
        return implode( "\n", array_keys( $rules ) );
    }

    private static function options(): array {
        static $options;
        return $options ??= self::sanitize( (array) ( YSCache::settings()['assets'] ?? [] ) );
    }

    private static function allowsOptimization(): bool {
        static $allowed;
        if ( $allowed !== null ) {
            return $allowed;
        }
        $allowed = false;
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
            ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ||
            ! empty( $_SERVER['QUERY_STRING'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ||
            ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || isset( $_SERVER['PHP_AUTH_USER'] ) ||
            is_user_logged_in() || is_feed() || is_preview() || is_search() || is_404() || is_customize_preview() ||
            ( is_singular() && post_password_required() ) || YSCacheIntegrations::bypass() ) {
            return false;
        }
        foreach ( array_keys( $_COOKIE ) as $cookie ) {
            foreach ( YSCacheIntegrations::cookiePrefixes() as $prefix ) {
                if ( str_starts_with( (string) $cookie, $prefix ) ) {
                    return false;
                }
            }
        }
        return $allowed = true;
    }

    public static function minifyStyle( string $tag, string $handle, string $source ): string {
        return self::options()['css_minify'] && self::allowsOptimization()
            ? self::replaceTagSource( $tag, $handle, $source, 'css' ) : $tag;
    }

    public static function minifyScript( string $tag, string $handle, string $source ): string {
        return self::options()['js_minify'] && self::allowsOptimization()
            ? self::replaceTagSource( $tag, $handle, $source, 'js' ) : $tag;
    }

    public static function deferScripts(): void {
        $options = self::options();
        if ( ! $options['js_defer'] || ! self::allowsOptimization() ) {
            return;
        }
        $scripts = wp_scripts();
        foreach ( $scripts->queue as $entry ) {
            $handle = explode( '?', $entry, 2 )[0];
            $asset = $scripts->registered[ $handle ] ?? null;
            if ( ! $asset || ! is_string( $asset->src ) || in_array( $handle, $scripts->done, true ) ||
                $scripts->get_data( $handle, 'strategy' ) || $scripts->get_data( $handle, 'before' ) ||
                $scripts->get_data( $handle, 'after' ) || $scripts->get_data( $handle, 'data' ) ||
                ! empty( $asset->textdomain ) ) {
                continue;
            }
            if ( self::localSource( $asset->src, $handle, 'js' ) !== null ) {
                wp_script_add_data( $handle, 'strategy', 'defer' );
            }
        }
    }

    private static function replaceTagSource( string $html, string $handle, string $source, string $type ): string {
        if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || ! self::sourceCanBeMinified( $source, $type ) ) {
            return $html;
        }
        $tag = new \WP_HTML_Tag_Processor( $html );
        $attribute = $type === 'css' ? 'href' : 'src';
        $name = $type === 'css' ? 'LINK' : 'SCRIPT';
        while ( $tag->next_tag( $name ) ) {
            $url = $tag->get_attribute( $attribute );
            if ( ! is_string( $url ) || $url !== html_entity_decode( $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ||
                $tag->get_attribute( 'integrity' ) !== null ||
                strtolower( trim( (string) $tag->get_attribute( 'crossorigin' ) ) ) === 'use-credentials' ) {
                continue;
            }
            if ( $type === 'js' && ! in_array( strtolower( (string) $tag->get_attribute( 'type' ) ), [ '', 'text/javascript', 'application/javascript' ], true ) ) {
                continue;
            }
            $asset = self::localSource( $url, $handle, $type );
            $optimized = $asset === null ? null : self::optimizedUrl( $asset, $type );
            if ( $optimized === null ) {
                return $html;
            }
            $tag->set_attribute( $attribute, $optimized );
            return $tag->get_updated_html();
        }
        return $html;
    }

    private static function sourceCanBeMinified( string $url, string $type ): bool {
        return ! preg_match( '~[.-]min\.' . $type . '(?:[?#]|\z)~i', $url );
    }

    private static function localSource( string $url, string $handle, string $type ): ?array {
        $page = self::site();
        $parts = self::parseUrl( $url, $page );
        if ( $parts === null || ! self::sameOrigin( $parts, $page ) || isset( $parts['fragment'] ) ||
            ( isset( $parts['query'] ) && ( ! str_starts_with( $parts['query'], 'ver=' ) ||
                ! preg_match( '/\A[A-Za-z0-9._~+-]{1,128}\z/D', rawurldecode( substr( $parts['query'], 4 ) ) ) ) ) ) {
            return null;
        }
        $path = self::publicPath( $parts['path'] );
        if ( $path === null || strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== $type ||
            self::excluded( $handle, $path, $type ) || self::criticalAsset( $handle, $path ) ) {
            return null;
        }
        foreach ( self::roots() as [ $prefix, $root ] ) {
            if ( ! str_starts_with( $path, $prefix ) ) {
                continue;
            }
            $candidate = $root . '/' . substr( $path, strlen( $prefix ) );
            $file = is_link( $candidate ) ? false : realpath( $candidate );
            if ( $file !== false && str_starts_with( wp_normalize_path( $file ), $root . '/' ) &&
                is_file( $file ) && is_readable( $file ) ) {
                return [ 'file' => $file, 'parts' => $parts ];
            }
        }
        return null;
    }

    private static function criticalAsset( string $handle, string $path ): bool {
        return (bool) preg_match( '~\A(?:jquery|wp-|wc[-_]|woocommerce|blocksy|ct-|ys[-_](?:cart|ec)|wpforms|fluentform|jet-form|wpcf7|contact-form-7|translatepress|trp-|underscore|backbone|moment)~i', $handle )
            || (bool) preg_match( '~/(?:woocommerce|ys-cart[^/]*|blocksy(?:-companion)?|contact-form-7|wpforms(?:-lite)?|fluentform(?:pro)?|jetformbuilder|translatepress[^/]*|ys-translatepress[^/]*)(?:/|$)~i', $path );
    }

    private static function excluded( string $handle, string $path, string $type ): bool {
        foreach ( explode( "\n", self::options()[ $type . '_excludes' ] ) as $rule ) {
            if ( $rule === '' ) {
                continue;
            }
            $subject = str_starts_with( $rule, '/' ) ? $path : $handle;
            $pattern = str_replace( '\*', '.*', preg_quote( $rule, '~' ) );
            if ( preg_match( '~\A' . $pattern . '\z~Di', $subject ) ) {
                return true;
            }
        }
        return false;
    }

    private static function roots(): array {
        static $roots;
        if ( $roots !== null ) {
            return $roots;
        }
        $roots = [];
        $site = self::site();
        foreach ( [
            [ plugins_url( '/' ), WP_PLUGIN_DIR ],
            [ content_url( '/themes/' ), WP_CONTENT_DIR . '/themes' ],
            [ content_url( '/mu-plugins/' ), WPMU_PLUGIN_DIR ],
            [ includes_url(), ABSPATH . WPINC ],
        ] as [ $url, $directory ] ) {
            $origin = self::parseUrl( $url );
            $real = realpath( $directory );
            if ( $origin !== null && self::sameOrigin( $origin, $site ) && $real !== false ) {
                $prefix = rtrim( rawurldecode( $origin['path'] ), '/' ) . '/';
                if ( $prefix !== '/' ) {
                    $roots[] = [ $prefix, rtrim( wp_normalize_path( $real ), '/' ) ];
                }
            }
        }
        return $roots;
    }

    private static function site(): array {
        static $site;
        return $site ??= self::parseUrl( home_url( '/' ) ) ?? [];
    }

    private static function parseUrl( string $url, array $site = [] ): ?array {
        if ( $url === '' || strlen( $url ) > 2300 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
            return null;
        }
        if ( $site !== [] && str_starts_with( $url, '//' ) ) {
            $url = $site['scheme'] . ':' . $url;
        } elseif ( $site !== [] && $url[0] === '/' ) {
            $url = self::origin( $site ) . $url;
        }
        $parts = wp_parse_url( $url );
        if ( ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( $parts['scheme'], [ 'http', 'https' ], true ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return null;
        }
        $parts['host'] = strtolower( $parts['host'] );
        $parts['path'] ??= '/';
        $parts['port'] ??= $parts['scheme'] === 'https' ? 443 : 80;
        return $parts;
    }

    private static function sameOrigin( array $first, array $second ): bool {
        return isset( $second['host'] ) && $first['host'] === $second['host'] &&
            $first['scheme'] === $second['scheme'] && $first['port'] === $second['port'];
    }

    private static function origin( array $parts ): string {
        $default = $parts['scheme'] === 'https' ? 443 : 80;
        return $parts['scheme'] . '://' . $parts['host'] . ( $parts['port'] === $default ? '' : ':' . $parts['port'] );
    }

    private static function publicPath( string $path ): ?string {
        $decoded = rawurldecode( $path );
        if ( preg_match( '~%(?:2f|5c|25)|%(?![0-9a-f]{2})~i', $path ) ||
            preg_match( '~//|[\x00-\x20\x7f?#\\\\]~', $decoded ) || preg_match( self::PRIVATE_PATH, $decoded ) ) {
            return null;
        }
        return $decoded;
    }

    private static function optimizedUrl( array $asset, string $type ): ?string {
        static $known = [];
        $parts = $asset['parts'];
        $file = $asset['file'];
        $identity = $type . '|' . $file . '|' . $parts['path'];
        if ( array_key_exists( $identity, $known ) ) {
            return $known[ $identity ];
        }
        $known[ $identity ] = null;
        $limit = $type === 'js' ? self::MAX_JS_BYTES : self::MAX_BYTES;
        $contents = @file_get_contents( $file, false, null, 0, $limit + 1 );
        if ( ! is_string( $contents ) || strlen( $contents ) > $limit || $contents === '' ||
            str_contains( $contents, "\0" ) ) {
            return null;
        }
        $storage = self::storage();
        if ( $storage === null ) {
            return null;
        }
        $hash = hash( 'sha256', YS_CACHE_VERSION . '|' . self::origin( $parts ) . $parts['path'] . '|' . $contents );
        $filename = $hash . '.' . $type;
        $target = $storage['directory'] . '/' . $filename;
        if ( ! self::reuse( $storage['directory'], $target ) ) {
            $minified = $type === 'css' ? self::compactCss( $contents, $parts ) : self::compactJavaScript( $contents );
            if ( $minified === null || $minified === '' || strlen( $minified ) >= strlen( $contents ) ||
                ! self::store( $storage['directory'], $target, $minified ) ) {
                return null;
            }
        }
        return $known[ $identity ] = $storage['url'] . '/' . $filename;
    }

    private static function storage(): ?array {
        static $storage = false;
        if ( $storage !== false ) {
            return $storage;
        }
        $storage = null;
        $uploads = wp_get_upload_dir();
        $url = self::parseUrl( (string) ( $uploads['baseurl'] ?? '' ) );
        $base = realpath( (string) ( $uploads['basedir'] ?? '' ) );
        $content = realpath( WP_CONTENT_DIR );
        if ( $url === null || ! self::sameOrigin( $url, self::site() ) || $base === false || $content === false ||
            ! str_starts_with( wp_normalize_path( $base ), rtrim( wp_normalize_path( $content ), '/' ) . '/' ) ) {
            return null;
        }
        $directory = $base . '/ys-cache-assets';
        if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755 ) && ! is_dir( $directory ) ) ||
            is_link( $directory ) || ! is_writable( $directory ) ) {
            return null;
        }
        return $storage = [ 'directory' => $directory, 'url' => self::origin( $url ) . rtrim( $url['path'], '/' ) . '/ys-cache-assets' ];
    }

    private static function ready( string $target ): bool {
        return ! is_link( $target ) && is_file( $target ) && @filesize( $target ) > 0;
    }

    private static function cacheLock( string $directory ) {
        $lockPath = $directory . '/.lock';
        return is_link( $lockPath ) ? false : @fopen( $lockPath, 'c' );
    }

    private static function reuse( string $directory, string $target ): bool {
        if ( ! self::ready( $target ) ) {
            return false;
        }
        if ( time() - (int) @filemtime( $target ) < self::REFRESH_AFTER ) {
            return true;
        }
        $lock = self::cacheLock( $directory );
        if ( ! $lock ) {
            return false;
        }
        try {
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                return false;
            }
            clearstatcache( true, $target );
            // The same lock prevents pruning a file while a new HTML response starts reusing it.
            return self::ready( $target ) && ( time() - (int) @filemtime( $target ) < self::REFRESH_AFTER || @touch( $target ) );
        } finally {
            fclose( $lock );
        }
    }

    private static function store( string $directory, string $target, string $contents ): bool {
        $lock = self::cacheLock( $directory );
        if ( ! $lock ) {
            return false;
        }
        try {
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                return false;
            }
            clearstatcache( true, $target );
            if ( self::ready( $target ) ) {
                return time() - (int) @filemtime( $target ) < self::REFRESH_AFTER || @touch( $target );
            }
            $count = 0;
            foreach ( new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS ) as $entry ) {
                if ( ! $entry->isFile() || $entry->isLink() || ! preg_match( '/\A[a-f0-9]{64}\.(?:css|js)\z/D', $entry->getFilename() ) ) {
                    continue;
                }
                if ( time() - $entry->getMTime() >= self::RETENTION && @unlink( $entry->getPathname() ) ) {
                    continue;
                }
                ++$count;
            }
            if ( $count >= self::MAX_FILES || is_link( $target ) ) {
                return false;
            }
            $temporary = @tempnam( $directory, '.asset-' );
            if ( $temporary === false ) {
                return false;
            }
            try {
                return realpath( dirname( $temporary ) ) === realpath( $directory ) &&
                    @file_put_contents( $temporary, $contents ) === strlen( $contents ) && @chmod( $temporary, 0644 ) &&
                    @rename( $temporary, $target );
            } finally {
                if ( is_file( $temporary ) ) {
                    @unlink( $temporary );
                }
            }
        } catch ( \UnexpectedValueException $error ) {
            return false;
        } finally {
            fclose( $lock );
        }
    }

    private static function compactCss( string $css, array $source ): ?string {
        if ( preg_match( '//u', $css ) !== 1 ||
            preg_match( '/(?:image-set|image|src)\s*\(/i', $css ) ||
            preg_match( '/@charset\s+["\x27](?!utf-8["\x27])/i', $css ) ) {
            return null;
        }
        $result = '';
        $space = false;
        for ( $i = 0, $length = strlen( $css ); $i < $length; ) {
            $start = $i;
            $char = $css[ $i++ ];
            if ( str_contains( " \t\r\n\f", $char ) ) {
                $space = true;
                continue;
            }
            if ( $char === '\\' ) {
                // Escaped identifiers can spell URL-related functions the lexer cannot recognize.
                return null;
            }
            if ( $char === '/' && ( $css[ $i ] ?? '' ) === '*' ) {
                $end = strpos( $css, '*/', $i + 1 );
                if ( $end === false ) {
                    return null;
                }
                $i = $end + 2;
                $token = substr( $css, $start, $i - $start );
                $previous = $start > 0 ? $css[ $start - 1 ] : '';
                $next = $css[ $i ] ?? '';
                if ( ! preg_match( '/\A\/\*!|@license|@preserve|copyright/i', $token ) &&
                    ( str_contains( " \t\r\n\f{};,", $previous ) || str_contains( " \t\r\n\f{};,", $next ) ) ) {
                    continue;
                }
            } elseif ( $char === '"' || $char === "'" ) {
                $end = self::stringEnd( $css, $start );
                if ( $end === null ) {
                    return null;
                }
                $i = $end;
                $token = substr( $css, $start, $i - $start );
            } elseif ( $char === '@' && preg_match( '/\G@import(?![a-z0-9_-])/i', $css, $match, 0, $start ) ) {
                $i = $start + strlen( $match[0] );
                $import = self::cssImport( $css, $i, $source );
                if ( $import === null ) {
                    return null;
                }
                $token = '@import ' . $import;
            } elseif ( $char === 'u' || $char === 'U' ) {
                $previous = $start > 0 ? $css[ $start - 1 ] : '';
                if ( strncasecmp( substr( $css, $start, 4 ), 'url(', 4 ) === 0 && ! preg_match( '/[a-z0-9_\x80-\xff-]/i', $previous ) ) {
                    $i = $start + 4;
                    $url = self::cssFunction( $css, $i, $source );
                    if ( $url === null ) {
                        return null;
                    }
                    $token = $url;
                } else {
                    $token = $char;
                }
            } else {
                $token = $char;
            }

            $previous = $result === '' ? '' : $result[ strlen( $result ) - 1 ];
            if ( $space && ! str_contains( '{};,', $previous ) && ! str_contains( '{};,', $token[0] ) ) {
                $result .= ' ';
            }
            $result .= $token;
            $space = false;
        }
        return $result;
    }

    private static function stringEnd( string $css, int $start ): ?int {
        $quote = $css[ $start ];
        for ( $i = $start + 1, $length = strlen( $css ); $i < $length; ++$i ) {
            $char = $css[ $i ];
            if ( $char === $quote ) {
                return $i + 1;
            }
            if ( $char === '\\' ) {
                ++$i;
                if ( ( $css[ $i ] ?? '' ) === "\r" && ( $css[ $i + 1 ] ?? '' ) === "\n" ) {
                    ++$i;
                }
            } elseif ( str_contains( "\r\n\f", $char ) ) {
                return null;
            }
        }
        return null;
    }

    private static function cssImport( string $css, int &$i, array $source ): ?string {
        $i += strspn( $css, " \t\r\n\f", $i );
        if ( strncasecmp( substr( $css, $i, 4 ), 'url(', 4 ) === 0 ) {
            $i += 4;
            return self::cssFunction( $css, $i, $source );
        }
        if ( ! in_array( $css[ $i ] ?? '', [ '"', "'" ], true ) ) {
            return null;
        }
        $end = self::stringEnd( $css, $i );
        if ( $end === null ) {
            return null;
        }
        $reference = self::cssReference( substr( $css, $i + 1, $end - $i - 2 ), $source );
        $i = $end;
        return $reference === null ? null : '"' . $reference . '"';
    }

    private static function cssFunction( string $css, int &$i, array $source ): ?string {
        $i += strspn( $css, " \t\r\n\f", $i );
        if ( in_array( $css[ $i ] ?? '', [ '"', "'" ], true ) ) {
            $end = self::stringEnd( $css, $i );
            if ( $end === null ) {
                return null;
            }
            $value = substr( $css, $i + 1, $end - $i - 2 );
            $i = $end + strspn( $css, " \t\r\n\f", $end );
        } else {
            $end = strpos( $css, ')', $i );
            if ( $end === false ) {
                return null;
            }
            $value = rtrim( substr( $css, $i, $end - $i ), " \t\r\n\f" );
            if ( preg_match( '/[\x00-\x20\x7f"()\'\\\\]/', $value ) ) {
                return null;
            }
            $i = $end;
        }
        if ( ( $css[ $i++ ] ?? '' ) !== ')' ) {
            return null;
        }
        $reference = self::cssReference( $value, $source );
        return $reference === null ? null : 'url("' . $reference . '")';
    }

    private static function cssReference( string $value, array $source ): ?string {
        if ( $value === '' || $value[0] === '#' ||
            preg_match( '/[\x00-\x1f\x7f"\\\\]/', $value ) || str_contains( $value, '?' ) ) {
            return null;
        }
        if ( str_starts_with( $value, '//' ) || preg_match( '/\A[a-z][a-z0-9+.-]*:/i', $value ) ) {
            return $value;
        }
        $directory = substr( $source['path'], 0, strrpos( $source['path'], '/' ) + 1 );
        return self::origin( $source ) . ( $value[0] === '/' ? $value : $directory . $value );
    }

    private static function compactJavaScript( string $source ): ?string {
        if ( ! extension_loaded( 'mbstring' ) || preg_match( '/\b(?:currentScript|importScripts|import|Worker|SharedWorker|sourceMappingURL)\b/', $source ) ) {
            return null;
        }
        $memoryLimit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        if ( $memoryLimit > 0 && $memoryLimit - memory_get_usage( true ) < 32 * 1024 * 1024 ) {
            return null;
        }
        self::loadMinifier();
        try {
            $keepComments = (bool) preg_match( '/\/\*!|@license|@preserve|copyright/i', $source );
            $program = Peast::latest( $source, [ 'strictEncoding' => true, 'comments' => $keepComments ] )->parse();
            $formatter = $keepComments ? new class( true ) extends Compact {
                protected $renderComments = true;
            } : new Compact();
            return $program->render( $formatter );
        } catch ( \Throwable $error ) {
            return null;
        }
    }

    private static function loadMinifier(): void {
        static $loaded = false;
        if ( $loaded ) {
            return;
        }
        $loaded = true;
        spl_autoload_register( static function ( string $class ): void {
            $prefix = 'YangSheep\\Cache\\Vendor\\Peast\\';
            if ( ! str_starts_with( $class, $prefix ) ) {
                return;
            }
            $path = YS_CACHE_DIR . 'vendor/ys-cache-optimizer/peast/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
            if ( is_file( $path ) ) {
                require_once $path;
            }
        } );
    }
}
