<?php

use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_entry;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_relative_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\read_file_index_directory_is_empty;
use function WordPress\Reprint\Exporter\read_file_index_entry_from_stat;
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
 * Each next_step() call performs one filesystem traversal event, inspects one
 * explicitly selected default-skipped path, or sorts the completed index.
 * False means the index is complete and remains false.
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
 * The selected-path form is for a sorted, deduplicated JSONL file containing
 * `{"path_b64":"..."}` rows. Ordinary traversal still omits generated caches
 * and development files. After that traversal, the processor inspects only
 * the selected paths, without descending into their directories.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type SelectedPathsNone array{mode:'ordinary'}
 * @phpstan-type SelectedPathsFile array{mode:'selected_default_skipped_paths',file_b64:string}
 * @phpstan-type SelectedPathsConfig SelectedPathsNone|SelectedPathsFile
 * @phpstan-type IndexingPosition array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type SupplementingPosition array{phase:'supplementing',selected_paths_byte_offset:int,preceding_selected_path_b64:string|null,fresh_local_index_byte_offset:int}
 * @phpstan-type SortingPosition array{phase:'sorting'}
 * @phpstan-type CompletePosition array{phase:'complete'}
 * @phpstan-type Position IndexingPosition|SupplementingPosition|SortingPosition|CompletePosition
 * @phpstan-type Cursor array{fresh_local_index_file_b64:string,filesystem_root_b64:string,storage_path_b64:string,include_caches:bool,selected_paths:SelectedPathsConfig,position:Position}
 */
final class FreshLocalIndexProcessor
{
    private const MAX_SELECTED_PATH_ROW_BYTES = 64 * 1024;

    /** @var Cursor Current cursor returned to the caller. */
    private array $cursor;

    /** Fresh local index path decoded from the cursor. */
    private string $fresh_local_index_file;

    /** Filesystem root decoded from the cursor. */
    private string $filesystem_root;

    /** Canonical Reprint storage path omitted from the index, or an empty string. */
    private string $storage_path;

    /** Filesystem traversal retained during indexing. */
    private FileIndexProcessor $file_index_processor;

    /** @var resource|null Fresh local index retained during indexing and supplementation. */
    private $fresh_local_index_handle = null;

