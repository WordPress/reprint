# Multipart staged push

Reprint push deploys a local file tree to a WordPress target. It is a
local-wins, file-only deployment mechanism: database push and upload gzip are
out of scope. Upload gzip remains a pull-response feature. A resumable push
uses raw bytes and a per-part Content-Length; gzip would need separate wire and
source byte counts plus resumable inflation semantics.

The sender computes a normalized final-tree delta against its own last
successful baseline. Every logical path appears at most once as a file, an
explicit empty directory, a symlink, or a delete. A file can occupy many
multipart parts and a network retry can resend bytes, but neither is another
logical change.

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

The JSONL files carry base64 paths because file names are arbitrary bytes. The
active checkpoint stores the target-issued session ID and only byte offsets
that the target has confirmed. It also stores a source fingerprint, size and
ctime, for a partial file. Before resuming, the sender reads the file again:
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
within the filesystem ctime resolution can escape it; the snapshot diff is the
deeper safety net on the next run.

## Endpoint vocabulary and authentication

Push uses the existing ?endpoint= router and signed HMAC envelope. The method
and complete request target, including query parameters, are authenticated
before the upload handler opens php://input.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | staged_session_create | Create or reopen the deterministic server-owned session for a create token. |
| POST | staged_session_upload | Stream multipart parts into that session. |
| GET | staged_session_status | Report target-derived state for explicitly named paths. |
| POST | staged_session_commit | Prepare and switch durable staged changes until complete. |
| POST | staged_session_discard | Remove an upload-only workspace. |

The old artifact, JSON-line, push, and advance routes are not aliases and are
not a second protocol. Create reports target-owned per-part and per-request
limits. Ordinary preflight also exposes a server-derived staged_push capability
object: availability, filesystem suitability, and safe limits, but never the
shared secret. This discovers a bad staging mount before a session exists.

Status accepts one or a small bounded set of base64 paths. For each it reports
missing, partial with actual staged bytes, or complete with type and size. It
never echoes an uploader's claimed offset as truth and does not scan the whole
workspace.

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
| empty directory path | X-Directory-Path |
| symlink path and target | X-Symlink-Path, X-Symlink-Target |

Pull-only fields such as X-Cursor, ctime, X-Chunk-Size, and first/last chunk
flags do not travel in push. A file part completes exactly when:

~~~
X-Chunk-Offset + Content-Length == X-File-Size
~~~

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

<target-relative-path>\0<target-relative-path>\0
~~~

The target validates paths one at a time and appends base64 records to its
private delete list. This is not a positive-change journal: a missing path in
the staged tree can mean either unchanged or deleted, so deletes need their own
durable representation.

## Caller-driven streaming

The upload processor is shaped like WP_HTML_Tag_Processor: the endpoint owns
the outer loop and the session owns one current part. There are no callbacks
into the endpoint and no buffered request body.

~~~php
$session->accept_upload($input);
try {
    while ($session->next_change()) {
    }
} finally {
    $session->finish_upload();
}
~~~

The input is Site_Export_Multipart_Stream_Input, a strict,
boundary-validated reader over php://input. It requires a Content-Length on
every part, bounds headers, retains only a small current body piece, and
refuses to move to another part until the current one is drained. A malformed
header, truncated body, invalid path, or offset gap throws before any later
part is read. finish_upload() releases the session lock even after failure;
completed staging stays durable.

The older callback-oriented MultipartStreamParser was extracted into the
exporter package as Site_Export_Multipart_Stream_Parser, where the importer
keeps a backwards-compatible adapter for pull responses. It retains pull's
optional-length behavior. The request reader is deliberately separate: push
never inherits that fallback or its large emergency buffer.

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
  session.json       immutable session metadata
  lock               excludes concurrent requests to this session
  work/
    files/           complete staged files, directories, and symlinks
    partial/         incomplete file bytes
    deletes.jsonl    accepted delete paths
    prepared/        complete candidate deployment roots
    backups/         live entries moved aside during switching
    maintenance.php  private identity for this session marker
  commit.json         derived action order, phase, indexes, and transition intent
~~~

session.json never becomes upload progress. There is no state.json, no
positive-change journal, and no full-journal replay. work/files plus
deletes.jsonl are the authoritative later commit plan. Once upload closes,
commit.json records only the derived execution order and current transition;
it cannot recreate a positive change without work/files. The only tail repair
is dropping an incomplete final JSONL delete record after a killed upload.

For a file part, lstat() on the corresponding work/partial file is the resume
truth:

