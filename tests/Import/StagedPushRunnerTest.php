<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Artifacts;
use Site_Export_Staged_Endpoints;
use StagedPushRunner;
use StagedUploadClient;
use UploadChunkSizer;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Drives the real runner through the real client, endpoints, and store
 * in-process; only the socket is a callable, which also records every
 * request and response code.
 *
 * The runner consumes the upload list the diff produced (path/size/ctime per
 * line) and keeps no done cache: resume trusts the store's committed offset
 * and verified short-circuit, the way pull trusts the files in its fs-root.
 */
class StagedPushRunnerTest extends TestCase {

    private const SECRET = 'staged-push-runner-test-secret';

    private string $staging_dir;

    private string $state_dir;

    private string $source_dir;

    /** @var array<int, array{endpoint:string, params:array, http_code:int}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->staging_dir = sys_get_temp_dir() . '/push-runner-staging-' . $suffix;
        $this->state_dir = sys_get_temp_dir() . '/push-runner-state-' . $suffix;
        $this->source_dir = sys_get_temp_dir() . '/push-runner-source-' . $suffix;
        mkdir($this->source_dir, 0700, true);
        mkdir($this->state_dir, 0700, true);
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->staging_dir, $this->state_dir, $this->source_dir] as $dir) {
            $this->removeDir($dir);
        }
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

    private function transportFor(Site_Export_Staged_Endpoints $endpoints): callable
    {
        return function (string $method, string $url, array $headers, $body, int $timeout) use ($endpoints): array {
            parse_str( (string) parse_url($url, PHP_URL_QUERY), $params);
            $endpoint = (string) ( $params['endpoint'] ?? '' );
            unset($params['endpoint']);

            $server = ['REQUEST_METHOD' => $method];
            foreach ($headers as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }

            switch ($endpoint) {
                case 'staged_upload':
                    $stream = fopen('php://temp', 'w+b');
                    if (is_resource($body)) {
                        rewind($body);
                        stream_copy_to_stream($body, $stream);
                    } else {
                        fwrite($stream, (string) $body);
                    }
                    rewind($stream);
                    $result = $endpoints->upload($params, $server, $stream);
                    fclose($stream);
                    break;
                case 'staged_finalize':
                    $result = $endpoints->finalize($params, $server);
                    break;
                case 'staged_status':
                    $result = $endpoints->status($params);
                    break;
                case 'staged_discard':
                    $result = $endpoints->discard($params, $server);
                    break;
                case 'staged_upload_batch':
                    $stream = fopen('php://temp', 'w+b');
                    if (is_resource($body)) {
                        rewind($body);
                        stream_copy_to_stream($body, $stream);
                    } else {
                        fwrite($stream, (string) $body);
                    }
                    rewind($stream);
                    $result = $endpoints->upload_batch($params, $server, $stream);
                    fclose($stream);
                    break;
                default:
                    return ['http_code' => 400, 'body' => '{"error":"unknown endpoint"}', 'error' => null];
            }

            $this->requests[] = [
                'endpoint' => $endpoint,
                'params' => $params,
                'http_code' => $result['http_code'],
            ];

            return [
                'http_code' => $result['http_code'],
                'body' => (string) json_encode($result['body']),
                'error' => null,
            ];
        };
    }

    /**
     * @return array{0:StagedPushRunner,1:UploadChunkSizer}
     */
    private function makeRunner(callable $transport, array $client_overrides = [], ?callable $on_progress = null, ?UploadChunkSizer $sizer = null): array
    {
        $sizer = $sizer ?? new UploadChunkSizer(
            ['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8],
            StagedPushRunner::read_state($this->state_dir)['sizer']
        );
        $client = new StagedUploadClient(array_merge([
            'base_url' => 'https://target.example/?reprint-api',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'sizer' => $sizer,
            'transport' => $transport,
            'sleeper' => static function (int $microseconds): void {
            },
        ], $client_overrides));

        $options = [
            'state_dir' => $this->state_dir,
            'source_root' => $this->source_dir,
            'client' => $client,
            'sizer' => $sizer,
        ];
        if ($on_progress !== null) {
            $options['on_progress'] = $on_progress;
        }
        return [new StagedPushRunner($options), $sizer];
    }

    /** Stage a source file the runner will read under source_root. */
    private function stageSource(string $artifact_id, string $body, ?int $mtime = null): void
    {
        $path = $this->source_dir . '/' . $artifact_id;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $body);
        if ($mtime !== null) {
            touch($path, $mtime);
        }
    }

