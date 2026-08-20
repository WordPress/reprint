<?php
/**
 * Reprint Server library – constants and function declarations, no request handling.
 *
 * Require this file to get access to the export API functions without
 * triggering any HTTP dispatch.
 */

use function WordPress\Reprint\Server\relative_path_under;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITE_EXPORT_VERSION')) {
    define('SITE_EXPORT_VERSION', '0.10.2-dev');
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

/** Returns whether this PHP runtime can serve push endpoints. */
function _site_export_push_is_supported(): bool {
    return PHP_VERSION_ID >= 70200;
}

/**
 * Resolve and load the server package runtime.
 *
 * Supports both plugin release bundles (with reprint-server-wp/vendor/) and
 * the monorepo checkout (root vendor/ + vendor/wp-php-toolkit/reprint-server).
 *
 * @return string|null Absolute path to export.php, or null when the runtime is missing.
 */
function _site_export_load_exporter_runtime(): ?string {
    static $loaded_export_path = null;

    if ($loaded_export_path !== null) {
        return $loaded_export_path;
    }

    $repo_root = dirname(SITE_EXPORT_PLUGIN_DIR);
    $candidates = [
        [
            'autoload' => SITE_EXPORT_PLUGIN_DIR . 'vendor/autoload.php',
            'export' => SITE_EXPORT_PLUGIN_DIR . 'vendor/wp-php-toolkit/reprint-server/src/export.php',
        ],
        [
            'autoload' => $repo_root . '/vendor/autoload.php',
            'export' => $repo_root . '/vendor/wp-php-toolkit/reprint-server/src/export.php',
        ],
    ];

    foreach ($candidates as $candidate) {
        if (!file_exists($candidate['autoload']) || !file_exists($candidate['export'])) {
            continue;
        }

        $autoload_path = realpath($candidate['autoload']);
        $export_path = realpath($candidate['export']);
        if ($autoload_path === false || $export_path === false) {
            continue;
        }

        require_once $autoload_path;
        $loaded_export_path = $export_path;
        return $export_path;
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
        return $managed_enabled
            ? null
            : 'Push access is disabled by the hosting provider through SITE_EXPORT_PUSH_ENABLED.';
    }

    $secret = _site_export_get_shared_secret();
    if ($secret === null || !function_exists('get_option')) {
        return 'Push access is disabled for the current connection token.';
    }

    $authorized_fingerprint = get_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, '');
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
function _site_export_update_push_authorization(bool $enabled): bool {
    if (!function_exists('update_option')) {
        return false;
    }

    $secret = _site_export_get_shared_secret();
    if ($enabled && $secret === null) {
        return false;
    }

    $fingerprint = '';
    if ($enabled) {
        $fingerprint = hash('sha256', $secret);
    }
    if (function_exists('get_option') && get_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, '') === $fingerprint) {
        return true;
    }

    return (bool) update_option(SITE_EXPORT_PUSH_AUTHORIZATION_OPTION, $fingerprint, false);
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
    if (!class_exists('Site_Export_HMAC_Server', false)) {
        _site_export_load_exporter_runtime();
    }

    if (!class_exists('Site_Export_HMAC_Server')) {
        return 'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.';
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
        _site_export_error(503, 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Reprint Server.');
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
    if (!class_exists('Site_Export_HTTP_Server', false)) {
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
    } elseif (_site_export_is_push_endpoint($endpoint)) {
        if (_site_export_has_secret_file()) {
            $secret = _site_export_get_file_secret();
            if (empty($secret)) {
                _site_export_push_error(503, 'not_configured', 'Invalid secret.php configuration. Remove it or replace it with a valid shared secret.');
            }
        } else {
            $secret = _site_export_get_option_secret();
        }
        if (empty($secret) || !is_string($secret)) {
            _site_export_push_error(503, 'not_configured', 'Configure the shared secret in WordPress admin under Tools > Reprint Server.');
        }
        if (!class_exists('Site_Export_HMAC_Server', false)) {
            _site_export_load_exporter_runtime();
        }
        if (!class_exists('Site_Export_HMAC_Server')) {
            _site_export_push_error(500, 'filesystem_error', 'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.');
        }
        // These exact request-line values are covered by the HMAC; WordPress
        // slashing or sanitization would verify a different target.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $request_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $hmac_server = new Site_Export_HMAC_Server($secret, SITE_EXPORT_TIMESTAMP_TOLERANCE);
        $auth_error = $hmac_server->verify_envelope($_SERVER, $request_method, $request_target);
        if ($auth_error !== null) {
            _site_export_push_error(403, 'auth_failed', $auth_error);
        }
    } else {
        _site_export_default_authenticate();
    }

    if (!_site_export_push_is_supported() && _site_export_is_push_endpoint($endpoint)) {
        _site_export_push_error(
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
    if (_site_export_is_push_endpoint($endpoint)) {
        $push_authorization_error = _site_export_get_push_authorization_error();
    }
    if (
        $push_authorization_error !== null
        && $endpoint !== 'push_commit'
    ) {
        _site_export_push_error(
            403,
            'push_disabled',
            $push_authorization_error
        );
    }

    // Ensure the Composer autoloader is loaded so Site_Export_HTTP_Server
    // is resolvable. The class itself will require export.php on demand
    // via serve() below.
    if (_site_export_load_exporter_runtime() === null) {
        _site_export_error(
            500,
            'Reprint Server runtime is incomplete. Run composer install in reprint-server-wp or rebuild the release package.'
        );
    }

    // -- Dispatch --
    try {
        $server_options = ['default_directory' => ABSPATH];
        if (Site_Export_HTTP_Server::is_push_endpoint($endpoint)) {
            // Push changes the web server's document root. ABSPATH remains the
            // pull default because it may point at a separate shared core tree.
            if (array_key_exists('docroot', $options)) {
                $configured_docroot = $options['docroot'];
            } else {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted server configuration and must retain exact filesystem bytes.
                $configured_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
            }
            if (!is_string($configured_docroot) || $configured_docroot === '') {
                throw new Site_Export_Push_Configuration_Exception(
                    'Push endpoints require docroot or DOCUMENT_ROOT to name an existing directory; observed '
                    . json_encode($configured_docroot) . '.'
                );
            }
            $canonical_docroot = realpath($configured_docroot);
            if ($canonical_docroot === false || !is_dir($canonical_docroot)) {
                throw new Site_Export_Push_Configuration_Exception(
                    'Push endpoints require docroot or DOCUMENT_ROOT to name an existing directory; observed '
                    . json_encode($configured_docroot) . '.'
                );
            }
            $docroot = $canonical_docroot === '/' ? '/' : rtrim($canonical_docroot, '/\\');
            $lexical_docroot = \WordPress\Reprint\Server\normalize_path(str_replace('\\', '/', $configured_docroot));
            $reprint_directory = $options['reprint_directory'] ?? (
                dirname($docroot) . '/.reprint-' . substr(hash('sha256', $docroot), 0, 12)
            );
            $excluded_paths = $options['excluded_paths'] ?? [];
            if (!is_array($excluded_paths)) {
                throw new Site_Export_Push_Configuration_Exception('excluded_paths must be an array.');
            }
            $canonical_plugin_directory = realpath(SITE_EXPORT_PLUGIN_DIR);
            $plugin_directory = rtrim($canonical_plugin_directory === false ? SITE_EXPORT_PLUGIN_DIR : $canonical_plugin_directory, '/\\');
            $logical_plugin_path_added = false;
            if (defined('WP_PLUGIN_DIR') && function_exists('plugin_basename')) {
                // Keep the registered installation path lexical until its
                // document-root-relative name is known. realpath() would turn a
                // symlinked plugin into its outside target and omit protection.
                $registered_plugin_file = str_replace('\\', '/', plugin_basename(SITE_EXPORT_PLUGIN_DIR . 'index.php'));
                $registered_plugin_directory = dirname($registered_plugin_file);
                $logical_plugin_directory = \WordPress\Reprint\Server\normalize_path(
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
                        $logical_plugin_directory_from_canonical_parent = \WordPress\Reprint\Server\normalize_path(
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
                        throw new Site_Export_Push_Configuration_Exception(
                            'WordPress reports the Reprint Server plugin inside the document root at '
                            . json_encode($logical_plugin_directory_to_verify)
                            . ', but that path does not resolve to SITE_EXPORT_PLUGIN_DIR '
                            . json_encode(SITE_EXPORT_PLUGIN_DIR) . '.'
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
        Site_Export_HTTP_Server::serve($server_options);
    } catch (Exception $e) {
        if (_site_export_is_push_endpoint($endpoint)) {
            if ($e instanceof Site_Export_Push_Configuration_Exception) {
                _site_export_push_error(503, 'not_configured', $e->getMessage());
            }
            if ($e instanceof InvalidArgumentException) {
                _site_export_push_error(400, 'invalid_request', $e->getMessage());
            }
            _site_export_push_error(
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
