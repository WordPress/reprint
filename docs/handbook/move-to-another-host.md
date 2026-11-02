# Move to another host

Use Reprint to prepare files and a database on a destination server. This
guide assumes you can run command-line PHP there, configure its web server,
and create an empty database. Source WordPress administrator access is enough
to install the plugin and authorize the client; source SSH is not required.

For a first experiment, complete [Copy a site to your computer](https://developer.wordpress.org/reprint/copy-a-site/).
For a managed product, see [Build with Reprint](https://developer.wordpress.org/reprint/build-with-reprint/).

## 1. Prepare the destination

Install the [client and required PHP extensions](https://developer.wordpress.org/reprint/requirements/). Create a
dedicated empty MySQL database and an account limited to it. Choose empty
download and assembled-site directories. Keep migration state outside web-served
directories.

Install Reprint on the source and [authorize the client](https://developer.wordpress.org/reprint/authorize-client/).

## 2. Copy into the destination database

Replace the addresses, paths, database name, and credentials:

```sh
php reprint.phar pull https://source.example \
  --state-dir=/srv/reprint-state --fs-root=/srv/reprint-files \
  --flatten-to=/srv/site \
  --target-engine=mysql --target-host=127.0.0.1 \
  --target-user=site_user --target-pass=YOUR_DATABASE_PASSWORD \
  --target-db=site_database --new-site-url=https://destination.example \
  --runtime=none --start-runtime=none
```

The assembled directory uses symlinks to the downloaded files; retain both
directories. This command imports source data into the destination database.
It does not configure a production web server or move DNS.

Run the same command to continue after interruption. See
[Database transfer reference](https://developer.wordpress.org/reprint/database-transfers/) for target compatibility
and import recovery limits.

## 3. Configure and test WordPress

Configure the destination web server and `wp-config.php` for the destination
paths and database. Do not reuse source-host database credentials.
[Prepare a hosting destination](https://developer.wordpress.org/reprint/hosting/) covers cleanup and startup checks;
[Runtime configuration](https://developer.wordpress.org/reprint/runtime/) covers generated server configuration.

Before opening the copy, disable outgoing mail, scheduled jobs, payments, and
other production service connections that must not run from a second site.
These settings are specific to your site and are not all handled by Reprint.

Test pages, assets, administrator login, forms, and the plugins your site needs.
Use a host preview or local DNS override to test the destination before changing
public DNS. A successful transfer alone does not confirm that every service works.

## 4. Switch traffic

A live transfer is not one coordinated snapshot of every file and database row.
Plan a write pause and final copy for new posts, uploads, orders, and accounts
created while the first copy ran. A repeated database import can replace
destination changes; do not treat it as a merge.

After checking the final copy, change the domain routing using your host's
normal process. Keep the original site available for your rollback plan until
you have checked the destination. Reprint does not perform this traffic switch.
