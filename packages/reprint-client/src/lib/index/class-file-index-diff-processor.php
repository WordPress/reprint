<?php

use function Reprint\Importer\decode_local_index_entry;

require_once __DIR__ . '/../local-index-update-functions.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint processor classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing processor classes.

/**
 * Walks two filesystem indexes together in path order.
 *
 * ## What the indexes represent
 *
 * A filesystem index is a path-sorted list describing a filesystem tree at one
 * point in time. Each index entry records one local relative path, whether that
 * path is a file, link, or directory, its size, its inode change time (ctime),
 * and, for some directories, whether it was empty.
 *
 * This processor opens two such lists:
 *
 * - The **earlier index** describes the tree at the starting point.
 * - The **later index** describes the tree at the ending point.
 *
 * "Earlier" and "later" therefore identify the snapshot which supplied an
 * entry. They do not mean the previously or subsequently visited path. This
 * class uses "previous" only for traversal history.
 *
 * ## How paths are compared
 *
 * `next_path()` selects the first unconsumed path found in either index. That
 * selected path becomes the **current path**. The current path can have:
 *
 * - an earlier entry and no later entry, meaning the path disappeared;
 * - no earlier entry and a later entry, meaning the path appeared; or
 * - an entry in both indexes, meaning the caller must compare their recorded
 *   information to decide whether the path changed.
 *
 * For example, while `wp-content/a.txt` is current, its earlier entry is the
 * record for `wp-content/a.txt` in the earlier index. It is not the record for
 * the path visited immediately before it. If the earlier index does not contain
 * `wp-content/a.txt`, the current path has no earlier entry and
 * `get_earlier_path_type()` returns null.
 *
 * This class only aligns the two indexes. It does not classify a change or
 * decide whether to copy, remove, or preserve a path. The caller makes that
 * decision from the information exposed for the current path.
 *
 * ## Current entries and lookahead entries
 *
 * The indexes are already sorted, so they can be merged in a single pass. The
 * processor retains at most one unread entry from each index and compares their
 * decoded path bytes. The earlier and later retained entries do not always name
 * the same path.
 *
 * Suppose the earlier index's retained entry is `b.txt` and the later index's
 * retained entry is `a.txt`. `a.txt` becomes the current path because it sorts
 * first. It has no earlier entry. The retained `b.txt` entry remains available
 * as the earlier lookahead path for callers which need to reason about what
 * follows without moving either stream.
 *
 * Therefore the information getters and lookahead getters answer different
 * questions:
 *
 * - `get_earlier_path_type()` describes the current path in the earlier
 *   snapshot, or returns null when that snapshot has no such path.
 * - `get_earlier_lookahead_path()` returns the path of the entry retained from
 *   the earlier stream, even when that entry belongs to a future current path.
 *
 * The corresponding later getters make the same distinction for the later
 * index. `get_previous_later_path()` is different again: it returns the last
 * path already consumed from the later index. Earlier-only paths may have been
 * selected since then.
 *
 * ## Selection and consumption
 *
 * A caller selects, inspects, processes, and then consumes one path:
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
 * `next_path()` is the only public method which reads index entries. Information
 * getters do not move either file handle and return stable values until the
 * current path is consumed. `consume_current_path()` advances the public cursor
 * past the index entries which supplied the current path.
 *
 * ## Resume boundaries
 *
 * The cursor records the byte offset after the last consumed entry in each
 * index and the last consumed later-index path. A selected but unconsumed entry
 * is deliberately not included. If a process stops before storing the consumed
 * cursor, `resume()` selects that path again. Work performed for one path must
 * therefore tolerate replay, or its caller must store a separate durable
 * confirmation.
 *
 * Both JSONL indexes must remain immutable and sorted by decoded path bytes,
 * not by their base64 representation. The cursor identifies byte positions in
 * those same files; it does not identify or validate their contents. The later
 * index must exist. A missing earlier index represents an empty starting tree.
 *
 * Selecting a path may move the private file handles beyond the public cursor
 * while the processor retains lookahead. Only the cursor returned after
 * `consume_current_path()` is a continuation boundary.
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}
 * @phpstan-type Cursor array{earlier_index_byte_offset:int,later_index_byte_offset:int,previous_later_index_entry_path_b64:string|null}
 */
