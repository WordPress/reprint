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
    define('SITE_EXPORT_VERSION', '0.9.2-dev');
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
if (!defined('SITE_EXPORT_PUSH_AUTHORIZATION_OPTION')) {
    define('SITE_EXPORT_PUSH_AUTHORIZATION_OPTION', 'site_export_push_authorized_token_fingerprint');
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
 * Sends one classified push-protocol failure and terminates the request.
 *
 * Failures raised before an endpoint method can format its own response use
 * the push response discriminator here instead of the legacy export error
 * object.
 *
 * The response contains `status` (`rejected`), `reason`, and `detail`.
 *
 * @param int $http_code HTTP status code.
 * @param string $reason Machine-readable push failure reason.
 * @param string $detail Human-readable violated condition.
 */
function _site_export_push_error(int $http_code, string $reason, string $detail): void {
    http_response_code($http_code);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'rejected',
        'reason' => $reason,
        'detail' => $detail,
    ]);
    exit;
}

/**
 * Returns whether an endpoint uses the push authentication, authorization,
 * and error contract.
 *
 * @param string $endpoint Exact endpoint query value.
 * @return bool Whether this is in the push endpoint namespace.
 */
function _site_export_is_push_endpoint(string $endpoint): bool {
    return strpos($endpoint, 'push_') === 0;
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

/** Returns the private PHP configuration read by the bundled push route. */
function _site_export_get_push_config_file(): ?string {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted server configuration and must retain exact filesystem bytes.
    $configured_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    if (!is_string($configured_docroot) || $configured_docroot === '') {
        return null;
    }
    $docroot = realpath($configured_docroot);
    if ($docroot === false || !is_dir($docroot)) {
        return null;
    }
    $docroot = $docroot === '/' ? '/' : rtrim($docroot, '/\\');
    if (!class_exists('Site_Export_HTTP_Server')) {
        _site_export_load_exporter_runtime();
    }
    if (!class_exists('Site_Export_HTTP_Server')) {
        return null;
    }
    return Site_Export_HTTP_Server::default_reprint_directory($docroot)
        . '/.reprint/push-config.php';
}

/** Reads the connection secret granted to the standalone push route. */
function _site_export_get_push_config_secret(): ?string {
    $configuration_path = _site_export_get_push_config_file();
    if ($configuration_path === null) {
        return null;
    }
    return Site_Export_HTTP_Server::load_push_connection_secret($configuration_path);
}

/** Atomically grants the bundled push route access with the current token. */
function _site_export_write_push_config(string $connection_secret): bool {
    if ($connection_secret === '') {
        return false;
    }
    $configuration_path = _site_export_get_push_config_file();
    if ($configuration_path === null) {
        return false;
    }
    $configuration_directory = dirname($configuration_path);
    if (
        !is_dir($configuration_directory)
        && !@mkdir($configuration_directory, 0700, true)
        && !is_dir($configuration_directory)
    ) {
        return false;
    }
    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $throwable) {
        return false;
    }
    $temporary_path = $configuration_path . '.' . $suffix . '.tmp';
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The private configuration needs a PHP array literal.
    $contents = "<?php\n\nreturn " . var_export([
        'connection_secret' => $connection_secret,
    ], true) . ";\n";
    if (@file_put_contents($temporary_path, '') !== 0) {
        return false;
    }
    if (!@chmod($temporary_path, 0600)) {
        @unlink($temporary_path);
        return false;
    }
    if (@file_put_contents($temporary_path, $contents) !== strlen($contents)) {
        @unlink($temporary_path);
        return false;
    }
    if (!@rename($temporary_path, $configuration_path)) {
        @unlink($temporary_path);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($configuration_path, true);
    }
    return true;
}

/** Revokes new push-session creation through the bundled push route. */
function _site_export_remove_push_config(): bool {
    $configuration_path = _site_export_get_push_config_file();
    if ($configuration_path === null) {
        return false;
    }
    clearstatcache(true, $configuration_path);
    if (!file_exists($configuration_path) && !is_link($configuration_path)) {
        return true;
    }
    return @unlink($configuration_path);
}

/**
 * Returns the hosting provider's push policy, or null when the site controls it.
 *
 * An early boolean SITE_EXPORT_PUSH_ENABLED constant takes precedence over the
 * environment variable of the same name. Any unrecognized value fails closed.
 */
