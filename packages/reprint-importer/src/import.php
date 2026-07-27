#!/usr/bin/env php
<?php
/**
 * Import client for export.php.
 *
 * Downloads SQL and files from a remote export.php script, with support for:
 * - Resumable downloads using cursors
 * - Streaming multipart parsing (no buffering)
 * - Progress reporting via JSON lines to stdout
 * - Three-phase import: files, SQL, then file deltas
 */

use function WordPress\Filesystem\wp_join_unix_paths;
use Reprint\Importer\Tuning\AdaptiveTuner;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\parse_size;
use function WordPress\Reprint\Exporter\path_is_within_root;

error_reporting(E_ALL);
ini_set("display_errors", "stderr");
ini_set("display_startup_errors", 1);

// Load composer autoloader for wp-php-toolkit dependencies
foreach ([
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
] as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        break;
    }
}

// Load vendored MySQL query stream (from sqlite-database-integration PR #264)
require_once __DIR__ . '/lib/mysql-query-stream/load.php';

// Load WordPress function stubs (needed by wp-php-toolkit outside WordPress)
require_once __DIR__ . '/lib/wp-stubs.php';

// Streaming protocol parsers.
require_once __DIR__ . '/lib/protocol/class-multipart-stream-parser.php';
if (!class_exists('MultipartStreamParser', false)) {
    class_alias(\Reprint\Importer\Protocol\MultipartStreamParser::class, 'MultipartStreamParser');
}

// Adaptive request sizing and pacing.
require_once __DIR__ . '/lib/tuning/class-adaptive-tuner.php';
if (!class_exists('AdaptiveTuner', false)) {
    class_alias(\Reprint\Importer\Tuning\AdaptiveTuner::class, 'AdaptiveTuner');
}

// Load URL rewriting components
require_once __DIR__ . '/lib/url-rewrite/load.php';

// Load host analyzers (produce a runtime manifest from preflight data)
require_once __DIR__ . '/lib/host/load.php';

// Load target runtime appliers (consume a manifest, write server config)
require_once __DIR__ . '/lib/target-runtime/load.php';

// External merge sort for large index files when exec() is unavailable
require_once __DIR__ . '/lib/external-merge-sort.php';

// Terminal progress rendering (spinner, progress lines, lifecycle messages)
require_once __DIR__ . '/lib/terminal-progress/class-terminal-progress.php';

// Typed state objects for the persisted import state.
require_once __DIR__ . '/lib/state/class-import-state.php';

// Adaptive sizing for push request bodies
require_once __DIR__ . '/lib/upload/class-push-request-sizer.php';
require_once __DIR__ . '/lib/upload/class-multipart-push-stream-client.php';
require_once __DIR__ . '/lib/push/class-push-plan.php';
require_once __DIR__ . '/lib/push/class-push-files-sender.php';

// High-level pull commands — orchestrate lower-level commands into pipelines
require_once __DIR__ . '/lib/pull/class-pull.php';

/**
 * If the ALL_PROXY environment variable is set, apply it to the cURL
 * handle via CURLOPT_PROXY.
 *
 * libcurl does inspect ALL_PROXY on its own, but only when curl is
 * built against a libc that exports the env var and when no one has
 * unset it in the PHP process. Some SAPIs and managed runtimes strip
 * the environment before PHP starts, so setting CURLOPT_PROXY
 * explicitly makes the behavior deterministic across hosts.
 *
 * Empty values are ignored — an explicit empty ALL_PROXY is the
 * shell idiom for "no proxy".
 */
function reprint_apply_curl_proxy_from_env($ch): ?string {
    $proxy = getenv('ALL_PROXY');
    if (!is_string($proxy) || $proxy === '') {
        return null;
    }
    curl_setopt($ch, CURLOPT_PROXY, $proxy);
    return $proxy;
}

/**
 * Mirror PHP's `openssl.cafile` ini value onto the cURL handle as
 * `CURLOPT_CAINFO` — workaround for WordPress Playground, where the
 * WASM curl build doesn't honor `curl.cainfo` / `openssl.cafile`
 * (both are PHP_INI_SYSTEM, and curl can't see PHP-level ini values
 * anyway). Reading the ini value in PHP and passing the path via a
 * per-handle option is the only knob that works there.
 *
 * No-op when `openssl.cafile` is empty (the typical Linux case —
 * curl uses its compile-time default). When it's set and points at
 * a readable file, we mirror it; if `curl.cainfo` was also set to
 * the same path PHP's curl extension already applied it to the
 * handle, so the per-handle setopt is a benign re-set.
 *
 * TODO: remove once https://github.com/WordPress/wordpress-playground
 * resolves `openssl.cafile` natively inside its WASM curl bundle.
 */
function reprint_apply_curl_ca_bundle($ch): ?string {
    // Insecure-TLS escape hatch for environments where neither
    // CURLOPT_CAINFO nor any other knob persuades the TLS layer to
    // trust the source's cert — notably WordPress Playground in the
    // browser, where networking goes through a JS TLS library running
    // inside the page (not libcurl's TLS) and that library may have
    // a CA store that pre-dates the Let's Encrypt intermediate the
    // source's cert is signed by. The wizard sets this env when it
    // hands off; we never set it for any other caller.
    if (getenv('REPRINT_INSECURE_TLS') === '1') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        return '(insecure)';
    }

    $cafile = (string) ini_get('openssl.cafile');
    if ($cafile === '' || !is_readable($cafile)) {
        return null;
    }
    curl_setopt($ch, CURLOPT_CAINFO, $cafile);
    return $cafile;
}

/**
 * The wire-protocol version this importer speaks.
 *
 * Both the export plugin (server) and the importer (client) are deployed
 * independently.  These two constants let them detect incompatibility at
 * preflight time instead of producing silent corruption.
 *
 * Bump this whenever a change to the wire protocol (cursor encoding,
 * multipart structure, header names, endpoint parameters, response format)
 * would break an older export plugin.
 */
define('IMPORT_PROTOCOL_VERSION', 1);

/**
 * The oldest *export plugin* protocol version this importer can talk to.
 *
 * During preflight-assert the importer checks that the remote's
 * protocol_version is >= this value; if not, it tells the user to
 * update the export plugin.
 *
 * Raise this when you drop backward-compatibility with old export plugins.
 * Keep it equal to IMPORT_PROTOCOL_VERSION if no backward compat is needed.
 */
define('IMPORT_MIN_EXPORT_VERSION', 1);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if (!($error['type'] & $fatal_types)) {
        return;
    }
    $json = json_encode([
        "error" => "Fatal: {$error['message']}",
        "file" => $error['file'],
        "line" => $error['line'],
        "type" => $error['type'],
    ]);
    if ($json === false) {
        $json = '{"error":"Fatal PHP error","file":"' . addslashes($error['file']) . '"}';
    }
    fwrite(STDERR, $json . "\n");
});

function resolve_sqlite_integration_path(string $suffix = ''): string
{
    $suffixes = [$suffix];
    $moved_paths = [
        '/php-polyfills.php' => '/packages/mysql-on-sqlite/src/php-polyfills.php',
        '/version.php' => '/packages/mysql-on-sqlite/src/version.php',
    ];
    if (isset($moved_paths[$suffix])) {
        $suffixes[] = $moved_paths[$suffix];
    }

    foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4)] as $project_root) {
        foreach ($suffixes as $candidate_suffix) {
            $candidate = $project_root . '/lib/sqlite-database-integration' . $candidate_suffix;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    throw new RuntimeException(
        'SQLite target support requires lib/sqlite-database-integration to be initialized.'
    );
}

function resolve_sqlite_integration_plugin_path(): string
{
    foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4)] as $project_root) {
        $root = $project_root . '/lib/sqlite-database-integration';
        $package = $root . '/packages/plugin-sqlite-database-integration';
        if (is_dir($package)) {
            return $package;
        }
        if (is_dir($root . '/wp-includes/sqlite')) {
            return $root;
        }
    }

    throw new RuntimeException(
        'SQLite runtime support requires lib/sqlite-database-integration to be initialized.'
    );
}

/**
 * Register a user-defined SQL function on a SQLite PDO. Routes to
 * Pdo\Sqlite::createFunction() on 8.4+; the legacy
 * PDO::sqliteCreateFunction() alias is deprecated in 8.5.
 */
function register_sqlite_function(PDO $sqlite_pdo, string $name, callable $fn, int $num_args = 1): void
{
    if ($sqlite_pdo instanceof PDO\SQLite) {
        $sqlite_pdo->createFunction($name, $fn, $num_args);
    } else {
        $sqlite_pdo->sqliteCreateFunction($name, $fn, $num_args);
    }
}


class ImportClient
{

    private const SAVE_STATE_EVERY_N_CHUNKS = 50;
    private const STATE_PATH_ENCODING_PREFIX = "base64:";
    private const SQLITE_PREPARED_INSERT_CACHE_MAX = 128;

    /**
     * Maximum number of consecutive cURL timeouts with no cursor progress
     * before the importer gives up. This prevents infinite retry loops
     * when the remote server is genuinely unresponsive.
     */
    private const MAX_CONSECUTIVE_TIMEOUTS = 3;

    /** @var string Export server URL. */
    public $remote_url;

    /** @var string Directory for import state files (.import-state.json, db.sql, etc.). */
    public $state_dir;

    /** @var string Directory where downloaded site files are written (no filesystem-root/ wrapper). */
    public $fs_root;

    /** @var string Path to .import-state.json — persists command, cursor, stage across invocations. */
    private $state_file;

    /**
     * @var float Monotonic timestamp of last progress JSON line emitted.
     * Used with $progress_throttle to rate-limit stdout progress output.
     */
    private $last_progress_output = 0;

    /** @var float Minimum seconds between progress output lines. */
    private $progress_throttle = 1.0;

    /**
     * @var string Path to .import-index.jsonl — sorted JSON-lines file tracking every
     * imported file's path, ctime, size, and type. Used for delta detection: on the next
     * sync we compare this against the remote index to decide what to download or delete.
     */
    private $index_file;

    /**
     * @var string|null Path to .import-index-updates.jsonl — temporary append-only file that
     * collects index mutations (upserts and deletes) during the current run. Merged into
     * $index_file at the end of a successful sync.
     */
    private $index_updates_file;

    /** @var resource|null Open file handle for $index_updates_file while writing. */
    private $index_updates_handle;

    /** @var int Number of entries written to $index_updates_file this run. */
    private $index_updates_count = 0;

    /**
     * Deduplication state for index updates. Consecutive upsert_index_entry() or
     * delete_index_entry() calls for the same path are collapsed into one write.
     *
     * @var string|null Last path written to the index updates file.
     */
    private $last_update_path = null;

    /** @var bool|null Whether the last index update was a deletion (true) or upsert (false). */
    private $last_update_delete = null;

    /** @var int|null ctime of the last upserted index entry. */
    private $last_update_ctime = null;

    /** @var int|null Size in bytes of the last upserted index entry. */
    private $last_update_size = null;

    /** @var string|null Type ("file", "link", "dir") of the last upserted index entry. */
    private $last_update_type = null;

    /**
     * @var string Path to .import-remote-index.jsonl — latest file index received
     * from the server, including directory `empty` fields when available.
     */
    private $remote_index_file;

    /** @var string Path to .import-download-list.jsonl — files to download, computed by diffing remote vs local index. */
    private $download_list_file;

    /** @var string Path to .import-download-list-skipped.jsonl — files skipped by --filter, downloaded later with --filter=skipped-earlier. */
    private $skipped_download_list_file;

    /** @var string Path to .import-audit.log — append-only log of every operation for debugging. */
    private $audit_log;

    /** @var string Path to .import-volatile-files.json — files the server marks as frequently-changing. */
    private $volatile_files_file;

    /** @var bool When true, emit detailed operation logs to stdout. Set via --verbose. */
    private $verbose_mode = false;

    /** @var bool Whether stdout is a TTY (enables interactive progress display). */
    private $is_tty;

    /** @var int Running count of files imported in the current invocation. */
    private $files_imported = 0;

    /** @var int|null Total entries in the current download list.  Set once
     *  at the start of download_files_from_list() by counting newlines. */
    private $download_list_total = null;

    /** @var int|null Entries already processed (before the current offset)
     *  in the download list.  Computed at list start and incremented after
     *  each batch completes.  This is the cumulative, restart-safe counter
     *  that consumers should display as "files done". */
    private $download_list_done = null;

    /**
     * @var ImportState Persistent import state loaded from / saved to $state_file.
     *
     * The on-disk schema remains JSON, but in-process code gets documented
     * state objects and typed nested properties.
     */
    public $state;

    /** @var bool Set to true by SIGTERM/SIGINT handler to finish the current chunk and exit cleanly. */
    private $shutdown_requested = false;

    /** @var int|null First signal asking files-push to stop after its active sender step. */
    private $files_push_stop_signal = null;

    /**
     * @var bool When true, tell the server to follow symlinks that point outside
     * the document root (expanding them into real files). Enabled by default,
     * disable with --no-follow-symlinks. Persisted in state so it survives
     * across invocations.
     */
    private $follow_symlinks = true;

    /**
     * @var string|null Destination directory for escaping (out-of-scope) symlink targets,
     * nested by the source path. null keeps the default: each symlink target is
     * mirrored at its own source path underneath --fs-root.
     *
     * Usage: --follow-symlinks=<dir>
     */
    private $symlink_bundle_directory = null;

    /** @var array|null Cached result of get_export_directories(). */
    private $export_directories_cache = null;

    /**
     * @var bool When true, ask the server to ship the default-skipped
     * generated content (wp-content/cache, .git, node_modules, etc.).
     *
     * The server's file-index endpoint filters these by default so a
     * typical migration doesn't waste bytes on regeneratable junk. Set
     * to true with --include-caches when the consumer genuinely needs
     * those paths transferred (for example, debugging a caching plugin
     * or migrating a site whose cache holds first-render-only artifacts
     * with no source).
     */
    private $include_caches = false;

    /**
     * @var string Controls behavior when the fs root is non-empty at import start.
     *
     * 'error' (default): throw an error if the fs root is non-empty.
     * 'preserve-local': preserve existing files, symlinks, and directories in the
     * fs root instead of overwriting them; non-writable directories are skipped
     * gracefully and logged to the audit log.
     *
     * On the first sync, existing fs root content is left untouched — any file,
     * symlink, or directory that already exists at a path the remote tries to write
     * is skipped and never added to the local index.
     *
     * On subsequent delta syncs, preserved paths survive because the importer only
     * acts on paths listed in the remote index. Local-only hosting infrastructure
     * (e.g. __wp__ symlinks, drop-in symlinks, shared plugin directories) is simply
     * invisible to the diff and never touched.
     *
     * Set via --on-fs-root-nonempty, persisted in state so it survives across invocations.
     */
    private $fs_root_nonempty_behavior = 'error';

    /**
     * Controls which files are downloaded during files-pull.
     *
     *   "none"             — download everything (default)
     *   "essential-files"  — skip uploads, download only code/config/themes/plugins
     *   "skipped-earlier"  — download only files that a prior --filter=essential-files skipped
     *
     * Set via --filter=<value>, persisted in state so it survives across
     * resume cycles within the same run.
     */
    private $filter = "none";

    /** @var string|null Extra remote directory to include in the export (--extra-directory). */
    private $extra_directory = null;

    /**
     * @var array<string,string> Resolved remap rules: full source path => its
     * full local target path (both absolute). Empty = no remapping (files land
     * nested based on their source).
     */
    private $remap_rules = [];

    /**
     * @var array<int,string> Resolved `--only` file paths: a list of real source
     * absolute path prefixes the files-pull command is restricted to. Empty = full sync
     * (every detected root).
     */
    private $pull_only_files_with_path_prefixes = [];

    /** @var AdaptiveTuner|null Adjusts request pacing based on server response times and errors. */
    private $tuner = null;

    /** @var Site_Export_HMAC_Client|null Signs requests when HMAC auth is configured. */
    private $hmac_client = null;

    /**
     * @var int|null MySQL max_allowed_packet value for the import database connection.
     * Passed to the server so it can split SQL statements to fit within this limit.
     */
    private $max_allowed_packet = null;

    /** @var int|null Last curl error number, for retry/diagnostic logic. */
    private $last_curl_errno = null;

    /** @var bool Whether the last curl request timed out. */
    private $last_curl_timeout = false;

    /** @var string|null Machine-readable error code from the last diagnose_http_error() call. */
    public $last_error_code = null;

    /** @var TerminalProgress Renders progress and lifecycle output to the terminal. */
    private TerminalProgress $progress;

    /** @var Pull Orchestrates high-level pull pipelines. */
    private Pull $pull;

    /** @var int Cumulative count of index entries written (survives retries). */
    private $index_entries_counted = 0;

    /**
     * Memoized lookups for "does remote index contain this path or any descendant path?"
     * keyed by normalized absolute path.
     *
     * @var array<string,bool>
     */
    private $remote_index_prefix_cache = [];

    /** @var int|null Current step in a multi-step pipeline (1-indexed). Set via --step. */
    private $pipeline_step = null;

    /** @var int|null Total number of pipeline steps. Set via --steps. */
    private $pipeline_steps = null;

    /** @var string Path to .import-status.json — machine-readable status for external progress readers. */
    private $status_file;

    /** @var string SQL output mode: 'file' (default), 'stdout', or 'mysql'. */
    private $sql_output_mode = 'file';

    /** @var string|null MySQL host for --sql-output=mysql. */
    private $mysql_host;

    /** @var int|null MySQL port for --sql-output=mysql. */
    private $mysql_port;

    /** @var string|null MySQL user for --sql-output=mysql. */
    private $mysql_user;

    /** @var string|null MySQL password for --sql-output=mysql. */
    private $mysql_password;

    /** @var string|null MySQL database for --sql-output=mysql. */
    private $mysql_database;

    /** @var resource File descriptor for progress output — STDOUT normally, STDERR in stdout mode. */
    private $progress_fd;

    /**
     * @var int Process exit code. 0 = import complete, 2 = partial progress
     * (caller should invoke again to continue).
     */
    public $exit_code = 0;

    public function __construct(
        string $remote_url,
        string $state_dir,
        string $fs_root,
        ?string $signal_handling_command = null
    )
    {
        // Register the command's signal behavior before constructor work can
        // create state or receive a signal under another command's policy.
        if (function_exists("pcntl_signal")) {
            // Enable async signals (PHP 7.1+) so signals work during blocking operations
            if (function_exists("pcntl_async_signals")) {
                pcntl_async_signals(true);
            }
            if ($signal_handling_command === 'files-push') {
                $this->enable_files_push_signal_handling();
            } elseif ($signal_handling_command !== 'files-diff') {
                // files-diff must not save the pull command's state from a
                // shutdown handler; default signal behavior ends the report.
                pcntl_signal(SIGINT, [$this, "handle_shutdown"]);
                pcntl_signal(SIGTERM, [$this, "handle_shutdown"]);
            }
        }

        $this->remote_url = rtrim($remote_url, "?&");
        $this->state_dir = rtrim($state_dir, "/");
        $this->fs_root = rtrim($fs_root, "/");
        $this->state_file = $this->state_dir . "/.import-state.json";
        $this->index_file = $this->state_dir . "/.import-index.jsonl";
        $this->index_updates_file =
            $this->state_dir . "/.import-index-updates.jsonl";
        $this->remote_index_file =
            $this->state_dir . "/.import-remote-index.jsonl";
        $this->download_list_file =
            $this->state_dir . "/.import-download-list.jsonl";
        $this->skipped_download_list_file =
            $this->state_dir . "/.import-download-list-skipped.jsonl";
        $this->audit_log = $this->state_dir . "/.import-audit.log";
        $this->volatile_files_file = $this->state_dir . "/.import-volatile-files.json";
        $this->status_file = $this->state_dir . "/.import-status.json";

        // Detect TTY for progress display. In stdout mode this is re-evaluated
        // against STDERR in run() once we know the output mode.
        $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
        $this->progress_fd = STDOUT;
        $this->progress = new TerminalProgress($this->is_tty, $this->progress_fd);
        $this->pull = new Pull($this, $this->progress);

        // Create directories
        if (!is_dir($this->state_dir)) {
            if (!mkdir($this->state_dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->state_dir}");
            }
        }
        if (!is_dir($this->fs_root)) {
            if (!mkdir($this->fs_root, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->fs_root}");
            }
        }

        $this->state = ImportState::from_array($this->default_state());
    }

    /**
     * Return current index size.
     */
    public function index_count(): int
    {
        if (!is_file($this->index_file)) {
            return 0;
        }
        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return 0;
        }
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }

    /**
     * Upsert a file entry in the index.
     */
    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        $this->record_index_update_file($path, $ctime, $size, $type);
    }

    /**
     * Delete a file entry from the index.
     */
    private function delete_index_entry(string $path): void
    {
        $this->record_index_update_deletion($path);
    }

    /**
     * Recover and merge any pending index updates from a previous run.
     */
    private function recover_index_updates(): void
    {
        if (
            $this->index_updates_file &&
            file_exists($this->index_updates_file)
        ) {
            $this->finalize_index_updates();
        }
    }

    /**
     * Log to audit file (always) and optionally to console.
     *
     * @param string $message Message to log
     * @param bool $to_console Whether to also output to console (respects verbose mode)
     */
    public function audit_log(string $message, bool $to_console = true): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $log_line = "[{$timestamp}] {$message}\n";

        // Always write to audit log
        file_put_contents($this->audit_log, $log_line, FILE_APPEND);

        // Output to console if verbose mode or if explicitly requested
        if ($to_console && $this->verbose_mode) {
            fwrite($this->progress_fd, $log_line);
        }
    }

    /** Return the typed in-process import state. */
    public function get_import_state(): ImportState
    {
        return $this->import_state();
    }

    /** Persist the current typed import state to disk. */
    public function save_import_state(): void
    {
        $this->save_state($this->import_state());
    }

    /** Mark a pull pipeline stage as completed in state. */
    public function mark_pull_stage_complete(string $stage, string $pipeline = 'pull', array $stage_sequence = []): void
    {
        $this->import_state()->pull_pipeline->started_by_command = $pipeline;
        $this->import_state()->pull_pipeline->last_completed_stage = $stage;
        if ($stage_sequence !== []) {
            $this->import_state()->pull_pipeline->stage_sequence = $stage_sequence;
        }
        $this->save_state($this->state);
    }

    /** Mark the pull pipeline as fully complete in state. */
    public function mark_pull_complete(string $pipeline = 'pull'): void
    {
        $this->import_state()->pull_pipeline->started_by_command = $pipeline;
        $this->import_state()->pull_pipeline->has_completed_once = true;
        $this->import_state()->active_resumable_command->completion_state = 'complete';
        $this->save_state($this->state);
    }

    /** Record the pull's file filter and whether deferred files remain. */
    public function set_pull_files_state(string $filter, bool $skipped_pending): void
    {
        $this->import_state()->pull_pipeline->files_filter = $filter;
        $this->import_state()->pull_pipeline->skipped_pending = $skipped_pending;
        $this->save_state($this->state);
    }

    /**
     * Resolve file-selection options after preflight is available.
     */
    public function prepare_files_pull_options(array $options, bool $assert_remap = true): void
    {
        $remap_raw = $options["remap"] ?? [];
        if (!empty($remap_raw)) {
            $this->remap_rules = $this->resolve_remap($remap_raw);
        }

        $only_raw = $options["only"] ?? [];
        if (is_string($only_raw)) {
            $only_raw = [$only_raw];
        }
        if (!empty($only_raw)) {
            $this->pull_only_files_with_path_prefixes = $this->resolve_pull_only_files_with_path_prefixes($only_raw);
        }

        if ($assert_remap) {
            $this->assert_files_remap_consistent();
        }
    }

    /** True when the skipped-download list exists and still has entries. */
    public function has_skipped_files_pending(): bool
    {
        return
            file_exists($this->skipped_download_list_file) &&
            filesize($this->skipped_download_list_file) > 0;
    }

    /**
     * Log the executed command and full argv to the audit log.
     * Called from the CLI entry point before run() so the invocation
     * is captured even if run() throws early.
     *
     * @param string       $command Canonical CLI command name.
     * @param list<string> $argv    Raw command arguments.
     * @param string|null  $pair    Files-diff or files-push pair key, when available.
     */
    public function audit_log_argv(string $command, array $argv, ?string $pair = null): void
    {
        // Mask the remote URL (argv[2]) to avoid logging secrets embedded in query strings.
        $masked = $argv;
        if (isset($masked[2]) && $command !== 'apply-runtime') {
            $masked[2] = preg_replace('/SECRET_KEY=[^&\s]+/', 'SECRET_KEY=***', $masked[2]);
            if ($command === 'files-push') {
                $masked[2] = self::mask_files_push_url_user_info($masked[2]);
            }
        }
        foreach ($masked as $argument_index => $argument) {
            if (is_string($argument) && strpos($argument, '--secret=') === 0) {
                $masked[$argument_index] = '--secret=***';
            }
        }
        $pair_field = $pair === null ? '' : " | pair={$pair}";
        $this->audit_log("COMMAND | {$command}{$pair_field} | argv=" . implode(' ', $masked), false);
    }

    /**
     * Load the volatile files tracker from disk.
     *
     * @return array<string, int> Map of path => change count
     */
    private function load_volatile_files(): array
    {
        if (!file_exists($this->volatile_files_file)) {
            return [];
        }
        $json = file_get_contents($this->volatile_files_file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the volatile files tracker to disk.
     * Deletes the file if the array is empty.
     */
    private function save_volatile_files(array $files): void
    {
        if (empty($files)) {
            if (file_exists($this->volatile_files_file)) {
                @unlink($this->volatile_files_file);
            }
            return;
        }
        $json = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return; // Don't corrupt the file
        }
        file_put_contents($this->volatile_files_file, $json . "\n");
    }

    /**
     * Record that a file changed during streaming.
     * Increments the change counter for the given path.
     */
    private function record_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        $count = ($files[$path] ?? 0) + 1;
        $files[$path] = $count;
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE | path={$path} | count={$count}");
    }

    /**
     * Clear a file from the volatile tracker after a successful download.
     */
    private function clear_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        if (!isset($files[$path])) {
            return;
        }
        unset($files[$path]);
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE CLEARED | path={$path}");
    }

    /**
     * Report volatile files to the user at sync completion.
     */
    private function report_volatile_files(): void
    {
        $files = $this->load_volatile_files();
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->audit_log(
            sprintf("VOLATILE SUMMARY | %d file(s) changed during sync", $count),
            true,
        );

        $this->progress->show_lifecycle_line("{$count} file(s) changed during sync and need re-syncing (run files-pull again):\n");

        foreach ($files as $path => $changes) {
            $suffix = $changes >= 3
                ? " (changed {$changes} times — may be too volatile to sync)"
                : " (changed {$changes} time" . ($changes > 1 ? "s" : "") . ")";
            $this->audit_log("  VOLATILE FILE | path={$path} | count={$changes}");
            $this->progress->show_lifecycle_line("  {$path}{$suffix}\n");
        }

        $this->output_progress(
            [
                "type" => "volatile_files",
                "files" => $files,
                "count" => $count,
                "message" => "{$count} file(s) changed during sync and need re-syncing (run files-pull again)",
            ],
            true,
        );
    }

    /**
     * Emit a preserve-local skip event to both TTY progress line and JSONL.
     */
    private function emit_skip_progress(string $path): void
    {
        $this->progress->show_progress_line("[skip] " . $this->display_path($path));
        $this->output_progress([
            "type" => "skip",
            "path" => $path,
            "message" => "[skip] " . $path,
        ], true);
    }

    /**
     * Run the import process with explicit command validation.
     *
     * @param array $options Options:
     *   - command: Required. One of the entries in $valid_commands below.
     *   - abort: Optional. Clear state for the command and exit immediately
     *   - verbose: Optional. Enable verbose output
     */
    public function run(array $options = []): void
    {
        $this->verbose_mode = $options["verbose"] ?? false;
        $this->progress->set_verbose_mode($this->verbose_mode);
        $this->follow_symlinks = $options["follow_symlinks"] ?? true;
        $this->include_caches = $options["include_caches"] ?? false;
        $this->extra_directory = $options["extra_directory"] ?? null;
        if (isset($options["fs_root_nonempty_behavior"])) {
            $this->fs_root_nonempty_behavior = $options["fs_root_nonempty_behavior"];
            if (!in_array($this->fs_root_nonempty_behavior, ['error', 'preserve-local'])) {
                throw new InvalidArgumentException(
                    "Invalid --on-fs-root-nonempty value: {$this->fs_root_nonempty_behavior}. " .
                        "Valid values: error, preserve-local",
                );
            }
        }
        $command = $options["command"] ?? null;

        // Apply legacy command aliases so callers using old names still work.
        static $command_aliases = [
            "files-sync" => "files-pull",
            "db-sync" => "db-pull",
            "flat-document-root" => "flat-docroot",
            "flatten-docroot" => "flat-docroot",
        ];
        if ($command && isset($command_aliases[$command])) {
            $command = $command_aliases[$command];
        }

        $abort = $options["abort"] ?? false;
        $this->pipeline_step = $options["pipeline_step"] ?? null;
        $this->pipeline_steps = $options["pipeline_steps"] ?? null;

        $valid_commands = [
            "pull",
            "pull-files",
            "pull-db",
            "files-pull",
            "files-diff",
            "files-push",
            "files-index",
            "files-stats",
            "db-pull",
            "db-index",
            "db-domains",
            "db-apply",
            "import-metadata",
            "preflight",
            "preflight-assert",
            "flat-docroot",
            "apply-runtime",
        ];

        if (!$command) {
            throw new InvalidArgumentException(
                "Command is required. Valid commands: " . implode(", ", $valid_commands),
            );
        }

        if (!in_array($command, $valid_commands, true)) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: " . implode(", ", $valid_commands),
            );
        }

        // files-diff and files-push use local push state and must not load
        // or write the pull command's .import-state.json file.
        if ($command === "files-diff") {
            $this->run_files_diff($options);
            return;
        }
        if ($command === "files-push") {
            $this->run_files_push($options);
            return;
        }

        // High-level pulls persist resume state before they enter the stage
        // runner. Reject invalid options first so a typo does not leave behind
        // state that looks like an interrupted pull.
        if (in_array($command, ["pull", "pull-db"], true)) {
            $this->pull->assert_options_valid_before_state_write($command, $options);
        }

        $this->state = ImportState::from_array($this->load_state());

        if ($command === "import-metadata") {
            $this->run_import_metadata();
            return;
        }

        // Persist follow_symlinks in state so it survives across invocations.
        // If explicitly set on CLI, store it.  Otherwise, restore from persisted state.
        if (isset($options["follow_symlinks"])) {
            $this->import_state()->follow_symlinks = $this->follow_symlinks;
            $this->save_state($this->state);
        } elseif (isset($this->import_state()->follow_symlinks)) {
            $this->follow_symlinks = $this->import_state()->follow_symlinks;
        }

        if (isset($options["symlink_bundle_directory"])) {
            $this->symlink_bundle_directory = $this->resolve_symlink_bundle_directory($options["symlink_bundle_directory"]);
            $this->follow_symlinks = true;
            $this->import_state()->follow_symlinks = true;
            $this->save_state($this->state);
        }

        // Persist fs_root_nonempty_behavior in state so it survives across invocations.
        // 'preserve-local' preserves existing local files instead of overwriting
        // them, and gracefully skips non-writable directories.
        if (isset($options["fs_root_nonempty_behavior"])) {
            $this->import_state()->fs_root_nonempty_behavior = $this->fs_root_nonempty_behavior;
            $this->save_state($this->state);
        } else {
            $this->fs_root_nonempty_behavior = $this->import_state()->fs_root_nonempty_behavior ?? 'error';
        }

        // Persist filter in state so it survives across resume cycles.
        //
        //   --filter=none             download everything (default)
        //   --filter=essential-files   skip uploads, download code/config/themes/plugins
        //   --filter=skipped-earlier   download only files skipped by a prior essential-files run
        //
        // Changing the filter mid-flight is not allowed.  The user must either
        // start fresh (--abort) or finish the current sync before switching.
        // Exceptions: essential-files may advance to skipped-earlier once
        // complete, and skipped-earlier (a terminal tail-fetch) may always give
        // way to a fresh full sync.
        if (isset($options["filter"])) {
            $next = $options["filter"];
            if (
                in_array($command, ["pull", "pull-files"], true) &&
                !in_array($next, ["none", "essential-files"], true)
            ) {
                throw new InvalidArgumentException(
                    "Invalid --filter value for {$command}: {$next}. " .
                        "Valid values: none, essential-files",
                );
            }
            $prev = $this->import_state()->filter ?? null;
            $status = $this->import_state()->active_resumable_command->completion_state ?? null;
            // Leaving a skipped-earlier tail-fetch for another sync is a fresh
            // cycle, not a mid-flight filter change.
            $is_mid_flight =
                $prev !== null &&
                $prev !== $next &&
                $prev !== "skipped-earlier" &&
                $status !== null &&
                $status !== "complete";
            if ($is_mid_flight) {
                throw new RuntimeException(
                    "Cannot change --filter from '{$prev}' to '{$next}' while a sync is in progress. " .
                        "Finish the current sync or use --abort to start over.",
                );
            }
            $this->filter = $next;
            $this->import_state()->filter = $this->filter;
            $this->save_state($this->state);
        } elseif (isset($this->import_state()->filter)) {
            $this->filter = $this->import_state()->filter;
        }

        // Persist max_allowed_packet in state so it survives across invocations.
        // The client sends this to the server so SQL statements are capped to a
        // size the client's MySQL instance can actually import.
        if (isset($options["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $options["max_allowed_packet"];
            $this->import_state()->max_allowed_packet = $this->max_allowed_packet;
            $this->save_state($this->state);
        } elseif (isset($this->import_state()->max_allowed_packet)) {
            $this->max_allowed_packet = (int) $this->import_state()->max_allowed_packet;
        }

        if (in_array($command, ["pull", "pull-db"], true)) {
            $options["sql_output"] = "file";
        }

        // Persist sql_output_mode in state so it survives across resume invocations.
        // The password is NOT persisted — it must be supplied on every run (or via
        // the MYSQL_PASSWORD environment variable).
        if (isset($options["sql_output"])) {
            $mode = $options["sql_output"];
            if (!in_array($mode, ["file", "stdout", "mysql"])) {
                throw new InvalidArgumentException(
                    "Invalid --sql-output mode: {$mode}. Valid modes: file, stdout, mysql",
                );
            }
            $this->sql_output_mode = $mode;
            $this->import_state()->sql_output = $mode;
        } elseif (isset($this->import_state()->sql_output)) {
            $this->sql_output_mode = $this->import_state()->sql_output;
        }

        // In stdout mode, SQL goes to STDOUT, so progress/status output must
        // go to STDERR to keep the streams separate.
        if ($this->sql_output_mode === "stdout") {
            $this->progress_fd = STDERR;
            $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDERR);
            $this->progress->set_progress_fd($this->progress_fd);
            $this->progress->set_is_tty($this->is_tty);
        }

        // MySQL connection parameters for --sql-output=mysql.
        if (isset($options["mysql_host"])) {
            $this->mysql_host = $options["mysql_host"];
            $this->import_state()->mysql_host = $this->mysql_host;
        } elseif (isset($this->import_state()->mysql_host)) {
            $this->mysql_host = $this->import_state()->mysql_host;
        }

        if (isset($options["mysql_port"])) {
            $this->mysql_port = (int) $options["mysql_port"];
            $this->import_state()->mysql_port = $this->mysql_port;
        } elseif (isset($this->import_state()->mysql_port)) {
            $this->mysql_port = (int) $this->import_state()->mysql_port;
        }

        if (isset($options["mysql_user"])) {
            $this->mysql_user = $options["mysql_user"];
            $this->import_state()->mysql_user = $this->mysql_user;
        } elseif (isset($this->import_state()->mysql_user)) {
            $this->mysql_user = $this->import_state()->mysql_user;
        }

        if (isset($options["mysql_database"])) {
            $this->mysql_database = $options["mysql_database"];
            $this->import_state()->mysql_database = $this->mysql_database;
        } elseif (isset($this->import_state()->mysql_database)) {
            $this->mysql_database = $this->import_state()->mysql_database;
        }

        $this->save_state($this->state);

        // Password is never persisted — must be supplied each run or via env.
        if (isset($options["mysql_password"])) {
            $this->mysql_password = $options["mysql_password"];
        } elseif (getenv("MYSQL_PASSWORD") !== false) {
            $this->mysql_password = getenv("MYSQL_PASSWORD");
        }

        // Validate mysql mode requirements.
        if ($this->sql_output_mode === "mysql" && empty($this->mysql_database)) {
            throw new InvalidArgumentException(
                "--mysql-database is required when using --sql-output=mysql",
            );
        }

        $this->initialize_tuner($options);

        // Initialize HMAC authentication if a shared secret was provided.
        // When set, every outgoing HTTP request will include X-Auth-Signature,
        // X-Auth-Nonce, and X-Auth-Timestamp headers so the export API can verify
        // the caller without a SECRET_KEY in the URL.
        if (!empty($options["secret"])) {
            if (!class_exists('Site_Export_HMAC_Client')) {
                throw new RuntimeException(
                    'Streaming exporter runtime not found. Run composer install before using --secret.'
                );
            }
            $this->hmac_client = new \Site_Export_HMAC_Client($options["secret"]);
        }

        // Pull-like commands orchestrate preflight and lower-level stages
        // internally, so they run before the normal command dispatch.
        if (in_array($command, ["pull", "pull-files", "pull-db"], true)) {
            if ($abort) {
                $this->pull->abort($command);
                return;
            }
            try {
                switch ($command) {
                    case "pull":
                        $this->pull->run($options);
                        break;
                    case "pull-files":
                        $this->pull->run_pull_files($options);
                        break;
                    case "pull-db":
                        $this->pull->run_pull_db($options);
                        break;
                }
            } catch (\Exception $e) {
                if ($e instanceof \PullFailureReportedException) {
                    $previous = $e->getPrevious();
                    if ($previous instanceof \Exception) {
                        throw $previous;
                    }
                    throw $e;
                }
                $this->output_progress([
                    "status" => "error",
                    "error" => $e->getMessage(),
                    "message" => "Error: " . $e->getMessage(),
                ]);
                $this->write_status_file($e->getMessage());
                throw $e;
            }
            return;
        }

        // preflight and preflight-assert run the preflight themselves and
        // exit directly — they do not go through the normal command dispatch.
        if ($command === "preflight") {
            $this->run_preflight();
            $this->run_preflight_report();
            return;
        }

        // db-domains and db-apply are local-only commands that don't need a remote server.
        if ($command === "db-domains") {
            $this->run_db_domains();
            return;
        }
        if ($command === "files-stats") {
            $this->run_files_stats();
            return;
        }
        if ($command === "flat-docroot") {
            $this->run_flat_document_root($options);
            return;
        }
        if ($command === "apply-runtime") {
            $this->run_apply_runtime($options);
            return;
        }
        if ($command === "db-apply") {
            if ($abort) {
                $this->handle_abort($command);
                return;
            }
            try {
                $this->run_db_apply($options);
                $final_status = $this->import_state()->active_resumable_command->completion_state ?? "complete";
                $this->output_progress(["status" => $final_status, "message" => "db-apply {$final_status}"]);
                if ($final_status === "partial") {
                    $this->exit_code = 2;
                }
            } catch (Exception $e) {
                $this->output_progress([
                    "status" => "error",
                    "error" => $e->getMessage(),
                    "error_code" => $this->last_error_code,
                    "message" => "Error: " . $e->getMessage(),
                ]);
                $this->write_status_file($e->getMessage());
                throw $e;
            }
            return;
        }

        // All other commands require a prior preflight run.
        $this->require_preflight();

        if (in_array($command, ["files-pull", "files-index"], true)) {
            $this->prepare_files_pull_options($options, $command === "files-pull" && !$abort);
        }

        // Handle --abort: clear state for the command and exit immediately.
        // To abort a sync, run `<command> --abort` (clears state), then
        // run `<command>` again (starts fresh).
        if ($abort) {
            // @TODO: Co-locate abort for each command with the run_*() method
            //        for that command.
            $this->handle_abort($command);
            return;
        }

        // Dispatch to appropriate command handler
        try {
            switch ($command) {
                case "preflight-assert":
                    $this->run_preflight_assert();
                    return;

                case "files-pull":
                    $this->run_files_sync();
                    break;

                case "files-index":
                    $this->run_files_index();
                    break;

                case "db-pull":
                    $this->run_db_sync();
                    break;
                case "db-index":
                    $this->run_db_index();
                    break;
            }

            $final_status = $this->import_state()->active_resumable_command->completion_state ?? "complete";
            $this->output_progress(["status" => $final_status, "message" => "{$command} {$final_status}"]);

            // Exit code 2 signals "partial progress, call me again" so
            // runner scripts can loop on $? without reading the state file.
            if ($final_status === "partial") {
                $this->exit_code = 2;
            }
        } catch (Exception $e) {
            $this->output_progress([
                "status" => "error",
                "error" => $e->getMessage(),
                "error_code" => $this->last_error_code,
                "message" => "Error: " . $e->getMessage(),
            ]);
            $this->write_status_file($e->getMessage());
            throw $e;
        }
    }

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions contain CLI filesystem paths, never HTML output.
    /**
     * Reports local paths changed since the pair's previous local index.
     *
     * files-diff makes no network request. It runs one complete PushPlan
     * against the previous local index a completed files-push published, then
     * streams the finished push and delete lists from the beginning. Every run
     * reports the whole diff, so an interrupted report needs no resume state:
     * running the command again prints the complete report.
     *
     * @param array $options {
     *     Parsed files-diff options and context.
     *
     *     @type array $files_diff_context Validated pair context.
     * }
     * @phpstan-param array<string,mixed> $options
     */
    private function run_files_diff(array $options): void
    {
        $context = $options['files_diff_context'] ?? self::prepare_files_pair_context(
            $this->remote_url,
            $this->state_dir,
            $this->fs_root,
            'files-diff'
        );
        if (!is_array($context)) {
            throw new InvalidArgumentException('files-diff requires its validated command context.');
        }

        $push_state_directory = $context['push_state_directory'];
        $previous_local_index = $push_state_directory . '/previous_local_index.jsonl';
        $missing_previous_local_index_message =
            'files-diff requires the pair\'s previous local index, which a completed files-push publishes '
            . 'for the same target URL, state directory, and local tree.';
        if (!is_dir($push_state_directory)) {
            throw new RuntimeException($missing_previous_local_index_message);
        }

        $lock_handle = $this->acquire_files_diff_lock($push_state_directory);
        $plan_directory = $push_state_directory . '/files-diff-plan';
        try {
            if (!is_file($previous_local_index)) {
                throw new RuntimeException($missing_previous_local_index_message);
            }

            // Build the complete local-only plan from scratch without target
            // exclusions. An interrupted files-diff discards it and runs it again.
            $this->remove_local_plan_directory($plan_directory);
            if (!mkdir($plan_directory, 0755, true)) {
                throw new RuntimeException('Failed to create the local plan directory: ' . $plan_directory . '.');
            }
            $excluded_paths_path = $plan_directory . '/no_target_exclusions.json';
            if (file_put_contents($excluded_paths_path, "[]\n") === false) {
                throw new RuntimeException('Failed to write the empty exclusions file: ' . $excluded_paths_path . '.');
            }
            $plan = PushPlan::start(
                $plan_directory,
                $context['local_tree'],
                $previous_local_index,
                $excluded_paths_path
            );
            try {
                while ($plan->next_step()) {
                    continue;
                }
            } finally {
                $plan->close();
            }

            $type_by_plan_type = [
                'file' => 'file',
                'directory' => 'dir',
                'symlink' => 'link',
            ];
            $local_paths_to_push_count = 0;
            foreach (
                $this->read_planned_local_paths_to_push($plan->get_local_paths_to_push_path())
                as $entry
            ) {
                $line = json_encode([
                    'command' => 'files-diff',
                    'action' => 'push',
                    'path_b64' => $entry['path'],
                    'type' => $type_by_plan_type[$entry['type']],
                    'size' => $entry['size'],
                    'ctime' => $entry['ctime'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($this->progress_fd, $line) !== strlen($line)) {
                    throw new RuntimeException('Failed to write the files-diff result.');
                }
                ++$local_paths_to_push_count;
            }

            $local_paths_to_delete_count = 0;
            foreach (
                $this->read_planned_local_paths_to_delete($plan->get_local_paths_to_delete_path())
                as $local_path_to_delete
            ) {
                $line = json_encode([
                    'command' => 'files-diff',
                    'action' => 'delete',
                    'path_b64' => base64_encode($local_path_to_delete),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($this->progress_fd, $line) !== strlen($line)) {
                    throw new RuntimeException('Failed to write the files-diff result.');
                }
                ++$local_paths_to_delete_count;
            }

            $line = json_encode([
                'command' => 'files-diff',
                'status' => 'complete',
                'local_paths_to_push' => $local_paths_to_push_count,
                'local_paths_to_delete' => $local_paths_to_delete_count,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($this->progress_fd, $line) !== strlen($line)) {
                throw new RuntimeException('Failed to write the files-diff result.');
            }
            if (!fflush($this->progress_fd)) {
                throw new RuntimeException('Failed to flush the files-diff result.');
            }
        } finally {
            $this->remove_local_plan_directory($plan_directory);
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
        }
    }

    /**
     * Reads the completed local paths-to-push list.
     *
     * @param string $local_paths_to_push_path Completed plan-owned JSONL path list.
     * @return Generator Completed plan entries.
     * @phpstan-return Generator<int,array{path:string,type:'file'|'directory'|'symlink',size:int,ctime:int},mixed,void>
     */
    private function read_planned_local_paths_to_push(string $local_paths_to_push_path): Generator
    {
        $local_paths_to_push_handle = fopen($local_paths_to_push_path, 'rb');
        if (!is_resource($local_paths_to_push_handle)) {
            throw new RuntimeException('Failed to open the completed local paths-to-push list.');
        }
        try {
            while (true) {
                $line = fgets($local_paths_to_push_handle);
                if ($line === false) {
                    if (!feof($local_paths_to_push_handle)) {
                        throw new RuntimeException('Failed to read the completed local paths-to-push list.');
                    }
                    return;
                }
                // The plan wrote this list moments ago in this process; its
                // entry schema is trusted, like every other plan consumer.
                /** @var array{path:string,type:'file'|'directory'|'symlink',size:int,ctime:int} $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                yield $entry;
            }
        } finally {
            fclose($local_paths_to_push_handle);
        }
    }

    /**
     * Reads the completed local paths-to-delete list.
     *
     * @param string $local_paths_to_delete_path Completed plan-owned NUL-delimited path list.
     * @return Generator Completed local paths to delete.
     * @phpstan-return Generator<int,string,mixed,void>
     */
    private function read_planned_local_paths_to_delete(string $local_paths_to_delete_path): Generator
    {
        $local_paths_to_delete_handle = fopen($local_paths_to_delete_path, 'rb');
        if (!is_resource($local_paths_to_delete_handle)) {
            throw new RuntimeException('Failed to open the completed local paths-to-delete list.');
        }
        try {
            while (true) {
                $local_path_to_delete = stream_get_line($local_paths_to_delete_handle, 1048576, "\0");
                if ($local_path_to_delete === false) {
                    if (!feof($local_paths_to_delete_handle)) {
                        throw new RuntimeException('Failed to read the completed local paths-to-delete list.');
                    }
                    return;
                }
                yield $local_path_to_delete;
            }
        } finally {
            fclose($local_paths_to_delete_handle);
        }
    }

    /**
     * Acquires the pair's files-diff lifecycle lock.
     *
     * @return resource Open lock handle.
     */
    private function acquire_files_diff_lock(string $push_state_directory)
    {
        $lock_path = $push_state_directory . '/files-diff.lock';
        $lock_handle = fopen($lock_path, 'c+b');
        if (!is_resource($lock_handle)) {
            throw new RuntimeException('Failed to open the files-diff lifecycle lock: ' . $lock_path . '.');
        }
        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            fclose($lock_handle);
            throw new RuntimeException('Another files-diff or files-pull process is using this target and local tree.');
        }
        return $lock_handle;
    }

    /** Removes one completed or discarded local plan and confirms every removal. */
    private function remove_local_plan_directory(string $plan_directory): void
    {
        if (!is_dir($plan_directory)) {
            return;
        }
        $plan_files = scandir($plan_directory);
        if ($plan_files === false) {
            throw new RuntimeException('Failed to read the local plan directory: ' . $plan_directory . '.');
        }
        foreach ($plan_files as $plan_file) {
            if ($plan_file === '.' || $plan_file === '..') {
                continue;
            }
            $plan_file_path = $plan_directory . '/' . $plan_file;
            if (!is_file($plan_file_path) || !unlink($plan_file_path)) {
                throw new RuntimeException('Failed to remove a local plan file: ' . $plan_file_path . '.');
            }
        }
        if (!rmdir($plan_directory)) {
            throw new RuntimeException('Failed to remove the local plan directory: ' . $plan_directory . '.');
        }
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Runs one caller-bounded files-push lifecycle.
     *
     * One open sender performs at most one step per loop turn. A planned stop
     * cancels any open multipart request before close() releases the lifecycle
     * lock. Terminal sender outcomes are reported without retrying or opening
     * a replacement; this process never opens a second sender.
     *
     * @param array $options {
     *     Parsed files-push options and context.
     *
     *     @type string $secret             HMAC shared secret.
     *     @type bool   $force_http         Whether the operator allowed a plain-HTTP target.
     *     @type array  $files_push_context Optional context already validated by the CLI entry point.
     * }
     * @phpstan-param array<string,mixed> $options
     */
    private function run_files_push(array $options): void
    {
        $started_at = hrtime(true) / 1000000000;
        $context = $options['files_push_context'] ?? self::prepare_files_push_context(
            $this->remote_url,
            $this->state_dir,
            $this->fs_root,
            $options
        );
        if (!is_array($context)) {
            throw new InvalidArgumentException('files-push requires its validated command context.');
        }

        $this->enable_files_push_signal_handling();

        if (!class_exists('Site_Export_HMAC_Client')) {
            throw new RuntimeException(
                'Streaming exporter runtime not found. Run composer install before using --secret.'
            );
        }

        $chunk_bytes = 4 * 1024 * 1024;
        $max_execution_seconds = (int) ini_get('max_execution_time');
        $memory_limit_value = trim( (string) ini_get('memory_limit') );
        $memory_limit_bytes = $memory_limit_value === '' || $memory_limit_value === '-1'
            ? -1
            : parse_size($memory_limit_value);
        $sender_options = [
            'docroot' => $context['local_tree'],
            'push_state_directory' => $context['push_state_directory'],
            'base_url' => $context['target_url'],
            'hmac_client' => new \Site_Export_HMAC_Client($options['secret']),
            'allow_http' => $options['force_http'] ?? false,
            'chunk_bytes' => $chunk_bytes,
        ];

        $resuming = is_file($context['push_state_directory'] . '/sender.json');
        $sender = $resuming
            ? PushFilesSender::resume($sender_options)
            : PushFilesSender::start($sender_options);
        $status = null;
        $reason = null;
        $detail = null;
        $phase = $sender->get_phase();
        $previous_phase = $phase;

        try {
            $this->audit_log(
                ( $resuming ? 'RESUME' : 'START' )
                    . " files-push | pair={$context['pair']} | phase={$phase}",
                false
            );

            while ($sender->get_status() === 'continue') {
                if ($this->files_push_stop_signal !== null) {
                    $status = 'interrupted';
                    $reason = 'signal';
                    $detail = 'Received signal ' . $this->files_push_stop_signal . '.';
                    break;
                }

                $stop_cause = self::files_push_stop_cause(
                    hrtime(true) / 1000000000 - $started_at,
                    memory_get_usage(true),
                    $max_execution_seconds,
                    $memory_limit_bytes,
                    $chunk_bytes
                );
                if ($stop_cause !== null) {
                    $status = 'partial';
                    $reason = $stop_cause;
                    break;
                }

                $has_next_sender_step = $sender->next_step();
                $phase = $sender->get_phase();
                if ($phase !== $previous_phase) {
                    $this->audit_log(
                        "PHASE files-push | pair={$context['pair']} | from={$previous_phase} | to={$phase}",
                        false
                    );
                    $previous_phase = $phase;
                }
                if (!$has_next_sender_step) {
                    break;
                }
            }

            if ($sender->get_status() !== 'continue') {
                $status = $sender->get_status();
                $reason = $sender->get_reason();
                $detail = $sender->get_detail();
            }
        } catch (\Throwable $throwable) {
            $status = 'error';
            $reason = 'unexpected_error';
            $detail = $throwable->getMessage();
        } finally {
            if ($sender->get_status() === 'continue') {
                try {
                    $sender->cancel();
                } catch (\Throwable $throwable) {
                    $status = 'error';
                    $reason = 'unexpected_error';
                    $detail = ( $detail === null ? '' : $detail . ' ' )
                        . 'Could not cancel the active sender request: '
                        . $throwable->getMessage();
                }
            }
            $phase = $sender->get_phase();
            try {
                $sender->close();
            } catch (\Throwable $throwable) {
                $status = 'error';
                $reason = 'unexpected_error';
                $detail = ( $detail === null ? '' : $detail . ' ' )
                    . 'Could not close the sender lifecycle: '
                    . $throwable->getMessage();
            }
        }

        if ($status === null) {
            $status = 'error';
            $reason = 'unexpected_error';
            $detail = 'The files-push sender stopped without an outcome.';
        }

        switch ($status) {
            case 'complete':
                $audit_line = "COMPLETE files-push | pair={$context['pair']} | phase={$phase}";
                $message = 'Files push complete.';
                $this->exit_code = 0;
                break;
            case 'partial':
                $audit_line = "PARTIAL files-push | pair={$context['pair']} | phase={$phase} | cause={$reason}";
                $message = 'Files push paused at a durable boundary; run the same command again to continue.';
                $this->exit_code = 2;
                break;
            case 'interrupted':
                $audit_line = "INTERRUPTED files-push | pair={$context['pair']} | phase={$phase} | signal={$this->files_push_stop_signal}";
                $message = 'Files push was interrupted at a durable boundary; run the same command again to continue.';
                $this->exit_code = 2;
                break;
            case 'restart':
                $audit_line = "RESTART files-push | pair={$context['pair']} | phase={$phase} | reason={$reason}";
                $message = 'Files push must restart; the next run will build a fresh plan.';
                $this->exit_code = 2;
                break;
            case 'failed':
                $audit_line = "FAILED files-push | pair={$context['pair']} | phase={$phase} | reason={$reason}";
                $message = $detail === null ? 'Files push failed.' : 'Files push failed: ' . $detail;
                $this->exit_code = 1;
                break;
            case 'error':
            default:
                $audit_line = "ERROR files-push | pair={$context['pair']} | phase={$phase} | reason={$reason}";
                $message = $detail === null ? 'Files push stopped with an error.' : 'Files push stopped with an error: ' . $detail;
                $this->exit_code = 1;
                break;
        }

        $this->audit_log($audit_line, false);
        $result = [
            'command' => 'files-push',
            'pair' => $context['pair'],
            'status' => $status,
            'phase' => $phase,
            'message' => $message,
        ];
        if ($reason !== null) {
            $result['reason'] = $reason;
        }
        if ($detail !== null) {
            $result['detail'] = $detail;
        }
        // Write the flat run status without consulting import state.
        $status_payload = [
            'command' => 'files-push',
            'pair' => $context['pair'],
            'status' => $status,
            'phase' => $phase,
            'reason' => $reason,
            'detail' => $detail,
            'ts' => microtime(true),
        ];
        $status_json = json_encode(
            $status_payload,
            JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($status_json !== false) {
            $temporary_status_path = $this->status_file . '.tmp';
            if (file_put_contents($temporary_status_path, $status_json) !== false) {
                rename($temporary_status_path, $this->status_file);
            }
        }

        // Emit the sole final JSON line for non-interactive callers.
        if ($this->is_tty && !$this->verbose_mode) {
            $this->progress->show_lifecycle_line($result['message'] . "\n");
            return;
        }
        $result_json = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($result_json === false) {
            $result_json = '{"command":"files-push","status":"error","message":"Could not encode the files-push result."}';
        }
        @fwrite($this->progress_fd, $result_json . "\n");
        @flush();
    }

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions are CLI text, not HTML.
    /**
     * Validates files-push inputs and derives its local push state directory.
     *
     * @param array $options {
     *     Parsed files-push options.
     *
     *     @type string $secret     HMAC shared secret.
     *     @type bool   $force_http Whether the operator allowed a plain-HTTP target.
     * }
     * @phpstan-param array<string,mixed> $options
     * @return array {
     *     Validated files-push command context.
     *
     *     @type string $target_url           Target exporter API URL.
     *     @type string $local_tree           Canonical local tree being sent.
     *     @type string $pair                 Target/local-tree pair key.
     *     @type string $push_state_directory Local push state directory.
     * }
     * @phpstan-return array{target_url:string,local_tree:string,pair:string,push_state_directory:string}
     */
    public static function prepare_files_push_context(
        string $target_url,
        string $state_directory,
        string $local_tree,
        array $options
    ): array {
        $secret = $options['secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('files-push requires --secret=TOKEN.');
        }
        if (preg_match('/(?:\?|&)SECRET_KEY(?:=|&|$)/', $target_url) === 1) {
            throw new InvalidArgumentException(
                'files-push does not accept SECRET_KEY in the target URL; pass --secret=TOKEN.'
            );
        }

        $context = self::prepare_files_pair_context(
            $target_url,
            $state_directory,
            $local_tree,
            'files-push'
        );
        $masked_target_url = self::mask_files_push_url_user_info($target_url);
        $force_http = $options['force_http'] ?? false;
        $scheme = strtolower( (string) parse_url($target_url, PHP_URL_SCHEME) );
        if ($scheme !== 'https' && !( $scheme === 'http' && $force_http === true )) {
            throw new InvalidArgumentException(
                'The files-push target must use HTTPS: ' . $masked_target_url
                . '. Pass --force-http only for a target you trust.'
            );
        }
        return $context;
    }

    /**
     * Validates the local inputs shared by files-diff and files-push.
     *
     * This helper deliberately does not require a secret or HTTPS. files-diff
     * identifies the pull source by URL but performs no network request.
     *
     * @param string $command Command name used in error messages.
     * @return array {
     *     Validated pair context.
     *
     *     @type string $target_url           Target exporter API URL.
     *     @type string $local_tree           Canonical local tree.
     *     @type string $pair                 Target/local-tree pair key.
     *     @type string $push_state_directory Local push state directory.
     * }
     * @phpstan-return array{target_url:string,local_tree:string,pair:string,push_state_directory:string}
     */
    public static function prepare_files_pair_context(
        string $target_url,
        string $state_directory,
        string $local_tree,
        string $command
    ): array {
        $masked_target_url = self::mask_files_push_url_user_info($target_url);
        if (strpos($target_url, '#') !== false) {
            throw new InvalidArgumentException(
                'The ' . $command . ' target URL must not contain a fragment: ' . $masked_target_url . '.'
            );
        }
        $target_url_user = parse_url($target_url, PHP_URL_USER);
        $target_url_password = parse_url($target_url, PHP_URL_PASS);
        if (is_string($target_url_user) || is_string($target_url_password)) {
            throw new InvalidArgumentException(
                'The ' . $command . ' target URL must not contain URL user-info: ' . $masked_target_url . '.'
            );
        }
        if (is_link($local_tree)) {
            throw new InvalidArgumentException('The local tree must not be a symlink: ' . $local_tree . '.');
        }
        if (!is_dir($local_tree)) {
            throw new InvalidArgumentException(
                'The local tree does not exist or is not a directory: ' . $local_tree . '.'
            );
        }
        $canonical_local_tree = realpath($local_tree);
        if ($canonical_local_tree === false) {
            throw new InvalidArgumentException(
                'The local tree does not exist or is not a directory: ' . $local_tree . '.'
            );
        }
        $canonical_local_tree = rtrim($canonical_local_tree, '/') ?: '/';
        $target_url = rtrim($target_url, '?&');
        $pair = hash('sha256', $target_url . "\0" . $canonical_local_tree);
        // Resolve an absolute physical path even when its final components do not exist.
        $push_state_directory = $state_directory . '/push/' . $pair;
        if (strpos($push_state_directory, '/') !== 0) {
            $working_directory = getcwd();
            if ($working_directory === false) {
                throw new RuntimeException('Could not resolve the current working directory.');
            }
            $push_state_directory = $working_directory . '/' . $push_state_directory;
        }
        $push_state_directory = normalize_path($push_state_directory);
        $existing_state_path = $push_state_directory;
        $missing_state_components = [];
        while (
            !file_exists($existing_state_path)
            && !is_link($existing_state_path)
            && $existing_state_path !== '/'
        ) {
            array_unshift($missing_state_components, basename($existing_state_path));
            $existing_state_path = dirname($existing_state_path);
        }
        $canonical_existing_state_path = realpath($existing_state_path);
        if ($canonical_existing_state_path !== false) {
            $push_state_directory = normalize_path(
                $canonical_existing_state_path
                    . ( $missing_state_components === []
                        ? ''
                        : '/' . implode('/', $missing_state_components) )
            );
        }
        if (
            $canonical_local_tree === '/'
            || path_is_within_root($push_state_directory, $canonical_local_tree)
        ) {
            throw new InvalidArgumentException(
                'The local push state directory ' . $push_state_directory
                . ' must be outside the local tree ' . $canonical_local_tree . '.'
            );
        }

        return [
            'target_url' => $target_url,
            'local_tree' => $canonical_local_tree,
            'pair' => $pair,
            'push_state_directory' => $push_state_directory,
        ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Returns why another sender step must not begin, or null when admitted.
     */
    public static function files_push_stop_cause(
        float $elapsed_seconds,
        int $allocated_bytes,
        int $max_execution_seconds,
        int $memory_limit_bytes,
        int $chunk_bytes
    ): ?string {
        if ($max_execution_seconds > 0 && $elapsed_seconds >= $max_execution_seconds * 0.8) {
            return 'time_limit';
        }
        if (
            $memory_limit_bytes !== -1
            && $allocated_bytes + $chunk_bytes >= $memory_limit_bytes * 0.8
        ) {
            return 'memory_limit';
        }
        return null;
    }

    /**
     * Handles a first files-push signal without interrupting its active step.
     */
    public function handle_files_push_shutdown(int $signal): void
    {
        if ($this->files_push_stop_signal === null) {
            $this->files_push_stop_signal = $signal;
            return;
        }
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), SIGKILL);
        }
        die("\nForced exit.\n");
    }

    /** Installs the files-push first-signal stop behavior when PCNTL exists. */
    public function enable_files_push_signal_handling(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handle_files_push_shutdown']);
            pcntl_signal(SIGTERM, [$this, 'handle_files_push_shutdown']);
        }
    }

    /** Masks URL authority credentials without changing the pair-key input. */
    private static function mask_files_push_url_user_info(string $url): string
    {
        $masked = preg_replace(
            '~^([a-z][a-z0-9+.-]*://)[^/?#]*@~i',
            '$1***@',
            $url
        );
        return is_string($masked) ? $masked : $url;
    }

    /**
     * Handle --abort for any command: clear relevant state and exit.
     *
     * Each command has its own set of files and state fields that need clearing.
     * After clearing, we save state and return — the caller exits without
     * running the actual sync. The user then runs the command again to start fresh.
     */
    private function handle_abort(string $command): void
    {
        switch ($command) {
            case "files-pull":
                // Clear sync progress (cursor, stage, status) and transient
                // files, but keep the local index and downloaded files intact.
                // This way the next `files-pull` sees a completed local index
                // and runs a delta sync rather than re-downloading everything.
                $this->audit_log(
                    "RESTART | Clearing files-pull progress (keeping local index and files)",
                    true,
                );
                $this->reset_state();

                // Merge any pending index updates into the main index before
                // clearing transient state so we don't lose work.
                $this->recover_index_updates();
                if (
                    $this->index_updates_file &&
                    file_exists($this->index_updates_file)
                ) {
                    @unlink($this->index_updates_file);
                    $this->audit_log("FILE DELETE | {$this->index_updates_file}");
                }
                $this->index_updates_file = null;
                $this->index_updates_handle = null;
                $this->index_updates_count = 0;

                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                if (file_exists($this->download_list_file)) {
                    @unlink($this->download_list_file);
                    $this->audit_log("FILE DELETE | {$this->download_list_file}");
                }
                if (file_exists($this->skipped_download_list_file)) {
                    @unlink($this->skipped_download_list_file);
                    $this->audit_log("FILE DELETE | {$this->skipped_download_list_file}");
                }
                if (file_exists($this->volatile_files_file)) {
                    @unlink($this->volatile_files_file);
                    $this->audit_log("FILE DELETE | {$this->volatile_files_file}");
                }
                $this->import_state()->index = new RemoteFileIndexCursorState();
                $this->import_state()->fetch = new DownloadListFetchProgressState();
                $this->import_state()->fetch_skipped = new DownloadListFetchProgressState();

                $this->save_state($this->state);
                break;

            case "files-index":
                $this->audit_log(
                    "RESTART | Clearing files-index state",
                    true,
                );
                $this->import_state()->active_resumable_command->command_name = "files-index";
                $this->import_state()->active_resumable_command->completion_state = null;
                $this->import_state()->active_resumable_command->current_stage = null;
                $this->import_state()->index = new RemoteFileIndexCursorState();
                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                $this->save_state($this->state);
                break;

            case "db-pull":
                $this->audit_log(
                    "RESTART | Clearing db-pull state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                if ($this->sql_output_mode === "file") {
                    $sql_file = $this->state_dir . "/db.sql";
                    if (file_exists($sql_file)) {
                        unlink($sql_file);
                        $this->audit_log(
                            "FILE DELETE | {$sql_file} | abort db-pull",
                        );
                    }
                }
                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-pull",
                    );
                }
                $domains_file = $this->state_dir . "/.import-domains.json";
                if (file_exists($domains_file)) {
                    unlink($domains_file);
                    $this->audit_log(
                        "FILE DELETE | {$domains_file} | abort db-pull",
                    );
                }
                break;

            case "db-index":
                $this->audit_log(
                    "RESTART | Clearing db-index state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-index",
                    );
                }
                break;

            case "db-apply":
                $this->audit_log(
                    "RESTART | Clearing db-apply state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);
                break;
        }

        $this->progress->show_lifecycle_line("State cleared for {$command}.\n");

        $this->output_progress(["status" => "aborted", "message" => "State cleared for {$command}."]);
    }

    /**
     * Initialize adaptive tuning from CLI options and persisted state.
     */
    private function initialize_tuner(array $options): void
    {
        $config = $this->import_state()->tuning->config ?? [];
        $state = $this->import_state()->tuning->state ?? [];
        $cli_config = $options["tuning_config"] ?? [];

        $config = array_merge($config, $cli_config);

        $this->tuner = new AdaptiveTuner($config, $state);
        $this->import_state()->tuning->config = $this->tuner->get_config();
        $this->import_state()->tuning->state = $this->tuner->get_state();

        $this->audit_log(
            "TUNER CONFIG | " . json_encode($this->import_state()->tuning->config),
            false,
        );
    }

    /**
     * Run a cheap preflight check to record exporter environment details.
     */
    public function run_preflight(): void
    {
        $url = $this->build_url("preflight", null, []);
        $this->audit_log("PREFLIGHT REQUEST | {$url}", false);

        // Try each User-Agent until one gets a JSON response.
        // Some WAFs block certain UAs (e.g. browser UAs with custom auth
        // headers), so we cycle through candidates and remember the winner.
        $result = null;
        $payload = null;
        foreach (self::USER_AGENTS as $ua) {
            $this->import_state()->user_agent = $ua;
            $result = $this->fetch_json($url);
            $payload = $result["json"] ?? null;
            if ($payload !== null) {
                $this->audit_log("USER-AGENT OK | {$ua}", false);
                break;
            }
            $this->audit_log("USER-AGENT BLOCKED | {$ua}", false);
        }

        $entry = [
            "timestamp" => time(),
            "url" => $url,
            "http_code" => (int) ($result["http_code"] ?? 0),
            "elapsed" => (float) ($result["elapsed"] ?? 0),
            "ok" => is_array($payload) ? ($payload["ok"] ?? null) : null,
            "data" => $payload,
            "error" => $result["error"] ?? null,
            "response_body_preview" => $payload === null && isset($result["body"])
                ? substr((string) $result["body"], 0, 200)
                : null,
        ];

        $this->import_state()->preflight = $entry;

        // Store WordPress version at the top level for easy access
        $wp_version = $payload["database"]["wp"]["wp_version"] ?? null;
        if (is_string($wp_version) && $wp_version !== "") {
            $this->import_state()->version = $wp_version;
        }

        // Store remote protocol version for compatibility checks
        if (isset($payload["protocol_version"])) {
            $this->import_state()->remote_protocol_version = (int) $payload["protocol_version"];
        }
        if (isset($payload["protocol_min_version"])) {
            $this->import_state()->remote_protocol_min_version = (int) $payload["protocol_min_version"];
        }

        // Detect webhost environment from preflight data.
        // The host analyzers score based on preflight signals. We also
        // check the local fs root for a __wp__ symlink as a fallback
        // when the remote preflight didn't report enough filesystem data.
        $detected_webhost = is_array($payload) ? detect_host($payload) : 'other';
        if ($detected_webhost === 'other' && is_link($this->fs_root . '/__wp__')) {
            $detected_webhost = 'wpcloud';
        }
        $this->import_state()->webhost = $detected_webhost;
        $this->audit_log("WEBHOST DETECTED | {$detected_webhost}", true);

        $this->save_state($this->state);

        $this->audit_log(
            "PREFLIGHT RESULT | " . json_encode($entry),
            false,
        );

        // Log non-standard WordPress directory layouts for awareness
        $paths = $payload["database"]["wp"]["paths_urls"] ?? null;
        if (is_array($paths)) {
            $abspath = rtrim($paths["abspath"] ?? "", "/");
            $content_dir = rtrim($paths["content_dir"] ?? "", "/");
            $uploads_basedir = rtrim(
                $paths["uploads"]["basedir"] ?? "",
                "/",
            );
            if (
                $abspath !== "" &&
                $content_dir !== "" &&
                $content_dir !== $abspath . "/wp-content"
            ) {
                $this->audit_log(
                    "NON-STANDARD LAYOUT | wp-content is at {$content_dir} " .
                        "(expected {$abspath}/wp-content)",
                );
            }
            if (
                $content_dir !== "" &&
                $uploads_basedir !== "" &&
                strpos($uploads_basedir, $content_dir) !== 0
            ) {
                $this->audit_log(
                    "NON-STANDARD LAYOUT | uploads at {$uploads_basedir} " .
                        "is outside wp-content ({$content_dir})",
                );
            }
        }

        $this->download_runtime_files();
    }

    /**
     * Download auto_prepend_file and auto_append_file scripts into
     * state_dir/runtime_files/.
     *
     * Called on every preflight: the directory is wiped and recreated
     * so it always reflects the current server state.  Download
     * failures are tolerated since the scripts may live on paths not
     * accessible to the web server process.
     */
    private function download_runtime_files(): void
    {
        $runtime_dir = $this->state_dir . "/runtime_files";

        // Always wipe and recreate so the directory reflects current state.
        if (is_dir($runtime_dir)) {
            self::rmdir_recursive($runtime_dir);
            $this->audit_log("RUNTIME FILES | deleted {$runtime_dir}");
        }

        $ini_all = $this->import_state()->preflight["data"]["runtime"]["ini_get_all"] ?? [];
        $files = [];
        foreach (["auto_prepend_file", "auto_append_file"] as $key) {
            $path = $ini_all[$key] ?? "";
            if (is_string($path) && $path !== "") {
                $files[] = $path;
            }
        }
        $files = array_values(array_unique($files));

        if (empty($files)) {
            $this->audit_log("RUNTIME FILES | no prepend/append scripts to download");
            return;
        }

        mkdir($runtime_dir, 0755, true);

        $this->audit_log(
            "RUNTIME FILES | downloading " . count($files) . " script(s): " .
                implode(", ", $files),
        );

        $downloaded = $this->fetch_files_into($runtime_dir, $files);
        $this->audit_log("RUNTIME FILES | downloaded {$downloaded}/" . count($files) . " script(s)");
    }

    /**
     * Download a list of absolute remote paths into $target_dir,
     * preserving their directory structure.
     *
     * Issues one file_fetch request per parent directory so that an
     * inaccessible directory doesn't block the others.  All errors
     * are caught and logged as non-fatal.
     *
     * @return int Number of files successfully downloaded.
     */
    private function fetch_files_into(string $target_dir, array $files): int
    {
        $by_dir = [];
        foreach ($files as $f) {
            $parent = dirname($f);
            if ($parent !== "" && $parent !== ".") {
                $by_dir[rtrim($parent, "/")][] = $f;
            }
        }

        $downloaded = 0;

        foreach ($by_dir as $directory => $dir_files) {
            $tmp = tempnam(sys_get_temp_dir(), "fetch-into-");
            if ($tmp === false) {
                continue;
            }
            file_put_contents($tmp, json_encode($dir_files, JSON_UNESCAPED_SLASHES));

            $post_data = [
                "file_list" => new \CURLFile($tmp, "application/json", "file_list"),
            ];
            $url = $this->build_url("file_fetch", null, ["directory" => [$directory]]);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;

            $context->on_chunk = function ($chunk) use ($target_dir, $context, &$downloaded) {
                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                if ($chunk_type === "file") {
                    $raw = $chunk["headers"]["x-file-path"] ?? "";
                    $path = base64_decode($raw, true);
                    if ($path === false || $path === "") {
                        return;
                    }

                    $is_first = ($chunk["headers"]["x-first-chunk"] ?? "0") === "1";
                    $is_last = ($chunk["headers"]["x-last-chunk"] ?? "0") === "1";
                    $local_path = $target_dir . $path;

                    if ($is_first) {
                        if ($context->file_handle) {
                            fclose($context->file_handle);
                            $context->file_handle = null;
                        }
                        $dir = dirname($local_path);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0755, true);
                        }
                        $context->file_handle = @fopen($local_path, "wb");
                        $context->file_path = $local_path;
                    }

                    if ($context->file_handle && isset($chunk["body"])) {
                        fwrite($context->file_handle, $chunk["body"]);
                    }

                    if ($is_last && $context->file_handle) {
                        fclose($context->file_handle);
                        $context->file_handle = null;
                        $downloaded++;
                        $this->audit_log("Saved {$path} → {$local_path}");
                    }
                } elseif ($chunk_type === "error") {
                    $body = json_decode($chunk["body"] ?? "{}", true);
                    $error_path = isset($body["path"]) ? base64_decode($body["path"]) : "unknown";
                    $this->audit_log("Fetch error for {$error_path}: " . ($body["message"] ?? "unknown"));
                } elseif ($chunk_type === "completion") {
                    $context->saw_completion = true;
                }
            };

            try {
                $this->fetch_streaming($url, null, $context, $post_data, "file_fetch");
            } catch (\RuntimeException $e) {
                $this->audit_log(
                    "Fetch failed for directory {$directory} (non-fatal): " .
                        substr($e->getMessage(), 0, 200),
                );
            }

            @unlink($tmp);

            if ($context->file_handle) {
                fclose($context->file_handle);
            }
        }

        return $downloaded;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private static function rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }


    /**
     * Assert that a preflight has already been run and stored in state.
     * All commands except preflight/preflight-assert call this before starting work.
     */
    private function require_preflight(): void
    {
        $entry = $this->import_state()->preflight ?? null;
        if (!is_array($entry) || empty($entry["data"])) {
            throw new RuntimeException(
                "No preflight data found. Run 'preflight' or 'preflight-assert' first.",
            );
        }
    }

    /**
     * Command: preflight
     *
     * Prints the full preflight response as pretty-printed JSON to stdout.
     * The preflight itself already ran in run_preflight() — this just
     * outputs the stored result.
     */
    private function run_preflight_report(): void
    {
        $entry = $this->import_state()->preflight ?? null;
        if ($entry === null) {
            echo "No preflight data available.\n";
            exit(1);
        }
        // @TODO: Store paths as base64 strings, not raw strings, since paths can contain arbitrary bytes
        echo json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        $ok = ($entry["http_code"] ?? 0) === 200 && !empty($entry["data"]["ok"]);
        $this->write_status_file($ok ? null : "Preflight failed");
        exit($ok ? 0 : 1);
    }

    /**
     * Command: preflight-assert
     *
     * Inspects the preflight response (already fetched by run_preflight())
     * and exits with code 0 if migration looks feasible, code 1 if not.
     * Prints a human-readable pass/fail summary to stdout.
     */
    private function run_preflight_assert(): void
    {
        $entry = $this->import_state()->preflight ?? null;
        $data = $entry["data"] ?? null;
        $checks = [];
        $all_pass = true;

        // 1. Server responded OK
        $http_ok = ($entry["http_code"] ?? 0) === 200;
        $checks[] = [
            "label" => "Server responded",
            "pass" => $http_ok,
            "detail" => $http_ok
                ? "HTTP 200"
                : "HTTP " . ($entry["http_code"] ?? "no response"),
        ];
        if (!$http_ok) {
            $all_pass = false;
        }

        // 2. Top-level ok flag
        $top_ok = is_array($data) && !empty($data["ok"]);
        $checks[] = [
            "label" => "Preflight OK",
            "pass" => $top_ok,
            "detail" => $top_ok
                ? "passed"
                : ($data["error"] ?? "preflight not ok"),
        ];
        if (!$top_ok) {
            $all_pass = false;
        }

        // 3. Protocol version compatibility
        $remote_ver = $this->import_state()->remote_protocol_version ?? null;
        $remote_min = $this->import_state()->remote_protocol_min_version ?? null;
        if ($remote_ver === null) {
            $proto_ok = false;
            $proto_detail = "Remote export plugin does not report a protocol version. Update the export plugin.";
        } elseif ($remote_ver < IMPORT_MIN_EXPORT_VERSION) {
            $proto_ok = false;
            $proto_detail = "Remote protocol v{$remote_ver} is too old (client requires >= v" . IMPORT_MIN_EXPORT_VERSION . "). Update the export plugin.";
        } elseif (IMPORT_PROTOCOL_VERSION < $remote_min) {
            $proto_ok = false;
            $proto_detail = "Client protocol v" . IMPORT_PROTOCOL_VERSION . " is too old (remote requires >= v{$remote_min}). Update the importer.";
        } else {
            $proto_ok = true;
            $proto_detail = "remote v{$remote_ver}, client v" . IMPORT_PROTOCOL_VERSION;
        }
        $checks[] = [
            "label" => "Protocol compatible",
            "pass" => $proto_ok,
            "detail" => $proto_detail,
        ];
        if (!$proto_ok) {
            $all_pass = false;
        }

        // 4. Filesystem accessible
        $fs = $data["filesystem"] ?? null;
        $fs_ok = is_array($fs) && !empty($fs["ok"]);
        $checks[] = [
            "label" => "Filesystem accessible",
            "pass" => $fs_ok,
            "detail" => $fs_ok
                ? "directories readable"
                : ($fs["error"] ?? "filesystem check failed"),
        ];
        if (!$fs_ok) {
            $all_pass = false;
        }

        // 5. Database accessible
        $db = $data["database"] ?? null;
        $db_ok = is_array($db) && !empty($db["connected"]);
        $checks[] = [
            "label" => "Database accessible",
            "pass" => $db_ok,
            "detail" => $db_ok
                ? ($db["version"] ?? "connected")
                : ($db["error"] ?? "database check failed"),
        ];
        if (!$db_ok) {
            $all_pass = false;
        }

        // We do not check for any encoding issues here. We'll move over
        // the entire database as it is.

        // Print summary
        foreach ($checks as $check) {
            $icon = $check["pass"] ? "PASS" : "FAIL";
            echo "[{$icon}] {$check["label"]}: {$check["detail"]}\n";
        }

        echo "\n";
        if ($all_pass) {
            echo "Migration looks feasible.\n";
            $this->write_status_file();
            exit(0);
        } else {
            echo "Migration may not be feasible. Review the failures above.\n";
            $this->write_status_file("Preflight assertions failed");
            exit(1);
        }
    }

    /**
     * Build request params for an endpoint using the adaptive tuner.
     */
    private function get_tuned_params(string $endpoint): array
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return [];
        }
        $params = $this->tuner->get_request_params($endpoint);
        if ($endpoint === "sql_chunk") {
            /**
             * Ask the exporter to omit source rows that should not enter the local clone.
             *
             * The protocol is intentionally data-shaped instead of exporter-defined
             * tokens: table_name_without_prefix is resolved against the source site's table prefix,
             * column is matched against the source table metadata, and value_base64 lets
             * the exporter compare with FROM_BASE64(...) without interpolating the raw
             * value into SQL. _edit_lock is ephemeral editor session state and would
             * otherwise create stale "being edited" notices in the imported site.
             */
            $params["skip_rows"] = [
                [
                    "table_name_without_prefix" => "postmeta",
                    "column" => "meta_key",
                    "value_base64" => base64_encode("_edit_lock"),
                ],
            ];

            // Tell the server about the client's max_allowed_packet so it can
            // cap SQL statements to a size the client can actually import.
            if ($this->max_allowed_packet !== null) {
                $params["max_allowed_packet"] = $this->max_allowed_packet;
            }
        }
        if (!empty($params)) {
            $this->audit_log(
                "TUNER REQUEST | endpoint={$endpoint} | params=" .
                    json_encode($params),
                false,
            );
        }
        return $params;
    }

    private function handle_tuner_error(string $endpoint, array $error): void
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_error($endpoint, $error);
        $log = [
            "TUNER ERROR",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "http_code=" . (int) ($decision["http_code"] ?? 0),
            "timeout=" . (!empty($decision["timeout"]) ? "yes" : "no"),
            "curl_errno=" . (int) ($decision["curl_errno"] ?? 0),
            "error_backoff_remaining=" .
                (int) ($decision["error_backoff_remaining"] ?? 0),
        ];
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        $this->audit_log(implode(" | ", $log), false);
    }

    /**
     * Record request metrics, apply tuning decisions, and sleep if needed.
     */
    private function finalize_tuned_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_result($endpoint, [
            "wall_time" => $wall_time,
            "server_time" => $response_stats["server_time"] ?? null,
            "status" => $response_stats["status"] ?? null,
            "bytes_processed" => $response_stats["bytes_processed"] ?? null,
            "entries_processed" => $response_stats["entries_processed"] ?? null,
            "sql_bytes" => $response_stats["sql_bytes"] ?? null,
            "ttfb" => $response_stats["ttfb"] ?? null,
            "total_time" => $response_stats["total_time"] ?? null,
            "memory_used" => $response_stats["memory_used"] ?? null,
            "memory_limit" => $response_stats["memory_limit"] ?? null,
        ]);

        $log = [
            "TUNER RESULT",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "status=" . ($decision["status"] ?? "unknown"),
            "elapsed=" . sprintf("%.3f", $decision["elapsed"] ?? 0) . "s",
            "server_time=" .
                sprintf("%.3f", (float) ($decision["server_time"] ?? 0)) .
                "s",
            "wall_time=" .
                sprintf("%.3f", (float) ($decision["wall_time"] ?? 0)) .
                "s",
        ];

        if (isset($decision["work_done"]) && $decision["work_done"] !== null) {
            $log[] = "work=" . (int) $decision["work_done"];
        }
        if (isset($decision["throughput"]) && $decision["throughput"] !== null) {
            $log[] =
                "throughput=" . sprintf("%.2f", $decision["throughput"]);
        }
        if (isset($decision["throughput_ema"]) && $decision["throughput_ema"] !== null) {
            $log[] = "ema=" . sprintf("%.2f", $decision["throughput_ema"]);
        }
        if (isset($decision["throughput_ratio"]) && $decision["throughput_ratio"] !== null) {
            $log[] =
                "ratio=" . sprintf("%.2f", (float) $decision["throughput_ratio"]);
        }
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        if (isset($decision["error_backoff_remaining"])) {
            $log[] =
                "error_backoff=" . (int) $decision["error_backoff_remaining"];
        }
        $log[] = "duty=" . sprintf("%.2f", $decision["duty"] ?? 0);
        $log[] =
            "sleep=" .
            sprintf("%.2f", $decision["sleep_seconds"] ?? 0) .
            "s";
        $this->audit_log(implode(" | ", $log), false);

        $sleep = (float) ($decision["sleep_seconds"] ?? 0);
        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
    }

    /**
     * Command: files-pull
     *
     * Unified file synchronization that auto-detects initial vs delta mode:
     * - No prior completed files-pull → initial mode (index all, fetch all)
     * - Prior completed files-pull → delta mode (re-index, diff, fetch changes)
     * - In-progress files-pull → resume from saved state
     *
     * Both modes share the same pipeline: index → diff → fetch.
     */
    public function run_files_sync(): void
    {
        $state_command = $this->import_state()->active_resumable_command->command_name ?? null;

        // A full `pull` leaves active_resumable_command on its last stage
        // (db-apply), not files-pull, so a standalone skipped-earlier run can't
        // tell that files-pull finished with a deferred tail. Recover that from
        // the pipeline's skipped_pending flag and restore the files-pull
        // checkpoint. Once the fetch starts it persists command_name=files-pull,
        // so on the resume passes this recovery is skipped and won't clobber the
        // checkpoint.
        if (
            $this->filter === "skipped-earlier" &&
            $state_command !== "files-pull" &&
            $this->import_state()->pull_pipeline->skipped_pending
        ) {
            $this->import_state()->active_resumable_command->command_name = "files-pull";
            $this->import_state()->active_resumable_command->completion_state = "complete";
            $this->import_state()->active_resumable_command->current_stage = null;
            $this->save_state($this->state);
            $state_command = "files-pull";
        }

        $current_status =
            $state_command === "files-pull"
                ? $this->import_state()->active_resumable_command->completion_state ?? null
                : null;
        $has_progress =
            $state_command === "files-pull" &&
            $current_status !== null &&
            $current_status !== "complete";

        $this->recover_index_updates();
        $this->assert_files_pull_only_unchanged_while_resuming($has_progress);
        $this->assert_symlink_bundle_directory_unchanged();

        // Already completed.
        if ($current_status === "complete") {
            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            // --filter=skipped-earlier: download only the files that a prior
            // --filter=essential-files run skipped.  This is the only way to
            // resume downloading those files — no implicit behavior.
            if ($this->filter === "skipped-earlier") {
                if (!$has_skipped) {
                    throw new RuntimeException(
                        "--filter=skipped-earlier was requested but there is no skipped file list. " .
                            "Run files-pull with --filter=essential-files first.",
                    );
                }
                $this->audit_log(
                    "FETCH SKIPPED | files-pull was complete — downloading previously skipped files",
                    true,
                );
                $this->progress->show_lifecycle_line("Downloading previously skipped files\n");
                $this->output_progress([
                    "type" => "lifecycle",
                    "event" => "starting",
                    "command" => "files-pull",
                    "stage" => "fetch-skipped",
                    "message" => "Downloading previously skipped files",
                ], true);
                $this->import_state()->active_resumable_command->completion_state = "in_progress";
                $this->import_state()->active_resumable_command->current_stage = "fetch-skipped";
                $this->import_state()->files_pull_only_fingerprint = $this->files_pull_only_fingerprint();
                $this->import_state()->files_pull_summary = new FilesPullSummaryState();
                $this->save_state($this->state);
                $this->run_files_sync_pipeline();
                // The deferred tail reopens a completed files-pull. Once the
                // tail finishes, restore the completed status so later filter
                // changes are judged against the actual lifecycle state.
                if (($this->import_state()->active_resumable_command->completion_state ?? null) === "partial") {
                    return;
                }
                $this->import_state()->active_resumable_command->completion_state = "complete";
                $this->save_state($this->state);
                return;
            }

            $index_size = $this->index_count();
            $this->progress->clear_progress_line();

            $skipped_note = $has_skipped
                ? " (some files were skipped — re-run with --filter=skipped-earlier to download them)"
                : "";
            $this->audit_log(
                sprintf("files-pull already complete: %d files indexed%s", $index_size, $skipped_note),
                true,
            );

            $this->progress->show_lifecycle_line("files-pull already complete: {$index_size} files indexed\n");
            if ($has_skipped) {
                $this->progress->show_lifecycle_line("Some files were skipped. Re-run with --filter=skipped-earlier to download them.\n");
            } else {
                $this->progress->show_lifecycle_line("To re-sync, run with --abort first to clear state.\n");
            }
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "already_complete",
                "command" => "files-pull",
                "files_indexed" => $index_size,
                "has_skipped" => $has_skipped,
                "message" => "files-pull already complete: {$index_size} files indexed",
            ], true);
            return;
        }

        // --filter=skipped-earlier is only valid after a completed
        // --filter=essential-files run. Reject it as a fresh start, but allow
        // resuming a skipped-files fetch that is already under way: a
        // multi-batch fetch returns status="partial" and is re-run, at which
        // point stage is "fetch-skipped" and the resume flows through the
        // $has_progress path below (and completes via the shared tail).
        if (
            $this->filter === "skipped-earlier" &&
            ($this->import_state()->active_resumable_command->current_stage ?? null) !== "fetch-skipped"
        ) {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no completed sync with skipped files. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        // Filter out "." and ".." explicitly: standard PHP scandir() returns them,
        // but WASM PHP (WordPress Playground) does not, so a `count <= 2` shortcut
        // would mis-classify directories with one or two real entries as empty.
        $is_empty = !is_dir($this->fs_root) || count(array_diff(
            scandir($this->fs_root) ?: [],
            [".", ".."]
        )) === 0;

        // A local index from a prior completed sync means the next run is a
        // delta: re-index the remote, diff against local, fetch only changes.
        $is_delta =
            file_exists($this->index_file) &&
            filesize($this->index_file) > 0;

        // Resuming an in-progress sync
        if ($has_progress) {
            // Don't reset files_imported here — it counts files within
            // the current batch and is only reset when a batch completes
            // (in download_files_from_list). Resetting it on entry would
            // cause the progress counter to dip between pull retries.
            $index_size = $this->index_count();


            $stage = $this->import_state()->active_resumable_command->current_stage ?? "index";
            $this->audit_log(
                sprintf(
                    "RESUME files-pull | stage=%s | indexed_files=%d",
                    $stage,
                    $index_size,
                ),
                true,
            );

            $this->progress->show_lifecycle_line("Resuming files-pull\n");
            $this->progress->show_lifecycle_line("  Stage: {$stage}\n");
            $this->progress->show_lifecycle_line("  Already indexed: {$index_size} files\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "files-pull",
                "stage" => $stage,
                "index_size" => $index_size,
                "message" => "Resuming files-pull (stage: {$stage}, indexed: {$index_size} files)",
            ], true);
        } else {
            // Starting fresh — validate that target directory is empty.
            // A delta sync ($is_delta) naturally has a non-empty fs root
            // because we put those files there during the initial sync.
            if (!$is_empty && !$is_delta && $this->fs_root_nonempty_behavior === 'error') {
                throw new RuntimeException(
                    "Target directory is not empty and no cursor found. " .
                        "Either clear the target directory, use --abort flag, or use --on-fs-root-nonempty=preserve-local to sync while preserving the existing content.",
                );
            }

            $this->import_state()->active_resumable_command->command_name = "files-pull";
            $this->import_state()->active_resumable_command->completion_state = "in_progress";
            $this->import_state()->active_resumable_command->current_stage = "index";
            $this->import_state()->files_pull_only_fingerprint = $this->files_pull_only_fingerprint();
            $this->import_state()->diff = new FileDiffProgressState();
            $this->import_state()->index = new RemoteFileIndexCursorState();
            $this->import_state()->fetch = new DownloadListFetchProgressState();
            $this->import_state()->fetch_skipped = new DownloadListFetchProgressState();
            $this->import_state()->files_pull_summary = new FilesPullSummaryState();
            $this->save_state($this->state);

            if ($is_delta) {
                $this->files_imported = 0;
                $index_size = $this->index_count();

                $this->audit_log(
                    "START files-pull (delta) | index_files={$index_size}",
                    true,
                );

                $this->progress->show_lifecycle_line("Starting files-pull (delta)\n");
                $this->progress->show_lifecycle_line("  Index contains: {$index_size} files\n");
                $this->progress->show_lifecycle_line("  Stage: index\n");
                $this->output_progress([
                    "type" => "lifecycle",
                    "event" => "starting",
                    "command" => "files-pull",
                    "delta" => true,
                    "index_size" => $index_size,
                    "message" => "Starting files-pull (delta, {$index_size} files indexed)",
                ], true);
            } else {
                $this->audit_log(
                    "START files-pull ({$this->fs_root_nonempty_behavior} mode, ".($is_empty ? 'empty directory' : 'non-empty directory').")",
                    true,
                );

                $this->progress->show_lifecycle_line("Starting files-pull\n");
                $this->output_progress([
                    "type" => "lifecycle",
                    "event" => "starting",
                    "command" => "files-pull",
                    "message" => "Starting files-pull",
                ], true);
            }
        }

        $this->import_state()->active_resumable_command->command_name = "files-pull";
        $this->import_state()->active_resumable_command->completion_state = "in_progress";
        $this->save_state($this->state);

        $this->run_files_sync_pipeline();

        // Pipeline returns early with partial status if interrupted
        if (($this->import_state()->active_resumable_command->completion_state ?? null) === "partial") {
            return;
        }

        $this->import_state()->active_resumable_command->completion_state = "complete";
        $this->save_state($this->state);

        $this->progress->clear_progress_line();
        $index_size = $this->index_count();
        $label = $is_delta ? "files-pull (delta)" : "files-pull";

        $this->audit_log(
            sprintf("%s complete: %d files indexed", $label, $index_size),
            true,
        );

        $this->progress->show_lifecycle_line("{$label} complete: {$index_size} files indexed\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-pull",
            "delta" => $is_delta,
            "files_indexed" => $index_size,
            "audit_log" => $this->audit_log,
            "message" => "{$label} complete: {$index_size} files indexed",
        ], true);

        $this->report_volatile_files();
    }

    /**
     * Shared index → diff → fetch pipeline used by both initial and delta syncs.
     *
     * Reads the current stage from state and runs each stage in sequence.
     * Returns early (with partial status) if any stage doesn't complete.
     */
    private function run_files_sync_pipeline(): void
    {
        $stage = $this->import_state()->active_resumable_command->current_stage ?? "index";

        if ($stage === "index") {
            $complete = $this->download_remote_index();
            if (!$complete) {
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                return;
            }
            if ($this->follow_symlinks) {
                $this->discover_symlink_targets();
                if ($this->shutdown_requested) {
                    $this->import_state()->active_resumable_command->completion_state = "partial";
                    $this->save_state($this->state);
                    return;
                }
            }
            $this->sort_index_file($this->remote_index_file);
            $this->import_state()->active_resumable_command->current_stage = "diff";
            $this->import_state()->diff = new FileDiffProgressState();
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | clearing before diff stage",
                );
            }
            if (file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | clearing before diff stage",
                );
            }
            $this->save_state($this->state);
            $stage = "diff";
        }

        if ($stage === "diff") {
            $complete = $this->diff_indexes_and_build_fetch_list();
            if (!$complete) {
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                return;
            }

            $has_downloads =
                file_exists($this->download_list_file) &&
                filesize($this->download_list_file) > 0;
            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            // Determine the first fetch stage to run.
            if ($has_downloads) {
                $stage = "fetch";
            } elseif ($has_skipped) {
                $stage = "fetch-skipped";
            } else {
                $stage = null;
            }
            $this->import_state()->active_resumable_command->current_stage = $stage;
            $this->save_state($this->state);

            // In pull mode, finalize the scanning line with a checkmark
            // and start the download progress on a fresh line.
            if ($has_downloads && $this->progress->is_mode('pipeline')) {
                $green = "\033[32m";
                $dim = "\033[2m";
                $r = "\033[0m";
                $scanned = number_format($this->index_entries_counted);
                $this->progress->clear_progress_line();
                $this->progress->print_line("  {$green}✓{$r} Scanned {$dim}— {$scanned} entries{$r}\n");
                $total = $this->count_newlines($this->download_list_file);
                $this->progress->set_active_label(null);
                $this->progress->show_progress_line(
                    "Downloading — 0 / " . number_format($total) . " files",
                    0.0
                );
            }

            if (!$has_downloads && file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | no files to fetch",
                );
            }
            if (!$has_skipped && file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | no skipped files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            $complete = $this->download_files_from_list(
                $this->download_list_file,
                "fetch",
            );
            if (!$complete) {
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->import_state()->fetch = new DownloadListFetchProgressState();

            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | fetch complete",
                );
            }

            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            if ($has_skipped && $this->filter === "essential-files") {
                // Essential files are done — mark the sync as complete.
                // The skipped list stays on disk for a later
                // --filter=skipped-earlier run.
                $this->import_state()->active_resumable_command->current_stage = null;
                $this->save_state($this->state);
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | skipped files listed in {$this->skipped_download_list_file} — run with --filter=skipped-earlier to download them",
                    true,
                );
                $stage = null;
            } elseif ($has_skipped) {
                // Skipped list exists but filter is "none" — download now.
                $this->import_state()->active_resumable_command->current_stage = "fetch-skipped";
                $this->save_state($this->state);
                $stage = "fetch-skipped";
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | transitioning to skipped files",
                    true,
                );
                $this->write_status_file();
            } else {
                $this->import_state()->active_resumable_command->current_stage = null;
                $this->save_state($this->state);
                $stage = null;
            }
        }

        if ($stage === "fetch-skipped") {
            $complete = $this->download_files_from_list(
                $this->skipped_download_list_file,
                "fetch_skipped",
            );
            if (!$complete) {
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->import_state()->active_resumable_command->current_stage = null;
            $this->import_state()->fetch_skipped = new DownloadListFetchProgressState();
            // Tail fully fetched; clear the flag so a later skipped-earlier run
            // doesn't treat the now-empty tail as pending.
            $this->import_state()->pull_pipeline->skipped_pending = false;
            $this->save_state($this->state);

            if (file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | skipped files fetch complete",
                );
            }
        }

        // Recreate intermediate path symlinks so the full symlink chain
        // works locally.  The server discovers these (e.g. /srv/wordpress
        // -> /wordpress) and includes them in the remote index.
        if ($this->follow_symlinks) {
            $this->recreate_intermediate_symlinks();
        }
    }

    /**
     * Command: files-index
     *
     * Rules:
     * - Streams the full remote index (DFS across directories) until complete
     * - If already completed: require --abort flag
     * - If abort flag: clear remote index file and index cursor
     */
    private function run_files_index(): void
    {
        $state_command = $this->import_state()->active_resumable_command->command_name ?? null;
        $current_status =
            $state_command === "files-index"
                ? $this->import_state()->active_resumable_command->completion_state ?? null
                : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "files-index already completed. Use --abort flag to start over.",
            );
        }

        if ($current_status === null) {
            $this->import_state()->active_resumable_command->command_name = "files-index";
            $this->import_state()->active_resumable_command->completion_state = "in_progress";
            $this->import_state()->active_resumable_command->current_stage = "index";
            $this->save_state($this->state);
            $this->audit_log("START files-index", true);
            $this->progress->show_lifecycle_line("Starting files-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "files-index",
                "message" => "Starting files-index",
            ], true);
        } else {
            $cursor = $this->import_state()->index->cursor ?? null;
            $this->audit_log(
                sprintf(
                    "RESUME files-index | cursor=%s",
                    $cursor ? substr($cursor, 0, 20) . "..." : "none",
                ),
                true,
            );
            $this->progress->show_lifecycle_line("Resuming files-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "files-index",
                "message" => "Resuming files-index",
            ], true);
        }

        $this->import_state()->active_resumable_command->command_name = "files-index";
        $this->save_state($this->state);

        $attempts = 0;
        $last_cursor = $this->import_state()->index->cursor ?? null;
        while (true) {
            $complete = $this->download_remote_index();
            if ($complete) {
                break;
            }

            if ($this->shutdown_requested) {
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                return;
            }

            $current_cursor = $this->import_state()->index->cursor ?? null;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException(
                    "files-index made no progress (cursor unchanged)",
                );
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 100000) {
                throw new RuntimeException(
                    "files-index exceeded maximum attempts",
                );
            }
        }

        // Follow symlinks: discover symlink targets outside known roots and
        // index them as additional directories.  Repeats until no new targets
        // are found, with cycle detection via realpath.
        if ($this->follow_symlinks) {
            $this->discover_symlink_targets();
        }

        $this->sort_index_file($this->remote_index_file);
        $this->import_state()->active_resumable_command->completion_state = "complete";
        $this->import_state()->active_resumable_command->current_stage = null;
        $this->save_state($this->state);

        $count = 0;
        if (file_exists($this->remote_index_file)) {
            $h = fopen($this->remote_index_file, "r");
            if ($h) {
                while (fgets($h) !== false) {
                    $count++;
                }
                fclose($h);
            }
        }
        $this->audit_log(
            sprintf("files-index complete: %d entries indexed", $count),
            true,
        );

        $this->progress->show_lifecycle_line("files-index complete: {$count} entries indexed\n");
        $this->progress->show_lifecycle_line("Remote index: {$this->remote_index_file}\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-index",
            "entries_indexed" => $count,
            "remote_index" => $this->remote_index_file,
            "audit_log" => $this->audit_log,
            "message" => "files-index complete: {$count} entries indexed",
        ], true);
    }

    /**
     * Recursively discover directories that need indexing beyond the primary
     * export roots.
     *
     * Scans the remote index for symlink entries with a "target" field,
     * resolves relative targets to absolute paths, and indexes each target
     * directory. Repeats until the queue is drained, with cycle detection.
     */
    private function discover_symlink_targets(): void
    {
        // Seed "already covered" from the dirs actually enumerated this run (the
        // --only prefixes when scoped), not the full preflight roots — otherwise a
        // narrow --only skips a target under a root but outside its scope.
        $roots = $this->get_export_directories();

        // Collect all indexed directory real paths for containment checks
        $visited = [];
        foreach ($roots as $root) {
            $visited[$root] = true;
        }

        $queue = $this->extract_symlink_dirs_from_index($visited);

        while (!empty($queue)) {
            $dir = array_shift($queue);
            if (isset($visited[$dir])) {
                continue;
            }
            // Skip if this directory is a subdirectory of an already-visited path,
            // since those files were already included in the parent's index.
            $already_covered = false;
            foreach ($visited as $v => $_) {
                if (str_starts_with($dir, $v . "/")) {
                    $already_covered = true;
                    break;
                }
            }
            if ($already_covered) {
                $this->audit_log(
                    "FOLLOW SYMLINK SKIP | {$dir} already covered by a visited parent",
                    true,
                );
                continue;
            }
            $visited[$dir] = true;

            $this->audit_log(
                "FOLLOW SYMLINK | indexing target directory: {$dir}",
                true,
            );
            $this->progress->show_lifecycle_line("Following symlink target: {$dir}\n");
            $this->output_progress([
                "type" => "symlink_follow",
                "directory" => $dir,
                "message" => "Following symlink target: {$dir}",
            ], true);

            // Reset the index cursor so download_remote_index starts fresh
            // for this directory, but appends to the existing index file.
            // Note we are not losing the previous cursor position. This code
            // runs only after the previous directory was fully indexed so
            // we won't need any prior cursor information again.
            $this->import_state()->index->cursor = null;
            $this->save_state($this->state);

            $attempts = 0;
            $last_cursor = null;
            while (true) {
                try {
                    $complete = $this->download_remote_index($dir);
                } catch (RuntimeException $e) {
                    // We won't be able to follow every symlink. If
                    // the response seems like the remote server rejecting
                    // our attempt to index this directory, log a warning
                    // and skip to the next directory instead of crashing.
                    $msg = $e->getMessage();
                    if (
                        strpos($msg, "HTTP error 4") !== false ||
                        strpos($msg, "dir_outside_root") !== false ||
                        strpos($msg, "outside of allowed roots") !== false
                    ) {
                        $this->audit_log(
                            "FOLLOW SYMLINK SKIP | server rejected {$dir}: " .
                                substr($msg, 0, 200),
                            true,
                        );
                        $this->progress->show_lifecycle_line("  Skipped (server rejected): {$dir}\n");
                        $this->output_progress([
                            "type" => "symlink_follow_rejected",
                            "directory" => $dir,
                            "message" => "Skipped (server rejected): {$dir}",
                        ], true);
                        continue 2;
                    }

                    // Still throw all the other errors.
                    throw $e;
                }
                if ($complete) {
                    break;
                }

                if ($this->shutdown_requested) {
                    return;
                }

                $current_cursor = $this->import_state()->index->cursor ?? null;
                if ($current_cursor === $last_cursor) {
                    throw new RuntimeException(
                        "files-index (symlink follow) made no progress (cursor unchanged)",
                    );
                }
                $last_cursor = $current_cursor;

                $attempts++;
                if ($attempts > 10_000) {
                    // @TODO: Consider a configurable maximum attempts for really large sites that
                    //        require more than 10,000 requests to index.
                    throw new RuntimeException(
                        "files-index (symlink follow) exceeded maximum attempts",
                    );
                }
            }

            // Scan newly added entries for more symlink targets
            $new_targets = $this->extract_symlink_dirs_from_index($visited);
            foreach ($new_targets as $target) {
                if (!isset($visited[$target])) {
                    $queue[] = $target;
                }
            }
        }
    }

    /**
     * Scan the remote index file for symlink entries whose targets are
     * directories not already in $visited.  Returns an array of real paths.
     *
     * Skips entries marked as "intermediate" — those are path-component
     * symlinks (e.g. /srv/wordpress -> /wordpress) emitted by the server's
     * discover_path_symlinks() for local recreation only, not for indexing.
     */
    private function extract_symlink_dirs_from_index(array $visited): array
    {
        $targets = [];
        if (!file_exists($this->remote_index_file)) {
            return $targets;
        }

        $handle = fopen($this->remote_index_file, "r");
        if (!$handle) {
            return $targets;
        }

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (!empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $target = base64_decode($target_encoded);
            if ($target === false || $target === "") {
                continue;
            }

            // If we've seen this target already, we can move on
            // to the next one.
            if (isset($visited[$target])) {
                continue;
            }

            // Check containment: skip if already under a visited root
            $contained = false;
            foreach ($visited as $root => $_) {
                if (str_starts_with($target, $root . "/")) {
                    $contained = true;
                    break;
                }
            }
            if ($contained) {
                continue;
            }

            $targets[] = $target;
        }
        fclose($handle);

        return array_values(array_unique($targets));
    }

    /**
     * Recreate intermediate symlinks discovered by the server's
     * discover_path_symlinks() function.
     *
     * When following symlinks, the server walks each target path component by
     * component and emits index entries for any intermediate symlinks it finds.
     * For example, if /srv/wordpress is a symlink to /wordpress, the server
     * emits an index entry with path=/srv/wordpress, target=/wordpress,
     * type=link, intermediate=true.
     *
     * Since the server indexes everything under realpath()-resolved paths,
     * the files are already downloaded to the target location (e.g.
     * fs-root/wordpress/...).  We just need to create the symlink
     * (e.g. fs-root/srv/wordpress -> /wordpress) so the directory
     * layout matches the server.
     */
    private function recreate_intermediate_symlinks(): void
    {
        if (!file_exists($this->remote_index_file)) {
            return;
        }

        $h = fopen($this->remote_index_file, "r");
        if (!$h) {
            return;
        }

        $created = 0;
        while (($line = fgets($h)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $path_encoded = $entry["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                continue;
            }

            /**
             * base64_decode second parameter is a `strict` flag. It rejects the entire
             * input if it contains any bytes that are not produced by base64_encode().
             *
             * @see https://www.php.net/base64_decode
             */
            $path = base64_decode($path_encoded, true);
            $target = base64_decode($target_encoded, true);
            if ($path === false || $path === "" || $target === false || $target === "") {
                continue;
            }

            try {
                $local_path = $this->remote_path_to_local_path_within_import_root($path);
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: invalid path {$path}: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            // Repoint through the same seam regular symlink chunks use, so the
            // link targets wherever the content actually landed (fs-root,
            // remapped, or bundled) instead of the raw source spelling.
            $target = $this->map_symlink_target_for_local_mirror($path, $local_path, $target);

            // Already correct — skip
            if (is_link($local_path) && readlink($local_path) === $target) {
                continue;
            }

            // Create parent directory
            $parent = dirname($local_path);
            if (!is_dir($parent)) {
                try {
                    $this->ensure_directory_path($parent);
                } catch (RuntimeException $e) {
                    $this->audit_log(
                        "INTERMEDIATE SYMLINK SKIP: failed to prepare parent for {$path}: " .
                            $e->getMessage(),
                        true,
                    );
                    continue;
                }
            }

            // Remove stale symlink if present
            if (is_link($local_path)) {
                @unlink($local_path);
            }

            // Don't overwrite a real directory — that shouldn't exist for
            // an intermediate symlink path, and if it does something else
            // is wrong.
            if (file_exists($local_path)) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: {$path} already exists as a real file/dir",
                    true,
                );
                continue;
            }

            // Validate that the symlink target doesn't escape the filesystem root.
            $root = $this->get_filesystem_root_path();
            try {
                $this->assert_symlink_target_within_root(
                    dirname($local_path),
                    $target,
                    $root
                );
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            if (@symlink($target, $local_path)) {
                $created++;
                $this->audit_log(
                    "INTERMEDIATE SYMLINK: {$path} -> {$target}",
                    false,
                );
            } else {
                $this->audit_log(
                    "Failed to create intermediate symlink: {$path} -> {$target}",
                    true,
                );
            }
        }
        fclose($h);

        if ($created > 0) {
            $this->audit_log(
                "Recreated {$created} intermediate symlink(s)",
                false,
            );
        }
    }

    /**
     * Command: db-pull
     *
     * Rules:
     * - Stream next portion of SQL from last saved cursor
     * - If already completed and db.sql exists: require --abort flag
     * - If db.sql missing but state says complete: warn and require --abort flag
     * - Otherwise: error
     */
    public function run_db_sync(): void
    {
        $state_command = $this->import_state()->active_resumable_command->command_name ?? null;
        $sql_file = $this->state_dir . "/db.sql";

        $has_progress =
            $state_command === "db-pull" &&
            ($this->import_state()->active_resumable_command->completion_state ?? null) === "in_progress";
        $current_status =
            $state_command === "db-pull"
                ? $this->import_state()->active_resumable_command->completion_state ?? null
                : null;

        // Check if already completed
        if ($current_status === "complete") {
            if ($this->sql_output_mode === "file") {
                $sql_exists = file_exists($sql_file);
                if ($sql_exists) {
                    throw new RuntimeException(
                        "db-pull already completed and db.sql exists. Use --abort flag to start over.",
                    );
                } else {
                    throw new RuntimeException(
                        "db-pull marked complete but db.sql is missing. Use --abort flag to re-sync.",
                    );
                }
            } else {
                throw new RuntimeException(
                    "db-pull already completed. Use --abort flag to start over.",
                );
            }
        }

        if ($has_progress) {
            $stage = $this->import_state()->active_resumable_command->current_stage ?? "db-index";
            $this->audit_log(
                sprintf(
                    "RESUME db-pull | stage=%s | cursor=%s",
                    $stage,
                    !empty($this->import_state()->active_resumable_command->remote_cursor)
                        ? substr($this->import_state()->active_resumable_command->remote_cursor, 0, 20) . "..."
                        : "none",
                ),
                true,
            );

            $this->progress->show_lifecycle_line("Resuming db-pull (stage: {$stage})\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-pull",
                "stage" => $stage,
                "message" => "Resuming db-pull (stage: {$stage})",
            ], true);
        } else {
            // Starting fresh
            $this->import_state()->active_resumable_command->command_name = "db-pull";
            $this->import_state()->active_resumable_command->completion_state = "in_progress";
            $this->import_state()->active_resumable_command->remote_cursor = null;
            $this->import_state()->active_resumable_command->current_stage = "db-index";
            $this->import_state()->diff = new FileDiffProgressState();
            $this->import_state()->db_index = new DatabaseTableIndexState();
            $this->save_state($this->state);

            $this->audit_log("START db-pull", true);

            $this->progress->show_lifecycle_line("Starting db-pull\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-pull",
                "message" => "Starting db-pull",
            ], true);
        }

        $this->import_state()->active_resumable_command->command_name = "db-pull";
        $this->save_state($this->state);

        // Stage 1: db-index (table metadata for progress estimation)
        $stage = $this->import_state()->active_resumable_command->current_stage ?? "db-index";
        if ($stage === "db-index") {
            $this->output_progress([
                "status" => "starting",
                "phase" => "db-index",
                "message" => "Downloading table metadata",
            ]);

            $this->download_db_index();

            // Timeout during db-index — state already saved, exit partial.
            if (($this->import_state()->active_resumable_command->completion_state ?? null) === "partial") {
                return;
            }

            $tables = (int) ($this->import_state()->db_index->tables ?? 0);
            $this->audit_log(
                sprintf("db-pull db-index stage complete: %d tables", $tables),
            );

            // Transition to sql stage
            $this->import_state()->active_resumable_command->current_stage = "sql";
            $this->import_state()->active_resumable_command->remote_cursor = null;
            $this->save_state($this->state);
        }

        // Stage 2: SQL dump download
        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
            "message" => "Downloading SQL dump",
        ]);

        $this->download_sql();

        // Timeout during SQL download — state already saved, exit partial.
        if (($this->import_state()->active_resumable_command->completion_state ?? null) === "partial") {
            return;
        }

        // Mark as complete
        $this->import_state()->active_resumable_command->completion_state = "complete";
        $this->save_state($this->state);

        $this->audit_log("db-pull complete", true);

        $this->progress->show_lifecycle_line("db-pull complete\n");
        if ($this->sql_output_mode === "file") {
            $this->progress->show_lifecycle_line("SQL file: {$sql_file}\n");
        } elseif ($this->sql_output_mode === "stdout") {
            $this->progress->show_lifecycle_line("SQL written to stdout\n");
        } elseif ($this->sql_output_mode === "mysql") {
            $this->progress->show_lifecycle_line("SQL imported into {$this->mysql_database}\n");
        }
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $db_sync_complete = [
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-pull",
            "sql_output_mode" => $this->sql_output_mode,
            "audit_log" => $this->audit_log,
            "message" => "db-pull complete",
        ];
        if ($this->sql_output_mode === "file") {
            $db_sync_complete["sql_file"] = $sql_file;
        }
        $this->output_progress($db_sync_complete, true);
    }

    // =========================================================================
    // db-apply: Apply SQL dump to a target MySQL database with URL rewriting
    // =========================================================================

    /**
     * Command: db-apply
     *
     * Reads db.sql, optionally rewrites URLs, and executes statements against
     * a target MySQL database. Supports resumption via statement count tracking.
     *
     */
    private function run_db_domains(): void
    {
        $domains_file = $this->state_dir . "/.import-domains.json";
        $sql_file = $this->state_dir . "/db.sql";

        if (file_exists($domains_file)) {
            // Fast path: domains were already discovered during db-pull
            $domains = json_decode(file_get_contents($domains_file), true);
            if (!is_array($domains)) {
                throw new RuntimeException(
                    "Failed to parse {$domains_file}",
                );
            }
        } elseif (file_exists($sql_file)) {
            // Scan db.sql for domains using the same pipeline as db-pull
            $query_stream = new \WP_MySQL_Naive_Query_Stream();
            $domain_collector = new \DomainCollector();

            $sql_handle = fopen($sql_file, "r");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }

            try {
                $chunk_size = 64 * 1024;
                while (!feof($sql_handle)) {
                    $data = fread($sql_handle, $chunk_size);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $query_stream->append_sql($data);
                    $this->drain_query_stream_for_domains(
                        $query_stream,
                        $domain_collector,
                    );
                }

                $query_stream->mark_input_complete();
                $this->drain_query_stream_for_domains(
                    $query_stream,
                    $domain_collector,
                );
            } finally {
                fclose($sql_handle);
            }

            $domains = $domain_collector->get_domains();

            // Save for future calls
            file_put_contents(
                $domains_file,
                json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        } else {
            throw new RuntimeException(
                "No domain data found. Run db-pull first, or place a db.sql file in {$this->state_dir}.",
            );
        }

        // Print one domain per line to stdout
        foreach ($domains as $domain) {
            echo $domain . "\n";
        }
    }

    /**
     * Print file index statistics: total indexed files and their size,
     * plus pending downloads and their size.
     *
     * Reads .import-remote-index.jsonl for all indexed files and
     * .import-download-list.jsonl for files not yet downloaded.
     */
    private function run_files_stats(): void
    {
        $remote_index = $this->remote_index_file;
        $download_list = $this->download_list_file;

        // Single pass over the remote index to build a path→size map.
        // Duplicates (from overlapping symlink targets) are collapsed
        // automatically because later entries overwrite earlier ones in
        // the map, so the counts we derive are always deduplicated.
        $size_by_path = [];

        if (is_file($remote_index)) {
            $handle = fopen($remote_index, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $entry = $this->parse_index_line($line);
                    if ($entry === null) {
                        continue;
                    }
                    $size_by_path[$entry["path"]] = $entry["size"];
                }
                fclose($handle);
            }
        }

        $indexed_count = count($size_by_path);
        $indexed_bytes = array_sum($size_by_path);

        // Walk the download list(s) to count pending files. The download
        // list only stores paths, so look up sizes from the map above.
        // Files before the fetch byte offset have already been downloaded.
        $pending_count = 0;
        $pending_bytes = 0;
        $skipped_pending_count = 0;
        $skipped_pending_bytes = 0;

        // Count pending in the main download list
        $fetch_offset = $this->import_state()->fetch->offset ?? 0;
        if (is_file($download_list)) {
            $handle = fopen($download_list, "r");
            if ($handle) {
                // Seek past already-downloaded entries. The fetch offset
                // is the byte position where the next batch starts, so
                // everything before it has been fetched.
                if ($fetch_offset > 0) {
                    fseek($handle, $fetch_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === "") {
                        continue;
                    }
                    $data = json_decode($line, true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $path_encoded = $data["path"] ?? "";
                    $path = base64_decode($path_encoded, true);
                    if ($path === false || $path === "") {
                        continue;
                    }
                    $pending_count++;
                    $pending_bytes += $size_by_path[$path] ?? 0;
                }
                fclose($handle);
            }
        }

        // Count pending in the skipped download list (uploads filtered out by --filter=essential-files)
        $skipped_offset = $this->import_state()->fetch_skipped->offset ?? 0;
        $skipped_list = $this->skipped_download_list_file;
        if (is_file($skipped_list)) {
            $handle = fopen($skipped_list, "r");
            if ($handle) {
                if ($skipped_offset > 0) {
                    fseek($handle, $skipped_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === "") {
                        continue;
                    }
                    $data = json_decode($line, true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $path_encoded = $data["path"] ?? "";
                    $path = base64_decode($path_encoded, true);
                    if ($path === false || $path === "") {
                        continue;
                    }
                    $skipped_pending_count++;
                    $skipped_pending_bytes += $size_by_path[$path] ?? 0;
                }
                fclose($handle);
            }
        }

        $result = [
            "indexed" => [
                "files" => $indexed_count,
                "bytes" => $indexed_bytes,
            ],
            "pending" => [
                "files" => $pending_count,
                "bytes" => $pending_bytes,
            ],
        ];
        if ($skipped_pending_count > 0 || is_file($skipped_list)) {
            $result["pending_skipped"] = [
                "files" => $skipped_pending_count,
                "bytes" => $skipped_pending_bytes,
            ];
        }

        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Prints host-facing import lifecycle metadata without mutating state.
     */
    private function run_import_metadata(): void
    {
        echo json_encode(
            $this->build_import_metadata(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /**
     * Builds the small metadata contract exposed to host integrations.
     *
     * `hasCompletedOnce` is derived from Reprint-owned pull state so
     * callers do not need to persist a parallel flag that could drift.
     *
     * @return array {
     *     Import metadata for host integrations.
     *
     *     @type bool  $hasCompletedOnce Whether the pull pipeline has completed
     *                                   at least once.
     *     @type mixed $pullStage        Last completed pull stage.
     * }
     * @phpstan-return array{hasCompletedOnce: bool, pullStage: mixed}
     */
    private function build_import_metadata(): array
    {
        $pull = $this->import_state()->pull_pipeline;

        return [
            "hasCompletedOnce" => $pull->has_completed_once,
            "pullStage" => $pull->last_completed_stage,
        ];
    }

    /**
     * Format a byte count into a human-readable string.
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

    /**
     * Generate runtime configuration for the imported site.
     *
     * Reads the detected webhost from state (set during preflight), runs the
     * appropriate host analyzer to produce a runtime manifest, then applies
     * it using the chosen runtime applier. The manifest captures what the
     * source site needs (constants, INI directives, error handlers);
     * the applier writes the files the target server needs to fulfill those
     * requirements.
     *
     * The effective fs root is --fs-root + the remote site's document_root
     * prefix (from preflight). For example, if the remote document_root is
     * /srv/htdocs and --fs-root is ./files, the effective fs root is
     * ./files/srv/htdocs. If the site was flattened with flat-docroot,
     * pass the flattened directory as --fs-root directly and the prefix
     * is not applied.
     */
    public function run_apply_runtime(array $options): void
    {
        $runtime = $options["runtime"] ?? null;
        if (empty($runtime)) {
            throw new InvalidArgumentException(
                "apply-runtime requires --runtime=RUNTIME."
            );
        }

        $output_dir = $options["output_dir"] ?? null;
        if (empty($output_dir)) {
            throw new InvalidArgumentException(
                "apply-runtime requires --output-dir=DIR to write runtime configuration files"
            );
        }

        // Load state to get preflight data and detected webhost.
        $entry = $this->import_state()->preflight ?? null;
        if (!is_array($entry) || empty($entry["data"])) {
            throw new RuntimeException(
                "apply-runtime requires a prior preflight run. " .
                "Run 'preflight' first to capture the source site's environment."
            );
        }

        $preflight_data = $entry["data"];
        $webhost = $this->import_state()->webhost ?? "other";

        // Resolve the effective fs root from either --flat-document-root
        // (used as-is) or --fs-root (prefixed with the remote document_root).
        // Mutual exclusion is already enforced at the CLI level.
        $flat_document_root = $options["flat_document_root"] ?? null;

        if (!empty($flat_document_root)) {
            // --flat-document-root: used directly as the web root.
            $effective_fs_root = rtrim($flat_document_root, "/");
        } else {
            // --fs-root: the raw download directory. The remote site's
            // document_root tells us where the web root lived on the
            // source server. Files are downloaded preserving the full
            // remote path, so the effective fs root is --fs-root +
            // document_root.
            $remote_doc_root = $preflight_data["runtime"]["document_root"] ?? "";
            if (is_string($remote_doc_root)) {
                $remote_doc_root = rtrim($remote_doc_root, "/");
            } else {
                $remote_doc_root = "";
            }

            if ($remote_doc_root !== "") {
                $effective_fs_root = $this->fs_root . $remote_doc_root;
            } else {
                $effective_fs_root = $this->fs_root;
            }

            if (!is_dir($effective_fs_root)) {
                throw new RuntimeException(
                    "Effective fs root does not exist: {$effective_fs_root}\n" .
                    "The remote document_root was: {$remote_doc_root}\n" .
                    "If you used flat-docroot, pass the flattened directory " .
                    "with --flat-document-root instead of --fs-root."
                );
            }
        }

        // Resolve to absolute paths so generated files work from any cwd.
        $abs_output_dir = realpath($output_dir) ?: $output_dir;
        $abs_fs_root = realpath($effective_fs_root) ?: $effective_fs_root;

        if (!is_dir($abs_output_dir)) {
            if (!mkdir($abs_output_dir, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create output directory: {$abs_output_dir}"
                );
            }
            $abs_output_dir = realpath($abs_output_dir);
        }

        // Step 1: Host analyzer produces a manifest from preflight data.
        $analyzer = host_analyzer_for($webhost);
        $manifest = $analyzer->analyze($preflight_data);
        $this->maybe_enable_remote_upload_proxy($manifest, $preflight_data);

        // Step 1b: Merge target database configuration from db-apply state.
        // db-apply persists the target engine and connection details so that
        // apply-runtime can generate the matching DB_* constants and, for
        // SQLite targets, set up the database integration plugin.
        $apply_state = $this->import_state()->apply;
        $target_engine = $apply_state->target_engine;
        if ($target_engine === "mysql") {
            $manifest->constants["DB_NAME"] = $apply_state->target_db ?? "";
            $manifest->constants["DB_USER"] = $apply_state->target_user ?? "";
            $manifest->constants["DB_PASSWORD"] = $apply_state->target_pass ?? "";
            $host_value = $apply_state->target_host ?? "127.0.0.1";
            $port_value = (int) ($apply_state->target_port ?? 3306);
            if ($port_value !== 3306) {
                $host_value .= ":" . $port_value;
            }
            $manifest->constants["DB_HOST"] = $host_value;
            // runtime.php defines DB_* before wp-config.php loads, which
            // causes "Constant already defined" warnings. Flag this so the
            // generated runtime.php installs a handler to suppress them.
            $manifest->has_db_constants = true;
        } elseif ($target_engine === "sqlite") {
            $sqlite_path = $apply_state->target_sqlite_path;
            $manifest->constants["DB_NAME"] = $apply_state->target_db ?? "sqlite_database";
            // The SQLite integration still requires a non-empty DB_NAME
            // for its MySQL information-schema emulation, even though the
            // physical database location comes from DB_DIR/DB_FILE.
            $manifest->has_db_constants = true;
            if ($sqlite_path !== null && $sqlite_path !== '') {
                $db_dir = rtrim(dirname($sqlite_path), '/') . '/';
                $db_file = basename($sqlite_path);
            } else {
                $db_dir = '{fs-root}/wp-content/database/';
                $db_file = '.ht.sqlite';
            }
            $manifest->sqlite = [
                'plugin_source' => resolve_sqlite_integration_plugin_path(),
                'plugin_dir' => '',  // resolved after copy_sqlite_plugin()
                'db_dir' => $db_dir,
                'db_file' => $db_file,
            ];
        }

        $this->audit_log("APPLY-RUNTIME | analyzed preflight (source={$manifest->source}, webhost={$webhost})");

        // Resolve host and port for the target server. If not provided on
        // the CLI, derive from the first URL rewrite target (saved by
        // db-apply). This way the dev server listens on the same address
        // the database was rewritten to.
        $host = $options["host"] ?? null;
        $port = $options["port"] ?? null;
        if ($host === null || $port === null) {
            $rewrite_map = $this->import_state()->apply->rewrite_url ?? [];
            $first_target = !empty($rewrite_map) ? reset($rewrite_map) : null;
            if (is_string($first_target)) {
                $parsed = parse_url($first_target);
                if ($host === null) {
                    $host = $parsed["host"] ?? null;
                }
                if ($port === null && isset($parsed["port"])) {
                    $port = $parsed["port"];
                }
            }
        }

        // Resolve the path to WordPress's index.php. On standard hosts it
        // lives in the fs root. On WPCloud the ABSPATH is a different
        // directory (e.g. /wordpress/core/X.Y.Z) which maps to
        // download_root + abspath when using --fs-root.
        $paths_urls = $preflight_data["database"]["wp"]["paths_urls"] ?? [];
        $abspath = rtrim($paths_urls["abspath"] ?? "", "/");
        if (!empty($flat_document_root)) {
            // Flattened layout: index.php is at the top level.
            $wordpress_index = $abs_fs_root . '/index.php';
        } elseif ($abspath !== "") {
            // Raw download: ABSPATH is relative to the download root,
            // not the effective fs root (which is download_root + document_root).
            $wordpress_index = realpath($this->fs_root . $abspath . '/index.php') ?: '';
        } else {
            $wordpress_index = $abs_fs_root . '/index.php';
        }

        // Step 2: Runtime applier writes server-specific config files.
        $applier = runtime_applier_for($runtime);
        $applier_options = [];
        if ($wordpress_index !== '') {
            $applier_options['wordpress_index'] = $wordpress_index;
        }
        if ($host !== null) {
            $applier_options['host'] = $host;
        }
        if ($port !== null) {
            $applier_options['port'] = (int) $port;
        }
        // Step 2b: For SQLite targets, copy the integration plugin into the
        // output directory BEFORE the applier runs, so generate_runtime_php()
        // can embed the resolved plugin path in the lazy-loader code.
        if ($manifest->sqlite !== null) {
            $copied_plugin = copy_sqlite_plugin(
                $manifest->sqlite['plugin_source'],
                $abs_output_dir,
            );
            // Replace the source path with the copied-to path so the
            // generated runtime.php points to the output directory.
            $manifest->sqlite['plugin_dir'] = $copied_plugin;
            // Resolve {fs-root} in db_dir now that we have the real path.
            $manifest->sqlite['db_dir'] = resolve_runtime_placeholders(
                $manifest->sqlite['db_dir'],
                $abs_fs_root,
            );
        }

        $summary = $applier->apply($manifest, $abs_fs_root, $abs_output_dir, $applier_options);

        if ($manifest->sqlite !== null) {
            $summary[] = "Copied sqlite-database-integration to {$abs_output_dir}/sqlite-database-integration";
        }

        // Remove production drop-ins and mu-plugins that would crash
        // the local site.  The host analyzer declares these — they
        // depend on infrastructure (Memcached servers, multisite APIs)
        // not available outside the original hosting environment.
        foreach ($manifest->paths_to_remove as $rel_path) {
            $full_path = $abs_fs_root . '/' . ltrim($rel_path, '/');
            if (!file_exists($full_path) && !is_link($full_path)) {
                continue;
            }
            if (is_dir($full_path) && !is_link($full_path)) {
                self::rmdir_recursive($full_path);
            } else {
                unlink($full_path);
            }
            $summary[] = "Removed production drop-in: {$rel_path}";
            $this->audit_log("APPLY-RUNTIME | removed {$rel_path} (production-only)");
        }

        foreach ($summary as $line) {
            $this->audit_log("APPLY-RUNTIME | {$line}");
        }

        // Persist which paths were removed so callers can inspect state.
        $this->import_state()->apply->remote_paths_removed_from_local_site = $manifest->paths_to_remove;
        $this->save_state($this->state);

        // Read the structured start config if the applier wrote one.
        // Playground CLI writes start.json with mount paths as seen by
        // this PHP process — callers (e.g. Studio) map them to host paths.
        $start_config_path = $abs_output_dir . '/start.json';
        $start_config = null;
        if (file_exists($start_config_path)) {
            $start_config = json_decode(file_get_contents($start_config_path), true);
        }

        // Output the summary and manifest as structured JSON for callers,
        // and print the human-readable summary to stderr.
        $this->output_progress([
            "status" => "complete",
            "command" => "apply-runtime",
            "runtime" => $runtime,
            "webhost" => $webhost,
            "webhost_source" => $manifest->source,
            "target_engine" => $target_engine,
            "paths_removed" => $manifest->paths_to_remove,
            "extra_directories" => $manifest->extra_directories,
            "start_config" => $start_config,
            "message" => "apply-runtime complete (runtime: {$runtime})",
        ]);

        if (!$this->progress->is_mode('pipeline')) {
            fwrite(STDERR, "\n");
            fwrite(STDERR, "Runtime: {$runtime}\n");
            fwrite(STDERR, "Source host: {$webhost}\n");
            if ($target_engine !== null) {
                fwrite(STDERR, "Target database: {$target_engine}\n");
            }
            fwrite(STDERR, "\n");
            foreach ($summary as $line) {
                fwrite(STDERR, "{$line}\n");
            }
        }
    }

    /**
     * Enable the temporary remote upload proxy when uploads may still be
     * missing locally.
     *
     * The proxy is active in two cases:
     * - files-pull is still incomplete
     * - a prior --filter=essential-files run left skipped uploads on disk
     */
    private function maybe_enable_remote_upload_proxy(RuntimeManifest $manifest, array $preflight_data): void
    {
        if (!$this->should_enable_remote_upload_proxy()) {
            return;
        }

        $base_url = $this->get_remote_upload_proxy_base_url($preflight_data);
        if ($base_url === null) {
            $this->audit_log(
                "APPLY-RUNTIME | remote upload proxy skipped (no source uploads URL available)",
                true,
            );
            return;
        }

        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL"] = $base_url;
        $state_dir = realpath($this->state_dir) ?: $this->state_dir;
        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE"] =
            rtrim($state_dir, "/") . "/.import-state.json";
        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_SKIPPED_FILE"] =
            rtrim($state_dir, "/") . "/.import-download-list-skipped.jsonl";
        $manifest->routes[] = [
            "handler" => "remote-upload-proxy",
            "path_pattern" => "/wp-content/uploads/.*",
            "condition" => "file_not_found",
            "description" => "Proxy missing uploads from the source site until files-pull completes",
        ];
        $this->audit_log(
            "APPLY-RUNTIME | enabled remote upload proxy ({$base_url})",
            true,
        );
    }

    /**
     * Decide whether runtime should proxy missing uploads from the source.
     *
     * Once files-pull is fully complete and no skipped uploads remain, the
     * proxy is disabled so requests are served only from local files.
     */
    private function should_enable_remote_upload_proxy(): bool
    {
        if (
            file_exists($this->skipped_download_list_file) &&
            filesize($this->skipped_download_list_file) > 0
        ) {
            return true;
        }

        if (($this->import_state()->active_resumable_command->command_name ?? null) !== "files-pull") {
            return false;
        }

        $status = $this->import_state()->active_resumable_command->completion_state ?? null;
        return $status !== null && $status !== "complete";
    }

    /**
     * Resolve the source uploads base URL used by the temporary runtime proxy.
     */
    private function get_remote_upload_proxy_base_url(array $preflight_data): ?string
    {
        $paths_urls = $preflight_data["database"]["wp"]["paths_urls"] ?? [];
        $uploads_baseurl = $paths_urls["uploads"]["baseurl"] ?? null;
        if (is_string($uploads_baseurl) && $uploads_baseurl !== "") {
            return rtrim($uploads_baseurl, "/");
        }

        $site_urls = [
            $paths_urls["home_url"] ?? null,
            $paths_urls["site_url"] ?? null,
            $preflight_data["database"]["wp"]["home"] ?? null,
            $preflight_data["database"]["wp"]["siteurl"] ?? null,
        ];
        foreach ($site_urls as $site_url) {
            if (is_string($site_url) && $site_url !== "") {
                return rtrim($site_url, "/") . "/wp-content/uploads";
            }
        }

        return null;
    }

    /**
     * Command: flat-docroot
     *
     * Creates a directory at the specified --flatten-to path that mirrors
     * a vanilla WordPress installation layout by symlinking entries from
     * the import fs root. Uses preflight data (paths_urls) to determine
     * where each WordPress component actually lives, rather than blindly
     * scanning fs root top-level entries.
     *
     * This is essential when the source site uses a non-standard layout
     * (e.g. WP Cloud with ABSPATH=/srv/htdocs and WP_CONTENT_DIR=/tmp/__wp__/wp-content)
     * and the target needs a conventional wp-admin/, wp-includes/,
     * wp-content/, wp-load.php structure.
     *
     * The command is idempotent: re-running refreshes all symlinks.
     * If a path that should be a symlink is a regular file/directory,
     * the command stops with an error unless --force is specified.
     */
    public function run_flat_document_root(array $options): void
    {
        $flatten_to = $options["flatten_to"] ?? null;
        if (empty($flatten_to)) {
            throw new InvalidArgumentException(
                "flat-docroot requires --flatten-to=PATH",
            );
        }

        $flatten_to = rtrim($flatten_to, "/");
        $force = $options["force"] ?? false;

        // Ensure the fs root exists
        if (!is_dir($this->fs_root)) {
            throw new RuntimeException(
                "Fs root does not exist: {$this->fs_root}",
            );
        }

        // Require preflight data so we know where WP components live
        $this->require_preflight();
        $preflight = $this->import_state()->preflight["data"] ?? [];

        // Extract WordPress directory paths from preflight
        $paths_urls = $preflight["database"]["wp"]["paths_urls"] ?? null;
        $abspath = null;
        $wp_admin_path = null;
        $wp_includes_path = null;
        $content_dir = null;
        $plugins_dir = null;
        $mu_plugins_dir = null;
        $uploads_basedir = null;

        if (is_array($paths_urls)) {
            $abspath = $this->clean_preflight_path( $paths_urls["abspath"] ?? null);
            $wp_admin_path = $this->clean_preflight_path( $paths_urls["wp_admin_path"] ?? null);
            $wp_includes_path = $this->clean_preflight_path( $paths_urls["wp_includes_path"] ?? null);
            $content_dir = $this->clean_preflight_path( $paths_urls["content_dir"] ?? null);
            $plugins_dir = $this->clean_preflight_path( $paths_urls["plugins_dir"] ?? null);
            $mu_plugins_dir = $this->clean_preflight_path( $paths_urls["mu_plugins_dir"] ?? null);
            $uploads_basedir = $this->clean_preflight_path(
                $paths_urls["uploads"]["basedir"] ?? null,
            );
        }

        // Fall back to wp_detect roots if abspath not available
        if ($abspath === null) {
            $roots = $preflight["wp_detect"]["roots"] ?? [];
            if (!empty($roots)) {
                $abspath = $this->clean_preflight_path( $roots[0]["path"] ?? null);
            }
        }

        if ($abspath === null) {
            throw new RuntimeException(
                "Cannot determine WordPress ABSPATH from preflight data. " .
                    "Run preflight first to detect the WordPress installation.",
            );
        }

        // Map remote paths to local paths within fs root
        $local_abspath = $this->fs_root . $abspath;
        if (!is_dir($local_abspath)) {
            throw new RuntimeException(
                "WordPress ABSPATH directory not found in fs root: {$local_abspath} " .
                    "(remote ABSPATH: {$abspath}). Has the file sync completed?",
            );
        }

        $local_wp_admin = $wp_admin_path !== null
            ? $this->fs_root . $wp_admin_path
            : null;
        $local_wp_includes = $wp_includes_path !== null
            ? $this->fs_root . $wp_includes_path
            : null;
        $local_content_dir = $content_dir !== null
            ? $this->fs_root . $content_dir
            : null;
        $local_plugins_dir = $plugins_dir !== null
            ? $this->fs_root . $plugins_dir
            : null;
        $local_mu_plugins_dir = $mu_plugins_dir !== null
            ? $this->fs_root . $mu_plugins_dir
            : null;
        $local_uploads_basedir = $uploads_basedir !== null
            ? $this->fs_root . $uploads_basedir
            : null;

        // Determine which components are "detached" — located outside
        // their conventional parent directory on the source server.
        // wp-admin and wp-includes are detached when their resolved path
        // differs from the ABSPATH/wp-admin or ABSPATH/wp-includes path
        // (e.g. WP Cloud where they live behind __wp__/).
        $wp_admin_detached = $wp_admin_path !== null
            && $wp_admin_path !== $abspath . "/wp-admin";
        $wp_includes_detached = $wp_includes_path !== null
            && $wp_includes_path !== $abspath . "/wp-includes";
        $content_detached = $content_dir !== null
            && strpos($content_dir, $abspath . "/") !== 0;
        $plugins_detached = $plugins_dir !== null
            && $content_dir !== null
            && strpos($plugins_dir, $content_dir . "/") !== 0;
        $mu_plugins_detached = $mu_plugins_dir !== null
            && $content_dir !== null
            && strpos($mu_plugins_dir, $content_dir . "/") !== 0;
        $uploads_detached = $uploads_basedir !== null
            && $content_dir !== null
            && strpos($uploads_basedir, $content_dir . "/") !== 0;

        // If any sub-component is detached from content_dir, we need to
        // "explode" wp-content into a real directory with individual symlinks
        // rather than symlinking the content_dir wholesale.
        $need_exploded_content =
            $plugins_detached || $mu_plugins_detached || $uploads_detached;

        // Create the target directory if it doesn't exist
        if (!is_dir($flatten_to)) {
            if (!mkdir($flatten_to, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create flatten-to directory: {$flatten_to}",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Created directory: {$flatten_to}",
            );
        }

        $this->audit_log(
            sprintf(
                "FLAT-DOCUMENT-ROOT | abspath=%s wp_admin=%s wp_includes=%s " .
                    "content_dir=%s content_detached=%s " .
                    "plugins_detached=%s mu_plugins_detached=%s uploads_detached=%s",
                $abspath,
                $wp_admin_path ?? "(from abspath)",
                $wp_includes_path ?? "(from abspath)",
                $content_dir ?? "(not set)",
                $content_detached ? "yes" : "no",
                $plugins_detached ? "yes" : "no",
                $mu_plugins_detached ? "yes" : "no",
                $uploads_detached ? "yes" : "no",
            ),
        );

        $created = 0;
        $refreshed = 0;
        $forced = 0;

        // Determine what to skip from ABSPATH enumeration.
        // Components with known detached locations are handled separately.
        $skip_from_abspath = [];
        if ($content_detached || $need_exploded_content) {
            $skip_from_abspath["wp-content"] = true;
        }
        if ($wp_admin_detached) {
            $skip_from_abspath["wp-admin"] = true;
        }
        if ($wp_includes_detached) {
            $skip_from_abspath["wp-includes"] = true;
        }

        // Phase 1: Symlink all entries from ABSPATH into flatten-to.
        // This covers core files (index.php, wp-load.php, wp-config.php, etc.)
        // and wp-admin/wp-includes when they're directly under ABSPATH.
        $entries = @scandir($local_abspath);
        if ($entries === false) {
            throw new RuntimeException(
                "Failed to scan ABSPATH directory: {$local_abspath}",
            );
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            if (isset($skip_from_abspath[$entry])) {
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Skipping '{$entry}' from ABSPATH " .
                        "(will be sourced from resolved location)",
                );
                continue;
            }

            $source = $local_abspath . "/" . $entry;
            $target = $flatten_to . "/" . $entry;
            $this->flatten_place_symlink(
                $source,
                $target,
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }

        // Phase 1b: Symlink detached wp-admin and wp-includes from their
        // resolved physical locations (e.g. /wordpress/wp-admin on WP Cloud).
        if ($wp_admin_detached && $local_wp_admin !== null && is_dir($local_wp_admin)) {
            $this->flatten_place_symlink(
                $local_wp_admin,
                $flatten_to . "/wp-admin",
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }
        if ($wp_includes_detached && $local_wp_includes !== null && is_dir($local_wp_includes)) {
            $this->flatten_place_symlink(
                $local_wp_includes,
                $flatten_to . "/wp-includes",
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }

        // Phase 1c: Symlink wp-config.php from ABSPATH's parent directory.
        // WordPress allows wp-config.php one directory above ABSPATH —
        // wp-load.php checks dirname(ABSPATH) as a fallback. On WP Cloud
        // the typical layout is /srv/htdocs/wp-config.php with ABSPATH at
        // /srv/htdocs/wordpress/, so Phase 1's ABSPATH scan won't find it.
        $wp_config_in_flatten = $flatten_to . "/wp-config.php";
        if (!file_exists($wp_config_in_flatten)) {
            $parent_of_abspath = dirname($abspath);
            $local_parent_wp_config = $this->fs_root . $parent_of_abspath . "/wp-config.php";
            if (file_exists($local_parent_wp_config)) {
                $this->flatten_place_symlink(
                    $local_parent_wp_config,
                    $wp_config_in_flatten,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Symlinked wp-config.php from ABSPATH parent: " .
                        "{$parent_of_abspath}/wp-config.php",
                );
            }
        }


        // Phase 2: Handle wp-content when it's outside ABSPATH
        if ($need_exploded_content && $local_content_dir !== null) {
            // wp-content must be a real directory because some sub-components
            // (plugins, mu-plugins, or uploads) live outside content_dir.
            $wp_content_target = $flatten_to . "/wp-content";
            $this->flatten_ensure_real_directory(
                $wp_content_target,
                $force,
                $forced,
            );

            // Symlink all entries from content_dir into the real wp-content dir
            if (is_dir($local_content_dir)) {
                $content_entries = @scandir($local_content_dir) ?: [];
                // Determine which sub-entries to skip (will be overridden)
                $skip_from_content = [];
                if ($plugins_detached) {
                    $skip_from_content["plugins"] = true;
                }
                if ($mu_plugins_detached) {
                    $skip_from_content["mu-plugins"] = true;
                }
                if ($uploads_detached) {
                    $skip_from_content["uploads"] = true;
                }

                foreach ($content_entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    if (isset($skip_from_content[$entry])) {
                        continue;
                    }
                    $source = $local_content_dir . "/" . $entry;
                    $target = $wp_content_target . "/" . $entry;
                    $this->flatten_place_symlink(
                        $source,
                        $target,
                        $force,
                        $created,
                        $refreshed,
                        $forced,
                    );
                }
            }

            // Symlink detached sub-components into wp-content
            if ($plugins_detached && is_dir($local_plugins_dir)) {
                $target = $wp_content_target . "/plugins";
                $this->flatten_place_symlink(
                    $local_plugins_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
            if ($mu_plugins_detached && is_dir($local_mu_plugins_dir)) {
                $target = $wp_content_target . "/mu-plugins";
                $this->flatten_place_symlink(
                    $local_mu_plugins_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
            if ($uploads_detached && is_dir($local_uploads_basedir)) {
                $target = $wp_content_target . "/uploads";
                $this->flatten_place_symlink(
                    $local_uploads_basedir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
        } elseif ($content_detached && $local_content_dir !== null) {
            // Content dir is outside ABSPATH but sub-components are inside it.
            // Simple case: just symlink the whole content_dir as wp-content.
            if (is_dir($local_content_dir)) {
                $target = $flatten_to . "/wp-content";
                $this->flatten_place_symlink(
                    $local_content_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            } else {
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Warning: content_dir not found in fs root: " .
                        "{$local_content_dir} (remote: {$content_dir})",
                    true,
                );
            }
        }

        $this->audit_log(
            sprintf(
                "FLAT-DOCUMENT-ROOT | Complete: %d created, %d refreshed, %d force-replaced",
                $created,
                $refreshed,
                $forced,
            ),
            true,
        );

        $result = [
            "status" => "complete",
            "flatten_to" => $flatten_to,
            "fs_root" => $this->fs_root,
            "abspath" => $abspath,
            "wp_admin_path" => $wp_admin_path,
            "wp_includes_path" => $wp_includes_path,
            "content_dir" => $content_dir,
            "content_detached" => $content_detached,
            "created" => $created,
            "refreshed" => $refreshed,
            "force_replaced" => $forced,
        ];
        if (!$this->progress->is_mode('pipeline')) {
            fwrite($this->progress_fd, json_encode($result) . "\n");
        }
        $this->output_progress(array_merge(["type" => "flat_docroot_complete"], $result));
    }

    /**
     * Clean a path value from preflight data: trim, strip trailing slash.
     * Returns null if the value is not a non-empty string.
     */
    private function clean_preflight_path($value): ?string
    {
        if (!is_string($value) || trim($value) === "") {
            return null;
        }
        return rtrim($value, "/");
    }

    /**
     * Compute a relative path from $from to $to.
     *
     * Both paths must be absolute. Returns a relative path such that
     * a symlink at $from/$name pointing to the result will resolve to $to.
     *
     * Example: relative_path('/a/b/c', '/a/d/e') => '../../d/e'
     */
    private static function compute_relative_path(
        string $from,
        string $to
    ): string {
        $from_parts = explode("/", trim($from, "/"));
        $to_parts = explode("/", trim($to, "/"));

        // Find common prefix length
        $common = 0;
        $max = min(count($from_parts), count($to_parts));
        while ($common < $max && $from_parts[$common] === $to_parts[$common]) {
            $common++;
        }

        // Go up from $from to the common ancestor, then down to $to
        $up = count($from_parts) - $common;
        $down = array_slice($to_parts, $common);

        $parts = array_merge(array_fill(0, $up, ".."), $down);
        return implode("/", $parts) ?: ".";
    }

    /**
     * Create or refresh a symlink at $target pointing to $source.
     * Handles conflicts (existing non-symlinks) based on --force flag.
     *
     * The symlink value is computed as a relative path from the symlink's
     * parent directory to the source, so it works regardless of CWD and
     * survives directory moves.
     */
    private function flatten_place_symlink(
        string $source,
        string $target,
        bool $force,
        int &$created,
        int &$refreshed,
        int &$forced
    ): void {
        // Resolve both paths to absolute so we can compute a correct
        // relative symlink value.  The source may not have a realpath()
        // (e.g. broken symlink), but its parent directory should exist.
        $abs_source = realpath($source);
        if ($abs_source === false) {
            // Source itself may be a symlink or not exist yet — try
            // resolving the parent and appending the basename.
            $parent_real = realpath(dirname($source));
            if ($parent_real === false) {
                throw new RuntimeException(
                    "Cannot resolve source path for symlink: {$source}",
                );
            }
            $abs_source = $parent_real . "/" . basename($source);
        }

        // The target's parent must exist (we create flatten-to before calling this).
        $target_parent_real = realpath(dirname($target));
        if ($target_parent_real === false) {
            throw new RuntimeException(
                "Cannot resolve target parent directory: " . dirname($target),
            );
        }

        $link_value = self::compute_relative_path($target_parent_real, $abs_source);

        // If the target is already a symlink, check if it resolves to the
        // same place. Refresh if not, skip if already correct.
        if (is_link($target)) {
            $current_link_target = readlink($target);
            if ($current_link_target === $link_value) {
                $refreshed++;
                return;
            }
            // Points elsewhere — remove and recreate
            unlink($target);
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Refreshed symlink: {$target} (was -> {$current_link_target})",
            );
            if (!symlink($link_value, $target)) {
                throw new RuntimeException(
                    "Failed to create symlink: {$target} -> {$link_value}",
                );
            }
            $refreshed++;
            return;
        }

        // If something exists at the target path that is not a symlink,
        // this is a conflict.
        if (file_exists($target)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create symlink at {$target}: a non-symlink " .
                        (is_dir($target) ? "directory" : "file") .
                        " already exists. Use --force to remove it and replace with a symlink.",
                );
            }

            $type = is_dir($target) ? "directory" : "file";
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT FORCE | Removing conflicting {$type}: {$target}",
                true,
            );

            // At this point, we know $target is not a symlink (symlinks
            // are handled above and return early). So we only need to
            // distinguish between directories and regular files.
            if (is_dir($target)) {
                $this->remove_directory_recursive($target);
            } else {
                unlink($target);
            }
            $forced++;
        }

        // Create the symlink
        if (!symlink($link_value, $target)) {
            throw new RuntimeException(
                "Failed to create symlink: {$target} -> {$link_value}",
            );
        }
        $this->audit_log(
            "FLAT-DOCUMENT-ROOT | Created symlink: {$target} -> {$link_value}",
        );
        $created++;
    }

    /**
     * Ensure a path is a real directory (not a symlink).
     * If it's a symlink, remove it (or error without --force).
     * If it doesn't exist, create it.
     */
    private function flatten_ensure_real_directory(
        string $path,
        bool $force,
        int &$forced
    ): void {
        if (is_link($path)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create real directory at {$path}: a symlink already " .
                        "exists. Use --force to remove it.",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT FORCE | Replacing symlink with real directory: {$path}",
                true,
            );
            unlink($path);
            $forced++;
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create directory: {$path}",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Created directory: {$path}",
            );
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function remove_directory_recursive(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan directory for removal: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * If --new-site-url is set, derive the source origin from the export URL
     * and append implicit --rewrite-url mappings for both HTTP and HTTPS
     * variants of the old URL to $options. The new URL is used verbatim.
     */
    private function resolve_new_site_url_option(array &$options): void
    {
        if (empty($options["new_site_url"])) {
            return;
        }

        $parsed_url = parse_url($this->remote_url);
        if (!$parsed_url || !isset($parsed_url['scheme'], $parsed_url['host'])) {
            throw new InvalidArgumentException(
                "--new-site-url requires a valid export URL to derive the source site origin.",
            );
        }

        $host_with_port = $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $host_with_port .= ':' . $parsed_url['port'];
        }

        if (!isset($options["rewrite_url"])) {
            $options["rewrite_url"] = [];
        }

        // Rewrite both http:// and https:// variants of the old origin
        // to the new URL verbatim, so we catch references stored with
        // either scheme in the database.
        $new_url = $options["new_site_url"];
        $options["rewrite_url"][] = ['https://' . $host_with_port, $new_url];
        $options["rewrite_url"][] = ['http://' . $host_with_port, $new_url];
    }

    private function escape_pdo_dsn_value(string $value): string
    {
        return str_replace(';', ';;', $value);
    }

    private function create_sqlite_target_pdo(string $target_path, string $target_db): PDO
    {
        if (!extension_loaded("pdo_sqlite")) {
            throw new RuntimeException(
                "SQLite target support requires the pdo_sqlite extension.",
            );
        }

        // The bundled loader require_onces a fixed set of class files
        // relative to its own dirname. When the host already loaded a
        // different copy of those same classes (notably WordPress
        // Playground's auto_prepend), each class declaration would throw
        // a fatal "name already in use". Skip the loader entirely when the
        // host's copy is already in memory — both trees expose the same
        // public class names, so the existing instance is fine.
        $driver_loader = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        if (
            class_exists("WP_PDO_MySQL_On_SQLite", false) &&
            class_exists("WP_Parser_Grammar", false)
        ) {
            $driver_loader = null;
        }

        if ($target_path !== ':memory:') {
            $target_dir = dirname($target_path);
            if ($target_dir !== '' && $target_dir !== '.' && !is_dir($target_dir)) {
                if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                    throw new RuntimeException(
                        "Cannot create SQLite directory: {$target_dir}",
                    );
                }
            }
        }

        if ($driver_loader !== null) { require_once $driver_loader; }

        $dsn = sprintf(
            "mysql-on-sqlite:path=%s;dbname=%s",
            $this->escape_pdo_dsn_value($target_path),
            $this->escape_pdo_dsn_value($target_db),
        );

        try {
            $pdo = new WP_PDO_MySQL_On_SQLite($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target SQLite database: " . $e->getMessage(),
                0,
                $e,
            );
        }

        // SQL dumps from MySQLDumpProducer encode every value as
        // FROM_BASE64('...'), and deactivate_host_plugins() reuses the same
        // encoding for its UPDATE — so the SQLite connection needs both.
        $sqlite_pdo = $pdo->get_connection()->get_pdo();
        register_sqlite_function($sqlite_pdo, 'FROM_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_decode($data);
        });
        register_sqlite_function($sqlite_pdo, 'TO_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_encode($data);
        });

        return $pdo;
    }

    private function create_target_db_apply_connection(array $options): array
    {
        $target_engine = strtolower((string) ($options["target_engine"] ?? "mysql"));
        if (!in_array($target_engine, ["mysql", "sqlite"], true)) {
            throw new InvalidArgumentException(
                "Invalid --target-engine value: {$target_engine}. Valid engines: mysql, sqlite.",
            );
        }

        if ($target_engine === "sqlite") {
            $target_path = $options["target_sqlite_path"] ?? null;
            $target_db = $options["target_db"] ?? "sqlite_database";

            if (!$target_path) {
                $content_dir = rtrim(
                    $this->import_state()->preflight["data"]["database"]["wp"]["paths_urls"]["content_dir"] ?? "",
                    "/",
                );
                if(!$content_dir) {
                    throw new InvalidArgumentException(
                        "--target-sqlite-path option is required but was missing.",
                    );
                }
                $target_path = $this->get_filesystem_root_path() . $content_dir . '/database/.ht.sqlite';
                $this->audit_log("DB-APPLY | defaulting SQLite path to: {$target_path}");
                $this->progress->show_lifecycle_line("SQLite path: {$target_path}\n");
            }

            // Persist target database configuration for apply-runtime.
            $this->import_state()->apply->target_engine = "sqlite";
            $this->import_state()->apply->target_db = $target_db;
            $this->import_state()->apply->target_sqlite_path = $target_path;

            return [
                $this->create_sqlite_target_pdo($target_path, $target_db),
                sprintf(
                    "engine=sqlite path=%s db=%s",
                    $target_path,
                    $target_db,
                ),
            ];
        }

        $target_host = $options["target_host"] ?? "127.0.0.1";
        $target_port = (int) ($options["target_port"] ?? 3306);
        $target_user = $options["target_user"] ?? null;
        $target_pass = $options["target_pass"] ?? "";
        $target_db = $options["target_db"] ?? null;

        if (!$target_user || !$target_db) {
            throw new InvalidArgumentException(
                "db-apply with --target-engine=mysql requires --target-user and --target-db.",
            );
        }

        // Persist target database configuration for apply-runtime.
        $this->import_state()->apply->target_engine = "mysql";
        $this->import_state()->apply->target_db = $target_db;
        $this->import_state()->apply->target_host = $target_host;
        $this->import_state()->apply->target_port = $target_port;
        $this->import_state()->apply->target_user = $target_user;
        $this->import_state()->apply->target_pass = $target_pass;

        $dsn = "mysql:host={$target_host};port={$target_port};dbname={$target_db};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $target_user, $target_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target MySQL database: " . $e->getMessage(),
                0,
                $e,
            );
        }

        return [
            $pdo,
            sprintf(
                "engine=mysql host=%s port=%d db=%s user=%s",
                $target_host,
                $target_port,
                $target_db,
                $target_user,
            ),
        ];
    }

    public function run_db_apply(array $options): void
    {
        $sql_file = $this->state_dir . "/db.sql";
        if (!file_exists($sql_file)) {
            throw new RuntimeException(
                "db.sql not found in {$this->state_dir}. Run db-pull first.",
            );
        }

        // If --new-site-url is provided, derive the source origin from the
        // export URL and add an implicit --rewrite-url mapping.
        $this->resolve_new_site_url_option($options);

        // Parse URL mapping
        $url_mapping = [];
        if (!empty($options["rewrite_url"])) {
            foreach ($options["rewrite_url"] as $pair) {
                $url_mapping[$pair[0]] = $pair[1];
            }
        }

        // Show discovered domains if available
        $domains_file = $this->state_dir . "/.import-domains.json";
        if (file_exists($domains_file)) {
            $domains = json_decode(file_get_contents($domains_file), true);
            if (is_array($domains) && !empty($domains)) {
                $this->audit_log(
                    sprintf("DISCOVERED DOMAINS | %s", implode(", ", $domains)),
                    false,
                );
                $this->progress->show_lifecycle_line("Discovered domains in SQL dump:\n");
                foreach ($domains as $domain) {
                    $mapped = isset($url_mapping[$domain]) ? " => {$url_mapping[$domain]}" : " (not mapped)";
                    $this->progress->show_lifecycle_line("  {$domain}{$mapped}\n");
                }
                $this->progress->show_lifecycle_line("\n");
                $domain_map = [];
                foreach ($domains as $domain) {
                    $domain_map[$domain] = $url_mapping[$domain] ?? null;
                }
                $this->output_progress([
                    "type" => "domains_discovered",
                    "domains" => $domain_map,
                    "message" => "Discovered " . count($domains) . " domain(s) in SQL dump",
                ], true);
            }
        }

        // Check state for resume
        $state_command = $this->import_state()->active_resumable_command->command_name ?? null;
        $current_status = $state_command === "db-apply" ? ($this->import_state()->active_resumable_command->completion_state ?? null) : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "db-apply already completed. Use --abort flag to re-run.",
            );
        }

        $apply_state = $this->import_state()->apply;
        $statements_executed = $apply_state->statements_executed;
        $bytes_read = $apply_state->bytes_read;
        $is_resume = $current_status === "in_progress" && $statements_executed > 0;

        if ($is_resume) {
            $this->audit_log(
                sprintf(
                    "RESUME db-apply | statements=%d | bytes_read=%d",
                    $statements_executed,
                    $bytes_read,
                ),
                true,
            );
            $this->progress->show_lifecycle_line("Resuming db-apply (executed: {$statements_executed} statements)\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-apply",
                "statements_executed" => $statements_executed,
                "bytes_read" => $bytes_read,
                "message" => "Resuming db-apply (executed: {$statements_executed} statements)",
            ], true);
        } else {
            $this->import_state()->active_resumable_command->command_name = "db-apply";
            $this->import_state()->active_resumable_command->completion_state = "in_progress";
            $this->import_state()->apply = new DatabaseApplyCommandState();
            if (!empty($url_mapping)) {
                $this->import_state()->apply->rewrite_url = $url_mapping;
            }
            $this->save_state($this->state);
            $statements_executed = 0;
            $bytes_read = 0;

            $this->audit_log("START db-apply", true);
            $this->progress->show_lifecycle_line("Starting db-apply\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-apply",
                "message" => "Starting db-apply",
            ], true);
        }

        // On resume, use the persisted URL mapping if none provided on CLI
        if (empty($url_mapping) && !empty($apply_state->rewrite_url)) {
            $url_mapping = $apply_state->rewrite_url;
        }

        // Set up SQL statement rewriter if we have URL mappings
        $stmt_rewriter = null;
        if (!empty($url_mapping)) {
            $table_prefix = $this->import_state()->preflight["data"]["database"]["wp"]["table_prefix"] ?? 'wp_';
            $stmt_rewriter = new SqlStatementRewriter(
                new StructuredDataUrlRewriter($url_mapping),
                $table_prefix,
            );
            $this->audit_log(
                sprintf(
                    "URL MAPPING | %d mapping(s): %s",
                    count($url_mapping),
                    implode(", ", array_map(
                        fn($from, $to) => "{$from} => {$to}",
                        array_keys($url_mapping),
                        array_values($url_mapping),
                    )),
                ),
                false,
            );
        }

        [$pdo, $connection_label] = $this->create_target_db_apply_connection($options);
        $sqlite_prepared_pdo = null;
        $sqlite_prepared_statement_cache = [];
        $sqlite_prepared_statement_cache_order = [];
        if (
            strtolower((string) ($options["target_engine"] ?? "mysql")) === "sqlite"
            && method_exists($pdo, 'get_connection')
        ) {
            $sqlite_prepared_pdo = $pdo->get_connection()->get_pdo();
            // These are connection-local import hints. Avoid journal/sync/locking
            // PRAGMAs because they alter durability or observable database state.
            $sqlite_prepared_pdo->exec('PRAGMA temp_store = MEMORY');
            $sqlite_prepared_pdo->exec('PRAGMA cache_size = -32768');
            $this->audit_log(
                'SQLite db-apply PRAGMAs | temp_store=MEMORY | cache_size=32768 KiB',
                false,
            );
        }

        $this->audit_log(
            "CONNECTED | {$connection_label}",
            false,
        );

        // Stream db.sql through the query stream and execute. Use the
        // fast strcspn-based parser by default; it self-falls-back to
        // WP_MySQL_Naive_Query_Stream if it ever fails to make progress
        // (buffer overflow without a top-level semicolon, or input drained
        // mid-string/comment), so the slow path is still available for
        // any input the fast scanner doesn't handle.
        $query_stream = new \WP_MySQL_FastQueryStream();
        $query_stream->set_error_logger(function (array $err) use (&$stmt_count) {
            $this->audit_log(
                sprintf(
                    "FAST QUERY STREAM fallback | reason=%s | byte_offset=%d | stmt=%d | %s | context=%.200s",
                    $err['reason'] ?? '?',
                    $err['byte_offset'] ?? 0,
                    $stmt_count,
                    $err['message'] ?? '',
                    $err['context'] ?? ''
                ),
                true
            );
            $this->progress->show_lifecycle_line(
                "Fast query stream fell back to lexer-based parser at byte offset "
                . ($err['byte_offset'] ?? 0) . "; see audit log for details\n"
            );
        });
        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        $sql_file_size = filesize($sql_file);
        $total_bytes_read = 0;
        $stmt_count = 0;
        $skipped = 0;
        $save_every = 100;
        $stmts_since_save = 0;

        // Load pre-computed statement count from db-pull for progress reporting
        $sql_stats_file = $this->state_dir . "/.import-sql-stats.json";
        $statements_total = null;
        if (file_exists($sql_stats_file)) {
            $stats = json_decode(file_get_contents($sql_stats_file), true);
            if (is_array($stats) && isset($stats["statements_total"])) {
                $statements_total = (int) $stats["statements_total"];
            }
        }

        // If resuming, seek to saved position. bytes_read is the byte offset
        // right after the last successfully executed query (tracked via
        // query_stream->get_bytes_consumed()), so no statement skipping is
        // needed after seeking — we're exactly at the next un-executed query.
        $seek_offset = 0;
        $stmts_to_skip = 0;
        if ($bytes_read > 0 && $bytes_read < $sql_file_size) {
            fseek($sql_handle, $bytes_read);
            $total_bytes_read = $bytes_read;
            $seek_offset = $bytes_read;
        } elseif ($statements_executed > 0) {
            // Can't seek — need to scan from beginning and skip statements
            $stmts_to_skip = $statements_executed;
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "db-apply",
            "statements_total" => $statements_total,
            "message" => "Applying SQL" . ($statements_total !== null ? " ({$statements_total} statements)" : ""),
        ]);

        try {
            $chunk_size = 64 * 1024; // 64KB read chunks

            while (!feof($sql_handle)) {
                // Check shutdown
                if ($this->shutdown_requested) {
                    $this->audit_log("SHUTDOWN REQUESTED | saving state", true);
                    break;
                }
                if (function_exists("pcntl_signal_dispatch")) {
                    pcntl_signal_dispatch();
                }

                $data = fread($sql_handle, $chunk_size);
                if ($data === false || $data === '') {
                    break;
                }
                $total_bytes_read += strlen($data);
                $query_stream->append_sql($data);

                while ($query_stream->next_query()) {
                    $query = $query_stream->get_query();
                    $stmt_count++;

                    // Skip already-executed statements on resume
                    if ($stmts_to_skip > 0) {
                        $stmts_to_skip--;
                        continue;
                    }

                    // Execute against target database
                    $executed_query = $query;
                    try {
                        $this->execute_db_apply_query(
                            $pdo,
                            $query,
                            $stmt_rewriter,
                            $sqlite_prepared_pdo,
                            $sqlite_prepared_statement_cache,
                            $sqlite_prepared_statement_cache_order,
                            $executed_query,
                        );
                    } catch (PDOException $e) {
                        $this->audit_log(
                            sprintf(
                                "SQL ERROR | stmt=%d | %s | query=%.200s",
                                $stmt_count,
                                $e->getMessage(),
                                $executed_query,
                            ),
                            true,
                        );
                        throw new RuntimeException(
                            "SQL execution error at statement {$stmt_count}: " .
                            $e->getMessage(),
                        );
                    }

                    $statements_executed++;
                    $stmts_since_save++;

                    // Save state periodically. bytes_read is the file offset
                    // right after the last extracted query — NOT total_bytes_read,
                    // which includes bytes buffered in the query stream that haven't
                    // formed a complete query yet. This ensures resumption starts at
                    // the exact boundary between executed and un-executed queries.
                    if ($stmts_since_save >= $save_every) {
                        $this->import_state()->apply->statements_executed = $statements_executed;
                        $this->import_state()->apply->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
                        $this->save_state($this->state);
                        $stmts_since_save = 0;

                        // Progress output
                        $apply_fraction = $sql_file_size > 0
                            ? $total_bytes_read / $sql_file_size
                            : null;
                        $pct = $apply_fraction !== null ? round($apply_fraction * 100, 1) : 0;

                        $progress_message = sprintf(
                            "%s statements",
                            $statements_total === null
                                ? number_format($statements_executed)
                                : number_format($statements_executed) . " / " . number_format($statements_total),
                        );

                        $this->output_progress([
                            "phase" => "db-apply",
                            "statements_executed" => $statements_executed,
                            "bytes_read" => $total_bytes_read,
                            "bytes_total" => $sql_file_size,
                            "pct" => $pct,
                            "statements_total" => $statements_total,
                            "message" => $progress_message,
                        ]);

                        $this->progress->show_progress_line($progress_message, $apply_fraction);
                    }
                }
            }

            // Drain any remaining buffered query
            $query_stream->mark_input_complete();
            while ($query_stream->next_query()) {
                $query = $query_stream->get_query();
                $stmt_count++;

                if ($stmts_to_skip > 0) {
                    $stmts_to_skip--;
                    continue;
                }

                $executed_query = $query;
                try {
                    $this->execute_db_apply_query(
                        $pdo,
                        $query,
                        $stmt_rewriter,
                        $sqlite_prepared_pdo,
                        $sqlite_prepared_statement_cache,
                        $sqlite_prepared_statement_cache_order,
                        $executed_query,
                    );
                } catch (PDOException $e) {
                    $this->audit_log(
                        sprintf(
                            "SQL ERROR | stmt=%d | %s | query=%.200s",
                            $stmt_count,
                            $e->getMessage(),
                            $executed_query,
                        ),
                        true,
                    );
                    throw new RuntimeException(
                        "SQL execution error at statement {$stmt_count}: " .
                        $e->getMessage(),
                    );
                }

                $statements_executed++;
            }

            if ($this->shutdown_requested) {
                // Save partial progress
                $this->import_state()->apply->statements_executed = $statements_executed;
                $this->import_state()->apply->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
                $this->import_state()->active_resumable_command->completion_state = "partial";
                $this->save_state($this->state);
                $this->audit_log(
                    sprintf(
                        "PARTIAL db-apply | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );
                $this->output_progress([
                    "status" => "partial",
                    "phase" => "db-apply",
                    "statements_executed" => $statements_executed,
                    "statements_total" => $statements_total,
                    "message" => "db-apply partial: {$statements_executed} statements executed",
                ], true);
            } else {
                // Deactivate host-specific plugins before marking complete.
                // The host analyzer declares paths_to_remove; any entry under
                // wp-content/plugins/ means that plugin will be deleted from
                // disk during apply-runtime. We remove it from active_plugins
                // now, while the database connection is still open, so
                // WordPress won't complain about missing plugin files.
                // We skip deactivate_plugins() because the plugin files will
                // be gone by the time WordPress boots — firing deactivation
                // hooks into absent code is pointless.
                $deactivated = $this->deactivate_host_plugins($pdo);
                foreach ($deactivated as $basename) {
                    $this->audit_log("DB-APPLY | deactivated plugin {$basename} (host-specific)");
                }

                // Drop plugins whose URL builders break when the site
                // URL has a non-/ path segment (e.g. WordPress Playground's
                // /scope:<slug>/ iframe scope).
                $deactivated = $this->deactivate_path_incompatible_plugins(
                    $pdo,
                    (string) ($options["new_site_url"] ?? ""),
                );
                foreach ($deactivated as $basename) {
                    $this->audit_log("DB-APPLY | deactivated plugin {$basename} (path-incompatible siteurl)");
                }

                // Mark complete
                $this->import_state()->apply->statements_executed = $statements_executed;
                $this->import_state()->apply->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
                $this->import_state()->active_resumable_command->completion_state = "complete";
                $this->save_state($this->state);

                $this->audit_log(
                    sprintf(
                        "db-apply complete | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );

                $this->output_progress([
                    "status" => "complete",
                    "phase" => "db-apply",
                    "statements_executed" => $statements_executed,
                    "statements_total" => $statements_total,
                    "message" => "db-apply complete ({$statements_executed} statements executed)",
                ]);

                if (!$this->progress->is_mode('pipeline')) {
                    // Clear the progress line before printing the final message
                    $this->progress->clear_progress_line();
                }
                $this->progress->show_lifecycle_line("db-apply complete ({$statements_executed} statements executed)\n");
            }
        } finally {
            fclose($sql_handle);
        }
    }

    private function execute_db_apply_query(
        PDO $pdo,
        string $query,
        ?SqlStatementRewriter $stmt_rewriter,
        ?PDO $sqlite_prepared_pdo,
        array &$sqlite_prepared_statement_cache,
        array &$sqlite_prepared_statement_cache_order,
        string &$executed_query
    ): void {
        $executed_query = $query;

        if ($sqlite_prepared_pdo !== null) {
            $prepared_insert = $stmt_rewriter !== null
                ? $stmt_rewriter->build_sqlite_prepared_insert($query)
                : SQLitePreparedInsertBuilder::build($query);

            if ($prepared_insert !== null) {
                $executed_query = $prepared_insert['sql'];
                $statement = $sqlite_prepared_statement_cache[$prepared_insert['sql']] ?? null;
                if (!$statement instanceof PDOStatement) {
                    $statement = $sqlite_prepared_pdo->prepare($prepared_insert['sql']);
                    if ($statement === false) {
                        throw new PDOException('Failed to prepare SQLite INSERT statement.');
                    }

                    $sqlite_prepared_statement_cache[$prepared_insert['sql']] = $statement;
                    $sqlite_prepared_statement_cache_order[] = $prepared_insert['sql'];
                    if (count($sqlite_prepared_statement_cache_order) > self::SQLITE_PREPARED_INSERT_CACHE_MAX) {
                        $oldest_sql = array_shift($sqlite_prepared_statement_cache_order);
                        if (is_string($oldest_sql)) {
                            unset($sqlite_prepared_statement_cache[$oldest_sql]);
                        }
                    }
                } else {
                    $statement->closeCursor();
                }

                foreach ($prepared_insert['params'] as $index => $value) {
                    $statement->bindValue(
                        $index + 1,
                        $value,
                        $prepared_insert['param_types'][$index] ?? PDO::PARAM_STR
                    );
                }

                if ($statement->execute() === false) {
                    throw new PDOException('Failed to execute SQLite INSERT statement.');
                }
                return;
            }
        }

        if ($stmt_rewriter !== null) {
            $executed_query = $stmt_rewriter->rewrite($query);
        }

        $pdo->exec($executed_query);
    }

    /**
     * Deactivate host-specific plugins in the target database.
     *
     * Looks at the detected webhost's paths_to_remove for entries under
     * wp-content/plugins/ and removes matching basenames from the
     * active_plugins option. Runs at the end of db-apply while the PDO
     * connection is still open.
     *
     * @return string[]  Plugin basenames actually removed.
     */
    private function deactivate_host_plugins(PDO $pdo): array
    {
        $webhost = $this->import_state()->webhost ?? "other";
        $analyzer = host_analyzer_for($webhost);
        $preflight_data = $this->import_state()->preflight["data"] ?? [];
        $manifest = $analyzer->analyze($preflight_data);

        $plugin_dirs = [];
        foreach ($manifest->paths_to_remove as $rel_path) {
            if (preg_match('#^wp-content/plugins/([^/]+)$#', $rel_path, $m)) {
                $plugin_dirs[] = $m[1];
            }
        }

        return $this->deactivate_plugins_by_dir($pdo, $plugin_dirs, "host-specific");
    }

    /**
     * Deactivate plugins whose URL builders break when the new site URL
     * has a non-/ path segment.
     *
     * page-optimize's concat-css/js builds asset URLs by concatenating
     * `$siteurl . $path`, which produces doubled prefixes (e.g.
     * `/scope:abc/scope:abc/wp-content/...`) when `$siteurl` already
     * carries a path component like WordPress Playground's
     * `/scope:<slug>/` iframe scope.
     *
     * wpcomsh has the same shape but lives on WP Cloud, where
     * WpcloudHostAnalyzer's paths_to_remove already feeds it through
     * deactivate_host_plugins().
     *
     * Skipped when the new site URL is empty or has no path beyond `/`.
     *
     * @return string[]  Plugin basenames actually removed.
     */
    private function deactivate_path_incompatible_plugins(PDO $pdo, string $new_site_url): array
    {
        if ($new_site_url === "") {
            return [];
        }
        $path = parse_url($new_site_url, PHP_URL_PATH);
        if ($path === null || $path === "" || $path === "/") {
            return [];
        }

        return $this->deactivate_plugins_by_dir(
            $pdo,
            ['page-optimize'],
            "path-incompatible siteurl",
        );
    }

    /**
     * Remove plugin entries whose basename starts with one of $plugin_dirs
     * from the `active_plugins` option in the target database.
     *
     * Requires `$pdo` to support `FROM_BASE64()` — native on MySQL 5.6+,
     * registered on SQLite by create_sqlite_target_pdo().
     *
     * @param string[] $plugin_dirs  Plugin directory names to match against
     *                               each `active_plugins` entry's basename.
     * @param string   $reason       Short label used in audit log messages.
     * @return string[]              Plugin basenames actually removed.
     */
    private function deactivate_plugins_by_dir(PDO $pdo, array $plugin_dirs, string $reason): array
    {
        if (empty($plugin_dirs)) {
            return [];
        }

        $preflight_data = $this->import_state()->preflight["data"] ?? [];
        $table_prefix = $preflight_data["database"]["wp"]["table_prefix"] ?? 'wp_';
        // Quote the table name to prevent SQL injection from a crafted prefix.
        $options_table = '`' . str_replace('`', '``', $table_prefix . 'options') . '`';

        // Stick to query()/exec() — WP_PDO_MySQL_On_SQLite overrides those
        // but not prepare(), and prepare() throws "object is uninitialized"
        // on the wrapper.
        $row = $pdo->query(
            "SELECT option_value FROM {$options_table} WHERE option_name = 'active_plugins'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['option_value'])) {
            return [];
        }

        // Use PhpSerializationProcessor to iterate string values safely —
        // no unserialize(), no risk of arbitrary object instantiation.
        $serialized = $row['option_value'];
        $processor = new \PhpSerializationProcessor($serialized);
        if ($processor->is_malformed()) {
            return [];
        }

        // Partition active_plugins entries against the directory list.
        $deactivated_plugins = [];
        $retained_plugins = [];
        while ($processor->next_value()) {
            $basename = $processor->get_value();
            $is_match = false;
            foreach ($plugin_dirs as $dir) {
                if (strpos($basename, $dir . '/') === 0) {
                    $is_match = true;
                    break;
                }
            }
            if ($is_match) {
                $deactivated_plugins[] = $basename;
            } else {
                $retained_plugins[] = $basename;
            }
        }

        if (empty($deactivated_plugins)) {
            $this->audit_log("DB-APPLY | no {$reason} plugins found in active_plugins");
            return [];
        }

        // FROM_BASE64 carries the new value into SQL — base64 is
        // [A-Za-z0-9+/=], so the literal can't carry SQL-special characters
        // regardless of what a plugin basename contains.
        $encoded_value = base64_encode(serialize(array_values($retained_plugins)));
        $pdo->exec(
            "UPDATE {$options_table} SET option_value = FROM_BASE64('{$encoded_value}') WHERE option_name = 'active_plugins'"
        );
        // The SQL dump runs with AUTOCOMMIT=0 and issues a final COMMIT,
        // but autocommit stays off. Our UPDATE needs an explicit COMMIT.
        $pdo->exec('COMMIT');

        $this->audit_log(
            "DB-APPLY | updated active_plugins (" .
            count($deactivated_plugins) . " {$reason} plugin(s) removed)",
        );

        return $deactivated_plugins;
    }

    /**
     * Command: db-index
     *
     * Streams table metadata (name/rows/size) for planning and diagnostics.
     */
    private function run_db_index(): void
    {
        $state_command = $this->import_state()->active_resumable_command->command_name ?? null;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $has_cursor =
            $state_command === "db-index" &&
            !empty($this->import_state()->active_resumable_command->remote_cursor ?? null);
        $current_status =
            $state_command === "db-index"
                ? $this->import_state()->active_resumable_command->completion_state ?? null
                : null;
        $tables_exists = file_exists($tables_file);

        if ($current_status === "complete") {
            if ($tables_exists) {
                throw new RuntimeException(
                    "db-index already completed and db-tables.jsonl exists. Use --abort flag to start over.",
                );
            } else {
                throw new RuntimeException(
                    "db-index marked complete but db-tables.jsonl is missing. Use --abort flag to re-run.",
                );
            }
        }

        if (!$has_cursor) {
            $this->import_state()->active_resumable_command->command_name = "db-index";
            $this->import_state()->active_resumable_command->completion_state = "in_progress";
            $this->import_state()->active_resumable_command->remote_cursor = null;
            $this->import_state()->active_resumable_command->current_stage = null;
            $this->import_state()->diff = new FileDiffProgressState();
            $this->import_state()->db_index = new DatabaseTableIndexState();
            $this->save_state($this->state);

            $this->audit_log("START db-index", true);
            $this->progress->show_lifecycle_line("Starting db-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-index",
                "message" => "Starting db-index",
            ], true);
        } else {
            $this->audit_log(
                sprintf(
                    "RESUME db-index | cursor=%s",
                    substr($this->import_state()->active_resumable_command->remote_cursor, 0, 20) . "...",
                ),
                true,
            );
            $this->progress->show_lifecycle_line("Resuming db-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-index",
                "message" => "Resuming db-index",
            ], true);
        }

        $this->import_state()->active_resumable_command->command_name = "db-index";
        $this->save_state($this->state);

        $this->download_db_index();

        $this->import_state()->active_resumable_command->completion_state = "complete";
        $this->save_state($this->state);

        $tables = (int) ($this->import_state()->db_index->tables ?? 0);
        $this->audit_log(
            sprintf("db-index complete: %d tables", $tables),
            true,
        );

        $this->progress->show_lifecycle_line("db-index complete: {$tables} tables\n");
        $this->progress->show_lifecycle_line("Table stats: {$tables_file}\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-index",
            "tables" => $tables,
            "tables_file" => $tables_file,
            "audit_log" => $this->audit_log,
            "message" => "db-index complete: {$tables} tables",
        ], true);
    }

    /**
     * Download file content for a prepared file list (file_fetch).
     *
     * @param array|null $post_data Optional POST data
     * @param string|null $cursor Cursor for resumption within the current batch
     */
    private function download_file_fetch(
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
        $fetch_state = $this->get_download_list_fetch_state($state_key);
        $cursor = $cursor ?? $fetch_state->cursor;
        $complete = false;
        $chunks_since_save = 0;

        // Crash recovery: if we have a tracked file that's larger than expected,
        // truncate it. This happens if we crashed after writing but before saving
        // the new cursor, so we'll re-fetch the same data.
        $tracked_file = $this->import_state()->current_file ?? null;
        $tracked_bytes = $this->import_state()->current_file_bytes ?? null;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating %s from %d to %d bytes",
                        $tracked_file,
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($tracked_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $params = $this->get_tuned_params("file_fetch");
        // Always send directory[] – see comment in download_remote_index().
        $export_dirs = $this->get_export_directories();
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }
        $url = $this->build_url("file_fetch", $cursor, $params);
        $this->audit_log("Downloading file fetch from {$url}");
        $this->audit_log("POST data: " . json_encode($post_data));

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        // Resume recovery: if a file was partially downloaded in a previous
        // request, re-open it in append mode so continuation chunks (where
        // is_first=false) can still be written.  Without this, the context
        // starts with file_handle=null and non-first chunks are silently dropped.
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $context->file_handle = fopen($tracked_file, "ab");
            if ($context->file_handle) {
                $context->file_path = $tracked_file;
                $context->file_bytes_written = $tracked_bytes;
                $this->audit_log(
                    sprintf(
                        "RESUME FILE | Re-opened %s at %d bytes for continued download",
                        $tracked_file,
                        $tracked_bytes,
                    ),
                    true,
                );
            }
        }

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            &$chunks_since_save,
            $context,
            $state_key
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            // Streamed file bodies can arrive in multiple parser callbacks
            // for one exporter file part. Save only at the part boundary:
            // mid-body, the cursor already points to the end of the part
            // while file_bytes_written may still lag; at is_streaming_close
            // the bytes are on disk and we force a per-part checkpoint.
            $is_streaming_body = !empty($chunk["is_streaming_body"]);
            $is_streaming_close = !empty($chunk["is_streaming_close"]);
            if (!$is_streaming_body) {
                $chunks_since_save++;
                $force_save = $is_streaming_close;
                if ($force_save || $chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS) {
                    $this->get_download_list_fetch_state($state_key)->cursor = $cursor;
                    // Track current file for crash recovery
                    if ($context->file_handle && $context->file_path) {
                        // Flush to ensure bytes are on disk before saving state
                        fflush($context->file_handle);
                        $this->import_state()->current_file = $context->file_path;
                        $this->import_state()->current_file_bytes = $context->file_bytes_written;
                    } else {
                        $this->import_state()->current_file = null;
                        $this->import_state()->current_file_bytes = null;
                    }
                    $this->save_state($this->state);
                    $chunks_since_save = 0;
                }
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "file") {
                $this->handle_file_chunk($chunk, $context);
            } elseif ($chunk_type === "directory") {
                $this->handle_directory_chunk($chunk);
            } elseif ($chunk_type === "symlink") {
                $this->handle_symlink_chunk($chunk);
            } elseif ($chunk_type === "missing") {
                $path = base64_decode($chunk["headers"]["x-file-path"] ?? "");
                if ($path) {
                    $this->audit_log("Missing on server: {$path}", true);
                }
                // @TODO: Cleanup the local file that we may have started downloading.
            } elseif ($chunk_type === "error") {
                $this->handle_error_chunk($chunk, "files", $context);
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "files");
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
                $context->saw_completion = true;
                $context->response_stats = [
                    "status" => $chunk["headers"]["x-status"] ?? null,
                    "bytes_processed" =>
                        isset($chunk["headers"]["x-bytes-processed"])
                            ? (int) $chunk["headers"]["x-bytes-processed"]
                            : null,
                    "server_time" =>
                        isset($chunk["headers"]["x-time-elapsed"])
                            ? (float) $chunk["headers"]["x-time-elapsed"]
                            : null,
                    "memory_used" =>
                        isset($chunk["headers"]["x-memory-used"])
                            ? (int) $chunk["headers"]["x-memory-used"]
                            : null,
                    "memory_limit" =>
                        isset($chunk["headers"]["x-memory-limit"])
                            ? (int) $chunk["headers"]["x-memory-limit"]
                            : null,
                ];
                $this->output_progress(
                    [
                        "phase" => "files",
                        "status" => $chunk["headers"]["x-status"] ?? "unknown",
                        "files_completed" =>
                            (int) ($chunk["headers"]["x-files-completed"] ?? 0),
                        "bytes_processed" =>
                            (int) ($chunk["headers"]["x-bytes-processed"] ?? 0),
                    ],
                    true,
                );
            }
        };

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->fetch_streaming(
                $url,
                $cursor,
                $context,
                $post_data,
                "file_fetch",
            );
        } catch (CurlTimeoutException $e) {
            // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
            // with no progress, so we don't retry forever.
            $this->assert_can_retry_consecutive_timeout("file_fetch", $cursor_before, $cursor);
            // Save state so the next invocation resumes from the
            // last cursor instead of crashing with exit code 1.
            $this->get_download_list_fetch_state($state_key)->cursor = $cursor;
            $this->finalize_index_updates();
            if ($context->file_handle && $context->file_path) {
                fflush($context->file_handle);
                $this->import_state()->current_file = $context->file_path;
                $this->import_state()->current_file_bytes = $context->file_bytes_written;
            }
            $this->import_state()->active_resumable_command->completion_state = "partial";
            $this->save_state($this->state);
            return false;
        }
        $this->import_state()->consecutive_timeouts = 0;
        $wall_time = microtime(true) - $request_start;

        $this->finalize_tuned_request(
            "file_fetch",
            $wall_time,
            $context->response_stats ?? [],
        );
        $this->get_download_list_fetch_state($state_key)->cursor = $cursor;
        $this->finalize_index_updates();
        // Update file tracking: track in-progress file, or clear if complete/no active file
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->import_state()->current_file = $context->file_path;
            $this->import_state()->current_file_bytes = $context->file_bytes_written;
        } else {
            $this->import_state()->current_file = null;
            $this->import_state()->current_file_bytes = null;
        }
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Download the remote index stream and write to disk.
     */
    private function download_remote_index(?string $list_dir_override = null): bool
    {
        $cursor = $this->import_state()->index->cursor;

        $roots = $this->get_root_directories_from_preflight();
        if (empty($roots)) {
            throw new RuntimeException(
                "No root directories found. Either add directory[]=... to the " .
                    "export URL, or run preflight first so directories can be auto-detected.",
            );
        }

        $mode = file_exists($this->remote_index_file) ? "a" : "w";
        // Initialize the index counter from the existing file so resume
        // shows a monotonically increasing count.
        if ($mode === "a" && $this->index_entries_counted === 0) {
            $this->index_entries_counted = $this->count_newlines($this->remote_index_file);
        }
        if ($mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->remote_index_file} | downloading fresh remote index",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->remote_index_file} | resuming remote index download",
            );
        }
        $handle = fopen($this->remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        $complete = false;
        $chunks_since_save = 0;

        $export_dirs = $this->get_export_directories();
        $params = $this->get_tuned_params("file_index");

        if ($cursor === null) {
            $start = $roots[0];
            if (!empty($this->pull_only_files_with_path_prefixes)) {
                // With --only, get_export_directories() returns only the resolved
                // file path prefixes, and those become the request's directory[]
                // allowlist. The exporter rejects list_dir unless it is inside
                // that allowlist, so $roots[0] may no longer be valid. Start from
                // the first --only file path prefix; the exporter still traverses
                // the remaining directory[] entries.
                $start = $export_dirs[0] ?? $roots[0];
            }

            $params["list_dir"] = $list_dir_override ?? $start;
        }
        if ($this->follow_symlinks) {
            $params["follow_symlinks"] = "1";
        }
        if ($this->include_caches) {
            // Server defaults to skipping caches/VCS metadata/OS junk.
            // Opt in to include them when the consumer explicitly asks.
            $params["include_caches"] = "1";
        }
        // Always send directory[] to the server when we have export dirs.
        // Without this parameter, the server falls back to ABSPATH as the
        // scan root. On managed hosts like wp.com Atomic, ABSPATH points to
        // a shared WordPress core directory (e.g. /wordpress/core/6.9.4/)
        // rather than the site's document root, so the scan would miss
        // wp-content entirely (no plugins, themes, or uploads).
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }
        $url = $this->build_url("file_index", $cursor, $params);
        $context = new StreamingContext();

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            &$chunks_since_save,
            $handle,
            $context
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $chunks_since_save++;
            if ($chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS) {
                $this->import_state()->index->cursor = $cursor;
                $this->save_state($this->state);
                $chunks_since_save = 0;
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "index_batch") {
                $body = $chunk["body"] ?? "";
                if ($body === "") {
                    return;
                }
                $items = json_decode($body, true);
                if (!is_array($items)) {
                    throw new RuntimeException(
                        "Invalid index batch JSON received from server",
                    );
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $path_encoded = $item["path"] ?? "";
                    if (!is_string($path_encoded) || $path_encoded === "") {
                        throw new RuntimeException(
                            "Invalid index batch item: missing path",
                        );
                    }
                    $path = base64_decode($path_encoded, true);
                    if ($path === "" || $path === false) {
                        throw new RuntimeException(
                            "Invalid index batch item: path base64 decode failed",
                        );
                    }
                    assert_valid_path(
                        $path,
                        "index batch path",
                    );
                    $ctime = (int) ($item["ctime"] ?? 0);
                    $size = (int) ($item["size"] ?? 0);
                    $type = (string) ($item["type"] ?? "file");

                    $entry = [
                        "path" => base64_encode($path),
                        "ctime" => $ctime,
                        "size" => $size,
                        "type" => $type,
                    ];
                    if (isset($item["target"]) && is_string($item["target"]) && $item["target"] !== "") {
                        $entry["target"] = $item["target"]; // already base64-encoded
                    }
                    if (!empty($item["intermediate"])) {
                        $entry["intermediate"] = true;
                    }
                    if (array_key_exists("empty", $item) && !is_bool($item["empty"])) {
                        throw new RuntimeException(
                            "Invalid index batch item: empty must be a boolean, received "
                            . json_encode($item["empty"]),
                        );
                    }
                    if (isset($item["empty"])) {
                        $entry["empty"] = $item["empty"];
                    }
                    $line = json_encode(
                        $entry,
                        JSON_UNESCAPED_SLASHES,
                    );
                    if ($line === false) {
                        continue;
                    }
                    $bytes = fwrite($handle, $line . "\n");
                    if ($bytes === false) {
                        throw new RuntimeException("Failed to write to remote index file (disk full?)");
                    }
                    $this->index_entries_counted++;
                }
                if ($this->index_entries_counted > 0) {
                    $this->progress->show_progress_line(
                        "Scanning remote files — " .
                        number_format($this->index_entries_counted) . " scanned"
                    );
                } else {
                    $this->progress->show_progress_line("Scanning remote files");
                }
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "index");
            } elseif ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
                $context->saw_completion = true;
                $context->response_stats = [
                    "status" => $chunk["headers"]["x-status"] ?? null,
                    "entries_processed" =>
                        isset($chunk["headers"]["x-total-entries"])
                            ? (int) $chunk["headers"]["x-total-entries"]
                            : null,
                    "server_time" =>
                        isset($chunk["headers"]["x-time-elapsed"])
                            ? (float) $chunk["headers"]["x-time-elapsed"]
                            : null,
                    "memory_used" =>
                        isset($chunk["headers"]["x-memory-used"])
                            ? (int) $chunk["headers"]["x-memory-used"]
                            : null,
                    "memory_limit" =>
                        isset($chunk["headers"]["x-memory-limit"])
                            ? (int) $chunk["headers"]["x-memory-limit"]
                            : null,
                ];
            } elseif ($chunk_type === "error") {
                $this->handle_error_chunk($chunk, "index", $context);
            }
        };

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->fetch_streaming($url, $cursor, $context, null, "file_index");
        } catch (CurlTimeoutException $e) {
            // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
            // with no progress, so we don't retry forever.
            $this->assert_can_retry_consecutive_timeout("file_index", $cursor_before, $cursor);
            fclose($handle);
            $this->import_state()->index->cursor = $cursor;
            $this->import_state()->active_resumable_command->completion_state = "partial";
            $this->save_state($this->state);
            return false;
        }
        $this->import_state()->consecutive_timeouts = 0;
        $wall_time = microtime(true) - $request_start;
        $this->finalize_tuned_request(
            "file_index",
            $wall_time,
            $context->response_stats ?? [],
        );
        fclose($handle);

        $this->import_state()->index->cursor = $complete ? null : $cursor;
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Diff local index against remote index and build download list.
     */
    private function diff_indexes_and_build_fetch_list(): bool
    {
        if (!file_exists($this->remote_index_file)) {
            throw new RuntimeException("Remote index file not found");
        }

        $diff = $this->import_state()->diff;
        $remote_offset = $diff->remote_offset;
        $local_after = $diff->local_after;
        $download_mode = $remote_offset > 0 ? "a" : "w";
        if ($download_mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->download_list_file} | building download list",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->download_list_file} | resuming download list build",
            );
        }
        $download_handle = fopen($this->download_list_file, $download_mode);
        if (!$download_handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        // When --filter=essential-files is active, uploads go to a separate
        // "skipped" list so only essential files are fetched in this run.
        $skipped_handle = null;
        $uploads_basedir = null;
        if ($this->filter === "essential-files") {
            if ($download_mode === "w") {
                $this->audit_log(
                    "FILE CREATE | {$this->skipped_download_list_file} | building skipped download list (uploads)",
                );
            } else {
                $this->audit_log(
                    "FILE APPEND | {$this->skipped_download_list_file} | resuming skipped download list build",
                );
            }
            $skipped_handle = fopen($this->skipped_download_list_file, $download_mode);
            if (!$skipped_handle) {
                fclose($download_handle);
                throw new RuntimeException("Failed to open skipped download list file");
            }
            $uploads_basedir = $this->get_uploads_basedir();
            $this->audit_log(
                "FILTER | essential-files | uploads_basedir=" . ($uploads_basedir ?? "(fallback: wp-content/uploads/)"),
            );
        }

        $remote_handle = fopen($this->remote_index_file, "r");
        if (!$remote_handle) {
            fclose($download_handle);
            throw new RuntimeException("Failed to open remote index file");
        }
        if ($remote_offset > 0) {
            fseek($remote_handle, $remote_offset);
        }

        $local_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $local = $this->read_index_line($local_handle);
        if ($local_after) {
            while (
                $local !== null &&
                strcmp($local["path"], $local_after) <= 0
            ) {
                $local = $this->read_index_line($local_handle);
            }
        }
        $this->begin_index_updates();
        $processed = 0;

        while (($line = fgets($remote_handle)) !== false) {
            if ($this->shutdown_requested) {
                break;
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $remote_offset = ftell($remote_handle);
            $remote = $this->parse_index_line($line);
            if (!$remote) {
                continue;
            }

            while (
                $local !== null &&
                strcmp($local["path"], $remote["path"]) < 0
            ) {
                // When --only file prefixes are active, only delete local files that fall under those prefixes.
                // The local files index ends up being a union across files-pull --only runs.
                if ($this->is_file_path_selected_by_pull_only_files($local["path"])) {
                    $this->delete_local_file_path($local["path"]);
                    $this->delete_index_entry($local["path"]);
                }
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            }

            if ($local !== null && $local["path"] === $remote["path"]) {
                if (
                    $local["ctime"] !== $remote["ctime"] ||
                    $local["size"] !== $remote["size"] ||
                    $local["type"] !== $remote["type"]
                ) {
                    // File is in both indexes but changed on the remote.
                    // Always re-download — this file is in our local index,
                    // meaning we synced it before; preserve-local does not
                    // protect files we own.
                    $target_handle = ($skipped_handle !== null && $this->is_uploads_path($remote["path"], $uploads_basedir))
                        ? $skipped_handle
                        : $download_handle;
                    $this->append_download_list(
                        $remote["path"],
                        $target_handle,
                    );
                }
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            } elseif (
                $local === null ||
                strcmp($local["path"], $remote["path"]) > 0
            ) {
                $skip_reason = $this->should_skip_for_preserve_local($remote["path"]);
                if ($skip_reason) {
                    $this->audit_log($skip_reason, true);
                    $this->emit_skip_progress($remote["path"]);
                } else {
                    $target_handle = ($skipped_handle !== null && $this->is_uploads_path($remote["path"], $uploads_basedir))
                        ? $skipped_handle
                        : $download_handle;
                    $this->append_download_list($remote["path"], $target_handle);
                }
            }

            $processed++;
            if ($processed % 200 === 0) {
                $this->import_state()->diff->remote_offset = $remote_offset;
                $this->import_state()->diff->local_after = $local_after;
                $this->save_state($this->state);
                $this->progress->tick_spinner();
            }
        }

        while ($local !== null) {
            if ($this->is_file_path_selected_by_pull_only_files($local["path"])) {
                $this->delete_local_file_path($local["path"]);
                $this->delete_index_entry($local["path"]);
            }
            $local_after = $local["path"];
            $local = $this->read_index_line($local_handle);
        }

        if ($local_handle) {
            fclose($local_handle);
        }
        fclose($remote_handle);
        fclose($download_handle);
        if ($skipped_handle !== null) {
            fclose($skipped_handle);
        }

        $this->import_state()->diff->remote_offset = $remote_offset;
        $this->import_state()->diff->local_after = $local_after;
        $this->save_state($this->state);

        $this->finalize_index_updates();

        return !$this->shutdown_requested;
    }

    /**
     * Download files from a prepared list.
     *
     * @param string $list_file   Path to the JSONL download list to process.
     * @param string $state_key   Key in $this->state that holds fetch progress
     *                            (e.g. "fetch" or "fetch_skipped").
     */
    /**
     * Count newlines in a file using buffered reads.  Much faster than
     * fgets() on large JSONL files because it never allocates per-line
     * strings — just scans raw bytes in 64 KB chunks.
     *
     * @param string $file       Path to the file.
     * @param int    $up_to_byte Stop after this byte offset (-1 = entire file).
     */
    private function count_newlines(string $file, int $up_to_byte = -1): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $handle = fopen($file, "r");
        if (!$handle) {
            return 0;
        }
        $count = 0;
        $chunk_size = 65536;
        $remaining = $up_to_byte >= 0 ? $up_to_byte : PHP_INT_MAX;
        while ($remaining > 0 && !feof($handle)) {
            $data = fread($handle, min($chunk_size, $remaining));
            if ($data === false || $data === '') {
                break;
            }
            $count += substr_count($data, "\n");
            $remaining -= strlen($data);
        }
        fclose($handle);
        return $count;
    }

    private function download_files_from_list(
        string $list_file,
        string $state_key
    ): bool {
        if (!file_exists($list_file)) {
            return true;
        }

        if (filesize($list_file) === 0) {
            return true;
        }

        // Compute download list counters once at the start of each list.
        // These survive across batches within one invocation and are
        // recomputed on restart from the state file's byte offset.
        if ($this->download_list_total === null) {
            $offset = $this->get_download_list_fetch_state($state_key)->offset;
            $this->download_list_total = $this->count_newlines($list_file);
            $this->download_list_done = $offset > 0
                ? $this->count_newlines($list_file, $offset)
                : 0;
        }
        $fetch_state = $this->get_download_list_fetch_state($state_key);
        $batch_file = $fetch_state->batch_file;
        $batch_offset = $fetch_state->offset;
        $next_offset = $fetch_state->next_offset;
        $cursor = $fetch_state->cursor;

        $batch_entries = $fetch_state->batch_entries;

        if ($batch_file === null || !file_exists($batch_file)) {
            $batch = $this->prepare_fetch_batch($list_file, $batch_offset);
            if ($batch === null) {
                return true;
            }
            $batch_file = $batch["file"];
            $batch_offset = $batch["offset"];
            $next_offset = $batch["next_offset"];
            $batch_entries = $batch["entries"];
            $cursor = null;
            $this->set_download_list_fetch_state($state_key, DownloadListFetchProgressState::from_array([
                "offset" => $batch_offset,
                "next_offset" => $next_offset,
                "batch_file" => $batch_file,
                "batch_entries" => $batch_entries,
                "cursor" => null,
            ]));
            $this->save_state($this->state);
        }

        $post_data = [
            "file_list" => new CURLFile(
                $batch_file,
                "application/json",
                "file-list.json",
            ),
        ];

        $complete = $this->download_file_fetch($post_data, $cursor, $state_key);
        if (!$complete) {
            return false;
        }

        if (file_exists($batch_file)) {
            @unlink($batch_file);
            $this->audit_log("FILE DELETE | {$batch_file} | fetch batch complete");
        }

        // Advance the done counter by the known batch size and reset
        // the per-batch file counter. files_imported counted files within
        // this batch; now that the batch is complete, those files are
        // accounted for in download_list_done.
        if ($this->download_list_done !== null) {
            $this->download_list_done += $batch_entries;
        }
        $this->import_state()->files_pull_summary->files_pulled += $batch_entries;
        $this->files_imported = 0;

        $this->set_download_list_fetch_state($state_key, DownloadListFetchProgressState::from_array([
            "offset" => $next_offset,
            "next_offset" => $next_offset,
            "batch_file" => null,
            "cursor" => null,
        ]));
        $this->save_state($this->state);

        return $next_offset >= filesize($list_file);
    }

    private function get_download_list_fetch_state(string $state_key): DownloadListFetchProgressState
    {
        if ($state_key === "fetch") {
            return $this->import_state()->fetch;
        }
        if ($state_key === "fetch_skipped") {
            return $this->import_state()->fetch_skipped;
        }
        throw new InvalidArgumentException("Unknown fetch state key: {$state_key}");
    }

    private function set_download_list_fetch_state(string $state_key, DownloadListFetchProgressState $state): void
    {
        if ($state_key === "fetch") {
            $this->import_state()->fetch = $state;
            return;
        }
        if ($state_key === "fetch_skipped") {
            $this->import_state()->fetch_skipped = $state;
            return;
        }
        throw new InvalidArgumentException("Unknown fetch state key: {$state_key}");
    }

    /**
     * Builds a JSON batch file listing the next set of paths to download.
     *
     * Reads from the download list (.import-download-list.jsonl) starting at
     * $offset, accumulating paths into a JSON array until the batch approaches
     * 80% of the server's max request size.  Always includes at least one path,
     * even if it alone exceeds the limit.
     *
     * The batch file is written to a temp file and intended to be uploaded as
     * the request body for the file_fetch endpoint.
     *
     * @param string $list_file Path to the JSONL download list.
     * @param int    $offset    Byte offset into the download list file.
     * @return array|null {
     *     Prepared fetch batch, or null if no paths remain.
     *
     *     @type string $file        Temporary batch file path.
     *     @type int    $offset      Byte offset where the batch began.
     *     @type int    $next_offset Byte offset for the next batch.
     *     @type int    $entries     Number of entries in the batch.
     * }
     * @phpstan-return array{file: string, offset: int, next_offset: int, entries: int}|null
     */
    private function prepare_fetch_batch(string $list_file, int $offset): ?array
    {
        // Cap the batch at 80% of the server's max request size so the
        // multipart envelope and headers still fit.  Floor at 256 KB so
        // tiny max_request values don't produce degenerate single-file batches.
        $max_request = $this->get_max_request_bytes();
        $limit = (int) max(256 * 1024, $max_request * 0.8);

        // Open the download list and seek to where the previous batch left off.
        $handle = fopen($list_file, "r");
        if (!$handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        // The output is a temp file containing a JSON array of paths, e.g.
        // ["/wp-content/uploads/photo.jpg","/wp-content/themes/flavor/style.css"]
        // This file gets uploaded as the request body for the file_fetch endpoint.
        $tmp = tempnam(sys_get_temp_dir(), "file-fetch-");
        if ($tmp === false) {
            fclose($handle);
            throw new RuntimeException("Failed to create fetch batch file");
        }
        $out = fopen($tmp, "w");
        if (!$out) {
            fclose($handle);
            @unlink($tmp);
            throw new RuntimeException("Failed to open fetch batch file");
        }

        // Read lines from the download list (one JSON entry per line) and
        // accumulate them into the JSON array until we approach the size limit.
        // The download list supports two formats:
        //   - A bare JSON string:   "/path/to/file"
        //   - A JSON object:        {"path": "<base64-encoded path>"}
        $bytes = 0;
        $entries = 0;
        $first = true;
        fwrite($out, "[");
        $bytes = 1;
        while (true) {
            // Remember where this line started so we can rewind if the
            // entry doesn't fit in the current batch.
            $line_start = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_string($decoded)) {
                $path = $decoded;
            } elseif (is_array($decoded) && isset($decoded["path"])) {
                $path = base64_decode($decoded["path"]);
            } else {
                continue;
            }
            if (!is_string($path) || $path === "") {
                continue;
            }
            $json_path = json_encode(
                $path,
                JSON_UNESCAPED_SLASHES,
            );
            if ($json_path === false) {
                continue;
            }
            $prefix = $first ? "" : ",";
            $chunk = $prefix . $json_path;
            $needed = $bytes + strlen($chunk) + 1; // +1 for closing bracket

            // Would this entry push us over the limit?
            if (!$first && $needed > $limit) {
                // Rewind to the start of this line so the next batch picks it up.
                fseek($handle, $line_start);
                break;
            }
            if ($first && $needed > $limit) {
                // Still write at least one entry even if it exceeds the limit,
                // otherwise we'd loop forever on a single long path.
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException("Failed to write fetch batch file (disk full?)");
                }
                $bytes += strlen($chunk);
                $entries++;
                $first = false;
                break;
            }

            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException("Failed to write fetch batch file (disk full?)");
            }
            $bytes += strlen($chunk);
            $entries++;
            $first = false;
        }
        fwrite($out, "]");
        $bytes += 1;

        $next_offset = ftell($handle);
        fclose($handle);
        fclose($out);

        // An empty batch (just "[]") means we've exhausted the download list.
        if ($bytes <= 2) {
            @unlink($tmp);
            return null;
        }

        return [
            "file" => $tmp,
            "offset" => $offset,
            "next_offset" => $next_offset,
            "entries" => $entries,
        ];
    }

    /**
     * Determine maximum request size for file_fetch uploads.
     */
    private function get_max_request_bytes(): int
    {
        $preflight = $this->import_state()->preflight["data"]["limits"] ?? null;
        $max_request = null;
        if (is_array($preflight) && isset($preflight["max_request_bytes"])) {
            $max_request = (int) $preflight["max_request_bytes"];
        }

        if ($max_request === null || $max_request <= 0) {
            return 4 * 1024 * 1024;
        }

        return $max_request;
    }

    /**
     * Return the uploads basedir from preflight data (e.g. "/wp-content/uploads").
     *
     * Falls back to a heuristic pattern match if the preflight doesn't contain
     * explicit uploads path information.
     */
    private function get_uploads_basedir(): ?string
    {
        $paths_urls = $this->import_state()->preflight["data"]["database"]["wp"]["paths_urls"] ?? null;
        if (!is_array($paths_urls)) {
            return null;
        }
        $basedir = $paths_urls["uploads"]["basedir"] ?? null;
        if (!is_string($basedir) || $basedir === "") {
            return null;
        }
        return rtrim($basedir, "/") . "/";
    }

    /**
     * Check whether a remote path belongs to the uploads directory.
     *
     * Uses the preflight-reported uploads basedir when available, otherwise
     * falls back to matching "wp-content/uploads/" anywhere in the path.
     */
    private function is_uploads_path(string $path, ?string $uploads_basedir): bool
    {
        if ($uploads_basedir !== null) {
            return strpos($path, $uploads_basedir) !== false;
        }
        // Fallback: match the conventional WordPress uploads path
        return strpos($path, "wp-content/uploads/") !== false;
    }

    /**
     * Append a path to the download list file.
     */
    private function append_download_list(string $path, $handle): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            fwrite($handle, $line . "\n");
        }
        $this->audit_log("Added to the download list: {$path}", false);
    }

    /**
     * Delete a local file path safely under the fs root.
     */
    private function delete_local_file_path(string $path): void
    {
        if ($path === "") {
            return;
        }
        try {
            $local_path = $this->remote_path_to_local_path_within_import_root($path);
        } catch (RuntimeException $e) {
            $this->audit_log(
                "Security: refusing to delete invalid path '{$path}': " . $e->getMessage(),
                true,
            );
            return;
        }
        if (!file_exists($local_path) && !is_link($local_path)) {
            return;
        }

        if ($this->remove_local_path_without_following_symlinks($local_path)) {
            $this->audit_log("Deleted: {$path}", false);
            return;
        }

        $this->audit_log("Failed to delete: {$path}", true);
    }

    /**
     * Remove a local path recursively without traversing symlink targets.
     *
     * Symlinks are always unlinked as links. Directories are traversed
     * depth-first.
     */
    private function remove_local_path_without_following_symlinks(
        string $local_path
    ): bool {
        if (!file_exists($local_path) && !is_link($local_path)) {
            return true;
        }

        if (is_link($local_path) || is_file($local_path)) {
            return true === @unlink($local_path);
        }

        if (is_dir($local_path)) {
            $entries = @scandir($local_path);
            if ($entries === false) {
                return false;
            }
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                if (
                    !$this->remove_local_path_without_following_symlinks(
                        $local_path . "/" . $entry
                    )
                ) {
                    return false;
                }
            }
            return true === @rmdir($local_path);
        }

        return true === @unlink($local_path);
    }

    /**
     * Parse one JSON index line into an array.
     */
    private function parse_index_line(string $line): ?array
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid index line format");
        }
        $path_encoded = $data["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            throw new RuntimeException("Invalid index path");
        }
        $path = base64_decode($path_encoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException("Invalid index path (base64 decode failed)");
        }
        assert_valid_path($path, "index path");
        return [
            "path" => $path,
            "ctime" => (int) ($data["ctime"] ?? 0),
            "size" => (int) ($data["size"] ?? 0),
            "type" => (string) ($data["type"] ?? "file"),
        ];
    }

    /**
     * Start collecting index updates into a temp file for streaming merge.
     */
    private function begin_index_updates(): void
    {
        if ($this->index_updates_handle) {
            return;
        }
        $is_new = false;
        if ($this->index_updates_file === null) {
            $tmp = tempnam(sys_get_temp_dir(), "index-updates-");
            if ($tmp === false) {
                throw new RuntimeException(
                    "Failed to create temp index updates file",
                );
            }
            $this->index_updates_file = $tmp;
            $is_new = true;
        } elseif (!file_exists($this->index_updates_file)) {
            $dir = dirname($this->index_updates_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $is_new = true;
        }
        $this->index_updates_handle = fopen($this->index_updates_file, "a");
        if (!$this->index_updates_handle) {
            throw new RuntimeException(
                "Failed to open temp index updates file",
            );
        }
        if ($is_new) {
            $this->audit_log(
                "FILE CREATE | {$this->index_updates_file} | index updates buffer",
            );
        }
        $this->index_updates_count = 0;
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    /**
     * Record a file upsert into the index updates stream.
     */
    private function record_index_update_file(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === false &&
            $this->last_update_ctime === $ctime &&
            $this->last_update_size === $size &&
            $this->last_update_type === $type
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "F",
                "path" => base64_encode($path),
                "ctime" => $ctime,
                "size" => $size,
                "type" => $type,
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->index_updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = false;
        $this->last_update_ctime = $ctime;
        $this->last_update_size = $size;
        $this->last_update_type = $type;
    }

    /**
     * Record a deletion into the index updates stream.
     */
    private function record_index_update_deletion(string $path): void
    {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === true
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "D",
                "path" => base64_encode($path),
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->index_updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = true;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    /**
     * Merge the collected updates with the existing sorted index without loading it into memory.
     */
    private function finalize_index_updates(): void
    {
        if ($this->index_updates_handle) {
            fclose($this->index_updates_handle);
            $this->index_updates_handle = null;
        }
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;

        $has_updates =
            $this->index_updates_count > 0 ||
            ($this->index_updates_file &&
                file_exists($this->index_updates_file) &&
                filesize($this->index_updates_file) > 0);

        if (!$has_updates) {
            if (
                $this->index_updates_file &&
                file_exists($this->index_updates_file)
            ) {
                @unlink($this->index_updates_file);
                $this->audit_log(
                    "FILE DELETE | {$this->index_updates_file} | no updates to merge",
                );
            }
            $this->index_updates_count = 0;
            return;
        }

        $updates_path = $this->index_updates_file;
        $new_index = $this->index_file . ".new";

        $this->audit_log(
            "INDEX MERGE START | merging updates into {$this->index_file}",
        );

        $old_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $upd_handle = fopen($updates_path, "r");
        $new_handle = fopen($new_index, "w");

        if (!$upd_handle || !$new_handle) {
            throw new RuntimeException("Failed to merge index updates");
        }

        $write_line = function ($handle, array $entry): void {
            $line = json_encode(
                [
                    "path" => base64_encode($entry["path"]),
                    "ctime" => (int) $entry["ctime"],
                    "size" => (int) $entry["size"],
                    "type" => (string) $entry["type"],
                ],
                JSON_UNESCAPED_SLASHES,
            );
            if ($line !== false) {
                fwrite($handle, $line . "\n");
            }
        };

        $old = $this->read_index_line($old_handle);
        $carry = null;
        $upd = $this->read_update_line($upd_handle, $carry);
        $last_written_path = null;

        while ($old !== null || $upd !== null) {
            if ($upd === null) {
                if ($last_written_path !== $old["path"]) {
                    $write_line($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
                continue;
            }

            if ($old === null) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
                continue;
            }

            $cmp = strcmp($old["path"], $upd["path"]);
            if ($cmp === 0) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $old = $this->read_index_line($old_handle);
                $upd = $this->read_update_line($upd_handle, $carry);
            } elseif ($cmp < 0) {
                if ($last_written_path !== $old["path"]) {
                    $write_line($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
            } else {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
            }
        }

        if ($old_handle) {
            fclose($old_handle);
        }
        fclose($upd_handle);
        fclose($new_handle);

        if (!rename($new_index, $this->index_file)) {
            throw new RuntimeException("Failed to replace index file");
        }
        $this->audit_log("INDEX MERGE COMPLETE | {$this->index_file} updated");

        @unlink($updates_path);
        $this->audit_log("FILE DELETE | {$updates_path} | updates merged");
        $this->index_updates_count = 0;
    }

    /**
     * Read one JSON record from the on-disk index.
     */
    private function read_index_line($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $parsed = $this->parse_index_line($line);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * Read one raw update record (F/D) from the updates file.
     */
    private function read_update_line_raw($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                throw new RuntimeException("Invalid index update line format");
            }
            $op = $data["op"] ?? null;
            $path_encoded = $data["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                throw new RuntimeException("Invalid index update path");
            }
            $path = base64_decode($path_encoded);
            if ($path === false || $path === "") {
                throw new RuntimeException("Invalid index update path (base64 decode failed)");
            }
            if ($op === "D") {
                return [
                    "path" => $path,
                    "delete" => true,
                    "ctime" => 0,
                    "size" => 0,
                    "type" => null,
                ];
            }
            if ($op === "F") {
                return [
                    "path" => $path,
                    "delete" => false,
                    "ctime" => (int) ($data["ctime"] ?? 0),
                    "size" => (int) ($data["size"] ?? 0),
                    "type" => (string) ($data["type"] ?? "file"),
                ];
            }
        }
        return null;
    }

    /**
     * Read one update record, coalescing consecutive updates to the same path.
     *
     * @param mixed $handle Update file handle
     * @param array|null $carry Read-ahead buffer for the next record
     */
    private function read_update_line($handle, ?array &$carry = null): ?array
    {
        if (!$handle) {
            return null;
        }
        $current = $carry ?? $this->read_update_line_raw($handle);
        $carry = null;
        if ($current === null) {
            return null;
        }

        while (true) {
            $next = $this->read_update_line_raw($handle);
            if ($next === null) {
                return $current;
            }
            if ($next["path"] !== $current["path"]) {
                $carry = $next;
                return $current;
            }
            // Same path: keep the latest update.
            $current = $next;
        }
    }
    /**
     * Download SQL from remote.
     */
    private function download_sql(): void
    {
        $cursor = $this->import_state()->active_resumable_command->remote_cursor ?? null;
        $complete = false;
        $mode = $this->sql_output_mode;

        // ── Set up write strategy based on output mode ──────────────

        $sql_handle = null;
        $mysql_conn = null;
        $buffer_handle = null;
        $sql_bytes_written = 0;
        $sql_buffer = "";

        if ($mode === "file") {
            $sql_file = $this->state_dir . "/db.sql";

            // Crash recovery: if SQL file is larger than expected, truncate it.
            // This happens if we crashed after writing but before saving the new cursor.
            $tracked_bytes = $this->import_state()->sql_bytes ?? null;
            if ($tracked_bytes !== null && file_exists($sql_file)) {
                $actual_size = filesize($sql_file);
                if ($actual_size > $tracked_bytes) {
                    $this->audit_log(
                        sprintf(
                            "CRASH RECOVERY | Truncating db.sql from %d to %d bytes",
                            $actual_size,
                            $tracked_bytes,
                        ),
                        true,
                    );
                    $handle = fopen($sql_file, "r+");
                    if ($handle) {
                        ftruncate($handle, $tracked_bytes);
                        fclose($handle);
                    }
                }
            }

            $sql_bytes_written = file_exists($sql_file) ? filesize($sql_file) : 0;

            // Open in write mode if no cursor (starting fresh), append mode if resuming
            $sql_handle = fopen($sql_file, $cursor ? "a" : "w");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }

        } elseif ($mode === "stdout") {
            $sql_bytes_written = $this->import_state()->sql_bytes ?? 0;

        } elseif ($mode === "mysql") {
            $sql_bytes_written = $this->import_state()->sql_bytes ?? 0;

            $host = $this->mysql_host ?? "127.0.0.1";
            $user = $this->mysql_user ?? "root";
            $pass = $this->mysql_password ?? "";
            $name = $this->mysql_database;

            // Parse host for port/socket (same format as WordPress DB_HOST).
            // An explicit --mysql-port takes precedence over a port embedded
            // in the host string.
            $port = $this->mysql_port ?? 3306;
            $socket = null;
            if (strpos($host, ":") !== false) {
                list($host, $port_or_socket) = explode(":", $host, 2);
                if ($port_or_socket[0] === "/") {
                    $socket = $port_or_socket;
                } elseif ($this->mysql_port === null) {
                    $port = (int) $port_or_socket;
                }
            }

            $mysql_conn = new \mysqli($host, $user, $pass, $name, $port, $socket);
            if ($mysql_conn->connect_error) {
                throw new RuntimeException("MySQL connection failed: " . $mysql_conn->connect_error);
            }
            $mysql_conn->set_charset("utf8mb4");

            $this->audit_log(
                "SQL OUTPUT mysql | connected via multi_query(): {$user}@{$host}:{$port}/{$name}",
                true,
            );

            // Open a persistent buffer file so partial queries survive crashes.
            // Each SQL chunk is appended to this file as it arrives; when the
            // query completes and executes, the file is truncated. If the process
            // dies at any point, the next run reloads whatever was accumulated.
            $buffer_file = $this->state_dir . "/.sql-buffer";
            if (file_exists($buffer_file)) {
                $sql_buffer = file_get_contents($buffer_file);
                $this->audit_log(
                    sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($sql_buffer)),
                    true,
                );
            }
            // Open in write mode (truncate) if we loaded nothing, append if we
            // have a partial query to continue accumulating into.
            $buffer_handle = fopen($buffer_file, $sql_buffer !== "" ? "a" : "w");
            if (!$buffer_handle) {
                throw new RuntimeException("Cannot open SQL buffer file: {$buffer_file}");
            }
        }

        // Domain discovery and statement counting: scan SQL for URLs during download
        $query_stream = class_exists('WP_MySQL_Naive_Query_Stream')
            ? new \WP_MySQL_Naive_Query_Stream()
            : null;
        $domain_collector = class_exists('DomainCollector')
            ? new \DomainCollector()
            : null;
        $domains_file = $this->state_dir . "/.import-domains.json";
        $sql_stats_file = $this->state_dir . "/.import-sql-stats.json";
        $sql_statements_counted = (int) ($this->import_state()->sql_statements_counted ?? 0);

        // Auto-detect the source site domain from the export URL so it
        // always appears in .import-domains.json even if the SQL dump
        // hasn't been fully scanned yet.
        if ($domain_collector) {
            $parsed_url = parse_url($this->remote_url);
            if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
                $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                if (!empty($parsed_url['port'])) {
                    $source_origin .= ':' . $parsed_url['port'];
                }
                $domain_collector->merge([$source_origin]);
            }
        }

        // Load previously discovered domains (from earlier partial downloads)
        if ($domain_collector && file_exists($domains_file)) {
            $prev = json_decode(file_get_contents($domains_file), true);
            if (is_array($prev)) {
                $domain_collector->merge($prev);
            }
        }

        // Log current progress at start of request
        $has_cursor = $cursor !== null;
        $this->audit_log(
            sprintf(
                "START SQL REQUEST | mode=%s | cursor=%s | bytes_written=%s",
                $mode,
                $has_cursor ? "YES" : "NO",
                number_format($sql_bytes_written) . " bytes",
            ),
            false,
        );

        $curl_timed_out = false;
        $caught_exception = null;
        $buffer_not_flushed = "";
        $chunks_since_save = 0;
        try {
            while (!$complete) {
                $params = $this->get_tuned_params("sql_chunk");
                $url = $this->build_url("sql_chunk", $cursor, $params);

                $context = new StreamingContext();
                $context->chunk_fingerprints = [];
                $context->on_chunk = function ($chunk) use (
                    $mode,
                    &$cursor,
                    &$complete,
                    &$sql_handle,
                    $mysql_conn,
                    &$buffer_handle,
                    &$sql_buffer,
                    &$sql_bytes_written,
                    $context,
                    $query_stream,
                    $domain_collector,
                    $domains_file,
                    &$sql_statements_counted,
                    &$chunks_since_save
                ) {
                    // Check if shutdown was requested
                    if ($this->shutdown_requested) {
                        throw new RuntimeException("Shutdown requested");
                    }

                    // Allow signal handlers to run
                    if (function_exists("pcntl_signal_dispatch")) {
                        pcntl_signal_dispatch();
                    }

                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    // Save cursor periodically (every 50 chunks).
                    // Skip saving when there's buffered SQL waiting for a
                    // complete statement — crash recovery would replay the
                    // cursor but miss the buffered bytes.
                    $chunks_since_save++;
                    if (
                        $chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS
                        && $sql_buffer === ""
                    ) {
                        if ($sql_handle) {
                            fflush($sql_handle);
                        }
                        $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                        $this->import_state()->sql_bytes = $sql_bytes_written;
                        $this->import_state()->sql_statements_counted = $sql_statements_counted;
                        $this->save_state($this->state);
                        $chunks_since_save = 0;

                        // Also persist discovered domains so they survive crashes.
                        // On resume, the SQL download picks up from the cursor,
                        // skipping already-downloaded data — so domains from that
                        // earlier data would be lost without periodic saves.
                        if ($domain_collector) {
                            $domains = $domain_collector->get_domains();
                            if (!empty($domains)) {
                                file_put_contents(
                                    $domains_file,
                                    json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                                );
                            }
                        }
                    }

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                    if ($chunk_type === "sql") {
                        $query_complete = ($chunk["headers"]["x-query-complete"] ?? "1") === "1";
                        $data = $chunk["body"];

                        switch ($mode) {
                            case "file":
                                $bytes = fwrite($sql_handle, $data);
                                if ($bytes === false || $bytes !== strlen($data)) {
                                    throw new RuntimeException(
                                        "SQL write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                                        "/" . strlen($data) . " bytes (disk full?)"
                                    );
                                }
                                $sql_bytes_written += $bytes;
                                break;

                            case "stdout":
                                $bytes = @fwrite(STDOUT, $data);
                                if ($bytes === false) {
                                    // Broken pipe — save state and exit cleanly so the
                                    // pipe reader (e.g. `mysql`) can finish on its own.
                                    $this->save_state($this->state);
                                    exit(0);
                                }
                                $sql_bytes_written += $bytes;
                                break;

                            case "mysql":
                                // Append to disk immediately so the buffer survives
                                // even if the process is killed mid-chunk.
                                if ($buffer_handle) {
                                    fwrite($buffer_handle, $data);
                                    fflush($buffer_handle);
                                }

                                $sql_buffer .= $data;
                                $sql_bytes_written += strlen($data);

                                if ($query_complete) {
                                    if (!$mysql_conn->multi_query($sql_buffer)) {
                                        throw new RuntimeException("MySQL execution failed: " . $mysql_conn->error);
                                    }
                                    // Drain all result sets from multi_query before sending the
                                    // next chunk — mysqli requires this.
                                    do {
                                        $result = $mysql_conn->store_result();
                                        if ($result) { $result->free(); }
                                        if ($mysql_conn->errno) {
                                            throw new RuntimeException("MySQL statement error: " . $mysql_conn->error);
                                        }
                                    } while ($mysql_conn->more_results() && $mysql_conn->next_result());

                                    // Query executed — truncate the buffer file and reset.
                                    if ($buffer_handle) {
                                        ftruncate($buffer_handle, 0);
                                        rewind($buffer_handle);
                                    }
                                    $sql_buffer = "";
                                }
                                break;
                        }

                        // Feed data to query stream for domain discovery and statement counting
                        if ($query_stream && $domain_collector) {
                            $query_stream->append_sql($data);
                            $this->drain_query_stream_for_domains(
                                $query_stream,
                                $domain_collector,
                                $sql_statements_counted,
                            );
                        }
                        // Show download progress on the TTY progress line.
                        // The bytes accumulate across chunks and requests.
                        // Include estimated total from db-index when available,
                        // but only if the estimate is larger than what we've
                        // already downloaded — INFORMATION_SCHEMA estimates
                        // can be wildly off (e.g. 7 KB for a 22 MB dump).
                        $db_bytes_est = (int) ($this->import_state()->db_index->bytes ?? 0);
                        $est_is_useful = $db_bytes_est > $sql_bytes_written;
                        $sql_fraction = $est_is_useful
                            ? $sql_bytes_written / $db_bytes_est
                            : null;
                        $sql_progress = $this->format_bytes($sql_bytes_written);
                        if ($est_is_useful) {
                            $sql_progress .= " / " . $this->format_bytes($db_bytes_est);
                        }
                        $this->progress->show_progress_line($sql_progress, $sql_fraction);

                    } elseif ($chunk_type === "progress") {
                        $this->handle_progress($chunk, "sql");
                    } elseif ($chunk_type === "completion") {
                        $complete =
                            ($chunk["headers"]["x-status"] ?? "") ===
                            "complete";
                        $context->saw_completion = true;
                        $context->response_stats = [
                            "status" => $chunk["headers"]["x-status"] ?? null,
                            "sql_bytes" =>
                                isset($chunk["headers"]["x-sql-bytes"])
                                    ? (int) $chunk["headers"]["x-sql-bytes"]
                                    : null,
                            "server_time" =>
                                isset($chunk["headers"]["x-time-elapsed"])
                                    ? (float) $chunk["headers"]["x-time-elapsed"]
                                    : null,
                            "memory_used" =>
                                isset($chunk["headers"]["x-memory-used"])
                                    ? (int) $chunk["headers"]["x-memory-used"]
                                    : null,
                            "memory_limit" =>
                                isset($chunk["headers"]["x-memory-limit"])
                                    ? (int) $chunk["headers"]["x-memory-limit"]
                                    : null,
                        ];
                        $this->output_progress(
                            [
                                "phase" => "sql",
                                "status" =>
                                    $chunk["headers"]["x-status"] ?? "unknown",
                                "batches_processed" =>
                                    (int) ($chunk["headers"][
                                        "x-batches-processed"
                                    ] ?? 0),
                            ],
                            true,
                        );
                    } elseif ($chunk_type === "error") {
                        $this->handle_error_chunk($chunk, "db-index", $context);
                    }
                };

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->fetch_streaming($url, $cursor, $context, null, "sql_chunk");
                } catch (CurlTimeoutException $e) {
                    // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
                    // with no progress, so we don't retry forever.
                    $this->assert_can_retry_consecutive_timeout("sql_chunk", $cursor_before, $cursor);
                    // Save state so the next invocation resumes from the
                    // last cursor instead of crashing with exit code 1.
                    if ($sql_handle) {
                        fflush($sql_handle);
                    }
                    $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                    $this->import_state()->sql_bytes = $sql_bytes_written;
                    $this->import_state()->sql_statements_counted = $sql_statements_counted;
                    $this->import_state()->active_resumable_command->completion_state = "partial";
                    $this->save_state($this->state);
                    // Discard any pending SQL buffer — it's incomplete and
                    // will be re-fetched on the next invocation. Setting
                    // this to "" also prevents the finally block from
                    // throwing about un-executed buffered SQL.
                    $sql_buffer = "";
                    $curl_timed_out = true;
                    break;
                } catch (RuntimeException $e) {
                    // The server may crash mid-response (max_execution_time,
                    // OOM, fatal error). This surfaces as either:
                    //  - "missing completion chunk" (response ended without it)
                    //  - cURL error 18/52/56 (partial transfer / recv error)
                    //  - "missing multipart boundary" (proxy error page)
                    // Treat these as a retryable partial response: save state
                    // so the next invocation resumes from the cursor. Unlike
                    // a timeout (where the buffer is discarded and re-fetched),
                    // we keep $sql_buffer intact here so the .sql-buffer file
                    // is preserved — the next run reloads it and continues
                    // accumulating from where the server left off.
                    $msg = $e->getMessage();
                    // Only retry connection-level curl errors that indicate
                    // the server crashed or the connection was interrupted.
                    // Do NOT retry content-encoding errors (e.g. gzip
                    // corruption, CURLE_BAD_CONTENT_ENCODING=61) — those
                    // will fail identically on every retry.
                    //   18 = CURLE_PARTIAL_FILE (transfer closed mid-stream)
                    //   52 = CURLE_GOT_NOTHING (empty response)
                    //   56 = CURLE_RECV_ERROR (connection reset / recv failure)
                    $is_retryable_curl = preg_match(
                        '/cURL error \((\d+)\):/', $msg, $curl_match
                    ) && in_array((int) $curl_match[1], [18, 52, 56]);
                    $is_retryable =
                        strpos($msg, "missing completion chunk") !== false ||
                        $is_retryable_curl ||
                        strpos($msg, "missing multipart boundary") !== false;
                    if ($is_retryable) {
                        $this->audit_log(
                            "INCOMPLETE RESPONSE | " . $msg .
                            " | buffered_sql=" . strlen($sql_buffer) . " bytes" .
                            " — will save state for retry",
                            true,
                        );
                        $this->assert_can_retry_consecutive_timeout("sql_chunk", $cursor_before, $cursor);
                        if ($sql_handle) {
                            fflush($sql_handle);
                        }
                        $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                        $this->import_state()->sql_bytes = $sql_bytes_written;
                        $this->import_state()->sql_statements_counted = $sql_statements_counted;
                        $this->import_state()->active_resumable_command->completion_state = "partial";
                        $this->save_state($this->state);
                        $curl_timed_out = true;
                        break;
                    }
                    throw $e;
                }
                $this->import_state()->consecutive_timeouts = 0;
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "sql_chunk",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                // Save cursor for resumption (keep it even when complete for reference)
                if ($sql_handle) {
                    fflush($sql_handle);
                }

                $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                // Clear sql_bytes when complete, otherwise save current position
                $this->import_state()->sql_bytes = $complete ? null : $sql_bytes_written;
                $this->save_state($this->state);
            }

            // Drain any remaining statements after download completes
            if ($query_stream && $domain_collector) {
                $query_stream->mark_input_complete();
                $this->drain_query_stream_for_domains(
                    $query_stream,
                    $domain_collector,
                    $sql_statements_counted,
                );

                // Save discovered domains
                $domains = $domain_collector->get_domains();
                if (!empty($domains)) {
                    file_put_contents(
                        $domains_file,
                        json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                    );
                    $this->audit_log(
                        sprintf(
                            "DOMAINS DISCOVERED | %d unique domains saved to .import-domains.json",
                            count($domains),
                        ),
                        false,
                    );
                }

                // Save statement count for db-apply progress reporting
                if ($sql_statements_counted > 0) {
                    file_put_contents(
                        $sql_stats_file,
                        json_encode(["statements_total" => $sql_statements_counted]) . "\n",
                    );
                    $this->audit_log(
                        sprintf(
                            "SQL STATS | %d statements counted during download",
                            $sql_statements_counted,
                        ),
                        false,
                    );
                }
            }
        } catch (\Throwable $e) {
            $caught_exception = $e;
            throw $e;
        } finally {
            if ($sql_handle) {
                fclose($sql_handle);
            }
            if ($buffer_handle) {
                fclose($buffer_handle);
                $buffer_handle = null;
            }
            if ($mysql_conn) {
                $pending = $sql_buffer;
                $mysql_conn->close();
                $mysql_conn = null;
                // Clean up buffer file — if we got here with an empty buffer,
                // all queries were executed successfully.
                $buffer_file = $this->state_dir . "/.sql-buffer";
                if ($pending === "" && file_exists($buffer_file)) {
                    unlink($buffer_file);
                }
                if ($pending !== "") {
                    if ($caught_exception !== null) {
                        // An exception is already in flight (e.g. curl error,
                        // MySQL error). Don't mask it by throwing about the
                        // buffer — the buffer data is safely persisted in
                        // .sql-buffer and will be recovered on the next run.
                        $this->audit_log(
                            "BUFFER NOT FLUSHED | " . strlen($pending) .
                            " bytes in SQL buffer during exception unwind" .
                            " (original error: " . $caught_exception->getMessage() . ")",
                            true,
                        );
                    } elseif ($curl_timed_out) {
                        // Crash recovery — the buffer file is preserved on
                        // disk so the next invocation reloads it and continues
                        // accumulating from where the server left off.
                        $this->audit_log(
                            "BUFFER PRESERVED | " . strlen($pending) .
                            " bytes in SQL buffer saved for crash recovery",
                            true,
                        );
                    } else {
                        $buffer_not_flushed = $pending;
                    }
                }
            }
        }

        if ($buffer_not_flushed !== "") {
            throw new RuntimeException(
                "Buffered SQL was never executed (" . strlen($buffer_not_flushed) .
                " bytes) — incomplete export?"
            );
        }

        if ($curl_timed_out) {
            return;
        }
    }

    /**
     * Drain complete SQL statements from a query stream and scan their
     * base64-decoded values for URL domains.
     */
    private function drain_query_stream_for_domains(
        \WP_MySQL_Naive_Query_Stream $query_stream,
        \DomainCollector $domain_collector,
        ?int &$statements_counted = null
    ) {
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            if ($statements_counted !== null) {
                $statements_counted++;
            }
            // Only scan INSERT statements (they contain data values).
            if (!self::sql_starts_with_token($query, \WP_MySQL_Lexer::INSERT_SYMBOL)) {
                continue;
            }
            // Only scan statements with base64 values
            if (strpos($query, "FROM_BASE64(") === false) {
                continue;
            }

            $table = self::extract_insert_table($query);
            $is_options_table = substr($table, -8) === '_options';

            $scanner = new \Base64ValueScanner($query);
            while ($scanner->next_value()) {
                // For _options tables, extract the option_name (second column)
                // and skip transients — they contain ephemeral cached data
                // that would pollute the domain list.
                $option_name = null;
                $match_offset = $scanner->get_match_offset();
                if ($is_options_table) {
                    $option_name = self::extract_option_name($query, $match_offset);
                    if ($option_name !== null && (
                        strpos($option_name, '_transient') === 0 ||
                        strpos($option_name, '_site_transient') === 0
                    )) {
                        continue;
                    }
                }

                $new_domains = $domain_collector->scan($scanner->get_value());
                if (!empty($new_domains)) {
                    $row_id = self::extract_row_identifier($query, $match_offset);

                    $option_ctx = '';
                    if ($option_name !== null) {
                        $option_ctx = ' option=' . $option_name;
                    }

                    foreach ($new_domains as $domain) {
                        $this->audit_log(
                            sprintf(
                                "NEW DOMAIN | %s | table=%s %s%s",
                                $domain,
                                $table,
                                $row_id,
                                $option_ctx,
                            ),
                            false,
                        );
                    }
                }
            }
        }
    }

    /**
     * Extract the table name from an INSERT INTO statement.
     */
    private static function extract_insert_table(string $query): string
    {
        if (preg_match('/INSERT\s+INTO\s+`([^`]+)`/i', $query, $m)) {
            return $m[1];
        }
        return '?';
    }

    /**
     * Extract a row identifier (PK value or offset) from the INSERT row
     * containing the base64 expression at $offset.
     *
     * Scans backwards from $offset to find the row-opening parenthesis,
     * then reads the first column value — typically the primary key.
     */
    private static function extract_row_identifier(string $query, int $offset): string
    {
        // Walk backwards from the match to find the row-opening '('.
        // Track parenthesis depth so we skip inner '(' from FROM_BASE64()
        // and CONVERT() wrappers.
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return 'offset=?';
        }

        // Read the first value after the row-opening '('.
        // Numeric PKs: (123, ...  or (-5, ...
        $after = substr($query, $row_start, 40);
        if (preg_match('/^(-?\d+)/', $after, $m)) {
            return 'pk=' . $m[1];
        }
        // String PKs: ('some-uuid', ...
        if (preg_match("/^'([^']{0,30})'/", $after, $m)) {
            return "pk=" . $m[1];
        }
        if (preg_match('/^NULL/i', $after)) {
            return 'pk=NULL';
        }

        return 'offset=?';
    }

    /**
     * Extract the option_name (second column) from a wp_options INSERT row.
     *
     * WordPress options tables have columns: option_id, option_name, option_value, autoload.
     * Given an offset inside the row, this finds the row-opening '(' and reads
     * past the first column (option_id) to extract the second column (option_name).
     */
    private static function extract_option_name(string $query, int $offset): ?string
    {
        // Find the row-opening '(' by walking backwards, same as extract_row_identifier.
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return null;
        }

        // Skip the first column value (option_id) and the comma separator,
        // then read the second column value (option_name) which is a quoted string.
        $after = substr($query, $row_start, 200);
        // First column is typically a number: "123," or could be FROM_BASE64(...)
        // Skip to the first comma that's outside parentheses.
        $len = strlen($after);
        $d = 0;
        $comma_pos = -1;
        for ($j = 0; $j < $len; $j++) {
            $c = $after[$j];
            if ($c === '(') { $d++; }
            elseif ($c === ')') { $d--; }
            elseif ($c === ',' && $d === 0) {
                $comma_pos = $j;
                break;
            }
        }

        if ($comma_pos < 0) {
            return null;
        }

        // After the comma, skip whitespace and read a quoted string or FROM_BASE64(...)
        $rest = ltrim(substr($after, $comma_pos + 1));
        // Simple quoted string: 'option_name'
        if (isset($rest[0]) && $rest[0] === "'") {
            if (preg_match("/^'([^']{0,80})'/", $rest, $m)) {
                return $m[1];
            }
        }
        // FROM_BASE64('...') wrapped value — decode it
        if (strpos($rest, 'FROM_BASE64(') === 0) {
            if (preg_match("/^FROM_BASE64\\('([A-Za-z0-9+\\/=]+)'\\)/", $rest, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    return substr($decoded, 0, 80);
                }
            }
        }

        return null;
    }

    /**
     * Check whether a SQL statement's first keyword token matches a given token ID.
     * Skips leading whitespace and comments, so "/* ... *​/ INSERT INTO ..." is handled.
     */
    private static function sql_starts_with_token(string $sql, int $expected_token_id): bool
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        while ($lexer->next_token()) {
            $token = $lexer->get_token();
            if (
                $token->id === \WP_MySQL_Lexer::WHITESPACE
                || $token->id === \WP_MySQL_Lexer::COMMENT
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_END
            ) {
                continue;
            }
            return $token->id === $expected_token_id;
        }
        return false;
    }

    /**
     * Download table stats from the db_index endpoint.
     */
    private function download_db_index(): void
    {
        $cursor = $this->import_state()->active_resumable_command->remote_cursor ?? null;
        $complete = false;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $stats = $this->import_state()->db_index;
        $tables_written = $stats->tables;
        $rows_estimated = $stats->rows_estimated;
        $bytes_written = $stats->bytes;

        if ($bytes_written > 0 && file_exists($tables_file)) {
            $actual_size = filesize($tables_file);
            if ($actual_size > $bytes_written) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating db-tables.jsonl from %d to %d bytes",
                        $actual_size,
                        $bytes_written,
                    ),
                    true,
                );
                $handle = fopen($tables_file, "r+");
                if ($handle) {
                    ftruncate($handle, $bytes_written);
                    fclose($handle);
                }
            }
        }

        $handle = fopen($tables_file, $cursor ? "a" : "w");
        if (!$handle) {
            throw new RuntimeException("Cannot open table stats file: {$tables_file}");
        }

        try {
            while (!$complete) {
                $params = [
                    "tables_per_batch" => 1000,
                ];
                $url = $this->build_url("db_index", $cursor, $params);

                $context = new StreamingContext();
                $context->on_chunk = function ($chunk) use (
                    &$cursor,
                    &$complete,
                    &$tables_written,
                    &$rows_estimated,
                    &$bytes_written,
                    $handle,
                    $context
                ) {
                    if ($this->shutdown_requested) {
                        throw new RuntimeException("Shutdown requested");
                    }
                    if (function_exists("pcntl_signal_dispatch")) {
                        pcntl_signal_dispatch();
                    }

                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";
                    if ($chunk_type === "table_stats") {
                        $data = json_decode($chunk["body"], true);
                        if (is_array($data)) {
                            foreach ($data as $row) {
                                $line = json_encode($row) . "\n";
                                $bytes = fwrite($handle, $line);
                                if ($bytes === false || $bytes !== strlen($line)) {
                                    throw new RuntimeException(
                                        "Table stats write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                                        "/" . strlen($line) . " bytes (disk full?)"
                                    );
                                }
                                $bytes_written += $bytes;
                                $tables_written++;
                                if (
                                    isset($row["rows"]) &&
                                    is_numeric($row["rows"])
                                ) {
                                    $rows_estimated += (int) $row["rows"];
                                }
                            }
                        }
                    } elseif ($chunk_type === "progress") {
                        $this->handle_progress($chunk, "db-index");
                    } elseif ($chunk_type === "completion") {
                        $complete =
                            ($chunk["headers"]["x-status"] ?? "") ===
                            "complete";
                        $context->saw_completion = true;
                        $context->response_stats = [
                            "status" => $chunk["headers"]["x-status"] ?? null,
                            "tables_processed" =>
                                isset($chunk["headers"]["x-tables-processed"])
                                    ? (int) $chunk["headers"]["x-tables-processed"]
                                    : null,
                            "rows_estimated" =>
                                isset($chunk["headers"]["x-rows-estimated"])
                                    ? (int) $chunk["headers"]["x-rows-estimated"]
                                    : null,
                            "server_time" =>
                                isset($chunk["headers"]["x-time-elapsed"])
                                    ? (float) $chunk["headers"]["x-time-elapsed"]
                                    : null,
                            "memory_used" =>
                                isset($chunk["headers"]["x-memory-used"])
                                    ? (int) $chunk["headers"]["x-memory-used"]
                                    : null,
                            "memory_limit" =>
                                isset($chunk["headers"]["x-memory-limit"])
                                    ? (int) $chunk["headers"]["x-memory-limit"]
                                    : null,
                        ];
                        $this->output_progress(
                            [
                                "phase" => "db-index",
                                "status" =>
                                    $chunk["headers"]["x-status"] ?? "unknown",
                                "tables_processed" =>
                                    (int) ($chunk["headers"][
                                        "x-tables-processed"
                                    ] ?? 0),
                            ],
                            true,
                        );
                    } elseif ($chunk_type === "error") {
                        $this->handle_error_chunk($chunk, "sql", $context);
                    }
                };

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->fetch_streaming(
                        $url,
                        $cursor,
                        $context,
                        null,
                        "db_index",
                    );
                } catch (CurlTimeoutException $e) {
                    // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
                    // with no progress, so we don't retry forever.
                    $this->assert_can_retry_consecutive_timeout("db_index", $cursor_before, $cursor);
                    fflush($handle);
                    $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                    $this->import_state()->db_index->file = $tables_file;
                    $this->import_state()->db_index->tables = $tables_written;
                    $this->import_state()->db_index->rows_estimated = $rows_estimated;
                    $this->import_state()->db_index->bytes = $bytes_written;
                    $this->import_state()->db_index->updated_at = (string) time();
                    $this->import_state()->active_resumable_command->completion_state = "partial";
                    $this->save_state($this->state);
                    return;
                }
                $this->import_state()->consecutive_timeouts = 0;
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "db_index",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                fflush($handle);
                $this->import_state()->active_resumable_command->remote_cursor = $cursor;
                $this->import_state()->db_index->file = $tables_file;
                $this->import_state()->db_index->tables = $tables_written;
                $this->import_state()->db_index->rows_estimated = $rows_estimated;
                $this->import_state()->db_index->bytes = $bytes_written;
                $this->import_state()->db_index->updated_at = (string) time();
                $this->save_state($this->state);
            }
        } finally {
            fclose($handle);
        }
    }


    /**
     * Assert that a symlink target resolves to a path within $root.
     *
     * For absolute targets, the target itself must be under $root.
     * For relative targets, the resolved path (parent dir + target) must be
     * under $root. We normalize ".." segments without touching the filesystem,
     * since the target may not exist yet.
     *
     * @throws RuntimeException if the target escapes the root.
     */
    private function assert_symlink_target_within_root(
        string $symlink_parent_dir,
        string $target,
        string $root
    ): void {
        if (str_starts_with($target, "/")) {
            // Absolute target: must be under root
            $resolved = normalize_path($target);
        } else {
            // Relative target: resolve against the symlink's parent directory
            $resolved = normalize_path($symlink_parent_dir . "/" . $target);
        }

        if (!path_is_within_root($resolved, $root)) {
            throw new RuntimeException(
                "Security: symlink target escapes filesystem root: {$target} " .
                "(resolves to {$resolved}, root is {$root})"
            );
        }
    }

    /**
     * Map a remote symlink target to the local fs-root mirror when possible.
     *
     * Handles both absolute and relative targets (relative ones are resolved
     * against the symlink's source directory). In-scope and non-followed targets
     * keep their original spelling.
     *
     * Example:
     *
     * Source site:
     *
     *   /srv/source-site/
     *   `-- wp-content/
     *       `-- themes/
     *           `-- indice -> /tmp/e2e-shared-themes/pub/indice
     *
     *   /tmp/e2e-shared-themes/pub/indice/
     *   |-- style.css
     *   `-- index.php
     *
     * Local import state:
     *
     *   <state-dir>/fs-root/
     *   |-- tmp/e2e-shared-themes/pub/indice/
     *   |   |-- style.css
     *   |   `-- index.php
     *   `-- srv/source-site/
     *       `-- wp-content/themes/
     *
     * Without this mapping, the symlink would point at /tmp/e2e-shared-themes/pub/indice
     * (which does not exist on the local machine, or worse, exists with unrelated content).
     * With this mapping, the symlink is rewritten to a relative path that resolves to the
     * mirrored local copy under fs-root.
     */
    private function map_symlink_target_for_local_mirror(
        string $path,
        string $local_path,
        string $target
    ): string {
        // Resolve to an absolute source path (relative targets against the
        // symlink's source dir) so both spellings are handled the same way.
        $source_target = str_starts_with($target, "/")
            ? normalize_path($target)
            : normalize_path(dirname($path) . "/" . $target);

        // Only rewrite a target whose subtree was actually followed and indexed;
        // everything else keeps its original (portable) spelling.
        if (
            !$this->follow_symlinks ||
            !$this->remote_index_contains_path_prefix($source_target)
        ) {
            return $target;
        }

        // Repoint to wherever the target's content is actually placed,
        // the same seam file chunks use, so that the link never dangles.
        $mapped_absolute = $this->remote_path_to_local_path_within_import_root($source_target);
        $mapped_relative = self::compute_relative_path(
            dirname($local_path),
            $mapped_absolute
        );

        $this->audit_log(
            "SYMLINK TARGET REMAP | {$path}: {$target} -> {$mapped_relative}",
            false,
        );

        return $mapped_relative;
    }

    /**
     * Checks if the remote index contains $path or any descendant under it.
     * Runs a memoized O(N) scan of .import-remote-index.jsonl.
     */
    private function remote_index_contains_path_prefix(string $path): bool
    {
        $path = rtrim(normalize_path($path), "/");
        if ($path === "") {
            return false;
        }

        if (isset($this->remote_index_prefix_cache[$path])) {
            return $this->remote_index_prefix_cache[$path];
        }

        if (!file_exists($this->remote_index_file)) {
            $this->remote_index_prefix_cache[$path] = false;
            return false;
        }

        $h = fopen($this->remote_index_file, "r");
        if (!$h) {
            $this->remote_index_prefix_cache[$path] = false;
            return false;
        }

        $prefix = $path . "/";
        $found = false;
        while (($line = fgets($h)) !== false) {
            try {
                $entry = $this->parse_index_line($line);
            } catch (RuntimeException $e) {
                continue;
            }
            if ($entry === null) {
                continue;
            }
            $entry_path = $entry["path"];
            if ($entry_path === $path || str_starts_with($entry_path, $prefix)) {
                $found = true;
                break;
            }
        }
        fclose($h);

        $this->remote_index_prefix_cache[$path] = $found;
        return $found;
    }

    /**
     * Return canonical fs root path, creating it if it doesn't exist.
     */
    private function get_filesystem_root_path(): string
    {
        if (!is_dir($this->fs_root)) {
            if (!mkdir($this->fs_root, 0755, true) && !is_dir($this->fs_root)) {
                throw new RuntimeException(
                    "Failed to create fs root directory: {$this->fs_root}",
                );
            }
        }

        $real = realpath($this->fs_root);
        if ($real === false) {
            throw new RuntimeException(
                "Failed to resolve fs root path: {$this->fs_root}",
            );
        }

        return $real;
    }

    /**
     * Refuse to reuse a files index with different --remap rules.
     *
     * The files index stores remote paths. Local writes/deletes derive their
     * targets from the current remap rules, so changing those rules while the
     * same index is still in use can point future updates at the wrong path.
     */
    private function assert_files_remap_consistent(): void
    {
        $fingerprint = $this->files_remap_fingerprint();
        $previous = $this->import_state()->files_remap_fingerprint ?? null;

        $has_existing_index = file_exists($this->index_file) && filesize($this->index_file) > 0;
        if ($previous === null && $has_existing_index && !empty($this->remap_rules)) {
            throw new RuntimeException(
                "Cannot use --remap with an existing files index that was created before remap tracking. " .
                    "Use a new --state-dir or clear the existing files index first.",
            );
        }

        if ($previous !== null && $previous !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change --remap rules while reusing the same files index. " .
                    "Use the original --remap rules, or use a new --state-dir for a fresh files-pull.",
            );
        }

        if ($previous === null) {
            $this->import_state()->files_remap_fingerprint = $fingerprint;
            $this->save_state($this->state);
        }
    }

    /**
     * Stable fingerprint for the resolved remap rule set.
     *
     * Rule order does not matter: remap matching chooses the deepest source
     * path, not the first matching rule.
     */
    private function files_remap_fingerprint(): string
    {
        $rules = $this->remap_rules;
        ksort($rules, SORT_STRING);
        return hash("sha256", json_encode($rules, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Refuse to resume a files-pull after changing --only.
     *
     * The in-progress remote index cursor/file was built from one directory[]
     * allowlist. Switching the selected source path prefixes mid-resume would
     * mix indexes from different traversals. Completed runs are allowed to use
     * different --only prefixes because the local index is intentionally a union
     * across files-pull --only runs.
     */
    private function assert_files_pull_only_unchanged_while_resuming(bool $has_progress): void
    {
        if (!$has_progress) {
            return;
        }

        $fingerprint = $this->files_pull_only_fingerprint();
        $previous = $this->import_state()->files_pull_only_fingerprint ?? null;

        if ($previous !== null && $previous !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change --only while resuming files-pull. " .
                    "Use the original --only values, or use --abort to start a new files-pull.",
            );
        }

        // Older in-progress state may not have this guard persisted yet. Record
        // the current value so subsequent resumes cannot drift.
        if ($previous === null) {
            $this->import_state()->files_pull_only_fingerprint = $fingerprint;
            $this->save_state($this->state);
        }
    }

    /**
     * Stable fingerprint for the resolved --only file path prefixes.
     *
     * Prefix order is part of the fingerprint because it determines the first
     * list_dir used to start the traversal.
     */
    private function files_pull_only_fingerprint(): string
    {
        return hash(
            "sha256",
            json_encode($this->pull_only_files_with_path_prefixes, JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Refuse to run files-pull after the --follow-symlinks bundle directory
     * changed. Placement of followed content is bound to it, so changing it
     * mid-state would split content across two layouts. Recorded on the first
     * run, compared on every run after; --abort resets it.
     */
    private function assert_symlink_bundle_directory_unchanged(): void
    {
        $fingerprint = $this->symlink_bundle_directory_fingerprint();
        $previous = $this->import_state()->symlink_bundle_directory_fingerprint ?? null;

        if ($previous !== null && $previous !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change the --follow-symlinks bundle directory for an existing files-pull. " .
                    "Use the original value, or use --abort to start a new files-pull.",
            );
        }

        if ($previous === null) {
            $this->import_state()->symlink_bundle_directory_fingerprint = $fingerprint;
            $this->save_state($this->state);
        }
    }

    /**
     * Fingerprint of the effective bundle placement root. No bundle directory
     * (and bare --follow-symlinks) fingerprints as fs-root, which is the
     * equivalent placement — so switching between those spellings is allowed.
     */
    private function symlink_bundle_directory_fingerprint(): string
    {
        $effective = $this->symlink_bundle_directory ?? rtrim($this->get_filesystem_root_path(), "/");
        return hash("sha256", $effective);
    }

    /**
     * Resolve the --follow-symlinks=<dir> bundle destination.
     *
     * Uses the same target grammar as --remap targets: a :fs-root: path or a raw
     * absolute path, which must resolve within --fs-root.
     */
    private function resolve_symlink_bundle_directory(string $raw): string
    {
        $fs_root = rtrim($this->get_filesystem_root_path(), "/");
        $directory = $this->resolve_token_path($raw, ["fs-root" => $fs_root]);

        if (!path_is_within_root($directory, $fs_root)) {
            throw new InvalidArgumentException(
                "--follow-symlinks bundle directory \"{$directory}\" resolves outside --fs-root ({$fs_root}); " .
                    "it must stay within the destination root",
            );
        }

        return $directory;
    }

    /**
     * Build the remap rules from the raw remap pairs + preflight data.
     *
     * Each argument is a template string of `:token:` substitutions and/or a raw absolute path.
     * Source arguments resolve against the remote site's WordPress path tokens.
     * Target arguments resolve under --fs-root and must stay within it.
     * Each rule is a full source path => full local target path (both absolute).
     *
     * @param array<int,array{0:string,1:string}> $remap_raw Raw (SOURCE, TARGET) pairs.
     * @return array<string,string> Source path => target path (both absolute).
     */
    private function resolve_remap(array $remap_raw): array
    {
        $fs_root = rtrim($this->get_filesystem_root_path(), "/");

        $source_tokens = $this->wp_source_path_tokens();
        $target_tokens = ["fs-root" => $fs_root];

        $rules = [];
        $wp_content_target = null;
        foreach ($remap_raw as [$source_raw, $target_raw]) {
            $source = $this->resolve_token_path($source_raw, $source_tokens);
            $target = $this->resolve_token_path($target_raw, $target_tokens);

            if (!path_is_within_root($target, $fs_root)) {
                throw new InvalidArgumentException(
                    "--remap target \"{$target}\" resolves outside --fs-root ({$fs_root}); " .
                        "targets must stay within the destination root",
                );
            }

            $rules[$source] = $target;
            if ($source === $source_tokens["wp-content"]) {
                $wp_content_target = $target;
            }
        }

        // When remapping wp-content, also remap plugins, mu-plugins, and uploads
        // directories that live outside WP_CONTENT_DIR. Skip any directory that already
        // has its own explicit --remap rule.
        if ($wp_content_target !== null) {
            foreach ($this->content_directories_outside_wp_content($source_tokens) as $name => $source) {
                if (!isset($rules[$source])) {
                    $rules[$source] = wp_join_unix_paths($wp_content_target, $name);
                }
            }
        }

        return $rules;
    }

    /**
     * Find plugins, mu-plugins, and uploads directories that WordPress reports
     * outside WP_CONTENT_DIR.
     *
     * When wp-content is selected with --only or --remap, these directories are not
     * covered by WP_CONTENT_DIR itself, so callers need to handle them separately.
     * Unknown paths are omitted because both WP_CONTENT_DIR and the directory path
     * are needed to decide whether the directory lives outside WP_CONTENT_DIR.
     *
     * @param array<string,string|null> $source_tokens From wp_source_path_tokens().
     * @return array<string,string> Directory name => real source path, for
     *                              directories outside WP_CONTENT_DIR only.
     */
    private function content_directories_outside_wp_content(array $source_tokens): array
    {
        $content = $source_tokens["wp-content"];
        if ($content === null) {
            return [];
        }

        $directories = [];
        foreach (["wp-plugins" => "plugins", "wp-mu-plugins" => "mu-plugins", "wp-uploads" => "uploads"] as $token => $name) {
            $source = $source_tokens[$token];
            if ($source !== null && !path_is_within_root($source, $content)) {
                $directories[$name] = $source;
            }
        }

        return $directories;
    }

    /**
     * Resolve raw `--only` sources into a deduped list of real source absolute
     * prefixes selected for files-pull. Each source is a `:token:` template (e.g.
     * `:wp-content:`, `:wp-uploads:`) or a raw absolute path,
     * resolved through the same source token table (wp_source_path_tokens)
     * as --remap.
     *
     * @param array<int,string> $only_raw Raw SOURCE values from the CLI.
     * @return array<int,string> Real source prefixes (deduped).
     */
    private function resolve_pull_only_files_with_path_prefixes(array $only_raw): array
    {
        $source_tokens = $this->wp_source_path_tokens();

        $prefixes = [];
        foreach ($only_raw as $src) {
            if ($src === "") {
                throw new InvalidArgumentException("--only source cannot be empty");
            }

            $resolved = $this->resolve_token_path($src, $source_tokens);
            $prefixes[$resolved] = true;

            // Selecting content_dir with --only also pulls in any plugins, mu-plugins,
            // or uploads directory outside WP_CONTENT_DIR so files-pull enumerates it too.
            if ($resolved === $source_tokens["wp-content"]) {
                foreach ($this->content_directories_outside_wp_content($source_tokens) as $source) {
                    $prefixes[$source] = true;
                }
            }
        }

        // Drop any prefix already covered by a broader one (e.g. wp-content and wp-content/plugins)
        // A files-pull --only run doesn't need to walk the same subtree twice.
        $sources = array_keys($prefixes);
        $minimal = [];
        foreach ($sources as $path) {
            $covered = false;

            foreach ($sources as $other) {
                if ($other !== $path && path_is_within_root($path, $other)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $minimal[] = $path;
            }
        }

        return $minimal;
    }

    /**
     * Whether $path is selected by the active --only file path prefixes. With no
     * --only flag, every file path is selected (returns true) — this keeps
     * the diff's delete drains at their default behavior. When --only is set,
     * true only when $path falls under one of those file path prefixes IF it is
     * NOT the directory itself (when the path remainder is an empty string): an
     * --only remote index lists what is inside each selected directory, never
     * the directory itself, so to the diff the selected directories always
     * appear deleted-on-remote even though they exist.
     */
    private function is_file_path_selected_by_pull_only_files(string $path): bool
    {
        if (empty($this->pull_only_files_with_path_prefixes)) {
            return true;
        }

        foreach ($this->pull_only_files_with_path_prefixes as $prefix) {
            $remainder = self::path_remainder_under($path, $prefix);
            if ($remainder === "") {
                return false;
            }
            if ($remainder !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source site's real paths from preflight data, as remap/--only token
     * name => absolute path (wp-content, wp-plugins, wp-mu-plugins, wp-uploads,
     * abspath).
     *
     * Plugins, mu-plugins, and uploads fall back to their conventional locations
     * under WP_CONTENT_DIR when WP_CONTENT_DIR is known. This is a pure
     * data-gatherer: any entry may be null when preflight lacks it (no
     * content_dir, abspath undetermined).
     */
    private function wp_source_path_tokens(): array
    {
        $preflight = $this->import_state()->preflight["data"] ?? [];
        $paths = $preflight["database"]["wp"]["paths_urls"] ?? [];

        $content_dir = $this->clean_preflight_path( $paths["content_dir"] ?? null);

        $abspath = $this->clean_preflight_path( $paths["abspath"] ?? null);
        if ($abspath === null) {
            $abspath = $this->clean_preflight_path( $preflight["wp_detect"]["roots"][0]["path"] ?? null);
        }

        $plugins_dir = $this->clean_preflight_path( $paths["plugins_dir"] ?? null);
        $mu_plugins_dir = $this->clean_preflight_path( $paths["mu_plugins_dir"] ?? null);
        $uploads_dir = $this->clean_preflight_path( $paths["uploads"]["basedir"] ?? null);

        // If preflight did not report a directory path, use its conventional
        // location under WP_CONTENT_DIR when WP_CONTENT_DIR is known.
        if ($content_dir !== null) {
            $plugins_dir = $plugins_dir ?? wp_join_unix_paths( $content_dir, "plugins" );
            $mu_plugins_dir = $mu_plugins_dir ?? wp_join_unix_paths( $content_dir, "mu-plugins" );
            $uploads_dir = $uploads_dir ?? wp_join_unix_paths( $content_dir, "uploads" );
        }

        return [
            "abspath" => $abspath,
            "wp-content" => $content_dir,
            "wp-plugins" => $plugins_dir,
            "wp-mu-plugins" => $mu_plugins_dir,
            "wp-uploads" => $uploads_dir,
        ];
    }

    /**
     * Resolve a --remap/--only path argument into an absolute path.
     *
     * Substitutes a known leading `:token:` (see the token tables in
     * resolve_remap and resolve_pull_only_files_with_path_prefixes) with its
     * value, then trims trailing slashes. The result must be a valid absolute
     * path with no `.`/`..` segments; a relative path or an unknown token (left
     * unsubstituted) fails that check. Referencing a token whose value is
     * unavailable in preflight is a distinct, clear error.
     *
     * @param string $raw The raw argument.
     * @param array<string,string|null> $tokens Token name => value (null = unavailable).
     */
    private function resolve_token_path(string $raw, array $tokens): string
    {
        $resolved = $raw;
        foreach ($tokens as $name => $value) {
            $token = ":{$name}:";
            $token_offset = strpos($resolved, $token);
            if ($token_offset === false) {
                continue;
            }

            if ($token_offset !== 0 || strpos($resolved, $token, strlen($token)) !== false) {
                throw new InvalidArgumentException(
                    "token \"{$token}\" must appear only at the beginning of the path"
                );
            }

            if ($value === null) {
                throw new InvalidArgumentException(
                    "Cannot resolve token \"{$token}\": not available in preflight data. Run preflight first."
                );
            }

            $resolved = $value . substr($resolved, strlen($token));
        }

        $resolved = rtrim($resolved, "/");
        assert_valid_path($resolved, "path \"{$raw}\"");

        return $resolved;
    }

    /**
     * Map a source absolute path to its absolute local target via the remap
     * rules (most specific source wins), or null when no rule applies.
     *
     * Any rules that match the same path are nested (each is a path-prefix of
     * it, and prefixes of one string are ordered by length), so the longest
     * matching source is the deepest — i.e. the most specific — match. Ranking
     * by source, not target: a rule applies based purely on its source side.
     */
    private function remap_source_path_to_target(string $source_path): ?string
    {
        $best = null;
        $best_source_length = -1;

        foreach ($this->remap_rules as $source => $target) {
            $rest = self::path_remainder_under($source_path, $source);
            if ($rest !== null && strlen($source) > $best_source_length) {
                $best = wp_join_unix_paths($target, $rest);
                $best_source_length = strlen($source);
            }
        }

        return $best;
    }

    /**
     * Resolve a remote absolute path into a local path under the fs root.
     *
     * Maps a remote absolute path (e.g. "/wp-content/uploads/photo.jpg") to a
     * local path under the import fs root. Performs symlink traversal security
     * checks to prevent directory traversal attacks that could write files
     * outside the import root.
     *
     * With --remap active, a source path matched by a remap rule is first routed to its
     * target (e.g. "/var/www/html/wp-content/x" -> "<docroot>/wp-content/x");
     * paths under no remap rule are still written under --fs-root using their
     * remote absolute path, exactly as a plain pull.
     */
    private function remote_path_to_local_path_within_import_root(
        string $path
    ): string {
        assert_valid_path($path, "remote path");
        if (!empty($this->remap_rules)) {
            $target = $this->remap_source_path_to_target($path);
            if ($target !== null) {
                return $target;
            }
        }

        // Following symlinks is currently the only way paths outside the original export scope reach this mapper.
        // Use the same bundle mapping for copied content and rewritten symlink targets so the links do not dangle.
        if ($this->symlink_bundle_directory !== null
            && !$this->path_is_within_original_export_scope($path)) {
            return $this->symlink_bundle_directory . $path;
        }

        return $this->get_filesystem_root_path() . $path;
    }


    /**
     * Returns the remainder of $path underneath $prefix,
     * empty string if $path === $prefix,
     * or null if $path is not under $prefix.
     */
    private static function path_remainder_under(string $path, string $prefix): ?string
    {
        $path = rtrim($path, "/");
        $prefix = rtrim($prefix, "/");

        if ($path === $prefix) {
            return "";
        }

        if (str_starts_with($path, $prefix . "/")) {
            return substr($path, strlen($prefix));
        }

        return null;
    }

    /**
     * Handle a metadata chunk from multipart response.
     */
    private function handle_metadata_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $filesystem_root = base64_decode($headers["x-filesystem-root"] ?? "", true);

        if ($filesystem_root) {
            $context->filesystem_root = $filesystem_root;
            $this->audit_log("Filesystem root: {$filesystem_root}", false);
        }
    }

    /**
     * Handle a file chunk from multipart response.
     */
    private function handle_file_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-file-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $is_first = ($headers["x-first-chunk"] ?? "0") === "1";
        $is_last = ($headers["x-last-chunk"] ?? "0") === "1";

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit_log(
                    "Warning: base64_decode failed for x-file-path header: " .
                        substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // Open file on first chunk
        if ($is_first) {
            // Reset skip flag for each new file
            $context->skip_current_file = false;

            if (
                (file_exists($local_path) || is_link($local_path)) &&
                (!is_file($local_path) || is_link($local_path))
            ) {
                if (
                    !$this->remove_local_path_without_following_symlinks(
                        $local_path
                    )
                ) {
                    throw new RuntimeException(
                        "Failed to replace path with file: {$path}",
                    );
                }
            }

            // Check if file exists locally
            $exists_locally = file_exists($local_path);
            $local_size = $exists_locally ? filesize($local_path) : 0;
            $file_size = (int) ($headers["x-file-size"] ?? 0);

            // Log file import with useful context
            $this->audit_log(
                sprintf(
                    "File: %s (remote_size=%d, ctime=%d, local_exists=%s, local_size=%d)",
                    $path,
                    $file_size,
                    (int) ($headers["x-file-ctime"] ?? 0),
                    $exists_locally ? "yes" : "no",
                    $local_size,
                ),
                false,
            );

            $files_done = ($this->download_list_done ?? 0) + $this->files_imported;
            $files_total = $this->download_list_total;
            $file_fraction = ($files_total !== null && $files_total > 0)
                ? $files_done / $files_total
                : null;
            $file_progress_message = $files_total !== null
                ? sprintf("Downloading — %s / %s files", number_format($files_done), number_format($files_total))
                : sprintf("Downloading — %s files", number_format($files_done));
            $this->progress->show_progress_line($file_progress_message, $file_fraction);
            $progress_record = [
                "type" => "file_progress",
                "files_done" => $files_done,
                "path" => $path,
                "size" => $file_size,
                "message" => $file_progress_message,
            ];
            if ($this->download_list_total !== null) {
                $progress_record["files_total"] = $this->download_list_total;
            }
            $this->output_progress($progress_record);
        }

        // Skip body/close for files being preserved
        if ($context->skip_current_file) {
            return;
        }

        // Open file handle on first chunk
        if ($is_first) {
            // Close previous file if any
            if ($context->file_handle) {
                fclose($context->file_handle);
                if ($context->file_ctime && $context->file_path) {
                    touch($context->file_path, $context->file_ctime);
                }
            }

            // Create parent directory if needed
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                // Check if any component of the path exists as a file and remove it
                try {
                    $this->ensure_directory_path($dir);
                } catch (PreserveLocalSkipException $e) {
                    $context->skip_current_file = true;
                    $this->audit_log($e->getMessage(), true);
                    $this->emit_skip_progress($path);
                    return;
                }
            }

            // Open new file
            $context->file_handle = fopen($local_path, "wb");
            if (!$context->file_handle) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open file for writing: {$local_path}\n" .
                        "Parent directory: {$dir}\n" .
                        "Directory exists: " .
                        (is_dir($dir) ? "yes" : "no") .
                        "\n" .
                        "Error: " .
                        ($error["message"] ?? "unknown"),
                );
            }
            $context->file_path = $local_path;
            $context->file_ctime = (int) ($headers["x-file-ctime"] ?? 0);
            $context->file_bytes_written = 0;  // Reset byte counter for new file
        }

        // Write body data if present
        if (isset($chunk["body"]) && $chunk["body"] !== "") {
            if ($context->file_handle) {
                $data = $chunk["body"];
                $bytes = fwrite($context->file_handle, $data);
                if ($bytes === false || $bytes !== strlen($data)) {
                    throw new RuntimeException(
                        "Write failed for {$context->file_path}: wrote " .
                        ($bytes === false ? "0" : $bytes) . "/" . strlen($data) .
                        " bytes (disk full?)"
                    );
                }
                $context->file_bytes_written += $bytes;
            }
        }

        // Close on last chunk
        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);

            // Set file modification time
            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }

            // Index update (JSON lines)
            $file_size = (int) ($headers["x-file-size"] ?? 0);
            $final_size = file_exists($context->file_path)
                ? filesize($context->file_path)
                : 0;

            $file_changed = ($headers["x-file-changed"] ?? "0") === "1";

            if ($context->file_ctime && !$file_changed) {
                $this->upsert_index_entry(
                    $path,
                    $context->file_ctime,
                    $file_size,
                    "file",
                );
                $this->files_imported++; // Count completed files only
                $this->clear_volatile_file($path);
                $this->audit_log(
                    sprintf("  Indexed (wrote %d bytes)", $final_size),
                    false,
                );
            } elseif ($file_changed) {
                $this->audit_log(
                    "  File changed during stream; index not updated",
                    true,
                );
            }

            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->file_bytes_written = 0;
            // Clear crash recovery tracking - file is complete
            $this->import_state()->current_file = null;
            $this->import_state()->current_file_bytes = null;
        }
    }

    /**
     * Build a short display path for progress messages: strip leading slash,
     * truncate from the left when too long.
     */
    private function display_path(string $path): string
    {
        $rel = ltrim($path, "/");
        $max = 60;
        if (strlen($rel) > $max) {
            $rel = "..." . substr($rel, -($max - 3));
        }
        return $rel;
    }

    /**
     * Check whether any component of the path (between the filesystem root
     * and the target) is a symlink.  In preserve-local mode this is used
     * to prevent creating new content through symlinked directories — their
     * contents belong to shared hosting infrastructure and must not be
     * modified.
     */
    private function should_skip_for_preserve_local(string $path): ?string
    {
        if ($this->fs_root_nonempty_behavior !== 'preserve-local') {
            return null;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // Skip if anything already exists at this path — regular file, symlink
        // (even to a file), or directory.  This preserves hosting symlinks like
        // wp-load.php -> __wp__/wp-load.php and drop-in symlinks like
        // object-cache.php -> ../../wordpress/drop-ins/...
        if (file_exists($local_path) || is_link($local_path)) {
            return "PRESERVE-LOCAL skip file (exists): {$path}";
        }

        // Skip if parent directory is not writable or if any directory component
        // in the path is a symlink.  We never create new files through symlinks —
        // the symlink and its target contents are shared hosting infrastructure.
        $dir = dirname($local_path);
        if (is_dir($dir) && !is_writable($dir)) {
            return "PRESERVE-LOCAL skip file (dir not writable): {$path}";
        }
        if ($this->path_traverses_symlink($dir)) {
            return "PRESERVE-LOCAL skip file (symlink in path): {$path}";
        }

        return null;
    }

    private function path_traverses_symlink(string $path): bool
    {
        $root = $this->get_filesystem_root_path();
        $relative = ltrim(substr($path, strlen($root)), "/");
        if ($relative === "") {
            return false;
        }

        $current = $root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                break;
            }
        }
        return false;
    }

    /**
     * Ensure a directory path exists, removing any files that block it.
     *
     * @param string $dir Directory path to ensure
     * @throws RuntimeException if directory cannot be created or is outside allowed path
     */
    private function ensure_directory_path(string $dir): void
    {
        // Security: Ensure path is under the fs root
        $real_filesystem_root = $this->get_filesystem_root_path();

        // Resolve the target path (or what it would be)
        // For non-existent paths, resolve the parent and append the final component
        $check_path = $dir;
        while (
            !file_exists($check_path) &&
            $check_path !== dirname($check_path)
        ) {
            $check_path = dirname($check_path);
        }

        if (file_exists($check_path)) {
            $real_check = realpath($check_path);
            if (
                $real_check === false ||
                !path_is_within_root($real_check, $real_filesystem_root)
            ) {
                // In preserve-local mode, a path that resolves outside the
                // fs root is expected when a directory like wp-content/plugins
                // is symlinked to a shared hosting location.  Skip gracefully
                // instead of treating it as a security violation.
                if ($this->fs_root_nonempty_behavior === 'preserve-local') {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: path resolves outside fs root via symlink: {$dir}",
                    );
                }
                throw new RuntimeException(
                    "Security: Refusing to create directory outside fs root: {$dir}",
                );
            }
        }

        if (is_dir($dir) && !is_link($dir)) {
            if ($this->fs_root_nonempty_behavior === 'preserve-local' && !is_writable($dir)) {
                throw new PreserveLocalSkipException(
                    "PRESERVE-LOCAL: directory not writable: {$dir}",
                );
            }
            return;
        }

        if (
            $dir !== $real_filesystem_root &&
            !str_starts_with($dir, $real_filesystem_root . "/")
        ) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside fs root: {$dir}",
            );
        }

        $relative = ltrim(substr($dir, strlen($real_filesystem_root)), "/");
        if ($relative === "") {
            return;
        }

        $current = $real_filesystem_root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;

            if (is_link($current)) {
                if ($this->fs_root_nonempty_behavior === 'preserve-local') {
                    // Never create directories through symlinks — the symlink
                    // and its target contents are shared hosting infrastructure
                    // that must not be modified.
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: symlink in directory path: {$current}",
                    );
                }
                $this->audit_log(
                    "Removing symlink blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove symlink blocking directory: {$current}",
                    );
                }
                // Clear cached realpath so the subsequent realpath() check
                // sees the new directory instead of the removed symlink.
                clearstatcache(true, $current);
            }

            // Remove file if blocking directory creation
            if (is_file($current)) {
                if ($this->fs_root_nonempty_behavior === 'preserve-local') {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: file blocks directory creation: {$current}",
                    );
                }
                $this->audit_log(
                    "Removing file blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove file blocking directory: {$current}",
                    );
                }
            }

            // Create directory if it doesn't exist
            if (is_dir($current)) {
                if ($this->fs_root_nonempty_behavior === 'preserve-local' && !is_writable($current)) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: directory not writable: {$current}",
                    );
                }
            } elseif (!mkdir($current, 0755) && !is_dir($current)) {
                throw new RuntimeException(
                    "Failed to create directory: {$current}\n" .
                        "Error: " .
                        (error_get_last()["message"] ?? "unknown"),
                );
            }

            $resolved = realpath($current);
            if ($resolved === false || !path_is_within_root($resolved, $real_filesystem_root)) {
                throw new RuntimeException(
                    "Security: Refusing to create directory outside fs root: {$current}",
                );
            }
        }
    }

    /**
     * Handle a directory chunk (create empty directory).
     */
    private function handle_directory_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-directory-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $ctime = (int) ($headers["x-directory-ctime"] ?? 0);

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit_log(
                    "Warning: base64_decode failed for x-directory-path header: " .
                        substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // In preserve-local mode, if the directory already exists (as a real
        // directory or via a symlink to a directory), keep it as-is.
        // Also skip if any parent component is a symlink — we never create
        // new directories through symlinked paths.
        if ($this->fs_root_nonempty_behavior === 'preserve-local') {
            if (is_dir($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip directory (exists): {$path}", true);
                $this->emit_skip_progress($path);
                if ($ctime > 0) {
                    $this->upsert_index_entry($path, $ctime, 0, "dir");
                }
                return;
            }
            if ($this->path_traverses_symlink($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip directory (symlink in path): {$path}", true);
                $this->emit_skip_progress($path);
                if ($ctime > 0) {
                    $this->upsert_index_entry($path, $ctime, 0, "dir");
                }
                return;
            }
        }

        if (
            (file_exists($local_path) || is_link($local_path)) &&
            (!is_dir($local_path) || is_link($local_path))
        ) {
            if (
                !$this->remove_local_path_without_following_symlinks($local_path)
            ) {
                throw new RuntimeException(
                    "Failed to replace path with directory: {$path}",
                );
            }
        }

        // Create directory, removing any files that block the path
        try {
            $this->ensure_directory_path($local_path);
        } catch (PreserveLocalSkipException $e) {
            $this->audit_log($e->getMessage(), true);
            $this->emit_skip_progress($path);
            return;
        }

        $this->audit_log("Directory: {$path}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "dir");
        }
    }

    /**
     * Recreates a symlink from the export stream in the local filesystem.
     *
     * Decodes the base64-encoded path and target from the chunk headers,
     * validates that the target stays within the filesystem root (preventing
     * directory traversal), then creates the symlink.  Failures are logged
     * to the audit log and reported as symlink_error progress events — they
     * do not halt the import.
     *
     * @param array $chunk Multipart chunk with x-symlink-path, x-symlink-target,
     *                     and x-symlink-ctime headers (all base64-encoded).
     */
    private function handle_symlink_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_path = $headers["x-symlink-path"] ?? "";
        $path = base64_decode($raw_path, true);
        $target = base64_decode($headers["x-symlink-target"] ?? "", true);
        $ctime = (int) ($headers["x-symlink-ctime"] ?? 0);

        // Skip if path or target is missing/empty
        if ($path === false || $path === "" || $target === false || $target === "") {
            if ($raw_path !== "" && ($path === false || $path === "")) {
                $this->audit_log(
                    "Warning: base64_decode failed for x-symlink-path header: " .
                        substr($raw_path, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);
        $target_for_local = $this->map_symlink_target_for_local_mirror(
            $path,
            $local_path,
            $target,
        );

        // In preserve-local mode, if something already exists at the symlink
        // path, keep it — whether it's a file, directory, or another symlink.
        // Also skip if any parent component is a symlink — we never create
        // new content through symlinked directories.
        if ($this->fs_root_nonempty_behavior === 'preserve-local') {
            if (file_exists($local_path) || is_link($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip symlink (path exists): {$path} -> {$target}", true);
                $this->emit_skip_progress($path);
                return;
            }
            if ($this->path_traverses_symlink(dirname($local_path))) {
                $this->audit_log("PRESERVE-LOCAL skip symlink (symlink in path): {$path} -> {$target}", true);
                $this->emit_skip_progress($path);
                return;
            }
        }

        // Validate that the symlink target doesn't escape the filesystem root.
        $root = $this->get_filesystem_root_path();
        try {
            $this->assert_symlink_target_within_root(
                dirname($local_path),
                $target_for_local,
                $root
            );
        } catch (RuntimeException $e) {
            $this->audit_log($e->getMessage(), true);
            $this->output_progress([
                "type" => "symlink_error",
                "path" => $path,
                "target" => $target_for_local,
                "error" => $e->getMessage(),
                "message" => "Symlink error: {$path} -> {$target}",
            ]);
            return;
        }

        // Remove existing file/symlink if present
        if (file_exists($local_path) || is_link($local_path)) {
            if (
                !$this->remove_local_path_without_following_symlinks($local_path)
            ) {
                $this->audit_log(
                    "Failed to remove existing path for symlink: {$local_path}",
                    true,
                );
                $this->output_progress([
                    "type" => "symlink_error",
                    "path" => $path,
                    "target" => $target_for_local,
                    "error" => "Failed to replace existing path",
                    "message" => "Symlink error: {$path} -> {$target}",
                ]);
                return;
            }
        }

        // Create parent directory
        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            try {
                $this->ensure_directory_path($dir);
            } catch (PreserveLocalSkipException $e) {
                $this->audit_log($e->getMessage(), true);
                $this->emit_skip_progress($path);
                return;
            } catch (RuntimeException $e) {
                // Log error and skip this symlink
                $this->audit_log(
                    "Failed to create directory for symlink: {$dir}",
                    true,
                );
                $this->output_progress([
                    "type" => "symlink_error",
                    "path" => $path,
                    "target" => $target_for_local,
                    "error" => "Failed to create parent directory",
                    "message" => "Symlink error: {$path} -> {$target}",
                ]);
                return;
            }
        }

        // Create symlink
        $symlink_result = symlink($target_for_local, $local_path);
        if (true !== $symlink_result || !is_link($local_path)) {
            // Log error and skip this symlink
            $this->audit_log(
                "Failed to create symlink: {$local_path} -> {$target_for_local}",
                true,
            );
            $this->output_progress([
                "type" => "symlink_error",
                "path" => $path,
                "target" => $target_for_local,
                "error" => "Failed to create symlink",
                "message" => "Symlink error: {$path} -> {$target}",
            ]);
            return;
        }

        // Try to set the ctime (may not work on all systems)
        if ($ctime > 0) {
            @touch($local_path, $ctime);
        }

        $this->audit_log("Symlink: {$path} -> {$target_for_local}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "link");
        }

        $this->output_progress([
            "type" => "symlink",
            "path" => $path,
            "target" => $target_for_local,
            "message" => "Symlink: {$path} -> {$target}",
        ]);
    }

    /**
     * Handle an error chunk from the server.
     */
    private function handle_error_chunk(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            $this->audit_log(
                "REMOTE ERROR | phase={$phase} | raw (JSON decode failed): " .
                    substr($body, 0, 500),
                true,
            );
            return;
        }

        $error_type = $data["error_type"] ?? "unknown";
        $path = $data["path"] ?? "";
        $message = $data["message"] ?? "Error";

        $this->audit_log(
            "REMOTE ERROR | phase={$phase} | type={$error_type} | path={$path} | message={$message}",
            true,
        );

        $is_file_error = in_array(
            $error_type,
            ["file_changed", "file_missing", "file_open", "file_read"],
            true,
        );
        if ($path !== "" && $is_file_error) {
            $local_path = $this->fs_root . $path;
            if ($context->file_handle && $context->file_path === $local_path) {
                fclose($context->file_handle);
                $context->file_handle = null;
                $context->file_path = null;
                $context->file_ctime = null;
                $context->file_bytes_written = 0;
            }

            if (file_exists($local_path)) {
                @unlink($local_path);
            }
            $this->delete_index_entry($path);

            if ($error_type === "file_changed") {
                $this->record_volatile_file($path);
            }
        }

        $error_progress_message = "Remote error: {$error_type} " . ($path !== "" ? $path : "");
        $this->progress->show_progress_line($error_progress_message);
        $this->output_progress(
            [
                "type" => "error",
                "phase" => $phase,
                "error_type" => $error_type,
                "path" => $path,
                "error_message" => $message,
                "message" => $error_progress_message,
            ],
            true,
        );
    }

    /**
     * Handle progress chunk.
     */
    private function handle_progress(array $chunk, string $phase): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            return;
        }

        $this->output_progress(array_merge(["phase" => $phase], $data));
    }

    /**
     * Build request URL with endpoint and cursor.
     */
    private function build_url(
        string $endpoint,
        ?string $cursor,
        array $params = []
    ): string {
        $url = $this->remote_url;
        $separator = strpos($url, "?") === false ? "?" : "&";

        $params["endpoint"] = $endpoint;
        if ($cursor) {
            // Also include cursor in query params as a fallback when headers are stripped.
            $params["cursor"] = $cursor;
        }
        $params["_cache_bust"] = time() . "-" . rand(0, 999999);

        return $url . $separator . http_build_query($params);
    }

    /**
     * Extract root directories from preflight wp_detect data.
     * Falls back to this when the URL doesn't contain directory[] params.
     */
    private function get_root_directories_from_preflight(): array
    {
        $roots = $this->import_state()->preflight["data"]["wp_detect"]["roots"] ?? [];
        if (!is_array($roots) || empty($roots)) {
            return [];
        }
        $dirs = [];
        foreach ($roots as $root) {
            $path = $root["path"] ?? null;
            if (is_string($path) && $path !== "") {
                $dirs[] = rtrim($path, "/");
            }
        }
        $dirs = array_values(array_unique($dirs));
        if (!empty($dirs)) {
            $this->audit_log(
                "DIRECTORY AUTO-DETECT | from preflight wp_detect.roots: " .
                    implode(", ", $dirs),
            );
        }
        return $dirs;
    }

    /**
     * Whether $path falls under one of the ORIGINAL export directories (the
     * --only prefixes, or the base roots without --only) — i.e. it was going to
     * be pulled anyway. Evaluated against the pre-follow scope; a followed
     * target outside all of these is "escaping" and eligible for symlink bundling.
     */
    private function path_is_within_original_export_scope(string $path): bool
    {
        foreach ($this->get_export_directories() as $root) {
            if (path_is_within_root($path, $root)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the list of directories the server should traverse.
     *
     * Starts from the wp_detect roots (ABSPATH, etc.) and adds
     * WP_CONTENT_DIR and document_root when they live outside those
     * roots. On managed hosts like wp.com Atomic, these are on
     * separate paths (e.g. /srv/htdocs/wp-content and /srv/htdocs
     * vs /wordpress/core/6.9.4) so the server won't discover them
     * by traversing ABSPATH alone.
     */
    private function get_export_directories(): array
    {
        // Memoized: The inputs (pull_only, remap, preflight) are all set before
        // the first caller and never change mid-run, so cache on first use.
        if ($this->export_directories_cache !== null) {
            return $this->export_directories_cache;
        }

        // With --only, files-pull should enumerate only the selected source path
        // prefixes. Do not add the default roots, remap sources, document root, or
        // auto-prepend/append directories below.
        if (!empty($this->pull_only_files_with_path_prefixes)) {
            $this->export_directories_cache = $this->pull_only_files_with_path_prefixes;
            return $this->export_directories_cache;
        }

        $dirs = $this->get_root_directories_from_preflight();
        if (empty($dirs)) {
            $this->export_directories_cache = [];
            return $this->export_directories_cache;
        }

        $preflight = $this->import_state()->preflight["data"] ?? [];

        // Collect extra paths that may live outside the wp_detect roots.
        $extra_paths = [
            "document_root" => rtrim($preflight["runtime"]["document_root"] ?? "", "/"),
            "content_dir" => rtrim($preflight["database"]["wp"]["paths_urls"]["content_dir"] ?? "", "/"),
        ];

        if ($this->extra_directory !== null && $this->extra_directory !== "") {
            $extra_paths["extra_directory"] = rtrim($this->extra_directory, "/");
        }

        // Ensure every --remap source is enumerated — including plugins or
        // uploads directories that live outside the WordPress roots and so
        // wouldn't be discovered by traversal alone.
        $remap_index = 0;
        foreach (array_keys($this->remap_rules) as $source) {
            $extra_paths["remap_source_{$remap_index}"] = $source;
            $remap_index++;
        }

        // auto_prepend_file / auto_append_file may point to directories
        // outside the WordPress roots (e.g. /scripts/env.php on Atomic).
        // Include those directories so the remote exporter traverses them.
        $ini_all = $preflight["runtime"]["ini_get_all"] ?? [];
        foreach (["auto_prepend_file", "auto_append_file"] as $ini_key) {
            $ini_path = $ini_all[$ini_key] ?? "";
            if (is_string($ini_path) && $ini_path !== "" && $ini_path[0] === "/") {
                $ini_dir = rtrim(dirname($ini_path), "/");
                if ($ini_dir !== "" && $ini_dir !== "/") {
                    $extra_paths[$ini_key] = $ini_dir;
                }
            }
        }

        foreach ($extra_paths as $label => $path) {
            if ($path === "") {
                continue;
            }
            // Check if this path is already covered by an existing dir.
            $covered = false;
            foreach ($dirs as $root) {
                if (
                    $path === $root ||
                    str_starts_with($path, $root . "/")
                ) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $dirs[] = $path;
                $this->audit_log(
                    "DIRECTORY AUTO-DETECT | adding {$label} outside roots: " .
                        $path,
                );
            }
        }

        $this->export_directories_cache = $dirs;
        return $this->export_directories_cache;
    }

    /**
     * Check if a function is available (not disabled).
     */
    private function function_available(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = ini_get("disable_functions");
        if ($disabled === false || trim($disabled) === "") {
            return true;
        }
        $list = array_map("trim", explode(",", $disabled));
        return !in_array($name, $list, true);
    }


    /**
     * Fast-path index sort via shell exec.
     *
     * Prepends a hex-encoded sort key to each line, shells out to `sort(1)`,
     * strips the keys, and deduplicates.  This handles arbitrarily large
     * files with no PHP memory pressure.
     *
     * @param string $path         The JSONL index file to sort.
     * @param string $tmp          Temporary output path for the sorted result.
     * @return bool True if the exec-based sort succeeded (and $path was replaced).
     */
    private function try_exec_sort(string $path, string $tmp): bool
    {
        if (!$this->function_available("exec")) {
            return false;
        }

        $keyed = $path . ".keyed";
        $sorted_keyed = $path . ".keyed.sorted";
        $in = fopen($path, "r");
        $out = fopen($keyed, "w");
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            $this->audit_log("Failed to prepare keyed index file, falling back to PHP sort");
            return false;
        }
        $lines_read = 0;
        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === "") {
                continue;
            }
            $entry = $this->parse_index_line($line);
            if ($entry === null) {
                continue;
            }
            $key = bin2hex($entry["path"]);
            fwrite($out, $key . "\t" . $line . "\n");
            if (++$lines_read % 500 === 0) {
                $this->progress->tick_spinner();
            }
        }
        fclose($in);
        fclose($out);

        $cmd =
            "LC_ALL=C sort -t '\t' -k1,1 " .
            escapeshellarg($keyed) .
            " > " .
            escapeshellarg($sorted_keyed);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit_log("exec() sort failed (exit code {$code}), falling back to PHP sort");
            return false;
        }

        $sorted_in = fopen($sorted_keyed, "r");
        $sorted_out = fopen($tmp, "w");
        if (!$sorted_in || !$sorted_out) {
            if ($sorted_in) {
                fclose($sorted_in);
            }
            if ($sorted_out) {
                fclose($sorted_out);
            }
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit_log("Failed to open sorted index files, falling back to PHP sort");
            return false;
        }

        $prev_key = null;
        $lines_stripped = 0;
        while (($line = fgets($sorted_in)) !== false) {
            $pos = strpos($line, "\t");
            if ($pos === false) {
                continue;
            }
            $key = substr($line, 0, $pos);
            $data = substr($line, $pos + 1);
            if ($data === "") {
                continue;
            }
            // Deduplicate: skip entries with the same path as the previous one.
            // This handles overlapping symlink targets that index the same files.
            if ($key === $prev_key) {
                continue;
            }
            $prev_key = $key;
            fwrite($sorted_out, $data);
            if (++$lines_stripped % 500 === 0) {
                $this->progress->tick_spinner();
            }
        }
        fclose($sorted_in);
        fclose($sorted_out);
        @unlink($keyed);
        @unlink($sorted_keyed);
        if (!rename($tmp, $path)) {
            throw new RuntimeException("Failed to replace sorted index file");
        }
        return true;
    }

    /**
     * Sorts an index file by path and removes duplicate entries.
     *
     * Tries the fast path first: prepends a hex-encoded sort key to each line,
     * shells out to `sort(1)`, then strips the keys.  This handles arbitrarily
     * large files with no PHP memory pressure.  If exec() is unavailable or
     * the sort command fails, falls back to an in-memory usort() — which
     * requires roughly 5x the file size in available memory.
     *
     * Duplicates arise from overlapping symlink targets that index the same
     * files; they are removed during the final write pass.
     *
     * @param string $path The JSONL index file to sort in place.
     */
    private function sort_index_file(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (filesize($path) === 0) {
            return;
        }

        $tmp = $path . ".sorted";

        // Fast path: shell out to `sort` for O(n log n) with no memory
        // pressure.  If anything goes wrong, fall through to the pure-PHP
        // external merge sort below.
        if ($this->try_exec_sort($path, $tmp)) {
            return;
        }

        // Pure-PHP fallback: external merge sort.  Splits the file into
        // memory-sized chunks, sorts each in memory, then streams a k-way
        // merge.  Handles files of any size without exec().
        $mem_limit_raw = ini_get("memory_limit");
        $mem_limit = ($mem_limit_raw === "-1" || $mem_limit_raw === "" || $mem_limit_raw === "0")
            ? 0
            : parse_size($mem_limit_raw);
        $mem_used = memory_get_usage(true);
        $available = $mem_limit > 0
            ? (int) (($mem_limit - $mem_used) * 0.6)
            : 256 * 1024 * 1024;

        $key_extractor = function (string $line): ?string {
            $entry = $this->parse_index_line($line);
            return $entry !== null ? $entry['path'] : null;
        };
        $sorter = new ExternalMergeSort(
            $key_extractor,
            max(1024, (int) ($available * 0.8)),
            true,
            dirname($path),
        );
        $sorter->sort($path);
    }

    /**
     * Return HMAC authentication headers formatted for curl ("Name: value"),
     * or an empty array if no secret was configured.
     *
     * @param string $body The request body content whose SHA-256 hash will
     *                     be included in the HMAC signature.  For CURLFile
     *                     uploads, pass the raw file content (not the
     *                     multipart envelope); for form-encoded POST, pass
     *                     the http_build_query() output; for GET, omit or
     *                     pass empty string.
     */
    private function get_hmac_headers(string $body = ''): array
    {
        if ($this->hmac_client === null) {
            return [];
        }
        return $this->hmac_client->get_curl_headers($body);
    }

    /**
     * Reset curl-related state at the start of each HTTP request.
     */
    private function reset_curl_state(): void
    {
        $this->last_curl_errno = null;
        $this->last_curl_timeout = false;
    }

    /**
     * User-Agent strings to try during preflight, in order of preference.
     * Some WAFs block browser UAs that carry custom auth headers, so we
     * start with an honest non-browser identity and fall back to common
     * browser strings.
     */
    private const USER_AGENTS = [
        "Reprint/1.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0",
    ];

    private function get_base_headers(string $accept): array
    {
        $ua = $this->import_state()->user_agent ?? self::USER_AGENTS[0];
        return [
            "User-Agent: {$ua}",
            "Accept: {$accept}",
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Connection: keep-alive",
        ];
    }

    /**
     * Build the multipart chunk handler callback shared by both parser
     * creation sites inside fetch_streaming.
     *
     * File parts are forwarded as body data arrives so large files are written
     * to disk incrementally. Non-file parts are still accumulated until
     * complete because they are small metadata/progress JSON payloads.
     */
    private function make_chunk_handler(
        StreamingContext $context,
        &$current_chunk
    ): callable {
        return function ($event) use ($context, &$current_chunk) {
            if ($event["type"] === "body") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file") {
                    if (!$current_chunk) {
                        $current_chunk = [
                            "headers" => $headers,
                            "body_streamed" => true,
                            "started" => false,
                        ];
                    }

                    if ($context->on_chunk) {
                        $stream_headers = $headers;
                        if (!empty($current_chunk["started"])) {
                            $stream_headers["x-first-chunk"] = "0";
                        }
                        // The parser emits a separate complete event after the
                        // last body bytes, so close/index the file from there.
                        $stream_headers["x-last-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $stream_headers,
                            "body" => $event["data"],
                            // Suppresses state saves while a streamed file
                            // part body is still being written.
                            "is_streaming_body" => true,
                        ]);
                    }
                    $current_chunk["started"] = true;
                    return;
                }

                if (!$current_chunk) {
                    $current_chunk = [
                        "headers" => $headers,
                        "body" => $event["data"],
                    ];
                } else {
                    $current_chunk["body"] =
                        ($current_chunk["body"] ?? "") .
                        $event["data"];
                }
            } elseif ($event["type"] === "complete") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file" && !empty($current_chunk["body_streamed"])) {
                    if ($context->on_chunk) {
                        $close_headers = $headers;
                        $close_headers["x-first-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $close_headers,
                            "body" => "",
                            // Forces a save at every streamed file-part
                            // boundary, even if the periodic counter has not
                            // reached SAVE_STATE_EVERY_N_CHUNKS.
                            "is_streaming_close" => true,
                        ]);
                    }
                } elseif ($current_chunk) {
                    // Chunk complete - emit to handler
                    if ($context->on_chunk) {
                        ($context->on_chunk)(
                            $current_chunk,
                        );
                    }
                } elseif ($headers) {
                    // No body data - emit just headers
                    if ($context->on_chunk) {
                        ($context->on_chunk)([
                            "headers" =>
                                $headers,
                            "body" => "",
                        ]);
                    }
                }
                $current_chunk = null;
            }
        };
    }

    /**
     * Check for curl errors after curl_exec and record timeout state.
     * Throws RuntimeException on any curl error.
     */
    private function check_curl_error($ch): void
    {
        if (!curl_errno($ch)) {
            return;
        }
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $timeout_errno = defined("CURLE_OPERATION_TIMEDOUT")
            ? CURLE_OPERATION_TIMEDOUT
            : 28;
        $this->last_curl_errno = $errno;
        $this->last_curl_timeout = $errno === $timeout_errno;
        if ($this->last_curl_timeout) {
            throw new CurlTimeoutException("cURL error: {$error}");
        }
        throw new RuntimeException("cURL error ($errno): {$error}");
    }

    /**
     * Track consecutive cURL timeouts and decide whether to retry or give up.
     *
     * Compares the cursor before and after the request. If the cursor advanced
     * (we got some data before stalling), the counter resets — the stall was
     * transient and resuming makes sense. If the cursor didn't move, the
     * counter increments. After MAX_CONSECUTIVE_TIMEOUTS with no progress,
     * throws a RuntimeException so the runner sees exit code 1 and stops.
     *
     * @param string $phase   Human-readable phase name for logs (e.g. "sql_chunk")
     * @param ?string $cursor_before Cursor value at the start of the request
     * @param ?string $cursor_after  Cursor value when the timeout fired
     */
    protected function assert_can_retry_consecutive_timeout(
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        if ($cursor_after !== null && $cursor_after !== $cursor_before) {
            // Progress was made — reset the counter.
            $this->import_state()->consecutive_timeouts = 0;
        } else {
            $this->import_state()->consecutive_timeouts =
                ($this->import_state()->consecutive_timeouts ?? 0) + 1;
        }

        $count = $this->import_state()->consecutive_timeouts;

        $this->audit_log(
            "CURL TIMEOUT | {$phase} | consecutive_timeouts={$count}/" .
                self::MAX_CONSECUTIVE_TIMEOUTS .
                " | cursor_moved=" .
                ($cursor_after !== $cursor_before ? "yes" : "no"),
            true,
        );

        if ($count >= self::MAX_CONSECUTIVE_TIMEOUTS) {
            throw new RuntimeException(
                "Remote server appears unreachable: {$count} consecutive " .
                "cURL timeouts with no progress during {$phase}. Giving up.",
            );
        }
    }

    /**
     * Diagnose an HTTP error and return a user-friendly message with
     * actionable advice. Used by fetch_json() and fetch_streaming() to
     * turn opaque "HTTP 403" messages into something a non-expert can
     * act on.
     *
     * Returns ['message' => ..., 'code' => ...].
     *
     * @param int         $http_code    HTTP status code (0 for connection failures).
     * @param string|null $body         Response body (may be HTML, JSON, or empty).
     * @param string|null $redirect_url The Location header / CURLINFO_REDIRECT_URL for 3xx responses.
     */
    private function diagnose_http_error(int $http_code, ?string $body, ?string $redirect_url = null): array
    {
        $body = ($body !== null && $body !== false) ? $body : '';

        $decoded = json_decode($body, true);
        $server_msg = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        $looks_like_html = !is_array($decoded) && $body !== '' && (
            stripos($body, '<html') !== false ||
            stripos($body, '<!doctype') !== false ||
            str_starts_with($body, '<')
        );

        // ── Redirects ────────────────────────────────────────────
        if ($http_code >= 300 && $http_code < 400) {
            $msg = $redirect_url
                ? "Wrong URL. The server redirected to {$redirect_url} " .
                  "(HTTP {$http_code}).\n\n" .
                  "Reprint does not follow redirects to avoid silently " .
                  "connecting to the wrong server. Retry with the target " .
                  "URL above."
                : "Wrong URL. The server returned a redirect (HTTP {$http_code}) " .
                  "instead of the export API.\n\n" .
                  "Reprint does not follow redirects. Check whether the site " .
                  "uses http vs https or www vs non-www and retry with the " .
                  "canonical URL.";
            return ['code' => 'REDIRECT', 'message' => $msg];
        }

        // ── Authentication / authorization ───────────────────────
        if ($http_code === 401 || $http_code === 403) {
            if ($this->hmac_client === null) {
                return [
                    'code' => 'AUTH_NO_SECRET',
                    'message' =>
                        "No --secret was provided. The remote site requires " .
                        "authentication.\n\n" .
                        "Pass --secret=YOUR_SECRET using the same secret " .
                        "configured in the Site Export plugin on the remote site.",
                ];
            }

            if ($server_msg === null) {
                return [
                    'code' => 'AUTH_FAILED',
                    'message' =>
                        "The request was blocked (HTTP {$http_code}) but the " .
                        "server did not say why. The exporter plugin always " .
                        "explains authentication failures, so something " .
                        "upstream is blocking the request — a server-level " .
                        "firewall, .htaccess rule, or security plugin.",
                ];
            }

            // The server tells us exactly what went wrong. Map each known
            // HMAC error to a targeted message.

            if (str_contains($server_msg, 'HMAC signature verification failed')) {
                return [
                    'code' => 'AUTH_SECRET_MISMATCH',
                    'message' =>
                        "Wrong shared secret. The --secret value does not match " .
                        "the one configured in the Site Export plugin settings " .
                        "(wp-admin → Site Export).",
                ];
            }

            if (str_contains($server_msg, 'timestamp expired')) {
                return [
                    'code' => 'AUTH_CLOCK_SKEW',
                    'message' =>
                        "Clock out of sync. {$server_msg}\n\n" .
                        "Check this machine's clock (run `date`) and compare " .
                        "it with the server's time.",
                ];
            }

            if (str_contains($server_msg, 'Content hash mismatch')) {
                return [
                    'code' => 'AUTH_CONTENT_TAMPERED',
                    'message' =>
                        "Request body was modified in transit. A proxy, CDN, " .
                        "or firewall between this machine and the server is " .
                        "altering the request content.",
                ];
            }

            if (str_contains($server_msg, 'Missing X-Auth-')) {
                return [
                    'code' => 'AUTH_HEADERS_STRIPPED',
                    'message' =>
                        "Authentication headers were stripped. The server " .
                        "reported: {$server_msg}\n\n" .
                        "A proxy, CDN, or security plugin is removing custom " .
                        "HTTP headers before they reach WordPress.",
                ];
            }

            return [
                'code' => 'AUTH_FAILED',
                'message' => "Authentication failed: {$server_msg}",
            ];
        }

        // ── Export not configured (503 from exporter) ────────────
        if ($http_code === 503 && $server_msg !== null) {
            return [
                'code' => 'EXPORT_NOT_CONFIGURED',
                'message' =>
                    "The exporter plugin is installed but not configured. " .
                    "The server reported: {$server_msg}",
            ];
        }

        // ── Not found ────────────────────────────────────────────
        if ($http_code === 404) {
            $msg = "The exporter plugin is not installed on the remote site.";
            if ($looks_like_html) {
                $msg .= " The server returned an HTML 404 page instead of " .
                         "the export API.";
            } else {
                $msg .= " The server returned HTTP 404.";
            }
            $msg .= "\n\nRun `php reprint.phar install-exporter` for setup " .
                     "instructions.";
            return ['code' => 'NOT_FOUND', 'message' => $msg];
        }

        // ── Server errors ────────────────────────────────────────
        if ($http_code >= 500) {
            $msg = $server_msg
                ? "The remote server crashed: {$server_msg}"
                : "The remote server crashed (HTTP {$http_code}).";
            $msg .= "\n\nThis is a problem on the remote server. " .
                     "Check its PHP error log for details.";
            return ['code' => 'SERVER_ERROR', 'message' => $msg];
        }

        // ── HTML response (plugin not installed / wrong URL) ─────
        if ($looks_like_html) {
            return [
                'code' => 'HTML_RESPONSE',
                'http_code' => $http_code,
                'message' =>
                    "The exporter plugin is not installed on the remote site. " .
                    "The server returned an HTML page (HTTP {$http_code}) " .
                    "instead of a JSON API response.\n\n" .
                    "Run `php reprint.phar install-exporter` for setup " .
                    "instructions.",
            ];
        }

        // ── Fallback ─────────────────────────────────────────────
        return [
            'code' => 'HTTP_ERROR',
            'message' => $server_msg
                ? "HTTP error {$http_code}: {$server_msg}"
                : "Unexpected HTTP status {$http_code}.",
        ];
    }

    /**
     * Format a diagnosed error as a single string for display.
     * Also stores the error code on the instance for output_progress
     * and write_status_file to pick up.
     */
    private function format_diagnosed_error(array $diagnosis): string
    {
        $this->last_error_code = $diagnosis['code'];
        return $diagnosis['message'];
    }

    /**
     * Fetch a JSON response for a lightweight request (non-streaming).
     */
    private function fetch_json(string $url): array
    {
        $this->reset_curl_state();

        $this->audit_log("HTTP_REQUEST | GET | {$url}", false);

        $ch = curl_init($url);
        reprint_apply_curl_proxy_from_env($ch);
        reprint_apply_curl_ca_bundle($ch);

        $headers = [
            ...$this->get_base_headers("application/json"),
            ...($this->get_hmac_headers()),
        ];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            // Bound the connect phase separately from the total timeout: a
            // stalled TCP connect would otherwise consume the whole 30s
            // budget with no connection ever established. No server
            // legitimately takes 10s just to accept a connection, so a
            // connect failure here is fast and retryable.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->progress->tick_spinner();
                    return 0;
                },
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = microtime(true) - $start;

        try {
            $this->check_curl_error($ch);
        } catch (RuntimeException $e) {
            @curl_close($ch);
            return [
                "ok" => false,
                "http_code" => 0,
                "elapsed" => $elapsed,
                "body" => null,
                "json" => null,
                "error" => $e->getMessage(),
                "curl_errno" => $this->last_curl_errno,
                "timeout" => $this->last_curl_timeout,
            ];
        }

        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        @curl_close($ch);

        if ($http_code !== 200) {
            $diagnosis = $this->diagnose_http_error($http_code, $body, $redirect_url);
            return [
                "ok" => false,
                "http_code" => $http_code,
                "elapsed" => $elapsed,
                "body" => $body,
                "json" => null,
                "error" => $this->format_diagnosed_error($diagnosis),
                "error_code" => $diagnosis['code'],
            ];
        }

        $json = null;
        $json_error = null;
        $error_code = null;
        if ($body !== false && $body !== "") {
            $json = json_decode($body, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                // HTTP 200 but body isn't valid JSON — likely an HTML page
                // from a site that doesn't have the exporter installed.
                $diagnosis = $this->diagnose_http_error(200, $body);
                if ($diagnosis['code'] === 'HTML_RESPONSE') {
                    $json_error = $this->format_diagnosed_error($diagnosis);
                    $error_code = $diagnosis['code'];
                } else {
                    $json_error = "Invalid JSON: " . json_last_error_msg();
                    $error_code = 'INVALID_JSON';
                }
            }
        }

        return [
            "ok" => $json_error === null,
            "http_code" => $http_code,
            "elapsed" => $elapsed,
            "body" => $body,
            "json" => $json,
            "error" => $json_error,
            "error_code" => $error_code,
        ];
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $this->reset_curl_state();

        // Log HTTP request details
        $log_parts = ["HTTP_REQUEST", $post_data ? "POST" : "GET", $url];

        if ($post_data && isset($post_data["file_list"])) {
            $file_list_part = $post_data["file_list"];
            if ($file_list_part instanceof CURLFile) {
                $upload_path = $file_list_part->getFilename();
                $upload_size = is_string($upload_path)
                    ? filesize($upload_path)
                    : false;
                $upload_size = $upload_size === false ? 0 : $upload_size;
                $log_parts[] = "file_list_file=" . $upload_size . "b";
            } else {
                $log_parts[] =
                    "file_list=" . strlen((string) $file_list_part) . "b";
            }
        }

        $this->audit_log(implode(" | ", $log_parts), false);

        $ch = curl_init($url);
        reprint_apply_curl_proxy_from_env($ch);
        reprint_apply_curl_ca_bundle($ch);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        // Build headers to look like a real browser
        $headers = [
            ...$this->get_base_headers("text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        // Configure POST data if provided.  We need to know the body
        // content BEFORE generating HMAC headers so the content hash
        // can be included in the signature.
        $body_for_signing = '';
        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $has_file = false;
            foreach ($post_data as $value) {
                if ($value instanceof CURLFile) {
                    $has_file = true;
                    break;
                }
            }
            if ($has_file) {
                // For CURLFile uploads, sign the raw file content — this
                // is the logical payload the server will receive, even
                // though curl wraps it in multipart framing.
                foreach ($post_data as $value) {
                    if ($value instanceof CURLFile) {
                        $body_for_signing .= file_get_contents(
                            $value->getFilename(),
                        );
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                $body_for_signing = http_build_query($post_data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body_for_signing);
            }
        }

        // Append HMAC auth headers now that we know the body content
        array_push($headers, ...($this->get_hmac_headers($body_for_signing)));

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            // Don't cap total transfer time — streaming responses can
            // legitimately run for 20+ minutes. Instead, detect stalled
            // connections: timeout only when fewer than 1 byte/sec is
            // received for 300 consecutive seconds.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_ENCODING => "gzip, deflate",
            // Tick the spinner during transfers. curl calls this roughly
            // once per second even when no data is flowing, which keeps
            // the Braille spinner rotating so it looks alive.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->progress->tick_spinner();
                    return 0; // 0 = continue, non-zero = abort
                },
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header_line) use (
                &$parser,
                $context,
                &$current_chunk
            ) {
                $len = strlen($header_line);

                // Parse Content-Type to extract boundary
                if (stripos($header_line, "Content-Type:") === 0) {
                    // Find boundary parameter
                    $pos = stripos($header_line, "boundary=");
                    if ($pos !== false) {
                        $boundary_start = $pos + 9; // length of 'boundary='
                        $boundary_value = substr($header_line, $boundary_start);
                        $boundary_value = trim($boundary_value);

                        // Remove quotes if present
                        if ($boundary_value[0] === '"') {
                            $quote_end = strpos($boundary_value, '"', 1);
                            if ($quote_end !== false) {
                                $boundary_value = substr(
                                    $boundary_value,
                                    1,
                                    $quote_end - 1,
                                );
                            }
                        } else {
                            // Find end (semicolon, comma, or whitespace)
                            $end_pos = strcspn($boundary_value, ";,\r\n \t");
                            $boundary_value = substr(
                                $boundary_value,
                                0,
                                $end_pos,
                            );
                        }

                        if ($boundary_value !== "") {
                            $this->audit_log(
                                "Creating multipart parser with boundary: $boundary_value",
                                false,
                            );
                            $parser = new \Reprint\Importer\Protocol\MultipartStreamParser(
                                $boundary_value,
                                $this->make_chunk_handler($context, $current_chunk),
                            );
                        }
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$parser,
                &$current_chunk,
                $context,
                &$bytes_received,
                &$last_heartbeat,
                &$last_progress_check,
                &$last_bytes_received,
                &$error_body
            ) {
                // If no parser yet, we might be receiving an error response
                if (!$parser) {
                    $error_body .= $data;
                    if (strlen($error_body) > 65536) {
                        $error_body = substr($error_body, -65536);
                    }

                    // Strict fallback: if body starts with a boundary line, parse it.
                    if (strncmp($error_body, "--boundary-", 11) === 0) {
                        $line_end = strpos($error_body, "\n");
                        if ($line_end !== false) {
                            $line = rtrim(substr($error_body, 0, $line_end), "\r\n");
                            if (strncmp($line, "--boundary-", 11) === 0) {
                                $boundary = substr($line, 2);
                                if ($boundary !== "") {
                                    $this->audit_log(
                                        "Detected boundary in body (no Content-Type): {$boundary}",
                                        false,
                                    );
                                    $parser = new \Reprint\Importer\Protocol\MultipartStreamParser(
                                        $boundary,
                                        $this->make_chunk_handler($context, $current_chunk),
                                    );
                                    $parser->feed($error_body);
                                    $error_body = "";
                                }
                            }
                        }
                    }

                    static $logged_no_parser = false;
                    if (!$logged_no_parser && strlen($error_body) > 0) {
                        $this->audit_log(
                            "No parser, accumulating error body (first 500 chars): " .
                                substr($error_body, 0, 500),
                            false,
                        );
                        $logged_no_parser = true;
                    }
                }

                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);

                // Check for stuck/slow transfer every 5 seconds
                $now = microtime(true);
                if ($now - $last_progress_check >= 5.0) {
                    $bytes_since_check = $bytes_received - $last_bytes_received;
                    $rate = $bytes_since_check / 5.0; // bytes per second

                    // Only output progress_check in verbose mode or non-TTY
                    if ($this->verbose_mode || !$this->is_tty) {
                        fwrite($this->progress_fd, json_encode([
                            "progress_check" => true,
                            "bytes_received" => $bytes_received,
                            "bytes_last_5s" => $bytes_since_check,
                            "rate_bps" => round($rate),
                        ]) . "\n");
                    }

                    // If we're receiving less than 1KB/s for 5 seconds, something is wrong
                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        $this->audit_log(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                            false,
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                // Output heartbeat every second (only in verbose/non-TTY mode)
                if ($now - $last_heartbeat >= 1.0) {
                    if ($this->verbose_mode || !$this->is_tty) {
                        $heartbeat = [
                            "heartbeat" => true,
                            "bytes_received" => $bytes_received,
                        ];
                        // Only emit file counters when the download list has
                        // been counted (fetch phase).  During indexing the
                        // list doesn't exist yet and emitting files_done:0
                        // without files_total confuses consumers.
                        if ($this->download_list_total !== null) {
                            $heartbeat["files_done"] =
                                ($this->download_list_done ?? 0) + $this->files_imported;
                            $heartbeat["files_total"] = $this->download_list_total;
                        }
                        fwrite($this->progress_fd, json_encode($heartbeat) . "\n");
                    }
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        $this->audit_log("Executing curl request...", false);
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        $this->audit_log(
            "curl_exec completed, result=" .
                ($result === false ? "false" : "true"),
            false,
        );

        try {
            try {
                $this->check_curl_error($ch);
            } catch (RuntimeException $curl_error) {
                if ($endpoint !== null) {
                    $this->handle_tuner_error($endpoint, [
                        "http_code" => 0,
                        "timeout" => $this->last_curl_timeout,
                        "curl_errno" => $this->last_curl_errno,
                    ]);
                }
                throw $curl_error;
            }

            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $total_time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        } finally {
            @curl_close($ch);
        }

        if (!isset($context->response_stats) || !is_array($context->response_stats)) {
            $context->response_stats = [];
        }
        $context->response_stats["ttfb"] = $ttfb;
        $context->response_stats["total_time"] = $total_time;

        if ($http_code !== 200) {
            if ($endpoint !== null) {
                $this->handle_tuner_error($endpoint, [
                    "http_code" => $http_code,
                    "timeout" => false,
                    "curl_errno" => 0,
                ]);
            }

            // Log what we received
            $this->audit_log(
                "HTTP error {$http_code} | error_body length: " .
                    strlen($error_body),
                true,
            );

            $diagnosis = $this->diagnose_http_error($http_code, $error_body, $redirect_url);
            $error_msg = $this->format_diagnosed_error($diagnosis);

            // Append stack trace from the server if available.
            if ($error_body) {
                $error_data = json_decode($error_body, true);
                if (is_array($error_data) && isset($error_data["trace"])) {
                    $error_msg .= "\n\nServer stack trace:\n" . $error_data["trace"];
                }
            }

            throw new RuntimeException($error_msg);
        }

        if (!$parser) {
            $snippet = $error_body ? substr($error_body, 0, 500) : "";
            throw new RuntimeException(
                "Invalid response: missing multipart boundary. " .
                    ($snippet !== "" ? "Body: {$snippet}" : ""),
            );
        }

        if (!$context->saw_completion) {
            throw new RuntimeException(
                "Invalid response: missing completion chunk from server.",
            );
        }
    }

    /**
     * Return the default compact state structure.
     */
    /**
     * Reset state to defaults while preserving cross-command data like
     * preflight results, version, and follow_symlinks.
     */
    private function reset_state(): void
    {
        $preflight = $this->import_state()->preflight ?? null;
        $version = $this->import_state()->version ?? null;
        $webhost = $this->import_state()->webhost ?? null;
        $follow = $this->import_state()->follow_symlinks ?? false;
        $nonempty = $this->import_state()->fs_root_nonempty_behavior ?? "error";
        $max_packet = $this->import_state()->max_allowed_packet ?? null;
        $files_remap_fingerprint = $this->import_state()->files_remap_fingerprint ?? null;
        $pull = $this->import_state()->pull_pipeline ?? null;
        $this->state = ImportState::from_array($this->default_state());
        $this->import_state()->preflight = $preflight;
        $this->import_state()->version = $version;
        $this->import_state()->webhost = $webhost;
        $this->import_state()->follow_symlinks = $follow;
        $this->import_state()->fs_root_nonempty_behavior = $nonempty;
        $this->import_state()->max_allowed_packet = $max_packet;
        $this->import_state()->files_remap_fingerprint = $files_remap_fingerprint;
        if ($pull !== null) {
            $this->import_state()->pull_pipeline = $pull;
        }
    }

    public function default_state(): array
    {
        return [
            // Resume checkpoint for the lower-level command currently being
            // run directly or as a stage inside a pull pipeline.
            "active_resumable_command" => [
                "command_name" => null,
                "completion_state" => null,
                "current_stage" => null,
                "remote_cursor" => null,
            ],
            "preflight" => null,
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "webhost" => null,
            "follow_symlinks" => true,
            "symlink_bundle_directory_fingerprint" => null,
            "fs_root_nonempty_behavior" => "error",
            "filter" => "none",
            "user_agent" => null,
            "max_allowed_packet" => null,
            "files_remap_fingerprint" => null,
            "files_pull_only_fingerprint" => null,
            "files_pull_summary" => [
                "files_pulled" => 0,
            ],
            "db_index" => [
                "file" => null,
                "tables" => 0,
                "rows_estimated" => 0,
                "bytes" => 0,
                "updated_at" => null,
            ],
            "diff" => [
                "remote_offset" => 0,
                "local_after" => null,
            ],
            "index" => [
                "cursor" => null,
            ],
            "fetch" => [
                "offset" => 0,
                "next_offset" => 0,
                "batch_file" => null,
                "cursor" => null,
                "batch_entries" => 0,
            ],
            "fetch_skipped" => [
                "offset" => 0,
                "next_offset" => 0,
                "batch_file" => null,
                "cursor" => null,
                "batch_entries" => 0,
            ],
            // Crash recovery: track in-progress file downloads
            // If we crash mid-write, we can truncate to the expected size on resume
            "current_file" => null,        // Path to file being written
            "current_file_bytes" => null,  // Expected bytes written so far
            // Crash recovery: track SQL file size
            "sql_bytes" => null,           // Expected SQL file size
            // SQL streaming progress for user-facing statement counts.
            "sql_statements_counted" => 0,
            // db-apply state
            "apply" => [
                "statements_executed" => 0,
                "bytes_read" => 0,
                "rewrite_url" => null,
                // Target database configuration — persisted by db-apply
                // so that apply-runtime can generate DB_* constants.
                "target_engine" => null,
                "target_db" => null,
                "target_host" => null,
                "target_port" => null,
                "target_user" => null,
                "target_pass" => null,
                "target_sqlite_path" => null,
                "remote_paths_removed_from_local_site" => [],
            ],
            // SQL output mode (file, stdout, mysql) — persisted for resume
            "sql_output" => null,
            // MySQL connection parameters — persisted for resume (password excluded)
            "mysql_host" => null,
            "mysql_port" => null,
            "mysql_user" => null,
            "mysql_database" => null,
            // Consecutive cURL timeout counter — tracks how many times in a
            // row a timeout fired without the cursor advancing. After
            // MAX_CONSECUTIVE_TIMEOUTS with no progress, the importer gives
            // up instead of retrying forever.
            "consecutive_timeouts" => 0,
            // Adaptive tuning state/config
            "tuning" => [
                "config" => [],
                "state" => [],
            ],
            // Resume checkpoint for the user-facing pull pipeline command
            // being orchestrated.
            "pull_pipeline" => [
                "started_by_command" => null,
                "stage_sequence" => [],
                "last_completed_stage" => null,
                "files_filter" => null,
                "skipped_pending" => false,
                "has_completed_once" => false,
            ],
        ];
    }


    /** Return the in-process typed state, hydrating legacy arrays at the boundary. */
    private function import_state(): ImportState
    {
        if (!$this->state instanceof ImportState) {
            $this->state = ImportState::from_array($this->state_to_array($this->state));
        }
        return $this->state;
    }

    /** Convert either the typed state object or a legacy array to the persisted schema. */
    private function state_to_array($state): array
    {
        if ($state instanceof ImportState) {
            return $state->to_array();
        }
        return is_array($state) ? $state : $this->default_state();
    }

    /**
     * Normalize state array to the compact schema.
     */
    private function normalize_state(array $state): array
    {
        $defaults = $this->default_state();
        $state = array_intersect_key($state, $defaults);
        $state = array_merge($defaults, $state);
        $active_resumable_command = $state["active_resumable_command"] ?? [];
        if (!is_array($active_resumable_command)) {
            $active_resumable_command = [];
        }
        $active_resumable_command = array_intersect_key(
            $active_resumable_command,
            $defaults["active_resumable_command"],
        );
        $state["active_resumable_command"] = array_merge(
            $defaults["active_resumable_command"],
            $active_resumable_command,
        );
        $diff = $state["diff"];
        if (!is_array($diff)) {
            $diff = [];
        }
        $diff = array_intersect_key($diff, $defaults["diff"]);
        $state["diff"] = array_merge($defaults["diff"], $diff);
        $index = $state["index"] ?? [];
        if (!is_array($index)) {
            $index = [];
        }
        $index = array_intersect_key($index, $defaults["index"]);
        $state["index"] = array_merge($defaults["index"], $index);
        $fetch = $state["fetch"] ?? [];
        if (!is_array($fetch)) {
            $fetch = [];
        }
        $fetch = array_intersect_key($fetch, $defaults["fetch"]);
        $state["fetch"] = array_merge($defaults["fetch"], $fetch);
        $files_pull_summary = $state["files_pull_summary"] ?? [];
        if (!is_array($files_pull_summary)) {
            $files_pull_summary = [];
        }
        $files_pull_summary = array_intersect_key(
            $files_pull_summary,
            $defaults["files_pull_summary"],
        );
        $state["files_pull_summary"] = array_merge(
            $defaults["files_pull_summary"],
            $files_pull_summary,
        );
        $tuning = $state["tuning"] ?? [];
        if (!is_array($tuning)) {
            $tuning = [];
        }
        $tuning = array_intersect_key($tuning, $defaults["tuning"]);
        $tuning = array_merge($defaults["tuning"], $tuning);
        $state["tuning"] = $tuning;
        $index_db = $state["db_index"] ?? [];
        if (!is_array($index_db)) {
            $index_db = [];
        }
        $index_db = array_intersect_key(
            $index_db,
            $defaults["db_index"],
        );
        $index_db = array_merge(
            $defaults["db_index"],
            $index_db,
        );
        $state["db_index"] = $index_db;
        $apply = $state["apply"] ?? [];
        if (!is_array($apply)) {
            $apply = [];
        }
        $apply = array_intersect_key($apply, $defaults["apply"]);
        $state["apply"] = array_merge($defaults["apply"], $apply);
        $pull = $state["pull_pipeline"] ?? [];
        if (!is_array($pull)) {
            $pull = [];
        }
        $pull = array_intersect_key($pull, $defaults["pull_pipeline"]);
        $state["pull_pipeline"] = array_merge($defaults["pull_pipeline"], $pull);
        if (!is_array($state["pull_pipeline"]["stage_sequence"])) {
            $state["pull_pipeline"]["stage_sequence"] = [];
        }
        return $state;
    }

    /**
     * Encode state path fields as base64 to make JSON persistence byte-safe.
     */
    private function encode_state_paths(array $state): array
    {
        $state["diff"]["local_after"] = $this->encode_state_path_value(
            $state["diff"]["local_after"] ?? null,
        );
        $state["fetch"]["batch_file"] = $this->encode_state_path_value(
            $state["fetch"]["batch_file"] ?? null,
        );
        $state["current_file"] = $this->encode_state_path_value(
            $state["current_file"] ?? null,
        );
        $state["db_index"]["file"] = $this->encode_state_path_value(
            $state["db_index"]["file"] ?? null,
        );

        if (
            isset($state["preflight"]) &&
            is_array($state["preflight"]) &&
            isset($state["preflight"]["data"]) &&
            is_array($state["preflight"]["data"])
        ) {
            $state["preflight"]["data"] = $this->encode_preflight_data_paths(
                $state["preflight"]["data"],
            );
        }

        return $state;
    }

    /**
     * Decode base64-encoded path fields in state after loading.
     *
     * Supports legacy plain-string fields for backward compatibility.
     */
    private function decode_state_paths(array $state): array
    {
        $state["diff"]["local_after"] = $this->decode_state_path_value(
            $state["diff"]["local_after"] ?? null,
        );
        $state["fetch"]["batch_file"] = $this->decode_state_path_value(
            $state["fetch"]["batch_file"] ?? null,
        );
        $state["current_file"] = $this->decode_state_path_value(
            $state["current_file"] ?? null,
        );
        $state["db_index"]["file"] = $this->decode_state_path_value(
            $state["db_index"]["file"] ?? null,
        );

        if (
            isset($state["preflight"]) &&
            is_array($state["preflight"]) &&
            isset($state["preflight"]["data"]) &&
            is_array($state["preflight"]["data"])
        ) {
            $state["preflight"]["data"] = $this->decode_preflight_data_paths(
                $state["preflight"]["data"],
            );
        }

        return $state;
    }

    /**
     * Encode preflight path fields.
     */
    private function encode_preflight_data_paths(array $data): array
    {
        if (isset($data["wp_detect"]["searched"]) && is_array($data["wp_detect"]["searched"])) {
            foreach ($data["wp_detect"]["searched"] as $idx => $path) {
                $data["wp_detect"]["searched"][$idx] = $this->encode_state_path_value($path);
            }
        }

        if (isset($data["wp_detect"]["roots"]) && is_array($data["wp_detect"]["roots"])) {
            foreach ($data["wp_detect"]["roots"] as $idx => $root) {
                if (!is_array($root)) {
                    continue;
                }
                foreach (["path", "wp_load_path", "wp_config_path"] as $key) {
                    if (array_key_exists($key, $root)) {
                        $data["wp_detect"]["roots"][$idx][$key] = $this->encode_state_path_value($root[$key]);
                    }
                }
            }
        }

        if (isset($data["runtime"]) && is_array($data["runtime"])) {
            foreach (["temp_dir", "document_root", "script_filename", "cwd"] as $key) {
                if (array_key_exists($key, $data["runtime"])) {
                    $data["runtime"][$key] = $this->encode_state_path_value($data["runtime"][$key]);
                }
            }
        }

        if (isset($data["filesystem"]["directories"]) && is_array($data["filesystem"]["directories"])) {
            foreach ($data["filesystem"]["directories"] as $idx => $dir_entry) {
                if (!is_array($dir_entry) || !array_key_exists("path", $dir_entry)) {
                    continue;
                }
                $data["filesystem"]["directories"][$idx]["path"] = $this->encode_state_path_value($dir_entry["path"]);
            }
        }

        if (isset($data["htaccess"]["files"]) && is_array($data["htaccess"]["files"])) {
            foreach ($data["htaccess"]["files"] as $idx => $file_entry) {
                if (!is_array($file_entry) || !array_key_exists("path", $file_entry)) {
                    continue;
                }
                $data["htaccess"]["files"][$idx]["path"] = $this->encode_state_path_value($file_entry["path"]);
            }
        }

        if (isset($data["wp_content"]["roots"]) && is_array($data["wp_content"]["roots"])) {
            foreach ($data["wp_content"]["roots"] as $idx => $root_entry) {
                if (!is_array($root_entry)) {
                    continue;
                }
                foreach (["root", "content_dir"] as $key) {
                    if (array_key_exists($key, $root_entry)) {
                        $data["wp_content"]["roots"][$idx][$key] = $this->encode_state_path_value($root_entry[$key]);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Decode preflight path fields.
     */
    private function decode_preflight_data_paths(array $data): array
    {
        if (isset($data["wp_detect"]["searched"]) && is_array($data["wp_detect"]["searched"])) {
            foreach ($data["wp_detect"]["searched"] as $idx => $path) {
                $data["wp_detect"]["searched"][$idx] = $this->decode_state_path_value($path);
            }
        }

        if (isset($data["wp_detect"]["roots"]) && is_array($data["wp_detect"]["roots"])) {
            foreach ($data["wp_detect"]["roots"] as $idx => $root) {
                if (!is_array($root)) {
                    continue;
                }
                foreach (["path", "wp_load_path", "wp_config_path"] as $key) {
                    if (array_key_exists($key, $root)) {
                        $data["wp_detect"]["roots"][$idx][$key] = $this->decode_state_path_value($root[$key]);
                    }
                }
            }
        }

        if (isset($data["runtime"]) && is_array($data["runtime"])) {
            foreach (["temp_dir", "document_root", "script_filename", "cwd"] as $key) {
                if (array_key_exists($key, $data["runtime"])) {
                    $data["runtime"][$key] = $this->decode_state_path_value($data["runtime"][$key]);
                }
            }
        }

        if (isset($data["filesystem"]["directories"]) && is_array($data["filesystem"]["directories"])) {
            foreach ($data["filesystem"]["directories"] as $idx => $dir_entry) {
                if (!is_array($dir_entry) || !array_key_exists("path", $dir_entry)) {
                    continue;
                }
                $data["filesystem"]["directories"][$idx]["path"] = $this->decode_state_path_value($dir_entry["path"]);
            }
        }

        if (isset($data["htaccess"]["files"]) && is_array($data["htaccess"]["files"])) {
            foreach ($data["htaccess"]["files"] as $idx => $file_entry) {
                if (!is_array($file_entry) || !array_key_exists("path", $file_entry)) {
                    continue;
                }
                $data["htaccess"]["files"][$idx]["path"] = $this->decode_state_path_value($file_entry["path"]);
            }
        }

        if (isset($data["wp_content"]["roots"]) && is_array($data["wp_content"]["roots"])) {
            foreach ($data["wp_content"]["roots"] as $idx => $root_entry) {
                if (!is_array($root_entry)) {
                    continue;
                }
                foreach (["root", "content_dir"] as $key) {
                    if (array_key_exists($key, $root_entry)) {
                        $data["wp_content"]["roots"][$idx][$key] = $this->decode_state_path_value($root_entry[$key]);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function encode_state_path_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        return self::STATE_PATH_ENCODING_PREFIX . base64_encode($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function decode_state_path_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        if (!str_starts_with($value, self::STATE_PATH_ENCODING_PREFIX)) {
            return $value;
        }
        $encoded = substr($value, strlen(self::STATE_PATH_ENCODING_PREFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $this->audit_log(
                "Warning: invalid base64-encoded state path; resetting field",
                true,
            );
            return null;
        }
        return $decoded;
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
    {
        if (!file_exists($this->state_file)) {
            return $this->default_state();
        }

        $contents = file_get_contents($this->state_file);
        if ($contents === false) {
            return $this->default_state();
        }

        $state = json_decode($contents, true);
        if (!is_array($state)) {
            $this->audit_log(
                "Warning: corrupt state file detected, renaming and starting fresh",
                true,
            );
            $corrupt_name = $this->state_file . ".corrupt." . time();
            @rename($this->state_file, $corrupt_name);
            return $this->default_state();
        }

        $state = $this->normalize_state($state);
        $state = $this->decode_state_paths($state);

        return $state;
    }

    /**
     * Save import state to disk.
     *
     * Uses atomic write (temp file + rename) to prevent corruption if
     * the process is killed mid-write.
     */
    private function save_state($state): void
    {
        // Keep the spinner alive between curl requests. save_state is
        // called frequently during streaming operations, so this fills
        // the gaps where curl's progress callback doesn't fire.
        $this->progress->tick_spinner();

        $state = $this->state_to_array($state);
        if ($this->tuner instanceof AdaptiveTuner) {
            $state["tuning"] = [
                "config" => $this->tuner->get_config(),
                "state" => $this->tuner->get_state(),
            ];
        }
        $state = $this->normalize_state($state);
        $state = $this->encode_state_paths($state);

        // Write to temp file first, then atomic rename
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException("Failed to encode state: " . json_last_error_msg());
        }
        $tmp_file = $this->state_file . '.tmp';
        $bytes = file_put_contents($tmp_file, $json);
        if ($bytes === false) {
            throw new RuntimeException("Failed to write state file: $tmp_file (disk full?)");
        }
        if (!rename($tmp_file, $this->state_file)) {
            throw new RuntimeException("Failed to rename state file: $tmp_file -> {$this->state_file}");
        }

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $has_cursor =
            !empty($state["active_resumable_command"]["remote_cursor"] ?? null) ||
            !empty($state["index"]["cursor"] ?? null) ||
            !empty($state["fetch"]["cursor"] ?? null);
        $cursor_info = $has_cursor ? "cursor=saved" : "cursor=none";

        $this->audit_log(
            sprintf(
                "SAVE CURSOR | total_indexed=%d | completed_this_run=%d | %s",
                $indexed,
                $files_imported,
                $cursor_info,
            ),
            false,
        );

        $this->write_status_file();
    }

    /**
     * Write a flat status file for external consumers (e.g. web UI polling).
     *
     * Derives a simple JSON object from the current state and pipeline
     * position. Written atomically via temp file + rename so readers
     * never see a partial write.
     */
    public function write_status_file(?string $error = null): void
    {
        $state = $this->state instanceof ImportState
            ? $this->state
            : ImportState::from_array($this->state_to_array($this->state));
        $command = $state->active_resumable_command->command_name;
        $status = $error !== null ? "error" : ($state->active_resumable_command->completion_state ?? "in_progress");

        // Derive phase from the state's stage field
        $phase = $state->active_resumable_command->current_stage;

        $payload = [
            "step" => $this->pipeline_step,
            "steps" => $this->pipeline_steps,
            "command" => $command,
            "status" => $status,
            "phase" => $phase,
            "error" => $error,
            "error_code" => $error !== null ? $this->last_error_code : null,
            "ts" => microtime(true),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return; // Best-effort — don't crash the import over a status file
        }
        $tmp = $this->status_file . ".tmp";
        if (file_put_contents($tmp, $json) !== false) {
            rename($tmp, $this->status_file);
        }
    }

    /**
     * Handle shutdown signals (SIGINT, SIGTERM).
     * Saves state before exiting.
     */
    public function handle_shutdown(int $signal): void
    {
        // Prevent multiple signal handling
        static $already_shutting_down = false;
        if ($already_shutting_down) {
            // Force kill on second signal
            if (
                function_exists("posix_kill") &&
                function_exists("posix_getpid")
            ) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            die("\nForced exit.\n");
        }
        $already_shutting_down = true;

        $this->shutdown_requested = true;
        $this->progress->clear_progress_line();

        // Flush index updates so progress is not lost on interrupt
        try {
            $this->finalize_index_updates();
        } catch (Exception $e) {
            $this->audit_log(
                "Failed to finalize index updates on shutdown: " .
                    $e->getMessage(),
                true,
            );
        }

        // Log final progress before exit
        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Files completed in this run
        $current_command = $this->import_state()->active_resumable_command->command_name ?? "unknown";

        $this->audit_log(
            sprintf(
                "SHUTDOWN REQUESTED | command=%s | total_indexed=%d files | completed_this_run=%d files",
                $current_command,
                $indexed,
                $files_imported,
            ),
            true,
        );

        $this->progress->show_lifecycle_line("\nInterrupted - saving state...\n");
        $this->progress->show_lifecycle_line("  Command: {$current_command}\n");
        $this->progress->show_lifecycle_line("  Total files indexed: {$indexed}\n");
        $this->progress->show_lifecycle_line("  Files completed in this run: {$files_imported}\n");
        $this->output_progress([
            "type" => "interrupt",
            "command" => $current_command,
            "files_indexed" => $indexed,
            "files_completed" => $files_imported,
            "message" => "Interrupted - saving state...",
        ], true);

        // Save current state (with timeout protection)
        try {
            $this->save_state($this->state);
            $this->progress->show_lifecycle_line("✓ State saved successfully\n");
            $this->output_progress([
                "type" => "state_saved",
                "message" => "State saved successfully",
            ], true);
        } catch (Exception $e) {
            fwrite($this->progress_fd, "Warning: Failed to save state: " . $e->getMessage() . "\n");
        }

        $this->progress->show_lifecycle_line("Exiting...\n");

        // CRITICAL: Use SIGKILL for immediate termination
        // Regular exit() hangs because PHP's shutdown sequence tries to
        // close the curl handle gracefully, which blocks waiting for server.
        // curl_close() also hangs when called during an active curl_exec().
        // SIGKILL bypasses all cleanup and terminates at OS level immediately.
        if (function_exists("posix_kill") && function_exists("posix_getpid")) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Fallback if posix functions not available
        die();
    }

    /**
     * Output progress as JSON line.
     * Only outputs in verbose mode or non-TTY mode (for programmatic consumption).
     *
     * @param array $data Progress data to output
     * @param bool $force Force output regardless of throttle
     */
    public function output_progress(array $data, bool $force = false): void
    {
        // In TTY non-verbose mode, suppress JSON output (use show_progress_line instead)
        if ($this->is_tty && !$this->verbose_mode) {
            return;
        }

        $now = microtime(true);

        // Always output status changes
        $is_status_change =
            isset($data["status"]) &&
            in_array($data["status"], ["starting", "complete", "error"]);

        // Output if forced, status change, or throttle time passed
        if (
            $force ||
            $is_status_change ||
            $now - $this->last_progress_output >= $this->progress_throttle
        ) {
            $written = @fwrite($this->progress_fd, json_encode($data) . "\n");
            if ($written === false) {
                // Broken pipe — save state and exit cleanly
                $this->save_state($this->state);
                exit(0);
            }
            @flush();
            $this->last_progress_output = $now;
        }
    }
}

/**
 * Context object passed to streaming callbacks.
 */
class StreamingContext
{
    public $on_chunk = null;
    public $file_handle = null;
    public $file_path = null;
    public $file_ctime = null;
    public $filesystem_root = null;
    public $chunk_fingerprints = [];
    public $need_client_slice = false;
    public $next_client_offset = 0;
    // Crash recovery: track bytes written for current file
    public $file_bytes_written = 0;
    // Last response stats from completion chunk
    public $response_stats = [];
    // Stream integrity
    public $saw_completion = false;
    // When true, skip writing the current file (preserve-local mode)
    public $skip_current_file = false;
}

/**
 * Thrown by ensure_directory_path() in preserve-local mode when a directory
 * component is not writable or a symlink blocks directory creation.
 * Callers catch this to skip the current file/directory/symlink gracefully.
 */
class PreserveLocalSkipException extends RuntimeException {}

/**
 * Thrown when a cURL request times out (CURLE_OPERATION_TIMEDOUT).
 * Callers catch this to save state and exit with "partial" status instead
 * of crashing with a fatal error — the next invocation resumes from the
 * last saved cursor.
 */
class CurlTimeoutException extends RuntimeException {}

// ============================================================================
// CLI Entry Point
// ============================================================================

// Returns the importer version string. Inside the phar, reads the baked-in
// VERSION file. In development, falls back to `git describe`.
function get_importer_version(): string {
    // When running from the phar, the VERSION file is baked in at build time.
    $version_file = __DIR__ . '/VERSION';
    if (file_exists($version_file)) {
        return trim(file_get_contents($version_file));
    }

    // Development fallback: derive from git.
    $tag = trim(shell_exec('git describe --exact-match --tags HEAD 2>/dev/null') ?: '');
    if ($tag !== '') {
        return $tag;
    }
    $latest = trim(shell_exec("git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1") ?: '');
    return ($latest !== '' ? $latest : 'v0.0.0') . '-trunk';
}

// Only run CLI logic if this file is executed directly (not included/required).
// IMPORTER_PHAR_ENTRY is defined by the phar stub and IMPORTER_WRAPPER_ENTRY is
// defined by the repo/package wrapper scripts, so the guard also passes when
// running as `php reprint.phar`, `php importer/import.php`, or the Composer bin.
if (
    PHP_SAPI === "cli" &&
    isset($argv) &&
    (
        realpath($argv[0] ?? "") === __FILE__ ||
        defined('IMPORTER_PHAR_ENTRY') ||
        defined('IMPORTER_WRAPPER_ENTRY')
    )
) {
    // Handle --version before anything else.
    if (isset($argv[1]) && in_array($argv[1], ["--version", "-V"])) {
        echo get_importer_version() . "\n";
        exit(0);
    }

    // ================================================================
    // CLI option definitions — single source of truth.
    //
    // The argument parser and help renderer both read from this array.
    // Adding a new option here automatically includes it in --help;
    // removing it here removes it from both parsing and help.
    //
    // Fields:
    //   name           --name without the dashes (required)
    //   type           'value'         --name=VAL
    //                  'flag'          --name (sets a boolean)
    //                  'value-or-next' --name=VAL or --name VAL
    //                  'pair'          --name A B (repeatable, takes 2 args)
    //   target         Where to store the parsed value:
    //                  'state_dir' | 'fs_root' → special local variables
    //                  'key'                   → $options['key']
    //                  'tuning_config.key'     → $options['tuning_config']['key']
    //   help           Description for --help output (null = hidden)
    //   help_section   'required' | 'global' → controls main --help grouping
    //                  null → not shown in main --help
    //   commands       Array of command names for per-command --help display
    //   placeholder    Value placeholder in help, e.g. 'DIR' (value types)
    //   short          Single-char alias, e.g. 'v' for -v (flag types)
    //   aliases        Array of alternative --names (hidden from help)
    //   repeatable     Append each value to target instead of replacing it
    //   cast           'int' | 'float' | 'size' (default: string)
    //   flag_value     What to store for flag types (default: true)
    //   valid_values   Array of allowed values (enforced at parse time)
    //   pair_args      Arg labels for pair type help, e.g. 'FROM TO'
    // ================================================================
    $option_defs = [
        // ── Required options ─────────────────────────────────────
        [
            'name' => 'state-dir',
            'type' => 'value',
            'target' => 'state_dir',
            'placeholder' => 'DIR',
            'help' => 'Directory for import state files and SQL dumps',
            'help_section' => 'required',
            'commands' => [],
        ],
        [
            'name' => 'fs-root',
            'type' => 'value',
            'target' => 'fs_root',
            'placeholder' => 'DIR',
            'help' => 'Local directory read from or written to for site files',
            'help_section' => 'required',
            'commands' => ['apply-runtime'],
            'aliases' => ['docroot'],
        ],

        // ── Global options ───────────────────────────────────────
        [
            'name' => 'secret',
            'type' => 'value',
            'target' => 'secret',
            'placeholder' => 'TOKEN',
            'help' => 'HMAC shared secret for export API authentication',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-push', 'files-index', 'db-pull', 'db-index', 'preflight', 'preflight-assert'],
        ],
        [
            'name' => 'force-http',
            'type' => 'flag',
            'target' => 'force_http',
            'help' => 'Allow a trusted plain-HTTP target; anyone able to observe or alter the connection can read or modify transferred content',
            'commands' => ['files-push'],
        ],
        [
            'name' => 'abort',
            'type' => 'flag',
            'target' => 'abort',
            'help' => 'Abort current sync and exit (preserves downloaded files)',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-index', 'db-pull', 'db-index', 'db-apply'],
        ],
        [
            'name' => 'verbose',
            'type' => 'flag',
            'target' => 'verbose',
            'short' => 'v',
            'help' => 'Show detailed request/response logs',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'pull-db', 'files-pull', 'files-push', 'files-index', 'db-pull', 'db-index', 'db-apply', 'flat-docroot', 'apply-runtime'],
        ],
        [
            'name' => 'no-follow-symlinks',
            'type' => 'flag',
            'target' => 'follow_symlinks',
            'flag_value' => false,
            'help' => 'Do not follow symlinks pointing outside root directories',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'files-pull'],
        ],
        [
            'name' => 'follow-symlinks',
            'type' => 'flag',
            'target' => 'follow_symlinks',
            'flag_value' => true,
            'help' => null,
            'commands' => [],
        ],
        [
            'name' => 'follow-symlinks',
            'type' => 'value',
            'target' => 'symlink_bundle_directory',
            'placeholder' => 'DIR',
            'help' => 'Follow symlinks, consolidating escaping (out-of-scope) targets into DIR ' .
                '(a :fs-root: path or an absolute path within --fs-root), nested by source path. ' .
                'Bare --follow-symlinks is equivalent to --follow-symlinks=:fs-root:.',
            'commands' => ['pull', 'pull-files', 'files-pull'],
        ],
        [
            'name' => 'on-fs-root-nonempty',
            'type' => 'value',
            'target' => 'fs_root_nonempty_behavior',
            'placeholder' => 'MODE',
            'help' => 'What to do when fs root is non-empty (error|preserve-local)',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'files-pull'],
            'aliases' => ['on-docroot-nonempty'],
        ],
        [
            'name' => 'include-caches',
            'type' => 'flag',
            'target' => 'include_caches',
            'flag_value' => true,
            'help' => 'Include generated caches, VCS metadata, OS junk and editor scratch files (skipped by default)',
            'help_section' => 'global',
            'commands' => ['pull', 'pull-files', 'files-pull', 'files-index'],
        ],
        [
            'name' => 'adaptive',
            'type' => 'flag',
            'target' => 'tuning_config.enabled',
            'flag_value' => true,
            'help' => 'Enable adaptive request tuning (default: on)',
            'help_section' => 'global',
            'commands' => [],
        ],
        [
            'name' => 'no-adaptive',
            'type' => 'flag',
            'target' => 'tuning_config.enabled',
            'flag_value' => false,
            'help' => null,
            'commands' => [],
        ],
        [
            'name' => 'step',
            'type' => 'value',
            'target' => 'pipeline_step',
            'placeholder' => 'N',
            'cast' => 'int',
            'help' => 'Current pipeline step (1-indexed, for status file)',
            'help_section' => 'global',
            'commands' => [],
        ],
        [
            'name' => 'steps',
            'type' => 'value',
            'target' => 'pipeline_steps',
            'placeholder' => 'N',
            'cast' => 'int',
            'help' => 'Total pipeline steps (for status file)',
            'help_section' => 'global',
            'commands' => [],
        ],

        // ── files-pull options ───────────────────────────────────
        [
            'name' => 'filter',
            'type' => 'value',
            'target' => 'filter',
            'placeholder' => 'MODE',
            'valid_values' => ['none', 'essential-files', 'skipped-earlier'],
            'help' => 'Filter which files to download (pull/pull-files: none|essential-files; files-pull also supports skipped-earlier)',
            'commands' => ['pull', 'pull-files', 'files-pull'],
        ],
        [
            'name' => 'extra-directory',
            'type' => 'value',
            'target' => 'extra_directory',
            'placeholder' => 'DIR',
            'help' => 'Additional remote directory to include in the export',
            'commands' => ['pull-files', 'files-pull', 'files-index'],
        ],

        // ── db-pull options ──────────────────────────────────────
        [
            'name' => 'max-allowed-packet',
            'type' => 'value',
            'target' => 'max_allowed_packet',
            'placeholder' => 'SIZE',
            'cast' => 'size',
            'help' => 'Client max_allowed_packet (e.g. 16M, 64M)',
            'commands' => ['pull-db', 'db-pull'],
        ],
        [
            'name' => 'sql-output',
            'type' => 'value',
            'target' => 'sql_output',
            'placeholder' => 'MODE',
            'help' => 'Output mode: file (default), stdout, mysql',
            'commands' => ['db-pull'],
        ],
        [
            'name' => 'mysql-host',
            'type' => 'value',
            'target' => 'mysql_host',
            'placeholder' => 'HOST',
            'help' => 'MySQL host (default: 127.0.0.1, for --sql-output=mysql)',
            'commands' => ['db-pull'],
        ],
        [
            'name' => 'mysql-port',
            'type' => 'value',
            'target' => 'mysql_port',
            'placeholder' => 'PORT',
            'help' => 'MySQL port (default: 3306, for --sql-output=mysql)',
            'commands' => ['db-pull'],
        ],
        [
            'name' => 'mysql-user',
            'type' => 'value',
            'target' => 'mysql_user',
            'placeholder' => 'USER',
            'help' => 'MySQL user (default: root, for --sql-output=mysql)',
            'commands' => ['db-pull'],
        ],
        [
            'name' => 'mysql-password',
            'type' => 'value',
            'target' => 'mysql_password',
            'placeholder' => 'PASS',
            'help' => 'MySQL password (or set MYSQL_PASSWORD env)',
            'commands' => ['db-pull'],
        ],
        [
            'name' => 'mysql-database',
            'type' => 'value',
            'target' => 'mysql_database',
            'placeholder' => 'DB',
            'help' => 'MySQL database (required for --sql-output=mysql)',
            'commands' => ['db-pull'],
        ],

        // ── db-apply options ─────────────────────────────────────
        [
            'name' => 'target-engine',
            'type' => 'value',
            'target' => 'target_engine',
            'placeholder' => 'ENGINE',
            'help' => 'Target database engine: mysql or sqlite',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-host',
            'type' => 'value',
            'target' => 'target_host',
            'placeholder' => 'HOST',
            'help' => 'Target MySQL host (default: 127.0.0.1)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-port',
            'type' => 'value',
            'target' => 'target_port',
            'placeholder' => 'PORT',
            'cast' => 'int',
            'help' => 'Target MySQL port (default: 3306)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-user',
            'type' => 'value',
            'target' => 'target_user',
            'placeholder' => 'USER',
            'help' => 'Target MySQL user (required for mysql)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-pass',
            'type' => 'value',
            'target' => 'target_pass',
            'placeholder' => 'PASS',
            'help' => 'Target MySQL password',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-db',
            'type' => 'value',
            'target' => 'target_db',
            'placeholder' => 'NAME',
            'help' => 'Target DB name (required for mysql, optional for sqlite)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'target-sqlite-path',
            'type' => 'value',
            'target' => 'target_sqlite_path',
            'placeholder' => 'PATH',
            'help' => 'Target SQLite database file (default: <wp-content>/database/.ht.sqlite)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'rewrite-url',
            'type' => 'pair',
            'target' => 'rewrite_url',
            'pair_args' => 'FROM TO',
            'help' => 'Rewrite FROM to TO (repeatable)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'new-site-url',
            'type' => 'value-or-next',
            'target' => 'new_site_url',
            'placeholder' => 'URL',
            'help' => 'New site URL (auto-creates --rewrite-url from export URL origin)',
            'commands' => ['pull', 'pull-db', 'db-apply'],
        ],
        [
            'name' => 'remap',
            'type' => 'pair',
            'target' => 'remap',
            'pair_args' => 'SOURCE TARGET',
            'help' => 'Place SOURCE (a :token: like :wp-uploads: or an absolute path) at TARGET ' .
                '(a :fs-root: path or an absolute path within --fs-root); repeatable',
            'commands' => ['pull-files', 'files-pull'],
        ],
        [
            'name' => 'only',
            'type' => 'value-or-next',
            'target' => 'only',
            'placeholder' => 'SOURCE',
            'repeatable' => true,
            'help' => 'Restrict the file pull to SOURCE (a :token: like :wp-content: or :wp-uploads:, or an absolute path); ' .
                'repeat for several. Default pulls everything',
            'commands' => ['pull-files', 'files-pull'],
        ],

        // ── flat-docroot options ────────────────────────────────
        [
            'name' => 'flatten-to',
            'type' => 'value',
            'target' => 'flatten_to',
            'placeholder' => 'PATH',
            'help' => 'Target directory for the flattened layout',
            'commands' => ['pull', 'flat-docroot'],
        ],
        [
            'name' => 'force',
            'type' => 'flag',
            'target' => 'force',
            'help' => 'Remove conflicting non-symlink files and replace with symlinks',
            'commands' => ['pull', 'flat-docroot'],
        ],

        // ── apply-runtime options ────────────────────────────────
        [
            'name' => 'runtime',
            'type' => 'value',
            'target' => 'runtime',
            'placeholder' => 'RUNTIME',
            'valid_values' => VALID_TARGET_RUNTIMES,
            'help' => 'Target server runtime: php-builtin, playground-cli, nginx-fpm, or none',
            'commands' => ['pull', 'apply-runtime'],
        ],
        [
            'name' => 'start-runtime',
            'type' => 'value',
            'target' => 'start_runtime',
            'placeholder' => 'RUNTIME',
            'valid_values' => VALID_TARGET_RUNTIMES,
            'help' => 'Runtime to launch after pull (php-builtin|playground-cli|nginx-fpm|none)',
            'commands' => ['pull'],
        ],
        [
            'name' => 'output-dir',
            'type' => 'value',
            'target' => 'output_dir',
            'placeholder' => 'DIR',
            'help' => 'Directory for generated runtime files',
            'commands' => ['pull', 'apply-runtime'],
        ],
        [
            'name' => 'flat-document-root',
            'type' => 'value',
            'target' => 'flat_document_root',
            'placeholder' => 'DIR',
            'help' => 'Flattened layout directory (used as-is)',
            'commands' => ['apply-runtime'],
            'aliases' => ['flattened-docroot'],
        ],
        [
            'name' => 'host',
            'type' => 'value',
            'target' => 'host',
            'placeholder' => 'HOST',
            'help' => 'Listen address (default: from rewrite URL, or localhost)',
            'commands' => ['apply-runtime'],
        ],
        [
            'name' => 'port',
            'type' => 'value',
            'target' => 'port',
            'placeholder' => 'PORT',
            'cast' => 'int',
            'help' => 'Listen port (default: from rewrite URL, or 8881)',
            'commands' => ['apply-runtime'],
        ],

        // ── Tuning options (accepted but hidden from help) ───────
        ['name' => 'duty', 'type' => 'value', 'target' => 'tuning_config.duty', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'duty-min', 'type' => 'value', 'target' => 'tuning_config.duty_min', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'duty-max', 'type' => 'value', 'target' => 'tuning_config.duty_max', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'throughput-alpha', 'type' => 'value', 'target' => 'tuning_config.throughput_ema_alpha', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'aimd-drop-ratio', 'type' => 'value', 'target' => 'tuning_config.aimd_drop_ratio', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'aimd-decrease-factor', 'type' => 'value', 'target' => 'tuning_config.aimd_decrease_factor', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'error-decrease-factor', 'type' => 'value', 'target' => 'tuning_config.error_decrease_factor', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'aimd-increase-file', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_file_bytes', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'aimd-increase-index', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_index_entries', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'aimd-increase-sql', 'type' => 'value', 'target' => 'tuning_config.aimd_increase_sql_fragments', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'tune-all', 'type' => 'flag', 'target' => 'tuning_config.tune_only_partial', 'flag_value' => false, 'help' => null, 'commands' => []],
        ['name' => 'buffered-ratio', 'type' => 'value', 'target' => 'tuning_config.buffered_ratio_threshold', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'buffered-min-time', 'type' => 'value', 'target' => 'tuning_config.buffered_min_server_time', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'buffered-cooldown', 'type' => 'value', 'target' => 'tuning_config.buffered_cooldown', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'error-backoff', 'type' => 'value', 'target' => 'tuning_config.error_backoff_requests', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'slow-host-threshold', 'type' => 'value', 'target' => 'tuning_config.slow_host_threshold', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'slow-file-chunk-max', 'type' => 'value', 'target' => 'tuning_config.slow_host_file_chunk_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'slow-index-batch-max', 'type' => 'value', 'target' => 'tuning_config.slow_host_index_batch_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'slow-sql-fragments-max', 'type' => 'value', 'target' => 'tuning_config.slow_host_sql_fragments_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'sleep-jitter', 'type' => 'value', 'target' => 'tuning_config.sleep_jitter', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'max-exec', 'type' => 'value', 'target' => 'tuning_config.max_execution_time', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'memory-threshold', 'type' => 'value', 'target' => 'tuning_config.memory_threshold', 'cast' => 'float', 'help' => null, 'commands' => []],
        ['name' => 'file-chunk-start', 'type' => 'value', 'target' => 'tuning_config.file_chunk_start', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'file-chunk-min', 'type' => 'value', 'target' => 'tuning_config.file_chunk_min', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'file-chunk-max', 'type' => 'value', 'target' => 'tuning_config.file_chunk_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'index-batch-start', 'type' => 'value', 'target' => 'tuning_config.index_batch_start', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'index-batch-min', 'type' => 'value', 'target' => 'tuning_config.index_batch_min', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'index-batch-max', 'type' => 'value', 'target' => 'tuning_config.index_batch_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'sql-fragments-start', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_start', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'sql-fragments-min', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_min', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'sql-fragments-max', 'type' => 'value', 'target' => 'tuning_config.sql_fragments_max', 'cast' => 'int', 'help' => null, 'commands' => []],
        ['name' => 'db-unbuffered', 'type' => 'flag', 'target' => 'tuning_config.db_unbuffered', 'help' => null, 'commands' => []],
        ['name' => 'db-query-time-limit', 'type' => 'value', 'target' => 'tuning_config.db_query_time_limit', 'cast' => 'int', 'help' => null, 'commands' => []],
    ];

    // ── CLI helper functions ─────────────────────────────────

    /**
     * Parse CLI options using the declarative option definitions.
     *
     * @return array {
     *     Parsed CLI option tuple.
     *
     *     @type string|null $0 State directory path.
     *     @type string|null $1 Filesystem root path.
     *     @type array       $2 Parsed options.
     * }
     * @phpstan-return array{0: ?string, 1: ?string, 2: array}
     */
    function _cli_parse_options(array $argv, int $argc, int $start, array $option_defs): array
    {
        $state_dir = null;
        $fs_root = null;
        $options = [
            "abort" => false,
            "verbose" => false,
            "secret" => null,
            "tuning_config" => [],
        ];

        for ($i = $start; $i < $argc; $i++) {
            $arg = $argv[$i];
            $matched = false;

            foreach ($option_defs as $def) {
                $names = [$def['name']];
                if (isset($def['aliases'])) {
                    $names = array_merge($names, $def['aliases']);
                }

                foreach ($names as $cli_name) {
                    switch ($def['type']) {
                        case 'value':
                            $prefix = "--{$cli_name}=";
                            if (strpos($arg, $prefix) === 0) {
                                $raw = substr($arg, strlen($prefix));
                                $value = _cli_cast($raw, $def['cast'] ?? null);
                                if (isset($def['valid_values']) && !in_array($value, $def['valid_values'], true)) {
                                    fwrite(STDERR, "Invalid --{$def['name']} value: {$raw}. Valid values: " . implode(", ", $def['valid_values']) . "\n");
                                    exit(1);
                                }
                                _cli_store($def, $value, $state_dir, $fs_root, $options);
                                $matched = true;
                                break 3;
                            }
                            break;

                        case 'flag':
                            if ($arg === "--{$cli_name}" || (isset($def['short']) && $arg === "-{$def['short']}")) {
                                _cli_store($def, $def['flag_value'] ?? true, $state_dir, $fs_root, $options);
                                $matched = true;
                                break 3;
                            }
                            break;

                        case 'value-or-next':
                            $prefix = "--{$cli_name}=";
                            if (strpos($arg, $prefix) === 0) {
                                $raw = substr($arg, strlen($prefix));
                                _cli_store($def, $raw, $state_dir, $fs_root, $options);
                                $matched = true;
                                break 3;
                            }
                            if ($arg === "--{$cli_name}") {
                                if (!isset($argv[$i + 1])) {
                                    fwrite(STDERR, "--{$def['name']} requires one argument: " . ($def['placeholder'] ?? 'VALUE') . "\n");
                                    exit(1);
                                }
                                _cli_store($def, $argv[$i + 1], $state_dir, $fs_root, $options);
                                $i += 1;
                                $matched = true;
                                break 3;
                            }
                            break;

                        case 'pair':
                            if ($arg === "--{$cli_name}") {
                                if (!isset($argv[$i + 1]) || !isset($argv[$i + 2])) {
                                    fwrite(STDERR, "--{$def['name']} requires two arguments: " . ($def['pair_args'] ?? 'ARG1 ARG2') . "\n");
                                    exit(1);
                                }
                                $target = $def['target'];
                                if (!isset($options[$target])) {
                                    $options[$target] = [];
                                }
                                $options[$target][] = [$argv[$i + 1], $argv[$i + 2]];
                                $i += 2;
                                $matched = true;
                                break 3;
                            }
                            break;
                    }
                }
            }

            if (!$matched) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                exit(1);
            }
        }

        return [$state_dir, $fs_root, $options];
    }

    /** @internal */
    function _cli_cast(string $raw, ?string $cast)
    {
        switch ($cast) {
            case 'int':   return (int) $raw;
            case 'float': return (float) $raw;
            case 'size':  return parse_size($raw);
            default:      return $raw;
        }
    }

    /** @internal */
    function _cli_store(array $def, $value, ?string &$state_dir, ?string &$fs_root, array &$options): void
    {
        $target = $def['target'];
        if ($target === 'state_dir') { $state_dir = $value; return; }
        if ($target === 'fs_root')   { $fs_root = $value;   return; }
        if (strpos($target, 'tuning_config.') === 0) {
            $options['tuning_config'][substr($target, strlen('tuning_config.'))] = $value;
            return;
        }
        if (!empty($def['repeatable'])) {
            if (!isset($options[$target])) {
                $options[$target] = [];
            }
            $options[$target][] = $value;
            return;
        }
        $options[$target] = $value;
    }

    /**
     * Render the main --help output.
     */
    function _cli_render_main_help(array $option_defs, array $command_info): void
    {
        $is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
        $re = $is_tty ? "\033[35m" : "";              // magenta (Re)
        $pr = $is_tty ? "\033[38;5;63m" : "";         // WP Blueberry ~#3858E9 (Print)
        $r  = $is_tty ? "\033[0m" : "";
        echo "{$re} ___         {$pr}___         _          _   {$r}\n";
        echo "{$re}| _ \\  ___  {$pr}| _ \\  _ _  (_)  _ _   | |_ {$r}\n";
        echo "{$re}|   / / -_) {$pr}|  _/ | '_| | | | ' \\  |  _|{$r}\n";
        echo "{$re}|_|_\\ \\___| {$pr}|_|   |_|   |_| |_||_|  \\__|{$r}\n";
        echo "\n";
        echo "Mirror any WordPress site over HTTP.\n";
        echo "Version " . get_importer_version() . "\n";
        echo "\n";
        echo "Usage: reprint <command> <remote-url> [options]\n";
        echo "\n";

        $high = array_filter($command_info, fn($i) => ($i['level'] ?? 'low') === 'high');
        $low = array_filter($command_info, fn($i) => ($i['level'] ?? 'low') === 'low');
        $max_len = max(array_map('strlen', array_keys($command_info)));

        echo "Commands:\n";
        foreach ($high as $name => $info) {
            echo "  " . str_pad($name, $max_len + 2) . $info["short"] . "\n";
        }
        echo "\n";
        echo "Low-level commands:\n";
        foreach ($low as $name => $info) {
            echo "  " . str_pad($name, $max_len + 2) . $info["short"] . "\n";
        }
        echo "\n";
        echo "Run 'reprint <command> --help' for command-specific help.\n";
        echo "\n";

        $required = array_filter($option_defs, fn($d) => ($d['help_section'] ?? null) === 'required');
        if ($required) {
            echo "Required options:\n";
            _cli_render_option_list($required);
            echo "\n";
        }

        echo "Shared options (see command help for availability):\n";
        $global = array_filter($option_defs, fn($d) => ($d['help_section'] ?? null) === 'global');
        // --version/-V is handled before option parsing, so inject it manually.
        _cli_render_option_list($global, ['--version, -V' => 'Print version and exit']);
        echo "\n";

        echo "Exit codes:\n";
        echo "  0  Command completed successfully\n";
        echo "  2  Partial progress — run the same command again to continue\n";
        echo "  1  Error\n";
        echo "\n";
        echo "Resumable commands keep their command-specific work under --state-dir.\n";
        echo "Run command-specific help for continuation and cancellation behavior.\n";
    }

    /**
     * Render per-command --help output.
     *
     * The "Options:" section is auto-generated from $option_defs so that
     * every declared option automatically appears in the right command's
     * help.  The hand-written $command_info provides the prose description
     * and any extra sections (examples, output-file lists, etc.).
     */
    function _cli_render_command_help(string $command, array $option_defs, array $command_info): void
    {
        if (!isset($command_info[$command])) {
            fwrite(STDERR, "Unknown command: {$command}\n");
            return;
        }

        $info = $command_info[$command];
        $usage = $info["usage"] ?? "reprint {$command} <remote-url> --state-dir=DIR --fs-root=DIR [options]";
        echo "Usage: {$usage}\n";
        echo "\n";
        echo $info["description"];

        // Collect options tagged for this command. Required options are also
        // shown when the command usage names them, so command-specific help
        // matches what the CLI requires without duplicating every command name
        // in the option definition.
        $cmd_options = array_filter($option_defs, function ($d) use ($command, $usage) {
            if (($d['help'] ?? null) === null) {
                return false;
            }
            if (isset($d['commands']) && in_array($command, $d['commands'], true)) {
                return true;
            }
            return
                ($d['help_section'] ?? null) === 'required' &&
                strpos($usage, "--{$d['name']}") !== false;
        });

        // Show command-specific options first, then global ones.
        if ($cmd_options) {
            usort($cmd_options, function ($a, $b) {
                $a_global = in_array($a['help_section'] ?? null, ['required', 'global'], true) ? 1 : 0;
                $b_global = in_array($b['help_section'] ?? null, ['required', 'global'], true) ? 1 : 0;
                return $a_global - $b_global;
            });
            echo "\n";
            echo "Options:\n";
            _cli_render_option_list($cmd_options);
        }

        if (!empty($info["extra"])) {
            echo "\n";
            echo $info["extra"];
        }
        echo "\n";
    }

    /**
     * Render the install-exporter guide.
     *
     * Shows the download URL for the exporter plugin matching this version
     * of reprint, and step-by-step installation instructions.
     */
    function _cli_render_install_exporter(): void
    {
        $version = get_importer_version();
        $is_dev = str_contains($version, '-trunk') || $version === 'v0.0.0';
        $is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
        $bold  = $is_tty ? "\033[1m" : "";
        $dim   = $is_tty ? "\033[2m" : "";
        $cyan  = $is_tty ? "\033[36m" : "";
        $reset = $is_tty ? "\033[0m" : "";

        $repo = "adamziel/streaming-site-migration";
        $zip_url = "https://github.com/{$repo}/releases/download/{$version}/reprint-exporter-wp.zip";
        $releases_url = "https://github.com/{$repo}/releases";

        echo "{$bold}Install the RePrint Exporter Plugin{$reset}\n";
        echo "\n";
        echo "The exporter plugin must be installed on the WordPress site you\n";
        echo "want to mirror. It exposes the HTTP API that reprint connects to.\n";
        echo "\n";

        echo "{$bold}Step 1: Download the plugin{$reset}\n";
        echo "\n";
        if ($is_dev) {
            echo "  You are running an unreleased development build ({$version}).\n";
            echo "  Install the exporter plugin from the same branch:\n";
            echo "\n";
            echo "  {$dim}composer build:exporter-plugin{$reset}\n";
            echo "\n";
            echo "  Then upload reprint-exporter-wp.zip through wp-admin,\n";
            echo "  or symlink reprint-exporter-wp/ into wp-content/plugins/.\n";
        } else {
            echo "  {$cyan}{$zip_url}{$reset}\n";
        }

        echo "\n";
        echo "{$bold}Step 2: Install on your WordPress site{$reset}\n";
        echo "\n";
        echo "  1. Log in to wp-admin\n";
        echo "  2. Go to Plugins → Add New Plugin → Upload Plugin\n";
        echo "  3. Upload reprint-exporter-wp.zip and activate it\n";
        echo "\n";
        echo "{$bold}Step 3: Configure the shared secret{$reset}\n";
        echo "\n";
        echo "  1. In wp-admin, go to Site Export (in the sidebar)\n";
        echo "  2. Enter a shared secret and save\n";
        echo "  3. Use the same secret with reprint:\n";
        echo "\n";
        echo "     {$dim}php reprint.phar preflight https://your-site.com \\\n";
        echo "       --secret=YOUR_SECRET \\\n";
        echo "       --state-dir=./state --fs-root=./files{$reset}\n";
        echo "\n";
    }

    /**
     * Render a list of options with aligned descriptions.
     *
     * @param array $defs   Option definition entries (only those with non-null help are rendered).
     * @param array $extra  Additional entries as ['--usage-string' => 'description'].
     */
    function _cli_render_option_list(array $defs, array $extra = []): void
    {
        $lines = [];
        foreach ($defs as $def) {
            if (($def['help'] ?? null) === null) {
                continue;
            }
            $lines[] = [_cli_option_usage($def), $def['help']];
        }
        foreach ($extra as $usage => $help) {
            $lines[] = [$usage, $help];
        }

        // Compute alignment: at least 2 spaces after the longest option.
        $max_usage = 0;
        foreach ($lines as [$usage, $_]) {
            $max_usage = max($max_usage, strlen($usage));
        }
        $col = max($max_usage + 2, 21);

        foreach ($lines as [$usage, $help]) {
            if (strlen($usage) >= $col) {
                // Option too long for the column — wrap description to next line.
                echo "  {$usage}\n";
                echo str_repeat(' ', $col + 2) . "{$help}\n";
            } else {
                echo "  " . str_pad($usage, $col) . "{$help}\n";
            }
        }
    }

    /** @internal Build the display string for one option, e.g. "--name=DIR" or "--name, -v". */
    function _cli_option_usage(array $def): string
    {
        $name = "--{$def['name']}";
        if (isset($def['short'])) {
            $name .= ", -{$def['short']}";
        }
        switch ($def['type']) {
            case 'value':
            case 'value-or-next':
                return "{$name}=" . ($def['placeholder'] ?? 'VALUE');
            case 'pair':
                return "{$name} " . ($def['pair_args'] ?? 'ARG1 ARG2');
            case 'flag':
            default:
                return $name;
        }
    }

    // ── Per-command help definitions ─────────────────────────────
    //
    // "short"       — one-line summary shown in the main help listing.
    // "description" — prose shown above the auto-generated Options section.
    // "extra"       — text shown below the Options section (examples,
    //                 output-file lists, mode explanations, etc.).
    //
    // The Options: section itself is generated from $option_defs so that
    // every declared option for a command is guaranteed to appear.
    // High-level commands are the ones most users will use. Low-level
    // commands expose focused workflows useful for scripting and hosting
    // platform integrations; pull composes the relevant pull-side commands.
    $command_info = [
        "pull" => [
            "level" => "high",
            "short" => "Clone a remote site (preflight + files + database + import)",
            "description" =>
                "Full site clone in a single command. Composes lower-level commands into\n" .
                "a resumable pipeline:\n" .
                "\n" .
                "  1. Preflight — probe the remote site environment\n" .
                "  2. Files     — download all remote files into --fs-root\n" .
                "  3. Database  — download the SQL dump\n" .
                "  4. Import    — apply SQL to a local database (if --target-db)\n" .
                "  5. Flatten   — reassemble into standard WP layout (if --flatten-to)\n" .
                "  6. Runtime   — generate server config (default: php-builtin)\n" .
                "  7. Start     — launch the selected runtime when supported\n" .
                "\n" .
                "Each step retries automatically on server timeouts. If the process is\n" .
                "interrupted, re-run the same command to resume from where it left off.\n" .
                "Running pull again after completion performs a delta sync.\n" .
                "\n" .
                "Use --filter=essential-files to defer uploads and other large wp-content\n" .
                "entries while still completing the rest of the pull.\n" .
                "\n" .
                "The ?site-export-api query parameter is added automatically if missing,\n" .
                "so you can pass just the site URL.\n",
            "extra" =>
                "Examples:\n" .
                "  # Download files and database (no import):\n" .
                "  reprint pull https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files\n" .
                "\n" .
                "  # Full clone with MySQL import and URL rewriting:\n" .
                "  reprint pull https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --target-user=root --target-db=wp_local \\\n" .
                "    --new-site-url=http://localhost:8881\n" .
                "\n" .
                "  # Complete the main pull now, defer the heavier file tail:\n" .
                "  reprint pull https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --filter=essential-files --target-engine=sqlite --runtime=none\n" .
                "\n" .
                "  # Full clone with SQLite, flattened layout, and PHP built-in server:\n" .
                "  reprint pull https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --target-engine=sqlite \\\n" .
                "    --new-site-url=http://localhost:8881 \\\n" .
                "    --flatten-to=./site --runtime=php-builtin --output-dir=./runtime\n" .
                "\n" .
                "  # Prepare a Playground runtime but let another process start it:\n" .
                "  reprint pull https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --runtime=playground-cli --start-runtime=none --output-dir=./runtime\n",
        ],
        "pull-files" => [
            "level" => "high",
            "short" => "Pull files through the high-level pull pipeline",
            "description" =>
                "Runs the file side of the pull pipeline:\n" .
                "\n" .
                "  1. Preflight — probe the remote site environment\n" .
                "  2. files-pull — download all files, or a selected subset\n" .
                "\n" .
                "This gives files the same retry and resume behavior as pull,\n" .
                "without running the database stages.\n",
            "extra" =>
                "Examples:\n" .
                "  reprint pull-files https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files\n" .
                "\n" .
                "  reprint pull-files https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --only=:wp-content: --only=:wp-plugins:\n",
        ],
        "pull-db" => [
            "level" => "high",
            "short" => "Pull and apply the database through the high-level pull pipeline",
            "description" =>
                "Runs the database side of the pull pipeline:\n" .
                "\n" .
                "  1. Preflight — probe the remote site environment\n" .
                "  2. db-pull — download the SQL dump into --state-dir/db.sql\n" .
                "  3. db-apply — import the dump into a local database\n" .
                "\n" .
                "This gives the database the same retry and resume behavior as pull,\n" .
                "without running the file or runtime stages. With no MySQL target\n" .
                "options, pull-db imports into SQLite by default.\n",
            "extra" =>
                "Examples:\n" .
                "  reprint pull-db https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --target-engine=sqlite\n" .
                "\n" .
                "  reprint pull-db https://example.com \\\n" .
                "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n" .
                "    --target-user=root --target-db=wp_local \\\n" .
                "    --new-site-url=http://localhost:8881\n",
        ],
        "install-exporter" => [
            "level" => "high",
            "short" => "Show how to install the exporter plugin on your site",
            "description" =>
                "Prints the download URL for the exporter WordPress plugin that\n" .
                "matches this version of reprint, and step-by-step installation\n" .
                "instructions.\n" .
                "\n" .
                "The exporter plugin must be installed on the remote site before\n" .
                "any other reprint command can connect to it.\n",
            "extra" => null,
        ],
        "preflight" => [
            "level" => "low",
            "short" => "Probe the remote site and cache its environment",
            "description" =>
                "Contacts the remote site and collects environment details:\n" .
                "PHP/MySQL versions, memory limits, filesystem access, database\n" .
                "connectivity, WordPress version, plugins, themes, directory layout,\n" .
                "and runtime scripts (auto_prepend_file, auto_append_file).\n" .
                "\n" .
                "Results are saved to state for use by later commands.\n" .
                "Prints the full response as pretty-printed JSON.\n" .
                "Exits 0 if the site reported OK, 1 otherwise.\n",
            "extra" => null,
        ],
        "preflight-assert" => [
            "level" => "low",
            "short" => "Verify the remote site can be mirrored (exits 0 or 1)",
            "description" =>
                "Runs the same check as the preflight command, then evaluates\n" .
                "key assertions:\n" .
                "\n" .
                "  - Remote site responded with HTTP 200\n" .
                "  - Preflight OK flag is set\n" .
                "  - Filesystem directories are accessible\n" .
                "  - Database connection works\n" .
                "\n" .
                "Prints a PASS/FAIL summary and exits 0 if all checks pass, 1 if not.\n",
            "extra" => null,
        ],
        "files-pull" => [
            "level" => "low",
            "short" => "Pull all files (initial) or only changes (delta)",
            "description" =>
                "Downloads files from the remote site into --fs-root.\n" .
                "\n" .
                "On the first run, indexes the full remote directory tree and then\n" .
                "downloads every file. On subsequent runs, re-indexes the remote tree,\n" .
                "diffs against the local index, and downloads only what changed.\n" .
                "Interrupted pulls resume from the last saved cursor.\n" .
                "\n" .
                "Runs files-index internally when no index exists yet.\n",
            "extra" =>
                "Filter modes:\n" .
                "  none             Pull all files (default)\n" .
                "  essential-files   Skip uploads, pull only code/config/themes/plugins.\n" .
                "                    The skipped file list is saved for later retrieval.\n" .
                "  skipped-earlier   Pull only files skipped by a prior essential-files run.\n" .
                "\n" .
                "Output files:\n" .
                "  (fs-root)/                              Downloaded files\n" .
                "  .import-index.jsonl                     Local file index\n" .
                "  .import-remote-index.jsonl              Remote index snapshot\n" .
                "  .import-download-list.jsonl             Files pending download\n" .
                "  .import-download-list-skipped.jsonl     Skipped files (when --filter=essential-files)\n" .
                "  .import-state.json                      Resumable state\n" .
                "  .import-audit.log                       Audit log\n",
        ],
        "files-diff" => [
            "level" => "low",
            "short" => "Show local file changes since the last push",
            "usage" => "reprint files-diff <target-url> --state-dir=DIR --fs-root=DIR",
            "description" =>
                "Shows which local paths a files-push would send or delete, comparing\n" .
                "the local tree at --fs-root with the pair's previous local index —\n" .
                "the index a completed files-push publishes for the same target URL,\n" .
                "state directory, and local tree.\n" .
                "The output is a local minimized push operation plan before target\n" .
                "exclusions, not a path-for-path filesystem log. Like files-push, its\n" .
                "default-skipped paths include generated wp-content caches, version-\n" .
                "control data, node_modules, package-manager caches, OS metadata, and\n" .
                "editor scratch files.\n" .
                "Paths remain base64 text in JSONL output so\n" .
                "arbitrary filesystem names are preserved. No network calls are made\n" .
                "and no secret is required.\n",
            "extra" =>
                "Every run reports the complete diff from the beginning; there is\n" .
                "no partial resume to continue.\n",
        ],
        "files-push" => [
            "level" => "low",
            "short" => "Push one local file tree without database work",
            "usage" => "reprint files-push <target-url> --state-dir=DIR --fs-root=DIR --secret=TOKEN [--force-http] [--verbose]",
            "description" =>
                "Sends the existing local tree at --fs-root to the target exporter API.\n" .
                "This is a low-level, files-only command: it performs no database work,\n" .
                "plan display, confirmation prompt, automatic retry, or automatic restart.\n" .
                "It does not require pull preflight.\n" .
                "\n" .
                "Each process runs one sender until it completes, reaches a caller time or\n" .
                "memory boundary, or receives a signal handled by this PHP runtime.\n" .
                "Re-run the same command after exit 2.\n" .
                "After a restart result, the next run starts a fresh plan.\n",
            "extra" =>
                "Exit outcomes:\n" .
                "  0  File push complete\n" .
                "  2  Partial, interrupted, or restart; run the command again\n" .
                "  1  Failed request or command error\n",
        ],
        "files-index" => [
            "level" => "low",
            "short" => "Index all remote files (initial) or detect changes (delta)",
            "description" =>
                "Streams the full remote directory tree over HTTP and writes each\n" .
                "entry (path, size, ctime, type, and directory emptiness) to\n" .
                ".import-remote-index.jsonl.\n" .
                "\n" .
                "On the first run, builds the complete index. On subsequent runs,\n" .
                "re-indexes and diffs against the prior snapshot to produce a\n" .
                "download list of changed files.\n" .
                "\n" .
                "When symlink-following is enabled, recursively discovers and indexes\n" .
                "additional directories outside the primary roots.\n" .
                "\n" .
                "Does not download any file contents.\n",
            "extra" => null,
        ],
        "files-stats" => [
            "level" => "low",
            "short" => "Show file counts and sizes from the local index",
            "description" =>
                "Reads local index files to report (no network calls):\n" .
                "\n" .
                "  - Total indexed files and their combined size\n" .
                "  - Files not yet downloaded and their combined size\n" .
                "\n" .
                "Output is JSON with 'indexed' and 'pending' sections.\n" .
                "Requires a prior files-index or files-pull run.\n",
            "extra" => null,
        ],
        "db-pull" => [
            "level" => "low",
            "short" => "Pull the database as a SQL dump (index + download)",
            "description" =>
                "Indexes remote tables, then streams the full SQL dump into\n" .
                "--state-dir/db.sql (default), to stdout, or directly into a\n" .
                "MySQL connection. Resumes from the last cursor if interrupted.\n" .
                "Discovered domains are cached for later use by db-apply.\n",
            "extra" =>
                "Output modes:\n" .
                "  file    Write to --state-dir/db.sql (default)\n" .
                "  stdout  Write raw SQL to stdout; progress goes to stderr\n" .
                "  mysql   Stream directly into a MySQL connection\n",
        ],
        "db-index" => [
            "level" => "low",
            "short" => "Pull table metadata from the remote database",
            "description" =>
                "Fetches table metadata (name, estimated rows, data size) from\n" .
                "the remote server and writes it to --state-dir/db-tables.jsonl.\n" .
                "Useful for planning before a full db-pull.\n",
            "extra" =>
                "Output files:\n" .
                "  db-tables.jsonl  One JSON object per table\n",
        ],
        "db-domains" => [
            "level" => "low",
            "short" => "Extract domains from the pulled SQL dump",
            "description" =>
                "Prints domains found in the SQL dump, one per line.\n" .
                "\n" .
                "If .import-domains.json exists (cached by db-pull), it is read\n" .
                "directly. Otherwise, db.sql is scanned and the result is cached\n" .
                "for future calls. No network calls.\n" .
                "\n" .
                "Example:\n" .
                "  reprint db-domains - --state-dir=/path/to/state\n",
            "extra" => null,
        ],
        "import-metadata" => [
            "level" => "low",
            "short" => "Print local import lifecycle metadata as JSON",
            "usage" => "reprint import-metadata --state-dir=DIR",
            "description" =>
                "Reads --state-dir/.import-state.json and prints metadata for\n" .
                "host integrations. No network calls are made.\n",
            "extra" =>
                "Example:\n" .
                "  reprint import-metadata --state-dir=./state | jq '.hasCompletedOnce'\n",
        ],
        "db-apply" => [
            "level" => "low",
            "short" => "Import the SQL dump into a local MySQL or SQLite database",
            "description" =>
                "Reads db.sql from --state-dir, optionally rewrites URLs, and executes\n" .
                "all statements against a target database. Resumable. Saves target\n" .
                "database credentials to state for use by apply-runtime.\n",
            "extra" =>
                "MySQL example:\n" .
                "  reprint db-apply - --state-dir=./state --fs-root=./files \\\n" .
                "    --target-user=root --target-db=wp_new \\\n" .
                "    --rewrite-url https://old.com https://new.com\n" .
                "\n" .
                "SQLite example:\n" .
                "  reprint db-apply - --state-dir=./state --fs-root=./files \\\n" .
                "    --target-engine=sqlite --target-sqlite-path=/path/to/db.sqlite \\\n" .
                "    --rewrite-url https://old.com https://new.com\n",
        ],
        "flat-docroot" => [
            "level" => "low",
            "short" => "Reassemble pulled files into a standard WordPress layout",
            "description" =>
                "Creates a directory at --flatten-to with symlinks that map the\n" .
                "pulled files back into a vanilla WordPress directory structure.\n" .
                "\n" .
                "Uses preflight paths (ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR,\n" .
                "WPMU_PLUGIN_DIR, uploads basedir) to locate each component\n" .
                "within --fs-root, even when they reside in different parent\n" .
                "directories on the source server (e.g. WP Cloud with ABSPATH at\n" .
                "/srv/htdocs and WP_CONTENT_DIR at /tmp/__wp__/wp-content).\n" .
                "\n" .
                "No files are copied — only symlinks are created. Idempotent.\n" .
                "If a path that should be a symlink is a regular file or directory,\n" .
                "the command stops with an error unless --force is specified.\n",
            "extra" => null,
        ],
        "apply-runtime" => [
            "level" => "low",
            "short" => "Generate server config and prepare the site to run locally",
            "description" =>
                "Generates server configuration (runtime.php, nginx.conf or start.sh)\n" .
                "from preflight data and removes production-only drop-ins and mu-plugins\n" .
                "that would crash outside the original host.\n" .
                "\n" .
                "If db-apply was run first, embeds the target database credentials\n" .
                "into runtime.php automatically.\n" .
                "\n" .
                "Does not require a remote URL — reads only from local state.\n" .
                "\n" .
                "Pass --fs-root for the raw download directory (the remote document_root\n" .
                "path is appended automatically), or --flat-document-root for a directory\n" .
                "created by flat-docroot (used as-is). These are mutually exclusive.\n",
            "extra" =>
                "Runtime modes:\n" .
                "  nginx-fpm      — writes runtime.php + nginx.conf\n" .
                "  php-builtin    — writes runtime.php + start.sh\n" .
                "  playground-cli — writes runtime.php + blueprint.json\n" .
                "\n" .
                "Database configuration:\n" .
                "  When db-apply has been run before apply-runtime, the target database\n" .
                "  engine and credentials are read from state and included in runtime.php\n" .
                "  as DB_* constants. For MySQL targets this means DB_HOST, DB_NAME,\n" .
                "  DB_USER, and DB_PASSWORD. For SQLite targets, the sqlite-database-\n" .
                "  integration plugin is copied into the output directory and a lazy-\n" .
                "  loading \$wpdb proxy is generated in runtime.php (Playground-style,\n" .
                "  no files placed in the fs-root).\n" .
                "\n" .
                "Output files (nginx-fpm):\n" .
                "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n" .
                "  (output-dir)/nginx.conf              Nginx server block\n" .
                "\n" .
                "Output files (php-builtin):\n" .
                "  (output-dir)/runtime.php             PHP runtime (constants, routing, handlers)\n" .
                "  (output-dir)/start.sh                Shell script to launch the server\n" .
                "\n" .
                "Output files (playground-cli):\n" .
                "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n" .
                "  (output-dir)/blueprint.json          Playground Blueprint\n" .
                "\n" .
                "Output files (sqlite target, additional):\n" .
                "  (output-dir)/sqlite-database-integration/   Plugin copy\n" .
                "\n" .
                "Examples:\n" .
                "  # From raw download directory:\n" .
                "  reprint apply-runtime --state-dir=./state \\\n" .
                "    --fs-root=./files --output-dir=./runtime --runtime=php-builtin\n" .
                "\n" .
                "  # From flattened layout:\n" .
                "  reprint apply-runtime --state-dir=./state \\\n" .
                "    --flat-document-root=./flat --output-dir=./runtime --runtime=php-builtin\n" .
                "\n" .
                "  bash ./runtime/start.sh\n",
        ],
    ];

    // Show main help when invoked with no arguments or just --help
    if ($argc < 2 || (isset($argv[1]) && in_array($argv[1], ["--help", "-h", "help"]))) {
        _cli_render_main_help($option_defs, $command_info);
        exit(1);
    }

    $command = $argv[1];

    // Legacy command names that still work on the CLI.
    $command_aliases = [
        "files-sync" => "files-pull",
        "db-sync" => "db-pull",
        "flat-document-root" => "flat-docroot",
        "flatten-docroot" => "flat-docroot",
    ];
    if (isset($command_aliases[$command])) {
        $command = $command_aliases[$command];
    }

    // install-exporter is a standalone guide — no URL, state-dir, or fs-root needed.
    // Handle it before per-command --help so it always shows the full guide.
    if ($command === "install-exporter") {
        _cli_render_install_exporter();
        exit(0);
    }

    // Per-command --help (can be requested before providing url/path)
    if (in_array("--help", array_slice($argv, 2)) || in_array("-h", array_slice($argv, 2))) {
        _cli_render_command_help($command, $option_defs, $command_info);
        exit(0);
    }

    // apply-runtime and import-metadata don't need a remote URL. Other
    // local-only commands (db-domains, db-apply, etc.) still accept it for
    // CLI consistency and backward compatibility with existing callers.
    $local_only_commands = ["apply-runtime", "import-metadata"];
    $is_local_only = in_array($command, $local_only_commands, true);

    if ($is_local_only) {
        $remote_url = "-";
        $option_start_index = 2; // options start right after the command
    } else {
        $remote_url = $argv[2] ?? null;
        if (!$remote_url) {
            fwrite(STDERR, "Error: <remote-url> is required\n");
            fwrite(STDERR, "Usage: reprint {$command} <remote-url> --state-dir=DIR --fs-root=DIR [options]\n");
            exit(1);
        }
        $option_start_index = 3;
    }

    [$state_dir, $fs_root, $options] = _cli_parse_options(
        $argv, $argc, $option_start_index, $option_defs
    );
    $options["command"] = $command;

    $reprint_files_command_arguments = array_slice($argv, $option_start_index);
    if ($command === 'files-push') {
        foreach ($reprint_files_command_arguments as $reprint_files_push_command_argument) {
            $reprint_files_push_option_allowed = in_array(
                $reprint_files_push_command_argument,
                ['--force-http', '--verbose', '-v'],
                true
            )
                || strpos($reprint_files_push_command_argument, '--state-dir=') === 0
                || strpos($reprint_files_push_command_argument, '--fs-root=') === 0
                || strpos($reprint_files_push_command_argument, '--secret=') === 0;
            if (!$reprint_files_push_option_allowed) {
                $reprint_files_push_option_name = explode('=', $reprint_files_push_command_argument, 2)[0];
                fwrite(STDERR, "Error: files-push does not accept {$reprint_files_push_option_name}.\n");
                exit(1);
            }
        }
    } elseif ($command === 'files-diff') {
        foreach ($reprint_files_command_arguments as $reprint_files_diff_command_argument) {
            $reprint_files_diff_option_allowed =
                strpos($reprint_files_diff_command_argument, '--state-dir=') === 0
                || strpos($reprint_files_diff_command_argument, '--fs-root=') === 0;
            if (!$reprint_files_diff_option_allowed) {
                $reprint_files_diff_option_name = explode('=', $reprint_files_diff_command_argument, 2)[0];
                fwrite(STDERR, "Error: files-diff does not accept {$reprint_files_diff_option_name}.\n");
                exit(1);
            }
        }
    } elseif (!empty($options['force_http'])) {
        fwrite(STDERR, "Error: --force-http is accepted only by files-push.\n");
        exit(1);
    }

    if (!$state_dir) {
        fwrite(STDERR, "Error: --state-dir=DIR is required\n");
        fwrite(STDERR, "Usage: reprint {$command} <remote-url> --state-dir=DIR --fs-root=DIR [options]\n");
        exit(1);
    }

    // apply-runtime accepts --flat-document-root as an alternative to --fs-root.
    $flat_document_root = $options["flat_document_root"] ?? null;
    if ($fs_root && $flat_document_root) {
        fwrite(STDERR, "Error: --fs-root and --flat-document-root are mutually exclusive.\n");
        fwrite(STDERR, "Use --fs-root for the raw download directory, or --flat-document-root for a flattened layout.\n");
        exit(1);
    }
    if (!$fs_root && !$flat_document_root && $command !== "import-metadata") {
        fwrite(STDERR, "Error: --fs-root=DIR is required\n");
        fwrite(STDERR, "Usage: reprint {$command} <remote-url> --state-dir=DIR --fs-root=DIR [options]\n");
        exit(1);
    }
    if (!$fs_root) {
        // For commands that need an fs root in the constructor, use the
        // flattened fs root. run_apply_runtime will resolve it properly.
        // import-metadata reads only state, but ImportClient still expects
        // an fs root path. Point it at state-dir rather than requiring an
        // otherwise-unused CLI option.
        $fs_root = $flat_document_root ?: $state_dir;
    }

    try {
        $reprint_files_push_context = null;
        $reprint_files_diff_context = null;
        if ($command === 'files-push') {
            $reprint_files_push_context = ImportClient::prepare_files_push_context(
                $remote_url,
                $state_dir,
                $fs_root,
                $options
            );
        } elseif ($command === 'files-diff') {
            $reprint_files_diff_context = ImportClient::prepare_files_pair_context(
                $remote_url,
                $state_dir,
                $fs_root,
                'files-diff'
            );
        }
        $client = new ImportClient($remote_url, $state_dir, $fs_root, $command);
        $reprint_files_pair = $reprint_files_push_context['pair'] ?? ( $reprint_files_diff_context['pair'] ?? null );
        $client->audit_log_argv($command, $argv, $reprint_files_pair);
        $client->run(
            $options
                + ( $reprint_files_push_context === null
                    ? []
                    : ['files_push_context' => $reprint_files_push_context] )
                + ( $reprint_files_diff_context === null
                    ? []
                    : ['files_diff_context' => $reprint_files_diff_context] )
        );
        // EXIT_AFTER_IMPORT controls whether we hand control back to
        // the caller after pull returns. Default true: standard CLI
        // invocations (reprint pull, the phar bin, e2e tests) get the
        // exit() they expect. Embedders that include the phar from a
        // web SAPI — the Playground wizard in reprint-import.php is
        // the live case — define EXIT_AFTER_IMPORT=false so cleanup
        // logic can run AFTER pull, in the same try/catch scope as
        // the include. Without that knob the bare exit() jumps the
        // embedder's stack and forces it to wire activation through
        // register_shutdown_function, where exceptions have no
        // channel to surface as ndjson events. Stash the exit code on
        // a global so the embedder can read it.
        $GLOBALS['REPRINT_IMPORTER_EXIT_CODE'] = (int) $client->exit_code;
        if (!defined('EXIT_AFTER_IMPORT') || EXIT_AFTER_IMPORT) {
            exit($client->exit_code);
        }
        return;
    } catch (\Throwable $e) {
        $is_tty = function_exists("posix_isatty") && posix_isatty(STDERR);
        $error_code = isset($client) ? $client->last_error_code : null;
        if ($is_tty) {
            fwrite(STDERR, "\nError: " . $e->getMessage() . "\n");
        } else {
            $error = [
                "error" => $e->getMessage(),
                "error_code" => $error_code,
                "exception" => get_class($e),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ];
            $json = json_encode($error);
            if ($json === false) {
                $json = '{"error":"' . addslashes($e->getMessage()) . '","exception":"' . get_class($e) . '"}';
            }
            fwrite(STDERR, $json . "\n");
        }
        $GLOBALS['REPRINT_IMPORTER_EXIT_CODE'] = 1;
        if (!defined('EXIT_AFTER_IMPORT') || EXIT_AFTER_IMPORT) {
            exit(1);
        }
        // When EXIT_AFTER_IMPORT is false we still want the embedder
        // to see the failure — re-throw so its try/catch around
        // `include $phar` can surface a proper `{type:'error'}` event.
        throw $e;
    }
}
