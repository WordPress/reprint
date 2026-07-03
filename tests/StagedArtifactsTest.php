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

        foreach (['index.php', 'index.php.part', 'index.php.meta.json', 'state.json'] as $id) {
            $this->assertSame('accepted', $store->append($id, 0, "body of {$id}")['status']);
            $verified = $store->finalize($id, strlen("body of {$id}"));
            $this->assertSame('verified', $verified['status'], "id: {$id}");
            $this->assertSame("body of {$id}", file_get_contents($this->staging_dir . '/files/' . $id));
        }
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

    public function testDiscardRemovesAllStagedData(): void
    {
        $store = $this->makeStore();
        $store->append('artifact-1', 0, 'payload');

        $this->assertTrue($store->discard('artifact-1'));

        $status = $store->status('artifact-1');
        $this->assertSame(['exists' => false, 'committed_bytes' => 0, 'verified' => false], $status);
        $this->assertTrue($store->discard('never-existed'));
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

    public function testStatusOfUnknownArtifact(): void
    {
        $store = $this->makeStore();

        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            $store->status('unknown')
        );
    }
}
