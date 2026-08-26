<?php

// This fixture supplies the WordPress functions and values the production
// plugin entry point reads. API routing, authentication, and dispatch all run
// through index.php and its reprint_server_api_options filter.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

$reprint_push_test_request_log = (string) getenv('REPRINT_PUSH_TEST_REQUEST_LOG');
$reprint_push_test_endpoint = filter_input(INPUT_GET, 'endpoint', FILTER_UNSAFE_RAW);
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
    define('REPRINT_SERVER_PUSH_ENABLED', $reprint_push_test_managed_state === 'true');
}

function plugin_dir_path(string $file): string {
    return $file === '' ? '' : dirname(__DIR__, 2) . '/reprint-server-wp/';
}

function plugin_basename(string $file): string {
    global $reprint_push_test_docroot_configuration;

    $registered_plugin_basename = $reprint_push_test_docroot_configuration['plugin_basename'] ?? null;
    if (
        defined('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR')
        && $file === constant('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR') . 'index.php'
        && is_string($registered_plugin_basename)
    ) {
        return $registered_plugin_basename;
    }
    return basename($file);
}

function get_option(string $name, $fallback = false) {
    if ($name === 'reprint_server_connection_token' || $name === 'site_export_secret') {
        return trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_SECRET_CONFIG') ) );
    }
    if (
        $name === 'reprint_server_push_authorized_token_fingerprint'
        || $name === 'site_export_push_authorized_token_fingerprint'
    ) {
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

$reprint_push_test_filters = [];
function add_filter(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): void {
    global $reprint_push_test_filters;
    $reprint_push_test_filters[$hook_name][] = [
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    ];
}
function apply_filters(string $hook_name, $value, ...$extra_args) {
    global $reprint_push_test_filters;
    $filters = $reprint_push_test_filters[$hook_name] ?? [];
    usort($filters, static function (array $left, array $right): int {
        return $left['priority'] <=> $right['priority'];
    });
    foreach ($filters as $filter) {
        $args = array_slice(array_merge([$value], $extra_args), 0, $filter['accepted_args']);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Generic WordPress filter test stub.
        $value = call_user_func_array($filter['callback'], $args);
    }
    return $value;
}
function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): void {
    add_filter($hook_name, $callback, $priority, $accepted_args);
}
function do_action(string $hook_name, ...$args): void {
    apply_filters($hook_name, null, ...$args);
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress test stub signature.
function update_option(string $name, $value, $autoload = null): bool {
    return true;
}

add_filter('site_export_api_options', static function (): array {
    return ['legacy_filter_ran' => true];
});
add_filter('reprint_server_api_options', static function ($value) use ($reprint_push_test_options) {
    if (!is_array($value) || ( $value['legacy_filter_ran'] ?? false ) !== true) {
        return null;
    }
    return array_merge($value, $reprint_push_test_options);
});

require_once dirname(__DIR__, 2) . '/reprint-server-wp/index.php';