    /** @var resource|null Selected default-skipped paths retained during supplementation. */
    private $selected_paths_handle = null;

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
        return self::create(
            $fresh_local_index_file,
            $filesystem_root,
            $storage_path,
            $include_caches,
            ["mode" => "ordinary"]
        );
    }

    /**
     * Starts a caches-off scan followed by selected default-skipped paths.
     *
     * The selected-path file must remain immutable for this lifecycle. It is
     * sorted and deduplicated by decoded local-relative path bytes. Each row
     * has exactly one canonical-base64 `path_b64` field.
     *
     * @param string $fresh_local_index_file                    Output JSONL file.
     * @param string $filesystem_root                           Filesystem root represented by the index.
     * @param string $storage_path                              Reprint storage path omitted from the index.
     * @param string $selected_default_skipped_index_paths_file Immutable path-only JSONL file.
     */
    public static function start_with_selected_default_skipped_paths(
        string $fresh_local_index_file,
        string $filesystem_root,
        string $storage_path,
        string $selected_default_skipped_index_paths_file
    ): self {
        $selected_paths_handle = self::open_selected_paths_at_offset(
            $selected_default_skipped_index_paths_file,
            0
        );
        $selected_paths_stat = fstat($selected_paths_handle);
        $fresh_local_index_stat = @stat($fresh_local_index_file);
        if (
            $selected_paths_stat !== false
            && $fresh_local_index_stat !== false
            && $selected_paths_stat["dev"] === $fresh_local_index_stat["dev"]
            && $selected_paths_stat["ino"] === $fresh_local_index_stat["ino"]
        ) {
            fclose($selected_paths_handle);
            throw new InvalidArgumentException(
                "The selected-path input and fresh local index must be different files."
            );
        }
        fclose($selected_paths_handle);
        return self::create(
            $fresh_local_index_file,
            $filesystem_root,
            $storage_path,
            false,
            [
                "mode" => "selected_default_skipped_paths",
                "file_b64" => base64_encode(
                    $selected_default_skipped_index_paths_file
                ),
            ]
        );
    }

    /**
     * Starts a fresh local index with one explicit path-selection mode.
     *
     * @phpstan-param SelectedPathsConfig $selected_paths
     */
    private static function create(
        string $fresh_local_index_file,
        string $filesystem_root,
        string $storage_path,
        bool $include_caches,
        array $selected_paths
    ): self {
        $processor = new self();
        $filesystem_root = $processor->resolve_filesystem_root($filesystem_root);
        $processor->fresh_local_index_file = $fresh_local_index_file;
        $processor->filesystem_root = $filesystem_root;
        $processor->storage_path = self::canonical_storage_path($storage_path);
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
            "selected_paths" => $selected_paths,
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
     * During supplementation, the output is truncated to its saved boundary
     * and the immutable selected-path input seeks to its saved byte offset.
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
        $processor->storage_path = self::canonical_storage_path($storage_path);
        self::assert_selected_paths_config($cursor["selected_paths"]);
        if (
            $cursor["selected_paths"]["mode"]
                === "selected_default_skipped_paths"
            && $cursor["include_caches"]
        ) {
            throw new InvalidArgumentException(
                "Selected default-skipped paths require a caches-off local traversal."
            );
        }
        $position = $cursor["position"];
        if (
            $position["phase"] !== "indexing"
            && $position["phase"] !== "supplementing"
        ) {
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
        $processor->fresh_local_index_handle = self::open_output_at_offset(
            $processor->fresh_local_index_file,
            $position["fresh_local_index_byte_offset"]
        );
        if ($position["phase"] === "supplementing") {
            $processor->selected_paths_handle = self::open_selected_paths_at_offset(
                self::selected_paths_file($cursor["selected_paths"]),
                $position["selected_paths_byte_offset"]
            );
            return $processor;
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
     * Performs one traversal event, selected-path inspection, or index sort.
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

        if ($position["phase"] === "supplementing") {
            return $this->supplement_next_selected_path($position);
        }

        if (!$this->file_index_processor->next_index_step()) {
            if (!fflush($this->fresh_local_index_handle)) {
                throw new RuntimeException(
                    "Failed to flush the fresh local index."
                );
            }
            $this->file_index_processor->close();
            if (
                $this->cursor["selected_paths"]["mode"]
                    === "selected_default_skipped_paths"
            ) {
                $this->selected_paths_handle = self::open_selected_paths_at_offset(
                    self::selected_paths_file(
                        $this->cursor["selected_paths"]
                    ),
                    0
                );
                $this->cursor["position"] = [
                    "phase" => "supplementing",
                    "selected_paths_byte_offset" => 0,
                    "preceding_selected_path_b64" => null,
                    "fresh_local_index_byte_offset" =>
                        $this->fresh_local_index_byte_offset(),
                ];
            } else {
                $this->close_fresh_local_index_handle();
                $this->cursor["position"] = ["phase" => "sorting"];
            }
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

        $this->cursor["position"] = [
            "phase" => "indexing",
            "file_index_cursor" =>
                $this->file_index_processor->get_cursor(),
            "fresh_local_index_byte_offset" =>
                $this->fresh_local_index_byte_offset(),
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
        $this->close_selected_paths_handle();
        $this->close_fresh_local_index_handle();
        $this->closed = true;
    }

    /**
     * Reads and inspects at most one selected default-skipped path.
     *
     * @phpstan-param SupplementingPosition $position
     */
    private function supplement_next_selected_path(array $position): bool
    {
        $row = self::read_bounded_selected_path_row(
            $this->selected_paths_handle
        );
        if ($row === false) {
            if (!fflush($this->fresh_local_index_handle)) {
                throw new RuntimeException(
                    "Failed to flush the fresh local index."
                );
            }
            $this->close_selected_paths_handle();
            $this->close_fresh_local_index_handle();
            $this->cursor["position"] = ["phase" => "sorting"];
            return true;
        }

        $selected_path = self::decode_selected_path_row($row);
        $preceding_selected_path = $position[
            "preceding_selected_path_b64"
        ] === null
            ? null
            : self::decode_canonical_base64_path(
                $position["preceding_selected_path_b64"],
                "preceding selected path"
            );
        if (
            $preceding_selected_path !== null
            && strcmp($selected_path, $preceding_selected_path) <= 0
        ) {
            throw new UnexpectedValueException(
                "Selected default-skipped paths must be sorted and deduplicated; "
                . base64_encode($selected_path)
                . " follows "
                . base64_encode($preceding_selected_path)
                . "."
            );
        }
        $absolute_path = wp_join_unix_paths(
            $this->filesystem_root,
            $selected_path
        );
        if (
            !FileIndexProcessor::path_is_default_skipped_below_root(
                $this->filesystem_root,
                $absolute_path
            )
        ) {
            throw new UnexpectedValueException(
                "Selected path is not omitted by the default local traversal: "
                . base64_encode($selected_path)
                . "."
            );
        }

        if ($this->path_is_in_storage($absolute_path)) {
            return $this->settle_selected_path($selected_path);
        }
        $resolved_parent = $this->resolve_selected_path_parent(
            $selected_path
        );
        if ($resolved_parent === null) {
            return $this->settle_selected_path($selected_path);
        }
        $resolved_absolute_path = wp_join_unix_paths(
            $resolved_parent,
            basename($absolute_path)
        );
        if (!$this->path_is_in_storage($resolved_absolute_path)) {
            clearstatcache(true, $absolute_path);
            $path_stat = @lstat($absolute_path);
            if ($path_stat !== false) {
                $this->append_selected_path_entry(
                    $selected_path,
                    $absolute_path,
                    $path_stat
                );
            }
        }

        return $this->settle_selected_path($selected_path);
    }

    /**
     * Resolves a selected path's parent through bounded lexical prefixes.
     *
     * is_executable() checks directory search permission for the current PHP
     * user on POSIX. A false lstat() for the next component under a confirmed
     * searchable directory is treated as a missing tail. Existing prefixes
     * must resolve to searchable directories inside the filesystem root. This
     * walks at most the path's parent components and never scans a directory.
     *
     * @return string|null Resolved immediate parent, or null for a missing tail.
     */
    private function resolve_selected_path_parent(
        string $selected_path
    ): ?string {
        $lexical_prefix = $this->filesystem_root;
        $resolved_prefix = $this->filesystem_root;
        $parent_components = explode("/", $selected_path);
        array_pop($parent_components);

        foreach ($parent_components as $parent_component) {
            $this->assert_searchable_selected_path_parent_prefix(
                $resolved_prefix
            );
            $lexical_prefix = wp_join_unix_paths(
                $lexical_prefix,
                $parent_component
            );
            clearstatcache(true, $lexical_prefix);
            $prefix_stat = @lstat($lexical_prefix);
            if ($prefix_stat === false) {
                return null;
            }
            $prefix_entry = read_file_index_entry_from_stat(
                $lexical_prefix,
                $prefix_stat
            );
            if (
                $prefix_entry["type"] !== "dir"
                && $prefix_entry["type"] !== "link"
            ) {
                throw new RuntimeException(
                    "Selected path parent prefix is not a directory: "
                    . base64_encode($lexical_prefix)
                    . "."
                );
            }
            $next_resolved_prefix = realpath($lexical_prefix);
            if ($next_resolved_prefix === false) {
                throw new RuntimeException(
                    "Selected path parent prefix does not resolve to a directory: "
                    . base64_encode($lexical_prefix)
                    . "."
                );
            }
            if (
                !path_is_same_as_or_descendant_of(
                    $next_resolved_prefix,
                    $this->filesystem_root
                )
            ) {
                throw new RuntimeException(
                    "Selected path parent prefix resolves outside the filesystem root: "
                    . base64_encode($lexical_prefix)
                    . " resolved to "
                    . base64_encode($next_resolved_prefix)
                    . "."
                );
            }
            $resolved_prefix = $next_resolved_prefix;
        }

        $this->assert_searchable_selected_path_parent_prefix(
            $resolved_prefix
        );
        return $resolved_prefix;
    }

    /** Requires one resolved parent prefix to be a searchable directory. */
    private function assert_searchable_selected_path_parent_prefix(
        string $resolved_prefix
    ): void {
        clearstatcache(true, $resolved_prefix);
        if (!is_dir($resolved_prefix) || !is_executable($resolved_prefix)) {
            throw new RuntimeException(
                "Selected path parent prefix is not a searchable directory: "
                . base64_encode($resolved_prefix)
                . "."
            );
        }
    }

    /** Stores the input and output boundaries after one selected path. */
    private function settle_selected_path(string $selected_path): bool
    {
        $selected_paths_byte_offset = ftell($this->selected_paths_handle);
        if (!is_int($selected_paths_byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the selected-path byte offset."
            );
        }
        $this->cursor["position"] = [
            "phase" => "supplementing",
            "selected_paths_byte_offset" => $selected_paths_byte_offset,
            "preceding_selected_path_b64" => base64_encode($selected_path),
            "fresh_local_index_byte_offset" =>
                $this->fresh_local_index_byte_offset(),
        ];
        return true;
    }

    /**
     * Appends the actual state of one selected path without descending.
     *
     * @param array{mode:int,ctime?:int,size?:int} $path_stat One lstat() result.
     */
    private function append_selected_path_entry(
        string $selected_path,
        string $absolute_path,
        array $path_stat
    ): void {
        $entry = read_file_index_entry_from_stat($absolute_path, $path_stat);
        // A selected unsupported type stays in this temporary index so exact
        // planning can replace it. Ordinary traversal rejects it above.
        $local_entry = [
            "path" => $selected_path,
            "ctime" => $entry["ctime"],
            "size" => $entry["size"],
            "type" => $entry["type"],
        ];
        if ($entry["type"] === "dir") {
            $directory_is_empty = read_file_index_directory_is_empty(
                $absolute_path
            );
            if ($directory_is_empty === null) {
                throw new RuntimeException(
                    "Could not inspect the selected local directory: "
                    . base64_encode($selected_path)
                    . "."
                );
            }
            $local_entry["empty"] = $directory_is_empty;
        }
        write_local_index_entry(
            $this->fresh_local_index_handle,
            $local_entry
        );
    }

    /** Reports whether a physical or lexical path belongs to Reprint storage. */
    private function path_is_in_storage(string $path): bool
    {
        return $this->storage_path !== ""
            && path_is_same_as_or_descendant_of($path, $this->storage_path);
    }

    /** Returns the fresh-index byte offset after the latest completed write. */
    private function fresh_local_index_byte_offset(): int
    {
        $byte_offset = ftell($this->fresh_local_index_handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the fresh local index byte offset."
            );
        }
        return $byte_offset;
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

    /** Returns the same canonical storage path used by FileIndexProcessor. */
    private static function canonical_storage_path(string $storage_path): string
    {
        $storage_path = rtrim($storage_path, "/");
        if ($storage_path === "") {
            return "";
        }
        $canonical_storage_path = realpath($storage_path);
        return $canonical_storage_path !== false
            ? $canonical_storage_path
            : $storage_path;
    }

    /** Validates one cursor-selected path source. */
    private static function assert_selected_paths_config(array $config): void
    {
        if (
            $config === ["mode" => "ordinary"]
        ) {
            return;
        }
        if (
            array_keys($config) !== ["mode", "file_b64"]
            || $config["mode"] !== "selected_default_skipped_paths"
            || !is_string($config["file_b64"])
        ) {
            throw new InvalidArgumentException(
                "Fresh local index cursor has an invalid selected-path config."
            );
        }
        self::decode_canonical_base64_path(
            $config["file_b64"],
            "selected-path file"
        );
    }

    /** @phpstan-param SelectedPathsConfig $config */
    private static function selected_paths_file(array $config): string
    {
        if ($config["mode"] !== "selected_default_skipped_paths") {
            throw new LogicException(
                "Ordinary local indexing has no selected-path file."
            );
        }
        return self::decode_canonical_base64_path(
            $config["file_b64"],
            "selected-path file"
        );
    }

    /**
     * Reads one complete bounded selected-path row.
     *
     * @param resource $handle Open selected-path file.
     * @return string|false One row including LF, or false at EOF.
     */
    private static function read_bounded_selected_path_row($handle)
    {
        $byte_offset = ftell($handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the selected-path row offset."
            );
        }
        $row = fgets($handle, self::MAX_SELECTED_PATH_ROW_BYTES + 1);
        if ($row === false) {
            if (feof($handle)) {
                return false;
            }
            throw new RuntimeException(
                "Failed to read the selected-path row at byte offset {$byte_offset}."
            );
        }
        if (substr($row, -1) !== "\n") {
            $row_bytes = strlen($row);
            if (feof($handle)) {
                throw new UnexpectedValueException(
                    "The selected-path row at byte offset {$byte_offset} is unterminated after {$row_bytes} bytes."
                );
            }
            throw new UnexpectedValueException(
                "The selected-path row at byte offset {$byte_offset} exceeds the maximum of "
                . self::MAX_SELECTED_PATH_ROW_BYTES
                . " bytes; read {$row_bytes} bytes without LF."
            );
        }
        return $row;
    }

    /** Decodes one strict path-only JSONL row. */
    private static function decode_selected_path_row(string $row): string
    {
        $record = json_decode(substr($row, 0, -1), true);
        if (
            !is_array($record)
            || array_keys($record) !== ["path_b64"]
            || !is_string($record["path_b64"])
        ) {
            throw new UnexpectedValueException(
                "Selected default-skipped path file contains an invalid row."
            );
        }
        $path = self::decode_canonical_base64_path(
            $record["path_b64"],
            "selected path"
        );
        assert_valid_relative_path($path, "Selected default-skipped path");
        return $path;
    }

    /** Decodes one canonical-base64 path. */
    private static function decode_canonical_base64_path(
        string $encoded_path,
        string $field_name
    ): string {
        $path = base64_decode($encoded_path, true);
        if ($path === false || base64_encode($path) !== $encoded_path) {
            throw new InvalidArgumentException(
                "Fresh local index {$field_name} has invalid canonical base64."
            );
        }
        return $path;
    }

    /** @return resource Open selected-path input positioned at its cursor. */
    private static function open_selected_paths_at_offset(
        string $path,
        int $byte_offset
    ) {
        $handle = @fopen($path, "rb");
        $stat = is_resource($handle) ? fstat($handle) : false;
        if (
            $stat === false
            || ( $stat["mode"] & 0170000 ) !== 0100000
            || $byte_offset < 0
            || $byte_offset > $stat["size"]
            || fseek($handle, $byte_offset) !== 0
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException(
                "Failed to open the immutable selected-path file at byte {$byte_offset}: {$path}"
            );
        }
        return $handle;
    }

    /** @return resource Open fresh index truncated to its durable boundary. */
    private static function open_output_at_offset(
        string $path,
        int $byte_offset
    ) {
        $handle = @fopen($path, "r+b");
        $stat = is_resource($handle) ? fstat($handle) : false;
        if (
            $stat === false
            || $byte_offset < 0
            || $byte_offset > $stat["size"]
            || !ftruncate($handle, $byte_offset)
            || fseek($handle, $byte_offset) !== 0
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException(
                "Failed to resume the fresh local index at byte {$byte_offset}: {$path}"
            );
        }
        return $handle;
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

    /** Closes the selected-path input retained during supplementation. */
    private function close_selected_paths_handle(): void
    {
        if (is_resource($this->selected_paths_handle)) {
            fclose($this->selected_paths_handle);
        }
        $this->selected_paths_handle = null;
    }

    /** Closes the fresh local index retained during indexing or supplementation. */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
    }
}
