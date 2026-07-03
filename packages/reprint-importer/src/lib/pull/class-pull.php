<?php
/**
 * Signals that Pull already emitted the stage-specific error/status output.
 */
class PullFailureReportedException extends RuntimeException
{
}

/**
 * High-level pull commands — orchestrate lower-level commands into
 * resumable pipelines.
 *
 * Each step retries automatically on server timeouts (exit code 2). If
 * the process is interrupted, re-running the same high-level command
 * resumes from the last completed step. Like `git pull` composes fetch +
 * merge, `pull` composes preflight → files-pull → db-pull → db-apply →
 * flat-docroot → apply-runtime → start. `pull-files` runs the file
 * subset, and `pull-db` runs the database subset.
 *
 * The class holds a reference to ImportClient because each stage
 * delegates to an ImportClient method (run_preflight, run_files_sync,
 * etc.). The orchestration logic (pipeline state, retry loop, stage
 * framing) lives here; the actual transfer logic stays in ImportClient.
 */
class Pull
{
    private ImportClient $client;
    private TerminalProgress $progress;

    public function __construct(ImportClient $client, TerminalProgress $progress)
    {
        $this->client = $client;
        $this->progress = $progress;
    }

    /**
     * Determine the pipeline stages based on the provided options.
     *
     * Always: preflight → files-pull → db-pull.
     * Adds db-apply when a database target is configured, flat-docroot
     * when --flatten-to is set, apply-runtime when --runtime is set,
     * and start when the selected start runtime can be launched
     * in-process.
     */
    public function stages(array $options): array
    {
        $stages = ['preflight', 'files-pull', 'db-pull'];
        $has_db_target =
            !empty($options['target_db']) ||
            !empty($options['target_engine']) ||
            !empty($options['target_sqlite_path']) ||
            !empty($options['target_user']);
        if ($has_db_target) {
            $stages[] = 'db-apply';
        }
        if (!empty($options['flatten_to'])) {
            $stages[] = 'flat-docroot';
        }
        $runtime = !empty($options['runtime']) ? $options['runtime'] : null;
        // A requested start runtime also implies runtime generation because
        // there is no generated server to start otherwise.
        if ($runtime === null && !empty($options['start_runtime']) && $options['start_runtime'] !== 'none') {
            $runtime = $options['start_runtime'];
        }
        // Without an explicit start option, pull starts only runtimes that this
        // process knows how to launch directly.
        $start_runtime = !empty($options['start_runtime'])
            ? $options['start_runtime']
            : $this->default_start_runtime($runtime);
        if ($runtime !== null && $runtime !== 'none') {
            $stages[] = 'apply-runtime';
            if (
                $start_runtime !== 'none' &&
                $start_runtime === $runtime &&
                $this->can_start_runtime($start_runtime)
            ) {
                $stages[] = 'start';
            }
        }
        return $stages;
    }

    /** Human-readable label for a pipeline stage. */
    public function stage_label(string $stage): string
    {
        switch ($stage) {
            case 'preflight':     return 'Connecting';
            case 'files-pull':    return 'Pulling files';
            case 'db-pull':       return 'Pulling database';
            case 'db-apply':      return 'Importing database';
            case 'flat-docroot':  return 'Flattening layout';
            case 'apply-runtime': return 'Preparing runtime';
            case 'start':         return 'Starting server';
            default:              return $stage;
        }
    }

    /**
     * Run the pull pipeline.
     */
    public function run(array $options): void
    {
        $this->normalize_url();
        $this->progress->set_mode('pipeline');

        $options = $this->validate_and_default_pull_options($options);

        $this->run_pipeline(
            'pull',
            $this->stages($options),
            $options,
            'Pulling'
        );
    }

