# Reprint terminology

This is the vocabulary contract for every Reprint surface. Read it before
changing pull or push code, tests, documentation, plans, review replies,
commit messages, or pull-request descriptions.

## Coordinate systems and relationship state

Every path name states the machine whose coordinates it uses and whether it
is absolute or relative to a named root.

- A **state directory** is the caller-selected local directory containing
  Reprint state for one filesystem root. Use `$state_dir`.
- The **filesystem root** is the local directory under which a pulled remote
  filesystem is reconstructed and whose contents are compared, pulled, and
  pushed. Use `$filesystem_root`.
- The **remote Reprint API URL** is the configured URL used for Reprint
  requests. Use `$remote_reprint_api_url`.
The domain path terms are:

| Term | Meaning | Preferred name |
| --- | --- | --- |
| Remote absolute path | Absolute path on the remote machine. | `$remote_absolute_path` |
| Local absolute path | Absolute path on the local machine. | `$local_absolute_path` |
| Local relative path | Path relative to the filesystem root. | `$local_relative_path` |
| Document-root-relative path | Path relative to the document root. | `$document_root_relative_path` |

An **absolute path** begins at `/`. A **relative path** has no leading slash.
A **normalized path** has repeated separators and `.` or `..` segments removed
lexically; it does not inspect the filesystem. A **resolved absolute path** is
an absolute path whose existing symlinks `realpath()` resolved. An
**uncreated suffix** is the final sequence of segments that does not yet
exist. A **base64 path** describes transport encoding, not coordinates.

Use `resolve_absolute_path_with_uncreated_suffix()` for a path resolver that
retains a missing suffix. Do not call this a canonical path. Do not use a bare
`$path`, `$local_path`, or `$remote_path` when more than one coordinate system
is present.

The pull conversion flow is remote absolute path → local absolute path →
local relative path. Push applies the reverse mapping from a
local relative path to a document-root-relative path.

## Indexes and mappings

- A **remote index** records remote absolute paths and remote-observed type,
  size, and ctime for pull operations already accounted for in the filesystem
  root. Use `$remote_index_file`.
- A **next remote index** is the snapshot of the remote paths selected for the
  current pull. Its entries use remote absolute paths and remote-observed
  metadata. Use `$next_remote_index_file`.
- A **local index** is the retained filesystem-root snapshot in one remote
  state directory. Its entries use local relative paths and locally observed
  type, size, ctime, and directory emptiness. The remote Reprint API URL selects
  the remote state directory; a different filesystem root uses a different
  state directory. Files-pull advances only the entries for completed local
  mutations; files-push atomically replaces the local index only after the
  target confirms commit. Use `$local_index_file`.
- A **fresh local index** is the current filesystem-root scan used for sync
  planning. Use `$fresh_local_index_file`.
- An **index entry** records one path, type, size, and ctime. Use
  `$index_entry`.
- A **file sync patch planner** compares a patch base index with a patch result
  index and selects `copy`, `delete`, or `replace` operations. To make two
  trees identical, the destination is the patch base and the source is the
  patch result. To copy only source changes, the source snapshots from the
  last sync and now are the patch base and result. The planner owns the index
  diff and the active deletion roots file.
- The **pull index WAL** records completed pull mutations awaiting application
  to the remote and local indexes.
- A **pull plan** lists remote absolute paths still scheduled for download or
  deletion. A **push plan** maps local relative paths to document-root-relative
  paths.

A **resolved path mapping** is an immutable mapping between remote absolute
prefixes and local absolute prefixes. A **pull mapping** converts a remote
absolute path to a local absolute path. A **push mapping** converts a
local relative path to a document-root-relative path. An **addressable mapping**
maps every relevant local relative path to exactly one document-root-relative path below the
document root; an **ambiguous mapping** has more than one document-root-relative path,
and an **unaddressable mapping** has none. An **identity mapping** leaves local
and document-root-relative paths identical. `path-mapping.json` stores the resolved
path mapping.

A state directory belongs to one filesystem root. Reusing its mapping state
with another filesystem root is rejected.

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
`create-remove.lock`, `commit-state`, `commit-state.lock`, and bounded removal
tombstones named `.removing-<push-session-id>/`.

