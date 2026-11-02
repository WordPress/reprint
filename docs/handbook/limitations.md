# What Reprint does not do

Reprint copies a WordPress site's files and database from a site running the
Reprint Server plugin to a computer or server running the client. The limits
below follow from that design. Each one links to the page with the details.
Read the first one before you copy a site a second time.

## It does not merge

A copy runs in one direction: the source replaces what is on the destination.
Reprint never combines changes made on both sides.

- **The database is replaced.** Running `pull` or `pull-db` again imports the
  source database again. The import drops and recreates every table in the
  source's dump, so posts, settings, orders, and users changed on the copy
  since the last import are replaced with the source's versions.
- **Files follow the pull mode.** The default catch-up mode keeps a local edit
  until the same file changes on the source; then the source version replaces
  it. Mirror mode also removes local files that the source does not have,
  inside the pull selection. Neither mode combines two edits to one file.
- **Push overwrites too.** On a host that offers database push, it replaces the
  live site's tables with the local ones. Rows created on the live site in the
  meantime are removed.

To reopen a finished copy, run its start script rather than another `pull`.
To fetch file changes without touching the database, use `pull-files`. Before
refreshing the database, save anything you changed on the copy. See
[How Reprint works](how-it-works.md#repeating-a-pull) and
[Copy selected files or the database](copy-selected-data.md).

## During a copy

- **It does not freeze the source.** The site keeps serving visitors while a
  copy runs, and files or rows can change between requests. Reprint does not
  produce one coordinated snapshot of every file and database row. For a final
  move, pause writes and take a last pass. See
  [Move to another host](move-to-another-host.md).
- **It does not bypass the source host's limits.** The client sizes requests to
  the time and memory the source reports and waits between requests, but the
  host's PHP limits, security services, and firewalls still apply. Reprint
  cannot complete a Cloudflare browser challenge and stops when a firewall
  blocks the Reprint API address. See [Troubleshooting](troubleshooting.md).
- **It does not read files PHP cannot read.** Paths blocked by `open_basedir`
  are reported and left out of the copy. See [Preflight](preflight.md).
- **It does not copy generated and tool files.** Known backup archives, caches,
  logs, upgrade and temporary paths, version-control metadata, IDE and
  package-manager caches, and editor scratch files are always skipped, and
  `--include` cannot add them back. See [File transfers](file-transfers.md).
- **It does not resume every database failure.** Transactional tables and keyed
  MyISAM tables continue from saved progress. A nontransactional table without
  a non-null unique key, or an interrupted oversized-value update, needs a
  fresh import. See [Database transfers](database-transfers.md).

## On the destination

- **It does not make every plugin work.** A completed copy does not establish
  that each plugin runs on the destination's PHP version and database engine,
  or without the source host's services. Some plugins need MySQL rather than
  SQLite. See [Clone your site](copy-a-site.md).
- **It does not disconnect live services.** Mail, payments, scheduled jobs, and
  other external connections in the copied database still point at live
  accounts until you change them. See
  [Move to another host](move-to-another-host.md).
- **It does not upgrade the database schema.** The SQL dump keeps the source's
  table definitions, including storage engines and collations. The destination
  decides what it accepts. MySQL 5.5 is supported as a source, not as a
  destination. See [Database transfers](database-transfers.md).
- **It does not edit your media or code.** With a new site address, Reprint
  rewrites the old address throughout the database, including serialized PHP,
  JSON, and block markup. It also rewrites every `.css` file it downloads,
  including stylesheets under uploads that the site no longer loads; it does
  not check which stylesheets are in use. Everything else is copied byte for
  byte on purpose: images, documents, and other media in uploads arrive
  identical to the source, and so do PHP, JavaScript, and HTML files. A theme
  or plugin that writes the old address into its code keeps it. See
  [Rewrite asset URLs](file-transfers.md#rewrite-asset-urls).
- **It does not rename files for a different filesystem.** Names ending in a dot
  or space, names that differ only by case, names over the destination's
  length limit, and NTFS alternate data streams are reported or skipped, not
  repaired. See [Copy a Windows-hosted site](windows-sources.md).
- **It does not switch traffic or delete the source.** Changing DNS, routing
  visitors, and removing the original site stay with you and your host. See
  [Move to another host](move-to-another-host.md).

## Scope

- **Activating the plugin does not start anything.** The plugin answers
  requests from an authorized client; the client decides when to copy. See
  [Authorize the Reprint client](authorize-client.md).
- **The WordPress.org plugin does not accept uploads.** Push, which sends local
  file or database changes back to a site, needs a server build that the
  site's host configures. The plugin from the WordPress.org directory serves
  downloads only. See [Command reference](commands.md).
- **It does not copy a whole multisite network.** It can move one site out of a
  network into a single-site installation. Content of other sites, non-core
  user metadata, unnumbered plugin tables, sessions, and application passwords
  need separate work. Creating a network or pushing into one is not
  supported. See [Move one site out of a network](multisite.md).
- **The handbook does not cover native Windows PHP for the client.** Run the
  client on macOS, Linux, or Windows Subsystem for Linux. Windows-hosted
  sources are supported. See [Requirements and downloads](requirements.md).
- **PHP's built-in server is not a production web server.** The generated start
  script is for checking a copy locally. For a public site, serve the copy with
  your own web server. See [Runtime configuration](runtime.md).

## What you do not need

You do not need SSH access to the source, a hosted Reprint account, or Git and
Composer on the destination. Administrator access to the WordPress site, a
terminal, and command-line PHP are enough. See
[Requirements and downloads](requirements.md).
