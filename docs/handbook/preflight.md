# Preflight reference

Examples use `$URL` for the authorized source address, `$STATE_DIR` for its
persistent state directory, and `$FS_ROOT` for downloaded files. Set these in
your shell first. Keep the state directory outside the filesystem root.
See [authorization](../authorize-client/) before running remote commands.

First, we'll make sure the server is reachable and the environment is in a good shape:

```bash
php reprint.phar preflight "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
```

The preflight contacts the export server and collects environment details: PHP/MySQL versions, memory limits, filesystem access, database connectivity, WordPress version, plugins, themes, and directory layout. The result is stored in `$STATE_DIR/remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/state.json` under the `preflight` key.

Some hosts link plugins or themes to shared files outside PHP's `open_basedir`
allowed paths. Preflight keeps those plugin names with type `unknown`, omits
uninspectable themes from the theme list, and records their base64 paths in
`wp_content.unavailable_paths_b64`. The client prints a warning for each path
and saves it in `audit.log`. These blocked entries do not stop the pull.
PHP still cannot read the blocked files; the pull may omit them. If the site
needs those files to run, ask the host to grant access or supply a replacement
before treating the copy as ready to use.

## Source address and connection checks

Remote Reprint API URLs must start with https:// or http://; URLs without a scheme and other schemes are rejected. Reprint rejects HTTP remote Reprint API URLs before selecting remote state or making requests unless `--insecure` explicitly allows an unencrypted connection. This applies to every remote command, including local-only commands and local hosts. The supplied URL's scheme is never changed.

For a site with an expired, self-signed, or wrong-hostname HTTPS certificate, `--insecure` also skips certificate and hostname verification. HTTPS stays encrypted, but Reprint cannot verify the server's identity; an attacker on the connection can read or modify transferred content. This does not repair other TLS handshake failures. `REPRINT_INSECURE_TLS=1` is an alternative to the flag; unset it or set it to `0` to keep the default checks. The choice is not saved in migration state, so supply it again when resuming.

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
[Progress, results, and exit codes](../progress-and-results/).

`progress.json` receives the same `error` and `error_code` as the command's
JSON result, rather than a generic "Preflight failed" message.

Responses with `cf-mitigated: challenge` use `CLOUDFLARE_CHALLENGE` in preflight
and downloads. Reprint cannot complete Cloudflare browser challenges. Ask the
site administrator to disable the challenge for POST requests to the Reprint
API URL or allowlist the migration machine's IP address, then try again.

If preflight reports that a hosting preview domain rewrote the response,
use the site's real domain or ask the host to disable response rewriting for
Reprint requests. The plain and encoded source domains must agree.

Low-level transfer commands require a saved preflight report. The high-level
`pull`, `pull-files`, and `pull-db` commands run preflight for you.

## Check the saved report

To check the saved filesystem and database report without another request, run:

```bash
php reprint.phar preflight-assert "$URL" --state-dir="$STATE_DIR" --fs-root="$FS_ROOT" --secret="$SECRET"
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


Next: [File transfers](../file-transfers/) or [Database transfers](../database-transfers/).
