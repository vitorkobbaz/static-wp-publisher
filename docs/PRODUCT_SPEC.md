# Product specification

## Scope

Static WP Publisher turns public WordPress responses into static HTML and keeps them fresh after content changes. WordPress remains the editorial source.

The product intentionally contains no SEO-audit, SEO-scoring, LLM-discovery, or AI-crawler module. Existing public HTML is preserved; URLs are rewritten only when required for static delivery or relocation.

## First public release

### Free plugin (WordPress.org)

- Unlimited local static pages, with no page, build, or export quota.
- Local atomic publication with stale-while-revalidate behavior.
- Event-driven invalidation, persistent queue, retries, locks, and dynamic fallback.
- Speed optimizations for static copies.
- Manual full directory/ZIP exports: relocatable preview or publishable target-domain mode, asset copying, URL rewriting, manifests, checksums, deployment instructions, and large-package safeguards.
- Apache, Nginx, and IIS guidance.
- Basic history, rollback primitives, logs, REST API, and WP-CLI.

### Pro add-on (distributed outside WordPress.org)

- Automatic deployment to SFTP, S3-compatible storage, and Netlify.
- Incremental deployment of changed pages and assets after each publication.
- Server profiles with redirect and real-404 configuration files.
- Priority support. Licensed per site (1, 5, or 25 sites); see `COMMERCIAL.md`.

## Explicit non-goals for the first release

- Search conversion.
- Form or comment backends.
- Headless-browser rendering.
- Remote post-deployment verification.
- Agency and Multisite control planes.
- Cloud hosting or build services.
## Compatibility

- WordPress 6.5+
- PHP 8.3+
- MySQL 8.0+ or MariaDB 10.11+
- Current WooCommerce, WPML, Polylang, and TranslatePress are certified progressively.
- Initial scale certification target: 100,000 URLs.
- Local origin TTFB target: under 200 ms in controlled normal conditions.

## Dynamic content policy

Authenticated, private, preview, password-protected, personalized, cart, checkout, account, and session-sensitive responses are not published as static files. Exported forms and comments remain visually present only after an explicit warning that they will not function without a backend.
