<?php

use PHPUnit\Framework\TestCase;

final class StagedEndpointsTest extends TestCase {

    private const SECRET = 'staged-endpoints-test-secret';

    private string $staging_dir;

    protected function setUp(): void
    {
        $this->staging_dir = sys_get_temp_dir() . '/staged-endpoints-test-' . bin2hex(random_bytes(8));
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

    private function makeEndpoints(array $overrides = []): Site_Export_Staged_Endpoints
    {
        return new Site_Export_Staged_Endpoints(array_merge([
            'staging_dir' => $this->staging_dir,
            'secret' => self::SECRET,
        ], $overrides));
    }

    /**
     * Headers the way $_SERVER presents them, signed like the HMAC client:
     * HMAC-SHA256(nonce . timestamp . SHA256(body), secret).
     */
    private function signedHeaders(string $body, array $overrides = []): array
    {
        $nonce = $overrides['nonce'] ?? bin2hex(random_bytes(16));
        $timestamp = $overrides['timestamp'] ?? (string) time();
        $content_hash = $overrides['content_hash'] ?? hash('sha256', $body);
        $signature = hash_hmac(
            'sha256',
            $nonce . $timestamp . $content_hash,
            $overrides['secret'] ?? self::SECRET
        );

        return [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_AUTH_SIGNATURE' => $overrides['signature'] ?? $signature,
            'HTTP_X_AUTH_NONCE' => $nonce,
            'HTTP_X_AUTH_TIMESTAMP' => $timestamp,
            'HTTP_X_AUTH_CONTENT_HASH' => $content_hash,
        ];
    }

    /** @return resource */
    private function bodyStream(string $body)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        return $stream;
    }

    private function upload(
        Site_Export_Staged_Endpoints $endpoints,
        string $artifact_id,
        int $offset,
        string $body,
        array $header_overrides = []
    ): array {
        $stream = $this->bodyStream($body);
        try {
            return $endpoints->upload(
                ['artifact_id' => $artifact_id, 'offset' => $offset],
                $this->signedHeaders($body, $header_overrides),
                $stream
            );
        } finally {
            fclose($stream);
        }
    }

    private function finalize(Site_Export_Staged_Endpoints $endpoints, string $artifact_id, int $total): array
    {
        return $endpoints->finalize(
            ['artifact_id' => $artifact_id, 'total_bytes' => $total],
            ['REQUEST_METHOD' => 'POST']
        );
    }

    // ---------------------------------------------------------------
    // Upload data plane
    // ---------------------------------------------------------------

    public function testChunksStageAndFinalizeVerifies(): void
    {
        // A small append buffer forces many store steps per chunk.
        $endpoints = $this->makeEndpoints(['append_buffer_bytes' => 4]);
        $body = 'the quick brown fox jumps over the lazy dog';
        $split = 20;

        $first = $this->upload($endpoints, 'a/b/dump.sql', 0, substr($body, 0, $split));
        $this->assertSame(200, $first['http_code']);
        $this->assertSame('accepted', $first['body']['status']);
        $this->assertSame($split, $first['body']['committed_bytes']);

        $second = $this->upload($endpoints, 'a/b/dump.sql', $split, substr($body, $split));
        $this->assertSame(strlen($body), $second['body']['committed_bytes']);

        $verified = $this->finalize($endpoints, 'a/b/dump.sql', strlen($body));
        $this->assertSame(200, $verified['http_code']);
        $this->assertSame('verified', $verified['body']['status']);
        $this->assertArrayNotHasKey('path', $verified['body']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/a/b/dump.sql'));
    }

    public function testRetriedChunkLandsAsDuplicate(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'abcdefghij');

        // The sender timed out without the response and retries the chunk.
        $retry = $this->upload($endpoints, 'artifact-1', 0, 'abcdefghij');

        $this->assertSame(200, $retry['http_code']);
        $this->assertSame('duplicate', $retry['body']['status']);
        $this->assertSame(10, $retry['body']['committed_bytes']);
    }

