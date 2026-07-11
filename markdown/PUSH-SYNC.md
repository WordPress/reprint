# Push Sync

Push local changes to a remote WordPress site from the local machine. File
transfer, remote apply, and cleanup are resumable; no request needs to hold a
whole file or walk a whole site; and incomplete file bytes never appear below
the live web root.

This is the agreed design. The delivery plan at the end maps it to the current
PR stack.

## Shape of a push

1. The local machine compares a current local file index with the baseline it
   saved after the last completed push to this target.
2. It opens a target-owned session and streams the differences as typed
   operations:

       directory(path)
       file(path, bytes...)
       symlink(path, target)
       delete(path)

3. The target validates and materializes each operation immediately in the
   session's private staging tree. It records only successfully materialized
   operations in its own durable journal.
4. Repeated `advance` requests commit fixed batches from that journal under a
   short WordPress maintenance window.
5. Only after the target reports `complete` does the local machine publish the
   pinned current index as its new baseline.

There is no uploaded manifest and no seal, prepare, or whole-session validation
phase. A large site does not create a large one-request metadata pass. File
bytes are written once to their final private staging inode and later renamed
into place; there is no prepared copy and no pre-commit content reread.

Both peers remain caller-driven. The target has durable transfer records, but
no worker, cron job, or long-lived login session.

## Transport and authentication

HTTPS is required. `--force-http` is an explicit opt-out: over plain HTTP an
active attacker can read and alter pushed bytes. HMAC still keeps the shared
secret off the wire and limits replay, but it does not protect the body.

Each request signature covers the method, path and query, timestamp, and nonce.
The payload is marked `UNSIGNED-PAYLOAD`; TLS supplies body integrity. This
keeps signing constant-memory and allows one request to carry many chunks from
many files.

Every session mutation also names the target-confirmed request generation in
that signed query. The target increments it before reading an upload body or
performing an apply batch. Retrying a request whose response was lost is
therefore rejected before its body is read. The sender asks for session status
and resumes from the target's operation count and current-file byte cursor.

Push request bodies are streamed through libcurl's pause mechanism, which
PHP's curl extension supports correctly from PHP 8.1. `reprint push` therefore
requires PHP 8.1 or newer; pull remains compatible with PHP 7.4. The remote
exporter/apply code remains PHP 7.2 compatible.

## Local change detection

ctime is machine-local, so push never compares a local timestamp with a remote
one. It compares the local machine with its own prior baseline:

    <state-dir>/push/<site>/last-sync-local-files.jsonl

The current index and baseline are decoded-path-sorted JSONL. The sender merges
them directly in bounded steps:

- current only, or different ctime/size/type: materialize the current path;
- baseline only: delete the path;
- equal: send nothing.

At most one bounded line from each input is resident. Input byte offsets,
previous paths, and the output byte offset are persisted. Even a huge index in
which every entry is unchanged advances across bounded, resumable calls.
There are no changed-path lists, required-parent candidates, external sort,
normalized manifest, file pre-digest, or manifest upload.

During that same merge, exact current-index lines stream to
`next-baseline.tmp`. Its flushed output offset advances with sender state. The
file is renamed over the baseline only after remote completion. If a source
changes after its operation was accepted, the pinned baseline still describes
what was pushed and the next index reports another change.

## Direct operation stream

Every frame starts with one bounded JSON line. Paths and symlink targets are
base64 because filenames are bytes and JSON strings must be UTF-8. File headers
are followed by exactly their declared payload bytes; other operations have no
payload. An operation index increases only when the target has accepted a
complete operation. Many operations share one request.

One file may span any number of frames and requests. State contains its path,
operation index, revision, expected size, and committed byte count. The sender
checks size + ctime, the same signals used by the local diff, before and after
bounded reads. If a same-type file changes while partial, a new persisted
revision restarts that file at offset zero. Replaying the same restart revision
after a lost response does not truncate its new prefix again. A source deletion
or type/tree change abandons the session and starts from a fresh index rather
than inventing a mixed snapshot.

Two independent limits bound each request:

- `chunk_bytes` is the one in-memory file unit;
- the learned request-body budget is what PHP and proxies enforce, including
  every JSON header line.

A separate server-owned frame count caps zero-payload operations. Otherwise a
request containing millions of directories or deletions could exceed an
execution limit without exceeding a byte limit. The sender rotates requests on
bytes, elapsed time, or frame count; slow transfers that keep moving have no
total-transfer timeout.

