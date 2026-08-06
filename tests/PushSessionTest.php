<?php

use PHPUnit\Framework\TestCase;

final class PushSessionTest extends TestCase {

    private string $root;
    private string $docroot;
    private string $reprint_directory;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/multipart-commit-' . bin2hex(random_bytes(8));
        $this->docroot = $this->root . '/docroot';
        $this->reprint_directory = $this->root . '/reprint';
        mkdir($this->docroot, 0700, true);
        mkdir($this->reprint_directory, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->root);
    }

    public function testClassifiedExceptionKeepsProtocolReasonAndContextSeparateFromThrowableCode(): void {
        $exception = new Site_Export_Push_Exception('lock_acquisition_failure', 'The session is busy.', ['operation' => 'receive']);

        $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame('The session is busy.', $exception->getMessage());
        $this->assertSame(['operation' => 'receive'], $exception->get_context());
    }

    public function testNewPushSessionHasOneCompletedTreeAndNoSecondPathShapedTree(): void {
        $push_session = $this->push_session('10101010101010101010101010101010');
        $work_directory = $push_session->get_push_directory() . '/work';

        $this->assertDirectoryExists($work_directory . '/files');
        $this->assertFileDoesNotExist($work_directory . '/partial');
        $this->assertFileDoesNotExist($work_directory . '/inflight.json');
        $this->assertFileDoesNotExist($work_directory . '/inflight.data');
        $push_metadata = json_decode(
            (string) file_get_contents($push_session->get_push_directory() . '/push.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertArrayNotHasKey('version', $push_metadata);
    }

    public function testPushSessionRejectsMoreThanOneHundredExcludedPaths(): void {
        $excluded_paths = [];
        for ($index = 0; $index < 101; ++$index) {
            $excluded_paths[] = 'excluded-' . $index;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100 excluded paths; received 101');
        Site_Export_Push_Session::create(
            $this->reprint_directory,
            $this->docroot,
            $excluded_paths,
            '11111111111111111111111111111110'
        );
    }

    public function testInFlightFileProgressComesFromTheDataFileAndOffsetZeroRestartsIt(): void {
        $push_session = $this->push_session('11111111111111111111111111111111');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '6',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'old',
        ]]);
        $this->assertSame(3, $push_session->get_status('upload.bin')['path']['accepted_bytes']);
        $inflight = json_decode(
            (string) file_get_contents($push_session->get_push_directory() . '/work/inflight.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertArrayNotHasKey('version', $inflight);

        foreach ([2, 4] as $offset) {
            try {
                $this->push_parts($push_session, [[
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode('upload.bin'),
                        'X-File-Size' => '6',
                        'X-Chunk-Offset' => (string) $offset,
                    ],
                    'body' => 'x',
                ]]);
                $this->fail('An in-flight file accepted cursor ' . $offset . ' while its data file contained 3 bytes.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('offset_gap', $exception->get_error_code());
            }
            $this->assertSame('old', file_get_contents($push_session->get_push_directory() . '/work/inflight.data'));
        }

        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $status = $push_session->get_status('upload.bin');
        $this->assertSame('complete', $status['path']['state']);
        $this->assertSame(3, $status['path']['accepted_bytes']);

        $this->commit_all($push_session);
        $this->assertSame('new', file_get_contents($this->docroot . '/upload.bin'));
    }

    public function testCurrentChangeReturnsEveryDocumentedShapeAndResets(): void {
        $push_session = $this->push_session('12121212121212121212121212121212');
        $boundary = 'current-change-boundary';
        $parts = [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('file.txt'),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'a',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('file.txt'),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '1',
                ],
                'body' => 'b',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('link'),
                    'X-Symlink-Target' => base64_encode('file.txt'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '0',
                ],
                'body' => "gone\0",
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '5',
                    'X-Delete-Complete' => '1',
                ],
                'body' => '',
            ],
        ];
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

        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $this->assertNull($push_session->get_current_change());
            $expected_changes = [
                ['path_b64' => base64_encode('file.txt'), 'state' => 'partial', 'type' => 'file', 'accepted_bytes' => 1],
                ['path_b64' => base64_encode('file.txt'), 'state' => 'complete', 'type' => 'file', 'accepted_bytes' => 2],
                ['path_b64' => base64_encode('empty'), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0],
                ['path_b64' => base64_encode('link'), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0],
                ['state' => 'partial', 'type' => 'delete-list', 'accepted_bytes' => 5],
                ['state' => 'complete', 'type' => 'delete-list', 'accepted_bytes' => 5],
            ];
            foreach ($expected_changes as $expected_change) {
                $this->assertTrue($push_session->next_change());
                $this->assertSame($expected_change, $push_session->get_current_change());
            }
            $this->assertFalse($push_session->next_change());
            $this->assertNull($push_session->get_current_change());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
        $this->assertNull($push_session->get_current_change());

        $input = fopen('php://temp', 'w+b');
        fwrite(
            $input,
            '--' . $boundary . "\r\n"
            . "X-Chunk-Type: directory\r\n"
            . 'X-Directory-Path: ' . base64_encode('empty') . "\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n"
        );
        rewind($input);
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $this->assertTrue($push_session->next_change());
            $this->assertNotNull($push_session->get_current_change());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
        $this->assertNull($push_session->get_current_change());
    }

    public function testInvalidPartStopsBeforeTheFollowingPartIsRead(): void {
        $push_session = $this->push_session('22222222222222222222222222222222');
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
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $push_session->next_change();
            $this->fail('An offset gap was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
            $this->assertStringContainsString('Start at offset 0', $exception->getMessage());
            $this->assertNull($push_session->get_current_change());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/files/must-not-be-read.bin');
    }

    public function testDeleteOffsetGapHasTheSameRecoverableProtocolReason(): void {
        $push_session = $this->push_session('23232323232323232323232323232323');

        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '1',
                ],
                'body' => "gone\0",
            ]]);
            $this->fail('A delete offset gap was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
            $this->assertStringContainsString('work delete stream has stored 0 bytes', $exception->getMessage());
        }
    }

    public function testExcludedAndInvalidPathsNeverReachWork(): void {
        $invalid_paths = [
            '',
            '/absolute',
            '.',
            '..',
            'a/../b',
            'a//b',
            'windows\\path',
            "nul\0path",
            str_repeat('x', 4097),
            'wp-content/plugins',
            'wp-content/plugins/reprint',
            'wp-content/plugins/reprint/keep.php',
            '.maintenance',
            '.maintenance/child',
        ];
        foreach ($invalid_paths as $index => $path) {
            $push_session = $this->push_session(sprintf('%032x', $index + 10));
            try {
                $this->push_parts($push_session, [[
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode($path),
                        'X-File-Size' => '1',
                        'X-Chunk-Offset' => '0',
                    ],
                    'body' => 'x',
                ]]);
                $this->fail('A reserved or excluded path was accepted as work: ' . base64_encode($path));
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testCommitTraversesAncestorsOfAnExcludedPathToInstallItsSibling(): void {
        $excluded_directory = $this->docroot . '/wp-content/plugins/reprint';
        mkdir($excluded_directory, 0700, true);
        file_put_contents($excluded_directory . '/sentinel.php', 'preserved');
        $push_session = $this->push_session('29292929292929292929292929292929');

        $this->push_file($push_session, 'wp-content/plugins/other/file.php', 'installed');
        $this->commit_all($push_session);

        $this->assertSame('installed', file_get_contents($this->docroot . '/wp-content/plugins/other/file.php'));
        $this->assertSame('preserved', file_get_contents($excluded_directory . '/sentinel.php'));
    }

    public function testReprintDirectoryBelowDocumentRootIsExcludedAndRootCanBeReopened(): void {
        $reprint_directory = $this->docroot . '/private-work';
        $push_session = Site_Export_Push_Session::create($reprint_directory, $this->docroot, [], str_repeat('a', 32));
        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('private-work/escape.php'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('The reprint directory was writable through the document root.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Excluded', $exception->getMessage());
        }

        $this->push_file($push_session, 'private-workshop/allowed.php', 'safe');
        $this->assertSame('complete', $push_session->get_status('private-workshop/allowed.php')['path']['state']);

        $root_session = Site_Export_Push_Session::create($this->reprint_directory, '/', [], str_repeat('b', 32));
        $reopened = Site_Export_Push_Session::open($this->reprint_directory, '/', $root_session->get_push_session_id(), []);
        $this->assertSame($root_session->get_push_directory(), $reopened->get_push_directory());
    }

    public function testCompletedWorkSymlinkCannotBecomeAnotherWorkPathsParent(): void {
        $push_session = $this->push_session('33333333333333333333333333333333');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode('parent'),
                'X-Symlink-Target' => base64_encode('../outside'),
            ],
            'body' => '',
        ]]);

        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent/child'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A work symlink was traversed as a private parent.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be used as the parent', $exception->getMessage());
        }
    }

    public function testCompletedEmptyDirectoryCanBecomeNonEmpty(): void {
        foreach (['file', 'directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 700 + $index));
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('parent'),
                ],
                'body' => '',
            ]]);

            if ($type === 'file') {
                $headers = [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent/child'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ];
                $body = 'x';
            } elseif ($type === 'directory') {
                $headers = [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('parent/child'),
                ];
                $body = '';
            } else {
                $headers = [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('parent/child'),
                    'X-Symlink-Target' => base64_encode('../elsewhere'),
                ];
                $body = '';
            }

            $this->push_parts($push_session, [['headers' => $headers, 'body' => $body]]);

            $this->assertSame('complete', $push_session->get_status('parent')['path']['state']);
            $this->assertSame('directory', $push_session->get_status('parent')['path']['type']);
            $this->assertSame('complete', $push_session->get_status('parent/child')['path']['state']);
            $this->assertSame($type, $push_session->get_status('parent/child')['path']['type']);
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/inflight.json');
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/inflight.data');
        }
    }

    public function testForeignMaintenanceStopsBeforeMutationAndOwnedMarkerRefreshes(): void {
        $push_session = $this->push_session('44444444444444444444444444444444');
        $this->push_file($push_session, 'pending.txt', 'new');
        file_put_contents($this->docroot . '/.maintenance', "<?php\n\$upgrading = 1;\n");
        $this->complete_work_deletes($push_session);

        try {
            $push_session->commit(1);
            $this->fail('A foreign maintenance marker was replaced.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->docroot . '/pending.txt');
        unlink($this->docroot . '/.maintenance');

        $push_session->commit(1);
        $first = file_get_contents($this->docroot . '/.maintenance');
        $marker_blocks_request = function (array $query): bool {
            $previous_query = $_GET;
            $_GET = $query;
            try {
                include $this->docroot . '/.maintenance';
                return isset($upgrading);
            } finally {
                $_GET = $previous_query;
            }
        };
        $this->assertStringContainsString('// reprint-push-session:' . $push_session->get_push_session_id(), (string) $first);
        $this->assertTrue($marker_blocks_request([]));
        $this->assertTrue($marker_blocks_request(['reprint-api' => '', 'endpoint' => 'preflight']));
        $this->assertFalse($marker_blocks_request(['reprint-api' => '', 'endpoint' => 'push_commit']));
        sleep(1);
        $push_session->commit(1);
        $second = file_get_contents($this->docroot . '/.maintenance');
        $this->assertNotSame($first, $second);
        $this->commit_all($push_session);
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
    }

    public function testCommitCannotBeRemovedUntilItCompletes(): void {
        $push_session = $this->push_session('55555555555555555555555555555555');
        $this->push_file($push_session, 'pending.txt', 'x');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);

        try {
            $push_session->remove_push_directory();
            $this->fail('An active direct commit was removed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('commit_required', $exception->get_error_code());
        }

        $this->commit_all($push_session);
        $this->assertTrue($push_session->remove_push_directory());
    }

    public function testPushSessionLockExcludesOtherOperationsUntilUploadFinishes(): void {
        $push_session = $this->push_session('66666666666666666666666666666666');
        $reopened = Site_Export_Push_Session::open(
            $this->reprint_directory,
            $this->docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint']
        );
        $input = fopen('php://temp', 'w+b');
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor('test-boundary'));

        try {
            $reopened->get_status();
            $this->fail('Status observed a session while an upload held its lock.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }

        try {
            $push_session->accept_upload($input, new Site_Export_Multipart_Processor('test-boundary'));
            $this->fail('One session object accepted two simultaneous uploads.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already open', $exception->getMessage());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }

        $this->assertSame('receiving_work', $reopened->get_status()['phase']);
    }

    public function testPushSessionLockIsReleasedWhenTheUploadProcessDies(): void {
        $push_session = $this->push_session('67676767676767676767676767676768');
        $ready_path = $this->root . '/upload-lock-ready';
        $configuration = base64_encode(json_encode([
            'bootstrap_path' => __DIR__ . '/bootstrap.php',
            'reprint_directory' => $this->reprint_directory,
            'docroot' => $this->docroot,
            'push_session_id' => $push_session->get_push_session_id(),
            'ready_path' => $ready_path,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $script = '$configuration = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);'
            . 'require $configuration["bootstrap_path"];'
            . '$push_session = Site_Export_Push_Session::open($configuration["reprint_directory"], $configuration["docroot"], $configuration["push_session_id"], ["wp-content/plugins/reprint"]);'
            . '$input = fopen("php://temp", "w+b");'
            . '$push_session->accept_upload($input, new Site_Export_Multipart_Processor("dead-owner-boundary"));'
            . 'file_put_contents($configuration["ready_path"], "ready");'
            . 'sleep(30);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $configuration],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($ready_path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($ready_path);

        $process_closed = false;
        try {
            try {
                $push_session->get_status();
                $this->fail('Status acquired the push lock while another process held an upload open.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
            }

            $this->assertTrue(proc_terminate($process, 9));
            proc_close($process);
            $process_closed = true;
        } finally {
            if (!$process_closed) {
                @proc_terminate($process, 9);
                proc_close($process);
            }
        }
        $this->assertSame('receiving_work', $push_session->get_status()['phase']);
    }

    public function testTruncatedUploadDoesNotBecomeACompleteChangeAndReleasesCleanly(): void {
        $push_session = $this->push_session('77777777777777777777777777777777');
        $boundary = 'test-boundary';
        $input = fopen('php://temp', 'w+b');
        fwrite(
            $input,
            '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('truncated.bin') . "\r\n"
            . "X-File-Size: 5\r\nX-Chunk-Offset: 0\r\nContent-Length: 5\r\n\r\nabc"
        );
        rewind($input);
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $push_session->next_change();
            $this->fail('A truncated multipart body was accepted as a complete change.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('declared content-length', strtolower($exception->getMessage()));
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }

        $reopened = Site_Export_Push_Session::open(
            $this->reprint_directory,
            $this->docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint']
        );
        $this->assertSame('receiving_work', $reopened->get_status()['phase']);
        $this->assertNotSame('complete', $reopened->get_status('truncated.bin')['path']['state']);
    }

    public function testMultipartCloseAtTheInputFragmentLimitReadsThroughEof(): void {
        $push_session = $this->push_session('78787878787878787878787878787878');
        [$boundary, $body] = $this->multipart_body_ending_at_input_fragment_limit();
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);

        $push_session->accept_upload(
            $input,
            new Site_Export_Multipart_Processor($boundary),
            PHP_INT_MAX,
            Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES
        );
        try {
            $this->assertTrue($push_session->next_change());
            $this->assertFalse($push_session->next_change());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
    }

    public function testByteAfterMultipartCloseExceedsTheRequestLimit(): void {
        $push_session = $this->push_session('79797979797979797979797979797979');
        [$boundary, $body] = $this->multipart_body_ending_at_input_fragment_limit();
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body . 'x');
        rewind($input);

        $push_session->accept_upload(
            $input,
            new Site_Export_Multipart_Processor($boundary),
            PHP_INT_MAX,
            Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES
        );
        try {
            $this->assertTrue($push_session->next_change());
            try {
                $push_session->next_change();
                $this->fail('A byte beyond the decoded request-body limit was ignored after the closing boundary.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('request_too_large', $exception->get_error_code());
                $this->assertSame(
                    ['observed_request_body_bytes' => Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES + 1],
                    $exception->get_context()
                );
                $this->assertStringContainsString(
                    (string) ( Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES + 1 ) . ' bytes',
                    $exception->getMessage()
                );
            }
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
    }

    public function testByteAfterMultipartCloseIsInvalidWithinTheRequestLimit(): void {
        $push_session = $this->push_session('7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a');
        [$boundary, $body] = $this->multipart_body_ending_at_input_fragment_limit();
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body . 'x');
        rewind($input);

        $push_session->accept_upload(
            $input,
            new Site_Export_Multipart_Processor($boundary),
            PHP_INT_MAX,
            Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES + 1
        );
        try {
            $this->assertTrue($push_session->next_change());
            try {
                $push_session->next_change();
                $this->fail('A byte after the closing multipart boundary was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('after the closing boundary', $exception->getMessage());
            }
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
    }

    public function testMalformedMultipartFieldsAreRejectedBeforeWork(): void {
        $cases = [
            [
                'headers' => ['X-Chunk-Type' => 'unknown'],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => '***',
                    'X-File-Size' => '0',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('negative.bin'),
                    'X-File-Size' => '-1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('overflow.bin'),
                    'X-File-Size' => str_repeat('9', 100),
                    'X-Chunk-Offset' => '0',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('foreign-header.bin'),
                    'X-File-Size' => '0',
                    'X-Chunk-Offset' => '0',
                    'X-Symlink-Target' => base64_encode('elsewhere'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('nonempty-directory'),
                ],
                'body' => 'x',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('empty-docroot'),
                    'X-Symlink-Target' => base64_encode(''),
                ],
                'body' => '',
            ],
        ];

        foreach ($cases as $index => $part) {
            $push_session = $this->push_session(sprintf('%032x', 100 + $index));
            try {
                $this->push_parts($push_session, [$part]);
                $this->fail('Malformed multipart case ' . $index . ' was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_diff(
                scandir($push_session->get_push_directory() . '/work/files') ?: [],
                ['.', '..']
            )));
        }
    }

    public function testPartByteCeilingRejectsBeforeWritingItsBody(): void {
        $push_session = $this->push_session('12341234123412341234123412341234');
        $boundary = 'test-boundary';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('too-large.bin') . "\r\n"
            . "X-File-Size: 2\r\nX-Chunk-Offset: 0\r\nContent-Length: 2\r\n\r\nxx\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary), 1);
        try {
            $push_session->next_change();
            $this->fail('A part above the configured byte ceiling was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeds the document-root maximum', $exception->getMessage());
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
        $this->assertSame('missing', $push_session->get_status('too-large.bin')['path']['state']);
    }

    public function testMalformedMetadataAndPushDirectorySymlinksAreRejected(): void {
        $push_metadata_session = $this->push_session('23452345234523452345234523452345');
        file_put_contents($push_metadata_session->get_push_directory() . '/push.json', '{not-json');
        try {
            $push_metadata_session->get_status();
            $this->fail('Malformed push metadata was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }

        $shape_session = $this->push_session('34563456345634563456345634563455');
        $push_metadata_path = $shape_session->get_push_directory() . '/push.json';
        $push_metadata = json_decode( (string) file_get_contents($push_metadata_path), true, 512, JSON_THROW_ON_ERROR);
        $push_metadata['excluded_paths_b64'] = 'not-a-list';
        file_put_contents($push_metadata_path, json_encode($push_metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $shape_session->get_status();
            $this->fail('Push metadata with a scalar excluded-path list was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('excluded paths', $exception->getMessage());
        }

        $symlink_session = $this->push_session('34563456345634563456345634563456');
        $files = $symlink_session->get_push_directory() . '/work/files';
        rmdir($files);
        symlink($this->docroot, $files);
        try {
            $symlink_session->get_status();
            $this->fail('A symlink replaced the private completed-files directory.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('not real', $exception->getMessage());
        }
    }

    public function testMalformedInFlightFilesAreClassifiedAsCorruption(): void {
        $malformed_session = $this->push_session('34563456345634563456345634563457');
        file_put_contents($malformed_session->get_push_directory() . '/work/inflight.json', '{not-json');
        try {
            $malformed_session->get_status();
            $this->fail('Malformed in-flight JSON was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('does not contain a JSON object', $exception->getMessage());
        }

        $oversized_session = $this->push_session('34563456345634563456345634563458');
        file_put_contents($oversized_session->get_push_directory() . '/work/inflight.json', str_repeat('x', 1048577));
        try {
            $oversized_session->get_status();
            $this->fail('Oversized in-flight JSON was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('not a bounded regular file', $exception->getMessage());
        }

        $wrong_type_session = $this->push_session('34563456345634563456345634563459');
        mkdir($wrong_type_session->get_push_directory() . '/work/inflight.data');
        try {
            $wrong_type_session->get_status();
            $this->fail('A directory was accepted as in-flight file data.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('unsupported type', $exception->getMessage());
        }
    }

    public function testSourceKeepsOnePathShapedWorkTreeAndSharedInFlightHelpers(): void {
        $source = file_get_contents(__DIR__ . '/../packages/reprint-server/src/class-push-session.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('work/partial', $source);
        $this->assertStringNotContainsString('work_partial', $source);
        $this->assertStringNotContainsString('first_tree_entry', $source);
        foreach (['read_inflight', 'finish_inflight_completion'] as $method) {
            $this->assertGreaterThanOrEqual(2, substr_count($source, '$this->' . $method . '('), $method . ' must remain shared by multiple callers.');
        }
    }

    public function testReopenRejectsDocumentRootAndExcludedPathConfigurationDrift(): void {
        $push_session = $this->push_session('45674567456745674567456745674567');
        $other_docroot = $this->root . '/other-docroot';
        mkdir($other_docroot, 0700);
        $changed_docroot = Site_Export_Push_Session::open(
            $this->reprint_directory,
            $other_docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint']
        );
        $changed_protection = Site_Export_Push_Session::open(
            $this->reprint_directory,
            $this->docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint', 'wp-config.php']
        );

        foreach ([$changed_docroot, $changed_protection] as $reopened) {
            try {
                $reopened->get_status();
                $this->fail('A session reopened with different immutable configuration.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    public function testRepeatedCreateRejectsExcludedPathConfigurationDriftImmediately(): void {
        $push_session = $this->push_session('45674567456745674567456745674568');

        try {
            Site_Export_Push_Session::create(
                $this->reprint_directory,
                $this->docroot,
                ['wp-content/plugins/reprint', 'wp-config.php'],
                $push_session->get_push_session_id()
            );
            $this->fail('Repeated create accepted different immutable excluded paths.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
    }

    public function testCompletedTypeChangeDiscardsInFlightFileDataAtTheSamePath(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 500 + $index));
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('changing'),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'a',
            ]]);
            $headers = $type === 'directory'
                ? [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('changing'),
                ]
                : [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('changing'),
                    'X-Symlink-Target' => base64_encode('../elsewhere'),
                ];
            $this->push_parts($push_session, [['headers' => $headers, 'body' => '']]);

            $this->assertSame('complete', $push_session->get_status('changing')['path']['state']);
            $this->assertSame($type, $push_session->get_status('changing')['path']['type']);
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/inflight.data');
        }
    }

    public function testStatusReportsMissingInFlightAndEveryCompletedValueType(): void {
        $push_session = $this->push_session('88888888888888888888888888888888');
        $raw_path = "line\nbreak.bin";
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($raw_path),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'a',
        ]]);

        $this->assertSame(
            ['path_b64' => base64_encode('missing'), 'state' => 'missing', 'accepted_bytes' => 0],
            $push_session->get_status('missing')['path']
        );
        $this->assertSame(
            ['path_b64' => base64_encode($raw_path), 'state' => 'partial', 'type' => 'file', 'accepted_bytes' => 1],
            $push_session->get_status($raw_path)['path']
        );

        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($raw_path),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '1',
            ],
            'body' => 'b',
        ]]);
        $this->push_parts($push_session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('empty.bin'),
                    'X-File-Size' => '0',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty-directory'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('link'),
                    'X-Symlink-Target' => base64_encode('../docroot'),
                ],
                'body' => '',
            ],
        ]);

        $this->assertSame(
            ['path_b64' => base64_encode($raw_path), 'state' => 'complete', 'type' => 'file', 'accepted_bytes' => 2],
            $push_session->get_status($raw_path)['path']
        );
        $this->assertSame(
            ['path_b64' => base64_encode('empty.bin'), 'state' => 'complete', 'type' => 'file', 'accepted_bytes' => 0],
            $push_session->get_status('empty.bin')['path']
        );
        $this->assertSame(
            ['path_b64' => base64_encode('empty-directory'), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0],
            $push_session->get_status('empty-directory')['path']
        );
        $this->assertSame(
            ['path_b64' => base64_encode('link'), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0],
            $push_session->get_status('link')['path']
        );
        $this->assertSame([
            'push_session_id' => '88888888888888888888888888888888',
            'phase' => 'receiving_work',
            'work_deletes_bytes' => 0,
            'work_deletes_complete' => false,
            'path' => null,
        ], $push_session->get_status());
    }

    public function testCompletedFileReplayRequiresItsExactEmptyEndCursor(): void {
        $push_session = $this->push_session('99999999999999999999999999999999');
        $this->push_file($push_session, 'complete.bin', 'abc');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('complete.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '3',
            ],
            'body' => '',
        ]]);
        $this->assertSame('complete', $push_session->get_status('complete.bin')['path']['state']);

        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('complete.bin'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '2',
                ],
                'body' => 'c',
            ]]);
            $this->fail('A completed file accepted a stale replay cursor.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
        }
    }

    public function testInFlightFileCannotBecomeAParentOfCompletedValues(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 200 + $index));
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent'),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'a',
            ]]);
            $headers = $type === 'directory'
                ? [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('parent/child'),
                ]
                : [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('parent/child'),
                    'X-Symlink-Target' => base64_encode('../elsewhere'),
                ];

            try {
                $this->push_parts($push_session, [['headers' => $headers, 'body' => '']]);
                $this->fail('An in-flight file became the parent of a completed ' . $type . '.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
            }
            $this->assertSame('a', file_get_contents($push_session->get_push_directory() . '/work/inflight.data'));
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/files/parent/child');
        }
    }

    public function testCompletedLeafCannotHideInFlightDescendants(): void {
        foreach (['file', 'directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 300 + $index));
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent/child'),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'a',
            ]]);
            if ($type === 'file') {
                $headers = [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('parent'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ];
                $body = 'x';
            } elseif ($type === 'directory') {
                $headers = [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('parent'),
                ];
                $body = '';
            } else {
                $headers = [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('parent'),
                    'X-Symlink-Target' => base64_encode('../elsewhere'),
                ];
                $body = '';
            }

            try {
                $this->push_parts($push_session, [['headers' => $headers, 'body' => $body]]);
                $this->fail('A completed ' . $type . ' hid an in-flight descendant.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
            }
            $this->assertSame('a', file_get_contents($push_session->get_push_directory() . '/work/inflight.data'));
        }
    }

    public function testOnlyTheMatchingPathCanUseTheInFlightSlot(): void {
        $push_session = $this->push_session('77777777777777777777777777777777');
        try {
            $this->push_parts($push_session, [
                [
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode('first.txt'),
                        'X-File-Size' => '2',
                        'X-Chunk-Offset' => '0',
                    ],
                    'body' => 'a',
                ],
                [
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode('second.txt'),
                        'X-File-Size' => '1',
                        'X-Chunk-Offset' => '0',
                    ],
                    'body' => 'b',
                ],
            ]);
            $this->fail('A different path replaced in-flight work in the same request.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }

        $this->assertSame(
            ['path_b64' => base64_encode('first.txt'), 'state' => 'partial', 'type' => 'file', 'accepted_bytes' => 1],
            $push_session->get_status('first.txt')['path']
        );
        $this->assertSame(
            ['path_b64' => base64_encode('second.txt'), 'state' => 'missing', 'accepted_bytes' => 0],
            $push_session->get_status('second.txt')['path']
        );
        $this->assertSame('a', file_get_contents($push_session->get_push_directory() . '/work/inflight.data'));
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/files/second.txt');
    }

    public function testInFlightFileStatusShadowsThePreviousCompletedFile(): void {
        $push_session = $this->push_session('67676767676767676767676767676767');
        $this->push_file($push_session, 'same-size.txt', 'old');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('same-size.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'n',
        ]]);

        $status = $push_session->get_status('same-size.txt')['path'];
        $this->assertSame('partial', $status['state']);
        $this->assertSame('file', $status['type']);
        $this->assertSame(1, $status['accepted_bytes']);
        $this->assertSame('old', file_get_contents($push_session->get_push_directory() . '/work/files/same-size.txt'));

        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('same-size.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '1',
            ],
            'body' => 'ew',
        ]]);
        $this->assertSame('new', file_get_contents($push_session->get_push_directory() . '/work/files/same-size.txt'));
    }

    public function testCommitRejectsInFlightWorkBeforeWritingACheckpoint(): void {
        $push_session = $this->push_session('56565656565656565656565656565656');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('inflight.txt'),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'x',
        ]]);
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "gone.txt\0",
        ]]);
        $this->complete_work_deletes($push_session);
        $this->assertSame(9, $push_session->get_status()['work_deletes_bytes']);
        $this->assertTrue($push_session->get_status()['work_deletes_complete']);

        try {
            $push_session->commit(1);
            $this->fail('Commit accepted in-flight work.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('in flight', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/commit.json');
        $this->assertFileDoesNotExist($this->docroot . '/inflight.txt');
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
    }

    public function testFileCompletionRecoversAtBothDurableBoundaries(): void {
        $push_session = $this->push_session('34343434343434343434343434343434');
        $work_directory = $push_session->get_push_directory() . '/work';
        $this->push_file($push_session, 'same-size.txt', 'old');

        $this->run_upload_with_filesystem_fault($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('same-size.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]], 'unlink', '/work/files/same-size.txt');

        $this->assertSame('old', file_get_contents($work_directory . '/files/same-size.txt'));
        $this->assertSame('new', file_get_contents($work_directory . '/inflight.data'));
        $this->assertFileExists($work_directory . '/inflight.json');
        $this->assertSame('complete', $push_session->get_status('same-size.txt')['path']['state']);
        $this->assertSame('new', file_get_contents($work_directory . '/files/same-size.txt'));
        $this->assertFileDoesNotExist($work_directory . '/inflight.json');
        $this->assertFileDoesNotExist($work_directory . '/inflight.data');

        $push_session = $this->push_session('45454545454545454545454545454545');
        $work_directory = $push_session->get_push_directory() . '/work';
        $this->run_upload_with_filesystem_fault($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('commit.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]], 'unlink', '/work/inflight.json');

        $this->assertSame('new', file_get_contents($work_directory . '/files/commit.txt'));
        $this->assertFileDoesNotExist($work_directory . '/inflight.data');
        $this->assertFileExists($work_directory . '/inflight.json');
        $this->complete_work_deletes($push_session);
        $this->commit_all($push_session);
        $this->assertFileDoesNotExist($work_directory . '/inflight.json');
        $this->assertSame('new', file_get_contents($this->docroot . '/commit.txt'));
    }

    /**
     * Applies the process umask when directory completion resumes after a failure.
     *
     * 0777 is the pre-umask ceiling, so a 0027 umask creates both normal and
     * recovered directories as 0750. Commit preserves that mode when it renames
     * the work directory into the document root.
     */
    public function testRecoveredDirectoryUsesTheDocumentRootProcessUmask(): void {
        $previous_umask = umask(0027);
        try {
            $normal_session = $this->push_session('60606060606060606060606060606060');
            $this->push_parts($normal_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('normal-directory'),
                ],
                'body' => '',
            ]]);

            $recovered_session = $this->push_session('61616161616161616161616161616161');
            $this->run_upload_with_filesystem_fault($recovered_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('recovered-directory'),
                ],
                'body' => '',
            ]], 'mkdir', '/work/files/recovered-directory');
            $recovered_session->get_status('recovered-directory');

            $this->complete_work_deletes($normal_session);
            $this->commit_all($normal_session);
            $this->complete_work_deletes($recovered_session);
            $this->commit_all($recovered_session);

            clearstatcache(true, $this->docroot . '/normal-directory');
            clearstatcache(true, $this->docroot . '/recovered-directory');
            $normal_mode = fileperms($this->docroot . '/normal-directory') & 0777;
            $recovered_mode = fileperms($this->docroot . '/recovered-directory') & 0777;

            $this->assertSame(0750, $normal_mode);
            $this->assertSame($normal_mode, $recovered_mode);
        } finally {
            umask($previous_umask);
        }
    }

    public function testDirectoryAndSymlinkCompletionRecoverBeforeAndAfterLeafCreation(): void {
        foreach (['directory', 'symlink'] as $type_index => $type) {
            foreach (['create', 'clear'] as $boundary_index => $boundary) {
                $push_session = $this->push_session(sprintf('%032x', 800 + ( $type_index * 10 ) + $boundary_index));
                $work_directory = $push_session->get_push_directory() . '/work';
                $path = 'nested/' . $type . '-' . $boundary;
                if ($type === 'directory') {
                    $headers = [
                        'X-Chunk-Type' => 'directory',
                        'X-Directory-Path' => base64_encode($path),
                    ];
                    $operation = 'mkdir';
                } else {
                    $headers = [
                        'X-Chunk-Type' => 'symlink',
                        'X-Symlink-Path' => base64_encode($path),
                        'X-Symlink-Target' => base64_encode('../target'),
                    ];
                    $operation = 'symlink';
                }
                $fault_operation = $boundary === 'create' ? $operation : 'unlink';
                $fault_path_suffix = $boundary === 'create' ? '/work/files/' . $path : '/work/inflight.json';

                $this->run_upload_with_filesystem_fault(
                    $push_session,
                    [['headers' => $headers, 'body' => '']],
                    $fault_operation,
                    $fault_path_suffix
                );

                $this->assertFileExists($work_directory . '/inflight.json');
                if ($boundary === 'create') {
                    $this->assertFileDoesNotExist($work_directory . '/files/' . $path);
                    $this->assertDirectoryExists($work_directory . '/files/nested');
                } elseif ($type === 'directory') {
                    $this->assertDirectoryExists($work_directory . '/files/' . $path);
                } else {
                    $this->assertTrue(is_link($work_directory . '/files/' . $path));
                }

                $this->assertSame(
                    [
                        'path_b64' => base64_encode($path),
                        'state' => 'complete',
                        'type' => $type,
                        'accepted_bytes' => 0,
                    ],
                    $push_session->get_status($path)['path']
                );
                $this->assertFileDoesNotExist($work_directory . '/inflight.json');
            }
        }
    }

    public function testStatusReportsPreparingDirectoryAndSymlinkFromAProductionFailure(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 900 + $index));
            $path = $type . '-replacement';
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode($path),
                    'X-File-Size' => '2',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $headers = $type === 'directory'
                ? [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode($path),
                ]
                : [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode($path),
                    'X-Symlink-Target' => base64_encode('../target'),
                ];

            $this->run_upload_with_filesystem_fault(
                $push_session,
                [['headers' => $headers, 'body' => '']],
                'unlink',
                '/work/inflight.data'
            );

            $this->assertSame(
                ['path_b64' => base64_encode($path), 'state' => 'partial', 'type' => $type, 'accepted_bytes' => 0],
                $push_session->get_status($path)['path']
            );
            $this->push_parts($push_session, [['headers' => $headers, 'body' => '']]);
            $this->assertSame('complete', $push_session->get_status($path)['path']['state']);
        }
    }

    public function testDocumentRootClaimBlocksAnotherCommitWithoutBlockingItsStatus(): void {
        $first = $this->push_session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $second = $this->push_session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->push_file($first, 'first.txt', 'first');
        $this->push_file($second, 'second.txt', 'second');
        $this->complete_work_deletes($first);
        $this->complete_work_deletes($second);
        $first->commit(1);

        $this->assertSame('receiving_work', $second->get_status()['phase']);
        try {
            $second->commit(1);
            $this->fail('A second push session committed while the document root was claimed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->docroot . '/second.txt');
        $this->commit_all($first);
        $this->commit_all($second);
        $this->assertSame('second', file_get_contents($this->docroot . '/second.txt'));
    }

    public function testRemoveIsBoundedAndNeverFollowsAPushDirectorySymlink(): void {
        $push_session = $this->push_session('cccccccccccccccccccccccccccccccc');
        $files = $push_session->get_push_directory() . '/work/files';
        for ($index = 0; $index < 300; ++$index) {
            file_put_contents($files . '/entry-' . $index, 'x');
        }
        $outside = $this->root . '/outside';
        mkdir($outside, 0700);
        file_put_contents($outside . '/sentinel', 'safe');
        symlink($outside, $files . '/outside-link');

        $this->assertFalse($push_session->remove_push_directory());
        $this->assertFileExists($outside . '/sentinel');
        do {
            $remove_complete = $push_session->remove_push_directory();
        } while (!$remove_complete);
        $this->assertFileExists($outside . '/sentinel');
        $this->assertDirectoryDoesNotExist($push_session->get_push_directory());
    }

    public function testCreateRemoveLockContentionLeavesTheLivePushDirectoryUntouched(): void {
        $push_session = $this->push_session('cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd');
        $push_directory = $push_session->get_push_directory();
        $create_remove_lock_path = dirname($push_directory) . '/create-remove.lock';
        $lock_process = $this->start_lock_process($create_remove_lock_path);

        $failure = null;
        try {
            $push_session->remove_push_directory();
        } catch (Site_Export_Push_Exception $exception) {
            $failure = $exception;
        } finally {
            $this->stop_lock_process($lock_process);
        }

        $this->assertInstanceOf(Site_Export_Push_Exception::class, $failure);
        $this->assertSame('lock_acquisition_failure', $failure->get_error_code());
        $this->assertDirectoryExists($push_directory);
        $this->assertFileDoesNotExist(dirname($push_directory) . '/.removing-' . $push_session->get_push_session_id());
    }

    public function testRemovalTombstoneBlocksCreateAndConvergesUnderTheCreateRemoveLock(): void {
        $push_session_id = 'dededededededededededededededede';
        $push_session = $this->push_session($push_session_id);
        $parts = [];
        for ($index = 0; $index < 300; ++$index) {
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('remove-entry-' . $index),
                ],
                'body' => '',
            ];
        }
        $this->push_parts($push_session, $parts);

        $push_directory = $push_session->get_push_directory();
        $push_sessions_directory = dirname($push_directory);
        $tombstone = $push_sessions_directory . '/.removing-' . $push_session_id;
        $this->assertFalse($push_session->remove_push_directory());
        $this->assertDirectoryDoesNotExist($push_directory);
        $this->assertDirectoryExists($tombstone);

        try {
            $this->push_session($push_session_id);
            $this->fail('A push session was recreated while its removal tombstone remained.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertDirectoryDoesNotExist($push_directory);

        $lock_process = $this->start_lock_process($push_sessions_directory . '/create-remove.lock');
        $failure = null;
        try {
            $push_session->remove_push_directory();
        } catch (Site_Export_Push_Exception $exception) {
            $failure = $exception;
        } finally {
            $this->stop_lock_process($lock_process);
        }
        $this->assertInstanceOf(Site_Export_Push_Exception::class, $failure);
        $this->assertSame('lock_acquisition_failure', $failure->get_error_code());
        $this->assertDirectoryExists($tombstone);

        $removal_calls = 0;
        do {
            $removed = $push_session->remove_push_directory();
            ++$removal_calls;
            $this->assertLessThan(10, $removal_calls, 'Bounded removal did not converge.');
        } while (!$removed);
        $this->assertTrue($push_session->remove_push_directory());
        $this->assertDirectoryDoesNotExist($tombstone);

        $recreated = $this->push_session($push_session_id);
        $this->assertSame('receiving_work', $recreated->get_status()['phase']);
    }

    /**
     * Runs an upload while one named filesystem call fails in production code.
     *
     * The upload runs in a separate PHP process with a small shared library
     * interposed at the libc boundary. The library fails only the requested
     * operation and path suffix. This leaves the same durable state that the
     * production method wrote before the failed call, without rewriting private
     * push-session files in the test.
     *
     * @param Site_Export_Push_Session $push_session Push session to reopen in the child process.
     * @param array<int,array{headers:array<string,string>,body:string}> $parts Multipart parts to upload.
     * @param string $operation One of unlink, mkdir, or symlink.
     * @param string $path_suffix Absolute-path suffix whose operation must fail.
     * @return array{class:string,reason:string|null,message:string} Classified child-process exception.
     */
    private function run_upload_with_filesystem_fault(
        Site_Export_Push_Session $push_session,
        array $parts,
        string $operation,
        string $path_suffix
    ): array {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            $this->markTestSkipped('Filesystem completion fault tests require libc interposition on Linux or macOS.');
        }

        $source_path = __DIR__ . '/fixtures/push-session-filesystem-fault.c';
        $library_extension = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $library_path = sys_get_temp_dir() . '/reprint-push-session-fault-' . hash_file('sha256', $source_path) . '.' . $library_extension;
        if (!is_file($library_path)) {
            $compile_command = PHP_OS_FAMILY === 'Darwin'
                ? ['cc', '-dynamiclib', '-O2', '-o', $library_path, $source_path]
                : ['cc', '-shared', '-fPIC', '-O2', '-o', $library_path, $source_path, '-ldl'];
            $compile_descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $compile_process = proc_open($compile_command, $compile_descriptors, $compile_pipes);
            $this->assertIsResource($compile_process, 'Could not start the filesystem fault-library compiler.');
            fclose($compile_pipes[0]);
            $compile_stdout = stream_get_contents($compile_pipes[1]);
            $compile_stderr = stream_get_contents($compile_pipes[2]);
            fclose($compile_pipes[1]);
            fclose($compile_pipes[2]);
            $compile_exit_code = proc_close($compile_process);
            $this->assertSame(0, $compile_exit_code, "Could not compile the filesystem fault library.\n" . $compile_stdout . $compile_stderr);
        }

        $boundary = 'filesystem-fault-boundary';
        $body = '';
        foreach ($parts as $part) {
            $body .= '--' . $boundary . "\r\n";
            foreach ($part['headers'] as $name => $value) {
                $body .= $name . ': ' . $value . "\r\n";
            }
            $body .= 'Content-Length: ' . strlen($part['body']) . "\r\n\r\n" . $part['body'] . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
        $configuration = base64_encode(json_encode([
            'reprint_directory' => $this->reprint_directory,
            'docroot' => $this->docroot,
            'push_session_id' => $push_session->get_push_session_id(),
            'excluded_paths' => ['wp-content/plugins/reprint'],
            'boundary' => $boundary,
            'body_b64' => base64_encode($body),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['REPRINT_FAULT_OPERATION'] = $operation;
        $environment['REPRINT_FAULT_PATH_SUFFIX'] = $path_suffix;
        if (PHP_OS_FAMILY === 'Darwin') {
            $environment['DYLD_INSERT_LIBRARIES'] = $library_path;
            $environment['DYLD_FORCE_FLAT_NAMESPACE'] = '1';
        } else {
            $environment['LD_PRELOAD'] = $library_path;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/run-push-session-upload.php', $configuration],
            $descriptors,
            $pipes,
            null,
            $environment
        );
        $this->assertIsResource($process, 'Could not start the faulted push-session upload process.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $this->assertSame(73, $exit_code, "The requested filesystem call did not fail.\nstdout: " . $stdout . "\nstderr: " . $stderr);
        $failure = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(Site_Export_Push_Exception::class, $failure['class']);
        $this->assertSame('filesystem_error', $failure['reason']);
        return $failure;
    }

    private function push_session(string $id): Site_Export_Push_Session {
        return Site_Export_Push_Session::create(
            $this->reprint_directory,
            $this->docroot,
            ['wp-content/plugins/reprint'],
            $id
        );
    }

    /** @return array{string,string} */
    private function multipart_body_ending_at_input_fragment_limit(): array {
        $boundary = 'fragment-limit-boundary';
        $path = 'fragment-limit.bin';
        $suffix = "\r\n--" . $boundary . "--\r\n";
        $payload_bytes = Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES;
        do {
            $previous_payload_bytes = $payload_bytes;
            $prefix = '--' . $boundary . "\r\n"
                . "X-Chunk-Type: file\r\n"
                . 'X-File-Path: ' . base64_encode($path) . "\r\n"
                . 'X-File-Size: ' . $payload_bytes . "\r\n"
                . "X-Chunk-Offset: 0\r\n"
                . 'Content-Length: ' . $payload_bytes . "\r\n\r\n";
            $payload_bytes = Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES
                - strlen($prefix)
                - strlen($suffix);
        } while ($payload_bytes !== $previous_payload_bytes);

        $body = $prefix . str_repeat('x', $payload_bytes) . $suffix;
        $this->assertSame(Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES, strlen($body));
        return [$boundary, $body];
    }

    /** @return resource */
    private function start_lock_process(string $lock_path) {
        $ready_path = $this->root . '/lock-ready-' . bin2hex(random_bytes(4));
        $script = '$lock = fopen($argv[1], "c+b");'
            . 'if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(2); }'
            . 'file_put_contents($argv[2], "ready");'
            . 'sleep(30);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $lock_path, $ready_path],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($ready_path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($ready_path);
        unlink($ready_path);
        return $process;
    }

    /** @param resource $process */
    private function stop_lock_process($process): void {
        @proc_terminate($process, 9);
        proc_close($process);
    }

    private function push_file(Site_Export_Push_Session $push_session, string $path, string $contents): void {
        $this->push_parts($push_session, [[
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
    private function push_parts(Site_Export_Push_Session $push_session, array $parts): void {
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
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            while ($push_session->next_change()) {
            }
        } finally {
            $push_session->finish_upload();
            fclose($input);
        }
    }

    private function commit_all(Site_Export_Push_Session $push_session): void {
        if (!$push_session->get_status()['work_deletes_complete']) {
            $this->complete_work_deletes($push_session);
        }
        do {
            $result = $push_session->commit(4);
        } while ($result['send_next_request']);
    }

    private function complete_work_deletes(Site_Export_Push_Session $push_session): void {
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) $push_session->get_status()['work_deletes_bytes'],
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
