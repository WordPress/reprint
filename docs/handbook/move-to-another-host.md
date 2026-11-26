# Move to another host

Use Reprint to prepare files and a database on a destination server. This guide assumes you can run command-line PHP there, configure its web server, and create an empty database. Source WordPress administrator access is enough to install the plugin and authorize the client; source SSH is not required.

For a first experiment, complete [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/). For a managed product, see [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).

## 1. Prepare the destination

Install the [client and required PHP extensions](https://developer.wordpress.org/reprint/getting-started/requirements/). Create a dedicated empty MySQL database and an account limited to it. Choose empty download and assembled-site directories. Keep migration state outside web-served directories.

Install Reprint on the source, then generate a key in a private destination directory:

```sh
php reprint.phar keygen https://source.example \
  --state-dir=/srv/reprint-state --out=/srv/reprint-key.pem
```

[Authorize the printed Migration Token](https://developer.wordpress.org/reprint/getting-started/authorize-client/) on the source. If it uses a connection token, replace the private-key flag below with `--secret='TOKEN'` using the saved token.

## 2. Copy into the destination database

Replace the addresses, paths, database name, and credentials:

```sh
php reprint.phar pull https://source.example \
  --private-key-path=/srv/reprint-key.pem \
  --state-dir=/srv/reprint-state --fs-root=/srv/reprint-files \
  --flatten-to=/srv/site \
  --target-engine=mysql --target-host=127.0.0.1 \
  --target-user=site_user --target-pass=YOUR_DATABASE_PASSWORD \
  --target-db=site_database --new-site-url=https://destination.example \
  --runtime=none --start-runtime=none
```

After the command finishes:

- `/srv/reprint-files` contains the downloaded files.
- `/srv/site` links to those files and gives your web server one folder to serve. Keep both folders. Deleting `/srv/reprint-files` would break the copy.
- `site_database` contains the imported database.

`--runtime=none --start-runtime=none` skip the development-server configuration and do not start a server, since your own web server will serve the copy. See [runtime options on pull](https://developer.wordpress.org/reprint/reference/runtime/#runtime-options-on-pull).

Next, configure your web server to serve `/srv/site` and check that WordPress works. Once the copy is ready, point your domain to the destination server. The steps below cover that setup.

Run the same command to continue after interruption. See [Database transfer reference](https://developer.wordpress.org/reprint/reference/database-transfers/) for target compatibility and import recovery limits.

## 3. Configure and test WordPress

Configure the destination web server and `wp-config.php` for the destination paths and database. Do not reuse source-host database credentials. [Prepare a hosting destination](https://developer.wordpress.org/reprint/build-with-reprint/hosting/) covers cleanup and startup checks; [Runtime configuration](https://developer.wordpress.org/reprint/reference/runtime/) covers generated server configuration.

With the default linked layout created above, `/srv/site/wp-load.php` and `/srv/site/wp-config.php` are symlinks pointing into `/srv/reprint-files`. Because PHP resolves `wp-load.php` through its symlink to the pulled core directory inside `/srv/reprint-files`, WordPress looks for `wp-config.php` next to that target (or one directory above it) rather than in `/srv/site`. If an editor or deployment step replaces `/srv/site/wp-config.php` with a regular file while core files remain linked, WordPress continues loading the untouched `wp-config.php` inside `/srv/reprint-files`.

Choose one of two ways to configure `wp-config.php`:

- **Keep linked core (default):** Edit the `wp-config.php` file that the `/srv/site/wp-config.php` symlink resolves to inside `/srv/reprint-files`, and keep the symlink in `/srv/site` intact rather than replacing it with a regular file.
- **Write a regular `wp-config.php` in `/srv/site`:** Add `--core-files=copy` to the `pull` command (or run `flat-docroot` with `--core-files=copy` and `--flatten-to=/srv/site`) so WordPress core—including `index.php` and `wp-load.php`—is copied as real files in `/srv/site`. Because `--core-files=copy` still creates `wp-config.php` as a symlink alongside `wp-content` and other non-core site files, remove `/srv/site/wp-config.php` first before writing a regular `wp-config.php` file there. Running `pull` or `flat-docroot` again against `/srv/site` will stop on that regular `wp-config.php` unless `--force` is passed (which replaces it with a symlink again).

Before opening the copy, disable outgoing mail, scheduled jobs, payments, and other production service connections that must not run from a second site. These settings are specific to your site and are not all handled by Reprint.

Test pages, assets, administrator login, forms, and the plugins your site needs. Use a host preview or local DNS override to test the destination before changing public DNS. A successful transfer alone does not confirm that every service works.

## 4. Switch traffic

A live transfer is not one coordinated snapshot of every file and database row. Plan a write pause and final copy for new posts, uploads, orders, and accounts created while the first copy ran. A repeated database import can replace destination changes; do not treat it as a merge.

After checking the final copy, change the domain routing using your host's normal process. Keep the original site available for your rollback plan until you have checked the destination. Reprint does not perform this traffic switch.
