# Progress, results, and exit codes

Use the final command report and exit code to determine whether a copy completed.

[PHP integration example](build-with-reprint.md)

Examples use `$URL` for the source address, `$STATE_DIR` for persistent private
state, and `$FS_ROOT` for downloaded files. Set those variables in your shell.

## Exit codes

| Code | Meaning | Caller action |
| --- | --- | --- |
| `0` | Command returned successfully. | Check the result status; `aborted` is not a completed transfer. |
| `1` | Command failed. | Read the error and resolve its cause. |
| `2` | Work is unfinished. | Run the same command with the same state. |
| `3` | Temporary transfer failure stopped this run. | Decide whether and when to run it again. |
| `4` | Client authorization is needed. | Enroll the Migration Token, then run again. |

Check command-specific help too. Some commands, including files-push, have
additional result statuses and do not retry inside the run. The WordPress.org
server package does not provide push.

## Final command reports

JSONL and compact CLI output end with a final command result, so callers do not
need to infer the outcome from progress messages. No separate flag is needed:

```sh
php reprint.phar pull "$URL" --state-dir="$STATE_DIR" \
    --fs-root="$FS_ROOT" --progress=compact
```

The CLI appends one JSON line after a command returns or throws a handled
exception in `--progress=jsonl` or `--progress=compact` mode. The default `auto`
mode also appends it when the progress stream is not a terminal. `--progress=tty`
and `auto` on a terminal do not append a report.

Existing progress and command data stay in place. Stage updates say which
command and stage are running; the final report says how the invocation ended.
It does not repeat individual preflight checks. Captured `preflight` output
contains its data record followed by the final report. Downloading runtime
files can also emit progress before those records. Callers must read the
records separately, not decode all of stdout as one JSON document or assume a
fixed line count. The same applies to other commands that print JSON data,
such as `pull-metadata` and `files-stats`; their data keeps its existing formatting.

The report goes to stdout, except when stdout carries SQL; then it goes to
stderr with the other progress records. `progress.json` remains the source for
live progress. A caller does not need its last update to read the final error.

```json
{
  "type": "reprint_report",
  "schema_version": 1,
  "command": "pull-files",
  "status": "error",
  "exit_code": 1,
  "failed_stage": "preflight",
  "error": "The remote server returned HTTP 401: Invalid signature",
  "error_code": "AUTH_FAILED"
}
```

`--progress=compact` prints short progress followed by the final report. It does
not create an additional progress log; `progress.json` snapshots and `audit.log`
are unchanged. With SQL on stdout, `auto` checks stderr to choose between JSONL
and terminal progress.

`command` is the outer command the caller invoked. Preflight inside `pull`,
`pull-files`, or `pull-db` does not produce a separate final report.

`status` is `complete`, `partial`, `error`, or `aborted`. A first `pull` can
report `enrollment_needed` with exit code `4`; display its `message` to ask the
administrator to authorize the client. Files-push also retains
its `interrupted`, `restart`, and `failed` outcomes and its `reason` and `detail`.
`exit_code` is the actual exit code; reporting does not choose retry timing or
change command outcomes. Exit 2 is unfinished work, not success. A successful
`--abort` reports `aborted`, not `complete`.

`failed_stage`, `error`, and `error_code` are null when unavailable. Failed
commands which have no specific error code still provide their error message.
HTTP and cURL details, including `http_code`, `curl_errno`, and
`consecutive_failures_without_progress`, are included when the command reports
them. Callers must not depend on every failure having those fields.

`preflight-assert` keeps its existing `checks` in its command result, with
`label`, `pass`, and `detail` for each check. These are not copied into the final
report, and no per-check codes are added.

Source progress in JSONL uses `type: "remote_progress"`. Its `phase` names the
client phase reading the response. Optional `remote_message` and
`remote_counters` describe the source scan; `file_bytes_total` and
`file_bytes_read` are non-negative integer counts for its current file. These
records do not change client screen counters, stages, or final report fields.
Compact output omits them.

## Reading a ticket