- a missing file accepts offset zero;
- an offset matching its actual regular-file size appends;
- offset zero discards a partial or completed staged version and restarts;
- every other offset is rejected.

When the actual size reaches the declared total, the target renames the file
into work/files. Directories and symlinks are complete atomically and stage
there directly. Only work/files entries are commit-ready.

### Filesystem requirement

For now, session storage and the target root must be on one filesystem. Create
checks their device numbers before it creates a workspace; staging and commit
also reject an affected mounted subtree. Reprint never falls back to a
cross-device mv, copy-and-delete, or non-atomic replacement. If the default
temporary staging directory is on another device, define
SITE_EXPORT_STAGING_DIR to a durable directory on ABSPATH's filesystem.

The session storage path is automatically protected if it lies under the
target, as are .maintenance and the installed Reprint plugin. A push cannot
stage, delete, or replace those paths. Target and workspace inspection uses
lstat() and refuses symlinked parents rather than following them.

## Commit, maintenance, and recovery

The first commit claims a target-wide coordinator, writes commit.json, and
closes upload. Only one session may prepare or mutate a target at a time. The
caller repeats commit while send_next_request is true; the target advances a
configured number of durable deployment actions per request. Candidate file
copies use bounded pieces, while a deployment action remains the scheduling
unit for commit progress.

An affected child of wp-content/plugins or wp-content/themes is one atomic
deployment unit. If its final type is a directory, preparation makes a
complete candidate below work/prepared: it starts from the live directory,
overlays completed staged files, directories, symlinks, and deletes, then
switches the candidate as a whole. A new directory starts entirely from
staging. Symlinks inside a candidate are recreated as links and are never
traversed.

The rule is narrower for the root itself: when the final unit is a file or a
symlink, preparation stages and replaces that single entry. It does not
reconstruct a symlink referent. A deleted unit is removed instead. Other paths
are prepared and replaced as individual entries, except a structural
file/directory transition builds the smallest required private replacement
tree. Current code keeps maintenance on for every visible replacement,
including static paths; it does not claim a pre-maintenance static-file
optimization.

At the start of preparation, the target captures the expected live identity
and a recursive lstat fingerprint of a directory's descendants. After the
private candidate is complete, it records those values with the prepared
identity and checks them again before switching. This catches a rewritten or
added child whose parent directory identity alone would not reveal the change.
Like the sender's size-plus-ctime token, a same-size in-place rewrite within
the filesystem timestamp resolution remains an honest stat-based detection
gap. Before a live rename, commit.json records the private backup location too.
Switching is then:

1. rename the old live entry to work/backups, when present;
2. rename the complete prepared entry into its live name;
3. persist the completed transition only after the replacement is visible.

PHP cannot atomically exchange two non-empty directories. The two renames can
briefly leave a path absent, so the switching phase owns a WordPress
.maintenance marker. A foreign marker stops the commit. Each request checks
and refreshes the session-owned marker; cleanup removes it only when it still
belongs to this session. If cleanup fails, the session remains retryable with
maintenance intact.

After a crash, recovery consults the transition intent and observed live,
prepared, and backup identities. It continues only when inode/device evidence
proves which rename happened; an unexpected external replacement is terminal,
not guessed through. A corrupt checkpoint is terminal as well; discard refuses
to assume that it was still upload-only. Backups remain private until all
switches have durable checkpoints. Site_Export_Staged_Session_Recovery_Server::serve($options)
is a small non-WordPress bootstrap exposing the same authenticated status,
commit, and discard endpoints for a host emergency route. It is the escape
hatch if a broken plugin or theme prevents normal WordPress boot.

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
baseline. --abort removes local state only after target discard confirms; once
live switching began it refuses and tells the operator to rerun normal push.
push-status does not require a source root, does not scan a tree, and never
creates a session.

There are intentionally no public push-upload, push-commit, push-discard,
push-resume, or target/session tuning flags. Those are protocol operations,
not deployment commands.

## Verification focus

Tests use real php -S endpoints, CLI processes, cURL, and temporary trees for
sender/target behavior. Focused unit tests cover multipart boundary and length
handling, path and symlink safety, partial-file rules, target-confirmed
cursors, candidate construction, maintenance ownership, two-rename recovery,
request-size learning, and the local baseline. Raw TCP tests prove that a part
reaches the network before send_part() returns. Linux CI mounts a separate
temporary filesystem and verifies that cross-device session creation is
rejected before any live mutation.
