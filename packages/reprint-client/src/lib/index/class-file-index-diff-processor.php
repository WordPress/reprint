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
 *     $processor = FileIndexDiffProcessor::start($before_index, $after_index);
 *     while ($processor->next_path()) {
 *         apply_path_operation(
 *             $processor->get_path(),
 *             $processor->get_before_path_type(),
 *             $processor->get_after_path_type()
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
 * | Before path type | After path type | Meaning                         |
 * |------------------|-----------------|---------------------------------|
 * | non-null         | null            | The path occurs only before.    |
 * | null             | non-null        | The path occurs only after.     |
 * | non-null         | non-null        | Both indexes contain the path.  |
 *
 * The two lookahead-path getters expose the entries currently retained from
 * the underlying streams even when one belongs to a later path. For example,
 * an after-only path may retain the next before-side path as lookahead. Callers
 * use this to recognize subtree replacements without reading ahead or moving
 * either cursor themselves.
 *
 * `previous_after_path` is the most recently consumed path from the after
 * index. Consuming a before-only path does not change it. This gives callers
 * the preceding after-side path while they process gaps in sparse indexes.
 *
 * ## Index and cursor requirements
 *
 * Both JSONL indexes must be sorted by decoded path bytes, not by their base64
 * representation. The after index must exist. A missing before index represents
 * an empty earlier snapshot.
 *
 * The cursor contains the byte offset after the last consumed entry in each
 * index and the previous consumed after-side path. It identifies positions
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
 * @phpstan-type Cursor array{before_index_byte_offset:int,after_index_byte_offset:int,previous_after_index_entry_path_b64:string|null}
 */
final class FileIndexDiffProcessor
{
    /** @var resource|null Open earlier index, or null when the earlier index is absent. */
    private $before_index_handle = null;

    /** @var resource|null Open later index. */
    private $after_index_handle = null;

    /** @var IndexEntry|null Earlier entry retained until its aligned path is consumed. */
    private ?array $before_index_entry = null;

    /** Whether the earlier entry has been read, including an EOF result. */
    private bool $before_index_entry_loaded = false;

    /** @var IndexEntry|null Later entry retained until its aligned path is consumed. */
    private ?array $after_index_entry = null;

    /** Whether the later entry has been read, including an EOF result. */
    private bool $after_index_entry_loaded = false;

    /** @var Cursor Position immediately after the last consumed aligned path. */
    private array $cursor;

    /** @var 'before'|'after'|'both'|null Relationship of the selected path. */
    private ?string $current_path_order = null;

    /** Most recently consumed after-side path restored for the selected path. */
    private ?string $previous_after_path = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Starts a new comparison at the beginning of both indexes.
     *
     * The before index may be absent, which is equivalent to an empty earlier
     * snapshot. The after index must be an existing readable file. Both files
     * remain open until `close()`.
     *
     * @param string $before_index_file Earlier index, or a missing path for an empty index.
     * @param string $after_index_file  Later index.
     * @return self Open processor positioned before the first aligned path.
     */
    public static function start(string $before_index_file, string $after_index_file): self
    {
        return self::resume(
            $before_index_file,
            $after_index_file,
            [
                "before_index_byte_offset" => 0,
                "after_index_byte_offset" => 0,
                "previous_after_index_entry_path_b64" => null,
            ]
        );
    }

    /**
     * Resumes a comparison after the last consumed aligned path.
     *
     * The byte offsets address the next unread entries. The previous after-side
     * path restores the context returned as `previous_after_path`. Entries read
     * before an interruption but not consumed are deliberately read again.
     *
     * The caller must provide the same immutable index contents used to produce
     * the cursor. This method restores positions; it does not fingerprint the
     * files or check that they still describe the same snapshots.
     *
     * @param string $before_index_file Earlier index, or a missing path for an empty index.
     * @param string $after_index_file  Later index.
     * @param array  $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type int         $before_index_byte_offset             Next byte in the earlier index.
     *     @type int         $after_index_byte_offset              Next byte in the later index.
     *     @type string|null $previous_after_index_entry_path_b64  Last consumed later-index path.
     * }
     * @phpstan-param Cursor $cursor
     * @return self Open processor restored at the supplied continuation boundary.
     */
    public static function resume(
        string $before_index_file,
        string $after_index_file,
        array $cursor
    ): self {
        $processor = new self();
        $processor->cursor = $cursor;
        if (is_file($before_index_file)) {
            $processor->before_index_handle = @fopen($before_index_file, "rb");
            if (!is_resource($processor->before_index_handle)) {
                throw new RuntimeException("Failed to open the before file index: {$before_index_file}.");
            }
        }
        $processor->after_index_handle = @fopen($after_index_file, "rb");
        if (!is_resource($processor->after_index_handle)) {
            $processor->close();
            throw new RuntimeException("Failed to open the after file index: {$after_index_file}.");
        }
        if (
            ( is_resource($processor->before_index_handle)
                && fseek(
                    $processor->before_index_handle,
                    $cursor["before_index_byte_offset"]
                ) !== 0 )
            || fseek(
                $processor->after_index_handle,
                $cursor["after_index_byte_offset"]
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
        if (!$this->before_index_entry_loaded) {
            $this->before_index_entry = $this->read_next_index_entry(
                $this->before_index_handle
            );
            $this->before_index_entry_loaded = true;
        }
        if (!$this->after_index_entry_loaded) {
            $this->after_index_entry = $this->read_next_index_entry(
                $this->after_index_handle
            );
            $this->after_index_entry_loaded = true;
        }
        if ($this->before_index_entry === null && $this->after_index_entry === null) {
            $this->complete = true;
            return false;
        }

        if ($this->before_index_entry === null) {
            $current_path_order = "after";
        } elseif ($this->after_index_entry === null) {
            $current_path_order = "before";
        } else {
            // Base64 text order does not preserve arbitrary path-byte order.
            $path_comparison = strcmp(
                $this->after_index_entry["path"],
                $this->before_index_entry["path"]
            );
            $current_path_order = $path_comparison < 0
                ? "after"
                : ( $path_comparison > 0 ? "before" : "both" );
        }

        $previous_after_path = null;
        if ($this->cursor["previous_after_index_entry_path_b64"] !== null) {
            $previous_after_path = base64_decode(
                $this->cursor["previous_after_index_entry_path_b64"],
                true
            );
            if ($previous_after_path === false) {
                throw new RuntimeException("The file-index diff cursor has an invalid previous path.");
            }
        }
        $this->current_path_order = $current_path_order;
        $this->previous_after_path = $previous_after_path;
        return true;
    }

    /** Returns the selected local relative path. */
    public function get_path(): string
    {
        $this->assert_current_path();
        return $this->current_path_order === "after"
            ? $this->after_index_entry["path"]
            : $this->before_index_entry["path"];
    }

    /** Returns the earlier local path type, or null when the path occurs only after. */
    public function get_before_path_type(): ?string
    {
        $entry = $this->get_before_entry();
        return $entry["type"] ?? null;
    }

    /** Returns the later local path type, or null when the path occurs only before. */
    public function get_after_path_type(): ?string
    {
        $entry = $this->get_after_entry();
        return $entry["type"] ?? null;
    }

    /** Returns the earlier size. The selected path must occur in the before index. */
    public function get_before_size(): int
    {
        return $this->get_required_before_entry()["size"];
    }

    /** Returns the later size. The selected path must occur in the after index. */
    public function get_after_size(): int
    {
        return $this->get_required_after_entry()["size"];
    }

    /** Returns the earlier ctime. The selected path must occur in the before index. */
    public function get_before_ctime(): int
    {
        return $this->get_required_before_entry()["ctime"];
    }

    /** Returns the later ctime. The selected path must occur in the after index. */
    public function get_after_ctime(): int
    {
        return $this->get_required_after_entry()["ctime"];
    }

    /** Returns the earlier directory empty marker, or null when it is not recorded. */
    public function get_before_directory_is_empty(): ?bool
    {
        $entry = $this->get_before_entry();
        return $entry["empty"] ?? null;
    }

    /** Returns the later directory empty marker, or null when it is not recorded. */
    public function get_after_directory_is_empty(): ?bool
    {
        $entry = $this->get_after_entry();
        return $entry["empty"] ?? null;
    }

    /** Returns the earlier entry retained as lookahead, which may follow the selected path. */
    public function get_before_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->before_index_entry["path"] ?? null;
    }

    /** Returns the later entry retained as lookahead, which may follow the selected path. */
    public function get_after_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->after_index_entry["path"] ?? null;
    }

    /** Returns the most recently consumed later-index path. */
    public function get_previous_after_path(): ?string
    {
        $this->assert_current_path();
        return $this->previous_after_path;
    }

    /**
     * Consumes the current path after its caller completes the associated work.
     *
     * A before-only or after-only path advances one index. A path present in
     * both indexes advances both. The cursor is updated only after the relevant
     * file positions are known, and consuming an after-side entry also records
     * that entry as the next result's `previous_after_path`.
     *
     * Calling this method after both indexes reached EOF is a logic error.
     */
    public function consume_current_path(): void
    {
        $this->assert_current_path();
        if ($this->current_path_order !== "after") {
            $before_index_byte_offset = ftell($this->before_index_handle);
            if (!is_int($before_index_byte_offset)) {
                throw new RuntimeException("Failed to read the before file-index byte offset.");
            }
            $this->cursor["before_index_byte_offset"] = $before_index_byte_offset;
            $this->before_index_entry = null;
            $this->before_index_entry_loaded = false;
        }
        if ($this->current_path_order !== "before") {
            $after_index_byte_offset = ftell($this->after_index_handle);
            if (!is_int($after_index_byte_offset)) {
                throw new RuntimeException("Failed to read the after file-index byte offset.");
            }
            $this->cursor["after_index_byte_offset"] = $after_index_byte_offset;
            $this->cursor["previous_after_index_entry_path_b64"] = base64_encode(
                $this->after_index_entry["path"]
            );
            $this->after_index_entry = null;
            $this->after_index_entry_loaded = false;
        }
        $this->current_path_order = null;
        $this->previous_after_path = null;
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
     *     @type int         $before_index_byte_offset            Next byte in the earlier index.
     *     @type int         $after_index_byte_offset             Next byte in the later index.
     *     @type string|null $previous_after_index_entry_path_b64 Last consumed later-index path.
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
        if (is_resource($this->before_index_handle)) {
            fclose($this->before_index_handle);
        }
        if (is_resource($this->after_index_handle)) {
            fclose($this->after_index_handle);
        }
        $this->before_index_handle = null;
        $this->before_index_entry = null;
        $this->after_index_entry = null;
        $this->current_path_order = null;
        $this->previous_after_path = null;
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
    private function get_before_entry(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "after"
            ? null
            : $this->before_index_entry;
    }

    /** @phpstan-return IndexEntry|null */
    private function get_after_entry(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "before"
            ? null
            : $this->after_index_entry;
    }

    /** @phpstan-return IndexEntry */
    private function get_required_before_entry(): array
    {
        $entry = $this->get_before_entry();
        if ($entry === null) {
            throw new LogicException("The current path has no before-index entry.");
        }
        return $entry;
    }

    /** @phpstan-return IndexEntry */
    private function get_required_after_entry(): array
    {
        $entry = $this->get_after_entry();
        if ($entry === null) {
            throw new LogicException("The current path has no after-index entry.");
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
