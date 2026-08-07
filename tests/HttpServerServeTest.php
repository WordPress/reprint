<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests Site_Export_HTTP_Server::serve() — the one-call convenience
 * entry point that loads server.php, constructs the server, and
 * dispatches the current request.
 *
 * Tests run in subprocesses because serve() requires server.php, which
 * registers shutdown and error handlers at module level.
 */
final class HttpServerServeTest extends TestCase
{
    private const CLASS_PATH = __DIR__ . '/../packages/reprint-server/src/class-http-server.php';
    private const SERVER_PATH = __DIR__ . '/../packages/reprint-server/src/server.php';

    public function testServeLoadsServerPhpWhenNotYetLoaded(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'preflight';
        \$_GET['directory'] = sys_get_temp_dir();

        require '{$this->classPath()}';

        // endpoint_preflight is defined by server.php; it must not
        // exist before serve() is called.
        if (function_exists('endpoint_preflight')) {
            echo "FAIL: endpoint_preflight defined before serve()";
            exit;
        }

        Site_Export_HTTP_Server::serve();

        // serve() should have required server.php, which defines endpoint_preflight.
        echo function_exists('endpoint_preflight') ? "OK" : "FAIL: endpoint_preflight not defined";
        PHP;

        $output = $this->runScript($script);

        $this->assertStringContainsString('OK', $output);
        $this->assertStringNotContainsString('FAIL', $output);
    }

    public function testServeDoesNotRedundantlyReloadServerPhp(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'preflight';
        \$_GET['directory'] = sys_get_temp_dir();

        require '{$this->classPath()}';
        require '{$this->serverPath()}';

        // Track require side-effect: if server.php was re-required, endpoint_preflight
        // would be re-declared which would be a fatal error.
        Site_Export_HTTP_Server::serve();
        echo "OK";
        PHP;

        $output = $this->runScript($script);
        $this->assertStringContainsString('OK', $output);
    }

    public function testServeForwardsOptionsToConstructor(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'preflight';

        require '{$this->classPath()}';

        // Override the default handler for 'preflight' to verify the
        // options were passed through to the constructor.
        Site_Export_HTTP_Server::serve([
            'handlers' => [
                'preflight' => function (\$config) {
                    echo "handler-called:" . \$config['endpoint'];
                },
            ],
        ]);
        PHP;

        $output = $this->runScript($script);
        $this->assertStringContainsString('handler-called:preflight', $output);
    }

    private function classPath(): string
    {
        $path = realpath(self::CLASS_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function serverPath(): string
    {
        $path = realpath(self::SERVER_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function runScript(string $body): string
    {
        $tmp_dir = sys_get_temp_dir() . '/serve-test-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            $php_code = "<?php\n" . $body . "\n";
            file_put_contents($tmp_dir . '/run.php', $php_code);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [PHP_BINARY, $tmp_dir . '/run.php'],
                $descriptors,
                $pipes,
                $tmp_dir
            );

            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return ($stdout ?: '') . ($stderr ?: '');
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }
}
