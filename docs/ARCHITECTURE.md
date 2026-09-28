# Architecture

## Packages

- Core owns capture, eligibility, storage, invalidation, queueing, publication, local serving, REST, CLI, and extension contracts.
- Export owns portability, rewriting, package manifests, ZIP generation, and commercial entitlement integration.
- Future Agency and Multisite add-ons consume the same public contracts.

## Publication flow

1. WordPress content or configuration changes enqueue affected URLs.
2. A worker claims jobs with per-URL leases. Each cron, admin, or REST pass processes up to 50 URLs (`swpp_worker_batch_size`) and stops claiming new jobs after 20 seconds (`swpp_worker_time_budget`), capped at half of PHP `max_execution_time`. WP-CLI `wp swpp process --limit=<n>` has no time budget.
3. The renderer fetches the anonymous public response with a signed internal-render header.
4. Eligibility rejects private, personalized, erroneous, or unsafe responses. Deterministic refusals (password-protected, personalized, cookie-setting, non-HTML, or non-200/non-transient responses) remove any existing static copy so WordPress serves the page dynamically, and close the job as `skipped` without retries. Transient failures (network errors, 408/425/429, 5xx, empty bodies) retry with exponential backoff. The full inventory excludes password-protected posts.
5. Storage writes to a temporary file, hashes it, and atomically replaces the live artifact.
6. The previous artifact is retained according to the configured policy.

WP-Cron is a compatibility fallback. Production sites should configure a real scheduler or invoke WP-CLI. Direct web-server delivery is enabled only through reviewed server rules; PHP fallback remains available.

## Administration

The Static Publisher screen is a read model (`StatusReport`) over the queue, the artifacts table, and visitor-facing content.

- **Content scope.** Only public post types that are real pages count. Page-builder and block-theme templates are excluded, as are content items whose address carries a query string. The excluded types are `elementor_library`, `e-floating-buttons`, `wp_template`, Divi/Beaver/Oxygen/Bricks libraries, and others (`DomainContentScope`, filter `swpp_excluded_post_types`). Publishing a template does not queue its own address. It requests a full rebuild, because headers and footers change every page.
- **States.** `DomainPageStatus` resolves each page from its latest job and artifact, then maps it to one tab:
  - *Static*: an up-to-date copy.
  - *Pending*: queued, generating, retrying, or update pending.
  - *Needs attention*: error, outdated copy, or a protected page that still has a public copy.
  - *Served by WordPress*: password-protected or not publishable.
  - *Not generated*.

  Colours follow the group: green only for up-to-date copies, blue for pending, amber for retrying or outdated, red for failures, grey for WordPress-only pages. Every state also has an icon.
- **Title row.** It follows core list screens: the heading, then one contextual action.
  - A fresh site gets "Create static copies".
  - When pages lack a good copy, the action is "Fix N pages" (`POST /swpp/v1/fix` queues exactly those pages).
  - A healthy site gets only "Update all static copies".
- **Delivery state.** A one-line "Static delivery: On · Pause" sits below the title, and pausing asks for confirmation. While paused, a persistent notice offers "Resume". A protected page that still has a public copy gets its own error notice with a one-click fix.
- **First run.** A single explanatory panel with "Create static copies" replaces the summary and the table.
- **Summary panel.**
  - The coverage sentence counts against every page ("13 of 16 pages load as fast static HTML").
  - Next comes a composition bar. Under it, a sentence says what the other pages are; each clause links to its tab.
  - Status is resolved in PHP for up to 2,000 recently modified items (`swpp_dashboard_item_limit`), with only the columns permalinks need. A persisted URL index is pending for very large sites.
- **Server response time.** One action, "Test visitor version", is used by the row, bulk, and summary page picker (`POST /swpp/v1/speed-check` with `post_id`).
  - It requests the page 3 times the way a visitor gets it and 3 times through WordPress (with a cache-busting query parameter the static server never answers), from this server.
  - It reports what visitors received and both median times, in seconds.
  - A speed-up is claimed only at 1.3× or more (`Domain\SpeedComparison`).
  - "How is this measured?" states that this is server response time, not a PageSpeed score, and links to PageSpeed Insights.
  - The last result is stored in `swpp_speed_check`.
