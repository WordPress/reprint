<?php

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}

/**
 * HTTP endpoints for the staged artifact store.
 *
 * This is the target side of a push stream: the sender opens one request,
 * frames many bounded chunks for many files, and this endpoint commits each
 * frame into Site_Export_Staged_Artifacts instead of touching the live site.
 *
 * Four routes share the existing endpoint dispatcher:
 *
 * - staged_push     (POST, data plane): framed file chunks in one streamed
 *   request body.
 * - staged_finalize (POST, control plane): confirm the assembled size.
 * - staged_status   (GET, control plane): resume hint for a sender.
 * - staged_discard  (POST, control plane): drop an artifact; retry
 *   until the response says discarded.
 *
 * Control-plane routes have small bodies and ride the embedding layer's
 * existing HMAC verification, like every other endpoint. staged_push uses
 * envelope HMAC instead: authenticate method + request target before reading
 * bytes, then let TLS protect the body. That keeps one push stream as one
 * request even when it carries many frames for many files.
 *
 * Chunk retries are the normal case, not an error: a sender that timed
 * out learns nothing about what landed, retries from its last cursor or
 * asks staged_status for the store's committed offset, and may later
 * resend with shifted boundaries. push_stream therefore compares the store's
 * committed offset with each frame first, skips the committed prefix of a
 * straddling mid-file frame, and restarts an artifact when the sender begins
 * it again at offset 0 (the sender only does that when its source changed or
 * it cannot vouch for the staged prefix). If a cursor's staged bytes were
 * lost, a mid-file frame fails the request without consuming its payload.
 *
 * Rejections the chunk sizer must learn from are typed for it: a frame
 * over the frame cap is HTTP 413 with max_frame_bytes in the payload,
 * exactly what record_too_large() consumes. The cap defaults to post_max_size
 * (falling back to the sizer's own 1 GiB hard cap when PHP reports none),
 * because a proxy or PHP itself would refuse larger bodies before this code
 * runs anyway.
 *
 * All options are server-owned. Client config never chooses the staging
 * directory, the secret, or the caps — a request parameter named like
 * an option is ignored. Methods return ['http_code' => int, 'body' =>
 * array] and never echo, so tests drive them directly; the dispatcher
 * wiring in Site_Export_HTTP_Server emits the JSON.
 */
final class Site_Export_Staged_Endpoints {

    /** The chunk sizer never sends more than this, so accept up to it. */
    private const DEFAULT_MAX_FRAME_BYTES = 1073741824;

    /** One append() step per this many request-body bytes. */
    private const DEFAULT_APPEND_BUFFER_BYTES = 262144;

    /** Request-body read size while discarding already-committed bytes. */
    private const READ_BUFFER_BYTES = 65536;

    /** Detail returned when a control-plane call names the reserved namespace. */
    private const RESERVED_NAMESPACE_MESSAGE = 'This artifact id is in reprint\'s reserved ".reprint/" namespace; only the deletion manifest may be written there.';

    /** @var Site_Export_Staged_Artifacts */
    private $store;

    /** @var string|null */
    private $secret;

    /** @var int */
    private $max_frame_bytes;

    /** @var int */
    private $append_buffer_bytes;

    /** @var int */
    private $timestamp_tolerance;

