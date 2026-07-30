# Push terminology

This is the vocabulary contract for every push surface. Read it before
changing push code, tests, documentation, plans, review replies, commit
messages, or pull-request descriptions.

## Core nouns

- A **push session** receives one resumable set of work for one document root.
- The **reprint directory** is the private directory supplied to the push session.
- The **document root** is the directory changed by commit.
- **Excluded paths** are document-root-relative paths that a push must not
  change.
- **Work files**, **in-flight work**, and **work deletes** are the only durable
  work owned by a push session. Work files are complete values. In-flight work
  is the one value currently being received or completed.
- **Commit** consumes work deletes and work files; it does not create or remove
  a push session.
- **Remove** deletes a push directory in bounded calls.

## PHP names

Use these names verbatim:

| Surface | Name |
| --- | --- |
| Exception class and file | `Site_Export_Push_Exception`, `class-push-exception.php` |
| Session class and file | `Site_Export_Push_Session`, `class-push-session.php` |
| reprint directory | `$reprint_directory` |
| Document root | `$docroot` |
| Excluded paths | `$excluded_paths` |
| Push session identity | `$push_session_id` |
| Push directory | `$push_directory` |
| Push metadata | `$push_metadata` |
| Commit checkpoint | `$commit_state` |
| In-flight work record | `$work_inflight_path` |
| In-flight file data | `$work_inflight_data_path` |
| Work-delete offset | `$work_deletes_byte_offset` |
| Current delete path | `$current_delete_path` |
| Current work-files descendant | `$current_work_files_descendant` |
| Commit cursor | `$commit_cursor` |

The public operations are `create()`, `open()`, `remove()`, and `commit()`.
Use `get_push_session_id()` and `get_push_directory()` for their accessors.

## Durable layout and JSON

Every push directory is located at:

```text
<reprint-directory>/.reprint/push/<push-session-id>/
```

It contains `push.json`, `push.lock`, optional `commit.json`, and `work/`. The
work directory contains `files/`, optional `inflight.json` and
`inflight.data`, `deletes`, and an optional private maintenance copy. `files/`
is the only path-shaped work tree. The shared `.reprint/push/` directory contains
`push-create.lock`, `commit-state`, `commit-state.lock`, and bounded removal
tombstones named `.removing-<push-session-id>/`.

`push-create.lock` is the create/remove lock. Create and every bounded remove
call acquire it non-blockingly. Remove holds it while inspecting and renaming
the live push directory and while performing one tombstone cleanup step, so
create cannot recreate the same push session until that cleanup finishes.

`inflight.json` records the path and type of the one in-flight work value.
File records use `preparing`, `receiving`, or `completing` and also contain
`total_bytes`. Directory and symlink records use `preparing` or `completing`;
symlink records also contain `target_b64`. `inflight.data` contains in-flight
file bytes, and its actual size is the receiver-confirmed cursor. Both paths
are absent when no work is in flight.

`push.json` has the keys
`push_session_id`, `docroot_b64`, `excluded_paths_b64`,
and `work_deletes_complete`.

`commit.json` has the phases `deleting_files`,
`installing_files`, and `complete`. Its cursor keys are
`work_deletes_byte_offset`, `current_delete_path`,
`current_work_files_descendant`, and `commit_cursor`. Path values remain
base64 text even where a key does not include a suffix. Its non-recoverable
failure key is `non_recoverable_commit_failure`.

These JSON records are unversioned while their schemas are still under
development. There are no compatibility aliases or migration paths.

## Local Reprint process lock

Every local Reprint command workflow runs under the **Reprint process lock** at
`<state-dir>/.reprint.lock`. Use `.reprint.lock` and `$process_lock`. The lock
is non-blocking and state-directory-wide: pull, push, diff, and other local
Reprint processes cannot run concurrently against the same state directory,
even when they use different target URLs or local trees.

The production CLI acquires the Reprint process lock before it prepares the
files command context, constructs `ImportClient`, or writes the command audit
entry. It passes the open lock to `ImportClient::run()` and releases it after
the command.
A direct `ImportClient::run()` call acquires the lock when its caller supplies
none. `PushFilesSender::start()` and `PushFilesSender::resume()` receive that
open lock from the caller; sender `close()` does not release it. This local
lock is separate from the receiver's push-session and commit locks.

## Pull index-update WAL

Call the single pull-side write-ahead log the **index-update WAL**. It lives at
`<state-dir>/remote-<hash>/pull-index-updates.wal`; use
`pull-index-updates.wal`,
`$index_update_wal_path`, and `$index_update_wal_handle`.
Applied batch records are cleared, but the empty WAL remains as a marker until
files-pull completes. A retained WAL is consumed only while resuming or
aborting the interrupted files-pull, including through a high-level pull
command; unrelated commands do not consume it.

