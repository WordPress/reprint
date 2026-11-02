# Temporary failures and retries

Some requests fail for reasons that pass: a rate limit, a busy host, a dropped
connection. Reprint retries those itself during a run and stops with exit code
`3` when retrying no longer saves progress. This page lists which failures
count as temporary, how long the client waits, and what a caller does next.
See [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/) for the other
exit codes and the final command report.

## Which failures are temporary

This applies to temporary streaming failures in file and database transfers,
including those reached through `pull`, `pull-files`, and `pull-db`:

- HTTP `400`, `408`, `413`, `418`, `421`, `425`, `429`, `500`, `502`, `503`,
  `504`, and `520–524` without an explicit Reprint error.
- Unmarked HTTP `401` or `403` after a signed request.
- cURL timeouts, connection resets during transfer, empty or cut-short
  responses, invalid compressed responses, and HTTP/2 or HTTP/3 stream errors.
- Multipart responses missing their boundary or completion marker.

Upstream HTTP `508` resource-limit responses are also temporary failures.
Explicit Reprint errors (JSON containing a matching HTTP `code`) remain fatal.
Preflight failures, DNS lookup failures, refused connections, certificate errors,
and local errors keep their existing classification. Explicit Reprint
authentication and configuration refusals remain permanent failures.

## How the client retries

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

Preflight uses the same waits and attempt limits for upstream HTTP `429`,
`503`, and `508`, retrying the same User-Agent rather than cycling through
headers immediately. It saves the failed report before waiting and exits `3`
if the retry limit is reached. A later run fetches a fresh report.
These waits also apply with `--no-adaptive`.

## What you see while it waits

While waiting, Reprint prints the HTTP failure and wait time. JSONL and compact
output include a lifecycle record with `event: "retry"`, `http_code`,
`error_code`, and `retry_after_seconds`; longer waits emit heartbeats every ten
seconds. A fourth consecutive HTTP `429` failure without saved progress exits
`3` without another wait. Other temporary errors keep the three-failure limit.

## After exit code 3

After exit `3`, a caller may wait and run the same command later with the same
state directory and filesystem root. Reprint keeps its saved progress and gives
the later run a new retry allowance. Scheduling that later run is
optional; without it, a person can run the command again. Reprint does not
choose a wait time or schedule a later invocation for the caller. Exit `1`
requires checking the error instead of scheduling an automatic retry.

The final command report for exit `3` includes `http_code` when one was
received, a nonzero `curl_errno` when available, and
`consecutive_failures_without_progress`, the failure count when the limit was
reached. See [Progress, results, and exit codes](https://developer.wordpress.org/reprint/reference/progress-and-results/).
