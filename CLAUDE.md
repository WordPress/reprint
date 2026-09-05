# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

AGENTS.md holds the working rules distilled from code review in this repo — what to do and what not to do. Read it before writing code; its rules override default instincts.

## Project Overview

This is a WordPress site export/import system that enables resumable, cursor-based synchronization of both database content and filesystem data over HTTP. The system is designed to work on resource-constrained shared hosting environments by carefully managing memory and execution time.

## Core Architecture

The codebase follows a producer-consumer pattern with two main components:

### Export Side (Server) — `packages/reprint-server/src/`
- **export.php**: HTTP endpoint that serves as the export API, handling authentication and routing requests to the appropriate producer
- **MySQLDumpProducer**: Generates SQL dump fragments with cursor-based resumption, supporting batched INSERT statements and all MySQL data types
- **FileTreeProducer / FileListProducer**: Streams filesystem contents (full tree or explicit list) in chunks with support for symlinks and cursor-based resumption

### Import Side (Client) — `packages/reprint-client/src/`
- **import.php**: CLI script that downloads from export.php using streaming multipart parsing, no buffering of entire response
- **MultipartStreamParser**: Incremental multipart/mixed parser that processes chunks as they arrive

### Supporting Classes
- **MysqlValueFormatter**: Formats MySQL values by type (NULL, numeric, binary hex, quoted strings)
- **ColumnTypeCache**: Queries and caches INFORMATION_SCHEMA.COLUMNS data
- **FileSnapshotStorage / SqliteSnapshotStorage**: Pluggable snapshot storage for deletion tracking

## Key Design Patterns

### Cursor-Based Reentrancy
Both producers support pausing and resuming via JSON cursors that encode complete state:
- Current table/file position
- Accumulated rows/chunks waiting to emit
- Last processed primary key values or byte offsets

Cursors are JSON strings internally, base64-encoded for HTTP transmission in X-Cursor (outgoing) and X-Export-Cursor (incoming) headers.

### Resource Budgeting
The system tracks memory and execution time limits to gracefully end requests before hitting host limits. This prevents process termination and allows resumption.

### Streaming Multipart Transport
Uses multipart/mixed content-type to split large files into chunks while transmitting per-chunk metadata (cursor, size, path). This allows splitting arbitrary-sized files across multiple HTTP requests.

## Development Commands

### Running Tests

```bash
# Run all PHPUnit tests
composer test

# Run with coverage (requires Xdebug)
composer test:coverage

# Run specific test file
cd tests && vendor/bin/phpunit MySQLDumpProducer/BasicDumpTest.php

# Run specific test method
cd tests && vendor/bin/phpunit --filter testRoundTripIntegrity
```

### Coding Standards

This repo uses WordPress Coding Standards for PHP. The ruleset lives in
`phpcs.xml.dist` and has temporary exclusions so cleanup can happen in focused
passes.

```bash
# Run WPCS
composer lint:php

# Apply PHPCS auto-fixes from the ruleset
composer lint:php:fix

# Check typed exporter source on PHP 7.2 and importer source on PHP 7.4
composer lint:php:compat
```

### Running E2E Tests

```bash
# From tests/e2e directory
cd tests/e2e

# Install JavaScript dependencies
npm install

# Run all end-to-end scenarios
npm run test

# Run a single scenario
npm run test -- tests/import-01-basic-file-sync.test.js
```

There are 49 E2E test files in `tests/e2e/tests/`, named `import-NN-description.test.js`. Each test spins up Docker containers with WordPress and runs a full import scenario.

### Database Configuration

Tests use environment variables defined in tests/phpunit.xml:
- DB_HOST (default: 127.0.0.1)
- DB_USER (default: root)
- DB_PASS (default: my-secret-pw)
- DB_NAME (default: test_mysql_dump)

Override with environment variables if needed.

## Important Implementation Details

### Symlink Security

Symlinks ARE automatically recreated during import. This is safe because all paths are relative to the `--fs-root` directory, preventing directory traversal outside it. Errors are logged to the audit log.

### Server-Side Directory Dedup

The file indexer (`endpoint_file_index` in `export.php`) prevents duplicate traversal of directories that overlap with configured roots. The `should_skip_index_root()` function checks each directory's `realpath()` against the scheduled root list — if a directory is a duplicate or parent of an already-scheduled root, traversal skips it. This is critical for WP.com Atomic sites where symlinks create overlapping paths (e.g. `/srv/htdocs/srv` → `/srv` creating infinite cycles, or `/wordpress/` and `/srv/htdocs/wordpress/` resolving to the same location).

### Non-Empty fs-root Handling (`--on-fs-root-nonempty`)

By default, `files-pull` refuses to start if `--fs-root` is non-empty (to prevent accidental overwrites). The `--on-fs-root-nonempty` flag controls this behavior:

- `--on-fs-root-nonempty=error` (default): throw an error and abort.
- `--on-fs-root-nonempty=preserve-local`: import into the non-empty directory while preserving all existing local content.

