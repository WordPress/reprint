<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the following line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/class-external-sort-processor.php';

final class ExternalSortProcessorTest extends TestCase
{
    private string $tempDir;
    private string $sourceFile;
    private string $outputFile;
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/external-sort-' . uniqid();
        $this->sourceFile = $this->tempDir . '/source';
        $this->outputFile = $this->tempDir . '/output';
        $this->workDirectory = $this->tempDir . '/work';
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testSortsMultipleRunsWithTwoHandlesAndBoundedWorkFiles(): void
    {
        $lines = [];
        for ($index = 8200; $index >= 0; --$index) {
            $lines[] = sprintf('%05d value', $index % 8199) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $lines));
        $processor = $this->startProcessor();
        $phases = [];
        $maximumOpenHandles = 0;
        $maximumWorkFiles = 0;
        while ($processor->next_step()) {
            $cursor = $processor->get_cursor();
            if (!isset($phases[$cursor['phase']])) {
                $phases[$cursor['phase']] = true;
                $maximumOpenHandles = max(
                    $maximumOpenHandles,
                    $this->openHandleCount($processor)
                );
                $maximumWorkFiles = max(
                    $maximumWorkFiles,
                    count(glob($this->workDirectory . '/sort.slot-*'))
                );
            }
        }

        $this->assertSame(2, $maximumOpenHandles);
        $this->assertLessThanOrEqual(2, $maximumWorkFiles);
        $this->assertArrayHasKey('merge_pass_complete', $phases);
        $this->assertSame([], glob($this->workDirectory . '/sort.slot-*'));
        $outputLines = file($this->outputFile, FILE_IGNORE_NEW_LINES);
        $this->assertCount(8199, $outputLines);
        $this->assertSame('00000 value', $outputLines[0]);
        $this->assertSame('08198 value', $outputLines[8198]);
        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();
        $this->assertFalse($processor->next_step());
    }

    public function testReplaysEveryDiscardedStepWithPreexistingOutput(): void
    {
        $lines = [];
        for ($index = 59; $index >= 0; --$index) {
            $lines[] = sprintf('%03d', $index) . str_repeat('x', 19996) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $lines));
        file_put_contents($this->outputFile, "old\n");
        $keyExtractor = static fn(string $line): string =>
            substr($line, 0, 3) . str_repeat('k', 65530);
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'replay',
            $keyExtractor
        );
        $phases = [];
        while (true) {
            $savedCursor = $this->jsonRoundTrip($processor->get_cursor());
            $phase = $savedCursor['phase'];
            if (!isset($phases['publishing_output'])) {
                $this->assertSame("old\n", file_get_contents($this->outputFile));
            }
            $phases[$phase] = true;

            $processor->next_step();
            $processor->close();
            $processor = \ExternalSortProcessor::resume(
                $this->sourceFile,
                $this->outputFile,
                $this->workDirectory,
                'replay',
                $keyExtractor,
                true,
                $savedCursor
            );
            if (!$processor->next_step()) {
                break;
            }
        }
        $phases[$processor->get_cursor()['phase']] = true;
        $processor->close();

        foreach ([
            'building_runs',
            'starting_merge',
            'merging_runs',
            'merge_pass_complete',
            'starting_output',
            'copying_output',
            'publishing_output',
            'cleaning_work_files',
            'complete',
        ] as $phase) {
            $this->assertArrayHasKey($phase, $phases);
        }
        $this->assertSame(implode('', array_reverse($lines)), file_get_contents($this->outputFile));
        $this->assertSame([], glob($this->workDirectory . '/replay.slot-*'));
        $this->assertFileDoesNotExist($this->outputFile . '.external-sort.swap');
    }

    public function testInitialStepCountsBlankAndSkippedRows(): void
    {
        file_put_contents($this->sourceFile, str_repeat("\n", 5000));
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required extractor signature.
            static fn(string $_line): ?string => null
        );
        $this->assertTrue($processor->next_step());
        $cursor = $processor->get_cursor();
        $this->assertSame('building_runs', $cursor['phase']);
        $this->assertSame(4096, $cursor['source_byte_offset']);
        $this->assertSame(0, $cursor['run_count']);
        while ($processor->next_step()) {
            continue;
        }
        $processor->close();
        $this->assertSame('', file_get_contents($this->outputFile));
    }

    public function testInitialStepsCountKeyBytesWithoutRereadingBoundaryRows(): void
    {
        file_put_contents($this->sourceFile, str_repeat("x\n", 100));
        $extractorCalls = 0;
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            static function (string $line) use (&$extractorCalls): string {
                ++$extractorCalls;
                return str_repeat($line, 65536);
            }
        );
        $steps = 0;
        do {
            $this->assertTrue($processor->next_step());
            ++$steps;
        } while ($processor->get_cursor()['phase'] === 'building_runs');

        $this->assertGreaterThan(1, $steps);
        $this->assertSame(100, $extractorCalls);
        $processor->close();
    }

    public function testRejectsOversizedPhysicalLineAndExtractedKey(): void
    {
        file_put_contents($this->sourceFile, str_repeat('x', 1048576) . "\n");
        $processor = $this->startProcessor();
        try {
            $processor->next_step();
            $this->fail('Oversized physical line was accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('byte offset 0', $exception->getMessage());
            $this->assertStringContainsString('maximum of 65536 bytes', $exception->getMessage());
        } finally {
            $processor->close();
        }

        file_put_contents($this->sourceFile, "x\n");
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'key-sort',
            static fn(string $line): string => str_repeat($line, 1048577)
        );
        try {
            $processor->next_step();
            $this->fail('Oversized extracted key was accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('1048577 bytes', $exception->getMessage());
            $this->assertStringContainsString('byte offset 0', $exception->getMessage());
        } finally {
            $processor->close();
        }

        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'type-sort',
            // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Exercises runtime validation.
            static fn(string $_line) => 7
        );
        try {
            $processor->next_step();
            $this->fail('Non-string extracted key was accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('integer', $exception->getMessage());
            $this->assertStringContainsString('byte offset 0', $exception->getMessage());
        } finally {
            $processor->close();
        }
    }

    public function testRejectsTamperedNoncanonicalCursorValues(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        $processor = $this->startProcessor();
        while ($processor->get_cursor()['phase'] !== 'merging_runs') {
            if (!$processor->next_step()) {
                break;
            }
        }
        $cursor = $processor->get_cursor();
        $processor->close();
        if ($cursor['phase'] !== 'merging_runs') {
            $cursor = [
                'phase' => 'building_runs',
                'source_file_bytes' => 2,
                'source_byte_offset' => '0',
                'slot_byte_offset' => 0,
                'run_count' => 0,
            ];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->resumeProcessor($cursor);
    }

    public function testRejectsTamperedCursorPastWorkFileInsteadOfExtendingIt(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        $processor = $this->startProcessor();
        $cursor = $processor->get_cursor();
        $processor->close();
        $cursor['slot_byte_offset'] = 1;

        $this->expectException(\UnexpectedValueException::class);
        $this->resumeProcessor($cursor);
    }

    public function testRejectsSourceCollisionWithDeterministicWorkFile(): void
    {
        mkdir($this->workDirectory);
        $this->sourceFile = $this->workDirectory . '/sort.slot-0';
        file_put_contents($this->sourceFile, "a\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->startProcessor();
    }

    public function testResumeRejectsDuplicatePolicyChange(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        $processor = $this->startProcessor();
        $cursor = $processor->get_cursor();
        $processor->close();

        $this->expectException(\UnexpectedValueException::class);
        \ExternalSortProcessor::resume(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            static fn(string $line): string => $line,
            false,
            $cursor
        );
    }

    public function testResumeRejectsDifferentSameSizeSourcePath(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        $processor = $this->startProcessor();
        $cursor = $processor->get_cursor();
        $processor->close();
        $otherSourceFile = $this->tempDir . '/other-source';
        file_put_contents($otherSourceFile, "b\n");

        $this->expectException(\UnexpectedValueException::class);
        \ExternalSortProcessor::resume(
            $otherSourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            static fn(string $line): string => $line,
            true,
            $cursor
        );
    }

    public function testRetainsEqualBinaryKeysWhenDeduplicationIsDisabled(): void
    {
        $lines = [];
        for ($index = 4100; $index >= 0; --$index) {
            $lines[] = sprintf('%04d', $index) . ( $index % 2 === 0 ? "\x00" : "\xFF" ) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $lines));
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'binary',
            // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required extractor signature.
            static fn(string $_line): string => "\x00\xFF",
            false
        );
        while ($processor->next_step()) {
            continue;
        }
        $processor->close();
        usort($lines, 'strcmp');
        $this->assertSame(implode('', $lines), file_get_contents($this->outputFile));
    }

    public function testResumeCarriesNonUtf8PreviousKeyAsCanonicalBase64(): void
    {
        $lines = [];
        for ($index = 4099; $index >= 0; --$index) {
            $lines[] = sprintf('%04d', $index) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $lines));
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required extractor signature.
        $keyExtractor = static fn(string $_line): string => "\xFF\x00";
        $processor = \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'non-utf8',
            $keyExtractor
        );
        do {
            $this->assertTrue($processor->next_step());
            $cursor = $processor->get_cursor();
        } while (( $cursor['previous_key_b64'] ?? null ) === null);
        $this->assertSame(base64_encode("\xFF\x00"), $cursor['previous_key_b64']);
        $cursor = $this->jsonRoundTrip($cursor);
        $processor->close();

        $resumed = \ExternalSortProcessor::resume(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'non-utf8',
            $keyExtractor,
            true,
            $cursor
        );
        while ($resumed->next_step()) {
            continue;
        }
        $resumed->close();
        $this->assertSame("0000\n", file_get_contents($this->outputFile));
    }

    public function testCompleteCursorResumesWithStableTerminalResult(): void
    {
        file_put_contents($this->sourceFile, "b\na\n");
        $processor = $this->startProcessor();
        while ($processor->next_step()) {
            continue;
        }
        $completeCursor = $this->jsonRoundTrip($processor->get_cursor());
        $processor->close();

        $resumed = $this->resumeProcessor($completeCursor);
        $this->assertFalse($resumed->next_step());
        $this->assertFalse($resumed->next_step());
        $resumed->close();
        $resumed->close();
        $this->assertSame("a\nb\n", file_get_contents($this->outputFile));
    }

    public function testStartRejectsOutputSymlinkWithoutTouchingConfiguredPaths(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        $oldTarget = $this->tempDir . '/old-output-target';
        file_put_contents($oldTarget, "old\n");
        if (!@symlink($oldTarget, $this->outputFile)) {
            $this->markTestSkipped('The output-symlink check requires symlink support.');
        }
        try {
            $this->startProcessor();
            $this->fail('An output symlink was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertTrue(is_link($this->outputFile));
        $this->assertSame("old\n", file_get_contents($oldTarget));
        $this->assertSame("a\n", file_get_contents($this->sourceFile));
        $this->assertDirectoryDoesNotExist($this->workDirectory);
    }

    public function testResumeRejectsOutputSymlinkBeforeTruncatingWorkTail(): void
    {
        $lines = [];
        for ($index = 4999; $index >= 0; --$index) {
            $lines[] = sprintf('%05d', $index) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $lines));
        $processor = $this->startProcessor();
        $this->assertTrue($processor->next_step());
        $savedCursor = $this->jsonRoundTrip($processor->get_cursor());
        $this->assertSame('building_runs', $savedCursor['phase']);
        $this->assertTrue($processor->next_step());
        $processor->close();
        $slotFile = $this->workDirectory . '/sort.slot-0';
        $workBytesWithUnconfirmedTail = file_get_contents($slotFile);
        $this->assertIsString($workBytesWithUnconfirmedTail);

        $oldTarget = $this->tempDir . '/old-output-target';
        file_put_contents($oldTarget, "old\n");
        if (!@symlink($oldTarget, $this->outputFile)) {
            $this->markTestSkipped('The output-symlink check requires symlink support.');
        }
        try {
            $this->resumeProcessor($savedCursor);
            $this->fail('An output symlink was accepted on resume.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertTrue(is_link($this->outputFile));
        $this->assertSame("old\n", file_get_contents($oldTarget));
        $this->assertSame($workBytesWithUnconfirmedTail, file_get_contents($slotFile));
    }

    public function testRejectsUnterminatedFinalLine(): void
    {
        file_put_contents($this->sourceFile, 'a');
        $processor = $this->startProcessor();
        try {
            $processor->next_step();
            $this->fail('Unterminated final line was accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('unterminated', $exception->getMessage());
        } finally {
            $processor->close();
        }
    }

    public function testReportsRealWriteLimitFailure(): void
    {
        if (
            !function_exists('pcntl_signal')
            || !function_exists('proc_open')
            || !defined('SIGXFSZ')
            || !is_executable('/bin/sh')
        ) {
            $this->markTestSkipped('The real file-size-limit check requires a POSIX shell, pcntl, and SIGXFSZ.');
        }
        $rows = [];
        for ($index = 0; $index < 100; ++$index) {
            $rows[] = sprintf('%03d', $index) . str_repeat('x', 97) . "\n";
        }
        file_put_contents($this->sourceFile, implode('', $rows));
        $script = $this->tempDir . '/write-limit.php';
        // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates an isolated fault script.
        file_put_contents($script, '<?php
pcntl_async_signals(true);
pcntl_signal(SIGXFSZ, SIG_IGN);
require ' . var_export(realpath(__DIR__ . '/../../packages/reprint-client/src/lib/class-external-sort-processor.php'), true) . ';
try {
    $processor = ExternalSortProcessor::start(' . var_export($this->sourceFile, true) . ', ' . var_export($this->outputFile, true) . ', ' . var_export($this->workDirectory, true) . ', "limited", static fn(string $line): string => $line);
    while ($processor->next_step()) {}
} catch (RuntimeException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}
exit(2);
');
        // phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $command = '/bin/sh -c ' . escapeshellarg(
            'ulimit -f 2; exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        );
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr . $stdout);
        $this->assertStringContainsString('Failed to write external sort work file', $stdout);
    }

    public function testReportsRealPublishRenameFailure(): void
    {
        file_put_contents($this->sourceFile, "a\n");
        mkdir($this->outputFile);
        $processor = $this->startProcessor();
        try {
            while ($processor->next_step()) {
                continue;
            }
            $this->fail('Publish rename into a directory was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to publish external sort output', $exception->getMessage());
        } finally {
            $processor->close();
        }
    }

    private function startProcessor(): \ExternalSortProcessor
    {
        return \ExternalSortProcessor::start(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            static fn(string $line): string => substr($line, 0, 5)
        );
    }

    private function resumeProcessor(array $cursor): \ExternalSortProcessor
    {
        return \ExternalSortProcessor::resume(
            $this->sourceFile,
            $this->outputFile,
            $this->workDirectory,
            'sort',
            static fn(string $line): string => substr($line, 0, 5),
            true,
            $cursor
        );
    }

    private function jsonRoundTrip(array $cursor): array
    {
        $json = json_encode($cursor);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function openHandleCount(\ExternalSortProcessor $processor): int
    {
        $reflection = new \ReflectionClass($processor);
        $count = 0;
        foreach (['input_handle', 'output_handle'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            if (is_resource($property->getValue($processor))) {
                ++$count;
            }
        }
        return $count;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
