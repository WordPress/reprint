# Local database baseline and diff

Suppose post 42 had the title `Summer sale` after pull. You change it to
`Autumn sale` locally. `db-diff` shows the old and new title. It does not
include the unchanged post body. Running it again shows the same change;
reviewing changes does not accept them as a new baseline.

These commands prepare a local diff for selective push. They do not
contact production, check production conflicts, or apply changes anywhere.

## Capture, edit, compare

Finish pulling and preparing the local site, including URL rewriting. Then
capture a baseline **before making edits**:

```sh
reprint db-baseline https://example.com/?reprint-api \
  --state-dir=/private/reprint-state \
  --source-dsn='mysql:host=127.0.0.1;dbname=local_wordpress;charset=utf8mb4' \
  --source-user=local_user --source-pass=local_password \
  --table=wp_posts --table=wp_postmeta --table=plugin_settings
```

The table names are exact and repeatable. Non-prefixed plugin tables work.
Every selected table needs a primary key. Tables not selected at capture
stay outside this diff. Without source connection options, the commands use
the local MySQL target recorded by `db-apply`.

After editing, use the same remote Reprint API URL, state directory, and
source DSN:

```sh
reprint db-diff https://example.com/?reprint-api \
  --state-dir=/private/reprint-state \
  --source-dsn='mysql:host=127.0.0.1;dbname=local_wordpress;charset=utf8mb4' \
  --source-user=local_user --source-pass=local_password
```

The output is JSONL. Each `database_change` record has a table, an action
(`insert`, `update`, or `delete`), a primary key, and `before`/`after` values.
Keys and column values are base64 strings; SQL NULL remains JSON null.
Updates include only changed columns. Inserts have `before: null`; deletes
have `after: null`. Serialized PHP and JSON values remain whole columns.
SET values use numeric masks. ENUM values carry `index:label`, so an invalid
index zero is distinct from a declared empty label. Spatial values retain
their local MySQL bytes. This is a review format, not executable SQL.

For example, decoding an update gives:

```text
table: wp_posts     key: ID=42
post_title: "Summer sale" -> "Autumn sale"
```

If an order was omitted before baseline capture, there is no old local row
to delete. The command cannot know whether that order still exists on
production. A row inserted locally can also have an ID already used on
production. Neither case permits a blind upsert or delete there.

## How the snapshot works

The client locks the selected local tables for reading in one `LOCK TABLES`
statement. Local writes to those tables wait until scanning finishes. This
works with InnoDB and MyISAM and requires SELECT and LOCK TABLES privileges.
Triggers and foreign-key relationships may cause MySQL to lock related
tables too. No database tables, triggers, or row data are created or changed.

The shared pull reader scans through primary-key cursors with unbuffered
results. Complete old rows go into local row files. A separate index stores
each key, row fingerprint, and byte offset. Fingerprints use `xxh128` when
available, or `sha256` otherwise. The chosen algorithm stays in the manifest.
A runtime missing the saved algorithm must fail instead of changing it.

The client releases the database locks before sorting the compact indexes
on disk. Sorting encoded keys avoids assuming that PHP and MySQL collations
order strings alike. Only a complete snapshot is renamed into
`<remote-state-directory>/database-baseline/`. Interrupted scans restart;
they never become baselines. The CLI holds the existing Reprint process lock.

A diff scans the selected local tables again and merges the two sorted
indexes. It reads full before/after values only for changed rows. It leaves
the retained baseline untouched and removes its temporary snapshot.

## Current limits

Capture is explicit, not part of pull yet. Do not pull again into this local
database and treat the resulting diff as your own edits. Start a new baseline
in a separate state directory after completing that pull. There is no reset
or baseline-advance command in this first version.

Only MySQL sources are supported. Missing tables and changes to the column
metadata or primary key stop the diff. This does not report schema changes,
new tables, triggers, or dependencies between rows. Primary-key edits appear
as a deletion and an insertion, not a rename. A different DSN spelling is
rejected even if it reaches the same database.

Every diff reads all selected local rows and writes a temporary snapshot.
Plan for the retained rows, another full set of rows, both indexes, and sort
files on disk. Base64 makes row values roughly one third larger. Memory grows
with the largest row, column metadata, and the sort working set; oversized
individual rows are not split. Each scan must finish in one PHP process.

Keep the state directory outside the web root. Baseline directories use
0700 and files use 0600, but the values are not encrypted. Diff output also
contains private data. Check the exit status before consuming a diff. With JSONL reporting, the
final command report must also say `complete`; a partial output stream is
not a successful diff. Fingerprints are change detectors, not
proof against deliberate hash collisions.

Production conflict checks, user approval, atomic application of selected
changes, and advancing only approved baseline rows belong in later PRs.

For an explicitly selected push of these records, see [Push selected database changes](DATABASE-CHANGES-PUSH.md). The baseline itself still does not advance.
