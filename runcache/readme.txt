=== RunCache – Full-Page, Object, and Edge Caching ===
Contributors: runcloud
Tags: cache, page cache, performance, redis, cloudflare
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The free WordPress caching plugin: server-level full page cache on NGINX, Apache or LiteSpeed, plus Redis object cache and Cloudflare.

== Description ==

[RunCache](https://runcloud.io/runcache) is a free WordPress caching plugin that combines a full page cache, a Redis object cache, and a CDN (content delivery network) in one place. It caches your pages in the web server itself, not only in PHP, and it runs on any server you control.

RunCache is built by RunCloud but does not require RunCloud hosting or a RunCloud account. It performs no license check and never contacts RunCloud.

### See RunCache in Action

https://youtu.be/crS2zbPFRRs

### Why a Server-Level Cache Plugin Is Faster

Most WordPress caching plugins work at the application level. They still start PHP, load WordPress, and run a drop-in before returning the stored HTML, so much of the cost of building the page is incurred on every request.

RunCache configures caching at the server level, allowing supported server configurations to return cached pages without starting PHP or opening a database connection. On a cache hit, NGINX, Apache, or LiteSpeed reads the stored page and returns it directly, so PHP never starts, and no database connection opens. That is what visitors feel in Time to First Byte (TTFB), and it matters most during traffic spikes, when PHP workers are the bottleneck.

Server-level caching normally comes with a catch: it works only on one web server, or only on one host's servers. RunCache is built the other way round. It supports NGINX, NGINX FastCGI, Apache, LiteSpeed, Redis, and Cloudflare, detects what your server actually runs, and offers only the types that will work.

Move hosts, and the same plugin and the same settings screen follow you.

### Full Page Cache: Seven Types, Ranked by Speed

Instantly improve your site's performance and scalability with zero-configuration caching and sensible defaults. RunCache writes the configuration for the type you pick and only offers what your server supports. [Compare cache types](https://runcloud.io/docs/full-page-cache).

1. **NGINX FastCGI Cache:** NGINX stores and serves the page itself, so PHP never starts on a hit. Fastest option on an NGINX stack. Needs shell access, plus `ngx_cache_purge` to purge single URLs. [Setup guide](https://runcloud.io/docs/nginx-fastcgi-cache).
2. **Apache:** mod_rewrite rules in `.htaccess` serve the stored file before the request reaches PHP. Nearly as fast as FastCGI and easier to enable, because `.htaccess` is usually already writable. Needs `mod_rewrite`, `mod_headers`, and a restart.
3. **LS Cache:** uses the cache engine built into LiteSpeed Web Server and OpenLiteSpeed. Comparable to FastCGI, and shown only when a LiteSpeed server is detected.
4. **NGINX Cache:** NGINX serves the stored HTML file directly. Simpler than FastCGI and needs no extra module, but varies on fewer request conditions.
5. **Redis Cache:** stores full pages in Redis rather than on disk. Slightly slower, because PHP still reads from Redis, but the right answer when several containers or servers share one cache.
6. **PHP File Cache:** serves stored HTML from a drop-in before WordPress loads. PHP starts, but your theme, plugins, and database are all skipped. Needs no server access, works on shared hosting, and is active the moment you activate RunCache.
7. **Cloudflare Cache:** caches your HTML at Cloudflare's edge and purges it when you publish, serving pages near the visitor rather than from your server. Helps international traffic the most, and works in front of any of the six above. [Setup guide](https://runcloud.io/docs/cloudflare-full-page-cache).

### Redis Object Cache

WordPress runs the same queries on every page load, fetching options, theme settings, and user data over and over, and the built-in object cache is cleared at the end of each request.

Redis Object Cache makes it persistent. WordPress checks Redis first, and on a hit, the value returns without touching the database. This is the layer that speeds up wp-admin, WooCommerce checkout, and membership areas, because none of those pages can be served from a page cache at all. [Learn more](https://runcloud.io/docs/object-cache), and [secure Redis with ACLs](https://runcloud.io/docs/securing-redis) before you expose it.

### Clear the WordPress Cache Automatically

You should rarely need to clear the cache manually, because RunCache purges the pages your changes actually affected. [Learn more about RunCache's Cache Purging settings](https://runcloud.io/docs/cache-purger-settings).

- **On publish and edit:** clears that post and the archives it appears on, not the whole cache. On by default.
- **On comments:** clears the post the comment belongs to, so it appears at once. On by default.
- **On-site changes:** theme switches, permalink changes, and site title edits clear everything. On by default.
- **Optional triggers:** menus, widgets, sidebars, Customizer, plugin activation, and upgrades. These ship off, because on a busy site, they clear far more than most people want.
- **Manual and scheduled:** clear everything, one URL, or only expired entries, from the settings page, the admin bar, or WP-CLI.

### Cache Preloading

A cache only helps visitors who arrive after it is built. The first request after a purge still waits for WordPress, and on a large site, that cold request is the slowest of the day.

Cache Preloader rebuilds those pages in the background, after a purge or on a schedule, so the cache is warm before anyone asks. It is rate-limited to 1-120 URLs per minute, so tune it to what your server can handle. [Preloader settings](https://runcloud.io/docs/cache-preloader-settings), [how WP-Cron schedules it](https://runcloud.io/docs/understanding-wp-cron).

### Cache Rules and Exclusions

Caching the wrong page is worse than not caching, so RunCache ships safe defaults and lets you extend them all. [Cache Rules reference](https://runcloud.io/docs/cache-rules).

- **URL Path Exclusion:** wp-admin, REST API, feeds, sitemaps, login, cart, checkout, and account pages are excluded out of the box.
- **Cookie Rules:** logged-in users, commenters, and visitors holding a WooCommerce cart bypass by default.
- **Query String Rules:** ignore tracking parameters so `?utm_source=` variants share one cached copy instead of duplicating it.
- **Browser and IP Rules:** let a developer, editor, or uptime monitor always see the live page.
- **Additional Cache Key:** store a separate copy per request header, which is how you serve desktop and mobile markup on one URL.
- **DONOTCACHEPAGE:** always honored so that any plugin can force a bypass with no configuration from you.

### Asset CDN

**BunnyCDN**, any **Custom CDN** hostname, or **Cloudflare APO**. Rewrites image, CSS, and JavaScript URLs so static files are served near the visitor, and purges the zone on demand. [BunnyCDN setup](https://runcloud.io/docs/bunnycdn-integration).

### What Is Never Cached

Logged-in users and commenters, cart, checkout, My Account, WooCommerce AJAX fragments, POST requests, feeds, REST requests, sitemaps, password-protected posts, page builder previews, and 404s.

### Also Included

- **Import and Export Settings:** move a configuration between sites as JSON, turning an hour of cache tuning into a one-minute setup on every client site after the first. [Import and export](https://runcloud.io/docs/import-export-settings).
- **WP-CLI native:** `wp runcache purge`, `preload-all`, `enable`, `status`, and `stats`, so clearing the cache fits into a deploy script.
- **Multisite:** network-enabled, cached pages namespaced per site, configured per site rather than network-wide.

### Free, With No Paid Tier

All the features above are in this plugin. No pro version, no license key, no locked settings, no upsells, and no feature that stops working after a trial.

There is also no meter. No page view cap, no bandwidth cap, no limit on how many sites you install it on, and no yearly renewal. Nothing is throttled as your traffic grows, and RunCache does not send your site through a mandatory third-party optimization service. External requests are made only when you choose to configure an integration, such as Cloudflare or BunnyCDN.

== Documentation and Support ==

- [RunCache product page](https://runcloud.io/runcache)
- [Introduction to RunCache](https://runcloud.io/docs/introduction-to-runcache)
- [Getting started](https://runcloud.io/docs/getting-started-with-runcache)
- [All documentation](https://runcloud.io/docs/category/runcache)
- [Do I really need a WordPress caching plugin?](https://runcloud.io/docs/do-i-really-need-a-wordpress-caching-plugin)
- [WordPress performance FAQ](https://runcloud.io/docs/wordpress-performance-faq)
- [Support forum](https://wordpress.org/support/plugin/runcache/)

== Installation ==

1. Go to **Plugins > Add New** and search for RunCache.
2. Click **Install Now**, then **Activate**.
3. Open **RunCache** from the admin menu.

Caching starts the moment you activate it, using the PHP file cache, which requires no server configuration. To switch cache types, open RunCache > Full Page Cache and choose one supported by your server.

WordPress 5.9 and PHP 8.0 are the only requirements. The server-level types need more: shell access and an NGINX reload for the NGINX types, `mod_rewrite` and `mod_headers` for Apache, LiteSpeed or OpenLiteSpeed for LS Cache, the `redis` extension for Redis, and a proxied zone plus API token for Cloudflare.

== Frequently Asked Questions ==

= How do I clear the WordPress cache? =

RunCache clears it for you whenever you publish or edit content, so you do not need to do it most of the time. To clear it manually, use the Purge button in the admin bar, open **RunCache > Cache Purger** to clear everything or a single URL, or run `wp runcache purge` on the command line.

= How do I know my pages are being cached? =

Open your site in a private window and check the response headers. `X-RunCache-Status` reads `HIT` when the page came from cache, `MISS` on the first request, and `BYPASS` when a rule excludes it. `X-RunCache-Type` names the type that answered. The Cloudflare edge providers report their layer with `X-CF-Status` instead.

= Which cache type should I use? =

Use the fastest one your server supports. On NGINX with shell access, that is NGINX FastCGI Cache, because NGINX answers the request, and PHP never starts. On LiteSpeed or OpenLiteSpeed, it is LS Cache. Without shell access, the PHP file cache already works, and Redis Cache is a step up if your host offers Redis.

= Is RunCache really free, or is there a pro version? =

RunCache is free, and there is no pro version. Nothing is locked behind a payment, a trial, an account, or a license key. There is no page view or bandwidth meter that throttles you as traffic grows, no per-site licensing, and no yearly renewal. If you are looking for a free cache plugin to replace a paid one, it includes every feature listed above.

= Do I need a RunCloud account to use RunCache? =

No. RunCache is standalone and works on any server you control, RunCloud-managed or not. It performs no license check and never contacts RunCloud.

= Does RunCache work with WooCommerce? =

Yes. Cart, checkout, My Account, add-to-cart, and AJAX cart fragments are excluded by default, so a cached page can never show one customer's cart to another. Stock changes purge that product. For pages that cannot be cached at all, use the Redis Object Cache. If you take payments through Stripe, see [WooCommerce Stripe gateway caching issues](https://runcloud.io/docs/woocommerce-stripe-gateway-caching-issues).

= Does RunCache work with Cloudflare? =

Yes, in two ways. Cloudflare Cache stores your HTML at the edge and purges it when content changes, and Cloudflare APO enables Automatic Platform Optimization on your zone. Both need your DNS proxied and an API token with Zone Read, Cache Purge, and Cache Rules Edit.

= Can I cache pages for logged-in users? =

No. Any visitor carrying a `wordpress_logged_in` cookie bypasses the page cache, and this applies to all roles, because a page built for one logged-in user must never be served to another. Use the Redis Object Cache instead.

= How do I exclude a page or URL from being cached? =

Open **RunCache > Cache Rules** and add the path under URL Path Exclusion. You can also exclude by cookie, query string, user agent, and IP. Developers can define `DONOTCACHEPAGE`, which RunCache honors automatically.

= I am on LiteSpeed or OpenLiteSpeed. Do I need to disable LiteSpeed Cache? =

Yes. If the LiteSpeed Cache plugin is active it holds the page-cache drop-in, so RunCache cannot take over. Deactivate LiteSpeed Cache first, then enable LS Cache inside RunCache.

= Can I use RunCache alongside another caching plugin? =

Not another page cache. Two page caches compete for the same `advanced-cache.php` drop-in, producing stale pages, so deactivate any other caches first. Plugins doing a different job, such as security or image optimization, are fine.

= Does RunCache modify wp-config.php? =

Yes. Enabling a cache type adds `define('WP_CACHE', true);`, and the Redis Object Cache adds a marked block with your Redis details, including the password in plain text. Both are removed when you disable the feature.

= What happens when I deactivate RunCache? =

Disable the active cache type first, from **RunCache > Full Page Cache**, which removes the generated `.htaccess` block or config file. Deactivating then purges the cache and removes the drop-in.

= How do I report a security issue? =

Please email support@runcloud.io rather than filing a public report so that it can be fixed before disclosure.

== External Services ==

RunCache contacts two third-party services, and only when you enable that integration and enter your own credentials. Otherwise, it makes no external requests. It contains no analytics, telemetry, or tracking. Web server detection and preloading requests stay on your own domain.

**Cloudflare** powers the Cloudflare Cache and APO integrations. Sent: your API token or account email and key, zone identifier, site hostname, and page URLs, and the cache rule, APO setting, or purge request. Sent when you save credentials, when a rule changes, on every purge, and at uninstall to remove the rule it created. [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/).

**BunnyCDN** powers the Asset CDN integration. Sent: your API key and Pull Zone identifier, asset and page URLs, and the purge request, when you verify or save credentials, and on every purge. [Terms](https://bunny.net/tos/), [Privacy](https://bunny.net/privacy/).

== Screenshots ==

1. RunCache dashboard: cache status, pages cached, size
2. Full Page Cache: RunCache detects your server and offers only the cache types that work
3. NGINX Page Cache setup with the generated include line
4. Redis Object Cache connection settings
5. Asset CDN: BunnyCDN or your own CDN hostname
6. Cache Rules: URL, cookie, query string, user agent, IP
7. Cache Purger: triggers and manual tools
8. Cache Preloader: rate limit and schedule

== Changelog ==

= 1.0.0 =
* First release on WordPress.org.
* Full page cache with seven types: NGINX FastCGI, NGINX, Apache, LS Cache, Redis, PHP file cache and Cloudflare.
* Redis object cache with connection testing and a guided setup.
* Asset CDN for BunnyCDN, any custom CDN hostname, and Cloudflare APO.
* Cache Rules for URL paths, cookies, query strings, user agents, IP addresses and an additional cache key, with DONOTCACHEPAGE always honored.
* Cache Purger with automatic triggers on publish, edit and comments, plus manual and scheduled purging.
* Cache Preloader with a configurable rate limit of 1 to 120 URLs per minute.
* WP-CLI commands and multisite support.
* Import and export settings as JSON.

== Upgrade Notice ==

= 1.0.0 =
First release on WordPress.org.

