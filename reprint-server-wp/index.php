<?php
/**
 * Plugin Name: Reprint Server
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Reprint Server – exposes a site export API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 0.9.5-dev
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once __DIR__ . '/lib.php';

// Intercept export API requests as early as possible.
// WordPress loads plugin files before firing `plugins_loaded`,
// so this runs before almost anything else in the WordPress stack.
//
// `?site-export-api` is the legacy query parameter kept for backwards
// compatibility with clients pinned to earlier plugin versions.
// New integrations should use `?reprint-api`.
if (isset($_GET['reprint-api']) || isset($_GET['site-export-api'])) {
    /**
     * Filters the endpoint configuration supplied by the WordPress plugin.
     *
     * Platforms must register this filter before regular plugins load, for
     * example from a must-use plugin.
     *
     * @param array $site_export_api_options Endpoint configuration overrides.
     */
    $site_export_api_options = apply_filters('site_export_api_options', []);
    if (!is_array($site_export_api_options)) {
        _site_export_push_error(
            503,
            'not_configured',
            'The site_export_api_options filter must return an array; observed '
            . gettype($site_export_api_options) . '.'
        );
    }
    _site_export_handle_api_request($site_export_api_options);
    exit;
}

// Register the settings page.
require_once __DIR__ . '/wordpress/site-export.php';
