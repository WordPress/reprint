# Multipart staged push

Reprint push deploys a local file tree to a WordPress target. It is a
local-wins, file-only deployment mechanism: database push and upload gzip are
out of scope. Upload gzip remains a pull-response feature. A resumable push
uses raw bytes and a per-part Content-Length; gzip would need separate wire and
source byte counts plus resumable inflation semantics.

The sender computes a normalized final-tree delta against its own last
successful baseline. Every logical path appears at most once as a file, an
explicit empty directory, a symlink, or a delete.
A file can occupy many multipart parts and a network retry can resend bytes,
but neither is another logical change.

## Sender state and resume

For each target URL, the sender keeps an on-disk current snapshot, positive
change list, delete list, active session checkpoint, and successful baseline:

~~~
<state-dir>/push/<target>/
  current-local-files.jsonl
  local-paths-to-push.jsonl
  local-paths-to-delete.jsonl
  session.json
  last-sync-local-files.jsonl
~~~

The source scan reads directory entries one at a time into an unsorted JSONL
file. A bounded external merge sort then orders that disk file for the streaming
baseline diff; no directory listing or complete snapshot is retained in memory.

The JSONL files carry base64 paths because file names are arbitrary bytes. The
active checkpoint stores the target-issued session ID and only byte offsets
that the target has confirmed. It also stores a source fingerprint—size and
ctime—for a partial file. Before resuming, the sender reads the file again:
matching fingerprints permit the target-confirmed offset; a mismatch restarts
that file at offset zero. It never appends new-version bytes to an old staged
prefix.

An interrupted response is an unknown outcome, not a successful upload. The
sender asks status for the affected path and persists the result derived from
the target staging tree. It advances the local baseline only after commit
reports complete, by atomically publishing the snapshot used to make the plan.
A source change after a snapshot was made therefore remains a delta for the
next push rather than being silently called deployed.

The token is deliberately small rather than a content hash. A same-size edit
within the filesystem ctime resolution can escape both it and the snapshot
diff, which uses the same size and ctime signals.

## Endpoint vocabulary and authentication

Push uses the existing ?endpoint= router and signed HMAC envelope. The method
and complete request target, including query parameters, are authenticated
before the upload handler opens php://input.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | staged_session_create | Create or reopen the deterministic server-owned session for a create token. |
| POST | staged_session_upload | Stream multipart parts into that session. |
| GET | staged_session_status | Report target-derived state for explicitly named paths. |
| POST | staged_session_commit | Delete planned roots, then install staged values directly until complete. |
| POST | staged_session_discard | Remove abandoned private work or a completed session. |

The old artifact, JSON-line, push, and advance routes are not aliases and are
not a second protocol. Create reports target-owned per-part and per-request
limits. Ordinary preflight also exposes a server-derived staged_push capability
object: availability, filesystem suitability, and safe limits, but never the
shared secret. This discovers a bad staging mount before a session exists.

Status accepts one or a small bounded set of base64 paths. For each it reports
missing, partial with actual staged bytes, or complete with type and size. It
never echoes an uploader's claimed offset as truth and does not scan the whole
workspace.

Classified target failures use the same descriptive string inside the server
and in the authenticated JSON `reason`; PHP's integer exception code is not a
second vocabulary. `apply_not_configured` maps to 503, `busy` to 423,
`session_not_found` to 404, `commit_required`, `live_tree_changed`,
`cross_device_filesystem`, and `invalid_session_state` to 409, and
`retryable_io_error` to 500. A runtime failure without one of those deliberate
classifications remains the generic 409 `session_rejected` response instead
of acquiring a code by accident.

## Multipart upload

One bounded HTTP request contains many raw multipart/mixed parts:

~~~
Content-Type: multipart/mixed; boundary=<fresh-boundary>
~~~

Every part has Content-Length. The outer request can use transfer streaming
without an overall Content-Length. Paths and symlink targets remain base64 in
headers; bodies are raw bytes.

| Meaning | Header |
| --- | --- |
| part type | X-Chunk-Type |
| file path, total bytes, offset | X-File-Path, X-File-Size, X-Chunk-Offset |
| directory path | X-Directory-Path |
| symlink path and target | X-Symlink-Path, X-Symlink-Target |

Pull-only fields such as X-Cursor, ctime, X-Chunk-Size, and first/last chunk
flags do not travel in push. A file part completes exactly when:

~~~
X-Chunk-Offset + Content-Length == X-File-Size
~~~

`X-Chunk-Type: directory` is a complete empty-directory replacement and has an
empty body. Push does not transmit or reproduce source permission modes; a
mode-only source change is not a push change.

The sender accounts for MIME delimiters and part headers as well as payload
bytes when sizing a request. Its file-read chunk is a separate, small
in-memory limit. A short source read produces a smaller valid part rather than
a promised byte count that cannot be fulfilled. A 413 permanently lowers the
learned request ceiling; an accepted empty request does not count as evidence
that it is safe to grow the ceiling.

Deletes travel in the one extra part type:

~~~
X-Chunk-Type: delete-list
Content-Type: application/octet-stream
Content-Length: <N>
X-Delete-Offset: <confirmed-byte-offset>
[X-Delete-Complete: 1]

