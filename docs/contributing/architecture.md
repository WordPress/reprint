# Architecture and durable state

Read the [public explanation](../handbook/how-it-works.md) for the user model.
This document is for changes spanning the client, server, state, or runtime.
Small local rules belong beside their implementation and in regression tests.
Use the [terminology contract](push-terminology.md) for exact path and lifecycle names.

## Client and server

[ImportClient](../../packages/reprint-client/src/import.php) drives pull workflows.
The source [HTTP server](../../packages/reprint-server/src/class-http-server.php)
authenticates and dispatches requests; it does not schedule a background migration.
The [pull protocol](pull-http.md) describes request fields and response framing.

The client offers both a high-level pull pipeline and smaller commands. The
pipeline coordinates saved preflight, file transfer, database download and apply,
flattening, and runtime setup. Keep that coordination in the pipeline rather
than requiring integrations to duplicate it. The public
[integration guide](../handbook/build-with-reprint.md) recommends that path first.

## State is not a second public API

The caller supplies one state directory per filesystem root. Commands addressing
one remote Reprint API URL share its remote state directory. Changing the
filesystem root while retaining that state is rejected. Local command workflows
use the state-directory-wide Reprint process lock.

```text
<state-dir>/
  process.lock
  progress.json
  audit.log
  remotes/<md5-of-trimmed-remote-reprint-api-url>/
    key.pem
    local_index.jsonl
    pull/
      state.json
      remote-index.jsonl
      remote-index.next.jsonl
      index.wal
      fetch-list.jsonl
      volatile-files.json
    push/
```

Use the [state classes](../../packages/reprint-client/src/lib/state/) and
[terminology contract](push-terminology.md) when changing these records. They
contain cursors, tuning state, and per-command checkpoints. Do not reproduce
all private fields as an integration schema. `pull-metadata`, command reports,
and the documented `progress.json` shape are the public inspection surfaces.

The pull index WAL records completed local actions awaiting projection into
remote and local indexes. Resume must retain or replay the unconfirmed suffix;
bytes beyond a durable offset are not completed work. The next remote index,
fetch list, and file cursors are streamed instead of loaded as complete arrays.
Files-diff and files-push must not consume an unfinished files-pull WAL.

A changed source file is tracked for a later file pass. Successful later
fetches clear that file's volatile record. File-fetch errors use the same path
mapping and selection as file data; they must not remove arbitrary local paths.
Existing physical parents are checked before local removal or replacement.

For file push, [PushFilesSender and PushPlan](push.md) share one caller-visible
lifecycle. Read their interruption and target-confirmation rules before changing
checkpoint timing. Database push has its own
[full-overwrite lifecycle](database-push.md).

## Source paths and filesystem writes

Source path format comes from preflight, not a guessed path prefix. The same
text can mean a Unix path or a Windows share. Relative selections and resolved
symlink targets need different handling; keep those concepts separate.
[RemoteToLocalPathMapper](../../packages/reprint-client/src/lib/pull/class-remote-to-local-path-mapper.php)
applies the saved mapping before writes.

Windows reads use PHP file functions, not FFI or an external program. Long UNC
paths use a PHP-supported namespace spelling at I/O time without changing the
index's path coordinates. Keep the user-visible path restrictions and destination
limits in [Windows sources](../handbook/windows-sources.md). The Windows migration
workflow covers drive and share sources against a Linux destination.

## Source hosts and target runtimes

[Host analyzers](../../packages/reprint-client/src/lib/host/) read preflight data
and produce a declarative RuntimeManifest: constants, INI settings, and routes.
[Runtime appliers](../../packages/reprint-client/src/lib/target-runtime/) write
configuration for PHP's built-in server, nginx/PHP-FPM, or Playground CLI.
This keeps source-host detection separate from destination server generation.

Download selection and local runtime cleanup are separate choices. A preserving
pull can retain source-host files, then runtime setup can remove their local
copies. Cleanup records those document-root-relative paths for file-push
exclusions before removing files. It must not teach a later push to delete the
source host's own plugins. See [File transfer reference](../handbook/file-transfers.md)
for the public options.

