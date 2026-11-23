# YS CACHE

[English](README.md) · [羊羊數位官網下載](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE 是 WordPress 的訪客 HTML 快取外掛，支援單頁與全站清除、多層 CDN 和自動匯入。需 WordPress 6.4 與 PHP 8.0 以上。內附的 YS Hub 整合需 PHP 8.2 以上。

## 安裝及開始使用

1. 從羊羊數位官網下載 YS CACHE ZIP。在 WordPress 開啟「外掛 → 安裝外掛 → 上傳外掛」，選取 ZIP 並啟用。
2. 啟用時會偵測運作中的相容快取外掛，匯入支援的設定後啟用 HTML 快取。請到「工具 → YS CACHE 自動匯入」查看結果或重試。舊版使用者可以直接更新 YS CACHE。若先前的獨立匯入工具仍在運作，待復原作業完成後，管理員下次進入後台時會自動停用該工具。
3. 首次自行設定快取時，在「控制台」下方開啟「快取管理」，啟用訪客 HTML 快取並儲存。預設效期為 28,800 秒，可用秒、分鐘、小時或天數查看與編輯同一個效期。
4. 前後台的 WordPress 工具列皆提供「清除快取」，可清除本頁或全站。後台沒有對應頁面時，單頁清除會請你輸入公開頁面網址。

## 設定與驗證整合

- **HTML、路徑與表單。** 登入、結帳、購物車工作階段、預覽與私人回應會繞過快取。在「快取設定 → 排除」填寫 `/en/contact/` 等公開路徑。`/news/* 300` 可縮短符合快取條件的 HTML 效期，WordPress nonce 更新及回應檢查也可能讓它提早到期。外掛會偵測頁面內容、版型編輯器、小工具或頁尾實際輸出的表單。 [路徑及縮短效期](docs/cache-integrations.md#路徑規則與縮短快取效期)
- **表單頁。** WPForms、Fluent Forms 與 JetFormBuilder 維持繞過 HTML 快取。「公開 Contact Form 7 路徑（Beta）」可讓指定網址中符合條件的簡單訪客表單使用本機 YS CACHE HTML 快取，最長五分鐘。若要讓外層頁面保持可快取、表單維持即時渲染，可內嵌獨立排除的同源表單頁。 [表單設定及測試](docs/cache-integrations.md#表單和快取效期)
- **訪客 HTML 提前快取。** 在「快取設定 → HTML 快取」指定確切的公開 HTTPS 路徑，讓符合條件的訪客在 WordPress 啟動前收到 HTML。選用 PixelYourSite Beta 整合後，符合條件的訪客直接開啟首頁時，可以重用首頁 HTML，並各自載入私有追蹤資料。另可啟用 Blocksy 商店公開內容快取，讓符合條件的分類與商品頁重用主內容，同時保留各訪客的頁首頁尾與追蹤資料。 [提前快取設定與驗證](docs/cache-integrations.md#訪客-html-提前快取與-pixelyoursitebeta)
- **Elementor 與 PixelYourSite。** 啟用 Elementor 原生元件快取後，本機 YS CACHE 模式下符合條件的已發布單一內容頁可重用 Elementor 文件片段，WordPress 會繼續為每位訪客產生追蹤資料。 [Elementor 快取標頭及清除](docs/cache-integrations.md#elementor-片段快取與-pixelyoursite)
- **CSS 與 JavaScript。** CSS 壓縮、JS 壓縮及延後執行 JS 可各自啟用，預設關閉。排除規則可填代號或路徑。要延後執行某支腳本，請明確填入它在 WordPress 的註冊代號，並測試會用到它的功能。
- **自動清除。** WordPress Cron 在背景合併相近的變更，先清來源，再依 CDN 層排序清除。WooCommerce 庫存變更只清受影響的頁面。暫時失敗會在 24 小時內逐步拉長重試間隔。如果 Cron 延遲，會在恢復執行時做最後一次清除嘗試。CDN 層可跟隨主設定或僅手動清除。
- **伺服器。** PHP 允許 WordPress 呼叫重設功能時，可自動偵測 OPcache。相容主機可啟用 LiteSpeed 或獨立 Nginx Cache（Beta）管理來源 HTML，並在 CDN 前清除。在「整合」也可設定 YS CLOUD。 [獨立 Nginx 設定](docs/nginx-cache.md#繁體中文)
- **CDN。** Cloudflare、Bunny CDN 和 Gcore 可用供應商憑證查找現有資源 ID。選擇傳遞範圍，由來源到外層排列，並填寫要清除的網站別名。純 CNAME 層會將符合條件的靜態資源改寫至既有 CDN 網域。DNS 與 API 清除由供應商設定。 [CDN 連線說明](docs/cache-integrations.md#cdn-和-cname)
- **語系。** 後台依 WordPress 使用者語言顯示。使用 TranslatePress 的網站，請個別測試每個語系網址及其對應圖片。

[整合操作說明](docs/cache-integrations.md#繁體中文) · [授權](LICENSE)
