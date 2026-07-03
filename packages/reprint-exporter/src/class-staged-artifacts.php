<?php

/**
 * Staged storage for chunked artifact transfers.
 *
 * A transfer moves many files plus database changes at network speed and can
 * be interrupted or aborted at any point — the live site must never see that
 * as half-applied state, and partial content must never exist under the
 * web-served tree, where the server would hand out a half-uploaded plugin
 * file as plain text. So staging exists for apply atomicity and containment,
 * not for authentication: nothing a transfer receives touches the site
 * directly, the bytes accumulate here while the site keeps running, aborting
 * before apply is free (discard the staged data), and the apply step later
 * moves verified artifacts into place in one short, controlled window
 * instead of mutating the live tree for the duration of the transfer.
 *
 * The first consumer is push: bounded chunk uploads from an outbound-only
 * local site (see UploadChunkSizer on the importer side). The pull file
 * writer has the same needs whenever its target is a live site — today it
 * streams downloads straight to their final paths, which is fine for a fresh
 * local directory but not for a web-served tree — so nothing here is
 * upload-specific: sequential offsets match pull's byte-offset resume model.
 *
 * The caller drives the loop, matching the streaming producers: where a
 * reader calls next_chunk() until the producer is done, a sender calls
 * append() once per buffer it read, and the store performs exactly one
 * bounded, individually-committed step per call. Nothing is held between
 * calls, so the transfer can stop after any step and resume from
 * committed_bytes — in the next request, the next process, or a test that
 * stops the loop at a chosen iteration. Stopping inside a step leaves an
 * uncommitted tail the next append truncates away. Reading the request
 * body, sizing buffers, and skipping bytes the store reports as duplicate
 * all belong to the caller's loop.
 *
 * Integrity checks are byte counts, the same discipline pull uses: an
 * append is accepted only at the committed offset and only whole, and
 * finalize() compares the assembled size against the total declared at plan
 * time. That catches truncation, missing bytes, and resume-offset bugs; it
 * does not read the artifact back, so finalize() costs the same for a 1 KB
 * file and a 50 GB dump. Corruption that preserves length — including a
 * staging file that shrank between requests and was zero-filled back to the
 * committed size by the next append's ftruncate — is not detected, the same
 * trust pull's writer places in its local disk. The wire belongs to TLS and
 * request authorization to the control plane's HMAC.
 *
 * Layout and state follow the pull importer's mechanics. Artifact bytes live
 * at their plain target-relative paths under files/ — no suffixes, so any
 * name a site can contain stages verbatim — and progress lives outside the
 * mirror: state.json holds the cursor for the single in-flight artifact and
 * verified.jsonl appends one line per artifact finalize() accepted.
 *
 * Transfers are sequential, like pull: progress is tracked for one artifact
 * at a time — the one currently being uploaded. That artifact can be
 * interrupted and resumed freely, because the cursor survives across
 * requests. Starting a different artifact forgets the unfinished one's
 * progress: its partial bytes stay under files/, but returning to it means
 * re-uploading from offset 0. Senders must finish or discard one artifact
 * before starting the next; interleaving two uploads is not supported.
 *
 * Locking: every mutator — append(), finalize(), discard() — holds one
 * exclusive non-blocking flock on the lock file for its single step and
 * releases it before returning. This is not for parallelism (transfers are
 * sequential); it exists so a retry racing its timed-out predecessor gets
 * "busy" instead of interleaving writes. The lock needs its own
 * never-replaced file: state.json commits by rename, and renaming a locked
 * file strands the held flock on the orphaned inode while the next opener
 * locks the fresh one. Readers stay lock-free — state.json is rename-atomic,
 * verified.jsonl is append-only, and committed_bytes only grows — so
 * status() always reads a safe resume hint.
 *
 * Contract:
 *
 * - Appends are sequential: a buffer is accepted only at the committed
 *   offset, whole or not at all. Re-sending already-committed bytes is an
 *   idempotent no-op ("duplicate"), and every response carries
 *   committed_bytes so an out-of-sync sender can resume at the right offset.
 * - Bytes become committed only after they are flushed and the cursor
 *   record moved, in that order — a crash mid-step leaves an uncommitted
 *   tail that the next append discards.
 * - finalize() compares the assembled size against the plan-declared total
 *   before recording the artifact in verified.jsonl; append() refuses
 *   verified artifacts.
 * - An artifact id is the files/-relative path the artifact will be applied
 *   from, mirroring the target tree — apply resolves artifacts by id, never
 *   by enumerating the staging directory. Ids with absolute, empty, "." or
 *   ".." segments or any backslash are rejected — stricter than the
 *   importer's fs-root path rule, which tolerates backslashes and empty
 *   segments.
 *
 * The endpoint owns authentication, request-size limits, and buffer sizing.
 * It must also place the staging directory outside the web-served tree, and
 * preferably on the same filesystem as the apply target so the apply step
 * can move verified artifacts with an atomic rename().
 */
