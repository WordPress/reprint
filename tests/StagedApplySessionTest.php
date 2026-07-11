<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply-session.php';

final class StagedApplySessionTest extends TestCase {

    private string $temporary_directory;

    private string $staging_directory;

    private string $target_directory;

    /** Creates isolated staging and target roots for one test. */
    protected function setUp(): void {
        $this->temporary_directory = sys_get_temp_dir() . '/reprint-direct-apply-' . bin2hex(random_bytes(8));
        $this->staging_directory = $this->temporary_directory . '/staging';
        $this->target_directory = $this->temporary_directory . '/target';
        mkdir($this->temporary_directory, 0700, true);
        mkdir($this->target_directory, 0700, true);
        mkdir($this->staging_directory, 0700, true);
    }

    /** Removes the isolated filesystem tree after each test. */
    protected function tearDown(): void {
        $this->removeTree($this->temporary_directory);
    }

    /** Verifies session creation installs the private fixed workspace. */
    public function testCreateBuildsPrivateWorkspace(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);

        self::assertDirectoryExists($session_directory . '/work/files');
        self::assertSame('', file_get_contents($session_directory . '/work/journal.jsonl'));
        self::assertFileExists($session_directory . '/session.json');
        self::assertFileDoesNotExist($session_directory . '/state.json');
        self::assertFileDoesNotExist($this->staging_directory . '/.htaccess');
        self::assertFileDoesNotExist($this->staging_directory . '/index.php');

