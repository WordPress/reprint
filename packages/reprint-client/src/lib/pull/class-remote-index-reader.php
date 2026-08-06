<?php

use function WordPress\Reprint\Exporter\assert_valid_path;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index failures are CLI filesystem paths and values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Reads one sorted remote JSONL index sequentially.
 *
 * A missing index is an empty reader because the first pull has no accounted
 * remote index. The byte offset follows the open handle so a caller can store
 * the position after a consumed entry and resume there in a new process.
 */
class RemoteIndexReader
{
    /** @var string Remote index file read by this object. */
    private string $remote_index_path;

    /** @var resource|null Open remote index handle, or null for a missing index. */
    private $remote_index_file_handle = null;

    public function __construct(string $remote_index_path)
    {
        $this->remote_index_path = $remote_index_path;
    }

    /** Opens the remote index, treating a missing file as an empty index. */
    public function open(): void
    {
        if (is_resource($this->remote_index_file_handle)) {
            return;
        }
        if (!file_exists($this->remote_index_path)) {
            return;
        }
        $remote_index_file_handle = fopen($this->remote_index_path, "r");
        if (!is_resource($remote_index_file_handle)) {
            throw new RuntimeException(
                "Failed to open the remote index file: {$this->remote_index_path}"
            );
        }
        $this->remote_index_file_handle = $remote_index_file_handle;
    }

    /**
     * Reads the next index entry, skipping blank lines.
     *
     * @return array|null {
     *     Decoded index entry, or null at EOF.
     *
     *     @type string $path  Decoded absolute path.
     *     @type int    $ctime Change time reported by the exporter.
     *     @type int    $size  Size in bytes.
     *     @type string $type  `file`, `dir`, or `link`.
     * }
     */
    public function next_entry(): ?array
    {
        if (!is_resource($this->remote_index_file_handle)) {
            return null;
        }
        while (( $remote_index_json_line = fgets($this->remote_index_file_handle) ) !== false) {
            $remote_index_entry = $this->parse_index_line($remote_index_json_line);
            if ($remote_index_entry !== null) {
                return $remote_index_entry;
            }
        }
        return null;
    }

    /** Returns the byte offset after the input consumed by next_entry(). */
    public function byte_offset(): int
    {
        if (!is_resource($this->remote_index_file_handle)) {
            return 0;
        }
        $byte_offset = ftell($this->remote_index_file_handle);
        if ($byte_offset === false) {
            throw new RuntimeException(
                "Failed to read the remote index byte offset: {$this->remote_index_path}"
            );
        }
        return $byte_offset;
    }

    /** Positions the open index at a previously stored byte offset. */
    public function seek_to_byte_offset(int $byte_offset): void
    {
        if (!is_resource($this->remote_index_file_handle)) {
            return;
        }
        if (fseek($this->remote_index_file_handle, $byte_offset) !== 0) {
            throw new RuntimeException(
                "Failed to seek the remote index to byte offset {$byte_offset}: {$this->remote_index_path}"
            );
        }
    }

    /** Closes the remote index handle. Repeated calls have no effect. */
    public function close(): void
    {
        if (!is_resource($this->remote_index_file_handle)) {
            return;
        }
        fclose($this->remote_index_file_handle);
        $this->remote_index_file_handle = null;
    }

    /**
     * Parses one JSON index line into an entry.
     *
     * @param string $line One JSONL line from a remote index file.
     * @return array|null {
     *     Decoded index entry, or null for an empty line.
     *
     *     @type string $path  Decoded absolute path.
     *     @type int    $ctime Change time reported by the exporter.
     *     @type int    $size  Size in bytes.
     *     @type string $type  `file`, `dir`, or `link`.
     * }
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
            "ctime" => (int) ( $data["ctime"] ?? 0 ),
            "size" => (int) ( $data["size"] ?? 0 ),
            "type" => (string) ( $data["type"] ?? "file" ),
        ];
    }
}
