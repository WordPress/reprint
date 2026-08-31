<?php

require_once __DIR__ . '/../../packages/reprint-client/src/lib/mysql-query-stream/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/import/load.php';

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\DatabaseResult;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\SpatialStatementDiagnostics;
use WordPress\Reprint\Server\MySQLDumpProducer;

class SpatialStatementDiagnosticsTest extends TestCase {

    public function testRejectsKnownNonzeroSridAcrossDatabaseEngines(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );
        $sql = $this->pointInsert(42, 4326);

        $this->expectException(RuntimeException::class);
        try {
            $diagnostics->assert_supported($diagnostics->inspect($sql));
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            $this->assertStringContainsString('[SPATIAL_AXIS_ORDER_UNSAFE]', $message);
            $this->assertStringContainsString('Source: MariaDB 10.11.18-MariaDB', $message);
            $this->assertStringContainsString('Target: MySQL 8.0.46', $message);
            $this->assertStringContainsString('Table: `maps`', $message);
            $this->assertStringContainsString('Row: `id` = 42', $message);
            $this->assertStringContainsString('Column: `location` POINT', $message);
            $this->assertStringContainsString('SRID: 4326', $message);
            $this->assertStringContainsString('Stored value: 25 bytes', $message);
            $this->assertMatchesRegularExpression('/SHA-256: [a-f0-9]{64}/', $message);
            $this->assertStringContainsString('WHERE `id` = 42;', $message);
            $this->assertStringContainsString('The row was not inserted.', $message);
            throw $error;
        }
    }

    public function testReportsUnknownMysqlSridBeforeAxisOrder(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );

        $this->expectException(RuntimeException::class);
        try {
            $diagnostics->assert_supported(
                $diagnostics->inspect($this->pointInsert(73, 999999))
            );
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            $this->assertStringContainsString('[SPATIAL_SRID_UNKNOWN]', $message);
            $this->assertStringContainsString('Row: `id` = 73', $message);
            $this->assertStringContainsString('SRID: 999999', $message);
            $this->assertStringContainsString(
                'FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS',
                $message
            );
            $this->assertStringNotContainsString('SPATIAL_AXIS_ORDER_UNSAFE', $message);
            throw $error;
        }
    }

    public function testAllowsSridZeroAcrossEnginesAndNonzeroSridOnSameEngine(): void
    {
        $mysql_target = $this->targetDatabase('8.0.46', [4326]);
        $cross_engine = new SpatialStatementDiagnostics(
            $mysql_target,
            '10.11.18-MariaDB',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );
        $cross_engine->assert_supported($cross_engine->inspect($this->pointInsert(1, 0)));

        $same_engine = new SpatialStatementDiagnostics(
            $mysql_target,
            '8.0.45',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );
        $same_engine->assert_supported($same_engine->inspect($this->pointInsert(2, 4326)));

        $this->addToAssertionCount(2);
    }

    public function testComparesSrsBehaviorInsteadOfOnlyServerProductNames(): void
    {
        foreach ([
            ['10.11.18-MariaDB', '5.7.44'],
            ['5.7.44', '10.11.18-MariaDB'],
        ] as [$source_version, $target_version]) {
            $diagnostics = new SpatialStatementDiagnostics(
                $this->targetDatabase($target_version, []),
                $source_version,
                'test target'
            );
            $diagnostics->assert_supported($diagnostics->inspect($this->pointInsert(8, 999999)));
        }

        foreach ([
            ['8.0.45', '5.7.44'],
            ['8.0.45', '10.11.18-MariaDB'],
        ] as [$source_version, $target_version]) {
            $diagnostics = new SpatialStatementDiagnostics(
                $this->targetDatabase($target_version, []),
                $source_version,
                'test target'
            );
            try {
                $diagnostics->assert_supported(
                    $diagnostics->inspect($this->pointInsert(9, 4326))
                );
                $this->fail('Expected different SRS behavior to stop the INSERT.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('[SPATIAL_AXIS_ORDER_UNSAFE]', $error->getMessage());
            }
        }

        $this->addToAssertionCount(2);
    }

    public function testReportsCheckRejectingNormalizedZeroByteValue(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );
        $marker_payload = base64_encode( (string) json_encode([
            't' => base64_encode('maps'),
            'r' => 1,
            'k' => [
                [
                    'c' => base64_encode('id'),
                    'n' => false,
                    'v' => base64_encode('17'),
                ],
            ],
            'c' => [base64_encode('location')],
        ]));
        $sql = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            MySQLDumpProducer::ZERO_BYTE_SPATIAL_ROW_COMMENT_PREFIX .
            $marker_payload . " */(17,NULLIF(1, 1 " .
            MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT . "));";
        $inspection = $diagnostics->inspect($sql);
        $error = new PDOException(
            "The target database statement failed: Check constraint 'location_required' is violated.",
            3819
        );

        $message = $diagnostics->describe_target_failure(
            $error,
            $inspection,
            3,
            1200,
            $sql
        );

        $this->assertStringContainsString('[SPATIAL_NULL_CONSTRAINT]', $message);
        $this->assertStringContainsString('Table: `maps`', $message);
        $this->assertStringContainsString('Row: `id` = 17', $message);
        $this->assertStringContainsString('Column: `location` POINT', $message);
        $this->assertStringContainsString('Conversion: zero bytes -> SQL NULL', $message);
        $this->assertStringContainsString("Target constraint: 'location_required'", $message);
        $this->assertStringContainsString('Target error 3819', $message);
        $this->assertStringContainsString('Statement in SQL group: 3', $message);
        $this->assertStringContainsString('db.sql group starts at byte: 1,200', $message);
        $this->assertStringContainsString('SHOW CREATE TABLE `maps`;', $message);
        $this->assertStringContainsString('The target cursor did not advance.', $message);
    }

    public function testZeroByteMarkerUsesStatementRowWhenTableHasNoPrimaryKey(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326], false),
            '10.11.18-MariaDB',
            'test target'
        );
        $point = pack('V', 0) . hex2bin('01010000000000000000001c400000000000002040');
        $marker_payload = base64_encode( (string) json_encode([
            't' => base64_encode('maps'),
            'r' => 2,
            'k' => [],
            'c' => [base64_encode('location')],
        ]) );
        $sql = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            "(1,FROM_BASE64('" . base64_encode($point) . "'))," .
            MySQLDumpProducer::ZERO_BYTE_SPATIAL_ROW_COMMENT_PREFIX .
            $marker_payload . ' */(2,NULL);';

        $inspection = $diagnostics->inspect($sql);

        $this->assertCount(1, $inspection['zero_byte_values']);
        $this->assertSame(2, $inspection['zero_byte_values'][0]['row_number']);
    }

    public function testRejectsZeroByteMarkersWhichDoNotIdentifyAnExactSpatialRow(): void
    {
        $primary_key = [
            [
                'c' => base64_encode('id'),
                'n' => false,
                'v' => base64_encode('17'),
            ],
        ];
        $valid = [
            't' => base64_encode('maps'),
            'r' => 1,
            'k' => $primary_key,
            'c' => [base64_encode('location')],
        ];
        $cases = [
            [array_diff_key($valid, ['r' => true]), 'invalid row number'],
            [array_merge($valid, ['r' => 2]), 'but the INSERT row count is 1'],
            [array_merge($valid, ['t' => base64_encode('other')]), 'but its INSERT names `maps`'],
            [array_merge($valid, ['k' => []]), 'but the target primary key is `id`'],
            [array_merge($valid, ['c' => [base64_encode('id')]]), 'target reports type int'],
            [array_merge($valid, ['t' => rtrim(base64_encode('maps'), '=')]), 'invalid base64'],
        ];
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'test target'
        );

        foreach ($cases as [$payload, $expected_message]) {
            $marker = base64_encode( (string) json_encode($payload) );
            $sql = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
                MySQLDumpProducer::ZERO_BYTE_SPATIAL_ROW_COMMENT_PREFIX .
                $marker . ' */(17,NULL);';
            try {
                $diagnostics->inspect($sql);
                $this->fail('Expected the invalid zero-byte row marker to be rejected.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString($expected_message, $error->getMessage());
            }
        }
    }

    public function testDoesNotBlameAnOrdinarySqlNullForAnUnrelatedTargetFailure(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '8.0.45',
            'test target'
        );
        $sql = "INSERT INTO `maps` (`id`,`location`) VALUES\n(23,NULL);";
        $inspection = $diagnostics->inspect($sql);
        $error = new PDOException('Duplicate entry for key label', 1062);

        $this->assertNull(
            $diagnostics->describe_target_failure($error, $inspection, 1, null, $sql)
        );
    }

    public function testCorruptSpatialDumpReportsRejectedValueContext(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '8.0.45',
            'engine=mysql host=mysql8 port=3306 db=wordpress user=root'
        );
        $stored_value = pack('V', 0) . "broken";
        $sql = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            "(23,FROM_BASE64('" . base64_encode($stored_value) . "'));";
        $inspection = $diagnostics->inspect($sql);
        $error = new PDOException(
            'The target database statement failed: Cannot get geometry object from data you send to the GEOMETRY field',
            1416
        );

        $message = $diagnostics->describe_target_failure(
            $error,
            $inspection,
            1,
            null,
            $sql
        );

        $this->assertStringContainsString('[SPATIAL_VALUE_REJECTED]', $message);
        $this->assertStringContainsString('Row: `id` = 23', $message);
        $this->assertStringContainsString('Column: `location` POINT', $message);
        $this->assertStringContainsString('Stored value: 10 bytes', $message);
        $this->assertStringContainsString('SRID: 0', $message);
        $this->assertStringContainsString('SHA-256: ' . hash('sha256', $stored_value), $message);
        $this->assertStringContainsString('Target error 1416', $message);
        $this->assertStringContainsString('SQL statement SHA-256: ' . hash('sha256', $sql), $message);
        $this->assertStringContainsString(
            'Reprint did not replace this nonzero value with NULL.',
            $message
        );
    }

    private function pointInsert(int $id, int $srid): string
    {
        $point_wkb = hex2bin('01010000000000000000001c400000000000002040');
        return "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            "({$id},FROM_BASE64('" . base64_encode(pack('V', $srid) . $point_wkb) . "'));";
    }

    /**
     * @param int[] $known_srids
     */
    private function targetDatabase(
        string $version,
        array $known_srids,
        bool $has_primary_key = true
    ): PdoDatabaseConnection
    {
        $pdo = new PDO('sqlite::memory:');
        return new class($pdo, $version, $known_srids, $has_primary_key) extends PdoDatabaseConnection {
            private string $version;

            /** @var int[] */
            private array $known_srids;

            private bool $has_primary_key;

            public function __construct(
                PDO $pdo,
                string $version,
                array $known_srids,
                bool $has_primary_key
            )
            {
                parent::__construct($pdo);
                $this->version = $version;
                $this->known_srids = $known_srids;
                $this->has_primary_key = $has_primary_key;
            }

            public function query(string $sql, array $params = []): DatabaseResult
            {
                if (strpos($sql, 'SELECT VERSION()') === 0) {
                    return parent::query('SELECT ? AS version', [$this->version]);
                }
                if (strpos($sql, 'SHOW FULL COLUMNS FROM `maps`') === 0) {
                    return parent::query(
                        "SELECT 'id' AS Field, 'int' AS Type " .
                        "UNION ALL SELECT 'location', 'point'"
                    );
                }
                if (strpos($sql, 'SHOW KEYS FROM `maps`') === 0) {
                    if (!$this->has_primary_key) {
                        return parent::query("SELECT 'PRIMARY' AS Key_name WHERE 0");
                    }
                    return parent::query(
                        "SELECT 'PRIMARY' AS Key_name, 1 AS Seq_in_index, 'id' AS Column_name"
                    );
                }
                if (strpos($sql, 'SELECT SRS_ID FROM INFORMATION_SCHEMA') === 0) {
                    $srid = (int) ( $params[0] ?? 0 );
                    if (in_array($srid, $this->known_srids, true)) {
                        return parent::query('SELECT ? AS SRS_ID', [$srid]);
                    }
                    return parent::query('SELECT 1 AS SRS_ID WHERE 0');
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test failure includes the unexpected SQL.
                throw new RuntimeException('Unexpected target metadata query: ' . $sql);
            }
        };
    }
}
