# Reprint — WordPress Site Migration

Clone any WordPress site over HTTP. One command pulls the files, database, and server config, then starts a local copy you can open in your browser.

## Quick start

### 1. Install the exporter plugin on the source site

```bash
php reprint.phar install-exporter
```

This prints the download URL and step-by-step instructions for installing the WordPress plugin on the site you want to clone. The plugin exposes the HTTP API that reprint connects to.

### 2. Pull the site

```bash
php reprint.phar pull https://example.com \
  --secret=YOUR_SECRET \
  --state-dir=./state --fs-root=./files
```

That's it. Reprint will:

1. **Preflight** the remote site (check connectivity, detect WordPress version and hosting environment)
2. **Download all files** (themes, plugins, uploads, core) into `--fs-root`
3. **Download the database** as a SQL dump
4. **Generate server config** for PHP's built-in server
5. **Start the local server** and print the URL

The output looks like:

```
Pulling example.com

[1/7] Preflight
  ✓ Preflight — WordPress 6.9.4, PHP 8.4.19

[2/7] Pulling files
  [5091 files] ...wp-content/uploads/2024/photo.jpg
  ✓ Pulling files

[3/7] Pulling database
  Downloading SQL: 4.2 MB (67.3%)
  ✓ Pulling database

[4/7] Importing database
  db-apply: 1234 / 5678 statements (45.2%)
  ✓ Importing database

[5/7] Generating runtime
  ✓ Generating runtime

✓ Pull complete

  Starting the server at http://localhost:8881
  Press Ctrl-C to stop.
```

### Options

**Database import** — add target database options and reprint will also import the SQL with URL rewriting:

```bash
# MySQL
php reprint.phar pull https://example.com --secret=TOKEN \
  --state-dir=./state --fs-root=./files \
  --target-user=root --target-db=wp_local \
  --new-site-url=http://localhost:8881

# SQLite (no MySQL needed)
php reprint.phar pull https://example.com --secret=TOKEN \
  --state-dir=./state --fs-root=./files \
  --target-engine=sqlite \
  --new-site-url=http://localhost:8881
```

**Runtime** — defaults to `php-builtin` (starts a server at the end). Override with `--runtime=nginx-fpm` or `--runtime=playground-cli` for other environments.

**Resume** — if interrupted, re-run the same command. It picks up where it left off. Running pull again after completion performs a delta sync (only downloads what changed).

**All options** — run `php reprint.phar pull --help` for the full list.

## Composer packages

The exporter and importer are published as separate Composer packages:

