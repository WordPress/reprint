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
        $this->assertSame(3, $session->get_status('upload.bin')['path']['accepted_bytes']);

        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $status = $session->get_status('upload.bin');
        $this->assertSame('complete', $status['path']['state']);
        $this->assertSame(3, $status['path']['accepted_bytes']);

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
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
            $this->assertStringContainsString('Start at offset 0', $exception->getMessage());
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/must-not-be-read.bin');
    }

    public function testDeleteOffsetGapHasTheSameRecoverableProtocolReason(): void {
        $session = $this->session('23232323232323232323232323232323');

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '1',
                ],
                'body' => "gone\0",
            ]]);
            $this->fail('A delete offset gap was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
            $this->assertStringContainsString('target has stored 0 bytes', $exception->getMessage());
        }
    }

    public function testProtectedAndInvalidPathsNeverReachStaging(): void {
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

    public function testSessionLockExcludesOtherOperationsUntilUploadFinishes(): void {
        $session = $this->session('66666666666666666666666666666666');
        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint']
        );
        $input = fopen('php://temp', 'w+b');
        $session->accept_upload($input, new Site_Export_Multipart_Processor('test-boundary'));

        try {
            $reopened->get_status();
            $this->fail('Status observed a session while an upload held its lock.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }

        try {
            $session->accept_upload($input, new Site_Export_Multipart_Processor('test-boundary'));
            $this->fail('One session object accepted two simultaneous uploads.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already open', $exception->getMessage());
        } finally {
            $session->finish_upload();
            fclose($input);
        }

        $this->assertSame('uploading', $reopened->get_status()['phase']);
    }

    public function testTruncatedUploadDoesNotBecomeACompleteChangeAndReleasesCleanly(): void {
        $session = $this->session('77777777777777777777777777777777');
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
        $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $session->next_change();
            $this->fail('A truncated multipart body was accepted as a complete change.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('declared content-length', strtolower($exception->getMessage()));
        } finally {
            $session->finish_upload();
            fclose($input);
        }

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint']
        );
        $this->assertSame('uploading', $reopened->get_status()['phase']);
        $this->assertNotSame('complete', $reopened->get_status('truncated.bin')['path']['state']);
    }

    public function testMalformedMultipartFieldsAreRejectedBeforeStaging(): void {
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
                    'X-Symlink-Path' => base64_encode('empty-target'),
                    'X-Symlink-Target' => base64_encode(''),
                ],
                'body' => '',
            ],
        ];

        foreach ($cases as $index => $part) {
            $session = $this->session(sprintf('%032x', 100 + $index));
            try {
                $this->stage($session, [$part]);
                $this->fail('Malformed multipart case ' . $index . ' was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_diff(
                scandir($session->get_session_directory() . '/work/files') ?: [],
                ['.', '..']
            )));
        }
    }

    public function testPartByteCeilingRejectsBeforeWritingItsBody(): void {
        $session = $this->session('12341234123412341234123412341234');
        $boundary = 'test-boundary';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('too-large.bin') . "\r\n"
            . "X-File-Size: 2\r\nX-Chunk-Offset: 0\r\nContent-Length: 2\r\n\r\nxx\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary), 1);
        try {
            $session->next_change();
            $this->fail('A part above the configured byte ceiling was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeds the target maximum', $exception->getMessage());
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        $this->assertSame('missing', $session->get_status('too-large.bin')['path']['state']);
    }

    public function testMalformedMetadataAndWorkspaceSymlinksAreRejected(): void {
        $metadata_session = $this->session('23452345234523452345234523452345');
        file_put_contents($metadata_session->get_session_directory() . '/session.json', '{not-json');
        try {
            $metadata_session->get_status();
            $this->fail('Malformed session metadata was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }

        $shape_session = $this->session('34563456345634563456345634563455');
        $metadata_path = $shape_session->get_session_directory() . '/session.json';
        $metadata = json_decode( (string) file_get_contents($metadata_path), true, 512, JSON_THROW_ON_ERROR);
        $metadata['protected_paths_b64'] = 'not-a-list';
        file_put_contents($metadata_path, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $shape_session->get_status();
            $this->fail('Session metadata with a scalar protected-path list was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
            $this->assertStringContainsString('protected paths', $exception->getMessage());
        }

        $symlink_session = $this->session('34563456345634563456345634563456');
        $files = $symlink_session->get_session_directory() . '/work/files';
        rmdir($files);
        symlink($this->target, $files);
        try {
            $symlink_session->get_status();
            $this->fail('A symlink replaced the private completed-files directory.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
            $this->assertStringContainsString('not real', $exception->getMessage());
        }
    }

    public function testReopenRejectsTargetAndProtectedPathConfigurationDrift(): void {
        $session = $this->session('45674567456745674567456745674567');
        $other_target = $this->root . '/other-target';
        mkdir($other_target, 0700);
        $changed_target = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $other_target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint']
        );
        $changed_protection = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint', 'wp-config.php']
        );

        foreach ([$changed_target, $changed_protection] as $reopened) {
            try {
                $reopened->get_status();
                $this->fail('A session reopened with different immutable configuration.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    public function testCompletedTypeChangeDiscardsThePartialFileAtTheSamePath(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 500 + $index));
            $this->stage($session, [[
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
            $this->stage($session, [['headers' => $headers, 'body' => '']]);

            $this->assertSame('complete', $session->get_status('changing')['path']['state']);
            $this->assertSame($type, $session->get_status('changing')['path']['type']);
            $this->assertFileDoesNotExist($session->get_session_directory() . '/work/partial/changing');
        }
    }

    public function testStatusReportsMissingPartialAndEveryCompletedValueType(): void {
        $session = $this->session('88888888888888888888888888888888');
        $raw_path = "line\nbreak.bin";
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($raw_path),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'a',
        ]]);
        $this->stage($session, [
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
                    'X-Symlink-Target' => base64_encode('../target'),
                ],
                'body' => '',
            ],
        ]);

        $this->assertSame(
            ['path_b64' => base64_encode('missing'), 'state' => 'missing', 'accepted_bytes' => 0],
            $session->get_status('missing')['path']
        );
        $this->assertSame('partial', $session->get_status($raw_path)['path']['state']);
        $this->assertSame(1, $session->get_status($raw_path)['path']['accepted_bytes']);
        $this->assertSame('file', $session->get_status('empty.bin')['path']['type']);
        $this->assertSame('directory', $session->get_status('empty-directory')['path']['type']);
        $this->assertSame('symlink', $session->get_status('link')['path']['type']);
        $this->assertNull($session->get_status()['path']);
    }

    public function testCompletedFileReplayRequiresItsExactEmptyEndCursor(): void {
        $session = $this->session('99999999999999999999999999999999');
        $this->stage_file($session, 'complete.bin', 'abc');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('complete.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '3',
            ],
            'body' => '',
        ]]);
        $this->assertSame('complete', $session->get_status('complete.bin')['path']['state']);

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('complete.bin'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '2',
                ],
                'body' => 'c',
            ]]);
            $this->fail('A completed file accepted a stale replay cursor.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('offset_gap', $exception->get_error_code());
        }
    }

    public function testPartialFileCannotBecomeAParentOfCompletedValues(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 200 + $index));
            $this->stage($session, [[
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
                $this->stage($session, [['headers' => $headers, 'body' => '']]);
                $this->fail('A partial file became the parent of a completed ' . $type . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('parent', $exception->getMessage());
            }
            $this->assertSame('a', file_get_contents($session->get_session_directory() . '/work/partial/parent'));
            $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/parent/child');
        }
    }

    public function testCompletedLeafCannotHidePartialDescendants(): void {
        foreach (['file', 'directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 300 + $index));
            $this->stage($session, [[
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
                $this->stage($session, [['headers' => $headers, 'body' => $body]]);
                $this->fail('A completed ' . $type . ' hid a partial descendant.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('descendant', $exception->getMessage());
            }
            $this->assertSame('a', file_get_contents($session->get_session_directory() . '/work/partial/parent/child'));
        }
    }

    public function testCheckpointMustContainEveryFieldReadByCommit(): void {
        $session = $this->session(sprintf('%032x', 400));
        $this->complete_delete_upload($session);
        $session->commit(1);
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = json_decode( (string) file_get_contents($checkpoint_path), true, 512, JSON_THROW_ON_ERROR);

        foreach (['current_deletion_b64', 'current_installation'] as $field) {
            $invalid_checkpoint = $checkpoint;
            unset($invalid_checkpoint[$field]);
            file_put_contents($checkpoint_path, json_encode($invalid_checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            try {
                $session->get_status();
                $this->fail('Commit checkpoint without ' . $field . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
        file_put_contents($checkpoint_path, json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->commit_all($session);
    }

    public function testTargetClaimBlocksAnotherCommitWithoutBlockingItsStatus(): void {
        $first = $this->session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $second = $this->session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->stage_file($first, 'first.txt', 'first');
        $this->stage_file($second, 'second.txt', 'second');
        $this->complete_delete_upload($first);
        $this->complete_delete_upload($second);
        $first->commit(1);

        $this->assertSame('uploading', $second->get_status()['phase']);
        try {
            $second->commit(1);
            $this->fail('A second session committed while the target was claimed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->target . '/second.txt');
        $this->commit_all($first);
        $this->commit_all($second);
        $this->assertSame('second', file_get_contents($this->target . '/second.txt'));
    }

    public function testDiscardIsBoundedAndNeverFollowsAWorkspaceSymlink(): void {
        $session = $this->session('cccccccccccccccccccccccccccccccc');
        $files = $session->get_session_directory() . '/work/files';
        for ($index = 0; $index < 300; ++$index) {
            file_put_contents($files . '/entry-' . $index, 'x');
        }
        $outside = $this->root . '/outside';
        mkdir($outside, 0700);
        file_put_contents($outside . '/sentinel', 'safe');
        symlink($outside, $files . '/outside-link');

        $this->assertFalse($session->discard_workspace());
        $this->assertFileExists($outside . '/sentinel');
        do {
            $discard_complete = $session->discard_workspace();
        } while (!$discard_complete);
        $this->assertFileExists($outside . '/sentinel');
        $this->assertDirectoryDoesNotExist($session->get_session_directory());
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
