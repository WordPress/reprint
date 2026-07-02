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

        // Simulate a crash after data was written but before the commit
        // record moved: garbage sits beyond committed_bytes in the file.
        $part_path = $this->staging_dir . '/artifact-1.part';
        file_put_contents($part_path, 'GARBAGE', FILE_APPEND);

        $this->assertSame('accepted', $this->writeString($store, 'artifact-1', 9, '-tail')['status']);
        $body = 'committed-tail';
        $result = $store->finalize('artifact-1', strlen($body), hash('crc32b', $body));
        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($result['path']));
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
    }

    public function testStagingMirrorsTheArtifactPath(): void
    {
        $store = $this->makeStore();
        $id = 'wp-content/themes/foo/style.css';

        $this->assertSame('accepted', $this->writeString($store, $id, 0, 'body { }')['status']);

        $this->assertFileExists($this->staging_dir . '/' . $id . '.part');
        $this->assertFileExists($this->staging_dir . '/' . $id . '.meta.json');
    }

    public function testIdsOutsideTheStagingDirAreRejected(): void
    {
        $store = $this->makeStore();

        foreach (['../../outside/etc/passwd', '/etc/passwd', 'a/../b', 'a//b', './a', ''] as $hostile_id) {
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

        $this->assertDirectoryDoesNotExist(dirname($this->staging_dir) . '/outside');
        $this->assertFileDoesNotExist('/etc/passwd.part');
    }

    // ---------------------------------------------------------------
    // Concurrency and lifecycle
    // ---------------------------------------------------------------

    public function testConcurrentWriterGetsBusy(): void
    {
        $store = $this->makeStore();
        $this->writeString($store, 'artifact-1', 0, 'first');

        // Hold the lock the way a concurrent writer would.
        $part_path = $this->staging_dir . '/artifact-1.part';
        $holder = fopen($part_path, 'r+b');
        flock($holder, LOCK_EX);

        $this->assertSame('busy', $this->writeString($store, 'artifact-1', 5, 'second')['status']);
        $this->assertSame('busy', $store->finalize('artifact-1', 5, hash('crc32b', 'first'))['status']);
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

    public function testStatusOfUnknownArtifact(): void
    {
        $store = $this->makeStore();

        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            $store->status('unknown')
        );
    }
}