## Target-owned staging and journal

A session's relevant private layout is:

    state.json
    lock
    work/operations.jsonl
    work/incoming-file
    work/staged/<raw path>
    work/backups/<raw path>
    current-operation.json

`operations.jsonl` is not a client manifest. The target encodes one bounded
record only after accepting that typed operation. `state.json` owns the
committed journal length, operation count, one current-file cursor, commit
cursor, request generation, phase, and target identity. Readers trust only the
journal prefix named by state; a crash tail is truncated on retry. No request
scans or seals the journal.

For a file, the target persists the descriptor, writes and flushes one bounded
piece, then atomically persists the byte cursor. At EOF it renames the incoming
file to its real private staged path, records its filesystem identity, appends
and flushes the journal record, and atomically advances state. Metadata
operations use the same intent, materialize, journal, state order. Retrying any
crash boundary converges on one accepted operation.

Operations arrive in strict raw-byte path order. Exact parents therefore
precede descendants. Missing staging parents are private 0700 containers, not
hidden client operations; commit creates a missing live parent safely when an
unchanged parent was absent on the target. Before every staging or target path
access, Reprint walks existing parents with `lstat()` and requires real
directories. Sequential operation paths reject a staged or live symlink
ancestor. Portable PHP pathname APIs cannot anchor the later rename to those
same directory inodes; concurrent local parent replacement is called out
below.

The staging filesystem supplies the structural validation. For example:

    file      wp-content/cache
    file      wp-content/cache/item.txt

The first operation makes `cache` a regular staged file, so the second cannot
be created below it and the session becomes failed/discard-only. The reverse
order is already rejected by strict raw-byte path ordering. Symlink ancestors
fail in the same way and cannot escape the private tree.

Deletes still need durable intent because absence has no filesystem node. They
are journaled and, where possible, occupy a private leaf tombstone so a later
materialization cannot appear below a deleted ancestor. A descendant delete
below an earlier file, symlink, or delete is harmless: replacing that ancestor
already removes the old subtree.

Permanent schema, path, ordering, protected-path, or structural errors fail the
whole session; the sender cannot skip one and apply the rest. Network
truncation, lock contention, offset gaps, frame-size rejection, and retryable
I/O keep the session resumable.

## Apply and recovery

The first `advance` is O(1): it refuses an incomplete file, freezes the
committed journal prefix by changing phase from `uploading` to `committing`,
claims the target coordinator, creates its identified maintenance marker, and
starts the fixed commit batch. There is no intermediate phase.

Each commit step reads one bounded target-authored record, writes
`current-operation.json`, and changes one path. Existing targets move to the
session backup tree when a type transition or recoverability requires it.
Staged files and symlinks move by same-filesystem rename. Directories are
idempotent ensure-directory operations; deletes move a present target to its
backup and are no-ops when absent.

The target-authored journal records each staged file or symlink identity, and
the operation intent records the original live identity or its confirmed absence.
If a process dies after a staged rename but before the state cursor moves,
retry accepts the missing staged node only when the live target still has the
recorded staged identity. Every target-to-backup rename is likewise checked
against the intent's original identity, both immediately and after a lost
response. A missing or replaced leaf stops recovery and leaves any private
backup in place. Reprint does not lock out external writers; do not
concurrently reorganize the target tree during apply. Once state advances, a
stale intent is simply cleared. One `advance` commits a fixed number of
operations and returns the new target-owned cursor.

The session owns its WordPress `.maintenance` marker and verifies that identity
before replacing or removing it. An `active-apply.json` coordinator permits
only one session to mutate a target. Once a commit intent or completed commit
shows that target mutation began, the session must be driven to completion; it
cannot be discarded into a half-applied site. A `committing` session that died
before its first target mutation can still be discarded safely. Abandoned
uploading/failed sessions and completed sessions use a bounded, persisted
discard traversal. HTTP create retry tokens retain a short retired fence so a
delayed signed request cannot address a new generation-zero incarnation.

WordPress treats a maintenance marker older than about ten minutes as stale.
Each `advance` refreshes Reprint's identified marker, but an abandoned commit
can therefore expose its mixed-version target after that window until recovery
advances it again. A future no-boot recovery runner must keep committing
sessions moving; the marker is not a permanent site lock.

This provides atomic individual file renames and recoverable per-operation
progress, not a globally atomic site release. Between operations the target can
contain a mix of versions. A release-directory pointer swap would be required
for global atomicity.

