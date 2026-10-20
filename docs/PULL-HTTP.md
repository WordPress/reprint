# Export parameter rollout

Keep the API routing marker (`?reprint-api` or the legacy `?site-export-api`)
in the URL. The client keeps every parameter of the supplied API URL, including
any custom routing query parameters, and appends `endpoint` to its query. It
does not copy parameters out of that URL. Every other export parameter travels
in the POST body. For example, POST to
`/?reprint-api&endpoint=db_index` with:

```json
{
  "skip_rows": [
    {
      "table_name_without_prefix": "postmeta",
      "column": "meta_key",
      "value_base64": "X2VkaXRfbG9jaw=="
    }
  ]
}
```

[PR #800](https://github.com/WordPress/reprint/pull/800) ships the POST client
and the compatible exporter together. The exporter still reads export
parameters from the query string, where clients sent them before the POST
transport. An older key or token client whose signature lacks
`X-Auth-Origin`, or sends the obsolete `X-Auth-Content-Hash` header, is refused
with `client_update_required` before its request is dispatched. When a parameter appears in both places, the
POST value wins for JSON and form bodies. The endpoint is the exception. The
server dispatches the query's value. When the query names none, it takes the
body's value unless that value is a push endpoint. Direct callers can still
name a pull endpoint in the body while signing the bare API URL. Existing
multipart file-list uploads keep their file part, and their options may stay
in the query string.

For `file_fetch`, send the requested paths in the multipart `file_list` upload.
The server ignores any `file_list_path` request parameter. That option remains
available to direct PHP callers of `endpoint_file_fetch()`.

Requests are signed over newline-joined fields behind a version label. The
connection token uses `reprint-hmac-sha256-v3`, and a key uses
`reprint-rsa-sha256-v2` plus its key id. The remaining fields, in order, are the
nonce, timestamp, uppercase HTTP method, request path and query, and destination
origin. There is no trailing newline. The origin is the URL scheme, hostname,
and non-default port, for example `https://example.com:8443`. Both signers send
it as `X-Auth-Origin`. The verifier compares it with the trusted public API URL
before accepting the signature. It never derives that expected address from
incoming headers. The signature never covers or hashes the body, so a multipart
upload streams without buffering.

This is authentication generation 3 and requires both client and plugin
v0.11.3 or later. See [public API URL configuration](../reprint-server-wp/README.md#public-api-url)
for WordPress defaults, standalone routes, and reverse proxies.

The exporter accepts `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data`. The signature covers the destination origin and the path and query. An
endpoint supplied only in the body is not signed, nor is the rest of the body. The separate push request contract is unchanged.

The client sends URL-encoded forms for preflight and streaming pull commands.
File-list downloads retain multipart uploads, with options before
the file part. Base64 path encoding is still used when supported, so arbitrary
filename bytes survive PHP form parsing. Cursors travel only in the body, which
also keeps a large cursor clear of host header size limits. The exporter still
reads an `X-Export-Cursor` header when the body has no cursor, for older
clients. The strict-WAF test strips that header and checks SQL continuation.

Multipart array fields use bracketed names built directly from their keys.
They do not depend on PHP's `arg_separator.output` setting.

The strict query-firewall test supplies an API URL with only its routing marker.
The client does not remove caller-supplied query parameters to satisfy a WAF.
The fixture strips every query parameter whose value contains anything but
letters, digits, and underscores and forwards the rest. The reported firewall
objected to base64 characters in query values. It strips such parameters on
POST requests too.
Request bodies and response streams pass through unchanged. This models the
reported query rejection, and it does not promise that all firewalls accept all
POST bodies.

A backward-compatibility test bypasses the strict WAF and sends legacy GET
query parameters, then uploads a multipart file list with its endpoint and
options still in the query. Normal export requests in the other tests use POST parameters.
