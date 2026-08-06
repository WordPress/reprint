# Push Sync

Push local changes to a remote WordPress site: files and database, driven
entirely from the local machine, resumable at every step, never leaving the
remote partially committed. This document is the agreed design; the delivery plan at
the end maps it to a PR stack.

## Shape of a push

1. The local machine knows what changed locally since the local index for this
   remote was advanced by completed pull mutations or replaced after a
   target-confirmed push, and since the database rows were stored after that
   push.
2. It shows a summary of local uploads and deletions. The user confirms that
   local should win for those paths and rows.
3. It transfers everything into a private push directory on the remote — file bytes,
   the work-delete stream, and (phase two) the database diff. The transfer is
   resumable at byte granularity.
4. It drives the commit step with repeated commands until done. The remote
   moves work files into place, executes deletions, and commits the database
   diff, and fixes symlinks — inside a maintenance window that lasts seconds,
   not the length of the transfer.
5. It stores the current local paths as the local index for that remote Reprint
   API URL and stores the current rows as the previously pushed rows. The push
   is complete only after this step. A later process resumes an interrupted
   sender from its stored phase.

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
one. It answers "what changed locally since my last target-confirmed push
commit to this remote" by comparing the current local paths and rows against
the local index and previously pushed rows for that remote Reprint API URL.

The local machine keeps these files **per remote Reprint API URL**. The caller
uses a different state directory for each filesystem root:

    <state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/local_index.jsonl
    <state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/push/previously_pushed_rows.jsonl   (phase two)

The local index records local relative paths and the locally observed type,
size, ctime, and directory emptiness. A target-confirmed files-push replaces it
with the sender's fresh scan. A later files-pull advances only the local paths
that it actually changes. Besides planning the next push, the index feeds the
local `files-diff` command, which reports the same comparison without pushing.

Files-pull records each completed file, directory, symlink, or deletion in
`<remote-state-directory>/pull/index.wal`. Each record carries the
remote index projection and, for a non-skipped path beneath the filesystem
root, the mapped local relative path plus its locally observed type, size, and
ctime. Applying a batch updates the remote index first, advances the local
index second, and clears the WAL only after both replacements finish. Resume
and abort replay the same batch.

This is deliberately not a post-pull filesystem scan. Path selection, filters,
remapping, preserve-local behavior, and failed local mutations leave unrelated
local index entries unchanged, so pending local additions, edits, and
deletions remain visible to files-diff and files-push. The merge applies the
sorted path mutations directly and removes an indexed subtree when its root is
deleted or replaced. Non-empty directories remain implicit. A successful
initial pull with no local mutations creates an empty local index.

`PushPlan` first builds a path-sorted fresh local index, then derives the local
paths to push and delete by diffing it against the local index its caller
supplies. The indexer marks physical emptiness while
it observes each directory, so a completed index distinguishes empty
directories from non-empty ones. Files and symlinks change when their `type`,
`ctime`, or `size` changes; unrelated index values do not select a path for
upload.

The index reader trusts the indexer's entry values. It handles only failures to
read a line, decode its JSON, or decode its base64 path. This keeps schema
ownership with the indexer instead of partially validating the same index entry in
the push plan.

A push plan is an internal part of the sender lifecycle:

1. Before `PushFilesSender::start()`, `files-push` loads the mandatory
   preflight document root. The sender enters `creating`, stores the document
   root's local relative path in `sender.json`, and requests
   at most 100 target exclusions from `push_create`. If another push session
   has an active commit, the sender stores its `blocking_push_session_id`, enters
   `finishing_previous_commit`, and sends one bounded `push_commit` request per
   step until that commit finishes. It then returns to `creating`. A successful
   create stores the exclusions in `excluded_paths.json` after creating the
   active `plan/` directory.
2. The sender starts one internal `PushPlan`. The plan copies the exclusions to
   `plan/excluded_paths.json`, then opens
   `plan/fresh_local_index.jsonl` and a `FileIndexProcessor`. Each internal
   `indexing` step advances one traversal event, appends its JSONL entries when
   applicable, and updates its traversal cursor and fresh-index byte offset.
   One sender step runs at most 256 internal planning steps without crossing
   an internal phase boundary, flushes their output, and stores the resulting
   cursor in `sender.json` before returning.
3. Once traversal is complete, the plan enters `starting_diff`. The next step
   starts the index diff and enters `diffing`.
4. Each later `next_step()` compares at most one path represented by either
   index. It returns true while another planning step remains and false when
   both indexes reach EOF. It
   writes files, symlinks, and empty directories with their local relative
   path, planned type, size, and ctime to `plan/local_paths_to_push.jsonl`,
   and writes raw NUL-delimited document-root-relative paths to
   `plan/local_paths_to_delete`.
