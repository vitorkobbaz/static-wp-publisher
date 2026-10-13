# Static WP Publisher

Static WP Publisher generates and serves public WordPress pages as static HTML while keeping WordPress as the editorial source.

This repository is temporarily public while the project is under active development. It is a monorepo containing both the free Core plugin and the separately distributed commercial add-on:

- `plugins/static-wp-publisher`: the free WordPress.org Core plugin.
- `plugins/static-wp-publisher-export`: the portable-export add-on. Manual exports are moving into the free plugin; this package becomes the paid Pro add-on (automatic deployment). See `docs/COMMERCIAL.md`.
- `docs`: product, architecture, operations, and roadmap documentation.
- `tests`: cross-package unit tests.

The first public milestone is the free plugin on WordPress.org (local static publication and manual exports), followed by the Pro add-on with automatic deployment.

## Development status

Early MVP. Do not install on production sites yet.

Implemented scope and production-release gaps are tracked in [docs/PENDING_FEATURES.md](docs/PENDING_FEATURES.md).

## Requirements

- WordPress 6.5 or newer
- PHP 8.3 or newer
- MySQL 8.0+ or MariaDB 10.11+
- HTTPS

## Local development

The repository uses Composer, npm, `@wordpress/env`, PHPUnit, Jest, Playwright, PHPStan, PHPCS, and GitHub Actions. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

The PHP code is licensed under GPL-2.0-or-later. The Static WP Publisher name and brand assets are not licensed for misleading use as an official distribution. See [LICENSE](LICENSE).

Copyright (c) Kobbaz Estudio LTDA.