`create-remove.lock` is the create/remove lock. Create and every bounded remove
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

## Local Reprint state layout

The **state directory** is the caller-supplied `<state-dir>`; use `$state_dir`.
Reprint uses it exactly as supplied and does not append `.reprint`. A consumer
may choose `.reprint` or any other private directory name. A **remote state
directory** contains state for one remote Reprint API URL. It is
`<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>`; use
`$remote_state_directory`. The **pull state directory** is
`<remote-state-directory>/pull`; use `$pull_state_directory`. Filenames inside
the state directory do not begin with a dot or repeat the scope supplied by
their parent directories.

```text
<state-dir>/
├── process.lock
├── progress.json
├── audit.log
└── remotes/
    └── <md5-of-trimmed-remote-reprint-api-url>/
        ├── local_index.jsonl
        ├── pull/
        │   ├── state.json
        │   ├── remote-index.jsonl
        │   ├── index.wal
        │   ├── remote-index.next.jsonl
        │   ├── fetch-list.jsonl
        │   ├── volatile-files.json
        │   ├── domains.json
        │   ├── sql-stats.json
        │   └── sql-buffer
        └── push/
```

Use these path names:

| Surface | Name |
| --- | --- |
| State directory | `$state_dir` |
| Pull state directory | `$pull_state_directory` |
| Remote state directory | `$remote_state_directory` |
| Local index file | `$local_index_file` |
| Pull state file | `$pull_state_file` |
| Remote index file | `$remote_index_file` |
| Pull index WAL | `$pull_index_wal_path` |
| Next remote index file | `$next_remote_index_file` |
| Fetch list file | `$fetch_list_file` |
| Volatile files file | `$volatile_files_file` |
| Domains file | `$domains_file` |
| SQL statistics file | `$sql_stats_file` |
| SQL buffer file | `$sql_buffer_file` |
| Audit log file | `$audit_log_file` |
| Progress file | `$progress_file` |
| Reprint process lock path | `$process_lock_path` |

Caller-facing outputs such as `db.sql`, `db-tables.jsonl`, generated runtime
files, and database files remain directly under the state directory.
Commands which read or write remote state receive the remote Reprint API URL
even when they make no network request. The URL selects the remote state
directory.

## Local Reprint process lock

Every local Reprint command workflow runs under the **Reprint process lock** at
`<state-dir>/process.lock`. Use `process.lock`,
`$process_lock_path`, and `$process_lock`. The lock is non-blocking and
state-directory-wide: pull, push, diff, and other local Reprint processes
cannot run concurrently against the same state directory, even when their
remote Reprint API URLs differ.

The production CLI acquires the Reprint process lock before it resolves the
local push state directory, constructs `ImportClient`, or writes the command
audit entry. It passes the open lock to `ImportClient::run()` and releases it
after the command. A direct `ImportClient::run()` call acquires the lock when
its caller supplies none. `PushFilesSender::start()` and
`PushFilesSender::resume()` receive that open lock from the caller; sender
`close()` does not release it. This local lock is separate from the receiver's
push-session and commit locks.

## Pull index WAL

Call the single pull-side write-ahead log the **pull index WAL**. It lives at
`<remote-state-directory>/pull/index.wal`; use
`pull/index.wal`, `$pull_index_wal_path`, and
`$pull_index_wal_handle`. Each record always carries the remote index
projection and also carries the local index projection when files-pull
completed a non-skipped local mutation beneath the filesystem root. Applying
a batch updates the remote index first, then the local index, and clears the
records only after both replacements finish.

The empty WAL remains until files-pull completes. A retained WAL
is consumed only while resuming or aborting the interrupted files-pull,
including through a high-level pull command. Files-diff and files-push reject
the unfinished files-pull instead of consuming its WAL.
Files-pull rejects an unfinished files-push while
`<remote-state-directory>/push/sender.json` exists.

## Local index and push state

The local index is `<remote-state-directory>/local_index.jsonl`.
`files-diff` and PushPlan read it. Files-pull advances it only for local paths
changed by completed file, directory, symlink, or deletion mutations.
PushFilesSender replaces it only after the target confirms commit. Planning
and active push state remain under `<remote-state-directory>/push/`.

