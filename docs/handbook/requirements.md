# Requirements and downloads

To clone your site, you need a terminal, command-line PHP, enough disk space, and administrator access to the WordPress site you want to copy.

## Check the destination computer

The client requires PHP 7.4 or newer. Use a terminal on macOS or Linux, or WSL on Windows. Run these checks on the computer receiving the copy. The generated development-server start script uses Bash; you do not need to change your interactive shell.

Native Windows PHP can run the step-by-step pull commands: `pull-files`, `pull-db` into SQLite, `merge-wp-content`, `flat-docroot`, and `apply-runtime`. Push and the Bash start script need WSL, macOS, or Linux. Windows lets only some accounts create symlinks, so links can arrive in another form. See [Links on a Windows destination](https://developer.wordpress.org/reprint/reference/file-transfers/#links-on-a-windows-destination).

```sh
php --version
php -m
```

The copy tutorial needs `curl`, `hash`, `json`, `mbstring`, `openssl`, `PDO`, and `zlib`. For SQLite, also enable `pdo_sqlite`. For MySQL, enable `mysqli` and have a reachable MySQL server and an empty destination database ready. Allow space for the downloaded files, SQL dump, and local database. Your PHP version and extensions must also meet the copied site's own needs.

If PHP is missing, install it with your operating system's package manager or use the PHP supplied by your local WordPress environment. In WSL, install PHP inside WSL. A missing extension must be enabled for that command-line PHP. See [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/) if a check fails.

## Download the client

[Download reprint.phar from WordPress.org](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar), or run this in your working folder:

```sh
curl -fL https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar -o reprint.phar
php reprint.phar --version
```

You should see a Reprint version number. The download URL always provides the latest stable client. You do not need Git or Composer to run it.

## Check the WordPress site

You need permission to install and configure the [Reprint plugin](https://wordpress.org/plugins/reprint/). It requires WordPress 4.7 or newer, PHP 5.6.20 or newer, and a working HTTPS address. On multisite, a network administrator must install and configure it. Source-host SSH is not needed.

If preflight fails on the source, the host can use the [source host checks](#source-host-checks) below.

## Choose a destination

The [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/) guide offers SQLite and MySQL, using PHP's development server to preview the copied site. You can run it on your laptop or a remote server. SQLite needs no separate database server. Choose MySQL if a plugin requires it or you want to use your existing database server. See [Database transfer reference](https://developer.wordpress.org/reprint/reference/database-transfers/) for other import options. PHP's built-in server is for local development, not a public production site.

## Source host checks

These checks are for the hosting provider when the source cannot complete preflight or supply its data.

The host needs JSON and hash support. PDO with its MySQL driver is recommended; the plugin can use WordPress's database connection when they are unavailable. Servers with OpenSSL use public/private-key authorization through Migration Tokens. Servers without it use shared connection tokens. Use a working HTTPS address for the source site.

The client sends POST requests with custom authentication headers to the site's Reprint API address. Security layers in front of the site must let those requests through: a Cloudflare browser challenge, a Wordfence request limit, or a proxy that strips custom headers stops the copy. See [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/#a-security-service-blocks-the-client).

MySQL 5.5 sources are supported. This does not imply MySQL 5.5 destination support: the destination must accept the exported schema and collations. See [Database transfer reference](https://developer.wordpress.org/reprint/reference/database-transfers/) for import behavior.

### Multisite sources

For selected-site exports, MySQL sources need `pdo_mysql`, a direct database connection, and SELECT, CREATE, DROP, INSERT, and UPDATE privileges. SQLite sources need a persistent database, a writable database directory, and working filesystem locks. These allow Reprint to save its user list and resume exports.

### For hosting providers

A host can supply client keys or a connection token in files beside the plugin, replace the built-in authentication, and embed the server in its own platform. The [plugin configuration guide](https://github.com/WordPress/reprint/blob/trunk/reprint-server-wp/README.md) covers `public-keys.php`, `secret.php`, the `reprint_server_api_options` filter, and library use.

Next: [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/).
