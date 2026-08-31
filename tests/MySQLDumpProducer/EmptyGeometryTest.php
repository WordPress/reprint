<?php

use PHPUnit\Framework\TestCase;
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

        $target_pdo = $this->executeDump($sql, $target);
        $this->assertSame(
            [
                ['id' => 1, 'location' => null, 'empty_blob_bytes' => 0],
                ['id' => 2, 'location' => 'POINT(2 3)', 'empty_blob_bytes' => 1],
            ],
            $target_pdo
                ->query(
                    'SELECT id, ST_AsText(location) AS location, ' .
                    'OCTET_LENGTH(empty_blob) AS empty_blob_bytes ' .
                    'FROM first_empty ORDER BY id'
                )
                ->fetchAll()
        );
        $this->assertSame(
            9,
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

        $columns = $target_pdo->query('SHOW FULL COLUMNS FROM mixed_geometry')->fetchAll();
        $columns_by_name = array_column($columns, null, 'Field');
        $this->assertSame('YES', $columns_by_name['location']['Null']);
        $this->assertSame($location_comment, $columns_by_name['location']['Comment']);
        $this->assertSame('YES', $columns_by_name['boundary']['Null']);
        $this->assertSame('Map area', $columns_by_name['boundary']['Comment']);

        preg_match_all('/ALTER TABLE `[^`]+`\n.*?;/s', $sql, $alter_statements);
        $this->assertCount(2, $alter_statements[0]);
        foreach ($alter_statements[0] as $alter_statement) {
            $target_pdo->exec($alter_statement);
        }
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
        $target_pdo = $this->connectTarget($database);
        $target_pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $target_pdo->exec(
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $target_pdo->exec("USE `{$database}`");
        $this->target_databases[] = $database;
        $target_pdo->exec($sql);
        return $target_pdo;
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
