<?php

// This fixture supplies the WordPress values the production plugin router
// reads; request authentication and endpoint dispatch remain production code.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

define('ABSPATH', rtrim( (string) getenv('REPRINT_PUSH_TEST_DOCROOT'), '/\\') . '/');

function plugin_dir_path(string $file): string {
    return $file === '' ? '' : dirname(__DIR__, 2) . '/reprint-exporter-wp/';
}

function get_option(string $name, $fallback = false) {
    if ($name === 'site_export_secret') {
        return (string) getenv('REPRINT_PUSH_TEST_SECRET');
    }
    return $fallback;
}

require_once dirname(__DIR__, 2) . '/reprint-exporter-wp/lib.php';

_site_export_handle_api_request([
    'reprint_directory' => (string) getenv('REPRINT_PUSH_TEST_DIRECTORY'),
    'excluded_paths' => ['preserved'],
    'maximum_part_bytes' => 64,
    'maximum_commit_entries' => 1,
]);
