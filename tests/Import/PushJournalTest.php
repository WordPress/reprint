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
 * (no baseline yet; stale lists from an earlier run) and the encoding
 * round-trip for paths that need base64 in the first place.
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
        $this->assertFalse($journal->has_local_files_baseline());

        $journal->capture_local_files_baseline($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertTrue($journal->has_local_files_baseline());
        $this->assertFileDoesNotExist($journal->local_files_baseline_path() . '.tmp');

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

    public function testLocalAndRemoteBaselinesAreSeparateFiles(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex(['a' => [1, 1, 'file']]));
        $journal->capture_remote_files_baseline($this->writeIndex(['b' => [2, 2, 'file']]));

        $this->assertTrue($journal->has_local_files_baseline());
        $this->assertTrue($journal->has_remote_files_baseline());
        $this->assertNotSame($journal->local_files_baseline_path(), $journal->remote_files_baseline_path());
        $this->assertSame(['a'], $this->indexPaths($journal->local_files_baseline_path()));
        $this->assertSame(['b'], $this->indexPaths($journal->remote_files_baseline_path()));
    }

    // ------------------------------------------------------------------
    //  Local diff
    // ------------------------------------------------------------------

    public function testFirstPushTreatsEveryEntryAsChanged(): void
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
            $this->listPaths($journal->upload_list_path())
        );
        $this->assertSame([], $this->listPaths($journal->deletion_list_path()));
    }

    public function testUnchangedIndexProducesEmptyLists(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex([
            'a.txt' => [100, 5, 'file'],
            'b/c.txt' => [200, 7, 'file'],
        ]);
        $journal->capture_local_files_baseline($index);

        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame([], $this->listPaths($journal->upload_list_path()));
        $this->assertSame([], $this->listPaths($journal->deletion_list_path()));
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
            'type-swap' => [100, 5, 'link'],
            'same.txt' => [100, 5, 'file'],
        ]));

        $this->assertSame(['changed' => 3, 'deleted' => 0], $counts);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($journal->upload_list_path())
        );
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
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($journal->upload_list_path()));
        $this->assertSame(['deleted.txt'], $this->listPaths($journal->deletion_list_path()));
    }

    public function testDiffReplacesListsFromAnEarlierRun(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);

        // First run, no baseline: a.txt lands on the upload list.
        $journal->diff_local_files($index);
        $this->assertSame(['a.txt'], $this->listPaths($journal->upload_list_path()));

        // Capture and rerun: the old list must be replaced, not appended to.
        $journal->capture_local_files_baseline($index);
        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame([], $this->listPaths($journal->upload_list_path()));
        $this->assertFileDoesNotExist($journal->upload_list_path() . '.tmp');
        $this->assertFileDoesNotExist($journal->deletion_list_path() . '.tmp');
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
            $this->listPaths($journal->upload_list_path())
        );
    }

    public function testDiffSkipsBlankLinesAndRejectsGarbage(): void
    {
        $journal = $this->makeJournal();

        $withBlanks = $this->tempDir . '/with-blanks.jsonl';
        file_put_contents(
            $withBlanks,
            $this->indexLine('a.txt', 100, 5, 'file') . "\n\n" .
            $this->indexLine('b.txt', 100, 5, 'file') . "\n"
        );
        $this->assertSame(['changed' => 2, 'deleted' => 0], $journal->diff_local_files($withBlanks));

        $garbage = $this->tempDir . '/garbage.jsonl';
        file_put_contents($garbage, "not json at all\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid index line');
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

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function makeJournal(): PushJournal
    {
        return new PushJournal($this->tempDir . '/state', 'https://example.com/');
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
        return json_encode(
            ['path' => base64_encode($path), 'ctime' => $ctime, 'size' => $size, 'type' => $type],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Decode the paths from an upload/deletion list ({"path": <base64>} lines).
     *
     * @return list<string>
     */
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

    /**
     * Decode the paths from a baseline (full index lines).
     *
     * @return list<string>
     */
    private function indexPaths(string $file): array
    {
        return $this->listPaths($file);
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
