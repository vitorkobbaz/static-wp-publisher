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

The Static Publisher screen is a read model (`StatusReport`) over the queue, the artifacts table, and public content:

- A banner shows whether static serving is on, with a single toggle.
- Summary cards show static copies, waiting and retrying jobs, pages served by WordPress (not publishable, including password-protected), and errors. Counts use the latest job per URL.
- "Generate all pages now" starts a full inventory scan and runs REST worker passes (`/swpp/v1/build`, then `/swpp/v1/process` until nothing is due) with a progress bar. Without JavaScript it falls back to one server-side pass.
- A paginated list of public pages and posts resolves each row with `Domain\PageStatus` (static, updating, stale, exposed, queued, generating, retrying, served by WordPress, error, not generated).
- Per-row actions: "Regenerate" runs that URL immediately through the queue. "Check" performs an anonymous loopback request without cookies and reports whether the `X-Static-WP-Publisher: HIT` header was returned.
- Row actions accept a content ID (0 = home page), never a URL or path. The ID must resolve to published, public content.

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
