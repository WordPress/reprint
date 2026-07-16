<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Transport errors are CLI/API values, never HTML output.

/**
 * Streams a caller-driven multipart/mixed upload over one live HTTP request.
 *
 * The client opens a signed target upload, pauses its cURL read callback until
 * the caller supplies a part, and resumes the transfer only long enough to send
 * that part. send_part() does not queue future work: when it returns true,
 * libcurl has consumed the complete MIME prefix, payload, and suffix through
 * the active transfer. The caller can then drop that one payload string, read
 * the next bounded source piece, and call send_part() again.
 *
 * Example:
 *
 *     if (!$client->start_upload_request($push_session_id)) {
 *         throw new RuntimeException($client->get_last_error());
 *     }
 *
 *     while ($offset < $total_bytes) {
 *         $maximum = $client->next_file_body_bytes($path, $total_bytes, $offset, $mode);
 *         if ($maximum === 0) {
 *             break;
 *         }
 *         $payload = read_source_piece($path, $offset, $maximum);
 *         if (!$client->send_part([
 *             'type' => 'file',
 *             'path' => $path,
 *             'total_bytes' => $total_bytes,
 *             'offset' => $offset,
 *             'payload' => $payload,
 *         ])) {
 *             break;
 *         }
 *         $offset += strlen($payload);
 *     }
 *
 *     $result = $client->finish_request();
 *
 * Three limits describe different dimensions. `chunk_bytes` bounds the one
 * payload string held in memory, `max_part_bytes` is the target's maximum
 * Content-Length for one MIME part, and PushRequestSizer bounds the decoded
 * entity body containing many parts. MIME delimiter and header bytes count
 * against that request-body budget; HTTP headers and transfer framing do not.
 *
 * A curl_multi handle outlives individual requests so libcurl may reuse its
 * connection. The active easy handle exists only from start_upload_request()
 * through finish_request(). Timeouts are per phase rather than total-transfer
 * deadlines: connection establishment, lack of upload progress, and lack of
 * finish/response progress after the closing boundary is queued. A slow
 * connection which continues moving bytes is allowed to continue.
 *
 * Pausing from a PHP cURL read callback is reliable only on PHP 8.1 and newer;
 * older bindings interpret CURL_READFUNC_PAUSE as end-of-body and silently
 * truncate the upload. The constructor enforces that runtime requirement while
 * this source file remains PHP 7.4 parseable for import.php's pull commands.
 */
class MultipartPushStreamClient
{
    /** @var string Exporter API URL used as the base of every signed request target. */
    private string $base_url;

    /** @var Site_Export_HMAC_Client Signs the exact method and URL before transfer. */
    private Site_Export_HMAC_Client $hmac_client;

    /** @var PushRequestSizer Learns the decoded entity-body budget across requests. */
    private PushRequestSizer $request_sizer;

    /** @var int Maximum caller file bytes held in one payload string. */
    private int $chunk_bytes;

    /** @var int Target-advertised maximum Content-Length for one MIME part. */
    private int $max_part_bytes;

    /** @var int Seconds allowed to establish a request and open its upload body. */
    private int $connect_timeout;

    /** @var int Seconds allowed with no multipart bytes consumed by libcurl. */
    private int $stall_timeout;

    /** @var int Seconds allowed without finish/response progress after the close is queued. */
    private int $response_timeout;

    /**
     * cURL easy handle for the currently open upload request.
     *
     * Null outside the start_upload_request()/finish_request() lifecycle.
     *
     * @var resource|object|null
     */
    private $curl_handle = null;

    /**
     * Long-lived cURL multi handle which preserves libcurl's connection cache.
     *
     * @var resource|object|null
     */
    private $multi_handle = null;

    /** @var string Random boundary token for the currently open MIME body. */
    private string $boundary = '';

    /**
     * MIME framing waiting to be returned by the cURL read callback.
     *
     * For a part this contains its boundary, header block, and header/body
     * separator, which are drained before the payload. At request completion
     * it contains the closing boundary instead.
     */
    private string $outbound_prefix = '';

    /** @var string One caller-supplied body piece currently being transmitted. */
    private string $outbound_payload = '';

    /** @var string Pending CRLF which terminates the current part body. */
    private string $outbound_suffix = '';

    /** @var int Next byte offset in $outbound_payload requested by libcurl. */
    private int $outbound_payload_offset = 0;

    /** @var bool Whether the read callback should return EOF after pending bytes. */
    private bool $body_complete = false;

    /** @var bool Whether libcurl has entered the upload read callback for this request. */
    private bool $curl_requested_body = false;

    /** @var bool Whether curl_multi reported terminal completion for the easy handle. */
    private bool $transfer_finished = false;

    /** @var string|null cURL or phase-timeout detail captured for result classification. */
    private ?string $transfer_error = null;

    /** @var int Total prefix, payload, and suffix bytes consumed by the read callback. */
    private int $outbound_consumed_bytes = 0;

    /** @var int Decoded MIME entity-body bytes charged for completed parts and close. */
    private int $body_bytes_sent = 0;

    /** @var int Complete MIME parts transmitted in the current request. */
    private int $parts_sent = 0;

    /** @var string|null Setup failure exposed when start_upload_request() returns false. */
    private ?string $last_error = null;

