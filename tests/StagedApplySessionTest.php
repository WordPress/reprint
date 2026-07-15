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

    public function testSessionIdsAndConfiguredRootsAreValidatedBeforeUse(): void {
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), '../' . str_repeat('a', 29), "a\0" . str_repeat('a', 30)] as $session_id) {
            try {
                Site_Export_Staged_Apply_Session::create($this->storage, $this->target, [], $session_id);
                $this->fail('Malformed session id was accepted: ' . base64_encode($session_id));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('32-character lowercase hexadecimal', $exception->getMessage());
            }
        }

        $missing = $this->root . '/missing';
        $target_link = $this->root . '/target-link';
        $storage_link = $this->root . '/storage-link';
        symlink($this->target, $target_link);
        symlink($this->storage, $storage_link);
        $cases = [
            ['relative', $this->target],
            [$this->storage, 'relative'],
            [$this->storage, $missing],
            [$storage_link, $this->target],
            [$this->storage, $target_link],
            [$this->target, $this->target],
        ];
        foreach ($cases as $index => [$storage, $target]) {
            try {
                Site_Export_Staged_Apply_Session::create($storage, $target, [], sprintf('%032x', 700 + $index));
                $this->fail('Invalid configured roots were accepted for case ' . $index . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        try {
            Site_Export_Staged_Apply_Session::open($missing, $this->target, str_repeat('a', 32), []);
            $this->fail('A missing storage root was accepted while reopening.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not a real directory', $exception->getMessage());
        }
    }

    public function testCreateUsesPrivateStorageAndIsIdempotent(): void {
        $storage = $this->root . '/created-storage';
        $previous_umask = umask(0000);
        try {
            $session = Site_Export_Staged_Apply_Session::create($storage, $this->target, ['z', 'a', 'a'], str_repeat('d', 32));
        } finally {
            umask($previous_umask);
        }
        clearstatcache(true, $storage);
        $this->assertSame(0700, fileperms($storage) & 0777);
        $this->stage_file($session, 'kept.bin', 'value');

        $replayed = Site_Export_Staged_Apply_Session::create($storage, $this->target, ['a', 'z'], str_repeat('d', 32));
        $this->assertSame($session->get_session_directory(), $replayed->get_session_directory());
        $this->assertSame('complete', $replayed->get_status('kept.bin')['path']['state']);
    }

    public function testMalformedProtectedPathConfigurationIsRejected(): void {
        $protected_path_lists = [
            [''],
            ['/absolute'],
            ['a//b'],
            ['a/./b'],
            ['a/../b'],
            ['windows\\path'],
            ["nul\0path"],
            [123],
        ];
        foreach ($protected_path_lists as $index => $protected_paths) {
            try {
                Site_Export_Staged_Apply_Session::create(
                    $this->storage,
                    $this->target,
                    $protected_paths,
                    sprintf('%032x', 800 + $index)
                );
                $this->fail('Malformed protected path configuration was accepted for case ' . $index . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('protected', strtolower($exception->getMessage()));
            }
        }
    }

    public function testCreationLockSerializesConcurrentSessionCreation(): void {
        $this->session(str_repeat('e', 32));
        $lock = fopen($this->storage . '/apply-sessions/create.lock', 'r+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            Site_Export_Staged_Apply_Session::create($this->storage, $this->target, [], str_repeat('f', 32));
            $this->fail('A session was created while the creation lock was held.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testCreationLockRejectsDirectoriesAndSymlinks(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $storage = $this->root . '/creation-lock-' . $index;
            mkdir($storage . '/apply-sessions', 0700, true);
            $lock_path = $storage . '/apply-sessions/create.lock';
            if ($type === 'directory') {
                mkdir($lock_path);
            } else {
                file_put_contents($this->target . '/creation-lock-sentinel', 'safe');
                symlink($this->target . '/creation-lock-sentinel', $lock_path);
            }
            try {
                Site_Export_Staged_Apply_Session::create($storage, $this->target, [], sprintf('%032x', 850 + $index));
                $this->fail('Creation lock ' . $type . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->target . '/creation-lock-sentinel'));
            }
        }
    }

    public function testFailedUploadKeepsEveryCompetingOperationLockedUntilFinish(): void {
        $session = $this->session('01010101010101010101010101010101');
        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint']
        );
        $boundary = 'test-boundary';
        $input = fopen('php://temp', 'w+b');
        fwrite($input, '--' . $boundary . "\r\nX-Chunk-Type: unknown\r\nContent-Length: 0\r\n\r\n\r\n--" . $boundary . "--\r\n");
        rewind($input);
        $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
        try {
            $session->next_change();
            $this->fail('Malformed part was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('X-Chunk-Type', $exception->getMessage());
        }

        foreach (['status', 'commit', 'discard', 'upload'] as $operation) {
            try {
                if ($operation === 'status') {
                    $reopened->get_status();
                } elseif ($operation === 'commit') {
                    $reopened->commit(1);
                } elseif ($operation === 'discard') {
                    $reopened->discard_workspace();
                } else {
                    $other_input = fopen('php://temp', 'w+b');
                    try {
                        $reopened->accept_upload($other_input, new Site_Export_Multipart_Processor($boundary));
                    } finally {
                        fclose($other_input);
                    }
                }
                $this->fail(ucfirst($operation) . ' bypassed the failed upload lock.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('busy', $exception->get_error_code());
            }
        }
        $session->finish_upload();
        fclose($input);
        $this->assertSame('uploading', $reopened->get_status()['phase']);
    }

    public function testSessionControlPathsRejectMissingAndTypeConfusedEntries(): void {
        $mutations = [
            'lock missing' => ['lock', 'missing'],
            'lock symlink' => ['lock', 'symlink'],
            'lock directory' => ['lock', 'directory'],
            'deletes symlink' => ['work/deletes', 'symlink'],
            'metadata directory' => ['session.json', 'directory'],
            'commit directory' => ['commit.json', 'directory'],
            'maintenance directory' => ['work/maintenance.php', 'directory'],
        ];
        $index = 0;
        foreach ($mutations as [$relative_path, $replacement]) {
            $session = $this->session(sprintf('%032x', 900 + $index));
            ++$index;
            $path = $session->get_session_directory() . '/' . $relative_path;
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            if ($replacement === 'symlink') {
                symlink($this->target, $path);
            } elseif ($replacement === 'directory') {
                mkdir($path);
            }
            try {
                $session->get_status();
                $this->fail('Type-confused control path was accepted: ' . $relative_path . '.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
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
            $session = $this->session(sprintf('%032x', 1000 + $index));
            $input = fopen('php://temp', 'w+b');
            fwrite($input, $request);
            rewind($input);
            $session->accept_upload($input, new Site_Export_Multipart_Processor($boundary));
            try {
                while ($session->next_change()) {
                    $this->assertNotNull($session->get_current_change());
                }
                $this->fail('Multipart truncation case ' . $index . ' completed normally.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } finally {
                $session->finish_upload();
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
            $session = $this->session(sprintf('%032x', 1100 + $index));
            try {
                $this->stage($session, [$part]);
                $this->fail('Malformed multipart header case ' . $index . ' was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testArbitraryPathBytesAndIndependentSymlinkTargetsRoundTrip(): void {
        $session = $this->session('02020202020202020202020202020202');
        $raw_path = "non-utf8-\xff.bin";
        if (@file_put_contents($this->target . '/' . $raw_path, 'probe') === false) {
            $raw_path = "control-\n.bin";
        } else {
            unlink($this->target . '/' . $raw_path);
        }
        $this->stage_file($session, $raw_path, 'bytes');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode('absolute-link'),
                'X-Symlink-Target' => base64_encode('/outside/is-allowed-as-a-value'),
            ],
            'body' => '',
        ]]);
        $this->assertSame(base64_encode($raw_path), $session->get_status($raw_path)['path']['path_b64']);
        $this->commit_all($session);
        $this->assertSame('bytes', file_get_contents($this->target . '/' . $raw_path));
        $this->assertSame('/outside/is-allowed-as-a-value', readlink($this->target . '/absolute-link'));
    }

    public function testFileResumeAcceptsOnlyTheActualCursorAndRestartsEveryStateAtZero(): void {
        $session = $this->session('03030303030303030303030303030303');
        $this->stage($session, [[
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
                $this->stage($session, [[
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode('value.bin'),
                        'X-File-Size' => '6',
                        'X-Chunk-Offset' => (string) $offset,
                    ],
                    'body' => 'x',
                ]]);
                $this->fail('Stale or gapped partial cursor ' . $offset . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('offset_gap', $exception->get_error_code());
            }
        }
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('value.bin'),
                'X-File-Size' => '6',
                'X-Chunk-Offset' => '2',
            ],
            'body' => 'cdef',
        ]]);
        $this->assertSame('complete', $session->get_status('value.bin')['path']['state']);

        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('value.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $this->commit_all($session);
        $this->assertSame('new', file_get_contents($this->target . '/value.bin'));
    }

    public function testEveryCorruptPartialEntryTypeIsRejectedWithoutFollowingIt(): void {
        $outside = $this->root . '/outside-partial';
        mkdir($outside);
        file_put_contents($outside . '/sentinel', 'safe');
        foreach (['directory', 'symlink', 'fifo'] as $index => $type) {
            if ($type === 'fifo' && !function_exists('posix_mkfifo')) {
                continue;
            }
            $session = $this->session(sprintf('%032x', 1200 + $index));
            $partial = $session->get_session_directory() . '/work/partial/corrupt';
            if ($type === 'directory') {
                mkdir($partial);
                file_put_contents($partial . '/child', 'x');
            } elseif ($type === 'symlink') {
                symlink($outside, $partial);
            } else {
                posix_mkfifo($partial, 0600);
            }
            try {
                $session->get_status('corrupt');
                $this->fail('Corrupt partial ' . $type . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            $this->assertSame('safe', file_get_contents($outside . '/sentinel'));
        }
    }

    public function testDeleteStreamCompletionIsFinalAndInvalidLaterRequestsPreserveAcceptedBytes(): void {
        $session = $this->session('04040404040404040404040404040404');
        $accepted = "first\0partial";
        $this->stage($session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $accepted,
        ]]);
        try {
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => (string) strlen($accepted)],
                'body' => "/../unsafe\0",
            ]]);
            $this->fail('Unsafe later deletion record was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($accepted, file_get_contents($session->get_session_directory() . '/work/deletes'));

        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) strlen($accepted),
                'X-Delete-Complete' => '1',
            ],
            'body' => "-path\0",
        ]]);
        $final_size = $session->get_status()['delete_bytes'];
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => (string) $final_size,
                'X-Delete-Complete' => '1',
            ],
            'body' => '',
        ]]);
        try {
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => (string) $final_size],
                'body' => "later\0",
            ]]);
            $this->fail('Bytes were appended after delete completion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already complete', $exception->getMessage());
        }
    }

    public function testCreateAndDeleteCompletionPreserveExactVersionThreeMetadata(): void {
        $session = $this->session('04140414041404140414041404140414');
        $created = $this->session_metadata($session);

        $this->assertSame([
            'version',
            'session_id',
            'target_root_b64',
            'protected_paths_b64',
            'delete_upload_complete',
            'commit_started',
        ], array_keys($created));
        $this->assertSame(3, $created['version']);
        $this->assertFalse($created['delete_upload_complete']);
        $this->assertFalse($created['commit_started']);

        $this->complete_delete_upload($session);
        $completed = $this->session_metadata($session);
        $this->assertSame(array_keys($created), array_keys($completed));
        $this->assertTrue($completed['delete_upload_complete']);
        $this->assertFalse($completed['commit_started']);
    }

    public function testEverySessionMetadataShapeIsValidatedBeforeStateIsUsed(): void {
        $invalid_values = [
            ['version' => 2],
            ['session_id' => 'wrong'],
            ['delete_upload_complete' => 1],
            ['commit_started' => null],
            ['commit_started' => 0],
            ['commit_started' => 'false'],
            ['target_root_b64' => 1],
            ['target_root_b64' => '***'],
            ['protected_paths_b64' => 'not-a-list'],
            ['protected_paths_b64' => ['path' => base64_encode('safe')]],
            ['protected_paths_b64' => [1]],
            ['protected_paths_b64' => ['***']],
        ];
        foreach ($invalid_values as $index => $replacement) {
            $session = $this->session(sprintf('%032x', 1300 + $index));
            $path = $session->get_session_directory() . '/session.json';
            $metadata = json_decode( (string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($replacement as $field => $value) {
                $metadata[$field] = $value;
            }
            file_put_contents($path, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            try {
                $session->get_status();
                $this->fail('Malformed session metadata case ' . $index . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
        }

        $missing_commit_started = $this->session('05150515051505150515051505150515');
        $missing_path = $missing_commit_started->get_session_directory() . '/session.json';
        $missing_metadata = $this->session_metadata($missing_commit_started);
        unset($missing_metadata['commit_started']);
        file_put_contents($missing_path, json_encode($missing_metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $missing_commit_started->get_status();
            $this->fail('Session metadata without commit_started was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
            $this->assertStringContainsString('commit_started', $exception->getMessage());
        }

        $oversized = $this->session('05050505050505050505050505050505');
        file_put_contents($oversized->get_session_directory() . '/session.json', str_repeat(' ', 1048577));
        try {
            $oversized->get_status();
            $this->fail('Oversized session metadata was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
    }

    public function testReplacedOwnedMaintenanceMarkerStopsFinalizationWithoutDeletingIt(): void {
        $session = $this->session('06060606060606060606060606060606');
        $this->complete_delete_upload($session);
        $session->commit(1);
        file_put_contents($this->target . '/.maintenance', "<?php\n// foreign replacement\n");
        try {
            $this->commit_all($session);
            $this->fail('A replaced maintenance marker was deleted during finalization.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }
        $this->assertSame("<?php\n// foreign replacement\n", file_get_contents($this->target . '/.maintenance'));
    }

    public function testMaintenanceReplacementDuringCommitStopsFinalization(): void {
        $session = $this->session('06560656065606560656065606560656');
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
        $this->stage($session, $parts);
        $this->complete_delete_upload($session);
        $session->commit(1);
        $maintenance_path = $this->target . '/.maintenance';
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
            $session->commit(1000);
            $this->fail('A maintenance marker replaced during commit was deleted at finalization.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($process));
        }
        $this->assertSame($replacement, file_get_contents($maintenance_path));
    }

    public function testCoordinatorLockPathsCannotBeDirectoriesOrSymlinks(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 1400 + $index));
            $this->complete_delete_upload($session);
            $lock_path = $this->storage . '/apply-sessions/target.lock';
            if (is_dir($lock_path) && !is_link($lock_path)) {
                rmdir($lock_path);
            } elseif (file_exists($lock_path) || is_link($lock_path)) {
                unlink($lock_path);
            }
            if ($type === 'directory') {
                mkdir($lock_path);
            } else {
                symlink($this->target . '/coordinator-sentinel', $lock_path);
                file_put_contents($this->target . '/coordinator-sentinel', 'safe');
            }
            try {
                $session->commit(1);
                $this->fail('Coordinator ' . $type . ' was accepted as a lock file.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->target . '/coordinator-sentinel'));
            }
        }
    }

    public function testActiveCoordinatorCannotBeADirectoryOrSymlink(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 1450 + $index));
            $this->complete_delete_upload($session);
            $active_path = $this->storage . '/apply-sessions/target.active';
            if (file_exists($active_path) || is_link($active_path)) {
                unlink($active_path);
            }
            if ($type === 'directory') {
                mkdir($active_path);
                file_put_contents($active_path . '/sentinel', 'safe');
            } else {
                file_put_contents($this->target . '/active-sentinel', 'safe');
                symlink($this->target . '/active-sentinel', $active_path);
            }
            try {
                $session->commit(1);
                $this->fail('Active coordinator ' . $type . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            if ($type === 'directory') {
                $this->assertSame('safe', file_get_contents($active_path . '/sentinel'));
                unlink($active_path . '/sentinel');
                rmdir($active_path);
            } else {
                $this->assertSame('safe', file_get_contents($this->target . '/active-sentinel'));
                unlink($active_path);
            }
        }
    }

    public function testCoordinatorRecordAcceptsOnlyMissingOwnedOrValidForeignState(): void {
        $owner = $this->session('31313131424242425353535364646464');
        $this->stage_file($owner, 'owner-pending', 'new');
        $this->complete_delete_upload($owner);
        $active_path = $this->storage . '/apply-sessions/target.active';

        $owner->commit(1);
        $this->assertSame($owner->get_session_id() . "\n", file_get_contents($active_path));
        $owner->commit(1);
        $this->assertSame($owner->get_session_id() . "\n", file_get_contents($active_path));

        $other = $this->session('32323232434343435454545465656565');
        $this->stage_file($other, 'other-pending', 'new');
        $this->complete_delete_upload($other);
        try {
            $other->commit(1);
            $this->fail('A valid foreign coordinator owner was overwritten.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }
        $this->assertSame($owner->get_session_id() . "\n", file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->target . '/other-pending');
    }

    public function testCoordinatorRecordRejectsEveryMalformedRegularFileWithoutReplacingIt(): void {
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
            $session = $this->session(sprintf('%032x', 2500 + $case_index));
            $sentinel = 'malformed-coordinator-' . $case_index;
            file_put_contents($this->target . '/' . $sentinel, 'safe');
            $this->stage_file($session, 'pending-' . $case_index, 'new');
            $this->complete_delete_upload($session);
            $active_path = $this->storage . '/apply-sessions/target.active';
            file_put_contents($active_path, $record);

            $observed_exception = null;
            try {
                $session->commit(1);
            } catch (Throwable $exception) {
                $observed_exception = $exception;
            }
            $this->assertInstanceOf(
                Site_Export_Staged_Apply_Exception::class,
                $observed_exception,
                'Malformed coordinator ' . $description . ' case was accepted.'
            );
            $this->assertSame('invalid_session_state', $observed_exception->get_error_code());
            $this->assertSame($record, file_get_contents($active_path));
            $this->assertSame('safe', file_get_contents($this->target . '/' . $sentinel));
            $this->assertFileDoesNotExist($this->target . '/pending-' . $case_index);
            $this->assertFileDoesNotExist($this->target . '/.maintenance');
            $this->assertFileDoesNotExist($session->get_session_directory() . '/work/maintenance.php');
            $checkpoint = json_decode(
                (string) file_get_contents($session->get_session_directory() . '/commit.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame('deleting', $checkpoint['phase']);
            $this->assertSame(0, $checkpoint['delete_offset']);
            $this->assertSame(0, $checkpoint['deletions_applied']);
            $this->assertSame(0, $checkpoint['values_applied']);
            unlink($active_path);
            ++$case_index;
        }
    }

    public function testTruncatedCoordinatorIsRejectedWithoutWritingTheCompetingSessionId(): void {
        $owner = $this->session('33333333444444445555555566666666');
        $this->stage_file($owner, 'owner-pending', 'new');
        $this->complete_delete_upload($owner);
        $owner->commit(1);
        $active_path = $this->storage . '/apply-sessions/target.active';
        file_put_contents($active_path, '');

        $competitor = $this->session('34343434454545455656565667676767');
        $this->stage_file($competitor, 'competitor-pending', 'new');
        $this->complete_delete_upload($competitor);
        try {
            $competitor->commit(1);
            $this->fail('A truncated coordinator was treated as unclaimed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }

        $this->assertSame('', file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->target . '/competitor-pending');
        $this->assertFileDoesNotExist($competitor->get_session_directory() . '/work/maintenance.php');
    }

    public function testUnreadableCoordinatorIsRetryableAndIsNeverTreatedAsAbsent(): void {
        $session = $this->session('35353535464646465757575768686868');
        $this->stage_file($session, 'unreadable-pending', 'new');
        $this->complete_delete_upload($session);
        $active_path = $this->storage . '/apply-sessions/target.active';
        $foreign_owner = str_repeat('d', 32) . "\n";
        file_put_contents($active_path, $foreign_owner);
        chmod($active_path, 0000);
        $probe = @fopen($active_path, 'rb');
        if (is_resource($probe)) {
            fclose($probe);
            chmod($active_path, 0600);
            $this->markTestSkipped('This platform does not enforce unreadable coordinator permissions for the test process.');
        }

        try {
            $session->commit(1);
            $this->fail('An unreadable coordinator was treated as absent.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('retryable_io_error', $exception->get_error_code());
        } finally {
            chmod($active_path, 0600);
        }
        $this->assertSame($foreign_owner, file_get_contents($active_path));
        $this->assertFileDoesNotExist($this->target . '/unreadable-pending');
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testDiscardHandlesMissingTombstonedAndContendedSessionsWithoutFollowingLinks(): void {
        $missing_id = '07070707070707070707070707070707';
        $this->assertTrue(Site_Export_Staged_Apply_Session::discard($this->storage, $this->target, $missing_id, []));

        $session = $this->session('08080808080808080808080808080808');
        $tombstone = $this->storage . '/apply-sessions/.discarding-' . $session->get_session_id();
        rename($session->get_session_directory(), $tombstone);
        $lock = fopen($tombstone . '/lock', 'r+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $session->discard_workspace();
            $this->fail('A contended discard tombstone was cleaned.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertTrue($session->discard_workspace());

        $outside = $this->root . '/discard-outside';
        mkdir($outside);
        file_put_contents($outside . '/lock', 'not a session lock');
        file_put_contents($outside . '/sentinel', 'safe');
        $linked_id = '09090909090909090909090909090909';
        symlink($outside, $this->storage . '/apply-sessions/.discarding-' . $linked_id);
        try {
            Site_Export_Staged_Apply_Session::discard($this->storage, $this->target, $linked_id, []);
            $this->fail('A symlink discard tombstone was followed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertSame('safe', file_get_contents($outside . '/sentinel'));
    }

    public function testDiscardTombstoneLockCannotBeReplacedByDirectoryOrSymlink(): void {
        foreach (['directory', 'symlink'] as $index => $type) {
            $session = $this->session(sprintf('%032x', 1500 + $index));
            $tombstone = $this->storage . '/apply-sessions/.discarding-' . $session->get_session_id();
            rename($session->get_session_directory(), $tombstone);
            unlink($tombstone . '/lock');
            if ($type === 'directory') {
                mkdir($tombstone . '/lock');
            } else {
                file_put_contents($this->target . '/tombstone-lock-sentinel', 'safe');
                symlink($this->target . '/tombstone-lock-sentinel', $tombstone . '/lock');
            }
            try {
                $session->discard_workspace();
                $this->fail('Discard tombstone lock ' . $type . ' was accepted.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            if ($type === 'symlink') {
                $this->assertSame('safe', file_get_contents($this->target . '/tombstone-lock-sentinel'));
            }
        }
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

    /**
     * @return array{
     *     version:3,
     *     session_id:string,
     *     target_root_b64:string,
     *     protected_paths_b64:list<string>,
     *     delete_upload_complete:bool,
     *     commit_started:bool
     * }
     */
    private function session_metadata(Site_Export_Staged_Apply_Session $session): array {
        $metadata = json_decode(
            (string) file_get_contents($session->get_session_directory() . '/session.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($metadata);
        return $metadata;
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
