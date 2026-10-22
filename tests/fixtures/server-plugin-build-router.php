<?php
/** Serve only an extracted plugin, with host configuration granting all push access. */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Isolated package fixture routes test requests, not WordPress form submissions.

define('ABSPATH', getenv('REPRINT_TEST_DOCROOT') . '/');
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', getenv('REPRINT_TEST_PLUGIN') . '/');
define('REPRINT_SERVER_PUSH_ENABLED', true);
define('SITE_EXPORT_PUSH_ENABLED', true);

// A standalone consumer must not need the WordPress adapter to enforce the flag.
if (isset($_GET['standalone'])) {
    require getenv('REPRINT_TEST_PLUGIN') . '/vendor/autoload.php';
    $reprint_test_push_options = [
        'docroot' => getenv('REPRINT_TEST_DOCROOT'),
        'reprint_directory' => getenv('REPRINT_TEST_DOCROOT') . '-push-state',
    ];
    \WordPress\Reprint\Server\HTTPServer::serve([
        'push' => $reprint_test_push_options,
        'database_push' => $reprint_test_push_options,
        'handlers' => ['push_future_probe' => static function () { echo 'Push handler reached.'; }],
    ]);
    exit;
}

require getenv('REPRINT_TEST_PLUGIN') . '/index.php';

if (isset($_GET['admin_probe'])) {
    \WordPress\Reprint\Server\Plugin\load_server_runtime();
    \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests($_GET['admin_probe'] === 'key');
    $reprint_test_page = \WordPress\Reprint\Server\Plugin\SettingsPage::get_instance();
    ob_start();
    $reprint_test_page->render_admin_page();
    $reprint_test_html = ob_get_clean();
    $reprint_test_configuration = \WordPress\Reprint\Server\Plugin\get_configuration_state();
    echo json_encode([
        'html' => $reprint_test_html,
        'push_supported' => $reprint_test_configuration['push_supported'],
        'push_enabled' => $reprint_test_configuration['push_enabled'],
        'push_actions' => array_values(array_filter($GLOBALS['reprint_test_actions'], static function ($name) { return strpos($name, 'admin_post_reprint_server_save_') === 0 && strpos($name, 'push_access') !== false; })),
        'token_grant' => \WordPress\Reprint\Server\Plugin\change_push_access(true),
        'key_grant' => \WordPress\Reprint\Server\Plugin\change_key_push_access($reprint_test_configuration['enrolled_keys'][0]['key_id'], true),
        'token_authorized' => \WordPress\Reprint\Server\Plugin\is_push_authorized(),
        'token_grant_written' => \WordPress\Reprint\Server\Plugin\update_push_authorization(true),
    ]);
    exit;
}

/** Supply the single-site WordPress context. */
function is_multisite() {
    return false;
}

/** Ignore legacy filter registration in this isolated WordPress bootstrap. */
function add_filter() {
}

/** The documented custom authenticator lets this fixture isolate build capabilities. */
function apply_filters($name, $value) {
    if ($name === 'reprint_server_api_options') {
        return [
            'authenticate' => isset($_GET['host_auth']) ? static function () {} : null,
            'docroot' => getenv('REPRINT_TEST_DOCROOT'),
            'reprint_directory' => getenv('REPRINT_TEST_DOCROOT') . '-push-state',
            'database_push' => true,
        ];
    }
    return $value;
}

/** Supply an enrolled key with a stored push grant through the normal option path. */
function get_option($name, $default = false) {
    if ($name === 'reprint_server_public_keys') {
        return [['public_key' => getenv('REPRINT_TEST_PUBLIC_KEY'), 'push' => true]];
    }
    if ($name === 'reprint_server_connection_token') {
        return 'fixture-token';
    }
    return $default;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function names required by the packaged administrator.

/** Record registered actions without running a WordPress request lifecycle. */
function add_action($name) { $GLOBALS['reprint_test_actions'][] = $name; }
/** Ignore activation registration outside WordPress. */
function register_activation_hook() {}
/** Use the packaged plugin basename. */
function plugin_basename($path) { return basename($path); }
/** Allow the administrator page to render. */
function current_user_can() { return true; }
/** Return test-local URLs. */
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
/** Return the site's API URL. */
function home_url($query) { return 'https://example.test/' . $query; }
/** Match WordPress URL parsing for the scheme check. */
function wp_parse_url($url, $component) { return parse_url($url, $component); }
/** No Settings API notices are pending. */
function get_settings_errors() { return []; }
/** Return untranslated test strings. */
function __($text) { return $text; }
/** Escape rendered text. */
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
/** Escape rendered attributes. */
function esc_attr($text) { return esc_html($text); }
/** Escape rendered URLs. */
function esc_url($text) { return esc_attr($text); }
/** Translate and escape rendered text. */
function esc_html__($text) { return esc_html($text); }
/** Translate and escape rendered attributes. */
function esc_attr__($text) { return esc_attr($text); }
/** Render only the button label in this page check. */
function submit_button($text) { echo '<button>' . esc_html($text) . '</button>'; }
/** Omit nonce markup; no administrator form is submitted by this fixture. */
function wp_nonce_field() {}
/** Omit Settings API markup; no administrator form is submitted. */
function settings_fields() {}
/** Render the checkbox's current value. */
function checked($value) { if ($value) { echo ' checked'; } }
/** Render a read-only managed control. */
function disabled($value) { if ($value) { echo ' disabled'; } }
/** Supply the option write API without changing fixture credentials. */
function update_option() { return true; }

/** Match the notice's WordPress key sanitizer. */
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
/** Test query values contain no WordPress-added slashes. */
function wp_unslash($value) { return $value; }
/** Notices have already been escaped by the packaged page. */
function wp_kses_post($value) { return $value; }