In `preserve-local` mode:
- Existing files are never overwritten — if anything (regular file, symlink, directory) already exists at a remote file's path, the remote file is skipped.
- Pre-existing symlinks in directory paths are kept, and no new content is ever created through them. If any component of a file's directory path is a symlink, the entire operation is skipped. This is critical for hosting environments where plugins, themes, and WP core are symlinked from a shared location — their contents must not be modified.
- Non-writable directories are skipped gracefully instead of causing errors.
- All skipped operations are logged to the audit log with a `PRESERVE-LOCAL` prefix.
- The setting persists in state, so it survives across resume cycles and delta syncs. During delta sync, previously-skipped files remain protected.

### SQL Dump Batching

MySQLDumpProducer accumulates rows internally (default 250 rows per batch) and emits complete multi-row INSERT statements. This is memory-efficient and produces dumps compatible with standard MySQL import tools.

### File Synchronization Phases

FileTreeProducer operates in three phases visible via get_progress():
1. **scanning**: Directory traversal to enumerate files
2. **sorting**: Sorting files by (ctime, path) for deterministic ordering
3. **streaming**: Emitting file chunks with resumption support

### Primary Key Handling

MySQLDumpProducer supports multiple primary key scenarios:
- Simple PK: Uses last PK value for cursor
- Composite PK: Uses all PK columns in cursor
- No PK: Falls back to OFFSET-based pagination (less efficient)

### Test Database Isolation

PHPUnit tests automatically create/drop test databases. The naming convention is:
- Export database: `test_mysql_dump`
- Import database: `test_mysql_dump_import`

### Runtime Manifest, Host Detection, and Excluded Plugins

The `apply-runtime` command separates source host detection from target runtime setup. The flow is:

1. **Host analyzer** (in `packages/reprint-client/src/lib/host/analyzers/`) reads current preflight data and produces a `RuntimeManifest` with only the settings needed by the target server: INI directives, constants, server vars, routes, extra directories, and optional SQLite setup.
2. **Runtime applier** (in `packages/reprint-client/src/lib/target-runtime/`) reads the manifest and generates server-specific configuration files.

The target database is an input to `apply-runtime`: it takes the same `--target-*` options as `db-apply` and falls back, field by field, to what `db-apply` recorded in state, so a caller that keeps its own database can generate a working runtime without ever running `db-apply`.

`excluded_plugins()` keeps plugin cleanup separate from target server setup. It calculates each excluded item's absolute source path from the `content_dir`, `plugins_dir`, and `mu_plugins_dir` values reported by preflight. Named source-host plugins and MU plugins are excluded from every import because copied files may remain after a site moves between hosts. Generic cache drop-ins are excluded only when current preflight paths identify WP Cloud or WP Engine, and WP Engine's generic `mu-plugin.php` loader is excluded only for WP Engine.

During `files-pull`, matching remote-index entries are omitted before the fetch list is built, so their file bodies are not downloaded. `apply-runtime` still removes matching local paths because an older import or pre-existing local tree may already contain them. At the end of `db-apply`, the importer removes matching regular plugin directories from the `active_plugins` option while the target database connection is still open. We skip WordPress's `deactivate_plugins()` because WordPress has not booted and the excluded plugin code may already be absent.

### SQL Streaming Crash Recovery

Direct MySQL output keeps incomplete SQL only in memory while one importer process requests more source responses. Existing SQL statement-size, fragment, time, and memory budgets may split SQL across multipart parts or put several regular INSERT statements in one part. File output adds a harmless cursor comment after each complete SQL group. `db-apply` reads those groups and sends them through the same MySQL importer as direct output. The importer runs one complete group, updates `__reprint_db_pull_progress_<uuid>` with the exporter cursor and the optional `db.sql` byte offset, and commits the target transaction. A replacement file importer reads that target row and seeks directly to the next group. Table replacement and oversized-value updates remain separate groups. The UUID makes an accidental table-name clash unlikely and changes whenever the table schema changes. Reprint logs and excludes that internal name from every source dump. For transactional target tables, the imported rows and position commit together. If the importer process stops, its replacement waits for the old target connection, reapplies `db-session-setup.sql`, and continues from the position stored in the target database. Repeated INSERT statements skip rows identified by a non-null unique key, which also permits keyed MyISAM tables to continue. Nontransactional tables without such a key, and nontransactional oversized-value UPDATE sequences, require a fresh import. Completed file applies remove the internal cursor table.

### Progress Tracking

During the file fetch phase, progress and heartbeat records include `files_done` (cumulative across restarts, derived from fetch list byte offset + current batch count) and `files_total` (total fetch list entries, fixed after the diff phase). Both are emitted together only when the fetch list exists.

During files-push, progress records include `files_done` and `files_total` together after planning. The completed count advances only at target-confirmed request boundaries and survives resume.

Interactive non-verbose files-push output uses one stage-weighted progress bar for the complete lifecycle. The percentage precedes a label which changes only at major lifecycle stages; while pushing local paths, target-confirmed file bytes appear beside the planned file byte total. These terminal-only details are not added to JSONL or `progress.json`.

