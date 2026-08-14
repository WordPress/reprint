<?php
declare(strict_types=1);
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal errors contain local paths and protocol field names, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Stores the raw index range and indexed roots for each completed traversal.
 *
 * Raw index byte ranges remain meaningful until sort. Base64 paths and
 * canonical-root markers keep the journal byte-safe and bounded-memory.
 */
final class RemoteIndexTraversalJournal
{
    private string $path;
    /** @var resource|null Open journal handle. */
    private $handle = null;
    private string $root_markers_directory;
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->root_markers_directory = dirname($path)
            . '/remote-index-traversal-roots.next';
    }
    /**
     * Opens the journal at its durable append boundary.
     *
     * Unconfirmed tail bytes are discarded. Boundary zero starts a fresh
     * lifecycle and streams removal of every marker from the preceding one.
     */
    public function open_and_truncate_to_saved_byte_offset(int $byte_offset): void
    {
        if (is_resource($this->handle)) {
            throw new LogicException('Remote-index traversal journal is already open.');
        }
        if ($byte_offset === 0) {
            $this->delete_root_markers();
        }
        $this->handle = fopen($this->path, 'c+b');
        $stat = is_resource($this->handle) ? fstat($this->handle) : false;
        if (
            !is_resource($this->handle)
            || $stat === false
            || $byte_offset < 0
            || $byte_offset > $stat['size']
            || !ftruncate($this->handle, $byte_offset)
            || fseek($this->handle, $byte_offset) !== 0
        ) {
            $this->close();
            throw new RuntimeException(
                "Failed to resume the remote-index traversal journal at byte offset {$byte_offset}."
            );
        }
    }

    /**
     * Durably records one completed traversal and writes its root markers.
     *
     * Markers are written before the returned boundary is saved in state. A
     * marker left by a failed state save remains harmless because lookups
     * validate its record against the state's saved journal boundary.
     *
     * @param string[] $indexed_roots Ordered canonical roots from the server.
     * @return int First journal byte after the durable completion record.
     */
    public function complete_traversal(
        int $next_remote_index_start_byte_offset,
        int $next_remote_index_end_byte_offset,
        string $canonical_list_directory,
        array $indexed_roots
    ): int {
        $this->require_handle();
        self::assert_path($canonical_list_directory, 'canonical list directory');
        if (
            $next_remote_index_start_byte_offset < 0
            || $next_remote_index_end_byte_offset
                < $next_remote_index_start_byte_offset
            || $indexed_roots === []
            || array_values($indexed_roots) !== $indexed_roots
        ) {
            throw new InvalidArgumentException(
                'Remote-index traversal roots or byte range are invalid.'
            );
        }
        foreach ($indexed_roots as $indexed_root) {
            if (!is_string($indexed_root)) {
                throw new InvalidArgumentException(
                    'Each remote-index traversal root must be a string.'
                );
            }
            self::assert_path($indexed_root, 'indexed root');
        }
        if (
            $indexed_roots[0] !== $canonical_list_directory
            || count($indexed_roots) !== count(array_unique($indexed_roots))
        ) {
            throw new InvalidArgumentException(
                'Remote-index traversal roots or byte range are invalid.'
            );
        }

        if (fseek($this->handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException('Failed to seek to the traversal journal append position.');
        }
        $completion_record_byte_offset = ftell($this->handle);
        $json = json_encode([
            'type' => 'traversal_complete',
            'indexed_roots_b64' => array_map('base64_encode', $indexed_roots),
            'next_remote_index_start_byte_offset' =>
                $next_remote_index_start_byte_offset,
            'next_remote_index_end_byte_offset' =>
                $next_remote_index_end_byte_offset,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_int($completion_record_byte_offset) || $json === false) {
            throw new RuntimeException('Failed to encode the traversal journal record.');
        }
        $line = $json . "\n";
        if (fwrite($this->handle, $line) !== strlen($line)) {
            throw new RuntimeException('Failed to append the traversal journal record.');
        }
        if (!fflush($this->handle)) {
            throw new RuntimeException('Failed to flush the remote-index traversal journal.');
        }
        $journal_byte_offset = ftell($this->handle);
        if (!is_int($journal_byte_offset)) {
            throw new RuntimeException('Failed to read the traversal journal byte offset.');
        }

        if (
            !is_dir($this->root_markers_directory)
            && !mkdir($this->root_markers_directory, 0777, true)
            && !is_dir($this->root_markers_directory)
        ) {
            throw new RuntimeException('Failed to create the traversal root marker directory.');
        }
        foreach ($indexed_roots as $indexed_root) {
            if ($this->root_marker_is_valid($indexed_root, $journal_byte_offset)) {
                continue;
            }
            $marker_path = $this->root_marker_path($indexed_root);
            $marker_json = json_encode([
                'completion_record_byte_offset' =>
                    $completion_record_byte_offset,
            ], JSON_UNESCAPED_SLASHES);
            if ($marker_json === false) {
                throw new RuntimeException('Failed to encode a traversal root marker.');
            }
            $swap_path = $marker_path . '.swap';
            if (
                file_put_contents($swap_path, $marker_json . "\n") === false
                || !rename($swap_path, $marker_path)
            ) {
                @unlink($swap_path);
                throw new RuntimeException('Failed to save a traversal root marker.');
            }
        }
        return $journal_byte_offset;
    }

    /** Reports whether a canonical path is inside a durably completed root. */
    public function covers_canonical_path(
        string $canonical_path,
        int $journal_byte_offset
    ): bool {
        self::assert_path($canonical_path, 'remote-index traversal target');
        $candidate = $canonical_path;
        while (true) {
            if ($this->root_marker_is_valid($candidate, $journal_byte_offset)) {
                return true;
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }
            $candidate = $parent;
        }
    }

    /** Decodes one canonical base64 path from protocol metadata. */
    public static function decode_canonical_path($encoded_path, string $field_name): string
    {
        $path = is_string($encoded_path)
            ? base64_decode($encoded_path, true)
            : false;
        if (!is_string($path) || base64_encode($path) !== $encoded_path) {
            throw new UnexpectedValueException("{$field_name} contains invalid base64.");
        }
        self::assert_path($path, $field_name);
        return $path;
    }

    /** @return string[] Decoded non-empty canonical protocol paths. */
    public static function decode_canonical_paths($encoded_paths, string $field_name): array
    {
        if (
            !is_array($encoded_paths)
            || $encoded_paths === []
            || array_values($encoded_paths) !== $encoded_paths
        ) {
            throw new UnexpectedValueException("{$field_name} must be a non-empty list.");
        }
        $paths = [];
        foreach ($encoded_paths as $encoded_path) {
            $paths[] = self::decode_canonical_path($encoded_path, $field_name);
        }
        return $paths;
    }

    /**
     * Reads one completed traversal from a durable forward journal position.
     *
     * The returned byte offset is the start of the next completion. Null means
     * the supplied position is exactly the saved journal boundary.
     *
     * @return array|null {
     *     One durable traversal completion, or null at the saved boundary.
     *
     *     @type string[] $indexed_roots                         Canonical indexed roots.
     *     @type int      $next_remote_index_start_byte_offset   Raw range start.
     *     @type int      $next_remote_index_end_byte_offset     Raw range end.
     *     @type int      $next_traversal_journal_byte_offset    Following journal byte.
     * }
     */
    public function read_completed_traversal_at(
        int $record_byte_offset,
        int $journal_byte_offset
    ): ?array {
        $this->require_handle();
        if ($record_byte_offset === $journal_byte_offset) {
            return null;
        }
        if (
            $record_byte_offset < 0
            || $record_byte_offset > $journal_byte_offset
            || fseek($this->handle, $record_byte_offset) !== 0
        ) {
            throw new UnexpectedValueException(
                'Remote-index traversal completion offset is invalid.'
            );
        }
        $line = fgets($this->handle);
        $next_record_byte_offset = ftell($this->handle);
        $record = is_string($line) && substr($line, -1) === "\n"
            ? json_decode(substr($line, 0, -1), true)
            : null;
        if (
            !is_int($next_record_byte_offset)
            || $next_record_byte_offset > $journal_byte_offset
            || !is_array($record)
            || array_keys($record) !== [
                'type',
                'indexed_roots_b64',
                'next_remote_index_start_byte_offset',
                'next_remote_index_end_byte_offset',
            ]
            || $record['type'] !== 'traversal_complete'
            || !is_int($record['next_remote_index_start_byte_offset'])
            || $record['next_remote_index_start_byte_offset'] < 0
            || !is_int($record['next_remote_index_end_byte_offset'])
            || $record['next_remote_index_end_byte_offset']
                < $record['next_remote_index_start_byte_offset']
        ) {
            throw new UnexpectedValueException(
                'Remote-index traversal completion is outside the durable journal or invalid.'
            );
        }
        $indexed_roots = self::decode_canonical_paths(
            $record['indexed_roots_b64'],
            'indexed_roots_b64'
        );
        if (count($indexed_roots) !== count(array_unique($indexed_roots))) {
            throw new UnexpectedValueException(
                'Remote-index traversal roots must contain no duplicates.'
            );
        }
        return [
            'indexed_roots' => $indexed_roots,
            'next_remote_index_start_byte_offset' =>
                $record['next_remote_index_start_byte_offset'],
            'next_remote_index_end_byte_offset' =>
                $record['next_remote_index_end_byte_offset'],
            'next_traversal_journal_byte_offset' =>
                $next_record_byte_offset,
        ];
    }

    /** Idempotently closes the journal. */
    public function close(): void
    {
        if (!is_resource($this->handle)) {
            $this->handle = null;
            return;
        }
        if (!fclose($this->handle)) {
            $this->handle = null;
            throw new RuntimeException('Failed to close the traversal journal.');
        }
        $this->handle = null;
    }

    /** @return string[] Canonical indexed roots from one durable completion. */
    private function read_indexed_roots_at(
        int $record_byte_offset,
        int $journal_byte_offset
    ): array {
        $completion = $this->read_completed_traversal_at(
            $record_byte_offset,
            $journal_byte_offset
        );
        if ($completion === null) {
            throw new UnexpectedValueException(
                'Remote-index traversal completion offset is invalid.'
            );
        }
        return $completion['indexed_roots'];
    }

    private function root_marker_path(string $canonical_root): string
    {
        return $this->root_markers_directory . '/'
            . hash('sha256', $canonical_root) . '.json';
    }

    private function root_marker_is_valid(
        string $canonical_root,
        int $journal_byte_offset
    ): bool {
        $marker_json = @file_get_contents(
            $this->root_marker_path($canonical_root)
        );
        $marker = is_string($marker_json)
            ? json_decode($marker_json, true)
            : null;
        if (
            !is_array($marker)
            || count($marker) !== 1
            || !isset($marker['completion_record_byte_offset'])
            || !is_int($marker['completion_record_byte_offset'])
        ) {
            return false;
        }
        try {
            $indexed_roots = $this->read_indexed_roots_at(
                $marker['completion_record_byte_offset'],
                $journal_byte_offset
            );
            return in_array($canonical_root, $indexed_roots, true);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Streams deletion of derived root markers without materializing names. */
    private function delete_root_markers(): void
    {
        $directory_handle = @opendir($this->root_markers_directory);
        if (!is_resource($directory_handle)) {
            return;
        }
        while (true) {
            $entry = readdir($directory_handle);
            if ($entry === false) {
                break;
            }
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->root_markers_directory . '/' . $entry);
            }
        }
        closedir($directory_handle);
        @rmdir($this->root_markers_directory);
    }

    /** Validates one absolute decoded path. */
    private static function assert_path(string $path, string $field_name): void
    {
        assert_valid_path($path, $field_name);
        if (normalize_path($path) !== $path) {
            throw new UnexpectedValueException("{$field_name} must be normalized.");
        }
    }

    private function require_handle(): void
    {
        if (!is_resource($this->handle)) {
            throw new LogicException('Remote-index traversal journal is not open.');
        }
    }
}
