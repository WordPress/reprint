<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class PullFilterFakeClient extends \ImportClient
{
    public int $files_pulled = 0;
    public int $preflight_runs = 0;
    public int $files_pull_runs = 0;
    public int $db_sync_runs = 0;
    public int $db_apply_runs = 0;
    public int $database_apply_signal_enables = 0;
    public int $command_signal_restores = 0;
    public bool $db_apply_stops_partial_once = false;
    public bool $db_apply_throws = false;
    public array $progress_events = [];
    public array $progress_file_errors = [];

    /** @var resource|null */
    private $terminal_progress_stream = null;

    public function __construct(string $state_dir, string $filesystem_root)
    {
        parent::__construct('http://fake.invalid', $state_dir, $filesystem_root);
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->progress_events[] = $data;
    }

    public function write_progress_file(?string $error = null): void
    {
        $this->progress_file_errors[] = $error;
    }

    public function captureTerminalProgress(): void
    {
        $ref = new \ReflectionClass(\ImportClient::class);
        $property = $ref->getProperty('progress');
        $property->setAccessible(true);
        $progress = $property->getValue($this);

        $this->terminal_progress_stream = fopen('php://temp', 'w+');
        $progress->set_terminal_output_enabled(true);
        $progress->set_progress_fd($this->terminal_progress_stream);
    }

    public function terminalProgressOutput(): string
    {
        if ($this->terminal_progress_stream === null) {
            return '';
        }

        rewind($this->terminal_progress_stream);
        $output = stream_get_contents($this->terminal_progress_stream);
        return $output === false ? '' : $output;
    }

    public function remote_index_entry_count(): int
    {
        return 12;
    }

    public function run_preflight(): void
    {
        $this->preflight_runs++;
        $state = $this->get_state();
        $state->set_preflight_record([
            "http_code" => 200,
            "data" => [
                "ok" => true,
                "database" => [
                    "wp" => [
                        "wp_version" => "6.8",
                        "paths_urls" => [
                            "content_dir" => "/var/www/html/wp-content",
                            "uploads" => [
                                "basedir" => "/var/www/html/wp-content/uploads",
                            ],
                        ],
                    ],
                ],
                "runtime" => [
                    "phpversion" => "8.2",
                ],
            ],
        ]);
        $state->active_resumable_command->completion_state = "complete";
        $this->save_state();
    }

    public function run_files_pull(): void
    {
        $state = $this->get_state();
        if (
            ($state->active_resumable_command->command_name ?? null) === "files-pull" &&
            ($state->active_resumable_command->completion_state ?? null) === "complete"
        ) {
            return;
        }

        $this->files_pull_runs++;

        $state = $this->get_state();
        $state->active_resumable_command->command_name = "files-pull";
        $state->active_resumable_command->completion_state = "complete";
        $state->active_resumable_command->current_stage = null;
        $state->files_pull_summary->files_pulled = $this->files_pulled;
        $this->save_state();
    }

    public function run_db_sync(): void
    {
        $this->db_sync_runs++;
        file_put_contents($this->state_dir . '/db.sql', "SELECT 1;\n");
        $state = $this->get_state();
        $state->active_resumable_command->command_name = "db-pull";
        $state->active_resumable_command->completion_state = "complete";
        $state->active_resumable_command->current_stage = null;
        $this->save_state();
    }

    public function run_db_apply(array $options): void
    {
        $this->db_apply_runs++;
        if ($this->db_apply_throws) {
            throw new \RuntimeException('Injected db-apply failure.');
        }
        $state = $this->get_state();
        $state->active_resumable_command->command_name = "db-apply";
        if ($this->db_apply_stops_partial_once) {
            $this->db_apply_stops_partial_once = false;
            $state->active_resumable_command->completion_state = "partial";
        } else {
            $state->active_resumable_command->completion_state = "complete";
        }
        $state->active_resumable_command->current_stage = null;
        $state->apply->statements_executed = 42;
        $this->save_state();
    }

    public function enable_database_apply_signal_handling(): void
    {
        ++$this->database_apply_signal_enables;
    }

    public function restore_command_signal_handling(): void
    {
        ++$this->command_signal_restores;
    }
}

