<?php

// This fixture exposes the production push route separately from a WordPress
// route which can be made defunct by committed files.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

$reprint_push_test_request_log = (string) getenv('REPRINT_PUSH_TEST_REQUEST_LOG');
$reprint_push_test_endpoint = filter_input(INPUT_GET, 'endpoint', FILTER_UNSAFE_RAW);
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The router needs the raw request path to select the test route.
$reprint_push_test_request_path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$reprint_push_test_is_push_request = $reprint_push_test_request_path === '/push.php';
if ($reprint_push_test_request_log !== '' && is_string($reprint_push_test_endpoint)) {
    register_shutdown_function(
        static function () use ($reprint_push_test_request_log, $reprint_push_test_endpoint, $reprint_push_test_is_push_request): void {
            file_put_contents(
                $reprint_push_test_request_log,
                ( $reprint_push_test_is_push_request ? 'push:' : 'wordpress:' ) . $reprint_push_test_endpoint . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    );
}

$reprint_push_test_wordpress_defunct_path = (string) getenv('REPRINT_PUSH_TEST_WORDPRESS_DEFUNCT_PATH');
$reprint_push_test_wordpress_is_defunct = $reprint_push_test_wordpress_defunct_path !== ''
    && is_file($reprint_push_test_wordpress_defunct_path)
    && strpos(
        (string) file_get_contents($reprint_push_test_wordpress_defunct_path),
        'The pushed plugin broke WordPress boot.'
    ) !== false;
$reprint_push_test_push_session_while_wordpress_defunct_path = (string) getenv('REPRINT_PUSH_TEST_PUSH_SESSION_WHILE_WORDPRESS_DEFUNCT_PATH');
if (
    $reprint_push_test_is_push_request
    && $reprint_push_test_wordpress_is_defunct
    && $reprint_push_test_endpoint === 'push_create'
) {
    file_put_contents($reprint_push_test_push_session_while_wordpress_defunct_path, 'observed');
}
if (
    !$reprint_push_test_is_push_request
    && $reprint_push_test_wordpress_is_defunct
) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'WordPress could not boot after loading the pushed plugin.';
    return;
}

$reprint_push_test_gate_endpoint_path = (string) getenv('REPRINT_PUSH_TEST_GATE_ENDPOINT_CONFIG');
$reprint_push_test_gate_endpoint = $reprint_push_test_gate_endpoint_path === ''
    ? ''
    : trim( (string) file_get_contents($reprint_push_test_gate_endpoint_path) );
if (
    $reprint_push_test_gate_endpoint !== ''
    && $reprint_push_test_endpoint === $reprint_push_test_gate_endpoint
) {
    $reprint_push_test_gate_ready_path = (string) getenv('REPRINT_PUSH_TEST_GATE_READY');
    $reprint_push_test_gate_release_path = (string) getenv('REPRINT_PUSH_TEST_GATE_RELEASE');
    if (!is_file($reprint_push_test_gate_ready_path)) {
        file_put_contents($reprint_push_test_gate_ready_path, 'ready', LOCK_EX);
        $reprint_push_test_gate_deadline = microtime(true) + 20;
        while (
            !is_file($reprint_push_test_gate_release_path)
            && microtime(true) < $reprint_push_test_gate_deadline
        ) {
            usleep(1000);
        }
    }
}

$reprint_push_test_docroot_configuration = json_decode(
    (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_DOCROOT_CONFIG') ),
    true
);
if (!is_array($reprint_push_test_docroot_configuration)) {
    $reprint_push_test_docroot_configuration = [];
}

define('ABSPATH', rtrim( (string) getenv('REPRINT_PUSH_TEST_ABSPATH'), '/\\') . '/');
if (is_string($reprint_push_test_docroot_configuration['document_root'] ?? null)) {
    $_SERVER['DOCUMENT_ROOT'] = $reprint_push_test_docroot_configuration['document_root'];
} else {
    unset($_SERVER['DOCUMENT_ROOT']);
}
if (is_string($reprint_push_test_docroot_configuration['wp_plugin_dir'] ?? null)) {
    define('WP_PLUGIN_DIR', $reprint_push_test_docroot_configuration['wp_plugin_dir']);
}

$reprint_push_test_managed_state = trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_MANAGED_PUSH_CONFIG') ) );
if ($reprint_push_test_managed_state !== '') {
    define('SITE_EXPORT_PUSH_ENABLED', $reprint_push_test_managed_state === 'true');
}

function plugin_dir_path(string $file): string {
    return $file === '' ? '' : dirname(__DIR__, 2) . '/reprint-exporter-wp/';
}

function plugin_basename(string $file): string {
    global $reprint_push_test_docroot_configuration;

    $registered_plugin_basename = $reprint_push_test_docroot_configuration['plugin_basename'] ?? null;
    if (defined('SITE_EXPORT_PLUGIN_DIR') && $file === SITE_EXPORT_PLUGIN_DIR . 'index.php' && is_string($registered_plugin_basename)) {
        return $registered_plugin_basename;
    }
    return basename($file);
}

function get_option(string $name, $fallback = false) {
    if ($name === 'site_export_secret') {
        return trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_SECRET_CONFIG') ) );
    }
    if ($name === 'site_export_push_authorized_token_fingerprint') {
        return trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_AUTHORIZATION_CONFIG') ) );
    }
    return $fallback;
}

$reprint_push_test_excluded_paths_b64 = json_decode(
    (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_EXCLUDED_PATHS_CONFIG') ),
    true
);
$reprint_push_test_options = [
    'excluded_paths' => is_array($reprint_push_test_excluded_paths_b64)
        ? array_map(
            static function ($encoded_path) {
                return is_string($encoded_path) ? base64_decode($encoded_path, true) : $encoded_path;
            },
            $reprint_push_test_excluded_paths_b64
        )
        : $reprint_push_test_excluded_paths_b64,
    'maximum_part_bytes' => 4,
    'maximum_commit_entries' => 1,
];
$reprint_push_test_docroot = $reprint_push_test_docroot_configuration['docroot'] ?? null;
if (is_string($reprint_push_test_docroot)) {
    $reprint_push_test_options['docroot'] = $reprint_push_test_docroot;
}
if (isset($reprint_push_test_docroot_configuration['maximum_part_bytes'])) {
    $reprint_push_test_options['maximum_part_bytes'] = $reprint_push_test_docroot_configuration['maximum_part_bytes'];
}
if (trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_CUSTOM_AUTH_CONFIG') ) ) === 'enabled') {
    $reprint_push_test_options['authenticate'] = function (): void {
    };
}
$reprint_push_test_directory = trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_DIRECTORY_CONFIG') ) );
if ($reprint_push_test_directory !== '') {
    $reprint_push_test_options['reprint_directory'] = $reprint_push_test_directory;
}

if ($reprint_push_test_is_push_request) {
    $reprint_push_test_bundled_route_configuration_path = (string) getenv('REPRINT_PUSH_TEST_BUNDLED_ROUTE_CONFIG');
    $reprint_push_test_use_bundled_route = $reprint_push_test_bundled_route_configuration_path !== ''
        && trim( (string) file_get_contents($reprint_push_test_bundled_route_configuration_path) ) === 'enabled';
    if ($reprint_push_test_use_bundled_route) {
        $_SERVER['SCRIPT_FILENAME'] = rtrim(
            (string) $reprint_push_test_docroot_configuration['document_root'],
            '/\\'
        ) . '/reprint-exporter-wp/push.php';
        require dirname(__DIR__, 2) . '/reprint-exporter-wp/push.php';
        return;
    }

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    require_once dirname(__DIR__, 2) . '/packages/reprint-exporter/src/class-http-server.php';
    $reprint_push_test_connection_secret = trim(
        (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_SECRET_CONFIG') )
    );
    $reprint_push_test_authorized_fingerprint = trim(
        (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_AUTHORIZATION_CONFIG') )
    );
    $reprint_push_test_personally_authorized = hash_equals(
        hash('sha256', $reprint_push_test_connection_secret),
        $reprint_push_test_authorized_fingerprint
    );
    $reprint_push_test_push_is_authorized = $reprint_push_test_managed_state === ''
        ? $reprint_push_test_personally_authorized
        : $reprint_push_test_managed_state === 'true';
    $reprint_push_test_connection_secret = $reprint_push_test_push_is_authorized
        ? $reprint_push_test_connection_secret
        : null;
    $reprint_push_test_push_options = [
        'connection_secret' => $reprint_push_test_connection_secret,
        'docroot' => $reprint_push_test_docroot_configuration['docroot'] ?? $reprint_push_test_docroot_configuration['document_root'] ?? null,
        'excluded_paths' => $reprint_push_test_options['excluded_paths'],
        'maximum_part_bytes' => $reprint_push_test_options['maximum_part_bytes'],
        'maximum_commit_entries' => $reprint_push_test_options['maximum_commit_entries'],
    ];
    if ($reprint_push_test_directory !== '') {
        $reprint_push_test_push_options['reprint_directory'] = $reprint_push_test_directory;
    }
    if ($reprint_push_test_push_is_authorized && isset($reprint_push_test_options['authenticate'])) {
        $reprint_push_test_push_options['authenticate'] = $reprint_push_test_options['authenticate'];
    }
    Site_Export_HTTP_Server::serve_push($reprint_push_test_push_options);
    return;
}

function apply_filters(string $hook_name, $value) {
    global $reprint_push_test_options;

    if ($hook_name === 'site_export_api_options') {
        return $reprint_push_test_options;
    }
    return $value;
}

require_once dirname(__DIR__, 2) . '/reprint-exporter-wp/index.php';
