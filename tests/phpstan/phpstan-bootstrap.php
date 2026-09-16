<?php
/**
 * PHPStan bootstrap – stub WordPress symbols that lib.php depends on
 * but that aren't available during static analysis.
 */

define('ABSPATH', '/tmp/wordpress/');

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) {
        return parse_url($url, $component);
    }
}

// Function declarations only; the WordPress integration is not executed here.
require_once __DIR__ . '/../../reprint-server-wp/wordpress/multisite.php';