class PullFailingPreflightFakeClient extends PullFilterFakeClient
{
    public function run_preflight(): void
    {
        $this->preflight_runs++;
        $this->last_error_code = 'HTTP_ERROR';
        $state = $this->get_state();
        $state->set_preflight_record([
            "http_code" => 500,
            "error" => "Exporter unavailable",
        ]);
        $state->active_resumable_command->completion_state = "complete";
        $this->save_state();
    }
}

/**
 * Fake client that records the options the pull pipeline hands to
 * apply-runtime, so we can assert the flatten_to -> flat_document_root
 * bridge. flat-docroot is stubbed to a no-op.
 */
class PullBridgeFakeClient extends PullFilterFakeClient
{
    public ?array $apply_runtime_options = null;

    public function run_flat_document_root(array $options): void
    {
    }

    public function run_apply_runtime(array $options): void
    {
        $this->apply_runtime_options = $options;
    }
}

/**
 * Tests for pull-level file filtering.
 */
class PullFilterOptionTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pull-filter-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $remoteReprintApiUrl = 'http://fake.invalid';
        $this->pullStateDirectory =
            $this->stateDir
            . '/remotes/'
            . md5(rtrim($remoteReprintApiUrl, '?&'))
            . '/pull';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
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

    private function makeClient(): PullFilterFakeClient
    {
        return new PullFilterFakeClient($this->stateDir, $this->filesystem_root);
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->pullStateDirectory . '/state.json'),
            true,
        );
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), $state);
    }

    public function testPullRejectsSkippedEarlierFilterBeforePersistingIt(): void
    {
        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "filter" => "skipped-earlier",
                "runtime" => "none",
            ]);
            $this->fail('Expected pull --filter=skipped-earlier to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Invalid --filter value for pull',
                $e->getMessage(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($this->pullStateDirectory . '/state.json');
    }

    public function testPullDoesNotAdvancePastFailedPreflight(): void
    {
        $client = new PullFailingPreflightFakeClient($this->stateDir, $this->filesystem_root);

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "runtime" => "none",
            ]);
            $this->fail('Expected pull to stop on failed preflight');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Exporter unavailable', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(0, $client->files_pull_runs);
        $this->assertNull($state["pull_pipeline"]["last_completed_stage"]);

        $error_events = array_values(array_filter(
            $client->progress_events,
            static fn (array $event): bool => ($event["status"] ?? null) === "error",
        ));
        $this->assertCount(1, $error_events);
        $this->assertSame("preflight", $error_events[0]["failed_stage"]);
        $this->assertSame("Exporter unavailable", $error_events[0]["message"]);
        $progress_file_errors = array_values(array_filter(
            $client->progress_file_errors,
            static fn ($error): bool => $error !== null,
        ));
        $this->assertSame(["Exporter unavailable"], $progress_file_errors);
    }

    public function testPullResumesAfterFilesPullCompletedBeforePipelineStageWasMarked(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull",
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullResumesSameUnfinishedPipelineWithoutConflict(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull",
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->files_pull_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullRefusesToClearCompletedCommandOwnedByDifferentUnfinishedPipeline(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull-db",
                "last_completed_stage" => null,
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);
        file_put_contents($this->stateDir . '/db.sql', "SELECT 1;\n");

        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "runtime" => "none",
            ]);
            $this->fail('Expected pull to refuse a different unfinished pipeline');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Another command is already in progress: pull-db', $e->getMessage());
            $this->assertStringContainsString('Rerun pull-db to resume it', $e->getMessage());
            $this->assertStringContainsString('Only use --abort if you want to discard', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $state = $this->readState();
        $this->assertSame('pull-db', $state["pull_pipeline"]["started_by_command"]);
        $this->assertNull($state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('db-pull', $state["active_resumable_command"]["command_name"]);
        $this->assertSame('complete', $state["active_resumable_command"]["completion_state"]);
        $this->assertFileExists($this->stateDir . '/db.sql');
    }

    public function testInvalidPullOptionsFailBeforeStateIsPersisted(): void
    {
        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "runtime" => "not-a-runtime",
            ]);
            $this->fail('Expected pull to reject an invalid runtime');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid --runtime value', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($this->pullStateDirectory . '/state.json');
    }

    public function testPullFilesSummaryReportsNoChangedFilesPulled(): void
    {
        $client = $this->makeClient();
        $client->captureTerminalProgress();

        $client->run([
            "command" => "pull-files",
            "progress" => "tty",
        ]);

        $output = $client->terminalProgressOutput();
        $this->assertStringContainsString('0 changed files pulled', $output);
        $this->assertStringNotContainsString('pull scope compared', $output);
    }

    public function testPullFilesSummaryReportsChangedFilePulled(): void
    {
        $client = $this->makeClient();
        $client->files_pulled = 1;
        $client->captureTerminalProgress();

        $client->run([
            "command" => "pull-files",
            "progress" => "tty",
            "only" => ["/var/www/html/wp-content/uploads/reprint-demo"],
        ]);

        $this->assertStringContainsString(
            '1 changed file pulled',
            $client->terminalProgressOutput(),
        );
    }

    public function testPullFilesRunsOnlyPreflightAndFilesStages(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull-files",
            "filter" => "essential-files",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(1, $client->files_pull_runs);
        $this->assertSame(0, $client->db_sync_runs);
        $this->assertSame(0, $client->db_apply_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('essential-files', $state["filter"]);
    }

    public function testPullFilesDoesNotAdvancePastFailedPreflight(): void
    {
        $client = new PullFailingPreflightFakeClient($this->stateDir, $this->filesystem_root);

        try {
            ob_start();
            $client->run(["command" => "pull-files"]);
            $this->fail('Expected pull-files to stop on failed preflight');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Exporter unavailable', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(0, $client->files_pull_runs);
        $this->assertNull($state["pull_pipeline"]["last_completed_stage"]);

        $error_events = array_values(array_filter(
            $client->progress_events,
            static fn (array $event): bool => ($event["status"] ?? null) === "error",
        ));
        $this->assertCount(1, $error_events);
        $this->assertSame("preflight", $error_events[0]["failed_stage"]);
        $this->assertSame("Exporter unavailable", $error_events[0]["message"]);
    }

    public function testPullFilesResumesAfterFilesPullCompletedBeforePipelineStageWasMarked(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull-files",
                "stage_sequence" => ["preflight", "files-pull"],
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('files-pull', $state["active_resumable_command"]["command_name"]);
    }

    public function testPullFilesAfterStandaloneFilesPullStartsFreshDelta(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->files_pull_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullAfterFilesPullCompletedBeforePipelineStageWasMarkedDoesNotStealIt(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull-files",
                "stage_sequence" => ["preflight", "files-pull"],
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "runtime" => "none",
            ]);
            $this->fail('Expected pull to reject an in-progress pull-files pipeline');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Another command is already in progress: pull-files', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame(0, $client->db_sync_runs);
    }

    public function testRerunningCompletedPullFilesStartsFreshFilesDelta(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run(["command" => "pull-files"]);
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_pull_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullAfterCompletedPullFilesStartsFreshFilesDelta(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run(["command" => "pull-files"]);
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_pull_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullDbRunsPreflightDownloadAndApplyStages(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame('pull-db', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('db-apply', $state["active_resumable_command"]["command_name"]);
        $this->assertSame(42, $state["apply"]["statements_executed"]);
    }

    public function testPullDbReturnsAfterAPartialApplyWithoutAdvancingThePipeline(): void
    {
        $client = $this->makeClient();
        $client->db_apply_stops_partial_once = true;

        ob_start();
        $client->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->exit_code);
        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame(1, $client->database_apply_signal_enables);
        $this->assertSame(1, $client->command_signal_restores);
        $this->assertSame('db-pull', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('db-apply', $state["active_resumable_command"]["command_name"]);
        $this->assertSame('partial', $state["active_resumable_command"]["completion_state"]);

        $resumedClient = $this->makeClient();
        ob_start();
        $resumedClient->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $completedState = $this->readState();
        $this->assertSame(0, $resumedClient->exit_code);
        $this->assertSame(1, $resumedClient->db_apply_runs);
        $this->assertSame(1, $resumedClient->database_apply_signal_enables);
        $this->assertSame(1, $resumedClient->command_signal_restores);
        $this->assertSame('db-apply', $completedState["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('complete', $completedState["active_resumable_command"]["completion_state"]);
    }

    public function testDatabaseApplySignalScopeRestoresTheWrapperPolicy(): void
    {
        if (
            !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_sigprocmask')
        ) {
            $this->markTestSkipped('The signal policy test requires PCNTL signal APIs.');
        }

        $originalAsyncSignals = pcntl_async_signals();
        $originalSigintHandler = pcntl_signal_get_handler(SIGINT);
        $originalSigtermHandler = pcntl_signal_get_handler(SIGTERM);
        pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $originalSignalMask);
        pcntl_sigprocmask(SIG_SETMASK, $originalSignalMask);

        try {
            $client = new \ImportClient(
                'http://fake.invalid',
                $this->stateDir,
                $this->filesystem_root,
                'pull-db',
            );
            $client->enable_database_apply_signal_handling();

            $this->assertFalse(pcntl_async_signals());
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $databaseApplySignalMask);
            pcntl_sigprocmask(SIG_SETMASK, $databaseApplySignalMask);
            $this->assertContains(SIGINT, $databaseApplySignalMask);
            $this->assertContains(SIGTERM, $databaseApplySignalMask);
            $databaseApplySigintHandler = pcntl_signal_get_handler(SIGINT);
            $databaseApplySigtermHandler = pcntl_signal_get_handler(SIGTERM);
            $this->assertIsArray($databaseApplySigintHandler);
            $this->assertSame($client, $databaseApplySigintHandler[0]);
            $this->assertSame(
                'handle_database_apply_shutdown',
                $databaseApplySigintHandler[1],
            );
            $this->assertIsArray($databaseApplySigtermHandler);
            $this->assertSame($client, $databaseApplySigtermHandler[0]);
            $this->assertSame(
                'handle_database_apply_shutdown',
                $databaseApplySigtermHandler[1],
            );

            $client->restore_command_signal_handling();

            $this->assertTrue(pcntl_async_signals());
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $restoredSignalMask);
            pcntl_sigprocmask(SIG_SETMASK, $restoredSignalMask);
            $this->assertNotContains(SIGINT, $restoredSignalMask);
            $this->assertNotContains(SIGTERM, $restoredSignalMask);
            $restoredSigintHandler = pcntl_signal_get_handler(SIGINT);
            $restoredSigtermHandler = pcntl_signal_get_handler(SIGTERM);
            $this->assertIsArray($restoredSigintHandler);
            $this->assertSame($client, $restoredSigintHandler[0]);
            $this->assertSame('handle_shutdown', $restoredSigintHandler[1]);
            $this->assertIsArray($restoredSigtermHandler);
            $this->assertSame($client, $restoredSigtermHandler[0]);
            $this->assertSame('handle_shutdown', $restoredSigtermHandler[1]);
        } finally {
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
            pcntl_async_signals(false);
            pcntl_signal(SIGINT, $originalSigintHandler);
            pcntl_signal(SIGTERM, $originalSigtermHandler);
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
            pcntl_async_signals($originalAsyncSignals);
            pcntl_sigprocmask(SIG_SETMASK, $originalSignalMask);
        }
    }

    public function testPullDbRestoresItsSignalPolicyWhenApplyFails(): void
    {
        $client = $this->makeClient();
        $client->db_apply_throws = true;

        try {
            ob_start();
            $client->run([
                "command" => "pull-db",
                "target_engine" => "sqlite",
            ]);
            $this->fail('Expected db-apply to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected db-apply failure.', $error->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame(1, $client->database_apply_signal_enables);
        $this->assertSame(1, $client->command_signal_restores);
    }

    public function testPullDbRejectsConflictingInProgressPullFiles(): void
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "partial",
                "current_stage" => "fetch",
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull-files",
                "stage_sequence" => ["preflight", "files-pull"],
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull-db",
                "target_engine" => "sqlite",
            ]);
            $this->fail('Expected pull-db to reject an in-progress pull-files command');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Another command is already in progress: pull-files', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $client->db_sync_runs);
        $this->assertSame(0, $client->db_apply_runs);
    }

    public function testPullDbResumesAfterDbPullCompletedBeforePipelineStageWasMarked(): void
    {
        file_put_contents($this->stateDir . '/db.sql', "SELECT 1;\n");
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "pull_pipeline" => [
                "started_by_command" => "pull-db",
                "stage_sequence" => ["preflight", "db-pull", "db-apply"],
                "last_completed_stage" => "preflight",
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(0, $client->db_sync_runs);
        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('db-apply', $state["active_resumable_command"]["command_name"]);
    }

    public function testPullDbAfterStandaloneDbPullDownloadsFreshDump(): void
    {
        file_put_contents($this->stateDir . '/db.sql', "SELECT stale;\n");
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "complete",
                "current_stage" => null,
            ],
            "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
        ]);

        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame("SELECT 1;\n", file_get_contents($this->stateDir . '/db.sql'));
        $this->assertSame('pull-db', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testInvalidPullDbOptionsFailBeforeStateIsPersisted(): void
    {
        $client = $this->makeClient();

        try {
            ob_start();
            $client->run([
                "command" => "pull-db",
                "target_engine" => "not-a-database",
            ]);
            $this->fail('Expected pull-db to reject an invalid target engine');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid --target-engine value', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($this->pullStateDirectory . '/state.json');
    }

    public function testPullWithEssentialFilesPersistsFilter(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertTrue($state["pull_pipeline"]["has_completed_once"]);
        $this->assertSame('essential-files', $state["filter"]);
        $this->assertArrayNotHasKey('files_filter', $state["pull_pipeline"]);
        $this->assertArrayNotHasKey('skipped_pending', $state["pull_pipeline"]);
    }

    public function testPullWithoutFilterRecordsFullDownloadMode(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertTrue($state["pull_pipeline"]["has_completed_once"]);
        $this->assertSame('none', $state["filter"]);
        $this->assertArrayNotHasKey('files_filter', $state["pull_pipeline"]);
        $this->assertArrayNotHasKey('skipped_pending', $state["pull_pipeline"]);
    }

    public function testCompletedPullCanChangeFileFilter(): void
    {
        $client = $this->makeClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        $client->run([
            "command" => "pull",
            "filter" => "none",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_pull_runs);
        $this->assertSame(2, $client->db_sync_runs);
        $this->assertSame('none', $state["filter"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullDerivesFlatDocumentRootFromFlattenTo(): void
    {
        $client = new PullBridgeFakeClient($this->stateDir, $this->filesystem_root);
        $flatten_to = $this->tempDir . '/flattened-site';

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "flatten_to" => $flatten_to,
            "runtime" => "playground-cli",
            "start_runtime" => "none",
        ]);
        ob_end_clean();

        // The pull pipeline must hand apply-runtime a flat_document_root
        // derived from --flatten-to, so the generated runtime targets the
        // flattened layout instead of the raw download tree.
        $this->assertIsArray($client->apply_runtime_options);
        $this->assertSame($flatten_to, $client->apply_runtime_options["flat_document_root"]);
    }

}
