# Reprint Server

Composer package for the Reprint streaming export engine — the HTTP endpoint
installed on the WordPress host that Reprint clients pull from and push to.

This package was previously published as `wp-php-toolkit/reprint-exporter`.
It replaces that package (`replace: self.version`), so the two names cannot be
installed side by side. Consumers should require
`wp-php-toolkit/reprint-server` directly.

## Development

This repository is a read-only Composer package split from the Reprint monorepo. It is published so Composer can install `wp-php-toolkit/reprint-server` directly.

Do not propose changes in this package repository. Open issues and pull requests against the source repository instead:

https://github.com/WordPress/reprint

The package repository is overwritten from `packages/reprint-server` in the monorepo during releases, so direct changes here will be lost.
