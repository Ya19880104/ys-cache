# YS CACHE

[English](README.md) · [羊羊數位官網下載](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE 是 WordPress 的訪客 HTML 快取外掛，支援單頁與全站清除、多層 CDN 和自動匯入。需 WordPress 6.4 與 PHP 8.0 以上。內附的 YS Hub 整合需 PHP 8.2 以上。

## 安裝及開始使用

1. 從羊羊數位官網下載 YS CACHE ZIP。在 WordPress 開啟「外掛 → 安裝外掛 → 上傳外掛」，選取 ZIP 並啟用。
2. 在「控制台」下方開啟「快取管理」，啟用訪客 HTML 快取並儲存。新安裝預設為 28,800 秒，可用秒、分鐘、小時或天數查看與編輯同一個效期。
3. 若要沿用支援的快取外掛設定，可從同一個下載頁取得自動匯入套件。請先安裝最新版 YS CACHE，再到「工具 → YS CACHE 自動匯入」查看結果。
4. 前後台的 WordPress 工具列皆提供「清除快取」，可清除本頁或全站。後台沒有對應頁面時，單頁清除會請你輸入公開頁面網址。

## 設定與驗證整合

- **HTML、路徑與表單。** 登入、結帳、購物車工作階段、預覽與私人回應會繞過快取。在「快取設定 → 排除」填寫 `/en/contact/` 等公開路徑。`/news/* 300` 可縮短符合快取條件的 HTML 效期，WordPress nonce 更新及回應檢查也可能讓它提早到期。外掛會偵測頁面內容、版型編輯器、小工具或頁尾實際輸出的表單。 [路徑及縮短效期](docs/cache-integrations.md#路徑規則與縮短快取效期)
- **表單頁。** WPForms、Fluent Forms 與 JetFormBuilder 維持繞過 HTML 快取。「公開 Contact Form 7 路徑（Beta）」可讓指定網址中符合條件的簡單訪客表單使用本機 YS CACHE HTML 快取，最長五分鐘。若要讓外層頁面保持可快取、表單維持即時渲染，可內嵌獨立排除的同源表單頁。 [表單設定及測試](docs/cache-integrations.md#表單和快取效期)
- **CSS 與 JavaScript。** CSS 壓縮、JS 壓縮及延後執行 JS 可各自啟用，預設關閉。排除規則可填代號或路徑。要延後執行某支腳本，請明確填入它在 WordPress 的註冊代號，並測試會用到它的功能。
- **自動清除。** WordPress Cron 在背景合併相近的變更，先清來源，再依 CDN 層排序清除。WooCommerce 庫存變更只清受影響的頁面。暫時失敗會逐步拉長重試間隔，最長 24 小時。CDN 層可跟隨主設定或僅手動清除。
- **伺服器。** PHP 允許 WordPress 呼叫重設功能時，可自動偵測 OPcache。相容主機可啟用 LiteSpeed Cache（Beta）管理來源 HTML，並與 CDN 依序清除。YS CLOUD 位於「整合」的「CDN 層」後方。
- **CDN。** 選擇傳遞範圍，並依來源到外層排列。填寫網站別名網域，讓清除涵蓋主要網域與別名。靜態資源可使用獨立 CNAME。儲存後在各已發布語系檢查圖片、JS 與 CSS 的網址及實際回應。供應商接受清除請求後，各 CDN 節點需要時間套用。
- **語系。** 後台依 WordPress 使用者語言顯示。使用 TranslatePress 的網站，請個別測試每個語系網址及其對應圖片。

[整合操作說明](docs/cache-integrations.md#繁體中文) · [授權](LICENSE)
