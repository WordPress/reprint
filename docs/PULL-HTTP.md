# Export parameter rollout

Keep the API routing marker (`?reprint-api` or the legacy `?site-export-api`)
in the URL. Every export parameter, including `endpoint`, belongs in the POST
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

Deploy the query/POST-compatible exporter from
[PR #800](https://github.com/WordPress/reprint/pull/800) first. Then deploy the
POST client before deploying this exporter, which no longer reads export
parameters from the query string. Old query-based clients cannot use this
exporter. An endpoint supplied only in the query is missing, even on POST.
Query values cannot supply defaults or override body values.

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

The strict query-firewall fixture permits only the routing marker in the URL.
It rejects `endpoint` and every other query parameter even on POST requests.
Request bodies and response streams pass through unchanged. This models the
reported query rejection; it does not promise that all firewalls accept all
POST bodies.
