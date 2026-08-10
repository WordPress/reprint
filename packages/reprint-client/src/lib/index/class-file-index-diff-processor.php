<?php

use function Reprint\Importer\decode_local_index_entry;

require_once __DIR__ . '/../local-index-update-functions.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint processor classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing processor classes.

/**
 * Aligns entries from two path-sorted file indexes without loading either index.
 *
 * `FileIndexDiffProcessor` provides the traversal shared by operations which
 * compare an earlier filesystem snapshot with a later one. It does not decide
 * whether two entries differ or what should happen to a path. Instead, it
 * presents the next path from the union of both indexes and lets its caller
 * inspect the entry from either side.
 *
 * ## Usage
 *
 * A caller performs its work before consuming the current path:
 *
 *     $processor = FileIndexDiffProcessor::start($earlier_index, $later_index);
 *     while ($processor->next_path()) {
 *         apply_path_operation(
 *             $processor->get_path(),
 *             $processor->get_earlier_path_type(),
 *             $processor->get_later_path_type()
 *         );
 *         $processor->consume_current_path();
 *         save_cursor($processor->get_cursor());
 *     }
 *     $processor->close();
 *
 * `next_path()` is the only path-selection method which reads from the index
 * handles. The `get_*()` methods only inspect the selected path. If the process
 * stops after applying an operation but before storing the consumed cursor,
 * `resume()` selects that path again. The operation associated with one path
 * therefore needs to tolerate replay, or the caller needs its own durable
 * confirmation.
 *
 * ## Alignment
 *
 * Each selected path has one of three relationships:
 *
 * | Earlier path type | Later path type | Meaning                         |
 * |-------------------|-----------------|---------------------------------|
 * | non-null          | null            | Only the earlier index has it.  |
 * | null              | non-null        | Only the later index has it.    |
 * | non-null         | non-null        | Both indexes contain the path.  |
 *
 * The two lookahead-path getters expose the entries currently retained from
 * the underlying streams even when one belongs to a later path. For example,
 * a later-only path may retain the next earlier-index path as lookahead. Callers
 * use this to recognize subtree replacements without reading ahead or moving
 * either cursor themselves.
 *
 * `previous_later_path` is the most recently consumed path from the later
 * index. Consuming an earlier-only path does not change it. This gives callers
 * the preceding later-index path while they process gaps in sparse indexes.
 *
 * ## Index and cursor requirements
 *
 * Both JSONL indexes must be sorted by decoded path bytes, not by their base64
 * representation. The later index must exist. A missing earlier index represents
 * an empty earlier snapshot.
 *
 * The cursor contains the byte offset after the last consumed entry in each
 * index and the previous consumed later-index path. It identifies positions
 * within the same immutable index files; it does not identify or validate the
 * files themselves. A caller which resumes with different index contents has
 * violated the processor contract.
 *
 * The processor keeps one current entry from each index in memory. Selecting a
 * path may move the file handles beyond the public cursor because the retained
 * entries are not consumed yet. Getter calls never move the handles. Only the
 * cursor returned after `consume_current_path()` is a continuation boundary.
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}
 * @phpstan-type Cursor array{earlier_index_byte_offset:int,later_index_byte_offset:int,previous_later_index_entry_path_b64:string|null}
 */
final class FileIndexDiffProcessor
{
    /** @var resource|null Open earlier index, or null when the earlier index is absent. */
    private $earlier_index_handle = null;

    /** @var resource|null Open later index. */
    private $later_index_handle = null;

    /** @var IndexEntry|null Earlier entry retained until its aligned path is consumed. */
    private ?array $earlier_index_entry = null;

    /** Whether the earlier entry has been read, including an EOF result. */
    private bool $earlier_index_entry_loaded = false;

    /** @var IndexEntry|null Later entry retained until its aligned path is consumed. */
    private ?array $later_index_entry = null;

    /** Whether the later entry has been read, including an EOF result. */
    private bool $later_index_entry_loaded = false;

