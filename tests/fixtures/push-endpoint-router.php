<?php

// This fixture supplies the WordPress values the production plugin router
// reads; request authentication and endpoint dispatch remain production code.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

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
        return (string) getenv('REPRINT_PUSH_TEST_SECRET');
    }
    return $fallback;
}

require_once dirname(__DIR__, 2) . '/reprint-exporter-wp/lib.php';

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
$reprint_push_test_directory = trim( (string) file_get_contents( (string) getenv('REPRINT_PUSH_TEST_DIRECTORY_CONFIG') ) );
if ($reprint_push_test_directory !== '') {
    $reprint_push_test_options['reprint_directory'] = $reprint_push_test_directory;
}
_site_export_handle_api_request($reprint_push_test_options);
