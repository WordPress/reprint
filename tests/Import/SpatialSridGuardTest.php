<?php

require_once __DIR__ . '/../../packages/reprint-client/src/lib/mysql-query-stream/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/import/load.php';

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\DatabaseResult;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\SpatialSridGuard;
use WordPress\Reprint\Server\MySQLDumpProducer;

class SpatialSridGuardTest extends TestCase {

    public function testStopsNonzeroSridWhenTargetUsesDifferentSrsRules(): void
    {
        $guard = $this->guard(false, true, '8.0.46');

        try {
            $guard->assert_statement_supported($this->markedPointInsert(42, 4326));
            $this->fail('Different source and target SRS rules should stop the INSERT.');
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            $this->assertStringContainsString('[SPATIAL_AXIS_ORDER_UNSAFE]', $message);
            $this->assertStringContainsString('Source: MariaDB 10.11.18-MariaDB', $message);
            $this->assertStringContainsString('Target: MySQL 8.0.46', $message);
            $this->assertStringContainsString('Table: `maps`', $message);
            $this->assertStringContainsString('Row: `id` = 42', $message);
            $this->assertStringContainsString('Column: `location`, SRID 4326', $message);
            $this->assertStringContainsString('The INSERT batch was not executed.', $message);
        }
    }

    public function testStopsTheOppositeSrsRuleCombination(): void
    {
        $guard = $this->guard(true, false, '10.11.18-MariaDB', '8.0.46');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[SPATIAL_AXIS_ORDER_UNSAFE]');
        $guard->assert_statement_supported($this->markedPointInsert(42, 4326));
    }

    public function testAllowsNonzeroSridWhenSourceAndTargetUseTheSameSrsRules(): void
    {
        $this->guard(false, false, '10.11.18-MariaDB')
            ->assert_statement_supported($this->markedPointInsert(1, 999999));
        $this->guard(true, true, '8.0.46', '8.0.45')
            ->assert_statement_supported($this->markedPointInsert(2, 4326));

        $this->addToAssertionCount(2);
    }

    public function testIgnoresOrdinaryStatements(): void
    {
        $guard = $this->guard(null, true, '8.0.46');
        $guard->assert_statement_supported(
            "INSERT INTO `maps` (`id`,`location`) VALUES (1,NULL);"
        );
        $guard->assert_statement_supported(
            "CREATE TABLE notes (value TEXT COMMENT '" .
            MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX . "not metadata */');"
        );

        $this->addToAssertionCount(2);
    }

    public function testRejectsUnknownMarkerVersion(): void
    {
        $sql = str_replace(
            MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX .
                MySQLDumpProducer::NONZERO_SRID_CONTEXT_VERSION . ' ',
            MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX . 'v2 ',
            $this->markedPointInsert(42, 4326)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported context version v2');
        $this->guard(false, true, '8.0.46')->assert_statement_supported($sql);
    }

    public function testRejectsInvalidMarkerFields(): void
    {
        $sql = $this->markedStatement([
            'wrong' => base64_encode('Table: `maps`'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid object shape');
        $this->guard(false, true, '8.0.46')->assert_statement_supported($sql);
    }

    public function testRequiresSourceRulesOnlyForAMarkedStatement(): void
    {
        $guard = $this->guard(null, true, '8.0.46');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source preflight did not report spatial reference rules');
        $guard->assert_statement_supported($this->markedPointInsert(42, 4326));
    }

    private function markedPointInsert(int $id, int $srid): string
    {
        return $this->markedStatement([
            'row_b64' => base64_encode(
                "Table: `maps`\n" .
                "Row: `id` = {$id}\n" .
                "Column: `location`, SRID {$srid}"
            ),
        ]);
    }

    private function markedStatement(array $context): string
    {
        return "INSERT INTO `maps` (`id`,`location`) VALUES (1,NULL),\n" .
            MySQLDumpProducer::NONZERO_SRID_COMMENT_PREFIX .
            MySQLDumpProducer::NONZERO_SRID_CONTEXT_VERSION . ' ' .
            json_encode($context) . " */\n" .
            "(42,NULL),(99,NULL);";
    }

    private function guard(
        ?bool $source_uses_srs_definitions,
        bool $target_uses_srs_definitions,
        string $target_version,
        string $source_version = '10.11.18-MariaDB'
    ): SpatialSridGuard {
        $pdo = new PDO('sqlite::memory:');
        $database = new class($pdo, $target_version, $target_uses_srs_definitions) extends PdoDatabaseConnection {
            private string $version;
            private bool $uses_srs_definitions;

            public function __construct(PDO $pdo, string $version, bool $uses_srs_definitions)
            {
                parent::__construct($pdo);
                $this->version = $version;
                $this->uses_srs_definitions = $uses_srs_definitions;
            }

            public function query(string $sql, array $params = []): DatabaseResult
            {
                if (strpos($sql, 'SELECT VERSION()') === 0) {
                    return parent::query('SELECT ? AS version', [$this->version]);
                }
                if (strpos($sql, 'FROM INFORMATION_SCHEMA.TABLES') !== false) {
                    return parent::query(
                        'SELECT ? AS uses_srs_definitions',
                        [$this->uses_srs_definitions ? 1 : 0]
                    );
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test failure includes the unexpected SQL.
                throw new RuntimeException('Unexpected target metadata query: ' . $sql);
            }
        };
        return new SpatialSridGuard(
            $database,
            $source_version,
            $source_uses_srs_definitions,
            'test target'
        );
    }
}
