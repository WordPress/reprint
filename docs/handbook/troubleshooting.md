# Troubleshooting

Keep the original command and its error message. Do not delete `state/` as
a first step: it contains credentials and saved transfer progress.

## The client does not start

If you see **command not found: php**, install command-line PHP or use your
development environment's PHP binary. If PHP cannot open `reprint.phar`, check
that your terminal is in the folder where you saved it.

Run `php --version` and `php -m` and compare the output with
[Requirements and downloads](requirements.md). A missing PHP extension must
be enabled for the terminal's PHP, which may differ from your web server's PHP.

## The first command stops after printing a Migration Token

This is expected. The client stops with exit code `4` so an administrator can
[authorize it](authorize-client.md). After authorization, run the same command
again, from the same folder and with the same `--state-dir`.

If authorization still fails, check that you authorized the token printed by
this client for this source site. Existing tokens for other clients do not grant
access to this one. If the host requires a connection token, use the alternative
steps on the authorization page. Update old clients and plugins together if
the error asks for an update.

## The source cannot be reached

Check that the source URL uses HTTPS and that the plugin is active. Copy the
Reprint API URL shown on the plugin settings screen if you are unsure which
address to use.

A login wall, firewall, or security challenge can prevent the client from
reaching the API. Ask the host to allow the Reprint requests. Resolve certificate
errors rather than disabling HTTPS checks for a transfer of real site data.

## A security service blocks the client

The client sends POST requests with custom authentication headers to the
Reprint API address. Services in front of the site can stop them:

- **Cloudflare browser challenge.** The client reports `CLOUDFLARE_CHALLENGE`
  and cannot complete the challenge. Ask the site administrator to disable it
  for POST requests to the Reprint API address or to allowlist the migration
  machine's IP address, then run the command again.
- **Wordfence or another firewall.** The client reports `WORDFENCE_BLOCKED`
  when Wordfence's request limit or a firewall rule stops it. Wait for a
  temporary block to expire and resume. If it keeps happening, ask the
  administrator to raise the limit or allowlist the machine.
- **A proxy that strips headers.** The client reports `AUTH_HEADERS_STRIPPED`
  when the site did not receive the authentication headers it sent. Ask the
  host to pass custom HTTP headers through to WordPress for the Reprint API
  address.

## Preflight reports a preview domain

Some hosts serve a site at a temporary preview domain before its real domain
points at them, and rewrite the real domain inside responses. Preflight
compares the domain in the site's home URL with a copy the plugin sends and
stops when they differ, reporting both. Use the site's real domain as the
source address, or ask the host to leave Reprint responses unchanged.

## A transfer was interrupted

Run the same `pull` command again with the same source address, state
directory, filesystem root, and options. Reprint uses its saved progress.
Read any failure message first; a permission or configuration problem still
needs to be corrected.

If a command reports that it cannot resume a particular database operation,
follow that message's instructions for a fresh import. Do not assume that
every database engine or failure can resume.

If the client stopped with exit code `3`, it reached its retry limit for a
temporary failure such as a rate limit or a dropped connection. Run the same
command again later. See [Temporary failures and retries](temporary-failures.md).

## The local server will not start

If port `8881` is already in use, stop the other local server and run:

```bash
bash state/runtime/start.sh
```

Run this from the working folder used in the tutorial. If the file does not
exist, runtime setup has not finished; check the preceding error and resume
the original pull command.

## The local site fails or behaves differently

Check the local server's terminal output for the first PHP error. The local PHP
version, extensions, database, or missing source-host service may be the cause.
SQLite does not make every MySQL-dependent plugin work unchanged.

Check the browser address too. The tutorial rewrites the site to
http://localhost:8881; opening the original address still visits the live site.

To restart an already copied site, use `start.sh`. Repeating a completed `pull`
can import the source database again and replace local database changes.

## Error codes

Error messages carry a code, shown in the terminal and as `error_code` in the
command report. Use it to find the cause:

| Code | Meaning | What to do |
| --- | --- | --- |
| `AUTH_NO_CREDENTIAL` | No key or token was passed and the site requires one. | Create and authorize a key with `keygen`, or pass `--secret`. |
| `AUTH_UNKNOWN_KEY` | The Migration Token is not enrolled on the site. | Authorize it under **Tools → Reprint Server**, or check that the Migration Token ID matches an enrolled one. |
| `AUTH_NOT_CONFIGURED` | The site requires key authorization and has no keys enrolled, or its credential configuration is broken. | Enroll the Migration Token. On a token host, check the connection settings and the site's PHP error log. |
| `AUTH_REQUIRES_KEY` | The site has OpenSSL and accepts Migration Tokens only, but a connection token was passed. | Use `keygen` and `--private-key-path` instead of `--secret`. |
| `AUTH_REQUIRES_TOKEN` | The site lacks OpenSSL and accepts a connection token only. | Pass `--secret` with the token saved in the plugin settings. Update the plugin if the message asks. |
| `AUTH_HEADERS_STRIPPED` | The site did not receive the client's authentication headers. | Ask the host to pass custom HTTP headers through to WordPress. |
| `AUTH_FAILED` | The site rejected the signature or token. | Check that you used the credential authorized for this site and this client. |
| `NOT_FOUND`, `HTML_RESPONSE` | The address returned a 404 or a web page instead of the Reprint API. | Check that the plugin is active and copy the API address from the plugin settings. |
| `REDIRECT` | The site redirected the request. The client does not follow redirects. | Use the address the message names, with the right scheme and `www` form. |
| `CLOUDFLARE_CHALLENGE`, `WORDFENCE_BLOCKED` | A security service blocked the request. | See [A security service blocks the client](#a-security-service-blocks-the-client). |
| `RATE_LIMITED` | The source answered HTTP 429. The client waits and retries, then stops with exit code `3`. | Run the command again later. Ask the host to allow the migration machine if it continues. |
| `REQUEST_TOO_LARGE` | The source rejected a request as too large (HTTP 413). | Treated as a temporary failure; see [Temporary failures and retries](temporary-failures.md). |
| `SERVER_ERROR` | The source answered with a server error. | Check the source's PHP error log. Temporary errors are retried. |
| `CURL_ERROR` | The connection failed: DNS, a refused connection, a timeout, or a certificate problem. | Check the address and the site's certificate. |
| `INVALID_JSON`, `INVALID_PREFLIGHT_RESPONSE` | The response was not the JSON the client expected. | Check for PHP warnings or a maintenance page at the source, and confirm the API address. |
| `PREFLIGHT_FAILED` | A preflight check failed. | Read the listed checks; see [Preflight](preflight.md). |
| `PREFLIGHT_REQUIRED` | A low-level command ran before `preflight` saved a report. | Run `preflight` with the same source address and state directory. |

## Ask for help

Ask usage questions in the
[WordPress.org support forum](https://wordpress.org/support/plugin/reprint/).
Report reproducible development bugs in the
[Reprint issue tracker](https://github.com/WordPress/reprint/issues).

Include the client and plugin versions, the command with tokens removed, and
the exact error. Leave out private keys, connection tokens, state directories,
and database dumps. Check logs for private site data before sharing them.
Report security vulnerabilities privately.
