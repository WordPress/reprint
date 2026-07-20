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
- The **WordPress route** is the normal exporter API route reached during
  plugin loading. It serves pull operations only.
- The **push route** creates a push session, receives work, reports status,
  commits work, and removes work without booting WordPress.
- The **connection secret** authenticates `push_create` at the push route.
- The target-issued **push session secret** authenticates every later request
  for one push session. It cannot create another push session.

All five push operations use the push route. The WordPress route rejects the
push namespace instead of serving any push operation as a fallback.

## PHP names

Use these names verbatim:

| Surface | Name |
| --- | --- |
| Exception class and file | `Site_Export_Push_Exception`, `class-push-exception.php` |
| Session class and file | `Site_Export_Push_Session`, `class-push-session.php` |
| Push route entry point | `Site_Export_HTTP_Server::serve_push()` |
| Load push connection secret | `Site_Export_HTTP_Server::load_push_connection_secret()` |
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

## Durable layout and metadata

Every push directory is located at:

```text
<reprint-directory>/.reprint/push/<push-session-id>/
```

It contains `push-metadata.php`, `push.lock`, optional `commit.json`, and
`work/`. The work directory contains `files/`, optional `inflight.json` and
`inflight.data`, `deletes`, and an optional private maintenance copy. `files/`
is the only path-shaped work tree. The shared `.reprint/push/` directory contains
`push-create.lock`, `commit-state`, `commit-state.lock`, and bounded removal
tombstones named `.removing-<push-session-id>/`.

The bundled push route reads
`<reprint-directory>/.reprint/push-config.php`. This mode-0600 PHP file returns
an array whose `connection_secret` value permits new push-session creation.
Removing it blocks `push_create` without taking the per-session secrets away
from push sessions which already exist.

`push-create.lock` is the create/remove lock. Create and every bounded remove
call acquire it non-blockingly. Remove holds it while inspecting and renaming
the live push directory and while performing one tombstone cleanup step, so
create cannot recreate the same push session until that cleanup finishes.
The tombstone keeps `push-metadata.php` and `push.lock` until its other entries
are gone, so every bounded remove request can authenticate and lock the same
push session.

`inflight.json` records the path and type of the one in-flight work value.
File records use `preparing`, `receiving`, or `completing` and also contain
`total_bytes`. Directory and symlink records use `preparing` or `completing`;
symlink records also contain `target_b64`. `inflight.data` contains in-flight
file bytes, and its actual size is the receiver-confirmed cursor. Both paths
are absent when no work is in flight.

`push-metadata.php` returns an array with the keys
`push_session_id`, `docroot_b64`, `excluded_paths_b64`,
`push_session_secret`, `maximum_part_bytes`, `maximum_commit_entries`, and
`work_deletes_complete`.

`commit.json` has the phases `deleting_files`,
`installing_files`, and `complete`. Its cursor keys are
`work_deletes_byte_offset`, `current_delete_path`,
`current_work_files_descendant`, and `commit_cursor`. Path values remain
base64 text even where a key does not include a suffix. Its non-recoverable
failure key is `non_recoverable_commit_failure`.

These metadata records are unversioned while their schemas are still under
development. There are no compatibility aliases or migration paths.

## Local push state

The local machine keeps planning and active state outside the receiver push
directory. Under `<state-dir>/push/<pair-key>/`, use these names verbatim:

| Surface | Name |
| --- | --- |
| Active plan directory | `plan`, `$plan_directory` |
| Plan-owned fresh local index | `fresh_local_index.jsonl`, `$fresh_local_index` |
| Local index at the previous push | `local_index_at_previous_push.jsonl`, `$local_index_at_previous_push` |
| Local paths to push | `local_paths_to_push.jsonl`, `$local_paths_to_push` |
| Local paths to delete | `local_paths_to_delete`, `$local_paths_to_delete` |
| Local push state directory | `push_state_directory`, `$push_state_directory` |
| PushPlan cursor | `push_plan_cursor`, `$push_plan_cursor`, `get_cursor()` |
| Sender-owned excluded paths | `excluded_paths.json`, `$excluded_paths_path` |
| Deleted-directory stack | `deleted_directories_stack.jsonl`, `$deleted_directories_stack` |
| Active state | `sender.json`, `$state_path` |
| Lifecycle lock file | `sender.lock`, `$lock_path` |
| Open lifecycle lock | `$lock_handle` |
| Selected path-list cursor | `$local_paths_to_push_byte_offset` |
| Local path type, size, and ctime | `local_path_type_size_and_ctime`, `$local_path_type_size_and_ctime`, `stat_local_path()` |