final class FileIndexDiffProcessor
{
    /** @var resource|null Stream containing the starting tree, or null for an empty tree. */
    private $earlier_index_handle = null;

    /** @var resource|null Stream containing the ending tree. */
    private $later_index_handle = null;

    /** @var IndexEntry|null Unconsumed earlier entry, which may be current or lookahead. */
    private ?array $earlier_index_entry = null;

    /** Whether the earlier entry has been read, including an EOF result. */
    private bool $earlier_index_entry_loaded = false;

    /** @var IndexEntry|null Unconsumed later entry, which may be current or lookahead. */
    private ?array $later_index_entry = null;

    /** Whether the later entry has been read, including an EOF result. */
    private bool $later_index_entry_loaded = false;

    /** @var Cursor Positions immediately after the entries consumed for the last current path. */
    private array $cursor;

    /** @var 'earlier'|'later'|'both'|null Indexes which contain the current path. */
    private ?string $current_path_order = null;

    /** Later-index path consumed most recently before the current path. */
    private ?string $previous_later_path = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Opens two filesystem indexes and starts before their first paths.
     *
     * The earlier file describes the starting tree and may be absent, which is
     * equivalent to an empty tree. The later file describes the ending tree and
     * must be readable. Both files remain open until `close()`.
     *
     * @param string $earlier_index_file Earlier index, or a missing path for an empty index.
     * @param string $later_index_file   Later index.
     * @return self Open processor positioned before either index's first path.
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
     * Reopens the two filesystem indexes at the positions recorded by a cursor.
     *
     * Each byte offset points to the next entry not represented by the stored
     * cursor. The previous later-index path restores the traversal information
     * returned by `get_previous_later_path()`. An entry selected before an
     * interruption but not consumed is deliberately selected again.
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
     * Selects the next unconsumed path found in either snapshot.
     *
     * This method retains at most one unread entry from each index, compares
     * their paths, and makes the first path in decoded-byte order current. The
     * current path may occur in the earlier index, the later index, or both.
     * Information getters may be called only after this method returns true.
     * The caller must consume the current path before selecting another one.
     * False means both indexes reached EOF and remains false on later calls.
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

    /**
     * Returns the local relative path selected by next_path().
     *
     * This method does not read either index. The returned path remains current
     * until `consume_current_path()`.
     */
    public function get_path(): string
    {
        $this->assert_current_path();
        return $this->current_path_order === "later"
            ? $this->later_index_entry["path"]
            : $this->earlier_index_entry["path"];
    }

