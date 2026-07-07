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

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->remote_files_baseline_path = $this->site_dir . "/last-sync-remote-files.jsonl";
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
                $out = json_encode(["path" => $current_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                }
                $changed++;
                $this->read_line($current_handle, $current_entry, $current_path, $current_base64_path);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                $out = json_encode(["path" => $baseline_base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($paths_to_delete_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on local_paths_to_delete, is the disk full?");
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
                    if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
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
        if (!fclose($paths_to_push_handle) || !rename($local_paths_to_push_tmp, $this->local_paths_to_push)) {
            throw new RuntimeException("Failed to move local_paths_to_push into place: {$this->local_paths_to_push}");
        }
        if (!fclose($paths_to_delete_handle) || !rename($local_paths_to_delete_tmp, $this->local_paths_to_delete)) {
            throw new RuntimeException("Failed to move local_paths_to_delete into place: {$this->local_paths_to_delete}");
        }

        return ["changed" => $changed, "deleted" => $deleted];
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