<target-relative-path>\0<target-relative-path>\0
~~~

The target validates paths one at a time and stores the raw NUL-delimited byte
stream in `work/deletes`. Every part carries the sender's confirmed byte
offset. An offset before the stored end is an exact replay: matching bytes are
accepted and a matching suffix may continue the stream, while different bytes
are rejected. An offset beyond the actual file size is a gap and is rejected.
Status reports that actual size as `delete_bytes`; it never trusts a claimed
cursor.

After the last delete byte is confirmed, the sender sends an empty part with
`X-Delete-Complete: 1` at the actual end offset. That explicit declaration is
separate from ending on a NUL byte. Commit rejects a missing declaration and
rejects a nonempty delete stream whose last record is unterminated. This is not
a positive-change journal: a missing path in the staged tree can mean either
unchanged or deleted, so deletes need their own durable representation.

## Caller-driven streaming

The upload processor is shaped like WP_HTML_Tag_Processor: the endpoint owns
the outer loop and the session owns one current part. There are no callbacks
into the endpoint and no buffered request body.

~~~php
$multipart = new Site_Export_Multipart_Processor($boundary);
$session->accept_upload($input, $multipart);
try {
    while ($session->next_change()) {
    }
} finally {
    $session->finish_upload();
}
~~~

Site_Export_Multipart_Processor is the one multipart implementation for both
pull responses and push requests. It owns no stream and invokes no callbacks.
A transport appends one bounded byte fragment, then the caller advances through
part-start, body, and part-end tokens until the processor asks for more input.
The pull cURL callback drains those tokens before returning. The push session
reads a bounded fragment from php://input only when needed and stops after one
part-end has been durably staged.

The processor requires the framing Reprint has emitted since its first
multipart exporter: the message starts with its first boundary, syntax uses
CRLF, every part has one decimal Content-Length, header names are unique, and a
closing boundary is required. Bounded MIME header continuation lines remain
valid because unfolding them does not make body framing ambiguous. The
processor does not carry the old parser's generic-MIME tolerance for LF-only
syntax, boundary-framed bodies, malformed or duplicate headers, or preambles
and epilogues. Those forms were capabilities of the original parser, not
formats emitted by Reprint. Mandatory lengths let arbitrary file bytes pass
without a boundary search and make premature EOF distinguishable from a clean
close; one grammar also prevents the authenticated upload endpoint from being
accidentally constructed in a permissive mode.

The processor retains only a bounded input fragment, bounded headers, and one
current body token. A malformed header, truncated body, invalid path, or offset
gap throws before any later part is staged. finish_upload() releases the session
lock even after failure; completed staging stays durable. When the target's
part-count cap is reached, it deliberately stops after the accepted part's
declared body and leaves the request suffix unread; `send_next_request` then
makes the sender continue in a fresh request.

On the sender, MultipartPushStreamClient holds one cURL multi handle across
requests and uses CURL_READFUNC_PAUSE. send_part() pumps cURL until the
complete part has been handed to the network; it never collects future parts
in a request string. This requires PHP 8.1 or newer at runtime. Timeouts are
per phase and progress based: connect, upload stall, and response wait, not a
total-transfer deadline. Plain HTTP is rejected unless the caller opts into
--allow-http for local development.

The target may stop only between completed parts at its part cap and returns
send_next_request: true; the sender uses the same cap and starts a fresh
request. It does not resume a PHP parser object. A later request starts a new
reader and resumes entirely from durable files.

## Target workspace

Each server-owned session has this private shape:

~~~
apply-sessions/<session-id>/
  session.json       session identity and delete-completion flag
  lock               excludes concurrent requests to this session
  work/
    files/           complete values and the positive-work queue
    partial/         incomplete file bytes
    deletes          raw NUL-delimited delete paths
    maintenance.php  private identity for this session marker
  commit.json         bounded delete/install progress, once commit starts
~~~

session.json contains the immutable session identity and the bounded boolean
that records the explicit delete-upload completion declaration. It never
becomes a sender-owned target cursor. `work/files` is the only source of
positive work: each successful direct installation consumes the corresponding
staged entry. `work/deletes` is both the negative plan and its durable cursor
source. The target does not create an action list, path index, candidate tree,
backup tree, second positive manifest, or directory enumeration.

For a file part, lstat() on the corresponding work/partial file is the resume
truth:

- a missing file accepts offset zero;
- an offset matching its actual regular-file size appends;
- offset zero discards a partial or completed staged version and restarts;
- every other offset is rejected.

If a crash leaves a full-sized file in `work/partial` before its promotion
rename, status reports that actual size and the sender supplies an empty final
part. The target can then complete promotion without retransmitting contents.

When the actual size reaches the declared total, the target renames the file
into work/files. Directories and symlinks have no body and stage there as
complete values. Only visible work/files entries are commit-ready.

### Filesystem requirement

