# Full database push

Suppose production has `wp_options` and `wp_orders`, but the local database
only has `wp_options`. `db-push` first loads a private incoming `wp_options`.
Production still serves its original tables. After explicit confirmation,
one `RENAME TABLE` moves both original tables aside and installs the incoming
`wp_options`. Production no longer has `wp_orders`.

This is a full overwrite, not a merge. Orders, accounts, settings, and other
rows missing locally are removed from the live site. That includes rows
excluded during pull and rows created on production while staging. There is
no full-database row comparison. Tables outside the configured WordPress
prefix are not selected. Prefix matching is case-sensitive. Every table inside
that prefix is selected; hosts must not share that prefix between independent
sites.

## Initial support

This first implementation is deliberately opt-in and limited:

- Local and hosted databases use MySQL 8.0+ or MariaDB 10.6.1+, with InnoDB
  base tables. Both machines need `pdo_mysql`. The client needs PHP 8.1+ for
  streamed uploads; the server needs PHP 7.2+.
- The local and hosted table prefixes are identical. Multisite is rejected.
- Between 1 and 256 source tables, 128 columns per table, and 1 MiB of values per row, before
  and after URL rewriting. The client asks MySQL to withhold larger rows and
  rejects them before upload. Archive records are limited to 2 MiB.
- Identifiers contain only ASCII letters, digits, and underscores. Foreign
  keys, triggers, named constraints, generated or spatial columns,
  partitions, external storage, events, and routines are not supported.
  The DDL check is conservative: a reserved schema keyword inside a comment
  or default value can also cause rejection.
- SQLite sources, table-prefix conversion, automatic writer shutdown, cache
  clearing, health checks, and automatic rollback are not implemented.

The host must keep a standalone Reprint API route and its authentication
available after `wp_options` is replaced. A normal active plugin and its
option-backed connection token do not meet this requirement: the incoming
database may deactivate the plugin or replace the token.

## Host setup

The host supplies an API entry point which does **not** boot WordPress. It
must remain reachable while public requests are stopped. Its configuration
and token live outside the document root. For example:

```php
<?php
// This private file defines ABSPATH, DB_HOST, DB_NAME, DB_USER, DB_PASSWORD,
// and sets $GLOBALS['table_prefix'] to the one site's table prefix.
require '/srv/private/reprint-database-config.php';

$plugin_directory = '/srv/site/wp-content/plugins/reprint-server/';
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', $plugin_directory);
define('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE', '/srv/private/reprint-token.php');
define('REPRINT_SERVER_PUSH_ENABLED', true);
require $plugin_directory . 'vendor/autoload.php';
require $plugin_directory . 'lib.php';

\WordPress\Reprint\Server\Plugin\handle_api_request([
    'docroot' => '/srv/site',
    'reprint_directory' => '/srv/private/reprint',
    'database_push' => true,
]);
```

The token file returns the shared secret as a PHP string. This is host-level
permission for destructive pushes, not a setting to expose to visitors.
Requests still require the existing signed push authentication and HTTPS.
`database_push` defaults to disabled. Setting it on a normal WordPress route
is insufficient: the host must actually provide the independent route.

The database account needs metadata visibility for dependencies, as well as
SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, and the privileges needed
by `RENAME TABLE` and advisory locks. Do not enable this for a shared schema
whose dependencies the account cannot inspect.

## Command flow

Use a private local state directory and the same remote Reprint API URL for
every step. These examples use explicit source credentials; omitting them
uses the local MySQL database previously recorded by `db-apply`.

```sh
reprint db-push https://example.com/reprint-api.php \
  --state-dir=/private/deploy-42 --secret=TOKEN \
  --source-dsn='mysql:host=127.0.0.1;dbname=local_site;charset=utf8mb4' \
  --source-user=local_user --source-pass=LOCAL_PASSWORD \
  --table-prefix=wp_ \
  --rewrite-url https://local.test https://example.com
```

The result contains `incoming_tables`, `replace_tables`, and a `review` token.
No live table has changed. Check the complete replacement list. Then stop and
drain **all** web requests, cron, queues, CLI commands, and schema migrations
which may use these tables. WordPress's `.maintenance` file alone is not enough.

```sh
reprint db-push https://example.com/reprint-api.php \
  --state-dir=/private/deploy-42 --secret=TOKEN \
  --commit=REVIEW_TOKEN --writers-stopped
```

`--writers-stopped` is the operator's confirmation, not an automatic check.
A changed production table list invalidates the review token. Re-run staging
with the original source settings to get the new list and token. Changes to
production **rows** do not invalidate it: full overwrite explicitly discards
those changes.

After commit, keep writers stopped. Clear persistent object caches and any
other host/application caches, verify the incoming site and its accounts,
then reopen it. Old tables remain under the private names reported in
`old_tables`. Do not switch back after reopening without accounting for new
writes; that would be another destructive overwrite.

Once satisfied, explicitly delete the retained old tables and hosted archive:

```sh
reprint db-push https://example.com/reprint-api.php \
  --state-dir=/private/deploy-42 --secret=TOKEN --cleanup
```

Before commit, `--abort` instead discards incoming tables and the hosted
archive. It never undoes a committed overwrite. Cleanup/discard remove one
table per call and then use bounded archive removal. A small terminal progress
table remains so repeated requests can report the result. The local archive
also remains; remove the private local state directory when no longer needed.
Use a new state directory for the next deployment.

## Preparation and recovery

The client takes one consistent InnoDB read snapshot and writes a private
archive. It rewrites each complete value locally, including serialized PHP
lengths and structured WordPress content, before base64 encoding it. Binary
columns are copied unchanged. Rewriting a primary key is rejected. Source
rows are never updated. Keep source **DDL** unchanged during preparation.
If preparation is interrupted, a new run starts a fresh snapshot; an open
MySQL transaction cannot survive process death. A sealed archive is reused
without scanning the source again.

Upload reuses the existing multipart sender and private work store, with many
parts per request. Database archives use a separate private store and cannot
be published by a file-push commit. Resume asks the target for its confirmed
byte offset. A failed request ends the current run; no automatic retry occurs.

Each import step applies one table definition, one row, or the end record.
The row and its archive byte offset commit in the same InnoDB transaction.
Private table creation and removal can be replayed after interruption. The
server needs no URL rewriter, HTML processor, SQLite translator, or SQL dump
parser: it reads bounded records, creates incoming tables, and binds row data.

Commit includes the progress table in the same multi-table rename as the
site tables. Its new name identifies a completed swap even if the client
never received the reply. The implementation requires the engine/version
combination with atomic DDL support described by
[MySQL](https://dev.mysql.com/doc/refman/8.0/en/atomic-ddl.html) and
[MariaDB](https://mariadb.com/docs/server/reference/sql-statements/data-definition/rename-table).
Keep a separate backup: retained tables on the same server are not disaster
recovery.

## Tests

The focused tests run against real MySQL/MariaDB and the production authenticated
HTTP dispatcher. They cover staging without live changes, cancellation,
process death with an open request after a confirmed request, a discarded
commit response, stale review tokens, failed unique-value imports, discard,
cleanup, client-side serialization rewriting, row-size rejection, exact
binary/decimal/BIT/NULL values, and zero auto-increment IDs. They do not
simulate database-server power loss or claim automatic writer draining.

Selective changes remain separate future work in
[issue #827](https://github.com/WordPress/reprint/issues/827).