- [`wp-php-toolkit/reprint-exporter`](https://packagist.org/packages/wp-php-toolkit/reprint-exporter) — Streaming export engine (SQL dumps, file trees, cursor-based resumption).
- [`wp-php-toolkit/reprint-importer`](https://packagist.org/packages/wp-php-toolkit/reprint-importer) — Streaming site importer with CLI and PHAR support.

Install whichever you need:

```bash
composer require wp-php-toolkit/reprint-exporter
composer require wp-php-toolkit/reprint-importer
```

Both packages depend on [`wp-php-toolkit/data-liberation`](https://packagist.org/packages/wp-php-toolkit/data-liberation) and [`wp-php-toolkit/html`](https://packagist.org/packages/wp-php-toolkit/html), which Composer pulls in automatically.

## Repository layout

- `packages/reprint-exporter` — Source for the `wp-php-toolkit/reprint-exporter` Composer package.
- `packages/reprint-importer` — Source for the `wp-php-toolkit/reprint-importer` Composer package.
- `reprint-exporter-wp` — WordPress plugin distribution that bundles `reprint-exporter`.
- `importer/import.php` — thin compatibility wrapper for the importer package entrypoint.

### Technical requirements

On the **migration source** side:

 - PHP 7.4+
 - ext-json — JSON encoding/decoding
 - ext-hash — hash_hmac, hash_equals
 - ext-zlib — deflate_init/deflate_add for gzip streaming
 - ext-pdo + ext-pdo_mysql — database access (already in composer.json)

Hosts exposing the push endpoints must set `display_errors=Off` at PHP
startup, through `php.ini`, `.user.ini`, or the PHP-FPM pool configuration.
`log_errors=On` is recommended so startup failures remain available to
operators. PHP and the web server can reject an oversized request before the
exporter runs, so those responses are not guaranteed to use the push JSON
protocol. The sender accepts a bare HTTP 413 and learns a smaller request
ceiling from it.

On the **migration target** side:

 - PHP 7.4+
 - ext-json — JSON encoding/decoding
 - ext-hash — hash_hmac, hash_equals
 - ext-zlib — deflate_init/deflate_add for gzip streaming
 - ext-pdo + ext-pdo_mysql — for MySQL targets
 - ext-pdo + ext-pdo_sqlite — for SQLite targets via sqlite-database-integration

### Coding standards

This repo uses WordPress Coding Standards for PHP. The ruleset lives in
`phpcs.xml.dist`; run `composer lint:php` for the WPCS audit and
`composer lint:php:fix` for the available PHPCS auto-fixes.

The ruleset has temporary exclusions for existing standards debt so cleanup can
happen in focused passes instead of one giant formatting change. New or touched
code should follow WPCS unless the ruleset explicitly excludes that sniff.

Run `composer lint:php:compat` for the separate PHP 7.4+ compatibility check.

### Cutting a release

To cut a release, run the **Tag Release** workflow from the GitHub Actions tab
(`.github/workflows/tag-release.yml`) and pick a bump type (`patch`, `minor`,
`major`, or `custom`). It creates and pushes the `vMAJOR.MINOR.PATCH` tag, then
bumps `trunk` to the next `-dev` version. Pushing the tag automatically triggers
the build, which publishes the GitHub Release (`reprint.phar` +
`reprint-exporter-wp.zip`) and the Composer packages to Packagist.

---

## Integrating with a hosting platform

The `pull` command is designed for developers cloning a site to their local machine. If you're building a hosting platform that migrates sites programmatically, you'll want the low-level commands instead — they give you full control over each step, exit codes for scripting, and structured JSON output for progress tracking.

### Getting started

Download the latest release artifacts from [GitHub Releases](../../releases):

* **`reprint.phar`** — a self-contained PHP archive that runs on the **migration target** (the hosting account you are migrating to). No cloning or `composer install` needed.
* **`reprint-exporter-wp.zip`** — install this on the **migration source** (the remote WordPress site you want to migrate).

Both must share the same secret string. The plugin has a UI screen where the user can paste the secret, and then
the importer must be fed the same secret string (more details below). Alternatively, the plugin
can be pre-packaged with a `./reprint-exporter-wp/secret.php` file where a pre-determined secret is shipped:

```php
<?php
return 'MY_SECRET_STRING';
```

### Migrating the data

The migration process has a few steps:

1. Preflight
2. Download the files
3. Download the database dump
4. Download the files delta

All commands below use the same base invocation. We'll use `$URL` and `$DIR` as shorthand:

```bash
URL="https://example.com/?reprint-api"
STATE_DIR="./local-directory-where-the-migration-state-will-be-tracked"
FS_ROOT="./local-directory-where-the-remote-site-files-will-be-recreated"
SECRET="your-shared-secret"
```

#### Step 1 — Preflight.

First, we'll make sure the server is reachable and the environment is in a good shape:

```bash
php reprint.phar preflight "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

The preflight contacts the export server and collects environment details: PHP/MySQL versions, memory limits, filesystem access, database connectivity, WordPress version, plugins, themes, and directory layout. The result is stored in `.import-state.json` under the `preflight` key.

All other commands check that a preflight has been completed and refuse to start without one.

To run very basic diagnostics that confirms the remote server replied and it has a
sound-looking filesystem and a database connection, run:

```bash
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

For hosting platform-specific checks, such as database version compatibility or
php version compatibility, you might need your own custom logic. See the 
[Status files](#status-files) section for more details.

#### Step 2 — Download files.

This first builds a full index of the remote directory tree, then streams every file.
It can be interrupted and resumed at any time — just re-run the same command:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

The command returns one of three exit codes:

- 0: sync completed
- 1: failure
- 2: partial completion, needs re-running

Which is to say, you'll need to wrap it in a loop that runs until failure or full completion.

**Non-empty local fs-root**

By default, `files-pull` refuses to start if `--fs-root` is non-empty. If you need to use a non-empty local fs-root,
the `--on-fs-root-nonempty` flag controls this behavior. It takes the following values:

- `--on-fs-root-nonempty=error` (default): throw an error and abort.
- `--on-fs-root-nonempty=preserve-local`: import into the non-empty directory while preserving all existing local content.

**Filtering files**

The `--filter` flag controls which files are downloaded. This is useful when the media library is large
and you want to bring the site online before downloading all the uploads:

```bash
# Step 1: download only essential files (code, config, themes, plugins)
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --filter=essential-files
```

The pipeline proceeds as usual through indexing and diffing, but skips uploads. When the essential
files are done, the sync marks itself **complete**. The skipped file list stays on disk at
`.import-fetch-list-skipped.jsonl`. At this point you can apply the database and bring the site online.

```bash
# Step 2: download the uploads
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --filter=skipped-earlier
```

The three filter values:

- `--filter=none` (default): download all files.
- `--filter=essential-files`: skip uploads, download only code/config/themes/plugins.
- `--filter=skipped-earlier`: download only files that a prior `--filter=essential-files` run skipped.

The uploads directory is detected from preflight data (`uploads.basedir`), falling back to
`wp-content/uploads/` if unavailable.

#### Pull only files.

`pull-files` runs the file side of the high-level pull pipeline:

1. `preflight`
2. `files-pull`

Use it when you want a `git pull`-style file update without running any
database stages:

```bash
php reprint.phar pull-files "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

It accepts the `pull` file filters (`--filter=none` and
`--filter=essential-files`) plus the same path selection options as
`files-pull`, including repeated `--only` values:

```bash
php reprint.phar pull-files "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --only=:wp-content: --only=:wp-plugins:
```

#### Pull only the database.

`pull-db` runs the database side of the high-level pull pipeline:

1. `preflight`
2. `db-pull`
3. `db-apply`

Use it when you want the local database to catch up with the remote site without
touching files or runtime configuration:

```bash
php reprint.phar pull-db "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --target-engine=sqlite
```

If a different pull-like command is already in progress, finish or abort that
command first. This avoids reusing partially written state for a different
pipeline.

#### Step 3 — Download the database.

By default, this streams a SQL dump into `$STATE_DIR/db.sql`:

```bash
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

You can also pipe the SQL directly to stdout or stream it into a MySQL server
without writing a file to disk. Use `--sql-output` to choose the mode:

```bash
# Pipe to stdout — useful for feeding into mysql CLI or another tool
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --sql-output=stdout | mysql -u root my_database

# Stream directly into MySQL — no intermediate file, no pipe
php reprint.phar db-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --sql-output=mysql --mysql-database=my_database --mysql-host=127.0.0.1 --mysql-user=root --mysql-password=secret
```

The three modes:

| Mode | What happens | Output file |
|------|-------------|-------------|
| `file` (default) | Writes SQL to `$STATE_DIR/db.sql` | `db.sql` |
| `stdout` | Streams SQL to stdout, progress/status goes to stderr | none |
| `mysql` | Connects via `mysqli::multi_query()` and executes statements as they arrive | none |

All three modes recover from server crashes mid-stream (PHP fatal errors,
OOM kills, `max_execution_time` expiry). When the server dies before sending
a completion chunk, the importer detects the transport failure, saves its
cursor, and exits with code 2 for automatic retry. Accumulated SQL is
persisted in a `.sql-buffer` file so the next run reloads it and continues.

The `mysql` mode requires `--mysql-database` and accepts `--mysql-host`,
`--mysql-port`, `--mysql-user`, and `--mysql-password` (or the `MYSQL_PASSWORD`
environment variable). The host string also supports `host:port` and
`host:/path/to/socket` formats (same as WordPress `DB_HOST`), but
`--mysql-port` takes precedence when both are specified.

The command returns one of three exit codes:

- 0: sync completed
- 1: failure
- 2: partial completion, needs re-running

#### Step 4 — Download files delta.

While the database was being dumped, some files may have changed.

First, we must abort the previous files-pull. Otherwise, it would just
tell us it's completed and refuse to proceed:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" --abort
```

From here, we can run the `files-pull` command again. It will index
the remote filesystem once again, compute which files have changed
since the initial sync, and apply that delta in the local directory:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

The command returns one of three exit codes:

- 0: sync completed
- 1: failure
- 2: partial completion, needs re-running

#### Step 5 — Apply the database with domain rewriting.

If the site's domain is changing (e.g. migrating from `https://old-site.com`
to `https://new-site.com`), use `db-apply` with `--rewrite-url` to import
the SQL dump into a target database while rewriting all URLs in one pass.

MySQL target:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --target-user=root --target-db=wp_new \
    --rewrite-url https://old-site.com https://new-site.com
```

SQLite target:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
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

You can map multiple domains by repeating the flag:

```bash
php reprint.phar db-apply "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --target-user=root --target-db=wp_new \
    --rewrite-url https://old-site.com https://new-site.com \
    --rewrite-url https://cdn.old-site.com https://cdn.new-site.com
```

If the domain isn't changing, you can skip `db-apply` and import `db.sql`
directly with a MySQL tool, or use `db-apply --target-engine=sqlite` to load it
into SQLite through the bundled `sqlite-database-integration` driver.

#### Step 6 — Generate runtime configuration.

The downloaded files need server-specific configuration to actually work —
PHP constants, INI directives, and request handlers that the source host
relied on. `apply-runtime` reads the preflight data, detects the source
hosting provider, and generates the configuration files your target server needs.

For PHP's built-in development server:

```bash
php reprint.phar apply-runtime --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=php-builtin
bash "$RUNTIME_DIR/start.sh"
```

For nginx + PHP-FPM:

```bash
php reprint.phar apply-runtime --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=nginx-fpm
# Include $RUNTIME_DIR/nginx.conf in your nginx configuration, then reload
```

The command accepts either `--fs-root` (the raw download directory — the remote
`document_root` path is appended automatically) or `--flat-document-root` (a
directory created by `flat-docroot`, used as-is). These are mutually exclusive.

Host and port default to the URL rewrite target from `db-apply` (so the server
listens on the same address the database was rewritten to). Override with
`--host` and `--port`.

**What gets generated:**

The command produces a `runtime.php` file that sets PHP constants, server
variables, and route handlers the source site needs. Each target runtime
wraps it differently:

| Runtime | Output files | How runtime.php loads |
|---------|-------------|----------------------|
| `php-builtin` | `runtime.php`, `start.sh` | Used as the router script for `php -S` |
| `nginx-fpm` | `runtime.php`, `nginx.conf` | Loaded via `auto_prepend_file` in `fastcgi_param PHP_VALUE` |

The architecture separates source host detection from target runtime
configuration. Host analyzers read preflight data and produce a declarative
manifest (constants, INI directives, routes). Runtime appliers consume the
manifest and write server-specific files. Adding a new source host or target
server is independent — you implement one interface without touching the other.

Currently supported source hosts: WP Cloud (with on-the-fly thumbnail
generation for missing image sizes, automatic stripping of production-only
drop-ins like Memcached object-cache and wpcomsh mu-plugins, and
auto-detection of extra directories from `auto_prepend_file`/`auto_append_file`
INI values), SiteGround, and a generic default.
Currently supported target runtimes: nginx + PHP-FPM, PHP's built-in
development server, and WordPress Playground CLI.

For Playground CI, set `PHP_BINARY=tests/e2e/ci/playground-php.sh`. The wrapper
delegates to `@wp-playground/cli php` by default. When
`WP_MYSQL_PARSER_EXTENSION_MANIFEST` is set, it runs the local `@php-wasm/node`
runner instead so it can load the SQLite Integration plugin's Rust
`wp_mysql_parser` PHP.wasm extension. CI verifies that this path does more than
load the `.so`: `tests/e2e/ci/verify-wp-mysql-parser.php` asserts that
`WP_MySQL_Lexer` resolves to the native lexer and that the SQLite driver creates
a native-backed parser before benchmarking Playground `db-pull` and `db-apply`.
That path requires Node.js with JSPI support; CI uses Node 24.

#### Shoehorning the site onto your platform

You've got a copy of the remote files in the `--fs-root` directory and
the database either already applied (via `db-apply`) or in `--state-dir/db.sql`.
From here, you need to figure out how to run that on your platform.

The `db.sql` file will contain the relevant `DELETE TABLE IF EXISTS`
statements to make sure it can always succeed. You might want to,
before the first run, clean up any tables that may have been already
created by your environment. We won't need them. Furthermore, they may
not get deleted during the database import if the site doesn't use
the same table prefix as your environment.

If you used `--sql-output=mysql`, the SQL was already executed — there's
no `db.sql` to import. For `--sql-output=stdout`, the SQL was piped to
whatever tool was reading stdout (typically `mysql` CLI).

### Status files

These files live directly in `$DIR` and are updated by the `import.php`
script with the latest migration details. They're written atomically,
such that a `.tmp` files is written first and then renamed to its final
name – this ensures readers never see a partially written state.

While there's many of these files, most of them are for internal use only.
The two that might be particularly useful for integrators are:

* `.import-status.json` – the current progress
* `.import-state.json` – the migration state store

#### `.import-status.json` – the current progress

When an external process (e.g. a web UI) needs to poll migration progress, it can read
`.import-status.json` in the output directory.

Pass `--step=N` and `--steps=N` to your `import.php` calls to embed the pipeline position in
the status file. For example, a four-step pipeline would pass `--step=1 --steps=4` for the
preflight, `--step=2 --steps=4` for db-index, and so on.

The file contains a flat JSON object:

```json
{
  "step": 2,
  "steps": 4,
  "command": "files-pull",
  "status": "in_progress",
  "phase": "index",
  "error": null,
  "ts": 1707600000.123
}
```

| Field     | Type              | Description |
|-----------|-------------------|-------------|
| `step`    | `int \| null`     | Current pipeline step (1-indexed). `null` when `--step` is not passed. |
| `steps`   | `int \| null`     | Total pipeline steps. `null` when `--steps` is not passed. |
| `command` | `string \| null`  | Current command name (`preflight`, `files-pull`, `db-pull`, etc.). |
| `status`  | `string`          | One of `in_progress`, `partial`, `complete`, `error`, `aborted`. |
| `phase`   | `string \| null`  | Sub-phase within the command (e.g. `index`, `diff`, `fetch`, `fetch-skipped`), or `null`. Derived from the internal state's `stage` field. |
| `error`   | `string \| null`  | Error message when `status` is `error`, otherwise `null`. |
| `ts`      | `float`           | Unix timestamp with microsecond precision (`microtime(true)`). |

During the file fetch phase, progress and heartbeat records also include
structured file counters:

| Field         | Type           | Description |
|---------------|----------------|-------------|
| `files_done`  | `int`          | Files already processed (cumulative across restarts). Derived from the fetch list byte offset plus the current batch's `files_imported`. |
| `files_total` | `int`          | Total non-empty entries in the fetch list. Fixed once the diff phase completes. |

Both fields are emitted together only when the fetch list exists — they
are absent during the index and diff phases. `files_done` grows monotonically
up to `files_total` and survives exit-code-2 restarts.

#### `.import-state.json` — the migration state store

This is the importer's brain. Every command reads it on startup and writes it
back periodically and on shutdown. It stores everything needed to resume after
a crash or interruption: the current command, cursor position, AIMD tuning
state, and per-phase bookmarks.

Written atomically (temp file + rename) so a crash mid-write never corrupts it.
If the JSON is invalid on load, the importer renames it to
`.import-state.json.corrupt.<timestamp>` and starts fresh.

```jsonc
{
  "command": "files-pull",         // active command
  "status": "in_progress",        // "in_progress" | "complete" | null
  "cursor": "...",                 // server-side cursor (opaque string)
  "stage": "streaming",           // current phase within the command
  "preflight": { ... },           // cached preflight response
  "version": "...",               // importer version
  "follow_symlinks": true,
  "max_allowed_packet": null,     // client-side MySQL packet limit

  // Per-command state sections:
  "db_index": {
    "file": "db-tables.jsonl",
    "tables": 42,
    "rows_estimated": 150000,
    "bytes": 8192,
    "updated_at": "2025-01-15T10:30:00Z"
  },
  "diff": {
    "remote_offset": 1024,        // byte offset into remote index
    "local_after": "base64..."    // last compared local path
  },
  "index": {
    "cursor": "..."               // file_index cursor
  },
  "filter": "none",               // "none" | "essential-files" | "skipped-earlier"
  "fetch": {
    "offset": 512,                // byte offset into fetch list
    "next_offset": 1024,
    "batch_file": null,
    "cursor": "..."               // file_fetch cursor
  },
  "fetch_skipped": {              // used when --filter=skipped-earlier
    "offset": 0,
    "next_offset": 0,
    "batch_file": null,
    "cursor": null
  },

  // Crash recovery: if the importer dies mid-write, these let it
  // truncate the partially-written file back to its last good state.
  "current_file": "wp-content/uploads/photo.jpg",
  "current_file_bytes": 1048576,  // expected size after last complete write
  "sql_bytes": 524288,            // expected db.sql size
  "sql_output": "file",           // "file" | "stdout" | "mysql"

  "tuning": {
    "config": { ... },            // AIMD parameters
    "state": { ... }              // current AIMD sizes
  }
}
```

**For the hosting platform**: Read this file to determine whether a command is
still running, completed, or needs resuming. The `command` + `status` fields
tell you where the pipeline is. The `stage` field gives finer granularity
(e.g., `"scanning"`, `"sorting"`, `"streaming"` for file sync).

For pull-level lifecycle checks, prefer `import-metadata` over reading
`.import-state.json` directly. It exposes Reprint-owned pull state as a small,
stable JSON contract for host integrations:

```bash
php reprint.phar import-metadata --state-dir="$STATE_DIR" | jq '.hasCompletedOnce'
```

#### `.import-volatile-files.json` — files that changed during sync

During `files-pull`, a file on the source may be modified while the importer is
streaming it. When that happens, the server returns a different content hash than
expected and the importer records the file in `.import-volatile-files.json`
instead of failing.

The file is a flat JSON object mapping paths to the number of times each file
was detected as changed:

```json
{
  "/srv/htdocs/wp-content/debug.log": 4,
  "/srv/htdocs/wp-content/cache/object-cache.tmp": 2
}
```

At the end of `files-pull`, the importer prints a summary of volatile files so
the caller can decide what to do — re-run the sync, ignore them, or ask the user.
Files that are subsequently downloaded successfully are automatically removed
from the tracker. The file is deleted entirely once all entries are cleared.

#### `.import-audit.log` — append-only event log

Every significant event during import is recorded in `.import-audit.log` as a
timestamped line. This includes file downloads, deletions, volatile file
detections, errors, and state transitions. The log is append-only — it's never
truncated or rotated, so it provides a complete history of the migration.

```
[2025-01-15 10:30:01] VOLATILE | path=/srv/htdocs/wp-content/debug.log | count=1
[2025-01-15 10:30:05] VOLATILE CLEARED | path=/srv/htdocs/wp-content/debug.log
[2025-01-15 10:31:12] FILE DELETE | .import-index-updates.jsonl
```

Pass `--verbose` to also print audit log entries to the console as they happen.
This is useful for debugging but noisy for production use.

### Low-level CLI commands

The importer accepts the following commands:

```
php reprint.phar <command> <URL> --state-dir=DIR --fs-root=DIR [options]
```

* `preflight` — Runs the preflight check and prints the full result as JSON. Exits with code 0 if OK, code 1 if not.
* `preflight-assert` — Runs the preflight check and prints a human-readable pass/fail summary. Exits with code 0 if migration looks feasible, code 1 if not.
* `pull-files` — Runs `preflight` and `files-pull` as one resumable high-level command.
* `pull-db` — Runs `preflight`, `db-pull`, and `db-apply` as one resumable high-level command.
* `files-pull` — Pull all files (initial) or only changes (delta). Runs files-index if needed.
* `files-index` — Index all remote files (initial) or detect changes (delta). No file contents downloaded.
* `db-pull` — Pull the database as a SQL dump. Defaults to writing `db.sql`; use `--sql-output=stdout` or `--sql-output=mysql` to stream elsewhere.
* `db-apply` — Applies `db.sql` to a target MySQL or SQLite database. Accepts `--rewrite-url FROM TO` (repeatable) to rewrite domains during import.
* `db-domains` — Lists domains discovered in the SQL dump. Reads `.import-domains.json` if available (written by `db-pull`), otherwise scans `db.sql`.
* `db-index` — Indexes database tables and their statistics (name, row count, size) to `db-tables.jsonl`.
* `import-metadata` — Prints local pull lifecycle metadata as JSON, including `hasCompletedOnce`. Requires only `--state-dir`; no network calls are made.
* `flat-docroot` — Reassemble pulled files into a standard WordPress directory layout using symlinks. Useful when the source site has a non-standard layout (e.g. WP Cloud with ABSPATH separate from wp-content).
* `apply-runtime` — Generates server configuration files (`runtime.php`, `start.sh` or `nginx.conf`) from preflight data. See [Step 6](#step-6--generate-runtime-configuration).

All commands except `preflight-assert` support `--abort` to abort the current sync and exit. For `files-pull`, this clears sync progress but keeps the local index and downloaded files — the next run performs a delta sync. For `db-pull` and `db-index`, it clears the output file so the next run starts from scratch. Interrupted commands automatically resume from the last saved cursor.
