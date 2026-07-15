<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal errors are CLI values, never HTML output.
/**
 * Per-remote-site source snapshot used to plan a push: the local baseline.
 *
 * ctime is machine-local, so push never compares a local timestamp against
 * a remote one. Each machine is compared against its own past instead
 * (markdown/PUSH-SYNC.md, "Change detection"). This class stores that past
 * on the local machine, one directory per remote site:
 *
 *     <state-dir>/push/<site>/last-sync-local-files.jsonl
 *     <state-dir>/push/<site>/last-sync-local-files.identity.json
 *
 * The baseline is a copy of a file index in the same format as
 * .import-index.jsonl: one JSON object per line with a base64-encoded path
 * plus ctime, size, type, and any symlink target, sorted by
 * decoded path. Non-empty directories use a private `tree-directory` marker:
 * it is never uploaded, but lets a later diff remove a vanished directory
 * after its last child goes. The adjacent identity binds that snapshot to
 * the managed remote directory and canonical local root which produced it.
 * A completed push or eligible full-root pull captures both.
 *
 * diff_local_files() answers "what changed on this machine since this
 * compatible baseline was captured". It streams the current index and the
 * local baseline together — both are sorted by path, so one pass suffices —
 * and writes two outputs into the site directory:
 *
 *     local-paths-to-push.jsonl  paths new since the baseline, or whose
 *                                ctime, size, or type differs
 *     local-delete-stream.bin    raw NUL-delimited paths in the baseline but
 *                                gone from the current index
 *
 * The diff parses one JSON line at a time from each input. Ordering compares
 * decoded paths; two entries with the same path count as unchanged when their
 * decoded JSON objects match, so JSON field order or escaping changes do not
 * affect the diff. Changed-path output carries the current snapshot entry
 * (path, type, size, ctime, and symlink target where relevant); private
 * tree-directory markers remain private and never become upload operations.
 * Delete output is already the exact byte stream sent to and stored by the
 * target. Filesystem paths cannot contain NUL, so the delimiter preserves every
 * other path byte without a second representation. The sender still lstat()s
 * positive changes immediately before upload; their fields are the normalized
 * logical change, not source truth after the plan was written.
 *
 * With no compatible baseline — because none has been captured yet, or the
 * push uses another managed directory or local root — every uploadable current
 * entry counts as changed and no deletion can be detected.
 *
 * Producing the current index is the caller's job; this class only
 * compares and stores. The outputs belong to the push which produced them and
 * remain its stable disk-backed inputs when that active session resumes. A new
 * push replaces both outputs from a new snapshot before creating its target
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
 *     $journal = new PushJournal('/srv/reprint-state', $target_url, '/srv/local-site');
 *     $summary = $journal->diff_local_files($current_snapshot);
 *     // Stream the positive plan and raw delete stream, then wait for commit.
 *     // After target commit succeeds:
 *     $journal->capture_local_files_baseline($current_snapshot);
 */
class PushJournal
{
    /**
     * Target-specific directory containing the baseline and current transfer inputs.
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
     * Identity which makes the adjacent baseline eligible for this source.
     *
     * @var string
     */
    public string $local_files_identity_path;

    /**
     * Managed remote directory and local root expected by this journal.
     *
     * @var array{managed_directory_b64:string|null,local_root_b64:string}
     */
    private array $local_files_identity;

    /**
     * JSONL current-snapshot entries which the active push must materialize.
     *
     * @var string
     */
    public string $local_paths_to_push;

    /**
     * Raw NUL-delimited paths which existed at baseline and are absent or replaced.
     *
     * @var string
     */
    public string $local_delete_stream_path;

