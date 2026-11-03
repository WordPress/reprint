# What Reprint does not do

Reprint copies a WordPress site's files and database from a site running the Reprint Server plugin to a computer or server running the client. The limits below follow from that design. Each one links to the page with the details. Read the first one before you copy a site a second time.

## It does not merge

A copy runs in one direction: the source replaces what is on the destination. Reprint never combines changes made on both sides.

- **The database is replaced.** Running `pull` or `pull-db` again imports the source database again. The import drops and recreates every table in the source's dump, so posts, settings, orders, and users changed on the copy since the last import are replaced with the source's versions.
- **Files follow the pull mode.** The default catch-up mode keeps a local edit until the same file changes on the source; then the source version replaces it. Mirror mode also removes local files that the source does not have, inside the pull selection. Neither mode combines two edits to one file.
- **Push overwrites too.** On a host that offers database push, it replaces the live site's tables with the local ones. Rows created on the live site in the meantime are removed.

To reopen a finished copy, run its start script rather than another `pull`. To fetch file changes without touching the database, use `pull-files`. Before refreshing the database, save anything you changed on the copy. See [How Reprint works](https://developer.wordpress.org/reprint/understand-reprint/how-it-works/#repeating-a-pull) and [Copy selected files or the database](https://developer.wordpress.org/reprint/guides/copy-selected-data/).

## During a copy

- **It does not freeze the source.** The site keeps serving visitors while a copy runs, and files or rows can change between requests. Reprint does not produce one coordinated snapshot of every file and database row. For a final move, pause writes and take a last pass. See [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/).
- **It does not bypass the source host's limits.** The client sizes requests to the time and memory the source reports and waits between requests, but the host's PHP limits, security services, and firewalls still apply. Reprint cannot complete a Cloudflare browser challenge and stops when a firewall blocks the Reprint API address. See [Troubleshooting](https://developer.wordpress.org/reprint/guides/troubleshooting/).
- **It does not read files PHP cannot read.** Paths blocked by `open_basedir` are reported and left out of the copy. See [Preflight](https://developer.wordpress.org/reprint/reference/preflight/).
- **It does not copy generated and tool files.** Known backup archives, caches, logs, upgrade and temporary paths, version-control metadata, IDE and package-manager caches, and editor scratch files are always skipped, and `--include` cannot add them back. See [File transfers](https://developer.wordpress.org/reprint/reference/file-transfers/).
- **It does not resume every database failure.** Transactional tables and keyed MyISAM tables continue from saved progress. A nontransactional table without a non-null unique key, or an interrupted oversized-value update, needs a fresh import. See [Database transfers](https://developer.wordpress.org/reprint/reference/database-transfers/).

## On the destination

- **It does not make every plugin work.** A completed copy does not establish that each plugin runs on the destination's PHP version and database engine, or without the source host's services. Some plugins need MySQL rather than SQLite. See [Clone your site](https://developer.wordpress.org/reprint/getting-started/copy-a-site/).
- **It does not disconnect live services.** Mail, payments, scheduled jobs, and other external connections in the copied database still point at live accounts until you change them. See [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/).
- **It does not upgrade the database schema.** The SQL dump keeps the source's table definitions, including storage engines and collations. The destination decides what it accepts. MySQL 5.5 is supported as a source, not as a destination. See [Database transfers](https://developer.wordpress.org/reprint/reference/database-transfers/).
- **It does not edit your media or code.** With a new site address, Reprint rewrites the old address in the database and in stylesheets. Images, documents, and other media are copied byte for byte by design, and so are PHP, JavaScript, and HTML files. A theme or plugin that writes the old address into its code keeps it. See [Rewrite asset URLs](https://developer.wordpress.org/reprint/reference/file-transfers/#rewrite-asset-urls).
- **It does not rename files for a different filesystem.** Names ending in a dot or space, names that differ only by case, names over the destination's length limit, and NTFS alternate data streams are reported or skipped, not repaired. See [Copy a Windows-hosted site](https://developer.wordpress.org/reprint/guides/windows-sources/).
- **It does not switch traffic or delete the source.** Changing DNS, routing visitors, and removing the original site stay with you and your host. See [Move to another host](https://developer.wordpress.org/reprint/guides/move-to-another-host/).

## Scope

- **Activating the plugin does not start anything.** The plugin answers requests from an authorized client; the client decides when to copy. See [Authorize the Reprint client](https://developer.wordpress.org/reprint/getting-started/authorize-client/).
- **The WordPress.org plugin does not accept uploads.** Push, which sends local file or database changes back to a site, needs a server build that the site's host configures. The plugin from the WordPress.org directory serves downloads only. See [Command reference](https://developer.wordpress.org/reprint/reference/commands/).
- **It does not copy a whole multisite network.** It can move one site out of a network into a single-site installation. Content of other sites, non-core user metadata, unnumbered plugin tables, sessions, and application passwords need separate work. Creating a network or pushing into one is not supported. See [Move one site out of a network](https://developer.wordpress.org/reprint/guides/multisite/).
- **The handbook does not cover native Windows PHP for the client.** Run the client on macOS, Linux, or Windows Subsystem for Linux. Windows-hosted sources are supported. See [Requirements and downloads](https://developer.wordpress.org/reprint/getting-started/requirements/).

## What you do not need

You do not need SSH access to the source, a hosted Reprint account, or Git and Composer on the destination. Administrator access to the WordPress site, a terminal, and command-line PHP are enough. See [Requirements and downloads](https://developer.wordpress.org/reprint/getting-started/requirements/).
