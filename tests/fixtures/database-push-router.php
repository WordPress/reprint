<?php

// The production library authenticates and dispatches every request. The host
// route uses file-backed credentials so replacing wp_options cannot remove it.
if (getenv('REPRINT_DB_PUSH_SERVER_PHP') && class_exists('PDO', false)) {
    throw new RuntimeException('The PDO-free push endpoint test must run without PDO.');
}
register_shutdown_function(static function (): void {
    file_put_contents(getenv('REPRINT_DB_TEST_ROOT') . '/requests', ($_GET['endpoint'] ?? '') . "\n", FILE_APPEND);
});
require dirname(__DIR__, 2) . '/reprint-server-wp/standalone.php';
