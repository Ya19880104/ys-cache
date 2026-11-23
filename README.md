# YS CACHE

[繁體中文](README.zh-TW.md) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE is a WordPress plugin for guest HTML caching, targeted cache clearing, and layered CDN delivery. It requires WordPress 6.4 or later and PHP 8.0 or later. The bundled YS Hub integration requires PHP 8.2 or later.

## Install and set up

1. Download the YS CACHE ZIP from YANGSHEEP CLOUD. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, and activate it.
2. Activation detects a supported active cache plugin and imports compatible settings before YS CACHE turns on HTML caching. Check **Tools → YS CACHE Auto Import** for the result or to retry. Existing users can update YS CACHE in place. If the old import add-on is active, an administrator's next visit retires it after any pending recovery finishes.
3. For a new cache configuration, open **Cache Management**, directly below **Dashboard**. Turn on guest HTML caching and save. The default lifetime is 28,800 seconds. Choose seconds, minutes, hours, or days to display and edit the same lifetime.
4. On a public page, use **Purge cache** in the WordPress toolbar to clear that page or the entire site. On an admin screen without a current page, enter the public page URL for a targeted purge.

## Set up and verify integrations

- **HTML, paths, and forms.** Logins, checkout, cart sessions, previews, and private responses bypass the cache. Enter public paths such as `/en/contact/` in **Cache settings → Exclusions**. A rule such as `/news/* 300` sets a shorter lifetime for eligible HTML. WordPress nonce rotation and response safety checks can shorten it further. Forms are detected in page content, builders, widgets, and footers. [Path rules and shorter lifetimes](docs/cache-integrations.md#path-rules-and-shorter-lifetimes)
- **Form pages.** WPForms, Fluent Forms, and JetFormBuilder continue to bypass HTML caching. **Public Contact Form 7 paths (Beta)** allows eligible simple guest forms on selected URLs to use local YS CACHE HTML for up to five minutes. To cache a containing page while keeping the form dynamic, embed a separate excluded same-origin form page. [Form setup and testing](docs/cache-integrations.md#forms-and-lifetime)
- **Early guest HTML.** In **Cache settings → HTML cache**, select exact public HTTPS paths whose anonymous HTML can be served before WordPress starts. The optional PixelYourSite beta serves a reusable homepage on supported direct visits and loads tracking privately for each visitor. With the separate Blocksy storefront option, eligible category and product pages can reuse their public main content while WordPress renders the visitor’s header, footer and tracking. [Early HTML setup and verification](docs/cache-integrations.md#early-guest-html-and-private-pixelyoursite-beta)
- **Elementor and PixelYourSite.** With native Elementor Element Caching active, eligible published singular pages in local YS CACHE mode can reuse Elementor document fragments while WordPress renders each visitor’s tracking. [Elementor cache headers and clearing](docs/cache-integrations.md#elementor-fragments-with-pixelyoursite)
- **CSS and JavaScript.** CSS minification, JS minification and JS deferral have separate switches and start off. Exclude handles or paths as needed. To defer a script, explicitly add its registered WordPress handle and test the features that use it.
- **Automatic clearing.** WordPress Cron combines nearby changes and clears the origin before the CDN layers. WooCommerce inventory changes target affected pages. Temporary failures retry with backoff for 24 hours. A delayed Cron runner makes the final attempt when it resumes. CDN layers can follow the main switch or be manual only.
- **Server.** OPcache can be detected automatically when PHP exposes its reset function to WordPress. LiteSpeed and standalone Nginx Cache (Beta) can manage origin HTML on supported hosts and purge before the CDN layers. YS CLOUD is also available under Integrations. [Standalone Nginx setup](docs/nginx-cache.md)
- **CDN.** Cloudflare, Bunny CDN and Gcore can look up existing resource IDs using provider credentials. Set the scope and order layers from the origin outward. Include alternate website hostnames for purge coverage. A CNAME-only layer rewrites eligible asset URLs to an existing delivery hostname. DNS setup and API purge use the CDN provider's configuration. [CDN connections](docs/cache-integrations.md#cdn-and-cname)
- **Languages.** The admin interface follows the WordPress user’s language. For a site using TranslatePress, test each language URL independently, including translated image variants.

[Integration guide](docs/cache-integrations.md) · [License](LICENSE)
