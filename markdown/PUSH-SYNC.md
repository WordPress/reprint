# Push Sync

Push local changes to a remote WordPress site: files and database, driven
entirely from the local machine, resumable at every step, never leaving the
remote half-applied. This document is the agreed design; the delivery plan at
the end maps it to a PR stack.

## Shape of a push

1. The local machine knows what changed locally since its last sync with this
   remote (files, deletions, database rows), by comparing against baselines it
   stored at the end of that sync.
2. It asks the remote to reindex just the paths (later: rows) the local
   changes touch, and compares the result against the remote baseline from
   the last sync. That reveals remote drift — files someone changed on the
   remote that this push would overwrite.
3. It shows a summary: changed locally, changed on the remote (within the
   pushed scope), deletions. The user confirms. Conflicts are file-level and
   row-level; the only resolutions are "override" and "skip". We never show
   line-level or field-level diffs.
4. It transfers everything into a staging area on the remote — file bytes,
   a deletion manifest, and (phase two) the database diff. The transfer is
   resumable at byte granularity.
5. It drives the apply step with repeated commands until done. The remote
   moves staged files into place, executes deletions, applies the database
   diff, and fixes symlinks — inside a maintenance window that lasts seconds,
   not the length of the transfer.
6. It runs one more scoped reindex and stores the results as the new
   baselines. The push is complete only after this step; a crashed push is
   re-driven from the top and converges.

Both sides act only when the local machine calls: no worker, no polling, no
sessions. The remote is a passive authenticated API.

## Peers and packages

There is one package: Reprint. Every peer ships the same code with three
capabilities:

