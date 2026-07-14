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

    public function testPartialFileProgressComesFromTheStagedFileAndOffsetZeroRestartsIt(): void {
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
        $partial = $session->get_status(['upload.bin']);
        $this->assertSame('partial', $partial['paths'][0]['state']);
        $this->assertSame(3, $partial['paths'][0]['accepted_bytes']);

        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('upload.bin'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        $complete = $session->get_status(['upload.bin']);
        $this->assertSame('complete', $complete['paths'][0]['state']);
        $this->assertSame(3, $complete['paths'][0]['accepted_bytes']);

        $this->commit_all($session);
        $this->assertSame('new', file_get_contents($this->target . '/upload.bin'));
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/partial/upload.bin');
    }

    public function testCommitPreservesAnExistingPluginTreeAndUsesMaintenanceForSwitching(): void {
        mkdir($this->target . '/wp-content/plugins/demo', 0700, true);
        mkdir($this->target . '/wp-content/uploads', 0700, true);
        file_put_contents($this->target . '/wp-content/plugins/demo/old.php', 'old plugin file');
        file_put_contents($this->target . '/obsolete.txt', 'remove me');

        $session = $this->session('22222222222222222222222222222222');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('wp-content/plugins/demo/new.php'),
                    'X-File-Size' => '15',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'new plugin file',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('wp-content/uploads/empty'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('current-plugin'),
                    'X-Symlink-Target' => base64_encode('wp-content/plugins/demo'),
                ],
                'body' => '',
            ],
            [
                'headers' => ['X-Chunk-Type' => 'delete-list'],
                'body' => "obsolete.txt\0",
            ],
        ]);

        $saw_maintenance = false;
        do {
            $result = $session->commit(1);
            if (($result['phase'] ?? null) === 'switching') {
                $saw_maintenance = is_file($this->target . '/.maintenance');
            }
        } while (!empty($result['send_next_request']));

        $this->assertTrue($saw_maintenance, 'maintenance stays live while a bounded switch has more work');
        $this->assertSame('old plugin file', file_get_contents($this->target . '/wp-content/plugins/demo/old.php'));
        $this->assertSame('new plugin file', file_get_contents($this->target . '/wp-content/plugins/demo/new.php'));
        $this->assertTrue(is_dir($this->target . '/wp-content/uploads/empty'));
        $this->assertTrue(is_link($this->target . '/current-plugin'));
        $this->assertSame('wp-content/plugins/demo', readlink($this->target . '/current-plugin'));
        $this->assertFileDoesNotExist($this->target . '/obsolete.txt');
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testProtectedPathsAndMalformedOffsetsDoNotReachTheLiveTarget(): void {
        $session = $this->session('33333333333333333333333333333333');
        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('wp-content/plugins/reprint/keep.php'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A protected path was staged.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Protected', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->target . '/wp-content/plugins/reprint/keep.php');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/wp-content/plugins/reprint/keep.php');
    }

    public function testForeignMaintenanceStopsBeforeThePreparedPluginIsSwapped(): void {
        mkdir($this->target . '/wp-content/plugins/demo', 0700, true);
        file_put_contents($this->target . '/wp-content/plugins/demo/plugin.php', 'old version');
        $session = $this->session('44444444444444444444444444444444');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('wp-content/plugins/demo/plugin.php'),
                'X-File-Size' => '11',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new version',
        ]]);
        file_put_contents($this->target . '/.maintenance', "<?php\n\$upgrading = 1;\n");

        try {
            $this->commit_all($session);
            $this->fail('A foreign maintenance marker allowed a live plugin swap.');
        } catch (RuntimeException $exception) {
            $this->assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
            $this->assertStringContainsString('foreign WordPress maintenance marker', $exception->getMessage());
        }

        $this->assertSame('old version', file_get_contents($this->target . '/wp-content/plugins/demo/plugin.php'));
        $this->assertSame("<?php\n\$upgrading = 1;\n", file_get_contents($this->target . '/.maintenance'));
    }

    public function testRecoveryFinishesAPluginSwapAfterTheFirstRename(): void {
        mkdir($this->target . '/wp-content/plugins/demo', 0700, true);
        file_put_contents($this->target . '/wp-content/plugins/demo/old.php', 'old plugin file');
        $session = $this->session('55555555555555555555555555555555');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('wp-content/plugins/demo/new.php'),
                'X-File-Size' => '15',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new plugin file',
        ]]);

        $action = $this->advance_until_first_prepared_action($session);
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = json_decode((string) file_get_contents($checkpoint_path), true);
        $this->assertIsArray($checkpoint);
        $path = 'wp-content/plugins/demo';
        $checkpoint['phase'] = 'switching';
        $checkpoint['prepare_offset'] = filesize($session->get_session_directory() . '/work/commit/actions.jsonl');
        $checkpoint['prepare_index'] = $checkpoint['actions_count'];
        $checkpoint['current_prepare'] = null;
        $checkpoint['transition'] = [
            'index' => 0,
            'stage' => 'prepared',
            'path_b64' => base64_encode($path),
            'expected_live' => $action['expected_live'],
            'expected_live_tree' => $action['expected_live_tree'],
            'prepared' => $action['prepared'],
            'backup_b64' => base64_encode($path),
        ];
        file_put_contents($checkpoint_path, json_encode($checkpoint, JSON_UNESCAPED_SLASHES));

        $backup = $session->get_session_directory() . '/work/backups/' . $path;
        mkdir(dirname($backup), 0700, true);
        rename($this->target . '/' . $path, $backup); // Simulate death after live -> backup.

        $this->commit_all($session);

        $this->assertSame('old plugin file', file_get_contents($this->target . '/' . $path . '/old.php'));
        $this->assertSame('new plugin file', file_get_contents($this->target . '/' . $path . '/new.php'));
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
        $this->assertFileDoesNotExist($backup);
    }

    public function testRecoveryRefusesToOverwriteAnExternalWriterAfterTheFirstRename(): void {
        $path = 'wp-content/plugins/demo';
        mkdir($this->target . '/' . $path, 0700, true);
        file_put_contents($this->target . '/' . $path . '/old.php', 'old plugin file');
        $session = $this->session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($path . '/new.php'),
                'X-File-Size' => '15',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new plugin file',
        ]]);

        $action = $this->advance_until_first_prepared_action($session);
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = json_decode((string) file_get_contents($checkpoint_path), true);
        $this->assertIsArray($checkpoint);
        $checkpoint['phase'] = 'switching';
        $checkpoint['prepare_offset'] = filesize($session->get_session_directory() . '/work/commit/actions.jsonl');
        $checkpoint['prepare_index'] = $checkpoint['actions_count'];
        $checkpoint['current_prepare'] = null;
        $checkpoint['transition'] = [
            'index' => 0,
            'stage' => 'prepared',
            'path_b64' => base64_encode($path),
            'expected_live' => $action['expected_live'],
            'expected_live_tree' => $action['expected_live_tree'],
            'prepared' => $action['prepared'],
            'backup_b64' => base64_encode($path),
        ];
        file_put_contents($checkpoint_path, json_encode($checkpoint, JSON_UNESCAPED_SLASHES));

        $backup = $session->get_session_directory() . '/work/backups/' . $path;
        mkdir(dirname($backup), 0700, true);
        rename($this->target . '/' . $path, $backup); // Simulate the first live rename before a crash.
        mkdir($this->target . '/' . $path, 0700, true);
        file_put_contents($this->target . '/' . $path . '/external.php', 'external writer');

        try {
            $this->commit_all($session);
            $this->fail('Recovery overwrote a live path changed by an external writer.');
        } catch (RuntimeException $exception) {
            $this->assertSame(Site_Export_Staged_Apply_Session::ERROR_LIVE_TREE_CHANGED, $exception->getCode());
            $this->assertStringContainsString('changed after preparation and before switching', $exception->getMessage());
        }

        $this->assertSame('external writer', file_get_contents($this->target . '/' . $path . '/external.php'));
        $this->assertSame('old plugin file', file_get_contents($backup . '/old.php'));
        $this->assertFileExists($session->get_session_directory() . '/work/prepared/' . $path . '/new.php');
    }

    public function testNestedLiveChangeAfterPreparationStopsBeforeMaintenance(): void {
        $path = 'wp-content/plugins/demo';
        mkdir($this->target . '/' . $path, 0700, true);
        file_put_contents($this->target . '/' . $path . '/old.php', 'old plugin file');
        $session = $this->session('cccccccccccccccccccccccccccccccc');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($path . '/new.php'),
                'X-File-Size' => '15',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new plugin file',
        ]]);

        $this->advance_until_first_prepared_action($session);
        file_put_contents($this->target . '/' . $path . '/external.php', 'external writer');

        try {
            $session->commit(1);
            $this->fail('A nested external write after preparation allowed a live plugin swap.');
        } catch (RuntimeException $exception) {
            $this->assertSame(Site_Export_Staged_Apply_Session::ERROR_LIVE_TREE_CHANGED, $exception->getCode());
            $this->assertStringContainsString('changed after preparation and before switching', $exception->getMessage());
        }

        $this->assertSame('old plugin file', file_get_contents($this->target . '/' . $path . '/old.php'));
        $this->assertSame('external writer', file_get_contents($this->target . '/' . $path . '/external.php'));
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testCorruptCommitCheckpointCannotBeDiscardedAsAnUploadOnlySession(): void {
        $session = $this->session('cccccccccccccccccccccccccccccccc');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('pending.txt'),
                'X-File-Size' => '1',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'x',
        ]]);
        $session->commit(1); // Create a preparing checkpoint without switching live files.
        file_put_contents($session->get_session_directory() . '/commit.json', '{"version":1,"phase":"switching"}');

        try {
            $session->discard_workspace();
            $this->fail('A corrupt checkpoint was treated as safely discardable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_STATE, $exception->getCode());
            $this->assertStringContainsString('unsupported version', $exception->getMessage());
        }

        $this->assertDirectoryExists($session->get_session_directory());
        $this->assertFileDoesNotExist($this->target . '/pending.txt');
    }

    public function testInvalidPartStopsBeforeTheFollowingPartIsRead(): void {
        $session = $this->session('66666666666666666666666666666666');
        $boundary = 'test-boundary';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('bad.bin') . "\r\n"
            . "X-File-Size: 2\r\n"
            . "X-Chunk-Offset: 1\r\n"
            . "Content-Length: 1\r\n\r\n"
            . "x\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('must-not-be-read.bin') . "\r\n"
            . "X-File-Size: 1\r\n"
            . "X-Chunk-Offset: 0\r\n"
            . "Content-Length: 1\r\n\r\n"
            . "y\r\n"
            . '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $session->accept_upload(new Site_Export_Multipart_Stream_Input($input, $boundary));
        try {
            try {
                $session->next_change();
                $this->fail('An offset gap was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Start at offset 0', $exception->getMessage());
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/must-not-be-read.bin');
    }

    public function testPartTypeRejectsHeadersForAnotherLogicalChange(): void {
        $session = $this->session('77777777777777777777777777777777');
        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty'),
                    'X-File-Path' => base64_encode('not-a-file-part'),
                ],
                'body' => '',
            ]]);
            $this->fail('A directory part accepted file-only metadata.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not allow header', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/empty');
    }

    public function testStorageBelowTheTargetIsAutomaticallyProtectedFromTheSamePush(): void {
        $storage = $this->target . '/private-staging';
        $session = Site_Export_Staged_Apply_Session::create(
            $storage,
            $this->target,
            [],
            '88888888888888888888888888888888',
            ['wp-content/plugins', 'wp-content/themes']
        );
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
            $this->fail('The session staging directory was writable through the push target.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Protected', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($storage . '/escape.php');
    }

    public function testFilesystemRootRemainsCanonicalWhenReopeningASession(): void {
        $session = Site_Export_Staged_Apply_Session::create(
            $this->storage,
            '/',
            [],
            '23232323232323232323232323232323',
            []
        );

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            '/',
            $session->get_session_id(),
            [],
            []
        );
        $this->assertSame($session->get_session_id(), $reopened->get_session_id());
        $this->assertTrue($session->discard_workspace());
    }

    public function testChildBelowAnExistingTargetSymlinkReplacesTheLinkWithoutFollowingIt(): void {
        $outside = $this->root . '/outside';
        mkdir($outside, 0700, true);
        $this->assertTrue(symlink($outside, $this->target . '/linked-parent'));
        $session = $this->session('99999999999999999999999999999999');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('linked-parent/escape.txt'),
                'X-File-Size' => '1',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'x',
        ]]);
        $this->commit_all($session);

        $this->assertFileDoesNotExist($outside . '/escape.txt');
        $this->assertFalse(is_link($this->target . '/linked-parent'));
        $this->assertSame('x', file_get_contents($this->target . '/linked-parent/escape.txt'));
    }

    public function testCompletedStagedSymlinkCannotBeUsedAsAParentForALaterPart(): void {
        $outside = $this->root . '/outside';
        mkdir($outside, 0700, true);
        $session = $this->session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode('staged-link'),
                'X-Symlink-Target' => base64_encode($outside),
            ],
            'body' => '',
        ]]);

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('staged-link/escape.txt'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A staged symlink was followed while staging its descendant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('workspace parent is not a real directory', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($outside . '/escape.txt');
    }

    public function testMaintenanceRefreshUpdatesTheTimestampWordPressReads(): void {
        $session = $this->session('dddddddddddddddddddddddddddddddd');
        $parts = [];
        foreach (['one.txt', 'two.txt', 'three.txt'] as $path) {
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode($path),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ];
        }
        $this->stage($session, $parts);

        $result = null;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $result = $session->commit(1);
            if (is_file($this->target . '/.maintenance') && !empty($result['send_next_request'])) {
                break;
            }
        }
        $this->assertIsArray($result);
        $this->assertFileExists($this->target . '/.maintenance');

        $marker = (string) file_get_contents($this->target . '/.maintenance');
        $stale_marker = preg_replace_callback('/(\$upgrading\s*=\s*[\'\"]?)([0-9]+)([\'\"]?;)/', static function (array $timestamp_match): string {
            return $timestamp_match[1] . str_pad('1', strlen($timestamp_match[2]), '0', STR_PAD_LEFT) . $timestamp_match[3];
        }, $marker, 1);
        $this->assertIsString($stale_marker);
        file_put_contents($this->target . '/.maintenance', $stale_marker);

        $session->commit(1);
        $refreshed = (string) file_get_contents($this->target . '/.maintenance');
        $this->assertSame(1, preg_match('/\$upgrading\s*=\s*[\'\"]?([0-9]+)[\'\"]?;/', $refreshed, $matches));
        $this->assertGreaterThanOrEqual(time() - 2, (int) $matches[1]);
    }

    public function testCommitMaterializesItsDiskBackedActionPlanAcrossRequests(): void {
        $session = $this->session('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('alpha/a.txt'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'a',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('beta/b.txt'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'b',
            ],
        ]);

        $session->commit(1);
        $result = $session->commit(1);
        $checkpoint = json_decode( (string) file_get_contents($session->get_session_directory() . '/commit.json'), true);
        $stagedJournal = $session->get_session_directory() . '/work/staged.jsonl';

        $this->assertSame('materializing', $result['phase']);
        $this->assertIsArray($checkpoint);
        $this->assertSame('materializing', $checkpoint['phase'] ?? null);
        $this->assertGreaterThan(0, $checkpoint['staged_paths_offset'] ?? 0);
        $this->assertLessThan(filesize($stagedJournal), $checkpoint['staged_paths_offset'] ?? PHP_INT_MAX);
        $this->assertArrayNotHasKey('actions', $checkpoint, 'An unbounded action array must not live in commit.json.');
        $this->assertFileExists($session->get_session_directory() . '/work/commit/actions.jsonl');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/prepared/alpha/a.txt');

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint'],
            ['wp-content/plugins', 'wp-content/themes']
        );
        $this->commit_all($reopened);
        $this->assertSame('a', file_get_contents($this->target . '/alpha/a.txt'));
        $this->assertSame('b', file_get_contents($this->target . '/beta/b.txt'));
    }

    public function testMaterializationRecoversAnIndexPublishedBeforeItsCandidateRecord(): void {
        $session = $this->session('21212121212121212121212121212121');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('survivor.txt'),
                'X-File-Size' => '8',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'survivor',
        ]]);

        $session->commit(1);
        $hash = hash('sha256', 'survivor.txt');
        $index = $session->get_session_directory() . '/work/commit/action-index/'
            . substr($hash, 0, 2) . '/' . substr($hash, 2) . '.json';
        mkdir(dirname($index), 0700, true);
        file_put_contents($index, json_encode([
            'path_b64' => base64_encode('survivor.txt'),
            'kind' => 'entry',
            'deleted' => false,
        ]));

        $this->commit_all($session);
        $this->assertSame('survivor', file_get_contents($this->target . '/survivor.txt'));
    }

    public function testLargePreparedFileResumesFromBoundedPersistedPieces(): void {
        $contents = str_repeat('0123456789abcdef', 65537);
        $session = $this->session('ffffffffffffffffffffffffffffffff');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('large.bin'),
                'X-File-Size' => (string) strlen($contents),
                'X-Chunk-Offset' => '0',
            ],
            'body' => $contents,
        ]]);

        $prepared = $session->get_session_directory() . '/work/prepared/large.bin';
        $saw_partial_candidate = false;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $result = $session->commit(1);
            clearstatcache(true, $prepared);
            if (is_file($prepared)) {
                $size = filesize($prepared);
                if (is_int($size) && $size > 0 && $size < strlen($contents)) {
                    $saw_partial_candidate = true;
                    break;
                }
            }
            if (empty($result['send_next_request'])) {
                break;
            }
        }
        $this->assertTrue($saw_partial_candidate, 'One commit step copied the complete large file instead of checkpointing a bounded piece.');

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint'],
            ['wp-content/plugins', 'wp-content/themes']
        );
        $this->commit_all($reopened);
        $this->assertSame($contents, file_get_contents($this->target . '/large.bin'));
    }

    public function testPreparedDeploymentTreeResumesAfterReopeningTheSession(): void {
        $plugin = 'wp-content/plugins/resumable';
        mkdir($this->target . '/' . $plugin . '/nested', 0750, true);
        file_put_contents($this->target . '/' . $plugin . '/keep.php', 'keep');
        file_put_contents($this->target . '/' . $plugin . '/nested/also-keep.php', 'nested');
        symlink('keep.php', $this->target . '/' . $plugin . '/current.php');

        $session = $this->session('15151515151515151515151515151515');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode($plugin . '/new.php'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);

        $checkpoint = null;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $session->commit(1);
            $checkpoint = json_decode( (string) file_get_contents($session->get_session_directory() . '/commit.json'), true);
            $current_prepare = $checkpoint['current_prepare'] ?? null;
            if (is_array($current_prepare) && 'copying' === ( $current_prepare['stage'] ?? null )) {
                break;
            }
        }
        $this->assertIsArray($checkpoint);
        $this->assertIsArray($checkpoint['current_prepare'] ?? null, 'Preparation never exposed a durable resumable tree cursor.');

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint'],
            ['wp-content/plugins', 'wp-content/themes']
        );
        $this->commit_all($reopened);

        $this->assertSame('keep', file_get_contents($this->target . '/' . $plugin . '/keep.php'));
        $this->assertSame('nested', file_get_contents($this->target . '/' . $plugin . '/nested/also-keep.php'));
        $this->assertSame('new', file_get_contents($this->target . '/' . $plugin . '/new.php'));
        $this->assertSame('keep.php', readlink($this->target . '/' . $plugin . '/current.php'));
    }

    public function testKilledPositivePathAppendCannotLoseOrInventAStagedValue(): void {
        $session = $this->session('16161616161616161616161616161616');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('real.txt'),
                'X-File-Size' => '4',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'real',
        ]]);
        $journal = $session->get_session_directory() . '/work/staged.jsonl';
        file_put_contents($journal, json_encode(['path_b64' => base64_encode('ghost.txt')]) . "\n", FILE_APPEND);
        file_put_contents($journal, json_encode([
            'path_b64' => base64_encode('ghost-directory'),
            'kind' => 'directory-mode',
            'mode' => 0711,
        ]) . "\n", FILE_APPEND);
        file_put_contents($journal, '{"path_b64":"killed', FILE_APPEND);

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint'],
            ['wp-content/plugins', 'wp-content/themes']
        );
        $this->commit_all($reopened);

        $this->assertSame('real', file_get_contents($this->target . '/real.txt'));
        $this->assertFileDoesNotExist($this->target . '/ghost.txt');
        $this->assertDirectoryDoesNotExist($this->target . '/ghost-directory');
    }

    public function testCommitPreservesExistingModesAndAppliesUploadedModes(): void {
        $plugin = $this->target . '/wp-content/plugins/modes';
        mkdir($plugin, 0751, true);
        chmod($plugin, 0751);
        file_put_contents($plugin . '/unchanged.php', 'unchanged');
        chmod($plugin . '/unchanged.php', 0640);

        $session = $this->session('12121212121212121212121212121212');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('wp-content/plugins/modes/executable.php'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
                'X-File-Mode' => '0755',
            ],
            'body' => 'new',
        ]]);
        $this->commit_all($session);

        $this->assertSame(0751, fileperms($plugin) & 07777);
        $this->assertSame(0640, fileperms($plugin . '/unchanged.php') & 07777);
        $this->assertSame(0755, fileperms($plugin . '/executable.php') & 07777);
    }

    public function testDirectoryModeMetadataPreservesChildrenWhileChangingTheDirectory(): void {
        $plugin = $this->target . '/wp-content/plugins/directory-mode';
        mkdir($plugin, 0755, true);
        file_put_contents($plugin . '/keep.php', 'keep');

        $session = $this->session('17171717171717171717171717171717');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'directory-mode',
                'X-Directory-Path' => base64_encode('wp-content/plugins/directory-mode'),
                'X-Directory-Mode' => '0711',
            ],
            'body' => '',
        ]]);
        $this->commit_all($session);

        $this->assertSame('keep', file_get_contents($plugin . '/keep.php'));
        $this->assertSame(0711, fileperms($plugin) & 07777);
    }

    public function testReadOnlyDirectoryModesAreAppliedAfterTheirChildrenArePrepared(): void {
        $plugin = $this->target . '/wp-content/plugins/read-only-mode';
        $nested = $plugin . '/nested';
        mkdir($nested, 0755, true);
        file_put_contents($nested . '/keep.php', 'keep');
        chmod($nested, 0555);

        $session = $this->session('18181818181818181818181818181818');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory-mode',
                    'X-Directory-Path' => base64_encode('wp-content/plugins/read-only-mode/nested'),
                    'X-Directory-Mode' => '0511',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('wp-content/plugins/read-only-mode/nested/new.php'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'new',
            ],
        ]);

        try {
            $this->commit_all($session);
            $this->assertSame('keep', file_get_contents($nested . '/keep.php'));
            $this->assertSame('new', file_get_contents($nested . '/new.php'));
            $this->assertSame(0511, fileperms($nested) & 07777);
        } finally {
            @chmod($nested, 0755);
        }
    }

    public function testReadOnlyActionRootCanBeInstalledAndReplaced(): void {
        $relative_root = 'read-only-root';
        $live_root = $this->target . '/' . $relative_root;
        $first = $this->session('19191919191919191919191919191919');
        $this->stage($first, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory-mode',
                    'X-Directory-Path' => base64_encode($relative_root),
                    'X-Directory-Mode' => '0555',
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode($relative_root . '/first.txt'),
                    'X-File-Size' => '5',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'first',
            ],
        ]);

        try {
            $this->commit_all($first);
            $this->assertSame(0555, fileperms($live_root) & 07777);

            $second = $this->session('20202020202020202020202020202020');
            $this->stage($second, [
                [
                    'headers' => [
                        'X-Chunk-Type' => 'directory-mode',
                        'X-Directory-Path' => base64_encode($relative_root),
                        'X-Directory-Mode' => '0511',
                    ],
                    'body' => '',
                ],
                [
                    'headers' => [
                        'X-Chunk-Type' => 'file',
                        'X-File-Path' => base64_encode($relative_root . '/second.txt'),
                        'X-File-Size' => '6',
                        'X-Chunk-Offset' => '0',
                    ],
                    'body' => 'second',
                ],
            ]);
            $this->commit_all($second);

            $this->assertSame('first', file_get_contents($live_root . '/first.txt'));
            $this->assertSame('second', file_get_contents($live_root . '/second.txt'));
            $this->assertSame(0511, fileperms($live_root) & 07777);
        } finally {
            @chmod($live_root, 0755);
            foreach (glob($this->storage . '/apply-sessions/*/work/{prepared,backups}/' . $relative_root, GLOB_BRACE) ?: [] as $private_root) {
                @chmod($private_root, 0755);
            }
        }
    }

    public function testSameSizeRewriteWithinOneCtimeTickStopsTheSwitch(): void {
        $session = $this->session('13131313131313131313131313131313');
        $live = $this->target . '/same-size.txt';
        $now = microtime(true);
        while ($now - floor($now) > 0.25) {
            usleep(10000);
            $now = microtime(true);
        }
        file_put_contents($live, 'AAAA');
        clearstatcache(true, $live);
        $before = lstat($live);
        $fingerprintMethod = new ReflectionMethod(Site_Export_Staged_Apply_Session::class, 'tree_fingerprint');
        $beforeFingerprint = $fingerprintMethod->invoke($session, $live);
        file_put_contents($live, 'BBBB');
        clearstatcache(true, $live);
        $after = lstat($live);
        $afterFingerprint = $fingerprintMethod->invoke($session, $live);
        $this->assertIsArray($before);
        $this->assertIsArray($after);
        if ($before['ctime'] !== $after['ctime']) {
            $this->markTestSkipped('This filesystem exposes sub-second ctime changes through PHP lstat().');
        }
        $this->assertNotSame($beforeFingerprint, $afterFingerprint, 'A live fingerprint ignored a same-size content rewrite in one ctime tick.');
    }

    public function testConfiguredDeploymentRootSwitchesAllOfItsChangesTogether(): void {
        $unit = 'custom-plugins/demo';
        mkdir($this->target . '/' . $unit, 0700, true);
        file_put_contents($this->target . '/' . $unit . '/old.php', 'old');
        $session = Site_Export_Staged_Apply_Session::create(
            $this->storage,
            $this->target,
            ['wp-content/plugins/reprint'],
            '14141414141414141414141414141414',
            ['custom-plugins']
        );
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode($unit . '/new.php'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'new',
            ],
            [
                'headers' => ['X-Chunk-Type' => 'delete-list'],
                'body' => $unit . "/old.php\0",
            ],
        ]);

        do {
            $result = $session->commit(1);
            $oldExists = file_exists($this->target . '/' . $unit . '/old.php');
            $newExists = file_exists($this->target . '/' . $unit . '/new.php');
            $this->assertTrue(
                $oldExists !== $newExists,
                'A configured deployment unit exposed an intermediate per-file switch.'
            );
        } while (!empty($result['send_next_request']));
        $this->assertSame('new', file_get_contents($this->target . '/' . $unit . '/new.php'));
    }

    private function session(string $id): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage,
            $this->target,
            ['wp-content/plugins/reprint'],
            $id,
            ['wp-content/plugins', 'wp-content/themes']
        );
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
            $body .= 'Content-Length: ' . strlen($part['body']) . "\r\n\r\n";
            $body .= $part['body'] . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $reader = new Site_Export_Multipart_Stream_Input($input, $boundary);
        $session->accept_upload($reader);
        try {
            while ($session->next_change()) {
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
    }

    private function commit_all(Site_Export_Staged_Apply_Session $session): void {
        do {
            $result = $session->commit(2);
        } while (!empty($result['send_next_request']));
    }

    /** @return array<string,mixed> */
    private function advance_until_first_prepared_action(Site_Export_Staged_Apply_Session $session): array {
        $path = $session->get_session_directory() . '/work/commit/prepared-actions.jsonl';
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $session->commit(1);
            if (!is_file($path) || filesize($path) === 0) {
                continue;
            }
            $handle = fopen($path, 'rb');
            $this->assertIsResource($handle);
            $line = fgets($handle);
            fclose($handle);
            $action = is_string($line) ? json_decode($line, true) : null;
            $this->assertIsArray($action);
            return $action;
        }
        $this->fail('The first prepared action was not checkpointed.');
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
