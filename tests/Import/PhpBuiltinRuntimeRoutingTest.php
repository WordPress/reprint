<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class PhpBuiltinRuntimeRoutingTest extends TestCase
{
    private string $tempDir;
    private string $docRoot;
    private string $coreRoot;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/php-builtin-routing-' . uniqid('', true);
        $this->docRoot = $this->tempDir . '/docroot';
        $this->coreRoot = $this->tempDir . '/wordpress/core/7.0';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->docRoot . '/wp-content/plugins/example', 0755, true);
        mkdir($this->coreRoot . '/wp-admin/css', 0755, true);

        file_put_contents($this->coreRoot . '/index.php', "<?php echo 'core-index';\n");
        file_put_contents(
            $this->coreRoot . '/wp-admin/install.php',
            "<?php echo 'core-install ' . json_encode([" .
                "'SCRIPT_NAME' => \$_SERVER['SCRIPT_NAME'] ?? null, " .
                "'SCRIPT_FILENAME' => \$_SERVER['SCRIPT_FILENAME'] ?? null" .
            "], JSON_UNESCAPED_SLASHES);\n",
        );
        file_put_contents($this->coreRoot . '/wp-admin/css/install.css', "body{color:#111}\n");
        file_put_contents(
            $this->docRoot . '/wp-content/plugins/example/site.php',
            "<?php echo 'docroot-plugin ' . \$_SERVER['SCRIPT_FILENAME'];\n",
        );

        $manifest = new \RuntimeManifest('other');
        $applier = new \PhpBuiltinApplier();
        $applier->apply($manifest, $this->docRoot, $this->outputDir, [
            'wordpress_index_php' => $this->coreRoot . '/index.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
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

    private function runRuntime(string $requestUri): string
    {
        $oldServer = $_SERVER;
        $bufferLevel = ob_get_level();

        $_SERVER['DOCUMENT_ROOT'] = $this->docRoot;
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['SCRIPT_NAME'] = '/runtime.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->outputDir . '/runtime.php';
        $_SERVER['PHP_SELF'] = '/runtime.php';
        unset($_SERVER['PATH_INFO']);

        ob_start();
        try {
            include $this->outputDir . '/runtime.php';
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_SERVER = $oldServer;
        }
    }

    public function testRoutesCorePhpFilesOutsideDocumentRoot(): void
    {
        $output = $this->runRuntime('/wp-admin/install.php');

        $this->assertStringContainsString('core-install', $output);
        $this->assertStringContainsString('"SCRIPT_NAME":"/wp-admin/install.php"', $output);
        $resolvedInstall = realpath($this->coreRoot . '/wp-admin/install.php');
        $this->assertStringContainsString(
            '"SCRIPT_FILENAME":"' . $resolvedInstall,
            $output,
        );
    }

    public function testDocumentRootPhpFilesTakePrecedenceOverCoreFallback(): void
    {
        $output = $this->runRuntime('/wp-content/plugins/example/site.php');

        $this->assertStringContainsString('docroot-plugin', $output);
        $this->assertStringContainsString(
            $this->docRoot . '/wp-content/plugins/example/site.php',
            $output,
        );
    }

    public function testServesCoreStaticFilesOutsideDocumentRoot(): void
    {
        $output = $this->runRuntime('/wp-admin/css/install.css');

        $this->assertSame("body{color:#111}\n", $output);
    }

    /**
     * Run the generated Bash script and check the arguments received by PHP.
     *
     * @dataProvider startScriptHosts
     */
    public function testStartScriptPreservesArgumentsAndPrintedAddress(string $host): void
    {
        $command_dir = $this->tempDir . '/commands';
        mkdir($command_dir);
        // Record argv instead of starting a server which would keep the test running.
        file_put_contents(
            $command_dir . '/php',
            '#!' . PHP_BINARY . "\n<?php\n" .
                'file_put_contents(__DIR__ . "/arguments.json", json_encode(array_slice($argv, 1)));' . "\n",
        );
        chmod($command_dir . '/php', 0755);

        $manifest = new \RuntimeManifest('other');
        $manifest->php_ini = [
            'memory_limit' => '256M',
            'include_path' => 'directory with spaces:$HOME:$(printf expanded):`printf expanded`',
            'error_log' => "quotes'\";backslash\\\nnext line",
            'setting with spaces' => 'one argument',
        ];
        $filesystem_root = $this->docRoot . " spaces'\"\$HOME;";
        $output_dir = $this->outputDir . " spaces'\"\$HOME;";
        mkdir($filesystem_root);
        $applier = new \PhpBuiltinApplier();
        $applier->apply($manifest, $filesystem_root, $output_dir, ['host' => $host, 'port' => 8882]);

        $process = proc_open(
            ['bash', $output_dir . '/start.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $command_dir . ':' . getenv('PATH')],
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        $expected_arguments = [];
        foreach ($manifest->php_ini as $key => $value) {
            $expected_arguments[] = '-d';
            $expected_arguments[] = $key . '=' . $value;
        }
        $expected_arguments = array_merge($expected_arguments, ['-S', $host . ':8882', '-t', $filesystem_root, $output_dir . '/runtime.php']);
        $this->assertSame($expected_arguments, json_decode(file_get_contents($command_dir . '/arguments.json'), true));
        $this->assertSame("Starting PHP built-in server...\n  http://{$host}:8882\n\n", $output);
    }

    /** @return array<string, array{0: string}> Addresses whose text must be preserved. */
    public static function startScriptHosts(): array
    {
        return [
            'ordinary address' => ['localhost'],
            'address with punctuation' => ["local'\"\$HOME\$(printf expanded)`printf expanded`;host\nnext line"],
        ];
    }

    public function testGeneratedRuntimeKeepsJitEnabledWhenAvoidingPhp84Mode1235(): void
    {
        $runtime = file_get_contents($this->outputDir . '/runtime.php');

        $this->assertStringContainsString("ini_get('opcache.jit') === '1235'", $runtime);
        $this->assertStringContainsString("ini_set('opcache.jit', 'tracing')", $runtime);
        $this->assertStringNotContainsString("ini_set('opcache.jit', 'off')", $runtime);
        $this->assertStringNotContainsString("ini_set('opcache.jit', 'disable')", $runtime);
    }
}
