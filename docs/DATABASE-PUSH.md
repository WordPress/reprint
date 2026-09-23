# Full database push

Suppose production has `wp_options` and `wp_orders`, but the local database
only has `wp_options`. `db-push` first loads a private incoming `wp_options`.
Production still serves its original tables. After explicit confirmation,
one `RENAME TABLE` moves both original tables aside and installs the incoming
`wp_options`. Production no longer has `wp_orders`.

This is a full overwrite, not a merge. Orders, accounts, settings, and other
rows missing locally are removed from the live site. That includes rows
excluded during pull and rows created on production while staging. There is
no full-database row comparison. Every table inside the configured WordPress prefix is selected. Prefix matching
is case-sensitive; hosts must not share that prefix between independent sites.

A plugin can also create a table such as `plugin_orders`, without `wp_`. Include
it explicitly with `--include-table=plugin_orders`; repeat the option for each
extra table. These names are exact, not patterns. Push cannot tell whether an
unprefixed table belongs to this site, so it never selects one automatically.
The source must still contain tables with the configured WordPress prefix.
An explicitly included table must exist locally. Its production copy is replaced
in full and appears in the review. Other tables outside the prefix stay alone.
Reprint's internal progress tables cannot be included.

Triggers are not moved. Every table review warns that the new live tables
will have no triggers after commit. Source triggers are left alone. Existing
target triggers stay attached to the retained old tables and are deleted when
cleanup drops those tables. Triggers on tables outside the selected tables
are left alone. Staging or aborting a push does not remove live triggers.

## Initial support

This first implementation is deliberately opt-in and limited:

- The hosted database requires MySQL 8.0+ or MariaDB 10.6.1+ and InnoDB
  tables for the crash-safe swap. This requirement does not apply to the
  local source: MySQL 5.5 sources and SQLite databases created by the bundled
  WordPress SQLite integration are tested.
- MySQL connections use `pdo_mysql` when available, otherwise `mysqli`.
  The hosted endpoint does not need PDO. The client still needs PDO core;
  SQLite sources also need `pdo_sqlite`. The client needs PHP 8.1+ for
  streamed uploads; the server source package needs PHP 7.2+.
- Source MyISAM tables become InnoDB on the target. Definitions must be valid for InnoDB. In particular, MyISAM
  per-group AUTO_INCREMENT keys without an index led by the auto-number
  column are not converted. Such a definition fails staging without changing
  live tables.
- The local and hosted table prefixes are identical. Explicit extra names are
  also identical on both sides. Multisite is rejected.
- Between 1 and 256 source tables, including explicit extras; 128 columns per table; and 1 MiB of values per row, before
  and after URL rewriting. The client asks MySQL to withhold larger rows and
  rejects them without changing live tables. Stream records are limited to 2 MiB.
- Table and column identifiers contain only ASCII letters, digits, and
  underscores. Foreign keys between selected tables, CHECK constraints,
  generated columns, spatial columns/indexes, and partitions are supported.
  Constraint names become bounded, push-specific names so incoming and live
  tables can coexist. Their definitions and enforcement settings are preserved.
- Source storage placement (`DATA DIRECTORY`, `INDEX DIRECTORY`, `TABLESPACE`,
  and `CONNECTION`) is omitted by the client. The target chooses its own
  storage. References to tables outside the push remain unsupported. Source
  routines and events are not exported; targets containing routines or events
  are rejected. Collations are preserved, so the target must support them.
  For example, MariaDB 10.6 rejects the `utf8mb4_0900_ai_ci` collation used by
  the SQLite integration by default; staging fails without changing live tables.
- Table-prefix conversion, automatic writer shutdown, cache
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

The server currently trusts DDL prepared by the authenticated client. It does
not parse SQL or guarantee that arbitrary client SQL stays within the selected
tables. A server-side parser remains a TODO; a keyword blacklist is not a SQL
validator. Enable this route only for trusted deployment clients, not as a
restricted SQL API for untrusted callers.

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
  --table-prefix=wp_ --include-table=plugin_orders \
  --rewrite-url https://local.test https://example.com
```

Omit `--include-table` when the prefix covers all site tables. Keep the same
extra table list when resuming staging. Commit, cleanup, and abort use the saved
selection; they do not need source credentials or the extra table options.

The result contains `incoming_tables`, `replace_tables`, `warnings`, and a
`review` token. No live table has changed. Check the complete replacement list
and the trigger warning. Then stop and drain **all** web requests, cron,
queues, CLI commands, and schema migrations
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

Once satisfied, explicitly delete the retained old tables:

```sh
reprint db-push https://example.com/reprint-api.php \
  --state-dir=/private/deploy-42 --secret=TOKEN --cleanup
