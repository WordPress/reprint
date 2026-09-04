<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use PDO;
use Reprint\Importer\Database\DatabaseConnection;
use Reprint\Importer\Database\MysqliDatabaseConnection;
use Reprint\Importer\Database\PdoDatabaseConnection;
use function Reprint\Importer\register_sqlite_function;
use function Reprint\Importer\resolve_sqlite_integration_path;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify deactivate_host_plugins() uses the shared target database API for
 * MySQL and SQLite. Regression: prepare() used to throw "object is
 * uninitialized" against the WP_PDO_MySQL_On_SQLite wrapper.
 */
class DeactivateHostPluginsTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private ?\mysqli $cleanupMysql = null;
    private ?string $mysqlDbName = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/deactivate-host-plugins-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->cleanupMysql !== null && $this->mysqlDbName !== null) {
            try {
                $this->cleanupMysql->query("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
            } catch (\Throwable $_) {
                // best-effort
            }
            $this->cleanupMysql->close();
        }
        $this->cleanupMysql = null;
        $this->mysqlDbName = null;
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public static function targetProvider(): array
    {
        return [
            'mysql' => ['mysql'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * @dataProvider targetProvider
     */
    public function testRemovesHostPluginsFromActivePluginsOption(string $engine): void
    {
        $database = $this->createDatabase($engine);
        $this->createWpOptionsTable($database);
        $this->insertOption($database, 'active_plugins', serialize([
            'sg-cachepress/sg-cachepress.php',
            'sg-security/sg-security.php',
            'sg-cachepress-extra/sg-cachepress-extra.php',
            'woocommerce/woocommerce.php',
            'akismet/akismet.php',
        ]));

        $this->writeState([
            'webhost' => 'siteground',
            'preflight' => [
                'data' => [
                    'database' => ['wp' => ['table_prefix' => 'wp_']],
                ],
            ],
        ]);
        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$database]);

        sort($result);
        $this->assertSame(
            [
                'sg-cachepress/sg-cachepress.php',
            ],
            $result,
            'expected only the SiteGround cache plugin to be reported as deactivated',
        );

        $remaining = unserialize($this->fetchOption($database, 'active_plugins'));
        $this->assertSame(
            [
                'sg-security/sg-security.php',
                'sg-cachepress-extra/sg-cachepress-extra.php',
                'woocommerce/woocommerce.php',
                'akismet/akismet.php',
            ],
            array_values($remaining),
            'expected non-host plugins to be preserved in order',
        );
    }

    /**
     * @dataProvider targetProvider
     */
    public function testHonorsCustomTablePrefix(string $engine): void
    {
        $database = $this->createDatabase($engine);
        $this->createWpOptionsTable($database, 'custom_');
        $this->insertOption($database, 'active_plugins', serialize([
            'sg-cachepress/sg-cachepress.php',
            'akismet/akismet.php',
        ]), 'custom_');

        $this->writeState([
            'webhost' => 'siteground',
            'preflight' => [
                'data' => [
                    'database' => ['wp' => ['table_prefix' => 'custom_']],
                ],
            ],
        ]);
        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$database]);
        $this->assertSame(['sg-cachepress/sg-cachepress.php'], $result);

        $remaining = unserialize($this->fetchOption($database, 'active_plugins', 'custom_'));
        $this->assertSame(['akismet/akismet.php'], array_values($remaining));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testReturnsEmptyWhenNoHostPluginsUnderPluginsDir(string $engine): void
    {
        // wpcloud only declares paths under mu-plugins and object-cache.php;
        // none match wp-content/plugins/, so deactivate should be a no-op
        // and the active_plugins value must be untouched.
        $database = $this->createDatabase($engine);
        $this->createWpOptionsTable($database);
        $serialized = serialize(['akismet/akismet.php']);
        $this->insertOption($database, 'active_plugins', $serialized);

        $this->writeState([
            'webhost' => 'wpcloud',
            'preflight' => [
                'data' => [
                    // Minimal data WpcloudHostAnalyzer::analyze() reads.
                    'runtime' => ['ini_get_all' => []],
                    'database' => ['wp' => ['table_prefix' => 'wp_']],
                ],
            ],
        ]);
        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$database]);
        $this->assertSame([], $result);
        $this->assertSame($serialized, $this->fetchOption($database, 'active_plugins'));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testReturnsEmptyWhenActivePluginsRowMissing(string $engine): void
    {
        $database = $this->createDatabase($engine);
        $this->createWpOptionsTable($database);
        // Intentionally no active_plugins row.

        $this->writeState([
            'webhost' => 'siteground',
            'preflight' => [
                'data' => [
                    'database' => ['wp' => ['table_prefix' => 'wp_']],
                ],
            ],
        ]);
        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$database]);
        $this->assertSame([], $result);
    }

    // ---- helpers ----

    private function createDatabase(string $engine): DatabaseConnection
    {
        if ($engine === 'mysql') {
            return $this->createMysqlDatabase();
        }
        if ($engine === 'sqlite') {
            return $this->createSqliteDatabase();
        }
        $this->fail("unknown engine: {$engine}");
    }

    private function createMysqlDatabase(): DatabaseConnection
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $this->mysqlDbName = 'test_deactivate_host_plugins_' . bin2hex(random_bytes(4));

        try {
            $root = new \mysqli($host, $user, $pass);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $root->query("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
        $root->query(
            "CREATE DATABASE `{$this->mysqlDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $this->cleanupMysql = $root;

        $mysqli = new \mysqli($host, $user, $pass, $this->mysqlDbName);
        $mysqli->set_charset('utf8mb4');
        return new MysqliDatabaseConnection($mysqli);
    }

    private function createSqliteDatabase(): DatabaseConnection
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $polyfills = resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/php-polyfills.php');
        $driver = resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/load.php');
        require_once $polyfills;
        require_once $driver;

        $dbPath = $this->tempDir . '/target.sqlite';
        $dsn = "mysql-on-sqlite:path={$dbPath};dbname=test_db";
        $database = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Mirror create_sqlite_target_pdo() — deactivate_host_plugins()
        // requires FROM_BASE64 on the SQLite connection.
        $sqlitePdo = $database->get_connection()->get_pdo();
        register_sqlite_function($sqlitePdo, 'FROM_BASE64', function ($data) {
            return $data === null ? null : base64_decode($data);
        });
        return new PdoDatabaseConnection($database, $sqlitePdo);
    }

    private function createWpOptionsTable(DatabaseConnection $database, string $prefix = 'wp_'): void
    {
        $table = '`' . $prefix . 'options`';
        $database->exec("DROP TABLE IF EXISTS {$table}");
        $database->exec(
            "CREATE TABLE {$table} ("
            . "`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`option_name` varchar(191) NOT NULL DEFAULT '', "
            . "`option_value` longtext NOT NULL, "
            . "`autoload` varchar(20) NOT NULL DEFAULT 'yes', "
            . "PRIMARY KEY (`option_id`), "
            . "UNIQUE KEY `option_name` (`option_name`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function insertOption(
        DatabaseConnection $database,
        string $name,
        string $value,
        string $prefix = 'wp_'
    ): void
    {
        $table = '`' . $prefix . 'options`';
        $quotedName = $database->quote($name);
        $quotedValue = $database->quote($value);
        $database->exec(
            "INSERT INTO {$table} (option_name, option_value, autoload) "
            . "VALUES ({$quotedName}, {$quotedValue}, 'yes')"
        );
    }

    private function fetchOption(
        DatabaseConnection $database,
        string $name,
        string $prefix = 'wp_'
    ): string
    {
        $table = '`' . $prefix . 'options`';
        $quotedName = $database->quote($name);
        $stmt = $database->query(
            "SELECT option_value FROM {$table} WHERE option_name = {$quotedName}"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $this->assertIsArray($row, "no row for option {$name}");
        return $row['option_value'];
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), $state);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('state');
        $property->setValue($client, $state);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }
        rmdir($dir);
    }
}