    /**
     * @param array $options Server-owned configuration:
     *   - staging_dir (string, required): passed to the artifact store.
     *     Must live outside the web-served tree.
     *   - secret (?string): shared secret for push authentication.
     *     Null leaves pushes answering 503 until one is configured.
     *   - max_frame_bytes (int): single frame payload cap; defaults to
     *     post_max_size, or 1 GiB when PHP reports no limit.
     *   - append_buffer_bytes (int): request-to-store step size.
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

        $max_frame_bytes_option = $options['max_frame_bytes'] ?? null;
        if (!is_numeric($max_frame_bytes_option) || (int) $max_frame_bytes_option <= 0) {
            $post_max_size = ini_get('post_max_size');
            $max_frame_bytes_option = is_string($post_max_size) && $post_max_size !== ''
                ? parse_size($post_max_size)
                : 0;
            if ($max_frame_bytes_option <= 0) {
                $max_frame_bytes_option = self::DEFAULT_MAX_FRAME_BYTES;
            }
        }
        $this->max_frame_bytes = (int) $max_frame_bytes_option;

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
     * Stage a framed stream of chunks for many artifacts in one request.
     *
     * Each frame is one JSON line followed by exactly "bytes" raw bytes:
     *
     * {"type":"chunk","artifact_id":"<base64 of path>","offset":0,"bytes":123,"total_bytes":456,"final":false}\n
     *
     * A frame commits before the next frame is read. If the request dies after
     * a commit, the next request may replay from the last sender cursor or from
     * the beginning: verified artifacts replayed at their verified size are
     * skipped, and an offset-0 frame for anything else that holds bytes
     * restarts the artifact from zero. A mid-file frame behind a damaged
     * cursor fails without consuming its payload or later frames. An
     * offset-zero frame discards the damage and stages fresh. The sender only
     * starts a file over when its source changed or it cannot vouch for the
     * staged prefix, and appending one version behind another would corrupt
     * the artifact.
     *
     * Behavior steps:
     * 1. Read one JSON header line and validate the frame before reading its
     *    payload bytes.
     * 2. Reject frames larger than this endpoint accepts, before consuming the
     *    oversized payload. Every rejection cursor reports the store's
     *    committed count, never the offset the sender claimed.
     * 3. If the artifact is verified and the frame declares its verified size,
     *    consume the frame payload only to keep the stream aligned with the
     *    next JSON header.
     * 4. A damaged cursor rejects a mid-file frame without consuming its
     *    payload or later frames; an offset-zero frame discards the damaged
     *    state and stages fresh.
     * 5. If the frame starts at byte 0 and the artifact holds anything else,
     *    discard the staged bytes and stage this frame fresh.
     * 6. If the artifact is partially committed and the frame starts mid-file,
     *    discard any replayed prefix bytes the store already has and append
     *    only the missing suffix, in bounded pieces so one frame cannot force
     *    the endpoint to hold the full chunk in memory.
     * 7. Finalize only when the frame marks the artifact final, then report the
     *    latest cursor for the sender to resume from after failures.
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

        $files_verified = 0;
        $cursor = null;
        while (!feof($input)) {
            $line = fgets($input);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            try {
                $frame = Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header($line);
            } catch (InvalidArgumentException $e) {
                return $this->stream_rejected(400, 'invalid_frame', $e->getMessage(), $cursor, $files_verified);
            }
            $artifact_id = $frame['artifact_id'];
            $offset = $frame['offset'];
            $bytes = $frame['bytes'];
            $total_bytes = $frame['total_bytes'];
            $final = $frame['final'];

            // Refuse frames that name reprint's reserved namespace before the
            // store is touched, so a pushed file can never overwrite the
            // deletion manifest (or hide as another .reprint/ artifact apply
            // would then trust). The one deletion-manifest id is allowed
            // through and stages like any other artifact.
            if (Site_Export_Staged_Push_Stream_Protocol::is_reserved_sender_artifact_id($artifact_id)) {
                return $this->stream_rejected(
                    400,
                    'reserved_artifact_id',
                    'The artifact id "' . $artifact_id . '" is in reprint\'s reserved ".reprint/" namespace; only the deletion manifest may be written there.',
                    ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => 0],
                    $files_verified
                );
            }

            // Response cursors re-encode the id so responses stay valid JSON
            // for arbitrary-byte paths, and they report only what the store
            // has confirmed — never the offset the sender claims. A rejection
            // echoing a claimed offset would send the retry to a position the
            // store never reached.
            $status = $this->store->status($artifact_id);
            $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => (int) $status['committed_bytes']];

            if ($bytes > $this->max_frame_bytes) {
                $response = $this->stream_rejected(413, 'frame_too_large', null, $cursor, $files_verified);
                $response['body']['max_frame_bytes'] = $this->max_frame_bytes;
                return $response;
            }

            if (isset($status['damage'])) {
                $damage = (string) $status['damage'];
                if ($offset !== 0) {
                    return $this->stream_rejected(409, 'staging_file_damaged', $damage, $cursor, $files_verified);
                }
                if (!$this->store->discard($artifact_id)) {
                    return $this->stream_rejected(423, 'busy', $damage, $cursor, $files_verified);
                }
                $status = $this->store->status($artifact_id);
                $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => 0];
            }

            /**
             * Already-verified replay case.
             *
             * The sender may replay a request body from the beginning after a
             * previous request committed and finalized this artifact. When the
             * declared size still matches, the store must not append or
             * finalize again, but the frame payload is still present in the
             * request body: read and discard those bytes so the next loop
             * iteration starts at the following JSON header.
             */
            if ($status['verified'] && $status['committed_bytes'] === $total_bytes) {
                if (!Site_Export_Staged_Push_Stream_Protocol::discard_exactly($input, $bytes, self::READ_BUFFER_BYTES)) {
                    return $this->stream_rejected(400, 'body_read_failed', null, $cursor, $files_verified);
                }
                if ($final) {
                    $files_verified++;
                }
                continue;
            }

