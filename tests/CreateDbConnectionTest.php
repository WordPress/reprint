<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CreateDbConnectionTest extends TestCase {
    public function testMysqlSessionUsesStableCharsetAndCollation(): void
    {
        $autoload_path = realpath(__DIR__ . '/../vendor/autoload.php');
        $export_path = realpath(__DIR__ . '/../packages/reprint-server/src/export.php');
        $this->assertIsString($autoload_path);
        $this->assertIsString($export_path);
        $connection_script = <<<'PHP'
        require $argv[1];
        require $argv[2];
        $mysql = create_db_connection(
            [
                'db_engine' => 'mysql',
                'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
                'db_name' => 'information_schema',
                'db_user' => getenv('DB_USER') ?: 'root',
                'db_password' => getenv('DB_PASS') ?: '',
            ],
            [
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET NAMES latin1 COLLATE latin1_swedish_ci",
            ]
        );
        $session = $mysql->query(
            "SELECT
                @@character_set_client AS character_set_client,
                @@character_set_connection AS character_set_connection,
                @@character_set_results AS character_set_results,
                @@collation_connection AS collation_connection"
        )->fetch(PDO::FETCH_ASSOC);
        fwrite(STDOUT, json_encode($session));
        PHP;

        $result = $this->runPhpProcess([
            PHP_BINARY,
            '-r',
            $connection_script,
            $autoload_path,
            $export_path,
        ]);
        $this->assertSame(
            0,
            $result['status'],
            $result['stderr'] . $result['stdout']
        );

        $this->assertSame(
            [
                'character_set_client' => 'utf8mb4',
                'character_set_connection' => 'utf8mb4',
                'character_set_results' => 'utf8mb4',
                'collation_connection' => 'utf8mb4_bin',
            ],
            json_decode($result['stdout'], true)
        );
    }

    public function testWpdbFallbackUsesStableCharsetAndCollation(): void
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped(
                'Overriding extension_loaded() requires PHP 8.0 or later.'
            );
        }

        $autoload_path = realpath(__DIR__ . '/../vendor/autoload.php');
        $export_path = realpath(__DIR__ . '/../packages/reprint-server/src/export.php');
        $this->assertIsString($autoload_path);
        $this->assertIsString($export_path);
        $connection_script = <<<'PHP'
        function extension_loaded($extension) {
            return $extension !== 'pdo_mysql';
        }
        class WpdbSessionTestDouble {
            public $last_error = '';
            public $queries = [];
            public function suppress_errors($suppress = true) {
            }
            public function hide_errors() {
            }
            public function get_results($query, $output) {
                $this->queries[] = [$query, $output];
                return [];
            }
        }
        require $argv[1];
        require $argv[2];
        $wpdb = new WpdbSessionTestDouble();
        $GLOBALS['wpdb'] = $wpdb;
        create_db_connection(
            [
                'db_engine' => 'mysql',
                'db_host' => 'unused',
                'db_name' => 'unused',
                'db_user' => 'unused',
                'db_password' => 'unused',
            ]
        );
        fwrite(STDOUT, json_encode($wpdb->queries));
        PHP;

        $result = $this->runPhpProcess([
            PHP_BINARY,
            '-d',
            'disable_functions=extension_loaded',
            '-r',
            $connection_script,
            $autoload_path,
            $export_path,
        ]);
        $this->assertSame(
            0,
            $result['status'],
            $result['stderr'] . $result['stdout']
        );
        $this->assertSame(
            [
                [
                    "SET NAMES utf8mb4 COLLATE utf8mb4_bin",
                    'ARRAY_A',
                ],
            ],
            json_decode($result['stdout'], true)
        );
    }

    public function testBareSocketPathDbHostOpensUnixSocket(): void
    {
        $this->assertDbHostOpensUnixSocket(false);
    }

    public function testHostnameAndSocketPathDbHostOpensUnixSocket(): void
    {
        $this->assertDbHostOpensUnixSocket(true);
    }

    private function assertDbHostOpensUnixSocket(bool $include_hostname): void
    {
        $socket_path = tempnam(sys_get_temp_dir(), 'reprint-db-socket-');
        $this->assertIsString($socket_path);
        unlink($socket_path);

        $socket_listener = stream_socket_server(
            'unix://' . $socket_path,
            $socket_error_code,
            $socket_error_message
        );
        $this->assertIsResource(
            $socket_listener,
            "{$socket_error_code}: {$socket_error_message}"
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = null;
        $db_host = $include_hostname
            ? 'localhost:' . $socket_path
            : $socket_path;
        $autoload_path = realpath(__DIR__ . '/../vendor/autoload.php');
        $export_path = realpath(__DIR__ . '/../packages/reprint-server/src/export.php');
        $this->assertIsString($autoload_path);
        $this->assertIsString($export_path);
        $connection_script = <<<'PHP'
        require $argv[1];
        require $argv[2];
        try {
            create_db_connection(
                [
                    'db_engine' => 'mysql',
                    'db_host' => $argv[3],
                    'db_name' => 'unused',
                    'db_user' => 'unused',
                    'db_password' => 'unused',
                ],
                [PDO::ATTR_TIMEOUT => 1]
            );
            fwrite(STDERR, "The connection unexpectedly completed.\n");
            exit(2);
        } catch (PDOException $exception) {
            fwrite(STDERR, $exception->getMessage());
        }
        PHP;

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $connection_script, $autoload_path, $export_path, $db_host],
                $descriptors,
                $pipes
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);

            $socket_connection = @stream_socket_accept($socket_listener, 3);
            $socket_was_opened = is_resource($socket_connection);
            if ($socket_was_opened) {
                fclose($socket_connection);
            }

            stream_get_contents($pipes[1]);
            $connection_error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];

            $process_status = proc_close($process);
            $process = null;
            $this->assertTrue(
                $socket_was_opened,
                "PDO did not open the Unix socket. {$connection_error}"
            );
            $this->assertSame(
                0,
                $process_status,
                "The PDO connection process failed. {$connection_error}"
            );
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            fclose($socket_listener);
            @unlink($socket_path);
        }
    }

    /**
     * Runs a PHP subprocess and captures its result.
     *
     * @param string[] $command Command and arguments.
     * @return array {
     *     @type string $stdout Standard output.
     *     @type string $stderr Standard error.
     *     @type int    $status Exit status.
     * }
     */
    private function runPhpProcess(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'status' => $status,
        ];
    }
}
