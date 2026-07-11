<?php
/**
 * Reprint Exporter library – constants and function declarations, no request handling.
 *
 * Require this file to get access to the export API functions without
 * triggering any HTTP dispatch.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITE_EXPORT_VERSION')) {
    define('SITE_EXPORT_VERSION', '0.8.2-dev');
}
if (!defined('SITE_EXPORT_PLUGIN_DIR')) {
    define('SITE_EXPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('SITE_EXPORT_SECRET_FILE')) {
    define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
}
if (!defined('SITE_EXPORT_SECRET_OPTION')) {
    define('SITE_EXPORT_SECRET_OPTION', 'site_export_secret');
}

/**
 * Maximum age of a request timestamp in seconds.
 * Requests older than this are rejected to prevent replay attacks.
 */
define('SITE_EXPORT_TIMESTAMP_TOLERANCE', 300);

/**
 * Sends a JSON error response and terminates.
 *
 * @return never
 */
function _site_export_error(int $code, string $message, ?string $reason = null): void {
    // The API entry point clears pre-existing output buffers before starting
    // its request buffer. Drop everything buffered since then before emitting
    // the one JSON response callers can safely parse.
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['error' => $message, 'code' => $code];
    if ($reason !== null) {
        $response['status'] = 'rejected';
        $response['reason'] = $reason;
        $response['detail'] = $message;
    }
    echo json_encode($response);
    exit;
}

/**
 * Resolve and load the exporter package runtime.
 *
 * Supports both plugin release bundles (with reprint-exporter-wp/vendor/) and
 * the monorepo checkout (root vendor/ + vendor/wp-php-toolkit/reprint-exporter).
 *
 * @return string|null Absolute path to export.php, or null when the runtime is missing.
 */
function _site_export_load_exporter_runtime(): ?string {
    $repo_root = dirname(SITE_EXPORT_PLUGIN_DIR);
    $candidates = [
        [
            'autoload' => SITE_EXPORT_PLUGIN_DIR . 'vendor/autoload.php',
            'export' => SITE_EXPORT_PLUGIN_DIR . 'vendor/wp-php-toolkit/reprint-exporter/src/export.php',
        ],
        [
            'autoload' => $repo_root . '/vendor/autoload.php',
            'export' => $repo_root . '/vendor/wp-php-toolkit/reprint-exporter/src/export.php',
        ],
    ];

    foreach ($candidates as $candidate) {
        if (!file_exists($candidate['autoload']) || !file_exists($candidate['export'])) {
            continue;
        }

        require_once $candidate['autoload'];
        return $candidate['export'];
    }

    return null;
}

/** Returns whether the legacy secret.php override exists. */
function _site_export_has_secret_file(): bool {
    return file_exists(SITE_EXPORT_SECRET_FILE);
}

/**
 * Reads the legacy secret.php override when present.
 *
 * @return string|null String secret when the file is valid, otherwise null.
 */
function _site_export_get_file_secret(): ?string {
    if (!_site_export_has_secret_file()) {
        return null;
    }

    $secret = require SITE_EXPORT_SECRET_FILE;
    return is_string($secret) ? $secret : null;
}

/** Reads the option-backed shared secret. */
function _site_export_get_option_secret(): string {
    if (!function_exists('get_option')) {
        return '';
    }

    $secret = get_option(SITE_EXPORT_SECRET_OPTION, '');
    return is_string($secret) ? $secret : '';
}

/**
 * Returns the effective shared secret.
 *
 * The legacy secret.php file takes precedence when present; otherwise the
 * site option is used.
 */
function _site_export_get_shared_secret(): ?string {
    if (_site_export_has_secret_file()) {
        return _site_export_get_file_secret();
    }

    $secret = _site_export_get_option_secret();
    return $secret === '' ? null : $secret;
}

/**
 * Updates only the option-backed shared secret used by the settings UI and REST API.
 */
function _site_export_update_shared_secret(string $secret): bool {
    if (!function_exists('update_option')) {
        return false;
    }

    return (bool) update_option(SITE_EXPORT_SECRET_OPTION, $secret, false);
}

