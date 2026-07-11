<?php

/**
 * Staged storage for artifact transfers.
 *
 * A transfer moves many files plus database changes at network speed and can
 * be interrupted or aborted at any point — the live site must never see that
 * as half-applied state, and partial content must never exist under the
 * web-served tree, where the server would execute a half-uploaded plugin
 * file. So staging exists for apply atomicity and containment,
 * not for authentication: nothing a transfer receives touches the site
 * directly, the bytes accumulate here while the site keeps running, aborting
 * before apply is free (discard the staged data), and the apply step later
 * moves verified artifacts into place in one short, controlled window
 * instead of mutating the live tree for the duration of the transfer.
 *
 * The first consumer is push: bounded frames from an outbound-only local
 * site, sized by the push client's chunk reads on the importer side. The
 * pull file writer has the same needs whenever its target is a live site — today it
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
 * file and a 50 GB dump. Corruption that preserves length is not detected,
 * the same trust pull's writer places in its local disk. A missing, shortened,
 * or non-regular file behind a committed cursor is rejected as damaged
 * staging instead of being extended with zero bytes. The wire belongs to TLS,
 * and whether a caller may talk to the endpoint at all is checked by
 * Site_Export_HMAC_Server before any of this code runs.
 *
 * Layout and state follow the pull importer's mechanics. Artifact bytes live
 * at their plain target-relative paths under files/ — no suffixes, so any
 * name a site can contain stages verbatim — and progress lives outside the
 * mirror: state.json holds the cursor for the single in-flight artifact,
 * and each artifact finalize() accepted has a marker at the same relative
 * path under verified/, holding the verified size. Markers, not a shared
 * log, because the already-verified check runs on every append: it reads
 * one known path per call no matter how many artifacts a transfer has
 * finished, where a log would be re-read and re-parsed in full each time
 * and a 50k-file push would spend its appends parsing it.
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
 * locks the fresh one. Readers stay lock-free — state.json and verified
 * markers appear whole or not at all (both commit by rename), and a hint
 * that goes stale mid-read (a discard or finalize can reset the cursor)
 * is corrected by the next append's duplicate or offset_gap answer — so
 * status() is always a safe starting point for a resume.
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
 *   before writing the artifact's verified marker; append() refuses
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
    private $verified_dir;

    /** @var string */
    private $verified_tmp_path;

    /** @var string */
    private $lock_path;

    public function __construct(string $staging_dir) {
        $base = rtrim($staging_dir, '/');
        $this->files_dir = $base . '/files';
        $this->state_path = $base . '/state.json';
        $this->verified_dir = $base . '/verified';
        // One reusable temp file, outside verified/: a marker's own ".tmp"
        // sibling would collide with the marker of an artifact actually
        // named that way. Marker writes happen under the lock, so a single
        // temp path cannot race itself.
        $this->verified_tmp_path = $base . '/verified.tmp';
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
     * @param string $artifact_id The file's target-relative path, e.g.
     *                            "wp-content/themes/foo/style.css".
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
                    'reason' => 'busy',
                    'detail' => null,
                    'committed_bytes' => $this->status($artifact_id)['committed_bytes'],
                ];
            }

            $verified_size = $this->read_verified_size($artifact_id);
            if ($verified_size !== null) {
                return [
                    'status' => 'rejected',
                    'reason' => 'already_verified',
                    'detail' => null,
                    'committed_bytes' => $verified_size,
                ];
            }

            // Sequential transfers: the cursor tracks one artifact. An
            // append to any other artifact starts it from scratch.
            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;
            $staging_file_diagnostic = $this->diagnose_staging_file($file_path, $committed);
            if ($staging_file_diagnostic !== null) {
                return [
                    'status' => 'rejected',
                    'reason' => 'staging_file_damaged',
                    'detail' => $staging_file_diagnostic,
                    'committed_bytes' => 0,
                ];
            }

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
            $file = @fopen($file_path, $committed > 0 ? 'r+b' : 'c+b');
            if ($file === false) {
                $staging_file_diagnostic = $this->diagnose_staging_file($file_path, $committed);
                if ($staging_file_diagnostic !== null) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'staging_file_damaged',
                        'detail' => $staging_file_diagnostic,
                        'committed_bytes' => 0,
                    ];
                }
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'open_artifact_file',
                    'committed_bytes' => $committed,
                ];
            }

            try {
                $staging_file_diagnostic = $this->diagnose_staging_file($file, $committed);
                if ($staging_file_diagnostic !== null) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'staging_file_damaged',
                        'detail' => $staging_file_diagnostic,
                        'committed_bytes' => 0,
                    ];
                }

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
                    'reason' => 'busy',
                    'detail' => null,
                    'committed_bytes' => $this->status($artifact_id)['committed_bytes'],
                    'path' => null,
                ];
            }

            $verified_size = $this->read_verified_size($artifact_id);
            if ($verified_size !== null) {
                // The record can outlive the file — a discard killed between
                // its steps, or external cleanup. There is nothing left to
                // apply, so verified must not be re-affirmed.
                if (!file_exists($file_path)) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'missing',
                        'detail' => 'verified_record',
                        'committed_bytes' => $verified_size,
                        'path' => null,
                    ];
                }
                if ($verified_size === $expected_total_bytes) {
                    return [
                        'status' => 'verified',
                        'reason' => null,
                        'detail' => null,
                        'committed_bytes' => $verified_size,
                        'path' => $file_path,
                    ];
                }
                return [
                    'status' => 'rejected',
                    'reason' => 'size_mismatch',
                    'detail' => 'verified_record',
                    'committed_bytes' => $verified_size,
                    'path' => null,
                ];
            }

            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;
            $staging_file_diagnostic = $this->diagnose_staging_file($file_path, $committed);
            if ($staging_file_diagnostic !== null) {
                return [
                    'status' => 'rejected',
                    'reason' => 'staging_file_damaged',
                    'detail' => $staging_file_diagnostic,
                    'committed_bytes' => 0,
                    'path' => null,
                ];
            }

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
                        'committed_bytes' => $committed,
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

            $file = @fopen($file_path, $committed > 0 ? 'r+b' : 'c+b');
            if ($file === false) {
                $staging_file_diagnostic = $this->diagnose_staging_file($file_path, $committed);
                if ($staging_file_diagnostic !== null) {
                    return [
                        'status' => 'rejected',
                        'reason' => 'staging_file_damaged',
                        'detail' => $staging_file_diagnostic,
                        'committed_bytes' => 0,
                        'path' => null,
                    ];
                }
                return [
                    'status' => 'rejected',
                    'reason' => 'io_error',
                    'detail' => 'open_artifact_file',
                    'committed_bytes' => $committed,
                    'path' => null,
                ];
            }
            $staging_file_diagnostic = $this->diagnose_staging_file($file, $committed);
            if ($staging_file_diagnostic !== null) {
                fclose($file);
                return [
                    'status' => 'rejected',
                    'reason' => 'staging_file_damaged',
                    'detail' => $staging_file_diagnostic,
                    'committed_bytes' => 0,
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

            if (!$this->write_verified_marker($artifact_id, $expected_total_bytes)) {
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
     * When the recorded cursor has lost its backing file, committed_bytes is
     * zero and the response describes the damage and the untrusted old cursor.
     *
     * @return array{exists:bool,committed_bytes:int,verified:bool,damage?:string,recorded_committed_bytes?:int}
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

        $verified_size = $this->read_verified_size($artifact_id);
        if ($verified_size !== null) {
            return [
                'exists' => file_exists($file_path),
                'committed_bytes' => $verified_size,
                'verified' => true,
            ];
        }

        $state = $this->read_state();
        $committed_bytes = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;
        $staging_file_diagnostic = $this->diagnose_staging_file($file_path, $committed_bytes);
        $status = [
            'exists' => file_exists($file_path),
            'committed_bytes' => $staging_file_diagnostic === null ? $committed_bytes : 0,
            'verified' => false,
        ];
        if ($staging_file_diagnostic !== null) {
            $status['damage'] = $staging_file_diagnostic;
            $status['recorded_committed_bytes'] = $committed_bytes;
        }
        return $status;
    }

    /**
     * Remove all staged data and records for an artifact. Safe to call for
     * unknown ids.
     *
     * Cursor and verification records are removed before the artifact. A kill
     * or failed unlink can therefore leave untrusted bytes behind, but never a
     * durable cursor or marker that authorizes bytes already removed. Retry
     * until true to finish removing any such orphan.
     *
     * @return bool False when a concurrent writer holds the store (an
     *              unguarded unlink would let that writer's commit resurrect
     *              a discarded artifact) or when a cleanup step failed and
     *              staged data remains.
     */
    public function discard(string $artifact_id): bool {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return true;
        }

        // A missing staging directory proves nothing is staged and no writer
        // is mid-step, so this one no-op may skip locking (open_lock() would
        // create the scaffolding as a side effect). Every other check belongs
        // under the lock: a first append can be in flight with no trace on
        // disk yet, and its commit would resurrect an artifact this call just
        // reported discarded.
        if (!is_dir(dirname($this->lock_path))) {
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

            $state = $this->read_state();
            if ($state['artifact_id'] === $artifact_id && !$this->write_state(null, 0)) {
                return false;
            }
            if (!$this->remove_verified_marker($artifact_id)) {
                return false;
            }
            return !( file_exists($file_path) || is_link($file_path) ) || @unlink($file_path);
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
        $this->ensure_web_guards(dirname($this->lock_path));
        return @fopen($this->lock_path, 'c+b');
    }

    /**
     * Writes deny rules for staging directories inside the document root.
     * They are all we can do from here: Apache reads the .htaccess and
     * refuses to serve the directory; nginx ignores both files and loses
     * nothing by their presence. Do not keep the staging directory inside
     * the document root unless the host offers nowhere else to write.
     *
     * Write failures are ignored: the store works the same without the
     * files, and the index exclusion of storage_path still applies.
     */
    private function ensure_web_guards(string $dir): void {
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Reprint staging area - never web-served.\n" .
                "<IfModule mod_authz_core.c>\n" .
                "    Require all denied\n" .
                "</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n" .
                "    Deny from all\n" .
                "</IfModule>\n"
            );
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n");
        }
    }

    private function ensure_parent_dir(string $path): bool {
        $dir = dirname($path);
        // A concurrent creator winning the mkdir race is success, not failure.
        return is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /**
     * Diagnose whether a nonzero cursor still has trustworthy backing bytes.
     * A longer file is valid: it is an uncommitted tail left by an interrupted
     * append and the next writer truncates it back to the cursor.
     *
     * A path is inspected with lstat() so links are never followed. An open
     * handle is rechecked with fstat() before ftruncate() can extend it: the
     * path check and open are separate operations, so cleanup may race them.
     *
     * @param string|resource $staging_file_path_or_handle
     */
    private function diagnose_staging_file($staging_file_path_or_handle, int $committed_bytes): ?string {
        if ($committed_bytes === 0) {
            return null;
        }

        if (is_string($staging_file_path_or_handle)) {
            $file_stat = @lstat($staging_file_path_or_handle);
            if (!is_array($file_stat)) {
                return 'staging_file_missing_at_cursor';
            }
        } else {
            $file_stat = @fstat($staging_file_path_or_handle);
        }
        if (
            !is_array($file_stat)
            || !isset($file_stat['mode'])
            || ( ( (int) $file_stat['mode'] & 0170000 ) !== 0100000 )
        ) {
            return 'staging_file_not_regular';
        }
        if (!isset($file_stat['size']) || (int) $file_stat['size'] < $committed_bytes) {
            return 'staging_file_shorter_than_cursor';
        }
        return null;
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
        // Ids are stored base64 (see write_state); an undecodable record
        // reads as no state, like any other malformed record.
        $artifact_id = base64_decode($state['artifact_id'], true);
        if ($artifact_id === false || $artifact_id === '') {
            return $defaults;
        }

        $committed_bytes = isset($state['committed_bytes']) ? (int) $state['committed_bytes'] : 0;
        return [
            'artifact_id' => $artifact_id,
            'committed_bytes' => max(0, $committed_bytes),
        ];
    }

    private function write_state(?string $artifact_id, int $committed_bytes): bool {
        // Write-then-rename keeps the cursor atomic: readers see the old
        // record or the new one, never a torn file. The temp file sits next
        // to the target so rename stays on the same filesystem.
        $tmp_path = $this->state_path . '.tmp';
        // The id is stored base64: file names are arbitrary bytes, JSON
        // strings must be UTF-8, and json_encode() returning false would
        // otherwise slip through the strlen() comparison below as an empty
        // record — committed_bytes would silently reset to 0 on every read.
        $json = json_encode([
            'artifact_id' => $artifact_id !== null ? base64_encode($artifact_id) : null,
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
     * The verified marker mirrors the artifact's relative path under
     * verified/, so lookup reads one known path regardless of transfer size.
     */
    private function verified_marker_path(string $artifact_id): string {
        return $this->verified_dir . '/' . $artifact_id;
    }

    /**
     * Reads an artifact's verified size, or null when it is not verified.
     *
     * A malformed marker — torn by a crash, since markers commit by rename
     * this means external interference — reads as not verified, so the
     * worst case is re-finalizing an artifact, never trusting a torn record.
     */
    private function read_verified_size(string $artifact_id): ?int {
        $raw = @file_get_contents($this->verified_marker_path($artifact_id));
        if ($raw === false) {
            return null;
        }
        $record = json_decode($raw, true);
        if (!is_array($record) || !is_int($record['size'] ?? null) || $record['size'] < 0) {
            return null;
        }
        return $record['size'];
    }

    private function write_verified_marker(string $artifact_id, int $size): bool {
        $marker_path = $this->verified_marker_path($artifact_id);
        if (!$this->ensure_parent_dir($marker_path)) {
            return false;
        }
        $json = json_encode(['size' => $size]);
        // Same discipline as the cursor: a short write (disk full) must
        // never become a marker, so write whole to the temp file and rename.
        if (@file_put_contents($this->verified_tmp_path, $json) !== strlen($json)) {
            @unlink($this->verified_tmp_path);
            return false;
        }
        if (!@rename($this->verified_tmp_path, $marker_path)) {
            @unlink($this->verified_tmp_path);
            return false;
        }
        return true;
    }

    private function remove_verified_marker(string $artifact_id): bool {
        $marker_path = $this->verified_marker_path($artifact_id);
        return !file_exists($marker_path) || @unlink($marker_path);
    }
}
