# YS CACHE integration guide

[繁體中文](#繁體中文) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

## Enhance

1. Sign in to your organisation’s Enhance panel. In **Settings → Access Tokens**, select **Create Token**. Keep the token private. Grant the organisation role needed to read FastCGI status, clear its cache and read or add cache exclusions. Ask the hosting administrator to grant access if your role cannot create or use a token.
2. Find the organisation ID in the panel and the Domain ID for the exact hostname you want to manage. The domains endpoint lists the IDs for a website. Enter the panel HTTPS URL, Domain ID, token, and the organisation ID if your panel requires it.
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

Add each CDN layer from the origin outward. You can look up the resource ID using a Cloudflare token with Zone Read and Cache Purge, a Bunny CDN account API key, or a Gcore customer API token. For a CNAME-only layer, configure the corresponding CDN separately if you also want API purges. Test the first and second guest requests in each published language. The translated image should keep its language-specific path and use the configured CNAME wherever it is eligible. Keep CSP and cross-origin settings consistent with your delivery host.

[Cloudflare](https://developers.cloudflare.com/api/resources/cache/methods/purge/) · [Bunny CDN](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) · [Gcore](https://docs.gcore.com/api-reference/cdn/tools/purge-cache)

## Forms and lifetime

WPForms, Fluent Forms, JetFormBuilder and Contact Form 7 are detected automatically. The plugin examines the current page’s blocks and shortcodes and checks rendered HTML for form markup and private tokens. A footer or builder template with a form affects every page where the form is rendered. Check both a form page and an ordinary page with anonymous requests. Extending the configured lifetime to seven days still respects the active nonce window and eligible responses.

## 繁體中文

### Enhance

1. 登入自己的 Enhance 組織面板。在 **Settings → Access Tokens** 選擇 **Create Token**。請妥善保管 Token，並確認組織角色有權讀取 FastCGI 狀態、清除快取，以及讀取與新增快取排除。若畫面沒有建立權限，請聯絡主機管理員。
2. 在面板取得組織 ID，並從網站的 domains API 查找要管理的網域 ID。填入面板 HTTPS 網址、網域 ID、Token，以及面板要求的組織 ID。
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

由來源到外層依序新增 CDN。Cloudflare 可用具備 Zone Read 與 Cache Purge 權限的 Token 查找資源 ID，Bunny CDN 使用帳戶 API Key，Gcore 使用客戶 API Token。僅填 CNAME 的層若也要透過 API 清除，請設定實際供應商。用各語系的訪客網址測兩次，確認換圖後的路徑與對應語系一致，且符合條件的資源使用 CNAME。若站點配置了 CSP 或跨網域政策，請確認 CDN 網域可載入相應資源。

[Cloudflare](https://developers.cloudflare.com/api/resources/cache/methods/purge/) · [Bunny CDN](https://bunny.net/docs/api-reference/core/pull-zone/list-pull-zones) · [Gcore](https://docs.gcore.com/api-reference/cdn/tools/purge-cache)

### 表單和快取效期

外掛自動偵測 WPForms、Fluent Forms、JetFormBuilder 與 Contact Form 7。檢查只針對當前頁面的區塊、短代碼和輸出的表單標記或私有 Token。若頁尾或共用版型含表單，每個載入它的頁面都會套用排除。請以未登入訪客分別測試表單頁和一般頁面。時效可填到七天，實際可命中時間仍受 nonce 時段及回應條件限制。
