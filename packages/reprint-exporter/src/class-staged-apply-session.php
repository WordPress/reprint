<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime errors are returned as API JSON, never rendered as HTML.

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}
if (!class_exists('Site_Export_Staged_Push_Stream_Parser', false)) {
    require_once __DIR__ . '/class-staged-push-stream-parser.php';
}

/**
 * Owns one resumable staged-apply upload session.
 *
 * Directory and symlink changes are stored below the private staged tree.
 * The receiver writes every accepted change, including a delete, to
 * journal.jsonl. A complete journal line is accepted; an incomplete final
 * line is dropped before the next upload. The final complete directory or
 * symlink record is materialized again before reading new changes, so a
 * stopped request can resume without rebuilding the whole staged tree. The
 * live target tree is never mutated during upload.
 * The caller accepts the upload, calls next_change() until it returns false,
 * then finishes the upload.
 */
final class Site_Export_Staged_Apply_Session {

    public const ERROR_BUSY = 1001;

    public const ERROR_SESSION_NOT_FOUND = 1002;

    public const ERROR_RETRYABLE_IO = 1003;

    public const ERROR_INVALID_CHANGE = 1004;

    private const MAX_METADATA_BYTES = 1048576;

    private const MAX_PATH_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES;

    private const MAX_JOURNAL_LINE_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES + 1;

    /**
     * Canonical live tree that this session may change during its later commit.
     *
     * @var string
     */
    private $target_root;

    /**
     * Server-owned opaque identifier used to name this session.
     *
     * @var string
     */
    private $session_id;

    /**
     * Private directory holding this session's metadata, journal, and staged tree.
     *
     * @var string
     */
    private $session_directory;

    /**
     * Immutable metadata binding this session to its target tree.
     *
     * @var string
     */
    private $session_metadata_path;

    /**
     * Fixed regular file used to exclude concurrent uploads for this session.
     *
     * @var string
     */
    private $lock_path;

    /**
     * JSONL journal of accepted directory, symlink, and delete changes.
     *
     * @var string
     */
    private $journal_path;

    /**
     * Private materialized tree for the directories and symlinks in the journal.
     *
     * @var string
     */
    private $staged_directory;

    /** @var resource|null Exclusive lock held while one upload is open. */
    private $upload_lock_handle = null;

    /** @var string|null Last accepted raw-byte path while an upload is open. */
    private $upload_last_path = null;

    /** @var Site_Export_Staged_Push_Stream_Parser|null Parser for the open request body. */
    private $upload_stream_parser = null;

    /** @var array<string,mixed>|null Change accepted by the last next_change() call. */
    private $current_change = null;

    /**
     * Records the canonical paths used by one session handle.
     *
     * The constructor does not inspect or create the workspace. Call create()
     * for a new session or open() for an existing session.
     *
     * @param string $staging_directory Existing staging root shared by apply sessions.
     * @param string $target_root Canonical root the later commit phase will change.
     * @param string $session_id  Random lowercase hexadecimal session identifier.
     */
    private function __construct(string $staging_directory, string $target_root, string $session_id) {
        $this->target_root = $target_root;
        $this->session_id = $session_id;
        $this->session_directory = $staging_directory . '/apply-sessions/' . $session_id;
        $this->session_metadata_path = $this->session_directory . '/session.json';
        $this->lock_path = $this->session_directory . '/lock';
        $this->journal_path = $this->session_directory . '/work/journal.jsonl';
        $this->staged_directory = $this->session_directory . '/work/files';
    }

