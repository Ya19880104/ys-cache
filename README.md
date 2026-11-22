# YS CACHE

[繁體中文](README.zh-TW.md) · [Official downloads](https://yangsheep.com.tw/wordpress-plugins/ys-cache/)

YS CACHE is a WordPress plugin for guest HTML caching, targeted cache clearing, and layered CDN delivery. It requires WordPress 6.4 or later and PHP 8.0 or later. The bundled YS Hub integration requires PHP 8.2 or later.

## Install and set up

1. Download the YS CACHE ZIP from YANGSHEEP CLOUD. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP, and activate it.
2. Open **Cache Management**, directly below **Dashboard**. Turn on guest HTML caching and save. The default lifetime for new installations is 28,800 seconds. Existing lifetimes are converted to seconds.
3. To bring settings from a supported cache plugin, use the optional automatic import ZIP on the same download page. Install the current YS CACHE version first and read the import result in **Tools → YS CACHE Auto Import**.
4. On a public page, use **Purge cache** in the WordPress toolbar to clear that page or the entire site. On an admin screen without a current page, enter the public page URL for a targeted purge.

## Set up and verify integrations

- **HTML and exclusions.** Requests involving logins, checkout, cart sessions, previews, or private responses bypass the cache. The plugin detects forms on the current page, including rendered forms in builders, widgets, or footers. Seven days is the maximum configured lifetime. WordPress nonce rotation and response safety checks may shorten that lifetime.
- **Server.** OPcache can be detected and selected automatically. To hand HTML caching to Enhance, verify FastCGI and its request-time bypass rules in the panel first. YS CACHE continues to coordinate invalidation. A single-page purge cannot clear Enhance FastCGI through the domain-wide API. Use the entire-site action when Enhance is serving HTML.
- **CDN.** Choose a delivery scope and order layers from the origin outward. Use the canonical site hostname for HTML delivery and dedicated CNAME hostnames for assets. After saving, check images, scripts and styles in each published language and verify the CDN serves the rewritten URLs. A provider can accept a purge before all CDN locations have applied it.
- **Languages.** The admin interface follows the WordPress user’s language. For a site using TranslatePress, test each language URL independently, including translated image variants.

[Integration guide](docs/cache-integrations.md) · [License](LICENSE)