    /**
     * Configures one reusable connection context and its independent limits.
     *
     * Construction fails on PHP versions whose curl binding cannot pause a
     * read callback without terminating the upload. HTTP is rejected unless
     * `allow_http` is explicitly true.
     *
     * Null optional values are treated as absent by the constructor's defaults.
     *
     * @param array<string,mixed> $options {
     *     Transport, authentication, and limit options.
     *
     *     @type string $base_url Required exporter API URL. Must use HTTPS
     *         unless `allow_http` is true.
     *     @type Site_Export_HMAC_Client $hmac_client Required signer for the
     *         exact method and request URL.
     *     @type bool $allow_http Whether to permit an explicit HTTP base URL.
     *         Default false.
     *     @type PushRequestSizer $request_sizer Request-body sizing state to
     *         reuse. Defaults to a new sizer.
     *     @type int|float|string $chunk_bytes Maximum caller payload bytes held
     *         in memory. A positive numeric value is cast to int. Default 4 MiB.
     *     @type int|float|string $max_part_bytes Maximum Content-Length for one
     *         MIME part. A positive numeric value is cast to int. Default PHP_INT_MAX.
     *     @type int|float|string $connect_timeout Seconds allowed to establish
     *         the request and open its body. Default 30.
     *     @type int|float|string $stall_timeout Seconds allowed with no upload
     *         bytes consumed by cURL. Default 60.
     *     @type int|float|string $response_timeout Seconds allowed without
     *         finish or response progress. Default 300.
     * }
     *
     * @throws InvalidArgumentException If required or non-null configuration
     *     is invalid or insecure.
     * @throws RuntimeException If PHP cannot support paused upload callbacks.
     */
    public function __construct(array $options)
    {
        if (PHP_VERSION_ID < 80100) {
            throw new RuntimeException(
                'reprint push requires PHP 8.1 or newer: streaming request bodies need CURL_READFUNC_PAUSE, '
                . 'which older PHP curl bindings interpret as end-of-body. See https://github.com/WordPress/reprint/issues/327.'
            );
        }
        $base_url = $options['base_url'] ?? null;
        if (!is_string($base_url) || $base_url === '') {
            throw new InvalidArgumentException('MultipartPushStreamClient requires a non-empty base_url option.');
        }
        $scheme = strtolower((string) parse_url($base_url, PHP_URL_SCHEME));
        $allow_http = $options['allow_http'] ?? false;
        if (!is_bool($allow_http) || ($scheme !== 'https' && $scheme !== 'http') || ($scheme === 'http' && !$allow_http)) {
            throw new InvalidArgumentException(
                'Push base_url must be https://, unless allow_http is true for an explicit http:// target.'
            );
        }
        $hmac_client = $options['hmac_client'] ?? null;
        if (!$hmac_client instanceof Site_Export_HMAC_Client) {
            throw new InvalidArgumentException('MultipartPushStreamClient requires a Site_Export_HMAC_Client.');
        }
        $this->base_url = rtrim($base_url, '?&');
        $this->hmac_client = $hmac_client;
        $this->request_sizer = $options['request_sizer'] ?? new PushRequestSizer();
        if (!$this->request_sizer instanceof PushRequestSizer) {
            throw new InvalidArgumentException('request_sizer must be a PushRequestSizer.');
        }

        $this->chunk_bytes = $this->positive_int_option($options, 'chunk_bytes', 4 * 1024 * 1024);
        $this->max_part_bytes = $this->positive_int_option($options, 'max_part_bytes', PHP_INT_MAX);
        $this->connect_timeout = $this->positive_int_option($options, 'connect_timeout', 30);
        $this->stall_timeout = $this->positive_int_option($options, 'stall_timeout', 60);
        $this->response_timeout = $this->positive_int_option($options, 'response_timeout', 300);
    }

