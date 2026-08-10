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

# Run PHP 7.4+ compatibility
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

### Runtime Manifest and Host Analyzers

The `apply-runtime` command separates source host detection from target runtime configuration. The flow is:

1. **Host analyzer** (in `packages/reprint-client/src/lib/host/analyzers/`) reads preflight data and produces a `RuntimeManifest` — a pure-data object with INI directives, constants, server vars, routes, `paths_to_remove`, and `extra_directories`.
2. **Runtime applier** (in `packages/reprint-client/src/lib/target-runtime/`) reads the manifest and generates server-specific configuration files.

The `WpcloudHostAnalyzer` auto-detects WP Cloud production infrastructure that won't work locally: Memcached-backed `object-cache.php`, wpcomsh mu-plugins, and `auto_prepend_file`/`auto_append_file` directories. It populates `paths_to_remove` (stripped after flattening) and `extra_directories` (auto-included in the export file list). The `SitegroundHostAnalyzer` detects SiteGround hosting via `sg-*` plugin prefixes and strips `sg-cachepress` and `sg-security` from disk.

Entries in `paths_to_remove` under `wp-content/plugins/` also trigger automatic plugin deactivation: at the end of `db-apply`, while the target database connection is still open, the importer instantiates the host analyzer, extracts plugin directory names from `paths_to_remove`, and removes matching entries from the `active_plugins` option. This prevents "plugin file does not exist" warnings in wp-admin. The deactivation is derived from `paths_to_remove` — there is no separate manifest field for it. We skip WordPress's `deactivate_plugins()` because the plugin files will already be gone from disk by the time WordPress boots, so firing deactivation hooks into absent code is pointless.

### SQL Streaming Crash Recovery

When the export server crashes mid-SQL-stream (`--sql-output=mysql` mode), the importer detects the transport failure (missing completion chunk, curl communication errors), saves the cursor, persists accumulated SQL in `<remote-state-directory>/pull/sql-buffer`, and exits with code 2 for automatic retry. The next run reloads the buffer and continues. The `finally` block avoids masking the original exception with a secondary buffer-related throw.

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
  - src/lib/host/: Host analyzers and RuntimeManifest (WpcloudHostAnalyzer, SitegroundHostAnalyzer, DefaultHostAnalyzer)
  - src/lib/target-runtime/: Runtime appliers (NginxFpmApplier, PhpBuiltinApplier, PlaygroundCliApplier)
  - src/lib/url-rewrite/: URL rewriting for db-apply
  - src/lib/mysql-query-stream/: MySQL query stream parser for direct streaming
- reprint-server-wp/: Self-contained WordPress plugin distribution directory
  - index.php: WordPress plugin entry point — intercepts `?reprint-api` requests (and the legacy `?site-export-api` alias) during plugin load, requires lib.php
  - lib.php: Standalone library — constants, auth functions, and request handler. Can be required without index.php by projects that want to embed the export engine with their own URL routing and authentication (pass a custom `authenticate` callable in the `$options` array to `_site_export_handle_api_request()`)
  - wordpress/: WordPress admin UI (site-export.php)
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
