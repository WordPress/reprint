<?php

/**
 * Moves verified staged artifacts into the live tree — the payoff of the
 * staged store: the site never sees a partial file, and the whole transfer
 * lands in one short window of renames.
 *
 * Apply is rename-only. rename() is what makes each file's appearance
 * atomic, and it only works when the staging directory and the target root
 * sit on the same filesystem. A staging directory on another device would
 * force copy-and-remove — a window where a half-copied file is live — so
 * apply refuses it up front with a typed "cross_device" rejection, before
 * any file has moved. Operators fix it by pointing the staging directory
 * (SITE_EXPORT_STAGING_DIR in the WordPress wiring) at the target's
 * filesystem; apply never silently degrades to copying.
 *
 * The manifest is itself a staged artifact: one JSONL line per artifact,
 * {"artifact_id": ..., "size": ...}. That keeps the apply request bounded
 * (an id, not a 50k-entry body) and reuses the store's verification for the
 * manifest bytes. The manifest artifact is consumed by apply, never applied
 * into the tree.
 *
 * Everything that can be checked is checked before the first rename:
 * the device match, the target root, the manifest's shape, and that every
 * listed artifact is verified at its manifest size (or already applied —
 * see below). A transfer that is incomplete or disagrees with its plan is
 * rejected whole; there is no partial apply of a half-verified transfer.
 *
 * A kill mid-apply leaves some files moved and some staged. Rerunning
 * apply recovers without a journal cursor: an entry whose staged file is
 * gone but whose target exists at the manifest size is already applied and
 * skips; everything still staged renames as normal. The pre-scan accepts
 * both states, so the rerun passes validation and finishes the window.
 * Only an entry that is neither staged-and-verified nor already applied
 * rejects the run — that transfer is genuinely incomplete.
 *
 * Apply holds the store's exclusive lock for the whole window, so an
 * upload retry racing the apply gets "busy" instead of writing into a
 * tree being consumed. The lock file is the same one the store's mutators
 * use; a killed apply releases it with the process.
 *
 * Policies cover the pull side of the same engine. on_existing "skip"
 * (pull's preserve-local) makes an occupied target path win: the entry is
 * not applied and its staged bytes are consumed. refuse_symlinked_parents
 * refuses to create anything through a symlinked directory. Protected
 * entries are classified before validation, so a rerun with nothing
 * staged does not reject a transfer over paths the policy was never going
 * to touch.
 */
final class Site_Export_Staged_Apply {

    /** @var string */
    private $staging_dir;

    /** @var string */
    private $files_dir;

    /** @var string */
    private $lock_path;

    /** @var string */
    private $target_root;

    /** @var Site_Export_Staged_Artifacts */
    private $store;

    /** @var callable */
    private $device_id;

    /** @var string */
    private $on_existing;

    /** @var bool */
    private $refuse_symlinked_parents;

    /**
     * @param array $options
     *   - staging_dir (string, required): same directory the store uses.
     *   - target_root (string, required): tree the artifacts apply into.
     *   - device_id (?callable): fn(string $path): ?int — stat device
     *     lookup, injectable for tests; null means the path is unreadable.
     *   - on_existing ("replace"|"skip"): what an occupied target path
     *     means. "replace" is push's own-tree semantics; "skip" is pull's
     *     preserve-local — the local path wins and the staged bytes are
     *     consumed unapplied.
     *   - refuse_symlinked_parents (bool): never create anything through a
     *     symlinked directory. Hosting layouts symlink plugins, themes,
     *     and core from shared locations; apply must not write into those
     *     through the link.
     */
    public function __construct(array $options) {
        $staging_dir = $options['staging_dir'] ?? null;
        $target_root = $options['target_root'] ?? null;
        if (!is_string($staging_dir) || $staging_dir === '') {
            throw new InvalidArgumentException('Staged apply requires a staging_dir option.');
        }
        if (!is_string($target_root) || $target_root === '') {
            throw new InvalidArgumentException('Staged apply requires a target_root option.');
        }
        $on_existing = $options['on_existing'] ?? 'replace';
        if (!in_array($on_existing, ['replace', 'skip'], true)) {
            throw new InvalidArgumentException('Staged apply on_existing must be "replace" or "skip".');
        }
        $this->staging_dir = rtrim($staging_dir, '/');
        $this->files_dir = $this->staging_dir . '/files';
        $this->lock_path = $this->staging_dir . '/lock';
        $this->target_root = rtrim($target_root, '/');
        $this->store = new Site_Export_Staged_Artifacts($staging_dir);
        $this->on_existing = $on_existing;
        $this->refuse_symlinked_parents = !empty($options['refuse_symlinked_parents']);
        $this->device_id = $options['device_id'] ?? static function (string $path): ?int {
            $stat = @stat($path);
            return $stat === false ? null : (int) $stat['dev'];
        };
    }

