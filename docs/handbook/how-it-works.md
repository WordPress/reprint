# How Reprint works

Suppose you want to test a plugin update without changing your live site.
Reprint can copy the live site's files and database to your computer, then
prepare a local server for the copy.

## Use the site's existing web access

Many WordPress administrators can install a plugin but cannot log in over SSH
or run server commands. Reprint uses a PHP plugin and HTTPS so a copy can use
the site's existing web access. The client runs on the receiving computer or
host, where it can store the copy and prepare the destination.

The Reprint Server plugin answers requests from an authorized client. The
client decides when to request files and database data. The plugin does not
start a background transfer when you activate it.

With Migration Tokens, the client keeps a private key and the site stores its
public key. The client signs requests to show which key it holds. On hosts
without OpenSSL, both sides use a shared connection token instead. HTTPS
protects the transferred data on the connection.

## Stream data instead of building a full-site archive

A large media library does not have to become one large archive on the source
host. That avoids needing spare source disk space for a second copy of the
site before a download can start.

Reprint sends file and database data in parts, with metadata and a saved
position alongside the content. One large file can span several requests.
The client records completed work so another run can continue after an
interruption instead of downloading the whole site again.

The state directory contains those positions and the client's credentials.
Keeping it is what lets the same command resume. It must stay outside the
filesystem root where site files are downloaded.

## Fit work into source-host limits

A shared host may stop a PHP request after a short time or limit its memory.
The source works within time and memory budgets, then returns a position from
which the next request can continue. A request ending does not have to mean
the whole migration has failed.

By default, the client adjusts request sizes using the work done and time
reported by the source. It also waits between requests to leave room for normal
site traffic. Waiting on the client lets the source release its PHP worker
instead of keeping that worker busy while sleeping.

These budgets reduce pressure; they cannot bypass a host's limits. Large
database values, directory listings, or multisite networks can still need
substantial memory.

## Keep file comparison and progress on the client

To refresh files, the client downloads a source index: a list of paths, types,
sizes, and change times. It compares that index with saved indexes on the
receiving computer, then requests the files that need downloading. The source
does not have to receive and compare the client's whole local index.

The client keeps those indexes on disk and reads them in order. It does not
need to load the entire file list into memory to compare two copies.
This separates the work of deciding what to copy from the source's work of
reading and sending data.

## Separate site data from destination setup

The database travels as SQL rather than a Reprint-only data format. A dump can
be saved, inspected, or sent to a MySQL-compatible database. Reprint can also
apply it to SQLite. Source table definitions are kept in the dump; a different
database engine can still require translation or compatibility changes. See
[Database transfers](../database-transfers/) for those limits.

Copying files and rows is only part of making a site work elsewhere. The source
host may supply configuration, plugins, or routes that the destination lacks.
Reprint detects known source-host settings separately from generating
destination server files. Adding a source host does not require a separate
migration flow for every destination server.

The local-copy tutorial downloads database data, imports it into SQLite,
rewrites the site address, and generates configuration for PHP's built-in
server. Reprint also adjusts known source-host settings for the local runtime.
Those adjustments do not guarantee that every plugin or external service works
on your computer. External services may still point at live accounts until you
disable them or supply test settings.

The downloaded tree can keep the source host's directory layout. The generated
runtime finds the WordPress root within it, so you do not need to guess which
downloaded directory to serve.

## Repeating a pull

After a completed file pull, the default **catch-up** mode applies remote
changes since the previous completed pull. For example, a locally edited
`style.css` stays when its remote index entry did not change. If that remote
path changes too, catch-up can replace the local edit. It does not combine
the two edits.

**Mirror** mode makes paths inside today's mirror scope match today's remote
index; remote-absent local paths are removed. Paths outside the current pull
selection are not changed by mirror. The `preserve-local` option can also keep
pre-existing local paths. Read `php reprint.phar files-pull --help` and choose
the selection and preservation options before using mirror.

Database imports follow different rules. Running a completed full `pull` again
can replace local database data. Use the generated `start.sh` when you only
want to reopen the existing local copy.

## A live copy is not a frozen snapshot

The source can change while a copy is running, and some database operations
cannot resume after every kind of failure. Reprint does not create a single,
coordinated snapshot of every file and database row. For a final move, plan
when to pause orders, uploads, and other writes, then check the copied site
before switching traffic.

Next: [Copy a site](../copy-a-site/) or [Build with Reprint](../build-with-reprint/).
