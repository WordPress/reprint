<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Session errors become authenticated API JSON, never HTML output.

if (!class_exists('Site_Export_Multipart_Stream_Input', false)) {
    require_once __DIR__ . '/class-multipart-stream-input.php';
}

/**
 * Stages and safely applies one resumable push session to a live site tree.
 *
 * Uploads mutate only the session's private `work/` directory. File pieces are
 * appended under `work/partial/` and become eligible for commit only after the
 * complete file is renamed into `work/files/`. Directories and symlinks are
 * complete values in `work/files/` as soon as their metadata part is accepted.
 * Deletes are an append-only JSONL set. The workspace itself is therefore the
 * source of truth for what the target has accepted; it does not trust or echo a
 * sender-owned cursor.
 *
 * A commit has four durable phases. `materializing` turns the append-only
 * upload journals into a disk-backed action plan, `preparing` builds private
 * candidates, `switching` publishes WordPress maintenance mode and replaces
 * live entries, and `cleaning` removes recovery backups before taking
 * maintenance mode down.
 * Any changed member below a configured deployment container's direct child is
 * collapsed into one complete replacement unit. Directory units start from the
 * live tree and overlay staged changes and deletes; a file or symlink unit is
 * already a complete value and is copied directly. Maintenance mode therefore
 * protects one complete deployment action for each plugin or theme instead of
 * exposing its members incrementally.
 *
 * Live replacement is a crash-recoverable pair of same-filesystem renames:
 * live to `work/backups/`, then `work/prepared/` to live. Session storage and
 * the target root must consequently be on the same device. `commit.json` is
 * the only mutable commit checkpoint and records enough physical identity to
 * reconcile a process death between either rename. `session.json` contains
 * only immutable session configuration. The append-before-publish
 * `work/staged.jsonl` manifest is the durable cursor used to resume positive
 * path materialization without trusting sender-owned state.
 *
 * Uploads are caller-driven so every part is persisted before next_change()
 * returns. The lock must always be released in a finally block:
 *
 *     $session->accept_upload($input);
 *     try {
 *         while ($session->next_change()) {
 *             $accepted_change = $session->get_current_change();
 *         }
 *     } finally {
 *         $session->finish_upload();
 *     }
 *
 * Commit work is deliberately bounded for HTTP runtimes. Call commit() again
 * while `send_next_request` is true. Once switching begins, the session cannot
 * accept uploads or be discarded; retries must drive it through cleanup so a
 * partially replaced live tree is never abandoned.
 */
final class Site_Export_Staged_Apply_Session {

    /** A session or target-wide non-blocking lock is currently owned elsewhere. */
    public const ERROR_BUSY = 1001;

    /** The requested private workspace does not exist. */
    public const ERROR_SESSION_NOT_FOUND = 1002;

    /** A filesystem operation failed without proving the session irrecoverable. */
    public const ERROR_RETRYABLE_IO = 1003;

    /** Live mutation has begun, so the caller must resume commit rather than upload or discard. */
    public const ERROR_COMMIT_REQUIRED = 1004;

    /** The live tree no longer matches the identity captured during preparation. */
    public const ERROR_LIVE_TREE_CHANGED = 1005;

    /** Durable session metadata is structurally invalid and cannot be reconciled safely. */
    public const ERROR_INVALID_STATE = 1006;

    /**
     * Maximum bytes accepted in a target-relative path or literal symlink target.
     *
     * Paths arrive inside headers and delete-list records. Bounding them keeps
     * diagnostics, private path construction, and an unterminated delete tail
     * independent of the total request size.
     */
    private const MAX_PATH_BYTES = 4096;

    /**
     * Maximum upload body piece read before it is written to private storage.
     *
     * This matches Site_Export_Multipart_Stream_Input's hard per-read ceiling,
     * keeping the target's in-memory payload unit at 256 KiB.
     */
    private const BODY_PIECE_BYTES = 262144;

    /**
     * Maximum bytes read from one session or commit JSON metadata file.
     *
     * Metadata is target-generated and normally small. The 1 MiB ceiling turns
     * corruption or replacement with an unexpected large file into a bounded
     * failure before JSON decoding.
     */
    private const MAX_METADATA_BYTES = 1048576;

    /** Maximum bytes in one target-generated commit-plan JSONL record. */
    private const MAX_COMMIT_RECORD_BYTES = 32768;

    /**
     * Canonical server-private storage root containing all apply sessions.
     *
     * It is verified to share a filesystem device with $target_root.
     *
     * @var string
     */
    private $storage_dir;

    /**
     * Canonical live site root whose relative entries are replaced at commit.
     *
     * @var string
     */
    private $target_root;

    /**
     * Target-derived 32-character hexadecimal identity for this workspace.
     *
     * @var string
     */
    private $session_id;

    /**
     * Target-relative paths which neither changes nor their ancestors may replace.
     *
     * This includes the running Reprint code and session storage when it lives
     * below the target root.
     *
     * @var string[]
     */
    private $protected_paths;

    /** @var string[] Target-relative plugin/theme container roots. */
    private $deployment_roots;

    /** @var string Absolute private directory for this session id. */
    private $session_dir;

    /** @var string Private mutable workspace below $session_dir. */
    private $work_dir;

    /** @var string Complete staged files, directories, and symlinks eligible for commit. */
    private $files_dir;

    /** @var string Incomplete regular files keyed by their final relative paths. */
    private $partial_dir;

    /** @var string Fully materialized replacement entries awaiting live renames. */
    private $prepared_dir;

    /** @var string Former live entries retained until every switch is checkpointed. */
    private $backups_dir;

    /** @var string Append-only JSONL records for validated target-relative deletes. */
    private $deletes_path;

    /** @var string Append-before-publish JSONL path evidence for work/files/. */
    private $staged_paths_path;

    /** @var string Immutable session id, target root, and protected-path metadata. */
    private $session_metadata_path;

    /** @var string Sole mutable checkpoint for planning, prepare, switch, and cleanup progress. */
    private $commit_path;

    /** @var string Per-session advisory lock shared by upload, status, commit, and discard. */
    private $lock_path;

    /**
     * Private hard-link identity used to prove ownership of live `.maintenance`.
     *
     * @var string
     */
    private $maintenance_identity_path;

    /** @var string Private disk-backed commit planning directory. */
    private $commit_work_dir;

    /** @var string One record for each unique candidate action path. */
    private $action_candidates_path;

    /** @var string Final disk-backed basic action sequence. */
    private $actions_path;

    /** @var string Prepared actions enriched with live and candidate identities. */
    private $prepared_actions_path;

    /** @var string Resumable traversal queue for the action currently being prepared. */
    private $prepare_queue_path;

    /** @var string Disk-backed directory-mode plans grouped by action and path depth. */
    private $prepare_modes_dir;

    /** @var string Flat hashed exact-delete lookup. */
    private $delete_index_dir;

    /** @var string Flat hashed lookup for paths having deleted descendants. */
    private $delete_descendants_index_dir;

    /** @var string Flat hashed exact-staged-entry lookup. */
    private $staged_index_dir;

    /** @var string Flat hashed current action record lookup. */
    private $action_index_dir;

    /**
     * Open exclusive session lock held for the current multipart request.
     *
     * Non-null means accept_upload() succeeded and finish_upload() is required.
     *
     * @var resource|null
     */
    private $upload_lock = null;

    /**
     * Multipart input currently driven by next_change(), or null outside upload.
     *
     * @var Site_Export_Multipart_Stream_Input|null
     */
    private $upload_input = null;

    /**
     * Target-confirmed result of the most recent successful next_change() call.
     *
     * It is cleared before advancing and when the upload closes, so callers
     * cannot mistake a previous part's result for current target state.
     *
     * @var array<string,mixed>|null
     */
    private $current_change = null;

    /** @var int Largest declared Content-Length accepted for one part in this request. */
    private $maximum_upload_part_bytes = PHP_INT_MAX;

    /** @var int Largest number of MIME parts accepted in this request. */
    private $maximum_upload_parts = 128;

    /** @var int Parts whose headers have been accepted in the current request. */
    private $upload_parts_read = 0;

