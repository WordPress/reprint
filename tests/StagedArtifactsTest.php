<?php

use PHPUnit\Framework\TestCase;

final class StagedArtifactsTest extends TestCase
{
    private string $staging_dir;

    protected function setUp(): void
    {
        $this->staging_dir = sys_get_temp_dir() . '/staged-artifacts-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->staging_dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function makeStore(): Site_Export_Staged_Artifacts
    {
        return new Site_Export_Staged_Artifacts($this->staging_dir);
    }

    // ---------------------------------------------------------------
    // Assembly and finalize
    // ---------------------------------------------------------------

    public function testSequentialAppendsAssembleAndVerify(): void
    {
        $store = $this->makeStore();
        $body = 'hello staged upload world';

        $first = $store->append('artifact-1', 0, substr($body, 0, 10));
        $second = $store->append('artifact-1', 10, substr($body, 10));

        $this->assertSame('accepted', $first['status']);
        $this->assertSame(10, $first['committed_bytes']);
        $this->assertSame('accepted', $second['status']);
        $this->assertSame(strlen($body), $second['committed_bytes']);

        $result = $store->finalize('artifact-1', strlen($body));
        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($result['path']));
        $this->assertTrue($store->status('artifact-1')['verified']);
    }

    public function testCallerDrivenLoopStreamsASourceInBoundedBuffers(): void
    {
        $store = $this->makeStore();
        $body = str_repeat('streamed!', 100000); // ~900 KB, many buffers

        // The endpoint's loop: read the source in bounded buffers, one
        // append per buffer.
        $source = fopen('php://temp', 'r+b');
        fwrite($source, $body);
        rewind($source);
        $committed = 0;
        while (($buffer = fread($source, 65536)) !== false && $buffer !== '') {
            $result = $store->append('artifact-1', $committed, $buffer);
            $this->assertSame('accepted', $result['status']);
            $committed = $result['committed_bytes'];
        }
        fclose($source);

        $this->assertSame(strlen($body), $committed);
        $verified = $store->finalize('artifact-1', strlen($body));
        $this->assertSame($body, file_get_contents($verified['path']));
    }

    public function testZeroByteArtifactVerifiesWithoutAppends(): void
    {
        $store = $this->makeStore();

        $result = $store->finalize('empty-artifact', 0);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('', file_get_contents($result['path']));
    }

    public function testFinalizeIsIdempotent(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7);

        $again = $store->finalize('artifact-1', 7);