Every command run by `ImportClient` accepts `--progress=auto|tty|jsonl` for that invocation. `auto` uses terminal output on a TTY and JSONL otherwise; `tty` and `jsonl` force either presentation without changing command state. Explicit `tty` and `jsonl` modes cannot be combined with `--verbose`.

## File Organization

- packages/reprint-server/: Packagist server package (previously reprint-exporter)
  - src/: Core export engine (export.php, producers, HMAC client, utilities)
- packages/reprint-client/: Packagist client package (previously reprint-importer)
  - src/: Import client and importer runtime support code
  - src/lib/host/: RuntimeManifest and the default, WP Cloud, and WP Engine host analyzers
  - src/lib/target-runtime/: Runtime appliers (NginxFpmApplier, PhpBuiltinApplier, PlaygroundCliApplier)
  - src/lib/url-rewrite/: URL rewriting for db-apply
  - src/lib/mysql-query-stream/: MySQL query stream parser for direct streaming
- reprint-server-wp/: Self-contained WordPress plugin distribution directory
  - index.php: WordPress plugin entry point — intercepts `?reprint-api` requests (and the legacy `?site-export-api` alias) during plugin load, requires lib.php
  - lib.php: Standalone library — constants, auth functions, and request handler. Can be required without index.php by projects that want to embed the export engine with their own URL routing and authentication (pass a custom `authenticate` callable in the `$options` array to `_site_export_handle_api_request()`)
  - wordpress/: Namespaced WordPress configuration adapter and native administrator UI (`configuration.php`, `reprint-server.php`)
- docs/: Architecture documentation (read these for deep understanding) and project logos (docs/assets/)
- tests/: PHPUnit test suite organized by component
- tests/e2e/: End-to-end Docker-based integration tests
- exports/: Git-ignored directory for test exports

## Testing Philosophy

Every test follows a 5-step pattern:
1. Setup: Create tables and insert test data
2. Export: Generate SQL dump or file sync
3. Assert: Verify output contains expected content
4. Round-trip: Import to new database/directory
5. Verify: Compare original and imported data for integrity

This ensures SQL is correct, valid, and preserves data without loss or corruption.

## WP.com Atomic Hosting Layout

WP.com Atomic sites have a non-standard directory structure that drives many of the recent dedup and split-root fixes. Understanding it helps when working on file sync:

- **ABSPATH** points to `/srv/htdocs/__wp__/` (shared WordPress core), not the document root.
- **Document root** is `/srv/htdocs/`, which contains `wp-content/` with the site's actual plugins, themes, and uploads — separate from the `__wp__/` tree.
- **Symlink cycles**: `/srv/htdocs/srv` → `/srv` creates infinite recursion during traversal.
- **Overlapping roots**: `/wordpress/` and `/srv/htdocs/wordpress/` can resolve to the same physical directory.
- **Production drop-ins**: Memcached `object-cache.php`, `wpcomsh` mu-plugins, and `auto_prepend_file` scripts in `/scripts/` that depend on production APIs.

The exporter must scan both roots (document root + ABSPATH) without infinite loops, and the importer must strip production-only infrastructure before the site can run locally.

## Common Gotchas

- **Cursor encoding**: Producers work with JSON strings. export.php handles base64 encoding for HTTP. Never pass base64 to producer constructors.
- **Variable names**: Use full, descriptive names for domain values. Do not abbreviate to save characters (for example, prefer `$current_base64_path` over `$cur_b64`).
- **JSON/JSONL parsing**: Parse JSON as JSON and validate semantic fields. Do not match prefixes, slice by fixed offsets, or depend on JSON field order or escaping.
- **Memory limits**: Large dataset tests require at least 512MB PHP memory_limit
- **Execution time**: LargeDatasetReentrancyTest processes 200,000+ rows and may take 30-60 seconds
- **MySQL version**: Minimum MySQL 5.7 required (for JSON type support)
- **Character encoding**: Tests assume utf8mb4 support

## Documentation

Architecture docs are in docs/:
- PUSH-SYNC.md: Push synchronization delivery plan
- PUSH-TERMINOLOGY.md: Push language contract (see AGENTS.md — read before push work)

Always consult these when working on the respective components.

## CLI API Design

### Progress Computation

Progress is computed client-side by reading state files. The remote state
directory is
`--state-dir/remotes/<md5-of-trimmed-remote-reprint-api-url>`:
- `<remote-state-directory>/pull/state.json`: Current command, status, cursor, stage
- `<remote-state-directory>/pull/remote-index.jsonl`: Remote index (line count = accounted entries)
- `<remote-state-directory>/pull/remote-index.next.jsonl`: Next remote index (for delta comparison)
- `<remote-state-directory>/pull/fetch-list.jsonl`: Files pending download
- `db.sql`: SQL dump file size

And from `--fs-root`:
- Actual downloaded files (recursive size/count)

This keeps the protocol minimal while enabling rich progress visualization.
