<?php
/** Serve only an extracted plugin, with host configuration granting all push access. */

define('ABSPATH', getenv('REPRINT_TEST_DOCROOT') . '/');
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', getenv('REPRINT_TEST_PLUGIN') . '/');
define('REPRINT_SERVER_PUSH_ENABLED', true);
define('SITE_EXPORT_PUSH_ENABLED', true);

require getenv('REPRINT_TEST_PLUGIN') . '/index.php';

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
    return $default;
}