For Playground CI, `PHP_BINARY=tests/e2e/ci/playground-php.sh` selects the wrapper.
`WP_MYSQL_PARSER_EXTENSION_MANIFEST` selects the local PHP.wasm runner so it can
load the SQLite integration's native parser extension. The
[verification script](../../tests/e2e/ci/verify-wp-mysql-parser.php) checks the
active lexer and parser, not just whether an extension loaded.

## Streaming CSS rewriting

The caller feeds `append_bytes()`, scans with `next_url()`, edits with
`set_raw_url()`, and marks the real file end with `input_finished()`. Completed
output is flushed after each URL. The shared parser cursor is an opaque string
with no source bytes. An HTTP part can end inside a URL, so Reprint saves its
unfinished bytes separately beside that cursor. Resume supplies those bytes
before appending the next HTTP part. A URL split across requests is rewritten once.

The processor keeps each unfinished CSS token and parses it again when more
bytes arrive. A large comment or embedded image therefore increases memory
use and saved state size; token size is not capped. Completed input is released.
Malformed string and URL tokens stay unchanged. More than 128 nested
`image-set()` functions are rejected. An error names the stylesheet; the
partial file is not marked complete.

## Database import checkpoints

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

### Imported WordPress cache cleanup

After applying the dump, `db-apply` removes `stylesheet_root`, `template_root`,
`rewrite_rules`, the database-backed site transients `theme_roots`, `update_core`,
`update_plugins`, and `update_themes`, and the `dirsize_cache` transient from the
imported WordPress options table. Transient timeout records are removed too.
WordPress can rebuild these caches for the local files and plugins; later update
checks may make network requests. The selected theme, theme settings, permalink
structure, custom upload paths, scheduled jobs, and locks stay unchanged. This
cleanup does not load WordPress or flush an external object cache.

## Multisite export

These notes explain the selected-site export and URL-mapping implementation.
For the task and its user-visible limits, use the [multisite guide](../handbook/multisite.md).

### Resulting site configuration

A pull from https://network.example/shop/?reprint-api selects the shop.
It creates a single-site WordPress install, not a new network. If the shop
uses network_7_posts, the target adopts `$table_prefix = 'network_7_'`.
No tables are renamed. Post, attachment, and user IDs stay the same.
WordPress reports its usual single-site blog ID of 1; source site IDs in
plugin data are not rewritten.

The shared network_users and network_usermeta tables keep their names.
The generated configuration uses CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE
to select them. Only the chosen site's users and allowed profile fields are
exported, not every network user. Capability keys and the roles option already
match the adopted table prefix.

The source can use MySQL or SQLite Database Integration 2.x/3.x. Reprint uses
the source's active driver. The target uses the bundled SQLite driver.
SQLite-to-MySQL imports still require a target that supports the source's
collations; MySQL 8's `utf8mb4_0900_ai_ci` is not supported by older MariaDB.

Direct `db-pull --sql-output=mysql` is rejected for multisite. Use `pull-db`,
or download with `db-pull` and apply with `db-apply`, so the target checks and
single-site setup run before the site is used.

Run the same command again to resume an interrupted apply. After initialization
starts, keep the same target database, URL replacements, and site administrator.
Until the target records its first SQL group, resume rechecks the target; only
an empty Reprint progress table is allowed. Each apply acquires the target
database lock before checking or changing tables.

The new site URL must be an HTTP(S) origin without credentials, a path, query,
or fragment. The URL parser normalizes scheme and host case, default ports,
and international names (café.test becomes xn--caf-dma.test). IP addresses are
accepted, including IPv4 aliases such as 127.1, which becomes 127.0.0.1.
These choices are checked at the command boundary before target tables change.

HTML, CSS and block URL fields use their format's serializer. The unknown-text
fallback has narrower output limits: it leaves a URL unchanged if the new host
needs escaping, for example a quoted host or IPv6 brackets. That limitation
never rejects the migration destination. IPv4 and a trailing dot are supported
by the fallback too.

Use apply-runtime (included in pull) to write the new wp-config.php. Source
database credentials, salts, Reprint tokens, network constants, and custom
bootstrap includes are not used in that configuration.

### Child-site URL mapping and large-network costs