## Remote storage and WordPress recovery

The target configures one private storage directory, preferably outside the
document root and on the same filesystem as the apply root. If it is inside the
target, it is automatically protected and excluded from file indexes. Reprint
writes Apache deny rules and an empty index file, but nginx does not honor those
files; inside-document-root storage is a last resort.

The WordPress plugin requires an explicit `SITE_EXPORT_STAGING_DIR`. Its
WordPress-booted route does not enable live apply until a standalone recovery
endpoint can finish a session after newly installed PHP breaks WordPress boot
or while a fresh maintenance marker is active. Generic integrations may enable
sessions when they already provide such a boot-independent entry point.

The Reprint plugin's own path and `.maintenance` are protected. Updating
Reprint itself remains separate from a site-content push.

## Accepted limitations

- **Name comparison is byte-exact and case-sensitive.** Reprint intentionally
  does not detect case aliases or Unicode normalization aliases. Paths are
  bytes on the wire and on POSIX filesystems, and supporting targets that
  compare them differently would require a target-specific whole-set
  collision model—the validation pass this design avoids. For example, a
  Linux source can contain both `wp-content/uploads/Foo.jpg` and
  `wp-content/uploads/foo.jpg`; a default case-insensitive macOS target maps
  both names to one entry, so the second operation can fail or replace the
  first. Likewise, NFC `café.jpg` and its decomposed NFD spelling can alias on
  a Unicode-normalizing volume. Do not use push when the staging filesystem or
  apply target is case-insensitive or Unicode-normalizing.
- **There is one staged byte copy.** Reprint trusts TLS plus the private staged
  inode's identity and size instead of rereading or hashing every file. If an
  external writer changes the just-installed inode in the tiny rename-to-state
  recovery window, recovery stops instead of reconstructing pristine bytes.
  Retaining that stronger guarantee would require the second immutable copy
  and preparation pass deliberately removed here.
- **Timestamp evidence has filesystem granularity.** A same-size source edit
  whose ctime is indistinguishable within one timestamp tick can escape the
  restart token. Rename recovery and backup proof ignore rename-unstable ctime
  and use inode, mode, size, and mtime, so a same-inode edit with an
  indistinguishable or restored mtime can escape those checks too. The next
  full index is the deeper source-side detection layer.
- **Process-death durability is not power-loss durability.** PHP 7.2 provides
  `fflush()` but no portable fsync contract. Ordering survives ordinary
  process/request death; it does not promise storage-controller persistence
  through sudden power loss.
- **Remote edits are local-wins.** A pushed path overwrites remote changes to
  that path. Push is deployment, not bidirectional conflict resolution.
- **Maintenance cannot stop SSH or cron.** External writers can still race an
  apply. Unexpected leaf-identity changes make recovery terminal rather than
  guessing. A writer can also replace a checked parent with another directory,
  or with a symlink in the interval before a pathname-based rename; PHP 7.2
  has no portable `openat`/`renameat` API to close that race. Do not run push
  beside any process that reorganizes live parent directories.
- **Nested mounts are unsupported.** The staging directory, target root, and
  every path Reprint mutates must share one filesystem device. A nested mount
  can make a later atomic backup/install rename fail after earlier operations
  committed, so exclude separately mounted subtrees from push.

## Delivery plan

The direct design replaces the current draft PRs in this order:

1. **#339 — Direct session staging.** Typed in-process operations, one partial
   file cursor, target-authored journal, path/security validation, and bounded
   discard. No live mutation.
2. **#338 — Bounded commit recovery.** Coordinator, maintenance identity,
   backups, rename evidence, target-drift handling, and commit cursor.
3. **#333 — Authenticated typed session protocol.** Create/push/status/advance/
   discard routes, bounded headers and frame count, and real HTTP tests.
4. **#336 — Streaming direct sender.** Resumable index merge, source revision
   handling, request rotation/lost-response catch-up, and baseline publication.
   This stack layer is a driver API; CLI, scanner, remap, and UI wiring remain
   later work.
5. **#337 — Delete legacy storage and planning docs.** Remove the now-unreachable
   staged-artifact store, its tests and classmap entry, and replace the old
   manifest/seal/prepare plan with this documentation.

Database row diffs and the standalone WordPress recovery endpoint remain later
slices. They must follow the same bounded, target-owned cursor rules.
