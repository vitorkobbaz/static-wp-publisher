# Pending functionality

This document separates the implemented Core + Individual Export MVP from work still required before a production commercial release.

## Release blockers

- Expand the real WordPress Playwright suite (wp-env). Covered today: activation and schema, publication, refresh, anonymous serving, bypass cookies, authenticated ZIP generation/download/manifest checks, and the in-place schema v1 → v2 upgrade (legacy jobs, builds, artifacts, and settings preserved; duplicate active jobs retired; expired v1 lease recovered; idempotent re-run). Still pending, in order:
  1. WP-Cron execution, retries with backoff, expired leases, and concurrent workers.
  2. Unpublishing and deletion, confirming static artifacts are removed.
  3. Exporting, downloading, and extracting the ZIP into a temporary directory.
  4. Serving the extracted package from an independent HTTP server.
  5. Playwright crawl of the extracted package: HTTP status, internal navigation, images/`srcset`/CSS/fonts/backgrounds, `noindex` in relocatable mode, manifest checksums, absence of PHP/`wp-config`/`.env`/traversal/sensitive files, and zero requests to the original WordPress.
  6. The same flow for a domain/subdirectory-targeted publishable export.
- Add reproducible performance and scale benchmarks, including the 100,000-URL inventory target and static TTFB below 200 ms under documented conditions.
- Complete Freemius production onboarding: product identifiers, signed SDK bootstrap, seven-day trial, 72/24-hour reminders, 30-day entitlement cache, update delivery, renewal, and expiration scenarios.
- Complete legal review for commercial terms, privacy, trial/no-refund language, trademark use, and the dedicated security contact.
- Add release packaging, signing/checksums, translation catalogs, and the WordPress.org submission pipeline for Core.

## Core follow-up

- Handle nonces embedded in public HTML. Static pages keep the anonymous nonces that themes/plugins print (for example `ElementorProFrontendConfig.nonce`), which WordPress invalidates after 12–24 hours; AJAX features and forms that verify them may start failing. Needed: detection in Eligibility/diagnostics, periodic regeneration of affected pages within the nonce lifetime, and an E2E check with Elementor-style config.
- Translate the administration screen (pt_BR first) once the release translation pipeline exists.
- Show archive/term pages as rows of the dashboard list with a type filter. Today they appear only under "Other addresses" when their latest generation failed.
- Persist a URL index for the dashboard so tabs, counters, and search scale past the 2,000-item analysis window (`swpp_dashboard_item_limit`) toward the 100,000-URL target.
- Per-page "Always serve with WordPress" exclusion (row and bulk action). This is useful for pages whose forms depend on expiring nonces.

- Enforce the free-tier limit of 10 published pages. The monetization model defines it, but the current `Inventory`/`Eligibility` code does not apply any page cap yet.

- Add reviewed Apache, Nginx, and IIS direct-file profiles plus configuration validation. The implemented PHP fallback is safe but still boots WordPress.
- Expose retained versions through an administrator rollback UI, REST operation, and WP-CLI command. Atomic version snapshots already exist as the underlying primitive.
- Add queue throughput profiles and runtime telemetry for very large sites; the current conservative worker is safe but intentionally slow.
- Add structured logs, retention controls, health diagnostics, and downloadable support reports.
- Expand dependency invalidation for theme-specific archives, widgets, global blocks, and plugin-defined routes.
- Certify supported combinations of WooCommerce, WPML, Polylang, and TranslatePress.

- Remaining PageSpeed items outside the safe optimizer:
  - critical CSS inlining and deferring jQuery-dependent scripts;
  - delaying third-party tags (Tag Manager/analytics);
  - responsive `sizes` for images displayed smaller than their source;
  - long cache lifetimes for versioned assets through web-server rules.
- Sweep combined-CSS bundles that no static copy references any more. They are content-addressed and only accumulate when CSS changes.
- Promote "Combine CSS files" from experimental after real-site feedback, possibly with an automated before/after visual comparison in the admin.

## Individual Export follow-up

- Add incremental/differential package generation; the MVP currently produces complete directory and ZIP exports.
- Add selectable Apache, Nginx, IIS, and generic-static-host deployment profiles, including redirect and real-404 configuration files.
- Add streaming/split archive strategies and disk-space estimation for very large packages.
- Add a commercial update channel and customer-facing license/trial screens after Freemius credentials are available.

## Later add-ons

- Agency add-on: portfolio operations, reusable profiles, white-label reports, and agency licensing.
- Multisite add-on: network policies, per-site queues, domain mapping, and network-aware CLI.

Cloud hosting, remote deployment, remote verification, SEO analysis, and LLM-discovery functionality remain outside the approved scope.
