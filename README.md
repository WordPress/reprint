# Reprint — WordPress Site Migration

Clone any WordPress site over HTTP. One command pulls the files, database, and server config, then starts a local copy you can open in your browser.

## Quick start

### 1. Install the Reprint Server plugin on the source site

```bash
php reprint.phar install-server
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

**Resume** — if interrupted, re-run the same command. It picks up where it left off. Running pull again after completion performs a delta sync. By default, the delta [catches up with remote changes](#file-pull-modes); mirror mode is available when the selected local paths must match the remote site.

**All options** — run `php reprint.phar pull --help` for the full list.

## Composer packages

The server and client are published as separate Composer packages:

- [`wp-php-toolkit/reprint-server`](https://packagist.org/packages/wp-php-toolkit/reprint-server) — Streaming export engine (SQL dumps, file trees, cursor-based resumption) that backs the Reprint Server plugin.
- [`wp-php-toolkit/reprint-client`](https://packagist.org/packages/wp-php-toolkit/reprint-client) — Streaming site importer with CLI and PHAR support.

Install whichever you need:

```bash
composer require wp-php-toolkit/reprint-server
composer require wp-php-toolkit/reprint-client
```

These packages were previously published as `wp-php-toolkit/reprint-exporter`
and `wp-php-toolkit/reprint-importer`. Each renamed package replaces its
predecessor at `self.version`, so the old and new names cannot be installed
side by side; consumers migrate by changing the requirement name.

The client package depends on
[`wp-php-toolkit/data-liberation`](https://packagist.org/packages/wp-php-toolkit/data-liberation)
and [`wp-php-toolkit/html`](https://packagist.org/packages/wp-php-toolkit/html),
which Composer pulls in automatically. The server package has no package
dependencies.

## Repository layout

- `packages/reprint-server` — Source for the `wp-php-toolkit/reprint-server` Composer package (the HTTP endpoint on the WordPress host).
- `packages/reprint-client` — Source for the `wp-php-toolkit/reprint-client` Composer package (the CLI). `packages/reprint-client/bin/reprint-client` is the entry point for repo checkouts; Composer installs it as `vendor/bin/reprint-client`.
- `reprint-server-wp` — WordPress plugin distribution that bundles `reprint-server`. The release ZIP keeps the legacy `reprint-exporter-wp.zip` name so upgrades land in the existing installed plugin directory.
- `tests` — PHPUnit suite (`tests/`), Docker-based e2e scenarios (`tests/e2e/`), and PHPStan support files (`tests/phpstan/`).
- `docs` — [design choices](docs/DESIGN.md), focused design documents, and
  project logos.
- `bin` — build tooling (PHAR build, plugin version stamping).
- `lib` — the `sqlite-database-integration` git submodule used by the MySQL query parser.

### Technical requirements

On the **migration source** side:

 - The release WordPress exporter plugin supports pull endpoints on PHP 5.6.20+; push endpoints require PHP 7.2+
 - The typed `wp-php-toolkit/reprint-server` Composer package requires PHP 7.2+
 - ext-json — JSON encoding/decoding
 - ext-hash — hash_hmac, hash_equals
 - ext-zlib (optional) — PHP 7.0+ uses it for gzip streaming; otherwise the exporter streams the multipart response without compression
 - ext-pdo + ext-pdo_mysql — recommended for MySQL exports; the plugin falls back to WordPress's wpdb layer when they are unavailable

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
 - ext-mysqli — for MySQL targets
 - ext-pdo + ext-pdo_sqlite — for SQLite targets via sqlite-database-integration

### Coding standards

This repo uses WordPress Coding Standards for PHP. The ruleset lives in
`phpcs.xml.dist`; run `composer lint:php` for the WPCS audit and
`composer lint:php:fix` for the available PHPCS auto-fixes.

The ruleset has temporary exclusions for existing standards debt so cleanup can
happen in focused passes instead of one giant formatting change. New or touched
code should follow WPCS unless the ruleset explicitly excludes that sniff.

Run `composer lint:php:compat` to check the typed exporter source on PHP 7.2+
and the importer on PHP 7.4+. CI builds the exporter plugin ZIP and checks every
PHP file in that generated artifact with PHP 5.6.

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

The preflight contacts the export server and collects environment details: PHP/MySQL versions, memory limits, filesystem access, database connectivity, WordPress version, plugins, themes, and directory layout. The result is stored in `$STATE_DIR/remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/state.json` under the `preflight` key.

Some hosts, including [Hostinger](https://www.hostinger.com/support/2489693-how-to-access-your-website-content-without-a-domain-in-hostinger/),
provide preview domains so you can browse a site before its real domain points
at the host. They replace the real domain in outgoing pages so links and assets
stay on the preview while the stored WordPress home URL stays unchanged.
Hostinger also applies this rewriting to JSON responses.

The server sends a base64 copy of the WordPress home domain to detect this.
For a home URL of `https://example.com:8443/blog`, the domain is `example.com`,
without the scheme, port, or path. If the decoded copy differs from the domain
in the plain home URL, preflight fails and reports both domains. Low-level pull
commands also reject that mismatch in saved preflight data.
Older servers that omit the copy, or report `null` because no domain was available,
remain compatible. The comparison checks the home domain, not every URL in the response.