Split the captured output into lines. Decode each whole line as JSON, ignore
non-JSON lines, and select records with `type: "reprint_report"` and a supported
`schema_version`. Do not match JSON prefixes or assume field order.

Each report describes one invocation. A ticket containing separately invoked
preflight and download commands can contain several reports. The adapter must
associate those reports with its steps and produce the overall migration
result. Selecting the last success without checking which command ran can
mistake a preflight success for a completed migration.

There is no report if PHP cannot load the program, argument parsing exits
before command setup, the process is killed, or its output pipe breaks. An
OOM or an uncatchable signal cannot reliably print a final line. Missing or
truncated reports mean the final result is unavailable; use the host job's
status, never an earlier success or the last progress tick.

The CLI adds the final report. PHP library callers still receive the command's
regular output and can read its exit code or catch its exception.

## Poll current progress

When an external process (e.g. a web UI) needs to poll migration progress, it can read
`$STATE_DIR/progress.json`.

Pass `--step=N` and `--steps=N` to client commands to include the pipeline position in
the progress file. For example, a four-step pipeline would pass `--step=1 --steps=4` for the
preflight, `--step=2 --steps=4` for db-index, and so on.

The file uses one versioned shape for every command. Fields that do not apply
to the current phase are `null` instead of disappearing:

```json
{
  "schema_version": 1,
  "step": 2,
  "steps": 4,
  "command": "files-pull",
  "status": "in_progress",
  "phase": "fetch",
  "message": "Downloading files",
  "progress": {
    "items": {
      "unit": "files",
      "done": 41,
      "total": 83
    },
    "bytes": {
      "done": 7340032,
      "total": 52428800
    },
    "current_file": {
      "path_b64": "L3dwLWNvbnRlbnQvdXBsb2Fkcy9sYXJnZS56aXA=",
      "bytes_done": 5242880,
      "bytes_total": 20971520
    },
    "current_table": null
  },
  "error": null,
  "error_code": null,
  "reason": null,
  "detail": null,
  "ts": 1707600000.123
}
```

| Field            | Type              | Description |
|------------------|-------------------|-------------|
| `schema_version` | `int`             | Version of this documented progress-screen shape. Currently `1`. |
| `step`           | `int \| null`     | Current pipeline step (1-indexed). `null` when `--step` is not passed. |
| `steps`          | `int \| null`     | Total pipeline steps. `null` when `--steps` is not passed. |
| `command`        | `string \| null`  | Current command name (`preflight`, `files-pull`, `db-pull`, etc.). |
| `status`         | `string`          | Command status. Common values are `in_progress`, `partial`, `complete`, `error`, and `aborted`; files-push also uses `interrupted`, `restart`, and `failed`. |
| `phase`          | `string \| null`  | Current phase within the command, such as `index`, `diff`, `fetch`, or `sql`. |
| `message`        | `string \| null`  | Short action text suitable for the main progress label. Read numeric progress from `progress` instead of parsing this text. |
| `progress`       | `object`          | Stable progress-screen counters described below. |
| `error`          | `string \| null`  | Error message when `status` is `error`, otherwise `null`. |
| `error_code`     | `string \| null`  | Machine-readable error code when one is available. |
| `reason`         | `string \| null`  | Files-push terminal reason when one is available. |
| `detail`         | `string \| null`  | Files-push terminal detail when one is available. |
| `ts`             | `float`           | Unix timestamp in seconds, including a fractional part. |

`progress.items` is either `null` or `{ "unit", "done", "total" }`.
The unit says what is counted: `files`, `tables`, `local_paths`, `records`, or
`statements`. `total` is `null` when Reprint cannot know the total before
finishing the work. `progress.bytes` is either `null` or `{ "done", "total" }`
for the current byte-bounded phase. `progress.current_file` is either `null` or
`{ "path_b64", "bytes_done", "bytes_total" }`. The path is base64 because
filesystem names are arbitrary bytes. During a file fetch, the item and byte
totals cover the paths selected by the current pull. A delta pull therefore
reports only changed paths. The byte total adds regular-file content sizes;
directories and symlinks add zero bytes.

