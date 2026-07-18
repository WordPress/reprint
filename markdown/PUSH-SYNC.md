# Push Sync

Push local changes to a remote WordPress site: files and database, driven
entirely from the local machine, resumable at every step, never leaving the
remote partially committed. This document is the agreed design; the delivery plan at
the end maps it to a PR stack.

## Shape of a push

1. The local machine knows what changed locally since its last push to this
   remote (files, deletions, database rows), by comparing against the local
   paths and rows stored after that push.
2. It shows a summary of local uploads and deletions. The user confirms that
   local should win for those paths and rows.
3. It transfers everything into a private push directory on the remote — file bytes,
   the work-delete stream, and (phase two) the database diff. The transfer is
   resumable at byte granularity.
4. It drives the commit step with repeated commands until done. The remote
   moves work files into place, executes deletions, and commits the database
   diff, and fixes symlinks — inside a maintenance window that lasts seconds,
   not the length of the transfer.
5. It stores the current local paths as the local index at the previous push
   and stores the current rows as the previously pushed rows. The push is
   complete only after this step; a crashed push is
   re-driven from the top and converges.

Both sides act only when the local machine calls: no worker, no polling, no
sessions. The remote is a passive authenticated API.

## Peers and packages

There is one package: Reprint. Every peer ships the same code with three
capabilities:

- **serve** — index and fetch endpoints (today's export surface),
- **commit** — push session, commit engine, database batch,
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
guarantees their integrity, and signing streams was the single biggest cause of
buffering pain. Signing cost is constant per request regardless of payload
size. The secret travels in no URL and no body, so it never lands in an
access log.

Authentication does not grant write authority. Connection tokens are
download-only by default, including tokens that predate push endpoints. Except
for the bounded recovery case below, every `push_*` operation requires current
push authorization before upload data is read, a push directory is created, or
the document root changes; custom authentication uses the same gate.

Revocation does not abandon durable recovery state. An authenticated caller
may keep calling `push_commit` only when that push session already has a durable
commit checkpoint. Those requests converge the already-started document-root
mutation; they cannot upload more work, inspect or remove private work, or
start commit for another push session. Other push operations return HTTP 403
with `reason: "push_disabled"`. This keeps token rotation or a managed policy
change from stranding a partial commit and its maintenance marker.

Personal consent stores only the current connection token's SHA-256
fingerprint. Rotating the token therefore revokes push access. A hosting
provider may override local consent by defining the boolean
`SITE_EXPORT_PUSH_ENABLED` constant before active plugins load or by setting
the environment variable of the same name. Managed `true` enables push and
managed `false` hard-disables push without abandoning durable commit recovery.

## Change detection: local machine compared against itself

ctime is machine-local, so push never compares a local timestamp to a remote
one. It only answers "what changed locally since my last successful push to
this remote" by comparing the current local paths and rows against the local
index at the previous push and the previously pushed rows.

The local machine keeps these files **per remote site**, overwritten after
each successful commit:

    <state-dir>/push/<site>/local_index_at_previous_push.jsonl
    <state-dir>/push/<site>/previously_pushed_rows.jsonl   (phase two)

`PushPlan` derives the local paths to push and delete by merging a path-sorted
fresh local index with `local_index_at_previous_push.jsonl`. It never reopens
the live tree. The indexer records physical emptiness while it observes each
directory, so a completed index distinguishes empty directories from non-empty
ones. Files and symlinks change when their `type`, `ctime`, or `size` changes;
unrelated index values do not select a path for upload.

The index reader trusts the indexer's entry values. It handles only failures to
read a line, decode its JSON, or decode its base64 path. This keeps schema
ownership with the indexer instead of partially validating the same index entry in
the push plan.

A push plan is an internal part of the sender lifecycle:

1. `PushFilesSender::start()` copies the completed fresh local index into the
   local push state directory through a `.swap` file, atomically renames the
   completed copy, and then stores `sender.json`.
2. `push_create` returns at most 100 target exclusions. The sender stores them
   once in `excluded_paths.json` and calls `PushPlan::start()`. The plan reads
   those exclusions and writes its initial cursor.
3. Each `next_step()` merges one path. It returns true while another planning
   step remains and false when both indexes reach EOF. It
   writes files, symlinks, and empty directories with their planned type, size,
   and ctime to
   `local_paths_to_push.jsonl`, and writes raw NUL-delimited paths to
   `local_paths_to_delete`.
4. The sender closes the plan before consuming those two files.
5. After the receiver commits successfully, the sender saves the retained fresh
   local index as `local_index_at_previous_push.jsonl` through the same swap-file
   copy. It then asks the closed plan to remove its cursor.

The complete index copy is a deliberate exception to bounded sender steps. A
representative index entry is about 150 bytes, so one million paths produce
roughly 150 MB, which takes about 15 seconds even at 10 MiB/s. PHP `copy()`
streams the index without loading it into memory, and only the final rename
moves the completed copy into place. This accepts that a 1 MiB/s drive reaches
30 seconds at roughly 200,000 paths. A stopped initial copy leaves no
`sender.json`, so a later start repeats it. This stays
simpler than two copy cursors, retained handles, and per-chunk state writes
until measurements from materially larger installations justify that machinery.

Each step flushes both path lists and the append-only deleted-directory stack
before atomically publishing the cursor with the two index offsets, two output
byte offsets, path counts, and active stack byte offset. Each stack entry links
to the preceding active directory, so resume reads only the top entry. A later
process calls `PushPlan::resume()` with only
the local push state directory. Resume uses the retained index and
`excluded_paths.json`, discards
bytes beyond the durable output offsets, and continues from the durable index
offsets.

The first push to a site has no local index from a previous push or previously
pushed rows. Every current file, symlink, and empty directory is selected, and
no local deletion can be detected yet.

Push is intentionally local-wins. If a developer edited the remote site
outside Reprint, a later push of the same path or row overwrites that remote
edit. This keeps push as a simple deployment tool instead of a conflict
manager.

## Deletes

Local deletions since the last push come from paths present in
`local_index_at_previous_push.jsonl` but absent from the fresh local index. They
travel as NUL-delimited document-root-relative paths in `work/deletes`. Commit
records its byte offset before and after every destructive mutation, so a later
request can resume from a durable checkpoint instead of repeating a delete.

## The push session

`Site_Export_Push_Session` stores each session at
`<reprint-directory>/.reprint/push/<push-session-id>/`. `push.json` is the
push identity and policy plus whether the work-delete stream is complete.
`commit.json` holds the bounded commit cursor. Both are atomically replaced
and validated against the current schema.

`work/files/` contains completed work values only. A single `work/inflight.json`
record identifies the value currently being received or published, and
`work/inflight.data` contains in-flight file bytes. Its actual size is the
receiver-confirmed cursor. A file replayed from byte zero restarts the same
path; a continuation must begin at that confirmed cursor. An upload for another
local path is rejected until the in-flight work is complete. `work/deletes` is
the corresponding durable delete queue.

Publication records its phase before it creates work-file parents or replaces
the completed value. A status, upload, or commit request can therefore finish
a publication after a lost response. Commit refuses to begin while work is
still being prepared or received, so it traverses completed work files only.

`remove()` renames a removable push directory to
`.removing-<push-session-id>/` before bounded cleanup. A false return means
more cleanup remains and must be retried. A push with a commit in progress is
not removable; its next commit request resumes the durable cursor instead.

## Push HTTP operations

The production exporter router exposes five authenticated push operations.
Every request uses the envelope signature described above. `push_upload` passes
`php://input` directly to the multipart processor instead of reading the
complete request for authentication.

- `POST push_create` creates or reopens the caller's 32-character lowercase
  hexadecimal `push_session_id`. A successful response contains `status`,
  `push_session_id`, `max_part_bytes`, `post_max_bytes`, and
  `excluded_paths_b64`. The two limits describe different dimensions: one
  multipart part and the complete decoded request body. The endpoint enforces
  the request-body limit while reading bounded fragments, including chunked
  requests without `Content-Length`. `excluded_paths_b64` is the normalized,
  sorted, immutable server policy stored for the push session; base64 preserves
  arbitrary path bytes which JSON cannot represent directly. A sender
  consuming this field must omit indexed paths equal to, below, or ancestors
  of any advertised exclusion.
- `POST push_upload` accepts `multipart/mixed`. A successful response contains
  `status`, `push_session_id`, `changes_accepted`, and `last_change`. The last
  change is null for an empty request. Otherwise it contains `state`, `type`,
  and `accepted_bytes`, plus `path_b64` for a file, directory, or symlink. A
  delete-list change has no path. Only the latest change is retained for the
  response; request memory does not grow with the number of parts.
- `GET push_status` accepts an optional base64 `path_b64`. A successful
  response contains `status`, `push_session_id`, `phase`,
  `work_deletes_bytes`, `work_deletes_complete`, and `path`. The path is null
  when none was requested. Otherwise it contains `path_b64`, `state`, and
  `accepted_bytes`; a non-missing path also contains `type`.
- `POST push_commit` performs one server-bounded commit call. A successful
  response contains `status`, `push_session_id`, `phase`,
  `send_next_request`, and `entries_processed`. The sender repeats it while
  `send_next_request` is true.
- `POST push_remove` performs one bounded remove step. A successful response
  contains `status`, `push_session_id`, and `removed`. The sender repeats it
  while `removed` is false.

Push deployments must set `display_errors=Off` at PHP startup through
`php.ini`, `.user.ini`, or the PHP-FPM pool configuration; changing it after
the request starts is too late for PHP's own `post_max_size` warning.
`log_errors=On` is recommended. When the endpoint code receives an oversized
request it returns a classified push JSON failure, but PHP or the web server
may reject a request before the exporter runs and that response need not use
the push JSON protocol. The sender therefore also treats a bare HTTP 413 as
`request_too_large` and learns a smaller request ceiling. Timeouts, connection
resets, empty responses, and malformed responses end the current sender run
without changing request sizing. A later push command reconciles the receiver
cursor before continuing.

The WordPress plugin passes the platform-supplied `docroot` to push endpoints,
defaulting to the web server's `DOCUMENT_ROOT`. A platform supplies the complete
trusted API-options array through the early `site_export_api_options` filter; a
direct embedder passes the same array to `_site_export_handle_api_request()`.
The document-root path must resolve to an existing directory. `ABSPATH` remains
the default only for pull endpoints because it may point at a separate shared
WordPress core tree. Push work lives in a document-root-specific private
directory beside the canonical document root unless server configuration
supplies `reprint_directory`.
Configured reprint directories must remain outside the document root; the HTTP
endpoints reject an inside path because they do not yet apply the indexing and
web-access protections described below. The plugin always excludes its logical
installed directory from push, including when that directory is a symlink to a
physical target outside the document root. A platform hook or embedding router
must choose its document root, reprint directory, and excluded paths as server
configuration; request parameters cannot select any of them.

## Local files sender

`PushFilesSender` joins the durable `PushPlan` to the receiver's push session.
An active push keeps these files under `<state-dir>/push/<site>/`:

```text
fresh_local_index.jsonl             plan-owned fresh local index
excluded_paths.json                 target exclusions for the active push
deleted_directories_stack.jsonl     append-only planning stack
local_index_at_previous_push.jsonl  index saved after the previous commit
local_paths_to_push.jsonl           local paths to push
local_paths_to_delete               raw NUL-delimited local paths to delete
cursor.json                         PushPlan cursor
sender.json                         active push state
sender.lock                         lifecycle lock
```

The sender has an explicit start/step/cancel/close lifecycle.
`PushFilesSender::start()` rejects unfinished active state, writes the initial
`creating` state, and acquires `sender.lock`. `PushFilesSender::resume()`
acquires the same lock and reads the unfinished state once. The returned sender
keeps that state in memory while `next_step()` performs bounded work. When the
caller stops between steps, `cancel()` discards an open multipart request and
returns to the preceding durable boundary. `close()` then releases the lock.
Without cancellation, `close()` finishes the request and stores its confirmed
local boundary. A second local process cannot start or resume the same sender
until the open sender is closed. `next_step()` returns true while another step
may be performed and false after completion, restart, or failure; the caller
reads that outcome from the sender. The caller may stop after any true return
and close the sender. If the process stops without closing, the next process
uses the preceding sender boundary and receiver-confirmed cursors to account
for later remote work.

The open sender lazily opens `local_paths_to_push.jsonl`,
`local_paths_to_delete`, and the current local file. It retains those handles
across `next_step()` calls, lets each handle advance with the work, and seeks
only when a newly opened or receiver-confirmed offset differs. It closes each
handle when its phase or file ends and closes any remaining handles before
`close()` releases the lifecycle lock.

`start()` copies the completed fresh local index into plan-owned state before
storing `sender.json` or returning the sender. `push_create` then supplies the
receiver exclusion policy, and each `planning` step merges one path. PushPlan
owns the merge offsets, output lengths,
deleted-directory ranges, and exclusions in `cursor.json`. No upload
begins until both indexes have been consumed and the two path lists are stable.

`sender.json` contains no second planning checkpoint and no copied receiver
cursor. It stores the push session and phase, the next byte offset in
`local_paths_to_push.jsonl`, the receiver part limit, and learned request-body
sizing state.
Its phases are `creating`, `starting_plan`, `planning`, `pushing_paths`,
`pushing_deletes`, `committing`, `saving_local_index_at_previous_push`,
`completing`, `removing`, and `discarding_plan`.
The separate start, index-save, completion, removal, and discard phases ensure
that a process stop between durable actions repeats only the current action.
During `planning`, `cursor.json` alone owns the merge position and output
lengths. A completed cursor remains until commit and the local index save
finishes,
or until `discarding_plan` follows confirmed target removal.

Each local path to push carries the type, size, and ctime from the index used to
plan it. When the receiver position is unknown, the sender compares the live
path with those values before asking `push_status` what the receiver has
accepted, and again after reading a file, symlink, or directory. A successful
upload retains the receiver-confirmed position for later steps in the same
lifecycle. A partial file resumes only while it still matches the plan. A lost
upload response leaves the earlier local path-list boundary in place; the next
process checks the receiver and either advances past complete work or safely
replays it.

After all local paths are pushed, a newly opened sender reads
`work_deletes_bytes` and `work_deletes_complete` from `push_status`. Successful
uploads retain those values in memory for later deletion steps. Those
receiver-owned values are the only work-delete cursor; `sender.json` does not
duplicate it. Each uploaded deletion-list part contains one complete local path.
The sender trusts this completed, immutable plan without consulting the fresh
local index or live local tree again. If a deleted path reappears after planning,
the current push may delete it on the target and the next push will send it.

A changed local path to push, a vanished path to push, or a directory to push
that is no longer empty moves the sender to `removing`. Repeated bounded remove
calls delete the upload-only push session; the sender enters `discarding_plan`,
removes the PushPlan cursor, and changes its status to `restart` so the caller
can produce a new fresh local index. Deletions deliberately retain the tree
captured by the completed fresh local index rather than attempting to describe
the live tree at commit time.

Repeated `push_commit` calls drive the receiver to `complete`. Only then does
the sender enter `saving_local_index_at_previous_push` and copy the plan-owned
fresh local index to `local_index_at_previous_push.jsonl`. Excluded entries
remain in that complete index; exclusions suppress remote work rather than
creating a second retained index representation.

Each local-path upload or deletion step sends at most one multipart part. A file
part contains one bounded chunk, a deletion-list part contains one complete
path, and a directory or symlink part contains one complete value. The sender
retains one multipart request across successive steps until its body budget is
spent or the caller explicitly cancels it. Without cancellation, close()
finishes that request and stores the confirmed local boundary. The sender
derives Content-Length from the bytes actually read and never reads another
local path to push until the current one is complete. Receiver
contention, offset gaps, and transport failures end the current sender run. The
caller may run the push command again; it resumes from the last durable local
boundary and reads receiver-confirmed progress before sending more work.

There is no overall sender deadline. The caller checks its own time and memory
budget before each step. Network operations have connect, no-progress, and
response-wait limits, but a connection that continues moving bytes may take as
long as needed.

The local path type, size, and ctime have one honest timestamp-resolution gap: a
same-size edit that keeps the same ctime second leaves all three unchanged and
remains invisible when a freshly generated index contains the same size, ctime,
and type values. Other drift remains detectable by the next local-index diff.
Push streaming requires PHP 8.1 or newer because older PHP cURL bindings can
truncate a paused upload; pull remains PHP 7.4-compatible.

## Where reprint stores its own data on the remote

The remote is configured with one storage path for everything reprint keeps:
the private push directory and any commit bookkeeping. The current push HTTP
endpoints require this path to be outside the document root. They do not yet
exclude an inside path from every file index or write web-server access guards,
so endpoint construction rejects that configuration instead of exposing private
push work. A host which cannot write outside the document root is not supported
until those protections exist.

## Commit

Remote-side, journaled, idempotent, driven by repeated commands until done.
Order:

1. **Receive work, outside maintenance:** multipart requests write one bounded
   chunk at a time into `work/inflight.data` and publish complete values in
   `work/files`. The site runs normally throughout receipt.
2. **Maintenance on.** Commit writes the `.maintenance` file itself, and since
   WordPress executes that file, ours whitelists reprint API requests
   (`$upgrading = 0` for us, `time()` for everyone else). WordPress's own
   rule that a `.maintenance` file older than 10 minutes is ignored stays
   intact, so an interrupted commit can never leave the site down for good.
3. **Commit:** consume `work/deletes`, then `work/files`, with the durable
   `commit.json` checkpoint written before each document-root mutation. The
   future database batch and symlink updates follow the same bounded cursor.
4. **Maintenance off:** commit releases its `commit-state` ownership after
   completion; the driver saves the local index at the previous push and rows
   after commit completes.

If the driver dies mid-commit: WordPress stops honoring the `.maintenance`
file after 10 minutes on its own, and the next commit request resumes from
`commit.json` — no worker or cron needed.

**The reprint plugin's own directory is never touched by commit.** It is
excluded from installs and deletes and reported as excluded in the summary.
Updating reprint itself is a separate concern, never part of a sync.

## Escape hatch: commit without booting WordPress

Normally the endpoint runs through the WordPress boot because it is
convenient. But a commit step can break that boot — a fatal in a partially
committed plugin — so the same endpoint is also reachable as a standalone PHP file that
never loads WordPress: it reads database credentials from `wp-config.php`
directly and operates on the filesystem and database with reprint's own code.
The driver falls back to it automatically when the normal route stops
answering sensibly. This is what makes commit failures recoverable from the
outside instead of requiring SSH.

## Database diff (phase two)

The database is pushed as a diff — INSERT, UPDATE, DELETE — never as a dump
that replaces tables. The mechanics mirror the file design:

- A **row index** — `(table, primary key, row hash)` — plays the role
  `(path, type, ctime, size)` plays for files. The local machine keeps the row
  index from the last push as `previously_pushed_rows`; diffing against it
  yields the upsert and delete sets.
- Push is local-wins for rows too. Rows changed on the remote outside Reprint
  are overwritten when the local diff touches the same primary key.
- The diff stream passes through the URL rewriter in the local-to-remote
  direction before work.
- Volatile rows are excluded by default (transients, sessions, cron), the
  same way volatile files are handled in pull.
- The batch executes inside the commit maintenance window, bounded and
  resumable like every other step.

## Accepted limitations

Stated here so nobody rediscovers them as surprises:

- **Same-size corruption is invisible.** Transfers are verified by byte
  count only. Corruption that preserves length passes. We decided detection
  is not worth hashing multi-gigabyte work files inside PHP time limits.
- **Remote edits are overwritten.** Push is a local-wins deployment tool, not
  a bidirectional conflict resolver. Developers who edit the remote site
  outside Reprint are responsible for pulling or preserving those changes
  before pushing over them.
- **Shell and cron can write during the maintenance window.** Maintenance
  blocks web requests; it cannot block SSH or system cron.
## Delivery plan

Files first, database second, each PR small and stacked in this order:

1. **Design doc** — this file.
2. **Envelope auth** — headers-only HMAC for data routes: the
   X-Auth-Content-Hash header carries the literal string UNSIGNED-PAYLOAD,
   and the signature covers the method and request target instead of a
   body hash.
3. **Work value store** — the store itself (PR #317, which succeeded
   the closed #298).
4. **Reprint-storage exclusions** — indexer and deletion-sync hard-exclude
   the configured storage path; web guards for inside-docroot placement.
5. **Push plan and local diff** — retained indexes in local push state, explicit start and
   resume lifecycle, bounded local change and deletion detection, and durable
   path lists for the sender.
6. **Push stream endpoint** — the store's HTTP surface plus a sender that
   sends one resumable multipart part per step; deletion work received;
   `--force-http` with honest help text (the first
   push networking this flag can gate). Decisions this slice locked in:
   sending streams through libcurl's pause mechanism, which PHP's curl
   extension supports from 8.1 — so `reprint push` requires PHP 8.1+ (pull
   keeps 7.4+; the full story is
   https://github.com/WordPress/reprint/issues/327) — and paths
   travel base64-encoded in MIME headers, response cursors, and push request
   parameters, because file paths are arbitrary bytes and JSON strings must
   be UTF-8.
7. **Package unification** — importer and exporter become one Reprint
   package (lite = serve-only build). Placed here because commit is the
   first piece that needs import-side code running on the remote.
8. **Commit engine, files** — delete `work/deletes`, then rename values from
   `work/files` into the document root with the whitelisted maintenance file
   and resumable `commit.json` cursor.
9. **Standalone escape hatch** — the no-boot endpoint and driver fallback.
10. **Row index and database diff** — the local row index, previously pushed
    rows, diff generation and URL rewrite, the commit batch.
11. **`reprint push`** — the one command that orchestrates plan, confirm,
    transfer, commit, resume.
12. **Budgets and resumable limits** — push requests stay bounded by two
    budgets of different dimensions: the fixed chunk (the sender's in-memory
    unit of one read) and the host-learned request body budget that
    PushRequestSizer sizes from reported php.ini limits and 413s, plus a
    wall-clock budget per request; any endpoint that stops after durable work
    returns the exact committed state the driver needs to retry. The commit step
    gets the main budgeted loop: process until a deadline or operation limit,
    return progress, and let the driver re-enter until complete.
