<?php

require_once __DIR__ . '/../../packages/reprint-client/src/lib/mysql-query-stream/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/import/load.php';

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\DatabaseResult;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\NullableSpatialColumnStatementRewriter;
use WordPress\Reprint\Server\MySQLDumpProducer;

class NullableSpatialColumnStatementRewriterTest extends TestCase {

    public function testReusesCompleteTargetDefinitionsAndRemovesOnlyNotNull(): void
    {
        $table = "map,\n`records";
        $location = "location,\n`north";
        $already_nullable = 'optional_shape';
        $create_table_sql = "CREATE TABLE `map,\n``records` (\n" .
            "  `id` int NOT NULL,\n" .
            "  `location,\n``north` point NOT NULL /*!80023 INVISIBLE */ " .
                "DEFAULT (st_geomfromtext(_utf8mb4'POINT(4 5)')) " .
                "COMMENT 'North''s path, NOT NULL',\n" .
            "  `optional_shape` geometry DEFAULT NULL COMMENT 'Already nullable',\n" .
            "  KEY `location_key` (`location,\n``north`(8))\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $database = $this->databaseReturningCreateTable($create_table_sql);
        $rewriter = new NullableSpatialColumnStatementRewriter($database);
        $marker = MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX .
            implode(' ', array_map('base64_encode', [
                $table,
                $location,
                $already_nullable,
            ])) . " */";
        $marked_alter = "-- Dumping data for table `map,\\n``records`\n\n" .
            $marker . "\n" .
            "ALTER TABLE `map,\n``records`\n" .
            "MODIFY COLUMN `location,\n``north` point NULL,\n" .
            "MODIFY COLUMN `optional_shape` geometry NULL;";

        $this->assertSame(
            "ALTER TABLE `map,\n``records`\n" .
            "MODIFY COLUMN `location,\n``north` point NULL /*!80023 INVISIBLE */ " .
                "DEFAULT (st_geomfromtext(_utf8mb4'POINT(4 5)')) " .
                "COMMENT 'North''s path, NOT NULL',\n" .
            "MODIFY COLUMN `optional_shape` geometry DEFAULT NULL " .
                "COMMENT 'Already nullable';",
            $rewriter->rewrite($marked_alter)
        );
    }

    public function testLeavesOrdinaryAlterUnchangedForTheNormalImportPath(): void
    {
        $database = $this->databaseReturningCreateTable('unused');
        $rewriter = new NullableSpatialColumnStatementRewriter($database);

        $this->assertNull(
            $rewriter->rewrite('ALTER TABLE `maps` MODIFY COLUMN `location` point NULL;')
        );
        $this->assertNull($rewriter->rewrite(
            "ALTER TABLE `maps` MODIFY COLUMN `location` point NULL " .
            "COMMENT '" . MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX . "';"
        ));
    }

    public function testRejectsSpatialIndexesBeforeMakingTheirColumnsNullable(): void
    {
        $database = $this->databaseReturningCreateTable(
            'CREATE TABLE `maps` (`location` point NOT NULL, SPATIAL KEY `location_index` (`location`))',
            [
                [
                    'Key_name' => 'location_index',
                    'Column_name' => 'location',
                    'Index_type' => 'SPATIAL',
                ],
            ]
        );
        $rewriter = new NullableSpatialColumnStatementRewriter($database);
        $marker = MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX .
            implode(' ', array_map('base64_encode', ['maps', 'location'])) . " */\n" .
            'ALTER TABLE `maps` MODIFY COLUMN `location` point NULL;';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('spatial index `location_index` requires the column to remain NOT NULL');
        $rewriter->rewrite($marker);
    }

    /** @dataProvider invalidMarkerProvider */
    public function testRejectsInvalidMarkers(string $payload, string $message): void
    {
        $database = $this->databaseReturningCreateTable('unused');
        $rewriter = new NullableSpatialColumnStatementRewriter($database);
        $sql = MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX .
            $payload . " */\nALTER TABLE `maps` MODIFY COLUMN `location` point NULL;";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $rewriter->rewrite($sql);
    }

    public static function invalidMarkerProvider(): array
    {
        return [
            'invalid base64 table' => [
                'not-base64 ' . base64_encode('location'),
                'invalid base64 identifier',
            ],
            'different table' => [
                base64_encode('other_maps') . ' ' . base64_encode('location'),
                'names a different table',
            ],
        ];
    }

    /** @param array<int,array{Key_name:string,Column_name:string,Index_type:string}> $spatial_indexes */
    private function databaseReturningCreateTable(
        string $create_table_sql,
        array $spatial_indexes = []
    ): PdoDatabaseConnection
    {
        $pdo = new PDO('sqlite::memory:');
        return new class($pdo, $create_table_sql, $spatial_indexes) extends PdoDatabaseConnection {
            private string $create_table_sql;

            /** @var array<int,array{Key_name:string,Column_name:string,Index_type:string}> */
            private array $spatial_indexes;

            public function __construct(PDO $pdo, string $create_table_sql, array $spatial_indexes)
            {
                parent::__construct($pdo);
                $this->create_table_sql = $create_table_sql;
                $this->spatial_indexes = $spatial_indexes;
            }

            public function query(string $sql, array $params = []): DatabaseResult
            {
                if (strpos($sql, 'SHOW INDEX FROM ') === 0) {
                    if ($this->spatial_indexes !== []) {
                        $index = $this->spatial_indexes[0];
                        return parent::query(
                            'SELECT ? AS Key_name, ? AS Column_name, ? AS Index_type',
                            [$index['Key_name'], $index['Column_name'], $index['Index_type']]
                        );
                    }
                    return parent::query(
                        'SELECT NULL AS Key_name, NULL AS Column_name, NULL AS Index_type WHERE 0 = 1'
                    );
                }
                return parent::query(
                    'SELECT ? AS "Create Table"',
                    [$this->create_table_sql]
                );
            }

            public function exec(string $sql): int
            {
                return 0;
            }
        };
    }
}
