# CLI reporting

Add `--report` when a caller needs a final command result without interpreting
progress messages:

```sh
php reprint.phar pull "$URL" --state-dir="$STATE_DIR" \
    --fs-root="$FS_ROOT" --secret="$SECRET" --report
```

The CLI appends one JSON line after a command returns or throws a handled
exception. Existing progress and command records stay in place; preflight
assertion checks gain the stable codes described below. `preflight` without
`--report` still prints its single JSON result. With `--report`, it prints
that result followed by the report.

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

`command` is the outer command the caller invoked. Preflight inside `pull`,
`pull-files`, or `pull-db` does not produce a separate final report.

`status` is `complete`, `partial`, `error`, or `aborted`. Files-push also retains
its `interrupted`, `restart`, and `failed` outcomes and its `reason` and `detail`.
`exit_code` is the actual exit code; reporting does not choose retry timing or
change command outcomes. Exit 2 is unfinished work, not success. A successful
`--abort` reports `aborted`, not `complete`.

`failed_stage`, `error`, and `error_code` are null when unavailable. Failed
commands which have no specific error code still provide their error message.
HTTP and cURL details, including `http_code`, `curl_errno`, and
`consecutive_failures_without_progress`, are included when the command reports
them. Callers must not depend on every failure having those fields.

`preflight-assert` also includes `checks`. Each check retains its `label`, `pass`,
and `detail`, with a stable `code`: `SERVER_RESPONDED`, `PREFLIGHT_OK`,
`PROTOCOL_COMPATIBLE`, `FILESYSTEM_ACCESSIBLE`, or `DATABASE_ACCESSIBLE`.
Translate codes, not English labels or error messages. Unknown codes need a
generic fallback.

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

The report is emitted at the CLI boundary. Library users calling
`ImportClient::run()` directly still receive the command's regular output and
can read its exit code or catch its exception.
