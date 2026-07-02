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
 * upload-specific: sequential offsets match pull's byte-offset resume model,
 * and adopting this store there mainly needs source-declared checksums in the
 * file index/fetch protocol.
 *
 * The checksums gate the apply step; they are not an auth scheme (the
 * control plane's HMAC owns authorization) and they do not guard the wire
 * (TLS does). They catch accidents neither can see: a sender reading the
 * wrong bytes (offset bugs, a live source file changing mid-transfer),
 * truncation in buffering layers behind the TLS edge, and the staging file
 * drifting between requests. finalize()'s whole-artifact checksum defines
 * "complete and correct"; the per-chunk checksum just localizes a bad chunk
 * to a one-chunk retry. CRC-32 is enough for that: the rest of the pipeline
 * moves data with no content hashing at all, nothing keys on content
 * digests, and error detection is the whole job.
 *
 * The sender hashes while reading and the store hashes while writing, so the
 * only extra pass is finalize() re-reading the artifact. That is deliberate:
 * chunk-time checks cannot see the staging file changing between requests
 * (a shrunken file is zero-filled back to the committed size by the next
 * write's ftruncate), and PHP 7.4 cannot resume a hash context across
 * requests.
 *
 * Layout and state follow the pull importer's mechanics. Artifact bytes live
 * at their plain target-relative paths under files/ — no suffixes, so any
 * name a site can contain stages verbatim — and progress lives outside the
 * mirror: state.json holds the cursor for the single in-flight artifact and
 * verified.jsonl appends one line per artifact finalize() accepted.
 *
 * Transfers are sequential, like pull: one artifact is in flight at a time
 * and the cursor tracks only it. Writing chunk 0 of another artifact moves
 * the cursor there; an unfinished predecessor keeps its bytes on disk but
 * restarts from offset 0 when the sender returns to it.
 *
 * Locking: every mutator — write_chunks(), finalize(), discard() — holds one
 * exclusive non-blocking flock on the lock file for its whole call, so a
 * batch keeps the store to itself from its first chunk to its last. This is
 * not for parallelism (transfers are sequential); it exists so a retry
 * racing its timed-out predecessor gets "busy" instead of interleaving
 * writes. The lock needs its own never-replaced file: state.json commits by
 * rename, and renaming a locked file strands the held flock on the orphaned
 * inode while the next opener locks the fresh one. Readers stay lock-free —
 * state.json is rename-atomic, verified.jsonl is append-only, and
 * committed_bytes only grows — so status() always reads a safe resume hint.
 *
 * Contract:
 *
 * - Chunks are sequential. A chunk is accepted only at the committed offset.
 *   Retrying an already-committed chunk is an idempotent no-op ("duplicate"),
 *   and every response carries committed_bytes so an out-of-sync uploader can
 *   resume at the right offset.
 * - Bytes become committed only after the chunk's CRC-32 matched. The data
 *   is flushed before the cursor record moves, so a crash mid-chunk leaves
 *   an uncommitted tail that the next write discards.
 * - finalize() re-hashes the assembled artifact and compares size and checksum
 *   before recording it in verified.jsonl; write_chunks() refuses verified
 *   artifacts.
 * - An artifact id is the files/-relative path the artifact will be applied
 *   from, mirroring the target tree — apply resolves artifacts by id, never
 *   by enumerating the staging directory. Ids with absolute, empty, "." or
 *   ".." segments or any backslash are rejected — stricter than the
 *   importer's fs-root path rule, which tolerates backslashes and empty
 *   segments.
 *
 * The endpoint owns authentication and request-size limits. It must also
 * place the staging directory outside the web-served tree, and preferably on
 * the same filesystem as the apply target so the apply step can move
 * verified artifacts with an atomic rename().
 */
final class Site_Export_Staged_Artifacts {

    private const WRITE_BUFFER_BYTES = 262144;

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
     * Stage one chunk of an artifact at the given offset.
     *
     * @param string          $artifact_id     Opaque artifact identifier.
     * @param int             $offset          Byte offset this chunk starts at.
     * @param int             $length          Declared chunk length in bytes.
     * @param string          $expected_crc32  Hex CRC-32 (crc32b) of the chunk body.
     * @param resource|string $source          Chunk body, or a readable stream positioned at it.
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     *   status "accepted"|"duplicate"|"busy"|"rejected"; reason is set on
     *   "rejected", and detail names the failing operation when the same
     *   reason can come from more than one place.
     */
    public function write_chunk(string $artifact_id, int $offset, int $length, string $expected_crc32, $source): array {
        return $this->write_chunks($artifact_id, [
            [
                'offset' => $offset,
                'length' => $length,
                'expected_crc32' => $expected_crc32,
                'source' => $source,
            ],
        ]);
    }

    /**
     * Stage consecutive chunks from one request while keeping the artifact
     * file open and the store locked.
     *
     * The upload endpoint can pass a generator that yields parsed chunks from
     * php://input, so a 100- or 1000-chunk request pays the open/flock/tail
     * cleanup cost once while still committing each chunk independently for
     * crash-safe resume.
     *
     * Because chunks commit one by one, a mid-batch rejection loses nothing:
     * committed_bytes reports the durable progress made before the bad chunk,
     * and the sender resumes from there.
     *
     * The iterable is also the pause point: a generator can simply stop
     * yielding when the endpoint's resource budget runs out, and the
     * response's committed_bytes tells the sender where the next request
     * should resume.
     *
     * @param iterable<int,array{offset:int,length:int,expected_crc32:string,source:resource|string}> $chunks
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     *   status "accepted"|"duplicate"|"busy"|"rejected"; reason is set on
     *   "rejected", and detail names the failing operation when the same
     *   reason can come from more than one place.
     */
    public function write_chunks(string $artifact_id, iterable $chunks): array {
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return $this->write_result('rejected', 'invalid_artifact_id', 0);
        }

        $lock = $this->open_lock();
        if ($lock === false) {
            return $this->write_result('rejected', 'io_error', 0, 'open_lock_file');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->write_result('busy', null, $this->status($artifact_id)['committed_bytes']);
            }

            $verified = $this->read_verified();
            if (isset($verified[$artifact_id])) {
                return $this->write_result('rejected', 'already_verified', $verified[$artifact_id]['size']);
            }

            // Sequential transfers: the cursor tracks one artifact. A write
            // to any other artifact starts it from scratch.
            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;

            if (!$this->ensure_parent_dir($file_path)) {
                return $this->write_result('rejected', 'io_error', $committed, 'create_staging_dir');
            }

            // Open without truncating: a resumed transfer must keep committed
            // bytes until the cursor decides what tail to discard.
            $file = @fopen($file_path, 'c+b');
            if ($file === false) {
                return $this->write_result('rejected', 'io_error', $committed, 'open_artifact_file');
            }

            try {
                // Discard any uncommitted tail from an interrupted earlier
                // write, then append at the only offset the cursor says is
                // committed.
                if (!ftruncate($file, $committed) || fseek($file, $committed) !== 0) {
                    return $this->write_result('rejected', 'io_error', $committed, 'truncate_uncommitted_tail');
                }

                $accepted = false;
                $duplicate = false;
                foreach ($chunks as $chunk) {
                    // These parameters arrive verbatim from a remote client's
                    // request, and the sequencing/copy logic below assumes they
                    // are well-formed — a negative offset would misreport as
                    // "duplicate" success and a short string body would never
                    // finish copying. Reject malformed input with typed reasons
                    // before writing bytes.
                    $offset = isset($chunk['offset']) ? (int) $chunk['offset'] : -1;
                    $length = isset($chunk['length']) ? (int) $chunk['length'] : 0;
                    $expected_crc32 = isset($chunk['expected_crc32']) ? (string) $chunk['expected_crc32'] : '';
                    $expected_crc32 = strtolower($expected_crc32);
                    $source = $chunk['source'] ?? null;

                    if ($length < 1) {
                        return $this->write_result('rejected', 'invalid_length', $committed);
                    }
                    if ($offset < 0) {
                        return $this->write_result('rejected', 'invalid_offset', $committed);
                    }
                    if (!preg_match('/^[0-9a-f]{8}$/', $expected_crc32)) {
                        return $this->write_result('rejected', 'invalid_hash', $committed);
                    }
                    if (is_string($source) && strlen($source) !== $length) {
                        return $this->write_result('rejected', 'length_mismatch', $committed);
                    }
                    if (!is_string($source) && !is_resource($source)) {
                        return $this->write_result('rejected', 'invalid_source', $committed);
                    }
                    if ($offset + $length <= $committed) {
                        $drain_reason = $this->drain_source($source, $length);
                        if ($drain_reason !== null) {
                            return $this->write_result('rejected', $drain_reason, $committed, 'duplicate_drain');
                        }
                        $duplicate = true;
                        continue;
                    }
                    if ($offset !== $committed) {
                        return $this->write_result('rejected', 'offset_gap', $committed);
                    }

                    $copy_reason = $this->copy_and_hash($source, $file, $length, $expected_crc32);
                    if ($copy_reason !== null) {
                        // A failed copy/hash can leave bytes past the committed
                        // offset; trim them before the caller retries this chunk.
                        ftruncate($file, $committed);
                        return $this->write_result('rejected', $copy_reason, $committed, 'chunk_body');
                    }

                    // The data is flushed before the cursor record moves: a crash
                    // between the two leaves a tail that the next write truncates.
                    if (!fflush($file)) {
                        ftruncate($file, $committed);
                        return $this->write_result('rejected', 'io_error', $committed, 'flush_chunk_body');
                    }

                    if (!$this->write_state($artifact_id, $committed + $length)) {
                        ftruncate($file, $committed);
                        return $this->write_result('rejected', 'io_error', $committed, 'persist_cursor');
                    }

                    $committed += $length;
                    $accepted = true;
                }

                if ($accepted) {
                    return $this->write_result('accepted', null, $committed);
                }
                if ($duplicate) {
                    return $this->write_result('duplicate', null, $committed);
                }
                return $this->write_result('rejected', 'empty_batch', $committed);
            } finally {
                fclose($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Verify the assembled artifact and record it as applyable.
     *
     * Idempotent: finalizing an already-verified artifact with the same size
     * and checksum succeeds again.
     *
     * $expected_crc32 must be the checksum declared when the transfer was
     * planned, before any bytes moved. A checksum the sender computes while
     * reading cannot catch a resume that mixed two versions of the source
     * file — it would faithfully hash the mixed bytes.
     *
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int,path:?string}
     *   status "verified"|"busy"|"rejected"; path is set on "verified", and
     *   detail names the failing operation when the same reason can come
     *   from more than one place.
     */
    public function finalize(string $artifact_id, int $expected_total_bytes, string $expected_crc32): array {
        $expected_crc32 = strtolower($expected_crc32);
        $file_path = $this->artifact_path($artifact_id);
        if ($file_path === null) {
            return $this->finalize_result('rejected', 'invalid_artifact_id', 0);
        }
        if (!preg_match('/^[0-9a-f]{8}$/', $expected_crc32)) {
            return $this->finalize_result('rejected', 'invalid_hash', 0);
        }
        if ($expected_total_bytes < 0) {
            return $this->finalize_result('rejected', 'invalid_total', 0);
        }

        $lock = $this->open_lock();
        if ($lock === false) {
            return $this->finalize_result('rejected', 'io_error', 0, 'open_lock_file');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->finalize_result('busy', null, $this->status($artifact_id)['committed_bytes']);
            }

            $verified = $this->read_verified();
            if (isset($verified[$artifact_id])) {
                $record = $verified[$artifact_id];
                $same = $record['size'] === $expected_total_bytes
                    && hash_equals($record['crc32'], $expected_crc32);
                return $same
                    ? $this->finalize_result('verified', null, $record['size'], null, $file_path)
                    : $this->finalize_result('rejected', 'hash_mismatch', $record['size'], 'verified_record');
            }

            $state = $this->read_state();
            $committed = $state['artifact_id'] === $artifact_id ? $state['committed_bytes'] : 0;

            if (!file_exists($file_path)) {
                // A zero-byte artifact legitimately has no chunks; the fopen
                // below creates its empty file.
                if ($expected_total_bytes > 0) {
                    return $this->finalize_result('rejected', 'missing', $committed);
                }
                if (!$this->ensure_parent_dir($file_path)) {
                    return $this->finalize_result('rejected', 'io_error', 0, 'create_staging_dir');
                }
            }

            if ($committed !== $expected_total_bytes) {
                return $this->finalize_result('rejected', 'size_mismatch', $committed);
            }

            $file = @fopen($file_path, 'c+b');
            if ($file === false) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'open_artifact_file');
            }
            // Drop any uncommitted tail so the checksum covers committed bytes only.
            $truncated = ftruncate($file, $committed);
            fclose($file);
            if (!$truncated) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'truncate_uncommitted_tail');
            }

            // Reading through a second handle without locking the artifact
            // file is safe: every writer serializes on the store lock held
            // here.
            $actual_crc32 = hash_file('crc32b', $file_path);
            if ($actual_crc32 === false) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'artifact_crc32');
            }
            if (!hash_equals($expected_crc32, $actual_crc32)) {
                return $this->finalize_result('rejected', 'hash_mismatch', $committed, 'artifact_crc32');
            }

            if (!$this->append_verified($artifact_id, $expected_total_bytes, $expected_crc32)) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'persist_verified_record');
            }
            if ($state['artifact_id'] === $artifact_id) {
                // Best effort: a stale cursor is harmless once the verified
                // record exists — already_verified wins on the next write.
                $this->write_state(null, 0);
            }

            return $this->finalize_result('verified', null, $committed, null, $file_path);
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
                'committed_bytes' => $verified[$artifact_id]['size'],
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
     * Copies exactly $length bytes to the artifact file while hashing the
     * same bytes that landed on disk.
     *
     * @param resource|string $source
     * @return string|null Rejection reason, or null when $length bytes were
     *                     copied and their checksum matched.
     */
    private function copy_and_hash($source, $file, int $length, string $expected_crc32): ?string {
        $context = hash_init('crc32b');
        $remaining = $length;

        while ($remaining > 0) {
            // Read in bounded buffers so string and stream sources share the
            // same write path without materializing a large stream body.
            if (is_string($source)) {
                $buffer = substr($source, $length - $remaining, min($remaining, self::WRITE_BUFFER_BYTES));
            } else {
                $buffer = fread($source, min($remaining, self::WRITE_BUFFER_BYTES));
                if ($buffer === false || $buffer === '') {
                    return 'short_body';
                }
            }

            hash_update($context, $buffer);
            if (fwrite($file, $buffer) !== strlen($buffer)) {
                return 'io_error';
            }
            $remaining -= strlen($buffer);
        }

        if (!hash_equals($expected_crc32, hash_final($context))) {
            return 'hash_mismatch';
        }

        return null;
    }

    /**
     * Consumes exactly $length bytes from a duplicate chunk stream.
     *
     * Duplicate chunks are not written or re-hashed, but a batched upload may
     * be reading consecutive chunk bodies from one request stream. Draining
     * keeps the parser aligned for the next chunk.
     *
     * @param resource|string $source
     * @return string|null Rejection reason, or null when there is no stream
     *                     body left for this store to consume.
     */
    private function drain_source($source, int $length): ?string {
        if (is_string($source)) {
            return null;
        }

        $remaining = $length;
        while ($remaining > 0) {
            $buffer = fread($source, min($remaining, self::WRITE_BUFFER_BYTES));
            if ($buffer === false || $buffer === '') {
                return 'short_body';
            }
            $remaining -= strlen($buffer);
        }

        return null;
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
     * Reads the verified-artifact records, keyed by artifact id.
     *
     * Malformed lines — a tail torn by a crash mid-append — are skipped, so
     * the worst case is re-verifying an artifact, never trusting a torn record.
     *
     * @return array<string,array{size:int,crc32:string}>
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
                || !is_string($record['crc32'] ?? null)
            ) {
                continue;
            }
            $records[$record['artifact_id']] = [
                'size' => $record['size'],
                'crc32' => $record['crc32'],
            ];
        }
        return $records;
    }

    private function append_verified(string $artifact_id, int $size, string $crc32): bool {
        $line = json_encode([
            'artifact_id' => $artifact_id,
            'size' => $size,
            'crc32' => $crc32,
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
        foreach ($records as $id => $record) {
            $lines .= json_encode([
                'artifact_id' => $id,
                'size' => $record['size'],
                'crc32' => $record['crc32'],
            ]) . "\n";
        }
        $tmp_path = $this->verified_path . '.tmp';
        if (@file_put_contents($tmp_path, $lines) !== strlen($lines) || !@rename($tmp_path, $this->verified_path)) {
            @unlink($tmp_path);
        }
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     */
    private function write_result(string $status, ?string $reason, int $committed_bytes, ?string $detail = null): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'committed_bytes' => $committed_bytes,
        ];
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int,path:?string}
     */
    private function finalize_result(string $status, ?string $reason, int $committed_bytes, ?string $detail = null, ?string $path = null): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'committed_bytes' => $committed_bytes,
            'path' => $path,
        ];
    }
}
