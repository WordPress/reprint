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

    public function testReadsVersionedContextWithoutParsingTheInsert(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('10.11.18-MariaDB', []),
            '10.11.18-MariaDB',
            'test target'
        );
        $statement =
            "INSERT IGNORE INTO maps SET id = 42, location = ST_GeomFromText('POINT(7 8)', 4326);";
        $sql = $this->markedStatement($statement, [
            'v' => [[
                'h' => str_repeat('a', 64),
                'b' => 25,
                's' => 4326,
                'z' => false,
                't' => base64_encode('point'),
                'c' => base64_encode('location'),
            ]],
            'd' => false,
            'k' => [[
                'v' => base64_encode('42'),
                'n' => false,
                't' => base64_encode('int'),
                'c' => base64_encode('id'),
            ]],
            't' => base64_encode('maps'),
        ]);

        $inspection = $diagnostics->inspect($sql);
        $diagnostics->assert_supported($inspection);

        $this->assertSame('maps', $inspection['table']);
        $this->assertSame('42', $inspection['primary_key']['id']['display']);
        $this->assertSame(4326, $inspection['spatial_values'][0]['srid']);
    }

    public function testRejectsContextChangedAfterTheProducerHash(): void
    {
        $sql = $this->pointInsert(42, 4326);
        $payload_start = strlen(
            MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX .
            MySQLDumpProducer::SPATIAL_STATEMENT_CONTEXT_VERSION . ' '
        );
        $payload_end = strpos($sql, ' */', $payload_start);
        $this->assertIsInt($payload_end);
        $marker_body = substr($sql, $payload_start, $payload_end - $payload_start);
        $hash_separator = strrpos($marker_body, ' ');
        $this->assertIsInt($hash_separator);
        $payload = json_decode(
            (string) base64_decode(substr($marker_body, 0, $hash_separator)),
            true
        );
        $payload['v'][0]['c'] = base64_encode('boundary');
        $sql = MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX .
            MySQLDumpProducer::SPATIAL_STATEMENT_CONTEXT_VERSION . ' ' .
            base64_encode( (string) json_encode($payload) ) .
            substr($sql, $payload_start + $hash_separator);
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'test target'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its SQL statement');
        $diagnostics->inspect($sql);
    }

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
        $same_engine->assert_supported($same_engine->inspect($this->pointInsert(2, 4326, true)));

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
                    $diagnostics->inspect($this->pointInsert(9, 4326, true))
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
        $statement = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            "(17,NULLIF(1, 1 " . MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT . "));";
        $sql = $this->markedStatement($statement, [
            't' => base64_encode('maps'),
            'k' => [[
                'c' => base64_encode('id'),
                't' => base64_encode('int'),
                'n' => false,
                'v' => base64_encode('17'),
            ]],
            'd' => false,
            'v' => [[
                'c' => base64_encode('location'),
                't' => base64_encode('point'),
                'z' => true,
                's' => null,
                'b' => 0,
                'h' => hash('sha256', ''),
            ]],
        ]);
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

        $this->assertStringContainsString('[SPATIAL_ROW_REJECTED]', $message);
        $this->assertStringContainsString('Table: `maps`', $message);
        $this->assertStringContainsString('Row: `id` = 17', $message);
        $this->assertStringContainsString(
            'Column candidate: `location` POINT (zero bytes converted to SQL NULL)',
            $message
        );
        $this->assertStringContainsString("Check constraint 'location_required'", $message);
        $this->assertStringContainsString('Target error 3819', $message);
        $this->assertStringContainsString('Statement in SQL group: 3', $message);
        $this->assertStringContainsString('db.sql group starts at byte: 1,200', $message);
        $this->assertStringContainsString('SHOW CREATE TABLE `maps`;', $message);
        $this->assertStringContainsString('The target cursor did not advance.', $message);
        $this->assertStringContainsString(
            'The target did not report which value caused the failure.',
            $message
        );
    }

    public function testSpatialMarkerUsesStatementRowWhenTableHasNoPrimaryKey(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'test target'
        );
        $statement = 'INSERT INTO `maps` (`id`,`location`) VALUES (2,NULL);';
        $sql = $this->markedStatement($statement, [
            't' => base64_encode('maps'),
            'k' => [],
            'd' => false,
            'v' => [[
                'c' => base64_encode('location'),
                't' => base64_encode('point'),
                'z' => true,
                's' => null,
                'b' => 0,
                'h' => hash('sha256', ''),
            ]],
        ]);

        $inspection = $diagnostics->inspect($sql);

        $this->assertSame([], $inspection['primary_key']);
        $message = $diagnostics->describe_target_failure(
            new PDOException('Target rejected the row.', 1),
            $inspection,
            1,
            null,
            $sql
        );
        $this->assertStringContainsString(
            'Row: statement row 1 (table has no primary key)',
            $message
        );
    }

    public function testRejectsSpatialMarkerWhoseStatementHashDoesNotMatch(): void
    {
        $statement = 'INSERT INTO `maps` (`id`,`location`) VALUES (17,NULL);';
        $sql = $this->markedStatement($statement, [
            't' => base64_encode('maps'),
            'k' => [[
                'c' => base64_encode('id'),
                't' => base64_encode('int'),
                'n' => false,
                'v' => base64_encode('17'),
            ]],
            'd' => false,
            'v' => [[
                'c' => base64_encode('location'),
                't' => base64_encode('point'),
                'z' => true,
                's' => null,
                'b' => 0,
                'h' => hash('sha256', ''),
            ]],
        ]);
        $sql = str_replace('VALUES (17,NULL)', 'VALUES (18,NULL)', $sql);
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '10.11.18-MariaDB',
            'test target'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its SQL statement');
        $diagnostics->inspect($sql);
    }

    public function testRejectsUnknownSpatialContextVersion(): void
    {
        $sql = str_replace(
            MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX .
                MySQLDumpProducer::SPATIAL_STATEMENT_CONTEXT_VERSION . ' ',
            MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX . 'v2 ',
            $this->pointInsert(42, 4326)
        );
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '8.0.45',
            'test target'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported context version v2');
        $diagnostics->inspect($sql);
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

    public function testMarkerTextInsideSqlIsNotTreatedAsProducerContext(): void
    {
        $diagnostics = new SpatialStatementDiagnostics(
            $this->targetDatabase('8.0.46', [4326]),
            '8.0.45',
            'test target'
        );
        $sql = "CREATE TABLE notes (value TEXT COMMENT '" .
            MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX . "not metadata */');";

        $this->assertNull($diagnostics->inspect($sql));
    }

    public function testUnmarkedCorruptSpatialDumpIsNotClassified(): void
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
        $this->assertNull($inspection);
    }

    private function pointInsert(
        int $id,
        int $srid,
        bool $source_uses_srs_definitions = false
    ): string
    {
        $point_wkb = hex2bin('01010000000000000000001c400000000000002040');
        $stored_value = pack('V', $srid) . $point_wkb;
        $statement = "INSERT INTO `maps` (`id`,`location`) VALUES\n" .
            "({$id},FROM_BASE64('" . base64_encode(pack('V', $srid) . $point_wkb) . "'));";
        if ($srid === 0) {
            return $statement;
        }
        return $this->markedStatement($statement, [
            't' => base64_encode('maps'),
            'k' => [[
                'c' => base64_encode('id'),
                't' => base64_encode('int'),
                'n' => false,
                'v' => base64_encode( (string) $id ),
            ]],
            'd' => $source_uses_srs_definitions,
            'v' => [[
                'c' => base64_encode('location'),
                't' => base64_encode('point'),
                'z' => false,
                's' => $srid,
                'b' => strlen($stored_value),
                'h' => hash('sha256', $stored_value),
            ]],
        ]);
    }

    private function markedStatement(string $statement, array $payload): string
    {
        $context_json = (string) json_encode($payload);
        return MySQLDumpProducer::SPATIAL_STATEMENT_COMMENT_PREFIX .
            MySQLDumpProducer::SPATIAL_STATEMENT_CONTEXT_VERSION . ' ' .
            base64_encode($context_json) . ' ' .
            hash('sha256', $context_json . "\n" . $statement) . " */\n" . $statement;
    }

    /**
     * @param int[] $known_srids
     */
    private function targetDatabase(
        string $version,
        array $known_srids
    ): PdoDatabaseConnection
    {
        $pdo = new PDO('sqlite::memory:');
        return new class($pdo, $version, $known_srids) extends PdoDatabaseConnection {
            private string $version;

            /** @var int[] */
            private array $known_srids;

            public function __construct(
                PDO $pdo,
                string $version,
                array $known_srids
            )
            {
                parent::__construct($pdo);
                $this->version = $version;
                $this->known_srids = $known_srids;
            }

            public function query(string $sql, array $params = []): DatabaseResult
            {
                if (strpos($sql, 'SELECT VERSION()') === 0) {
                    return parent::query('SELECT ? AS version', [$this->version]);
                }
                if (strpos($sql, 'FROM INFORMATION_SCHEMA.TABLES') !== false) {
                    $uses_srs_definitions = strpos($this->version, 'MariaDB') === false
                        && (int) $this->version >= 8;
                    return parent::query(
                        'SELECT ? AS uses_srs_definitions',
                        [$uses_srs_definitions ? 1 : 0]
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
