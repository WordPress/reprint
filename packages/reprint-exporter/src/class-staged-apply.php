<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime errors are returned as API JSON, never rendered as HTML.

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}

/**
 * Owns one direct, resumable staged-apply session.
 *
 * Typed operations are materialized directly below work/staged as they are
 * accepted. The target writes one bounded JSONL record for each completed
 * operation; there is no uploaded manifest, validation pass, prepared copy,
 * or file-content scan. A file has one durable cursor and one private
 * incoming name until its final chunk is flushed and renamed into staged/.
 *
 * Commit reads one target-authored record at a time. Before changing the live
 * tree it persists an intent containing the original target identity and the
 * staged identity. A missing staged node is accepted after a lost response
 * only when the live node still has that recorded identity.
 */
final class Site_Export_Staged_Apply {

    public const ERROR_BUSY = 1001;

    public const ERROR_DISCARD_PENDING = 1002;

    public const ERROR_STALE_GENERATION = 1003;

    public const ERROR_SESSION_NOT_FOUND = 1004;

    public const ERROR_RETRYABLE_IO = 1005;

    public const ERROR_INVALID_OPERATION = 1006;

    private const MAX_METADATA_BYTES = 1048576;

    private const MAX_OPERATION_LINE_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES;

    private const MAX_PATH_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES;

    private const MAX_FILE_CHUNK_BYTES = 4194304;

    private const MAX_ADVANCE_COMMIT_STEPS = 8;

    private const MAX_CREATED_SESSION_CLEANUP_OPERATIONS = 128;

    private const MAX_DISCARD_STEPS = 256;

    private const MAX_DISCARD_STATE_BYTES = 32768;

    private const MAX_RETIRED_GC_ENTRIES = 64;

    private const MAX_MAINTENANCE_MARKER_BYTES = 128;

    private const MAINTENANCE_FILENAME = '.maintenance';

    /** @var string */
    private $storage_dir;

    /** @var string */
    private $target_root;

    /** @var string[] */
    private $protected_paths;

    /** @var string */
    private $session_id;

    /** @var string */
    private $session_dir;

    /** @var string */
    private $discarding_session_dir;

    /** @var string */
    private $discard_state_path;

    /** @var string */
    private $retired_session_path;

    /** @var string */
    private $state_path;

    /** @var string */
    private $lock_path;

    /** @var string */
    private $operation_path;

    /** @var string */
    private $journal_path;

    /** @var string */
    private $incoming_file_path;

    /** @var string */
    private $staged_dir;

    /** @var string */
    private $backup_dir;

    /** @var string */
    private $maintenance_marker_identity_path;

    /** @var string */
    private $previous_maintenance_marker_identity_path;

    /** @var string */
    private $next_maintenance_marker_link_path;

    /** @var bool */
    private $upload_lock_held = false;

