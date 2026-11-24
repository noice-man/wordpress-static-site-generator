=== Static Site Generator for WordPress — WPR Static ===
Contributors: wpr
Tags: static site generator, static html, wordpress static, page cache, export
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into a static HTML frontend on the same domain. Automatic rebuilds, integrity checks and optional portable ZIP export.

== Description ==
Static Site Generator for WordPress (WPR Static) pre-generates eligible public HTML pages while keeping your existing domain, theme, WordPress editor and original media/CSS/JS URLs. It validates a build before serving its pages. No third-party cloud service is required.

* One-click static mode after activation; no new domain or DNS changes.
* Direct Apache HTML delivery when the generated rules and guarded storage pass live HTTP probes.
* Early PHP delivery using a managed advanced-cache.php if the configuration allows it; otherwise a WordPress PHP fallback. The fallback loads WordPress and does not eliminate database initialization.
* Anonymous GET/HEAD only. Requests with cookies, query strings, authorization, origin crawler headers or unsupported routes fall back to WordPress. Forms requiring POST, pages with nonces, private cache headers, content-security policy, Set-Cookie or unexpected Vary headers remain dynamic.
* Rebuilds are debounced after WordPress content changes. To avoid leaking stale private/deleted content, any detected content change suspends static delivery until a fresh build passes validation.
* Separate portable export with HTML/CSS/JS/media rewriting, ZIP and local publish.
* Readiness check, logs, integrity validation and build history.

== Installation ==
1. Upload the release ZIP via Plugins > Add New > Upload Plugin. Activate it on a staging site first.
2. Open Static Site Generator in the admin sidebar and click Enable static mode. The current home URL is used automatically.
3. Wait for the build to complete. The overview shows the selected delivery mode and eligible routes. Test anonymous browsing, admin access, forms and changes to content.
4. To return to regular WordPress, click Disable static mode. Normal plugin deactivation also removes the managed routing integration.
5. For a separate static hosting site, use Settings and Build portable export. Download its ZIP when ready.

Requires WordPress single-site, pretty permalinks, anonymous HTTP loopback, PHP DOM/XML and writable private storage outside all public roots. ZipArchive is needed for ZIP export, but not for the same-domain frontend. If the default private sibling directory is unavailable, an administrator may set WPR_STATIC_STORAGE in wp-config.php. See INSTALL-RU.md for details.

== Frequently Asked Questions ==
= Will it work on Nginx? =
PHP delivery can work on Nginx without server configuration if WordPress receives the request. Direct no-PHP routing is automatically attempted only on suitable root Apache installations. PHP delivery is not equivalent to zero-PHP delivery. A conflicting advanced-cache.php must be disabled first.
= What happens to forms and accounts? =
Dynamic requests continue to use WordPress. The conservative detection is not a guarantee for arbitrary plugins; test your site. WooCommerce frontend mode is blocked in this release; standalone export is still available with its existing limitations.
= Do I need to export all assets? =
Not for same-domain mode: original asset URLs stay unchanged and assets remain on the original server. A separate portable export includes collected resources and rewritten paths.
= What if automatic rebuilding does not start? =
WP-Cron needs WordPress traffic or an external cron trigger. Keep the progress page open or use WP-CLI. After edits the frontend temporarily returns to WordPress until a new validated build is ready. Direct filesystem/database edits and external caches are outside guaranteed invalidation coverage.
= Is telemetry enabled? =
No. The bundled original WPR Update Hub SDK 1.1.3 has telemetry disabled. Standard update checks still use the hub.

== Changelog ==
= 1.1.0 =
* SEO-oriented public name; unchanged plugin slug and Update URI.
* Same-domain static frontend, live Apache route probes, guarded direct files, early PHP drop-in and WordPress fallback.
* Debounced rebuild, fail-closed invalidation, origin response proof, dynamic route bypass, automatic old frontend-build retention, frontend CLI commands.
* Same-domain HTML retains original markup and asset URLs; portable export is a separate mode.
= 1.0.0 =
* Initial static crawler, queue, validation, manifest, ZIP and local publication.
