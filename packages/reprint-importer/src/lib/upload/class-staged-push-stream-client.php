<?php
/**
 * Sender for the staged push stream endpoint.
 *
 * A push stream is one authenticated HTTP request whose body is a sequence of
 * framed file chunks. The target commits each frame into
 * Site_Export_Staged_Artifacts as it is read, so a broken connection can be
 * retried from the last cursor the sender has, or from the beginning with the
 * target absorbing duplicate/verified frames.
 *
 * The client is a pass-through wire. The caller reads a chunk of a file into
 * memory — at most next_chunk_body_bytes(), which is why a chunk is the one
 * thing that may be buffered — and send_chunk() sends it over the network
 * before returning:
 *
 *     $client->start_push_request();
 *     while (...) {
 *         if ($client->should_finish_request()) {
 *             $result = $client->finish_request();   // persist $result['cursor']
 *             $client->start_push_request();
 *         }
 *         $payload = fread($source_handle, $client->next_chunk_body_bytes());
 *         $client->send_chunk([
 *             'artifact_id' => $artifact_id,
 *             'offset'      => $offset,
 *             'total_bytes' => $total_bytes,
 *             'final'       => $offset + strlen($payload) >= $total_bytes,
 *             'payload'     => $payload,
 *         ]);
 *     }
 *     $result = $client->finish_request();
 *
 * The transfer runs through libcurl, driven with the curl_multi API so the
 * request stays open between chunks: send_chunk() hands the frame to curl's
 * read callback and pumps the transfer until libcurl has consumed every
 * byte. libcurl writes to the socket as it consumes, so when send_chunk()
 * returns true the frame has left for the network — at most libcurl's
 * upload buffer (64 KiB) sits between this process and the socket, never a
 * request body. Between chunks the read callback returns
 * CURL_READFUNC_PAUSE, which PHP's curl extension only supports since
 * PHP 8.1 — on 7.4/8.0 that return is misread as end-of-body and the upload
 * silently truncates, so the constructor refuses to run there. The full
 * story: https://github.com/WordPress/reprint/issues/327
 *
 * Two sizes govern the loop, and they are different dimensions. The chunk
 * is the small fixed in-memory unit of one fread. The request body budget
 * is what hosts and proxies actually limit — post_max_size,
 * client_max_body_size and friends measure the entity body, and nothing
 * compresses request bodies, so the bytes we write are the bytes that get
 * measured. That budget is learned per host by PushRequestSizer and charges
 * frame header lines alongside payloads; the transfer framing around them
 * is libcurl's business (chunked on HTTP/1.1, DATA frames on HTTP/2) and
 * rides in the sizer's safety margin. next_chunk_body_bytes() folds both
 * sizes into the one number a caller's fread needs.
 */
class StagedPushStreamClient
{
    private string $base_url;

    private ?Site_Export_HMAC_Client $hmac_client;

    private PushRequestSizer $request_sizer;

    private int $request_timeout;

    /** @var int|float Wall-clock budget per request, in seconds. */
    private $max_request_seconds;

    /** @var int In-memory unit of one caller fread, in bytes. */
    private int $chunk_bytes;

    /** @var resource|object|null curl easy handle for the open request */
    private $curl_handle = null;

    /** @var resource|object|null curl multi handle driving the open request */
    private $multi_handle = null;

    /** Bytes handed to send_chunk() that libcurl has not consumed yet. */
    private string $outbound_bytes = "";

    /** How far into $outbound_bytes libcurl's read callback has consumed. */
    private int $outbound_offset = 0;

    /** The read callback signals end-of-body when this is true. */
    private bool $body_complete = false;

    /**
     * libcurl asked the read callback for body bytes — the request head is
     * out and the connection is established.
     */
    private bool $curl_requested_body = false;

    private bool $transfer_finished = false;

    private ?string $transfer_error = null;

    private float $request_started_at = 0.0;

    private int $body_bytes_sent = 0;

    private int $chunks_sent = 0;

