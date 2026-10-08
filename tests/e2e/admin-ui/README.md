# Reprint administrator UI browser tests

Use a **disposable, single-site WordPress install on loopback**, with the current
Reprint Server plugin active, OpenSSL available, no enrolled keys, and no
file-managed credentials or push policy. These tests add keys, grant push
access, and remove their keys through real WordPress forms. They do not push
files. If a test fails midway, reset the disposable site's Reprint settings
before rerunning it.

From `tests/e2e`:

```sh
npm ci
npx playwright install chromium
REPRINT_ADMIN_TEST_URL=http://127.0.0.1:18850 \
REPRINT_ADMIN_TEST_USER=admin \
REPRINT_ADMIN_TEST_PASSWORD=password \
npm run test:admin
```

The URL has no trailing slash. The test refuses non-loopback URLs. Use test
credentials, never credentials for a real site.

The browser covers first setup, invalid and duplicate keys, multiple keys,
explicit per-key push consent, removal back to setup, native disclosures and
forms with JavaScript disabled, mobile access controls, clipboard success,
and clipboard permission failure. Host policy, credential-file precedence,
token-only hosts, and network capability rules are covered by
`tests/ReprintServerPluginTest.php`.
