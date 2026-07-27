<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CreateDbConnectionTest extends TestCase {
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
        $export_path = realpath(__DIR__ . '/../packages/reprint-exporter/src/export.php');
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
}