    /** @param string[] $protected_paths */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths) {
        $this->storage_dir = $storage_dir;
        $this->target_root = $target_root;
        $this->protected_paths = $protected_paths;
        $this->session_id = $session_id;
        $this->session_dir = $storage_dir . '/apply-sessions/' . $session_id;
        $this->discarding_session_dir = $storage_dir . '/apply-sessions/.discarding-' . $session_id;
        $this->discard_state_path = $storage_dir . '/apply-sessions/.discarding-' . $session_id . '.json';
        $this->retired_session_path = $storage_dir . '/apply-sessions/retired-' . $session_id;
        $this->state_path = $this->session_dir . '/state.json';
        $this->lock_path = $this->session_dir . '/lock';
        $this->operation_path = $this->session_dir . '/current-operation.json';
        $this->journal_path = $this->session_dir . '/work/operations.jsonl';
        $this->incoming_file_path = $this->session_dir . '/work/incoming-file';
        $this->staged_dir = $this->session_dir . '/work/staged';
        $this->backup_dir = $this->session_dir . '/work/backups';
        $this->maintenance_marker_identity_path = $this->session_dir . '/work/maintenance-marker.php';
        $this->previous_maintenance_marker_identity_path = $this->session_dir . '/work/maintenance-marker.previous.php';
        $this->next_maintenance_marker_link_path = $this->session_dir . '/work/maintenance-marker.next.php';
    }

    /**
     * Create an opaque server-owned session.
     *
     * A server-derived id makes a lost create response idempotent. Once such
     * a session is discarded, a retained marker prevents a delayed signed
     * request from finding a new generation-zero session with the same id.
     *
     * @param string[] $protected_paths
     */
    public static function create(
        string $storage_dir,
        string $target_root,
        array $protected_paths = [],
        ?string $server_session_id = null,
        int $retired_session_seconds = 601
    ): self {
        $target_root = self::require_absolute_directory($target_root, 'apply target', false);
        $storage_dir = self::require_staging_directory($storage_dir, $target_root, true);
        self::require_same_filesystem($storage_dir, $target_root);
        $protected_paths = self::protect_storage_path(
            $storage_dir,
            $target_root,
            self::normalize_protected_paths($protected_paths)
        );

        $sessions_dir = $storage_dir . '/apply-sessions';
        self::require_real_directory_path($sessions_dir, 'staged apply sessions directory', true);

        if ($server_session_id !== null) {
            if (!preg_match('/^[a-f0-9]{32}$/D', $server_session_id)) {
                throw new InvalidArgumentException('The server-derived staged apply session id must be a 32-character lowercase hexadecimal value.');
            }
            if ($retired_session_seconds <= 0) {
                throw new InvalidArgumentException('The retired staged apply session retention must be a positive number of seconds.');
            }
            $creation_lock = @fopen($sessions_dir . '/create.lock', 'c+b');
            if ($creation_lock === false) {
                throw new RuntimeException('Could not lock staged apply session creation for ' . $server_session_id . '.', self::ERROR_RETRYABLE_IO);
            }
            try {
                if (!flock($creation_lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Staged apply session creation is busy. Retry create for session ' . $server_session_id . '.', self::ERROR_BUSY);
                }
                self::remove_expired_retired_sessions_step($sessions_dir, $retired_session_seconds);
                $session_dir = $sessions_dir . '/' . $server_session_id;
                $existing = new self($storage_dir, $target_root, $server_session_id, $protected_paths);
                $discarding_stat = @lstat($existing->discarding_session_dir);
                if (is_array($discarding_stat) || is_file($existing->discard_state_path)) {
                    if (is_array($discarding_stat) && !self::stat_is_real_directory($discarding_stat)) {
                        throw new RuntimeException('The staged apply discard tombstone is not a real directory: ' . $existing->discarding_session_dir);
                    }
                    $existing->advance_discard_cleanup();
                }

                $session_stat = @lstat($session_dir);
                if (is_array($session_stat)) {
                    if (!self::stat_is_real_directory($session_stat)) {
                        throw new RuntimeException('The staged apply session path is not a real directory: ' . $session_dir);
                    }
                    if (self::is_real_regular_file($existing->state_path) && self::is_real_regular_file($existing->lock_path)) {
                        return self::open($storage_dir, $target_root, $server_session_id, $protected_paths);
                    }
                    if (!@rename($session_dir, $existing->discarding_session_dir)) {
                        throw new RuntimeException('Could not move the incomplete staged apply session into bounded cleanup: ' . $session_dir, self::ERROR_RETRYABLE_IO);
                    }
                    $existing->advance_discard_cleanup();
                }
                $retired_path = $sessions_dir . '/retired-' . $server_session_id;
                self::remove_expired_retired_session($retired_path, $retired_session_seconds);
                if (is_file($retired_path)) {
                    throw new RuntimeException('The staged apply create retry token was already consumed by discarded session ' . $server_session_id . '. Start a new session with a new create token.');
                }
                if (!@mkdir($session_dir, 0700)) {
                    throw new RuntimeException('Could not create staged apply session directory: ' . $session_dir, self::ERROR_RETRYABLE_IO);
                }
                return self::initialize_created_session($storage_dir, $target_root, $server_session_id, $protected_paths, true);
            } finally {
                flock($creation_lock, LOCK_UN);
                fclose($creation_lock);
            }
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $session_id = bin2hex(random_bytes(16));
            if (@mkdir($sessions_dir . '/' . $session_id, 0700)) {
                return self::initialize_created_session($storage_dir, $target_root, $session_id, $protected_paths, false);
            }
        }
        throw new RuntimeException('Could not create a unique staged apply session after 10 attempts.', self::ERROR_RETRYABLE_IO);
    }

    /** @param string[] $protected_paths */
    public static function open(string $storage_dir, string $target_root, string $session_id, array $protected_paths = []): self {
        $target_root = self::normalize_absolute_path_for_inspection($target_root, 'apply target');
        $storage_dir = self::require_staging_directory($storage_dir, $target_root, false);
        self::require_real_directory_path($storage_dir . '/apply-sessions', 'staged apply sessions directory', false);
        if (!preg_match('/^[a-f0-9]{32}$/D', $session_id)) {
            throw new InvalidArgumentException('The staged apply session id must be a 32-character lowercase hexadecimal value.');
        }
        $protected_paths = self::protect_storage_path(
            $storage_dir,
            $target_root,
            self::normalize_protected_paths($protected_paths)
        );
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        $session_stat = @lstat($session->session_dir);
        if (!is_array($session_stat) || !self::stat_is_real_directory($session_stat)) {
            if (is_array($session_stat)) {
                throw new RuntimeException('The staged apply session path is not a real directory: ' . $session->session_dir);
            }
            if (is_dir($session->discarding_session_dir) || is_file($session->discard_state_path) || is_file($session->retired_session_path)) {
                return $session;
            }
            throw new RuntimeException('The staged apply session does not exist: ' . $session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $session->require_private_workspace();
        // Opening and inspecting a private session must remain possible when
        // the live root was replaced. Upload and commit operations perform
        // the identity check before they touch either tree; discard needs no
        // access to the old root and must still be able to clean up.
        $session->read_state(false);
        return $session;
    }

    public function get_session_id(): string {
        return $this->session_id;
    }

    /** @return array<string,mixed> */
    public function get_status(): array {
        if (!$this->session_directory_exists()) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $this->require_private_workspace();
        return $this->read_state(false);
    }

    /**
     * Fence and hold one upload request while it accepts any bounded number
     * of frames. The new generation is durable before the callback can read
     * the request body, so replaying a timed-out stream cannot restart a file.
     *
     * @return mixed
     */
    public function while_uploading(int $expected_request_generation, callable $callback) {
        return $this->with_session_lock(function () use ($expected_request_generation, $callback) {
            $state = $this->read_state();
            if ($state['phase'] !== 'uploading') {
                throw new RuntimeException('The staged apply session is not accepting operations because its phase is ' . $state['phase'] . '.');
            }
            $this->require_expected_request_generation($state, $expected_request_generation);
            ++$state['request_generation'];
            $this->write_state($state);
            if ($this->upload_lock_held) {
                throw new LogicException('The staged apply upload callback cannot be nested.');
            }
            $this->upload_lock_held = true;
            try {
                return $callback($this);
            } finally {
                $this->upload_lock_held = false;
            }
        });
    }

    /** @return array<string,mixed> */
    public function accept_directory(int $operation_index, string $path): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        $this->require_no_current_file($state);
        $this->validate_new_path_or_fail($state, $path, 'directory');
        $entry = ['type' => 'directory', 'path' => $path];
        $this->begin_metadata_operation($entry, $operation_index);
        $staged = $this->staged_path($path);
        if (!$this->ensure_staged_parents($path)) {
            $this->fail_invalid_operation('Cannot stage directory ' . $this->describe_path($path) . ' below a non-directory staged ancestor.');
        }
        if ($this->path_present($staged)) {
            if (!is_dir($staged) || is_link($staged)) {
                $this->fail_invalid_operation('Cannot stage directory ' . $this->describe_path($path) . ' because that staged path is already a non-directory.');
            }
        } elseif (!@mkdir($staged, 0700)) {
            throw new RuntimeException('Could not create staged directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        return $this->complete_upload_operation($state, $entry);
    }

    /** @return array<string,mixed> */
    public function accept_symlink(int $operation_index, string $path, string $target): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        $this->require_no_current_file($state);
        $this->validate_new_path_or_fail($state, $path, 'symlink');
        if ($target === '' || strpos($target, "\0") !== false || strlen($target) > self::MAX_PATH_BYTES) {
            $this->fail_invalid_operation('Refusing staged symlink ' . $this->describe_path($path) . ' with an empty, NUL-containing, or overlong target.');
        }
        $entry = ['type' => 'symlink', 'path' => $path, 'target' => $target];
        $this->begin_metadata_operation($entry, $operation_index);
        if (!$this->ensure_staged_parents($path)) {
            $this->fail_invalid_operation('Cannot stage symlink ' . $this->describe_path($path) . ' below a non-directory staged ancestor.');
        }
        $staged = $this->staged_path($path);
        if ($this->path_present($staged)) {
            if (!is_link($staged) || readlink($staged) !== $target) {
                $this->fail_invalid_operation('Cannot stage symlink ' . $this->describe_path($path) . ' because that staged path already has another shape.');
            }
        } elseif (!@symlink($target, $staged)) {
            throw new RuntimeException('Could not create staged symlink ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $entry['staged_identity'] = $this->require_path_identity($staged, 'staged symlink');
        return $this->complete_upload_operation($state, $entry);
    }

    /** @return array<string,mixed> */
    public function accept_delete(int $operation_index, string $path): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        $this->require_no_current_file($state);
        $this->validate_new_path_or_fail($state, $path, 'delete');
        $entry = ['type' => 'delete', 'path' => $path];
        $this->begin_metadata_operation($entry, $operation_index);

        // A prior file, symlink, or tombstone already makes a descendant
        // absent in the staged shape. Keep the delete only in the journal.
        if ($this->ensure_staged_parents($path, true)) {
            $staged = $this->staged_path($path);
            if (!$this->path_present($staged)) {
                $tombstone = @fopen($staged, 'x+b');
                if ($tombstone === false) {
                    throw new RuntimeException('Could not create staged delete tombstone ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                try {
                    if (!fflush($tombstone)) {
                        throw new RuntimeException('Could not flush staged delete tombstone ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                    }
                } finally {
                    fclose($tombstone);
                }
                @chmod($staged, 0600);
            }
        }
        return $this->complete_upload_operation($state, $entry);
    }

    /**
     * Append one bounded file frame at the target-confirmed cursor.
     *
     * @return array<string,mixed>
     */
    public function append_file_chunk(
        int $operation_index,
        string $path,
        int $revision,
        int $offset,
        string $payload,
        int $total_bytes,
        bool $restart
    ): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        if ($revision < 0 || $offset < 0 || $total_bytes < 0) {
            $this->fail_invalid_operation('File operation ' . $operation_index . ' has a negative revision, offset, or total byte count.');
        }
        if (strlen($payload) > self::MAX_FILE_CHUNK_BYTES) {
            return $this->upload_result('rejected', 'size_exceeded', $state);
        }

        $current_file = $state['current_file'];
        if ($current_file === null) {
            if ($offset !== 0) {
                return $this->upload_result('rejected', 'offset_gap', $state);
            }
            $this->validate_new_path_or_fail($state, $path, 'file');
            if (!$this->ensure_staged_parents($path)) {
                $this->fail_invalid_operation('Cannot stage file ' . $this->describe_path($path) . ' below a non-directory staged ancestor.');
            }
            if ($this->path_present($this->staged_path($path))) {
                $this->fail_invalid_operation('Cannot stage file ' . $this->describe_path($path) . ' because that staged path is already occupied.');
            }
            $state = $this->start_file_operation($state, $operation_index, $path, $revision, $total_bytes);
            $current_file = $state['current_file'];
        } elseif (
            $current_file['operation_index'] !== $operation_index
            || base64_decode($current_file['path_b64'], true) !== $path
        ) {
            return $this->upload_result('rejected', 'operation_gap', $state);
        } else {
            // Complete the cleanup for the durable current revision before a
            // later revision can replace its restart metadata. The staged
            // completion, if any, still belongs to the revision named by
            // restart_previous_total_bytes.
            if (( $current_file['restart_pending'] ?? false ) === true) {
                $state = $this->finish_file_restart($state, $path);
                $current_file = $state['current_file'];
            }
            if ($current_file['revision'] !== $revision) {
                if (!$restart || $offset !== 0) {
                    return $this->upload_result('rejected', 'offset_gap', $state);
                }
                $state = $this->restart_file_operation($state, $operation_index, $path, $revision, $total_bytes);
                $current_file = $state['current_file'];
            } elseif ($current_file['total_bytes'] !== $total_bytes) {
                return $this->upload_result('rejected', 'operation_gap', $state);
            } elseif ($restart && $offset !== 0) {
                return $this->upload_result('rejected', 'offset_gap', $state);
            }
        }

        $this->require_existing_staged_parents($path);
        $this->reconcile_file_upload_operation($state, $path);

        $committed_bytes = (int) $current_file['committed_bytes'];
        $payload_bytes = strlen($payload);
        if ($payload_bytes > 0 && $offset + $payload_bytes <= $committed_bytes) {
            return $this->upload_result('duplicate', null, $state);
        }
        if ($offset < $committed_bytes) {
            // A retry may re-read a differently sized local chunk. Discard
            // only its already-confirmed prefix and append the suffix; making
            // chunk boundaries part of the durable protocol would otherwise
            // strand a valid byte stream after any sender size adjustment.
            $confirmed_prefix_bytes = $committed_bytes - $offset;
            $payload = substr($payload, $confirmed_prefix_bytes);
            $payload_bytes = strlen($payload);
            $offset = $committed_bytes;
        }
        if ($offset !== $committed_bytes) {
            return $this->upload_result('rejected', 'offset_gap', $state);
        }
        if ($offset + $payload_bytes > $total_bytes) {
            return $this->upload_result('rejected', 'size_exceeded', $state);
        }
        if ($payload_bytes === 0 && $committed_bytes !== $total_bytes) {
            return $this->upload_result('rejected', 'empty_payload', $state);
        }

        $entry = [
            'type' => 'file',
            'path' => $path,
            'bytes' => $total_bytes,
        ];
        $staged = $this->staged_path($path);
        if ($this->path_present($staged)) {
            // The final rename may have completed before its journal/state
            // commit. Only the active descriptor can reach this recovery.
            if (!is_file($staged) || is_link($staged) || @filesize($staged) !== $total_bytes) {
                $this->fail_invalid_operation('The recovered staged file has the wrong shape for ' . $this->describe_path($path) . '.');
            }
            $entry['staged_identity'] = $this->require_path_identity($staged, 'staged file');
            return $this->complete_upload_operation($state, $entry);
        }

        $file = @fopen($this->incoming_file_path, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Could not open the staged incoming file for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        try {
            $incoming_stat = @fstat($file);
            if (!is_array($incoming_stat) || !isset($incoming_stat['size']) || !is_int($incoming_stat['size'])) {
                throw new RuntimeException('Could not read the staged incoming file size for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($incoming_stat['size'] < $committed_bytes) {
                $this->fail_invalid_operation(
                    'Cannot resume staged file ' . $this->describe_path($path) . ' at its confirmed byte ' . $committed_bytes
                    . ' because the incoming file contains only ' . $incoming_stat['size'] . ' bytes.'
                );
            }
            if (!ftruncate($file, $committed_bytes) || fseek($file, $committed_bytes, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not resume staged file ' . $this->describe_path($path) . ' at byte ' . $committed_bytes . '.', self::ERROR_RETRYABLE_IO);
            }
            if ($payload_bytes > 0 && !$this->write_all($file, $payload)) {
                ftruncate($file, $committed_bytes);
                throw new RuntimeException('Could not write the next staged file chunk for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
            if (!fflush($file)) {
                ftruncate($file, $committed_bytes);
                throw new RuntimeException('Could not flush the next staged file chunk for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($file);
        }

        $next_bytes = $committed_bytes + $payload_bytes;
        if ($next_bytes < $total_bytes) {
            $state['current_file']['committed_bytes'] = $next_bytes;
            $this->write_state($state);
            return $this->upload_result('accepted', null, $state);
        }

        if (!@rename($this->incoming_file_path, $staged)) {
            throw new RuntimeException('Could not finalize staged file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
        $entry['staged_identity'] = $this->require_path_identity($staged, 'staged file');
        return $this->complete_upload_operation($state, $entry);
    }

    /** Mark a malformed or semantically invalid upload as discard-only. */
    public function fail_upload(string $detail): void {
        $this->require_upload_lock();
        if ($detail === '' || strlen($detail) > self::MAX_METADATA_BYTES / 2) {
            throw new InvalidArgumentException('The staged apply failure detail must be a non-empty bounded string.');
        }
        $state = $this->read_state();
        if ($state['phase'] !== 'uploading' && $state['phase'] !== 'failed') {
            throw new RuntimeException('Cannot fail staged apply upload while its phase is ' . $state['phase'] . '.');
        }
        $state['phase'] = 'failed';
        $state['failure'] = $detail;
        $this->write_state($state);
    }

    /**
     * Close upload and commit at most a fixed number of target operations.
     *
     * @return array<string,mixed>
     */
    public function advance(int $expected_request_generation): array {
        return $this->with_session_lock(function () use ($expected_request_generation): array {
            $state = $this->read_state();
            $this->require_expected_request_generation($state, $expected_request_generation);
            if ($state['phase'] === 'complete') {
                return $this->complete_session_while_locked($state);
            }
            if ($state['phase'] === 'failed') {
                throw new RuntimeException('The failed staged apply session cannot advance; discard it. Failure: ' . $state['failure']);
            }
            if ($state['phase'] !== 'uploading' && $state['phase'] !== 'committing') {
                throw new RuntimeException('The staged apply session cannot advance in phase ' . $state['phase'] . '.');
            }
            if ($state['current_file'] !== null) {
                throw new RuntimeException('Cannot close staged apply upload while file operation ' . $state['current_file']['operation_index'] . ' has only ' . $state['current_file']['committed_bytes'] . ' of ' . $state['current_file']['total_bytes'] . ' bytes.');
            }
            $operation = $this->read_operation();
            if (is_array($operation) && ( $operation['purpose'] ?? null ) === 'upload') {
                $operation_index = (int) ( $operation['operation_index'] ?? -1 );
                if ($operation_index < (int) $state['operation_count']) {
                    // The operation was journaled and state advanced before
                    // death; only the final unlink of its descriptor was lost.
                    $this->clear_operation();
                } else {
                    throw new RuntimeException(
                        'Cannot close staged apply upload while operation ' . $operation_index
                        . ' has a durable upload intent that has not reached the target-authored journal. Replay that operation first.'
                    );
                }
            }

            $this->claim_active_session();
            $this->create_maintenance_marker();
            ++$state['request_generation'];
            if ($state['phase'] === 'uploading') {
                $this->truncate_journal_tail( (int) $state['journal_bytes']);
                $state['phase'] = 'committing';
            }
            $this->write_state($state);

            for ($step = 0; $step < self::MAX_ADVANCE_COMMIT_STEPS; $step++) {
                $state = $this->commit_one_while_locked($state);
                if ($state['phase'] === 'complete') {
                    return $this->complete_session_while_locked($state);
                }
            }
            return $state;
        });
    }

    /**
     * Abort an uncommitted/failed session or remove a completed session.
     */
    public function discard(int $expected_request_generation): bool {
        if (is_dir($this->discarding_session_dir) || is_file($this->discard_state_path)) {
            return $this->advance_discard_cleanup();
        }
        if (is_file($this->retired_session_path) && !is_dir($this->session_dir)) {
            return true;
        }
        $this->with_session_lock(function () use ($expected_request_generation): void {
            $state = $this->read_state(false);
            $this->require_expected_request_generation($state, $expected_request_generation);
            $discarding_complete = $state['phase'] === 'discarding' && ( $state['discarding_complete'] ?? false ) === true;
            $target_mutation_started = is_file($this->operation_path)
                && ( ( $this->read_operation()['purpose'] ?? null ) === 'commit' );
            $target_mutation_started = $target_mutation_started || (int) $state['commit_count'] > 0;
            if ($state['phase'] !== 'complete' && !$discarding_complete && $target_mutation_started) {
                throw new RuntimeException('Cannot discard staged apply session ' . $this->session_id . ' after it has begun target mutations. Resume its durable commit instead.');
            }
            if ($state['phase'] !== 'discarding') {
                $state['discarding_complete'] = $state['phase'] === 'complete';
                $state['phase'] = 'discarding';
                $state['failure'] = null;
                ++$state['request_generation'];
                $this->write_state($state);
            }
            try {
                $this->remove_maintenance_marker();
                $this->release_active_session();
                if (( $state['retire_session_id'] ?? false ) === true) {
                    $this->write_retired_session_marker();
                }
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    'The staged apply discard is pending for session ' . $this->session_id . ': ' . $exception->getMessage(),
                    self::ERROR_DISCARD_PENDING,
                    $exception
                );
            }
        });
        $this->move_session_to_discarding_directory();
        return $this->advance_discard_cleanup();
    }

    /** @param string[] $protected_paths */
    private static function initialize_created_session(
        string $storage_dir,
        string $target_root,
        string $session_id,
        array $protected_paths,
        bool $retire_session_id
    ): self {
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        $target_root_stat = @lstat($target_root);
        if (!is_array($target_root_stat) || !isset($target_root_stat['dev'], $target_root_stat['ino'])) {
            self::remove_created_session_tree($session->session_dir);
            throw new RuntimeException('Could not record the staged apply target root identity: ' . $target_root, self::ERROR_RETRYABLE_IO);
        }
        try {
            foreach ([$session->staged_dir, $session->backup_dir] as $directory) {
                if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Could not create staged apply workspace directory: ' . $directory, self::ERROR_RETRYABLE_IO);
                }
            }
            if (@file_put_contents($session->lock_path, '') === false) {
                throw new RuntimeException('Could not create the staged apply session lock: ' . $session->lock_path, self::ERROR_RETRYABLE_IO);
            }
            $journal = @fopen($session->journal_path, 'x+b');
            if ($journal === false) {
                throw new RuntimeException('Could not create the target-authored staged apply journal: ' . $session->journal_path, self::ERROR_RETRYABLE_IO);
            }
            try {
                if (!fflush($journal)) {
                    throw new RuntimeException('Could not flush the target-authored staged apply journal: ' . $session->journal_path, self::ERROR_RETRYABLE_IO);
                }
            } finally {
                fclose($journal);
            }
            $session->write_state([
                'version' => 1,
                'session_id' => $session_id,
                'target_root_b64' => base64_encode($target_root),
                'target_root_dev' => (int) $target_root_stat['dev'],
                'target_root_ino' => (int) $target_root_stat['ino'],
                'protected_paths_b64' => array_map('base64_encode', $protected_paths),
                'phase' => 'uploading',
                'request_generation' => 0,
                'operation_count' => 0,
                'journal_bytes' => 0,
                'last_path_b64' => null,
                'last_type' => null,
                'current_file' => null,
                'commit_offset' => 0,
                'commit_count' => 0,
                'failure' => null,
                'retire_session_id' => $retire_session_id,
            ]);
        } catch (Throwable $exception) {
            self::remove_created_session_tree($session->session_dir);
            throw $exception;
        }
        return $session;
    }

    private function require_upload_lock(): void {
        if (!$this->upload_lock_held) {
            throw new LogicException('Staged apply operations may be accepted only inside while_uploading().');
        }
    }

    private function require_private_workspace(): void {
        foreach (
            [
                dirname($this->session_dir) => 'staged apply sessions directory',
                $this->session_dir => 'staged apply session directory',
                dirname($this->staged_dir) => 'staged apply work directory',
                $this->staged_dir => 'staged apply staged tree',
                $this->backup_dir => 'staged apply backup tree',
            ] as $path => $description
        ) {
            self::require_real_directory_path($path, $description, false);
        }
        foreach (
            [
                $this->state_path => 'staged apply state',
                $this->lock_path => 'staged apply lock',
                $this->journal_path => 'target-authored staged apply journal',
            ] as $path => $description
        ) {
            self::require_real_regular_file($path, $description);
        }
        foreach (
            [
                $this->operation_path => 'staged apply current operation',
                $this->incoming_file_path => 'staged apply incoming file',
                $this->maintenance_marker_identity_path => 'staged apply maintenance identity',
                $this->previous_maintenance_marker_identity_path => 'staged apply previous maintenance identity',
                $this->next_maintenance_marker_link_path => 'staged apply next maintenance identity',
            ] as $path => $description
        ) {
            if (is_array(@lstat($path))) {
                self::require_real_regular_file($path, $description);
            }
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed>|null */
    private function operation_gap_result(array $state, int $operation_index): ?array {
        if ($operation_index < 0) {
            $this->fail_invalid_operation('The staged apply operation index must be non-negative; observed ' . $operation_index . '.');
        }
        if ($state['phase'] !== 'uploading') {
            throw new RuntimeException('The staged apply session is not accepting operations because its phase is ' . $state['phase'] . '.');
        }
        if ($operation_index !== (int) $state['operation_count']) {
            return $this->upload_result(
                $operation_index < (int) $state['operation_count'] ? 'duplicate' : 'rejected',
                $operation_index < (int) $state['operation_count'] ? null : 'operation_gap',
                $state
            );
        }
        return null;
    }

    /** @param array<string,mixed> $state */
    private function require_no_current_file(array $state): void {
        if ($state['current_file'] !== null) {
            throw new RuntimeException(
                'File operation ' . $state['current_file']['operation_index'] . ' is incomplete at byte '
                . $state['current_file']['committed_bytes'] . '; metadata operation ' . $state['operation_count'] . ' cannot start yet.'
            );
        }
    }

    /** @param array<string,mixed> $state */
    private function validate_new_path_or_fail(array $state, string $path, string $type): void {
        try {
            $this->require_writable_target_path($path, $type);
        } catch (RuntimeException $exception) {
            $this->fail_invalid_operation($exception->getMessage());
        }
        if (strlen($path) > self::MAX_PATH_BYTES) {
            $this->fail_invalid_operation('Refusing staged apply path longer than ' . self::MAX_PATH_BYTES . ' bytes: ' . $this->describe_path($path));
        }
        $last_path = $state['last_path_b64'] === null ? null : base64_decode($state['last_path_b64'], true);
        if (is_string($last_path) && strcmp($last_path, $path) >= 0) {
            $this->fail_invalid_operation(
                'Staged apply paths must be strictly increasing by raw bytes; received ' . $this->describe_path($path)
                . ' after ' . $this->describe_path($last_path) . '.'
            );
        }
    }

    /** @param array<string,mixed> $entry */
    private function begin_metadata_operation(array $entry, int $operation_index): void {
        $operation = $this->read_operation();
        if ($operation !== null && ( $operation['purpose'] ?? null ) === 'upload' && (int) ( $operation['operation_index'] ?? -1 ) < $operation_index) {
            $this->clear_operation();
            $operation = null;
        }
        $expected = [
            'purpose' => 'upload',
            'operation_index' => $operation_index,
            'entry' => $entry,
        ];
        if ($operation !== null && $operation !== $expected) {
            $this->fail_invalid_operation('The in-flight staged apply operation does not match replayed operation ' . $operation_index . '.');
        }
        if ($operation === null) {
            $this->write_operation($expected);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function complete_upload_operation(array $state, array $entry): array {
        $this->truncate_journal_tail( (int) $state['journal_bytes']);
        $record = [
            'operation_index' => (int) $state['operation_count'],
            'entry' => $this->serialize_entry($entry),
        ];
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode target-authored staged apply journal operation.');
        }
        $line = $encoded . "\n";
        if (strlen($line) > self::MAX_OPERATION_LINE_BYTES) {
            $this->fail_invalid_operation('The target-authored staged apply operation exceeds its bounded encoded size.');
        }
        $journal = @fopen($this->journal_path, 'c+b');
        if ($journal === false) {
            throw new RuntimeException('Could not open the target-authored staged apply journal: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($journal, (int) $state['journal_bytes'], SEEK_SET) !== 0 || !$this->write_all($journal, $line) || !fflush($journal)) {
                throw new RuntimeException('Could not append target-authored staged apply operation ' . $state['operation_count'] . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($journal);
        }

        ++$state['operation_count'];
        $state['journal_bytes'] += strlen($line);
        $state['last_path_b64'] = base64_encode($entry['path']);
        $state['last_type'] = $entry['type'];
        $state['current_file'] = null;
        $this->write_state($state);
        $this->clear_operation();
        return $this->upload_result('accepted', null, $state);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function start_file_operation(
        array $state,
        int $operation_index,
        string $path,
        int $revision,
        int $total_bytes
    ): array {
        $operation = $this->read_operation();
        if ($operation !== null && ( $operation['purpose'] ?? null ) === 'upload' && (int) ( $operation['operation_index'] ?? -1 ) < $operation_index) {
            $this->clear_operation();
            $operation = null;
        }
        $descriptor = $this->file_upload_operation($operation_index, $path, $revision, $total_bytes);
        if ($operation !== null && $operation !== $descriptor) {
            $this->fail_invalid_operation('The in-flight staged apply operation does not match file operation ' . $operation_index . '.');
        }
        if ($operation === null) {
            $this->write_operation($descriptor);
        }
        $state['current_file'] = [
            'operation_index' => $operation_index,
            'path_b64' => base64_encode($path),
            'revision' => $revision,
            'committed_bytes' => 0,
            'total_bytes' => $total_bytes,
        ];
        $this->write_state($state);
        $this->truncate_incoming_file(0);
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function restart_file_operation(
        array $state,
        int $operation_index,
        string $path,
        int $revision,
        int $total_bytes
    ): array {
        $previous_total_bytes = $state['current_file']['total_bytes'];
        $this->write_operation($this->file_upload_operation($operation_index, $path, $revision, $total_bytes));
        $state['current_file'] = [
            'operation_index' => $operation_index,
            'path_b64' => base64_encode($path),
            'revision' => $revision,
            'committed_bytes' => 0,
            'total_bytes' => $total_bytes,
            'restart_pending' => true,
            'restart_previous_total_bytes' => $previous_total_bytes,
        ];
        // The new revision becomes authoritative before any old staged or
        // incoming bytes are removed. restart_pending makes that cleanup
        // repeatable if the process dies before its second state commit.
        $this->write_state($state);
        return $this->finish_file_restart($state, $path);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function finish_file_restart(array $state, string $path): array {
        $current_file = $state['current_file'];
        if (!is_array($current_file) || ( $current_file['restart_pending'] ?? null ) !== true) {
            return $state;
        }
        $previous_total_bytes = $current_file['restart_previous_total_bytes'] ?? null;
        if (!is_int($previous_total_bytes) || $previous_total_bytes < 0) {
            throw new RuntimeException('The staged apply pending file restart has no valid previous byte count.');
        }
        $this->require_existing_staged_parents($path);
        $staged = $this->staged_path($path);
        if ($this->path_present($staged)) {
            $staged_size = !is_link($staged) && is_file($staged) ? @filesize($staged) : false;
            if ($staged_size !== $previous_total_bytes) {
                $this->fail_invalid_operation('Cannot restart file ' . $this->describe_path($path) . ' because its old staged completion has an unexpected shape or size.');
            }
            if (!@unlink($staged)) {
                throw new RuntimeException('Could not remove the old staged completion while restarting ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
        }
        $this->truncate_incoming_file(0);
        $state['current_file']['restart_pending'] = false;
        unset($state['current_file']['restart_previous_total_bytes']);
        $this->write_state($state);
        return $state;
    }

    /** @return array<string,mixed> */
    private function file_upload_operation(int $operation_index, string $path, int $revision, int $total_bytes): array {
        return [
            'purpose' => 'upload',
            'operation_index' => $operation_index,
            'entry' => [
                'type' => 'file',
                'path' => $path,
                'bytes' => $total_bytes,
                'revision' => $revision,
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private function reconcile_file_upload_operation(array $state, string $path): void {
        $current_file = $state['current_file'];
        if (!is_array($current_file) || base64_decode($current_file['path_b64'], true) !== $path) {
            throw new LogicException('Cannot reconcile a staged file operation without its durable current-file state.');
        }
        $expected = $this->file_upload_operation(
            $current_file['operation_index'],
            $path,
            $current_file['revision'],
            $current_file['total_bytes']
        );
        $operation = $this->read_operation();
        if ($operation !== $expected) {
            // Restart persists its descriptor before changing the durable
            // revision. A death between those writes leaves the old state
            // authoritative and no new-revision bytes have been touched, so
            // continuing the old revision must first restore its descriptor.
            $this->write_operation($expected);
        }
    }

    private function truncate_incoming_file(int $bytes): void {
        $file = @fopen($this->incoming_file_path, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Could not open the staged incoming file.', self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!ftruncate($file, $bytes) || !fflush($file)) {
                throw new RuntimeException('Could not truncate the staged incoming file to ' . $bytes . ' bytes.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($file);
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function upload_result(string $status, ?string $reason, array $state): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'operation_count' => (int) $state['operation_count'],
            'current_file' => $state['current_file'],
        ];
    }

    private function fail_invalid_operation(string $detail): void {
        $this->fail_upload($detail);
        throw new RuntimeException($detail, self::ERROR_INVALID_OPERATION);
    }

    private function truncate_journal_tail(int $journal_bytes): void {
        $journal = @fopen($this->journal_path, 'c+b');
        if ($journal === false) {
            throw new RuntimeException('Could not open the target-authored staged apply journal: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            $stat = fstat($journal);
            $observed_bytes = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : -1;
            if ($observed_bytes < $journal_bytes) {
                throw new RuntimeException(
                    'The target-authored staged apply journal is shorter than its durable cursor; expected at least '
                    . $journal_bytes . ' bytes, observed ' . $observed_bytes . '.'
                );
            }
            if ($observed_bytes > $journal_bytes && ( !ftruncate($journal, $journal_bytes) || !fflush($journal) )) {
                throw new RuntimeException('Could not remove the uncommitted staged apply journal tail after byte ' . $journal_bytes . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($journal);
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function complete_session_while_locked(array $state): array {
        // Completion is durable before these two cleanup steps. A later
        // advance repairs a process death between either cleanup and return.
        $this->remove_maintenance_marker();
        $this->release_active_session();
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function commit_one_while_locked(array $state): array {
        $operation = $this->read_operation();
        if (
            $operation !== null
            && ( $operation['purpose'] ?? null ) === 'upload'
            && (int) ( $operation['operation_index'] ?? -1 ) < (int) $state['operation_count']
        ) {
            $this->clear_operation();
            $operation = null;
        }
        if (
            $operation !== null
            && ( $operation['purpose'] ?? null ) === 'commit'
            && (int) ( $operation['next_offset'] ?? -1 ) === (int) $state['commit_offset']
        ) {
            $this->clear_operation();
            return $state;
        }

        if ( (int) $state['commit_offset'] === (int) $state['journal_bytes']) {
            if ( (int) $state['commit_count'] !== (int) $state['operation_count']) {
                throw new RuntimeException('The staged apply commit count does not match the completed operation count at the journal end.');
            }
            if ($operation !== null) {
                throw new RuntimeException('The staged apply current operation remains at the completed journal cursor.');
            }
            $state['phase'] = 'complete';
            $this->write_state($state);
            return $state;
        }

        if ($operation === null) {
            $record = $this->read_journal_record( (int) $state['commit_offset'], (int) $state['journal_bytes']);
            if ($record['operation_index'] !== (int) $state['commit_count']) {
                throw new RuntimeException(
                    'The target-authored staged apply journal operation index ' . $record['operation_index']
                    . ' does not match commit count ' . $state['commit_count'] . '.'
                );
            }
            $path = $record['entry']['path'];
            $target_status = $this->inspect_target_path_without_following($path);
            $operation = [
                'purpose' => 'commit',
                'journal_offset' => (int) $state['commit_offset'],
                'next_offset' => $record['next_offset'],
                'operation_index' => $record['operation_index'],
                'entry' => $record['entry'],
                'target_was_absent' => !$target_status['present'],
                'target_identity' => $target_status['present'] ? $target_status['identity'] : null,
            ];
            $this->write_operation($operation);
        } elseif (
            ( $operation['purpose'] ?? null ) !== 'commit'
            || (int) ( $operation['journal_offset'] ?? -1 ) !== (int) $state['commit_offset']
            || (int) ( $operation['operation_index'] ?? -1 ) !== (int) $state['commit_count']
        ) {
            throw new RuntimeException('The staged apply current operation does not match the server-confirmed commit cursor.');
        }

        $this->commit_entry($operation);
        $state['commit_offset'] = (int) $operation['next_offset'];
        ++$state['commit_count'];
        $this->write_state($state);
        $this->clear_operation();
        return $state;
    }

    /**
     * @return array{operation_index:int,next_offset:int,entry:array<string,mixed>}
     */
    private function read_journal_record(int $offset, int $journal_bytes): array {
        if ($offset < 0 || $offset >= $journal_bytes) {
            throw new RuntimeException('The staged apply journal cursor ' . $offset . ' is outside its confirmed ' . $journal_bytes . ' bytes.');
        }
        $journal = @fopen($this->journal_path, 'rb');
        if ($journal === false) {
            throw new RuntimeException('Could not read the target-authored staged apply journal: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($journal, $offset, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek to staged apply journal byte ' . $offset . '.', self::ERROR_RETRYABLE_IO);
            }
            $maximum_read = min(self::MAX_OPERATION_LINE_BYTES + 1, $journal_bytes - $offset + 1);
            $line = fgets($journal, $maximum_read);
            if (!is_string($line)) {
                throw new RuntimeException('Could not read staged apply journal operation at byte ' . $offset . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($journal);
        }
        if (substr($line, -1) !== "\n") {
            throw new RuntimeException('The target-authored staged apply journal operation at byte ' . $offset . ' is truncated or overlong.');
        }
        $next_offset = $offset + strlen($line);
        if ($next_offset > $journal_bytes) {
            throw new RuntimeException('The target-authored staged apply journal operation crosses its confirmed byte length.');
        }
        $record = json_decode(substr($line, 0, -1), true);
        if (
            !is_array($record)
            || !isset($record['operation_index'], $record['entry'])
            || !is_int($record['operation_index'])
            || $record['operation_index'] < 0
            || !is_array($record['entry'])
        ) {
            throw new RuntimeException('The target-authored staged apply journal operation at byte ' . $offset . ' is malformed.');
        }
        return [
            'operation_index' => $record['operation_index'],
            'next_offset' => $next_offset,
            'entry' => $this->deserialize_entry($record['entry']),
        ];
    }

    /** @param array<string,mixed> $operation */
    private function commit_entry(array $operation): void {
        $entry = $operation['entry'];
        $path = $entry['path'];
        $target = $this->target_path($path);
        $backup = $this->backup_path($path);
        $this->require_writable_target_path($path, $entry['type']);
        $this->require_unfollowed_workspace_parents($backup);

        if ($entry['type'] === 'delete') {
            $this->commit_delete($operation, $target, $backup);
            return;
        }

        $this->ensure_target_parents($path);
        if ($entry['type'] === 'directory') {
            if ($this->is_protected_ancestor($path)) {
                if (!is_dir($target) || is_link($target)) {
                    throw new RuntimeException('Protected staged apply ancestor ' . $this->describe_path($path) . ' is no longer a real directory.');
                }
                return;
            }
            if ($this->path_present($target) && ( !is_dir($target) || is_link($target) )) {
                if ($this->path_present($backup)) {
                    $this->require_confirmed_backup($operation, $backup, 'directory target backup');
                    throw new RuntimeException('Cannot resume directory ' . $this->describe_path($path) . ' because its backup exists while a non-directory target reappeared.');
                }
                $this->require_identity_match($target, $operation['target_identity'], 'original directory target replacement');
                if (!$this->move_to_backup($target, $backup)) {
                    throw new RuntimeException('Could not back up target before creating directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                $this->require_confirmed_backup($operation, $backup, 'directory target backup');
            }
            if ($this->path_present($backup)) {
                $this->require_confirmed_backup($operation, $backup, 'directory target backup');
            }
            if (!is_dir($target)) {
                if ($this->path_present($target) || !@mkdir($target, 0755)) {
                    throw new RuntimeException('Could not create target directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
            }
            return;
        }

        $this->require_existing_staged_parents($path);
        $staged = $this->staged_path($path);
        if ($this->path_present($staged)) {
            $this->require_identity_match($staged, $entry['staged_identity'], 'staged ' . $entry['type']);
            if ($entry['type'] === 'symlink' && ( !is_link($staged) || readlink($staged) !== $entry['target'] )) {
                throw new RuntimeException('The staged symlink target changed for ' . $this->describe_path($path) . '.');
            }
            if ($this->path_present($target)) {
                if ($this->path_present($backup)) {
                    $this->require_confirmed_backup($operation, $backup, 'original target backup');
                    throw new RuntimeException('Cannot apply ' . $this->describe_path($path) . ' because its backup exists while the target reappeared.');
                }
                if (( $operation['target_was_absent'] ?? null ) !== false) {
                    throw new RuntimeException('Cannot apply ' . $this->describe_path($path) . ' because a target appeared after the commit intent was recorded.');
                }
                $this->require_identity_match($target, $operation['target_identity'], 'original target');
                if (!$this->move_to_backup($target, $backup)) {
                    throw new RuntimeException('Could not back up target before applying ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                $this->require_confirmed_backup($operation, $backup, 'original target backup');
            } elseif (( $operation['target_was_absent'] ?? null ) === false && !$this->path_present($backup)) {
                throw new RuntimeException('Cannot apply ' . $this->describe_path($path) . ' because its original target disappeared before it was backed up.');
            }
            if ($this->path_present($backup)) {
                $this->require_confirmed_backup($operation, $backup, 'original target backup');
            }
            $this->require_same_filesystem_for_rename($staged, $target, 'move staged item into target');
            if (!@rename($staged, $target)) {
                throw new RuntimeException('Could not atomically move staged item into target: ' . $target, self::ERROR_RETRYABLE_IO);
            }
            return;
        }

        // A successful rename removes the private name. Device, inode, mode,
        // size, and mtime must still match at the live name. ctime is omitted
        // because rename changes it on some filesystems but not others.
        if (!$this->path_present($target)) {
            throw new RuntimeException(
                'Cannot confirm installed target after lost rename response at ' . $target
                . ' because both its staged and live names are absent.'
            );
        }
        $this->require_identity_match($target, $entry['staged_identity'], 'installed target after lost rename response', true);
        if ($this->path_present($backup)) {
            $this->require_confirmed_backup($operation, $backup, 'original target backup');
        }
        if ($entry['type'] === 'symlink' && ( !is_link($target) || readlink($target) !== $entry['target'] )) {
            throw new RuntimeException('Cannot confirm the installed symlink target for ' . $this->describe_path($path) . '.');
        }
    }

    /** @param array<string,mixed> $operation */
    private function commit_delete(array $operation, string $target, string $backup): void {
        $path = $operation['entry']['path'];
        $target_status = $this->inspect_target_path_without_following($path);
        if (!$target_status['present']) {
            if ($this->path_present($backup)) {
                $this->require_confirmed_backup($operation, $backup, 'delete target backup');
                return;
            }
            if (( $operation['target_was_absent'] ?? null ) === true) {
                return;
            }
            throw new RuntimeException('Cannot confirm deletion of ' . $this->describe_path($path) . ' because its original target disappeared before backup.');
        }
        if (( $operation['target_was_absent'] ?? null ) === true) {
            throw new RuntimeException('Cannot delete ' . $this->describe_path($path) . ' because a target appeared after the commit intent was recorded.');
        }
        if ($this->path_present($backup)) {
            $this->require_confirmed_backup($operation, $backup, 'delete target backup');
            throw new RuntimeException('Cannot delete ' . $this->describe_path($path) . ' because its backup exists while the target reappeared.');
        }
        $this->require_identity_match($target, $operation['target_identity'], 'delete target');
        if (!$this->move_to_backup($target, $backup)) {
            throw new RuntimeException('Could not back up target scheduled for deletion: ' . $target, self::ERROR_RETRYABLE_IO);
        }
        $this->require_confirmed_backup($operation, $backup, 'delete target backup');
    }

    /** @param array<string,mixed> $operation */
    private function require_confirmed_backup(array $operation, string $backup, string $description): void {
        if (( $operation['target_was_absent'] ?? null ) === true) {
            // An earlier ancestor replacement can leave an inherited path in
            // the backup tree. This operation did not create or own it.
            return;
        }
        if (( $operation['target_was_absent'] ?? null ) !== false) {
            throw new RuntimeException('Cannot confirm ' . $description . ' because the commit intent has no valid original-target state.');
        }
        $this->require_identity_match($backup, $operation['target_identity'] ?? null, $description, true);
    }

    /**
     * Inspect a relative target without ever resolving a symlinked ancestor.
     * A missing or non-directory ancestor makes the leaf absent in the safe
     * target namespace, which is sufficient for a descendant delete.
     *
     * @return array{present:bool,identity:?array<string,int>}
     */
    private function inspect_target_path_without_following(string $path): array {
        $segments = explode('/', $path);
        $leaf = array_pop($segments);
        $current = $this->target_root;
        foreach ($segments as $segment) {
            $current = $this->join_path($current, $segment);
            $stat = @lstat($current);
            if (!is_array($stat) || !$this->stat_is_directory($stat)) {
                return ['present' => false, 'identity' => null];
            }
        }
        $target = $this->join_path($current, $leaf);
        $stat = @lstat($target);
        if (!is_array($stat)) {
            return ['present' => false, 'identity' => null];
        }
        return ['present' => true, 'identity' => $this->identity_from_stat($stat)];
    }

    private function ensure_target_parents(string $path): void {
        $segments = explode('/', $path);
        array_pop($segments);
        $current = $this->target_root;
        foreach ($segments as $segment) {
            $current = $this->join_path($current, $segment);
            $stat = @lstat($current);
            if (is_array($stat)) {
                if (!$this->stat_is_directory($stat)) {
                    throw new RuntimeException('Refusing to apply through non-directory target parent: ' . $current);
                }
                continue;
            }
            if (!@mkdir($current, 0755)) {
                throw new RuntimeException('Could not create missing target parent directory: ' . $current, self::ERROR_RETRYABLE_IO);
            }
        }
    }
    private function advance_discard_cleanup(): bool {
        $discarding_stat = @lstat($this->discarding_session_dir);
        if (is_array($discarding_stat) && !self::stat_is_real_directory($discarding_stat)) {
            throw new RuntimeException('The staged apply discard tombstone is not a real directory: ' . $this->discarding_session_dir, self::ERROR_DISCARD_PENDING);
        }
        if (is_array(@lstat($this->discard_state_path)) && !self::is_real_regular_file($this->discard_state_path)) {
            throw new RuntimeException('The staged apply discard cleanup state is not a real regular file: ' . $this->discard_state_path, self::ERROR_DISCARD_PENDING);
        }
        $cleanup_lock_path = dirname($this->session_dir) . '/discard.lock';
        $cleanup_lock = @fopen($cleanup_lock_path, 'c+b');
        if ($cleanup_lock === false) {
            throw new RuntimeException('Could not open the staged apply discard cleanup lock: ' . $cleanup_lock_path, self::ERROR_DISCARD_PENDING);
        }
        try {
            if (!flock($cleanup_lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The staged apply discard cleanup is busy for session ' . $this->session_id . '.', self::ERROR_DISCARD_PENDING);
            }
            if (!is_array($discarding_stat)) {
                if (is_file($this->discard_state_path) && !@unlink($this->discard_state_path)) {
                    throw new RuntimeException('Could not remove completed staged apply discard state: ' . $this->discard_state_path, self::ERROR_DISCARD_PENDING);
                }
                return true;
            }

            $directory_stack = $this->read_discard_directory_stack();
            if (!is_file($this->discard_state_path)) {
                $this->write_discard_directory_stack($directory_stack);
            }
            for ($step = 0; $step < self::MAX_DISCARD_STEPS; $step++) {
                $directory_path = $this->discarding_session_dir;
                if ($directory_stack !== []) {
                    $directory_path .= '/' . implode('/', $directory_stack);
                }
                if (!is_dir($directory_path) || is_link($directory_path)) {
                    if ($directory_stack === []) {
                        throw new RuntimeException('The staged apply discard tombstone changed during cleanup: ' . $directory_path, self::ERROR_DISCARD_PENDING);
                    }
                    array_pop($directory_stack);
                    $this->write_discard_directory_stack($directory_stack);
                    continue;
                }

                $directory = @opendir($directory_path);
                if ($directory === false) {
                    throw new RuntimeException('Could not read staged apply discard directory: ' . $directory_path, self::ERROR_DISCARD_PENDING);
                }
                $entry = false;
                try {
                    while (true) {
                        $candidate = readdir($directory);
                        if ($candidate === false) {
                            break;
                        }
                        if ($candidate !== '.' && $candidate !== '..') {
                            $entry = $candidate;
                            break;
                        }
                    }
                } finally {
                    closedir($directory);
                }

                if ($entry === false) {
                    if (!@rmdir($directory_path)) {
                        throw new RuntimeException('Could not remove staged apply discard directory: ' . $directory_path, self::ERROR_DISCARD_PENDING);
                    }
                    if ($directory_stack === []) {
                        if (is_file($this->discard_state_path) && !@unlink($this->discard_state_path)) {
                            throw new RuntimeException('Could not remove completed staged apply discard state: ' . $this->discard_state_path, self::ERROR_DISCARD_PENDING);
                        }
                        return true;
                    }
                    array_pop($directory_stack);
                    continue;
                }

                $entry_path = $directory_path . '/' . $entry;
                if (is_dir($entry_path) && !is_link($entry_path)) {
                    $directory_stack[] = $entry;
                    // Descending does not change the filesystem. Persist it
                    // immediately so a timeout cannot make every retry walk
                    // the same slow prefix without durable progress.
                    $this->write_discard_directory_stack($directory_stack);
                    continue;
                }
                if (!@unlink($entry_path)) {
                    throw new RuntimeException('Could not remove staged apply discard path: ' . $entry_path, self::ERROR_DISCARD_PENDING);
                }
            }
            $this->write_discard_directory_stack($directory_stack);
        } finally {
            flock($cleanup_lock, LOCK_UN);
            fclose($cleanup_lock);
        }
        throw new RuntimeException('The staged apply discard cleanup is pending for session ' . $this->session_id . '.', self::ERROR_DISCARD_PENDING);
    }

    /** @return string[] */
    private function read_discard_directory_stack(): array {
        if (!is_file($this->discard_state_path)) {
            return [];
        }
        $contents = @file_get_contents($this->discard_state_path, false, null, 0, self::MAX_DISCARD_STATE_BYTES + 1);
        if (is_string($contents) && strlen($contents) > self::MAX_DISCARD_STATE_BYTES) {
            throw new RuntimeException('The staged apply discard cleanup state exceeds its bounded size: ' . $this->discard_state_path, self::ERROR_DISCARD_PENDING);
        }
        $state = is_string($contents) ? json_decode($contents, true) : null;
        $encoded_stack = is_array($state) ? ( $state['directory_stack_b64'] ?? null ) : null;
        if (
            !is_array($state)
            || ( $state['version'] ?? null ) !== 1
            || ( $state['session_id'] ?? null ) !== $this->session_id
            || !is_array($encoded_stack)
        ) {
            throw new RuntimeException('The staged apply discard cleanup state is malformed: ' . $this->discard_state_path, self::ERROR_DISCARD_PENDING);
        }
        $directory_stack = [];
        $path_bytes = 0;
        foreach ($encoded_stack as $encoded_entry) {
            $entry = is_string($encoded_entry) ? base64_decode($encoded_entry, true) : false;
            if ($entry === false || $entry === '' || $entry === '.' || $entry === '..' || strpos($entry, '/') !== false || strpos($entry, "\0") !== false) {
                throw new RuntimeException('The staged apply discard cleanup directory stack is malformed.', self::ERROR_DISCARD_PENDING);
            }
            $path_bytes += strlen($entry) + 1;
            if ($path_bytes > self::MAX_PATH_BYTES) {
                throw new RuntimeException('The staged apply discard cleanup directory stack exceeds the supported path length.', self::ERROR_DISCARD_PENDING);
            }
            $directory_stack[] = $entry;
        }
        return $directory_stack;
    }

    /** @param string[] $directory_stack */
    private function write_discard_directory_stack(array $directory_stack): void {
        $this->write_json_file($this->discard_state_path, [
            'version' => 1,
            'session_id' => $this->session_id,
            'directory_stack_b64' => array_map('base64_encode', $directory_stack),
        ], self::MAX_DISCARD_STATE_BYTES);
    }

    /** Fence a retried create until every signed request from this incarnation expires. */
    private function write_retired_session_marker(): void {
        if (!is_file($this->retired_session_path)) {
            $marker = @fopen($this->retired_session_path, 'x');
            if ($marker === false) {
                if (!is_file($this->retired_session_path)) {
                    throw new RuntimeException('Could not create the retired staged apply session marker: ' . $this->retired_session_path, self::ERROR_RETRYABLE_IO);
                }
            } else {
                try {
                    $contents = $this->session_id . "\n";
                    if (fwrite($marker, $contents) !== strlen($contents) || !fflush($marker)) {
                        throw new RuntimeException('Could not write the retired staged apply session marker: ' . $this->retired_session_path, self::ERROR_RETRYABLE_IO);
                    }
                } finally {
                    fclose($marker);
                }
                @chmod($this->retired_session_path, 0600);
            }
        }

        $sessions_dir = dirname($this->session_dir);
        $lock_path = $sessions_dir . '/retired-gc.lock';
        $lock = @fopen($lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open the retired staged apply session cleanup lock: ' . $lock_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Retired staged apply session cleanup is busy. Retry discard for session ' . $this->session_id . '.', self::ERROR_BUSY);
            }
            $current_dir = $sessions_dir . '/retired-gc-current';
            $deferred_dir = $sessions_dir . '/retired-gc-deferred';
            self::require_real_directory_path($current_dir, 'retired staged apply current cleanup directory', true);
            self::require_real_directory_path($deferred_dir, 'retired staged apply deferred cleanup directory', true);
            $current_path = $current_dir . '/' . $this->session_id;
            $deferred_path = $deferred_dir . '/' . $this->session_id;
            if (!is_file($current_path) && !is_file($deferred_path) && !@link($this->retired_session_path, $current_path)) {
                throw new RuntimeException('Could not queue retired staged apply session cleanup for ' . $this->session_id . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function claim_active_session(): void {
        $lock_path = $this->storage_dir . '/apply.lock';
        $lock = @fopen($lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open staged apply coordinator lock: ' . $lock_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException(
                    'Another staged apply session is changing the target. Retry after it finishes.',
                    self::ERROR_BUSY
                );
            }
            $this->recover_completed_maintenance_marker();
            $active_path = $this->storage_dir . '/active-apply.json';
            $active = $this->read_json_file($active_path);
            if (is_file($active_path) && !is_array($active)) {
                throw new RuntimeException('The staged apply coordinator record is malformed: ' . $active_path);
            }
            $active_id = is_array($active) ? ( $active['session_id'] ?? null ) : null;
            if (is_array($active) && !is_string($active_id)) {
                throw new RuntimeException('The staged apply coordinator record has no valid session id: ' . $active_path);
            }
            if (is_string($active_id) && $active_id !== $this->session_id) {
                $active_state_path = $this->storage_dir . '/apply-sessions/' . $active_id . '/state.json';
                $active_state = $this->read_json_file($active_state_path);
                if (!is_array($active_state) || !in_array($active_state['phase'] ?? null, ['complete'], true)) {
                    throw new RuntimeException(
                        'Staged apply session ' . $active_id . ' owns the target. Resume or discard that session before starting another.',
                        self::ERROR_BUSY
                    );
                }
            }
            $this->write_json_file($active_path, ['session_id' => $this->session_id]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recover_completed_maintenance_marker(): void {
        $maintenance_path = $this->target_path(self::MAINTENANCE_FILENAME);
        $contents = $this->read_maintenance_marker_contents($maintenance_path, 'completed staged apply maintenance marker');
        if ($contents === null) {
            return;
        }
        if (!preg_match('/\A<\?php\n\$upgrading = [0-9]+;\n\/\/ reprint-staged-apply-session:([a-f0-9]{32})\n\z/D', $contents, $matches)) {
            return;
        }
        $completed_session_dir = $this->storage_dir . '/apply-sessions/' . $matches[1];
        $owned_marker = $this->maintenance_marker_identity_for_target(
            $maintenance_path,
            $matches[1],
            [
                $completed_session_dir . '/work/maintenance-marker.php',
                $completed_session_dir . '/work/maintenance-marker.previous.php',
            ],
            $contents
        );
        if ($owned_marker === null || $owned_marker['contents'] !== $contents) {
            return;
        }
        $owned_identity_path = $owned_marker['identity_path'];
        $state = $this->read_json_file($completed_session_dir . '/state.json');
        $encoded_target_root = is_array($state) ? ( $state['target_root_b64'] ?? null ) : null;
        $completed_target_root = is_string($encoded_target_root) ? base64_decode($encoded_target_root, true) : false;
        $completed_or_discarding = is_array($state)
            && ( $state['phase'] === 'complete' || ( $state['phase'] === 'discarding' && ( $state['discarding_complete'] ?? false ) === true ) );
        if (!$completed_or_discarding || $completed_target_root !== $this->target_root) {
            return;
        }
        $confirmed_marker = $this->maintenance_marker_identity_for_target(
            $maintenance_path,
            $matches[1],
            [$owned_identity_path],
            $owned_marker['contents']
        );
        if (
            $confirmed_marker === null
            || $confirmed_marker['identity_path'] !== $owned_identity_path
            || $confirmed_marker['contents'] !== $owned_marker['contents']
        ) {
            return;
        }
        if (!@unlink($maintenance_path)) {
            $confirmed_marker = $this->maintenance_marker_identity_for_target(
                $maintenance_path,
                $matches[1],
                [$owned_identity_path],
                $owned_marker['contents']
            );
            if (
                $confirmed_marker === null
                || $confirmed_marker['identity_path'] !== $owned_identity_path
                || $confirmed_marker['contents'] !== $owned_marker['contents']
            ) {
                return;
            }
            throw new RuntimeException('Could not remove the maintenance marker left by completed staged apply session ' . $matches[1] . ': ' . $maintenance_path, self::ERROR_RETRYABLE_IO);
        }
    }

    private function release_active_session(): void {
        $lock_path = $this->storage_dir . '/apply.lock';
        $lock = @fopen($lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open staged apply coordinator lock: ' . $lock_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The staged apply coordinator is busy while releasing session ' . $this->session_id . '. Retry.', self::ERROR_BUSY);
            }
            $active_path = $this->storage_dir . '/active-apply.json';
            $active = $this->read_json_file($active_path);
            if (is_array($active) && ( $active['session_id'] ?? null ) === $this->session_id && is_file($active_path) && !@unlink($active_path)) {
                throw new RuntimeException('Could not release staged apply session ownership: ' . $active_path, self::ERROR_RETRYABLE_IO);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function create_maintenance_marker(): void {
        $maintenance_path = $this->target_path(self::MAINTENANCE_FILENAME);
        $marker = '// reprint-staged-apply-session:' . $this->session_id;
        $owned_identity_path = null;
        $owned_marker_contents = null;
        if ($this->path_present($maintenance_path)) {
            if (is_link($maintenance_path) || !is_file($maintenance_path)) {
                throw new RuntimeException(
                    'The target already has a maintenance marker not owned by staged apply: ' . $maintenance_path,
                    self::ERROR_BUSY
                );
            }
            $owned_marker = $this->maintenance_marker_identity_for_target(
                $maintenance_path,
                $this->session_id,
                [
                    $this->maintenance_marker_identity_path,
                    $this->previous_maintenance_marker_identity_path,
                ]
            );
            if ($owned_marker === null) {
                throw new RuntimeException(
                    'The target already has a maintenance marker not owned by staged apply: ' . $maintenance_path,
                    self::ERROR_BUSY
                );
            }
            $owned_identity_path = $owned_marker['identity_path'];
            $owned_marker_contents = $owned_marker['contents'];
        }

        if ($owned_identity_path === null) {
            $this->clear_private_maintenance_marker_path($this->previous_maintenance_marker_identity_path);
            $this->clear_private_maintenance_marker_path($this->next_maintenance_marker_link_path);
            $this->publish_maintenance_marker_identity($marker);
            if (!@link($this->maintenance_marker_identity_path, $maintenance_path)) {
                $code = $this->path_present($maintenance_path) ? self::ERROR_BUSY : self::ERROR_RETRYABLE_IO;
                throw new RuntimeException(
                    'Could not publish the staged apply maintenance marker: ' . $maintenance_path,
                    $code
                );
            }
            return;
        }

        $this->clear_private_maintenance_marker_path($this->next_maintenance_marker_link_path);
        if ($owned_identity_path === $this->maintenance_marker_identity_path) {
            $this->clear_private_maintenance_marker_path($this->previous_maintenance_marker_identity_path);
            if (!@rename($this->maintenance_marker_identity_path, $this->previous_maintenance_marker_identity_path)) {
                throw new RuntimeException(
                    'Could not retain the current staged apply maintenance marker identity before refresh.',
                    self::ERROR_RETRYABLE_IO
                );
            }
        } else {
            $this->clear_private_maintenance_marker_path($this->maintenance_marker_identity_path);
        }

        $this->publish_maintenance_marker_identity($marker);
        if (!@link($this->maintenance_marker_identity_path, $this->next_maintenance_marker_link_path)) {
            throw new RuntimeException(
                'Could not retain the next staged apply maintenance marker identity before refresh.',
                self::ERROR_RETRYABLE_IO
            );
        }
        $confirmed_marker = $this->maintenance_marker_identity_for_target(
            $maintenance_path,
            $this->session_id,
            [$this->previous_maintenance_marker_identity_path],
            $owned_marker_contents
        );
        if (
            $confirmed_marker === null
            || $confirmed_marker['identity_path'] !== $this->previous_maintenance_marker_identity_path
            || $confirmed_marker['contents'] !== $owned_marker_contents
        ) {
            throw new RuntimeException(
                'The target maintenance marker changed before staged apply could refresh it: ' . $maintenance_path,
                self::ERROR_BUSY
            );
        }
        // Rename a complete hardlink over the stale owned marker. The new
        // private identity remains behind, so later cleanup never trusts
        // marker text alone and no crash can expose a partial marker.
        if (!@rename($this->next_maintenance_marker_link_path, $maintenance_path)) {
            $confirmed_marker = $this->maintenance_marker_identity_for_target(
                $maintenance_path,
                $this->session_id,
                [$this->previous_maintenance_marker_identity_path],
                $owned_marker_contents
            );
            $still_owned = $confirmed_marker !== null
                && $confirmed_marker['identity_path'] === $this->previous_maintenance_marker_identity_path
                && $confirmed_marker['contents'] === $owned_marker_contents;
            throw new RuntimeException(
                'Could not atomically refresh the staged apply maintenance marker: ' . $maintenance_path,
                $still_owned ? self::ERROR_RETRYABLE_IO : self::ERROR_BUSY
            );
        }
        $this->clear_private_maintenance_marker_path($this->previous_maintenance_marker_identity_path);
    }

    private function publish_maintenance_marker_identity(string $marker): void {
        $temporary_path = $this->maintenance_marker_identity_path . '.tmp';
        if ($this->path_present($temporary_path) && !@unlink($temporary_path)) {
            throw new RuntimeException(
                'Could not clear the staged apply maintenance marker temporary file: ' . $temporary_path,
                self::ERROR_RETRYABLE_IO
            );
        }
        $this->ensure_workspace_parent($temporary_path);
        $handle = @fopen($temporary_path, 'x');
        if ($handle === false) {
            throw new RuntimeException(
                'Could not create the staged apply maintenance marker temporary file: ' . $temporary_path,
                self::ERROR_RETRYABLE_IO
            );
        }
        $contents = "<?php\n\$upgrading = " . time() . ";\n" . $marker . "\n";
        $write_error = null;
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException(
                    'Could not flush the staged apply maintenance marker temporary file: ' . $temporary_path,
                    self::ERROR_RETRYABLE_IO
                );
            }
        } catch (Throwable $exception) {
            $write_error = $exception;
        } finally {
            fclose($handle);
        }
        if ($write_error !== null) {
            @unlink($temporary_path);
            throw $write_error;
        }
        if (!@rename($temporary_path, $this->maintenance_marker_identity_path)) {
            @unlink($temporary_path);
            throw new RuntimeException(
                'Could not commit the staged apply maintenance marker identity: ' . $this->maintenance_marker_identity_path,
                self::ERROR_RETRYABLE_IO
            );
        }
        @chmod($this->maintenance_marker_identity_path, 0600);
    }

    private function remove_maintenance_marker(): void {
        $maintenance_path = $this->target_path(self::MAINTENANCE_FILENAME);
        if (!$this->path_present($maintenance_path)) {
            return;
        }
        $owned_marker = $this->maintenance_marker_identity_for_target(
            $maintenance_path,
            $this->session_id,
            [
                $this->maintenance_marker_identity_path,
                $this->previous_maintenance_marker_identity_path,
            ]
        );
        if ($owned_marker === null) {
            return;
        }
        $owned_identity_path = $owned_marker['identity_path'];
        $confirmed_marker = $this->maintenance_marker_identity_for_target(
            $maintenance_path,
            $this->session_id,
            [$owned_identity_path],
            $owned_marker['contents']
        );
        if (
            $confirmed_marker === null
            || $confirmed_marker['identity_path'] !== $owned_identity_path
            || $confirmed_marker['contents'] !== $owned_marker['contents']
        ) {
            return;
        }
        if (!@unlink($maintenance_path)) {
            $confirmed_marker = $this->maintenance_marker_identity_for_target(
                $maintenance_path,
                $this->session_id,
                [$owned_identity_path],
                $owned_marker['contents']
            );
            if (
                $confirmed_marker === null
                || $confirmed_marker['identity_path'] !== $owned_identity_path
                || $confirmed_marker['contents'] !== $owned_marker['contents']
            ) {
                return;
            }
            throw new RuntimeException(
                'Could not remove the staged apply maintenance marker: ' . $maintenance_path,
                self::ERROR_RETRYABLE_IO
            );
        }
    }

    /**
     * @param string[] $identity_paths
     * @return array{identity_path:string,contents:string}|null
     */
    private function maintenance_marker_identity_for_target(string $maintenance_path, string $session_id, array $identity_paths, ?string $expected_contents = null): ?array {
        clearstatcache(true, $maintenance_path);
        $before_stat = @lstat($maintenance_path);
        if (
            !is_array($before_stat)
            || !isset($before_stat['dev'], $before_stat['ino'], $before_stat['mode'], $before_stat['size'], $before_stat['ctime'])
            || !is_int($before_stat['dev'])
            || !is_int($before_stat['ino'])
            || !is_int($before_stat['mode'])
            || !is_int($before_stat['size'])
            || !is_int($before_stat['ctime'])
            || ( $before_stat['mode'] & 0170000 ) !== 0100000
            || $before_stat['size'] > self::MAX_MAINTENANCE_MARKER_BYTES
        ) {
            return null;
        }

        $matching_identity_path = null;
        foreach ($identity_paths as $identity_path) {
            clearstatcache(true, $identity_path);
            $identity_stat = @lstat($identity_path);
            if (
                is_array($identity_stat)
                && isset($identity_stat['dev'], $identity_stat['ino'], $identity_stat['mode'])
                && is_int($identity_stat['dev'])
                && is_int($identity_stat['ino'])
                && is_int($identity_stat['mode'])
                && ( $identity_stat['mode'] & 0170000 ) === 0100000
                && $identity_stat['dev'] === $before_stat['dev']
                && $identity_stat['ino'] === $before_stat['ino']
            ) {
                $matching_identity_path = $identity_path;
                break;
            }
        }
        if ($matching_identity_path === null) {
            return null;
        }

        $contents = $this->read_maintenance_marker_contents($maintenance_path, 'staged apply maintenance marker');
        if ($contents === null) {
            return null;
        }
        if ($expected_contents !== null && $contents !== $expected_contents) {
            return null;
        }
        clearstatcache(true, $maintenance_path);
        clearstatcache(true, $matching_identity_path);
        $after_stat = @lstat($maintenance_path);
        $identity_stat = @lstat($matching_identity_path);
        if (
            !is_array($after_stat)
            || !is_array($identity_stat)
            || !isset($after_stat['dev'], $after_stat['ino'], $after_stat['mode'], $after_stat['size'], $after_stat['ctime'])
            || !isset($identity_stat['dev'], $identity_stat['ino'], $identity_stat['mode'])
            || $after_stat['dev'] !== $before_stat['dev']
            || $after_stat['ino'] !== $before_stat['ino']
            || $after_stat['mode'] !== $before_stat['mode']
            || $after_stat['size'] !== $before_stat['size']
            || $after_stat['ctime'] !== $before_stat['ctime']
            || $identity_stat['dev'] !== $before_stat['dev']
            || $identity_stat['ino'] !== $before_stat['ino']
            || $identity_stat['mode'] !== $before_stat['mode']
        ) {
            return null;
        }
        $confirmed_contents = $this->read_maintenance_marker_contents($matching_identity_path, 'private staged apply maintenance marker identity');
        if ($confirmed_contents === null) {
            return null;
        }
        if (
            $confirmed_contents !== $contents
            || ( $expected_contents !== null && $confirmed_contents !== $expected_contents )
            || preg_match(
                '/\A<\?php\n\$upgrading = [0-9]+;\n\/\/ reprint-staged-apply-session:'
                    . preg_quote($session_id, '/') . '\n\z/D',
                $confirmed_contents
            ) !== 1
        ) {
            return null;
        }
        clearstatcache(true, $maintenance_path);
        clearstatcache(true, $matching_identity_path);
        $final_stat = @lstat($maintenance_path);
        $final_identity_stat = @lstat($matching_identity_path);
        if (
            !is_array($final_stat)
            || !is_array($final_identity_stat)
            || !isset($final_stat['dev'], $final_stat['ino'], $final_stat['mode'], $final_stat['size'], $final_stat['ctime'])
            || !isset($final_identity_stat['dev'], $final_identity_stat['ino'], $final_identity_stat['mode'])
            || $final_stat['dev'] !== $before_stat['dev']
            || $final_stat['ino'] !== $before_stat['ino']
            || $final_stat['mode'] !== $before_stat['mode']
            || $final_stat['size'] !== $before_stat['size']
            || $final_stat['ctime'] !== $before_stat['ctime']
            || $final_identity_stat['dev'] !== $before_stat['dev']
            || $final_identity_stat['ino'] !== $before_stat['ino']
            || $final_identity_stat['mode'] !== $before_stat['mode']
        ) {
            return null;
        }
        return [
            'identity_path' => $matching_identity_path,
            'contents' => $confirmed_contents,
        ];
    }

    private function read_maintenance_marker_contents(string $path, string $description): ?string {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (
            !is_array($stat)
            || !isset($stat['mode'], $stat['size'])
            || !is_int($stat['mode'])
            || !is_int($stat['size'])
            || ( $stat['mode'] & 0170000 ) !== 0100000
            || $stat['size'] < 0
            || $stat['size'] > self::MAX_MAINTENANCE_MARKER_BYTES
        ) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_MAINTENANCE_MARKER_BYTES + 1);
        if (!is_string($contents)) {
            if ($this->path_present($path)) {
                throw new RuntimeException('Could not read the ' . $description . ': ' . $path, self::ERROR_RETRYABLE_IO);
            }
            return null;
        }
        if (strlen($contents) > self::MAX_MAINTENANCE_MARKER_BYTES) {
            return null;
        }
        return $contents;
    }

    private function clear_private_maintenance_marker_path(string $path): void {
        if (!$this->path_present($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            throw new RuntimeException(
                'Could not clear staged apply private maintenance marker path: ' . $path,
                self::ERROR_RETRYABLE_IO
            );
        }
        if (!@unlink($path)) {
            throw new RuntimeException(
                'Could not clear staged apply private maintenance marker path: ' . $path,
                self::ERROR_RETRYABLE_IO
            );
        }
    }

    private function move_session_to_discarding_directory(): void {
        if (is_dir($this->discarding_session_dir) || is_file($this->discard_state_path)) {
            throw new RuntimeException('The staged apply discard tombstone already exists: ' . $this->discarding_session_dir);
        }
        if (!@rename($this->session_dir, $this->discarding_session_dir)) {
            throw new RuntimeException('Could not move staged apply session into its discard tombstone: ' . $this->session_dir, self::ERROR_RETRYABLE_IO);
        }
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function serialize_entry(array $entry): array {
        $serialized = $entry;
        $serialized['path_b64'] = base64_encode($entry['path']);
        unset($serialized['path']);
        if (array_key_exists('target', $serialized)) {
            $serialized['target_b64'] = base64_encode($serialized['target']);
            unset($serialized['target']);
        }
        return $serialized;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function deserialize_entry(array $entry): array {
        $type = $entry['type'] ?? null;
        $encoded_path = $entry['path_b64'] ?? null;
        $path = is_string($encoded_path) ? base64_decode($encoded_path, true) : false;
        if (!in_array($type, ['file', 'directory', 'symlink', 'delete'], true) || $path === false || $path === '') {
            throw new RuntimeException('The target-authored staged apply operation entry is malformed.');
        }
        // Rebuild the decoded entry in its canonical in-memory order. JSON
        // serialization moves the base64 path field; keeping that wire order
        // would make strict crash-replay descriptor comparison fail even
        // though every key and value is identical.
        $decoded = ['type' => $type, 'path' => $path];
        foreach ($entry as $field => $value) {
            if (!in_array($field, ['type', 'path_b64', 'target_b64'], true)) {
                $decoded[$field] = $value;
            }
        }
        $this->require_writable_target_path($path, $type);
        if ($type === 'file') {
            if (!isset($decoded['bytes']) || !is_int($decoded['bytes']) || $decoded['bytes'] < 0) {
                throw new RuntimeException('The target-authored staged apply file byte count is malformed for ' . $this->describe_path($path) . '.');
            }
            if (array_key_exists('revision', $decoded) && ( !is_int($decoded['revision']) || $decoded['revision'] < 0 )) {
                throw new RuntimeException('The staged apply file revision is malformed for ' . $this->describe_path($path) . '.');
            }
        }
        if ($type === 'symlink') {
            $encoded_target = $entry['target_b64'] ?? null;
            $target = is_string($encoded_target) ? base64_decode($encoded_target, true) : false;
            if ($target === false || $target === '' || strpos($target, "\0") !== false) {
                throw new RuntimeException('The target-authored staged apply symlink target is malformed for ' . $this->describe_path($path) . '.');
            }
            $decoded['target'] = $target;
        }
        if (in_array($type, ['file', 'symlink'], true) && array_key_exists('staged_identity', $decoded)) {
            $this->require_valid_identity($decoded['staged_identity'], 'staged operation identity');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $operation */
    private function write_operation(array $operation): void {
        if (isset($operation['entry'])) {
            if (!is_array($operation['entry'])) {
                throw new RuntimeException('Cannot write staged apply operation with a malformed entry.');
            }
            $operation['entry'] = $this->serialize_entry($operation['entry']);
        }
        $this->write_json_file($this->operation_path, $operation);
    }

    /** @return array<string,mixed>|null */
    private function read_operation(): ?array {
        if (!$this->path_present($this->operation_path)) {
            return null;
        }
        if (!is_file($this->operation_path) || is_link($this->operation_path)) {
            throw new RuntimeException('The staged apply current operation is not a regular file: ' . $this->operation_path);
        }
        $operation = $this->read_json_file($this->operation_path);
        if (!is_array($operation) || !is_string($operation['purpose'] ?? null) || !isset($operation['entry']) || !is_array($operation['entry'])) {
            throw new RuntimeException('The staged apply current operation is malformed: ' . $this->operation_path);
        }
        if (!in_array($operation['purpose'], ['upload', 'commit'], true)) {
            throw new RuntimeException('The staged apply current operation has an invalid purpose.');
        }
        $operation['entry'] = $this->deserialize_entry($operation['entry']);
        return $operation;
    }

    private function clear_operation(): void {
        if ($this->path_present($this->operation_path) && !@unlink($this->operation_path)) {
            throw new RuntimeException('Could not clear the completed staged apply current operation: ' . $this->operation_path, self::ERROR_RETRYABLE_IO);
        }
    }

    /** @param array<string,mixed> $state */
    private function write_state(array $state): void {
        $this->write_json_file($this->state_path, $state);
    }

    /** @return array<string,mixed> */
    private function read_state(bool $require_target_identity = true): array {
        $state = $this->read_json_file($this->state_path);
        if (!is_array($state) || ( $state['version'] ?? null ) !== 1 || ( $state['session_id'] ?? null ) !== $this->session_id) {
            throw new RuntimeException('The staged apply session state is missing or malformed: ' . $this->state_path);
        }
        $encoded_target_root = $state['target_root_b64'] ?? null;
        $encoded_protected_paths = $state['protected_paths_b64'] ?? null;
        if (!is_string($encoded_target_root) || !is_array($encoded_protected_paths)) {
            throw new RuntimeException('The staged apply session configuration is malformed.');
        }
        $stored_target_root = base64_decode($encoded_target_root, true);
        $stored_protected_paths = [];
        foreach ($encoded_protected_paths as $encoded_path) {
            $protected_path = is_string($encoded_path) ? base64_decode($encoded_path, true) : false;
            if ($protected_path === false || $protected_path === '') {
                throw new RuntimeException('The staged apply session protected paths are malformed.');
            }
            $stored_protected_paths[] = $protected_path;
        }
        if ($stored_target_root !== $this->target_root || $stored_protected_paths !== $this->protected_paths) {
            throw new RuntimeException('The staged apply session configuration no longer matches its target or protected paths.');
        }
        foreach (['target_root_dev', 'target_root_ino', 'request_generation', 'operation_count', 'journal_bytes', 'commit_offset', 'commit_count'] as $field) {
            if (!isset($state[$field]) || !is_int($state[$field]) || $state[$field] < 0) {
                throw new RuntimeException('The staged apply session state field ' . $field . ' must be a non-negative integer.');
            }
        }
        if ($require_target_identity) {
            $target_stat = @lstat($this->target_root);
            if (!is_array($target_stat) || !isset($target_stat['dev'], $target_stat['ino'])) {
                throw new RuntimeException('Could not confirm the staged apply target root identity: ' . $this->target_root, self::ERROR_RETRYABLE_IO);
            }
            if ( (int) $target_stat['dev'] !== $state['target_root_dev'] || (int) $target_stat['ino'] !== $state['target_root_ino']) {
                throw new RuntimeException('The staged apply target root was replaced after session creation: ' . $this->target_root);
            }
        }
        $phase = $state['phase'] ?? null;
        if (!in_array($phase, ['uploading', 'committing', 'complete', 'failed', 'discarding'], true)) {
            throw new RuntimeException('The staged apply session phase is invalid: ' . ( is_string($phase) ? $phase : gettype($phase) ) . '.');
        }
        if ($state['commit_offset'] > $state['journal_bytes'] || $state['commit_count'] > $state['operation_count']) {
            throw new RuntimeException('The staged apply commit cursor exceeds its target-authored operation journal.');
        }
        if (in_array($phase, ['uploading', 'failed'], true) && ( $state['commit_offset'] !== 0 || $state['commit_count'] !== 0 )) {
            throw new RuntimeException('The staged apply pre-commit phase contains a live target cursor.');
        }
        if ($phase === 'complete' && ( $state['commit_offset'] !== $state['journal_bytes'] || $state['commit_count'] !== $state['operation_count'] )) {
            throw new RuntimeException('The completed staged apply session has an incomplete commit cursor.');
        }
        if (!array_key_exists('current_file', $state)) {
            throw new RuntimeException('The staged apply current file state is missing.');
        }
        if ($state['current_file'] !== null) {
            $current_file = $state['current_file'];
            $path = is_array($current_file) && is_string($current_file['path_b64'] ?? null)
                ? base64_decode($current_file['path_b64'], true)
                : false;
            if (
                !is_array($current_file)
                || !isset($current_file['operation_index'], $current_file['revision'], $current_file['committed_bytes'], $current_file['total_bytes'])
                || !is_int($current_file['operation_index'])
                || $current_file['operation_index'] !== $state['operation_count']
                || $path === false
                || $path === ''
                || !is_int($current_file['revision'])
                || $current_file['revision'] < 0
                || !is_int($current_file['committed_bytes'])
                || !is_int($current_file['total_bytes'])
                || $current_file['committed_bytes'] < 0
                || $current_file['committed_bytes'] > $current_file['total_bytes']
            ) {
                throw new RuntimeException('The staged apply current file cursor is malformed.');
            }
            $this->require_writable_target_path($path, 'file');
            $restart_pending = $current_file['restart_pending'] ?? false;
            $restart_previous_total_bytes = $current_file['restart_previous_total_bytes'] ?? null;
            if (
                !is_bool($restart_pending)
                || ( $restart_pending && ( !is_int($restart_previous_total_bytes) || $restart_previous_total_bytes < 0 ) )
                || ( !$restart_pending && $restart_previous_total_bytes !== null )
            ) {
                throw new RuntimeException('The staged apply current file restart state is malformed.');
            }
            if (!in_array($phase, ['uploading', 'failed', 'discarding'], true)) {
                throw new RuntimeException('The staged apply phase cannot retain a current file cursor.');
            }
        }
        $last_path_b64 = $state['last_path_b64'] ?? null;
        $last_type = $state['last_type'] ?? null;
        $last_path = is_string($last_path_b64) ? base64_decode($last_path_b64, true) : null;
        if (
            ( $last_path_b64 === null ) !== ( $last_type === null )
            || ( $last_path_b64 !== null && ( $last_path === false || $last_path === '' ) )
            || ( $last_type !== null && !in_array($last_type, ['file', 'directory', 'symlink', 'delete'], true) )
            || ( ( $state['operation_count'] === 0 ) !== ( $last_path_b64 === null ) )
        ) {
            throw new RuntimeException('The staged apply last accepted path state is malformed.');
        }
        if (is_string($last_path)) {
            $this->require_writable_target_path($last_path, $last_type);
        }
        $failure = $state['failure'] ?? null;
        if (( $phase === 'failed' && ( !is_string($failure) || $failure === '' ) ) || ( $phase !== 'failed' && $failure !== null )) {
            throw new RuntimeException('The staged apply failure state is malformed.');
        }
        if (!is_bool($state['retire_session_id'] ?? null)) {
            throw new RuntimeException('The staged apply create retry state is malformed.');
        }
        return $state;
    }

    /** @return array<string,mixed>|null */
    private function read_json_file(string $path): ?array {
        if (!$this->path_present($path)) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_METADATA_BYTES + 1);
        if ($contents === false) {
            throw new RuntimeException('Could not read staged apply metadata: ' . $path, self::ERROR_RETRYABLE_IO);
        }
        if (strlen($contents) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('The staged apply metadata exceeds the bounded size: ' . $path);
        }
        $value = json_decode($contents, true);
        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    private function write_json_file(string $path, array $value, int $max_bytes = self::MAX_METADATA_BYTES): void {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode staged apply metadata: ' . $path);
        }
        if (strlen($encoded) > $max_bytes) {
            throw new RuntimeException('The staged apply metadata exceeds the bounded size: ' . $path);
        }
        $tmp = $path . '.tmp';
        $tmp_stat = @lstat($tmp);
        if (is_array($tmp_stat)) {
            if (self::stat_is_real_directory($tmp_stat)) {
                throw new RuntimeException('The staged apply metadata temporary path is a directory: ' . $tmp);
            }
            if (!@unlink($tmp)) {
                throw new RuntimeException('Could not clear staged apply metadata temporary path: ' . $tmp, self::ERROR_RETRYABLE_IO);
            }
        }
        // Exclusive creation cannot follow a symlink planted after the
        // lstat/unlink check. A leftover hard link is unlinked, never opened.
        $handle = @fopen($tmp, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Could not write staged apply metadata: ' . $tmp, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!$this->write_all($handle, $encoded) || !fflush($handle)) {
                throw new RuntimeException('Could not flush staged apply metadata: ' . $tmp, self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Could not commit staged apply metadata: ' . $path, self::ERROR_RETRYABLE_IO);
        }
    }

    /** @return mixed */
    private function with_session_lock(callable $callback) {
        if (!$this->session_directory_exists()) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $this->require_private_workspace();
        $lock = @fopen($this->lock_path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open staged apply session lock: ' . $this->lock_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry with the same session id.', self::ERROR_BUSY);
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function require_expected_request_generation(array $state, int $expected_request_generation): void {
        if ($expected_request_generation < 0) {
            throw new InvalidArgumentException('The staged apply expected request generation must be non-negative; observed ' . $expected_request_generation . '.');
        }
        if ($state['request_generation'] !== $expected_request_generation) {
            throw new RuntimeException(
                'The staged apply expected generation ' . $expected_request_generation . ' does not match server-confirmed generation '
                . $state['request_generation'] . '. Fetch status and retry from that generation.',
                self::ERROR_STALE_GENERATION
            );
        }
    }

    private function session_directory_exists(): bool {
        clearstatcache(true, $this->session_dir);
        return is_dir($this->session_dir);
    }

    /**
     * Create real 0700 staging parents without ever traversing a symlink.
     *
     * @return bool False only when an existing non-directory ancestor is
     *   allowed to make a descendant delete journal-only.
     */
    private function ensure_staged_parents(string $path, bool $allow_non_directory_ancestor = false): bool {
        $segments = explode('/', $path);
        array_pop($segments);
        $current = $this->staged_dir;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if (is_array($stat)) {
                if ($this->stat_is_directory($stat)) {
                    continue;
                }
                if ($allow_non_directory_ancestor) {
                    return false;
                }
                return false;
            }
            if ($this->path_present($current)) {
                if ($allow_non_directory_ancestor) {
                    return false;
                }
                return false;
            }
            if (!@mkdir($current, 0700)) {
                throw new RuntimeException('Could not create staged apply parent directory: ' . $current, self::ERROR_RETRYABLE_IO);
            }
        }
        return true;
    }

    private function require_existing_staged_parents(string $path): void {
        $segments = explode('/', $path);
        array_pop($segments);
        $current = $this->staged_dir;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if (!is_array($stat) || !$this->stat_is_directory($stat)) {
                throw new RuntimeException(
                    'Staged apply parent is missing or no longer a real directory: ' . $current
                );
            }
        }
    }

    private function require_writable_target_path(string $path, ?string $entry_type = null): void {
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new RuntimeException('Refusing unsafe staged apply path: ' . $this->describe_path($path));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Refusing unsafe staged apply path: ' . $this->describe_path($path));
            }
        }
        $first_segment = explode('/', $path, 2)[0];
        if ($first_segment === self::MAINTENANCE_FILENAME) {
            throw new RuntimeException('Refusing staged apply maintenance path: ' . $this->describe_path($path));
        }
        foreach ($this->protected_paths as $protected_path) {
            if ($path === $protected_path || strpos($path, $protected_path . '/') === 0) {
                throw new RuntimeException('Refusing protected staged apply path: ' . $this->describe_path($path));
            }
            if (strpos($protected_path, $path . '/') === 0 && $entry_type !== 'directory') {
                throw new RuntimeException('Refusing protected staged apply ancestor path: ' . $this->describe_path($path));
            }
        }
    }

    private function is_protected_ancestor(string $path): bool {
        foreach ($this->protected_paths as $protected_path) {
            if (strpos($protected_path, $path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private function staged_path(string $path): string {
        return $this->staged_dir . '/' . $path;
    }

    private function backup_path(string $path): string {
        return $this->backup_dir . '/' . $path;
    }

    private function target_path(string $path): string {
        return $this->join_path($this->target_root, $path);
    }

    private function join_path(string $root, string $path): string {
        return $root === '/' ? '/' . $path : $root . '/' . $path;
    }

    private function path_present(string $path): bool {
        return file_exists($path) || is_link($path);
    }

    private function describe_path(string $path): string {
        return 'base64:' . base64_encode($path);
    }

    private function move_to_backup(string $target, string $backup): bool {
        $this->ensure_workspace_parent($backup);
        $this->require_same_filesystem_for_rename($target, $backup, 'move target into backup');
        return @rename($target, $backup);
    }

    private function ensure_workspace_parent(string $path): void {
        $parent = dirname($path);
        $session_prefix = $this->session_dir . '/';
        if ($parent !== $this->session_dir && strpos($parent, $session_prefix) !== 0) {
            throw new LogicException('A staged apply workspace path escaped its session directory: ' . $path);
        }
        $relative = $parent === $this->session_dir ? '' : substr($parent, strlen($session_prefix));
        $current = $this->session_dir;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if (is_array($stat)) {
                if (!$this->stat_is_directory($stat)) {
                    throw new RuntimeException('Staged apply workspace parent is not a real directory: ' . $current);
                }
                continue;
            }
            if (!@mkdir($current, 0700)) {
                throw new RuntimeException('Could not create staged apply workspace parent: ' . $current, self::ERROR_RETRYABLE_IO);
            }
        }
    }

    private function require_unfollowed_workspace_parents(string $path): void {
        $parent = dirname($path);
        $session_prefix = $this->session_dir . '/';
        if ($parent !== $this->session_dir && strpos($parent, $session_prefix) !== 0) {
            throw new LogicException('A staged apply workspace path escaped its session directory: ' . $path);
        }
        $relative = $parent === $this->session_dir ? '' : substr($parent, strlen($session_prefix));
        $current = $this->session_dir;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if (!is_array($stat)) {
                return;
            }
            if ($this->stat_is_directory($stat)) {
                continue;
            }
            if (is_link($current)) {
                throw new RuntimeException('Staged apply workspace parent became a symlink: ' . $current);
            }
            return;
        }
    }

    private function require_same_filesystem_for_rename(string $source, string $destination, string $operation): void {
        $source_stat = @lstat($source);
        $destination_stat = @lstat(dirname($destination));
        if (!is_array($source_stat) || !is_array($destination_stat) || !isset($source_stat['dev'], $destination_stat['dev'])) {
            throw new RuntimeException('Could not determine filesystem devices to ' . $operation . '.', self::ERROR_RETRYABLE_IO);
        }
        if ( (int) $source_stat['dev'] !== (int) $destination_stat['dev']) {
            throw new RuntimeException('Cannot atomically ' . $operation . ' across filesystem devices.');
        }
    }

    /** @return array<string,int> */
    private function require_path_identity(string $path, string $description): array {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            throw new RuntimeException('Could not read ' . $description . ' identity: ' . $path, self::ERROR_RETRYABLE_IO);
        }
        return $this->identity_from_stat($stat);
    }

    /** @param array<string,mixed> $stat @return array<string,int> */
    private function identity_from_stat(array $stat): array {
        $identity = [];
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($stat[$field]) || !is_int($stat[$field])) {
                throw new RuntimeException('A staged apply filesystem identity has no integer ' . $field . ' field.', self::ERROR_RETRYABLE_IO);
            }
            $identity[$field] = $stat[$field];
        }
        return $identity;
    }

    /** @param mixed $identity */
    private function require_valid_identity($identity, string $description): void {
        if (!is_array($identity)) {
            throw new RuntimeException('The ' . $description . ' is malformed.');
        }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($identity[$field]) || !is_int($identity[$field])) {
                throw new RuntimeException('The ' . $description . ' has no integer ' . $field . ' field.');
            }
        }
    }

    /** @param mixed $expected */
    private function require_identity_match(string $path, $expected, string $description, bool $ignore_ctime = false): void {
        $this->require_valid_identity($expected, $description . ' identity');
        $observed = $this->require_path_identity($path, $description);
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ($ignore_ctime && $field === 'ctime') {
                continue;
            }
            if ($observed[$field] !== $expected[$field]) {
                throw new RuntimeException(
                    'Cannot confirm ' . $description . ' at ' . $path . '; identity field ' . $field
                    . ' expected ' . $expected[$field] . ', observed ' . $observed[$field] . '.'
                );
            }
        }
    }

    /** @param array<string,mixed> $stat */
    private function stat_is_directory(array $stat): bool {
        return isset($stat['mode']) && ( ( (int) $stat['mode'] & 0170000 ) === 0040000 );
    }

    /** @param resource $handle */
    private function write_all($handle, string $bytes): bool {
        $written_bytes = 0;
        $total_bytes = strlen($bytes);
        while ($written_bytes < $total_bytes) {
            $written = fwrite($handle, substr($bytes, $written_bytes));
            if (!is_int($written) || $written <= 0) {
                return false;
            }
            $written_bytes += $written;
        }
        return true;
    }

    /** @param string[] $protected_paths @return string[] */
    private static function normalize_protected_paths(array $protected_paths): array {
        $normalized = [];
        foreach ($protected_paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Each protected staged apply path must be a non-empty relative string.');
            }
            if ($path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
                throw new InvalidArgumentException('Protected staged apply path is unsafe: ' . base64_encode($path));
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('Protected staged apply path is unsafe: ' . base64_encode($path));
                }
            }
            $normalized[] = $path;
        }
        sort($normalized, SORT_STRING);
        return array_values(array_unique($normalized));
    }

    /** @param string[] $protected_paths @return string[] */
    private static function protect_storage_path(string $storage_dir, string $target_root, array $protected_paths): array {
        if ($storage_dir === $target_root) {
            throw new InvalidArgumentException('The staged apply storage directory must not be the same directory as its apply target.');
        }
        $storage_prefix = $storage_dir === '/' ? '/' : $storage_dir . '/';
        if (strpos($target_root, $storage_prefix) === 0) {
            throw new InvalidArgumentException('The staged apply storage directory must not contain its apply target.');
        }
        $target_prefix = $target_root === '/' ? '/' : $target_root . '/';
        if (strpos($storage_dir, $target_prefix) !== 0) {
            return $protected_paths;
        }
        $storage_path = substr($storage_dir, strlen($target_prefix));
        $protected_paths[] = $storage_path;
        return self::normalize_protected_paths($protected_paths);
    }

    private static function normalize_absolute_path_for_inspection(string $path, string $name): string {
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('The ' . $name . ' must be an absolute path without NUL bytes: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The ' . $name . ' must not contain dot segments: ' . $path);
            }
        }
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;
        $resolved = realpath($path);
        if (is_string($resolved)) {
            return $resolved;
        }

        // Inspection and discard must still find a session after its target
        // disappears. Resolve the nearest surviving parent so lexical aliases
        // such as macOS /var -> /private/var still match the canonical path
        // recorded at creation.
        $missing_segments = [];
        $ancestor = $path;
        while ($ancestor !== '/' && realpath($ancestor) === false) {
            array_unshift($missing_segments, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $resolved_ancestor = realpath($ancestor);
        if (!is_string($resolved_ancestor)) {
            return $path;
        }
        foreach ($missing_segments as $segment) {
            $resolved_ancestor = $resolved_ancestor === '/'
                ? '/' . $segment
                : $resolved_ancestor . '/' . $segment;
        }
        return $resolved_ancestor;
    }

    private static function require_real_directory_path(string $path, string $description, bool $create): void {
        $stat = @lstat($path);
        if (!is_array($stat) && $create) {
            if (!@mkdir($path, 0700, true)) {
                $stat = @lstat($path);
                if (!is_array($stat)) {
                    throw new RuntimeException('Could not create the ' . $description . ': ' . $path, self::ERROR_RETRYABLE_IO);
                }
            }
            $stat = @lstat($path);
        }
        if (!is_array($stat) || !self::stat_is_real_directory($stat)) {
            throw new RuntimeException('The ' . $description . ' must be a real directory, not a symlink or another file type: ' . $path);
        }
    }

    /** @param array<string,mixed> $stat */
    private static function stat_is_real_directory(array $stat): bool {
        return isset($stat['mode'])
            && is_int($stat['mode'])
            && ( $stat['mode'] & 0170000 ) === 0040000;
    }

    private static function is_real_regular_file(string $path): bool {
        $stat = @lstat($path);
        return is_array($stat)
            && isset($stat['mode'])
            && is_int($stat['mode'])
            && ( $stat['mode'] & 0170000 ) === 0100000;
    }

    private static function require_real_regular_file(string $path, string $description): void {
        if (!self::is_real_regular_file($path)) {
            throw new RuntimeException('The ' . $description . ' must be a real regular file, not a symlink or another file type: ' . $path);
        }
    }

    private static function require_staging_directory(string $path, string $target_root, bool $create): string {
        self::require_no_symlinked_staging_components_inside_target($path, $target_root);
        $resolved = self::require_absolute_directory($path, 'staging directory', $create, true);
        self::require_no_symlinked_staging_components_inside_target($path, $target_root);
        self::ensure_storage_web_guards($resolved);
        return $resolved;
    }

    private static function require_absolute_directory(string $path, string $name, bool $create, bool $reject_symlinks = false): string {
        clearstatcache(true);
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('The ' . $name . ' must be an absolute path without NUL bytes: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The ' . $name . ' must not contain dot segments: ' . $path);
            }
        }
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;
        if ($reject_symlinks && ( $path === '/' || is_link($path) )) {
            throw new InvalidArgumentException('The ' . $name . ' must not be the filesystem root or a symlink: ' . $path);
        }
        if ($create && !is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create the ' . $name . ': ' . $path, self::ERROR_RETRYABLE_IO);
        }
        if (!is_dir($path)) {
            throw new RuntimeException('The ' . $name . ' is not an existing directory: ' . $path);
        }
        if ($reject_symlinks && is_link($path)) {
            throw new InvalidArgumentException('The ' . $name . ' must not be a symlink: ' . $path);
        }
        $resolved = realpath($path);
        if (!is_string($resolved)) {
            throw new RuntimeException('Could not resolve the ' . $name . ': ' . $path, self::ERROR_RETRYABLE_IO);
        }
        return $resolved;
    }

    private static function ensure_storage_web_guards(string $storage_dir): void {
        $htaccess = $storage_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $contents = "# Reprint staging area - never web-served.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Deny from all\n"
                . "</IfModule>\n";
            if (@file_put_contents($htaccess, $contents) !== strlen($contents)) {
                throw new RuntimeException('Could not create the staging web-server guard: ' . $htaccess, self::ERROR_RETRYABLE_IO);
            }
        }
        $index = $storage_dir . '/index.php';
        if (!file_exists($index) && @file_put_contents($index, "<?php\n") !== 6) {
            throw new RuntimeException('Could not create the staging index guard: ' . $index, self::ERROR_RETRYABLE_IO);
        }
    }

    private static function require_no_symlinked_staging_components_inside_target(string $path, string $target_root): void {
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
            return;
        }
        $current = '';
        $target_prefix = $target_root === '/' ? '/' : $target_root . '/';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                return;
            }
            $current .= '/' . $segment;
            if (!is_link($current)) {
                continue;
            }
            $resolved_parent = realpath(dirname($current));
            if (is_string($resolved_parent) && ( $resolved_parent === $target_root || strpos($resolved_parent, $target_prefix) === 0 )) {
                throw new InvalidArgumentException(
                    'The staging directory must not use a symlinked component inside the apply target; component base64 is '
                    . base64_encode($current) . '.'
                );
            }
        }
    }

    private static function require_same_filesystem(string $storage_dir, string $target_root): void {
        $storage_stat = @stat($storage_dir);
        $target_stat = @stat($target_root);
        if (!is_array($storage_stat) || !is_array($target_stat) || !isset($storage_stat['dev'], $target_stat['dev'])) {
            throw new RuntimeException('Could not determine staging and target filesystem devices.', self::ERROR_RETRYABLE_IO);
        }
        if ( (int) $storage_stat['dev'] !== (int) $target_stat['dev']) {
            throw new RuntimeException(
                'The staging device ' . $storage_stat['dev'] . ' and target device ' . $target_stat['dev']
                . ' must match for atomic renames.'
            );
        }
    }

    private static function remove_expired_retired_sessions_step(string $sessions_dir, int $retired_session_seconds): void {
        $lock = @fopen($sessions_dir . '/retired-gc.lock', 'c+b');
        if ($lock === false) {
            return;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            $current_dir = $sessions_dir . '/retired-gc-current';
            $deferred_dir = $sessions_dir . '/retired-gc-deferred';
            self::require_real_directory_path($current_dir, 'retired staged apply current cleanup directory', true);
            self::require_real_directory_path($deferred_dir, 'retired staged apply deferred cleanup directory', true);
            $directory = @opendir($current_dir);
            if ($directory === false) {
                return;
            }
            $reached_end = false;
            for ($inspected = 0; $inspected < self::MAX_RETIRED_GC_ENTRIES; $inspected++) {
                do {
                    $entry = readdir($directory);
                } while ($entry === '.' || $entry === '..');
                if ($entry === false) {
                    $reached_end = true;
                    break;
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $entry) !== 1) {
                    @unlink($current_dir . '/' . $entry);
                    continue;
                }
                $marker_path = $sessions_dir . '/retired-' . $entry;
                self::remove_expired_retired_session($marker_path, $retired_session_seconds);
                if (!is_file($marker_path)) {
                    @unlink($current_dir . '/' . $entry);
                    continue;
                }
                $deferred_path = $deferred_dir . '/' . $entry;
                if (is_file($deferred_path)) {
                    @unlink($current_dir . '/' . $entry);
                } else {
                    @rename($current_dir . '/' . $entry, $deferred_path);
                }
            }
            closedir($directory);
            if ($reached_end && @rmdir($current_dir) && @rename($deferred_dir, $current_dir)) {
                self::require_real_directory_path($deferred_dir, 'retired staged apply deferred cleanup directory', true);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function remove_expired_retired_session(string $marker_path, int $retired_session_seconds): void {
        $modified_at = @filemtime($marker_path);
        if ($modified_at !== false && $modified_at < time() - $retired_session_seconds) {
            @unlink($marker_path);
        }
    }

    private static function remove_created_session_tree(string $path): void {
        $pending = [$path];
        for ($operation = 0; $operation < self::MAX_CREATED_SESSION_CLEANUP_OPERATIONS && $pending !== []; $operation++) {
            $current = $pending[count($pending) - 1];
            if (is_link($current) || !is_dir($current)) {
                if (( file_exists($current) || is_link($current) ) && !@unlink($current)) {
                    throw new RuntimeException('Could not remove staged apply path: ' . $current, self::ERROR_RETRYABLE_IO);
                }
                array_pop($pending);
                continue;
            }
            $directory = @opendir($current);
            if ($directory === false) {
                throw new RuntimeException('Could not open staged apply directory: ' . $current, self::ERROR_RETRYABLE_IO);
            }
            $entry = false;
            try {
                while (true) {
                    $candidate = readdir($directory);
                    if ($candidate === false) {
                        break;
                    }
                    if ($candidate !== '.' && $candidate !== '..') {
                        $entry = $candidate;
                        break;
                    }
                }
            } finally {
                closedir($directory);
            }
            if (is_string($entry)) {
                $pending[] = $current . '/' . $entry;
                continue;
            }
            if (!@rmdir($current)) {
                throw new RuntimeException('Could not remove staged apply directory: ' . $current, self::ERROR_RETRYABLE_IO);
            }
            array_pop($pending);
        }
    }
}
