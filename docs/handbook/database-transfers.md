# Database transfer reference

Examples use `$URL` for the authorized source address, `$STATE_DIR` for its
persistent state directory, and `$FS_ROOT` for downloaded files. Set these in
your shell first. Keep the state directory outside the filesystem root.
See [authorization](../authorize-client/) before running remote commands.

## Download SQL

By default, this streams a SQL dump into `$STATE_DIR/db.sql`:

```bash
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT"
```

You can also pipe the SQL directly to stdout or stream it into a MySQL server
without writing a file to disk. Use `--sql-output` to choose the mode:

```bash
# Pipe to stdout — useful for feeding into mysql CLI or another tool
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --sql-output=stdout | mysql --binary-mode --local-infile=0 -u import_user -p my_database

# Stream directly into MySQL — no intermediate file, no pipe
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --sql-output=mysql --mysql-database=my_database --mysql-host=127.0.0.1 --mysql-user=import_user --mysql-password=secret
```

For a pipe into the `mysql` CLI, keep `--binary-mode` and `--local-infile=0`.
The first disables local client commands such as `source` and `system` while
reading piped input; the second disables `LOAD DATA LOCAL INFILE`. These flags
do not filter SQL statements. The receiving SQL client is responsible for these protections.

Use a database account limited to the target database rather than a server
administrator account. Both the CLI pipe and `--sql-output=mysql` execute SQL
from the source with the permissions of the supplied account. `stdout` writes
the dump unchanged, so the program reading it must choose how to process it.

The three modes:

| Mode | What happens | Output file |
|------|-------------|-------------|
| `file` (default) | Writes SQL to `$STATE_DIR/db.sql` | `db.sql` |
| `stdout` | Streams SQL to stdout, progress/status goes to stderr | none |
| `mysql` | Connects through mysqli and executes complete statements one at a time | none |

## Continue an interrupted database import

When a source response stops mid-stream, the same importer asks for the
remaining SQL and keeps an unfinished statement in memory. Each decoded SQL
multipart body is at most 16 MiB. Each INSERT statement is also at most 16 MiB.
When a batch contains complete statements followed by an unfinished statement,
the source sends the complete prefix first so the importer can save its cursor.
That split can leave at most 16 MiB after the checkpoint, and finishing the
current bounded INSERT can add at most another 16 MiB. The next complete
checkpoint is therefore at most 32 MiB away. A `db.sql` group adds one
separator newline before its cursor marker.

Direct MySQL output stores the last committed group cursor in the target table
`__reprint_db_pull_progress_<uuid>`. The UUID makes an accidental name clash
unlikely and changes whenever the table schema changes. Reprint logs and excludes
that internal name from the source dump.

File output keeps the same SQL groups by adding a harmless comment after each
complete group. The comment records the exporter cursor. `db-apply` reads one
group at a time and sends it through the same database importer for MySQL and
SQLite. The target stores the exporter cursor and matching `db.sql` byte offset.
A replacement process reads that row and seeks directly to the next group.

For direct MySQL output and later `db-apply`, the MySQL importer executes the
statements in a complete group one at a time. It stores the group cursor only
after every statement in that group succeeds.

For transactional target tables, the group and its next cursor are committed
together. SQLite uses this transaction for every group. Table replacement and
oversized-value updates remain separate groups. If a MySQL process stops, its
replacement waits for the old target connection, reruns `db-session-setup.sql`,
and continues from the cursor stored in MySQL. A repeated INSERT skips rows
identified by a non-null unique key, so this also works for keyed MyISAM tables.
Reprint cannot continue a nontransactional table without such a key, or while
an oversized value is being appended in separate UPDATE statements; those cases
require aborting and starting the database import again. After `db-apply`
finishes, it removes its internal cursor table from the imported database.

## Target schema compatibility

