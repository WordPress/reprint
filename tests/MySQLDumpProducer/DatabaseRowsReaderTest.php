<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\DataLiberation\DatabaseRowsReader;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class DatabaseRowsReaderTest extends MySQLDumpProducerTestBase {

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
