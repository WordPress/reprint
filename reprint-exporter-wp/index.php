<?php
/**
 * Plugin Name: Reprint Exporter
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Reprint Exporter – exposes a site export API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 0.9.2-dev
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
    $site_export_request_options = [];
    if (defined('SITE_EXPORT_STAGING_DIR')) {
        $site_export_request_options['staging_dir'] = SITE_EXPORT_STAGING_DIR;
    }
    _site_export_handle_api_request($site_export_request_options);
    exit;
}

// Register the settings page.
require_once __DIR__ . '/wordpress/site-export.php';
