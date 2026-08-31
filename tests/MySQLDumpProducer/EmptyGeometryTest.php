<?php

require_once __DIR__ . '/../../packages/reprint-client/src/lib/mysql-query-stream/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/import/load.php';

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\NullableSpatialColumnStatementRewriter;
use WordPress\Reprint\Server\MySQLDumpProducer;

/**
 * Tests zero-byte spatial values created by MariaDB ADD COLUMN operations.
 */
class EmptyGeometryTest extends TestCase {
    /** @var PDO|null */
    private $source_pdo;

    /** @var string */
    private $source_database = 'test_empty_geometry_source';

    /** @var string[] */
    private $target_databases = [];

    protected function setUp(): void
    {
        $source_host = getenv('MARIADB_SOURCE_HOST');
        if (!$source_host) {
            $this->markTestSkipped('Set MARIADB_SOURCE_HOST to run MariaDB geometry tests.');
        }

        try {
            $this->source_pdo = $this->connect(
                $source_host,
                getenv('MARIADB_SOURCE_PORT') ?: '3306',
                getenv('MARIADB_SOURCE_USER') ?: 'root',
                getenv('MARIADB_SOURCE_PASS') ?: ''
            );
        } catch (PDOException $error) {
            $this->markTestSkipped('Cannot connect to the MariaDB test source: ' . $error->getMessage());
        }

        $this->source_pdo->exec("DROP DATABASE IF EXISTS `{$this->source_database}`");
        $this->source_pdo->exec(
            "CREATE DATABASE `{$this->source_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $this->source_pdo->exec("USE `{$this->source_database}`");
    }

    protected function tearDown(): void
    {
        if ($this->source_pdo) {
            $this->source_pdo->exec("DROP DATABASE IF EXISTS `{$this->source_database}`");
        }
        foreach ($this->target_databases as $target) {
            try {
                $target_pdo = $this->connectTarget($target);
                $target_pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
            } catch (PDOException $error) {
                // Ignore cleanup errors.
                continue;
            }
        }
        $this->source_pdo = null;
    }

    /**
     * @dataProvider targetDatabaseProvider
     */
    public function testZeroByteSpatialValuesRoundTripToRealTargets(string $target): void
    {
        $large_text = str_repeat('large geometry row ', 1000);
        $location_comment = "Map's path \\tiles\nSecond line";
        $quoted_location_comment = $this->source_pdo->quote($location_comment);
        $this->source_pdo->exec(
            "CREATE TABLE first_empty (
                id INT PRIMARY KEY,
                empty_blob BLOB
            )"
        );
        $this->source_pdo->exec(
            "INSERT INTO first_empty VALUES (1, ''), (2, X'00')"
        );
        $this->source_pdo->exec(
            "ALTER TABLE first_empty
                ADD COLUMN location POINT NOT NULL COMMENT {$quoted_location_comment},
                ADD COLUMN route LINESTRING NOT NULL,
                ADD COLUMN boundary POLYGON NOT NULL,
                ADD COLUMN locations MULTIPOINT NOT NULL,
                ADD COLUMN routes MULTILINESTRING NOT NULL,
                ADD COLUMN boundaries MULTIPOLYGON NOT NULL,
                ADD COLUMN shape GEOMETRY NOT NULL,
                ADD COLUMN shapes GEOMETRYCOLLECTION NOT NULL,
                ADD COLUMN optional_shape GEOMETRY NOT NULL"
        );
        $this->source_pdo->exec(
            "ALTER TABLE first_empty
                MODIFY COLUMN optional_shape GEOMETRY NULL COMMENT 'Optional shape'"
        );
        $this->source_pdo->exec(
            "UPDATE first_empty
                SET location = ST_GeomFromText('POINT(2 3)')
                WHERE id = 2"
        );

        $this->source_pdo->exec(
            "CREATE TABLE mixed_geometry (
                id INT PRIMARY KEY,
                label VARCHAR(30),
                payload LONGTEXT
            )"
        );
        $insert = $this->source_pdo->prepare(
            'INSERT INTO mixed_geometry VALUES (?, ?, ?)'
        );
        $insert->execute([1, 'valid before empty', 'small']);
        $insert->execute([2, 'first empty', '']);
        $insert->execute([3, 'valid after empty', 'small']);
        $insert->execute([4, 'repeated empty', $large_text]);
        $this->source_pdo->exec(
            "ALTER TABLE mixed_geometry
                ADD COLUMN location POINT NOT NULL COMMENT {$quoted_location_comment},
                ADD COLUMN boundary POLYGON NOT NULL COMMENT 'Map area'"
        );
        $this->source_pdo->exec(
            "UPDATE mixed_geometry SET
                location = ST_GeomFromText('POINT(1 1)'),
                boundary = ST_GeomFromText('POLYGON((0 0,0 1,1 1,0 0))')
                WHERE id IN (1, 3)"
        );

