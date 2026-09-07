# Pull one network site into a single site

Site 7 keeps its wp_7_* tables. The target adopts wp_7_ as its table prefix
and uses CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE for wp_users and
wp_usermeta. No table names or content IDs change. WordPress runs in single-site
mode, where get_current_blog_id() returns 1. The source is not changed.
The remote Reprint API URL selects the site.

## Stack

1. Export selected database tables and shared rows through the network-authenticated endpoint.
2. Let network administrators manage the connection token through WordPress.
3. Limit file indexing and fetching to shared code and selected uploads.
4. Configure a fresh target network, URLs, uploads, and network access.
5. Adopt the selected table prefix, grant site access, and carry over network plugin activations and inherited language.
6. Migrate a populated network through the real HTTP endpoint and CLI.

Each layer has an E2E success case and a rejection case, in addition to focused
tests. The database layer imports the HTTP SQL response into MySQL and rejects
a cross-site cursor. The admin layer changes a token through the real form and
rejects a site administrator. The file layer transfers chosen uploads and rejects
sibling paths. The target layer boots WordPress and rejects a non-empty database.
The final layer exercises the larger overlapping-network fixture.
This is a pull into a fresh target, not a merge or a multisite push.

## Test matrix

- Main site and non-main site; retain content/user IDs and adopt the selected prefix.
- Three sites, including another network in the same database.
- Shared member with different roles on each site; member without content.
- Former member who still authored a post; registered commenter; link author.
- Unrelated user; sibling capability keys; sessions and application passwords.
- Equal post and attachment IDs in different site tables.
- Network options, network plugins, enabled themes, selected-site options.
- Sibling options, signup records, and Reprint credentials never exported.
- Multiple database batches and oversized values, interrupted and resumed.
- Resume with another selected site rejected before reading its records.
- Main uploads containing sibling sites/ directories; direct fetch bypass.
- Symlinks and custom uploads must not widen the selected file scope.
- Source URLs, serialized data, attachment metadata, and bare network domains.
- HTTP and HTTPS links to selected pages, shared code, and media; sibling links stay remote.
- Target boot, login, admin, existing media, new media, and network plugin load.
- Site administrator login supplied with a different case from the imported record.
- Single-site boot with unchanged shared user table names and no network constants.
- Network-active plugins become site-active; host exclusions still apply.
- Network language inheritance versus an explicit site language.
- Oversized profiles exported before their membership rows reach the target.
- Direct MySQL output rejected without changing existing target tables.
- Target lock contention and process death on both sides of initialization,
  SQL-start, and cleanup state saves; target changes before SQL remain rejected.
- Source rows and files unchanged after migration.

## Limits

WordPress does not describe which site arbitrary shared plugin records belong
to. The first version must report unsupported storage rather than silently
copying the network database. It cannot promise plugin-defined cross-site
references will keep working after the other sites are removed. Tests name
the supported cases; “all plugins” is not a testable contract.
