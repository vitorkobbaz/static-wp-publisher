# Contributing

## Workflow

1. Branch from `main` using a short-lived branch.
2. Keep Core and paid add-on code in their respective packages.
3. Never commit credentials, customer sites, production databases, or commercial SDK secrets.
4. Run `composer check`, `npm run lint:js`, and relevant end-to-end tests.
5. Open a pull request with behavior changes, test evidence, risks, and pending work.

## Local environment

Install Docker Desktop, PHP 8.3+, Composer, Node.js, and npm. Then run:

```bash
composer install
npm install
npm run env:start
composer check
```

The E2E test instance uses host port 80 so WordPress can perform a real self-request from its web container. Ensure that port is available before `npm run test:e2e`; the current smoke suite does not support overriding `WP_ENV_TESTS_PORT`.

E2E fixtures live in the test-only `tests/e2e/fixtures/swpp-e2e-support` plugin, loaded only in the wp-env `tests` environment. Its `wp swpp-e2e seed-v1` command rebuilds the original schema v1 tables with legacy data so `tests/e2e/specs/schema-upgrade.spec.ts` can exercise the real in-place upgrade on the next web request. When the schema changes again, add the previous DDL as a new seed instead of editing the v1 one.

WPML and Freemius jobs are skipped unless their protected CI secrets are available.

## Real-site fixtures

Real sites may be used only in isolated private environments. They must be anonymized by default. Credentials, tokens, customer records, orders, messages, and other personal data must never enter Git or public CI.
