<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/reprint-logo-dark.svg">
  <img src="docs/assets/reprint-logo.svg" alt="Reprint" width="250" height="75">
</picture>

# Reprint — WordPress Site Migration

Copy a WordPress site's files and database over HTTP. Reprint streams the data
without first building a large archive on the source host, and keeps state so
an interrupted transfer can continue.

## Start here

- **Try Reprint now:** [copy the demo to your computer](docs/handbook/try-reprint.md). No WordPress login needed.
- **Clone my site:** follow [Copy a site to your computer](docs/handbook/copy-a-site.md).
- **Integrate Reprint into my product:** start with [Build with Reprint](docs/handbook/build-with-reprint.md).

## Try it with the demo site

You need PHP with the extensions listed in
[Requirements and downloads](docs/handbook/requirements.md). The demo supplies
a shared access key, so you can copy it without connecting your own site:

```sh
mkdir try-reprint
cd try-reprint
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
curl -fL https://reprint-demo.wordpress.net/public.key -o demo.key
php reprint.phar pull https://reprint-demo.wordpress.net/ \
  --private-key-path=./demo.key \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --new-site-url=http://localhost:8881
```

When the local server starts, open http://localhost:8881. The
[Try Reprint tutorial](docs/handbook/try-reprint.md) covers checking the copy
and stopping and restarting the server.

To copy a site you administer, follow
[Copy a site to your computer](docs/handbook/copy-a-site.md). The WordPress.org
server package provides downloads only; it does not provide push.

## Documentation

The [Reprint handbook](docs/handbook/README.md) contains tutorials, task guides,
integration instructions, command reference, and explanations. Its Markdown
source and WordPress.org import manifest live in this repository.

- [Commands](docs/handbook/commands.md)
- [Troubleshooting](docs/handbook/troubleshooting.md)
- [Support forum](https://wordpress.org/support/plugin/reprint/)
- [Report a reproducible bug](https://github.com/WordPress/reprint/issues)

## Development

See [Contributing](docs/contributing/contributing.md) for development setup, architecture, tests,
and releases. Source packages live in
[`packages/reprint-client`](packages/reprint-client/) and
[`packages/reprint-server`](packages/reprint-server/).

Reprint is licensed under [GPL-2.0-or-later](LICENSE).
