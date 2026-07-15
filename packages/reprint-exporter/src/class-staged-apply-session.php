<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Session errors become authenticated API JSON, never HTML output.

if (!class_exists('Site_Export_Multipart_Processor', false)) {
    require_once __DIR__ . '/class-multipart-processor.php';
}
if (!class_exists('Site_Export_Staged_Apply_Exception', false)) {
    require_once __DIR__ . '/class-staged-apply-exception.php';
}

/**
 * Stages a push privately, then deletes and installs its values directly.
 *
 * `work/files/` is both the completed staging tree and the positive-work queue.
 * Incomplete file bytes live under `work/partial/`, but both trees represent
 * one logical path namespace: a value in either tree cannot hide or contain a
 * value in the other.
 * Successful installation consumes each entry. Deletes remain raw NUL-delimited
 * bytes in `work/deletes`; their confirmed cursor is the file's actual size.
 * Commit persists only one deletion, one installation, and a path-depth-bounded
 * traversal stack. It never builds a candidate tree, action plan, backup, path
 * index, or second queue.
 */
final class Site_Export_Staged_Apply_Session {

    public const ERROR_BUSY = 'busy';
    public const ERROR_OFFSET_GAP = 'offset_gap';
    public const ERROR_SESSION_NOT_FOUND = 'session_not_found';
    public const ERROR_RETRYABLE_IO = 'retryable_io_error';
    public const ERROR_COMMIT_REQUIRED = 'commit_required';
    public const ERROR_LIVE_TREE_CHANGED = 'live_tree_changed';
    public const ERROR_INVALID_STATE = 'invalid_session_state';
    public const ERROR_CROSS_DEVICE_FILESYSTEM = 'cross_device_filesystem';

    private const MAX_PATH_BYTES = 4096;
    private const MAX_METADATA_BYTES = 1048576;
    private const DISCARD_ENTRY_LIMIT = 256;

    /** @var string */
    private $storage_dir;
    /** @var string */
    private $target_root;
    /** @var string */
    private $session_id;
    /** @var string[] */
    private $protected_paths;
    /** @var string */
    private $session_dir;
    /** @var string */
    private $work_dir;
    /** @var string */
    private $files_dir;
    /** @var string */
    private $partial_dir;
    /** @var string */
    private $deletes_path;
    /** @var string */
    private $session_metadata_path;
    /** @var string */
    private $commit_path;
    /** @var string */
    private $lock_path;
    /** @var string */
    private $maintenance_identity_path;

    /** @var resource|null */
    private $upload_lock = null;
    /** @var resource|null */
    private $upload_input = null;
    /** @var Site_Export_Multipart_Processor|null */
    private $upload_processor = null;
    /** @var bool */
    private $current_upload_part_ended = false;
    /** @var array<string,mixed>|null */
    private $current_change = null;
    /** @var int */
    private $maximum_upload_part_bytes = PHP_INT_MAX;