During `db-pull`, `progress.items` counts exported tables and
`progress.current_table` reports row progress for the active table. MySQL's
table row count is an estimate, so `rows_total_is_estimate` is always `true`.
The reported row count is suitable for approximate progress, not an exact
completion percentage:

```json
{
  "command": "db-pull",
  "phase": "sql",
  "message": "Downloading SQL dump",
  "progress": {
    "items": {
      "unit": "tables",
      "done": 2,
      "total": 12
    },
    "bytes": {
      "done": 500100,
      "total": null
    },
    "current_file": null,
    "current_table": {
      "name": "wp_posts",
      "rows_done": 500,
      "rows_total": 12000,
      "rows_total_is_estimate": true
    }
  }
}
```

Reprint replaces `progress.json` atomically at most once per second while a
command is active. A terminal, partial, cleared, or error state is written
immediately. A polling UI can therefore read this file without parsing JSONL.
The JSONL records that report screen progress carry the same `schema_version`
and nested `progress` object, while their event-specific top-level fields stay
available for existing consumers.
Ordinary log events, such as a skipped path, do not replace the screen's action
label. File counts include every processed path, including failed or skipped
paths, directories and symlinks. Live updates and resume use the same rule.
If a batch must be downloaded again, its old batch counts are cleared first.
A new file reports its own path and size even after an error in the previous file.
Completed file bytes exclude failed files and use their downloaded size, not
the earlier index size. Failed files or source size changes can make
completed bytes differ from the planned byte total.

Client commands accept `--progress=auto|tty|jsonl|compact`. The default `auto`
mode uses terminal progress when its output stream is a terminal and
JSONL otherwise. Use `--progress=tty` to force the terminal presentation when
output is captured, or `--progress=jsonl` to force structured progress in a
terminal:

```bash
php reprint.phar files-push "$URL" --state-dir="$STATE_DIR" \
    --fs-root="$FS_ROOT" --progress=jsonl
```

The selected mode applies only to that invocation and is not retained in
command state. Explicit `tty`, `jsonl`, and `compact` modes cannot be combined with
`--verbose`.

The mode controls progress and status output. It does not
reformat a command's data result, such as preflight or pull-metadata JSON,
files-stats JSON, or SQL written with `--sql-output=stdout`.

The file-download bar in `pull` and `pull-files` uses downloaded bytes divided
by the selected file byte total, including bytes written for the current file.
The file count beside it still counts completed paths. When the byte total is
unknown or zero, downloads show a spinner instead of a percentage.

Use `--progress=compact` to print command starts, stage changes, command results,
warnings, and errors as JSON lines. During a stage, it prints item and byte
counters at most once every 30 seconds, only when they changed. These updates
omit individual file paths and table names. A blocked request or a stage
without item/byte counters stays quiet.
It hides per-file records, preserve-local skips, repeated stage labels,
per-request results, receive-rate diagnostics, and debug chatter.
Warnings and errors, including rejected symlink targets, remain individual
records; compact mode does not group them.

```bash
php reprint.phar files-pull "$URL" --state-dir="$STATE_DIR" \
    --fs-root="$FS_ROOT" --progress=compact
```

Compact mode only changes the displayed output. It does not save the omitted
records or create `progress.jsonl`. `progress.json` still receives the latest
progress snapshot, and `audit.log` remains available for troubleshooting.
Use `--progress=jsonl` when the caller wants the full progress stream.
With `--sql-output=stdout`, SQL stays on stdout and compact progress goes to stderr.

The files-push terminal presentation uses one stage-weighted progress bar. The
percentage comes first, followed by a major stage such as `Indexing`, `Pushing`,
or `Committing`. While pushing local paths, the line also shows target-confirmed
file bytes against the planned file byte total. The percentage covers the
whole push, including indexing and commit work; it is not elapsed time or an
estimated completion time.

The JSONL presentation emits `push_progress` records. After planning completes,
their legacy `files_done` and `files_total` fields remain available. The same
counts also appear in `progress.items` with the `local_paths` unit. During a
byte-bounded files-push phase, `progress.bytes` reports the saved byte
position and total. The final result and `progress.json` use the same nested
progress object. Saved counts and byte positions are retained when a command
exits with code `2` and is run again.

