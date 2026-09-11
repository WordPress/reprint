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

The first deployment accepts query parameters and POST parameters. When a
parameter appears in both places, the POST value wins for both JSON and form
bodies. Old clients can keep sending query parameters during this deployment.
Afterward, deploy the client that sends every export parameter in the body,
then remove the exporter's query-parameter fallback. This is a change to where
parameters are read, not just to the HTTP method.

The exporter accepts `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data`. Sign the exact JSON or URL-encoded body bytes with
the existing HMAC client. Multipart file-list uploads retain their existing
file-content signature. The separate push request contract is unchanged.

The strict query-firewall fixture permits only the routing marker in the URL.
It rejects `endpoint` and every other query parameter even on POST requests.
Request bodies and response streams pass through unchanged. This models the
reported query rejection; it does not promise that all firewalls accept all
POST bodies.