5. The sender closes the plan before consuming those two files.
6. After the target confirms commit, the sender saves the retained fresh local
   index as `<remote-state-directory>/local_index.jsonl` through the same
   swap-file copy. It then removes the complete `plan/` directory and the
   sender-owned exclusions file. After the target confirms removal of a
   discarded push session, the sender removes the same files without changing
   the local index for that remote Reprint API URL.

Until the sender stores the initial PushPlan cursor, `starting_plan` remains
the durable phase. An interrupted start is repeated and overwrites its initial
plan files. After each later plan step, changed files are flushed before the
sender atomically stores the returned cursor in `sender.json`.

The completed-index copy after commit is a deliberate exception to bounded
sender steps. A
representative index entry is about 150 bytes, so one million paths produce
roughly 150 MB, which takes about 15 seconds even at 10 MiB/s. PHP `copy()`
streams the index without loading it into memory, and only the final rename
moves the completed copy into place. This accepts that a 1 MiB/s drive reaches
30 seconds at roughly 200,000 paths. A stopped copy is repeated by the next
sender run. Keeping another cursor and retained handle for this post-commit copy
is not justified until measurements from materially larger installations show
that it matters.

The cursor contains the plan directory, filesystem root, local index file,
document root's local relative path, and current planning position. During
indexing, that position contains the `FileIndexProcessor` cursor and committed
fresh-index byte offset. During diffing, each step flushes only the path list or
append-only deleted-directory stack changed by that step before updating the two index
offsets, two output byte offsets, and active stack byte offset. Each stack entry
links to the preceding active directory, so continuation reads only the top
entry. A later process passes the stored cursor to `PushPlan::resume()`. The
plan uses its private exclusions copy, discards bytes beyond the stored output
offsets, and continues from the retained internal phase.

The first push to a site has no local index or previously pushed rows. Every
current file, symlink, and empty directory is selected, and no local deletion
can be detected yet.

Push is intentionally local-wins. If a developer edited the remote site
outside Reprint, a later push of the same path or row overwrites that remote
edit. This keeps push as a simple deployment tool instead of a conflict
manager.

## Deletes

Local deletions since the last push come from paths present in the local index
but absent from the fresh local index. They
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
record identifies the value currently being received or completed, and
`work/inflight.data` contains in-flight file bytes. Its actual size is the
receiver-confirmed cursor. A file replayed from byte zero restarts the same
path; a continuation must begin at that confirmed cursor. An upload for another
local path is rejected until the in-flight work is complete. `work/deletes` is
the corresponding durable delete queue.

The `completing` phase is stored before the receiver creates work-file parents
or replaces the completed value. A status, upload, or commit request can
therefore finish that work after a lost response. Commit refuses to begin while
work is still being prepared or received, so it traverses completed work files
only.

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
  of any advertised exclusion. When another push session has an active commit,
  the endpoint returns `commit_required` with its `blocking_push_session_id`
  before creating the requested push session.
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
directory beside the resolved absolute document root unless server configuration
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
Every local Reprint command workflow runs under `<state-dir>/process.lock`.
The production CLI acquires this non-blocking lock before it resolves the local
push state directory, constructs `ImportClient`, or writes the command audit
entry. It passes the open lock to `ImportClient::run()` and releases it after
the command. A direct `ImportClient::run()` call acquires the lock when its
caller supplies none. The one state-directory-wide Reprint process lock
prevents concurrent pull, push, diff, and other local Reprint processes from
using that site state, regardless of their remote Reprint API URLs.

The remote state directory keeps the retained local index beside active push
state:

```text
<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/
  local_index.jsonl                     retained filesystem-root snapshot for pull, diff, and push
  pull/
    index.wal                           completed pull mutations awaiting application to both indexes
  push/
    excluded_paths.json                 sender-owned target exclusions
    sender.json                         active push state
    plan/
      excluded_paths.json               target exclusions for the active push
      fresh_local_index.jsonl           plan-owned fresh local index
      local_paths_to_push.jsonl         local paths to push
      local_paths_to_delete             raw NUL-delimited document-root-relative paths
      deleted_directories_stack.jsonl   append-only planning stack
```

The sender has an explicit start/step/cancel/close lifecycle.
Its caller acquires the Reprint process lock and passes the open lock to
`PushFilesSender::start()` or `PushFilesSender::resume()`.
`PushFilesSender::start()` rejects unfinished active state and writes the
initial `creating` state. `PushFilesSender::resume()` reads the unfinished state
once. The returned sender keeps that state in memory while `next_step()`
performs bounded work. When the caller stops between steps, `cancel()` discards
an open multipart request and returns to the preceding durable boundary.
`close()` releases sender resources but not the caller-owned Reprint process
lock, and never finishes a request or advances the workflow. `next_step()`
returns true while another step may be performed and false after completion,
restart, or failure; the caller reads that outcome from the sender. The caller
may stop after any true return and close the sender. If the process stops
without closing, the next process uses the preceding sender boundary and
receiver-confirmed cursors to account for later remote work.

