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

if (!function_exists('wp_unslash')) {
    /**
     * @param string|array $value
     * @return string|array
     */
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}
