# Reprint Server — WordPress Plugin

When working from this monorepo checkout, run `composer install` in
`reprint-server-wp/` to populate the bundled `vendor/` directory used by the
plugin runtime. GitHub release ZIPs already include that vendor tree.

The maintained source keeps its PHP 7.2 type declarations. The release build
copies that source to a temporary directory and removes unsupported syntax from
the copy. The resulting plugin ZIP supports pull endpoints on PHP 5.6.20 or
newer. Push endpoints require PHP 7.2 or newer.

## API Routing

Many shared hosts (SiteGround, GoDaddy, etc.) block direct PHP execution inside `wp-content/plugins/` at the web server level, returning a 403 before the request ever reaches PHP. To work around this, export API requests are routed through WordPress's front controller (`index.php` at the site root), which hosts never block.

### How it works

The plugin file (`index.php`) is `include`'d by WordPress during its plugin loading loop — this happens *before* the `plugins_loaded` hook fires, making it the earliest interception point available to a regular plugin.

When a request arrives at `https://example.com/?reprint-api`, the plugin:

1. Detects `$_GET['reprint-api']` during plugin file load
2. Reverts WordPress error display settings (`display_errors`, `html_errors`) that `wp_debug_mode()` may have turned on
3. Clears any output buffering WordPress started
4. Sets up error handlers, authentication through `RequestAuthenticator`, and runs the export endpoint
5. Calls `exit` — WordPress never finishes booting

This gives us a clean execution environment while using WordPress's front controller as the entry point.

### Platform configuration

The bundled WordPress entry point passes the result of the
`reprint_server_api_options` filter to the request handler. A platform must
register this filter before the regular Reprint Server plugin file loads;
registering it on `plugins_loaded` is too late. A must-use plugin is the usual
place to register it:

```php
add_filter('reprint_server_api_options', static function (array $options): array {
    $options['docroot'] = '/srv/www/public';
    $options['reprint_directory'] = '/srv/www/.reprint';
    return $options;
});
```

The supported options are:

- `authenticate` — a callback that authenticates every non-preflight API
  request instead of the built-in `RequestAuthenticator`. For a push request, this
  callback must authenticate from request metadata without reading or
  buffering `php://input`; the endpoint streams that body after authentication.
- `docroot` — the document root for push. It must resolve to an
  existing directory and defaults to `$_SERVER['DOCUMENT_ROOT']`.
- `reprint_directory` — the private push storage directory outside `docroot`.
  It defaults to a document-root-specific sibling directory.
- `excluded_paths` — document-root-relative paths that push must preserve. The
  Reprint Server plugin directory is also preserved automatically when it is
  below `docroot`.
- `maximum_part_bytes` — the maximum `Content-Length` accepted for one push
  upload part. It defaults to 4 MiB.
- `maximum_commit_entries` — the maximum number of bounded entries processed
  by one `push_commit` request. It defaults to 256.

## Authentication

The host decides the scheme; no option, constant, or environment variable
selects it. `Utils::key_auth_required()` returns whether `openssl_verify`
exists. Where it does, `RequestAuthenticator` accepts only signatures made with
an enrolled public key and answers a connection token with `requires_key_auth`.
Where it does not, the authenticator accepts only the connection token and
answers a key signature with `requires_token_auth`. A site on a host with
OpenSSL that has no enrolled key answers every request with `no_keys_enrolled`
(HTTP 503). A stored connection token is kept but not accepted there, and the
settings page says so. A platform that pre-packages `secret.php` ships
`public-keys.php` to hosts with OpenSSL instead, or its sites answer
`no_keys_enrolled` from the moment they update. The Remove button appears for an option-stored token
only; a `secret.php` token is named and must be removed from disk, since the
page cannot delete that file. `HMACServer` itself refuses on a
host with OpenSSL, so embedders that call it directly must move to
`RequestAuthenticator`.

Every authentication error body carries `auth_version`. Embedders that write their own authentication error JSON must include `"auth_version": 2`, or current token clients report the plugin as outdated. A request with `X-Auth-Content-Hash`, which every released token client sends, is refused with `client_update_required` (HTTP 403).

### Public keys

The settings page has an enrollment form which takes a PEM or one-line public
key, and a table of enrolled keys with each key's id, the date it was added,
and a per-key push grant. Enrolled keys live in the `reprint_server_public_keys`
option (the network option on multisite), which is never exposed through REST.
A `public-keys.php` file beside the plugin overrides the option, the same
precedence `secret.php` has for the token: the file returns a list of PEM or
one-line public keys, it is the only key source while it exists, and the page
shows its keys read-only and refuses enrollment. Removing a key from the table
revokes its push grant with it. Keys from `public-keys.php` carry no grant, so
push to such a site needs the managed policy below.

