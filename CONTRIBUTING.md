# Contributing to Reprint

For using Reprint or adding it to a product, start with the
[handbook](docs/handbook/README.md). This page is for changes to Reprint itself.
Read [AGENTS.md](AGENTS.md) before changing code or documentation.

## Set up a checkout

Use PHP 7.4 or newer, Composer, and the PHP extensions required by
[composer.json](composer.json). Docker is needed for the end-to-end tests.

```sh
git clone --recurse-submodules https://github.com/WordPress/reprint.git
cd reprint
composer install
```

For an existing checkout, run `git submodule update --init --recursive` first.
Composer links the local client and server packages into the root vendor tree.
The bundled plugin has its own physical vendor copy; refresh it before testing
plugin changes, as described in [AGENTS.md](AGENTS.md).

## Find the code

| Path | Purpose |
| --- | --- |
| `packages/reprint-client` | Client package, CLI, pull workflows, and local runtime setup. |
| `packages/reprint-server` | Server API, database export, and file transfer implementation. |
| `reprint-server-wp` | WordPress plugin, settings UI, and self-contained distribution. |
| `tests` | PHPUnit tests and static-analysis support. |
| `tests/e2e` | Docker and Playground end-to-end tests. |
| `bin` | Build and version-stamping tools. |
| `lib/sqlite-database-integration` | SQLite integration submodule and SQL parser. |
| `docs/handbook` | Public documentation, listed in `docs/manifest.json`. |
| `docs/contributing` | Architecture, protocol, and maintenance notes; not imported as handbook pages. |

The client and server are separate Composer packages,
`wp-php-toolkit/reprint-client` and `wp-php-toolkit/reprint-server`.
Their old names, `reprint-importer` and `reprint-exporter`, cannot be installed
alongside their replacements. See the package READMEs for embedding details.

## Test a change

Run focused tests from the test directory:

```sh
cd tests
../vendor/bin/phpunit HandbookManifestTest.php
cd ..
composer analyze -- --memory-limit=1G
git diff --check
```

Use the test file for the behavior you changed. Run `composer test` for the
PHPUnit suite. The [admin UI test guide](tests/e2e/admin-ui/README.md) and
[end-to-end test configuration](tests/e2e/package.json) cover the browser and
migration tests. Tests that use MySQL need the database described in
[CLAUDE.md](CLAUDE.md).

Use `composer lint:php` for WordPress coding standards and
`composer lint:php:compat` for the supported PHP syntax. Existing per-file
violation counts must not increase. New code must keep the package's minimum
PHP version; push's PHP 8.1 client requirement is a runtime check.

## Read before changing a subsystem

- [Architecture and durable state](docs/contributing/architecture.md)
- [Pull HTTP protocol](docs/contributing/pull-http.md)
- [File push implementation](docs/contributing/push.md)
- [Database push implementation](docs/contributing/database-push.md)
- [Push terminology](docs/contributing/push-terminology.md)

Read [How Reprint works](docs/handbook/how-it-works.md) for the larger design
choices and their tradeoffs. Keep local implementation reasons with the code
and regression tests. Future work belongs in issues and PRs; a past bug's test
matrix does not need a second maintained document.

## Documentation and releases

The [publishing plan](docs/contributing/publishing.md) defines audiences, page
types, the proposed developer.wordpress.org location, and the import steps.
Public pages use ordinary Markdown and published relative links; do not add a
conversion or link-rewriting build.

See [Releasing Reprint](docs/contributing/releases.md) for release workflows,
WordPress.org downloads, and plugin-directory asset updates.
