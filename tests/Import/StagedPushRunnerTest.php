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
 */
class StagedPushRunnerTest extends TestCase
{
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
        return function (string $method, string $url, array $headers, string $body, int $timeout) use ($endpoints): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            $endpoint = (string) ($params['endpoint'] ?? '');
            unset($params['endpoint']);

            $server = ['REQUEST_METHOD' => $method];
            foreach ($headers as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }

            switch ($endpoint) {
                case 'staged_upload':
                    $stream = fopen('php://temp', 'w+b');
                    fwrite($stream, $body);
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
            'client' => $client,
            'sizer' => $sizer,
        ];
        if ($on_progress !== null) {
            $options['on_progress'] = $on_progress;
        }
        return [new StagedPushRunner($options), $sizer];
    }

    /**
     * @return array{artifact_id:string,source_path:string}
     */
    private function planEntry(string $artifact_id, string $body): array
    {
        $path = $this->source_dir . '/' . str_replace('/', '_', $artifact_id);
        file_put_contents($path, $body);
        return ['artifact_id' => $artifact_id, 'source_path' => $path];
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

    public function testPushesAPlanToCompletion(): void
    {
        $plan = [
            $this->planEntry('wp-content/plugins/a.php', str_repeat('a', 20)),
            $this->planEntry('wp-content/themes/b.css', str_repeat('b', 9)),
            $this->planEntry('empty.txt', ''),
        ];
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($plan);

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
        $this->assertFileExists($this->state_dir . '/.push-verified.jsonl');
    }

    public function testProgressCoversChunksAndFiles(): void
    {
        $plan = [$this->planEntry('artifact.bin', str_repeat('x', 20))];
        $progress = [];
        [$runner] = $this->makeRunner(
            $this->transportFor($this->makeEndpoints()),
            [],
            static function (array $record) use (&$progress): void {
                $progress[] = $record;
            }
        );

        $runner->push($plan);

        $this->assertNotEmpty($progress);
        $last = end($progress);
        $this->assertSame(1, $last['files_done']);
        $this->assertSame(1, $last['files_total']);
        $this->assertSame(20, $last['committed_bytes']);
    }

    // ---------------------------------------------------------------
    // Resume
    // ---------------------------------------------------------------

    public function testResumeSkipsCachedArtifactsWithoutRequests(): void
    {
        $plan = [
            $this->planEntry('a.bin', str_repeat('a', 12)),
            $this->planEntry('b.bin', str_repeat('b', 12)),
        ];
        $transport = $this->transportFor($this->makeEndpoints());
        [$runner] = $this->makeRunner($transport);
        $this->assertSame('completed', $runner->push($plan)['status']);

        $this->requests = [];
        [$again] = $this->makeRunner($transport);
        $result = $again->push($plan);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['files_done']);
        $this->assertCount(0, $this->requests, 'cache hits must not cost requests');
    }

    public function testTornCacheLineFallsBackToTheServerShortCircuit(): void
    {
        $plan = [$this->planEntry('artifact.bin', str_repeat('x', 12))];
        $transport = $this->transportFor($this->makeEndpoints());
        [$runner] = $this->makeRunner($transport);
        $runner->push($plan);

        // A kill mid-append left a torn cache line instead of the record.
        file_put_contents($this->state_dir . '/.push-verified.jsonl', '{"artifact_id":"artifact.bin","si');

        $this->requests = [];
        [$again] = $this->makeRunner($transport);
        $result = $again->push($plan);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['files_done']);
        $this->assertCount(0, $this->requestsFor('staged_upload'), 'no bytes re-uploaded');
        $this->assertCount(1, $this->requestsFor('staged_status'));
        $this->assertCount(1, $this->requestsFor('staged_finalize'));
    }

    public function testMidArtifactResumeUploadsOnlyTheTail(): void
    {
        $body = str_repeat('resumable-', 3);
        $plan = [$this->planEntry('artifact.bin', $body)];
        // A previous run staged the first 16 bytes before dying; no cache
        // line was written.
        (new Site_Export_Staged_Artifacts($this->staging_dir))
            ->append('artifact.bin', 0, substr($body, 0, 16));
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($plan);

        $this->assertSame('completed', $result['status']);
        $uploads = $this->requestsFor('staged_upload');
        $this->assertSame('16', $uploads[0]['params']['offset']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
    }

    // ---------------------------------------------------------------
    // Failure routing
    // ---------------------------------------------------------------

    public function testArtifactScopedFailureContinuesAndRerunRetriesIt(): void
    {
        $plan = [
            $this->planEntry('a.bin', str_repeat('a', 8)),
            $this->planEntry('gone.bin', str_repeat('g', 8)),
            $this->planEntry('c.bin', str_repeat('c', 8)),
        ];
        unlink($plan[1]['source_path']);
        $transport = $this->transportFor($this->makeEndpoints());
        [$runner] = $this->makeRunner($transport);

        $result = $runner->push($plan);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['files_done']);
        $this->assertSame(
            [['artifact_id' => 'gone.bin', 'reason' => 'source_unreadable', 'detail' => $plan[1]['source_path']]],
            $result['failed']
        );

        // The source reappears; a rerun uploads only the failed artifact.
        file_put_contents($plan[1]['source_path'], str_repeat('g', 8));
        $this->requests = [];
        [$again] = $this->makeRunner($transport);
        $retry = $again->push($plan);

        $this->assertSame(['completed', 3, []], [$retry['status'], $retry['files_done'], $retry['failed']]);
        $uploads = $this->requestsFor('staged_upload');
        $this->assertCount(1, $uploads);
        $this->assertSame('gone.bin', $uploads[0]['params']['artifact_id']);
    }

    public function testTransferScopedFailureAborts(): void
    {
        $plan = [
            $this->planEntry('a.bin', str_repeat('a', 8)),
            $this->planEntry('b.bin', str_repeat('b', 8)),
        ];
        [$runner] = $this->makeRunner(
            $this->transportFor($this->makeEndpoints()),
            ['hmac_client' => new Site_Export_HMAC_Client('wrong-secret')]
        );

        $result = $runner->push($plan);

        $this->assertSame('aborted', $result['status']);
        $this->assertSame('auth_failed', $result['abort_reason']);
        $this->assertSame(0, $result['files_done']);
        $this->assertCount(1, $this->requestsFor('staged_upload'), 'the abort stops the walk');
    }

    public function testPlanSizeDisagreeingWithTheCacheFailsTyped(): void
    {
        $entry = $this->planEntry('artifact.bin', str_repeat('x', 10));
        $transport = $this->transportFor($this->makeEndpoints());
        [$runner] = $this->makeRunner($transport);
        $runner->push([$entry]);

        // The next plan declares a different size for the verified artifact.
        $entry['total_bytes'] = 12;
        [$again] = $this->makeRunner($transport);
        $result = $again->push([$entry]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('size_mismatch', $result['failed'][0]['reason']);
    }

    public function testInvalidPlanEntriesAreRecordedNotFatal(): void
    {
        $plan = [
            ['artifact_id' => 'no-source.bin'],
            $this->planEntry('ok.bin', 'fine'),
        ];
        [$runner] = $this->makeRunner($this->transportFor($this->makeEndpoints()));

        $result = $runner->push($plan);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['files_done']);
        $this->assertSame('invalid_artifact_entry', $result['failed'][0]['reason']);
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

        $first = $runner->push([$this->planEntry('a.bin', str_repeat('a', 20))]);
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
        $second = $again->push([$this->planEntry('b.bin', str_repeat('b', 20))]);

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

    public function testUnwritableStateDirAbortsTypedBeforeAnyRequest(): void
    {
        // A file where the state dir belongs makes mkdir fail: the runner
        // must abort typed instead of pushing without a done cache (every
        // rerun would then re-upload the world).
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
            'client' => $client,
            'sizer' => new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8]),
        ]);

        try {
            $result = $runner->push([
                ['artifact_id' => 'a.txt', 'source_path' => $this->source_dir . '/never-read'],
            ]);

            $this->assertSame('aborted', $result['status']);
            $this->assertSame('state_dir_unwritable', $result['abort_reason']);
            $this->assertSame([], $this->requests, 'no request may travel without a state dir');
        } finally {
            @unlink($blocker);
        }
    }
}
