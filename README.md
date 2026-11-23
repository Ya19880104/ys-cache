# YS CACHE

[繁體中文](README.zh-TW.md) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE is a WordPress plugin for guest HTML caching, targeted cache clearing, and layered CDN delivery. It requires WordPress 6.4 or later and PHP 8.0 or later. The bundled YS Hub integration requires PHP 8.2 or later.

## Install and set up

1. Download the YS CACHE ZIP from YANGSHEEP CLOUD. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, and activate it.
2. Open **Cache Management**, directly below **Dashboard**. Turn on guest HTML caching and save. New installations default to 28,800 seconds. Choose seconds, minutes, hours, or days to display and edit the same lifetime.
3. To bring settings from a supported cache plugin, use the optional automatic import ZIP on the same download page. Install the current YS CACHE version first and read the import result in **Tools → YS CACHE Auto Import**.
4. On a public page, use **Purge cache** in the WordPress toolbar to clear that page or the entire site. On an admin screen without a current page, enter the public page URL for a targeted purge.

## Set up and verify integrations

- **HTML, paths, and forms.** Logins, checkout, cart sessions, previews, and private responses bypass the cache. Enter public paths such as `/en/contact/` in **Cache settings → Exclusions**. A rule such as `/news/* 300` sets a shorter lifetime for eligible HTML. WordPress nonce rotation and response safety checks can shorten it further. Forms are detected in page content, builders, widgets, and footers. [Path rules and shorter lifetimes](docs/cache-integrations.md#path-rules-and-shorter-lifetimes)
- **Form pages.** WPForms, Fluent Forms, and JetFormBuilder continue to bypass HTML caching. **Public Contact Form 7 paths (Beta)** allows eligible simple guest forms on selected URLs to use local YS CACHE HTML for up to five minutes. To cache a containing page while keeping the form dynamic, embed a separate excluded same-origin form page. [Form setup and testing](docs/cache-integrations.md#forms-and-lifetime)
- **CSS and JavaScript.** CSS minification, JS minification and JS deferral have separate switches and start off. Exclude handles or paths as needed. To defer a script, explicitly add its registered WordPress handle and test the features that use it.
- **Automatic clearing.** WordPress Cron combines nearby changes and clears the origin before the CDN layers. WooCommerce inventory changes target affected pages. Temporary failures retry with backoff for up to 24 hours. CDN layers can follow the main switch or be manual only.
- **Server.** OPcache can be detected automatically when PHP exposes its reset function to WordPress. LiteSpeed Cache (Beta) can manage origin HTML and participate in the same CDN purge sequence on supported hosts. YS CLOUD is available under Integrations, after CDN Layers.
- **CDN.** Choose a delivery scope and order layers from the origin outward. Enter alternate website hostnames for purge coverage and use dedicated CNAME hostnames for assets. After saving, check images, scripts and styles in each published language and verify the CDN serves the rewritten URLs. A provider can accept a purge before all CDN locations have applied it.
- **Languages.** The admin interface follows the WordPress user’s language. For a site using TranslatePress, test each language URL independently, including translated image variants.

[Integration guide](docs/cache-integrations.md) · [License](LICENSE)
