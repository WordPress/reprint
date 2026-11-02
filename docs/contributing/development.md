# Set up Reprint development

Use this guide to run Reprint from a checkout, test a change, and prepare a pull
request. You need Git, command-line PHP, and Composer. Read the
[repository working rules](https://github.com/WordPress/reprint/blob/trunk/AGENTS.md) before changing code or documentation.

## Set up a checkout

Use PHP 8.1 or newer for the development tools and PHPUnit. Install Composer
and the PHP extensions required by
[composer.json](https://github.com/WordPress/reprint/blob/trunk/composer.json). Docker is needed for the end-to-end tests.

```sh
git clone --recurse-submodules https://github.com/WordPress/reprint.git
cd reprint
composer install
```

For an existing checkout, run `git submodule update --init --recursive` first.
Composer links the local client and server packages into `vendor/`, so root
tests use the code you edit under `packages/`. Check that the client runs with:

```sh
vendor/bin/reprint-client --version
```

The WordPress plugin keeps a copied server package instead. Before your first
plugin test, install its dependencies:

```sh
composer --working-dir=reprint-server-wp install
```

After changing server code under `packages/`, refresh that plugin copy:

```sh
composer --working-dir=reprint-server-wp reinstall wp-php-toolkit/reprint-server
```

Without that refresh, a test of the plugin bundle may run the old server code.

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
| `docs/contributing` | Contributor guides and protocol reference. The manifest selects which pages are published. |

The client and server are separate Composer packages,
`wp-php-toolkit/reprint-client` and `wp-php-toolkit/reprint-server`.
For embedding details, read the
[client package](https://github.com/WordPress/reprint/blob/trunk/packages/reprint-client/README.md)
and [server package](https://github.com/WordPress/reprint/blob/trunk/packages/reprint-server/README.md) guides.

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
PHPUnit suite. The [admin UI test guide](https://github.com/WordPress/reprint/blob/trunk/tests/e2e/admin-ui/README.md) and
[end-to-end test configuration](https://github.com/WordPress/reprint/blob/trunk/tests/e2e/package.json) cover the browser and
migration tests. Tests that use MySQL need the database described in
[CLAUDE.md](https://github.com/WordPress/reprint/blob/trunk/CLAUDE.md).

Use `composer lint:php` for WordPress coding standards and
`composer lint:php:compat` for the supported PHP syntax. Existing per-file
violation counts must not increase. New code must keep the package's minimum
PHP version; push's PHP 8.1 client requirement is a runtime check.

## Prepare a pull request

Choose one change that a reviewer can test on its own. Explain what a user or
caller could do before and what they can do after the change. Include steps
that reproduce the problem or demonstrate the new behavior.

Add a test for the behavior you changed. For a resume change, stop the operation
at the boundary you changed, start a new process, and check that it neither
skips unfinished work nor applies completed work twice. The
[architecture guide](https://developer.wordpress.org/reprint/contributing/architecture/) explains how files, indexes, and database
progress fit together.

Update the relevant handbook page when command options, results, or limits
change. Open the pull request in the
[development repository](https://github.com/WordPress/reprint).

## Change documentation

Edit the Markdown source under `docs/`. Use complete HTTPS URLs for every
link, including links to another handbook page. For a new published page, add
its title, slug, source file, parent, and order to `docs/manifest.json`, and link
it from the relevant section of the handbook.

Run `HandbookManifestTest.php` after changing links or page structure. It checks
that links name published pages, contributor pages are included, and every page
can be reached from the handbook home. For importer registration and deployment,
see the [publishing plan](https://github.com/WordPress/reprint/blob/trunk/docs/contributing/publishing.md).

## Prepare a release

Follow [Releasing Reprint](https://developer.wordpress.org/reprint/contributing/releases/) when publishing a stable version or
updating plugin-directory screenshots.
