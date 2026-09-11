# Pull request rollout

Pull endpoints require POST. An authenticated GET receives HTTP 405 with `Allow: POST, OPTIONS`
and an instruction to update the client. There is no GET fallback in the client.

Deploy the GET/POST-compatible exporter from [PR #800](https://github.com/WordPress/reprint/pull/800) first. Only then deploy
the POST client and the exporter that rejects GET. Existing GET clients cannot
use the POST-only exporter. Push request methods and authentication are unchanged.

Pull endpoints accept `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data` request bodies. Keep the API routing marker
(`?reprint-api` or `?site-export-api`) and `endpoint` in the URL. The client
also preserves unrelated routing keys in an embedder-supplied API URL. Put pull
options such as `directory`, `cursor`, and `skip_rows` in the body. A firewall
may reject base64 values or nested parameter names in a query string before
WordPress receives the request.

For example, POST to `/?reprint-api&endpoint=db_index` with this JSON body:

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

Sign the exact body bytes with the existing HMAC client. Multipart file-list
uploads use the existing file-content signature. Do not sign a GET request
and reuse its empty-body signature for a POST body.

The query-firewall E2E fixture rejects every query key except the API routing
markers and `endpoint`. It passes request bodies and response streams through
unchanged. This models the reported query rejection; it does not promise that
all firewalls accept all POST bodies.

The client sends URL-encoded forms for preflight and streaming pull commands.
File-list downloads retain multipart uploads, with the options in form fields.
Base64 path encoding is still used when supported, so arbitrary filename bytes
survive PHP form parsing. Cursors travel in the body as well as the existing
header; the strict-WAF test removes that header and checks SQL continuation.
