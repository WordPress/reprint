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

// ── TEMPORARY E2E DIAGNOSTIC — REVERT BEFORE MERGE ──────────────────────────
// Traces the double-bootstrap behind the intermittent wpcloud-flatten fatal
// ("Cannot redeclare class ComposerAutoloaderInit*"). Gated to the wpcloud
// E2E vhost so the rest of the suite stays quiet.
if (
    getenv('SITE_EXPORT_TEST_MODE') &&
    strpos($_SERVER['DOCUMENT_ROOT'] ?? '', 'wpcloud') !== false
) {
    $stu_diag_trace = array_map(
        static function ($f) {
            return ($f['file'] ?? '?') . ':' . ($f['line'] ?? 0);
        },
        debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
    );
    error_log(
        '[E2E-DIAG] index.php __FILE__=' . __FILE__
        . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '-')
        . ' includers=' . implode(' <- ', array_slice($stu_diag_trace, 0, 6))
    );
    error_log(
        '[E2E-DIAG] init-classes=' . implode(
            ',',
            preg_grep('/^ComposerAutoloaderInit/', get_declared_classes()) ?: ['none']
        )
    );
    if (!defined('STU_DIAG_SHUTDOWN_ARMED')) {
        define('STU_DIAG_SHUTDOWN_ARMED', true);
        register_shutdown_function(static function () {
            $e = error_get_last();
            if (!$e || strpos($e['message'], 'ComposerAutoloaderInit') === false) {
                return;
            }
            error_log('[E2E-DIAG] FATAL ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
            // Ordered include list at the moment of death: the entry just
            // before the second autoload.php names its includer chain.
            error_log(
                '[E2E-DIAG] at-fatal includes: '
                . implode(' | ', preg_grep('#site-export|autoload#', get_included_files()) ?: [])
            );
        });
    }
}
// ── END TEMPORARY E2E DIAGNOSTIC ─────────────────────────────────────────────

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