- **serve** — index and fetch endpoints (today's export surface),
- **apply** — staging store, apply engine, database batch,
- **drive** — the CLI that runs syncs.

"Reprint lite" is a serve-only build for peers that only ever get pulled
from. The WordPress plugin ships the full package.

## Transport and authentication

HTTPS is required. `--force-http` opts out explicitly, and its help text says
what it gives up: over plain HTTP an active attacker can read and modify
transferred content; the flag only keeps the shared secret off the wire and
limits replay.

Every request carries an HMAC signature over exactly four values — the
HTTP method, the URL's path and query, a timestamp, and a random nonce. Payloads are not signed and not hashed: TLS already
guarantees their integrity, and signing streams was the single biggest source
of buffering pain. Signing cost is constant per request regardless of payload
size. The secret travels in no URL and no body, so it never lands in an
access log.

## Change detection: each machine compared against itself

ctime is machine-local, so we never compare a local timestamp to a remote
one. Each side is compared only against its own past:

- "changed on my machine since the last sync" — local index now vs the local
  baseline,
- "changed on the remote since the last sync" — remote index now (scoped to
  the pushed paths) vs the remote baseline.

The local machine keeps one set of baselines **per remote site**, overwritten
after each successful apply:

    <state-dir>/push/<site>/last-sync-local-files.jsonl
    <state-dir>/push/<site>/last-sync-remote-files.jsonl
    <state-dir>/push/<site>/last-sync-local-rows.jsonl   (phase two)

Each baseline is a copy of a file index in the `.import-index.jsonl` format
the pull path already reads and writes — one JSON object per line, sorted by
path.

The remote baseline is captured by a scoped reindex that runs *after* apply —
apply itself changes remote ctimes, and without this refresh the next push
would report everything we just wrote as drift.

The first push to a site has no baselines: everything is "changed locally",
nothing can be checked for drift, and the summary says so. ctime is trusted
as-is; events that bump it without changing content (chmod, restores, host
migrations) produce false drift warnings, and "override" is how the user
moves past them.

## Deletions

Local deletions since the last sync come out of the baseline comparison. They
travel as a deletion manifest — itself a staged artifact, uploaded through
the same store as file bytes — and the apply step executes the unlinks inside
the same window as the moves. Remote deletions since the last sync appear in
the drift summary.

## The staging store

The staging area is `Site_Export_Staged_Artifacts` as built: artifact bytes
at plain target-relative paths under `files/`, a cursor in `state.json`
(replaced by writing a temp file and renaming it, so readers never see a
half-written record), one small verified marker per finished artifact under
`verified/`, and one `lock` file. The caller
drives the loop — one `append()` per buffer, individually committed, so the
transfer can stop after any step and resume from `committed_bytes` in a new
request. `finalize()` checks the assembled size against the size declared in
the plan; nothing re-reads or hashes artifacts.

Driver rule: **a discard that did not return true must be retried until it
does** — before re-uploading an artifact and before starting a fresh push
over leftovers. A half-finished discard leaves states that only another
discard cleans up.

## Where reprint stores its own data on the remote

The remote is configured with one storage path for everything reprint keeps:
the staging area and any apply bookkeeping. Preferably outside the document
root. When the host only allows writing inside the document root:

- the file indexer never lists anything under it. `storage_path` is the
  server's own setting, so every index request knows it — including a
  pulling peer's, which never scans this site's staging data,
- the deletion step refuses to touch anything under it,
- an `.htaccess` (deny-all) and an empty `index.php` are written into
  it. That is all that can be done from inside the directory: Apache
  honors the deny rules, nginx ignores both files and loses nothing by
  their presence. Do not keep this directory inside the document root
  unless the host offers nowhere else to write.

## Apply

Remote-side, journaled, idempotent, driven by repeated commands until done.
Order:

1. **Copy-first, outside maintenance:** static assets (uploads, media) move
   to their final paths; PHP, plugins, and themes are materialized as `.new`
   siblings next to their targets. The site runs normally throughout.
2. **Maintenance on.** We write the `.maintenance` file ourselves, and since
   WordPress executes that file, ours whitelists reprint API requests
   (`$upgrading = 0` for us, `time()` for everyone else). WordPress's own
   rule that a `.maintenance` file older than 10 minutes is ignored stays
   intact, so an interrupted apply can never leave the site down for good.
3. **Recheck, now that web writes are frozen:** the apply command carries the
   expected `(path, ctime, size)` tuples; the remote re-verifies them and
   refuses with a fresh conflict list instead of overwriting drift.
4. **Swap:** journaled `.new`/`.bak` renames, deletion unlinks, the database
   batch, symlink fixes. The window contains renames and a row batch —
   seconds, independent of payload size.
5. **Maintenance off**, scoped reindex, baselines updated.

If the driver dies mid-apply: WordPress stops honoring the `.maintenance`
file after 10 minutes on its own, the journal is resumable by the next apply
command, and any authenticated push request that notices an unfinished
journal finishes it before doing its own work — no worker or cron needed.

**The reprint plugin's own directory is never touched by apply.** It is
excluded from swaps and deletions and reported as excluded in the summary.
Updating reprint itself is a separate concern, never part of a sync.

## Escape hatch: apply without booting WordPress

Normally the endpoint runs through the WordPress boot because it is
convenient. But an apply step can break that boot — a fatal in a half-swapped
plugin — so the same endpoint is also reachable as a standalone PHP file that
never loads WordPress: it reads database credentials from `wp-config.php`
directly and operates on the filesystem and database with reprint's own code.
The driver falls back to it automatically when the normal route stops
answering sensibly. This is what makes apply failures recoverable from the
outside instead of requiring SSH.

## Database diff (phase two)

The database is pushed as a diff — INSERT, UPDATE, DELETE — never as a dump
that replaces tables. The mechanics mirror the file design:

- A **row index** — `(table, primary key, row hash)` — plays the role
  `(path, ctime, size)` plays for files. The local machine keeps the row
  index from the last sync as a baseline; diffing against it yields the
  upsert and delete sets.
- The remote exposes a row-index endpoint scoped to the touched primary
  keys, cursor-paginated the same way the dump producer already walks
  tables, for drift detection.
- Conflicts are row-level: a serialized option conflicts as a whole row, and
  the resolutions are override or skip. Rows inserted on both sides with the
  same primary key are conflicts; we do not remap keys.
- The diff stream passes through the URL rewriter in the local-to-remote
  direction before staging.
- Volatile rows are excluded by default (transients, sessions, cron), the
  same way volatile files are handled in pull.
- The batch executes inside the apply maintenance window, bounded and
  resumable like every other step.

## Accepted limitations

Stated here so nobody rediscovers them as surprises:

- **Same-size corruption is invisible.** Transfers are verified by byte
  count only. Corruption that preserves length passes. We decided detection
  is not worth hashing multi-gigabyte artifacts inside PHP time limits.
- **ctime produces false drift** after chmod, restores, or host migrations.
  The cost is a noisy summary, not wrong data; "override" moves past it.
- **Shell and cron can write during the maintenance window.** Maintenance
  blocks web requests; it cannot block SSH or system cron. The recheck runs
  after freezing web writes; what happens under it from a shell is accepted.
- **Conflicts are whole-file and whole-row.** No line or field granularity,
  by design.
- **A sender that abandons a failed discard and re-uploads anyway** can end
  up with a zero-filled prefix that passes the size check. The discard
  retry rule exists precisely to make this unreachable.

## Delivery plan

Files first, database second, each PR small and stacked in this order:

1. **Design doc** — this file.
2. **Envelope auth** — headers-only HMAC for data routes: the
   X-Auth-Content-Hash header carries the literal string UNSIGNED-PAYLOAD,
   and the signature covers the method and request target instead of a
   body hash.
3. **Staged artifact store** — the store itself (PR #317, which succeeded
   the closed #298).
4. **Reprint-storage exclusions** — indexer and deletion-sync hard-exclude
   the configured storage path; web guards for inside-docroot placement.
5. **Push journal and local diff** — per-site baselines, capture and
   overwrite logic, local change and deletion detection.
6. **Scoped remote reindex and drift summary** — path-scoped index requests,
   baseline comparison, the summary/confirm UX.
7. **Upload endpoint** — the store's HTTP surface plus the sender loop with
   chunk sizing; deletion manifest staged; `--force-http` with honest help
   text (the first push networking this flag can gate).
8. **Package unification** — importer and exporter become one Reprint
   package (lite = serve-only build). Placed here because apply is the
   first piece that needs import-side code running on the remote.
9. **Apply engine, files** — journaled swaps, copy-first, the whitelisted
   maintenance file, the recheck, unfinished-journal completion, post-apply
   reindex. Reuses the apply code already built in PR #277 (the journaled
   `.new`/`.bak` swaps and the copy-first flow); the relay around that code
   is dropped.
10. **Standalone escape hatch** — the no-boot endpoint and driver fallback.
11. **Row index and database diff** — the row index producer and endpoint,
    local row baselines, diff generation and URL rewrite, the apply batch.
12. **`reprint push`** — the one command that orchestrates plan, confirm,
    transfer, apply, resume.
