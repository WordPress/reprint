<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Push errors are CLI/API values, never HTML output.

/**
 * Drives a local source tree through the resumable staged-push protocol.
 *
 * This is the sender's high-level lifecycle. A first run scans the source
 * without following symlinks, externally sorts a JSONL snapshot, and compares
 * it with the last completed baseline. Changed and deleted paths remain as
 * disk-backed streaming plans. The driver then creates one target workspace,
 * uploads many bounded parts per HTTP request, and repeats bounded commit calls
 * until the target has switched and cleaned every live action.
 *
 * Example:
 *
 *     $push = new MultipartPush([
 *         'base_url' => 'https://target.example/export.php',
 *         'source_root' => '/srv/source-site',
 *         'state_dir' => '/srv/reprint-state',
 *         'secret' => getenv('REPRINT_PUSH_SECRET'),
 *     ]);
 *
 *     $result = $push->run();
 *
 * run() is intentionally resumable rather than a one-shot transaction. Its
 * target-specific state directory contains the current source snapshot, the
 * changed/delete plan files maintained by PushJournal, and one `session.json`
 * checkpoint. The checkpoint is written before ambiguous operations and holds
 * local phase/plan cursors, source tokens, target-issued ids, and learned request
 * sizing. Every stored accepted byte count comes from target confirmation. It
 * never contains a materialized request body or future in-memory frame list.
 *
 * File cursors carry a source token made from size, ctime, and mode. If the source
 * changes between pieces, the next request restarts that logical file at offset
 * zero so new bytes are never appended behind an old-version prefix. As with
 * the journal signals, a same-size edit whose ctime lands in the same timestamp
 * second can escape this token; callers which require a frozen source should
 * snapshot it before pushing.
 *
 * An upload response may be lost after the target accepted bytes. In that case
 * this driver asks status for the first uncertain path and resumes only from
 * the target's workspace-derived offset. It never promotes its own attempted
 * offset to confirmed state. Structural source changes which invalidate the
 * disk plan discard the still-private target session and require a new scan.
 */
class MultipartPush
{
    /**
     * Memory budget given to the external merge sort for a source snapshot.
     *
     * Larger sites spill sorted runs to disk; this 4 MiB value is not a bound on
     * the snapshot or source tree itself.
     */
    private const SNAPSHOT_SORT_MEMORY_BYTES = 4 * 1024 * 1024;

    /**
     * Sender-side ceiling for one NUL-delimited delete-list payload.
     *
     * The target's advertised part limit may reduce it further. Keeping delete
     * payloads at 256 KiB also bounds the string assembled from JSONL records.
     */
    private const DELETE_PART_BYTES = 256 * 1024;

    /**
     * Sender-side maximum MIME parts placed in one HTTP request.
     *
     * The target may advertise a smaller cap. This independent ceiling bounds
     * response confirmation records and work performed before a checkpoint.
     */
    private const MAX_PARTS_PER_REQUEST = 128;

    /**
     * Maximum bytes read for one JSONL changed/delete plan record.
     *
     * Plan lines contain metadata for one path. The cap detects an incomplete
     * or corrupted file without reading an unbounded line into memory.
     */
    private const MAX_PLAN_LINE_BYTES = 16384;

    /** @var string Exporter API URL, including any required API selector query. */
    private string $base_url;

    /** @var string Canonical real directory scanned as the local source tree. */
    private string $source_root;

    /** @var string Root containing target-specific local push state. */
    private string $state_dir;

    /** @var string Target-specific subdirectory selected by PushJournal::site_key(). */
    private string $site_dir;

    /** @var string Atomic local checkpoint for the active target session. */
    private string $session_path;

    /** @var string Sorted JSONL snapshot captured at the start of the current push. */
    private string $snapshot_path;

    /** @var string Shared secret used to sign target control and upload requests. */
    private string $secret;

    /** @var bool Whether an explicit insecure http:// base URL is permitted. */
    private bool $allow_http;

    /** @var bool Whether lifecycle progress is written to STDERR. */
    private bool $verbose;

    /** @var bool Whether this instance may inspect state but never scan or push its source root. */
    private bool $status_only;

    /**
     * Maintains the completed baseline and disk-backed changed/delete plans.
     *
     * @var PushJournal
     */
    private $journal;