    /**
     * Write an upload list (the diff's output) from specs, staging each
     * source. Each spec: ['id'=>string, 'body'=>string, 'mtime'=>?int,
     * 'size'=>?int (overrides the real size, to force a mismatch)].
     */
    private function uploadList(array $specs): string
    {
        $lines = '';
        foreach ($specs as $spec) {
            $this->stageSource($spec['id'], $spec['body'], $spec['mtime'] ?? null);
            $ctime = (int) ( $spec['mtime'] ?? filemtime($this->source_dir . '/' . $spec['id']) );
            $lines .= json_encode([
                'path' => base64_encode($spec['id']),
                'size' => $spec['size'] ?? strlen($spec['body']),
                'ctime' => $ctime,
            ]) . "\n";
        }
        $file = $this->state_dir . '/.push-upload-list.jsonl';
        file_put_contents($file, $lines);
        return $file;
    }

    private function requestsFor(string $endpoint): array
    {
        return array_values(array_filter($this->requests, static function (array $request) use ($endpoint): bool {
            return $request['endpoint'] === $endpoint;
        }));
    }

    // ---------------------------------------------------------------
    // Completion and state files
    // ---------------------------------------------------------------

    public function testPushesAnUploadListToCompletion(): void
    {
        $list = $this->uploadList([
            ['id' => 'wp-content/plugins/a.php', 'body' => str_repeat('a', 20)],
            ['id' => 'wp-content/themes/b.css', 'body' => str_repeat('b', 9)],
            ['id' => 'empty.txt', 'body' => ''],
        ]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['files_total']);
        $this->assertSame(3, $result['files_done']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(
            str_repeat('a', 20),
            file_get_contents($this->staging_dir . '/files/wp-content/plugins/a.php')
        );

        $state = StagedPushRunner::read_state($this->state_dir);
        $this->assertSame(3, $state['files_total']);
        $this->assertSame(3, $state['files_done']);
        $this->assertArrayHasKey('chunk_bytes', $state['sizer']);
        $this->assertFileDoesNotExist(
            $this->state_dir . '/.push-verified.jsonl',
            'the collapse dropped the done cache'
        );
    }

    public function testProgressCoversChunksAndFiles(): void
    {
        $list = $this->uploadList([['id' => 'artifact.bin', 'body' => str_repeat('x', 20)]]);
        $progress = [];
        [$runner] = $this->makeRunner(
            $this->transportFor($this->makeEndpoints()),
            [],
            static function (array $record) use (&$progress): void {
                $progress[] = $record;
            }
        );

        $runner->push($list);

        $this->assertNotEmpty($progress);
        $last = end($progress);
        $this->assertSame(1, $last['files_done']);
        $this->assertSame(1, $last['files_total']);
        $this->assertSame(20, $last['committed_bytes']);
    }

    public function testEmptyUploadListCompletesWithoutRequests(): void
    {
        $list = $this->uploadList([]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame(['completed', 0, 0], [$result['status'], $result['files_total'], $result['files_done']]);
        $this->assertCount(0, $this->requests);
    }

    public function testMalformedUploadLineIsSkipped(): void
    {
        $list = $this->uploadList([['id' => 'good.txt', 'body' => 'ok']]);
        file_put_contents($list, "not json at all\n" . file_get_contents($list) . "{\"path\":\"\"}\n");
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['files_done']);
        $this->assertSame('ok', file_get_contents($this->staging_dir . '/files/good.txt'));
    }

    // ---------------------------------------------------------------
    // Resume through the store, not a cache
    // ---------------------------------------------------------------

    public function testMidArtifactResumeUploadsOnlyTheTail(): void
    {
        $body = str_repeat('resumable-', 3);
        // A previous run staged the first 16 bytes before dying.
        ( new Site_Export_Staged_Artifacts($this->staging_dir) )
            ->append('artifact.bin', 0, substr($body, 0, 16));
        $list = $this->uploadList([['id' => 'artifact.bin', 'body' => $body]]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $uploads = $this->requestsFor('staged_upload');
        $this->assertSame('16', $uploads[0]['params']['offset']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
    }

    public function testFullyStagedArtifactShortCircuitsWithoutReuploadingBytes(): void
    {
        // A large file finished in a prior run is still verified in the
        // store; the client confirms it through status/finalize without
        // sending a byte — pull's "already in fs-root" trust, inverted.
        $body = str_repeat('x', 20);
        $store = new Site_Export_Staged_Artifacts($this->staging_dir);
        $store->append('big.bin', 0, $body);
        $this->assertSame('verified', $store->finalize('big.bin', 20)['status']);

        $list = $this->uploadList([['id' => 'big.bin', 'body' => $body]]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame(['completed', 1], [$result['status'], $result['files_done']]);
        $this->assertCount(0, $this->requestsFor('staged_upload'), 'no bytes re-uploaded');
        $this->assertNotEmpty($this->requestsFor('staged_status'));
    }

    // ---------------------------------------------------------------
    // Failure routing
    // ---------------------------------------------------------------

    public function testArtifactScopedFailureContinuesAndRerunRetriesIt(): void
    {
        $list = $this->uploadList([
            ['id' => 'a.bin', 'body' => str_repeat('a', 8)],
            ['id' => 'gone.bin', 'body' => str_repeat('g', 8)],
            ['id' => 'c.bin', 'body' => str_repeat('c', 8)],
        ]);
        unlink($this->source_dir . '/gone.bin');
        $transport = $this->transportFor($this->makeEndpoints());
        [$runner] = $this->makeRunner($transport);

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['files_done']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('gone.bin', $result['failed'][0]['artifact_id']);
        $this->assertContains(
            $result['failed'][0]['reason'],
            ['source_unreadable', 'source_short', 'source_changed'],
            'a missing source fails artifact-scoped'
        );

        // The source reappears; a rerun uploads only the failed artifact.
        file_put_contents($this->source_dir . '/gone.bin', str_repeat('g', 8));
        $this->requests = [];
        [$again] = $this->makeRunner($transport);
        $retry = $again->push($list);

        $this->assertSame(['completed', 3, []], [$retry['status'], $retry['files_done'], $retry['failed']]);
        $uploads = array_values(array_filter(
            $this->requestsFor('staged_upload'),
            static fn(array $r): bool => ( $r['params']['artifact_id'] ?? '' ) === 'gone.bin'
        ));
        $this->assertNotEmpty($uploads, 'only the previously-failed artifact re-uploads its bytes');
    }

    public function testTransferScopedFailureAborts(): void
    {
        $list = $this->uploadList([
            ['id' => 'a.bin', 'body' => str_repeat('a', 8)],
            ['id' => 'b.bin', 'body' => str_repeat('b', 8)],
        ]);
        [$runner] = $this->makeRunner(
            $this->transportFor($this->makeEndpoints()),
            ['hmac_client' => new Site_Export_HMAC_Client('wrong-secret')]
        );

        $result = $runner->push($list);

        $this->assertSame('aborted', $result['status']);
        $this->assertSame('auth_failed', $result['abort_reason']);
        $this->assertSame(0, $result['files_done']);
    }

    public function testDeclaredSizeLongerThanSourceFailsScoped(): void
    {
        // The diff declared 12 bytes but the source holds 10: the client
        // refuses rather than move the mismatch to finalize.
        $list = $this->uploadList([['id' => 'short.bin', 'body' => str_repeat('x', 10), 'size' => 12]]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('source_short', $result['failed'][0]['reason']);
    }

    public function testSourceChangedMidPushFailsTheArtifactAndContinues(): void
    {
        $volatile_body = str_repeat('abcd', 6); // 3 chunks at the 8-byte sizer
        $list = $this->uploadList([
            ['id' => 'volatile.bin', 'body' => $volatile_body],
            ['id' => 'stable.bin', 'body' => 'steady'],
        ]);
        $base = $this->transportFor($this->makeEndpoints());
        $rewritten = false;
        $transport = function (...$args) use ($base, &$rewritten): array {
            $response = $base(...$args);
            if (
                !$rewritten
                && strpos($args[1], 'staged_upload') !== false
                && strpos($args[1], 'volatile.bin') !== false
            ) {
                $rewritten = true;
                // An active site edits the file while the push reads it.
                file_put_contents($this->source_dir . '/volatile.bin', str_repeat('zzzz', 6));
                touch($this->source_dir . '/volatile.bin', time() + 10);
            }
            return $response;
        };
        [$runner] = $this->makeRunner($transport);

        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['files_done']);
        $this->assertSame('source_changed', $result['failed'][0]['reason']);
        $this->assertSame(
            'steady',
            file_get_contents($this->staging_dir . '/files/stable.bin'),
            'the rest of the transfer still pushes'
        );
    }

    // ---------------------------------------------------------------
    // Sizer persistence
    // ---------------------------------------------------------------

    public function testLearnedChunkLimitsSurviveRuns(): void
    {
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 10]);
        $transport = $this->transportFor($endpoints);
        $sizer = new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 32, 'max_bytes' => 32]);
        [$runner] = $this->makeRunner($transport, [], null, $sizer);

        $first = $runner->push($this->uploadList([['id' => 'a.bin', 'body' => str_repeat('a', 20)]]));
        $this->assertSame('completed', $first['status']);
        $this->assertNotEmpty(array_filter($this->requests, static function (array $request): bool {
            return $request['http_code'] === 413;
        }), 'the first run learns the cap from a 413');

        // A fresh process restores the sizer through read_state(); the
        // learned ceiling means no new 413 probes.
        $this->requests = [];
        $restored = new UploadChunkSizer(
            ['floor_bytes' => 4, 'start_bytes' => 32, 'max_bytes' => 32],
            StagedPushRunner::read_state($this->state_dir)['sizer']
        );
        [$again] = $this->makeRunner($transport, [], null, $restored);
        $second = $again->push($this->uploadList([['id' => 'b.bin', 'body' => str_repeat('b', 20)]]));

        $this->assertSame('completed', $second['status']);
        $this->assertSame([], array_filter($this->requests, static function (array $request): bool {
            return $request['http_code'] === 413;
        }), 'the second run starts under the learned cap');
    }

