<?php

// Serves the production plugin entry point with the stored credentials a test
// writes to the JSON file named by REPRINT_AUTH_TEST_CONFIG. Authentication,
// the push gate, and dispatch all run through index.php as on a live site.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$reprint_auth_test_configuration = json_decode(
    (string) file_get_contents( (string) getenv('REPRINT_AUTH_TEST_CONFIG') ),
    true
);
if (!is_array($reprint_auth_test_configuration)) {
    $reprint_auth_test_configuration = [];
}

if (isset($reprint_auth_test_configuration['sqlite_database_path'])) {
    require_once dirname(__DIR__, 2) . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
    define('SQLITE_DB_DROPIN_VERSION', '1.0');
    $reprint_auth_test_database = new WP_PDO_MySQL_On_SQLite(
        'mysql-on-sqlite:path=' . $reprint_auth_test_configuration['sqlite_database_path'] . ';dbname=wp_test'
    );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- SQLite integration exposes its loaded database under this global name.
    $GLOBALS['@pdo'] = $reprint_auth_test_database;
    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Supply the real SQLite driver's WordPress database context.
    $GLOBALS['wpdb'] = (object) ['dbh' => $reprint_auth_test_database, 'prefix' => 'wp_'];
    ini_set('error_log', dirname($reprint_auth_test_configuration['sqlite_database_path']) . '/server.log');
    // An old producer can spin here; bound that failed test with PHP's own limit.
    set_time_limit(1);
}

if (isset($reprint_auth_test_configuration['key_auth_required'])) {
    \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(
        $reprint_auth_test_configuration['key_auth_required'] === true
    );
}

define('ABSPATH', __DIR__ . '/');
define(
    'WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE',
    $reprint_auth_test_configuration['credentials_directory'] . '/secret.php'
);
define(
    'WordPress\\Reprint\\Server\\Plugin\\PUBLIC_KEYS_FILE',
    $reprint_auth_test_configuration['credentials_directory'] . '/public-keys.php'
);

function plugin_dir_path(string $file): string {
    return $file === '' ? '' : dirname(__DIR__, 2) . '/reprint-server-wp/';
}

function get_option(string $name, $fallback = false) {
    global $reprint_auth_test_configuration;

    return $reprint_auth_test_configuration['options'][$name] ?? $fallback;
}

function apply_filters(string $hook_name, $value) {
    global $reprint_auth_test_configuration;
    if ($hook_name === 'reprint_server_api_options' && array_key_exists('api_url', $reprint_auth_test_configuration)) {
        $value['api_url'] = $reprint_auth_test_configuration['api_url'];
    }
    return $value;
}

require_once dirname(__DIR__, 2) . '/reprint-server-wp/index.php';