final class Site_Export_Staged_Artifacts {

    /** @var string */
    private $files_dir;

    /** @var string */
    private $state_path;

    /** @var string */
    private $verified_path;

    /** @var string */
    private $lock_path;

    public function __construct(string $staging_dir) {
        $base = rtrim($staging_dir, '/');
        $this->files_dir = $base . '/files';
        $this->state_path = $base . '/state.json';
        $this->verified_path = $base . '/verified.jsonl';
        $this->lock_path = $base . '/lock';
    }

    /**
     * Append one caller-provided buffer at the committed offset.
     *
     * One call is one reentrant step: validate, lock, write the whole
     * buffer, flush, move the cursor, unlock. The caller's loop reads its
     * source and sizes the buffers; a "duplicate" response means these
     * bytes are already committed and the caller should skip forward in
     * its own source and continue from committed_bytes.
     *
     * @param string $artifact_id Opaque artifact identifier.
     * @param int    $offset      Byte offset this buffer starts at.
     * @param string $bytes       The buffer to append.
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     *   status "accepted"|"duplicate"|"busy"|"rejected"; reason is set on
     *   "rejected", and detail names the failing operation when the same
     *   reason can come from more than one place.
     */
    public function append(string $artifact_id, int $offset, string $bytes): array {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return [
                'status' => 'rejected',
                'reason' => 'invalid_artifact_id',
                'detail' => null,
                'committed_bytes' => 0,
            ];
        }
        if ($offset < 0) {
            return [
                'status' => 'rejected',
                'reason' => 'invalid_offset',
                'detail' => null,
                'committed_bytes' => 0,
            ];
        }
        if ($bytes === '') {
            return [
                'status' => 'rejected',
                'reason' => 'empty_body',
                'detail' => null,
                'committed_bytes' => 0,
            ];
        }

        $lock = $this->open_lock();
        if ($lock === false) {
            return [
                'status' => 'rejected',
                'reason' => 'io_error',
                'detail' => 'open_lock_file',
                'committed_bytes' => 0,
            ];
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return [
                    'status' => 'busy',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $this->status($artifact_id)['committed_bytes'],
                ];
            }

            $verified = $this->read_verified();
            if (isset($verified[$artifact_id])) {
                return [
                    'status' => 'rejected',
                    'reason' => 'already_verified',
                    'detail' => null,
                    'committed_bytes' => $verified[$artifact_id],
                ];
            }

            // Sequential transfers: the cursor tracks one artifact. An
            // append to any other artifact starts it from scratch.
            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;

            if ($offset + strlen($bytes) <= $committed) {
                return [
                    'status' => 'duplicate',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $committed,
                ];
            }
            if ($offset !== $committed) {
                return [
                    'status' => 'rejected',
                    'reason' => 'offset_gap',
                    'detail' => null,
                    'committed_bytes' => $committed,
                ];
            }

