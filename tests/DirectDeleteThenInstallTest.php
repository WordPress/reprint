<?php

use PHPUnit\Framework\TestCase;

final class DirectDeleteThenInstallTest extends TestCase {

    private string $root;
    private string $target;
    private string $storage;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/direct-apply-' . bin2hex(random_bytes(8));
        $this->target = $this->root . '/target';
        $this->storage = $this->root . '/storage';
        mkdir($this->target, 0700, true);
        mkdir($this->storage, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->root);
    }

    public function testCommitDeletesBeforeInstallingAndConsumesTheStagingTree(): void {
        mkdir($this->target . '/tree', 0700, true);
        file_put_contents($this->target . '/tree/old.txt', 'old');

        $session = $this->session('11111111111111111111111111111111');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '0',
                ],
                'body' => "tree\0",
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('tree/new.txt'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'new',
            ],
        ]);
        $this->complete_delete_upload($session);

        $saw_deleted_before_install = false;
        do {
            $result = $session->commit(1);
            if (!file_exists($this->target . '/tree/old.txt') && !file_exists($this->target . '/tree/new.txt')) {
                $saw_deleted_before_install = true;
            }
        } while ($result['send_next_request']);

        $this->assertTrue($saw_deleted_before_install);
        $this->assertSame('new', file_get_contents($this->target . '/tree/new.txt'));
        $this->assertSame([], $this->directory_entries($session->get_session_directory() . '/work/files'));
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/prepared');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/backups');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/commit');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/staged.jsonl');
    }

    public function testCommitProcessesMultipleEntriesInOneCall(): void {
        $session = $this->session('12121212121212121212121212121212');
        $this->stage_file($session, 'first.txt', 'one');
        $this->stage_file($session, 'second.txt', 'two');
        $this->stage_file($session, 'third.txt', 'three');
        $this->complete_delete_upload($session);

        $result = $session->commit(16);

        $this->assertSame('complete', $result['phase']);
        $this->assertFalse($result['send_next_request']);
        $this->assertGreaterThan(1, $result['entries_processed']);
        $this->assertSame('one', file_get_contents($this->target . '/first.txt'));
        $this->assertSame('two', file_get_contents($this->target . '/second.txt'));
        $this->assertSame('three', file_get_contents($this->target . '/third.txt'));
    }

    public function testDeleteUploadAcceptsReplayAndContinuesFromItsActualRawSize(): void {
        $session = $this->session('22222222222222222222222222222222');
        $first = "first\0partial";
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $first,
        ]]);

        $replay_and_continue = $first . "-path\0";
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $replay_and_continue,
        ]]);

        $this->assertSame(
            $replay_and_continue,
            file_get_contents($session->get_session_directory() . '/work/deletes')
        );
    }

    public function testDeleteUploadRejectsOffsetGapsAndDifferingReplayBytes(): void {
        $session = $this->session('33333333333333333333333333333333');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "first\0",
        ]]);

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '99',
                ],
                'body' => "later\0",
            ]]);
            $this->fail('An offset gap was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame(Site_Export_Staged_Apply_Session::ERROR_OFFSET_GAP, $exception->get_error_code());
            $this->assertStringContainsString('offset 99', $exception->getMessage());
            $this->assertStringContainsString('6 bytes', $exception->getMessage());
        }

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '0',
                ],
                'body' => "other\0",
            ]]);
            $this->fail('Differing replay bytes were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('replay differs', $exception->getMessage());
        }

        $this->assertSame("first\0", file_get_contents($session->get_session_directory() . '/work/deletes'));
    }

    public function testDeleteUploadRequiresAnOffsetAndRejectsEmptyRecords(): void {
        $session = $this->session('00110011001100110011001100110011');
        try {
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list'],
                'body' => "path\0",
            ]]);
            $this->fail('A delete part without X-Delete-Offset was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('x-delete-offset', $exception->getMessage());
        }

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '0',
                ],
                'body' => "path\0\0",
            ]]);
            $this->fail('An empty delete record was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('empty deletion record', $exception->getMessage());
        }
        $this->assertSame('', file_get_contents($session->get_session_directory() . '/work/deletes'));
    }

    public function testDeletePathLimitIsEnforcedAcrossRequestsWithoutTruncatingAcceptedBytes(): void {
        $session = $this->session('00220022002200220022002200220022');
        $prefix = str_repeat('a', 4090);
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $prefix,
        ]]);

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '4090',
                ],
                'body' => '1234567',
            ]]);
            $this->fail('A delete path longer than the cross-request limit was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('4096 bytes', $exception->getMessage());
        }

        $this->assertSame($prefix, file_get_contents($session->get_session_directory() . '/work/deletes'));
    }

    public function testReopenUsesTheActualDeleteFileSizeAndAcceptsReplayContinuation(): void {
        $session = $this->session('00330033003300330033003300330033');
        $stored = "first\0part";
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $stored,
        ]]);

        $reopened = $this->reopen($session);
        $this->assertSame(strlen($stored), $reopened->get_status()['delete_bytes']);
        $complete = "first\0partial\0";
        $this->stage($reopened, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $complete,
        ]]);

        $this->assertSame($complete, file_get_contents($session->get_session_directory() . '/work/deletes'));
    }

    public function testCommitRequiresTheDeleteCompletionDeclaration(): void {
        $session = $this->session('00440044004400440044004400440044');
        try {
            $session->commit(1);
            $this->fail('Commit began without a completed delete upload declaration.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('explicit completed delete upload', $exception->getMessage());
        }

        $this->complete_delete_upload($session);
        $this->commit_all($session);
    }

    public function testCommitRejectsAnUnterminatedDeleteRecordWithoutTruncatingIt(): void {
        $session = $this->session('44444444444444444444444444444444');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => 'unfinished',
        ]]);
        $this->complete_delete_upload($session);

        try {
            $session->commit(1);
            $this->fail('Commit accepted an unterminated delete record.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('end in NUL', $exception->getMessage());
        }

        $this->assertSame('unfinished', file_get_contents($session->get_session_directory() . '/work/deletes'));
    }

    public function testModeTransportIsRejected(): void {
        $session = $this->session('55555555555555555555555555555555');

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('mode.txt'),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                    'X-File-Mode' => '0600',
                ],
                'body' => 'x',
            ]]);
            $this->fail('A file mode header was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not allow header', $exception->getMessage());
        }

        try {
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory-mode',
                    'X-Directory-Path' => base64_encode('directory'),
                    'X-Directory-Mode' => '0700',
                ],
                'body' => '',
            ]]);
            $this->fail('A directory-mode part was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('file, directory, symlink, or delete-list', $exception->getMessage());
        }
    }

    public function testFileAndSymlinkReplaceCompatibleLiveValuesWithoutDeleteRecords(): void {
        file_put_contents($this->target . '/file', 'old file');
        file_put_contents($this->target . '/outside-sentinel', 'safe');
        symlink('outside-sentinel', $this->target . '/link');

        $session = $this->session('66666666666666666666666666666666');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('file'),
                    'X-File-Size' => '3',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'new',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'symlink',
                    'X-Symlink-Path' => base64_encode('link'),
                    'X-Symlink-Target' => base64_encode('new-target'),
                ],
                'body' => '',
            ],
        ]);

        $this->commit_all($session);

        $this->assertSame('new', file_get_contents($this->target . '/file'));
        $this->assertTrue(is_link($this->target . '/link'));
        $this->assertSame('new-target', readlink($this->target . '/link'));
        $this->assertSame('safe', file_get_contents($this->target . '/outside-sentinel'));
    }

    public function testExplicitEmptyAndStructuralDirectoriesRemainDistinctPendingValues(): void {
        $session = $this->session('00550055005500550055005500550055');
        $this->stage($session, [
            [
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty'),
                ],
                'body' => '',
            ],
            [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('structural/child.txt'),
                    'X-File-Size' => '5',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'child',
            ],
        ]);
        $this->commit_all($session);

        $this->assertDirectoryExists($this->target . '/empty');
        $this->assertSame([], $this->directory_entries($this->target . '/empty'));
        $this->assertSame('child', file_get_contents($this->target . '/structural/child.txt'));
    }

    public function testExplicitEmptyDirectoryUsesTheTargetProcessUmask(): void {
        $previous_umask = umask(0027);
        try {
            $session = $this->session('abababababababababababababababab');
            $this->stage($session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty'),
                ],
                'body' => '',
            ]]);
            $this->commit_all($session);

            clearstatcache(true, $this->target . '/empty');
            $this->assertSame(0750, fileperms($this->target . '/empty') & 0777);
        } finally {
            umask($previous_umask);
        }
    }

    public function testObservedSymlinkAncestorStopsCommitAndLeavesMaintenanceActive(): void {
        mkdir($this->target . '/outside', 0700, true);
        file_put_contents($this->target . '/outside/sentinel', 'safe');

        $session = $this->session('77777777777777777777777777777777');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('parent/file.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        symlink('outside', $this->target . '/parent');

        try {
            $this->commit_all($session);
            $this->fail('A symlink ancestor was traversed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
            $this->assertSame('parent/file.txt', base64_decode($exception->get_context()['path_b64'], true));
            $this->assertSame('parent', base64_decode($exception->get_context()['conflict_path_b64'], true));
        }

        $this->assertSame('safe', file_get_contents($this->target . '/outside/sentinel'));
        $this->assertFileExists($this->target . '/.maintenance');
    }

    public function testCommitCheckpointContainsOnlyBoundedBase64PathState(): void {
        $session = $this->session('88888888888888888888888888888888');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode("tree/line\nbreak"),
                'X-File-Size' => '1',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'x',
        ]]);
        $this->complete_delete_upload($session);

        $session->commit(1);
        $checkpoint = json_decode((string) file_get_contents($session->get_session_directory() . '/commit.json'), true);

        $this->assertIsArray($checkpoint);
        $this->assertSame(2, $checkpoint['version']);
        $this->assertContains($checkpoint['phase'], ['deleting', 'applying', 'complete']);
        $this->assertArrayHasKey('delete_offset', $checkpoint);
        $this->assertArrayHasKey('current_deletion_b64', $checkpoint);
        $this->assertArrayHasKey('current_installation', $checkpoint);
        $this->assertArrayHasKey('traversal_stack', $checkpoint);
        $this->assertArrayNotHasKey('actions_count', $checkpoint);
        $this->assertArrayNotHasKey('prepare_offset', $checkpoint);
        $this->assertArrayNotHasKey('transition', $checkpoint);
        $checkpoint_keys = array_keys($checkpoint);
        sort($checkpoint_keys, SORT_STRING);
        $this->assertSame([
            'current_deletion_b64',
            'current_installation',
            'delete_offset',
            'deletions_applied',
            'maintenance_token',
            'phase',
            'traversal_stack',
            'values_applied',
            'version',
        ], $checkpoint_keys);
        $this->assertStringNotContainsString("line\nbreak", (string) file_get_contents($session->get_session_directory() . '/commit.json'));
    }

    public function testDeepTraversalCheckpointGrowsWithPathLengthRatherThanEveryPathPrefix(): void {
        $session = $this->session('89898989898989898989898989898989');
        $directory_depth = 300;
        $path = implode('/', array_fill(0, $directory_depth, 'd')) . '/leaf.txt';
        $this->stage_file($session, $path, 'value');
        $this->complete_delete_upload($session);

        $session->commit($directory_depth + 1);
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint = $this->checkpoint($session);

        $this->assertCount($directory_depth, $checkpoint['traversal_stack']);
        $this->assertLessThan(32768, filesize($checkpoint_path));
        foreach ($checkpoint['traversal_stack'] as $frame) {
            $this->assertSame(['component_b64'], array_keys($frame));
            $this->assertSame('d', base64_decode($frame['component_b64'], true));
        }

        $this->commit_all($this->reopen($session));
        $this->assertSame('value', file_get_contents($this->target . '/' . $path));
    }

    public function testInterruptedInstallationWithStagedEntryPresentRemainsPending(): void {
        $session = $this->session('99999999999999999999999999999999');
        $this->stage_file($session, 'pending.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $this->set_current_installation($session, 'pending.txt', 'file');

        $reopened = $this->reopen($session);
        $this->commit_all($reopened);

        $this->assertSame('new', file_get_contents($this->target . '/pending.txt'));
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/files/pending.txt');
    }

    public function testInterruptedInstallationWithExpectedLiveTypeCompletes(): void {
        $session = $this->session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->stage_file($session, 'installed.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $this->set_current_installation($session, 'installed.txt', 'file');
        rename(
            $session->get_session_directory() . '/work/files/installed.txt',
            $this->target . '/installed.txt'
        );

        $this->commit_all($this->reopen($session));

        $this->assertSame('new', file_get_contents($this->target . '/installed.txt'));
    }

    public function testInterruptedInstallationRejectsASymlinkAncestorBeforeInspectingTheDestination(): void {
        mkdir($this->target . '/outside', 0700, true);
        file_put_contents($this->target . '/outside/installed.txt', 'outside');
        $session = $this->session('abababababababababababababababab');
        $this->stage_file($session, 'parent/installed.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $this->set_current_installation($session, 'parent/installed.txt', 'file');
        unlink($session->get_session_directory() . '/work/files/parent/installed.txt');
        symlink('outside', $this->target . '/parent');

        try {
            $this->reopen($session)->commit(1);
            $this->fail('Interrupted installation followed a symlink ancestor.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
            $this->assertSame('parent/installed.txt', base64_decode($exception->get_context()['path_b64'], true));
            $this->assertSame('parent', base64_decode($exception->get_context()['conflict_path_b64'], true));
        }

        $this->assertSame('outside', file_get_contents($this->target . '/outside/installed.txt'));
        $this->assertFileExists($this->target . '/.maintenance');
    }

    public function testInterruptedInstallationWithBothEntriesAbsentIsTerminal(): void {
        $session = $this->session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->stage_file($session, 'missing.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $this->set_current_installation($session, 'missing.txt', 'file');
        unlink($session->get_session_directory() . '/work/files/missing.txt');

        try {
            $this->reopen($session)->commit(1);
            $this->fail('Missing staged and live entries were treated as installed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
            $this->assertSame('absent', $exception->get_context()['observed_live_identity']['type']);
        }

        $this->assertFileExists($this->target . '/.maintenance');
    }

    public function testInterruptedInstallationWithIncompatibleLiveTypeIsTerminal(): void {
        $session = $this->session('cccccccccccccccccccccccccccccccc');
        $this->stage_file($session, 'conflict', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $this->set_current_installation($session, 'conflict', 'file');
        unlink($session->get_session_directory() . '/work/files/conflict');
        mkdir($this->target . '/conflict');

        try {
            $this->reopen($session)->commit(1);
            $this->fail('An incompatible live directory completed a file installation.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
            $this->assertSame('directory', $exception->get_context()['observed_live_identity']['type']);
        }

        $this->assertDirectoryExists($this->target . '/conflict');
        $this->assertFileExists($this->target . '/.maintenance');
    }

    public function testStructuralDirectoryResumesAfterLiveCreation(): void {
        $session = $this->session('dddddddddddddddddddddddddddddddd');
        $this->stage_file($session, 'tree/child.txt', 'child');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $session->commit(1);

        $checkpoint = $this->checkpoint($session);
        $this->assertSame('tree', base64_decode($checkpoint['traversal_stack'][0]['component_b64'], true));
        $this->assertDirectoryExists($this->target . '/tree');

        $this->commit_all($this->reopen($session));
        $this->assertSame('child', file_get_contents($this->target . '/tree/child.txt'));
    }

    public function testStructuralCleanupResumesAfterStagingDirectoryWasRemoved(): void {
        $session = $this->session('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $this->stage_file($session, 'tree/child.txt', 'child');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $session->commit(1);
        $session->commit(1);
        $this->assertSame([], $this->directory_entries($session->get_session_directory() . '/work/files/tree'));

        $this->set_current_installation($session, 'tree', 'directory');
        rmdir($session->get_session_directory() . '/work/files/tree');

        $this->commit_all($this->reopen($session));

        $this->assertSame('child', file_get_contents($this->target . '/tree/child.txt'));
        $this->assertSame([], $this->directory_entries($session->get_session_directory() . '/work/files'));
    }

    public function testRecursiveDeletionUnlinksChildSymlinksWithoutFollowingThem(): void {
        mkdir($this->target . '/outside', 0700, true);
        file_put_contents($this->target . '/outside/sentinel', 'safe');
        mkdir($this->target . '/delete-root', 0700);
        symlink('../outside', $this->target . '/delete-root/link');

        $session = $this->session('ffffffffffffffffffffffffffffffff');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "delete-root\0",
        ]]);
        $this->commit_all($session);

        $this->assertFileDoesNotExist($this->target . '/delete-root');
        $this->assertSame('safe', file_get_contents($this->target . '/outside/sentinel'));
    }

    public function testDeletionBelowAMissingAncestorIsAlreadyComplete(): void {
        $session = $this->session('abcdefabcdefabcdefabcdefabcdefab');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "missing/child\0",
        ]]);

        $this->commit_all($session);

        $this->assertSame('complete', $session->get_status()['phase']);
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testIncompatibleLiveDirectoryIsLeftUntouchedAndTerminal(): void {
        mkdir($this->target . '/conflict', 0700);
        file_put_contents($this->target . '/conflict/sentinel', 'safe');
        $session = $this->session('1234567890abcdef1234567890abcdef');
        $this->stage_file($session, 'conflict', 'new');

        try {
            $this->commit_all($session);
            $this->fail('A staged file replaced an incompatible live directory.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
            $this->assertSame(['absent', 'file', 'symlink'], $exception->get_context()['expected_live_types']);
        }

        $this->assertSame('safe', file_get_contents($this->target . '/conflict/sentinel'));
        $this->assertFileExists($this->target . '/.maintenance');
        try {
            $session->commit(1);
            $this->fail('A terminal session allowed a force retry.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
        }
    }

    public function testCommitRejectsIncompleteFilesAndNonPositiveEntryBudgets(): void {
        $session = $this->session('10101010101010101010101010101010');
        $this->stage($session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('partial.bin'),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'a',
        ]]);
        $this->complete_delete_upload($session);
        try {
            $session->commit(1);
            $this->fail('Commit began with an incomplete staged file.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('work/partial', $exception->getMessage());
        }
        foreach ([0, -1] as $maximum_entries) {
            try {
                $session->commit($maximum_entries);
                $this->fail('Commit accepted a non-positive entry budget.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('greater than zero', $exception->getMessage());
            }
        }
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testOneEntryBudgetNeverAdvancesMoreThanOneDurableUnit(): void {
        $session = $this->session('11112222333344445555666677778888');
        $this->stage_file($session, 'first.txt', 'first');
        $this->stage_file($session, 'second.txt', 'second');
        $this->complete_delete_upload($session);

        do {
            $before = $this->checkpoint_or_null($session);
            $result = $session->commit(1);
            $this->assertSame(1, $result['entries_processed']);
            $after = $this->checkpoint($session);
            if ($before !== null) {
                $work_delta = ( $after['deletions_applied'] - $before['deletions_applied'] )
                    + ( $after['values_applied'] - $before['values_applied'] );
                $this->assertLessThanOrEqual(1, $work_delta);
            }
        } while ($result['send_next_request']);

        $complete = $session->commit(1);
        $this->assertSame(0, $complete['entries_processed']);
        $this->assertFalse($complete['send_next_request']);
    }

    public function testDeletionResumesBeforeAndAfterTheLiveUnlinkBoundary(): void {
        foreach ([false, true] as $index => $already_unlinked) {
            file_put_contents($this->target . '/delete-' . $index, 'old');
            $session = $this->session(sprintf('%032x', 1500 + $index));
            $path = 'delete-' . $index;
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => $path . "\0",
            ]]);
            $this->complete_delete_upload($session);
            $session->commit(1);
            $checkpoint = $this->checkpoint($session);
            $this->assertSame($path, base64_decode($checkpoint['current_deletion_b64'], true));
            if ($already_unlinked) {
                unlink($this->target . '/' . $path);
            }

            $this->commit_all($this->reopen($session));
            $this->assertFileDoesNotExist($this->target . '/' . $path);
            $this->assertSame(strlen($path) + 1, $this->checkpoint($session)['delete_offset']);
        }
    }

    public function testEveryCheckpointFieldAndNestedShapeIsValidatedBeforeMutation(): void {
        $session = $this->session('12121212343434345656565678787878');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $path = $session->get_session_directory() . '/commit.json';
        $valid = $this->checkpoint($session);
        $invalid_checkpoints = [
            array_merge($valid, ['version' => 1]),
            array_merge($valid, ['phase' => 'unknown']),
            array_merge($valid, ['delete_offset' => -1]),
            array_merge($valid, ['delete_offset' => '0']),
            array_merge($valid, ['deletions_applied' => null]),
            array_merge($valid, ['values_applied' => 1.5]),
            array_merge($valid, ['maintenance_token' => 'short']),
            array_merge($valid, ['current_deletion_b64' => '***']),
            array_merge($valid, ['current_deletion_b64' => base64_encode('../unsafe')]),
            array_merge($valid, ['current_installation' => []]),
            array_merge($valid, ['current_installation' => ['path_b64' => '***', 'expected_type' => 'file']]),
            array_merge($valid, ['current_installation' => ['path_b64' => base64_encode('path'), 'expected_type' => 'other']]),
            array_merge($valid, ['traversal_stack' => 'not-a-list']),
            array_merge($valid, ['traversal_stack' => [[]]]),
            array_merge($valid, ['traversal_stack' => [['component_b64' => base64_encode('a/b')]]]),
            array_merge($valid, ['terminal_error' => []]),
            array_merge($valid, ['terminal_error' => ['reason' => 'busy', 'detail' => 'x', 'context' => []]]),
        ];
        foreach ($invalid_checkpoints as $index => $checkpoint) {
            file_put_contents($path, json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            try {
                $session->get_status();
                $this->fail('Malformed checkpoint case ' . $index . ' was accepted.');
            } catch (Throwable $exception) {
                $this->assertInstanceOf(
                    Site_Export_Staged_Apply_Exception::class,
                    $exception,
                    'Checkpoint case ' . $index . ' threw ' . get_class($exception) . ': ' . $exception->getMessage()
                );
                $this->assertSame('invalid_session_state', $exception->get_error_code());
            }
            $this->assertFileDoesNotExist($this->target . '/unexpected');
        }

        file_put_contents($path, str_repeat(' ', 1048577));
        try {
            $session->get_status();
            $this->fail('Oversized commit checkpoint was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        file_put_contents($path, json_encode($valid, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->commit_all($session);
    }

    public function testMissingCheckpointAfterCommitStartedIsRejectedInsteadOfRestarted(): void {
        file_put_contents($this->target . '/delete-me', 'old');
        $session = $this->session('13131313131313131313131313131313');
        $this->stage($session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "delete-me\0",
        ]]);
        $this->complete_delete_upload($session);
        $session->commit(1);
        unlink($session->get_session_directory() . '/commit.json');

        try {
            $session->commit(1);
            $this->fail('A committing session silently recreated its missing checkpoint.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertFileExists($this->target . '/delete-me');
    }

    public function testMissingCheckpointAfterCompletedCommitIsRejectedByEveryPublicOperation(): void {
        foreach (['commit', 'status', 'upload', 'discard'] as $index => $operation) {
            $path = 'completed-delete-' . $operation;
            file_put_contents($this->target . '/' . $path, 'old');
            $session = $this->session(sprintf('%032x', 2200 + $index));
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => $path . "\0",
            ]]);
            $this->commit_all($session);
            $this->assertFileDoesNotExist($this->target . '/' . $path);
            file_put_contents($this->target . '/' . $path, 'replacement');
            unlink($session->get_session_directory() . '/commit.json');

            $observed_exception = null;
            $upload_input = null;
            $upload_accepted = false;
            try {
                if ($operation === 'commit') {
                    $session->commit(16);
                } elseif ($operation === 'status') {
                    $session->get_status();
                } elseif ($operation === 'upload') {
                    $upload_input = fopen('php://temp', 'w+b');
                    $this->assertIsResource($upload_input);
                    $session->accept_upload($upload_input, new Site_Export_Multipart_Processor('missing-checkpoint'));
                    $upload_accepted = true;
                } else {
                    $session->discard_workspace();
                }
            } catch (Throwable $exception) {
                $observed_exception = $exception;
            } finally {
                if ($upload_accepted) {
                    $session->finish_upload();
                }
                if (is_resource($upload_input)) {
                    fclose($upload_input);
                }
            }

            $this->assertInstanceOf(
                Site_Export_Staged_Apply_Exception::class,
                $observed_exception,
                'Missing completed checkpoint was accepted by ' . $operation . '.'
            );
            $this->assertSame('invalid_session_state', $observed_exception->get_error_code());
            $this->assertSame('replacement', file_get_contents($this->target . '/' . $path));
            $this->assertFileDoesNotExist($this->target . '/.maintenance');
            $this->assertFileDoesNotExist($this->storage . '/apply-sessions/target.active');
            $this->assertFileDoesNotExist($session->get_session_directory() . '/commit.json');
            $this->assertDirectoryExists($session->get_session_directory());
        }
    }

    public function testCommitStartedIsPersistedBeforeTargetActivityAndRepairsThePublicationWindow(): void {
        $initial = $this->session('22222222333333334444444455555555');
        $initial_metadata = $this->session_metadata($initial);
        $this->assertSame([
            'version',
            'session_id',
            'target_root_b64',
            'protected_paths_b64',
            'delete_upload_complete',
            'commit_started',
        ], array_keys($initial_metadata));
        $this->assertSame(3, $initial_metadata['version']);
        $this->assertFalse($initial_metadata['commit_started']);
        $this->complete_delete_upload($initial);

        $active_path = $this->storage . '/apply-sessions/target.active';
        mkdir($active_path);
        try {
            $initial->commit(1);
            $this->fail('The malformed target coordinator was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertFileExists($initial->get_session_directory() . '/commit.json');
        $this->assertTrue($this->session_metadata($initial)['commit_started']);
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
        $this->assertFileDoesNotExist($initial->get_session_directory() . '/work/maintenance.php');
        rmdir($active_path);
        $this->commit_all($initial);

        $publication_window = $this->session('22222222333333334444444466666666');
        $this->complete_delete_upload($publication_window);
        $this->write_checkpoint($publication_window, $this->initial_checkpoint());
        $this->assertFalse($this->session_metadata($publication_window)['commit_started']);
        mkdir($active_path);
        try {
            $publication_window->commit(1);
            $this->fail('The publication-window checkpoint reached a malformed coordinator.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertTrue($this->session_metadata($publication_window)['commit_started']);
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
        rmdir($active_path);
        $this->commit_all($publication_window);
    }

    public function testDeleteOffsetMustStayOnDurableRecordBoundariesBeforeTargetClaim(): void {
        $mid_record = $this->session('23232323343434344545454556565656');
        file_put_contents($this->target . '/irst', 'must survive');
        $this->stage($mid_record, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_delete_upload($mid_record);
        $this->assert_invalid_checkpoint_before_target_claim(
            $mid_record,
            array_merge($this->initial_checkpoint(), ['delete_offset' => 1]),
            ['irst' => 'must survive']
        );

        $beyond_eof = $this->session('24242424353535354646464657575757');
        file_put_contents($this->target . '/beyond-sentinel', 'must survive');
        $this->stage($beyond_eof, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_delete_upload($beyond_eof);
        $this->assert_invalid_checkpoint_before_target_claim(
            $beyond_eof,
            array_merge($this->initial_checkpoint(), ['delete_offset' => 7]),
            ['beyond-sentinel' => 'must survive']
        );

        $current_at_eof = $this->session('25252525363636364747474758585858');
        file_put_contents($this->target . '/first', 'must survive');
        $this->stage($current_at_eof, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_delete_upload($current_at_eof);
        $this->assert_invalid_checkpoint_before_target_claim(
            $current_at_eof,
            array_merge($this->initial_checkpoint(), [
                'delete_offset' => 6,
                'current_deletion_b64' => base64_encode('first'),
            ]),
            ['first' => 'must survive']
        );
    }

    public function testCurrentDeletionMustMatchTheDurableRecordBeforeTargetClaim(): void {
        $session = $this->session('26262626373737374848484859595959');
        file_put_contents($this->target . '/unrelated', 'must survive');
        $this->stage($session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_delete_upload($session);

        $this->assert_invalid_checkpoint_before_target_claim(
            $session,
            array_merge($this->initial_checkpoint(), ['current_deletion_b64' => base64_encode('unrelated')]),
            ['unrelated' => 'must survive']
        );
    }

    public function testDeleteOffsetAndPhaseFieldsMustAgreeBeforeTargetClaim(): void {
        $cases = [
            'applying before delete EOF' => ['phase' => 'applying'],
            'complete before delete EOF' => ['phase' => 'complete'],
            'deleting with installation' => [
                'current_installation' => ['path_b64' => base64_encode('pending'), 'expected_type' => 'file'],
            ],
            'deleting with traversal' => [
                'traversal_stack' => [['component_b64' => base64_encode('pending')]],
            ],
            'complete with installation' => [
                'phase' => 'complete',
                'delete_offset' => 6,
                'current_installation' => ['path_b64' => base64_encode('pending'), 'expected_type' => 'file'],
            ],
            'complete with traversal' => [
                'phase' => 'complete',
                'delete_offset' => 6,
                'traversal_stack' => [['component_b64' => base64_encode('pending')]],
            ],
            'associative traversal stack' => [
                'phase' => 'applying',
                'delete_offset' => 6,
                'traversal_stack' => ['frame' => ['component_b64' => base64_encode('pending')]],
            ],
        ];
        $case_index = 0;
        foreach ($cases as $description => $case) {
            $session = $this->session(sprintf('%032x', 2300 + $case_index));
            $sentinel = 'phase-sentinel-' . $case_index;
            file_put_contents($this->target . '/' . $sentinel, 'must survive');
            $this->stage($session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => "first\0",
            ]]);
            $this->complete_delete_upload($session);
            $this->assert_invalid_checkpoint_before_target_claim(
                $session,
                array_merge($this->initial_checkpoint(), $case),
                [$sentinel => 'must survive'],
                $description
            );
            ++$case_index;
        }
    }

    public function testCurrentDeletionBindingAcceptsArbitraryNonUtf8PathBytes(): void {
        $path = "non-utf8-\xff";
        $session = $this->session('27272727383838384949494960606060');
        $this->stage($session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $path . "\0",
        ]]);
        $this->complete_delete_upload($session);
        $this->write_checkpoint($session, array_merge($this->initial_checkpoint(), [
            'current_deletion_b64' => base64_encode($path),
        ]));

        $this->commit_all($session);

        $this->assertSame(strlen($path) + 1, $this->checkpoint($session)['delete_offset']);
        $this->assertSame(1, $this->checkpoint($session)['deletions_applied']);
    }

    public function testRetryableIoDoesNotAdvanceAndCanResume(): void {
        $session = $this->session('14141414141414141414141414141414');
        $this->stage_file($session, 'installed.txt', 'new');
        $this->complete_delete_upload($session);
        chmod($this->target, 0500);
        try {
            try {
                $session->commit(1);
                $this->fail('Commit mutated an unwritable target.');
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                $this->assertSame('retryable_io_error', $exception->get_error_code());
            }
            $checkpoint = $this->checkpoint($session);
            $this->assertSame('deleting', $checkpoint['phase']);
            $this->assertSame(0, $checkpoint['delete_offset']);
            $this->assertSame(0, $checkpoint['values_applied']);
        } finally {
            chmod($this->target, 0700);
        }
        $this->commit_all($this->reopen($session));
        $this->assertSame('new', file_get_contents($this->target . '/installed.txt'));
    }

    public function testTerminalFailureIsDurableAcrossReopen(): void {
        mkdir($this->target . '/conflict');
        $session = $this->session('15151515151515151515151515151515');
        $this->stage_file($session, 'conflict', 'new');
        try {
            $this->commit_all($session);
            $this->fail('Incompatible destination was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $first) {
            $this->assertSame('live_tree_changed', $first->get_error_code());
            try {
                $this->reopen($session)->commit(1);
                $this->fail('Reopened terminal session retried live mutation.');
            } catch (Site_Export_Staged_Apply_Exception $second) {
                $this->assertSame($first->get_error_code(), $second->get_error_code());
                $this->assertSame($first->getMessage(), $second->getMessage());
                $this->assertSame($first->get_context(), $second->get_context());
            }
        }
    }

    public function testUnsupportedLiveAndStagedNodesAreRejectedWithoutMutation(): void {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('FIFO creation is unavailable.');
        }
        posix_mkfifo($this->target . '/live-fifo', 0600);
        $delete_session = $this->session('16161616161616161616161616161616');
        $this->stage($delete_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "live-fifo\0",
        ]]);
        try {
            $this->commit_all($delete_session);
            $this->fail('Unsupported live node was deleted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('live_tree_changed', $exception->get_error_code());
        }
        $this->assertFileExists($this->target . '/live-fifo');
        unlink($this->target . '/.maintenance');
        unlink($this->storage . '/apply-sessions/target.active');

        $install_session = $this->session('17171717171717171717171717171717');
        posix_mkfifo($install_session->get_session_directory() . '/work/files/staged-fifo', 0600);
        try {
            $this->commit_all($install_session);
            $this->fail('Unsupported staged node was installed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->target . '/staged-fifo');
    }

    public function testCrossDeviceSessionCreationIsRejectedAndCleanedUpWhenAvailable(): void {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            $this->markTestSkipped('No writable secondary filesystem is available.');
        }
        $target_device = stat($this->target)['dev'];
        $secondary_device = stat('/dev/shm')['dev'];
        if ($target_device === $secondary_device) {
            $this->markTestSkipped('/dev/shm is not a distinct filesystem.');
        }
        $secondary_target = '/dev/shm/reprint-staged-apply-' . bin2hex(random_bytes(8));
        mkdir($secondary_target, 0700);
        $session_id = '18181818181818181818181818181818';
        try {
            Site_Export_Staged_Apply_Session::create($this->storage, $secondary_target, [], $session_id);
            $this->fail('Cross-device session creation was accepted.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('cross_device_filesystem', $exception->get_error_code());
            $this->assertDirectoryDoesNotExist($this->storage . '/apply-sessions/' . $session_id);
        } finally {
            rmdir($secondary_target);
        }
    }

    public function testTargetRootReplacementStopsCommitBeforeLiveMutation(): void {
        $session = $this->session('19191919191919191919191919191919');
        $this->stage_file($session, 'pending.txt', 'new');
        $this->complete_delete_upload($session);
        rmdir($this->target);
        file_put_contents($this->target, 'not a directory');
        try {
            $session->commit(1);
            $this->fail('Commit used a replaced target root.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $this->assertSame('not a directory', file_get_contents($this->target));
    }

    public function testMissingCoordinatorAndMaintenanceMarkersAreRecreatedOnResume(): void {
        foreach (['live-marker', 'private-marker', 'active-coordinator', 'target-lock'] as $index => $missing_path) {
            $session = $this->session(sprintf('%032x', 1600 + $index));
            $this->stage_file($session, 'value-' . $index . '.txt', 'new');
            $this->complete_delete_upload($session);
            $session->commit(1);
            if ($missing_path === 'live-marker') {
                unlink($this->target . '/.maintenance');
            } elseif ($missing_path === 'private-marker') {
                unlink($session->get_session_directory() . '/work/maintenance.php');
            } elseif ($missing_path === 'active-coordinator') {
                unlink($this->storage . '/apply-sessions/target.active');
            } else {
                unlink($this->storage . '/apply-sessions/target.lock');
            }

            $this->commit_all($this->reopen($session));
            $this->assertSame('new', file_get_contents($this->target . '/value-' . $index . '.txt'));
            $this->assertFileDoesNotExist($this->target . '/.maintenance');
            $this->assertFileDoesNotExist($this->storage . '/apply-sessions/target.active');
        }
    }

    public function testMissingSessionLockAfterCommitReportsCorruptionWithoutMutation(): void {
        $session = $this->session('20202020202020202020202020202020');
        $this->stage_file($session, 'pending.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        unlink($session->get_session_directory() . '/lock');

        try {
            $this->reopen($session)->commit(1);
            $this->fail('A session resumed after its lock disappeared.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
            $this->assertStringContainsString('lock', strtolower($exception->getMessage()));
        }
        $this->assertFileDoesNotExist($this->target . '/pending.txt');
    }

    public function testForeignCoordinatorReplacementStopsResumeWithoutInstalling(): void {
        $session = $this->session('21212121212121212121212121212121');
        $this->stage_file($session, 'pending.txt', 'new');
        $this->complete_delete_upload($session);
        $session->commit(1);
        $foreign_owner = str_repeat('f', 32) . "\n";
        file_put_contents($this->storage . '/apply-sessions/target.active', $foreign_owner);

        try {
            $this->reopen($session)->commit(1);
            $this->fail('A session ignored replaced target ownership.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('busy', $exception->get_error_code());
        }
        $this->assertSame($foreign_owner, file_get_contents($this->storage . '/apply-sessions/target.active'));
        $this->assertFileDoesNotExist($this->target . '/pending.txt');
    }

    public function testTargetReleaseFailureAfterCompletionIsRetriedWithoutReplayingWork(): void {
        $deleted_path = 'release-deleted.txt';
        file_put_contents($this->target . '/' . $deleted_path, 'old');
        $session = $this->session('28282828393939395050505061616161');
        $parts = [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $deleted_path . "\0",
        ]];
        for ($index = 0; $index < 400; ++$index) {
            $path = 'release-entry-' . $index;
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
        $this->complete_delete_upload($session);
        $session->commit(1);

        $active_path = $this->storage . '/apply-sessions/target.active';
        $maintenance_path = $this->target . '/.maintenance';
        unlink($maintenance_path);
        $script = '$active = base64_decode(' . var_export(base64_encode($active_path), true) . ');'
            . '$maintenance = base64_decode(' . var_export(base64_encode($maintenance_path), true) . ');'
            . '$deadline = microtime(true) + 10;'
            . 'while (!is_file($maintenance) && microtime(true) < $deadline) { usleep(100); }'
            . 'if (!is_file($maintenance) || !unlink($active) || !mkdir($active)) { exit(1); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        try {
            $session->commit(1000);
            $this->fail('Commit reported success after target release failed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($process));
        }
        $this->assertSame('complete', $this->checkpoint($session)['phase']);
        $this->assertDirectoryExists($active_path);

        try {
            $session->commit(1);
            $this->fail('A complete checkpoint hid its still-corrupt target coordinator.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }

        file_put_contents($this->target . '/release-entry-0', 'changed after completion');
        file_put_contents($this->target . '/' . $deleted_path, 'replacement after completion');
        rmdir($active_path);
        file_put_contents($active_path, $session->get_session_id() . "\n");
        $result = $session->commit(1);

        $this->assertSame('complete', $result['phase']);
        $this->assertFalse($result['send_next_request']);
        $this->assertSame(0, $result['entries_processed']);
        $this->assertFileDoesNotExist($active_path);
        $this->assertSame('changed after completion', file_get_contents($this->target . '/release-entry-0'));
        $this->assertSame('replacement after completion', file_get_contents($this->target . '/' . $deleted_path));
    }

    public function testTargetReleaseLeavesAValidForeignOwnerAfterCompletedSession(): void {
        $session = $this->session('29292929404040405151515162626262');
        $this->stage_file($session, 'completed-value', 'new');
        $this->commit_all($session);
        $foreign_owner = str_repeat('e', 32) . "\n";
        $active_path = $this->storage . '/apply-sessions/target.active';
        file_put_contents($active_path, $foreign_owner);

        $result = $session->commit(1);

        $this->assertSame('complete', $result['phase']);
        $this->assertSame($foreign_owner, file_get_contents($active_path));
        $this->assertSame('new', file_get_contents($this->target . '/completed-value'));
    }

    public function testDiscardReleaseFailureAfterRenameIsRetriedBeforeTombstoneCleanup(): void {
        $session = $this->session('30303030414141415252525263636363');
        $active_path = $this->storage . '/apply-sessions/target.active';
        mkdir($active_path);

        try {
            $session->discard_workspace();
            $this->fail('Discard cleaned a tombstone after target release failed.');
        } catch (Site_Export_Staged_Apply_Exception $exception) {
            $this->assertSame('invalid_session_state', $exception->get_error_code());
        }
        $tombstone = $this->storage . '/apply-sessions/.discarding-' . $session->get_session_id();
        $this->assertDirectoryDoesNotExist($session->get_session_directory());
        $this->assertDirectoryExists($tombstone);

        rmdir($active_path);
        file_put_contents($active_path, $session->get_session_id() . "\n");
        $this->assertTrue($session->discard_workspace());

        $this->assertFileDoesNotExist($active_path);
        $this->assertDirectoryDoesNotExist($tombstone);
    }

    private function session(string $id): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage,
            $this->target,
            ['wp-content/plugins/reprint'],
            $id
        );
    }

    private function reopen(Site_Export_Staged_Apply_Session $session): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::open(
            $this->storage,
            $this->target,
            $session->get_session_id(),
            ['wp-content/plugins/reprint']
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
     *     version:2,
     *     phase:'deleting'|'applying'|'complete',
     *     delete_offset:int,
     *     current_deletion_b64:?string,
     *     current_installation:?array{path_b64:string,expected_type:'file'|'directory'|'symlink'},
     *     traversal_stack:list<array{component_b64:string}>,
     *     maintenance_token:string,
     *     deletions_applied:int,
     *     values_applied:int,
     *     terminal_error?:array{reason:string,detail:string,context:array<string,mixed>}
     * }
     */
    private function checkpoint(Site_Export_Staged_Apply_Session $session): array {
        $checkpoint = json_decode(
            (string) file_get_contents($session->get_session_directory() . '/commit.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($checkpoint);
        return $checkpoint;
    }

    /**
     * @return array{
     *     version:2,
     *     phase:'deleting'|'applying'|'complete',
     *     delete_offset:int,
     *     current_deletion_b64:?string,
     *     current_installation:?array{path_b64:string,expected_type:'file'|'directory'|'symlink'},
     *     traversal_stack:list<array{component_b64:string}>,
     *     maintenance_token:string,
     *     deletions_applied:int,
     *     values_applied:int,
     *     terminal_error?:array{reason:string,detail:string,context:array<string,mixed>}
     * }|null
     */
    private function checkpoint_or_null(Site_Export_Staged_Apply_Session $session): ?array {
        $path = $session->get_session_directory() . '/commit.json';
        return is_file($path) ? $this->checkpoint($session) : null;
    }

    private function set_current_installation(Site_Export_Staged_Apply_Session $session, string $path, string $type): void {
        $checkpoint = $this->checkpoint($session);
        $checkpoint['current_installation'] = ['path_b64' => base64_encode($path), 'expected_type' => $type];
        file_put_contents(
            $session->get_session_directory() . '/commit.json',
            json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
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

    /**
     * @return array{
     *     version:2,
     *     phase:'deleting',
     *     delete_offset:0,
     *     current_deletion_b64:null,
     *     current_installation:null,
     *     traversal_stack:list<array{component_b64:string}>,
     *     maintenance_token:string,
     *     deletions_applied:0,
     *     values_applied:0
     * }
     */
    private function initial_checkpoint(): array {
        return [
            'version' => 2,
            'phase' => 'deleting',
            'delete_offset' => 0,
            'current_deletion_b64' => null,
            'current_installation' => null,
            'traversal_stack' => [],
            'maintenance_token' => str_repeat('a', 32),
            'deletions_applied' => 0,
            'values_applied' => 0,
        ];
    }

    /** @param array<string,mixed> $checkpoint */
    private function write_checkpoint(Site_Export_Staged_Apply_Session $session, array $checkpoint): void {
        file_put_contents(
            $session->get_session_directory() . '/commit.json',
            json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string,mixed> $checkpoint Deliberately corrupt checkpoint candidate.
     * @param array<string,string> $preserved_paths Live paths and contents which must not change.
     */
    private function assert_invalid_checkpoint_before_target_claim(
        Site_Export_Staged_Apply_Session $session,
        array $checkpoint,
        array $preserved_paths,
        string $case = ''
    ): void {
        $this->write_checkpoint($session, $checkpoint);
        $checkpoint_path = $session->get_session_directory() . '/commit.json';
        $checkpoint_contents = file_get_contents($checkpoint_path);
        $observed_exception = null;
        try {
            $session->commit(16);
        } catch (Throwable $exception) {
            $observed_exception = $exception;
        }

        $this->assertInstanceOf(
            Site_Export_Staged_Apply_Exception::class,
            $observed_exception,
            'Corrupt commit checkpoint was accepted' . ( $case === '' ? '.' : ' for case ' . $case . '.' )
        );
        $this->assertSame('invalid_session_state', $observed_exception->get_error_code());
        $this->assertSame($checkpoint_contents, file_get_contents($checkpoint_path));
        foreach ($preserved_paths as $path => $contents) {
            $this->assertSame($contents, file_get_contents($this->target . '/' . $path));
        }
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
        $this->assertFileDoesNotExist($session->get_session_directory() . '/work/maintenance.php');
        $this->assertFileDoesNotExist($this->storage . '/apply-sessions/target.active');
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
            $result = $session->commit(2);
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

    /** @return string[] */
    private function directory_entries(string $path): array {
        return array_values(array_diff(scandir($path) ?: [], ['.', '..']));
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
