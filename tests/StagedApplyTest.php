<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply.php';

final class StagedApplyTest extends TestCase {

    private string $temporary_directory;

    private string $storage_directory;

    private string $target_directory;

    protected function setUp(): void {
        $this->temporary_directory = sys_get_temp_dir() . '/reprint-direct-apply-' . bin2hex(random_bytes(8));
        $this->storage_directory = $this->temporary_directory . '/staging';
        $this->target_directory = $this->temporary_directory . '/target';
        mkdir($this->temporary_directory, 0700, true);
        mkdir($this->target_directory, 0700, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->temporary_directory);
    }

    public function testCreateBuildsPrivateDirectWorkspaceAndWebGuards(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);

        self::assertFileExists($this->storage_directory . '/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->storage_directory . '/.htaccess'));
        self::assertSame("<?php\n", file_get_contents($this->storage_directory . '/index.php'));
        self::assertDirectoryExists($session_directory . '/work/staged');
        self::assertDirectoryExists($session_directory . '/work/backups');
        self::assertSame('', file_get_contents($session_directory . '/work/operations.jsonl'));
        self::assertFileDoesNotExist($session_directory . '/artifacts');
        self::assertFileDoesNotExist($session_directory . '/work/prepared');

        $status = $session->get_status();
        self::assertSame('uploading', $status['phase']);
        self::assertSame(0, $status['operation_count']);
        self::assertNull($status['current_file']);
    }

    public function testCreateRejectsASymlinkedSessionsDirectoryWithoutTouchingItsTarget(): void {
        mkdir($this->storage_directory, 0700);
        $outside = $this->temporary_directory . '/outside-sessions';
        $session_id = str_repeat('a', 32);
        mkdir($outside . '/' . $session_id, 0700, true);
        file_put_contents($outside . '/' . $session_id . '/sentinel', 'keep');
        symlink($outside, $this->storage_directory . '/apply-sessions');

        try {
            Site_Export_Staged_Apply::create(
                $this->storage_directory,
                $this->target_directory,
                [],
                $session_id
            );
            self::fail('Expected symlinked sessions directory rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be a real directory', $exception->getMessage());
        }
        self::assertSame('keep', file_get_contents($outside . '/' . $session_id . '/sentinel'));
    }

    public function testDeterministicCreateMakesBoundedProgressCleaningADeepIncompleteWorkspace(): void {
        mkdir($this->storage_directory . '/apply-sessions', 0700, true);
        $session_id = str_repeat('b', 32);
        $incomplete = $this->storage_directory . '/apply-sessions/' . $session_id;
        mkdir($incomplete, 0700);
        $deep = $incomplete;
        for ($depth = 0; $depth < 140; $depth++) {
            $deep .= '/d';
            mkdir($deep, 0700);
        }
        file_put_contents($deep . '/sentinel', 'partial');

        $session = null;
        $observed_pending_cleanup = false;
        for ($attempt = 0; $attempt < 10 && $session === null; $attempt++) {
            try {
                $session = Site_Export_Staged_Apply::create(
                    $this->storage_directory,
                    $this->target_directory,
                    [],
                    $session_id
                );
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_DISCARD_PENDING, $exception->getCode());
                $observed_pending_cleanup = true;
            }
        }

