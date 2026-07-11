<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime errors are returned as API JSON, never rendered as HTML.

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}
if (!class_exists('Site_Export_Staged_Artifacts', false)) {
    require_once __DIR__ . '/class-staged-artifacts.php';
}

/**
 * Owns one direct, resumable staged-apply upload session.
 *
 * Typed operations are materialized directly below the private staged tree
 * as they are accepted. The target writes one bounded JSONL record for each
 * completed operation; there is no uploaded manifest, validation pass, or
 * prepared copy. This class owns operation completion, ordering, and replay.
 * The live target tree is never mutated by this upload-only session.
 */
final class Site_Export_Staged_Apply_Session {

    public const ERROR_BUSY = 1001;

    public const ERROR_SESSION_NOT_FOUND = 1002;

    public const ERROR_RETRYABLE_IO = 1003;

    public const ERROR_INVALID_OPERATION = 1004;

    private const MAX_METADATA_BYTES = 1048576;

    private const MAX_OPERATION_LINE_BYTES = 32768;

    private const MAX_PATH_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES;

    /** @var string */
    private $target_root;

    /** @var string[] */
    private $protected_paths;

    /** @var string */
    private $session_id;

    /** @var string */
    private $session_dir;

    /** @var string */
    private $state_path;

    /** @var string */
    private $lock_path;

    /** @var string */
    private $journal_path;

    /** @var string */
    private $staged_dir;

    /** @var Site_Export_Staged_Artifacts */
    private $artifact_store;

    /** @var bool */
    private $upload_lock_held = false;

    /**
     * Records the canonical paths used by one session handle.
     *
     * The constructor does not inspect or create the workspace. Call create()
     * for a new session or open() for an existing session.
     *
     * @param string   $storage_dir     Canonical staging root shared by apply sessions.
     * @param string   $target_root     Canonical root the later commit phase will change.
     * @param string   $session_id      Random lowercase hexadecimal session identifier.
     * @param string[] $protected_paths Target-relative paths this session may not change.
     */
    private function __construct(string $storage_dir, string $target_root, string $session_id, array $protected_paths) {
        $this->target_root = $target_root;
        $this->protected_paths = $protected_paths;
        $this->session_id = $session_id;
        $this->session_dir = $storage_dir . '/apply-sessions/' . $session_id;
        $this->state_path = $this->session_dir . '/state.json';
        $this->lock_path = $this->session_dir . '/lock';
        $this->journal_path = $this->session_dir . '/work/operations.jsonl';
        $this->staged_dir = $this->session_dir . '/work/files';
        $this->artifact_store = new Site_Export_Staged_Artifacts($this->session_dir . '/work');
    }

