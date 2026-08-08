<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Test files-pull state transitions and preserve-local diff behavior.
 *
 * A completed files-pull should refuse to re-run without --abort.
 * After --abort, the next run should start fresh (not "already complete").
 * In preserve-local mode, previously-synced files that changed remotely
 * must still be re-downloaded (not skipped).
 */
class FilesPullStateTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-state-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->pullStateDirectory =
            $this->stateDir . '/remotes/' . md5('http://fake.url') . '/pull';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->pullStateDirectory, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root);
    }

    /**
     * Write a state file directly.
     */
    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), array_replace_recursive([
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "files_pull_intent" => "copy-changes",
        ], $state));
    }

    /**
     * Read the current state file.
     */
    private function readState(): array
    {
        $contents = file_get_contents($this->pullStateDirectory . '/state.json');
        return json_decode($contents, true);
    }

    /**
     * Build a sorted index line from a path, ctime, size, and type.
     */
    private function indexLine(string $path, int $ctime, int $size, string $type = "file"): string
    {
        return json_encode([
            "path" => base64_encode($path),
            "ctime" => $ctime,
            "size" => $size,
            "type" => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Read the fetch list file and return the list of paths.
     */
    private function readFetchList(): array
    {
        $fetchListFilePath = $this->pullStateDirectory . '/fetch-list.jsonl';
        if (!file_exists($fetchListFilePath)) {
            return [];
        }
        $paths = [];
        foreach (file($fetchListFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data["path"])) {
                $paths[] = base64_decode($data["path"]);
            }
        }
        return $paths;
    }

    /**
     * Set up a client with state loaded and preserve-local mode.
     */
    private function prepareClient(): array
    {
        $client = $this->makeClient();
        $reflection = new \ReflectionClass($client);

        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        $behaviorProp = $reflection->getProperty('fs_root_nonempty_behavior');
        $behaviorProp->setValue($client, 'preserve-local');

        $intentProperty = $reflection->getProperty('files_pull_intent');
        $intentProperty->setValue($client, 'copy-changes');

        return [$client, $reflection];
    }

    // ---------------------------------------------------------------
    // State transition tests
    // ---------------------------------------------------------------

    /**
     * A completed files-pull should refuse to re-run.
     */
    public function testCompletedFilesPullRefusesToRerun()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('run_files_pull');
        $method->invoke($client);

        $state = $this->readState();
        $this->assertEquals("complete", $state["active_resumable_command"]["completion_state"]);
        $this->assertEquals("files-pull", $state["active_resumable_command"]["command_name"]);
    }

    /**
     * After --abort, the state should not be "complete".
     */
    public function testAbortClearsCompletedStatus()
    {
        $remoteIndexFile = $this->pullStateDirectory . '/remote-index.jsonl';
        file_put_contents($remoteIndexFile, $this->indexLine('/wp-login.php', 1000, 100));

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $abortMethod = $reflection->getMethod('handle_abort');
        $abortMethod->invoke($client, 'files-pull');

        $state = $this->readState();
        $this->assertNotEquals(
            "complete",
            $state["active_resumable_command"]["completion_state"] ?? null,
            "After abort, resumable command completion state must not be 'complete' — " .
            "the next run should start fresh",
        );
    }

    /**
     * Full abort→re-run cycle: the next run should start fresh, not
     * report "already complete".
     */
    public function testAbortThenRerunStartsFresh()
    {
        $remoteIndexFile = $this->pullStateDirectory . '/remote-index.jsonl';
        file_put_contents($remoteIndexFile, $this->indexLine('/wp-login.php', 1000, 100));

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
            ],
        ]);

        // Step 1: abort
        [$client, $reflection] = $this->prepareClient();
        $reflection->getMethod('handle_abort')->invoke($client, 'files-pull');

        // Step 2: new client, try run_files_pull
        [$client2, $reflection2] = $this->prepareClient();

        try {
            $reflection2->getMethod('run_files_pull')->invoke($client2);
        } catch (\Exception $e) {
            // Expected: will fail trying to contact the fake URL
        }

        $state = $this->readState();
        $this->assertNotEquals(
            "complete",
            $state["active_resumable_command"]["completion_state"],
            "After abort + re-run, the sync should start fresh, not report 'already complete'",
        );
        $this->assertEquals("files-pull", $state["active_resumable_command"]["command_name"]);
    }

    // ---------------------------------------------------------------
    // Preserve-local diff tests
    // ---------------------------------------------------------------

    /**
     * In preserve-local mode, a file that is in the remote index and changed
     * remotely (different ctime) must be added to the fetch list.
     *
     * Preserve-local protects pre-existing local files, not files we
     * previously synced. A changed file in the remote index is ours to update.
     */
    public function testDeltaDiffRedownloadsChangedIndexedFile()
    {
        // Remote index: file synced at ctime 1000
        $remoteIndexFile = $this->pullStateDirectory . '/remote-index.jsonl';
        file_put_contents($remoteIndexFile, $this->indexLine('/wp-content/themes/flavor/style.css', 1000, 200));

        // Next remote index: same file at ctime 2000 (changed)
        $nextRemoteIndexFile = $this->pullStateDirectory . '/remote-index.next.jsonl';
        file_put_contents($nextRemoteIndexFile, $this->indexLine('/wp-content/themes/flavor/style.css', 2000, 250));

        // The file exists locally (downloaded during the initial sync)
        $localFile = $this->filesystem_root . '/wp-content/themes/flavor/style.css';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'old content');

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "diff",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $diffMethod = $reflection->getMethod('compare_remote_indexes_and_build_fetch_list');
        $diffMethod->invoke($client);

        $downloads = $this->readFetchList();
        $this->assertContains(
            '/wp-content/themes/flavor/style.css',
            $downloads,
            "A changed file in the remote index must be re-downloaded, not skipped by preserve-local",
        );
    }

    /**
     * In preserve-local mode, a file that is NOT in the remote index but
     * exists locally (pre-existing) must be skipped.
     */
    public function testDeltaDiffSkipsPreExistingLocalFile()
    {
        // Remote index: empty (file was never synced by us)
        $remoteIndexFile = $this->pullStateDirectory . '/remote-index.jsonl';
        file_put_contents($remoteIndexFile, '');

        // Next remote index: file exists on remote
        $nextRemoteIndexFile = $this->pullStateDirectory . '/remote-index.next.jsonl';
        file_put_contents($nextRemoteIndexFile, $this->indexLine('/wp-content/object-cache.php', 1000, 500));

        // The file exists locally (pre-existing, e.g. hosting drop-in)
        $localFile = $this->filesystem_root . '/wp-content/object-cache.php';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'local drop-in');

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "diff",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $diffMethod = $reflection->getMethod('compare_remote_indexes_and_build_fetch_list');
        $diffMethod->invoke($client);

        $downloads = $this->readFetchList();
        $this->assertNotContains(
            '/wp-content/object-cache.php',
            $downloads,
            "A pre-existing local file not in the index must be skipped by preserve-local",
        );
    }

    /**
     * handle_file_chunk must overwrite an existing local file in
     * preserve-local mode when the file was placed in the fetch list
     * by the diff stage (i.e., it's a file we previously synced that
     * changed remotely).
     *
     * This is the fetch-stage counterpart to testDeltaDiffRedownloadsChangedIndexedFile.
     * The diff stage decides what to download; the fetch stage must not
     * second-guess that decision.
     */
    public function testFetchStageOverwritesPreviouslySyncedFile()
    {
        // Create the file locally (simulates a prior sync)
        $localFile = $this->filesystem_root . '/wp-content/themes/flavor/style.css';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'old content');

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        // Send a file chunk with new content
        $method = $reflection->getMethod('handle_file_chunk');
        $context = new StreamingContext();
        $chunk = [
            'headers' => [
                'x-file-path' => base64_encode('/wp-content/themes/flavor/style.css'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '2000',
                'x-file-size' => '11',
            ],
            'body' => 'new content',
        ];

        $method->invoke($client, $chunk, $context);

        if ($context->file_handle) {
            fclose($context->file_handle);
        }

        $this->assertEquals(
            'new content',
            file_get_contents($localFile),
            "Fetch stage must overwrite existing files that were placed in the fetch list",
        );
    }
}
