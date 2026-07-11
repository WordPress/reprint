<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply-session.php';

final class StagedApplyDiscardTest extends TestCase {

    private string $temporary_directory;

    private string $storage_directory;

    private string $target_directory;

    /** Creates isolated storage and target roots for one test. */
    protected function setUp(): void {
        $this->temporary_directory = sys_get_temp_dir() . '/reprint-direct-discard-' . bin2hex(random_bytes(8));
        $this->storage_directory = $this->temporary_directory . '/staging';
        $this->target_directory = $this->temporary_directory . '/target';
        mkdir($this->temporary_directory, 0700, true);
        mkdir($this->target_directory, 0700, true);
    }

    /** Removes the isolated filesystem tree after each test. */
    protected function tearDown(): void {
        $this->removeTree($this->temporary_directory);
    }

    /** Verifies discard removes staged state without following staged links. */
    public function testDiscardRemovesOnlyTheSession(): void {
        $session = $this->createSession();
        $session->while_uploading(function (Site_Export_Staged_Apply_Session $upload): void {
            $upload->accept_directory(0, 'nested');
        });
        $outside_file = $this->temporary_directory . '/outside.txt';
        file_put_contents($outside_file, 'keep');
        symlink($outside_file, $this->sessionDirectory($session) . '/work/files/outside-link');

        self::assertTrue(Site_Export_Staged_Apply_Session::discard(
            $this->storage_directory,
            $session->get_session_id()
        ));

        self::assertDirectoryDoesNotExist($this->sessionDirectory($session));
        self::assertFileExists($outside_file);
        self::assertSame('keep', file_get_contents($outside_file));
        self::assertTrue(Site_Export_Staged_Apply_Session::discard(
            $this->storage_directory,
            $session->get_session_id()
        ));
    }

    /** Verifies each call removes a bounded number of entries and can resume. */
    public function testLargeDiscardFinishesAcrossCalls(): void {
        $session = $this->createSession();
        $many_files = $this->sessionDirectory($session) . '/work/files/many';
        mkdir($many_files, 0700, true);
        for ($index = 0; $index < 300; ++$index) {
            file_put_contents($many_files . '/' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT), 'x');
        }

        self::assertFalse(Site_Export_Staged_Apply_Session::discard(
            $this->storage_directory,
            $session->get_session_id()
        ));
        self::assertDirectoryDoesNotExist($this->sessionDirectory($session));
        $discarding_directory = $this->discardingDirectory($session);
        self::assertDirectoryExists($discarding_directory);
        $remaining_files = glob($discarding_directory . '/work/files/many/*');
        self::assertIsArray($remaining_files);
        self::assertNotEmpty($remaining_files);
        self::assertLessThan(300, count($remaining_files));

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            if (Site_Export_Staged_Apply_Session::discard($this->storage_directory, $session->get_session_id())) {
                self::assertDirectoryDoesNotExist($discarding_directory);
                return;
            }
        }
        self::fail('The bounded staged apply discard did not finish within five calls.');
    }

    /** Verifies discard refuses to race an active upload. */
    public function testDiscardReturnsBusyWhileTheSessionLockIsHeld(): void {
        $session = $this->createSession();
        $session_lock = fopen($this->sessionDirectory($session) . '/lock', 'r+b');
        self::assertIsResource($session_lock);
        self::assertTrue(flock($session_lock, LOCK_EX | LOCK_NB));
        try {
            Site_Export_Staged_Apply_Session::discard($this->storage_directory, $session->get_session_id());
            self::fail('Expected discard to reject a held session lock.');
        } catch (RuntimeException $exception) {
            self::assertSame(Site_Export_Staged_Apply_Session::ERROR_BUSY, $exception->getCode());
        } finally {
            flock($session_lock, LOCK_UN);
            fclose($session_lock);
        }

        self::assertDirectoryExists($this->sessionDirectory($session));
        self::assertDirectoryDoesNotExist($this->discardingDirectory($session));
    }

    /** Verifies a retry continues after the active directory was renamed. */
    public function testDiscardResumesFromTheTombstone(): void {
        $session = $this->createSession();
        self::assertTrue(rename($this->sessionDirectory($session), $this->discardingDirectory($session)));

        self::assertTrue(Site_Export_Staged_Apply_Session::discard(
            $this->storage_directory,
            $session->get_session_id()
        ));
        self::assertDirectoryDoesNotExist($this->discardingDirectory($session));

        $session = $this->createSession();
        $discarding_directory = $this->discardingDirectory($session);
        self::assertTrue(rename($this->sessionDirectory($session), $discarding_directory));
        $this->removeTree($discarding_directory . '/state.json');
        $this->removeTree($discarding_directory . '/work');
        $this->removeTree($discarding_directory . '/lock');

        // Simulate a cut after the lock was unlinked but before the empty
        // tombstone itself was removed.
        self::assertTrue(Site_Export_Staged_Apply_Session::discard(
            $this->storage_directory,
            $session->get_session_id()
        ));
        self::assertDirectoryDoesNotExist($discarding_directory);
    }

    /** Creates a session in this test's isolated roots. */
    private function createSession(): Site_Export_Staged_Apply_Session {
        return Site_Export_Staged_Apply_Session::create(
            $this->storage_directory,
            $this->target_directory
        );
    }

    /** Returns the active directory owned by a test session. */
    private function sessionDirectory(Site_Export_Staged_Apply_Session $session): string {
        return $this->storage_directory . '/apply-sessions/' . $session->get_session_id();
    }

    /** Returns the private tombstone used while deleting a session. */
    private function discardingDirectory(Site_Export_Staged_Apply_Session $session): string {
        return $this->storage_directory . '/apply-sessions/.discarding-' . $session->get_session_id();
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
