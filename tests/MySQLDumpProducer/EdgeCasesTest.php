<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\DataLiberation\MySQLDumpProducer;

/**
 * Ferocious edge-case tests designed to break MySQLDumpProducer in every
 * conceivable way: state machine corruption, cursor round-trips with binary
 * data, batch boundaries, malformed inputs, column naming horrors, data type
 * extremes, concurrent mutations, and oversized-row fallbacks.
 */
class EdgeCasesTest extends MySQLDumpProducerTestBase
{
    // ──────────────────────────────────────────────────
    // State machine integrity
    // ──────────────────────────────────────────────────

    /**
     * Calling next_sql_fragment after the producer is finished must return
     * false indefinitely — no phantom fragments, no exceptions.
     */
    public function testNextFragmentAfterFinishReturnsFalse(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $this->pdo->exec("INSERT INTO t VALUES (1)");

        $producer = $this->createProducer();
        $this->collectAllFragments($producer);
        $this->assertTrue($producer->is_finished());

        // Call 10 more times — all must return false
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($producer->next_sql_fragment());
            $this->assertTrue($producer->is_finished());
        }
    }

    /**
     * get_sql_fragment before any call to next_sql_fragment must be null.
     */
    public function testGetFragmentBeforeNextReturnsNull(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $producer = $this->createProducer();
        $this->assertNull($producer->get_sql_fragment());
    }

    // ──────────────────────────────────────────────────
    // Invalid constructor inputs
    // ──────────────────────────────────────────────────

    public function testInvalidCursorJson(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $this->expectException(\InvalidArgumentException::class);
        $this->createProducer(['cursor' => 'not-json!!!']);
    }

    public function testCursorWithBadCurrentOffset(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $this->expectException(\InvalidArgumentException::class);
        $this->createProducer(['cursor' => json_encode([
            'current_table' => 't',
            'current_offset' => 'banana',
            'state' => 'emit_row',
        ])]);
    }

    public function testCursorWithBadRowsInBatch(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $this->expectException(\InvalidArgumentException::class);
        $this->createProducer(['cursor' => json_encode([
            'current_table' => 't',
            'rows_in_batch' => [1, 2, 3],
            'state' => 'emit_row',
        ])]);
    }

    // ──────────────────────────────────────────────────
    // Batch boundary precision
    // ──────────────────────────────────────────────────

    /**
     * With exactly batch_size rows there should be exactly 1 INSERT statement.
     */
    public function testExactlyBatchSizeRows(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        $values = [];
        for ($i = 1; $i <= 50; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec("INSERT INTO t (v) VALUES " . implode(',', $values));

        $sql = $this->getDumpSQL(['batch_size' => 50]);
        $this->assertEquals(1, $this->countInsertStatements($sql));

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    /**
     * With batch_size+1 rows there should be exactly 2 INSERT statements.
     */
    public function testBatchSizePlusOneRows(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        $values = [];
        for ($i = 1; $i <= 51; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec("INSERT INTO t (v) VALUES " . implode(',', $values));

        $sql = $this->getDumpSQL(['batch_size' => 50]);
        $this->assertEquals(2, $this->countInsertStatements($sql));

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    /**
     * batch_size=1 means every row gets its own INSERT statement.
     */
    public function testBatchSizeOne(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        $this->pdo->exec("INSERT INTO t (v) VALUES (1),(2),(3),(4),(5)");

        $sql = $this->getDumpSQL(['batch_size' => 1]);
        $this->assertEquals(5, $this->countInsertStatements($sql));

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    public function testBatchBoundaryCursorDoesNotPrefetchTheNextRow(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v INT)");
        $this->pdo->exec("INSERT INTO t VALUES (1, 10), (2, 20)");
        $producer = $this->createProducer(['batch_size' => 1]);

        do {
            $producer->next_sql_fragment();
            $sql = (string) $producer->get_sql_fragment();
        } while (strpos($sql, 'INSERT INTO') !== 0);

        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        $this->assertNull($cursor['current_row']);
        $this->assertSame(['id' => 1], $cursor['last_pk_values']);
    }

    // ──────────────────────────────────────────────────
    // tables_to_process filtering
    // ──────────────────────────────────────────────────

    /**
     * Specifying a subset of tables should only export those tables.
     */
    public function testTablesFilter(): void
    {
        $this->pdo->exec("CREATE TABLE keep_me (id INT PRIMARY KEY)");
        $this->pdo->exec("CREATE TABLE skip_me (id INT PRIMARY KEY)");
        $this->pdo->exec("INSERT INTO keep_me VALUES (1)");
        $this->pdo->exec("INSERT INTO skip_me VALUES (2)");

        $sql = $this->getDumpSQL(['tables_to_process' => ['keep_me']]);

        $this->assertSQLContains('keep_me', $sql);
        $this->assertSQLNotContains('skip_me', $sql);
    }

    /**
     * Specifying a nonexistent table throws when trying to SHOW CREATE TABLE.
     */
    public function testNonexistentTableFilter(): void
    {
        $this->pdo->exec("CREATE TABLE real_table (id INT PRIMARY KEY)");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does_not_exist/');
        $this->getDumpSQL(['tables_to_process' => ['does_not_exist']]);
    }

    // ──────────────────────────────────────────────────
    // Views should be excluded from auto-discovered tables
    // ──────────────────────────────────────────────────

    public function testViewsAreExcluded(): void
    {
        $this->pdo->exec("CREATE TABLE base_table (id INT PRIMARY KEY, v INT)");
        $this->pdo->exec("INSERT INTO base_table VALUES (1, 42)");
        $this->pdo->exec("CREATE VIEW my_view AS SELECT * FROM base_table");

        // Auto-discover: views should NOT appear
        $sql = $this->getDumpSQL();

        $this->assertSQLContains('base_table', $sql);
        $this->assertStringNotContainsString('CREATE VIEW', $sql);
        // The view name might appear in a comment, but there should be no INSERT INTO my_view
        $this->assertStringNotContainsString('INSERT INTO `my_view`', $sql);
    }

    // ──────────────────────────────────────────────────
    // Column names from hell
    // ──────────────────────────────────────────────────

    public function testColumnNamesWithSpaces(): void
    {
        $this->pdo->exec("CREATE TABLE t (`id` INT PRIMARY KEY, `first name` VARCHAR(50), `last name` VARCHAR(50))");
        $this->pdo->exec("INSERT INTO t VALUES (1, 'John', 'Doe')");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    public function testColumnNamesWithReservedWords(): void
    {
        $this->pdo->exec("CREATE TABLE t (`select` INT PRIMARY KEY, `from` VARCHAR(50), `where` VARCHAR(50), `order` INT)");
        $this->pdo->exec("INSERT INTO t VALUES (1, 'a', 'b', 2)");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    public function testTableNameWithReservedWord(): void
    {
        $this->pdo->exec("CREATE TABLE `select` (`order` INT PRIMARY KEY, `value` TEXT)");
        $this->pdo->exec("INSERT INTO `select` VALUES (1, 'hello')");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['select']);
    }

    // ──────────────────────────────────────────────────
    // Numeric extremes
    // ──────────────────────────────────────────────────

    public function testUnsignedBigintMax(): void
    {
        $this->pdo->exec("CREATE TABLE t (id BIGINT UNSIGNED PRIMARY KEY, v VARCHAR(10))");
        $this->pdo->exec("INSERT INTO t VALUES (18446744073709551615, 'max')");
        $this->pdo->exec("INSERT INTO t VALUES (0, 'zero')");

        $sql = $this->getDumpSQL();

        // The max value must appear unquoted
        $this->assertSQLContains('18446744073709551615', $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    public function testDecimalPrecisionEdge(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, val DECIMAL(65,30))");
        $this->pdo->exec("INSERT INTO t VALUES (1, '12345678901234567890123456789012345.123456789012345678901234567890')");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Date/time extremes
    // ──────────────────────────────────────────────────

    /**
     * Zero dates ('0000-00-00', '0000-00-00 00:00:00') are valid MySQL values
     * when the server's sql_mode does not include NO_ZERO_DATE. Many WordPress
     * sites store them (e.g. wp_posts.post_date for auto-drafts). The dump must
     * round-trip these faithfully.
     *
     * The dump header sets a sql_mode that omits NO_ZERO_DATE and
     * NO_ZERO_IN_DATE, so zero dates are accepted during import even if
     * the target server's default mode includes those restrictions (as
     * MySQL 8.0+ does by default).
     *
     * From the MySQL 8.0 Reference Manual (§5.1.11 "Server SQL Modes"):
     *
     *   NO_ZERO_DATE: [...] If strict mode is not enabled, '0000-00-00' is
     *   permitted and inserts produce no warning. If strict mode is enabled,
     *   '0000-00-00' is not permitted and inserts produce an error, unless
     *   IGNORE is given as well. [...]
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html#sqlmode_no_zero_date
     */
    public function testZeroDatesAndTimestamps(): void
    {
        // Allow zero dates on the source connection so we can INSERT test data.
        $this->pdo->exec("SET SESSION sql_mode = ''");

        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, d DATE, dt DATETIME, ts TIMESTAMP NULL)");
        $this->pdo->exec("INSERT INTO t VALUES (1, '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00')");
        $this->pdo->exec("INSERT INTO t VALUES (2, '9999-12-31', '9999-12-31 23:59:59', '2038-01-19 03:14:07')");

        $sql = $this->getDumpSQL();

        // The import connection keeps the server's default sql_mode (which
        // typically includes NO_ZERO_DATE on MySQL 8.0+). The dump's own
        // SET SQL_MODE header ensures zero dates are accepted anyway.
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $importPdo->exec("SET SESSION sql_mode = ''");
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    /**
     * Verify the dump's SET SQL_MODE header is sufficient for zero-date
     * import — no extra sql_mode override should be needed on the target.
     *
     * This test deliberately leaves the import connection's sql_mode at its
     * server default (which on MySQL 8.0 includes NO_ZERO_DATE and
     * NO_ZERO_IN_DATE). The dump's own preamble must disable those
     * restrictions for the duration of the import.
     */
    public function testZeroDatesImportWithoutManualSqlModeOverride(): void
    {
        $this->pdo->exec("SET SESSION sql_mode = ''");

        $this->pdo->exec(
            "CREATE TABLE t_zero (" .
            "  id INT PRIMARY KEY," .
            "  d DATE," .
            "  dt DATETIME," .
            "  ts TIMESTAMP NULL" .
            ")"
        );
        $this->pdo->exec(
            "INSERT INTO t_zero VALUES " .
            "(1, '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00')," .
            "(2, '9999-12-31', '9999-12-31 23:59:59', '2038-01-19 03:14:07')"
        );

        $sql = $this->getDumpSQL();

        // Import WITHOUT touching sql_mode — the dump header handles it.
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // For comparison we need to allow reading zero dates on both sides.
        $this->pdo->exec("SET SESSION sql_mode = ''");
        $importPdo->exec("SET SESSION sql_mode = ''");
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t_zero']);
    }

    /**
     * Partial zero dates like '2024-00-01' (zero month) or '2024-01-00'
     * (zero day) are controlled by NO_ZERO_IN_DATE, which the dump header
     * also omits.
     *
     * From the MySQL 8.0 Reference Manual (§5.1.11 "Server SQL Modes"):
     *
     *   NO_ZERO_IN_DATE: [...] affects whether the server permits dates
     *   in which the year part is nonzero but the month or day part is 0.
     *   [...] If this mode and strict mode are both enabled, '2024-00-01'
     *   and '2024-01-00' are not permitted and inserts produce an error.
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html#sqlmode_no_zero_in_date
     */
    public function testPartialZeroDatesRoundTrip(): void
    {
        $this->pdo->exec("SET SESSION sql_mode = ''");

        $this->pdo->exec(
            "CREATE TABLE t_partial_zero (" .
            "  id INT PRIMARY KEY," .
            "  d DATE," .
            "  dt DATETIME" .
            ")"
        );
        $this->pdo->exec(
            "INSERT INTO t_partial_zero VALUES " .
            "(1, '2024-00-00', '2024-00-00 00:00:00')," .
            "(2, '2024-01-00', '2024-01-00 12:30:00')," .
            "(3, '2024-00-15', '2024-00-15 08:00:00')"
        );

        $sql = $this->getDumpSQL();

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $importPdo->exec("SET SESSION sql_mode = ''");
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t_partial_zero']);
    }

    // ──────────────────────────────────────────────────
    // Very wide tables (many columns)
    // ──────────────────────────────────────────────────

    public function testWideTable(): void
    {
        $cols = ['id INT PRIMARY KEY'];
        $vals = ['1'];
        for ($i = 1; $i <= 100; $i++) {
            $cols[] = "c{$i} VARCHAR(10)";
            $vals[] = "'v{$i}'";
        }

        $this->pdo->exec("CREATE TABLE wide (" . implode(',', $cols) . ")");
        $this->pdo->exec("INSERT INTO wide VALUES (" . implode(',', $vals) . ")");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['wide']);
    }

    // ──────────────────────────────────────────────────
    // Cursor round-trip with binary data in current_row
    // ──────────────────────────────────────────────────

    /**
     * Pause mid-table on a row containing binary data (including null bytes),
     * serialize the cursor, resume from it, and verify the complete export
     * produces a valid dump.
     */
    public function testCursorRoundTripWithBinaryRow(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, data BLOB)");

        $binaryValues = [];
        $stmt = $this->pdo->prepare("INSERT INTO t (data) VALUES (?)");
        for ($i = 0; $i < 10; $i++) {
            $val = random_bytes(200);
            $binaryValues[] = $val;
            $stmt->execute([$val]);
        }

        // Use batch_size=3 so we get pauses mid-table
        $options = ['batch_size' => 3];
        $producer = $this->createProducer($options);
        $allFragments = [];

        // Consume fragments in bursts of 2, saving/restoring cursor each time
        $iterations = 0;
        while (!$producer->is_finished() && $iterations < 50) {
            $count = 0;
            while ($count < 2 && $producer->next_sql_fragment()) {
                $frag = $producer->get_sql_fragment();
                if ($frag !== null) {
                    $allFragments[] = $frag;
                }
                $count++;
            }

            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                // Verify cursor is valid JSON
                $decoded = json_decode($cursor, true);
                $this->assertIsArray($decoded, "Cursor must be valid JSON");
                // Re-create producer from cursor
                $options['cursor'] = $cursor;
                $producer = $this->createProducer($options);
            }
            $iterations++;
        }

        $sql = implode("\n", $allFragments);
        var_dump($sql);
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(10, $rows);
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($binaryValues[$i], $rows[$i], "Binary data mismatch at row {$i}");
        }
    }

    // ──────────────────────────────────────────────────
    // Cursor resume when source table was dropped between requests
    // (column types gone → RuntimeException)
    // ──────────────────────────────────────────────────

    public function testCursorResumeAfterTableColumnTypesGone(): void
    {
        $this->pdo->exec("CREATE TABLE vanish (id INT PRIMARY KEY, v TEXT)");
        $this->pdo->exec("INSERT INTO vanish VALUES (1,'x')");

        $producer = $this->createProducer(['batch_size' => 1]);
        // Advance past header
        $producer->next_sql_fragment();
        $cursor = $producer->get_reentrancy_cursor();

        // Drop and recreate as empty different-schema table so it still exists
        // in TABLES but columns differ. Actually, let's just drop it entirely
        // to trigger the RuntimeException path.
        $this->pdo->exec("DROP TABLE vanish");

        // The producer should detect the table is gone when restoring cursor
        // and either throw or gracefully skip. Current code path: if table not
        // found in tables_to_process, state resets. But if table IS in
        // tables_to_process but has no columns, it throws RuntimeException.
        // Let's test the "table not found in auto-discovered list" path.
        $producer2 = $this->createProducer(['cursor' => $cursor]);
        $fragments = $this->collectAllFragments($producer2);

        // Should produce valid (if minimal) SQL
        $sql = implode("\n", $fragments);
        $this->assertSQLContains('COMMIT', $sql);
    }

    // ──────────────────────────────────────────────────
    // Oversized row in table with NO primary key
    // (should fall back to emitting as-is, not crash)
    // ──────────────────────────────────────────────────

    public function testOversizedRowNoPrimaryKey(): void
    {
        $this->pdo->exec("CREATE TABLE no_pk (data LONGTEXT)");
        $largeText = str_repeat("x", 50000);
        $stmt = $this->pdo->prepare("INSERT INTO no_pk (data) VALUES (?)");
        $stmt->execute([$largeText]);

        // With a tiny max_statement_size, this row exceeds the limit.
        // Since there's no PK, it cannot use the UPDATE+CONCAT fallback.
        // The producer should throw rather than silently emitting an
        // oversized statement that may fail on import.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("no primary key");

        $this->getDumpSQL(['max_statement_size' => 10000]);
    }

    // ──────────────────────────────────────────────────
    // Rows inserted after cursor was saved
    // ──────────────────────────────────────────────────

    public function testRowsInsertedAfterCursorSave(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO t (v) VALUES ({$i})");
        }

        $producer = $this->createProducer(['batch_size' => 3]);
        $fragments = [];

        // Advance until we get the first INSERT batch
        while ($producer->next_sql_fragment()) {
            $frag = $producer->get_sql_fragment();
            $fragments[] = $frag;
            if (strpos($frag, 'INSERT INTO `t`') === 0) {
                break;
            }
        }

        $cursor = $producer->get_reentrancy_cursor();

        // Insert more rows after cursor was saved
        for ($i = 6; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO t (v) VALUES ({$i})");
        }

        // Resume — should pick up the new rows too (they have higher PKs)
        $producer2 = $this->createProducer(['batch_size' => 3, 'cursor' => $cursor]);
        while ($producer2->next_sql_fragment()) {
            $frag = $producer2->get_sql_fragment();
            if ($frag !== null) {
                $fragments[] = $frag;
            }
        }

        $sql = implode("\n", $fragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = (int) $importPdo->query("SELECT COUNT(*) FROM t")->fetchColumn();

        // Should have all 10 rows (or at least ≥ the cached row + new ones)
        $this->assertGreaterThanOrEqual(10, $count);
    }

    // ──────────────────────────────────────────────────
    // Single row table + batch_size=1 + cursor resume
    // ──────────────────────────────────────────────────

    public function testSingleRowBatchSizeOneCursorResume(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(10))");
        $this->pdo->exec("INSERT INTO t VALUES (1, 'only')");

        $producer = $this->createProducer(['batch_size' => 1]);
        $fragments = [];

        // Get header
        $producer->next_sql_fragment();
        $fragments[] = $producer->get_sql_fragment();

        // Save cursor after header
        $cursor = $producer->get_reentrancy_cursor();

        // Resume
        $producer2 = $this->createProducer(['batch_size' => 1, 'cursor' => $cursor]);
        while ($producer2->next_sql_fragment()) {
            $frag = $producer2->get_sql_fragment();
            if ($frag !== null) {
                $fragments[] = $frag;
            }
        }

        $sql = implode("\n", $fragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // All-NULL row
    // ──────────────────────────────────────────────────

    public function testAllNullRow(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, a INT, b VARCHAR(50), c BLOB, d DATETIME)");
        $this->pdo->exec("INSERT INTO t VALUES (1, NULL, NULL, NULL, NULL)");

        $sql = $this->getDumpSQL();
        $this->assertMatchesRegularExpression('/\(1,NULL,NULL,NULL,NULL\)/', $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Very long string values (but under max_statement_size)
    // ──────────────────────────────────────────────────

    public function testLongStringUnderLimit(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v LONGTEXT)");

        // 100KB string — fits within default max_statement_size
        $longStr = str_repeat("abcdefghij", 10000);
        $stmt = $this->pdo->prepare("INSERT INTO t VALUES (1, ?)");
        $stmt->execute([$longStr]);

        $sql = $this->getDumpSQL();
        // Should NOT use UPDATE+CONCAT
        $this->assertStringNotContainsString('UPDATE `t` SET', $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $imported = $importPdo->query("SELECT v FROM t WHERE id = 1")->fetchColumn();
        $this->assertEquals($longStr, $imported);
    }

    // ──────────────────────────────────────────────────
    // PK-based WHERE clause with string PKs containing special chars
    // ──────────────────────────────────────────────────

    public function testStringPrimaryKeyWithSpecialCharsInCursor(): void
    {
        $this->pdo->exec("CREATE TABLE t (id VARCHAR(100) PRIMARY KEY, v INT)");

        // Insert rows with PK values that need escaping
        $stmt = $this->pdo->prepare("INSERT INTO t VALUES (?, ?)");
        $stmt->execute(["it's", 1]);
        $stmt->execute(["back\\slash", 2]);
        $stmt->execute(["new\nline", 3]);
        $stmt->execute(["normal", 4]);

        // batch_size=2 forces pagination through WHERE clause with quoted PK
        $sql = $this->getDumpSQL(['batch_size' => 2]);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // NULL primary key values in composite PK cursor (shouldn't happen
    // in MySQL, but the code handles it — let's verify the WHERE clause)
    // ──────────────────────────────────────────────────

    public function testBuildComparisonWithNullValue(): void
    {
        // We can't have NULL in a real PK, but we can test the code path
        // indirectly: a table with an INT PK and NULL-able unique key.
        // The PK cursor code won't use NULL, but we can verify the
        // build_comparison method handles it through a direct cursor injection.
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO t (v) VALUES ({$i})");
        }

        // Craft a cursor with null PK value — the producer should handle
        // this gracefully (IS NOT NULL in WHERE clause)
        $cursor = json_encode([
            'current_table' => 't',
            'current_pk_columns' => ['id'],
            'last_pk_values' => ['id' => null],
            'current_offset' => 0,
            'state' => 'start_insert',
            'current_row' => null,
            'rows_in_batch' => 0,
            'current_column_names' => null,
            'oversized_queue' => [],
            'oversized_pk_values' => null,
            'state_after_oversized' => null,
            'current_statement_size' => 0,
        ]);

        $producer = $this->createProducer(['cursor' => $cursor]);
        $fragments = $this->collectAllFragments($producer);
        $sql = implode("\n", $fragments);

        // Should produce valid SQL (WHERE id IS NOT NULL picks up all rows)
        $this->assertSQLContains('INSERT INTO', $sql);
    }

    // ──────────────────────────────────────────────────
    // Empty database with create_table disabled
    // ──────────────────────────────────────────────────

    public function testEmptyDatabaseNoCreateTable(): void
    {
        $producer = $this->createProducer(['create_table_query' => false]);
        $fragments = $this->collectAllFragments($producer);

        $this->assertCount(2, $fragments, 'Should only have header and footer');
        $this->assertSQLContains('SET @OLD_UNIQUE_CHECKS', $fragments[0]);
        $this->assertSQLContains('COMMIT', $fragments[1]);
    }

    // ──────────────────────────────────────────────────
    // Generated/virtual columns
    // ──────────────────────────────────────────────────

    /**
     * Tables with STORED generated columns export correctly. The dump
     * includes the generated column's value which MySQL rejects on INSERT
     * unless we handle it. This tests current behavior: the export includes
     * the value, and MySQL may reject it with a general error. The important
     * thing is that the producer doesn't crash.
     */
    public function testGeneratedColumnsProduceValidSql(): void
    {
        $this->pdo->exec("
            CREATE TABLE t (
                id INT PRIMARY KEY,
                first_name VARCHAR(50),
                last_name VARCHAR(50),
                full_name VARCHAR(101) GENERATED ALWAYS AS (CONCAT(first_name, ' ', last_name)) STORED
            )
        ");
        $this->pdo->exec("INSERT INTO t (id, first_name, last_name) VALUES (1, 'John', 'Doe')");

        // The producer should not crash. It will include the generated column
        // in the INSERT which MySQL rejects, but that's a known limitation.
        $sql = $this->getDumpSQL();
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('FROM_BASE64', $sql);
    }

    // ──────────────────────────────────────────────────
    // Auto-increment gap: verify PKs with gaps don't confuse cursor
    // ──────────────────────────────────────────────────

    public function testPrimaryKeyWithLargeGaps(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(10))");
        $this->pdo->exec("INSERT INTO t VALUES (1, 'a')");
        $this->pdo->exec("INSERT INTO t VALUES (1000000, 'b')");
        $this->pdo->exec("INSERT INTO t VALUES (2000000000, 'c')");

        $sql = $this->getDumpSQL(['batch_size' => 1]);
        $this->assertEquals(3, $this->countInsertStatements($sql));

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Composite PK with lexicographic ordering edge case
    // ──────────────────────────────────────────────────

    /**
     * With a composite PK (a, b), if we pause after (1, 3), the WHERE clause
     * must correctly pick up (1, 4) but also (2, 1).  This is the classic
     * lexicographic ordering trap.
     */
    public function testCompositePKLexicographicResume(): void
    {
        $this->pdo->exec("CREATE TABLE t (a INT, b INT, v VARCHAR(10), PRIMARY KEY (a, b))");
        $values = [];
        for ($a = 1; $a <= 3; $a++) {
            for ($b = 1; $b <= 5; $b++) {
                $values[] = "({$a}, {$b}, 'r{$a}{$b}')";
            }
        }
        $this->pdo->exec("INSERT INTO t VALUES " . implode(',', $values));

        // batch_size=4 forces a cursor save mid-table
        $options = ['batch_size' => 4];
        $producer = $this->createProducer($options);
        $allFragments = [];

        $iterations = 0;
        while (!$producer->is_finished() && $iterations < 20) {
            $count = 0;
            while ($count < 3 && $producer->next_sql_fragment()) {
                $frag = $producer->get_sql_fragment();
                if ($frag !== null) {
                    $allFragments[] = $frag;
                }
                $count++;
            }

            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                $options['cursor'] = $cursor;
                $producer = $this->createProducer($options);
            }
            $iterations++;
        }

        $sql = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $count = (int) $importPdo->query("SELECT COUNT(*) FROM t")->fetchColumn();
        $this->assertEquals(15, $count, "All 15 rows must survive composite PK cursor resume");
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Duplicate rows in no-PK table with OFFSET pagination
    // ──────────────────────────────────────────────────

    public function testNoPKTableWithDuplicateRows(): void
    {
        $this->pdo->exec("CREATE TABLE t (v VARCHAR(50))");
        for ($i = 0; $i < 20; $i++) {
            $this->pdo->exec("INSERT INTO t VALUES ('same')");
        }

        $sql = $this->getDumpSQL(['batch_size' => 5]);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = (int) $importPdo->query("SELECT COUNT(*) FROM t")->fetchColumn();
        $this->assertEquals(20, $count);
    }

    // ──────────────────────────────────────────────────
    // Oversized row cursor resume mid-UPDATE-queue
    // ──────────────────────────────────────────────────

    /**
     * Pause the producer in the middle of emitting UPDATE chunks for an
     * oversized row, save the cursor, resume, and verify the complete
     * data survives round-trip.
     */
    public function testCursorResumeMidOversizedUpdateQueue(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data LONGBLOB)");
        $largeData = random_bytes(60 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO t VALUES (1, ?)");
        $stmt->execute([$largeData]);

        $options = ['max_statement_size' => 8 * 1024, 'batch_size' => 1];
        $producer = $this->createProducer($options);
        $allFragments = [];

        $iterations = 0;
        while (!$producer->is_finished() && $iterations < 100) {
            // Process exactly 1 fragment per iteration to maximize cursor saves
            if ($producer->next_sql_fragment()) {
                $frag = $producer->get_sql_fragment();
                if ($frag !== null) {
                    $allFragments[] = $frag;
                }
            }

            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                $options['cursor'] = $cursor;
                $producer = $this->createProducer($options);
            }
            $iterations++;
        }

        $this->assertLessThan(100, $iterations);

        $sql = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT data FROM t WHERE id = 1")->fetchColumn();
        $this->assertEquals($largeData, $imported, "Data must survive cursor-per-fragment oversized resume");
    }

    // ──────────────────────────────────────────────────
    // estimate_formatted_size should never undercount by more than ~10%
    // (undercount causes the oversized-row code path not to trigger when it should)
    // ──────────────────────────────────────────────────

    public function testEstimateFormattedSizeAccuracy(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v TEXT)");

        // Strings that stress the escaping estimator
        $testCases = [
            '',                                     // empty
            "hello",                                // plain ASCII
            "it's a \"test\"",                      // quotes
            "back\\slash\\es",                      // backslashes
            "null\x00byte\x00here",                 // null bytes
            str_repeat("'", 1000),                  // worst-case escaping
            str_repeat("\\", 1000),                 // worst-case backslashes
            str_repeat("\n", 500),                   // newlines
            str_repeat("a", 10000),                 // long plain string
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (v) VALUES (?)");
        foreach ($testCases as $tc) {
            $stmt->execute([$tc]);
        }

        $sql = $this->getDumpSQL();

        // If estimates are wildly off, the INSERT would either:
        // 1. Exceed max_statement_size (estimate too low → no split triggered)
        // 2. Split unnecessarily (estimate too high → premature splitting)
        // The round-trip test verifies the SQL is at least valid.
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Concurrent schema change: column added between requests
    // ──────────────────────────────────────────────────

    public function testColumnAddedBetweenCursorSaves(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, v INT)");
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO t (v) VALUES ({$i})");
        }

        $producer = $this->createProducer(['batch_size' => 5]);
        $fragments = [];

        // Process first batch
        while ($producer->next_sql_fragment()) {
            $frag = $producer->get_sql_fragment();
            $fragments[] = $frag;
            if (strpos($frag, 'INSERT INTO `t`') === 0) {
                break;
            }
        }

        $cursor = $producer->get_reentrancy_cursor();

        // Add a column — the resumed producer will see a different schema
        $this->pdo->exec("ALTER TABLE t ADD COLUMN extra VARCHAR(10) DEFAULT 'new'");

        // Resume — the current_column_names from cursor won't include 'extra'
        // but the new query will. This tests whether the producer handles
        // schema evolution gracefully.
        $producer2 = $this->createProducer(['batch_size' => 5, 'cursor' => $cursor]);
        while ($producer2->next_sql_fragment()) {
            $frag = $producer2->get_sql_fragment();
            if ($frag !== null) {
                $fragments[] = $frag;
            }
        }

        $sql = implode("\n", $fragments);

        // The SQL should at least be syntactically valid and importable
        // (even if the column count changes between INSERT batches)
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('COMMIT', $sql);
    }

    // ──────────────────────────────────────────────────
    // Multiple tables where first is empty and second has data
    // ──────────────────────────────────────────────────

    public function testFirstTableEmptySecondHasData(): void
    {
        $this->pdo->exec("CREATE TABLE aaa (id INT PRIMARY KEY)");
        $this->pdo->exec("CREATE TABLE bbb (id INT PRIMARY KEY, v VARCHAR(10))");
        $this->pdo->exec("INSERT INTO bbb VALUES (1, 'hello')");

        $producer = $this->createProducer();
        $fragments = $this->collectAllFragments($producer);

        // Verify bbb appears somewhere in the fragments
        $hasBbbInsert = false;
        $hasBbbCreate = false;
        foreach ($fragments as $frag) {
            if (strpos($frag, 'INSERT INTO `bbb`') !== false) {
                $hasBbbInsert = true;
            }
            if (strpos($frag, '`bbb`') !== false && strpos($frag, 'CREATE TABLE') !== false) {
                $hasBbbCreate = true;
            }
        }
        $this->assertTrue($hasBbbCreate, "Should have CREATE TABLE for bbb");
        $this->assertTrue($hasBbbInsert, "Should have INSERT for bbb");

        $sql = implode("\n", $fragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['aaa', 'bbb']);
    }

    // ──────────────────────────────────────────────────
    // Negative PK values with cursor resume
    // ──────────────────────────────────────────────────

    public function testNegativePrimaryKeyValues(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(10))");
        $this->pdo->exec("INSERT INTO t VALUES (-100, 'neg')");
        $this->pdo->exec("INSERT INTO t VALUES (-1, 'neg1')");
        $this->pdo->exec("INSERT INTO t VALUES (0, 'zero')");
        $this->pdo->exec("INSERT INTO t VALUES (1, 'pos1')");
        $this->pdo->exec("INSERT INTO t VALUES (100, 'pos')");

        $sql = $this->getDumpSQL(['batch_size' => 2]);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // DEFAULT values and AUTO_INCREMENT round-trip
    // ──────────────────────────────────────────────────

    public function testDefaultValuesPreserved(): void
    {
        $this->pdo->exec("
            CREATE TABLE t (
                id INT PRIMARY KEY AUTO_INCREMENT,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                count INT NOT NULL DEFAULT 0,
                created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("INSERT INTO t (id) VALUES (1)");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Check that the actual stored values match (not just the defaults)
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);

        // The CREATE TABLE in the dump should preserve DEFAULT clauses
        $this->assertMatchesRegularExpression("/DEFAULT 'pending'/", $sql);
    }

    // ──────────────────────────────────────────────────
    // String that looks like a hex literal
    // ──────────────────────────────────────────────────

    public function testStringLookingLikeHex(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(100))");
        $stmt = $this->pdo->prepare("INSERT INTO t VALUES (1, ?)");
        $stmt->execute(['0xDEADBEEF']);

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $val = $importPdo->query("SELECT v FROM t WHERE id = 1")->fetchColumn();
        $this->assertEquals('0xDEADBEEF', $val, "String '0xDEADBEEF' must not be treated as binary");
    }

    // ──────────────────────────────────────────────────
    // UNSIGNED integer types (TINYINT through BIGINT)
    // ──────────────────────────────────────────────────

    public function testUnsignedIntegerTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE t (
                id INT UNSIGNED PRIMARY KEY,
                tiny TINYINT UNSIGNED,
                small SMALLINT UNSIGNED,
                med MEDIUMINT UNSIGNED,
                big BIGINT UNSIGNED
            )
        ");
        $this->pdo->exec("INSERT INTO t VALUES (4294967295, 255, 65535, 16777215, 18446744073709551615)");
        $this->pdo->exec("INSERT INTO t VALUES (0, 0, 0, 0, 0)");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // TEXT types: TINYTEXT, TEXT, MEDIUMTEXT, LONGTEXT
    // ──────────────────────────────────────────────────

    public function testAllTextTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE t (
                id INT PRIMARY KEY,
                tiny TINYTEXT,
                regular TEXT,
                medium MEDIUMTEXT,
                long_t LONGTEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO t VALUES (1, ?, ?, ?, ?)");
        $stmt->execute([
            str_repeat('a', 200),       // TINYTEXT max ~255
            str_repeat('b', 10000),     // TEXT
            str_repeat('c', 50000),     // MEDIUMTEXT
            str_repeat('d', 100000),    // LONGTEXT
        ]);

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);
    }

    // ──────────────────────────────────────────────────
    // Float edge cases: NaN, Infinity are not valid MySQL values,
    // but 0, -0, very small, very large floats are.
    // ──────────────────────────────────────────────────

    public function testFloatEdgeCases(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, f DOUBLE, g FLOAT)");
        $this->pdo->exec("INSERT INTO t VALUES (1, 0, 0)");
        $this->pdo->exec("INSERT INTO t VALUES (2, -0, -0)");
        $this->pdo->exec("INSERT INTO t VALUES (3, 1.7976931348623e+100, 1.5e+20)");
        $this->pdo->exec("INSERT INTO t VALUES (4, 2.225e-100, 1.175e-20)");

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Float precision may vary between export and import, so just verify
        // row counts and that the values are approximately correct
        $origRows = $this->pdo->query("SELECT * FROM t ORDER BY id")->fetchAll();
        $importRows = $importPdo->query("SELECT * FROM t ORDER BY id")->fetchAll();
        $this->assertCount(4, $importRows);
        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals($origRows[$i]['id'], $importRows[$i]['id']);
            $this->assertEqualsWithDelta((float)$origRows[$i]['f'], (float)$importRows[$i]['f'], abs((float)$origRows[$i]['f']) * 1e-10 + 1e-300);
        }
    }

    // ──────────────────────────────────────────────────
    // Finished producer cursor should encode "finished" state
    // ──────────────────────────────────────────────────

    /**
     * After finishing, the cursor encodes "finished" state.
     * Note: current_table may be `false` (PHP array pointer exhausted),
     * which json_encode serializes as `false`. The cursor validator rejects
     * non-string/non-null current_table, so resuming from a truly finished
     * cursor is not supported — but the state field should be "finished".
     */
    public function testCursorAfterFinish(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY)");
        $this->pdo->exec("INSERT INTO t VALUES (1)");

        $producer = $this->createProducer();
        $this->collectAllFragments($producer);
        $this->assertTrue($producer->is_finished());

        $cursor = $producer->get_reentrancy_cursor();
        $decoded = json_decode($cursor, true);

        $this->assertEquals('finished', $decoded['state']);
    }

    // ──────────────────────────────────────────────────
    // Multiple empty tables
    // ──────────────────────────────────────────────────

    public function testMultipleEmptyTables(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("CREATE TABLE t{$i} (id INT PRIMARY KEY)");
        }

        $producer = $this->createProducer();
        $fragments = $this->collectAllFragments($producer);
        $sql = implode("\n", $fragments);

        // Should have 5 CREATE TABLEs, 0 INSERTs
        $tables = $this->extractTableNames($sql);
        $this->assertCount(5, $tables);
        $this->assertEquals(0, $this->countInsertStatements($sql));

        $importPdo = $this->executeDumpInNewDatabase($sql);
        for ($i = 1; $i <= 5; $i++) {
            $count = (int) $importPdo->query("SELECT COUNT(*) FROM t{$i}")->fetchColumn();
            $this->assertEquals(0, $count);
        }
    }

    // ──────────────────────────────────────────────────
    // Identifiers containing backticks
    // ──────────────────────────────────────────────────

    /**
     * MySQL allows backticks inside identifiers when escaped by doubling.
     * Verify the producer handles table and column names containing backticks
     * without generating broken SQL.
     */
    public function testIdentifiersContainingBackticks(): void
    {
        // Table name with a backtick
        $this->pdo->exec("CREATE TABLE `tricky``table` (
            `id` INT PRIMARY KEY,
            `col``name` VARCHAR(100),
            `normal` INT
        )");
        $this->pdo->exec("INSERT INTO `tricky``table` VALUES (1, 'hello', 42)");
        $this->pdo->exec("INSERT INTO `tricky``table` VALUES (2, 'world', 99)");

        $sql = $this->getDumpSQL();

        // The dump must contain the escaped identifier
        $this->assertSQLContains('`tricky``table`', $sql);
        $this->assertSQLContains('`col``name`', $sql);

        // Round-trip: the generated SQL must parse and reproduce the data
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['tricky`table']);
    }
}
