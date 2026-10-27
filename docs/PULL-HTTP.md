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
Each record's `path` uses ordinary base64 or
[`rp.`-prefixed ROT13-base64](#sending-file-paths-through-source-firewalls),
depending on the source's preflight capabilities.
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
the file part. Path parameters use the
[format selected by preflight](#choosing-a-format-for-older-servers).
Both base64 formats preserve non-UTF-8 filename bytes. Cursors travel only in
the body, which also keeps a large cursor clear of host header size limits. The
exporter still reads an `X-Export-Cursor` header when the body has no cursor, for older
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

## Sending file paths through source firewalls

For an index request selecting `/tmp`, the client sends `rp.Y3EgpN==` instead
of ordinary base64 (`L3RtcA==`) when the source reports support. Here are the
POST fields after form decoding:

| Field | Ordinary base64 | ROT13-base64 with the `rp.` prefix |
| --- | --- | --- |
| `list_dir` | `L3RtcA==` | `rp.Y3EgpN==` |
| `directory[0]` | `L3RtcA==` | `rp.Y3EgpN==` |

The same format applies to `pulled_before` and other array entries in these
directory parameters. The endpoint stays in the URL.

A reported source passed preflight but redirected file-index requests with
base64 paths in their POST bodies. Disabling its Hide My WP IDS allowed the
migration to proceed. The format below is intended to avoid that kind of false
match. It has not been tested against that source's IDS.

### Encoding and decoding a path

The client base64-encodes the path bytes, applies ROT13 to that text, and adds
`rp.`:

```php
$encoded_path = 'rp.' . str_rot13(base64_encode('/tmp'));
// rp.Y3EgpN==
```

The server recognizes the exact `rp.` prefix and reverses those steps:

```text
rp.Y3EgpN==  ->  Y3EgpN==  ->  L3RtcA==  ->  /tmp
             strip rp.      ROT13        base64 decode
```

Decoded directory parameters must be absolute paths in the source host's path
format. Empty values, invalid base64, and relative paths are rejected. A failed
`rp.` value is not tried again as another format. The transform preserves the
original bytes, including Windows paths and non-UTF-8 filename bytes.

The period is outside the standard base64 alphabet. For example,
`base64_decode('rp.Y3EgpN==', true)` returns `false`. The prefix is reserved for
this exact format; it selects the Reprint decoder rather than an ordinary
base64 decoder.

The prefix adds no percent escapes to a URL-encoded form. For `/tmp`, the actual
form field is:

```text
list_dir=rp.Y3EgpN%3D%3D
```

Only the payload's `+`, `/`, and `=` need the same escaping as ordinary base64.
Multipart forms carry `rp.Y3EgpN==` without URL encoding.

### File-list uploads

For a download of `/tmp/a.txt`, the `path` in the uploaded `file_list` JSON
changes as follows. Other record fields are omitted:

```diff
-[{"path":"L3RtcC9hLnR4dA=="}]
+[{"path":"rp.Y3EgpP9uYaE4qN=="}]
```

This applies when the source reports support, including runtime-file downloads
and pending batches prepared by an older client. Immediately before upload,
the client writes an encoded temporary copy one entry at a time. Paths already
starting with `rp.` are copied without encoding them again. The input list
stays unchanged, and the temporary copy is removed when the request returns or
throws. Newly prepared batches count the prefix bytes against the request-body
budget. The server accepts both formats, even within one uploaded list.

### Cursors sent back to the source

A cursor tells the source where to continue. A file response can return the
base64 encoding of this JSON:

```json
{"path":"L3RtcC9hLnR4dA==","bytes":16384}
```

The client keeps the response cursor unchanged in saved state. When the source
reports support, only the copy sent in the next POST changes:

```php
$post_cursor = 'rp.' . str_rot13($response_cursor);
```

The cursor is already base64, so the client does not base64-encode it again or
change its nested paths. The server removes `rp.`, undoes ROT13, then runs the
existing base64 and cursor JSON checks. Resuming needs no saved-state conversion.

### Choosing a format for older servers

Preflight reports `rot13_base64_request_values: true` when the source accepts
the new format. The client selects the following POST formats:

| Source reports | `directory`, `list_dir`, `pulled_before` | Uploaded file-list paths and POST cursors |
| --- | --- | --- |
| `rot13_base64_request_values: true` | `rp.` + ROT13-base64 | `rp.` + ROT13-base64 |
| Only `base64_path_parameters: true` | Ordinary base64 | Ordinary base64 |
| Neither | Raw paths | Ordinary base64 |

Preflight itself sends raw paths so it can discover support. New servers still
report `base64_path_parameters: true` and accept ordinary base64 and raw
directory paths for older clients. Protocol version 3 remains unchanged.

Response headers, response cursors, saved indexes, and SQL row-filter values
keep their existing encodings. Push requests and their multipart part headers
are unchanged.

This encoding adds no secrecy or authentication. A permissive base64 decoder
can ignore punctuation, and a firewall can learn the transform too. It does not
guarantee acceptance by every firewall. HTTPS and signed requests are unchanged.

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
