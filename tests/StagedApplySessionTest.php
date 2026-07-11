<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply-session.php';

final class StagedApplySessionTest extends TestCase {

    private string $temporary_directory;

    private string $storage_directory;

    private string $target_directory;

    /** Creates isolated storage and target roots for one test. */
    protected function setUp(): void {
        $this->temporary_directory = sys_get_temp_dir() . '/reprint-direct-apply-' . bin2hex(random_bytes(8));
        $this->storage_directory = $this->temporary_directory . '/staging';
        $this->target_directory = $this->temporary_directory . '/target';
        mkdir($this->temporary_directory, 0700, true);
        mkdir($this->target_directory, 0700, true);
    }

    /** Removes the isolated filesystem tree after each test. */
    protected function tearDown(): void {
        $this->removeTree($this->temporary_directory);
    }

    /** Verifies session creation installs the private fixed workspace. */
    public function testCreateBuildsPrivateWorkspace(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);

        self::assertFileExists($this->storage_directory . '/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->storage_directory . '/.htaccess'));
        self::assertSame("<?php\n", file_get_contents($this->storage_directory . '/index.php'));
        self::assertDirectoryExists($session_directory . '/work/files');
        self::assertSame('', file_get_contents($session_directory . '/work/operations.jsonl'));

        $status = $session->get_status();
        self::assertSame('uploading', $status['phase']);
        self::assertSame(0, $status['operation_count']);
    }

