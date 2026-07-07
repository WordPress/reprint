<?php
/**
 * Per-remote-site memory of the last completed push: the baselines.
 *
 * ctime is machine-local, so push never compares a local timestamp against
 * a remote one. Each machine is compared against its own past instead
 * (markdown/PUSH-SYNC.md, "Change detection"). This class stores that past
 * on the local machine, one directory per remote site:
 *
 *     <state-dir>/push/<site>/last-sync-local-files.jsonl
 *     <state-dir>/push/<site>/last-sync-remote-files.jsonl
 *
 * Each baseline is a copy of a file index in the same format as
 * .import-index.jsonl: one JSON object per line with a base64-encoded path
 * plus ctime, size, and type, sorted by decoded path. The push driver
 * captures both at the end of a successful push. A capture writes a
 * temporary file and renames it into place, so a killed process never
 * leaves a truncated baseline for the next push to trust — until the
 * rename lands, the previous baseline stays in effect.
 *
 * diff_local_files() answers "what changed on this machine since the last
 * push to this site". It streams the current index and the local baseline
 * together — both are sorted by path, so one pass suffices — and writes
 * two lists into the site directory:
 *
 *     local-paths-to-push.jsonl    paths new since the baseline, or whose
 *                                  ctime, size, or type differs
 *     local-paths-to-delete.jsonl  paths in the baseline but gone from the
 *                                  current index
 *
 * The diff parses one JSON line at a time from each input. Ordering compares
 * decoded paths; two entries with the same path count as unchanged when their
 * decoded JSON objects match, so JSON field order or escaping changes do not
 * affect the diff. Output lines carry only the base64 path, producing the
 * .import-download-list.jsonl shape: one {"path": <base64>} object per line.
 * The lists carry no sizes or types on purpose — the files are local, so the
 * upload step reads the filesystem when it stages them instead of trusting a
 * snapshot that may already be stale.
 *
 * With no baseline yet — the first push to a site — every current entry
 * counts as changed and no deletion can be detected.
 *
 * diff_remote_files() answers "did the remote change any path this push is
 * about to touch". Its current index input must be the scoped remote reindex
 * for local_paths_to_push plus local_paths_to_delete. The remote baseline is
 * filtered to that same scope before comparison, so unrelated remote changes
 * do not block a push.
 *
 * Producing the current index is the caller's job; this class only
 * compares and stores. The lists belong to the run that produced them:
 * a resumed push reruns the diff (one cheap local pass) rather than
 * trusting lists from an earlier run.
 */
class PushJournal
{
    private string $site_dir;

    /** @var string Copy of the local file index from the last completed push. */
    public string $local_files_baseline_path;

    /** @var string Copy of the remote file index from the last completed push. */
    public string $remote_files_baseline_path;

    /** @var string JSONL file of local paths to push, written by diff_local_files(). */
    public string $local_paths_to_push;

    /** @var string JSONL file of local paths whose deletion should be pushed, written by diff_local_files(). */
    public string $local_paths_to_delete;

    /** @var string JSONL file of scoped remote paths changed since the remote baseline. */
    public string $remote_paths_changed_since_last_sync;

    /** @var string JSONL file of scoped remote paths deleted since the remote baseline. */
    public string $remote_paths_deleted_since_last_sync;

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->remote_files_baseline_path = $this->site_dir . "/last-sync-remote-files.jsonl";
        $this->local_paths_to_push = $this->site_dir . "/local-paths-to-push.jsonl";
        $this->local_paths_to_delete = $this->site_dir . "/local-paths-to-delete.jsonl";
        $this->remote_paths_changed_since_last_sync = $this->site_dir . "/remote-paths-changed-since-last-sync.jsonl";
        $this->remote_paths_deleted_since_last_sync = $this->site_dir . "/remote-paths-deleted-since-last-sync.jsonl";
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
     * Store a copy of the local file index as the new local baseline.
     *
     * The push driver calls this at the end of a successful push; from then
     * on "changed locally" means "different from this index". The copy is
     * atomic (temp file + rename) and the source file is left untouched.
     */
    public function capture_local_files_baseline(string $index_file): void
    {
        $this->replace_file($this->local_files_baseline_path, $index_file);
    }

    /**
     * Store a copy of the remote file index as the new remote baseline.
     *
     * Captured from the scoped reindex that runs after apply — apply itself
     * changes remote ctimes, so without this refresh the next push would
     * report everything it just wrote as remote drift.
     */
    public function capture_remote_files_baseline(string $index_file): void
    {
        $this->replace_file($this->remote_files_baseline_path, $index_file);
    }

    /**
     * Compare the current local index against the local baseline and write
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
     * @return array{changed: int, deleted: int} Entry counts, for the push summary.
     */
    public function diff_local_files(string $current_index_file): array
    {
        if (!is_file($current_index_file)) {
            throw new RuntimeException("Cannot diff, the current index file is missing: {$current_index_file}");
        }
        $this->ensure_site_dir();

        return $this->diff_index_files(
            $current_index_file,
            is_file($this->local_files_baseline_path) ? $this->local_files_baseline_path : null,
            $this->local_paths_to_push,
            $this->local_paths_to_delete,
            true
        );
    }

