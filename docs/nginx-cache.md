# Nginx Cache (Beta)

[繁體中文](#繁體中文) · [Cache integrations](cache-integrations.md)

Use **Cache Management → Integrations → Nginx Cache (Beta)** for a standalone Nginx FastCGI HTML cache. Nginx owns HTML storage when the integration is enabled. YS CACHE calls the local purge endpoint for the affected page or site, then dispatches configured CDN layers from the origin outward. If the origin response cannot be confirmed, the CDN purge waits. Enable one HTML owner at a time. Turn off the YS CLOUD and LiteSpeed integrations before enabling standalone Nginx.

You need PHP cURL, access to the Nginx configuration and a loopback listener that PHP and Nginx can reach in the same network namespace. Each WordPress site needs a unique port, secret and cache key prefix. The examples use `example.com`, port `18483` and the `ys_example` FastCGI cache zone. Replace those values together. Generate a unique secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and restrict access to the files that hold it.

Add these constants to `wp-config.php` before WordPress loads. The port must be from 1024 to 65535. The secret must be 32 to 128 characters using letters, digits, `_` and `-`.

```php
define( 'YS_CACHE_NGINX_PURGE_PORT', 18483 );
define( 'YS_CACHE_NGINX_PURGE_SECRET', '<REPLACE-WITH-YOUR-UNIQUE-SECRET>' );
```

## Configure the public FastCGI cache

Add the `map` and `fastcgi_cache_path` directives in `http`. Use a fresh zone for this site's public HTML. Start with an explicit list of public paths and add only pages you have checked. This example bypasses all other paths, cookies, authorization, query strings, non-GET or non-HEAD methods, range requests and other request headers that may change the response. Review login, cart, checkout, account, forms, excluded paths and every published language before expanding the list. [NGINX FastCGI cache directives](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html)

```nginx
# http context
fastcgi_cache_path /var/cache/nginx/ys-example levels=1:2 keys_zone=ys_example:16m inactive=30m max_size=256m;

map $request_method $ys_example_skip_method {
    default 1;
    GET 0;
    HEAD 0;
}

map $request_uri $ys_example_skip_path {
    default 1;
    /about-us/ 0;
    /en/about-us/ 0;
}

map "$is_args|$http_cookie|$http_authorization|$http_range|$http_origin|$http_x_requested_with|$http_x_wp_nonce" $ys_example_skip_private {
    default 1;
    "||||||" 0;
}
```

Merge these directives into the site's existing PHP location. Retain the site's existing PHP-FPM settings and protective headers. Keep `Cache-Control`, `Set-Cookie`, `Vary` and `X-Accel-Expires` in effect. Keep one origin key per raw, query-free public path. The example bypasses storage of upstream `Vary` variants so a single exact purge operates on the entire key. [NGINX FastCGI response handling](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_ignore_headers)

```nginx
# Existing WordPress PHP location in the public server
fastcgi_cache ys_example;
fastcgi_cache_key "example.com|$request_uri";
fastcgi_cache_methods GET HEAD;
fastcgi_cache_bypass $ys_example_skip_method $ys_example_skip_path $ys_example_skip_private;
fastcgi_no_cache $ys_example_skip_method $ys_example_skip_path $ys_example_skip_private $upstream_http_set_cookie $upstream_http_vary;
fastcgi_cache_use_stale off;
```

Add this exact prefix location to the site's public virtual host so public traffic stays away from the purge routes. Keep it separate from the loopback server below.

```nginx
location ^~ /_ys-cache-nginx/ { return 404; }
```

## Configure the loopback listener

Put this server in `http`. Set the same port and secret as WordPress, and set `X-YS-Nginx-Site` to the lowercase hostname from the WordPress home URL. Use the listener for this site's purge requests only. Preserve the three response headers on all endpoint responses, including an OSS exact-key `412`.

```nginx
server {
    listen 127.0.0.1:18483 default_server;
    server_name 127.0.0.1;

    add_header X-YS-Nginx-Purge v1 always;
    add_header X-YS-Nginx-Site example.com always;
    add_header X-YS-Nginx-Mode oss always; # set to plus for the Plus example

    if ($http_x_ys_nginx_secret != "<REPLACE-WITH-YOUR-UNIQUE-SECRET>") { return 403; }

    location = /_ys-cache-nginx/status {
        if ($request_method != GET) { return 405; }
        return 204;
    }

    include /etc/nginx/ys-cache/example-com-purge-locations.conf;
    location / { return 404; }
}
```

### OSS with ngx_cache_purge 3.0.4

Load the `nginx-modules/ngx_cache_purge` 3.0.4 module built for your Nginx package. Keep purge synchronous and retain the upstream legacy exact-key miss status. Add these directives once in `http`. The loopback listener uses `X-YS-Nginx-Mode: oss`. [Upstream 3.0.4 release](https://github.com/nginx-modules/ngx_cache_purge/releases/tag/3.0.4) · [Upstream 3.0.4 directives](https://raw.githubusercontent.com/nginx-modules/ngx_cache_purge/3.0.4/README.md)

```nginx
cache_purge_background_queue off;
cache_purge_legacy_status on;
```

Use the following contents for `example-com-purge-locations.conf`. Each page purge uses the exact raw request path in `X-YS-Nginx-Path`. The site purge uses the hard-coded prefix shared by that site's public keys. Keep the endpoint results native, including exact misses.

```nginx
location = /_ys-cache-nginx/page {
    if ($request_method != PURGE) { return 405; }
    if ($http_x_ys_nginx_path !~ "^/[^?*#]*$") { return 400; }
    fastcgi_cache_purge ys_example "example.com|$http_x_ys_nginx_path";
}

location = /_ys-cache-nginx/site {
    if ($request_method != PURGE) { return 405; }
    fastcgi_cache_purge ys_example "example.com|/*";
}
```

### Nginx Plus

Use the built-in `fastcgi_cache_purge` directive and set the listener mode to `plus`. Add `purger=on` to the Plus `fastcgi_cache_path` if you need background removal of wildcard-matched files from disk. Native wildcard invalidation can take effect before those files are removed. [NGINX Plus FastCGI purge](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_cache_purge)

Use the following contents for `example-com-purge-locations.conf`. The Unix socket is deliberately absent. Reserve that path solely for this fallback and keep its parent directory under administrator control. If Nginx cannot complete a native purge and attempts a FastCGI connection, it fails instead of obtaining a success response from PHP or WordPress. Keep error handlers from converting that failure into a successful status.

```nginx
location = /_ys-cache-nginx/page {
    if ($request_method != PURGE) { return 405; }
    if ($http_x_ys_nginx_path !~ "^/[^?*#]*$") { return 400; }
    fastcgi_cache ys_example;
    fastcgi_cache_key "example.com|$http_x_ys_nginx_path";
    fastcgi_cache_purge 1;
    fastcgi_pass unix:/run/ys-cache-nginx/no-backend.sock;
    fastcgi_next_upstream off;
}

location = /_ys-cache-nginx/site {
    if ($request_method != PURGE) { return 405; }
    fastcgi_cache ys_example;
    fastcgi_cache_key "example.com|/*";
    fastcgi_cache_purge 1;
    fastcgi_pass unix:/run/ys-cache-nginx/no-backend.sock;
    fastcgi_next_upstream off;
}
```

## Verify on your host

1. Run `nginx -t` before reloading. Confirm the listener binds only to `127.0.0.1` and requests without the secret return `403`. Verify the public virtual host has no purge routes. Open **Nginx Cache (Beta)** in WordPress and confirm that the loopback listener is detected. The connection check expects an authenticated `204` with all three protocol headers.
2. Verify native bypass rules on the test host, then confirm them in WordPress, enable Nginx for HTML and save. Use Nginx logs or an existing diagnostic header to confirm two anonymous requests to each eligible page produce an origin MISS followed by a HIT. Check two eligible pages and every relevant language. Confirm that protected pages, form pages, query strings and cookies bypass the origin cache and that CSP, HSTS and other existing protective headers stay intact on a HIT. [NGINX header inheritance](https://nginx.org/en/docs/http/ngx_http_headers_module.html#add_header)
3. Use the toolbar to purge one warmed page. It should MISS on the next anonymous request while the neighboring page remains a HIT. Purge the site and check both selected pages MISS. Check origin before CDN. The plugin expects native `204` for Plus, or `200` for synchronous OSS. A native OSS `412` is accepted only for an exact page with no matching entry and verified response headers. `202` is treated as an unconfirmed origin purge.
4. Enable CDN clearing after confirming origin eviction. Verify that each edge layer refreshes after the origin in the configured order. A provider's acceptance is the start of edge propagation. Run these checks on a test host before using this Beta integration in production.

## 繁體中文

在 **快取管理 → 整合 → Nginx Cache（Beta）** 設定獨立的 Nginx FastCGI HTML 快取。啟用後由 Nginx 保存 HTML。YS CACHE 會先清除來源的單頁或整站快取，再依來源到外層的順序觸發 CDN。來源回應無法確認時，CDN 清除會暫停。啟用獨立 Nginx 前，請先關閉 YS CLOUD 與 LiteSpeed 整合。

主機須啟用 PHP cURL。WordPress 的 PHP 與 Nginx 須在相同網路命名空間，能連線到同一個 `127.0.0.1`。每個站點各用獨立連接埠、密鑰和快取鍵前綴。範例使用 `example.com`、`18483` 和 `ys_example`。請一起替換，在 `wp-config.php` 載入 WordPress 前加入兩個常數，並在 Nginx 設定中填入相同密鑰。連接埠範圍為 1024 到 65535。密鑰使用英數、`_` 與 `-`，長度 32 到 128 字元。可使用上方 PHP 指令產生隨機密鑰並限制有權讀取設定檔的帳戶。 [WordPress 與公開快取設定](#configure-the-public-fastcgi-cache)

1. 先在 `http` 加入 `fastcgi_cache_path` 與 `map` 範例，再將快取指令合併至網站現有的 PHP location。保留 PHP-FPM 與 CSP、HSTS 等防護標頭，讓 Nginx 遵守 `Cache-Control`、`Set-Cookie`、`Vary`、`X-Accel-Expires`。範例只允許列出的公開路徑，並在讀取快取前排除 Cookie、授權、查詢字串、非 GET／HEAD，以及會改變內容的請求標頭。新增路徑前先確認登入、帳戶、購物車、結帳、表單、手動排除與各語系的行為。
2. 加入獨立的 loopback listener。狀態使用 `GET /_ys-cache-nginx/status`。單頁用 `PURGE /_ys-cache-nginx/page` 搭配 `X-YS-Nginx-Path`，整站用 `PURGE /_ys-cache-nginx/site` 和設定中固定的前綴萬用字元。三者都要驗證 `X-YS-Nginx-Secret`，回應須包含 `X-YS-Nginx-Purge: v1`、`X-YS-Nginx-Site` 和 `X-YS-Nginx-Mode`。公開網站的虛擬主機請維持這些路徑回傳 404。 [Loopback 範例](#configure-the-loopback-listener)
3. OSS 使用 `ngx_cache_purge` 3.0.4，維持同步清除和原生未命中狀態。Plus 使用原生 `fastcgi_cache_purge`，並讓後備 FastCGI 連線只能失敗。請依版本擇一套用，保留各版本回傳的原始結果。 [OSS 範例](#oss-with-ngx_cache_purge-304) · [Nginx Plus 範例](#nginx-plus)
4. 執行 `nginx -t` 再重載。開啟 WordPress 的「Nginx Cache（Beta）」確認偵測結果，狀態路由需回傳 204 及三個標頭。在測試主機核對排除規則後勾選確認並啟用。選兩個公開測試頁確認來源快取先 MISS 後 HIT。清除其中一頁後，該頁應 MISS，另一頁仍 HIT。清除整站則兩頁皆應 MISS。Plus 清除成功為原生 204，OSS 同步清除成功為 200。僅接受帶有驗證標頭的 OSS 單頁原生 412 作為快取不存在。202 不算來源清除完成。最後驗證 CDN 清除順序和各節點更新。請先在測試主機驗證 Beta 整合，再套用至正式環境。 [主機驗證流程](#verify-on-your-host)
