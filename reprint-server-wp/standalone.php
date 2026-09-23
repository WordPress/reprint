<?php

namespace WordPress\Reprint\Server\Plugin;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone entry point; WordPress is never loaded.

// No WordPress bootstrap: the incoming database may deactivate this plugin or
// replace its option-backed token. Only trusted host configuration is loaded.
ini_set('display_errors', '0');
header('Cache-Control: no-store');
// FPM workers retain realpath results across requests. Recheck private paths
// before loading config, not only later inside the shared request handler.
clearstatcache(true);

$reject_configuration = static function (string $detail): void {
    http_response_code(503);
    header('Content-Type: application/octet-stream');
    echo json_encode(['status' => 'rejected', 'reason' => 'not_configured', 'detail' => $detail]);
    exit;
};

$require_private_file = static function ($path, string $docroot) use ($reject_configuration): string {
    $resolved = is_string($path) && $path !== '' ? realpath($path) : false;
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        $reject_configuration('Standalone Reprint requires a readable private configuration file and token file.');
    }
    $resolved = str_replace('\\', '/', $resolved);
    $docroot = rtrim(str_replace('\\', '/', $docroot), '/') . '/';
    if (DIRECTORY_SEPARATOR === '\\') {
        $inside = strncasecmp($resolved, $docroot, strlen($docroot)) === 0;
    } else {
        $inside = strpos($resolved, $docroot) === 0;
    }
    if ($inside) {
        $reject_configuration('Standalone Reprint configuration and token files must be outside the document root.');
    }
    return $resolved;
};

// This variable is set by the host, never by a request parameter or header.
$config_path = getenv('REPRINT_SERVER_CONFIG');
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted web-server configuration.
$web_docroot_path = (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' );
$web_docroot = $web_docroot_path === '' ? false : realpath($web_docroot_path);
if ($web_docroot === false || !is_dir($web_docroot)) {
    $reject_configuration('Standalone Reprint requires DOCUMENT_ROOT to name an existing directory.');
}
$config_path = $require_private_file($config_path, $web_docroot);
$options = require $config_path;
if (!is_array($options) || !isset($options['docroot']) || !is_string($options['docroot'])) {
    $reject_configuration('Standalone Reprint configuration must return API options with a docroot path.');
}
$docroot = realpath($options['docroot']);
if ($docroot === false || !is_dir($docroot) || !defined('ABSPATH')) {
    $reject_configuration('Standalone Reprint configuration must define ABSPATH and name an existing docroot.');
}
$require_private_file($config_path, $docroot);
$token_constant = __NAMESPACE__ . '\\CONNECTION_TOKEN_FILE';
$token_path = defined($token_constant) ? constant($token_constant) : null;
$require_private_file($token_path, $web_docroot);
$require_private_file($token_path, $docroot);

define(__NAMESPACE__ . '\\PLUGIN_DIR', __DIR__ . '/');
require __DIR__ . '/lib.php';
if (load_server_runtime() === null) {
    $reject_configuration('Reprint Server runtime is incomplete. Install the release bundle or run composer install in reprint-server-wp.');
}
handle_api_request($options);
