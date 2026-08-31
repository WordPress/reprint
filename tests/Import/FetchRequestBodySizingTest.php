<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\State\FetchListProgressState;
use Reprint\Importer\StreamingContext;
use Reprint\Importer\Tuning\AdaptiveTuner;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * The JSON path list uploaded to file_fetch is the only request body a pull
 * sends, and a web server in front of the source can refuse it for being too
 * large. Reprint sizes that body from limits PHP reports, which say nothing
 * about what the server ahead of PHP will accept, so the size has to be able
 * to come down and a batch already written at the old size has to be rebuilt.
 */
class FetchRequestBodySizingTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystemRoot;
    private $fetchListFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fetch-body-sizing-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->pullStateDirectory =
            $this->stateDir . '/remotes/' . md5('http://fake.url') . '/pull';
        $this->filesystemRoot = $this->tempDir . '/fs-root';
        mkdir($this->pullStateDirectory, 0755, true);
        mkdir($this->filesystemRoot, 0755, true);

        $this->fetchListFile = $this->pullStateDirectory . '/fetch-list.jsonl';
        $lines = '';
        for ($i = 0; $i < 4000; $i++) {
            $path = sprintf('/var/www/web/wp-content/uploads/2026/01/file-%05d.jpg', $i);
            $lines .= json_encode(['path' => base64_encode($path)]) . "\n";
        }
        file_put_contents($this->fetchListFile, $lines);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Serve one canned HTTP response from a forked child.
     *
     * @return array{0: string, 1: int} Remote URL and the child's pid.
     */
    private function startJsonServer(string $response): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, (string) $errstr);
        $address = stream_socket_get_name($listener, false);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($listener, 5);
            if ($connection !== false) {
                stream_set_timeout($connection, 5);
                $request = '';
                while (strpos($request, "\r\n\r\n") === false) {
                    $piece = fread($connection, 8192);
                    if ($piece === false || $piece === '') {
                        break;
                    }
                    $request .= $piece;
                }
                fwrite($connection, $response);
                fclose($connection);
            }
            fclose($listener);
            exit(0);
        }
        fclose($listener);

        return ['http://' . $address . '/?site-export-api', $pid];
    }

    /**
     * @return array{0: \ImportClient, 1: \ReflectionClass}
     */
    private function prepareClient(
        int $requestBodyBudget,
        array $fetchState = [],
        bool $adaptiveTuningEnabled = true
    ): array
    {
        $client = new BatchCapturingClient(
            'http://fake.url',
            $this->stateDir,
            $this->filesystemRoot,
        );
        \write_current_pull_state($client, [
            'preflight' => ['data' => ['ok' => true], 'http_code' => 200],
            'fetch' => array_merge(
                (new FetchListProgressState())->to_array(),
                $fetchState,
            ),
        ]);

        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client),
        );
        $reflection->getProperty('is_tty')->setValue($client, false);
        $reflection->getProperty('tuner')->setValue($client, new AdaptiveTuner([
            'enabled' => $adaptiveTuningEnabled,
            'file_fetch_request_body_max' => $requestBodyBudget,
            'file_fetch_request_body_min' => 1024,
        ]));

        return [$client, $reflection];
    }

    private function writeBatchFile(int $bytes): string
    {
        $path = $this->tempDir . '/batch-' . uniqid() . '.json';
        file_put_contents($path, '[' . str_repeat('x', max(0, $bytes - 2)) . ']');

        return $path;
    }

    public function testBatchIsBuiltWithinTheRequestBodyBudget(): void
    {
        [$client, $reflection] = $this->prepareClient(64 * 1024);

        $batch = $reflection->getMethod('prepare_fetch_batch')
            ->invoke($client, $this->fetchListFile, 0);

        $this->assertNotNull($batch);
        $this->assertGreaterThan(0, $batch['entries']);
        $this->assertLessThanOrEqual(64 * 1024, filesize($batch['file']));
        // The list is far longer than one budget's worth, so this has to be a
        // partial batch rather than the whole thing squeaking under the limit.
        $this->assertLessThan(filesize($this->fetchListFile), $batch['next_offset']);
    }

    public function testAnOversizedBatchIsRebuiltFromTheSamePlaceInTheList(): void
    {
        // A batch outlives many requests: the exporter streams what its
        // per-request budget allows, returns a cursor, and the same body goes
        // back up next time. After a 413 drops the budget, that batch has to be
        // rebuilt — and rebuilding must not step over anything it had reached,
        // which holds because fetch.offset only advances on batch completion.
        $lines = file($this->fetchListFile);
        $offsetOfLine100 = strlen(implode('', array_slice($lines, 0, 100)));
        $expectedFirstPath = json_decode($lines[100], true)['path'];

        $stale = $this->writeBatchFile(512 * 1024);
        [$client, $reflection] = $this->prepareClient(64 * 1024, [
            'offset' => $offsetOfLine100,
            'batch_file' => $stale,
            'batch_entries' => 3900,
            'next_offset' => filesize($this->fetchListFile),
            'cursor' => 'cursor-from-a-completed-part',
        ]);
        $state = $reflection->getMethod('get_state')->invoke($client);
        $state->current_file = $this->filesystemRoot . '/partly-written.bin';
        $state->current_file_bytes = 4096;

        $reflection->getMethod('fetch_files_from_list')
            ->invoke($client, $this->fetchListFile);

        $this->assertFileDoesNotExist($stale);
        $this->assertLessThanOrEqual(64 * 1024, $client->sentBatchBytes);
        $this->assertSame($offsetOfLine100, $state->fetch->offset);

        $rebuilt = json_decode(file_get_contents($client->sentBatchFile), true);
        $this->assertSame($expectedFirstPath, $rebuilt[0]['path']);

        // Left set, fetch_file_batch() reopens that path in append mode and
        // resumes writing into it for a batch that no longer contains it.
        $this->assertNull($client->currentFileAtSend);
    }

    public function testA413RebuildsTheNextBatchWhenAdaptiveTuningIsDisabled(): void
    {
        $lines = file($this->fetchListFile);
        $offsetOfLine100 = strlen(implode('', array_slice($lines, 0, 100)));
        $expectedFirstPath = json_decode($lines[100], true)['path'];

        $stale = $this->writeBatchFile(128 * 1024);
        [$client, $reflection] = $this->prepareClient(128 * 1024, [
            'offset' => $offsetOfLine100,
            'batch_file' => $stale,
            'batch_entries' => 3900,
            'next_offset' => filesize($this->fetchListFile),
            'cursor' => 'cursor-from-a-completed-part',
        ], false);

        $reflection->getMethod('handle_tuner_error')->invoke($client, 'file_fetch', [
            'http_code' => 413,
            'timeout' => false,
            'curl_errno' => 0,
            'final_attempt' => false,
        ]);
        $reflection->getMethod('fetch_files_from_list')
            ->invoke($client, $this->fetchListFile);

        $this->assertLessThanOrEqual(64 * 1024, $client->sentBatchBytes);
        $state = $reflection->getMethod('get_state')->invoke($client);
        $this->assertSame($offsetOfLine100, $state->fetch->offset);

        $rebuilt = json_decode(file_get_contents($client->sentBatchFile), true);
        $this->assertSame($expectedFirstPath, $rebuilt[0]['path']);
    }

    public function testAShrunkBudgetSurvivesIntoTheNextInvocationWhenAdaptiveTuningIsDisabled(): void
    {
        // files-pull can make several requests before it exits. A budget that
        // did not survive a partial response would resend the same oversized
        // body on the next request and never recover.
        $reflection = new \ReflectionClass(\ImportClient::class);
        $loadState = $reflection->getMethod('load_state');
        $initTuner = $reflection->getMethod('initialize_tuner');
        $tunerProperty = $reflection->getProperty('tuner');

        $first = new \ImportClient('http://fake.url', $this->stateDir, $this->filesystemRoot);
        \write_current_pull_state($first, [
            'preflight' => [
                // Under the hard cap, so this value is what has to reach the
                // tuner — above it the clamp result is indistinguishable from
                // the default and the seeding would go unverified.
                'data' => ['limits' => ['max_request_bytes' => 655360]],
                'http_code' => 200,
            ],
        ]);
        $reflection->getProperty('state')->setValue($first, $loadState->invoke($first));
        $initTuner->invoke($first, [
            'tuning_config' => ['enabled' => false],
        ]);

        // 640 KiB reported, x0.8, under the cap so it stands as-is.
        $this->assertSame(
            524288,
            $tunerProperty->getValue($first)->get_request_body_budget('file_fetch'),
        );

        $reflection->getMethod('handle_tuner_error')->invoke($first, 'file_fetch', [
            'http_code' => 413,
            'timeout' => false,
            'curl_errno' => 0,
            'final_attempt' => false,
        ]);
        $first->save_state();

        $second = new \ImportClient('http://fake.url', $this->stateDir, $this->filesystemRoot);
        $reflection->getProperty('state')->setValue($second, $loadState->invoke($second));
        $initTuner->invoke($second, [
            'tuning_config' => ['enabled' => false],
        ]);

        $this->assertSame(
            262144,
            $tunerProperty->getValue($second)->get_request_body_budget('file_fetch'),
        );
    }

    public function testRunningPreflightTightensTheBoundInTheSameRun(): void
    {
        // initialize_tuner() runs before run_preflight(), so until preflight
        // reports, the bound comes from the fallback default. A source that
        // accepts less than the cap has to take effect in the run that learns
        // it: exceeding upload_max_filesize is rejected by PHP as a bad file
        // part, not as a 413, so nothing downstream would recover from it.
        $payload = json_encode([
            'ok' => true,
            'protocol_version' => 3,
            // upload_max_filesize=512K caps the source below the hard ceiling.
            'limits' => ['max_request_bytes' => 524288],
        ]);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;

        [$url, $pid] = $this->startJsonServer($response);

        $reflection = new \ReflectionClass(\ImportClient::class);
        $client = new \ImportClient($url, $this->stateDir, $this->filesystemRoot);
        \write_current_pull_state($client, []);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client),
        );
        $reflection->getProperty('is_tty')->setValue($client, false);
        $reflection->getMethod('initialize_tuner')->invoke($client, []);

        $tunerProperty = $reflection->getProperty('tuner');
        $this->assertSame(
            800 * 1024,
            $tunerProperty->getValue($client)->get_request_body_budget('file_fetch'),
        );

        $client->run_preflight();
        pcntl_waitpid($pid, $status);

        $this->assertSame(
            419430,
            $tunerProperty->getValue($client)->get_request_body_budget('file_fetch'),
        );
    }

    public function testApplyingAReportedLimitKeepsWhatTheTunerAlreadyLearned(): void
    {
        // preflight lands after a rejection has already forced the budget down
        // — on a resumed run, where the shrink came back from state. Raising it
        // to the newly reported bound would resend the body that was refused.
        $reflection = new \ReflectionClass(\ImportClient::class);
        $client = new \ImportClient('http://fake.url', $this->stateDir, $this->filesystemRoot);
        \write_current_pull_state($client, [
            'preflight' => [
                'data' => ['limits' => ['max_request_bytes' => 8388608]],
                'http_code' => 200,
            ],
        ]);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client),
        );
        $reflection->getMethod('initialize_tuner')->invoke($client, []);

        $reflection->getMethod('handle_tuner_error')->invoke($client, 'file_fetch', [
            'http_code' => 413,
            'timeout' => false,
            'curl_errno' => 0,
            'final_attempt' => false,
        ]);

        $tuner = $reflection->getProperty('tuner')->getValue($client);
        $this->assertSame(400 * 1024, $tuner->get_request_body_budget('file_fetch'));

        $reflection->getMethod('apply_reported_request_body_limit')->invoke($client);

        $this->assertSame(400 * 1024, $tuner->get_request_body_budget('file_fetch'));
    }

    public function testFinalAttemptIsReachedOneRequestBeforeTheLimit(): void
    {
        [$client, $reflection] = $this->prepareClient(64 * 1024);
        $isFinal = $reflection->getMethod('is_final_resume_attempt');
        $state = $reflection->getMethod('get_state')->invoke($client);

        // MAX_CONSECUTIVE_INTERRUPTED_RESPONSES is 3, and the failure being
        // handled has not been counted yet: at a stored count of 1 exactly one
        // request remains, so that is the last chance to pick a working size.
        $state->consecutive_interrupted_responses = 0;
        $this->assertFalse($isFinal->invoke($client));

        $state->consecutive_interrupted_responses = 1;
        $this->assertTrue($isFinal->invoke($client));
    }
}

/**
 * Records the batch handed to file_fetch instead of sending it.
 */
class BatchCapturingClient extends \ImportClient
{
    public $sentBatchFile = null;
    public $sentBatchBytes = null;
    public $currentFileAtSend = false;

    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $file = $post_data['file_list']->getFilename();
        $this->sentBatchFile = $file;
        $this->sentBatchBytes = filesize($file);

        // Sampled here rather than after the call: fetch_file_batch() clears
        // its own file tracking once the request finishes with no open handle,
        // which would mask whether the discarded batch cleared it.
        $this->currentFileAtSend = (new \ReflectionClass(\ImportClient::class))
            ->getMethod('get_state')
            ->invoke($this)
            ->current_file;
    }
}
