# Reprint Exporter — WordPress Plugin

When working from this monorepo checkout, run `composer install` in
`reprint-exporter-wp/` to populate the bundled `vendor/` directory used by the
plugin runtime. GitHub release ZIPs already include that vendor tree.

## API Routing

Many shared hosts (SiteGround, GoDaddy, etc.) block direct PHP execution inside `wp-content/plugins/` at the web server level, returning a 403 before the request ever reaches PHP. To work around this, export API requests are routed through WordPress's front controller (`index.php` at the site root), which hosts never block.

### How it works

The plugin file (`index.php`) is `include`'d by WordPress during its plugin loading loop — this happens *before* the `plugins_loaded` hook fires, making it the earliest interception point available to a regular plugin.

When a request arrives at `https://example.com/?reprint-api` (or the legacy `?site-export-api` alias, kept for backwards compatibility), the plugin:

1. Detects `$_GET['reprint-api']` or `$_GET['site-export-api']` during plugin file load
2. Reverts WordPress error display settings (`display_errors`, `html_errors`) that `wp_debug_mode()` may have turned on
3. Clears any output buffering WordPress started
4. Sets up error handlers, HMAC auth, and runs the export endpoint
5. Calls `exit` — WordPress never finishes booting

This gives us a clean execution environment while using WordPress's front controller as the entry point.

### Platform configuration

The bundled WordPress entry point passes the result of the
`site_export_api_options` filter to the export handler. A platform must
register this filter before the regular Reprint Exporter plugin file loads;
registering it on `plugins_loaded` is too late. A must-use plugin is the usual
place to register it:

```php
add_filter('site_export_api_options', static function (array $options): array {
    $options['docroot'] = '/srv/www/public';
    $options['reprint_directory'] = '/srv/www/.reprint';
    return $options;
});
```

The supported options are:

- `authenticate` — a callback that authenticates every non-preflight API
  request instead of the built-in HMAC verifier. For a push request, this
  callback must authenticate from request metadata without reading or
  buffering `php://input`; the endpoint streams that body after authentication.
- `docroot` — the document root for push. It must resolve to an
  existing directory and defaults to `$_SERVER['DOCUMENT_ROOT']`.
- `reprint_directory` — the private push storage directory outside `docroot`.
  It defaults to a document-root-specific sibling directory.
- `excluded_paths` — document-root-relative paths that push must preserve. The
  exporter plugin directory is also preserved automatically when it is below
  `docroot`.
- `maximum_part_bytes` — the maximum `Content-Length` accepted for one push
  upload part. It defaults to 4 MiB.
- `maximum_commit_entries` — the maximum number of bounded entries processed
  by one `push_commit` request. It defaults to 256.

## Push access

Connection tokens authorize downloads only by default. This also applies to
tokens that already existed when the plugin was upgraded; no migration enables
push access. A site administrator can grant push access from the plugin settings
page. The grant stores a fingerprint of the current connection token, so rotating
that token revokes the grant and requires fresh consent.

Hosts can manage push access before active plugins load with an immutable boolean:

```php
define('SITE_EXPORT_PUSH_ENABLED', true);
```

The `SITE_EXPORT_PUSH_ENABLED` environment variable accepts the same boolean
policy. The constant wins when both are present. `true` enables push without a
local grant; `false` hard-disables push even when a local grant exists. The sole
recovery exception lets an authenticated caller finish a commit which already
has a durable checkpoint, so revocation cannot strand a partially changed
document root. It cannot start commit or use any other push operation until
push is authorized again. Managed sites show the effective state as read-only
in WordPress admin. Custom authentication does not bypass this authorization
gate.

## Using as a library

The export engine can be embedded in another PHP project without the WordPress plugin wrapper. Require `lib.php` instead of `index.php` — it defines constants and functions but does not handle any HTTP requests or check any URLs.

```php
// Your project must define ABSPATH before requiring lib.php.
define('ABSPATH', '/path/to/wordpress/');

require_once '/path/to/reprint-exporter-wp/lib.php';

// Route however you like — lib.php doesn't check URLs.
if ($myRouter->matches('/export')) {
    // Use default HMAC authentication (reads secret.php when present,
    // otherwise falls back to the site option):
    _site_export_handle_api_request();

    // Or supply your own authentication:
    _site_export_handle_api_request([
        'authenticate' => function () {
            if (!my_auth_check()) {
                _site_export_error(403, 'Unauthorized');
            }
        },
    ]);
}
```

`_site_export_handle_api_request()` accepts the same options documented under
Platform configuration. Direct `lib.php` embedders pass them as the function's
array argument and do not use the WordPress filter.

`lib.php` defines these constants (using WordPress's `plugin_dir_path`):

- `SITE_EXPORT_VERSION` — plugin version string
- `SITE_EXPORT_PLUGIN_DIR` — absolute path to the plugin directory
- `SITE_EXPORT_SECRET_FILE` — optional path to a PHP file that overrides the stored HMAC shared secret
- `SITE_EXPORT_SECRET_OPTION` — WordPress site option name used for the stored HMAC shared secret
- `SITE_EXPORT_PUSH_AUTHORIZATION_OPTION` — WordPress site option containing the token fingerprint granted personal push access
- `SITE_EXPORT_TIMESTAMP_TOLERANCE` — max request age in seconds (default 300)