            if (!$this->ensure_parent_dir($file_path)) {
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'create_staging_dir',
                    'committed_bytes' => $committed,
                ];
            }

            // Open without truncating: a resumed transfer must keep committed
            // bytes until the cursor decides what tail to discard.
            $file = @fopen($file_path, 'c+b');
            if ($file === false) {
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'open_artifact_file',
                    'committed_bytes' => $committed,
                ];
            }

            try {
                // Discard any uncommitted tail from an interrupted earlier
                // step, then append at the only offset the cursor says is
                // committed.
                if (!ftruncate($file, $committed) || fseek($file, $committed) !== 0) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'io_error',
                        'detail' => 'truncate_uncommitted_tail',
                        'committed_bytes' => $committed,
                    ];
                }

                if (fwrite($file, $bytes) !== strlen($bytes)) {
                    // A partial write leaves bytes past the committed offset;
                    // trim them before the caller retries this step.
                    ftruncate($file, $committed);
                    return [
                        'status' => 'rejected',
                        'reason' => 'io_error',
                        'detail' => 'write_body',
                        'committed_bytes' => $committed,
                    ];
                }

                // The data is flushed before the cursor record moves: a crash
                // between the two leaves a tail that the next append truncates.
                if (!fflush($file)) {
                    ftruncate($file, $committed);
                    return [
                        'status' => 'rejected',
                        'reason' => 'io_error',
                        'detail' => 'flush_body',
                        'committed_bytes' => $committed,
                    ];
                }

                if (!$this->write_state($artifact_id, $committed + strlen($bytes))) {
                    ftruncate($file, $committed);
                    return [
                        'status' => 'rejected',
                        'reason' => 'io_error',
                        'detail' => 'persist_cursor',
                        'committed_bytes' => $committed,
                    ];
                }

                return [
                    'status' => 'accepted',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $committed + strlen($bytes),
                ];
            } finally {
                fclose($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Confirm the assembled artifact against its declared size and record it
     * as applyable.
     *
     * Idempotent: finalizing an already-verified artifact with the same size
     * succeeds again.
     *
     * $expected_total_bytes is the size declared when the transfer was
     * planned. The check never reads the artifact back, so it costs the same
     * regardless of artifact size.
     *
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int,path:?string}
     *   status "verified"|"busy"|"rejected"; path is set on "verified", and
     *   detail names the failing operation when the same reason can come
     *   from more than one place.
     */
    public function finalize(string $artifact_id, int $expected_total_bytes): array {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return [
                'status' => 'rejected',
                'reason' => 'invalid_artifact_id',
                'detail' => null,
                'committed_bytes' => 0,
                'path' => null,
            ];
        }
        if ($expected_total_bytes < 0) {
            return [
                'status' => 'rejected',
                'reason' => 'invalid_total',
                'detail' => null,
                'committed_bytes' => 0,
                'path' => null,
            ];
        }

        $lock = $this->open_lock();
        if ($lock === false) {
            return [
                'status' => 'rejected',
                'reason' => 'io_error',
                'detail' => 'open_lock_file',
                'committed_bytes' => 0,
                'path' => null,
            ];
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return [
                    'status' => 'busy',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $this->status($artifact_id)['committed_bytes'],
                    'path' => null,
                ];
            }

            $verified = $this->read_verified();
            if (isset($verified[$artifact_id])) {
                if ($verified[$artifact_id] === $expected_total_bytes) {
                    return [
                        'status' => 'verified',
                        'reason' => null,
                        'detail' => null,
                        'committed_bytes' => $verified[$artifact_id],
                        'path' => $file_path,
                    ];
                }
                return [
                    'status' => 'rejected',
                    'reason' => 'size_mismatch',
                    'detail' => 'verified_record',
                    'committed_bytes' => $verified[$artifact_id],
                    'path' => null,
                ];
            }

            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;

            if (!file_exists($file_path)) {
                // A zero-byte artifact legitimately has no appends; the fopen
                // below creates its empty file.
                if ($expected_total_bytes > 0) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'missing',
                        'detail' => null,
                        'committed_bytes' => $committed,
                        'path' => null,
                    ];
                }
                if (!$this->ensure_parent_dir($file_path)) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'io_error',
                        'detail' => 'create_staging_dir',
                        'committed_bytes' => 0,
                        'path' => null,
                    ];
                }
            }

            if ($committed !== $expected_total_bytes) {
                return [
                    'status' => 'rejected',
                    'reason' => 'size_mismatch',
                    'detail' => null,
                    'committed_bytes' => $committed,
                    'path' => null,
                ];
            }

            $file = @fopen($file_path, 'c+b');
            if ($file === false) {
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'open_artifact_file',
                    'committed_bytes' => $committed,
                    'path' => null,
                ];
            }
            // Drop any uncommitted tail so the artifact holds committed bytes only.
            $truncated = ftruncate($file, $committed);
            fclose($file);
            if (!$truncated) {
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'truncate_uncommitted_tail',
                    'committed_bytes' => $committed,
                    'path' => null,
                ];
            }

            if (!$this->append_verified($artifact_id, $expected_total_bytes)) {
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'persist_verified_record',
                    'committed_bytes' => $committed,
                    'path' => null,
                ];
            }
            if ($state['artifact_id'] === $artifact_id) {
                // Best effort: a stale cursor is harmless once the verified
                // record exists — already_verified wins on the next append.
                $this->write_state(null, 0);
            }

            return [
                'status' => 'verified',
                'reason' => null,
                'detail' => null,
                'committed_bytes' => $committed,
                'path' => $file_path,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Returns the recorded staging state for an artifact.
     *
     * This is advisory and intentionally lock-free; writers still enforce the
     * committed offset under the lock before accepting bytes.
     *
     * @return array{exists:bool,committed_bytes:int,verified:bool}
     */
    public function status(string $artifact_id): array {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return [
                'exists' => false,
                'committed_bytes' => 0,
                'verified' => false,
            ];
        }

        $verified = $this->read_verified();
        if (isset($verified[$artifact_id])) {
            return [
                'exists' => file_exists($file_path),
                'committed_bytes' => $verified[$artifact_id],
                'verified' => true,
            ];
        }

        $state = $this->read_state();
        return [
            'exists' => file_exists($file_path),
            'committed_bytes' => $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0,
            'verified' => false,
        ];
    }

    /**
     * Remove all staged data and records for an artifact. Safe to call for
     * unknown ids.
     *
     * @return bool False when a concurrent writer holds the store — an
     *              unguarded unlink would let that writer's commit resurrect
     *              a discarded artifact.
     */
    public function discard(string $artifact_id): bool {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return true;
        }

        // Discarding an artifact nothing knows about is a no-op and must not
        // create the staging scaffolding as a side effect.
        $state = $this->read_state();
        if (
            !file_exists($file_path)
            && $state['artifact_id'] !== $artifact_id
            && !isset($this->read_verified()[$artifact_id])
        ) {
            return true;
        }

        $lock = $this->open_lock();
        if ($lock === false) {
            return false;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }

            @unlink($file_path);
            $state = $this->read_state();
            if ($state['artifact_id'] === $artifact_id) {
                $this->write_state(null, 0);
            }
            $this->remove_verified($artifact_id);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Resolve an artifact id to its path under files/, or null when the id
     * is not a clean staging-relative path.
     */
    private function artifact_path(string $artifact_id): ?string {
        if ($artifact_id === '' || $artifact_id[0] === '/' || strpos($artifact_id, "\0") !== false || strpos($artifact_id, '\\') !== false) {
            return null;
        }
        foreach (explode('/', $artifact_id) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $this->files_dir . '/' . $artifact_id;
    }

    /**
     * Opens the flock target, creating the staging directory on first use.
     *
     * The lock file must never be written or rename-replaced: a rename
     * strands a held flock on the orphaned inode and lets the next opener
     * lock the store concurrently.
     *
     * @return resource|false
     */
    private function open_lock() {
        if (!$this->ensure_parent_dir($this->lock_path)) {
            return false;
        }
        return @fopen($this->lock_path, 'c+b');
    }

    private function ensure_parent_dir(string $path): bool {
        $dir = dirname($path);
        // A concurrent creator winning the mkdir race is success, not failure.
        return is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /**
     * Reads the cursor as best-effort state.
     *
     * A missing or unreadable record is treated as no artifact in flight, so
     * the next writer must restart its artifact from offset 0 instead of
     * trusting stale bytes.
     *
     * @return array{artifact_id:?string,committed_bytes:int}
     */
    private function read_state(): array {
        $defaults = [
            'artifact_id' => null,
            'committed_bytes' => 0,
        ];

        $raw = @file_get_contents($this->state_path);
        if ($raw === false) {
            return $defaults;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || !is_string($state['artifact_id'] ?? null)) {
            return $defaults;
        }

        $committed_bytes = isset($state['committed_bytes']) ? (int) $state['committed_bytes'] : 0;
        return [
            'artifact_id' => $state['artifact_id'],
            'committed_bytes' => max(0, $committed_bytes),
        ];
    }

    private function write_state(?string $artifact_id, int $committed_bytes): bool {
        // Write-then-rename keeps the cursor atomic: readers see the old
        // record or the new one, never a torn file. The temp file sits next
        // to the target so rename stays on the same filesystem.
        $tmp_path = $this->state_path . '.tmp';
        $json = json_encode([
            'artifact_id' => $artifact_id,
            'committed_bytes' => $committed_bytes,
        ]);
        // A short write (disk full) returns a byte count, not false — never
        // rename a torn record over the good one.
        if (@file_put_contents($tmp_path, $json) !== strlen($json)) {
            @unlink($tmp_path);
            return false;
        }
        if (!@rename($tmp_path, $this->state_path)) {
            @unlink($tmp_path);
            return false;
        }
        return true;
    }

    /**
     * Reads the verified-artifact records: artifact id to verified size.
     *
     * Malformed lines — a tail torn by a crash mid-append — are skipped, so
     * the worst case is re-finalizing an artifact, never trusting a torn
     * record.
     *
     * @return array<string,int>
     */
    private function read_verified(): array {
        $raw = @file_get_contents($this->verified_path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $records = [];
        foreach (explode("\n", $raw) as $line) {
            $record = json_decode($line, true);
            if (
                !is_array($record)
                || !is_string($record['artifact_id'] ?? null)
                || !is_int($record['size'] ?? null)
                || $record['size'] < 0
            ) {
                continue;
            }
            $records[$record['artifact_id']] = $record['size'];
        }
        return $records;
    }

    private function append_verified(string $artifact_id, int $size): bool {
        $line = json_encode([
            'artifact_id' => $artifact_id,
            'size' => $size,
        ]) . "\n";
        return @file_put_contents($this->verified_path, $line, FILE_APPEND) === strlen($line);
    }

    private function remove_verified(string $artifact_id): void {
        $records = $this->read_verified();
        if (!isset($records[$artifact_id])) {
            return;
        }
        unset($records[$artifact_id]);

        $lines = '';
        foreach ($records as $id => $size) {
            $lines .= json_encode([
                'artifact_id' => $id,
                'size' => $size,
            ]) . "\n";
        }
        $tmp_path = $this->verified_path . '.tmp';
        if (@file_put_contents($tmp_path, $lines) !== strlen($lines) || !@rename($tmp_path, $this->verified_path)) {
            @unlink($tmp_path);
        }
    }
}