```

Before commit, `--abort` instead discards incoming tables. It never undoes a
committed overwrite. Cleanup/discard remove one table per call. A small terminal
progress table remains so repeated requests can report the result. Remove the
private local state directory when no longer needed. Use a new state directory
for the next deployment.

A recorded SQLite `db-apply` target is selected automatically. To use another
WordPress SQLite database, supply
`--source-dsn='mysql-on-sqlite:path=/absolute/path/database.sqlite;dbname=wordpress'`.
Double any semicolon inside a DSN value. MySQL credentials are not used for SQLite.

## Streaming and recovery

For example, if row 10 changes locally after the target has copied it, the
incoming table keeps the copied value. Rows read later may contain newer values.
Push uses pull's row reader and its weaker consistency guarantee, without a
transaction or table lock covering the whole push. Keep source data still if you
need all copied tables to describe one moment. Keep source tables and columns
unchanged until staging finishes.

The client reads rows in primary-key order. After interruption it continues
after the last key committed by the target. Tables without a primary key use
OFFSET instead; inserts or deletes during the push can make that position skip
or repeat rows. There is no change log or reconciliation pass.

The client rewrites each complete value before sending it, including serialized
PHP lengths and structured WordPress content. Binary columns are copied unchanged.
ENUM index zero is distinct from a declared empty label or the label `0`.
Restoring that legacy value accepts only the server warnings naming its columns;
other warnings roll the row back. Rewriting a primary key is rejected. Source
rows are never updated. Pull and push share numeric reads: native floating-point
values become 17-digit round-trip decimals before PHP string conversion or cursor
storage. MySQL SET values travel as unsigned masks, preserving empty members and
all 64 bits. When pulling into SQLite, the client converts those masks back to
labels using the column definition. SQLite stores labels rather than masks; it
cannot retain the distinction between an empty member and no member. Exact SET
mask round-trips require MySQL or MariaDB.
A saved MySQL cursor containing SET labels from an older server cannot resume
with mask-based reads; abort that transfer and start again after upgrading.

Spatial type detection is shared, but the formats remain different. Push uses
the source engine's WKB conversion plus SRID; pull retains its raw spatial bytes,
large-value streaming, and cross-engine axis-order guard. Sharing the spatial
format would require changing the SQL dump and importer together, not just
moving the push expression into a helper.

MySQL source connections use UTC so TIMESTAMP values
keep their meaning on the target. SQLite sources are opened read-only, using the
integration's stored MySQL schema. Plain SQLite databases and integration
metadata requiring an upgrade are not supported.

There is no full local or hosted archive. Many bounded multipart parts travel
in one request through the existing streaming sender. A record can span requests;
the target keeps only that unfinished record in its progress table. A running
client retains its encoded record between successful requests. A new client
process instead reads the target-confirmed source cursor and restarts the
unfinished record at byte zero, because its local row may have changed. A failed
request ends the current run; no automatic retry occurs.

The client parses each `SHOW CREATE TABLE` result with its existing SQL parser.
It preserves expressions, quoted names, comments, defaults, indexes, and
partitioning while removing source storage placement and selecting InnoDB for
the incoming tables. It gives CHECK and
foreign key constraints new names that do not collide with the live schema.
Generated columns are omitted from row inserts so the target computes them
from rewritten input values. Spatial values travel as WKB bytes plus their
SRID, without URL rewriting.

After all rows are sent, the client revisits each table definition and sends
its deferred foreign key clauses. It does not scan completed rows again. References point to incoming tables, not live tables.
Each `ALTER TABLE ADD CONSTRAINT` validates the imported rows with foreign key
checks enabled, including cycles and self-references. A failed validation
leaves production unchanged and prevents the ready state. Validation can take
longer than a host request allows on large tables; a later request checks
whether the constraint exists before replaying an unconfirmed ALTER.

Each import step applies one table definition, one row, one foreign key, or
the end record. The row and its source cursor commit in the same InnoDB
transaction. Database push requests share one lock per target database, because
explicit extra tables can overlap selections with different prefixes. Private table creation and removal can be replayed after
interruption. Cleanup and discard disable foreign key checks only around each
private-table DROP so cycles do not prevent removal. Foreign keys from tables
outside the selected site are rejected before staging and again before commit.
The server needs no URL rewriter, HTML processor, SQLite translator, or SQL
parser: it applies client-prepared DDL and binds row data.

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
process death with an open request after a confirmed request, discarded upload and
commit responses, stale review tokens, failed unique-value imports, discard,
cleanup, client-side serialization rewriting, row-size rejection, exact
binary/decimal/BIT/NULL values, and zero auto-increment IDs. Compatibility
cases run with a PDO-free endpoint, a client without `pdo_mysql`, MySQL 5.5
and MyISAM sources, and SQLite ENUM labels, BIT numbers, and binary values. Schema tests cover
keyword-like literals, generated values, spatial bytes and SRIDs, partitions,
source storage placement, cyclic/self-referencing foreign keys, constraint
validation failures, long names, and replay after an ALTER commits but its
progress write times out, source edits between runs, raw composite-key cursors,
and a row rollback when its progress write fails. They do not simulate database-server power loss or claim automatic writer draining.

Trigger tests use the real CLI and HTTP endpoint. They cover source triggers,
target triggers, both, and neither; the warning also appears on a resumed
review. They check that incoming tables have no triggers, old target triggers
remain until cleanup, abort leaves live triggers working, and source triggers
and triggers outside the selected prefix are unchanged.

The WordPress E2E suite shares pull datasets for SQL edge values, structured
URL rewriting, binary/composite keys, legacy ENUM values, 200 × 80 KiB payloads,
and a 50,050-row version of the pull batch-boundary fixture. It compares every
site schema and every complete row against pull/apply, checks source and live
tables before commit, and verifies home-page rendering and admin authentication
after commit and cleanup. Builder markup is checked against explicit expected
values and rendered through WordPress. The pull suite’s two known unsupported
encoded-URL cases remain limitations of the shared rewriter. A separate 13 MiB
row check verifies
safe rejection at the current row limit.

Selective changes remain separate future work in
[issue #827](https://github.com/WordPress/reprint/issues/827).
