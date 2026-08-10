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
 * - The **old index** describes the tree at the starting point.
 * - The **new index** describes the tree at the ending point.
 *
 * "Old" and "new" identify the snapshot which supplied an entry. They do not
 * describe traversal order within either index.
 *
 * ## How paths are compared
 *
 * `next_path()` selects the first unconsumed path found in either index. That
 * selected path becomes the **current path**. The current path can have:
 *
 * - an old entry and no new entry, meaning the path disappeared;
 * - no old entry and a new entry, meaning the path appeared; or
 * - an entry in both indexes, meaning the caller must compare their recorded
 *   information to decide whether the path changed.
 *
 * For example, while `wp-content/a.txt` is current, its old entry is the
 * record for `wp-content/a.txt` in the old index. It is not the record for
 * the path visited immediately before it. If the old index does not contain
 * `wp-content/a.txt`, the current path has no old entry and
 * `get_path_type_in_old_index()` returns null.
 *
 * This class only aligns the two indexes. It does not classify a change or
 * decide whether to copy, remove, or preserve a path. The caller makes that
 * decision from the information exposed for the current path.
 *
 * ## Current, preceding, and following paths
 *
 * The indexes are already sorted, so they can be merged in a single pass. The
 * processor retains at most one unread entry from each index and compares their
 * decoded path bytes. The retained old and new entries do not always name the
 * same path.
 *
 * Suppose the old index's retained entry is `b.txt` and the new index's
 * retained entry is `a.txt`. `a.txt` becomes the current path because it sorts
 * first. It has no old entry. Relative to `a.txt`, `b.txt` is the following path
 * in the old index.
 *
 * Current-path information and neighboring paths answer different questions:
 *
 * - `get_path_type_in_old_index()` describes the current path in the old index,
 *   or returns null when that index has no such path.
 * - When the current path is absent from the old index,
 *   `get_following_path_in_old_index()` returns the first old-index path which
 *   sorts after it.
 *
 * The corresponding new-index getters make the same distinction.
 * `get_preceding_path_in_new_index()` returns the closest new-index path which
 * sorts before the current path. `get_following_path_in_new_index()` returns the
 * closest new-index path which sorts after a current path absent from that
 * index. Together they bracket the position where that missing path would
 * appear in the new index.
 *
 * ## Selection and consumption
 *
 * A caller selects, inspects, processes, and then consumes one path:
 *
 *     $processor = FileIndexDiffProcessor::start($old_index, $new_index);
 *     while ($processor->next_path()) {
 *         apply_path_operation(
 *             $processor->get_path(),
 *             $processor->get_path_type_in_old_index(),
 *             $processor->get_path_type_in_new_index()
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
 * index and the new-index path preceding the next merge position. A selected
 * but unconsumed entry is deliberately not included. If a process stops before
 * storing the consumed cursor, `resume()` selects that path again. Work
 * performed for one path must
 * therefore tolerate replay, or its caller must store a separate durable
 * confirmation.
 *
 * Both JSONL indexes must remain immutable and sorted by decoded path bytes,
 * not by their base64 representation. The cursor identifies byte positions in
 * those same files; it does not identify or validate their contents. The new
 * index must exist. A missing old index represents an empty starting tree.
 *
 * Selecting a path may move the private file handles beyond the public cursor
 * while the processor retains unread entries. Only the cursor returned after
 * `consume_current_path()` is a continuation boundary.
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}
 * @phpstan-type Cursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 */
final class FileIndexDiffProcessor
{
    /** @var resource|null Stream containing the starting tree, or null for an empty tree. */
    private $old_index_handle = null;

    /** @var resource|null Stream containing the ending tree. */
    private $new_index_handle = null;

    /** @var IndexEntry|null Unconsumed old entry, which may be current or following. */
    private ?array $old_index_entry = null;

    /** Whether the old entry has been read, including an EOF result. */
    private bool $old_index_entry_loaded = false;

    /** @var IndexEntry|null Unconsumed new entry, which may be current or following. */
    private ?array $new_index_entry = null;

    /** Whether the new entry has been read, including an EOF result. */
    private bool $new_index_entry_loaded = false;

    /** @var Cursor Positions immediately after the entries consumed for the last current path. */
    private array $cursor;

    /** @var 'old'|'new'|'both'|null Indexes which contain the current path. */
    private ?string $current_path_membership = null;

    /** Closest consumed new-index path which sorts before the current path. */
    private ?string $preceding_path_in_new_index = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Opens two filesystem indexes and starts before their first paths.
     *
     * The old file describes the starting tree and may be absent, which is
     * equivalent to an empty tree. The new file describes the ending tree and
     * must be readable. Both files remain open until `close()`.
     *
     * @param string $old_index_file Old index, or a missing path for an empty index.
     * @param string $new_index_file New index.
     * @return self Open processor positioned before either index's first path.
     */
    public static function start(string $old_index_file, string $new_index_file): self
    {
        return self::resume(
            $old_index_file,
            $new_index_file,
            [
                "old_index_byte_offset" => 0,
                "new_index_byte_offset" => 0,
                "preceding_new_index_entry_path_b64" => null,
            ]
        );
    }

    /**
     * Reopens the two filesystem indexes at the positions recorded by a cursor.
     *
     * Each byte offset points to the next entry not represented by the stored
     * cursor. The preceding new-index path restores the lower neighbor returned
     * by `get_preceding_path_in_new_index()`. An entry selected before an
     * interruption but not consumed is deliberately selected again.
     *
     * The caller must provide the same immutable index contents used to produce
     * the cursor. This method restores positions; it does not fingerprint the
     * files or check that they still describe the same snapshots.
     *
     * @param string $old_index_file Old index, or a missing path for an empty index.
     * @param string $new_index_file New index.
     * @param array  $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type int         $old_index_byte_offset              Next byte in the old index.
     *     @type int         $new_index_byte_offset              Next byte in the new index.
     *     @type string|null $preceding_new_index_entry_path_b64 New-index path before the next position.
     * }
     * @phpstan-param Cursor $cursor
     * @return self Open processor restored at the supplied continuation boundary.
     */
    public static function resume(
        string $old_index_file,
        string $new_index_file,
        array $cursor
    ): self {
        $processor = new self();
        $processor->cursor = $cursor;
        if (is_file($old_index_file)) {
            $processor->old_index_handle = @fopen($old_index_file, "rb");
            if (!is_resource($processor->old_index_handle)) {
                throw new RuntimeException("Failed to open the old file index: {$old_index_file}.");
            }
        }
        $processor->new_index_handle = @fopen($new_index_file, "rb");
        if (!is_resource($processor->new_index_handle)) {
            $processor->close();
            throw new RuntimeException("Failed to open the new file index: {$new_index_file}.");
        }
        if (
            ( is_resource($processor->old_index_handle)
                && fseek(
                    $processor->old_index_handle,
                    $cursor["old_index_byte_offset"]
                ) !== 0 )
            || fseek(
                $processor->new_index_handle,
                $cursor["new_index_byte_offset"]
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
     * current path may occur in the old index, the new index, or both.
     * Information getters may be called only after this method returns true.
     * The caller must consume the current path before selecting another one.
     * False means both indexes reached EOF and remains false on subsequent calls.
     *
     * @return bool Whether a path was selected.
     */
    public function next_path(): bool
    {
        $this->assert_open();
        if ($this->current_path_membership !== null) {
            throw new LogicException(
                "Cannot select another file-index path before consuming the current path."
            );
        }
        if ($this->complete) {
            return false;
        }
        if (!$this->old_index_entry_loaded) {
            $this->old_index_entry = $this->read_next_index_entry(
                $this->old_index_handle
            );
            $this->old_index_entry_loaded = true;
        }
        if (!$this->new_index_entry_loaded) {
            $this->new_index_entry = $this->read_next_index_entry(
                $this->new_index_handle
            );
            $this->new_index_entry_loaded = true;
        }
        if ($this->old_index_entry === null && $this->new_index_entry === null) {
            $this->complete = true;
            return false;
        }

        if ($this->old_index_entry === null) {
            $current_path_membership = "new";
        } elseif ($this->new_index_entry === null) {
            $current_path_membership = "old";
        } else {
            // Base64 text order does not preserve arbitrary path-byte order.
            $path_comparison = strcmp(
                $this->new_index_entry["path"],
                $this->old_index_entry["path"]
            );
            $current_path_membership = $path_comparison < 0
                ? "new"
                : ( $path_comparison > 0 ? "old" : "both" );
        }

        $preceding_path_in_new_index = null;
        if ($this->cursor["preceding_new_index_entry_path_b64"] !== null) {
            $preceding_path_in_new_index = base64_decode(
                $this->cursor["preceding_new_index_entry_path_b64"],
                true
            );
            if ($preceding_path_in_new_index === false) {
                throw new RuntimeException("The file-index diff cursor has an invalid preceding new-index path.");
            }
        }
        $this->current_path_membership = $current_path_membership;
        $this->preceding_path_in_new_index = $preceding_path_in_new_index;
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
        return $this->current_path_membership === "new"
            ? $this->new_index_entry["path"]
            : $this->old_index_entry["path"];
    }

    /**
     * Returns the current path's type in the starting tree.
     *
     * Null means the old index has no entry for the current path: the path
     * did not exist in the starting tree. A retained following old entry for
     * another path does not affect this result.
     */
    public function get_path_type_in_old_index(): ?string
    {
        $entry = $this->get_old_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /**
     * Returns the current path's type in the ending tree.
     *
     * Null means the new index has no entry for the current path: the path no
     * longer exists in the ending tree. A retained following new entry for
     * another path does not affect this result.
     */
    public function get_path_type_in_new_index(): ?string
    {
        $entry = $this->get_new_index_entry_for_current_path();
        return $entry["type"] ?? null;
    }

    /**
     * Returns the size recorded for the current path in the starting tree.
     *
     * The current path must have an old entry. Call
     * `get_path_type_in_old_index()` first when its presence is not already known.
     */
    public function get_size_in_old_index(): int
    {
        return $this->get_required_old_index_entry()["size"];
    }

    /**
     * Returns the size recorded for the current path in the ending tree.
     *
     * The current path must have a new entry. Call `get_path_type_in_new_index()`
     * first when its presence is not already known.
     */
    public function get_size_in_new_index(): int
    {
        return $this->get_required_new_index_entry()["size"];
    }

    /**
     * Returns the ctime recorded for the current path in the starting tree.
     *
     * The current path must have an old entry. Call
     * `get_path_type_in_old_index()` first when its presence is not already known.
     */
    public function get_ctime_in_old_index(): int
    {
        return $this->get_required_old_index_entry()["ctime"];
    }

    /**
     * Returns the ctime recorded for the current path in the ending tree.
     *
     * The current path must have a new entry. Call `get_path_type_in_new_index()`
     * first when its presence is not already known.
     */
    public function get_ctime_in_new_index(): int
    {
        return $this->get_required_new_index_entry()["ctime"];
    }

    /**
     * Returns whether the current path was an empty directory in the starting tree.
     *
     * Null means either that the current path has no old entry or that its
     * old entry does not carry the optional empty-directory marker. Inspect
     * `get_path_type_in_old_index()` when those cases need to be distinguished.
     */
    public function get_directory_is_empty_in_old_index(): ?bool
    {
        $entry = $this->get_old_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /**
     * Returns whether the current path is an empty directory in the ending tree.
     *
     * Null means either that the current path has no new entry or that its
     * new entry does not carry the optional empty-directory marker. Inspect
     * `get_path_type_in_new_index()` when those cases need to be distinguished.
     */
    public function get_directory_is_empty_in_new_index(): ?bool
    {
        $entry = $this->get_new_index_entry_for_current_path();
        return $entry["empty"] ?? null;
    }

    /**
     * Returns the old-index path immediately following the current path.
     *
     * The current path must be absent from the old index. Its insertion position
     * then falls immediately before the retained old entry. Null means no old
     * path follows it because the old index reached EOF or was absent.
     */
    public function get_following_path_in_old_index(): ?string
    {
        $this->assert_current_path();
        if ($this->current_path_membership !== "new") {
            throw new LogicException(
                "The current path occurs in the old index, so its following old-index path has not been read."
            );
        }
        return $this->old_index_entry["path"] ?? null;
    }

    /**
     * Returns the new-index path immediately following the current path.
     *
     * The current path must be absent from the new index. Its insertion position
     * then falls immediately before the retained new entry. Null means no new
     * path follows it because the new index reached EOF.
     */
    public function get_following_path_in_new_index(): ?string
    {
        $this->assert_current_path();
        if ($this->current_path_membership !== "old") {
            throw new LogicException(
                "The current path occurs in the new index, so its following new-index path has not been read."
            );
        }
        return $this->new_index_entry["path"] ?? null;
    }

    /**
     * Returns the new-index path immediately preceding the current path.
     *
     * This is the closest new-index path which sorts before the current path,
     * not necessarily the path selected immediately before it. Null means the
     * current path sorts before every path in the new index.
     */
    public function get_preceding_path_in_new_index(): ?string
    {
        $this->assert_current_path();
        return $this->preceding_path_in_new_index;
    }

    /**
     * Records that the caller finished processing the current path.
     *
     * An old-only or new-only path consumes the entry from that index. A path
     * present in both indexes consumes both entries. A retained following entry
     * is not consumed. The cursor is updated only after the file positions of
     * the consumed entries are known. Consuming a new entry also
     * makes the current path a subsequent result's preceding new-index path.
     *
     * Calling this method after both indexes reached EOF is a logic error.
     */
    public function consume_current_path(): void
    {
        $this->assert_current_path();
        if ($this->current_path_membership !== "new") {
            $old_index_byte_offset = ftell($this->old_index_handle);
            if (!is_int($old_index_byte_offset)) {
                throw new RuntimeException("Failed to read the old file-index byte offset.");
            }
            $this->cursor["old_index_byte_offset"] = $old_index_byte_offset;
            $this->old_index_entry = null;
            $this->old_index_entry_loaded = false;
        }
        if ($this->current_path_membership !== "old") {
            $new_index_byte_offset = ftell($this->new_index_handle);
            if (!is_int($new_index_byte_offset)) {
                throw new RuntimeException("Failed to read the new file-index byte offset.");
            }
            $this->cursor["new_index_byte_offset"] = $new_index_byte_offset;
            $this->cursor["preceding_new_index_entry_path_b64"] = base64_encode(
                $this->new_index_entry["path"]
            );
            $this->new_index_entry = null;
            $this->new_index_entry_loaded = false;
        }
        $this->current_path_membership = null;
        $this->preceding_path_in_new_index = null;
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
     *     @type int         $old_index_byte_offset              Next byte in the old index.
     *     @type int         $new_index_byte_offset              Next byte in the new index.
     *     @type string|null $preceding_new_index_entry_path_b64 New-index path before the next position.
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
        if (is_resource($this->old_index_handle)) {
            fclose($this->old_index_handle);
        }
        if (is_resource($this->new_index_handle)) {
            fclose($this->new_index_handle);
        }
        $this->old_index_handle = null;
        $this->old_index_entry = null;
        $this->new_index_entry = null;
        $this->current_path_membership = null;
        $this->preceding_path_in_new_index = null;
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
        if ($this->current_path_membership === null) {
            throw new LogicException("No current file-index path. Call next_path() first.");
        }
    }

    /**
     * Returns the current path's entry from the starting tree, when it has one.
     *
     * The retained old entry may instead be the path following a current path
     * found only in the new index. In that case this method returns null rather
     * than exposing the unrelated retained entry.
     *
     * @phpstan-return IndexEntry|null
     */
    private function get_old_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_membership === "new"
            ? null
            : $this->old_index_entry;
    }

    /**
     * Returns the current path's entry from the ending tree, when it has one.
     *
     * The retained new entry may instead be the path following a current path
     * found only in the old index. In that case this method returns null rather
     * than exposing the unrelated retained entry.
     *
     * @phpstan-return IndexEntry|null
     */
    private function get_new_index_entry_for_current_path(): ?array
    {
        $this->assert_current_path();
        return $this->current_path_membership === "old"
            ? null
            : $this->new_index_entry;
    }

    /**
     * Returns the current path's old entry when its presence is required.
     *
     * @phpstan-return IndexEntry
     */
    private function get_required_old_index_entry(): array
    {
        $entry = $this->get_old_index_entry_for_current_path();
        if ($entry === null) {
            throw new LogicException("The current path has no old-index entry.");
        }
        return $entry;
    }

    /**
     * Returns the current path's new entry when its presence is required.
     *
     * @phpstan-return IndexEntry
     */
    private function get_required_new_index_entry(): array
    {
        $entry = $this->get_new_index_entry_for_current_path();
        if ($entry === null) {
            throw new LogicException("The current path has no new-index entry.");
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
