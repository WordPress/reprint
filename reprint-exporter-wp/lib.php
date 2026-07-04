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

/** Sends a JSON error response and terminates. */
function _site_export_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'code' => $code]);
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
 * Server-side options for the staged artifact endpoints.
 *
 * The staging directory must live outside the web-served tree, and WordPress
 * has no guaranteed writable directory there, so the default sits under the
 * system temp dir, keyed by ABSPATH so co-hosted sites do not share staging
 * state. A host that cleans its temp dir only costs a transfer its progress —
 * artifacts re-upload from offset 0. Define SITE_EXPORT_STAGING_DIR to pick a
 * durable location instead.
 */
function _site_export_staged_options(): array {
    $staging_dir = defined('SITE_EXPORT_STAGING_DIR')
        ? SITE_EXPORT_STAGING_DIR
        : rtrim(sys_get_temp_dir(), '/') . '/reprint-staging-' . md5(ABSPATH);

    return [
        'staging_dir' => $staging_dir,
        'secret' => _site_export_get_shared_secret(),
        'timestamp_tolerance' => SITE_EXPORT_TIMESTAMP_TOLERANCE,
        // Where staged_apply moves verified artifacts. Apply is rename-only,
        // so the staging dir must sit on this root's filesystem — apply
        // rejects "cross_device" otherwise, and senders probe that with
        // check_only before uploading anything.
        'apply_target_root' => defined('SITE_EXPORT_APPLY_ROOT') ? SITE_EXPORT_APPLY_ROOT : ABSPATH,
    ];
}

/**
 * Data-plane endpoints authenticate inside their handlers.
 *
 * The default HMAC handler buffers php://input to hash it, which defeats the
 * staged upload handlers' spool-then-verify discipline. Keep this list next
 * to the public request handler so every large-body route makes an explicit
 * auth decision.
 */
function _site_export_endpoint_authenticates_in_handler(string $endpoint): bool {
    return in_array($endpoint, ['staged_upload', 'staged_upload_batch'], true);
}

/**
 * Handle an export API request.
 *
 * WordPress is already loaded at this point — DB credentials, $table_prefix,
 * and the database layer (including the SQLite db.php drop-in when present)
 * are all available.
 *
 * @param array $options Optional overrides:
 *   - 'authenticate' (callable): Called to authenticate the request.
 *        Defaults to _site_export_default_authenticate().
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
    // The class is loaded by the Composer autoloader on demand, but
    // load it eagerly in case the autoloader hasn't been required yet.
    if (!class_exists('Site_Export_HTTP_Server')) {
        _site_export_load_exporter_runtime();
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
        http_response_code(500);
        @header('Content-Type: application/json');
        echo json_encode($error);
        exit(1);
    });

    set_exception_handler(function ($e) {
        $error = [
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        error_log('Reprint Exporter API exception: ' . json_encode($error));
        http_response_code(500);
        @header('Content-Type: application/json');
        echo json_encode($error);
        exit(1);
    });

    // -- Authenticate --
    // Staged data-plane endpoints authenticate inside their handlers: the
    // default handler here buffers the whole request body to hash it, which
    // chunk and batch uploads cannot afford. Those routes verify the signed
    // headers first and hash the body as it streams. A custom authenticate
    // callable still runs for every endpoint — its embedder owns that tradeoff.
    // filter_input, not WP sanitizers: lib.php also runs without WordPress
    // bootstrapped (hosts that route the API from their own index.php).
    $endpoint = (string) filter_input(INPUT_GET, 'endpoint');
    $authenticate = $options['authenticate'] ?? null;
    if ($authenticate !== null) {
        $authenticate();
    } elseif (!_site_export_endpoint_authenticates_in_handler($endpoint)) {
        _site_export_default_authenticate();
    }

    // Ensure the Composer autoloader is loaded so Site_Export_HTTP_Server
    // is resolvable. The class itself will require export.php on demand
    // via serve() below.
    if (_site_export_load_exporter_runtime() === null) {
        _site_export_error(
            500,
            'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.'
        );
    }

    // -- Dispatch --
    try {
        Site_Export_HTTP_Server::serve([
            'default_directory' => ABSPATH,
            'staged' => _site_export_staged_options(),
        ]);
    } catch (Exception $e) {
        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
