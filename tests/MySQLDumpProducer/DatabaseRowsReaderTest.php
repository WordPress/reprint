<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\Reprint\Server\DatabaseRowsReader;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class DatabaseRowsReaderTest extends MySQLDumpProducerTestBase {

    /** A table name identifies one position, including after an empty table or resume. */
    public function testTableNamesDetermineOrderBeforeAndAfterResume(): void
    {
        $expected_tables = ['third_table', 'empty_table', 'first_table'];
        foreach ($expected_tables as $table) {
            $this->pdo->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY)");
        }
        $this->pdo->exec('INSERT INTO third_table VALUES (3)');
        $this->pdo->exec('INSERT INTO first_table VALUES (1)');

        foreach ([false, true] as $resume) {
            foreach ([$expected_tables, ['third_table', 'third_table', 'empty_table', 'first_table', 'empty_table']] as $requested_tables) {
                $options = ['tables_to_process' => $requested_tables, 'batch_size' => 1];
                $reader = new DatabaseRowsReader($this->pdo, $options);
                $rows = [];
                foreach ($expected_tables as $table) {
                    $this->assertTrue($reader->move_to_next_table());
                    $this->assertSame($table, $reader->get_current_table());
                    while ($reader->next_record()) {
                        $rows[] = (int) $reader->get_current_record()['id'];
                    }
                    if ($resume) {
                        $cursor = $reader->get_cursor_state();
                        $reader->close();
                        $reader = new DatabaseRowsReader($this->pdo, $options);
                        $this->assertTrue($reader->restore_cursor_state($cursor));
                    }
                }
                $this->assertFalse($reader->move_to_next_table());
                $this->assertNull($reader->get_current_table());
                $this->assertSame([3, 1], $rows);
                $reader->close();
            }
        }

        $empty_reader = new DatabaseRowsReader($this->pdo, ['tables_to_process' => []]);
        $this->assertFalse($empty_reader->move_to_next_table());
        $this->assertFalse($empty_reader->start_user_tables());
        $empty_reader->close();
    }

    public function testExcludesRequestedAndReprintProgressTablesWhenDiscoveringSourceTables(): void
    {
        $this->pdo->exec('CREATE TABLE included_table (id INT PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE skipped_table (id INT PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE `__reprint_db_pull_progress_another_schema` (id INT PRIMARY KEY)'
        );

        $reader = new DatabaseRowsReader(
            $this->pdo,
            ['exclude_tables' => ['SKIPPED_TABLE']]
        );
        $reader->initialize_tables_to_process();

        $tables = [];
        while ($reader->move_to_next_table()) {
            $tables[] = $reader->get_current_table();
        }

        $this->assertContains('included_table', $tables);
        $this->assertNotContains('skipped_table', $tables);
        $this->assertNotContains('__reprint_db_pull_progress_another_schema', $tables);
    }

    public function testTableDiscoverySuppliesRowEstimatesAndExcludesViews(): void
    {
        $this->pdo->exec('CREATE TABLE counted_rows (id INT PRIMARY KEY) ENGINE=MyISAM');
        $this->pdo->exec('INSERT INTO counted_rows VALUES (1), (2), (3)');
        $this->pdo->exec('CREATE VIEW counted_rows_view AS SELECT * FROM counted_rows');
        $reader = new DatabaseRowsReader($this->pdo);
        $reader->initialize_tables_to_process();
        $this->assertTrue($reader->move_to_next_table());
        $cursor = $reader->get_cursor_state();
        $this->assertSame('counted_rows', $cursor['current_table']);
        $this->assertSame(3, $cursor['current_table_rows_estimated']);
        $this->assertSame(1, $cursor['tables_total']);
        $this->assertFalse($reader->move_to_next_table());
    }

    public function testNumericTableNamesRemainStringsAcrossResume(): void
    {
        $this->pdo->exec('CREATE TABLE `123` (id INT PRIMARY KEY)');
        foreach ([[], ['tables_to_process' => ['123']]] as $options) {
            $reader = new DatabaseRowsReader($this->pdo, $options);
            if (!$reader->has_initialized_tables()) {
                $reader->initialize_tables_to_process();
            }
            $this->assertTrue($reader->move_to_next_table());
            $cursor = $reader->get_cursor_state();
            $this->assertSame('123', $cursor['current_table']);
            $reader->close();
            $reader = new DatabaseRowsReader($this->pdo, $options);
            $this->assertTrue($reader->restore_cursor_state($cursor));
            $this->assertSame($cursor, $reader->get_cursor_state());
            $reader->close();
        }
    }

    public function testReturnsStructuredRecordsWithoutFormattingSql(): void
    {
        $this->pdo->exec(
            'CREATE TABLE structured_records (' .
            'id INT PRIMARY KEY, content VARBINARY(255), nullable_value TEXT NULL)'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO structured_records VALUES (?, ?, ?)'
        );
        $raw_content = "raw\0bytes\xFF";
        $insert->execute([7, $raw_content, null]);

        $reader = new DatabaseRowsReader($this->pdo, ['batch_size' => 1]);
        $reader->initialize_tables_to_process();

        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());
        $this->assertSame('structured_records', $reader->get_current_table());
        $this->assertSame(['id'], $reader->get_current_primary_key_columns());
        $this->assertSame(
            ['id' => 7, 'content' => $raw_content, 'nullable_value' => null],
            $reader->get_current_record()
        );
    }

    public function testCursorResumesAfterTheLastReturnedCompositeKey(): void
    {
        $this->pdo->exec(
            'CREATE TABLE structured_resume (' .
            'part_a INT NOT NULL, part_b VARCHAR(20) NOT NULL, content TEXT, ' .
            'PRIMARY KEY (part_a, part_b))'
        );
        $this->pdo->exec(
            "INSERT INTO structured_resume VALUES " .
            "(1, 'a', 'first'), (1, 'b', 'second'), (2, 'a', 'third')"
        );

        $reader = new DatabaseRowsReader($this->pdo, ['batch_size' => 1]);
        $reader->initialize_tables_to_process();
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());
        $this->assertSame('first', $reader->get_current_record()['content']);

        $resumed = new DatabaseRowsReader($this->pdo, ['batch_size' => 1]);
        $this->assertTrue($resumed->restore_cursor_state($reader->get_cursor_state()));
        $remaining_content = [];
        while ($resumed->next_record()) {
            $remaining_content[] = $resumed->get_current_record()['content'];
        }

        $this->assertSame(['second', 'third'], $remaining_content);
    }

    public function testRestoreRejectsAChangedPrimaryKey(): void
    {
        $this->pdo->exec(
            'CREATE TABLE changed_primary_key (' .
            'id INT NOT NULL, replacement_id INT NOT NULL, content TEXT, ' .
            'PRIMARY KEY (id), UNIQUE KEY (replacement_id))'
        );
        $this->pdo->exec(
            "INSERT INTO changed_primary_key VALUES (1, 10, 'first'), (2, 20, 'second')"
        );

        $reader = new DatabaseRowsReader($this->pdo, ['batch_size' => 1]);
        $reader->initialize_tables_to_process();
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());
        $cursor = $reader->get_cursor_state();

        $this->pdo->exec(
            'ALTER TABLE changed_primary_key DROP PRIMARY KEY, ADD PRIMARY KEY (replacement_id)'
        );

        $resumed = new DatabaseRowsReader($this->pdo, ['batch_size' => 1]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot restore the database row cursor because the primary key for table `changed_primary_key` changed.'
        );
        $resumed->restore_cursor_state($cursor);
    }
}
