<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify deactivate_host_plugins() rewrites active_plugins identically on
 * MySQL and SQLite targets. Regression: prepare() used to throw "object
 * is uninitialized" against the WP_PDO_MySQL_On_SQLite wrapper.
 */
class DeactivateHostPluginsTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private ?PDO $cleanupPdo = null;
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
        if ($this->cleanupPdo !== null && $this->mysqlDbName !== null) {
            try {
                $this->cleanupPdo->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
            } catch (PDOException $_) {
                // best-effort
            }
        }
        $this->cleanupPdo = null;
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
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $this->insertOption($pdo, 'active_plugins', serialize([
            'sg-cachepress/sg-cachepress.php',
            'sg-security/sg-security.php',
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

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$pdo]);

        sort($result);
        $this->assertSame(
            [
                'sg-cachepress/sg-cachepress.php',
                'sg-security/sg-security.php',
            ],
            $result,
            'expected only sg-* entries to be reported as deactivated',
        );

        $remaining = unserialize($this->fetchOption($pdo, 'active_plugins'));
        $this->assertSame(
            [
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
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo, 'custom_');
        $this->insertOption($pdo, 'active_plugins', serialize([
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

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$pdo]);
        $this->assertSame(['sg-cachepress/sg-cachepress.php'], $result);

        $remaining = unserialize($this->fetchOption($pdo, 'active_plugins', 'custom_'));
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
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $serialized = serialize(['akismet/akismet.php']);
        $this->insertOption($pdo, 'active_plugins', $serialized);

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

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$pdo]);
        $this->assertSame([], $result);
        $this->assertSame($serialized, $this->fetchOption($pdo, 'active_plugins'));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testReturnsEmptyWhenActivePluginsRowMissing(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
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

        $result = $this->callPrivate($client, 'deactivate_host_plugins', [$pdo]);
        $this->assertSame([], $result);
    }

    // ---- helpers ----

    private function createPdo(string $engine): PDO
    {
        if ($engine === 'mysql') {
            return $this->createMysqlPdo();
        }
        if ($engine === 'sqlite') {
            return $this->createSqlitePdo();
        }
        $this->fail("unknown engine: {$engine}");
    }

    private function createMysqlPdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $this->mysqlDbName = 'test_deactivate_host_plugins_' . bin2hex(random_bytes(4));

        try {
            $root = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $root->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
        $root->exec("CREATE DATABASE `{$this->mysqlDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->cleanupPdo = $root;

        $pdo = new PDO(
            "mysql:host={$host};dbname={$this->mysqlDbName};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
        return $pdo;
    }

    private function createSqlitePdo(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $polyfills = resolve_sqlite_integration_path('/php-polyfills.php');
        $driver = resolve_sqlite_integration_path('/wp-pdo-mysql-on-sqlite.php');
        require_once $polyfills;
        require_once $driver;

        $dbPath = $this->tempDir . '/target.sqlite';
        $dsn = "mysql-on-sqlite:path={$dbPath};dbname=test_db";
        $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Mirror create_sqlite_target_pdo() — deactivate_host_plugins()
        // requires FROM_BASE64 on the SQLite connection.
        \register_sqlite_function($pdo->get_connection()->get_pdo(), 'FROM_BASE64', function ($data) {
            return $data === null ? null : base64_decode($data);
        });
        return $pdo;
    }

    private function createWpOptionsTable(PDO $pdo, string $prefix = 'wp_'): void
    {
        $table = '`' . $prefix . 'options`';
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        $pdo->exec(
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

    private function insertOption(PDO $pdo, string $name, string $value, string $prefix = 'wp_'): void
    {
        // Use exec() with ANSI-escaped literals to stay within the universal
        // PDO surface that WP_PDO_MySQL_On_SQLite supports.
        $table = '`' . $prefix . 'options`';
        $quotedName = $this->ansiQuote($name);
        $quotedValue = $this->ansiQuote($value);
        $pdo->exec(
            "INSERT INTO {$table} (option_name, option_value, autoload) "
            . "VALUES ({$quotedName}, {$quotedValue}, 'yes')"
        );
    }

    private function fetchOption(PDO $pdo, string $name, string $prefix = 'wp_'): string
    {
        $table = '`' . $prefix . 'options`';
        $quotedName = $this->ansiQuote($name);
        $stmt = $pdo->query(
            "SELECT option_value FROM {$table} WHERE option_name = {$quotedName}"
        );
        $this->assertNotFalse($stmt, 'query returned false');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no row for option {$name}");
        return $row['option_value'];
    }

    private function ansiQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function writeState(array $state): void
    {
        \write_current_import_state($this->makeClient(), $state);
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
