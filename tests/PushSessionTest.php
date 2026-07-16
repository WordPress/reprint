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

    public function testNewPushSessionHasOneCompletedTreeAndNoPartialTree(): void {
        $push_session = $this->push_session('10101010101010101010101010101010');
        $work_directory = $push_session->get_push_directory() . '/work';

        $this->assertDirectoryExists($work_directory . '/files');
        $this->assertFileDoesNotExist($work_directory . '/partial');
        $this->assertFileDoesNotExist($work_directory . '/inflight.json');
        $this->assertFileDoesNotExist($work_directory . '/inflight.data');
    }

    public function testPartialFileProgressComesFromTheFileAndOffsetZeroRestartsIt(): void {
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
                $this->fail('Unsafe path was work: ' . base64_encode($path));
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
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

    public function testCompletedTypeChangeRemovesThePartialFileAtTheSamePath(): void {
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
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/partial/changing');
        }
    }

    public function testStatusReportsMissingPartialAndEveryCompletedValueType(): void {
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
            ['path_b64' => base64_encode('missing'), 'state' => 'missing', 'accepted_bytes' => 0],
            $push_session->get_status('missing')['path']
        );
        $this->assertSame('partial', $push_session->get_status($raw_path)['path']['state']);
        $this->assertSame(1, $push_session->get_status($raw_path)['path']['accepted_bytes']);
        $this->assertSame('file', $push_session->get_status('empty.bin')['path']['type']);
        $this->assertSame('directory', $push_session->get_status('empty-directory')['path']['type']);
        $this->assertSame('symlink', $push_session->get_status('link')['path']['type']);
        $this->assertNull($push_session->get_status()['path']);
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

    public function testPartialFileCannotBecomeAParentOfCompletedValues(): void {
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
                $this->fail('A partial file became the parent of a completed ' . $type . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('parent', $exception->getMessage());
            }
            $this->assertSame('a', file_get_contents($push_session->get_push_directory() . '/work/partial/parent'));
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/files/parent/child');
        }
    }

    public function testCompletedLeafCannotHidePartialDescendants(): void {
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
                $this->fail('A completed ' . $type . ' hid a partial descendant.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('descendant', $exception->getMessage());
            }
            $this->assertSame('a', file_get_contents($push_session->get_push_directory() . '/work/partial/parent/child'));
        }
    }

    public function testCheckpointMustContainEveryFieldReadByCommit(): void {
        $push_session = $this->push_session(sprintf('%032x', 400));
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $checkpoint_path = $push_session->get_push_directory() . '/commit.json';
        $checkpoint = json_decode( (string) file_get_contents($checkpoint_path), true, 512, JSON_THROW_ON_ERROR);

        foreach (['current_delete_path', 'current_work_files_descendant'] as $field) {
            $invalid_checkpoint = $checkpoint;
            unset($invalid_checkpoint[$field]);
            file_put_contents($checkpoint_path, json_encode($invalid_checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            try {
                $push_session->get_status();
                $this->fail('Commit checkpoint without ' . $field . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
        file_put_contents($checkpoint_path, json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->commit_all($push_session);
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

    private function push_session(string $id): Site_Export_Push_Session {
        return Site_Export_Push_Session::create(
            $this->reprint_directory,
            $this->docroot,
            ['wp-content/plugins/reprint'],
            $id
        );
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
