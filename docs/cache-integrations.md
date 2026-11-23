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

The global HTML lifetime can be set to seven days. In the shorter-lifetime field, enter a path rule followed by a space and a positive whole number of seconds. For example, `/news/* 300` caps eligible news pages at 300 seconds. If `/news/latest/ 60` also matches, YS CACHE uses the shortest matching duration or the global lifetime, whichever is lower. HTML eligibility and nonce checks still apply. Any server or CDN copy already stored can keep its own lifetime. Check and purge each cache layer if you need an immediate change.

To check the result, make two anonymous GET requests to the same URL with no query string. A fresh eligible local page typically returns `X-YS-Cache: MISS` and then `X-YS-Cache: HIT`. A form page excluded by the plugin typically returns `X-YS-Cache: BYPASS` when WordPress handles the request. An upstream response can replay an origin header, so check the upstream status separately. An Nginx cache can report `X-FastCGI-Cache: HIT` when configured to send that header.

## Forms and lifetime

YS CACHE checks the current page’s shortcodes and blocks and inspects the rendered output. A form in a shared footer or builder template can affect every page where it appears. WPForms, Fluent Forms, and JetFormBuilder default to bypass because rendered forms can include expiring tokens, session data, dynamic fields, and payment or validation state. Contact Form 7 normally bypasses too. [WPForms cache and form tokens](https://wpforms.com/docs/how-to-optimize-cache-settings-to-fix-submission-errors/) · [Fluent Forms payments and caching](https://fluentforms.com/why-fluent-forms-payments-break-with-caching-plugins-and-solutions/)

### Simple public Contact Form 7 pages (Beta)

Add the public page paths to **Public Contact Form 7 paths (Beta)** to opt in. Use this with the local YS CACHE HTML mode on Contact Form 7 6.2.x for simple guest forms. Add each translated URL you intend to cache. Eligible pages expire after at most 300 seconds, with shorter path or global limits taking precedence. Use server and CDN bypass rules for these form paths.

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

Keep WordPress Cron running to process queued changes. Content edits use a 15-second window and inventory uses a 60-second window before becoming eligible. Temporary transport errors, rate limits and server errors retry with backoff for up to 24 hours. Check the last result and next attempt under **Cache Settings → HTML Cache**. LiteSpeed uses an HTTP request to `wp-cron.php` so the worker can detect the server cache.

WooCommerce inventory updates clear the affected product and its published languages. A stock availability change also clears the shop, relevant category and tag archives, and the front page. Add pages that embed stock-sensitive content with the `ys_cache_stock_purge_urls` filter. Origin integrations that only support site-wide clearing leave automatic inventory purges to existing expiration and manual site-wide clearing.

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

全站 HTML 效期最長可設為七天。在縮短效期欄位，每行輸入一個路徑規則、空白及正整數秒數。例如 `/news/* 300` 將符合快取條件的新聞頁限制在 300 秒內。若同時符合 `/news/latest/ 60`，YS CACHE 會從符合的規則及全站效期中取最短值。HTML 適用條件與 nonce 效期仍然生效。伺服器或 CDN 已存入的快取可能有各自的效期。若需立即套用，請逐層檢查並清除。

使用未登入且不帶查詢字串的訪客請求，連續兩次讀取同一個網址。可快取且尚未建立本機快取的頁面通常會先顯示 `X-YS-Cache: MISS`，接著顯示 `X-YS-Cache: HIT`。由 WordPress 處理且被排除的表單頁通常顯示 `X-YS-Cache: BYPASS`。上游快取可能一併傳回原來的標頭，請另外檢查上游的狀態。若主機有提供相應標頭，Nginx 命中時可看到 `X-FastCGI-Cache: HIT`。

### 表單和快取效期

YS CACHE 會檢查當前頁面的短代碼、區塊及實際輸出。共用頁尾或版型含表單時，可能影響每個顯示表單的頁面。WPForms、Fluent Forms 和 JetFormBuilder 預設繞過快取，因為表單可能含會到期的權杖、工作階段資料、動態欄位或付款及驗證狀態。Contact Form 7 預設也會繞過。 [WPForms 快取與表單權杖](https://wpforms.com/docs/how-to-optimize-cache-settings-to-fix-submission-errors/) · [Fluent Forms 付款及快取](https://fluentforms.com/why-fluent-forms-payments-break-with-caching-plugins-and-solutions/)

#### 簡單公開 Contact Form 7 頁面（Beta）

在「公開 Contact Form 7 路徑（Beta）」填入要啟用的公開路徑。此選項適用於使用本機 YS CACHE HTML 的 Contact Form 7 6.2.x 簡單訪客表單。各語系網址需分別填寫。符合條件的頁面效期最長 300 秒，若路徑或全站效期較短，會採較短的值。請同時在伺服器及 CDN 排除這些表單網址。

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

請保持 WordPress Cron 運作，以處理排入佇列的變更。內容編輯先合併 15 秒，庫存變更先合併 60 秒，再由背景工作處理。連線中斷、限流和伺服器暫時錯誤會逐步拉長重試間隔，最長 24 小時。可在「快取設定 → HTML 快取」查看上次結果與下次嘗試時間。LiteSpeed 透過 HTTP 請求 `wp-cron.php`，讓背景工作能偵測伺服器快取。

WooCommerce 庫存更新會清除受影響的商品頁及已發布的語系版本。可售狀態改變時，也會清除商店、相關分類與標籤彙整頁，以及首頁。若其他頁面嵌入會顯示庫存的內容，可以用 `ys_cache_stock_purge_urls` 加入清除範圍。若來源整合只支援整站清除，這些庫存變更會依原有快取效期更新，也可手動清除整站。
