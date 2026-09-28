<?php

require dirname(__DIR__, 3) . '/packages/reprint-client/src/import.php';

$root = $argv[1];
$database = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=' . $root . '/target.sqlite;dbname=wordpress');
$connection = new Reprint\Importer\Database\PdoDatabaseConnection($database, $database->get_connection()->get_pdo());
$client = new ImportClient('https://source.example/', $root, $root . '/files');
(new ReflectionMethod($client, 'create_database_import_position_table'))->invoke($client, $connection);
$members = [];
for ($index = 0; $index < 64; ++$index) {
    $members[] = 'member' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . str_repeat('x', 247);
}
$columns = [];
for ($index = 0; $index < 16; ++$index) {
    $columns[] = '`flags' . $index . '`';
}
$definitions = array_map(static fn($column) => $column . " SET('" . implode("','", $members) . "')", $columns);
$rows = [];
for ($index = 1; $index <= 250; ++$index) {
    $rows[] = '(' . $index . ',' . implode(',', array_fill(0, 16, '18446744073709551615')) . ')';
}
// This is the exporter's ordinary 250-row batch and no-op duplicate clause.
// The masks occupy about 86 KB, but their labels need about 65 MB before base64.
$sql = 'CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY,' . implode(',', $definitions) . ');'
    . 'INSERT INTO `wp_sets` (`id`,' . implode(',', $columns) . ') VALUES ' . implode(',', $rows)
    . ' ON DUPLICATE KEY UPDATE `id` = `id`;';
$statement_count = (new ReflectionMethod($client, 'execute_database_import_group'))->invoke(
    $client, $connection, $sql, hash('sha256', 'sets'), 'after', null, 'sqlite'
);
if ($statement_count !== 2) {
    throw new RuntimeException('The import must still count the two source statements, not the split rows.');
}
$expected = implode(',', $members);
$result = $database->query('SELECT * FROM wp_sets ORDER BY id');
$count = 0;
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    if ($row['id'] !== ++$count) {
        throw new RuntimeException('SET import changed row order or omitted a row.');
    }
    unset($row['id']);
    foreach ($row as $label) {
        if ($label !== $expected) {
            throw new RuntimeException('SET import changed a selected member.');
        }
    }
}
if ($count !== 250) {
    throw new RuntimeException('SET import did not insert all 250 rows.');
}
echo json_encode(['rows' => $count, 'peak_bytes' => memory_get_peak_usage(true)]);
$connection->close();
