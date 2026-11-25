# YS CACHE integration guide

[繁體中文](#繁體中文) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

## LiteSpeed Cache (Beta)

In **Cache Management → Integrations → LiteSpeed Cache**, check detection before enabling the integration. It requires a single-site WordPress installation, LiteSpeed or OpenLiteSpeed server caching, LiteSpeed Cache 7.9.2 or later and a reachable same-origin WordPress admin loopback. Turn off LiteSpeed Guest Mode and forced cache rules. Verify server-level bypasses for logins, cart and checkout sessions, forms and translated dynamic routes on the actual host, then confirm them in the integration. LiteSpeed manages origin HTML. YS CACHE can shorten storage to the configured HTML lifetime and current guest nonce window.

Entire-site and single-page clearing dispatch to LiteSpeed first. Content updates use the main automatic clearing switch and each CDN layer’s own follow setting. If YS CACHE cannot confirm origin dispatch, it pauses the CDN purge for that run. Verify origin eviction and subsequent edge refresh on the host, including on translated URLs. [LiteSpeed WordPress cache API](https://docs.litespeedtech.com/lscache/lscwp/api/)

## Nginx Cache (Beta)

Standalone Nginx FastCGI caching requires a host-configured, authenticated loopback listener. Open **Cache Management → Integrations → Nginx Cache (Beta)** and check the connection and native bypass rules before enabling. Nginx owns HTML storage and purges the origin before CDN. Set up the public cache key and loopback purge routes together. [Nginx host configuration](nginx-cache.md)

## OPcache

OPcache resets PHP’s in-memory opcode cache for the serving PHP runtime. This requires the extension and permission to call `opcache_reset()`. A WordPress request does not reset other isolated PHP pools. [PHP manual](https://www.php.net/manual/en/function.opcache-reset.php)

## CDN and CNAME

Configure the delivery hostname with your CDN first. Keep website HTML on the canonical hostname and use an asset CNAME for rewritten resources. For the scopes that include HTML, enable the verified HTML setting only after your CDN bypasses stateful requests before cache lookup.

| Scope | HTML at the canonical host | CNAME assets |
| --- | --- | --- |
| Entire site | Yes | JS, CSS, images, fonts and supported media |
| HTML only | Yes | None |
| JS and CSS | No | JS and CSS |
| Static resources | No | JS, CSS, images, fonts and supported media |
| HTML, JS and CSS | Yes | JS and CSS |

Add each CDN layer from the origin outward and choose whether it follows the main content-update purge setting. **Find resource ID** looks up an existing provider resource. Choose the provider and enter its credential, then enter the delivery hostname or fill in a known resource ID for verification. HTML layers with a blank hostname use the WordPress site hostname. Asset-only layers require the actual CDN delivery hostname, including when the resource ID is known.

| Provider | Resource ID | Credential |
| --- | --- | --- |
| Cloudflare | Zone ID | API token scoped to the zone, with Zone Read for hostname lookup and Cache Purge for clearing. [List Zones](https://developers.cloudflare.com/api/resources/zones/methods/list/) · [Purge cache](https://developers.cloudflare.com/api/resources/cache/methods/purge/) |
| Bunny CDN | Pull Zone ID | Account API key. Match a hostname already attached to the Pull Zone. [List Pull Zones](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) |
| Gcore | CDN resource ID | Gcore customer API token with IAM/CDN role access to read the resource and clear its cache. Match its primary or secondary delivery hostname. [CDN resources](https://docs.gcore.com/api-reference/cdn/cdn-resources/get-cdn-resources-list) |

Lookup confirms that the credential can read an existing resource. To verify cache clearing, enable API purge, save, then check a targeted purge against that resource. A provider can accept a purge before all of its CDN locations have applied it.

For asset URL rewriting alone, select **CNAME** and fill in the delivery hostname already configured at the provider. Select the static asset scope and turn on rewriting. Configure DNS, TLS and origin delivery at the CDN provider. This layer handles WordPress URL rewriting and has no resource lookup or API purge. To use API purge, choose the corresponding supported provider and connect its resource. Test rewritten images, scripts and styles in each published language. Keep CSP and cross-origin settings consistent with your delivery hostname.

Under **CDN Layers → Website alias hostnames**, enter the alternate domains that serve the same public paths, one hostname per line. For an alias with separate CDN resources, configure an HTML-capable row for that hostname at each CDN layer it uses. Check a manual single-page purge on both hostnames after setup. Bunny CDN accepts up to 20 URLs per YS CACHE batch, including one URL for each applicable hostname.

### Bunny CDN API key

1. Sign in to the Bunny dashboard and open **Account → API Key**. Copy the account API key. Ask the primary account owner if the key is unavailable to your account.
2. In WordPress, set the CDN layer provider to **Bunny CDN**, enter a hostname already attached to the Pull Zone, and paste the key into **API token / key**. Use **Find resource ID** to select the matching Pull Zone, then enable API purging and save. [Bunny account API keys](https://bunny.net/docs/account/api-keys)

### Gcore API token

1. Sign in to the Gcore Customer Portal. Open the avatar menu and select **Profile → API tokens → Create token**. Set a name and expiry, grant the CDN permissions needed to read resources and purge cache, and copy the token while it is displayed.
2. In WordPress, select **Gcore**, enter a hostname already attached to the CDN resource, and paste the token into **API token / key**. Use **Find resource ID** to find the matching resource, then enable API purging and save. [Gcore API tokens](https://docs.gcore.com/account-settings/api-tokens)

## Path rules and shorter lifetimes

Open **Cache settings → Exclusions**. Enter one rule per line. Copy the public URL path starting with `/`, including any language prefix and public subdirectory. For `https://example.com/site/en/contact/?source=email#form`, enter `/site/en/contact/`. Omitting the final slash from a literal rule such as `/en/contact` matches both `/en/contact` and `/en/contact/`. A literal ending in `/` matches the slash version. `/members/*` matches the members page and paths under it. Cookie rules match names by prefix, so `private_session_` matches `private_session_123`. User-Agent rules match a substring, so `ExampleBot` matches `ExampleBot/1.0`.

The global HTML lifetime can be set up to 12 hours (43,200 seconds). In the shorter-lifetime field, enter a path rule followed by a space and a positive whole number of seconds. For example, `/news/* 300` caps eligible news pages at 300 seconds. If `/news/latest/ 60` also matches, YS CACHE uses the shortest matching duration or the global lifetime, whichever is lower. HTML eligibility and nonce checks still apply. Any server or CDN copy already stored can keep its own lifetime. Check and purge each cache layer if you need an immediate change. [Why the limit is 12 hours](#lifetime)

To check the result, make two anonymous GET requests to the same URL with no query string. A fresh eligible local page typically returns `X-YS-Cache: MISS` and then `X-YS-Cache: HIT`. With the early cache, a hit served before WordPress also carries `X-YS-Cache-Engine: early`, and a request handed to WordPress carries `X-YS-Cache-Early` with the reason. A form page excluded by the plugin typically returns `X-YS-Cache: BYPASS` when WordPress handles the request. An upstream response can replay an origin header, so check the upstream status separately. An Nginx cache can report `X-FastCGI-Cache: HIT` when configured to send that header.

## Early cache

With local HTML caching on, YS CACHE serves public pages from `wp-content/advanced-cache.php` before WordPress loads. The switch is **Serve cached pages before WordPress loads** in **Cache settings → HTML cache**, on by default. It requires a single-site WordPress installation on HTTPS, `WP_CACHE` enabled in `wp-config.php`, a writable cache directory outside the site root, and an `advanced-cache.php` file that YS CACHE can manage. The settings page shows a notice while the early cache cannot run.

The early cache also stays off in two cases. A verified CDN layer that caches HTML on the canonical host keeps control of the HTML response. The official TranslatePress automatic language detection redirects the same URL differently for each visitor. Static CDN layers keep their URL rewriting and purge settings.

### What is served early

Any public `GET` or `HEAD` request on the site's own HTTPS hostname can be served, including the front page, pages, posts, the shop, categories, products and translated URLs. Admin, REST, feed, `wp-content`, `wp-includes` and `.php` paths always go to WordPress. Cart, checkout and account pages, searches and pages excluded under **Exclusions → Paths** are never stored, so they go to WordPress too.

Cookies are checked against a deny-list. A request goes to WordPress when it carries a cookie whose name starts with one of these prefixes:

| Group | Cookie name prefixes |
| --- | --- |
| WordPress | `wordpress_logged_in_`, `wordpress_sec_`, `wp-postpass_`, `comment_author_`, `wordpressuser_`, `wordpresspass_`, and the site's actual login cookie names |
| WooCommerce cart | `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`, unless the cart option is on |
| YS CART | `ys_ec_session`, `ys_ec_tks_`, `__Host-ys_ec_coupon_handoff` |
| Wishlists | `blc_products_wish_list` (Blocksy), `yith_wcwl_session_`, `tinv_wishlistkey` |
| Currency and language | `woocommerce_current_currency`, `wmc_current_currency`, `woocs_current_currency`, `aelia_cs_selected_currency`, `wcml_client_currency`, `trp_language` |
| Private store link | `woo-share` (WooCommerce coming soon) |
| Your rules | Each prefix under **Exclusions → Cookie** |

Other cookies do not affect serving. Visitors with analytics cookies such as `_ga`, `_fbp` and `pysTrafficSource`, or with a PHP session cookie such as `PHPSESSID`, receive the cached page. Storing is stricter, as described below. User-Agent rules under **Exclusions** also send matching requests to WordPress.

Tracking parameters do not change the page, so YS CACHE ignores them and serves the copy stored for the URL without them. The built-in list follows WP Rocket's list plus common ad platforms: every parameter starting with `utm_`, `hsa_`, `mtm_`, `pk_`, `sfmc_` or `trk_`, and names such as `fbclid`, `gclid`, `gbraid`, `wbraid`, `gad_source`, `msclkid`, `ttclid`, `twclid`, `li_fat_id`, `yclid`, `igshid`, `srsltid`, `mc_cid` and `_gl`. Add other names, one per line, under **Additional ignored query parameters**. Only add parameters that PHP never reads. Referral, coupon and currency links must reach WordPress. A request with any other query parameter goes to WordPress.

YS CACHE stores a desktop and a phone copy of each page. It chooses between them with the same test as WordPress `wp_is_mobile()`, which some themes and plugins use to print different markup. `Sec-CH-UA-Mobile: ?1` means phone. Without that hint, a User-Agent containing `Mobile`, `Android`, `Silk/`, `Kindle`, `BlackBerry`, `Opera Mini` or `Opera Mobi` is a phone. When the YS TranslatePress Addons language suggestion is on, each suggested language also gets its own copy.

By default, shoppers with items in the cart go to WordPress. Turn on **Serve cached pages to shoppers with items in the cart** to serve them cached pages too. WooCommerce cart fragments then refresh the mini cart after the page loads. Leave the option off when the theme shows cart-dependent prices, discounts or messages on catalog pages.

Requests with `Authorization`, `X-WP-Nonce`, an AJAX `X-Requested-With: XMLHttpRequest` header, `Origin`, `Range`, a request body or a language override header go to WordPress. Reloads, `Cache-Control: no-cache` and `Pragma` request headers, and conditional requests can be served from the cache.

### How pages are stored

YS CACHE stores a page only from a clean WordPress render or a preload request. The request is a `GET` without a query string on the site's HTTPS hostname, from a visitor who is not logged in. It has no deny-listed cookie and no PHP session cookie. Its other cookies are analytics cookies only: `_ga`, `_gid`, `_gat`, `_gcl_`, `_fbp`, `_fbc`, `_ttp`, `_clck`, `_clsk`, `_uet`, `_pin_`, `sbjs_`, PixelYourSite cookies such as `pys_` and `pbid`, and names added with the `ys_cache_writer_cookie_prefixes` filter.

The response must be a complete `200` HTML page with an empty cart, no WooCommerce notice, no cookie other than an ignorable one, and no private or `no-store` cache header from another plugin. Searches, 404 pages, feeds, previews, password-protected content, cart, checkout and account pages, WooCommerce coming soon pages, and pages with per-visitor forms or tokens are not stored. Every visitor can read the shared copy, but no visitor's state is written into it.

Copies stay outside the web root, compressed with gzip. Each hit checks the copy's integrity hash and replays the compatible security headers of the original response.

### Lifetime

Each copy expires after the cache lifetime or a shorter path lifetime under **Exclusions**, and no copy lasts longer than 12 hours. Cached pages contain WordPress nonces for guests, for example in add-to-cart, AJAX search and REST requests. WordPress accepts a nonce during the half of `nonce_life` in which it was created and during the next half. With the default one-day `nonce_life`, any page younger than 12 hours still carries valid nonces.

YS CACHE caps each copy at half of `nonce_life` minus five minutes, which is 42,900 seconds by default. A site that shortens `nonce_life` gets a shorter cap. The lifetime setting accepts up to 43,200 seconds, and the default is 28,800 seconds. Preload or the next clean visit refills expired copies.

### Preload

With **Preload public pages (desktop and phone)** on, WordPress Cron renders public pages in the background, so visitors rarely meet an empty cache. Preload covers the front page, the shop and blog pages, published products, non-empty product categories and tags, published pages other than cart, checkout, account and excluded paths, and the 50 most recent posts, up to 2,000 URLs. Adjust the list with the `ys_cache_preload_paths` filter. Preload requests each URL once as a desktop and once as a phone. Both User-Agents end in `YSCachePreload/1`. The requests carry no cookies and a signed `X-YS-Cache-Preload` header, so they always reach WordPress.

The job runs every minute. It sends one request at a time for up to 40 seconds per run and skips pages whose copy has at least 30 minutes left. A run stops early when two responses take longer than 10 seconds, a request cannot connect, or the server answers with a status such as 403, 429 or 503. Each page gets up to two attempts per device. Saving the settings, turning preload on and site-wide purges queue every URL. A targeted purge queues only the cleared URLs. An hourly pass refreshes copies that are about to expire. **Cache settings → HTML cache** shows the queue, the last run, the last error and the stored copies.

Early hits end before WordPress can start WP-Cron. When most visits are cached, trigger `wp-cron.php` from a server cron job every minute. The server must also reach the site's canonical HTTPS hostname.

### Purges and refills

Saving a product or post clears these pages:

- Its URL and its previous URL
- The front page and the shop or blog page
- Its public categories and their parent categories
- Its author and date archives
- The TranslatePress versions of these URLs

Changes that affect only one page clear that page, such as variations, product images, prices, thumbnails and approved comments. Inventory changes clear the product. When availability changes, they also clear the shop, the product's category and tag archives and the front page. Clearing a URL also clears its `/page/N/` pagination and both device copies. Local copies are cleared in the same request and preload renders them again. Server and CDN purges are still queued.

Menus, widgets, the Customizer, theme changes, plugin activation and updates, category and tag edits, Woo Discount Rules rule changes and site appearance options clear the whole site.

### Response headers

| Header | Meaning |
| --- | --- |
| `X-YS-Cache: HIT` and `X-YS-Cache-Engine: early` | YS CACHE sent the page before WordPress loaded. |
| `Cache-Control: private, no-cache` | The browser revalidates on each visit and shared caches do not store the page. Without `no-store`, back and forward navigation stays instant. |
| `ETag` and `Last-Modified` | A request with a matching `If-None-Match` gets `304 Not Modified` without a body. |
| `X-YS-Cache-Early` | The request went to WordPress. The value gives the reason. |

Early hits also send `X-Accel-Expires: 0`, so an Nginx FastCGI cache does not store them.

| `X-YS-Cache-Early` | Reason |
| --- | --- |
| `cookie` | A deny-listed cookie, such as a login, cart, wishlist or currency cookie |
| `query` | A query parameter that is not ignored |
| `header` | A header such as `Authorization` or `X-WP-Nonce`, an AJAX request, a request body, or an oversized User-Agent |
| `agent` | A User-Agent rule under **Exclusions** |
| `path` | An admin, REST, feed or file path |
| `method` | A method other than `GET` and `HEAD` |
| `host` | A hostname other than the site's HTTPS hostname |
| `miss` | No valid copy. WordPress renders the page and stores it if it qualifies. |
| `policy` | The host's security policy changed after the last sync. Save the settings to sync again. |

### PixelYourSite

YS CACHE caches pages with PixelYourSite output like any other page, as WP Rocket does, and works with PixelYourSite's existing settings. Nothing in PixelYourSite needs to change.

- PixelYourSite cookies, including `PHPSESSID`, are ignored when serving, so visitors keep receiving cached pages.
- The render that fills the cache asks PixelYourSite not to open a PHP session for that one request (the `pys_skip_session` filter). Early hits run no PHP at all, so this matches every cached page view.
- When PixelYourSite sends its server events through AJAX, its browser script creates new event IDs for every page view. Visitors who receive the same cached HTML still send their own paired browser and server events.
- When PixelYourSite instead sends server events while WordPress renders a page, the preloader pauses, so it does not record fake visits. Visitor renders still fill the cache.
- As a last safeguard, YS CACHE does not store PixelYourSite output that contains a visitor's data, such as a user ID, personal advanced-matching data or a purchase event. Clean guest renders never contain these.

### Verify the early cache

1. Save the settings or purge the HTML cache. Wait until **Cache settings → HTML cache** shows no pending preload pages.
2. Open a private window with Developer Tools → Network. Visit the front page, a category and a product. For each main document, check for `X-YS-Cache: HIT` and `X-YS-Cache-Engine: early`. A page that preload has not reached shows `X-YS-Cache-Early: miss` on the first visit and hits on the next.
3. Reload the pages. Reloads now hit too, as `200` or `304 Not Modified`, even with **Disable cache** checked in Developer Tools.
4. Open the same pages in a second browser profile, which has none of the first profile's cookies. After preload, its first visit hits. Switch Developer Tools to a phone device and reload to check the phone copy.
5. Add `?utm_source=test` to a URL, and the page still hits. Add `?test=1`, and the response shows `X-YS-Cache-Early: query`. Log in, or add a product to the cart with the cart option off, and the response shows `X-YS-Cache-Early: cookie`.
6. With PixelYourSite, compare the event requests of both profiles. Each profile sends its own event IDs.

## Forms and lifetime

YS CACHE checks the current page’s shortcodes and blocks and inspects the rendered output. A form in a shared footer or builder template can affect every page where it appears. WPForms, Fluent Forms, and JetFormBuilder default to bypass because rendered forms can include expiring tokens, session data, dynamic fields, and payment or validation state. Contact Form 7 normally bypasses too. [WPForms cache and form tokens](https://wpforms.com/docs/how-to-optimize-cache-settings-to-fix-submission-errors/) · [Fluent Forms payments and caching](https://fluentforms.com/why-fluent-forms-payments-break-with-caching-plugins-and-solutions/)

### Simple public form pages (Beta)

In **Public form paths (Beta)**, enter each public path on its own line, such as `/contact/` and `/en/contact/`. Eligible local HTML expires after five minutes or the shorter page lifetime. Exclude these HTML routes from upstream server and CDN caching. Origin responses use private, no-store headers.

| Form and version | Eligible local HTML | Guest submission verified |
| --- | --- | --- |
| WPForms Lite 2.0.5.1 | A dedicated page containing one native form shortcode. The plugin creates fresh fields, timing token, referrer and script settings. | AJAX confirmation |
| Fluent Forms 6.2.17, free edition | One simple public form with text, name, email or message fields. The plugin creates fresh fields and script settings. | AJAX success and saved test entry |
| JetFormBuilder 3.6.8.2 | One public text or message form with a submit button and either no action or one Send Email action. The plugin creates fresh fields and signatures. | Native submission and success redirect |
| Contact Form 7 6.2.x | A dedicated native shortcode page can reuse verified public form markup locally. A second shortcode or dynamic block bypasses the page. | Feedback endpoint on 6.2.5 |

To enable the first three adapters, select the per-visitor rendering switch below the public path list. Existing Contact Form 7 paths keep their original scope until you turn it on. Builder-generated forms and footer widgets use native page rendering, preserving visitor-specific content around the form. Other versions and unsupported fields also use native page rendering. The local form adapter is opt-in and does not publish the HTML to an upstream CDN.

Keep forms with payments, CAPTCHA, prefilled or private fields, login requirements, uploads, or custom form hooks on the bypass path. YS CACHE also bypasses the page when it detects another dynamic form or sensitive output. Keep the form’s protection and submission settings in place. Confirm the anonymous page renders and submit a test entry on a test host before using the option on a live site. [Contact Form 7 shortcode setup](https://contactform7.com/faq/)

### Keep one embedded form dynamic

1. Create a separate WordPress page on the same site, such as `/contact-form/`, and insert the form plugin’s standard shortcode there. The form page renders through the plugin and keeps its normal validation and submission logic.
2. Exclude that page in YS CACHE and in every server or CDN layer that caches HTML. Keep any translated form URLs excluded too.
3. Insert a same-origin iframe on the containing page, using its editor or builder’s supported embed feature. The containing page can still be cached if it is otherwise eligible.

```html
<iframe src="/contact-form/" title="Contact form" loading="lazy" height="640"></iframe>
```

Confirm that the site’s framing policy allows same-origin embeds. Check the containing page’s cache status and the form page’s bypass separately, then submit a test entry in the iframe on the test host. Repeat for each published language and verify the validation and confirmation states.

## Background clearing and inventory

Keep WordPress Cron running to process queued changes. Content edits use a 15-second window and inventory uses a 60-second window before becoming eligible. Temporary transport errors, rate limits and server errors retry with backoff for 24 hours. If Cron misses the last scheduled run, the next worker makes one final attempt. Sites that disable visitor-triggered WP-Cron need a working server scheduler. An early HTML hit returns before WordPress can start its scheduler, so a site where most visits are cached also needs one for preload. Local HTML expiry is checked on each cache read. Check the last result and next attempt under **Cache Settings → HTML Cache**. LiteSpeed uses an HTTP request to `wp-cron.php` so the worker can detect the server cache.

WooCommerce inventory updates clear the affected product and its published languages. A stock availability change also clears the shop, relevant category and tag archives, and the front page. The local HTML copies are cleared in the same request and preload renders them again. Server and CDN purges wait for the background window. Add pages that embed stock-sensitive content with the `ys_cache_stock_purge_urls` filter. Origin integrations that only support site-wide clearing leave automatic inventory purges to existing expiration and manual site-wide clearing.

## 繁體中文

### LiteSpeed Cache（Beta）

在「快取管理 → 整合 → LiteSpeed Cache」確認偵測結果後再啟用。需 WordPress 單站、已啟用快取的 LiteSpeed 或 OpenLiteSpeed 伺服器、LiteSpeed Cache 7.9.2 以上，以及可連通的 WordPress 同源後台回環請求。關閉 LiteSpeed 的訪客模式與強制快取規則。在實際主機確認伺服器會略過登入、購物車、結帳、表單及各語系的動態路徑，並在整合頁確認。啟用後由 LiteSpeed 管理來源 HTML，YS CACHE 可依 HTML 設定及訪客 nonce 效期縮短快取時間。

全站和單頁清除先送到 LiteSpeed。更新內容時依主設定自動清除，CDN 各層可選擇是否跟隨。若 YS CACHE 無法確認來源已接手清除，該次會暫停清除 CDN。請在實際主機驗證來源舊快取已移除，再檢查 CDN 與各語系的新回應。 [LiteSpeed WordPress 快取 API](https://docs.litespeedtech.com/lscache/lscwp/api/)

### Nginx Cache（Beta）

獨立 Nginx FastCGI 快取需要主機設定可驗證身分的 loopback listener。在「快取管理 → 整合 → Nginx Cache（Beta）」確認連線與伺服器排除行為後再啟用。Nginx 負責儲存 HTML，YS CACHE 先清除來源並確認結果，再清除 CDN。公開快取鍵和清除路由須一起設定。 [Nginx 主機設定](nginx-cache.md#繁體中文)

### OPcache

OPcache 清除會重設處理該 WordPress 請求的 PHP 記憶體快取。需 PHP 啟用擴充並允許呼叫 `opcache_reset()`。各自隔離的 PHP 集區需在其環境重設。 [PHP 手冊](https://www.php.net/manual/en/function.opcache-reset.php)

### CDN 和 CNAME

請先在 CDN 設好傳遞網域。HTML 使用網站主要網域，靜態資源可用獨立 CNAME。若選擇含 HTML 的範圍，請先驗證 CDN 讀取快取前會排除有狀態的請求，再開啟 CDN HTML 設定。

| 範圍 | 主要網域的 HTML | CNAME 資源 |
| --- | --- | --- |
| 全站 | 支援 | JS、CSS、圖片、字型與支援的媒體 |
| 僅 HTML | 支援 | 無 |
| JS／CSS | 無 | JS、CSS |
| 靜態資源 | 無 | JS、CSS、圖片、字型與支援的媒體 |
| HTML／JS／CSS | 支援 | JS、CSS |

由來源到外層依序新增 CDN，並設定各層是否跟隨主設定的內容更新清除。「查詢資源 ID」會配對已存在的資源。選擇供應商及填入憑證後，可輸入傳遞網域查找已存在的資源，或填入已知的 ID 進行驗證。HTML 層的網域留白時，採用 WordPress 主網域。靜態資源層需填寫實際 CDN 傳遞網域，即使已知資源 ID 也需要。

| 供應商 | 資源 ID | 憑證 |
| --- | --- | --- |
| Cloudflare | Zone ID | 使用限該 Zone 的 API Token。用網域查找需 Zone Read，清除需 Cache Purge。 [查詢 Zone](https://developers.cloudflare.com/api/resources/zones/methods/list/) · [清除快取](https://developers.cloudflare.com/api/resources/cache/methods/purge/) |
| Bunny CDN | Pull Zone ID | 使用帳戶 API Key。網域須已綁定至 Pull Zone。 [查詢 Pull Zones](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) |
| Gcore | CDN 資源 ID | 使用具備讀取與清除權限的 IAM／CDN 角色 API Token，可配對資源的主要或次要傳遞網域。 [查詢 CDN 資源](https://docs.gcore.com/api-reference/cdn/cdn-resources/get-cdn-resources-list) |

查找確認憑證可讀取既有資源。要驗證清除權限，請啟用 API 清除並儲存，再測試指定資源的單頁清除。供應商受理後，各 CDN 節點仍需時間套用。

若只需要改寫靜態資源，請選擇「CNAME」、填入已在供應商設定的 CDN 網域，選擇範圍並啟用網址改寫。請在供應商設定 DNS、TLS 和回源。純 CNAME 層只改寫 WordPress 資源網址，略過資源查找及 API 清除。要清除請選對應的支援供應商並連接資源。儲存後檢查各已發布語系的圖片、JS 和 CSS 網址及其回應，並確認 CSP 與跨網域政策允許該 CDN 網域。

在「CDN 層 → 網站別名網域」填入提供相同公開路徑的別名，一行一個主機名稱。若別名有獨立的 CDN 資源，請在該別名經過的每一層設定可傳遞 HTML 的項目。設定後，用同一頁測試主要與別名網域的手動單頁清除。Bunny CDN 每批最多處理 20 個網址，同一路徑在每個主機名稱各算一個網址。

#### Bunny CDN API Key

1. 登入 Bunny 控制台，開啟 **Account → API Key**，複製帳戶 API Key。若帳號看不到 Key，請由主帳號持有人處理。
2. 回到 WordPress 的「CDN 層」，選擇 **Bunny CDN**，填入已綁定 Pull Zone 的主機名稱及 API Key。按「查詢資源 ID」配對 Pull Zone，再啟用 API 清除並儲存。 [Bunny 帳戶 API Key](https://bunny.net/docs/account/api-keys)

#### Gcore API Token

1. 登入 Gcore Customer Portal，從頭像選單開啟 **Profile → API tokens → Create token**。設定名稱和效期，授予讀取 CDN 資源與清除快取所需權限，並在畫面顯示時複製 Token。
2. 回到 WordPress 的「CDN 層」，選擇 **Gcore**，填入已綁定 CDN 資源的主機名稱及 Token。按「查詢資源 ID」配對資源，再啟用 API 清除並儲存。 [Gcore API Token](https://docs.gcore.com/account-settings/api-tokens)

### 路徑規則與縮短快取效期

開啟「快取設定 → 排除」，每行填寫一條規則。只複製公開網址中從 `/` 開始的路徑，保留語系前綴及公開子目錄。`https://example.com/site/en/contact/?source=email#form` 應填寫為 `/site/en/contact/`。精確規則 `/en/contact` 會比對有無結尾斜線的兩種網址，填成 `/en/contact/` 則會比對有斜線的版本。`/members/*` 會比對會員頁及其子路徑。Cookie 規則依名稱前綴比對，例如 `private_session_` 會比對 `private_session_123`。User-Agent 規則比對其中一段文字，例如 `ExampleBot` 會比對 `ExampleBot/1.0`。

全站 HTML 效期最長可設為 12 小時（43,200 秒）。在縮短效期欄位，每行輸入一個路徑規則、空白及正整數秒數。例如 `/news/* 300` 將符合快取條件的新聞頁限制在 300 秒內。若同時符合 `/news/latest/ 60`，YS CACHE 會從符合的規則及全站效期中取最短值。HTML 適用條件與 nonce 效期仍然生效。伺服器或 CDN 已存入的快取可能有各自的效期。若需立即套用，請逐層檢查並清除。 [為何上限是 12 小時](#效期上限)

使用未登入且不帶查詢字串的訪客請求，連續兩次讀取同一個網址。可快取且尚未建立本機快取的頁面通常會先顯示 `X-YS-Cache: MISS`，接著顯示 `X-YS-Cache: HIT`。使用提前快取時，在 WordPress 載入前送出的命中回應還會帶有 `X-YS-Cache-Engine: early`，交給 WordPress 的請求則會以 `X-YS-Cache-Early` 標示原因。由 WordPress 處理且被排除的表單頁通常顯示 `X-YS-Cache: BYPASS`。上游快取可能一併傳回原來的標頭，請另外檢查上游的狀態。若主機有提供相應標頭，Nginx 命中時可看到 `X-FastCGI-Cache: HIT`。

### 提前快取

啟用本機 HTML 快取後，YS CACHE 會透過 `wp-content/advanced-cache.php`，在 WordPress 載入前送出公開頁面。開關是「快取設定 → HTML 快取」的「在 WordPress 載入前送出快取頁面」，預設開啟。此功能需要 HTTPS 單站 WordPress、在 `wp-config.php` 啟用 `WP_CACHE`、網站目錄外可寫入的快取目錄，以及可由 YS CACHE 管理的 `advanced-cache.php`。無法運作時，設定頁會顯示提示。

另有兩種情況會停用提前快取。已驗證的 CDN 層負責主網域的 HTML 快取時，HTML 回應由該層繼續管理。TranslatePress 官方自動語言偵測會依訪客把同一個網址導向不同語系。靜態 CDN 層會沿用原有的網址改寫與清除設定。

#### 送出範圍

網站本身 HTTPS 主機名稱上的公開 `GET` 或 `HEAD` 請求都可以送出，包括首頁、頁面、文章、商店、分類、商品與各語系網址。後台、REST、Feed、`wp-content`、`wp-includes` 與 `.php` 路徑一律交給 WordPress。購物車、結帳、帳戶頁、搜尋，以及「排除 → 路徑」中的頁面不會寫入快取，因此也會交給 WordPress。

Cookie 採排除清單。請求帶有名稱以下列前綴開頭的 Cookie 時，會交給 WordPress：

| 類別 | Cookie 名稱前綴 |
| --- | --- |
| WordPress | `wordpress_logged_in_`、`wordpress_sec_`、`wp-postpass_`、`comment_author_`、`wordpressuser_`、`wordpresspass_`，以及網站實際使用的登入 Cookie 名稱 |
| WooCommerce 購物車 | `woocommerce_items_in_cart`、`woocommerce_cart_hash`、`wp_woocommerce_session_`，開啟購物車選項時不排除 |
| YS CART | `ys_ec_session`、`ys_ec_tks_`、`__Host-ys_ec_coupon_handoff` |
| 願望清單 | `blc_products_wish_list`（Blocksy）、`yith_wcwl_session_`、`tinv_wishlistkey` |
| 幣別與語言 | `woocommerce_current_currency`、`wmc_current_currency`、`woocs_current_currency`、`aelia_cs_selected_currency`、`wcml_client_currency`、`trp_language` |
| 私人商店連結 | `woo-share`（WooCommerce 即將推出） |
| 自訂規則 | 「排除 → Cookie」中的每個前綴 |

其他 Cookie 不影響送出。帶有 `_ga`、`_fbp`、`pysTrafficSource` 等分析 Cookie，或帶有 `PHPSESSID` 等 PHP session Cookie 的訪客，仍會收到快取頁面。寫入條件較嚴格，詳見下文。「排除」中的 User-Agent 規則也會讓符合的請求交給 WordPress。

追蹤參數不會改變頁面內容，YS CACHE 會忽略這些參數，送出不含參數網址的快取。內建清單參考 WP Rocket 的清單並補上常見廣告平台：所有以 `utm_`、`hsa_`、`mtm_`、`pk_`、`sfmc_`、`trk_` 開頭的參數，以及 `fbclid`、`gclid`、`gbraid`、`wbraid`、`gad_source`、`msclkid`、`ttclid`、`twclid`、`li_fat_id`、`yclid`、`igshid`、`srsltid`、`mc_cid`、`_gl` 等名稱。其他名稱可在「額外忽略的查詢參數」每行填寫一個。只加入 PHP 不會讀取的參數，推薦、優惠券與幣別連結必須交給 WordPress。帶有其他查詢參數的請求會交給 WordPress。

YS CACHE 會為每個頁面分別儲存桌機與手機版本，判斷方式與 WordPress `wp_is_mobile()` 相同，部分佈景主題與外掛會依此輸出不同內容。有 `Sec-CH-UA-Mobile: ?1` 時視為手機。沒有此提示時，User-Agent 含 `Mobile`、`Android`、`Silk/`、`Kindle`、`BlackBerry`、`Opera Mini` 或 `Opera Mobi` 即為手機。開啟 YS TranslatePress Addons 的語言建議時，每個建議語言也會各有一份快取。

預設情況下，購物車內有商品的顧客會交給 WordPress。開啟「對購物車內有商品的顧客也送出快取頁面」後，這些顧客也會收到快取頁面，並在頁面載入後由 WooCommerce 購物車片段（cart fragments）更新迷你購物車。若佈景主題會在商品目錄頁顯示隨購物車變動的價格、折扣或訊息，請保持關閉。

帶有 `Authorization`、`X-WP-Nonce`、AJAX 的 `X-Requested-With: XMLHttpRequest`、`Origin`、`Range`、請求本文或語系覆寫標頭的請求會交給 WordPress。重新整理、`Cache-Control: no-cache` 與 `Pragma` 請求標頭，以及條件式請求都可以由快取送出。

#### 寫入條件

YS CACHE 只用乾淨的 WordPress 渲染結果或預載請求寫入快取。請求須為網站 HTTPS 主機名稱上不含查詢字串的 `GET`，訪客未登入，沒有排除清單中的 Cookie，也沒有 PHP session Cookie。其他 Cookie 只能是分析 Cookie：`_ga`、`_gid`、`_gat`、`_gcl_`、`_fbp`、`_fbc`、`_ttp`、`_clck`、`_clsk`、`_uet`、`_pin_`、`sbjs_`、`pys_` 與 `pbid` 等 PixelYourSite Cookie，以及透過 `ys_cache_writer_cookie_prefixes` 篩選器加入的名稱。

回應須為完整的 `200` HTML 頁面：購物車為空、沒有 WooCommerce 通知、除可忽略的 Cookie 外不設定 Cookie，且其他外掛沒有送出 private 或 `no-store` 快取標頭。搜尋、404、Feed、預覽、密碼保護內容、購物車、結帳、帳戶、WooCommerce 即將推出頁面，以及含逐位訪客表單或權杖的頁面都不會寫入。每位訪客都能讀取共用快取，但任何訪客的狀態都不會寫進去。

快取存放在網站目錄外並以 gzip 壓縮。每次命中都會檢查完整性雜湊，並重播原始回應中相容的安全標頭。

#### 效期上限

每份快取依快取效期或「排除」中較短的路徑效期到期，最長不超過 12 小時。快取頁面內含訪客用的 WordPress nonce，例如加入購物車、AJAX 搜尋與 REST 請求。WordPress 會在 nonce 建立時所在的半個 `nonce_life`，以及接下來的半個 `nonce_life` 內接受它。以預設一天的 `nonce_life` 計算，未滿 12 小時的頁面內的 nonce 一定仍然有效。

YS CACHE 將每份快取限制在 `nonce_life` 的一半減五分鐘，預設為 42,900 秒。縮短 `nonce_life` 的網站，上限也會跟著縮短。效期設定最多可填 43,200 秒，預設為 28,800 秒。到期的快取會由預載或下一個乾淨的造訪補回。

#### 預載

開啟「預載公開頁面（桌機與手機）」後，WordPress Cron 會在背景產生公開頁面，訪客很少遇到空的快取。預載範圍包括首頁、商店頁與文章列表頁、已發布商品、非空的商品分類與標籤、購物車、結帳、帳戶與排除路徑以外的已發布頁面，以及最近 50 篇文章，上限 2,000 個網址。可用 `ys_cache_preload_paths` 篩選器調整清單。每個網址各以桌機與手機請求一次，兩者的 User-Agent 都以 `YSCachePreload/1` 結尾。請求不帶 Cookie，並帶有簽章的 `X-YS-Cache-Preload` 標頭，因此一定會交給 WordPress。

背景工作每分鐘執行，一次送出一個請求，每輪最多 40 秒，剩餘效期 30 分鐘以上的頁面會略過。連續兩個回應超過 10 秒、連線失敗，或伺服器回應 403、429、503 等狀態時，該輪會提早結束。每個頁面的每種裝置最多嘗試兩次。儲存設定、開啟預載及全站清除後會排入所有網址。單頁清除只排入被清除的網址。每小時另會補上即將到期的頁面。「快取設定 → HTML 快取」會顯示佇列、上次執行結果、最近一次錯誤與已儲存的快取。

提前命中會在 WordPress 啟動 WP-Cron 前結束。若多數造訪都命中快取，請用主機排程每分鐘呼叫 `wp-cron.php`。伺服器也必須能連線到網站的正式 HTTPS 主機名稱。

#### 清除與回填

儲存商品或文章時，會清除下列頁面：

- 該頁網址與變更前的舊網址
- 首頁，以及商店頁或文章列表頁
- 所屬的公開分類及其上層分類
- 作者與日期彙整頁
- 上述網址的 TranslatePress 各語系版本

只影響單頁的變更只清除該頁，例如商品變體、商品圖片、價格、縮圖與已核准的留言。庫存變更會清除該商品。可售狀態改變時，也會清除商店、商品所屬的分類與標籤彙整頁及首頁。清除一個網址時，也會一併清除它的 `/page/N/` 分頁與兩種裝置版本。本機快取在同一個請求中清除，並由預載重新產生。伺服器與 CDN 清除照常排入佇列。

選單、小工具、自訂器、佈景主題變更、外掛啟用與更新、分類和標籤編輯、Woo Discount Rules 規則變更與網站外觀選項會清除全站。

#### 回應標頭

| 標頭 | 意義 |
| --- | --- |
| `X-YS-Cache: HIT` 與 `X-YS-Cache-Engine: early` | 頁面在 WordPress 載入前由 YS CACHE 送出。 |
| `Cache-Control: private, no-cache` | 瀏覽器每次造訪都會重新驗證，共用快取不會儲存頁面。由於沒有 `no-store`，上一頁與下一頁仍可立即顯示。 |
| `ETag` 與 `Last-Modified` | `If-None-Match` 相符的請求會收到不含內容的 `304 Not Modified`。 |
| `X-YS-Cache-Early` | 請求已交給 WordPress，值為原因。 |

提前命中也會送出 `X-Accel-Expires: 0`，避免 Nginx FastCGI 快取儲存這些回應。

| `X-YS-Cache-Early` | 原因 |
| --- | --- |
| `cookie` | 帶有排除清單中的 Cookie，例如登入、購物車、願望清單或幣別 Cookie |
| `query` | 帶有未忽略的查詢參數 |
| `header` | 帶有 `Authorization` 或 `X-WP-Nonce` 等標頭、AJAX 請求、請求本文，或過長的 User-Agent |
| `agent` | 符合「排除」中的 User-Agent 規則 |
| `path` | 後台、REST、Feed 或檔案路徑 |
| `method` | `GET`、`HEAD` 以外的方法 |
| `host` | 不是網站的 HTTPS 主機名稱 |
| `miss` | 沒有有效的快取。WordPress 產生頁面，符合條件時寫入快取。 |
| `policy` | 主機安全政策在上次同步後有變更。請儲存設定重新同步。 |

#### PixelYourSite 設定

YS CACHE 和 WP Rocket 一樣，把含 PixelYourSite 輸出的頁面當成一般頁面快取，沿用 PixelYourSite 現有的設定，不需要更改任何選項。

- 送出快取時忽略 PixelYourSite 的 Cookie（包含 `PHPSESSID`），訪客會持續收到快取頁面。
- 產生快取的那一次渲染，會請 PixelYourSite 不要為這個請求開啟 PHP session（`pys_skip_session` 篩選器）。提前命中完全不執行 PHP，所以這與每一次快取命中的行為相同。
- PixelYourSite 以 AJAX 傳送伺服器事件時，它的瀏覽器指令碼每次瀏覽都會產生新的事件 ID；拿到同一份快取 HTML 的訪客，仍會送出各自配對的瀏覽器與伺服器事件。
- 若 PixelYourSite 設定為在 WordPress 渲染頁面時直接送出伺服器事件，預載會自動暫停，避免記錄假的造訪；一般訪客的渲染仍會寫入快取。
- 最後一道保險：PixelYourSite 輸出含有某位訪客的資料（例如使用者 ID、進階比對個資或購買事件）時不寫入。乾淨的訪客渲染不會出現這些資料。

#### 驗證步驟

1. 儲存設定或清除 HTML 快取，等到「快取設定 → HTML 快取」顯示沒有待處理的預載頁面。
2. 開啟無痕視窗與「開發人員工具 → 網路」，依序造訪首頁、分類與商品。逐一選取主文件，確認帶有 `X-YS-Cache: HIT` 與 `X-YS-Cache-Engine: early`。預載尚未處理的頁面第一次會顯示 `X-YS-Cache-Early: miss`，下一次就會命中。
3. 重新整理頁面。重新整理現在也會命中，狀態為 `200` 或 `304 Not Modified`，即使勾選開發人員工具的「停用快取」也一樣。
4. 用第二個瀏覽器設定檔開啟相同頁面，它沒有第一個設定檔的任何 Cookie。預載完成後，第一次造訪就會命中。將開發人員工具切換為手機裝置並重新整理，確認手機版本。
5. 在網址加上 `?utm_source=test` 仍會命中。加上 `?test=1` 會顯示 `X-YS-Cache-Early: query`。登入，或在購物車選項關閉時把商品加入購物車，會顯示 `X-YS-Cache-Early: cookie`。
6. 使用 PixelYourSite 時，比較兩個設定檔的事件請求，兩者應各自送出不同的事件 ID。

### 表單和快取效期

YS CACHE 會檢查當前頁面的短代碼、區塊及實際輸出。共用頁尾或版型含表單時，可能影響每個顯示表單的頁面。WPForms、Fluent Forms 和 JetFormBuilder 預設繞過快取，因為表單可能含會到期的權杖、工作階段資料、動態欄位或付款及驗證狀態。Contact Form 7 預設也會繞過。 [WPForms 快取與表單權杖](https://wpforms.com/docs/how-to-optimize-cache-settings-to-fix-submission-errors/) · [Fluent Forms 付款及快取](https://fluentforms.com/why-fluent-forms-payments-break-with-caching-plugins-and-solutions/)

#### 簡單公開表單頁面（Beta）

在「公開表單路徑（Beta）」每行填一個公開路徑，例如 `/contact/` 及 `/en/contact/`。本機頁面快取最多五分鐘，也會遵守較短的頁面效期。請在上層伺服器及 CDN 排除這些 HTML 路徑，來源站會送出 private、no-store 標頭。

| 表單及版本 | 可使用本機快取的範圍 | 已驗證的訪客送出流程 |
| --- | --- | --- |
| WPForms Lite 2.0.5.1 | 頁面內容只有一個原生表單短代碼。每位訪客取得新的欄位、時間權杖、來源網址與腳本設定。 | AJAX 送出及成功確認 |
| Fluent Forms 免費版 6.2.17 | 一張只有文字、姓名、電子郵件或留言欄位的公開表單。每位訪客取得新的表單及腳本設定。 | AJAX 成功並保存測試紀錄 |
| JetFormBuilder 3.6.8.2 | 一張只有文字、留言、送出按鈕的公開表單，可沒有送出動作或只設定一個 Send Email 動作。每位訪客取得新的欄位及簽章。 | 原生送出並轉向成功頁 |
| Contact Form 7 6.2.x | 只有一個原生短代碼的專用表單頁可在本機重用已驗證的公開內容。另含短代碼或動態區塊的頁面會繞過快取。 | 6.2.5 的表單回應端點 |

若要啟用前三項整合，請開啟公開路徑下方的逐位訪客表單開關。既有 Contact Form 7 路徑會保留原來的範圍，直到你開啟這項設定。編輯器動態產生的表單與頁尾小工具會使用原生頁面渲染，保留表單周圍的訪客專屬內容。其他版本或欄位亦會回到原生渲染。此功能需明確填入路徑，產生的 HTML 限來源站使用。

付款、CAPTCHA、預填或私人欄位、登入限制、檔案上傳及有自訂表單掛鉤的頁面維持排除。YS CACHE 若偵測到其他動態表單或敏感輸出，整頁也會繞過快取。請保留原本的表單防護及送出設定。上線前，請在測試站用訪客身分確認表單正常顯示並送出一筆測試資料。 [Contact Form 7 短代碼說明](https://contactform7.com/faq/)

#### 讓單一內嵌表單維持即時渲染

1. 在同一個 WordPress 網站建立獨立頁面，例如 `/contact-form/`，加入表單外掛原生短代碼。表單頁由外掛正常渲染，保留原本的驗證及送出流程。
2. 在 YS CACHE 及各個會快取 HTML 的伺服器和 CDN 層排除表單頁。各語系的表單網址也要一起排除。
3. 用外層頁面的編輯器或版型工具，加入支援的同源 iframe。外層頁面若符合其他快取條件，仍然可以快取。

```html
<iframe src="/contact-form/" title="聯絡表單" loading="lazy" height="640"></iframe>
```

先確認網站的框架政策允許同源內嵌，分別檢查外層頁面的快取狀態與表單頁的排除結果，再用 iframe 在測試站送出一筆測試資料。各語系都應驗證，並確認欄位檢查及送出後的提示。

### 背景清除與庫存

請保持 WordPress Cron 運作，以處理排入佇列的變更。內容編輯先合併 15 秒，庫存變更先合併 60 秒，再由背景工作處理。連線中斷、限流和伺服器暫時錯誤會在 24 小時內逐步拉長重試間隔。若 Cron 錯過最後排定的時間，下一次執行會補做最後一次嘗試。停用訪客觸發 WP-Cron 的網站需要主機排程。提前命中 HTML 時，請求會在 WordPress 啟動排程前結束，因此多數造訪都命中快取的網站，也需要主機排程來執行預載。本機 HTML 每次讀取都會檢查到期時間。可在「快取設定 → HTML 快取」查看上次結果與下次嘗試時間。LiteSpeed 透過 HTTP 請求 `wp-cron.php`，讓背景工作能偵測伺服器快取。

WooCommerce 庫存更新會清除受影響的商品頁及已發布的語系版本。可售狀態改變時，也會清除商店、相關分類與標籤彙整頁，以及首頁。本機 HTML 快取會在同一個請求中清除，並由預載重新產生；伺服器與 CDN 清除則等背景合併時間後執行。若其他頁面嵌入會顯示庫存的內容，可以用 `ys_cache_stock_purge_urls` 加入清除範圍。若來源整合只支援整站清除，這些庫存變更會依原有快取效期更新，也可手動清除整站。
