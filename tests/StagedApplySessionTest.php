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

        $session->commit(1); // Prepare only; live files remain untouched.
        try {
            $session->commit(1);
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

        $session->commit(1); // Build and checkpoint the prepared candidate.
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = json_decode((string) file_get_contents($checkpoint_path), true);
        $this->assertIsArray($checkpoint);
        $action = $checkpoint['actions'][0] ?? null;
        $this->assertIsArray($action);
        $path = 'wp-content/plugins/demo';
        $checkpoint['phase'] = 'switching';
        $checkpoint['transition'] = [
            'index' => 0,
            'stage' => 'prepared',
            'path_b64' => base64_encode($path),
            'expected_live' => $action['expected_live'],
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

        $session->commit(1); // Build and checkpoint the private candidate.
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = json_decode((string) file_get_contents($checkpoint_path), true);
        $this->assertIsArray($checkpoint);
        $action = $checkpoint['actions'][0] ?? null;
        $this->assertIsArray($action);
        $checkpoint['phase'] = 'switching';
        $checkpoint['transition'] = [
            'index' => 0,
            'stage' => 'prepared',
            'path_b64' => base64_encode($path),
            'expected_live' => $action['expected_live'],
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
            $this->assertStringContainsString('unexpected live, prepared, or backup state', $exception->getMessage());
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

        $session->commit(1); // Build and checkpoint the private candidate.
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
            $this->assertStringContainsString('action list', $exception->getMessage());
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
            '88888888888888888888888888888888'
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

    public function testExistingTargetSymlinkCannotBeUsedAsAStagedParent(): void {
        $outside = $this->root . '/outside';
        mkdir($outside, 0700, true);
        $this->assertTrue(symlink($outside, $this->target . '/linked-parent'));
        $session = $this->session('99999999999999999999999999999999');
        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('linked-parent/escape.txt'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A target symlink parent was followed while staging.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symlinked target parent', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($outside . '/escape.txt');
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

    private function session(string $id): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage,
            $this->target,
            ['wp-content/plugins/reprint'],
            $id
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
