<?php

use function WordPress\Reprint\Exporter\assert_valid_path;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index failures are CLI filesystem paths and values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Reads one path-sorted remote JSONL index through a retained file handle.
 *
 * Remote indexes describe source absolute paths. Paths are base64-encoded on
 * disk because Unix path bytes are not necessarily valid UTF-8. For example,
 * this JSONL entry describes `/srv/site/wp-content/index.php`:
 *
 *     {"path":"L3Nydi9zaXRlL3dwLWNvbnRlbnQvaW5kZXgucGhw","ctime":1722864000,"size":1234,"type":"file"}
 *
 * next_entry() validates and decodes the path, casts the scalar fields, and
 * returns:
 *
 *     [
 *         "path"  => "/srv/site/wp-content/index.php",
 *         "ctime" => 1722864000,
 *         "size"  => 1234,
 *         "type"  => "file",
 *     ]
 *
 * Extra raw-record fields such as `target` and `intermediate` are deliberately
 * omitted from the returned entry. Callers which need those fields read the
 * raw symlink records instead. This reader also does not read
 * `local_index.jsonl`: that relative-path format has an optional `empty`
 * field and remains owned by PushPlan and the local-index merge helpers.
 *
 * ## Lifecycle and resume
 *
 * Store the byte offset only after the returned entry has been processed:
 *
 *     $reader = new RemoteIndexReader($remote_index_path);
 *     try {
 *         $reader->open();
 *         $reader->seek_to_byte_offset($processed_byte_offset);
 *         while (($entry = $reader->next_entry()) !== null) {
 *             apply_remote_index_entry($entry);
 *             $processed_byte_offset = $reader->byte_offset();
 *             save_processed_byte_offset($processed_byte_offset);
 *         }
 *     } finally {
 *         $reader->close();
 *     }
 *
 * If the process stops inside apply_remote_index_entry(), the stored offset
 * still precedes that entry. A new reader therefore selects it again:
 *
 *     $reader = new RemoteIndexReader($remote_index_path);
 *     try {
 *         $reader->open();
 *         $reader->seek_to_byte_offset(load_processed_byte_offset());
 *         $entry = $reader->next_entry(); // The first unprocessed entry.
 *     } finally {
 *         $reader->close();
 *     }
 *
 * A missing file behaves like an empty index, as it does during the first
 * pull:
 *
 *     $reader = new RemoteIndexReader($missing_remote_index_path);
 *     try {
 *         $reader->open();
 *         $entry = $reader->next_entry(); // null.
 *         $byte_offset = $reader->byte_offset(); // 0.
 *     } finally {
 *         $reader->close();
 *     }
 *
 * Blank lines are skipped. A malformed non-blank line is consumed before
 * next_entry() throws, so a caller which accepts rejected records can continue
 * with the following line:
 *
 *     $reader = new RemoteIndexReader($remote_index_path);
 *     try {
 *         $reader->open();
 *         try {
 *             $reader->next_entry();
 *         } catch (RuntimeException $exception) {
 *             $rejected_line_end = $reader->byte_offset();
 *         }
 *         $following_entry = $reader->next_entry();
 *     } finally {
 *         $reader->close();
 *     }
 *
 * The reader assumes the file is already sorted and never sorts or writes it.
 */
class RemoteIndexReader
{
    /** @var string Remote index file read by this object. */
    private string $remote_index_path;

    /** @var resource|null Open remote index handle, or null for a missing index. */
    private $remote_index_file_handle = null;

    /**
     * Configures the remote index path without opening it.
     *
     * @param string $remote_index_path Path to one remote JSONL index.
     */
    public function __construct(string $remote_index_path)
    {
        $this->remote_index_path = $remote_index_path;
    }

    /**
     * Opens the remote index, treating a missing file as an empty index.
     *
     * Repeated calls retain the current handle and byte offset.
     *
     * @throws RuntimeException When the path exists but cannot be opened.
     */
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
     * Reads and decodes the next index entry, skipping blank lines.
     *
     * A malformed line has already advanced the handle when this method
     * throws. Calling next_entry() again therefore starts at the following
     * line rather than retrying the rejected bytes.
     *
     * @return array|null {
     *     Decoded index entry, or null for a missing index or at EOF.
     *
     *     @type string $path  Decoded absolute path.
     *     @type int    $ctime Change time reported by the exporter.
     *     @type int    $size  Size in bytes.
     *     @type string $type  `file`, `dir`, or `link`.
     * }
     * @throws RuntimeException When a non-blank line is not a decodable index
     *                          entry.
     * @throws InvalidArgumentException When the decoded path is not a valid
     *                                  remote absolute path.
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

    /**
     * Returns the byte offset after the input consumed by next_entry().
     *
     * Returns zero for a missing file or before the first read. Blank and
     * malformed lines consumed by next_entry() count toward the offset.
     *
     * @throws RuntimeException When the open handle cannot report its offset.
     */
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

    /**
     * Positions the open index at a previously stored byte offset.
     *
     * Use offsets returned by byte_offset(); an arbitrary offset may point
     * into the middle of a JSONL record. A missing-file reader remains empty
     * and treats the seek as a no-op.
     *
     * @param int $byte_offset Byte offset at the start of the next record.
     * @throws RuntimeException When the open handle cannot seek to the offset.
     */
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

    /**
     * Closes the retained remote index handle.
     *
     * Repeated calls have no effect.
     */
    public function close(): void
    {
        if (!is_resource($this->remote_index_file_handle)) {
            return;
        }
        fclose($this->remote_index_file_handle);
        $this->remote_index_file_handle = null;
    }

    /**
     * Parses one JSON index line into a validated entry.
     *
     * Missing ctime and size values become zero, and a missing type becomes
     * `file`, preserving the historical remote-index parsing contract.
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
     * @throws RuntimeException When the line or base64 path is malformed.
     * @throws InvalidArgumentException When the decoded path is not a valid
     *                                  remote absolute path.
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
