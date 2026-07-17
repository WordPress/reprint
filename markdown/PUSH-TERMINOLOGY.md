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

## Local sender journal

The sender's durable files are local machine state, not part of the push
directory. Under `<state-dir>/push/<site>/`, use these names verbatim:

| Surface | Name |
| --- | --- |
| Active sender checkpoint | `sender.json`, `$sender_state_path` |
| Enriched index selected for the active push | `sender-index.jsonl`, `$sender_index_path` |
| Positive-work path list | `local-paths-to-push.jsonl`, `$local_paths_to_push` |
| Raw local work-delete stream | `work-deletes`, `$work_deletes_path` |
| Local sender transition lock | `sender.lock` |
| Local planning checkpoint key | `planning_checkpoint` |
| Selected path-list cursor | `$paths_byte_offset` |
| Selected positive-work path | `$current_path_b64` |
| Receiver-confirmed file cursor | `$confirmed_bytes` |

`sender.json` is unversioned development state. Its phases are `creating`,
`planning`, `reconciling_work`, `uploading_work`, `reconciling_deletes`,
`uploading_deletes`, `committing`, and `removing`. It also records the push
session ID, local planning input offsets and committed output lengths, selected
path, last source token confirmed by an upload response, receiver-confirmed
file and work-delete cursors, recoverable-failure count, target part limit,
receiver exclusion policy, and request-sizing state.
`planning_checkpoint` is local sender resume metadata. It is unrelated to the
receiver commit checkpoint in `commit.json`, named `$commit_state` in PHP.
`push.json` remains the receiver-owned
push identity and policy; it does not store local source evidence or transport
progress.

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