    /**
     * Configures one source/target pair and opens its local state namespace.
     *
     * Required options are `base_url`, `source_root`, `state_dir`, and `secret`.
     * `allow_http` defaults to false and exists only for an explicitly selected
     * development target; `verbose` and `status_only` default to false. The
     * source root must be a real directory rather than a symlink. Relative state
     * directories are resolved against the current working directory and
     * created mode 0700. Status-only instances do not scan their placeholder
     * source and therefore allow state below it; run() rejects such instances.
     *
     * @param array<string,mixed> $options Sender-owned configuration.
     *
     * @throws InvalidArgumentException If required configuration is missing or unsafe.
     * @throws RuntimeException If the local state directory cannot be created.
     */
    public function __construct(array $options)
    {
        foreach (['base_url', 'source_root', 'state_dir', 'secret'] as $required) {
            if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
                throw new InvalidArgumentException('MultipartPush requires a non-empty ' . $required . ' option.');
            }
        }
        $source_root = realpath($options['source_root']);
        if ($source_root === false || !is_dir($source_root) || is_link($options['source_root'])) {
            throw new InvalidArgumentException('push --source-root must name a real directory: ' . $options['source_root'] . '.');
        }
        $state_dir = rtrim($options['state_dir'], '/');
        if ($state_dir === '' || $state_dir[0] !== '/') {
            $state_dir = getcwd() . '/' . $state_dir;
        }
        $existing_state_parent = $state_dir;
        while (!file_exists($existing_state_parent) && !is_link($existing_state_parent)) {
            $parent = dirname($existing_state_parent);
            if ($parent === $existing_state_parent) {
                break;
            }
            $existing_state_parent = $parent;
        }
        $existing_state_parent = realpath($existing_state_parent);
        if (!is_dir($state_dir) && !@mkdir($state_dir, 0700, true) && !is_dir($state_dir)) {
            throw new RuntimeException('Could not create push state directory ' . $state_dir . '.');
        }
        $canonical_state_dir = realpath($state_dir);
        if ($canonical_state_dir === false || !is_dir($canonical_state_dir) || is_link($state_dir)) {
            throw new InvalidArgumentException('push state_dir must name a real directory: ' . $options['state_dir'] . '.');
        }
        $canonical_state_dir = $canonical_state_dir === '/' ? '/' : rtrim($canonical_state_dir, '/');
        $status_only = $options['status_only'] ?? false;
        if (!is_bool($status_only)) {
            throw new InvalidArgumentException('status_only must be a boolean.');
        }
        $source_prefix = $source_root === '/' ? '/' : rtrim($source_root, '/') . '/';
        if (!$status_only && ($canonical_state_dir === $source_root || strpos($canonical_state_dir . '/', $source_prefix) === 0)) {
            if (is_string($existing_state_parent)) {
                $current = $canonical_state_dir;
                while ($current !== $existing_state_parent && @rmdir($current)) {
                    $current = dirname($current);
                }
            }
            throw new InvalidArgumentException(
                'push state_dir must be outside source_root so Reprint does not snapshot its own changing state; '
                . $options['state_dir'] . ' resolves inside ' . $source_root . '.'
            );
        }
        $allow_http = $options['allow_http'] ?? false;
        if (!is_bool($allow_http)) {
            throw new InvalidArgumentException('allow_http must be a boolean.');
        }
        $this->base_url = $this->export_api_base_url($options['base_url']);
        $this->source_root = $source_root === '/' ? '/' : rtrim($source_root, '/');
        $this->state_dir = $canonical_state_dir;
        $this->secret = $options['secret'];
        $this->allow_http = $allow_http;
        $this->verbose = (bool) ($options['verbose'] ?? false);
        $this->status_only = $status_only;
        $this->journal = new PushJournal($this->state_dir, $this->base_url);
        $this->site_dir = dirname($this->journal->local_files_baseline_path);
        $this->session_path = $this->site_dir . '/session.json';
        $this->snapshot_path = $this->site_dir . '/current-local-files.jsonl';
    }

    /**
     * Starts, resumes, or completes the scan, upload, and commit lifecycle.
     *
     * Dry runs build the same disk-backed snapshot and plans without creating
     * a target session, which makes their changed/deleted counts representative
     * without mutating the target. A normal first run persists its create token
     * before contacting the target, then checkpoints every target-confirmed
     * cursor and learned request-size decision. Re-running after an exception
     * continues the active phase rather than rescanning underneath it.
     *
     * After target commit completes, the starting snapshot atomically becomes
     * the next local baseline and the active session checkpoint is removed.
     * Passing $abort delegates to abort() before any new scan or upload work.
     *
     * @param bool $dry_run Whether to stop after writing the local snapshot and plans.
     * @param bool $abort Whether to discard an existing private target session.
     * @return array<string,mixed> Completion, dry-run, or abort status plus path counts.
     *
     * @throws RuntimeException If local state, source files, transport, or target
     *     protocol state cannot be advanced safely.
     */
    public function run(bool $dry_run = false, bool $abort = false): array
    {
        if ($this->status_only) {
            throw new LogicException('A status-only MultipartPush instance cannot scan or push a source tree.');
        }
        if ($abort) {
            return $this->abort();
        }
        $state = $this->read_state();
        if ($state === null) {
            $summary = $this->prepare_new_push();
            if ($dry_run) {
                return array_merge(['status' => 'dry_run'], $summary);
            }
            $state = [
                'version' => 1,
                'source_root' => $this->source_root,
                'phase' => 'creating',
                // Persist before the request: a lost create response retries
                // the same server-derived target session, never creates two.
                'create_token' => bin2hex(random_bytes(16)),
                'session_id' => null,
                'delete_offset' => 0,
                'deletes_complete' => false,
                'plan_offset' => 0,
                'current' => null,
                'sizer' => [],
                'max_frame_bytes' => null,
                'max_upload_parts' => null,
                'summary' => $summary,
            ];
            $this->write_state($state);
        } elseif (($state['source_root'] ?? null) !== $this->source_root) {
            throw new RuntimeException('The active push belongs to source root ' . json_encode($state['source_root'] ?? null) . '. Use that root or abort it first.');
        }

        $client = $this->new_client(
            is_array($state['sizer'] ?? null) ? $state['sizer'] : [],
            is_numeric($state['max_frame_bytes'] ?? null) ? (int) $state['max_frame_bytes'] : null
        );
        if (($state['phase'] ?? null) === 'creating') {
            $this->create_or_reopen_session($state, $client);
        }

        if (($state['phase'] ?? null) === 'uploading') {
            $this->upload_deletes($state, $client);
            $this->upload_changes($state, $client);
            $state['phase'] = 'committing';
            $state['sizer'] = $client->get_request_sizer_state();
            $this->write_state($state);
        }

        if (($state['phase'] ?? null) === 'committing') {
            $this->commit($state, $client);
            $this->journal->capture_local_files_baseline($this->snapshot_path);
            $summary = is_array($state['summary'] ?? null) ? $state['summary'] : [];
            @unlink($this->session_path);
            return array_merge(['status' => 'complete'], $summary);
        }
        throw new RuntimeException('Unknown multipart push phase ' . json_encode($state['phase'] ?? null) . '.');
    }

    /**
     * Combines the local phase with target-derived session status.
     *
     * Before create returns, status can only report the persisted local
     * `creating` phase. Once a target session id exists, this sends the normal
     * signed status control request and annotates its response with the local
     * phase and current target-confirmed file cursor. It does not advance either
     * upload or commit.
     *
     * @return array<string,mixed> Local/remote phase and suggested next action.
     */
    public function status(): array
    {
        $state = $this->read_state();
        if ($state === null) {
            return ['status' => 'no_active_push'];
        }
        $session_id = $state['session_id'] ?? null;
        if (!is_string($session_id)) {
            return [
                'status' => 'creating',
                'phase' => $state['phase'] ?? 'creating',
                'next_action' => 'rerun_push',
            ];
        }
        $client = $this->new_client(
            is_array($state['sizer'] ?? null) ? $state['sizer'] : [],
            is_numeric($state['max_frame_bytes'] ?? null) ? (int) $state['max_frame_bytes'] : null
        );
        $response = $client->control_request('GET', 'staged_session_status', ['session_id' => $session_id]);
        $response['local_phase'] = $state['phase'] ?? 'unknown';
        $current = $state['current'] ?? null;
        if (is_array($current) && is_string($current['path_b64'] ?? null)) {
            $response['active_path_b64'] = $current['path_b64'];
            $response['active_accepted_bytes'] = (int) ($current['accepted_bytes'] ?? 0);
        }
        $response['next_action'] = in_array($state['phase'] ?? null, ['committing'], true)
            ? 'rerun_push'
            : 'rerun_push_or_abort';
        return $response;
    }

    /**
     * Discards private target work that has not begun live mutation.
     *
     * If a create response was lost, the persisted create token is replayed to
     * recover the deterministic target session before discard. The local
     * checkpoint is removed only after the target confirms deletion. A session
     * that has begun live mutation must instead be resumed to completion and is
     * deliberately not removable through this path.
     *
     * @return array<string,mixed> `aborted` or `no_active_push` status.
     *
     * @throws RuntimeException If target commit has begun or discard is not confirmed.
     */
    public function abort(): array
    {
        $state = $this->read_state();
        if ($state === null) {
            return ['status' => 'no_active_push'];
        }
        $client = $this->new_client(
            is_array($state['sizer'] ?? null) ? $state['sizer'] : [],
            is_numeric($state['max_frame_bytes'] ?? null) ? (int) $state['max_frame_bytes'] : null
        );
        // A create response can be lost after the target has created its
        // workspace. Reopen that deterministic create token before deleting
        // the local checkpoint, otherwise --abort could orphan the target
        // session it was meant to clean up.
        if (!is_string($state['session_id'] ?? null)) {
            if (($state['phase'] ?? null) !== 'creating') {
                throw new RuntimeException('Local push checkpoint has no target session id for phase ' . json_encode($state['phase'] ?? null) . '.');
            }
            $this->create_or_reopen_session($state, $client);
        }
        $response = $client->control_request('POST', 'staged_session_discard', [
            'session_id' => $this->session_id_from_state($state),
        ]);
        if (($response['reason'] ?? null) === 'commit_required') {
            throw new RuntimeException(
                'Push commit has begun on the target. Re-run push to finish it; --abort cannot discard a live mutation.'
            );
        }
        $this->require_control_status($response, 'discarded');
        if (!@unlink($this->session_path)) {
            throw new RuntimeException('Could not remove local push session checkpoint ' . $this->session_path . '.');
        }
        return ['status' => 'aborted'];
    }

    /**
     * Creates or reopens the target session for the persisted create token.
     *
     * The token is stored before its first request, so a lost response can be
     * retried without creating a second workspace.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Signed target client.
     */
    private function create_or_reopen_session(array &$state, MultipartPushStreamClient $client): void
    {
        $create_token = $state['create_token'] ?? null;
        if (!is_string($create_token) || preg_match('/^[a-f0-9]{32}$/D', $create_token) !== 1) {
            throw new RuntimeException('Push session checkpoint has no valid create token.');
        }
        $response = $client->control_request('POST', 'staged_session_create', ['create_token' => $create_token]);
        $this->require_control_status($response, 'created');
        $session_id = $response['session_id'] ?? null;
        if (!is_string($session_id) || preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new RuntimeException('Target create response has no valid session_id.');
        }
        $max_frame_bytes = $response['max_frame_bytes'] ?? null;
        if (!is_numeric($max_frame_bytes) || (int) $max_frame_bytes <= 0) {
            throw new RuntimeException('Target create response has no positive max_frame_bytes limit.');
        }
        $max_upload_parts = $response['max_upload_parts'] ?? null;
        if (!is_numeric($max_upload_parts) || (int) $max_upload_parts <= 0) {
            throw new RuntimeException('Target create response has no positive max_upload_parts limit.');
        }
        $state['session_id'] = $session_id;
        $state['phase'] = 'uploading';
        $state['max_frame_bytes'] = (int) $max_frame_bytes;
        $state['max_upload_parts'] = (int) $max_upload_parts;
        $client->apply_reported_limits([$response['post_max_bytes'] ?? null]);
        $client->set_max_part_bytes($state['max_frame_bytes']);
        $state['sizer'] = $client->get_request_sizer_state();
        $this->write_state($state);
    }

    /**
     * Writes a sorted source snapshot and disk-backed changed/delete plans.
     *
     * The recursive scan writes in filesystem order to a temporary JSONL file;
     * ExternalMergeSort then orders decoded path bytes within a fixed memory
     * budget before PushJournal performs its streaming merge diff.
     *
     * @return array{changed:int,deleted:int}
     */
    private function prepare_new_push(): array
    {
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0700, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException('Could not create push state directory ' . $this->site_dir . '.');
        }
        $temporary = $this->snapshot_path . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write local push snapshot ' . $temporary . '.');
        }
        try {
            $this->scan_directory($this->source_root, '', $handle, null, null);
        } finally {
            fclose($handle);
        }
        (new ExternalMergeSort(function (string $line): ?string {
            $entry = json_decode($line, true);
            if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
                return null;
            }
            $path = base64_decode($entry['path'], true);
            return $path === false ? null : $path;
        }, self::SNAPSHOT_SORT_MEMORY_BYTES, true, $this->site_dir))->sort($temporary, $this->snapshot_path);
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        return $this->journal->diff_local_files($this->snapshot_path);
    }

    /**
     * Scans without following links and records only uploadable leaves, empty
     * directories, and existence markers for non-empty directory trees.
     *
     * @param string $directory Absolute directory currently being scanned.
     * @param string $relative_path Target-relative path of that directory.
     * @param resource $handle Writable unsorted snapshot stream.
     * @param int|null $directory_ctime Parent-supplied lstat ctime for this directory.
     * @param int|null $directory_mode Parent-supplied permission bits for this directory.
     */
    private function scan_directory(string $directory, string $relative_path, $handle, ?int $directory_ctime, ?int $directory_mode): void
    {
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('Could not scan source directory ' . $directory . '.');
        }
        $children = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $children[] = $entry;
            }
        }
        if ($relative_path !== '' && $children === []) {
            $this->write_snapshot_entry($handle, $relative_path, 'directory', 0, $directory_ctime ?? 0, $directory_mode);
            return;
        }
        if ($relative_path !== '') {
            // Non-empty directories are not positive upload operations: their
            // children create them. Keep an existence-only baseline marker so
            // deleting a whole non-empty directory also removes the now-empty
            // directory on the target.
            $this->write_snapshot_entry($handle, $relative_path, 'tree-directory', 0, $directory_ctime ?? 0, $directory_mode);
        }
        foreach ($children as $entry) {
            $path = $relative_path === '' ? $entry : $relative_path . '/' . $entry;
            $this->validate_relative_path($path);
            $absolute_path = $directory . '/' . $entry;
            clearstatcache(true, $absolute_path);
            $stat = @lstat($absolute_path);
            if (!is_array($stat) || !isset($stat['mode'], $stat['ctime'], $stat['size'])) {
                throw new RuntimeException('Could not stat source path ' . $this->display_path($path) . '.');
            }
            $type_bits = ((int) $stat['mode']) & 0170000;
            if ($type_bits === 0040000) {
                $this->scan_directory($absolute_path, $path, $handle, (int) $stat['ctime'], ((int) $stat['mode']) & 07777);
            } elseif ($type_bits === 0100000) {
                $this->write_snapshot_entry($handle, $path, 'file', (int) $stat['size'], (int) $stat['ctime'], ((int) $stat['mode']) & 07777);
            } elseif ($type_bits === 0120000) {
                $target = @readlink($absolute_path);
                if (!is_string($target)) {
                    throw new RuntimeException('Could not read source symlink ' . $this->display_path($path) . '.');
                }
                $this->write_snapshot_entry($handle, $path, 'symlink', (int) $stat['size'], (int) $stat['ctime'], null, $target);
            } else {
                throw new RuntimeException('Source path ' . $this->display_path($path) . ' has an unsupported filesystem type.');
            }
        }
    }

    /**
     * Writes one binary-safe source identity record to the unsorted snapshot.
     *
     * Paths and symlink targets are base64 because JSON accepts only UTF-8 text.
     * File size, ctime, and mode are the same drift signals stored beside
     * resumable cursors; the logical type distinguishes files, links, empty
     * directories, and private non-empty tree markers.
     *
     * @param resource $handle Writable temporary snapshot stream.
     * @param string $path Target-relative source path.
     * @param string $type Snapshot logical type.
     * @param int $size lstat size in bytes.
     * @param int $ctime lstat change timestamp in seconds.
     * @param int|null $mode Permission bits for files and directories.
     * @param string|null $target Literal symlink target, when applicable.
     */
    private function write_snapshot_entry($handle, string $path, string $type, int $size, int $ctime, ?int $mode = null, ?string $target = null): void
    {
        $entry = [
            'path' => base64_encode($path),
            'type' => $type,
            'size' => $size,
            'ctime' => $ctime,
        ];
        if ($mode !== null) {
            $entry['mode'] = $mode;
        }
        if ($target !== null) {
            $entry['target'] = base64_encode($target);
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false || fwrite($handle, $line . "\n") !== strlen($line) + 1) {
            throw new RuntimeException('Could not write local push snapshot entry for ' . $this->display_path($path) . '.');
        }
    }

    /**
     * Sends bounded delete-list parts and persists only target-confirmed offsets.
     *
     * Delete replay is idempotent at commit planning, so an indeterminate
     * response retries the same complete NUL-delimited records. The JSONL byte
     * offset moves only after an accepted response confirms the part type.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Active target client.
     */
    private function upload_deletes(array &$state, MultipartPushStreamClient $client): void
    {
        if (!empty($state['deletes_complete'])) {
            return;
        }
        while (true) {
            $start = (int) ($state['delete_offset'] ?? 0);
            [$payload, $end] = $this->read_delete_part($start, $this->maximum_part_bytes($state));
            if ($payload === '') {
                $state['deletes_complete'] = true;
                $this->write_state($state);
                return;
            }
            $session_id = $this->session_id_from_state($state);
            if (!$client->start_upload_request($session_id)) {
                throw new RuntimeException('Could not open delete-list upload: ' . $client->get_last_error());
            }
            $sent = $client->send_part(['type' => 'delete-list', 'payload' => $payload]);
            $result = $client->finish_request();
            if (!$sent || $result['status'] !== 'complete') {
                $this->handle_unknown_upload_result($state, $client, $result, null);
                continue;
            }
            $accepted = $result['response']['accepted'] ?? null;
            if (!is_array($accepted) || !isset($accepted[0]) || ($accepted[0]['type'] ?? null) !== 'delete-list') {
                throw new RuntimeException('Target did not confirm the delete-list part it accepted.');
            }
            $state['delete_offset'] = $end;
            $state['sizer'] = $client->get_request_sizer_state();
            $this->write_state($state);
        }
    }

    /**
     * Reads complete delete records up to one bounded MIME-part payload.
     *
     * The returned offset always lands after a complete JSONL source record;
     * the payload always lands after a NUL path delimiter. One record too large
     * for the target part ceiling fails explicitly instead of being split.
     *
     * @param int $offset Confirmed byte offset in the JSONL delete plan.
     * @param int $maximum_bytes Target-advertised one-part ceiling.
     * @return array{0:string,1:int}
     */
    private function read_delete_part(int $offset, int $maximum_bytes): array
    {
        if (!is_file($this->journal->local_paths_to_delete)) {
            return ['', $offset];
        }
        $handle = @fopen($this->journal->local_paths_to_delete, 'rb');
        if ($handle === false || fseek($handle, $offset, SEEK_SET) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not resume the local delete list at byte ' . $offset . '.');
        }
        $payload = '';
        $end = $offset;
        try {
            while (($line = fgets($handle, self::MAX_PLAN_LINE_BYTES + 1)) !== false) {
                if (strlen($line) > self::MAX_PLAN_LINE_BYTES || substr($line, -1) !== "\n") {
                    throw new RuntimeException('Local delete list contains an oversized or incomplete path record.');
                }
                $entry = json_decode($line, true);
                $path = is_array($entry) && isset($entry['path']) && is_string($entry['path']) ? base64_decode($entry['path'], true) : false;
                if ($path === false || $path === '') {
                    throw new RuntimeException('Local delete list contains an invalid path record.');
                }
                $record = $path . "\0";
                $part_limit = min(self::DELETE_PART_BYTES, $maximum_bytes);
                if (strlen($record) > $part_limit) {
                    throw new RuntimeException(
                        'Delete path ' . $this->display_path($path) . ' cannot fit inside the target multipart part limit of '
                        . $part_limit . ' bytes.'
                    );
                }
                if ($payload !== '' && strlen($payload) + strlen($record) > $part_limit) {
                    fseek($handle, -strlen($line), SEEK_CUR);
                    break;
                }
                $payload .= $record;
                $end = ftell($handle);
            }
        } finally {
            fclose($handle);
        }
        return [$payload, $end];
    }

    /**
     * Drives caller-paced multipart requests from the disk-backed change plan.
     *
     * Each body piece is read immediately before send_part(). Local cursors
     * advance only after the target confirms the corresponding durable part;
     * an unknown response is reconciled through status before reuse.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Active target client.
     */
    private function upload_changes(array &$state, MultipartPushStreamClient $client): void
    {
        while ($this->read_plan_entry((int) ($state['plan_offset'] ?? 0)) !== null) {
            $session_id = $this->session_id_from_state($state);
            if (!$client->start_upload_request($session_id)) {
                throw new RuntimeException('Could not open multipart upload: ' . $client->get_last_error());
            }
            $sent = [];
            $working_offset = (int) $state['plan_offset'];
            $working_file = null;
            while (count($sent) < $this->maximum_upload_parts($state) && !$client->should_finish_request()) {
                $entry = $this->read_plan_entry($working_offset);
                if ($entry === null) {
                    break;
                }
                $path = $entry['path'];
                if ($entry['type'] === 'file') {
                    $file = $this->prepare_file_part($state, $path, $working_file);
                    if ($file['restart_session']) {
                        $this->finish_or_discard_open_request($client);
                        $this->restart_for_structural_source_change($state);
                        return;
                    }
                    $payload_limit = $client->next_file_body_bytes($path, $file['size'], $file['offset'], $file['mode']);
                    if ($payload_limit === 0) {
                        break;
                    }
                    if ($file['offset'] === $file['size']) {
                        // A killed target can retain every byte in work/partial
                        // before its promotion rename. An empty final part lets
                        // that target complete the rename without retransmitting.
                        $payload = '';
                    } else {
                        $payload = $this->read_file_piece(
                            $file['absolute_path'],
                            $file['offset'],
                            $payload_limit,
                            $file['size']
                        );
                        if ($payload === '') {
                            $this->finish_or_discard_open_request($client);
                            $this->restart_for_structural_source_change($state);
                            return;
                        }
                    }
                    $after_read = $this->regular_file_stat($file['absolute_path']);
                    if ($after_read === null) {
                        $this->finish_or_discard_open_request($client);
                        $this->restart_for_structural_source_change($state);
                        return;
                    }
                    $next_file_offset = $file['offset'] + strlen($payload);
                    $complete = $next_file_offset === $file['size'];
                    if (!$client->send_part([
                        'type' => 'file',
                        'path' => $path,
                        'total_bytes' => $file['size'],
                        'offset' => $file['offset'],
                        'mode' => $file['mode'],
                        'payload' => $payload,
                    ])) {
                        break;
                    }
                    $sent[] = [
                        'type' => 'file',
                        'path' => $path,
                        'plan_offset' => $working_offset,
                        'next_plan_offset' => $entry['next_offset'],
                        'fingerprint' => $file['fingerprint'],
                        'source_changed' => $after_read !== $file['fingerprint'],
                        'replacement_fingerprint' => $after_read,
                    ];
                    if ($after_read !== $file['fingerprint']) {
                        // Finish this request so the confirmed old prefix can
                        // be recorded as stale, then restart this one file at
                        // offset zero on the next request.
                        break;
                    }
                    $working_file = [
                        'path' => $path,
                        'offset' => $next_file_offset,
                        'size' => $file['size'],
                        'fingerprint' => $file['fingerprint'],
                    ];
                    if ($complete) {
                        $working_offset = $entry['next_offset'];
                        $working_file = null;
                    }
                    continue;
                }

                $part = $this->prepare_metadata_part($entry);
                if ($part === null) {
                    $this->finish_or_discard_open_request($client);
                    $this->restart_for_structural_source_change($state);
                    return;
                }
                if (!$client->send_part($part)) {
                    break;
                }
                $sent[] = [
                    'type' => $entry['type'],
                    'path' => $path,
                    'plan_offset' => $working_offset,
                    'next_plan_offset' => $entry['next_offset'],
                ];
                $working_offset = $entry['next_offset'];
            }
            $result = $client->finish_request();
            if ($sent === []) {
                if ($result['status'] === 'complete') {
                    throw new RuntimeException('Multipart request could not fit one upload part inside its request-body budget.');
                }
                $this->handle_unknown_upload_result($state, $client, $result, null);
                continue;
            }
            if ($result['status'] !== 'complete') {
                $this->handle_unknown_upload_result($state, $client, $result, $sent[0]);
                continue;
            }
            $this->apply_upload_response($state, $sent, $result['response']);
            $state['sizer'] = $client->get_request_sizer_state();
            $this->write_state($state);
        }
    }

    /**
     * Selects the persisted file offset whose source token still matches.
     *
     * A persisted cursor is reusable only with the same size, ctime, and mode
     * fingerprint. The fingerprint is checkpointed before any bytes leave.
     * A same-size rewrite within one ctime tick remains indistinguishable to
     * both this token and the snapshot diff, which uses the same signals.
     *
     * @param array<string,mixed> $state
     * @param string $path Current target-relative file path.
     * @param array<string,mixed>|null $working_file In-request cursor not yet checkpointed.
     * @return array<string,mixed>
     */
    private function prepare_file_part(array &$state, string $path, ?array $working_file): array
    {
        $absolute_path = $this->source_path($path);
        $stat = $this->regular_file_stat($absolute_path);
        if ($stat === null) {
            return ['restart_session' => true];
        }
        $fingerprint = ['size' => $stat['size'], 'ctime' => $stat['ctime'], 'mode' => $stat['mode']];
        if ($working_file !== null) {
            if ($working_file['path'] !== $path || $working_file['fingerprint'] !== $fingerprint) {
                return ['restart_session' => true];
            }
            return [
                'restart_session' => false,
                'absolute_path' => $absolute_path,
                'size' => $stat['size'],
                'offset' => $working_file['offset'],
                'mode' => $stat['mode'],
                'fingerprint' => $fingerprint,
            ];
        }
        $current = $state['current'] ?? null;
        $offset = 0;
        if (is_array($current) && ($current['path_b64'] ?? null) === base64_encode($path) && ($current['fingerprint'] ?? null) === $fingerprint) {
            $offset = (int) ($current['accepted_bytes'] ?? 0);
        } else {
            // Persist the fingerprint before bytes leave the process. If the
            // response is lost, status can safely decide whether its staged
            // bytes belong to this exact source version.
            $state['current'] = [
                'path_b64' => base64_encode($path),
                'accepted_bytes' => 0,
                'fingerprint' => $fingerprint,
            ];
            $this->write_state($state);
        }
        if ($offset < 0 || $offset > $stat['size']) {
            $offset = 0;
            $state['current']['accepted_bytes'] = 0;
            $this->write_state($state);
        }
        return [
            'restart_session' => false,
            'absolute_path' => $absolute_path,
            'size' => $stat['size'],
            'offset' => $offset,
            'mode' => $stat['mode'],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Revalidates a directory or symlink immediately before sending it.
     *
     * Empty directories must still be empty, directory-mode records must still
     * name non-empty directories, and symlinks must still be links with non-empty
     * literal targets. Returning null triggers a fresh whole-tree scan rather
     * than applying metadata from a stale structural plan.
     *
     * @param array<string,mixed> $entry Current disk-plan record.
     * @return array<string,mixed>|null Null when the source changed structurally.
     */
    private function prepare_metadata_part(array $entry): ?array
    {
        $path = $entry['path'];
        $absolute_path = $this->source_path($path);
        clearstatcache(true, $absolute_path);
        $stat = @lstat($absolute_path);
        if (!is_array($stat)) {
            return null;
        }
        $type_bits = ((int) ($stat['mode'] ?? 0)) & 0170000;
        if ($entry['type'] === 'directory' || $entry['type'] === 'directory-mode') {
            if ($type_bits !== 0040000) {
                return null;
            }
            $handle = @opendir($absolute_path);
            if ($handle === false) {
                return null;
            }
            $has_entries = false;
            try {
                while (true) {
                    $candidate = readdir($handle);
                    if ($candidate === false) {
                        break;
                    }
                    if ($candidate !== '.' && $candidate !== '..') {
                        $has_entries = true;
                        break;
                    }
                }
            } finally {
                closedir($handle);
            }
            if (
                ($entry['type'] === 'directory' && $has_entries)
                || ($entry['type'] === 'directory-mode' && !$has_entries)
            ) {
                return null;
            }
            $mode = ((int) $stat['mode']) & 07777;
            if (!isset($entry['mode']) || $entry['mode'] !== $mode) {
                return null;
            }
            return ['type' => $entry['type'], 'path' => $path, 'mode' => $mode, 'payload' => ''];
        }
        if ($entry['type'] === 'symlink') {
            $target = $type_bits === 0120000 ? @readlink($absolute_path) : false;
            if (!is_string($target) || $target === '') {
                return null;
            }
            return ['type' => 'symlink', 'path' => $path, 'target' => $target, 'payload' => ''];
        }
        throw new RuntimeException('Local push plan has unsupported type ' . json_encode($entry['type']) . '.');
    }

    /**
     * Advances local cursors from an ordered, target-confirmed part list.
     *
     * Confirmation order, type, path, file size, and accepted byte counts must
     * match what this request sent. A file observed changing during transmission
     * remains on the same plan record with a zero cursor and replacement token.
     *
     * @param array<string,mixed> $state
     * @param array<int,array<string,mixed>> $sent
     * @param array<string,mixed>|null $response
     */
    private function apply_upload_response(array &$state, array $sent, ?array $response): void
    {
        $accepted = is_array($response['accepted'] ?? null) ? $response['accepted'] : null;
        if (!is_array($accepted) || count($accepted) !== count($sent)) {
            throw new RuntimeException('Target upload response did not confirm every sent multipart part.');
        }
        foreach ($sent as $index => $descriptor) {
            $confirmation = $accepted[$index];
            if (!is_array($confirmation) || ($confirmation['type'] ?? null) !== $descriptor['type']) {
                throw new RuntimeException('Target upload response changed the type of a sent multipart part.');
            }
            if ($descriptor['type'] === 'file') {
                if (($confirmation['path_b64'] ?? null) !== base64_encode($descriptor['path'])) {
                    throw new RuntimeException('Target upload response confirmed a different file path.');
                }
                $accepted_bytes = $confirmation['accepted_bytes'] ?? null;
                if (
                    !is_numeric($accepted_bytes)
                    || (int) $accepted_bytes < 0
                    || (int) $accepted_bytes > (int) $descriptor['fingerprint']['size']
                ) {
                    throw new RuntimeException('Target upload response has no valid accepted byte count.');
                }
                $state['current'] = [
                    'path_b64' => base64_encode($descriptor['path']),
                    'accepted_bytes' => (int) $accepted_bytes,
                    'fingerprint' => $descriptor['fingerprint'],
                ];
                if (!empty($descriptor['source_changed'])) {
                    // The bytes just accepted may belong to a source version
                    // that changed while this request was moving. Leave the
                    // logical path pending and force its next part to restart
                    // at zero; never append the new version to this prefix.
                    $state['current'] = [
                        'path_b64' => base64_encode($descriptor['path']),
                        'accepted_bytes' => 0,
                        'fingerprint' => $descriptor['replacement_fingerprint'],
                    ];
                    continue;
                }
                if (($confirmation['state'] ?? null) === 'complete') {
                    if ((int) $accepted_bytes !== (int) $descriptor['fingerprint']['size']) {
                        throw new RuntimeException('Target marked a file complete at a byte count different from its source size.');
                    }
                    $state['plan_offset'] = $descriptor['next_plan_offset'];
                    $state['current'] = null;
                }
                continue;
            }
            if (($confirmation['path_b64'] ?? null) !== base64_encode($descriptor['path']) || ($confirmation['state'] ?? null) !== 'complete') {
                throw new RuntimeException('Target did not confirm completed ' . $descriptor['type'] . ' ' . $this->display_path($descriptor['path']) . '.');
            }
            $state['plan_offset'] = $descriptor['next_plan_offset'];
        }
    }

    /**
     * Handles a failed or indeterminate upload without trusting sender offsets.
     *
     * Terminal failures stop immediately. Retryable results reconcile the first
     * uncertain path, persist the request sizer's new evidence, and leave later
     * parts pending for idempotent replay.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Target client used for status.
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $first_sent
     */
    private function handle_unknown_upload_result(array &$state, MultipartPushStreamClient $client, array $result, ?array $first_sent): void
    {
        if ($result['status'] === 'failed') {
            throw new RuntimeException('Multipart upload failed: ' . ($result['reason'] ?? 'unknown') . '. ' . ($result['detail'] ?? ''));
        }
        // A retry has no sender-confirmed outcome. Ask the target for only
        // the current path before reusing a persisted file cursor.
        if ($first_sent !== null) {
            $this->recover_first_sent_status($state, $client, $first_sent);
        }
        $state['sizer'] = $client->get_request_sizer_state();
        $this->write_state($state);
    }

    /**
     * Reconciles the first indeterminate part with target workspace state.
     *
     * A file cursor is reused only if the current size, ctime, and mode token still
     * matches the sent token. Completed metadata advances its plan record;
     * missing metadata remains pending and will be replayed.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Target client used for status.
     * @param array<string,mixed> $sent
     */
    private function recover_first_sent_status(array &$state, MultipartPushStreamClient $client, array $sent): void
    {
        $session_id = $this->session_id_from_state($state);
        $response = $client->control_request('GET', 'staged_session_status', [
            'session_id' => $session_id,
            'path' => base64_encode($sent['path']),
        ]);
        $this->require_control_status($response, 'ok');
        $paths = $response['paths'] ?? null;
        $target = is_array($paths) && isset($paths[0]) && is_array($paths[0]) ? $paths[0] : null;
        if ($target === null || ($target['path_b64'] ?? null) !== base64_encode($sent['path'])) {
            throw new RuntimeException('Target status did not return the requested path.');
        }
        if ($sent['type'] === 'file') {
            $current = $state['current'] ?? null;
            if (!is_array($current) || ($current['path_b64'] ?? null) !== base64_encode($sent['path'])) {
                return;
            }
            $current_fingerprint = $this->regular_file_stat($this->source_path($sent['path']));
            if ($current_fingerprint === null) {
                $this->restart_for_structural_source_change($state);
                return;
            }
            if ($current_fingerprint !== ($sent['fingerprint'] ?? null)) {
                // The target may have accepted old-version bytes before the
                // response was lost. Do not call a completed old version the
                // final source: the next request starts this path at zero.
                $state['current'] = [
                    'path_b64' => base64_encode($sent['path']),
                    'accepted_bytes' => 0,
                    'fingerprint' => $current_fingerprint,
                ];
                return;
            }
            if (($target['state'] ?? null) === 'complete') {
                if ((int) ($target['accepted_bytes'] ?? -1) !== (int) $current_fingerprint['size']) {
                    throw new RuntimeException('Target status marked a file complete at a byte count different from its source size.');
                }
                $state['plan_offset'] = $sent['next_plan_offset'];
                $state['current'] = null;
                return;
            }
            if (($target['state'] ?? null) === 'partial') {
                $accepted_bytes = $target['accepted_bytes'] ?? null;
                if (!is_numeric($accepted_bytes) || (int) $accepted_bytes < 0 || (int) $accepted_bytes > (int) $current_fingerprint['size']) {
                    throw new RuntimeException('Target status has no valid partial byte count.');
                }
                $state['current']['accepted_bytes'] = (int) $accepted_bytes;
            }
            return;
        }
        if (($target['state'] ?? null) === 'complete') {
            $state['plan_offset'] = $sent['next_plan_offset'];
        }
    }

    /**
     * Discards a snapshot-stale target workspace and requires a fresh scan.
     *
     * @param array<string,mixed> $state
     */
    private function restart_for_structural_source_change(array &$state): void
    {
        $this->log('Source changed structurally during push; discarding its private target session and rebuilding the plan.');
        $session_id = $this->session_id_from_state($state);
        $client = $this->new_client(
            is_array($state['sizer'] ?? null) ? $state['sizer'] : [],
            is_numeric($state['max_frame_bytes'] ?? null) ? (int) $state['max_frame_bytes'] : null
        );
        $response = $client->control_request('POST', 'staged_session_discard', ['session_id' => $session_id]);
        $this->require_control_status($response, 'discarded');
        @unlink($this->session_path);
        // The caller returns to its outer invocation; a later `push` builds a
        // fresh normalized tree rather than mixing two source snapshots.
        throw new RuntimeException('Source changed structurally during push. Its staged session was discarded; run push again.');
    }

    /**
     * Best-effort closes an open request before its whole session is discarded.
     *
     * The later structural-change error is more useful than a secondary
     * transport failure from this cleanup attempt.
     *
     * @param MultipartPushStreamClient $client Client with an open request.
     */
    private function finish_or_discard_open_request(MultipartPushStreamClient $client): void
    {
        try {
            $client->finish_request();
        } catch (Throwable $exception) {
            // The target session remains private and restart_for_* discards it
            // below; the original source-change error is more actionable.
        }
    }

    /**
     * Drives bounded target commit calls until every deployment action is durable.
     *
     * @param array<string,mixed> $state
     * @param MultipartPushStreamClient $client Signed control client.
     */
    private function commit(array &$state, MultipartPushStreamClient $client): void
    {
        $session_id = $this->session_id_from_state($state);
        do {
            $response = $client->control_request('POST', 'staged_session_commit', ['session_id' => $session_id]);
            $this->require_control_status($response, 'ok');
            $this->log('Commit phase ' . ($response['phase'] ?? 'unknown') . ', ' . (int) ($response['files_remaining'] ?? 0) . ' live entries remaining.');
        } while (!empty($response['send_next_request']));
    }

    /**
     * Reads one changed-path record at a resumable byte offset.
     *
     * The returned next offset is ftell() after one complete newline-terminated
     * record, making the JSONL file itself a constant-memory cursor space.
     *
     * @param int $offset Confirmed byte offset in the changed-path plan.
     * @return array<string,mixed>|null
     */
    private function read_plan_entry(int $offset): ?array
    {
        if (!is_file($this->journal->local_paths_to_push)) {
            return null;
        }
        $handle = @fopen($this->journal->local_paths_to_push, 'rb');
        if ($handle === false || fseek($handle, $offset, SEEK_SET) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not resume local changed-path list at byte ' . $offset . '.');
        }
        try {
            $line = fgets($handle, self::MAX_PLAN_LINE_BYTES + 1);
            if ($line === false) {
                return null;
            }
            if (strlen($line) > self::MAX_PLAN_LINE_BYTES || substr($line, -1) !== "\n") {
                throw new RuntimeException('Local changed-path list contains an oversized or incomplete path record.');
            }
            $entry = json_decode($line, true);
            $path = is_array($entry) && isset($entry['path']) && is_string($entry['path']) ? base64_decode($entry['path'], true) : false;
            if ($path === false || $path === '') {
                throw new RuntimeException('Local changed-path list contains an invalid path record.');
            }
            $type = $entry['type'] ?? null;
            if (!is_string($type) || !in_array($type, ['file', 'directory', 'directory-mode', 'symlink'], true)) {
                throw new RuntimeException('Local changed-path list contains no supported logical type for ' . $this->display_path($path) . '.');
            }
            $mode = $entry['mode'] ?? null;
            if (in_array($type, ['file', 'directory', 'directory-mode'], true) && (!is_int($mode) || $mode < 0 || $mode > 07777)) {
                throw new RuntimeException('Local changed-path list contains no valid mode for ' . $this->display_path($path) . '.');
            }
            return [
                'path' => $path,
                'type' => $type,
                'mode' => $mode,
                'next_offset' => ftell($handle),
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Returns the size, ctime, and mode token stored beside a resumable cursor.
     *
     * lstat() deliberately rejects a file which became a symlink or another
     * type; ctime catches ordinary replacement/content changes which mtime can
     * hide through touch(), subject to the filesystem timestamp resolution.
     *
     * @param string $path Absolute source path.
     * @return array{size:int,ctime:int,mode:int}|null
     */
    private function regular_file_stat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000) {
            return null;
        }
        return [
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
            'mode' => ((int) $stat['mode']) & 07777,
        ];
    }

    /**
     * Reads at most one configured in-memory piece at an exact source offset.
     *
     * The bytes actually returned determine the later Content-Length. A short
     * read is therefore a valid smaller frame, while false is an I/O failure.
     *
     * @param string $path Absolute regular-file source path.
     * @param int $offset Byte offset confirmed for this source token.
     * @param int $maximum_bytes Positive caller read budget.
     * @param int $snapshotted_total_bytes Total declared in the multipart file headers.
     * @return string Bytes actually read, possibly fewer than requested.
     */
    private function read_file_piece(string $path, int $offset, int $maximum_bytes, int $snapshotted_total_bytes): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false || fseek($handle, $offset, SEEK_SET) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not read source file at offset ' . $offset . ': ' . $path . '.');
        }
        try {
            // A source may grow after its token was captured. Never read bytes
            // beyond the total this part will declare to the target.
            $piece = fread($handle, min($maximum_bytes, $snapshotted_total_bytes - $offset));
            if ($piece === false) {
                throw new RuntimeException('Could not read source file ' . $path . '.');
            }
            return $piece;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Returns the validated target-issued session id from a local checkpoint.
     *
     * @param array<string,mixed> $state Active local session checkpoint.
     * @return string 32-character lowercase hexadecimal session id.
     */
    private function session_id_from_state(array $state): string
    {
        $session_id = $state['session_id'] ?? null;
        if (!is_string($session_id) || preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new RuntimeException('Local push session checkpoint has no target-issued session id.');
        }
        return $session_id;
    }

    /**
     * Reads the active local session checkpoint when one exists.
     *
     * @return array<string,mixed>|null Decoded checkpoint, or null with no active push.
     */
    private function read_state(): ?array
    {
        if (!is_file($this->session_path)) {
            return null;
        }
        $contents = @file_get_contents($this->session_path);
        $state = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($state)) {
            throw new RuntimeException('Local push session checkpoint is not valid JSON: ' . $this->session_path . '.');
        }
        return $state;
    }

    /**
     * Atomically replaces the local session checkpoint after a flushed write.
     *
     * @param array<string,mixed> $state
     */
    private function write_state(array $state): void
    {
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0700, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException('Could not create push session directory ' . $this->site_dir . '.');
        }
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode local push session checkpoint.');
        }
        $temporary = $this->session_path . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write local push session checkpoint.');
        }
        try {
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Could not flush local push session checkpoint.');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $this->session_path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish local push session checkpoint.');
        }
    }

    /**
     * Builds a client seeded with checkpointed request sizing and part policy.
     *
     * A fresh HMAC client is stateless; only PushRequestSizer learning and the
     * target's one-part ceiling need to survive process boundaries.
     *
     * @param array<string,mixed> $sizer_state Persisted adaptive sizing state.
     * @param int|null $max_part_bytes Target-advertised one-part ceiling.
     * @return MultipartPushStreamClient Configured streaming client.
     */
    private function new_client(array $sizer_state, ?int $max_part_bytes = null): MultipartPushStreamClient
    {
        $options = [
            'base_url' => $this->base_url,
            'allow_http' => $this->allow_http,
            'hmac_client' => new Site_Export_HMAC_Client($this->secret),
            'request_sizer' => new PushRequestSizer([], $sizer_state),
        ];
        if ($max_part_bytes !== null) {
            $options['max_part_bytes'] = $max_part_bytes;
        }
        return new MultipartPushStreamClient($options);
    }

    /**
     * Returns the target-advertised maximum payload bytes for one MIME part.
     *
     * @param array<string,mixed> $state Active local session checkpoint.
     * @return int Positive one-part ceiling.
     */
    private function maximum_part_bytes(array $state): int
    {
        $maximum = $state['max_frame_bytes'] ?? null;
        if (!is_numeric($maximum) || (int) $maximum <= 0) {
            throw new RuntimeException('Local push session checkpoint has no positive target max_frame_bytes limit.');
        }
        return (int) $maximum;
    }

    /**
     * Returns the smaller of target and sender MIME-part count ceilings.
     *
     * @param array<string,mixed> $state Active local session checkpoint.
     * @return int Positive per-request part count.
     */
    private function maximum_upload_parts(array $state): int
    {
        $maximum = $state['max_upload_parts'] ?? self::MAX_PARTS_PER_REQUEST;
        if (!is_numeric($maximum) || (int) $maximum <= 0) {
            throw new RuntimeException('Local push session checkpoint has no positive target max_upload_parts limit.');
        }
        return min(self::MAX_PARTS_PER_REQUEST, (int) $maximum);
    }

    /**
     * Requires the target's machine-readable control status to match expectation.
     *
     * @param array<string,mixed> $response Decoded control response.
     * @param string $expected_status Success status required by this lifecycle step.
     */
    private function require_control_status(array $response, string $expected_status): void
    {
        if (($response['status'] ?? null) !== $expected_status) {
            throw new RuntimeException(
                'Push control request expected status ' . $expected_status . ', received '
                . json_encode($response['status'] ?? null) . ': ' . (string) ($response['detail'] ?? $response['reason'] ?? '')
            );
        }
    }

    /**
     * Builds an absolute source path after validating its relative bytes.
     *
     * @param string $relative_path Target-relative source path.
     * @return string Absolute path below $source_root.
     */
    private function source_path(string $relative_path): string
    {
        $this->validate_relative_path($relative_path);
        return ($this->source_root === '/' ? '' : $this->source_root) . '/' . $relative_path;
    }

    /**
     * Rejects path traversal and the target-owned maintenance marker.
     *
     * Paths are raw byte strings. Absolute paths, NUL, backslashes, empty/dot
     * segments, and `.maintenance` are not valid source entries for this push.
     *
     * @param string $path Target-relative source path.
     */
    private function validate_relative_path(string $path): void
    {
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new RuntimeException('Source path cannot be sent safely: ' . $this->display_path($path) . '.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Source path cannot be sent safely: ' . $this->display_path($path) . '.');
            }
        }
        if ($path === '.maintenance' || strpos($path, '.maintenance/') === 0) {
            throw new RuntimeException('Source path .maintenance is reserved for target commit maintenance mode.');
        }
    }

    /**
     * Encodes arbitrary path bytes for safe terminal and exception text.
     *
     * @param string $path Raw filesystem path bytes.
     * @return string Base64 representation.
     */
    private function display_path(string $path): string
    {
        return base64_encode($path);
    }

    /**
     * Writes one lifecycle message only when verbose output is enabled.
     *
     * @param string $message Human-readable progress without the push prefix.
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            fwrite(STDERR, '[push] ' . $message . "\n");
        }
    }

    /**
     * Adds the exporter API selector without replacing an existing query.
     *
     * URLs already selecting either supported API parameter are preserved so
     * caller-provided routing and later HMAC signatures use the same target.
     *
     * @param string $base_url User-supplied exporter URL.
     * @return string URL ready for endpoint query parameters.
     */
    private function export_api_base_url(string $base_url): string
    {
        $base_url = rtrim($base_url, '?&');
        $query = parse_url($base_url, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            if (array_key_exists('reprint-api', $parameters) || array_key_exists('site-export-api', $parameters)) {
                return $base_url;
            }
        }
        return $base_url . (strpos($base_url, '?') === false ? '?' : '&') . 'reprint-api=1';
    }
}