    /** @var Cursor Position immediately after the last consumed aligned path. */
    private array $cursor;

    /** @var 'earlier'|'later'|'both'|null Relationship of the selected path. */
    private ?string $current_path_order = null;

    /** Most recently consumed later-index path restored for the selected path. */
    private ?string $previous_later_path = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Starts a new comparison at the beginning of both indexes.
     *
     * The earlier index may be absent, which is equivalent to an empty earlier
     * snapshot. The later index must be an existing readable file. Both files
     * remain open until `close()`.
     *
     * @param string $earlier_index_file Earlier index, or a missing path for an empty index.
     * @param string $later_index_file   Later index.
     * @return self Open processor positioned before the first aligned path.
     */
    public static function start(string $earlier_index_file, string $later_index_file): self
    {
        return self::resume(
            $earlier_index_file,
            $later_index_file,
            [
                "earlier_index_byte_offset" => 0,
                "later_index_byte_offset" => 0,
                "previous_later_index_entry_path_b64" => null,
            ]
        );
    }

    /**
     * Resumes a comparison after the last consumed aligned path.
     *
     * The byte offsets address the next unread entries. The previous later-index
     * path restores the context returned as `previous_later_path`. Entries read
     * before an interruption but not consumed are deliberately read again.
     *
     * The caller must provide the same immutable index contents used to produce
     * the cursor. This method restores positions; it does not fingerprint the
     * files or check that they still describe the same snapshots.
     *
     * @param string $earlier_index_file Earlier index, or a missing path for an empty index.
     * @param string $later_index_file   Later index.
     * @param array  $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type int         $earlier_index_byte_offset            Next byte in the earlier index.
     *     @type int         $later_index_byte_offset              Next byte in the later index.
     *     @type string|null $previous_later_index_entry_path_b64  Last consumed later-index path.
     * }
     * @phpstan-param Cursor $cursor
     * @return self Open processor restored at the supplied continuation boundary.
     */
    public static function resume(
        string $earlier_index_file,
        string $later_index_file,
        array $cursor
    ): self {
        $processor = new self();
        $processor->cursor = $cursor;
        if (is_file($earlier_index_file)) {
            $processor->earlier_index_handle = @fopen($earlier_index_file, "rb");
            if (!is_resource($processor->earlier_index_handle)) {
                throw new RuntimeException("Failed to open the earlier file index: {$earlier_index_file}.");
            }
        }
        $processor->later_index_handle = @fopen($later_index_file, "rb");
        if (!is_resource($processor->later_index_handle)) {
            $processor->close();
            throw new RuntimeException("Failed to open the later file index: {$later_index_file}.");
        }
        if (
            ( is_resource($processor->earlier_index_handle)
                && fseek(
                    $processor->earlier_index_handle,
                    $cursor["earlier_index_byte_offset"]
                ) !== 0 )
            || fseek(
                $processor->later_index_handle,
                $cursor["later_index_byte_offset"]
            ) !== 0
        ) {
            $processor->close();
            throw new RuntimeException("Failed to restore the file-index diff cursor.");
        }
        return $processor;
    }

