<?php

declare(strict_types=1);

/**
 * Runs endpoint_file_index() in a subprocess and returns the paths it indexed.
 *
 * A subprocess because the endpoint streams to stdout and installs handlers.
 */
trait FileIndexEndpointRunnerTrait
{
    /**
     * @param string[] $directories
     * @return string[]
     */
    protected function runFileIndex(array $directories, string $listDir): array
    {
        $configPath = $this->tempDir . '/config.json';
        file_put_contents(
            $configPath,
            json_encode([
                'directory' => $directories,
                'list_dir' => $listDir,
                'follow_symlinks' => true,
                'batch_size' => 1000,
            ], JSON_THROW_ON_ERROR),
        );

        $scriptPath = $this->tempDir . '/run-file-index.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_index($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
                var_export(dirname(__DIR__) . '/packages/reprint-server/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_index should exit cleanly.\nstderr: {$stderr}");

        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'Expected gzip-compressed multipart response');

        preg_match_all('/"path":"([^"]+)"/', $decoded, $matches);

        return array_map(
            static fn(string $encodedPath): string => (string) base64_decode($encodedPath, true),
            $matches[1],
        );
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
