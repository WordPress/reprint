<?php

use function WordPress\Reprint\Exporter\parse_size;

/**
 * HTTP endpoints for the staged artifact store.
 *
 * This is the target side of a push chunk upload: the sender reads its
 * source in bounded chunks (sized by UploadChunkSizer on the importer
 * side) and posts each chunk here, where it accumulates in
 * Site_Export_Staged_Artifacts instead of touching the live site.
 *
 * Four routes share the existing endpoint dispatcher:
 *
 * - staged_upload   (POST, data plane): raw chunk bytes in the request
 *   body, artifact_id and offset in the query string.
 * - staged_finalize (POST, control plane): confirm the assembled size.
 * - staged_status   (GET, control plane): resume hint for a sender.
 * - staged_discard  (POST, control plane): drop an artifact; retry
 *   until the response says discarded.
 *
 * Control-plane routes have small bodies and ride the embedding layer's
 * existing HMAC verification, like every other endpoint. staged_upload
 * cannot: that verification buffers the whole request body to hash it,
 * and a chunk can be as large as the sizer's 1 GiB cap. So the upload
 * route authenticates inside the handler, in two steps that keep memory
 * bounded and keep unauthenticated bytes away from the store:
 *
 * 1. verify_signed_content_hash() checks the signature, nonce, and
 *    timestamp headers before a single body byte is read. A caller
 *    without the shared secret is rejected without costing a body read.
 * 2. The body streams into a spool (memory up to a small threshold,
 *    then a temp file) while a SHA-256 digest accumulates. Only when
 *    the digest matches the signed claim does the spool drain into
 *    append(), one bounded buffer per call. A mismatched or oversized
 *    body is discarded with the spool — the store never sees it. The
 *    spool is one extra sequential disk pass over bytes the host
 *    already buffered for the request; that is the price of never
 *    committing an unverified byte.
 *
 * A replayed capture of a signed upload within the timestamp tolerance
 * re-appends bytes the store already committed and comes back
 * "duplicate" — idempotent, same as a sender retry.
 *
 * Chunk retries are the normal case, not an error: a sender that timed
 * out learns nothing about what landed, retries the same chunk, and may
 * later resend with shifted boundaries. The upload route therefore
 * compares the store's committed offset with the chunk's range first,
 * answers "duplicate" for fully-committed ranges, skips the committed
 * prefix of a straddling chunk, and answers offset_gap (with
 * committed_bytes) only when the chunk starts beyond the frontier.
 *
 * Rejections the chunk sizer must learn from are typed for it: a body
 * over the request cap is HTTP 413 with max_request_bytes in the
 * payload, exactly what record_too_large() consumes. The cap defaults
 * to post_max_size (falling back to the sizer's own 1 GiB hard cap when
 * PHP reports none), because a proxy or PHP itself would refuse larger
 * bodies before this code runs anyway.
 *
 * All options are server-owned. Client config never chooses the staging
 * directory, the secret, or the caps — a request parameter named like
 * an option is ignored. Methods return ['http_code' => int, 'body' =>
 * array] and never echo, so tests drive them directly; the dispatcher
 * wiring in Site_Export_HTTP_Server emits the JSON.
 */
final class Site_Export_Staged_Endpoints {

    /** The chunk sizer never sends more than this, so accept up to it. */
    private const DEFAULT_MAX_REQUEST_BYTES = 1073741824;

    /** One append() step per this many spooled bytes. */
    private const DEFAULT_APPEND_BUFFER_BYTES = 262144;

    /** Spool bytes held in memory before php://temp spills to disk. */
    private const SPOOL_MEMORY_BYTES = 2097152;

    /** Request-body read size while spooling. */
    private const READ_BUFFER_BYTES = 65536;

    /** @var Site_Export_Staged_Artifacts */
    private $store;

    /** @var string|null */
    private $secret;

    /** @var int */
    private $max_request_bytes;

    /** @var int */
    private $append_buffer_bytes;

    /** @var int */
    private $timestamp_tolerance;