    public function testResentChunkStraddlingTheFrontierAppendsOnlyTheTail(): void
    {
        $endpoints = $this->makeEndpoints(['append_buffer_bytes' => 4]);
        $this->upload($endpoints, 'artifact-1', 0, 'abcdefghij');

        // After a resync the sender resends from offset 0 with a larger
        // chunk; only the bytes past the committed frontier may land.
        $result = $this->upload($endpoints, 'artifact-1', 0, 'abcdefghijKLMNO');

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('accepted', $result['body']['status']);
        $this->assertSame(15, $result['body']['committed_bytes']);
        $this->assertSame(
            'abcdefghijKLMNO',
            file_get_contents($this->staging_dir . '/files/artifact-1')
        );
    }

    public function testChunkBeyondTheFrontierIsAnOffsetGapWithResumeHint(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'abcde');

        $result = $this->upload($endpoints, 'artifact-1', 50, 'later-bytes');

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('offset_gap', $result['body']['reason']);
        $this->assertSame(5, $result['body']['committed_bytes']);
    }

    public function testUploadToAVerifiedArtifactIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'payload');
        $this->finalize($endpoints, 'artifact-1', 7);

        $result = $this->upload($endpoints, 'artifact-1', 7, 'more');

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('already_verified', $result['body']['reason']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();

        $result = $this->upload($endpoints, 'artifact-1', 0, '');

        $this->assertSame(400, $result['http_code']);
        $this->assertSame('empty_body', $result['body']['reason']);
    }

    public function testUploadWhileTheStoreIsHeldReportsBusy(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'first');

        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);
        $result = $this->upload($endpoints, 'artifact-1', 5, 'second');
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertSame(423, $result['http_code']);
        $this->assertSame('busy', $result['body']['status']);
        $this->assertSame(5, $result['body']['committed_bytes']);
    }

    // ---------------------------------------------------------------
    // Upload authentication: no unverified byte reaches the store
    // ---------------------------------------------------------------

    public function testUploadWithoutAuthHeadersIsRejectedBeforeTheStore(): void
    {
        $endpoints = $this->makeEndpoints();
        $stream = $this->bodyStream('payload');

        $result = $endpoints->upload(
            ['artifact_id' => 'artifact-1', 'offset' => 0],
            ['REQUEST_METHOD' => 'POST'],
            $stream
        );
        fclose($stream);

        $this->assertSame(403, $result['http_code']);
        $this->assertSame('auth_failed', $result['body']['reason']);
        $this->assertStringContainsString('X-Auth-Signature', $result['body']['detail']);
        $this->assertDirectoryDoesNotExist($this->staging_dir);
    }

    public function testUploadWithWrongSignatureIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();

        $result = $this->upload($endpoints, 'artifact-1', 0, 'payload', [
            'signature' => str_repeat('0', 64),
        ]);

        $this->assertSame(403, $result['http_code']);
        $this->assertSame('auth_failed', $result['body']['reason']);
        $this->assertDirectoryDoesNotExist($this->staging_dir);
    }

    public function testUploadWithExpiredTimestampIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();

        $result = $this->upload($endpoints, 'artifact-1', 0, 'payload', [
            'timestamp' => (string) ( time() - 3600 ),
        ]);

        $this->assertSame(403, $result['http_code']);
        $this->assertSame('auth_failed', $result['body']['reason']);
    }

    public function testBodyNotMatchingTheSignedHashNeverReachesTheStore(): void
    {
        $endpoints = $this->makeEndpoints();

        // Valid signature over the hash of different bytes: the headers
        // authenticate, the body does not.
        $result = $this->upload($endpoints, 'artifact-1', 0, 'actually-sent-bytes', [
            'content_hash' => hash('sha256', 'the-bytes-that-were-signed'),
        ]);

        $this->assertSame(403, $result['http_code']);
        $this->assertSame('content_hash_mismatch', $result['body']['reason']);
        $this->assertFileDoesNotExist($this->staging_dir . '/files/artifact-1');
        $this->assertSame(0, $endpoints->status(['artifact_id' => 'artifact-1'])['body']['committed_bytes']);
    }

    public function testUploadWithoutAConfiguredSecretIsUnavailable(): void
    {
        $endpoints = $this->makeEndpoints(['secret' => null]);

        $result = $this->upload($endpoints, 'artifact-1', 0, 'payload');

        $this->assertSame(503, $result['http_code']);
        $this->assertSame('not_configured', $result['body']['reason']);
    }

    // ---------------------------------------------------------------
    // Request-size limits: the chunk sizer's 413 contract
    // ---------------------------------------------------------------

    public function testBodyOverTheCapIs413WithMaxRequestBytes(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 64]);

        $result = $this->upload($endpoints, 'artifact-1', 0, str_repeat('x', 100));

        $this->assertSame(413, $result['http_code']);
        $this->assertSame('request_too_large', $result['body']['reason']);
        $this->assertSame(64, $result['body']['max_request_bytes']);
        $this->assertFileDoesNotExist($this->staging_dir . '/files/artifact-1');
    }

    public function testDeclaredContentLengthOverTheCapIs413BeforeReading(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 64]);

        // An empty stream stands in for the body: the declared length alone
        // must trigger the rejection, before any read happens.
        $stream = $this->bodyStream('');
        $headers = $this->signedHeaders('');
        $headers['CONTENT_LENGTH'] = '5000';
        $result = $endpoints->upload(
            ['artifact_id' => 'artifact-1', 'offset' => 0],
            $headers,
            $stream
        );
        fclose($stream);

        $this->assertSame(413, $result['http_code']);
        $this->assertSame('request_too_large', $result['body']['reason']);
        $this->assertSame(64, $result['body']['max_request_bytes']);
    }

    // ---------------------------------------------------------------
    // Control plane: finalize, status, discard
    // ---------------------------------------------------------------

    public function testFinalizeWithWrongTotalIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'payload');

        $result = $this->finalize($endpoints, 'artifact-1', 99);

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('size_mismatch', $result['body']['reason']);
    }

    public function testFinalizeOfUnknownArtifactReportsMissing(): void
    {
        $endpoints = $this->makeEndpoints();

        $result = $this->finalize($endpoints, 'never-uploaded', 5);

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('missing', $result['body']['reason']);
    }

    public function testFinalizeIsIdempotentAndZeroByteArtifactsVerify(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'payload');
        $this->finalize($endpoints, 'artifact-1', 7);

        $this->assertSame(200, $this->finalize($endpoints, 'artifact-1', 7)['http_code']);
        $this->assertSame('verified', $this->finalize($endpoints, 'empty.txt', 0)['body']['status']);
    }

    public function testStatusReportsResumeState(): void
    {
        $endpoints = $this->makeEndpoints();

        $unknown = $endpoints->status(['artifact_id' => 'artifact-1']);
        $this->assertSame(200, $unknown['http_code']);
        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            $unknown['body']
        );

        $this->upload($endpoints, 'artifact-1', 0, 'abcde');
        $known = $endpoints->status(['artifact_id' => 'artifact-1']);
        $this->assertSame(
            ['exists' => true, 'committed_bytes' => 5, 'verified' => false],
            $known['body']
        );
    }

    public function testDiscardReportsHeldStoreAsRetriable(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->upload($endpoints, 'artifact-1', 0, 'payload');

        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);
        $busy = $endpoints->discard(
            ['artifact_id' => 'artifact-1'],
            ['REQUEST_METHOD' => 'POST']
        );
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertSame(423, $busy['http_code']);
        $this->assertSame(['discarded' => false], $busy['body']);

        $done = $endpoints->discard(
            ['artifact_id' => 'artifact-1'],
            ['REQUEST_METHOD' => 'POST']
        );
        $this->assertSame(200, $done['http_code']);
        $this->assertSame(['discarded' => true], $done['body']);
    }

    // ---------------------------------------------------------------
    // Apply route
    // ---------------------------------------------------------------

    public function testApplyRouteProbesThenMovesAVerifiedTransfer(): void
    {
        $target_root = $this->staging_dir . '-target';
        mkdir($target_root, 0700, true);
        try {
            $endpoints = $this->makeEndpoints(['apply_target_root' => $target_root]);
            $this->upload($endpoints, 'wp-content/a.txt', 0, 'applied!');
            $this->finalize($endpoints, 'wp-content/a.txt', 8);
            $manifest = json_encode(['artifact_id' => 'wp-content/a.txt', 'size' => 8]) . "\n";
            $this->upload($endpoints, 'm.jsonl', 0, $manifest);
            $this->finalize($endpoints, 'm.jsonl', strlen($manifest));

            $probe = $endpoints->apply(
                ['manifest_id' => 'm.jsonl', 'check_only' => '1'],
                ['REQUEST_METHOD' => 'POST']
            );
            $this->assertSame([200, 'ready'], [$probe['http_code'], $probe['body']['status']]);
            $this->assertFileDoesNotExist($target_root . '/wp-content/a.txt');

            $result = $endpoints->apply(['manifest_id' => 'm.jsonl'], ['REQUEST_METHOD' => 'POST']);
            $this->assertSame(
                [200, 'applied', 1],
                [$result['http_code'], $result['body']['status'], $result['body']['applied']]
            );
            $this->assertSame('applied!', file_get_contents($target_root . '/wp-content/a.txt'));
        } finally {
            $this->removeDir($target_root);
        }
    }

    public function testApplyRouteReportsCrossDeviceOnTheProbe(): void
    {
        $target_root = $this->staging_dir . '-target';
        mkdir($target_root, 0700, true);
        try {
            $endpoints = $this->makeEndpoints([
                'apply_target_root' => $target_root,
                'apply_device_id' => function (string $path): ?int {
                    return strpos($path, '-target') !== false ? 22 : 11;
                },
            ]);

            // No transfer exists yet — the environment verdict alone must
            // reject, so a sender learns before uploading anything.
            $probe = $endpoints->apply(
                ['manifest_id' => 'not-yet-staged', 'check_only' => '1'],
                ['REQUEST_METHOD' => 'POST']
            );

            $this->assertSame(409, $probe['http_code']);
            $this->assertSame('cross_device', $probe['body']['reason']);
        } finally {
            $this->removeDir($target_root);
        }
    }

    public function testApplyRouteValidatesConfigurationAndMethod(): void
    {
        $unconfigured = $this->makeEndpoints();
        $missing_root = $unconfigured->apply(['manifest_id' => 'm'], ['REQUEST_METHOD' => 'POST']);
        $this->assertSame(
            [503, 'not_configured', 'apply_target_root'],
            [$missing_root['http_code'], $missing_root['body']['reason'], $missing_root['body']['detail']]
        );

        $configured = $this->makeEndpoints(['apply_target_root' => sys_get_temp_dir()]);
        $get = $configured->apply(['manifest_id' => 'm'], ['REQUEST_METHOD' => 'GET']);
        $this->assertSame(405, $get['http_code']);

        $no_manifest = $configured->apply([], ['REQUEST_METHOD' => 'POST']);
        $this->assertSame([400, 'invalid_manifest_id'], [$no_manifest['http_code'], $no_manifest['body']['reason']]);
    }

    // ---------------------------------------------------------------
    // Request validation and server-owned options
    // ---------------------------------------------------------------

    public function testMutatingRoutesRequirePost(): void
    {
        $endpoints = $this->makeEndpoints();
        $get = ['REQUEST_METHOD' => 'GET'];

        $upload = $endpoints->upload(['artifact_id' => 'a', 'offset' => 0], $get, null);
        $finalize = $endpoints->finalize(['artifact_id' => 'a', 'total_bytes' => 1], $get);
        $discard = $endpoints->discard(['artifact_id' => 'a'], $get);

        foreach ([$upload, $finalize, $discard] as $result) {
            $this->assertSame(405, $result['http_code']);
            $this->assertSame('method_not_allowed', $result['body']['reason']);
        }
    }

    public function testMalformedParametersAreRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $post = ['REQUEST_METHOD' => 'POST'];

        $no_id = $endpoints->upload(['offset' => 0], $post, null);
        $this->assertSame([400, 'invalid_artifact_id'], [$no_id['http_code'], $no_id['body']['reason']]);

        $bad_offset = $endpoints->upload(['artifact_id' => 'a', 'offset' => -1], $post, null);
        $this->assertSame('invalid_offset', $bad_offset['body']['reason']);

        $bad_total = $endpoints->finalize(['artifact_id' => 'a', 'total_bytes' => 'many'], $post);
        $this->assertSame('invalid_total', $bad_total['body']['reason']);

        $bad_status_id = $endpoints->status(['artifact_id' => '']);
        $this->assertSame(400, $bad_status_id['http_code']);
    }

    public function testClientParametersCannotChooseServerOptions(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 1024]);
        $evil_dir = $this->staging_dir . '-evil';
        $body = str_repeat('x', 100);

        $stream = $this->bodyStream($body);
        $result = $endpoints->upload(
            [
                'artifact_id' => 'artifact-1',
                'offset' => 0,
                // Options are server-owned; parameters with the same names
                // must be ignored.
                'staging_dir' => $evil_dir,
                'max_request_bytes' => 10,
                'secret' => 'attacker-chosen',
            ],
            $this->signedHeaders($body),
            $stream
        );
        fclose($stream);

        $this->assertSame(200, $result['http_code']);
        $this->assertFileExists($this->staging_dir . '/files/artifact-1');
        $this->assertDirectoryDoesNotExist($evil_dir);
    }

    // ---------------------------------------------------------------
    // Dispatcher wiring
    // ---------------------------------------------------------------

    public function testHttpServerRoutesStagedEndpoints(): void
    {
        $server = new Site_Export_HTTP_Server([
            'staged' => ['staging_dir' => $this->staging_dir, 'secret' => self::SECRET],
        ]);

        ob_start();
        $server->handle_request([
            'get' => ['endpoint' => 'staged_status', 'artifact_id' => 'artifact-1'],
            'server' => ['REQUEST_METHOD' => 'GET'],
            'body' => '',
        ]);
        $output = ob_get_clean();

        $this->assertSame(
            ['exists' => false, 'committed_bytes' => 0, 'verified' => false],
            json_decode( (string) $output, true)
        );
    }

    public function testStagedRoutesAreAbsentWithoutTheStagedOption(): void
    {
        $server = new Site_Export_HTTP_Server();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid endpoint: 'staged_status'");

        $server->dispatch(['endpoint' => 'staged_status']);
    }

    public function testExplicitHandlersWinOverStagedRegistration(): void
    {
        $called = false;
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'staged_status' => function (array $config) use (&$called): void {
                    $called = true;
                },
            ],
            'staged' => ['staging_dir' => $this->staging_dir, 'secret' => self::SECRET],
        ]);

        $server->dispatch(['endpoint' => 'staged_status']);

        $this->assertTrue($called);
    }

    public function testHandleRequestOnlyReadsTheBodyForJsonContent(): void
    {
        $reads = 0;
        $options = [
            'handlers' => ['preflight' => static function (array $config): void {}],
            'body_reader' => function () use (&$reads): string {
                ++$reads;
                return '';
            },
        ];
        $server = new Site_Export_HTTP_Server($options);

        $server->handle_request([
            'get' => ['endpoint' => 'preflight'],
            'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/octet-stream'],
        ]);
        $this->assertSame(0, $reads, 'a raw body must not be buffered for config parsing');

        $server->handle_request([
            'get' => ['endpoint' => 'preflight'],
            'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json; charset=utf-8'],
        ]);
        $this->assertSame(1, $reads, 'a JSON body still feeds config parsing');
    }
}