## Push access

Connection tokens and enrolled keys authorize downloads only by default. This
also applies to credentials that already existed when the plugin was upgraded;
no migration enables push access. A site administrator grants push access from
the plugin settings page: per key in the enrolled-key table, or for the
connection token on a host without OpenSSL. The token grant stores a
fingerprint of the current connection token, so rotating that token revokes the
grant and requires fresh consent.

Hosts can manage push access before active plugins load with an immutable boolean:

```php
define('WordPress\\Reprint\\Server\\Plugin\\PUSH_ENABLED', true);
```

The `REPRINT_SERVER_PUSH_ENABLED` environment variable and global constant
accept the same boolean policy. The namespaced constant wins when more than one
surface is present. `true` enables push without a
local grant; `false` hard-disables push even when a local grant exists. The sole
recovery exception lets an authenticated caller finish a commit which already
has a durable checkpoint, so revocation cannot strand a partially changed
document root. It cannot start commit or use any other push operation until
push is authorized again. Managed sites show the effective state as read-only
in WordPress admin. Custom authentication does not bypass this authorization
gate.

The bundled settings page is available at **Tools > Reprint Server** (the
network settings page on multisite). It uses the WordPress Settings API for the
connection token and separate authenticated administrator actions for key
enrollment, key removal, and push access. The page is an adapter over the shared
configuration functions; it does not own the token, key, or push-authorization
rules.

## Administrator page

For example, a tool prints a public key and asks you to authorize it. Paste that
key into **Authorize a tool to copy this site** and choose **Authorize tool**.
The form becomes a confirmation telling you to return to your tool to start or
resume the copy. This page saves access. It does not start a transfer, show
transfer progress, or confirm that a tool connected.

The public-key form stays visible on normal visits, even when other keys are
already saved. An existing key does not tell this page whether your current
tool has access. **I don’t have a public key yet** gives the steps to get one,
with Reprint CLI help inside it. The remote Reprint API URL stays below the
form. Existing keys, push permissions, and unused credentials stay in the
closed **Manage tool access** section. Results from those controls reopen it.
There is no separate server-details section or routine status badge.

| Visit or host state | Next action on the page |
| --- | --- |
| The tool already printed a key | Paste that public key and choose **Authorize tool**. |
| No key printed yet | Open **I don’t have a public key yet**. Copy the API URL into the tool, then paste the key it gives you. The CLI help shows how to generate one. |
| Other keys are saved; another tool needs access | Paste its public key into the same visible form. After authorization, return to the tool. |
| A key should stop working | Open **Manage tool access**, match its key id to the tool, and choose **Remove key**. |
| A tool must change files on this site | Open **Manage tool access**, check **Allow push** for its key, and choose **Save push access**. Checking alone changes nothing. Token hosts have their push controls here too. |
| OpenSSL is missing | Save a connection token and use the same token in the tool. Key enrollment is not offered. Changing a saved token disconnects tools using the old token and revokes its push access. |
| A token remains after moving to an OpenSSL host | **Manage tool access** explains that it is unused and offers removal for an option-stored token. |
| `public-keys.php` supplies keys | Edit that file. The page lists its keys without enrollment or removal forms. |
| `secret.php` supplies a token on a token host | Use the file's token. Editing the stored option does not replace the file. |
| The hosting provider controls push access | Read the effective permission. Per-key push forms are not offered. The authorization confirmation uses that permission too. |
| A multisite network is being copied | Choose a site's home URL. Network credentials can pull any site; push controls are not offered. Only network administrators can manage access. |
| A form is rejected | Read the action result. The authorization form remains visible after an enrollment rejection. |

Notices report form results and errors. Successful key authorization appears
in the main card instead of another notice. Public keys and connection tokens
are never offered as competing choices: the host determines which one works.
The forms and disclosure sections work without JavaScript; JavaScript adds
token generation, token visibility, and URL copying with visible success or
manual-copy guidance. WordPress and other plugins' notices are not suppressed.

## Uninstalling

Deleting Reprint Server through WordPress removes its stored connection token,
enrolled public keys, push authorization, and activation redirect transient,
including the legacy `site_export_*` settings. On multisite, it cleans these
settings on every site and removes the connection token and enrolled keys from
every network. Other plugins' settings are left alone. Deactivation keeps
Reprint's settings.

Migration integrations must run WordPress's uninstall routine while the plugin
files are still present. Removing the directory directly does not run cleanup.
WordPress deletes files inside the plugin directory, including `secret.php`
and `public-keys.php`, when deleting the plugin. Host-configured token files and private transfer
directories outside that directory are not removed by this uninstall routine.

