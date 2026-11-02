# File push implementation

Use this reference when changing file uploads or commit behavior, or when
configuring a host to receive file pushes. Start with
[development setup](https://developer.wordpress.org/reprint/contributing/development/)
if you do not have a working checkout.

Suppose you edit a theme's `style.css` in a local copy. A file push has three jobs:

1. Compare the local files with the saved local index and select changed paths.
2. Upload those paths to private work files on the target. Visitors still see
   the existing site during upload.
3. Commit the uploaded work: apply deletions and replacements to the target's
   document root, then save the new local index.

A **push session** holds the uploaded work and the saved position for commit.
`PushFilesSender` is the client processor that runs these jobs. Its `PushPlan`
prepares the paths to upload and delete. A **step** performs bounded work before
returning to its caller, so the caller can stop and save progress between steps.
A durable boundary is a saved position after a local action finishes or the
target confirms it. If a request loses its reply, the next process asks the
target how much it accepted before sending more. The API details below explain
which state each step may change.

Use the [terminology contract](https://github.com/WordPress/reprint/blob/trunk/docs/contributing/push-terminology.md)
when naming paths, phases, or state fields in code.

The WordPress.org plugin omits file and database push. These endpoints require
a separately configured source build. The command examples run from the
repository root after `composer install`; `vendor/bin/reprint-client` runs the
client code from that checkout.

## Transport and authentication

HTTPS is required by default. `--insecure` or `REPRINT_INSECURE_TLS=1`
allows plain HTTP and skips certificate and hostname checks for HTTPS. The
choice is invocation-only. Over plain HTTP, an observer can read transferred
content. Over HTTP or unverified HTTPS, an active attacker can read and modify
it. Request signatures keep the connection token and private key off the wire
and limit replay, but do not replace server verification.

Servers with OpenSSL use public/private-key authorization. Servers without
OpenSSL use a shared connection token. The client uses the key in
`<remote-state-directory>/key.pem` unless `--private-key-path=PATH` supplies
another key or `--secret=TOKEN` selects token authentication. A host with
OpenSSL rejects the token; the client never falls back to another credential.

Every request carries a signature over newline-joined fields: the protocol
label (plus the key id for a key), a random nonce, a timestamp, the HTTP method,
and the URL's path and query (which name the endpoint). The label and field
layout appear in [Pull HTTP protocol](https://developer.wordpress.org/reprint/contributing/pull-http/). The signature covers those fixed fields, not the uploaded content. That lets
the client send file chunks without reading the whole upload into memory first.
Verified HTTPS protects the uploaded content during transfer. Neither the connection token nor the private key travels in a URL or body,
so neither lands in an access log.

Authentication does not grant write authority. Connection tokens and enrolled
keys are download-only by default, including tokens that predate push endpoints.
On a key host, enable **Allow push** for the key under **Tools → Reprint Server**.
That grant applies only to that key. A token's grant or another key's grant
cannot authorize it.

Except for the bounded recovery case below, every `push_*` operation requires
current push authorization before upload data is read, a push directory is created, or
the document root changes; custom authentication uses the same gate.

If **Allow push** is revoked after commit has started, blocking every further
request could leave the site partly changed. A client that still authenticates
may therefore call `push_commit` when that push session already has a saved
commit checkpoint. Those calls finish the changes already started. They cannot
upload more work, inspect or remove private work, or start another commit. Other push operations return HTTP 403
with `reason: "push_disabled"`. This keeps token rotation, key push-grant
revocation, or a managed policy change from stranding a partial commit and its
maintenance marker.

Personal consent stores a `push` flag on each enrolled key, or the current
connection token's SHA-256 fingerprint on a token host. Newly enrolled keys
have no push grant. Rotating the token revokes its push access. A hosting
provider may override local consent by defining the boolean
`WordPress\Reprint\Server\Plugin\PUSH_ENABLED` constant before active plugins
load or by setting `REPRINT_SERVER_PUSH_ENABLED` in the environment. Managed
`true` enables push and managed `false` hard-disables push without abandoning
durable commit recovery.

### Pushing with an enrolled key

Use the same remote Reprint API URL and state directory as the existing pull.
If no key exists yet, generate one:

```sh
vendor/bin/reprint-client keygen 'https://example.com/?reprint-api' --state-dir=./state
```

Enroll the printed Migration Token under **Tools → Reprint Server**, then enable
**Allow push** for that Migration Token. With saved preflight data from the pull, run:

```sh
vendor/bin/reprint-client files-push 'https://example.com/?reprint-api' --state-dir=./state --fs-root=./files
```

The command finds the private key in the state directory. Omit `--secret` on
an OpenSSL host. If the private key is stored elsewhere, pass
`--private-key-path=/path/to/key.pem`. Push from the client requires PHP 8.1+
for streaming uploads; see [issue #327](https://github.com/WordPress/reprint/issues/327).

## Change detection: local machine compared against itself

ctime is machine-local, so push never compares a local timestamp to a remote
one. It compares the current local paths against the local index for that
remote Reprint API URL. Completed pull mutations advance that index; a
target-confirmed files-push replaces it with the sender's fresh scan.

The local machine keeps these files **per remote Reprint API URL**. The caller
uses a different state directory for each filesystem root:

    <state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/local_index.jsonl

The local index records local relative paths and the locally observed type,
size, ctime, and directory emptiness. A target-confirmed files-push replaces it
with the sender's fresh scan. A later files-pull advances only the local paths
that it actually changes. Besides planning the next push, the index feeds the
local `files-diff` command, which reports the same comparison without pushing.

Files-pull records each completed file, directory, symlink, or deletion in
`<remote-state-directory>/pull/index.wal`. Each record carries the
source fields needed to update the remote index and, for a non-skipped path beneath the filesystem
root, the mapped local relative path plus its locally observed type, size, and
ctime. Applying a batch updates the remote index first, advances the local
index second, and clears the WAL only after both replacements finish. Resume
and abort replay the same batch.

Only paths actually changed by the pull update the local index. For example,
a local theme edit left alone by a preserving pull must still appear as an edit
in the next `files-diff`. Scanning the whole local tree after the pull would
record that edit as the new baseline and hide it from the next push. The merge applies the
sorted path mutations directly and removes an indexed subtree when its root is
deleted or replaced. Non-empty directories remain implicit. A successful
initial pull with no local mutations creates an empty local index.

`PushPlan` first copies the caller's local index to
`plan/patch_base_index.jsonl`, omitting entries which are now
default-skipped. It then builds a path-sorted fresh local index and derives the
local paths to push and delete by diffing the two plan-owned indexes. The
caller's local index remains unchanged during planning. File-name rules use
the current filesystem type, so a file which became a directory or symlink
remains in the patch base and is replaced. The indexer marks physical
emptiness while
it observes each directory, so a completed index distinguishes empty
directories from non-empty ones. Files and symlinks change when their `type`,
`ctime`, or `size` changes; unrelated index values do not select a path for
upload.

The index reader trusts the indexer's entry values. It handles only failures to
read a line, decode its JSON, or decode its base64 path. The indexer validates the schema; the push plan does not partly validate
the same index entry again.

A push plan is an internal part of the sender lifecycle:

1. Before `PushFilesSender::start()`, `files-push` loads the mandatory
   preflight document root. The sender enters `creating`, stores the document
   root's local relative path in `sender.json`, and requests
   at most 100 target exclusions from `push_create`. If another push session
   has an active commit, the sender requires a 32-character lowercase hexadecimal
   `blocking_push_session_id` before storing it and entering
   `finishing_previous_commit`, and sends one bounded `push_commit` request per
   step until that commit finishes. It then returns to `creating`. A malformed
   blocking ID fails the run without leaving `creating`. A `push_not_found`
   response while finishing the previous commit fails the run and stores
   `creating` for the next resume. Older checkpoints with an invalid blocking ID
   return to `creating` in one local step without sending a request. A successful
   create combines the target exclusions with at most 100 caller exclusions
   and stores the result in `excluded_paths.json` after creating the active
   `plan/` directory. The combined list stays with that plan across resume.
2. The sender starts one internal `PushPlan`. The plan copies the exclusions to
   `plan/excluded_paths.json` and enters `filtering_patch_base`. Each step reads
   at most one caller-owned local-index entry and appends it to
   `plan/patch_base_index.jsonl` unless it is now default-skipped. The cursor
   stores the byte offsets in both files.
3. When the patch base reaches EOF, the plan opens
   `plan/fresh_local_index.jsonl` and a `FileIndexProcessor`. Each internal
   `indexing` step advances one traversal event, appends its JSONL entries when
   applicable, and updates its traversal cursor and fresh-index byte offset.
   One sender step runs at most 256 internal planning steps without crossing
   an internal phase boundary, flushes their output, and stores the resulting
   cursor in `sender.json` before returning.
4. Once traversal is complete, the plan enters `starting_diff`. The next step
   starts the index diff between the selected patch base and the fresh local
   index, then enters `diffing`.
5. Each later `next_step()` compares at most one path represented by either
   index. It returns true while another planning step remains and false when
   both indexes reach EOF. It
   writes files, symlinks, and empty directories with their local relative
   path, planned type, size, and ctime to `plan/local_paths_to_push.jsonl`,
   and writes raw NUL-delimited document-root-relative paths to
   `plan/local_paths_to_delete`.
6. The sender closes the plan before consuming those two files.
7. After the target confirms commit, the sender saves the retained fresh local
   index as `<remote-state-directory>/local_index.jsonl` through the same
   swap-file copy. It then removes the complete `plan/` directory and the
   sender-owned exclusions file. After the target confirms removal of a
   discarded push session, the sender removes the same files without changing
   the local index for that remote Reprint API URL.

`files-push --abort` removes the local `plan/`, `excluded_paths.json`, and
`sender.json`, plus `excluded_paths.json.tmp` and `sender.json.tmp`, under the
Reprint process lock. It does not decode the sender checkpoint, require preflight
or a connection credential, or send a request. It keeps the completed local index,
pull state, and local site files unchanged. It does not cancel a target commit
already in progress or remove staged target work. The next push starts a fresh
local sender and may still need to finish that target commit.

Until the sender stores the initial PushPlan cursor, `starting_plan` remains
the durable phase. An interrupted start is repeated and overwrites its initial
plan files. After each later plan step, changed files are flushed before the
sender atomically stores the returned cursor in `sender.json`.

The completed-index copy after commit is the deliberate exception to bounded
sender steps. A representative index entry is about 150 bytes, so one million
paths produce roughly 150 MB, which takes about 15 seconds even at 10 MiB/s.
PHP `copy()` streams the index without loading it into memory, and only the
final rename moves the completed copy into place. This accepts that a 1 MiB/s
drive reaches 30 seconds at roughly 200,000 paths. A stopped copy is repeated
by the next sender run. Keeping another cursor and retained handle for this
post-commit copy is not justified until measurements from materially larger
installations show that it matters.

The cursor contains the push-plan version, plan directory, filesystem root,
local index file, document root's local relative path, and current planning
position. During `filtering_patch_base`, that position contains committed byte
offsets in the caller's local index and the plan-owned patch base. During
`indexing`, it contains the `FileIndexProcessor` cursor and committed
fresh-index byte offset. During `diffing`, each step flushes only the path list
or append-only active deletion roots file changed by that step before updating
the two output byte offsets and the nested FileSyncPatchPlanner cursor. Each
active deletion root links to the preceding one, so continuation reads only
the current root. PushPlan stores and restores the planner cursor without
rebuilding it. A later process passes the stored cursor to
`PushPlan::resume()`. The plan uses its private exclusions copy, discards bytes
beyond the stored output offsets, and continues from the retained internal
phase. An older cursor stopped during `indexing` restarts at
`filtering_patch_base` so one traversal never mixes old and current skip rules.

If there is no local index, every current file, symlink, and empty directory
is selected, and no local deletion can be detected yet. A preceding pull can
already have created a local index before the first push.

Push is intentionally local-wins. If a developer edited the remote site
outside Reprint, a later push of the same path overwrites that remote
edit. This keeps push as a simple deployment tool instead of a conflict
manager.

## Deletes

Local deletions since the last push come from paths present in the filtered
patch base but absent from the fresh local index. A path omitted from a
filtered patch base because it is now default-skipped does not become a delete
operation. Local deletions travel as NUL-delimited document-root-relative paths
in `work/deletes`. Commit
records its byte offset before and after every destructive mutation, so a later
request can resume from a durable checkpoint instead of repeating a delete.

## The push session

`WordPress\Reprint\Server\PushSession` stores each session at
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
Every request uses the headers-only signature described above. `push_upload` passes
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
trusted API-options array through the early `reprint_server_api_options` filter;
a direct embedder passes the same array to
`WordPress\Reprint\Server\Plugin\handle_api_request()`.
The document-root path must resolve to an existing directory. `ABSPATH` remains
the default only for pull endpoints because it may point at a separate shared
WordPress core tree. Push requires an explicit `reprint_directory` in the trusted
API-options array; there is no inferred directory. The host must choose a private
push directory outside every web-served tree, on the same filesystem as the
document root. A sibling of one document root can still be served by a parent
virtual host, and PHP cannot enumerate those other roots. Missing configuration
returns `not_configured` (HTTP 503) before a push session is created. Pull endpoints
are unchanged. Existing unfinished pushes require their existing private directory
to remain configured; selecting a new empty directory does not move their state.
The HTTP endpoints also reject a directory inside the current document root
because they do not yet apply the indexing and web-access protections described
below. The plugin always excludes its logical installed directory from push,
including when that directory is a symlink to a physical target outside the
document root. If the physical target is also inside the document root, both
document-root-relative paths are excluded. A platform hook or embedding router
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
      patch_base_index.jsonl            patch base without newly default-skipped entries
      local_paths_to_push.jsonl         local paths to push
      local_paths_to_delete             raw NUL-delimited document-root-relative paths
      deleted_directories_stack.jsonl   active directory-deletion roots
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

During PushPlan's internal `filtering_patch_base` phase, the plan retains the
open local index and patch base across steps. A reopened plan truncates the
patch base and seeks both files to their stored byte offsets. During
`indexing`, it retains one `FileIndexProcessor` and the open fresh local index
across steps. A newly opened plan truncates that file to the byte offset stored
with the processor cursor before continuing. The sender lazily opens
`local_paths_to_push.jsonl`, `local_paths_to_delete`, and the current local file.
It retains those handles across `next_step()` calls, lets each handle advance
with the work, and seeks only when a newly opened or receiver-confirmed offset
differs. It closes each handle when its phase or file ends and closes any
remaining handles before `close()` returns.

The sender stores the mandatory preflight document root, creates the push session,
and stores its exclusion policy before it starts PushPlan. Each internal
`filtering_patch_base` step reads at most one local-index entry, each
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
`local_paths_to_push.jsonl`, the selected local-path count and file byte total,
the target-confirmed local-path count and completed file bytes, the receiver
part limit, and learned request-body sizing state. Its phases are `creating`,
`finishing_previous_commit`, `starting_plan`, `planning`, `pushing_paths`,
`pushing_deletes`, `committing`, `saving_local_index`, `completing`, `removing`,
and `discarding_plan`.
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
replays it. The sender persists completed file sizes with that path-list
boundary. It keeps a partial file's receiver-confirmed byte offset only in
memory and reads it from `push_status` again after resume.

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
`--fs-root`, an authorized key or connection token, and saved preflight data; HTTPS is required unless the
operator passes `--insecure` or sets `REPRINT_INSECURE_TLS=1`. It reads but never writes
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

Like other client commands, files-push accepts
`--progress=auto|tty|jsonl|compact` for one invocation. The default `auto` mode uses
terminal progress when its output stream is a TTY and JSONL otherwise. `tty`
and `jsonl` force the corresponding presentation. `compact` prints stage changes,
command results, warnings, and errors without saving the omitted records.
Within a stage, it prints changed item and byte counters at most once every
30 seconds, without per-file details. These updates require an existing progress
event; there is no separate timer that prints while a request is blocked.
`progress.json` snapshots and `audit.log` are unchanged. These modes are not
stored in sender state and cannot be combined with `--verbose`.

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
interruptions, and terminal outcomes. The terminal presentation uses one
stage-weighted progress bar. The percentage precedes a label which changes only
at the major `Preparing`, `Indexing`, `Pushing`, `Pushing deletions`,
`Committing`, `Saving index`, and `Finishing` stages. The index diff advances
from durable byte offsets across both indexes, local-path upload advances from
target-confirmed path counts, and deleted-path upload advances from the
target-confirmed deletion-list byte offset. While local paths are pushed, the
line also shows the sum of target-confirmed file bytes against the file byte
total accumulated by the single-pass plan. Indexing and commit have no bounded
total, so they advance only at phase milestones. The percentage is lifecycle
progress, not a time estimate.

PushPlan reports its internal phase and durable index byte counts.
PushFilesSender combines those with target-confirmed upload counts in one
progress snapshot. The CLI alone maps that snapshot onto stage weights and
terminal labels.

The overall bar is terminal-only. The JSONL presentation emits the same
throttled `push_progress` records as before.
After planning, those records, the final result, and the flat progress file
include `files_done` and `files_total` together. The total comes from the
completed plan; the completed count advances only when the target confirms the
request containing a local path, and both counts survive resume. They are
absent while planning. Neither the audit log nor the progress file copies
receiver cursors or tentative upload positions.

## Local runtime cleanup and file pushes

`apply-runtime` removes known host-plugin copies by default even when the pull
preserved them. Before removal, it saves the document-root-relative paths in
`apply.remote_paths_removed_from_local_site`. `files-push` supplies that record
as caller exclusions, so cleanup does not become a source-host deletion.
Exclusions also prevent an ancestor deletion or replacement from covering a
protected path. Command resets, database imports, and later runtime opt-outs
retain the record; skipping cleanup does not restore removed files.

Runtime cleanup rejects an unfinished files-push or files-pull. A push therefore
keeps the same exclusions when resumed, and a pull must finish or be aborted
before setup changes its local files.

## Local files-diff command

`reprint files-diff <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR [--progress=auto|tty|jsonl|compact]`
reports a local minimized push operation plan before target exclusions: the
local paths a files-push would send or delete, compared against the same
filtered patch base used by files-push. The retained local index is not
changed. Files-pull advances that local index after
completed local mutations, and files-push replaces it after the target
confirms commit. The remote state directory is
`<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>`, so another URL
query cannot reuse the index. A different filesystem root uses a different
state directory. The command accepts only `--state-dir`, `--fs-root`, and the
optional `--progress`; it needs no secret, performs no preflight, and makes no
network request. It reads any local runtime cleanup record from pull state and
excludes those paths, with the remote document root prepended for its
filesystem-root-wide plan. It runs one complete PushPlan against the local index in
`<remote-state-directory>/push/files-diff-plan/` while the command holds the
state-directory-wide Reprint process lock.

`--progress` accepts `auto`, `tty`, or `jsonl`. `auto` is the default: it
selects `tty` when stdout is a TTY and `jsonl` otherwise. `tty` uses
`modified: PATH` for each local path to push and `deleted: PATH` for each local
path to delete, both in Git's red status color. Paths containing unsafe bytes
use C-style quoting so one path always occupies one output line. `jsonl`
selects the previous uncolored machine-readable contract. Each selected
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
so that configuration is rejected. Choose a location outside every web-served
directory before enabling push.

## Commit

Commit applies the uploaded work to the live document root. Each request does
part of that work and saves its position, so another request can continue if
the process stops. The order is:

1. **Receive work, outside maintenance:** multipart requests write one bounded
   chunk at a time into `work/inflight.data` and move complete values into
   `work/files`. The site runs normally throughout receipt.
2. **Maintenance on.** Commit writes the `.maintenance` file itself, and since
   WordPress executes that file, it allows Reprint API requests to
   continue while other WordPress requests see maintenance mode. WordPress's own
   rule that a `.maintenance` file older than 10 minutes is ignored stays
   intact, so maintenance mode expires if no later commit request arrives.
3. **Commit:** consume `work/deletes`, then `work/files`, with the durable
   `commit.json` checkpoint written before each document-root mutation. The
   symlink updates follow the same bounded cursor. Database overwrite uses
   the separate workflow described below.
4. **Maintenance off:** commit releases its `commit-state` record after
   completion; the driver saves the local index for that remote Reprint API URL
   after the target confirms commit.

If the driver dies mid-commit: WordPress stops honoring the `.maintenance`
file after 10 minutes on its own, and the next commit request resumes from
`commit.json` — no worker or cron needed.

**The reprint plugin's own directory is never touched by commit.** It is
excluded from installs and deletes and reported as excluded in the summary.
Updating reprint itself is a separate concern, never part of a sync.

## Finish a commit when WordPress cannot start

A replaced plugin can cause a PHP fatal error before commit finishes. The
normal WordPress API route may then fail too, because starting WordPress loads
that plugin.

The client can continue through a standalone PHP route that reads
`wp-config.php` without starting WordPress. It uses Reprint's own filesystem
and database code to finish the remaining work. Keep that route independent
of WordPress startup when changing routing, and test a resumed commit with
WordPress startup failing.

## Replace database data separately

`files-push` does not transfer the active local database. Use `db-push` when you
intend to replace the selected production tables, including rows added on
production since the last pull. That operation requires a separate host route
and a pause of all database writers before commit. Follow the
[database push guide](https://developer.wordpress.org/reprint/contributing/database-push/)
for the table review, confirmation, and checks before reopening the site.

## Accepted limitations

These file-push limits do not replace the database overwrite requirement to
stop all writers:

- **Same-size corruption is invisible.** Transfers are verified by byte
  count only. Corruption that preserves length passes. The target does not compare content hashes.
- **Remote edits are overwritten.** Push is a local-wins deployment tool, not
  a bidirectional conflict resolver. Developers who edit the remote site
  outside Reprint are responsible for pulling or preserving those changes
  before pushing over them.
- **Shell and cron can write during the maintenance window.** Maintenance
  blocks web requests; it cannot block SSH or system cron.

The active local SQLite database is not part of files-diff or files-push.
Both commands derive its path from the saved db-apply target and exclude that
file, its `-wal`, `-shm`, and `-journal` companions, and its
`.reprint-import.lock` file. Other SQLite files remain ordinary site content.
Use db-push to transfer the active local database.