    public function testReadStateDefaultsWhenNothingPersisted(): void
    {
        $state = StagedPushRunner::read_state($this->state_dir . '-never-created');

        $this->assertSame(['sizer' => [], 'files_total' => 0, 'files_done' => 0], $state);
    }

    // ---------------------------------------------------------------
    // Batched uploads
    // ---------------------------------------------------------------

    public function testSmallFilesTravelInBatchesNotOneRequestEach(): void
    {
        $specs = [];
        for ($i = 0; $i < 6; $i++) {
            $specs[] = ['id' => "small-{$i}.txt", 'body' => "file number {$i}"];
        }
        $sizer = new UploadChunkSizer(['floor_bytes' => 512, 'start_bytes' => 4096, 'max_bytes' => 4096]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()), [], null, $sizer);

        $result = $runner->push($this->uploadList($specs));

        $this->assertSame(['completed', 6], [$result['status'], $result['files_done']]);
        $this->assertCount(1, $this->requestsFor('staged_upload_batch'), 'six files, one conversation');
        $this->assertCount(0, $this->requestsFor('staged_upload'), 'no per-file requests for small files');
        $this->assertSame('file number 3', file_get_contents($this->staging_dir . '/files/small-3.txt'));
    }

    public function testMixedListBatchesSmallAndChunksLarge(): void
    {
        $sizer = new UploadChunkSizer(['floor_bytes' => 64, 'start_bytes' => 512, 'max_bytes' => 512]);
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()), [], null, $sizer);

        $result = $runner->push($this->uploadList([
            ['id' => 'small-a.txt', 'body' => 'aa'],
            ['id' => 'large.bin', 'body' => str_repeat('L', 2000)],
            ['id' => 'small-b.txt', 'body' => 'bb'],
        ]));

        $this->assertSame(['completed', 3, []], [$result['status'], $result['files_done'], $result['failed']]);
        $this->assertGreaterThanOrEqual(1, count($this->requestsFor('staged_upload_batch')));
        $this->assertGreaterThanOrEqual(
            4,
            count($this->requestsFor('staged_upload')),
            'the 2000-byte file streams in 512-byte chunks'
        );
        $this->assertSame(str_repeat('L', 2000), file_get_contents($this->staging_dir . '/files/large.bin'));
    }

    public function testBatchMemberChangedSinceDiffFailsScopedAndContinues(): void
    {
        // The volatility discipline holds on the batch path too: a small
        // file edited between the diff and the upload fails typed and local,
        // and the rest of its batch still travels.
        $mtime = 1000;
        $list = $this->uploadList([
            ['id' => 'volatile.txt', 'body' => 'original', 'mtime' => $mtime],
            ['id' => 'stable.txt', 'body' => 'steady'],
        ]);
        file_put_contents($this->source_dir . '/volatile.txt', 'rewritten');
        touch($this->source_dir . '/volatile.txt', $mtime + 10);

        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));
        $result = $runner->push($list);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['files_done']);
        $this->assertSame(
            ['volatile.txt', 'source_changed'],
            [$result['failed'][0]['artifact_id'], $result['failed'][0]['reason']]
        );
        $this->assertSame('steady', file_get_contents($this->staging_dir . '/files/stable.txt'));
        $this->assertFileDoesNotExist(
            $this->staging_dir . '/files/volatile.txt',
            'stale content never travels'
        );
    }

    public function testBatchShrinksAfterA413AndCompletes(): void
    {
        $specs = [];
        for ($i = 0; $i < 6; $i++) {
            $specs[] = ['id' => "cap-{$i}.txt", 'body' => str_repeat( (string) $i, 40)];
        }
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 700]);
        $sizer = new UploadChunkSizer(['floor_bytes' => 64, 'start_bytes' => 4096, 'max_bytes' => 4096]);
        [$runner] = $this->makeRunner($this->transportFor($endpoints), [], null, $sizer);

        $result = $runner->push($this->uploadList($specs));

        $this->assertSame(['completed', 6, []], [$result['status'], $result['files_done'], $result['failed']]);
        $this->assertNotEmpty(array_filter($this->requests, static function (array $request): bool {
            return $request['http_code'] === 413;
        }), 'the first oversized batch teaches the sizer');
        $this->assertGreaterThanOrEqual(2, count($this->requestsFor('staged_upload_batch')), 'repartitioned batches');
    }

    public function testUnwritableStateDirAbortsTypedBeforeAnyRequest(): void
    {
        // A file where the state dir belongs makes mkdir fail: the runner
        // aborts typed instead of pushing without persistence.
        $blocker = $this->state_dir . '-blocker';
        file_put_contents($blocker, 'not a directory');
        $transport = function (...$args): array {
            $this->requests[] = ['endpoint' => 'unexpected', 'params' => [], 'http_code' => 0];
            return ['http_code' => 200, 'body' => '{}', 'error' => null];
        };
        $client = new StagedUploadClient([
            'base_url' => 'https://target.example/?reprint-api',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'sizer' => new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8]),
            'transport' => $transport,
            'sleeper' => static function (int $microseconds): void {
            },
        ]);
        $runner = new StagedPushRunner([
            'state_dir' => $blocker . '/nested',
            'source_root' => $this->source_dir,
            'client' => $client,
            'sizer' => new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8]),
        ]);

        try {
            $list = $this->uploadList([['id' => 'a.txt', 'body' => 'x']]);
            $result = $runner->push($list);

            $this->assertSame('aborted', $result['status']);
            $this->assertSame('state_dir_unwritable', $result['abort_reason']);
            $this->assertSame([], $this->requests, 'no request may travel without a state dir');
        } finally {
            @unlink($blocker);
        }
    }
}
