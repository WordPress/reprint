# Requirements and downloads

Reprint runs in two places. Check the WordPress host and the receiving
computer separately.

## On the WordPress site

The [WordPress.org Reprint plugin](https://wordpress.org/plugins/reprint/)
requires WordPress 4.7 or newer and PHP 7.2 or newer. Prefer maintained
WordPress and PHP versions when your host supports them.

You need administrator access to install and configure it. On multisite,
you need a network administrator. No source-host SSH access is needed for the
plugin-based transfer.

The host needs JSON and hash support. PDO with its MySQL driver is recommended;
the plugin can use WordPress's database connection when they are unavailable.
OpenSSL enables Migration Tokens; hosts without it use connection tokens.
Use a working HTTPS address for the source site.

The WordPress.org package supports downloads only. It does not include file
push or database push. This handbook's first-copy tutorial uses that package.

## On your computer

The client requires PHP 7.4 or newer. The local-copy tutorial also needs:

- A Bash shell, such as macOS Terminal, a Linux terminal, or WSL on Windows.
- PHP extensions `curl`, `hash`, `json`, `mbstring`, `openssl`, `PDO`,
  `pdo_sqlite`, and `zlib`. OpenSSL is needed for the tutorial's Migration
  Token steps.
- Enough free disk space for the downloaded files, SQL dump, and local database.
- A PHP version and extensions that also meet the copied site's own needs.

Check the PHP used by your terminal:

```bash
php --version
php -m
```

If `php` is missing, install command-line PHP using your operating system's
package manager, or use the PHP binary supplied by your local WordPress
development environment. In WSL, install and run PHP inside WSL. Ask whoever
manages your environment if you cannot add PHP extensions.

SQLite is convenient for a first copy. Some plugins depend on MySQL-specific
behavior and need a MySQL destination instead. PHP's built-in server is for
local development; do not expose it as a production server.

## Download the client

Get the latest stable versions from WordPress.org:

- [Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar).
  This URL stays the same when a new stable client is released.
- [Install the Reprint plugin](https://wordpress.org/plugins/reprint/).
  The plugin directory has the current ZIP and installation details.

Save `reprint.phar` in your working folder. It is a PHP archive containing the
client and its dependencies; you do not need Git or Composer to run it.
To download it from your terminal and check that it runs:

```bash
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
```

Next: [Copy a site to your computer](../copy-a-site/), or
[authorize another tool](../authorize-a-tool/).