Use these names verbatim:

| Surface | Name |
| --- | --- |
| Active plan directory | `plan`, `$plan_directory` |
| Plan-owned fresh local index file | `fresh_local_index.jsonl`, `$fresh_local_index_file` |
| Local index file | `local_index.jsonl`, `local_index_file`, `$local_index_file` |
| Local paths to push | `local_paths_to_push.jsonl`, `$local_paths_to_push` |
| Local paths to delete | `local_paths_to_delete`, `$local_paths_to_delete` |
| Local push state directory | `push_state_directory`, `$push_state_directory` |
| PushPlan cursor | `push_plan_cursor`, `$push_plan_cursor`, `get_cursor()` |
| Sender-owned excluded paths | `excluded_paths.json`, `$excluded_paths_path` |
| Active deletion roots | `deleted_directories_stack.jsonl`, `$active_deletion_roots_file` |
| Active state | `sender.json`, `$state_path` |
| Selected path-list cursor | `$local_paths_to_push_byte_offset` |
| Selected local-path count | `local_paths_to_push_count`, `$local_paths_to_push_count` |
| Target-confirmed local-path count | `local_paths_pushed`, `$local_paths_pushed` |
| Selected local file bytes | `local_file_bytes_to_push`, `$local_file_bytes_to_push` |
| Target-confirmed completed local file bytes | `local_file_bytes_pushed`, `$local_file_bytes_pushed` |
| Consumed index bytes in progress | `index_bytes_done`, `$index_bytes_done` |
| Combined index bytes in progress | `index_bytes_total`, `$index_bytes_total` |
| Target-confirmed file bytes in progress | `file_bytes_done`, `$file_bytes_done` |
| Selected file bytes in progress | `file_bytes_total`, `$file_bytes_total` |
| Target-confirmed deletion-list bytes in progress | `deleted_paths_bytes_done`, `$deleted_paths_bytes_done` |
| Deletion-list bytes in progress | `deleted_paths_bytes_total`, `$deleted_paths_bytes_total` |
| Local path type, size, and ctime | `local_path_type_size_and_ctime`, `$local_path_type_size_and_ctime`, `stat_local_path()` |

`sender.json` and `excluded_paths.json` live directly under the local push
state directory. The sender creates `plan/` for one active plan. PushPlan
copies the sender-owned exclusions to `plan/excluded_paths.json` when it starts.
`fresh_local_index.jsonl`, `local_paths_to_push.jsonl`,
`local_paths_to_delete`, and `deleted_directories_stack.jsonl` live inside it.

The local index contains the fresh filesystem-root scan the sender saved after
a target-confirmed files-push commit, advanced path by path by later completed
files-pull mutations.
Files-pull does not scan unrelated paths or accept their pending local changes.
PushPlan diffs its fresh local index against the local index its caller
supplies. Its FileSyncPatchPlanner owns the FileIndexDiffProcessor and the
active deletion roots file. That file remembers directory deletions which
cover index paths the planner has not processed yet.

The PushPlan cursor is stored in `sender.json`. It contains `plan_directory`,
`local_index_file`, and the current
planning position. During `indexing`, that position contains the complete
FreshLocalIndexProcessor cursor. During `diffing`, it contains the output
offsets and the complete FileSyncPatchPlanner cursor. PushPlan stores either
nested cursor without unpacking or rebuilding it. The active
deletion roots file is append-only; each entry links to the preceding active
directory. The exclusions have a maximum of 100 paths. The `sender.json`
phases are `creating`, `finishing_previous_commit`,
`starting_plan`, `planning`, `pushing_paths`, `pushing_deletes`, `committing`,
`saving_local_index`, `completing`, `removing`, and `discarding_plan`. It stores
both the current and blocking push session IDs, selected path-list cursor,
selected local-path count and file byte total, target-confirmed local-path
count and completed file bytes, receiver part limit, and request-sizing state.
The index diff cursor retains the selected local-path count and file byte total
as it builds the path list, and its complete cursor retains both final totals.
The index diff completes before local paths are sent. The index copy after a
target-confirmed commit has no separate copy cursor and is repeated after
interruption. After the index is saved or the remote confirms removal, the
sender clears the PushPlan cursor, then removes the entire plan directory and
its exclusions file. It does not ask PushPlan to manage terminal cleanup;
PushPlan only closes its open handles.