During PushPlan's internal `indexing` phase, the plan retains one
`FileIndexProcessor` and the open fresh local index across steps. A
newly opened plan truncates that file to the byte offset stored with the
processor cursor before continuing. The sender lazily opens
`local_paths_to_push.jsonl`, `local_paths_to_delete`, and the current local file.
It retains those handles across `next_step()` calls, lets each handle advance
with the work, and seeks only when a newly opened or receiver-confirmed offset
differs. It closes each handle when its phase or file ends and closes any
remaining handles before `close()` returns.

The sender stores the mandatory preflight document root, creates the push session,
and stores its exclusion policy before it starts PushPlan. Each internal
`indexing` step completes one traversal event, and `starting_diff` initializes
the index diff. Each internal `diffing` step compares at most one path and
updates the path lists. One sender step runs at most 256 internal steps from
the current phase before flushing their output and storing the complete
PushPlan cursor in `sender.json`. PushPlan owns the meaning of its file-index
cursor, index offsets, output lengths, and deleted-directory ranges. No upload
begins until both indexes have been consumed and the two path lists are stable.

`sender.json` contains no copied receiver cursor. It stores the current push
session, an optional blocking push session, the phase, the document root's local
relative path, the PushPlan cursor, the next byte offset in
`local_paths_to_push.jsonl`, the receiver part limit, and learned request-body
sizing state. Its phases are `creating`, `finishing_previous_commit`,
`starting_plan`, `planning`, `pushing_paths`, `pushing_deletes`, `committing`,
`saving_local_index`, `completing`, `removing`, and `discarding_plan`.
The separate previous-commit, start, local-index-save, completion, removal,
and discard phases ensure that a process stop between durable actions repeats
only the current action.
During `planning`, the PushPlan cursor in `sender.json` contains the
plan's internal phase and continuation offsets. A completed cursor remains until
the local index is saved, or until the target confirms removal. The sender then
clears the cursor, enters `completing` or `discarding_plan`, and removes the
active plan files without reopening PushPlan.

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
duplicate it. Each uploaded deletion-list part contains one complete
document-root-relative path.
The sender trusts this completed, immutable plan without consulting the fresh
local index or live filesystem root again. If a deleted path reappears after planning,
the current push may delete it on the target and the next push will send it.

A changed local path to push, a vanished path to push, or a directory to push
that is no longer empty moves the sender to `removing`. Repeated bounded remove
calls delete the upload-only push session; the sender enters `discarding_plan`,
removes the entire plan directory, and changes its status to `restart` so a new
sender can build a fresh local index. Deletions deliberately retain the tree
captured by the completed fresh local index rather than attempting to describe
the live tree at commit time.

Repeated `push_commit` calls drive the receiver to `complete`. Only then does
the sender enter `saving_local_index` and copy the plan-owned fresh local index
to `<remote-state-directory>/local_index.jsonl`. Excluded entries
remain in that complete index; exclusions suppress remote work rather than
creating a second retained index representation. The sender then removes the
entire plan directory before deleting active sender state.

Each local-path upload or deletion step sends at most one multipart part. A file
part contains one bounded chunk, a deletion-list part contains one complete
path, and a directory or symlink part contains one complete value. The sender
retains one multipart request across successive steps until its body budget is
spent or the caller explicitly cancels it. `close()` only releases sender
resources. The sender
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

## Low-level files-push command

`reprint files-push <remote-reprint-api-url>` is the production CLI caller for one
`PushFilesSender`. It treats the saved preflight document root as a path beneath
the resolved filesystem root named by `--fs-root`. It removes that local prefix when producing document-root-relative paths and excludes local paths
outside the document root from push and delete work. It requires `--state-dir`,
`--fs-root`, `--secret`, and saved preflight data; HTTPS is required unless the
operator passes `--force-http`. It reads but never writes
`<remote-state-directory>/pull/state.json`. It does not run preflight itself,
show a plan, ask for confirmation, transfer a database, retry a failed request,
or start a replacement sender after a `restart` outcome.

The local push state directory is
`<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/push`. The hash
directory name is:

```text
md5(rtrim(<remote-reprint-api-url>, "?&"))
```

A different remote query therefore selects a different retained local index. A
different filesystem root requires a different state directory and does not
participate in the directory name. Fragments, URL user-info, and `SECRET_KEY`
target parameters are rejected. The local push state directory must be outside
the filesystem root so planning cannot index its own changing files.

