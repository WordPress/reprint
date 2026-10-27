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
server dispatches the query's value. When the query names none, it takes the
body's value unless that value is a push endpoint. Released key clients send
the endpoint in the body, so this fallback keeps them working. Existing
multipart file-list uploads keep their file part, and their options may stay
in the query string.

For `file_fetch`, send the requested paths in the multipart `file_list` upload.
The server ignores any `file_list_path` request parameter. That option remains
available to direct PHP callers of `endpoint_file_fetch()`.

Requests are signed over newline-joined fields behind a version label. The
connection token uses `reprint-hmac-sha256-v2`, and a key uses
`reprint-rsa-sha256-v1` plus its key id. The remaining fields are the nonce,
the timestamp, the uppercase HTTP method, and the request's path and query,
which name the endpoint. The signature never covers or hashes the body, so a
multipart upload streams without buffering.

The exporter accepts `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data`. The signature covers the URL, endpoint included, and
never the body. The separate push request contract is unchanged.

The client sends URL-encoded forms for preflight and streaming pull commands.
File-list downloads retain multipart uploads, with options before
the file part. Path parameters are encoded when supported, so arbitrary
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

## Branded request path encoding

For example, `/tmp` travels as `rp.Y3EgpN==`. The client base64-encodes the
path bytes, applies ROT13 to that text, and prepends the three ASCII bytes
`rp.`:

```php
$encoded_path = 'rp.' . str_rot13(base64_encode($path));
$path = base64_decode(str_rot13(substr($encoded_path, 3)), true);
```

The server checks for `rp.` before selecting this decoder. A marked value
must decode to an absolute path in the source host's path format. Empty or
invalid base64 and relative paths are rejected; a failed marked value is not
tried again as another format. Unix and Windows paths and non-UTF-8 filename
bytes use the same byte-preserving transform.

The marker is reserved for this exact format. Its period is outside the
standard base64 alphabet, so the complete value fails strict base64 decoding.
All three marker bytes pass through URL-encoded forms without percent escapes.
The payload still uses `+`, `/`, and `=` where base64 would use them; the
existing form encoder escapes those characters as before. Multipart forms
carry the same marked value without URL encoding.

The new preflight capability is `rot13_base64_path_parameters: true`. Clients
prefer it for `directory`, `list_dir`, and `pulled_before`, including array
entries. Servers continue reporting `base64_path_parameters: true` for old
clients. A client talking to an older server sends ordinary base64 when that
older capability is present, or raw paths when neither capability is present.
Preflight itself always sends raw paths so it can discover the supported
format. New servers accept raw paths, ordinary base64, and marked values.
The protocol version remains 3 because older clients and servers still work.

This avoids handing a source path directly to a firewall's ordinary base64
decoder. A reported migration source redirected file-index requests after
inspecting the base64 paths in their POST bodies. This format is a workaround
for that kind of false match, not a promise that every firewall accepts it.
A permissive base64 decoder may ignore the period, and a firewall can implement
this transform too. The encoding adds no secrecy or authentication; HTTPS and
the existing signed request contract are unchanged.

Response headers, response cursors, file-list uploads, saved indexes, and SQL
row-filter values keep their existing encodings. Only the three request path
parameters above use the new format.

## Output before the response

A blank line before `<?php` in the site's `index.php` can send the HTTP headers
before Reprint loads. Preflight still returns its JSON object. Streaming pull
endpoints send uncompressed multipart data when they can no longer set headers.
The client skips leading spaces, tabs, and line breaks before detecting the
`--boundary-` line in the body. Part headers carry the cursors and other stream
metadata as before. This does not buffer the response or change the protocol.

This fallback does not accept HTML or PHP warning text before the boundary.
It also cannot restore an HTTP status or CORS header after headers were sent;
a browser may still block a cross-origin request. Remove the early output on
the source site to restore those headers and gzip compression.
