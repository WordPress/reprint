<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

use Reprint\Importer\Database\PdoDatabaseConnection;
use WordPress\Reprint\Server\DatabaseRowsReader;
use WordPress\Reprint\Server\MySQLDumpProducer;

/**
 * Tests MySQL dump with rows larger than max_allowed_packet.
 *
 * These tests verify that large BLOB/TEXT columns are properly split into
 * multiple UPDATE statements when they would exceed max_statement_size.
 */
class OversizedRowsTest extends MySQLDumpProducerTestBase
{
    /**
     * Test that a small row is exported normally without splitting.
     */
    public function testSmallRowNotSplit(): void
    {
        $this->pdo->exec("
            CREATE TABLE small_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert 1KB of data - should not trigger splitting
        $smallData = random_bytes(1024);
        $stmt = $this->pdo->prepare("INSERT INTO small_data (content) VALUES (?)");
        $stmt->execute([$smallData]);

        // Use a large max_statement_size so nothing gets split
        $sql = $this->getDumpSQL(['max_statement_size' => 1024 * 1024]);

        // Should not contain an oversized-value UPDATE statement.
        $this->assertStringNotContainsString('UPDATE `small_data` SET', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['small_data']);
    }

    /**
     * Test that a large row is split into INSERT + UPDATE statements.
     */
    public function testLargeRowSplitIntoUpdates(): void
    {
        $this->pdo->exec("
            CREATE TABLE large_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert 100KB of data with a 10KB max_statement_size
        // This should trigger splitting
        $largeData = random_bytes(100 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO large_data (content) VALUES (?)");
        $stmt->execute([$largeData]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Should contain UPDATE statements with CONCAT
        $this->assertStringContainsString('UPDATE `large_data` SET', $sql);
        $this->assertStringContainsString('CONCAT', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify data integrity
        $imported = $importPdo->query("SELECT content FROM large_data WHERE id = 1")->fetchColumn();
        $this->assertEquals(strlen($largeData), strlen($imported), 'Large blob size should match');
        $this->assertEquals($largeData, $imported, 'Large blob content should match');
    }

    /**
     * Test that large TEXT columns are handled correctly.
     */
    public function testLargeTextColumnSplit(): void
    {
        $this->pdo->exec("
            CREATE TABLE large_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGTEXT
            )
        ");

        // Insert 50KB of text data
        $largeText = str_repeat("Hello World! This is test data. ", 2000);
        $stmt = $this->pdo->prepare("INSERT INTO large_text (content) VALUES (?)");
        $stmt->execute([$largeText]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Should contain UPDATE statements
        $this->assertStringContainsString('UPDATE `large_text` SET', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT content FROM large_text WHERE id = 1")->fetchColumn();
        $this->assertEquals($largeText, $imported, 'Large text content should match');
    }

    /**
     * @dataProvider oversizedCharacterColumnProvider
     */
    public function testLargeMultibyteCharacterColumnRoundTrips(
        string $table,
        string $column_type
    ): void {
        $this->pdo->exec("
            CREATE TABLE `{$table}` (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content {$column_type} CHARACTER SET utf8mb4
            )
        ");

        $large_text = str_repeat("\xF0\x9F\x99\x82", 8000);
        $statement = $this->pdo->prepare(
            "INSERT INTO `{$table}` (content) VALUES (?)"
        );
        $statement->execute([$large_text]);

        $max_statement_size = 10 * 1024;
        $fragments = $this->collectFragmentsWithinSteps(
            $this->createProducer(['max_statement_size' => $max_statement_size]),
            100
        );

        $this->assertStringContainsString(
            "UPDATE `{$table}` SET",
            implode("\n", $fragments)
        );
        foreach ($fragments as $fragment) {
            $this->assertLessThanOrEqual(
                $max_statement_size,
                strlen($fragment),
                'Every emitted SQL fragment must fit max_statement_size.'
            );
        }

        $import_pdo = $this->executeDumpInNewDatabase(implode("\n", $fragments));
        $imported = $import_pdo
            ->query("SELECT content FROM `{$table}` WHERE id = 1")
            ->fetchColumn();
        $this->assertSame($large_text, $imported);
    }

    public static function oversizedCharacterColumnProvider(): array
    {
        return [
            'varchar' => ['oversized_varchar', 'VARCHAR(10000)'],
            'text' => ['oversized_text', 'TEXT'],
            'mediumtext' => ['oversized_mediumtext', 'MEDIUMTEXT'],
            'longtext' => ['oversized_longtext', 'LONGTEXT'],
        ];
    }

    public function testLargeMultibyteTextResumesAfterEveryFragment(): void
    {
        $this->pdo->exec("
            CREATE TABLE resumed_multibyte_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGTEXT CHARACTER SET utf8mb4
            )
        ");

        $large_text = str_repeat("A\xC3\xA9\xE6\xBC\xA2\xF0\x9F\x99\x82", 4000);
        $statement = $this->pdo->prepare(
            "INSERT INTO resumed_multibyte_text (content) VALUES (?)"
        );
        $statement->execute([$large_text]);

        $options = [
            'max_statement_size' => 10 * 1024,
            'batch_size' => 1,
        ];
        $producer = $this->createProducer($options);
        $fragments = [];
        for ($step = 0; $step < 100; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $fragments[] = $producer->get_sql_fragment();
            if (!$producer->is_finished()) {
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer($options);
            }
        }

        $this->assertTrue(
            $producer->is_finished(),
            'The resumed exporter must finish instead of requesting text past its final character.'
        );
        $import_pdo = $this->executeDumpInNewDatabase(implode("\n", $fragments));
        $imported = $import_pdo
            ->query("SELECT content FROM resumed_multibyte_text WHERE id = 1")
            ->fetchColumn();
        $this->assertSame($large_text, $imported);
    }

    public function testOldOversizedTextCursorWithProgressRequiresRestart(): void
    {
        $this->pdo->exec("
            CREATE TABLE old_oversized_text_cursor (
                id INT PRIMARY KEY,
                content LONGTEXT
            )
        ");
        $statement = $this->pdo->prepare(
            "INSERT INTO old_oversized_text_cursor VALUES (1, ?)"
        );
        $statement->execute([str_repeat('a', 30 * 1024)]);

        $options = ['max_statement_size' => 10 * 1024];
        $producer = $this->createProducer($options);
        do {
            $this->assertTrue($producer->next_sql_fragment());
        } while (strpos($producer->get_sql_fragment(), 'UPDATE `old_oversized_text_cursor`') !== 0);

        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        unset($cursor['oversized_queue'][0]['character_offset']);
        $options['cursor'] = json_encode($cursor);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('earlier oversized text format');
        $this->createProducer($options);
    }

    /**
     * @dataProvider oversizedBinaryColumnProvider
     */
    public function testLargeBinaryColumnStillRoundTrips(
        string $table,
        string $column_type
    ): void {
        $this->pdo->exec("
            CREATE TABLE `{$table}` (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content {$column_type}
            )
        ");

        $large_value = random_bytes(30 * 1024);
        $statement = $this->pdo->prepare(
            "INSERT INTO `{$table}` (content) VALUES (?)"
        );
        $statement->execute([$large_value]);

        $sql = implode("\n", $this->collectFragmentsWithinSteps(
            $this->createProducer(['max_statement_size' => 10 * 1024]),
            100
        ));
        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $imported = $import_pdo
            ->query("SELECT content FROM `{$table}` WHERE id = 1")
            ->fetchColumn();
        $this->assertSame($large_value, $imported);
    }

    public static function oversizedBinaryColumnProvider(): array
    {
        return [
            'varbinary' => ['oversized_varbinary', 'VARBINARY(40000)'],
            'blob' => ['oversized_blob', 'BLOB'],
            'mediumblob' => ['oversized_mediumblob', 'MEDIUMBLOB'],
            'longblob' => ['oversized_longblob', 'LONGBLOB'],
        ];
    }

    public function testOversizedTextStopsWhenTheSourceValueShrinks(): void
    {
        $this->pdo->exec("
            CREATE TABLE shrinking_oversized_text (
                id INT PRIMARY KEY,
                content LONGTEXT
            )
        ");
        $statement = $this->pdo->prepare(
            "INSERT INTO shrinking_oversized_text VALUES (1, ?)"
        );
        $statement->execute([str_repeat('a', 30 * 1024)]);

        $producer = $this->createProducer(['max_statement_size' => 10 * 1024]);
        do {
            $this->assertTrue($producer->next_sql_fragment());
            $fragment = $producer->get_sql_fragment();
        } while (strpos($fragment, 'INSERT INTO `shrinking_oversized_text`') !== 0);

        $this->pdo->exec(
            "UPDATE shrinking_oversized_text SET content = '' WHERE id = 1"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('returned an empty chunk at byte offset 0');
        $producer->next_sql_fragment();
    }

    /**
     * Geometry bytes use durable binary staging until the complete value is valid.
     *
     * @dataProvider oversizedSpatialColumnProvider
     */
    public function testOversizedGeometryRoundTripsAcrossBoundedStatements(
        string $column_type,
        string $wkt_format
    ): void
    {
        $this->pdo->exec("
            CREATE TABLE oversized_geometry_value (
                id INT PRIMARY KEY,
                content {$column_type} NOT NULL,
                SPATIAL INDEX content_index (content)
            )
        ");
        $points = [];
        for ($point = 0; $point < 3000; ++$point) {
            $latitude = ($point % 179) - 89;
            $longitude = ($point % 359) - 179;
            $points[] = $latitude . ' ' . $longitude;
        }
        $coordinates = implode(',', $points);
        $closed_coordinates = $coordinates . ',' . $points[0];
        $wkt = sprintf($wkt_format, $coordinates, $closed_coordinates);
        $statement = $this->pdo->prepare(
            "INSERT INTO oversized_geometry_value VALUES (1, ST_GeomFromText(?, 4326))"
        );
        $statement->execute([$wkt]);

        $source_value = $this->pdo
            ->query('SELECT CAST(content AS BINARY) FROM oversized_geometry_value')
            ->fetchColumn();
        $max_statement_size = 10 * 1024;
        $options = [
            'max_statement_size' => $max_statement_size,
            'batch_size' => 1,
        ];
        $producer = $this->createProducer($options);
        $fragments = [];
        for ($step = 0; $step < 200; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $fragments[] = $producer->get_sql_fragment();
            if (!$producer->is_finished()) {
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer($options);
            }
        }

        $this->assertTrue($producer->is_finished());
        foreach ($fragments as $fragment) {
            $this->assertLessThanOrEqual($max_statement_size, strlen($fragment));
        }
        $sql = implode("\n", $fragments);
        $marker_pattern = '/' .
            preg_quote(MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX, '/') .
            preg_quote(MySQLDumpProducer::NONZERO_SRID_CONTEXT_VERSION, '/') .
            ' (\{[^\r\n]+\}) \*\//';
        $this->assertSame(1, preg_match($marker_pattern, $sql, $marker));
        $context = json_decode($marker[1], true);
        $this->assertIsArray($context);
        $this->assertStringContainsString(
            'Column: `content`, SRID 4326',
            base64_decode($context['row_b64'], true)
        );
        $this->assertStringNotContainsString('ST_GeomFromText(', $sql);
        $this->assertStringContainsString(
            'REPLACE INTO `__reprint_db_pull_progress_spatial` (`id`, `chunk_number`, `value`)',
            $sql
        );
        $this->assertStringContainsString(
            '(1,CONCAT((SELECT `value` FROM `__reprint_db_pull_progress_spatial` ' .
                'WHERE `id` = 1 AND `chunk_number` = 0)',
            $sql
        );
        $this->assertLessThan(
            strpos($sql, 'INSERT INTO `oversized_geometry_value`'),
            strpos($sql, 'REPLACE INTO `__reprint_db_pull_progress_spatial`')
        );
        $this->assertSame(
            1,
            preg_match(
                "/REPLACE INTO `__reprint_db_pull_progress_spatial` " .
                    "\\(`id`, `chunk_number`, `value`\\) VALUES " .
                    "\\(1, 0, FROM_BASE64\\('[A-Za-z0-9+\\/=]+'\\)\\);/",
                $sql,
                $first_chunk,
                PREG_OFFSET_CAPTURE
            )
        );
        $first_chunk_sql = $first_chunk[0][0];
        $first_chunk_offset = $first_chunk[0][1];
        $sql = substr_replace(
            $sql,
            $first_chunk_sql . "\n" . $first_chunk_sql,
            $first_chunk_offset,
            strlen($first_chunk_sql)
        );

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $imported_value = $import_pdo
            ->query('SELECT CAST(content AS BINARY) FROM oversized_geometry_value')
            ->fetchColumn();
        $this->assertSame($source_value, $imported_value);
        $this->assertSame(
            0,
            (int) $import_pdo
                ->query(
                    "SELECT COUNT(*) FROM information_schema.tables " .
                    "WHERE table_schema = DATABASE() " .
                    "AND table_name = '__reprint_db_pull_progress_spatial'"
                )
                ->fetchColumn()
        );
    }

    public static function oversizedSpatialColumnProvider(): array
    {
        return [
            'geometry' => ['GEOMETRY', 'LINESTRING(%s)'],
            'linestring' => ['LINESTRING', 'LINESTRING(%s)'],
            'polygon' => ['POLYGON', 'POLYGON((%2$s))'],
            'multipoint' => ['MULTIPOINT', 'MULTIPOINT(%s)'],
            'multilinestring' => ['MULTILINESTRING', 'MULTILINESTRING((%s))'],
            'multipolygon' => ['MULTIPOLYGON', 'MULTIPOLYGON(((%2$s)))'],
            'geometrycollection' => [
                'GEOMETRYCOLLECTION',
                'GEOMETRYCOLLECTION(LINESTRING(%s))',
            ],
        ];
    }

    public function testOrderedRowQueryOmitsSpatialValueAboveItsInlineLimit(): void
    {
        $this->pdo->exec(
            'CREATE TABLE deferred_spatial_value (' .
            'id INT PRIMARY KEY, route LINESTRING NOT NULL)'
        );
        $points = [];
        for ($point = 0; $point < 1000; ++$point) {
            $points[] = $point . ' ' . $point;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO deferred_spatial_value VALUES (1, ST_GeomFromText(?))'
        );
        $statement->execute(['LINESTRING(' . implode(',', $points) . ')']);
        $expected_value = $this->pdo
            ->query(
                'SELECT CAST(route AS BINARY) FROM deferred_spatial_value'
            )
            ->fetchColumn();
        $this->assertIsString($expected_value);

        $reader = new DatabaseRowsReader($this->pdo, [
            'tables_to_process' => ['deferred_spatial_value'],
            'maximum_inline_spatial_bytes' => 1024,
        ]);
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());

        $this->assertNull($reader->get_current_record()['route']);
        $this->assertSame(
            strlen($expected_value),
            $reader->get_current_spatial_value_length('route')
        );
        $this->assertSame(
            substr($expected_value, 0, 4),
            $reader->get_current_oversized_spatial_value_prefix('route')
        );
    }

    public function testSpatialValueAboveTargetPacketLimitStopsBeforeStaging(): void
    {
        $this->pdo->exec(
            'CREATE TABLE spatial_value_above_target_packet (' .
            'id INT PRIMARY KEY, route LINESTRING NOT NULL)'
        );
        $points = [];
        for ($point = 0; $point < 2000; ++$point) {
            $points[] = $point . ' ' . $point;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO spatial_value_above_target_packet VALUES (1, ST_GeomFromText(?))'
        );
        $statement->execute(['LINESTRING(' . implode(',', $points) . ')']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('but the target max_allowed_packet is 16384 bytes');

        $this->collectAllFragments($this->createProducer([
            'max_statement_size' => 8 * 1024,
            'target_max_allowed_packet' => 16 * 1024,
        ]));
    }

    public function testMultipleOversizedSpatialColumnsReuseOneStagingTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE multiple_spatial_values (
                id INT PRIMARY KEY,
                route LINESTRING NOT NULL,
                routes MULTILINESTRING NOT NULL,
                payload LONGBLOB NOT NULL
            )
        ");
        $points = [];
        for ($point = 0; $point < 2000; ++$point) {
            $latitude = ($point % 179) - 89;
            $longitude = ($point % 359) - 179;
            $points[] = $latitude . ' ' . $longitude;
        }
        $coordinates = implode(',', $points);
        $statement = $this->pdo->prepare(
            "INSERT INTO multiple_spatial_values VALUES (" .
            "1, ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326), ?)"
        );
        $statement->execute([
            'LINESTRING(' . $coordinates . ')',
            'MULTILINESTRING((' . $coordinates . '))',
            str_repeat('mixed spatial and binary ', 4000),
        ]);
        $statement = $this->pdo->prepare(
            "INSERT INTO multiple_spatial_values VALUES (" .
            "2, ST_GeomFromText(?), ST_GeomFromText(?), ?)"
        );
        $statement->execute([
            'LINESTRING(0 0,1 1)',
            'MULTILINESTRING((0 0,1 1))',
            'row after staged values',
        ]);
        $source_row = $this->pdo
            ->query(
                'SELECT id, CAST(route AS BINARY) AS route, ' .
                'CAST(routes AS BINARY) AS routes, payload ' .
                'FROM multiple_spatial_values ORDER BY id'
            )
            ->fetchAll();

        $options = ['max_statement_size' => 8 * 1024];
        $producer = $this->createProducer($options);
        $fragments = [];
        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();
            if (!$producer->is_finished()) {
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer($options);
            }
        }
        $sql = implode("\n", $fragments);
        $this->assertSame(
            1,
            substr_count($sql, 'CREATE TABLE IF NOT EXISTS `__reprint_db_pull_progress_spatial`')
        );
        $this->assertGreaterThan(
            2,
            substr_count($sql, 'REPLACE INTO `__reprint_db_pull_progress_spatial`')
        );

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $imported_row = $import_pdo
            ->query(
                'SELECT id, CAST(route AS BINARY) AS route, ' .
                'CAST(routes AS BINARY) AS routes, payload ' .
                'FROM multiple_spatial_values ORDER BY id'
            )
            ->fetchAll();
        $this->assertSame($source_row, $imported_row);
    }

    public function testMyIsamResumeAcceptsStagedSpatialChunks(): void
    {
        $this->pdo->exec(
            'CREATE TABLE myisam_spatial_resume (' .
            'id INT PRIMARY KEY, content GEOMETRY NOT NULL) ENGINE=MyISAM'
        );
        $client = ( new ReflectionClass(ImportClient::class) )->newInstanceWithoutConstructor();
        $assert_resume = new ReflectionMethod(
            ImportClient::class,
            'assert_mysql_import_can_repeat_next_group'
        );
        $assert_resume->setAccessible(true);
        $cursor = [
            'state' => 'emit_oversized_update',
            'current_table' => 'myisam_spatial_resume',
            'oversized_queue' => [[
                'column' => 'content',
                'data_type' => 'GEOMETRY',
                'byte_offset' => 8192,
                'total_length' => 16384,
                'spatial_staging_id' => 1,
                'chunk_number' => 1,
            ]],
        ];
        $database = new PdoDatabaseConnection($this->pdo);

        $this->assertNull($assert_resume->invoke(
            $client,
            $database,
            base64_encode(json_encode($cursor)),
            'db-pull'
        ));

        $cursor['state'] = 'stage_oversized_spatial';
        array_unshift($cursor['oversized_queue'], [
            'column' => 'label',
            'data_type' => 'LONGBLOB',
            'byte_offset' => 0,
            'total_length' => 16384,
        ]);
        $this->assertNull($assert_resume->invoke(
            $client,
            $database,
            base64_encode(json_encode($cursor)),
            'db-pull'
        ));
        array_shift($cursor['oversized_queue']);
        $cursor['state'] = 'emit_oversized_update';

        unset($cursor['oversized_queue'][0]['spatial_staging_id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may have already appended part of a large value');
        $assert_resume->invoke(
            $client,
            $database,
            base64_encode(json_encode($cursor)),
            'db-pull'
        );
    }

    public function testStartingNewImportDropsSavedSpatialStagingTable(): void
    {
        $staging_table = '__reprint_db_pull_progress_spatial';
        $this->pdo->exec(
            "CREATE TABLE `{$staging_table}` (" .
            '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY, `value` LONGBLOB NOT NULL)'
        );
        $client_reflection = new ReflectionClass(ImportClient::class);
        $client = $client_reflection->newInstanceWithoutConstructor();
        $database = new PdoDatabaseConnection($this->pdo);
        $create_position_table = $client_reflection->getMethod(
            'create_database_import_position_table'
        );
        $create_position_table->setAccessible(true);
        $create_position_table->invoke($client, $database);
        $position_table = $client_reflection->getConstant(
            'DATABASE_IMPORT_POSITION_TABLE'
        );
        $this->assertIsString($position_table);
        $cursor = base64_encode(json_encode([]));
        $statement = $this->pdo->prepare(
            "REPLACE INTO `{$position_table}` " .
            '(`id`, `source_hash`, `source_cursor`, `file_byte_offset`) ' .
            "VALUES (1, REPEAT('a', 64), ?, 0)"
        );
        $statement->execute([$cursor]);

        $reset_position = $client_reflection->getMethod(
            'reset_database_import_position'
        );
        $reset_position->setAccessible(true);
        $reset_position->invoke($client, $database);

        $this->assertSame(
            0,
            (int) $this->pdo
                ->query(
                    "SELECT COUNT(*) FROM information_schema.tables " .
                    "WHERE table_schema = DATABASE() AND table_name = '{$staging_table}'"
                )
                ->fetchColumn()
        );
        $this->assertSame(
            0,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM `{$position_table}`")
                ->fetchColumn()
        );
    }

    /**
     * Test multiple large columns in the same row.
     */
    public function testMultipleLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE multi_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                blob1 LONGBLOB,
                blob2 LONGBLOB,
                small_col VARCHAR(100)
            )
        ");

        $blob1 = random_bytes(50 * 1024);
        $blob2 = random_bytes(50 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO multi_large (blob1, blob2, small_col) VALUES (?, ?, ?)");
        $stmt->execute([$blob1, $blob2, 'small value']);

        $sql = $this->getDumpSQL(['max_statement_size' => 20 * 1024]);

        // Both large columns should trigger updates
        $updateCount = substr_count($sql, 'UPDATE `multi_large` SET');
        $this->assertGreaterThan(1, $updateCount, 'Should have multiple UPDATE statements');

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT * FROM multi_large WHERE id = 1")->fetch();
        $this->assertEquals($blob1, $row['blob1']);
        $this->assertEquals($blob2, $row['blob2']);
        $this->assertEquals('small value', $row['small_col']);
    }

    /** Combined values may use one bounded update apiece. */
    public function testCombinedValuesAbovePartBodyLimitUseBoundedUpdates(): void
    {
        $this->pdo->exec("
            CREATE TABLE combined_fragment_limit (
                id INT PRIMARY KEY AUTO_INCREMENT,
                blob1 LONGBLOB,
                blob2 LONGBLOB
            )
        ");

        $blob1 = str_repeat('a', 13 * 512 * 1024);
        $blob2 = str_repeat('b', 13 * 512 * 1024);
        $stmt = $this->pdo->prepare(
            "INSERT INTO combined_fragment_limit (blob1, blob2) VALUES (?, ?)"
        );
        $stmt->execute([$blob1, $blob2]);

        $fragments = $this->collectAllFragments($this->createProducer([
            'max_statement_size' => 64 * 1024 * 1024,
        ]));
        foreach ($fragments as $fragment) {
            $this->assertLessThanOrEqual(
                MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES,
                strlen($fragment)
            );
        }
        $target = $this->executeDumpInNewDatabase(implode("\n", $fragments));
        $row = $target
            ->query('SELECT blob1, blob2 FROM combined_fragment_limit WHERE id = 1')
            ->fetch();
        $this->assertSame($blob1, $row['blob1']);
        $this->assertSame($blob2, $row['blob2']);
    }

    /** Text and binary chunks must both stay below the packet target. */
    public function testMixedTextAndBlobStayWithinStatementLimit(): void
    {
        $this->pdo->exec("
            CREATE TABLE mixed_fragment_limits (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text_value LONGTEXT,
                blob_value LONGBLOB
            )
        ");

        $value = str_repeat('a', 13 * 512 * 1024);
        $stmt = $this->pdo->prepare(
            "INSERT INTO mixed_fragment_limits (text_value, blob_value) VALUES (?, ?)"
        );
        $stmt->execute([$value, $value]);

        $max_statement_size = 8 * 1024 * 1024;
        $fragments = $this->collectAllFragments($this->createProducer([
            'max_statement_size' => $max_statement_size,
        ]));
        foreach ($fragments as $fragment) {
            $this->assertLessThanOrEqual(
                $max_statement_size,
                strlen($fragment),
                'Every text and binary fragment must fit max_statement_size.'
            );
        }

        $import_pdo = $this->executeDumpInNewDatabase(implode("\n", $fragments));
        $row = $import_pdo
            ->query('SELECT text_value, blob_value FROM mixed_fragment_limits WHERE id = 1')
            ->fetch();
        $this->assertSame($value, $row['text_value']);
        $this->assertSame($value, $row['blob_value']);
    }

    /**
     * A multi-row INSERT must close before its SQL exceeds one part body.
     *
     * The exporter limits each SQL multipart part to 16 MiB, but one INSERT
     * may span several parts. A part ending inside that statement cannot close
     * the client's current SQL group. Without a statement limit, many bounded
     * parts can therefore become one SQL group which db-apply reads into one
     * PHP string.
     *
     * Each row below fits comfortably in one part. Together, the formatted
     * rows exceed one part body. The producer must start another INSERT, and
     * the resulting dump must still restore all 160 rows.
     */
    public function testUnkeyedInsertDoesNotExceedPartBodyLimit(): void
    {
        $this->pdo->exec("
            CREATE TABLE unkeyed_large_insert (
                payload MEDIUMBLOB NOT NULL
            )
        ");
        $payload = str_repeat('x', 80 * 1024);
        $stmt = $this->pdo->prepare(
            "INSERT INTO unkeyed_large_insert (payload) VALUES (?)"
        );
        for ($row = 0; $row < 160; ++$row) {
            $stmt->execute([$payload]);
        }

        $producer = $this->createProducer([
            'max_statement_size' => 64 * 1024 * 1024,
        ]);
        $fragments = $this->collectAllFragments($producer);
        $sql = implode('', $fragments);

        $insert_prefix = 'INSERT INTO `unkeyed_large_insert` ';
        $current_insert_bytes = null;
        $insert_statement_bytes = [];
        foreach ($fragments as $fragment) {
            if (strncmp($fragment, $insert_prefix, strlen($insert_prefix)) === 0) {
                $current_insert_bytes = 0;
            }
            if ($current_insert_bytes !== null) {
                $current_insert_bytes += strlen($fragment);
                if (substr($fragment, -1) === ';') {
                    $insert_statement_bytes[] = $current_insert_bytes;
                    $current_insert_bytes = null;
                }
            }
        }

        $this->assertGreaterThan(
            MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES,
            array_sum($insert_statement_bytes),
            'The formatted rows must exceed one part body for this regression test.'
        );
        $this->assertLessThanOrEqual(
            MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES,
            max($insert_statement_bytes),
            'Every complete INSERT must fit within one SQL part body.'
        );

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $rowCount = $importPdo->query(
            "SELECT COUNT(*) FROM unkeyed_large_insert"
        )->fetchColumn();
        $this->assertSame(160, (int) $rowCount);
    }

    /** JSON size calculation includes the CONVERT(... USING utf8mb4) wrapper. */
    public function testJsonFormattedSizeIsExact(): void
    {
        $producer = $this->createProducer();
        $estimate_formatted_size = new ReflectionMethod(
            $producer,
            'estimate_formatted_size'
        );
        $estimate_formatted_size->setAccessible(true);
        $value = '{"key":"value"}';
        $formatted = "CONVERT(FROM_BASE64('" . base64_encode($value) . "') USING utf8mb4)";

        $this->assertSame(
            strlen($formatted),
            $estimate_formatted_size->invoke($producer, $value, 'JSON')
        );
    }

    /**
     * Test that multiple rows with large data are handled correctly.
     */
    public function testMultipleRowsWithLargeData(): void
    {
        $this->pdo->exec("
            CREATE TABLE multi_rows (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $data1 = random_bytes(30 * 1024);
        $data2 = random_bytes(30 * 1024);
        $data3 = random_bytes(30 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO multi_rows (content) VALUES (?)");
        $stmt->execute([$data1]);
        $stmt->execute([$data2]);
        $stmt->execute([$data3]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM multi_rows ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertEquals($data1, $rows[0]['content']);
        $this->assertEquals($data2, $rows[1]['content']);
        $this->assertEquals($data3, $rows[2]['content']);
    }

    public function testResumeAfterOversizedRowKeepsItsQueryBoundary(): void
    {
        $this->pdo->exec(
            "CREATE TABLE retained_boundary (id INT PRIMARY KEY, content LONGBLOB)"
        );
        $insert = $this->pdo->prepare("INSERT INTO retained_boundary VALUES (?, ?)");
        $insert->execute([1, str_repeat('x', 20 * 1024)]);
        $insert->execute([2, 'second']);
        $insert->execute([3, 'third']);

        $options = [
            'batch_size' => 2,
            'max_statement_size' => 8 * 1024,
        ];
        $producer = $this->createProducer($options);
        do {
            $this->assertTrue($producer->next_sql_fragment());
            $fragment = (string) $producer->get_sql_fragment();
        } while (strpos($fragment, 'INSERT INTO `retained_boundary`') !== 0);

        $options['cursor'] = $producer->get_reentrancy_cursor();
        $producer = $this->createProducer($options);
        do {
            $this->assertTrue($producer->next_sql_fragment());
            $fragment = (string) $producer->get_sql_fragment();
        } while (
            strpos($fragment, 'INSERT INTO `retained_boundary`') !== 0 ||
            strpos($fragment, base64_encode('second')) === false
        );

        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        $this->assertArrayNotHasKey('current_row', $cursor);
        $this->assertSame(['id' => 2], $cursor['last_pk_values']);
    }

    public function testRowEmitterStopsAtTheReaderQueryBoundary(): void
    {
        $this->pdo->exec(
            "CREATE TABLE emitted_boundary (id INT PRIMARY KEY, content LONGBLOB)"
        );
        $insert = $this->pdo->prepare("INSERT INTO emitted_boundary VALUES (?, ?)");
        $insert->execute([1, str_repeat('x', 20 * 1024)]);
        $insert->execute([2, 'second']);
        $insert->execute([3, 'third']);
        $insert->execute([4, 'fourth']);

        $producer = $this->createProducer([
            'batch_size' => 3,
            'max_statement_size' => 8 * 1024,
        ]);
        do {
            $this->assertTrue($producer->next_sql_fragment());
            $fragment = (string) $producer->get_sql_fragment();
        } while (strpos($fragment, base64_encode('third')) === false);

        $cursor = json_decode($producer->get_reentrancy_cursor(), true);
        $this->assertArrayNotHasKey('current_row', $cursor);
        $this->assertSame(['id' => 3], $cursor['last_pk_values']);
    }

    /**
     * Test with composite primary key.
     */
    public function testCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE composite_pk (
                tenant_id INT,
                item_id INT,
                data LONGBLOB,
                PRIMARY KEY (tenant_id, item_id)
            )
        ");

        $largeData = random_bytes(50 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO composite_pk (tenant_id, item_id, data) VALUES (?, ?, ?)");
        $stmt->execute([1, 100, $largeData]);
        $stmt->execute([1, 200, random_bytes(50 * 1024)]);
        $stmt->execute([2, 100, random_bytes(50 * 1024)]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // UPDATE statements should use composite WHERE clause
        $this->assertStringContainsString('UPDATE `composite_pk` SET', $sql);
        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertStringContainsString('item_id', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT data FROM composite_pk WHERE tenant_id = 1 AND item_id = 100")->fetch();
        $this->assertEquals($largeData, $row['data']);
    }

    /**
     * Test cursor-based resumption with oversized rows.
     */
    public function testReentrancyWithOversizedRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE reentrant_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert multiple large rows
        $data = [];
        $stmt = $this->pdo->prepare("INSERT INTO reentrant_large (content) VALUES (?)");
        for ($i = 0; $i < 5; $i++) {
            $data[$i] = random_bytes(20 * 1024);
            $stmt->execute([$data[$i]]);
        }

        // Export with small max_statement_size and limited fragments per iteration
        $options = [
            'max_statement_size' => 8 * 1024,
            'batch_size' => 1,
        ];

        $producer = $this->createProducer($options);
        $allFragments = [];
        $iterations = 0;
        $maxIterations = 100;

        while (!$producer->is_finished() && $iterations < $maxIterations) {
            // Simulate stopping and resuming every few fragments
            $fragmentsThisRound = 0;
            while ($fragmentsThisRound < 3 && $producer->next_sql_fragment()) {
                $fragment = $producer->get_sql_fragment();
                if ($fragment !== null) {
                    $allFragments[] = $fragment;
                }
                $fragmentsThisRound++;
            }

            if (!$producer->is_finished()) {
                // Save cursor and create new producer
                $cursor = $producer->get_reentrancy_cursor();
                $options['cursor'] = $cursor;
                $producer = $this->createProducer($options);
            }

            $iterations++;
        }

        $this->assertLessThan($maxIterations, $iterations, 'Should complete within reasonable iterations');

        $sql = implode("\n", $allFragments);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM reentrant_large ORDER BY id")->fetchAll();
        $this->assertCount(5, $rows);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($data[$i], $rows[$i]['content'], "Row $i content should match");
        }
    }

    /**
     * The oversized row checkpoint retains its primary key while UPDATE
     * fragments are emitted. Arbitrary key bytes must survive every resume.
     */
    public function testReentrancyWithInvalidUtf8PrimaryKeyInOversizedRow(): void
    {
        $this->pdo->exec("
            CREATE TABLE reentrant_large_binary_key (
                id VARBINARY(16) PRIMARY KEY,
                content LONGBLOB
            )
        ");

        $id = "\xFF\xFE\x80";
        $content = random_bytes(20 * 1024);
        $stmt = $this->pdo->prepare(
            "INSERT INTO reentrant_large_binary_key (id, content) VALUES (?, ?)"
        );
        $stmt->execute([$id, $content]);

        $options = [
            "max_statement_size" => 8 * 1024,
            "batch_size" => 1,
        ];
        $producer = $this->createProducer($options);
        $fragments = [];
        $saw_oversized_primary_key_checkpoint = false;

        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();

            try {
                $cursor = $producer->get_reentrancy_cursor();
            } catch (RuntimeException $e) {
                $this->fail(
                    "The cursor must preserve the oversized row primary key: " .
                    $e->getMessage()
                );
            }

            $cursor_data = json_decode($cursor, true);
            if ($cursor_data["state"] === "emit_oversized_update") {
                $saw_oversized_primary_key_checkpoint = true;
                $this->assertSame(
                    base64_encode($id),
                    $cursor_data["last_pk_values"]["id"]["__binary__"]
                );
                $this->assertArrayNotHasKey("oversized_pk_values", $cursor_data);
            }

            if (strpos($producer->get_sql_fragment(), 'UPDATE `reentrant_large_binary_key`') !== false) {
                $this->assertTrue(
                    $producer->current_fragment_must_be_its_own_part(),
                    'Each oversized-value update must be sent in its own multipart part.'
                );
            }

            if (!$producer->is_finished()) {
                $options["cursor"] = $cursor;
                $producer = $this->createProducer($options);
            }
        }

        $this->assertTrue($saw_oversized_primary_key_checkpoint);

        $sql = implode("\n", $fragments);
        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual(
            $this->pdo,
            $import_pdo,
            ["reentrant_large_binary_key"]
        );
    }

    /**
     * Fetching oversized chunks must compare a text primary key without
     * passing its latin1 bytes through the utf8mb4 connection charset.
     */
    public function testReentrancyWithLatin1TextPrimaryKeyInOversizedRow(): void
    {
        $this->pdo->exec("
            CREATE TABLE reentrant_large_text_key (
                id VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_bin PRIMARY KEY,
                content LONGBLOB
            )
        ");

        $id = "\x80\xE9";
        $content = random_bytes(20 * 1024);
        $encoded_id = base64_encode($id);
        $stmt = $this->pdo->prepare(
            "INSERT INTO reentrant_large_text_key (id, content)
             VALUES (CONVERT(FROM_BASE64('{$encoded_id}') USING latin1), ?)"
        );
        $stmt->execute([$content]);

        $options = [
            "max_statement_size" => 8 * 1024,
            "batch_size" => 1,
        ];
        $producer = $this->createProducer($options);
        $fragments = [];

        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();

            if (!$producer->is_finished()) {
                $options["cursor"] = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer($options);
            }
        }

        $sql = implode("\n", $fragments);
        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(
            strtoupper(bin2hex($id)),
            $import_pdo
                ->query("SELECT HEX(id) FROM reentrant_large_text_key")
                ->fetchColumn()
        );
        $this->assertSame(
            $content,
            $import_pdo
                ->query("SELECT content FROM reentrant_large_text_key")
                ->fetchColumn()
        );
    }

    /**
     * Test with base64 string encoding.
     */
    public function testBase64EncodingWithLargeData(): void
    {
        $this->pdo->exec("
            CREATE TABLE base64_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGTEXT
            )
        ");

        $largeText = str_repeat("Base64 encoded text data! ", 3000);
        $stmt = $this->pdo->prepare("INSERT INTO base64_large (content) VALUES (?)");
        $stmt->execute([$largeText]);

        $sql = $this->getDumpSQL([
            'max_statement_size' => 15 * 1024,
        ]);

        // Should use FROM_BASE64 in updates
        $this->assertStringContainsString('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT content FROM base64_large WHERE id = 1")->fetchColumn();
        $this->assertEquals($largeText, $imported);
    }

    /**
     * Test that each SQL statement is within max_statement_size.
     */
    public function testStatementSizesRespectLimit(): void
    {
        $this->pdo->exec("
            CREATE TABLE size_check (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $largeData = random_bytes(100 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO size_check (content) VALUES (?)");
        $stmt->execute([$largeData]);

        $maxSize = 10 * 1024;
        $producer = $this->createProducer(['max_statement_size' => $maxSize]);

        $oversizedStatements = [];
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) {
                $size = strlen($fragment);
                // Allow some margin for measurement differences
                if ($size > $maxSize * 1.5) {
                    $oversizedStatements[] = [
                        'size' => $size,
                        'preview' => substr($fragment, 0, 100) . '...',
                    ];
                }
            }
        }

        $this->assertEmpty(
            $oversizedStatements,
            'All statements should be within max_statement_size. Oversized: ' .
            json_encode($oversizedStatements)
        );
    }

    /**
     * Test mixed small and large rows.
     */
    public function testMixedRowSizes(): void
    {
        $this->pdo->exec("
            CREATE TABLE mixed_sizes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO mixed_sizes (content) VALUES (?)");

        // Mix of small and large rows
        $data = [
            random_bytes(500),           // Small
            random_bytes(50 * 1024),     // Large
            random_bytes(200),           // Small
            random_bytes(40 * 1024),     // Large
            random_bytes(100),           // Small
        ];

        foreach ($data as $d) {
            $stmt->execute([$d]);
        }

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM mixed_sizes ORDER BY id")->fetchAll();
        $this->assertCount(5, $rows);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($data[$i], $rows[$i]['content'], "Row $i content should match");
        }
    }

    /**
     * Test with NULL values in large columns.
     */
    public function testNullValuesInLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE null_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO null_large (content) VALUES (?)");
        $stmt->execute([null]);
        $stmt->execute([random_bytes(30 * 1024)]);
        $stmt->execute([null]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM null_large ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]['content']);
        $this->assertNotNull($rows[1]['content']);
        $this->assertNull($rows[2]['content']);
    }

    /**
     * Test with empty string values.
     */
    public function testEmptyStringInLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE empty_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $largeData = random_bytes(30 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO empty_large (content) VALUES (?)");
        $stmt->execute(['']);
        $stmt->execute([$largeData]);
        $stmt->execute(['']);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM empty_large ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertEquals('', $rows[0]['content']);
        $this->assertEquals($largeData, $rows[1]['content']);
        $this->assertEquals('', $rows[2]['content']);
    }

    /**
     * Test that primary key columns are never split (they're needed for WHERE clause).
     */
    public function testPrimaryKeyColumnsNotSplit(): void
    {
        // Use a VARCHAR primary key with moderate value (max key length is 3072 bytes with utf8mb4)
        $this->pdo->exec("
            CREATE TABLE varchar_pk (
                id VARCHAR(200) PRIMARY KEY,
                content LONGBLOB
            )
        ");

        $largePk = str_repeat('x', 150);
        $largeContent = random_bytes(30 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO varchar_pk (id, content) VALUES (?, ?)");
        $stmt->execute([$largePk, $largeContent]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // The PK value should appear in UPDATE WHERE clauses
        // It should NOT be split
        $this->assertStringContainsString('UPDATE `varchar_pk` SET', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT * FROM varchar_pk")->fetch();
        $this->assertEquals($largePk, $row['id']);
        $this->assertEquals($largeContent, $row['content']);
    }

    /**
     * Tables without a primary key can't use the UPDATE ... CONCAT() fallback
     * for oversized rows. The producer should throw rather than silently emitting
     * a row that exceeds max_statement_size.
     */
    public function testOversizedRowWithoutPrimaryKeyThrows(): void
    {
        $this->pdo->exec("
            CREATE TABLE no_pk_large (
                name VARCHAR(100),
                content LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO no_pk_large (name, content) VALUES (?, ?)");
        $stmt->execute(['test', random_bytes(50 * 1024)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("no primary key");

        $this->getDumpSQL(['max_statement_size' => 10 * 1024]);
    }

    /** A large non-key row value must not be copied into the next cursor header. */
    public function testCursorSizeStaysSmallWithOversizedRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE cursor_size_check (
                id INT PRIMARY KEY AUTO_INCREMENT,
                blob1 LONGBLOB,
                blob2 LONGBLOB,
                blob3 LONGBLOB
            )
        ");

        // This small row creates a cursor boundary immediately before the large row.
        $this->pdo->exec(
            "INSERT INTO cursor_size_check (blob1, blob2, blob3) VALUES ('small', 'row', 'first')"
        );

        // If copied into the cursor, these values exceed the test's 8190-byte limit.
        $stmt = $this->pdo->prepare(
            "INSERT INTO cursor_size_check (blob1, blob2, blob3) VALUES (?, ?, ?)"
        );
        $stmt->execute([
            random_bytes(64 * 1024),
            random_bytes(64 * 1024),
            random_bytes(32 * 1024),
        ]);

        $options = [
            'max_statement_size' => 32 * 1024,
            'batch_size' => 250,
        ];

        $producer = $this->createProducer($options);
        $maxCursorSize = 0;

        // Walk through all fragments, checking cursor size at every step
        while ($producer->next_sql_fragment()) {
            $cursor = $producer->get_reentrancy_cursor();
            // The client sends this encoded value in X-Export-Cursor.
            $cursorSize = strlen(base64_encode($cursor));
            if ($cursorSize > $maxCursorSize) {
                $maxCursorSize = $cursorSize;
            }
        }

        $this->assertLessThanOrEqual(
            8190,
            $maxCursorSize,
            "Encoded cursor must stay within the test's 8190-byte header-value limit, " .
            "got {$maxCursorSize} bytes"
        );
    }

    /** Collects a dump without allowing a broken cursor to loop forever. */
    private function collectFragmentsWithinSteps(
        MySQLDumpProducer $producer,
        int $maximum_steps
    ): array {
        $fragments = [];
        for ($step = 0; $step < $maximum_steps; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $fragments[] = $producer->get_sql_fragment();
        }
        $this->assertTrue(
            $producer->is_finished(),
            "The exporter did not finish within {$maximum_steps} steps."
        );
        return $fragments;
    }
}
