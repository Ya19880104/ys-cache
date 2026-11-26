<?php

use YangSheep\Cache\YSCacheEarlyRequest as Request;

if ( PHP_SAPI !== 'cli' ) {
    exit;
}

function expect_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
    }
}

define( 'ABSPATH', sys_get_temp_dir() . '/ys-cache-request-test/' );
require dirname( __DIR__ ) . '/src/YSCacheEarlyStore.php';
require dirname( __DIR__ ) . '/src/YSCacheEarlyRequest.php';

$host = 'store.example.test';
$config = [ 'host' => $host ];
$desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1';
$request = [
    'REQUEST_METHOD' => 'GET',
    'HTTPS' => 'on',
    'HTTP_HOST' => $host,
    'REQUEST_URI' => '/product-category/soap/',
    'HTTP_USER_AGENT' => $desktop,
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_ACCEPT_LANGUAGE' => 'zh-TW,zh;q=0.9,en-US;q=0.8',
];
$hit = static fn ( array $server, array $cookies = [], ?array $settings = null ) => Request::evaluate( $server, $cookies, $settings ?? $config );

// 一般訪客、重新整理與條件請求都可命中。
expect_same( [ 'path' => '/product-category/soap/', 'variant' => 'd' ], $hit( $request ), 'Serve a clean category request' );
expect_same( 'd', $hit( array_replace( $request, [ 'REQUEST_METHOD' => 'HEAD' ] ) )['variant'] ?? null, 'Serve HEAD' );
foreach ( [
    [ 'HTTP_CACHE_CONTROL' => 'max-age=0' ],
    [ 'HTTP_PRAGMA' => 'no-cache', 'HTTP_CACHE_CONTROL' => 'no-cache' ],
    [ 'HTTP_IF_NONE_MATCH' => '"abc"' ],
    [ 'HTTP_IF_MODIFIED_SINCE' => 'Tue, 24 Nov 2026 10:00:00 GMT' ],
    [ 'HTTP_REFERER' => 'https://www.google.com/' ],
    [ 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_USER' => '?1' ],
    [ 'HTTP_SEC_PURPOSE' => 'prefetch' ],
    [ 'HTTP_X_REQUESTED_WITH' => 'jp.naver.line.android' ],
    [ 'HTTP_X_REQUESTED_WITH' => 'com.facebook.katana' ],
    [ 'HTTP_ACCEPT' => '' ],
    [ 'REQUEST_URI' => '/product-category/soap/?' ],
] as $override ) {
    expect_same( true, is_array( $hit( array_replace( $request, $override ) ) ), 'Serve reloads, referrals, prefetch and in-app browsers: ' . json_encode( $override ) );
}

// 追蹤參數不影響命中；其他參數一律交給 WordPress。
foreach ( [ '?utm_source=fb&utm_medium=cpc', '?fbclid=IwAR0abc', '?gclid=Cj0K&gbraid=1', '?srsltid=AfmBOo', '?mibextid=Zxz2cZ', '?UTM_Campaign=x', '?ttclid=1&_gl=1*abc' ] as $query ) {
    $tracked = $hit( array_replace( $request, [ 'REQUEST_URI' => '/product/starset/' . $query ] ) );
    expect_same( [ 'path' => '/product/starset/', 'variant' => 'd' ], $tracked, 'Map tracking parameters onto the clean page: ' . $query );
}
foreach ( [ '?add-to-cart=12', '?orderby=price', '?s=soap', '?utm_source=fb&page=2', '?preview=true', '?p=12', '?wc-ajax=get_refreshed_fragments', '?ys_cache_pixel=script' ] as $query ) {
    expect_same( 'query', $hit( array_replace( $request, [ 'REQUEST_URI' => '/product/starset/' . $query ] ) ), 'Bypass functional query strings: ' . $query );
}
expect_same( true, is_array( $hit( array_replace( $request, [ 'REQUEST_URI' => '/?campaign_ref=x' ] ), [], $config + [ 'ignore_params' => [ 'campaign_ref' ] ] ) ), 'Honor configured extra ignored parameters' );

// cookie 採排除清單：分析、session 與瀏覽紀錄 cookie 不影響送出。
$analytics = [ '_ga' => 'GA1.1.1.1', '_ga_ABC' => 'x', '_fbp' => 'fb.1', '_fbc' => 'fb.1.x', 'PHPSESSID' => 'abc', 'pbid' => 'x', 'pys_first_visit' => '1',
    'pysTrafficSource' => 'google.com', 'pys_landing_page' => 'x', 'sbjs_current' => 'x', '_gcl_au' => 'x', 'woocommerce_recently_viewed' => '12|13',
    'ys_ec_src_attr' => 'x', 'wp-settings-1' => 'x', 'cookielawinfo-checkbox' => 'yes' ];
expect_same( 'd', $hit( $request, $analytics )['variant'] ?? null, 'Ignore analytics, session and history cookies when serving' );
foreach ( [ 'wordpress_logged_in_abc123', 'wordpress_sec_abc', 'wp-postpass_abc', 'comment_author_abc', 'comment_author_email_abc',
    'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_abc', 'ys_ec_session', 'ys_ec_tks_x',
    'blc_products_wish_list', 'yith_wcwl_session_abc', 'woocs_current_currency', 'wmc_current_currency', 'trp_language', 'woo-share' ] as $name ) {
    expect_same( 'cookie', $hit( $request, $analytics + [ $name => '1' ] ), 'Bypass state cookie ' . $name );
}
$cartAllowed = $config + [ 'deny_cookies' => Request::DENY_COOKIES ];
expect_same( 'd', $hit( $request, [ 'woocommerce_items_in_cart' => '1', 'woocommerce_cart_hash' => 'x', 'wp_woocommerce_session_abc' => 'x' ], $cartAllowed )['variant'] ?? null, 'Serve cart visitors when the site enables fragments refresh' );
expect_same( 'cookie', $hit( $request, [ 'wordpress_logged_in_x' => '1' ], $cartAllowed ), 'Logged-in users always bypass' );
expect_same( 'cookie', $hit( array_replace( $request, [ 'HTTP_COOKIE' => 'malformed' ] ), [] ), 'Bypass unparsed cookie headers' );
expect_same( 'cookie', $hit( $request, [ 'custom_private' => '1' ], $config + [ 'deny_cookies' => [ 'custom_private' ] ] ), 'Honor configured cookie exclusions' );

// 方法、主機、路徑與標頭。
foreach ( [
    'method' => [ 'REQUEST_METHOD' => 'POST' ],
    'host' => [ 'HTTP_HOST' => 'alias.example.test' ],
    'host ' => [ 'HTTPS' => 'off' ],
    'path' => [ 'REQUEST_URI' => '/wp-admin/' ],
    'path ' => [ 'REQUEST_URI' => '/wp-json/wp/v2/posts' ],
    'path  ' => [ 'REQUEST_URI' => '/wp-login.php' ],
    'path   ' => [ 'REQUEST_URI' => '/foo/%2e%2e/bar/' ],
    'path    ' => [ 'REQUEST_URI' => '/feed/' ],
    'header' => [ 'HTTP_AUTHORIZATION' => 'Basic x' ],
    'header ' => [ 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest' ],
    'header  ' => [ 'HTTP_X_WP_NONCE' => 'x' ],
    'header   ' => [ 'HTTP_RANGE' => 'bytes=0-10' ],
    'header    ' => [ 'HTTP_ACCEPT' => 'application/json' ],
    'header     ' => [ 'CONTENT_LENGTH' => '16' ],
    'header      ' => [ 'HTTP_X_WC_STORE_API_NONCE' => 'x' ],
    'header       ' => [ 'HTTP_X_ORIGINAL_URL' => '/other/' ],
    'header        ' => [ 'HTTP_ORIGIN' => 'https://evil.example' ],
] as $reason => $override ) {
    expect_same( trim( $reason ), $hit( array_replace( $request, $override ) ), 'Bypass with reason ' . trim( $reason ) . ': ' . json_encode( $override ) );
}
expect_same( 'agent', $hit( array_replace( $request, [ 'HTTP_USER_AGENT' => 'ExampleBot/1.0' ] ), [], $config + [ 'deny_agents' => [ 'ExampleBot' ] ] ), 'Honor excluded agents' );

// 變體：與 wp_is_mobile() 一致，只有桌機與手機兩種。
$variant = static fn ( array $server ): ?string => Request::variant( $server, [] );
expect_same( 'd', $variant( [ 'HTTP_USER_AGENT' => $desktop ] ), 'Desktop Chrome' );
expect_same( 'd', $variant( [ 'HTTP_USER_AGENT' => str_replace( '148', '149', $desktop ) ] ), 'A new browser release keeps the same variant' );
expect_same( 'm', $variant( [ 'HTTP_USER_AGENT' => $iphone ] ), 'iPhone Safari' );
expect_same( 'm', $variant( [ 'HTTP_USER_AGENT' => $iphone . ' [FBAN/FBIOS;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/18.5;FBSS/3;FBID/phone;FBLC/zh_TW;FBOP/5]' ] ), 'Facebook in-app on iOS' );
expect_same( 'm', $variant( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36 Line/14.0.0' ] ), 'LINE in-app on Android' );
expect_same( 'm', $variant( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) Tablet' ] ), 'Android tablets follow wp_is_mobile()' );
expect_same( 'd', $variant( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15' ] ), 'iPad desktop-class Safari' );
expect_same( 'm', $variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_SEC_CH_UA_MOBILE' => '?1' ] ), 'Client hint wins, as in wp_is_mobile()' );
expect_same( 'd', $variant( [ 'HTTP_USER_AGENT' => $iphone, 'HTTP_SEC_CH_UA_MOBILE' => '?0' ] ), 'Client hint ?0 means desktop' );
expect_same( 'd', $variant( [] ), 'Missing UA is desktop' );
expect_same( null, $variant( [ 'HTTP_USER_AGENT' => str_repeat( 'A', 2049 ) ] ), 'Reject oversized UA' );
expect_same( 'd', $variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ] ), 'Language does not split variants by default' );
$languages = [ 'languages' => [ 'en_US', 'zh_TW' ] ];
expect_same( 'd-zh_tw', Request::variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_ACCEPT_LANGUAGE' => 'zh-TW,zh;q=0.9' ], $languages ), 'Add the language suggestion when configured' );
expect_same( 'd-en_us', Request::variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9' ], $languages ), 'Match the primary language' );
expect_same( 'd', Request::variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_ACCEPT_LANGUAGE' => 'ja-JP' ], $languages ), 'No suggestion for unpublished languages' );
expect_same( null, Request::variant( [ 'HTTP_USER_AGENT' => $desktop, 'HTTP_ACCEPT_LANGUAGE' => "zh\xff" ], $languages ), 'Reject malformed language headers' );
// 語言建議與頁面語言相同時不顯示建議，和沒有建議的訪客共用同一份。
$tp = [ 'languages' => [ 'en_US', 'zh_TW' ], 'language_slugs' => [ 'zh-tw' => 'zh_TW' ], 'default_language' => 'en_US' ];
$page = static fn ( string $agent, string $language, string $uri ): ?string => Request::variant( [ 'HTTP_USER_AGENT' => $agent, 'HTTP_ACCEPT_LANGUAGE' => $language, 'REQUEST_URI' => $uri ], $tp );
expect_same( 'd', $page( $desktop, 'en-US,en;q=0.9', '/shop/' ), 'No suggestion when the browser matches the default-language page' );
expect_same( 'd-zh_tw', $page( $desktop, 'zh-TW,zh;q=0.9', '/shop/' ), 'Suggest Chinese on an English page' );
expect_same( 'd', $page( $desktop, 'zh-TW,zh;q=0.9', '/zh-tw/shop/' ), 'No suggestion on the Chinese page for a Chinese browser' );
expect_same( 'm-en_us', $page( $iphone, 'en-US,en;q=0.9', '/zh-tw/' ), 'Suggest English on the Chinese page' );
expect_same( 'd', $page( $desktop, 'ja-JP', '/zh-tw/' ), 'No suggestion for unpublished languages on translated pages' );
expect_same( 'zh_TW', Request::pageLanguage( '/ZH-TW/product/x/?utm_source=a', $tp ), 'Language prefixes are case-insensitive and ignore the query' );
expect_same( 'en_US', Request::pageLanguage( '/zh-twx/', $tp ), 'Only exact prefixes select a language' );
expect_same( '', Request::pageLanguage( '/zh-tw/', [] ), 'Sites without TranslatePress have no page language' );

// 寫入端只接受乾淨請求。
expect_same( [ 'path' => '/product-category/soap/', 'variant' => 'd' ], Request::writerPath( $request, [], $config ), 'Write from a cookieless request' );
expect_same( 'm', Request::writerPath( array_replace( $request, [ 'HTTP_USER_AGENT' => $iphone ] ), [ '_ga' => '1', '_fbp' => '1', 'pys_landing_page' => 'x' ], $config )['variant'] ?? null, 'Write with analytics cookies only' );
expect_same( null, Request::writerPath( $request, [ 'PHPSESSID' => 'abc' ], $config ), 'Never write from a PHP session' );
expect_same( null, Request::writerPath( $request, [ 'woocommerce_recently_viewed' => '12' ], $config ), 'Never write with visitor history' );
expect_same( null, Request::writerPath( $request, [ 'woocommerce_items_in_cart' => '1' ], $cartAllowed ), 'Never write with a cart even when serving cart visitors' );
expect_same( null, Request::writerPath( array_replace( $request, [ 'REQUEST_URI' => '/product/starset/?utm_source=fb' ] ), [], $config ), 'Never write from a tracked URL (forms echo the query)' );
expect_same( null, Request::writerPath( array_replace( $request, [ 'QUERY_STRING' => 'x=1' ] ), [], $config ), 'Never write when the server reports a query' );
expect_same( null, Request::writerPath( $request, [ 'mysess' => '1' ], $config + [ 'session_name' => 'mysess', 'writer_cookies' => [ 'mysess' ] ] ), 'Never write from the configured session cookie' );

// 百分比編碼的大小寫不影響網址（RFC 3986）：WordPress 的連結是小寫，WordPress 的 HTTP 函式庫、Google 與通訊軟體常送大寫。
// 讀取與寫入都使用小寫的標準路徑，同一頁只存一份。
$upperEncoded = '/product-category/%E6%89%8B%E5%B7%A5%E7%9A%82/';
$lowerEncoded = '/product-category/%e6%89%8b%e5%b7%a5%e7%9a%82/';
expect_same( [ 'path' => $lowerEncoded, 'variant' => 'd' ], $hit( array_replace( $request, [ 'REQUEST_URI' => $upperEncoded ] ) ), 'Serve uppercase percent-encoding from the canonical path' );
expect_same( [ 'path' => $lowerEncoded, 'variant' => 'd' ], $hit( array_replace( $request, [ 'REQUEST_URI' => $lowerEncoded . '?fbclid=abc' ] ) ), 'The WordPress link keeps its path' );
expect_same( $lowerEncoded, Request::writerPath( array_replace( $request, [ 'REQUEST_URI' => $upperEncoded ] ), [], $config )['path'] ?? null, 'Write uppercase percent-encoding under the canonical path' );
expect_same( '/a%2db/', Request::evaluate( array_replace( $request, [ 'REQUEST_URI' => '/a%2Db/' ] ), [], $config )['path'] ?? null, 'Never decode an octet into another address' );
expect_same( '/Soap/', Request::evaluate( array_replace( $request, [ 'REQUEST_URI' => '/Soap/' ] ), [], $config )['path'] ?? null, 'Letter case outside percent-encoding is kept' );

// gzip 協商。
expect_same( true, Request::acceptsGzip( 'gzip, deflate, br, zstd' ), 'Chrome accepts gzip' );
expect_same( true, Request::acceptsGzip( 'br;q=1.0, gzip;q=0.8, *;q=0.1' ), 'Weighted gzip' );
expect_same( false, Request::acceptsGzip( 'gzip;q=0, br' ), 'gzip refused by q=0' );
expect_same( false, Request::acceptsGzip( '' ), 'No header means identity' );
expect_same( true, Request::acceptsGzip( '*' ), 'Wildcard accepts gzip' );
expect_same( false, Request::acceptsGzip( 'identity' ), 'Identity only' );

echo "Early request checks passed.\n";
