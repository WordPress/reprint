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
 * Successful installation consumes each entry. Deletes remain raw NUL-delimited
 * bytes in `work/deletes`; their confirmed cursor is the file's actual size.
 * Commit persists only one deletion, one installation, and a path-depth-bounded
 * traversal stack. It never builds a candidate tree, action plan, backup, path
 * index, or second queue.
 */
final class Site_Export_Staged_Apply_Session {

    public const ERROR_BUSY = 'busy';
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
    /** @var int */
    private $maximum_upload_parts = 128;
    /** @var int */
    private $upload_parts_read = 0;

    /**
     * Derives private paths without touching the filesystem.
     *
     * @param string[] $protected_paths
     */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths) {
        $this->storage_dir = rtrim($storage_dir, '/');
        $this->target_root = $target_root === '/' ? '/' : rtrim($target_root, '/');
        $this->session_id = $session_id;
        $this->protected_paths = $protected_paths;
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
     * bytes can be accepted.
     *
     * @param string[] $protected_paths
     */
    public static function create(string $storage_dir, string $target_root, array $protected_paths, string $session_id): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', true);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::normalize_protected_paths(
            self::protect_session_storage($storage_dir, $target_root, $protected_paths)
        );
        $sessions_dir = $storage_dir . '/apply-sessions';
        if (!is_dir($sessions_dir) && !@mkdir($sessions_dir, 0700, true) && !is_dir($sessions_dir)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create staged apply sessions directory ' . $sessions_dir . '.');
        }
        $sessions_dir = self::require_directory($sessions_dir, 'staged apply sessions', false);
        $creation_lock = @fopen($sessions_dir . '/create.lock', 'c+b');
        if ($creation_lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply creation lock.');
        }
        try {
            if (!flock($creation_lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session creation is busy. Retry the create request.');
            }
            $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
            if (file_exists($session->session_dir) || is_link($session->session_dir)) {
                $session->assert_workspace_layout();
                $session->read_session_metadata();
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
                'protected_paths_b64' => array_map('base64_encode', $protected_paths),
                'delete_upload_complete' => false,
            ]);
            $session->assert_workspace_layout();
            return $session;
        } finally {
            flock($creation_lock, LOCK_UN);
            fclose($creation_lock);
        }
    }

    /** @param string[] $protected_paths */
    public static function open(string $storage_dir, string $target_root, string $session_id, array $protected_paths): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::normalize_protected_paths(
            self::protect_session_storage($storage_dir, $target_root, $protected_paths)
        );
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        if (!file_exists($session->session_dir) && !is_link($session->session_dir)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_SESSION_NOT_FOUND, 'The staged apply session does not exist: ' . $session_id . '.');
        }
        $session->assert_workspace_layout();
        $session->read_session_metadata();
        $session->assert_same_filesystem($session->files_dir, $session->target_root, 'stage', '');
        return $session;
    }

    /** @param string[] $protected_paths */
    public static function discard(string $storage_dir, string $target_root, string $session_id, array $protected_paths): bool {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::normalize_protected_paths(
            self::protect_session_storage($storage_dir, $target_root, $protected_paths)
        );
        return ( new self($storage_dir, $target_root, $session_id, $protected_paths) )->discard_workspace();
    }

    public function get_session_id(): string {
        return $this->session_id;
    }

    public function get_session_directory(): string {
        return $this->session_dir;
    }

    /**
     * Opens one caller-driven multipart request without reading its body.
     *
     * @param resource $input
     */
    public function accept_upload($input, Site_Export_Multipart_Processor $processor, int $maximum_part_bytes = PHP_INT_MAX, int $maximum_parts = 128): void {
        if ($this->upload_lock !== null) {
            throw new LogicException('A staged apply upload is already open; call finish_upload() first.');
        }
        if (!is_resource($input)) {
            throw new InvalidArgumentException('Staged apply multipart input must be a readable stream resource; received ' . gettype($input) . '.');
        }
        if ($maximum_part_bytes <= 0 || $maximum_parts <= 0) {
            throw new InvalidArgumentException('Multipart part byte and count limits must both be greater than zero.');
        }
        if (!is_dir($this->session_dir)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_SESSION_NOT_FOUND, 'The staged apply session does not exist: ' . $this->session_id . '.');
        }
        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply session lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session ' . $this->session_id . ' is busy. Retry the upload.');
        }
        try {
            $this->assert_workspace_layout();
            if (is_file($this->commit_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_COMMIT_REQUIRED, 'Uploads are closed because this staged apply session is committing.');
            }
            $this->upload_lock = $lock;
            $this->upload_input = $input;
            $this->upload_processor = $processor;
            $this->current_upload_part_ended = false;
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

    /** Reads and durably stages exactly one complete MIME part. */
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
            if ($this->upload_parts_read >= $this->maximum_upload_parts) {
                throw new InvalidArgumentException('Multipart upload contains more than the target maximum of ' . $this->maximum_upload_parts . ' parts per request.');
            }
            ++$this->upload_parts_read;
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
            throw $exception;
        }
    }

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
        $this->maximum_upload_parts = 128;
        $this->upload_parts_read = 0;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** @return array<string,mixed>|null */
    public function get_current_change(): ?array {
        return $this->current_change;
    }

    /** @param string[] $paths @return array<string,mixed> */
    public function get_status(array $paths = []): array {
        return $this->with_session_lock(function () use ($paths): array {
            $reported_paths = [];
            foreach ($paths as $path) {
                $this->validate_path($path);
                $partial = $this->private_path($this->partial_dir, $path);
                $complete = $this->private_path($this->files_dir, $path);
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
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Partial staging path ' . base64_encode($path) . ' is not a regular file.');
                    }
                    $reported_paths[] = [
                        'path_b64' => base64_encode($path),
                        'state' => 'partial',
                        'type' => 'file',
                        'accepted_bytes' => $partial_identity['size'],
                    ];
                    continue;
                }
                $reported_paths[] = ['path_b64' => base64_encode($path), 'state' => 'missing', 'accepted_bytes' => 0];
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
                'paths' => $reported_paths,
            ];
        });
    }

    /**
     * Advances bounded delete or install work under maintenance.
     *
     * @return array<string,mixed>
     */
    public function commit(int $maximum_steps = 1): array {
        if ($maximum_steps <= 0) {
            throw new InvalidArgumentException('The staged apply commit step limit must be greater than zero.');
        }
        return $this->with_session_lock(function () use ($maximum_steps): array {
            $state = $this->read_json($this->commit_path);
            if ($state === null) {
                $state = $this->start_commit();
            } else {
                $this->require_valid_commit_state($state);
            }
            if (isset($state['terminal_error'])) {
                $this->throw_terminal_error($state['terminal_error']);
            }
            if ($state['phase'] === 'complete') {
                return $this->commit_result($state, 0);
            }
            $this->claim_target();
            $this->publish_or_refresh_maintenance_marker($state);
            $files_applied = 0;
            try {
                for ($step = 0; $step < $maximum_steps && $state['phase'] !== 'complete'; ++$step) {
                    if ($state['phase'] === 'deleting') {
                        $this->advance_deletion($state);
                    } else {
                        $files_applied += $this->advance_installation($state);
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
            return $this->commit_result($state, $files_applied);
        });
    }

    /** Removes an upload-only or completed workspace in bounded calls. */
    public function discard_workspace(): bool {
        $discarding_session_dir = $this->storage_dir . '/apply-sessions/.discarding-' . $this->session_id;
        if (!is_dir($this->session_dir)) {
            return $this->discard_tombstone($discarding_session_dir);
        }
        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply session lock for discard.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session ' . $this->session_id . ' is busy. Retry discard.');
            }
            $this->assert_workspace_layout();
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

    // Upload helpers follow the public lifecycle so readers encounter behavior
    // before the multipart implementation details.

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

    /** @param array<string,string> $headers */
    private function stage_file_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-file-path', 'x-file-size', 'x-chunk-offset'], 'file');
        $path = $this->decode_path_header($headers, 'x-file-path');
        $total_bytes = $this->require_non_negative_header($headers, 'x-file-size');
        $offset = $this->require_non_negative_header($headers, 'x-chunk-offset');
        if ($offset > $total_bytes || $part_bytes > $total_bytes - $offset) {
            throw new InvalidArgumentException('File part for ' . base64_encode($path) . ' exceeds its declared total of ' . $total_bytes . ' bytes.');
        }
        $this->assert_target_parent_same_filesystem($path);
        $partial_path = $this->private_path($this->partial_dir, $path);
        $complete_path = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($partial_path);
        $this->ensure_private_parent($complete_path);

        $complete = $this->path_identity($complete_path);
        if ($complete !== null) {
            if ($offset === 0) {
                $this->remove_private_entry($complete_path);
            } elseif ($complete['type'] === 'file' && $complete['size'] === $total_bytes && $offset === $total_bytes && $part_bytes === 0) {
                if ($this->read_current_upload_body_piece() !== null) {
                    throw new LogicException('Multipart processor exposed file bytes for an empty completed-file replay.');
                }
                $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'file', 'accepted_bytes' => $total_bytes];
                return;
            } else {
                throw new InvalidArgumentException('Completed staged file ' . base64_encode($path) . ' can only be restarted at offset 0.');
            }
        }

        $partial = $this->path_identity($partial_path);
        if ($partial !== null && $partial['type'] !== 'file') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The partial path for ' . base64_encode($path) . ' is a ' . $partial['type'] . ', not a regular file.');
        }
        $actual_bytes = $partial === null ? 0 : $partial['size'];
        if ($offset === 0 && $actual_bytes !== 0) {
            if (!@unlink($partial_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not restart partial file ' . base64_encode($path) . ' at offset 0.');
            }
            $actual_bytes = 0;
        } elseif ($offset !== $actual_bytes) {
            throw new InvalidArgumentException('File part for ' . base64_encode($path) . ' starts at offset ' . $offset . ', but work/partial contains ' . $actual_bytes . ' bytes. Start at offset 0 or resume at the actual size.');
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
                if ($received > $part_bytes) {
                    throw new LogicException('Multipart processor exposed more file bytes than Content-Length.');
                }
                $this->write_all($handle, $piece, 'partial file ' . base64_encode($path));
            }
            if ($received !== $part_bytes) {
                throw new LogicException('Multipart processor exposed ' . $received . ' file bytes for Content-Length ' . $part_bytes . '.');
            }
            if (!fflush($handle)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not flush partial file ' . base64_encode($path) . '.');
            }
        } finally {
            fclose($handle);
        }
        $accepted_bytes = $actual_bytes + $received;
        if ($accepted_bytes === $total_bytes) {
            $this->rename_private($partial_path, $complete_path, 'promote completed staged file ' . base64_encode($path));
            $state = 'complete';
        } else {
            $state = 'partial';
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => $state, 'type' => 'file', 'accepted_bytes' => $accepted_bytes];
    }

    /** @param array<string,string> $headers */
    private function stage_directory_part(array $headers, int $part_bytes): void {
        $this->require_only_headers($headers, ['content-length', 'content-type', 'x-chunk-type', 'x-directory-path'], 'directory');
        if ($part_bytes !== 0 || $this->read_current_upload_body_piece() !== null) {
            throw new InvalidArgumentException('Multipart directory part must have Content-Length 0.');
        }
        $path = $this->decode_path_header($headers, 'x-directory-path');
        $this->assert_target_parent_same_filesystem($path);
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        $identity = $this->path_identity($target);
        if ($identity !== null && $identity['type'] === 'directory' && $this->first_directory_entry($target) !== null) {
            throw new InvalidArgumentException('Explicit empty directory ' . base64_encode($path) . ' conflicts with staged descendants.');
        }
        if ($identity !== null && $identity['type'] !== 'directory') {
            $this->remove_private_entry($target);
        }
        if (!is_dir($target) && !@mkdir($target, 0777)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not stage explicit empty directory ' . base64_encode($path) . '.');
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0];
    }

    /** @param array<string,string> $headers */
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
        $this->assert_target_parent_same_filesystem($path);
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        $identity = $this->path_identity($target);
        if ($identity !== null && $identity['type'] === 'directory' && $this->first_directory_entry($target) !== null) {
            throw new InvalidArgumentException('Staged symlink ' . base64_encode($path) . ' conflicts with staged descendants.');
        }
        if ($identity !== null) {
            $this->remove_private_entry($target);
        }
        if (!@symlink($target_value, $target)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not stage symlink ' . base64_encode($path) . '.');
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0];
    }

    /** @param array<string,string> $headers */
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
            $stored_bytes = $this->file_size_from_handle($handle, 'staged delete stream');
            if ($offset > $stored_bytes) {
                throw new InvalidArgumentException('Delete-list part starts at offset ' . $offset . ', but the target has stored ' . $stored_bytes . ' bytes.');
            }
            $position = $offset;
            $trailing_path = $this->read_delete_trailing_path($handle, $stored_bytes);
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
                    if (!hash_equals($stored, substr($piece, 0, $overlap))) {
                        throw new InvalidArgumentException('Delete-list replay differs from bytes already stored at offset ' . $position . '.');
                    }
                    $position += $overlap;
                    $piece_offset = $overlap;
                }
                if ($piece_offset < strlen($piece)) {
                    if ($position !== $stored_bytes) {
                        throw new LogicException('Delete-list append did not begin at the actual stored size.');
                    }
                    $append = substr($piece, $piece_offset);
                    $trailing_path = $this->validate_appended_delete_bytes($trailing_path, $append);
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
            $this->mark_delete_upload_complete();
        }
        $this->current_change = ['state' => $complete ? 'complete' : 'partial', 'type' => 'delete-list', 'accepted_bytes' => $stored_bytes];
    }

    /** @return array<string,mixed> */
    private function start_commit(): array {
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
            'files_applied' => 0,
        ];
        $this->write_json($this->commit_path, $state);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function advance_deletion(array &$state): void {
        if ($state['current_deletion_b64'] === null) {
            $record = $this->read_delete_record( (int) $state['delete_offset']);
            if ($record === null) {
                $state['phase'] = 'applying';
                $this->write_json($this->commit_path, $state);
                return;
            }
            $state['current_deletion_b64'] = base64_encode($record['path']);
            $this->write_json($this->commit_path, $state);
            return;
        }

        $path = $this->decode_commit_path($state['current_deletion_b64'], 'current deletion');
        $this->validate_path($path);
        $parent_device = $this->assert_live_ancestors($path, 'delete');
        if ($parent_device !== null) {
            $target = $this->target_path($path);
            $identity = $this->path_identity($target);
            if ($identity !== null) {
                if (!in_array($identity['type'], ['file', 'directory', 'symlink'], true)) {
                    $this->throw_live_tree_changed('delete', $path, $path, null, ['absent', 'file', 'directory', 'symlink'], $identity);
                }
                if ($identity['dev'] !== $parent_device) {
                    $this->throw_cross_device('delete', $path, $this->staging_device(), $identity['dev']);
                }
                $this->delete_one_entry($target, $path, $path, $parent_device);
            }
            if ($this->path_identity($target) !== null) {
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
     * @param string $requested_path Delete root used in conflict responses.
     */
    private function delete_one_entry(string $absolute_path, string $relative_path, string $requested_path, int $parent_device): void {
        $identity = $this->path_identity($absolute_path);
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

    /** @param array<string,mixed> $state */
    private function advance_installation(array &$state): int {
        if ($state['current_installation'] !== null) {
            return $this->resolve_current_installation($state);
        }

        $stack_size = count($state['traversal_stack']);
        if ($stack_size === 0) {
            $parent_path = '';
            $staged_directory = $this->files_dir;
        } else {
            $frame = $state['traversal_stack'][$stack_size - 1];
            $parent_path = $this->decode_commit_path($frame['path_b64'], 'traversal frame');
            $staged_directory = $this->private_path($this->files_dir, $parent_path);
        }
        $entry = $this->first_directory_entry($staged_directory);
        if ($entry === null) {
            if ($stack_size === 0) {
                $this->finish_commit($state);
                return 0;
            }
            return $this->cleanup_structural_directory($state, $parent_path, $staged_directory);
        }

        $path = $parent_path === '' ? $entry : $parent_path . '/' . $entry;
        $this->validate_path($path);
        $staged_path = $this->private_path($this->files_dir, $path);
        $identity = $this->path_identity($staged_path);
        if ($identity === null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Selected staged path disappeared before installation: ' . base64_encode($path) . '.');
        }
        if ($identity['type'] === 'directory' && $this->first_directory_entry($staged_path) !== null) {
            $state['traversal_stack'][] = ['path_b64' => base64_encode($path), 'kind' => 'structural'];
            $this->write_json($this->commit_path, $state);
            $this->prepare_structural_directory($path, $this->first_staged_leaf_path($staged_path, $path));
            return 0;
        }
        if (!in_array($identity['type'], ['file', 'directory', 'symlink'], true)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Staged path ' . base64_encode($path) . ' has unsupported type ' . $identity['type'] . '.');
        }
        return $this->install_staged_value($state, $path, $identity['type'], false);
    }

    /** @param array<string,mixed> $state */
    private function cleanup_structural_directory(array &$state, string $path, string $staged_path): int {
        $live = $this->path_identity($this->target_path($path));
        if ($live === null || $live['type'] !== 'directory') {
            $this->throw_live_tree_changed('install', $path, $path, 'directory', ['directory'], $live);
        }
        $state['current_installation'] = ['path_b64' => base64_encode($path), 'expected_type' => 'directory'];
        $this->write_json($this->commit_path, $state);
        if (!@rmdir($staged_path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not consume empty structural staging directory ' . base64_encode($path) . '.');
        }
        $state['current_installation'] = null;
        array_pop($state['traversal_stack']);
        ++$state['files_applied'];
        $this->write_json($this->commit_path, $state);
        return 1;
    }

    private function prepare_structural_directory(string $path, string $requested_path): void {
        $parent_device = $this->assert_live_ancestors($path, 'install', 'directory');
        $live_path = $this->target_path($path);
        $live = $this->path_identity($live_path);
        if ($live === null) {
            if (!@mkdir($live_path, 0777) && !is_dir($live_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not create structural live directory ' . base64_encode($path) . '.');
            }
            $live = $this->path_identity($live_path);
        }
        if ($live === null || $live['type'] !== 'directory') {
            $this->throw_live_tree_changed('install', $requested_path, $path, 'directory', ['absent', 'directory'], $live);
        }
        if ($live['dev'] !== $parent_device || $live['dev'] !== $this->staging_device()) {
            $this->throw_cross_device('install', $path, $this->staging_device(), $live['dev']);
        }
    }

    /** @param array<string,mixed> $state */
    private function install_staged_value(array &$state, string $path, string $expected_type, bool $recovering): int {
        $staged_path = $this->private_path($this->files_dir, $path);
        $staged = $this->path_identity($staged_path);
        if ($staged === null || $staged['type'] !== $expected_type) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Staged ' . $expected_type . ' ' . base64_encode($path) . ' is not present for installation.');
        }
        $parent_device = $this->assert_live_ancestors($path, 'install', $expected_type);
        $live_path = $this->target_path($path);
        $live = $this->path_identity($live_path);
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
        $this->rename_into_live($staged_path, $live_path, $path, $staged['dev'], $parent_device);
        $state['current_installation'] = null;
        ++$state['files_applied'];
        $this->write_json($this->commit_path, $state);
        return 1;
    }

    /** @param array<string,mixed> $state */
    private function resolve_current_installation(array &$state): int {
        $installation = $state['current_installation'];
        $path = $this->decode_commit_path($installation['path_b64'], 'current installation');
        $expected_type = $installation['expected_type'];
        $stack_size = count($state['traversal_stack']);
        $structural_cleanup = false;
        if ($stack_size > 0) {
            $top = $state['traversal_stack'][$stack_size - 1];
            $structural_cleanup = hash_equals($top['path_b64'], $installation['path_b64']);
        }
        $staged_path = $this->private_path($this->files_dir, $path);
        $staged = $this->path_identity($staged_path);
        $live = $this->path_identity($this->target_path($path));

        if ($structural_cleanup) {
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
            ++$state['files_applied'];
            $this->write_json($this->commit_path, $state);
            return 1;
        }

        if ($staged !== null) {
            return $this->install_staged_value($state, $path, $expected_type, true);
        }
        if ($live === null || $live['type'] !== $expected_type) {
            $this->throw_live_tree_changed('install', $path, $path, $expected_type, [$expected_type], $live);
        }
        $state['current_installation'] = null;
        ++$state['files_applied'];
        $this->write_json($this->commit_path, $state);
        return 1;
    }

    /** @param array<string,mixed> $state */
    private function finish_commit(array &$state): void {
        if ($state['current_deletion_b64'] !== null || $state['current_installation'] !== null || $state['traversal_stack'] !== []) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion with active bounded work state.');
        }
        if ( (int) $state['delete_offset'] !== $this->file_size($this->deletes_path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion before consuming the complete delete stream.');
        }
        if ($this->first_directory_entry($this->files_dir) !== null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit reached completion while work/files still contains pending values.');
        }
        $this->remove_owned_maintenance_marker($state);
        $state['phase'] = 'complete';
        $this->write_json($this->commit_path, $state);
        $this->release_target();
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function commit_result(array $state, int $files_applied): array {
        $complete = $state['phase'] === 'complete';
        $result = [
            'phase' => $state['phase'],
            'files_applied' => $files_applied,
            'deletions_applied' => (int) $state['deletions_applied'],
            'errors' => [],
            'send_next_request' => !$complete,
        ];
        if ($complete) {
            $result['files_remaining'] = 0;
        }
        return $result;
    }

    /** @param array<string,mixed> $terminal_error */
    private function throw_terminal_error(array $terminal_error): void {
        throw new Site_Export_Staged_Apply_Exception(
            $terminal_error['reason'],
            $terminal_error['detail'],
            $terminal_error['context']
        );
    }

    /**
     * Validates existing live ancestors without following a symlink.
     *
     * @return int|null Device of the nearest real parent, or null when a
     *                  deletion root is already absent below a missing parent.
     */
    private function assert_live_ancestors(string $path, string $operation, ?string $staged_type = null): ?int {
        $root = $this->path_identity($this->target_root);
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
            $identity = $this->path_identity($absolute);
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

    /** Rejects separately mounted nearest live parents before maintenance starts. */
    private function assert_target_parent_same_filesystem(string $path): void {
        $staging_device = $this->staging_device();
        $root = $this->path_identity($this->target_root);
        if ($root === null || $root['type'] !== 'directory') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The managed live root is no longer a real directory.');
        }
        if ($root['dev'] !== $staging_device) {
            $this->throw_cross_device('stage', $path, $staging_device, $root['dev']);
        }
        $absolute = $this->target_root;
        $device = $root['dev'];
        $segments = explode('/', $path);
        array_pop($segments);
        foreach ($segments as $segment) {
            $absolute .= ( $absolute === '/' ? '' : '/' ) . $segment;
            $identity = $this->path_identity($absolute);
            if ($identity === null) {
                break;
            }
            if ($identity['dev'] !== $device || $identity['dev'] !== $staging_device) {
                $this->throw_cross_device('stage', $path, $staging_device, $identity['dev']);
            }
            if ($identity['type'] !== 'directory') {
                break;
            }
            $device = $identity['dev'];
        }
    }

    private function rename_into_live(string $staged_path, string $live_path, string $relative_path, int $staging_device, int $live_device): void {
        error_clear_last();
        if (@rename($staged_path, $live_path)) {
            return;
        }
        $last_error = error_get_last();
        $message = is_array($last_error) ? $last_error['message'] : '';
        $observed_live = $this->path_identity($live_path);
        if ($observed_live !== null && $observed_live['dev'] !== $staging_device) {
            $this->throw_cross_device('install', $relative_path, $staging_device, $observed_live['dev']);
        }
        if (stripos($message, 'cross-device') !== false || stripos($message, 'exdev') !== false) {
            $this->throw_cross_device('install', $relative_path, $staging_device, $live_device);
        }
        throw new Site_Export_Staged_Apply_Exception(
            self::ERROR_RETRYABLE_IO,
            'Could not rename staged ' . base64_encode($relative_path) . ' directly into the live tree'
            . ( $message === '' ? '.' : ': ' . $message )
        );
    }

    /**
     * @param string[] $expected_live_types
     * @param array<string,mixed>|null $observed_identity
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

    private function assert_same_filesystem(string $staging_path, string $live_path, string $operation, string $relative_path): void {
        $staging = $this->path_identity($staging_path);
        $live = $this->path_identity($live_path);
        if ($staging === null || $live === null) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not determine the staging and live filesystem devices.');
        }
        if ($staging['dev'] !== $live['dev']) {
            $this->throw_cross_device($operation, $relative_path, $staging['dev'], $live['dev']);
        }
    }

    private function staging_device(): int {
        $identity = $this->path_identity($this->files_dir);
        if ($identity === null || $identity['type'] !== 'directory') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'work/files is not a real staging directory.');
        }
        return $identity['dev'];
    }

    /** @return array<string,mixed>|null */
    private function read_delete_record(int $offset): ?array {
        $size = $this->file_size($this->deletes_path);
        if ($offset === $size) {
            return null;
        }
        if ($offset < 0 || $offset > $size) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Delete-consumption offset ' . $offset . ' is outside the ' . $size . '-byte stream.');
        }
        $handle = @fopen($this->deletes_path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
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
                        throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The staged delete stream contains an empty record at offset ' . $offset . '.');
                    }
                    $this->validate_path($path);
                    return ['path' => $path];
                }
                $path .= $byte;
                ++$path_bytes;
            }
        } finally {
            fclose($handle);
        }
        throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'A staged delete path exceeds ' . self::MAX_PATH_BYTES . ' bytes.');
    }

    /** @param resource $handle */
    private function read_delete_trailing_path($handle, int $stored_bytes): string {
        if ($stored_bytes === 0) {
            return '';
        }
        $suffix_bytes = min($stored_bytes, self::MAX_PATH_BYTES + 1);
        if (fseek($handle, $stored_bytes - $suffix_bytes) !== 0) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not inspect the staged delete-stream suffix.');
        }
        $suffix = $this->read_exact($handle, $suffix_bytes, 'staged delete-stream suffix');
        $last_nul = strrpos($suffix, "\0");
        $trailing = $last_nul === false ? $suffix : substr($suffix, $last_nul + 1);
        if ($last_nul === false && $stored_bytes > self::MAX_PATH_BYTES) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'The incomplete staged delete path already exceeds ' . self::MAX_PATH_BYTES . ' bytes.');
        }
        return $trailing;
    }

    private function validate_appended_delete_bytes(string $trailing_path, string $bytes): string {
        $length = strlen($bytes);
        for ($index = 0; $index < $length; ++$index) {
            if ($bytes[$index] === "\0") {
                if ($trailing_path === '') {
                    throw new InvalidArgumentException('Delete-list parts may not contain an empty deletion record.');
                }
                $this->validate_path($trailing_path);
                $this->assert_target_parent_same_filesystem($trailing_path);
                $trailing_path = '';
                continue;
            }
            $trailing_path .= $bytes[$index];
            if (strlen($trailing_path) > self::MAX_PATH_BYTES) {
                throw new InvalidArgumentException('Delete-list path exceeds the maximum of ' . self::MAX_PATH_BYTES . ' bytes.');
            }
        }
        return $trailing_path;
    }

    /** @param resource $handle */
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

    /** @return string|null */
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

    /** Returns a non-directory descendant, ignoring empty structural parents. */
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
                $identity = $this->path_identity($path);
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

    /** Returns one staged leaf so a structural-ancestor conflict names requested work. */
    private function first_staged_leaf_path(string $directory, string $relative_path): string {
        $entry = $this->first_directory_entry($directory);
        if ($entry === null) {
            return $relative_path;
        }
        $child_path = $relative_path . '/' . $entry;
        $identity = $this->path_identity($directory . '/' . $entry);
        if ($identity !== null && $identity['type'] === 'directory') {
            return $this->first_staged_leaf_path($directory . '/' . $entry, $child_path);
        }
        return $child_path;
    }

    private function publish_or_refresh_maintenance_marker(array $state): void {
        $token = $state['maintenance_token'];
        $live_path = $this->target_path('.maintenance');
        $identity = $this->path_identity($live_path);
        if ($identity !== null && !$this->maintenance_marker_is_owned($live_path, $token)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'A foreign WordPress maintenance marker already exists. Retry after its owner removes it.');
        }
        $contents = $this->maintenance_marker_contents($token);
        $this->write_atomic_file($this->maintenance_identity_path, $contents, 0600);
        $this->write_atomic_file($live_path, $contents, 0644);
    }

    private function maintenance_marker_contents(string $token): string {
        return "<?php\n"
            . "\$reprint_staged_apply_request = (isset(\$_GET['reprint-api']) || isset(\$_GET['site-export-api']))\n"
            . "    && isset(\$_GET['endpoint']) && is_string(\$_GET['endpoint'])\n"
            . "    && strpos(\$_GET['endpoint'], 'staged_session_') === 0;\n"
            . "if (!\$reprint_staged_apply_request) {\n"
            . "    \$upgrading = " . time() . ";\n"
            . "}\n"
            . "unset(\$reprint_staged_apply_request);\n"
            . "// reprint-staged-session:" . $this->session_id . ':' . $token . "\n";
    }

    private function maintenance_marker_is_owned(string $path, string $token): bool {
        $contents = @file_get_contents($path, false, null, 0, 512);
        return is_string($contents)
            && strpos($contents, '// reprint-staged-session:' . $this->session_id . ':' . $token . "\n") !== false;
    }

    /** Marker removal remains retryable and precedes the complete checkpoint. */
    private function remove_owned_maintenance_marker(array $state): void {
        $live_path = $this->target_path('.maintenance');
        $identity = $this->path_identity($live_path);
        if ($identity !== null) {
            if (!$this->maintenance_marker_is_owned($live_path, $state['maintenance_token'])) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'The session-owned maintenance marker was replaced by another owner.');
            }
            if (!@unlink($live_path)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove the session-owned WordPress maintenance marker.');
            }
        }
        if ($this->path_identity($this->maintenance_identity_path) !== null && !@unlink($this->maintenance_identity_path)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not remove the private maintenance ownership marker.');
        }
    }

    private function claim_target(): void {
        $this->with_target_lock(function (): void {
            $active_path = $this->storage_dir . '/apply-sessions/target.active';
            $active = @file_get_contents($active_path);
            if (is_string($active) && trim($active) !== '' && trim($active) !== $this->session_id) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Another staged apply session is already committing this target: ' . trim($active) . '.');
            }
            $this->write_target_coordinator_file($active_path, $this->session_id . "\n");
        });
    }

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

    private function write_target_coordinator_file(string $path, string $contents): void {
        if (dirname($path) !== $this->storage_dir . '/apply-sessions') {
            throw new LogicException('The target coordinator path escaped its storage directory.');
        }
        $this->write_atomic_file($path, $contents, 0600);
    }

    /** @return mixed */
    private function with_session_lock(callable $callback) {
        if (!is_dir($this->session_dir)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_SESSION_NOT_FOUND, 'The staged apply session does not exist: ' . $this->session_id . '.');
        }
        $lock = @fopen($this->lock_path, 'r+b');
        if ($lock === false) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not open the staged apply session lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_BUSY, 'Staged apply session ' . $this->session_id . ' is busy. Retry the request.');
            }
            $this->assert_workspace_layout();
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed>|null */
    private function read_json(string $path): ?array {
        $identity = $this->path_identity($path);
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

    /** @param array<string,mixed> $value */
    private function write_json(string $path, array $value): void {
        $contents = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($contents)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Could not encode bounded staged apply metadata.');
        }
        $this->write_atomic_file($path, $contents, 0600);
    }

    private function write_atomic_file(string $path, string $contents, int $permissions): void {
        $temporary = $path . '.tmp-' . $this->session_id;
        if ($this->path_identity($temporary) !== null && !@unlink($temporary)) {
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

    /** @param resource $handle */
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

    /** @param array<string,mixed> $state */
    private function require_valid_commit_state(array $state): void {
        if (( $state['version'] ?? null ) !== 2) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an unsupported version.');
        }
        if (!in_array($state['phase'] ?? null, ['deleting', 'applying', 'complete'], true)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid phase.');
        }
        foreach (['delete_offset', 'deletions_applied', 'files_applied'] as $field) {
            if (!isset($state[$field]) || !is_int($state[$field]) || $state[$field] < 0) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint field ' . $field . ' must be a non-negative integer.');
            }
        }
        if (!is_string($state['maintenance_token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['maintenance_token']) !== 1) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid maintenance token.');
        }
        if (( $state['current_deletion_b64'] ?? null ) !== null) {
            $this->decode_commit_path($state['current_deletion_b64'], 'current deletion');
        }
        $installation = $state['current_installation'] ?? null;
        if ($installation !== null) {
            if (!is_array($installation) || !is_string($installation['path_b64'] ?? null)
                || !in_array($installation['expected_type'] ?? null, ['file', 'directory', 'symlink'], true)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid current installation.');
            }
            $this->decode_commit_path($installation['path_b64'], 'current installation');
        }
        if (!is_array($state['traversal_stack'] ?? null) || count($state['traversal_stack']) > self::MAX_PATH_BYTES) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid traversal stack.');
        }
        foreach ($state['traversal_stack'] as $frame) {
            if (!is_array($frame) || ( $frame['kind'] ?? null ) !== 'structural' || !is_string($frame['path_b64'] ?? null)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid structural traversal frame.');
            }
            $this->decode_commit_path($frame['path_b64'], 'traversal frame');
        }
        if (isset($state['terminal_error'])) {
            $error = $state['terminal_error'];
            if (!is_array($error) || !in_array($error['reason'] ?? null, [self::ERROR_LIVE_TREE_CHANGED, self::ERROR_CROSS_DEVICE_FILESYSTEM], true)
                || !is_string($error['detail'] ?? null) || !is_array($error['context'] ?? null)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Commit checkpoint has an invalid terminal error.');
            }
        }
    }

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

    private function assert_workspace_layout(): void {
        foreach ([$this->session_dir, $this->work_dir, $this->files_dir, $this->partial_dir] as $directory) {
            $identity = $this->path_identity($directory);
            if ($identity === null || $identity['type'] !== 'directory') {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Required staged apply directory is missing or not real: ' . $directory . '.');
            }
        }
        foreach ([$this->session_metadata_path, $this->lock_path, $this->deletes_path] as $file) {
            $identity = $this->path_identity($file);
            if ($identity === null || $identity['type'] !== 'file') {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Required staged apply file is missing or not regular: ' . $file . '.');
            }
        }
        foreach ([$this->commit_path, $this->maintenance_identity_path] as $optional_file) {
            $identity = $this->path_identity($optional_file);
            if ($identity !== null && $identity['type'] !== 'file') {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Optional staged apply file has an unsupported type: ' . $optional_file . '.');
            }
        }
    }

    private function read_session_metadata(): void {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata) || ( $metadata['version'] ?? null ) !== 2 || ( $metadata['session_id'] ?? null ) !== $this->session_id
            || !is_bool($metadata['delete_upload_complete'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata has an unsupported version or session identity.');
        }
        $target = base64_decode( (string) ( $metadata['target_root_b64'] ?? '' ), true);
        $protected = [];
        foreach (( $metadata['protected_paths_b64'] ?? [] ) as $encoded) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($decoded)) {
                throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata contains an invalid protected path.');
            }
            $protected[] = $decoded;
        }
        if ($target !== $this->target_root || $protected !== $this->protected_paths) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata does not match the current apply configuration.');
        }
    }

    private function delete_upload_is_complete(): bool {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata) || !is_bool($metadata['delete_upload_complete'] ?? null)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata has no valid delete-upload completion state.');
        }
        return $metadata['delete_upload_complete'];
    }

    private function mark_delete_upload_complete(): void {
        $metadata = $this->read_json($this->session_metadata_path);
        if (!is_array($metadata)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Session metadata is missing while completing the delete upload.');
        }
        $metadata['delete_upload_complete'] = true;
        $this->write_json($this->session_metadata_path, $metadata);
    }

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

    /** @param array<string,string> $headers */
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

    /** @param array<string,string> $headers @param string[] $allowed */
    private function require_only_headers(array $headers, array $allowed, string $type): void {
        foreach ($headers as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException('Multipart ' . $type . ' part does not allow header ' . json_encode($name) . '.');
            }
        }
    }

    /** @param array<string,string> $headers */
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

    private function target_path(string $relative_path): string {
        return $this->target_root . ( $this->target_root === '/' ? '' : '/' ) . $relative_path;
    }

    private function private_path(string $root, string $relative_path): string {
        return $root . '/' . $relative_path;
    }

    /** Creates missing private structural parents and rejects links or files. */
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
            $identity = $this->path_identity($current);
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

    /** @return array<string,mixed>|null */
    private function path_identity(string $path): ?array {
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

    private function remove_private_entry(string $path): void {
        $identity = $this->path_identity($path);
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

    private function rename_private(string $source, string $destination, string $description): void {
        if (!@rename($source, $destination)) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not ' . $description . '.');
        }
    }

    private function file_size(string $path): int {
        $identity = $this->path_identity($path);
        if ($identity === null || $identity['type'] !== 'file') {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_INVALID_STATE, 'Expected a regular file at ' . $path . '.');
        }
        return $identity['size'];
    }

    /** @param resource $handle */
    private function file_size_from_handle($handle, string $description): int {
        $stat = fstat($handle);
        if (!is_array($stat) || !isset($stat['size'])) {
            throw new Site_Export_Staged_Apply_Exception(self::ERROR_RETRYABLE_IO, 'Could not determine the actual size of ' . $description . '.');
        }
        return (int) $stat['size'];
    }

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

    /** @param string[] $protected_paths @return string[] */
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

    private static function require_session_id(string $session_id): void {
        if (preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Staged apply session id must be a 32-character lowercase hexadecimal string.');
        }
    }

    /** @param string[] $protected_paths @return string[] */
    private static function normalize_protected_paths(array $protected_paths): array {
        $normalized = [];
        foreach ($protected_paths as $path) {
            if (!is_string($path) || $path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
                throw new InvalidArgumentException('Each protected staged apply path must be a non-empty safe relative path.');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('Protected staged apply path is unsafe: ' . base64_encode($path) . '.');
                }
            }
            $normalized[] = $path;
        }
        sort($normalized, SORT_STRING);
        return array_values(array_unique($normalized));
    }

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
