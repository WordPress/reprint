# Authentication reference

For the WordPress settings steps, follow [Authorize the Reprint client](../authorize-client/).
This reference describes how the client chooses credentials and how a product
should handle the authorization stop.

## Migration Tokens

When the source PHP has `openssl_verify()`, Reprint Server accepts requests
signed with an enrolled public key. The private key stays with the client.
A connection token is not accepted by that source; the client does not fall
back to a different method.

With no credential, a first `pull` generates a key, prints its Migration Token,
and exits with code `4`. Ask the administrator to enroll it, then run the same
command with the same state. Installing the plugin alone does not authorize it.

For a pipeline that starts with a lower-level command, generate the key first:

```sh
php reprint.phar keygen https://example.com --state-dir=./state
```

The generated private key is stored for that source under the state directory.
Later remote commands find it automatically. `--private-key-path=PATH` selects
another private key; `keygen --out=PATH` writes one to a chosen path.
Deleting the state directory can therefore remove the credentials needed to
continue. Use `pull-metadata` to inspect the saved key's public enrollment value.

In JSONL or compact mode, key generation reports its `key_id`, `key_path`, and
`public_key` in the command report. Handle this as a request for user action,
not an automatic retry. See [Progress and results](../progress-and-results/).

## Connection tokens

When source PHP lacks OpenSSL verification, both sides use the connection token
saved in WordPress settings. Pass it with `--secret=TOKEN`. The settings screen
shows which method the source supports. A key signature is not accepted on a
connection-token-only host.

Update the client and plugin together when a token-format mismatch asks for an
update. Preserve the original site URL, including any routing query parameters.
Use working HTTPS; `--insecure` is an explicit exception to connection checks,
not a remedy for an invalid credential.

## Remove access

Remove the enrolled Migration Token in WordPress settings, or replace or clear
the connection token on a token-only source. Retaining a local copy does not
require keeping source access enabled.

## Host-managed credentials

A host can supply public keys or a connection token outside the settings UI.
A configured public-keys file becomes the only key source and disables local
enrollment. Host-managed credentials must be changed through that host's process.

For PHP embedding, managed configuration, and the separate push permission,
see the [plugin source configuration guide](https://github.com/WordPress/reprint/blob/trunk/reprint-server-wp/README.md).
The WordPress.org server package remains downloads-only regardless of grants.
