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
  is the one value currently being received or published.
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
File records use `preparing`, `receiving`, or `publishing` and also contain
`total_bytes`. Directory and symlink records use `preparing` or `publishing`;
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

## Local push state

The local machine keeps planning and active state outside the target push
directory. Under `<state-dir>/push/<site>/`, use these names verbatim:

| Surface | Name |
| --- | --- |
| Plan-owned fresh local index | `fresh_local_index.jsonl`, `$fresh_local_index` |
| Local index at the previous push | `local_index_at_previous_push.jsonl`, `$local_index_at_previous_push` |
| Local paths to push | `local_paths_to_push.jsonl`, `$local_paths_to_push` |
| Local paths to delete | `local_paths_to_delete`, `$local_paths_to_delete` |
| Local push state directory | `push_state_directory`, `$push_state_directory` |
| PushPlan cursor | `cursor.json`, `$cursor_file` |
| Active state | `sender.json`, `$state_path` |
| Lifecycle lock file | `sender.lock`, `$lock_path` |
| Open lifecycle lock | `$lock_handle` |
| Selected path-list cursor | `$local_paths_to_push_byte_offset` |
| Local path type, size, and ctime | `local_path_type_size_and_ctime`, `$local_path_type_size_and_ctime`, `stat_local_path()` |

`cursor.json` owns planning offsets, output offsets and counts, active deleted
directory ranges, and `excluded_paths_b64`. `sender.json` does not repeat them.
Its phases are `creating`, `planning`, `pushing_paths`, `pushing_deletes`,
`committing`, and `removing`. It records the push session ID, selected path-list
cursor, local path type, size, and ctime for a partial file, target part limit,
and request-sizing state.

A request failure ends the current sender run. The active state remains in
place so a later push command can resume from the last durable boundary.

When a local path to push disappears or changes while being read, the sender
reports `local_path_changed` and moves to `removing`. After removal it requests
a new fresh local index.

Receiver-confirmed file and work-delete cursors remain receiver state. The
sender reads them from `push_status`; it does not copy them into `sender.json`.
`push.json` remains the receiver-owned push identity and policy, while
`commit.json` and `$commit_state` remain the receiver commit checkpoint.

`PushFilesSender::start()` and `PushFilesSender::resume()` acquire
`sender.lock`; `PushFilesSender::close()` releases it. `next_step()` does not
acquire or release the lock and does not reread `sender.json`. Every
`continue` result follows a durable sender boundary, so a later process may
resume after the current process closes the sender. In `pushing_paths` or
`pushing_deletes`, one step sends at most one multipart part.

## PushFilesSender names

Use these names verbatim inside `PushFilesSender`:

| Meaning | Name |
| --- | --- |
| Local path to push | `LocalPathToPush`, `$local_path_to_push`, `read_local_path_to_push()` |
| Push stream client | `$push_stream_client`, `create_push_stream_client()` |
| Push stream client options | `$push_stream_client_options` |
| Request sizer options | `request_sizer_options`, `$request_sizer_options` |
| Push request | `send_push_request()` |
| Create push session | `create_push_session()` |
| Upload next file chunk | `upload_next_file_chunk()` |
| Upload next chunk of deleted paths | `upload_next_chunk_of_deleted_paths()` |
| Request result | `$request_result` |
| Failure result | `$failure_result` |
| Plan result | `$plan_result` |
| Receiver path status | `$receiver_path_status` |
| Receiver path type | `$receiver_path_type` |
| Receiver-confirmed bytes | `$receiver_confirmed_bytes` |
| Whether this upload completes the local path | `$upload_completes_local_path` |
| Maximum file payload bytes | `$maximum_file_payload_bytes` |
| Maximum delete-list payload bytes | `$maximum_delete_list_payload_bytes` |
| Local I/O failure detail | `$local_io_failure_detail` |
| Whether the local delete list is complete | `$local_delete_list_complete` |
| Open directory handle | `$directory_handle` |
| Open local paths-to-push handle | `$local_paths_to_push_handle` |
| Open local paths-to-delete handle | `$local_paths_to_delete_handle` |
| Open local file handle | `$local_file_handle` |
| Local path stat result | `$path_stat` |
| File type bits | `$file_type_bits` |
| Delete active state | `delete_state()` |

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