For now, session storage and the target root must be on one filesystem. Create
checks their device numbers before it creates a workspace; staging and commit
also reject an affected mounted subtree. Reprint never falls back to a
cross-device mv, copy-and-delete, or non-atomic replacement. The embedding
caller must configure durable staging storage explicitly; the WordPress plugin
uses SITE_EXPORT_STAGING_DIR. There is no system-temporary fallback because
direct installation requires same-filesystem rename.

The session storage path is automatically protected if it lies under the
target, as are .maintenance and the installed Reprint plugin. A push cannot
stage, delete, or replace those paths. Target and workspace inspection uses
lstat() and refuses symlinked parents rather than following them.

## Commit, maintenance, and recovery

The first commit requires an explicitly completed, NUL-terminated delete
stream, rejects every incomplete file under work/partial, writes commit.json,
and closes upload. It then claims a target-wide coordinator. Only one session
may mutate a target at a time, and the caller repeats commit while
send_next_request is true.

Commit first consumes every delete root in stream order. A recursive delete
uses lstat() and removes at most one leaf or empty directory per bounded step;
it never follows a symlink or crosses onto another mounted filesystem. Once
the raw delete cursor reaches the file's actual size, commit consumes the
staging tree directly. It walks `work/files` a path level at a time, creates
only required structural live directories, and renames each completed file,
empty directory, or symlink directly into its live destination. Successful
renames consume the staged values, so the remaining tree is the queue.

commit.json contains the delete byte offset, at most one current deletion, at
most one current installation, and a path-depth-bounded structural traversal
stack. The target persists the current operation before live mutation. On
restart it resolves that operation from the actual staged and live entries:
the value is either still staged and must be installed, or is already live and
can be acknowledged. The same rules cover interruption between delete steps,
directory creation, rename, and checkpoint persistence without planning or
buffering the rest of the tree.

Maintenance begins before the first delete or install step and remains active
until `work/deletes` is fully consumed and `work/files` is empty. A foreign
marker stops the commit. Each request checks and refreshes the session-owned
marker. The marker lets only `staged_session_*` API requests bootstrap
WordPress so authenticated status and commit can resume while ordinary web and
pull requests still receive WordPress's maintenance response. Cleanup removes
the marker only when it still belongs to this session. If cleanup fails, the
session remains retryable with maintenance intact.

The target accepts compatible live drift instead of comparing tree hashes:
deleting an already absent path succeeds, a planned delete removes the live
entry now at that path, and a staged file or symlink replaces a live file or
symlink. Structural changes that would make direct application ambiguous are
terminal. Examples include finding a symlink or file where a required live
ancestor must be a directory, finding a directory where a file or symlink is
to be installed, or encountering a mounted subtree. `live_tree_changed` and
`cross_device_filesystem` responses include the operation, requested path,
conflicting path or device identities, expected types, observed identity, and
a human-readable detail. The checkpoint preserves the structured terminal
error and maintenance remains active; rerunning commit returns the same error
rather than guessing or forcing through it.

A corrupt checkpoint is terminal as well, and discard refuses to assume a
session was upload-only after commit began. Site_Export_Staged_Session_Recovery_Server::serve($options)
is a small non-WordPress bootstrap exposing the same authenticated status,
commit, and discard endpoints for a host emergency route. It is the escape
hatch if a broken plugin or theme prevents normal WordPress boot.

After commit completes, the sender publishes its successful baseline, records a
local `cleaning` phase, and discards the completed target workspace. The target
first renames the locked workspace to `.discarding-<id>`, then removes at most
256 private entries per request. Each removal is durable progress, and later
discard requests resume the tombstone until it is empty. Discard is idempotent
when both names are already absent, so a lost cleanup response is resumed without
recreating or recommitting the session. The local session.json is removed only
after that confirmation, and a failed local removal is reported rather than
silently ignored.

## CLI

The public surface stays deliberately small:

~~~sh
reprint push <target-url> \
  --source-root=DIR --state-dir=DIR --secret=TOKEN \
  [--dry-run] [--abort] [--allow-http] [--verbose]

reprint push-status <target-url> \
  --state-dir=DIR --secret=TOKEN [--allow-http] [--verbose]
~~~

push resumes an existing compatible local session by default. --dry-run builds
and reports the local plan without creating a target session or publishing a
baseline, and rejects when an active push already exists. --abort removes local
state only after target discard confirms; once direct live mutation began it
refuses and tells the operator to rerun normal push.
push-status does not require a source root, does not scan a tree, and never
creates a session.

There are intentionally no public push-upload, push-commit, push-discard,
push-resume, or target/session tuning flags. Those are protocol operations,
not deployment commands.

## Verification focus

Tests use real php -S endpoints, CLI processes, cURL, and temporary trees for
sender/target behavior. Focused unit tests cover multipart boundary and length
handling, path and symlink safety, partial-file rules, target-confirmed
cursors, raw delete replay and completion, maintenance ownership, direct
delete/install interruption recovery, compatible and incompatible live drift,
request-size learning, and the local baseline. Raw TCP tests prove that a part
reaches the network before send_part() returns. Docker end-to-end tests use a
real WordPress target and HMAC requests, kill the push PHP process during commit,
exercise symlinks and empty directories, and mount separate filesystems to
verify cross-device refusal before an unsafe mutation.
