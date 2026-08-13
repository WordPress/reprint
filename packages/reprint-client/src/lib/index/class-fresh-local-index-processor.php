<?php

use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_entry;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/../sort-index-file.php';
require_once __DIR__ . '/../local-index-update-functions.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing streaming classes.

/**
 * Builds one sorted fresh local index in resumable steps.
 *
 * FileIndexProcessor walks the filesystem. This processor writes its entries
 * in local-relative coordinates, stores the output byte offset with the
 * traversal cursor, and sorts the completed index. Callers store get_cursor()
 * after each step and pass that cursor unchanged to resume().
 *
 * A resumed indexing phase truncates the fresh local index to its saved byte
 * offset before continuing the filesystem traversal. Bytes written by a step
 * whose cursor was not stored are written again from the same traversal point.
 *
 * Each next_step() call performs one filesystem traversal event or sorts the
 * completed index. False means the index is complete and remains false.
 *
 *     $processor = FreshLocalIndexProcessor::start(
 *         $fresh_local_index_file,
 *         $filesystem_root,
 *         $storage_path
 *     );
 *     do {
 *         $has_next_step = $processor->next_step();
 *         $processor->flush_pending_output();
 *         save_cursor($processor->get_cursor());
 *     } while ($has_next_step);
 *     $processor->close();
 *
 * A later process continues with
 * `FreshLocalIndexProcessor::resume($saved_cursor)`.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type IndexingPosition array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type SortingPosition array{phase:'sorting'}
 * @phpstan-type CompletePosition array{phase:'complete'}
 * @phpstan-type Position IndexingPosition|SortingPosition|CompletePosition
 * @phpstan-type Cursor array{fresh_local_index_file_b64:string,filesystem_root_b64:string,storage_path_b64:string,include_caches:bool,position:Position}
 */
final class FreshLocalIndexProcessor
{
    /** @var Cursor Current cursor returned to the caller. */
    private array $cursor;

    /** Fresh local index path decoded from the cursor. */
    private string $fresh_local_index_file;

    /** Filesystem root decoded from the cursor. */
    private string $filesystem_root;

    /** Filesystem traversal retained during indexing. */
    private FileIndexProcessor $file_index_processor;

    /** @var resource|null Fresh local index retained during indexing. */
    private $fresh_local_index_handle = null;

    /** Whether close() released this processor's handles. */
    private bool $closed = false;

    /**
     * Starts a fresh local index before its first filesystem path.
     *
     * The output file is replaced. The filesystem root must resolve to a real
     * directory and may not itself be a symlink.
     *
     * @param string $fresh_local_index_file Output JSONL file.
     * @param string $filesystem_root        Filesystem root represented by the index.
     * @param string $storage_path           Reprint storage path omitted by FileIndexProcessor.
     * @param bool   $include_caches          Whether generated caches and development files are indexed.
     */
    public static function start(
        string $fresh_local_index_file,
        string $filesystem_root,
        string $storage_path,
        bool $include_caches = false
    ): self {
        $processor = new self();
        $filesystem_root = $processor->resolve_filesystem_root($filesystem_root);
        $processor->fresh_local_index_file = $fresh_local_index_file;
        $processor->filesystem_root = $filesystem_root;
        $processor->fresh_local_index_handle = fopen(
            $fresh_local_index_file,
            "w+b"
        );
        if (!is_resource($processor->fresh_local_index_handle)) {
            throw new RuntimeException(
                "Failed to open the fresh local index: {$fresh_local_index_file}"
            );
        }
        $processor->file_index_processor = FileIndexProcessor::start(
            [$filesystem_root],
            $filesystem_root,
            false,
            $include_caches,
            $storage_path
        );
        $processor->cursor = [
            "fresh_local_index_file_b64" => base64_encode(
                $fresh_local_index_file
            ),
            "filesystem_root_b64" => base64_encode($filesystem_root),
            "storage_path_b64" => base64_encode($storage_path),
            "include_caches" => $include_caches,
            "position" => [
                "phase" => "indexing",
                "file_index_cursor" =>
                    $processor->file_index_processor->get_cursor(),
                "fresh_local_index_byte_offset" => 0,
            ],
        ];
        return $processor;
    }

