<?php
declare(strict_types=1);

/**
 * Typed pull-state objects.
 *
 * Reprint persists pull state as JSON, so these objects keep explicit
 * in-process property names while to_array()/from_array() define the current
 * on-disk schema.
 */

class ResumableCommandCheckpointState
{
    /** @var string|null Lower-level command name, e.g. files-pull/db-pull/db-apply. */
    public ?string $command_name = null;

    /** @var string|null Completion state: in_progress, partial, complete, or null before start. */
    public ?string $completion_state = null;

    /** @var string|null Internal stage within the active command. */
    public ?string $current_stage = null;

    /** @var string|null Remote pagination cursor for resumable endpoints. */
    public ?string $remote_cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->command_name = $data['command_name'];
        $state->completion_state = $data['completion_state'];
        $state->current_stage = $data['current_stage'];
        $state->remote_cursor = $data['remote_cursor'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'command_name' => $this->command_name,
            'completion_state' => $this->completion_state,
            'current_stage' => $this->current_stage,
            'remote_cursor' => $this->remote_cursor,
        ];
    }
}

class DatabaseTableIndexState
{
    /** @var string|null Path to the db table index file. */
    public ?string $file = null;

    /** @var int Number of tables indexed. */
    public int $tables = 0;

    /** @var int Estimated number of rows across indexed tables. */
    public int $rows_estimated = 0;

    /** @var int Bytes represented by the index. */
    public int $bytes = 0;

    /** @var string|null Timestamp of the latest index update. */
    public ?string $updated_at = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->file = $data['file'];
        $state->tables = $data['tables'];
        $state->rows_estimated = $data['rows_estimated'];
        $state->bytes = $data['bytes'];
        $state->updated_at = $data['updated_at'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'file' => $this->file,
            'tables' => $this->tables,
            'rows_estimated' => $this->rows_estimated,
            'bytes' => $this->bytes,
            'updated_at' => $this->updated_at,
        ];
    }
}

class FileDiffProgressState
{
    /** @var int Byte offset into the next remote index while diffing. */
    public int $next_remote_index_byte_offset = 0;

    /** @var string|null Last local index entry path consumed at the current next remote index byte offset. */
    public ?string $last_consumed_local_index_entry_path = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->next_remote_index_byte_offset =
            $data['next_remote_index_byte_offset'];
        $state->last_consumed_local_index_entry_path =
            $data['last_consumed_local_index_entry_path'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'next_remote_index_byte_offset' =>
                $this->next_remote_index_byte_offset,
            'last_consumed_local_index_entry_path' =>
                $this->last_consumed_local_index_entry_path,
        ];
    }
}

class RemoteFileIndexCursorState
{
    /** @var string|null Remote file-index cursor. */
    public ?string $cursor = null;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->cursor = $data['cursor'];
        return $state;
    }

    public function to_array(): array
    {
        return ['cursor' => $this->cursor];
    }
}

class FetchListProgressState
{
    /** @var int Current byte offset into the fetch-list file. */
    public int $offset = 0;

    /** @var int Next byte offset after the current batch. */
    public int $next_offset = 0;

    /** @var string|null Path to the current batch file. */
    public ?string $batch_file = null;

    /** @var string|null Cursor returned by the active fetch request. */
    public ?string $cursor = null;

    /** @var int Number of file entries in the current batch. */
    public int $batch_entries = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->offset = $data['offset'];
        $state->next_offset = $data['next_offset'];
        $state->batch_file = $data['batch_file'];
        $state->cursor = $data['cursor'];
        $state->batch_entries = $data['batch_entries'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'offset' => $this->offset,
            'next_offset' => $this->next_offset,
            'batch_file' => $this->batch_file,
            'cursor' => $this->cursor,
            'batch_entries' => $this->batch_entries,
        ];
    }
}

class FilesPullSummaryState
{
    /** @var int Number of changed files pulled in the current files-pull run. */
    public int $files_pulled = 0;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->files_pulled = $data['files_pulled'];
        return $state;
    }

    public function to_array(): array
    {
        return ['files_pulled' => $this->files_pulled];
    }
}

/**
 * db-apply state, including target database configuration retained so
 * apply-runtime can generate DB_* constants.
 */