    /**
     * @param array $options Server-owned configuration:
     *   - staging_dir (string, required): passed to the artifact store.
     *     Must live outside the web-served tree.
     *   - secret (?string): shared secret for upload authentication.
     *     Null leaves uploads answering 503 until one is configured.
     *   - max_request_bytes (int): upload body cap; defaults to
     *     post_max_size, or 1 GiB when PHP reports no limit.
     *   - append_buffer_bytes (int): spool-to-store step size.
     *   - timestamp_tolerance (int): HMAC freshness window in seconds.
     */
    public function __construct(array $options) {
        $staging_dir = $options['staging_dir'] ?? null;
        if (!is_string($staging_dir) || $staging_dir === '') {
            throw new InvalidArgumentException('Staged endpoints require a staging_dir option.');
        }
        $this->store = new Site_Export_Staged_Artifacts($staging_dir);

        $secret = $options['secret'] ?? null;
        $this->secret = is_string($secret) && $secret !== '' ? $secret : null;

        $max = $options['max_request_bytes'] ?? null;
        if (!is_numeric($max) || (int) $max <= 0) {
            $post_max = ini_get('post_max_size');
            $max = is_string($post_max) && $post_max !== '' ? parse_size($post_max) : 0;
            if ($max <= 0) {
                $max = self::DEFAULT_MAX_REQUEST_BYTES;
            }
        }
        $this->max_request_bytes = (int) $max;

        $buffer = $options['append_buffer_bytes'] ?? null;
        $this->append_buffer_bytes = is_numeric($buffer) && (int) $buffer > 0
            ? (int) $buffer
            : self::DEFAULT_APPEND_BUFFER_BYTES;

        $tolerance = $options['timestamp_tolerance'] ?? null;
        $this->timestamp_tolerance = is_numeric($tolerance) && (int) $tolerance > 0
            ? (int) $tolerance
            : 300;
    }