    /**
     * Resumes from a cursor returned by get_cursor().
     *
     * During indexing, bytes after the saved output offset are discarded and
     * FileIndexProcessor resumes from the traversal cursor stored with it.
     * Sorting starts again from the complete unsorted file. A complete cursor
     * opens no files.
     *
     * @phpstan-param Cursor $cursor
     */
    public static function resume(array $cursor): self
    {
        $processor = new self();
        $processor->cursor = $cursor;
        $processor->fresh_local_index_file = self::decode_cursor_path(
            $cursor["fresh_local_index_file_b64"],
            "fresh local index file"
        );
        $processor->filesystem_root = self::decode_cursor_path(
            $cursor["filesystem_root_b64"],
            "filesystem root"
        );
        $storage_path = self::decode_cursor_path(
            $cursor["storage_path_b64"],
            "storage path"
        );
        $position = $cursor["position"];
        if ($position["phase"] !== "indexing") {
            return $processor;
        }

        $filesystem_root = $processor->resolve_filesystem_root(
            $processor->filesystem_root
        );
        if ($filesystem_root !== $processor->filesystem_root) {
            throw new RuntimeException(
                "The fresh local index filesystem root no longer resolves to its saved path."
            );
        }
        $processor->fresh_local_index_handle = fopen(
            $processor->fresh_local_index_file,
            "r+b"
        );
        if (!is_resource($processor->fresh_local_index_handle)) {
            throw new RuntimeException(
                "Failed to reopen the fresh local index: "
                . $processor->fresh_local_index_file
            );
        }
        if (
            !ftruncate(
                $processor->fresh_local_index_handle,
                $position["fresh_local_index_byte_offset"]
            )
            || fseek(
                $processor->fresh_local_index_handle,
                $position["fresh_local_index_byte_offset"]
            ) !== 0
        ) {
            fclose($processor->fresh_local_index_handle);
            $processor->fresh_local_index_handle = null;
            throw new RuntimeException(
                "Failed to truncate and seek the fresh local index to byte "
                . $position["fresh_local_index_byte_offset"]
                . "."
            );
        }
        $processor->file_index_processor = FileIndexProcessor::resume(
            [$filesystem_root],
            json_encode(
                $position["file_index_cursor"],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            false,
            $cursor["include_caches"],
            $storage_path
        );
        return $processor;
    }

    /**
     * Performs one filesystem traversal event or sorts the completed index.
     *
     * True means another step may be performed. False means the fresh local
     * index is complete.
     */
    public function next_step(): bool
    {
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            return false;
        }
        if ($this->closed) {
            throw new LogicException(
                "Cannot take a fresh local index step after close()."
            );
        }

        if ($position["phase"] === "sorting") {
            if (!sort_index_file($this->fresh_local_index_file)) {
                throw new RuntimeException(
                    "Failed to sort the fresh local index: "
                    . $this->fresh_local_index_file
                );
            }
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }

        if (!$this->file_index_processor->next_index_step()) {
            if (!fflush($this->fresh_local_index_handle)) {
                throw new RuntimeException(
                    "Failed to flush the fresh local index."
                );
            }
            $this->file_index_processor->close();
            $this->close_fresh_local_index_handle();
            $this->cursor["position"] = ["phase" => "sorting"];
            return true;
        }

        switch ($this->file_index_processor->get_step_status()) {
            case FileIndexProcessor::STATUS_INDEXED:
                foreach (
                    $this->file_index_processor->get_index_entries()
                    as $file_index_processor_entry
                ) {
                    if ($file_index_processor_entry["type"] === "other") {
                        throw new RuntimeException(
                            "Cannot index the unsupported local path: "
                            . base64_encode(
                                $file_index_processor_entry["path"]
                            )
                            . "."
                        );
                    }
                    if (
                        $file_index_processor_entry["type"] === "dir"
                        && !array_key_exists(
                            "empty",
                            $file_index_processor_entry
                        )
                    ) {
                        throw new RuntimeException(
                            "Could not inspect the local directory: "
                            . base64_encode(
                                $file_index_processor_entry["path"]
                            )
                            . "."
                        );
                    }

                    $local_relative_path = relative_path_under(
                        $file_index_processor_entry["path"],
                        $this->filesystem_root
                    );
                    if ($local_relative_path === null) {
                        throw new LogicException(
                            "File index path is outside the filesystem root."
                        );
                    }
                    $fresh_local_index_entry = [
                        "path" => $local_relative_path,
                        "ctime" => $file_index_processor_entry["ctime"],
                        "size" => $file_index_processor_entry["size"],
                        "type" => $file_index_processor_entry["type"],
                    ];
                    if ($file_index_processor_entry["type"] === "dir") {
                        $fresh_local_index_entry["empty"] =
                            $file_index_processor_entry["empty"];
                    }
                    write_local_index_entry(
                        $this->fresh_local_index_handle,
                        $fresh_local_index_entry
                    );
                }
                break;

            case FileIndexProcessor::STATUS_DIRECTORY_ERROR:
                $directory_error =
                    $this->file_index_processor->get_directory_error();
                throw new RuntimeException(
                    $directory_error["message"]
                    . ": "
                    . base64_encode($directory_error["path"])
                    . "."
                );

            case FileIndexProcessor::STATUS_SKIPPED:
            case FileIndexProcessor::STATUS_PATH_UNAVAILABLE:
            case FileIndexProcessor::STATUS_DIRECTORY_COMPLETE:
                break;
        }

        $fresh_local_index_byte_offset = ftell(
            $this->fresh_local_index_handle
        );
        if (!is_int($fresh_local_index_byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the fresh local index byte offset."
            );
        }
        $this->cursor["position"] = [
            "phase" => "indexing",
            "file_index_cursor" =>
                $this->file_index_processor->get_cursor(),
            "fresh_local_index_byte_offset" =>
                $fresh_local_index_byte_offset,
        ];
        return true;
    }