class DatabaseApplyCommandState
{
    /** @var int SQL statements successfully executed. */
    public int $statements_executed = 0;

    /** @var int Bytes read from db.sql. */
    public int $bytes_read = 0;

    /** @var array<string,string>|null URL rewrite map selected for db-apply. */
    public ?array $rewrite_url = null;

    /** @var string|null Runtime target database engine: mysql or sqlite. */
    public ?string $target_engine = null;

    /** @var string|null Runtime database name. */
    public ?string $target_db = null;

    /** @var string|null Runtime database host. */
    public ?string $target_host = null;

    /** @var int|null Runtime database port. */
    public ?int $target_port = null;

    /** @var string|null Runtime database user. */
    public ?string $target_user = null;

    /** @var string|null Runtime database password. */
    public ?string $target_pass = null;

    /** @var string|null Runtime SQLite database path. */
    public ?string $target_sqlite_path = null;

    /** @var string[] Remote paths intentionally removed while applying runtime state. */
    public array $remote_paths_removed_from_local_site = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->statements_executed = $data['statements_executed'];
        $state->bytes_read = $data['bytes_read'];
        $state->rewrite_url = $data['rewrite_url'];
        $state->target_engine = $data['target_engine'];
        $state->target_db = $data['target_db'];
        $state->target_host = $data['target_host'];
        $state->target_port = $data['target_port'];
        $state->target_user = $data['target_user'];
        $state->target_pass = $data['target_pass'];
        $state->target_sqlite_path = $data['target_sqlite_path'];
        $state->remote_paths_removed_from_local_site = array_values($data['remote_paths_removed_from_local_site']);
        return $state;
    }

    public function to_array(): array
    {
        return [
            'statements_executed' => $this->statements_executed,
            'bytes_read' => $this->bytes_read,
            'rewrite_url' => $this->rewrite_url,
            'target_engine' => $this->target_engine,
            'target_db' => $this->target_db,
            'target_host' => $this->target_host,
            'target_port' => $this->target_port,
            'target_user' => $this->target_user,
            'target_pass' => $this->target_pass,
            'target_sqlite_path' => $this->target_sqlite_path,
            'remote_paths_removed_from_local_site' => $this->remote_paths_removed_from_local_site,
        ];
    }
}

class AdaptiveTuningState
{
    /** @var array<string,mixed> Tuner configuration. */
    public array $config = [];

    /** @var array<string,mixed> Tuner runtime state. */
    public array $state = [];

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->config = $data['config'];
        $state->state = $data['state'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'config' => $this->config,
            'state' => $this->state,
        ];
    }
}

class PullPipelineCheckpointState
{
    /** @var string|null User-facing pipeline command that owns the checkpoint. */
    public ?string $started_by_command = null;

    /** @var string[] Ordered stage names for the pipeline currently being resumed. */
    public array $stage_sequence = [];

    /** @var string|null Last whole pipeline stage saved as complete. */
    public ?string $last_completed_stage = null;

    /** @var string|null Files filter used by the pipeline. */
    public ?string $files_filter = null;

    /** @var bool Whether deferred files are still pending. */
    public bool $skipped_pending = false;

    /** @var bool Whether this pipeline completed at least once. */
    public bool $has_completed_once = false;

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->started_by_command = $data['started_by_command'];
        $state->stage_sequence = array_values($data['stage_sequence']);
        $state->last_completed_stage = $data['last_completed_stage'];
        $state->files_filter = $data['files_filter'];
        $state->skipped_pending = $data['skipped_pending'];
        $state->has_completed_once = $data['has_completed_once'];
        return $state;
    }

    public function to_array(): array
    {
        return [
            'started_by_command' => $this->started_by_command,
            'stage_sequence' => $this->stage_sequence,
            'last_completed_stage' => $this->last_completed_stage,
            'files_filter' => $this->files_filter,
            'skipped_pending' => $this->skipped_pending,
            'has_completed_once' => $this->has_completed_once,
        ];
    }
}

/**
 * In-process pull state with typed properties for each persisted field.
 *
 * This object mirrors pull/state.json. Add new persistent state here first;
 * from_array() requires the complete current schema.
 */
