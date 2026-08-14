<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/LegacySqliteMetadataDriver.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class SqliteDriverPDOLegacyMetadataTest extends TestCase {
    public function testSuppliesTableTypesWhenTheLegacyDriverOnlySupportsShowTables(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $driver = new LegacySqliteMetadataDriver();
        $adapter = new SqliteDriverPDO($driver, new PDO('sqlite::memory:'));

        $this->assertSame(
            [['Tables_in_wp_test' => 'wp_posts', 'Table_type' => 'BASE TABLE']],
            $adapter->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertSame(['SHOW FULL TABLES', 'SHOW TABLES'], $driver->queries);
    }
}
