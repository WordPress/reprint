# Reprint terminology

This is the vocabulary contract for every Reprint surface. Read it before
changing pull or push code, tests, documentation, plans, review replies,
commit messages, or pull-request descriptions.

## Coordinate systems and relationship state

Every path name states the machine whose coordinates it uses and whether it
is filesystem-absolute or document-root-relative.

- A **state directory** is the caller-selected local directory containing
  Reprint state for one local document root. Use `$state_directory`.
- The **local document root** is the local directory whose contents are
  compared, pulled, and pushed. Use `$local_document_root`.
- The **import root** is the local directory under which a pulled target
  filesystem is reconstructed. The local document root may be nested inside
  it. Use `$import_root`.
- A **target** is the site addressed by the configured exporter API URL. It is
  the source during pull and the destination during push. Use `$target_url`.
- The **target identity URL** removes URL user-info, `SECRET_KEY`, and the
  `site-export-api` alias from the target URL. Use `$target_identity_url`.
- The **target fingerprint** is the SHA-256 of the target identity URL and is
  only a directory-safe identifier. Use `$target_fingerprint`.
- A **target relationship** is the synchronization relationship between the
  state directory's local document root and one target.
- A **target relationship directory** is the local
  `remote-<target-fingerprint>/` directory containing one target
  relationship's state. Use `$target_relationship_directory`.
- The **target document root** is the absolute document root on the target
  machine. Use `$target_document_root`.

The four domain path terms are:

| Term | Meaning | Preferred name |
| --- | --- | --- |
| Target filesystem path | Absolute path on the target machine. | `$target_filesystem_path` |
| Local filesystem path | Absolute path on the local machine. | `$local_filesystem_path` |
| Target document path | Path relative to the target document root. | `$target_document_path` |
| Local document path | Path relative to the local document root. | `$local_document_path` |

An **absolute path** begins at `/`. A **document path** has no leading slash.
A **normalized path** has repeated separators and `.` or `..` segments removed
lexically; it does not inspect the filesystem. A **resolved absolute path** is
an absolute path whose existing symlinks `realpath()` resolved. An
**uncreated suffix** is the final sequence of segments that does not yet
exist. A **base64 path** describes transport encoding, not coordinates.

Use `resolve_absolute_path_with_uncreated_suffix()` for a path resolver that
retains a missing suffix. Do not call this a canonical path. Do not use a bare
`$path`, `$local_path`, or `$remote_path` when more than one coordinate system
is present.

The pull conversion flow is target filesystem path → local filesystem path →
local document path. Push applies the reverse mapping from local document path
to target document path.

## Indexes and mappings

- A **target index** is the last target filesystem state accepted by pull.
  Its entries use target filesystem paths and target-observed metadata. Use
  `$target_index_file`.
- A **local index** is the locally accounted baseline. Its entries use local
  document paths and locally observed metadata. Use `$local_index_file`.
- A **fresh local index** is the current local-document-root scan created while
  planning a push. Use `$fresh_local_index_file`.
- An **index entry** records one path, type, size, and ctime. Use
  `$index_entry`.
- The **index-update WAL** records completed pull mutations awaiting application
  to the target and local indexes.
- A **pull plan** lists target filesystem paths still scheduled for download or
  deletion. A **push plan** pairs local document paths with target document
  paths.

A **resolved path mapping** is an immutable mapping between target filesystem
prefixes and local filesystem prefixes. A **pull mapping** converts a target
filesystem path to a local filesystem path. A **push mapping** converts a local
document path to a target document path. An **addressable mapping** maps every
relevant local document path to exactly one target document path below the
target document root; an **ambiguous mapping** has more than one target path,
and an **unaddressable mapping** has none. An **identity mapping** leaves local
and target document paths identical. `path-mapping.json` stores the resolved
path mapping for a target relationship.

A state directory belongs to one local document root and may contain several
target relationships. Each target relationship directory is keyed only by its
target fingerprint. Reusing a mapped relationship with another local document
root is rejected.

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
even when their target or local-tree pairs differ.

