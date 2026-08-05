<?php

/**
 * Standalone push route for the web server's document root.
 *
 * This file deliberately does not load WordPress. The private configuration
 * written while WordPress is healthy lets files-push repair code which later
 * prevents WordPress from starting.
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
while (ob_get_level()) {
    ob_end_clean();
}
clearstatcache(true);

$site_export_plugin_directory = __DIR__ . '/';
$site_export_repo_root = dirname(__DIR__);
$site_export_runtime = null;
foreach ([
    [
        'autoload' => $site_export_plugin_directory . 'vendor/autoload.php',
        'http_server' => $site_export_plugin_directory . 'vendor/wp-php-toolkit/reprint-exporter/src/class-http-server.php',
    ],
    [
        'autoload' => $site_export_repo_root . '/vendor/autoload.php',
        'http_server' => $site_export_repo_root . '/vendor/wp-php-toolkit/reprint-exporter/src/class-http-server.php',
    ],
] as $site_export_candidate) {
    if (is_file($site_export_candidate['autoload']) && is_file($site_export_candidate['http_server'])) {
        $site_export_runtime = $site_export_candidate;
        break;
    }
}
if ($site_export_runtime === null) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.'
    );
}
require_once $site_export_runtime['autoload'];
require_once $site_export_runtime['http_server'];

Site_Export_HTTP_Server::handle_cors_headers_and_terminate_on_options('*');

// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Push authenticates the exact signed request target below.
$site_export_endpoint = $_GET['endpoint'] ?? null;
if (!is_string($site_export_endpoint) || strpos($site_export_endpoint, 'push_') !== 0) {
    _site_export_standalone_push_error(
        404,
        'invalid_request',
        'The standalone push route accepts only push endpoints.'
    );
}

$site_export_server_docroot_configuration = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (!is_string($site_export_server_docroot_configuration) || $site_export_server_docroot_configuration === '') {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The standalone push route requires DOCUMENT_ROOT to name an existing directory.'
    );
}
$site_export_server_docroot = realpath($site_export_server_docroot_configuration);
if ($site_export_server_docroot === false || !is_dir($site_export_server_docroot)) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The standalone push route requires DOCUMENT_ROOT to name an existing directory; observed '
            . json_encode($site_export_server_docroot_configuration)
            . '.'
    );
}
$site_export_server_docroot = $site_export_server_docroot === '/'
    ? '/'
    : rtrim($site_export_server_docroot, '/\\');
$site_export_configuration_reprint_directory = dirname($site_export_server_docroot)
    . '/.reprint-'
    . substr(hash('sha256', $site_export_server_docroot), 0, 12);
$site_export_configuration_path = $site_export_configuration_reprint_directory
    . '/.reprint/push-config.json';
if (!is_file($site_export_configuration_path) || is_link($site_export_configuration_path)) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'Enable push access in WordPress before using the standalone push route.'
    );
}
$site_export_configuration_json = file_get_contents($site_export_configuration_path);
$site_export_configuration = is_string($site_export_configuration_json)
    ? json_decode($site_export_configuration_json, true)
    : null;
$site_export_configuration_has_required_fields = is_array($site_export_configuration)
    && array_key_exists('push_authorization_error', $site_export_configuration)
    && array_key_exists('excluded_paths_b64', $site_export_configuration);
$site_export_connection_secret_b64 = is_array($site_export_configuration)
    ? ( $site_export_configuration['connection_secret_b64'] ?? null )
    : null;
$site_export_docroot_b64 = is_array($site_export_configuration)
    ? ( $site_export_configuration['docroot_b64'] ?? null )
    : null;
$site_export_reprint_directory_b64 = is_array($site_export_configuration)
    ? ( $site_export_configuration['reprint_directory_b64'] ?? null )
    : null;
$site_export_excluded_paths_b64 = is_array($site_export_configuration)
    ? ( $site_export_configuration['excluded_paths_b64'] ?? null )
    : null;
$site_export_push_authorization_error = is_array($site_export_configuration)
    ? ( $site_export_configuration['push_authorization_error'] ?? null )
    : null;
$site_export_connection_secret = is_string($site_export_connection_secret_b64)
    ? base64_decode($site_export_connection_secret_b64, true)
    : false;
$site_export_configured_docroot = is_string($site_export_docroot_b64)
    ? base64_decode($site_export_docroot_b64, true)
    : false;
$site_export_reprint_directory = is_string($site_export_reprint_directory_b64)
    ? base64_decode($site_export_reprint_directory_b64, true)
    : false;
if (
    !$site_export_configuration_has_required_fields
    || $site_export_connection_secret === false
    || $site_export_connection_secret === ''
    || $site_export_configured_docroot === false
    || $site_export_configured_docroot === ''
    || $site_export_reprint_directory === false
    || $site_export_reprint_directory === ''
    || !is_array($site_export_excluded_paths_b64)
    || (
        $site_export_push_authorization_error !== null
        && (
            !is_string($site_export_push_authorization_error)
            || $site_export_push_authorization_error === ''
        )
    )
) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The private standalone push configuration is invalid.'
    );
}
$site_export_docroot = realpath($site_export_configured_docroot);
if ($site_export_docroot === false || !is_dir($site_export_docroot)) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The private standalone push configuration names a document root which is not an existing directory.'
    );
}
$site_export_docroot = $site_export_docroot === '/' ? '/' : rtrim($site_export_docroot, '/\\');
$site_export_excluded_paths = [];
foreach ($site_export_excluded_paths_b64 as $site_export_excluded_path_b64) {
    $site_export_excluded_path = is_string($site_export_excluded_path_b64)
        ? base64_decode($site_export_excluded_path_b64, true)
        : false;
    if ($site_export_excluded_path === false) {
        _site_export_standalone_push_error(
            503,
            'not_configured',
            'The private standalone push configuration contains an invalid excluded path.'
        );
    }
    $site_export_excluded_paths[] = $site_export_excluded_path;
}

$site_export_script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($site_export_script_filename) || $site_export_script_filename === '') {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The standalone push route requires SCRIPT_FILENAME to identify its installed directory.'
    );
}
$site_export_route_directory = \WordPress\Reprint\Exporter\normalize_path(
    str_replace('\\', '/', dirname($site_export_script_filename))
);
$site_export_canonical_route_directory = realpath($site_export_route_directory);
$site_export_canonical_plugin_directory = realpath(__DIR__);
if (
    $site_export_canonical_route_directory === false
    || $site_export_canonical_plugin_directory === false
    || rtrim($site_export_canonical_route_directory, '/\\') !== rtrim($site_export_canonical_plugin_directory, '/\\')
) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'SCRIPT_FILENAME does not identify the installed Reprint Exporter directory.'
    );
}

// Keep the served path lexical until its document-root-relative name is known.
// realpath() could follow a symlinked plugin outside the document root and omit
// the installed route from push exclusions.
$site_export_route_relative_path = null;
$site_export_route_docroots = [str_replace('\\', '/', $site_export_docroot)];
if ($site_export_server_docroot === $site_export_docroot) {
    $site_export_lexical_server_docroot = rtrim(
        \WordPress\Reprint\Exporter\normalize_path(
            str_replace('\\', '/', $site_export_server_docroot_configuration)
        ),
        '/'
    );
    $site_export_route_docroots[] = $site_export_lexical_server_docroot === ''
        ? '/'
        : $site_export_lexical_server_docroot;
}
foreach (array_unique($site_export_route_docroots) as $site_export_route_docroot) {
    $site_export_docroot_prefix = $site_export_route_docroot === '/'
        ? '/'
        : $site_export_route_docroot . '/';
    if (strpos($site_export_route_directory . '/', $site_export_docroot_prefix) === 0) {
        $site_export_docroot_relative_offset = $site_export_route_docroot === '/'
            ? 1
            : strlen($site_export_route_docroot) + 1;
        $site_export_route_relative_path = substr(
            $site_export_route_directory,
            $site_export_docroot_relative_offset
        );
        break;
    }
}
if ($site_export_route_relative_path === '') {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'The Reprint Exporter directory must not be the configured document root.'
    );
}
if (
    $site_export_route_relative_path === null
    && $site_export_server_docroot === $site_export_docroot
) {
    _site_export_standalone_push_error(
        503,
        'not_configured',
        'SCRIPT_FILENAME must locate the Reprint Exporter directory below the configured document root.'
    );
}
if ($site_export_route_relative_path !== null) {
    $site_export_excluded_paths[] = $site_export_route_relative_path;
}

$site_export_request_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
$site_export_request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
$site_export_authentication_error = (
    new Site_Export_HMAC_Server($site_export_connection_secret, 300)
)->verify_envelope($_SERVER, $site_export_request_method, $site_export_request_target);
if ($site_export_authentication_error !== null) {
    _site_export_standalone_push_error(403, 'auth_failed', $site_export_authentication_error);
}

$site_export_managed_push_enabled = getenv('SITE_EXPORT_PUSH_ENABLED');
if ($site_export_managed_push_enabled !== false) {
    $site_export_push_authorization_error = filter_var(
        $site_export_managed_push_enabled,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) === true
        ? null
        : 'Push access is disabled by the hosting provider through SITE_EXPORT_PUSH_ENABLED.';
}
if ($site_export_push_authorization_error !== null && $site_export_endpoint !== 'push_commit') {
    _site_export_standalone_push_error(
        403,
        'push_disabled',
        $site_export_push_authorization_error
    );
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput

$site_export_push_options = [
    'reprint_directory' => $site_export_reprint_directory,
    'docroot' => $site_export_docroot,
    'excluded_paths' => $site_export_excluded_paths,
];
foreach (['maximum_part_bytes', 'maximum_commit_entries'] as $site_export_option_name) {
    if (array_key_exists($site_export_option_name, $site_export_configuration)) {
        $site_export_push_options[$site_export_option_name] = $site_export_configuration[$site_export_option_name];
    }
}
if ($site_export_push_authorization_error !== null) {
    $site_export_push_options['commit_start_denial_detail'] = $site_export_push_authorization_error;
}

try {
    Site_Export_HTTP_Server::serve(['push' => $site_export_push_options]);
} catch (Site_Export_Push_Configuration_Exception $exception) {
    _site_export_standalone_push_error(503, 'not_configured', $exception->getMessage());
} catch (InvalidArgumentException $exception) {
    _site_export_standalone_push_error(400, 'invalid_request', $exception->getMessage());
} catch (Throwable $throwable) {
    _site_export_standalone_push_error(
        500,
        'filesystem_error',
        'The standalone push route failed while processing the request.'
    );
}

/** Sends one push-protocol failure without loading WordPress. */
function _site_export_standalone_push_error(int $http_code, string $reason, string $detail): void {
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
