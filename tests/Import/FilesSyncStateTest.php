<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test files-pull state transitions and preserve-local diff behavior.
 *
 * A completed files-pull should refuse to re-run without --abort.
 * After --abort, the next run should start fresh (not "already complete").
 * In preserve-local mode, previously-synced files that changed remotely
 * must still be re-downloaded (not skipped).
 */
class FilesSyncStateTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-state-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fs_root, 0755, true);
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
        return new \ImportClient('http://fake.url', $this->stateDir, $this->fs_root);
    }

    /**
     * Write a state file directly.
     */
    private function writeState(array $state): void
    {
        $defaults = [
            "active_resumable_command" => [
                "command_name" => null,
                "completion_state" => null,
                "current_stage" => null,
                "remote_cursor" => null,
            ],
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    /**
     * Read the current state file.
     */
    private function readState(): array
    {
        $contents = file_get_contents($this->stateDir . '/.import-state.json');
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
     * Read the download list file and return the list of paths.
     */
    private function readDownloadList(): array
    {
        $file = $this->stateDir . '/.import-download-list.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

        return [$client, $reflection];
    }

    // ---------------------------------------------------------------
    // State transition tests
    // ---------------------------------------------------------------

    /**
     * A completed files-pull should refuse to re-run.
     */
    public function testCompletedFilesSyncRefusesToRerun()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('run_files_sync');
        $method->invoke($client);

        $state = $this->readState();
        $this->assertEquals("complete", $state["active_resumable_command"]["completion_state"]);
        $this->assertEquals("files-pull", $state["active_resumable_command"]["command_name"]);
    }

    /**
     * The deferred skipped-files tail reopens a completed files-pull, and a
     * successful tail must restore the completed status before returning.
     */
    public function testSkippedEarlierTailRestoresCompletedStatus()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "filter" => "essential-files",
        ]);
        file_put_contents(
            $this->stateDir . '/.import-download-list-skipped.jsonl',
            json_encode([
                "path" => base64_encode('/wp-content/uploads/2024/01/photo.jpg'),
            ], JSON_UNESCAPED_SLASHES) . "\n",
        );

        $client = new CompletedFileFetchClient(
            'http://fake.url',
            $this->stateDir,
            $this->fs_root,
        );

        ob_start();
        $client->run([
            "command" => "files-pull",
            "filter" => "skipped-earlier",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertEquals("complete", $state["active_resumable_command"]["completion_state"]);
        $this->assertEquals("files-pull", $state["active_resumable_command"]["command_name"]);
        $this->assertNull($state["active_resumable_command"]["current_stage"]);
        $this->assertEquals("skipped-earlier", $state["filter"]);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    /**
     * After --abort, the state should not be "complete".
     */
    public function testAbortClearsCompletedStatus()
    {
        $indexFile = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($indexFile, $this->indexLine('/wp-login.php', 1000, 100));

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
        $indexFile = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($indexFile, $this->indexLine('/wp-login.php', 1000, 100));

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
            ],
        ]);

        // Step 1: abort
        [$client, $reflection] = $this->prepareClient();
        $reflection->getMethod('handle_abort')->invoke($client, 'files-pull');

        // Step 2: new client, try run_files_sync
        [$client2, $reflection2] = $this->prepareClient();

        try {
            $reflection2->getMethod('run_files_sync')->invoke($client2);
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

    /**
     * After a full `pull`, active_resumable_command points at the last stage
     * (db-apply), not files-pull, but pull_pipeline records a deferred tail. A
     * standalone skipped-earlier run must recover the completed files-pull and
     * fetch the tail rather than throwing "no completed sync with skipped
     * files".
     */
    public function testSkippedEarlierAfterCompositePullAdoptsFilesPullState(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-download-list-skipped.jsonl',
            $this->indexLine('/wp-content/uploads/2024/01/photo.jpg', 1000, 100),
        );
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-apply",
                "completion_state" => "complete",
            ],
            "filter" => "skipped-earlier",
            "pull_pipeline" => [
                "files_filter" => "essential-files",
                "skipped_pending" => true,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();
        $filterProp = $reflection->getProperty('filter');
        $filterProp->setValue($client, 'skipped-earlier');

        try {
            $reflection->getMethod('run_files_sync')->invoke($client);
        } catch (\Exception $e) {
            // Expected: the fetch fails against the fake URL. The point is that
            // it got PAST the "no completed sync with skipped files" guard.
            $this->assertStringNotContainsString(
                'no completed sync with skipped files',
                $e->getMessage(),
            );
        }

        // The checkpoint was restored to the completed files-pull and the
        // deferred-tail fetch started.
        $state = $this->readState();
        $this->assertSame('files-pull', $state["active_resumable_command"]["command_name"]);
        $this->assertSame('fetch-skipped', $state["active_resumable_command"]["current_stage"]);
    }

    // ---------------------------------------------------------------
    // Preserve-local diff tests
    // ---------------------------------------------------------------

    /**
     * In preserve-local mode, a file that is in the local index and changed
     * remotely (different ctime) must be added to the download list.
     *
     * Preserve-local protects pre-existing local files, not files we
     * previously synced. A changed file in the local index is ours to update.
     */
    public function testDeltaDiffRedownloadsChangedIndexedFile()
    {
        // Local index: file synced at ctime 1000
        $localIndex = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($localIndex, $this->indexLine('/wp-content/themes/flavor/style.css', 1000, 200));

        // Remote index: same file at ctime 2000 (changed)
        $remoteIndex = $this->stateDir . '/.import-remote-index.jsonl';
        file_put_contents($remoteIndex, $this->indexLine('/wp-content/themes/flavor/style.css', 2000, 250));

        // The file exists locally (downloaded during the initial sync)
        $localFile = $this->fs_root . '/wp-content/themes/flavor/style.css';
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

        $diffMethod = $reflection->getMethod('diff_indexes_and_build_fetch_list');
        $diffMethod->invoke($client);

        $downloads = $this->readDownloadList();
        $this->assertContains(
            '/wp-content/themes/flavor/style.css',
            $downloads,
            "A changed file in the local index must be re-downloaded, not skipped by preserve-local",
        );
    }

    /**
     * In preserve-local mode, a file that is NOT in the local index but
     * exists locally (pre-existing) must be skipped.
     */
    public function testDeltaDiffSkipsPreExistingLocalFile()
    {
        // Local index: empty (file was never synced by us)
        $localIndex = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($localIndex, '');

        // Remote index: file exists on remote
        $remoteIndex = $this->stateDir . '/.import-remote-index.jsonl';
        file_put_contents($remoteIndex, $this->indexLine('/wp-content/object-cache.php', 1000, 500));

        // The file exists locally (pre-existing, e.g. hosting drop-in)
        $localFile = $this->fs_root . '/wp-content/object-cache.php';
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

        $diffMethod = $reflection->getMethod('diff_indexes_and_build_fetch_list');
        $diffMethod->invoke($client);

        $downloads = $this->readDownloadList();
        $this->assertNotContains(
            '/wp-content/object-cache.php',
            $downloads,
            "A pre-existing local file not in the index must be skipped by preserve-local",
        );
    }

    /**
     * handle_file_chunk must overwrite an existing local file in
     * preserve-local mode when the file was placed in the download list
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
        $localFile = $this->fs_root . '/wp-content/themes/flavor/style.css';
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
        $context = new \StreamingContext();
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
            "Fetch stage must overwrite existing files that were placed in the download list",
        );
    }
}

/**
 * Test double that completes a file_fetch request without real network I/O.
 */
class CompletedFileFetchClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        ($context->on_chunk)([
            "headers" => [
                "x-chunk-type" => "completion",
                "x-status" => "complete",
            ],
            "body" => "",
        ]);
    }
}