For example, Online Backup for WordPress uses a MyISAM table whose
`AUTO_INCREMENT` column is second in a composite primary key. If the target
reports `enforce_storage_engine=InnoDB`, direct MySQL output and `db-apply`
add a non-unique index starting with that column so InnoDB can create the
table. Existing rows, IDs, and keys are kept. Future IDs use one table-wide
sequence instead of separate sequences for each group; plugins that rely on
per-group numbering may need changes. Reprint reports this change in terminal
output, JSONL warning records, and `audit.log`. It does not change the source
database or the downloaded SQL file, and leaves compatible schemas unchanged.

The `mysql` mode requires `--mysql-database` and accepts `--mysql-host`,
`--mysql-port`, `--mysql-user`, and `--mysql-password` (or the `MYSQL_PASSWORD`
environment variable). The host string also supports `host:port` and
`host:/path/to/socket` formats (same as WordPress `DB_HOST`), but
`--mysql-port` takes precedence when both are specified.


## Apply the downloaded database

If the site's domain is changing (e.g. migrating from https://old-site.com
to https://new-site.com), use `db-apply` with `--rewrite-url` to import
the SQL dump into a target database while rewriting all URLs in one pass.

MySQL target:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --target-user=site_import --target-db=wp_new \
    --rewrite-url https://old-site.com https://new-site.com
```

SQLite target:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --target-engine=sqlite --target-sqlite-path="$STATE_DIR/wordpress.sqlite" \
    --target-db=wp_new \
    --rewrite-url https://old-site.com https://new-site.com
```

This reads `db.sql` from the state directory and executes each statement against
the target database. For every data-bearing statement (`INSERT`, `UPDATE`), it
decodes the base64-encoded column values, detects the data format (serialized PHP,
JSON, block markup, plain text), and rewrites URLs through the appropriate parser
so that surrounding structure stays intact. Serialized PHP `s:N:` length prefixes
are recalculated, JSON is re-encoded, and block comment attributes are updated.
Rewriting allows at most 16 active calls through nested encoded strings. Values
below that depth stay unchanged; shallower sibling values are still rewritten.

## WordPress cleanup after apply

After applying the dump, `db-apply` removes `stylesheet_root`, `template_root`,
`rewrite_rules`, the database-backed site transients `theme_roots`, `update_core`,
`update_plugins`, and `update_themes`, and the `dirsize_cache` transient from the
imported WordPress options table. Transient timeout records are removed too.
WordPress can rebuild these caches for the local files and plugins; later update
checks may make network requests. The selected theme, theme settings, permalink
structure, custom upload paths, scheduled jobs, and locks stay unchanged. This
cleanup does not load WordPress or flush an external object cache.

## Unavailable full-text parsers

For MySQL and MariaDB targets, `db-apply` and `db-pull --sql-output=mysql`
check named full-text parsers in exported `CREATE TABLE` statements against
the target's active full-text parser plugins. If a parser is unavailable,
Reprint omits that index's `WITH PARSER` clause and keeps the index and its
columns. The target's default parser is used instead, so search matches and
ranking may change. Supported parsers remain unchanged. The saved `db.sql`
is never edited by this fallback.

Each omitted parser produces a JSONL warning, an audit-log entry, and a
message in terminal mode. Callers can detect the warning by its `type` and
`reason` fields:

```json
{
  "type": "warning",
  "phase": "sql",
  "reason": "unsupported_fulltext_parser",
  "table": "wp_articles",
  "index": "content_search",
  "parser": "ngram",
  "message": "The target has no active full-text parser 'ngram'. Omitting it from index 'content_search' on table 'wp_articles' and using the default parser; search matches and ranking may change."
}
```

## Multiple URL replacements

You can map multiple domains by repeating the flag:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --target-user=site_import --target-db=wp_new \
    --rewrite-url https://old-site.com https://new-site.com \
    --rewrite-url https://cdn.old-site.com https://cdn.new-site.com
```

A direct SQL import does not run Reprint's WordPress cleanup or runtime setup.
Use `db-apply` when you need those steps and their resumable import behavior.

For a database that is already running, see
[Rewrite URLs in a live database](../rewrite-urls/).