    /**
     * Creates a private upload session with a random server-owned id.
     *
     * The storage directory is created when needed. When it is inside the
     * target, its relative path is added to the protected set automatically.
     * Session creation does not mutate the target outside that protected
     * storage subtree.
     *
     * @param string   $storage_dir     Absolute directory that owns all apply sessions.
     * @param string   $target_root     Existing absolute directory to bind to the session.
     * @param string[] $protected_paths Target-relative paths this session may not change.
     * @return self The newly initialized session.
     */
    public static function create(
        string $storage_dir,
        string $target_root,
        array $protected_paths = []
    ): self {
        $target_root = self::require_absolute_directory($target_root, 'apply target', false);
        $storage_dir = self::require_staging_directory($storage_dir, $target_root, true);
        $protected_paths = self::protect_storage_path(
            $storage_dir,
            $target_root,
            $protected_paths
        );

        $sessions_dir = $storage_dir . '/apply-sessions';
        self::require_real_directory_path($sessions_dir, 'staged apply sessions directory', true);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $session_id = bin2hex(random_bytes(16));
            if (@mkdir($sessions_dir . '/' . $session_id, 0700)) {
                return self::initialize_created_session($storage_dir, $target_root, $session_id, $protected_paths);
            }
        }
        throw new RuntimeException('Could not create a unique staged apply session after 10 attempts.', self::ERROR_RETRYABLE_IO);
    }

    /**
     * Opens an active session.
     *
     * The supplied target and protected paths must match the configuration
     * stored in the session.
     *
     * @param string   $storage_dir     Existing absolute staging root used at creation.
     * @param string   $target_root     Absolute target path originally bound to the session.
     * @param string   $session_id      Lowercase hexadecimal id returned by get_session_id().
     * @param string[] $protected_paths Target-relative paths this session may not change.
     * @return self The active session.
     */
    public static function open(
        string $storage_dir,
        string $target_root,
        string $session_id,
        array $protected_paths = []
    ): self {
        $target_root = self::require_absolute_directory($target_root, 'apply target', false);
        $storage_dir = self::require_staging_directory($storage_dir, $target_root, false);
        self::require_real_directory_path($storage_dir . '/apply-sessions', 'staged apply sessions directory', false);
        if (!preg_match('/^[a-f0-9]{32}$/D', $session_id)) {
            throw new InvalidArgumentException('The staged apply session id must be a 32-character lowercase hexadecimal value.');
        }
        $protected_paths = self::protect_storage_path(
            $storage_dir,
            $target_root,
            $protected_paths
        );
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        $session_stat = @lstat($session->session_dir);
        if (!is_array($session_stat) || !self::stat_is_real_directory($session_stat)) {
            if (is_array($session_stat)) {
                throw new RuntimeException('The staged apply session path is not a real directory: ' . $session->session_dir);
            }
            throw new RuntimeException('The staged apply session does not exist: ' . $session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $session->require_private_workspace();
        $session->read_state();
        return $session;
    }

    /**
     * Returns the opaque id used to address this session.
     *
     * @return string Lowercase hexadecimal session id.
     */
    public function get_session_id(): string {
        return $this->session_id;
    }

    /**
     * Returns durable upload progress without taking the writer lock.
     *
     * The response includes phase, operation_count, journal_bytes,
     * last_path_b64, failure, and the target-confirmed current file cursor.
     *
     * @return array<string,mixed> Durable session status.
     */
    public function get_status(): array {
        if (!$this->session_directory_exists()) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $this->require_private_workspace();
        $state = $this->read_state();
        $state['current_file'] = $this->current_file_status($state);
        return $state;
    }

    /**
     * Locks one upload request while its callback accepts frames.
     *
     * A concurrent request fails with ERROR_BUSY. Once the lock is released,
     * operation indexes and staged shapes make a replay safe. The caller
     * remains responsible for its per-request frame and time budgets.
     *
     * @param callable $callback Processes frames through the locked handle.
     * @return mixed The callback's return value.
     */
    public function while_uploading(callable $callback) {
        return $this->with_session_lock(function () use ($callback) {
            $state = $this->read_state();
            if ($state['phase'] !== 'uploading') {
                throw new RuntimeException('The staged apply session is not accepting operations because its phase is ' . $state['phase'] . '.');
            }
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

    /**
     * Stages one directory operation and records it in the journal.
     *
     * Replaying the current operation is idempotent only when it names the
     * same directory. Earlier indexes return duplicate; later indexes return
     * operation_gap.
     *
     * @param int    $operation_index Zero-based index expected next by the target.
     * @param string $path            Raw-byte target-relative directory path.
     * @return array{status:string,reason:?string,operation_count:int,current_file:?array<string,mixed>}
     */
    public function accept_directory(int $operation_index, string $path): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        if ($state['current_file'] !== null) {
            return $this->upload_result('rejected', 'operation_mismatch', $state);
        }
        $this->validate_new_path_or_fail($state, $path, 'directory');
        $entry = ['type' => 'directory', 'path' => $path];
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

    /**
     * Stages one symlink without following its target.
     *
     * The target is stored as bounded raw bytes, but may not be empty or
     * contain NUL. A replay must match any symlink already materialized at
     * that path.
     *
     * @param int    $operation_index Zero-based index expected next by the target.
     * @param string $path            Raw-byte target-relative symlink path.
     * @param string $target          Raw-byte symlink target stored without resolution.
     * @return array{status:string,reason:?string,operation_count:int,current_file:?array<string,mixed>}
     */
    public function accept_symlink(int $operation_index, string $path, string $target): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        if ($state['current_file'] !== null) {
            return $this->upload_result('rejected', 'operation_mismatch', $state);
        }
        $this->validate_new_path_or_fail($state, $path, 'symlink');
        if ($target === '' || strpos($target, "\0") !== false || strlen($target) > self::MAX_PATH_BYTES) {
            $this->fail_invalid_operation('Refusing staged symlink ' . $this->describe_path($path) . ' with an empty, NUL-containing, or overlong target.');
        }
        $entry = ['type' => 'symlink', 'path' => $path, 'target' => $target];
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
        return $this->complete_upload_operation($state, $entry);
    }

    /**
     * Records one delete in the target-authored journal.
     *
     * Deletes have no staging-tree representation: absence means unchanged,
     * and a tombstone would wrongly block replacing a file with a directory.
     *
     * @param int    $operation_index Zero-based index expected next by the target.
     * @param string $path            Raw-byte target-relative path to remove during commit.
     * @return array{status:string,reason:?string,operation_count:int,current_file:?array<string,mixed>}
     */
    public function accept_delete(int $operation_index, string $path): array {
        $this->require_upload_lock();
        $state = $this->read_state();
        $gap = $this->operation_gap_result($state, $operation_index);
        if ($gap !== null) {
            return $gap;
        }
        if ($state['current_file'] !== null) {
            return $this->upload_result('rejected', 'operation_mismatch', $state);
        }
        $this->validate_new_path_or_fail($state, $path, 'delete');
        $entry = ['type' => 'delete', 'path' => $path];
        return $this->complete_upload_operation($state, $entry);
    }

    /**
     * Accepts one bounded file chunk at the target-confirmed cursor.
     *
     * The session persists only the current file's path, revision, and total.
     * Site_Export_Staged_Artifacts owns its confirmed byte cursor. A changed
     * revision replaces old bytes only when the client explicitly restarts at
     * offset zero. Damage rejects the chunk instead of trusting or repairing
     * staged bytes.
     *
     * @param int    $operation_index Zero-based index expected next by the target.
     * @param string $path            Raw-byte target-relative file path.
     * @param int    $revision        Sender-owned revision that increases after source changes.
     * @param int    $offset          Offset of this payload in the file revision.
     * @param string $payload         One caller-bounded chunk.
     * @param int    $total_bytes     Complete byte length of this revision.
     * @param bool   $restart         Whether offset zero explicitly replaces staged bytes.
     * @return array{status:string,reason:?string,operation_count:int,current_file:?array<string,mixed>}
     */
    public function accept_file_chunk(
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
        $payload_bytes = strlen($payload);
        if ($offset > $total_bytes || $payload_bytes > $total_bytes - $offset) {
            return $this->upload_result('rejected', 'size_exceeded', $state);
        }
        if ($restart && $offset !== 0) {
            return $this->upload_result('rejected', 'offset_gap', $state);
        }

        $current_file = $state['current_file'];
        if ($current_file === null) {
            if ($offset !== 0) {
                return $this->upload_result('rejected', 'offset_gap', $state);
            }
            $this->validate_new_path_or_fail($state, $path, 'file');
            $state['current_file'] = [
                'path_b64' => base64_encode($path),
                'revision' => $revision,
                'total_bytes' => $total_bytes,
            ];
            $this->write_state($state);
            $current_file = $state['current_file'];
            // Nothing predates a new descriptor, so a restart flag on its
            // first frame has nothing to discard.
            $restart = false;
        } else {
            $current_path = base64_decode($current_file['path_b64'], true);
            if (!is_string($current_path) || $current_path !== $path) {
                return $this->upload_result('rejected', 'operation_mismatch', $state);
            }
        }

        if ($restart) {
            if ($revision < $current_file['revision']) {
                return $this->upload_result('rejected', 'stale_revision', $state);
            }
            if ($revision === $current_file['revision'] && $total_bytes !== $current_file['total_bytes']) {
                return $this->upload_result('rejected', 'size_mismatch', $state);
            }
            if (!$this->ensure_staged_parents($path)) {
                $this->fail_invalid_operation('Cannot restart file ' . $this->describe_path($path) . ' below a non-directory staged ancestor.');
            }
            // Discard first. A cut before the new descriptor is saved leaves
            // the old revision at cursor zero, which tells the client to retry
            // the same explicit restart.
            if (!$this->artifact_store->discard($path)) {
                throw new RuntimeException(
                    'Could not discard the previous staged revision for ' . $this->describe_path($path) . '.',
                    self::ERROR_RETRYABLE_IO
                );
            }
            $state['current_file'] = [
                'path_b64' => base64_encode($path),
                'revision' => $revision,
                'total_bytes' => $total_bytes,
            ];
            $this->write_state($state);
            $current_file = $state['current_file'];
        } elseif ($revision !== $current_file['revision']) {
            return $this->upload_result('rejected', 'revision_mismatch', $state);
        } elseif ($total_bytes !== $current_file['total_bytes']) {
            return $this->upload_result('rejected', 'size_mismatch', $state);
        }

        if (!$this->ensure_staged_parents($path)) {
            $this->fail_invalid_operation('Cannot stage file ' . $this->describe_path($path) . ' below a non-directory staged ancestor.');
        }
        $artifact_status = $this->artifact_store->status($path);
        if (isset($artifact_status['damage'])) {
            return $this->upload_result('rejected', 'staging_file_damaged', $state);
        }
        if ($artifact_status['verified']) {
            $this->fail_invalid_operation('The staged file store has an unexpected verification marker for ' . $this->describe_path($path) . '.');
        }
        $committed_bytes = (int) $artifact_status['committed_bytes'];
        if ($committed_bytes > $total_bytes) {
            $this->fail_invalid_operation(
                'The staged file cursor for ' . $this->describe_path($path) . ' exceeds its declared ' . $total_bytes . ' bytes.'
            );
        }
        $staged_file_path = $this->staged_path($path);
        $staged_file_stat = @lstat($staged_file_path);
        if (is_array($staged_file_stat) && !self::stat_is_real_regular_file($staged_file_stat)) {
            return $this->upload_result('rejected', 'staging_file_damaged', $state);
        }

        $entry = [
            'type' => 'file',
            'path' => $path,
            'bytes' => $total_bytes,
        ];
        if ($total_bytes === 0) {
            if (is_array($staged_file_stat) && (int) $staged_file_stat['size'] !== 0) {
                if (!$this->artifact_store->discard($path)) {
                    throw new RuntimeException('Could not clear stale bytes before staging zero-byte file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                $staged_file_stat = false;
            }
            if (!is_array($staged_file_stat)) {
                $empty_file = @fopen($staged_file_path, 'x+b');
                if ($empty_file === false) {
                    throw new RuntimeException('Could not create staged zero-byte file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                }
                try {
                    if (!fflush($empty_file)) {
                        throw new RuntimeException('Could not flush staged zero-byte file ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
                    }
                } finally {
                    fclose($empty_file);
                }
                @chmod($staged_file_path, 0600);
            }
            $state['current_file'] = null;
            return $this->complete_upload_operation($state, $entry);
        }

        if ($committed_bytes === $total_bytes) {
            $state['current_file'] = null;
            return $this->complete_file_operation($state, $entry, $staged_file_path, $total_bytes);
        }
        if ($payload_bytes > 0 && $offset + $payload_bytes <= $committed_bytes) {
            return $this->upload_result('duplicate', null, $state);
        }
        if ($offset < $committed_bytes) {
            // Chunk boundaries are not durable. Drop the already-confirmed
            // prefix and append only the suffix the store still needs.
            $payload = substr($payload, $committed_bytes - $offset);
            $payload_bytes = strlen($payload);
            $offset = $committed_bytes;
        }
        if ($offset !== $committed_bytes) {
            return $this->upload_result('rejected', 'offset_gap', $state);
        }
        if ($payload_bytes === 0) {
            return $this->upload_result('rejected', 'empty_payload', $state);
        }

        $append_result = $this->artifact_store->append($path, $offset, $payload);
        if ($append_result['status'] === 'busy') {
            throw new RuntimeException('The staged file store is busy for ' . $this->describe_path($path) . '.', self::ERROR_BUSY);
        }
        if ($append_result['status'] === 'rejected') {
            if ($append_result['reason'] === 'staging_file_damaged') {
                return $this->upload_result('rejected', 'staging_file_damaged', $state);
            }
            if ($append_result['reason'] === 'offset_gap') {
                return $this->upload_result('rejected', 'offset_gap', $state);
            }
            throw new RuntimeException(
                'Could not append staged file ' . $this->describe_path($path) . ': '
                . (string) $append_result['reason'] . ( $append_result['detail'] === null ? '' : ' (' . $append_result['detail'] . ')' ) . '.',
                self::ERROR_RETRYABLE_IO
            );
        }
        $committed_bytes = (int) $append_result['committed_bytes'];
        if ($committed_bytes < $total_bytes) {
            return $this->upload_result('accepted', null, $state);
        }

        $state['current_file'] = null;
        return $this->complete_file_operation($state, $entry, $staged_file_path, $total_bytes);
    }

    /**
     * Marks a malformed or semantically invalid upload as failed.
     *
     * No later operation may be accepted. The failure remains available in
     * status for diagnosis.
     *
     * @param string $detail Stable human-readable reason the upload is invalid.
     */
    public function fail_upload(string $detail): void {
        $this->require_upload_lock();
        if ($detail === '' || strlen($detail) > self::MAX_METADATA_BYTES / 2) {
            throw new InvalidArgumentException('The staged apply failure detail must be a non-empty bounded string.');
        }
        $state = $this->read_state();
        if ($state['phase'] !== 'uploading') {
            throw new RuntimeException('Cannot fail staged apply upload while its phase is ' . $state['phase'] . '.');
        }
        $state['phase'] = 'failed';
        $state['failure'] = $detail;
        $this->write_state($state);
    }

    /**
     * Initializes the fixed workspace layout after its random directory wins mkdir().
     *
     * If initialization fails, paths created for this random session are
     * removed on a best-effort basis before the exception is rethrown.
     *
     * @param string   $storage_dir     Canonical staging root shared by apply sessions.
     * @param string   $target_root     Canonical root the later commit phase will change.
     * @param string   $session_id      Random lowercase hexadecimal session identifier.
     * @param string[] $protected_paths Target-relative paths this session may not change.
     * @return self The initialized session.
     */
    private static function initialize_created_session(
        string $storage_dir,
        string $target_root,
        string $session_id,
        array $protected_paths
    ): self {
        $session = new self($storage_dir, $target_root, $session_id, $protected_paths);
        try {
            if (!@mkdir($session->staged_dir, 0700, true) && !is_dir($session->staged_dir)) {
                throw new RuntimeException('Could not create staged apply workspace directory: ' . $session->staged_dir, self::ERROR_RETRYABLE_IO);
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
                'protected_paths_b64' => array_map('base64_encode', $protected_paths),
                'phase' => 'uploading',
                'operation_count' => 0,
                'journal_bytes' => 0,
                'last_path_b64' => null,
                'current_file' => null,
                'failure' => null,
            ]);
        } catch (Throwable $exception) {
            foreach ([$session->state_path . '.tmp', $session->state_path, $session->lock_path, $session->journal_path] as $path) {
                if ($session->path_present($path)) {
                    @unlink($path);
                }
            }
            @rmdir($session->staged_dir);
            @rmdir(dirname($session->staged_dir));
            @rmdir($session->session_dir);
            throw $exception;
        }
        return $session;
    }

    /** Ensures an operation is being accepted inside the locked upload callback. */
    private function require_upload_lock(): void {
        if (!$this->upload_lock_held) {
            throw new LogicException('Staged apply operations may be accepted only inside while_uploading().');
        }
    }

    /**
     * Verifies that every fixed workspace component has its expected file type.
     *
     * The fixed session state, lock, journal, and work tree must have their
     * expected leaf types. No check here follows a workspace link.
     */
    private function require_private_workspace(): void {
        foreach (
            [
                dirname($this->session_dir) => 'staged apply sessions directory',
                $this->session_dir => 'staged apply session directory',
                dirname($this->staged_dir) => 'staged apply work directory',
                $this->staged_dir => 'staged apply staged tree',
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
                dirname($this->staged_dir) . '/state.json' => 'staged file cursor',
                dirname($this->staged_dir) . '/lock' => 'staged file lock',
            ] as $path => $description
        ) {
            if (is_array(@lstat($path))) {
                self::require_real_regular_file($path, $description);
            }
        }
    }

    /**
     * Classifies an operation index against the next durable journal index.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null A duplicate/gap response, or null for the next index.
     */
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

    /**
     * Validates a new path against safety, protection, length, and ordering rules.
     *
     * Any violation fails the whole upload because accepting a later
     * operation would no longer describe one ordered target plan.
     *
     * @param array<string,mixed> $state
     */
    private function validate_new_path_or_fail(array $state, string $path, string $type): void {
        $path_bytes = strlen($path);
        if ($path_bytes > self::MAX_PATH_BYTES) {
            $this->fail_invalid_operation(
                'Refusing staged apply path of ' . $path_bytes . ' bytes; the maximum is ' . self::MAX_PATH_BYTES . ' bytes.'
            );
        }
        try {
            $this->require_writable_target_path($path, $type);
        } catch (RuntimeException $exception) {
            $this->fail_invalid_operation($exception->getMessage());
        }
        $last_path = $state['last_path_b64'] === null ? null : base64_decode($state['last_path_b64'], true);
        if (is_string($last_path) && strcmp($last_path, $path) >= 0) {
            $this->fail_invalid_operation(
                'Staged apply paths must be strictly increasing by raw bytes; received ' . $this->describe_path($path)
                . ' after ' . $this->describe_path($last_path) . '.'
            );
        }
    }

    /**
     * Appends one completed operation and advances state past the flushed line.
     *
     * Any journal tail beyond state.json's cursor came from an interrupted
     * earlier attempt and is truncated before this record is written again.
     *
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
            if (fseek($journal, (int) $state['journal_bytes'], SEEK_SET) !== 0 || !self::write_all($journal, $line) || !fflush($journal)) {
                throw new RuntimeException('Could not append target-authored staged apply operation ' . $state['operation_count'] . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($journal);
        }

        ++$state['operation_count'];
        $state['journal_bytes'] += strlen($line);
        $state['last_path_b64'] = base64_encode($entry['path']);
        $this->write_state($state);
        return $this->upload_result('accepted', null, $state);
    }

    /**
     * Requires the completed staged file to match its declared shape and size.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function complete_file_operation(array $state, array $entry, string $staged_file_path, int $total_bytes): array {
        $staged_file_stat = @lstat($staged_file_path);
        if (
            !is_array($staged_file_stat)
            || !self::stat_is_real_regular_file($staged_file_stat)
            || !isset($staged_file_stat['size'])
            || (int) $staged_file_stat['size'] !== $total_bytes
        ) {
            $this->fail_invalid_operation('The completed staged file has the wrong shape or size for ' . $this->describe_path($entry['path']) . '.');
        }
        return $this->complete_upload_operation($state, $entry);
    }

    /**
     * Adds the artifact-store cursor to the persisted current-file descriptor.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null
     */
    private function current_file_status(array $state): ?array {
        $current_file = $state['current_file'];
        if (!is_array($current_file)) {
            return null;
        }
        $path = base64_decode($current_file['path_b64'], true);
        if (!is_string($path)) {
            throw new RuntimeException('The staged apply current file path is malformed.');
        }
        $artifact_status = $this->artifact_store->status($path);
        $status = [
            'operation_index' => (int) $state['operation_count'],
            'path_b64' => $current_file['path_b64'],
            'revision' => $current_file['revision'],
            'committed_bytes' => (int) $artifact_status['committed_bytes'],
            'total_bytes' => $current_file['total_bytes'],
        ];
        if (isset($artifact_status['damage'])) {
            $status['damage'] = $artifact_status['damage'];
            $status['recorded_committed_bytes'] = (int) $artifact_status['recorded_committed_bytes'];
            return $status;
        }
        $staged_stat = @lstat($this->staged_path($path));
        if (is_array($staged_stat) && !self::stat_is_real_regular_file($staged_stat)) {
            $status['damage'] = 'staging_file_not_regular';
            $status['recorded_committed_bytes'] = (int) $artifact_status['committed_bytes'];
        }
        return $status;
    }

    /**
     * Builds the bounded response returned for an accepted, duplicate, or rejected frame.
     *
     * @param string              $status Accepted, duplicate, or rejected.
     * @param string|null         $reason Stable rejection reason, or null otherwise.
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,operation_count:int,current_file:?array<string,mixed>}
     */
    private function upload_result(string $status, ?string $reason, array $state): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'operation_count' => (int) $state['operation_count'],
            'current_file' => $this->current_file_status($state),
        ];
    }

    /** Marks the upload failed and throws the stable invalid-operation error. */
    private function fail_invalid_operation(string $detail): void {
        $this->fail_upload($detail);
        throw new RuntimeException($detail, self::ERROR_INVALID_OPERATION);
    }

    /**
     * Truncates bytes written beyond the durable journal cursor after a crash.
     */
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

    /**
     * Encodes arbitrary path and symlink-target bytes for JSON persistence.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
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

    /**
     * Atomically replaces the session's bounded state record.
     *
     * @param array<string,mixed> $state
     */
    private function write_state(array $state): void {
        $this->write_json_file($this->state_path, $state);
    }

    /**
     * Reads and validates the complete durable session state.
     *
     * This validates stored configuration and progress, but deliberately does
     * not inspect the live target identity. Upload never mutates the target;
     * identity validation is deferred to the later commit phase.
     *
     * @return array<string,mixed>
     */
    private function read_state(): array {
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
        foreach (['operation_count', 'journal_bytes'] as $field) {
            if (!isset($state[$field]) || !is_int($state[$field]) || $state[$field] < 0) {
                throw new RuntimeException('The staged apply session state field ' . $field . ' must be a non-negative integer.');
            }
        }
        $journal_stat = @lstat($this->journal_path);
        $journal_size = is_array($journal_stat) && isset($journal_stat['size']) ? (int) $journal_stat['size'] : -1;
        if ($journal_size < $state['journal_bytes']) {
            throw new RuntimeException(
                'The target-authored staged apply journal is shorter than its durable cursor; expected at least '
                . $state['journal_bytes'] . ' bytes, observed ' . $journal_size . '.'
            );
        }
        $phase = $state['phase'] ?? null;
        if (!in_array($phase, ['uploading', 'failed'], true)) {
            throw new RuntimeException('The staged apply session phase is invalid: ' . ( is_string($phase) ? $phase : gettype($phase) ) . '.');
        }
        if (!array_key_exists('current_file', $state) || ( $state['current_file'] !== null && !is_array($state['current_file']) )) {
            throw new RuntimeException('The staged apply current file state is malformed.');
        }
        $current_file_path = null;
        if (is_array($state['current_file'])) {
            $encoded_current_file_path = $state['current_file']['path_b64'] ?? null;
            $current_file_path = is_string($encoded_current_file_path) ? base64_decode($encoded_current_file_path, true) : false;
            if (
                !is_string($current_file_path)
                || $current_file_path === ''
                || strlen($current_file_path) > self::MAX_PATH_BYTES
                || base64_encode($current_file_path) !== $encoded_current_file_path
                || !isset($state['current_file']['revision'])
                || !is_int($state['current_file']['revision'])
                || $state['current_file']['revision'] < 0
                || !isset($state['current_file']['total_bytes'])
                || !is_int($state['current_file']['total_bytes'])
                || $state['current_file']['total_bytes'] < 0
            ) {
                throw new RuntimeException('The staged apply current file state is malformed.');
            }
            $this->require_writable_target_path($current_file_path, 'file');
        }
        $last_path_b64 = $state['last_path_b64'] ?? null;
        $last_path = is_string($last_path_b64) ? base64_decode($last_path_b64, true) : null;
        if (
            ( $last_path_b64 !== null && ( $last_path === false || $last_path === '' ) )
            || ( ( $state['operation_count'] === 0 ) !== ( $last_path_b64 === null ) )
        ) {
            throw new RuntimeException('The staged apply last accepted path state is malformed.');
        }
        if (is_string($last_path)) {
            // Directory is the least restrictive legal shape: an accepted
            // directory may be an ancestor of a protected descendant.
            $this->require_writable_target_path($last_path, 'directory');
        }
        if (is_string($current_file_path) && is_string($last_path) && strcmp($last_path, $current_file_path) >= 0) {
            throw new RuntimeException('The staged apply current file path is not after the last completed path.');
        }
        $failure = $state['failure'] ?? null;
        if (( $phase === 'failed' && ( !is_string($failure) || $failure === '' ) ) || ( $phase !== 'failed' && $failure !== null )) {
            throw new RuntimeException('The staged apply failure state is malformed.');
        }
        return $state;
    }

    /**
     * Reads one bounded JSON object, or null when the path is absent or malformed.
     *
     * @return array<string,mixed>|null
     */
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

    /**
     * Writes a bounded JSON object through an exclusive temporary file and rename.
     *
     * Existing temporary paths are unlinked first, so a leftover symlink or
     * hard link is never opened for writing.
     *
     * @param array<string,mixed> $value
     */
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
            if (!self::write_all($handle, $encoded) || !fflush($handle)) {
                throw new RuntimeException('Could not flush staged apply metadata: ' . $tmp, self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Could not commit staged apply metadata: ' . $path, self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Runs one session transition under its non-blocking, never-replaced lock.
     *
     * @return mixed
     */
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

    /** Checks whether the active session directory still exists without cached stat data. */
    private function session_directory_exists(): bool {
        clearstatcache(true, $this->session_dir);
        return is_dir($this->session_dir);
    }

    /**
     * Creates missing staging parents as real 0700 directories without following links.
     *
     * @return bool False when an existing ancestor is not a real directory.
     */
    private function ensure_staged_parents(string $path): bool {
        $segments = explode('/', $path);
        array_pop($segments);
        $current = $this->staged_dir;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if (is_array($stat)) {
                if (self::stat_is_real_directory($stat)) {
                    continue;
                }
                return false;
            }
            if ($this->path_present($current)) {
                return false;
            }
            if (!@mkdir($current, 0700)) {
                throw new RuntimeException(
                    'Could not create staged apply parent directory ' . $this->describe_path($current) . '.',
                    self::ERROR_RETRYABLE_IO
                );
            }
        }
        return true;
    }

    /**
     * Validates one target-relative path against syntax and protected paths.
     *
     * Directories may be ancestors of a protected path because installing
     * their permitted siblings still needs structural staging parents. Other
     * entry types may neither equal nor contain a protected path.
     */
    private function require_writable_target_path(string $path, ?string $entry_type = null): void {
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new RuntimeException('Refusing unsafe staged apply path: ' . $this->describe_path($path));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Refusing unsafe staged apply path: ' . $this->describe_path($path));
            }
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

    /** Appends a validated target-relative path to the staged-tree root. */
    private function staged_path(string $path): string {
        return $this->staged_dir . '/' . $path;
    }

    /** Checks path presence without treating a dangling symlink as absent. */
    private function path_present(string $path): bool {
        return file_exists($path) || is_link($path);
    }

    /** Formats arbitrary path bytes safely for diagnostics. */
    private function describe_path(string $path): string {
        return 'base64:' . base64_encode($path);
    }

    /**
     * Writes an entire bounded string, retrying ordinary short writes.
     *
     * @param resource $handle
     */
    private static function write_all($handle, string $bytes): bool {
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

    /**
     * Validates, sorts, and deduplicates target-relative protected paths.
     *
     * @param string[] $protected_paths
     * @return string[]
     */
    private static function normalize_protected_paths(array $protected_paths): array {
        $normalized = [];
        foreach ($protected_paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Each protected staged apply path must be a non-empty relative string.');
            }
            $path_bytes = strlen($path);
            if ($path_bytes > self::MAX_PATH_BYTES) {
                throw new InvalidArgumentException(
                    'Protected staged apply path has ' . $path_bytes . ' bytes; the maximum is ' . self::MAX_PATH_BYTES . ' bytes.'
                );
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

    /**
     * Validates the protected set and adds storage when it lives below the target.
     *
     * Storage that equals or contains the target is rejected because the
     * target index cannot safely exclude that topology.
     *
     * @param string[] $protected_paths
     * @return string[]
     */
    private static function protect_storage_path(string $storage_dir, string $target_root, array $protected_paths): array {
        if ($storage_dir === $target_root) {
            throw new InvalidArgumentException('The staged apply storage directory must not be the same directory as its apply target.');
        }
        $storage_prefix = $storage_dir === '/' ? '/' : $storage_dir . '/';
        if (strpos($target_root, $storage_prefix) === 0) {
            throw new InvalidArgumentException('The staged apply storage directory must not contain its apply target.');
        }
        $target_prefix = $target_root === '/' ? '/' : $target_root . '/';
        if (strpos($storage_dir, $target_prefix) === 0) {
            $protected_paths[] = substr($storage_dir, strlen($target_prefix));
        }
        return self::normalize_protected_paths($protected_paths);
    }

    /** Creates or validates a directory using lstat so the leaf cannot be a symlink. */
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

    /**
     * Checks an lstat record for a real directory mode.
     *
     * @param array<string,mixed> $stat
     */
    private static function stat_is_real_directory(array $stat): bool {
        return isset($stat['mode'])
            && is_int($stat['mode'])
            && ( $stat['mode'] & 0170000 ) === 0040000;
    }

    /**
     * Checks an lstat record for a real regular-file mode.
     *
     * @param array<string,mixed> $stat
     */
    private static function stat_is_real_regular_file(array $stat): bool {
        return isset($stat['mode'])
            && is_int($stat['mode'])
            && ( $stat['mode'] & 0170000 ) === 0100000;
    }

    /** Requires a path's leaf to be a real regular file. */
    private static function require_real_regular_file(string $path, string $description): void {
        $stat = @lstat($path);
        if (!is_array($stat) || !self::stat_is_real_regular_file($stat)) {
            throw new RuntimeException('The ' . $description . ' must be a real regular file, not a symlink or another file type: ' . $path);
        }
    }

    /**
     * Creates or opens the staging root and installs its web-access guards.
     *
     * A staging path below the target may not reach its leaf through a
     * target-owned symlink, because later relative protection would inspect
     * a different tree than filesystem writes use.
     */
    private static function require_staging_directory(string $path, string $target_root, bool $create): string {
        self::require_no_symlinked_staging_components_inside_target($path, $target_root);
        $resolved = self::require_absolute_directory($path, 'staging directory', $create, true);
        self::require_no_symlinked_staging_components_inside_target($path, $target_root);
        self::ensure_storage_web_guards($resolved);
        return $resolved;
    }

    /**
     * Validates, optionally creates, and canonicalizes an absolute directory.
     */
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

    /** Creates missing web guards and requires existing guards to retain the expected contents. */
    private static function ensure_storage_web_guards(string $storage_dir): void {
        self::ensure_storage_web_guard(
            $storage_dir . '/.htaccess',
            "# Reprint staging area - never web-served.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Deny from all\n"
                . "</IfModule>\n",
            'web-server'
        );
        self::ensure_storage_web_guard($storage_dir . '/index.php', "<?php\n", 'index');
    }

    /** Creates one guard exclusively, or verifies an existing real regular guard. */
    private static function ensure_storage_web_guard(string $path, string $contents, string $description): void {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            $guard = @fopen($path, 'x+b');
            if ($guard !== false) {
                try {
                    if (!self::write_all($guard, $contents) || !fflush($guard)) {
                        throw new RuntimeException('Could not write the staging ' . $description . ' guard: ' . $path, self::ERROR_RETRYABLE_IO);
                    }
                } catch (Throwable $exception) {
                    fclose($guard);
                    @unlink($path);
                    throw $exception;
                }
                fclose($guard);
                return;
            }

            // A concurrent creator may have won exclusive creation. It must
            // have installed the same real guard that this call would write.
            $stat = @lstat($path);
            if (!is_array($stat)) {
                throw new RuntimeException('Could not create the staging ' . $description . ' guard: ' . $path, self::ERROR_RETRYABLE_IO);
            }
        }

        self::require_real_regular_file($path, 'staging ' . $description . ' guard');
        $observed_contents = @file_get_contents($path, false, null, 0, strlen($contents) + 1);
        if ($observed_contents === false) {
            throw new RuntimeException('Could not read the existing staging ' . $description . ' guard.', self::ERROR_RETRYABLE_IO);
        }
        if ($observed_contents !== $contents) {
            $observed_bytes = isset($stat['size']) && is_int($stat['size']) ? $stat['size'] : -1;
            throw new RuntimeException(
                'The staging ' . $description . ' guard contents do not match Reprint\'s required guard; expected '
                . strlen($contents) . ' bytes, observed ' . $observed_bytes . ' bytes.'
            );
        }
    }

    /**
     * Rejects a staging path that traverses a symlink owned by the target tree.
     */
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
}
