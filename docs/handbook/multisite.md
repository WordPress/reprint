# Move one site out of a network

Use this guide to copy one multisite subsite into a single-site installation.
It does not copy the whole network or create a new network.

## 1. Select the site and authorize the client

You need a network administrator to install and configure Reprint. Use the
selected site's home address, such as https://network.example/shop/, rather
than the network home. Generate a key for the copy:

```sh
php reprint.phar keygen 'https://network.example/shop/?reprint-api' \
  --state-dir=./state --out=./reprint-key.pem
```

[Authorize the printed Migration Token](https://developer.wordpress.org/reprint/authorize-client/) in Network Admin.
If the settings ask for a connection token instead, skip key generation and
replace the private-key flag below with `--secret='NETWORK_TOKEN'` using the
saved token.

## 2. Copy into an empty destination

Create the destination directories and an empty MySQL or SQLite target. Supply
`--new-site-url` and `--site-admin=LOGIN`; LOGIN must identify a user included
in the selected site's export. The example prepares files and SQLite data for
https://shop.example without starting a development server
(`--runtime=none --start-runtime=none`; see
[runtime options on pull](https://developer.wordpress.org/reprint/runtime/#runtime-options-on-pull)):

```sh
php reprint.phar pull 'https://network.example/shop/?reprint-api' \
  --private-key-path=./reprint-key.pem \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite --target-sqlite-path=/srv/shop/database.sqlite \
  --target-db=shop --new-site-url=https://shop.example --site-admin=alice \
  --runtime=none --start-runtime=none
```

## 3. Configure and check the destination

Configure the destination web server and database connection as described in
[Move to another host](https://developer.wordpress.org/reprint/move-to-another-host/) or use the
[runtime configuration commands](https://developer.wordpress.org/reprint/runtime/). Check the imported administrator,
posts, media, theme, plugins, and site-specific services before switching traffic.
Read the plugin-table and separate-work sections below before treating the
copy as complete.

## What moves

| Included | Needs separate work |
| --- | --- |
| The selected site's posts, comments, media, theme, and plugin files. | Content belonging to other sites in the network. |
| Site members and users referenced by core content, with core profile fields and roles. | Non-core user metadata and users referenced only by plugin tables. |
| The site's options and supported network settings. | Unknown network settings and shared plugin configuration. |
| Numbered site-local plugin tables, such as `network_7_wc_orders`. | Unnumbered plugin tables that may contain shared network data. |

Post, attachment, and user IDs stay the same. The target keeps the selected
site's table prefix; Reprint prepares the shared user-table configuration for
single-site WordPress. Existing non-main-site media paths keep their
`wp-content/uploads/sites/ID` layout, including for new uploads.

Network-active plugins become active on the copied site. Reprint itself stays
inactive. Host plugins are downloaded by default; see
[host-plugin selection](https://developer.wordpress.org/reprint/file-transfers/) if the destination does not need them.
Sessions and WordPress application passwords are not copied.

Links to the selected site and its media are rewritten to the new address.
Links to other network sites keep pointing to the source. Unsupported plugin
fields can retain old URLs, so check those separately. A plugin that stores
site IDs or depends on multisite APIs may need its own migration steps.

## Before switching traffic

- Check the imported administrator, members, media, and plugin features.
- Reconfigure plugins that need shared settings, non-core user metadata, or
  external services. Copying WooCommerce tables alone does not establish that
  every store feature and customer profile works.
- Review plugins that store private data beside their shared code. Shared
  plugin directories are copied in full.
- Pause writes for the final copy if new orders, accounts, or uploads must not
  be missed. The source is not a single frozen snapshot.

Reprint does not delete the source site or change DNS.

## Supported layouts and databases

The destination must be an empty single-site database. Merging into an existing
database and pushing into a multisite network are not supported. The new site
address must be an HTTP(S) origin without a path, query, or fragment.

Legacy `blogs.dir` uploads, custom upload or content directories, shared custom
source user tables, and symlinks in selected paths are rejected. Cache and
database drop-ins are not copied. Unnumbered plugin tables can stop the export
because Reprint cannot assume that their data belongs to the selected site.

Sources can use MySQL or SQLite Database Integration 2.x/3.x. The destination
must support the source's collations; for example, an older MariaDB server may
reject a MySQL 8 collation. Use `pull` or `pull-db` rather than direct
`db-pull --sql-output=mysql` for a selected multisite site.

## If export setup or resuming fails

A selected-site export uses a helper table on the source to collect users.
Core WordPress tables and indexes are unchanged. If setup fails, ask the host
to check the [multisite source requirements](https://developer.wordpress.org/reprint/requirements/#multisite-sources).

Only one export can run for a selected source site at a time. Another site in
the network can export separately. Starting a fresh export prevents the older
export from resuming. Keep the same target, URL replacements, and administrator
while continuing an unfinished import.
Leave Reprint's lock and state files in place.

Large networks can require substantial memory even when copying one site.
Try a selected-site migration before planning a large rollout; a successful
small-site copy does not establish capacity for the whole network.