    /**
     * Selects the next aligned path from the two indexes.
     *
     * This method reads at most one retained entry from each index. It returns
     * true when the per-information getters may inspect a selected path. The
     * caller must consume that path before selecting another one. False means
     * both indexes reached EOF and remains false on later calls.
     *
     * @return bool Whether a path was selected.
     */
    public function next_path(): bool
    {
        $this->assert_open();
        if ($this->current_path_order !== null) {
            throw new LogicException(
                "Cannot select another file-index path before consuming the current path."
            );
        }
        if ($this->complete) {
            return false;
        }
        if (!$this->earlier_index_entry_loaded) {
            $this->earlier_index_entry = $this->read_next_index_entry(
                $this->earlier_index_handle
            );
            $this->earlier_index_entry_loaded = true;
        }
        if (!$this->later_index_entry_loaded) {
            $this->later_index_entry = $this->read_next_index_entry(
                $this->later_index_handle
            );
            $this->later_index_entry_loaded = true;
        }
        if ($this->earlier_index_entry === null && $this->later_index_entry === null) {
            $this->complete = true;
            return false;
        }

        if ($this->earlier_index_entry === null) {
            $current_path_order = "later";
        } elseif ($this->later_index_entry === null) {
            $current_path_order = "earlier";
        } else {
            // Base64 text order does not preserve arbitrary path-byte order.
            $path_comparison = strcmp(
                $this->later_index_entry["path"],
                $this->earlier_index_entry["path"]
            );
            $current_path_order = $path_comparison < 0
                ? "later"
                : ( $path_comparison > 0 ? "earlier" : "both" );
        }

        $previous_later_path = null;
        if ($this->cursor["previous_later_index_entry_path_b64"] !== null) {
            $previous_later_path = base64_decode(
                $this->cursor["previous_later_index_entry_path_b64"],
                true
            );
            if ($previous_later_path === false) {
                throw new RuntimeException("The file-index diff cursor has an invalid previous path.");
            }
        }
        $this->current_path_order = $current_path_order;
        $this->previous_later_path = $previous_later_path;
        return true;
    }

    /** Returns the selected local relative path. */
    public function get_path(): string
    {
        $this->assert_current_path();
        return $this->current_path_order === "later"
            ? $this->later_index_entry["path"]
            : $this->earlier_index_entry["path"];
    }

    /** Returns the earlier local path type, or null when only the later index has the path. */
    public function get_earlier_path_type(): ?string
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /** Returns the later local path type, or null when only the earlier index has the path. */
    public function get_later_path_type(): ?string
    {
        $entry = $this->get_later_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /** Returns the earlier size. The selected path must occur in the earlier index. */
    public function get_earlier_size(): int
    {
        return $this->get_required_earlier_index_entry()["size"];
    }

    /** Returns the later size. The selected path must occur in the later index. */
    public function get_later_size(): int
    {
        return $this->get_required_later_index_entry()["size"];
    }

    /** Returns the earlier ctime. The selected path must occur in the earlier index. */
    public function get_earlier_ctime(): int
    {
        return $this->get_required_earlier_index_entry()["ctime"];
    }

    /** Returns the later ctime. The selected path must occur in the later index. */
    public function get_later_ctime(): int
    {
        return $this->get_required_later_index_entry()["ctime"];
    }

    /** Returns the earlier directory empty marker, or null when it is not recorded. */
    public function get_earlier_directory_is_empty(): ?bool
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /** Returns the later directory empty marker, or null when it is not recorded. */
    public function get_later_directory_is_empty(): ?bool
    {
        $entry = $this->get_later_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /** Returns the earlier entry retained as lookahead, which may follow the selected path. */
    public function get_earlier_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->earlier_index_entry["path"] ?? null;
    }

    /** Returns the later entry retained as lookahead, which may follow the selected path. */
    public function get_later_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->later_index_entry["path"] ?? null;
    }

    /** Returns the most recently consumed later-index path. */
    public function get_previous_later_path(): ?string
    {
        $this->assert_current_path();
        return $this->previous_later_path;
    }

