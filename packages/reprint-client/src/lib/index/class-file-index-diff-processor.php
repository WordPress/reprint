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
 *     while ($current_path = $processor->get_current_path()) {
 *         apply_path_operation($current_path);
 *         $processor->consume_current_path();
 *         save_cursor($processor->get_cursor());
 *     }
 *     $processor->close();
 *
 * `get_current_path()` is idempotent. It continues to return the same path
 * until `consume_current_path()` advances the processor. If the process stops
 * after applying an operation but before storing the new cursor, `resume()`
 * presents that path again. The operation associated with one path therefore
 * needs to tolerate replay, or the caller needs its own durable confirmation.
 *
 * ## Alignment
 *
 * Each current path has one of three orders:
 *
 * | Order    | Before entry | After entry | Meaning                         |
 * |----------|--------------|-------------|---------------------------------|
 * | `before` | present      | null        | The path occurs only before.    |
 * | `after`  | null         | present     | The path occurs only after.     |
 * | `both`   | present      | present     | Both indexes contain the path.  |
 *
 * The two `*_lookahead` values expose the entries currently retained from the
 * underlying streams even when one belongs to a later path. For example, an
 * `after` result may include the next `before` entry as `before_lookahead`.
 * Callers use this to recognize subtree replacements without reading ahead or
 * moving either cursor themselves.
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
 * The processor keeps one current entry from each index in memory. Reading a
 * current path may move the file handles beyond the public cursor because the
 * retained entries are not consumed yet. Only the cursor returned after
 * `consume_current_path()` is a continuation boundary.
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}
 * @phpstan-type CurrentPath array{order:'before'|'after'|'both',before:IndexEntry|null,after:IndexEntry|null,before_lookahead:IndexEntry|null,after_lookahead:IndexEntry|null,previous_after_path:string|null}
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
     * Returns the current aligned path without consuming it.
     *
     * The first call reads at most one retained entry from each index. Later
     * calls return the same result until `consume_current_path()` advances one
     * or both streams. Null means both indexes reached EOF and remains stable
     * until the processor is closed.
     *
     * `before` and `after` describe the aligned path. A null value means that
     * side has no entry at the path. The lookahead values describe the retained
     * stream entries and may therefore refer to different paths.
     *
     * @return array|null {
     *     Current aligned path, or null after both indexes reach EOF.
     *
     *     @type string     $order               `before`, `after`, or `both`.
     *     @type array|null $before              Entry at this path in the earlier index.
     *     @type array|null $after               Entry at this path in the later index.
     *     @type array|null $before_lookahead    Current retained earlier-index entry.
     *     @type array|null $after_lookahead     Current retained later-index entry.
     *     @type string|null $previous_after_path Last consumed later-index path.
     * }
     *
     * @phpstan-return CurrentPath|null
     */
    public function get_current_path(): ?array
    {
        $this->assert_open();
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
            return null;
        }

        if ($this->before_index_entry === null) {
            $order = "after";
        } elseif ($this->after_index_entry === null) {
            $order = "before";
        } else {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so compare decoded paths.
            $path_comparison = strcmp(
                $this->after_index_entry["path"],
                $this->before_index_entry["path"]
            );
            $order = $path_comparison < 0
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

        return [
            "order" => $order,
            "before" => $order === "after" ? null : $this->before_index_entry,
            "after" => $order === "before" ? null : $this->after_index_entry,
            "before_lookahead" => $this->before_index_entry,
            "after_lookahead" => $this->after_index_entry,
            "previous_after_path" => $previous_after_path,
        ];
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
        $current_path = $this->get_current_path();
        if ($current_path === null) {
            throw new LogicException("Cannot consume a completed file-index diff.");
        }
        if ($current_path["order"] !== "after") {
            $before_index_byte_offset = ftell($this->before_index_handle);
            if (!is_int($before_index_byte_offset)) {
                throw new RuntimeException("Failed to read the before file-index byte offset.");
            }
            $this->cursor["before_index_byte_offset"] = $before_index_byte_offset;
            $this->before_index_entry = null;
            $this->before_index_entry_loaded = false;
        }
        if ($current_path["order"] !== "before") {
            $after_index_byte_offset = ftell($this->after_index_handle);
            if (!is_int($after_index_byte_offset)) {
                throw new RuntimeException("Failed to read the after file-index byte offset.");
            }
            $this->cursor["after_index_byte_offset"] = $after_index_byte_offset;
            $this->cursor["previous_after_index_entry_path_b64"] = base64_encode(
                $current_path["after"]["path"]
            );
            $this->after_index_entry = null;
            $this->after_index_entry_loaded = false;
        }
    }

    /**
     * Returns the continuation boundary after the last consumed path.
     *
     * Reading through `get_current_path()` does not change this cursor. A caller
     * should first complete its work for the current path, then consume the path,
     * make its own output durable, and finally store this cursor.
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
        $this->closed = true;
    }

    /** Rejects attempts to inspect or consume paths after close(). */
    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException("Cannot use a closed file-index diff processor.");
        }
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