`sender.json`, `sender.lock`, `excluded_paths.json`, and
`local_index_at_previous_push.jsonl` live directly under the local push state
directory. The sender creates `plan/` for one active plan. PushPlan copies the
sender-owned exclusions to `plan/excluded_paths.json` when it starts.
`fresh_local_index.jsonl`, `local_paths_to_push.jsonl`,
`local_paths_to_delete`, and `deleted_directories_stack.jsonl` live inside it.

The PushPlan cursor is stored in `sender.json`. It contains the plan
directory, local tree root, local index at the previous push, and current
planning position. During `indexing`, that position contains the
FileIndexProcessor cursor and the committed byte offset in
`fresh_local_index.jsonl`. During `diffing`, it contains the index offsets,
output offsets, and the active byte offset in
`deleted_directories_stack.jsonl`. The stack file is append-only; each entry
links to the preceding active directory. The exclusions have a maximum of 100
paths. `sender.json` phases are `creating`, `starting_plan`, `planning`,
`pushing_paths`, `pushing_deletes`, `committing`,
`saving_local_index_at_previous_push`, `completing`,
`removing`, and `discarding_plan`. It stores the push session ID, selected
path-list cursor, receiver part limit, push session secret, and request-sizing
state. `sender.json` uses mode 0600 because it contains that session-scoped
secret. The index diff
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
sender trusts the completed deletion plan without checking the live local tree;
changes after planning belong to the next push.

Receiver-confirmed file and work-delete cursors remain receiver state. A newly
opened sender reads them from `push_status`, while a successful upload retains
them in memory for later steps in the same lifecycle. It does not copy them into
`sender.json`.
`push-metadata.php` remains the receiver-owned push identity and policy, while
`commit.json` and `$commit_state` remain the receiver commit checkpoint.

`PushFilesSender::start()` and `PushFilesSender::resume()` acquire
`sender.lock`; `PushFilesSender::close()` releases it. `next_step()` does not
acquire or release the lock and does not reread `sender.json`. A sender retains
one multipart request across steps. A caller stopping between steps calls
`cancel()` to discard that request and return to the preceding durable
boundary, then calls `close()` to release resources and the lock. `close()`
never finishes an open request. In `pushing_paths` or
`pushing_deletes`, one step sends at most one multipart part. `next_step()`
returns true while another step may be performed and false when `get_status()`
reports `complete`, `restart`, or `failed`.

## Files-push CLI names

The low-level, files-only command is `files-push`. Its `target URL` is the push
URL, and its `local tree` is the canonical directory supplied by `--fs-root`.
It requires `--secret=TOKEN`; `--force-http` is the explicit plain-HTTP opt-in.

The `pair key` identifies exactly one target URL and canonical local tree:

```text
sha256(rtrim(<target-url>, "?&") + "\0" + <canonical-local-tree-path>)
```

The `pair state directory` is `<state-dir>/push/<pair-key>/`. `files-push`
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
| Connection-authenticated request | `send_connection_request()` |
| Push-session-authenticated request | `send_push_session_request()` |
| Configure push session authentication | `set_push_session_hmac_client()` |
| Open upload request stage | `$upload_request_stage`: `closed`, `sending_parts`, or `finishing` |
| Whether the open request has sent parts | `MultipartPushStreamClient::has_sent_parts()` |
| Create push session | `create_push_session()` |
| Commit push | `commit_push()` |
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
| Local tree root | `$local_tree_root`, `set_local_tree_root()` |
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
`work_deletes_bytes`. `push_create` uses the connection secret and returns
`push_session_secret`. `push_upload`, `push_status`, `push_commit`, and
`push_remove` use that push session secret at the same push route. Every commit
request is idempotent, including the request which creates `commit.json`.
Push-session failures are
`lock_acquisition_failure`, `offset_gap`, `push_not_found`, `filesystem_error`,
`commit_required`, `unexpected_docroot_mutation`, `corrupted_push_state`, and
`same_device`. Authentication, authorization, and request-boundary failures
are `auth_failed`, `push_disabled`, `not_configured`, `invalid_request`, and
`request_too_large`.

The document-root `.maintenance` file identifies its owner with the push
session ID. `commit.json` stores no separate maintenance value.
