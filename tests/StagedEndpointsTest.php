<?php

use PHPUnit\Framework\TestCase;

final class StagedEndpointsTest extends TestCase {

    private const SECRET = 'staged-endpoints-test-secret';

    private string $staging_dir;

    private string $target_dir;

    protected function setUp(): void {
        $this->staging_dir = sys_get_temp_dir() . '/staged-endpoints-test-' . bin2hex(random_bytes(8));
        $this->target_dir = sys_get_temp_dir() . '/staged-endpoints-target-' . bin2hex(random_bytes(8));
        mkdir($this->target_dir, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->staging_dir);
        $this->remove_tree($this->target_dir);
    }

    public function test_create_is_idempotent_after_a_lost_response(): void {
        $endpoints = $this->make_endpoints();
        $create_token = str_repeat('a', 32);

        $first = $endpoints->session_create(
            ['create_token' => $create_token],
            $this->session_headers('staged_session_create', null, null, 'POST', ['create_token' => $create_token])
        );
        $second = $endpoints->session_create(
            ['create_token' => $create_token],
            $this->session_headers('staged_session_create', null, null, 'POST', ['create_token' => $create_token])
        );

        $this->assertSame(201, $first['http_code']);
        $this->assertSame('created', $first['body']['status']);
        $this->assertSame($first['body']['session_id'], $second['body']['session_id']);
        $this->assertSame('uploading', $first['body']['phase']);
        $this->assertSame(0, $first['body']['operation_count']);
        $this->assertNull($first['body']['current_file']);
        $this->assertArrayNotHasKey('prepare_chunk_bytes', $first['body']);
    }

    public function test_typed_operations_stage_and_apply_without_a_manifest_step(): void {
        file_put_contents($this->target_dir . '/old.txt', 'remove me');
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();

        $response = $this->push($endpoints, $session, 0, [
            ['type' => 'directory', 'operation_index' => 0, 'path' => 'content'],
            [
                'type' => 'file',
                'operation_index' => 1,
                'path' => 'content/file.txt',
                'revision' => 1,
                'offset' => 0,
                'total_bytes' => 5,
                'restart' => false,
                'payload' => 'hello',
            ],
            ['type' => 'symlink', 'operation_index' => 2, 'path' => 'content/link', 'target' => 'file.txt'],
            ['type' => 'delete', 'operation_index' => 3, 'path' => 'old.txt'],
        ]);

        $this->assertSame(200, $response['http_code']);
        $this->assertSame('complete', $response['body']['status']);
        $this->assertSame(4, $response['body']['operation_count']);
        $this->assertNull($response['body']['current_file']);
        $this->assertSame('uploading', $response['body']['phase']);

        $final = $this->advance_until_complete($endpoints, $session, $response['body']['request_generation']);
        $this->assertSame('complete', $final['phase']);
        $this->assertSame('hello', file_get_contents($this->target_dir . '/content/file.txt'));
        $this->assertSame('file.txt', readlink($this->target_dir . '/content/link'));
        $this->assertFileDoesNotExist($this->target_dir . '/old.txt');
    }

    public function test_file_upload_resumes_from_target_confirmed_bytes(): void {
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();

        $first = $this->push($endpoints, $session, 0, [[
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'partial.bin',
            'revision' => 7,
            'offset' => 0,
            'total_bytes' => 4,
            'restart' => false,
            'payload' => 'ab',
        ]]);

        $this->assertSame(0, $first['body']['operation_count']);
        $this->assertSame(2, $first['body']['current_file']['committed_bytes']);
        $this->assertSame(7, $first['body']['current_file']['revision']);

        $second = $this->push($endpoints, $session, $first['body']['request_generation'], [[
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'partial.bin',
            'revision' => 7,
            'offset' => 2,
            'total_bytes' => 4,
            'restart' => false,
            'payload' => 'cd',
        ]]);

        $this->assertSame(1, $second['body']['operation_count']);
        $this->assertNull($second['body']['current_file']);
        $final = $this->advance_until_complete($endpoints, $session, $second['body']['request_generation']);
        $this->assertSame('complete', $final['phase']);
        $this->assertSame('abcd', file_get_contents($this->target_dir . '/partial.bin'));
    }

