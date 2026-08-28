# Reprint Server

Composer package for the Reprint streaming export engine — the HTTP endpoint
installed on the WordPress host that Reprint clients pull from and push to.

This package was previously published as `wp-php-toolkit/reprint-exporter`.
It replaces that package (`replace: self.version`), so the two names cannot be
installed side by side. Consumers should require
`wp-php-toolkit/reprint-server` directly.

## Loading it

Composer's classmap covers every class in `src/`, so classes resolve after
requiring `vendor/autoload.php`.

The namespaced helpers in `src/utils.php` are loaded lazily. A consumer that
calls one directly must require the file after Composer's autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/wp-php-toolkit/reprint-server/src/utils.php';

\WordPress\Reprint\Server\normalize_path('/srv/site/../site/wp-content');
```

This is a loading-contract change from releases that declared `utils.php` in
Composer's `autoload.files`: code that previously called a helper after only
requiring `vendor/autoload.php` must add the explicit `require_once` above.

`src/export.php` never autoloads, and that is deliberate. It declares functions
rather than classes, so the classmap scan finds nothing in it to register. Keep
it that way: requiring the file starts an output buffer and installs error,
exception and shutdown handlers, all of which belong at dispatch time.
`Site_Export_HTTP_Server::serve()` requires it at the right moment. Adding a
class to `export.php` would make it autoloadable and break that.

## Development

This repository is a read-only Composer package split from the Reprint monorepo. It is published so Composer can install `wp-php-toolkit/reprint-server` directly.

Do not propose changes in this package repository. Open issues and pull requests against the source repository instead:

https://github.com/WordPress/reprint

The package repository is overwritten from `packages/reprint-server` in the monorepo during releases, so direct changes here will be lost.
