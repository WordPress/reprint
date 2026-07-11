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
        self::assertDirectoryDoesNotExist($session_directory . '/work/backups');
        self::assertSame('', file_get_contents($session_directory . '/work/operations.jsonl'));
        self::assertFileDoesNotExist($session_directory . '/artifacts');
        self::assertFileDoesNotExist($session_directory . '/work/prepared');

        $status = $session->get_status();
        self::assertSame('uploading', $status['phase']);
        self::assertSame(0, $status['operation_count']);
        self::assertNull($status['current_file']);
        self::assertArrayNotHasKey('commit_offset', $status);
        self::assertArrayNotHasKey('commit_count', $status);
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

    public function testTypedOperationsStageDirectlyWithoutMutatingTheLiveTarget(): void {
        file_put_contents($this->target_directory . '/gone.txt', 'old');
        $session = $this->createSession();

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
            $upload->accept_delete(1, 'gone.txt');
            $upload->append_file_chunk(2, 'implicit/child.txt', 0, 0, 'hello', 5, false);
            $upload->accept_symlink(3, 'link', 'implicit/child.txt');
        });

        $session_directory = $this->sessionDirectory($session);
        $staged_directory = $session_directory . '/work/staged';
        self::assertDirectoryExists($staged_directory . '/directory');
        self::assertSame('hello', file_get_contents($staged_directory . '/implicit/child.txt'));
        self::assertTrue(is_link($staged_directory . '/link'));
        self::assertSame('implicit/child.txt', readlink($staged_directory . '/link'));
        self::assertFileDoesNotExist($session_directory . '/artifacts');

        $journal_entries = array_map(
            static function (string $line): array {
                return json_decode($line, true);
            },
            file($session_directory . '/work/operations.jsonl', FILE_IGNORE_NEW_LINES)
        );
        self::assertSame(['directory', 'delete', 'file', 'symlink'], array_column(array_column($journal_entries, 'entry'), 'type'));
        self::assertSame(
            array_map('base64_encode', ['directory', 'gone.txt', 'implicit/child.txt', 'link']),
            array_column(array_column($journal_entries, 'entry'), 'path_b64')
        );

        $status = $session->get_status();
        self::assertSame('uploading', $status['phase']);
        self::assertSame(4, $status['operation_count']);
        self::assertSame('old', file_get_contents($this->target_directory . '/gone.txt'));
        self::assertFileDoesNotExist($this->target_directory . '/directory');
        self::assertFileDoesNotExist($this->target_directory . '/implicit');
        self::assertFileDoesNotExist($this->target_directory . '/link');
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
        self::assertSame('abcdef', file_get_contents($this->sessionDirectory($session) . '/work/staged/large.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/large.bin');
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
        self::assertSame('abcdefghij', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('newest', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('oldest', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('new-longer', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('new', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('new', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
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
        self::assertSame('third', file_get_contents($this->sessionDirectory($session) . '/work/staged/file.bin'));
        self::assertFileDoesNotExist($this->target_directory . '/file.bin');
    }

    public function testZeroByteFileUsesOneEmptyFrame(): void {
        $session = $this->createSession();
        $result = $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): array {
            return $upload->append_file_chunk(0, 'empty', 0, 0, '', 0, false);
        });

        self::assertSame(1, $result['operation_count']);
        $staged_path = $this->sessionDirectory($session) . '/work/staged/empty';
        self::assertFileExists($staged_path);
        self::assertSame(0, filesize($staged_path));
        self::assertFileDoesNotExist($this->target_directory . '/empty');
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

    public function testUploadMetadataTemporarySymlinksAreUnlinkedWithoutTouchingTheirTargets(): void {
        $session = $this->createSession();
        $outside = $this->temporary_directory . '/outside-metadata';
        file_put_contents($outside, 'keep');
        symlink($outside, $this->sessionDirectory($session) . '/current-operation.json.tmp');
        symlink($outside, $this->sessionDirectory($session) . '/state.json.tmp');

        $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
            $upload->accept_directory(0, 'directory');
        });

        self::assertSame('keep', file_get_contents($outside));
        self::assertFileDoesNotExist($this->sessionDirectory($session) . '/current-operation.json.tmp');
        self::assertFileDoesNotExist($this->sessionDirectory($session) . '/state.json.tmp');
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
        self::assertSame('abcdef', file_get_contents($this->sessionDirectory($session) . '/work/staged/file'));
        self::assertFileDoesNotExist($this->target_directory . '/file');
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

    public function testUploadOnlyStateRejectsACommitOperationDescriptor(): void {
        $session = $this->createSession();
        $operation_path = $this->sessionDirectory($session) . '/current-operation.json';
        file_put_contents($operation_path, json_encode([
            'purpose' => 'commit',
            'entry' => [
                'type' => 'directory',
                'path_b64' => base64_encode('directory'),
            ],
        ]));

        try {
            $session->while_uploading(0, function (Site_Export_Staged_Apply $upload): void {
                $upload->accept_directory(0, 'directory');
            });
            self::fail('Expected the upload-only state machine to reject a commit descriptor.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('is not an upload operation', $exception->getMessage());
        }
        self::assertSame('uploading', $session->get_status()['phase']);
        self::assertSame(0, $session->get_status()['operation_count']);
    }

    public function testUncommittedDiscardTraversesMoreThanOneBoundedCleanupBatch(): void {
        $session = $this->createSession();
        $staged = $this->sessionDirectory($session) . '/work/staged';
        for ($file = 0; $file < 600; $file++) {
            file_put_contents($staged . '/cleanup-' . $file, 'x');
        }

        $observed_pending = false;
        $request_generation = 0;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                if ($session->discard($request_generation)) {
                    self::assertTrue($observed_pending);
                    return;
                }
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply::ERROR_DISCARD_PENDING, $exception->getCode());
                $observed_pending = true;
                ++$request_generation;
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
        self::assertSame('bytes', file_get_contents($this->sessionDirectory($session) . '/work/staged/' . $path));
        self::assertFileDoesNotExist($this->target_directory . '/' . $path);
    }

    private function createSession(): Site_Export_Staged_Apply {
        return Site_Export_Staged_Apply::create($this->storage_directory, $this->target_directory);
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
