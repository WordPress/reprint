<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Artifacts;
use Site_Export_Staged_Endpoints;
use StagedUploadClient;
use UploadChunkSizer;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Drives the real StagedUploadClient against the real staged endpoints and
 * store in-process: requests are signed by the real HMAC client and verified
 * by the real HMAC server, only the socket is replaced by a callable.
 */
class StagedUploadClientTest extends TestCase
{
    private const SECRET = 'staged-upload-client-test-secret';

    private string $staging_dir;

    private string $source_path;

    /** @var array<int, array{endpoint:string, params:array}> */
    private array $requests = [];

    /** @var int[] */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->staging_dir = sys_get_temp_dir() . '/staged-upload-client-' . $suffix;
        $this->source_path = sys_get_temp_dir() . '/staged-upload-source-' . $suffix;
        $this->requests = [];
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->source_path);
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
     * In-process transport: routes signed requests into the endpoint class
     * the way the HTTP dispatcher would, recording every call.
     */
    private function transportFor(Site_Export_Staged_Endpoints $endpoints): callable
    {
        return function (string $method, string $url, array $headers, string $body, int $timeout) use ($endpoints): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            $endpoint = (string) ($params['endpoint'] ?? '');
            unset($params['endpoint']);
            $this->requests[] = ['endpoint' => $endpoint, 'params' => $params];

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

            return [
                'http_code' => $result['http_code'],
                'body' => (string) json_encode($result['body']),
                'error' => null,
            ];
        };
    }

    private function makeClient(callable $transport, array $overrides = []): StagedUploadClient
    {
        return new StagedUploadClient(array_merge([
            'base_url' => 'https://target.example/?reprint-api',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'sizer' => new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8]),
            'transport' => $transport,
            'sleeper' => function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
        ], $overrides));
    }

    private function writeSource(string $body): void
    {
        file_put_contents($this->source_path, $body);
    }

    private function uploadCalls(): array
    {
        return array_values(array_filter($this->requests, static function (array $request): bool {
            return $request['endpoint'] === 'staged_upload';
        }));
    }

    // ---------------------------------------------------------------
    // Happy paths
    // ---------------------------------------------------------------

    public function testUploadsAMultiChunkArtifactToVerified(): void
    {
        $body = str_repeat('0123456789', 6);
        $this->writeSource($body);
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $progress = [];
        $result = $client->upload_artifact(
            'wp-content/uploads/a.bin',
            $this->source_path,
            null,
            static function (int $committed, int $total) use (&$progress): void {
                $progress[] = [$committed, $total];
            }
        );

        $this->assertSame('verified', $result['status']);
        $this->assertSame(strlen($body), $result['committed_bytes']);
        $this->assertSame(
            $body,
            file_get_contents($this->staging_dir . '/files/wp-content/uploads/a.bin')
        );
        $this->assertCount(8, $this->uploadCalls(), '60 bytes in 8-byte chunks');
        $offsets = array_column($progress, 0);
        $sorted = $offsets;
        sort($sorted);
        $this->assertSame($sorted, $offsets, 'progress never goes backward');
        $this->assertSame([strlen($body), strlen($body)], end($progress));
    }

    public function testResumesFromTheStoreCommittedOffset(): void
    {
        $body = str_repeat('resumable-', 6);
        $this->writeSource($body);
        // A previous run staged the first 24 bytes.
        (new Site_Export_Staged_Artifacts($this->staging_dir))
            ->append('artifact.bin', 0, substr($body, 0, 24));
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $result = $client->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('24', $this->uploadCalls()[0]['params']['offset']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
    }

    public function testZeroByteArtifactVerifiesWithoutUploads(): void
    {
        $this->writeSource('');
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $result = $client->upload_artifact('empty.txt', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertCount(0, $this->uploadCalls());
    }

    public function testAlreadyVerifiedArtifactShortCircuits(): void
    {
        $body = 'push-me-once';
        $this->writeSource($body);
        $transport = $this->transportFor($this->makeEndpoints());
        $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);
        $this->requests = [];

        $again = $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);
        $this->assertSame('verified', $again['status']);
        $this->assertCount(0, $this->uploadCalls());

        $wrong_total = $this->makeClient($transport)
            ->upload_artifact('artifact.bin', $this->source_path, strlen($body) + 5);
        $this->assertSame(['failed', 'size_mismatch'], [$wrong_total['status'], $wrong_total['reason']]);
    }

    // ---------------------------------------------------------------
    // Response-driven resync
    // ---------------------------------------------------------------

    public function testLostResponseRetryLandsAsDuplicateAndContinues(): void
    {
        $body = str_repeat('abcdefgh', 4);
        $this->writeSource($body);
        $base = $this->transportFor($this->makeEndpoints());

        // The second chunk commits server-side but its response is lost.
        $upload_seen = 0;
        $transport = function (...$args) use ($base, &$upload_seen): array {
            $response = $base(...$args);
            if (strpos($args[1], 'staged_upload') !== false && ++$upload_seen === 2) {
                return ['http_code' => 0, 'body' => '', 'error' => 'timeout after commit'];
            }
            return $response;
        };

        $result = $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
        $this->assertNotEmpty($this->sleeps, 'the lost response costs one backoff');
    }

    public function testServerSideDiscardMidTransferResyncsFromZero(): void
    {
        $body = str_repeat('resync!!', 5);
        $this->writeSource($body);
        $base = $this->transportFor($this->makeEndpoints());

        // Someone discards the artifact on the target between two chunks.
        $upload_seen = 0;
        $transport = function (...$args) use ($base, &$upload_seen): array {
            if (strpos($args[1], 'staged_upload') !== false && ++$upload_seen === 3) {
                (new Site_Export_Staged_Artifacts($this->staging_dir))->discard('artifact.bin');
            }
            return $base(...$args);
        };

        $result = $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
    }

    // ---------------------------------------------------------------
    // Chunk-size learning
    // ---------------------------------------------------------------

    public function test413ShrinksToTheReportedCapAndSucceeds(): void
    {
        $body = str_repeat('x', 40);
        $this->writeSource($body);
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 10]);
        $sizer = new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 32, 'max_bytes' => 32]);
        $client = $this->makeClient($this->transportFor($endpoints), ['sizer' => $sizer]);

        $result = $client->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertSame($body, file_get_contents($this->staging_dir . '/files/artifact.bin'));
        $this->assertLessThanOrEqual(9, $sizer->chunk_bytes(), 'ceiling learned from the 413');
    }

    public function testGivesUpWhenTheFloorIsStillTooLarge(): void
    {
        $this->writeSource(str_repeat('x', 40));
        $endpoints = $this->makeEndpoints(['max_request_bytes' => 2]);
        $sizer = new UploadChunkSizer(['floor_bytes' => 4, 'start_bytes' => 4, 'max_bytes' => 8]);
        $client = $this->makeClient($this->transportFor($endpoints), ['sizer' => $sizer]);

        $result = $client->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame(
            ['failed', 'chunk_size_exhausted'],
            [$result['status'], $result['reason']]
        );
        $this->assertLessThan(4, count($this->uploadCalls()), 'no endless probing below the floor');
    }

    // ---------------------------------------------------------------
    // Failure classes
    // ---------------------------------------------------------------

    public function testBusyStoreRetriesThenSucceeds(): void
    {
        $body = 'busy-then-fine';
        $this->writeSource($body);
        $base = $this->transportFor($this->makeEndpoints());

        $injected = false;
        $transport = function (...$args) use ($base, &$injected): array {
            if (!$injected && strpos($args[1], 'staged_upload') !== false) {
                $injected = true;
                return [
                    'http_code' => 423,
                    'body' => '{"status":"busy","reason":null,"detail":null,"committed_bytes":0}',
                    'error' => null,
                ];
            }
            return $base(...$args);
        };

        $result = $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame('verified', $result['status']);
        $this->assertNotEmpty($this->sleeps);
    }

    public function testControlPlaneAuthEnvelopeSurfacesAsAuthFailed(): void
    {
        // lib.php rejects control-plane requests with its own {error, code}
        // envelope before any endpoint runs; the client must read that as
        // an auth failure, not an unexpected response.
        $envelope = static function (): array {
            return [
                'http_code' => 403,
                'body' => '{"error":"HMAC signature verification failed","code":403}',
                'error' => null,
            ];
        };
        $client = $this->makeClient($envelope);
        $this->writeSource('bytes');

        $probe = $client->apply('.manifest.jsonl', true);
        $this->assertSame(['failed', 'auth_failed'], [$probe['status'], $probe['reason']]);

        $upload = $client->upload_artifact('artifact.bin', $this->source_path);
        $this->assertSame(['failed', 'auth_failed'], [$upload['status'], $upload['reason']]);
    }

    public function testWrongSecretFailsFastWithoutRetries(): void
    {
        $this->writeSource('some bytes');
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()), [
            'hmac_client' => new Site_Export_HMAC_Client('not-the-target-secret'),
        ]);

        $result = $client->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame(['failed', 'auth_failed'], [$result['status'], $result['reason']]);
        $this->assertCount(1, $this->uploadCalls(), 'auth failures must not be retried');
    }

    public function testSourceRewrittenMidUploadFailsTyped(): void
    {
        $body = str_repeat('abcd', 8); // 32 bytes: several 8-byte chunks
        $this->writeSource($body);
        $base = $this->transportFor($this->makeEndpoints());
        $rewritten = false;
        $transport = function (...$args) use ($base, &$rewritten): array {
            $response = $base(...$args);
            if (!$rewritten && strpos($args[1], 'staged_upload') !== false) {
                $rewritten = true;
                // Same length, new content, future mtime — invisible to
                // every byte-count check in the pipeline.
                file_put_contents($this->source_path, str_repeat('zzzz', 8));
                touch($this->source_path, time() + 10);
            }
            return $response;
        };
        $client = $this->makeClient($transport);

        $result = $client->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame(['failed', 'source_changed'], [$result['status'], $result['reason']]);
        $this->assertFalse(
            $client->status('artifact.bin')['exists'],
            'torn staged bytes must be discarded, never verified'
        );
    }

    public function testStalePlanMtimeFailsBeforeAnyUpload(): void
    {
        $this->writeSource('current content');
        // Remnants of the older version sit staged on the target.
        (new Site_Export_Staged_Artifacts($this->staging_dir))->append('artifact.bin', 0, 'old-');
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $result = $client->upload_artifact(
            'artifact.bin',
            $this->source_path,
            null,
            null,
            ((int) filemtime($this->source_path)) - 100
        );

        $this->assertSame(['failed', 'source_changed'], [$result['status'], $result['reason']]);
        $this->assertCount(0, $this->uploadCalls(), 'a stale plan must not upload');
        $this->assertFalse($client->status('artifact.bin')['exists'], 'stale remnants are discarded');
    }

    public function testTransportHardFailureStopsBounded(): void
    {
        $this->writeSource(str_repeat('x', 32));
        $transport = function (...$args): array {
            parse_str((string) parse_url($args[1], PHP_URL_QUERY), $params);
            if (($params['endpoint'] ?? '') === 'staged_status') {
                return [
                    'http_code' => 200,
                    'body' => '{"exists":false,"committed_bytes":0,"verified":false}',
                    'error' => null,
                ];
            }
            $this->requests[] = ['endpoint' => (string) $params['endpoint'], 'params' => $params];
            return ['http_code' => 0, 'body' => '', 'error' => 'connection refused'];
        };

        $result = $this->makeClient($transport)->upload_artifact('artifact.bin', $this->source_path);

        $this->assertSame(['failed', 'transport_failed'], [$result['status'], $result['reason']]);
        $this->assertLessThanOrEqual(
            6,
            count($this->uploadCalls()),
            'bounded by the sizer floor and the consecutive-failure cap'
        );
    }

    public function testSourceShorterThanDeclaredTotalFails(): void
    {
        $this->writeSource('only-ten-b');
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $result = $client->upload_artifact('artifact.bin', $this->source_path, 20);

        $this->assertSame(['failed', 'source_short'], [$result['status'], $result['reason']]);
    }

    public function testMissingSourceFileFails(): void
    {
        $client = $this->makeClient($this->transportFor($this->makeEndpoints()));

        $result = $client->upload_artifact('artifact.bin', $this->source_path . '-missing');

        $this->assertSame(['failed', 'source_unreadable'], [$result['status'], $result['reason']]);
    }

    // ---------------------------------------------------------------
    // Status and discard passthrough
    // ---------------------------------------------------------------

    public function testStatusAndDiscardRoundTrip(): void
    {
        $body = 'discard-me';
        $this->writeSource($body);
        $transport = $this->transportFor($this->makeEndpoints());
        $client = $this->makeClient($transport);
        $client->upload_artifact('artifact.bin', $this->source_path);

        $status = $client->status('artifact.bin');
        $this->assertSame(['ok', true, true], [$status['status'], $status['exists'], $status['verified']]);
        $this->assertSame(strlen($body), $status['committed_bytes']);

        $this->assertSame('discarded', $client->discard('artifact.bin')['status']);
        $this->assertFalse($client->status('artifact.bin')['exists']);
    }
}