    /**
     * Starts one signed upload request for the supplied target-owned push session ID.
     *
     * This resets all per-request state, generates a fresh MIME boundary, adds
     * the easy handle to the reusable multi handle, and pumps until libcurl asks
     * for body bytes. At that point the read callback is paused with no payload
     * queued and the caller may begin send_part() calls.
     *
     * Redirects are not followed because the HMAC covers this exact target URL.
     * The Expect header is explicitly disabled: PHP's development server never
     * answers `100 Continue`, which would otherwise consume the full phase
     * timeout before any body bytes move.
     *
     * @param string $push_session_id Target-issued 32-character hexadecimal push session ID.
     *
     * @return bool False when connection setup failed; get_last_error()
     *     explains why.
     *
     * @throws InvalidArgumentException If the push session ID is malformed.
     * @throws RuntimeException If another upload request is already open.
     */
    public function start_upload_request(string $push_session_id): bool
    {
        if ($this->curl_handle !== null) {
            throw new RuntimeException('An upload request is already open; call finish_request() first.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $push_session_id) !== 1) {
            throw new InvalidArgumentException('Target push_session_id must be a 32-character lowercase hexadecimal value.');
        }
        $this->boundary = 'reprint-' . bin2hex(random_bytes(16));
        $this->outbound_prefix = '';
        $this->outbound_payload = '';
        $this->outbound_suffix = '';
        $this->outbound_payload_offset = 0;
        $this->body_complete = false;
        $this->curl_requested_body = false;
        $this->transfer_finished = false;
        $this->transfer_error = null;
        $this->outbound_consumed_bytes = 0;
        $this->body_bytes_sent = 0;
        $this->parts_sent = 0;
        $this->last_error = null;

        $request_url = $this->endpoint_url('push_upload', ['push_session_id' => $push_session_id]);
        $headers = $this->hmac_client->get_envelope_auth_headers('POST', $request_url);
        $headers['Content-Type'] = 'multipart/mixed; boundary=' . $this->boundary;
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        // php -S never answers 100-continue. Sending the request head then
        // one bounded body is more useful than stalling every local test and
        // every compatible host for an interim response it will not send.
        $header_lines[] = 'Expect:';

        $this->curl_handle = curl_init($request_url);
        if (function_exists('reprint_apply_curl_proxy_from_env')) {
            reprint_apply_curl_proxy_from_env($this->curl_handle);
        }
        if (function_exists('reprint_apply_curl_ca_bundle')) {
            reprint_apply_curl_ca_bundle($this->curl_handle);
        }
        curl_setopt_array($this->curl_handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_READFUNCTION => function ($curl_handle, $stream, int $length) {
                $this->curl_requested_body = true;
                if ($this->outbound_prefix !== '') {
                    return $this->consume_string('outbound_prefix', $length);
                }
                if ($this->outbound_payload_offset < strlen($this->outbound_payload)) {
                    $piece = substr($this->outbound_payload, $this->outbound_payload_offset, $length);
                    $this->outbound_payload_offset += strlen($piece);
                    $this->outbound_consumed_bytes += strlen($piece);
                    if ($this->outbound_payload_offset >= strlen($this->outbound_payload)) {
                        $this->outbound_payload = '';
                        $this->outbound_payload_offset = 0;
                    }
                    return $piece;
                }
                if ($this->outbound_suffix !== '') {
                    return $this->consume_string('outbound_suffix', $length);
                }
                if ($this->body_complete) {
                    return '';
                }
                return CURL_READFUNC_PAUSE;
            },
        ]);

        if ($this->multi_handle === null) {
            $this->multi_handle = curl_multi_init();
        }
        curl_multi_add_handle($this->multi_handle, $this->curl_handle);
        $deadline = microtime(true) + $this->connect_timeout;
        while (!$this->curl_requested_body && !$this->transfer_finished) {
            if (microtime(true) > $deadline) {
                $this->transfer_error = 'Timed out after ' . $this->connect_timeout . 's opening the multipart upload request.';
                break;
            }
            $this->pump_transfer();
        }
        if (!$this->curl_requested_body) {
            $this->last_error = $this->transfer_error ?? 'The multipart upload ended before the request body opened.';
            curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
            $this->curl_handle = null;
            return false;
        }
        return true;
    }

