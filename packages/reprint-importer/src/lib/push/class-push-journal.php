<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.
/**
 * Per-remote-site memory of the last completed push and local push planning.
 *
 * ctime is machine-local, so push never compares a local timestamp against
 * a remote one. Each machine is compared against its own past instead
 * (markdown/PUSH-SYNC.md, "Change detection"). This class stores that past
 * on the local machine, one directory per remote site:
 *
 *     <state-dir>/push/<site>/last-sync-local-files.jsonl
 *
 * The baseline is the sender index from the last completed push, captured only
 * after the receiver commits successfully. The source index records an `empty`
 * boolean on every directory entry, so both inputs distinguish an empty
 * directory from a non-empty one and planning never reads the source tree.
 *
 * diff_local_files() performs one bounded planning step. It merges the
 * path-sorted current index and baseline while writing three durable files:
 *
 *     sender-index.jsonl          every current source-index entry
 *     local-paths-to-push.jsonl   files, symlinks, and empty directories to
 *                                 inspect and upload
 *     work-deletes                raw NUL-delimited roots to delete
 *
 * The caller persists the returned checkpoint. On the next call, bytes beyond
 * its three committed output lengths are truncated before planning resumes at
 * the two saved input offsets. A process that dies before saving a checkpoint
 * therefore replays only uncommitted work, without duplicate output records.
 *
 * With no baseline, every current file, symlink, and empty directory is
 * selected and no deletion can be detected. The positive-work list carries
 * only paths because the sender rechecks the filesystem before upload.
 * Planning holds one line from each input and active delete roots bounded by
 * path nesting; indexes and plans are never accumulated in memory.
 *
 * @phpstan-type CurrentIndexIdentity array{device:int,inode:int,size:int,ctime:int,mtime:int}
 * @phpstan-type PlanningCheckpoint array{current_index_byte_offset:int,baseline_byte_offset:int,sender_index_bytes:int,local_paths_to_push_bytes:int,work_deletes_bytes:int,changed:int,deleted:int,active_work_delete_roots_b64:list<string>,current_index_identity:CurrentIndexIdentity}
 */
class PushJournal
{
    private const PLANNING_RECORDS_PER_STEP = 1000;

    private string $site_dir;

    /** @var string Sender index from the last completed push. */
    public string $local_files_baseline_path;

    /** @var string JSONL file of local paths to push. */
    public string $local_paths_to_push;

    /** @var string Raw NUL-delimited work deletes. */
    public string $work_deletes_path;