    /**
     * Derives every private path for a validated session configuration.
     *
     * Construction performs no I/O. create() and open() are the public
     * factories which canonicalize roots and validate the on-disk layout.
     *
     * @param string   $storage_dir Canonical private session storage root.
     * @param string   $target_root Canonical live site root.
     * @param string   $session_id Validated target-owned session identity.
     * @param string[] $protected_paths Normalized target-relative protected paths.
     * @param string[] $deployment_roots Normalized plugin/theme container roots.
     */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths, array $deployment_roots) {
        $this->storage_dir = rtrim($storage_dir, '/');
        $this->target_root = $target_root === '/' ? '/' : rtrim($target_root, '/');
        $this->session_id = $session_id;
        $this->protected_paths = $protected_paths;
        $this->deployment_roots = $deployment_roots;
        $this->session_dir = $this->storage_dir . '/apply-sessions/' . $session_id;
        $this->work_dir = $this->session_dir . '/work';
        $this->files_dir = $this->work_dir . '/files';
        $this->partial_dir = $this->work_dir . '/partial';
        $this->prepared_dir = $this->work_dir . '/prepared';
        $this->backups_dir = $this->work_dir . '/backups';
        $this->deletes_path = $this->work_dir . '/deletes.jsonl';
        $this->staged_paths_path = $this->work_dir . '/staged.jsonl';
        $this->session_metadata_path = $this->session_dir . '/session.json';
        $this->commit_path = $this->session_dir . '/commit.json';
        $this->lock_path = $this->session_dir . '/lock';
        $this->maintenance_identity_path = $this->work_dir . '/maintenance.php';
        $this->commit_work_dir = $this->work_dir . '/commit';
        $this->action_candidates_path = $this->commit_work_dir . '/action-candidates.jsonl';
        $this->actions_path = $this->commit_work_dir . '/actions.jsonl';
        $this->prepared_actions_path = $this->commit_work_dir . '/prepared-actions.jsonl';
        $this->prepare_queue_path = $this->commit_work_dir . '/prepare-queue.jsonl';
        $this->prepare_modes_dir = $this->commit_work_dir . '/prepare-modes';
        $this->delete_index_dir = $this->commit_work_dir . '/delete-index';
        $this->delete_descendants_index_dir = $this->commit_work_dir . '/delete-descendants-index';
        $this->staged_index_dir = $this->commit_work_dir . '/staged-index';
        $this->action_index_dir = $this->commit_work_dir . '/action-index';
    }

    /**
     * Creates or idempotently reopens the workspace for one target-derived id.
     *
     * Both roots are canonicalized and required to be real directories on the
     * same filesystem before any session is exposed. A new workspace receives
     * mode-0700 staging directories, an advisory lock, and immutable metadata.
     * If the session id already exists, its complete private layout and stored
     * configuration must match the current server configuration exactly.
     *
     * @param string   $storage_dir Private root in which `apply-sessions/` lives.
     * @param string   $target_root Live site root changed by commit().
     * @param string[] $protected_paths Target-relative paths excluded from changes.
     * @param string   $session_id Target-derived 32-character hexadecimal id.
     * @param string[] $deployment_roots Plugin/theme containers whose children switch atomically.
     * @return self New or existing validated session.
     *
     * @throws InvalidArgumentException If configuration is unsafe or crosses devices.
     * @throws RuntimeException If creation is busy or private storage cannot be written.
     */
    public static function create(string $storage_dir, string $target_root, array $protected_paths, string $session_id, array $deployment_roots): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', true);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::protect_session_storage($storage_dir, $target_root, $protected_paths);
        self::require_same_filesystem($storage_dir, $target_root);
        $protected_paths = self::normalize_protected_paths($protected_paths);
        $deployment_roots = self::normalize_deployment_roots($deployment_roots);

        $sessions_dir = $storage_dir . '/apply-sessions';
        if (!is_dir($sessions_dir) && !@mkdir($sessions_dir, 0700, true) && !is_dir($sessions_dir)) {
            throw new RuntimeException('Could not create staged apply sessions directory ' . $sessions_dir . '.', self::ERROR_RETRYABLE_IO);
        }
        $sessions_dir = self::require_directory($sessions_dir, 'staged apply sessions', false);
        self::require_same_filesystem($sessions_dir, $target_root);
        $creation_lock = @fopen($sessions_dir . '/create.lock', 'c+b');
        if ($creation_lock === false) {
            throw new RuntimeException('Could not open the staged apply creation lock.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($creation_lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Staged apply session creation is busy. Retry the create request.', self::ERROR_BUSY);
            }
            $session = new self($storage_dir, $target_root, $session_id, $protected_paths, $deployment_roots);
            if (file_exists($session->session_dir) || is_link($session->session_dir)) {
                $session->assert_workspace_layout();
                $session->read_session_metadata();
                return $session;
            }
            if (!@mkdir($session->session_dir, 0700, true)) {
                throw new RuntimeException('Could not create staged apply session ' . $session_id . '.', self::ERROR_RETRYABLE_IO);
            }
            foreach ([$session->files_dir, $session->partial_dir, $session->prepared_dir, $session->backups_dir] as $directory) {
                if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    self::remove_tree($session->session_dir);
                    throw new RuntimeException('Could not create staged apply workspace directory ' . $directory . '.', self::ERROR_RETRYABLE_IO);
                }
            }
            if (@file_put_contents($session->lock_path, '') === false) {
                self::remove_tree($session->session_dir);
                throw new RuntimeException('Could not create staged apply session lock.', self::ERROR_RETRYABLE_IO);
            }
            if (@file_put_contents($session->staged_paths_path, '') === false) {
                self::remove_tree($session->session_dir);
                throw new RuntimeException('Could not create the staged positive-path journal.', self::ERROR_RETRYABLE_IO);
            }
            $session->assert_workspace_layout();
            $session->write_json($session->session_metadata_path, [
                'version' => 1,
                'session_id' => $session_id,
                'target_root_b64' => base64_encode($target_root),
                'protected_paths_b64' => array_map('base64_encode', $protected_paths),
                'deployment_roots_b64' => array_map('base64_encode', $deployment_roots),
            ]);
            return $session;
        } finally {
            flock($creation_lock, LOCK_UN);
            fclose($creation_lock);
        }
    }

    /**
     * Opens an existing workspace after verifying its private layout and identity.
     *
     * The immutable target root and protected paths recorded at creation must
     * still match server configuration. This prevents a session id created for
     * one destination from being replayed after the endpoint is reconfigured.
     *
     * @param string   $storage_dir Existing private session storage root.
     * @param string   $target_root Current live site root.
     * @param string   $session_id Target-owned session identity to open.
     * @param string[] $protected_paths Current target-relative protected paths.
     * @param string[] $deployment_roots Current plugin/theme container roots.
     * @return self Validated existing session.
     *
     * @throws InvalidArgumentException If configuration is unsafe or crosses devices.
     * @throws RuntimeException If the session is missing, corrupt, or mismatched.
     */
    public static function open(string $storage_dir, string $target_root, string $session_id, array $protected_paths, array $deployment_roots): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::protect_session_storage($storage_dir, $target_root, $protected_paths);
        self::require_same_filesystem($storage_dir, $target_root);
        $sessions_dir = self::require_directory($storage_dir . '/apply-sessions', 'staged apply sessions', false);
        self::require_same_filesystem($sessions_dir, $target_root);
        $session = new self(
            $storage_dir,
            $target_root,
            $session_id,
            self::normalize_protected_paths($protected_paths),
            self::normalize_deployment_roots($deployment_roots)
        );
        if (!file_exists($session->session_dir) && !is_link($session->session_dir)) {
            throw new RuntimeException('The staged apply session does not exist: ' . $session_id . '.', self::ERROR_SESSION_NOT_FOUND);
        }
        $session->assert_workspace_layout();
        $session->read_session_metadata();
        return $session;
    }

    /**
     * Returns the target-owned identity used by control and upload endpoints.
     *
     * @return string 32-character lowercase hexadecimal session id.
     */
    public function get_session_id(): string {
        return $this->session_id;
    }

    /**
     * Returns the absolute private directory which contains this workspace.
     *
     * Recovery tooling uses this path to locate immutable and commit metadata.
     *
     * @return string Absolute session directory.
     */
    public function get_session_directory(): string {
        return $this->session_dir;
    }

    /**
     * Opens one authenticated multipart request for caller-driven staging.
     *
     * This acquires the per-session lock without waiting, repairs any single
     * incomplete delete-list record left by a process death, and freezes the
     * target limits used by subsequent next_change() calls. It does not read
     * the request body. An existing commit checkpoint closes uploads because
     * the staged final tree has already been frozen.
     *
     * The caller must invoke finish_upload() in a finally block after this
     * method succeeds, including when next_change() throws.
     *
     * @param Site_Export_Multipart_Stream_Input $input Validated request-body reader.
     * @param int $maximum_part_bytes Largest declared part body allowed by target policy.
     * @param int $maximum_parts Largest number of parts allowed in this request.
     *
     * @throws LogicException If another upload is already open on this object.
     * @throws InvalidArgumentException If either request limit is not positive.
     * @throws RuntimeException If the session is busy, missing, or already committing.
     */
    public function accept_upload(Site_Export_Multipart_Stream_Input $input, int $maximum_part_bytes = PHP_INT_MAX, int $maximum_parts = 128): void {
        if ($this->upload_lock !== null) {
            throw new LogicException('A staged apply upload is already open; call finish_upload() first.');
        }
        if ($maximum_part_bytes <= 0) {
            throw new InvalidArgumentException('The staged apply maximum multipart part size must be greater than zero.');
        }
        if ($maximum_parts <= 0) {
            throw new InvalidArgumentException('The staged apply maximum multipart part count must be greater than zero.');
        }
        if (!is_dir($this->session_dir)) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id . '.', self::ERROR_SESSION_NOT_FOUND);
        }
        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open the staged apply session lock.', self::ERROR_RETRYABLE_IO);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry the upload.', self::ERROR_BUSY);
        }
        try {
            $this->assert_workspace_layout();
            if (is_file($this->commit_path)) {
                throw new RuntimeException('Uploads are closed because this staged apply session is committing.', self::ERROR_COMMIT_REQUIRED);
            }
            $this->repair_delete_tail();
            $this->repair_staged_paths_tail();
            $this->upload_lock = $lock;
            $this->upload_input = $input;
            $this->current_change = null;
            $this->maximum_upload_part_bytes = $maximum_part_bytes;
            $this->maximum_upload_parts = $maximum_parts;
            $this->upload_parts_read = 0;
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
    }

    /**
     * Reads, validates, and durably stages exactly one complete multipart part.
     *
     * File parts append only at the byte offset already present in
     * `work/partial/`; offset zero restarts that path. Complete files are
     * promoted into `work/files/`. Directory and symlink parts replace their
     * staged path as complete metadata values, and delete-list parts append
     * complete NUL-delimited paths as recoverable JSONL records.
     *
     * True means get_current_change() now describes target-confirmed durable
     * state for this part. False means the request ended with a clean closing
     * boundary. Invalid input makes the remaining request terminal before any
     * later part is read, while already flushed partial bytes remain available
     * for status and resume.
     *
     * @return bool True after one part is staged, false at the closing boundary.
     *
     * @throws LogicException If accept_upload() has not opened a request.
     * @throws InvalidArgumentException If part headers, offsets, or paths are invalid.
     * @throws RuntimeException If request input or durable storage fails.
     */
    public function next_change(): bool {
        if ($this->upload_lock === null || $this->upload_input === null) {
            throw new LogicException('Accept an upload before reading changes.');
        }
        $this->current_change = null;
        try {
            if (!$this->upload_input->next_part()) {
                return false;
            }
            if ($this->upload_parts_read >= $this->maximum_upload_parts) {
                throw new InvalidArgumentException(
                    'Multipart upload contains more than the target maximum of '
                    . $this->maximum_upload_parts . ' parts per request.'
                );
            }
            ++$this->upload_parts_read;
            $headers = $this->upload_input->get_current_headers();
            $part_bytes = $this->require_non_negative_header($headers, 'content-length');
            if ($part_bytes > $this->maximum_upload_part_bytes) {
                throw new InvalidArgumentException(
                    'Multipart part Content-Length ' . $part_bytes . ' exceeds the target maximum of '
                    . $this->maximum_upload_part_bytes . ' bytes.'
                );
            }
            $type = $headers['x-chunk-type'] ?? null;
            if (!is_string($type) || $type === '') {
                throw new InvalidArgumentException('Multipart push part requires an X-Chunk-Type header.');
            }
            if ($type === 'file') {
                $this->stage_file_part($headers, $part_bytes);
            } elseif ($type === 'directory') {
                $this->stage_directory_part($headers, $part_bytes);
            } elseif ($type === 'directory-mode') {
                $this->stage_directory_mode_part($headers, $part_bytes);
            } elseif ($type === 'symlink') {
                $this->stage_symlink_part($headers, $part_bytes);
            } elseif ($type === 'delete-list') {
                $this->stage_delete_list_part($headers);
            } else {
                throw new InvalidArgumentException('Unsupported multipart X-Chunk-Type ' . json_encode($type) . '.');
            }
            return true;
        } catch (Throwable $exception) {
            // A malformed part is terminal for this request. Existing durable
            // partial bytes remain useful evidence for the next request.
            $this->upload_input = null;
            throw $exception;
        }
    }

    /**
     * Ends the upload request, clears its transient state, and releases its lock.
     *
     * This does not discard durable partial or complete changes. It only closes
     * the in-process lifecycle started by accept_upload(), making the workspace
     * available to status, another upload request, or commit.
     *
     * @throws LogicException If no upload request is open.
     */
    public function finish_upload(): void {
        if ($this->upload_lock === null) {
            throw new LogicException('No staged apply upload is open; call accept_upload() first.');
        }
        $lock = $this->upload_lock;
        $this->upload_lock = null;
        $this->upload_input = null;
        $this->current_change = null;
        $this->maximum_upload_part_bytes = PHP_INT_MAX;
        $this->maximum_upload_parts = 128;
        $this->upload_parts_read = 0;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Returns the target-confirmed result of the most recently staged part.
     *
     * A file result reports its durable accepted byte count and whether it is
     * partial or complete. Metadata and delete-list results report only work
     * already persisted by the target. Null means no next_change() call has
     * succeeded in the current upload lifecycle.
     *
     * @return array<string,mixed>|null Current target-derived change result.
     */
    public function get_current_change(): ?array {
        return $this->current_change;
    }

    /**
     * Returns target-derived upload and commit state for explicitly requested paths.
     *
     * Each requested path is inspected in `work/files/` and `work/partial/` at
     * the time of this call. Missing paths report zero accepted bytes. Sender
     * offsets are never accepted as input or reflected as truth. The response
     * also reports the durable commit phase, defaulting to `uploading` before a
     * commit checkpoint exists.
     *
     * @param string[] $paths Raw target-relative paths to inspect.
     * @return array<string,mixed> Session phase and one state record per path.
     *
     * @throws InvalidArgumentException If any requested path is unsafe or protected.
     * @throws RuntimeException If session state cannot be read consistently.
     */
    public function get_status(array $paths = []): array {
        return $this->with_session_lock(function () use ($paths): array {
            $this->repair_delete_tail();
            $reported_paths = [];
            foreach ($paths as $path) {
                $this->validate_path($path);
                $partial = $this->private_path($this->partial_dir, $path);
                $complete = $this->private_path($this->files_dir, $path);
                // Do not let a completed staged symlink turn a later status
                // probe for one of its descendants into a read outside work/.
                $this->ensure_private_parent($partial, false);
                $this->ensure_private_parent($complete, false);
                $complete_identity = $this->path_identity($complete);
                if ($complete_identity !== null) {
                    $reported_paths[] = [
                        'path_b64' => base64_encode($path),
                        'state' => 'complete',
                        'type' => $complete_identity['type'],
                        'accepted_bytes' => $complete_identity['type'] === 'file' ? $complete_identity['size'] : 0,
                    ];
                    continue;
                }
                $partial_identity = $this->path_identity($partial);
                if ($partial_identity !== null) {
                    if ($partial_identity['type'] !== 'file') {
                        throw new RuntimeException('Partial staging path ' . $this->describe_path($path) . ' is not a regular file.', self::ERROR_RETRYABLE_IO);
                    }
                    $reported_paths[] = [
                        'path_b64' => base64_encode($path),
                        'state' => 'partial',
                        'type' => 'file',
                        'accepted_bytes' => $partial_identity['size'],
                    ];
                    continue;
                }
                $reported_paths[] = [
                    'path_b64' => base64_encode($path),
                    'state' => 'missing',
                    'accepted_bytes' => 0,
                ];
            }
            $commit = $this->read_json($this->commit_path);
            if (is_array($commit)) {
                $this->require_valid_commit_state($commit);
            }
            return [
                'session_id' => $this->session_id,
                'phase' => is_array($commit) ? $commit['phase'] : 'uploading',
                'paths' => $reported_paths,
            ];
        });
    }

    /**
     * Appends one file part at the offset confirmed by work/partial/.
     *
     * Offset zero always replaces any earlier partial or complete artifact.
     * A file becomes commit-visible only after its declared total size has
     * been written and the partial file is renamed into work/files/.
     *
     * @param array<string,string> $headers Validated MIME headers for the part.
     * @param int $part_bytes Exact body bytes declared by Content-Length.
     */
    private function stage_file_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-file-path', 'x-file-size', 'x-chunk-offset', 'x-file-mode'], true)) {
                throw new InvalidArgumentException('Multipart file part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-file-path');
        $total_bytes = $this->require_non_negative_header($headers, 'x-file-size');
        $offset = $this->require_non_negative_header($headers, 'x-chunk-offset');
        $mode = isset($headers['x-file-mode']) ? $this->require_mode_header($headers, 'x-file-mode') : null;
        if ($offset > $total_bytes || $part_bytes > $total_bytes - $offset) {
            throw new InvalidArgumentException(
                'File part for ' . $this->describe_path($path) . ' declares offset ' . $offset
                . ', Content-Length ' . $part_bytes . ', and total size ' . $total_bytes . ', which do not fit.'
            );
        }

        $partial_path = $this->private_path($this->partial_dir, $path);
        $complete_path = $this->private_path($this->files_dir, $path);
        // Validate every private parent before lstat() or unlink() touches a
        // leaf. A prior valid symlink part must not make a later invalid
        // child path resolve through that symlink.
        $this->ensure_private_parent($partial_path);
        $this->ensure_private_parent($complete_path);
        if ($offset === 0) {
            $this->remove_entry($partial_path);
            $this->remove_entry($complete_path);
        } else {
            $partial_identity = $this->path_identity($partial_path);
            if ($partial_identity === null || $partial_identity['type'] !== 'file') {
                throw new InvalidArgumentException(
                    'File part for ' . $this->describe_path($path) . ' starts at offset ' . $offset
                    . ', but work/partial has no regular file at that offset. Start at offset 0.'
                );
            }
            if ($partial_identity['size'] !== $offset) {
                throw new InvalidArgumentException(
                    'File part for ' . $this->describe_path($path) . ' starts at offset ' . $offset
                    . ', but work/partial contains ' . $partial_identity['size'] . ' bytes.'
                );
            }
            if ($mode !== null && $partial_identity['permissions'] !== $mode) {
                throw new InvalidArgumentException(
                    'File part for ' . $this->describe_path($path) . ' declares mode '
                    . sprintf('0%o', $mode) . ', but work/partial has mode '
                    . sprintf('0%o', $partial_identity['permissions']) . '. Start at offset 0.'
                );
            }
        }

        $file = @fopen($partial_path, $offset === 0 ? 'c+b' : 'r+b');
        if ($file === false) {
            throw new RuntimeException('Could not open partial file ' . $this->describe_path($path) . ' for staging.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($file, $offset, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek partial file ' . $this->describe_path($path) . ' to offset ' . $offset . '.', self::ERROR_RETRYABLE_IO);
            }
            while ($this->upload_input->remaining_body_bytes() > 0) {
                $piece = $this->upload_input->read_body_piece(self::BODY_PIECE_BYTES);
                $this->write_all($file, $piece, 'partial file ' . $this->describe_path($path));
            }
            if (!fflush($file)) {
                throw new RuntimeException('Could not flush partial file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($file);
        }
        if ($mode !== null && !@chmod($partial_path, $mode)) {
            throw new RuntimeException('Could not apply staged file mode for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }

        $actual = $this->path_identity($partial_path);
        if ($actual === null || $actual['type'] !== 'file' || $actual['size'] !== $offset + $part_bytes) {
            throw new RuntimeException('Partial file size changed while staging ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        if ($actual['size'] === $total_bytes) {
            $this->ensure_private_parent($complete_path);
            // Journal the path before rename makes it commit-visible. A killed
            // request can therefore leave either a harmless record for an
            // absent path or both the record and completed staged value, never
            // a completed value which commit planning cannot resume finding.
            $this->append_staged_path_record($path);
            if (!@rename($partial_path, $complete_path)) {
                throw new RuntimeException('Could not promote completed staged file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            $this->current_change = [
                'path_b64' => base64_encode($path),
                'state' => 'complete',
                'type' => 'file',
                'accepted_bytes' => $total_bytes,
            ];
            return;
        }
        $this->current_change = [
            'path_b64' => base64_encode($path),
            'state' => 'partial',
            'type' => 'file',
            'accepted_bytes' => $actual['size'],
        ];
    }

    /**
     * Replaces a staged path with an explicitly empty final directory.
     *
     * Directory parts have no body. Removing any earlier staged value before
     * mkdir makes the metadata part a complete replacement rather than a hint
     * to create parents for later children.
     *
     * @param array<string,string> $headers MIME headers containing the encoded path.
     * @param int $part_bytes Exact body bytes, which must be zero.
     */
    private function stage_directory_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-directory-path', 'x-directory-mode'], true)) {
                throw new InvalidArgumentException('Multipart directory part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-directory-path');
        $mode = isset($headers['x-directory-mode']) ? $this->require_mode_header($headers, 'x-directory-mode') : null;
        if ($part_bytes !== 0) {
            throw new InvalidArgumentException('Multipart directory part must have Content-Length 0.');
        }
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        // A directory part represents an explicitly empty final directory,
        // not merely a request to create a missing parent.
        $this->remove_entry($target);
        $this->append_staged_path_record($path);
        if (!is_dir($target) && !@mkdir($target, 0700)) {
            throw new RuntimeException('Could not stage directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        if ($mode !== null && !@chmod($target, $mode)) {
            throw new RuntimeException('Could not apply staged directory mode for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0];
    }

    /**
     * Records the final mode of a non-empty directory without emptying it.
     *
     * The private directory is only an overlay traversal point. Its requested
     * mode lives in the append-before-publish journal record so a killed
     * request cannot expose unjournaled metadata.
     *
     * @param array<string,string> $headers MIME headers containing path and mode.
     * @param int $part_bytes Exact body bytes, which must be zero.
     */
    private function stage_directory_mode_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-directory-path', 'x-directory-mode'], true)) {
                throw new InvalidArgumentException('Multipart directory-mode part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-directory-path');
        $mode = $this->require_mode_header($headers, 'x-directory-mode');
        if ($part_bytes !== 0) {
            throw new InvalidArgumentException('Multipart directory-mode part must have Content-Length 0.');
        }
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        $identity = $this->path_identity($target);
        if ($identity !== null && $identity['type'] !== 'directory') {
            throw new InvalidArgumentException(
                'Directory mode for ' . $this->describe_path($path) . ' conflicts with a staged ' . $identity['type'] . '.'
            );
        }
        $this->append_staged_path_record($path, ['kind' => 'directory-mode', 'mode' => $mode]);
        if ($identity === null && !@mkdir($target, 0700) && !is_dir($target)) {
            throw new RuntimeException('Could not stage directory mode for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'directory-mode', 'accepted_bytes' => 0];
    }

    /**
     * Replaces a staged path with a symlink preserving its literal target.
     *
     * The target is decoded as an arbitrary byte string and is neither resolved
     * nor followed. This preserves relative links while private-parent checks
     * prevent a staged link from redirecting later writes outside the workspace.
     *
     * @param array<string,string> $headers MIME headers containing path and target.
     * @param int $part_bytes Exact body bytes, which must be zero.
     */
    private function stage_symlink_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-symlink-path', 'x-symlink-target'], true)) {
                throw new InvalidArgumentException('Multipart symlink part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-symlink-path');
        $target_value = $this->decode_path_header($headers, 'x-symlink-target', false);
        if ($target_value === '' || strpos($target_value, "\0") !== false || strlen($target_value) > self::MAX_PATH_BYTES) {
            throw new InvalidArgumentException('Symlink target for ' . $this->describe_path($path) . ' is invalid.');
        }
        if ($part_bytes !== 0) {
            throw new InvalidArgumentException('Multipart symlink part must have Content-Length 0.');
        }
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        $this->remove_entry($target);
        $this->append_staged_path_record($path);
        if (!@symlink($target_value, $target)) {
            throw new RuntimeException('Could not stage symlink ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0];
    }

    /**
     * Records one path before its completed staged value becomes visible.
     *
     * Duplicate records are intentional: restarting or replacing a path
     * appends new evidence, while commit planning de-duplicates the current
     * value in its disk-backed index. A record whose value never became visible
     * is ignored, which makes the append-before-publish ordering crash-safe.
     */
    private function append_staged_path_record(string $path, array $metadata = []): void {
        $encoded = json_encode(['path_b64' => base64_encode($path)] + $metadata, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) + 1 > self::MAX_COMMIT_RECORD_BYTES) {
            throw new RuntimeException('Could not encode staged positive path ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $handle = @fopen($this->staged_paths_path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not open the staged positive-path journal.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $this->write_all($handle, $encoded . "\n", 'staged positive-path journal');
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the staged positive-path journal.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Appends complete NUL-delimited paths as JSONL records.
     *
     * A process death can leave only the last record incomplete; the next
     * request repairs that tail before accepting or reporting session state.
     *
     * @param array<string,string> $headers Validated delete-list MIME headers.
     */
    private function stage_delete_list_part(array $headers): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type'], true)) {
                throw new InvalidArgumentException('Multipart delete-list part does not allow header ' . json_encode($name) . '.');
            }
        }
        $handle = @fopen($this->deletes_path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not open the staged delete list.', self::ERROR_RETRYABLE_IO);
        }
        $tail = '';
        $accepted = 0;
        try {
            while ($this->upload_input->remaining_body_bytes() > 0) {
                $tail .= $this->upload_input->read_body_piece(self::BODY_PIECE_BYTES);
                while (($delimiter = strpos($tail, "\0")) !== false) {
                    $path = substr($tail, 0, $delimiter);
                    $tail = (string) substr($tail, $delimiter + 1);
                    $this->validate_path($path);
                    $encoded_record = json_encode(['path_b64' => base64_encode($path)]);
                    if ($encoded_record === false) {
                        throw new RuntimeException('Could not encode a staged delete path.', self::ERROR_RETRYABLE_IO);
                    }
                    $record = $encoded_record . "\n";
                    $this->write_all($handle, $record, 'staged delete list');
                    ++$accepted;
                }
                if (strlen($tail) > self::MAX_PATH_BYTES) {
                    throw new InvalidArgumentException('A delete-list path exceeds ' . self::MAX_PATH_BYTES . ' bytes.');
                }
            }
            if ($tail !== '') {
                throw new InvalidArgumentException('A delete-list part ends with a path not terminated by NUL.');
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the staged delete list.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
        $this->current_change = ['state' => 'complete', 'type' => 'delete-list', 'accepted_paths' => $accepted];
    }

    /**
     * Advances the durable commit by a bounded number of deployment actions.
     *
     * The first call freezes uploads by creating `commit.json`. Materialization
     * then derives a disk-backed action list from the positive-path and delete
     * manifests. Preparation may span many calls without maintenance mode
     * because it only copies private candidates. Switching publishes or
     * refreshes the session-owned WordPress `.maintenance` marker before any
     * live rename. Cleanup retains maintenance mode until all switches are
     * checkpointed and recovery backups are gone.
     *
     * The same call may cross phase boundaries while budget remains. A retry
     * resumes from the checkpoint, including a process death between either
     * rename of one action. The result deliberately counts only visible live
     * replacements as `files_applied`; private preparation and cleanup do not
     * inflate that number.
     *
     * @param int $maximum_steps Maximum bounded materialize, prepare, or switch
     *     operations in this call.
     * @return array<string,mixed> Durable phase, progress counts, and whether
     *     another commit request is required.
     *
     * @throws InvalidArgumentException If the step budget is not positive.
     * @throws RuntimeException If the target is busy, changed, or cannot be
     *     advanced without losing recovery evidence.
     *
     */
    public function commit(int $maximum_steps = 1): array {
        if ($maximum_steps <= 0) {
            throw new InvalidArgumentException('The staged apply commit step limit must be greater than zero.');
        }
        return $this->with_session_lock(function () use ($maximum_steps): array {
            $state = $this->read_json($this->commit_path);
            if (is_array($state)) {
                $this->require_valid_commit_state($state);
            }
            if (is_array($state) && ($state['phase'] ?? null) === 'complete') {
                $this->release_target();
                return [
                    'session_id' => $this->session_id,
                    'phase' => 'complete',
                    'files_applied' => 0,
                    'files_remaining' => 0,
                    'errors' => [],
                    'send_next_request' => false,
                ];
            }
            if ($state === null) {
                $state = $this->start_commit();
            }
            $this->claim_target();

            $files_applied = 0;
            $steps = 0;
            while ($steps < $maximum_steps && $state['phase'] === 'materializing') {
                $this->materialize_commit_action($state);
                ++$steps;
                $this->write_json($this->commit_path, $state);
            }

            while ($steps < $maximum_steps && $state['phase'] === 'preparing') {
                if ($state['current_prepare'] === null) {
                    $record = $this->read_commit_record($this->actions_path, (int) $state['prepare_offset']);
                    if ($record === null) {
                        $state['phase'] = 'switching';
                        $this->write_json($this->commit_path, $state);
                        break;
                    }
                    $state['current_prepare'] = $this->start_preparing_action(
                        $record['value'],
                        $record['next_offset'],
                        (int) $state['prepare_index']
                    );
                } elseif ($this->advance_preparing_action($state['current_prepare'])) {
                    $this->truncate_file_to($this->prepared_actions_path, (int) $state['prepared_actions_bytes']);
                    $state['prepared_actions_bytes'] = $this->append_commit_record(
                        $this->prepared_actions_path,
                        $state['current_prepare']['action']
                    );
                    $state['prepare_offset'] = $state['current_prepare']['action_next_offset'];
                    ++$state['prepare_index'];
                    $state['current_prepare'] = null;
                }
                ++$steps;
                $this->write_json($this->commit_path, $state);
            }

            if ($state['phase'] === 'switching') {
                // Detect drift before publishing maintenance whenever no
                // rename transition is already in flight. A failed preflight
                // must not strand a site in maintenance mode when no live
                // mutation from this session has begun.
                $next_record = $this->read_commit_record($this->prepared_actions_path, (int) $state['switch_offset']);
                if ($next_record !== null) {
                    $this->require_prepared_live_tree($state, $next_record['value'], (int) $state['switch_index']);
                }
                $this->publish_or_refresh_maintenance_marker($state);
                while ($steps < $maximum_steps) {
                    $record = $this->read_commit_record($this->prepared_actions_path, (int) $state['switch_offset']);
                    if ($record === null) {
                        $state['phase'] = 'cleaning';
                        $this->write_json($this->commit_path, $state);
                        break;
                    }
                    $action = $record['value'];
                    $this->require_prepared_live_tree($state, $action, (int) $state['switch_index']);
                    if ($this->switch_action($state, $action, (int) $state['switch_index'])) {
                        ++$files_applied;
                    }
                    $state['switch_offset'] = $record['next_offset'];
                    ++$state['switch_index'];
                    ++$steps;
                    $this->write_json($this->commit_path, $state);
                }
            }

            if ($state['phase'] === 'cleaning') {
                // Backups are recovery evidence until every live replacement
                // has a durable checkpoint. At this point they are private
                // cleanup only; failure leaves maintenance and is retryable.
                $this->remove_entry($this->backups_dir);
                if (!@mkdir($this->backups_dir, 0700, true) && !is_dir($this->backups_dir)) {
                    throw new RuntimeException('Could not recreate the private backup directory.', self::ERROR_RETRYABLE_IO);
                }
                $this->remove_owned_maintenance_marker($state);
                $state['phase'] = 'complete';
                $this->write_json($this->commit_path, $state);
                $this->release_target();
            }

            $remaining = max(0, (int) $state['actions_count'] - (int) $state['switch_index']);
            return [
                'session_id' => $this->session_id,
                'phase' => $state['phase'],
                'files_applied' => $files_applied,
                'files_remaining' => $state['phase'] === 'complete' ? 0 : $remaining,
                'errors' => [],
                'send_next_request' => $state['phase'] !== 'complete',
            ];
        });
    }

    /**
     * Removes a private session only while abandoning it cannot strand live changes.
     *
     * A session may be discarded during upload or preparation because neither
     * phase has mutated the live tree. Once switching begins, discard is
     * rejected with ERROR_COMMIT_REQUIRED and commit() must resume to cleanup.
     * The exclusive session lock remains held until recursive removal finishes,
     * preventing a concurrent upload from reopening the disappearing workspace.
     *
     * @return bool True when the private session directory no longer exists.
     *
     * @throws LogicException If this object still has an open upload.
     * @throws RuntimeException If the session is busy, live mutation began, or
     *     private storage cannot be removed safely.
     */
    public function discard_workspace(): bool {
        if ($this->upload_lock !== null) {
            throw new LogicException('Finish the upload before discarding its session.');
        }
        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open staged apply session lock for discard.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry discard.', self::ERROR_BUSY);
            }
            $this->assert_workspace_layout();
            $state = $this->read_json($this->commit_path);
            if (is_array($state)) {
                $this->require_valid_commit_state($state);
                if (!in_array($state['phase'] ?? null, ['materializing', 'preparing'], true)) {
                    throw new RuntimeException(
                        'This staged apply session has begun live mutation and must be resumed to completion.',
                        self::ERROR_COMMIT_REQUIRED
                    );
                }
            }
            $this->release_target();
            // Keep the session lock until the directory is gone. Releasing it
            // first would let a new upload open a workspace that discard is
            // about to recursively remove.
            self::remove_tree($this->session_dir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return !file_exists($this->session_dir);
    }

    /**
     * Freezes uploads by writing the sole mutable commit checkpoint.
     *
     * The maintenance ownership token and materialization cursors are persisted
     * before planning starts. Each later call advances the immutable upload
     * journals into the same disk-backed action sequence after a restart.
     *
     * @return array<string,mixed> Newly persisted `materializing` checkpoint.
     */
    private function start_commit(): array {
        $this->repair_delete_tail();
        $this->repair_staged_paths_tail();
        if (!@mkdir($this->commit_work_dir, 0700, true) && !is_dir($this->commit_work_dir)) {
            throw new RuntimeException('Could not create the private commit planning directory.', self::ERROR_RETRYABLE_IO);
        }
        foreach ([$this->delete_index_dir, $this->delete_descendants_index_dir, $this->staged_index_dir, $this->action_index_dir, $this->prepare_modes_dir] as $directory) {
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create a private commit index directory.', self::ERROR_RETRYABLE_IO);
            }
        }
        foreach ([$this->action_candidates_path, $this->actions_path, $this->prepared_actions_path, $this->prepare_queue_path] as $path) {
            $this->write_atomic_file($path, '', 0600);
        }
        $state = [
            'version' => 2,
            'phase' => 'materializing',
            'materialize_stage' => 'delete_index',
            'delete_index_offset' => 0,
            'staged_paths_offset' => 0,
            'delete_action_offset' => 0,
            'candidate_offset' => 0,
            'actions_bytes' => 0,
            'actions_count' => 0,
            'prepare_offset' => 0,
            'prepared_actions_bytes' => 0,
            'prepare_index' => 0,
            'current_prepare' => null,
            'switch_offset' => 0,
            'switch_index' => 0,
            'transition' => null,
            'maintenance_token' => bin2hex(random_bytes(16)),
        ];
        $this->write_json($this->commit_path, $state);
        return $state;
    }

    /**
     * Advances one durable, bounded record of commit-plan materialization.
     *
     * Deletes are indexed first, then the append-only positive-path journal is
     * consumed one record at a time. Candidate actions are de-duplicated in a
     * flat hashed index and copied to the final JSONL sequence in a last pass.
     * No phase retains the staged path set or action list in PHP memory.
     *
     * @param array<string,mixed> $state Mutable commit checkpoint.
     */
    private function materialize_commit_action(array &$state): void {
        $stage = $state['materialize_stage'] ?? null;
        if ($stage === 'delete_index') {
            $record = $this->read_staged_delete_record((int) $state['delete_index_offset']);
            if ($record === null) {
                $state['materialize_stage'] = 'staged';
                return;
            }
            $this->write_commit_index_record($this->delete_index_dir, $record['path'], ['deleted' => true]);
            foreach ($this->path_ancestors($record['path']) as $ancestor) {
                $this->write_commit_index_record($this->delete_descendants_index_dir, $ancestor, ['has_descendant' => true]);
            }
            $state['delete_index_offset'] = $record['next_offset'];
            return;
        }
        if ($stage === 'staged') {
            $record = $this->read_commit_record($this->staged_paths_path, (int) $state['staged_paths_offset']);
            if ($record !== null) {
                $path = $this->decode_commit_path($record['value'], 'staged positive path');
                $staged_path = $this->private_path($this->files_dir, $path);
                $identity = $this->path_identity($staged_path);
                $record_kind = $record['value']['kind'] ?? 'entry';
                if ($record_kind === 'directory-mode') {
                    $mode = $record['value']['mode'] ?? null;
                    if (!is_int($mode) || $mode < 0 || $mode > 07777) {
                        throw new RuntimeException('Staged directory-mode record has an invalid mode.', self::ERROR_INVALID_STATE);
                    }
                    if ($identity === null) {
                        $state['staged_paths_offset'] = $record['next_offset'];
                        return;
                    }
                    if ($identity['type'] !== 'directory') {
                        throw new RuntimeException(
                            'Staged directory-mode path is not a private directory: ' . $this->describe_path($path) . '.',
                            self::ERROR_INVALID_STATE
                        );
                    }
                    $existing = $this->read_commit_index_record($this->staged_index_dir, $path);
                    if ($existing !== null && ($existing['type'] ?? null) !== 'directory-mode') {
                        throw new RuntimeException('Staged path has conflicting value and mode operations: ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
                    }
                    $this->write_commit_index_record($this->staged_index_dir, $path, ['type' => 'directory-mode', 'mode' => $mode]);
                    $this->record_staged_action_candidate($path, true);
                } elseif ($record_kind !== 'entry') {
                    throw new RuntimeException('Staged positive-path record has an invalid kind.', self::ERROR_INVALID_STATE);
                } elseif ($identity !== null) {
                    $existing = $this->read_commit_index_record($this->staged_index_dir, $path);
                    if ($existing !== null && ($existing['type'] ?? null) === 'directory-mode') {
                        throw new RuntimeException('Staged path has conflicting value and mode operations: ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
                    }
                    if ($identity['type'] === 'directory') {
                        $handle = @opendir($staged_path);
                        if ($handle === false) {
                            throw new RuntimeException('Could not inspect staged directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                        }
                        try {
                            do {
                                $entry = readdir($handle);
                            } while ($entry === '.' || $entry === '..');
                        } finally {
                            closedir($handle);
                        }
                        // Structural parent directories are not final values;
                        // their separately journaled descendants drive actions.
                        if ($entry === false) {
                            $this->write_commit_index_record($this->staged_index_dir, $path, ['type' => 'directory']);
                            $this->record_staged_action_candidate($path);
                        }
                    } elseif ($identity['type'] === 'file' || $identity['type'] === 'symlink') {
                        $this->write_commit_index_record($this->staged_index_dir, $path, ['type' => $identity['type']]);
                        $this->record_staged_action_candidate($path);
                    } else {
                        throw new RuntimeException('Staged path has an unsupported type: ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                    }
                }
                $state['staged_paths_offset'] = $record['next_offset'];
                return;
            }
            $state['materialize_stage'] = 'delete_actions';
            return;
        }
        if ($stage === 'delete_actions') {
            $record = $this->read_staged_delete_record((int) $state['delete_action_offset']);
            if ($record === null) {
                $state['materialize_stage'] = 'actions';
                return;
            }
            $unit = $this->deployment_unit_for_path($record['path']);
            if ($unit !== null) {
                $this->record_action_candidate($unit, 'unit', false);
            } else {
                $this->record_action_candidate($record['path'], 'entry', true);
            }
            $state['delete_action_offset'] = $record['next_offset'];
            return;
        }
        if ($stage === 'actions') {
            $record = $this->read_commit_record($this->action_candidates_path, (int) $state['candidate_offset']);
            if ($record === null) {
                $state['materialize_stage'] = 'complete';
                $state['phase'] = 'preparing';
                return;
            }
            $path = $this->decode_commit_path($record['value'], 'candidate action');
            $action = $this->read_commit_index_record($this->action_index_dir, $path);
            if ($action === null) {
                throw new RuntimeException('Commit candidate action index lost ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
            }
            $this->truncate_file_to($this->actions_path, (int) $state['actions_bytes']);
            $candidate_end = $action['candidate_end'] ?? null;
            if (!is_int($candidate_end) || $candidate_end <= 0) {
                throw new RuntimeException('Commit candidate action index has no valid candidate cursor.', self::ERROR_INVALID_STATE);
            }
            if ($candidate_end !== $record['next_offset']) {
                // An append completed before its index publication and was
                // repeated after recovery. Only the candidate cursor retained
                // by the index names the current enumerable record.
                $state['candidate_offset'] = $record['next_offset'];
                return;
            }
            unset($action['candidate_end']);
            if (!$this->action_has_covering_ancestor($path)) {
                $state['actions_bytes'] = $this->append_commit_record($this->actions_path, $action);
                ++$state['actions_count'];
            }
            $state['candidate_offset'] = $record['next_offset'];
            return;
        }
        throw new RuntimeException('Commit checkpoint has an invalid materialization stage ' . json_encode($stage) . '.', self::ERROR_INVALID_STATE);
    }

    /** Records the action implied by one staged value or directory mode. */
    private function record_staged_action_candidate(string $path, bool $directory_mode = false): void {
        if (!$directory_mode && $this->read_commit_index_record($this->delete_index_dir, $path) !== null) {
            throw new RuntimeException(
                'The staged final tree both deletes and materializes ' . $this->describe_path($path) . '.',
                self::ERROR_INVALID_STATE
            );
        }
        $unit = $this->deployment_unit_for_path($path);
        if ($unit !== null) {
            $this->record_action_candidate($unit, 'unit', false);
            return;
        }
        if ($directory_mode) {
            $this->record_action_candidate($path, 'tree', false);
            return;
        }
        $tree_root = $this->indexed_structural_tree_root($path);
        if ($tree_root !== null) {
            $this->record_action_candidate($tree_root, 'tree', false);
            return;
        }
        $this->record_action_candidate($path, 'entry', false);
    }

    /** Finds a structural replacement root using disk-backed delete indexes. */
    private function indexed_structural_tree_root(string $path): ?string {
        foreach ($this->path_ancestors($path) as $ancestor) {
            if ($this->read_commit_index_record($this->delete_index_dir, $ancestor) !== null) {
                return $ancestor;
            }
            if ($this->is_protected_ancestor($ancestor)) {
                $identity = $this->path_identity($this->target_root . '/' . $ancestor);
                if ($identity === null || $identity['type'] !== 'directory') {
                    throw new InvalidArgumentException(
                        'Cannot replace non-directory ancestor ' . $this->describe_path($ancestor)
                        . ' because it contains a protected staged apply path.'
                    );
                }
                continue;
            }
            $identity = $this->path_identity($this->target_path($ancestor));
            if ($identity === null || $identity['type'] !== 'directory') {
                return $ancestor;
            }
        }
        return $this->read_commit_index_record($this->delete_descendants_index_dir, $path) !== null ? $path : null;
    }

    /**
     * Adds or strengthens one de-duplicated action marker and candidate record.
     */
    private function record_action_candidate(string $path, string $kind, bool $deleted): void {
        $record = [
            'path_b64' => base64_encode($path),
            'kind' => $kind,
            'deleted' => $deleted,
        ];
        $existing = $this->read_commit_index_record($this->action_index_dir, $path);
        if ($existing === null) {
            // Append before publishing the index. If index publication dies,
            // retry appends again and points candidate_end at the later record;
            // the enumeration pass ignores the orphaned earlier append.
            $record['candidate_end'] = $this->append_commit_record(
                $this->action_candidates_path,
                ['path_b64' => base64_encode($path)]
            );
            $this->write_commit_index_record($this->action_index_dir, $path, $record);
            return;
        }
        $priority = ['entry' => 1, 'tree' => 2, 'unit' => 3];
        $existing_kind = $existing['kind'] ?? null;
        if (!is_string($existing_kind) || !isset($priority[$existing_kind])) {
            throw new RuntimeException('Commit action index contains an invalid kind.', self::ERROR_INVALID_STATE);
        }
        if ($existing_kind === 'entry' && $kind === 'entry' && (bool) $existing['deleted'] !== $deleted) {
            throw new RuntimeException('Commit action both deletes and materializes ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
        }
        $changed = false;
        if ($priority[$kind] > $priority[$existing_kind]) {
            $existing['kind'] = $kind;
            $existing['deleted'] = $deleted;
            $changed = true;
        }
        if (!array_key_exists('candidate_end', $existing)) {
            // Recover an index published by the former index-first ordering, or
            // an interrupted publication from this same materialization pass.
            $existing['candidate_end'] = $this->append_commit_record(
                $this->action_candidates_path,
                ['path_b64' => base64_encode($path)]
            );
            $changed = true;
        } elseif (!is_int($existing['candidate_end']) || $existing['candidate_end'] <= 0) {
            throw new RuntimeException('Commit action index contains an invalid candidate cursor.', self::ERROR_INVALID_STATE);
        }
        if ($changed) {
            $this->write_commit_index_record($this->action_index_dir, $path, $existing);
        }
    }

    /** Returns whether a final action is subsumed by an ancestor tree/delete. */
    private function action_has_covering_ancestor(string $path): bool {
        foreach ($this->path_ancestors($path) as $ancestor) {
            $action = $this->read_commit_index_record($this->action_index_dir, $ancestor);
            if ($action === null) {
                continue;
            }
            if (($action['kind'] ?? null) === 'unit' || ($action['kind'] ?? null) === 'tree' || !empty($action['deleted'])) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] Strict ancestors from highest to direct parent. */
    private function path_ancestors(string $path): array {
        $segments = explode('/', $path);
        $ancestors = [];
        $segment_count = count($segments);
        for ($length = 1; $length < $segment_count; ++$length) {
            $ancestors[] = implode('/', array_slice($segments, 0, $length));
        }
        return $ancestors;
    }

    /** Writes one collision-checked path record into a flat hashed index. */
    private function write_commit_index_record(string $index_dir, string $path, array $value): void {
        $marker = $this->commit_index_path($index_dir, $path);
        $value['path_b64'] = base64_encode($path);
        $this->write_json($marker, $value);
    }

    /** Reads one flat hashed index record while checking its original path. */
    private function read_commit_index_record(string $index_dir, string $path): ?array {
        $value = $this->read_json($this->commit_index_path($index_dir, $path));
        if ($value === null) {
            return null;
        }
        $stored_path = isset($value['path_b64']) && is_string($value['path_b64']) ? base64_decode($value['path_b64'], true) : false;
        if ($stored_path !== $path) {
            throw new RuntimeException('Commit path index collision or corruption for ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
        }
        return $value;
    }

    /** Returns the bounded fixed-name marker for an arbitrary raw path. */
    private function commit_index_path(string $index_dir, string $path): string {
        $hash = hash('sha256', $path);
        $shard = $index_dir . '/' . substr($hash, 0, 2);
        if (!is_dir($shard) && !@mkdir($shard, 0700, true) && !is_dir($shard)) {
            throw new RuntimeException('Could not create a private commit index shard.', self::ERROR_RETRYABLE_IO);
        }
        return $shard . '/' . substr($hash, 2) . '.json';
    }

    /** @return array{path:string,next_offset:int}|null */
    private function read_staged_delete_record(int $offset): ?array {
        $record = $this->read_commit_record($this->deletes_path, $offset);
        if ($record === null) {
            return null;
        }
        $path = $this->decode_commit_path($record['value'], 'staged delete');
        $this->validate_path($path);
        return ['path' => $path, 'next_offset' => $record['next_offset']];
    }

    /** Decodes a required path_b64 field from a durable plan record. */
    private function decode_commit_path(array $record, string $description): string {
        $path = isset($record['path_b64']) && is_string($record['path_b64']) ? base64_decode($record['path_b64'], true) : false;
        if ($path === false) {
            throw new RuntimeException('Commit ' . $description . ' record has an invalid path.', self::ERROR_INVALID_STATE);
        }
        if ($path !== '') {
            $this->validate_path($path);
        }
        return $path;
    }

    /** @return array{value:array<string,mixed>,next_offset:int}|null */
    private function read_commit_record(string $path, int $offset): ?array {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return null;
        }
        if ($identity['type'] !== 'file' || $offset < 0 || $offset > (int) $identity['size']) {
            throw new RuntimeException('Commit JSONL cursor is outside a regular file: ' . $path . '.', self::ERROR_INVALID_STATE);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false || fseek($handle, $offset, SEEK_SET) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not resume commit JSONL file ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $line = fgets($handle, self::MAX_COMMIT_RECORD_BYTES + 2);
            if ($line === false) {
                return null;
            }
            if (strlen($line) > self::MAX_COMMIT_RECORD_BYTES || substr($line, -1) !== "\n") {
                throw new RuntimeException('Commit JSONL file contains an oversized or incomplete record: ' . $path . '.', self::ERROR_INVALID_STATE);
            }
            $value = json_decode(substr($line, 0, -1), true);
            if (!is_array($value)) {
                throw new RuntimeException('Commit JSONL file contains invalid JSON: ' . $path . '.', self::ERROR_INVALID_STATE);
            }
            $next_offset = ftell($handle);
            if (!is_int($next_offset)) {
                throw new RuntimeException('Could not checkpoint commit JSONL file ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            return ['value' => $value, 'next_offset' => $next_offset];
        } finally {
            fclose($handle);
        }
    }

    /** Appends and flushes one bounded JSONL record, returning the durable size. */
    private function append_commit_record(string $path, array $value): int {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) + 1 > self::MAX_COMMIT_RECORD_BYTES) {
            throw new RuntimeException('Could not encode bounded commit JSONL record for ' . $path . '.', self::ERROR_INVALID_STATE);
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not append commit JSONL file ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $this->write_all($handle, $encoded . "\n", 'commit JSONL file ' . $path);
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush commit JSONL file ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            $stat = fstat($handle);
            if (!is_array($stat) || !isset($stat['size'])) {
                throw new RuntimeException('Could not checkpoint commit JSONL append for ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            return (int) $stat['size'];
        } finally {
            fclose($handle);
        }
    }

    /** Truncates bytes written after the last durable checkpoint. */
    private function truncate_file_to(string $path, int $size): void {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open commit plan for recovery truncation: ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $stat = fstat($handle);
            if (!is_array($stat) || $size < 0 || $size > (int) $stat['size'] || !ftruncate($handle, $size) || !fflush($handle)) {
                throw new RuntimeException('Could not truncate commit plan to durable byte ' . $size . ': ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Indicates whether replacing a path would implicitly replace a protected child.
     *
     * @param string $path Target-relative potential ancestor.
     * @return bool True when a protected path is strictly below it.
     */
    private function is_protected_ancestor(string $path): bool {
        foreach ($this->protected_paths as $protected_path) {
            if (strpos($protected_path, $path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Captures one action and its live fingerprint before resumable copying.
     *
     * @param array<string,mixed> $action Basic disk-backed action record.
     * @param int $action_next_offset Durable byte after that action record.
     * @param int $action_index Stable ordinal used for its private mode plan.
     * @return array<string,mixed> Durable cursor for one prepared candidate.
     */
    private function start_preparing_action(array $action, int $action_next_offset, int $action_index): array {
        $path = $this->decode_commit_path($action, 'action');
        $kind = $action['kind'] ?? null;
        $deleted = $action['deleted'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['entry', 'tree', 'unit'], true) || !is_bool($deleted)) {
            throw new RuntimeException('Commit action has an invalid kind or deletion state.', self::ERROR_INVALID_STATE);
        }
        if ($kind !== 'entry' && $deleted) {
            throw new RuntimeException('Only an entry action may be a direct deletion.', self::ERROR_INVALID_STATE);
        }
        $this->assert_target_path_same_filesystem($path);
        $live_path = $this->target_path($path);
        $staged_path = $this->private_path($this->files_dir, $path);
        $live_identity = $this->path_identity($live_path);
        $staged_identity = $this->path_identity($staged_path);
        $mode_plan_dir = $this->prepare_modes_dir . '/' . $action_index;
        if (!@mkdir($mode_plan_dir, 0700) && !is_dir($mode_plan_dir)) {
            throw new RuntimeException('Could not create a private directory-mode plan.', self::ERROR_RETRYABLE_IO);
        }

        $sources = [];
        if ($kind === 'entry' && !$deleted) {
            if ($staged_identity === null) {
                throw new RuntimeException('Staged entry disappeared before commit: ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            $sources[] = 'staged';
        } elseif ($kind === 'tree' || $kind === 'unit') {
            if ($live_identity !== null && $live_identity['type'] === 'directory') {
                $sources[] = 'live';
            }
            if ($staged_identity !== null) {
                $sources[] = 'staged';
            }
        }

        $action['expected_live'] = $live_identity;
        $action['expected_live_tree'] = $this->tree_fingerprint($live_path);
        return [
            'action' => $action,
            'action_next_offset' => $action_next_offset,
            'stage' => 'reset',
            'sources' => $sources,
            'source_index' => 0,
            'source_started' => false,
            'queue_offset' => 0,
            'queue_bytes' => 0,
            'directory' => null,
            'file' => null,
            'mode_plan_index' => $action_index,
            'mode_bytes' => [],
            'maximum_mode_depth' => 0,
            'mode_depth' => 0,
            'mode_offset' => 0,
            'root_directory_mode' => null,
        ];
    }

    /**
     * Advances one bounded preparation operation for the current action.
     *
     * Directory children and deferred modes live in disk-backed plans, while
     * regular files advance by at most BODY_PIECE_BYTES. Reopening a session
     * therefore resumes a large candidate tree, read-only mode finalization,
     * and an individual large file from durable cursors.
     *
     * @param array<string,mixed> $prepare Mutable current-action cursor.
     * @return bool True only after the candidate identity has been verified.
     */
    private function advance_preparing_action(array &$prepare): bool {
        $action = $prepare['action'];
        $root_path = $this->decode_commit_path($action, 'preparing action');
        $prepared_root = $this->private_path($this->prepared_dir, $root_path);

        if ($prepare['stage'] === 'reset') {
            $this->ensure_private_parent($prepared_root);
            $this->remove_entry($prepared_root);
            $this->truncate_file_to($this->prepare_queue_path, 0);
            $prepare['queue_offset'] = 0;
            $prepare['queue_bytes'] = 0;
            $prepare['stage'] = 'copying';
            return false;
        }

        if ($prepare['stage'] === 'copying') {
            $sources = $prepare['sources'];
            if (!is_array($sources) || !isset($prepare['source_index']) || !is_int($prepare['source_index'])) {
                throw new RuntimeException('Commit preparation source cursor is invalid.', self::ERROR_INVALID_STATE);
            }
            if ($prepare['source_index'] >= count($sources)) {
                $prepare['stage'] = 'modes';
                $prepare['mode_depth'] = $prepare['maximum_mode_depth'];
                $prepare['mode_offset'] = 0;
                return false;
            }
            $source_kind = $sources[$prepare['source_index']] ?? null;
            if ($source_kind !== 'live' && $source_kind !== 'staged') {
                throw new RuntimeException('Commit preparation source kind is invalid.', self::ERROR_INVALID_STATE);
            }

            if (!$prepare['source_started']) {
                $this->truncate_file_to($this->prepare_queue_path, 0);
                $prepare['queue_offset'] = 0;
                $prepare['queue_bytes'] = $this->append_commit_record(
                    $this->prepare_queue_path,
                    ['path_b64' => base64_encode($root_path)]
                );
                $prepare['directory'] = null;
                $prepare['file'] = null;
                $prepare['source_started'] = true;
                return false;
            }

            $this->truncate_file_to($this->prepare_queue_path, (int) $prepare['queue_bytes']);
            if (is_array($prepare['file'])) {
                $this->advance_preparing_file($prepare, $source_kind, $root_path);
                return false;
            }
            if (is_array($prepare['directory'])) {
                $this->advance_preparing_directory($prepare, $source_kind, $root_path);
                return false;
            }

            $record = $this->read_commit_record($this->prepare_queue_path, (int) $prepare['queue_offset']);
            if ($record === null) {
                ++$prepare['source_index'];
                $prepare['source_started'] = false;
                return false;
            }
            $path = $this->decode_commit_path($record['value'], 'preparation queue');
            if ($path !== $root_path && strpos($path, $root_path . '/') !== 0) {
                throw new RuntimeException('Commit preparation queue escaped its action root.', self::ERROR_INVALID_STATE);
            }
            $staged_record = $this->read_commit_index_record($this->staged_index_dir, $path);
            $staged_replaces_live = $staged_record !== null
                && ($staged_record['type'] ?? null) !== 'directory-mode';
            if (
                $source_kind === 'live'
                && ($this->read_commit_index_record($this->delete_index_dir, $path) !== null || $staged_replaces_live)
            ) {
                $prepare['queue_offset'] = $record['next_offset'];
                return false;
            }

            $source = $source_kind === 'live' ? $this->target_path($path) : $this->private_path($this->files_dir, $path);
            $destination = $this->private_path($this->prepared_dir, $path);
            $identity = $this->path_identity($source);
            if ($identity === null) {
                $this->throw_preparation_source_changed($source_kind, $path);
            }
            $this->require_preparation_source_device($identity, $source);
            $this->ensure_private_parent($destination);

            if ($identity['type'] === 'directory') {
                $destination_identity = $this->path_identity($destination);
                if ($destination_identity !== null && $destination_identity['type'] !== 'directory') {
                    if ($source_kind !== 'staged') {
                        throw new RuntimeException('Prepared destination changed type while copying ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                    }
                    $this->remove_entry($destination);
                    $destination_identity = null;
                }
                if ($destination_identity === null && !@mkdir($destination, 0700) && !is_dir($destination)) {
                    throw new RuntimeException('Could not create prepared directory ' . $destination . '.', self::ERROR_RETRYABLE_IO);
                }
                $directory_mode = (int) $identity['permissions'];
                $explicit_staged_directory = false;
                if ($source_kind === 'staged' && $staged_record !== null) {
                    $staged_type = $staged_record['type'] ?? null;
                    if ($staged_type !== 'directory' && $staged_type !== 'directory-mode') {
                        throw new RuntimeException('Staged directory index has an invalid type for ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
                    }
                    $explicit_staged_directory = true;
                    if ($staged_type === 'directory-mode') {
                        $requested_mode = $staged_record['mode'] ?? null;
                        if (!is_int($requested_mode) || $requested_mode < 0 || $requested_mode > 07777) {
                            throw new RuntimeException('Staged directory index has an invalid mode for ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
                        }
                        $directory_mode = $requested_mode;
                    }
                }
                if ($source_kind === 'live' || $explicit_staged_directory) {
                    $this->record_preparing_directory_mode($prepare, $path, $directory_mode);
                }
                $prepare['directory'] = [
                    'path_b64' => base64_encode($path),
                    'last_entry_b64' => null,
                    'queue_next_offset' => $record['next_offset'],
                    'identity' => $identity,
                ];
                return false;
            }

            if ($identity['type'] === 'file') {
                $destination_identity = $this->path_identity($destination);
                if ($destination_identity !== null && $destination_identity['type'] !== 'file') {
                    throw new RuntimeException('Prepared file destination has an unexpected type at ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                $prepare['file'] = [
                    'path_b64' => base64_encode($path),
                    'queue_next_offset' => $record['next_offset'],
                    'identity' => $identity,
                ];
                return false;
            }

            if ($identity['type'] !== 'symlink') {
                throw new RuntimeException('Could not prepare unsupported filesystem entry ' . $source . '.', self::ERROR_RETRYABLE_IO);
            }
            $target = @readlink($source);
            if (!is_string($target)) {
                $this->throw_preparation_source_changed($source_kind, $path);
            }
            $destination_identity = $this->path_identity($destination);
            if ($destination_identity === null) {
                if (!@symlink($target, $destination)) {
                    throw new RuntimeException('Could not recreate prepared symlink ' . $source . '.', self::ERROR_RETRYABLE_IO);
                }
            } elseif ($destination_identity['type'] !== 'symlink' || @readlink($destination) !== $target) {
                throw new RuntimeException('Prepared symlink destination disagrees with its durable cursor at ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($this->path_identity($source) !== $identity) {
                $this->throw_preparation_source_changed($source_kind, $path);
            }
            $prepare['queue_offset'] = $record['next_offset'];
            return false;
        }

        if ($prepare['stage'] === 'modes') {
            $this->advance_preparing_directory_modes($prepare, $root_path);
            return false;
        }

        if ($prepare['stage'] !== 'verifying') {
            throw new RuntimeException('Commit preparation has an invalid stage.', self::ERROR_INVALID_STATE);
        }
        $live_path = $this->target_path($root_path);
        if (
            $this->path_identity($live_path) !== ($action['expected_live'] ?? null)
            || $this->tree_fingerprint($live_path) !== ($action['expected_live_tree'] ?? null)
        ) {
            throw new RuntimeException(
                'Live entry changed while preparing ' . $this->describe_path($root_path)
                . '; refusing to switch a candidate built from an external writer.',
                self::ERROR_LIVE_TREE_CHANGED
            );
        }
        $action['prepared'] = $this->path_identity($prepared_root);
        $root_directory_mode = $prepare['root_directory_mode'] ?? null;
        if ($root_directory_mode !== null) {
            if (!is_int($root_directory_mode) || $root_directory_mode < 0 || $root_directory_mode > 07777) {
                throw new RuntimeException('Commit preparation root directory mode is invalid.', self::ERROR_INVALID_STATE);
            }
            if (!is_array($action['prepared']) || ($action['prepared']['type'] ?? null) !== 'directory') {
                throw new RuntimeException('Commit preparation recorded a directory mode for a non-directory root.', self::ERROR_INVALID_STATE);
            }
            $action['final_directory_mode'] = $root_directory_mode;
        }
        $prepare['action'] = $action;
        $prepare['stage'] = 'complete';
        return true;
    }

    /** Advances one child of a reopened directory in deterministic byte order. */
    private function advance_preparing_directory(array &$prepare, string $source_kind, string $root_path): void {
        $cursor = $prepare['directory'];
        $path = $this->decode_commit_path($cursor, 'preparation directory cursor');
        if ($path !== $root_path && strpos($path, $root_path . '/') !== 0) {
            throw new RuntimeException('Commit preparation directory escaped its action root.', self::ERROR_INVALID_STATE);
        }
        $source = $source_kind === 'live' ? $this->target_path($path) : $this->private_path($this->files_dir, $path);
        $identity = $cursor['identity'] ?? null;
        if (!is_array($identity) || $this->path_identity($source) !== $identity) {
            $this->throw_preparation_source_changed($source_kind, $path);
        }
        $last_entry_b64 = $cursor['last_entry_b64'] ?? null;
        $last_entry = $last_entry_b64 === null
            ? null
            : (is_string($last_entry_b64) ? base64_decode($last_entry_b64, true) : false);
        if ($last_entry === false || ($last_entry !== null && ($last_entry === '' || strpos($last_entry, '/') !== false || strpos($last_entry, "\0") !== false))) {
            throw new RuntimeException('Commit preparation directory entry cursor is invalid.', self::ERROR_INVALID_STATE);
        }
        $handle = @opendir($source);
        if ($handle === false) {
            $this->throw_preparation_source_changed($source_kind, $path);
        }
        $entry = false;
        try {
            while (($candidate = readdir($handle)) !== false) {
                if ($candidate === '.' || $candidate === '..') {
                    continue;
                }
                if ($last_entry !== null && strcmp($candidate, $last_entry) <= 0) {
                    continue;
                }
                if ($entry === false || strcmp($candidate, $entry) < 0) {
                    $entry = $candidate;
                }
            }
        } finally {
            closedir($handle);
        }
        if ($entry === false) {
            if ($this->path_identity($source) !== $identity) {
                $this->throw_preparation_source_changed($source_kind, $path);
            }
            $prepare['queue_offset'] = (int) $cursor['queue_next_offset'];
            $prepare['directory'] = null;
            return;
        }
        $child_path = $path . '/' . $entry;
        $this->validate_path($child_path);
        $this->truncate_file_to($this->prepare_queue_path, (int) $prepare['queue_bytes']);
        $prepare['queue_bytes'] = $this->append_commit_record(
            $this->prepare_queue_path,
            ['path_b64' => base64_encode($child_path)]
        );
        $cursor['last_entry_b64'] = base64_encode($entry);
        $prepare['directory'] = $cursor;
    }

    /** Records one exact directory mode without making the candidate read-only yet. */
    private function record_preparing_directory_mode(array &$prepare, string $path, int $mode): void {
        if ($mode < 0 || $mode > 07777) {
            throw new RuntimeException('Commit preparation directory mode is invalid.', self::ERROR_INVALID_STATE);
        }
        $mode_bytes = $prepare['mode_bytes'] ?? null;
        if (!is_array($mode_bytes)) {
            throw new RuntimeException('Commit preparation directory-mode byte cursors are invalid.', self::ERROR_INVALID_STATE);
        }
        $depth = substr_count($path, '/') + 1;
        $plan_path = $this->preparing_mode_plan_path($prepare, $depth);
        $durable_bytes = $mode_bytes[$depth] ?? 0;
        if (!is_int($durable_bytes) || $durable_bytes < 0) {
            throw new RuntimeException('Commit preparation directory-mode byte cursor is invalid.', self::ERROR_INVALID_STATE);
        }
        if (!array_key_exists($depth, $mode_bytes)) {
            $this->write_atomic_file($plan_path, '', 0600);
        }
        $this->truncate_file_to($plan_path, $durable_bytes);
        $prepare['mode_bytes'][$depth] = $this->append_commit_record($plan_path, [
            'path_b64' => base64_encode($path),
            'mode' => $mode,
        ]);
        $prepare['maximum_mode_depth'] = max((int) ($prepare['maximum_mode_depth'] ?? 0), $depth);
    }

    /** Applies one deferred directory mode, deepest paths first. */
    private function advance_preparing_directory_modes(array &$prepare, string $root_path): void {
        $depth = $prepare['mode_depth'] ?? null;
        $offset = $prepare['mode_offset'] ?? null;
        $mode_bytes = $prepare['mode_bytes'] ?? null;
        if (!is_int($depth) || $depth < 0 || !is_int($offset) || $offset < 0 || !is_array($mode_bytes)) {
            throw new RuntimeException('Commit preparation directory-mode cursor is invalid.', self::ERROR_INVALID_STATE);
        }
        if ($depth === 0) {
            $prepare['stage'] = 'verifying';
            return;
        }
        $durable_bytes = $mode_bytes[$depth] ?? 0;
        if (!is_int($durable_bytes) || $durable_bytes < 0 || $offset > $durable_bytes) {
            throw new RuntimeException('Commit preparation directory-mode byte range is invalid.', self::ERROR_INVALID_STATE);
        }
        if ($durable_bytes === 0) {
            $prepare['mode_depth'] = $depth - 1;
            $prepare['mode_offset'] = 0;
            return;
        }
        $plan_path = $this->preparing_mode_plan_path($prepare, $depth);
        $this->truncate_file_to($plan_path, $durable_bytes);
        $record = $this->read_commit_record($plan_path, $offset);
        if ($record === null) {
            $prepare['mode_depth'] = $depth - 1;
            $prepare['mode_offset'] = 0;
            return;
        }
        $path = $this->decode_commit_path($record['value'], 'preparation directory-mode plan');
        if ($path !== $root_path && strpos($path, $root_path . '/') !== 0) {
            throw new RuntimeException('Commit preparation directory mode escaped its action root.', self::ERROR_INVALID_STATE);
        }
        $mode = $record['value']['mode'] ?? null;
        if (!is_int($mode) || $mode < 0 || $mode > 07777) {
            throw new RuntimeException('Commit preparation directory-mode plan has an invalid mode.', self::ERROR_INVALID_STATE);
        }
        $destination = $this->private_path($this->prepared_dir, $path);
        $identity = $this->path_identity($destination);
        if ($identity === null || $identity['type'] !== 'directory') {
            throw new RuntimeException('Prepared directory disappeared before applying its mode at ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        if ($path === $root_path) {
            // Some platforms refuse to rename a directory without owner write
            // permission. Keep the private action root writable through both
            // renames; the transition applies its final mode after install.
            $prepare['root_directory_mode'] = $mode;
        } elseif (!@chmod($destination, $mode)) {
            throw new RuntimeException('Could not preserve prepared directory mode for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $prepare['mode_offset'] = $record['next_offset'];
    }

    /** Returns one action-and-depth-specific deferred directory-mode plan. */
    private function preparing_mode_plan_path(array $prepare, int $depth): string {
        $index = $prepare['mode_plan_index'] ?? null;
        if (!is_int($index) || $index < 0 || $depth <= 0 || $depth > self::MAX_PATH_BYTES) {
            throw new RuntimeException('Commit preparation directory-mode plan identity is invalid.', self::ERROR_INVALID_STATE);
        }
        return $this->prepare_modes_dir . '/' . $index . '/' . $depth . '.jsonl';
    }

    /** Copies at most one bounded piece of the current regular file. */
    private function advance_preparing_file(array &$prepare, string $source_kind, string $root_path): void {
        $cursor = $prepare['file'];
        $path = $this->decode_commit_path($cursor, 'preparation file cursor');
        if ($path !== $root_path && strpos($path, $root_path . '/') !== 0) {
            throw new RuntimeException('Commit preparation file escaped its action root.', self::ERROR_INVALID_STATE);
        }
        $source = $source_kind === 'live' ? $this->target_path($path) : $this->private_path($this->files_dir, $path);
        $destination = $this->private_path($this->prepared_dir, $path);
        $identity = $cursor['identity'] ?? null;
        if (!is_array($identity) || ($identity['type'] ?? null) !== 'file' || $this->path_identity($source) !== $identity) {
            $this->throw_preparation_source_changed($source_kind, $path);
        }
        $destination_identity = $this->path_identity($destination);
        if ($destination_identity !== null && $destination_identity['type'] !== 'file') {
            throw new RuntimeException('Prepared file destination changed type at ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $accepted_bytes = $destination_identity === null ? 0 : (int) $destination_identity['size'];
        if ($accepted_bytes > (int) $identity['size']) {
            throw new RuntimeException('Prepared file exceeds its source size at ' . $this->describe_path($path) . '.', self::ERROR_INVALID_STATE);
        }
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'c+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not resume prepared file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($input, $accepted_bytes, SEEK_SET) !== 0 || fseek($output, $accepted_bytes, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek prepared file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            $remaining = (int) $identity['size'] - $accepted_bytes;
            if ($remaining > 0) {
                $piece = fread($input, min(self::BODY_PIECE_BYTES, $remaining));
                if (!is_string($piece) || $piece === '') {
                    throw new RuntimeException('Could not read source file while preparing ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                $this->write_all($output, $piece, 'prepared file ' . $this->describe_path($path));
                $accepted_bytes += strlen($piece);
            }
            if (!fflush($output)) {
                throw new RuntimeException('Could not flush prepared file ' . $destination . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($this->path_identity($source) !== $identity) {
            $this->throw_preparation_source_changed($source_kind, $path);
        }
        if ($accepted_bytes === (int) $identity['size']) {
            if (!@chmod($destination, (int) $identity['permissions'])) {
                throw new RuntimeException('Could not preserve prepared file mode for ' . $source . '.', self::ERROR_RETRYABLE_IO);
            }
            $prepare['queue_offset'] = (int) $cursor['queue_next_offset'];
            $prepare['file'] = null;
        }
    }

    /** Requires a copied source to remain on the target filesystem. */
    private function require_preparation_source_device(array $identity, string $source): void {
        $target_identity = $this->path_identity($this->target_root);
        if ($target_identity === null || $target_identity['type'] !== 'directory') {
            throw new RuntimeException('The apply target root is not a directory.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $identity['dev'] !== (int) $target_identity['dev']) {
            throw new RuntimeException(
                'Refusing to prepare ' . $source . ' on device ' . $identity['dev']
                . ' below a target rooted on device ' . $target_identity['dev'] . '.',
                self::ERROR_RETRYABLE_IO
            );
        }
    }

    /** Throws the source-appropriate error when a resumable copy loses input. */
    private function throw_preparation_source_changed(string $source_kind, string $path): void {
        if ($source_kind === 'live') {
            throw new RuntimeException(
                'Live entry changed while preparing ' . $this->describe_path($path) . '.',
                self::ERROR_LIVE_TREE_CHANGED
            );
        }
        throw new RuntimeException(
            'Staged entry changed while preparing ' . $this->describe_path($path) . '.',
            self::ERROR_RETRYABLE_IO
        );
    }

    /**
     * Advances one crash-recoverable two-rename live transition.
     *
     * A transition checkpoint is persisted before any live chmod or rename.
     * Reconciliation may perform both renames now or prove that a prior process
     * already did. Read-only roots are made owner-writable under maintenance and
     * the replacement's exact final mode is restored after installation. The
     * checkpoint is cleared only after the intended live outcome is proven.
     *
     * @param array<string,mixed> $state
     * @param int $index Current action index.
     * @return bool Whether the prepared identity differs from the prior live one.
     */
    private function switch_action(array &$state, array $action, int $index): bool {
        $path = base64_decode((string) ($action['path_b64'] ?? ''), true);
        if ($path === false || $path === '') {
            throw new RuntimeException('Commit checkpoint has an invalid switch path.', self::ERROR_RETRYABLE_IO);
        }
        $this->validate_path($path);
        $live_path = $this->target_path($path);
        $prepared_path = $this->private_path($this->prepared_dir, $path);
        $backup_path = $this->private_path($this->backups_dir, $path);

        if (!is_array($state['transition'])) {
            $this->require_prepared_live_tree($state, $action, $index);
            $final_directory_mode = $action['final_directory_mode'] ?? null;
            if ($final_directory_mode !== null && (!is_int($final_directory_mode) || $final_directory_mode < 0 || $final_directory_mode > 07777)) {
                throw new RuntimeException('Prepared commit action has an invalid final directory mode.', self::ERROR_INVALID_STATE);
            }
            $state['transition'] = [
                'index' => $index,
                'stage' => 'prepared',
                'path_b64' => base64_encode($path),
                'expected_live' => $action['expected_live'] ?? null,
                'expected_live_tree' => $action['expected_live_tree'] ?? null,
                'prepared' => $action['prepared'] ?? null,
                'final_directory_mode' => $final_directory_mode,
                'backup_b64' => base64_encode($path),
            ];
            $this->write_json($this->commit_path, $state);
        }
        $transition = $state['transition'];
        if (!is_array($transition) || (int) ($transition['index'] ?? -1) !== $index) {
            throw new RuntimeException('Commit transition checkpoint does not match its current action.', self::ERROR_RETRYABLE_IO);
        }
        $this->reconcile_transition($live_path, $prepared_path, $backup_path, $state);
        $changed = ($transition['expected_live'] ?? null) !== ($transition['prepared'] ?? null);
        $state['transition'] = null;
        return $changed;
    }

    /**
     * Rejects live drift after preparation unless a durable transition already
     * proves that this session intentionally moved the entry.
     *
     * @param array<string,mixed> $state
     * @param int $index Prepared action whose live tree is about to switch.
     */
    private function require_prepared_live_tree(array $state, array $action, int $index): void {
        // A durable transition already describes a live rename that may have
        // happened. Its recovery must use the recorded physical identities,
        // rather than reject the intentionally absent live path here.
        if (is_array($state['transition'] ?? null)) {
            return;
        }
        if (!array_key_exists('expected_live_tree', $action)) {
            throw new RuntimeException('Prepared commit action ' . $index . ' has no live-tree fingerprint.', self::ERROR_INVALID_STATE);
        }
        $expected_live_tree = $action['expected_live_tree'];
        if ($expected_live_tree !== null && !is_string($expected_live_tree)) {
            throw new RuntimeException('Prepared commit action ' . $index . ' has an invalid live-tree fingerprint.', self::ERROR_INVALID_STATE);
        }
        $path = base64_decode((string) ($action['path_b64'] ?? ''), true);
        if ($path === false || $path === '') {
            throw new RuntimeException('Prepared commit action ' . $index . ' has an invalid path.', self::ERROR_INVALID_STATE);
        }
        $this->validate_path($path);
        if ($this->tree_fingerprint($this->target_path($path)) !== $expected_live_tree) {
            throw new RuntimeException(
                'Live entry changed after preparation and before switching ' . $this->describe_path($path)
                . '; refusing to overwrite an external writer.',
                self::ERROR_LIVE_TREE_CHANGED
            );
        }
    }

    /**
     * Reconciles exactly one two-rename transition after a process death.
     *
     * The checkpoint is written before the first rename.  We only continue
     * when the live, prepared, and backup identities prove which of those
     * renames completed; an outside writer is never guessed through.
     * @param string $live_path Absolute live entry path.
     * @param string $prepared_path Absolute private replacement path.
     * @param string $backup_path Absolute private prior-live path.
     * @param array<string,mixed> $state Durable commit checkpoint to advance.
     */
    private function reconcile_transition(string $live_path, string $prepared_path, string $backup_path, array &$state): void {
        while (true) {
            $transition = $state['transition'] ?? null;
            if (!is_array($transition)) {
                throw new RuntimeException('Commit transition checkpoint is missing.', self::ERROR_RETRYABLE_IO);
            }
            $expected_live = $transition['expected_live'] ?? null;
            $expected_live_tree = $transition['expected_live_tree'] ?? null;
            $prepared = $transition['prepared'] ?? null;
            $backup_expected = $transition['backup'] ?? null;
            $installed = $transition['installed'] ?? null;
            $writable_live = $transition['writable_live'] ?? null;
            $mode_applied = $transition['mode_applied'] ?? null;
            foreach ([$expected_live, $prepared, $backup_expected, $installed, $writable_live, $mode_applied] as $identity) {
                if ($identity !== null && !is_array($identity)) {
                    throw new RuntimeException('Commit transition contains an invalid filesystem identity.', self::ERROR_RETRYABLE_IO);
                }
            }
            $final_directory_mode = $transition['final_directory_mode'] ?? null;
            if ($final_directory_mode !== null && (!is_int($final_directory_mode) || $final_directory_mode < 0 || $final_directory_mode > 07777)) {
                throw new RuntimeException('Commit transition contains an invalid final directory mode.', self::ERROR_INVALID_STATE);
            }
            $stage = $transition['stage'] ?? 'prepared';
            if (!is_string($stage) || !in_array($stage, ['prepared', 'live_writable', 'backup', 'installed', 'mode_applied'], true)) {
                throw new RuntimeException('Commit transition contains an invalid stage.', self::ERROR_RETRYABLE_IO);
            }
            if ($expected_live_tree !== null && !is_string($expected_live_tree)) {
                throw new RuntimeException('Commit transition contains an invalid live-tree fingerprint.', self::ERROR_INVALID_STATE);
            }

            $live = $this->path_identity($live_path);
            $candidate = $this->path_identity($prepared_path);
            $backup = $this->path_identity($backup_path);

            if ($expected_live === null && $prepared === null) {
                if ($live === null && $candidate === null && $backup === null) {
                    return;
                }
                throw new RuntimeException('Delete transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            $live_requires_owner_access = is_array($expected_live)
                && ($expected_live['type'] ?? null) === 'directory'
                && (((int) ($expected_live['permissions'] ?? 0)) & 0700) !== 0700;
            if ($stage === 'prepared' && $live_requires_owner_access) {
                $writable_mode = ((int) $expected_live['permissions']) | 0700;
                if ($live === $expected_live && $backup === null) {
                    if (!@chmod($live_path, $writable_mode)) {
                        throw new RuntimeException('Could not make the live directory movable under maintenance.', self::ERROR_RETRYABLE_IO);
                    }
                    $this->record_live_writable_transition($state, $this->path_identity($live_path));
                    continue;
                }
                // A process may die after chmod() but before its checkpoint.
                // The same inode and exact temporary mode identify that narrow
                // transition window without following or guessing another path.
                if (
                    $live !== null
                    && $backup === null
                    && $this->same_physical_entry($live, $expected_live)
                    && ($live['permissions'] ?? null) === $writable_mode
                ) {
                    $this->record_live_writable_transition($state, $live);
                    continue;
                }
                throw new RuntimeException('Writable-live transition has unexpected live or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            if (
                $stage === 'prepared'
                && $expected_live_tree !== null
                && $live !== null
                && $this->tree_fingerprint($live_path) !== $expected_live_tree
            ) {
                throw new RuntimeException(
                    'Live entry changed after preparation and before switching ' . $this->describe_path($live_path)
                    . '; refusing to overwrite an external writer.',
                    self::ERROR_LIVE_TREE_CHANGED
                );
            }

            if ($stage === 'installed' || $stage === 'mode_applied') {
                $expected_backup = $expected_live === null ? null : $backup_expected;
                if ($candidate !== null || $backup !== $expected_backup || $live === null) {
                    throw new RuntimeException('Installed transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
                }
                if ($stage === 'mode_applied') {
                    if ($live === $mode_applied) {
                        return;
                    }
                    throw new RuntimeException('Installed directory mode changed after its checkpoint.', self::ERROR_LIVE_TREE_CHANGED);
                }
                if ($live === $installed) {
                    if ($final_directory_mode === null || ($live['permissions'] ?? null) === $final_directory_mode) {
                        return;
                    }
                    if (($live['type'] ?? null) !== 'directory' || !@chmod($live_path, $final_directory_mode)) {
                        throw new RuntimeException('Could not apply the installed directory mode.', self::ERROR_RETRYABLE_IO);
                    }
                    $this->record_mode_applied_transition($state, $this->path_identity($live_path));
                    continue;
                }
                if (
                    $final_directory_mode !== null
                    && $this->same_physical_entry($live, $installed)
                    && ($live['permissions'] ?? null) === $final_directory_mode
                ) {
                    $this->record_mode_applied_transition($state, $live);
                    continue;
                }
                throw new RuntimeException('Installed transition changed before its final mode checkpoint.', self::ERROR_LIVE_TREE_CHANGED);
            }

            $live_before_rename = $stage === 'live_writable' ? $writable_live : $expected_live;
            $can_move_live = ($stage === 'prepared' && $live === $expected_live)
                || ($stage === 'live_writable' && $live === $writable_live);
            $before_backup = $stage === 'prepared' || $stage === 'live_writable';

            if ($expected_live === null) {
                if ($stage === 'prepared' && $live === null && $candidate === $prepared && $backup === null) {
                    $this->ensure_target_parent($live_path);
                    $this->rename_same_filesystem($prepared_path, $live_path, 'install prepared entry');
                    $this->record_transition_stage($state, 'installed', null, $this->path_identity($live_path));
                    continue;
                }
                // A process can die after the rename but before its durable
                // post-rename identity is recorded. inode/dev prove that the
                // candidate became live; capture its current identity before
                // completing the transition on the next loop.
                if ($stage === 'prepared' && $this->same_physical_entry($live, $prepared) && $candidate === null && $backup === null) {
                    $this->record_transition_stage($state, 'installed', null, $live);
                    continue;
                }
                throw new RuntimeException('Install transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            if ($prepared === null) {
                if ($can_move_live && $backup === null && $candidate === null) {
                    $this->ensure_target_parent($live_path);
                    $this->ensure_private_parent($backup_path);
                    $this->rename_same_filesystem($live_path, $backup_path, 'move deleted entry into backup');
                    $this->record_transition_stage($state, 'backup', $this->path_identity($backup_path), null);
                    continue;
                }
                if ($before_backup && $live === null && $this->same_physical_entry($backup, $live_before_rename) && $candidate === null) {
                    $this->record_transition_stage($state, 'backup', $backup, null);
                    continue;
                }
                if ($stage === 'backup' && $live === null && $backup === $backup_expected && $candidate === null) {
                    return;
                }
                throw new RuntimeException('Delete transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            if ($can_move_live && $backup === null && $candidate === $prepared) {
                $this->ensure_target_parent($live_path);
                $this->ensure_private_parent($backup_path);
                $this->rename_same_filesystem($live_path, $backup_path, 'move replaced entry into backup');
                $this->record_transition_stage($state, 'backup', $this->path_identity($backup_path), null);
                continue;
            }
            if ($before_backup && $live === null && $this->same_physical_entry($backup, $live_before_rename) && $candidate === $prepared) {
                $this->record_transition_stage($state, 'backup', $backup, null);
                continue;
            }
            if ($stage === 'backup' && $live === null && $backup === $backup_expected && $candidate === $prepared) {
                $this->ensure_target_parent($live_path);
                $this->rename_same_filesystem($prepared_path, $live_path, 'install replacement entry');
                $this->record_transition_stage($state, 'installed', $backup_expected, $this->path_identity($live_path));
                continue;
            }
            if ($stage === 'backup' && $this->same_physical_entry($live, $prepared) && $backup === $backup_expected && $candidate === null) {
                $this->record_transition_stage($state, 'installed', $backup_expected, $live);
                continue;
            }
            throw new RuntimeException('Replacement transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
        }
    }

    /**
     * Checkpoints the identities observed after one rename and before the next.
     *
     * @param array<string,mixed> $state
     * @param string $stage `backup` or `installed` durable transition stage.
     * @param array<string,mixed>|null $backup
     * @param array<string,mixed>|null $installed
     */
    private function record_transition_stage(array &$state, string $stage, ?array $backup, ?array $installed): void {
        if (!is_array($state['transition'] ?? null)) {
            throw new RuntimeException('Commit transition checkpoint is missing.', self::ERROR_RETRYABLE_IO);
        }
        $state['transition']['stage'] = $stage;
        if ($backup !== null) {
            $state['transition']['backup'] = $backup;
        }
        if ($installed !== null) {
            $state['transition']['installed'] = $installed;
        }
        $this->write_json($this->commit_path, $state);
    }

    /** Checkpoints the owner-writable live identity required for portable rename. */
    private function record_live_writable_transition(array &$state, ?array $identity): void {
        if (!is_array($state['transition'] ?? null) || $identity === null || ($identity['type'] ?? null) !== 'directory') {
            throw new RuntimeException('Could not checkpoint the writable live directory identity.', self::ERROR_RETRYABLE_IO);
        }
        $state['transition']['stage'] = 'live_writable';
        $state['transition']['writable_live'] = $identity;
        $this->write_json($this->commit_path, $state);
    }

    /** Checkpoints the installed directory after applying its exact final mode. */
    private function record_mode_applied_transition(array &$state, ?array $identity): void {
        if (!is_array($state['transition'] ?? null) || $identity === null || ($identity['type'] ?? null) !== 'directory') {
            throw new RuntimeException('Could not checkpoint the installed directory mode.', self::ERROR_RETRYABLE_IO);
        }
        $state['transition']['stage'] = 'mode_applied';
        $state['transition']['mode_applied'] = $identity;
        $this->write_json($this->commit_path, $state);
    }

    /**
     * Compares the stable identity of a path across a rename.
     *
     * A rename can change ctime, so the full durable identity used for
     * ordinary outside-writer detection cannot identify the same entry in
     * the small window between rename() and the next checkpoint write.
     *
     * @param array<string,mixed>|null $actual Identity observed at the new path.
     * @param array<string,mixed>|null $expected Identity captured before rename.
     * @return bool True when type, device, inode, and symlink target prove sameness.
     */
    private function same_physical_entry(?array $actual, ?array $expected): bool {
        if ($actual === null || $expected === null) {
            return false;
        }
        foreach (['type', 'dev', 'ino'] as $key) {
            if (($actual[$key] ?? null) !== ($expected[$key] ?? null)) {
                return false;
            }
        }
        return ($actual['type'] ?? null) !== 'symlink'
            || ($actual['target_b64'] ?? null) === ($expected['target_b64'] ?? null);
    }

    /**
     * Publishes or refreshes this session's WordPress maintenance marker.
     *
     * The private hard-linked identity distinguishes this session's marker
     * from a marker owned by WordPress or another deployment. A foreign marker
     * is left untouched and makes the commit retryable.
     *
     * @param array<string,mixed> $state
     */
    private function publish_or_refresh_maintenance_marker(array $state): void {
        $token = $state['maintenance_token'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new RuntimeException('Commit checkpoint has no valid maintenance marker identity.', self::ERROR_RETRYABLE_IO);
        }
        $live_path = $this->target_root . '/.maintenance';
        $live_identity = $this->path_identity($live_path);
        if ($live_identity !== null && !$this->maintenance_marker_is_owned($live_path, $token)) {
            throw new RuntimeException('A foreign WordPress maintenance marker is already present.', self::ERROR_BUSY);
        }

        if ($this->path_identity($this->maintenance_identity_path) === null) {
            $contents = $this->maintenance_marker_contents($token);
            $this->write_atomic_file($this->maintenance_identity_path, $contents, 0600);
        }
        if (!$this->maintenance_marker_is_owned($this->maintenance_identity_path, $token)) {
            throw new RuntimeException('The private maintenance marker identity is not owned by this session.', self::ERROR_RETRYABLE_IO);
        }

        $this->refresh_private_maintenance_marker($token);

        if ($live_identity === null) {
            // link() fails atomically when another updater wins the absent-name
            // race. A check-then-rename sequence could overwrite its marker.
            if (!@link($this->maintenance_identity_path, $live_path)) {
                $observed = $this->path_identity($live_path);
                if ($observed !== null && !$this->maintenance_marker_is_owned($live_path, $token)) {
                    throw new RuntimeException('A foreign WordPress maintenance marker appeared while publishing this session marker.', self::ERROR_BUSY);
                }
                throw new RuntimeException('Could not publish the WordPress maintenance marker.', self::ERROR_RETRYABLE_IO);
            }
        }

        if (!$this->maintenance_marker_is_owned($live_path, $token)) {
            throw new RuntimeException('The WordPress maintenance marker changed while it was being refreshed.', self::ERROR_BUSY);
        }
    }

    /**
     * Rewrites the timestamp WordPress evaluates without replacing the owned inode.
     *
     * WordPress ignores the marker's filesystem mtime and expires the `$upgrading`
     * value stored in its PHP body. A fixed-width decimal string lets each refresh
     * overwrite the same bytes in place, preserving the hard-link identity used
     * for ownership and avoiding a rename which could replace a foreign marker.
     *
     * @param string $token Commit checkpoint's maintenance ownership token.
     */
    private function refresh_private_maintenance_marker(string $token): void {
        $contents = $this->maintenance_marker_contents($token);
        $identity = $this->path_identity($this->maintenance_identity_path);
        if ($identity === null || $identity['type'] !== 'file' || (int) $identity['size'] !== strlen($contents)) {
            throw new RuntimeException('The private maintenance marker has an unexpected size and cannot be refreshed safely.', self::ERROR_RETRYABLE_IO);
        }
        $handle = @fopen($this->maintenance_identity_path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open the private maintenance marker for refresh.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($handle, 0, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek the private maintenance marker for refresh.', self::ERROR_RETRYABLE_IO);
            }
            $this->write_all($handle, $contents, 'private maintenance marker');
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the private maintenance marker refresh.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Returns the fixed-length marker body WordPress evaluates on every request.
     *
     * @param string $token Commit checkpoint's maintenance ownership token.
     */
    private function maintenance_marker_contents(string $token): string {
        $timestamp = sprintf('%010d', time());
        if (strlen($timestamp) !== 10) {
            throw new RuntimeException('The current time cannot be represented in the maintenance marker.', self::ERROR_RETRYABLE_IO);
        }
        return "<?php\n\$upgrading = '" . $timestamp . "';\n// reprint-staged-session:" . $this->session_id . ':' . $token . "\n";
    }

    /**
     * Removes the live maintenance marker only when this session still owns it.
     *
     * @param array<string,mixed> $state
     */
    private function remove_owned_maintenance_marker(array $state): void {
        $token = $state['maintenance_token'] ?? null;
        if (!is_string($token)) {
            throw new RuntimeException('Commit checkpoint has no maintenance marker identity.', self::ERROR_RETRYABLE_IO);
        }
        $live_path = $this->target_root . '/.maintenance';
        if ($this->path_identity($live_path) === null) {
            return;
        }
        if (!$this->maintenance_marker_is_owned($live_path, $token)) {
            throw new RuntimeException('Refusing to remove a foreign WordPress maintenance marker.', self::ERROR_BUSY);
        }
        if (!@unlink($live_path)) {
            throw new RuntimeException('Could not remove this session\'s WordPress maintenance marker.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Indicates whether a maintenance marker is still owned by this session.
     *
     * Ownership requires both the same file identity as the private hard link
     * and the session/token line in a small bounded body. Neither inode reuse nor
     * copied marker contents alone is enough to authorize removal.
     *
     * @param string $path Live or private marker path to inspect.
     * @param string $token Commit checkpoint's random maintenance token.
     * @return bool True only when both identity and contents match.
     */
    private function maintenance_marker_is_owned(string $path, string $token): bool {
        $identity = $this->path_identity($path);
        $private_identity = $this->path_identity($this->maintenance_identity_path);
        if (
            $identity === null
            || $private_identity === null
            || $identity['type'] !== 'file'
            || $private_identity['type'] !== 'file'
            || $identity !== $private_identity
            || (int) $identity['size'] > 512
        ) {
            return false;
        }
        $contents = @file_get_contents($path, false, null, 0, 513);
        if (!is_string($contents) || strlen($contents) > 512) {
            return false;
        }
        return strpos($contents, '// reprint-staged-session:' . $this->session_id . ':' . $token . "\n") !== false;
    }

    /**
     * Claims the one target-wide coordinator before preparation begins.
     *
     * Session locks serialize work within one id; this separate claim prevents
     * two sessions from preparing and switching the same target concurrently.
     * Reclaiming an existing claim by this session is idempotent after a crash.
     */
    private function claim_target(): void {
        $this->with_target_lock(function (): void {
            $active_path = $this->storage_dir . '/apply-sessions/target.active';
            $active = @file_get_contents($active_path);
            if (is_string($active)) {
                $active = trim($active);
                if ($active !== '' && $active !== $this->session_id) {
                    throw new RuntimeException('Another staged apply session is already committing this target: ' . $active . '.', self::ERROR_BUSY);
                }
            }
            $this->write_target_coordinator_file($active_path, $this->session_id . "\n");
        });
    }

    /**
     * Releases only this session's target-wide coordinator claim.
     *
     * A missing or foreign active id is left untouched, so stale cleanup from
     * this object cannot unlock another commit.
     */
    private function release_target(): void {
        $this->with_target_lock(function (): void {
            $active_path = $this->storage_dir . '/apply-sessions/target.active';
            $active = @file_get_contents($active_path);
            if (!is_string($active) || trim($active) !== $this->session_id) {
                return;
            }
            if (!@unlink($active_path)) {
                throw new RuntimeException('Could not release the staged apply target coordinator.', self::ERROR_RETRYABLE_IO);
            }
        });
    }

    /**
     * Runs one coordinator mutation under the non-blocking target-wide lock.
     *
     * @param callable():void $callback
     */
    private function with_target_lock(callable $callback): void {
        $lock_path = $this->storage_dir . '/apply-sessions/target.lock';
        $lock = @fopen($lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open the staged apply target coordinator lock.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The staged apply target coordinator is busy. Retry the request.', self::ERROR_BUSY);
            }
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Publishes target-wide coordinator metadata through a flushed temporary rename.
     *
     * The path is restricted to the apply-sessions root. Readers therefore see
     * either the old complete owner id or the new one, never a partial write.
     *
     * @param string $path Allowed target coordinator metadata path.
     * @param string $contents Complete owner record to publish.
     */
    private function write_target_coordinator_file(string $path, string $contents): void {
        $parent = dirname($path);
        if ($parent !== $this->storage_dir . '/apply-sessions') {
            throw new LogicException('The staged apply target coordinator path escaped its storage directory.');
        }
        $temporary = $path . '.tmp';
        if ($this->path_identity($temporary) !== null && !@unlink($temporary)) {
            throw new RuntimeException('Could not clear the staged apply target coordinator temporary file.', self::ERROR_RETRYABLE_IO);
        }
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create staged apply target coordinator metadata.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $this->write_all($handle, $contents, 'staged apply target coordinator metadata');
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush staged apply target coordinator metadata.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish staged apply target coordinator metadata.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Discards only an incomplete final JSONL record after a killed upload.
     *
     * Complete newline-terminated records are immutable evidence. If the file
     * lacks a final newline, truncation returns it to the preceding newline;
     * malformed complete records are rejected later rather than silently removed.
     */
    private function repair_delete_tail(): void {
        $this->repair_jsonl_tail($this->deletes_path, 'staged delete list');
    }

    /** Repairs a killed append to the positive-path journal. */
    private function repair_staged_paths_tail(): void {
        $this->repair_jsonl_tail($this->staged_paths_path, 'staged positive-path journal');
    }

    /** Discards only the final non-newline-terminated record of one journal. */
    private function repair_jsonl_tail(string $path, string $description): void {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return;
        }
        if ($identity['type'] !== 'file') {
            throw new RuntimeException('The ' . $description . ' is not a regular file.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $identity['size'] === 0) {
            return;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not repair the ' . $description . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $size = (int) $identity['size'];
            if (fseek($handle, -1, SEEK_END) !== 0 || fread($handle, 1) === "\n") {
                return;
            }
            $position = $size;
            while ($position > 0) {
                $start = max(0, $position - 65536);
                if (fseek($handle, $start, SEEK_SET) !== 0) {
                    throw new RuntimeException('Could not seek the ' . $description . ' for repair.', self::ERROR_RETRYABLE_IO);
                }
                $chunk = fread($handle, $position - $start);
                if (!is_string($chunk)) {
                    throw new RuntimeException('Could not read the ' . $description . ' for repair.', self::ERROR_RETRYABLE_IO);
                }
                $newline = strrpos($chunk, "\n");
                if ($newline !== false) {
                    if (!ftruncate($handle, $start + $newline + 1) || !fflush($handle)) {
                        throw new RuntimeException('Could not repair the ' . $description . ' tail.', self::ERROR_RETRYABLE_IO);
                    }
                    return;
                }
                $position = $start;
            }
            if (!ftruncate($handle, 0) || !fflush($handle)) {
                throw new RuntimeException('Could not clear an incomplete ' . $description . ' record.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Performs one atomic rename after proving both sides share a device.
     *
     * rename() is the commit primitive; falling back to copy-and-delete would
     * expose partial plugin/theme trees. A device mismatch is therefore a hard
     * refusal which names both observed device ids.
     *
     * @param string $source Existing entry moved atomically.
     * @param string $destination Absent destination path.
     * @param string $operation Human-readable action named in failures.
     */
    private function rename_same_filesystem(string $source, string $destination, string $operation): void {
        $source_identity = $this->path_identity($source);
        $destination_parent_identity = $this->path_identity(dirname($destination));
        if ($source_identity === null || $destination_parent_identity === null || $destination_parent_identity['type'] !== 'directory') {
            throw new RuntimeException('Could not determine filesystem identities to ' . $operation . '.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $source_identity['dev'] !== (int) $destination_parent_identity['dev']) {
            throw new RuntimeException(
                'Refusing non-atomic ' . $operation . ': source device ' . $source_identity['dev']
                . ' differs from destination device ' . $destination_parent_identity['dev'] . '.'
            );
        }
        if (!@rename($source, $destination)) {
            throw new RuntimeException('Could not ' . $operation . '.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Requires every target parent to be a real same-device directory.
     *
     * The method creates no live parents. Action planning must choose a complete
     * tree root whenever the final entry sits below a missing ancestor; silently
     * mkdir here would split one intended atomic tree into visible substeps.
     *
     * @param string $target_path Absolute live destination path.
     */
    private function ensure_target_parent(string $target_path): void {
        $relative = substr($target_path, strlen($this->target_root));
        $relative = ltrim($relative, '/');
        $segments = $relative === '' ? [] : explode('/', $relative);
        array_pop($segments);
        $current = $this->target_root;
        $root_identity = $this->path_identity($current);
        if ($root_identity === null || $root_identity['type'] !== 'directory') {
            throw new RuntimeException('The apply target root is not a directory.', self::ERROR_RETRYABLE_IO);
        }
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $identity = $this->path_identity($current);
            if ($identity === null || $identity['type'] !== 'directory' || is_link($current)) {
                throw new RuntimeException('Refusing to install through a missing or symlinked target parent ' . $current . '.');
            }
            if ((int) $identity['dev'] !== (int) $root_identity['dev']) {
                throw new RuntimeException('Refusing to install below separately mounted target parent ' . $current . '.');
            }
        }
    }

    /**
     * Verifies existing path components stay on the target device and no
     * ancestor is a symlink.
     *
     * The final path itself may be a symlink because replacing a symlink is a
     * valid operation; only ancestor links could redirect traversal.
     *
     * @param string $path Validated target-relative path.
     */
    private function assert_target_path_same_filesystem(string $path): void {
        if ($path === '') {
            throw new RuntimeException('Could not check an empty target path.', self::ERROR_RETRYABLE_IO);
        }
        $root_identity = $this->path_identity($this->target_root);
        if ($root_identity === null || $root_identity['type'] !== 'directory') {
            throw new RuntimeException('The apply target root is not a directory.', self::ERROR_RETRYABLE_IO);
        }
        $current = $this->target_root;
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            $current .= '/' . $segment;
            $identity = $this->path_identity($current);
            if ($identity === null) {
                break;
            }
            if ((int) $identity['dev'] !== (int) $root_identity['dev']) {
                throw new RuntimeException('Refusing staged apply path below separately mounted target path ' . $current . '.');
            }
            // A file or symlink ancestor can be a deliberate transition to a
            // directory. Stop at that lstat result: commit planning replaces
            // the ancestor as a whole, and must never inspect through a link.
            if ($index < count($segments) - 1 && $identity['type'] !== 'directory') {
                break;
            }
        }
    }

    /**
     * Builds a private workspace path after proving its root cannot escape the session.
     *
     * @param string $root One of this session's private subtree roots.
     * @param string $relative_path Validated target-relative path.
     * @return string Absolute private path.
     */
    private function private_path(string $root, string $relative_path): string {
        $this->validate_path($relative_path);
        $root = rtrim($root, '/');
        if ($root === '' || strpos($root . '/', $this->session_dir . '/') !== 0) {
            throw new LogicException('A staged apply private path escaped its session workspace.');
        }
        return $root . '/' . $relative_path;
    }

    /**
     * Builds an absolute live path from a validated target-relative path.
     *
     * @param string $relative_path Target-relative path.
     * @return string Absolute path below $target_root.
     */
    private function target_path(string $relative_path): string {
        $this->validate_path($relative_path);
        return $this->target_root === '/' ? '/' . $relative_path : $this->target_root . '/' . $relative_path;
    }

    /**
     * Requires private parent components to be actual directories. When
     * $create_missing is false, a missing parent is safe and simply means
     * there cannot yet be a staged leaf below it.
     *
     * @param string $path Absolute private leaf path.
     * @param bool $create_missing Whether absent parents should be created.
     */
    private function ensure_private_parent(string $path, bool $create_missing = true): void {
        $session_prefix = $this->session_dir . '/';
        $parent = dirname($path);
        if ($parent !== $this->session_dir && strpos($parent . '/', $session_prefix) !== 0) {
            throw new LogicException('A staged apply private path escaped its session workspace.');
        }
        $relative = $parent === $this->session_dir ? '' : substr($parent, strlen($session_prefix));
        $current = $this->session_dir;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            $identity = $this->path_identity($current);
            if ($identity !== null && ($identity['type'] !== 'directory' || is_link($current))) {
                throw new RuntimeException('Staged apply workspace parent is not a real directory: ' . $current . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($identity === null && !$create_missing) {
                return;
            }
            if ($identity === null && !@mkdir($current, 0700) && !is_dir($current)) {
                throw new RuntimeException('Could not create staged apply workspace directory ' . $current . '.', self::ERROR_RETRYABLE_IO);
            }
        }
    }

    /**
     * Returns the no-follow filesystem identity persisted in commit checkpoints.
     *
     * Entries include type, device, inode, size, and ctime; symlinks also include
     * the literal target. lstat() keeps an attacker-controlled link from
     * changing what is identified.
     *
     * @param string $path Absolute filesystem path to inspect.
     * @return array<string,mixed>|null Stable identity, or null when absent.
     */
    private function path_identity(string $path): ?array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (!isset($stat['mode'], $stat['dev'], $stat['ino'], $stat['ctime'], $stat['size'])) {
            throw new RuntimeException('Could not read complete filesystem identity for ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $mode = (int) $stat['mode'];
        $type_bits = $mode & 0170000;
        if ($type_bits === 0040000) {
            $type = 'directory';
        } elseif ($type_bits === 0100000) {
            $type = 'file';
        } elseif ($type_bits === 0120000) {
            $type = 'symlink';
        } else {
            throw new RuntimeException('Unsupported filesystem entry type at ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $identity = [
            'type' => $type,
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'ctime' => (int) $stat['ctime'],
            'size' => (int) $stat['size'],
            'permissions' => $mode & 07777,
        ];
        if ($type === 'symlink') {
            $target = @readlink($path);
            if (!is_string($target)) {
                throw new RuntimeException('Could not read symbolic link target at ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            $identity['target_b64'] = base64_encode($target);
        }
        return $identity;
    }

    /**
     * Fingerprints a live entry and every descendant without following links.
     *
     * A directory's own lstat identity cannot observe a rewrite of an
     * existing child file. A streaming digest avoids retaining an identity map
     * for the complete tree while closing that gap between candidate preparation
     * and the first live rename.
     *
     * @param string $path Absolute live entry path.
     * @return string|null Deterministic SHA-256 digest, or null when absent.
     */
    private function tree_fingerprint(string $path): ?string {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return null;
        }
        $context = hash_init('sha256');
        $this->append_tree_fingerprint($context, $path, '', $identity, (int) $identity['dev']);
        return hash_final($context);
    }

    /**
     * Adds one identity and its sorted descendants to a no-follow tree digest.
     *
     * @param resource|object $context
     * @param string $path Absolute entry currently being hashed.
     * @param string $relative_path Root-relative path included in the digest.
     * @param array<string,mixed> $identity
     * @param int $root_device Device id every descendant must retain.
     */
    private function append_tree_fingerprint($context, string $path, string $relative_path, array $identity, int $root_device): void {
        if ((int) $identity['dev'] !== $root_device) {
            throw new RuntimeException(
                'Refusing to fingerprint a live tree below separately mounted path ' . $path . '.',
                self::ERROR_RETRYABLE_IO
            );
        }
        $content_hash = null;
        if ($identity['type'] === 'file') {
            $content_hash = @hash_file('sha256', $path);
            if (!is_string($content_hash) || $this->path_identity($path) !== $identity) {
                throw new RuntimeException('Live file changed while it was being fingerprinted: ' . $path . '.', self::ERROR_LIVE_TREE_CHANGED);
            }
        }
        $record = json_encode([
            'path_b64' => base64_encode($relative_path),
            'identity' => $identity,
            'content_sha256' => $content_hash,
        ], JSON_UNESCAPED_SLASHES);
        if ($record === false) {
            throw new RuntimeException('Could not fingerprint live tree entry ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        hash_update($context, $record . "\n");
        if ($identity['type'] !== 'directory') {
            return;
        }
        $entries = @scandir($path);
        if (!is_array($entries) || $this->path_identity($path) !== $identity) {
            throw new RuntimeException('Live directory changed while it was being fingerprinted: ' . $path . '.', self::ERROR_LIVE_TREE_CHANGED);
        }
        $children = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $children[] = $entry;
            }
        }
        sort($children, SORT_STRING);
        foreach ($children as $entry) {
            $child_path = $path . '/' . $entry;
            $child_identity = $this->path_identity($child_path);
            if ($child_identity === null) {
                throw new RuntimeException('Live tree entry disappeared while it was being fingerprinted: ' . $child_path . '.', self::ERROR_LIVE_TREE_CHANGED);
            }
            $child_relative_path = $relative_path === '' ? $entry : $relative_path . '/' . $entry;
            $this->append_tree_fingerprint($context, $child_path, $child_relative_path, $child_identity, $root_device);
        }
    }

    /**
     * Recursively removes an entry without following symlinks.
     *
     * A symlink is unlinked as one leaf regardless of its target. Missing paths
     * are already the desired state and return successfully.
     *
     * @param string $path Absolute live or private entry to remove.
     */
    private function remove_entry(string $path): void {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return;
        }
        if ($identity['type'] === 'directory') {
            // Prepared candidates and backups preserve source modes, including
            // read-only directories. They are private cleanup inputs here, so
            // restore owner access before descending into them.
            if (!@chmod($path, 0700)) {
                throw new RuntimeException('Could not make private directory removable: ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            $entries = @scandir($path);
            if (!is_array($entries)) {
                throw new RuntimeException('Could not read directory for removal: ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove_entry($path . '/' . $entry);
                }
            }
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove directory ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            return;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove filesystem entry ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Reads one bounded JSON object from durable session metadata.
     *
     * @param string $path Session or commit metadata path.
     * @return array<string,mixed>|null Decoded object, or null when absent.
     */
    private function read_json(string $path): ?array {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return null;
        }
        if ($identity['type'] !== 'file' || (int) $identity['size'] > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('Staged apply metadata is not a bounded regular file: ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_METADATA_BYTES + 1);
        if (!is_string($contents) || strlen($contents) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('Could not read bounded staged apply metadata ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $value = json_decode($contents, true);
        if (!is_array($value)) {
            throw new RuntimeException('Staged apply metadata is not valid JSON: ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        return $value;
    }

    /**
     * Encodes and atomically publishes one bounded session metadata object.
     *
     * @param string $path Session or commit metadata destination.
     * @param array<string,mixed> $value Complete value to persist.
     */
    private function write_json(string $path, array $value): void {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('Could not encode bounded staged apply metadata ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->write_atomic_file($path, $encoded, 0600);
    }

    /**
     * Publishes private metadata only after a complete, flushed temporary write.
     *
     * The temporary file is created exclusively beside its destination and then
     * renamed, so recovery observes either the prior complete checkpoint or the
     * new one. A stale temporary entry is removed without following symlinks.
     *
     * @param string $path Final private metadata path.
     * @param string $contents Complete bytes to publish.
     * @param int $permissions Mode applied before the final rename.
     */
    private function write_atomic_file(string $path, string $contents, int $permissions): void {
        $this->ensure_private_parent($path);
        $temporary = $path . '.tmp';
        if ($this->path_identity($temporary) !== null) {
            $this->remove_entry($temporary);
        }
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create staged apply temporary file ' . $temporary . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $this->write_all($handle, $contents, 'staged apply metadata ' . $path);
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush staged apply temporary file ' . $temporary . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, $permissions);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish staged apply file ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Writes every byte to a stream whose fwrite() calls may be short.
     *
     * @param resource $handle Writable stream.
     * @param string $contents Complete bytes to write.
     * @param string $description Human-readable object named in failures.
     */
    private function write_all($handle, string $contents, string $description): void {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write ' . $description . '.', self::ERROR_RETRYABLE_IO);
            }
            $offset += $written;
        }
    }

    /**
     * Runs one session operation under its non-blocking workspace lock.
     *
     * @param callable():mixed $callback
     * @return mixed
     */
    private function with_session_lock(callable $callback) {
        if (!is_dir($this->session_dir)) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id . '.', self::ERROR_SESSION_NOT_FOUND);
        }
        $lock = @fopen($this->lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open staged apply session lock.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry the request.', self::ERROR_BUSY);
            }
            $this->assert_workspace_layout();
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The workspace is private state, but it is still durable state that can
     * survive a crashed process. Refuse a substituted directory or symlink
     * rather than letting a later lstat() through an unexpected ancestor.
     *
     * All required private roots must be real directories and the lock must be
     * a regular file. Missing optional data files are interpreted by their
     * individual readers, never by weakening the workspace roots.
     */
    private function assert_workspace_layout(): void {
        foreach ([
            'session directory' => $this->session_dir,
            'work directory' => $this->work_dir,
            'completed-files directory' => $this->files_dir,
            'partial-files directory' => $this->partial_dir,
            'prepared-files directory' => $this->prepared_dir,
            'backup directory' => $this->backups_dir,
        ] as $description => $path) {
            $identity = $this->path_identity($path);
            if ($identity === null || $identity['type'] !== 'directory' || is_link($path)) {
                throw new RuntimeException('Staged apply ' . $description . ' is not a real directory: ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
        }
        $lock_identity = $this->path_identity($this->lock_path);
        if ($lock_identity === null || $lock_identity['type'] !== 'file' || is_link($this->lock_path)) {
            throw new RuntimeException('Staged apply session lock is not a regular file.', self::ERROR_RETRYABLE_IO);
        }
        $staged_paths_identity = $this->path_identity($this->staged_paths_path);
        if ($staged_paths_identity === null || $staged_paths_identity['type'] !== 'file' || is_link($this->staged_paths_path)) {
            throw new RuntimeException('Staged apply positive-path journal is not a regular file.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Verifies immutable session ownership against current server configuration.
     *
     * The session id, canonical target root, and normalized protected paths must
     * exactly match the creation metadata. A server reconfiguration cannot
     * silently retarget a durable workspace created for another tree.
     */
    private function read_session_metadata(): void {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata) || ($metadata['version'] ?? null) !== 1 || ($metadata['session_id'] ?? null) !== $this->session_id || !isset($metadata['target_root_b64'])) {
            throw new RuntimeException('Staged apply session metadata is invalid.', self::ERROR_INVALID_STATE);
        }
        $target_root = is_string($metadata['target_root_b64']) ? base64_decode($metadata['target_root_b64'], true) : false;
        if ($target_root === false || $target_root !== $this->target_root) {
            throw new RuntimeException('Staged apply session belongs to a different target root.', self::ERROR_INVALID_STATE);
        }
        $stored_paths = $metadata['protected_paths_b64'] ?? null;
        if (!is_array($stored_paths)) {
            throw new RuntimeException('Staged apply session protected-path metadata is invalid.', self::ERROR_INVALID_STATE);
        }
        $decoded_paths = [];
        foreach ($stored_paths as $encoded_path) {
            $path = is_string($encoded_path) ? base64_decode($encoded_path, true) : false;
            if ($path === false || $path === '') {
                throw new RuntimeException('Staged apply session protected-path metadata is invalid.', self::ERROR_INVALID_STATE);
            }
            $decoded_paths[] = $path;
        }
        if (self::normalize_protected_paths($decoded_paths) !== $this->protected_paths) {
            throw new RuntimeException('Staged apply session protected paths no longer match server configuration.', self::ERROR_INVALID_STATE);
        }
        $stored_roots = $metadata['deployment_roots_b64'] ?? null;
        if (!is_array($stored_roots)) {
            throw new RuntimeException('Staged apply session deployment-root metadata is invalid.', self::ERROR_INVALID_STATE);
        }
        $decoded_roots = [];
        foreach ($stored_roots as $encoded_root) {
            $root = is_string($encoded_root) ? base64_decode($encoded_root, true) : false;
            if ($root === false || $root === '') {
                throw new RuntimeException('Staged apply session deployment-root metadata is invalid.', self::ERROR_INVALID_STATE);
            }
            $decoded_roots[] = $root;
        }
        if (self::normalize_deployment_roots($decoded_roots) !== $this->deployment_roots) {
            throw new RuntimeException('Staged apply session deployment roots no longer match server configuration.', self::ERROR_INVALID_STATE);
        }
    }

    /**
     * Rejects a corrupt or internally inconsistent durable commit checkpoint.
     *
     * Version, phase names, action record shapes, index bounds, phase/index
     * consistency, and the basic transition type are checked here. Later
     * prepare/switch methods validate decoded paths, identities, maintenance
     * ownership, and transition-stage fields at the point they use them.
     *
     * @param array<string,mixed> $state
     */
    private function require_valid_commit_state(array $state): void {
        if (($state['version'] ?? null) !== 2) {
            throw new RuntimeException('Commit checkpoint has an unsupported version ' . json_encode($state['version'] ?? null) . '.', self::ERROR_INVALID_STATE);
        }
        $phase = $state['phase'] ?? null;
        if (!is_string($phase) || !in_array($phase, ['materializing', 'preparing', 'switching', 'cleaning', 'complete'], true)) {
            throw new RuntimeException('Commit checkpoint has an invalid phase ' . json_encode($phase) . '.', self::ERROR_INVALID_STATE);
        }
        foreach ([$this->commit_work_dir, $this->delete_index_dir, $this->delete_descendants_index_dir, $this->staged_index_dir, $this->action_index_dir, $this->prepare_modes_dir] as $directory) {
            $identity = $this->path_identity($directory);
            if ($identity === null || $identity['type'] !== 'directory') {
                throw new RuntimeException('Commit checkpoint is missing a private disk-backed planning directory.', self::ERROR_INVALID_STATE);
            }
        }
        foreach (['delete_index_offset', 'staged_paths_offset', 'delete_action_offset', 'candidate_offset', 'actions_bytes', 'actions_count', 'prepare_offset', 'prepared_actions_bytes', 'prepare_index', 'switch_offset', 'switch_index'] as $field) {
            $value = $state[$field] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException('Commit checkpoint ' . $field . ' must be a non-negative integer; observed ' . json_encode($value) . '.', self::ERROR_INVALID_STATE);
            }
        }
        if ($state['prepare_index'] > $state['actions_count'] || $state['switch_index'] > $state['prepare_index']) {
            throw new RuntimeException('Commit checkpoint action indexes are inconsistent.', self::ERROR_INVALID_STATE);
        }
        if (!array_key_exists('current_prepare', $state)) {
            throw new RuntimeException('Commit checkpoint has no current preparation cursor.', self::ERROR_INVALID_STATE);
        }
        $current_prepare = $state['current_prepare'];
        if ($current_prepare !== null && !is_array($current_prepare)) {
            throw new RuntimeException('Commit checkpoint current preparation cursor is invalid.', self::ERROR_INVALID_STATE);
        }
        if ($phase !== 'preparing' && $current_prepare !== null) {
            throw new RuntimeException('Commit checkpoint retains a preparation cursor in phase ' . $phase . '.', self::ERROR_INVALID_STATE);
        }
        $materialize_stage = $state['materialize_stage'] ?? null;
        if (!is_string($materialize_stage) || !in_array($materialize_stage, ['delete_index', 'staged', 'delete_actions', 'actions', 'complete'], true)) {
            throw new RuntimeException('Commit checkpoint has an invalid materialization stage.', self::ERROR_INVALID_STATE);
        }
        if ($phase !== 'materializing' && $materialize_stage !== 'complete') {
            throw new RuntimeException('Commit checkpoint left materialization incomplete before phase ' . $phase . '.', self::ERROR_INVALID_STATE);
        }
        if (!in_array($phase, ['materializing', 'preparing'], true) && $state['prepare_index'] !== $state['actions_count']) {
            throw new RuntimeException('Commit checkpoint phase ' . $phase . ' has unprepared actions.', self::ERROR_INVALID_STATE);
        }
        if (in_array($phase, ['cleaning', 'complete'], true) && $state['switch_index'] !== $state['actions_count']) {
            throw new RuntimeException('Commit checkpoint phase ' . $phase . ' has unswitched actions.', self::ERROR_INVALID_STATE);
        }
        if (array_key_exists('transition', $state) && $state['transition'] !== null && !is_array($state['transition'])) {
            throw new RuntimeException('Commit checkpoint transition is invalid.', self::ERROR_INVALID_STATE);
        }
    }

    /**
     * Rejects path traversal, protected targets, and separately mounted target
     * components before a staged operation mutates the filesystem.
     *
     * Paths are raw byte strings relative to the target root. Absolute paths,
     * empty/dot segments, NUL, backslashes, `.maintenance`, and any overlap with
     * protected paths are refused before private or live path construction.
     *
     * @param string $path Target-relative path to validate.
     */
    private function validate_path(string $path): void {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new InvalidArgumentException('Unsafe staged apply path ' . $this->describe_path($path) . '.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Unsafe staged apply path ' . $this->describe_path($path) . '.');
            }
        }
        if ($path === '.maintenance' || strpos($path, '.maintenance/') === 0) {
            throw new InvalidArgumentException('The WordPress maintenance marker is protected from staged apply changes.');
        }
        foreach ($this->protected_paths as $protected_path) {
            if (
                $path === $protected_path
                || strpos($path, $protected_path . '/') === 0
                || strpos($protected_path, $path . '/') === 0
            ) {
                throw new InvalidArgumentException('Protected staged apply path ' . $this->describe_path($path) . '.');
            }
        }
        $this->assert_target_path_same_filesystem($path);
    }

    /**
     * Decodes one base64 protocol header and optionally validates it as a path.
     *
     * Symlink targets use the same binary-safe transport but deliberately skip
     * target-path validation because their literal relative syntax is data, not
     * a path traversed by the staging process.
     *
     * @param array<string,string> $headers Current part headers.
     * @param string $header Lowercase required header name.
     * @param bool $is_target_path Whether to apply target path safety rules.
     * @return string Raw decoded byte string.
     */
    private function decode_path_header(array $headers, string $header, bool $is_target_path = true): string {
        $encoded = $headers[$header] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new InvalidArgumentException('Multipart push part requires a ' . $header . ' header.');
        }
        $value = base64_decode($encoded, true);
        if ($value === false) {
            throw new InvalidArgumentException('Multipart push header ' . $header . ' is not valid base64.');
        }
        if ($is_target_path) {
            $this->validate_path($value);
        }
        return $value;
    }

    /**
     * Returns a required decimal header within this server's integer range.
     *
     * Leading signs, whitespace, and leading zeroes are rejected so one value
     * has one wire representation and arithmetic cannot silently overflow.
     *
     * @param array<string,string> $headers Current part headers.
     * @param string $header Lowercase required header name.
     * @return int Parsed non-negative value.
     */
    private function require_non_negative_header(array $headers, string $header): int {
        $value = $headers[$header] ?? null;
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('Multipart push header ' . $header . ' must be a non-negative integer; observed ' . json_encode($value) . '.');
        }
        if (strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('Multipart push header ' . $header . ' exceeds this server\'s integer range: ' . $value . '.');
        }
        return (int) $value;
    }

    /**
     * Returns an octal permission header without accepting file-type bits.
     *
     * @param array<string,string> $headers Current part headers.
     * @param string $header Lowercase required header name.
     */
    private function require_mode_header(array $headers, string $header): int {
        $value = $headers[$header] ?? null;
        if (!is_string($value) || preg_match('/^0[0-7]{3,4}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Multipart push header ' . $header . ' must be an octal mode such as 0644; observed ' . json_encode($value) . '.');
        }
        $mode = octdec($value);
        if ($mode < 0 || $mode > 07777) {
            throw new InvalidArgumentException('Multipart push header ' . $header . ' exceeds permission bits: ' . $value . '.');
        }
        return $mode;
    }

    /**
     * Returns the complete plugin or theme root containing a changed path.
     *
     * A path below a configured container becomes that container's direct child.
     * Container directories and unrelated paths return null because they are not
     * independently deployable units.
     *
     * @param string $path Validated target-relative path.
     * @return string|null Atomic deployment-unit root.
     */
    private function deployment_unit_for_path(string $path): ?string {
        foreach ($this->deployment_roots as $root) {
            $prefix = $root . '/';
            if (strpos($path, $prefix) !== 0) {
                continue;
            }
            $remainder = substr($path, strlen($prefix));
            $separator = strpos($remainder, '/');
            $child = $separator === false ? $remainder : substr($remainder, 0, $separator);
            if ($child !== '') {
                return $root . '/' . $child;
            }
        }
        return null;
    }

    /**
     * Encodes an arbitrary path byte string for safe text diagnostics.
     *
     * @param string $path Raw filesystem path bytes.
     * @return string Base64 representation safe for JSON and logs.
     */
    private function describe_path(string $path): string {
        return base64_encode($path);
    }

    /**
     * Returns a canonical real directory, creating it only when explicitly allowed.
     *
     * Symlinked roots are refused because later no-follow checks depend on a
     * stable directory identity.
     *
     * @param string $path Absolute configured path.
     * @param string $description Human-readable role named in failures.
     * @param bool $create Whether a missing directory may be created mode 0700.
     * @return string Canonical absolute directory without a trailing slash,
     *     except for the filesystem root `/`.
     */
    private static function require_directory(string $path, string $description, bool $create): string {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('The ' . $description . ' must be an absolute directory; observed ' . json_encode($path) . '.');
        }
        if ($create && !is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create ' . $description . ' directory ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $real_path = realpath($path);
        if ($real_path === false || !is_dir($real_path) || is_link($path)) {
            throw new InvalidArgumentException('The ' . $description . ' is not a real directory: ' . $path . '.');
        }
        return $real_path === '/' ? '/' : rtrim($real_path, '/');
    }

    /**
     * Requires two existing roots to report the same filesystem device.
     *
     * Commit never falls back from rename to copy-and-delete, so this check is
     * performed at create/open time before the target accepts upload work.
     *
     * @param string $left_path First existing root.
     * @param string $right_path Second existing root.
     */
    private static function require_same_filesystem(string $left_path, string $right_path): void {
        $left = @lstat($left_path);
        $right = @lstat($right_path);
        if (!is_array($left) || !is_array($right) || !isset($left['dev'], $right['dev'])) {
            throw new RuntimeException('Could not determine the storage and target filesystem devices.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $left['dev'] !== (int) $right['dev']) {
            throw new InvalidArgumentException(
                'Staged apply requires storage and target root on one filesystem; storage device '
                . $left['dev'] . ' differs from target device ' . $right['dev'] . '.'
            );
        }
    }

    /**
     * Session state below the target root must be untouchable by a push just
     * like the installed Reprint plugin. This also covers a configured
     * staging path that did not exist when the WordPress plugin built options.
     *
     * @param string $storage_dir Canonical private session storage root.
     * @param string $target_root Canonical live target root.
     * @param string[] $protected_paths Configured target-relative protected paths.
     * @return string[] Protected paths including nested session storage, if any.
     */
    private static function protect_session_storage(string $storage_dir, string $target_root, array $protected_paths): array {
        if ($storage_dir === $target_root) {
            throw new InvalidArgumentException('Staged apply session storage must not be the apply target root itself.');
        }
        $target_prefix = $target_root === '/' ? '/' : $target_root . '/';
        if (strpos($storage_dir . '/', $target_prefix) === 0) {
            $relative_storage = ltrim(substr($storage_dir, strlen($target_root)), '/');
            if ($relative_storage !== '') {
                $protected_paths[] = $relative_storage;
            }
        }
        return $protected_paths;
    }

    /**
     * Requires the fixed target-owned session id grammar used in paths and HMAC.
     *
     * @param string $session_id Proposed session identity.
     */
    private static function require_session_id(string $session_id): void {
        if (preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Staged apply session id must be a 32-character lowercase hexadecimal string.');
        }
    }

    /**
     * Validates, sorts, and de-duplicates target-relative protected paths.
     *
     * Normalization makes immutable session metadata independent of option
     * order while retaining raw path bytes.
     *
     * @param string[] $protected_paths Configured path list.
     * @return string[] Stable normalized path list.
     */
    private static function normalize_protected_paths(array $protected_paths): array {
        return self::normalize_configured_paths($protected_paths, 'protected staged apply path');
    }

    /** @param string[] $deployment_roots @return string[] */
    private static function normalize_deployment_roots(array $deployment_roots): array {
        return self::normalize_configured_paths($deployment_roots, 'staged apply deployment root');
    }

    /**
     * @param string[] $paths Configured target-relative paths.
     * @return string[] Stable normalized path list.
     */
    private static function normalize_configured_paths(array $paths, string $description): array {
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
                throw new InvalidArgumentException('Each ' . $description . ' must be a non-empty safe relative path.');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException(ucfirst($description) . ' is unsafe: ' . $path . '.');
                }
            }
            $normalized[] = $path;
        }
        sort($normalized, SORT_STRING);
        return array_values(array_unique($normalized));
    }

    /**
     * Recursively removes private session storage without following symlinks.
     *
     * This static variant is used while creation is rolling back and after the
     * instance lock is held for discard. Any link encountered is unlinked as a
     * leaf, never traversed.
     *
     * @param string $path Private session entry to remove.
     */
    private static function remove_tree(string $path): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        $type = ((int) $stat['mode']) & 0170000;
        if ($type === 0040000) {
            $entries = @scandir($path);
            if (!is_array($entries)) {
                throw new RuntimeException('Could not read staged apply directory for removal: ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove_tree($path . '/' . $entry);
                }
            }
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove staged apply directory ' . $path . '.', self::ERROR_RETRYABLE_IO);
            }
            return;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove staged apply entry ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
    }
}
