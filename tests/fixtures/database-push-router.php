<?php

// The production library authenticates and dispatches every request. The host
// route uses file-backed credentials so replacing wp_options cannot remove it.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('REPRINT_DB_PUSH_SERVER_PHP') && class_exists('PDO', false)) {
    throw new RuntimeException('The PDO-free push endpoint test must run without PDO.');
}
define('ABSPATH', getenv('REPRINT_DB_TEST_ROOT') . '/site/');
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', dirname(__DIR__, 2) . '/reprint-server-wp/');
define('WordPress\\Reprint\\Server\\Plugin\\CONNECTION_TOKEN_FILE', getenv('REPRINT_DB_TEST_ROOT') . '/secret.php');
define('REPRINT_SERVER_PUSH_ENABLED', true);
define('DB_HOST', str_replace(';port=', ':', getenv('DB_HOST')));
define('DB_NAME', getenv('DB_NAME') . '_receiver');
define('DB_USER', getenv('DB_USER'));
define('DB_PASSWORD', getenv('DB_PASS'));
$GLOBALS['table_prefix'] = 'wp_';
register_shutdown_function(static function (): void {
    file_put_contents(getenv('REPRINT_DB_TEST_ROOT') . '/requests', ($_GET['endpoint'] ?? '') . "\n", FILE_APPEND);
});
require dirname(__DIR__, 2) . '/reprint-server-wp/lib.php';
\WordPress\Reprint\Server\Plugin\handle_api_request([
    'docroot' => ABSPATH,
    'reprint_directory' => getenv('REPRINT_DB_TEST_ROOT') . '/private',
    'database_push' => true,
    'maximum_part_bytes' => 16384,
]);
