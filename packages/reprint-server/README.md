# Reprint Server

Composer package for the Reprint streaming export engine — the HTTP endpoint
installed on the WordPress host that Reprint clients pull from and push to.

This package was previously published as `wp-php-toolkit/reprint-exporter`.
It replaces that package (`replace: self.version`), so the two names cannot be
installed side by side. Consumers should require
`wp-php-toolkit/reprint-server` directly.

## Loading it

Composer's classmap covers every canonical class in `src/`, so classes
resolve after requiring `vendor/autoload.php`.

Reprint's entry points load their utility functions internally. `src/utils.php`
is an internal implementation file and is not a public autoload entry point.

The Reprint Server plugin loads `src/compat.php` when it opens the server
runtime so that released `Site_Export_*` server names continue to resolve
without adding work to every request which loads the shared Composer
autoloader.

Standalone consumers which request a released server name before its canonical
class must explicitly require `src/compat.php` after Composer's autoloader.

`src/export.php` never autoloads, and that is deliberate. It declares functions
rather than classes, so the classmap scan finds nothing in it to register. Keep
it that way: requiring the file starts an output buffer and installs error,
exception and shutdown handlers, all of which belong at dispatch time.
`WordPress\Reprint\Server\HTTPServer::serve()` requires it at the right moment.
Adding a class to `export.php` would make it autoloadable and break that.

Server classes use the `WordPress\Reprint\Server` namespace. The released
`Site_Export_*` server names remain autoloadable aliases. The global
`Site_Export_HMAC_Client` name and file remain unchanged because the Reprint
client package consumes that class directly.

## Development

This repository is a read-only Composer package split from the Reprint monorepo. It is published so Composer can install `wp-php-toolkit/reprint-server` directly.

Do not propose changes in this package repository. Open issues and pull requests against the source repository instead:

https://github.com/WordPress/reprint

The package repository is overwritten from `packages/reprint-server` in the monorepo during releases, so direct changes here will be lost.