- **Assets.** CSS and JS are versioned by content hash, so updates bypass browser and CDN caches.
- **Visual language.** The screen follows native wp-admin conventions: a Site Health-style header band, the admin colour scheme (`--wp-admin-theme-color`) as the only accent, WordPress status colours, a 4px spacing grid, and tabular numerals. States are a dot plus a text label, never colour alone. Below 782px the state also appears inside the title cell, because WordPress collapses secondary columns there.
- **List.** The list is a native `WP_List_Table` with tabs, search, pagination, checkboxes, and bulk actions (Regenerate, Check delivery). Row actions are View, Regenerate, and Check delivery.
  - With JavaScript, row and bulk actions run in place through `POST /swpp/v1/pages/{id}/{regenerate|verify}`, and only the affected rows are updated.
  - Without JavaScript, the same actions go through admin-post (row) or the list-table request (bulk, up to 50 items).
- **Generation.** "Generate all pages now" runs `/swpp/v1/build`, then `/swpp/v1/process` passes with a progress bar. "Process pending now" runs only the process passes. While jobs are queued or running, the screen polls `/swpp/v1/status` and reloads when they finish, unless the user is selecting rows or an action is running.
- **Row actions** accept a content ID (0 = home page), never a URL or path. The ID must resolve to published, visitor-facing content.
- **Verification.** "Check delivery" performs an anonymous loopback request without cookies. It reports whether the `X-Static-WP-Publisher: HIT` header came back, with the HTTP status and timing.

## Speed optimizations in static copies

`Application\PageOptimizer` runs `Domain\HtmlOptimizer` on every rendered page before `Storage::write()`. WordPress output itself is never modified. The optimizer works on a token stream (comments, raw-text elements such as script, style and noscript, tags, text), so script and style bodies are never treated as markup. Any failure stores the page exactly as rendered, and an already optimized document (marker `data-swpp-opt`) is left alone.

- **Optimize images and fonts** (`optimize`, on by default):
  - At most two image preloads with `fetchpriority="high"` are inserted right after `<meta charset>`:
    - the `<img>` WordPress marked `fetchpriority="high"` (never lazy);
    - the CSS background of the first three Elementor sections that declare `background_background`. It is looked up in the page's own local `/elementor/css/` stylesheets, ignoring rules inside `@media` or `:hover`.
  - Images after the first two get `loading="lazy"`, which the `swpp_eager_images` filter adjusts.
  - Every image gets `decoding="async"`.
  - Missing `width`/`height` are read from local files.
  - Inline `@font-face` rules with `font-display: auto|block`, or with no value, get `swap`.
- **Combine CSS files** (`combine_css`, experimental, off by default):
  - Only runs of consecutive `<link rel="stylesheet">` in `<head>` are combined. The files must share the same `media`, be local, and contain no `@import`. Any inline style, script, other markup, or non-qualifying sheet ends a run, so the cascade order is unchanged.
  - Relative `url()` references are made absolute, and `@charset` is dropped.
  - Bundles are content-addressed files under `static-wp-publisher/site-N/assets/css/`, written atomically.
- **File access:** `Infrastructure\LocalAssetResolver` reads only files under `content_url()` or `includes_url()`, with an allowed extension, whose resolved real path stays inside `wp-content` or `wp-includes`. It never uses a path from a request.
- **Rebuilds:** changing the options in "Speed options" rebuilds every copy.

## Storage

Default root: `wp-content/uploads/static-wp-publisher/<site-id>/`

Builds, published artifacts, versions, temporary files, and manifests are isolated. Temporary artifacts are denied public execution and use canonicalized allowlisted paths.

Portable export working directories and ZIP files are stored separately under the system temporary directory, outside every detected web document root. Hosts whose temporary directory resolves inside the public site must provide a writable private path with the `swpp_export_private_base_dir` filter. Download authorizations expire after one hour, while export data is retained briefly for retry and purged after 48 hours by a daily cleanup job.

## Security boundaries

- Only anonymous GET responses are eligible.
- The PHP fallback serves an artifact only for query-less URLs or URLs whose parameters are all known tracking parameters (`utm_*`, `gclid`, `fbclid`, `msclkid`, and similar; adjustable with `swpp_ignored_query_parameters`). Search, `?p=`, previews, pagination, and any other parameter reach WordPress.
- Cookies, request bodies, authorization headers, and private response headers are never persisted. Nonces that themes or plugins print into public anonymous HTML (for example Elementor Pro front-end config) are persisted with the page and expire after 12–24 hours; see `PENDING_FEATURES.md`.
- Renderer requests are host-restricted and signed to avoid cache recursion.
- Export paths reject traversal, absolute paths, symlink escapes, duplicate normalized names, and case collisions.
- Commercial licensing is isolated behind an adapter and cannot disable Core.
