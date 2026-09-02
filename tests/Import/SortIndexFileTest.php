<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\try_exec_sort_index_file;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/sort-index-file.php';
require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class SortIndexFileTest extends TestCase
{
    private string $temporary_directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir() . '/sort-index-file-test-' . uniqid();
        mkdir($this->temporary_directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary_directory . '/*') ?: [] as $path) {
            $this->remove_path($path);
        }
        rmdir($this->temporary_directory);
        parent::tearDown();
    }

    public function testSystemSortLimitsBufferAndParallelismWhenAvailable(): void
    {
        if (!function_exists('exec') || !is_executable('/usr/bin/sort')) {
            $this->markTestSkipped('The system sort command is unavailable.');
        }

        $system_sort_path = $this->write_unsorted_index('system-sort.jsonl', false);
        $external_sort_path = $this->write_unsorted_index('external-sort.jsonl', false);
        $parse_index_path = fn(string $line): ?string => $this->index_path_from_line($line);
        $sort_directory = $this->temporary_directory . '/sort-bin';
        $attempt_file = $this->temporary_directory . '/system-sort-attempted';
        mkdir($sort_directory);
        file_put_contents(
            $sort_directory . '/sort',
            "#!/bin/sh\nprintf '%s\\n' \"$@\" > " . escapeshellarg($attempt_file)
                . "\nexec /usr/bin/sort \"$@\"\n"
        );
        chmod($sort_directory . '/sort', 0755);

        $original_path = getenv('PATH');
        putenv('PATH=' . $sort_directory . ':' . $original_path);
        try {
            $this->assertTrue(
                try_exec_sort_index_file(
                    $system_sort_path,
                    $system_sort_path . '.sorted',
                    $parse_index_path
                )
            );
        } finally {
            putenv('PATH=' . $original_path);
        }

        $sorter = new \ExternalMergeSort(
            $parse_index_path,
            1024,
            true,
            $this->temporary_directory,
        );
        $sorter->sort($external_sort_path);

        $arguments = file($attempt_file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($arguments);
        $buffer_size_option = array_search('-S', $arguments, true);
        $this->assertIsInt($buffer_size_option);
        $this->assertSame('32M', $arguments[$buffer_size_option + 1]);
        $this->assertContains('--parallel=1', $arguments);
        $temporary_directory_option = array_search('-T', $arguments, true);
        $this->assertIsInt($temporary_directory_option);
        $this->assertSame(
            $this->temporary_directory,
            $arguments[$temporary_directory_option + 1]
        );
        $this->assertSame(
            file_get_contents($external_sort_path),
            file_get_contents($system_sort_path)
        );
    }

    public function testSystemSortCompletesInsideTheCiMemoryLimit(): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true' || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('The constrained-memory sort runs once in the PHPUnit CI matrix.');
        }

        $docker_path_output = [];
        $docker_path_exit_code = 0;
        exec('command -v docker', $docker_path_output, $docker_path_exit_code);
        $this->assertSame(0, $docker_path_exit_code, 'The PHPUnit workflow must provide Docker.');
        $docker_path = $docker_path_output[0] ?? '';
        $this->assertNotSame('', $docker_path, 'The PHPUnit workflow must provide Docker.');

        $docker_image_output = [];
        $docker_image_exit_code = 0;
        exec(
            escapeshellarg($docker_path) . ' image inspect mariadb:10.11 2>/dev/null',
            $docker_image_output,
            $docker_image_exit_code
        );
        $this->assertSame(
            0,
            $docker_image_exit_code,
            'The PHPUnit workflow must provide its mariadb:10.11 service image.'
        );

        $path = $this->temporary_directory . '/constrained-memory-index.jsonl';
        $input = fopen($path, 'w');
        $this->assertIsResource($input);
        $path_prefix = '/wp-content/uploads/';
        $path_suffix = '-' . str_repeat('x', 180) . '.jpg';
        for ($index = 100000; $index >= 1; --$index) {
            fwrite(
                $input,
                $this->index_line(
                    $path_prefix . sprintf('%08d', $index) . $path_suffix,
                    $index
                ) . "\n"
            );
        }
        fclose($input);
        $input_size = filesize($path);
        $this->assertIsInt($input_size);

        // The PHPUnit workflow already pulls this image for its MariaDB
        // service. Run only GNU sort in a 64 MiB cgroup so the limit includes
        // the complete child process, as it does on a constrained host.
        $sort_directory = $this->temporary_directory . '/memory-limited-sort-bin';
        mkdir($sort_directory);
        file_put_contents(
            $sort_directory . '/sort',
            "#!/bin/sh\nexec " . escapeshellarg($docker_path)
                . " run --rm --network=none"
                . " --memory=64m --memory-swap=64m"
                . ' -v ' . escapeshellarg(
                    $this->temporary_directory . ':' . $this->temporary_directory
                )
                . " --entrypoint /usr/bin/sort mariadb:10.11 \"$@\"\n"
        );
        chmod($sort_directory . '/sort', 0755);

        $original_path = getenv('PATH');
        putenv('PATH=' . $sort_directory . ':' . $original_path);
        try {
            $this->assertTrue(
                try_exec_sort_index_file(
                    $path,
                    $path . '.sorted',
                    fn(string $line): ?string => $this->index_path_from_line($line)
                ),
                'GNU sort must complete while the child process is limited to 64 MiB.'
            );
        } finally {
            putenv('PATH=' . $original_path);
        }

        $sorted_input = fopen($path, 'r');
        $this->assertIsResource($sorted_input);
        $first_line = fgets($sorted_input);
        fclose($sorted_input);

        $this->assertSame($input_size, filesize($path));
        $this->assertIsString($first_line);
        $this->assertSame(
            $path_prefix . '00000001' . $path_suffix,
            $this->index_path_from_line($first_line)
        );
    }

    public function testFallsBackToExternalSortWhenSystemSortFails(): void
    {
        $path = $this->write_unsorted_index();
        $sort_directory = $this->temporary_directory . '/sort-bin';
        $attempt_file = $this->temporary_directory . '/system-sort-attempted';
        mkdir($sort_directory);
        file_put_contents(
            $sort_directory . '/sort',
            "#!/bin/sh\nprintf attempted > " . escapeshellarg($attempt_file) . "\nexit 1\n"
        );
        chmod($sort_directory . '/sort', 0755);

        $original_path = getenv('PATH');
        putenv('PATH=' . $sort_directory . ':' . $original_path);
        try {
            $this->assertTrue(sort_index_file($path));
        } finally {
            putenv('PATH=' . $original_path);
        }

        $this->assertFileExists($attempt_file);
        $this->assertSame(['/apple', '/banana', '/zebra'], $this->index_paths($path));
    }

    public function testUsesExternalSortWhenExecIsDisabled(): void
    {
        $path = $this->write_unsorted_index();
        $script = $this->temporary_directory . '/sort-without-exec.php';
        file_put_contents($script, '<?php' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-server/src/utils.php', true) . ';' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-client/src/lib/sort-index-file.php', true) . ';' . "\n"
            . '\\Reprint\\Importer\\sort_index_file($argv[1]);' . "\n");

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=exec', $script, $path],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stdout . $stderr);
        $this->assertSame(['/apple', '/banana', '/zebra'], $this->index_paths($path));
    }

    public function testReturnsFalseForAMissingIndexFile(): void
    {
        $this->assertFalse(sort_index_file($this->temporary_directory . '/missing.jsonl'));
    }

    public function testSortsLocalIndexRelativePaths(): void
    {
        $path = $this->temporary_directory . '/local-index.jsonl';
        file_put_contents($path, implode("\n", [
            $this->index_line('zebra', 3),
            $this->index_line('apple', 2),
            $this->index_line('banana', 4),
        ]));

        $this->assertTrue(sort_index_file($path));
        $this->assertSame(['apple', 'banana', 'zebra'], $this->index_paths($path));
    }

    public function testImporterRejectsAMissingNextRemoteIndex(): void
    {
        $client = new \ImportClient(
            'https://example.test/?reprint-api',
            $this->temporary_directory . '/state',
            $this->temporary_directory . '/files'
        );
        $sort_next_remote_index_file = (new \ReflectionClass($client))
            ->getMethod('sort_next_remote_index_file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot sort the next remote index because it does not exist');
        $sort_next_remote_index_file->invoke($client);
    }

    public function testRejectsMalformedIndexLines(): void
    {
        $path = $this->temporary_directory . '/index.jsonl';
        file_put_contents($path, "not json\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid index line format');
        sort_index_file($path);
    }

    private function write_unsorted_index(string $filename = 'index.jsonl', bool $with_duplicate = true): string
    {
        $lines = [
            $this->index_line('/zebra', 3),
            $this->index_line('/apple', 2),
            $this->index_line('/banana', 4),
        ];
        if ($with_duplicate) {
            $lines[] = $this->index_line('/zebra', 1);
        }
        $lines[] = '';

        $path = $this->temporary_directory . '/' . $filename;
        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function index_path_from_line(string $line): ?string
    {
        $entry = json_decode($line, true);
        if (!is_array($entry) || !isset($entry['path'])) {
            return null;
        }
        return base64_decode($entry['path'], true) ?: null;
    }

    /** @return string[] */
    private function index_paths(string $path): array
    {
        $paths = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true);
            $paths[] = base64_decode($entry['path'], true);
        }
        return $paths;
    }

    private function remove_path(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }

        foreach (glob($path . '/*') ?: [] as $child) {
            $this->remove_path($child);
        }
        rmdir($path);
    }

    private function index_line(string $path, int $ctime): string
    {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => 0,
            'type' => 'file',
        ]);
    }
}
