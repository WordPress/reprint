<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class AbortStateTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/abort-state-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->fileRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * @dataProvider commandStateProvider
     *
     * @param array<string> $removedArtifacts State-directory artifacts owned by the command.
     */
    public function testAbortClearsOnlyCommandState(
        string $command,
        array $removedArtifacts
    ): void {
        $client = $this->client();
        $before = $this->populatedState();
        \write_current_import_state($client, $before);

        $artifacts = [
            'pull/local-index.jsonl',
            'pull/remote-index.jsonl',
            'pull/fetch-list.jsonl',
            'pull/skipped-fetch-list.jsonl',
            'pull/volatile-files.json',
            'pull/sql-buffer',
            'db.sql',
            'db-tables.jsonl',
            'pull/domains.json',
        ];
        foreach ($artifacts as $artifact) {
            file_put_contents($this->stateDirectory . '/' . $artifact, "original\n");
        }

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_abort');
        $method->setAccessible(true);
        $method->invoke($client, $command);
        $loadState = $reflection->getMethod('load_state');
        $loadState->setAccessible(true);

        $this->assertSame(
            $this->expectedStateAfterAbort($before, $command),
            $loadState->invoke($client)->to_array()
        );
        foreach ($artifacts as $artifact) {
            $path = $this->stateDirectory . '/' . $artifact;
            if (in_array($artifact, $removedArtifacts, true)) {
                $this->assertFileDoesNotExist($path);
            } else {
                $this->assertFileExists($path);
            }
        }
    }

    public function testDbPullAbortRemovesSqlArtifactsAfterRestoringMysqlMode(): void
    {
        $client = $this->client();
        \write_current_import_state($client, [
            'active_resumable_command' => [
                'command_name' => 'db-pull',
                'completion_state' => 'partial',
                'current_stage' => 'sql',
                'remote_cursor' => 'remote-cursor',
            ],
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
            'sql_output' => 'mysql',
            'mysql_database' => 'stream_db',
        ]);
        $sqlFile = $this->stateDirectory . '/db.sql';
        $sqlBufferFile = $this->stateDirectory . '/pull/sql-buffer';
        file_put_contents($sqlFile, "stale dump\n");
        file_put_contents($sqlBufferFile, 'partial statement');

        $processLock = new \ReprintProcessLock($this->stateDirectory);
        try {
            $client->run([
                'command' => 'db-pull',
                'abort' => true,
            ], $processLock);
        } finally {
            $processLock->close();
        }

        $this->assertFileDoesNotExist($sqlFile);
        $this->assertFileDoesNotExist($sqlBufferFile);
    }

    /** @return array<string,array{string,array<string>}> */
    public static function commandStateProvider(): array
    {
        return [
            'files-pull' => [
                'files-pull',
                [
                    'pull/remote-index.jsonl',
                    'pull/fetch-list.jsonl',
                    'pull/skipped-fetch-list.jsonl',
                    'pull/volatile-files.json',
                ],
            ],
            'files-index' => [
                'files-index',
                ['pull/remote-index.jsonl'],
            ],
            'db-pull' => [
                'db-pull',
                [
                    'db.sql',
                    'db-tables.jsonl',
                    'pull/domains.json',
                    'pull/sql-buffer',
                ],
            ],
            'db-index' => [
                'db-index',
                ['db-tables.jsonl'],
            ],
            'db-apply' => [
                'db-apply',
                [],
            ],
        ];
    }

    /**
     * Populate every state group so replacing the whole ImportState cannot pass.
     *
     * @return array<string,mixed>
     */
    private function populatedState(): array
    {
        $state = ( new \ImportState() )->to_array();
        return array_replace_recursive($state, [
            'active_resumable_command' => [
                'command_name' => 'previous-command',
                'completion_state' => 'complete',
                'current_stage' => 'previous-stage',
                'remote_cursor' => 'remote-cursor',
            ],
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
            'remote_protocol_version' => 1,
            'version' => '0.9.3-dev',
            'webhost' => 'wpcloud',
            'follow_symlinks' => false,
            'local_followed_symlinks_root_fingerprint' => 'followed-root',
            'fs_root_nonempty_behavior' => 'preserve-local',
            'filter' => 'essential-files',
            'user_agent' => 'Reprint test',
            'max_allowed_packet' => 1048576,
            'resolved_path_mappings_fingerprint' => 'path-mappings',
            'files_pull_only_fingerprint' => 'only-files',
            'files_pull_summary' => [
                'files_pulled' => 42,
            ],
            'db_index' => [
                'file' => $this->stateDirectory . '/db-tables.jsonl',
                'tables' => 3,
                'rows_estimated' => 120,
                'bytes' => 2048,
                'updated_at' => '1234567890',
            ],
            'diff' => [
                'remote_offset' => 64,
                'local_after' => '/remote/wp-content',
            ],
            'index' => [
                'cursor' => 'file-index-cursor',
            ],
            'fetch' => [
                'offset' => 128,
                'next_offset' => 256,
                'batch_file' => $this->stateDirectory . '/fetch-batch.jsonl',
                'cursor' => 'fetch-cursor',
                'batch_entries' => 5,
            ],
            'fetch_skipped' => [
                'offset' => 512,
                'next_offset' => 1024,
                'batch_file' => $this->stateDirectory . '/skipped-batch.jsonl',
                'cursor' => 'skipped-cursor',
                'batch_entries' => 7,
            ],
            'current_file' => $this->fileRoot . '/wp-content/current.php',
            'current_file_bytes' => 4096,
            'sql_bytes' => 8192,
            'sql_statements_counted' => 99,
            'apply' => [
                'statements_executed' => 17,
                'bytes_read' => 16384,
                'rewrite_url' => ['https://source.example' => 'https://local.example'],
                'target_engine' => 'sqlite',
                'target_db' => 'local_db',
                'target_host' => '127.0.0.1',
                'target_port' => 3307,
                'target_user' => 'local_user',
                'target_pass' => 'local_pass',
                'target_sqlite_path' => $this->fileRoot . '/database.sqlite',
                'remote_paths_removed_from_local_site' => ['wp-content/object-cache.php'],
            ],
            'sql_output' => 'mysql',
            'mysql_host' => 'database.example',
            'mysql_port' => 3308,
            'mysql_user' => 'stream_user',
            'mysql_database' => 'stream_db',
            'consecutive_interrupted_responses' => 4,
            'tuning' => [
                'config' => ['enabled' => true],
                'state' => ['file_chunk_bytes' => 2097152],
            ],
            'pull_pipeline' => [
                'started_by_command' => 'pull',
                'stage_sequence' => ['preflight', 'files-pull', 'db-pull'],
                'last_completed_stage' => 'files-pull',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
                'has_completed_once' => true,
            ],
        ]);
    }

    /**
     * Reset the state groups owned by the aborted command and leave the rest unchanged.
     *
     * @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    private function expectedStateAfterAbort(array $before, string $command): array
    {
        $expected = $before;
        $expected['active_resumable_command'] = ( new \ResumableCommandCheckpointState() )->to_array();
        $expected['active_resumable_command']['command_name'] = $command;
        $expected['consecutive_interrupted_responses'] = 0;

        switch ($command) {
            case 'files-pull':
                $expected['local_followed_symlinks_root_fingerprint'] = null;
                $expected['filter'] = 'none';
                $expected['files_pull_only_fingerprint'] = null;
                $expected['files_pull_summary'] = ( new \FilesPullSummaryState() )->to_array();
                $expected['diff'] = ( new \FileDiffProgressState() )->to_array();
                $expected['index'] = ( new \RemoteFileIndexCursorState() )->to_array();
                $expected['fetch'] = ( new \FetchListProgressState() )->to_array();
                $expected['fetch_skipped'] = ( new \FetchListProgressState() )->to_array();
                $expected['current_file'] = null;
                $expected['current_file_bytes'] = null;
                break;

            case 'files-index':
                $expected['index'] = ( new \RemoteFileIndexCursorState() )->to_array();
                break;

            case 'db-pull':
                $expected['db_index'] = ( new \DatabaseTableIndexState() )->to_array();
                $expected['sql_bytes'] = null;
                $expected['sql_statements_counted'] = 0;
                $expected['sql_output'] = null;
                $expected['mysql_host'] = null;
                $expected['mysql_port'] = null;
                $expected['mysql_user'] = null;
                $expected['mysql_database'] = null;
                break;

            case 'db-index':
                $expected['db_index'] = ( new \DatabaseTableIndexState() )->to_array();
                break;

            case 'db-apply':
                $expected['apply'] = ( new \DatabaseApplyCommandState() )->to_array();
                break;
        }

        return $expected;
    }

    private function client(): \ImportClient
    {
        return new \ImportClient(
            'https://example.com/?site-export-api',
            $this->stateDirectory,
            $this->fileRoot
        );
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
