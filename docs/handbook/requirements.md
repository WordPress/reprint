# Requirements and downloads

For a first local copy, you need a terminal, command-line PHP, enough disk
space, and administrator access to the WordPress site you want to copy.

## Check your computer

The client requires PHP 7.4 or newer. Use a terminal on macOS or Linux, or WSL
on Windows. The generated local-server start script uses Bash; you do not need
to change your interactive shell.

```sh
php --version
php -m
```

The first-copy tutorial needs `curl`, `hash`, `json`, `mbstring`, `openssl`,
`PDO`, `pdo_sqlite`, and `zlib`. Allow space for the downloaded files, SQL dump,
and local database. Your PHP version and extensions must also meet the copied
site's own needs.

If PHP is missing, install it with your operating system's package manager or
use the PHP supplied by your local WordPress environment. In WSL, install PHP
inside WSL. A missing extension must be enabled for that command-line PHP.
See [Troubleshooting](troubleshooting.md) if a check fails.

## Download the client

[Download reprint.phar from WordPress.org](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar),
or run this in your working folder:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
```

You should see a Reprint version number. The download URL always provides the
latest stable client. You do not need Git or Composer to run it.

## Check the WordPress site

You need permission to install and configure the
[Reprint plugin](https://wordpress.org/plugins/reprint/). It requires WordPress
4.7 or newer, PHP 7.2 or newer, and a working HTTPS address. On multisite, a
network administrator must install and configure it. Source-host SSH is not needed.

The WordPress.org package provides downloads, not push. For host-specific
extension and database checks, see [Prepare a hosting destination](hosting.md).

## Choose a destination

The [first-copy tutorial](copy-a-site.md) uses SQLite and PHP's local server,
so no local MySQL server is needed. Some plugins need a MySQL destination;
see [Database transfer reference](database-transfers.md) when choosing one.
PHP's built-in server is for local development, not a public production site.

Next: [Copy a site to your computer](copy-a-site.md).
