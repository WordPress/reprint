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

    private function writeString(Site_Export_Staged_Artifacts $store, string $id, int $offset, string $bytes): array
    {
        return $store->write_chunk($id, $offset, strlen($bytes), hash('crc32b', $bytes), $bytes);
    }

    private function chunk(int $offset, string $bytes): array
    {
        return [
            'offset' => $offset,
            'length' => strlen($bytes),
            'expected_crc32' => hash('crc32b', $bytes),
            'source' => $bytes,
        ];
    }

    // ---------------------------------------------------------------
    // Assembly and finalize
    // ---------------------------------------------------------------

    public function testSequentialChunksAssembleAndVerify(): void
    {
        $store = $this->makeStore();
        $body = 'hello staged upload world';

        $first = $this->writeString($store, 'artifact-1', 0, substr($body, 0, 10));
        $second = $this->writeString($store, 'artifact-1', 10, substr($body, 10));

        $this->assertSame('accepted', $first['status']);
        $this->assertSame(10, $first['committed_bytes']);
        $this->assertSame('accepted', $second['status']);
        $this->assertSame(strlen($body), $second['committed_bytes']);

        $result = $store->finalize('artifact-1', strlen($body), hash('crc32b', $body));
        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($result['path']));
        $this->assertTrue($store->status('artifact-1')['verified']);
    }

    public function testChunkBodyCanBeAStream(): void
    {
        $store = $this->makeStore();
        $body = str_repeat('streamed!', 100000); // ~900 KB, spans several copy buffers

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $body);
        rewind($stream);
        $result = $store->write_chunk('artifact-1', 0, strlen($body), hash('crc32b', $body), $stream);
        fclose($stream);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('verified', $store->finalize('artifact-1', strlen($body), hash('crc32b', $body))['status']);
    }

    public function testManyChunksCanBeWrittenInOneOpenLockedBatch(): void
    {
        $store = $this->makeStore();
        $chunks = [];
        $body = '';
        $offset = 0;
        for ($i = 0; $i < 1000; $i++) {
            $bytes = chr(65 + ($i % 26));
            $chunks[] = $this->chunk($offset, $bytes);
            $body .= $bytes;
            $offset++;
        }

        $result = $store->write_chunks('artifact-1', $chunks);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(strlen($body), $result['committed_bytes']);
        $verified = $store->finalize('artifact-1', strlen($body), hash('crc32b', $body));
        $this->assertSame('verified', $verified['status']);
        $this->assertSame($body, file_get_contents($verified['path']));
    }

    public function testStreamingBatchKeepsTheArtifactLockAcrossChunks(): void
    {
        $store = $this->makeStore();
        $busy_write = null;

        $chunks = (function () use ($store, &$busy_write) {
            yield $this->chunk(0, 'first');
            $busy_write = $this->writeString($store, 'artifact-1', 5, 'blocked');
            yield $this->chunk(5, 'second');
        })();

        $result = $store->write_chunks('artifact-1', $chunks);

        $this->assertSame(['busy', 5], [$busy_write['status'], $busy_write['committed_bytes']]);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(11, $result['committed_bytes']);
        $verified = $store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'));
        $this->assertSame('firstsecond', file_get_contents($verified['path']));
    }

    public function testZeroByteArtifactVerifiesWithoutChunks(): void
    {
        $store = $this->makeStore();

        $result = $store->finalize('empty-artifact', 0, hash('crc32b', ''));

        $this->assertSame('verified', $result['status']);
        $this->assertSame('', file_get_contents($result['path']));
    }

    public function testFinalizeIsIdempotent(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7, hash('crc32b', 'payload'));

        $again = $store->finalize('artifact-1', 7, hash('crc32b', 'payload'));

        $this->assertSame('verified', $again['status']);
    }

    public function testFinalizeOfUnknownArtifactReportsMissing(): void
    {
        $store = $this->makeStore();

        $result = $store->finalize('never-uploaded', 5, hash('crc32b', 'bytes'));

        $this->assertSame(['rejected', 'missing'], [$result['status'], $result['reason']]);
    }

    public function testRefinalizeWithDifferentChecksumIsRejected(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7, hash('crc32b', 'payload'));

        $result = $store->finalize('artifact-1', 7, hash('crc32b', 'tampered'));

        $this->assertSame(
            ['rejected', 'hash_mismatch', 'verified_record'],
            [$result['status'], $result['reason'], $result['detail']]
        );
        $this->assertTrue($store->status('artifact-1')['verified']);
    }

    // ---------------------------------------------------------------
    // Idempotent retries and resume
    // ---------------------------------------------------------------

    public function testRetryingACommittedChunkIsADuplicateNoOp(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $retry = $this->writeString($store, 'artifact-1', 0, 'first');

        $this->assertSame('duplicate', $retry['status']);
        $this->assertSame(5, $retry['committed_bytes']);
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
    }

    public function testDuplicateStreamChunkIsDrainedInsideBatch(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'firstsecond');
        rewind($stream);
        $result = $store->write_chunks('artifact-1', [
            [
                'offset' => 0,
                'length' => 5,
                'expected_crc32' => hash('crc32b', 'first'),
                'source' => $stream,
            ],
            [
                'offset' => 5,
                'length' => 6,
                'expected_crc32' => hash('crc32b', 'second'),
                'source' => $stream,
            ],
        ]);
        fclose($stream);

        $this->assertSame('accepted', $result['status']);
        $verified = $store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'));
        $this->assertSame('firstsecond', file_get_contents($verified['path']));
    }

    public function testSwitchingArtifactsRestartsTheAbandonedOne(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-a', 0, 'first');

        // Sequential transfers: chunk 0 of another artifact moves the cursor.
        $this->assertSame('accepted', $this->writeString($store, 'artifact-b', 0, 'bee')['status']);

        $stale = $this->writeString($store, 'artifact-a', 5, 'second');
        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$stale['status'], $stale['reason'], $stale['committed_bytes']]
        );
        $this->assertSame('accepted', $this->writeString($store, 'artifact-a', 0, 'fresh')['status']);
    }

    public function testMidBatchRejectionKeepsEarlierChunksCommitted(): void
    {
        $store = $this->makeStore();

        $result = $store->write_chunks('artifact-1', [
            $this->chunk(0, 'first'),
            [
                'offset' => 5,
                'length' => 6,
                'expected_crc32' => hash('crc32b', 'other!'),
                'source' => 'second',
            ],
        ]);

        $this->assertSame(
            ['rejected', 'hash_mismatch', 'chunk_body', 5],
            [$result['status'], $result['reason'], $result['detail'], $result['committed_bytes']]
        );

        // The sender resumes from committed_bytes instead of restarting.
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
        $verified = $store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'));
        $this->assertSame('firstsecond', file_get_contents($verified['path']));
    }

    public function testDuplicateDrainOfTruncatedStreamIsRejected(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'fir'); // Three of the duplicate chunk's five bytes.
        rewind($stream);
        $result = $store->write_chunks('artifact-1', [
            [
                'offset' => 0,
                'length' => 5,
                'expected_crc32' => hash('crc32b', 'first'),
                'source' => $stream,
            ],
        ]);
        fclose($stream);

        $this->assertSame(
            ['rejected', 'short_body', 'duplicate_drain', 5],
            [$result['status'], $result['reason'], $result['detail'], $result['committed_bytes']]
        );
    }

    public function testGeneratorFailureMidBatchReleasesTheLockAndKeepsProgress(): void
    {
        $store = $this->makeStore();
        $chunks = (function () {
            yield $this->chunk(0, 'first');
            throw new DomainException('endpoint failed to parse the next chunk');
        })();

        try {
            $store->write_chunks('artifact-1', $chunks);
            $threw = false;
        } catch (DomainException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The generator exception should propagate to the endpoint.');
        $this->assertSame(5, $store->status('artifact-1')['committed_bytes']);
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
    }

    public function testOffsetGapIsRejectedWithCommittedBytesForResync(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $result = $this->writeString($store, 'artifact-1', 20, 'too-far');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('offset_gap', $result['reason']);
        $this->assertSame(5, $result['committed_bytes']);
    }

    // ---------------------------------------------------------------
    // Integrity
    // ---------------------------------------------------------------

    public function testChunkHashMismatchLeavesCommittedBytesUntouched(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $result = $store->write_chunk('artifact-1', 5, 6, hash('crc32b', 'other!'), 'second');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('hash_mismatch', $result['reason']);
        $this->assertSame(5, $result['committed_bytes']);

        // The correct retry still lands at the same offset.
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
        $store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'));
        $this->assertSame(
            'firstsecond',
            file_get_contents($store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'))['path'])
        );
    }

    public function testUncommittedTailFromACrashedWriteIsDiscarded(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'committed');

        // Simulate a crash after data was written but before the cursor
        // moved: garbage sits beyond committed_bytes in the file.
        file_put_contents($this->staging_dir . '/files/artifact-1', 'GARBAGE', FILE_APPEND);

        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 9, '-tail')['status']);
        $body = 'committed-tail';
        $result = $store->finalize('artifact-1', strlen($body), hash('crc32b', $body));
        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($result['path']));
    }

    public function testExternallyShrunkenStagingFileFailsTheArtifactChecksum(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        // Simulate the staging file drifting between requests: something
        // shrinks it below the committed size.
        file_put_contents($this->staging_dir . '/files/artifact-1', 'fi');

        // The next write zero-fills back to the committed size and appends;
        // only finalize's whole-artifact checksum can see the damage.
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);

        $result = $store->finalize('artifact-1', 11, hash('crc32b', 'firstsecond'));
        $this->assertSame(
            ['rejected', 'hash_mismatch', 'artifact_crc32'],
            [$result['status'], $result['reason'], $result['detail']]
        );
        $this->assertFalse($store->status('artifact-1')['verified']);
    }

    public function testCorruptCommitRecordRestartsTheArtifact(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        file_put_contents($this->staging_dir . '/state.json', 'not json {');

        $this->assertSame(0, $store->status('artifact-1')['committed_bytes']);
        $resumed = $this->writeString($store, 'artifact-1', 5, 'second');
        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$resumed['status'], $resumed['reason'], $resumed['committed_bytes']]
        );
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 0, 'fresh')['status']);
    }

    public function testFinalizeRejectsWrongSizeAndWrongHash(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');

        $wrong_size = $store->finalize('artifact-1', 99, hash('crc32b', 'payload'));
        $this->assertSame(['rejected', 'size_mismatch'], [$wrong_size['status'], $wrong_size['reason']]);

        $wrong_hash = $store->finalize('artifact-1', 7, hash('crc32b', 'tampered'));
        $this->assertSame(['rejected', 'hash_mismatch'], [$wrong_hash['status'], $wrong_hash['reason']]);

        $this->assertFalse($store->status('artifact-1')['verified']);
    }

    public function testShortStreamBodyIsRejected(): void
    {
        $store = $this->makeStore();

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'only-9b!!');
        rewind($stream);
        $result = $store->write_chunk('artifact-1', 0, 100, hash('crc32b', 'whatever'), $stream);
        fclose($stream);

        $this->assertSame(['rejected', 'short_body', 0], [$result['status'], $result['reason'], $result['committed_bytes']]);
    }

    public function testStringLengthMismatchIsRejected(): void
    {
        $store = $this->makeStore();

        $result = $store->write_chunk('artifact-1', 0, 3, hash('crc32b', 'abcdef'), 'abcdef');

        $this->assertSame(['rejected', 'length_mismatch'], [$result['status'], $result['reason']]);
    }

    public function testWritingToAVerifiedArtifactIsRejected(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');
        $store->finalize('artifact-1', 7, hash('crc32b', 'payload'));

        $result = $this->writeString($store, 'artifact-1', 7, 'more');

        $this->assertSame(['rejected', 'already_verified'], [$result['status'], $result['reason']]);
    }

    // ---------------------------------------------------------------
    // Input validation
    // ---------------------------------------------------------------

    public function testMalformedHashAndLengthAreRejected(): void
    {
        $store = $this->makeStore();

        $bad_hash = $store->write_chunk('artifact-1', 0, 5, 'not-a-crc32', 'bytes');
        $this->assertSame(['rejected', 'invalid_hash'], [$bad_hash['status'], $bad_hash['reason']]);

        $bad_length = $store->write_chunk('artifact-1', 0, 0, hash('crc32b', ''), '');
        $this->assertSame(['rejected', 'invalid_length'], [$bad_length['status'], $bad_length['reason']]);

        $bad_offset = $store->write_chunk('artifact-1', -1, 5, hash('crc32b', 'bytes'), 'bytes');
        $this->assertSame(['rejected', 'invalid_offset'], [$bad_offset['status'], $bad_offset['reason']]);

        $bad_source = $store->write_chunk('artifact-1', 0, 5, hash('crc32b', 'bytes'), 42);
        $this->assertSame(['rejected', 'invalid_source'], [$bad_source['status'], $bad_source['reason']]);
    }

    public function testUppercaseChecksumsAreAccepted(): void
    {
        $store = $this->makeStore();

        $result = $store->write_chunk('artifact-1', 0, 5, strtoupper(hash('crc32b', 'bytes')), 'bytes');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(
            'verified',
            $store->finalize('artifact-1', 5, strtoupper(hash('crc32b', 'bytes')))['status']
        );
    }

    public function testEmptyBatchIsRejectedWithoutDisturbingCommittedBytes(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        $result = $store->write_chunks('artifact-1', []);

        $this->assertSame(
            ['rejected', 'empty_batch', 5],
            [$result['status'], $result['reason'], $result['committed_bytes']]
        );
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
    }

    public function testStagingMirrorsTheArtifactPath(): void
    {
        $store = $this->makeStore();
        $id = 'wp-content/themes/foo/style.css';

        $this->assertSame('accepted', $this->writeString($store, $id, 0, 'body { }')['status']);

        $this->assertFileExists($this->staging_dir . '/files/' . $id);
        $this->assertFileExists($this->staging_dir . '/state.json');
    }

    public function testSiteFilenamesThatLookLikeStagingRecordsStageVerbatim(): void
    {
        $store = $this->makeStore();

        foreach (['index.php', 'index.php.part', 'index.php.meta.json', 'state.json'] as $id) {
            $this->assertSame('accepted', $this->writeString($store, $id, 0, "body of {$id}")['status']);
            $verified = $store->finalize($id, strlen("body of {$id}"), hash('crc32b', "body of {$id}"));
            $this->assertSame('verified', $verified['status'], "id: {$id}");
            $this->assertSame("body of {$id}", file_get_contents($this->staging_dir . '/files/' . $id));
        }
    }

    public function testIdsOutsideTheStagingDirAreRejected(): void
    {
        $store = $this->makeStore();

        foreach (['../../outside/etc/passwd', '/etc/passwd', 'a/../b', 'a//b', './a', '', 'a\\b', "a\0b"] as $hostile_id) {
            $result = $this->writeString($store, $hostile_id, 0, 'bytes');
            $this->assertSame(
                ['rejected', 'invalid_artifact_id'],
                [$result['status'], $result['reason']],
                "id: {$hostile_id}"
            );
            $finalized = $store->finalize($hostile_id, 5, hash('crc32b', 'bytes'));
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
        $this->writeString($store, 'artifact-1', 0, 'first');

        // Hold the lock the way a concurrent writer would.
        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);

        $busy_write = $this->writeString($store, 'artifact-1', 5, 'second');
        $this->assertSame(['busy', 5], [$busy_write['status'], $busy_write['committed_bytes']]);
        $busy_finalize = $store->finalize('artifact-1', 5, hash('crc32b', 'first'));
        $this->assertSame(['busy', 5], [$busy_finalize['status'], $busy_finalize['committed_bytes']]);
        $this->assertFalse($store->discard('artifact-1'));

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
    }

    public function testDiscardRemovesAllStagedData(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');

        $this->assertTrue($store->discard('artifact-1'));

        $status = $store->status('artifact-1');
        $this->assertSame(['exists' => false, 'committed_bytes' => 0, 'verified' => false], $status);
        $this->assertTrue($store->discard('never-existed'));
    }

    public function testDiscardedArtifactRestartsFromScratch(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');
        $store->discard('artifact-1');

        $stale = $this->writeString($store, 'artifact-1', 5, 'second');

        $this->assertSame(
            ['rejected', 'offset_gap', 0],
            [$stale['status'], $stale['reason'], $stale['committed_bytes']]
        );
        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 0, 'fresh')['status']);
    }

    public function testDiscardClearsTheCursorWhenTheArtifactFileIsMissing(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'payload');
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
