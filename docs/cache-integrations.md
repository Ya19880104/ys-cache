# YS CACHE integration guide

[繁體中文](#繁體中文) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

## LiteSpeed Cache (Beta)

In **Cache Management → Integrations → LiteSpeed Cache**, check detection before enabling the integration. It requires a single-site WordPress installation, LiteSpeed or OpenLiteSpeed server caching, LiteSpeed Cache 7.9.2 or later and a reachable same-origin WordPress admin loopback. Turn off LiteSpeed Guest Mode and forced cache rules. Verify server-level bypasses for logins, cart and checkout sessions, forms and translated dynamic routes on the actual host, then confirm them in the integration. LiteSpeed manages origin HTML. YS CACHE can shorten storage to the configured HTML lifetime and current guest nonce window.

Entire-site and single-page clearing dispatch to LiteSpeed first. Content updates use the main automatic clearing switch and each CDN layer’s own follow setting. If YS CACHE cannot confirm origin dispatch, it pauses the CDN purge for that run. Verify origin eviction and subsequent edge refresh on the host, including on translated URLs. [LiteSpeed WordPress cache API](https://docs.litespeedtech.com/lscache/lscwp/api/)

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

Add each CDN layer from the origin outward and choose whether it follows the main content-update purge setting. Resource discovery requires a Cloudflare token with Zone Read and Cache Purge, a Bunny CDN account API key, or a Gcore customer API token. Discovery finds existing resources. To purge a CNAME-only layer, also configure its CDN provider and credential. Test the first and second guest requests in each published language. The translated image should keep its language-specific path and use the configured CNAME wherever it is eligible. Keep CSP and cross-origin settings consistent with your delivery host.

[Cloudflare](https://developers.cloudflare.com/api/resources/cache/methods/purge/) · [Bunny CDN](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) · [Gcore](https://docs.gcore.com/api-reference/cdn/tools/purge-cache)

### Bunny CDN API key

1. Sign in to the Bunny dashboard and open **Account → API Key**. Copy the account API key. Ask the primary account owner if the key is unavailable to your account.
2. In WordPress, set the CDN layer provider to **Bunny CDN**, enter a hostname already attached to the Pull Zone, and paste the key into **API token / key**. Use **Find resource ID** to select the matching Pull Zone, then enable API purging and save. [Bunny account API keys](https://bunny.net/docs/account/api-keys)

### Gcore API token

1. Sign in to the Gcore Customer Portal. Open the avatar menu and select **Profile → API tokens → Create token**. Set a name and expiry, grant the CDN permissions needed to read resources and purge cache, and copy the token while it is displayed.
2. In WordPress, select **Gcore**, enter a hostname already attached to the CDN resource, and paste the token into **API token / key**. Use **Find resource ID** to find the matching resource, then enable API purging and save. [Gcore API tokens](https://docs.gcore.com/account-settings/api-tokens)

## Forms and lifetime

WPForms, Fluent Forms, JetFormBuilder and Contact Form 7 are detected automatically. The plugin examines the current page’s blocks and shortcodes and checks rendered HTML for form markup and private tokens. A footer or builder template with a form affects every page where the form is rendered. Check both a form page and an ordinary page with anonymous requests. Extending the configured lifetime to seven days still respects the active nonce window and eligible responses.

## 繁體中文

### LiteSpeed Cache（Beta）

在「快取管理 → 整合 → LiteSpeed Cache」確認偵測結果後再啟用。需 WordPress 單站、已啟用快取的 LiteSpeed 或 OpenLiteSpeed 伺服器、LiteSpeed Cache 7.9.2 以上，以及可連通的 WordPress 同源後台回環請求。關閉 LiteSpeed 的訪客模式與強制快取規則。在實際主機確認伺服器會略過登入、購物車、結帳、表單及各語系的動態路徑，並在整合頁確認。啟用後由 LiteSpeed 管理來源 HTML，YS CACHE 可依 HTML 設定及訪客 nonce 效期縮短快取時間。

全站和單頁清除先送到 LiteSpeed。更新內容時依主設定自動清除，CDN 各層可選擇是否跟隨。若 YS CACHE 無法確認來源已接手清除，該次會暫停清除 CDN。請在實際主機驗證來源舊快取已移除，再檢查 CDN 與各語系的新回應。 [LiteSpeed WordPress 快取 API](https://docs.litespeedtech.com/lscache/lscwp/api/)

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

由來源到外層依序新增 CDN，並設定各層是否跟隨主設定的內容更新清除。Cloudflare 可用具備 Zone Read 與 Cache Purge 權限的 Token 查找資源 ID，Bunny CDN 使用帳戶 API Key，Gcore 使用客戶 API Token。查找會配對已存在的資源。僅填 CNAME 的層若也要透過 API 清除，請同時設定供應商與憑證。用各語系的訪客網址測兩次，確認換圖後的路徑與對應語系一致，且符合條件的資源使用 CNAME。若站點配置了 CSP 或跨網域政策，請確認 CDN 網域可載入相應資源。

[Cloudflare](https://developers.cloudflare.com/api/resources/cache/methods/purge/) · [Bunny CDN](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) · [Gcore](https://docs.gcore.com/api-reference/cdn/tools/purge-cache)

#### Bunny CDN API Key

1. 登入 Bunny 控制台，開啟 **Account → API Key**，複製帳戶 API Key。若帳號看不到 Key，請由主帳號持有人處理。
2. 回到 WordPress 的「CDN 層」，選擇 **Bunny CDN**，填入已綁定 Pull Zone 的主機名稱及 API Key。按「查詢資源 ID」配對 Pull Zone，再啟用 API 清除並儲存。 [Bunny 帳戶 API Key](https://bunny.net/docs/account/api-keys)

#### Gcore API Token

1. 登入 Gcore Customer Portal，從頭像選單開啟 **Profile → API tokens → Create token**。設定名稱和效期，授予讀取 CDN 資源與清除快取所需權限，並在畫面顯示時複製 Token。
2. 回到 WordPress 的「CDN 層」，選擇 **Gcore**，填入已綁定 CDN 資源的主機名稱及 Token。按「查詢資源 ID」配對資源，再啟用 API 清除並儲存。 [Gcore API Token](https://docs.gcore.com/account-settings/api-tokens)

### 表單和快取效期

外掛自動偵測 WPForms、Fluent Forms、JetFormBuilder 與 Contact Form 7。檢查只針對當前頁面的區塊、短代碼和輸出的表單標記或私有 Token。若頁尾或共用版型含表單，每個載入它的頁面都會套用排除。請以未登入訪客分別測試表單頁和一般頁面。時效可填到七天，實際可命中時間仍受 nonce 時段及回應條件限制。
