# Pull request rollout

The exporter already accepts GET and POST. Deploy this tested exporter
before deploying a client that requires POST. Remove GET pull support only after that exporter deployment.

Pull endpoints accept `application/json`, `application/x-www-form-urlencoded`,
and `multipart/form-data` request bodies. Keep the API routing marker
(`?reprint-api` or `?site-export-api`) and `endpoint` in the URL. Put pull
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
