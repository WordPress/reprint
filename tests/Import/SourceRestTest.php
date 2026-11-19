<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * The source rests after every response, and the client waits that rest out
 * before its next request, in this process or a later one.
 */
class SourceRestTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/source-rest-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_directory($this->root);
    }

    public function testACompleteResponseLeavesItsRestForTheNextRequest(): void {
        $client = $this->client_with_tuner('gentle');

        $started = microtime(true);
        $this->finish_request($client, 'complete', 0.3);
        $this->assertLessThan(0.2, microtime(true) - $started, 'The client must not rest right after the response.');

        $rest_until = $client->get_state()->source_rest_until;
        $this->assertNotNull($rest_until, 'A complete response earns rest like a partial one.');
        $this->assertEqualsWithDelta(microtime(true) + 0.3, $rest_until, 0.1);
    }

    public function testANewProcessWaitsOutTheSavedRestBeforeItsFirstRequest(): void {
        $client = $this->client_with_tuner('gentle');
        $this->finish_request($client, 'complete', 0.4);
        $client->save_state();

        // The next pull stage runs in a new process and sends its first request.
        $next_process = $this->client_with_tuner('gentle');
        $started = microtime(true);
        $this->send_request_to_closed_port($next_process, 'streaming');
        $this->assertGreaterThan(0.3, microtime(true) - $started, 'The first request must wait out the saved rest.');

        $started = microtime(true);
        $this->send_request_to_closed_port($next_process, 'json');
        $this->assertLessThan(0.2, microtime(true) - $started, 'The rest is waited out once.');
    }

    public function testJsonRequestsAlsoWaitOutTheRest(): void {
        $client = $this->client_with_tuner('gentle');
        $this->finish_request($client, 'partial', 0.4);

        $started = microtime(true);
        $this->send_request_to_closed_port($client, 'json');
        $this->assertGreaterThan(0.3, microtime(true) - $started);
    }

    public function testFullSourceLoadNeverRestsAndDoesNotCarryOver(): void {
        $client = $this->client_with_tuner('full');
        $this->finish_request($client, 'partial', 2.0);
        $this->assertNull($client->get_state()->source_rest_until, 'Full source load sends the next request at once.');
        $client->save_state();

        // A later invocation without --source-load=full is gentle again.
        $gentle = $this->client_with_tuner('gentle');
        $this->finish_request($gentle, 'partial', 0.3);
        $this->assertNotNull($gentle->get_state()->source_rest_until);
    }

    public function testRunRejectsAnUnknownSourceLoad(): void {
        $client = new \ImportClient('http://127.0.0.1:9/', $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --source-load value: fast. Valid values: gentle, full');
        $client->run(['command' => 'files-pull', 'source_load' => 'fast']);
    }

    /** Builds a client whose tuner was initialised for this invocation's source load. */
    private function client_with_tuner(string $source_load): \ImportClient {
        $client = new \ImportClient('http://127.0.0.1:9/', $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue($client, $reflection->getMethod('load_state')->invoke($client));
        $reflection->getProperty('source_load')->setValue($client, $source_load);
        $reflection->getMethod('initialize_tuner')->invoke($client, ['tuning_config' => ['min_sleep' => 0.0]]);
        return $client;
    }

    /** Reports a finished file_fetch response whose source worked for $server_time seconds. */
    private function finish_request(\ImportClient $client, string $status, float $server_time): void {
        ( new \ReflectionClass(\ImportClient::class) )->getMethod('finalize_tuned_request')->invoke(
            $client,
            'file_fetch',
            $server_time,
            ['server_time' => $server_time, 'status' => $status, 'bytes_processed' => 1024]
        );
    }

    /** Sends a request to a closed port, which fails at once, so its duration is the wait before it. */
    private function send_request_to_closed_port(\ImportClient $client, string $kind): void {
        $reflection = new \ReflectionClass(\ImportClient::class);
        try {
            if ($kind === 'json') {
                $reflection->getMethod('fetch_json')->invoke($client, 'http://127.0.0.1:9/', []);
            } else {
                $reflection->getMethod('fetch_streaming')->invoke($client, 'http://127.0.0.1:9/', new \Reprint\Importer\StreamingContext(), [], 'file_fetch');
            }
        } catch (\RuntimeException $error) {
            // The refused connection is expected.
        }
    }

    private function remove_directory(string $directory): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
