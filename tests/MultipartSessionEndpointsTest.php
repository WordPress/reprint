<?php

use PHPUnit\Framework\TestCase;

final class MultipartSessionEndpointsTest extends TestCase {

    private const SECRET = 'multipart-endpoint-test-secret';

    private string $root;
    private string $target;
    private string $storage;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/multipart-endpoint-' . bin2hex(random_bytes(8));
        $this->target = $this->root . '/target';
        $this->storage = $this->root . '/storage';
        mkdir($this->target, 0700, true);
        mkdir($this->storage, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->root);
    }

    public function testCreateIsIdempotentAndStatusOnlyReportsRequestedPaths(): void {
        $endpoints = $this->endpoints();
        $token = str_repeat('a', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $first = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $second = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));

        $this->assertSame(201, $first['http_code']);
        $this->assertSame('created', $first['body']['status']);
        $this->assertSame($first['body']['session_id'], $second['body']['session_id']);

        $path = base64_encode('only-this-path.txt');
        $status_uri = '/?endpoint=staged_session_status&session_id=' . $first['body']['session_id'] . '&path=' . rawurlencode($path);
        $status = $endpoints->session_status(
            ['session_id' => $first['body']['session_id'], 'path' => $path],
            $this->headers('GET', $status_uri)
        );
        $this->assertSame('ok', $status['body']['status']);
        $this->assertSame([[
            'path_b64' => $path,
            'state' => 'missing',
            'accepted_bytes' => 0,
        ]], $status['body']['paths']);
    }

    public function testDisabledApplyReturnsItsStringErrorCode(): void {
        $options = $this->options();
        $options['apply_sessions_enabled'] = false;
        $endpoints = new Site_Export_Staged_Endpoints($options);
        $token = str_repeat('a', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;

        $response = $endpoints->session_create(
            ['create_token' => $token],
            $this->headers('POST', $create_uri)
        );

        $this->assertSame(503, $response['http_code']);
        $this->assertSame('error', $response['body']['status']);
        $this->assertSame('apply_not_configured', $response['body']['reason']);
    }

    public function testMissingSessionReturnsItsStringErrorCode(): void {
        $endpoints = $this->endpoints();
        $create_token = str_repeat('a', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $create_token;
        $endpoints->session_create(
            ['create_token' => $create_token],
            $this->headers('POST', $create_uri)
        );

        $session_id = str_repeat('b', 32);
        $status_uri = '/?endpoint=staged_session_status&session_id=' . $session_id;

        $response = $endpoints->session_status(
            ['session_id' => $session_id],
            $this->headers('GET', $status_uri)
        );

        $this->assertSame(404, $response['http_code']);
        $this->assertSame('error', $response['body']['status']);
        $this->assertSame('session_not_found', $response['body']['reason']);
    }

    public function testDiscardRemovesACompletedSessionAndIsIdempotentAfterRemoval(): void {
        $endpoints = $this->endpoints();
        $token = str_repeat('e', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $session_id = $created['body']['session_id'];
        $this->assertIsString($session_id);
        $session = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session_id,
            []
        );
        $this->completeDeleteUpload($endpoints, $session_id);
        do {
            $commit = $session->commit(1);
        } while (!empty($commit['send_next_request']));

        $discard_uri = '/?endpoint=staged_session_discard&session_id=' . $session_id;
        $first = $endpoints->session_discard(
            ['session_id' => $session_id],
            $this->headers('POST', $discard_uri)
        );
        $second = $endpoints->session_discard(
            ['session_id' => $session_id],
            $this->headers('POST', $discard_uri)
        );

        $this->assertSame(200, $first['http_code']);
        $this->assertSame('discarded', $first['body']['status']);
        $this->assertDirectoryDoesNotExist($this->storage . '/apply-sessions/' . $session_id);
        $this->assertSame(200, $second['http_code']);
        $this->assertSame('discarded', $second['body']['status']);
    }

    public function testCompletedSessionCleanupIsBoundedAndResumesFromItsTombstone(): void {
        $endpoints = $this->endpoints();
        $token = str_repeat('f', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $session_id = $created['body']['session_id'];
        $this->assertIsString($session_id);
        $session = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session_id,
            []
        );
        $this->completeDeleteUpload($endpoints, $session_id);
        do {
            $commit = $session->commit(1);
        } while (!empty($commit['send_next_request']));
        $load = $session->get_session_directory() . '/work/files/cleanup-load';
        mkdir($load, 0700);
        for ($index = 0; $index < 300; ++$index) {
            file_put_contents($load . '/' . $index, 'x');
        }

        $discard_uri = '/?endpoint=staged_session_discard&session_id=' . $session_id;
        $response = $endpoints->session_discard(
            ['session_id' => $session_id],
            $this->headers('POST', $discard_uri)
        );

        $this->assertSame('discarding', $response['body']['status']);
        $this->assertTrue($response['body']['send_next_request']);
        $this->assertDirectoryDoesNotExist($this->storage . '/apply-sessions/' . $session_id);
        $this->assertDirectoryExists($this->storage . '/apply-sessions/.discarding-' . $session_id);

        $requests = 1;
        while (!empty($response['body']['send_next_request'])) {
            $response = $this->endpoints()->session_discard(
                ['session_id' => $session_id],
                $this->headers('POST', $discard_uri)
            );
            ++$requests;
        }

        $this->assertGreaterThan(1, $requests);
        $this->assertSame('discarded', $response['body']['status']);
        $this->assertDirectoryDoesNotExist($this->storage . '/apply-sessions/.discarding-' . $session_id);
    }

    public function testBadEnvelopeIsRejectedBeforeTheHttpServerReadsTheBody(): void {
        $reads = 0;
        $server = new Site_Export_HTTP_Server([
            'body_reader' => static function () use (&$reads): string {
                ++$reads;
                return 'must not be read';
            },
            'staged' => $this->options(),
        ]);
        ob_start();
        try {
            $server->handle_request([
                'get' => ['endpoint' => 'staged_session_upload', 'session_id' => str_repeat('b', 32)],
                'post' => [],
                'server' => [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/?endpoint=staged_session_upload&session_id=' . str_repeat('b', 32),
                    'CONTENT_TYPE' => 'multipart/mixed; boundary=x',
                ],
            ]);
            $body = ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        $this->assertSame(0, $reads);
        $decoded = json_decode((string) $body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('auth_failed', $decoded['reason']);
    }

    public function testPreflightReceivesOnlyTheServerDerivedPushCapability(): void {
        $reported = null;
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => static function (array $config) use (&$reported): void {
                    $reported = $config['staged_push'] ?? null;
                },
            ],
            'staged' => $this->options(),
        ]);
        $server->handle_request([
            'get' => ['endpoint' => 'preflight', 'staged_push' => 'untrusted'],
            'post' => [],
            'server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?endpoint=preflight'],
        ]);

        $this->assertIsArray($reported);
        $this->assertTrue($reported['available']);
        $this->assertTrue($reported['filesystem_ok']);
        $this->assertSame(1024, $reported['max_frame_bytes']);
        $this->assertArrayNotHasKey('secret', $reported);
    }

    public function testUploadStopsAtTheConfiguredPartCapBetweenDurableParts(): void {
        $options = $this->options();
        $options['max_upload_parts'] = 1;
        $endpoints = new Site_Export_Staged_Endpoints($options);
        $token = str_repeat('d', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $session_id = $created['body']['session_id'];
        $this->assertIsString($session_id);

        $boundary = 'part-cap';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: directory\r\n"
            . 'X-Directory-Path: ' . base64_encode('first-empty') . "\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: directory\r\n"
            . 'X-Directory-Path: ' . base64_encode('must-wait') . "\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $upload_uri = '/?endpoint=staged_session_upload&session_id=' . $session_id;
        $headers = $this->headers('POST', $upload_uri);
        $headers['Content-Type'] = 'multipart/mixed; boundary=' . $boundary;
        try {
            $response = $endpoints->session_upload(['session_id' => $session_id], $headers, $input);
        } finally {
            fclose($input);
        }

        $this->assertSame(200, $response['http_code']);
        $this->assertTrue($response['body']['send_next_request']);
        $this->assertCount(1, $response['body']['accepted']);
        $status_uri = '/?endpoint=staged_session_status&session_id=' . $session_id
            . '&paths=' . rawurlencode(json_encode([base64_encode('first-empty'), base64_encode('must-wait')]));
        $status = $endpoints->session_status(
            ['session_id' => $session_id],
            $this->headers('GET', $status_uri)
        );
        $this->assertSame('complete', $status['body']['paths'][0]['state']);
        $this->assertSame('missing', $status['body']['paths'][1]['state']);
    }

    public function testStandaloneRecoveryServerUsesTheNormalStatusSemanticsWithoutWordPress(): void {
        $endpoints = $this->endpoints();
        $token = str_repeat('c', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $session_id = $created['body']['session_id'];
        $this->assertIsString($session_id);
        $path = base64_encode('recoverable.txt');
        $status_uri = '/?endpoint=staged_session_status&session_id=' . $session_id . '&path=' . rawurlencode($path);

        $old_get = $_GET;
        $old_server = $_SERVER;
        $_GET = ['endpoint' => 'staged_session_status', 'session_id' => $session_id, 'path' => $path];
        $_SERVER = $this->headers('GET', $status_uri);
        ob_start();
        try {
            Site_Export_Staged_Session_Recovery_Server::serve($this->options());
            $body = ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        } finally {
            $_GET = $old_get;
            $_SERVER = $old_server;
        }

        $response = json_decode((string) $body, true);
        $this->assertIsArray($response);
        $this->assertSame('ok', $response['status']);
        $this->assertSame([[
            'path_b64' => $path,
            'state' => 'missing',
            'accepted_bytes' => 0,
        ]], $response['paths']);
    }

    public function testLiveTreeConflictResponseIncludesTheAuthenticatedSchema(): void {
        $endpoints = $this->endpoints();
        $token = str_repeat('9', 32);
        $create_uri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $create_uri));
        $session_id = $created['body']['session_id'];
        $this->assertIsString($session_id);

        $boundary = 'conflict-upload';
        $path = 'conflict';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode($path) . "\r\n"
            . "X-File-Size: 3\r\nX-Chunk-Offset: 0\r\nContent-Length: 3\r\n\r\nnew\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $upload_uri = '/?endpoint=staged_session_upload&session_id=' . $session_id;
        $headers = $this->headers('POST', $upload_uri);
        $headers['Content-Type'] = 'multipart/mixed; boundary=' . $boundary;
        try {
            $uploaded = $endpoints->session_upload(['session_id' => $session_id], $headers, $input);
        } finally {
            fclose($input);
        }
        $this->assertSame('accepted', $uploaded['body']['status']);
        $this->completeDeleteUpload($endpoints, $session_id);
        mkdir($this->target . '/' . $path);
        file_put_contents($this->target . '/' . $path . '/sentinel', 'safe');

        $commit_uri = '/?endpoint=staged_session_commit&session_id=' . $session_id;
        do {
            $response = $endpoints->session_commit(
                ['session_id' => $session_id],
                $this->headers('POST', $commit_uri)
            );
        } while (($response['body']['status'] ?? null) === 'ok' && !empty($response['body']['send_next_request']));

        $this->assertSame(409, $response['http_code']);
        $this->assertSame('live_tree_changed', $response['body']['reason']);
        $this->assertSame('install', $response['body']['operation']);
        $this->assertSame(base64_encode($path), $response['body']['path_b64']);
        $this->assertSame(base64_encode($path), $response['body']['conflict_path_b64']);
        $this->assertSame('file', $response['body']['staged_type']);
        $this->assertSame(['absent', 'file', 'symlink'], $response['body']['expected_live_types']);
        $this->assertSame('directory', $response['body']['observed_live_identity']['type']);
        $this->assertSame('safe', file_get_contents($this->target . '/' . $path . '/sentinel'));
        $this->assertFileExists($this->target . '/.maintenance');
    }

    public function testDeletionConflictResponseIncludesTheRequestedAndObservedPaths(): void {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('POSIX FIFO creation is unavailable.');
        }
        $unsupportedPath = $this->target . '/unsupported';
        $this->assertTrue(posix_mkfifo($unsupportedPath, 0600));
        $endpoints = $this->endpoints();
        $token = str_repeat('8', 32);
        $createUri = '/?endpoint=staged_session_create&create_token=' . $token;
        $created = $endpoints->session_create(['create_token' => $token], $this->headers('POST', $createUri));
        $sessionId = $created['body']['session_id'];
        $deleteBytes = "unsupported\0";
        $boundary = 'delete-conflict';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\nX-Delete-Offset: 0\r\n"
            . 'Content-Length: ' . strlen($deleteBytes) . "\r\n\r\n"
            . $deleteBytes . "\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . 'X-Delete-Offset: ' . strlen($deleteBytes) . "\r\n"
            . "X-Delete-Complete: 1\r\nContent-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $uploadUri = '/?endpoint=staged_session_upload&session_id=' . $sessionId;
        $headers = $this->headers('POST', $uploadUri);
        $headers['Content-Type'] = 'multipart/mixed; boundary=' . $boundary;
        try {
            $uploaded = $endpoints->session_upload(['session_id' => $sessionId], $headers, $input);
        } finally {
            fclose($input);
        }
        $this->assertSame('complete', $uploaded['body']['accepted'][1]['state']);

        $commitUri = '/?endpoint=staged_session_commit&session_id=' . $sessionId;
        do {
            $response = $endpoints->session_commit(
                ['session_id' => $sessionId],
                $this->headers('POST', $commitUri)
            );
        } while (($response['body']['status'] ?? null) === 'ok' && !empty($response['body']['send_next_request']));

        $this->assertSame(409, $response['http_code']);
        $this->assertSame('live_tree_changed', $response['body']['reason']);
        $this->assertSame('delete', $response['body']['operation']);
        $this->assertSame(base64_encode('unsupported'), $response['body']['path_b64']);
        $this->assertSame(base64_encode('unsupported'), $response['body']['conflict_path_b64']);
        $this->assertSame('other', $response['body']['observed_live_identity']['type']);
        $this->assertFileExists($this->target . '/.maintenance');
        $this->assertTrue(file_exists($unsupportedPath));
        unlink($unsupportedPath);
    }

    private function endpoints(): Site_Export_Staged_Endpoints {
        return new Site_Export_Staged_Endpoints($this->options());
    }

    private function completeDeleteUpload(Site_Export_Staged_Endpoints $endpoints, string $sessionId): void {
        $boundary = 'delete-complete';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . "X-Delete-Offset: 0\r\n"
            . "X-Delete-Complete: 1\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $uploadUri = '/?endpoint=staged_session_upload&session_id=' . $sessionId;
        $headers = $this->headers('POST', $uploadUri);
        $headers['Content-Type'] = 'multipart/mixed; boundary=' . $boundary;
        try {
            $response = $endpoints->session_upload(['session_id' => $sessionId], $headers, $input);
        } finally {
            fclose($input);
        }
        $this->assertSame('complete', $response['body']['accepted'][0]['state']);
    }

    /** @return array<string,mixed> */
    private function options(): array {
        return [
            'staging_dir' => $this->storage,
            'secret' => self::SECRET,
            'apply_target_root' => $this->target,
            'apply_sessions_enabled' => true,
            'max_frame_bytes' => 1024,
        ];
    }

    /** @return array<string,string> */
    private function headers(string $method, string $request_uri): array {
        $headers = (new Site_Export_HMAC_Client(self::SECRET))->get_envelope_auth_headers($method, 'http://example.test' . $request_uri);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $request_uri];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return $server;
    }

    private function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
