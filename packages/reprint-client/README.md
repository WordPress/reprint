# Reprint Client

Composer package for the Reprint CLI — the client that drives pull and push
operations against a site running the Reprint Server plugin.

This package was previously published as `wp-php-toolkit/reprint-importer`.
It replaces that package (`replace: self.version`), so the two names cannot be
installed side by side. Consumers should require
`wp-php-toolkit/reprint-client` directly. The package publishes the
`reprint-client` binary plus a `reprint-importer` compatibility wrapper.

## Development

This repository is a read-only Composer package split from the Reprint monorepo. It is published so Composer can install `wp-php-toolkit/reprint-client` directly.

Do not propose changes in this package repository. Open issues and pull requests against the source repository instead:

https://github.com/WordPress/reprint

The package repository is overwritten from `packages/reprint-client` in the monorepo during releases, so direct changes here will be lost.