function _site_export_get_managed_push_enabled(): ?bool {
    if (defined('SITE_EXPORT_PUSH_ENABLED')) {
        return SITE_EXPORT_PUSH_ENABLED === true;
    }

    $environment_value = getenv('SITE_EXPORT_PUSH_ENABLED');
    if ($environment_value === false) {
        return null;
    }

    $enabled = filter_var($environment_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $enabled === true;
}

/** Returns whether the current connection token is authorized for push. */
function _site_export_is_push_authorized(): bool {
    return _site_export_get_push_authorization_error() === null;
}

/** Returns the exact push authorization failure, or null when push may start new work. */
function _site_export_get_push_authorization_error(): ?string {
    $managed_enabled = _site_export_get_managed_push_enabled();
    if ($managed_enabled !== null) {
        if (!$managed_enabled) {
            return 'Push access is disabled by the hosting provider through SITE_EXPORT_PUSH_ENABLED.';
        }
        $secret = _site_export_get_shared_secret();
        if (
            $secret === null
            || !hash_equals($secret, _site_export_get_push_config_secret() ?? '')
        ) {
            return 'Push access is enabled by the hosting provider, but the standalone push route is not configured for the current connection token.';
        }
        return null;
    }

    $secret = _site_export_get_shared_secret();
    if ($secret === null || !function_exists('get_option')) {
        return 'Push access is disabled for the current connection token.';
    }

    $authorized_fingerprint = get_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, '');
    $authorized = is_string($authorized_fingerprint)
        && $authorized_fingerprint !== ''
        && hash_equals(hash('sha256', $secret), $authorized_fingerprint)
        && hash_equals($secret, _site_export_get_push_config_secret() ?? '');
    return $authorized ? null : 'Push access is disabled for the current connection token.';
}

/** Copies managed push policy into the configuration read without WordPress. */
function _site_export_sync_managed_push_config(): bool {
    $managed_enabled = _site_export_get_managed_push_enabled();
    if ($managed_enabled === null) {
        return true;
    }
    if (!$managed_enabled) {
        return _site_export_remove_push_config();
    }
    $secret = _site_export_get_shared_secret();
    if ($secret === null) {
        return false;
    }
    $configured_secret = _site_export_get_push_config_secret();
    return (
        is_string($configured_secret)
        && hash_equals($secret, $configured_secret)
    ) || _site_export_write_push_config($secret);
}

/**
 * Grants or revokes personal push authorization for the current token.
 *
 * The stored fingerprint is the only local authorization state. A different
 * current token therefore cannot inherit the prior token's write authority.
 */
function _site_export_update_push_authorization(bool $enabled): bool {
    if (!function_exists('update_option')) {
        return false;
    }

    $secret = _site_export_get_shared_secret();
    if ($enabled && $secret === null) {
        return false;
    }

    $fingerprint = $enabled ? hash('sha256', $secret) : '';
    if ($enabled) {
        if (!_site_export_write_push_config($secret)) {
            return false;
        }
    } elseif (!_site_export_remove_push_config()) {
        return false;
    }
    if (function_exists('get_option') && get_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, '') === $fingerprint) {
        return true;
    }
    if ( (bool) update_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, $fingerprint, false) ) {
        return true;
    }
    if ($enabled) {
        _site_export_remove_push_config();
    }
    return false;
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
 * Handle an export API request.
 *
 * WordPress is already loaded at this point — DB credentials, $table_prefix,
 * and the database layer (including the SQLite db.php drop-in when present)
 * are all available.
 *
 * The bundled plugin passes the `site_export_api_options` filter result here.
 * A direct library embedder supplies the same trusted options array itself.
 *
 * @param array $options {
 *     Optional endpoint configuration overrides.
 *
 *     @type callable $authenticate Optional. Authenticates the request.
 *                                  Defaults to _site_export_default_authenticate().
 * }
 * @phpstan-param array{authenticate?:callable} $options
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
    $endpoint = (string) filter_input(INPUT_GET, 'endpoint');
    if (_site_export_is_push_endpoint($endpoint)) {
        _site_export_push_error(
            404,
            'invalid_request',
            'Push requests must use the standalone push URL shown on the Reprint Exporter settings screen.'
        );
    }
    $authenticate = $options['authenticate'] ?? null;
    if ($authenticate !== null) {
        $authenticate();
    } else {
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
        $server_options = ['default_directory' => ABSPATH];
        Site_Export_HTTP_Server::serve($server_options);
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