All other commands check that a preflight has been completed and refuse to start without one.

To run very basic diagnostics that confirms the remote server replied and it has a
sound-looking filesystem and a database connection, run:

```bash
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

For hosting platform-specific checks, such as database version compatibility or
php version compatibility, you might need your own custom logic. See the 
[State and progress files](#state-and-progress-files) section for more details.

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

**File pull modes**

`files-pull`, `pull-files`, and `pull` use `--mode=catch-up` by default.
Catch-up applies paths whose remote index entries changed since the previous
completed pull. A local edit remains when that remote path did not change, and
a path found only in the local tree remains in place.

Use mirror mode when paths inside today's pull selection must match today's
remote index:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --mode=mirror
```

For example, suppose `wp-content/themes/example/style.css` still contains
`blue` remotely but contains `red` locally, and `debug.log` exists only
locally. Catch-up leaves both paths alone when the remote index did not change.
Mirror downloads the remote `style.css` and removes `debug.log` when both paths
are inside the current pull selection.

The normal path options define that selection. This includes `--include`,
`--exclude`, `--filter`, and `--remap`. Mirror does not change paths outside
the selection. `--on-fs-root-nonempty=preserve-local`
also keeps pre-existing local paths which were not recorded by the first pull.

File pulls always omit paths matched by the built-in default skip rules. These
rules cover known generated backup archives, cache, log, upgrade, and temporary
paths, plus version-control metadata, `node_modules`, IDE and package-manager
caches, operating-system metadata, and editor scratch files. `--include`,
`--exclude`, `--filter`, and `--remap` cannot override these omissions.

Mirror mode requires `--state-dir` to be outside `--fs-root`, because the state
files must not appear in the local tree being compared. The selected mode is
saved when `files-pull` starts. To switch modes, first run the same command with
`--abort`, then start it again with the other `--mode` value.

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

The pipeline rewrites this preset to exclude the `:wp-uploads:` path prefix,
then proceeds through the normal index, diff, and fetch stages. When the
essential files are done, the sync marks itself **complete**. At this point you
can apply the database and bring the site online.

```bash
# Step 2: download the uploads
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --filter=skipped-earlier
```

The three filter values:

- `--filter=none` (default): download all files.
- `--filter=essential-files`: skip uploads, download only code/config/themes/plugins.
- `--filter=skipped-earlier`: include only the `:wp-uploads:` path prefix. The
  name is retained for compatibility; a prior `essential-files` run is not
  required.

