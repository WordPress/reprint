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
        $reported_phases = [];
        do {
            $result = $push_session->commit(1);
            $this->assertSame(['phase', 'send_next_request', 'entries_processed'], array_keys($result));
            $this->assertContains($result['phase'], ['deleting_files', 'installing_files', 'complete']);
            $this->assertIsBool($result['send_next_request']);
            $this->assertIsInt($result['entries_processed']);
            $this->assertSame($result['phase'], $push_session->get_status()['phase']);
            $reported_phases[$result['phase']] = true;
            if (!file_exists($this->docroot . '/tree/old.txt') && !file_exists($this->docroot . '/tree/new.txt')) {
                $saw_deleted_before_install = true;
            }
        } while ($result['send_next_request']);

        $this->assertSame(['deleting_files', 'installing_files', 'complete'], array_keys($reported_phases));
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

    public function testExplicitEmptyAndWorkAncestorDirectoriesRemainDistinctPendingValues(): void {
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
                    'X-File-Path' => base64_encode('work-ancestor/child.txt'),
                    'X-File-Size' => '5',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'child',
            ],
        ]);
        $this->commit_all($push_session);

        $this->assertDirectoryExists($this->docroot . '/empty');
        $this->assertSame([], $this->directory_entries($this->docroot . '/empty'));
        $this->assertSame('child', file_get_contents($this->docroot . '/work-ancestor/child.txt'));
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
            'phase',
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

    public function testInstallingFilesResumesAfterCreatingADocumentRootAncestorDirectory(): void {
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

    public function testWorkAncestorDirectoryCleanupResumesAfterTheDirectoryWasRemoved(): void {
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

    public function testReachableCommitLockContentionRetriesReleaseWithoutReplayingWork(): void {
        $deleted_path = 'reachable-release-deleted.txt';
        file_put_contents($this->docroot . '/' . $deleted_path, 'old');
        $push_session = $this->push_session('36363636474747475858585869696969');
        $parts = [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $deleted_path . "\0",
        ]];
        for ($index = 0; $index < 400; ++$index) {
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('reachable-release-entry-' . $index),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ];
        }
        $this->push_parts($push_session, $parts);
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);

        $commit_state_lock_path = $this->reprint_directory . '/.reprint/push/commit-state.lock';
        $deleted_docroot_path = $this->docroot . '/' . $deleted_path;
        $ready_path = $this->root . '/release-lock-ready';
        $script = '$lock_path = base64_decode(' . json_encode(base64_encode($commit_state_lock_path), JSON_THROW_ON_ERROR) . ');'
            . '$deleted = base64_decode(' . json_encode(base64_encode($deleted_docroot_path), JSON_THROW_ON_ERROR) . ');'
            . '$ready = base64_decode(' . json_encode(base64_encode($ready_path), JSON_THROW_ON_ERROR) . ');'
            . '$deadline = microtime(true) + 10;'
            . 'while (is_file($deleted) && microtime(true) < $deadline) { usleep(100); }'
            . '$lock = fopen($lock_path, "c+b");'
            . 'if (is_file($deleted) || $lock === false || !flock($lock, LOCK_EX)) { exit(1); }'
            . 'file_put_contents($ready, "ready");'
            . 'usleep(2000000);'
            . 'flock($lock, LOCK_UN); fclose($lock);';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        try {
            $push_session->commit(1000);
            $this->fail('Commit reported success while another request held the commit-state lock.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($process));
        }
        $this->assertFileExists($ready_path);
        $this->assertSame('complete', $this->commit_state($push_session)['phase']);
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        $this->assertSame($push_session->get_push_session_id() . "\n", file_get_contents($active_path));

        file_put_contents($this->docroot . '/reachable-release-entry-0', 'changed after completion');
        file_put_contents($this->docroot . '/' . $deleted_path, 'replacement after completion');
        $result = $push_session->commit(1);

        $this->assertSame('complete', $result['phase']);
        $this->assertFalse($result['send_next_request']);
        $this->assertSame(0, $result['entries_processed']);
        $this->assertFileDoesNotExist($active_path);
        $this->assertSame('changed after completion', file_get_contents($this->docroot . '/reachable-release-entry-0'));
        $this->assertSame('replacement after completion', file_get_contents($this->docroot . '/' . $deleted_path));
    }

    public function testReachableRemoveContentionRetriesReleaseBeforeDeletingState(): void {
        $deleted_path = 'remove-release-deleted.txt';
        file_put_contents($this->docroot . '/' . $deleted_path, 'old');
        $push_session = $this->push_session('37373737484848485959595970707070');
        $parts = [[
            'headers' => ['X-Chunk-Type' => 'delete-list', 'X-Delete-Offset' => '0'],
            'body' => $deleted_path . "\0",
        ]];
        for ($index = 0; $index < 400; ++$index) {
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('remove-release-entry-' . $index),
                    'X-File-Size' => '1',
                    'X-Chunk-Offset' => '0',
                ],
                'body' => 'x',
            ];
        }
        $this->push_parts($push_session, $parts);
        $this->complete_work_deletes($push_session);
        $push_session->commit(1);

        $commit_state_lock_path = $this->reprint_directory . '/.reprint/push/commit-state.lock';
        $deleted_docroot_path = $this->docroot . '/' . $deleted_path;
        $completion_ready_path = $this->root . '/remove-completion-lock-ready';
        $completion_script = '$lock_path = base64_decode(' . json_encode(base64_encode($commit_state_lock_path), JSON_THROW_ON_ERROR) . ');'
            . '$deleted = base64_decode(' . json_encode(base64_encode($deleted_docroot_path), JSON_THROW_ON_ERROR) . ');'
            . '$ready = base64_decode(' . json_encode(base64_encode($completion_ready_path), JSON_THROW_ON_ERROR) . ');'
            . '$deadline = microtime(true) + 10;'
            . 'while (is_file($deleted) && microtime(true) < $deadline) { usleep(100); }'
            . '$lock = fopen($lock_path, "c+b");'
            . 'if (is_file($deleted) || $lock === false || !flock($lock, LOCK_EX)) { exit(1); }'
            . 'file_put_contents($ready, "ready");'
            . 'usleep(2000000);'
            . 'flock($lock, LOCK_UN); fclose($lock);';
        $completion_process = proc_open([PHP_BINARY, '-r', $completion_script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $completion_pipes);
        $this->assertIsResource($completion_process);
        foreach ($completion_pipes as $pipe) {
            fclose($pipe);
        }
        try {
            $push_session->commit(1000);
            $this->fail('Commit release was not blocked by valid commit-state lock contention.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($completion_process));
        }
        $this->assertSame('complete', $this->commit_state($push_session)['phase']);
        $active_path = $this->reprint_directory . '/.reprint/push/commit-state';
        $this->assertSame($push_session->get_push_session_id() . "\n", file_get_contents($active_path));

        $remove_ready_path = $this->root . '/remove-lock-ready';
        $remove_script = '$lock_path = base64_decode(' . json_encode(base64_encode($commit_state_lock_path), JSON_THROW_ON_ERROR) . ');'
            . '$ready = base64_decode(' . json_encode(base64_encode($remove_ready_path), JSON_THROW_ON_ERROR) . ');'
            . '$lock = fopen($lock_path, "c+b");'
            . 'if ($lock === false || !flock($lock, LOCK_EX)) { exit(1); }'
            . 'file_put_contents($ready, "ready");'
            . 'usleep(2000000);'
            . 'flock($lock, LOCK_UN); fclose($lock);';
        $remove_process = proc_open([PHP_BINARY, '-r', $remove_script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $remove_pipes);
        $this->assertIsResource($remove_process);
        foreach ($remove_pipes as $pipe) {
            fclose($pipe);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($remove_ready_path) && microtime(true) < $deadline) {
            usleep(100);
        }
        $this->assertFileExists($remove_ready_path);
        try {
            $push_session->remove_push_directory();
            $this->fail('Push removal reported success while the commit-state lock was held.');
        } catch (Site_Export_Push_Exception $exception) {
            $this->assertSame('lock_acquisition_failure', $exception->get_error_code());
        } finally {
            $this->assertSame(0, proc_close($remove_process));
        }

        $tombstone = $this->reprint_directory . '/.reprint/push/.removing-' . $push_session->get_push_session_id();
        $this->assertDirectoryDoesNotExist($push_session->get_push_directory());
        $this->assertDirectoryExists($tombstone);
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

    /** @return array<string,mixed> */
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

    private function set_current_work_files_descendant(Site_Export_Push_Session $push_session, string $path, string $type): void {
        $commit_state = $this->commit_state($push_session);
        $commit_state['current_work_files_descendant'] = ['path_b64' => base64_encode($path), 'expected_type' => $type];
        file_put_contents(
            $push_session->get_push_directory() . '/commit.json',
            json_encode($commit_state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
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
