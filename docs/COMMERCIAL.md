# Commercial model

## Free plugin (WordPress.org)

Static WP Publisher is distributed through WordPress.org and is complete on its own:

- Unlimited local static publication with automatic refresh after content changes.
- Speed optimizations, dashboard, REST API, and WP-CLI.
- Manual portable exports (directory and ZIP) with no page, build, or export quota.

WordPress.org guideline 5 forbids trialware: the free plugin may not contain features locked behind payment, and it may not stop working after a quota. The free plugin therefore never limits pages, builds, or exports, and contains no licensing code. Paid functionality lives only in a separately distributed add-on.

The free plugin may mention the Pro add-on in its readme and in one dismissible panel on the export screen. It never shows promotional notices elsewhere in wp-admin (guideline 11).

## Pro add-on (Static WP Publisher Pro)

Pro adds automation on top of the free exports:

- Automatic deployment to SFTP, S3-compatible storage (AWS S3, Cloudflare R2, Backblaze B2), and Netlify.
- Incremental deployment: only pages and assets that changed are sent after each publication.
- Server profiles with redirect and real-404 configuration files.
- Priority support.

Later Pro additions: GitHub and Cloudflare Pages targets, static forms and search, Multisite, and agency white-label reports.

## Pricing

Annual subscriptions, in USD:

| Plan | Sites | Price |
| --- | --- | --- |
| Personal | 1 | 79 / year |
| Business | 5 | 149 / year |
| Agency | 25 | 299 / year |

- A 14-day free trial needs no payment card. When it ends without a purchase, Pro stops deploying automatically. The free plugin and every export already made keep working.
- Updates and support end with the subscription. The installed Pro version keeps working.
- Reference: Simply Static Pro charges USD 99 per site per year for a comparable feature set; the free Simply Static plugin includes unlimited ZIP exports.

## Merchant and licensing

Freemius is the merchant of record and licensing provider (checkout, taxes, licenses, trials, and Pro updates). Only the Pro add-on contains the Freemius SDK. The free plugin never depends on Freemius.

## Before selling

Commercial terms, privacy, trial, refund, and trademark use require legal review before sales open.
