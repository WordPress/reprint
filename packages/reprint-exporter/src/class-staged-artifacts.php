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
 *   are rejected, same as the importer's fs-root-relative path rule.
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
     * @return array{status:string,reason:?string,committed_bytes:int}
     *   status "accepted"|"duplicate"|"busy"|"rejected"; reason is set on "rejected".
     */
    public function write_chunk(string $artifact_id, int $offset, int $length, string $expected_crc32, $source): array {
        // These parameters arrive verbatim from a remote client's request,
        // and the sequencing/copy logic below assumes they are well-formed —
        // a negative offset would misreport as "duplicate" success and a
        // short string body would never finish copying. Reject malformed
        // input with typed reasons before taking the lock or touching disk.
        $expected_crc32 = strtolower($expected_crc32);
        $paths = $this->artifact_paths($artifact_id);
        if ($paths === null) {
            return $this->write_result('rejected', 'invalid_artifact_id', 0);
        }
        if ($length < 1 || $offset < 0) {
            return $this->write_result('rejected', 'invalid_length', 0);
        }
        if (!preg_match('/^[0-9a-f]{8}$/', $expected_crc32)) {
            return $this->write_result('rejected', 'invalid_hash', 0);
        }
        if (is_string($source) && strlen($source) !== $length) {
            return $this->write_result('rejected', 'length_mismatch', 0);
        }
        if (!is_string($source) && !is_resource($source)) {
            return $this->write_result('rejected', 'invalid_source', 0);
        }
        if (!$this->ensure_parent_dir($paths['part'])) {
            return $this->write_result('rejected', 'io_error', 0);
        }

        $part = @fopen($paths['part'], 'c+b');
        if ($part === false) {
            return $this->write_result('rejected', 'io_error', 0);
        }

        try {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                return $this->write_result('busy', null, 0);
            }

            $meta = $this->read_meta($paths['meta']);
            $committed = $meta['committed_bytes'];
            if ($meta['verified']) {
                return $this->write_result('rejected', 'already_verified', $committed);
            }
            if ($offset + $length <= $committed) {
                return $this->write_result('duplicate', null, $committed);
            }
            if ($offset !== $committed) {
                return $this->write_result('rejected', 'offset_gap', $committed);
            }

            // Discard any uncommitted tail from an interrupted earlier write.
            if (!ftruncate($part, $committed) || fseek($part, $committed) !== 0) {
                return $this->write_result('rejected', 'io_error', $committed);
            }

            $copy_reason = $this->copy_and_hash($source, $part, $length, $expected_crc32);
            if ($copy_reason !== null) {
                ftruncate($part, $committed);
                return $this->write_result('rejected', $copy_reason, $committed);
            }

            // The data is durable before the commit record moves: a crash
            // between the two leaves a tail that the next write truncates.
            if (!fflush($part)) {
                ftruncate($part, $committed);
                return $this->write_result('rejected', 'io_error', $committed);
            }

            $meta['committed_bytes'] = $committed + $length;
            if (!$this->write_meta($paths['meta'], $meta)) {
                ftruncate($part, $committed);
                return $this->write_result('rejected', 'io_error', $committed);
            }

            return $this->write_result('accepted', null, $meta['committed_bytes']);
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
     * @return array{status:string,reason:?string,committed_bytes:int,path:?string}
     *   status "verified"|"busy"|"rejected"; path is set on "verified".
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
            return $this->finalize_result('rejected', 'size_mismatch', 0);
        }

        $part_path = $paths['part'];
        if (!file_exists($part_path)) {
            // A zero-byte artifact legitimately has no chunks.
            if ($expected_total_bytes > 0 || !$this->ensure_parent_dir($part_path) || @touch($part_path) === false) {
                return $this->finalize_result('rejected', 'missing', 0);
            }
        }

        $part = @fopen($part_path, 'c+b');
        if ($part === false) {
            return $this->finalize_result('rejected', 'io_error', 0);
        }

        try {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                return $this->finalize_result('busy', null, 0);
            }

            $meta = $this->read_meta($paths['meta']);
            $committed = $meta['committed_bytes'];
            if ($meta['verified']) {
                $same = $meta['verified_total_bytes'] === $expected_total_bytes
                    && is_string($meta['verified_crc32'])
                    && hash_equals($meta['verified_crc32'], $expected_crc32);
                return $same
                    ? $this->finalize_result('verified', null, $committed, $part_path)
                    : $this->finalize_result('rejected', 'hash_mismatch', $committed);
            }

            if ($committed !== $expected_total_bytes) {
                return $this->finalize_result('rejected', 'size_mismatch', $committed);
            }

            // Drop any uncommitted tail so the checksum covers committed bytes only.
            if (!ftruncate($part, $committed)) {
                return $this->finalize_result('rejected', 'io_error', $committed);
            }

            $actual_crc32 = hash_file('crc32b', $part_path);
            if ($actual_crc32 === false) {
                return $this->finalize_result('rejected', 'io_error', $committed);
            }
            if (!hash_equals($expected_crc32, $actual_crc32)) {
                return $this->finalize_result('rejected', 'hash_mismatch', $committed);
            }

            $meta['verified'] = true;
            $meta['verified_total_bytes'] = $expected_total_bytes;
            $meta['verified_crc32'] = $expected_crc32;
            if (!$this->write_meta($paths['meta'], $meta)) {
                return $this->finalize_result('rejected', 'io_error', $committed);
            }

            return $this->finalize_result('verified', null, $committed, $part_path);
        } finally {
            flock($part, LOCK_UN);
            fclose($part);
        }
    }

    /**
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

        $part = @fopen($paths['part'], 'r+b');
        if ($part !== false) {
            if (!flock($part, LOCK_EX | LOCK_NB)) {
                fclose($part);
                return false;
            }
            @unlink($paths['part']);
            flock($part, LOCK_UN);
            fclose($part);
        }
        @unlink($paths['meta']);
        return true;
    }

    /**
     * @param resource|string $source
     * @return string|null Rejection reason, or null when $length bytes were
     *                     copied and their checksum matched.
     */
    private function copy_and_hash($source, $part, int $length, string $expected_crc32): ?string {
        $context = hash_init('crc32b');
        $remaining = $length;

        while ($remaining > 0) {
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
        return is_dir($dir) || @mkdir($dir, 0700, true);
    }

    /**
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

        $meta = array_merge($defaults, array_intersect_key($meta, $defaults));
        $meta['committed_bytes'] = max(0, (int) $meta['committed_bytes']);
        $meta['verified'] = (bool) $meta['verified'];
        $meta['verified_total_bytes'] = $meta['verified_total_bytes'] !== null ? (int) $meta['verified_total_bytes'] : null;
        return $meta;
    }

    private function write_meta(string $meta_path, array $meta): bool {
        // Write-then-rename keeps the commit record atomic: readers see the
        // old record or the new one, never a torn file.
        $tmp_path = $meta_path . '.tmp';
        if (@file_put_contents($tmp_path, json_encode($meta)) === false) {
            return false;
        }
        if (!@rename($tmp_path, $meta_path)) {
            @unlink($tmp_path);
            return false;
        }
        return true;
    }

    /**
     * @return array{status:string,reason:?string,committed_bytes:int}
     */
    private function write_result(string $status, ?string $reason, int $committed_bytes): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'committed_bytes' => $committed_bytes,
        ];
    }

    /**
     * @return array{status:string,reason:?string,committed_bytes:int,path:?string}
     */
    private function finalize_result(string $status, ?string $reason, int $committed_bytes, ?string $path = null): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'committed_bytes' => $committed_bytes,
            'path' => $path,
        ];
    }
}
