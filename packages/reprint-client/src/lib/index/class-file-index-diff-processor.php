<?php

use function Reprint\Importer\decode_local_index_entry;

require_once __DIR__ . '/../local-index-update-functions.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint processor classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing processor classes.

/**
 * Retains the current path from two sorted file indexes.
 *
 * Callers inspect one aligned before/after pair with get_current_path(), perform
 * their operation for that path, then call consume_current_path(). The cursor
 * advances only when the caller confirms that operation completed. A resumed
 * processor therefore presents an unconfirmed path again.
 *
 * The indexes must use decoded-path byte order. A missing before index is an
 * empty index. The processor retains only one entry from each index.
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}
 * @phpstan-type CurrentPath array{order:'before'|'after'|'both',before:IndexEntry|null,after:IndexEntry|null,before_lookahead:IndexEntry|null,after_lookahead:IndexEntry|null,previous_after_path:string|null}
 * @phpstan-type Cursor array{before_index_byte_offset:int,after_index_byte_offset:int,previous_after_index_entry_path_b64:string|null}
 */
final class FileIndexDiffProcessor
{
    /** @var resource|null */
    private $before_index_handle = null;

    /** @var resource|null */
    private $after_index_handle = null;

    /** @var IndexEntry|null */
    private ?array $before_index_entry = null;

    private bool $before_index_entry_loaded = false;

    /** @var IndexEntry|null */
    private ?array $after_index_entry = null;

    private bool $after_index_entry_loaded = false;

    /** @var Cursor */
    private array $cursor;

    private bool $closed = false;

    /**
     * Opens two sorted indexes at their beginning.
     *
     * @param string $before_index_file Earlier index, or a missing path for an empty index.
     * @param string $after_index_file  Later index.
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
     * Reopens two sorted indexes at the last consumed path.
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
     */
    public static function resume(
        string $before_index_file,
        string $after_index_file,
        array $cursor
    ): self {
        $processor = new self();
        $processor->cursor = $cursor;
        if (is_file($before_index_file)) {
            $processor->before_index_handle = fopen($before_index_file, "rb");
            if (!is_resource($processor->before_index_handle)) {
                throw new RuntimeException("Failed to open the before file index: {$before_index_file}.");
            }
        }
        $processor->after_index_handle = fopen($after_index_file, "rb");
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
     * Returns the next aligned path without consuming it.
     *
     * `before` is an earlier index entry and `after` is its desired or current
     * replacement. Null means that side has no entry at this path.
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

    /** Consumes the current aligned path after the caller completes its operation. */
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
     * Returns the cursor after the last consumed path.
     *
     * @phpstan-return Cursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Idempotently closes both index handles. */
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

    /** Rejects work after close(). */
    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException("Cannot use a closed file-index diff processor.");
        }
    }

    /**
     * Reads one index entry and decodes its path.
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