Page links follow the selected home/site URL bases. Selecting
`shop.network.test` rewrites its pages, not pages on `news.network.test`.
Selecting `network.test/shop` rewrites `/shop/article`, not `/news/article`.
A root-relative `/news/article` becomes an absolute source link so it does not
point at the target after migration.

For overlapping paths, preflight reads the source site directory. Selecting
`network.test/` keeps `/news/article` remote when `/news/` is another site.
Selecting `/shop` also keeps `/shop/news/article` remote when `/shop/news/` is
another site, but still rewrites `/shop/newsletter`. Relative child-site links
become absolute source links. Update the source plugin to get this path list;
an older source without it still applies the broad selected-base rule.

The importer saves the list once in a separate, immutable JSON file under the
remote's pull-state directory. Progress saves contain its file name, not its
paths. SQL apply keeps the same file across interruptions, even if a later
preflight finds different paths. Each new apply process loads the list once
and builds a hash set. Each URL checks its path segments against that set,
stopping at the longest stored path instead of hashing ever-longer prefixes.
It does not scan the site list. Older list files remain in the state directory;
keep that directory until the migration is complete.

Upload filtering uses two prefixes for each content URL and HTTP(S) scheme.
For site 7, keep `uploads` on the source, then rewrite `uploads/sites/7`.
For site 1, rewrite `uploads`, but keep `uploads/sites` on the source.
The longer matching prefix wins. A prefix must end at a path boundary, so
`sites/7` does not match `sites/70` or `sites/7-other`. These rules cover media
from any other site without adding an entry for that site.
The plain-text scanner retains one next match per rule. It searches again only
when the cursor passes that match. A rule with no remaining match is finished,
so it does not rescan the rest of a large value for every other URL.

The generated URL map has at most 20 entries. One million other-domain records
do not enlarge preflight. One million matching 20-byte child paths need about
62 MiB for the PHP list and 86 MiB for the lookup set on PHP 8.4. Building the
set temporarily holds both. JSON handling also needs space; this is an accepted
memory cost, not a fixed bound independent of network size. The source uses
the wpdb adapter with at most 1,000 buffered rows per query. The first query
uses domain/path filters; it can scan and sort many rows. Further batches scan
primary-key windows, including non-matches, rather than repeatedly sorting the
remaining matches. This runs only at preflight, not for every SQL/file request.

Child-path lookup and selected-site SQL export work on MySQL and SQLite.

The large-network tests cover both directory cases, then run a real SQL import
with a million child paths. They also check that progress JSON stays small and
URL matching stays fast. These tests create directory rows, not a million sets
of WordPress tables.

The whole migration still has memory and scan limits. Source setup loads all
database table names with `SHOW TABLES` on each request to reject unsupported
plugin tables. SQL export also uses `SHOW TABLE STATUS` for the whole database;
its PDO query is buffered by default. The URL rewriter works on one database
value at a time, but that value can itself be large. A network with millions
of tables is not yet safe from OOM or long table-discovery queries.

A custom `--rewrite-url` rule does not change the base used for relative HTML
links. For example, adding a CDN rule still resolves `about/page` against the
selected site's home URL. Generated site rules take precedence when a custom
rule repeats the same source base.

Child-site path checks ignore ASCII case, like WordPress's normal site-directory
collation. `/shop/NEWS/page` stays at the source when `/shop/news/` is a child
site. Known HTML, CSS and block URL fields use the toolkit URL parser:
`/shop/a;b/../news/page` reaches that same child. Legal path punctuation does
not end a URL. Each lookup checks path prefixes, not the full site list.

Unknown text has no reliable URL boundary or escape rules. When a host has
child-site exclusions, its URLs remain unchanged in such text, including
selected-site URLs. For example, an HTML `href` to `/shop/about` still moves,
but the same absolute URL in an unsupported plugin field stays remote. Hosts
without child-site exclusions retain the cautious source-base replacement.
This fallback no longer scans or copies a path suffix to guess a site's scope.

STYLE bodies use the CSS parser. Non-block markup also keeps raw source-base
replacement. For source hosts without child-site exclusions, that fallback can
change source URLs inside query values, CSS strings and comments. Changed URL
attributes use HTML escaping. Block attributes use the block parser's JSON
encoder; whitespace and escaping can change.