    private ?string $last_error = null;

    /**
     * @param array $options
     *   - base_url (string, required): the export API URL; endpoint is appended
     *     to its query string.
     *   - hmac_client (?Site_Export_HMAC_Client): envelope request signer.
     *   - request_sizer (?PushRequestSizer): request-body-size decisions;
     *     defaults to a fresh sizer. Pass one restored from persisted state
     *     to keep learned limits.
     *   - chunk_bytes (int): in-memory unit of one caller fread; unrelated
     *     to the request body budget. Default 4 MiB.
     *   - request_timeout (int): seconds without transfer progress (opening
     *     the request, handing one chunk to libcurl, or the response read)
     *     before the request fails. Default 120.
     *   - max_request_seconds (int|float): wall-clock budget per request;
     *     should_finish_request() turns true once a request is older than
     *     this. Soft — checked between chunks — so set it with margin below
     *     the host's execution/proxy limits. Default 30.
     */
    public function __construct(array $options)
    {
        if (PHP_VERSION_ID < 80100) {
            // Before 8.1, ext/curl only honors string returns from the read
            // callback: CURL_READFUNC_PAUSE falls through to 0, libcurl
            // reads that as end-of-body, and the upload silently truncates.
            throw new RuntimeException(
                "reprint push requires PHP 8.1 or newer: streaming request bodies through curl needs"
                . " CURL_READFUNC_PAUSE support, which PHP's curl extension added in 8.1 — on older PHP"
                . " the pause return is misread as end-of-body and the upload silently truncates."
                . " Current PHP is " . PHP_VERSION . "."
                . " See https://github.com/WordPress/reprint/issues/327 for the full story."
            );
        }

        $base_url = $options["base_url"] ?? null;
        if (!is_string($base_url) || $base_url === "") {
            throw new InvalidArgumentException("StagedPushStreamClient requires a base_url option.");
        }
        $this->base_url = $base_url;
        $this->hmac_client = $options["hmac_client"] ?? null;
        $this->request_sizer = $options["request_sizer"] ?? new PushRequestSizer();
        $chunk_bytes = $options["chunk_bytes"] ?? null;
        $this->chunk_bytes = is_numeric($chunk_bytes) && (int) $chunk_bytes > 0 ? (int) $chunk_bytes : 4 * 1024 * 1024;
        $timeout = $options["request_timeout"] ?? null;
        $this->request_timeout = is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 120;
        $max_request_seconds = $options["max_request_seconds"] ?? null;
        $this->max_request_seconds = is_numeric($max_request_seconds) && $max_request_seconds > 0 ? $max_request_seconds : 30;
    }