class PullState
{
    /** Resume checkpoint for a lower-level command run directly or inside a pull pipeline. */
    public ResumableCommandCheckpointState $active_resumable_command;
    /** @var array<string,mixed>|null */
    public ?array $preflight = null;
    public ?int $remote_protocol_version = null;
    /** @var string|null Source WordPress version saved with state. */
    public ?string $version = null;
    /** @var string|null Webhost detected during preflight. */
    public ?string $webhost = null;
    public bool $follow_symlinks = true;
    /** @var string|null Fingerprint of the local followed symlinks root; guards resume. */
    public ?string $local_followed_symlinks_root_fingerprint = null;
    public string $fs_root_nonempty_behavior = 'error';
    public string $filter = 'none';
    /** @var string|null User-Agent that worked during preflight. */
    public ?string $user_agent = null;
    public ?int $max_allowed_packet = null;
    /** @var string|null Fingerprint of resolved path mappings; guards files-pull reuse. */
    public ?string $resolved_path_mappings_fingerprint = null;
    public ?string $files_pull_only_fingerprint = null;
    public FilesPullSummaryState $files_pull_summary;
    public DatabaseTableIndexState $db_index;
    public FileDiffProgressState $diff;
    public RemoteFileIndexCursorState $index;
    public FetchListProgressState $fetch;
    public FetchListProgressState $fetch_skipped;
    /** @var string|null Path to the file being written for crash recovery. */
    public ?string $current_file = null;
    /** @var int|null Expected bytes written to the current file. */
    public ?int $current_file_bytes = null;
    /** @var int|null Expected SQL file size recorded for crash recovery. */
    public ?int $sql_bytes = null;
    /** @var int SQL statements counted while streaming db.sql. */
    public int $sql_statements_counted = 0;
    public DatabaseApplyCommandState $apply;
    /** @var string|null SQL output mode persisted for resume: file, stdout, or mysql. */
    public ?string $sql_output = null;
    /**
     * @var string|null MySQL host persisted for resume.
     *
     * The password is deliberately excluded from pull state.
     */
    public ?string $mysql_host = null;
    /** @var int|null MySQL port persisted for resume. */
    public ?int $mysql_port = null;
    /** @var string|null MySQL user persisted for resume. */
    public ?string $mysql_user = null;
    /** @var string|null MySQL database persisted for resume. */
    public ?string $mysql_database = null;
    /** Number of consecutive interrupted responses without cursor progress. */
    public int $consecutive_interrupted_responses = 0;
    /** Adaptive tuner configuration and state. */
    public AdaptiveTuningState $tuning;
    /** Resume checkpoint for the user-facing pull pipeline. */
    public PullPipelineCheckpointState $pull_pipeline;

    public function __construct()
    {
        $this->active_resumable_command = new ResumableCommandCheckpointState();
        $this->db_index = new DatabaseTableIndexState();
        $this->diff = new FileDiffProgressState();
        $this->index = new RemoteFileIndexCursorState();
        $this->fetch = new FetchListProgressState();
        $this->fetch_skipped = new FetchListProgressState();
        $this->files_pull_summary = new FilesPullSummaryState();
        $this->apply = new DatabaseApplyCommandState();
        $this->tuning = new AdaptiveTuningState();
        $this->pull_pipeline = new PullPipelineCheckpointState();
    }

