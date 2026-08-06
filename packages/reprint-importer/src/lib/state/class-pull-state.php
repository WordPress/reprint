<?php
declare(strict_types=1);

use Reprint\Importer\State\AdaptiveTuningState;
use Reprint\Importer\State\DatabaseApplyCommandState;
use Reprint\Importer\State\DatabaseTableIndexState;
use Reprint\Importer\State\FetchListProgressState;
use Reprint\Importer\State\FileDiffProgressState;
use Reprint\Importer\State\FilesPullSummaryState;
use Reprint\Importer\State\PullPipelineCheckpointState;
use Reprint\Importer\State\RemoteFileIndexCursorState;
use Reprint\Importer\State\ResumableCommandCheckpointState;

/**
 * In-process pull state with typed properties for each persisted field.
 *
 * This object mirrors the pull state file. Add new persistent state here first;
 * from_array() requires the complete current schema.
 */
class PullState
{
    /**
     * Config paths readable via get(), mapped to the default applied when the
     * preflight payload has no usable value at that path. A null default means
     * absence is meaningful and the caller handles it.
     */
    private const CONFIG_DEFAULTS = [
        'preflight.limits.max_request_bytes' => 4 * 1024 * 1024,
        'preflight.runtime.document_root' => '',
        'preflight.runtime.ini_get_all' => [],
        'preflight.database.wp.table_prefix' => 'wp_',
        'preflight.database.wp.paths_urls.abspath' => null,
        'preflight.database.wp.paths_urls.wp_admin_path' => null,
        'preflight.database.wp.paths_urls.wp_includes_path' => null,
        'preflight.database.wp.paths_urls.content_dir' => null,
        'preflight.database.wp.paths_urls.plugins_dir' => null,
        'preflight.database.wp.paths_urls.mu_plugins_dir' => null,
        'preflight.database.wp.paths_urls.uploads.basedir' => null,
        'preflight.wp_detect.roots' => [],
    ];

    /** Resume checkpoint for a lower-level command run directly or inside a pull pipeline. */
    public ResumableCommandCheckpointState $active_resumable_command;
    /** @var array<string,mixed>|null Verbatim preflight record; read it through preflight_record() or get(). */
    private ?array $preflight = null;
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
    /** @var string|null Files-pull path-selection fingerprint; guards resume. */
    public ?string $files_pull_path_selection_fingerprint = null;
    public FilesPullSummaryState $files_pull_summary;
    public DatabaseTableIndexState $db_index;
    public FileDiffProgressState $diff;
    public RemoteFileIndexCursorState $index;
    public FetchListProgressState $fetch;
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
        $state->files_pull_path_selection_fingerprint = $data['files_pull_path_selection_fingerprint'];
        $state->files_pull_summary = FilesPullSummaryState::from_array($data['files_pull_summary']);
        $state->db_index = DatabaseTableIndexState::from_array($data['db_index']);
        $state->diff = FileDiffProgressState::from_array($data['diff']);
        $state->index = RemoteFileIndexCursorState::from_array($data['index']);
        $state->fetch = FetchListProgressState::from_array($data['fetch']);
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

    /**
     * The verbatim preflight record, for code that reports what the server
     * said (pipeline status, pull metadata, host analyzers). Code that needs
     * an effective config value uses get() instead.
     *
     * @return array<string,mixed>|null
     */
    public function preflight_record(): ?array
    {
        return $this->preflight;
    }

    /** @param array<string,mixed>|null $entry */
    public function set_preflight_record(?array $entry): void
    {
        $this->preflight = $entry;
    }

    /**
     * Read an effective config value from the preflight payload.
     *
     * $preflight stays exactly what the server reported; defaults for missing
     * or unusable values are applied here instead. Every path read anywhere in
     * the importer must be registered in CONFIG_DEFAULTS, so a typo throws
     * instead of silently returning null.
     */
    public function get(string $path)
    {
        if (!array_key_exists($path, self::CONFIG_DEFAULTS)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Hardcoded path from our own call sites, never HTML output.
            throw new UnexpectedValueException('Unknown config path: ' . $path);
        }
        $default = self::CONFIG_DEFAULTS[$path];

        $segments = explode('.', $path);
        // The leading "preflight" segment maps to $preflight['data'], the raw payload.
        array_shift($segments);
        $value = $this->preflight['data'] ?? null;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }

        if ($value === null) {
            return $default;
        }

        // A reported value of the wrong type is as unusable as a missing one.
        // The int defaults are all sizes and limits, where the exporter reports
        // 0 when the host has none configured; stay conservative and use the
        // default there too.
        if (is_int($default)) {
            return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
        }
        if (is_string($default) && !is_string($value)) {
            return $default;
        }
        if (is_array($default) && !is_array($value)) {
            return $default;
        }

        return $value;
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
            'files_pull_path_selection_fingerprint' => $this->files_pull_path_selection_fingerprint,
            'files_pull_summary' => $this->files_pull_summary->to_array(),
            'db_index' => $this->db_index->to_array(),
            'diff' => $this->diff->to_array(),
            'index' => $this->index->to_array(),
            'fetch' => $this->fetch->to_array(),
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
