<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-importer/src/import.php';
require_once __DIR__ . '/../packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php';

final class PushEndpointsTest extends TestCase {

    private const SECRET = 'real-push-endpoint-test-secret';

    /** @var resource|null */
    private $server_process;

    private string $root;
    private string $docroot;
    private string $reprint_directory;
    private string $base_url;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Push endpoint E2E requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $this->root = sys_get_temp_dir() . '/push-endpoints-' . bin2hex(random_bytes(6));
        $this->docroot = $this->root . '/docroot';
        $this->reprint_directory = $this->root . '/reprint';
        mkdir($this->docroot, 0700, true);
        mkdir($this->reprint_directory, 0700, true);
        file_put_contents($this->docroot . '/remove.txt', 'old');
        mkdir($this->docroot . '/preserved');
        file_put_contents($this->docroot . '/preserved/value.txt', 'keep');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = realpath(__DIR__ . '/fixtures/push-endpoint-router.php');
        $this->assertNotFalse($router);
        $environment = array_merge($_ENV, [
            'REPRINT_PUSH_TEST_SECRET' => self::SECRET,
            'REPRINT_PUSH_TEST_DOCROOT' => $this->docroot,
            'REPRINT_PUSH_TEST_DIRECTORY' => $this->reprint_directory,
        ]);
        $server_log = $this->root . '/server.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $server_log, 'a'],
            2 => ['file', $server_log, 'a'],
        ];
        $process = proc_open([PHP_BINARY, '-d', 'post_max_size=1M', '-S', $address, $router], $descriptors, $pipes, dirname($router), $environment);
        $this->assertIsResource($process);
        $this->server_process = $process;
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $connect_error, $connect_error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $this->base_url = 'http://' . $address . '/?reprint-api=1';
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Push endpoint test server did not start: ' . file_get_contents($server_log));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testSignedEndpointsReceiveManyChangesCommitAndRemove(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('a', 32);

        $create = $client->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame([
            'status' => 'created',
            'push_session_id' => $push_session_id,
            'max_part_bytes' => 64,
            'post_max_bytes' => 1048576,
            'http_code' => 200,
        ], $create['response']);
        $client->set_max_part_bytes($create['response']['max_part_bytes']);
        $client->apply_reported_limits([$create['response']['post_max_bytes']]);

        $this->assertTrue($client->start_upload_request($push_session_id));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 0,
            'payload' => "ab\0c",
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 4,
            'payload' => 'defg',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'directory',
            'path' => 'empty-directory',
            'payload' => '',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'symlink',
            'path' => 'file-link',
            'target' => 'nested/file.bin',
            'payload' => '',
        ]));
        $delete_payload = "remove.txt\0";
        $delete_offset = 0;
        foreach (str_split($delete_payload, 4) as $delete_piece) {
            $this->assertTrue($client->send_part([
                'type' => 'delete-list',
                'offset' => $delete_offset,
                'payload' => $delete_piece,
            ]));
            $delete_offset += strlen($delete_piece);
        }
        $this->assertTrue($client->send_part([
            'type' => 'delete-list',
            'offset' => $delete_offset,
            'complete' => true,
            'payload' => '',
        ]));
        $upload = $client->finish_request();
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));
        $this->assertSame(8, $upload['response']['changes_accepted']);
        $this->assertSame([
            'state' => 'complete',
            'type' => 'delete-list',
            'accepted_bytes' => strlen($delete_payload),
        ], $upload['response']['last_change']);

        $status = $client->control_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
            'path_b64' => base64_encode('nested/file.bin'),
        ], ['accepted']);
        $this->assertSame('complete', $status['status'], (string) json_encode($status));
        $this->assertSame('receiving_work', $status['response']['phase']);
        $this->assertSame(strlen($delete_payload), $status['response']['work_deletes_bytes']);
        $this->assertTrue($status['response']['work_deletes_complete']);
        $this->assertSame([
            'path_b64' => base64_encode('nested/file.bin'),
            'state' => 'complete',
            'type' => 'file',
            'accepted_bytes' => 8,
        ], $status['response']['path']);

        $commit_requests = 0;
        do {
            $commit = $client->control_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit['status'], (string) json_encode($commit));
            ++$commit_requests;
        } while ($commit['response']['send_next_request']);

        $this->assertGreaterThan(1, $commit_requests, 'The one-entry endpoint budget must require repeated commit calls.');
        $this->assertSame('complete', $commit['response']['phase']);
        $this->assertFileDoesNotExist($this->docroot . '/remove.txt');
        $this->assertSame("ab\0cdefg", file_get_contents($this->docroot . '/nested/file.bin'));
        $this->assertDirectoryExists($this->docroot . '/empty-directory');
        $this->assertSame([], array_values(array_diff(scandir($this->docroot . '/empty-directory') ?: [], ['.', '..'])));
        $this->assertTrue(is_link($this->docroot . '/file-link'));
        $this->assertSame('nested/file.bin', readlink($this->docroot . '/file-link'));
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));

        do {
            $remove = $client->control_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $remove['status'], (string) json_encode($remove));
        } while (!$remove['response']['removed']);
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    public function testEndpointGuardsRejectWrongMethodContentTypeAndAuthentication(): void
    {
        $push_session_id = str_repeat('b', 32);
        $client = $this->newClient(self::SECRET);

        $wrong_method = $client->control_request('GET', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $wrong_method['status']);
        $this->assertSame('invalid_request', $wrong_method['reason']);
        $this->assertSame('Push endpoint requires POST; observed GET.', $wrong_method['detail']);

        $wrong_secret = $this->newClient('not-the-server-secret');
        $authentication = $wrong_secret->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $authentication['status']);
        $this->assertSame('auth_failed', $authentication['reason']);
        $this->assertStringContainsString('HMAC signature verification failed', $authentication['detail']);

        $create = $client->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status']);
        $push_lock = fopen($this->reprint_directory . '/.reprint/push/' . $push_session_id . '/push.lock', 'c+b');
        $this->assertIsResource($push_lock);
        $this->assertTrue(flock($push_lock, LOCK_EX | LOCK_NB));
        $lock_contention = $client->control_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
        ], ['accepted']);
        $this->assertSame('retry', $lock_contention['status']);
        $this->assertSame('lock_acquisition_failure', $lock_contention['reason']);
        flock($push_lock, LOCK_UN);
        fclose($push_lock);

        $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart/mixed', $response['detail']);

        $boundary = 'truncated-endpoint-test';
        $truncated_body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('truncated.txt') . "\r\n"
            . "X-File-Size: 1\r\n"
            . "X-Chunk-Offset: 0\r\n"
            . "Content-Length: 1\r\n\r\n"
            . 'x';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: multipart/mixed; boundary=' . $boundary];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $truncated_body,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart body ended before', $response['detail']);
    }

    /**
     * Completes a mixed-value push while reconstructing the sender each step.
     *
     * The first upload request carries multiple work values and multiple
     * bounded chunks. The large file then changes behind a partial receiver
     * cursor, forcing an offset-zero restart before repeated commit installs
     * the file, empty directory, symlink, replacement, and deletion.
     */
    public function testHighLevelSenderRestartsChangedSourceAndResumesAfterEveryRequest(): void
    {
        $local_docroot = $this->root . '/local-docroot';
        mkdir($local_docroot . '/nested', 0700, true);
        mkdir($local_docroot . '/empty-directory', 0700, true);
        file_put_contents($local_docroot . '/nested/large.bin', str_repeat('A', 2000));
        file_put_contents($local_docroot . '/same-size.txt', 'new!');
        symlink('nested/large.bin', $local_docroot . '/file-link');
        file_put_contents($this->docroot . '/same-size.txt', 'old!');

        $large_identity = lstat($local_docroot . '/nested/large.bin');
        $same_size_identity = lstat($local_docroot . '/same-size.txt');
        $link_identity = lstat($local_docroot . '/file-link');
        $nested_identity = lstat($local_docroot . '/nested');
        $empty_identity = lstat($local_docroot . '/empty-directory');
        $this->assertIsArray($large_identity);
        $this->assertIsArray($same_size_identity);
        $this->assertIsArray($link_identity);
        $this->assertIsArray($nested_identity);
        $this->assertIsArray($empty_identity);
        $current_index = $this->root . '/current-index.jsonl';
        $this->writeIndex($current_index, [
            'empty-directory' => [ (int) $empty_identity['ctime'], (int) $empty_identity['size'], 'dir'],
            'file-link' => [ (int) $link_identity['ctime'], (int) $link_identity['size'], 'link'],
            'nested' => [ (int) $nested_identity['ctime'], (int) $nested_identity['size'], 'dir'],
            'nested/large.bin' => [ (int) $large_identity['ctime'], (int) $large_identity['size'], 'file'],
            'same-size.txt' => [ (int) $same_size_identity['ctime'], (int) $same_size_identity['size'], 'file'],
        ]);

        $journal = new PushJournal($this->root . '/sender-state', $this->base_url);
        $baseline = $this->root . '/baseline-index.jsonl';
        $this->writeIndex($baseline, [
            'remove.txt' => [1, 3, 'file'],
            'same-size.txt' => [1, 4, 'file'],
        ]);
        $journal->capture_local_files_baseline($baseline);

        $source_changed = false;
        $observed_many_chunks = false;
        $observed_many_values = false;
        $commit_requests = 0;
        $removed_caller_index = false;
        for ($step = 0; $step < 200; ++$step) {
            // A new object on every step proves that sender.json, target
            // status, the source token, the learned request size, and the
            // target part limit are sufficient after process restart.
            $state_before_request = $journal->read_sender_state();
            $is_first_upload_request = is_array($state_before_request)
                && $state_before_request['phase'] === 'uploading_work'
                && $state_before_request['current_path_b64'] === null
                && $state_before_request['paths_byte_offset'] === 0;
            $sender = $this->newSender($local_docroot, $current_index, $journal);
            $result = $sender->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $journal->read_sender_state();
            if (!$removed_caller_index && is_array($state)) {
                unlink($current_index);
                $removed_caller_index = true;
            }
            if (is_array($state) && $state['phase'] === 'committing') {
                ++$commit_requests;
            }
            if ($is_first_upload_request) {
                $this->assertIsArray($state);
                $this->assertSame(base64_encode('nested/large.bin'), $state['current_path_b64']);
                $this->assertGreaterThan(64, $state['confirmed_bytes']);
                $this->assertLessThan(2000, $state['confirmed_bytes']);
                $work_files = $this->reprint_directory . '/.reprint/push/' . $state['push_session_id'] . '/work/files';
                $this->assertDirectoryExists($work_files . '/empty-directory');
                $this->assertTrue(is_link($work_files . '/file-link'));
                $observed_many_chunks = true;
                $observed_many_values = true;
                sleep(1);
                file_put_contents($local_docroot . '/nested/large.bin', str_repeat('B', 2000));
                clearstatcache(true, $local_docroot . '/nested/large.bin');
                $source_changed = true;
            }
            if ($result['status'] !== 'continue') {
                break;
            }
        }

        $this->assertTrue($observed_many_chunks, 'One sender request must confirm more than one bounded file chunk.');
        $this->assertTrue($observed_many_values, 'That same sender request must publish multiple positive-work values before the partial file.');
        $this->assertTrue($removed_caller_index, 'The active sender must resume from its stable journal index after the caller index disappears.');
        $this->assertTrue($source_changed, 'The test must edit the source while the receiver has a partial file.');
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertGreaterThan(1, $commit_requests, 'The one-entry endpoint budget must require repeated high-level commit calls.');
        $this->assertSame(str_repeat('B', 2000), file_get_contents($this->docroot . '/nested/large.bin'));
        $this->assertSame('new!', file_get_contents($this->docroot . '/same-size.txt'));
        $this->assertDirectoryExists($this->docroot . '/empty-directory');
        $this->assertTrue(is_link($this->docroot . '/file-link'));
        $this->assertSame('nested/large.bin', readlink($this->docroot . '/file-link'));
        $this->assertFileDoesNotExist($this->docroot . '/remove.txt');
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));
        $this->assertNull($journal->read_sender_state());
        $this->assertSame(
            file_get_contents($journal->sender_index_path),
            file_get_contents($journal->local_files_baseline_path),
            'The stable start-of-push index becomes the baseline; a mid-push source edit is sent but remains detectable on the next diff.'
        );
    }

    /**
     * Restarts every source-drift class from an existing partial file.
     *
     * Growth, shrinkage past the receiver cursor, same-size replacement, and
     * a file-to-directory change must all replace the old prefix rather than
     * append bytes from a different source value.
     */
    public function testHighLevelSenderRestartsGrownShrunkSameSizeAndTypeChangedFiles(): void
    {
        $variants = [
            'grew' => static function (string $path): void {
                file_put_contents($path, str_repeat('G', 2500));
            },
            'shrank' => static function (string $path): void {
                file_put_contents($path, str_repeat('S', 100));
            },
            'same-size' => static function (string $path): void {
                file_put_contents($path, str_repeat('E', 2000));
            },
            'type-change' => static function (string $path): void {
                unlink($path);
                mkdir($path, 0700);
                file_put_contents($path . '/appeared-after-index.txt', 'later');
            },
        ];

        foreach ($variants as $name => $change_source) {
            $local_docroot = $this->root . '/drift-' . $name;
            mkdir($local_docroot, 0700, true);
            $relative_path = 'value-' . $name;
            $source_path = $local_docroot . '/' . $relative_path;
            file_put_contents($source_path, str_repeat('O', 2000));
            $identity = lstat($source_path);
            $this->assertIsArray($identity);
            $current_index = $this->root . '/drift-' . $name . '.jsonl';
            $this->writeIndex($current_index, [
                $relative_path => [ (int) $identity['ctime'], (int) $identity['size'], 'file'],
            ]);
            $journal = new PushJournal($this->root . '/drift-state-' . $name, $this->base_url);

            for ($step = 0; $step < 100; ++$step) {
                $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
                $this->assertNotSame('failed', $result['status'], $name . ': ' . json_encode($result));
                $state = $journal->read_sender_state();
                if (
                    is_array($state)
                    && $state['current_path_b64'] === base64_encode($relative_path)
                    && $state['confirmed_bytes'] > 64
                    && $state['confirmed_bytes'] < 2000
                ) {
                    break;
                }
            }
            $this->assertIsArray($state, $name);
            $this->assertGreaterThan(64, $state['confirmed_bytes'], $name);
            sleep(1);
            $change_source($source_path);
            clearstatcache(true, $source_path);

            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertSame('continue', $result['status'], $name . ': ' . json_encode($result));
            $this->assertSame('source_changed', $result['reason'], $name);
            $restarted_state = $journal->read_sender_state();
            $this->assertIsArray($restarted_state, $name);
            $this->assertSame('uploading_work', $restarted_state['phase'], $name);
            $this->assertSame($state['source_token'], $restarted_state['source_token'], $name);
            $this->assertSame($state['confirmed_bytes'], $restarted_state['confirmed_bytes'], $name);
            if ($name === 'shrank') {
                $shrunken_identity = lstat($source_path);
                $this->assertIsArray($shrunken_identity);
                $this->assertGreaterThan(
                    $shrunken_identity['size'],
                    $state['confirmed_bytes'],
                    'The target cursor must begin beyond the shrunken source EOF.'
                );
            }

            for (++$step; $step < 200; ++$step) {
                $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
                $this->assertNotSame('failed', $result['status'], $name . ': ' . json_encode($result));
                if ($result['status'] !== 'continue') {
                    break;
                }
            }
            $this->assertSame('complete', $result['status'], $name . ': ' . json_encode($result));
            $target_path = $this->docroot . '/' . $relative_path;
            if ($name === 'type-change') {
                $this->assertFileExists($source_path . '/appeared-after-index.txt');
                $this->assertDirectoryExists($target_path);
                $this->assertSame([], array_values(array_diff(scandir($target_path) ?: [], ['.', '..'])));
            } else {
                $this->assertSame(file_get_contents($source_path), file_get_contents($target_path), $name);
            }
        }
    }

    /**
     * Removes the upload-only push session when its selected source vanishes.
     *
     * The remove response is discarded to prove that a reconstructed sender
     * repeats bounded removal before asking the caller for a fresh index.
     */
    public function testHighLevelSenderRemovesUploadOnlySessionWhenCurrentSourceDisappears(): void
    {
        $local_docroot = $this->root . '/deleted-source';
        mkdir($local_docroot, 0700, true);
        $relative_path = 'deleted-source.bin';
        $source_path = $local_docroot . '/' . $relative_path;
        file_put_contents($source_path, str_repeat('D', 2000));
        $identity = lstat($source_path);
        $this->assertIsArray($identity);
        $current_index = $this->root . '/deleted-source.jsonl';
        $this->writeIndex($current_index, [
            $relative_path => [ (int) $identity['ctime'], (int) $identity['size'], 'file'],
        ]);
        $journal = new PushJournal($this->root . '/deleted-source-state', $this->base_url);

        for ($step = 0; $step < 100; ++$step) {
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $journal->read_sender_state();
            if (is_array($state) && $state['confirmed_bytes'] > 64 && $state['confirmed_bytes'] < 2000) {
                break;
            }
        }
        $this->assertIsArray($state);
        $push_session_id = $state['push_session_id'];
        unlink($source_path);
        clearstatcache(true, $source_path);

        $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
        $this->assertSame('continue', $result['status'], (string) json_encode($result));
        $state = $journal->read_sender_state();
        $this->assertIsArray($state);
        $this->assertSame('removing', $state['phase']);
        $this->sendPostAndDiscardResponse(
            $this->base_url . '&endpoint=push_remove&push_session_id=' . $push_session_id,
            null,
            ''
        );

        for (; $step < 200; ++$step) {
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            if ($result['status'] !== 'continue') {
                break;
            }
        }
        $this->assertSame('restart', $result['status'], (string) json_encode($result));
        $this->assertSame('source_changed', $result['reason']);
        $this->assertNull($journal->read_sender_state());
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
        $this->assertFileDoesNotExist($this->docroot . '/' . $relative_path);
    }

    /**
     * Verifies that recoverable target contention becomes a terminal result.
     *
     * The real push lock remains held while five reconstructed senders query
     * status. Each one reads the durable failure count left by the previous
     * process boundary; the fifth must stop instead of returning a final retry.
     */
    public function testHighLevelSenderStopsAfterBoundedRecoverableFailures(): void
    {
        $local_docroot = $this->root . '/retry-source';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/value.txt', 'value');
        $identity = lstat($local_docroot . '/value.txt');
        $this->assertIsArray($identity);
        $current_index = $this->root . '/retry-source.jsonl';
        $this->writeIndex($current_index, [
            'value.txt' => [ (int) $identity['ctime'], (int) $identity['size'], 'file'],
        ]);
        $journal = new PushJournal($this->root . '/retry-state', $this->base_url);

        $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
        $this->assertSame('continue', $result['status'], (string) json_encode($result));
        $state = $journal->read_sender_state();
        $this->assertIsArray($state);
        $this->assertSame('reconciling_work', $state['phase']);
        $push_lock = fopen(
            $this->reprint_directory . '/.reprint/push/' . $state['push_session_id'] . '/push.lock',
            'r+b'
        );
        $this->assertIsResource($push_lock);
        $this->assertTrue(flock($push_lock, LOCK_EX | LOCK_NB));
        try {
            for ($failure_number = 1; $failure_number <= 5; ++$failure_number) {
                $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
                $this->assertSame(
                    $failure_number === 5 ? 'failed' : 'continue',
                    $result['status'],
                    (string) json_encode($result)
                );
            }
        } finally {
            flock($push_lock, LOCK_UN);
            fclose($push_lock);
        }

        $this->assertSame('retry_exhausted', $result['reason']);
        $this->assertStringContainsString('5 consecutive recoverable failures', $result['detail']);
        $state = $journal->read_sender_state();
        $this->assertIsArray($state);
        $this->assertSame(5, $state['recoverable_failures']);
    }

    /**
     * Rejects a malformed control response without spending the retry budget.
     *
     * A real TCP peer accepts push_create and returns a non-JSON entity body.
     * The response is complete and unambiguous, so repeating it cannot repair
     * the protocol violation and must not be classified like a lost response.
     */
    public function testHighLevelSenderTreatsMalformedControlResponseAsTerminal(): void
    {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Malformed control-response coverage requires PHP curl and pcntl.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertNotFalse($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            stream_set_timeout($connection, 3);
            $request = '';
            while (strpos($request, "\r\n\r\n") === false && !feof($connection)) {
                $piece = fread($connection, 64 * 1024);
                if (!is_string($piece) || $piece === '') {
                    break;
                }
                $request .= $piece;
            }
            if (strpos($request, 'endpoint=push_create') === false) {
                fclose($connection);
                fclose($listener);
                exit(3);
            }
            $body = '<html>not a push response</html>';
            fwrite(
                $connection,
                "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body)
                    . "\r\nConnection: close\r\n\r\n" . $body
            );
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $local_docroot = $this->root . '/malformed-response-source';
        mkdir($local_docroot, 0700, true);
        $current_index = $this->root . '/malformed-response-index.jsonl';
        $this->writeIndex($current_index, []);
        $journal = new PushJournal($this->root . '/malformed-response-state', 'http://' . $address . '/?reprint-api=1');
        $sender = new PushFilesSender([
            'docroot' => $local_docroot,
            'current_index_file' => $current_index,
            'journal' => $journal,
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'response_timeout' => 2,
        ]);
        $result = $sender->send_next_request();
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertSame('failed', $result['status'], (string) json_encode($result));
        $this->assertSame('malformed_response', $result['reason']);
        $this->assertStringContainsString('invalid JSON', $result['detail']);
        $state = $journal->read_sender_state();
        $this->assertIsArray($state);
        $this->assertSame(0, $state['recoverable_failures']);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    /**
     * Resumes from target-confirmed bytes after three interrupted requests.
     *
     * Raw chunked connections close before a part, within its declared body,
     * and after a valid complete part without reading the response. The sender's
     * durable checkpoint still names the same path with no optimistic cursor;
     * status must recover zero until a complete MIME part was accepted, and
     * the complete part's byte count afterward.
     */
    public function testHighLevelSenderReconcilesInterruptedUploadParts(): void
    {
        $interruptions = [
            'before-part' => ['', 0],
            'within-part' => [str_repeat('I', 32), 0],
            'after-part' => [str_repeat('I', 64), 64],
        ];
        $base_url = parse_url($this->base_url);
        $this->assertIsArray($base_url);
        $this->assertIsString($base_url['host'] ?? null);
        $this->assertIsInt($base_url['port'] ?? null);

        foreach ($interruptions as $name => [$interrupted_payload, $expected_cursor]) {
            $local_docroot = $this->root . '/interrupted-' . $name;
            mkdir($local_docroot, 0700, true);
            $relative_path = 'interrupted-' . $name . '.bin';
            $source_path = $local_docroot . '/' . $relative_path;
            file_put_contents($source_path, str_repeat('I', 2000));
            $identity = lstat($source_path);
            $this->assertIsArray($identity);
            $current_index = $this->root . '/interrupted-' . $name . '.jsonl';
            $this->writeIndex($current_index, [
                $relative_path => [ (int) $identity['ctime'], (int) $identity['size'], 'file'],
            ]);
            $journal = new PushJournal($this->root . '/interrupted-state-' . $name, $this->base_url);
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertSame('continue', $result['status'], $name . ': ' . json_encode($result));
            $state = $journal->read_sender_state();
            $this->assertIsArray($state);

            $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $state['push_session_id'];
            $authentication_headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
            $boundary = 'reprint-interrupted-' . str_replace('-', '', $name);
            $mime_headers = '--' . $boundary . "\r\n"
                . "X-Chunk-Type: file\r\n"
                . 'X-File-Path: ' . base64_encode($relative_path) . "\r\n"
                . "X-File-Size: 2000\r\n"
                . "X-Chunk-Offset: 0\r\n"
                . "Content-Length: 64\r\n\r\n";
            $request_body = $interrupted_payload === '' ? '' : $mime_headers . $interrupted_payload;
            if ($interrupted_payload !== '') {
                $request_body .= "\r\n";
            }
            $complete_request = $name === 'after-part';
            if ($complete_request) {
                $request_body .= '--' . $boundary . "--\r\n";
            }
            $connection = stream_socket_client(
                'tcp://' . $base_url['host'] . ':' . $base_url['port'],
                $error_number,
                $error_message,
                3
            );
            $this->assertIsResource($connection, $error_message);
            $request_target = (string) $base_url['path'] . '?' . $base_url['query']
                . '&endpoint=push_upload&push_session_id=' . $state['push_session_id'];
            $request_headers = "POST " . $request_target . " HTTP/1.1\r\n"
                . 'Host: ' . $base_url['host'] . ':' . $base_url['port'] . "\r\n"
                . 'Content-Type: multipart/mixed; boundary=' . $boundary . "\r\n"
                . "Transfer-Encoding: chunked\r\nConnection: close\r\n";
            foreach ($authentication_headers as $header_name => $header_value) {
                $request_headers .= $header_name . ': ' . $header_value . "\r\n";
            }
            $request_headers .= "\r\n";
            if ($request_body !== '') {
                $request_headers .= dechex(strlen($request_body)) . "\r\n" . $request_body . "\r\n";
            }
            if ($complete_request) {
                $request_headers .= "0\r\n\r\n";
            }
            $this->assertSame(strlen($request_headers), fwrite($connection, $request_headers));
            // The first two variants truncate the request. The third sends a
            // complete body but discards the response immediately.
            fclose($connection);

            $state['phase'] = 'reconciling_work';
            $state['current_path_b64'] = base64_encode($relative_path);
            $state['next_paths_byte_offset'] = filesize($journal->local_paths_to_push);
            $state['source_token'] = [
                'type' => 'file',
                'size' => (int) $identity['size'],
                'ctime' => (int) $identity['ctime'],
            ];
            $state['confirmed_bytes'] = 0;
            $journal->write_sender_state($state);

            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $status = $this->newClient(self::SECRET)->control_request('GET', 'push_status', [
                    'push_session_id' => $state['push_session_id'],
                    'path_b64' => base64_encode($relative_path),
                ], ['accepted']);
                if ($status['status'] === 'complete') {
                    break;
                }
                usleep(10000);
            }
            $this->assertSame('complete', $status['status'], $name . ': ' . json_encode($status));
            $this->assertSame($expected_cursor, $status['response']['path']['accepted_bytes'], $name);

            for ($step = 0; $step < 200; ++$step) {
                $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
                $this->assertNotSame('failed', $result['status'], $name . ': ' . json_encode($result));
                if ($result['status'] !== 'continue') {
                    break;
                }
            }
            $this->assertSame('complete', $result['status'], $name . ': ' . json_encode($result));
            $this->assertSame(str_repeat('I', 2000), file_get_contents($this->docroot . '/' . $relative_path));
        }
    }

    /**
     * Repeats work-delete close and commit after their responses are lost.
     *
     * Both requests reach the real endpoint, but the test closes its socket
     * without reading the response. A reconstructed sender must recover the
     * target's durable delete cursor and commit checkpoint, not its last local
     * request result.
     */
    public function testHighLevelSenderRepeatsWorkDeleteAndCommitAfterLostResponses(): void
    {
        file_put_contents($this->docroot . '/delete-after-lost-response.txt', 'old');
        $local_docroot = $this->root . '/lost-response-source';
        mkdir($local_docroot, 0700, true);
        $current_index = $this->root . '/lost-response-current.jsonl';
        $this->writeIndex($current_index, []);
        $baseline = $this->root . '/lost-response-baseline.jsonl';
        $this->writeIndex($baseline, [
            'delete-after-lost-response.txt' => [1, 3, 'file'],
        ]);
        $journal = new PushJournal($this->root . '/lost-response-state', $this->base_url);
        $journal->capture_local_files_baseline($baseline);

        for ($step = 0; $step < 20; ++$step) {
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $journal->read_sender_state();
            if (is_array($state) && $state['phase'] === 'reconciling_deletes') {
                break;
            }
        }
        $this->assertIsArray($state);
        $this->assertSame('reconciling_deletes', $state['phase']);
        $work_deletes = (string) file_get_contents($journal->work_deletes_path);
        $this->assertSame("delete-after-lost-response.txt\0", $work_deletes);
        $boundary = 'reprint-lost-delete-response';
        $delete_body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . "X-Delete-Offset: 0\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($work_deletes) . "\r\n\r\n"
            . $work_deletes . "\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . 'X-Delete-Offset: ' . strlen($work_deletes) . "\r\n"
            . "X-Delete-Complete: 1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $this->sendPostAndDiscardResponse(
            $this->base_url . '&endpoint=push_upload&push_session_id=' . $state['push_session_id'],
            'multipart/mixed; boundary=' . $boundary,
            $delete_body
        );

        for (; $step < 60; ++$step) {
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $journal->read_sender_state();
            if (is_array($state) && $state['phase'] === 'committing') {
                break;
            }
        }
        $this->assertIsArray($state);
        $this->assertSame(strlen($work_deletes), $state['work_deletes_byte_offset']);
        $this->assertSame('committing', $state['phase']);

        $this->sendPostAndDiscardResponse(
            $this->base_url . '&endpoint=push_commit&push_session_id=' . $state['push_session_id'],
            null,
            ''
        );
        for (; $step < 120; ++$step) {
            $result = $this->newSender($local_docroot, $current_index, $journal)->send_next_request();
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            if ($result['status'] !== 'continue') {
                break;
            }
        }
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertFileDoesNotExist($this->docroot . '/delete-after-lost-response.txt');
        $this->assertNull($journal->read_sender_state());
    }

    private function newClient(string $secret): MultipartPushStreamClient
    {
        return new MultipartPushStreamClient([
            'base_url' => $this->base_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client($secret),
            'chunk_bytes' => 4,
            'connect_timeout' => 3,
            'stall_timeout' => 3,
            'response_timeout' => 5,
        ]);
    }

    /**
     * Reconstructs the production sender at a process-restart boundary.
     *
     * @param string $local_docroot Local document root whose values are sent.
     * @param string $current_index Path-sorted local file index for this push.
     * @param PushJournal $journal Durable per-target sender journal.
     * @return PushFilesSender Sender restored from the journal checkpoint.
     */
    private function newSender(string $local_docroot, string $current_index, PushJournal $journal): PushFilesSender
    {
        return new PushFilesSender([
            'docroot' => $local_docroot,
            'current_index_file' => $current_index,
            'journal' => $journal,
            'base_url' => $this->base_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 64,
            'request_sizer_config' => [
                'floor_bytes' => 2048,
                'start_bytes' => 2048,
                'max_bytes' => 2048,
            ],
            'connect_timeout' => 3,
            'stall_timeout' => 3,
            'response_timeout' => 5,
        ]);
    }

    /**
     * Sends one complete signed POST request and discards its response.
     *
     * Closing the socket immediately after the terminating transfer chunk
     * reproduces a sender that cannot know whether the target completed the
     * operation. The caller must reconcile from target state afterward.
     *
     * @param string $url Exact push endpoint URL to authenticate and request.
     * @param string|null $content_type Request Content-Type, or null when the
     *     endpoint has no body format.
     * @param string $body Decoded HTTP entity body.
     */
    private function sendPostAndDiscardResponse(string $url, ?string $content_type, string $body): void
    {
        $target = parse_url($url);
        $this->assertIsArray($target);
        $this->assertIsString($target['host'] ?? null);
        $this->assertIsInt($target['port'] ?? null);
        $authentication_headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $connection = stream_socket_client(
            'tcp://' . $target['host'] . ':' . $target['port'],
            $error_number,
            $error_message,
            3
        );
        $this->assertIsResource($connection, $error_message);
        $request = 'POST ' . $target['path'] . '?' . $target['query'] . " HTTP/1.1\r\n"
            . 'Host: ' . $target['host'] . ':' . $target['port'] . "\r\n"
            . "Transfer-Encoding: chunked\r\nConnection: close\r\n";
        if ($content_type !== null) {
            $request .= 'Content-Type: ' . $content_type . "\r\n";
        }
        foreach ($authentication_headers as $header_name => $header_value) {
            $request .= $header_name . ': ' . $header_value . "\r\n";
        }
        $request .= "\r\n";
        if ($body !== '') {
            $request .= dechex(strlen($body)) . "\r\n" . $body . "\r\n";
        }
        $request .= "0\r\n\r\n";
        $this->assertSame(strlen($request), fwrite($connection, $request));
        fclose($connection);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * Writes a path-sorted local index in the production JSONL shape.
     *
     * @param array<string,array{0:int,1:int,2:'file'|'dir'|'link'}> $entries
     *     Path to `[ctime, size, type]` records.
     */
    private function writeIndex(string $path, array $entries): void
    {
        uksort($entries, 'strcmp');
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        foreach ($entries as $entry_path => [$ctime, $size, $type]) {
            $line = json_encode([
                'path' => base64_encode($entry_path),
                'ctime' => $ctime,
                'size' => $size,
                'type' => $type,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $this->assertSame(strlen($line), fwrite($handle, $line));
        }
        fclose($handle);
    }
}
