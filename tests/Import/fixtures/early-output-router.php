
<?php

// The blank first line models output from index.php before WordPress loads.
// output_buffering=0 makes headers_sent() true before the exporter starts.
flush();

require_once __DIR__ . '/../../../vendor/autoload.php';

if (isset($_GET['warning'])) {
    require_once __DIR__ . '/../../../packages/reprint-server/src/export.php';
    trigger_error('Source warning before streaming', E_USER_WARNING);
    return;
}

if (isset($_GET['cors'])) {
    set_error_handler(static function ($number, $message) {
        throw new RuntimeException($message);
    });
    \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options();
    echo '{"ok":true}';
    return;
}

// Fragmented protocol responses exercise the client's boundary detection over
// real HTTP reads, independently of the exporter's choice of write sizes.
if (is_file('fragments.json')) {
    foreach (json_decode(file_get_contents('fragments.json'), true) as $fragment) {
        echo $fragment;
        flush();
        usleep(30000);
    }
    return;
}

require_once __DIR__ . '/../../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
define('SQLITE_DB_DROPIN_VERSION', '2.2.0');
$reprint_database = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
$reprint_database->exec('CREATE TABLE wp_posts (ID bigint PRIMARY KEY, post_title longtext)');
$reprint_database->exec("INSERT INTO wp_posts VALUES (1, 'sample database row')");
$GLOBALS['@pdo'] = $reprint_database->get_connection()->get_pdo();
$GLOBALS['wpdb'] = (object) ['dbh' => $reprint_database, 'prefix' => 'wp_'];
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
