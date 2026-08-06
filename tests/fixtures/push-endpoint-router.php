<?php

// This fixture serves the production standalone push route directly. Other
// requests receive the WordPress functions and values read by index.php and
// its site_export_api_options filter.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

$reprint_push_test_request_log = (string) getenv('REPRINT_PUSH_TEST_REQUEST_LOG');
$reprint_push_test_endpoint = filter_input(INPUT_GET, 'endpoint', FILTER_UNSAFE_RAW);
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The router needs the raw path to select the production route under test.
$reprint_push_test_request_path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$reprint_push_test_is_standalone_push_request = $reprint_push_test_request_path === '/reprint-exporter-wp/push.php';
if ($reprint_push_test_request_log !== '' && is_string($reprint_push_test_endpoint)) {
    register_shutdown_function(
        static function () use ($reprint_push_test_request_log, $reprint_push_test_endpoint): void {
            file_put_contents(
                $reprint_push_test_request_log,
                $reprint_push_test_endpoint . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    );
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

if (is_string($reprint_push_test_docroot_configuration['document_root'] ?? null)) {
    $_SERVER['DOCUMENT_ROOT'] = $reprint_push_test_docroot_configuration['document_root'];
} else {
    unset($_SERVER['DOCUMENT_ROOT']);
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- DOCUMENT_ROOT is trusted test server configuration and must retain exact filesystem bytes.
$reprint_push_test_document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
$reprint_push_test_defunct_plugin_path = is_string($reprint_push_test_document_root)
    ? rtrim($reprint_push_test_document_root, '/\\') . '/wp-content/plugins/defunct/defunct.php'
    : '';
if (!$reprint_push_test_is_standalone_push_request && is_file($reprint_push_test_defunct_plugin_path)) {
    try {
        require $reprint_push_test_defunct_plugin_path;
    } catch (Throwable $throwable) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'WordPress could not boot after loading the pushed plugin.';
        return;
    }
}
if ($reprint_push_test_is_standalone_push_request) {
    $_SERVER['SCRIPT_FILENAME'] = rtrim( (string) $reprint_push_test_document_root, '/\\')
        . '/reprint-exporter-wp/push.php';
    require dirname(__DIR__, 2) . '/reprint-exporter-wp/push.php';
    return;
}

define('ABSPATH', rtrim( (string) getenv('REPRINT_PUSH_TEST_ABSPATH'), '/\\') . '/');
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

function apply_filters(string $hook_name, $value) {
    global $reprint_push_test_options;

    if ($hook_name === 'site_export_api_options') {
        return $reprint_push_test_options;
    }
    return $value;
}

require_once dirname(__DIR__, 2) . '/reprint-exporter-wp/index.php';