## Read saved pull metadata

```bash
php reprint.phar pull-metadata https://example.com --state-dir=./state
```

This makes no network request. The source address selects the saved remote.
The result includes `hasCompletedOnce`, artifact availability, `sourceSite`,
and `key`. `sourceSite` contains the source WordPress home and site URLs,
table prefix, and reported charsets; unavailable fields are null. `key`
contains the stored key's `id`, private-key `path`, and enrollable `publicKey`,
or is null when no key is stored.

`hasCompletedOnce` records a past completed pull. It does not prove that the
current invocation completed. Check that invocation's exit code and report.
Captured output can include the data object followed by a command report;
do not decode all stdout as one JSON document.

Other files under the state directory are private implementation details.
Do not edit them or use their fields as a product integration contract.

## Audit log

`audit.log` is an append-only diagnostic log in the state directory. It records
requests, local mutations, warnings, and errors. `--verbose` also prints audit
entries. Keep the log private and review it before sharing it in a support
request; it can contain site paths and other site details.

## Temporary pull failures

Run again immediately after exit `2`. Reprint retries temporary streaming
failures itself. It stops with exit `3` when consecutive failed requests reach
the retry limit without making saved progress. The limit is four for
HTTP `429` and three for other temporary failures. Different temporary errors
share this count. A successful request, or a failed request that still saves
transfer progress, resets it.

HTTP `429` reports `RATE_LIMITED`, including when the host sends an HTML block
page. These responses do not shrink file chunks, index batches, or SQL batches:
smaller batches would require more requests. Before repeating a streaming
request after a temporary HTTP failure, Reprint waits for a valid `Retry-After`
header, expressed as seconds or an HTTP date, capped at 30 seconds per wait.
The audit log retains the original
header; `retry_after_seconds` reports the actual capped wait. For consecutive
`429` failures without a valid header, it waits 5, 10, and 15 seconds before
the three retries. Transfer progress is saved before waiting.
Stopping the process during that wait does not discard
saved transfer progress. Successful requests do not gain any delay.

Upstream HTTP `508` resource-limit responses are also temporary failures.
Preflight uses the same waits and attempt limits for upstream HTTP `429`,
`503`, and `508`, retrying the same User-Agent rather than cycling through
headers immediately. It saves the failed report before waiting and exits `3`
if the retry limit is reached. A later run fetches a fresh report. Explicit
Reprint authentication and configuration refusals remain permanent failures.
These waits also apply with `--no-adaptive`.

While waiting, Reprint prints the HTTP failure and wait time. JSONL and compact
output include a lifecycle record with `event: "retry"`, `http_code`,
`error_code`, and `retry_after_seconds`; longer waits emit heartbeats every ten
seconds. A fourth consecutive HTTP `429` failure without saved progress exits
`3` without another wait. Other temporary errors keep the three-failure limit.

After exit `3`, a caller may wait and run the same command later with the same
state directory and filesystem root. Reprint keeps its saved progress and gives
the later run a new retry allowance. Scheduling that later run is
optional; without it, a person can run the command again. Exit `1` requires
checking the error instead of scheduling an automatic retry.

This applies to temporary streaming failures in file and database transfers,
including those reached through `pull`, `pull-files`, and `pull-db`:

- HTTP `400`, `408`, `413`, `418`, `421`, `425`, `429`, `500`, `502`, `503`,
  `504`, and `520–524` without an explicit Reprint error.
- Unmarked HTTP `401` or `403` after a signed request.
- cURL timeouts, connection resets during transfer, empty or cut-short
  responses, invalid compressed responses, and HTTP/2 or HTTP/3 stream errors.
- Multipart responses missing their boundary or completion marker.

Explicit Reprint errors (JSON containing a matching HTTP `code`) remain fatal.
Preflight failures, DNS lookup failures, refused connections, certificate errors,
and local errors keep their existing classification. After exit `3`, Reprint does not schedule a later invocation for the caller.
