# Requirements and downloads

To clone your site, you need a terminal, command-line PHP, enough disk
space, and administrator access to the WordPress site you want to copy.

## Check the destination computer

The client requires PHP 7.4 or newer. Use a terminal on macOS or Linux, or WSL
on Windows. Run these checks on the computer receiving the copy. The generated
development-server start script uses Bash; you do not need to change your
interactive shell.

```sh
php --version
php -m
```

The copy tutorial needs `curl`, `hash`, `json`, `mbstring`, `openssl`, `PDO`,
and `zlib`. For SQLite, also enable `pdo_sqlite`. For MySQL, enable `mysqli`
and have a reachable MySQL server and an empty destination database ready. Allow
space for the downloaded files, SQL dump, and local database. Your PHP version
and extensions must also meet the copied site's own needs.

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

The [Clone your site](copy-a-site.md) guide offers SQLite and MySQL, using PHP's
development server to preview the copied site. You can run it on your laptop
or a remote server. SQLite needs no separate database server. Choose MySQL if
a plugin requires it or you want to use your existing database server.
See [Database transfer reference](database-transfers.md) for other import options.
PHP's built-in server is for local development, not a public production site.

Next: [Clone your site](copy-a-site.md).