Each WAL record has an `op` of `F` or `D` and a
`remote_path_b64`. F records also have `remote_type`, `remote_size`, and
`remote_ctime`. A completed local mutation adds `local_path_b64`; its F record
also has `local_type`, `local_size`, and `local_ctime`. Replay applies the
remote projection to `.remote-index.jsonl`, then applies the local projection
to `.local-index.jsonl`. The WAL is cleared only after both replacements
succeed.

## Local index and push state

One target URL in a state directory uses:

```text
<state-dir>/remote-<sha256(target URL with user-info, SECRET_KEY, and site-export-api removed)>/
  .remote-index.jsonl
  .remote-index.next.jsonl
  .local-index.jsonl
  pull-plan.jsonl
  pull-index-updates.wal
  push/
```

The hash omits URL user-info, `SECRET_KEY`, and the `site-export-api` endpoint
alias; changing any other query parameter selects a different remote state
directory. A different local document root uses a different state directory.

Use `remote_state_directory`, `$remote_state_directory`, and `remote state
directory` for `remote-<hash>/`. Use `local_index_path`, `$local_index_path`,
and `local index` for `.local-index.jsonl`. The local push state directory is
`remote-<hash>/push/`. The hash is only a directory name; it is not part of
the command result or sender cursor.

Use these names verbatim:

| Surface | Name |
| --- | --- |
| Local index path | `local_index_path`, `$local_index_path` |
| Active plan directory | `plan`, `$plan_directory` |
| Plan-owned fresh local index | `.local-index.next.jsonl`, `$fresh_local_index` |
| Byte offset in the local index | `byte_offset_in_local_index`, `$byte_offset_in_local_index` |
| Paths to push | `paths-to-push.jsonl`, `$local_paths_to_push` |
| Target paths to delete | `paths-to-delete`, `$target_paths_to_delete` |
| Local paths to delete | `local-paths-to-delete`, `$local_paths_to_delete` |
| Local push state directory | `push_state_directory`, `$push_state_directory` |
| PushPlan cursor | `push_plan_cursor`, `$push_plan_cursor`, `get_cursor()` |
| Sender-owned excluded paths | `excluded-paths.json`, `$excluded_paths_path` |
| Deleted directory path | `deleted_directory_path_b64`, `$deleted_directory_path` |
| Active state | `sender.json`, `$state_path` |
| Plan-owned committed local-index updates | `plan/local-index-updates.jsonl` |
| Selected path-list cursor | `$local_paths_to_push_byte_offset` |
| Local path type, size, and ctime | `local_path_type_size_and_ctime`, `$local_path_type_size_and_ctime`, `stat_local_path()` |

`sender.json` and `excluded-paths.json` live directly under the local push
state directory. The sender creates `plan/` for one active plan and starts
PushPlan with the local index path and sender-owned excluded paths.
`.local-index.next.jsonl`, `paths-to-push.jsonl`, `paths-to-delete`,
`local-paths-to-delete`, and the post-commit `local-index-updates.jsonl` live
inside `plan/`. Each paths-to-push record stores both `local_path_b64` and
`target_path_b64`. `paths-to-delete` stores target paths for the request;
`local-paths-to-delete` stores the paired local paths for the committed
local-index update.

`path-mapping.json` stores the target document root and the resolved remote and
local prefix rules for one remote state directory. The first mapping is
immutable. Push validates it before opening a push request, reads and stats
`local_path_b64`, addresses the receiver with `target_path_b64`, and rewrites
pulled symlink targets back into target coordinates. A relative symlink target
outside the local tree is rejected when the symlink path itself is remapped.

Completing files-pull creates the local index when it is missing. Each actual
local mutation is recorded in `pull-index-updates.wal` with the local path and,
for an F record, the local path type, size, and ctime observed after the
mutation. Applying a WAL batch first updates the remote index, then applies
those local mutation records to the local index. An F update also adds its
directory ancestors. A D update removes the path and its descendants.
Default-skipped paths do not enter the local index. The WAL batch is cleared
only after both index replacements finish.

Selected, filtered, remapped, and preserve-local pulls update only paths they
actually change, their directory ancestors, and descendants removed by a
deletion or type replacement. Other branches remain unchanged, so local
additions, edits, and deletions elsewhere remain pending. Aborting files-pull
replays the current WAL batch into the remote index and local index before it
clears pull progress and keeps the local index. Resuming or aborting files-pull
is the only way to consume its retained WAL. files-pull refuses to start while
the same target and local tree have an unfinished files-push, so the local
index cannot change while PushPlan is between processes.

files-diff reads the local index without changing it. PushPlan diffs its fresh
local index directly against that local index.
`byte_offset_in_local_index` is the position from which PushPlan reads its
current local-index lookahead entry again after resume.

