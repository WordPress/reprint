<?php

use PHPUnit\Framework\TestCase;

final class StagedEndpointsTest extends TestCase {

    private const SECRET = 'staged-endpoints-test-secret';
    private const PUSH_TARGET = '/?endpoint=staged_push';

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

    /** @return resource */
    private function bodyStream(string $body)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        return $stream;
    }

    private function pushHeaders(string $secret = self::SECRET, array $overrides = []): array
    {
        $headers = (new Site_Export_HMAC_Client($secret))->get_envelope_auth_headers('POST', self::PUSH_TARGET);
        return array_merge([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => self::PUSH_TARGET,
        ], $headers, $overrides);
    }

    /** @param array<int,array{artifact_id:string,offset:int,bytes:string,total_bytes:int,final:bool}> $frames */
    private function pushBody(array $frames): string
    {
        $body = '';
        foreach ($frames as $frame) {
            $header = json_encode([
                'type' => 'chunk',
                'artifact_id' => base64_encode($frame['artifact_id']),
                'offset' => $frame['offset'],
                'bytes' => strlen($frame['bytes']),
                'total_bytes' => $frame['total_bytes'],
                'final' => $frame['final'],
            ], JSON_UNESCAPED_SLASHES);
            if ($header === false) {
                throw new RuntimeException('Could not encode staged push stream frame header.');
            }
            $body .= $header . "\n" . $frame['bytes'];
        }
        return $body;
    }

    /** @param array<int,array{artifact_id:string,offset:int,bytes:string,total_bytes:int,final:bool}> $frames */
    private function push(
        Site_Export_Staged_Endpoints $endpoints,
        array $frames,
        array $headers = [],
        array $config = []
    ): array {
        $stream = $this->bodyStream($this->pushBody($frames));
        try {
            return $endpoints->push_stream($config, $headers ?: $this->pushHeaders(), $stream);
        } finally {
            fclose($stream);
        }
    }

    // ---------------------------------------------------------------
    // Push data plane
    // ---------------------------------------------------------------

    public function testPushStreamStagesManyChunksAndFinalizes(): void
    {
        // A small append buffer forces many store steps per frame.
        $endpoints = $this->makeEndpoints(['append_buffer_bytes' => 4]);
        $body = 'the quick brown fox jumps over the lazy dog';
        $split = 20;

        $result = $this->push($endpoints, [
            ['artifact_id' => 'a/b/dump.sql', 'offset' => 0, 'bytes' => substr($body, 0, $split), 'total_bytes' => strlen($body), 'final' => false],
            ['artifact_id' => 'a/b/dump.sql', 'offset' => $split, 'bytes' => substr($body, $split), 'total_bytes' => strlen($body), 'final' => true],
        ]);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('complete', $result['body']['status']);
        $this->assertSame(['artifact_id' => base64_encode('a/b/dump.sql'), 'committed_bytes' => strlen($body)], $result['body']['cursor']);
        $this->assertSame(1, $result['body']['files_verified']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/a/b/dump.sql'));
    }

    public function testPushStreamRetryAbsorbsDuplicateBytes(): void
    {
        $endpoints = $this->makeEndpoints();
        $frame = ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'abcdefghij', 'total_bytes' => 10, 'final' => true];
        $this->push($endpoints, [$frame]);

        // The sender timed out without the response and retries from the same cursor.
        $retry = $this->push($endpoints, [$frame]);

        $this->assertSame(200, $retry['http_code']);
        $this->assertSame('complete', $retry['body']['status']);
        $this->assertSame(['artifact_id' => base64_encode('artifact-1'), 'committed_bytes' => 10], $retry['body']['cursor']);
        $this->assertSame(1, $retry['body']['files_verified']);
        $this->assertSame('abcdefghij', file_get_contents($this->staging_dir . '/files/artifact-1'));
    }

    public function testPushStreamStraddlingFrameAppendsOnlyTheTail(): void
    {
        $endpoints = $this->makeEndpoints(['append_buffer_bytes' => 4]);
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'abcdefghij', 'total_bytes' => 15, 'final' => false],
        ]);

        // After a resync the sender resends from offset 0 with a larger
        // frame; only the bytes past the committed frontier may land.
        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'abcdefghijKLMNO', 'total_bytes' => 15, 'final' => true],
        ]);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame(['artifact_id' => base64_encode('artifact-1'), 'committed_bytes' => 15], $result['body']['cursor']);
        $this->assertSame('abcdefghijKLMNO', file_get_contents($this->staging_dir . '/files/artifact-1'));
    }

    public function testPushStreamFrameBeyondTheFrontierIsAnOffsetGapWithResumeHint(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'abcde', 'total_bytes' => 20, 'final' => false],
        ]);

        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 50, 'bytes' => 'later-bytes', 'total_bytes' => 100, 'final' => false],
        ]);

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('offset_gap', $result['body']['reason']);
        $this->assertSame(['artifact_id' => base64_encode('artifact-1'), 'committed_bytes' => 5], $result['body']['cursor']);
    }

    public function testPushStreamRejectsWrongSecretBeforeTheStore(): void
    {
        $endpoints = $this->makeEndpoints();

        $result = $this->push($endpoints, [
            ['artifact_id' => 'secret.bin', 'offset' => 0, 'bytes' => 'secret', 'total_bytes' => 6, 'final' => true],
        ], $this->pushHeaders('wrong-secret'));

        $this->assertSame(403, $result['http_code']);
        $this->assertSame('auth_failed', $result['body']['reason']);
        $this->assertDirectoryDoesNotExist($this->staging_dir);
    }

    public function testPushStreamWithoutAConfiguredSecretIsUnavailable(): void
    {
        $endpoints = $this->makeEndpoints(['secret' => null]);

        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'payload', 'total_bytes' => 7, 'final' => true],
        ]);

        $this->assertSame(503, $result['http_code']);
        $this->assertSame('not_configured', $result['body']['reason']);
    }

    public function testPushStreamBodyOverTheCapIs413WithMaxFrameBytes(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 64]);

        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => str_repeat('x', 100), 'total_bytes' => 100, 'final' => true],
        ]);

        $this->assertSame(413, $result['http_code']);
        $this->assertSame('frame_too_large', $result['body']['reason']);
        $this->assertSame(64, $result['body']['max_frame_bytes']);
        $this->assertFileDoesNotExist($this->staging_dir . '/files/artifact-1');
    }

    public function testPushStreamWhileTheStoreIsHeldReportsBusy(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'first', 'total_bytes' => 11, 'final' => false],
        ]);

        $holder = fopen($this->staging_dir . '/lock', 'r+b');
        flock($holder, LOCK_EX);
        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 5, 'bytes' => 'second', 'total_bytes' => 11, 'final' => true],
        ]);
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertSame(423, $result['http_code']);
        $this->assertSame('busy', $result['body']['status']);
        $this->assertSame(['artifact_id' => base64_encode('artifact-1'), 'committed_bytes' => 5], $result['body']['cursor']);
    }

    public function testMalformedPushFrameIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $stream = $this->bodyStream(json_encode(['type' => 'chunk', 'artifact_id' => base64_encode('a'), 'offset' => 5, 'bytes' => 1, 'total_bytes' => 3, 'final' => false]) . "\nX");
        try {
            $result = $endpoints->push_stream([], $this->pushHeaders(), $stream);
        } finally {
            fclose($stream);
        }

        $this->assertSame(400, $result['http_code']);
        $this->assertSame('invalid_frame', $result['body']['reason']);
        $this->assertStringContainsString('offset 5 and 1 payload bytes, which exceeds total_bytes 3', $result['body']['detail']);
    }

    // ---------------------------------------------------------------
    // Control plane: finalize, status, discard
    // ---------------------------------------------------------------

    public function testFinalizeWithWrongTotalIsRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'payload', 'total_bytes' => 10, 'final' => false],
        ]);

        $result = $this->finalize($endpoints, 'artifact-1', 99);

        $this->assertSame(409, $result['http_code']);
        $this->assertSame('size_mismatch', $result['body']['reason']);
    }

    private function finalize(Site_Export_Staged_Endpoints $endpoints, string $artifact_id, int $total): array
    {
        return $endpoints->finalize(
            ['artifact_id' => $artifact_id, 'total_bytes' => $total],
            ['REQUEST_METHOD' => 'POST']
        );
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
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'payload', 'total_bytes' => 7, 'final' => true],
        ]);

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

        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'abcde', 'total_bytes' => 10, 'final' => false],
        ]);
        $known = $endpoints->status(['artifact_id' => 'artifact-1']);
        $this->assertSame(
            ['exists' => true, 'committed_bytes' => 5, 'verified' => false],
            $known['body']
        );
    }

    public function testDiscardReportsHeldStoreAsRetriable(): void
    {
        $endpoints = $this->makeEndpoints();
        $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => 'payload', 'total_bytes' => 7, 'final' => true],
        ]);

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
    // Request validation and server-owned options
    // ---------------------------------------------------------------

    public function testMutatingRoutesRequirePost(): void
    {
        $endpoints = $this->makeEndpoints();
        $get = ['REQUEST_METHOD' => 'GET'];
        $stream = $this->bodyStream('');

        $push = $endpoints->push_stream([], $get, $stream);
        fclose($stream);
        $finalize = $endpoints->finalize(['artifact_id' => 'a', 'total_bytes' => 1], $get);
        $discard = $endpoints->discard(['artifact_id' => 'a'], $get);

        foreach ([$push, $finalize, $discard] as $result) {
            $this->assertSame(405, $result['http_code']);
            $this->assertSame('method_not_allowed', $result['body']['reason']);
        }
    }

    public function testMalformedParametersAreRejected(): void
    {
        $endpoints = $this->makeEndpoints();
        $post = ['REQUEST_METHOD' => 'POST'];

        $bad_total = $endpoints->finalize(['artifact_id' => 'a', 'total_bytes' => 'many'], $post);
        $this->assertSame('invalid_total', $bad_total['body']['reason']);

        $bad_status_id = $endpoints->status(['artifact_id' => '']);
        $this->assertSame(400, $bad_status_id['http_code']);
    }

    public function testClientParametersCannotChooseServerOptions(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 1024]);
        $evil_dir = $this->staging_dir . '-evil';
        $result = $this->push($endpoints, [
            ['artifact_id' => 'artifact-1', 'offset' => 0, 'bytes' => str_repeat('x', 100), 'total_bytes' => 100, 'final' => true],
        ], [], [
            // Options are server-owned; parameters with the same names
            // must be ignored.
            'staging_dir' => $evil_dir,
            'max_request_bytes' => 10,
            'secret' => 'attacker-chosen',
        ]);

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

    public function testHandleRequestDoesNotBufferStagedPushBodies(): void
    {
        $reads = 0;
        $server = new Site_Export_HTTP_Server([
            'staged' => ['staging_dir' => $this->staging_dir, 'secret' => self::SECRET],
            'body_reader' => function () use (&$reads): string {
                ++$reads;
                return 'this would buffer the raw push body';
            },
        ]);

        $previous_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
        $previous_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = self::PUSH_TARGET;
        $buffer_level = ob_get_level();
        ob_start();
        try {
            $server->handle_request([
                'get' => ['endpoint' => 'staged_push'],
                'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'],
            ]);
            $output = ob_get_clean();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            if ($previous_request_method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previous_request_method;
            }
            if ($previous_request_uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previous_request_uri;
            }
        }

        $this->assertSame(0, $reads, 'staged_push body bytes must only be read by the staged handler');
        $this->assertSame('auth_failed', json_decode( (string) $output, true)['reason']);
    }
}