    /**
     * Open a staged_push request: connect and send the signed request head,
     * returning once libcurl asks for body bytes. The request body starts
     * empty; send_chunk() fills it.
     *
     * @return bool False when the connection could not be opened;
     *              get_last_error() says why.
     */
    public function start_push_request(): bool
    {
        if ($this->curl_handle !== null) {
            throw new RuntimeException("A push request is already open; call finish_request() before starting another.");
        }

        $this->outbound_bytes = "";
        $this->outbound_offset = 0;
        $this->body_complete = false;
        $this->curl_requested_body = false;
        $this->transfer_finished = false;
        $this->transfer_error = null;
        $this->body_bytes_sent = 0;
        $this->chunks_sent = 0;
        $this->last_error = null;

        $request_url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(["endpoint" => "staged_push"]);
        $request_headers = $this->hmac_client !== null
            ? $this->hmac_client->get_envelope_auth_headers("POST", $request_url)
            : [];
        $request_headers["Content-Type"] = Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE;

        $header_lines = [];
        foreach ($request_headers as $header_name => $header_value) {
            $header_lines[] = $header_name . ": " . $header_value;
        }
        // Suppress Expect: 100-continue; waiting for the interim response
        // would stall the first chunk for nothing.
        $header_lines[] = "Expect:";

        $this->curl_handle = curl_init($request_url);
        if (function_exists("reprint_apply_curl_proxy_from_env")) {
            reprint_apply_curl_proxy_from_env($this->curl_handle);
        }
        if (function_exists("reprint_apply_curl_ca_bundle")) {
            reprint_apply_curl_ca_bundle($this->curl_handle);
        }
        curl_setopt_array($this->curl_handle, [
            // Upload with no declared size: libcurl picks the transfer
            // framing (chunked on HTTP/1.1, DATA frames on HTTP/2).
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->request_timeout,
            // Total lifetime of one request, including caller time between
            // chunks; keep it above max_request_seconds plus response time.
            CURLOPT_TIMEOUT => $this->request_timeout,
            CURLOPT_READFUNCTION => function ($curl_handle, $stream, int $length) {
                $this->curl_requested_body = true;
                if ($this->outbound_offset < strlen($this->outbound_bytes)) {
                    $piece = substr($this->outbound_bytes, $this->outbound_offset, $length);
                    $this->outbound_offset += strlen($piece);
                    if ($this->outbound_offset >= strlen($this->outbound_bytes)) {
                        $this->outbound_bytes = "";
                        $this->outbound_offset = 0;
                    }
                    return $piece;
                }
                if ($this->body_complete) {
                    return "";
                }
                return CURL_READFUNC_PAUSE;
            },
        ]);

        $this->multi_handle = curl_multi_init();
        curl_multi_add_handle($this->multi_handle, $this->curl_handle);

        // Drive the transfer until the head is out — libcurl asking for body
        // bytes proves it — so connection and TLS failures surface here, not
        // in the middle of the caller's chunk loop.
        $deadline = microtime(true) + $this->request_timeout;
        while (!$this->curl_requested_body && !$this->transfer_finished) {
            if (microtime(true) > $deadline) {
                $this->transfer_error = "Timed out after " . $this->request_timeout . "s opening the push stream request.";
                break;
            }
            $this->pump_transfer();
        }
        if (!$this->curl_requested_body) {
            $this->last_error = $this->transfer_error ?? "The push stream request ended before the request head was sent.";
            curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
            curl_multi_close($this->multi_handle);
            $this->curl_handle = null;
            $this->multi_handle = null;
            return false;
        }

        $this->request_started_at = microtime(true);
        return true;
    }

