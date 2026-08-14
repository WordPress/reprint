<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/LegacySqliteMetadataDriver.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class SqliteDriverPDOLegacyMetadataTest extends TestCase {
    public function testSuppliesTableTypesFromLegacyTableStatusMetadata(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $driver = new LegacySqliteMetadataDriver();
        $adapter = new SqliteDriverPDO($driver, new PDO('sqlite::memory:'));

        $this->assertSame(
            [['Name' => 'wp_posts', 'Table_type' => 'BASE TABLE']],
            $adapter->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertSame(['SHOW FULL TABLES', 'SHOW TABLE STATUS;'], $driver->queries);
    }

    public function testRetriesQuotedMetadataQueriesForTheLegacyParser(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $driver = new LegacySqliteMetadataDriver();
        $adapter = new SqliteDriverPDO($driver, new PDO('sqlite::memory:'));

        $this->assertSame(
            [['Key_name' => 'PRIMARY', 'Column_name' => 'ID', 'Seq_in_index' => 0]],
            $adapter->query('SHOW INDEX FROM `wp_posts`')->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertSame(
            [['Field' => 'ID', 'Type' => 'INTEGER']],
            $adapter->query('SHOW FULL COLUMNS FROM `wp_posts`')->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertSame(
            [
                'SHOW INDEX FROM `wp_posts`',
                'SHOW INDEX FROM wp_posts',
                'SHOW FULL COLUMNS FROM `wp_posts`',
                'SHOW FULL COLUMNS FROM wp_posts',
            ],
            $driver->queries
        );
    }
}