    public function test_replaying_the_same_restart_revision_does_not_truncate_its_prefix(): void {
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();
        $first = $this->push($endpoints, $session, 0, [[
            'type' => 'file', 'operation_index' => 0, 'path' => 'changing.bin',
            'revision' => 1, 'offset' => 0, 'total_bytes' => 4, 'restart' => false, 'payload' => 'ab',
        ]]);
        $restarted = $this->push($endpoints, $session, $first['body']['request_generation'], [[
            'type' => 'file', 'operation_index' => 0, 'path' => 'changing.bin',
            'revision' => 2, 'offset' => 0, 'total_bytes' => 4, 'restart' => true, 'payload' => 'XY',
        ]]);

        // Simulate retrying the request whose response was lost. Its signed
        // generation is stale, so the body is rejected before it can restart
        // the file a second time.
        $stale = $this->push($endpoints, $session, $first['body']['request_generation'], [[
            'type' => 'file', 'operation_index' => 0, 'path' => 'changing.bin',
            'revision' => 2, 'offset' => 0, 'total_bytes' => 4, 'restart' => true, 'payload' => 'XY',
        ]]);
        $this->assertSame(409, $stale['http_code']);
        $this->assertSame('stale_session_state', $stale['body']['reason']);

        $status = $endpoints->session_status(
            ['session_id' => $session->get_session_id()],
            $this->session_headers('staged_session_status', $session->get_session_id(), null, 'GET')
        );
        $this->assertSame(2, $status['body']['current_file']['revision']);
        $this->assertSame(2, $status['body']['current_file']['committed_bytes']);

        $finished = $this->push($endpoints, $session, $restarted['body']['request_generation'], [
            [
                'type' => 'file', 'operation_index' => 0, 'path' => 'changing.bin',
                'revision' => 2, 'offset' => 0, 'total_bytes' => 4, 'restart' => true, 'payload' => 'XY',
            ],
            [
                'type' => 'file', 'operation_index' => 0, 'path' => 'changing.bin',
                'revision' => 2, 'offset' => 2, 'total_bytes' => 4, 'restart' => false, 'payload' => 'ZW',
            ],
        ]);
        $this->assertSame(1, $finished['body']['operation_count']);
        $this->advance_until_complete($endpoints, $session, $finished['body']['request_generation']);
        $this->assertSame('XYZW', file_get_contents($this->target_dir . '/changing.bin'));
    }

    public function test_invalid_frame_fails_the_session_instead_of_skipping_an_operation(): void {
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();
        $stream = $this->body_stream("not-json\n");
        try {
            $response = $endpoints->session_push_stream(
                ['session_id' => $session->get_session_id()],
                $this->session_headers('staged_session_push', $session->get_session_id(), 0),
                $stream
            );
        } finally {
            fclose($stream);
        }

        $this->assertSame(400, $response['http_code']);
        $this->assertSame('invalid_frame', $response['body']['reason']);
        $this->assertSame('failed', $response['body']['phase']);

        $retry = $this->push($endpoints, $session, $response['body']['request_generation'], [
            ['type' => 'delete', 'operation_index' => 0, 'path' => 'ignored.txt'],
        ]);
        $this->assertSame(409, $retry['http_code']);
        $this->assertSame('session_rejected', $retry['body']['reason']);
    }

    public function test_payload_cap_rejection_keeps_the_session_resumable(): void {
        $endpoints = $this->make_endpoints(['max_frame_bytes' => 2]);
        $session = $this->new_session();
        $oversized = $this->push($endpoints, $session, 0, [[
            'type' => 'file', 'operation_index' => 0, 'path' => 'small.bin',
            'revision' => 1, 'offset' => 0, 'total_bytes' => 3, 'restart' => false, 'payload' => 'abc',
        ]]);

        $this->assertSame(413, $oversized['http_code']);
        $this->assertSame(2, $oversized['body']['max_frame_bytes']);
        $this->assertSame(0, $oversized['body']['operation_count']);
        $this->assertSame('uploading', $oversized['body']['phase']);

        $accepted = $this->push($endpoints, $session, $oversized['body']['request_generation'], [
            [
                'type' => 'file', 'operation_index' => 0, 'path' => 'small.bin',
                'revision' => 1, 'offset' => 0, 'total_bytes' => 3, 'restart' => false, 'payload' => 'ab',
            ],
            [
                'type' => 'file', 'operation_index' => 0, 'path' => 'small.bin',
                'revision' => 1, 'offset' => 2, 'total_bytes' => 3, 'restart' => false, 'payload' => 'c',
            ],
        ]);
        $this->assertSame(1, $accepted['body']['operation_count']);
    }