The production CLI acquires the Reprint process lock before it prepares pair
context, constructs `ImportClient`, or writes the command audit entry. It
passes the open lock to `ImportClient::run()` and releases it after the command.
A direct `ImportClient::run()` call acquires the lock when its caller supplies
none. `PushFilesSender::start()` and `PushFilesSender::resume()` receive that
open lock from the caller; sender `close()` does not release it. This local
lock is separate from the receiver's push-session and commit locks.

## Pull index-update WAL

Call the single pull-side write-ahead log the **index-update WAL**. It lives at
`<state-dir>/.import-index-updates.wal`; use `.import-index-updates.wal`,
`$index_update_wal_path`, and `$index_update_wal_handle`.
Applied batch records are cleared, but the empty WAL remains as a marker until
files-pull completes. A retained WAL is consumed only while resuming or
aborting the interrupted files-pull, including through a high-level pull
command; unrelated commands do not consume it.

## Local push state

The local machine keeps planning and active state outside the receiver push
directory. Under `<state-dir>/push/<pair-key>/`, use these names verbatim:

| Surface | Name |
| --- | --- |
| Active plan directory | `plan`, `$plan_directory` |
| Plan-owned fresh local index | `fresh_local_index.jsonl`, `$fresh_local_index` |
| Previous local index | `previous_local_index.jsonl`, `previous_local_index`, `$previous_local_index` |
| Byte offset in the previous local index | `byte_offset_in_previous_local_index`, `$byte_offset_in_previous_local_index` |
| Local paths to push | `local_paths_to_push.jsonl`, `$local_paths_to_push` |
| Local paths to delete | `local_paths_to_delete`, `$local_paths_to_delete` |
| Local push state directory | `push_state_directory`, `$push_state_directory` |
| PushPlan cursor | `push_plan_cursor`, `$push_plan_cursor`, `get_cursor()` |
| Sender-owned excluded paths | `excluded_paths.json`, `$excluded_paths_path` |
| Deleted-directory stack | `deleted_directories_stack.jsonl`, `$deleted_directories_stack` |
| Active state | `sender.json`, `$state_path` |
| Selected path-list cursor | `$local_paths_to_push_byte_offset` |
| Local path type, size, and ctime | `local_path_type_size_and_ctime`, `$local_path_type_size_and_ctime`, `stat_local_path()` |

`sender.json`, `excluded_paths.json`, and
`previous_local_index.jsonl` live directly under the local push state
directory. The sender creates `plan/` for one active plan. PushPlan copies the
sender-owned exclusions to `plan/excluded_paths.json` when it starts.
`fresh_local_index.jsonl`, `local_paths_to_push.jsonl`,
`local_paths_to_delete`, and `deleted_directories_stack.jsonl` live inside it.

The **previous local index** describes the local document root as the pair's last
completed push observed it. The sender saves it after a successful commit, and
`files-diff` reads it. PushPlan diffs its fresh local index against the copy
its caller supplies. `byte_offset_in_previous_local_index` is the position
from which its current lookahead entry is read again after resume.

The PushPlan cursor is stored in `sender.json`. It contains the plan
directory, local document root, previous local index, and current
planning position. During `indexing`, that position contains the
FileIndexProcessor cursor and the committed byte offset in
`fresh_local_index.jsonl`. During `diffing`, it contains the index offsets,
output offsets, and the active byte offset in
`deleted_directories_stack.jsonl`. The stack file is append-only; each entry
links to the preceding active directory. The exclusions have a maximum of 100
paths. `sender.json` phases are `creating`, `starting_plan`,
`planning`, `pushing_paths`, `pushing_deletes`, `committing`,
`saving_previous_local_index`, `completing`,
`removing`, and `discarding_plan`. It stores the push session ID, selected
path-list cursor, receiver part limit, and request-sizing state. The index diff
completes before local paths are sent. The index copy after a successful commit
has no separate copy cursor and is repeated after interruption. After the index
is saved or the target confirms removal, the sender clears the PushPlan
cursor, then removes the entire plan directory and its exclusions file. It
does not ask PushPlan to manage terminal cleanup; PushPlan only closes its open
handles.