    /**
     * Compare a scoped current remote index against the remote baseline and
     * write the remote paths that changed or disappeared since the last sync.
     *
     * The scoped current remote index must contain exactly the paths from the
     * local push/delete lists that still exist on the remote. The method
     * filters the stored remote baseline to those same local paths before the
     * merge, so a remote edit outside the pushed scope cannot become a false
     * conflict.
     *
     * If there is no remote baseline yet, this is the first push to the site:
     * drift cannot be detected, stale drift lists are replaced with empty
     * files, and baseline_missing is true for the summary UI.
     *
     * @return array{changed: int, deleted: int, baseline_missing: bool} Entry counts, for the push summary.
     */
    public function diff_remote_files(string $current_remote_index_file): array
    {
        if (!is_file($current_remote_index_file)) {
            throw new RuntimeException("Cannot diff, the current remote index file is missing: {$current_remote_index_file}");
        }
        $this->ensure_site_dir();

        if (!is_file($this->local_paths_to_push)) {
            throw new RuntimeException("Cannot diff remote drift, local_paths_to_push is missing: {$this->local_paths_to_push}");
        }
        if (!is_file($this->local_paths_to_delete)) {
            throw new RuntimeException("Cannot diff remote drift, local_paths_to_delete is missing: {$this->local_paths_to_delete}");
        }

        if (!is_file($this->remote_files_baseline_path)) {
            $this->write_empty_jsonl($this->remote_paths_changed_since_last_sync);
            $this->write_empty_jsonl($this->remote_paths_deleted_since_last_sync);
            return ["changed" => 0, "deleted" => 0, "baseline_missing" => true];
        }

        $scoped_remote_baseline = $this->site_dir . "/remote-baseline-for-local-paths.jsonl.tmp";
        $this->write_scoped_remote_baseline($scoped_remote_baseline);
        try {
            $counts = $this->diff_index_files(
                $current_remote_index_file,
                $scoped_remote_baseline,
                $this->remote_paths_changed_since_last_sync,
                $this->remote_paths_deleted_since_last_sync,
                false
            );
        } finally {
            if (is_file($scoped_remote_baseline)) {
                unlink($scoped_remote_baseline);
            }
        }

        return [
            "changed" => $counts["changed"],
            "deleted" => $counts["deleted"],
            "baseline_missing" => false,
        ];
    }

