# Move one site out of a network

A pull from https://network.example/shop/?reprint-api selects the shop.
It creates a fresh one-site network. Site IDs, user IDs, and table names stay
the same. If the shop was site 7, the new network's main site is site 7.

In Network Admin → Settings → Reprint Server, set a network connection token.
A site's ordinary administrator cannot set or read that token. Use the selected
site's home URL with ?reprint-api, not the network's home URL.

Run the pull with an empty MySQL target database, --new-site-url, and
--network-admin=LOGIN. LOGIN must name a user included in the selected site's
export. This is an explicit grant of access to the new network. Other source
superadmins are not copied merely because they administer the source network.

Direct `db-pull --sql-output=mysql` is rejected for multisite. Use `pull-db`,
or download with `db-pull` and apply with `db-apply`, so the target checks and
network setup run before the site is used.

Run the same command again to resume an interrupted apply. After initialization
starts, keep the same target database, URL replacements, and network administrator.
Until the target records its first SQL group, resume rechecks the target; only
an empty Reprint progress table is allowed. Each apply acquires the target
database lock before checking or changing tables.

The new site URL must have an HTTP(S) DNS host name (localhost is accepted)
and no path, query, or fragment. Use its ASCII form (xn-- for international
names), without a trailing dot. Numeric target hosts are not supported by
the plain-text URL rewriter.
Use apply-runtime (included in pull) to write the new wp-config.php. Source
database credentials, salts, Reprint tokens, and custom bootstrap includes
are not used in that configuration.

## What moves

Core site tables; members and users referenced by posts, comments, or links;
core profile fields and the selected site's roles; selected network settings;
shared core, plugin, theme, language, and mu-plugin code; selected media.
Users keep their IDs and password hashes, but sessions and application
passwords do not move. The chosen user becomes the target network administrator.
The saved administrator name uses the spelling in the imported user record,
even when MySQL matches a different case in LOGIN.

A non-main site's uploads keep wp-content/uploads/sites/ID after promotion.
Both existing attachment URLs and new uploads use that path. Links to sibling
sites remain links to the source network. They do not turn into local pages.
Both HTTP and HTTPS links to selected pages and media move to the new URL;
links to sibling pages and media keep their original scheme and address.

## What needs separate work

Plugin-defined tables and shared plugin settings need explicit migration rules.
Unknown tables stop the pull. Unknown network settings and non-core user
metadata are not copied; plugins that depend on those values need separate
configuration at the target. Shared plugin directories are copied in full;
review plugins that store private data beside their code before migrating.
Cross-site content references cannot work locally
when the referenced site was not moved.

This first version rejects legacy blogs.dir uploads, custom upload or content
directories, shared custom user tables, and symlinks in selected paths. It does
not copy cache or database drop-ins. It does not support a SQLite target,
merging into an existing database, or pushing into a multisite network.

The source is not a transactionally frozen snapshot. Pause writes for the final
migration if a point-in-time copy is required. The pull does not delete the
source site or change DNS.