    /**
     * Validate a transfer and move it into the target root.
     *
     * @param string $manifest_id Staged artifact holding the manifest.
     * @param bool $check_only Validate everything, move nothing. This is
     *   what a sender calls before uploading gigabytes: a staging
     *   directory that can never apply (cross_device) rejects here, at
     *   the start, not after the transfer.
     * @return array{status:string,reason:?string,detail:?string,applied:int,already_applied:int,skipped:int}
     *   status "applied"|"ready"|"busy"|"rejected"; skipped counts entries
     *   the on_existing/symlink policies protected.
     */
    public function apply(string $manifest_id, bool $check_only = false): array {
        $precheck = $this->check_environment();
        if ($precheck !== null) {
            return $precheck;
        }

        if ($check_only && !$this->store->status($manifest_id)['verified']) {
            // Probes run before the transfer exists. The environment is
            // the answer; the manifest is checked when apply runs for real.
            return $this->result('ready', null, null);
        }

        $lock = @fopen($this->lock_path, 'c+b');
        if ($lock === false) {
            return $this->result('rejected', 'io_error', 'open_lock_file');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->result('busy', null, null);
            }

            $entries = $this->read_manifest($manifest_id);
            if (!is_array($entries)) {
                return $this->result('rejected', 'manifest_invalid', $entries);
            }

            // Nothing moves until the whole transfer proves consistent.
            // Protected entries are decided here too, before validation:
            // a rerun with nothing staged must not reject the transfer
            // over a path the policy was never going to touch.
            $already_applied = [];
            $protected = [];
            foreach ($entries as $index => $entry) {
                $verdict = $this->classify($entry);
                if ($verdict === 'staged') {
                    continue;
                }
                if ($verdict === 'applied') {
                    $already_applied[$index] = true;
                    continue;
                }
                if ($verdict === 'protected') {
                    $protected[$index] = true;
                    continue;
                }
                return $this->result('rejected', $verdict, $entry['artifact_id']);
            }

            if ($check_only) {
                return $this->result('ready', null, null, 0, count($already_applied), count($protected));
            }

            // Apply holds the store's lock, so cleanup below unlinks the
            // store's files directly — its own discard() would contend on
            // the very lock this process holds.
            $applied = 0;
            foreach ($entries as $index => $entry) {
                if (isset($protected[$index])) {
                    // The local path wins; consume the staged bytes so they
                    // cannot be applied by a later, differently-configured
                    // run.
                    @unlink($this->files_dir . '/' . $entry['artifact_id']);
                    @unlink($this->staging_dir . '/verified/' . $entry['artifact_id']);
                    continue;
                }
                if (isset($already_applied[$index])) {
                    // A rerun after a kill: the file is in place; only its
                    // leftover marker remains to consume.
                    @unlink($this->staging_dir . '/verified/' . $entry['artifact_id']);
                    continue;
                }
                $move_error = $this->move_into_place($entry['artifact_id']);
                if ($move_error !== null) {
                    // Everything validated, so this is environmental
                    // (permissions, disk). Rerunning apply resumes: moved
                    // entries classify as applied, the rest re-validate.
                    return $this->result('rejected', 'io_error', $move_error, $applied, count($already_applied), count($protected));
                }
                ++$applied;
            }

            // The manifest is consumed with the transfer it described, and
            // a cursor still naming a consumed artifact must not survive to
            // answer a future upload with its stale offset.
            @unlink($this->files_dir . '/' . $manifest_id);
            @unlink($this->staging_dir . '/verified/' . $manifest_id);
            $this->clear_cursor_for($entries, $manifest_id);

            return $this->result('applied', null, null, $applied, count($already_applied), count($protected));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Clears the store cursor when it names an artifact this apply consumed.
     * Same write-then-rename commit as the store's own cursor writes.
     */
    private function clear_cursor_for(array $entries, string $manifest_id): void {
        $state_path = $this->staging_dir . '/state.json';
        $raw = @file_get_contents($state_path);
        $state = $raw === false ? null : json_decode($raw, true);
        $cursor_artifact = is_array($state) && is_string($state['artifact_id'] ?? null)
            ? $state['artifact_id']
            : null;
        if ($cursor_artifact === null) {
            return;
        }

        $consumed = $cursor_artifact === $manifest_id;
        foreach ($entries as $entry) {
            if ($entry['artifact_id'] === $cursor_artifact) {
                $consumed = true;
                break;
            }
        }
        if (!$consumed) {
            return;
        }

        $json = json_encode(['artifact_id' => null, 'committed_bytes' => 0]);
        $tmp_path = $state_path . '.tmp';
        if (@file_put_contents($tmp_path, $json) === strlen($json)) {
            @rename($tmp_path, $state_path);
        }
        @unlink($tmp_path);
    }

    /**
     * The environment checks that hold regardless of transfer state.
     *
     * @return array|null Null when the environment can apply.
     */
    private function check_environment(): ?array {
        $target_device = call_user_func($this->device_id, $this->target_root);
        if ($target_device === null) {
            return $this->result('rejected', 'target_missing', $this->target_root);
        }
        if (!is_dir($this->target_root) || !is_writable($this->target_root)) {
            return $this->result('rejected', 'target_unwritable', $this->target_root);
        }

        // The staging scaffolding may predate any upload; the device of the
        // staging base decides where files/ will live.
        if (!is_dir($this->staging_dir) && !@mkdir($this->staging_dir, 0700, true) && !is_dir($this->staging_dir)) {
            return $this->result('rejected', 'staging_unavailable', $this->staging_dir);
        }
        $staging_device = call_user_func($this->device_id, $this->staging_dir);
        if ($staging_device === null) {
            return $this->result('rejected', 'staging_unavailable', $this->staging_dir);
        }

        if ($staging_device !== $target_device) {
            // Apply is rename-only by design: copy-and-remove would serve
            // half-copied files from the live tree. Refuse before anything
            // moves; the fix is a same-filesystem staging directory.
            return $this->result('rejected', 'cross_device', sprintf(
                'staging %s is on device %d, target %s on device %d',
                $this->staging_dir,
                $staging_device,
                $this->target_root,
                $target_device
            ));
        }

        return null;
    }

    /**
     * Reads and validates the manifest artifact.
     *
     * @return array|string Entry list, or an error detail string.
     */
    private function read_manifest(string $manifest_id) {
        $status = $this->store->status($manifest_id);
        if (!$status['verified']) {
            return 'manifest artifact is not verified: ' . $manifest_id;
        }
        $raw = @file_get_contents($this->files_dir . '/' . $manifest_id);
        if ($raw === false) {
            return 'manifest artifact is not readable: ' . $manifest_id;
        }

        $entries = [];
        foreach (explode("\n", $raw) as $line_number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (
                !is_array($entry)
                || !is_string($entry['artifact_id'] ?? null)
                || !is_int($entry['size'] ?? null)
                || $entry['size'] < 0
            ) {
                return 'manifest line ' . ( $line_number + 1 ) . ' is malformed';
            }
            if ($entry['artifact_id'] === $manifest_id) {
                return 'manifest lists itself';
            }
            // Manifest content is sender data; ids must satisfy the same
            // path rule the store enforces at upload time, or a crafted
            // manifest could probe or write outside the target root.
            if (!$this->valid_artifact_id($entry['artifact_id'])) {
                return 'manifest line ' . ( $line_number + 1 ) . ' has an invalid artifact id';
            }
            $entries[] = [
                'artifact_id' => $entry['artifact_id'],
                'size' => $entry['size'],
            ];
        }
        return $entries;
    }

    /** Mirrors the store's artifact id rule (see its contract docblock). */
    private function valid_artifact_id(string $artifact_id): bool {
        if (
            $artifact_id === ''
            || $artifact_id[0] === '/'
            || strpos($artifact_id, "\0") !== false
            || strpos($artifact_id, '\\') !== false
        ) {
            return false;
        }
        foreach (explode('/', $artifact_id) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * One manifest entry is either staged (verified at the manifest size),
     * already applied (a rerun after a kill), or a typed problem.
     */
    private function classify(array $entry): string {
        // Policy verdicts come first: a protected path is out of bounds no
        // matter what is or is not staged for it.
        if ($this->refuse_symlinked_parents && $this->has_symlinked_parent($entry['artifact_id'])) {
            return 'protected';
        }
        if ($this->on_existing === 'skip') {
            $occupied = $this->target_root . '/' . $entry['artifact_id'];
            // is_link covers dangling symlinks, which file_exists misses;
            // preserve-local protects those too.
            if (file_exists($occupied) || is_link($occupied)) {
                return 'protected';
            }
        }

        $status = $this->store->status($entry['artifact_id']);
        if ($status['verified'] && $status['committed_bytes'] === $entry['size'] && $status['exists']) {
            return 'staged';
        }

        $target_path = $this->target_root . '/' . $entry['artifact_id'];
        $target_size = @filesize($target_path);
        if ($target_size !== false && $target_size === $entry['size'] && !$status['exists']) {
            return 'applied';
        }

        if ($status['verified'] && !$status['exists']) {
            return 'artifact_file_missing';
        }
        if ($status['exists'] && !$status['verified']) {
            return 'unverified_artifact';
        }
        return 'missing_artifact';
    }

    /**
     * @return string|null Error detail, or null when the artifact moved.
     */
    private function move_into_place(string $artifact_id): ?string {
        $staged_path = $this->files_dir . '/' . $artifact_id;
        $target_path = $this->target_root . '/' . $artifact_id;

        // Type swaps happen between transfers: a directory or symlink can
        // occupy the path a file now belongs at. rename() replaces files
        // and symlinks atomically but not directories, so clear anything
        // that is not a plain file first — the same replacement the pull
        // writer performs, and like it, never following links.
        if (is_link($target_path) === false && is_dir($target_path)) {
            if (!$this->remove_path_without_following_symlinks($target_path)) {
                return 'clear_target_path: ' . $artifact_id;
            }
        }

        $parent = dirname($target_path);
        if (!is_dir($parent)) {
            // A file left where a directory now belongs blocks mkdir; the
            // transfer's tree wins, same as above.
            $blocking_error = $this->clear_blocking_parents($artifact_id);
            if ($blocking_error !== null) {
                return $blocking_error . ': ' . $artifact_id;
            }
            if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                return 'create_target_dir: ' . $artifact_id;
            }
        }
        // Same filesystem by precheck, so this replaces atomically; the
        // tree never holds a partial file.
        if (!@rename($staged_path, $target_path)) {
            return 'rename: ' . $artifact_id;
        }
        // The marker is consumed with the file. A kill between the two
        // reruns as "applied" (target at size, nothing staged) and only
        // costs this unlink again.
        @unlink($this->staging_dir . '/verified/' . $artifact_id);
        return null;
    }

    /**
     * Removes a file or symlink sitting on the artifact's parent chain
     * where a directory now belongs. Symlinked directories pass through:
     * only an actual non-directory blocks mkdir.
     *
     * @return string|null Error detail, or null when the chain is clear.
     */
    private function clear_blocking_parents(string $artifact_id): ?string {
        $parent_rel = dirname($artifact_id);
        if ($parent_rel === '.') {
            return null;
        }
        $path = $this->target_root;
        foreach (explode('/', $parent_rel) as $segment) {
            $path .= '/' . $segment;
            if ((is_link($path) || file_exists($path)) && !is_dir($path)) {
                if (!@unlink($path)) {
                    return 'clear_blocking_parent';
                }
            }
        }
        return null;
    }

    /**
     * Trunk's replacement discipline: unlink files and symlinks directly
     * (never following the link), recurse into real directories.
     */
    private function remove_path_without_following_symlinks(string $path): bool {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path) === true;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path_without_following_symlinks($path . '/' . $entry)) {
                return false;
            }
        }
        return @rmdir($path) === true;
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,applied:int,already_applied:int}
     */
    /**
     * Whether any directory component of the artifact's target path is a
     * symlink. Only the relative components are checked — the target root
     * itself may legitimately be reached through links.
     */
    private function has_symlinked_parent(string $artifact_id): bool {
        $parent = dirname($artifact_id);
        if ($parent === '.') {
            return false;
        }
        $path = $this->target_root;
        foreach (explode('/', $parent) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                return true;
            }
        }
        return false;
    }

    private function result(string $status, ?string $reason, ?string $detail, int $applied = 0, int $already_applied = 0, int $skipped = 0): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'applied' => $applied,
            'already_applied' => $already_applied,
            'skipped' => $skipped,
        ];
    }
}