        $this->assertSame('verified', $again['status']);
    }

    public function testFinalizeOfUnknownArtifactReportsMissing(): void
    {
        $store = $this->makeStore();

        $result = $store->finalize('never-uploaded', 5);

        $this->assertSame(['rejected', 'missing'], [$result['status'], $result['reason']]);
    }

    public function testRefinalizeWithDifferentSizeIsRejected(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7);

        $result = $store->finalize('artifact-1', 9);

        $this->assertSame(
            ['rejected', 'size_mismatch', 'verified_record'],
            [$result['status'], $result['reason'], $result['detail']]
        );
        $this->assertTrue($store->status('artifact-1')['verified']);
    }

    // ---------------------------------------------------------------
    // Reentrancy and resume
    // ---------------------------------------------------------------

    public function testLoopCanStopAfterAnyStepAndResumeInAFreshProcess(): void
    {
        $buffers = ['aa', 'bb', 'cc', 'dd'];

        foreach ([0, 1, 2, 3, 4] as $stop_after) {
            $staging = $this->staging_dir . "/stop-after-{$stop_after}";
            $first_run = new Site_Export_Staged_Artifacts($staging);
            for ($i = 0; $i < $stop_after; $i++) {
                $this->assertSame(
                    'accepted',
                    $first_run->append('artifact-1', $i * 2, $buffers[$i])['status']
                );
            }
            unset($first_run); // The driving loop stops; nothing is held.

            // A fresh instance — the next request — resumes from the cursor.
            $resumed = new Site_Export_Staged_Artifacts($staging);
            $this->assertSame(
                $stop_after * 2,
                $resumed->status('artifact-1')['committed_bytes'],
                "stopped after {$stop_after} steps"
            );
            for ($i = $stop_after; $i < 4; $i++) {
                $this->assertSame(
                    'accepted',
                    $resumed->append('artifact-1', $i * 2, $buffers[$i])['status']
                );
            }
            $verified = $resumed->finalize('artifact-1', 8);
            $this->assertSame('verified', $verified['status'], "stopped after {$stop_after} steps");
            $this->assertSame('aabbccdd', file_get_contents($verified['path']));
        }
    }

    public function testStoppingInsideAStepDiscardsTheUncommittedTail(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'committed');

        // Simulate a kill after bytes were written but before the cursor
        // moved: garbage sits beyond committed_bytes in the file.
        file_put_contents($this->staging_dir . '/files/artifact-1', 'GARBAGE', FILE_APPEND);

        $this->assertSame('accepted', $store->append('artifact-1', 9, '-tail')['status']);
        $body = 'committed-tail';
        $result = $store->finalize('artifact-1', strlen($body));
        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($result['path']));
    }

    public function testResendingCommittedBytesIsADuplicateNoOp(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        $retry = $store->append('artifact-1', 0, 'first');

        $this->assertSame('duplicate', $retry['status']);
        $this->assertSame(5, $retry['committed_bytes']);
        $this->assertSame('accepted', $store->append('artifact-1', 5, 'second')['status']);
    }

    public function testOffsetGapIsRejectedWithCommittedBytesForResync(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        $result = $store->append('artifact-1', 20, 'too-far');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('offset_gap', $result['reason']);
        $this->assertSame(5, $result['committed_bytes']);
    }

    public function testSwitchingArtifactsRestartsTheAbandonedOne(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-a', 0, 'first');

        // Sequential transfers: an append to another artifact moves the cursor.
        $this->assertSame('accepted', $store->append('artifact-b', 0, 'bee')['status']);

        $stale = $store->append('artifact-a', 5, 'second');
        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$stale['status'], $stale['reason'], $stale['committed_bytes']]
        );
        $this->assertSame('accepted', $store->append('artifact-a', 0, 'fresh')['status']);
    }

    public function testCorruptCommitRecordRestartsTheArtifact(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        file_put_contents($this->staging_dir . '/state.json', 'not json {');

        $this->assertSame(0, $store->status('artifact-1')['committed_bytes']);
        $resumed = $store->append('artifact-1', 5, 'second');
        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$resumed['status'], $resumed['reason'], $resumed['committed_bytes']]
        );
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'fresh')['status']);
    }

    public function testShrunkenStagingFileIsZeroFilledBackToTheCursor(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        // The staging file drifts between requests: something shrinks it
        // below the committed size. The cursor, not the file, is the
        // authority — the next append zero-fills back to the committed size.
        file_put_contents($this->staging_dir . '/files/artifact-1', 'fi');

        $this->assertSame('accepted', $store->append('artifact-1', 5, 'second')['status']);
        $verified = $store->finalize('artifact-1', 11);
        $this->assertSame('verified', $verified['status']);
        $this->assertSame("fi\0\0\0second", file_get_contents($verified['path']));
    }

    // ---------------------------------------------------------------
    // Crash recovery: partial states a kill can leave behind
    // ---------------------------------------------------------------

    public function testEveryCallCanRunInItsOwnProcess(): void
    {
        // Each call constructs its own store instance — no shared in-memory
        // state, as if every step ran in a separate PHP request.
        $step = fn() => new Site_Export_Staged_Artifacts($this->staging_dir);

        $this->assertSame('accepted', $step()->append('a.txt', 0, 'aaa')['status']);
        $this->assertSame('accepted', $step()->append('a.txt', 3, 'AAA')['status']);
        $this->assertSame('verified', $step()->finalize('a.txt', 6)['status']);
        $this->assertSame('accepted', $step()->append('b.txt', 0, 'bb')['status']);
        $this->assertSame('verified', $step()->finalize('b.txt', 2)['status']);
        $this->assertSame('verified', $step()->finalize('empty.txt', 0)['status']);

        $this->assertSame('aaaAAA', file_get_contents($this->staging_dir . '/files/a.txt'));
        $this->assertSame('bb', file_get_contents($this->staging_dir . '/files/b.txt'));
        $this->assertTrue($step()->status('a.txt')['verified']);
    }

    public function testKilledCursorWriteLeavesRecoverableState(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        // Simulate a kill inside write_state: the temp record was written,
        // the rename never happened, and the appended bytes sit beyond the
        // still-old cursor.
        file_put_contents($this->staging_dir . '/state.json.tmp', '{"artifact_id":"artifact-1"');
        file_put_contents($this->staging_dir . '/files/artifact-1', 'second', FILE_APPEND);

        $this->assertSame(5, $store->status('artifact-1')['committed_bytes']);
        $this->assertSame('accepted', $store->append('artifact-1', 5, 'second')['status']);
        $verified = $store->finalize('artifact-1', 11);
        $this->assertSame('verified', $verified['status']);
        $this->assertSame('firstsecond', file_get_contents($verified['path']));
    }

    public function testTornVerifiedRecordIsIgnoredAndRefinalizeRecovers(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-a', 0, 'aaa');
        $store->finalize('artifact-a', 3);
        $store->append('artifact-b', 0, 'bbbb');

        // A malformed marker (markers commit by rename, so this means
        // external interference) must read as not verified.
        file_put_contents(
            $this->staging_dir . '/verified/artifact-b',
            '{"si'
        );

        $this->assertFalse($store->status('artifact-b')['verified']);
        $this->assertTrue($store->status('artifact-a')['verified']);
        $this->assertSame('verified', $store->finalize('artifact-b', 4)['status']);
        $this->assertTrue($store->status('artifact-b')['verified']);
    }

    public function testFinalizeKilledBeforeClearingCursorStaysConsistent(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        // Simulate a kill after the verified marker landed but before the
        // cursor was cleared.
        mkdir($this->staging_dir . '/verified', 0700, true);
        file_put_contents(
            $this->staging_dir . '/verified/artifact-1',
            json_encode(['size' => 7])
        );

        $stale = $store->append('artifact-1', 7, 'more');
        $this->assertSame(['rejected', 'already_verified'], [$stale['status'], $stale['reason']]);
        $this->assertSame('verified', $store->finalize('artifact-1', 7)['status']);
        $this->assertSame('accepted', $store->append('artifact-2', 0, 'next')['status']);
    }

    public function testVerifiedRecordWithoutTheArtifactFileReportsMissing(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7);
        unlink($this->staging_dir . '/files/artifact-1');

        $refinalized = $store->finalize('artifact-1', 7);

        $this->assertSame(
            ['rejected', 'missing', 'verified_record'],
            [$refinalized['status'], $refinalized['reason'], $refinalized['detail']]
        );

        // Recovery: discard the orphaned record, then upload from scratch.
        $this->assertTrue($store->discard('artifact-1'));
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'payload')['status']);
        $this->assertSame('verified', $store->finalize('artifact-1', 7)['status']);
    }

    public function testDiscardKilledMidwayIsRetriable(): void
    {
        // Window 1: the artifact file was unlinked, the cursor survived.
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');
        unlink($this->staging_dir . '/files/artifact-1');

        $this->assertTrue($store->discard('artifact-1'));
        $this->assertSame(0, $store->status('artifact-1')['committed_bytes']);
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'fresh')['status']);

        // Window 2: the cursor was cleared, the verified record survived.
        $store->finalize('artifact-1', 5);
        unlink($this->staging_dir . '/files/artifact-1');

        $this->assertTrue($store->discard('artifact-1'));
        $this->assertFalse($store->status('artifact-1')['verified']);
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'again')['status']);
    }

    public function testArtifactPathBlockedByAnotherEntryIsATypedError(): void
    {
        $store = $this->makeStore();

        // A directory sits where the artifact file should go.
        $store->append('theme/style.css', 0, 'body{}');
        $blocked_by_dir = $store->append('theme', 0, 'zip');
        $this->assertSame(
            ['rejected', 'io_error', 'open_artifact_file'],
            [$blocked_by_dir['status'], $blocked_by_dir['reason'], $blocked_by_dir['detail']]
        );

        // A file sits where a parent directory should go.
        $store->append('plugin.php', 0, '<?php');
        $blocked_by_file = $store->append('plugin.php/readme.txt', 0, 'hi');
        $this->assertSame(
            ['rejected', 'io_error', 'create_staging_dir'],
            [$blocked_by_file['status'], $blocked_by_file['reason'], $blocked_by_file['detail']]
        );
    }

    public function testUnwritableStagingDirectoryIsATypedError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks do not bind as root.');
        }

        mkdir($this->staging_dir, 0700, true);
        chmod($this->staging_dir, 0500);
        $store = $this->makeStore();

        $result = $store->append('artifact-1', 0, 'bytes');
        $this->assertSame(
            ['rejected', 'io_error', 'open_lock_file'],
            [$result['status'], $result['reason'], $result['detail']]
        );

        // The next run recovers once the environment does.
        chmod($this->staging_dir, 0700);
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'bytes')['status']);
    }

    // ---------------------------------------------------------------
    // Input validation
    // ---------------------------------------------------------------

    public function testFinalizeRejectsWrongSize(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        $wrong_size = $store->finalize('artifact-1', 99);

        $this->assertSame(['rejected', 'size_mismatch'], [$wrong_size['status'], $wrong_size['reason']]);
        $this->assertFalse($store->status('artifact-1')['verified']);
    }

    public function testMalformedOffsetAndBufferAreRejected(): void
    {
        $store = $this->makeStore();

        $bad_offset = $store->append('artifact-1', -1, 'bytes');
        $this->assertSame(['rejected', 'invalid_offset'], [$bad_offset['status'], $bad_offset['reason']]);

        $empty = $store->append('artifact-1', 0, '');
        $this->assertSame(['rejected', 'empty_body'], [$empty['status'], $empty['reason']]);
    }

    public function testWritingToAVerifiedArtifactIsRejected(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7);

        $result = $store->append('artifact-1', 7, 'more');

        $this->assertSame(['rejected', 'already_verified'], [$result['status'], $result['reason']]);
    }

    public function testStagingMirrorsTheArtifactPath(): void
    {
        $store = $this->makeStore();
        $id = 'wp-content/themes/foo/style.css';

        $this->assertSame('accepted', $store->append($id, 0, 'body { }')['status']);

        $this->assertFileExists($this->staging_dir . '/files/' . $id);
        $this->assertFileExists($this->staging_dir . '/state.json');
    }

    public function testSiteFilenamesThatLookLikeStagingRecordsStageVerbatim(): void
    {
        $store = $this->makeStore();

        // 'lock' and 'files' shadow the store's own scaffolding names;
        // they must stage under files/ without touching the real lock
        // file or the artifact tree root.
        foreach (['index.php', 'index.php.part', 'index.php.meta.json', 'state.json', 'verified', 'verified.tmp', 'lock', 'files'] as $id) {
            $this->assertSame('accepted', $store->append($id, 0, "body of {$id}")['status']);
            $verified = $store->finalize($id, strlen("body of {$id}"));
            $this->assertSame('verified', $verified['status'], "id: {$id}");
            $this->assertSame("body of {$id}", file_get_contents($this->staging_dir . '/files/' . $id));
        }

        // The store's own lock file must still be the lock, not an
        // artifact: concurrency still excludes after staging id 'lock'.
        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB), 'the base lock file survives as a lock');
        $busy = $store->append('another.txt', 0, 'x');
        $this->assertSame('busy', $busy['status']);
        flock($holder, LOCK_UN);
        fclose($holder);
    }

    public function testDeeplyNestedIdsStageAndDiscard(): void
    {
        // Hosting trees nest far deeper than fixtures; the files/ mirror,
        // the verified/ marker tree, and discard must all handle it.
        $store = $this->makeStore();
        $id = implode('/', array_fill(0, 12, 'd')) . '/deep.txt';

        $this->assertSame('accepted', $store->append($id, 0, 'deep bytes')['status']);
        $this->assertSame('verified', $store->finalize($id, 10)['status']);
        $this->assertFileExists($this->staging_dir . '/files/' . $id);
        $this->assertTrue($store->status($id)['verified']);

        $this->assertTrue($store->discard($id));
        $this->assertFalse($store->status($id)['exists']);
        $this->assertFileDoesNotExist($this->staging_dir . '/files/' . $id);
        // Discard removes the file and the records, not the directory
        // skeleton it grew — that goes when the staging dir is torn down.
        $this->assertDirectoryExists($this->staging_dir . '/files/d');
    }

    public function testIdsOutsideTheStagingDirAreRejected(): void
    {
        $store = $this->makeStore();

        foreach (['../../outside/etc/passwd', '/etc/passwd', 'a/../b', 'a//b', './a', '', 'a\\b', "a\0b"] as $hostile_id) {
            $result = $store->append($hostile_id, 0, 'bytes');
            $this->assertSame(
                ['rejected', 'invalid_artifact_id'],
                [$result['status'], $result['reason']],
                "id: {$hostile_id}"
            );
            $finalized = $store->finalize($hostile_id, 5);
            $this->assertSame(
                ['rejected', 'invalid_artifact_id'],
                [$finalized['status'], $finalized['reason']],
                "id: {$hostile_id}"
            );
            $this->assertFalse($store->status($hostile_id)['exists'], "id: {$hostile_id}");
            $this->assertTrue($store->discard($hostile_id), "id: {$hostile_id}");
        }

        $this->assertDirectoryDoesNotExist(dirname(dirname($this->staging_dir)) . '/outside');
        $this->assertFileDoesNotExist($this->staging_dir . '/files/etc/passwd');
    }

    // ---------------------------------------------------------------
    // Concurrency and lifecycle
    // ---------------------------------------------------------------

    public function testConcurrentWriterGetsBusy(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');

        // Hold the lock the way a concurrent writer would.
        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);

        $busy_append = $store->append('artifact-1', 5, 'second');
        $this->assertSame(['busy', 5], [$busy_append['status'], $busy_append['committed_bytes']]);
        $busy_finalize = $store->finalize('artifact-1', 5);
        $this->assertSame(['busy', 5], [$busy_finalize['status'], $busy_finalize['committed_bytes']]);
        $this->assertFalse($store->discard('artifact-1'));

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertSame('accepted', $store->append('artifact-1', 5, 'second')['status']);
    }

    public function testDiscardOfAnUntracedArtifactRespectsTheWriterLock(): void
    {
        $store = $this->makeStore();
        $store->append('other-artifact', 0, 'xx');

        // The first append for artifact-1 is in flight: it holds the lock
        // but has not yet created the file or moved the cursor. A discard
        // that answered true here would be contradicted by that append's
        // commit a moment later.
        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);

        $this->assertFalse($store->discard('artifact-1'));

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertTrue($store->discard('artifact-1'));
    }

    public function testDiscardRemovesAllStagedData(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        $this->assertTrue($store->discard('artifact-1'));

        $status = $store->status('artifact-1');
        $this->assertSame(['exists' => false, 'committed_bytes' => 0, 'verified' => false], $status);
        $this->assertTrue($store->discard('never-existed'));
    }

    public function testDiscardBeforeAnyStagingExistsCreatesNothing(): void
    {
        $store = $this->makeStore();

        $this->assertTrue($store->discard('artifact-1'));

        $this->assertDirectoryDoesNotExist($this->staging_dir);
    }

    public function testDiscardedArtifactRestartsFromScratch(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'first');
        $store->discard('artifact-1');

        $stale = $store->append('artifact-1', 5, 'second');

        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$stale['status'], $stale['reason'], $stale['committed_bytes']]
        );
        $this->assertSame('accepted', $store->append('artifact-1', 0, 'fresh')['status']);
    }

    public function testDiscardClearsTheCursorWhenTheArtifactFileIsMissing(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        unlink($this->staging_dir . '/files/artifact-1');

        $this->assertTrue($store->discard('artifact-1'));

        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            $store->status('artifact-1')
        );
    }

    public function testDiscardReportsFailureWhenTheArtifactFileCannotBeRemoved(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks do not bind as root.');
        }

        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        // Unlinking needs write permission on files/, not on the file.
        chmod($this->staging_dir . '/files', 0500);
        $this->assertFalse($store->discard('artifact-1'));
        $this->assertFileExists($this->staging_dir . '/files/artifact-1');

        // Retry until true: the next attempt finishes the cleanup.
        chmod($this->staging_dir . '/files', 0700);
        $this->assertTrue($store->discard('artifact-1'));
        $this->assertSame(0, $store->status('artifact-1')['committed_bytes']);
    }

    public function testDiscardReportsFailureWhenTheCursorCannotBeCleared(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks do not bind as root.');
        }

        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        // Clearing the cursor writes state.json.tmp into the staging dir;
        // the artifact unlink itself only needs write on files/.
        chmod($this->staging_dir, 0500);
        $this->assertFalse($store->discard('artifact-1'));
        $this->assertSame(7, $store->status('artifact-1')['committed_bytes']);

        chmod($this->staging_dir, 0700);
        $this->assertTrue($store->discard('artifact-1'));
        $this->assertSame(0, $store->status('artifact-1')['committed_bytes']);
    }

    public function testDiscardReportsFailureWhenTheVerifiedRecordCannotBeRemoved(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks do not bind as root.');
        }

        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7);

        // Unlinking the marker needs write permission on verified/.
        chmod($this->staging_dir . '/verified', 0500);
        $this->assertFalse($store->discard('artifact-1'));
        $this->assertTrue($store->status('artifact-1')['verified']);

        chmod($this->staging_dir . '/verified', 0700);
        $this->assertTrue($store->discard('artifact-1'));
        $this->assertFalse($store->status('artifact-1')['verified']);
    }

    public function testStatusOfUnknownArtifact(): void
    {
        $store = $this->makeStore();

        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            $store->status('unknown')
        );
    }
}
