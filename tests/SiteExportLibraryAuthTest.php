<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteExportLibraryAuthTest extends TestCase
{
    private const LIB_PATH = __DIR__ . '/../reprint-exporter-wp/lib.php';

    public function testLargeBodyStagedEndpointsAuthenticateInTheirHandlers(): void
    {
        $script = <<<PHP
        define('ABSPATH', __DIR__ . '/');
        define('SITE_EXPORT_PLUGIN_DIR', __DIR__ . '/');
        require '{$this->libPath()}';

        echo '__RESULT__' . json_encode([
            'chunk' => _site_export_endpoint_authenticates_in_handler('staged_upload'),
            'batch' => _site_export_endpoint_authenticates_in_handler('staged_upload_batch'),
            'control' => _site_export_endpoint_authenticates_in_handler('staged_status'),
        ]);
        PHP;

        $result = json_decode($this->resultJson($this->runScript($script)), true);

        $this->assertSame([
            'chunk' => true,
            'batch' => true,
            'control' => false,
        ], $result);
    }

    private function resultJson(string $output): string
    {
        $marker = '__RESULT__';
        $position = strrpos($output, $marker);
        $this->assertNotFalse($position, $output);
        return substr($output, $position + strlen($marker));
    }

    private function libPath(): string
    {
        $path = realpath(self::LIB_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function runScript(string $body): string
    {
        $tmp_dir = sys_get_temp_dir() . '/site-export-library-auth-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            $php_path = $tmp_dir . '/run.php';
            file_put_contents($php_path, "<?php\n" . $body . "\n");

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open([PHP_BINARY, $php_path], $descriptors, $pipes, $tmp_dir);
            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (($stdout ?: '') === '') {
                return $stderr ?: '';
            }
            return $stdout;
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }
}