    public function test_truncated_payload_keeps_the_confirmed_partial_cursor_resumable(): void {
        $endpoints = $this->make_endpoints(['append_buffer_bytes' => 2]);
        $session = $this->new_session();
        $operation = [
            'type' => 'file', 'operation_index' => 0, 'path' => 'truncated.bin',
            'revision' => 1, 'offset' => 0, 'total_bytes' => 4, 'restart' => false, 'payload' => 'abcd',
        ];
        $body = Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($operation) . 'ab';
        $stream = $this->body_stream($body);
        try {
            $response = $endpoints->session_push_stream(
                ['session_id' => $session->get_session_id()],
                $this->session_headers('staged_session_push', $session->get_session_id(), 0),
                $stream
            );
        } finally {
            fclose($stream);
        }

        $this->assertSame(400, $response['http_code']);
        $this->assertSame('body_read_failed', $response['body']['reason']);
        $this->assertSame('uploading', $response['body']['phase']);
        $this->assertSame(2, $response['body']['current_file']['committed_bytes']);

        $finished = $this->push($endpoints, $session, $response['body']['request_generation'], [[
            'type' => 'file', 'operation_index' => 0, 'path' => 'truncated.bin',
            'revision' => 1, 'offset' => 2, 'total_bytes' => 4, 'restart' => false, 'payload' => 'cd',
        ]]);
        $this->assertSame(1, $finished['body']['operation_count']);
    }

    public function test_metadata_only_requests_stop_at_the_server_frame_cap(): void {
        $endpoints = $this->make_endpoints(['max_frames_per_request' => 2]);
        $session = $this->new_session();
        $first = $this->push($endpoints, $session, 0, [
            ['type' => 'delete', 'operation_index' => 0, 'path' => 'a'],
            ['type' => 'delete', 'operation_index' => 1, 'path' => 'b'],
            ['type' => 'delete', 'operation_index' => 2, 'path' => 'c'],
        ]);

        $this->assertSame(2, $first['body']['frames_processed']);
        $this->assertSame(2, $first['body']['operation_count']);
        $second = $this->push($endpoints, $session, $first['body']['request_generation'], [
            ['type' => 'delete', 'operation_index' => 2, 'path' => 'c'],
        ]);
        $this->assertSame(3, $second['body']['operation_count']);
    }

    public function test_staged_symlink_ancestor_cannot_escape_the_private_tree(): void {
        $outside = sys_get_temp_dir() . '/staged-endpoints-outside-' . bin2hex(random_bytes(8));
        mkdir($outside, 0700, true);
        try {
            $endpoints = $this->make_endpoints();
            $session = $this->new_session();
            $response = $this->push($endpoints, $session, 0, [
                ['type' => 'symlink', 'operation_index' => 0, 'path' => 'escape', 'target' => $outside],
                [
                    'type' => 'file', 'operation_index' => 1, 'path' => 'escape/written.txt',
                    'revision' => 1, 'offset' => 0, 'total_bytes' => 1, 'restart' => false, 'payload' => 'x',
                ],
            ]);

            $this->assertSame(409, $response['http_code']);
            $this->assertSame('failed', $session->get_status()['phase']);
            $this->assertFileDoesNotExist($outside . '/written.txt');
        } finally {
            $this->remove_tree($outside);
        }
    }

    public function test_real_site_paths_under_dot_reprint_are_not_reserved(): void {
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();
        $response = $this->push($endpoints, $session, 0, [[
            'type' => 'file', 'operation_index' => 0, 'path' => '.reprint/site-file',
            'revision' => 1, 'offset' => 0, 'total_bytes' => 1, 'restart' => false, 'payload' => 'x',
        ]]);

        $this->assertSame(1, $response['body']['operation_count']);
        $this->advance_until_complete($endpoints, $session, $response['body']['request_generation']);
        $this->assertSame('x', file_get_contents($this->target_dir . '/.reprint/site-file'));
    }

