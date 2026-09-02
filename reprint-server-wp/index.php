<?php
/**
 * Plugin Name: Reprint Server
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Exposes the Reprint API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 0.10.8-dev
 * Requires PHP: 7.2
 * PHP 5.6 support: release builds downgrade a copy of this PHP 7.2 source and set its requirement to 5.6.20.
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once __DIR__ . '/compat.php';
reprint_server_compat_normalize_legacy_request();
require_once __DIR__ . '/lib.php';

// Detect the API request while the plugin file loads, then answer it after
// other plugins have run their normal request checks. Security plugins such
// as Wordfence apply request limits during `template_redirect`.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects API request routing.
if (isset($_GET['reprint-api'])) {
    add_action(
        'template_redirect',
        static function() {
            /**
             * Filters the endpoint configuration supplied by the WordPress plugin.
             *
             * Platforms must register this filter before `template_redirect`,
             * for example from a must-use plugin.
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
        },
        PHP_INT_MAX
    );
}

// Register the option-backed configuration, then the settings page.
require_once __DIR__ . '/wordpress/configuration.php';
\WordPress\Reprint\Server\Plugin\register_wordpress_configuration();
require_once __DIR__ . '/wordpress/reprint-server.php';
