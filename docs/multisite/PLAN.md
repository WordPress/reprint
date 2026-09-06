# Pull one site into a one-site network

Site 7 stays site 7. Its tables stay wp_7_*. The target uses wp_ as
its base prefix and makes site 7 the main site. The source is not changed.
The remote Reprint API URL selects the site.

## Stack

1. Export selected database tables and shared rows through the network-authenticated endpoint.
2. Let network administrators manage the connection token through WordPress.
3. Limit file indexing and fetching to shared code and selected uploads.
4. Configure a fresh target network, URLs, uploads, and network access.
5. Migrate a populated network through the real HTTP endpoint and CLI.

Each layer has an E2E success case and a rejection case, in addition to focused
tests. The database layer imports the HTTP SQL response into MySQL and rejects
a cross-site cursor. The admin layer changes a token through the real form and
rejects a site administrator. The file layer transfers chosen uploads and rejects
sibling paths. The target layer boots WordPress and rejects a non-empty database.
The final layer exercises the larger overlapping-network fixture.
This is a pull into a fresh target, not a merge or a multisite push.

## Test matrix

- Main site and non-main site; retain IDs and non-default base prefix.
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
- Target boot, login, admin, existing media, new media, and network plugin load.
- Source rows and files unchanged after migration.

## Limits

WordPress does not describe which site arbitrary shared plugin records belong
to. The first version must report unsupported storage rather than silently
copying the network database. It cannot promise plugin-defined cross-site
references will keep working after the other sites are removed. Tests name
the supported cases; “all plugins” is not a testable contract.
