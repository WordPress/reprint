<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply-session.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-artifacts.php';

final class StagedApplyFileSessionTest extends TestCase {

    private string $temporary_directory;

    private string $storage_directory;

    private string $target_directory;

    /** Creates isolated storage and target roots for one test. */
    protected function setUp(): void {
        $this->temporary_directory = sys_get_temp_dir() . '/reprint-direct-file-' . bin2hex(random_bytes(8));
        $this->storage_directory = $this->temporary_directory . '/staging';
        $this->target_directory = $this->temporary_directory . '/target';
        mkdir($this->temporary_directory, 0700, true);
        mkdir($this->target_directory, 0700, true);
    }

    /** Removes the isolated filesystem tree after each test. */
    protected function tearDown(): void {
        $this->removeTree($this->temporary_directory);
    }

    /** Verifies a reopened session resumes from the target-confirmed file cursor. */
    public function testPartialFileResumesAfterReopen(): void {
        $session = $this->createSession();
        $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'large.bin', 4, 0, 'abc', 6, false);
        });

        self::assertSame('accepted', $result['status']);
        self::assertSame(0, $result['operation_count']);
        self::assertSame(3, $result['current_file']['committed_bytes']);

        $reopened = Site_Export_Staged_Apply_Session::open(
            $this->storage_directory,
            $this->target_directory,
            $session->get_session_id()
        );
        self::assertSame(3, $reopened->get_status()['current_file']['committed_bytes']);
        $result = $reopened->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'large.bin', 4, 3, 'def', 6, false);
        });

        self::assertSame(1, $result['operation_count']);
        self::assertNull($result['current_file']);
        self::assertSame('abcdef', file_get_contents($this->stagedFile($session, 'large.bin')));
        self::assertFileDoesNotExist($this->target_directory . '/large.bin');
        $journal_entry = json_decode(
            (string) file_get_contents($this->sessionDirectory($session) . '/work/operations.jsonl'),
            true
        );
        self::assertSame('file', $journal_entry['entry']['type']);
        self::assertSame(6, $journal_entry['entry']['bytes']);
    }

    /** Verifies duplicate, gap, and shifted-boundary retries use the store cursor. */
    public function testFileReplayUsesTheStoreCursor(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'abcd', 10, false);
        });

        $results = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return [
                $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'ab', 10, false),
                $upload->accept_file_chunk(0, 'file.bin', 1, 7, 'x', 10, false),
                $upload->accept_file_chunk(0, 'file.bin', 1, 2, 'cdefgh', 10, false),
                $upload->accept_file_chunk(0, 'file.bin', 1, 8, 'ij', 10, false),
            ];
        });

        self::assertSame('duplicate', $results[0]['status']);
        self::assertSame('offset_gap', $results[1]['reason']);
        self::assertSame(8, $results[2]['current_file']['committed_bytes']);
        self::assertSame(1, $results[3]['operation_count']);
        self::assertSame('abcdefghij', file_get_contents($this->stagedFile($session, 'file.bin')));
    }

    /** Verifies source changes require an explicit, newer offset-zero restart. */
    public function testRevisionChangesRequireExplicitRestart(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 2, 0, 'old', 6, false);
        });

        $mismatch = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 3, 0, 'new', 3, false);
        });
        self::assertSame('revision_mismatch', $mismatch['reason']);
        self::assertSame('old', file_get_contents($this->stagedFile($session, 'file.bin')));

        $size_mismatch = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 2, 0, 'old', 7, false);
        });
        self::assertSame('size_mismatch', $size_mismatch['reason']);
        self::assertSame('old', file_get_contents($this->stagedFile($session, 'file.bin')));

        $stale = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'bad', 3, true);
        });
        self::assertSame('stale_revision', $stale['reason']);
        self::assertSame('old', file_get_contents($this->stagedFile($session, 'file.bin')));

        $restarted = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 3, 0, 'new', 3, true);
        });
        self::assertSame(1, $restarted['operation_count']);
        self::assertSame('new', file_get_contents($this->stagedFile($session, 'file.bin')));
    }

    /** Verifies explicit restart replaces same-size, growing, and shrinking files. */
    public function testExplicitRestartReplacesChangedFileSizes(): void {
        $cases = [
            'same size' => ['old', 6, 'newest'],
            'growing' => ['old', 6, 'new-longer'],
            'shrinking' => ['old-data', 12, 'new'],
        ];

        foreach ($cases as $label => [$old_payload, $old_total_bytes, $new_payload]) {
            $session = $this->createSession();
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($old_payload, $old_total_bytes): void {
                $upload->accept_file_chunk(0, 'file.bin', 1, 0, $old_payload, $old_total_bytes, false);
            });
            $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload) use ($new_payload): array {
                return $upload->accept_file_chunk(0, 'file.bin', 2, 0, $new_payload, strlen($new_payload), true);
            });

            self::assertSame(1, $result['operation_count'], $label);
            self::assertSame($new_payload, file_get_contents($this->stagedFile($session, 'file.bin')), $label);
        }
    }

    /** Verifies retrying a restart is safe after old bytes were already discarded. */
    public function testRestartRetriesAfterArtifactDiscard(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'old', 6, false);
        });
        $store = new Site_Export_Staged_Artifacts($this->sessionDirectory($session) . '/work');

        // Simulate a cut after restart discarded old bytes but before the
        // session recorded the new revision.
        self::assertTrue($store->discard('file.bin'));
        $status = $session->get_status()['current_file'];
        self::assertSame(1, $status['revision']);
        self::assertSame(0, $status['committed_bytes']);

        $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 2, 0, 'new', 3, true);
        });
        self::assertSame(1, $result['operation_count']);
        self::assertSame('new', file_get_contents($this->stagedFile($session, 'file.bin')));
    }

    /** Verifies damaged bytes stop until a later explicit restart. */
    public function testDamagedStagingRequiresExplicitRestart(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 0, 0, 'first', 11, false);
        });
        $staged_file = $this->stagedFile($session, 'file.bin');
        file_put_contents($staged_file, 'fi');

        $damaged_status = $session->get_status()['current_file'];
        self::assertSame(0, $damaged_status['committed_bytes']);
        self::assertSame('staging_file_shorter_than_cursor', $damaged_status['damage']);
        self::assertSame(5, $damaged_status['recorded_committed_bytes']);

        $damaged = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 0, 5, 'second', 11, false);
        });
        self::assertSame('rejected', $damaged['status']);
        self::assertSame('staging_file_damaged', $damaged['reason']);
        self::assertSame('uploading', $session->get_status()['phase']);
        self::assertSame('fi', file_get_contents($staged_file));

        $restarted = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 0, 0, 'firstsecond', 11, true);
        });
        self::assertSame(1, $restarted['operation_count']);
        self::assertSame('firstsecond', file_get_contents($staged_file));
    }

    /** Verifies an empty file completes without inventing a payload. */
    public function testZeroByteFileCompletes(): void {
        $session = $this->createSession();
        $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'empty', 0, 0, '', 0, false);
        });

        self::assertSame(1, $result['operation_count']);
        self::assertSame(0, filesize($this->stagedFile($session, 'empty')));
    }

    /** Verifies a full store cursor can finish the journal after a process cut. */
    public function testFullCursorCompletesTheJournalOnRetry(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'abc', 6, false);
        });
        $store = new Site_Export_Staged_Artifacts($this->sessionDirectory($session) . '/work');
        self::assertSame('accepted', $store->append('file.bin', 3, 'def')['status']);

        $result = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'file.bin', 1, 3, 'def', 6, false);
        });
        self::assertSame(1, $result['operation_count']);
        self::assertSame('abcdef', file_get_contents($this->stagedFile($session, 'file.bin')));
    }

    /** Verifies a current file rejects another operation at the same index. */
    public function testCurrentFileRejectsAnotherOperationShape(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_file_chunk(0, 'file.bin', 1, 0, 'abc', 6, false);
        });

        $file = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_file_chunk(0, 'other.bin', 1, 0, 'abc', 6, false);
        });
        self::assertSame('operation_mismatch', $file['reason']);

        $directory = $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): array {
            return $upload->accept_directory(0, 'other');
        });
        self::assertSame('operation_mismatch', $directory['reason']);
        self::assertSame('abc', file_get_contents($this->stagedFile($session, 'file.bin')));
    }

    /** Verifies a held artifact lock is retryable and does not fail the session. */
    public function testArtifactLockContentionIsRetryable(): void {
        $session = $this->createSession();
        $session_directory = $this->sessionDirectory($session);
        $artifact_lock = fopen($session_directory . '/work/lock', 'c+b');
        self::assertIsResource($artifact_lock);
        self::assertTrue(flock($artifact_lock, LOCK_EX | LOCK_NB));
        try {
            $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
                $upload->accept_file_chunk(0, 'file.bin', 0, 0, 'data', 4, false);
            });
            self::fail('Expected held artifact lock contention.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
        } finally {
            flock($artifact_lock, LOCK_UN);
            fclose($artifact_lock);
        }

        self::assertSame('uploading', $session->get_status()['phase']);
        self::assertSame(0, $session->get_status()['current_file']['committed_bytes']);
    }

    /** Creates a session in this test's isolated roots. */
    private function createSession(): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage_directory,
            $this->target_directory
        );
    }

    /** Returns the filesystem directory owned by a test session. */
    private function sessionDirectory(Site_Export_Staged_Apply_Session $session): string {
        return $this->storage_directory . '/apply-sessions/' . $session->get_session_id();
    }

    /** Returns one staged file path. */
    private function stagedFile(Site_Export_Staged_Apply_Session $session, string $path): string {
        return $this->sessionDirectory($session) . '/work/files/' . $path;
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