A request failure ends the current sender run. The active state remains in
place so a later push command can resume from the last durable boundary. Only
an explicit `request_too_large` failure lowers future request sizes.

When a local path to push changes, the sender reports `local_path_changed` and
moves to `removing`. After removal a new sender builds a fresh local index. The
sender trusts the completed deletion plan without checking the live filesystem root;
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

## CLI progress output names

The **progress output mode** is the invocation-only `--progress` value accepted
by every command executed by `ImportClient`: `auto`, `tty`, or `jsonl`. `auto`
selects the terminal presentation when the current progress stream is a TTY and
the JSONL presentation otherwise. `tty` and `jsonl` force those presentations.
Never store the progress output mode in command state. Explicit `tty` and
`jsonl` modes do not combine with `--verbose`. The progress output mode governs
progress, lifecycle, and status output; it does not reformat a command's data
result.

## Files-diff CLI names

The local-only command is `files-diff`. Its `remote Reprint API URL` and
`filesystem root` have the same meanings as for `files-push`. It reads
`<remote-state-directory>/local_index.jsonl`, which files-pull advances after
completed local mutations and files-push writes after the target confirms
commit. Files-diff never changes it.

`--progress` accepts `auto`, `tty`, or `jsonl`. `auto` is the default: it
selects `tty` when stdout is a TTY and `jsonl` otherwise. `tty` uses
`modified: PATH` for each local path to push and `deleted: PATH` for each local
path to delete, both in Git's red status color. Paths containing unsafe bytes
use C-style quoting so one path always occupies one output line. `jsonl`
selects uncolored machine-readable output. Each JSONL change record has
`command: "files-diff"`, an `action` of `push` or `delete`, and `path_b64`. A
push record also has the local path `type`, `size`, and `ctime`; its type is
`file`, `dir`, or `link`. These records form a local minimized push operation
plan before remote exclusions: descendants represent a new non-empty
directory, one deleted subtree root covers its descendants, and metadata-only
changes to non-empty directories select no operation. The final JSONL record
has `status: "complete"`, `local_paths_to_push`, and
`local_paths_to_delete`.

files-diff persists nothing between runs. It runs one complete PushPlan in
`files-diff-plan/` while its command holds the state-directory-wide Reprint
process lock, streams both finished path lists from the beginning, and removes
the plan directory before exiting. An interrupted report is not resumed;
running the command again prints the complete report.

## Files-push CLI names

The low-level, files-only command is `files-push`. Its `remote Reprint API URL` is the
exporter API URL, and its `filesystem root` is the resolved absolute directory supplied by
`--fs-root`. It requires saved preflight data and treats its remote document
root as a path beneath that filesystem root. Local relative paths beneath the document root become document-root-relative paths; other local paths do not
become push or delete work. It also requires `--secret=TOKEN`; `--force-http`
is the explicit plain-HTTP opt-in.

`files-push` uses the shared progress output mode. It never stores that mode in
sender state.

The **local push state directory** is
`<state-dir>/remotes/<md5-of-trimmed-remote-reprint-api-url>/push`. The hash
directory name is:

```text
md5(rtrim(<remote-reprint-api-url>, "?&"))
```

The state directory belongs to one filesystem root. Use a different state
directory for a different filesystem root; the filesystem root does not
participate in the directory name. `files-push` chooses `start` or `resume`
only from whether `sender.json` exists there. The receiver-confirmed upload
positions remain receiver-owned; they are not a files-push cursor and are not
copied into `<remote-state-directory>/pull/state.json` or the state-directory-wide
`progress.json`.

The terminal presentation uses one stage-weighted progress bar. The percentage
precedes a label which changes only between `Preparing`, `Indexing`,
`Pushing`, `Pushing deletions`, `Committing`, `Saving index`, and `Finishing`.
Durable index byte offsets and target-confirmed upload boundaries advance the
bounded parts of the bar; indexing and commit advance at phase milestones
because neither has a bounded total. During `Pushing`, the line shows
target-confirmed file bytes against the selected local file byte total when
that total is nonzero. The percentage describes lifecycle progress, not time
remaining. The overall bar is terminal-only.

