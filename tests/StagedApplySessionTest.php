<?php

use PHPUnit\Framework\TestCase;

final class StagedApplySessionTest extends TestCase {

    private string $root;
    private string $target;
    private string $storage;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/multipart-apply-' . bin2hex(random_bytes(8));
        $this->target = $this->root . '/target';
        $this->storage = $this->root . '/storage';
        mkdir($this->target, 0700, true);
        mkdir($this->storage, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->root);
    }

    public function testClassifiedExceptionKeepsProtocolReasonAndContextSeparateFromThrowableCode(): void {
        $exception = new Site_Export_Staged_Apply_Exception('busy', 'The session is busy.', ['operation' => 'stage']);

        $this->assertSame('busy', $exception->get_error_code());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('The session is busy.', $exception->getMessage());
        $this->assertSame(['operation' => 'stage'], $exception->get_context());
    }

    public function testPartialFileProgressComesFromTheFileAndOffsetZeroRestartsIt(): void {
        $session = $this->session('11111111111111111111111111111111');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '6',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'old',
        ]]);
        $this->assertSame(3, $session->get_status(['upload.bin'])['paths'][0]['accepted_bytes']);

        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $status = $session->get_status(['upload.bin']);
        $this->assertSame('complete', $status['paths'][0]['state']);
        $this->assertSame(3, $status['paths'][0]['accepted_bytes']);

        $this->commit_all($session);
        $this->assertSame('new', file_get_contents($this->target . '/upload.bin'));
    }

    public function testInvalidPartStopsBeforeTheFollowingPartIsRead(): void {
        $session = $this->session('22222222222222222222222222222222');
        $boundary = 'test-boundary';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('bad.bin') . "\r\n"
            . "X-File-Size: 2\r\nX-Chunk-Offset: 1\r\nContent-Length: 1\r\n\r\nx\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('must-not-be-read.bin') . "\r\n"
            . "X-File-Size: 1\r\nX-Chunk-Offset: 0\r\nContent-Length: 1\r\n\r\ny\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $session->next_change();
            $this->fail('An offset gap was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Start at offset 0', $exception->getMessage());
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/must-not-be-read.bin');
    }

    public function testProtectedAndInvalidPathsNeverReachStaging(): void {
        $invalid_paths = ['', '/absolute', '.', '..', 'a/../b', "nul\0path", 'wp-content/plugins/reprint/keep.php', '.maintenance'];
        foreach ($invalid_paths as $index => $path) {
            $session = $this->session(sprintf('%032x', $index + 10));
            try {
                $this->stage($session, [[
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode($path),
                        'X-File-Size' => '1',
                        'X-Chunk-Offset' => '0',
                    ],
                    'body' => 'x',
                ]]);
                $this->fail('Unsafe path was staged: ' . base64_encode($path));
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testStorageBelowTargetIsProtectedAndRootCanBeReopened(): void {
        $storage = $this->target . '/private-staging';
        $session = Site_Export_Staged_Apply_Session::create($storage, $this->target, [], str_repeat('a', 32));
        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('private-staging/escape.php'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('Session storage was writable through the push target.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Protected', $exception->getMessage());
        }

        $root_session = Site_Export_Staged_Apply_Session::create($this->storage, '/', [], str_repeat('b', 32));
        $reopened = Site_Export_Staged_Apply_Session::open($this->storage, '/', $root_session->get_session_id(), []);
        $this->assertSame($root_session->get_session_directory(), $reopened->get_session_directory());
    }

    public function testCompletedStagedSymlinkCannotBecomeAnotherStagedPathsParent(): void {
        $session = $this->session('33333333333333333333333333333333');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode('parent'),
                'X-Symlink-Target' => base64_encode('../outside'),
            ],
            'body' => '',
        ]]);

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent/child'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A staged symlink was traversed as a private parent.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be used as the parent', $exception->getMessage());
        }
    }

    public function testForeignMaintenanceStopsBeforeMutationAndOwnedMarkerRefreshes(): void {
        $session = $this->session('44444444444444444444444444444444');
        $this->stage_file($session, 'pending.txt', 'new');
        file_put_contents($this->target . '/.maintenance', "<?php\n\$upgrading = 1;\n");
        $this->complete_delete_upload($session);

        try {
            $session->commit(1);
            $this->fail('A foreign maintenance marker was replaced.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->target . '/pending.txt');
        unlink($this->target . '/.maintenance');

        $session->commit(1);
        $first = file_get_contents($this->target . '/.maintenance');
        $marker_blocks_request = function (array $query): bool {
            $previous_query = $_GET;
            $_GET = $query;
            try {
                include $this->target . '/.maintenance';
                return isset($upgrading);
            } finally {
                $_GET = $previous_query;
            }
        };
        $this->assertTrue($marker_blocks_request([]));
        $this->assertTrue($marker_blocks_request(['reprint-api' => '', 'endpoint' => 'preflight']));
        $this->assertFalse($marker_blocks_request(['reprint-api' => '', 'endpoint' => 'staged_session_commit']));
        sleep(1);
        $session->commit(1);
        $second = file_get_contents($this->target . '/.maintenance');
        $this->assertNotSame($first, $second);
        $this->commit_all($session);
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testCommitCannotBeDiscardedUntilItCompletes(): void {
        $session = $this->session('55555555555555555555555555555555');
        $this->stage_file($session, 'pending.txt', 'x');
        $this->complete_delete_upload($session);
        $session->commit(1);

        try {
            $session->discard_workspace();
            $this->fail('An active direct apply was discarded.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('commit_required', $exception->get_error_code());
        }

        $this->commit_all($session);
        $this->assertTrue($session->discard_workspace());
    }

    private function session(string $id): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage,
            $this->target,
            ['wp-content/plugins/reprint'],
            $id
        );
    }

    private function stage_file(Site_Export_Staged_Apply_Session $session, string $path, string $contents): void {
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($path),
                'X-File-Size' => (string) strlen($contents),
                'X-Chunk-Offset' => '0',
            ],
            'body' => $contents,
        ]]);
    }

    /** @param array<int,array{headers:array<string,string>,body:string}> $parts */
    private function stage(Site_Export_Staged_Apply_Session $session, array $parts): void {
        $boundary = 'test-boundary';
        $body = '';
        foreach ($parts as $part) {
            $body .= '--' . $boundary . "\r\n";
            foreach ($part['headers'] as $name => $value) {
                $body .= $name . ': ' . $value . "\r\n";
            }
            $body .= 'Content-Length: ' . strlen($part['body']) . "\r\n\r\n" . $part['body'] . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            while ($session->next_change()) {
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
    }

    private function commit_all(Site_Export_Staged_Apply_Session $session): void {
        if (!$session->get_status()['delete_upload_complete']) {
            $this->complete_delete_upload($session);
        }
        do {
            $result = $session->commit(4);
        } while ($result['send_next_request']);
    }

    private function complete_delete_upload(Site_Export_Staged_Apply_Session $session): void {
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) $session->get_status()['delete_bytes'],
                'X-Delete-Complete' => '1',
            ],
            'body' => '',
        ]]);
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
