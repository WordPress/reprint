<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class NginxRuntimeConfigTest extends TestCase {
    private string $directory;

    /** Create an isolated runtime directory. */
    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/nginx-runtime-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    /** Remove files created by this test. */
    protected function tearDown(): void {
        $this->remove_directory($this->directory);
    }

    /** Quoted roots and host names remain single nginx parameters. */
    public function testQuotesRootAndHostWithSpacesAndPunctuation(): void {
        $root = $this->directory . '/site name;{draft}"quote\'\\path#name';
        mkdir($root);
        $host = 'local;{draft}"quote\'\\host';
        $manifest = new \RuntimeManifest('other');
        $manifest->php_ini = ['memory_limit' => '256M'];
        $applier = new \NginxFpmApplier();
        $applier->apply($manifest, $root, $this->directory . '/runtime', ['host' => $host, 'port' => 8882]);
        $config = file_get_contents($this->directory . '/runtime/nginx.conf');
        $this->assertStringContainsString('root "' . strtr($root, ['\\' => '\\\\', '"' => '\\"']) . '";', $config);
        $this->assertStringContainsString('server_name "local;{draft}\\"quote\'\\\\host";', $config);
        $this->assertStringContainsString('memory_limit=256M', $config);
    }

    /** @dataProvider unsupported_values */
    public function testReportsUnsupportedConfigurationTextBeforeWritingFiles(string $field, string $value): void {
        $root = $field === 'root' ? $this->directory . '/' . $value : $this->directory;
        $output = $field === 'output' ? $this->directory . '/' . $value : $this->directory . '/runtime';
        $host = $field === 'host' ? $value : 'localhost';
        try {
            $applier = new \NginxFpmApplier();
            $applier->apply(new \RuntimeManifest('other'), $root, $output, ['host' => $host]);
            $this->fail('Expected unsupported nginx text to be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('nginx', $error->getMessage());
            $this->assertStringContainsString(json_encode($field === 'root' ? $root : ( $field === 'output' ? $output . '/runtime.php' : $host )), $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($output);
    }

    /** @return array<string, array{0: string, 1: string}> Literal fields and unsupported characters. */
    public static function unsupported_values(): array {
        $values = [];
        foreach (['root', 'host', 'output'] as $field) {
            foreach (['variable' => 'site$uri', 'newline' => "site\nname", 'carriage return' => "site\rname", 'NUL' => "site\0name"] as $name => $value) {
                $values[$field . ' ' . $name] = [$field, $value];
            }
        }
        return $values;
    }

    /** Ask nginx itself to parse the generated configuration and serve this exact root. */
    public function testNginxServesTheLiteralRootDirectory(): void {
        exec('command -v nginx', $output, $exit_code);
        if ($exit_code !== 0) {
            $this->markTestSkipped('This test requires the nginx executable.');
        }
        $nginx = trim($output[0]);
        $root = $this->directory . '/site name;{draft}"quote\'\\path#name';
        mkdir($root);
        file_put_contents($root . '/index.html', 'literal document root');
        // Use an empty include so the test does not depend on a system FPM setup.
        file_put_contents($this->directory . '/fastcgi_params', '');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr(strrchr($address, ':'), 1);
        $applier = new \NginxFpmApplier();
        $applier->apply(new \RuntimeManifest('other'), $root, $this->directory . '/runtime', ['port' => $port]);
        $config = "daemon off;\nmaster_process off;\npid {$this->directory}/nginx.pid;\nerror_log {$this->directory}/nginx.log;\nevents {}\nhttp {\naccess_log off;\n"
            . file_get_contents($this->directory . '/runtime/nginx.conf') . "\n}\n";
        file_put_contents($this->directory . '/nginx.conf', $config);
        $process = proc_open([$nginx, '-p', $this->directory . '/', '-c', 'nginx.conf', '-e', $this->directory . '/nginx.log'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/stdout', 'w'], 2 => ['file', $this->directory . '/stderr', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $body = false;
            $deadline = microtime(true) + 5;
            do {
                $body = @file_get_contents('http://' . $address . '/');
                if ($body !== false || !proc_get_status($process)['running']) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame('literal document root', $body, file_get_contents($this->directory . '/stderr'));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    /** Remove only the test's own files after the nginx process has stopped. */
    private function remove_directory(string $directory): void {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->remove_directory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
