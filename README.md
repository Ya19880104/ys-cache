# YS CACHE

[繁體中文](README.zh-TW.md) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE is a WordPress plugin for guest HTML caching, targeted cache clearing, and layered CDN delivery. It requires WordPress 6.4 or later and PHP 8.0 or later. The bundled YS Hub integration requires PHP 8.2 or later.

## Install and set up

1. Download the YS CACHE ZIP from YANGSHEEP CLOUD. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, and activate it.
2. Activation detects a supported active cache plugin and imports compatible settings before YS CACHE turns on HTML caching. Check **Tools → YS CACHE Auto Import** for the result or to retry. Existing users can update YS CACHE in place. If the old import add-on is active, an administrator's next visit retires it after any pending recovery finishes.
3. For a new cache configuration, open **Cache Management**, directly below **Dashboard**. Turn on guest HTML caching and save. The default lifetime is 28,800 seconds and the maximum is 12 hours. Choose seconds, minutes, hours, or days to display and edit the same lifetime.
4. On a public page, use **Purge cache** in the WordPress toolbar to clear that page or the entire site. On an admin screen without a current page, enter the public page URL for a targeted purge.

## Set up and verify integrations

- **HTML, paths, and forms.** Logins, checkout, cart sessions, previews, and private responses bypass the cache. Enter public paths such as `/en/contact/` in **Cache settings → Exclusions**. A rule such as `/news/* 300` sets a shorter lifetime for eligible HTML. WordPress nonce rotation and response safety checks can shorten it further. Forms are detected in page content, builders, widgets, and footers. [Path rules and shorter lifetimes](docs/cache-integrations.md#path-rules-and-shorter-lifetimes)
- **Form pages.** In **Public form paths (Beta)**, add a guest contact page and its translated paths. Local HTML can be reused for up to five minutes on supported simple forms. WPForms Lite, Fluent Forms and JetFormBuilder render fresh fields on each visit. Contact Form 7 can reuse public form markup. [Supported versions and setup](docs/cache-integrations.md#forms-and-lifetime)
- **Early cache.** With local HTML caching on, every public page can be served before WordPress loads. Logged-in users and visitors with a cart, wishlist, currency selection or saved comment details go to WordPress. Tracking parameters such as `utm_*`, `fbclid` and `gclid` are ignored, and desktop and phone versions are cached separately. Pages are stored only from clean renders, preload refills them in the background, and each copy expires within 12 hours so guest nonces stay valid. Find the switches in **Cache settings → HTML cache**. [Early cache rules, headers and verification](docs/cache-integrations.md#early-cache)
- **PixelYourSite.** Pages with PixelYourSite output are cached like any other page, as with WP Rocket, and no PixelYourSite setting needs to change. Its cookies, including `PHPSESSID`, never make a visitor skip the cache. [PixelYourSite and caching](docs/cache-integrations.md#pixelyoursite)
- **CSS and JavaScript.** CSS minification, JS minification and JS deferral have separate switches and start off. Exclude handles or paths as needed. To defer a script, explicitly add its registered WordPress handle and test the features that use it.
- **Automatic clearing.** Saving a product or post clears that page, its archives and the front page, and inventory changes target the affected pages. Menus, widgets, theme changes and discount rules clear the whole site. Local copies are cleared right away and preload renders them again. WordPress Cron combines nearby changes and clears the origin before the CDN layers. Temporary failures retry with backoff for 24 hours. A delayed Cron runner makes the final attempt when it resumes. CDN layers can follow the main switch or be manual only.
- **Server.** OPcache can be detected automatically when PHP exposes its reset function to WordPress. LiteSpeed and standalone Nginx Cache (Beta) can manage origin HTML on supported hosts and purge before the CDN layers. YS CLOUD is also available under Integrations. [Standalone Nginx setup](docs/nginx-cache.md)
- **CDN.** Cloudflare, Bunny CDN and Gcore can look up existing resource IDs using provider credentials. Set the scope and order layers from the origin outward. Include alternate website hostnames for purge coverage. A CNAME-only layer rewrites eligible asset URLs to an existing delivery hostname. DNS setup and API purge use the CDN provider's configuration. [CDN connections](docs/cache-integrations.md#cdn-and-cname)
- **Languages.** The admin interface follows the WordPress user’s language. For a site using TranslatePress, test each language URL independently, including translated image variants.

[Integration guide](docs/cache-integrations.md) · [License](LICENSE)
