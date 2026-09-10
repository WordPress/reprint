<?php

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\DatabaseRowsReader;

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
        $queries_before_progress = $database->queries;
        for ($update = 0; $update < 100; ++$update) {
            $this->assertSame(12000, $reader->get_cursor_state()['current_table_rows_estimated']);
            $this->assertSame(1, $reader->get_cursor_state()['current_table_number']);
        }
        $this->assertSame($queries_before_progress, $database->queries);
        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame('A_table', $reader->get_current_table());
        $this->assertSame(
            [
                'SHOW TABLE STATUS;',
                'SHOW INDEX FROM `z_table`',
                'SHOW FULL COLUMNS FROM `z_table`',
                'SHOW INDEX FROM `A_table`',
                'SHOW FULL COLUMNS FROM `A_table`',
            ],
            $database->queries
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProgressDoesNotSearchTheTableList(): void
    {
        $reader = new DatabaseRowsReader(new DatabaseRowsReaderMetadataConnection(['first', 'second']));
        $reader->initialize_tables_to_process();
        $reader->move_to_next_table();
        $reader->move_to_next_table();
        require __DIR__ . '/fixtures/forbid-progress-table-search.php';
        $this->assertSame(2, $reader->get_cursor_state()['current_table_number']);
    }

    public function testUsesReturnedOrderWhenPrimaryKeyPositionsAreNotUsable(): void
    {
        $database = new DatabaseRowsReaderMetadataConnection(
            ['composite_table'],
            [
                ['Key_name' => 'secondary', 'Column_name' => 'ignored', 'Seq_in_index' => 0],
                ['Key_name' => 'PRIMARY', 'Column_name' => 'part_a', 'Seq_in_index' => 0],
                ['Key_name' => 'PRIMARY', 'Column_name' => 'part_b', 'Seq_in_index' => 0],
            ]
        );
        $reader = new DatabaseRowsReader($database);

        $reader->initialize_tables_to_process();

        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame(['part_a', 'part_b'], $reader->get_current_primary_key_columns());
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
        ]));
        $this->assertSame(['id'], $reader->get_current_column_names());

        $this->assertTrue($reader->move_to_next_table());
        $this->assertSame('third', $reader->get_current_table());
    }
}