    /**
     * Creates a private upload session with a random server-owned id.
     *
     * The existing staging directory must be outside the target. The caller
     * is responsible for keeping it outside the web-served tree, as required
     * by the existing staged artifact store.
     *
     * @param string $staging_directory Existing absolute staging directory that owns all apply sessions.
     * @param string $target_root Existing absolute directory to bind to the session.
     * @return self The newly initialized session.
     */
    public static function create(string $staging_directory, string $target_root): self {
        $target_root = self::require_absolute_directory($target_root, 'apply target');
        $staging_directory = self::require_staging_directory($staging_directory, $target_root);

        $sessions_directory = $staging_directory . '/apply-sessions';
        if (!@mkdir($sessions_directory, 0700) && !file_exists($sessions_directory) && !is_link($sessions_directory)) {
            throw new RuntimeException('Could not create the staged apply sessions directory: ' . $sessions_directory, self::ERROR_RETRYABLE_IO);
        }
        self::require_real_directory($sessions_directory, 'staged apply sessions directory');

        $session_id = bin2hex(random_bytes(16));
        $session_directory = $sessions_directory . '/' . $session_id;
        if (!@mkdir($session_directory, 0700)) {
            throw new RuntimeException('Could not create staged apply session directory: ' . $session_directory, self::ERROR_RETRYABLE_IO);
        }
        $session = new self($staging_directory, $target_root, $session_id);
        // A failed initialization removes only paths below this new,
        // random session directory before returning the error.
        try {
            if (!@mkdir($session->staged_directory, 0700, true) && !is_dir($session->staged_directory)) {
                throw new RuntimeException('Could not create staged apply workspace directory: ' . $session->staged_directory, self::ERROR_RETRYABLE_IO);
            }
            if (@file_put_contents($session->lock_path, '') === false) {
                throw new RuntimeException('Could not create the staged apply session lock: ' . $session->lock_path, self::ERROR_RETRYABLE_IO);
            }
            $journal_handle = @fopen($session->journal_path, 'x+b');
            if ($journal_handle === false) {
                throw new RuntimeException('Could not create journal.jsonl: ' . $session->journal_path, self::ERROR_RETRYABLE_IO);
            }
            fclose($journal_handle);
            $session->write_session_metadata([
                'version' => 1,
                'session_id' => $session_id,
                'target_root_b64' => base64_encode($target_root),
            ]);
        } catch (Throwable $exception) {
            foreach ([$session->session_metadata_path, $session->lock_path, $session->journal_path] as $path) {
                if ($session->path_present($path)) {
                    @unlink($path);
                }
            }
            @rmdir($session->staged_directory);
            @rmdir(dirname($session->staged_directory));
            @rmdir($session->session_directory);
            throw $exception;
        }
        return $session;
    }