        self::assertSame(
            ['journal_bytes' => 0, 'last_path_b64' => null],
            $session->get_status()
        );
    }

    /** Verifies every typed change stages and journals without live mutation. */
    public function testTypedChangesStageAndJournalWithoutMutatingTheTarget(): void {
        file_put_contents($this->target_directory . '/b-delete', 'old');
        $session = $this->createSession();
        $input = $this->requestBody([
            ['type' => 'directory', 'path' => 'a-directory'],
            ['type' => 'delete', 'path' => 'b-delete'],
            ['type' => 'directory', 'path' => 'c-directory'],
            ['type' => 'symlink', 'path' => 'd-link', 'target' => 'c-directory'],
        ]);

        $session->accept_upload($input);
        try {
            $changes = [];
            while ($session->next_change()) {
                $changes[] = $session->get_current_change();
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }

        self::assertSame(
            [
                ['type' => 'directory', 'path' => 'a-directory'],
                ['type' => 'delete', 'path' => 'b-delete'],
                ['type' => 'directory', 'path' => 'c-directory'],
                ['type' => 'symlink', 'path' => 'd-link', 'target' => 'c-directory'],
            ],
            $changes
        );

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
            file($session_directory . '/work/journal.jsonl', FILE_IGNORE_NEW_LINES)
        );
        self::assertSame(
            [
                ['type' => 'directory', 'path_b64' => base64_encode('a-directory')],
                ['type' => 'delete', 'path_b64' => base64_encode('b-delete')],
                ['type' => 'directory', 'path_b64' => base64_encode('c-directory')],
                [
                    'type' => 'symlink',
                    'path_b64' => base64_encode('d-link'),
                    'target_b64' => base64_encode('c-directory'),
                ],
            ],
            $journal_entries
        );

        $status = $session->get_status();
        self::assertSame(base64_encode('d-link'), $status['last_path_b64']);
        self::assertSame(filesize($session_directory . '/work/journal.jsonl'), $status['journal_bytes']);
        self::assertSame('old', file_get_contents($this->target_directory . '/b-delete'));
        self::assertFileDoesNotExist($this->target_directory . '/a-directory');
        self::assertFileDoesNotExist($this->target_directory . '/c-directory');
        self::assertFileDoesNotExist($this->target_directory . '/d-link');
    }

    /** Changes belong to an upload request, not to an unlocked session. */
    public function testChangesRequireAnOpenUpload(): void {
        $session = $this->createSession();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Accept an upload before reading changes.');
        $session->next_change();
    }

    /** A rejected frame leaves its payload and later frames unread. */
    public function testRejectedChangeFrameDoesNotReadItsPayloadOrLaterFrames(): void {
        $session = $this->createSession();
        $next_header = Site_Export_Staged_Push_Stream_Protocol::encode_apply_change_header([
            'type' => 'directory',
            'path' => 'later',
        ]);
        $input = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        fwrite($input, json_encode([
            'type' => 'directory',
            'path_b64' => base64_encode('first'),
            'bytes' => 1,
        ], JSON_UNESCAPED_SLASHES) . "\n" . 'x' . $next_header);
        rewind($input);

        $session->accept_upload($input);
        try {
            try {
                $session->next_change();
                self::fail('Expected payload-bearing metadata frame rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('A staged apply change frame must not declare payload bytes.', $exception->getMessage());
            }
            try {
                $session->next_change();
                self::fail('Expected the rejected request body to stay stopped.');
            } catch (LogicException $exception) {
                self::assertSame(
                    'This staged apply upload stopped after a rejected change; call finish_upload() before starting another.',
                    $exception->getMessage()
                );
            }
            self::assertSame('x' . $next_header, stream_get_contents($input));
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        self::assertNull($session->get_status()['last_path_b64']);
    }

    /** The last saved path tells a retry where to continue. */
    public function testLastPathTellsARetryWhereToContinue(): void {
        $session = $this->createSession();
        $first_input = $this->requestBody([['type' => 'directory', 'path' => 'a']]);
        $session->accept_upload($first_input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($first_input);
        }
        self::assertSame(base64_encode('a'), $session->get_status()['last_path_b64']);

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->staging_directory,
            $this->target_directory,
            $session->get_session_id()
        );
        $duplicate_input = $this->requestBody([['type' => 'directory', 'path' => 'a']]);
        $reopened->accept_upload($duplicate_input);
        try {
            try {
                $reopened->next_change();
                self::fail('Expected a retry at the saved path to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_CHANGE, $exception->getCode());
                self::assertStringContainsString('must sort after the last accepted path', $exception->getMessage());
            }
        } finally {
            $reopened->finish_upload();
            fclose($duplicate_input);
        }
        $next_input = $this->requestBody([['type' => 'directory', 'path' => 'b']]);
        $reopened->accept_upload($next_input);
        try {
            self::assertTrue($reopened->next_change());
            self::assertFalse($reopened->next_change());
        } finally {
            $reopened->finish_upload();
            fclose($next_input);
        }
        self::assertSame(base64_encode('b'), $reopened->get_status()['last_path_b64']);
    }

    /** An invalid change leaves earlier accepted changes retryable. */
    public function testUnsafeChangeLeavesTheSessionRetryable(): void {
        $session = $this->createSession();
        $later_header = Site_Export_Staged_Push_Stream_Protocol::encode_apply_change_header([
            'type' => 'directory',
            'path' => 'later',
        ]);
        $invalid_input = $this->requestBody([
            ['type' => 'directory', 'path' => '../escape'],
            ['type' => 'directory', 'path' => 'later'],
        ]);
        $session->accept_upload($invalid_input);
        try {
            try {
                $session->next_change();
                self::fail('Expected unsafe path rejection.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_CHANGE, $exception->getCode());
            }
            try {
                $session->next_change();
                self::fail('Expected the rejected request body to stay stopped.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('stopped after a rejected change', $exception->getMessage());
            }
            self::assertSame($later_header, stream_get_contents($invalid_input));
        } finally {
            $session->finish_upload();
            fclose($invalid_input);
        }
        $safe_input = $this->requestBody([['type' => 'directory', 'path' => 'safe']]);
        $session->accept_upload($safe_input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($safe_input);
        }
        self::assertSame(base64_encode('safe'), $session->get_status()['last_path_b64']);
    }

    /** An out-of-order path leaves earlier accepted changes retryable. */
    public function testOutOfOrderPathLeavesTheSessionRetryable(): void {
        $out_of_order = $this->createSession();
        $out_of_order_input = $this->requestBody([
            ['type' => 'directory', 'path' => 'z-last'],
            ['type' => 'directory', 'path' => 'a-first'],
        ]);
        $out_of_order->accept_upload($out_of_order_input);
        try {
            self::assertTrue($out_of_order->next_change());
            try {
                $out_of_order->next_change();
                self::fail('Expected out-of-order path rejection.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_CHANGE, $exception->getCode());
                self::assertStringContainsString('must sort after the last accepted path', $exception->getMessage());
            }
        } finally {
            $out_of_order->finish_upload();
            fclose($out_of_order_input);
        }
        $next_input = $this->requestBody([['type' => 'directory', 'path' => 'zz-next']]);
        $out_of_order->accept_upload($next_input);
        try {
            self::assertTrue($out_of_order->next_change());
            self::assertFalse($out_of_order->next_change());
        } finally {
            $out_of_order->finish_upload();
            fclose($next_input);
        }
        self::assertSame(base64_encode('zz-next'), $out_of_order->get_status()['last_path_b64']);
    }

    /** Verifies path limits fail without reflecting unbounded input. */
    public function testOverlongPathsAreRejectedWithBoundedDiagnostics(): void {
        $overlong_path = str_repeat('a', Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES + 1);
        $expected_size_detail = ( Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES + 1 )
            . ' bytes; the maximum is ' . Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES . ' bytes';
        $session = $this->createSession();
        $input = $this->requestBody([['type' => 'directory', 'path' => $overlong_path]]);
        $session->accept_upload($input);
        try {
            try {
                $session->next_change();
                self::fail('Expected the overlong change path to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_CHANGE, $exception->getCode());
                self::assertStringContainsString($expected_size_detail, $exception->getMessage());
                self::assertLessThan(200, strlen($exception->getMessage()));
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        self::assertNull($session->get_status()['last_path_b64']);
    }

    /** Verifies reopening never follows a substituted work directory. */
    public function testOpenRejectsASymlinkedPrivateWorkDirectory(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        rename($session_directory . '/work', $session_directory . '/work-real');
        symlink($this->temporary_directory, $session_directory . '/work');

        try {
            Site_Export_Staged_Apply_Session::open(
                $this->staging_directory,
                $this->target_directory,
                $session->get_session_id()
            );
            self::fail('Expected private work-directory symlink rejection.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('work directory must be a real directory', $exception->getMessage());
        }
    }

    /** Staging may neither contain nor live inside the apply target. */
    public function testStagingAndTargetMustBeSeparateDirectoryTrees(): void {
        $inside_target = $this->target_directory . '/staging';
        mkdir($inside_target);
        foreach ([$inside_target, $this->target_directory, $this->temporary_directory] as $staging_directory) {
            try {
                Site_Export_Staged_Apply_Session::create($staging_directory, $this->target_directory);
                self::fail('Expected overlapping staging and target trees to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must be separate directory trees', $exception->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($inside_target . '/apply-sessions');
    }

    /** The caller owns the staging root; session creation does not create it. */
    public function testStagingDirectoryMustAlreadyExist(): void {
        $missing_staging_directory = $this->temporary_directory . '/missing-staging';

        try {
            Site_Export_Staged_Apply_Session::create($missing_staging_directory, $this->target_directory);
            self::fail('Expected a missing staging directory to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('is not an existing directory', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($missing_staging_directory);
    }

    /** A new upload repeats the final complete journal change after a process cut. */
    public function testFinalJournalChangeIsMaterializedAgainAfterAProcessCut(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        $input = $this->requestBody([
            ['type' => 'directory', 'path' => 'a-earlier'],
            ['type' => 'directory', 'path' => 'b-final'],
        ]);
        $session->accept_upload($input);
        try {
            self::assertTrue($session->next_change());
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($input);
        }

        // b-final is the state left when the process stops after its journal
        // line is flushed but before the directory is created. a-earlier
        // proves that recovery does not replay the whole journal.
        rmdir($session_directory . '/work/files/a-earlier');
        rmdir($session_directory . '/work/files/b-final');

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->staging_directory,
            $this->target_directory,
            $session->get_session_id()
        );
        $empty_input = $this->requestBody([]);
        $reopened->accept_upload($empty_input);
        try {
            self::assertFalse($reopened->next_change());
        } finally {
            $reopened->finish_upload();
            fclose($empty_input);
        }

        self::assertDirectoryDoesNotExist($session_directory . '/work/files/a-earlier');
        self::assertDirectoryExists($session_directory . '/work/files/b-final');
        self::assertSame(base64_encode('b-final'), $reopened->get_status()['last_path_b64']);
        self::assertCount(2, file($session_directory . '/work/journal.jsonl', FILE_IGNORE_NEW_LINES));
    }

    /** Verifies symlinks cannot acquire staged descendants. */
    public function testSymlinkAncestorsRejectDescendantMaterialization(): void {
        $outside = $this->temporary_directory . '/outside';
        mkdir($outside);

        $session = $this->createSession();
        $input = $this->requestBody([
            ['type' => 'symlink', 'path' => 'node', 'target' => $outside],
            ['type' => 'directory', 'path' => 'node/child'],
        ]);
        $session->accept_upload($input);
        try {
            self::assertTrue($session->next_change());
            try {
                $session->next_change();
                self::fail('Expected descendant rejection below a staged symlink.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_INVALID_CHANGE, $exception->getCode());
                self::assertStringContainsString('non-directory staged ancestor', $exception->getMessage());
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        self::assertSame(base64_encode('node'), $session->get_status()['last_path_b64']);
        self::assertFileDoesNotExist($outside . '/child');

        $next_input = $this->requestBody([['type' => 'directory', 'path' => 'z-next']]);
        $session->accept_upload($next_input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($next_input);
        }
        self::assertSame(base64_encode('z-next'), $session->get_status()['last_path_b64']);
    }

    /** An incomplete journal tail is removed before the next change. */
    public function testIncompleteJournalTailIsRemovedBeforeTheNextChange(): void {
        $session = $this->createSession();
        $first_input = $this->requestBody([['type' => 'directory', 'path' => 'a']]);
        $session->accept_upload($first_input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($first_input);
        }
        $journal = $this->sessionDirectory($session) . '/work/journal.jsonl';
        file_put_contents($journal, '{uncommitted', FILE_APPEND);

        $next_input = $this->requestBody([['type' => 'directory', 'path' => 'b']]);
        $session->accept_upload($next_input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($next_input);
        }

        self::assertCount(2, file($journal, FILE_IGNORE_NEW_LINES));
        self::assertSame(base64_encode('b'), $session->get_status()['last_path_b64']);
    }

    /** Status ignores an incomplete journal tail without changing the journal. */
    public function testStatusReportsOnlyTheCompleteJournalPrefix(): void {
        $session = $this->createSession();
        $input = $this->requestBody([['type' => 'directory', 'path' => 'directory']]);
        $session->accept_upload($input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($input);
        }
        $journal = $this->sessionDirectory($session) . '/work/journal.jsonl';
        $complete_journal_bytes = filesize($journal);
        self::assertIsInt($complete_journal_bytes);
        self::assertNotFalse(file_put_contents($journal, '{unfinished', FILE_APPEND));

        self::assertSame(
            [
                'journal_bytes' => $complete_journal_bytes,
                'last_path_b64' => base64_encode('directory'),
            ],
            $session->get_status()
        );
        self::assertSame($complete_journal_bytes + strlen('{unfinished'), filesize($journal));
    }

    /** One upload holds the session until its caller finishes it. */
    public function testOpenUploadRejectsAnotherUploadUntilFinished(): void {
        $session = $this->createSession();
        $input = $this->requestBody([]);
        $session->accept_upload($input);
        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->staging_directory,
            $this->target_directory,
            $session->get_session_id()
        );
        $contending_input = $this->requestBody([]);
        try {
            try {
                $reopened->get_status();
                self::fail('Expected an open upload to block its status request.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
            }
            try {
                $reopened->accept_upload($contending_input);
                self::fail('Expected an open upload to hold the session lock.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
            }
        } finally {
            $session->finish_upload();
            fclose($input);
            fclose($contending_input);
        }
        $next_input = $this->requestBody([['type' => 'directory', 'path' => 'directory']]);
        $reopened->accept_upload($next_input);
        try {
            self::assertTrue($reopened->next_change());
            self::assertFalse($reopened->next_change());
        } finally {
            $reopened->finish_upload();
            fclose($next_input);
        }
        self::assertSame(base64_encode('directory'), $reopened->get_status()['last_path_b64']);
    }

    /** Verifies arbitrary path bytes remain base64-safe in the journal. */
    public function testRawNonUtf8PathRoundTripsThroughJournal(): void {
        $path = "raw-\xff";
        $probe = $this->target_directory . '/' . $path;
        if (@file_put_contents($probe, 'probe') === false) {
            self::markTestSkipped('This filesystem API does not accept a non-UTF-8 file name.');
        }
        unlink($probe);
        $session = $this->createSession();
        $input = $this->requestBody([['type' => 'directory', 'path' => $path]]);
        $session->accept_upload($input);
        try {
            self::assertTrue($session->next_change());
            self::assertFalse($session->next_change());
        } finally {
            $session->finish_upload();
            fclose($input);
        }

        self::assertSame(base64_encode($path), $session->get_status()['last_path_b64']);
        self::assertDirectoryExists($this->sessionDirectory($session) . '/work/files/' . $path);
        self::assertFileDoesNotExist($this->target_directory . '/' . $path);
    }

    /** Verifies filesystem errors encode arbitrary path bytes for JSON responses. */
    public function testRawNonUtf8PathIsEncodedInFilesystemFailure(): void {
        $path = str_repeat('a', 255) . "\xff/file";
        $session = $this->createSession();

        $input = $this->requestBody([['type' => 'directory', 'path' => $path]]);
        $session->accept_upload($input);
        try {
            try {
                $session->next_change();
                self::fail('Expected the overlong filesystem segment to fail materialization.');
            } catch (RuntimeException $exception) {
                self::assertSame(Site_Export_Staged_Apply_Session::ERROR_RETRYABLE_IO, $exception->getCode());
                self::assertStringContainsString('base64:', $exception->getMessage());
                self::assertStringNotContainsString("\xff", $exception->getMessage());
                self::assertNotFalse(json_encode(['error' => $exception->getMessage()]));
            }
        } finally {
            $session->finish_upload();
            fclose($input);
        }
    }

    /**
     * Builds one framed request body from raw change values.
     *
     * @param array<int,array<string,mixed>> $changes
     * @return resource
     */
    private function requestBody(array $changes) {
        $input = fopen('php://temp', 'w+b');
        if ($input === false) {
            throw new RuntimeException('Could not create staged apply request-body test stream.');
        }
        foreach ($changes as $change) {
            if (fwrite($input, Site_Export_Staged_Push_Stream_Protocol::encode_apply_change_header($change)) === false) {
                throw new RuntimeException('Could not write staged apply request-body test stream.');
            }
        }
        rewind($input);
        return $input;
    }

    /** Creates a session in this test's isolated roots. */
    private function createSession(): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->staging_directory,
            $this->target_directory
        );
    }

    /** Returns the filesystem directory owned by a test session. */
    private function sessionDirectory(Site_Export_Staged_Apply_Session $session): string {
        return $this->staging_directory . '/apply-sessions/' . $session->get_session_id();
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