The uploads directory is detected from preflight data (`uploads.basedir`), falling back to
`wp-content/uploads/` if unavailable.

The presets use the same repeatable path-prefix options available directly on
`files-pull` and `pull-files`:

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --include=:wp-content: --exclude=:wp-uploads:
```

`--include=SOURCE` supplies an included source prefix and `--exclude=SOURCE`
supplies an excluded source prefix. Both accept WordPress path tokens or
absolute source paths, and exclusions win when the prefixes overlap. Switching
filters after a completed run starts a new filtered delta against the shared
remote index; there is no separate skipped-file list or fetch stage.

Symlinks are followed by default. With `--no-follow-symlinks`, a selected
symlink is copied as a link without indexing its target. A selected path reached
through a symlinked parent is rejected; use the default or
`--follow-symlinks` so Reprint can preserve the requested link path and index
the physical target.

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
`files-pull`, including repeated `--include` and `--exclude` values:

```bash
php reprint.phar pull-files "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET" \
    --include=:wp-content: --exclude=:wp-uploads:
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
| `mysql` | Connects through mysqli and executes complete statements one at a time | none |

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
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=php-builtin
bash "$RUNTIME_DIR/start.sh"
```

For nginx + PHP-FPM:

```bash
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=nginx-fpm
# Include $RUNTIME_DIR/nginx.conf in your nginx configuration, then reload
```

The command accepts either `--fs-root` (the raw download directory — the remote
`document_root` path is appended automatically) or `--flat-document-root` (a
directory created by `flat-docroot`, used as-is). These are mutually exclusive.

Host and port default to the URL rewrite target from `db-apply` (so the server
listens on the same address the database was rewritten to). Override with
`--host` and `--port`.

The database written into `runtime.php` is the one `db-apply` connected to. To
point the runtime at a database `db-apply` did not create — a local database you
are keeping, for instance, when you pull the files but not the database — name
it with the same `--target-*` options `db-apply` takes:

```bash
php reprint.phar apply-runtime "$URL" --state-dir="$STATE_DIR" \
    --flat-document-root="$FLAT_DIR" --output-dir="$RUNTIME_DIR" --runtime=php-builtin \
    --target-engine=sqlite --target-sqlite-path="$FLAT_DIR/wp-content/database/.ht.sqlite"
