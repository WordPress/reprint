<?php

if (!class_exists('Site_Export_Multipart_Stream_Input', false)) {
    require_once __DIR__ . '/class-multipart-stream-input.php';
}

/**
 * One private, resumable push workspace.
 *
 * Uploads only mutate work/. Files become commit-ready when they move from
 * work/partial/ into work/files/. commit.json is deliberately the only
 * mutable commit checkpoint; session.json identifies immutable session
 * configuration and is never an upload cursor or a positive-change journal.
 */
final class Site_Export_Staged_Apply_Session {

    public const ERROR_BUSY = 1001;
    public const ERROR_SESSION_NOT_FOUND = 1002;
    public const ERROR_RETRYABLE_IO = 1003;
    public const ERROR_COMMIT_REQUIRED = 1004;
    public const ERROR_LIVE_TREE_CHANGED = 1005;
    public const ERROR_INVALID_STATE = 1006;

    private const MAX_PATH_BYTES = 4096;
    private const BODY_PIECE_BYTES = 262144;
    private const MAX_METADATA_BYTES = 1048576;

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
    private $prepared_dir;
    /** @var string */
    private $backups_dir;
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
    /** @var Site_Export_Multipart_Stream_Input|null */
    private $upload_input = null;
    /** @var array<string,mixed>|null */
    private $current_change = null;
    /** @var int */
    private $maximum_upload_part_bytes = PHP_INT_MAX;
    /** @var int */
    private $maximum_upload_parts = 128;
    /** @var int */
    private $upload_parts_read = 0;