    public static function from_array(array $data): self
    {
        $state = new self();
        reprint_assert_state_keys($data, array_keys($state->to_array()), self::class);
        $state->active_resumable_command = ResumableCommandCheckpointState::from_array($data['active_resumable_command']);
        $state->preflight = $data['preflight'];
        $state->remote_protocol_version = $data['remote_protocol_version'];
        $state->version = $data['version'];
        $state->webhost = $data['webhost'];
        $state->follow_symlinks = $data['follow_symlinks'];
        $state->local_followed_symlinks_root_fingerprint = $data['local_followed_symlinks_root_fingerprint'];
        $state->fs_root_nonempty_behavior = $data['fs_root_nonempty_behavior'];
        $state->filter = $data['filter'];
        $state->user_agent = $data['user_agent'];
        $state->max_allowed_packet = $data['max_allowed_packet'];
        $state->resolved_path_mappings_fingerprint = $data['resolved_path_mappings_fingerprint'];
        $state->files_pull_only_fingerprint = $data['files_pull_only_fingerprint'];
        $state->files_pull_summary = FilesPullSummaryState::from_array($data['files_pull_summary']);
        $state->db_index = DatabaseTableIndexState::from_array($data['db_index']);
        $state->diff = FileDiffProgressState::from_array($data['diff']);
        $state->index = RemoteFileIndexCursorState::from_array($data['index']);
        $state->fetch = FetchListProgressState::from_array($data['fetch']);
        $state->fetch_skipped = FetchListProgressState::from_array($data['fetch_skipped']);
        $state->current_file = $data['current_file'];
        $state->current_file_bytes = $data['current_file_bytes'];
        $state->sql_bytes = $data['sql_bytes'];
        $state->sql_statements_counted = $data['sql_statements_counted'];
        $state->apply = DatabaseApplyCommandState::from_array($data['apply']);
        $state->sql_output = $data['sql_output'];
        $state->mysql_host = $data['mysql_host'];
        $state->mysql_port = $data['mysql_port'];
        $state->mysql_user = $data['mysql_user'];
        $state->mysql_database = $data['mysql_database'];
        $state->consecutive_interrupted_responses = $data['consecutive_interrupted_responses'];
        $state->tuning = AdaptiveTuningState::from_array($data['tuning']);
        $state->pull_pipeline = PullPipelineCheckpointState::from_array($data['pull_pipeline']);
        return $state;
    }

    public function to_array(): array
    {
        return [
            'active_resumable_command' => $this->active_resumable_command->to_array(),
            'preflight' => $this->preflight,
            'remote_protocol_version' => $this->remote_protocol_version,
            'version' => $this->version,
            'webhost' => $this->webhost,
            'follow_symlinks' => $this->follow_symlinks,
            'local_followed_symlinks_root_fingerprint' => $this->local_followed_symlinks_root_fingerprint,
            'fs_root_nonempty_behavior' => $this->fs_root_nonempty_behavior,
            'filter' => $this->filter,
            'user_agent' => $this->user_agent,
            'max_allowed_packet' => $this->max_allowed_packet,
            'resolved_path_mappings_fingerprint' => $this->resolved_path_mappings_fingerprint,
            'files_pull_only_fingerprint' => $this->files_pull_only_fingerprint,
            'files_pull_summary' => $this->files_pull_summary->to_array(),
            'db_index' => $this->db_index->to_array(),
            'diff' => $this->diff->to_array(),
            'index' => $this->index->to_array(),
            'fetch' => $this->fetch->to_array(),
            'fetch_skipped' => $this->fetch_skipped->to_array(),
            'current_file' => $this->current_file,
            'current_file_bytes' => $this->current_file_bytes,
            'sql_bytes' => $this->sql_bytes,
            'sql_statements_counted' => $this->sql_statements_counted,
            'apply' => $this->apply->to_array(),
            'sql_output' => $this->sql_output,
            'mysql_host' => $this->mysql_host,
            'mysql_port' => $this->mysql_port,
            'mysql_user' => $this->mysql_user,
            'mysql_database' => $this->mysql_database,
            'consecutive_interrupted_responses' => $this->consecutive_interrupted_responses,
            'tuning' => $this->tuning->to_array(),
            'pull_pipeline' => $this->pull_pipeline->to_array(),
        ];
    }
}

/**
 * Reject pull-state shapes other than the one written by the current code.
 *
 * @param array<string,mixed> $data          Observed state data.
 * @param string[]            $expected_keys Current field names.
 */
function reprint_assert_state_keys(array $data, array $expected_keys, string $state_name): void
{
    $actual_keys = array_keys($data);
    sort($actual_keys);
    sort($expected_keys);
    if ($actual_keys === $expected_keys) {
        return;
    }

    $missing_keys = array_values(array_diff($expected_keys, $actual_keys));
    $unexpected_keys = array_values(array_diff($actual_keys, $expected_keys));
    $details = [];
    if ($missing_keys !== []) {
        $details[] = 'missing ' . implode(', ', $missing_keys);
    }
    if ($unexpected_keys !== []) {
        $details[] = 'unexpected ' . implode(', ', $unexpected_keys);
    }

    throw new UnexpectedValueException(
        $state_name . ' does not match the current state schema: ' . implode('; ', $details)
    );
}