    /** @var string Current source index selected for the active push. */
    public string $sender_index_path;

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->local_paths_to_push = $this->site_dir . "/local-paths-to-push.jsonl";
        $this->work_deletes_path = $this->site_dir . "/work-deletes";
        $this->sender_index_path = $this->site_dir . "/sender-index.jsonl";
    }

    /**
     * Directory name for a remote site URL: a readable slug plus a short
     * hash. "https://Example.com/blog/" becomes "example.com-blog-<hash>".
     *
     * Host, port, and path identify the site; scheme, credentials, query,
     * and fragment do not (http and https reach the same files). The slug
     * keeps the directory recognizable when someone lists <state-dir>/push;
     * the hash tells apart URLs whose slugs collide, like a site on port
     * 8080 next to one on 8081.
     */
    public static function site_key(string $site_url): string
    {
        $site_url = trim($site_url);
        $parts = parse_url($site_url);
        if ((!is_array($parts) || empty($parts["host"])) && strpos($site_url, "//") === false) {
            // A bare "example.com/blog" parses as all-path; retry it as a
            // host-relative URL.
            $parts = parse_url("//" . $site_url);
        }
        if (!is_array($parts) || empty($parts["host"]) || !is_string($parts["host"])) {
            throw new RuntimeException("Cannot derive a push site key, the URL has no host: {$site_url}");
        }
        $host = strtolower($parts["host"]);
        $port = isset($parts["port"]) ? ":" . $parts["port"] : "";
        $path = isset($parts["path"]) && is_string($parts["path"]) ? rtrim($parts["path"], "/") : "";
        $normalized = $host . $port . $path;

        $slug = trim((string) preg_replace("/[^a-z0-9.]+/", "-", strtolower($normalized)), "-.");
        // Directory names have length limits; the hash carries identity,
        // so a long slug can be cut without risking collisions.
        $slug = substr($slug, 0, 60);
        $hash = substr(sha1($normalized), 0, 8);

        return $slug === "" ? $hash : "{$slug}-{$hash}";
    }

    /**
     * Store the pushed sender index as the new local baseline.
     *
     * The push driver calls this at the end of a successful push; from then
     * on "changed locally" means "different from this index". The copy is
     * atomic (temp file + rename) and the source file is left untouched. A
     * killed process therefore leaves the previous complete baseline in
     * effect rather than publishing a truncated replacement.
     */
    public function capture_local_files_baseline(string $index_file): void
    {
        $this->replace_file($this->local_files_baseline_path, $index_file);
    }

    /**
     * Perform one bounded local planning step.
     *
     * A null checkpoint starts a new plan and replaces any older output. A
     * returned `planning` checkpoint resumes the same plan. `complete` means
     * all three output files are immutable and ready for the sender.
     * `source_changed` returns the input checkpoint after rolling output back
     * to its committed lengths; the caller must regenerate the current index
     * and start a new plan.
     *
     * Every current entry is copied to sender-index.jsonl, including paths
     * which conflict with an excluded path. Exclusions suppress network work,
     * not the complete source snapshot used by later baseline publication.
     *
     * @param string $current_index_file Path-sorted current source index.
     * @param string[] $excluded_paths Receiver-owned document-root-relative paths.
     * @param array|null $checkpoint {
     *     Last durable planning boundary, or null to begin.
     *
     *     @type int      $current_index_byte_offset  Next unconsumed current-index byte.
     *     @type int      $baseline_byte_offset       Next unconsumed baseline byte.
     *     @type int      $sender_index_bytes         Committed sender-index length.
     *     @type int      $local_paths_to_push_bytes  Committed positive-work list length.
     *     @type int      $work_deletes_bytes         Committed raw work-delete length.
     *     @type int      $changed                    Planned positive-work path count.
     *     @type int      $deleted                    Planned work-delete root count.
     *     @type string[] $active_work_delete_roots_b64 Base64 roots whose descendant ranges remain active.
     *     @type array    $current_index_identity     Device, inode, size, ctime, and mtime captured at start.
     * }
     * @return array {
     *     Result of this planning step.
     *
     *     @type string $status     `planning`, `complete`, or `source_changed`.
     *     @type array  $checkpoint The new durable boundary, or the input boundary on source change.
     * }
     * @phpstan-param list<string> $excluded_paths
     * @phpstan-param PlanningCheckpoint|null $checkpoint
     * @phpstan-return array{status:'planning'|'complete'|'source_changed',checkpoint:PlanningCheckpoint}
     */
    public function diff_local_files(
        string $current_index_file,
        array $excluded_paths = [],
        ?array $checkpoint = null
    ): array {
        $starting_new_plan = $checkpoint === null;
        $current_index_identity = $this->current_index_identity($current_index_file);
        if ($starting_new_plan) {
            if ($current_index_identity === null) {
                throw new RuntimeException("Cannot plan local files, the current index file is missing: {$current_index_file}");
            }
            $checkpoint = [
                "current_index_byte_offset" => 0,
                "baseline_byte_offset" => 0,
                "sender_index_bytes" => 0,
                "local_paths_to_push_bytes" => 0,
                "work_deletes_bytes" => 0,
                "changed" => 0,
                "deleted" => 0,
                "active_work_delete_roots_b64" => [],
                "current_index_identity" => $current_index_identity,
            ];
        }

        $this->ensure_site_dir();
        $current_handle = null;
        $baseline_handle = null;
        $sender_index_handle = null;
        $local_paths_to_push_handle = null;
        $work_deletes_handle = null;

        try {
            $sender_index_handle = $this->open_planning_output(
                $this->sender_index_path,
                $checkpoint["sender_index_bytes"]
            );
            $local_paths_to_push_handle = $this->open_planning_output(
                $this->local_paths_to_push,
                $checkpoint["local_paths_to_push_bytes"]
            );
            $work_deletes_handle = $this->open_planning_output(
                $this->work_deletes_path,
                $checkpoint["work_deletes_bytes"]
            );

            if ($current_index_identity !== $checkpoint["current_index_identity"]) {
                return $this->source_changed_result(
                    $checkpoint,
                    $sender_index_handle,
                    $local_paths_to_push_handle,
                    $work_deletes_handle
                );
            }

            $current_handle = fopen($current_index_file, "rb");
            if (!$current_handle) {
                throw new RuntimeException("Failed to open the current index: {$current_index_file}");
            }
            $opened_current_index_identity = $this->handle_identity($current_handle);
            if ($opened_current_index_identity !== $checkpoint["current_index_identity"]) {
                return $this->source_changed_result(
                    $checkpoint,
                    $sender_index_handle,
                    $local_paths_to_push_handle,
                    $work_deletes_handle
                );
            }

            // Publish an empty, identity-bearing boundary before reading the
            // first record. If the process dies during its first real batch,
            // the next process cannot silently adopt a replacement index.
            if ($starting_new_plan) {
                $this->flush_planning_outputs(
                    $sender_index_handle,
                    $local_paths_to_push_handle,
                    $work_deletes_handle
                );
                return ["status" => "planning", "checkpoint" => $checkpoint];
            }

            if (is_file($this->local_files_baseline_path)) {
                $baseline_handle = fopen($this->local_files_baseline_path, "rb");
                if (!$baseline_handle) {
                    throw new RuntimeException("Failed to open the local baseline: {$this->local_files_baseline_path}");
                }
            }

            $this->seek_input(
                $current_handle,
                $checkpoint["current_index_byte_offset"],
                "current index"
            );
            if ($baseline_handle) {
                $this->seek_input(
                    $baseline_handle,
                    $checkpoint["baseline_byte_offset"],
                    "local baseline"
                );
            }

            $current_index_byte_offset = $checkpoint["current_index_byte_offset"];
            $baseline_byte_offset = $checkpoint["baseline_byte_offset"];
            $changed = $checkpoint["changed"];
            $deleted = $checkpoint["deleted"];
            $active_work_delete_roots = [];
            foreach ($checkpoint["active_work_delete_roots_b64"] as $encoded_root) {
                $root = base64_decode($encoded_root, true);
                if ($root === false || $root === "") {
                    throw new RuntimeException("Planning checkpoint contains an invalid active work-delete root.");
                }
                $active_work_delete_roots[] = $root;
            }

            $this->read_line(
                $current_handle,
                $current_entry,
                $current_path,
                $current_base64_path,
                $next_current_index_byte_offset
            );
            $this->read_line(
                $baseline_handle,
                $baseline_entry,
                $baseline_path,
                $baseline_base64_path,
                $next_baseline_byte_offset
            );

            $records_processed = 0;
            while ($current_entry !== null || $baseline_entry !== null) {
                // Base64 does not preserve byte order ('0' sorts before 'A'
                // in ASCII but encodes a higher value), so ordering uses the
                // decoded path bytes.
                if ($baseline_entry === null) {
                    $order = -1;
                } elseif ($current_entry === null) {
                    $order = 1;
                } else {
                    $order = strcmp($current_path, $baseline_path);
                }

                $records_for_path = $order === 0 ? 2 : 1;
                if ($records_processed + $records_for_path > self::PLANNING_RECORDS_PER_STEP) {
                    break;
                }

                $current_shape = null;
                if ($order <= 0) {
                    $current_shape = $this->entry_shape($current_entry, "current index");
                    $sender_index_line = json_encode(
                        $current_entry,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ) . "\n";
                    if (fwrite($sender_index_handle, $sender_index_line) !== strlen($sender_index_line)) {
                        throw new RuntimeException(
                            "Short write on sender index {$this->sender_index_path}, is the disk full?"
                        );
                    }
                    unset($sender_index_line);
                }

                $baseline_shape = null;
                if ($order >= 0) {
                    $baseline_shape = $this->entry_shape($baseline_entry, "local baseline");
                }

                if ($order < 0) {
                    // New files, symlinks, and empty directories need positive
                    // work. A new non-empty directory is represented by its
                    // descendants.
                    if (
                        $current_shape !== "non_empty_directory"
                        && !$this->path_conflicts_with_excluded_paths($current_path, $excluded_paths)
                    ) {
                        $this->write_list_path(
                            $local_paths_to_push_handle,
                            $current_base64_path,
                            $this->local_paths_to_push
                        );
                        ++$changed;
                    }
                } elseif ($order > 0) {
                    // A deleted non-empty directory emits one root. Its later
                    // descendant entries are already covered by that record.
                    if (
                        !$this->path_conflicts_with_excluded_paths($baseline_path, $excluded_paths)
                        && !$this->is_covered_by_active_work_delete_root(
                            $baseline_path,
                            $active_work_delete_roots
                        )
                    ) {
                        $this->write_work_delete($work_deletes_handle, $baseline_path);
                        ++$deleted;
                        if ($baseline_shape === "non_empty_directory") {
                            $this->remember_active_work_delete_root(
                                $baseline_path,
                                $active_work_delete_roots
                            );
                        }
                    }
                } else {
                    $current_is_file_or_symlink = $current_shape === "file" || $current_shape === "symlink";
                    $baseline_is_file_or_symlink = $baseline_shape === "file" || $baseline_shape === "symlink";
                    $non_empty_directory_becomes_empty = $current_shape === "empty_directory"
                        && $baseline_shape === "non_empty_directory";
                    $empty_directory_needs_install = $current_shape === "empty_directory"
                        && $baseline_shape !== "empty_directory";
                    $changed_file_or_symlink_needs_install = $current_is_file_or_symlink
                        && $current_entry != $baseline_entry;
                    $needs_delete = $current_is_file_or_symlink !== $baseline_is_file_or_symlink
                        || $non_empty_directory_becomes_empty;
                    $needs_push = $empty_directory_needs_install
                        || $changed_file_or_symlink_needs_install;
                    $path_is_excluded = $this->path_conflicts_with_excluded_paths(
                        $current_path,
                        $excluded_paths
                    );

                    if (
                        $needs_delete
                        && !$path_is_excluded
                        && !$this->is_covered_by_active_work_delete_root(
                            $baseline_path,
                            $active_work_delete_roots
                        )
                    ) {
                        $this->write_work_delete($work_deletes_handle, $baseline_path);
                        ++$deleted;
                        $this->remember_active_work_delete_root(
                            $baseline_path,
                            $active_work_delete_roots
                        );
                    }
                    if ($needs_push && !$path_is_excluded) {
                        // Comparing decoded JSON objects keeps field order and
                        // slash escaping out of file and symlink detection. A
                        // writer field change may select every value once: a
                        // wasted upload, but never a missed local change.
                        $this->write_list_path(
                            $local_paths_to_push_handle,
                            $current_base64_path,
                            $this->local_paths_to_push
                        );
                        ++$changed;
                    }
                }

                if ($order <= 0) {
                    $current_index_byte_offset = $next_current_index_byte_offset;
                    $this->read_line(
                        $current_handle,
                        $current_entry,
                        $current_path,
                        $current_base64_path,
                        $next_current_index_byte_offset
                    );
                }
                if ($order >= 0) {
                    $baseline_byte_offset = $next_baseline_byte_offset;
                    $this->read_line(
                        $baseline_handle,
                        $baseline_entry,
                        $baseline_path,
                        $baseline_base64_path,
                        $next_baseline_byte_offset
                    );
                }
                $records_processed += $records_for_path;
            }

            if (
                $this->current_index_identity($current_index_file) !== $checkpoint["current_index_identity"]
                || $this->handle_identity($current_handle) !== $checkpoint["current_index_identity"]
            ) {
                return $this->source_changed_result(
                    $checkpoint,
                    $sender_index_handle,
                    $local_paths_to_push_handle,
                    $work_deletes_handle
                );
            }

            $this->flush_planning_outputs(
                $sender_index_handle,
                $local_paths_to_push_handle,
                $work_deletes_handle
            );
            $complete = $current_entry === null && $baseline_entry === null;
            if ($complete) {
                $active_work_delete_roots = [];
            }
            $next_checkpoint = [
                "current_index_byte_offset" => $current_index_byte_offset,
                "baseline_byte_offset" => $baseline_byte_offset,
                "sender_index_bytes" => $this->handle_byte_offset($sender_index_handle, $this->sender_index_path),
                "local_paths_to_push_bytes" => $this->handle_byte_offset(
                    $local_paths_to_push_handle,
                    $this->local_paths_to_push
                ),
                "work_deletes_bytes" => $this->handle_byte_offset($work_deletes_handle, $this->work_deletes_path),
                "changed" => $changed,
                "deleted" => $deleted,
                "active_work_delete_roots_b64" => array_map("base64_encode", $active_work_delete_roots),
                "current_index_identity" => $checkpoint["current_index_identity"],
            ];

            return [
                "status" => $complete ? "complete" : "planning",
                "checkpoint" => $next_checkpoint,
            ];
        } finally {
            foreach (
                [
                    $current_handle,
                    $baseline_handle,
                    $sender_index_handle,
                    $local_paths_to_push_handle,
                    $work_deletes_handle,
                ] as $handle
            ) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * Open one output at its last committed byte and discard a later tail.
     *
     * @return resource
     */
    private function open_planning_output(string $path, int $committed_bytes)
    {
        $handle = fopen($path, "c+b");
        if (!$handle) {
            throw new RuntimeException("Failed to open planning output for writing: {$path}");
        }
        $identity = fstat($handle);
        $actual_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($actual_bytes < $committed_bytes) {
            fclose($handle);
            throw new RuntimeException(
                "Planning output {$path} contains {$actual_bytes} bytes, shorter than the checkpointed {$committed_bytes} bytes."
            );
        }
        if (!ftruncate($handle, $committed_bytes) || fseek($handle, $committed_bytes) !== 0) {
            fclose($handle);
            throw new RuntimeException("Failed to restore planning output {$path} to {$committed_bytes} bytes.");
        }
        return $handle;
    }

    /** @param resource $handle */
    private function seek_input($handle, int $byte_offset, string $description): void
    {
        $identity = fstat($handle);
        $input_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($byte_offset > $input_bytes) {
            throw new RuntimeException(
                "The {$description} checkpoint offset {$byte_offset} exceeds its {$input_bytes}-byte file."
            );
        }
        if (fseek($handle, $byte_offset) !== 0) {
            throw new RuntimeException("Failed to seek the {$description} to byte {$byte_offset}.");
        }
    }

    /**
     * Return the plain logical kind needed by the transition table.
     *
     * @param array<string, mixed> $entry Parsed index entry.
     * @return 'file'|'symlink'|'empty_directory'|'non_empty_directory'
     */
    private function entry_shape(array $entry, string $index_description): string
    {
        $type = $entry["type"] ?? null;
        if ($type === "file") {
            return "file";
        }
        if ($type === "link") {
            return "symlink";
        }
        if ($type !== "dir") {
            throw new RuntimeException(
                "Unexpected {$index_description} entry type: " . json_encode($type)
            );
        }
        if (!array_key_exists("empty", $entry) || !is_bool($entry["empty"])) {
            throw new RuntimeException(
                "Directory entry in the {$index_description} has no boolean empty field: "
                . json_encode($entry, JSON_UNESCAPED_SLASHES)
            );
        }
        return $entry["empty"] ? "empty_directory" : "non_empty_directory";
    }

    /** @param resource $handle */
    private function write_list_path($handle, string $base64_path, string $output_path): void
    {
        $line = json_encode(["path" => $base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException("Short write on local push path list {$output_path}, is the disk full?");
        }
    }

    /** @param resource $handle */
    private function write_work_delete($handle, string $path): void
    {
        $record = $path . "\0";
        if (fwrite($handle, $record) !== strlen($record)) {
            throw new RuntimeException("Short write on work deletes {$this->work_deletes_path}, is the disk full?");
        }
    }

    /**
     * Remember an emitted work-delete root after discarding intervals passed
     * by the sorted baseline.
     *
     * @param string[] $active_work_delete_roots Roots whose descendant ranges remain active.
     */
    private function remember_active_work_delete_root(string $path, array &$active_work_delete_roots): void
    {
        if ($this->is_covered_by_active_work_delete_root($path, $active_work_delete_roots)) {
            return;
        }
        $active_work_delete_roots[] = $path;
    }

    /**
     * Report whether a prior work-delete root already covers this path.
     *
     * Byte sorting can put a sibling such as `a-other` before `a/child`, so
     * one active root is insufficient. Active intervals form a stack whose
     * depth is bounded by path nesting rather than the number of index rows.
     *
     * @param string[] $active_work_delete_roots Roots whose descendant ranges remain active.
     */
    private function is_covered_by_active_work_delete_root(
        string $path,
        array &$active_work_delete_roots
    ): bool {
        while ($active_work_delete_roots !== []) {
            $root = $active_work_delete_roots[count($active_work_delete_roots) - 1];
            $descendant_prefix = $root . "/";
            if (strpos($path, $descendant_prefix) === 0) {
                return true;
            }
            if (strcmp($path, $descendant_prefix) <= 0) {
                return false;
            }
            array_pop($active_work_delete_roots);
        }
        return false;
    }

    /**
     * Report whether changing a path could change an excluded path.
     *
     * An exact path, its descendant, and its ancestor all conflict: replacing
     * an ancestor directory could otherwise remove the excluded value.
     *
     * @param string[] $excluded_paths Raw receiver-owned paths.
     */
    private function path_conflicts_with_excluded_paths(string $path, array $excluded_paths): bool
    {
        foreach ($excluded_paths as $excluded_path) {
            if (
                $path === $excluded_path
                || strpos($path, $excluded_path . "/") === 0
                || strpos($excluded_path, $path . "/") === 0
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read and parse the next index line.
     *
     * All out-parameters become null at EOF. The next byte offset belongs to
     * the first byte after the parsed line and becomes durable only when the
     * caller consumes that entry.
     *
     * @param resource|null $handle
     * @param array<string, mixed>|null $entry
     */
    private function read_line(
        $handle,
        ?array &$entry,
        ?string &$path,
        ?string &$base64_path,
        ?int &$next_byte_offset
    ): void {
        $entry = null;
        $path = null;
        $base64_path = null;
        $next_byte_offset = null;
        if (!$handle) {
            return;
        }
        $raw_line = fgets($handle);
        if ($raw_line === false) {
            if (!feof($handle)) {
                throw new RuntimeException("Failed to read a local push index line.");
            }
            return;
        }

        try {
            $decoded_entry = json_decode($raw_line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Unexpected index line, it is not valid JSON: " . substr($raw_line, 0, 120),
                0,
                $exception
            );
        }
        if (!is_array($decoded_entry) || !array_key_exists("path", $decoded_entry) || !is_string($decoded_entry["path"])) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $decoded_path = base64_decode($decoded_entry["path"], true);
        if ($decoded_path === false || $decoded_path === "" || strpos($decoded_path, "\0") !== false) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $offset = ftell($handle);
        if (!is_int($offset)) {
            throw new RuntimeException("Failed to determine the next local push index byte offset.");
        }
        $entry = $decoded_entry;
        $path = $decoded_path;
        $base64_path = $decoded_entry["path"];
        $next_byte_offset = $offset;
    }

    /**
     * Return the current index identity used across every planning step.
     *
     * @return CurrentIndexIdentity|null
     */
    private function current_index_identity(string $path): ?array
    {
        clearstatcache(true, $path);
        $identity = @stat($path);
        return is_array($identity) ? $this->normalize_identity($identity) : null;
    }

    /**
     * Return the identity of an already-open current index handle.
     *
     * @param resource $handle
     * @return CurrentIndexIdentity|null
     */
    private function handle_identity($handle): ?array
    {
        $identity = fstat($handle);
        return is_array($identity) ? $this->normalize_identity($identity) : null;
    }

    /**
     * Normalize PHP stat keys into the persisted current-index identity.
     *
     * @param array<string|int, mixed> $identity
     * @return CurrentIndexIdentity
     */
    private function normalize_identity(array $identity): array
    {
        return [
            "device" => (int) $identity["dev"],
            "inode" => (int) $identity["ino"],
            "size" => (int) $identity["size"],
            "ctime" => (int) $identity["ctime"],
            "mtime" => (int) $identity["mtime"],
        ];
    }

    /**
     * Roll uncommitted tails back and report that planning evidence changed.
     *
     * @param PlanningCheckpoint $checkpoint
     * @param resource $sender_index_handle
     * @param resource $local_paths_to_push_handle
     * @param resource $work_deletes_handle
     * @return array{status:'source_changed',checkpoint:PlanningCheckpoint}
     */
    private function source_changed_result(
        array $checkpoint,
        $sender_index_handle,
        $local_paths_to_push_handle,
        $work_deletes_handle
    ): array {
        $outputs = [
            [$sender_index_handle, $checkpoint["sender_index_bytes"], $this->sender_index_path],
            [$local_paths_to_push_handle, $checkpoint["local_paths_to_push_bytes"], $this->local_paths_to_push],
            [$work_deletes_handle, $checkpoint["work_deletes_bytes"], $this->work_deletes_path],
        ];
        foreach ($outputs as [$handle, $committed_bytes, $path]) {
            if (!ftruncate($handle, $committed_bytes) || !fflush($handle)) {
                throw new RuntimeException("Failed to roll planning output {$path} back to {$committed_bytes} bytes.");
            }
        }
        return ["status" => "source_changed", "checkpoint" => $checkpoint];
    }

    /**
     * Flush all output bytes before their lengths become checkpointable.
     *
     * @param resource $sender_index_handle
     * @param resource $local_paths_to_push_handle
     * @param resource $work_deletes_handle
     */
    private function flush_planning_outputs(
        $sender_index_handle,
        $local_paths_to_push_handle,
        $work_deletes_handle
    ): void {
        if (
            !fflush($sender_index_handle)
            || !fflush($local_paths_to_push_handle)
            || !fflush($work_deletes_handle)
        ) {
            throw new RuntimeException("Failed to flush local push planning output.");
        }
    }

    /** @param resource $handle */
    private function handle_byte_offset($handle, string $path): int
    {
        $offset = ftell($handle);
        if (!is_int($offset)) {
            throw new RuntimeException("Failed to determine the committed planning length for {$path}.");
        }
        return $offset;
    }

    /**
     * Copy an index file over a baseline: temp file in the same directory,
     * then rename, so readers only ever see the old or the new baseline.
     */
    private function replace_file(string $target, string $source_index_file): void
    {
        if (!is_file($source_index_file)) {
            throw new RuntimeException("Cannot capture a baseline, the index file is missing: {$source_index_file}");
        }
        $this->ensure_site_dir();
        $tmp = $target . ".tmp";
        if (!copy($source_index_file, $tmp)) {
            throw new RuntimeException("Failed to copy the index into a baseline temp file: {$tmp}");
        }
        if (!rename($tmp, $target)) {
            throw new RuntimeException("Failed to move the baseline into place: {$target}");
        }
    }

    private function ensure_site_dir(): void
    {
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0755, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException("Failed to create the push journal directory: {$this->site_dir}");
        }
    }
}
