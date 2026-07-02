<?php

/**
 * Staged storage for chunked artifact transfers.
 *
 * A transfer moves many files plus database changes at network speed and can
 * be interrupted or aborted at any point — the live site must never see that
 * as half-applied state, and partial content must never exist under the
 * web-served tree, where the server would hand out a half-uploaded
 * "plugin.php.part" as plain text. So staging exists for apply atomicity and
 * containment, not for authentication: nothing a transfer receives touches
 * the site directly, the bytes accumulate here while the site keeps running,
 * aborting before apply is free (discard the staged data), and the apply
 * step later moves verified artifacts into place in one short, controlled
 * window instead of mutating the live tree for the duration of the transfer.
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
 * The sender hashes while reading and write_chunk() hashes while writing,
 * so the only extra pass is finalize() re-reading the artifact. That is
 * deliberate: chunk-time checks cannot see the staging file changing
 * between requests (a shrunken file is zero-filled back to the committed
 * size by the next write's ftruncate), and PHP 7.4 cannot resume a hash
 * context across requests.
 *
 * Contract:
 *
 * - Chunks are sequential. A chunk is accepted only at the committed offset.
 *   Retrying an already-committed chunk is an idempotent no-op ("duplicate"),
 *   and every response carries committed_bytes so an out-of-sync uploader can
 *   resume at the right offset.
 * - Bytes become committed only after the chunk's CRC-32 matched. The data
 *   is flushed before the commit record is updated, so a crash mid-chunk
 *   leaves an uncommitted tail that the next write discards.
 * - finalize() re-hashes the assembled artifact and compares size and checksum
 *   before marking it verified; write_chunk() refuses verified artifacts.
 * - An artifact id is the staging-relative path the artifact will be applied
 *   from, mirroring the target tree — the staging dir reads like the site
 *   and apply is a rename. Ids with absolute, empty, "." or ".." segments
 *   or any backslash are rejected — stricter than the importer's fs-root
 *   path rule, which tolerates backslashes and empty segments.
 * - Writers hold an exclusive flock on the staging file; a concurrent writer
 *   gets "busy" instead of interleaved writes.
 *
 * The endpoint owns authentication and request-size limits. It must also
 * place the staging directory outside the web-served tree, and preferably on
 * the same filesystem as the apply target so the apply step can move
 * verified artifacts with an atomic rename().
 */
final class Site_Export_Staged_Artifacts {

    private const WRITE_BUFFER_BYTES = 262144;

    /** @var string */
    private $staging_dir;