    /**
     * Consumes the current path after its caller completes the associated work.
     *
     * An earlier-only or later-only path advances one index. A path present in
     * both indexes advances both. The cursor is updated only after the relevant
     * file positions are known, and consuming a later-index entry also records
     * that entry as the next result's `previous_later_path`.
     *
     * Calling this method after both indexes reached EOF is a logic error.
     */
    public function consume_current_path(): void
    {
        $this->assert_current_path();
        if ($this->current_path_order !== "later") {
            $earlier_index_byte_offset = ftell($this->earlier_index_handle);
            if (!is_int($earlier_index_byte_offset)) {
                throw new RuntimeException("Failed to read the earlier file-index byte offset.");
            }
            $this->cursor["earlier_index_byte_offset"] = $earlier_index_byte_offset;
            $this->earlier_index_entry = null;
            $this->earlier_index_entry_loaded = false;
        }
        if ($this->current_path_order !== "earlier") {
            $later_index_byte_offset = ftell($this->later_index_handle);
            if (!is_int($later_index_byte_offset)) {
                throw new RuntimeException("Failed to read the later file-index byte offset.");
            }
            $this->cursor["later_index_byte_offset"] = $later_index_byte_offset;
            $this->cursor["previous_later_index_entry_path_b64"] = base64_encode(
                $this->later_index_entry["path"]
            );
            $this->later_index_entry = null;
            $this->later_index_entry_loaded = false;
        }
        $this->current_path_order = null;
        $this->previous_later_path = null;
    }

    /**
     * Returns the continuation boundary after the last consumed path.
     *
     * Selecting a path and reading its information does not change this cursor.
     * A caller should first complete its work for the current path, then consume
     * the path, make its own output durable, and finally store this cursor.
     *
     * @return array {
     *     Cursor for `resume()`.
     *
     *     @type int         $earlier_index_byte_offset            Next byte in the earlier index.
     *     @type int         $later_index_byte_offset              Next byte in the later index.
     *     @type string|null $previous_later_index_entry_path_b64  Last consumed later-index path.
     * }
     *
     * @phpstan-return Cursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /**
     * Idempotently closes both index handles and makes this instance terminal.
     *
     * Closing does not consume a retained path or alter the cursor. To continue,
     * create another instance with `resume()` and the last stored cursor.
     */
    public function close(): void
    {
        if (is_resource($this->earlier_index_handle)) {
            fclose($this->earlier_index_handle);
        }
        if (is_resource($this->later_index_handle)) {
            fclose($this->later_index_handle);
        }
        $this->earlier_index_handle = null;
        $this->earlier_index_entry = null;
        $this->later_index_entry = null;
        $this->current_path_order = null;
        $this->previous_later_path = null;
        $this->closed = true;
    }

    /** Rejects attempts to inspect or consume paths after close(). */
    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException("Cannot use a closed file-index diff processor.");
        }
    }

    /** Rejects information requests when next_path() has not selected a path. */
    private function assert_current_path(): void
    {
        $this->assert_open();
        if ($this->current_path_order === null) {
            throw new LogicException("No current file-index path. Call next_path() first.");
        }
    }

    /** @phpstan-return IndexEntry|null */
    private function get_earlier_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "later"
            ? null
            : $this->earlier_index_entry;
    }

    /** @phpstan-return IndexEntry|null */
    private function get_later_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "earlier"
            ? null
            : $this->later_index_entry;
    }

    /** @phpstan-return IndexEntry */
    private function get_required_earlier_index_entry(): array
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        if ($entry === null) {
            throw new LogicException("The current path has no earlier-index entry.");
        }
        return $entry;
    }

    /** @phpstan-return IndexEntry */
    private function get_required_later_index_entry(): array
    {
        $entry = $this->get_later_index_entry_for_current_path();
        if ($entry === null) {
            throw new LogicException("The current path has no later-index entry.");
        }
        return $entry;
    }

    /**
     * Reads and decodes one entry, or returns null at EOF or for an absent index.
     *
     * The file handle advances when a line is read, but the public cursor does
     * not advance until the caller consumes the aligned path containing it.
     *
     * @param resource|null $index_handle Open index or null for an empty index.
     * @phpstan-return IndexEntry|null
     */
    private function read_next_index_entry($index_handle): ?array
    {
        if (!is_resource($index_handle)) {
            return null;
        }
        $line = fgets($index_handle);
        if ($line === false) {
            if (!feof($index_handle)) {
                throw new RuntimeException("Failed to read a file-index entry.");
            }
            return null;
        }
        return decode_local_index_entry($line);
    }
}
