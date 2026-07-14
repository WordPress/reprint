<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal errors are CLI values, never HTML output.
/**
 * Per-remote-site memory of the last completed push: the local baseline.
 *
 * ctime is machine-local, so push never compares a local timestamp against
 * a remote one. Each machine is compared against its own past instead
 * (markdown/PUSH-SYNC.md, "Change detection"). This class stores that past
 * on the local machine, one directory per remote site:
 *
 *     <state-dir>/push/<site>/last-sync-local-files.jsonl
 *
 * The baseline is a copy of a file index in the same format as
 * .import-index.jsonl: one JSON object per line with a base64-encoded path
 * plus ctime, size, type, permission mode, and any symlink target, sorted by
 * decoded path. Non-empty directories use a private `tree-directory` marker:
 * it is never uploaded, but lets a later diff remove a vanished directory
 * after its last child goes. The push driver captures it at the end of a
 * successful push. A capture writes a temporary file and renames it into
 * place, so a killed process never leaves a truncated baseline for the next
 * push to trust — until the rename lands, the previous baseline stays in
 * effect.
 *
 * diff_local_files() answers "what changed on this machine since the last
 * push to this site". It streams the current index and the local baseline
 * together — both are sorted by path, so one pass suffices — and writes
 * two lists into the site directory:
 *
 *     local-paths-to-push.jsonl    paths new since the baseline, or whose
 *                                  ctime, size, type, or mode differs
 *     local-paths-to-delete.jsonl  paths in the baseline but gone from the
 *                                  current index
 *
 * The diff parses one JSON line at a time from each input. Ordering compares
 * decoded paths; two entries with the same path count as unchanged when their
 * decoded JSON objects match, so JSON field order or escaping changes do not
 * affect the diff. Changed-path output carries the current snapshot entry
 * (path, type, size, ctime, mode, and symlink target where relevant); private
 * tree-directory markers become directory-mode operations rather than leaking
 * into that list. Delete output carries just a base64 path. The sender still
 * lstat()s immediately before upload; these fields are the normalized logical
 * change, not source truth after the plan was written.
 *
 * With no baseline yet — the first push to a site — every uploadable current
 * entry counts as changed and no deletion can be detected.
 *
 * Producing the current index is the caller's job; this class only
 * compares and stores. The lists belong to the push which produced them and
 * remain its stable disk-backed plan when that active session resumes. A new
 * push replaces both lists from a new snapshot before creating its target
 * session.
 *
 * This journal is deliberately not a record of what the target accepted and
 * not the target's commit plan. MultipartPush keeps target-confirmed cursors in
 * its separate session checkpoint, while the target reconstructs accepted work
 * from its own `work/files/`, `work/partial/`, and delete storage. The baseline
 * advances only after target commit completes.
 *
 * Example:
 *
 *     $journal = new PushJournal('/srv/reprint-state', $target_url);
 *     $summary = $journal->diff_local_files($current_snapshot);
 *     // Stream the generated push/delete lists, then wait for target commit.
 *     // After target commit succeeds:
 *     $journal->capture_local_files_baseline($current_snapshot);
 */
class PushJournal
{
    /**
     * Target-specific directory containing the baseline and current plan files.
     *
     * The directory name is derived from site_key(), separating baselines by
     * normalized destination identity instead of source-tree location.
     *
     * @var string
     */
    private string $site_dir;

    /**
     * Copy of the local file index from the last completed target commit.
     *
     * @var string
     */
    public string $local_files_baseline_path;

    /**
     * JSONL current-snapshot entries which the active push must materialize.
     *
     * @var string
     */
    public string $local_paths_to_push;

    /**
     * JSONL base64 paths which existed at baseline and are absent or replaced.
     *
     * @var string
     */
    public string $local_paths_to_delete;

