<?php

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_File_Chunk_Stream_Reader', false)) {
    require_once __DIR__ . '/class-file-chunk-stream-reader.php';
}

/**
 * HTTP endpoints for the staged artifact store.
 *
 * This is the target side of a push stream: the sender opens one request,
 * writes many bounded multipart file chunks for many files, and this endpoint
 * commits each part into Site_Export_Staged_Artifacts instead of touching the
 * live site.
 *
 * Five routes share the existing endpoint dispatcher:
 *
 * - staged_push     (POST, data plane): multipart file chunks in one
 *   streamed request body.
 * - staged_upload   (POST, data plane, legacy route): raw chunk bytes in the
 *   request body, artifact_id and offset in the query string.
 * - staged_finalize (POST, control plane): confirm the assembled size.
 * - staged_status   (GET, control plane): resume hint for a sender.
 * - staged_discard  (POST, control plane): drop an artifact; retry
 *   until the response says discarded.
 *
 * Control-plane routes have small bodies and ride the embedding layer's
 * existing HMAC verification, like every other endpoint. staged_push uses
 * envelope HMAC instead: authenticate method + request target before reading
 * bytes, then let TLS protect the body. That keeps one push stream as one
 * request even when it carries many chunks for many files.
 *
 * The legacy staged_upload route still authenticates each raw chunk body in
 * two steps that keep memory bounded and keep unauthenticated bytes away from
 * the store:
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

        $max_request_bytes_option = $options['max_request_bytes'] ?? null;
        if (!is_numeric($max_request_bytes_option) || (int) $max_request_bytes_option <= 0) {
            $post_max_size = ini_get('post_max_size');
            $max_request_bytes_option = is_string($post_max_size) && $post_max_size !== ''
                ? parse_size($post_max_size)
                : 0;
            if ($max_request_bytes_option <= 0) {
                $max_request_bytes_option = self::DEFAULT_MAX_REQUEST_BYTES;
            }
        }
        $this->max_request_bytes = (int) $max_request_bytes_option;

        $append_buffer_bytes_option = $options['append_buffer_bytes'] ?? null;
        $this->append_buffer_bytes = is_numeric($append_buffer_bytes_option) && (int) $append_buffer_bytes_option > 0
            ? (int) $append_buffer_bytes_option
            : self::DEFAULT_APPEND_BUFFER_BYTES;

        $timestamp_tolerance_option = $options['timestamp_tolerance'] ?? null;
        $this->timestamp_tolerance = is_numeric($timestamp_tolerance_option) && (int) $timestamp_tolerance_option > 0
            ? (int) $timestamp_tolerance_option
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
        $hmac_server = new Site_Export_HMAC_Server($this->secret, $this->timestamp_tolerance);
        $authentication_result = $hmac_server->verify_signed_content_hash($headers);
        if ($authentication_result['error'] !== null) {
            return $this->rejected(403, 'auth_failed', $authentication_result['error']);
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
            $hash_context = hash_init('sha256');
            $spooled_bytes = 0;
            while (( $request_body_chunk = fread($input, self::READ_BUFFER_BYTES) ) !== false && $request_body_chunk !== '') {
                $spooled_bytes += strlen($request_body_chunk);
                if ($spooled_bytes > $this->max_request_bytes) {
                    return $this->too_large($artifact_id);
                }
                hash_update($hash_context, $request_body_chunk);
                if (fwrite($spool, $request_body_chunk) !== strlen($request_body_chunk)) {
                    return $this->rejected(500, 'io_error', 'write_spool');
                }
            }
            if ($request_body_chunk === false && !feof($input)) {
                return $this->rejected(400, 'body_read_failed');
            }

            // The signature authenticated the hash claim; this comparison
            // authenticates the bytes. Nothing reached the store yet.
            if (!hash_equals( (string) $authentication_result['content_hash'], hash_final($hash_context))) {
                return $this->rejected(403, 'content_hash_mismatch');
            }

            if ($spooled_bytes === 0) {
                return $this->rejected(400, 'empty_body');
            }

            $status = $this->store->status($artifact_id);
            if ($status['verified']) {
                return $this->rejected(409, 'already_verified', null, $status['committed_bytes']);
            }
            $committed_bytes = $status['committed_bytes'];
            if ($committed_bytes >= $offset + $spooled_bytes) {
                // A retried chunk that fully landed before the sender's
                // timeout. Nothing to write.
                return [
                    'http_code' => 200,
                    'body' => [
                        'status' => 'duplicate',
                        'reason' => null,
                        'detail' => null,
                        'committed_bytes' => $committed_bytes,
                    ],
                ];
            }
            if ($committed_bytes < $offset) {
                return $this->rejected(409, 'offset_gap', null, $committed_bytes);
            }

            // A resent chunk may straddle the committed frontier; skip the
            // prefix the store already has so appends line up with it.
            rewind($spool);
            if ($committed_bytes > $offset && fseek($spool, $committed_bytes - $offset) !== 0) {
                return $this->rejected(500, 'io_error', 'seek_spool');
            }

            $append_offset = $committed_bytes;
            while (( $spooled_chunk = fread($spool, $this->append_buffer_bytes) ) !== false && $spooled_chunk !== '') {
                $append_result = $this->store->append($artifact_id, $append_offset, $spooled_chunk);
                if ($append_result['status'] !== 'accepted') {
                    return $this->from_store_result($append_result);
                }
                $append_offset = $append_result['committed_bytes'];
            }

            return [
                'http_code' => 200,
                'body' => [
                    'status' => 'accepted',
                    'reason' => null,
                    'detail' => null,
                    'committed_bytes' => $append_offset,
                ],
            ];
        } finally {
            fclose($spool);
        }
    }

    /**
     * Stage a multipart stream of chunks for many artifacts in one request.
     *
     * Each part uses the shared file chunk stream headers: x-file-path carries
     * the base64 artifact id, x-chunk-offset and x-chunk-size describe the byte
     * range, x-file-size declares the full artifact size, and x-last-chunk asks
     * the store to verify that artifact after the part lands.
     *
     * A part commits before the next part is read. If the request dies after a
     * commit, the next request may replay from the last sender cursor or from
     * the beginning; verified artifacts and duplicate ranges are absorbed.
     *
     * @param array $config Request parameters.
     * @param array $headers Request headers/server vars ($_SERVER shape).
     * @param resource|null $input Request body stream (php://input).
     * @return array{http_code:int,body:array}
     */
    public function push_stream(array $config, array $headers, $input): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        if ($this->secret === null) {
            return $this->rejected(503, 'not_configured', 'shared_secret');
        }
        if (!is_resource($input)) {
            return $this->rejected(500, 'io_error', 'open_request_body');
        }

        $request_target = $headers['REQUEST_URI'] ?? null;
        if (!is_string($request_target) || $request_target === '') {
            return $this->rejected(400, 'missing_request_target');
        }
        $auth_error = (new Site_Export_HMAC_Server($this->secret, $this->timestamp_tolerance))
            ->verify_envelope($headers, (string) ( $headers['REQUEST_METHOD'] ?? 'POST' ), $request_target);
        if ($auth_error !== null) {
            return $this->rejected(403, 'auth_failed', $auth_error);
        }

        $content_type = (string) ( $headers['CONTENT_TYPE'] ?? ( $headers['HTTP_CONTENT_TYPE'] ?? '' ) );
        $boundary = Site_Export_File_Chunk_Stream_Reader::boundary_from_content_type($content_type);
        if ($boundary === null) {
            return $this->rejected(400, 'invalid_content_type', 'missing_multipart_boundary');
        }

        $files_verified = 0;
        $cursor = null;
        $current_part = null;
        $reader = new Site_Export_File_Chunk_Stream_Reader(
            $boundary,
            function (array $event) use (&$current_part, &$cursor, &$files_verified): void {
                if ($event['type'] === 'body') {
                    $part = $this->start_push_stream_part($event['headers'], $current_part, $cursor, $files_verified);
                    $data = (string) $event['data'];
                    $current_part['body_bytes_read'] += strlen($data);
                    if (!$part['already_verified'] && $data !== '') {
                        $this->append_push_stream_part_body($current_part, $data, $cursor, $files_verified);
                    }
                    return;
                }

                if ($event['type'] === 'complete') {
                    $part = $this->start_push_stream_part($event['headers'], $current_part, $cursor, $files_verified);
                    if ($part['body_bytes_read'] !== $part['bytes']) {
                        throw new Site_Export_Staged_Push_Stream_Response_Exception(
                            $this->stream_rejected(400, 'body_size_mismatch', null, $cursor, $files_verified)
                        );
                    }
                    if ($part['final']) {
                        if ($part['already_verified']) {
                            $files_verified++;
                        } else {
                            $finalize_result = $this->store->finalize($part['artifact_id'], $part['total_bytes']);
                            unset($finalize_result['path']);
                            if ($finalize_result['status'] !== 'verified') {
                                $response = $this->from_store_result($finalize_result);
                                $response['body']['cursor'] = $cursor;
                                $response['body']['files_verified'] = $files_verified;
                                throw new Site_Export_Staged_Push_Stream_Response_Exception($response);
                            }
                            $files_verified++;
                            $cursor = [
                                'artifact_id' => $part['artifact_id'],
                                'committed_bytes' => (int) $finalize_result['committed_bytes'],
                            ];
                        }
                    }
                    $current_part = null;
                }
            }
        );

        try {
            while (( $request_body_chunk = fread($input, self::READ_BUFFER_BYTES) ) !== false && $request_body_chunk !== '') {
                $reader->feed($request_body_chunk);
            }
            if ($request_body_chunk === false && !feof($input)) {
                return $this->stream_rejected(400, 'body_read_failed', null, $cursor, $files_verified);
            }
        } catch (Site_Export_Staged_Push_Stream_Response_Exception $response_exception) {
            return $response_exception->response;
        }

        return [
            'http_code' => 200,
            'body' => [
                'status' => 'complete',
                'reason' => null,
                'detail' => null,
                'cursor' => $cursor,
                'files_verified' => $files_verified,
            ],
        ];
    }

    /**
     * @param array<string,string> $headers
     * @param array|null $current_part
     * @return array{artifact_id:string,offset:int,bytes:int,total_bytes:int,final:bool,append_offset:int,body_bytes_read:int,already_verified:bool}
     */
    private function start_push_stream_part(array $headers, ?array &$current_part, ?array &$cursor, int $files_verified): array {
        if ($current_part !== null) {
            return $current_part;
        }

        if (($headers['x-chunk-type'] ?? '') !== 'file') {
            throw new Site_Export_Staged_Push_Stream_Response_Exception(
                $this->stream_rejected(400, 'invalid_chunk_type', null, $cursor, $files_verified)
            );
        }

        $raw_artifact_id = $headers['x-file-path'] ?? '';
        $artifact_id = base64_decode($raw_artifact_id, true);
        $offset = $headers['x-chunk-offset'] ?? null;
        $bytes = $headers['x-chunk-size'] ?? ( $headers['content-length'] ?? null );
        $total_bytes = $headers['x-file-size'] ?? null;
        if (
            $artifact_id === false
            || $artifact_id === ''
            || !is_numeric($offset)
            || (int) $offset < 0
            || !is_numeric($bytes)
            || (int) $bytes < 0
            || !is_numeric($total_bytes)
            || (int) $total_bytes < 0
        ) {
            throw new Site_Export_Staged_Push_Stream_Response_Exception(
                $this->stream_rejected(400, 'invalid_frame', 'fields', $cursor, $files_verified)
            );
        }

        $offset = (int) $offset;
        $bytes = (int) $bytes;
        $total_bytes = (int) $total_bytes;
        if ($offset + $bytes > $total_bytes) {
            throw new Site_Export_Staged_Push_Stream_Response_Exception(
                $this->stream_rejected(400, 'invalid_frame', 'range_exceeds_total', $cursor, $files_verified)
            );
        }

        $cursor = ['artifact_id' => $artifact_id, 'committed_bytes' => $offset];
        if ($bytes > $this->max_request_bytes) {
            $response = $this->stream_rejected(413, 'frame_too_large', null, $cursor, $files_verified);
            $response['body']['max_frame_bytes'] = $this->max_request_bytes;
            throw new Site_Export_Staged_Push_Stream_Response_Exception($response);
        }

        $status = $this->store->status($artifact_id);
        $already_verified = (bool) $status['verified'];
        if ($already_verified) {
            if ($status['committed_bytes'] !== $total_bytes) {
                throw new Site_Export_Staged_Push_Stream_Response_Exception(
                    $this->stream_rejected(409, 'size_mismatch', null, [
                        'artifact_id' => $artifact_id,
                        'committed_bytes' => $status['committed_bytes'],
                    ], $files_verified)
                );
            }
            $cursor = ['artifact_id' => $artifact_id, 'committed_bytes' => $status['committed_bytes']];
        }

        $current_part = [
            'artifact_id' => $artifact_id,
            'offset' => $offset,
            'bytes' => $bytes,
            'total_bytes' => $total_bytes,
            'final' => ($headers['x-last-chunk'] ?? '0') === '1',
            'append_offset' => $offset,
            'body_bytes_read' => 0,
            'already_verified' => $already_verified,
        ];
        return $current_part;
    }

    /** @param array{artifact_id:string,append_offset:int} $part */
    private function append_push_stream_part_body(array &$part, string $payload_piece, ?array &$cursor, int $files_verified): void {
        while ($payload_piece !== '') {
            $append_result = $this->store->append($part['artifact_id'], $part['append_offset'], $payload_piece);
            if ($append_result['status'] === 'accepted' || $append_result['status'] === 'duplicate') {
                $part['append_offset'] = max($part['append_offset'] + strlen($payload_piece), (int) $append_result['committed_bytes']);
                $cursor = ['artifact_id' => $part['artifact_id'], 'committed_bytes' => (int) $append_result['committed_bytes']];
                break;
            }

            $committed_bytes = (int) $append_result['committed_bytes'];
            if (
                $append_result['reason'] === 'offset_gap'
                && $committed_bytes > $part['append_offset']
                && $committed_bytes < $part['append_offset'] + strlen($payload_piece)
            ) {
                $payload_piece = substr($payload_piece, $committed_bytes - $part['append_offset']);
                $part['append_offset'] = $committed_bytes;
                continue;
            }

            $response = $this->from_store_result($append_result);
            $response['body']['cursor'] = [
                'artifact_id' => $part['artifact_id'],
                'committed_bytes' => $committed_bytes,
            ];
            $response['body']['files_verified'] = $files_verified;
            throw new Site_Export_Staged_Push_Stream_Response_Exception($response);
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
    private function stream_rejected(int $http_code, string $reason, ?string $detail, ?array $cursor, int $files_verified): array {
        return [
            'http_code' => $http_code,
            'body' => [
                'status' => 'rejected',
                'reason' => $reason,
                'detail' => $detail,
                'cursor' => $cursor,
                'files_verified' => $files_verified,
            ],
        ];
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
     * The structured too-large rejection PushFrameSizer::record_too_large()
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


final class Site_Export_Staged_Push_Stream_Response_Exception extends RuntimeException {
    /** @var array{http_code:int,body:array} */
    public array $response;

    /** @param array{http_code:int,body:array} $response */
    public function __construct(array $response) {
        parent::__construct((string) ($response['body']['reason'] ?? 'stream_rejected'));
        $this->response = $response;
    }
}
