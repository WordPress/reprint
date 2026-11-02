# Authentication reference

[Authorize a client in WordPress](https://developer.wordpress.org/reprint/authorize-client/)

## Migration Tokens

Reprint uses public/private-key authorization whenever the server supports
OpenSSL. The client keeps the private key. The Migration Token you authorize
on the server contains the public key.

A server using key authorization does not accept a connection token, and the
client does not switch to a different authorization method after a failure.

With no credential, a first `pull` generates a key, prints its Migration Token,
and exits with code `4`. Ask the administrator to enroll it, then run the same
command with the same state. Installing the plugin alone does not authorize it.

To use an explicit key file, generate it before running transfer commands:

```sh
php reprint.phar keygen 'https://example.com/?reprint-api' \
  --state-dir=./state --out=./reprint-key.pem
```

Authorize the printed Migration Token in WordPress, then pass the private key
to each command that contacts the source:

```sh
php reprint.phar preflight 'https://example.com/?reprint-api' \
  --private-key-path=./reprint-key.pem --state-dir=./state --fs-root=./files
```

Without `--out`, the client stores the key under the state directory and can
find it automatically for the same source address. Handbook examples name the
key file explicitly. Keep that file and the state directory between runs.

In JSONL or compact mode, key generation reports its `key_id`, `key_path`, and
`public_key` in the command report. Handle this as a request for user action,
not an automatic retry. See [Progress and results](https://developer.wordpress.org/reprint/progress-and-results/).

## Connection tokens

When the server does not support OpenSSL, the client and server use a shared
connection token saved in WordPress settings. Pass it with `--secret=TOKEN`.
The settings screen shows which authorization method the server supports.

For example:

```sh
php reprint.phar preflight 'https://example.com/?reprint-api' \
  --secret='TOKEN' --state-dir=./state --fs-root=./files
```

Replace `TOKEN` with the saved connection token and include it on every command
that contacts that source, including later runs. Use `--secret` instead of
`--private-key-path`, never both. Commands that only read or change local data,
such as `db-apply` and `apply-runtime`, do not need source credentials.

Update the client and plugin together when a token-format mismatch asks for an
update. Preserve the original site URL, including any routing query parameters.
Use working HTTPS; `--insecure` is an explicit exception to connection checks,
not a remedy for an invalid credential.

## Remove access

Remove the enrolled Migration Token in WordPress settings, or replace or clear
the connection token on a token-only source. Keeping your copy does not
require keeping source access enabled.

## Host-managed credentials

A host can supply public keys or a connection token outside the settings UI.
A configured public-keys file becomes the only key source and disables local
enrollment. Host-managed credentials must be changed through that host's process.

For PHP embedding, managed configuration, and the separate push permission,
see the [plugin source configuration guide](https://github.com/WordPress/reprint/blob/trunk/reprint-server-wp/README.md).
The WordPress.org server package remains downloads-only regardless of grants.