```

The SQLite file itself may be missing — the `sqlite-database-integration` plugin
creates it on the first request — but its directory must exist. The options
apply to this run only; `apply-runtime` does not record them in state.

Every field you leave out falls back to what `db-apply` recorded, as long as the
engine matches. Name the whole connection when you point at a different MySQL
database — otherwise you inherit `db-apply`'s host, port or password.

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

### State and progress files

Reprint uses `$STATE_DIR` exactly as supplied. Consumers that want the state
hidden can choose a directory named `.reprint`; Reprint does not append that
name itself. State-directory-wide command progress and the audit log live
directly in the state directory. The retained local index lives at
`remotes/<md5-of-trimmed-remote-reprint-api-url>/local_index.jsonl`.
`files-pull` advances it for each completed local mutation without scanning
unrelated paths; `files-push` replaces it only after the target confirms
commit. Pull and push operation state live in the sibling `pull/`
and `push/` directories.
State-directory-wide and pull filenames do not begin with a dot or repeat the
scope supplied by their parent directory.

The directory name is `md5(rtrim(<remote-reprint-api-url>, "?&"))`. Commands
which read local pull state receive the same remote Reprint API URL even when
they make no network request; the URL selects the remote state directory. The
filesystem root is not part of that directory name, so a different filesystem
root uses a different state directory.

`<remote-state-directory>/pull/state.json` and the state-directory-wide
`progress.json` are written atomically:
a `.tmp` file is written first and then renamed to its final name so readers
never see partially written state.

While there's many of these files, most of them are for internal use only.
The two that might be particularly useful for integrators are:

* `progress.json` – the current progress
* `<remote-state-directory>/pull/state.json` – the pull state store

#### `progress.json` – the current progress

When an external process (e.g. a web UI) needs to poll migration progress, it can read
`$STATE_DIR/progress.json`.

Pass `--step=N` and `--steps=N` to your `import.php` calls to embed the pipeline position in
the progress file. For example, a four-step pipeline would pass `--step=1 --steps=4` for the
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
| `phase`   | `string \| null`  | Sub-phase within the command (e.g. `index`, `diff`, `fetch`), or `null`. Derived from the internal state's `stage` field. |
| `error`   | `string \| null`  | Error message when `status` is `error`, otherwise `null`. |
| `ts`      | `float`           | Unix timestamp with microsecond precision (`microtime(true)`). |

During the file fetch phase, progress and heartbeat records also include
structured file counters:

| Field         | Type           | Description |
|---------------|----------------|-------------|
| `files_done`  | `int`          | Files already processed (cumulative across restarts). Derived from the fetch list byte offset plus the current batch count. |
| `files_total` | `int`          | Total non-empty entries in the fetch list. Fixed once the diff phase completes. |

Both fields are emitted together only when the fetch list exists — they
are absent during the index and diff phases. `files_done` grows monotonically
up to `files_total` and survives exit-code-2 restarts.

Every command run by `ImportClient` accepts `--progress=auto|tty|jsonl`. The
default `auto` mode uses terminal progress when its output stream is a TTY and
JSONL otherwise. Use `--progress=tty` to force the terminal presentation when
output is captured, or `--progress=jsonl` to force structured progress in a
terminal:

```bash
php reprint.phar files-push "$URL" --state-dir="$STATE_DIR" \
    --fs-root="$FS_ROOT" --secret="$SECRET" --progress=jsonl
