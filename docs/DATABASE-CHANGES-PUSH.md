# Push selected database changes

Suppose the local `wp_posts` row 42 changes its title from `Draft` to `Ready`.
Production adds row 90 and changes row 42's comment count. Select the title
change: push checks that the production title is still `Draft`, changes it to
`Ready`, and leaves row 90 and the comment count alone. If the production title
is already different, the entire push rolls back.

This command builds on [local database baselines](DATABASE-BASELINE.md).
Full overwrite remains a separate `db-push` command.

## Review, select, confirm

Capture the baseline after preparing the local site, before making edits. Use
exact table names, including plugin tables outside the WordPress prefix.

```sh
reprint db-baseline https://live.example/reprint-api \
  --state-dir=/private/reprint --table=wp_posts --table=plugin_data \
  --source-dsn='mysql:host=localhost;dbname=local' --source-user=local

# After local edits:
reprint db-diff https://live.example/reprint-api \
  --state-dir=/private/reprint --source-dsn='mysql:host=localhost;dbname=local' \
  --source-user=local --progress=jsonl > changes.jsonl

# Review changes.jsonl. Remove whole records you do not want to push.
# Values are base64 strings; SQL NULL is JSON null. Keep the file private.
reprint db-push-changes https://live.example/reprint-api \
  --state-dir=/private/reprint --changes=changes.jsonl \
  --rewrite-url https://local.example https://live.example

# Repeat the same command with the printed token and the connection secret:
reprint db-push-changes https://live.example/reprint-api \
  --state-dir=/private/reprint --changes=changes.jsonl \
  --rewrite-url https://local.example https://live.example \
  --secret=TOKEN --commit=REVIEW
```

Without `--commit`, this reads the selected file, rewrites its values, and
prints per-table insert/update/delete counts and a review token. It makes no
network requests. The token covers the selected records, their table metadata,
and the rewritten values. Changing any of those requires another review.
A successful final `db-diff` command report is accepted and omitted from the
push. Other command reports, malformed records, and incomplete lines fail.
A filtered selection containing only change records is also accepted.

The file determines the selection. This command does not silently generate a
new diff or include changes made after the file was saved. Keep or remove whole
rows; column-level selection is not a separate UI yet.

## Conflict rules

Each row carries an explicit `insert`, `update`, or `delete` action.

An **insert** requires the primary key to be absent. The record contains the
whole new row. A duplicate primary or unique key is a conflict; push never
turns an insert into an update.

An **update** requires the primary key to exist and the old values of the
selected columns to match byte-for-byte. It changes only those columns.
Production edits to other columns remain intact. An existing row whose update
changes nothing is still an update, not a missing-row error.

A **delete** requires the primary key to exist and the entire old row to match.
It cannot delete an order that was omitted before baseline capture: that order
has no local baseline row from which to produce a deletion.

The server locks a row before checking it and keeps the lock until commit.
Constraint errors, invalid values, changed column layouts, and generated values
which do not match the requested result also stop the push. The response names
the first conflict's table, stream record number, and failed condition. It does
not reconcile values or produce a second database-sized diff.

Resolve a conflict locally or remove that record, save a new selection, and
review again. Do not change `before` values unless you have inspected production
and deliberately want to replace those values. The baseline is a local snapshot,
not a claim that those old values ever existed on production.

## One request, one transaction

The client streams bounded multipart parts through the existing push transport.
The target applies completed records inside one MySQL transaction. It commits
only after receiving the end record, the multipart closing boundary, and a
matching review hash. Any earlier failure rolls back all live row changes.
Killing the PHP process also rolls back its open transaction.

The commit writes a small InnoDB receipt in `__reprint_db_change_receipts` in the
same transaction as the selected changes. After a lost response, repeat the
**same** reviewed command. It checks the receipt before sending rows again.
The same reviewed selection is applied at most once while its receipt exists.
Keep this internal table when maintaining the database. There is no automatic
receipt cleanup in this version. Creating the receipt table happens before the
row transaction; a failed push can leave that empty internal table behind.

There is no automatic retry and no mid-transaction resume. A failed upload must
start over. The command never splits a selection into several commits to fit a
request limit. Select a smaller batch if the target rejects the request size.
After a response failure, check the same review's receipt before editing the
selection: a response can be lost after a successful commit.

URL rewriting remains entirely in the client, including `before` values used
for conflict checks. It uses the existing structured-data rewriter; binary
columns do not enter it. URL rewrites that would change primary keys fail.
Target reads share their numeric and byte encoding with the baseline reader,
including promoted FLOAT values, unsigned SET masks, and ENUM indexes.

## Requirements and current limits

The target needs the existing host-configured standalone push route; normal
WordPress requests and multisite are not supported. The client uses the existing
PHP 8.1+ streaming transport. The target uses PDO MySQL or the existing mysqli
fallback. No SQL parser or change-tracking triggers are installed on the target.

Selected target tables must be InnoDB base tables with primary keys and matching
column metadata. This is stricter than full overwrite because MyISAM writes
cannot roll back. Tables with triggers are refused: a trigger could write to a
nontransactional table. Incoming cascading foreign keys are refused because they
could change rows outside the selection. Ordinary restrictive foreign keys stay
enabled. Record order must satisfy them; there is no dependency sorter or ID
remapping. A primary-key edit appears as a delete and an insert.

The dedicated target account needs its normal table read/write privileges,
CREATE for the receipt table, a direct TRIGGER grant for selected tables, and a
**direct global REFERENCES grant**. MySQL can hide triggers without TRIGGER
privilege. It can also hide an incoming foreign key in another database when
only this site's database is visible. The global REFERENCES grant makes those
constraints visible without granting read access to their rows. Role-only grants
are not accepted by this first implementation. Configure this account through
the host; the command never grants itself privileges.

Spatial writes and writing legacy ENUM index zero are not supported yet. A
changed ordinary column in a row with unchanged spatial data can still be pushed.
Records are capped at 2 MiB, including base64 and JSON. Production rows above
2 MiB of decoded column values cannot be conflict-checked. The entire multipart
request must fit the target's request limit and the shared client's request
budget (initially at most 32 MiB). Slow uploads can hold production row locks;
host request timeouts and MySQL deadlocks abort the transaction. InnoDB's
AUTO_INCREMENT counter can advance even when row changes roll back.

The **local baseline does not advance** after push yet. Later diffs still include
these local edits. Repeating the same review checks its receipt, but combining
already-pushed edits with new edits produces a new review and can conflict.
For now, remove already-pushed records from the next selection. Advancing only
confirmed selected rows in the local baseline is the next step.

SQL writes do not call WordPress/plugin hooks, send emails, update search
services, or flush persistent object caches. Clear affected caches and verify
the site after commit. The transaction covers database rows, not those outside
systems or the site's files.
