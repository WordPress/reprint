<?php

use PHPUnit\Framework\TestCase;

final class PushCommitTest extends TestCase {

    private string $root;
    private string $docroot;
    private string $reprint_directory;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/direct-commit-' . bin2hex(random_bytes(8));
        $this->docroot = $this->root . '/docroot';
        $this->reprint_directory = $this->root . '/reprint';
        mkdir($this->docroot, 0700, true);
        mkdir($this->reprint_directory, 0700, true);
    }

    protected function tearDown(): void {
        $this->remove_tree($this->root);
    }

    public function testCommitDeletesBeforeInstallingAndConsumesTheWorkTree(): void {
        mkdir($this->docroot . '/tree', 0700, true);
        file_put_contents($this->docroot . '/tree/old.txt', 'old');

        $push_session = $this->push_session('11111111111111111111111111111111');
        $this->push_parts($push_session, [
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
        $this->complete_work_deletes($push_session);

        $saw_deleted_before_install = false;
        do {
            $result = $push_session->commit(1);
            if (!file_exists($this->docroot . '/tree/old.txt') && !file_exists($this->docroot . '/tree/new.txt')) {
                $saw_deleted_before_install = true;
            }
        } while ($result['send_next_request']);

        $this->assertTrue($saw_deleted_before_install);
        $this->assertSame('new', file_get_contents($this->docroot . '/tree/new.txt'));
        $this->assertSame([], $this->directory_entries($push_session->get_push_directory() . '/work/files'));
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/prepared');
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/backups');
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/commit');
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/work.jsonl');
    }

    public function testCommitProcessesMultipleEntriesInOneCall(): void {
        $push_session = $this->push_session('12121212121212121212121212121212');
        $this->push_file($push_session, 'first.txt', 'one');
        $this->push_file($push_session, 'second.txt', 'two');
        $this->push_file($push_session, 'third.txt', 'three');
        $this->complete_work_deletes($push_session);

        $result = $push_session->commit(16);

        $this->assertSame('complete', $result['phase']);
        $this->assertFalse($result['send_next_request']);
        $this->assertGreaterThan(1, $result['entries_processed']);
        $this->assertSame('one', file_get_contents($this->docroot . '/first.txt'));
        $this->assertSame('two', file_get_contents($this->docroot . '/second.txt'));
        $this->assertSame('three', file_get_contents($this->docroot . '/third.txt'));
    }

    public function testDeleteUploadAcceptsReplayAndContinuesFromItsActualRawSize(): void {
        $push_session = $this->push_session('22222222222222222222222222222222');
        $first = "first\0partial";
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $first,
        ]]);

        $replay_and_continue = $first . "-path\0";
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $replay_and_continue,
        ]]);

        $this->assertSame(
            $replay_and_continue,
            file_get_contents($push_session->get_push_directory() . '/work/deletes')
        );
    }

    public function testDeleteUploadRejectsOffsetGapsAndDifferingReplayBytes(): void {
        $push_session = $this->push_session('33333333333333333333333333333333');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "first\0",
        ]]);

        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '99',
                ],
                'body' => "later\0",
            ]]);
            $this->fail('An offset gap was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame(Site_Export_Push_Session::ERROR_OFFSET_GAP, $exception->get_error_code());
            $this->assertStringContainsString('offset 99', $exception->getMessage());
            $this->assertStringContainsString('6 bytes', $exception->getMessage());
        }

        try {
            $this->push_parts($push_session, [[
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

        $this->assertSame("first\0", file_get_contents($push_session->get_push_directory() . '/work/deletes'));
    }

    public function testDeleteUploadRequiresAnOffsetAndRejectsEmptyRecords(): void {
        $push_session = $this->push_session('00110011001100110011001100110011');
        try {
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list'],
                'body' => "path\0",
            ]]);
            $this->fail('A delete part without X-Delete-Offset was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('x-delete-offset', $exception->getMessage());
        }

        try {
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'delete-list',
                    'X-Delete-Offset' => '0',
                ],
                'body' => "path\0\0",
            ]]);
            $this->fail('An empty delete record was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('empty delete record', $exception->getMessage());
        }
        $this->assertSame('', file_get_contents($push_session->get_push_directory() . '/work/deletes'));
    }

    public function testDeletePathLimitIsEnforcedAcrossRequestsWithoutTruncatingAcceptedBytes(): void {
        $push_session = $this->push_session('00220022002200220022002200220022');
        $prefix = str_repeat('a', 4090);
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $prefix,
        ]]);

        try {
            $this->push_parts($push_session, [[
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

        $this->assertSame($prefix, file_get_contents($push_session->get_push_directory() . '/work/deletes'));
    }

    public function testReopenUsesTheActualDeleteFileSizeAndAcceptsReplayContinuation(): void {
        $push_session = $this->push_session('00330033003300330033003300330033');
        $stored = "first\0part";
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $stored,
        ]]);

        $reopened = $this->reopen($push_session);
        $this->assertSame(strlen($stored), $reopened->get_status()['work_deletes_bytes']);
        $complete = "first\0partial\0";
        $this->push_parts($reopened, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => $complete,
        ]]);

        $this->assertSame($complete, file_get_contents($push_session->get_push_directory() . '/work/deletes'));
    }

    public function testCommitRequiresTheDeleteCompletionDeclaration(): void {
        $push_session = $this->push_session('00440044004400440044004400440044');
        try {
            $push_session->commit(1);
            $this->fail('Commit began without a completed delete upload declaration.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('explicit completed delete upload', $exception->getMessage());
        }

        $this->complete_work_deletes($push_session);
        $this->commit_all($push_session);
    }

    public function testCommitRejectsAnUnterminatedDeleteRecordWithoutTruncatingIt(): void {
        $push_session = $this->push_session('44444444444444444444444444444444');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => 'unfinished',
        ]]);
        $this->complete_work_deletes($push_session);

        try {
            $push_session->commit(1);
            $this->fail('Commit accepted an unterminated delete record.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('end in NUL', $exception->getMessage());
        }

        $this->assertSame('unfinished', file_get_contents($push_session->get_push_directory() . '/work/deletes'));
    }

    public function testModeTransportIsRejected(): void {
        $push_session = $this->push_session('55555555555555555555555555555555');

        try {
            $this->push_parts($push_session, [[
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
            $this->push_parts($push_session, [[
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

    public function testFileAndSymlinkReplaceCompatibleDocumentRootValuesWithoutDeleteRecords(): void {
        file_put_contents($this->docroot . '/file', 'old file');
        file_put_contents($this->docroot . '/outside-sentinel', 'safe');
        symlink('outside-sentinel', $this->docroot . '/link');

        $push_session = $this->push_session('66666666666666666666666666666666');
        $this->push_parts($push_session, [
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
                    'X-Symlink-Target' => base64_encode('new-docroot'),
                ],
                'body' => '',
            ],
        ]);

        $this->commit_all($push_session);

        $this->assertSame('new', file_get_contents($this->docroot . '/file'));
        $this->assertTrue(is_link($this->docroot . '/link'));
        $this->assertSame('new-docroot', readlink($this->docroot . '/link'));
        $this->assertSame('safe', file_get_contents($this->docroot . '/outside-sentinel'));
    }

    public function testExplicitEmptyAndStructuralDirectoriesRemainDistinctPendingValues(): void {
        $push_session = $this->push_session('00550055005500550055005500550055');
        $this->push_parts($push_session, [
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
        $this->commit_all($push_session);

        $this->assertDirectoryExists($this->docroot . '/empty');
        $this->assertSame([], $this->directory_entries($this->docroot . '/empty'));
        $this->assertSame('child', file_get_contents($this->docroot . '/structural/child.txt'));
    }

    public function testExplicitEmptyDirectoryUsesTheDocumentRootProcessUmask(): void {
        $previous_umask = umask(0027);
        try {
            $push_session = $this->push_session('abababababababababababababababab');
            $this->push_parts($push_session, [[
                'headers' => [
                    'X-Chunk-Type' => 'directory',
                    'X-Directory-Path' => base64_encode('empty'),
                ],
                'body' => '',
            ]]);
            $this->commit_all($push_session);

            clearstatcache(true, $this->docroot . '/empty');
            $this->assertSame(0750, fileperms($this->docroot . '/empty') & 0777);
        } finally {
            umask($previous_umask);
        }
    }

    public function testObservedSymlinkAncestorStopsCommitAndLeavesMaintenanceActive(): void {
        mkdir($this->docroot . '/outside', 0700, true);
        file_put_contents($this->docroot . '/outside/sentinel', 'safe');

        $push_session = $this->push_session('77777777777777777777777777777777');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('parent/file.txt'),
                'X-File-Size' => '3',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'new',
        ]]);
        symlink('outside', $this->docroot . '/parent');

        try {
            $this->commit_all($push_session);
            $this->fail('A symlink ancestor was traversed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
            $this->assertSame('parent/file.txt', base64_decode($exception->get_context()['path_b64'], true));
            $this->assertSame('parent', base64_decode($exception->get_context()['conflict_path_b64'], true));
        }

        $this->assertSame('safe', file_get_contents($this->docroot . '/outside/sentinel'));
        $this->assertFileExists($this->docroot . '/.maintenance');
    }

    public function testCommitCheckpointContainsOnlyBoundedBase64PathState(): void {
        $push_session = $this->push_session('88888888888888888888888888888888');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode("tree/line\nbreak"),
                'X-File-Size' => '1',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'x',
        ]]);
        $this->complete_work_deletes($push_session);

        $push_session->commit(1);
        $commit_state = json_decode((string) file_get_contents($push_session->get_push_directory() . '/commit.json'), true);

        $this->assertIsArray($commit_state);
        $this->assertSame(3, $commit_state['version']);
        $this->assertContains($commit_state['phase'], ['deleting_files', 'installing_files', 'complete']);
        $this->assertArrayHasKey('work_deletes_byte_offset', $commit_state);
        $this->assertArrayHasKey('current_delete_path', $commit_state);
        $this->assertArrayHasKey('current_work_files_descendant', $commit_state);
        $this->assertArrayHasKey('commit_cursor', $commit_state);
        $this->assertArrayNotHasKey('actions_count', $commit_state);
        $this->assertArrayNotHasKey('prepare_offset', $commit_state);
        $this->assertArrayNotHasKey('transition', $commit_state);
        $commit_state_keys = array_keys($commit_state);
        sort($commit_state_keys, SORT_STRING);
        $this->assertSame([
            'commit_cursor',
            'current_delete_path',
            'current_work_files_descendant',
            'deleted_files',
            'installed_files',
            'phase',
            'version',
            'work_deletes_byte_offset',
        ], $commit_state_keys);
        $this->assertStringNotContainsString("line\nbreak", (string) file_get_contents($push_session->get_push_directory() . '/commit.json'));
    }

    public function testDeepCommitCursorGrowsWithPathLengthRatherThanEveryPathPrefix(): void {
        $push_session = $this->push_session('89898989898989898989898989898989');
        $directory_depth = 300;
        $path = implode('/', array_fill(0, $directory_depth, 'd')) . '/leaf.txt';
        $this->push_file($push_session, $path, 'value');
        $this->complete_work_deletes($push_session);

        $push_session->commit($directory_depth + 1);
        $commit_state_path = $push_session->get_push_directory() . '/commit.json';
        $commit_state = $this->commit_state($push_session);

        $this->assertCount($directory_depth, $commit_state['commit_cursor']);
        $this->assertLessThan(32768, filesize($commit_state_path));
        foreach ($commit_state['commit_cursor'] as $frame) {
            $this->assertSame(['component_b64'], array_keys($frame));
            $this->assertSame('d', base64_decode($frame['component_b64'], true));
        }

        $this->commit_all($this->reopen($push_session));
        $this->assertSame('value', file_get_contents($this->docroot . '/' . $path));
    }

    public function testInterruptedInstallingFilesWithWorkEntryPresentRemainsPending(): void {
        $push_session = $this->push_session('99999999999999999999999999999999');
        $this->push_file($push_session, 'pending.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $this->set_current_work_files_descendant($push_session, 'pending.txt', 'file');

        $reopened = $this->reopen($push_session);
        $this->commit_all($reopened);

        $this->assertSame('new', file_get_contents($this->docroot . '/pending.txt'));
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/files/pending.txt');
    }

    public function testInterruptedInstallingFilesWithExpectedDocumentRootTypeCompletes(): void {
        $push_session = $this->push_session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->push_file($push_session, 'installed.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $this->set_current_work_files_descendant($push_session, 'installed.txt', 'file');
        rename(
            $push_session->get_push_directory() . '/work/files/installed.txt',
            $this->docroot . '/installed.txt'
        );

        $this->commit_all($this->reopen($push_session));

        $this->assertSame('new', file_get_contents($this->docroot . '/installed.txt'));
    }

    public function testInterruptedInstallingFilesRejectsASymlinkAncestorBeforeInspectingTheDestination(): void {
        mkdir($this->docroot . '/outside', 0700, true);
        file_put_contents($this->docroot . '/outside/installed.txt', 'outside');
        $push_session = $this->push_session('abababababababababababababababab');
        $this->push_file($push_session, 'parent/installed.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $this->set_current_work_files_descendant($push_session, 'parent/installed.txt', 'file');
        unlink($push_session->get_push_directory() . '/work/files/parent/installed.txt');
        symlink('outside', $this->docroot . '/parent');

        try {
            $this->reopen($push_session)->commit(1);
            $this->fail('Interrupted installing_files followed a symlink ancestor.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
            $this->assertSame('parent/installed.txt', base64_decode($exception->get_context()['path_b64'], true));
            $this->assertSame('parent', base64_decode($exception->get_context()['conflict_path_b64'], true));
        }

        $this->assertSame('outside', file_get_contents($this->docroot . '/outside/installed.txt'));
        $this->assertFileExists($this->docroot . '/.maintenance');
    }

    public function testInterruptedInstallingFilesWithBothEntriesAbsentRecordsANonRecoverableCommitFailure(): void {
        $push_session = $this->push_session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->push_file($push_session, 'missing.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $this->set_current_work_files_descendant($push_session, 'missing.txt', 'file');
        unlink($push_session->get_push_directory() . '/work/files/missing.txt');

        try {
            $this->reopen($push_session)->commit(1);
            $this->fail('Missing work and docroot entries were treated as installed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
            $this->assertSame('absent', $exception->get_context()['observed_docroot_identity']['type']);
        }

        $this->assertFileExists($this->docroot . '/.maintenance');
    }

    public function testInterruptedInstallingFilesWithIncompatibleDocumentRootTypeRecordsANonRecoverableCommitFailure(): void {
        $push_session = $this->push_session('cccccccccccccccccccccccccccccccc');
        $this->push_file($push_session, 'conflict', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $this->set_current_work_files_descendant($push_session, 'conflict', 'file');
        unlink($push_session->get_push_directory() . '/work/files/conflict');
        mkdir($this->docroot . '/conflict');

        try {
            $this->reopen($push_session)->commit(1);
            $this->fail('An incompatible docroot directory completed a file installing_files.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
            $this->assertSame('directory', $exception->get_context()['observed_docroot_identity']['type']);
        }

        $this->assertDirectoryExists($this->docroot . '/conflict');
        $this->assertFileExists($this->docroot . '/.maintenance');
    }

    public function testStructuralDirectoryResumesAfterDocumentRootCreation(): void {
        $push_session = $this->push_session('dddddddddddddddddddddddddddddddd');
        $this->push_file($push_session, 'tree/child.txt', 'child');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $push_session->commit(1);

        $commit_state = $this->commit_state($push_session);
        $this->assertSame('tree', base64_decode($commit_state['commit_cursor'][0]['component_b64'], true));
        $this->assertDirectoryExists($this->docroot . '/tree');

        $this->commit_all($this->reopen($push_session));
        $this->assertSame('child', file_get_contents($this->docroot . '/tree/child.txt'));
    }

    public function testStructuralCleanupResumesAfterWorkDirectoryWasRemoved(): void {
        $push_session = $this->push_session('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $this->push_file($push_session, 'tree/child.txt', 'child');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $push_session->commit(1);
        $push_session->commit(1);
        $this->assertSame([], $this->directory_entries($push_session->get_push_directory() . '/work/files/tree'));

        $this->set_current_work_files_descendant($push_session, 'tree', 'directory');
        rmdir($push_session->get_push_directory() . '/work/files/tree');

        $this->commit_all($this->reopen($push_session));

        $this->assertSame('child', file_get_contents($this->docroot . '/tree/child.txt'));
        $this->assertSame([], $this->directory_entries($push_session->get_push_directory() . '/work/files'));
    }

    public function testRecursiveDeleteUnlinksChildSymlinksWithoutFollowingThem(): void {
        mkdir($this->docroot . '/outside', 0700, true);
        file_put_contents($this->docroot . '/outside/sentinel', 'safe');
        mkdir($this->docroot . '/delete-root', 0700);
        symlink('../outside', $this->docroot . '/delete-root/link');

        $push_session = $this->push_session('ffffffffffffffffffffffffffffffff');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "delete-root\0",
        ]]);
        $this->commit_all($push_session);

        $this->assertFileDoesNotExist($this->docroot . '/delete-root');
        $this->assertSame('safe', file_get_contents($this->docroot . '/outside/sentinel'));
    }

    public function testDeleteBelowAMissingAncestorIsAlreadyComplete(): void {
        $push_session = $this->push_session('abcdefabcdefabcdefabcdefabcdefab');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'delete-list',
                'X-Delete-Offset' => '0',
            ],
            'body' => "missing/child\0",
        ]]);

        $this->commit_all($push_session);

        $this->assertSame('complete', $push_session->get_status()['phase']);
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
    }

    public function testIncompatibleDocumentRootDirectoryIsLeftUntouchedAndRecordsANonRecoverableCommitFailure(): void {
        mkdir($this->docroot . '/conflict', 0700);
        file_put_contents($this->docroot . '/conflict/sentinel', 'safe');
        $push_session = $this->push_session('1234567890abcdef1234567890abcdef');
        $this->push_file($push_session, 'conflict', 'new');

        try {
            $this->commit_all($push_session);
            $this->fail('A work file replaced an incompatible docroot directory.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
            $this->assertSame(['absent', 'file', 'symlink'], $exception->get_context()['expected_docroot_types']);
        }

        $this->assertSame('safe', file_get_contents($this->docroot . '/conflict/sentinel'));
        $this->assertFileExists($this->docroot . '/.maintenance');
        try {
            $push_session->commit(1);
            $this->fail('A non-recoverable commit failure allowed a forced retry.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
        }
    }

    public function testCommitRejectsIncompleteFilesAndNonPositiveEntryBudgets(): void {
        $push_session = $this->push_session('10101010101010101010101010101010');
        $this->push_parts($push_session, [[
            'headers' => [
                'X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('partial.bin'),
                'X-File-Size' => '2',
                'X-Chunk-Offset' => '0',
            ],
            'body' => 'a',
        ]]);
        $this->complete_work_deletes($push_session);
        try {
            $push_session->commit(1);
            $this->fail('Commit began with an incomplete work file.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('work/partial', $exception->getMessage());
        }
        foreach ([0, -1] as $maximum_entries) {
            try {
                $push_session->commit($maximum_entries);
                $this->fail('Commit accepted a non-positive entry budget.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('greater than zero', $exception->getMessage());
            }
        }
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
    }

    public function testOneEntryBudgetNeverAdvancesMoreThanOneDurableUnit(): void {
        $push_session = $this->push_session('11112222333344445555666677778888');
        $this->push_file($push_session, 'first.txt', 'first');
        $this->push_file($push_session, 'second.txt', 'second');
        $this->complete_work_deletes($push_session);

        do {
            $before = $this->commit_state_or_null($push_session);
            $result = $push_session->commit(1);
            $this->assertSame(1, $result['entries_processed']);
            $after = $this->commit_state($push_session);
            if ($before !== null) {
                $work_delta = ( $after['deleted_files'] - $before['deleted_files'] )
                    + ( $after['installed_files'] - $before['installed_files'] );
                $this->assertLessThanOrEqual(1, $work_delta);
            }
        } while ($result['send_next_request']);

        $complete = $push_session->commit(1);
        $this->assertSame(0, $complete['entries_processed']);
        $this->assertFalse($complete['send_next_request']);
    }

    public function testDeleteResumesBeforeAndAfterTheDocumentRootUnlinkBoundary(): void {
        foreach ([false, true] as $index => $already_unlinked) {
            file_put_contents($this->docroot . '/delete-' . $index, 'old');
            $push_session = $this->push_session(sprintf('%032x', 1500 + $index));
            $path = 'delete-' . $index;
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => $path . "\0",
            ]]);
            $this->complete_work_deletes($push_session);
            $push_session->commit(1);
            $commit_state = $this->commit_state($push_session);
            $this->assertSame($path, base64_decode($commit_state['current_delete_path'], true));
            if ($already_unlinked) {
                unlink($this->docroot . '/' . $path);
            }

            $this->commit_all($this->reopen($push_session));
            $this->assertFileDoesNotExist($this->docroot . '/' . $path);
            $this->assertSame(strlen($path) + 1, $this->commit_state($push_session)['work_deletes_byte_offset']);
        }
    }

    public function testEveryCheckpointFieldAndNestedShapeIsValidatedBeforeMutation(): void {
        $push_session = $this->push_session('12121212343434345656565678787878');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $path = $push_session->get_push_directory() . '/commit.json';
        $valid = $this->commit_state($push_session);
        $corrupted_commit_states = [
            array_merge($valid, ['version' => 1]),
            array_merge($valid, ['phase' => 'unknown']),
            array_merge($valid, ['work_deletes_byte_offset' => -1]),
            array_merge($valid, ['work_deletes_byte_offset' => '0']),
            array_merge($valid, ['deleted_files' => null]),
            array_merge($valid, ['installed_files' => 1.5]),
            array_merge($valid, ['current_delete_path' => '***']),
            array_merge($valid, ['current_delete_path' => base64_encode('../unsafe')]),
            array_merge($valid, ['current_work_files_descendant' => []]),
            array_merge($valid, ['current_work_files_descendant' => ['path_b64' => '***', 'expected_type' => 'file']]),
            array_merge($valid, ['current_work_files_descendant' => ['path_b64' => base64_encode('path'), 'expected_type' => 'other']]),
            array_merge($valid, ['commit_cursor' => 'not-a-list']),
            array_merge($valid, ['commit_cursor' => [[]]]),
            array_merge($valid, ['commit_cursor' => [['component_b64' => base64_encode('a/b')]]]),
            array_merge($valid, ['non_recoverable_commit_failure' => []]),
            array_merge($valid, ['non_recoverable_commit_failure' => ['reason' => 'lock_acquisition_failure', 'detail' => 'x', 'context' => []]]),
        ];
        foreach ($corrupted_commit_states as $index => $commit_state) {
            file_put_contents($path, json_encode($commit_state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            try {
                $push_session->get_status();
                $this->fail('Malformed checkpoint case ' . $index . ' was accepted.');
            } catch (Throwable $exception) {
                $this->assertInstanceOf(
                    Site_Export_Push_Exception::class,
                    $exception,
                    'Checkpoint case ' . $index . ' threw ' . get_class($exception) . ': ' . $exception->getMessage()
                );
                $this->assertSame('corrupted_push_state', $exception->get_error_code());
            }
            $this->assertFileDoesNotExist($this->docroot . '/unexpected');
        }

        file_put_contents($path, str_repeat(' ', 1048577));
        try {
            $push_session->get_status();
            $this->fail('Oversized commit checkpoint was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        file_put_contents($path, json_encode($valid, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->commit_all($push_session);
    }

    public function testMissingCheckpointAfterCommitStartedIsRejectedInsteadOfRestarted(): void {
        file_put_contents($this->docroot . '/delete-me', 'old');
        $push_session = $this->push_session('13131313131313131313131313131313');
        $this->push_parts($push_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "delete-me\0",
        ]]);
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        unlink($push_session->get_push_directory() . '/commit.json');

        try {
            $push_session->commit(1);
            $this->fail('A committing session silently recreated its missing checkpoint.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertFileExists($this->docroot . '/delete-me');
    }

    public function testMissingCheckpointAfterCompletedCommitIsRejectedByEveryPublicOperation(): void {
        foreach (['commit', 'status', 'upload', 'remove'] as $index => $operation) {
            $path = 'completed-delete-' . $operation;
            file_put_contents($this->docroot . '/' . $path, 'old');
            $push_session = $this->push_session(sprintf('%032x', 2200 + $index));
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => $path . "\0",
            ]]);
            $this->commit_all($push_session);
            $this->assertFileDoesNotExist($this->docroot . '/' . $path);
            file_put_contents($this->docroot . '/' . $path, 'replacement');
            unlink($push_session->get_push_directory() . '/commit.json');

            $observed_exception = null;
            $upload_input = null;
            $upload_accepted = false;
            try {
                if ($operation === 'commit') {
                    $push_session->commit(16);
                } elseif ($operation === 'status') {
                    $push_session->get_status();
                } elseif ($operation === 'upload') {
                    $upload_input = fopen('php://temp', 'w+b');
                    $this->assertIsResource($upload_input);
                    $push_session->accept_upload($upload_input, new Site_Export_Multipart_Processor('missing-checkpoint'));
                    $upload_accepted = true;
                } else {
                    $push_session->remove_push_directory();
                }
            } catch (Throwable $exception) {
                $observed_exception = $exception;
            } finally {
                if ($upload_accepted) {
                    $push_session->finish_upload();
                }
                if (is_resource($upload_input)) {
                    fclose($upload_input);
                }
            }

            $this->assertInstanceOf(
                Site_Export_Push_Exception::class,
                $observed_exception,
                'Missing completed checkpoint was accepted by ' . $operation . '.'
            );
            $this->assertSame('corrupted_push_state', $observed_exception->get_error_code());
            $this->assertSame('replacement', file_get_contents($this->docroot . '/' . $path));
            $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
            $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
            $this->assertFileDoesNotExist($push_session->get_push_directory() . '/commit.json');
            $this->assertDirectoryExists($push_session->get_push_directory());
        }
    }

    public function testCommitStartedIsPersistedBeforeDocumentRootActivityAndRepairsThePublicationWindow(): void {
        $initial = $this->push_session('22222222333333334444444455555555');
        $initial_metadata = $this->push_metadata($initial);
        $this->assertSame([
            'version',
            'push_session_id',
            'docroot_b64',
            'excluded_paths_b64',
            'work_deletes_complete',
            'commit_started',
        ], array_keys($initial_metadata));
        $this->assertSame(4, $initial_metadata['version']);
        $this->assertFalse($initial_metadata['commit_started']);
        $this->complete_work_deletes($initial);

        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        mkdir($active_path);
        try {
            $initial->commit(1);
            $this->fail('The malformed commit-state owner was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertFileExists($initial->get_push_directory() . '/commit.json');
        $this->assertTrue($this->push_metadata($initial)['commit_started']);
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        $this->assertFileDoesNotExist($initial->get_push_directory() . '/work/maintenance.php');
        rmdir($active_path);
        $this->commit_all($initial);

        $publication_window = $this->push_session('22222222333333334444444466666666');
        $this->complete_work_deletes($publication_window);
        $this->write_commit_state($publication_window, $this->initial_commit_state());
        $this->assertFalse($this->push_metadata($publication_window)['commit_started']);
        mkdir($active_path);
        try {
            $publication_window->commit(1);
            $this->fail('The publication-window checkpoint reached a malformed commit-state owner.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertTrue($this->push_metadata($publication_window)['commit_started']);
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        rmdir($active_path);
        $this->commit_all($publication_window);
    }

    public function testWorkDeletesByteOffsetMustStayOnDurableRecordBoundariesBeforeDocumentRootClaim(): void {
        $mid_record = $this->push_session('23232323343434344545454556565656');
        file_put_contents($this->docroot . '/irst', 'must survive');
        $this->push_parts($mid_record, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_work_deletes($mid_record);
        $this->assert_corrupted_commit_state_before_document_root_claim(
            $mid_record,
            array_merge($this->initial_commit_state(), ['work_deletes_byte_offset' => 1]),
            ['irst' => 'must survive']
        );

        $beyond_eof = $this->push_session('24242424353535354646464657575757');
        file_put_contents($this->docroot . '/beyond-sentinel', 'must survive');
        $this->push_parts($beyond_eof, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_work_deletes($beyond_eof);
        $this->assert_corrupted_commit_state_before_document_root_claim(
            $beyond_eof,
            array_merge($this->initial_commit_state(), ['work_deletes_byte_offset' => 7]),
            ['beyond-sentinel' => 'must survive']
        );

        $current_at_eof = $this->push_session('25252525363636364747474758585858');
        file_put_contents($this->docroot . '/first', 'must survive');
        $this->push_parts($current_at_eof, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_work_deletes($current_at_eof);
        $this->assert_corrupted_commit_state_before_document_root_claim(
            $current_at_eof,
            array_merge($this->initial_commit_state(), [
                'work_deletes_byte_offset' => 6,
                'current_delete_path' => base64_encode('first'),
            ]),
            ['first' => 'must survive']
        );
    }

    public function testCurrentDeletePathMustMatchTheDurableRecordBeforeDocumentRootClaim(): void {
        $push_session = $this->push_session('26262626373737374848484859595959');
        file_put_contents($this->docroot . '/unrelated', 'must survive');
        $this->push_parts($push_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "first\0",
        ]]);
        $this->complete_work_deletes($push_session);

        $this->assert_corrupted_commit_state_before_document_root_claim(
            $push_session,
            array_merge($this->initial_commit_state(), ['current_delete_path' => base64_encode('unrelated')]),
            ['unrelated' => 'must survive']
        );
    }

    public function testWorkDeletesByteOffsetAndPhaseFieldsMustAgreeBeforeDocumentRootClaim(): void {
        $cases = [
            'committing before delete EOF' => ['phase' => 'installing_files'],
            'complete before delete EOF' => ['phase' => 'complete'],
            'deleting with installing_files' => [
                'current_work_files_descendant' => ['path_b64' => base64_encode('pending'), 'expected_type' => 'file'],
            ],
            'deleting with commit cursor' => [
                'commit_cursor' => [['component_b64' => base64_encode('pending')]],
            ],
            'complete with installing_files' => [
                'phase' => 'complete',
                'work_deletes_byte_offset' => 6,
                'current_work_files_descendant' => ['path_b64' => base64_encode('pending'), 'expected_type' => 'file'],
            ],
            'complete with commit cursor' => [
                'phase' => 'complete',
                'work_deletes_byte_offset' => 6,
                'commit_cursor' => [['component_b64' => base64_encode('pending')]],
            ],
            'associative commit cursor' => [
                'phase' => 'installing_files',
                'work_deletes_byte_offset' => 6,
                'commit_cursor' => ['frame' => ['component_b64' => base64_encode('pending')]],
            ],
        ];
        $case_index = 0;
        foreach ($cases as $description => $case) {
            $push_session = $this->push_session(sprintf('%032x', 2300 + $case_index));
            $sentinel = 'phase-sentinel-' . $case_index;
            file_put_contents($this->docroot . '/' . $sentinel, 'must survive');
            $this->push_parts($push_session, [[
                'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
                'body' => "first\0",
            ]]);
            $this->complete_work_deletes($push_session);
            $this->assert_corrupted_commit_state_before_document_root_claim(
                $push_session,
                array_merge($this->initial_commit_state(), $case),
                [$sentinel => 'must survive'],
                $description
            );
            ++$case_index;
        }
    }

    public function testCurrentDeleteBindingAcceptsArbitraryNonUtf8PathBytes(): void {
        $path = "non-utf8-\xff";
        $push_session = $this->push_session('27272727383838384949494960606060');
        $this->push_parts($push_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $path . "\0",
        ]]);
        $this->complete_work_deletes($push_session);
        $this->write_commit_state($push_session, array_merge($this->initial_commit_state(), [
            'current_delete_path' => base64_encode($path),
        ]));

        $this->commit_all($push_session);

        $this->assertSame(strlen($path) + 1, $this->commit_state($push_session)['work_deletes_byte_offset']);
        $this->assertSame(1, $this->commit_state($push_session)['deleted_files']);
    }

    public function testRecoverableIoFailureDoesNotAdvanceAndCanResume(): void {
        $push_session = $this->push_session('14141414141414141414141414141414');
        $this->push_file($push_session, 'installed.txt', 'new');
        $this->complete_work_deletes($push_session);
        chmod($this->docroot, 0500);
        try {
            try {
                $push_session->commit(1);
                $this->fail('Commit mutated an unwritable document root.');
            } catch (Site_Export_Push_Exception $exception) {
                $this->assertSame('filesystem_error', $exception->get_error_code());
            }
            $commit_state = $this->commit_state($push_session);
            $this->assertSame('deleting_files', $commit_state['phase']);
            $this->assertSame(0, $commit_state['work_deletes_byte_offset']);
            $this->assertSame(0, $commit_state['installed_files']);
        } finally {
            chmod($this->docroot, 0700);
        }
        $this->commit_all($this->reopen($push_session));
        $this->assertSame('new', file_get_contents($this->docroot . '/installed.txt'));
    }

    public function testNonRecoverableCommitFailureIsDurableAcrossReopen(): void {
        mkdir($this->docroot . '/conflict');
        $push_session = $this->push_session('15151515151515151515151515151515');
        $this->push_file($push_session, 'conflict', 'new');
        try {
            $this->commit_all($push_session);
            $this->fail('Incompatible destination was accepted.');
        } catch (Site_Export_Push_Exception $first) {
            $this->assertSame('unexpected_docroot_mutation', $first->get_error_code());
            try {
                $this->reopen($push_session)->commit(1);
                $this->fail('A reopened push session retried document-root mutation after a non-recoverable commit failure.');
            } catch (Site_Export_Push_Exception $second) {
                $this->assertSame($first->get_error_code(), $second->get_error_code());
                $this->assertSame($first->getMessage(), $second->getMessage());
                $this->assertSame($first->get_context(), $second->get_context());
            }
        }
    }

    public function testUnsupportedDocumentRootAndWorkNodesAreRejectedWithoutMutation(): void {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('FIFO creation is unavailable.');
        }
        posix_mkfifo($this->docroot . '/docroot-fifo', 0600);
        $delete_session = $this->push_session('16161616161616161616161616161616');
        $this->push_parts($delete_session, [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => "docroot-fifo\0",
        ]]);
        try {
            $this->commit_all($delete_session);
            $this->fail('Unsupported document-root node was deleted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('unexpected_docroot_mutation', $exception->get_error_code());
        }
        $this->assertFileExists($this->docroot . '/docroot-fifo');
        unlink($this->docroot . '/.maintenance');
        unlink($this->reprint_directory . '/.reprint/push/commit-state');

        $install_session = $this->push_session('17171717171717171717171717171717');
        posix_mkfifo($install_session->get_push_directory() . '/work/files/work-fifo', 0600);
        try {
            $this->commit_all($install_session);
            $this->fail('Unsupported work node was installed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertFileDoesNotExist($this->docroot . '/work-fifo');
    }

    public function testCrossDevicePushSessionCreationIsRejectedAndCleanedUpWhenAvailable(): void {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            $this->markTestSkipped('No writable secondary filesystem is available.');
        }
        $docroot_device = stat($this->docroot)['dev'];
        $secondary_device = stat('/dev/shm')['dev'];
        if ($docroot_device === $secondary_device) {
            $this->markTestSkipped('/dev/shm is not a distinct filesystem.');
        }
        $secondary_docroot = '/dev/shm/reprint-push-' . bin2hex(random_bytes(8));
        mkdir($secondary_docroot, 0700);
        $push_session_id = '18181818181818181818181818181818';
        try {
            Site_Export_Push_Session::create($this->reprint_directory, $secondary_docroot, [], $push_session_id);
            $this->fail('Cross-device session creation was accepted.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('same_device', $exception->get_error_code());
            $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
        } finally {
            rmdir($secondary_docroot);
        }
    }

    public function testDocumentRootReplacementStopsCommitBeforeDocumentRootMutation(): void {
        $push_session = $this->push_session('19191919191919191919191919191919');
        $this->push_file($push_session, 'pending.txt', 'new');
        $this->complete_work_deletes($push_session);
        rmdir($this->docroot);
        file_put_contents($this->docroot, 'not a directory');
        try {
            $push_session->commit(1);
            $this->fail('Commit used a replaced document root.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $this->assertSame('not a directory', file_get_contents($this->docroot));
    }

    public function testMissingCommitStateAndMaintenanceMarkersAreRecreatedOnResume(): void {
        foreach (['docroot-marker', 'private-marker', 'active-coordinator', 'docroot-lock'] as $index => $missing_path) {
            $push_session = $this->push_session(sprintf('%032x', 1600 + $index));
            $this->push_file($push_session, 'value-' . $index . '.txt', 'new');
            $this->complete_work_deletes($push_session);
            $push_session->commit(1);
            if ($missing_path === 'docroot-marker') {
                unlink($this->docroot . '/.maintenance');
            } elseif ($missing_path === 'private-marker') {
                unlink($push_session->get_push_directory() . '/work/maintenance.php');
            } elseif ($missing_path === 'active-coordinator') {
                unlink($this->reprint_directory . '/.reprint/push/commit-state');
            } else {
                unlink($this->reprint_directory . '/.reprint/push/commit-state.lock');
            }

            $this->commit_all($this->reopen($push_session));
            $this->assertSame('new', file_get_contents($this->docroot . '/value-' . $index . '.txt'));
            $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
            $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
        }
    }

    public function testMissingPushSessionLockAfterCommitReportsCorruptionWithoutMutation(): void {
        $push_session = $this->push_session('20202020202020202020202020202020');
        $this->push_file($push_session, 'pending.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        unlink($push_session->get_push_directory() . '/push.lock');

        try {
            $this->reopen($push_session)->commit(1);
            $this->fail('A push session resumed after its lock disappeared.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
            $this->assertStringContainsString('lock', strtolower($exception->getMessage()));
        }
        $this->assertFileDoesNotExist($this->docroot . '/pending.txt');
    }

    public function testForeignCommitStateOwnerReplacementStopsResumeWithoutInstalling(): void {
        $push_session = $this->push_session('21212121212121212121212121212121');
        $this->push_file($push_session, 'pending.txt', 'new');
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);
        $foreign_owner = str_repeat('f', 32) . "\n";
        file_put_contents($this->reprint_directory . '/.reprint/push/commit-state', $foreign_owner);

        try {
            $this->reopen($push_session)->commit(1);
            $this->fail('A push session ignored replaced document-root ownership.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        }
        $this->assertSame($foreign_owner, file_get_contents($this->reprint_directory . '/.reprint/push/commit-state'));
        $this->assertFileDoesNotExist($this->docroot . '/pending.txt');
    }

    public function testCommitStateReleaseFailureAfterCompletionIsRetriedWithoutReplayingWork(): void {
        $deleted_path = 'release-deleted.txt';
        file_put_contents($this->docroot . '/' . $deleted_path, 'old');
        $push_session = $this->push_session('28282828393939395050505061616161');
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
        $this->push_parts($push_session, $parts);
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);

        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        $maintenance_path = $this->docroot . '/.maintenance';
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
            $push_session->commit(1000);
            $this->fail('Commit reported success after commit-state release failed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($process));
        }
        $this->assertSame('complete', $this->commit_state($push_session)['phase']);
        $this->assertDirectoryExists($active_path);

        try {
            $push_session->commit(1);
            $this->fail('A complete checkpoint hid its still-corrupt commit-state owner.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }

        file_put_contents($this->docroot . '/release-entry-0', 'changed after completion');
        file_put_contents($this->docroot . '/' . $deleted_path, 'replacement after completion');
        rmdir($active_path);
        file_put_contents($active_path, $push_session->get_push_session_id() . "\n");
        $result = $push_session->commit(1);

        $this->assertSame('complete', $result['phase']);
        $this->assertFalse($result['send_next_request']);
        $this->assertSame(0, $result['entries_processed']);
        $this->assertFileDoesNotExist($active_path);
        $this->assertSame('changed after completion', file_get_contents($this->docroot . '/release-entry-0'));
        $this->assertSame('replacement after completion', file_get_contents($this->docroot . '/' . $deleted_path));
    }

    public function testCommitStateReleaseLeavesAValidForeignOwnerAfterCompletedPushSession(): void {
        $push_session = $this->push_session('29292929404040405151515162626262');
        $this->push_file($push_session, 'completed-value', 'new');
        $this->commit_all($push_session);
        $foreign_owner = str_repeat('e', 32) . "\n";
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        file_put_contents($active_path, $foreign_owner);

        $result = $push_session->commit(1);

        $this->assertSame('complete', $result['phase']);
        $this->assertSame($foreign_owner, file_get_contents($active_path));
        $this->assertSame('new', file_get_contents($this->docroot . '/completed-value'));
    }

    public function testRemoveReleaseFailureAfterRenameIsRetriedBeforeTombstoneCleanup(): void {
        $push_session = $this->push_session('30303030414141415252525263636363');
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        mkdir($active_path);

        try {
            $push_session->remove_push_directory();
            $this->fail('Remove cleaned a tombstone after commit-state release failed.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('corrupted_push_state', $exception->get_error_code());
        }
        $tombstone = $this->reprint_directory . '/.reprint/push/.removing-' . $push_session->get_push_session_id();
        $this->assertDirectoryDoesNotExist($push_session->get_push_directory());
        $this->assertDirectoryExists($tombstone);

        rmdir($active_path);
        file_put_contents($active_path, $push_session->get_push_session_id() . "\n");
        $this->assertTrue($push_session->remove_push_directory());

        $this->assertFileDoesNotExist($active_path);
        $this->assertDirectoryDoesNotExist($tombstone);
    }

    private function push_session(string $id): Site_Export_Push_Session {
        return Site_Export_Push_Session::create(
            $this->reprint_directory,
            $this->docroot,
            ['wp-content/plugins/reprint'],
            $id
        );
    }

    private function reopen(Site_Export_Push_Session $push_session): Site_Export_Push_Session {
        return Site_Export_Push_Session::open(
            $this->reprint_directory,
            $this->docroot,
            $push_session->get_push_session_id(),
            ['wp-content/plugins/reprint']
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
     *     phase:'deleting_files'|'installing_files'|'complete',
     *     work_deletes_byte_offset:int,
     *     current_delete_path:?string,
     *     current_work_files_descendant:?array{path_b64:string,expected_type:'file'|'directory'|'symlink'},
     *     commit_cursor:list<array{component_b64:string}>,
     *     deleted_files:int,
     *     installed_files:int,
     *     non_recoverable_commit_failure?:array{reason:string,detail:string,context:array<string,mixed>}
     * }
     */
    private function commit_state(Site_Export_Push_Session $push_session): array {
        $commit_state = json_decode(
            (string) file_get_contents($push_session->get_push_directory() . '/commit.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($commit_state);
        return $commit_state;
    }

    /**
     * @return array{
     *     version:3,
     *     phase:'deleting_files'|'installing_files'|'complete',
     *     work_deletes_byte_offset:int,
     *     current_delete_path:?string,
     *     current_work_files_descendant:?array{path_b64:string,expected_type:'file'|'directory'|'symlink'},
     *     commit_cursor:list<array{component_b64:string}>,
     *     deleted_files:int,
     *     installed_files:int,
     *     non_recoverable_commit_failure?:array{reason:string,detail:string,context:array<string,mixed>}
     * }|null
     */
    private function commit_state_or_null(Site_Export_Push_Session $push_session): ?array {
        $path = $push_session->get_push_directory() . '/commit.json';
        return is_file($path) ? $this->commit_state($push_session) : null;
    }

    private function set_current_work_files_descendant(Site_Export_Push_Session $push_session, string $path, string $type): void {
        $commit_state = $this->commit_state($push_session);
        $commit_state['current_work_files_descendant'] = ['path_b64' => base64_encode($path), 'expected_type' => $type];
        file_put_contents(
            $push_session->get_push_directory() . '/commit.json',
            json_encode($commit_state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
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

    /**
     * @return array{
     *     version:3,
     *     phase:'deleting_files',
     *     work_deletes_byte_offset:0,
     *     current_delete_path:null,
     *     current_work_files_descendant:null,
     *     commit_cursor:list<array{component_b64:string}>,
     *     deleted_files:0,
     *     installed_files:0
     * }
     */
    private function initial_commit_state(): array {
        return [
            'version' => 3,
            'phase' => 'deleting_files',
            'work_deletes_byte_offset' => 0,
            'current_delete_path' => null,
            'current_work_files_descendant' => null,
            'commit_cursor' => [],
            'deleted_files' => 0,
            'installed_files' => 0,
        ];
    }

    /** @param array<string,mixed> $commit_state */
    private function write_commit_state(Site_Export_Push_Session $push_session, array $commit_state): void {
        file_put_contents(
            $push_session->get_push_directory() . '/commit.json',
            json_encode($commit_state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string,mixed> $commit_state Deliberately corrupt checkpoint candidate.
     * @param array<string,string> $preserved_paths Document-root paths and contents which must not change.
     */
    private function assert_corrupted_commit_state_before_document_root_claim(
        Site_Export_Push_Session $push_session,
        array $commit_state,
        array $preserved_paths,
        string $case = ''
    ): void {
        $this->write_commit_state($push_session, $commit_state);
        $commit_state_path = $push_session->get_push_directory() . '/commit.json';
        $commit_state_contents = file_get_contents($commit_state_path);
        $observed_exception = null;
        try {
            $push_session->commit(16);
        } catch (Throwable $exception) {
            $observed_exception = $exception;
        }

        $this->assertInstanceOf(
            Site_Export_Push_Exception::class,
            $observed_exception,
            'Corrupt commit checkpoint was accepted' . ( $case === '' ? '.' : ' for case ' . $case . '.' )
        );
        $this->assertSame('corrupted_push_state', $observed_exception->get_error_code());
        $this->assertSame($commit_state_contents, file_get_contents($commit_state_path));
        foreach ($preserved_paths as $path => $contents) {
            $this->assertSame($contents, file_get_contents($this->docroot . '/' . $path));
        }
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        $this->assertFileDoesNotExist($push_session->get_push_directory() . '/work/maintenance.php');
        $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
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
            $body .= 'Content-Length: ' . strlen($part['body']) . "\r\n\r\n";
            $body .= $part['body'] . "\r\n";
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
            $result = $push_session->commit(2);
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