A failed preflight report remains saved for diagnosis. `db-apply`,
`db-rewrite-urls`, and `apply-runtime` reject that saved error before using its
source data. Local SQL commands can still run without a preflight report.

### Source user table and resume

Exporting site 7 creates `network_7_reprint_users` on the source. Site 8 uses
`network_8_reprint_users`; site 1 uses `network_reprint_users`. These tables
never enter the SQL dump or database index. Core WordPress tables and indexes
are not changed.

A MySQL source needs `pdo_mysql`, a direct connection using its WordPress database
credentials, and SELECT, CREATE, DROP, INSERT, and UPDATE privileges. The shared
wpdb fallback is not used for selected-site exports: its reads and writes may
use different connections or share a plugin's transaction. Saved IDs must
commit before their export cursor is sent.

A SQLite source uses its active translator and native connection, with no open
transaction. Saved IDs commit before the cursor is sent. Each site holds a
separate file lock beside the database, for example
`.ht.sqlite.network_7_reprint_users.lock`. SQLite targets hold
`database.sqlite.reprint-import.lock` across the empty check and SQL commits.
The SQLite source lock file also stores the fixed-size export token because
older SQLite drivers discard table comments. A fresh export replaces this token
before it replaces the saved rows; no cursor with the new token is sent until
the table is ready. Both require a writable database directory and working
filesystem locks.
The locks release on close or process death. Leave the lock files in place;
removing one while another process uses it would allow two separate locks.
In-memory databases cannot resume across requests and are rejected.

Content tables finish first. Each batch of posts, comments or links saves its
user IDs and source row IDs before sending SQL. A partial export still visits
omitted content tables in that same walk, reading only their IDs. Row-filtered
content collects its IDs before exporting the permitted rows; exclusions do
not remove those users from the site's export.

Next, a separate stage reads network usermeta for the selected capabilities
key, adding members with no content. It reads one primary-key window at a time
and saves last_scanned_usermeta_id. It does not load profile values into PHP.
There is no second list of content tables to discover or resume.

Users and allowed profiles go last. Membership collection is not entered again
for either table. Their queries use primary-key lookups into the saved table,
rather than repeating full comment and link scans per batch. Each membership
batch and the boundaries before and after this stage have a resume cursor.

Each table keeps one row per distinct discovered user, not one row per comment.
Storage grows with those users, rather than being allocated upfront. There is
no fixed disk reservation; the database must have room for the set. Reprint
keeps only one export's set per site. Starting a fresh export replaces it and
makes older cursors fail. Requests that overlap for the same site are rejected
without waiting. Other sites can export at the same time.

The table stays after completion so a lost final response can be replayed.
The next fresh export replaces completed or abandoned rows. It is also safe to
drop the site's `reprint_users` table when no export request is running, but
that discards the ability to resume that export.

One source reference is saved per user and checked again by primary key during
user/profile reads, including oversized value chunks. If that reference
changes, the export stops. Another valid relationship might still exist;
restart to discover it. Relationships added behind a discovery cursor also
require a fresh export. This is not a frozen source snapshot.

### Plugin tables

For a numbered site, plugin tables such as `network_7_wc_orders` move in full,
without renaming. Tables under `network_8_` or `network_70_` stay behind and do
not block site 7's export. Existing core filters still apply to shared users,
usermeta and the selected site's options. Reprint's saved user tables stay out.
Rows use the existing batched, resumable SQL reader, including oversized values.

This uses the plugin convention of building site-local table names from
`$wpdb->prefix`. A prefix does not prove that a plugin stores only that site's
private data. The current flow requires network administrator authorization; this rule must not
be treated as permission to expose arbitrary plugin data to site-only admins.

Unnumbered plugin tables such as `network_orders` still stop the pull, even
when selecting site 1: they may contain main-site or shared network data.
They need explicit migration rules, as do shared plugin settings.

The plugin-table E2E test installs WooCommerce with custom order tables and
post-table synchronization disabled. It reads an imported order and creates
a new one after booting the single-site target. This does not establish full
WooCommerce compatibility: customer usermeta, shared extensions, external
services, and plugin-specific site IDs still need separate migration rules.
Users referenced only by plugin tables are not added to the exported user set.
