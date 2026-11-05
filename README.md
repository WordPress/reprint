<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/reprint-logo-dark.svg">
  <img src="docs/assets/reprint-logo.svg" alt="Reprint" width="250" height="75">
</picture>

# Reprint — WordPress Site Migration

Copy a WordPress site's files and database over HTTP. Reprint streams the data
without first building a large archive on the source host, and keeps state so
an interrupted transfer can continue.

## Start here

- [Try Reprint now](docs/handbook/try-reprint.md)
- [Clone your site](docs/handbook/copy-a-site.md)
- [Integrate Reprint into your product](docs/handbook/build-with-reprint.md)

## Try it with the demo site

You need PHP with the extensions listed in
[Requirements and downloads](docs/handbook/requirements.md). You can copy the demo without
an account, a key download, or a site of your own:

```sh
mkdir try-reprint
cd try-reprint
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar pull https://reprint-demo.wordpress.net \
  --state-dir=./state --fs-root=./files \
  --port=8881 --new-site-url=http://localhost:8881 \
  --secret=public-demo
```

`public-demo` is a placeholder for the CLI. The demo server ignores it.

When the local server starts, open http://localhost:8881.
[Check, stop, or reopen your copy](docs/handbook/try-reprint.md).

## Documentation

- [Handbook](docs/handbook/README.md)
- [What Reprint does not do](docs/handbook/limitations.md)
- [Commands](docs/handbook/commands.md)
- [Troubleshooting](docs/handbook/troubleshooting.md)
- [Support forum](https://wordpress.org/support/plugin/reprint/)
- [Report a reproducible bug](https://github.com/WordPress/reprint/issues)

## Development

- [Contribute to Reprint](docs/contributing/contributing.md)
- [Client source](packages/reprint-client/)
- [Server source](packages/reprint-server/)

Reprint is licensed under [GPL-2.0-or-later](LICENSE).