    /** @phpstan-return Cursor Current cursor after the latest completed step. */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns the current phase from the resumable cursor. */
    public function get_phase(): string
    {
        return $this->cursor["position"]["phase"];
    }

    /** Flushes fresh-index bytes before the caller stores get_cursor(). */
    public function flush_pending_output(): void
    {
        if (
            is_resource($this->fresh_local_index_handle)
            && !fflush($this->fresh_local_index_handle)
        ) {
            throw new RuntimeException("Failed to flush the fresh local index.");
        }
    }

    /** Closes retained handles. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (isset($this->file_index_processor)) {
            $this->file_index_processor->close();
        }
        $this->close_fresh_local_index_handle();
        $this->closed = true;
    }

    /** Returns the resolved real directory represented by the index. */
    private function resolve_filesystem_root(string $filesystem_root): string
    {
        clearstatcache(true, $filesystem_root);
        $resolved_filesystem_root = realpath($filesystem_root);
        if (
            $resolved_filesystem_root === false
            || !is_dir($resolved_filesystem_root)
            || is_link($filesystem_root)
        ) {
            throw new InvalidArgumentException(
                "FreshLocalIndexProcessor requires the filesystem root to be a real directory."
            );
        }
        return trim_right_slash($resolved_filesystem_root);
    }

    /** Decodes one arbitrary-byte path stored in the JSON cursor. */
    private static function decode_cursor_path(
        string $encoded_path,
        string $field_name
    ): string {
        $path = base64_decode($encoded_path, true);
        if ($path === false) {
            throw new InvalidArgumentException(
                "Fresh local index cursor contains an invalid base64 {$field_name}."
            );
        }
        return $path;
    }

    /** Closes the fresh local index retained during filesystem traversal. */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
    }
}
