# Preflight reference

Examples use `$URL`, `$STATE_DIR`, `$FS_ROOT`, and an authorized
`./reprint-key.pem` as described in [Conventions](https://developer.wordpress.org/reprint/commands/#conventions).

Check whether the source is reachable and ready to supply a copy:

```bash
php reprint.phar preflight "$URL" \
  --private-key-path=./reprint-key.pem --state-dir="$STATE_DIR" --fs-root="$FS_ROOT"
```

Preflight checks the source's PHP and database versions, available memory,
filesystem access, plugins, themes, and WordPress layout. It saves the report
for later commands; it does not download the site's files or database. Use
`preflight-assert` below to check the saved report.

Some hosts restrict access to shared plugin or theme files. Reprint warns about
files it cannot read and records those warnings in `audit.log`. The copy can
continue without those files. If the site needs them to run, ask the host to
grant access or supply a replacement before treating the copy as ready to use.

## Source address and connection checks

Use the source site's full HTTPS address. Addresses without https:// or http://
are rejected. An HTTP address requires `--insecure`, even for localhost or a
command that only reads saved state. Reprint does not switch HTTP to HTTPS
automatically.

For HTTPS, `--insecure` also accepts expired, self-signed, or wrong-hostname
certificates. The connection stays encrypted, but Reprint cannot verify that
it reached the intended server. Someone intercepting the connection could
read or change the transferred data. Other HTTPS connection failures can
still stop the transfer.

`REPRINT_INSECURE_TLS=1` is an alternative to the flag. Unset it or set it to
`0` to keep the default checks. The choice is not saved, so supply it again
when resuming.

To resume state created over HTTP by an older client, keep using the HTTP URL with `--insecure`.

## Results and errors

The command's JSON result keeps the response data and HTTP details, and includes
`status`, `error`, `error_code`, and `message`. On failure, `status` is `error`,
`error` contains the failure detail, and `message` is the same detail prefixed
with `Error: `. HTTP failures use the same codes as downloads, such as
`SERVER_ERROR`, `AUTH_FAILED`, and `REDIRECT`. Connection failures use
`CURL_ERROR`; malformed JSON uses `INVALID_JSON`; a response without a preflight
object uses `INVALID_PREFLIGHT_RESPONSE`. Failed preflight checks use
`PREFLIGHT_FAILED`. On success, `status` is `complete`, and both error fields
are null. Permanent failures exit with code `1`. Temporary request failures
can stop with code `3` after the retry allowance; see
[Progress, results, and exit codes](https://developer.wordpress.org/reprint/progress-and-results/).

`progress.json` receives the same `error` and `error_code` as the command's
JSON result, rather than a generic "Preflight failed" message.

Reprint reports `CLOUDFLARE_CHALLENGE` when Cloudflare requires a browser
challenge. The client cannot complete that challenge. Ask the site administrator
to disable the challenge for POST requests to the Reprint API URL or allowlist
the migration machine's IP address, then try again.

If preflight reports that a hosting preview domain rewrote the response,
use the site's real domain or ask the host to disable response rewriting for
Reprint requests.

Low-level transfer commands require a saved preflight report. The high-level
`pull`, `pull-files`, and `pull-db` commands run preflight for you.

## Check the saved report

To check the saved filesystem and database report without another request, run:

```bash
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT"
```

`preflight-assert` reads the saved report without making another preflight
request. Its JSONL result includes the same error fields alongside the existing
`checks` list. Request failures retain their saved detail and code. Assertion
failures report the failed check details with `PREFLIGHT_FAILED`. If no report
has been saved, it reports `PREFLIGHT_REQUIRED` and asks you to run `preflight`.
The terminal summary and `progress.json` also include the failure detail.
The preflight stage inside `pull`, `pull-files`, and `pull-db` uses the same
failure details and codes. Download errors use the same four fields; their
`message` may add the failed stage, while `error` and `progress.json` retain
the failure detail. cURL failures use `CURL_ERROR` for both preflight and downloads.


Next: [File transfers](https://developer.wordpress.org/reprint/file-transfers/) or [Database transfers](https://developer.wordpress.org/reprint/database-transfers/).
