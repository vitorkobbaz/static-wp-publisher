=== Kobbaz Static Publisher ===
Contributors: vitorkobbaz
Tags: static site, static html, performance, export, speed
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve public pages as fast static HTML that refreshes itself when you edit, and export your site as a portable static package.

== Description ==

Static Publisher keeps WordPress as your editor and turns every public page into a ready-made static HTML copy. Visitors who are not logged in get the static copy; you keep working in WordPress as usual. When you edit a page, its copy and the pages that depend on it are rebuilt automatically in the background.

**Serve static HTML from your own server**

* Static copies for every public page, with no page limit.
* Automatic refresh after edits, menu, theme and customizer changes.
* A persistent queue with retries, so a slow or failing page never blocks the others.
* Safe by default: logged-in visitors, password-protected, private, personalized, search, preview and cart-like responses are always served by WordPress.
* Pages that are unpublished, deleted, protected or moved stop being served immediately.
* Optional speed optimizations for the copies: image preloading and lazy loading, missing image sizes, `font-display: swap`, and experimental CSS combining.

**Export a portable static site**

* Create a complete directory or ZIP of your site, with no page or export limit.
* Relocatable preview packages, or packages built for a target domain or subdirectory.
* Same-origin images, styles, scripts and fonts are copied and URLs are rewritten.
* Every package includes a manifest with SHA-256 checksums and deployment instructions.
* Warnings for things a static host cannot run, such as forms, comments and search.

**Built for administrators**

* A dashboard showing which pages are static, pending, served by WordPress or need attention, with row and bulk actions.
* "Test visitor version" compares the response time of the static copy and WordPress on your server.
* REST API and WP-CLI commands (`wp swpp status`, `build`, `publish`, `process`).

= Static Publisher Pro =

[Static Publisher Pro](https://static-publisher.vitorkobbaz.com/pro/) is a separate, paid add-on that deploys your static site automatically to SFTP, S3-compatible storage (AWS S3, Cloudflare R2, Backblaze B2) or Netlify, sending only the pages that changed. Everything described above is free and works without it.

== Installation ==

1. In WordPress, go to Plugins > Add New, search for "Static Publisher", then install and activate it. Or upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Open Static Publisher in the admin menu and click "Create static copies".
3. Use "Test visitor version" on a page to compare it with WordPress, then turn on static delivery.
4. To create a portable package, open Static Publisher > Portable Export.

== Frequently Asked Questions ==

= Is there a limit on pages or exports? =

No. The free plugin has no page, build or export limit.

= Do logged-in users see static pages? =

No. Logged-in users, and visitors with comment, password or cart cookies, always get the live WordPress page.

= What happens to forms, comments and search? =

When static delivery runs on your own server, WordPress still handles form submissions, comments and search. In an exported package they will not work without a backend, and the export lists the pages that use them.

= Which web servers are supported? =

Static copies are served by the plugin itself, so Apache, Nginx, LiteSpeed and IIS all work without extra configuration.

= Does it send data to external services? =

No. The plugin only makes requests to your own site to render pages and to measure speed.

== Screenshots ==

1. The dashboard with each page's state and the speed comparison.
2. Testing the visitor version of a page.
3. Creating a portable export.

== Changelog ==

= 1.0.0 =
* First public release.
* Static copies served from your own server, refreshed automatically after edits.
* Portable directory and ZIP exports, free and unlimited.
* Speed optimizations for static copies.
* Dashboard, REST API and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First public release.
