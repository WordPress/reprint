<?php

require_once __DIR__ . "/MySQLDumpProducerTestBase.php";

/**
 * Tests cursor-based pause/resume (reentrancy): large datasets, composite keys,
 * no-PK tables, batch boundaries, mid-batch resumption, and cursor edge cases.
 */
class ReentrancyTest extends MySQLDumpProducerTestBase
{
    // ──────────────────────────────────────────────────
    // Large dataset reentrancy (from LargeDatasetReentrancyTest)
    // ──────────────────────────────────────────────────

    /**
     * Test exporting 200k rows with cursor resumption every 200 rows.
     * This results in 1000+ iterations to fully test reentrancy.
     */
    public function testLargeDatasetWithReentrancy(): void
    {
        $totalRows = 200000;
        $resumeEvery = 200; // Rows per iteration

        $this->pdo->exec("
            CREATE TABLE large_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100),
                value INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert test data in batches
        $this->insertLargeDataset($totalRows);

        // Export with resumption
        $allFragments = $this->exportWithReentrancy($resumeEvery);

        // Verify we got all the data
        $completeSQL = implode("\n", $allFragments);

        // Count INSERT statements
        $insertCount = substr_count($completeSQL, "INSERT INTO");
        $this->assertGreaterThan(
            0,
            $insertCount,
            "Should have INSERT statements",
        );

        // Import and verify
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify row count
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM large_table")
            ->fetchColumn();
        $this->assertEquals($totalRows, $count, "All rows should be imported");

        // Verify no duplicates
        $distinctCount = $importPdo
            ->query("SELECT COUNT(DISTINCT id) FROM large_table")
            ->fetchColumn();
        $this->assertEquals(
            $totalRows,
            $distinctCount,
            "All IDs should be unique",
        );

        // Verify data integrity with checksums
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["large_table"]);
    }

    /**
     * Test with composite primary key and reentrancy.
     */
    public function testCompositePrimaryKeyReentrancy(): void
    {
        $this->pdo->exec("
            CREATE TABLE composite_large (
                part1 INT NOT NULL,
                part2 INT NOT NULL,
                data VARCHAR(50),
                PRIMARY KEY (part1, part2)
            )
        ");

        // Insert 10,000 rows with composite key
        $values = [];
        for ($i = 1; $i <= 100; $i++) {
            for ($j = 1; $j <= 100; $j++) {
                $values[] = "({$i}, {$j}, 'data_{$i}_{$j}')";
                if (count($values) >= 1000) {
                    $this->pdo->exec(
                        "INSERT INTO composite_large VALUES " .
                            implode(",", $values),
                    );
                    $values = [];
                }
            }
        }
        if (!empty($values)) {
            $this->pdo->exec(
                "INSERT INTO composite_large VALUES " . implode(",", $values),
            );
        }

        // Export with frequent resumption
        $allFragments = $this->exportWithReentrancy(100);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify all rows
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM composite_large")
            ->fetchColumn();
        $this->assertEquals(10000, $count);

        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            "composite_large",
        ]);
    }

    /**
     * Test with no primary key and offset-based pagination.
     */
    public function testNoPrimaryKeyReentrancy(): void
    {
        $this->pdo->exec("
            CREATE TABLE no_pk_large (
                data VARCHAR(100),
                value INT
            )
        ");

        // Insert 5000 rows without PK
        for ($batch = 0; $batch < 50; $batch++) {
            $values = [];
            for ($i = 0; $i < 100; $i++) {
                $row = $batch * 100 + $i;
                $values[] = "('row_{$row}', {$row})";
            }
            $this->pdo->exec(
                "INSERT INTO no_pk_large VALUES " . implode(",", $values),
            );
        }

        // Export with resumption
        $allFragments = $this->exportWithReentrancy(100);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        $this->assertDatabasesEqual($this->pdo, $importPdo, ["no_pk_large"]);
    }

    /**
     * Test multiple tables with reentrancy.
     */
    public function testMultipleTablesWithReentrancy(): void
    {
        // Create 3 tables
        for ($t = 1; $t <= 3; $t++) {
            $this->pdo->exec("
                CREATE TABLE table_{$t} (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    data VARCHAR(50)
                )
            ");

            // Insert 1000 rows per table
            for ($batch = 0; $batch < 10; $batch++) {
                $values = [];
                for ($i = 0; $i < 100; $i++) {
                    $values[] =
                        "('table_{$t}_row_" . ($batch * 100 + $i) . "')";
                }
                $this->pdo->exec(
                    "INSERT INTO table_{$t} (data) VALUES " .
                        implode(",", $values),
                );
            }
        }

        // Export with frequent resumption
        $allFragments = $this->exportWithReentrancy(50);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify all tables
        for ($t = 1; $t <= 3; $t++) {
            $count = $importPdo
                ->query("SELECT COUNT(*) FROM table_{$t}")
                ->fetchColumn();
            $this->assertEquals(
                1000,
                $count,
                "table_{$t} should have 1000 rows",
            );
        }
    }

    /**
     * Test cursor state across batch boundaries.
     */
    public function testCursorAcrossBatchBoundaries(): void
    {
        $this->pdo->exec("
            CREATE TABLE batch_boundary (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert exactly 500 rows (2 batches of 250)
        for ($i = 1; $i <= 500; $i++) {
            $this->pdo->exec(
                "INSERT INTO batch_boundary (data) VALUES ('row_{$i}')",
            );
        }

        // Export with batch size of 250, resume every 125 rows
        $producer = $this->createProducer(["batch_size" => 250]);
        $fragmentCount = 0;
        $iterationCount = 0;
        $cursor = null;
        $allFragments = [];

        while (!$producer->is_finished()) {
            $rowsInIteration = 0;

            while ($rowsInIteration < 125 && $producer->next_sql_fragment()) {
                $sql = $producer->get_sql_fragment();
                if ($sql !== null) {
                    $allFragments[] = $sql;
                    if ($this->isInsertRowFragment($sql)) {
                        ++$rowsInIteration;
                    }
                }
                $fragmentCount++;
            }

            $iterationCount++;

            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                // Resume from cursor
                $producer = $this->createProducer([
                    "batch_size" => 250,
                    "cursor" => $cursor,
                ]);
            }
        }

        // Verify that resumption worked and all data was exported
        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM batch_boundary")
            ->fetchColumn();
        $this->assertEquals(500, $count, "All 500 rows should be exported");

        // Should have multiple iterations due to resumption
        $this->assertGreaterThanOrEqual(
            2,
            $iterationCount,
            "Should have at least 2 iterations with resumption",
        );
    }

    /**
     * Test resumption in middle of accumulated batch.
     */
    public function testResumptionMidBatch(): void
    {
        $this->pdo->exec("
            CREATE TABLE mid_batch (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert 300 rows
        for ($i = 1; $i <= 300; $i++) {
            $this->pdo->exec(
                "INSERT INTO mid_batch (data) VALUES ('row_{$i}')",
            );
        }

        // Start export with batch size 250
        $producer = $this->createProducer(["batch_size" => 250]);

        // Process CREATE TABLE
        $producer->next_sql_fragment();
        $producer->next_sql_fragment(); // Header comment

        // Now we should be accumulating rows
        // Get cursor before first INSERT is emitted
        $cursor = $producer->get_reentrancy_cursor();

        // Create new producer from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 250,
            "cursor" => $cursor,
        ]);

        // Should be able to continue seamlessly
        $fragments = $this->collectAllFragments($producer2);

        // Verify we got INSERTs
        $insertFragments = array_filter($fragments, function ($f) {
            return strpos($f, "INSERT INTO") === 0;
        });

        $this->assertGreaterThan(0, count($insertFragments));
    }

    /**
     * Test very small batch size with many iterations.
     */
    public function testSmallBatchManyIterations(): void
    {
        $this->pdo->exec("
            CREATE TABLE small_batches (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data INT
            )
        ");

        // Insert 1000 rows
        $values = [];
        for ($i = 1; $i <= 1000; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec(
            "INSERT INTO small_batches (data) VALUES " . implode(",", $values),
        );

        // Export with very small batch size (10 rows)
        $allFragments = $this->exportWithReentrancy(10, 10);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        $count = $importPdo
            ->query("SELECT COUNT(*) FROM small_batches")
            ->fetchColumn();
        $this->assertEquals(1000, $count);

        // Verify no duplicates or missing rows
        $sum = $importPdo
            ->query("SELECT SUM(data) FROM small_batches")
            ->fetchColumn();
        $expectedSum = (1000 * 1001) / 2; // Sum of 1 to 1000
        $this->assertEquals($expectedSum, $sum);
    }

    /**
     * Test cursor serialization/deserialization integrity.
     */
    public function testCursorSerializationIntegrity(): void
    {
        $this->pdo->exec("
            CREATE TABLE cursor_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        for ($i = 1; $i <= 100; $i++) {
            $this->pdo->exec(
                "INSERT INTO cursor_test (data) VALUES ('row_{$i}')",
            );
        }

        $producer = $this->createProducer(["batch_size" => 25]);

        // Process a few fragments
        for ($i = 0; $i < 5; $i++) {
            $producer->next_sql_fragment();
        }

        // Get cursor
        $cursor = $producer->get_reentrancy_cursor();

        // Verify cursor is valid JSON
        $cursorData = json_decode($cursor, true);
        $this->assertIsArray($cursorData);
        $this->assertArrayHasKey("current_table", $cursorData);
        $this->assertArrayHasKey("state", $cursorData);
        $this->assertArrayNotHasKey("current_row", $cursorData);
        $this->assertArrayNotHasKey("current_row_ends_query_batch", $cursorData);
        $this->assertArrayNotHasKey("current_column_names", $cursorData);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $cursorData["current_column_names_hash"]
        );

        // Resume from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 25,
            "cursor" => $cursor,
        ]);

        // Should be able to continue
        $this->assertFalse($producer2->is_finished());
        $this->assertTrue($producer2->next_sql_fragment());
    }

    public function testLegacyCursorEmitsItsRetainedRowOnce(): void
    {
        $this->pdo->exec("CREATE TABLE legacy_cursor (id INT PRIMARY KEY, data VARCHAR(50))");
        $this->pdo->exec(
            "INSERT INTO legacy_cursor VALUES (1, 'first'), (2, 'second'), (3, 'third')"
        );

        $producer = $this->createProducer(["batch_size" => 250]);
        $fragments = [];
        while ($producer->next_sql_fragment()) {
            $fragment = (string) $producer->get_sql_fragment();
            $fragments[] = $fragment;
            if (strpos($fragment, 'INSERT INTO `legacy_cursor`') === 0) {
                $fragments[count($fragments) - 1] .= ',';
                break;
            }
        }

        $legacyCursor = json_decode($producer->get_reentrancy_cursor(), true);
        unset($legacyCursor["current_column_names_hash"]);
        $legacyCursor["last_pk_values"] = ["id" => 2];
        $legacyCursor["current_row"] = [
            "id" => 2,
            "data" => ["__binary__" => base64_encode("second")],
        ];
        $legacyCursor["current_row_ends_query_batch"] = false;
        $legacyCursor["current_column_names"] = ["id", "data"];

        $producer = $this->createProducer([
            "batch_size" => 250,
            "cursor" => json_encode($legacyCursor),
        ]);
        $this->assertTrue($producer->next_sql_fragment());
        $legacyRetainedRowFragment = (string) $producer->get_sql_fragment();
        $this->assertStringStartsWith("(", $legacyRetainedRowFragment);
        $this->assertStringContainsString(base64_encode("second"), $legacyRetainedRowFragment);
        $cursorData = json_decode($producer->get_reentrancy_cursor(), true);
        $this->assertArrayNotHasKey("current_row", $cursorData);
        $this->assertSame(["id" => 2], $cursorData["last_pk_values"]);

        $fragments[] = $legacyRetainedRowFragment;
        $producer = $this->createProducer([
            "batch_size" => 250,
            "cursor" => json_encode($cursorData),
        ]);
        $fragments = array_merge($fragments, $this->collectAllFragments($producer));
        $importPdo = $this->executeDumpInNewDatabase(implode("\n", $fragments));
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["legacy_cursor"]);
    }

    /**
     * Test export of exactly one row to test edge cases.
     */
    public function testSingleRowExport(): void
    {
        $this->pdo->exec("
            CREATE TABLE single_row (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO single_row VALUES (1, 'only row')");

        $sql = $this->getDumpSQL();

        // Should have CREATE TABLE and one INSERT
        $this->assertSQLContains("CREATE TABLE", $sql);
        $this->assertSQLContains("INSERT INTO", $sql);
        $this->assertSQLContains("FROM_BASE64", $sql);

        // Verify only one INSERT
        $insertCount = substr_count($sql, "INSERT INTO");
        $this->assertEquals(1, $insertCount);
    }

    /**
     * Test export with exactly batch size rows.
     */
    public function testExactBatchSizeRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE exact_batch (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data INT
            )
        ");

        // Insert exactly 250 rows (one batch)
        $values = [];
        for ($i = 1; $i <= 250; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec(
            "INSERT INTO exact_batch (data) VALUES " . implode(",", $values),
        );

        $sql = $this->getDumpSQL(["batch_size" => 250]);

        // Should have exactly one INSERT
        $insertCount = substr_count($sql, "INSERT INTO");
        $this->assertEquals(1, $insertCount);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM exact_batch")
            ->fetchColumn();
        $this->assertEquals(250, $count);
    }

    // ──────────────────────────────────────────────────
    // Resume edge cases (from ResumeEdgeCasesTest)
    // ──────────────────────────────────────────────────

    /**
     * Test resuming when no more rows exist after pause.
     * Should not leave a dangling comma.
     */
    public function testResumeWithNoMoreRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE resume_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert rows which can disappear after the first open INSERT part.
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO resume_test (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 5]);
        $fragments = [];

        // Process header and CREATE TABLE
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;

            // Stop after the first row opens an INSERT statement.
            if (strpos($sql, 'INSERT INTO `resume_test`') === 0) {
                break;
            }
        }

        // Save the last emitted row position, then remove every possible next row.
        $cursor = $producer->get_reentrancy_cursor();
        $this->pdo->exec("DELETE FROM resume_test WHERE id > 1");

        // Resume from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 5,
            "cursor" => $cursor,
        ]);

        // Collect remaining fragments
        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Verify the complete SQL is valid
        $completeSQL = implode("\n", $fragments);

        // Should not have any dangling commas
        $this->assertStringNotContainsString(",\n\n", $completeSQL);
        $this->assertStringNotContainsString(",\nCOMMIT", $completeSQL);

        // Resume closes the open INSERT without inventing a retained row.
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["resume_test"]);
    }

    /**
     * Test resuming when table is dropped after pause.
     * Should handle gracefully.
     */
    public function testResumeWithDroppedTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE temp_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert rows
        for ($i = 1; $i <= 100; $i++) {
            $this->pdo->exec("INSERT INTO temp_table (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 50]);
        $fragments = [];

        // Process until we've emitted first batch
        $fragmentCount = 0;
        while ($producer->next_sql_fragment() && $fragmentCount < 10) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;
            $fragmentCount++;
        }

        // Get cursor
        $cursor = $producer->get_reentrancy_cursor();

        // Drop the table - simulate table disappearing
        $this->pdo->exec("DROP TABLE temp_table");

        // Create a different table to verify producer can continue with other tables
        $this->pdo->exec("
            CREATE TABLE other_table (
                id INT PRIMARY KEY,
                value VARCHAR(50)
            )
        ");
        $this->pdo->exec("INSERT INTO other_table VALUES (1, 'test')");

        // Resume from cursor - should handle missing table gracefully
        $producer2 = $this->createProducer([
            "batch_size" => 50,
            "cursor" => $cursor,
        ]);

        // The producer detects that the table was dropped (not found in
        // tables_to_process) and gracefully skips to the next table instead
        // of crashing. It should still produce output for other_table.
        $resumedFragments = [];
        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $resumedFragments[] = $sql;
            }
        }

        // Verify the producer continued past the dropped table and
        // processed other_table
        $hasOtherTable = false;
        foreach ($resumedFragments as $fragment) {
            if (str_contains($fragment, 'other_table')) {
                $hasOtherTable = true;
                break;
            }
        }
        $this->assertTrue($hasOtherTable, "Should continue with other tables after dropped table");
    }

    /**
     * Test that INSERT header always includes first row.
     * This prevents dangling "INSERT INTO ... VALUES" with no rows.
     */
    public function testInsertHeaderIncludesFirstRow(): void
    {
        $this->pdo->exec("
            CREATE TABLE first_row_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert just 3 rows
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec("INSERT INTO first_row_test (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 5]);
        $fragments = [];

        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Find the INSERT header fragment
        $insertHeaderFragment = null;
        foreach ($fragments as $fragment) {
            if (strpos($fragment, "INSERT INTO") === 0) {
                $insertHeaderFragment = $fragment;
                break;
            }
        }

        $this->assertNotNull($insertHeaderFragment, "Should have INSERT header");

        // The INSERT header should include at least the first row
        // It should contain at least one opening parenthesis for row data
        $this->assertStringContainsString("INSERT INTO", $insertHeaderFragment);
        $this->assertStringContainsString("VALUES", $insertHeaderFragment);

        // Should have at least one complete row in the header fragment
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($insertHeaderFragment, "("),
            "INSERT header should include first row"
        );
    }

    /**
     * Test cursor resume right before table boundary.
     * Ensures we don't leave dangling SQL when transitioning tables.
     */
    public function testResumeAtTableBoundary(): void
    {
        // Create two tables
        $this->pdo->exec("
            CREATE TABLE table_a (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE table_b (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert data in both
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO table_a (data) VALUES ('a_{$i}')");
            $this->pdo->exec("INSERT INTO table_b (data) VALUES ('b_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 20]);
        $fragments = [];

        // Process until we finish table_a
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;

            // Stop right after finishing table_a's only INSERT batch.
            if (strpos($sql, 'INSERT INTO `table_a`') === 0) {
                break;
            }
        }

        // Get cursor at table boundary
        $cursor = $producer->get_reentrancy_cursor();

        // Resume and process table_b
        $producer2 = $this->createProducer([
            "batch_size" => 20,
            "cursor" => $cursor,
        ]);

        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Verify complete SQL is valid
        $completeSQL = implode("\n", $fragments);

        // Should have both tables
        $this->assertStringContainsString("table_a", $completeSQL);
        $this->assertStringContainsString("table_b", $completeSQL);

        // Verify no syntax errors
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $countA = $importPdo->query("SELECT COUNT(*) FROM table_a")->fetchColumn();
        $countB = $importPdo->query("SELECT COUNT(*) FROM table_b")->fetchColumn();

        $this->assertEquals(10, $countA);
        $this->assertEquals(10, $countB);
    }

    /**
     * Test resume after all data rows consumed but before footer.
     */
    public function testResumeBeforeFooter(): void
    {
        $this->pdo->exec("
            CREATE TABLE final_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO final_table (data) VALUES ('only_row')");

        $producer = $this->createProducer(["batch_size" => 10]);
        $fragments = [];

        // Process everything except footer
        // We'll count fragments and stop after we've seen all data
        $fragmentCount = 0;
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;
            $fragmentCount++;

            // Stop after we've emitted all data (before footer)
            // The footer is typically the last fragment
            // We'll process all but the last 1 fragment
            if ($fragmentCount >= 4) {
                // We've processed header, CREATE TABLE, table comment, INSERT
                // Next would be footer
                break;
            }
        }

        // Get cursor after all data
        $cursor = $producer->get_reentrancy_cursor();

        // Resume to get footer
        $producer2 = $this->createProducer([
            "batch_size" => 10,
            "cursor" => $cursor,
        ]);

        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        $completeSQL = implode("\n", $fragments);

        // Should have complete, valid SQL
        $this->assertStringContainsString("SET @OLD_UNIQUE_CHECKS", $completeSQL);
        $this->assertStringContainsString("COMMIT", $completeSQL);
        $this->assertStringContainsString("FROM_BASE64", $completeSQL);

        // Should be able to import
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $count = $importPdo->query("SELECT COUNT(*) FROM final_table")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    /**
     * A cursor with an oversized_queue entry missing required fields should
     * throw, not silently default to empty/zero values.
     */
    public function testCorruptCursorMissingOversizedQueueFields(): void
    {
        $this->pdo->exec("
            CREATE TABLE corrupt_cursor_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data LONGBLOB
            )
        ");
        $this->pdo->exec("INSERT INTO corrupt_cursor_test (data) VALUES ('x')");

        // Build a cursor with an oversized_queue item that's missing data_type,
        // byte_offset, and total_length. The producer should reject it.
        $cursor = json_encode([
            "current_table" => "corrupt_cursor_test",
            "current_pk_columns" => ["id"],
            "last_pk_values" => ["id" => 1],
            "current_offset" => 0,
            "state" => "emit_oversized_update",
            "current_row" => null,
            "rows_in_batch" => 0,
            "current_column_names" => ["id", "data"],
            "oversized_queue" => [
                ["column" => "data"],  // missing data_type, byte_offset, total_length
            ],
            "oversized_pk_values" => ["id" => 1],
            "state_after_oversized" => "start_insert",
            "current_statement_size" => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("oversized_queue");

        $this->createProducer(["cursor" => $cursor]);
    }

    /**
     * A cursor in STATE_EMIT_OVERSIZED_UPDATE with null state_after_oversized
     * should throw rather than silently fall back to an arbitrary state.
     */
    public function testCorruptCursorNullStateAfterOversized(): void
    {
        $this->pdo->exec("
            CREATE TABLE null_state_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data LONGBLOB
            )
        ");
        $stmt = $this->pdo->prepare("INSERT INTO null_state_test (data) VALUES (?)");
        $stmt->execute([random_bytes(50 * 1024)]);

        // Build a cursor that's in the oversized update state but has no
        // state_after_oversized, simulating a corrupt or hand-edited cursor.
        $cursor = json_encode([
            "current_table" => "null_state_test",
            "current_pk_columns" => ["id"],
            "last_pk_values" => ["id" => 1],
            "current_offset" => 0,
            "state" => "emit_oversized_update",
            "current_row" => null,
            "rows_in_batch" => 0,
            "current_column_names" => ["id", "data"],
            "oversized_queue" => [],
            "oversized_pk_values" => ["id" => 1],
            "state_after_oversized" => null,
            "current_statement_size" => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("state_after_oversized");

        $producer = $this->createProducer(["cursor" => $cursor]);
        $producer->next_sql_fragment();
    }

    // ──────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────

    /**
     * Helper: Insert large dataset in batches.
     */
    private function insertLargeDataset(int $totalRows): void
    {
        $batchSize = 1000;
        $batches = ceil($totalRows / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $values = [];
            $start = $batch * $batchSize + 1;
            $end = min(($batch + 1) * $batchSize, $totalRows);

            for ($i = $start; $i <= $end; $i++) {
                $values[] = "('data_{$i}', {$i})";
            }

            $this->pdo->exec(
                "INSERT INTO large_table (data, value) VALUES " .
                    implode(",", $values),
            );
        }
    }

    /**
     * Helper: Export with reentrancy, resuming every N rows.
     */
    private function exportWithReentrancy(
        int $resumeEveryRows,
        int $batchSize = 250,
    ): array {
        $allFragments = [];
        $producer = $this->createProducer(["batch_size" => $batchSize]);
        $totalRowsProcessed = 0;
        $iterations = 0;

        while (!$producer->is_finished()) {
            $rowsInIteration = 0;
            $fragmentsInIteration = 0;

            while ($producer->next_sql_fragment()) {
                $sql = $producer->get_sql_fragment();

                if ($sql !== null) {
                    $allFragments[] = $sql;
                    $fragmentsInIteration++;

                    if ($this->isInsertRowFragment($sql)) {
                        ++$rowsInIteration;
                    }
                }

                // Resume after processing enough rows
                if ($rowsInIteration >= $resumeEveryRows) {
                    break;
                }
            }

            $totalRowsProcessed += $rowsInIteration;
            $iterations++;

            // Resume from cursor if not finished
            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer([
                    "batch_size" => $batchSize,
                    "cursor" => $cursor,
                ]);
            }
        }

        // Log for debugging
        fwrite(
            STDERR,
            "Completed export in {$iterations} iterations, processed ~{$totalRowsProcessed} rows\n",
        );

        return $allFragments;
    }

    /** Returns whether one producer fragment emits one INSERT row. */
    private function isInsertRowFragment(string $sql): bool
    {
        $trimmed = ltrim( $sql );
        return strpos( $trimmed, "INSERT INTO" ) === 0 ||
            ( $trimmed !== "" && $trimmed[0] === "(" ) ||
            substr( $trimmed, 0, 2 ) === ",(";
    }
}