    public function __construct(string $staging_dir) {
        $this->staging_dir = rtrim($staging_dir, '/');
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
     * Stage consecutive chunks from one request while keeping the staging file
     * open and locked.
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
     * @param iterable<int,array{offset:int,length:int,expected_crc32:string,source:resource|string}> $chunks
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     *   status "accepted"|"duplicate"|"busy"|"rejected"; reason is set on
     *   "rejected", and detail names the failing operation when the same
     *   reason can come from more than one place.
     */
    public function write_chunks(string $artifact_id, iterable $chunks): array {
        $paths = $this->artifact_paths($artifact_id);
        if ($paths === null) {
            return $this->write_result('rejected', 'invalid_artifact_id', 0);
        }
        if (!$this->ensure_parent_dir($paths['part'])) {
            return $this->write_result('rejected', 'io_error', 0, 'create_staging_dir');
        }

        // Open without truncating: a resumed transfer must keep committed
        // bytes until the metadata check below decides what tail to discard.
        $part = @fopen($paths['part'], 'c+b');
        if ($part === false) {
            return $this->write_result('rejected', 'io_error', 0, 'open_staging_file');
        }

        try {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                return $this->write_result('busy', null, $this->read_meta($paths['meta'])['committed_bytes']);
            }

            $meta = $this->read_meta($paths['meta']);
            $committed = $meta['committed_bytes'];
            if ($meta['verified']) {
                return $this->write_result('rejected', 'already_verified', $committed);
            }

            // Discard any uncommitted tail from an interrupted earlier write,
            // then append at the only offset the metadata says is committed.
            if (!ftruncate($part, $committed) || fseek($part, $committed) !== 0) {
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

                $copy_reason = $this->copy_and_hash($source, $part, $length, $expected_crc32);
                if ($copy_reason !== null) {
                    // A failed copy/hash can leave bytes past the committed
                    // offset; trim them before the caller retries this chunk.
                    ftruncate($part, $committed);
                    return $this->write_result('rejected', $copy_reason, $committed, 'chunk_body');
                }

                // The data is flushed before the commit record moves: a crash
                // between the two leaves a tail that the next write truncates.
                if (!fflush($part)) {
                    ftruncate($part, $committed);
                    return $this->write_result('rejected', 'io_error', $committed, 'flush_chunk_body');
                }

                $meta['committed_bytes'] = $committed + $length;
                if (!$this->write_meta($paths['meta'], $meta)) {
                    ftruncate($part, $committed);
                    return $this->write_result('rejected', 'io_error', $committed, 'persist_commit_record');
                }

                $committed = $meta['committed_bytes'];
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
            flock($part, LOCK_UN);
            fclose($part);
        }
    }

    /**
     * Verify the assembled artifact and mark it applyable.
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
        $paths = $this->artifact_paths($artifact_id);
        if ($paths === null) {
            return $this->finalize_result('rejected', 'invalid_artifact_id', 0);
        }
        if (!preg_match('/^[0-9a-f]{8}$/', $expected_crc32)) {
            return $this->finalize_result('rejected', 'invalid_hash', 0);
        }
        if ($expected_total_bytes < 0) {
            return $this->finalize_result('rejected', 'invalid_total', 0);
        }

        $part_path = $paths['part'];
        if (!file_exists($part_path)) {
            // A zero-byte artifact legitimately has no chunks; the fopen
            // below creates its empty part file.
            if ($expected_total_bytes > 0) {
                return $this->finalize_result('rejected', 'missing', 0);
            }
            if (!$this->ensure_parent_dir($part_path)) {
                return $this->finalize_result('rejected', 'io_error', 0, 'create_staging_dir');
            }
        }

        $part = @fopen($part_path, 'c+b');
        if ($part === false) {
            return $this->finalize_result('rejected', 'io_error', 0, 'open_staging_file');
        }

        try {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                return $this->finalize_result('busy', null, $this->read_meta($paths['meta'])['committed_bytes']);
            }

            $meta = $this->read_meta($paths['meta']);
            $committed = $meta['committed_bytes'];
            if ($meta['verified']) {
                $same = $meta['verified_total_bytes'] === $expected_total_bytes
                    && is_string($meta['verified_crc32'])
                    && hash_equals($meta['verified_crc32'], $expected_crc32);
                return $same
                    ? $this->finalize_result('verified', null, $committed, null, $part_path)
                    : $this->finalize_result('rejected', 'hash_mismatch', $committed, 'verified_record');
            }

            if ($committed !== $expected_total_bytes) {
                return $this->finalize_result('rejected', 'size_mismatch', $committed);
            }

            // Drop any uncommitted tail so the checksum covers committed bytes only.
            if (!ftruncate($part, $committed)) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'truncate_uncommitted_tail');
            }

            // Hash while holding the staging lock. hash_file() uses its own
            // read handle, but all writers in this store respect this flock.
            $actual_crc32 = hash_file('crc32b', $part_path);
            if ($actual_crc32 === false) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'artifact_crc32');
            }
            if (!hash_equals($expected_crc32, $actual_crc32)) {
                return $this->finalize_result('rejected', 'hash_mismatch', $committed, 'artifact_crc32');
            }

            $meta['verified'] = true;
            $meta['verified_total_bytes'] = $expected_total_bytes;
            $meta['verified_crc32'] = $expected_crc32;
            if (!$this->write_meta($paths['meta'], $meta)) {
                return $this->finalize_result('rejected', 'io_error', $committed, 'persist_commit_record');
            }

            return $this->finalize_result('verified', null, $committed, null, $part_path);
        } finally {
            flock($part, LOCK_UN);
            fclose($part);
        }
    }

    /**
     * Returns the persisted staging state for an artifact.
     *
     * This is advisory and intentionally lock-free; writers still enforce the
     * committed offset under flock before accepting bytes.
     *
     * @return array{exists:bool,committed_bytes:int,verified:bool}
     */
    public function status(string $artifact_id): array {
        $paths = $this->artifact_paths($artifact_id);
        if ($paths === null) {
            return [
                'exists' => false,
                'committed_bytes' => 0,
                'verified' => false,
            ];
        }

        $meta = $this->read_meta($paths['meta']);
        return [
            'exists' => file_exists($paths['part']) || file_exists($paths['meta']),
            'committed_bytes' => $meta['committed_bytes'],
            'verified' => $meta['verified'],
        ];
    }

    /**
     * Remove all staged data for an artifact. Safe to call for unknown ids.
     *
     * @return bool False when a concurrent writer holds the artifact — an
     *              unguarded unlink would let that writer's commit resurrect
     *              an orphaned record.
     */
    public function discard(string $artifact_id): bool {
        $paths = $this->artifact_paths($artifact_id);
        if ($paths === null) {
            return true;
        }

        if (!file_exists($paths['part']) && !file_exists($paths['meta'])) {
            return true;
        }

        // 'c+b' so the lock exists even when only the meta record does, and
        // the meta is unlinked before the part while the lock is held — a
        // writer arriving after the part unlink locks a fresh inode, and
        // stale meta it could still read would resurrect its committed_bytes
        // over bytes that are gone.
        $part = @fopen($paths['part'], 'c+b');
        if ($part === false) {
            return false;
        }
        try {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                return false;
            }
            @unlink($paths['meta']);
            @unlink($paths['part']);
            return true;
        } finally {
            flock($part, LOCK_UN);
            fclose($part);
        }
    }