One process starts or resumes exactly one sender. Before every `next_step()` it
checks whether another step may begin. The wall-clock admission deadline is 80
percent of PHP's finite `max_execution_time`; zero is unlimited. With a finite
`memory_limit`, another step begins only when current allocated PHP memory plus
the same 4 MiB file chunk passed to the sender remains below 80 percent of the
limit. These checks happen between steps. An active network step that keeps
moving bytes, and the completed-index copy, may run past the deadline.

A planned pause or first handled SIGINT or SIGTERM calls `cancel()` while the
sender still reports `continue`, then calls `close()`. A first handled signal
only asks the outer loop to stop after the active step; a terminal sender
outcome takes precedence. A second signal may terminate immediately. Without
PCNTL, ordinary process termination can bypass cleanup, and the next process
continues through the same hard-death contract used after SIGKILL.

The stable CLI mapping is `complete`/0, `partial`/2, `interrupted`/2,
`restart`/2, `failed`/1, and `error`/1. Exit 2 asks the operator to run the
same command again. After `restart`, that next run builds a fresh plan. The
state-directory-wide audit log records opening mode, phase changes, planned pauses, handled
interruptions, and terminal outcomes. Terminal output names the active phase
and shows `Uploading — N / T files` while local paths are sent.
Non-interactive output emits throttled `push_progress` JSONL records.
After planning, those records, the final result, and the flat progress file
include `files_done` and `files_total` together. The total comes from the
completed plan; the completed count advances only when the target confirms the
request containing a local path, and both counts survive resume. They are
absent while planning. Neither the audit log nor the progress file copies
receiver cursors or tentative upload positions.

## Local files-diff command

`reprint files-diff <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [--jsonl]`
reports a local minimized push operation plan before target exclusions: the
local paths a files-push would send or delete, compared against
`<remote-state-directory>/local_index.jsonl`. Files-pull advances that local
index after completed local mutations, and files-push replaces it after the
target confirms commit. The remote state directory is
`<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>`, so another URL
query cannot reuse the index. A different filesystem root uses a different
state directory. The command accepts only `--state-dir`, `--fs-root`, and the
optional `--jsonl`; it needs no secret, performs no preflight, and makes no
network request. It runs one complete PushPlan against the local index in
`<remote-state-directory>/push/files-diff-plan/` while the command holds the
state-directory-wide Reprint process lock.

Default output is a path diff: `+` marks each local path to push and `-` marks
each local path to delete. Paths containing unsafe bytes use C-style quoting
so one path always occupies one output line. `--jsonl`
explicitly selects the previous machine-readable contract. Each selected
current file, symlink, or empty directory then has `action: "push"`,
`path_b64`, `type`, `size`, and `ctime`; its type is `file`, `dir`, or `link`.
Each selected local deletion has `action: "delete"` and `path_b64`. Type
transitions may emit both actions for one path. This is a local minimized push
operation plan before target exclusions, not a path-for-path filesystem log:
descendants represent a new non-empty directory, one deleted subtree root
covers its descendants, and metadata-only changes to non-empty directories
select no operation. Base64 keeps arbitrary filesystem path bytes
representable in JSONL. The final JSONL record carries `status: "complete"`
with `local_paths_to_push` and `local_paths_to_delete` counts.

files-diff persists nothing between runs. The whole plan runs in one process,
both finished path lists stream from the beginning, and the plan directory is
removed before exit. An interrupted report is therefore never resumed
mid-stream: running the command again always prints the complete report. The
same-size, same-ctime-second gap described above still applies.

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
   chunk at a time into `work/inflight.data` and move complete values into
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
   completion; the driver saves the local index and previously pushed rows for
   that remote Reprint API URL after the target confirms commit.

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
11. **`reprint files-push`** — the low-level, files-only caller that retains
    one sender per process, applies caller time and memory admission budgets,
    and reports completion, continuation, restart, or failure without retrying.
12. **`reprint files-diff`** — a local-only command that reports the paths a
    files-push would send or delete against the local index for that remote
    Reprint API URL, without contacting the target. Files-pull advances that
    index only for completed local mutations through its pull index WAL.
13. **`reprint push`** — the high-level command that adds a change summary,
    confirmation boundary, database work, transfer, commit, and resume.
14. **Budgets and resumable limits** — push requests stay bounded by two
    budgets of different dimensions: the fixed chunk (the sender's in-memory
    unit of one read) and the host-learned request body budget that
    PushRequestSizer sizes from reported php.ini limits and 413s, plus a
    wall-clock budget per request; any endpoint that stops after durable work
    returns the exact committed state the driver needs to retry. The commit step
    gets the main budgeted loop: process until a deadline or operation limit,
    return progress, and let the driver re-enter until complete.
