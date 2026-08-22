<?php
/**
 * Plugin Name: Reprint Server
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Exposes the Reprint API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 0.10.7-dev
 * Requires PHP: 7.2
 * PHP 5.6 support: release builds downgrade a copy of this PHP 7.2 source and set its requirement to 5.6.20.
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once __DIR__ . '/compat.php';
reprint_server_bootstrap_compatibility();
require_once __DIR__ . '/lib.php';

// Intercept Reprint Server API requests as early as possible.
// WordPress loads plugin files before firing `plugins_loaded`,
// so this runs before almost anything else in the WordPress stack.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects API request routing.
if (isset($_GET['reprint-api'])) {
    /**
     * Filters the endpoint configuration supplied by the WordPress plugin.
     *
     * Platforms must register this filter before regular plugins load, for
     * example from a must-use plugin.
     *
     * @param array $reprint_server_api_options Endpoint configuration overrides.
     */
    $reprint_server_api_options = apply_filters('reprint_server_api_options', []);
    if (!is_array($reprint_server_api_options)) {
        \WordPress\Reprint\Server\Plugin\push_error(
            503,
            'not_configured',
            'The reprint_server_api_options filter must return an array; observed '
            . gettype($reprint_server_api_options) . '.'
        );
    }
    \WordPress\Reprint\Server\Plugin\handle_api_request($reprint_server_api_options);
    exit;
}

// Register the settings page.
require_once __DIR__ . '/wordpress/site-export.php';