        self::assertTrue($observed_pending_cleanup);
        self::assertInstanceOf(Site_Export_Staged_Apply::class, $session);
        self::assertSame('uploading', $session->get_status()['phase']);
    }

    public function testTypedOperationsStageDirectlyAndCommitWithoutAPreparationPass(): void {
        file_put_contents($this->target_directory . '/gone.txt', 'old');
        $session = $this->createSession();

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
            $upload->accept_delete(1, 'gone.txt');
            $upload->append_file_chunk(2, 'implicit/child.txt', 0, 0, 'hello', 5, false);
            $upload->accept_symlink(3, 'link', 'implicit/child.txt');
        });

        $session_directory = $this->sessionDirectory($session);
        self::assertSame('hello', file_get_contents($session_directory . '/work/staged/implicit/child.txt'));
        self::assertFileDoesNotExist($session_directory . '/artifacts');
        self::assertFileExists($this->target_directory . '/gone.txt');

        $status = $this->advanceUntilComplete($session);
        self::assertSame(4, $status['commit_count']);
        self::assertDirectoryExists($this->target_directory . '/directory');
        self::assertFileDoesNotExist($this->target_directory . '/gone.txt');
        self::assertSame('hello', file_get_contents($this->target_directory . '/implicit/child.txt'));
        self::assertSame('implicit/child.txt', readlink($this->target_directory . '/link'));
    }

    public function testPartialFileCursorSurvivesRequestsAndReportsOnlyConfirmedBytes(): void {
        $session = $this->createSession();
        $result = $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'large.bin', 4, 0, 'abc', 6, false);
        });

        self::assertSame('accepted', $result['status']);
        self::assertSame(0, $result['operation_count']);
        self::assertSame(3, $result['current_file']['committed_bytes']);
        self::assertSame(1, $session->get_status()['request_generation']);

        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'large.bin', 4, 3, 'def', 6, false);
        });
        self::assertSame(1, $result['operation_count']);
        self::assertNull($result['current_file']);
        $this->advanceUntilComplete($session);
        self::assertSame('abcdef', file_get_contents($this->target_directory . '/large.bin'));
    }

    public function testIncomingFileCannotResumeBelowItsConfirmedCursor(): void {
        foreach (['truncated', 'deleted'] as $damage) {
            $session = $this->createSession();
            $path = 'file-' . $damage;
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload) use ($path): void {
                $upload->append_file_chunk(0, $path, 0, 0, 'abc', 6, false);
            });
            $incoming = $this->sessionDirectory($session) . '/work/incoming-file';
            if ($damage === 'truncated') {
                file_put_contents($incoming, 'a');
            } else {
                unlink($incoming);
            }

            try {
                $session->while_uploading(1, function (Site_Export_Staged_Apply $upload) use ($path): void {
                    $upload->append_file_chunk(0, $path, 0, 3, 'def', 6, false);
                });
                self::fail('Expected confirmed incoming-file cursor damage to fail the session.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_INVALID_OPERATION, $exception->getCode());
                self::assertStringContainsString('confirmed byte 3', $exception->getMessage());
            }
            self::assertSame('failed', $session->get_status()['phase']);
            self::assertSame(0, $session->get_status()['operation_count']);
            self::assertFileDoesNotExist($this->sessionDirectory($session) . '/work/staged/' . $path);
        }
    }

    public function testFileReplayHandlesDuplicateGapAndShiftedChunkBoundary(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'abcd', 10, false);
        });

        $results = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return [
                $upload->append_file_chunk(0, 'file.bin', 1, 0, 'ab', 10, false),
                $upload->append_file_chunk(0, 'file.bin', 1, 7, 'x', 10, false),
                $upload->append_file_chunk(0, 'file.bin', 1, 2, 'cdefgh', 10, false),
                $upload->append_file_chunk(0, 'file.bin', 1, 8, 'ij', 10, false),
            ];
        });

        self::assertSame('duplicate', $results[0]['status']);
        self::assertSame('offset_gap', $results[1]['reason']);
        self::assertSame(8, $results[2]['current_file']['committed_bytes']);
        self::assertSame(1, $results[3]['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('abcdefghij', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testNewRevisionRestartsIncompleteFileButReplayOfSameRestartDoesNotTruncate(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 2, 0, 'new', 6, true);
        });
        $result = $session->while_uploading(2, function (Site_Export_Staged_Apply $upload): array {
            $duplicate = $upload->append_file_chunk(0, 'file.bin', 2, 0, 'new', 6, true);
            self::assertSame('duplicate', $duplicate['status']);
            return $upload->append_file_chunk(0, 'file.bin', 2, 3, 'est', 6, false);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('newest', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testInterruptedRestartIntentDoesNotReplaceDurableOldRevision(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $operation_path = $this->sessionDirectory($session) . '/current-operation.json';
        $interrupted_restart = json_decode( (string) file_get_contents($operation_path), true);
        $interrupted_restart['entry']['revision'] = 2;
        file_put_contents($operation_path, json_encode($interrupted_restart));

        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file.bin', 1, 3, 'est', 6, false);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('oldest', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testNewRevisionMayGrowTheDeclaredFileSize(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file.bin', 2, 0, 'new-longer', 10, true);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('new-longer', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testNewRevisionMayShrinkTheDeclaredFileSize(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old-data', 12, false);
        });
        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file.bin', 2, 0, 'new', 3, true);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('new', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testNewRevisionDiscardsOldCompletionRenamedBeforeItsStateCommit(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $session_directory = $this->sessionDirectory($session);
        file_put_contents($session_directory . '/work/incoming-file', 'oldold');
        rename($session_directory . '/work/incoming-file', $session_directory . '/work/staged/file.bin');

        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file.bin', 2, 0, 'new', 3, true);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('new', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testLaterRevisionFinishesAnInterruptedEarlierRestartBeforeReplacingItsMetadata(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $session_directory = $this->sessionDirectory($session);
        file_put_contents($session_directory . '/work/incoming-file', 'oldold');
        rename($session_directory . '/work/incoming-file', $session_directory . '/work/staged/file.bin');

        // Simulate death after revision 2 became durable but before it
        // removed the completed revision-1 staged inode.
        $operation_path = $session_directory . '/current-operation.json';
        $operation = json_decode( (string) file_get_contents($operation_path), true);
        $operation['entry']['revision'] = 2;
        $operation['entry']['bytes'] = 3;
        file_put_contents($operation_path, json_encode($operation));
        $state_path = $session_directory . '/state.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        $state['current_file'] = [
            'operation_index' => 0,
            'path_b64' => base64_encode('file.bin'),
            'revision' => 2,
            'committed_bytes' => 0,
            'total_bytes' => 3,
            'restart_pending' => true,
            'restart_previous_total_bytes' => 6,
        ];
        file_put_contents($state_path, json_encode($state));

        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file.bin', 3, 0, 'third', 5, true);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('third', file_get_contents($this->target_directory . '/file.bin'));
    }

    public function testZeroByteFileUsesOneEmptyFrame(): void {
        $session = $this->createSession();
        $result = $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'empty', 0, 0, '', 0, false);
        });

        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertFileExists($this->target_directory . '/empty');
        self::assertSame(0, filesize($this->target_directory . '/empty'));
    }

    public function testAdvanceRefusesAnIncompleteFileWithoutConsumingGeneration(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'a', 2, false);
        });

        try {
            $session->advance(1);
            self::fail('Expected incomplete file rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('only 1 of 2 bytes', $exception->getMessage());
        }
        self::assertSame(1, $session->get_status()['request_generation']);
    }

    public function testAdvanceRefusesAnUnjournaledMetadataIntentWithoutClosingUpload(): void {
        $session = $this->createSession();
        file_put_contents(
            $this->sessionDirectory($session) . '/current-operation.json',
            json_encode([
                'purpose' => 'upload',
                'operation_index' => 0,
                'entry' => ['type' => 'directory', 'path_b64' => base64_encode('directory')],
            ])
        );

        try {
            $session->advance(0);
            self::fail('Expected unjournaled metadata intent rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Replay that operation first', $exception->getMessage());
        }
        self::assertSame(0, $session->get_status()['request_generation']);
        self::assertSame('uploading', $session->get_status()['phase']);

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
        });
        $this->advanceUntilComplete($session);
        self::assertDirectoryExists($this->target_directory . '/directory');
    }

    public function testAdvanceRefusesAFileDescriptorWrittenBeforeItsCursorState(): void {
        $session = $this->createSession();
        file_put_contents(
            $this->sessionDirectory($session) . '/current-operation.json',
            json_encode([
                'purpose' => 'upload',
                'operation_index' => 0,
                'entry' => [
                    'type' => 'file',
                    'path_b64' => base64_encode('file'),
                    'bytes' => 3,
                    'revision' => 1,
                ],
            ])
        );

        try {
            $session->advance(0);
            self::fail('Expected file descriptor/cursor cut rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Replay that operation first', $exception->getMessage());
        }
        self::assertNull($session->get_status()['current_file']);
        self::assertSame(0, $session->get_status()['request_generation']);

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 1, 0, 'new', 3, false);
        });
        $this->advanceUntilComplete($session);
        self::assertSame('new', file_get_contents($this->target_directory . '/file'));
    }

    public function testUnsafePathPoisonsUploadAndOnlyDiscardRemains(): void {
        $session = $this->createSession();
        try {
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
                $upload->accept_directory(0, '../escape');
            });
            self::fail('Expected unsafe path rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply::ERROR_INVALID_OPERATION, $exception->getCode());
        }

        self::assertSame('failed', $session->get_status()['phase']);
        self::assertTrue($session->discard(1));
    }

    public function testPartialFileCanBeInspectedAndDiscardedAfterMalformedFrameFailsUpload(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'partial', 20, false);
            $upload->fail_upload('The following frame header is malformed.');
        });

        $status = $session->get_status();
        self::assertSame('failed', $status['phase']);
        self::assertSame(7, $status['current_file']['committed_bytes']);
        self::assertTrue($session->discard(1));
    }

    public function testPrivateSessionCanBeInspectedAndDiscardedAfterTargetRootReplacement(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'partial', 20, false);
        });
        $session_id = $session->get_session_id();
        rename($this->target_directory, $this->temporary_directory . '/replaced-target');
        mkdir($this->target_directory, 0700);

        $reopened = Site_Export_Staged_Apply::open(
            $this->storage_directory,
            $this->target_directory,
            $session_id
        );
        self::assertSame(7, $reopened->get_status()['current_file']['committed_bytes']);
        self::assertTrue($reopened->discard(1));
    }

    public function testPrivateSessionCanBeReopenedAndDiscardedAfterTargetRootRemoval(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'partial', 20, false);
        });
        $session_id = $session->get_session_id();
        rmdir($this->target_directory);

        $reopened = Site_Export_Staged_Apply::open(
            $this->storage_directory,
            $this->target_directory,
            $session_id
        );
        self::assertSame('uploading', $reopened->get_status()['phase']);
        self::assertTrue($reopened->discard(1));
    }

    public function testOpenRejectsASymlinkedPrivateWorkDirectory(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        rename($session_directory . '/work', $session_directory . '/work-real');
        symlink($this->temporary_directory, $session_directory . '/work');

        try {
            Site_Export_Staged_Apply::open(
                $this->storage_directory,
                $this->target_directory,
                $session->get_session_id()
            );
            self::fail('Expected private work-directory symlink rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('work directory must be a real directory', $exception->getMessage());
        }
    }

    public function testMetadataTemporarySymlinksAreUnlinkedWithoutTouchingTheirTargets(): void {
        $session = $this->createSession();
        $outside = $this->temporary_directory . '/outside-metadata';
        file_put_contents($outside, 'keep');
        symlink($outside, $this->sessionDirectory($session) . '/state.json.tmp');

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
        });
        self::assertSame('keep', file_get_contents($outside));

        symlink($outside, $this->storage_directory . '/active-apply.json.tmp');
        $this->advanceUntilComplete($session);
        self::assertSame('keep', file_get_contents($outside));
    }

    public function testDiscardResumesWhenDiscardingStateWasWrittenBeforeDirectoryRename(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'partial', 20, false);
        });
        $state_path = $this->sessionDirectory($session) . '/state.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        $state['phase'] = 'discarding';
        $state['discarding_complete'] = false;
        $state['request_generation'] = 2;
        file_put_contents($state_path, json_encode($state));

        self::assertSame('discarding', $session->get_status()['phase']);
        self::assertTrue($session->discard(2));
    }

    public function testStagedSymlinkCannotBeUsedAsAParentEscape(): void {
        $outside = $this->temporary_directory . '/outside';
        mkdir($outside);
        $session = $this->createSession();
        try {
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload) use ($outside): void {
                $upload->accept_symlink(0, 'escape', $outside);
                $upload->append_file_chunk(1, 'escape/file', 0, 0, 'bad', 3, false);
            });
            self::fail('Expected staged symlink ancestor rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('non-directory staged ancestor', $exception->getMessage());
        }

        self::assertFileDoesNotExist($outside . '/file');
        self::assertSame('failed', $session->get_status()['phase']);
    }

    public function testPartialFileFinalizationStopsWhenItsStagedParentWasReplacedByASymlink(): void {
        $outside = $this->temporary_directory . '/outside-partial-file';
        mkdir($outside);
        file_put_contents($outside . '/sentinel', 'keep');
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'parent/file', 0, 0, 'abc', 6, false);
        });
        $staged_directory = $this->sessionDirectory($session) . '/work/staged';
        self::assertTrue(rename($staged_directory . '/parent', $staged_directory . '/parent-original'));
        self::assertTrue(symlink($outside, $staged_directory . '/parent'));

        try {
            $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): void {
                $upload->append_file_chunk(0, 'parent/file', 0, 3, 'def', 6, false);
            });
            self::fail('Expected a replaced staged parent to stop file finalization.');
        } catch (RuntimeException $exception) {
            self::assertNotContains(
                $exception->getCode(),
                [
                    Site_Export_Staged_Apply::ERROR_BUSY,
                    Site_Export_Staged_Apply::ERROR_STALE_GENERATION,
                    Site_Export_Staged_Apply::ERROR_RETRYABLE_IO,
                ]
            );
            self::assertStringContainsString('parent', $exception->getMessage());
        }

        self::assertSame(3, $session->get_status()['current_file']['committed_bytes']);
        self::assertSame('keep', file_get_contents($outside . '/sentinel'));
        self::assertFileDoesNotExist($outside . '/file');
    }

    public function testInterruptedFileRestartDoesNotUnlinkThroughAReplacedStagedParent(): void {
        $outside = $this->temporary_directory . '/outside-restart';
        mkdir($outside);
        file_put_contents($outside . '/file', 'victim');
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'parent/file', 1, 0, 'old', 6, false);
        });
        $session_directory = $this->sessionDirectory($session);
        file_put_contents($session_directory . '/work/incoming-file', 'oldold');
        self::assertTrue(rename(
            $session_directory . '/work/incoming-file',
            $session_directory . '/work/staged/parent/file'
        ));

        // Simulate death after the new revision and its restart intent became
        // durable but before the old staged completion was removed.
        $operation_path = $session_directory . '/current-operation.json';
        $operation = json_decode( (string) file_get_contents($operation_path), true);
        $operation['entry']['revision'] = 2;
        $operation['entry']['bytes'] = 3;
        file_put_contents($operation_path, json_encode($operation));
        $state_path = $session_directory . '/state.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        $state['current_file'] = [
            'operation_index' => 0,
            'path_b64' => base64_encode('parent/file'),
            'revision' => 2,
            'committed_bytes' => 0,
            'total_bytes' => 3,
            'restart_pending' => true,
            'restart_previous_total_bytes' => 6,
        ];
        file_put_contents($state_path, json_encode($state));

        $staged_directory = $session_directory . '/work/staged';
        self::assertTrue(rename($staged_directory . '/parent', $staged_directory . '/parent-original'));
        self::assertTrue(symlink($outside, $staged_directory . '/parent'));
        try {
            $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): void {
                $upload->append_file_chunk(0, 'parent/file', 2, 0, 'new', 3, true);
            });
            self::fail('Expected a replaced staged parent to stop interrupted restart cleanup.');
        } catch (RuntimeException $exception) {
            self::assertNotContains(
                $exception->getCode(),
                [
                    Site_Export_Staged_Apply::ERROR_BUSY,
                    Site_Export_Staged_Apply::ERROR_STALE_GENERATION,
                    Site_Export_Staged_Apply::ERROR_RETRYABLE_IO,
                ]
            );
            self::assertStringContainsString('parent', $exception->getMessage());
        }

        self::assertTrue($session->get_status()['current_file']['restart_pending']);
        self::assertSame('victim', file_get_contents($outside . '/file'));
        self::assertSame('oldold', file_get_contents($staged_directory . '/parent-original/file'));
    }

    public function testFileReplacementMakesDescendantDeletesHarmless(): void {
        mkdir($this->target_directory . '/node');
        file_put_contents($this->target_directory . '/node/old.txt', 'old');
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'node', 0, 0, 'replacement', 11, false);
            $upload->accept_delete(1, 'node/old.txt');
        });

        $this->advanceUntilComplete($session);
        self::assertSame('replacement', file_get_contents($this->target_directory . '/node'));
    }

    public function testParentDeleteMakesItsDescendantDeleteHarmless(): void {
        mkdir($this->target_directory . '/node');
        file_put_contents($this->target_directory . '/node/old.txt', 'old');
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_delete(0, 'node');
            $upload->accept_delete(1, 'node/old.txt');
        });

        $this->advanceUntilComplete($session);
        self::assertFileDoesNotExist($this->target_directory . '/node');
    }

    public function testDeleteTombstoneRejectsLaterMaterializationBelowIt(): void {
        $session = $this->createSession();
        try {
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
                $upload->accept_delete(0, 'node');
                $upload->append_file_chunk(1, 'node/new.txt', 0, 0, 'x', 1, false);
            });
            self::fail('Expected delete/materialization conflict.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('non-directory staged ancestor', $exception->getMessage());
        }
        self::assertSame('failed', $session->get_status()['phase']);
    }

    public function testCrashAfterFinalFileRenameIsRecoveredWithoutReadingFileAgain(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'abc', 6, false);
        });
        $session_directory = $this->sessionDirectory($session);
        file_put_contents($session_directory . '/work/incoming-file', 'abcdef');
        rename($session_directory . '/work/incoming-file', $session_directory . '/work/staged/file');

        $result = $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'file', 0, 3, 'def', 6, false);
        });
        self::assertSame(1, $result['operation_count']);
        $this->advanceUntilComplete($session);
        self::assertSame('abcdef', file_get_contents($this->target_directory . '/file'));
    }

    public function testLostCommitRenameProofDoesNotDependOnRenameChangingCtime(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'contents', 8, false);
        });
        $session_directory = $this->sessionDirectory($session);
        $journal_path = $session_directory . '/work/operations.jsonl';
        $line = (string) file_get_contents($journal_path);
        $record = json_decode(rtrim($line, "\n"), true);
        $original_ctime = $record['entry']['staged_identity']['ctime'];
        $record['entry']['staged_identity']['ctime'] = $original_ctime === 9999999999 ? 9999999998 : $original_ctime + 1;
        $changed_line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        self::assertSame(strlen($line), strlen($changed_line));
        file_put_contents($journal_path, $changed_line);
        rename($session_directory . '/work/staged/file', $this->target_directory . '/file');

        $this->advanceUntilComplete($session);
        self::assertSame('contents', file_get_contents($this->target_directory . '/file'));
    }

    public function testCommittedFileRecoveryStopsWhenItsStagedParentWasReplacedByASymlink(): void {
        $this->assertCommittedOperationStopsAtReplacedStagedParent('file');
    }

    public function testCommittedSymlinkRecoveryStopsWhenItsStagedParentWasReplacedByASymlink(): void {
        $this->assertCommittedOperationStopsAtReplacedStagedParent('symlink');
    }

    public function testFileBackupMustMatchThePersistedCommitIntent(): void {
        $this->assertWrongBackupIsRejected('file');
    }

    public function testSymlinkBackupMustMatchThePersistedCommitIntent(): void {
        $this->assertWrongBackupIsRejected('symlink');
    }

    public function testDeleteBackupMustMatchThePersistedCommitIntent(): void {
        $this->assertWrongBackupIsRejected('delete');
    }

    public function testDirectoryReplacementBackupMustMatchThePersistedCommitIntent(): void {
        $this->assertWrongBackupIsRejected('directory');
    }

    public function testPersistedTargetToBackupCutsResumeForEveryReplacingOperation(): void {
        foreach (['file', 'symlink', 'delete', 'directory'] as $type) {
            $path = 'resume-' . $type;
            file_put_contents($this->target_directory . '/' . $path, 'old');
            $session = $this->createSession();
            $this->acceptSingleOperation($session, $type, $path);
            $paths = $this->persistCommitIntent($session, $path);
            rename($paths['target'], $paths['backup']);

            $this->advanceUntilComplete($session);

            if ($type === 'file') {
                self::assertSame('new', file_get_contents($paths['target']));
            } elseif ($type === 'symlink') {
                self::assertSame('destination', readlink($paths['target']));
            } elseif ($type === 'delete') {
                self::assertFileDoesNotExist($paths['target']);
            } else {
                self::assertDirectoryExists($paths['target']);
            }
        }
    }

    public function testPersistedStagedToTargetCutRequiresBothInstalledAndBackupIdentity(): void {
        $path = 'file';
        file_put_contents($this->target_directory . '/' . $path, 'old');
        $session = $this->createSession();
        $this->acceptSingleOperation($session, 'file', $path);
        $paths = $this->persistCommitIntent($session, $path);
        rename($paths['target'], $paths['backup']);
        rename($paths['staged'], $paths['target']);

        $this->advanceUntilComplete($session);

        self::assertSame('new', file_get_contents($paths['target']));
        self::assertSame('old', file_get_contents($paths['backup']));
    }

    public function testCommitStateWrittenBeforeIntentUnlinkClearsOnlyTheStaleIntent(): void {
        $path = 'file';
        $session = $this->createSession();
        $this->acceptSingleOperation($session, 'file', $path);
        $paths = $this->persistCommitIntent($session, $path);
        rename($paths['staged'], $paths['target']);
        $state_path = $this->sessionDirectory($session) . '/state.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        $state['commit_offset'] = $state['journal_bytes'];
        $state['commit_count'] = 1;
        file_put_contents($state_path, json_encode($state));

        $this->advanceUntilComplete($session);

        self::assertSame('new', file_get_contents($paths['target']));
        self::assertFileDoesNotExist($this->sessionDirectory($session) . '/current-operation.json');
    }

    public function testTargetReappearanceBesideAConfirmedBackupStopsCommit(): void {
        $path = 'file';
        file_put_contents($this->target_directory . '/' . $path, 'old');
        $session = $this->createSession();
        $this->acceptSingleOperation($session, 'file', $path);
        $paths = $this->persistCommitIntent($session, $path);
        rename($paths['target'], $paths['backup']);
        file_put_contents($paths['target'], 'intruder');

        try {
            $session->advance(1);
            self::fail('Expected target reappearance rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('backup exists while the target reappeared', $exception->getMessage());
        }
        self::assertSame('intruder', file_get_contents($paths['target']));
        self::assertSame('old', file_get_contents($paths['backup']));
    }

    public function testMissingStagedAndInstalledNamesAfterLostRenameIsTerminal(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'contents', 8, false);
        });
        $staged = $this->sessionDirectory($session) . '/work/staged/file';
        $target = $this->target_directory . '/file';
        rename($staged, $target);
        unlink($target);

        try {
            $session->advance(1);
            self::fail('Expected irrecoverable lost-rename drift.');
        } catch (RuntimeException $exception) {
            self::assertSame(0, $exception->getCode());
            self::assertStringContainsString('both its staged and live names are absent', $exception->getMessage());
        }
    }

    public function testUncommittedJournalTailIsTruncatedBeforeNextOperation(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'a');
        });
        $journal = $this->sessionDirectory($session) . '/work/operations.jsonl';
        file_put_contents($journal, '{uncommitted', FILE_APPEND);

        $session->while_uploading(1, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(1, 'b');
        });
        $lines = file($journal, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        self::assertSame(2, $session->get_status()['operation_count']);
    }

    public function testCommitWorkIsBoundedAndResumesFromDurableCursor(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            for ($operation = 0; $operation < 20; $operation++) {
                $upload->accept_directory($operation, sprintf('dir-%02d', $operation));
            }
        });

        $status = $session->advance(1);
        self::assertSame('committing', $status['phase']);
        self::assertSame(8, $status['commit_count']);
        $reopened = Site_Export_Staged_Apply::open(
            $this->storage_directory,
            $this->target_directory,
            $session->get_session_id()
        );
        $status = $this->advanceUntilComplete($reopened);
        self::assertSame(20, $status['commit_count']);
    }

    public function testMaintenanceMarkerIsPublishedRefreshedAndRemovedWithItsIdentity(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            for ($operation = 0; $operation < 20; $operation++) {
                $upload->accept_directory($operation, sprintf('directory-%02d', $operation));
            }
        });

        $status = $session->advance(1);
        self::assertSame('committing', $status['phase']);
        $maintenance_path = $this->target_directory . '/.maintenance';
        $identity_path = $this->sessionDirectory($session) . '/work/maintenance-marker.php';
        $first_contents = (string) file_get_contents($maintenance_path);
        $first_stat = lstat($maintenance_path);
        $identity_stat = lstat($identity_path);
        self::assertSame($first_stat['ino'], $identity_stat['ino']);

        sleep(1);
        $status = $session->advance($status['request_generation']);
        self::assertSame('committing', $status['phase']);
        $second_contents = (string) file_get_contents($maintenance_path);
        $second_stat = lstat($maintenance_path);
        $identity_stat = lstat($identity_path);
        self::assertNotSame($first_contents, $second_contents);
        self::assertNotSame($first_stat['ino'], $second_stat['ino']);
        self::assertSame($second_stat['ino'], $identity_stat['ino']);

        $this->advanceUntilComplete($session);
        self::assertFileDoesNotExist($maintenance_path);
        self::assertFileDoesNotExist($this->storage_directory . '/active-apply.json');
    }

    public function testMaintenanceRefreshResumesFromEachIdentityHandoffCut(): void {
        $cuts = [
            'previous_identity_published',
            'next_identity_temporary_published',
            'next_identity_published',
            'next_identity_linked',
            'live_marker_replaced',
        ];

        foreach ($cuts as $cut_number => $cut) {
            $session = $this->createSession();
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload) use ($cut_number): void {
                for ($operation = 0; $operation < 17; $operation++) {
                    $upload->accept_directory($operation, sprintf('refresh-%d-%02d', $cut_number, $operation));
                }
            });

            $status = $session->advance(1);
            self::assertSame('committing', $status['phase']);
            self::assertSame(8, $status['commit_count']);
            $maintenance_path = $this->target_directory . '/.maintenance';
            $work_directory = $this->sessionDirectory($session) . '/work';
            $current_identity_path = $work_directory . '/maintenance-marker.php';
            $previous_identity_path = $work_directory . '/maintenance-marker.previous.php';
            $next_identity_path = $work_directory . '/maintenance-marker.next.php';
            $temporary_identity_path = $current_identity_path . '.tmp';

            // Reproduce each process-death boundary in the refresh sequence:
            // retain the old identity, publish the new inode, hardlink it to
            // the rename source, and finally replace the live marker.
            self::assertTrue(rename($current_identity_path, $previous_identity_path));
            if ($cut !== 'previous_identity_published') {
                $replacement_contents = "<?php\n\$upgrading = " . ( time() + $cut_number + 1 ) . ";\n"
                    . '// reprint-staged-apply-session:' . $session->get_session_id() . "\n";
                $replacement_path = $cut === 'next_identity_temporary_published'
                    ? $temporary_identity_path
                    : $current_identity_path;
                self::assertSame(strlen($replacement_contents), file_put_contents($replacement_path, $replacement_contents));
            }
            if ($cut === 'next_identity_linked' || $cut === 'live_marker_replaced') {
                self::assertTrue(link($current_identity_path, $next_identity_path));
            }
            if ($cut === 'live_marker_replaced') {
                self::assertTrue(rename($next_identity_path, $maintenance_path));
            }

            $status = $session->advance($status['request_generation']);
            self::assertSame('committing', $status['phase'], $cut);
            self::assertSame(16, $status['commit_count'], $cut);
            $maintenance_stat = lstat($maintenance_path);
            $identity_stat = lstat($current_identity_path);
            self::assertIsArray($maintenance_stat);
            self::assertIsArray($identity_stat);
            self::assertSame($maintenance_stat['ino'], $identity_stat['ino'], $cut);
            self::assertFileDoesNotExist($previous_identity_path);
            self::assertFileDoesNotExist($next_identity_path);
            self::assertFileDoesNotExist($temporary_identity_path);

            $this->advanceUntilComplete($session);
            self::assertFileDoesNotExist($maintenance_path);
        }
    }

    public function testAnotherSessionRecoversAMarkerLeftAfterCompletionBecameDurable(): void {
        $completed = $this->createSession();
        $completed->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            for ($operation = 0; $operation < 8; $operation++) {
                $upload->accept_directory($operation, sprintf('completed-%02d', $operation));
            }
        });
        $completed_status = $completed->advance(1);
        self::assertSame('committing', $completed_status['phase']);
        self::assertSame(8, $completed_status['commit_count']);

        // The next bounded commit step only writes phase=complete before it
        // removes .maintenance and active-apply.json. Simulate death at that
        // exact boundary while preserving the target-owned marker inode.
        $completed_state_path = $this->sessionDirectory($completed) . '/state.json';
        $completed_state = json_decode( (string) file_get_contents($completed_state_path), true);
        $completed_state['phase'] = 'complete';
        file_put_contents($completed_state_path, json_encode($completed_state));
        $completed_marker_stat = lstat($this->target_directory . '/.maintenance');
        self::assertIsArray($completed_marker_stat);

        $next = $this->createSession();
        $next->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            for ($operation = 0; $operation < 9; $operation++) {
                $upload->accept_directory($operation, sprintf('next-%02d', $operation));
            }
        });
        $next_status = $next->advance(1);
        self::assertSame('committing', $next_status['phase']);
        $next_marker_stat = lstat($this->target_directory . '/.maintenance');
        self::assertIsArray($next_marker_stat);
        self::assertNotSame($completed_marker_stat['ino'], $next_marker_stat['ino']);
        self::assertStringContainsString($next->get_session_id(), (string) file_get_contents($this->target_directory . '/.maintenance'));
        self::assertSame(
            ['session_id' => $next->get_session_id()],
            json_decode( (string) file_get_contents($this->storage_directory . '/active-apply.json'), true)
        );

        $this->advanceUntilComplete($next);
    }

    public function testForeignMaintenanceMarkerIsPreservedAndSessionCanStillBeDiscarded(): void {
        $foreign = "<?php\n\$upgrading = 1;\n// another-owner\n";
        file_put_contents($this->target_directory . '/.maintenance', $foreign);
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
        });

        try {
            $session->advance(1);
            self::fail('Expected foreign maintenance marker rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply::ERROR_BUSY, $exception->getCode());
        }
        self::assertSame($foreign, file_get_contents($this->target_directory . '/.maintenance'));
        self::assertTrue($session->discard(1));
        self::assertSame($foreign, file_get_contents($this->target_directory . '/.maintenance'));
    }

    public function testCompletedDiscardTraversesMoreThanOneBoundedCleanupBatch(): void {
        $session = $this->createSession();
        $status = $session->advance(0);
        self::assertSame('complete', $status['phase']);
        $staged = $this->sessionDirectory($session) . '/work/staged';
        for ($file = 0; $file < 600; $file++) {
            file_put_contents($staged . '/cleanup-' . $file, 'x');
        }

        $observed_pending = false;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                if ($session->discard($status['request_generation'])) {
                    self::assertTrue($observed_pending);
                    return;
                }
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_DISCARD_PENDING, $exception->getCode());
                $observed_pending = true;
            }
        }
        self::fail('The bounded discard traversal did not finish within 10 calls.');
    }

    public function testDeterministicSessionIdIsFencedUntilItsRetiredMarkerExpires(): void {
        $session_id = str_repeat('a', 32);
        $session = Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            $session_id,
            2
        );
        self::assertTrue($session->discard(0));
        $retired_path = $this->storage_directory . '/apply-sessions/retired-' . $session_id;
        self::assertFileExists($retired_path);

        try {
            Site_Export_Staged_Apply::create(
                $this->storage_directory,
                $this->target_directory,
                [],
                $session_id,
                2
            );
            self::fail('Expected the retired deterministic session id to remain fenced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already consumed', $exception->getMessage());
        }

        self::assertTrue(touch($retired_path, time() - 3));
        clearstatcache(true, $retired_path);
        $replacement = Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            $session_id,
            2
        );
        self::assertSame(0, $replacement->get_status()['request_generation']);
        self::assertFileDoesNotExist($retired_path);
    }

    public function testRetiredSessionGarbageCollectionRotatesOnlySixtyFourEntriesPerCreate(): void {
        Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            str_repeat('a', 32)
        );
        $sessions_directory = $this->storage_directory . '/apply-sessions';
        $current_directory = $sessions_directory . '/retired-gc-current';
        $deferred_directory = $sessions_directory . '/retired-gc-deferred';
        for ($entry = 1; $entry <= 65; $entry++) {
            $session_id = sprintf('%032x', $entry);
            $retired_path = $sessions_directory . '/retired-' . $session_id;
            file_put_contents($retired_path, $session_id . "\n");
            self::assertTrue(link($retired_path, $current_directory . '/' . $session_id));
        }

        Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            str_repeat('b', 32),
            3600
        );
        self::assertSame(1, $this->countDirectoryEntries($current_directory));
        self::assertSame(64, $this->countDirectoryEntries($deferred_directory));

        Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            str_repeat('c', 32),
            3600
        );
        self::assertSame(65, $this->countDirectoryEntries($current_directory));
        self::assertSame(0, $this->countDirectoryEntries($deferred_directory));
    }

    public function testRetiredGarbageCollectionRejectsSymlinkedQueueDirectoriesWithoutTouchingTheirTargets(): void {
        Site_Export_Staged_Apply::create(
            $this->storage_directory,
            $this->target_directory,
            [],
            str_repeat('a', 32)
        );
        $sessions_directory = $this->storage_directory . '/apply-sessions';
        $current_directory = $sessions_directory . '/retired-gc-current';
        $deferred_directory = $sessions_directory . '/retired-gc-deferred';
        $outside_directory = $this->temporary_directory . '/outside-retired-gc';
        mkdir($outside_directory);
        file_put_contents($outside_directory . '/sentinel', 'keep');

        self::assertTrue(rmdir($current_directory));
        self::assertTrue(symlink($outside_directory, $current_directory));
        try {
            Site_Export_Staged_Apply::create(
                $this->storage_directory,
                $this->target_directory,
                [],
                str_repeat('b', 32)
            );
            self::fail('Expected the symlinked current retired-session queue to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be a real directory', $exception->getMessage());
        }
        self::assertSame('keep', file_get_contents($outside_directory . '/sentinel'));

        self::assertTrue(unlink($current_directory));
        self::assertTrue(mkdir($current_directory, 0700));
        self::assertTrue(rmdir($deferred_directory));
        self::assertTrue(symlink($outside_directory, $deferred_directory));
        try {
            Site_Export_Staged_Apply::create(
                $this->storage_directory,
                $this->target_directory,
                [],
                str_repeat('b', 32)
            );
            self::fail('Expected the symlinked deferred retired-session queue to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be a real directory', $exception->getMessage());
        }
        self::assertSame('keep', file_get_contents($outside_directory . '/sentinel'));
    }

    public function testSequentialLiveParentSymlinkDriftStopsBeforeEscapingTarget(): void {
        $outside = $this->temporary_directory . '/outside-live-parent';
        mkdir($outside);
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'parent/file', 0, 0, 'new', 3, false);
        });
        symlink($outside, $this->target_directory . '/parent');

        try {
            $session->advance(1);
            self::fail('Expected live parent symlink drift rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('non-directory target parent', $exception->getMessage());
        }
        self::assertFileDoesNotExist($outside . '/file');
    }

    public function testOnlyOneSessionCanOwnTargetCommitAtATime(): void {
        $first = $this->createSession();
        $second = $this->createSession();
        $first->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            for ($operation = 0; $operation < 9; $operation++) {
                $upload->accept_directory($operation, sprintf('first-%02d', $operation));
            }
        });
        $second->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'second');
        });
        self::assertSame('committing', $first->advance(1)['phase']);

        try {
            $second->advance(1);
            self::fail('Expected active session contention.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply::ERROR_BUSY, $exception->getCode());
        }
        self::assertSame(1, $second->get_status()['request_generation']);
        $this->advanceUntilComplete($first);
        self::assertSame('complete', $this->advanceUntilComplete($second)['phase']);
    }

    public function testHeldSessionFlockRejectsConcurrentUploadBeforeItsCallbackRuns(): void {
        $session = $this->createSession();
        $session_lock = fopen($this->sessionDirectory($session) . '/lock', 'c+b');
        self::assertIsResource($session_lock);
        self::assertTrue(flock($session_lock, LOCK_EX | LOCK_NB));
        $called = false;
        try {
            try {
                $session->while_uploading(0, function () use (&$called): void {
                    $called = true;
                });
                self::fail('Expected held session-lock contention.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_BUSY, $exception->getCode());
            }
        } finally {
            flock($session_lock, LOCK_UN);
            fclose($session_lock);
        }
        self::assertFalse($called);
        self::assertSame(0, $session->get_status()['request_generation']);
    }

    public function testHeldApplyFlockRejectsCommitBeforeClosingUpload(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
        });
        $apply_lock = fopen($this->storage_directory . '/apply.lock', 'c+b');
        self::assertIsResource($apply_lock);
        self::assertTrue(flock($apply_lock, LOCK_EX | LOCK_NB));
        try {
            try {
                $session->advance(1);
                self::fail('Expected held apply coordinator-lock contention.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_BUSY, $exception->getCode());
            }
        } finally {
            flock($apply_lock, LOCK_UN);
            fclose($apply_lock);
        }
        self::assertSame('uploading', $session->get_status()['phase']);
        self::assertSame(1, $session->get_status()['request_generation']);
        $this->advanceUntilComplete($session);
    }

    public function testHeldDiscardFlockLeavesDurableCleanupForTheRetry(): void {
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->append_file_chunk(0, 'file', 0, 0, 'partial', 20, false);
        });
        $discard_lock = fopen($this->storage_directory . '/apply-sessions/discard.lock', 'c+b');
        self::assertIsResource($discard_lock);
        self::assertTrue(flock($discard_lock, LOCK_EX | LOCK_NB));
        try {
            try {
                $session->discard(1);
                self::fail('Expected held discard cleanup-lock contention.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_DISCARD_PENDING, $exception->getCode());
            }
        } finally {
            flock($discard_lock, LOCK_UN);
            fclose($discard_lock);
        }
        self::assertDirectoryExists($this->storage_directory . '/apply-sessions/.discarding-' . $session->get_session_id());
        self::assertTrue($session->discard(2));
    }

    public function testStaleGenerationIsRejectedBeforeUploadCallbackRuns(): void {
        $session = $this->createSession();
        $called = false;
        try {
            $session->while_uploading(1, function () use (&$called): void {
                $called = true;
            });
            self::fail('Expected stale generation rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply::ERROR_STALE_GENERATION, $exception->getCode());
        }
        self::assertFalse($called);
        self::assertSame(0, $session->get_status()['request_generation']);
    }

    public function testRawNonUtf8PathRoundTripsThroughStateAndJournal(): void {
        $path = "raw-\xff";
        $probe = $this->target_directory . '/' . $path;
        if (@file_put_contents($probe, 'probe') === false) {
            self::markTestSkipped('This filesystem API does not accept a non-UTF-8 file name.');
        }
        unlink($probe);
        $session = $this->createSession();
        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload) use ($path): void {
            $upload->append_file_chunk(0, $path, 0, 0, 'bytes', 5, false);
        });

        self::assertSame(base64_encode($path), $session->get_status()['last_path_b64']);
        $this->advanceUntilComplete($session);
        self::assertSame('bytes', file_get_contents($this->target_directory . '/' . $path));
    }

    private function createSession(): Site_Export_Staged_Apply {
        return Site_Export_Staged_Apply::create($this->storage_directory, $this->target_directory);
    }

    private function assertWrongBackupIsRejected(string $type): void {
        $path = 'item';
        file_put_contents($this->target_directory . '/' . $path, 'old');
        $session = $this->createSession();
        $this->acceptSingleOperation($session, $type, $path);
        $paths = $this->persistCommitIntent($session, $path);
        rename($paths['target'], $paths['backup']);
        unlink($paths['backup']);
        file_put_contents($paths['backup'], 'wrong');

        try {
            $session->advance(1);
            self::fail('Expected wrong backup identity rejection for ' . $type . '.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('identity field', $exception->getMessage());
        }
        self::assertFileDoesNotExist($paths['target']);
        self::assertSame('wrong', file_get_contents($paths['backup']));
    }

    private function assertCommittedOperationStopsAtReplacedStagedParent(string $type): void {
        $session = $this->createSession();
        $this->acceptSingleOperation($session, $type, 'parent/item');
        $this->persistCommitIntent($session, 'parent/item');
        $session_directory = $this->sessionDirectory($session);
        $staged_directory = $session_directory . '/work/staged';
        $original_parent = $session_directory . '/work/staged-parent-original';
        $outside = $this->temporary_directory . '/outside-committed-' . $type;
        mkdir($outside);
        file_put_contents($outside . '/sentinel', 'keep');
        self::assertTrue(rename($staged_directory . '/parent', $original_parent));
        self::assertTrue(rename($original_parent . '/item', $outside . '/item'));
        self::assertTrue(symlink($outside, $staged_directory . '/parent'));

        try {
            $session->advance(1);
            self::fail('Expected a replaced staged parent to stop committed ' . $type . ' recovery.');
        } catch (RuntimeException $exception) {
            self::assertNotContains(
                $exception->getCode(),
                [
                    Site_Export_Staged_Apply::ERROR_BUSY,
                    Site_Export_Staged_Apply::ERROR_STALE_GENERATION,
                    Site_Export_Staged_Apply::ERROR_RETRYABLE_IO,
                ]
            );
        }

        $status = $session->get_status();
        self::assertSame(0, $status['commit_count']);
        self::assertSame('keep', file_get_contents($outside . '/sentinel'));
        self::assertFileDoesNotExist($this->target_directory . '/parent/item');
        if ($type === 'file') {
            self::assertSame('new', file_get_contents($outside . '/item'));
        } else {
            self::assertTrue(is_link($outside . '/item'));
            self::assertSame('destination', readlink($outside . '/item'));
        }
    }

    private function acceptSingleOperation(Site_Export_Staged_Apply $session, string $type, string $path): void {
        $session->while_uploading(0, static function (Site_Export_Staged_Apply $upload) use ($type, $path): void {
            if ($type === 'file') {
                $upload->append_file_chunk(0, $path, 0, 0, 'new', 3, false);
            } elseif ($type === 'symlink') {
                $upload->accept_symlink(0, $path, 'destination');
            } elseif ($type === 'delete') {
                $upload->accept_delete(0, $path);
            } else {
                $upload->accept_directory(0, $path);
            }
        });
    }

    /** @return array{target:string,backup:string,staged:string} */
    private function persistCommitIntent(Site_Export_Staged_Apply $session, string $path): array {
        $session_directory = $this->sessionDirectory($session);
        $journal_line = (string) file_get_contents($session_directory . '/work/operations.jsonl');
        $record = json_decode(rtrim($journal_line, "\n"), true);
        $target = $this->target_directory . '/' . $path;
        $target_stat = @lstat($target);
        $target_identity = null;
        if (is_array($target_stat)) {
            $target_identity = [];
            foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
                $target_identity[$field] = $target_stat[$field];
            }
        }
        file_put_contents(
            $session_directory . '/current-operation.json',
            json_encode([
                'purpose' => 'commit',
                'journal_offset' => 0,
                'next_offset' => strlen($journal_line),
                'operation_index' => 0,
                'entry' => $record['entry'],
                'target_was_absent' => $target_identity === null,
                'target_identity' => $target_identity,
            ], JSON_UNESCAPED_SLASHES)
        );
        $state_path = $session_directory . '/state.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        $state['phase'] = 'committing';
        file_put_contents($state_path, json_encode($state));

        return [
            'target' => $target,
            'backup' => $session_directory . '/work/backups/' . $path,
            'staged' => $session_directory . '/work/staged/' . $path,
        ];
    }

    /** @return array<string,mixed> */
    private function advanceUntilComplete(Site_Export_Staged_Apply $session): array {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = $session->get_status();
            if ($status['phase'] === 'complete') {
                return $status;
            }
            $status = $session->advance($status['request_generation']);
            if ($status['phase'] === 'complete') {
                return $status;
            }
        }
        self::fail('The staged apply session did not complete within 20 bounded advances.');
    }

    private function sessionDirectory(Site_Export_Staged_Apply $session): string {
        return $this->storage_directory . '/apply-sessions/' . $session->get_session_id();
    }

    private function countDirectoryEntries(string $path): int {
        $directory = opendir($path);
        if ($directory === false) {
            self::fail('Could not inspect test directory: ' . $path);
        }
        $count = 0;
        try {
            while (true) {
                $entry = readdir($directory);
                if ($entry === false) {
                    break;
                }
                if ($entry !== '.' && $entry !== '..') {
                    ++$count;
                }
            }
        } finally {
            closedir($directory);
        }
        return $count;
    }

    private function removeTree(string $path): void {
        if (is_link($path) || !is_dir($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        $directory = opendir($path);
        if ($directory === false) {
            return;
        }
        try {
            while (true) {
                $entry = readdir($directory);
                if ($entry === false) {
                    break;
                }
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        } finally {
            closedir($directory);
        }
        rmdir($path);
    }
}