The PushPlan cursor is stored in `sender.json`. During `indexing`, it contains
the FileIndexProcessor cursor and the committed byte offset in
`.local-index.next.jsonl`. During `diffing`, it contains the index offsets,
output offsets, and `deleted_directory_path_b64` while descendants of one
deleted directory need no separate deletion. The exclusions have a maximum of
100 paths. `sender.json` phases are `creating`, `starting_plan`,
`planning`, `pushing_paths`, `pushing_deletes`, `committing`,
`updating_local_index`, `completing`,
`removing`, and `discarding_plan`. It stores the push session ID, selected
path-list cursor, receiver part limit, and request-sizing state. The index diff
completes before local paths are sent. After a successful commit, the sender
writes `plan/local-index-updates.jsonl` from the committed path lists and
applies it to the local index. The merge adds directory ancestors for each F
update and removes the path and its descendants for each D update. If the local
index does not exist, the same atomic merge creates it. Paths excluded by the
target do not enter the local index. The post-commit update has no separate
cursor and is repeated after interruption.
After the updates are applied or the target confirms removal,
the sender clears the PushPlan cursor, then removes the entire plan directory
and the sender-owned excluded paths file. It does not ask PushPlan to manage
terminal cleanup; PushPlan only closes its open handles.

A request failure ends the current sender run. The active state remains in
place so a later push command can resume from the last durable boundary. Only
an explicit `request_too_large` failure lowers future request sizes.

When a local path to push changes, the sender reports `local_path_changed` and
moves to `removing`. After removal a new sender builds a fresh local index. The
sender trusts the completed deletion plan without checking the live local tree;
changes after planning belong to the next push.

Receiver-confirmed file and work-delete cursors remain receiver state. A newly
opened sender reads them from `push_status`, while a successful upload retains
them in memory for later steps in the same lifecycle. It does not copy them into
`sender.json`.
`push.json` remains the receiver-owned push identity and policy, while
`commit.json` and `$commit_state` remain the receiver commit checkpoint.

`PushFilesSender::start()` and `PushFilesSender::resume()` require the
caller-owned Reprint process lock. `next_step()` does not acquire or release
the lock and does not reread `sender.json`. A sender retains one multipart
request across steps. A caller stopping between steps calls `cancel()` to
discard that request and return to the preceding durable boundary, then calls
`close()` to release sender resources. `close()` never finishes an open
request. In `pushing_paths` or
`pushing_deletes`, one step sends at most one multipart part. `next_step()`
returns true while another step may be performed and false when `get_status()`
reports `complete`, `restart`, or `failed`.

## Files-diff CLI names

The local-only command is `files-diff`. Its `target URL`, `local tree`, and
`local push state directory` have the same meanings as `files-push`. It reads
the local index updated by files-pull and committed files-push operations and
never changes it. A partial pull updates only the paths it changed, leaving
pending changes elsewhere in the diff. The target URL uses the same exporter
endpoint path and query as the file-only pull but omits URL user-info and
`SECRET_KEY`. It includes the `site-export-api` query which `pull-files` adds
to a bare site URL.

Each JSONL change record has `command: "files-diff"`, an `action` of `push` or
`delete`, `local_path_b64`, and `target_path_b64`. A push record also has the
local path `type`, `size`, and `ctime`; its type is `file`, `dir`, or `link`.
These records form a local minimized push operation plan before target
exclusions: descendants represent a new non-empty directory, one deleted
subtree root covers its descendants, and metadata-only changes to non-empty
directories select no operation. The final record has `status: "complete"`,
`local_paths_to_push`, and `local_paths_to_delete`.

files-diff persists nothing between runs. It runs one complete PushPlan in
`files-diff-plan/` while its command holds the state-directory-wide Reprint
process lock, streams both finished path lists from the beginning, and removes
the plan directory before exiting. An interrupted report is not resumed;
running the command again prints the complete report.

## Files-push CLI names

The low-level, files-only command is `files-push`. Its `target URL` is the
exporter API URL, and its `local tree` is the canonical directory supplied by
`--fs-root`. It requires `--secret=TOKEN`; `--force-http` is the explicit
plain-HTTP opt-in.

The same SHA-256 digest names the local index and local push state directory:

```text
sha256(<target-url-without-authentication>)
```

The local index is `<state-dir>/remote-<hash>/.local-index.jsonl`, and the
`local push state directory` is `<state-dir>/remote-<hash>/push/`. `files-push`
chooses `start` or `resume` only from whether `sender.json` exists there. The
hash omits URL user-info, `SECRET_KEY`, and the `site-export-api` endpoint
alias; files-push receives its secret through `--secret`. A different local
tree uses a different state directory.
receiver-confirmed upload positions remain receiver-owned; they are not a
files-push cursor and are not copied into `.import-state.json` or
`.import-status.json`.