    public function test_wrong_secret_is_rejected_before_the_body_is_opened(): void {
        $endpoints = $this->make_endpoints();
        $session = $this->new_session();
        $headers = $this->session_headers('staged_session_push', $session->get_session_id(), 0);
        $target = $headers['REQUEST_URI'];
        $wrong = ( new Site_Export_HMAC_Client('wrong-secret') )->get_envelope_auth_headers('POST', $target) + [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $target,
        ];

        $response = $endpoints->session_push_stream(
            ['session_id' => $session->get_session_id()],
            $wrong,
            null
        );

        $this->assertSame(403, $response['http_code']);
        $this->assertSame('auth_failed', $response['body']['reason']);
        $this->assertSame(0, $session->get_status()['request_generation']);
    }

    public function test_present_invalid_endpoint_limits_are_not_silently_defaulted(): void {
        foreach (
            [
                ['secret' => ''],
                ['secret' => false],
                ['max_frame_bytes' => 0],
                ['append_buffer_bytes' => 'no'],
                ['append_buffer_bytes' => 4194305],
                ['max_frames_per_request' => -1],
                ['max_frames_per_request' => Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST + 1],
                ['timestamp_tolerance' => 0],
            ] as $invalid
        ) {
            try {
                $this->make_endpoints($invalid);
                $this->fail('Expected invalid endpoint option to throw: ' . json_encode($invalid));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('must be', $exception->getMessage());
            }
        }
    }

    private function make_endpoints(array $overrides = []): Site_Export_Staged_Endpoints {
        return new Site_Export_Staged_Endpoints(array_merge(
            [
                'staging_dir' => $this->staging_dir,
                'secret' => self::SECRET,
                'apply_target_root' => $this->target_dir,
            ],
            $overrides
        ));
    }

    private function new_session(): Site_Export_Staged_Apply {
        return Site_Export_Staged_Apply::create($this->staging_dir, $this->target_dir);
    }

    /** @param array<int,array<string,mixed>> $operations */
    private function push(
        Site_Export_Staged_Endpoints $endpoints,
        Site_Export_Staged_Apply $session,
        int $generation,
        array $operations
    ): array {
        $body = '';
        foreach ($operations as $operation) {
            $body .= Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($operation);
            if ($operation['type'] === 'file') {
                $body .= $operation['payload'];
            }
        }
        $stream = $this->body_stream($body);
        try {
            return $endpoints->session_push_stream(
                ['session_id' => $session->get_session_id()],
                $this->session_headers('staged_session_push', $session->get_session_id(), $generation),
                $stream
            );
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,mixed> */
    private function advance_until_complete(
        Site_Export_Staged_Endpoints $endpoints,
        Site_Export_Staged_Apply $session,
        int $generation
    ): array {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $response = $endpoints->session_advance(
                ['session_id' => $session->get_session_id()],
                $this->session_headers('staged_session_advance', $session->get_session_id(), $generation)
            );
            $this->assertSame(200, $response['http_code'], json_encode($response['body']));
            $generation = $response['body']['request_generation'];
            if ($response['body']['phase'] === 'complete') {
                return $response['body'];
            }
        }
        $this->fail('The staged apply session did not complete within 100 bounded advances.');
    }

    private function session_headers(
        string $endpoint,
        ?string $session_id = null,
        ?int $expected_generation = null,
        string $method = 'POST',
        array $extra_parameters = []
    ): array {
        $target = '/?endpoint=' . rawurlencode($endpoint);
        if ($session_id !== null) {
            $target .= '&session_id=' . rawurlencode($session_id);
        }
        if ($expected_generation !== null) {
            $target .= '&expected_request_generation=' . $expected_generation;
        }
        foreach ($extra_parameters as $name => $value) {
            $target .= '&' . rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        return ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers($method, $target) + [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
        ];
    }

    /** @return resource */
    private function body_stream(string $body) {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || fwrite($stream, $body) !== strlen($body) || fseek($stream, 0) !== 0) {
            throw new RuntimeException('Could not create the staged endpoint test body.');
        }
        return $stream;
    }

    private function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove_tree($path . '/' . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