    /**
     * Selects the local state files for one normalized remote site identity.
     *
     * Construction derives paths only; directories are created lazily when a
     * baseline or diff is written.
     *
     * @param string $state_dir Local root shared by push state for all targets.
     * @param string $site_url Remote site URL used to isolate this baseline.
     */
    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->local_paths_to_push = $this->site_dir . "/local-paths-to-push.jsonl";
        $this->local_paths_to_delete = $this->site_dir . "/local-paths-to-delete.jsonl";
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
     *
     * @param string $site_url Absolute or host-relative remote site URL.
     * @return string Filesystem-safe, recognizable target key.
     *
     * @throws RuntimeException If the URL has no host component.
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
     * Stores a copy of the local file index as the new local baseline.
     *
     * The push driver calls this at the end of a successful push; from then
     * on "changed locally" means "different from this index". The copy is
     * atomic (temp file + rename) and the source file is left untouched.
     *
     * @param string $index_file Sorted JSONL snapshot whose target commit completed.
     *
     * @throws RuntimeException If the snapshot is missing or cannot be published.
     */
    public function capture_local_files_baseline(string $index_file): void
    {
        $this->replace_file($this->local_files_baseline_path, $index_file);
    }

    /**
     * Compares the current local index against the local baseline and writes
     * the local paths to push and local paths to delete, replacing any lists
     * from an earlier run.
     *
     * A single merge pass over the two path-sorted files: a path only in
     * the current index is new, a path in both whose lines differ has
     * changed (both go to local_paths_to_push), a path only in the baseline
     * was deleted (it goes to local_paths_to_delete). Unchanged paths produce
     * no output. Each list is written to a temporary file and renamed into
     * place, so a killed run never leaves a torn line behind.
     *
     * Memory stays constant however large the site is: the merge holds one
     * line from each input file and the lists go straight to disk, so an
     * index with a million entries costs the same as one with ten.
     *
     * @param string $current_index_file Path-sorted JSONL snapshot for this push.
     * @return array{changed: int, deleted: int} Entry counts, for the push summary.
     *
     * @throws RuntimeException If an index is invalid or plan files cannot be written.
     */
    public function diff_local_files(string $current_index_file): array
    {
        if (!is_file($current_index_file)) {
            throw new RuntimeException("Cannot diff, the current index file is missing: {$current_index_file}");
        }
        $this->ensure_site_dir();

        $current_handle = fopen($current_index_file, "r");
        if (!$current_handle) {
            throw new RuntimeException("Failed to open the current index: {$current_index_file}");
        }
        $baseline_handle = null;
        if (is_file($this->local_files_baseline_path)) {
            $baseline_handle = fopen($this->local_files_baseline_path, "r");
            if (!$baseline_handle) {
                fclose($current_handle);
                throw new RuntimeException("Failed to open the local baseline: {$this->local_files_baseline_path}");
            }
        }

        $local_paths_to_push_tmp = $this->local_paths_to_push . ".tmp";
        $paths_to_push_handle = fopen($local_paths_to_push_tmp, "w");
        if (!$paths_to_push_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            throw new RuntimeException("Failed to open local_paths_to_push for writing: {$local_paths_to_push_tmp}");
        }
        $local_paths_to_delete_tmp = $this->local_paths_to_delete . ".tmp";
        $paths_to_delete_handle = fopen($local_paths_to_delete_tmp, "w");
        if (!$paths_to_delete_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            fclose($paths_to_push_handle);
            throw new RuntimeException("Failed to open local_paths_to_delete for writing: {$local_paths_to_delete_tmp}");
        }

        $changed = 0;
        $deleted = 0;
        $pending_deleted_tree_roots = [];
        $pending_positive_replacement_roots = [];
        $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
        $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
        while ($current_entry !== null || $baseline_entry !== null) {
            // base64 does not preserve byte order ('0' sorts before 'A' in
            // ASCII but encodes a higher value), so ordering has to use the
            // decoded paths.
            if ($baseline_entry === null) {
                $order = -1;
            } elseif ($current_entry === null) {
                $order = 1;
            } else {
                $order = strcmp($current_path, $baseline_path);
            }

            if ($order < 0) {
                // Only in the current index: new since the last push.
                $is_tree_directory = $this->is_tree_directory($current_entry);
                $changed_entry = $is_tree_directory
                    ? $this->directory_mode_change($current_entry)
                    : $current_entry;
                $out = json_encode($changed_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                }
                $changed++;
                if (!$is_tree_directory) {
                    $this->remember_pending_root($current_path, $pending_positive_replacement_roots);
                }
                $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                if (!$this->is_below_pending_root($baseline_path, $pending_deleted_tree_roots)
                    && !$this->is_below_pending_root($baseline_path, $pending_positive_replacement_roots)) {
                    $out = json_encode(["path" => $baseline_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($paths_to_delete_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on local_paths_to_delete, is the disk full?");
                    }
                    $deleted++;
                    if ($this->is_tree_directory($baseline_entry)) {
                        $this->remember_pending_root($baseline_path, $pending_deleted_tree_roots);
                    }
                }
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
            } else {
                // Same path on both sides. Decoded JSON array comparison keeps
                // field order and slash escaping out of change detection. A
                // writer field change would mark everything changed once (a
                // wasted re-upload, never a missed one).
                if ($this->is_tree_directory($current_entry) && $this->is_tree_directory($baseline_entry)) {
                    // A non-empty directory still exists. Its ctime changes
                    // with child entries; only a mode change is upload work.
                    if (($current_entry['mode'] ?? null) !== ($baseline_entry['mode'] ?? null)) {
                        $changed_entry = $this->directory_mode_change($current_entry);
                        $out = json_encode($changed_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                        if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                            throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                        }
                        ++$changed;
                    }
                } elseif ($current_entry != $baseline_entry && $this->is_tree_directory($current_entry)) {
                    // A file or link becoming a non-empty directory needs a
                    // delete of the old root; staged children construct the
                    // replacement directory tree.
                    if (!$this->is_tree_directory($baseline_entry) && ($baseline_entry['type'] ?? null) !== 'directory') {
                        $out = json_encode(["path" => $baseline_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                        if (fwrite($paths_to_delete_handle, $out) !== strlen($out)) {
                            throw new RuntimeException("Short write on local_paths_to_delete, is the disk full?");
                        }
                        $deleted++;
                        $this->remember_pending_root($baseline_path, $pending_deleted_tree_roots);
                    }
                    if (
                        ($baseline_entry['type'] ?? null) !== 'directory'
                        || ($current_entry['mode'] ?? null) !== ($baseline_entry['mode'] ?? null)
                    ) {
                        $changed_entry = $this->directory_mode_change($current_entry);
                        $out = json_encode($changed_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                        if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                            throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                        }
                        ++$changed;
                    }
                } elseif ($current_entry != $baseline_entry) {
                    $out = json_encode($current_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                    }
                    $changed++;
                    $this->remember_pending_root($current_path, $pending_positive_replacement_roots);
                }
                $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
            }
        }

        fclose($current_handle);
        if ($baseline_handle) {
            fclose($baseline_handle);
        }
        if (!fclose($paths_to_push_handle) || !rename($local_paths_to_push_tmp, $this->local_paths_to_push)) {
            throw new RuntimeException("Failed to move local_paths_to_push into place: {$this->local_paths_to_push}");
        }
        if (!fclose($paths_to_delete_handle) || !rename($local_paths_to_delete_tmp, $this->local_paths_to_delete)) {
            throw new RuntimeException("Failed to move local_paths_to_delete into place: {$this->local_paths_to_delete}");
        }

        return ["changed" => $changed, "deleted" => $deleted];
    }

    /**
     * Indicates whether an index entry is a private non-empty directory marker.
     *
     * @param array<string,mixed> $entry Decoded snapshot record.
     * @return bool True for `tree-directory`, which is diff metadata, not upload work.
     */
    private function is_tree_directory(array $entry): bool
    {
        return ($entry['type'] ?? null) === 'tree-directory';
    }

    /** Converts a private snapshot marker into one uploadable mode operation. */
    private function directory_mode_change(array $entry): array
    {
        $mode = $entry['mode'] ?? null;
        if (!is_int($mode) || $mode < 0 || $mode > 07777) {
            throw new RuntimeException('A non-empty directory snapshot has no valid permission mode.');
        }
        $entry['type'] = 'directory-mode';
        return $entry;
    }

    /**
     * Indicates whether a path is already covered by a prior replacement root.
     *
     * Sorted input makes roots appear before descendants. Suppressing covered
     * descendants keeps delete plans minimal and avoids contradictory work when
     * a complete ancestor is already deleted or positively replaced.
     *
     * Byte sorting can put a sibling such as `a-other` before `a/child`, so one
     * active root is insufficient. The pending roots form a stack ordered by
     * their not-yet-reached `root/` intervals. A new pending interval can sit
     * before the prior one only when its path extends that prior root, which
     * bounds stack depth by path length rather than the number of changed paths.
     *
     * @param string $path Decoded path being classified.
     * @param string[] $pending_roots Not-yet-passed replacement roots.
     * @return bool True when $path is a strict descendant of a root.
     */
    private function is_below_pending_root(string $path, array &$pending_roots): bool
    {
        while ($pending_roots !== []) {
            $root = $pending_roots[count($pending_roots) - 1];
            $descendant_prefix = $root . '/';
            if (strpos($path, $descendant_prefix) === 0) {
                return true;
            }
            if (strcmp($path, $descendant_prefix) <= 0) {
                return false;
            }
            array_pop($pending_roots);
        }
        return false;
    }

    /** Adds one root after discarding intervals already passed by sorted input. */
    private function remember_pending_root(string $path, array &$pending_roots): void
    {
        if ($this->is_below_pending_root($path, $pending_roots)) {
            return;
        }
        $pending_roots[] = $path;
    }

    /**
     * Reads the next index line and parses its JSON object.
     *
     * All three out-parameters become null at end of file: $entry is the
     * decoded index object, $path the decoded path (for ordering),
     * $base64_path the encoded path (reused in output lines).
     *
     * @param resource|null $handle
     * @param array<string, mixed>|null $entry
     * @param string|null $path Decoded path used for bytewise ordering.
     * @param string|null $base64_path Original encoded path reused in output.
     */
    private function read_line($handle, ?array &$entry, ?string &$path, ?string &$base64_path): void
    {
        $entry = null;
        $path = null;
        $base64_path = null;
        if (!$handle) {
            return;
        }
        $raw_line = fgets($handle);
        if ($raw_line === false) {
            return;
        }

        try {
            $decoded_entry = json_decode($raw_line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unexpected index line, it is not valid JSON: " . substr($raw_line, 0, 120), 0, $exception);
        }
        if (!is_array($decoded_entry) || !array_key_exists("path", $decoded_entry) || !is_string($decoded_entry["path"])) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $decoded_path = base64_decode($decoded_entry["path"], true);
        if ($decoded_path === false || $decoded_path === "") {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $entry = $decoded_entry;
        $path = $decoded_path;
        $base64_path = $decoded_entry["path"];
    }

    /**
     * Copies an index file over a baseline through an adjacent temporary file.
     *
     * The final rename means readers see either the prior completed baseline or
     * the new complete snapshot, never a partially copied index.
     *
     * @param string $target Final baseline path.
     * @param string $source_index_file Completed snapshot to copy.
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

    /**
     * Creates the target-specific journal directory before a write.
     *
     * Construction stays side-effect free; capture and diff call this only when
     * they have output to publish.
     */
    private function ensure_site_dir(): void
    {
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0755, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException("Failed to create the push journal directory: {$this->site_dir}");
        }
    }
}
