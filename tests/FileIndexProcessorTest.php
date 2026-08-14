<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\relative_path_under;

require_once dirname(__DIR__) . '/packages/reprint-server/src/class-file-index-processor.php';

final class FileIndexProcessorTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-processor-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
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

        $processor = FileIndexProcessor::start(
            [realpath($docroot)],
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

        $processor = FileIndexProcessor::start(
            [realpath($docroot)],
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

        $processor = FileIndexProcessor::start(
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

        $processor = FileIndexProcessor::start(
            [ (string) realpath($docroot) ],
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

        $intermediate_entries = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => ( $entry['intermediate'] ?? false ) === true
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

        $processor = FileIndexProcessor::start(
            [realpath($docroot)],
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
            [realpath($docroot)],
            $cursor,
            false,
            true,
            ''
        );
        $this->assertFalse($resumed->next_index_step());
        $resumed->close();
    }

    public function testCursorKeepsTheCanonicalRootsScheduledAtStart(): void
    {
        $docroot = $this->tempDir . '/site';
        $target = $this->tempDir . '/target';
        $link = $docroot . '/link';
        mkdir($docroot, 0755, true);
        mkdir($target, 0755, true);
        symlink($target, $link);
        $canonicalDocroot = (string) realpath($docroot);
        $canonicalTarget = (string) realpath($target);

        $processor = FileIndexProcessor::start(
            [$canonicalDocroot],
            $link,
            true,
            true,
            ''
        );
        $this->assertSame(
            [$canonicalTarget, $canonicalDocroot],
            $processor->get_index_roots()
        );
        $this->assertTrue($processor->next_index_step());
        $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
        $processor->close();

        $resumed = FileIndexProcessor::resume(
            [$canonicalDocroot],
            $cursor,
            true,
            true,
            ''
        );
        $this->assertSame(
            [$canonicalTarget, $canonicalDocroot],
            $resumed->get_index_roots()
        );
        $this->assertSame($canonicalTarget, $resumed->get_index_directory());
        $resumed->close();
    }

    public function testResumeRejectsChangedCanonicalDirectories(): void
    {
        $first = $this->tempDir . '/first';
        $second = $this->tempDir . '/second';
        mkdir($first, 0755, true);
        mkdir($second, 0755, true);
        $first = (string) realpath($first);
        $second = (string) realpath($second);

        $processor = FileIndexProcessor::start(
            [$first],
            $first,
            true,
            true,
            ''
        );
        $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
        $processor->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The file-index selection changed while resuming the traversal'
        );
        FileIndexProcessor::resume(
            [$second],
            $cursor,
            true,
            true,
            ''
        );
    }

    public function testResumeAcceptsTheSameCanonicalDirectoriesInAnotherOrder(): void
    {
        $first = $this->tempDir . '/first';
        $second = $this->tempDir . '/second';
        mkdir($first, 0755, true);
        mkdir($second, 0755, true);
        $first = (string) realpath($first);
        $second = (string) realpath($second);

        $processor = FileIndexProcessor::start(
            [$first, $second],
            $first,
            true,
            true,
            ''
        );
        $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
        $processor->close();

        $resumed = FileIndexProcessor::resume(
            [$second, $first, $second],
            $cursor,
            true,
            true,
            ''
        );
        $this->assertSame([$first, $second], $resumed->get_index_roots());
        $resumed->close();
    }

    public function testResumeRejectsCursorWithoutTraversalRoots(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Index cursor must contain its traversal stack, list directory, and selection fingerprint'
        );
        FileIndexProcessor::resume(
            [ (string) realpath( $docroot ) ],
            json_encode(['stack' => []], JSON_THROW_ON_ERROR),
            false,
            true,
            ''
        );
    }

    public function testResumeRejectsTamperedSelectionFingerprint(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        $canonical_docroot = (string) realpath($docroot);

        $processor = FileIndexProcessor::start(
            [$canonical_docroot],
            $canonical_docroot,
            true,
            false,
            ''
        );
        $cursor = $processor->get_cursor();
        $processor->close();
        $cursor['selection_fingerprint'] = str_repeat('0', 64);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The file-index selection changed while resuming the traversal'
        );
        FileIndexProcessor::resume(
            [$canonical_docroot],
            json_encode($cursor, JSON_THROW_ON_ERROR),
            true,
            false,
            ''
        );
    }

    public function testResumeRejectsChangedTraversalSettings(): void
    {
        $docroot = $this->tempDir . '/site';
        $storage = $this->tempDir . '/storage';
        mkdir($docroot, 0755, true);
        mkdir($storage, 0755, true);
        $canonical_docroot = (string) realpath($docroot);

        $processor = FileIndexProcessor::start(
            [$canonical_docroot],
            $canonical_docroot,
            true,
            true,
            $storage
        );
        $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
        $processor->close();

        foreach (
            [
                [false, true, $storage],
                [true, false, $storage],
                [true, true, ''],
            ] as [$follow_symlinks, $include_caches, $next_storage]
        ) {
            try {
                FileIndexProcessor::resume(
                    [$canonical_docroot],
                    $cursor,
                    $follow_symlinks,
                    $include_caches,
                    $next_storage
                );
                $this->fail('Expected changed traversal settings to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'file-index selection changed',
                    $exception->getMessage()
                );
            }
        }
    }

    public function testStepAfterCloseIsRejected(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot, 0755, true);
        $processor = FileIndexProcessor::start(
            [realpath($docroot)],
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
        $processor = FileIndexProcessor::start(
            [$canonicalDocroot],
            $canonicalDocroot,
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
                    [$canonicalDocroot],
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
