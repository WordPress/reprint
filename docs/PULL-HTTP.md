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
transport. A released token client, recognised by its
`X-Auth-Content-Hash` header, is refused with `client_update_required` before
its request is dispatched. When a parameter appears in both places, the
POST value wins for JSON and form bodies. The endpoint is the exception. The
server dispatches only the query's value and ignores a body endpoint. The
plugin refuses an authenticated request whose query names no endpoint with
`client_update_required`, since only released key clients send the endpoint
in the body. Existing multipart file-list uploads keep their file
part, and their options may stay in the query string.

Requests are signed over newline-joined fields behind a version label. The
connection token uses `reprint-hmac-sha256-v2`, and a key uses
`reprint-rsa-sha256-v1` plus its key id. The remaining fields are the nonce,
the timestamp, the uppercase HTTP method, and the request's path and query,
which name the endpoint. The signature never covers or hashes the body, so a
multipart upload streams without buffering.

Deploy this release before merging
[PR #801](https://github.com/WordPress/reprint/pull/801), which only removes the
exporter's query-parameter fallback. Do not remove that fallback until clients
have moved to POST parameters.

The exporter accepts `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data`. The signature covers the URL, endpoint included, and
never the body. The separate push request contract is unchanged.

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
