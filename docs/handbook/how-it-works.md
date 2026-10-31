# How Reprint works

Suppose you want to test a plugin update without changing your live site.
Reprint can copy the live site's files and database to your computer, then
prepare a local server for the copy.

## The client drives the transfer

The Reprint Server plugin answers requests from an authorized client. The
client decides when to request files and database data. The plugin does not
start a background transfer when you activate it.

With Migration Tokens, the client keeps a private key and the site stores its
public key. The client signs requests to show which key it holds. On hosts
without OpenSSL, both sides use a shared connection token instead. HTTPS
protects the transferred data on the connection.

## Streaming and resuming

A large media library does not have to become one large archive on the source
host. Reprint sends bounded pieces of file and database data across requests.
It records positions so another run can continue after an interruption.

The state directory contains those positions and the client's credentials.
Keeping it is what lets the same command resume. It must stay outside the
filesystem root where site files are downloaded.

Resuming has limits. The source can change while a copy is running, and some
database operations cannot resume after every kind of failure. A transfer
does not create a single, coordinated snapshot of every file and database row.
A live-site migration still needs a plan for writes made during the copy.

## Preparing a local site

The local-copy tutorial downloads database data, imports it into SQLite,
rewrites the site address, and generates configuration for PHP's built-in
server. Reprint also adjusts known source-host settings for the local runtime.
Those adjustments do not guarantee that every plugin or external service works
on your computer.

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

Next: [Copy a site](../copy-a-site/) or [Build with Reprint](../build-with-reprint/).
