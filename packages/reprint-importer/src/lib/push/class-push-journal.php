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
 * push to this site". It walks the current index and the local baseline
 * together — both are sorted by path, so one pass suffices, the same merge
 * diff_indexes_and_build_fetch_list() in import.php runs against a remote
 * index — and writes two lists into the site directory:
 *
 *     upload-list.jsonl     paths new since the baseline, or whose ctime,
 *                           size, or type differs
 *     deletion-list.jsonl   paths in the baseline but gone from the index
 *
 * Both lists hold one {"path": <base64>} object per line, the same shape
 * as .import-download-list.jsonl. They carry no sizes or types on purpose:
 * the files are local, so the upload step reads the filesystem when it
 * stages them instead of trusting a snapshot that may already be stale.
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

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
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

    public function site_dir(): string
    {
        return $this->site_dir;
    }

    public function local_files_baseline_path(): string
    {
        return $this->site_dir . "/last-sync-local-files.jsonl";
    }

    public function remote_files_baseline_path(): string
    {
        return $this->site_dir . "/last-sync-remote-files.jsonl";
    }

    public function has_local_files_baseline(): bool
    {
        return is_file($this->local_files_baseline_path());
    }

    public function has_remote_files_baseline(): bool
    {
        return is_file($this->remote_files_baseline_path());
    }

    public function capture_local_files_baseline(string $index_file): void
    {
        $this->replace_file($this->local_files_baseline_path(), $index_file);
    }

    public function capture_remote_files_baseline(string $index_file): void
    {
        $this->replace_file($this->remote_files_baseline_path(), $index_file);
    }

    public function upload_list_path(): string
    {
        return $this->site_dir . "/upload-list.jsonl";
    }

    public function deletion_list_path(): string
    {
        return $this->site_dir . "/deletion-list.jsonl";
    }

    /**
     * Compare the current local index against the local baseline and write
     * upload-list.jsonl and deletion-list.jsonl, replacing any lists from
     * an earlier run.
     *
     * Both inputs are sorted by decoded path, so this is a single merge
     * pass: a path only in the current index is new, a path in both with
     * a different ctime, size, or type has changed (both go to the upload
     * list), a path only in the baseline was deleted. Unchanged paths
     * produce no output. Each list is written to a temporary file and
     * renamed into place, so a killed run never leaves a torn line behind.
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
        if ($this->has_local_files_baseline()) {
            $baseline_handle = fopen($this->local_files_baseline_path(), "r");
            if (!$baseline_handle) {
                fclose($current_handle);
                throw new RuntimeException("Failed to open the local baseline: {$this->local_files_baseline_path()}");
            }
        }

        $upload_tmp = $this->upload_list_path() . ".tmp";
        $upload_handle = fopen($upload_tmp, "w");
        if (!$upload_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            throw new RuntimeException("Failed to open the upload list for writing: {$upload_tmp}");
        }
        $deletion_tmp = $this->deletion_list_path() . ".tmp";
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
        $current = $this->read_index_line($current_handle);
        $baseline = $this->read_index_line($baseline_handle);
        while ($current !== null || $baseline !== null) {
            if ($baseline === null) {
                $order = -1;
            } elseif ($current === null) {
                $order = 1;
            } else {
                $order = strcmp($current["path"], $baseline["path"]);
            }

            if ($order < 0) {
                // Only in the current index: new since the last push.
                $this->append_path_line($upload_handle, $current["path"]);
                $changed++;
                $current = $this->read_index_line($current_handle);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                $this->append_path_line($deletion_handle, $baseline["path"]);
                $deleted++;
                $baseline = $this->read_index_line($baseline_handle);
            } else {
                if (
                    $current["ctime"] !== $baseline["ctime"] ||
                    $current["size"] !== $baseline["size"] ||
                    $current["type"] !== $baseline["type"]
                ) {
                    $this->append_path_line($upload_handle, $current["path"]);
                    $changed++;
                }
                $current = $this->read_index_line($current_handle);
                $baseline = $this->read_index_line($baseline_handle);
            }
        }

        fclose($current_handle);
        if ($baseline_handle) {
            fclose($baseline_handle);
        }
        if (!fclose($upload_handle) || !rename($upload_tmp, $this->upload_list_path())) {
            throw new RuntimeException("Failed to move the upload list into place: {$this->upload_list_path()}");
        }
        if (!fclose($deletion_handle) || !rename($deletion_tmp, $this->deletion_list_path())) {
            throw new RuntimeException("Failed to move the deletion list into place: {$this->deletion_list_path()}");
        }

        return ["changed" => $changed, "deleted" => $deleted];
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

    /**
     * Read the next entry from a sorted index file, skipping blank lines.
     *
     * Mirrors read_index_line()/parse_index_line() in import.php, minus
     * their path validation: the baselines are this class's own files, and
     * everything that later acts on these paths — the upload step, the
     * remote staging store — validates them again at that boundary.
     *
     * @param resource|null $handle
     * @return array{path: string, ctime: int, size: int, type: string}|null
     */
    private function read_index_line($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                throw new RuntimeException("Invalid index line: " . substr($line, 0, 120));
            }
            $path_encoded = $data["path"] ?? "";
            $path = is_string($path_encoded) ? base64_decode($path_encoded, true) : false;
            if ($path === false || $path === "") {
                throw new RuntimeException("Invalid index path in line: " . substr($line, 0, 120));
            }
            return [
                "path" => $path,
                "ctime" => (int) ($data["ctime"] ?? 0),
                "size" => (int) ($data["size"] ?? 0),
                "type" => (string) ($data["type"] ?? "file"),
            ];
        }
        return null;
    }

    /**
     * Write one {"path": <base64>} line — the .import-download-list.jsonl
     * shape. The byte-count check is there because a silently short write
     * (disk full) would drop a path from a list that drives real uploads
     * and deletions.
     *
     * @param resource $handle
     */
    private function append_path_line($handle, string $path): void
    {
        $line = json_encode(["path" => base64_encode($path)], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new RuntimeException("Failed to encode a list line for path: {$path}");
        }
        $written = fwrite($handle, $line . "\n");
        if ($written !== strlen($line) + 1) {
            throw new RuntimeException("Short write on a push list, is the disk full?");
        }
    }
}
