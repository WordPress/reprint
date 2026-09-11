# Move one site out of a network

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

In Network Admin → Settings → Reprint Server, set a network connection token.
A site's ordinary administrator cannot set or read that token. Use the selected
site's home URL with ?reprint-api, not the network's home URL.
Preflight sends the selected site's URL bases and a separate list of child-site
paths below those bases on the same host. It does not list other domains or
pages. The URL map does not grow with network size.

Run the pull with an empty MySQL target database, --new-site-url, and
--site-admin=LOGIN. LOGIN must name a user included in the selected site's
export. This explicitly adds the administrator role to that user at the target;
it keeps their existing roles and direct capabilities. Other imported users
keep their selected-site roles. Source superadmins are not copied merely
because they administer the source network.

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

## What moves

Core site tables; members and users referenced by posts, comments, or links;
core profile fields and the selected site's roles; selected network settings;
shared core, plugin, theme, language, and mu-plugin code; selected media.
Users keep their IDs and password hashes, but sessions and application
passwords do not move. LOGIN uses the target database's normal login matching,
so a different letter case still selects the same imported user ID.

Network-active plugins join the site's active_plugins list, without duplicate
entries. Reprint stays inactive. Host plugins stay enabled by default; use
--exclude-host-plugins to omit the listed source-host plugins from the files
and activation list.
The site's language inherits the network language only when it had no WPLANG
option; an explicit site language, including English, stays unchanged.
The filtered network tables remain in the database for repeatable cleanup.
Single-site WordPress does not use those tables or expose Network Admin.

A non-main site's uploads keep wp-content/uploads/sites/ID after migration.
Both existing attachment URLs and new uploads use that path. Both HTTP and
HTTPS links to selected pages and media move to the new URL. Media belonging
to other sites stays on the source.

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
memory cost, not a fixed bound independent of network size. The source reads
rows through MySQLi without a buffered result or a PHP object per row. It does
this only at preflight, not for every SQL/file request.

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

## Source user table and resume

Exporting site 7 creates `network_7_reprint_users` on the source. Site 8 uses
`network_8_reprint_users`; site 1 uses `network_reprint_users`. These tables
never enter the SQL dump or database index. Core WordPress tables and indexes
are not changed.

The source needs `pdo_mysql`, a direct connection using its WordPress database
credentials, and SELECT, CREATE, DROP, INSERT, and UPDATE privileges. The shared
wpdb fallback is not used for selected-site exports: its reads and writes may
use different connections or share a plugin's transaction. Saved IDs must
commit before their export cursor is sent.

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

The table-walk cursor changed. Exports started with the earlier discovery-source
cursor cannot resume; start a fresh database export after updating the source plugin.

## What needs separate work

Plugin-defined tables and shared plugin settings need explicit migration rules.
Unknown tables stop the pull. Unknown network settings and non-core user
metadata are not copied; plugins that depend on those values need separate
configuration at the target. Plugins that require multisite APIs cannot run
unchanged on a single site. Shared plugin directories are copied in full;
review plugins that store private data beside their code before migrating.
Cross-site content references cannot work locally when the referenced site
was not moved.

This first version rejects legacy blogs.dir uploads, custom upload or content
directories, shared custom user tables at the source, and symlinks in selected
paths. It does not copy cache or database drop-ins. It does not support a SQLite
target, merging into an existing database, or pushing into a multisite network.

The source is not a transactionally frozen snapshot. Pause writes for the final
migration if a point-in-time copy is required. The pull does not delete the
source site or change DNS.