    /**
     * Normalizes one session's policy and derives its private paths.
     *
     * Factory methods canonicalize the storage and target roots before they
     * reach this constructor. The constructor then establishes the invariant
     * shared by every session handle: protected paths are safe, sorted, and
     * unique, and storage below the managed root protects itself from push.
     * No filesystem state is read or changed here.
     *
     * @param list<string> $protected_paths Target-relative paths which push
     *                                      must never stage, delete, or replace.
     */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths) {
        $this->storage_dir = rtrim($storage_dir, '/');
        $this->target_root = $target_root === '/' ? '/' : rtrim($target_root, '/');
        $this->session_id = $session_id;
        if ($storage_dir === $this->target_root) {
            throw new InvalidArgumentException('Staged apply session storage must not be the apply target root itself.');
        }
        $target_prefix = $this->target_root === '/' ? '/' : $this->target_root . '/';
        if (strpos($storage_dir . '/', $target_prefix) === 0) {
            $relative_storage = ltrim(substr($storage_dir, strlen($this->target_root)), '/');
            if ($relative_storage !== '') {
                $protected_paths[] = $relative_storage;
            }
        }
        $normalized_protected_paths = [];
        foreach ($protected_paths as $path) {
            if (!is_string($path) || $path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
                throw new InvalidArgumentException('Each protected staged apply path must be a non-empty safe relative path.');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('Protected staged apply path is unsafe: ' . base64_encode($path) . '.');
                }
            }
            $normalized_protected_paths[] = $path;
        }
        sort($normalized_protected_paths, SORT_STRING);
        $this->protected_paths = array_values(array_unique($normalized_protected_paths));
        $this->session_dir = $this->storage_dir . '/apply-sessions/' . $session_id;
        $this->session_metadata_path = $this->session_dir . '/session.json';
        $this->commit_path = $this->session_dir . '/commit.json';
        $this->lock_path = $this->session_dir . '/lock';
        $this->work_dir = $this->session_dir . '/work';
        $this->files_dir = $this->work_dir . '/files';
        $this->partial_dir = $this->work_dir . '/partial';
        $this->deletes_path = $this->work_dir . '/deletes';
        $this->maintenance_identity_path = $this->work_dir . '/maintenance.php';
    }

    /**
     * Creates or idempotently reopens one private apply session.
     *
     * The empty staging tree is created before its device is compared with the
     * managed root. A mismatch removes the new session before any multipart
     * bytes can be accepted. That device check necessarily stats the new tree;
     * successful creation and metadata writes are otherwise trusted instead
     * of being followed by a complete layout scan.
     *
     * Replaying the same session id returns a handle to the existing workspace.
     * Its durable layout and immutable metadata are validated under the session
     * lock by the first upload, status, or commit operation. Keeping that check
     * at the operation boundary avoids reading the same layout before and after
     * its lock is acquired.
     *
     * @param string $storage_dir Durable private storage on the target filesystem.
     * @param string $target_root Managed live directory receiving committed values.
     * @param list<string> $protected_paths Target-relative paths which push must preserve.
     * @param string $session_id Stable lowercase hexadecimal session identity.
     * @return self New or existing session handle.
     */
    public static function create(string $storage_dir, string $target_root, array $protected_paths, string $session_id): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', true);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        $sessions_dir = $storage_dir . '/apply-sessions';
        if (!@mkdir($sessions_dir, 0700)) {
            if (!is_dir($sessions_dir)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create staged apply sessions directory ' . $sessions_dir . '.');
            }
            $sessions_dir = self::require_directory($sessions_dir, 'staged apply sessions', false);
        }
        $creation_lock = @fopen($sessions_dir . '/create.lock', 'c+b');
        if ($creation_lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply creation lock.');
        }
        try {
            if (!flock($creation_lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session creation is busy. Retry the create request.');
            }
            if (file_exists($session->session_dir) || is_link($session->session_dir)) {
                return $session;
            }
            if (!@mkdir($session->files_dir, 0700, true) || !@mkdir($session->partial_dir, 0700, true)) {
                self::remove_tree($session->session_dir);
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create the staged apply workspace directories.');
            }
            if (@file_put_contents($session->lock_path, '') === false || @file_put_contents($session->deletes_path, '') === false) {
                self::remove_tree($session->session_dir);
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create staged apply session control files.');
            }
            try {
                $session->assert_same_filesystem($session->files_dir, $session->target_root, 'stage', '');
            } catch (Throwable $exception) {
                self::remove_tree($session->session_dir);
                throw $exception;
            }
            $session->write_json($session->session_metadata_path, [
                'version' => 2,
                'session_id' => $session_id,
                'target_root_b64' => base64_encode($target_root),
                'protected_paths_b64' => array_map('base64_encode', $session->protected_paths),
                'delete_upload_complete' => false,
            ]);
            return $session;
        } finally {
            flock($creation_lock, LOCK_UN);
            fclose($creation_lock);
        }
    }

    /**
     * Creates a handle for a session which will be validated when it is used.
     *
     * This method canonicalizes the configured roots but deliberately does not
     * inspect the session workspace. Upload, status, and commit acquire the
     * session lock and then validate its complete layout, immutable metadata,
     * and same-filesystem relationship exactly once for that operation.
     *
     * @param string $storage_dir Durable private session storage.
     * @param string $target_root Managed live directory.
     * @param string $session_id Lowercase hexadecimal session identity.
     * @param list<string> $protected_paths Target-relative paths which push must preserve.
     * @return self Session handle; the session may prove missing or invalid
     *              when its first operation acquires the lock.
     */
    public static function open(string $storage_dir, string $target_root, string $session_id, array $protected_paths): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        return new self($storage_dir, $target_root, $session_id, $protected_paths);
    }

    /**
     * Removes private session work without requiring the old target configuration.
     *
     * Discard validates the workspace under its session lock, but it does not
     * require current protected paths or the target root to match immutable
     * session metadata. That exception is intentional: operators must still be
     * able to remove abandoned private work after configuration changes.
     *
     * @param string $storage_dir Durable private session storage.
     * @param string $target_root Currently configured managed live directory.
     * @param string $session_id Lowercase hexadecimal session identity.
     * @param list<string> $protected_paths Currently configured protected paths.
     * @return bool True when the workspace and any discard tombstone are gone.
     */
    public static function discard(string $storage_dir, string $target_root, string $session_id, array $protected_paths): bool {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        return ( new self($storage_dir, $target_root, $session_id, $protected_paths) )->discard_workspace();
    }

    /**
     * Returns the immutable identity assigned to this staged apply session.
     *
     * The session id is the caller-provided lowercase hexadecimal token used
     * in upload, status, commit, and discard endpoints. It is not re-read from
     * disk here; operations that depend on durable state validate the matching
     * metadata while holding the session lock.
     *
     * @return string Session id used in public protocol responses and paths.
     */
    public function get_session_id(): string {
        return $this->session_id;
    }

    /**
     * Returns the private workspace directory derived for this session.
     *
     * This is an implementation path under the configured staged-apply storage
     * directory. The method is used by tests and endpoint code that need to
     * inspect or remove the private workspace; it does not imply that the
     * directory currently exists or has passed layout validation.
     *
     * @return string Absolute path to the session's private directory.
     */
    public function get_session_directory(): string {
        return $this->session_dir;
    }

    /**
     * Opens one caller-driven multipart request without reading its body.
     *
     * The session lock remains held until finish_upload() is called, so no
     * status, commit, discard, or second upload can observe a partly processed
     * MIME part. The supplied processor owns the request boundary and parser
     * state. The byte limit applies to each part's declared Content-Length,
     * not to the complete HTTP request.
     *
     * A session which has started commit is closed to further uploads. This
     * method validates that condition before any bytes are read from $input.
     *
     * @param resource $input Blocking stream containing one multipart request.
     * @param Site_Export_Multipart_Processor $processor Parser configured with
     *                                                   the request boundary.
     * @param int $maximum_part_bytes Largest Content-Length accepted for one part.
     *
     * @throws LogicException If another upload is already open on this object.
     * @throws InvalidArgumentException If the stream or part limit is invalid.
     * @throws Site_Export_Staged_Apply_Exception If the session is busy,
     *     malformed, unavailable, or already committing.
     */
    public function accept_upload($input, Site_Export_Multipart_Processor $processor, int $maximum_part_bytes = PHP_INT_MAX): void {
        if ($this->upload_lock !== null) {
            throw new LogicException('A staged apply upload is already open; call finish_upload() first.');
        }
        if (!is_resource($input)) {
            throw new InvalidArgumentException('Staged apply multipart input must be a readable stream resource; received ' . gettype($input) . '.');
        }
        if ($maximum_part_bytes <= 0) {
            throw new InvalidArgumentException('Multipart part byte limit must be greater than zero.');
        }
        $lock = $this->acquire_session_lock();
        try {
            $this->assert_session_configuration();
            if (is_file($this->commit_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_COMMIT_REQUIRED, 'Uploads are closed because this staged apply session is committing.');
            }
            $this->upload_lock = $lock;
            $this->upload_input = $input;
            $this->upload_processor = $processor;
            $this->current_upload_part_ended = false;
            $this->current_change = null;
            $this->maximum_upload_part_bytes = $maximum_part_bytes;
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
    }

    /**
     * Reads and records the next change from the active multipart upload.
     *
     * Each MIME part describes one file chunk, directory, symlink, or segment
     * of the raw deletion stream. File bodies pass through the multipart
     * processor in bounded pieces instead of being collected in memory. One
     * call interprets exactly one complete part and does not begin interpreting
     * the following part before returning.
     *
     * Returning true means the complete part has been accepted into the target
     * workspace and get_current_change() describes the resulting target state.
     * A file part may leave that file partial, so true does not mean the logical
     * file or the complete multipart request is finished.
     *
     * Returning false means the closing multipart boundary was consumed. EOF
     * in a header, body, or boundary throws instead, so truncation is never
     * reported as normal completion.
     *
     * accept_upload() must be called first. The caller must eventually call
     * finish_upload(), including after an exception, to release the session
     * lock and clear the request state.
     *
     * @return bool True when one complete part was accepted, false after the
     *              multipart request closed cleanly.
     *
     * @throws LogicException If no upload is active or parser state is inconsistent.
     * @throws InvalidArgumentException If the part violates the push protocol.
     * @throws RuntimeException If the request is truncated or the target
     *     cannot record the part.
     */
    public function next_change(): bool {
        if ($this->upload_lock === null || $this->upload_input === null || $this->upload_processor === null) {
            throw new LogicException('Accept an upload before reading changes.');
        }
        $this->current_change = null;
        $this->current_upload_part_ended = false;
        try {
            if (!$this->next_upload_token()) {
                return false;
            }
            if ($this->upload_processor->get_token_type() !== Site_Export_Multipart_Processor::TOKEN_PART_START) {
                throw new LogicException('Expected a multipart part-start token before the next change.');
            }
            $headers = $this->upload_processor->get_current_headers();
            $part_bytes = $this->require_non_negative_header($headers, 'content-length');
            if ($part_bytes > $this->maximum_upload_part_bytes) {
                throw new InvalidArgumentException('Multipart part Content-Length ' . $part_bytes . ' exceeds the target maximum of ' . $this->maximum_upload_part_bytes . ' bytes.');
            }
            $type = $headers['x-chunk-type'] ?? null;
            if (!is_string($type) || !in_array($type, ['file', 'directory', 'symlink', 'delete-list'], true)) {
                throw new InvalidArgumentException('Multipart X-Chunk-Type must be file, directory, symlink, or delete-list; observed ' . json_encode($type) . '.');
            }
            if ($type === 'file') {
                $this->stage_file_part($headers, $part_bytes);
            } elseif ($type === 'directory') {
                $this->stage_directory_part($headers, $part_bytes);
            } elseif ($type === 'symlink') {
                $this->stage_symlink_part($headers, $part_bytes);
            } else {
                $this->stage_delete_list_part($headers, $part_bytes);
            }
            $unread = $this->read_current_upload_body_piece();
            if ($unread !== null) {
                throw new LogicException('The multipart part handler left ' . strlen($unread) . ' body bytes unread.');
            }
            return true;
        } catch (Throwable $exception) {
            $this->upload_input = null;
            $this->upload_processor = null;
            $this->current_change = null;
            throw $exception;
        }
    }

    /**
     * Closes the active upload and releases its session lock.
     *
     * This method does not drain or validate the remainder of the multipart
     * request. A caller may therefore stop after any complete part when a
     * request budget is exhausted; a later request resumes from workspace
     * state. It must also be called after next_change() throws.
     *
     * @throws LogicException If no upload is active.
     */
    public function finish_upload(): void {
        if ($this->upload_lock === null) {
            throw new LogicException('No staged apply upload is open; call accept_upload() first.');
        }
        $lock = $this->upload_lock;
        $this->upload_lock = null;
        $this->upload_input = null;
        $this->upload_processor = null;
        $this->current_upload_part_ended = false;
        $this->current_change = null;
        $this->maximum_upload_part_bytes = PHP_INT_MAX;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Returns the target state published by the latest accepted MIME part.
     *
     * The value is meaningful only after next_change() returns true. Calling
     * next_change() again clears the previous value before processing, and
     * finish_upload() clears it when the request closes.
     *
     * @return array{state:string,type:string,accepted_bytes:int,path_b64?:string}|null
     *     Accepted type, state, byte cursor, and path when applicable, or null
     *     when no result is current.
     */
    public function get_current_change(): ?array {
        return $this->current_change;
    }

    /**
     * Reports target-confirmed session progress and selected path cursors.
     *
     * Senders use this snapshot after a lost response or process restart. It
     * derives every cursor from the target workspace rather than echoing a
     * sender's claimed offset. Calling it without a path returns only session
     * progress; it never enumerates the complete positive-work tree.
     *
     * The optional path is the one in-flight file whose upload response was
     * lost. Delete-list resume does not need a path; use delete_bytes from the
     * session-level result. The path status is encoded as path_b64 so arbitrary
     * filesystem bytes remain representable. It is reported as one of:
     *
     *  - missing, with an accepted_bytes cursor of zero;
     *  - partial, with the regular file's actual stored byte size; or
     *  - complete, with its file, directory, or symlink type and a file-size
     *    cursor where applicable.
     *
     * The session-level result contains the session id, the current uploading,
     * deleting, applying, or complete phase, the actual delete-stream byte
     * size, whether its completion was explicitly declared, and a path status
     * when a path was requested. The complete snapshot is read while holding
     * the session lock.
     *
     * @param string|null $path Raw target-relative path byte string to inspect.
     * @return array{
     *     session_id:string,
     *     phase:string,
     *     delete_bytes:int,
     *     delete_upload_complete:bool,
     *     path:array{path_b64:string,state:string,accepted_bytes:int,type?:string}|null
     * } Target-confirmed session and optional path progress.
     *
     * @throws InvalidArgumentException If the requested path is unsafe.
     * @throws Site_Export_Staged_Apply_Exception If the session is busy,
     *     unavailable, corrupt, or no longer matches the target configuration.
     */
    public function get_status(?string $path = null): array {
        return $this->with_session_lock(function () use ($path): array {
            $reported_path = null;
            if ($path !== null) {
                $this->validate_path($path);
                $partial = $this->partial_dir . '/' . $path;
                $complete = $this->files_dir . '/' . $path;
                $this->ensure_private_parent($partial, false);
                $this->ensure_private_parent($complete, false);
                $complete_identity = $this->lstat_path($complete);
                if ($complete_identity !== null) {
                    $reported_path = [
                        'path_b64' => base64_encode($path),
                        'state' => 'complete',
                        'type' => $complete_identity['type'],
                        'accepted_bytes' => $complete_identity['type'] === 'file' ? $complete_identity['size'] : 0,
                    ];
                } else {
                    $partial_identity = $this->lstat_path($partial);
                    if ($partial_identity !== null) {
                        if ($partial_identity['type'] !== 'file') {
                            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Partial staging path ' . base64_encode($path) . ' is not a regular file.');
                        }
                        $reported_path = [
                            'path_b64' => base64_encode($path),
                            'state' => 'partial',
                            'type' => 'file',
                            'accepted_bytes' => $partial_identity['size'],
                        ];
                    } else {
                        $reported_path = ['path_b64' => base64_encode($path), 'state' => 'missing', 'accepted_bytes' => 0];
                    }
                }
            }
            $commit = $this->read_json($this->commit_path);
            if (is_array($commit)) {
                $this->require_valid_commit_state($commit);
            }
            return [
                'session_id' => $this->session_id,
                'phase' => is_array($commit) ? $commit['phase'] : 'uploading',
                'delete_bytes' => $this->file_size($this->deletes_path),
                'delete_upload_complete' => $this->delete_upload_is_complete(),
                'path' => $reported_path,
            ];
        });
    }

    /**
     * Advances a bounded amount of live-tree mutation for this session.
     *
     * Commit starts only after the delete upload has been explicitly closed and
     * every staged file is complete. The first call creates a durable checkpoint
     * and claims the target so no other session can mutate the same live tree.
     * Subsequent calls resume from that checkpoint, refresh the WordPress
     * maintenance marker, and perform at most $maximum_entries units of delete or
     * install work before returning.
     *
     * Live-tree drift and cross-device destinations are terminal for the
     * session: the failure is written into the commit checkpoint and replayed on
     * later calls. Retryable I/O failures are not terminal, so a later call can
     * retry the same bounded step from the durable state.
     *
     * @param int $maximum_entries Maximum bounded commit entries to process in this call.
     * @return array{phase:string,send_next_request:bool,entries_processed:int}
     *     Current phase, whether another request is needed, and entries
     *     processed by this call.
     */
    public function commit(int $maximum_entries = 1): array {
        if ($maximum_entries <= 0) {
            throw new InvalidArgumentException('The staged apply commit entry limit must be greater than zero.');
        }
        return $this->with_session_lock(function () use ($maximum_entries): array {
            $state = $this->read_json($this->commit_path);
            if ($state === null) {
                if (!$this->delete_upload_is_complete()) {
                    throw new InvalidArgumentException('Commit requires an explicit completed delete upload declaration.');
                }
                $delete_bytes = $this->file_size($this->deletes_path);
                if ($delete_bytes > 0) {
                    $handle = @fopen($this->deletes_path, 'rb');
                    if ($handle === false || fseek($handle, -1, SEEK_END) !== 0) {
                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not inspect the final staged delete byte.');
                    }
                    $last_byte = fread($handle, 1);
                    fclose($handle);
                    if ($last_byte !== "\0") {
                        throw new InvalidArgumentException('A nonempty delete stream must end in NUL before commit; the final record is unterminated.');
                    }
                }
                if ($this->first_tree_entry($this->partial_dir) !== null) {
                    throw new InvalidArgumentException('Commit cannot begin while work/partial still contains an incomplete file.');
                }
                $state = [
                    'version' => 2,
                    'phase' => 'deleting',
                    'delete_offset' => 0,
                    'current_deletion_b64' => null,
                    'current_installation' => null,
                    'traversal_stack' => [],
                    'maintenance_token' => bin2hex(random_bytes(16)),
                    'deletions_applied' => 0,
                    'values_applied' => 0,
                ];
                $this->write_json($this->commit_path, $state);
            } else {
                $this->require_valid_commit_state($state);
            }
            if (isset($state['terminal_error'])) {
                throw new Site_Export_Staged_Apply_Exception(
                    $state['terminal_error']['reason'],
                    $state['terminal_error']['detail'],
                    $state['terminal_error']['context']
                );
            }
            if ($state['phase'] === 'complete') {
                return [
                    'phase' => $state['phase'],
                    'send_next_request' => false,
                    'entries_processed' => 0,
                ];
            }
            $this->with_target_lock(function (): void {
                $active_path = $this->storage_dir . '/apply-sessions/target.active';
                $active = @file_get_contents($active_path);
                if (is_string($active) && trim($active) !== '' && trim($active) !== $this->session_id) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Another staged apply session is already committing this target: ' . trim($active) . '.');
                }
                $this->write_atomic_file($active_path, $this->session_id . "\n", 0600);
            });
            $maintenance_token = $state['maintenance_token'];
            $maintenance_live_path = $this->target_path('.maintenance');
            $maintenance_identity = $this->lstat_path($maintenance_live_path);
            if ($maintenance_identity !== null && !$this->maintenance_marker_is_owned($maintenance_live_path, $maintenance_token)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'A foreign WordPress maintenance marker already exists. Retry after its owner removes it.');
            }
            $maintenance_contents = "<?php\n"
                . "\$reprint_staged_apply_request = (isset(\$_GET['reprint-api']) || isset(\$_GET['site-export-api']))\n"
                . "    && isset(\$_GET['endpoint']) && is_string(\$_GET['endpoint'])\n"
                . "    && strpos(\$_GET['endpoint'], 'staged_session_') === 0;\n"
                . "if (!\$reprint_staged_apply_request) {\n"
                . "    \$upgrading = " . time() . ";\n"
                . "}\n"
                . "unset(\$reprint_staged_apply_request);\n"
                . "// reprint-staged-session:" . $this->session_id . ':' . $maintenance_token . "\n";
            $this->write_atomic_file($this->maintenance_identity_path, $maintenance_contents, 0600);
            $this->write_atomic_file($maintenance_live_path, $maintenance_contents, 0644);
            try {
                for ($entries_processed = 0; $entries_processed < $maximum_entries && $state['phase'] !== 'complete'; ++$entries_processed) {
                    if ($state['phase'] === 'deleting') {
                        $this->advance_deletion($state);
                    } else {
                        $this->advance_installation($state);
                    }
                }
            } catch (Site_Export_Staged_Apply_Exception $exception) {
                if (in_array($exception->get_error_code(), [self::ERROR_LIVE_TREE_CHANGED, self::ERROR_CROSS_DEVICE_FILESYSTEM], true)) {
                    $state['terminal_error'] = [
                        'reason' => $exception->get_error_code(),
                        'detail' => $exception->getMessage(),
                        'context' => $exception->get_context(),
                    ];
                    $this->write_json($this->commit_path, $state);
                }
                throw $exception;
            }
            return [
                'phase' => $state['phase'],
                'send_next_request' => $state['phase'] !== 'complete',
                'entries_processed' => $entries_processed,
            ];
        });
    }

    /**
     * Advances bounded cleanup of an upload-only or completed workspace.
     *
     * A session which has begun an incomplete commit remains recovery state and
     * cannot be discarded. An eligible session is atomically renamed to a
     * private tombstone before entries are removed, so a lost response or later
     * request resumes cleanup without making the old session addressable again.
     *
     * @return bool True when cleanup is complete, false when the bounded entry
     *              limit left tombstone work for another call.
     */
    public function discard_workspace(): bool {
        $discarding_session_dir = $this->storage_dir . '/apply-sessions/.discarding-' . $this->session_id;
        if ($this->lstat_path($this->session_dir) === null) {
            return $this->discard_tombstone($discarding_session_dir);
        }
        $lock = $this->acquire_session_lock();
        try {
            $state = $this->read_json($this->commit_path);
            if (is_array($state)) {
                $this->require_valid_commit_state($state);
                if ($state['phase'] !== 'complete') {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_COMMIT_REQUIRED, 'Live mutation has begun. Resume commit instead of discarding this session.');
                }
            }
            if (file_exists($discarding_session_dir) || is_link($discarding_session_dir)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'A discard tombstone already exists for staged apply session ' . $this->session_id . '.');
            }
            if (!@rename($this->session_dir, $discarding_session_dir)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not publish the staged apply discard tombstone.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->release_target();
        return $this->discard_tombstone($discarding_session_dir);
    }

    /**
     * Returns the next body fragment for the current multipart part.
     *
     * The multipart processor may expose a body in several bounded fragments,
     * followed by a PART_END token. This method hides that token transition
     * from the part-specific staging code: a string means bytes still belong to
     * the current part, and null means the declared Content-Length has been
     * satisfied. It never reads into the next part.
     *
     * @return string|null Current body bytes, or null after the part end.
     */
    private function read_current_upload_body_piece(): ?string {
        if ($this->current_upload_part_ended) {
            return null;
        }
        if (!$this->next_upload_token()) {
            throw new LogicException('Multipart input closed before the current part-end token.');
        }
        $type = $this->upload_processor->get_token_type();
        if ($type === Site_Export_Multipart_Processor::TOKEN_BODY) {
            return $this->upload_processor->get_current_body_piece();
        }
        if ($type === Site_Export_Multipart_Processor::TOKEN_PART_END) {
            $this->current_upload_part_ended = true;
            return null;
        }
        throw new LogicException('Expected multipart body or part-end; received ' . json_encode($type) . '.');
    }

    /**
     * Advances the multipart processor, feeding it bounded request bytes.
     *
     * The processor is drained before each new fread(), so this method
     * preserves the streaming contract: at most one request fragment and one
     * exposed token are live at a time. Clean completion returns false; a
     * truncated request is reported by finish_input().
     *
     * @return bool True when a processor token is current, false after close.
     */
    private function next_upload_token(): bool {
        while (!$this->upload_processor->next_token()) {
            if ($this->upload_processor->is_complete()) {
                return false;
            }
            if (!$this->upload_processor->paused_at_incomplete_input()) {
                throw new LogicException('Multipart processor stopped without completing or requesting input.');
            }
            $bytes = fread($this->upload_input, Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES);
            if ($bytes === false) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read the multipart upload request body.');
            }
            if ($bytes === '') {
                $this->upload_processor->finish_input();
                return false;
            }
            $this->upload_processor->append_bytes($bytes);
        }
        return true;
    }

    /**
     * Accepts one file MIME part into work/partial or work/files.
     *
     * The caller has already validated Content-Length against the target's part
     * ceiling. This method validates the file-specific headers, enforces the
     * target-confirmed resume offset, streams the body into the partial file,
     * and promotes the file atomically inside private storage only when the
     * declared total size has been reached.
     *
     * @param array{content-length:string,content-type?:string,x-chunk-type:string,x-file-path:string,x-file-size:string,x-chunk-offset:string} $headers
     *     Normalized file part headers.
     * @param int $part_bytes Declared Content-Length for this file chunk.
     */
    private function stage_file_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-file-path', 'x-file-size', 'x-chunk-offset'], 'file');
        $path = $this->decode_path_header($headers, 'x-file-path');
        $total_bytes = $this->require_non_negative_header($headers, 'x-file-size');
        $offset = $this->require_non_negative_header($headers, 'x-chunk-offset');
        if ($offset > $total_bytes || $part_bytes > $total_bytes - $offset) {
            throw new InvalidArgumentException('File part for ' . base64_encode($path) . ' exceeds its declared total of ' . $total_bytes . ' bytes.');
        }
        $partial_path = $this->partial_dir . '/' . $path;
        $complete_path = $this->files_dir . '/' . $path;
        $this->ensure_private_parent($partial_path);
        $this->ensure_private_parent($complete_path);

        $complete = $this->lstat_path($complete_path);
        if ($complete !== null) {
            if ($offset !== 0 && $complete['type'] === 'file' && $complete['size'] === $total_bytes && $offset === $total_bytes && $part_bytes === 0) {
                if ($this->read_current_upload_body_piece() !== null) {
                    throw new LogicException('Multipart processor exposed file bytes for an empty completed-file replay.');
                }
                $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'file', 'accepted_bytes' => $total_bytes];
                return;
            }
            if ($offset !== 0) {
                throw new Site_Export_Staged_Apply_Exception(
                    self::ERROR_OFFSET_GAP,
                    'Completed staged file ' . base64_encode($path) . ' can only be restarted at offset 0.'
                );
            }
            if ($complete['type'] === 'directory' && $this->first_directory_entry($complete_path) !== null) {
                throw new InvalidArgumentException('Staged file ' . base64_encode($path) . ' conflicts with staged descendants.');
            }
        }

        $partial = $this->lstat_path($partial_path);
        if ($partial !== null && $partial['type'] === 'directory' && $this->first_directory_entry($partial_path) !== null) {
            throw new InvalidArgumentException('Staged file ' . base64_encode($path) . ' conflicts with partial descendants.');
        }
        if ($partial !== null && !in_array($partial['type'], ['file', 'directory'], true)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The partial path for ' . base64_encode($path) . ' is a ' . $partial['type'] . ', not a regular file.');
        }
        if ($complete !== null) {
            $this->remove_private_entry($complete_path);
        }
        if ($partial !== null && $partial['type'] === 'directory') {
            $this->remove_private_entry($partial_path);
            $partial = null;
        }
        $actual_bytes = $partial === null ? 0 : $partial['size'];
        if ($offset === 0 && $actual_bytes !== 0) {
            if (!@unlink($partial_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not restart partial file ' . base64_encode($path) . ' at offset 0.');
            }
            $actual_bytes = 0;
        } elseif ($offset !== $actual_bytes) {
            throw new Site_Export_Staged_Apply_Exception(
                self::ERROR_OFFSET_GAP,
                'File part for ' . base64_encode($path) . ' starts at offset ' . $offset . ', but work/partial contains ' . $actual_bytes . ' bytes. Start at offset 0 or resume at the actual size.'
            );
        }

        $handle = @fopen($partial_path, $actual_bytes === 0 ? 'wb' : 'ab');
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open partial file ' . base64_encode($path) . ' for staging.');
        }
        $received = 0;
        try {
            while (true) {
                $piece = $this->read_current_upload_body_piece();
                if ($piece === null) {
                    break;
                }
                $received += strlen($piece);
                $this->write_all($handle, $piece, 'partial file ' . base64_encode($path));
            }
            if (!fflush($handle)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not flush partial file ' . base64_encode($path) . '.');
            }
        } finally {
            fclose($handle);
        }
        $accepted_bytes = $actual_bytes + $received;
        if ($accepted_bytes === $total_bytes) {
            if (!@rename($partial_path, $complete_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not promote completed staged file ' . base64_encode($path) . '.');
            }
            $state = 'complete';
        } else {
            $state = 'partial';
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => $state, 'type' => 'file', 'accepted_bytes' => $accepted_bytes];
    }

    /**
     * Accepts one explicit empty-directory MIME part.
     *
     * Directory parts have no body. They create or refresh an empty directory in
     * the completed staging tree, but they reject conflicts with already staged
     * descendants because a single staged path cannot be both a leaf value and a
     * structural parent.
     *
     * @param array{content-length:string,content-type?:string,x-chunk-type:string,x-directory-path:string} $headers
     *     Normalized directory part headers.
     * @param int $part_bytes Declared Content-Length, which must be zero.
     */
    private function stage_directory_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-directory-path'], 'directory');
        if ($part_bytes !== 0 || $this->read_current_upload_body_piece() !== null) {
            throw new InvalidArgumentException('Multipart directory part must have Content-Length 0.');
        }
        $path = $this->decode_path_header($headers, 'x-directory-path');
        $target = $this->files_dir . '/' . $path;
        $partial = $this->partial_dir . '/' . $path;
        $this->ensure_private_parent($target);
        $this->ensure_private_parent($partial, false);
        $identity = $this->lstat_path($target);
        $partial_identity = $this->lstat_path($partial);
        if ($identity !== null && $identity['type'] === 'directory' && $this->first_directory_entry($target) !== null) {
            throw new InvalidArgumentException('Explicit empty directory ' . base64_encode($path) . ' conflicts with staged descendants.');
        }
        if ($partial_identity !== null && $partial_identity['type'] === 'directory' && $this->first_directory_entry($partial) !== null) {
            throw new InvalidArgumentException('Explicit empty directory ' . base64_encode($path) . ' conflicts with partial descendants.');
        }
        if ($identity !== null && $identity['type'] !== 'directory') {
            $this->remove_private_entry($target);
        }
        if ($partial_identity !== null) {
            $this->remove_private_entry($partial);
        }
        if (!is_dir($target) && !@mkdir($target, 0777)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not stage explicit empty directory ' . base64_encode($path) . '.');
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0];
    }

    /**
     * Accepts one symlink MIME part.
     *
     * Symlink parts carry their target in a base64 header and have an empty
     * body. The staged value replaces any previous leaf at the same private path
     * and rejects directory conflicts that would orphan already staged children.
     *
     * @param array{content-length:string,content-type?:string,x-chunk-type:string,x-symlink-path:string,x-symlink-target:string} $headers
     *     Normalized symlink part headers.
     * @param int $part_bytes Declared Content-Length, which must be zero.
     */
    private function stage_symlink_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-symlink-path', 'x-symlink-target'], 'symlink');
        if ($part_bytes !== 0 || $this->read_current_upload_body_piece() !== null) {
            throw new InvalidArgumentException('Multipart symlink part must have Content-Length 0.');
        }
        $path = $this->decode_path_header($headers, 'x-symlink-path');
        $target_value = $this->decode_path_header($headers, 'x-symlink-target', false);
        if ($target_value === '' || strlen($target_value) > self::MAX_PATH_BYTES || strpos($target_value, "\0") !== false) {
            throw new InvalidArgumentException('Symlink target must contain between 1 and ' . self::MAX_PATH_BYTES . ' bytes without NUL.');
        }
        $target = $this->files_dir . '/' . $path;
        $partial = $this->partial_dir . '/' . $path;
        $this->ensure_private_parent($target);
        $this->ensure_private_parent($partial, false);
        $identity = $this->lstat_path($target);
        $partial_identity = $this->lstat_path($partial);
        if ($identity !== null && $identity['type'] === 'directory' && $this->first_directory_entry($target) !== null) {
            throw new InvalidArgumentException('Staged symlink ' . base64_encode($path) . ' conflicts with staged descendants.');
        }
        if ($partial_identity !== null && $partial_identity['type'] === 'directory' && $this->first_directory_entry($partial) !== null) {
            throw new InvalidArgumentException('Staged symlink ' . base64_encode($path) . ' conflicts with partial descendants.');
        }
        if ($identity !== null) {
            $this->remove_private_entry($target);
        }
        if ($partial_identity !== null) {
            $this->remove_private_entry($partial);
        }
        if (!@symlink($target_value, $target)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not stage symlink ' . base64_encode($path) . '.');
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0];
    }

    /**
     * Accepts one segment of the raw NUL-delimited delete stream.
     *
     * The delete stream is append-only, but lost responses may cause callers to
     * replay bytes already stored by the target. Overlapping bytes must match
     * exactly; new bytes are validated record-by-record before they are flushed.
     * A completion declaration records that no more delete bytes may be added.
     *
     * @param array{content-length:string,content-type?:string,x-chunk-type:string,x-delete-offset:string,x-delete-complete?:string} $headers
     *     Normalized delete-list part headers.
     * @param int $part_bytes Declared Content-Length for this delete segment.
     */
    private function stage_delete_list_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-delete-offset', 'x-delete-complete'], 'delete-list');
        $offset = $this->require_non_negative_header($headers, 'x-delete-offset');
        $complete = ( $headers['x-delete-complete'] ?? null ) === '1';
        if (isset($headers['x-delete-complete']) && !$complete) {
            throw new InvalidArgumentException('Multipart X-Delete-Complete must be 1 when present.');
        }
        if ($this->delete_upload_is_complete() && ( !$complete || $offset !== $this->file_size($this->deletes_path) || $part_bytes !== 0 )) {
            throw new InvalidArgumentException('Delete upload is already complete; only its empty completion declaration may be replayed.');
        }
        $handle = @fopen($this->deletes_path, 'r+b');
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the raw staged delete stream.');
        }
        try {
            $delete_stat = fstat($handle);
            if (!is_array($delete_stat) || !isset($delete_stat['size'])) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not determine the actual size of staged delete stream.');
            }
            $stored_bytes = (int) $delete_stat['size'];
            if ($offset > $stored_bytes) {
                throw new Site_Export_Staged_Apply_Exception(
                    self::ERROR_OFFSET_GAP,
                    'Delete-list part starts at offset ' . $offset . ', but the target has stored ' . $stored_bytes . ' bytes.'
                );
            }
            $position = $offset;
            if ($stored_bytes === 0) {
                $trailing_path = '';
            } else {
                $suffix_bytes = min($stored_bytes, self::MAX_PATH_BYTES + 1);
                if (fseek($handle, $stored_bytes - $suffix_bytes) !== 0) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not inspect the staged delete-stream suffix.');
                }
                $suffix = $this->read_exact($handle, $suffix_bytes, 'staged delete-stream suffix');
                $last_nul = strrpos($suffix, "\0");
                $trailing_path = $last_nul === false ? $suffix : substr($suffix, $last_nul + 1);
                if ($last_nul === false && $stored_bytes > self::MAX_PATH_BYTES) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The incomplete staged delete path already exceeds ' . self::MAX_PATH_BYTES . ' bytes.');
                }
            }
            while (true) {
                $piece = $this->read_current_upload_body_piece();
                if ($piece === null) {
                    break;
                }
                $piece_offset = 0;
                $overlap = min(strlen($piece), max(0, $stored_bytes - $position));
                if ($overlap > 0) {
                    if (fseek($handle, $position) !== 0) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not seek within the staged delete stream for replay validation.');
                    }
                    $stored = $this->read_exact($handle, $overlap, 'staged delete replay');
                    if ($stored !== substr($piece, 0, $overlap)) {
                        throw new InvalidArgumentException('Delete-list replay differs from bytes already stored at offset ' . $position . '.');
                    }
                    $position += $overlap;
                    $piece_offset = $overlap;
                }
                if ($piece_offset < strlen($piece)) {
                    $append = substr($piece, $piece_offset);
                    $append_length = strlen($append);
                    for ($index = 0; $index < $append_length; ++$index) {
                        if ($append[$index] === "\0") {
                            if ($trailing_path === '') {
                                throw new InvalidArgumentException('Delete-list parts may not contain an empty deletion record.');
                            }
                            $this->validate_path($trailing_path);
                            $trailing_path = '';
                            continue;
                        }
                        $trailing_path .= $append[$index];
                        if (strlen($trailing_path) > self::MAX_PATH_BYTES) {
                            throw new InvalidArgumentException('Delete-list path exceeds the maximum of ' . self::MAX_PATH_BYTES . ' bytes.');
                        }
                    }
                    if (fseek($handle, 0, SEEK_END) !== 0) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not seek to the staged delete stream end.');
                    }
                    $this->write_all($handle, $append, 'staged delete stream');
                    $stored_bytes += strlen($append);
                    $position += strlen($append);
                    if (!fflush($handle)) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not flush the staged delete stream.');
                    }
                }
            }
            if ($complete && $position !== $stored_bytes) {
                throw new InvalidArgumentException('Delete completion must be declared at the actual stored size of ' . $stored_bytes . ' bytes.');
            }
        } finally {
            fclose($handle);
        }
        if ($complete) {
            $metadata = $this->read_json($this->session_metadata_path);
            if (!is_array($metadata)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata is missing while completing the delete upload.');
            }
            $metadata['delete_upload_complete'] = true;
            $this->write_json($this->session_metadata_path, $metadata);
        }
        $this->current_change = ['state' => $complete ? 'complete' : 'partial', 'type' => 'delete-list', 'accepted_bytes' => $stored_bytes];
    }

    /**
     * Performs one bounded deletion step from the durable commit checkpoint.
     *
     * The first call for a record copies the next NUL-delimited path from the
     * raw delete stream into `current_deletion_b64`. A later call removes at
     * most one leaf or empty directory beneath that root and advances the byte
     * cursor only after the live path is confirmed absent.
     *
     * @param array{
     *     phase:string,
     *     delete_offset:int,
     *     current_deletion_b64:?string,
     *     current_installation:?array{path_b64:string,expected_type:string},
     *     traversal_stack:list<array{component_b64:string}>,
     *     maintenance_token:string,
     *     deletions_applied:int,
     *     values_applied:int,
     *     terminal_error?:array{reason:string,detail:string,context:array<string,mixed>}
     * } $state Commit checkpoint, mutated in place.
     */
    private function advance_deletion(array &$state): void {
        if ($state['current_deletion_b64'] === null) {
            $delete_offset = (int) $state['delete_offset'];
            $delete_size = $this->file_size($this->deletes_path);
            if ($delete_offset === $delete_size) {
                $state['phase'] = 'applying';
                $this->write_json($this->commit_path, $state);
                return;
            }
            if ($delete_offset < 0 || $delete_offset > $delete_size) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Delete-consumption offset ' . $delete_offset . ' is outside the ' . $delete_size . '-byte stream.');
            }
            $handle = @fopen($this->deletes_path, 'rb');
            if ($handle === false || fseek($handle, $delete_offset) !== 0) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not seek to the confirmed delete-consumption offset.');
            }
            $path = '';
            $path_bytes = 0;
            try {
                while ($path_bytes <= self::MAX_PATH_BYTES) {
                    $byte = fread($handle, 1);
                    if ($byte === false) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read the staged delete stream.');
                    }
                    if ($byte === '') {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The staged delete stream ended before its NUL record terminator.');
                    }
                    if ($byte === "\0") {
                        if ($path === '') {
                            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The staged delete stream contains an empty record at offset ' . $delete_offset . '.');
                        }
                        $this->validate_path($path);
                        $state['current_deletion_b64'] = base64_encode($path);
                        $this->write_json($this->commit_path, $state);
                        return;
                    }
                    $path .= $byte;
                    ++$path_bytes;
                }
            } finally {
                fclose($handle);
            }
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'A staged delete path exceeds ' . self::MAX_PATH_BYTES . ' bytes.');
        }

        $path = $this->decode_commit_path($state['current_deletion_b64'], 'current deletion');
        $this->validate_path($path);
        $parent_device = $this->assert_live_ancestors($path, 'delete');
        if ($parent_device !== null) {
            $target = $this->target_path($path);
            $identity = $this->lstat_path($target);
            if ($identity !== null) {
                if (!in_array($identity['type'], ['file', 'directory', 'symlink'], true)) {
                    $this->throw_live_tree_changed('delete', $path, $path, null, ['absent', 'file', 'directory', 'symlink'], $identity);
                }
                if ($identity['dev'] !== $parent_device) {
                    $this->throw_cross_device('delete', $path, $this->staging_device(), $identity['dev']);
                }
                $this->delete_one_entry($target, $path, $path, $parent_device);
            }
            if ($this->lstat_path($target) !== null) {
                return;
            }
        }
        $state['delete_offset'] += strlen($path) + 1;
        $state['current_deletion_b64'] = null;
        ++$state['deletions_applied'];
        $this->write_json($this->commit_path, $state);
    }

    /**
     * Removes at most one leaf or empty directory below one planned root.
     *
     * Directories are drained depth-first so each commit step is bounded and
     * recoverable. The requested root is kept separate from the recursive
     * relative path so drift responses can name both the user-requested delete
     * and the nested path that actually conflicted.
     *
     * @param string $absolute_path Current live filesystem path to inspect.
     * @param string $relative_path Target-relative path matching $absolute_path.
     * @param string $requested_path Original delete root used in conflicts.
     * @param int $parent_device Device id expected for the current entry.
     */
    private function delete_one_entry(string $absolute_path, string $relative_path, string $requested_path, int $parent_device): void {
        $identity = $this->lstat_path($absolute_path);
        if ($identity === null) {
            return;
        }
        if ($identity['dev'] !== $parent_device) {
            $this->throw_cross_device('delete', $relative_path, $this->staging_device(), $identity['dev']);
        }
        if ($identity['type'] === 'file' || $identity['type'] === 'symlink') {
            if (!@unlink($absolute_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove live ' . $identity['type'] . ' ' . base64_encode($relative_path) . '.');
            }
            return;
        }
        if ($identity['type'] !== 'directory') {
            $this->throw_live_tree_changed('delete', $requested_path, $relative_path, null, ['file', 'directory', 'symlink'], $identity);
        }
        $entry = $this->first_directory_entry($absolute_path);
        if ($entry === null) {
            if (!@rmdir($absolute_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove empty live directory ' . base64_encode($relative_path) . '.');
            }
            return;
        }
        $child_relative = $relative_path . '/' . $entry;
        $this->delete_one_entry($absolute_path . '/' . $entry, $child_relative, $requested_path, $identity['dev']);
    }

    /**
     * Performs one bounded installation or traversal step.
     *
     * The completed staging tree is its own queue. This method walks it
     * depth-first, creating structural live directories before their children,
     * installing one leaf value per step, and consuming empty structural staging
     * directories after their descendants have been applied.
     *
     * @param array{
     *     phase:string,
     *     delete_offset:int,
     *     current_deletion_b64:?string,
     *     current_installation:?array{path_b64:string,expected_type:string},
     *     traversal_stack:list<array{component_b64:string}>,
     *     maintenance_token:string,
     *     deletions_applied:int,
     *     values_applied:int,
     *     terminal_error?:array{reason:string,detail:string,context:array<string,mixed>}
     * } $state Commit checkpoint, mutated in place.
     */
    private function advance_installation(array &$state): void {
        if ($state['current_installation'] !== null) {
            /*
             * A checkpoint may survive either side of a rename or structural
             * cleanup. The staged value may still be present and need retrying,
             * or it may already be consumed and require verification in the
             * live tree. Resolve that evidence before selecting any new work.
             */
            $installation = $state['current_installation'];
            $path = $this->decode_commit_path($installation['path_b64'], 'current installation');
            $expected_type = $installation['expected_type'];
            $stack_size = count($state['traversal_stack']);
            $structural_cleanup = false;
            if ($stack_size > 0) {
                $structural_cleanup = $this->traversal_path($state['traversal_stack']) === $path;
            }
            $staged_path = $this->files_dir . '/' . $path;
            $staged = $this->lstat_path($staged_path);

            if ($structural_cleanup) {
                $this->assert_live_ancestors($path, 'install', 'directory');
                $live = $this->lstat_path($this->target_path($path));
                if ($staged !== null) {
                    if ($staged['type'] !== 'directory' || $this->first_directory_entry($staged_path) !== null) {
                        $this->throw_live_tree_changed('install', $path, $path, 'directory', ['directory'], $live);
                    }
                    if ($live === null || $live['type'] !== 'directory') {
                        $this->throw_live_tree_changed('install', $path, $path, 'directory', ['directory'], $live);
                    }
                    if (!@rmdir($staged_path)) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not finish structural staging cleanup for ' . base64_encode($path) . '.');
                    }
                } elseif ($live === null || $live['type'] !== 'directory') {
                    $this->throw_live_tree_changed('install', $path, $path, 'directory', ['directory'], $live);
                }
                $state['current_installation'] = null;
                array_pop($state['traversal_stack']);
                ++$state['values_applied'];
                $this->write_json($this->commit_path, $state);
                return;
            }

            if ($staged !== null) {
                $this->install_staged_value($state, $path, $expected_type, true);
                return;
            }
            $this->assert_live_ancestors($path, 'install', $expected_type);
            $live = $this->lstat_path($this->target_path($path));
            if ($live === null || $live['type'] !== $expected_type) {
                $this->throw_live_tree_changed('install', $path, $path, $expected_type, [$expected_type], $live);
            }
            $state['current_installation'] = null;
            ++$state['values_applied'];
            $this->write_json($this->commit_path, $state);

            return;
        }

        $stack_size = count($state['traversal_stack']);
        if ($stack_size === 0) {
            $parent_path = '';
            $staged_directory = $this->files_dir;
        } else {
            $parent_path = $this->traversal_path($state['traversal_stack']);
            $staged_directory = $this->files_dir . '/' . $parent_path;
        }
        $entry = $this->first_directory_entry($staged_directory);
        if ($entry === null) {
            if ($stack_size === 0) {
                if ($state['current_deletion_b64'] !== null || $state['traversal_stack'] !== []) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion with active bounded work state.');
                }
                if ( (int) $state['delete_offset'] !== $this->file_size($this->deletes_path)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion before consuming the complete delete stream.');
                }
                if ($this->first_directory_entry($this->files_dir) !== null) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion while work/files still contains pending values.');
                }
                $maintenance_live_path = $this->target_path('.maintenance');
                $maintenance_identity = $this->lstat_path($maintenance_live_path);
                if ($maintenance_identity !== null) {
                    if (!$this->maintenance_marker_is_owned($maintenance_live_path, $state['maintenance_token'])) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'The session-owned maintenance marker was replaced by another owner.');
                    }
                    if (!@unlink($maintenance_live_path)) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove the session-owned WordPress maintenance marker.');
                    }
                }
                if ($this->lstat_path($this->maintenance_identity_path) !== null && !@unlink($this->maintenance_identity_path)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove the private maintenance ownership marker.');
                }
                $state['phase'] = 'complete';
                $this->write_json($this->commit_path, $state);
                $this->release_target();
                return;
            }
            $this->assert_live_ancestors($parent_path, 'install', 'directory');
            $live = $this->lstat_path($this->target_path($parent_path));
            if ($live === null || $live['type'] !== 'directory') {
                $this->throw_live_tree_changed('install', $parent_path, $parent_path, 'directory', ['directory'], $live);
            }
            $state['current_installation'] = ['path_b64' => base64_encode($parent_path), 'expected_type' => 'directory'];
            $this->write_json($this->commit_path, $state);
            if (!@rmdir($staged_directory)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not consume empty structural staging directory ' . base64_encode($parent_path) . '.');
            }
            $state['current_installation'] = null;
            array_pop($state['traversal_stack']);
            ++$state['values_applied'];
            $this->write_json($this->commit_path, $state);
            return;
        }

        $path = $parent_path === '' ? $entry : $parent_path . '/' . $entry;
        $this->validate_path($path);
        $staged_path = $this->files_dir . '/' . $path;
        $identity = $this->lstat_path($staged_path);
        if ($identity === null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Selected staged path disappeared before installation: ' . base64_encode($path) . '.');
        }
        if ($identity['type'] === 'directory' && $this->first_directory_entry($staged_path) !== null) {
            $state['traversal_stack'][] = ['component_b64' => base64_encode($entry)];
            $this->write_json($this->commit_path, $state);
            $requested_path = $this->first_staged_leaf_path($staged_path, $path);
            $parent_device = $this->assert_live_ancestors($path, 'install', 'directory');
            $live_path = $this->target_path($path);
            $live = $this->lstat_path($live_path);
            if ($live === null) {
                if (!@mkdir($live_path, 0777) && !is_dir($live_path)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create structural live directory ' . base64_encode($path) . '.');
                }
                $live = $this->lstat_path($live_path);
            }
            if ($live === null || $live['type'] !== 'directory') {
                $this->throw_live_tree_changed('install', $requested_path, $path, 'directory', ['absent', 'directory'], $live);
            }
            if ($live['dev'] !== $parent_device || $live['dev'] !== $this->staging_device()) {
                $this->throw_cross_device('install', $path, $this->staging_device(), $live['dev']);
            }
            return;
        }
        if (!in_array($identity['type'], ['file', 'directory', 'symlink'], true)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Staged path ' . base64_encode($path) . ' has unsupported type ' . $identity['type'] . '.');
        }
        $this->install_staged_value($state, $path, $identity['type'], false);
    }

    /**
     * Renames one completed staged value into the live tree.
     *
     * Before rename, the checkpoint records the exact path and expected type so
     * recovery can tell whether the staged value still needs installation or
     * the live tree already contains the committed value. Only same-filesystem
     * renames are allowed; copy fallback would break the direct-install model.
     *
     * @param array{
     *     current_installation:?array{path_b64:string,expected_type:string},
     *     traversal_stack:list<array{component_b64:string}>,
     *     values_applied:int
     * } $state Commit checkpoint, mutated in place.
     * @param string $path Target-relative value path.
     * @param string $expected_type Staged type expected at $path.
     * @param bool $recovering Whether current_installation is already durable.
     */
    private function install_staged_value(array &$state, string $path, string $expected_type, bool $recovering): void {
        $staged_path = $this->files_dir . '/' . $path;
        $staged = $this->lstat_path($staged_path);
        if ($staged === null || $staged['type'] !== $expected_type) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Staged ' . $expected_type . ' ' . base64_encode($path) . ' is not present for installation.');
        }
        $parent_device = $this->assert_live_ancestors($path, 'install', $expected_type);
        $live_path = $this->target_path($path);
        $live = $this->lstat_path($live_path);
        $expected_live_types = $expected_type === 'directory' ? ['absent'] : ['absent', 'file', 'symlink'];
        $observed_type = $live === null ? 'absent' : $live['type'];
        if (!in_array($observed_type, $expected_live_types, true)) {
            $this->throw_live_tree_changed('install', $path, $path, $expected_type, $expected_live_types, $live);
        }
        if ($parent_device !== $staged['dev']) {
            $this->throw_cross_device('install', $path, $staged['dev'], $parent_device);
        }
        if (!$recovering) {
            $state['current_installation'] = ['path_b64' => base64_encode($path), 'expected_type' => $expected_type];
            $this->write_json($this->commit_path, $state);
        }
        error_clear_last();
        if (!@rename($staged_path, $live_path)) {
            $last_error = error_get_last();
            $message = is_array($last_error) ? $last_error['message'] : '';
            $observed_live = $this->lstat_path($live_path);
            if ($observed_live !== null && $observed_live['dev'] !== $staged['dev']) {
                $this->throw_cross_device('install', $path, $staged['dev'], $observed_live['dev']);
            }
            if (stripos($message, 'cross-device') !== false || stripos($message, 'exdev') !== false) {
                $this->throw_cross_device('install', $path, $staged['dev'], $parent_device);
            }
            throw new Site_Export_Staged_Apply_Exception(
                self::ERROR_RETRYABLE_IO,
                'Could not rename staged ' . base64_encode($path) . ' directly into the live tree'
                . ( $message === '' ? '.' : ': ' . $message )
            );
        }
        $state['current_installation'] = null;
        ++$state['values_applied'];
        $this->write_json($this->commit_path, $state);
    }


    /**
     * Validates existing live ancestors without following a symlink.
     *
     * @return int|null Device of the nearest real parent, or null when a
     *                  deletion root is already absent below a missing parent.
     */
    private function assert_live_ancestors(string $path, string $operation, ?string $staged_type = null): ?int {
        $root = $this->lstat_path($this->target_root);
        if ($root === null || $root['type'] !== 'directory') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The managed live root is no longer a real directory.');
        }
        $staging_device = $this->staging_device();
        if ($root['dev'] !== $staging_device) {
            $this->throw_cross_device($operation, $path, $staging_device, $root['dev']);
        }
        $device = $root['dev'];
        $absolute = $this->target_root;
        $relative = '';
        $segments = explode('/', $path);
        array_pop($segments);
        foreach ($segments as $segment) {
            $relative = $relative === '' ? $segment : $relative . '/' . $segment;
            $absolute .= ( $absolute === '/' ? '' : '/' ) . $segment;
            $identity = $this->lstat_path($absolute);
            if ($identity === null) {
                if ($operation === 'delete') {
                    return null;
                }
                $this->throw_live_tree_changed($operation, $path, $relative, $staged_type, ['directory'], null);
            }
            if ($identity['type'] !== 'directory') {
                $this->throw_live_tree_changed($operation, $path, $relative, $staged_type, ['directory'], $identity);
            }
            if ($identity['dev'] !== $device) {
                $this->throw_cross_device($operation, $relative, $staging_device, $identity['dev']);
            }
            $device = $identity['dev'];
        }
        return $device;
    }

    /**
     * @param list<string> $expected_live_types Live identity types accepted at
     *                                          the conflicting path.
     * @param array{type:string,dev:int,ino:int,size:int,ctime:int}|null $observed_identity
     *     Observed live filesystem identity, or null when absent.
     */
    private function throw_live_tree_changed(
        string $operation,
        string $path,
        string $conflict_path,
        ?string $staged_type,
        array $expected_live_types,
        ?array $observed_identity
    ): void {
        $detail = 'Refusing the operation because the observed live filesystem state is incompatible. The conflicting path was left untouched.';
        $context = [
            'operation' => $operation,
            'path_b64' => base64_encode($path),
            'conflict_path_b64' => base64_encode($conflict_path),
            'expected_live_types' => $expected_live_types,
            'observed_live_identity' => $observed_identity === null ? ['type' => 'absent'] : $observed_identity,
            'detail' => $detail,
        ];
        if ($staged_type !== null) {
            $context['staged_type'] = $staged_type;
        }
        throw new Site_Export_Staged_Apply_Exception(self::ERROR_LIVE_TREE_CHANGED, $detail, $context);
    }

    /**
     * Raises the terminal same-filesystem violation used by push commit.
     *
     * Staged apply intentionally has no copy fallback. Copying would turn a
     * bounded rename step into an unbounded transfer and could leave partially
     * copied live files after interruption, so any device mismatch becomes a
     * classified terminal error.
     *
     * @param string $operation Stage, delete, or install operation being checked.
     * @param string $path Target-relative path associated with the mismatch.
     * @param int $staging_device Device id of the private staging filesystem.
     * @param int $live_device Device id observed in the live tree.
     */
    private function throw_cross_device(string $operation, string $path, int $staging_device, int $live_device): void {
        $detail = 'The staged value and live destination are on different filesystems. This push requires same-filesystem rename and has no copy fallback.';
        throw new Site_Export_Staged_Apply_Exception(self::ERROR_CROSS_DEVICE_FILESYSTEM, $detail, [
            'operation' => $operation,
            'path_b64' => base64_encode($path),
            'staging_device' => $staging_device,
            'live_device' => $live_device,
            'detail' => $detail,
        ]);
    }

    /**
     * Verifies that two concrete paths are on the same device.
     *
     * This is used when creating or opening a session, where both paths must
     * already exist and lstat() can supply device ids directly. Later per-path
     * checks use the live ancestor walkers because the final destination may
     * not exist yet.
     *
     * @param string $staging_path Existing private staging path.
     * @param string $live_path Existing live-tree path.
     * @param string $operation Operation name to report on failure.
     * @param string $relative_path Target-relative path to report on failure.
     */
    private function assert_same_filesystem(string $staging_path, string $live_path, string $operation, string $relative_path): void {
        $staging = $this->lstat_path($staging_path);
        $live = $this->lstat_path($live_path);
        if ($staging === null || $live === null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not determine the staging and live filesystem devices.');
        }
        if ($staging['dev'] !== $live['dev']) {
            $this->throw_cross_device($operation, $relative_path, $staging['dev'], $live['dev']);
        }
    }

    /**
     * Returns the device id of the completed staging tree root.
     *
     * All direct installs must remain on this device. Reading it from work/files
     * rather than cached constructor state keeps recovery honest if the private
     * workspace was moved or corrupted between requests.
     *
     * @return int Device id reported by lstat().
     */
    private function staging_device(): int {
        $identity = $this->lstat_path($this->files_dir);
        if ($identity === null || $identity['type'] !== 'directory') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'work/files is not a real staging directory.');
        }
        return $identity['dev'];
    }

    /**
     * Reads an exact number of bytes from a stream or reports a precise short read.
     *
     * Delete replay validation and suffix inspection rely on exact byte counts.
     * Returning partial data would corrupt offset accounting, so short reads
     * are classified as retryable I/O failures naming the observed length.
     *
     * @param resource $handle Open stream positioned at the first byte to read.
     * @param int $bytes Number of bytes required.
     * @param string $description Human-readable stream description for errors.
     * @return string Bytes read from the stream.
     */
    private function read_exact($handle, int $bytes, string $description): string {
        $result = '';
        $result_bytes = 0;
        while ($result_bytes < $bytes) {
            $piece = fread($handle, $bytes - $result_bytes);
            if ($piece === false || $piece === '') {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read complete ' . $description . '; expected ' . $bytes . ' bytes and observed ' . $result_bytes . '.');
            }
            $result .= $piece;
            $result_bytes += strlen($piece);
        }
        return $result;
    }

    /**
     * Returns the first child name in a directory without following children.
     *
     * The method is used only to distinguish empty directories from ones with
     * descendants. It returns the raw directory entry name so callers can build
     * their own private or live path without allocating a full listing.
     *
     * @param string $directory Absolute directory path.
     * @return string|null First child name, or null when the directory is empty.
     */
    private function first_directory_entry(string $directory): ?string {
        $handle = @opendir($directory);
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read directory ' . $directory . '.');
        }
        try {
            while (true) {
                $entry = readdir($handle);
                if ($entry === false) {
                    break;
                }
                if ($entry !== '.' && $entry !== '..') {
                    return $entry;
                }
            }
        } finally {
            closedir($handle);
        }
        return null;
    }

    /**
     * Finds whether a tree contains any non-empty staged work.
     *
     * Empty directories can be structural traversal artifacts rather than
     * logical values. This descends through such directories until it sees a
     * leaf value or a directory with its own non-empty descendant.
     *
     * @param string $directory Absolute private directory path.
     * @return string|null Entry name proving pending work exists, or null.
     */
    private function first_tree_entry(string $directory): ?string {
        $handle = @opendir($directory);
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read directory ' . $directory . '.');
        }
        try {
            while (true) {
                $entry = readdir($handle);
                if ($entry === false) {
                    break;
                }
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                $identity = $this->lstat_path($path);
                if ($identity === null) {
                    continue;
                }
                if ($identity['type'] !== 'directory' || $this->first_tree_entry($path) !== null) {
                    return $entry;
                }
            }
        } finally {
            closedir($handle);
        }
        return null;
    }

    /**
     * Returns a staged leaf path below a structural directory.
     *
     * When a live structural ancestor conflicts, reporting only the ancestor can
     * hide which staged value required it. This walks to one descendant so the
     * error can name requested work rather than only the traversal directory.
     *
     * @param string $directory Absolute staged directory being traversed.
     * @param string $relative_path Target-relative path for that directory.
     * @return string Target-relative descendant or the original path if empty.
     */
    private function first_staged_leaf_path(string $directory, string $relative_path): string {
        $entry = $this->first_directory_entry($directory);
        if ($entry === null) {
            return $relative_path;
        }
        $child_path = $relative_path . '/' . $entry;
        $identity = $this->lstat_path($directory . '/' . $entry);
        if ($identity !== null && $identity['type'] === 'directory') {
            return $this->first_staged_leaf_path($directory . '/' . $entry, $child_path);
        }
        return $child_path;
    }

    /**
     * Checks whether a live .maintenance file belongs to this commit token.
     *
     * The marker may be a normal WordPress maintenance file created by another
     * process. Only files containing this session's ownership comment are safe
     * to refresh or remove; foreign markers keep the target busy.
     *
     * @param string $path Absolute live .maintenance path.
     * @param string $token Commit checkpoint's maintenance token.
     * @return bool Whether the marker contains this session's ownership line.
     */
    private function maintenance_marker_is_owned(string $path, string $token): bool {
        $contents = @file_get_contents($path, false, null, 0, 512);
        return is_string($contents)
            && strpos($contents, '// reprint-staged-session:' . $this->session_id . ':' . $token . "\n") !== false;
    }

    /**
     * Releases this session's target-wide commit claim if it still owns it.
     *
     * The active marker is advisory state protected by the target lock. Missing
     * or foreign contents are left untouched so cleanup cannot erase another
     * session's claim after an operator or retry changed target ownership.
     */
    private function release_target(): void {
        $this->with_target_lock(function (): void {
            $active_path = $this->storage_dir . '/apply-sessions/target.active';
            $active = @file_get_contents($active_path);
            if (!is_string($active) || trim($active) !== $this->session_id) {
                return;
            }
            if (!@unlink($active_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not release the staged apply target coordinator.');
            }
        });
    }

    /**
     * Runs a callback while holding the target-wide coordinator lock.
     *
     * This lock serializes the small `target.active` file shared by all apply
     * sessions for one storage directory. It is intentionally separate from a
     * session lock so a committing session can block other committers without
     * blocking their upload/status cleanup paths.
     *
     * @param callable $callback Critical section to execute while locked.
     */
    private function with_target_lock(callable $callback): void {
        $lock = @fopen($this->storage_dir . '/apply-sessions/target.lock', 'c+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply target coordinator lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'The staged apply target coordinator is busy. Retry the request.');
            }
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Runs one callback against a validated session while holding its lock.
     *
     * The workspace layout is checked by acquire_session_lock(). Immutable
     * session identity and the same-filesystem requirement are then checked
     * before the callback can read or mutate session state.
     *
     * @return mixed Callback result.
     */
    private function with_session_lock(callable $callback) {
        $lock = $this->acquire_session_lock();
        try {
            $this->assert_session_configuration();
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Locks one existing session after checking only the paths needed to do so safely.
     *
     * The complete durable workspace is validated after the lock is held. This
     * avoids trusting a pre-lock snapshot while also rejecting an already
     * malformed session or lock path before fopen() is called.
     *
     * @return resource Exclusive session lock owned by the caller.
     */
    private function acquire_session_lock() {
        $session_identity = $this->lstat_path($this->session_dir);
        if ($session_identity === null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_SESSION_NOT_FOUND, 'The staged apply session does not exist: ' . $this->session_id . '.');
        }
        if ($session_identity['type'] !== 'directory') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The staged apply session path is not a real directory: ' . $this->session_dir . '.');
        }
        $lock_identity = $this->lstat_path($this->lock_path);
        if ($lock_identity === null || $lock_identity['type'] !== 'file') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The staged apply session lock is missing or not regular: ' . $this->lock_path . '.');
        }

        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply session lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session ' . $this->session_id . ' is busy. Retry the request.');
        }
        try {
            foreach ([$this->session_dir, $this->work_dir, $this->files_dir, $this->partial_dir] as $directory) {
                $identity = $this->lstat_path($directory);
                if ($identity === null || $identity['type'] !== 'directory') {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Required staged apply directory is missing or not real: ' . $directory . '.');
                }
            }
            foreach ([$this->session_metadata_path, $this->lock_path, $this->deletes_path] as $file) {
                $identity = $this->lstat_path($file);
                if ($identity === null || $identity['type'] !== 'file') {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Required staged apply file is missing or not regular: ' . $file . '.');
                }
            }
            foreach ([$this->commit_path, $this->maintenance_identity_path] as $optional_file) {
                $identity = $this->lstat_path($optional_file);
                if ($identity !== null && $identity['type'] !== 'file') {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Optional staged apply file has an unsupported type: ' . $optional_file . '.');
                }
            }
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
        return $lock;
    }

    /**
     * Verifies that durable session identity still matches this server configuration.
     *
     * Discard deliberately omits this check: private work may need cleanup
     * after the target or protected-path configuration has changed. Upload,
     * status, and commit must agree with the immutable session metadata and
     * retain the same-filesystem guarantee under which the session was made.
     */
    private function assert_session_configuration(): void {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata) || ( $metadata['version'] ?? null ) !== 2 || ( $metadata['session_id'] ?? null ) !== $this->session_id
            || !is_bool($metadata['delete_upload_complete'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata has an unsupported version or session identity.');
        }
        if (!is_string($metadata['target_root_b64'] ?? null) || !is_array($metadata['protected_paths_b64'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata does not contain the configured target and protected paths.');
        }
        $target = base64_decode($metadata['target_root_b64'], true);
        $protected = [];
        foreach ($metadata['protected_paths_b64'] as $encoded) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($decoded)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata contains an invalid protected path.');
            }
            $protected[] = $decoded;
        }
        if ($target !== $this->target_root || $protected !== $this->protected_paths) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata does not match the current apply configuration.');
        }
        $this->assert_same_filesystem($this->files_dir, $this->target_root, 'stage', '');
    }

    /**
     * Reads a bounded JSON object from private session metadata.
     *
     * Missing files return null so callers can distinguish optional checkpoints
     * from malformed ones. Existing files must be regular, within the metadata
     * size ceiling, and decode to a JSON object.
     *
     * @param string $path Absolute metadata file path.
     * @return array<string,mixed>|null Decoded caller-specific object, or null
     *                                  if absent.
     */
    private function read_json(string $path): ?array {
        $identity = $this->lstat_path($path);
        if ($identity === null) {
            return null;
        }
        if ($identity['type'] !== 'file' || $identity['size'] > self::MAX_METADATA_BYTES) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Metadata file ' . $path . ' is not a bounded regular file.');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read metadata file ' . $path . '.');
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Metadata file ' . $path . ' does not contain a JSON object.');
        }
        return $decoded;
    }

    /**
     * Atomically writes one bounded JSON metadata object.
     *
     * JSON is encoded without slash escaping because metadata contains many
     * filesystem paths already protected by base64 where necessary. The encoded
     * object must fit the same ceiling enforced by read_json().
     *
     * @param string $path Absolute metadata file path.
     * @param array{
     *     version?:int,
     *     session_id?:string,
     *     target_root_b64?:string,
     *     protected_paths_b64?:list<string>,
     *     delete_upload_complete?:bool,
     *     phase?:string,
     *     delete_offset?:int,
     *     current_deletion_b64?:?string,
     *     current_installation?:?array{path_b64:string,expected_type:string},
     *     traversal_stack?:list<array{component_b64:string}>,
     *     maintenance_token?:string,
     *     deletions_applied?:int,
     *     values_applied?:int,
     *     terminal_error?:array{reason:string,detail:string,context:array<string,mixed>}
     * } $value Session metadata or commit checkpoint object to persist.
     */
    private function write_json(string $path, array $value): void {
        $contents = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($contents)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Could not encode bounded staged apply metadata.');
        }
        if (strlen($contents) > self::MAX_METADATA_BYTES) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Encoded staged apply metadata exceeds the maximum of ' . self::MAX_METADATA_BYTES . ' bytes.');
        }
        $this->write_atomic_file($path, $contents, 0600);
    }

    /**
     * Writes a private file through a session-specific temporary path and rename.
     *
     * The temporary name includes the session id so concurrent sessions updating
     * shared coordinator files do not collide before the target lock serializes
     * the final rename. Permissions are applied before publication.
     *
     * @param string $path Absolute destination path.
     * @param string $contents Complete file contents to write.
     * @param int $permissions File mode applied to the temporary file.
     */
    private function write_atomic_file(string $path, string $contents, int $permissions): void {
        $temporary = $path . '.tmp-' . $this->session_id;
        if ($this->lstat_path($temporary) !== null && !@unlink($temporary)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not clear temporary metadata file ' . $temporary . '.');
        }
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create temporary metadata file ' . $temporary . '.');
        }
        try {
            $this->write_all($handle, $contents, 'metadata file ' . $path);
            if (!fflush($handle)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not flush temporary metadata file ' . $temporary . '.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, $permissions);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not publish metadata file ' . $path . '.');
        }
    }

    /**
     * Writes every byte of a string to an already opened stream.
     *
     * fwrite() may accept only part of a string. This loops until all bytes are
     * written and reports the exact completed count if the stream stops making
     * progress, preventing silent truncation of staged payloads or metadata.
     *
     * @param resource $handle Writable stream.
     * @param string $contents Bytes to write.
     * @param string $description Human-readable destination for errors.
     */
    private function write_all($handle, string $contents, string $description): void {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not finish writing ' . $description . '; wrote ' . $offset . ' of ' . $length . ' bytes.');
            }
            $offset += $written;
        }
    }

    /**
     * Validates the durable commit checkpoint schema before it drives mutation.
     *
     * Every field that controls delete offsets, traversal, maintenance
     * ownership, or pending installation is checked before use. This keeps a
     * corrupt checkpoint from being interpreted as live-tree authority.
     *
     * @param array{
     *     version?:mixed,
     *     phase?:mixed,
     *     delete_offset?:mixed,
     *     deletions_applied?:mixed,
     *     values_applied?:mixed,
     *     maintenance_token?:mixed,
     *     current_deletion_b64?:mixed,
     *     current_installation?:mixed,
     *     traversal_stack?:mixed,
     *     terminal_error?:mixed
     * } $state Decoded commit checkpoint.
     */
    private function require_valid_commit_state(array $state): void {
        if (( $state['version'] ?? null ) !== 2) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an unsupported version.');
        }
        if (!in_array($state['phase'] ?? null, ['deleting', 'applying', 'complete'], true)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid phase.');
        }
        foreach (['delete_offset', 'deletions_applied', 'values_applied'] as $field) {
            if (!isset($state[$field]) || !is_int($state[$field]) || $state[$field] < 0) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint field ' . $field . ' must be a non-negative integer.');
            }
        }
        if (!is_string($state['maintenance_token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['maintenance_token']) !== 1) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid maintenance token.');
        }
        if (!array_key_exists('current_deletion_b64', $state)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint is missing current_deletion_b64.');
        }
        if ($state['current_deletion_b64'] !== null) {
            $this->decode_commit_path($state['current_deletion_b64'], 'current deletion');
        }
        if (!array_key_exists('current_installation', $state)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint is missing current_installation.');
        }
        $installation = $state['current_installation'];
        if ($installation !== null) {
            if (!is_array($installation) || !is_string($installation['path_b64'] ?? null)
                || !in_array($installation['expected_type'] ?? null, ['file', 'directory', 'symlink'], true)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid current installation.');
            }
            $this->decode_commit_path($installation['path_b64'], 'current installation');
        }
        if (!is_array($state['traversal_stack'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid traversal stack.');
        }
        foreach ($state['traversal_stack'] as $frame) {
            if (!is_array($frame) || !is_string($frame['component_b64'] ?? null)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid structural traversal frame.');
            }
        }
        $this->traversal_path($state['traversal_stack']);
        if (isset($state['terminal_error'])) {
            $error = $state['terminal_error'];
            if (!is_array($error) || !in_array($error['reason'] ?? null, [self::ERROR_LIVE_TREE_CHANGED, self::ERROR_CROSS_DEVICE_FILESYSTEM], true)
                || !is_string($error['detail'] ?? null) || !is_array($error['context'] ?? null)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid terminal error.');
            }
        }
    }

    /**
     * Decodes and validates a base64 path stored in a commit checkpoint.
     *
     * Checkpoints store arbitrary filesystem bytes as base64 to remain valid
     * JSON. This method rejects missing, malformed, or unsafe paths before they
     * are used to select live filesystem work.
     *
     * @param mixed $encoded Candidate base64 value from metadata.
     * @param string $description Field name used in error messages.
     * @return string Decoded target-relative path.
     */
    private function decode_commit_path($encoded, string $description): string {
        if (!is_string($encoded)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit ' . $description . ' path is not base64 text.');
        }
        $path = base64_decode($encoded, true);
        if (!is_string($path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit ' . $description . ' path is not valid base64.');
        }
        $this->validate_path($path);
        return $path;
    }

    /**
     * Reconstructs the target-relative traversal path from checkpoint frames.
     *
     * Each frame stores exactly one base64 path component. The method validates
     * each component independently, rebuilds the slash-separated path, and then
     * applies the normal target-relative path rules to the result.
     *
     * @param list<array{component_b64:string}> $stack Traversal frames.
     * @return string Target-relative path for the current traversal directory.
     */
    private function traversal_path(array $stack): string {
        $path = '';
        foreach ($stack as $frame) {
            $encoded = $frame['component_b64'] ?? null;
            $component = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($component) || $component === '' || strpos($component, '/') !== false) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit traversal frame does not contain one valid base64 path component.');
            }
            $path = $path === '' ? $component : $path . '/' . $component;
            if (strlen($path) > self::MAX_PATH_BYTES) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit traversal path exceeds the maximum of ' . self::MAX_PATH_BYTES . ' bytes.');
            }
        }
        if ($path !== '') {
            $this->validate_path($path);
        }
        return $path;
    }

    /**
     * Reads whether the sender explicitly closed the delete stream.
     *
     * A zero-byte or currently stored delete stream is not enough to commit:
     * the sender must declare completion so the target knows no later request
     * will append more deletion records.
     *
     * @return bool True once a delete-list part declared completion.
     */
    private function delete_upload_is_complete(): bool {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata) || !is_bool($metadata['delete_upload_complete'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata has no valid delete-upload completion state.');
        }
        return $metadata['delete_upload_complete'];
    }

    /**
     * Validates one target-relative path accepted by staged apply.
     *
     * Paths are byte strings carried through base64 on the wire. They must be
     * relative, bounded, free of NUL/backslash and dot segments, outside the
     * WordPress maintenance marker, and not equal to or an ancestor/descendant
     * of a protected path.
     *
     * @param string $path Target-relative raw path bytes.
     */
    private function validate_path(string $path): void {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new InvalidArgumentException('Target-relative path must contain between 1 and ' . self::MAX_PATH_BYTES . ' safe bytes; observed ' . base64_encode($path) . '.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Target-relative path contains an empty, dot, or parent segment: ' . base64_encode($path) . '.');
            }
        }
        if ($path === '.maintenance' || strpos($path, '.maintenance/') === 0) {
            throw new InvalidArgumentException('Protected target-relative path cannot be changed: ' . base64_encode($path) . '.');
        }
        foreach ($this->protected_paths as $protected_path) {
            if ($path === $protected_path || strpos($path, $protected_path . '/') === 0 || strpos($protected_path, $path . '/') === 0) {
                throw new InvalidArgumentException('Protected target-relative path cannot be changed: ' . base64_encode($path) . '.');
            }
        }
    }

    /**
     * Decodes a base64 path header from one multipart part.
     *
     * Target paths are validated immediately because they select private and
     * live filesystem locations. Symlink target values can be arbitrary relative
     * strings, so callers can disable target-path validation and apply their
     * own symlink-target rules instead.
     *
     * @param array<string,string> $headers Normalized part headers keyed by lowercase header name.
     * @param string $header Header name to read.
     * @param bool $is_target_path Whether to apply target path validation.
     * @return string Decoded header bytes.
     */
    private function decode_path_header(array $headers, string $header, bool $is_target_path = true): string {
        $encoded = $headers[$header] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new InvalidArgumentException('Multipart part requires a non-empty ' . $header . ' header.');
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('Multipart header ' . $header . ' is not valid base64.');
        }
        if ($is_target_path) {
            $this->validate_path($decoded);
        }
        return $decoded;
    }

    /**
     * Rejects unexpected headers for a multipart part type.
     *
     * The staged apply protocol is deliberately narrow. Extra headers are not
     * ignored because a misspelled required header or a future unsupported
     * option should fail at the boundary instead of silently changing meaning.
     *
     * @param array<string,string> $headers Normalized headers to inspect, keyed
     *                                      by lowercase header name.
     * @param list<string> $allowed Lowercase header names allowed for this part.
     * @param string $type Human-readable part type for errors.
     */
    private function require_only_headers(array $headers, array $allowed, string $type): void {
        foreach ($headers as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException('Multipart ' . $type . ' part does not allow header ' . json_encode($name) . '.');
            }
        }
    }

    /**
     * Reads a non-negative decimal integer header.
     *
     * Header values arrive as strings. This validates the decimal grammar and
     * rejects values that overflow PHP's integer range rather than silently
     * wrapping offsets, sizes, or Content-Length values.
     *
     * @param array<string,string> $headers Normalized headers to inspect, keyed
     *                                      by lowercase header name.
     * @param string $header Header name to read.
     * @return int Parsed non-negative integer.
     */
    private function require_non_negative_header(array $headers, string $header): int {
        $value = $headers[$header] ?? null;
        if (!is_string($value) || $value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Multipart header ' . $header . ' must be a non-negative decimal integer; observed ' . json_encode($value) . '.');
        }
        $integer = (int) $value;
        if ($integer < 0 || ( (string) $integer !== ltrim($value, '0') && !preg_match('/^0+$/D', $value) )) {
            throw new InvalidArgumentException('Multipart header ' . $header . ' exceeds the supported integer range; observed ' . json_encode($value) . '.');
        }
        return $integer;
    }

    /**
     * Joins the managed live root with one validated relative path.
     *
     * The caller is responsible for validate_path() where the value originates.
     * This method only preserves correct slash handling for both `/` and normal
     * directory roots.
     *
     * @param string $relative_path Target-relative path.
     * @return string Absolute path in the live tree.
     */
    private function target_path(string $relative_path): string {
        return $this->target_root . ( $this->target_root === '/' ? '' : '/' ) . $relative_path;
    }

    /**
     * Creates or validates private structural parents for a staged path.
     *
     * Only work/files and work/partial paths are accepted. Missing parents are
     * created when requested; existing parents must be real directories so a
     * staged leaf, link, or external path cannot become a container for another
     * value.
     *
     * @param string $path Absolute private path whose parent is required.
     * @param bool $create_missing Whether absent parent directories are created.
     */
    private function ensure_private_parent(string $path, bool $create_missing = true): void {
        $parent = dirname($path);
        $root = null;
        foreach ([$this->files_dir, $this->partial_dir] as $candidate) {
            if ($parent === $candidate || strpos($parent . '/', $candidate . '/') === 0) {
                $root = $candidate;
                break;
            }
        }
        if ($root === null) {
            throw new LogicException('Private staged path escaped work/files and work/partial.');
        }
        if ($parent === $root) {
            return;
        }
        $relative = substr($parent, strlen($root) + 1);
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            $identity = $this->lstat_path($current);
            if ($identity === null) {
                if (!$create_missing) {
                    return;
                }
                if (!@mkdir($current, 0700)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create private structural staging directory ' . $current . '.');
                }
                continue;
            }
            if ($identity['type'] !== 'directory') {
                throw new InvalidArgumentException('A staged ' . $identity['type'] . ' cannot be used as the parent of another path.');
            }
        }
    }

    /**
     * Returns the lstat identity of one filesystem path.
     *
     * lstat() is used deliberately so symlinks are classified as symlinks
     * rather than followed. Keeping the syscall and mode classification here
     * gives status, recovery, and drift reporting the same view of a path.
     *
     * @param string $path Absolute path to inspect.
     * @return array{type:string,dev:int,ino:int,size:int,ctime:int}|null Type,
     *     device, inode, size, and ctime, or null if absent.
     */
    private function lstat_path(string $path): ?array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return null;
        }
        $type_bits = ( (int) ( $stat['mode'] ?? 0 ) ) & 0170000;
        if ($type_bits === 0100000) {
            $type = 'file';
        } elseif ($type_bits === 0040000) {
            $type = 'directory';
        } elseif ($type_bits === 0120000) {
            $type = 'symlink';
        } else {
            $type = 'other';
        }
        return [
            'type' => $type,
            'dev' => (int) ( $stat['dev'] ?? 0 ),
            'ino' => (int) ( $stat['ino'] ?? 0 ),
            'size' => (int) ( $stat['size'] ?? 0 ),
            'ctime' => (int) ( $stat['ctime'] ?? 0 ),
        ];
    }

    /**
     * Removes one staged private leaf or empty directory.
     *
     * A directory with descendants represents structural state for other staged
     * paths and cannot be replaced by a different logical value. Files,
     * symlinks, and other leaf-like entries are unlinked without following them.
     *
     * @param string $path Absolute private staging path.
     */
    private function remove_private_entry(string $path): void {
        $identity = $this->lstat_path($path);
        if ($identity === null) {
            return;
        }
        if ($identity['type'] === 'directory') {
            if ($this->first_directory_entry($path) !== null) {
                throw new InvalidArgumentException('A staged directory with descendants cannot be replaced by another logical value.');
            }
            if (!@rmdir($path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove an empty private staged directory.');
            }
            return;
        }
        if (!@unlink($path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove a private staged ' . $identity['type'] . '.');
        }
    }

    /**
     * Returns the current size of a required regular file.
     *
     * The size is read through lstat_path() so the path is lstat() checked
     * and symlinks are not followed. Missing files or non-files indicate corrupt
     * staged apply state.
     *
     * @param string $path Absolute file path.
     * @return int Current byte size.
     */
    private function file_size(string $path): int {
        $identity = $this->lstat_path($path);
        if ($identity === null || $identity['type'] !== 'file') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Expected a regular file at ' . $path . '.');
        }
        return $identity['size'];
    }

    /**
     * Advances bounded cleanup of a renamed discard tombstone.
     *
     * Discard first renames a session so it is no longer addressable by its
     * public id. This method then removes at most DISCARD_ENTRY_LIMIT entries
     * while holding the tombstone's own lock, preserving that lock until the
     * final empty-directory removal.
     *
     * @param string $tombstone Absolute tombstone directory path.
     * @return bool True when the tombstone is gone, false when work remains.
     */
    private function discard_tombstone(string $tombstone): bool {
        if (!is_dir($tombstone)) {
            return true;
        }
        $lock_path = $tombstone . '/lock';
        $lock = @fopen($lock_path, 'r+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply discard tombstone lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply discard cleanup is busy. Retry discard.');
            }
            $remaining_entries = self::DISCARD_ENTRY_LIMIT;
            $empty = self::discard_directory_entries($tombstone, $remaining_entries, true);
            if (!$empty) {
                return false;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!@unlink($lock_path) || !@rmdir($tombstone)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove the completed staged apply discard tombstone.');
        }
        return true;
    }

    /**
     * Returns one configured directory as a canonical real path.
     *
     * Newly created session storage uses mode 0700 deliberately. PHP's default
     * 0777 mode, even after a typical umask, can expose staged site contents to
     * other system accounts. Existing configured directories keep their mode.
     *
     * @param string $path Absolute directory path from configuration.
     * @param string $description Human-readable name for validation errors.
     * @param bool $create Whether the directory may be created if missing.
     * @return string Canonical absolute directory path without trailing slash.
     */
    private static function require_directory(string $path, string $description, bool $create): string {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('The ' . $description . ' must be an absolute directory; observed ' . json_encode($path) . '.');
        }
        if ($create && !is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create ' . $description . ' directory ' . $path . '.');
        }
        $real_path = realpath($path);
        if ($real_path === false || !is_dir($real_path) || is_link($path)) {
            throw new InvalidArgumentException('The ' . $description . ' is not a real directory: ' . $path . '.');
        }
        return $real_path === '/' ? '/' : rtrim($real_path, '/');
    }

    /**
     * Validates the public session id grammar.
     *
     * Session ids are used in URLs, directory names, lock files, and ownership
     * comments. Restricting them to lowercase hexadecimal keeps those contexts
     * unambiguous and avoids any path normalization concerns.
     *
     * @param string $session_id Caller-provided session id.
     */
    private static function require_session_id(string $session_id): void {
        if (preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Staged apply session id must be a 32-character lowercase hexadecimal string.');
        }
    }

    /**
     * Removes a bounded number of entries from a discard directory tree.
     *
     * The counter is shared through recursive calls so one discard request has a
     * hard work limit no matter how deeply nested the tombstone is. The top
     * level may preserve its lock file until all other entries are gone.
     *
     * @param string $directory_path Absolute directory currently being drained.
     * @param int $remaining_entries Remaining unlink/rmdir operations allowed.
     * @param bool $preserve_lock Whether to keep a child named `lock`.
     * @return bool True when this directory is empty enough to remove.
     */
    private static function discard_directory_entries(string $directory_path, int &$remaining_entries, bool $preserve_lock = false): bool {
        $handle = @opendir($directory_path);
        if ($handle === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read staged apply discard directory: ' . $directory_path . '.');
        }
        try {
            while (true) {
                $entry = readdir($handle);
                if ($entry === false) {
                    break;
                }
                if ($entry === '.' || $entry === '..' || ( $preserve_lock && $entry === 'lock' )) {
                    continue;
                }
                if ($remaining_entries === 0) {
                    return false;
                }
                $entry_path = $directory_path . '/' . $entry;
                clearstatcache(true, $entry_path);
                $stat = @lstat($entry_path);
                if (!is_array($stat)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Staged apply discard entry disappeared during cleanup: ' . $entry_path . '.');
                }
                $type = ( (int) ( $stat['mode'] ?? 0 ) ) & 0170000;
                if ($type === 0040000) {
                    if (!self::discard_directory_entries($entry_path, $remaining_entries)) {
                        return false;
                    }
                    if ($remaining_entries === 0) {
                        return false;
                    }
                    if (!@rmdir($entry_path)) {
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove staged apply discard directory: ' . $entry_path . '.');
                    }
                } elseif (!@unlink($entry_path)) {
                    throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove staged apply discard entry: ' . $entry_path . '.');
                }
                --$remaining_entries;
            }
        } finally {
            closedir($handle);
        }
        return true;
    }

    /**
     * Recursively removes a newly created private tree after setup failure.
     *
     * This is used only before a session becomes usable, when cleanup should be
     * immediate rather than bounded by discard semantics. It uses lstat() and
     * unlink/rmdir so symlinks are removed as links and never traversed.
     *
     * @param string $path Absolute private path to remove if it exists.
     */
    private static function remove_tree(string $path): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return;
        }
        $type = ( (int) ( $stat['mode'] ?? 0 ) ) & 0170000;
        if ($type === 0040000) {
            $handle = @opendir($path);
            if ($handle === false) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not read staged apply directory for removal: ' . $path . '.');
            }
            try {
                while (true) {
                    $entry = readdir($handle);
                    if ($entry === false) {
                        break;
                    }
                    if ($entry !== '.' && $entry !== '..') {
                        self::remove_tree($path . '/' . $entry);
                    }
                }
            } finally {
                closedir($handle);
            }
            if (!@rmdir($path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove staged apply directory ' . $path . '.');
            }
            return;
        }
        if (!@unlink($path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove staged apply entry ' . $path . '.');
        }
    }
}
