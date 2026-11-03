# Database transfer reference

Examples use `$URL`, `$STATE_DIR`, `$FS_ROOT`, and an authorized `./reprint-key.pem` as described in [Conventions](https://developer.wordpress.org/reprint/reference/commands/#conventions). Run [preflight](https://developer.wordpress.org/reprint/reference/preflight/) with the same source address and state directory before downloading data.

## Download SQL

By default, this streams a SQL dump into `$STATE_DIR/db.sql`:

```bash
php reprint.phar db-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT"
```

You can also pipe the SQL directly to stdout or stream it into a MySQL server without writing a file to disk. Use `--sql-output` to choose the mode:

```bash
# Pipe to stdout — useful for feeding into mysql CLI or another tool
php reprint.phar db-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --sql-output=stdout | mysql --binary-mode --local-infile=0 -u import_user -p my_database

# Stream directly into MySQL — no intermediate file, no pipe
php reprint.phar db-pull "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" \
    --sql-output=mysql --mysql-database=my_database --mysql-host=127.0.0.1 --mysql-user=import_user --mysql-password=secret
```

For a pipe into the `mysql` CLI, keep `--binary-mode` and `--local-infile=0`. The first disables local client commands such as `source` and `system` while reading piped input; the second disables `LOAD DATA LOCAL INFILE`. These flags do not filter SQL statements. The receiving SQL client is responsible for these protections.

Use a database account limited to the target database rather than a server administrator account. Both the CLI pipe and `--sql-output=mysql` execute SQL from the source with the permissions of the supplied account. `stdout` writes the dump unchanged, so the program reading it must choose how to process it.

The three modes:

| Mode | What happens | Output file |
|------|-------------|-------------|
| `file` (default) | Writes SQL to `$STATE_DIR/db.sql` | `db.sql` |
| `stdout` | Streams SQL to stdout, progress/status goes to stderr | none |
| `mysql` | Executes SQL directly against the target database | none |

## Continue an interrupted database import

Run the same command with the same state directory, source address, and target database to continue. Keep the downloaded SQL file unchanged while an apply is unfinished. Do not delete migration state to restart the web server.

Transactional tables can resume from completed work. Keyed MyISAM tables can also resume, but a nontransactional table without a non-null unique key or an interrupted oversized-value update may require aborting and starting the import again. Follow the command's error instructions for those cases.

See [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/) when a product runs these commands on a user's behalf.

## Target schema compatibility

The SQL dump keeps the source table definitions, including storage-engine clauses such as `ENGINE=MyISAM`. Copying a database is not an instruction to upgrade its schema. Storage engines can affect locking, transactions, full-text search, and how IDs are generated.

The destination database has the final say. A MySQL-compatible target may substitute an engine it enforces; Reprint permits that substitution rather than rewriting the engine clause itself. SQLite translates the definitions because it has no MyISAM or InnoDB backend.

For a MyISAM table with IDs numbered separately within each group, a target that enforces InnoDB may need an additional index. Reprint adds that index during import. Existing rows, IDs, and keys are kept, but future IDs use one table-wide sequence. Plugins that rely on per-group numbering may need changes. Reprint reports this change in terminal output, JSONL warning records, and `audit.log`. It does not change the source database or the downloaded SQL file, and leaves compatible schemas unchanged.

The `mysql` mode requires `--mysql-database` and accepts `--mysql-host`, `--mysql-port`, `--mysql-user`, and `--mysql-password` (or the `MYSQL_PASSWORD` environment variable). The host string also supports `host:port` and `host:/path/to/socket` formats (same as WordPress `DB_HOST`), but `--mysql-port` takes precedence when both are specified.


## Apply the downloaded database

If the site's domain is changing (e.g. migrating from https://old-site.com to https://new-site.com), use `db-apply` with `--rewrite-url` to import the SQL dump into a target database while rewriting all URLs in one pass.

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

This imports `db.sql` from the state directory and rewrites URLs in serialized PHP values, JSON, block markup, and plain text while keeping those formats valid. URLs inside deeply nested encoded strings may remain unchanged.

## WordPress cleanup after apply

After apply, Reprint clears database-backed WordPress caches for theme paths, permalink rules, update checks, and directory sizes so WordPress can rebuild them for the destination. Later update checks may make network requests.

The selected theme, theme settings, permalink structure, upload paths, scheduled jobs, and locks stay unchanged. This does not load WordPress or flush an external object cache.

## Unavailable full-text parsers

For MySQL and MariaDB targets, `db-apply` and `db-pull --sql-output=mysql` check named full-text parsers in exported `CREATE TABLE` statements against the target's active full-text parser plugins. If a parser is unavailable, Reprint omits that index's `WITH PARSER` clause and keeps the index and its columns. The target's default parser is used instead, so search matches and ranking may change. Supported parsers remain unchanged. The saved `db.sql` is never edited by this fallback.

Each omitted parser produces a JSONL warning, an audit-log entry, and a message in terminal mode. Callers can detect the warning by its `type` and `reason` fields:

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

A direct SQL import does not run Reprint's WordPress cleanup or runtime setup. Use `db-apply` when you need those steps and their resumable import behavior.

For a database that is already running, see [Rewrite URLs in a live database](https://developer.wordpress.org/reprint/guides/rewrite-urls/).
