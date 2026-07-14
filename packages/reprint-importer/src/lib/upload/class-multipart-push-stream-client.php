<?php

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
 *     if (!$client->start_upload_request($session_id)) {
 *         throw new RuntimeException($client->get_last_error());
 *     }
 *
 *     while ($offset < $total_bytes) {
 *         $maximum = $client->next_file_body_bytes($path, $total_bytes, $offset);
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
 * deadlines: connection establishment, lack of upload progress, and waiting
 * for a response after the closing boundary. A slow upload which continues
 * moving bytes is allowed to continue.
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

    /** @var int Seconds allowed for the finish/response phase after the close is queued. */
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

    /** @var string Pending delimiter and header bytes consumed before payload. */
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
     * read callback without terminating the upload. Required options are
     * `base_url` and `hmac_client`. `request_sizer` may restore learned sizing;
     * `chunk_bytes`, `max_part_bytes`, `connect_timeout`, `stall_timeout`, and
     * `response_timeout` accept positive numeric overrides. HTTP is rejected
     * unless `allow_http` is explicitly true.
     *
     * Null optional values are treated as absent by the constructor's defaults.
     *
     * @param array<string,mixed> $options Transport, authentication, and limit options.
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
     * Starts one signed upload request for the supplied target-owned session id.
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
     * @param string $session_id Target-issued 32-character hexadecimal id.
     *
     * @return bool False when connection setup failed; get_last_error()
     *     explains why.
     *
     * @throws InvalidArgumentException If the session id is malformed.
     * @throws RuntimeException If another upload request is already open.
     */
    public function start_upload_request(string $session_id): bool
    {
        if ($this->curl_handle !== null) {
            throw new RuntimeException('An upload request is already open; call finish_request() first.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Target session_id must be a 32-character lowercase hexadecimal value.');
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

        $request_url = $this->endpoint_url('staged_session_upload', ['session_id' => $session_id]);
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
     * @param array<string,mixed> $part Type, payload, and type-specific metadata.
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
        // Reserve enough digits for a PHP integer Content-Length plus all
        // headers; the actual part is charged after send_part().
        $headers = $this->part_headers([
            'type' => 'file',
            'path' => $path,
            'total_bytes' => $total_bytes,
            'offset' => $offset,
            'payload' => '',
        ], 0);
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
     * deadline beginning when that closing boundary is queued, not at request
     * start. Redirects,
     * structured target rejections, HTTP 413, invalid JSON, and cURL failures are
     * converted into stable `complete`, `retry`, or `failed` results.
     *
     * A 413 permanently lowers the learned sizing ceiling. Other ambiguous
     * request failures shrink temporarily. An accepted request records sizing
     * success only if at least one part was sent; an accepted empty request is
     * not evidence that a larger body would succeed.
     *
     * @return array<string,mixed> Classification, reason/detail, decoded target
     *     response, and the sent part/body counts.
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
            $deadline = microtime(true) + $this->response_timeout;
            while (!$this->transfer_finished) {
                if (microtime(true) > $deadline) {
                    $this->transfer_error = 'No response arrived within ' . $this->response_timeout . 's of finishing the multipart upload body.';
                    break;
                }
                $this->pump_transfer();
            }
        }

        $http_code = (int) curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
        $redirect_url = (string) curl_getinfo($this->curl_handle, CURLINFO_REDIRECT_URL);
        $body = (string) curl_multi_getcontent($this->curl_handle);
        curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
        $this->curl_handle = null;

        if (in_array($http_code, [301, 302, 303, 307, 308], true)) {
            return $this->result('failed', 'redirected', $redirect_url === ''
                ? 'The target redirected the upload. Use its final URL as the push target.'
                : 'The target redirected to ' . $redirect_url . '. Use that address as the push target.');
        }
        $decoded = json_decode($body, true);
        if ($http_code === 413) {
            $reported_limit = is_array($decoded) ? ($decoded['post_max_bytes'] ?? null) : null;
            $decision = $this->request_sizer->record_too_large(is_numeric($reported_limit) ? (int) $reported_limit : null);
            return $this->result(
                $decision['action'] === 'give_up' ? 'failed' : 'retry',
                'request_too_large',
                is_array($decoded) ? null : 'HTTP 413 Request Entity Too Large.',
                is_array($decoded) ? $decoded : null
            );
        }
        if (!is_array($decoded)) {
            $decision = $this->request_sizer->record_request_failure();
            return $this->result(
                $decision['action'] === 'give_up' ? 'failed' : 'retry',
                $decision['action'] === 'give_up' ? 'request_size_exhausted' : 'request_failed',
                $this->transfer_error ?? 'Invalid JSON response (HTTP ' . $http_code . '): ' . substr($body, 0, 160)
            );
        }
        if (($decoded['status'] ?? null) !== 'accepted') {
            $reason = is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'unexpected_response';
            return $this->result(
                in_array($reason, ['busy'], true) ? 'retry' : 'failed',
                $reason,
                is_string($decoded['detail'] ?? null) ? $decoded['detail'] : 'HTTP ' . $http_code,
                $decoded
            );
        }
        if ($this->parts_sent > 0) {
            $this->request_sizer->record_success();
        }
        return $this->result('complete', null, null, $decoded);
    }

    /**
     * Sends one signed control request and decodes its JSON response.
     *
     * Control calls use a no-progress timeout rather than a total-transfer
     * deadline and refuse redirects so signatures are never replayed elsewhere.
     *
     * @param string $method GET or POST.
     * @param string $endpoint Protocol endpoint query value.
     * @param array<string,mixed> $parameters Additional signed query parameters.
     * @return array<string,mixed> Decoded target body plus its HTTP status code.
     *
     * @throws InvalidArgumentException If the method is unsupported.
     * @throws RuntimeException If transport, redirect, or JSON decoding fails.
     */
    public function control_request(string $method, string $endpoint, array $parameters = []): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Multipart push control method must be GET or POST.');
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
            throw new RuntimeException('The target redirected to ' . ($redirect_url === '' ? 'another address' : $redirect_url) . '. Use that address as the push target.');
        }
        if (!is_string($body)) {
            throw new RuntimeException('Push control request failed: ' . ($error === '' ? 'no response' : $error) . '.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Push control request returned invalid JSON (HTTP ' . $http_code . '): ' . substr($body, 0, 160));
        }
        $decoded['http_code'] = $http_code;
        return $decoded;
    }

    /**
     * Returns learned request-sizing state suitable for the local checkpoint.
     *
     * @return array<string,mixed> Serializable PushRequestSizer state.
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
     * @param array<int,mixed> $limits Remote limit candidates in bytes.
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
     * payload already in hand. Metadata parts require empty bodies; delete-list
     * payloads must end at a NUL record boundary.
     *
     * @param array<string,mixed> $part Caller-supplied part descriptor.
     * @param int $payload_bytes Exact strlen() of its payload.
     * @return array<string,string> MIME headers in wire spelling.
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
            $headers['X-Directory-Path'] = base64_encode($this->non_empty_string_part_field($part, 'path', 'directory'));
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
            if ($payload_bytes > 0 && substr((string) ($part['payload'] ?? ''), -1) !== "\0") {
                throw new InvalidArgumentException('Delete-list parts must end at a NUL path boundary.');
            }
            $headers['Content-Type'] = 'application/octet-stream';
        }
        $headers['Content-Length'] = (string) $payload_bytes;
        return $headers;
    }

    /**
     * Returns one required non-empty string from a part descriptor.
     *
     * @param array<string,mixed> $part Part descriptor being validated.
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
     * @param array<string,mixed> $parameters Additional request-target values.
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
     * @param array<string,mixed> $options Constructor options.
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
     * Builds the stable upload result shape consumed by MultipartPush.
     *
     * @param string $status `complete`, `retry`, or `failed`.
     * @param string|null $reason Machine-readable target/transport classification.
     * @param string|null $detail Human-readable failure detail.
     * @param array<string,mixed>|null $response Decoded target JSON when available.
     * @return array<string,mixed> Result plus current request counters.
     */
    private function result(string $status, ?string $reason, ?string $detail, ?array $response = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'response' => $response,
            'parts_sent' => $this->parts_sent,
            'body_bytes_sent' => $this->body_bytes_sent,
        ];
    }
}