    /**
     * Send one framed chunk — header line, then the payload — over the
     * network.
     *
     * This performs the actual network transmission: the frame is handed to
     * libcurl's read callback and the transfer is pumped until libcurl has
     * consumed every byte and written it toward the socket. When this
     * returns true the frame is on the network, not queued in this process;
     * at most libcurl's 64 KiB upload buffer trails behind.
     *
     * The payload's length is the frame's byte count; there is no separate
     * declaration to reconcile. Invalid descriptors throw with the exact
     * violated condition. Remote conditions do not throw: false means the
     * transfer ended early — dead connection or the target already
     * responded — and finish_request() reports which.
     *
     * @param array{artifact_id:string,offset:int,total_bytes:int,final:bool,payload:string} $chunk
     */
    public function send_chunk(array $chunk): bool
    {
        $artifact_id = $chunk["artifact_id"] ?? null;
        if (!is_string($artifact_id) || $artifact_id === "") {
            throw new InvalidArgumentException("Expected chunk field \"artifact_id\" to be a non-empty string.");
        }
        $offset = $chunk["offset"] ?? null;
        if (!is_int($offset) || $offset < 0) {
            throw new InvalidArgumentException("Expected chunk field \"offset\" to be a non-negative integer for \"" . $artifact_id . "\".");
        }
        $total_bytes = $chunk["total_bytes"] ?? null;
        if (!is_int($total_bytes) || $total_bytes < 0) {
            throw new InvalidArgumentException("Expected chunk field \"total_bytes\" to be a non-negative integer for \"" . $artifact_id . "\".");
        }
        $final = $chunk["final"] ?? null;
        if (!is_bool($final)) {
            throw new InvalidArgumentException("Expected chunk field \"final\" to be a boolean for \"" . $artifact_id . "\".");
        }
        $payload = $chunk["payload"] ?? null;
        if (!is_string($payload)) {
            throw new InvalidArgumentException("Expected chunk field \"payload\" to be a string for \"" . $artifact_id . "\".");
        }
        if ($offset + strlen($payload) > $total_bytes) {
            throw new InvalidArgumentException(
                "Chunk for \"" . $artifact_id . "\" spans bytes " . $offset . "-" . ($offset + strlen($payload)) . ", which exceeds total_bytes " . $total_bytes . "."
            );
        }
        if ($payload === "" && !$final && $total_bytes > 0) {
            throw new InvalidArgumentException(
                "Refusing a zero-byte non-final chunk for \"" . $artifact_id . "\" — the source file is shorter than its declared total_bytes " . $total_bytes . "."
            );
        }
        if ($final && $offset + strlen($payload) !== $total_bytes) {
            throw new InvalidArgumentException(
                "Chunk for \"" . $artifact_id . "\" is marked final at byte " . ($offset + strlen($payload)) . " but total_bytes is " . $total_bytes . "."
            );
        }

        if ($this->curl_handle === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before send_chunk().");
        }
        if ($this->transfer_finished) {
            return false;
        }

        $frame_header = json_encode([
            "type" => "chunk",
            "artifact_id" => $artifact_id,
            "offset" => $offset,
            "bytes" => strlen($payload),
            "total_bytes" => $total_bytes,
            "final" => $final,
        ], JSON_UNESCAPED_SLASHES);
        if ($frame_header === false) {
            throw new RuntimeException("Could not encode the staged push stream frame header for \"" . $artifact_id . "\".");
        }
        $frame_header .= "\n";

        $this->outbound_bytes = $frame_header . $payload;
        $this->outbound_offset = 0;
        curl_pause($this->curl_handle, CURLPAUSE_CONT);
        $deadline = microtime(true) + $this->request_timeout;
        while ($this->outbound_bytes !== "" && !$this->transfer_finished) {
            if (microtime(true) > $deadline) {
                $this->transfer_error = "Timed out after " . $this->request_timeout . "s sending push stream bytes.";
                $this->transfer_finished = true;
                break;
            }
            $this->pump_transfer();
        }
        if ($this->outbound_bytes !== "") {
            // The transfer ended mid-frame; drop the leftover so the read
            // callback cannot leak stale bytes into a later pump.
            $this->outbound_bytes = "";
            $this->outbound_offset = 0;
            return false;
        }

        $this->body_bytes_sent += strlen($frame_header) + strlen($payload);
        $this->chunks_sent++;
        return true;
    }

    /**
     * How many bytes the caller's next fread should ask for.
     *
     * The fixed chunk size — the in-memory unit — bounded by what remains of
     * the host-learned request body budget. That budget is denominated in
     * entity-body bytes, the dimension request-size limits measure: frame
     * header lines and payloads. Returns 0 when the request is full;
     * should_finish_request() is already true then. Near the end of a file
     * fread simply returns fewer bytes and the smaller frame is correct, so
     * callers need no min() of their own. The budget is soft: the header
     * riding along with the last chunk may overshoot it by one line, which
     * the sizer's safety margin absorbs.
     */
    public function next_chunk_body_bytes(): int
    {
        $remaining_body_budget = max(0, $this->request_sizer->request_body_bytes() - $this->body_bytes_sent);
        return min($this->chunk_bytes, $remaining_body_budget);
    }

