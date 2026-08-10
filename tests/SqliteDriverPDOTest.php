<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
require_once __DIR__ . '/../packages/reprint-server/src/class-sqlite-driver-pdo.php';

final class SqliteDriverPDOTest extends TestCase
{
    public function testFromBase64WorksThroughAdapterQueryPath(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $pdo = new PDO('sqlite::memory:');
        $connection = new WP_SQLite_Connection(['pdo' => $pdo]);
        $driver = new WP_SQLite_Driver($connection, 'wp_test');
        $adapter = new SqliteDriverPDO($driver, $pdo);

        $adapter->query('CREATE TABLE wp_postmeta (meta_id INTEGER, meta_key TEXT)');
        $adapter->query(
            "INSERT INTO wp_postmeta (meta_id, meta_key) VALUES " .
            "(1, '_edit_lock'), (2, '_thumbnail_id'), (3, NULL)"
        );

        $stmt = $adapter->query(
            "SELECT meta_id FROM wp_postmeta " .
            "WHERE meta_key IS NULL OR meta_key <> FROM_BASE64('" . base64_encode('_edit_lock') . "') " .
            "ORDER BY meta_id"
        );

        $this->assertSame(
            [['meta_id' => '2'], ['meta_id' => '3']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