            /**
             * Restart case.
             *
             * An offset-0 frame for an artifact that already holds bytes means
             * the sender is pushing the file over: its source changed since
             * those bytes were staged, or it is replaying after a failure and
             * cannot vouch for the staged prefix. Drop the staged bytes and
             * stage this frame fresh — appending a new version behind an old
             * prefix would build a file no version of the source ever was.
             */
            if ($offset === 0 && ($status['verified'] || (int) $status['committed_bytes'] > 0)) {
                if (!$this->store->discard($artifact_id)) {
                    return $this->stream_rejected(423, 'busy', null, $cursor, $files_verified);
                }
                $status = $this->store->status($artifact_id);
                $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => 0];
            } elseif ($status['verified']) {
                // A mid-file frame into a verified artifact of another size:
                // the source changed but the sender did not restart. Refuse
                // rather than mix versions; the sender restarts from zero.
                return $this->stream_rejected(409, 'size_mismatch', null, $cursor, $files_verified);
            }

            if ($bytes > 0) {
                $remaining_frame_bytes = $bytes;
                $append_offset = $offset;

                $committed_bytes = (int) $status['committed_bytes'];

                /**
                 * Partially-committed replay case.
                 *
                 * The sender can retry a frame whose prefix was accepted before
                 * the earlier request failed. Consume the bytes already stored,
                 * advance the append offset to the first missing byte, and keep
                 * the cursor at the remote committed position so the sender can
                 * resume from that boundary if this request fails too.
                 */
                if ($committed_bytes > $offset) {
                    $already_committed_bytes = min($bytes, $committed_bytes - $offset);
                    if (!Site_Export_Staged_Push_Stream_Protocol::discard_exactly($input, $already_committed_bytes, self::READ_BUFFER_BYTES)) {
                        return $this->stream_rejected(400, 'body_read_failed', null, $cursor, $files_verified);
                    }
                    $remaining_frame_bytes -= $already_committed_bytes;
                    $append_offset += $already_committed_bytes;
                    $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => $committed_bytes];
                }

                while ($remaining_frame_bytes > 0) {
                    $payload_piece_bytes = min($this->append_buffer_bytes, $remaining_frame_bytes);
                    $payload_piece = Site_Export_Staged_Push_Stream_Protocol::read_exactly($input, $payload_piece_bytes);
                    if ($payload_piece === null) {
                        return $this->stream_rejected(400, 'body_read_failed', null, $cursor, $files_verified);
                    }
                    $remaining_frame_bytes -= $payload_piece_bytes;

                    while ($payload_piece !== '') {
                        /**
                         * Append/reconcile case.
                         *
                         * A bounded payload piece normally appends once. If the
                         * store reports that part of this piece was already
                         * committed, trim that prefix and retry only the suffix;
                         * this keeps a resumed request moving forward without
                         * asking the sender to open another request for the same
                         * frame.
                         */
                        $append_result = $this->store->append($artifact_id, $append_offset, $payload_piece);
                        if ($append_result['status'] === 'accepted' || $append_result['status'] === 'duplicate') {
                            $append_offset = max($append_offset + strlen($payload_piece), (int) $append_result['committed_bytes']);
                            $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => (int) $append_result['committed_bytes']];
                            break;
                        }

                        $committed_bytes = (int) $append_result['committed_bytes'];
                        if (
                            $append_result['reason'] === 'offset_gap'
                            && $committed_bytes > $append_offset
                            && $committed_bytes < $append_offset + strlen($payload_piece)
                        ) {
                            $payload_piece = substr($payload_piece, $committed_bytes - $append_offset);
                            $append_offset = $committed_bytes;
                            continue;
                        }

                        $response = $this->from_store_result($append_result);
                        $response['body']['cursor'] = [
                            'artifact_id' => base64_encode($artifact_id),
                            'committed_bytes' => $committed_bytes,
                        ];
                        $response['body']['files_verified'] = $files_verified;
                        return $response;
                    }
                }
            }

            /**
             * Final frame case.
             *
             * Payload bytes are durable before this branch runs. Only frames
             * marked final ask the store to compare committed bytes with
             * total_bytes and mark the artifact verified.
             */
            if ($final) {
                $finalize_result = $this->store->finalize($artifact_id, $total_bytes);
                unset($finalize_result['path']);
                if ($finalize_result['status'] !== 'verified') {
                    $response = $this->from_store_result($finalize_result);
                    $response['body']['cursor'] = $cursor;
                    $response['body']['files_verified'] = $files_verified;
                    return $response;
                }
                $files_verified++;
                $cursor = ['artifact_id' => base64_encode($artifact_id), 'committed_bytes' => (int) $finalize_result['committed_bytes']];
            }
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
     * Confirm an assembled artifact against its plan-declared size.
     *
     * @return array{http_code:int,body:array}
     */
    public function finalize(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }

        $artifact_id = $this->decode_artifact_id_param($config);
        if ($artifact_id === null) {
            return $this->rejected(400, 'invalid_artifact_id');
        }
        if (Site_Export_Staged_Push_Stream_Protocol::is_reserved_sender_artifact_id($artifact_id)) {
            return $this->rejected(400, 'reserved_artifact_id', self::RESERVED_NAMESPACE_MESSAGE);
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
        $artifact_id = $this->decode_artifact_id_param($config);
        if ($artifact_id === null) {
            return $this->rejected(400, 'invalid_artifact_id');
        }
        if (Site_Export_Staged_Push_Stream_Protocol::is_reserved_sender_artifact_id($artifact_id)) {
            return $this->rejected(400, 'reserved_artifact_id', self::RESERVED_NAMESPACE_MESSAGE);
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

        $artifact_id = $this->decode_artifact_id_param($config);
        if ($artifact_id === null) {
            return $this->rejected(400, 'invalid_artifact_id');
        }
        if (Site_Export_Staged_Push_Stream_Protocol::is_reserved_sender_artifact_id($artifact_id)) {
            return $this->rejected(400, 'reserved_artifact_id', self::RESERVED_NAMESPACE_MESSAGE);
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
     * Read a control-plane artifact id parameter: base64 in the request —
     * file paths are arbitrary bytes, the same convention the push stream
     * frames use — raw path out. Null when missing or not decodable.
     */
    private function decode_artifact_id_param(array $config): ?string {
        $artifact_id = $config['artifact_id'] ?? null;
        if (!is_string($artifact_id) || $artifact_id === '') {
            return null;
        }
        $artifact_id = base64_decode($artifact_id, true);
        return $artifact_id === false || $artifact_id === '' ? null : $artifact_id;
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
                switch ((string) $result['reason']) {
                    case 'io_error':
                        $code = 500;
                        break;
                    case 'offset_gap':
                    case 'already_verified':
                    case 'size_mismatch':
                    case 'missing':
                    case 'staging_file_damaged':
                        $code = 409;
                        break;
                    default:
                        $code = 400;
                }
        }

        return [
            'http_code' => $code,
            'body' => $result,
        ];
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

}