    /**
     * Whether the current request should end now: its byte or time budget is
     * spent, or the transfer already ended (dead connection or an early
     * response).
     */
    public function should_finish_request(): bool
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before should_finish_request().");
        }
        return $this->transfer_finished
            || $this->next_chunk_body_bytes() === 0
            || (microtime(true) - $this->request_started_at) > $this->max_request_seconds;
    }

    /**
     * End the request body, read the target's response, and fold it into the
     * retry/cursor decision.
     *
     * The read callback reports end-of-body to libcurl, the transfer is
     * pumped to completion, and the response is interpreted. When the
     * transfer broke mid-stream, a parseable response still wins over the
     * transport error — a target that rejected mid-stream (413 from a proxy,
     * auth failure) breaks the upload but its response carries the reason
     * and a resume cursor.
     *
     * The returned cursor is the server-confirmed one from the response, or
     * null when no response arrived — resume from the last persisted cursor
     * or ask staged_status.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int}
     */
    public function finish_request(): array
    {
        if ($this->curl_handle === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before finish_request().");
        }

        if (!$this->transfer_finished) {
            $this->body_complete = true;
            curl_pause($this->curl_handle, CURLPAUSE_CONT);
            $deadline = microtime(true) + $this->request_timeout;
            while (!$this->transfer_finished) {
                if (microtime(true) > $deadline) {
                    $this->transfer_error = "Timed out after " . $this->request_timeout . "s waiting for the push stream response.";
                    break;
                }
                $this->pump_transfer();
            }
        }

        $http_code = (int) curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
        $response_body = (string) curl_multi_getcontent($this->curl_handle);
        curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
        curl_multi_close($this->multi_handle);
        $this->curl_handle = null;
        $this->multi_handle = null;

        $decoded = json_decode($response_body, true);
        if (!is_array($decoded)) {
            $decision = $this->request_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                $this->transfer_error
                    ?? "invalid JSON response (HTTP " . $http_code . "): " . substr($response_body, 0, 120)
            );
        }

        $response_cursor = is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : null;

        if ($http_code === 413 || ($decoded["reason"] ?? null) === "frame_too_large") {
            $reported_max_frame_bytes = $decoded["max_frame_bytes"] ?? ($decoded["max_request_bytes"] ?? null);
            $decision = $this->request_sizer->record_too_large(
                is_numeric($reported_max_frame_bytes) ? (int) $reported_max_frame_bytes : null
            );
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                $decision["action"] === "give_up" ? "chunk_size_exhausted" : "frame_too_large",
                null,
                $response_cursor
            );
        }

        if (($decoded["status"] ?? null) !== "complete") {
            return $this->result(
                "failed",
                is_string($decoded["reason"] ?? null) ? $decoded["reason"] : "unexpected_response",
                is_string($decoded["detail"] ?? null) ? $decoded["detail"] : ("HTTP " . $http_code),
                $response_cursor,
                (int) ($decoded["files_verified"] ?? 0)
            );
        }

        $this->request_sizer->record_success();
        return $this->result("complete", null, null, $response_cursor, (int) ($decoded["files_verified"] ?? 0));
    }

    /**
     * Why the last start_push_request() returned false.
     */
    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    /**
     * One curl_multi step: perform pending transfer work, harvest the
     * completion message, and wait briefly for socket activity.
     */
    private function pump_transfer(): void
    {
        do {
            $status = curl_multi_exec($this->multi_handle, $active_transfers);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while (($message = curl_multi_info_read($this->multi_handle)) !== false) {
            if ($message["msg"] === CURLMSG_DONE) {
                $this->transfer_finished = true;
                if ($message["result"] !== CURLE_OK) {
                    $this->transfer_error = curl_error($this->curl_handle) ?: curl_strerror((int) $message["result"]);
                }
            }
        }

        if (!$this->transfer_finished) {
            curl_multi_select($this->multi_handle, 0.05);
        }
    }

    /** @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int} */
    private function result(string $status, ?string $reason, ?string $detail, ?array $cursor = null, int $files_verified = 0): array
    {
        return [
            "status" => $status,
            "reason" => $reason,
            "detail" => $detail,
            "cursor" => $cursor,
            "files_verified" => $files_verified,
            "chunks_sent" => $this->chunks_sent,
            "body_bytes_sent" => $this->body_bytes_sent,
        ];
    }
}
