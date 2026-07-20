<?php

/**
 * Standalone push route for the plugin's default document root.
 *
 * This file deliberately does not load WordPress. Hosting integrations which
 * override the push document root or reprint directory must expose their own
 * route with the same server-owned paths.
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

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
    if (
        is_file($site_export_candidate['autoload'])
        && is_file($site_export_candidate['http_server'])
    ) {
        $site_export_runtime = $site_export_candidate;
        break;
    }
}
if ($site_export_runtime === null) {
    _site_export_push_route_error(
        'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.'
    );
}
require_once $site_export_runtime['autoload'];
require_once $site_export_runtime['http_server'];

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted server configuration and must retain exact filesystem bytes.
$site_export_configured_docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (!is_string($site_export_configured_docroot) || $site_export_configured_docroot === '') {
    _site_export_push_route_error('The push route requires DOCUMENT_ROOT to name an existing directory.');
}
$site_export_docroot = realpath($site_export_configured_docroot);
if ($site_export_docroot === false || !is_dir($site_export_docroot)) {
    _site_export_push_route_error(
        'The push route requires DOCUMENT_ROOT to name an existing directory; observed '
        . json_encode($site_export_configured_docroot) . '.'
    );
}
$site_export_docroot = $site_export_docroot === '/' ? '/' : rtrim($site_export_docroot, '/\\');
$site_export_reprint_directory = Site_Export_HTTP_Server::default_reprint_directory($site_export_docroot);

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- SCRIPT_FILENAME is trusted server configuration and must retain exact filesystem bytes.
$site_export_script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($site_export_script_filename) || $site_export_script_filename === '') {
    _site_export_push_route_error('The push route requires SCRIPT_FILENAME to identify its excluded path.');
}
// Keep the route directory lexical until its document-root-relative name is
// known. realpath() may turn a symlinked plugin into an outside target and
// would then omit the installed route from the push exclusions.
$site_export_route_directory = \WordPress\Reprint\Exporter\normalize_path(
    str_replace('\\', '/', dirname($site_export_script_filename))
);
$site_export_lexical_docroot = rtrim(
    \WordPress\Reprint\Exporter\normalize_path(str_replace('\\', '/', $site_export_configured_docroot)),
    '/'
);
$site_export_lexical_docroot = $site_export_lexical_docroot === '' ? '/' : $site_export_lexical_docroot;
$site_export_normalized_docroot = str_replace('\\', '/', $site_export_docroot);
$site_export_route_relative_path = null;
foreach (array_unique([$site_export_lexical_docroot, $site_export_normalized_docroot]) as $site_export_route_docroot) {
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
$site_export_canonical_route_directory = realpath($site_export_route_directory);
$site_export_canonical_plugin_directory = realpath(__DIR__);
if (
    $site_export_route_relative_path === null
    || $site_export_canonical_route_directory === false
    || $site_export_canonical_plugin_directory === false
    || rtrim($site_export_canonical_route_directory, '/\\') !== rtrim($site_export_canonical_plugin_directory, '/\\')
) {
    _site_export_push_route_error(
        'The push route must run from the Reprint Exporter directory below DOCUMENT_ROOT so that directory can be excluded from push.'
    );
}
if ($site_export_route_relative_path === '') {
    _site_export_push_route_error('The push route directory must not be the document root.');
}

$site_export_connection_secret = null;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- The endpoint is validated by serve_push() and authenticated from the exact signed request target.
$site_export_endpoint = $_GET['endpoint'] ?? null;
if ($site_export_endpoint === 'push_create') {
    $site_export_push_configuration_path = $site_export_reprint_directory . '/.reprint/push-config.php';
    if (file_exists($site_export_push_configuration_path) || is_link($site_export_push_configuration_path)) {
        $site_export_connection_secret = Site_Export_HTTP_Server::load_push_connection_secret(
            $site_export_push_configuration_path
        );
        if ($site_export_connection_secret === null) {
            _site_export_push_route_error(
                'The private push configuration must be a PHP file which returns a non-empty connection_secret without producing output.'
            );
        }
    }
    $site_export_managed_push_enabled = getenv('SITE_EXPORT_PUSH_ENABLED');
    if (
        $site_export_managed_push_enabled !== false
        && filter_var($site_export_managed_push_enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true
    ) {
        $site_export_connection_secret = null;
    }
}

Site_Export_HTTP_Server::handle_cors_headers_and_terminate_on_options('*');
Site_Export_HTTP_Server::serve_push([
    'connection_secret' => $site_export_connection_secret,
    'reprint_directory' => $site_export_reprint_directory,
    'docroot' => $site_export_docroot,
    'excluded_paths' => [$site_export_route_relative_path],
]);

/** Sends one push-route configuration failure without loading WordPress. */
function _site_export_push_route_error(string $detail): void {
    http_response_code(503);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'rejected',
        'reason' => 'not_configured',
        'detail' => $detail,
    ]);
    exit;
}
