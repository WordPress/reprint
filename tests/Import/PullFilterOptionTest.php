<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class PullFilterFakeClient extends \ImportClient
{
    private bool $create_skipped_list;
    public int $files_pulled = 0;
    public int $preflight_runs = 0;
    public int $files_sync_runs = 0;
    public int $db_sync_runs = 0;
    public int $db_apply_runs = 0;
    public array $progress_events = [];
    public array $status_errors = [];

    /** @var resource|null */
    private $terminal_progress_stream = null;

    public function __construct(string $state_dir, string $fs_root, bool $create_skipped_list)
    {
        $this->create_skipped_list = $create_skipped_list;
        parent::__construct('http://fake.invalid', $state_dir, $fs_root);
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->progress_events[] = $data;
    }

    public function write_status_file(?string $error = null): void
    {
        $this->status_errors[] = $error;
    }

    public function captureTerminalProgress(): void
    {
        $ref = new \ReflectionClass(\ImportClient::class);
        $property = $ref->getProperty('progress');
        $property->setAccessible(true);
        $progress = $property->getValue($this);

        $this->terminal_progress_stream = fopen('php://temp', 'w+');
        $progress->set_is_tty(true);
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

    public function index_count(): int
    {
        return 12;
    }

    public function run_preflight(): void
    {
        $this->preflight_runs++;
        $state = $this->get_import_state();
        $state->preflight = [
            "http_code" => 200,
            "data" => [
                "ok" => true,
                "database" => [
                    "wp" => [
                        "wp_version" => "6.8",
                    ],
                ],
                "runtime" => [
                    "phpversion" => "8.2",
                ],
            ],
        ];
        $state->active_resumable_command->completion_state = "complete";
        $this->save_import_state();
    }

    public function run_files_sync(): void
    {
        $state = $this->get_import_state();
        if (
            ($state->active_resumable_command->command_name ?? null) === "files-pull" &&
            ($state->active_resumable_command->completion_state ?? null) === "complete"
        ) {
            return;
        }

        $this->files_sync_runs++;
        if ($this->create_skipped_list) {
            file_put_contents(
                $this->state_dir . '/.import-download-list-skipped.jsonl',
                "{\"path\":\"" . base64_encode('/wp-content/uploads/2024/01/photo.jpg') . "\"}\n",
            );
        } else {
            @unlink($this->state_dir . '/.import-download-list-skipped.jsonl');
        }

        $state = $this->get_import_state();
        $state->active_resumable_command->command_name = "files-pull";
        $state->active_resumable_command->completion_state = "complete";
        $state->active_resumable_command->current_stage = null;
        $state->files_pull_summary->files_pulled = $this->files_pulled;
        $this->save_import_state();
    }

    public function run_db_sync(): void
    {
        $this->db_sync_runs++;
        file_put_contents($this->state_dir . '/db.sql', "SELECT 1;\n");
        $state = $this->get_import_state();
        $state->active_resumable_command->command_name = "db-pull";
        $state->active_resumable_command->completion_state = "complete";
        $state->active_resumable_command->current_stage = null;
        $this->save_import_state();
    }

    public function run_db_apply(array $options): void
    {
        $this->db_apply_runs++;
        $state = $this->get_import_state();
        $state->active_resumable_command->command_name = "db-apply";
        $state->active_resumable_command->completion_state = "complete";
        $state->active_resumable_command->current_stage = null;
        $state->apply->statements_executed = 42;
        $this->save_import_state();
    }
}

class PullFailingPreflightFakeClient extends PullFilterFakeClient
{
    public function run_preflight(): void
    {
        $this->preflight_runs++;
        $this->last_error_code = 'HTTP_ERROR';
        $state = $this->get_import_state();
        $state->preflight = [
            "http_code" => 500,
            "error" => "Exporter unavailable",
        ];
        $state->active_resumable_command->completion_state = "complete";
        $this->save_import_state();
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
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pull-filter-test-' . uniqid();
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

    private function makeClient(bool $create_skipped_list): PullFilterFakeClient
    {
        return new PullFilterFakeClient($this->stateDir, $this->fs_root, $create_skipped_list);
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );
    }

    public function testPullRejectsSkippedEarlierFilterBeforePersistingIt(): void
    {
        $client = $this->makeClient(false);

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

        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
    }

    public function testPullDoesNotAdvancePastFailedPreflight(): void
    {
        $client = new PullFailingPreflightFakeClient($this->stateDir, $this->fs_root, false);

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
        $this->assertSame(0, $client->files_sync_runs);
        $this->assertNull($state["pull_pipeline"]["last_completed_stage"]);

        $error_events = array_values(array_filter(
            $client->progress_events,
            static fn (array $event): bool => ($event["status"] ?? null) === "error",
        ));
        $this->assertCount(1, $error_events);
        $this->assertSame("preflight", $error_events[0]["failed_stage"]);
        $this->assertSame("Exporter unavailable", $error_events[0]["message"]);
        $status_errors = array_values(array_filter(
            $client->status_errors,
            static fn ($error): bool => $error !== null,
        ));
        $this->assertSame(["Exporter unavailable"], $status_errors);
    }

    public function testPullResumesAfterFilesPullCompletedBeforePipelineStageWasMarked(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(0, $client->files_sync_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullResumesSameUnfinishedPipelineWithoutConflict(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->files_sync_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullRefusesToClearCompletedCommandOwnedByDifferentUnfinishedPipeline(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );
        file_put_contents($this->stateDir . '/db.sql', "SELECT 1;\n");

        $client = $this->makeClient(false);

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
        $client = $this->makeClient(false);

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

        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
    }

    public function testPullFilesSummaryReportsNoChangedFilesPulled(): void
    {
        $client = $this->makeClient(false);
        $client->captureTerminalProgress();

        $client->run([
            "command" => "pull-files",
        ]);

        $output = $client->terminalProgressOutput();
        $this->assertStringContainsString('0 changed files pulled', $output);
        $this->assertStringNotContainsString('pull scope compared', $output);
    }

    public function testPullFilesSummaryReportsChangedFilePulled(): void
    {
        $client = $this->makeClient(false);
        $client->files_pulled = 1;
        $client->captureTerminalProgress();

        $client->run([
            "command" => "pull-files",
            "only" => ["/var/www/html/wp-content/uploads/reprint-demo"],
        ]);

        $this->assertStringContainsString(
            '1 changed file pulled',
            $client->terminalProgressOutput(),
        );
    }

    public function testPullFilesSummaryReportsDeferredFilesPending(): void
    {
        $client = $this->makeClient(true);
        $client->captureTerminalProgress();

        $client->run([
            "command" => "pull-files",
            "filter" => "essential-files",
        ]);

        $this->assertStringContainsString(
            '0 changed files pulled, deferred files pending',
            $client->terminalProgressOutput(),
        );
    }

    public function testPullFilesRunsOnlyPreflightAndFilesStages(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull-files",
            "filter" => "essential-files",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(1, $client->files_sync_runs);
        $this->assertSame(0, $client->db_sync_runs);
        $this->assertSame(0, $client->db_apply_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('essential-files', $state["pull_pipeline"]["files_filter"]);
    }

    public function testPullFilesDoesNotAdvancePastFailedPreflight(): void
    {
        $client = new PullFailingPreflightFakeClient($this->stateDir, $this->fs_root, false);

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
        $this->assertSame(0, $client->files_sync_runs);
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
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(0, $client->files_sync_runs);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('files-pull', $state["active_resumable_command"]["command_name"]);
    }

    public function testPullFilesAfterStandaloneFilesPullStartsFreshDelta(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "active_resumable_command" => [
                    "command_name" => "files-pull",
                    "completion_state" => "complete",
                    "current_stage" => null,
                ],
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->files_sync_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullAfterFilesPullCompletedBeforePipelineStageWasMarkedDoesNotStealIt(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

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

        $this->assertSame(0, $client->files_sync_runs);
        $this->assertSame(0, $client->db_sync_runs);
    }

    public function testRerunningCompletedPullFilesStartsFreshFilesDelta(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run(["command" => "pull-files"]);
        $client->run(["command" => "pull-files"]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_sync_runs);
        $this->assertSame('pull-files', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('files-pull', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullAfterCompletedPullFilesStartsFreshFilesDelta(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run(["command" => "pull-files"]);
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_sync_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame('pull', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
    }

    public function testPullDbRunsPreflightDownloadAndApplyStages(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull-db",
            "target_engine" => "sqlite",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->preflight_runs);
        $this->assertSame(0, $client->files_sync_runs);
        $this->assertSame(1, $client->db_sync_runs);
        $this->assertSame(1, $client->db_apply_runs);
        $this->assertSame('pull-db', $state["pull_pipeline"]["started_by_command"]);
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('db-apply', $state["active_resumable_command"]["command_name"]);
        $this->assertSame(42, $state["apply"]["statements_executed"]);
    }

    public function testPullDbRejectsConflictingInProgressPullFiles(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

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
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
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
            ]),
        );

        $client = $this->makeClient(false);

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
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "active_resumable_command" => [
                    "command_name" => "db-pull",
                    "completion_state" => "complete",
                    "current_stage" => null,
                ],
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );

        $client = $this->makeClient(false);

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
        $client = $this->makeClient(false);

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

        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
    }

    public function testPullWithEssentialFilesPersistsDeferredFilesState(): void
    {
        $client = $this->makeClient(true);

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('essential-files', $state["pull_pipeline"]["files_filter"]);
        $this->assertTrue($state["pull_pipeline"]["skipped_pending"]);
        $this->assertTrue($state["pull_pipeline"]["has_completed_once"]);
        $this->assertSame('essential-files', $state["filter"]);
        $this->assertFileExists($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullWithoutFilterRecordsFullDownloadMode(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('none', $state["pull_pipeline"]["files_filter"]);
        $this->assertFalse($state["pull_pipeline"]["skipped_pending"]);
        $this->assertTrue($state["pull_pipeline"]["has_completed_once"]);
        $this->assertSame('none', $state["filter"]);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullDerivesFlatDocumentRootFromFlattenTo(): void
    {
        $client = new PullBridgeFakeClient($this->stateDir, $this->fs_root, false);
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

    public function testRepullAfterSkippedEarlierTailUsesCompletedFilesPullState(): void
    {
        // The deferred "skipped-earlier" tail belongs to a files-pull that
        // has finished. Once that lifecycle state is truthful, a completed
        // pull can delta re-pull without bypassing the mid-flight guard.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "active_resumable_command" => [
                    "command_name" => "files-pull",
                    "completion_state" => "complete",
                    "current_stage" => null,
                ],
                "filter" => "skipped-earlier",
                "pull_pipeline" => [
                    "started_by_command" => "pull",
                    "stage_sequence" => ["preflight", "files-pull", "db-pull", "db-apply"],
                    "last_completed_stage" => "db-apply",
                    "files_filter" => "essential-files",
                    "skipped_pending" => true,
                ],
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('db-apply', $state["pull_pipeline"]["last_completed_stage"]);
        $this->assertSame('essential-files', $state["filter"]);
    }

    public function testRepullAfterInterruptedSkippedEarlierTailIsNotBlocked(): void
    {
        // An interrupted skipped-earlier tail leaves completion_state="partial"
        // with filter=skipped-earlier. A new essential-files pull must still be
        // allowed: the filter-change guard must not treat the terminal tail as
        // a mid-flight sync.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "active_resumable_command" => [
                    "command_name" => "files-pull",
                    "completion_state" => "partial",
                    "current_stage" => "fetch-skipped",
                ],
                "filter" => "skipped-earlier",
                "pull_pipeline" => [
                    "started_by_command" => "pull",
                    "stage_sequence" => ["preflight", "files-pull", "db-pull", "db-apply"],
                    "last_completed_stage" => "db-apply",
                    "files_filter" => "essential-files",
                    "skipped_pending" => true,
                ],
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('essential-files', $state["filter"]);
    }
}
