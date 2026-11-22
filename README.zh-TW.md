# YS CACHE

[English](README.md) · [羊羊數位官網下載](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE 是 WordPress 的訪客 HTML 快取外掛，支援單頁與全站清除、多層 CDN 和自動匯入。需 WordPress 6.4 與 PHP 8.0 以上。內附的 YS Hub 整合需 PHP 8.2 以上。

## 安裝及開始使用

1. 從羊羊數位官網下載 YS CACHE ZIP。在 WordPress 開啟「外掛 → 安裝外掛 → 上傳外掛」，選取 ZIP 並啟用。
2. 在「控制台」下方開啟「快取管理」，啟用訪客 HTML 快取並儲存。新安裝預設為 28,800 秒。既有站的效期會換算成秒。
3. 若要沿用支援的快取外掛設定，可從同一個下載頁取得自動匯入套件。請先安裝最新版 YS CACHE，再到「工具 → YS CACHE 自動匯入」查看結果。
4. 前後台的 WordPress 工具列皆提供「清除快取」，可清除本頁或全站。後台沒有對應頁面時，單頁清除會請你輸入公開頁面網址。

## 設定與驗證整合

- **HTML 與排除。** 登入、結帳、購物車工作階段、預覽與私人回應會繞過快取。外掛只檢查當前頁面，包含版型編輯器、小工具或頁尾實際輸出的表單。效期上限可設為七天，WordPress nonce 更新或回應檢查可能縮短實際命中時間。
- **伺服器。** 可自動偵測並選取 OPcache。若由 Enhance 提供 HTML 快取，請先確認 FastCGI 已啟用，並驗證 Nginx 在讀取快取前排除有狀態的請求。YS CACHE 仍協調清除。Enhance 的 API 以網域為清除單位，因此由 Enhance 提供 HTML 時，請用全站清除。
- **CDN。** 選擇傳遞範圍，並依來源到外層排列。HTML 沿用網站主要網域，靜態資源可使用獨立 CNAME。儲存後在各已發布語系檢查圖片、JS 與 CSS 的網址及實際回應。供應商接受清除請求後，各 CDN 節點需要時間套用。
- **語系。** 後台依 WordPress 使用者語言顯示。使用 TranslatePress 的網站，請個別測試每個語系網址及其對應圖片。

[整合操作說明](docs/cache-integrations.md#繁體中文) · [授權](LICENSE)