    /**
     * Returns the current path's type in the starting tree.
     *
     * Null means the earlier index has no entry for the current path: the path
     * did not exist in the starting tree. A retained earlier lookahead entry for
     * another path does not affect this result.
     */
    public function get_earlier_path_type(): ?string
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /**
     * Returns the current path's type in the ending tree.
     *
     * Null means the later index has no entry for the current path: the path no
     * longer exists in the ending tree. A retained later lookahead entry for
     * another path does not affect this result.
     */
    public function get_later_path_type(): ?string
    {
        $entry = $this->get_later_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /**
     * Returns the size recorded for the current path in the starting tree.
     *
     * The current path must have an earlier entry. Call
     * `get_earlier_path_type()` first when its presence is not already known.
     */
    public function get_earlier_size(): int
    {
        return $this->get_required_earlier_index_entry()["size"];
    }

    /**
     * Returns the size recorded for the current path in the ending tree.
     *
     * The current path must have a later entry. Call `get_later_path_type()`
     * first when its presence is not already known.
     */
    public function get_later_size(): int
    {
        return $this->get_required_later_index_entry()["size"];
    }

    /**
     * Returns the ctime recorded for the current path in the starting tree.
     *
     * The current path must have an earlier entry. Call
     * `get_earlier_path_type()` first when its presence is not already known.
     */
    public function get_earlier_ctime(): int
    {
        return $this->get_required_earlier_index_entry()["ctime"];
    }

    /**
     * Returns the ctime recorded for the current path in the ending tree.
     *
     * The current path must have a later entry. Call `get_later_path_type()`
     * first when its presence is not already known.
     */
    public function get_later_ctime(): int
    {
        return $this->get_required_later_index_entry()["ctime"];
    }

    /**
     * Returns whether the current path was an empty directory in the starting tree.
     *
     * Null means either that the current path has no earlier entry or that its
     * earlier entry does not carry the optional empty-directory marker. Inspect
     * `get_earlier_path_type()` when those cases need to be distinguished.
     */
    public function get_earlier_directory_is_empty(): ?bool
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /**
     * Returns whether the current path is an empty directory in the ending tree.
     *
     * Null means either that the current path has no later entry or that its
     * later entry does not carry the optional empty-directory marker. Inspect
     * `get_later_path_type()` when those cases need to be distinguished.
     */
    public function get_later_directory_is_empty(): ?bool
    {
        $entry = $this->get_later_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /**
     * Returns the path of the entry retained from the earlier index.
     *
     * This is stream lookahead, not necessarily information about the current
     * path. When only the later index contains the current path, the earlier
     * retained entry names a path which sorts after it. Null means the earlier
     * stream has no retained entry because it reached EOF or was absent.
     */
    public function get_earlier_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->earlier_index_entry["path"] ?? null;
    }

    /**
     * Returns the path of the entry retained from the later index.
     *
     * This is stream lookahead, not necessarily information about the current
     * path. When only the earlier index contains the current path, the later
     * retained entry names a path which sorts after it. Null means the later
     * stream has reached EOF.
     */
    public function get_later_lookahead_path(): ?string
    {
        $this->assert_current_path();
        return $this->later_index_entry["path"] ?? null;
    }

    /**
     * Returns the last path consumed from the later index before the current path.
     *
     * This reports traversal history, not an entry from the earlier snapshot.
     * Consuming an earlier-only path leaves this value unchanged, so it may not
     * be the path selected immediately before the current one. Null means no
     * later-index path has been consumed yet.
     */
    public function get_previous_later_path(): ?string
    {
        $this->assert_current_path();
        return $this->previous_later_path;
    }

    /**
     * Records that the caller finished processing the current path.
     *
     * An earlier-only or later-only path consumes the entry from that index. A
     * path present in both indexes consumes both entries. Retained lookahead for
     * a future path is not consumed. The cursor is updated only after the file
     * positions of the consumed entries are known. Consuming a later entry also
     * makes the current path the next result's `get_previous_later_path()`.
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
     * Returns the positions from which another processor can continue.
     *
     * Each offset points immediately after the last entry consumed from its
     * index. Selecting a path and inspecting its information does not change
     * these offsets, even though private handles may have read retained entries.
     * A caller should finish its work for the current path, consume the path,
     * make its own output durable, and then store this cursor.
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

    /**
     * Returns the current path's entry from the starting tree, when it has one.
     *
     * The retained earlier entry may instead be lookahead for a future path.
     * In that case the current path exists only in the later index and this
     * method returns null rather than exposing the unrelated retained entry.
     *
     * @phpstan-return IndexEntry|null
     */
    private function get_earlier_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "later"
            ? null
            : $this->earlier_index_entry;
    }

    /**
     * Returns the current path's entry from the ending tree, when it has one.
     *
     * The retained later entry may instead be lookahead for a future path. In
     * that case the current path exists only in the earlier index and this
     * method returns null rather than exposing the unrelated retained entry.
     *
     * @phpstan-return IndexEntry|null
     */
    private function get_later_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_order === "earlier"
            ? null
            : $this->later_index_entry;
    }

    /**
     * Returns the current path's earlier entry when its presence is required.
     *
     * @phpstan-return IndexEntry
     */
    private function get_required_earlier_index_entry(): array
    {
        $entry = $this->get_earlier_index_entry_for_current_path();
        if ($entry === null) {
            throw new LogicException("The current path has no earlier-index entry.");
        }
        return $entry;
    }

    /**
     * Returns the current path's later entry when its presence is required.
     *
     * @phpstan-return IndexEntry
     */
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
     * not advance until the caller consumes the current path containing it.
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
