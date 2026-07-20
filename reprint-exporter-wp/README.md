# Reprint Exporter — WordPress Plugin

When working from this monorepo checkout, run `composer install` in
`reprint-exporter-wp/` to populate the bundled `vendor/` directory used by the
plugin runtime. GitHub release ZIPs already include that vendor tree.

## HTTP routes

The plugin exposes separate URLs for pull and push because they have different
boot requirements.

### Download route

Many shared hosts block direct PHP execution below `wp-content/plugins/`.
Pull requests therefore use WordPress's front controller:

```text
https://example.com/?reprint-api
```

The legacy `?site-export-api` query remains available. WordPress includes the
plugin before `plugins_loaded`; the plugin sees the query, authenticates the
request, dispatches it, and exits before WordPress finishes booting.

A platform may replace the built-in authentication through an early
`site_export_api_options` filter. Register it before regular plugins load, such
as from a must-use plugin:

```php
add_filter('site_export_api_options', static function (array $options): array {
    $options['authenticate'] = static function (): void {
        if (!my_auth_check()) {
            _site_export_error(403, 'Unauthorized');
        }
    };
    return $options;
});
```

This filter applies to the WordPress download route only. Push does not pass
through WordPress.

### Push route

Every push operation uses a standalone PHP route. This includes
`push_create`, so `files-push` can start a new push when a prior push left
WordPress unable to boot. The WordPress route rejects all `push_*` requests.

The bundled URL is:

```text
https://example.com/wp-content/plugins/reprint-exporter/push.php
```

The exact URL is shown on the **Reprint Exporter** settings screen after push
access is enabled. Give this URL, not `?reprint-api`, to `files-push`:

```bash
php reprint.phar files-push "https://example.com/wp-content/plugins/reprint-exporter/push.php" \
  --state-dir="/path/to/reprint-state" \
  --fs-root="/path/to/local-tree" \
  --secret="the-target-connection-token"
```

`push.php` loads Reprint but does not load WordPress, `wp-load.php`, themes, or
plugins. It reads the canonical document root from `DOCUMENT_ROOT`. Its
private reprint directory is a document-root-specific sibling:

```text
<parent-of-document-root>/.reprint-<first-12-sha256-characters>/.reprint/
```

The route excludes its own plugin directory from push. This keeps the push
route available even when the pushed tree would otherwise replace or delete it.

The connection token used to create new push sessions is stored in:

```text
<reprint-directory>/.reprint/push-config.php
```

The file has mode 0600 and returns an array with `connection_secret`. It is
outside the document root and prints nothing when PHP executes it. Enabling
push access writes this file. Disabling push access or rotating the token
removes it.

`push_create` authenticates with that connection secret and returns a random
secret scoped to the new push session. Upload, status, commit, and remove use
the session secret at the same URL. The target stores it in the session's
mode-0600 `push-metadata.php`; `files-push` stores its copy in the local pair's
mode-0600 `sender.json`. Neither secret is sent in a URL or request body.

Removing `push-config.php` blocks new sessions. It does not break a session
which already has its own secret, so an in-progress commit can still finish.

### Hosts which block the bundled push URL

A host which blocks direct PHP below `wp-content/plugins`, or which needs a
custom document root or reprint directory, must expose another PHP URL which
does not load WordPress. The host owns this route and its private configuration.
For example:

```php
<?php
require_once '/srv/reprint-exporter/vendor/autoload.php';

$connection_secret = null;
if (($_GET['endpoint'] ?? null) === 'push_create' && is_file('/srv/reprint/push-config.php')) {
    $connection_secret = Site_Export_HTTP_Server::load_push_connection_secret(
        '/srv/reprint/push-config.php'
    );
}

Site_Export_HTTP_Server::serve_push([
    'connection_secret' => $connection_secret,
    'docroot' => '/srv/www/public',
    'reprint_directory' => '/srv/reprint',
    'excluded_paths' => ['reprint-push.php'],
]);
```

The configuration file should return an array, produce no output, and be
readable only by the web-server account. Read it only for `push_create`;
created sessions authenticate from their own private metadata. The document
root, reprint directory, and excluded paths are server configuration, never
request parameters. Give this route's URL directly to `files-push`.

If the custom route is outside the document root, it does not need to exclude
itself. If it is inside, its document-root-relative directory must be in
`excluded_paths`.

## Push access

Connection tokens authorize downloads only by default, including tokens which
predate push support. A site administrator can grant the current token push
access from the plugin settings page. The grant stores a token fingerprint in
WordPress and the token itself in the private PHP configuration described
above. Rotating the token revokes the grant.

Hosts can manage push access before active plugins load with a boolean:

```php
define('SITE_EXPORT_PUSH_ENABLED', true);
```

The `SITE_EXPORT_PUSH_ENABLED` environment variable accepts the same values.
The constant wins when both are present. Managed `true` writes the current
token to the standalone configuration; managed `false` removes it. Managed
sites show push access as read-only in WordPress admin.

Disabling push prevents new session creation. Existing session secrets remain
valid so their work can reach a terminal result; this avoids stranding a
partially changed document root.

## Using the download route as a library

The pull engine can be embedded in another PHP project without the plugin's URL
check. Require `lib.php` instead of `index.php`; it declares functions but does
not handle a request by itself.

```php
// lib.php expects the WordPress path constants and plugin_dir_path().
define('ABSPATH', '/path/to/wordpress/');

require_once '/path/to/reprint-exporter-wp/lib.php';

if ($myRouter->matches('/export')) {
    _site_export_handle_api_request();
}
```

Supply an `authenticate` callback to `_site_export_handle_api_request()` to
replace its built-in pull authentication. Use
`Site_Export_HTTP_Server::serve_push()` for a standalone push route instead.

`lib.php` defines these constants:

- `SITE_EXPORT_VERSION` — plugin version string
- `SITE_EXPORT_PLUGIN_DIR` — absolute path to the plugin directory
- `SITE_EXPORT_SECRET_FILE` — optional PHP file overriding the stored HMAC shared secret
- `SITE_EXPORT_SECRET_OPTION` — WordPress option containing the shared secret
- `SITE_EXPORT_PUSH_AUTHORIZATION_OPTION` — WordPress option containing the token fingerprint granted push access
- `SITE_EXPORT_TIMESTAMP_TOLERANCE` — maximum request age in seconds