    /**
     * Compare two sorted index files and write {"path": <base64>} lists for
     * entries that changed or disappeared.
     *
     * @return array{changed: int, deleted: int}
     */
    private function diff_index_files(
        string $current_index_file,
        ?string $baseline_index_file,
        string $changed_paths_file,
        string $deleted_paths_file,
        bool $missing_baseline_marks_current_changed
    ): array {
        $current_handle = fopen($current_index_file, "r");
        if (!$current_handle) {
            throw new RuntimeException("Failed to open the current index: {$current_index_file}");
        }
        $baseline_handle = null;
        if ($baseline_index_file !== null && is_file($baseline_index_file)) {
            $baseline_handle = fopen($baseline_index_file, "r");
            if (!$baseline_handle) {
                fclose($current_handle);
                throw new RuntimeException("Failed to open the baseline index: {$baseline_index_file}");
            }
        }

        $changed_paths_tmp = $changed_paths_file . ".tmp";
        $changed_paths_handle = fopen($changed_paths_tmp, "w");
        if (!$changed_paths_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            throw new RuntimeException("Failed to open changed paths list for writing: {$changed_paths_tmp}");
        }
        $deleted_paths_tmp = $deleted_paths_file . ".tmp";
        $deleted_paths_handle = fopen($deleted_paths_tmp, "w");
        if (!$deleted_paths_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            fclose($changed_paths_handle);
            throw new RuntimeException("Failed to open deleted paths list for writing: {$deleted_paths_tmp}");
        }

        $changed = 0;
        $deleted = 0;
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
                if ($baseline_handle || $missing_baseline_marks_current_changed) {
                    $out = json_encode(["path" => $current_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($changed_paths_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on changed paths list, is the disk full?");
                    }
                    $changed++;
                }
                $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                $out = json_encode(["path" => $baseline_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($deleted_paths_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on deleted paths list, is the disk full?");
                }
                $deleted++;
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
            } else {
                // Same path on both sides. Decoded JSON array comparison keeps
                // field order and slash escaping out of change detection. A
                // writer field change would mark everything changed once (a
                // wasted re-upload, never a missed one).
                if ($current_entry != $baseline_entry) {
                    $out = json_encode(["path" => $current_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($changed_paths_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on changed paths list, is the disk full?");
                    }
                    $changed++;
                }
                $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
            }
        }

        fclose($current_handle);
        if ($baseline_handle) {
            fclose($baseline_handle);
        }
        if (!fclose($changed_paths_handle) || !rename($changed_paths_tmp, $changed_paths_file)) {
            throw new RuntimeException("Failed to move changed paths list into place: {$changed_paths_file}");
        }
        if (!fclose($deleted_paths_handle) || !rename($deleted_paths_tmp, $deleted_paths_file)) {
            throw new RuntimeException("Failed to move deleted paths list into place: {$deleted_paths_file}");
        }

        return ["changed" => $changed, "deleted" => $deleted];
    }

    /**
     * Write the remote baseline entries for paths this push will touch.
     *
     * local_paths_to_push and local_paths_to_delete are already sorted by
     * decoded path because diff_local_files() writes them during a merge over
     * the sorted local index. The remote baseline is sorted the same way. This
     * merge keeps memory constant and ignores remote baseline entries outside
     * the local push scope.
     */
    private function write_scoped_remote_baseline(string $target): void
    {
        $baseline_handle = fopen($this->remote_files_baseline_path, "r");
        if (!$baseline_handle) {
            throw new RuntimeException("Failed to open the remote baseline: {$this->remote_files_baseline_path}");
        }
        $paths_to_push_handle = fopen($this->local_paths_to_push, "r");
        if (!$paths_to_push_handle) {
            fclose($baseline_handle);
            throw new RuntimeException("Failed to open local_paths_to_push: {$this->local_paths_to_push}");
        }
        $paths_to_delete_handle = fopen($this->local_paths_to_delete, "r");
        if (!$paths_to_delete_handle) {
            fclose($baseline_handle);
            fclose($paths_to_push_handle);
            throw new RuntimeException("Failed to open local_paths_to_delete: {$this->local_paths_to_delete}");
        }
        $target_handle = fopen($target, "w");
        if (!$target_handle) {
            fclose($baseline_handle);
            fclose($paths_to_push_handle);
            fclose($paths_to_delete_handle);
            throw new RuntimeException("Failed to open scoped remote baseline for writing: {$target}");
        }

        $last_scope_path = null;
        $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
        $this->read_line($paths_to_push_handle, $path_to_push_entry, $path_to_push, $path_to_push_base64);
        $this->read_line($paths_to_delete_handle, $path_to_delete_entry, $path_to_delete, $path_to_delete_base64);

        while ($baseline_entry !== null && ($path_to_push_entry !== null || $path_to_delete_entry !== null)) {
            if ($path_to_push_entry !== null && ($path_to_delete_entry === null || strcmp($path_to_push, $path_to_delete) <= 0)) {
                $scope_path = $path_to_push;
            } else {
                $scope_path = $path_to_delete;
            }

            if ($scope_path === $last_scope_path) {
                if ($path_to_push === $scope_path) {
                    $this->read_line($paths_to_push_handle, $path_to_push_entry, $path_to_push, $path_to_push_base64);
                }
                if ($path_to_delete === $scope_path) {
                    $this->read_line($paths_to_delete_handle, $path_to_delete_entry, $path_to_delete, $path_to_delete_base64);
                }
                continue;
            }

            $order = strcmp($baseline_path, $scope_path);
            if ($order < 0) {
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
                continue;
            }
            if ($order > 0) {
                $last_scope_path = $scope_path;
                if ($path_to_push === $scope_path) {
                    $this->read_line($paths_to_push_handle, $path_to_push_entry, $path_to_push, $path_to_push_base64);
                }
                if ($path_to_delete === $scope_path) {
                    $this->read_line($paths_to_delete_handle, $path_to_delete_entry, $path_to_delete, $path_to_delete_base64);
                }
                continue;
            }

            $line = json_encode($baseline_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($target_handle, $line) !== strlen($line)) {
                throw new RuntimeException("Short write on scoped remote baseline, is the disk full?");
            }
            $last_scope_path = $scope_path;
            $this->read_line($baseline_handle, $baseline_entry, $baseline_path, $baseline_base64_path);
            if ($path_to_push === $scope_path) {
                $this->read_line($paths_to_push_handle, $path_to_push_entry, $path_to_push, $path_to_push_base64);
            }
            if ($path_to_delete === $scope_path) {
                $this->read_line($paths_to_delete_handle, $path_to_delete_entry, $path_to_delete, $path_to_delete_base64);
            }
        }

        fclose($baseline_handle);
        fclose($paths_to_push_handle);
        fclose($paths_to_delete_handle);
        if (!fclose($target_handle)) {
            throw new RuntimeException("Failed to close scoped remote baseline: {$target}");
        }
    }

    /**
     * Atomically replace a JSONL list with an empty file.
     */
    private function write_empty_jsonl(string $target): void
    {
        $tmp = $target . ".tmp";
        $handle = fopen($tmp, "w");
        if (!$handle) {
            throw new RuntimeException("Failed to open empty JSONL temp file for writing: {$tmp}");
        }
        if (!fclose($handle) || !rename($tmp, $target)) {
            throw new RuntimeException("Failed to move empty JSONL file into place: {$target}");
        }
    }

    /**
     * Read the next index line and parse its JSON object.
     *
     * All three out-parameters become null at end of file: $entry is the
     * decoded index object, $path the decoded path (for ordering),
     * $base64_path the encoded path (reused in output lines).
     *
     * @param resource|null $handle
     * @param array<string, mixed>|null $entry
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
