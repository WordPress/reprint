<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php';

/**
 * Coverage for PushJournal: per-remote-site baselines and the local diff.
 *
 * The diff drives real uploads and deletions later in a push, so the tests
 * pin the classification exactly: new, ctime-changed, size-changed,
 * type-changed, deleted, unchanged — plus the two boundary situations
 * (no baseline yet; stale outputs from an earlier run), the encoding
 * round-trip for paths that need base64 in the first place, and the JSON
 * parsing behavior that keeps the diff independent from field order.
 */
final class PushJournalTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/push-journal-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Site keys
    // ------------------------------------------------------------------

    public function testSiteKeyIdentifiesTheSiteNotTheUrlSpelling(): void
    {
        $canonical = PushJournal::site_key('https://example.com/blog');

        // Spelling variants of the same site map to the same directory.
        $this->assertSame($canonical, PushJournal::site_key('http://example.com/blog'));
        $this->assertSame($canonical, PushJournal::site_key('https://EXAMPLE.com/blog/'));
        $this->assertSame($canonical, PushJournal::site_key('https://example.com/blog?preview=1'));
        $this->assertSame($canonical, PushJournal::site_key('example.com/blog'));

        // Different sites map to different directories.
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.com'));
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.com:8080/blog'));
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.org/blog'));

        // The slug part stays readable.
        $this->assertStringStartsWith('example.com-blog-', $canonical);
    }

    public function testSiteKeyRejectsUrlsWithoutAHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no host');
        PushJournal::site_key('/just/a/path');
    }

    // ------------------------------------------------------------------
    //  Baselines
    // ------------------------------------------------------------------

    public function testCaptureCreatesAndOverwritesTheBaseline(): void
    {
        $journal = $this->makeJournal();
        $this->assertFileDoesNotExist($journal->local_files_baseline_path);

        $journal->capture_local_files_baseline($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertFileExists($journal->local_files_baseline_path);
        $this->assertFileDoesNotExist($journal->local_files_baseline_path . '.tmp');

        // A second capture replaces the first: diffing an index identical
        // to the second capture reports no changes.
        $second = $this->writeIndex(['b.txt' => [200, 9, 'file']]);
        $journal->capture_local_files_baseline($second);
        $this->assertSame(
            ['changed' => 0, 'deleted' => 0],
            $journal->diff_local_files($second)
        );
    }

    public function testCaptureRequiresTheIndexFileToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('index file is missing');
        $this->makeJournal()->capture_local_files_baseline($this->tempDir . '/no-such-index.jsonl');
    }

    public function testCapturedBaselineIsUsableOnlyForItsManagedDirectoryAndLocalRoot(): void
    {
        $stateDir = $this->tempDir . '/state';
        $localRoot = $this->tempDir . '/local-site';
        $target = 'https://example.com/export?directory=%2Fsrv%2Fsite';
        $index = $this->writeIndex(['index.php' => [100, 5, 'file']]);
        $journal = new PushJournal($stateDir, $target, $localRoot);

        $journal->capture_local_files_baseline($index);

        $this->assertTrue($journal->has_compatible_local_files_baseline());
        $this->assertSame(
            ['changed' => 0, 'deleted' => 0],
            $journal->diff_local_files($index)
        );
        $otherManagedDirectory = new PushJournal(
            $stateDir,
            'https://example.com/export?directory=%2Fsrv%2Fother',
            $localRoot
        );
        $otherLocalRoot = new PushJournal(
            $stateDir,
            $target,
            $this->tempDir . '/other-local-site'
        );
        $this->assertFalse($otherManagedDirectory->has_compatible_local_files_baseline());
        $this->assertFalse($otherLocalRoot->has_compatible_local_files_baseline());
    }

    public function testTargetAndStateDirectoryKeepBaselinesIndependent(): void
    {
        $index = $this->writeIndex(['index.php' => [100, 5, 'file']]);
        $localRoot = $this->tempDir . '/local-site';
        $target = 'https://example.com/export?directory=%2Fsrv%2Fsite';
        $journal = new PushJournal($this->tempDir . '/state-a', $target, $localRoot);
        $journal->capture_local_files_baseline($index);

        $otherTarget = new PushJournal(
            $this->tempDir . '/state-a',
            'https://other.example/export?directory=%2Fsrv%2Fsite',
            $localRoot
        );
        $otherStateDirectory = new PushJournal($this->tempDir . '/state-b', $target, $localRoot);
        $this->assertFalse($otherTarget->has_compatible_local_files_baseline());
        $this->assertFalse($otherStateDirectory->has_compatible_local_files_baseline());
    }

    public function testIncompatibleIdentityUsesCreateOnlyDiff(): void
    {
        $stateDir = $this->tempDir . '/state';
        $localRoot = $this->tempDir . '/local-site';
        $index = $this->writeIndex(['index.php' => [100, 5, 'file']]);
        $baselineJournal = new PushJournal(
            $stateDir,
            'https://example.com/export?directory=%2Fsrv%2Fsite',
            $localRoot
        );
        $baselineJournal->capture_local_files_baseline($index);

        $journal = new PushJournal(
            $stateDir,
            'https://example.com/export?directory=%2Fsrv%2Fother',
            $localRoot
        );

        $this->assertSame(['changed' => 1, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame(['index.php'], $this->listPaths($journal->local_paths_to_push));
    }

    public function testBaselineWithoutItsIdentityIsNeverTrusted(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex(['index.php' => [100, 5, 'file']]);
        mkdir(dirname($journal->local_files_baseline_path), 0755, true);
        copy($index, $journal->local_files_baseline_path);

        $this->assertFalse($journal->has_compatible_local_files_baseline());
        $this->assertSame(['changed' => 1, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame(['index.php'], $this->listPaths($journal->local_paths_to_push));
    }

    public function testInvalidationRemovesBothBaselineAndIdentity(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'index.php' => [100, 5, 'file'],
        ]));
        $this->assertTrue($journal->has_compatible_local_files_baseline());

        PushJournal::invalidate_local_files_baseline(
            $this->tempDir . '/state',
            'https://example.com/'
        );

        $this->assertFalse($journal->has_compatible_local_files_baseline());
        $this->assertFileDoesNotExist($journal->local_files_baseline_path);
        $this->assertFileDoesNotExist($journal->local_files_identity_path);
    }

    public function testFailedCaptureAfterInvalidationCannotRestoreStaleTrust(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'index.php' => [100, 5, 'file'],
        ]));
        PushJournal::invalidate_local_files_baseline(
            $this->tempDir . '/state',
            'https://example.com/?directory=%2Fsrv%2Fsite'
        );

        try {
            $journal->capture_local_files_baseline($this->tempDir . '/missing-snapshot.jsonl');
            $this->fail('Expected the missing snapshot capture to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('index file is missing', $exception->getMessage());
        }

        $this->assertFalse($journal->has_compatible_local_files_baseline());
    }

    public function testManagedDirectoryRequiresOneAbsoluteScalarUrlParameter(): void
    {
        $this->assertSame(
            '/srv/site',
            PushJournal::managed_directory_from_url(
                'https://example.com/export?reprint-api=1&directory=%2Fsrv%2Fsite%2F'
            )
        );
        $this->assertSame(
            '/',
            PushJournal::managed_directory_from_url('https://example.com/export?directory=%2F')
        );
        $this->assertSame(
            '/srv//site',
            PushJournal::managed_directory_from_url('https://example.com/export?directory=%2Fsrv%2F%2Fsite%2F%2F')
        );
    }

    public function testManagedDirectoryRejectsEveryUnrepresentableQueryShape(): void
    {
        $ineligible_urls = [
            'missing' => 'https://example.com/export',
            'empty' => 'https://example.com/export?directory=',
            'relative' => 'https://example.com/export?directory=relative',
            'NUL' => 'https://example.com/export?directory=%2Fsrv%00site',
            'dot segment' => 'https://example.com/export?directory=%2Fsrv%2F.%2Fsite',
            'dot-dot segment' => 'https://example.com/export?directory=%2Fsrv%2F..%2Fsite',
            'repeated scalar' => 'https://example.com/export?directory=%2Fsrv%2Fsite&directory=%2Fsrv%2Fother',
            'literal array' => 'https://example.com/export?directory[]=%2Fsrv%2Fsite',
            'encoded array' => 'https://example.com/export?directory%5B%5D=%2Fsrv%2Fsite',
            'indexed array' => 'https://example.com/export?directory%5B0%5D=%2Fsrv%2Fsite',
            'keyed array' => 'https://example.com/export?directory%5Bsite%5D=%2Fsrv%2Fsite',
            'nested array' => 'https://example.com/export?directory%5Bsite%5D%5Broot%5D=%2Fsrv%2Fsite',
            'unclosed bracket' => 'https://example.com/export?directory%5Bsite=%2Fsrv%2Fsite',
            'unopened bracket' => 'https://example.com/export?directory%5D=%2Fsrv%2Fsite',
            'mixed scalar and array' => 'https://example.com/export?directory=%2Fsrv%2Fsite&directory%5B%5D=%2Fsrv%2Fother',
            'encoded name and array' => 'https://example.com/export?%64irectory%5B%5D=%2Fsrv%2Fsite',
        ];

        foreach ($ineligible_urls as $description => $url) {
            $this->assertNull(
                PushJournal::managed_directory_from_url($url),
                $description
            );
        }
    }

    // ------------------------------------------------------------------
    //  Local diff
    // ------------------------------------------------------------------

    public function testNoCompatibleBaselineTreatsEveryEntryAsChanged(): void
    {
        $journal = $this->makeJournal();
        $counts = $journal->diff_local_files($this->writeIndex([
            'index.php' => [100, 10, 'file'],
            'wp-content' => [100, 0, 'dir'],
            'wp-content/themes/foo/style.css' => [150, 20, 'file'],
        ]));

        $this->assertSame(['changed' => 3, 'deleted' => 0], $counts);
        $this->assertSame(
            ['index.php', 'wp-content', 'wp-content/themes/foo/style.css'],
            $this->listPaths($journal->local_paths_to_push)
        );
        $this->assertSame([], $this->deletePaths($journal->local_delete_stream_path));

        // Push plans retain the normalized entry type. The sender re-stats
        // before reading it, but does not rescan the complete snapshot for
        // every planned path just to rediscover its logical type.
        $firstLine = strtok((string) file_get_contents($journal->local_paths_to_push), "\n");
        $this->assertSame(
            '{"path":"' . base64_encode('index.php') . '","ctime":100,"size":10,"type":"file"}',
            $firstLine
        );
    }

    public function testUnchangedIndexProducesEmptyOutputs(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex([
            'a.txt' => [100, 5, 'file'],
            'b/c.txt' => [200, 7, 'file'],
        ]);
        $journal->capture_local_files_baseline($index);

        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame([], $this->deletePaths($journal->local_delete_stream_path));
    }

    public function testDeleteStreamIsTheExactRawNulDelimitedStream(): void
    {
        $journal = $this->makeJournal();
        $first_path = "line\nbreak-\xff";
        $second_path = 'plain.txt';
        $journal->capture_local_files_baseline($this->writeIndex([
            $first_path => [100, 5, 'file'],
            $second_path => [100, 5, 'file'],
        ]));

        $this->assertSame(
            ['changed' => 0, 'deleted' => 2],
            $journal->diff_local_files($this->writeIndex([]))
        );
        $this->assertSame(
            $first_path . "\0" . $second_path . "\0",
            file_get_contents($journal->local_delete_stream_path)
        );
        $this->assertFileDoesNotExist($journal->local_delete_stream_path . '.tmp');
    }

    public function testCtimeSizeOrTypeChangeEachMarkThePathChanged(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'ctime-bump.txt' => [100, 5, 'file'],
            'size-bump.txt' => [100, 5, 'file'],
            'type-swap' => [100, 5, 'file'],
            'same.txt' => [100, 5, 'file'],
        ]));

        $counts = $journal->diff_local_files($this->writeIndex([
            'ctime-bump.txt' => [101, 5, 'file'],
            'size-bump.txt' => [100, 6, 'file'],
            'type-swap' => [100, 5, 'symlink'],
            'same.txt' => [100, 5, 'file'],
        ]));

        $this->assertSame(['changed' => 3, 'deleted' => 0], $counts);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($journal->local_paths_to_push)
        );
    }

    public function testOldBaselineModesAndModeOnlyChangesAreIgnored(): void
    {
        $journal = $this->makeJournal();
        $baseline = $this->tempDir . '/directory-mode-baseline.jsonl';
        $current = $this->tempDir . '/directory-mode-current.jsonl';
        file_put_contents($baseline, json_encode([
            'path' => base64_encode('existing'),
            'ctime' => 1,
            'size' => 0,
            'type' => 'tree-directory',
            'mode' => 0755,
        ]) . "\n");
        file_put_contents($current, json_encode([
            'path' => base64_encode('existing'),
            'ctime' => 2,
            'size' => 0,
            'type' => 'tree-directory',
            'mode' => 0711,
        ]) . "\n" . json_encode([
            'path' => base64_encode('new-tree'),
            'ctime' => 2,
            'size' => 0,
            'type' => 'tree-directory',
            'mode' => 0750,
        ]) . "\n");
        $journal->capture_local_files_baseline($baseline);

        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($current));
        $this->assertSame([], file($journal->local_paths_to_push, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function testEveryLogicalTypeTransitionEmitsOnlyTheRequiredClearAndInstallWork(): void
    {
        $types = ['file', 'symlink', 'empty', 'structural'];
        foreach ($types as $previousType) {
            foreach ($types as $currentType) {
                $caseRoot = $this->tempDir . '/' . $previousType . '-to-' . $currentType;
                $journal = new PushJournal($caseRoot, 'https://example.com/', $caseRoot . '/local-site');
                $baseline = $this->writeLogicalIndex($caseRoot . '-baseline.jsonl', $previousType, 1);
                $current = $this->writeLogicalIndex($caseRoot . '-current.jsonl', $currentType, 2);
                $journal->capture_local_files_baseline($baseline);

                $journal->diff_local_files($current);

                $clearRequired = (
                    in_array($previousType, ['file', 'symlink'], true)
                    && in_array($currentType, ['empty', 'structural'], true)
                ) || (
                    in_array($previousType, ['empty', 'structural'], true)
                    && in_array($currentType, ['file', 'symlink'], true)
                ) || ($previousType === 'structural' && $currentType === 'empty');
                $expectedDeletes = $clearRequired ? ['value'] : [];
                $expectedPushes = $currentType === 'structural'
                    ? ['value/child.txt']
                    : (($currentType === 'empty' && $previousType === 'empty') ? [] : ['value']);

                $message = $previousType . ' to ' . $currentType;
                $this->assertSame($expectedDeletes, $this->deletePaths($journal->local_delete_stream_path), $message);
                $this->assertSame($expectedPushes, $this->listPaths($journal->local_paths_to_push), $message);
                $this->assertStringNotContainsString('directory-mode', (string) file_get_contents($journal->local_paths_to_push), $message);
            }
        }
    }

    public function testNewChangedDeletedAndUnchangedTogether(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'changed.txt' => [100, 5, 'file'],
            'deleted.txt' => [100, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));

        $counts = $journal->diff_local_files($this->writeIndex([
            'added.txt' => [300, 3, 'file'],
            'changed.txt' => [200, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));

        $this->assertSame(['changed' => 2, 'deleted' => 1], $counts);
        // Output order follows the sorted index order.
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame(['deleted.txt'], $this->deletePaths($journal->local_delete_stream_path));
    }

    public function testReplacementRootSurvivesLexicallyInterleavedSiblingPaths(): void
    {
        $journal = $this->makeJournal();
        $baseline = $this->tempDir . '/interleaved-baseline.jsonl';
        $current = $this->tempDir . '/interleaved-current.jsonl';
        file_put_contents($baseline, implode("\n", [
            json_encode(['path' => base64_encode('a'), 'ctime' => 1, 'size' => 0, 'type' => 'tree-directory', 'mode' => 0755]),
            json_encode(['path' => base64_encode('a/child'), 'ctime' => 1, 'size' => 1, 'type' => 'file', 'mode' => 0644]),
        ]) . "\n");
        file_put_contents($current, implode("\n", [
            json_encode(['path' => base64_encode('a'), 'ctime' => 2, 'size' => 1, 'type' => 'file', 'mode' => 0644]),
            // `-` sorts before `/`, so this sibling appears before a/child.
            json_encode(['path' => base64_encode('a-other'), 'ctime' => 2, 'size' => 1, 'type' => 'file', 'mode' => 0644]),
        ]) . "\n");
        $journal->capture_local_files_baseline($baseline);

        $this->assertSame(['changed' => 2, 'deleted' => 1], $journal->diff_local_files($current));
        $this->assertSame(['a'], $this->deletePaths($journal->local_delete_stream_path));
    }

    public function testDiffReplacesOutputsFromAnEarlierRun(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);

        // With no compatible baseline, a.txt lands in local_paths_to_push.
        $journal->diff_local_files($index);
        $this->assertSame(['a.txt'], $this->listPaths($journal->local_paths_to_push));

        // Capture and rerun: the old outputs must be replaced, not appended to.
        $journal->capture_local_files_baseline($index);
        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertFileDoesNotExist($journal->local_paths_to_push . '.tmp');
        $this->assertFileDoesNotExist($journal->local_delete_stream_path . '.tmp');
    }

    public function testFailedDiffDoesNotReplaceThePublishedDeleteStream(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'prior-delete.txt' => [1, 1, 'file'],
        ]));
        $journal->diff_local_files($this->writeIndex([]));
        $this->assertSame("prior-delete.txt\0", file_get_contents($journal->local_delete_stream_path));

        $next_baseline = $this->writeIndex([
            'a-next-delete.txt' => [1, 1, 'file'],
        ]);
        $journal->capture_local_files_baseline($next_baseline);
        $invalid_current = $this->tempDir . '/invalid-after-first-entry.jsonl';
        file_put_contents(
            $invalid_current,
            $this->indexLine('z-new.txt', 1, 1, 'file') . "\nnot-json\n"
        );

        try {
            $journal->diff_local_files($invalid_current);
            self::fail('The invalid index unexpectedly produced a delete stream.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not valid JSON', $exception->getMessage());
        }
        $this->assertSame("prior-delete.txt\0", file_get_contents($journal->local_delete_stream_path));
    }

    public function testPathsThatNeedBase64SurviveTheRoundTrip(): void
    {
        // A newline in a filename is the reason the index encodes paths at
        // all; the non-ASCII name checks the bytes pass through untouched.
        $weird = "wp-content/uploads/line\nbreak.png";
        $utf8 = 'wp-content/uploads/naïve-café.jpg';

        $journal = $this->makeJournal();
        $counts = $journal->diff_local_files($this->writeIndex([
            $weird => [100, 5, 'file'],
            $utf8 => [100, 6, 'file'],
        ]));

        $this->assertSame(['changed' => 2, 'deleted' => 0], $counts);
        $this->assertEqualsCanonicalizing(
            [$weird, $utf8],
            $this->listPaths($journal->local_paths_to_push)
        );
    }

    public function testDiffParsesJsonWithoutDependingOnFieldOrderOrEscaping(): void
    {
        $journal = $this->makeJournal();
        $path = 'wp-content/???';
        $base64Path = base64_encode($path);
        $baseline = $this->tempDir . '/baseline-shape.jsonl';
        $current = $this->tempDir . '/current-shape.jsonl';

        file_put_contents(
            $baseline,
            json_encode(['path' => $base64Path, 'ctime' => 100, 'size' => 5, 'type' => 'file'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $current,
            json_encode(['type' => 'file', 'size' => 5, 'ctime' => 100, 'path' => $base64Path], JSON_THROW_ON_ERROR) . "\n"
        );

        $journal->capture_local_files_baseline($baseline);

        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($current));
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame([], $this->deletePaths($journal->local_delete_stream_path));
    }

    public function testDiffRejectsLinesTheIndexWritersDoNotProduce(): void
    {
        // A blank line means the file is not a JSONL index and the diff
        // stops instead of silently skipping a possibly-corrupt entry.
        $journal = $this->makeJournal();
        $garbage = $this->tempDir . '/garbage.jsonl';
        file_put_contents(
            $garbage,
            $this->indexLine('a.txt', 100, 5, 'file') . "\n\nnot json at all\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $journal->diff_local_files($garbage);
    }

    public function testDiffRejectsAnUndecodablePath(): void
    {
        $journal = $this->makeJournal();
        $bad = $this->tempDir . '/bad-path.jsonl';
        file_put_contents($bad, '{"path":"%%%not-base64%%%","ctime":1,"size":1,"type":"file"}' . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid index path');
        $journal->diff_local_files($bad);
    }

    public function testDiffRequiresTheCurrentIndexToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current index file is missing');
        $this->makeJournal()->diff_local_files($this->tempDir . '/no-such-index.jsonl');
    }

    public function testDiffMemoryDoesNotGrowWithTheNumberOfChangedRoots(): void
    {
        $class = realpath(__DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php');
        $this->assertNotFalse($class);
        $caseRoot = $this->tempDir . '/bounded-memory';
        mkdir($caseRoot, 0700, true);
        $script = <<<'PHP'
require $argv[1];
$root = $argv[2];
$current = $root . '/current.jsonl';
$baseline = $root . '/baseline.jsonl';
$handle = fopen($current, 'wb');
for ($index = 0; $index < 500000; ++$index) {
    $path = sprintf('a-%06d.txt', $index);
    fwrite($handle, json_encode([
        'path' => base64_encode($path),
        'ctime' => 1,
        'size' => 1,
        'type' => 'file',
    ]) . "\n");
}
fclose($handle);
file_put_contents($baseline, json_encode([
    'path' => base64_encode('z-deleted-tree'),
    'ctime' => 1,
    'size' => 0,
    'type' => 'tree-directory',
]) . "\n");
$journal = new PushJournal($root . '/state', 'https://example.com/', $root . '/local-site');
$journal->capture_local_files_baseline($baseline);
$summary = $journal->diff_local_files($current);
if ($summary !== ['changed' => 500000, 'deleted' => 1]) {
    fwrite(STDERR, json_encode($summary));
    exit(2);
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=20M', '-r', $script, $class, $caseRoot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $caseRoot
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function makeJournal(): PushJournal
    {
        return new PushJournal(
            $this->tempDir . '/state',
            'https://example.com/?directory=%2Fsrv%2Fsite',
            $this->tempDir . '/local-site'
        );
    }

    /**
     * Write a sorted index file. Entries map path => [ctime, size, type];
     * the helper sorts by path bytes, matching how real index files are
     * stored.
     *
     * @param array<string, array{0: int, 1: int, 2: string}> $entries
     */
    private function writeIndex(array $entries): string
    {
        uksort($entries, 'strcmp');
        $lines = '';
        foreach ($entries as $path => [$ctime, $size, $type]) {
            $lines .= $this->indexLine($path, $ctime, $size, $type) . "\n";
        }
        $file = $this->tempDir . '/index-' . uniqid() . '.jsonl';
        file_put_contents($file, $lines);
        return $file;
    }

    private function indexLine(string $path, int $ctime, int $size, string $type): string
    {
        // Match the real index writers so fixtures use production-shaped JSON.
        return json_encode(
            ['path' => base64_encode($path), 'ctime' => $ctime, 'size' => $size, 'type' => $type],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function writeLogicalIndex(string $path, string $logicalType, int $version): string
    {
        if ($logicalType === 'file') {
            $entries = [[
                'path' => base64_encode('value'),
                'ctime' => $version,
                'size' => $version,
                'type' => 'file',
            ]];
        } elseif ($logicalType === 'symlink') {
            $entries = [[
                'path' => base64_encode('value'),
                'ctime' => $version,
                'size' => $version,
                'type' => 'symlink',
                'target' => base64_encode('target-' . $version),
            ]];
        } elseif ($logicalType === 'empty') {
            $entries = [[
                'path' => base64_encode('value'),
                'ctime' => $version,
                'size' => 0,
                'type' => 'directory',
            ]];
        } else {
            $entries = [
                [
                    'path' => base64_encode('value'),
                    'ctime' => $version,
                    'size' => 0,
                    'type' => 'tree-directory',
                ],
                [
                    'path' => base64_encode('value/child.txt'),
                    'ctime' => $version,
                    'size' => $version,
                    'type' => 'file',
                ],
            ];
        }
        file_put_contents($path, implode("\n", array_map(static function (array $entry): string {
            return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $entries)) . "\n");
        return $path;
    }

    /** @return list<string> */
    private function listPaths(string $file): array
    {
        $this->assertFileExists($file);
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $paths[] = base64_decode($data['path'], true);
        }
        return $paths;
    }

    /** @return list<string> */
    private function deletePaths(string $file): array
    {
        $this->assertFileExists($file);
        $stream = file_get_contents($file);
        $this->assertIsString($stream);
        if ($stream === '') {
            return [];
        }
        $this->assertStringEndsWith("\0", $stream);
        return explode("\0", substr($stream, 0, -1));
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
