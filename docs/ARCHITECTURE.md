# Architecture

## Packages

- Core owns capture, eligibility, storage, invalidation, queueing, publication, local serving, REST, CLI, and extension contracts.
- Export owns portability, rewriting, package manifests, ZIP generation, and commercial entitlement integration.
- Future Agency and Multisite add-ons consume the same public contracts.

## Publication flow

1. WordPress content or configuration changes enqueue affected URLs.
2. A worker claims jobs with per-URL locks and adaptive batches.
3. The renderer fetches the anonymous public response with a signed internal-render header.
4. Eligibility rejects private, personalized, erroneous, or unsafe responses.
5. Storage writes to a temporary file, hashes it, and atomically replaces the live artifact.
6. The previous artifact is retained according to the configured policy.

WP-Cron is a compatibility fallback. Production sites should configure a real scheduler or invoke WP-CLI. Direct web-server delivery is enabled only through reviewed server rules; PHP fallback remains available.

## Storage

Default root: `wp-content/uploads/static-wp-publisher/<site-id>/`

Builds, published artifacts, versions, temporary files, and manifests are isolated. Temporary artifacts are denied public execution and use canonicalized allowlisted paths.

## Security boundaries

- Only anonymous GET responses are eligible.
- Cookies, bodies, authorization headers, nonces, and private response headers are never persisted.
- Renderer requests are host-restricted and signed to avoid cache recursion.
- Export paths reject traversal, absolute paths, symlink escapes, duplicate normalized names, and case collisions.
- Commercial licensing is isolated behind an adapter and cannot disable Core.