    /**
     * Selects the local state files for one normalized remote site identity.
     *
     * Construction derives paths only; directories are created lazily when a
     * baseline or diff is written.
     *
     * @param string $state_dir Local root shared by push state for all targets.
     * @param string $site_url Remote site URL used to isolate this baseline.
     * @param string $local_root Canonical local root represented by the baseline.
     */
    public function __construct(string $state_dir, string $site_url, string $local_root)
    {
        if ($local_root === '' || $local_root[0] !== '/') {
            throw new InvalidArgumentException("Push journal local_root must be an absolute path: {$local_root}");
        }
        $local_root = $local_root === '/' ? '/' : rtrim($local_root, '/');
        $managed_directory = self::managed_directory_from_url($site_url);
        $this->site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $this->local_files_baseline_path = $this->site_dir . "/last-sync-local-files.jsonl";
        $this->local_files_identity_path = $this->site_dir . "/last-sync-local-files.identity.json";
        $this->local_files_identity = [
            'managed_directory_b64' => $managed_directory === null ? null : base64_encode($managed_directory),
            'local_root_b64' => base64_encode($local_root),
        ];
        $this->local_paths_to_push = $this->site_dir . "/local-paths-to-push.jsonl";
        $this->local_delete_stream_path = $this->site_dir . "/local-delete-stream.bin";
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
     * Returns the one explicit absolute directory selected by a target URL.
     *
     * Pull baseline publication needs an exact managed-directory identity.
     * A missing parameter, a repeated/array parameter, or a relative value is
     * ambiguous and therefore cannot seed a full-root push baseline.
     *
     * @param string $site_url Exporter URL supplied to both pull and push.
     * @return string|null Normalized absolute directory, or null when ambiguous.
     */
    public static function managed_directory_from_url(string $site_url): ?string
    {
        $query = parse_url($site_url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        $directories = [];
        foreach (explode('&', $query) as $parameter) {
            [$encoded_name, $encoded_value] = array_pad(explode('=', $parameter, 2), 2, '');
            $name = urldecode($encoded_name);
            if (
                strpos($name, 'directory[') === 0 ||
                strpos($name, 'directory]') === 0
            ) {
                return null;
            }
            if ($name === 'directory') {
                $directories[] = urldecode($encoded_value);
            }
        }
        if (count($directories) !== 1) {
            return null;
        }
        $directory = $directories[0];
        if ($directory === '' || $directory[0] !== '/' || strpos($directory, "\0") !== false) {
            return null;
        }
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        $directory = rtrim($directory, '/');
        return $directory === '' ? '/' : $directory;
    }

    /**
     * Stores a copy of the local file index as the new local baseline.
     *
     * The push driver calls this at the end of a successful push; from then
     * on "changed locally" means "different from this index". Both files are
     * prepared before the old identity is invalidated. The baseline and then
     * its identity are renamed into place, so any interrupted publication is
     * unavailable rather than trusted with the wrong identity. The source file
     * is left untouched.
     *
     * @param string $index_file Sorted JSONL snapshot whose push or pull filesystem phase completed.
     *
     * @throws RuntimeException If the snapshot is missing or cannot be published.
     */
    public function capture_local_files_baseline(string $index_file): void
    {
        if (!is_file($index_file)) {
            throw new RuntimeException("Cannot capture a baseline, the index file is missing: {$index_file}");
        }
        $this->ensure_site_dir();
        $baseline_tmp = $this->local_files_baseline_path . ".tmp";
        $identity_tmp = $this->local_files_identity_path . ".tmp";
        if (!copy($index_file, $baseline_tmp)) {
            throw new RuntimeException("Failed to copy the index into a baseline temp file: {$baseline_tmp}");
        }
        $identity = json_encode($this->local_files_identity, JSON_UNESCAPED_SLASHES);
        if ($identity === false || file_put_contents($identity_tmp, $identity . "\n") !== strlen($identity) + 1) {
            @unlink($baseline_tmp);
            @unlink($identity_tmp);
            throw new RuntimeException("Failed to write the local baseline identity: {$identity_tmp}");
        }
        if (is_file($this->local_files_identity_path) && !unlink($this->local_files_identity_path)) {
            @unlink($baseline_tmp);
            @unlink($identity_tmp);
            throw new RuntimeException("Failed to invalidate the prior local baseline identity: {$this->local_files_identity_path}");
        }
        if (!rename($baseline_tmp, $this->local_files_baseline_path)) {
            @unlink($baseline_tmp);
            @unlink($identity_tmp);
            throw new RuntimeException("Failed to move the baseline into place: {$this->local_files_baseline_path}");
        }
        if (!rename($identity_tmp, $this->local_files_identity_path)) {
            @unlink($identity_tmp);
            throw new RuntimeException("Failed to move the local baseline identity into place: {$this->local_files_identity_path}");
        }
    }

    /** Returns whether the existing baseline belongs to this exact source. */
    public function has_compatible_local_files_baseline(): bool
    {
        if (!is_file($this->local_files_baseline_path) || !is_file($this->local_files_identity_path)) {
            return false;
        }
        // phpcs:ignore WordPress.WhiteSpace.CastStructureSpacing.NoSpaceBeforeOpenParenthesis -- Match this class's established cast style.
        $identity = json_decode((string) file_get_contents($this->local_files_identity_path), true);
        return is_array($identity) && $identity === $this->local_files_identity;
    }

    /**
     * Makes the current baseline unavailable before a pull can mutate files.
     *
     * The baseline goes first. If removing the identity subsequently fails,
     * no snapshot remains for that identity to make trustworthy.
     *
     * @param string $state_dir Local root shared by push state for all targets.
     * @param string $site_url Remote site URL whose one baseline is invalidated.
     */
    public static function invalidate_local_files_baseline(string $state_dir, string $site_url): void
    {
        $site_dir = rtrim($state_dir, "/") . "/push/" . self::site_key($site_url);
        $baseline_path = $site_dir . "/last-sync-local-files.jsonl";
        $identity_path = $site_dir . "/last-sync-local-files.identity.json";
        if (is_file($baseline_path) && !unlink($baseline_path)) {
            throw new RuntimeException("Failed to invalidate the local baseline: {$baseline_path}");
        }
        if (is_file($identity_path) && !unlink($identity_path)) {
            throw new RuntimeException("Failed to remove the invalid local baseline identity: {$identity_path}");
        }
    }

    /**
     * Compares the current local index against the local baseline and writes
     * the positive plan and raw delete stream, replacing either output from an
     * earlier run.
     *
     * A single merge pass over the two path-sorted files: a path only in
     * the current index is new, a path in both whose lines differ has
     * changed (both go to local_paths_to_push), a path only in the baseline
     * was deleted (its raw bytes and a NUL go to local_delete_stream_path).
     * Unchanged paths produce no output. Both outputs are written to temporary
     * files and renamed into place, so a killed run never leaves a torn record
     * behind.
     *
     * Memory stays constant however large the site is: the merge holds one
     * line from each input file and the outputs go straight to disk, so an
     * index with a million entries costs the same as one with ten.
     *
     * @param string $current_index_file Path-sorted JSONL snapshot for this push.
     * @return array{changed: int, deleted: int} Entry counts, for the push summary.
     *
     * @throws RuntimeException If an index is invalid or output files cannot be written.
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
        if ($this->has_compatible_local_files_baseline()) {
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
        $local_delete_stream_tmp = $this->local_delete_stream_path . ".tmp";
        $delete_stream_handle = fopen($local_delete_stream_tmp, "wb");
        if (!$delete_stream_handle) {
            fclose($current_handle);
            if ($baseline_handle) {
                fclose($baseline_handle);
            }
            fclose($paths_to_push_handle);
            throw new RuntimeException("Failed to open the local delete stream for writing: {$local_delete_stream_tmp}");
        }

        $changed = 0;
        $deleted = 0;
        $pending_deleted_tree_roots = [];
        $this->read_line($current_handle, $current_entry, $current_path);
        $this->read_line($baseline_handle, $baseline_entry, $baseline_path);
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
                if (!$this->is_tree_directory($current_entry)) {
                    $out = json_encode($this->without_mode($current_entry), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                    }
                    ++$changed;
                }
                $this->read_line($current_handle, $current_entry, $current_path);
            } elseif ($order > 0) {
                // Only in the baseline: deleted since the last push.
                if (!$this->is_below_pending_root($baseline_path, $pending_deleted_tree_roots)) {
                    $out = $baseline_path . "\0";
                    if (fwrite($delete_stream_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on the local delete stream, is the disk full?");
                    }
                    $deleted++;
                    if ($this->is_tree_directory($baseline_entry)) {
                        $this->remember_pending_root($baseline_path, $pending_deleted_tree_roots);
                    }
                }
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path);
            } else {
                $current_entry = $this->without_mode($current_entry);
                $baseline_entry = $this->without_mode($baseline_entry);
                $current_type = $current_entry['type'] ?? null;
                $baseline_type = $baseline_entry['type'] ?? null;
                $needs_delete = false;
                $needs_push = false;

                if ($current_type === 'tree-directory') {
                    // A structural directory is represented by its children.
                    // An existing empty or structural directory can be reused;
                    // a file or symlink must be cleared before child installs.
                    $needs_delete = in_array($baseline_type, ['file', 'symlink'], true);
                } elseif ($current_type === 'directory') {
                    // Explicit empty directories replace every prior non-empty
                    // or non-directory value, but unchanged emptiness is no work.
                    $needs_delete = $baseline_type !== 'directory';
                    $needs_push = $baseline_type !== 'directory';
                } elseif (in_array($current_type, ['file', 'symlink'], true)) {
                    $needs_delete = in_array($baseline_type, ['directory', 'tree-directory'], true);
                    $needs_push = $needs_delete || $current_entry != $baseline_entry;
                } else {
                    throw new RuntimeException('Current local index contains unsupported type ' . json_encode($current_type) . '.');
                }

                if ($needs_delete) {
                    $out = $baseline_path . "\0";
                    if (fwrite($delete_stream_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on the local delete stream, is the disk full?");
                    }
                    ++$deleted;
                    $this->remember_pending_root($baseline_path, $pending_deleted_tree_roots);
                }
                if ($needs_push) {
                    $out = json_encode($current_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($paths_to_push_handle, $out) !== strlen($out)) {
                        throw new RuntimeException("Short write on local_paths_to_push, is the disk full?");
                    }
                    ++$changed;
                }
                $this->read_line($current_handle, $current_entry, $current_path);
                $this->read_line($baseline_handle, $baseline_entry, $baseline_path);
            }
        }

        fclose($current_handle);
        if ($baseline_handle) {
            fclose($baseline_handle);
        }
        if (!fclose($paths_to_push_handle) || !rename($local_paths_to_push_tmp, $this->local_paths_to_push)) {
            throw new RuntimeException("Failed to move local_paths_to_push into place: {$this->local_paths_to_push}");
        }
        if (!fclose($delete_stream_handle) || !rename($local_delete_stream_tmp, $this->local_delete_stream_path)) {
            throw new RuntimeException("Failed to move the local delete stream into place: {$this->local_delete_stream_path}");
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

    /** Older baselines may contain permissions, but modes are not push state. */
    private function without_mode(array $entry): array
    {
        unset($entry['mode']);
        return $entry;
    }

    /**
     * Indicates whether a path is already covered by a prior replacement root.
     *
     * Sorted input makes roots appear before descendants. Suppressing covered
     * descendants keeps the delete stream minimal and avoids contradictory work when
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
     * Both out-parameters become null at end of file: $entry is the decoded
     * index object and $path is the decoded path used for ordering and raw
     * delete output.
     *
     * @param resource|null $handle
     * @param array<string, mixed>|null $entry
     * @param string|null $path Decoded path used for bytewise ordering.
     */
    private function read_line($handle, ?array &$entry, ?string &$path): void
    {
        $entry = null;
        $path = null;
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
