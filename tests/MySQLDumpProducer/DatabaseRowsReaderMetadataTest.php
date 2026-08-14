<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DatabaseRowsReader;

require_once __DIR__ . '/fixtures/DatabaseRowsReaderMetadataConnection.php';

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

    public function testRestoreAlignsAProvidedTableListWithTheCurrentTable(): void
    {
        $database = new DatabaseRowsReaderMetadataConnection(['first', 'second', 'third']);
        $reader = new DatabaseRowsReader($database, [
            'tables_to_process' => ['first', 'second', 'third'],
        ]);

        $this->assertTrue($reader->restore_cursor_state([
            'current_table' => 'second',
            'current_pk_columns' => ['id'],
            'last_pk_values' => ['id' => 1],
            'current_offset' => 0,
            'current_row' => null,
            'current_column_names' => ['id'],
        ]));

        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame('third', $reader->get_current_table());
    }
}
