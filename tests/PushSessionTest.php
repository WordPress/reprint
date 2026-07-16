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

    public function testPushSessionIdsAndConfiguredRootsAreValidatedBeforeUse(): void {
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), '../' . str_repeat('a', 29), "a\0" . str_repeat('a', 30)] as $push_session_id) {
            try {
                Site_Export_Push_Session::create($this->reprint_directory, $this->docroot, [], $push_session_id);
                $this->fail('Malformed session id was accepted: ' . base64_encode($push_session_id));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('32-character lowercase hexadecimal', $exception->getMessage());
            }
        }

        $missing = $this->root . '/missing';
        $docroot_link = $this->root . '/docroot-link';
        $reprint_directory_link = $this->root . '/reprint-directory-link';
        symlink($this->docroot, $docroot_link);
        symlink($this->reprint_directory, $reprint_directory_link);
        $cases = [
            ['relative', $this->docroot],
            [$this->reprint_directory, 'relative'],
            [$this->reprint_directory, $missing],
            [$reprint_directory_link, $this->docroot],
            [$this->reprint_directory, $docroot_link],
            [$this->docroot, $this->docroot],
        ];
        foreach ($cases as $index => [$reprint_directory, $docroot]) {
            try {
                Site_Export_Push_Session::create($reprint_directory, $docroot, [], sprintf('%032x', 700 + $index));
                $this->fail('Invalid configured roots were accepted for case ' . $index . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        try {
            Site_Export_Push_Session::open($missing, $this->docroot, str_repeat('a', 32), []);
            $this->fail('A missing reprint directory root was accepted while reopening.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not a real directory', $exception->getMessage());
        }
    }

    public function testCreateUsesPrivateReprintDirectoryAndIsIdempotent(): void {
        $reprint_directory = $this->root . '/created-reprint-directory';
        $previous_umask = umask(0000);
        try {
            $push_session = Site_Export_Push_Session::create($reprint_directory, $this->docroot, ['z', 'a', 'a'], str_repeat('d', 32));
        } finally {
            umask($previous_umask);
        }
        clearstatcache(true, $reprint_directory);
        $this->assertSame(0700, fileperms($reprint_directory) & 0777);
        $this->push_file($push_session, 'kept.bin', 'value');

        $replayed = Site_Export_Push_Session::create($reprint_directory, $this->docroot, ['a', 'z'], str_repeat('d', 32));
        $this->assertSame($push_session->get_push_directory(), $replayed->get_push_directory());
        $this->assertSame('complete', $replayed->get_status('kept.bin')['path']['state']);
    }

    public function testMalformedExcludedPathConfigurationIsRejected(): void {
        $excluded_path_lists = [
            [''],
            ['/absolute'],
            ['a//b'],
            ['a/./b'],
            ['a/../b'],
            ['windows\\path'],
            ["nul\0path"],
            [123],
        ];
        foreach ($excluded_path_lists as $index => $excluded_paths) {
            try {
                Site_Export_Push_Session::create(
                    $this->reprint_directory,
                    $this->docroot,
                    $excluded_paths,
                    sprintf('%032x', 800 + $index)
                );
                $this->fail('Malformed excluded path configuration was accepted for case ' . $index . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('excluded', strtolower($exception->getMessage()));
            }
        }
    }

    public function testCreationLockSerializesConcurrentPushSessionCreation(): void {
        $this->push_session(str_repeat('e', 32));
        $lock = fopen($this->reprint_directory . '/.reprint/push/push-create.lock', 'r+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            Site_Export_Push_Session::create($this->reprint_directory, $this->docroot, [], str_repeat('f', 32));
            $this->fail('A session was created while the creation lock was held.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testCreationLockRejectsDirectoriesAndSymlinks(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $reprint_directory = $this->root . '/creation-lock-' . $index;
            mkdir($reprint_directory . '/.reprint/push', 0700, true);
            $push_lock_path = $reprint_directory . '/.reprint/push/push-create.lock';
            if ($type === 'directory') {
                mkdir($push_lock_path);
            } else {
                file_put_contents($this->docroot . '/creation-lock-sentinel', 'safe');
                symlink($this->docroot . '/creation-lock-sentinel', $push_lock_path);
            }
            try {
                Site_Export_Push_Session::create($reprint_directory, $this->docroot, [], sprintf('%032x', 850 + $index));
                $this->fail('Creation lock ' . $type . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->docroot . '/creation-lock-sentinel'));
            }
        }
    }

    public function testFailedUploadKeepsEveryCompetingOperationLockedUntilFinish(): void {
        $push_session = $this->push_session('01010101010101010101010101010101');
        $reopened = Site_Export_Push_Session::open(
            $this->reprint_directory,
            $this->docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint']
        );
        $boundary = 'test-boundary';
        $input = fopen('php://temp', 'w+b');
        fwrite($input, '--' . $boundary . "\r\nX-Chunk-Type: unknown\r\nContent-Length: 0\r\n\r\n\r\n--" . $boundary . "--\r\n");
        rewind($input);
        $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $push_session->next_change();
            $this->fail('Malformed part was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('X-Chunk-Type', $exception->getMessage());
        }

        foreach (['status', 'commit', 'remove', 'upload'] as $operation) {
            try {
                if ($operation === 'status') {
                    $reopened->get_status();
                } elseif ($operation === 'commit') {
                    $reopened->commit(1);
                } elseif ($operation === 'remove') {
                    $reopened->remove_push_directory();
                } else {
                    $other_input = fopen('php://temp', 'w+b');
                    try {
                        $reopened->accept_upload($other_input, new Site_Export_Multipart_Processor($boundary));
                    } finally {
                        fclose($other_input);
                    }
                }
                $this->fail(ucfirst($operation) . ' bypassed the failed upload lock.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
            }
        }
        $push_session->finish_upload();
        fclose($input);
        $this->assertSame('receiving_work', $reopened->get_status()['phase']);
    }

    public function testPushSessionControlPathsRejectMissingAndTypeConfusedEntries(): void {
        $mutations = [
            'push lock missing' => ['push.lock', 'missing'],
            'push lock symlink' => ['push.lock', 'symlink'],
            'push lock directory' => ['push.lock', 'directory'],
            'deletes symlink' => ['work/deletes', 'symlink'],
            'metadata directory' => ['push.json', 'directory'],
            'commit directory' => ['commit.json', 'directory'],
            'maintenance directory' => ['work/maintenance.php', 'directory'],
        ];
        $index = 0;
        foreach ($mutations as [$relative_path, $replacement]) {
            $push_session = $this->push_session(sprintf('%032x', 900 + $index));
            ++$index;
            $path = $push_session->get_push_directory() . '/' . $relative_path;
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            if ($replacement === 'symlink') {
                symlink($this->docroot, $path);
            } elseif ($replacement === 'directory') {
                mkdir($path);
            }
            try {
                $push_session->get_status();
                $this->fail('Type-confused control path was accepted: ' . $relative_path . '.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
        }
    }

    public function testMultipartRejectsEveryTruncationBoundary(): void {
        $boundary = 'test-boundary';
        $complete = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('value.bin') . "\r\n"
            . "X-File-Size: 3\r\nX-Chunk-Offset: 0\r\nContent-Length: 3\r\n\r\nabc\r\n"
            . '--' . $boundary . "--\r\n";
        $truncated_requests = [
            substr($complete, 0, 4),
            substr($complete, 0, strpos($complete, "\r\n\r\n") - 1),
            substr($complete, 0, strpos($complete, 'abc') + 2),
            substr($complete, 0, strpos($complete, "\r\n--" . $boundary) + 5),
            substr($complete, 0, -2),
        ];
        foreach ($truncated_requests as $index => $request) {
            $push_session = $this->push_session(sprintf('%032x', 1000 + $index));
            $input = fopen('php://temp', 'w+b');
            fwrite($input, $request);
            rewind($input);
            $push_session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
            try {
                while ($push_session->next_change()) {
                    $this->assertNotNull($push_session->get_current_change());
                }
                $this->fail('Multipart truncation case ' . $index . ' completed normally.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } finally {
                $push_session->finish_upload();
                fclose($input);
            }
        }
    }

    public function testMultipartHeaderGrammarRejectsMissingAndAmbiguousValues(): void {
        $parts = [
            ['headers' => [], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Size' => '0', 'X-Chunk-Offset' => '0'], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'directory'], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('link')], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '+1'], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '1x'], 'body' => ''],
            ['headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0', 'X-Delete-Complete' => '0'], 'body' => ''],
        ];
        foreach ($parts as $index => $part) {
            $push_session = $this->push_session(sprintf('%032x', 1100 + $index));
            try {
                $this->push_parts($push_session, [$part]);
                $this->fail('Malformed multipart header case ' . $index . ' was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testArbitraryPathBytesAndIndependentSymlinkTargetsRoundTrip(): void {
        $push_session = $this->push_session('02020202020202020202020202020202');
        $raw_path = "non-utf8-\xff.bin";
        if (@file_put_contents($this->docroot . '/' . $raw_path, 'probe') === false) {
            $raw_path = "control-\n.bin";
        } else {
            unlink($this->docroot . '/' . $raw_path);
        }
        $this->push_file($push_session, $raw_path, 'bytes');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode('absolute-link'),
                'X-Symlink-Target' => base64_encode('/outside/is-allowed-as-a-value'),
            ],
            'body' => '',
        ]]);
        $this->assertSame(base64_encode($raw_path), $push_session->get_status($raw_path)['path']['path_b64']);
        $this->commit_all($push_session);
        $this->assertSame('bytes', file_get_contents($this->docroot . '/' . $raw_path));
        $this->assertSame('/outside/is-allowed-as-a-value', readlink($this->docroot . '/absolute-link'));
    }

    public function testFileResumeAcceptsOnlyTheActualCursorAndRestartsEveryStateAtZero(): void {
        $push_session = $this->push_session('03030303030303030303030303030303');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('value.bin'),
                'X-File-Size' => '6',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'ab',
        ]]);
        foreach ([1, 3] as $offset) {
            try {
                $this->push_parts($push_session, [[
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode('value.bin'),
                        'X-File-Size' => '6',
                        'X-Chunk-Offset' => (string) $offset,
                    ],
                    'body' => 'x',
                ]]);
                $this->fail('Stale or gapped partial cursor ' . $offset . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('offset_gap', $exception->get_error_code());
            }
        }
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('value.bin'),
                'X-File-Size' => '6',
                'X-Chunk-Offset' => '2',
            ],
            'body' => 'cdef',
        ]]);
        $this->assertSame('complete', $push_session->get_status('value.bin')['path']['state']);

        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('value.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $this->commit_all($push_session);
        $this->assertSame('new', file_get_contents($this->docroot . '/value.bin'));
    }

    public function testEveryCorruptPartialEntryTypeIsRejectedWithoutFollowingIt(): void {
        $outside = $this->root . '/outside-partial';
        mkdir($outside);
        file_put_contents($outside . '/sentinel', 'safe');
        foreach (['directory', 'symlink', 'fifo'] as $index => $type) {
            if ($type === 'fifo' && !function_exists('posix_mkfifo')) {
                continue;
            }
            $push_session = $this->push_session(sprintf('%032x', 1200 + $index));
            $partial = $push_session->get_push_directory() . '/work/partial/corrupt';
            if ($type === 'directory') {
                mkdir($partial);
                file_put_contents($partial . '/child', 'x');
            } elseif ($type === 'symlink') {
                symlink($outside, $partial);
            } else {
                posix_mkfifo($partial, 0600);
            }
            try {
                $push_session->get_status('corrupt');
                $this->fail('Corrupt partial ' . $type . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            $this->assertSame('safe', file_get_contents($outside . '/sentinel'));
        }
    }

    public function testDeleteStreamCompletionIsFinalAndInvalidLaterRequestsPreserveAcceptedBytes(): void {
        $push_session = $this->push_session('04040404040404040404040404040404');
        $accepted = "first\0partial";
        $this->push_parts($push_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $accepted,
        ]]);
        try {
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => (string) strlen($accepted)],
                'body' => "/../unsafe\0",
            ]]);
            $this->fail('Unsafe later delete record was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($accepted, file_get_contents($push_session->get_push_directory() . '/work/deletes'));

        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) strlen($accepted),
                'X-Delete-Complete' => '1',
            ],
            'body' => "-path\0",
        ]]);
        $final_size = $push_session->get_status()['work_deletes_bytes'];
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) $final_size,
                'X-Delete-Complete' => '1',
            ],
            'body' => '',
        ]]);
        try {
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => (string) $final_size],
                'body' => "later\0",
            ]]);
            $this->fail('Bytes were appended after delete completion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already complete', $exception->getMessage());
        }
    }

    public function testCreateAndDeleteCompletionPreserveExactVersionFourMetadata(): void {
        $push_session = $this->push_session('04140414041404140414041404140414');
        $created = $this->push_metadata($push_session);

        $this->assertSame([
            'version',
            'push_session_id',
            'docroot_b64',
            'excluded_paths_b64',
            'work_deletes_complete',
            'commit_started',
        ], array_keys($created));
        $this->assertSame(4, $created['version']);
        $this->assertFalse($created['work_deletes_complete']);
        $this->assertFalse($created['commit_started']);

        $this->complete_work_deletes($push_session);
        $completed = $this->push_metadata($push_session);
        $this->assertSame(array_keys($created), array_keys($completed));
        $this->assertTrue($completed['work_deletes_complete']);
        $this->assertFalse($completed['commit_started']);
    }

    public function testEveryPushSessionMetadataShapeIsValidatedBeforeStateIsUsed(): void {
        $invalid_values = [
            ['version' => 3],
            ['push_session_id' => 'wrong'],
            ['work_deletes_complete' => 1],
            ['commit_started' => null],
            ['commit_started' => 0],
            ['commit_started' => 'false'],
            ['docroot_b64' => 1],
            ['docroot_b64' => '***'],
            ['excluded_paths_b64' => 'not-a-list'],
            ['excluded_paths_b64' => ['path' => base64_encode('safe')]],
            ['excluded_paths_b64' => [1]],
            ['excluded_paths_b64' => ['***']],
        ];
        foreach ($invalid_values as $index => $replacement) {
            $push_session = $this->push_session(sprintf('%032x', 1300 + $index));
            $path = $push_session->get_push_directory() . '/push.json';
            $push_metadata = json_decode( (string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($replacement as $field => $value) {
                $push_metadata[$field] = $value;
            }
            file_put_contents($path, json_encode($push_metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            try {
                $push_session->get_status();
                $this->fail('Malformed push metadata case ' . $index . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
        }

        $missing_commit_started = $this->push_session('05150515051505150515051505150515');
        $missing_path = $missing_commit_started->get_push_directory() . '/push.json';
        $missing_metadata = $this->push_metadata($missing_commit_started);
        unset($missing_metadata['commit_started']);
        file_put_contents($missing_path, json_encode($missing_metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $missing_commit_started->get_status();
            $this->fail('Push metadata without commit_started was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('commit_started', $exception->getMessage());
        }

        $oversized = $this->push_session('05050505050505050505050505050505');
        file_put_contents($oversized->get_push_directory() . '/push.json', str_repeat(' ', 1048577));
        try {
            $oversized->get_status();
            $this->fail('Oversized push metadata was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
    }

    public function testReplacedOwnedMaintenanceMarkerStopsCommitCompletionWithoutDeletingIt(): void {
        $push_session = $this->push_session('06060606060606060606060606060606');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        file_put_contents($this->docroot . '/.maintenance', "<?php\n// foreign replacement\n");
        try {
            $this->commit_all($push_session);
            $this->fail('A replaced maintenance marker was deleted during commit completion.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertSame("<?php\n// foreign replacement\n", file_get_contents($this->docroot . '/.maintenance'));
    }

    public function testMaintenanceReplacementDuringCommitStopsCommitCompletion(): void {
        $push_session = $this->push_session('06560656065606560656065606560656');
        $parts = [];
        for ($index = 0; $index < 400; ++$index) {
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('entry-' . $index),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ];
        }
        $this->push_parts($push_session, $parts);
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $maintenance_path = $this->docroot . '/.maintenance';
        unlink($maintenance_path);
        $replacement = "<?php\n// replaced during commit\n";
        $script = '$path = base64_decode(' . var_export(base64_encode($maintenance_path), true) . ');'
            . '$deadline = microtime(true) + 5;'
            . 'while (!file_exists($path) && microtime(true) < $deadline) { usleep(100); }'
            . 'if (!file_exists($path) || file_put_contents($path, ' . var_export($replacement, true) . ') === false) { exit(1); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        try {
            $push_session->commit(1000);
            $this->fail('A maintenance marker replaced during commit was deleted at commit completion.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($process));
        }
        $this->assertSame($replacement, file_get_contents($maintenance_path));
    }

    public function testCommitStateLockPathsCannotBeDirectoriesOrSymlinks(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 1400 + $index));
            $this->complete_work_deletes($push_session);
            $push_lock_path = $this->reprint_directory . '/.reprint/push/commit-state.lock';
            if (is_dir($push_lock_path) && !is_link($push_lock_path)) {
                rmdir($push_lock_path);
            } elseif (file_exists($push_lock_path) || is_link($push_lock_path)) {
                unlink($push_lock_path);
            }
            if ($type === 'directory') {
                mkdir($push_lock_path);
            } else {
                symlink($this->docroot . '/coordinator-sentinel', $push_lock_path);
                file_put_contents($this->docroot . '/coordinator-sentinel', 'safe');
            }
            try {
                $push_session->commit(1);
                $this->fail('Commit-state lock ' . $type . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->docroot . '/coordinator-sentinel'));
            }
        }
    }

    public function testCommitStateOwnerCannotBeADirectoryOrSymlink(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 1450 + $index));
            $this->complete_work_deletes($push_session);
            $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
            if (file_exists($active_path) || is_link($active_path)) {
                unlink($active_path);
            }
            if ($type === 'directory') {
                mkdir($active_path);
                file_put_contents($active_path . '/sentinel', 'safe');
            } else {
                file_put_contents($this->docroot . '/active-sentinel', 'safe');
                symlink($this->docroot . '/active-sentinel', $active_path);
            }
            try {
                $push_session->commit(1);
                $this->fail('Commit-state owner ' . $type . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            if ($type === 'directory') {
                $this->assertSame('safe', file_get_contents($active_path . '/sentinel'));
                unlink($active_path . '/sentinel');
                rmdir($active_path);
            } else {
                $this->assertSame('safe', file_get_contents($this->docroot . '/active-sentinel'));
                unlink($active_path);
            }
        }
    }

    public function testCommitStateOwnerAcceptsOnlyMissingOwnedOrValidForeignState(): void {
        $owner = $this->push_session('31313131424242425353535364646464');
        $this->push_file($owner, 'owner-pending', 'new');
        $this->complete_work_deletes($owner);
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';

        $owner->commit(1);
        $this->assertSame($owner->get_push_session_id() . "\n", file_get_contents($active_path));
        $owner->commit(1);
        $this->assertSame($owner->get_push_session_id() . "\n", file_get_contents($active_path));

        $other = $this->push_session('32323232434343435454545465656565');
        $this->push_file($other, 'other-pending', 'new');
        $this->complete_work_deletes($other);
        try {
            $other->commit(1);
            $this->fail('A valid foreign commit-state owner was overwritten.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertSame($owner->get_push_session_id() . "\n", file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->docroot . '/other-pending');
    }

    public function testCommitStateOwnerRejectsEveryMalformedRegularFileWithoutReplacingIt(): void {
        $invalid_records = [
            'empty' => '',
            'too short' => str_repeat('a', 31) . "\n",
            'too long' => str_repeat('a', 33) . "\n",
            'uppercase' => str_repeat('A', 32) . "\n",
            'non-hex' => str_repeat('g', 32) . "\n",
            'missing newline' => str_repeat('a', 32),
            'extra newline' => str_repeat('a', 32) . "\n\n",
            'extra data' => str_repeat('a', 32) . "\nextra",
        ];
        $case_index = 0;
        foreach ($invalid_records as $description => $record) {
            $push_session = $this->push_session(sprintf('%032x', 2500 + $case_index));
            $sentinel = 'malformed-commit-state-owner-' . $case_index;
            file_put_contents($this->docroot . '/' . $sentinel, 'safe');
            $this->push_file($push_session, 'pending-' . $case_index, 'new');
            $this->complete_work_deletes($push_session);
            $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
            file_put_contents($active_path, $record);

            $observed_exception = null;
            try {
                $push_session->commit(1);
            } catch (Throwable $exception) {
                $observed_exception = $exception;
            }
            $this->assertInstanceOf(
                Site_Export_Push_Exception::class,
                $observed_exception,
                'Malformed commit-state owner ' . $description . ' case was accepted.'
            );
            $this->assertSame('corrupted_push_state', $observed_exception->get_error_code());
            $this->assertSame($record, file_get_contents($active_path));
            $this->assertSame('safe', file_get_contents($this->docroot . '/' . $sentinel));
            $this->assertFileDoesNotExist($this->docroot . '/pending-' . $case_index);
            $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/maintenance.php');
            $checkpoint = json_decode(
                (string) file_get_contents($push_session->get_push_directory() . '/commit.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame('deleting_files', $checkpoint['phase']);
            $this->assertSame(0, $checkpoint['work_deletes_byte_offset']);
            $this->assertSame(0, $checkpoint['deleted_files']);
            $this->assertSame(0, $checkpoint['installed_files']);
            unlink($active_path);
            ++$case_index;
        }
    }

    public function testTruncatedCommitStateOwnerIsRejectedWithoutWritingTheCompetingPushSessionId(): void {
        $owner = $this->push_session('33333333444444445555555566666666');
        $this->push_file($owner, 'owner-pending', 'new');
        $this->complete_work_deletes($owner);
        $owner->commit(1);
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        file_put_contents($active_path, '');

        $competitor = $this->push_session('34343434454545455656565667676767');
        $this->push_file($competitor, 'competitor-pending', 'new');
        $this->complete_work_deletes($competitor);
        try {
            $competitor->commit(1);
            $this->fail('A truncated commit-state owner was treated as unclaimed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }

        $this->assertSame('', file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->docroot . '/competitor-pending');
        $this->assertFileDoesNotExist($competitor->get_push_directory() . '/work/maintenance.php');
    }

    public function testUnreadableCommitStateOwnerIsRecoverableAndIsNeverTreatedAsAbsent(): void {
        $push_session = $this->push_session('35353535464646465757575768686868');
        $this->push_file($push_session, 'unreadable-pending', 'new');
        $this->complete_work_deletes($push_session);
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        $foreign_owner = str_repeat('d', 32) . "\n";
        file_put_contents($active_path, $foreign_owner);
        chmod($active_path, 0000);
        $probe = @fopen($active_path, 'rb');
        if (is_resource($probe)) {
            fclose($probe);
            chmod($active_path, 0600);
            $this->markTestSkipped('This platform does not enforce unreadable commit-state owner permissions for the test process.');
        }

        try {
            $push_session->commit(1);
            $this->fail('An unreadable commit-state owner was treated as absent.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('filesystem_error', $exception->get_error_code());
        } finally {
            chmod($active_path, 0600);
        }
        $this->assertSame($foreign_owner, file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->docroot . '/unreadable-pending');
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
    }

    public function testRemoveHandlesMissingTombstonedAndContendedPushSessionsWithoutFollowingLinks(): void {
        $missing_id = '07070707070707070707070707070707';
        $this->assertTrue(Site_Export_Push_Session::remove($this->reprint_directory, $this->docroot, $missing_id, []));

        $push_session = $this->push_session('08080808080808080808080808080808');
        $tombstone = $this->reprint_directory . '/.reprint/push/.removing-' . $push_session->get_push_session_id();
        rename($push_session->get_push_directory(), $tombstone);
        $lock = fopen($tombstone . '/push.lock', 'r+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $push_session->remove_push_directory();
            $this->fail('A contended remove tombstone was cleaned.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertTrue($push_session->remove_push_directory());

        $outside = $this->root . '/remove-outside';
        mkdir($outside);
        file_put_contents($outside . '/push.lock', 'not a push lock');
        file_put_contents($outside . '/sentinel', 'safe');
        $linked_id = '09090909090909090909090909090909';
        symlink($outside, $this->reprint_directory . '/.reprint/push/.removing-' . $linked_id);
        try {
            Site_Export_Push_Session::remove($this->reprint_directory, $this->docroot, $linked_id, []);
            $this->fail('A symlink remove tombstone was followed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertSame('safe', file_get_contents($outside . '/sentinel'));
    }

    public function testRemoveTombstoneLockCannotBeReplacedByDirectoryOrSymlink(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $push_session = $this->push_session(sprintf('%032x', 1500 + $index));
            $tombstone = $this->reprint_directory . '/.reprint/push/.removing-' . $push_session->get_push_session_id();
            rename($push_session->get_push_directory(), $tombstone);
            unlink($tombstone . '/push.lock');
            if ($type === 'directory') {
                mkdir($tombstone . '/push.lock');
            } else {
                file_put_contents($this->docroot . '/tombstone-lock-sentinel', 'safe');
                symlink($this->docroot . '/tombstone-lock-sentinel', $tombstone . '/push.lock');
            }
            try {
                $push_session->remove_push_directory();
                $this->fail('Remove tombstone lock ' . $type . ' was accepted.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->docroot . '/tombstone-lock-sentinel'));
            }
        }
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

    /**
     * @return array{
     *     version:3,
     *     push_session_id:string,
     *     docroot_b64:string,
     *     excluded_paths_b64:list<string>,
     *     work_deletes_complete:bool,
     *     commit_started:bool
     * }
     */
    private function push_metadata(Site_Export_Push_Session $push_session): array {
        $push_metadata = json_decode(
            (string) file_get_contents($push_session->get_push_directory() . '/push.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($push_metadata);
        return $push_metadata;
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