## Using as a library

The export engine can be embedded in another PHP project or WordPress plugin
without the bundled plugin wrapper. Require `lib.php` instead of `index.php`.
It defines constants and functions but does not handle requests, check URLs,
register WordPress hooks, add administrator pages, or install activation hooks.

```php
use function WordPress\Reprint\Server\Plugin\error;
use function WordPress\Reprint\Server\Plugin\handle_api_request;

// Your project must define ABSPATH before requiring lib.php.
define('ABSPATH', '/path/to/wordpress/');

require_once '/path/to/reprint-server-wp/lib.php';

// Route however you like — lib.php doesn't check URLs.
if ($myRouter->matches('/export')) {
    // Use the default authentication: key signatures on a host with openssl_verify,
    // connection tokens elsewhere. Keys come from public-keys.php when present,
    // otherwise the public-keys option; the token from secret.php when present,
    // otherwise the connection-token option.
    handle_api_request();

    // Or supply your own authentication:
    handle_api_request([
        'authenticate' => function () {
            if (!my_auth_check()) {
                error(403, 'Unauthorized');
            }
        },
    ]);
}
```

`WordPress\Reprint\Server\Plugin\handle_api_request()` accepts the same options documented under
Platform configuration. Direct `lib.php` embedders pass them as the function's
array argument and do not use the WordPress filter.

An embedding WordPress plugin which wants the option-backed connection token
and its push-authorization revocation hooks may opt into that integration
without loading the bundled administrator:

```php
use function WordPress\Reprint\Server\Plugin\register_wordpress_configuration;

require_once '/path/to/reprint-server-wp/lib.php';
require_once '/path/to/reprint-server-wp/wordpress/configuration.php';

register_wordpress_configuration();
```

The embedding plugin may use the namespaced `get_configuration_state()`,
`change_connection_token()`, `change_push_access()`, `enroll_public_key()`,
`remove_public_key()`, and `change_key_push_access()` operations to render and
process its own administrator surface. It should not require
`wordpress/reprint-server.php` unless it explicitly wants the bundled
**Tools > Reprint Server** page.

Reading the host rule and identifying keys needs the server runtime, which the
API path loads only while answering a request. `get_configuration_state()` and
the key operations therefore call `require_server_runtime()` themselves, which
looks for the Composer autoloader in `vendor/` beside the plugin or at the
repository root. An embedder that ships the `wp-php-toolkit/reprint-server`
package elsewhere must make `WordPress\Reprint\Server\Utils` autoloadable
before calling them: otherwise the key operations return `runtime_missing`,
and the configuration state lists no enrolled keys.

`lib.php` defines these constants in `WordPress\Reprint\Server\Plugin`
(using WordPress's `plugin_dir_path`):

- `VERSION` — plugin version string
- `PLUGIN_DIR` — absolute path to the plugin directory
- `CONNECTION_TOKEN_FILE` — optional path to a PHP file that overrides the stored connection token
- `CONNECTION_TOKEN_OPTION` — WordPress site option name used for the stored connection token
- `PUBLIC_KEYS_FILE` — optional path to a PHP file returning the enrolled public keys; it replaces the option while it exists
- `PUBLIC_KEYS_OPTION` — WordPress site option name (network option on multisite) holding the enrolled public keys and their push grants
- `PUSH_AUTHORIZATION_OPTION` — WordPress site option containing the connection-token fingerprint granted personal push access
- `TIMESTAMP_TOLERANCE` — max request age in seconds (default 300)

For compatibility, the plugin still accepts `?site-export-api`, applies the
legacy `site_export_api_options` filter before the canonical filter, migrates
the old `site_export_*` options, recognizes `SITE_EXPORT_PUSH_ENABLED`,
and exposes the released `_site_export_*()` functions and `SITE_EXPORT_*`
constants. New integrations should use the Reprint Server names. `compat.php`
owns the compatibility names; canonical request and library code use only the
Reprint Server runtime API.

Sites that stored their connection token under the earlier option names keep
it: `compat.php` copies `site_export_secret` and
`site_export_push_authorized_token_fingerprint` into the option names above
when those do not exist yet, then deletes the legacy options. The copy runs
while `lib.php` loads, which is early enough for the endpoint to authenticate
on the first request after the update — that request exits before any hook
fires — and early enough that the settings page has not yet registered its
option listeners, so moving the token does not read as a rotation and does not
revoke push authorization. Once no site carries the legacy options,
`compat.php` can be deleted outright.
