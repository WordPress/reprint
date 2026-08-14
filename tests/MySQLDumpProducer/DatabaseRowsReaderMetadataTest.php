<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DatabaseRowsReader;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataTest extends TestCase {

    public function testUsesPublicMetadataQueriesAndPreservesTheirTableOrder(): void
    {
        $database = new DatabaseRowsReaderMetadataConnection(['z_table', 'A_table']);
        $reader = new DatabaseRowsReader($database);

        $reader->initialize_tables_to_process();

        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame('z_table', $reader->get_current_table());
        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame('A_table', $reader->get_current_table());
        $this->assertSame(
            [
                'SHOW FULL TABLES',
                "SHOW INDEX FROM `z_table` WHERE Key_name = 'PRIMARY'",
                'SHOW FULL COLUMNS FROM `z_table`',
                "SHOW INDEX FROM `A_table` WHERE Key_name = 'PRIMARY'",
                'SHOW FULL COLUMNS FROM `A_table`',
            ],
            $database->queries
        );
    }
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataConnection {
    /** @var string[] */
    private $tables;

    /** @var string[] */
    public $queries = [];

    /** @param string[] $tables */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function query(string $query): DatabaseRowsReaderMetadataStatement
    {
        $this->queries[] = $query;
        if ($query === 'SHOW FULL TABLES') {
            return new DatabaseRowsReaderMetadataStatement(array_map(static function ($table) {
                return ['table' => $table, 'type' => 'BASE TABLE'];
            }, $this->tables));
        }
        if (strpos($query, 'SHOW INDEX FROM ') === 0) {
            return new DatabaseRowsReaderMetadataStatement([
                ['Column_name' => 'id', 'Seq_in_index' => 1],
            ]);
        }
        if (strpos($query, 'SHOW FULL COLUMNS FROM ') === 0) {
            return new DatabaseRowsReaderMetadataStatement([
                ['Field' => 'id', 'Type' => 'int(11)'],
            ]);
        }
        throw new RuntimeException("Unexpected query: {$query}");
    }
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderMetadataStatement {
    /** @var array[] */
    private $rows;

    /** @param array[] $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @return array|false */
    public function fetch()
    {
        if (count($this->rows) === 0) {
            return false;
        }
        return array_shift($this->rows);
    }
}