```

The selected mode applies only to that invocation and is not retained in
command state. Explicit `tty` and `jsonl` modes cannot be combined with
`--verbose`.

The selector governs progress, lifecycle, and status output. It does not
reformat a command's data result, such as preflight or pull-metadata JSON,
files-stats JSON, or SQL written with `--sql-output=stdout`.

The files-push terminal presentation uses one stage-weighted progress bar. The
percentage comes first, followed by a major stage such as `Indexing`, `Pushing`,
or `Committing`. While pushing local paths, the line also shows target-confirmed
file bytes against the file byte total collected by the plan. Durable index byte
offsets, target-confirmed counts and byte offsets, and phase milestones advance
the bar. The percentage describes lifecycle progress, not elapsed time or an
estimated completion time.

These terminal-only details do not change machine output. The JSONL
presentation emits `push_progress` records. After planning completes, those
records, the final result, and `progress.json` include `files_done` and
`files_total` together. `files_total` is the number of local paths selected by
the plan; `files_done` advances only after the target confirms the request
containing each path, and both counts survive exit-code-2 restarts. The fields
are absent while the plan is still being built.

#### `<remote-state-directory>/pull/state.json` — the pull state store

This is the pull state store. Pull commands read it on startup and write it
back periodically and on shutdown. It stores the current command, cursor
position, AIMD tuning state, and per-phase bookmarks. Direct MySQL output also
records each committed cursor in the target database.

Written atomically (temp file + rename) so a crash mid-write never corrupts it.
If the JSON is invalid on load, the importer renames it to
`state.json.corrupt.<timestamp>` in the same pull state directory and starts
fresh.

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
    "index_diff_cursor": {
      "old_index_byte_offset": 512,
      "new_index_byte_offset": 1024,
      "preceding_new_index_entry_path_b64": "base64..."
    },
    // Output bytes covered by the index-diff cursor.
    "fetch_list_byte_offset": 256,
    "pull_index_wal_byte_offset": 128
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

For pull lifecycle and source-site details, prefer `pull-metadata` over reading
`<remote-state-directory>/pull/state.json` directly. It exposes a small, stable
JSON contract for host integrations:

```bash
php reprint.phar pull-metadata "$URL" --state-dir="$STATE_DIR" | jq '.hasCompletedOnce'
```

The `sourceSite` object contains the source WordPress home URL, site URL, table
prefix, WordPress database charset, and database server charset reported by
preflight. Each field is `null` when preflight did not report it.

#### `<remote-state-directory>/pull/volatile-files.json` — files that changed during sync

During `files-pull`, a file on the source may be modified while the importer is
streaming it. When that happens, the server returns a different content hash than
expected and the importer records the file in
`<remote-state-directory>/pull/volatile-files.json`
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

#### `audit.log` — append-only event log

Every significant event during import is recorded in `audit.log` as a
timestamped line. This includes file downloads, deletions, volatile file
detections, errors, and state transitions. The log is append-only — it's never
truncated or rotated, so it provides a complete history of the migration.

```
[2025-01-15 10:30:01] VOLATILE | path=/srv/htdocs/wp-content/debug.log | count=1
[2025-01-15 10:30:05] VOLATILE CLEARED | path=/srv/htdocs/wp-content/debug.log
[2025-01-15 10:31:12] FILE TRUNCATE | /tmp/reprint-state/remotes/0123456789abcdef0123456789abcdef/pull/index.wal | pull index WAL batch applied
```

Pass `--verbose` to also print audit log entries to the console as they happen.
This is useful for debugging but noisy for production use.

### Low-level CLI commands

The importer accepts the following commands:

```
php reprint.phar <command> <URL> --state-dir=DIR --fs-root=DIR [options]
```

* `preflight` — Runs the preflight check and prints the full result as JSON. Exits with code 0 if OK, code 1 if not.
* `preflight-assert` — Runs the preflight check and prints a human-readable pass/fail summary in terminal mode or one structured result in JSONL mode. Exits with code 0 if migration looks feasible, code 1 if not.
* `pull-files` — Runs `preflight` and `files-pull` as one resumable high-level command.
* `pull-db` — Runs `preflight`, `db-pull`, and `db-apply` as one resumable high-level command.
* `files-pull` — Pull all files (initial) or a delta. Catch-up mode applies remote changes; mirror mode makes the selected local paths match the current remote index. Runs files-index if needed.
* `files-index` — Index all remote files (initial) or detect changes (delta). No file contents downloaded.
* `db-pull` — Pull the database as a SQL dump. Defaults to writing `db.sql`; use `--sql-output=stdout` or `--sql-output=mysql` to stream elsewhere.
* `db-apply` — Applies `db.sql` to a target MySQL or SQLite database. Both engines continue from the file group named by the cursor stored in the target. Accepts `--rewrite-url FROM TO` (repeatable) to rewrite domains during import.
* `db-rewrite-urls` — Rewrites URLs directly in an existing MySQL or SQLite database. You can stop it and continue later. See [Rewrite URLs in a live database](docs/DB-REWRITE-URLS.md).
* `db-index` — Indexes database tables and their statistics (name, row count, size) to `db-tables.jsonl`.
* `pull-metadata` — Prints pull lifecycle and source-site metadata as JSON. The remote Reprint API URL selects the pull state; no network calls are made.
* `flat-docroot` — Reassemble pulled files into a standard WordPress directory layout using symlinks. Useful when the source site has a non-standard layout (e.g. WP Cloud with ABSPATH separate from wp-content).
* `apply-runtime` — Generates server configuration files (`runtime.php`, `start.sh` or `nginx.conf`) from the pull state selected by the remote Reprint API URL. No network calls are made. See [Step 6](#step-6--generate-runtime-configuration).

All commands except `preflight-assert` support `--abort` to abort the current sync and exit. For `files-pull`, this clears sync progress but keeps the remote index and downloaded files — the next run performs a delta sync. For `db-pull` and `db-index`, it clears the output file so the next run starts from scratch. Interrupted commands automatically resume from the last saved cursor.