    /**
     * Handle --abort for high-level pull commands.
     *
     * File pipelines keep downloaded site files in place. The database
     * pipeline removes stale database artifacts so the next pull-db fetches
     * and applies a fresh dump.
     */
    public function abort(string $command = 'pull'): void
    {
        $this->prepare_repull($command);
        $label = $command === 'pull' ? 'Pull' : $command;
        $message = "{$label} state cleared.";
        $message .= $command === 'pull-db'
            ? " Database artifacts will be downloaded again."
            : " Downloaded files are preserved.";
        $this->progress->show_lifecycle_line("{$message}\n");
        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "aborted",
            "command" => $command,
            "message" => $message,
        ], true);
    }

    /**
     * Run only the file stages from the pull pipeline.
     */
    public function run_pull_files(array $options): void
    {
        $this->normalize_url();
        $this->progress->set_mode('pipeline');

        if (!isset($options['filter'])) {
            $options['filter'] = $this->client->get_import_state()->filter ?? 'none';
        }
        if (!in_array($options['filter'], ['none', 'essential-files'], true)) {
            throw new InvalidArgumentException(
                "Invalid --filter value for pull-files: {$options['filter']}. " .
                "Valid values: none, essential-files"
            );
        }

        $this->run_pipeline(
            'pull-files',
            ['preflight', 'files-pull'],
            $options,
            'Pulling files from'
        );
    }

    /**
     * Run only the database stages from the pull pipeline.
     */
    public function run_pull_db(array $options): void
    {
        $this->normalize_url();
        $this->progress->set_mode('pipeline');

        $options['sql_output'] = 'file';
        $options = $this->default_pull_db_target_options($options);
        $options = $this->validate_database_target_options($options);

        $this->run_pipeline(
            'pull-db',
            ['preflight', 'db-pull', 'db-apply'],
            $options,
            'Pulling database from'
        );
    }

    /**
     * Runs a named pull pipeline from the first unfinished stage.
     *
     * ImportState::$pull_pipeline records orchestration progress: which
     * user-facing command started the pipeline and which whole stage was last
     * completed. ImportState::$active_resumable_command records the resumable
     * lower-level command, whether it was invoked directly or as a stage in
     * this pipeline, including its completion status, internal state, and
     * remote cursor.
     *
     * Keeping those checkpoints separate lets a rerun resume safely when a
     * lower-level command completed but the pipeline checkpoint was not saved
     * yet.
     */
    private function run_pipeline(
        string $command,
        array $stages,
        array $options,
        string $title
    ): void {
        $state = $this->client->get_import_state();
        $pull_pipeline = $state->pull_pipeline->started_by_command;
        $pull_stage = $state->pull_pipeline->last_completed_stage;
        $stage_sequence = $state->pull_pipeline->stage_sequence;
        if (!is_array($stage_sequence) || $stage_sequence === []) {
            $stage_sequence = $stages;
        }
        $pipeline_final_stage = $stage_sequence[count($stage_sequence) - 1] ?? null;
        $state_command = $state->active_resumable_command->command_name;
        $state_status = $state->active_resumable_command->completion_state;
        $completed_stage = $pull_pipeline === $command ? $pull_stage : null;
        $completed_pipeline =
            $pull_pipeline !== null &&
            $pull_stage !== null &&
            $pipeline_final_stage !== null &&
            $pull_stage === $pipeline_final_stage;

        if ($completed_pipeline) {
            $this->prepare_repull($command);
            $completed_stage = null;
            $pull_pipeline = null;
            $pull_stage = null;
            $state_command = null;
            $state_status = null;
        }

        $pipeline_has_resume_state =
            $pull_pipeline !== null &&
            (
                $pull_stage !== null ||
                $state_command !== null ||
                $state_status !== null
            );

        if ($pipeline_has_resume_state && $pull_pipeline !== $command) {
            throw new RuntimeException(
                "Another command is already in progress: {$pull_pipeline}. " .
                "Rerun {$pull_pipeline} to resume it. Only use --abort if you want to discard " .
                "that pipeline's resume state before running {$command}."
            );
        }

        $has_direct_command_state = !$pipeline_has_resume_state && $state_status !== null;
        if (
            $has_direct_command_state &&
            $state_status !== 'complete' &&
            in_array($state_command, ['files-pull', 'db-pull', 'db-apply'], true) &&
            !in_array($state_command, $stages, true)
        ) {
            throw new RuntimeException(
                "Another command is already in progress: {$state_command}. " .
                "Rerun {$state_command} to resume it. Only use --abort if you want to discard " .
                "that command's resume state before running {$command}."
            );
        }

        if ($has_direct_command_state && $state_status === 'complete') {
            // Users can run lower-level commands directly, e.g.
            // `reprint files-pull` or `reprint db-pull`, without going through
            // this pull pipeline. Those commands save their own completion
            // state in active_resumable_command. That state must not make a
            // high-level command skip its matching stage: the pipeline has not
            // recorded that stage as complete. Clear the direct command
            // checkpoint first so the stage computes a fresh delta.
            $state_dir = $this->client->state_dir;
            if ($state_command === 'files-pull' && in_array('files-pull', $stages, true)) {
                // Keep the local file index, but clear transient files-pull
                // download state so this pipeline computes a fresh
                // remote-vs-local delta.
                $state = $this->client->get_import_state();
                $state->active_resumable_command->command_name = null;
                $state->active_resumable_command->completion_state = null;
                $state->active_resumable_command->remote_cursor = null;
                $state->active_resumable_command->current_stage = null;
                $state->consecutive_timeouts = 0;
                $state->current_file = null;
                $state->current_file_bytes = null;
                $state->diff = new FileDiffProgressState();
                $state->index = new RemoteFileIndexCursorState();
                $state->fetch = new DownloadListFetchProgressState();
                $state->fetch_skipped = new DownloadListFetchProgressState();
                $state->files_pull_summary = new FilesPullSummaryState();
                $state->files_pull_only_fingerprint = null;
                $this->client->save_import_state();
                foreach ([
                    "{$state_dir}/.import-remote-index.jsonl",
                    "{$state_dir}/.import-download-list.jsonl",
                    "{$state_dir}/.import-download-list-skipped.jsonl",
                ] as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            } elseif ($state_command === 'db-pull' && in_array('db-pull', $stages, true)) {
                // Discard database dump artifacts from any previous runs.
                $state = $this->client->get_import_state();
                $state->active_resumable_command->command_name = null;
                $state->active_resumable_command->completion_state = null;
                $state->active_resumable_command->remote_cursor = null;
                $state->active_resumable_command->current_stage = null;
                $state->consecutive_timeouts = 0;
                $state->sql_bytes = null;
                $state->db_index = new DatabaseTableIndexState();
                $this->client->save_import_state();
                foreach ([
                    "{$state_dir}/db.sql",
                    "{$state_dir}/db-tables.jsonl",
                    "{$state_dir}/.import-domains.json",
                ] as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        $total = count($stages);
        $start_index = 0;
        if ($completed_stage !== null) {
            $idx = array_search($completed_stage, $stages, true);
            if ($idx !== false) {
                $start_index = $idx + 1;
            }
        }

        $host = parse_url($this->client->remote_url, PHP_URL_HOST) ?? $this->client->remote_url;
        $bold = "\033[1m";
        $r = "\033[0m";
        $this->progress->print_line("\n{$bold}{$title} {$host}{$r}\n");

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => $command,
            "stages" => $stages,
            "resume_from" => $start_index,
            "message" => "Starting {$command}",
        ], true);

        $this->client->audit_log(
            sprintf("%s | stages=%s | resume_from=%d", strtoupper($command), implode(",", $stages), $start_index),
            true,
        );

        for ($i = 0; $i < $start_index; $i++) {
            $this->print_skipped($stages[$i]);
        }

        for ($i = $start_index; $i < $total; $i++) {
            $stage = $stages[$i];
            $step = $i + 1;

            $this->print_stage_header($stage);

            try {
                $this->run_stage($stage, $options, $step, $total);
            } catch (\Exception $e) {
                $this->report_failure($command, $stage, $stages, $i, $e);
                throw new PullFailureReportedException($e->getMessage(), 0, $e);
            }

            $this->client->mark_pull_stage_complete($stage, $command, $stages);
        }

        // The 'start' stage handles its own completion (it needs to save
        // state before blocking on the server process).
        if (!in_array('start', $stages, true)) {
            $this->client->mark_pull_complete($command);
            if ($command === 'pull') {
                $this->print_summary();
            }
        }

        $complete_message = $command === 'pull' ? 'Pull complete' : "{$command} complete";
        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => $command,
            "stages" => $stages,
            "message" => $complete_message,
        ], true);
    }

    /**
     * Runs one pipeline stage and prints its completion summary.
     *
     * Stages that delegate to lower-level commands rely on those commands for
     * their own resume state. The pipeline checkpoint is saved by the caller
     * after this method returns, so a thrown exception leaves the stage
     * unfinished at the orchestration level.
     */
    private function run_stage(string $stage, array $options, int $step, int $total): void
    {
        switch ($stage) {
            case 'preflight':
                $this->client->run_preflight();
                $preflight = $this->client->get_import_state()->preflight;
                $ok = ($preflight["http_code"] ?? 0) === 200 && !empty($preflight["data"]["ok"]);
                if (!$ok) {
                    $this->client->exit_code = 1;
                    throw new RuntimeException($preflight["error"] ?? "Preflight check failed");
                }
                $summary = null;
                $data = $preflight["data"] ?? null;
                if (is_array($data)) {
                    $parts = [];
                    $wp = $data["database"]["wp"]["wp_version"] ?? null;
                    if ($wp) {
                        $parts[] = "WordPress {$wp}";
                    }
                    $php = $data["runtime"]["phpversion"] ?? null;
                    if ($php) {
                        $parts[] = "PHP {$php}";
                    }
                    $summary = $parts ? implode(", ", $parts) : null;
                }
                $this->print_done($stage, $summary);
                break;

            case 'files-pull':
                $this->client->prepare_files_pull_options($options);
                $this->run_until_complete('files-pull', function () {
                    $this->client->run_files_sync();
                });
                $skipped_pending =
                    $options['filter'] === 'essential-files' &&
                    $this->client->has_skipped_files_pending();
                $this->client->set_pull_files_state($options['filter'], $skipped_pending);
                $files_pulled = $this->client->state->files_pull_summary->files_pulled;
                $pulled_label = $files_pulled === 1 ? 'file' : 'files';
                $summary = sprintf(
                    '%s changed %s pulled',
                    number_format($files_pulled),
                    $pulled_label,
                );
                if ($skipped_pending) {
                    $summary .= ", deferred files pending";
                }
                $this->print_done($stage, $summary);
                break;

            case 'db-pull':
                // A completed db-pull is useful only when the downloaded SQL
                // dump is still present. Without the dump, the following
                // db-apply stage would have nothing safe to import.
                $state = $this->client->get_import_state();
                if (
                    $state->active_resumable_command->command_name !== 'db-pull' ||
                    $state->active_resumable_command->completion_state !== 'complete' ||
                    !file_exists($this->client->state_dir . '/db.sql')
                ) {
                    $this->run_until_complete('db-pull', function () {
                        $this->client->run_db_sync();
                    });
                }
                $sql_file = $this->client->state_dir . "/db.sql";
                $size = null;
                if (file_exists($sql_file)) {
                    $bytes = filesize($sql_file);
                    if ($bytes !== false) {
                        $size = $this->format_bytes($bytes);
                    }
                }
                $this->print_done($stage, $size);
                break;

            case 'db-apply':
                // Database import is not safe to run twice. If db-apply
                // already reached lower-level completion, accept that result even
                // when the pipeline checkpoint still needs to be advanced.
                $state = $this->client->get_import_state();
                if (
                    $state->active_resumable_command->command_name !== 'db-apply' ||
                    $state->active_resumable_command->completion_state !== 'complete'
                ) {
                    $this->run_until_complete('db-apply', function () use ($options) {
                        $this->client->run_db_apply($options);
                    });
                }
                $stmts = $state->apply->statements_executed;
                $this->print_done($stage, $stmts > 0 ? number_format($stmts) . " statements" : null);
                break;

            case 'flat-docroot':
                $this->client->run_flat_document_root($options);
                $this->print_done($stage);
                break;

            case 'apply-runtime':
                $this->client->run_apply_runtime($options);
                $this->print_done($stage);
                break;

            case 'start':
                $this->start_server($options);
                break;
        }
    }

    /**
     * Reject invalid high-level pull options before ImportClient persists
     * resume-related state.
     */
    public function assert_options_valid_before_state_write(string $command, array $options): void
    {
        $sql_output = $options["sql_output"] ?? "file";
        if ($sql_output !== "file") {
            throw new InvalidArgumentException(
                "{$command} downloads SQL to the local state directory before applying it. " .
                "Use db-pull directly for --sql-output={$sql_output}."
            );
        }

        if ($command === 'pull') {
            $this->assert_runtime_options_valid($options);
            $options = $this->default_pull_runtime_options($options);
            if ($options['runtime'] === 'none') {
                unset($options['runtime']);
            }
            $options = $this->default_pull_target_options($options);
            $this->validate_database_target_options($options);
            return;
        }

        if ($command === 'pull-db') {
            $options = $this->default_pull_db_target_options($options);
            $this->validate_database_target_options($options);
        }
    }

    /**
     * Validate user-provided pull options and apply defaults without saving state.
     */
    private function validate_and_default_pull_options(array $options): array
    {
        $this->assert_runtime_options_valid($options);
        // Default --runtime to php-builtin so pull always ends with a
        // running local server. Users can override with --runtime=nginx-fpm,
        // --runtime=playground-cli, or --runtime=none to skip runtime
        // generation entirely.
        $options = $this->default_pull_runtime_options($options);

        if ($options['runtime'] === 'none') {
            unset($options['runtime']);
        }

        // Default --target-engine to sqlite for php-builtin and
        // playground-cli (no MySQL server needed). nginx-fpm users
        // probably have a server stack — require explicit DB config.
        $options = $this->default_pull_target_options($options);
        $options = $this->validate_database_target_options($options);

        if (empty($options['output_dir'])) {
            $options['output_dir'] = $this->client->state_dir . '/runtime';
        }

        if (!isset($options['filter'])) {
            $options['filter'] = $this->client->get_import_state()->filter;
        }
        if (!in_array($options['filter'], ['none', 'essential-files'], true)) {
            throw new InvalidArgumentException(
                "Invalid --filter value for pull: {$options['filter']}. " .
                "Valid values: none, essential-files"
            );
        }

        // When --flatten-to produces a flattened document root, the
        // apply-runtime stage must target that flattened directory rather
        // than its default (the raw download tree + remote document_root).
        // Derive flat_document_root from flatten_to so a single
        // `pull --flatten-to=X --runtime=...` generates a runtime rooted at
        // the flattened layout.
        if (!empty($options['flatten_to']) && empty($options['flat_document_root'])) {
            $options['flat_document_root'] = $options['flatten_to'];
        }

        return $options;
    }

    /**
     * Validates runtime options for both CLI and programmatic callers.
     *
     * Programmatic callers can invoke ImportClient without the CLI parser, so
     * pull validates runtime names and start/runtime combinations here before
     * any resume state is written.
     */
    private function assert_runtime_options_valid(array $options): void
    {
        foreach (['runtime', 'start_runtime'] as $key) {
            if (!empty($options[$key]) && !in_array($options[$key], VALID_TARGET_RUNTIMES, true)) {
                $flag = str_replace('_', '-', $key);
                throw new InvalidArgumentException(
                    "Invalid --{$flag} value: {$options[$key]}. " .
                    "Valid runtimes: " . implode(', ', VALID_TARGET_RUNTIMES)
                );
            }
        }

        $options = $this->default_pull_runtime_options($options);
        if ($options['start_runtime'] === 'none') {
            return;
        }
        if (!$this->can_start_runtime($options['start_runtime'])) {
            throw new InvalidArgumentException(
                "Starting runtime {$options['start_runtime']} is not supported yet. " .
                "Supported start runtimes: php-builtin, playground-cli, none"
            );
        }
        if ($options['start_runtime'] !== $options['runtime']) {
            throw new InvalidArgumentException(
                "--start-runtime={$options['start_runtime']} requires matching --runtime={$options['start_runtime']}, " .
                "or omit --runtime to use {$options['start_runtime']} for both."
            );
        }
    }

    /**
     * Applies runtime defaults without mutating persisted state.
     *
     * `pull` should produce a runnable local site by default, so it generates
     * php-builtin runtime files unless the caller selects a runtime, selects a
     * start runtime, or disables runtime generation explicitly.
     */
    private function default_pull_runtime_options(array $options): array
    {
        if (empty($options['runtime'])) {
            if (!empty($options['start_runtime']) && $options['start_runtime'] !== 'none') {
                $options['runtime'] = $options['start_runtime'];
            } else {
                $options['runtime'] = 'php-builtin';
            }
        }

        if (empty($options['start_runtime'])) {
            $options['start_runtime'] = $this->default_start_runtime($options['runtime']);
        }

        return $options;
    }

    /**
     * Applies the database target defaults for generated runtimes.
     *
     * php-builtin and playground-cli can run without a local MySQL server, so
     * pull imports into SQLite by default for those runtimes. nginx-fpm does
     * not imply a database target because it is normally paired with an
     * externally managed server.
     */
    private function default_pull_target_options(array $options): array
    {
        if (empty($options['target_engine']) && empty($options['target_user']) && empty($options['target_db'])) {
            if (($options['runtime'] ?? null) !== 'nginx-fpm') {
                $options['target_engine'] = 'sqlite';
            }
        }

        return $options;
    }

    /**
     * Applies database target defaults for the database-only pull command.
     */
    private function default_pull_db_target_options(array $options): array
    {
        if (empty($options['target_engine'])) {
            $has_mysql_target =
                !empty($options['target_host']) ||
                !empty($options['target_port']) ||
                !empty($options['target_user']) ||
                !empty($options['target_pass']);
            $has_sqlite_target = !empty($options['target_sqlite_path']);
            if ($has_sqlite_target || (!$has_mysql_target && empty($options['target_db']))) {
                $options['target_engine'] = 'sqlite';
            }
        }

        return $options;
    }

    /**
     * Validates database import target options.
     *
     * MySQL imports need a user and database name because Reprint connects to
     * an existing server. SQLite imports can use the generated default path
     * when no explicit target path is supplied.
     */
    private function validate_database_target_options(array $options): array
    {
        if (!empty($options['target_engine'])) {
            $engine = strtolower($options['target_engine']);
            if (!in_array($engine, ['mysql', 'sqlite'], true)) {
                throw new InvalidArgumentException(
                    "Invalid --target-engine value: {$options['target_engine']}. " .
                    "Valid engines: mysql, sqlite"
                );
            }
            $options['target_engine'] = $engine;
        }

        if (($options['target_engine'] ?? 'mysql') === 'mysql') {
            if (empty($options['target_user'])) {
                throw new InvalidArgumentException(
                    "--target-user is required for MySQL database import."
                );
            }
            if (empty($options['target_db'])) {
                throw new InvalidArgumentException(
                    "--target-db is required for MySQL database import."
                );
            }
        }

        return $options;
    }

    /**
     * Returns the implicit start mode for a generated runtime.
     */
    private function default_start_runtime(?string $runtime): string
    {
        return $this->can_start_runtime($runtime) ? $runtime : 'none';
    }

    /**
     * Indicates whether pull can launch the generated runtime directly.
     */
    private function can_start_runtime(?string $runtime): bool
    {
        return in_array($runtime, ['php-builtin', 'playground-cli'], true);
    }

    /**
     * Append ?site-export-api to bare site URLs so users can pass
     * https://example.com instead of https://example.com/?site-export-api.
     */
    private function normalize_url(): void
    {
        $url = $this->client->remote_url;
        if (strpos($url, 'site-export-api') === false) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $this->client->remote_url = $url . $separator . 'site-export-api';
        }
    }

    /**
     * Reset sub-command state for a delta re-pull.
     *
     * Keeps preflight data in place, then clears only the checkpoint groups
     * owned by the high-level command being restarted. Keeping that ownership
     * map here prevents callers from having to know which file/database state
     * belongs to which pipeline.
     */
    private function prepare_repull(string $command): void
    {
        $state_dir = $this->client->state_dir;
        switch ($command) {
            case 'pull':
                $reset_file_transfer_state = true;
                $reset_file_selection_state = false;
                $reset_db_state = true;
                break;

            case 'pull-files':
                $reset_file_transfer_state = true;
                $reset_file_selection_state = true;
                $reset_db_state = false;
                break;

            case 'pull-db':
                $reset_file_transfer_state = false;
                $reset_file_selection_state = false;
                $reset_db_state = true;
                break;

            default:
                throw new InvalidArgumentException("Unknown pull command: {$command}");
        }


        $state = $this->client->get_import_state();
        $state->pull_pipeline->started_by_command = $command;
        $state->pull_pipeline->stage_sequence = [];
        $state->pull_pipeline->last_completed_stage = null;
        $state->pull_pipeline->files_filter = null;
        $state->pull_pipeline->skipped_pending = false;
        $state->pull_pipeline->has_completed_once = true;
        $state->active_resumable_command->command_name = null;
        $state->active_resumable_command->completion_state = null;
        $state->active_resumable_command->remote_cursor = null;
        $state->active_resumable_command->current_stage = null;
        $state->consecutive_timeouts = 0;
        if ($reset_file_transfer_state) {
            $state->current_file = null;
            $state->current_file_bytes = null;
            $state->diff = new FileDiffProgressState();
            $state->fetch = new DownloadListFetchProgressState();
            $state->fetch_skipped = new DownloadListFetchProgressState();
            $state->files_pull_summary = new FilesPullSummaryState();
        }
        if ($reset_file_selection_state) {
            $state->index = new RemoteFileIndexCursorState();
            $state->files_pull_only_fingerprint = null;
        }
        if ($reset_db_state) {
            $state->sql_bytes = null;
            $state->db_index = new DatabaseTableIndexState();
            $state->apply = new DatabaseApplyCommandState();
            $state->sql_output = null;
        }
        $this->client->save_import_state();

        $paths = [];
        if ($reset_file_transfer_state) {
            $paths[] = $state_dir . "/.import-remote-index.jsonl";
            $paths[] = $state_dir . "/.import-download-list.jsonl";
            $paths[] = $state_dir . "/.import-download-list-skipped.jsonl";
        }
        if ($reset_db_state) {
            $paths[] = $state_dir . "/db.sql";
            $paths[] = $state_dir . "/db-tables.jsonl";
            $paths[] = $state_dir . "/.import-domains.json";
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $this->client->audit_log(strtoupper($command) . " | prepared for delta re-pull", true);
    }

    /**
     * Lower-level commands return with completion_state="partial" when a
     * server timeout drops the connection. This loop retries automatically,
     * resetting the completion state to "in_progress" so the handler enters
     * its resume path on the next call.
     */
    private function run_until_complete(string $stage, callable $handler): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $handler();
            $state = $this->client->get_import_state();
            if ($state->active_resumable_command->completion_state === 'complete') {
                return;
            }
            if ($state->active_resumable_command->completion_state !== 'partial') {
                throw new RuntimeException("Stage {$stage} stopped before completing.");
            }
            $state->active_resumable_command->completion_state = 'in_progress';
            $this->client->save_import_state();
            $this->client->exit_code = 0;
            $this->progress->tick_spinner();
        }

        throw new RuntimeException("Stage {$stage} kept reporting partial progress after 1000 retry attempts; aborting.");
    }

    /**

     * Start the local server. For php-builtin / playground-cli this
     * runs start.sh via passthru and blocks until the user hits Ctrl-C.
     */
    private function start_server(array $options): void
    {
        $output_dir = $options['output_dir'] ?? $this->client->state_dir . '/runtime';
        $start_sh = $output_dir . '/start.sh';

        if (!file_exists($start_sh)) {
            throw new RuntimeException(
                "start.sh not found at {$start_sh}. " .
                "apply-runtime may have failed to generate it."
            );
        }

        $host = $options['host'] ?? 'localhost';
        $port = (int) ($options['port'] ?? 8881);
        $url = "http://{$host}:{$port}";

        // Mark pull complete BEFORE the server blocks so killing the
        // server (Ctrl-C) doesn't leave the pipeline mid-flight.
        $state = $this->client->get_import_state();
        $state->pull_pipeline->last_completed_stage = 'start';
        $state->pull_pipeline->has_completed_once = true;
        $state->active_resumable_command->completion_state = 'complete';
        $this->client->save_import_state();

        $green = "\033[32m";
        $bold = "\033[1m";
        $dim = "\033[2m";
        $cyan = "\033[36m";
        $r = "\033[0m";
        $this->progress->clear_progress_line();
        $this->progress->print_line("  {$green}✓{$r} Ready at {$cyan}{$bold}{$url}{$r}\n");
        $this->progress->print_line("    {$dim}Press Ctrl-C to stop.{$r}\n\n");

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "server_starting",
            "command" => "pull",
            "url" => $url,
            "start_sh" => $start_sh,
            "message" => "Starting server at {$url}",
        ], true);

        passthru("bash " . escapeshellarg($start_sh), $exit_code);
        $this->client->exit_code = $exit_code;
    }

    /**
     * Starts a progress line that stage commands can update in place.
     */
    private function print_stage_header(string $stage): void
    {
        $this->progress->clear_progress_line();
        $label = $this->stage_label($stage);
        $this->progress->set_active_label($label);
        $cyan = "\033[36m";
        $r = "\033[0m";
        // \n claims a fresh line, then \r\033[K positions us at column 0.
        // Subsequent show_progress_line calls overwrite this in place.
        $this->progress->print_line("\n\r\033[K  {$cyan}⠋{$r} {$label}");
    }

    /**
     * Finishes the active progress line with the stage result.
     */
    private function print_done(string $stage, ?string $summary = null): void
    {
        $this->progress->clear_progress_line();
        $green = "\033[32m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $label = $this->stage_label($stage);
        $extra = $summary ? " {$dim}— {$summary}{$r}" : "";
        $this->progress->print_line("  {$green}✓{$r} {$label}{$extra}\n");
        $this->progress->set_active_label(null);
    }

    /**
     * Shows a completed stage that is being skipped during resume.
     */
    private function print_skipped(string $stage): void
    {
        $dim = "\033[2m";
        $r = "\033[0m";
        $label = $this->stage_label($stage);
        $this->progress->print_line("  {$dim}✓ {$label}{$r}\n");
    }

    /**
     * Prints the final summary for pipelines that return to the shell.
     */
    private function print_summary(): void
    {
        $green = "\033[32m";
        $bold = "\033[1m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $fs_root = $this->client->fs_root;
        $this->progress->print_line(
            "\n{$green}{$bold}Done.{$r} {$dim}Files in {$fs_root}{$r}\n"
        );
        if ($this->client->get_import_state()->pull_pipeline->skipped_pending) {
            $this->progress->print_line(
                "{$dim}Deferred files remain. The skipped download list was preserved on disk for a follow-up sync.{$r}\n"
            );
        }
    }

    /**
     * Reports a failed stage once before the caller rethrows.
     *
     * Preflight failures are formatted separately because a missing exporter
     * plugin is an actionable setup problem, not a resumable transfer failure.
     */
    private function report_failure(string $command, string $stage, array $stages, int $i, \Exception $e): void
    {
        $message = $stage === 'preflight'
            ? $e->getMessage()
            : "Pull failed at {$stage}: " . $e->getMessage();

        $this->client->output_progress([
            "status" => "error",
            "command" => $command,
            "failed_stage" => $stage,
            "completed_stages" => array_slice($stages, 0, $i),
            "error_code" => $this->client->last_error_code,
            "error" => $e->getMessage(),
            "message" => $message,
        ]);
        $this->client->write_status_file($message);

        $red = "\033[31m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $this->progress->clear_progress_line();

        if ($stage === 'preflight') {
            $error_code = $this->client->last_error_code;
            $is_not_installed =
                $error_code === 'NOT_FOUND' ||
                $error_code === 'HTML_RESPONSE';

            if ($is_not_installed) {
                $cyan = "\033[36m";
                $this->progress->print_line("\n{$red}  ✗ The exporter plugin is not installed on this site.{$r}\n\n");
                $this->progress->print_line("  To set it up, run:\n\n");
                $this->progress->print_line("    {$cyan}php reprint.phar install-exporter{$r}\n\n");
                $this->progress->print_line("  {$dim}This will show the download URL and step-by-step instructions.{$r}\n");
            } else {
                $this->progress->print_line("\n{$red}  ✗ Preflight failed{$r}\n");
                $this->progress->print_line("  " . implode("\n  ", explode("\n", $e->getMessage())) . "\n");
            }
            return;
        }

        $this->progress->print_line("  {$red}✗ " . $this->stage_label($stage) . "{$r}\n");
        $this->progress->print_line("    {$dim}" . $e->getMessage() . "{$r}\n\n");
        $this->progress->print_line("  Re-run the same command to resume.\n");
    }

    /**
     * Formats a byte count for human-readable stage summaries.
     */
    private function format_bytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf("%.1f GB", $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf("%.1f MB", $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf("%.1f KB", $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
