<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.
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
 * plus ctime, size, and type, sorted by decoded path. The push driver
 * captures it at the end of a successful push. A capture writes a
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
 *     work-deletes                 the same deletions as raw NUL-delimited
 *                                  path bytes for the receiver
 *     sender-index.jsonl           stable index selected for the active push
 *     sender.json                  the active sender's resumable request state
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
 * Producing the current index is the caller's job; this class only compares
 * and stores. A new push replaces the lists. An active sender keeps using the
 * lists named by sender.json so its byte offsets continue to identify the
 * same records after a process restart.
 */
class PushJournal
{
    private string $site_dir;

    /** @var string Copy of the local file index from the last completed push. */
    public string $local_files_baseline_path;

    /** @var string JSONL file of local paths to push, written by diff_local_files(). */
    public string $local_paths_to_push;

    /** @var string JSONL file of local paths whose deletion should be pushed, written by diff_local_files(). */
    public string $local_paths_to_delete;

    /** @var string Raw NUL-delimited work deletes prepared for the receiver. */
    public string $work_deletes_path;

    /** @var string Atomic checkpoint for the active high-level sender. */
    public string $sender_state_path;

    /** @var string Stable local index selected when the active push began. */
    public string $sender_index_path;

    public function __construct(string $state_dir, string $site_url)
    {
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->local_paths_to_push = $this->site_dir . "/local-paths-to-push.jsonl";
        $this->local_paths_to_delete = $this->site_dir . "/local-paths-to-delete.jsonl";
        $this->work_deletes_path = $this->site_dir . "/work-deletes";
        $this->sender_state_path = $this->site_dir . "/sender.json";
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
     * Captures the stable index whose path lists and final baseline belong to
     * the active push.
     *
     * Source tokens may still change after this copy; that restarts the value
     * being sent and leaves the start-of-push index evidence as the baseline.
     * A later index whose size, ctime, or type differs therefore selects the
     * path again. Replacing the caller's index during a long push can never mark
     * paths absent from the stable index as synchronized.
     */
    public function capture_sender_index(string $index_file): void
    {
        $this->replace_file($this->sender_index_path, $index_file);
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
     * Converts the deletion JSONL into the receiver's raw work-delete stream.
     *
     * Each input record is decoded and written immediately as `path + NUL`.
     * A temporary file is renamed only after the complete list is consumed, so
     * sender.json never refers to a torn work-delete record after interruption.
     *
     * @return int Complete raw work-delete byte count.
     */
    public function prepare_work_deletes(): int
    {
        if (!is_file($this->local_paths_to_delete)) {
            throw new RuntimeException("Cannot prepare work deletes, the local deletion list is missing: {$this->local_paths_to_delete}");
        }
        $this->ensure_site_dir();
        $input = fopen($this->local_paths_to_delete, "rb");
        if (!$input) {
            throw new RuntimeException("Failed to open local_paths_to_delete: {$this->local_paths_to_delete}");
        }
        $temporary = $this->work_deletes_path . ".tmp";
        $output = fopen($temporary, "wb");
        if (!$output) {
            fclose($input);
            throw new RuntimeException("Failed to open work deletes for writing: {$temporary}");
        }
        $bytes = 0;
        try {
            while (true) {
                $this->read_line($input, $entry, $path, $base64_path);
                if ($entry === null) {
                    break;
                }
                $record = $path . "\0";
                if (fwrite($output, $record) !== strlen($record)) {
                    throw new RuntimeException("Short write on work deletes, is the disk full?");
                }
                $bytes += strlen($record);
            }
            if (!fclose($output)) {
                $output = null;
                throw new RuntimeException("Failed to close work deletes: {$temporary}");
            }
            $output = null;
            if (!rename($temporary, $this->work_deletes_path)) {
                throw new RuntimeException("Failed to move work deletes into place: {$this->work_deletes_path}");
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
        return $bytes;
    }

    /**
     * Reads the active sender checkpoint written by write_sender_state().
     *
     * The journal owns this private file and atomically replaces it. A valid
     * JSON object is therefore trusted as the sender's last durable boundary;
     * the workflow interprets its phase and correlated fields. `source_token`
     * and `confirmed_bytes` describe the last positive-work part confirmed by
     * an upload response, not bytes merely handed to the network.
     *
     * @return array{
     *     version:1,
     *     push_session_id:string,
     *     phase:'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     paths_byte_offset:int,
     *     current_path_b64:?string,
     *     next_paths_byte_offset:int,
     *     source_token:array{type:'file'|'directory'|'symlink',size:int,ctime:int}|null,
     *     confirmed_bytes:int,
     *     work_deletes_byte_offset:int,
     *     recoverable_failures:int,
     *     max_part_bytes:?int,
     *     request_sizer_state:array{request_body_bytes:int,ceiling_bytes:?int,growth_holdoff_remaining:int}
     * }|null Durable sender state, or null when no push is active.
     */
    public function read_sender_state(): ?array
    {
        if (!is_file($this->sender_state_path)) {
            return null;
        }
        $json = file_get_contents($this->sender_state_path);
        if (!is_string($json)) {
            throw new RuntimeException("Failed to read sender state: {$this->sender_state_path}");
        }
        $state = json_decode($json, true);
        if (!is_array($state)) {
            throw new RuntimeException("Sender state does not contain a JSON object: {$this->sender_state_path}");
        }
        return $state;
    }

    /**
     * Atomically records the sender's last receiver-reconcilable boundary.
     *
     * @param array{
     *     version:1,
     *     push_session_id:string,
     *     phase:'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     paths_byte_offset:int,
     *     current_path_b64:?string,
     *     next_paths_byte_offset:int,
     *     source_token:array{type:'file'|'directory'|'symlink',size:int,ctime:int}|null,
     *     confirmed_bytes:int,
     *     work_deletes_byte_offset:int,
     *     recoverable_failures:int,
     *     max_part_bytes:?int,
     *     request_sizer_state:array{request_body_bytes:int,ceiling_bytes:?int,growth_holdoff_remaining:int}
     * } $state Complete sender checkpoint.
     */
    public function write_sender_state(array $state): void
    {
        $this->ensure_site_dir();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->sender_state_path . ".tmp";
        if (file_put_contents($temporary, $json) !== strlen($json)) {
            throw new RuntimeException("Failed to write sender state: {$temporary}");
        }
        if (!rename($temporary, $this->sender_state_path)) {
            throw new RuntimeException("Failed to move sender state into place: {$this->sender_state_path}");
        }
    }

    /**
     * Removes the completed or deliberately abandoned sender checkpoint.
     */
    public function clear_sender_state(): void
    {
        if (is_file($this->sender_state_path) && !unlink($this->sender_state_path)) {
            throw new RuntimeException("Failed to remove sender state: {$this->sender_state_path}");
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
     * Copies an index into a journal file by same-directory temp and rename.
     *
     * This supplies the atomic replacement used by both the completed local
     * baseline and the stable index selected for an active sender.
     */
    private function replace_file(string $target, string $source_index_file): void
    {
        if (!is_file($source_index_file)) {
            throw new RuntimeException("Cannot replace a journal file, the source index file is missing: {$source_index_file}");
        }
        $this->ensure_site_dir();
        $tmp = $target . ".tmp";
        if (!copy($source_index_file, $tmp)) {
            throw new RuntimeException("Failed to copy the source index into a temporary journal file: {$tmp}");
        }
        if (!rename($tmp, $target)) {
            throw new RuntimeException("Failed to move the journal file into place: {$target}");
        }
    }

    private function ensure_site_dir(): void
    {
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0755, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException("Failed to create the push journal directory: {$this->site_dir}");
        }
    }
}
