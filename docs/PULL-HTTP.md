# Export parameter rollout

Keep the API routing marker (`?reprint-api` or the legacy `?site-export-api`)
in the URL. The client passes the supplied API URL to cURL unchanged, including
any custom routing query parameters. It does not copy parameters out of that URL.
Every client-generated export parameter, including `endpoint`, goes in the POST
body. For example, POST to `/?reprint-api` with:

```json
{
  "endpoint": "db_index",
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
and the compatible exporter together. The exporter still accepts query
parameters from older clients. When a parameter appears in both places, the
POST value wins for JSON and form bodies. Existing multipart file-list uploads
keep their file part and file-content signature, including when an older client
sends the endpoint and options in the query string.

Deploy this release before merging
[PR #801](https://github.com/WordPress/reprint/pull/801), which only removes the
exporter's query-parameter fallback. Do not remove that fallback until clients
have moved to POST parameters.

The exporter accepts `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data`. Sign the exact JSON or URL-encoded body bytes with
the existing HMAC client. Multipart file-list uploads retain their existing
file-content signature. The separate push request contract is unchanged.

The client sends URL-encoded forms for preflight and streaming pull commands.
File-list downloads retain multipart uploads, with endpoint and options before
the file part. Base64 path encoding is still used when supported, so arbitrary
filename bytes survive PHP form parsing. Cursors travel in the body as well as
the existing header; the strict-WAF test removes that header and checks SQL
continuation.

Multipart array fields use bracketed names built directly from their keys.
They do not depend on PHP's `arg_separator.output` setting.

The strict query-firewall test supplies an API URL with only its routing marker.
The client does not remove caller-supplied query parameters to satisfy a WAF.
The fixture permits only the routing marker in the URL.
It rejects `endpoint` and every other query parameter even on POST requests.
Request bodies and response streams pass through unchanged. This models the
reported query rejection; it does not promise that all firewalls accept all
POST bodies.

A backward-compatibility test bypasses the strict WAF and sends legacy GET
query parameters, then uploads a multipart file list with its endpoint and
options still in the query. Normal export requests in the other tests use POST parameters.