    /**
     * Stage one chunk of an artifact.
     *
     * @param array $config Request parameters (artifact_id, offset).
     * @param array $headers Request headers/server vars ($_SERVER shape).
     * @param resource|null $input Request body stream (php://input).
     * @return array{http_code:int,body:array}
     */
    public function upload(array $config, array $headers, $input): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }

        $artifact_id = $config['artifact_id'] ?? null;
        if (!is_string($artifact_id) || $artifact_id === '') {
            return $this->rejected(400, 'invalid_artifact_id');
        }
        $offset = $config['offset'] ?? null;
        if (!is_numeric($offset) || (int) $offset < 0) {
            return $this->rejected(400, 'invalid_offset');
        }
        $offset = (int) $offset;

        if ($this->secret === null) {
            return $this->rejected(503, 'not_configured', 'shared_secret');
        }

        // Headers first: nobody without the secret gets a body read.
        $hmac = new Site_Export_HMAC_Server($this->secret, $this->timestamp_tolerance);
        $auth = $hmac->verify_signed_content_hash($headers);
        if ($auth['error'] !== null) {
            return $this->rejected(403, 'auth_failed', $auth['error']);
        }

        $declared_length = $headers['CONTENT_LENGTH'] ?? ( $headers['HTTP_CONTENT_LENGTH'] ?? null );
        if (is_numeric($declared_length) && (int) $declared_length > $this->max_request_bytes) {
            return $this->too_large($artifact_id);
        }

        if (!is_resource($input)) {
            return $this->rejected(500, 'io_error', 'open_request_body');
        }

        $spool = fopen('php://temp/maxmemory:' . self::SPOOL_MEMORY_BYTES, 'w+b');
        if ($spool === false) {
            return $this->rejected(500, 'io_error', 'open_spool');
        }

        try {
            $context = hash_init('sha256');
            $spooled = 0;
            while (( $buffer = fread($input, self::READ_BUFFER_BYTES) ) !== false && $buffer !== '') {
                $spooled += strlen($buffer);
                if ($spooled > $this->max_request_bytes) {
                    return $this->too_large($artifact_id);
                }
                hash_update($context, $buffer);
                if (fwrite($spool, $buffer) !== strlen($buffer)) {
                    return $this->rejected(500, 'io_error', 'write_spool');
                }
            }
            if ($buffer === false && !feof($input)) {
                return $this->rejected(400, 'body_read_failed');
            }

            // The signature authenticated the hash claim; this comparison
            // authenticates the bytes. Nothing reached the store yet.
            if (!hash_equals( (string) $auth['content_hash'], hash_final($context))) {
                return $this->rejected(403, 'content_hash_mismatch');
            }

            if ($spooled === 0) {
                return $this->rejected(400, 'empty_body');
            }

            $status = $this->store->status($artifact_id);
            if ($status['verified']) {
                return $this->rejected(409, 'already_verified', null, $status['committed_bytes']);
            }
            $committed = $status['committed_bytes'];
            if ($committed >= $offset + $spooled) {
                // A retried chunk that fully landed before the sender's
                // timeout. Nothing to write.
                return [
                    'http_code' => 200,
                    'body' => [
                        'status' => 'duplicate',
                        'reason' => null,
                        'detail' => null,
                        'committed_bytes' => $committed,
                    ],
                ];
            }
            if ($committed < $offset) {
                return $this->rejected(409, 'offset_gap', null, $committed);
            }

            // A resent chunk may straddle the committed frontier; skip the
            // prefix the store already has so appends line up with it.
            rewind($spool);
            if ($committed > $offset && fseek($spool, $committed - $offset) !== 0) {
                return $this->rejected(500, 'io_error', 'seek_spool');
            }

            $position = $committed;
            while (( $buffer = fread($spool, $this->append_buffer_bytes) ) !== false && $buffer !== '') {
                $result = $this->store->append($artifact_id, $position, $buffer);
                if ($result['status'] !== 'accepted') {
                    return $this->from_store_result($result);
                }
                $position = $result['committed_bytes'];
            }

            return [
                'http_code' => 200,
                'body' => [
                    'status' => 'accepted',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $position,
                ],
            ];
        } finally {
            fclose($spool);
        }
    }

    /**
     * Confirm an assembled artifact against its plan-declared size.
     *
     * @return array{http_code:int,body:array}
     */
    public function finalize(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }

        $artifact_id = $config['artifact_id'] ?? null;
        if (!is_string($artifact_id) || $artifact_id === '') {
            return $this->rejected(400, 'invalid_artifact_id');
        }
        $total_bytes = $config['total_bytes'] ?? null;
        if (!is_numeric($total_bytes) || (int) $total_bytes < 0) {
            return $this->rejected(400, 'invalid_total');
        }

        $result = $this->store->finalize($artifact_id, (int) $total_bytes);
        // The staged path is server-local detail; apply resolves by id.
        unset($result['path']);
        return $this->from_store_result($result);
    }

    /**
     * Resume hint for a sender: committed offset and verified flag.
     *
     * @return array{http_code:int,body:array}
     */
    public function status(array $config): array {
        $artifact_id = $config['artifact_id'] ?? null;
        if (!is_string($artifact_id) || $artifact_id === '') {
            return $this->rejected(400, 'invalid_artifact_id');
        }

        return [
            'http_code' => 200,
            'body' => $this->store->status($artifact_id),
        ];
    }

    /**
     * Drop an artifact's staged bytes and records.
     *
     * @return array{http_code:int,body:array}
     */
    public function discard(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }

        $artifact_id = $config['artifact_id'] ?? null;
        if (!is_string($artifact_id) || $artifact_id === '') {
            return $this->rejected(400, 'invalid_artifact_id');
        }

        if (!$this->store->discard($artifact_id)) {
            // Held by a concurrent writer or a failed cleanup step; both
            // are the store's retry-until-true contract.
            return [
                'http_code' => 423,
                'body' => ['discarded' => false],
            ];
        }
        return [
            'http_code' => 200,
            'body' => ['discarded' => true],
        ];
    }

    /**
     * @return array{http_code:int,body:array}|null Null when the method is POST.
     */
    private function require_post(array $headers): ?array {
        $method = strtoupper( (string) ( $headers['REQUEST_METHOD'] ?? '' ));
        if ($method === 'POST') {
            return null;
        }
        return $this->rejected(405, 'method_not_allowed');
    }

    /**
     * Map a store result onto an HTTP code, passing its typed body through.
     *
     * @return array{http_code:int,body:array}
     */
    private function from_store_result(array $result): array {
        switch ($result['status']) {
            case 'accepted':
            case 'duplicate':
            case 'verified':
                $code = 200;
                break;
            case 'busy':
                $code = 423;
                break;
            default:
                $code = $this->code_for_reason( (string) $result['reason']);
        }

        return [
            'http_code' => $code,
            'body' => $result,
        ];
    }

    private function code_for_reason(string $reason): int {
        switch ($reason) {
            case 'io_error':
                return 500;
            case 'offset_gap':
            case 'already_verified':
            case 'size_mismatch':
            case 'missing':
                return 409;
            default:
                return 400;
        }
    }

    /**
     * @return array{http_code:int,body:array}
     */
    private function rejected(int $http_code, string $reason, ?string $detail = null, int $committed_bytes = 0): array {
        return [
            'http_code' => $http_code,
            'body' => [
                'status' => 'rejected',
                'reason' => $reason,
                'detail' => $detail,
                'committed_bytes' => $committed_bytes,
            ],
        ];
    }

    /**
     * The structured too-large rejection UploadChunkSizer::record_too_large()
     * consumes: HTTP 413 plus the cap it must stay under.
     *
     * @return array{http_code:int,body:array}
     */
    private function too_large(string $artifact_id): array {
        $response = $this->rejected(
            413,
            'request_too_large',
            null,
            $this->store->status($artifact_id)['committed_bytes']
        );
        $response['body']['max_request_bytes'] = $this->max_request_bytes;
        return $response;
    }
}