        $source_empty_lengths = $this->source_pdo
            ->query(
                'SELECT OCTET_LENGTH(CAST(location AS BINARY)), ' .
                'OCTET_LENGTH(CAST(boundary AS BINARY)) ' .
                'FROM mixed_geometry WHERE id = 2'
            )
            ->fetch(PDO::FETCH_NUM);
        $this->assertSame([0, 0], array_map('intval', $source_empty_lengths));

        $options = [
            'batch_size' => 2,
            'max_statement_size' => 1024,
            'tables_to_process' => ['first_empty', 'mixed_geometry'],
        ];
        $sql = $this->exportWithResumeAfterEveryFragment($options);
        $this->assertSame(2, substr_count($sql, 'ALTER TABLE'));
        $this->assertStringNotContainsString('MODIFY COLUMN `optional_shape`', $sql);
        $this->assertStringContainsString(
            'UPDATE `mixed_geometry` SET `payload` = CONCAT',
            $sql
        );
        $this->assertStringContainsString(
            MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT,
            $sql
        );

        $target_pdo = $this->executeDump($sql, $target);
        $this->assertSame(
            strpos($target, 'mariadb') !== false ? 0 : 1,
            (int) $target_pdo
                ->query('SELECT location IS NULL FROM first_empty WHERE id = 1')
                ->fetchColumn()
        );
        $this->assertSame(
            'POINT(2 3)',
            $target_pdo
                ->query('SELECT ST_AsText(location) FROM first_empty WHERE id = 2')
                ->fetchColumn()
        );
        $this->assertSame(
            1,
            (int) $target_pdo
                ->query('SELECT OCTET_LENGTH(empty_blob) FROM first_empty WHERE id = 2')
                ->fetchColumn()
        );
        $this->assertSame(
            strpos($target, 'mariadb') !== false ? 1 : 9,
            (int) $target_pdo
                ->query(
                    'SELECT (location IS NULL) + (route IS NULL) + (boundary IS NULL) + ' .
                    '(locations IS NULL) + (routes IS NULL) + (boundaries IS NULL) + ' .
                    '(shape IS NULL) + (shapes IS NULL) + (optional_shape IS NULL) ' .
                    'FROM first_empty WHERE id = 1'
                )
                ->fetchColumn()
        );
        $this->assertSame(
            [
                ['id' => 1, 'location' => 'POINT(1 1)', 'boundary' => 'POLYGON((0 0,0 1,1 1,0 0))'],
                ['id' => 2, 'location' => null, 'boundary' => null],
                ['id' => 3, 'location' => 'POINT(1 1)', 'boundary' => 'POLYGON((0 0,0 1,1 1,0 0))'],
                ['id' => 4, 'location' => null, 'boundary' => null],
            ],
            $target_pdo
                ->query(
                    'SELECT id, ST_AsText(location) AS location, ST_AsText(boundary) AS boundary ' .
                    'FROM mixed_geometry ORDER BY id'
                )
                ->fetchAll()
        );
        $this->assertSame(
            $large_text,
            $target_pdo->query('SELECT payload FROM mixed_geometry WHERE id = 4')->fetchColumn()
        );
        $this->assertSame(
            strpos($target, 'mariadb') !== false ? [0, 0] : [2, 2],
            array_map(
                'intval',
                $target_pdo
                    ->query(
                        'SELECT (location IS NULL) + (boundary IS NULL) FROM mixed_geometry ' .
                        'WHERE id IN (2, 4) ORDER BY id'
                    )
                    ->fetchAll(PDO::FETCH_COLUMN)
            )
        );

