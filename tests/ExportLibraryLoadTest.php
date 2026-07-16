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
final class ExportLibraryLoadTest extends TestCase
{
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
            proc_close($process);

            return ['output' => ($stdout ?: '') . ($stderr ?: '')];
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }
}
