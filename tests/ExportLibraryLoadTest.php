<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies that requiring export.php does not authenticate requests
 * or terminate the process — it only registers functions and classes.
 *
 * Tests run in subprocesses because export.php registers shutdown and
 * error handlers at module level.
 */
final class ExportLibraryLoadTest extends TestCase {
    private const EXPORT_PATH = __DIR__ . '/../packages/reprint-exporter/src/export.php';

    public function testRequiringExportPhpDoesNotRejectMissingSecretKey(): void
    {
        $script = <<<'PHP'
        $_GET['endpoint'] = 'preflight';
        PHP;

        $result = $this->runExportWith($script);

        $this->assertStringNotContainsString('Invalid secret key', $result['output']);
        $this->assertStringContainsString('endpoint-handlers-loaded', $result['output']);
    }

    public function testRequiringExportPhpDefinesEndpointHandlers(): void
    {
        $result = $this->runExportWith('');

        $this->assertStringContainsString('endpoint-handlers-loaded', $result['output']);
    }

    public function testPluginRuntimeLoaderSkipsAutoloadAlreadyLoadedThroughSymlinkedPluginDirectory(): void
    {
        $tmp_dir = sys_get_temp_dir() . '/export-runtime-loader-test-' . uniqid('', true);
        $physical_plugin_directory = $tmp_dir . '/physical-plugin';
        $linked_plugin_directory = $tmp_dir . '/linked-plugin';
        $autoload_path = $physical_plugin_directory . '/vendor/autoload.php';
        $linked_autoload_path = $linked_plugin_directory . '/vendor/autoload.php';
        $export_path = $physical_plugin_directory . '/vendor/wp-php-toolkit/reprint-exporter/src/export.php';
        mkdir(dirname($export_path), 0755, true);
        file_put_contents($autoload_path, "<?php\nclass ComposerAutoloaderInitAliasTest {}\n");
        file_put_contents($export_path, "<?php\n");

        if (!symlink($physical_plugin_directory, $linked_plugin_directory)) {
            $this->removePath($tmp_dir);
            $this->markTestSkipped('Could not create a plugin directory symlink.');
        }

        $canonical_export_path = realpath($export_path);
        $this->assertNotFalse($canonical_export_path, 'export.php must exist');
        $canonical_autoload_path = realpath($autoload_path);
        $this->assertNotFalse($canonical_autoload_path, 'autoload.php must exist');
        $lib_path = realpath(__DIR__ . '/../reprint-exporter-wp/lib.php');
        $this->assertNotFalse($lib_path, 'lib.php must exist');
        $linked_autoload_path_encoded = base64_encode($linked_autoload_path);
        $linked_plugin_directory_encoded = base64_encode($linked_plugin_directory . '/');
        $lib_path_encoded = base64_encode($lib_path);

        try {
            $php_code = '<?php' . "\n"
                . 'require_once base64_decode(\'' . $linked_autoload_path_encoded . '\', true);' . "\n"
                . 'define(\'ABSPATH\', __DIR__ . \'/\');' . "\n"
                . 'define(\'SITE_EXPORT_PLUGIN_DIR\', base64_decode(\'' . $linked_plugin_directory_encoded . '\', true));' . "\n"
                . 'require base64_decode(\'' . $lib_path_encoded . '\', true);' . "\n"
                . 'echo _site_export_load_exporter_runtime();' . "\n";

            $result = $this->runPhpCode($php_code);

            $this->assertSame(0, $result['status'], $result['output']);
            $this->assertSame($canonical_export_path, trim($result['output']));
        } finally {
            $this->removePath($tmp_dir);
        }
    }

    /**
     * @return array {
     *     Export runner result.
     *
     *     @type string $output Captured output.
     * }
     * @phpstan-return array{output:string}
     */
    private function runExportWith(string $setup_script): array
    {
        $export_path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($export_path, 'export.php must exist');

        $php_code = <<<PHP
        <?php
        {$setup_script}
        require '{$export_path}';
        // If we got here, require() completed without die()ing.
        // Print a marker indicating endpoint functions are defined.
        echo function_exists('endpoint_preflight') ? 'endpoint-handlers-loaded' : 'handlers-missing';
        PHP;

        return ['output' => $this->runPhpCode($php_code)['output']];
    }

    /**
     * @return array {
     *     PHP runner result.
     *
     *     @type string $output Captured stdout and stderr.
     *     @type int    $status Process exit status.
     * }
     * @phpstan-return array{output:string,status:int}
     */
    private function runPhpCode(string $php_code): array
    {
        $tmp_dir = sys_get_temp_dir() . '/export-library-test-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
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
            $status = proc_close($process);

            return [
                'output' => ( $stdout ?: '' ) . ( $stderr ?: '' ),
                'status' => $status,
            ];
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }

        rmdir($path);
    }
}
