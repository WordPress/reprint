<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\FileIndexProcessor;
use function WordPress\Reprint\Server\relative_path_under;

require_once dirname(__DIR__) . '/packages/reprint-server/src/class-file-index-processor.php';

$GLOBALS['reprint_server_directory_scan_hook_calls'] = 0;
if (!function_exists('_e2e_call_hook')) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Production test-hook stub.
    function _e2e_call_hook(string $name, array &$arguments = []): void
    {
        if ($name === 'test_hook_during_dir_scan' && isset($arguments[1])) {
            ++$GLOBALS['reprint_server_directory_scan_hook_calls'];
        }
    }
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Test stub mirrors the production hook.
final class FileIndexProcessorTest extends TestCase {

    private string $tempDir;

    /** @var string|false */
    private $originalCanonicalTestMode;

    /** @var string|false */
    private $originalLegacyTestMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-processor-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->originalCanonicalTestMode = getenv('REPRINT_SERVER_TEST_MODE');
        $this->originalLegacyTestMode = getenv('SITE_EXPORT_TEST_MODE');
        putenv('REPRINT_SERVER_TEST_MODE');
        putenv('SITE_EXPORT_TEST_MODE');
        $GLOBALS['reprint_server_directory_scan_hook_calls'] = 0;
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        $this->restoreEnvironment('REPRINT_SERVER_TEST_MODE', $this->originalCanonicalTestMode);
        $this->restoreEnvironment('SITE_EXPORT_TEST_MODE', $this->originalLegacyTestMode);
        parent::tearDown();
    }

    public function testCanonicalTestModeRunsDirectoryScanHook(): void
    {
        putenv('REPRINT_SERVER_TEST_MODE=1');

        $this->assertDirectoryScanHookRuns();
    }

    public function testLegacyTestModeStillRunsDirectoryScanHook(): void
    {
        putenv('SITE_EXPORT_TEST_MODE=1');

        $this->assertDirectoryScanHookRuns();
    }

    public function testResumeAfterEveryStepMatchesOneOpenProcessor(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/empty', 0755, true);
        mkdir($docroot . '/nested', 0755, true);
        mkdir($docroot . '/wp-content/cache', 0755, true);
        file_put_contents($docroot . '/a.txt', 'a');
        file_put_contents($docroot . '/nested/b.txt', 'b');
        file_put_contents($docroot . '/wp-content/cache/skipped.txt', 'skip');
        symlink('a.txt', $docroot . '/a-link');

        $uninterrupted = $this->runProcessor($docroot, false);
        $resumed = $this->runProcessor($docroot, true);

        $this->assertSame($uninterrupted, $resumed);
        $this->assertContains('empty', $this->relativePaths($resumed['entries'], $docroot));
        $this->assertContains('a-link', $this->relativePaths($resumed['entries'], $docroot));
        $this->assertContains('nested/b.txt', $this->relativePaths($resumed['entries'], $docroot));
        $this->assertNotContains('nested', $this->relativePaths($resumed['entries'], $docroot));
        $this->assertNotContains('wp-content', $this->relativePaths($resumed['entries'], $docroot));
        $link_stat = lstat($docroot . '/a-link');
        $this->assertIsArray($link_stat);
        foreach ($resumed['entries'] as $index_entry) {
            if ($index_entry['path'] === $docroot . '/a-link') {
                $this->assertSame( (int) $link_stat['size'], $index_entry['size']);
            }
        }
        $this->assertNotContains(
            'wp-content/cache',
            $this->relativePaths($resumed['entries'], $docroot)
        );
        $this->assertContains(FileIndexProcessor::STATUS_SKIPPED, $resumed['statuses']);
        $this->assertContains(FileIndexProcessor::STATUS_DIRECTORY_COMPLETE, $resumed['statuses']);
        $this->assertTrue($resumed['empty_directory_was_empty']);
    }

    public function testPathThatDisappearsAfterDirectoryScanGetsItsOwnStep(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/a.txt', 'a');
        file_put_contents($docroot . '/b.txt', 'b');

        $processor = $this->startProcessor(
            [$docroot],
            $docroot,
            false,
            true,
            ''
        );
        $this->assertTrue($processor->next_index_step());
        $this->assertSame(FileIndexProcessor::STATUS_INDEXED, $processor->get_step_status());

        unlink($docroot . '/b.txt');

        $this->assertTrue($processor->next_index_step());
        $this->assertSame(
            FileIndexProcessor::STATUS_PATH_UNAVAILABLE,
            $processor->get_step_status()
        );
        $this->assertSame([], $processor->get_index_entries());
        $processor->close();
    }

    public function testMissingScheduledDirectoryReportsTheDirectoryAndContinues(): void
    {
        $docroot = $this->tempDir . '/site';
        $vanishingDirectory = $docroot . '/a-directory';
        mkdir($vanishingDirectory, 0755, true);
        file_put_contents($docroot . '/z.txt', 'z');
        $docroot = (string) realpath($docroot);
        $vanishingDirectory = (string) realpath($vanishingDirectory);

        $processor = $this->startProcessor(
            [$docroot],
            $docroot,
            false,
            true,
            ''
        );
        $this->assertTrue($processor->next_index_step());
        $this->assertSame(FileIndexProcessor::STATUS_INDEXED, $processor->get_step_status());
        $this->assertSame($vanishingDirectory, $processor->get_index_entries()[0]['path']);

        rmdir($vanishingDirectory);

        $this->assertTrue($processor->next_index_step());
        $this->assertSame(
            FileIndexProcessor::STATUS_DIRECTORY_ERROR,
            $processor->get_step_status()
        );
        $this->assertSame(
            [
                'error_type' => 'dir_open',
                'path' => $vanishingDirectory,
                'message' => 'Directory does not exist or is not accessible',
            ],
            $processor->get_directory_error()
        );

        $this->assertTrue($processor->next_index_step());
        $this->assertSame(FileIndexProcessor::STATUS_INDEXED, $processor->get_step_status());
        $this->assertSame($docroot . '/z.txt', $processor->get_index_entries()[0]['path']);
        $processor->close();
    }

    public function testFilesystemRootCanAuthorizeAnIndexedDirectory(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/index.php', '<?php');

        $processor = $this->startProcessor(
            ['/'],
            $docroot,
            false,
            true,
            ''
        );

        $this->assertTrue($processor->next_index_step());
        $this->assertSame(FileIndexProcessor::STATUS_INDEXED, $processor->get_step_status());
        $processor->close();
    }

    public function testFollowedRelativeSymlinkIndexesAnIntermediateLink(): void
    {
        $docroot = $this->tempDir . '/site';
        $real_target = $this->tempDir . '/real/target';
        $alias = $this->tempDir . '/alias';
        mkdir($docroot, 0755, true);
        mkdir($real_target, 0755, true);
        symlink($this->tempDir . '/real', $alias);
        symlink('../alias/./target', $docroot . '/link');

        $processor = $this->startProcessor(
            [$docroot],
            $docroot,
            true,
            true,
            ''
        );
        $entries = [];
        while ($processor->next_index_step()) {
            foreach ($processor->get_index_entries() as $entry) {
                $entries[] = $entry;
            }
        }
        $processor->close();

        $physicalAlias = (string) realpath(dirname($alias)) . '/alias';
        $intermediate_entries = array_values(array_filter(
            $entries,
            static fn(array $entry): bool =>
                ( $entry['intermediate'] ?? false ) === true
                && $entry['path'] === $physicalAlias
        ));
        $this->assertCount(1, $intermediate_entries);
        $this->assertSame('link', $intermediate_entries[0]['type']);
        $link_entries = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => $entry['path'] === (string) realpath($docroot) . '/link'
        ));
        $this->assertCount(1, $link_entries);
        $this->assertSame(
            (string) realpath($real_target),
            $link_entries[0]['target']
        );
    }

    public function testResumeWithACompletedCursorRemainsComplete(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);

        $processor = $this->startProcessor(
            [$docroot],
            $docroot,
            false,
            true,
            ''
        );
        $this->assertTrue($processor->next_index_step());
        $this->assertSame(
            FileIndexProcessor::STATUS_DIRECTORY_COMPLETE,
            $processor->get_step_status()
        );
        $this->assertFalse($processor->next_index_step());
        $this->assertFalse($processor->next_index_step());
        $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
        $processor->close();
        $processor->close();

        $resumed = FileIndexProcessor::resume(
            [$this->root($docroot)],
            $cursor,
            false,
            true,
            ''
        );
        $this->assertFalse($resumed->next_index_step());
        $resumed->close();
    }

    public function testStepAfterCloseIsRejected(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        $processor = $this->startProcessor(
            [$docroot],
            $docroot,
            false,
            true,
            ''
        );
        $processor->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot take a file-index step after close().');
        $processor->next_index_step();
    }

    public function testSingleFileRootIsIndexedWithoutTraversal(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/wp-config.php', '<?php // config');
        file_put_contents($docroot . '/other.php', '<?php // other');
        $configPath = (string) realpath($docroot . '/wp-config.php');

        $result = $this->collectEntries([$configPath], $configPath);

        $this->assertCount(1, $result['entries']);
        $this->assertSame($configPath, $result['entries'][0]['path']);
        $this->assertSame('file', $result['entries'][0]['type']);
        $this->assertSame(filesize($configPath), $result['entries'][0]['size']);
    }

    public function testFileRootAndDirectoryRootAreBothIndexed(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/wp-content/plugins/hello', 0755, true);
        file_put_contents($docroot . '/wp-config.php', '<?php // config');
        file_put_contents($docroot . '/wp-content/plugins/hello/hello.php', '<?php // hello');
        $configPath = (string) realpath($docroot . '/wp-config.php');
        $pluginsPath = (string) realpath($docroot . '/wp-content/plugins');

        $result = $this->collectEntries([$configPath, $pluginsPath], $configPath);

        $paths = array_column($result['entries'], 'path');
        $this->assertContains($configPath, $paths);
        $this->assertContains($pluginsPath . '/hello/hello.php', $paths);
    }

    public function testFileSymlinkRootIsIndexedAsTheLinkItself(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/target.php', '<?php // target');
        symlink('target.php', $docroot . '/link.php');
        $linkPath = (string) realpath($docroot) . '/link.php';

        $result = $this->collectEntries([$linkPath], $linkPath);

        $this->assertCount(1, $result['entries']);
        $this->assertSame($linkPath, $result['entries'][0]['path']);
        $this->assertSame('link', $result['entries'][0]['type']);
        // Only a link ending at a directory carries a target; fetch supplies this one.
        $this->assertArrayNotHasKey('target', $result['entries'][0]);
    }

    public function testRootRecordRejectsAnUnresolvedSymlink(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        symlink('absent.php', $docroot . '/broken.php');
        $brokenPath = (string) realpath($docroot) . '/broken.php';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("File-index root missing resolved_path: {$brokenPath}");

        FileIndexProcessor::start([
            [
                'requested_path' => $brokenPath,
                'resolved_path' => null,
                'type' => 'symlink',
            ],
        ], [
            'requested_path' => $brokenPath,
            'resolved_path' => null,
            'type' => 'symlink',
        ], false, true, '');
    }

    public function testStartRootMustBeConfiguredOrADirectory(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        $unconfiguredPath = $docroot . '/unconfigured.php';
        file_put_contents($unconfiguredPath, '<?php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File-index start root must be a configured root or a directory');

        FileIndexProcessor::start(
            [$this->root($docroot)],
            $this->root($unconfiguredPath),
            false,
            false,
            ''
        );
    }

    public function testFileRootInsideASkippedDirectoryIsOmitted(): void
    {
        // A directory root here indexes nothing, so a file root must not either.
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/wp-content/cache', 0755, true);
        file_put_contents($docroot . '/wp-content/cache/keep.php', '<?php // keep');
        $cachedPath = (string) realpath($docroot . '/wp-content/cache/keep.php');

        $result = $this->collectEntries([$cachedPath], $cachedPath, false);

        $this->assertSame([], $result['entries']);
        $this->assertContains(FileIndexProcessor::STATUS_SKIPPED, $result['statuses']);
    }

    public function testFileRootInsideASkippedDirectoryIsIndexedWhenCachesAreIncluded(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/wp-content/cache', 0755, true);
        file_put_contents($docroot . '/wp-content/cache/keep.php', '<?php // keep');
        $cachedPath = (string) realpath($docroot . '/wp-content/cache/keep.php');

        $result = $this->collectEntries([$cachedPath], $cachedPath, true);

        $this->assertCount(1, $result['entries']);
        $this->assertSame($cachedPath, $result['entries'][0]['path']);
    }

    public function testFileRootInsideTheStoragePathIsNeverIndexed(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/.reprint', 0755, true);
        file_put_contents($docroot . '/.reprint/sender.json', '{"token":"secret"}');
        $storagePath = (string) realpath($docroot . '/.reprint');
        $senderPath = $storagePath . '/sender.json';

        $processor = $this->startProcessor([$senderPath], $senderPath, false, true, $storagePath);
        $entries = [];
        while ($processor->next_index_step()) {
            foreach ($processor->get_index_entries() as $entry) {
                $entries[] = $entry;
            }
        }
        $processor->close();

        $this->assertSame([], $entries, 'Reprint storage must never be indexed, even when named');
    }

    public function testEachNamedPathIsOneStepAndSurvivesAResume(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        $roots = [];
        for ($index = 0; $index < 5; $index++) {
            $path = $docroot . '/file' . $index . '.php';
            file_put_contents($path, '<?php // ' . $index);
            $roots[] = (string) realpath($path);
        }

        $uninterrupted = $this->collectEntries($roots, $roots[0]);
        $resumed = $this->collectEntries($roots, $roots[0], true, true);

        $this->assertSame($roots, array_column($uninterrupted['entries'], 'path'));
        $this->assertSame($roots, array_column($resumed['entries'], 'path'));
        $this->assertSame(
            5,
            count(array_filter(
                $uninterrupted['statuses'],
                static fn(?string $status): bool => $status === FileIndexProcessor::STATUS_INDEXED
            )),
            'Each named path must be its own step, not one step returning all of them'
        );
    }

    public function testResumingAfterTheFirstStepDoesNotRepeatFileRoots(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/nested', 0755, true);
        file_put_contents($docroot . '/wp-config.php', '<?php // config');
        file_put_contents($docroot . '/nested/b.txt', 'b');
        $configPath = (string) realpath($docroot . '/wp-config.php');
        $nestedPath = (string) realpath($docroot . '/nested');
        $roots = [$configPath, $nestedPath];

        $uninterrupted = $this->collectEntries($roots, $configPath);
        $resumed = $this->collectEntries($roots, $configPath, true, true);

        $this->assertSame(
            array_column($uninterrupted['entries'], 'path'),
            array_column($resumed['entries'], 'path')
        );
        $this->assertSame(
            1,
            count(array_keys(array_column($resumed['entries'], 'path'), $configPath)),
            'The named file must be indexed exactly once across a resume'
        );
    }

    /**
     * Runs a processor over explicit roots and collects every entry.
     *
     * @param string[] $roots                Configured roots, canonical.
     * @param string   $indexDirectory       Root where traversal begins.
     * @param bool     $includeCaches        Whether generated caches are included.
     * @param bool     $resumeAfterEveryStep Whether to reopen from the cursor each step.
     * @return array {
     *     @type array[]  $entries  File-index entries.
     *     @type string[] $statuses Status returned by every step.
     * }
     */
    private function collectEntries(
        array $roots,
        string $indexDirectory,
        bool $includeCaches = true,
        bool $resumeAfterEveryStep = false
    ): array {
        $root_records = array_map([$this, 'root'], $roots);
        $processor = FileIndexProcessor::start(
            $root_records,
            $this->root($indexDirectory),
            false,
            $includeCaches,
            ''
        );
        $entries = [];
        $statuses = [];
        while ($processor->next_index_step()) {
            $statuses[] = $processor->get_step_status();
            foreach ($processor->get_index_entries() as $entry) {
                $entries[] = $entry;
            }
            if ($resumeAfterEveryStep) {
                $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
                $processor->close();
                $processor = FileIndexProcessor::resume(
                    $root_records,
                    $cursor,
                    false,
                    $includeCaches,
                    ''
                );
            }
        }
        $processor->close();

        return ['entries' => $entries, 'statuses' => $statuses];
    }

    /**
     * @param string[] $roots File-index root paths.
     */
    private function startProcessor(
        array $roots,
        string $start,
        bool $followSymlinks,
        bool $includeCaches,
        string $storagePath
    ): FileIndexProcessor {
        $rootRecords = array_map([$this, 'root'], $roots);
        return FileIndexProcessor::start(
            $rootRecords,
            $this->root($start),
            $followSymlinks,
            $includeCaches,
            $storagePath
        );
    }

    private function assertDirectoryScanHookRuns(): void
    {
        $docroot = $this->tempDir . '/test-mode-site';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/index.php', '<?php');
        $processor = $this->startProcessor([$docroot], $docroot, false, true, '');

        try {
            $this->assertTrue($processor->next_index_step());
            $this->assertGreaterThan(0, $GLOBALS['reprint_server_directory_scan_hook_calls']);
        } finally {
            $processor->close();
        }
    }

    /**
     * @param string|false $value Original environment value.
     */
    private function restoreEnvironment(string $name, $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }
        putenv($name . '=' . $value);
    }

    /**
     * @return array{requested_path:string,resolved_path:string,type:'directory'|'file'|'symlink'}
     */
    private function root(string $path): array
    {
        $stat = lstat($path);
        if ($stat === false) {
            throw new RuntimeException("Test root does not exist: {$path}");
        }
        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            throw new RuntimeException("Test root does not resolve: {$path}");
        }
        $mode = $stat['mode'] & FileIndexProcessor::STAT_TYPE_MASK;
        $type = $mode === FileIndexProcessor::STAT_TYPE_LINK
            ? 'symlink'
            : ( is_dir($path) ? 'directory' : 'file' );
        return [
            'requested_path' => \WordPress\Reprint\Server\normalize_path($path),
            'resolved_path' => $resolvedPath,
            'type' => $type,
        ];
    }

    /**
     * @return array {
     *     Completed traversal.
     *
     *     @type array[]  $entries                    File-index entries.
     *     @type string[] $statuses                   Status returned by every step.
     *     @type bool     $empty_directory_was_empty Whether the empty directory was classified correctly.
     * }
     */
    private function runProcessor(string $docroot, bool $resumeAfterEveryStep): array
    {
        $canonicalDocroot = realpath($docroot);
        $root = $this->root((string) $canonicalDocroot);
        $processor = FileIndexProcessor::start(
            [$root],
            $root,
            false,
            false,
            ''
        );
        $entries = [];
        $statuses = [];
        while ($processor->next_index_step()) {
            $statuses[] = $processor->get_step_status();
            foreach ($processor->get_index_entries() as $entry) {
                $entries[] = $entry;
            }

            if ($resumeAfterEveryStep) {
                $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
                $processor->close();
                $processor = FileIndexProcessor::resume(
                    [$root],
                    $cursor,
                    false,
                    false,
                    ''
                );
            }
        }
        $processor->close();

        $emptyDirectoryWasEmpty = false;
        foreach ($entries as $entry) {
            if ($entry['path'] === $canonicalDocroot . '/empty') {
                $emptyDirectoryWasEmpty = ( $entry['empty'] ?? null ) === true;
            }
        }

        return [
            'entries' => $entries,
            'statuses' => $statuses,
            'empty_directory_was_empty' => $emptyDirectoryWasEmpty,
        ];
    }

    /**
     * @param array[] $entries File-index entries.
     * @return string[] Document-root-relative paths.
     */
    private function relativePaths(array $entries, string $docroot): array
    {
        $root = (string) realpath($docroot);
        $paths = [];
        foreach ($entries as $entry) {
            $relativePath = relative_path_under($entry['path'], $root);
            if ($relativePath !== null && $relativePath !== '') {
                $paths[] = $relativePath;
            }
        }
        return $paths;
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
