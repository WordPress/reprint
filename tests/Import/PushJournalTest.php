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
 * (no baseline yet; stale lists from an earlier run), the encoding
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
            $this->listPaths($journal->local_paths_to_push)
        );
        $this->assertSame([], $this->listPaths($journal->local_paths_to_delete));

        // Pin the exact bytes: one {"path": <base64>} object per line, the
        // .import-download-list.jsonl shape.
        $firstLine = strtok((string) file_get_contents($journal->local_paths_to_push), "\n");
        $this->assertSame('{"path":"' . base64_encode('index.php') . '"}', $firstLine);
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
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame([], $this->listPaths($journal->local_paths_to_delete));
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
            $this->listPaths($journal->local_paths_to_push)
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
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame(['deleted.txt'], $this->listPaths($journal->local_paths_to_delete));
    }

    public function testDiffReplacesListsFromAnEarlierRun(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);

        // First run, no baseline: a.txt lands in local_paths_to_push.
        $journal->diff_local_files($index);
        $this->assertSame(['a.txt'], $this->listPaths($journal->local_paths_to_push));

        // Capture and rerun: the old list must be replaced, not appended to.
        $journal->capture_local_files_baseline($index);
        $this->assertSame(['changed' => 0, 'deleted' => 0], $journal->diff_local_files($index));
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertFileDoesNotExist($journal->local_paths_to_push . '.tmp');
        $this->assertFileDoesNotExist($journal->local_paths_to_delete . '.tmp');
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
        $this->assertSame([], $this->listPaths($journal->local_paths_to_delete));
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

    /**
     * Keeps active path lists independent from later caller-index changes.
     *
     * The sender copy, positive-work list, and raw work-delete stream must all
     * describe one index so their persisted byte offsets remain meaningful
     * after the caller replaces or removes its original index file.
     */
    public function testActiveSenderIndexAndWorkDeletesStayStable(): void
    {
        $journal = $this->makeJournal();
        $baseline = $this->writeIndex([
            'delete-me.txt' => [100, 1, 'file'],
            'keep.txt' => [100, 1, 'file'],
        ]);
        $journal->capture_local_files_baseline($baseline);
        $current = $this->writeIndex([
            'keep.txt' => [100, 1, 'file'],
            'new.txt' => [200, 2, 'file'],
        ]);

        $journal->capture_sender_index($current);
        $journal->diff_local_files($journal->sender_index_path);
        $this->assertSame(strlen("delete-me.txt\0"), $journal->prepare_work_deletes());
        $this->assertSame("delete-me.txt\0", file_get_contents($journal->work_deletes_path));

        file_put_contents($current, $this->indexLine('later.txt', 300, 3, 'file') . "\n");
        $this->assertSame(['new.txt'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame(['delete-me.txt'], $this->listPaths($journal->local_paths_to_delete));
        $this->assertStringContainsString(base64_encode('new.txt'), (string) file_get_contents($journal->sender_index_path));
        $this->assertStringNotContainsString(base64_encode('later.txt'), (string) file_get_contents($journal->sender_index_path));
    }

    /**
     * Round-trips and clears the complete sender checkpoint shape.
     *
     * The private JSON record is trusted as one atomically replaced unit, so
     * every correlated cursor and source field must survive unchanged.
     */
    public function testSenderStateRoundTripsTheExactCheckpoint(): void
    {
        $journal = $this->makeJournal();
        $state = [
            'version' => 1,
            'push_session_id' => str_repeat('a', 32),
            'phase' => 'reconciling_work',
            'paths_byte_offset' => 17,
            'current_path_b64' => base64_encode('file.txt'),
            'next_paths_byte_offset' => 41,
            'source_token' => ['type' => 'file', 'size' => 9, 'ctime' => 123],
            'confirmed_bytes' => 4,
            'work_deletes_byte_offset' => 7,
            'recoverable_failures' => 2,
            'max_part_bytes' => 4194304,
            'request_sizer_state' => [
                'request_body_bytes' => 1048576,
                'ceiling_bytes' => 2097152,
                'growth_holdoff_remaining' => 1,
            ],
        ];

        $this->assertNull($journal->read_sender_state());
        $journal->write_sender_state($state);
        $this->assertSame($state, $journal->read_sender_state());
        $this->assertFileDoesNotExist($journal->sender_state_path . '.tmp');
        $journal->clear_sender_state();
        $this->assertNull($journal->read_sender_state());
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
        // Match the real index writers so fixtures use production-shaped JSON.
        return json_encode(
            ['path' => base64_encode($path), 'ctime' => $ctime, 'size' => $size, 'type' => $type],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Decode a {"path": <base64>} JSONL list.
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
