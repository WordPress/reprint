<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/WordPress/reprint/trunk/docs/assets/reprint-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/WordPress/reprint/trunk/docs/assets/reprint-logo.svg" alt="Reprint" width="250" height="75">
</picture>

# Reprint — WordPress Site Migration

Copy a WordPress site's files and database over HTTP. Reprint streams the data
without first building a large archive on the source host, and keeps state so
an interrupted transfer can continue.

## Start here

- **View the demo site:** explore the [demo site](https://reprint-demo.wordpress.net/).
- **Clone my site:** follow [Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/).
- **Integrate Reprint into my product:** start with [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).

## Quick start

Install the [Reprint plugin](https://wordpress.org/plugins/reprint/) on the site
you want to copy. Download the client to the computer receiving the copy:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
php reprint.phar pull https://example.com \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

The first pull prints a Migration Token and stops. Enroll it under
**Tools → Reprint Server** on the source site, then run the same command again.
The [tutorial](https://developer.wordpress.org/reprint/copy-a-site/) covers the complete setup and checks.

The client needs PHP 7.4 or newer and the extensions listed in
[Requirements and downloads](https://developer.wordpress.org/reprint/requirements/). The WordPress.org
server package provides downloads only; it does not provide push.

## Documentation

The [Reprint handbook](https://developer.wordpress.org/reprint/) contains tutorials, task guides,
integration instructions, command reference, and explanations. Its Markdown
source and WordPress.org import manifest live in this repository.

- [Commands](https://developer.wordpress.org/reprint/commands/)
- [Troubleshooting](https://developer.wordpress.org/reprint/troubleshooting/)
- [Support forum](https://wordpress.org/support/plugin/reprint/)
- [Report a reproducible bug](https://github.com/WordPress/reprint/issues)

## Development

See [Contributing](https://developer.wordpress.org/reprint/contributing/) for development setup, architecture, tests,
and releases. Source packages live in
[`packages/reprint-client`](https://github.com/WordPress/reprint/tree/trunk/packages/reprint-client) and
[`packages/reprint-server`](https://github.com/WordPress/reprint/tree/trunk/packages/reprint-server).

Reprint is licensed under [GPL-2.0-or-later](https://github.com/WordPress/reprint/blob/trunk/LICENSE).