    /** @param string[] $protected_paths */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths) {
        $this->storage_dir = rtrim($storage_dir, '/');
        $this->target_root = rtrim($target_root, '/');
        $this->session_id = $session_id;
        $this->protected_paths = $protected_paths;
        $this->session_dir = $this->storage_dir . '/apply-sessions/' . $session_id;
        $this->work_dir = $this->session_dir . '/work';
        $this->files_dir = $this->work_dir . '/files';
        $this->partial_dir = $this->work_dir . '/partial';
        $this->prepared_dir = $this->work_dir . '/prepared';
        $this->backups_dir = $this->work_dir . '/backups';
        $this->deletes_path = $this->work_dir . '/deletes.jsonl';
        $this->session_metadata_path = $this->session_dir . '/session.json';
        $this->commit_path = $this->session_dir . '/commit.json';
        $this->lock_path = $this->session_dir . '/lock';
        $this->maintenance_identity_path = $this->work_dir . '/maintenance.php';
    }

    /**
     * Create or reopen the server-derived session id for one create token.
     *
     * @param string[] $protected_paths
     */
    public static function create(string $storage_dir, string $target_root, array $protected_paths, string $session_id): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', true);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::protect_session_storage($storage_dir, $target_root, $protected_paths);
        self::require_same_filesystem($storage_dir, $target_root);
        $protected_paths = self::normalize_protected_paths($protected_paths);

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
            $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
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
            $session->assert_workspace_layout();
            $session->write_json($session->session_metadata_path, [
                'version' => 1,
                'session_id' => $session_id,
                'target_root_b64' => base64_encode($target_root),
                'protected_paths_b64' => array_map('base64_encode', $protected_paths),
            ]);
            return $session;
        } finally {
            flock($creation_lock, LOCK_UN);
            fclose($creation_lock);
        }
    }

    /**
     * Opens an existing workspace only when its private layout is intact and
     * its immutable target and protected paths still match server configuration.
     *
     * @param string[] $protected_paths
     */
    public static function open(string $storage_dir, string $target_root, string $session_id, array $protected_paths): self {
        self::require_session_id($session_id);
        $storage_dir = self::require_directory($storage_dir, 'session storage', false);
        $target_root = self::require_directory($target_root, 'apply target root', false);
        $protected_paths = self::protect_session_storage($storage_dir, $target_root, $protected_paths);
        self::require_same_filesystem($storage_dir, $target_root);
        $sessions_dir = self::require_directory($storage_dir . '/apply-sessions', 'staged apply sessions', false);
        self::require_same_filesystem($sessions_dir, $target_root);
        $session = new self($storage_dir, $target_root, $session_id, self::normalize_protected_paths($protected_paths));
        if (!file_exists($session->session_dir) && !is_link($session->session_dir)) {
            throw new RuntimeException('The staged apply session does not exist: ' . $session_id . '.', self::ERROR_SESSION_NOT_FOUND);
        }
        $session->assert_workspace_layout();
        $session->read_session_metadata();
        return $session;
    }

    public function get_session_id(): string {
        return $this->session_id;
    }

    public function get_session_directory(): string {
        return $this->session_dir;
    }

    /**
     * Accept one authenticated multipart request. The caller must always call
     * finish_upload(), including after next_change() throws.
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
     * Stage exactly one complete multipart part.
     *
     * False means the request ended with a clean closing boundary. Invalid
     * input stops this request before later parts are read.
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

    /** Ends the upload request and releases its session lock. */
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

    /** @return array<string,mixed>|null */
    public function get_current_change(): ?array {
        return $this->current_change;
    }

    /**
     * Return only target-derived state for explicitly requested paths.
     *
     * @param string[] $paths raw target-relative paths
     * @return array<string,mixed>
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
     */
    private function stage_file_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-file-path', 'x-file-size', 'x-chunk-offset'], true)) {
                throw new InvalidArgumentException('Multipart file part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-file-path');
        $total_bytes = $this->require_non_negative_header($headers, 'x-file-size');
        $offset = $this->require_non_negative_header($headers, 'x-chunk-offset');
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

        $actual = $this->path_identity($partial_path);
        if ($actual === null || $actual['type'] !== 'file' || $actual['size'] !== $offset + $part_bytes) {
            throw new RuntimeException('Partial file size changed while staging ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        if ($actual['size'] === $total_bytes) {
            $this->ensure_private_parent($complete_path);
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

    /** Replaces a staged path with an explicitly empty final directory. */
    private function stage_directory_part(array $headers, int $part_bytes): void {
        foreach ($headers as $name => $unused) {
            if (!in_array($name, ['content-length', 'content-type', 'x-chunk-type', 'x-directory-path'], true)) {
                throw new InvalidArgumentException('Multipart directory part does not allow header ' . json_encode($name) . '.');
            }
        }
        $path = $this->decode_path_header($headers, 'x-directory-path');
        if ($part_bytes !== 0) {
            throw new InvalidArgumentException('Multipart directory part must have Content-Length 0.');
        }
        $target = $this->private_path($this->files_dir, $path);
        $this->ensure_private_parent($target);
        // A directory part represents an explicitly empty final directory,
        // not merely a request to create a missing parent.
        $this->remove_entry($target);
        if (!is_dir($target) && !@mkdir($target, 0700)) {
            throw new RuntimeException('Could not stage directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'directory', 'accepted_bytes' => 0];
    }

    /** Replaces a staged path with a symlink preserving its literal target. */
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
        if (!@symlink($target_value, $target)) {
            throw new RuntimeException('Could not stage symlink ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->current_change = ['path_b64' => base64_encode($path), 'state' => 'complete', 'type' => 'symlink', 'accepted_bytes' => 0];
    }

    /**
     * Appends complete NUL-delimited paths as JSONL records.
     *
     * A process death can leave only the last record incomplete; the next
     * request repairs that tail before accepting or reporting session state.
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
     * Advance preparation, switching, or cleanup by a bounded number of
     * deployment actions. The response deliberately counts only visible live
     * replacements as files_applied.
     *
     * @return array<string,mixed>
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
            while ($steps < $maximum_steps && $state['phase'] === 'preparing') {
                if ($state['prepare_index'] >= count($state['actions'])) {
                    $state['phase'] = 'switching';
                    $this->write_json($this->commit_path, $state);
                    break;
                }
                $this->prepare_action($state, (int) $state['prepare_index']);
                ++$state['prepare_index'];
                ++$steps;
                $this->write_json($this->commit_path, $state);
            }

            if ($state['phase'] === 'switching') {
                if ($state['switch_index'] < count($state['actions'])) {
                    // Detect a changed member of a prepared directory before
                    // maintenance is published. A directory's own ctime does
                    // not change when an existing child file is rewritten.
                    $this->require_prepared_live_tree($state, (int) $state['switch_index']);
                }
                $this->publish_or_refresh_maintenance_marker($state);
                while ($steps < $maximum_steps && $state['switch_index'] < count($state['actions'])) {
                    if ($this->switch_action($state, (int) $state['switch_index'])) {
                        ++$files_applied;
                    }
                    ++$state['switch_index'];
                    ++$steps;
                    $this->write_json($this->commit_path, $state);
                }
                if ($state['switch_index'] >= count($state['actions'])) {
                    $state['phase'] = 'cleaning';
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

            $remaining = max(0, count($state['actions']) - (int) $state['switch_index']);
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

    /** Removes a private session after upload release and before live mutation. */
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
                if (($state['phase'] ?? null) !== 'preparing') {
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
     * @return array<string,mixed>
     */
    private function start_commit(): array {
        $this->repair_delete_tail();
        $actions = $this->build_commit_actions();
        $state = [
            'version' => 1,
            'phase' => 'preparing',
            'actions' => $actions,
            'prepare_index' => 0,
            'switch_index' => 0,
            'transition' => null,
            'maintenance_token' => bin2hex(random_bytes(16)),
        ];
        $this->write_json($this->commit_path, $state);
        return $state;
    }

    /**
     * Builds the minimal set of live replacement actions for the final tree.
     *
     * Every changed plugin or theme member collapses into one complete unit
     * action. Generic paths remain individual entries unless a type change or
     * ancestor deletion requires reconstructing a complete subtree.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_commit_actions(): array {
        $staged_paths = $this->list_staged_paths($this->files_dir);
        $deleted_paths = $this->read_delete_paths();
        $actions = [];

        // Plugin and theme children always move with their complete root.
        // Generic paths only need a complete replacement tree when a final
        // child must replace a missing, non-directory, or deleted ancestor.
        foreach (array_merge(array_keys($staged_paths), array_keys($deleted_paths)) as $path) {
            $unit = $this->deployment_unit_for_path($path);
            if ($unit !== null) {
                $actions['unit:' . base64_encode($unit)] = [
                    'path_b64' => base64_encode($unit),
                    'kind' => 'unit',
                    'deleted' => false,
                ];
            }
        }

        foreach ($staged_paths as $path => $type) {
            if ($this->deployment_unit_for_path($path) !== null) {
                continue;
            }
            if (isset($deleted_paths[$path])) {
                throw new RuntimeException(
                    'The staged final tree both deletes and materializes ' . $this->describe_path($path) . '.',
                    self::ERROR_RETRYABLE_IO
                );
            }
            $tree_root = $this->structural_tree_root($path, $deleted_paths);
            if ($tree_root !== null) {
                $actions['tree:' . base64_encode($tree_root)] = [
                    'path_b64' => base64_encode($tree_root),
                    'kind' => 'tree',
                    'deleted' => false,
                ];
                continue;
            }
            $actions['entry:' . base64_encode($path)] = [
                'path_b64' => base64_encode($path),
                'kind' => 'entry',
                'deleted' => false,
            ];
        }

        foreach ($deleted_paths as $path => $unused) {
            if ($this->deployment_unit_for_path($path) !== null || $this->path_is_covered_by_action_tree($path, $actions)) {
                continue;
            }
            if ($this->has_staged_ancestor($path, $staged_paths) || $this->has_deleted_ancestor($path, $deleted_paths)) {
                continue;
            }
            $actions['entry:' . base64_encode($path)] = [
                'path_b64' => base64_encode($path),
                'kind' => 'entry',
                'deleted' => true,
            ];
        }

        $actions = array_values($actions);
        usort($actions, static function (array $left, array $right): int {
            $left_path = base64_decode((string) $left['path_b64'], true);
            $right_path = base64_decode((string) $right['path_b64'], true);
            if ($left_path === false || $right_path === false) {
                return strcmp((string) $left['path_b64'], (string) $right['path_b64']);
            }
            // Parents switch before their direct descendants when a source
            // contains an explicitly empty directory outside a deployment
            // unit.  The source normally supplies only such empty dirs.
            if (strpos($right_path, $left_path . '/') === 0) {
                return -1;
            }
            if (strpos($left_path, $right_path . '/') === 0) {
                return 1;
            }
            return strcmp($left_path, $right_path);
        });
        return $actions;
    }

    /**
     * Finds the highest ancestor that must be replaced as a complete tree.
     *
     * @param array<string,bool> $deleted_paths
     */
    private function structural_tree_root(string $path, array $deleted_paths): ?string {
        foreach (array_slice(explode('/', $path), 0, -1) as $index => $unused) {
            $ancestor = implode('/', array_slice(explode('/', $path), 0, $index + 1));
            if (isset($deleted_paths[$ancestor])) {
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
        foreach ($deleted_paths as $deleted_path => $unused) {
            if (strpos($deleted_path, $path . '/') === 0) {
                return $path;
            }
        }
        return null;
    }

    /** @param array<string,array<string,mixed>> $actions */
    private function path_is_covered_by_action_tree(string $path, array $actions): bool {
        foreach ($actions as $action) {
            if (($action['kind'] ?? null) !== 'unit' && ($action['kind'] ?? null) !== 'tree') {
                continue;
            }
            $root = base64_decode((string) ($action['path_b64'] ?? ''), true);
            if ($root !== false && ($path === $root || strpos($path, $root . '/') === 0)) {
                return true;
            }
        }
        return false;
    }

    private function is_protected_ancestor(string $path): bool {
        foreach ($this->protected_paths as $protected_path) {
            if (strpos($protected_path, $path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,string> $staged_paths */
    private function has_staged_ancestor(string $path, array $staged_paths): bool {
        foreach ($staged_paths as $staged_path => $type) {
            if ($path !== $staged_path && strpos($path, $staged_path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $deleted_paths */
    private function has_deleted_ancestor(string $path, array $deleted_paths): bool {
        foreach ($deleted_paths as $deleted_path => $unused) {
            if ($path !== $deleted_path && strpos($path, $deleted_path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Materializes one private candidate and checkpoints its live identity.
     *
     * The candidate is accepted only if the live entry and all descendants
     * retain the identities observed before preparation began.
     *
     * @param array<string,mixed> $state
     */
    private function prepare_action(array &$state, int $index): void {
        $action = $state['actions'][$index] ?? null;
        if (!is_array($action)) {
            throw new RuntimeException('Commit checkpoint has no action at index ' . $index . '.', self::ERROR_RETRYABLE_IO);
        }
        $path = base64_decode((string) ($action['path_b64'] ?? ''), true);
        if ($path === false || $path === '') {
            throw new RuntimeException('Commit checkpoint has an invalid action path.', self::ERROR_RETRYABLE_IO);
        }
        $this->validate_path($path);
        $this->assert_target_path_same_filesystem($path);

        $live_path = $this->target_path($path);
        $prepared_path = $this->private_path($this->prepared_dir, $path);
        // Record the live identity before copying it.  Preparation can span
        // substantial trees, so accepting a writer that changed the source
        // during that copy would create a candidate from an unknown mix.
        $expected_live = $this->path_identity($live_path);
        $expected_live_tree = $this->tree_fingerprint($live_path);
        $this->ensure_private_parent($prepared_path);
        $this->remove_entry($prepared_path);

        if ($action['kind'] === 'unit' || $action['kind'] === 'tree') {
            $this->prepare_complete_tree($path, $prepared_path);
        } elseif ($action['kind'] === 'entry' && !(bool) $action['deleted']) {
            $staged_path = $this->private_path($this->files_dir, $path);
            if ($this->path_identity($staged_path) === null) {
                throw new RuntimeException('Staged entry disappeared before commit: ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            $this->copy_entry($staged_path, $prepared_path);
        } elseif ($action['kind'] !== 'entry') {
            throw new RuntimeException('Commit checkpoint has an invalid action kind.', self::ERROR_RETRYABLE_IO);
        }

        if (
            $this->path_identity($live_path) !== $expected_live
            || $this->tree_fingerprint($live_path) !== $expected_live_tree
        ) {
            throw new RuntimeException(
                'Live entry changed while preparing ' . $this->describe_path($path)
                . '; refusing to switch a candidate built from an external writer.',
                self::ERROR_LIVE_TREE_CHANGED
            );
        }

        $state['actions'][$index]['expected_live'] = $expected_live;
        $state['actions'][$index]['expected_live_tree'] = $expected_live_tree;
        $state['actions'][$index]['prepared'] = $this->path_identity($prepared_path);
    }

    /**
     * Builds the final value of one complete replacement root.
     *
     * Directory changes start with the current live tree, overlay staged
     * descendants, and apply staged deletes. A staged file or symlink is
     * already a complete root value and is copied directly; it is never
     * reconstructed as a directory tree.
     */
    private function prepare_complete_tree(string $root_path, string $prepared_path): void {
        $staged_path = $this->private_path($this->files_dir, $root_path);
        $staged_identity = $this->path_identity($staged_path);
        $deleted_paths = $this->read_delete_paths();
        if (isset($deleted_paths[$root_path]) && $staged_identity === null) {
            return;
        }
        if ($staged_identity !== null && $staged_identity['type'] !== 'directory') {
            $this->copy_entry($staged_path, $prepared_path);
            return;
        }

        $live_path = $this->target_path($root_path);
        $live_identity = $this->path_identity($live_path);
        if ($live_identity !== null && $live_identity['type'] === 'directory') {
            $this->copy_entry($live_path, $prepared_path);
        } else {
            $this->ensure_private_parent($prepared_path);
            if (!@mkdir($prepared_path, 0700, true) && !is_dir($prepared_path)) {
                throw new RuntimeException('Could not create prepared deployment unit ' . $this->describe_path($root_path) . '.', self::ERROR_RETRYABLE_IO);
            }
        }
        if ($staged_identity !== null) {
            $this->overlay_directory($staged_path, $prepared_path);
        }
        foreach ($deleted_paths as $deleted_path => $unused) {
            if ($deleted_path === $root_path) {
                continue;
            }
            $prefix = $root_path . '/';
            if (strpos($deleted_path, $prefix) !== 0) {
                continue;
            }
            $relative = substr($deleted_path, strlen($prefix));
            $delete_path = $this->private_path($prepared_path, $relative);
            $this->ensure_private_parent($delete_path);
            $this->remove_entry($delete_path);
        }
    }

    /**
     * Advances one crash-recoverable two-rename live transition.
     *
     * @param array<string,mixed> $state
     */
    private function switch_action(array &$state, int $index): bool {
        $action = $state['actions'][$index] ?? null;
        if (!is_array($action)) {
            throw new RuntimeException('Commit checkpoint has no switch action at index ' . $index . '.', self::ERROR_RETRYABLE_IO);
        }
        $path = base64_decode((string) ($action['path_b64'] ?? ''), true);
        if ($path === false || $path === '') {
            throw new RuntimeException('Commit checkpoint has an invalid switch path.', self::ERROR_RETRYABLE_IO);
        }
        $this->validate_path($path);
        $live_path = $this->target_path($path);
        $prepared_path = $this->private_path($this->prepared_dir, $path);
        $backup_path = $this->private_path($this->backups_dir, $path);

        if (!is_array($state['transition'])) {
            $this->require_prepared_live_tree($state, $index);
            $state['transition'] = [
                'index' => $index,
                'stage' => 'prepared',
                'path_b64' => base64_encode($path),
                'expected_live' => $action['expected_live'] ?? null,
                'expected_live_tree' => $action['expected_live_tree'] ?? null,
                'prepared' => $action['prepared'] ?? null,
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
     */
    private function require_prepared_live_tree(array $state, int $index): void {
        // A durable transition already describes a live rename that may have
        // happened. Its recovery must use the recorded physical identities,
        // rather than reject the intentionally absent live path here.
        if (is_array($state['transition'] ?? null)) {
            return;
        }
        $action = $state['actions'][$index] ?? null;
        if (!is_array($action) || !array_key_exists('expected_live_tree', $action)) {
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
     * Reconcile exactly one two-rename transition after a process death.
     *
     * The checkpoint is written before the first rename.  We only continue
     * when the live, prepared, and backup identities prove which of those
     * renames completed; an outside writer is never guessed through.
     *
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
            foreach ([$expected_live, $prepared, $backup_expected, $installed] as $identity) {
                if ($identity !== null && !is_array($identity)) {
                    throw new RuntimeException('Commit transition contains an invalid filesystem identity.', self::ERROR_RETRYABLE_IO);
                }
            }
            $stage = $transition['stage'] ?? 'prepared';
            if (!is_string($stage) || !in_array($stage, ['prepared', 'backup', 'installed'], true)) {
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
                if ($stage === 'installed' && $live === $installed && $candidate === null && $backup === null) {
                    return;
                }
                throw new RuntimeException('Install transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            if ($prepared === null) {
                if ($stage === 'prepared' && $live === $expected_live && $backup === null && $candidate === null) {
                    $this->ensure_target_parent($live_path);
                    $this->ensure_private_parent($backup_path);
                    $this->rename_same_filesystem($live_path, $backup_path, 'move deleted entry into backup');
                    $this->record_transition_stage($state, 'backup', $this->path_identity($backup_path), null);
                    continue;
                }
                if ($stage === 'prepared' && $live === null && $this->same_physical_entry($backup, $expected_live) && $candidate === null) {
                    $this->record_transition_stage($state, 'backup', $backup, null);
                    continue;
                }
                if ($stage === 'backup' && $live === null && $backup === $backup_expected && $candidate === null) {
                    return;
                }
                throw new RuntimeException('Delete transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
            }

            if ($stage === 'prepared' && $live === $expected_live && $backup === null && $candidate === $prepared) {
                $this->ensure_target_parent($live_path);
                $this->ensure_private_parent($backup_path);
                $this->rename_same_filesystem($live_path, $backup_path, 'move replaced entry into backup');
                $this->record_transition_stage($state, 'backup', $this->path_identity($backup_path), null);
                continue;
            }
            if ($stage === 'prepared' && $live === null && $this->same_physical_entry($backup, $expected_live) && $candidate === $prepared) {
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
            if ($stage === 'installed' && $live === $installed && $backup === $backup_expected && $candidate === null) {
                return;
            }
            throw new RuntimeException('Replacement transition has unexpected live, prepared, or backup state.', self::ERROR_LIVE_TREE_CHANGED);
        }
    }

    /**
     * Checkpoints the identities observed after one rename and before the next.
     *
     * @param array<string,mixed> $state
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

    /**
     * Compare the stable identity of a path across a rename.
     *
     * A rename can change ctime, so the full durable identity used for
     * ordinary outside-writer detection cannot identify the same entry in
     * the small window between rename() and the next checkpoint write.
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
            $contents = "<?php\n\$upgrading = " . time() . ";\n// reprint-staged-session:" . $this->session_id . ':' . $token . "\n";
            $this->write_atomic_file($this->maintenance_identity_path, $contents, 0600);
        }
        if (!$this->maintenance_marker_is_owned($this->maintenance_identity_path, $token)) {
            throw new RuntimeException('The private maintenance marker identity is not owned by this session.', self::ERROR_RETRYABLE_IO);
        }

        if ($live_identity === null) {
            $temporary_path = $live_path . '.reprint-' . $this->session_id;
            if ($this->path_identity($temporary_path) !== null) {
                throw new RuntimeException('Refusing to replace an unexpected maintenance marker temporary path.', self::ERROR_RETRYABLE_IO);
            }
            if (!@link($this->maintenance_identity_path, $temporary_path)) {
                throw new RuntimeException('Could not link the private maintenance marker into the target root.', self::ERROR_RETRYABLE_IO);
            }
            if (!@rename($temporary_path, $live_path)) {
                @unlink($temporary_path);
                throw new RuntimeException('Could not publish the WordPress maintenance marker.', self::ERROR_RETRYABLE_IO);
            }
        } elseif (!@touch($live_path)) {
            throw new RuntimeException('Could not refresh the WordPress maintenance marker.', self::ERROR_RETRYABLE_IO);
        }

        if (!$this->maintenance_marker_is_owned($live_path, $token)) {
            throw new RuntimeException('The WordPress maintenance marker changed while it was being refreshed.', self::ERROR_BUSY);
        }
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

    /** Checks both the hard-linked identity and the embedded session token. */
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

    /** Claim the one target-wide coordinator before preparation begins. */
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

    /** Release only this session's target-wide coordinator claim. */
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

    /** Publishes target-wide coordinator metadata through a temporary rename. */
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

    /** @return array<string,string> */
    private function list_staged_paths(string $directory): array {
        $identity = $this->path_identity($directory);
        if ($identity === null) {
            return [];
        }
        if ($identity['type'] !== 'directory') {
            throw new RuntimeException('Staged files root is not a directory.', self::ERROR_RETRYABLE_IO);
        }
        $paths = [];
        $this->collect_staged_paths($directory, '', $paths);
        return $paths;
    }

    /**
     * Collects staged leaves and explicitly empty directories.
     *
     * Non-empty directories are structural parents rather than independent
     * final entries, so their descendants alone enter the action planner.
     *
     * @param array<string,string> $paths
     */
    private function collect_staged_paths(string $directory, string $prefix, array &$paths): void {
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('Could not read staged directory ' . $directory . '.', self::ERROR_RETRYABLE_IO);
        }
        $children = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $children[] = $entry;
        }
        if ($prefix !== '' && $children === []) {
            $paths[$prefix] = 'directory';
            return;
        }
        foreach ($children as $entry) {
            $path = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $full_path = $directory . '/' . $entry;
            $identity = $this->path_identity($full_path);
            if ($identity === null) {
                throw new RuntimeException('Staged path disappeared while building a commit: ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($identity['type'] === 'directory') {
                $this->collect_staged_paths($full_path, $path, $paths);
                continue;
            }
            if ($identity['type'] !== 'file' && $identity['type'] !== 'symlink') {
                throw new RuntimeException('Staged path has an unsupported type: ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            $paths[$path] = $identity['type'];
        }
    }

    /** @return array<string,bool> */
    private function read_delete_paths(): array {
        $this->repair_delete_tail();
        if ($this->path_identity($this->deletes_path) === null) {
            return [];
        }
        $handle = @fopen($this->deletes_path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the staged delete list.', self::ERROR_RETRYABLE_IO);
        }
        $paths = [];
        try {
            while (($line = fgets($handle, 8193)) !== false) {
                if (strlen($line) > 8192 || substr($line, -1) !== "\n") {
                    throw new RuntimeException('The staged delete list has an invalid record length.', self::ERROR_RETRYABLE_IO);
                }
                $record = json_decode(substr($line, 0, -1), true);
                if (!is_array($record) || !isset($record['path_b64']) || !is_string($record['path_b64'])) {
                    throw new RuntimeException('The staged delete list contains an invalid record.', self::ERROR_RETRYABLE_IO);
                }
                $path = base64_decode($record['path_b64'], true);
                if ($path === false || $path === '') {
                    throw new RuntimeException('The staged delete list contains an invalid path.', self::ERROR_RETRYABLE_IO);
                }
                $this->validate_path($path);
                $paths[$path] = true;
            }
        } finally {
            fclose($handle);
        }
        return $paths;
    }

    /** Discard only an incomplete final JSONL record after a killed upload. */
    private function repair_delete_tail(): void {
        $identity = $this->path_identity($this->deletes_path);
        if ($identity === null) {
            return;
        }
        if ($identity['type'] !== 'file') {
            throw new RuntimeException('The staged delete list is not a regular file.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $identity['size'] === 0) {
            return;
        }
        $handle = @fopen($this->deletes_path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not repair the staged delete list.', self::ERROR_RETRYABLE_IO);
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
                    throw new RuntimeException('Could not seek the staged delete list for repair.', self::ERROR_RETRYABLE_IO);
                }
                $chunk = fread($handle, $position - $start);
                if (!is_string($chunk)) {
                    throw new RuntimeException('Could not read the staged delete list for repair.', self::ERROR_RETRYABLE_IO);
                }
                $newline = strrpos($chunk, "\n");
                if ($newline !== false) {
                    if (!ftruncate($handle, $start + $newline + 1) || !fflush($handle)) {
                        throw new RuntimeException('Could not repair the staged delete list tail.', self::ERROR_RETRYABLE_IO);
                    }
                    return;
                }
                $position = $start;
            }
            if (!ftruncate($handle, 0) || !fflush($handle)) {
                throw new RuntimeException('Could not clear an incomplete staged delete list record.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Clones one entry without following symlinks.
     *
     * Directories recurse, symlinks preserve their literal target, and regular
     * files are copied through bounded pieces rather than one in-memory string.
     */
    private function copy_entry(string $source, string $destination): void {
        $identity = $this->path_identity($source);
        if ($identity === null) {
            throw new RuntimeException('Source entry disappeared during commit preparation: ' . $source . '.', self::ERROR_RETRYABLE_IO);
        }
        $target_root_identity = $this->path_identity($this->target_root);
        if ($target_root_identity === null || $target_root_identity['type'] !== 'directory') {
            throw new RuntimeException('The apply target root is not a directory.', self::ERROR_RETRYABLE_IO);
        }
        if ((int) $identity['dev'] !== (int) $target_root_identity['dev']) {
            throw new RuntimeException(
                'Refusing to prepare entry on device ' . $identity['dev']
                . ' below a target rooted on device ' . $target_root_identity['dev'] . '.',
                self::ERROR_RETRYABLE_IO
            );
        }
        $this->ensure_private_parent($destination);
        $this->remove_entry($destination);
        if ($identity['type'] === 'directory') {
            if (!@mkdir($destination, 0700) && !is_dir($destination)) {
                throw new RuntimeException('Could not create prepared directory ' . $destination . '.', self::ERROR_RETRYABLE_IO);
            }
            $entries = @scandir($source);
            if (!is_array($entries)) {
                throw new RuntimeException('Could not read directory while preparing ' . $source . '.', self::ERROR_RETRYABLE_IO);
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->copy_entry($source . '/' . $entry, $destination . '/' . $entry);
            }
            return;
        }
        if ($identity['type'] === 'symlink') {
            $target = @readlink($source);
            if (!is_string($target) || !@symlink($target, $destination)) {
                throw new RuntimeException('Could not recreate prepared symlink ' . $source . '.', self::ERROR_RETRYABLE_IO);
            }
            return;
        }
        if ($identity['type'] !== 'file') {
            throw new RuntimeException('Could not prepare unsupported filesystem entry ' . $source . '.', self::ERROR_RETRYABLE_IO);
        }
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not open file while preparing ' . $source . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            while (!feof($input)) {
                $piece = fread($input, self::BODY_PIECE_BYTES);
                if ($piece === false) {
                    throw new RuntimeException('Could not read file while preparing ' . $source . '.', self::ERROR_RETRYABLE_IO);
                }
                if ($piece !== '') {
                    $this->write_all($output, $piece, 'prepared file ' . $source);
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('Could not flush prepared file ' . $destination . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Overlay staged descendants without following a live or staged link. */
    private function overlay_directory(string $source, string $destination): void {
        $identity = $this->path_identity($source);
        if ($identity === null || $identity['type'] !== 'directory') {
            throw new RuntimeException('Expected a staged directory while preparing a deployment unit.', self::ERROR_RETRYABLE_IO);
        }
        $entries = @scandir($source);
        if (!is_array($entries)) {
            throw new RuntimeException('Could not read staged directory ' . $source . '.', self::ERROR_RETRYABLE_IO);
        }
        $children = array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
        if ($children === []) {
            $this->copy_entry($source, $destination);
            return;
        }
        $destination_identity = $this->path_identity($destination);
        if ($destination_identity === null || $destination_identity['type'] !== 'directory') {
            $this->ensure_private_parent($destination);
            $this->remove_entry($destination);
            if (!@mkdir($destination, 0700) && !is_dir($destination)) {
                throw new RuntimeException('Could not create prepared directory ' . $destination . '.', self::ERROR_RETRYABLE_IO);
            }
        }
        foreach ($children as $entry) {
            $source_child = $source . '/' . $entry;
            $destination_child = $destination . '/' . $entry;
            $child_identity = $this->path_identity($source_child);
            if ($child_identity === null) {
                throw new RuntimeException('Staged child disappeared while preparing ' . $source . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($child_identity['type'] === 'directory') {
                $this->overlay_directory($source_child, $destination_child);
            } else {
                $this->copy_entry($source_child, $destination_child);
            }
        }
    }

    /** Performs one atomic rename after proving both sides share a device. */
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

    /** Requires every target parent to be a real same-device directory. */
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
            // A regular-file ancestor can be a deliberate file -> directory
            // transition; the commit planner prepares its whole replacement
            // tree privately. A link would make even inspection/copy follow
            // an attacker-controlled path, so it is never a valid ancestor.
            if ($index < count($segments) - 1 && is_link($current)) {
                throw new RuntimeException('Refusing staged apply path below a symlinked target parent ' . $current . '.');
            }
            if ((int) $identity['dev'] !== (int) $root_identity['dev']) {
                throw new RuntimeException('Refusing staged apply path below separately mounted target path ' . $current . '.');
            }
        }
    }

    private function private_path(string $root, string $relative_path): string {
        $this->validate_path($relative_path);
        $root = rtrim($root, '/');
        if ($root === '' || strpos($root . '/', $this->session_dir . '/') !== 0) {
            throw new LogicException('A staged apply private path escaped its session workspace.');
        }
        return $root . '/' . $relative_path;
    }

    private function target_path(string $relative_path): string {
        $this->validate_path($relative_path);
        return $this->target_root === '/' ? '/' . $relative_path : $this->target_root . '/' . $relative_path;
    }

    /**
     * Require private parent components to be actual directories. When
     * $create_missing is false, a missing parent is safe and simply means
     * there cannot yet be a staged leaf below it.
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
     * @return array<string,mixed>|null
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
     * Fingerprint a live entry and every descendant without following links.
     *
     * A directory's own lstat identity cannot observe a rewrite of an
     * existing child file. This bounded-memory digest closes that gap between
     * candidate preparation and the first live rename.
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
     * @param array<string,mixed> $identity
     */
    private function append_tree_fingerprint($context, string $path, string $relative_path, array $identity, int $root_device): void {
        if ((int) $identity['dev'] !== $root_device) {
            throw new RuntimeException(
                'Refusing to fingerprint a live tree below separately mounted path ' . $path . '.',
                self::ERROR_RETRYABLE_IO
            );
        }
        $record = json_encode([
            'path_b64' => base64_encode($relative_path),
            'identity' => $identity,
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

    /** Recursively removes an entry without following symlinks. */
    private function remove_entry(string $path): void {
        $identity = $this->path_identity($path);
        if ($identity === null) {
            return;
        }
        if ($identity['type'] === 'directory') {
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

    /** @return array<string,mixed>|null */
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

    /** @param array<string,mixed> $value */
    private function write_json(string $path, array $value): void {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('Could not encode bounded staged apply metadata ' . $path . '.', self::ERROR_RETRYABLE_IO);
        }
        $this->write_atomic_file($path, $encoded, 0600);
    }

    /** Publishes private metadata only after a complete, flushed temporary write. */
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

    /** @param resource $handle */
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
    }

    /** Verifies immutable session ownership against the current server configuration. */
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
    }

    /**
     * Rejects a corrupt or internally inconsistent durable commit checkpoint.
     *
     * @param array<string,mixed> $state
     */
    private function require_valid_commit_state(array $state): void {
        if (($state['version'] ?? null) !== 1) {
            throw new RuntimeException('Commit checkpoint has an unsupported version ' . json_encode($state['version'] ?? null) . '.', self::ERROR_INVALID_STATE);
        }
        $phase = $state['phase'] ?? null;
        if (!is_string($phase) || !in_array($phase, ['preparing', 'switching', 'cleaning', 'complete'], true)) {
            throw new RuntimeException('Commit checkpoint has an invalid phase ' . json_encode($phase) . '.', self::ERROR_INVALID_STATE);
        }
        $actions = $state['actions'] ?? null;
        if (!is_array($actions)) {
            throw new RuntimeException('Commit checkpoint has no action list.', self::ERROR_INVALID_STATE);
        }
        foreach ($actions as $index => $action) {
            if (!is_array($action)
                || !is_string($action['path_b64'] ?? null)
                || $action['path_b64'] === ''
                || !is_string($action['kind'] ?? null)
                || !in_array($action['kind'], ['unit', 'tree', 'entry'], true)
                || !array_key_exists('deleted', $action)
                || !is_bool($action['deleted'])) {
                throw new RuntimeException('Commit checkpoint action ' . $index . ' is invalid.', self::ERROR_INVALID_STATE);
            }
        }
        $action_count = count($actions);
        foreach (['prepare_index', 'switch_index'] as $field) {
            $value = $state[$field] ?? null;
            if (!is_int($value) || $value < 0 || $value > $action_count) {
                throw new RuntimeException('Commit checkpoint ' . $field . ' must be an integer from 0 through ' . $action_count . '; observed ' . json_encode($value) . '.', self::ERROR_INVALID_STATE);
            }
        }
        if ($phase !== 'preparing' && $state['prepare_index'] !== $action_count) {
            throw new RuntimeException('Commit checkpoint phase ' . $phase . ' has unprepared actions.', self::ERROR_INVALID_STATE);
        }
        if (in_array($phase, ['cleaning', 'complete'], true) && $state['switch_index'] !== $action_count) {
            throw new RuntimeException('Commit checkpoint phase ' . $phase . ' has unswitched actions.', self::ERROR_INVALID_STATE);
        }
        if (array_key_exists('transition', $state) && $state['transition'] !== null && !is_array($state['transition'])) {
            throw new RuntimeException('Commit checkpoint transition is invalid.', self::ERROR_INVALID_STATE);
        }
    }

    /**
     * Rejects path traversal, protected targets, and separately mounted target
     * components before a staged operation mutates the filesystem.
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

    /** Returns the complete plugin or theme root containing a changed path. */
    private function deployment_unit_for_path(string $path): ?string {
        $segments = explode('/', $path);
        if (count($segments) < 3 || $segments[0] !== 'wp-content' || ($segments[1] !== 'plugins' && $segments[1] !== 'themes')) {
            return null;
        }
        return $segments[0] . '/' . $segments[1] . '/' . $segments[2];
    }

    private function describe_path(string $path): string {
        return base64_encode($path);
    }

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
     * @param string[] $protected_paths
     * @return string[]
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
                    throw new InvalidArgumentException('Protected staged apply path is unsafe: ' . $path . '.');
                }
            }
            $normalized[] = $path;
        }
        sort($normalized, SORT_STRING);
        return array_values(array_unique($normalized));
    }

    /** Recursively removes private session storage without following symlinks. */
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