    /**
     * Opens an active session.
     *
     * The supplied target must match the target stored in the session.
     *
     * @param string $staging_directory Existing absolute staging directory used at creation.
     * @param string $target_root Absolute target path originally bound to the session.
     * @param string $session_id  Lowercase hexadecimal id returned by get_session_id().
     * @return self The active session.
     */
    public static function open(string $staging_directory, string $target_root, string $session_id): self {
        $target_root = self::require_absolute_directory($target_root, 'apply target');
        $staging_directory = self::require_staging_directory($staging_directory, $target_root);
        self::require_real_directory($staging_directory . '/apply-sessions', 'staged apply sessions directory');
        if (!preg_match('/^[a-f0-9]{32}$/D', $session_id)) {
            throw new InvalidArgumentException('The staged apply session id must be a 32-character lowercase hexadecimal value.');
        }
        $session = new self($staging_directory, $target_root, $session_id);
        $session_metadata = @lstat($session->session_directory);
        if (!is_array($session_metadata) || !self::is_real_directory($session_metadata)) {
            if (is_array($session_metadata)) {
                throw new RuntimeException('The staged apply session path is not a real directory: ' . $session->session_directory);
            }
            throw new RuntimeException('The staged apply session does not exist: ' . $session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $session->assert_workspace_structure();
        $session->read_session_metadata();
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
     * Returns progress from the final complete journal line.
     *
     * @return array{journal_bytes:int,last_path_b64:?string}
     */
    public function get_status(): array {
        if (!$this->session_directory_exists()) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $this->assert_workspace_structure();
        $lock_handle = @fopen($this->lock_path, 'r+b');
        if ($lock_handle === false) {
            throw new RuntimeException('Could not open staged apply session lock: ' . $this->lock_path, self::ERROR_RETRYABLE_IO);
        }
        if (!flock($lock_handle, LOCK_SH | LOCK_NB)) {
            fclose($lock_handle);
            throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry the status request.', self::ERROR_BUSY);
        }
        try {
            $this->read_session_metadata();
            $journal_status = $this->read_journal_tail(false);
            return [
                'journal_bytes' => $journal_status['journal_bytes'],
                'last_path_b64' => $journal_status['last_path_b64'],
            ];
        } finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
        }
    }

    /**
     * Accepts one upload request that streams changes into this session.
     *
     * This takes the session lock, drops an incomplete journal tail, and
     * materializes the final complete change again before reading this request.
     * The caller must call finish_upload() in a finally block.
     * Each next_change() call reads and stages one change before returning.
     *
     * @param resource $input Framed request body stream.
     */
    public function accept_upload($input): void {
        if ($this->upload_lock_handle !== null) {
            throw new LogicException('A staged apply upload is already open; call finish_upload() before starting another.');
        }
        $stream_parser = new Site_Export_Staged_Push_Stream_Parser($input);
        if (!$this->session_directory_exists()) {
            throw new RuntimeException('The staged apply session does not exist: ' . $this->session_id, self::ERROR_SESSION_NOT_FOUND);
        }
        $this->assert_workspace_structure();
        $lock_handle = @fopen($this->lock_path, 'r+b');
        if ($lock_handle === false) {
            throw new RuntimeException('Could not open staged apply session lock: ' . $this->lock_path, self::ERROR_RETRYABLE_IO);
        }
        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            fclose($lock_handle);
            throw new RuntimeException('Staged apply session ' . $this->session_id . ' is busy. Retry the upload.', self::ERROR_BUSY);
        }
        try {
            $this->read_session_metadata();
            $journal_status = $this->read_journal_tail(true);
            $last_change = $journal_status['last_change'];
            if (is_array($last_change)) {
                $this->materialize_change($last_change);
            }
            $this->upload_lock_handle = $lock_handle;
            $this->upload_last_path = is_array($last_change) ? $last_change['path'] : null;
            $this->upload_stream_parser = $stream_parser;
            $this->current_change = null;
        } catch (Throwable $exception) {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            throw $exception;
        }
    }

    /** Ends an upload request and releases the session lock. */
    public function finish_upload(): void {
        if ($this->upload_lock_handle === null) {
            throw new LogicException('No staged apply upload is open; call accept_upload() before finish_upload().');
        }
        $lock_handle = $this->upload_lock_handle;
        $this->upload_lock_handle = null;
        $this->upload_last_path = null;
        $this->upload_stream_parser = null;
        $this->current_change = null;
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }

    /**
     * Reads and stages the next change in the open upload request.
     *
     * Returns false at the clean end of the request body. Invalid headers or
     * changes stop this request and throw before later request-body frames are
     * read.
     */
    public function next_change(): bool {
        if ($this->upload_lock_handle === null) {
            throw new LogicException('Accept an upload before reading changes.');
        }
        if ($this->upload_stream_parser === null) {
            throw new LogicException('This staged apply upload stopped after a rejected change; call finish_upload() before starting another.');
        }
        $this->current_change = null;
        try {
            if (!$this->upload_stream_parser->next_frame()) {
                return false;
            }
            $change = Site_Export_Staged_Push_Stream_Protocol::decode_apply_change_frame(
                $this->upload_stream_parser->get_current_frame()
            );
            if ($change['type'] === 'directory') {
                $this->stage_directory_change($change['path']);
            } elseif ($change['type'] === 'symlink') {
                $this->stage_symlink_change($change['path'], $change['target']);
            } else {
                $this->stage_delete_change($change['path']);
            }
            $this->current_change = $change;
            return true;
        } catch (Throwable $exception) {
            // Stop this request after a failed change. The next request can
            // retry from the final complete journal line.
            $this->upload_stream_parser = null;
            throw $exception;
        }
    }

    /** Returns the change staged by the last successful next_change() call. */
    public function get_current_change(): ?array {
        return $this->current_change;
    }

    /**
     * Stages one directory change and records it in the journal.
     *
     * @param string $path Raw-byte target-relative directory path.
     */
    private function stage_directory_change(string $path): void {
        $this->require_next_path($path);
        $this->require_directory_can_be_materialized($path);
        $this->append_change_to_journal(['type' => 'directory', 'path_b64' => base64_encode($path)], $path);
        $this->materialize_directory_change($path);
    }

    /** Materializes a validated directory below the private staged tree. */
    private function materialize_directory_change(string $path): void {
        $this->require_directory_can_be_materialized($path);
        $this->ensure_staged_parents($path);
        $staged_path = $this->staged_path($path);
        if ($this->path_present($staged_path)) {
            return;
        } elseif (!@mkdir($staged_path, 0700)) {
            throw new RuntimeException('Could not create staged directory ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
    }

    /**
     * Stages one symlink without following its target.
     *
     * The target is stored as bounded raw bytes, but may not be empty or
     * contain NUL.
     *
     * @param string $path   Raw-byte target-relative symlink path.
     * @param string $target Raw-byte symlink target stored without resolution.
     */
    private function stage_symlink_change(string $path, string $target): void {
        $this->require_next_path($path);
        $this->require_symlink_target($path, $target);
        $this->require_symlink_can_be_materialized($path, $target);
        $this->append_change_to_journal(
            ['type' => 'symlink', 'path_b64' => base64_encode($path), 'target_b64' => base64_encode($target)],
            $path
        );
        $this->materialize_symlink_change($path, $target);
    }

    /** Materializes a validated symlink below the private staged tree. */
    private function materialize_symlink_change(string $path, string $target): void {
        $this->require_symlink_can_be_materialized($path, $target);
        $this->ensure_staged_parents($path);
        $staged_path = $this->staged_path($path);
        if ($this->path_present($staged_path)) {
            return;
        } elseif (!@symlink($target, $staged_path)) {
            throw new RuntimeException('Could not create staged symlink ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
        }
    }

    /** Materializes the directory or symlink represented by one journal record. */
    private function materialize_change(array $change): void {
        if ($change['type'] === 'directory') {
            $this->materialize_directory_change($change['path']);
            return;
        }
        if ($change['type'] === 'symlink') {
            $this->materialize_symlink_change($change['path'], $change['target']);
            return;
        }
        if ($change['type'] !== 'delete') {
            throw new LogicException('The staged change journal has an unknown change type.');
        }
    }

    /** Requires a bounded, non-empty raw-byte symlink target. */
    private function require_symlink_target(string $path, string $target): void {
        if ($target === '') {
            throw new RuntimeException(
                'The target for staged symlink ' . $this->describe_path($path) . ' must not be empty.',
                self::ERROR_INVALID_CHANGE
            );
        }
        if (strpos($target, "\0") !== false) {
            throw new RuntimeException(
                'The target for staged symlink ' . $this->describe_path($path) . ' must not contain NUL bytes.',
                self::ERROR_INVALID_CHANGE
            );
        }
        $target_bytes = strlen($target);
        if ($target_bytes > self::MAX_PATH_BYTES) {
            throw new RuntimeException(
                'The target for staged symlink ' . $this->describe_path($path) . ' is ' . $target_bytes
                . ' bytes; the maximum is ' . self::MAX_PATH_BYTES . ' bytes.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /**
     * Records one delete in journal.jsonl.
     *
     * Deletes have no staging-tree representation: absence means unchanged,
     * and a tombstone would wrongly block replacing a file with a directory.
     *
     * @param string $path Raw-byte target-relative path to remove during commit.
     */
    private function stage_delete_change(string $path): void {
        $this->require_next_path($path);
        $this->append_change_to_journal(['type' => 'delete', 'path_b64' => base64_encode($path)], $path);
    }

    /**
     * Verifies that every fixed workspace component has its expected file type.
     *
     * No check here follows a workspace link.
     */
    private function assert_workspace_structure(): void {
        foreach (
            [
                dirname($this->session_directory) => 'staged apply sessions directory',
                $this->session_directory => 'staged apply session directory',
                dirname($this->staged_directory) => 'staged apply work directory',
                $this->staged_directory => 'staged apply staged tree',
            ] as $path => $description
        ) {
            self::require_real_directory($path, $description);
        }
        foreach (
            [
                $this->session_metadata_path => 'staged apply session metadata',
                $this->lock_path => 'staged apply lock',
                $this->journal_path => 'staged change journal',
            ] as $path => $description
        ) {
            $file_metadata = @lstat($path);
            if (
                !is_array($file_metadata)
                || !isset($file_metadata['mode'])
                || !is_int($file_metadata['mode'])
                || ( $file_metadata['mode'] & 0170000 ) !== 0100000
            ) {
                throw new RuntimeException('The ' . $description . ' must be a real regular file, not a symlink or another file type: ' . $path);
            }
        }
    }

    /** Requires each new path to sort after the last accepted path. */
    private function require_next_path(string $path): void {
        $this->require_path_after($path, $this->upload_last_path);
    }

    /** Requires a valid path to sort after a supplied raw-byte path. */
    private function require_path_after(string $path, ?string $last_path): void {
        $path_bytes = strlen($path);
        if ($path_bytes > self::MAX_PATH_BYTES) {
            throw new RuntimeException(
                'Refusing staged apply path of ' . $path_bytes . ' bytes; the maximum is ' . self::MAX_PATH_BYTES . ' bytes.',
                self::ERROR_INVALID_CHANGE
            );
        }
        try {
            $this->require_target_relative_path($path);
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage(), self::ERROR_INVALID_CHANGE);
        }
        if ($last_path !== null && strcmp($last_path, $path) >= 0) {
            throw new RuntimeException(
                'Each staged apply path must sort after the last accepted path using raw byte order; received '
                . $this->describe_path($path) . ' after ' . $this->describe_path($last_path) . '.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /**
     * Appends one accepted change as one journal line.
     *
     * @param array<string,mixed> $record JSON-safe journal record; callers base64-encode raw path bytes.
     * @param string              $path   Raw path saved as the last accepted path.
     */
    private function append_change_to_journal(array $record, string $path): void {
        $encoded_record = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded_record)) {
            throw new RuntimeException('Could not encode a staged change journal record.');
        }
        $line = $encoded_record . "\n";
        if (strlen($line) > self::MAX_JOURNAL_LINE_BYTES) {
            throw new RuntimeException('A staged change journal record exceeds its bounded size.');
        }
        $journal_handle = @fopen($this->journal_path, 'r+b');
        if ($journal_handle === false) {
            throw new RuntimeException('Could not open journal.jsonl: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (fseek($journal_handle, 0, SEEK_END) !== 0 || !self::write_all($journal_handle, $line) || !fflush($journal_handle)) {
                throw new RuntimeException('Could not append a staged change for ' . $this->describe_path($path) . '.', self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($journal_handle);
        }
        $this->upload_last_path = $path;
    }

    /**
     * Writes the immutable metadata for a new session.
     *
     * @param array<string,mixed> $metadata
     */
    private function write_session_metadata(array $metadata): void {
        $encoded_metadata = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded_metadata)) {
            throw new RuntimeException('Could not encode staged apply session metadata.');
        }
        if (strlen($encoded_metadata) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('The staged apply session metadata exceeds its bounded size: ' . $this->session_metadata_path);
        }
        $metadata_handle = @fopen($this->session_metadata_path, 'x+b');
        if ($metadata_handle === false) {
            throw new RuntimeException('Could not write staged apply session metadata: ' . $this->session_metadata_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            if (!self::write_all($metadata_handle, $encoded_metadata) || !fflush($metadata_handle)) {
                throw new RuntimeException('Could not flush staged apply session metadata: ' . $this->session_metadata_path, self::ERROR_RETRYABLE_IO);
            }
        } finally {
            fclose($metadata_handle);
        }
    }

    /**
     * Reads immutable metadata that binds this session to its target tree.
     *
     * Upload does not mutate the target; its identity is checked later during
     * the commit phase.
     *
     * @return array<string,mixed>
     */
    private function read_session_metadata(): array {
        $contents = @file_get_contents($this->session_metadata_path, false, null, 0, self::MAX_METADATA_BYTES + 1);
        if ($contents === false) {
            throw new RuntimeException('Could not read staged apply session metadata: ' . $this->session_metadata_path, self::ERROR_RETRYABLE_IO);
        }
        if (strlen($contents) > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('The staged apply session metadata exceeds its bounded size: ' . $this->session_metadata_path);
        }
        $decoded_metadata = json_decode($contents);
        if (!is_object($decoded_metadata)) {
            throw new RuntimeException('The staged apply session metadata must be a JSON object: ' . $this->session_metadata_path);
        }
        $metadata = get_object_vars($decoded_metadata);
        if (( $metadata['version'] ?? null ) !== 1) {
            throw new RuntimeException('The staged apply session metadata version must be 1: ' . $this->session_metadata_path);
        }
        if (( $metadata['session_id'] ?? null ) !== $this->session_id) {
            throw new RuntimeException('The staged apply session metadata id does not match its directory: ' . $this->session_metadata_path);
        }
        $encoded_target_root = $metadata['target_root_b64'] ?? null;
        if (!is_string($encoded_target_root)) {
            throw new RuntimeException('The staged apply session metadata must contain a base64-encoded target root.');
        }
        $stored_target_root = base64_decode($encoded_target_root, true);
        if (!is_string($stored_target_root)) {
            throw new RuntimeException('The staged apply session metadata target root is not valid base64.');
        }
        if ($stored_target_root !== $this->target_root) {
            throw new RuntimeException('The staged apply session belongs to another target directory.');
        }
        return $metadata;
    }

    /**
     * Returns the usable journal prefix and its final complete change.
     *
     * Only the final line is read. With an exclusive upload lock, an
     * incomplete final line is discarded before another change is accepted.
     *
     * @param bool $discard_incomplete_tail Whether this caller holds the exclusive upload lock.
     * @return array{journal_bytes:int,last_path_b64:?string,last_change:?array<string,mixed>}
     */
    private function read_journal_tail(bool $discard_incomplete_tail): array {
        $journal_handle = @fopen($this->journal_path, $discard_incomplete_tail ? 'r+b' : 'rb');
        if ($journal_handle === false) {
            throw new RuntimeException('Could not read journal.jsonl: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
        }
        try {
            $journal_metadata = fstat($journal_handle);
            $observed_journal_bytes = is_array($journal_metadata) && isset($journal_metadata['size'])
                ? (int) $journal_metadata['size']
                : -1;
            if ($observed_journal_bytes < 0) {
                throw new RuntimeException('Could not determine the size of journal.jsonl: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
            }
            if ($observed_journal_bytes === 0) {
                return [
                    'journal_bytes' => 0,
                    'last_path_b64' => null,
                    'last_change' => null,
                ];
            }

            $tail_bytes = min($observed_journal_bytes, self::MAX_JOURNAL_LINE_BYTES + 1);
            $tail_start = $observed_journal_bytes - $tail_bytes;
            if (fseek($journal_handle, $tail_start, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek to the end of journal.jsonl: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
            }
            $tail = stream_get_contents($journal_handle, $tail_bytes);
            if (!is_string($tail) || strlen($tail) !== $tail_bytes) {
                throw new RuntimeException('Could not read the end of journal.jsonl: ' . $this->journal_path, self::ERROR_RETRYABLE_IO);
            }

            $last_newline = strrpos($tail, "\n");
            $last_change = null;
            if ($last_newline === false) {
                if ($observed_journal_bytes > self::MAX_JOURNAL_LINE_BYTES) {
                    throw new RuntimeException('The final staged change journal line exceeds its bounded size.');
                }
                $complete_journal_bytes = 0;
            } else {
                $complete_journal_bytes = $observed_journal_bytes - ( $tail_bytes - $last_newline - 1 );
                $previous_newline = strrpos(substr($tail, 0, $last_newline), "\n");
                if ($previous_newline === false) {
                    if ($tail_start !== 0) {
                        throw new RuntimeException('The final staged change journal line exceeds its bounded size.');
                    }
                    $line = substr($tail, 0, $last_newline);
                } else {
                    $line = substr($tail, $previous_newline + 1, $last_newline - $previous_newline - 1);
                }
                $last_change = $this->decode_journal_change($line);
            }

            if ($discard_incomplete_tail && $complete_journal_bytes !== $observed_journal_bytes) {
                if (!ftruncate($journal_handle, $complete_journal_bytes) || !fflush($journal_handle)) {
                    throw new RuntimeException('Could not remove the incomplete staged change journal tail.', self::ERROR_RETRYABLE_IO);
                }
            }
            return [
                'journal_bytes' => $complete_journal_bytes,
                'last_path_b64' => $last_change === null ? null : base64_encode($last_change['path']),
                'last_change' => $last_change,
            ];
        } finally {
            fclose($journal_handle);
        }
    }

    /** Decodes and validates one complete journal line. */
    private function decode_journal_change(string $line): array {
        $record = json_decode($line, true);
        if (!is_array($record)) {
            throw new RuntimeException('The staged change journal contains an invalid JSON record.');
        }
        if (!array_key_exists('bytes', $record)) {
            $record['bytes'] = 0;
        }
        try {
            $change = Site_Export_Staged_Push_Stream_Protocol::decode_apply_change_frame($record);
            $this->require_path_after($change['path'], null);
            if ($change['type'] === 'symlink') {
                $this->require_symlink_target($change['path'], $change['target']);
            }
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The staged change journal contains an invalid change: ' . $exception->getMessage());
        } catch (RuntimeException $exception) {
            throw new RuntimeException('The staged change journal contains an invalid change: ' . $exception->getMessage());
        }
        return $change;
    }

    /** Checks whether the active session directory still exists without cached stat data. */
    private function session_directory_exists(): bool {
        clearstatcache(true, $this->session_directory);
        return is_dir($this->session_directory);
    }

    /**
     * Creates missing staging parents as real 0700 directories without following links.
     */
    private function ensure_staged_parents(string $path): void {
        $segments = explode('/', $path);
        array_pop($segments);
        $current_directory = $this->staged_directory;
        foreach ($segments as $segment) {
            $current_directory .= '/' . $segment;
            $directory_metadata = @lstat($current_directory);
            if (is_array($directory_metadata) && self::is_real_directory($directory_metadata)) {
                continue;
            }
            if (!is_array($directory_metadata) && !$this->path_present($current_directory)) {
                if (!@mkdir($current_directory, 0700)) {
                    throw new RuntimeException(
                        'Could not create staged apply parent directory ' . $this->describe_path($current_directory) . '.',
                        self::ERROR_RETRYABLE_IO
                    );
                }
                continue;
            }
            throw new RuntimeException(
                'Cannot stage ' . $this->describe_path($path) . ' below a non-directory staged ancestor.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /** Requires a directory change not to conflict with the staged tree. */
    private function require_directory_can_be_materialized(string $path): void {
        $this->require_staged_parents_are_directories($path);
        $staged_path = $this->staged_path($path);
        if ($this->path_present($staged_path) && ( !is_dir($staged_path) || is_link($staged_path) )) {
            throw new RuntimeException(
                'Cannot stage directory ' . $this->describe_path($path) . ' because that staged path is not a real directory.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /** Requires a symlink change not to conflict with the staged tree. */
    private function require_symlink_can_be_materialized(string $path, string $target): void {
        $this->require_staged_parents_are_directories($path);
        $staged_path = $this->staged_path($path);
        if (!$this->path_present($staged_path)) {
            return;
        }
        if (!is_link($staged_path)) {
            throw new RuntimeException(
                'Cannot stage symlink ' . $this->describe_path($path) . ' because that staged path is not a symlink.',
                self::ERROR_INVALID_CHANGE
            );
        }
        if (readlink($staged_path) !== $target) {
            throw new RuntimeException(
                'Cannot stage symlink ' . $this->describe_path($path) . ' because that staged symlink has another target.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /** Requires existing staging parents to be real directories. */
    private function require_staged_parents_are_directories(string $path): void {
        $segments = explode('/', $path);
        array_pop($segments);
        $current_directory = $this->staged_directory;
        foreach ($segments as $segment) {
            $current_directory .= '/' . $segment;
            $directory_metadata = @lstat($current_directory);
            if (is_array($directory_metadata) && self::is_real_directory($directory_metadata)) {
                continue;
            }
            if (!is_array($directory_metadata) && !$this->path_present($current_directory)) {
                continue;
            }
            throw new RuntimeException(
                'Cannot stage ' . $this->describe_path($path) . ' below a non-directory staged ancestor.',
                self::ERROR_INVALID_CHANGE
            );
        }
    }

    /** Validates target-relative path syntax without assuming UTF-8. */
    private function require_target_relative_path(string $path): void {
        if ($path === '') {
            throw new RuntimeException('The staged apply path must not be empty.');
        }
        if ($path[0] === '/') {
            throw new RuntimeException('The staged apply path must be relative: ' . $this->describe_path($path));
        }
        if (strpos($path, "\0") !== false) {
            throw new RuntimeException('The staged apply path must not contain NUL bytes: ' . $this->describe_path($path));
        }
        if (strpos($path, '\\') !== false) {
            throw new RuntimeException('The staged apply path must not contain backslashes: ' . $this->describe_path($path));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                throw new RuntimeException('The staged apply path must not contain empty segments: ' . $this->describe_path($path));
            }
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('The staged apply path must not contain "' . $segment . '" segments: ' . $this->describe_path($path));
            }
        }
    }

    /** Appends a validated target-relative path to the staged-tree root. */
    private function staged_path(string $path): string {
        return $this->staged_directory . '/' . $path;
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

    /** Requires a directory whose leaf is not a symlink. */
    private static function require_real_directory(string $path, string $description): void {
        $directory_metadata = @lstat($path);
        if (!is_array($directory_metadata) || !self::is_real_directory($directory_metadata)) {
            throw new RuntimeException('The ' . $description . ' must be a real directory, not a symlink or another file type: ' . $path);
        }
    }

    /**
     * Checks an lstat record for a real directory mode.
     *
     * @param array<string,mixed> $directory_metadata
     */
    private static function is_real_directory(array $directory_metadata): bool {
        return isset($directory_metadata['mode'])
            && is_int($directory_metadata['mode'])
            && ( $directory_metadata['mode'] & 0170000 ) === 0040000;
    }

    /** Opens a staging root that is separate from the apply target. */
    private static function require_staging_directory(string $path, string $target_root): string {
        $resolved_staging_directory = self::require_absolute_directory($path, 'staging directory');
        $staging_leaf = rtrim($path, '/');
        $staging_leaf = $staging_leaf === '' ? '/' : $staging_leaf;
        if ($staging_leaf === '/') {
            throw new InvalidArgumentException('The staging directory must not be the filesystem root.');
        }
        if (is_link($staging_leaf)) {
            throw new InvalidArgumentException('The staging directory must not be a symlink: ' . $path);
        }
        $target_prefix = rtrim($target_root, '/') . '/';
        $staging_prefix = rtrim($resolved_staging_directory, '/') . '/';
        if (
            $resolved_staging_directory === $target_root
            || strpos($resolved_staging_directory, $target_prefix) === 0
            || strpos($target_root, $staging_prefix) === 0
        ) {
            throw new InvalidArgumentException('The staging directory and apply target must be separate directory trees.');
        }
        return $resolved_staging_directory;
    }

    /**
     * Validates and canonicalizes an existing absolute directory.
     */
    private static function require_absolute_directory(string $path, string $name): string {
        clearstatcache(true);
        if ($path === '') {
            throw new InvalidArgumentException('The ' . $name . ' path must not be empty.');
        }
        if ($path[0] !== '/') {
            throw new InvalidArgumentException('The ' . $name . ' must be an absolute path: ' . $path);
        }
        if (strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('The ' . $name . ' path must not contain NUL bytes.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The ' . $name . ' must not contain dot segments: ' . $path);
            }
        }
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;
        if (!is_dir($path)) {
            throw new RuntimeException('The ' . $name . ' is not an existing directory: ' . $path);
        }
        $resolved_path = realpath($path);
        if (!is_string($resolved_path)) {
            throw new RuntimeException('Could not resolve the ' . $name . ': ' . $path, self::ERROR_RETRYABLE_IO);
        }
        return $resolved_path;
    }
}
