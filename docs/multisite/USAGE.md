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

This replaces the unmerged one-site-network preview and its --network-admin
option. Start with a fresh state directory and empty database when switching
from that preview; its saved apply state is not a single-site migration.
The source export protocol is unchanged.

The new site URL must have an HTTP(S) DNS host name (localhost is accepted)
and no path, query, or fragment. Use its ASCII form (xn-- for international
names), without a trailing dot. Numeric target hosts are not supported by
the plain-text URL rewriter.
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
entries. Reprint and the usual source-host plugin exclusions stay inactive.
The site's language inherits the network language only when it had no WPLANG
option; an explicit site language, including English, stays unchanged.
The filtered network tables remain in the database for repeatable cleanup.
Single-site WordPress does not use those tables or expose Network Admin.

A non-main site's uploads keep wp-content/uploads/sites/ID after migration.
Both existing attachment URLs and new uploads use that path. Links to sibling
sites remain links to the source network. They do not turn into local pages.
Both HTTP and HTTPS links to selected pages and media move to the new URL;
links to sibling pages and media keep their original scheme and address.

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
