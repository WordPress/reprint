# Architecture and durable state

Use this page when changing how the client downloads data, writes local files,
or continues a stopped transfer. Start with
[How Reprint works](../handbook/how-it-works.md) for the reasons behind the design.

## Find the code for your change

| What you want to change | Where to start |
| --- | --- |
| A command or the order of migration steps | The [client entry point](https://github.com/WordPress/reprint/blob/trunk/packages/reprint-client/src/import.php). |
| How the source receives a request | The [server request handler](https://github.com/WordPress/reprint/blob/trunk/packages/reprint-server/src/class-http-server.php) and [pull protocol](pull-http.md). |
| Where a downloaded path is written | The [source-to-local path mapper](https://github.com/WordPress/reprint/blob/trunk/packages/reprint-client/src/lib/pull/class-remote-to-local-path-mapper.php). |
| Settings copied from a source host | The [source-host analyzers](https://github.com/WordPress/reprint/tree/trunk/packages/reprint-client/src/lib/host). |
| Generated PHP or web-server configuration | The [target runtime code](https://github.com/WordPress/reprint/tree/trunk/packages/reprint-client/src/lib/target-runtime). |

The client starts each migration step and asks the source for data. The source
answers the request, then releases its PHP worker. Installing the plugin does
not start a background migration. Keep the complete `pull` workflow in the
client so products can use one command rather than rebuild the same sequence.

## Keep progress after a process stops

Suppose the client has downloaded part of a large image when the process stops.
The next process needs to know which bytes are saved and which source to ask
for the rest. A **cursor** is that saved position. It may contain a byte offset,
a database key, or the position in an index.

Use one state directory for each local filesystem root. The filesystem root
contains the downloaded site files. The state directory contains credentials,
cursors, indexes, and logs. Keep state outside the downloaded files so a pull
cannot replace the information needed to continue it.

Within state, the remote Reprint API URL selects the records for that source.
Changing the URL can select different records. Reusing the same state with a
different filesystem root is rejected: its saved positions describe the first
copy, not the new directory.

For product integrations, read progress through `pull-metadata`, command
reports, or the documented `progress.json` fields. See
[Progress, results, and exit codes](../handbook/progress-and-results.md).
Private state files can change when the implementation changes; do not make a
product depend on their current layout.

## Keep file indexes in step with completed writes

A file pull keeps two indexes. The remote index records source paths and source
change fields. The local index records paths and change fields observed on the
receiving computer. They are used for later file comparison.

A file write and both index updates cannot happen as one filesystem operation.
For example, a process can stop after replacing the remote index but before
replacing the local index. Clearing its update records at that point would
leave the local index missing completed changes.

The pull index WAL is a write-ahead log at `pull/index.wal`. It records completed
local changes until both indexes include them:

1. Apply a downloaded file, directory, link, or deletion locally.
2. Record that completed change in the pull index WAL.
3. Apply the recorded changes to the remote index and then the local index.
4. Clear the records only after both index replacements finish.

Resume and abort replay the saved batch if either index replacement was
interrupted. Read the indexes and path lists in order from disk; loading an
entire site index would make memory use grow with the site's file count.
`files-diff` and `files-push` must wait until an unfinished file pull has finished
or been aborted, so they do not compare against a partly updated local index.

If a source file changes while it is being read, the client records it for a
later pass. A successful later download removes it from that list. A failed
file response still has to pass the same path checks as a successful response;
an error must not cause removal of an unrelated local file.

## Check where a path will actually write

Suppose the copy lives at `/work/files`, but `/work/files/uploads` is a symlink
to `/srv/shared`. Writing `/work/files/uploads/photo.jpg` would change a file
outside the copy. Removing that path would have the same problem.

Before writing, replacing, or removing a local path, resolve its existing
parent directories and check that the result stays inside the filesystem root.
Removing a symlink itself must remove the link, not the file it points to.
Cover both cases when changing path handling; the
[symlink path tests](https://github.com/WordPress/reprint/blob/trunk/tests/Import/UnixSymlinkPathTest.php) and
[file-error path tests](https://github.com/WordPress/reprint/blob/trunk/tests/Import/PullFileErrorPathTest.php) exercise them.

Read the source path format from preflight. For example, `//server/share` may
be a Windows network share or a Unix path. Its spelling alone cannot tell you
which rules to apply. The mapper uses the reported format before converting
source paths to local paths. See [Windows sources](../handbook/windows-sources.md)
for the supported selections and destination limits.

## Separate host settings from destination setup

A source host can supply plugins, PHP settings, or image routes that are absent
on the receiving computer. Host analyzers turn those findings into a description
of the settings the copy needs. Runtime code uses that description to generate
files for PHP's local server, nginx/PHP-FPM, or Playground CLI.

Keep these two jobs separate. Adding support for another source host should
not require a separate implementation for every destination server.

Host-plugin cleanup can remove a local plugin that still exists on the source.
Without an exclusion, a later file push could treat that absence as a request
to delete the source plugin. Runtime setup therefore saves the removed
paths before deleting their local copies, and file push excludes those paths.
Keep those exclusions through command resets and database imports. Skipping a
later cleanup does not bring removed files back.

## Continue a stylesheet across requests

An HTTP response can end halfway through a CSS URL. Save both the parser's
position and the unfinished text. On resume, give the parser that unfinished
text before reading the next response. Otherwise a split URL can be missed or
rewritten twice.

The file downloader supplies successive byte strings to the CSS parser and
writes completed output after each URL. Mark input complete only at the real
file end, not at the end of one HTTP part. The parser position contains no
source text; the unfinished text is saved separately.

A long comment or embedded image can make the unfinished token large, so this
part of the parser has no fixed memory bound. Malformed tokens stay unchanged.
More than 128 nested `image-set()` functions stop that stylesheet, and the
partial download is not marked complete.

## Database import checkpoints

A SQL group contains complete SQL statements and an exporter cursor. The
importer saves that cursor after applying the group, so the next process knows
which statements still need to run.

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
`__reprint_db_pull_progress_<uuid>`. The UUID separates this progress table from site tables and from older
versions of the progress-table schema. Reprint logs and excludes
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

Use this section when changing how one subsite becomes a single-site copy.
For the commands and destination checks, follow the [multisite guide](../handbook/multisite.md).

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

### Keep links to other sites on the source

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
preflight finds different paths. Otherwise identical URLs could be rewritten
differently before and after an interruption. Each new apply process loads the
list once and builds a hash set. Each URL checks its path segments against that set,
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

When changing path lookup, test it with a large network. The generated URL map
has at most 20 entries. One million other-domain records
do not enlarge preflight. One million matching 20-byte child paths need about
62 MiB for the PHP list and 86 MiB for the lookup set on PHP 8.4. Building the
set temporarily holds both. JSON handling also needs space. Account for both lists when estimating
memory use for a large network. The source uses
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

Users and allowed profiles go last. Do not repeat membership collection when resuming either table; their saved
user set already identifies which rows to export. Their queries use primary-key lookups into the saved table,
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
private data. Only a network administrator can authorize the export. Do not grant this
access to a subsite administrator: a plugin may have put other sites' data
in the same table.

Unnumbered plugin tables such as `network_orders` still stop the pull, even
when selecting site 1: they may contain main-site or shared network data.
They need explicit migration rules, as do shared plugin settings.

The plugin-table E2E test installs WooCommerce with custom order tables and
post-table synchronization disabled. It reads an imported order and creates
a new one after booting the single-site target. This does not establish full
WooCommerce compatibility: customer usermeta, shared extensions, external
services, and plugin-specific site IDs still need separate migration rules.
Users referenced only by plugin tables are not added to the exported user set.
