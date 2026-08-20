# Reprint Server

Composer package for the Reprint streaming export engine — the HTTP endpoint
installed on the WordPress host that Reprint clients pull from and push to.

This package was previously published as `wp-php-toolkit/reprint-exporter`.
It replaces that package (`replace: self.version`), so the two names cannot be
installed side by side. Consumers should require
`wp-php-toolkit/reprint-server` directly.

## Loading it

The package autoloads normally. Composer's classmap covers every class in
`src/`, and the `files` entry loads `src/utils.php`, the PDO polyfill, and the
legacy-class compatibility loader.
Install it and the classes resolve — there is nothing to call.

`src/export.php` never autoloads, and that is deliberate. It declares functions
rather than classes, so the classmap scan finds nothing in it to register. Keep
it that way: requiring the file starts an output buffer and installs error,
exception and shutdown handlers, all of which belong at dispatch time.
`WordPress\Reprint\Server\HTTPServer::serve()` requires it at the right moment. Adding a
class to `export.php` would make it autoloadable and break that.

Server classes use the `WordPress\Reprint\Server` namespace. The released
`Site_Export_*` server names remain autoloadable aliases. The global
`Site_Export_HMAC_Client` name and file remain unchanged because the Reprint
client package consumes that class directly.

One thing not to change without reading it first: the `PDO`, `PDOStatement` and
`PDOException` polyfills in `src/class-pdo-polyfill.php` are declared inside an
`eval()`. That keeps them out of the generated classmap, which for a consumer
like Jetpack is shared by every plugin on the site. The header comment on that
file explains it, and `tests/PdoPolyfillTest.php` fails the build if the
declarations ever move somewhere a scanner can see them.

## Development

This repository is a read-only Composer package split from the Reprint monorepo. It is published so Composer can install `wp-php-toolkit/reprint-server` directly.

Do not propose changes in this package repository. Open issues and pull requests against the source repository instead:

https://github.com/WordPress/reprint

The package repository is overwritten from `packages/reprint-server` in the monorepo during releases, so direct changes here will be lost.
