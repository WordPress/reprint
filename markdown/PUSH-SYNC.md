# Push Sync

Push local changes to a remote WordPress site: files and database, driven
entirely from the local machine, resumable at every step, never leaving the
remote half-applied. This document is the agreed design; the delivery plan at
the end maps it to a PR stack.

## Shape of a push

1. The local machine knows what changed locally since its last push to this
   remote (files, deletions, database rows), by comparing against the local
   baselines it stored at the end of that push.
2. It shows a summary of local uploads and deletions. The user confirms that
   local should win for those paths and rows.
3. It transfers everything into a staging area on the remote — file bytes,
   a deletion manifest, and (phase two) the database diff. The transfer is
   resumable at byte granularity.
4. It drives the apply step with repeated commands until done. The remote
   moves staged files into place, executes deletions, applies the database
   diff, and fixes symlinks — inside a maintenance window that lasts seconds,
   not the length of the transfer.
5. It stores the current local indexes as the new local baselines. The push is
   complete only after this step; a crashed push is re-driven from the top and
   converges.

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

## Change detection: local machine compared against itself

ctime is machine-local, so push never compares a local timestamp to a remote
one. It only answers "what changed locally since my last successful push to
this remote" by comparing the current local indexes against local baselines.

The local machine keeps one set of baselines **per remote site**, overwritten
after each successful apply:

    <state-dir>/push/<site>/last-sync-local-files.jsonl
    <state-dir>/push/<site>/last-sync-local-rows.jsonl   (phase two)

Each file baseline is a copy of a local file index in the `.import-index.jsonl`
format the pull path already reads and writes — one JSON object per line,
sorted by path.

The first push to a site has no baselines: every current local file counts as
changed, and no local deletion can be detected yet.

Push is intentionally local-wins. If a developer edited the remote site
outside Reprint, a later push of the same path or row overwrites that remote
edit. This keeps push as a simple deployment tool instead of a conflict
manager.

## Deletions

Local deletions since the last push come out of the baseline comparison. They
travel as a deletion manifest — itself a staged artifact, uploaded through
the same store as file bytes — and the apply step executes the unlinks inside
the same window as the moves.

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
3. **Swap:** journaled `.new`/`.bak` renames, deletion unlinks, the database
   batch, symlink fixes. The window contains renames and a row batch —
   seconds, independent of payload size.
4. **Maintenance off**, local baselines updated by the driver after apply
   completes.

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
  index from the last push as a baseline; diffing against it yields the
  upsert and delete sets.
- Push is local-wins for rows too. Rows changed on the remote outside Reprint
  are overwritten when the local diff touches the same primary key.
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
- **Remote edits are overwritten.** Push is a local-wins deployment tool, not
  a bidirectional conflict resolver. Developers who edit the remote site
  outside Reprint are responsible for pulling or preserving those changes
  before pushing over them.
- **Shell and cron can write during the maintenance window.** Maintenance
  blocks web requests; it cannot block SSH or system cron.
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
5. **Push journal and local diff** — per-site local baselines, capture and
   overwrite logic, local change and deletion detection.
6. **Push stream endpoint** — the store's HTTP surface plus a sender that
   streams framed chunks for many files through one authenticated request;
   deletion manifest staged; `--force-http` with honest help text (the first
   push networking this flag can gate). Decisions this slice locked in:
   sending streams through libcurl's pause mechanism, which PHP's curl
   extension supports from 8.1 — so `reprint push` requires PHP 8.1+ (pull
   keeps 7.4+; the full story is
   https://github.com/WordPress/reprint/issues/327) — and artifact ids
   travel base64-encoded in frames, response cursors, and control-plane
   parameters, because file paths are arbitrary bytes and JSON strings must
   be UTF-8.
7. **Package unification** — importer and exporter become one Reprint
   package (lite = serve-only build). Placed here because apply is the
   first piece that needs import-side code running on the remote.
8. **Apply engine, files** — journaled swaps, copy-first, the whitelisted
   maintenance file, unfinished-journal completion. Reuses the apply code
   already built in PR #277 (the journaled `.new`/`.bak` swaps and the
   copy-first flow); the relay around that code is dropped.
9. **Standalone escape hatch** — the no-boot endpoint and driver fallback.
10. **Row index and database diff** — the local row index, row baseline,
    diff generation and URL rewrite, the apply batch.
11. **`reprint push`** — the one command that orchestrates plan, confirm,
    transfer, apply, resume.
12. **Budgets and resumable limits** — push requests stay bounded by two
    budgets of different dimensions: the fixed chunk (the sender's in-memory
    unit of one read) and the host-learned request body budget that
    PushRequestSizer sizes from reported php.ini limits and 413s, plus a
    wall-clock budget per request; any endpoint that stops after durable work
    returns the exact committed state the driver needs to retry. The apply step
    gets the main budgeted loop: process until a deadline or operation limit,
    return progress, and let the driver re-enter until complete.