    /**
     * Copies exactly $length bytes to the staging file while hashing the same
     * bytes that landed on disk.
     *
     * @param resource|string $source
     * @return string|null Rejection reason, or null when $length bytes were
     *                     copied and their checksum matched.
     */
    private function copy_and_hash($source, $part, int $length, string $expected_crc32): ?string {
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
            if (fwrite($part, $buffer) !== strlen($buffer)) {
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
     * Resolve an artifact id to its staging file paths, or null when the id
     * is not a clean staging-relative path.
     */
    private function artifact_paths(string $artifact_id): ?array {
        if ($artifact_id === '' || $artifact_id[0] === '/' || strpos($artifact_id, "\0") !== false || strpos($artifact_id, '\\') !== false) {
            return null;
        }
        foreach (explode('/', $artifact_id) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $base = $this->staging_dir . '/' . $artifact_id;
        return [
            'part' => $base . '.part',
            'meta' => $base . '.meta.json',
        ];
    }

    private function ensure_parent_dir(string $path): bool {
        $dir = dirname($path);
        // A concurrent creator winning the mkdir race is success, not failure.
        return is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /**
     * Reads the commit record as best-effort state.
     *
     * A missing or unreadable record is treated as an empty artifact so the
     * next writer must restart from offset 0 instead of trusting stale bytes.
     *
     * @return array{committed_bytes:int,verified:bool,verified_total_bytes:?int,verified_crc32:?string}
     */
    private function read_meta(string $meta_path): array {
        $defaults = [
            'committed_bytes' => 0,
            'verified' => false,
            'verified_total_bytes' => null,
            'verified_crc32' => null,
        ];

        $raw = @file_get_contents($meta_path);
        if ($raw === false) {
            return $defaults;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return $defaults;
        }

        // Metadata is intentionally not trusted as schema: old/corrupt files
        // fall back to defaults after keeping only recognized keys.
        $meta = array_merge($defaults, array_intersect_key($meta, $defaults));
        $meta['committed_bytes'] = max(0, (int) $meta['committed_bytes']);
        $meta['verified'] = (bool) $meta['verified'];
        $meta['verified_total_bytes'] = $meta['verified_total_bytes'] !== null ? (int) $meta['verified_total_bytes'] : null;
        return $meta;
    }

    private function write_meta(string $meta_path, array $meta): bool {
        // Write-then-rename keeps the commit record atomic: readers see the
        // old record or the new one, never a torn file. Keep the temp file
        // next to the target so rename stays on the same filesystem.
        $tmp_path = $meta_path . '.tmp';
        $json = json_encode($meta);
        // A short write (disk full) returns a byte count, not false — never
        // rename a torn record over the good one.
        if (@file_put_contents($tmp_path, $json) !== strlen($json)) {
            @unlink($tmp_path);
            return false;
        }
        if (!@rename($tmp_path, $meta_path)) {
            @unlink($tmp_path);
            return false;
        }
        return true;
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
