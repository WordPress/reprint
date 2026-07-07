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
 *     upload-list.jsonl     paths new since the baseline, or whose ctime,
 *                           size, or type differs
 *     deletion-list.jsonl   paths in the baseline but gone from the index
 *
 * The diff works on raw lines and never touches a JSON function. Index
 * lines always start with {"path":"<base64>" — every index writer in
 * import.php puts the path field first — so the path comes out of the
 * line by position. Ordering compares decoded paths; two entries with the
 * same path count as unchanged only when their lines are byte-identical,
 * which holds because both files come from the same writer. Output lines
 * reuse the base64 substring as-is, producing the .import-download-list.jsonl
 * shape: one {"path": <base64>} object per line. The lists carry no sizes
 * or types on purpose — the files are local, so the upload step reads the
 * filesystem when it stages them instead of trusting a snapshot that may
 * already be stale.
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

    public string $local_files_baseline_path;
    public string $remote_files_baseline_path;
    public string $upload_list_path;
    public string $deletion_list_path;

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->remote_files_baseline_path = $this->site_dir . "/last-sync-remote-files.jsonl";
        $this->upload_list_path = $this->site_dir . "/upload-list.jsonl";
        $this->deletion_list_path = $this->site_dir . "/deletion-list.jsonl";
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

    public function capture_local_files_baseline(string $index_file): void
    {
        $this->replace_file($this->local_files_baseline_path, $index_file);
    }

    public function capture_remote_files_baseline(string $index_file): void
    {
        $this->replace_file($this->remote_files_baseline_path, $index_file);
    }

    /**
     * Compare the current local index against the local baseline and write
     * upload-list.jsonl and deletion-list.jsonl, replacing any lists from
     * an earlier run.
     *
     * A single merge pass over the two path-sorted files: a path only in
     * the current index is new, a path in both whose lines differ has
     * changed (both go to the upload list), a path only in the baseline
     * was deleted. Unchanged paths produce no output. Each list is written
     * to a temporary file and renamed into place, so a killed run never
     * leaves a torn line behind.
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

        $upload_tmp = $this->upload_list_path . ".tmp";
        $upload_handle = fopen($upload_tmp, "w");
        if (!$upload_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            throw new RuntimeException("Failed to open the upload list for writing: {$upload_tmp}");
        }
        $deletion_tmp = $this->deletion_list_path . ".tmp";
        $deletion_handle = fopen($deletion_tmp, "w");
        if (!$deletion_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            fclose($upload_handle);
            throw new RuntimeException("Failed to open the deletion list for writing: {$deletion_tmp}");
        }

        $changed = 0;
        $deleted = 0;
        $this->read_line($current_handle, $cur_line, $cur_path, $cur_b64);
        $this->read_line($baseline_handle, $base_line, $base_path, $base_b64);
        while ($cur_line !== null || $base_line !== null) {
            // base64 does not preserve byte order ('0' sorts before 'A' in
            // ASCII but encodes a higher value), so ordering has to use the
            // decoded paths.
            if ($base_line === null) {
                $order = -1;
            } elseif ($cur_line === null) {
                $order = 1;
            } else {
                $order = strcmp($cur_path, $base_path);
            }

            if ($order < 0) {
                // Only in the current index: new since the last push.
                $out = '{"path":"' . $cur_b64 . "\"}\n";
                if (fwrite($upload_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on the upload list, is the disk full?");
                }
                $changed++;
                $this->read_line($current_handle, $cur_line, $cur_path, $cur_b64);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                $out = '{"path":"' . $base_b64 . "\"}\n";
                if (fwrite($deletion_handle, $out) !== strlen($out)) {
                    throw new RuntimeException("Short write on the deletion list, is the disk full?");
                }
                $deleted++;
                $this->read_line($baseline_handle, $base_line, $base_path, $base_b64);
            } else {
                // Same path on both sides. Same writer, same fields — so a
                // changed ctime, size, or type is exactly a changed line.
                // A writer format change would mark everything changed once
                // (a wasted re-upload, never a missed one).
                if ($cur_line !== $base_line) {
                    $out = '{"path":"' . $cur_b64 . "\"}\n";
                    if (fwrite($upload_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on the upload list, is the disk full?");
                    }
                    $changed++;
                }
                $this->read_line($current_handle, $cur_line, $cur_path, $cur_b64);
                $this->read_line($baseline_handle, $base_line, $base_path, $base_b64);
            }
        }

        fclose($current_handle);
        if ($baseline_handle) {
            fclose($baseline_handle);
        }
        if (!fclose($upload_handle) || !rename($upload_tmp, $this->upload_list_path)) {
            throw new RuntimeException("Failed to move the upload list into place: {$this->upload_list_path}");
        }
        if (!fclose($deletion_handle) || !rename($deletion_tmp, $this->deletion_list_path)) {
            throw new RuntimeException("Failed to move the deletion list into place: {$this->deletion_list_path}");
        }

        return ["changed" => $changed, "deleted" => $deleted];
    }

    /**
     * Read the next index line and pull the path out of it by position,
     * without decoding the JSON.
     *
     * Index lines start with {"path":"<base64>" — every index writer in
     * import.php emits the path field first, and base64 never contains a
     * quote or a backslash, so the first quote after the prefix ends the
     * encoded path. Anything else is not an index file and stops the diff.
     *
     * All three out-parameters become null at end of file: $line is the
     * raw line, $path the decoded path (for ordering), $b64 the encoded
     * path (reused verbatim in output lines).
     *
     * @param resource|null $handle
     */
    private function read_line($handle, ?string &$line, ?string &$path, ?string &$b64): void
    {
        $line = null;
        $path = null;
        $b64 = null;
        if (!$handle) {
            return;
        }
        $raw = fgets($handle);
        if ($raw === false) {
            return;
        }
        if (strncmp($raw, '{"path":"', 9) !== 0) {
            throw new RuntimeException('Unexpected index line, it does not start with {"path":" — ' . substr($raw, 0, 120));
        }
        $quote = strpos($raw, '"', 9);
        $encoded = $quote === false ? "" : substr($raw, 9, $quote - 9);
        $decoded = $encoded === "" ? false : base64_decode($encoded, true);
        if ($decoded === false || $decoded === "") {
            throw new RuntimeException("Invalid index path in line: " . substr($raw, 0, 120));
        }
        $line = $raw;
        $path = $decoded;
        $b64 = $encoded;
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
