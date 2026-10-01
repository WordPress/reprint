<?php

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Test router records real incoming requests.
// phpcs:disable WordPress.Security.NonceVerification -- The production router verifies HMAC signatures.
$reprint_request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getcwd() . '/requests.jsonl', json_encode([
    'path' => $reprint_request_path,
    'endpoint' => $_POST['endpoint'] ?? $_GET['endpoint'] ?? null,
]) . "\n", FILE_APPEND);
if ($reprint_request_path === '/redirect') {
    header('Location: /new', true, 301);
    return;
}
if (is_file(getcwd() . '/slow-index') && ( $_POST['endpoint'] ?? '' ) === 'file_index') {
    sleep(2);
}
if (is_file(getcwd() . '/unavailable')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Site temporarily unavailable.']);
    return;
}
if ($reprint_request_path === '/different-site') {
    chdir(getcwd() . '/another');
}
$_SERVER['DOCUMENT_ROOT'] = getcwd() . '/remote';
// Supply a real SQLite-backed database, as the WordPress SQLite drop-in does.
require_once __DIR__ . '/../../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Reproduce the SQLite drop-in environment.
define('SQLITE_DB_DROPIN_VERSION', '3.0.0');
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reproduce the SQLite drop-in connection.
$GLOBALS['@pdo'] = new PDO('sqlite::memory:');
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Supply a real database for the production endpoint.
$GLOBALS['wpdb'] = (object) [
    'dbh' => new WP_SQLite_Driver(new WP_SQLite_Connection(['pdo' => $GLOBALS['@pdo']]), 'wordpress'),
    'prefix' => 'wp_',
];
require __DIR__ . '/insecure-tls-router.php';