        $columns = $target_pdo->query('SHOW FULL COLUMNS FROM mixed_geometry')->fetchAll();
        $columns_by_name = array_column($columns, null, 'Field');
        $this->assertSame(
            strpos($target, 'mariadb') !== false ? 'NO' : 'YES',
            $columns_by_name['location']['Null']
        );
        $this->assertSame($location_comment, $columns_by_name['location']['Comment']);
        $this->assertSame(
            strpos($target, 'mariadb') !== false ? 'NO' : 'YES',
            $columns_by_name['boundary']['Null']
        );
        $this->assertSame('Map area', $columns_by_name['boundary']['Comment']);

        preg_match_all(
            '/' . preg_quote(MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX, '/') .
                '.*? \*\/\s+ALTER TABLE `[^`]+`\n.*?;/s',
            $sql,
            $alter_statements
        );
        $this->assertCount(2, $alter_statements[0]);
        $database = new PdoDatabaseConnection($target_pdo);
        $rewriter = new NullableSpatialColumnStatementRewriter($database);
        foreach ($alter_statements[0] as $alter_statement) {
            $rewritten_statement = $rewriter->rewrite($alter_statement);
            $this->assertNotNull($rewritten_statement);
            $target_pdo->exec($rewritten_statement);
        }
    }

    /** @dataProvider targetDatabaseProvider */
    public function testOversizedSpatialValueRoundTripsToRealTargets(string $target): void
    {
        $this->source_pdo->exec(
            'CREATE TABLE oversized_route (' .
            'id INT PRIMARY KEY, route LINESTRING NOT NULL, ' .
            'SPATIAL INDEX route_index (route))'
        );
        $points = [];
        for ($point = 0; $point < 3000; ++$point) {
            $latitude = ($point % 179) - 89;
            $longitude = ($point % 359) - 179;
            $points[] = $latitude . ' ' . $longitude;
        }
        $statement = $this->source_pdo->prepare(
            'INSERT INTO oversized_route VALUES (1, ST_GeomFromText(?, 4326))'
        );
        $statement->execute(['LINESTRING(' . implode(',', $points) . ')']);
        $source_value = $this->source_pdo
            ->query('SELECT CAST(route AS BINARY) FROM oversized_route')
            ->fetchColumn();

        $sql = $this->exportWithResumeAfterEveryFragment([
            'batch_size' => 1,
            'max_statement_size' => 8 * 1024,
            'tables_to_process' => ['oversized_route'],
        ]);
        $target_pdo = $this->executeDump($sql, $target);
        $target_value = $target_pdo
            ->query('SELECT CAST(route AS BINARY) FROM oversized_route')
            ->fetchColumn();

        $this->assertSame($source_value, $target_value);
        $this->assertSame(
            0,
            (int) $target_pdo
                ->query(
                    "SELECT COUNT(*) FROM information_schema.tables " .
                    "WHERE table_schema = DATABASE() " .
                    "AND table_name = '__reprint_db_pull_progress_spatial'"
                )
                ->fetchColumn()
        );
    }

    public function testNullableAlterPreservesCompleteColumnDefinition(): void
    {
        $column = "location,\n`north";
        $quoted_column = '`' . str_replace('`', '``', $column) . '`';
        $comment = "North's path \\tiles\nSecond line";
        $quoted_comment = $this->source_pdo->quote($comment);

        $this->source_pdo->exec(
            'CREATE TABLE definition_attributes (id INT PRIMARY KEY)'
        );
        $this->source_pdo->exec('INSERT INTO definition_attributes VALUES (1)');
        $this->source_pdo->exec(
            "ALTER TABLE definition_attributes ADD COLUMN {$quoted_column} POINT NOT NULL"
        );
        $this->source_pdo->exec(
            "ALTER TABLE definition_attributes MODIFY COLUMN {$quoted_column} POINT NOT NULL " .
            "INVISIBLE DEFAULT (ST_GeomFromText('POINT(4 5)')) COMMENT {$quoted_comment}"
        );

        $source_columns = $this->source_pdo
            ->query('SHOW FULL COLUMNS FROM definition_attributes')
            ->fetchAll();
        $source_column = array_column($source_columns, null, 'Field')[$column];
        $this->assertSame('NO', $source_column['Null']);
        $this->assertSame(
            0,
            (int) $this->source_pdo
                ->query(
                    "SELECT OCTET_LENGTH(CAST({$quoted_column} AS BINARY)) " .
                    'FROM definition_attributes'
                )
                ->fetchColumn()
        );

        $sql = $this->exportWithResumeAfterEveryFragment([
            'tables_to_process' => ['definition_attributes'],
        ]);
        $target_pdo = $this->executeDump(
            $sql,
            'test_empty_geometry_mariadb_definition_target'
        );

        $target_columns = $target_pdo
            ->query('SHOW FULL COLUMNS FROM definition_attributes')
            ->fetchAll();
        $target_column = array_column($target_columns, null, 'Field')[$column];
        $this->assertSame('NO', $target_column['Null']);
        foreach (['Type', 'Default', 'Extra', 'Comment'] as $field) {
            $this->assertSame($source_column[$field], $target_column[$field], $field);
        }
        $this->assertSame(
            0,
            (int) $target_pdo
                ->query("SELECT {$quoted_column} IS NULL FROM definition_attributes")
                ->fetchColumn()
        );
        $this->assertSame(
            'POINT(4 5)',
            $target_pdo
                ->query("SELECT ST_AsText({$quoted_column}) FROM definition_attributes")
                ->fetchColumn()
        );
    }

    /**
     * MariaDB preserves its non-NULL placeholder, while MySQL rejects the
     * normalized NULL instead of silently weakening the source CHECK.
     *
     * @dataProvider targetDatabaseProvider
     */
    public function testCheckConstraintRejectingNullStopsImport(string $target): void
    {
        $this->source_pdo->exec(
            'CREATE TABLE constrained_geometry (id INT PRIMARY KEY)'
        );
        $this->source_pdo->exec('INSERT INTO constrained_geometry VALUES (1)');
        $this->source_pdo->exec(
            'ALTER TABLE constrained_geometry ADD COLUMN location POINT NOT NULL'
        );
        $this->source_pdo->exec(
            'ALTER TABLE constrained_geometry ADD CONSTRAINT location_required ' .
            'CHECK (location IS NOT NULL)'
        );

        $sql = $this->exportWithResumeAfterEveryFragment([
            'tables_to_process' => ['constrained_geometry'],
        ]);
        if (strpos($target, 'mariadb') !== false) {
            $target_pdo = $this->executeDump($sql, $target);
            $this->assertSame(
                0,
                (int) $target_pdo
                    ->query('SELECT location IS NULL FROM constrained_geometry')
                    ->fetchColumn()
            );
            return;
        }
        try {
            $this->executeDump($sql, $target);
            $this->fail('The target CHECK constraint should reject the normalized NULL.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('location_required', $error->getMessage());
        }

        $target_pdo = $this->connectTarget($target);
        $target_pdo->exec("USE `{$target}`");
        $create_table = $target_pdo
            ->query('SHOW CREATE TABLE constrained_geometry')
            ->fetch();
        $this->assertStringContainsString('location_required', $create_table['Create Table']);
        $columns = $target_pdo
            ->query('SHOW FULL COLUMNS FROM constrained_geometry')
            ->fetchAll();
        $columns_by_name = array_column($columns, null, 'Field');
        $this->assertSame('YES', $columns_by_name['location']['Null']);
    }

    /**
     * A zero-byte geometry has no bounding box, so MariaDB cannot build an
     * R-tree over the column after the populated-table ADD COLUMN operation.
     */
    public function testMariaDbRejectsSpatialIndexForZeroByteGeometry(): void
    {
        $this->source_pdo->exec(
            'CREATE TABLE zero_byte_spatial_index (id INT PRIMARY KEY)'
        );
        $this->source_pdo->exec('INSERT INTO zero_byte_spatial_index VALUES (1)');
        $this->source_pdo->exec(
            'ALTER TABLE zero_byte_spatial_index ADD COLUMN location POINT NOT NULL'
        );

        try {
            $this->source_pdo->exec(
                'ALTER TABLE zero_byte_spatial_index ADD SPATIAL INDEX location_index (location)'
            );
            $this->fail('MariaDB accepted a spatial index over a zero-byte geometry.');
        } catch (PDOException $error) {
            $this->assertStringContainsString(
                'geometry object',
                strtolower($error->getMessage())
            );
        }
    }

    /**
     * SHOW CREATE TABLE can omit backticks when the target session disabled
     * sql_quote_show_create. The rewriter must set it before parsing.
     *
     * @dataProvider mysqlTargetDatabaseProvider
     */
    public function testRewriterForcesQuotedShowCreateOutput(string $target): void
    {
        $database = $this->targetDatabaseName('test_empty_geometry_quoted_show_create', $target);
        $target_pdo = $this->initializeTargetDatabase($database);
        $target_pdo->exec(
            'CREATE TABLE quoted_show_create (id INT PRIMARY KEY, location POINT NOT NULL)'
        );
        $target_pdo->exec('SET SESSION sql_quote_show_create = 0');

        $rewriter = new NullableSpatialColumnStatementRewriter(
            new PdoDatabaseConnection($target_pdo)
        );
        $rewritten_alter = $rewriter->rewrite($this->nullableSpatialColumnMarker(
            'quoted_show_create',
            ['location']
        ));
        $this->assertStringContainsString(
            'ALTER TABLE `quoted_show_create`',
            $rewritten_alter
        );
        $this->assertMatchesRegularExpression(
            '/MODIFY COLUMN `location` POINT NULL/i',
            $rewritten_alter
        );
        $this->assertSame(
            1,
            (int) $target_pdo->query('SELECT @@SESSION.sql_quote_show_create')->fetchColumn()
        );
    }

    /**
     * MariaDB preserves a spatial index and its NOT NULL column. MySQL stops
     * before issuing an ALTER which cannot coexist with that index.
     *
     * @dataProvider targetDatabaseProvider
     */
    public function testSpatialIndexStopsNullableRewrite(string $target): void
    {
        $database = $this->targetDatabaseName('test_empty_geometry_spatial_index', $target);
        $target_pdo = $this->initializeTargetDatabase($database);
        $target_pdo->exec(
            'CREATE TABLE spatially_indexed_geometry (' .
            'id INT PRIMARY KEY, location POINT NOT NULL, ' .
            'SPATIAL INDEX location_index (location))'
        );

        $rewriter = new NullableSpatialColumnStatementRewriter(
            new PdoDatabaseConnection($target_pdo)
        );
        if (strpos($target, 'mariadb') !== false) {
            $this->assertSame(
                'DO 0;',
                $rewriter->rewrite($this->nullableSpatialColumnMarker(
                    'spatially_indexed_geometry',
                    ['location']
                ))
            );
        } else {
            try {
                $rewriter->rewrite($this->nullableSpatialColumnMarker(
                    'spatially_indexed_geometry',
                    ['location']
                ));
                $this->fail('The rewriter must reject a nullable spatial index column.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('location_index', $error->getMessage());
                $this->assertStringContainsString('NOT NULL', $error->getMessage());
            }
        }

        $columns = $target_pdo
            ->query('SHOW FULL COLUMNS FROM spatially_indexed_geometry')
            ->fetchAll();
        $columns_by_name = array_column($columns, null, 'Field');
        $this->assertSame('NO', $columns_by_name['location']['Null']);
    }

    public function testEarlierCursorFormatResumesBeforeFirstEmptyValue(): void
    {
        $this->source_pdo->exec(
            'CREATE TABLE earlier_cursor (id INT PRIMARY KEY)'
        );
        $this->source_pdo->exec('INSERT INTO earlier_cursor VALUES (1), (2)');
        $this->source_pdo->exec(
            'ALTER TABLE earlier_cursor ADD COLUMN location POINT NOT NULL'
        );
        $this->source_pdo->exec(
            "UPDATE earlier_cursor SET location = ST_GeomFromText('POINT(1 1)') WHERE id = 1"
        );

        $options = [
            'batch_size' => 10,
            'tables_to_process' => ['earlier_cursor'],
        ];
        $producer = new MySQLDumpProducer($this->source_pdo, $options);
        $fragments = [];
        $cursor = null;
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            $fragments[] = $fragment;
            if (strpos($fragment, 'INSERT INTO `earlier_cursor`') === 0) {
                $cursor = json_decode($producer->get_reentrancy_cursor(), true);
                break;
            }
        }
        $this->assertNotNull($cursor);
        unset(
            $cursor['nullable_spatial_columns'],
            $cursor['pending_nullable_spatial_columns']
        );

        $options['cursor'] = json_encode($cursor);
        $producer = new MySQLDumpProducer($this->source_pdo, $options);
        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();
        }

        $target_pdo = $this->executeDump(
            implode("\n", $fragments),
            'test_empty_geometry_earlier_cursor_target'
        );
        $this->assertSame(
            ['POINT(1 1)', null],
            $target_pdo
                ->query('SELECT ST_AsText(location) FROM earlier_cursor ORDER BY id')
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testPendingSpatialAlterRejectsSourceTypeChange(): void
    {
        $this->source_pdo->exec(
            'CREATE TABLE changed_spatial_type (id INT PRIMARY KEY)'
        );
        $this->source_pdo->exec('INSERT INTO changed_spatial_type VALUES (1), (2)');
        $this->source_pdo->exec(
            'ALTER TABLE changed_spatial_type ADD COLUMN location POINT NOT NULL'
        );
        $this->source_pdo->exec(
            "UPDATE changed_spatial_type SET location = ST_GeomFromText('POINT(1 1)') WHERE id = 1"
        );

        $options = [
            'batch_size' => 10,
            'tables_to_process' => ['changed_spatial_type'],
        ];
        $producer = new MySQLDumpProducer($this->source_pdo, $options);
        $cursor = null;
        while ($producer->next_sql_fragment()) {
            $cursor_data = json_decode($producer->get_reentrancy_cursor(), true);
            if ($cursor_data['state'] === 'emit_nullable_spatial_columns') {
                $cursor = json_encode($cursor_data);
                break;
            }
        }
        $this->assertNotNull($cursor);

        $this->source_pdo->exec(
            'ALTER TABLE changed_spatial_type MODIFY COLUMN location LONGBLOB NOT NULL'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'column `changed_spatial_type`.`location` is no longer spatial'
        );
        $options['cursor'] = $cursor;
        new MySQLDumpProducer($this->source_pdo, $options);
    }

    public static function targetDatabaseProvider(): array
    {
        return [
            'MySQL 8 target' => ['test_empty_geometry_mysql_target'],
            'MariaDB target' => ['test_empty_geometry_mariadb_target'],
        ];
    }

    public static function mysqlTargetDatabaseProvider(): array
    {
        return [
            'MySQL 8 target' => ['test_empty_geometry_mysql_target'],
        ];
    }

    private function exportWithResumeAfterEveryFragment(array $options): string
    {
        $producer = new MySQLDumpProducer($this->source_pdo, $options);
        $fragments = [];
        for ($step = 0; $step < 100; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $fragments[] = $producer->get_sql_fragment();
            if (!$producer->is_finished()) {
                $options['cursor'] = $producer->get_reentrancy_cursor();
                $producer = new MySQLDumpProducer($this->source_pdo, $options);
            }
        }
        $this->assertTrue($producer->is_finished(), 'The exporter did not finish within 100 steps.');
        return implode("\n", $fragments);
    }

    private function executeDump(string $sql, string $database): PDO
    {
        $target_pdo = $this->initializeTargetDatabase($database);

        $connection = new PdoDatabaseConnection($target_pdo);
        $rewriter = new NullableSpatialColumnStatementRewriter($connection);
        $query_stream = new WP_MySQL_FastQueryStream();
        $query_stream->append_sql($sql);
        $query_stream->mark_input_complete();
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            $target_pdo->exec($rewriter->rewrite($query) ?? $query);
        }
        return $target_pdo;
    }

    private function initializeTargetDatabase(string $database): PDO
    {
        $target_pdo = $this->connectTarget($database);
        $target_pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $target_pdo->exec(
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $target_pdo->exec("USE `{$database}`");
        $this->target_databases[] = $database;
        return $target_pdo;
    }

    private function targetDatabaseName(string $prefix, string $target): string
    {
        return $prefix . ( strpos($target, 'mariadb') !== false ? '_mariadb' : '_mysql' );
    }

    /** @param string[] $columns */
    private function nullableSpatialColumnMarker(string $table, array $columns): string
    {
        return MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX .
            implode(' ', array_map('base64_encode', array_merge([$table], $columns))) .
            " */\nALTER TABLE `" . str_replace('`', '``', $table) . "`\nMODIFY COLUMN `" .
            str_replace('`', '``', $columns[0]) . '` POINT NULL;';
    }

    private function connectTarget(string $database): PDO
    {
        if (strpos($database, 'mariadb') !== false) {
            return $this->connect(
                getenv('MARIADB_SOURCE_HOST'),
                getenv('MARIADB_SOURCE_PORT') ?: '3306',
                getenv('MARIADB_SOURCE_USER') ?: 'root',
                getenv('MARIADB_SOURCE_PASS') ?: ''
            );
        }
        return $this->connect(
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: ''
        );
    }

    private function connect(string $host, string $port, string $user, string $password): PDO
    {
        return new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}