    /**
     * Sends one already-read bounded multipart part over the active transfer.
     *
     * Headers are computed from strlen($part['payload']), so a short source read
     * produces a smaller truthful frame rather than a body shorter than its
     * declared Content-Length. The method first verifies that the complete MIME
     * part and closing delimiter fit the current request-body budget. If they do,
     * it resumes cURL and pumps until every byte of this part has been consumed
     * or the transfer ends/stalls.
     *
     * False before transmission means the caller should close this request and
     * retry the same logical part in another one. False after a transport failure
     * has an indeterminate target outcome; finish_request() classifies the
     * response and the high-level driver reconciles target status before reuse.
     *
     * @param array<string,mixed> $part {
     *     One multipart part to send.
     *
     *     @type string $type Required. `file`, `directory`, `symlink`, or
     *         `delete-list`.
     *     @type string $payload Required raw body bytes. Must be empty for a
     *         directory or symlink.
     *     @type string $path Required target-relative path for a file,
     *         directory, or symlink.
     *     @type int $total_bytes Required complete source size for a file.
     *     @type int $offset Required target-confirmed byte offset for a file or
     *         delete list.
     *     @type string $target Required raw link target for a symlink. Must be
     *         non-empty and contain no NUL byte.
     *     @type bool $complete Optional delete-list completion declaration.
     * }
     * @return bool True after the complete part was supplied to libcurl; false
     *     when it did not fit or the transfer stopped before completion.
     *
     * @throws InvalidArgumentException If part type, payload, or metadata is invalid.
     * @throws RuntimeException If no upload request is open.
     */
    public function send_part(array $part): bool
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException('No upload request is open; call start_upload_request() before send_part().');
        }
        if ($this->transfer_finished) {
            return false;
        }
        $payload = $part['payload'] ?? null;
        if (!is_string($payload)) {
            throw new InvalidArgumentException('Multipart push part payload must be a string.');
        }
        $headers = $this->part_headers($part, strlen($payload));
        $prefix = '--' . $this->boundary . "\r\n";
        foreach ($headers as $name => $value) {
            $prefix .= $name . ': ' . $value . "\r\n";
        }
        $prefix .= "\r\n";

        $part_body_bytes = strlen($prefix) + strlen($payload) + 2;
        if ($this->body_bytes_sent + $part_body_bytes + $this->closing_boundary_bytes() > $this->request_sizer->request_body_bytes()) {
            return false;
        }

        $this->outbound_prefix = $prefix;
        $this->outbound_payload = $payload;
        $this->outbound_payload_offset = 0;
        $this->outbound_suffix = "\r\n";
        curl_pause($this->curl_handle, CURLPAUSE_CONT);

        $seen_bytes = $this->outbound_consumed_bytes;
        $last_progress_at = microtime(true);
        while (($this->outbound_prefix !== '' || $this->outbound_payload !== '' || $this->outbound_suffix !== '') && !$this->transfer_finished) {
            if ($seen_bytes !== $this->outbound_consumed_bytes) {
                $seen_bytes = $this->outbound_consumed_bytes;
                $last_progress_at = microtime(true);
            } elseif (microtime(true) - $last_progress_at > $this->stall_timeout) {
                $this->transfer_error = 'The multipart upload stalled: no bytes moved for ' . $this->stall_timeout . 's.';
                $this->transfer_finished = true;
                break;
            }
            $this->pump_transfer();
        }
        if ($this->outbound_prefix !== '' || $this->outbound_payload !== '' || $this->outbound_suffix !== '') {
            $this->outbound_prefix = '';
            $this->outbound_payload = '';
            $this->outbound_suffix = '';
            $this->outbound_payload_offset = 0;
            return false;
        }
        $this->body_bytes_sent += $part_body_bytes;
        ++$this->parts_sent;
        return true;
    }

    /**
     * Returns the safe maximum for the caller's next file read.
     *
     * The result is bounded by the in-memory chunk limit, target part limit,
     * and remaining decoded entity-body budget after reserving worst-case MIME
     * headers and the closing boundary. The path, total size, and offset are
     * encoded exactly as send_part() will encode them. Zero means the caller
     * should finish this request before reading more source bytes.
     *
     * @param string $path Raw target-relative file path.
     * @param int $total_bytes Current source file size.
     * @param int $offset Target-confirmed offset for the next piece.
     * @return int Maximum payload bytes to read, or zero when no part fits.
     *
     * @throws InvalidArgumentException If total or offset is inconsistent.
     * @throws RuntimeException If no upload request is open.
     */
    public function next_file_body_bytes(string $path, int $total_bytes, int $offset): int
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException('No upload request is open; call start_upload_request() before next_file_body_bytes().');
        }
        if ($total_bytes < 0 || $offset < 0 || $offset > $total_bytes) {
            throw new InvalidArgumentException('File part total and offset must be non-negative and offset must not exceed total.');
        }
        return $this->next_body_bytes([
            'type' => 'file',
            'path' => $path,
            'total_bytes' => $total_bytes,
            'offset' => $offset,
            'payload' => '',
        ]);
    }

    /**
     * Returns the safe maximum for the next raw delete-stream read.
     *
     * @param int $offset Target-confirmed raw delete-stream byte offset.
     * @return int Maximum payload bytes to read, or zero when no part fits.
     */
    public function next_delete_body_bytes(int $offset): int
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException('No upload request is open; call start_upload_request() before next_delete_body_bytes().');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Delete-list offset must be non-negative.');
        }
        return $this->next_body_bytes([
            'type' => 'delete-list',
            'offset' => $offset,
            'payload' => '',
        ]);
    }

    /**
     * Calculates body capacity from an empty file or delete-list descriptor.
     *
     * @param array<string,mixed> $part {
     *     Header fields for the next body whose payload has not been read.
     *
     *     @type string $type Required. `file` or `delete-list`.
     *     @type string $payload Required empty string used only for header sizing.
     *     @type string $path Required target-relative path for a file.
     *     @type int $total_bytes Required complete source size for a file.
     *     @type int $offset Required target-confirmed byte offset.
     * }
     * @return int Maximum body bytes allowed after MIME overhead and the close.
     */
    private function next_body_bytes(array $part): int
    {
        // Reserve enough digits for a PHP integer Content-Length plus all
        // headers; the actual part is charged after send_part().
        $headers = $this->part_headers($part, 0);
        $headers['Content-Length'] = (string) PHP_INT_MAX;
        $overhead = strlen('--' . $this->boundary . "\r\n\r\n\r\n") + 2;
        foreach ($headers as $name => $value) {
            $overhead += strlen($name) + 2 + strlen($value) + 2;
        }
        $remaining = $this->request_sizer->request_body_bytes()
            - $this->body_bytes_sent
            - $overhead
            - $this->closing_boundary_bytes();
        return max(0, min($this->chunk_bytes, $this->max_part_bytes, $remaining));
    }

    /**
     * Indicates whether the current request has ended or only its close still fits.
     *
     * This is a quick lifecycle check after a successful send. Callers preparing
     * a file should still use next_file_body_bytes(), which accounts for that
     * particular part's MIME headers.
     *
     * @return bool True after transport completion or request-budget exhaustion.
     *
     * @throws RuntimeException If no upload request is open.
     */
    public function should_finish_request(): bool
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException('No upload request is open; call start_upload_request() before should_finish_request().');
        }
        return $this->transfer_finished
            || $this->body_bytes_sent + $this->closing_boundary_bytes() >= $this->request_sizer->request_body_bytes();
    }

    /**
     * Finishes the MIME body, waits for the response, and closes the easy handle.
     *
     * When the transfer is still active, this queues the closing boundary and
     * signals EOF only after cURL consumes it. The finish phase has its own
     * no-progress timer beginning when that closing boundary is queued, not a
     * total deadline measured from request start. Redirects,
     * structured target rejections, HTTP 413, invalid JSON, and cURL failures are
     * converted into stable `complete`, `retry`, or `failed` results.
     *
     * A 413 permanently lowers the learned sizing ceiling. Other ambiguous
     * request failures shrink temporarily. An accepted request records sizing
     * success only if at least one part was sent; an accepted empty request is
     * not evidence that a larger body would succeed.
     *
     * The result contains these keys:
     *
     * - `status`: `complete`, `retry`, or `failed`.
     * - `reason`: machine-readable failure classification, or null on success.
     * - `detail`: human-readable failure detail, or null when none was supplied.
     * - `response`: decoded target JSON, or null when no usable JSON arrived.
     * - `parts_sent`: complete MIME parts supplied in this request.
     * - `body_bytes_sent`: MIME entity-body bytes accounted for this request.
     *
     * @return array {
     *     Classified request result and transmission counters.
     *
     *     @type string      $status          Request status.
     *     @type string|null $reason          Machine-readable failure reason, or
     *                                       null on success.
     *     @type string|null $detail          Human-readable failure detail, or
     *                                       null when none was supplied.
     *     @type array|null  $response        Decoded target JSON, or null when
     *                                       no usable JSON arrived.
     *     @type int         $parts_sent      Complete MIME parts sent.
     *     @type int         $body_bytes_sent MIME entity-body bytes sent.
     * }
     * @phpstan-return array{
     *     status:string,
     *     reason:?string,
     *     detail:?string,
     *     response:?array<string,mixed>,
     *     parts_sent:int,
     *     body_bytes_sent:int
     * }
     *
     * @throws RuntimeException If no upload request is open.
     */
    public function finish_request(): array
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException('No upload request is open; call start_upload_request() before finish_request().');
        }
        if (!$this->transfer_finished) {
            $this->outbound_prefix = '--' . $this->boundary . "--\r\n";
            $this->body_bytes_sent += strlen($this->outbound_prefix);
            $this->outbound_payload = '';
            $this->outbound_suffix = '';
            $this->body_complete = true;
            curl_pause($this->curl_handle, CURLPAUSE_CONT);
            $seen_outbound_bytes = $this->outbound_consumed_bytes;
            $seen_inbound_bytes = (float) curl_getinfo($this->curl_handle, CURLINFO_SIZE_DOWNLOAD)
                + (int) curl_getinfo($this->curl_handle, CURLINFO_HEADER_SIZE);
            $last_progress_at = microtime(true);
            while (!$this->transfer_finished) {
                $this->pump_transfer();
                $inbound_bytes = (float) curl_getinfo($this->curl_handle, CURLINFO_SIZE_DOWNLOAD)
                    + (int) curl_getinfo($this->curl_handle, CURLINFO_HEADER_SIZE);
                if ($seen_outbound_bytes !== $this->outbound_consumed_bytes || $seen_inbound_bytes !== $inbound_bytes) {
                    $seen_outbound_bytes = $this->outbound_consumed_bytes;
                    $seen_inbound_bytes = $inbound_bytes;
                    $last_progress_at = microtime(true);
                } elseif (microtime(true) - $last_progress_at > $this->response_timeout) {
                    $this->transfer_error = 'The multipart request finish phase stalled: no upload or response bytes moved for ' . $this->response_timeout . 's.';
                    break;
                }
            }
        }

        $http_code = (int) curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
        $redirect_url = (string) curl_getinfo($this->curl_handle, CURLINFO_REDIRECT_URL);
        $body = (string) curl_multi_getcontent($this->curl_handle);
        curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
        $this->curl_handle = null;

        if (in_array($http_code, [301, 302, 303, 307, 308], true)) {
            return [
                'status' => 'failed',
                'reason' => 'redirected',
                'detail' => $redirect_url === ''
                    ? 'The target redirected the upload. Use its final URL as the push base_url.'
                    : 'The target redirected to ' . $redirect_url . '. Use that address as the push base_url.',
                'response' => null,
                'parts_sent' => $this->parts_sent,
                'body_bytes_sent' => $this->body_bytes_sent,
            ];
        }
        $decoded = json_decode($body, true);
        if ($http_code === 413) {
            $reported_limit = is_array($decoded) ? ($decoded['post_max_bytes'] ?? null) : null;
            $decision = $this->request_sizer->record_too_large(is_numeric($reported_limit) ? (int) $reported_limit : null);
            return [
                'status' => $decision['action'] === 'give_up' ? 'failed' : 'retry',
                'reason' => 'request_too_large',
                'detail' => is_array($decoded) ? null : 'HTTP 413 Request Entity Too Large.',
                'response' => is_array($decoded) ? $decoded : null,
                'parts_sent' => $this->parts_sent,
                'body_bytes_sent' => $this->body_bytes_sent,
            ];
        }
        if (!is_array($decoded)) {
            if ($body !== '') {
                return [
                    'status' => 'failed',
                    'reason' => 'malformed_response',
                    'detail' => 'Invalid JSON response (HTTP ' . $http_code . '): ' . substr($body, 0, 160),
                    'response' => null,
                    'parts_sent' => $this->parts_sent,
                    'body_bytes_sent' => $this->body_bytes_sent,
                ];
            }
            $decision = $this->request_sizer->record_request_failure();
            return [
                'status' => $decision['action'] === 'give_up' ? 'failed' : 'retry',
                'reason' => $decision['action'] === 'give_up' ? 'request_size_exhausted' : 'request_failed',
                'detail' => $this->transfer_error ?? 'Invalid JSON response (HTTP ' . $http_code . '): ' . substr($body, 0, 160),
                'response' => null,
                'parts_sent' => $this->parts_sent,
                'body_bytes_sent' => $this->body_bytes_sent,
            ];
        }
        $decoded['http_code'] = $http_code;
        $result = $this->classify_response($decoded, ['accepted']);
        if ($result['status'] !== 'complete') {
            return $result;
        }
        if ($this->parts_sent > 0) {
            $this->request_sizer->record_success();
        }
        return $result;
    }

    /**
     * Sends one signed control request and decodes its JSON response.
     *
     * Control calls use a no-progress timeout rather than a total-transfer
     * deadline and refuse redirects so signatures are never replayed elsewhere.
     *
     * @param string $method GET or POST.
     * @param string $endpoint Protocol endpoint query value.
     * @param array<string,mixed> $parameters Endpoint-specific query parameters.
     *     Their keys are encoded and signed but are not interpreted here.
     * @param string[] $expected_statuses Successful protocol statuses for this endpoint.
     * @return array {
     *     Response classification. Keys have the meanings documented by
     *     finish_request().
     *
     *     @type string      $status          Request status.
     *     @type string|null $reason          Machine-readable failure reason, or
     *                                       null on success.
     *     @type string|null $detail          Human-readable failure detail, or
     *                                       null when none was supplied.
     *     @type array       $response        Decoded target JSON.
     *     @type int         $parts_sent      Complete MIME parts sent.
     *     @type int         $body_bytes_sent MIME entity-body bytes sent.
     * }
     * @phpstan-return array{
     *     status:string,
     *     reason:?string,
     *     detail:?string,
     *     response:array<string,mixed>,
     *     parts_sent:int,
     *     body_bytes_sent:int
     * }
     *
     * @throws InvalidArgumentException If the method is unsupported.
     * @throws RuntimeException If transport, redirect, or JSON decoding fails.
     */
    public function control_request(string $method, string $endpoint, array $parameters, array $expected_statuses): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Multipart push control method must be GET or POST.');
        }
        if ($expected_statuses === [] || count(array_filter($expected_statuses, 'is_string')) !== count($expected_statuses)) {
            throw new InvalidArgumentException('Multipart push control requests require one or more string success statuses.');
        }
        $url = $this->endpoint_url($endpoint, $parameters);
        $headers = $this->hmac_client->get_envelope_auth_headers($method, $url);
        $lines = ['Accept: application/json', 'Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        if (function_exists('reprint_apply_curl_proxy_from_env')) {
            reprint_apply_curl_proxy_from_env($handle);
        }
        if (function_exists('reprint_apply_curl_ca_bundle')) {
            reprint_apply_curl_ca_bundle($handle);
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => $method === 'POST' ? '' : null,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            // A control request has a bounded response, but it must not use
            // CURLOPT_TIMEOUT: that is a total-transfer deadline and kills
            // a slow connection that is still moving bytes. libcurl's low
            // speed timer is a stall timeout instead.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $this->response_timeout,
        ]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $http_code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $redirect_url = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
        curl_close($handle);
        if (in_array($http_code, [301, 302, 303, 307, 308], true)) {
            throw new RuntimeException('The target redirected to ' . ($redirect_url === '' ? 'another address' : $redirect_url) . '. Use that address as the push base_url.');
        }
        if (!is_string($body)) {
            throw new RuntimeException('Push control request failed: ' . ($error === '' ? 'no response' : $error) . '.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Push control request returned invalid JSON (HTTP ' . $http_code . '): ' . substr($body, 0, 160));
        }
        $decoded['http_code'] = $http_code;
        return $this->classify_response($decoded, $expected_statuses);
    }

    /**
     * Returns learned request-sizing state suitable for the local checkpoint.
     *
     * The returned checkpoint contains:
     *
     * - `request_body_bytes`: current decoded entity-body budget.
     * - `ceiling_bytes`: learned session ceiling, or null while unknown.
     * - `growth_holdoff_remaining`: accepted requests still required before growth.
     *
     * @return array {
     *     Serializable PushRequestSizer state.
     *
     *     @type int      $request_body_bytes       Current decoded entity-body
     *                                             budget.
     *     @type int|null $ceiling_bytes            Learned session ceiling, or
     *                                             null while unknown.
     *     @type int      $growth_holdoff_remaining Accepted requests still
     *                                             required before growth.
     * }
     * @phpstan-return array{
     *     request_body_bytes:int,
     *     ceiling_bytes:?int,
     *     growth_holdoff_remaining:int
     * }
     */
    public function get_request_sizer_state(): array
    {
        return $this->request_sizer->get_state();
    }

    /**
     * Applies target-reported entity-body ceilings to future upload requests.
     *
     * Null and non-positive candidates are ignored by PushRequestSizer; useful
     * limits may only lower the learned session ceiling.
     *
     * @param array<int,int|float|string|null> $limits Remote byte-limit
     *     candidates. Numeric values are cast to integers; null and non-positive
     *     values are ignored.
     */
    public function apply_reported_limits(array $limits): void
    {
        $this->request_sizer->apply_reported_limits($limits);
    }

    /**
     * Lowers the maximum payload bytes allowed in one MIME part.
     *
     * A create response owns this ceiling. It limits one part, while the request
     * sizer separately limits the complete decoded entity body. Repeated calls
     * can only tighten the current value.
     *
     * @param int $max_part_bytes Positive target-advertised part limit.
     *
     * @throws InvalidArgumentException If the limit is not positive.
     */
    public function set_max_part_bytes(int $max_part_bytes): void
    {
        if ($max_part_bytes <= 0) {
            throw new InvalidArgumentException('max_part_bytes must be a positive integer.');
        }
        $this->max_part_bytes = min($this->max_part_bytes, $max_part_bytes);
    }

    /**
     * Returns why the most recent start_upload_request() could not open a body.
     *
     * The value is reset at the start of each request. Errors after body setup
     * are instead returned by finish_request().
     *
     * @return string|null Setup failure detail, or null when none is recorded.
     */
    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    /**
     * Encodes and validates the protocol headers for one already-read payload.
     *
     * Paths and symlink targets are base64 because filesystem byte strings are
     * not necessarily valid UTF-8. Content-Length is always derived from the
     * payload already in hand. Metadata parts require empty bodies. Delete-list
     * payloads may end within a NUL-delimited record and resume in a later part.
     *
     * `$part` supports the following keys:
     *
     * - `type`: required `file`, `directory`, `symlink`, or `delete-list`.
     * - `path`: required for file, directory, and symlink parts.
     * - `total_bytes`: required complete source size for a file.
     * - `offset`: required target-confirmed offset for a file or delete list.
     * - `target`: required raw link target for a symlink.
     * - `complete`: optional delete-list completion declaration.
     * - `payload`: supplied by send_part(); only its separately computed byte
     *   count is used here.
     *
     * The returned map always contains `X-Chunk-Type` and `Content-Length`.
     * File parts add `X-File-Path`, `X-File-Size`, and `X-Chunk-Offset`;
     * directories add `X-Directory-Path`; symlinks add `X-Symlink-Path` and
     * `X-Symlink-Target`; delete lists add `X-Delete-Offset`, optionally
     * `X-Delete-Complete`, and an octet-stream `Content-Type`.
     *
     * @param array<string,mixed> $part Part descriptor using the keys above.
     * @param int $payload_bytes Exact strlen() of its payload.
     * @return array<string,string> Type-specific MIME headers in wire spelling.
     *
     * @throws InvalidArgumentException If type-specific fields are invalid.
     */
    private function part_headers(array $part, int $payload_bytes): array
    {
        if ($payload_bytes > $this->max_part_bytes) {
            throw new InvalidArgumentException(
                'Multipart part payload is ' . $payload_bytes . ' bytes but the target maximum is '
                . $this->max_part_bytes . ' bytes.'
            );
        }
        $type = $part['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['file', 'directory', 'symlink', 'delete-list'], true)) {
            throw new InvalidArgumentException('Multipart push part type must be file, directory, symlink, or delete-list.');
        }
        $headers = ['X-Chunk-Type' => $type];
        if ($type === 'file') {
            $path = $this->non_empty_string_part_field($part, 'path', 'file');
            $total = $part['total_bytes'] ?? null;
            $offset = $part['offset'] ?? null;
            if (!is_int($total) || !is_int($offset) || $total < 0 || $offset < 0 || $offset + $payload_bytes > $total) {
                throw new InvalidArgumentException('File part must have non-negative total_bytes and offset that contain its payload.');
            }
            $headers['X-File-Path'] = base64_encode($path);
            $headers['X-File-Size'] = (string) $total;
            $headers['X-Chunk-Offset'] = (string) $offset;
        } elseif ($type === 'directory') {
            if ($payload_bytes !== 0) {
                throw new InvalidArgumentException('Directory parts must have an empty body.');
            }
            $headers['X-Directory-Path'] = base64_encode($this->non_empty_string_part_field($part, 'path', $type));
        } elseif ($type === 'symlink') {
            if ($payload_bytes !== 0) {
                throw new InvalidArgumentException('Symlink parts must have an empty body.');
            }
            $headers['X-Symlink-Path'] = base64_encode($this->non_empty_string_part_field($part, 'path', 'symlink'));
            $target = $part['target'] ?? null;
            if (!is_string($target) || $target === '' || strpos($target, "\0") !== false) {
                throw new InvalidArgumentException('Symlink part target must be a non-empty string without NUL.');
            }
            $headers['X-Symlink-Target'] = base64_encode($target);
        } else {
            $offset = $part['offset'] ?? null;
            if (!is_int($offset) || $offset < 0) {
                throw new InvalidArgumentException('Delete-list parts require a non-negative target-confirmed offset.');
            }
            $headers['X-Delete-Offset'] = (string) $offset;
            if (!empty($part['complete'])) {
                $headers['X-Delete-Complete'] = '1';
            }
            $headers['Content-Type'] = 'application/octet-stream';
        }
        $headers['Content-Length'] = (string) $payload_bytes;
        return $headers;
    }

    /**
     * Returns one required non-empty string from a part descriptor.
     *
     * @param array<string,mixed> $part Part descriptor containing the key named
     *     by `$field`.
     * @param string $field Required array key.
     * @param string $type Part type named in validation errors.
     * @return string Validated field value.
     *
     * @throws InvalidArgumentException If the field is absent or empty.
     */
    private function non_empty_string_part_field(array $part, string $field, string $type): string
    {
        $value = $part[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($type . ' part field ' . $field . ' must be a non-empty string.');
        }
        return $value;
    }

    /**
     * Builds the exact query URL which is later covered by envelope HMAC.
     *
     * @param string $endpoint Protocol endpoint value.
     * @param array<string,mixed> $parameters Endpoint-specific request-target
     *     values. Keys are encoded verbatim; this method adds `endpoint`.
     * @return string RFC 3986-encoded target URL.
     */
    private function endpoint_url(string $endpoint, array $parameters): string
    {
        $parameters = array_merge(['endpoint' => $endpoint], $parameters);
        return $this->base_url . (strpos($this->base_url, '?') === false ? '?' : '&') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Returns decoded entity-body bytes reserved for the current closing delimiter.
     *
     * @return int Boundary token, final `--`, and CRLF byte count.
     */
    private function closing_boundary_bytes(): int
    {
        return strlen('--' . $this->boundary . "--\r\n");
    }

    /**
     * Consumes one pending upload field and advances the stall-progress counter.
     *
     * Payload uses an offset to avoid repeatedly copying a shrinking large
     * string; the prefix and suffix are tiny syntax strings and use this helper.
     *
     * @param string $property Pending string property requested by cURL.
     * @param int $length Maximum bytes requested by the read callback.
     * @return string Next bytes supplied to cURL.
     */
    private function consume_string(string $property, int $length): string
    {
        $value = $this->$property;
        $piece = substr($value, 0, $length);
        $this->$property = (string) substr($value, strlen($piece));
        $this->outbound_consumed_bytes += strlen($piece);
        return $piece;
    }

    /**
     * Advances libcurl once and records terminal transfer state.
     *
     * curl_multi_select() waits only 50 ms when no completion is available, so
     * caller loops can enforce their phase-specific progress deadlines without
     * turning those deadlines into CURLOPT_TIMEOUT total-transfer limits.
     */
    private function pump_transfer(): void
    {
        do {
            $status = curl_multi_exec($this->multi_handle, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while (($message = curl_multi_info_read($this->multi_handle)) !== false) {
            if ($message['msg'] === CURLMSG_DONE) {
                $this->transfer_finished = true;
                if ($message['result'] !== CURLE_OK) {
                    $this->transfer_error = curl_error($this->curl_handle) ?: curl_strerror((int) $message['result']);
                }
            }
        }
        if (!$this->transfer_finished) {
            curl_multi_select($this->multi_handle, 0.05);
        }
    }

    /**
     * Returns a positive integer option, distinguishing absence from invalid input.
     *
     * Numeric strings are accepted because CLI arguments and persisted state may
     * supply them. A present invalid value throws rather than silently choosing
     * the default.
     *
     * @param array<string,mixed> $options Constructor option map documented by
     *     __construct(). This helper reads `chunk_bytes`, `max_part_bytes`,
     *     `connect_timeout`, `stall_timeout`, or `response_timeout` as selected
     *     by `$name`.
     * @param string $name Option key.
     * @param int $default Value used only when the key is absent.
     * @return int Validated positive integer.
     */
    private function positive_int_option(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? $default;
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException($name . ' must be a positive integer.');
        }
        return (int) $value;
    }

    /**
     * Classifies every decoded upload and control response by protocol reason.
     *
     * `busy` and `offset_gap` are recoverable because a later request can use
     * the receiver-confirmed cursor. Every other rejection fails the request; HTTP
     * status alone never promotes an unknown reason into a retry.
     *
     * Classification reads these response keys:
     *
     * - `status`: target protocol status compared with `$expected_statuses`.
     * - `reason`: optional machine-readable rejection reason. Only `busy` and
     *   `offset_gap` are recoverable.
     * - `detail`: optional human-readable rejection detail.
     * - `http_code`: observed HTTP status used when no detail was supplied.
     *
     * All other endpoint-specific keys are retained unchanged in `response`.
     *
     * @param array<string,mixed> $response Decoded target response containing
     *     the classification keys above.
     * @param string[] $expected_statuses Successful statuses for this request.
     * @return array {
     *     Stable result whose keys are documented by finish_request().
     *
     *     @type string      $status          Request status.
     *     @type string|null $reason          Machine-readable failure reason, or
     *                                       null on success.
     *     @type string|null $detail          Human-readable failure detail, or
     *                                       null when none was supplied.
     *     @type array       $response        Decoded target JSON.
     *     @type int         $parts_sent      Complete MIME parts sent.
     *     @type int         $body_bytes_sent MIME entity-body bytes sent.
     * }
     * @phpstan-return array{
     *     status:string,
     *     reason:?string,
     *     detail:?string,
     *     response:array<string,mixed>,
     *     parts_sent:int,
     *     body_bytes_sent:int
     * }
     */
    private function classify_response(array $response, array $expected_statuses): array
    {
        if (in_array($response['status'] ?? null, $expected_statuses, true)) {
            return [
                'status' => 'complete',
                'reason' => null,
                'detail' => null,
                'response' => $response,
                'parts_sent' => $this->parts_sent,
                'body_bytes_sent' => $this->body_bytes_sent,
            ];
        }
        $reason = is_string($response['reason'] ?? null) ? $response['reason'] : 'unexpected_response';
        return [
            'status' => in_array($reason, ['busy', 'offset_gap'], true) ? 'retry' : 'failed',
            'reason' => $reason,
            'detail' => is_string($response['detail'] ?? null)
                ? $response['detail']
                : 'HTTP ' . (int) ($response['http_code'] ?? 0),
            'response' => $response,
            'parts_sent' => $this->parts_sent,
            'body_bytes_sent' => $this->body_bytes_sent,
        ];
    }
}
