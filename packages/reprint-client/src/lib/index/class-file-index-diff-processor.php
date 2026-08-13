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
 * point in time. Each index entry records one path, whether that path is a file,
 * link, or directory, its size, its inode change time (ctime), and, for some
 * directories, whether it was empty. Both indexes must use the same path
 * coordinate system; paths may be local relative paths or remote absolute paths.
 * Each physical line must decode to one entry. A decoder cannot filter lines;
 * blank or invalid records must throw.
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
 * - an old entry and no new entry, making the path `deleted`;
 * - no old entry and a new entry, making the path `added`; or
 * - an entry in both indexes, making the path `modified` when its recorded
 *   type, size, or ctime differs and `unchanged` otherwise.
 *
 * For example, while `wp-content/a.txt` is current, its old entry is the
 * record for `wp-content/a.txt` in the old index. It is not the record for
 * the path visited immediately before it. If the old index does not contain
 * `wp-content/a.txt`, the current path has no old entry and
 * `get_path_type_in_old_index()` returns null.
 *
 * The processor labels that snapshot difference through `get_path_transition()`.
 * It does not decide whether to copy, remove, or preserve a path. That policy
 * remains with the caller.
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
 * ## Traversal
 *
 * The first call to `next_path()` selects a path. Each subsequent call consumes
 * the current path, advances the cursor, and selects the following path:
 *
 *     $processor = FileIndexDiffProcessor::create($old_index, $new_index);
 *     $has_path = $processor->next_path();
 *     while ($has_path) {
 *         apply_path_operation(
 *             $processor->get_path(),
 *             $processor->get_path_transition()
 *         );
 *         $has_path = $processor->next_path();
 *         save_cursor($processor->get_cursor());
 *     }
 *     $processor->close();
 *
 * `next_path()` is the only public method which reads index entries. Information
 * getters do not move either file handle and return stable values until the
 * next call to `next_path()`.
 *
 * ## Resume boundaries
 *
 * The cursor records the byte offset after the last consumed entry in each
 * index and the new-index path preceding the next merge position. A selected
 * but unconsumed entry is deliberately not included. If a process stops before
 * advancing and storing the cursor, `resume()` selects that path again. Work
 * performed for one path must therefore tolerate replay, or its caller must
 * store a separate durable confirmation.
 *
 * Both JSONL indexes must remain immutable and sorted by decoded path bytes,
 * not by their base64 representation. The cursor identifies byte positions in
 * those same files; it does not identify or validate their contents. The new
 * index must exist. A missing old index represents an empty starting tree.
 *
 * Selecting a path may move the private file handles beyond the public cursor
 * while the processor retains unread entries. The cursor still names only
 * consumed entries and can always be passed to `resume()`.
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

    /** @var callable(string):IndexEntry Decodes one JSONL index line. */
    private $decode_index_line;

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
    private ?string $current_path_found_in = null;

    /** Closest consumed new-index path which sorts before the current path. */
    private ?string $preceding_path_in_new_index = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() has made this processor terminal. */
    private bool $closed = false;

    /**
     * Creates a processor positioned before either index's first path.
     *
     * The old file describes the starting tree and may be absent, which is
     * equivalent to an empty tree. The new file describes the ending tree and
     * must be readable. Both files remain open until `close()`.
     *
     * @param string        $old_index_file  Old index, or a missing path for an empty index.
     * @param string        $new_index_file  New index.
     * @param callable|null $decode_index_line Decoder for one JSONL line. Null uses the local decoder.
     * @phpstan-param (callable(string):IndexEntry)|null $decode_index_line
     * @return self Open processor positioned before either index's first path.
     */
    public static function create(
        string $old_index_file,
        string $new_index_file,
        ?callable $decode_index_line = null
    ): self {
        return self::resume(
            $old_index_file,
            $new_index_file,
            [
                "old_index_byte_offset" => 0,
                "new_index_byte_offset" => 0,
                "preceding_new_index_entry_path_b64" => null,
            ],
            $decode_index_line
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
     * The cursor does not store the line decoder, so the caller must pass the
     * same decoder again when resuming.
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
     * @param callable|null $decode_index_line Decoder for one JSONL line. Null uses the local decoder.
     * @phpstan-param (callable(string):IndexEntry)|null $decode_index_line
     * @return self Open processor restored at the supplied continuation boundary.
     */
    public static function resume(
        string $old_index_file,
        string $new_index_file,
        array $cursor,
        ?callable $decode_index_line = null
    ): self {
        $processor = new self();
        $processor->cursor = $cursor;
        $processor->decode_index_line = $decode_index_line
            ?? static function (string $line): array {
                return decode_local_index_entry($line);
            };
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
     * If a path is already current, this method first records it as consumed and
     * advances the public cursor past the entries which supplied it. It then
     * retains at most one unread entry from each index, compares their paths,
     * and makes the first path in decoded-byte order current. The current path
     * may occur in the old index, the new index, or both. Information getters
     * may be called only after this method returns true. False means both indexes
     * reached EOF and remains false on subsequent calls.
     *
     * @return bool Whether a path was selected.
     */
    public function next_path(): bool
    {
        $this->assert_open();
        if ($this->current_path_found_in !== null) {
            if ($this->current_path_found_in !== "new") {
                $old_index_byte_offset = ftell($this->old_index_handle);
                if (!is_int($old_index_byte_offset)) {
                    throw new RuntimeException("Failed to read the old file-index byte offset.");
                }
                $this->cursor["old_index_byte_offset"] = $old_index_byte_offset;
                $this->old_index_entry = null;
                $this->old_index_entry_loaded = false;
            }
            if ($this->current_path_found_in !== "old") {
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
            $this->current_path_found_in = null;
            $this->preceding_path_in_new_index = null;
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
            $current_path_found_in = "new";
        } elseif ($this->new_index_entry === null) {
            $current_path_found_in = "old";
        } else {
            // Base64 text order does not preserve arbitrary path-byte order.
            $path_comparison = strcmp(
                $this->new_index_entry["path"],
                $this->old_index_entry["path"]
            );
            $current_path_found_in = $path_comparison < 0
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
        $this->current_path_found_in = $current_path_found_in;
        $this->preceding_path_in_new_index = $preceding_path_in_new_index;
        return true;
    }

    /**
     * Returns the path selected by next_path().
     *
     * This method does not read either index. The returned path remains current
     * until the next call to `next_path()`.
     */
    public function get_path(): string
    {
        $this->assert_current_path();
        return $this->current_path_found_in === "new"
            ? $this->new_index_entry["path"]
            : $this->old_index_entry["path"];
    }

    /**
     * Returns the current path's transition from the old to the new snapshot.
     *
     * A path is `added` when it occurs only in the new index and `deleted` when
     * it occurs only in the old index. A path present in both is `modified`
     * when its recorded type, size, or ctime differs; otherwise it is
     * `unchanged`. The label describes the indexes, not the operation a caller
     * should perform.
     *
     * @return string One of `added`, `modified`, `deleted`, or `unchanged`.
     * @phpstan-return 'added'|'modified'|'deleted'|'unchanged'
     */
    public function get_path_transition(): string
    {
        $this->assert_current_path();
        if ($this->current_path_found_in === "new") {
            return "added";
        }
        if ($this->current_path_found_in === "old") {
            return "deleted";
        }

        $old_index_entry = $this->get_required_old_index_entry();
        $new_index_entry = $this->get_required_new_index_entry();
        if (
            $old_index_entry["type"] !== $new_index_entry["type"]
            || $old_index_entry["size"] !== $new_index_entry["size"]
            || $old_index_entry["ctime"] !== $new_index_entry["ctime"]
        ) {
            return "modified";
        }
        return "unchanged";
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
        if ($this->current_path_found_in !== "new") {
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
        if ($this->current_path_found_in !== "old") {
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
     * Returns the positions from which another processor can continue.
     *
     * Each offset points immediately after the last entry consumed from its
     * index. Selecting a path and inspecting its information does not change
     * these offsets, even though private handles may have read retained entries.
     * Calling `next_path()` again advances the cursor past the current path
     * before selecting another one.
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
        $this->current_path_found_in = null;
        $this->preceding_path_in_new_index = null;
        $this->closed = true;
    }

    /** Rejects attempts to select or inspect paths after close(). */
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
        if ($this->current_path_found_in === null) {
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
        return $this->current_path_found_in === "new"
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
        return $this->current_path_found_in === "old"
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
     * does not advance until `next_path()` moves past the current path.
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
        $index_entry = ( $this->decode_index_line )($line);
        if (!is_array($index_entry)) {
            throw new UnexpectedValueException(
                "The file-index decoder returned " . gettype($index_entry)
                . "; expected one index entry for each line."
            );
        }
        return $index_entry;
    }
}
