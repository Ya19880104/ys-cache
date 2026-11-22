# YS CACHE integration guide

[繁體中文](#繁體中文) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

## Enhance

1. Site owners should ask their hosting provider to configure this integration when their Enhance account has no access to API tokens. A hosting administrator with sufficient privileges can create one through **Settings → Access Tokens**. Use a credential whose verified rights cover only the relevant customer or site and allow the required FastCGI operations. Keep cluster-wide credentials on the hosting side.
2. The hosting administrator can provide `YS_CACHE_ENHANCE_URL`, `YS_CACHE_ENHANCE_DOMAIN_ID`, `YS_CACHE_ENHANCE_TOKEN`, and, if required, `YS_CACHE_ENHANCE_ORG_ID` in the site’s `wp-config.php`. Alternatively, enter those values in the Enhance form if you have an appropriate token. The domains endpoint lists the Domain ID for the exact hostname. Automatic detection can recognize the host configuration without any of these values being entered in WordPress admin. Local HTML and available OPcache can be cleared without an API credential.
3. In **Cache Management → Integrations → Enhance**, check the FastCGI status. Preview and synchronize the suggested path exclusions. Check translated and nested commerce paths as well as the bypass rules for cookies, query strings, authorisation, cart headers and non-GET/HEAD requests before you give Enhance control of HTML. Use the entire-site action for Enhance because its purge API clears the whole domain.

[Enhance API](https://apidocs.enhance.com/spec/oas3-api.yaml) · [Cache exclusions](https://enhance.com/docs/website-tools/nginx/cache-exclusion)

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

## Forms and lifetime

WPForms, Fluent Forms, JetFormBuilder and Contact Form 7 are detected automatically. The plugin examines the current page’s blocks and shortcodes and checks rendered HTML for form markup and private tokens. A footer or builder template with a form affects every page where the form is rendered. Check both a form page and an ordinary page with anonymous requests. Extending the configured lifetime to seven days still respects the active nonce window and eligible responses.

## 繁體中文

### Enhance

1. 一般網站用戶若在 Enhance 找不到建立 Token 的權限，請由主機服務商設定。有足夠權限的主機管理員可從 **Settings → Access Tokens** 建立憑證，並確認它只對該客戶或網站擁有必要的 FastCGI 權限。全平台管理憑證請留在主機端。
2. 主機管理員可在該站的 `wp-config.php` 提供 `YS_CACHE_ENHANCE_URL`、`YS_CACHE_ENHANCE_DOMAIN_ID`、`YS_CACHE_ENHANCE_TOKEN`，視面板需求加上 `YS_CACHE_ENHANCE_ORG_ID`。網域 ID 可從網站的 domains API 找到。配置完整後，WordPress 後台可自動偵測。若管理員提供了適用的 Token，也可從後台填入。本機 HTML 與可用的 OPcache 能在沒有 API 憑證的情況下清除。
3. 在「快取管理 → 整合 → Enhance」檢查 FastCGI 狀態，預覽並同步建議排除。切換 HTML 管理權前，驗證非 GET／HEAD 請求、Cookie、query string、授權和購物車標頭，以及翻譯後或巢狀的結帳等路徑。Enhance 清除 API 以網域為單位，請使用全站清除。

[Enhance API](https://apidocs.enhance.com/spec/oas3-api.yaml) · [快取排除](https://enhance.com/docs/website-tools/nginx/cache-exclusion)

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

### 表單和快取效期

外掛自動偵測 WPForms、Fluent Forms、JetFormBuilder 與 Contact Form 7。檢查只針對當前頁面的區塊、短代碼和輸出的表單標記或私有 Token。若頁尾或共用版型含表單，每個載入它的頁面都會套用排除。請以未登入訪客分別測試表單頁和一般頁面。時效可填到七天，實際可命中時間仍受 nonce 時段及回應條件限制。