`PushPlan::get_progress()` reports its internal phase and durable index byte
counts. `PushFilesSender::get_progress()` folds those counts into one sender
snapshot alongside target-confirmed path, file-byte, and deletion-list-byte
counts. The CLI maps that snapshot onto labels and stage weights. PushPlan and
PushFilesSender do not calculate terminal percentages or choose output format.

The JSONL presentation emits `push_progress` records. `files_done` and
`files_total` appear together after planning in those records, the final
result, and `progress.json`; they are absent while the plan is still being
built. `files_total` is the selected local-path count.
`files_done` is the target-confirmed local-path count, so an open, failed, or
canceled request does not advance it. Both counts survive resume.

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
| Blocking push session ID | `blocking_push_session_id`, `$blocking_push_session_id` |
| Finish previous commit | `finishing_previous_commit`, `finish_previous_commit()` |
| Remove push session | `remove_push_session()` |
| Upload next file chunk | `upload_next_file_chunk()` |
| Upload next chunk of deleted paths | `upload_next_chunk_of_deleted_paths()` |
| Request result | `$request_result` |
| Plan result | `$plan_result` |
| Sender status | `$status`, `get_status()` |
| Sender phase | `get_phase()` |
| Sender progress | `get_progress()` |
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
| Local paths-to-delete byte total | `$local_paths_to_delete_bytes` |
| Open local file handle | `$local_file_handle` |
| Local path stat result | `$path_stat` |
| File type bits | `$file_type_bits` |
| Delete active state | `delete_state()` |

## PushPlan names

Use these names verbatim inside `PushPlan`:

| Meaning | Name |
| --- | --- |
| Active plan directory | `$plan_directory` |
| Filesystem root | `$filesystem_root`, `set_filesystem_root()` |
| Local index file | `$local_index_file` |
| Open local index file | `$local_index_file_handle` |
| Local index lookahead entry | `$local_index_lookahead_entry`, `$local_index_lookahead_entry_loaded` |
| Read the next index entry | `read_next_index_entry()`, `$index_file_handle` |
| Index entry and shape | `$index_entry`, `$local_index_entry`, `$local_index_entry_shape`, `index_entry_shape()` |
| Cursor | `$cursor`, `get_cursor()` |
| Plan-owned excluded paths | `$excluded_paths_file` |
| Fresh local index processor | `FreshLocalIndexProcessor`, `$fresh_local_index_processor` |
| Fresh local indexing cursor | `fresh_local_index_cursor`, `$fresh_local_index_cursor` |
| Fresh local index byte offset | `$fresh_local_index_byte_offset` |
| Open fresh local index | `$fresh_local_index_handle` |
| Combined index bytes | `$index_bytes_total` |
| File-sync planner cursor | `file_sync_planner_cursor`, `$file_sync_planner_cursor` |
| Plan progress | `get_progress()` |
| Start index diff | `start_index_diff()` |
| Seek an index file | `seek_index_file_to_byte_offset()`, `$index_file_handle` |
| Open push-plan output file | `open_push_plan_output_file_at_byte_offset()`, `$push_plan_output_file_handle` |

## Protocol names

The work-upload endpoint is `push_upload` and its push-session parameter is
`push_session_id`. Endpoint names and endpoint prefixes begin with `push_`.
JSON responses use `push_session_id`, `blocking_push_session_id`,
`receiving_work`, and `work_deletes_bytes`. Push-session failures are
`lock_acquisition_failure`, `offset_gap`, `push_not_found`, `filesystem_error`,
`commit_required`, `unexpected_docroot_mutation`, `corrupted_push_state`, and
`same_device`. Authentication, authorization, and request-boundary failures
are `auth_failed`, `push_disabled`, `not_configured`, `invalid_request`, and
`request_too_large`.

The document-root `.maintenance` file identifies its owner with the push
session ID. `commit.json` stores no separate maintenance value.
