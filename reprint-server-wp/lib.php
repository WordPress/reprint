<?php

namespace WordPress\Reprint\Server\Plugin;

/**
 * Reprint Server library – constants and function declarations, no request handling.
 *
 * Require this file to get access to the Reprint Server API functions without
 * triggering any HTTP dispatch.
 */

use Exception;
use InvalidArgumentException;
use WordPress\Reprint\Server\HMACServer;
use WordPress\Reprint\Server\HTTPServer;
use WordPress\Reprint\Server\PushConfigurationException;

use function WordPress\Reprint\Server\relative_path_under;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/compat.php';
\reprint_server_bootstrap_compatibility(false);

if (!defined(__NAMESPACE__ . '\\VERSION')) {
    define(__NAMESPACE__ . '\\VERSION', '0.10.7-dev');
}
if (!defined(__NAMESPACE__ . '\\PLUGIN_DIR')) {
    define(__NAMESPACE__ . '\\PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined(__NAMESPACE__ . '\\SECRET_FILE')) {
    define(__NAMESPACE__ . '\\SECRET_FILE', PLUGIN_DIR . 'secret.php');
}
if (!defined(__NAMESPACE__ . '\\SECRET_OPTION')) {
    define(__NAMESPACE__ . '\\SECRET_OPTION', 'reprint_server_secret');
}
if (!defined(__NAMESPACE__ . '\\PUSH_AUTHORIZATION_OPTION')) {
    define(__NAMESPACE__ . '\\PUSH_AUTHORIZATION_OPTION', 'reprint_server_push_authorized_token_fingerprint');
}

/**
 * Maximum age of a request timestamp in seconds.
 * Requests older than this are rejected to prevent replay attacks.
 */
if (!defined(__NAMESPACE__ . '\\TIMESTAMP_TOLERANCE')) {
    define(__NAMESPACE__ . '\\TIMESTAMP_TOLERANCE', 300);
}

/** Sends a JSON error response and terminates. */
function error(int $code, string $message): void {
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
function push_error(int $http_code, string $reason, string $detail): void {
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
function is_push_endpoint(string $endpoint): bool {
    return strpos($endpoint, 'push_') === 0;
}

/** Returns whether this PHP runtime can serve push endpoints. */
function push_is_supported(): bool {
    return PHP_VERSION_ID >= 70200;
}

/** Resolves dot segments in a slash-delimited path without touching the filesystem. */
function _site_export_normalize_path(string $path): string {
    $parts = explode('/', $path);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($resolved);
        } else {
            $resolved[] = $part;
        }
    }
    return '/' . implode('/', $resolved);
}

/**
 * Resolve and load the server package runtime.
 *
 * Supports both plugin release bundles (with reprint-server-wp/vendor/) and
 * the monorepo checkout (root vendor/ + vendor/wp-php-toolkit/reprint-server).
 *
 * @return string|null Absolute path to export.php, or null when the runtime is missing.
 */
function load_server_runtime(): ?string {
    static $loaded_export_path = null;

    if ($loaded_export_path !== null) {
        return $loaded_export_path;
    }

    $repo_root = dirname(PLUGIN_DIR);
    $candidates = [
        [
            'autoload' => PLUGIN_DIR . 'vendor/autoload.php',
            'compat' => PLUGIN_DIR . 'vendor/wp-php-toolkit/reprint-server/src/compat.php',
            'export' => PLUGIN_DIR . 'vendor/wp-php-toolkit/reprint-server/src/export.php',
        ],
        [
            'autoload' => $repo_root . '/vendor/autoload.php',
            'compat' => $repo_root . '/vendor/wp-php-toolkit/reprint-server/src/compat.php',
            'export' => $repo_root . '/vendor/wp-php-toolkit/reprint-server/src/export.php',
        ],
    ];

    foreach ($candidates as $candidate) {
        if (
            !file_exists($candidate['autoload'])
            || !file_exists($candidate['compat'])
            || !file_exists($candidate['export'])
        ) {
            continue;
        }

        $autoload_path = realpath($candidate['autoload']);
        $compat_path = realpath($candidate['compat']);
        $export_path = realpath($candidate['export']);
        if ($autoload_path === false || $compat_path === false || $export_path === false) {
            continue;
        }

        require_once $autoload_path;
        require_once $compat_path;
        $loaded_export_path = $export_path;
        return $export_path;
    }

    return null;
}

/** Returns whether the legacy secret.php override exists. */
function has_secret_file(): bool {
    return file_exists(SECRET_FILE);
}

/**
 * Reads the legacy secret.php override when present.
 *
 * @return string|null String secret when the file is valid, otherwise null.
 */
function get_file_secret(): ?string {
    if (!has_secret_file()) {
        return null;
    }

    $secret = require SECRET_FILE;
    return is_string($secret) ? $secret : null;
}

/** Reads the option-backed shared secret. */
function get_option_secret(): string {
    if (!function_exists('get_option')) {
        return '';
    }

    $secret = get_option(SECRET_OPTION, '');
    return is_string($secret) ? $secret : '';
}

/**
 * Returns the effective shared secret.
 *
 * The legacy secret.php file takes precedence when present; otherwise the
 * site option is used.
 */
function get_shared_secret(): ?string {
    if (has_secret_file()) {
        return get_file_secret();
    }

    $secret = get_option_secret();
    return $secret === '' ? null : $secret;
}

/**
 * Updates only the option-backed shared secret used by the settings UI and REST API.
 */
function update_shared_secret(string $secret): bool {
    if (!function_exists('update_option')) {
        return false;
    }

    return (bool) update_option(SECRET_OPTION, $secret, false);
}

/**
 * Returns the hosting provider's push policy, or null when the site controls it.
 *
 * A canonical constant takes precedence over global configuration. Any
 * unrecognized environment value fails closed.
 */
function get_managed_push_enabled(): ?bool {
    if (defined(__NAMESPACE__ . '\\PUSH_ENABLED')) {
        return constant(__NAMESPACE__ . '\\PUSH_ENABLED') === true;
    }
    if (defined('REPRINT_SERVER_PUSH_ENABLED')) {
        return constant('REPRINT_SERVER_PUSH_ENABLED') === true;
    }
    $environment_value = getenv('REPRINT_SERVER_PUSH_ENABLED');
    if ($environment_value !== false) {
        $enabled = filter_var($environment_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $enabled === true;
    }

    if (function_exists('apply_filters')) {
        /**
         * Filters the managed push policy when canonical configuration is absent.
         *
         * @param bool|null $enabled Whether push is managed and enabled, or null when site-controlled.
         */
        $enabled = apply_filters('reprint_server_managed_push_enabled', null);
        return $enabled === true ? true : ( $enabled === null ? null : false );
    }
    return null;
}

/** Returns whether the current connection token is authorized for push. */
function is_push_authorized(): bool {
    return get_push_authorization_error() === null;
}

/** Returns the exact push authorization failure, or null when push may start new work. */
function get_push_authorization_error(): ?string {
    $managed_enabled = get_managed_push_enabled();
    if ($managed_enabled !== null) {
        return $managed_enabled
            ? null
            : 'Push access is disabled by the hosting provider through REPRINT_SERVER_PUSH_ENABLED.';
    }

    $secret = get_shared_secret();
    if ($secret === null || !function_exists('get_option')) {
        return 'Push access is disabled for the current connection token.';
    }

    $authorized_fingerprint = get_option(PUSH_AUTHORIZATION_OPTION, '');
    $authorized = is_string($authorized_fingerprint)
        && $authorized_fingerprint !== ''
        && hash_equals(hash('sha256', $secret), $authorized_fingerprint);
    return $authorized ? null : 'Push access is disabled for the current connection token.';
}

/**
 * Grants or revokes personal push authorization for the current token.
 *
 * The stored fingerprint is the only local authorization state. A different
 * current token therefore cannot inherit the prior token's write authority.
 */
function update_push_authorization(bool $enabled): bool {
    if (!function_exists('update_option')) {
        return false;
    }

    $secret = get_shared_secret();
    if ($enabled && $secret === null) {
        return false;
    }

    $fingerprint = '';
    if ($enabled) {
        $fingerprint = hash('sha256', $secret);
    }
    if (function_exists('get_option') && get_option(PUSH_AUTHORIZATION_OPTION, null) === $fingerprint) {
        return true;
    }

    return (bool) update_option(PUSH_AUTHORIZATION_OPTION, $fingerprint, false);
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
function verify_hmac(string $secret): ?string {
    if (!class_exists(HMACServer::class, false)) {
        load_server_runtime();
    }

    if (!class_exists(HMACServer::class)) {
        return 'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.';
    }

    $server = new HMACServer($secret, TIMESTAMP_TOLERANCE);
    return $server->verify_globals();
}

/**
 * Default HMAC authentication handler.
 *
 * Reads the shared secret from secret.php when present, otherwise from the
 * site option, and verifies the request's HMAC signature.
 * Calls error() on failure.
 */
function default_authenticate(): void {
    if (has_secret_file()) {
        $secret = get_file_secret();
        if (empty($secret)) {
            error(503, 'Invalid secret.php configuration. Please remove it or replace it with a valid shared secret.');
        }
    } else {
        $secret = get_option_secret();
    }

    if (empty($secret) || !is_string($secret)) {
        error(503, 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Reprint Server.');
    }

    $auth_error = verify_hmac($secret);
    if ($auth_error !== null) {
        error(403, $auth_error);
    }
}

/**
 * Handle an export API request.
 *
 * WordPress is already loaded at this point — DB credentials, $table_prefix,
 * and the database layer (including the SQLite db.php drop-in when present)
 * are all available.
 *
 * The bundled plugin passes the `reprint_server_api_options` filter result here.
 * A direct library embedder supplies the same trusted options array itself.
 *
 * @param array $options {
 *     Optional endpoint configuration overrides.
 *
 *     @type callable $authenticate Optional. Authenticates the request.
 *                                  Defaults to default_authenticate().
 *     @type string $docroot Optional. Document root for push. Defaults
 *                           to the server's DOCUMENT_ROOT. The configured path
 *                           must resolve to an existing directory.
 *     @type string $reprint_directory Optional. Private push storage path
 *                                     outside the document root.
 *                                     Defaults to a document-root-specific sibling.
 *     @type string[] $excluded_paths Optional. Document-root-relative paths
 *                                    push must preserve. The Reprint Server
 *                                    plugin directory is always included when
 *                                    it is below the document root.
 *     @type int $maximum_part_bytes Optional. Maximum Content-Length for one
 *                                   push upload part. Defaults to 4 MiB.
 *     @type int $maximum_commit_entries Optional. Maximum bounded entries one
 *                                       push_commit request processes. Defaults
 *                                       to 256.
 * }
 * @phpstan-param array{
 *     authenticate?:callable,
 *     docroot?:string,
 *     reprint_directory?:string,
 *     excluded_paths?:string[],
 *     maximum_part_bytes?:int,
 *     maximum_commit_entries?:int
 * } $options
 */
function handle_api_request(array $options = []): void {
    // Revert WordPress error display settings (wp_debug_mode may
    // have enabled display_errors based on WP_DEBUG_DISPLAY).
    if (function_exists('ini_set')) {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');
    }

    // Clear any output buffering WordPress started.
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Emit CORS headers and short-circuit OPTIONS preflight before
    // authentication runs — browsers send preflight OPTIONS without
    // credentials, so we must not require auth before CORS passes.
    // The class is loaded by the Composer autoloader on demand, but
    // load it eagerly in case the autoloader hasn't been required yet.
    if (!class_exists(HTTPServer::class, false)) {
        load_server_runtime();
    }
    HTTPServer::handle_cors_headers_and_terminate_on_options('*');

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
        error_log('Reprint Server API error: ' . json_encode($error));
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
        error_log('Reprint Server API exception: ' . json_encode($error));
        http_response_code(500);
        @header('Content-Type: application/json');
        echo json_encode($error);
        exit(1);
    });

    // -- Authenticate --
    // Push requests use envelope authentication: the signature covers the
    // method and exact request target while TLS protects the streamed body.
    // The legacy verifier hashes php://input and remains only for the existing
    // pull endpoints with bounded command bodies. A custom authenticate
    // callable still runs for every endpoint; its embedder owns that policy.
    // filter_input, not WP sanitizers: lib.php also runs without WordPress
    // bootstrapped (hosts that route the API from their own index.php).
    $endpoint = (string) filter_input(INPUT_GET, 'endpoint');
    $authenticate = $options['authenticate'] ?? null;
    if ($authenticate !== null) {
        $authenticate();
    } elseif (is_push_endpoint($endpoint)) {
        if (has_secret_file()) {
            $secret = get_file_secret();
            if (empty($secret)) {
                push_error(503, 'not_configured', 'Invalid secret.php configuration. Remove it or replace it with a valid shared secret.');
            }
        } else {
            $secret = get_option_secret();
        }
        if (empty($secret) || !is_string($secret)) {
            push_error(503, 'not_configured', 'Configure the shared secret in WordPress admin under Tools > Reprint Server.');
        }
        if (!class_exists(HMACServer::class, false)) {
            load_server_runtime();
        }
        if (!class_exists(HMACServer::class)) {
            push_error(500, 'filesystem_error', 'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.');
        }
        // These exact request-line values are covered by the HMAC; WordPress
        // slashing or sanitization would verify a different target.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $request_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $hmac_server = new HMACServer($secret, TIMESTAMP_TOLERANCE);
        $auth_error = $hmac_server->verify_envelope($_SERVER, $request_method, $request_target);
        if ($auth_error !== null) {
            push_error(403, 'auth_failed', $auth_error);
        }
    } else {
        default_authenticate();
    }

    if (!push_is_supported() && is_push_endpoint($endpoint)) {
        push_error(
            503,
            'push_disabled',
            'Push endpoints require PHP 7.2 or newer; observed PHP ' . PHP_VERSION . '.'
        );
    }

    // Authentication completes first. Every push operation requires current
    // authorization except resuming commit from its durable checkpoint, which
    // must remain available so revocation cannot strand document-root changes.
    // Push endpoint parameters travel in the query string, so the dispatcher
    // does not need to read php://input after this gate.
    $push_authorization_error = null;
    if (is_push_endpoint($endpoint)) {
        $push_authorization_error = get_push_authorization_error();
    }
    if (
        $push_authorization_error !== null
        && $endpoint !== 'push_commit'
    ) {
        push_error(
            403,
            'push_disabled',
            $push_authorization_error
        );
    }

    // Ensure the Composer autoloader is loaded so HTTPServer
    // is resolvable. The class itself will require export.php on demand
    // via serve() below.
    if (load_server_runtime() === null) {
        error(
            500,
            'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.'
        );
    }

    // -- Dispatch --
    try {
        $server_options = ['default_directory' => ABSPATH];
        if (HTTPServer::is_push_endpoint($endpoint)) {
            // Push changes the web server's document root. ABSPATH remains the
            // pull default because it may point at a separate shared core tree.
            if (array_key_exists('docroot', $options)) {
                $configured_docroot = $options['docroot'];
            } else {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted server configuration and must retain exact filesystem bytes.
                $configured_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
            }
            if (!is_string($configured_docroot) || $configured_docroot === '') {
                throw new PushConfigurationException(
                    'Push endpoints require docroot or DOCUMENT_ROOT to name an existing directory; observed '
                    . json_encode($configured_docroot) . '.'
                );
            }
            $canonical_docroot = realpath($configured_docroot);
            if ($canonical_docroot === false || !is_dir($canonical_docroot)) {
                throw new PushConfigurationException(
                    'Push endpoints require docroot or DOCUMENT_ROOT to name an existing directory; observed '
                    . json_encode($configured_docroot) . '.'
                );
            }
            $docroot = $canonical_docroot === '/' ? '/' : rtrim($canonical_docroot, '/\\');
            $lexical_docroot = _site_export_normalize_path(str_replace('\\', '/', $configured_docroot));
            $reprint_directory = $options['reprint_directory'] ?? (
                dirname($docroot) . '/.reprint-' . substr(hash('sha256', $docroot), 0, 12)
            );
            $excluded_paths = $options['excluded_paths'] ?? [];
            if (!is_array($excluded_paths)) {
                throw new PushConfigurationException('excluded_paths must be an array.');
            }
            $canonical_plugin_directory = realpath(PLUGIN_DIR);
            $plugin_directory = rtrim($canonical_plugin_directory === false ? PLUGIN_DIR : $canonical_plugin_directory, '/\\');
            $logical_plugin_path_added = false;
            if (defined('WP_PLUGIN_DIR') && function_exists('plugin_basename')) {
                // Keep the registered installation path lexical until its
                // document-root-relative name is known. realpath() would turn a
                // symlinked plugin into its outside target and omit protection.
                $registered_plugin_file = str_replace('\\', '/', plugin_basename(PLUGIN_DIR . 'index.php'));
                $registered_plugin_directory = dirname($registered_plugin_file);
                $logical_plugin_directory = _site_export_normalize_path(
                    str_replace('\\', '/', (string) WP_PLUGIN_DIR)
                    . ( $registered_plugin_directory === '.' ? '' : '/' . $registered_plugin_directory )
                );
                $logical_plugin_directory_to_verify = $logical_plugin_directory;
                $logical_plugin_relative_path = relative_path_under(
                    $logical_plugin_directory,
                    $lexical_docroot
                );
                if ($logical_plugin_relative_path === null) {
                    $logical_plugin_relative_path = relative_path_under(
                        $logical_plugin_directory,
                        $docroot
                    );
                }
                if ($logical_plugin_relative_path === null) {
                    // WP_PLUGIN_DIR may itself be a symlink alias into the
                    // document root. Resolve that parent, but keep the
                    // registered plugin subdirectory lexical so its installed
                    // path survives a final symlink to the outside target.
                    $canonical_wordpress_plugin_directory = realpath( (string) WP_PLUGIN_DIR );
                    if ($canonical_wordpress_plugin_directory !== false) {
                        $logical_plugin_directory_from_canonical_parent = _site_export_normalize_path(
                            str_replace('\\', '/', $canonical_wordpress_plugin_directory)
                            . ( $registered_plugin_directory === '.' ? '' : '/' . $registered_plugin_directory )
                        );
                        $logical_plugin_relative_path = relative_path_under(
                            $logical_plugin_directory_from_canonical_parent,
                            $docroot
                        );
                        if ($logical_plugin_relative_path !== null) {
                            $logical_plugin_directory_to_verify = $logical_plugin_directory_from_canonical_parent;
                        }
                    }
                }
                if ($logical_plugin_relative_path !== null) {
                    $resolved_logical_plugin_directory = realpath($logical_plugin_directory_to_verify);
                    if (
                        $logical_plugin_relative_path === ''
                        || $resolved_logical_plugin_directory === false
                        || $canonical_plugin_directory === false
                        || rtrim($resolved_logical_plugin_directory, '/\\') !== $plugin_directory
                    ) {
                        throw new PushConfigurationException(
                            'WordPress reports the Reprint Server plugin inside the document root at '
                            . json_encode($logical_plugin_directory_to_verify)
                            . ', but that path does not resolve to PLUGIN_DIR '
                            . json_encode(PLUGIN_DIR) . '.'
                        );
                    }
                    $excluded_paths[] = $logical_plugin_relative_path;
                    $logical_plugin_path_added = true;
                }
            }
            $plugin_relative_path = relative_path_under(
                $plugin_directory,
                $docroot
            );
            if (
                !$logical_plugin_path_added
                && $plugin_relative_path !== null
                && $plugin_relative_path !== ''
            ) {
                $excluded_paths[] = str_replace('\\', '/', $plugin_relative_path);
            }
            $push_options = [
                'reprint_directory' => $reprint_directory,
                'docroot' => $docroot,
                'excluded_paths' => $excluded_paths,
            ];
            if (array_key_exists('maximum_part_bytes', $options)) {
                $push_options['maximum_part_bytes'] = $options['maximum_part_bytes'];
            }
            if (array_key_exists('maximum_commit_entries', $options)) {
                $push_options['maximum_commit_entries'] = $options['maximum_commit_entries'];
            }
            if ($push_authorization_error !== null) {
                $push_options['commit_start_denial_detail'] = $push_authorization_error;
            }
            $server_options['push'] = $push_options;
        }
        HTTPServer::serve($server_options);
    } catch (Exception $e) {
        if (is_push_endpoint($endpoint)) {
            if ($e instanceof PushConfigurationException) {
                push_error(503, 'not_configured', $e->getMessage());
            }
            if ($e instanceof InvalidArgumentException) {
                push_error(400, 'invalid_request', $e->getMessage());
            }
            push_error(
                500,
                'filesystem_error',
                'The push endpoint failed while processing the request.'
            );
        }
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

if (function_exists('do_action')) {
    /** Fires after the canonical Reprint Server library has loaded. */
    do_action('reprint_server_library_loaded');
}
\reprint_server_bootstrap_compatibility(false);