/**
 * Verify HMAC authentication.
 *
 * The signature covers a SHA-256 hash of the request body rather than
 * the raw bytes.  This sidesteps the problem that libcurl generates
 * multipart boundaries internally so the client can't predict the exact
 * byte stream — but it CAN hash the logical content before encoding.
 *
 * Signature = HMAC-SHA256(nonce + timestamp + SHA256(body), secret)
 *
 * The client sends X-Auth-Content-Hash = SHA256(body).  The server
 * independently hashes what it received and checks both that the hash
 * matches AND that the HMAC is valid.
 */
function _site_export_verify_hmac(string $secret): ?string {
    if (!class_exists('Site_Export_HMAC_Server')) {
        _site_export_load_exporter_runtime();
    }

    if (!class_exists('Site_Export_HMAC_Server')) {
        return 'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.';
    }

    $server = new Site_Export_HMAC_Server($secret, SITE_EXPORT_TIMESTAMP_TOLERANCE);
    return $server->verify_globals();
}

/**
 * Default HMAC authentication handler.
 *
 * Reads the shared secret from secret.php when present, otherwise from the
 * site option, and verifies the request's HMAC signature.
 * Calls _site_export_error() on failure.
 */
function _site_export_default_authenticate(): void {
    if (_site_export_has_secret_file()) {
        $secret = _site_export_get_file_secret();
        if (empty($secret)) {
            _site_export_error(503, 'Invalid secret.php configuration. Please remove it or replace it with a valid shared secret.');
        }
    } else {
        $secret = _site_export_get_option_secret();
    }

    if (empty($secret) || !is_string($secret)) {
        _site_export_error(503, 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Reprint Exporter.');
    }

    $auth_error = _site_export_verify_hmac($secret);
    if ($auth_error !== null) {
        _site_export_error(403, $auth_error);
    }
}

/**
 * Server-side options for direct staged-apply session endpoints.
 *
 * Push sessions need an administrator-owned staging directory, preferably
 * outside the web-served tree. WordPress has no guaranteed private writable
 * directory, so sessions are unavailable until SITE_EXPORT_STAGING_DIR
 * explicitly supplies one. Pull routes remain available without it. The
 * WordPress route also keeps live apply disabled until the standalone recovery
 * endpoint can advance a session without booting WordPress.
 */
function _site_export_staged_options(): ?array {
    if (!defined('SITE_EXPORT_STAGING_DIR')) {
        return null;
    }
    if (
        _site_export_staging_directory_error() !== null
        || _site_export_apply_target_error() !== null
    ) {
        return null;
    }
    $staging_dir = rtrim(SITE_EXPORT_STAGING_DIR, '/');

    $apply_target_root = defined('SITE_EXPORT_APPLY_ROOT')
        ? SITE_EXPORT_APPLY_ROOT
        : ABSPATH;
    $apply_target_root = rtrim($apply_target_root, '/');
    $apply_target_root = $apply_target_root === '' ? '/' : $apply_target_root;
    $protected_paths = [];

    // __DIR__ resolves a plugin-directory symlink to its target. Keep the
    // configured path lexical as well, so an apply cannot remove the symlink
    // WordPress uses to load this plugin.
    $plugin_directory_candidates = [rtrim(SITE_EXPORT_PLUGIN_DIR, '/')];
    $real_plugin_dir = realpath(__DIR__);
    $real_target_root = realpath($apply_target_root);

    // WordPress records plugin symlink mappings before it includes a plugin.
    // plugin_basename() turns the resolved __FILE__ back into that install
    // path; only accept a candidate under one of WordPress's two known plugin
    // roots when it resolves to this plugin's real directory.
    if (is_string($real_plugin_dir) && function_exists('plugin_basename')) {
        $plugin_relative_file = plugin_basename(__FILE__);
        $plugin_relative_directory = dirname($plugin_relative_file);
        $plugin_relative_segments = explode('/', $plugin_relative_directory);
        if (
            $plugin_relative_directory !== '.'
            && $plugin_relative_directory !== ''
            && $plugin_relative_directory[0] !== '/'
            && strpos($plugin_relative_directory, '\\') === false
            && !in_array('.', $plugin_relative_segments, true)
            && !in_array('..', $plugin_relative_segments, true)
        ) {
            $plugin_roots = [
                defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
                defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null,
            ];
            foreach ($plugin_roots as $plugin_root) {
                if (!is_string($plugin_root) || $plugin_root === '') {
                    continue;
                }
                $lexical_plugin_directory = rtrim($plugin_root, '/') . '/' . $plugin_relative_directory;
                if (
                    ( is_dir($lexical_plugin_directory) || is_link($lexical_plugin_directory) )
                    && realpath($lexical_plugin_directory) === $real_plugin_dir
                ) {
                    $plugin_directory_candidates[] = $lexical_plugin_directory;
                }
            }
        }
    }

    $lexical_target_root = rtrim($apply_target_root, '/');
    $lexical_target_prefix = $lexical_target_root === '' || $lexical_target_root === '/'
        ? '/'
        : $lexical_target_root . '/';
    foreach ($plugin_directory_candidates as $plugin_directory) {
        if (
            $plugin_directory === ''
            || strpos($plugin_directory, $lexical_target_prefix) !== 0
            || ( !is_dir($plugin_directory) && !is_link($plugin_directory) )
        ) {
            continue;
        }
        $protected_path = substr($plugin_directory, strlen($lexical_target_prefix));
        $protected_path_segments = explode('/', $protected_path);
        if (
            $protected_path !== ''
            && !in_array('', $protected_path_segments, true)
            && !in_array('.', $protected_path_segments, true)
            && !in_array('..', $protected_path_segments, true)
            && strpos($protected_path, '\\') === false
        ) {
            $protected_paths[] = $protected_path;
        }
    }

    // Keep protecting a normal in-tree installation even when WordPress did
    // not register a symlink mapping and SITE_EXPORT_PLUGIN_DIR is resolved.
    if (is_string($real_plugin_dir) && is_string($real_target_root)) {
        $target_prefix = rtrim($real_target_root, '/') . '/';
        if (strpos($real_plugin_dir, $target_prefix) === 0) {
            $protected_paths[] = substr($real_plugin_dir, strlen($target_prefix));
        }
    }
    $protected_paths = array_values(array_unique($protected_paths));

    return [
        'staging_dir' => $staging_dir,
        'secret' => _site_export_get_shared_secret(),
        'timestamp_tolerance' => SITE_EXPORT_TIMESTAMP_TOLERANCE,
        'apply_target_root' => $apply_target_root,
        'apply_protected_paths' => $protected_paths,
        // A commit can leave WordPress's maintenance marker behind or install
        // PHP that prevents the next WordPress boot. Do not expose live apply
        // through this boot-dependent route until the standalone recovery
        // endpoint can advance the same session without loading WordPress.
        'apply_sessions_enabled' => false,
    ];
}

/** Returns a pointed error for a present but unsafe staging directory. */
function _site_export_staging_directory_error(): ?string {
    if (!defined('SITE_EXPORT_STAGING_DIR')) {
        return null;
    }
    if (!class_exists('Site_Export_HTTP_Server')) {
        _site_export_load_exporter_runtime();
    }
    if (!class_exists('Site_Export_HTTP_Server')) {
        return 'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.';
    }
    try {
        Site_Export_HTTP_Server::normalize_storage_path(
            SITE_EXPORT_STAGING_DIR,
            'SITE_EXPORT_STAGING_DIR'
        );
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
    return null;
}

/** Returns a pointed error for an unsafe staged-apply target. */
function _site_export_apply_target_error(): ?string {
    $apply_target_root = defined('SITE_EXPORT_APPLY_ROOT')
        ? SITE_EXPORT_APPLY_ROOT
        : ABSPATH;
    if (!is_string($apply_target_root)) {
        return 'SITE_EXPORT_APPLY_ROOT must be a string; observed ' . gettype($apply_target_root) . '.';
    }
    if ($apply_target_root === '' || $apply_target_root[0] !== '/' || strpos($apply_target_root, "\0") !== false) {
        return 'SITE_EXPORT_APPLY_ROOT must be an absolute non-empty path without NUL bytes; observed base64 '
            . base64_encode($apply_target_root) . '.';
    }
    foreach (explode('/', $apply_target_root) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return 'SITE_EXPORT_APPLY_ROOT must not contain dot segments; observed base64 '
                . base64_encode($apply_target_root) . '.';
        }
    }
    $apply_target_root = rtrim($apply_target_root, '/');
    $apply_target_root = $apply_target_root === '' ? '/' : $apply_target_root;
    if (!is_dir($apply_target_root)) {
        return 'SITE_EXPORT_APPLY_ROOT must name an existing directory; observed base64 '
            . base64_encode($apply_target_root) . '.';
    }
    if (!is_string(realpath($apply_target_root))) {
        return 'SITE_EXPORT_APPLY_ROOT could not be resolved to a canonical directory; observed base64 '
            . base64_encode($apply_target_root) . '.';
    }
    return null;
}

/**
 * Handle an export API request.
 *
 * WordPress is already loaded at this point — DB credentials, $table_prefix,
 * and the database layer (including the SQLite db.php drop-in when present)
 * are all available.
 *
 * @param array $options Optional overrides:
 *   - 'authenticate' (callable): Runs first for every endpoint and replaces
 *        default whole-body HMAC on ordinary routes. The staged_session_*
 *        routes additionally require their shared-secret
 *        envelope HMAC.
 */
function _site_export_handle_api_request(array $options = []): void {
    // Revert WordPress error display settings (wp_debug_mode may
    // have enabled display_errors based on WP_DEBUG_DISPLAY).
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');

    // Clear any output buffering WordPress started.
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Emit CORS headers and short-circuit OPTIONS preflight before
    // authentication runs — browsers send preflight OPTIONS without
    // credentials, so we must not require auth before CORS passes.
    // Load Composer exactly once. clearstatcache() below can make the same
    // symlinked plugin path resolve under another spelling; requiring its
    // generated autoloader again would redeclare Composer's initializer.
    if (!class_exists('Site_Export_HTTP_Server')) {
        _site_export_load_exporter_runtime();
    }
    if (!class_exists('Site_Export_HTTP_Server')) {
        _site_export_error(
            500,
            'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.'
        );
    }
    Site_Export_HTTP_Server::handle_cors_headers_and_terminate_on_options('*');

    // Buffer output so stray warnings don't corrupt the JSON response.
    ob_start();

    // Clear PHP's stat and realpath caches to ensure fresh filesystem state.
    // PHP-FPM workers cache realpath() results for 120 seconds across requests.
    // If the same worker handles both an initial file_index scan and a delta scan
    // within that window, stale cached paths can cause wrong type information
    // (e.g., a symlink that was replaced by a directory still resolves as the
    // old symlink target). This is cheap and prevents non-deterministic failures.
    clearstatcache(true);

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        $error = [
            'error' => "PHP Error: $errstr",
            'file' => $errfile,
            'line' => $errline,
            'type' => $errno,
        ];
        error_log('Reprint Exporter API error: ' . json_encode($error));
        _site_export_error(500, 'The Reprint Exporter encountered an internal error.');
    });

    set_exception_handler(function ($e) {
        $error = [
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        error_log('Reprint Exporter API exception: ' . json_encode($error));
        _site_export_error(500, 'The Reprint Exporter encountered an internal error.');
    });

    // -- Authenticate --
    // Chunk uploads cannot afford the default handler's whole-body hash, and
    // every session route needs envelope HMAC to bind its session id and
    // generation in the exact request target. The retired staged_push route
    // also takes this path so an older sender's large body can be rejected
    // without buffering it. Verify the envelope before reading server
    // configuration; the HTTP route verifies current session routes again for
    // direct HTTP-server users. TLS protects streamed bytes. A custom
    // authenticate callable still runs first for every endpoint as an
    // additional host-owned policy; it never replaces the staged envelope.
    // filter_input, not WP sanitizers: lib.php also runs without WordPress
    // bootstrapped (hosts that route the API from their own index.php).
    $endpoint = (string) filter_input(INPUT_GET, 'endpoint');
    $authenticate = $options['authenticate'] ?? null;
    $is_staged_session_endpoint = Site_Export_HTTP_Server::is_staged_session_endpoint($endpoint);
    $is_retired_staged_push_endpoint = $endpoint === 'staged_push';
    if ($authenticate !== null) {
        // Embedders use this hook for policy as well as authentication, so it
        // must run for every endpoint before any built-in rejection exits.
        $authenticate();
    }

    if ($is_staged_session_endpoint || $is_retired_staged_push_endpoint) {
        $secret = _site_export_get_shared_secret();
        if ($secret === null) {
            _site_export_error(
                503,
                'Staged session authentication is unavailable because no shared secret is configured.',
                'not_configured'
            );
        }
        // HMAC must verify the exact request target and method, not sanitized
        // values that could differ from the bytes the client signed.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitizing would change the signed bytes.
        $request_target = $_SERVER['REQUEST_URI'] ?? null;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- HMAC must verify the exact HTTP method.
        $request_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        if (!is_string($request_target) || $request_target === '') {
            _site_export_error(403, 'Authentication failed.', 'auth_failed');
        }
        $auth_error = ( new Site_Export_HMAC_Server($secret, SITE_EXPORT_TIMESTAMP_TOLERANCE) )->verify_envelope(
            $_SERVER,
            $request_method,
            $request_target
        );
        if ($auth_error !== null) {
            _site_export_error(403, 'Authentication failed.', 'auth_failed');
        }
    } elseif ($authenticate === null) {
        _site_export_default_authenticate();
    }

    if ($is_retired_staged_push_endpoint) {
        _site_export_error(
            410,
            'The staged_push endpoint was removed. Use staged_session_create and staged_session_push.',
            'endpoint_retired'
        );
    }

    // -- Dispatch --
    try {
        $staging_configuration_error = _site_export_staging_directory_error();
        $apply_target_configuration_error = defined('SITE_EXPORT_STAGING_DIR')
            && $staging_configuration_error === null
            ? _site_export_apply_target_error()
            : null;
        $staged_options = _site_export_staged_options();
        if (
            $staged_options === null
            && ( $is_staged_session_endpoint || $staging_configuration_error !== null )
        ) {
            if ($staging_configuration_error !== null) {
                _site_export_error(503, $staging_configuration_error, 'apply_storage_invalid');
            }
            if ($apply_target_configuration_error !== null) {
                _site_export_error(503, $apply_target_configuration_error, 'apply_target_invalid');
            }
            if ($is_staged_session_endpoint) {
                _site_export_error(
                    503,
                    'Push requires SITE_EXPORT_STAGING_DIR to name an explicitly managed private staging directory.',
                    'apply_storage_not_configured'
                );
            }
        }
        $server_options = ['default_directory' => ABSPATH];
        if ($staged_options !== null) {
            $server_options['staged'] = $staged_options;
        } elseif (defined('SITE_EXPORT_STAGING_DIR') && $staging_configuration_error === null) {
            // A bad apply target must not make pull indexes expose otherwise
            // valid server-owned staging data.
            $server_options['storage_path'] = rtrim(SITE_EXPORT_STAGING_DIR, '/');
        }
        Site_Export_HTTP_Server::serve($server_options);
    } catch (InvalidArgumentException $e) {
        // Dispatcher validation messages are the API's actionable response to
        // authenticated caller input. Return the message, but never its trace.
        _site_export_error(400, $e->getMessage());
    } catch (Exception $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Details stay in the server log, never the response.
        error_log('Reprint Exporter API request exception: ' . $e);
        _site_export_error(500, 'The Reprint Exporter encountered an internal error.');
    }
}