    /** Verifies every typed operation stages and journals without live mutation. */
    public function testTypedOperationsStageAndJournalWithoutMutatingTheTarget(): void {
        file_put_contents($this->target_directory . '/b-delete', 'old');
        $session = $this->createSession();

        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_directory(0, 'a-directory');
            $upload->accept_delete(1, 'b-delete');
            $upload->accept_directory(2, 'c-directory');
            $upload->accept_symlink(3, 'd-link', 'c-directory');
        });

        $session_directory = $this->sessionDirectory($session);
        $staged_directory = $session_directory . '/work/files';
        self::assertDirectoryExists($staged_directory . '/a-directory');
        self::assertDirectoryExists($staged_directory . '/c-directory');
        self::assertTrue(is_link($staged_directory . '/d-link'));
        self::assertSame('c-directory', readlink($staged_directory . '/d-link'));

        $journal_entries = array_map(
            static function (string $line): array {
                return json_decode($line, true);
            },
            file($session_directory . '/work/operations.jsonl', FILE_IGNORE_NEW_LINES)
        );
        self::assertSame(
            ['directory', 'delete', 'directory', 'symlink'],
            array_column(array_column($journal_entries, 'entry'), 'type')
        );
        self::assertSame(
            array_map('base64_encode', ['a-directory', 'b-delete', 'c-directory', 'd-link']),
            array_column(array_column($journal_entries, 'entry'), 'path_b64')
        );

        $status = $session->get_status();
        self::assertSame(4, $status['operation_count']);
        self::assertSame('old', file_get_contents($this->target_directory . '/b-delete'));
        self::assertFileDoesNotExist($this->target_directory . '/a-directory');
        self::assertFileDoesNotExist($this->target_directory . '/c-directory');
        self::assertFileDoesNotExist($this->target_directory . '/d-link');
    }

    /** Verifies an unsafe path permanently stops the session. */
    public function testUnsafeOperationPoisonsTheSession(): void {
        $session = $this->createSession();
        try {
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
                $upload->accept_directory(0, '../escape');
            });
            self::fail('Expected unsafe path rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_OPERATION, $exception->getCode());
        }

        self::assertSame('failed', $session->get_status()['phase']);
    }

    /** Verifies protocol failure remains available in status. */
    public function testMalformedUploadFailsTheSession(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->fail_upload('The following frame header is malformed.');
        });

        $status = $session->get_status();
        self::assertSame('failed', $status['phase']);
        self::assertSame('The following frame header is malformed.', $status['failure']);
    }

    /** Verifies protected and noncanonical operation paths are terminal. */
    public function testProtectedAndOutOfOrderPathsPoisonTheirSessions(): void {
        $protected = $this->createSession(['protected']);
        try {
            $protected->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
                $upload->accept_directory(0, 'protected');
            });
            self::fail('Expected protected path rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_OPERATION, $exception->getCode());
            self::assertStringContainsString('protected staged apply path', $exception->getMessage());
        }
        self::assertSame('failed', $protected->get_status()['phase']);

        $out_of_order = $this->createSession();
        try {
            $out_of_order->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
                $upload->accept_directory(0, 'z-last');
                $upload->accept_directory(1, 'a-first');
            });
            self::fail('Expected out-of-order path rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_OPERATION, $exception->getCode());
            self::assertStringContainsString('strictly increasing', $exception->getMessage());
        }
        self::assertSame('failed', $out_of_order->get_status()['phase']);
        self::assertSame(1, $out_of_order->get_status()['operation_count']);
    }

    /** Verifies path limits fail without reflecting unbounded input. */
    public function testOverlongPathsAreRejectedWithBoundedDiagnostics(): void {
        $overlong_path = str_repeat('a', Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES + 1);
        $expected_size_detail = ( Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES + 1 )
            . ' bytes; the maximum is ' . Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES . ' bytes';
        $session = $this->createSession();
        try {
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($overlong_path): void {
                $upload->accept_directory(0, $overlong_path);
            });
            self::fail('Expected the overlong operation path to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_OPERATION, $exception->getCode());
            self::assertStringContainsString($expected_size_detail, $exception->getMessage());
            self::assertLessThan(200, strlen($exception->getMessage()));
        }
        self::assertSame('failed', $session->get_status()['phase']);

        try {
            $this->createSession([$overlong_path]);
            self::fail('Expected the overlong protected path to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($expected_size_detail, $exception->getMessage());
            self::assertLessThan(200, strlen($exception->getMessage()));
        }
    }

    /** Verifies reopening never follows a substituted work directory. */
    public function testOpenRejectsASymlinkedPrivateWorkDirectory(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        rename($session_directory . '/work', $session_directory . '/work-real');
        symlink($this->temporary_directory, $session_directory . '/work');

        try {
            Site_Export_Staged_Apply_Session::open(
                $this->storage_directory,
                $this->target_directory,
                $session->get_session_id()
            );
            self::fail('Expected private work-directory symlink rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('work directory must be a real directory', $exception->getMessage());
        }
    }

    /** Verifies storage guard creation cannot write through planted links. */
    public function testStorageWebGuardsNeverFollowPlantedSymlinks(): void {
        foreach (['.htaccess', 'index.php'] as $guard_name) {
            $storage_directory = $this->temporary_directory . '/staging-' . str_replace('.', '-', $guard_name);
            mkdir($storage_directory, 0700);
            $outside = $this->temporary_directory . '/outside-' . str_replace('.', '-', $guard_name);
            if ($guard_name === 'index.php') {
                file_put_contents($outside, 'keep');
            }
            symlink($outside, $storage_directory . '/' . $guard_name);

            try {
                Site_Export_Staged_Apply_Session::create($storage_directory, $this->target_directory);
                self::fail('Expected the planted ' . $guard_name . ' symlink to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('must be a real regular file', $exception->getMessage(), $guard_name);
            }

            if ($guard_name === 'index.php') {
                self::assertSame('keep', file_get_contents($outside));
            } else {
                self::assertFileDoesNotExist($outside);
            }
        }
    }

    /** Verifies pre-existing storage guards cannot silently permit web access. */
    public function testStorageWebGuardsRejectUnexpectedRegularContents(): void {
        foreach (['.htaccess', 'index.php'] as $guard_name) {
            $storage_directory = $this->temporary_directory . '/staging-wrong-' . str_replace('.', '-', $guard_name);
            mkdir($storage_directory, 0700);
            file_put_contents($storage_directory . '/' . $guard_name, 'unsafe');

            try {
                Site_Export_Staged_Apply_Session::create($storage_directory, $this->target_directory);
                self::fail('Expected the unexpected ' . $guard_name . ' contents to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('guard contents do not match', $exception->getMessage(), $guard_name);
            }
            self::assertSame('unsafe', file_get_contents($storage_directory . '/' . $guard_name));
        }
    }

    /** Verifies a materialized operation can be replayed after a process cut. */
    public function testMaterializedOperationCanBeReplayedAfterAProcessCut(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        $state_path = $session_directory . '/state.json';
        mkdir($session_directory . '/work/files/directory');
        $outside = $this->temporary_directory . '/outside-metadata';
        file_put_contents($outside, 'keep');
        symlink($outside, $state_path . '.tmp');

        $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_directory(0, 'directory');
        });
        self::assertSame(1, $result['operation_count']);
        self::assertSame('keep', file_get_contents($outside));
        self::assertFileDoesNotExist($state_path . '.tmp');
        self::assertCount(1, file($session_directory . '/work/operations.jsonl', FILE_IGNORE_NEW_LINES));
    }

    /** Verifies symlinks cannot acquire staged descendants. */
    public function testSymlinkAncestorsRejectDescendantMaterialization(): void {
        $outside = $this->temporary_directory . '/outside';
        mkdir($outside);

        $session = $this->createSession();
        try {
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($outside): void {
                $upload->accept_symlink(0, 'node', $outside);
                $upload->accept_directory(1, 'node/child');
            });
            self::fail('Expected descendant rejection below a staged symlink.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_OPERATION, $exception->getCode());
            self::assertStringContainsString('non-directory staged ancestor', $exception->getMessage());
        }
        self::assertSame('failed', $session->get_status()['phase']);
        self::assertSame(1, $session->get_status()['operation_count']);
        self::assertFileDoesNotExist($outside . '/child');
    }

    /** Verifies crash-left journal bytes are removed before replay. */
    public function testUncommittedJournalTailIsTruncatedBeforeTheNextOperation(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_directory(0, 'a');
        });
        $journal = $this->sessionDirectory($session) . '/work/operations.jsonl';
        file_put_contents($journal, '{uncommitted', FILE_APPEND);

        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_directory(1, 'b');
        });

        self::assertCount(2, file($journal, FILE_IGNORE_NEW_LINES));
        self::assertSame(2, $session->get_status()['operation_count']);
    }

    /** Verifies status fails when the durable journal prefix was lost. */
    public function testShortJournalFailsStatusEarly(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_directory(0, 'directory');
        });
        $journal = $this->sessionDirectory($session) . '/work/operations.jsonl';
        self::assertNotFalse(file_put_contents($journal, ''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('journal is shorter than its durable cursor');
        $session->get_status();
    }

    /** Verifies lock contention cannot enter an upload callback. */
    public function testHeldSessionFlockRejectsUploadBeforeItsCallbackRuns(): void {
        $session = $this->createSession();
        $session_lock = fopen($this->sessionDirectory($session) . '/lock', 'c+b');
        self::assertIsResource($session_lock);
        self::assertTrue(flock($session_lock, LOCK_EX | LOCK_NB));
        $called = false;
        try {
            try {
                $session->while_uploading(function () use (&$called): void {
                    $called = true;
                });
                self::fail('Expected held session-lock contention.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
            }
        } finally {
            flock($session_lock, LOCK_UN);
            fclose($session_lock);
        }

        self::assertFalse($called);
    }

    /** Verifies arbitrary path bytes remain base64-safe across persistence. */
    public function testRawNonUtf8PathRoundTripsThroughStateAndJournal(): void {
        $path = "raw-\xff";
        $probe = $this->target_directory . '/' . $path;
        if (@file_put_contents($probe, 'probe') === false) {
            self::markTestSkipped('This filesystem API does not accept a non-UTF-8 file name.');
        }
        unlink($probe);
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($path): void {
            $upload->accept_directory(0, $path);
        });

        self::assertSame(base64_encode($path), $session->get_status()['last_path_b64']);
        self::assertDirectoryExists($this->sessionDirectory($session) . '/work/files/' . $path);
        self::assertFileDoesNotExist($this->target_directory . '/' . $path);
    }

    /** Verifies filesystem errors encode arbitrary path bytes for JSON responses. */
    public function testRawNonUtf8PathIsEncodedInFilesystemFailure(): void {
        $path = str_repeat('a', 255) . "\xff/file";
        $session = $this->createSession();

        try {
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($path): void {
                $upload->accept_directory(0, $path);
            });
            self::fail('Expected the overlong filesystem segment to fail materialization.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_RETRYABLE_IO, $exception->getCode());
            self::assertStringContainsString('base64:', $exception->getMessage());
            self::assertStringNotContainsString("\xff", $exception->getMessage());
            self::assertNotFalse(json_encode(['error' => $exception->getMessage()]));
        }
    }

    /**
     * Creates a session in this test's isolated roots.
     *
     * @param string[] $protected_paths Target-relative paths the session must reject.
     * @return Site_Export_Staged_Apply_Session The new test session.
     */
    private function createSession(array $protected_paths = []): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage_directory,
            $this->target_directory,
            $protected_paths
        );
    }

    /** Returns the filesystem directory owned by a test session. */
    private function sessionDirectory(Site_Export_Staged_Apply_Session $session): string {
        return $this->storage_directory . '/apply-sessions/' . $session->get_session_id();
    }

    /** Removes an isolated test tree without following symlinks. */
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