Files-push lifecycle lines use these command-first names verbatim: `START
files-push`, `RESUME files-push`, `PHASE files-push`, `PARTIAL files-push`,
`INTERRUPTED files-push`, `COMPLETE files-push`, `RESTART files-push`, `FAILED
files-push`, and `ERROR files-push`. Planned stop causes are `time_limit` and
`memory_limit`.

The CLI outcome names are `complete`, `partial`, `interrupted`, `restart`,
`failed`, and `error`. `complete` exits 0; `partial`, `interrupted`, and
`restart` exit 2; `failed` and `error` exit 1. A `restart` ends the current
process, and the next invocation starts a fresh plan.

## PushFilesSender names

Use these names verbatim inside `PushFilesSender`:

| Meaning | Name |
| --- | --- |
| Local path to push | `LocalPathToPush`, `$local_path_to_push`, `read_next_local_path_to_push()` |
| Local path to delete | `LocalPathToDelete`, `$local_path_to_delete`, `read_next_local_path_to_delete()` |
| Push stream client | `$push_stream_client`, `create_push_stream_client()` |
| Push stream client options | `$push_stream_client_options` |
| Request sizer options | `request_sizer_options`, `$request_sizer_options` |
| Push request | `send_push_request()` |
| Open upload request stage | `$upload_request_stage`: `closed`, `sending_parts`, or `finishing` |
| Whether the open request has sent parts | `MultipartPushStreamClient::has_sent_parts()` |
| Create push session | `create_push_session()` |
| Remove push session | `remove_push_session()` |
| Upload next file chunk | `upload_next_file_chunk()` |
| Upload next chunk of deleted paths | `upload_next_chunk_of_deleted_paths()` |
| Request result | `$request_result` |
| Plan result | `$plan_result` |
| Sender status | `$status`, `get_status()` |
| Sender phase | `get_phase()` |
| Sender outcome classification | `$reason`, `get_reason()` |
| Sender outcome explanation | `$detail`, `get_detail()` |
| Receiver path status | `$receiver_path_status` |
| Receiver path type | `$receiver_path_type` |
| File byte offset for the next part | `$file_byte_offset` |
| Whether this upload completes the local path | `$upload_completes_local_path` |
| Maximum file payload bytes | `$maximum_file_payload_bytes` |
| Maximum delete-list payload bytes | `$maximum_delete_list_payload_bytes` |
| Local I/O failure detail | `$local_io_failure_detail` |
| Whether the target delete list is complete | `$target_delete_list_complete` |
| Copy through a swap file | `copy_through_swap_file()`, `$source_path`, `$target_path` |
| Open directory handle | `$directory_handle` |
| Open local paths-to-push handle | `$local_paths_to_push_handle` |
| Open target paths-to-delete handle | `$target_paths_to_delete_handle` |
| Open local file handle | `$local_file_handle` |
| Local path stat result | `$path_stat` |
| File type bits | `$file_type_bits` |
| Delete active state | `delete_state()` |

## PushPlan names

Use these names verbatim inside `PushPlan`:

| Meaning | Name |
| --- | --- |
| Active plan directory | `$plan_directory` |
| Local tree root | `$local_tree_root`, `set_local_tree_root()` |
| Local index path | `$local_index_path` |
| Open local index | `$local_index_handle` |
| Local index lookahead entry | `$local_index_lookahead_entry`, `$local_index_lookahead_entry_loaded` |
| Byte offset in the local index | `$byte_offset_in_local_index` |
| Cursor | `$cursor`, `get_cursor()` |
| Excluded paths file | `$excluded_paths_path` |
| Fresh local index processor | `$file_index_processor`, `next_file_index_step()` |
| Fresh local indexing cursor | `IndexingCursor`, `file_index_cursor` |
| Fresh local index byte offset | `$fresh_local_index_byte_offset` |
| Open fresh local index | `$fresh_local_index_handle` |
| Index diff cursor | `IndexDiffCursor` |
| Start index diff | `start_index_diff()` |

## Protocol names

The work-upload endpoint is `push_upload` and its push-session parameter is
`push_session_id`. Endpoint names and endpoint prefixes begin with `push_`.
JSON responses use `push_session_id`, `receiving_work`, and
`work_deletes_bytes`. Push-session failures are
`lock_acquisition_failure`, `offset_gap`, `push_not_found`, `filesystem_error`,
`commit_required`, `unexpected_docroot_mutation`, `corrupted_push_state`, and
`same_device`. Authentication, authorization, and request-boundary failures
are `auth_failed`, `push_disabled`, `not_configured`, `invalid_request`, and
`request_too_large`.

The document-root `.maintenance` file identifies its owner with the push
session ID. `commit.json` stores no separate maintenance value.