A request failure ends the current sender run. The active state remains in
place so a later push command can resume from the last durable boundary. Only
an explicit `request_too_large` failure lowers future request sizes.

When a local path to push changes, the sender reports `local_path_changed` and
moves to `removing`. After removal a new sender builds a fresh local index. The
sender trusts the completed deletion plan without checking the live local document root;
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

The local-only command is `files-diff`. Its `target URL`, `local document root`, `pair
key`, and `local push state directory` have the same meanings and pair-key
formula as `files-push`. It reads the pair's `previous_local_index.jsonl`,
which a completed files-push publishes, and never changes it.

Each JSONL change record has `command: "files-diff"`, an `action` of `push` or
`delete`, and `path_b64`. A push record also has the local path `type`, `size`,
and `ctime`; its type is `file`, `dir`, or `link`. These records form a local
minimized push operation plan before target exclusions: descendants represent
a new non-empty directory, one deleted subtree root covers its descendants,
and metadata-only changes to non-empty directories select no operation. The
final record has `status: "complete"`, `local_paths_to_push`, and
`local_paths_to_delete`.

files-diff persists nothing between runs. It runs one complete PushPlan in
`files-diff-plan/` while its command holds the state-directory-wide Reprint
process lock, streams both finished path lists from the beginning, and removes
the plan directory before exiting. An interrupted report is not resumed;
running the command again prints the complete report.

## Files-push CLI names

The low-level, files-only command is `files-push`. Its `target URL` is the
exporter API URL, and its `local document root` is the resolved absolute directory supplied by
`--fs-root`. It requires `--secret=TOKEN`; `--force-http` is the explicit
plain-HTTP opt-in.

The `pair key` identifies exactly one target URL and resolved local document root:

```text
sha256(rtrim(<target-url>, "?&") + "\0" + <resolved-local-document-root>)
```

The `local push state directory` is `<state-dir>/push/<pair-key>/`. `files-push`
chooses `start` or `resume` only from whether `sender.json` exists there. The
receiver-confirmed upload positions remain receiver-owned; they are not a
files-push cursor and are not copied into `.import-state.json` or
`.import-status.json`.

Files-push lifecycle lines use these command-first names verbatim: `START
files-push`, `RESUME files-push`, `PHASE files-push`, `PARTIAL files-push`,
`INTERRUPTED files-push`, `COMPLETE files-push`, `RESTART files-push`, `FAILED
files-push`, and `ERROR files-push`. Every line contains `pair=<pair-key>`.
Planned stop causes are `time_limit` and `memory_limit`.

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
| Whether the local delete list is complete | `$local_delete_list_complete` |
| Copy through a swap file | `copy_through_swap_file()`, `$source_path`, `$target_path` |
| Open directory handle | `$directory_handle` |
| Open local paths-to-push handle | `$local_paths_to_push_handle` |
| Open local paths-to-delete handle | `$local_paths_to_delete_handle` |
| Open local file handle | `$local_file_handle` |
| Local path stat result | `$path_stat` |
| File type bits | `$file_type_bits` |
| Delete active state | `delete_state()` |

## PushPlan names

Use these names verbatim inside `PushPlan`:

| Meaning | Name |
| --- | --- |
| Active plan directory | `$plan_directory` |
| Local tree root | `$local_document_root`, `set_local_document_root()` |
| Previous local index | `$previous_local_index` |
| Open previous local index | `$previous_local_index_handle` |
| Previous local index lookahead entry | `$previous_local_index_lookahead_entry`, `$previous_local_index_lookahead_entry_loaded` |
| Byte offset in the previous local index | `$byte_offset_in_previous_local_index` |
| Cursor | `$cursor`, `get_cursor()` |
| Plan-owned excluded paths | `$excluded_paths_file` |
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
