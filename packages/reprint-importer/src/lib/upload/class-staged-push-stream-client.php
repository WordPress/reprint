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
 * Bytes are written to the network as the caller advances the request: each
 * StagedPushStreamRequest::next_chunk() call reads the next file range from
 * disk and writes it straight to the request socket before returning. Nothing
 * accumulates a request body in memory — at most one 64 KiB disk read is in
 * flight. Callers decide how many frames go into one request, finalize it,
 * and resume the next request from the cursor.
 */
class StagedPushStreamClient
{
    private string $base_url;

    private ?Site_Export_HMAC_Client $hmac_client;

    private PushFrameSizer $frame_sizer;

    private int $request_timeout;

    /**
     * @param array $options
     *   - base_url (string, required): the export API URL; endpoint is appended
     *     to its query string.
     *   - hmac_client (?Site_Export_HMAC_Client): envelope request signer.
     *   - frame_sizer (?PushFrameSizer): frame-size decisions; defaults to a
     *     fresh frame sizer. Pass one restored from persisted state to keep
     *     learned limits.
     *   - request_timeout (int): seconds without socket progress (one write,
     *     or the response read) before the request fails.
     */
    public function __construct(array $options)
    {
        $base_url = $options["base_url"] ?? null;
        if (!is_string($base_url) || $base_url === "") {
            throw new InvalidArgumentException("StagedPushStreamClient requires a base_url option.");
        }
        $this->base_url = $base_url;
        $this->hmac_client = $options["hmac_client"] ?? null;
        $this->frame_sizer = $options["frame_sizer"] ?? new PushFrameSizer();
        $timeout = $options["request_timeout"] ?? null;
        $this->request_timeout = is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 120;
    }

    /**
     * Create one staged_push request that the caller advances chunk by chunk.
     *
     * The caller owns the nested loop: call StagedPushStreamRequest::next_chunk()
     * until the request budget or the caller's own budget says to stop, then
     * hand the request to push_request(). Each next_chunk() call writes that
     * frame to the network; the connection opens on the first one.
     *
     * @param array{max_chunks?:int|null,max_payload_bytes?:int|null} $limits
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     */
    public function create_request(StagedPushStreamProcessor $processor, ?array $cursor = null, array $limits = []): StagedPushStreamRequest
    {
        $request_url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(["endpoint" => "staged_push"]);

        $request_headers = $this->hmac_client !== null
            ? $this->hmac_client->get_envelope_auth_headers("POST", $request_url)
            : [];
        $request_headers["Content-Type"] = Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE;
        $request_headers["Transfer-Encoding"] = "chunked";
        $request_headers["Connection"] = "close";

        return new StagedPushStreamRequest(
            $request_url,
            $request_headers,
            $this->request_timeout,
            $processor->files(),
            $this->frame_sizer->chunk_bytes(),
            $cursor ?? $processor->cursor(),
            $limits["max_chunks"] ?? null,
            $limits["max_payload_bytes"] ?? null
        );
    }

    /**
     * Finish one staged_push request and return the request-level result.
     *
     * The frames themselves were already written to the network by
     * next_chunk(); this ends the request body, reads the target's response,
     * and folds it into the retry/cursor decision.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}
     */
    public function push_request(StagedPushStreamRequest $request): array
    {
        $request->finalize();
        $response = $request->finish();
        $request_start_cursor = $request->start_cursor();

        if ($response["http_code"] === 0 && $response["error"] === null) {
            // No frame was ever written, so no network exchange happened.
            return $this->result("request_complete", null, null, $request->cursor(), 0, 0, 0);
        }

        if ($response["error"] !== null) {
            $decision = $this->frame_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                $response["error"],
                $request_start_cursor,
                0,
                $request->bytes_streamed(),
                $request->chunks_streamed()
            );
        }

        $decoded = json_decode((string) $response["body"], true);
        if (!is_array($decoded)) {
            $decision = $this->frame_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                "invalid JSON response (HTTP " . (int) $response["http_code"] . "): " . substr((string) $response["body"], 0, 120),
                $request_start_cursor,
                0,
                $request->bytes_streamed(),
                $request->chunks_streamed()
            );
        }

        if ((int) $response["http_code"] === 413 || ($decoded["reason"] ?? null) === "frame_too_large") {
            $reported_max_frame_bytes = $decoded["max_frame_bytes"] ?? ($decoded["max_request_bytes"] ?? null);
            $decision = $this->frame_sizer->record_too_large(
                is_numeric($reported_max_frame_bytes) ? (int) $reported_max_frame_bytes : null
            );
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                $decision["action"] === "give_up" ? "chunk_size_exhausted" : "frame_too_large",
                null,
                is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : $request_start_cursor,
                0,
                $request->bytes_streamed(),
                $request->chunks_streamed()
            );
        }

        if (($decoded["status"] ?? null) !== "complete") {
            return $this->result(
                "failed",
                is_string($decoded["reason"] ?? null) ? $decoded["reason"] : "unexpected_response",
                is_string($decoded["detail"] ?? null) ? $decoded["detail"] : ("HTTP " . (int) $response["http_code"]),
                is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : $request->cursor(),
                (int) ($decoded["files_verified"] ?? 0),
                $request->bytes_streamed(),
                $request->chunks_streamed()
            );
        }

        $this->frame_sizer->record_success();
        return $this->result(
            "request_complete",
            null,
            null,
            is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : null,
            (int) ($decoded["files_verified"] ?? 0),
            $request->bytes_streamed(),
            $request->chunks_streamed()
        );
    }

    /**
     * Convenience wrapper that fills and sends one request.
     *
     * Prefer create_request()/push_request() when the caller needs to pause
     * inside a request after a caller-chosen number of chunks.
     *
     * @param array{max_chunks?:int|null,max_payload_bytes?:int|null} $limits
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}
     */
    public function push_next_request(StagedPushStreamProcessor $processor, ?array $cursor = null, array $limits = []): array
    {
        if ($processor->is_finished_at($cursor ?? $processor->cursor())) {
            return $this->result("complete", null, null, $cursor ?? $processor->cursor(), 0, 0, 0);
        }

        $request = $this->create_request($processor, $cursor, $limits);
        while ($request->next_chunk()) {
            // Fill this request up to the configured request budget.
        }
        return $this->push_request($request);
    }

    /** @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int} */
    private function result(string $status, ?string $reason, ?string $detail, ?array $cursor, int $files_verified, int $bytes_streamed, int $chunks_streamed): array
    {
        return [
            "status" => $status,
            "reason" => $reason,
            "detail" => $detail,
            "cursor" => $cursor,
            "files_verified" => $files_verified,
            "bytes_streamed" => $bytes_streamed,
            "chunks_streamed" => $chunks_streamed,
        ];
    }
}

/**
 * One live staged_push request.
 *
 * next_chunk() writes the next frame — header line plus the file's byte
 * range — straight to the request socket and only then returns. The socket
 * opens lazily on the first frame, so a request that never pushes a chunk
 * never touches the network. finish() ends the chunked request body and
 * reads the target's response.
 */
final class StagedPushStreamRequest
{
    /** Largest single disk read; also the largest write handed to the socket. */
    private const DISK_READ_BYTES = 65536;

    private string $request_url;

    /** @var array<string,string> */
    private array $request_headers;

    private int $request_timeout;

    /** @var array<int,array{artifact_id:string,source_path:string,total_bytes:int}> */
    private array $files;

    private int $frame_bytes;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $start_cursor;

    private ?int $max_chunks;

    private ?int $max_payload_bytes;

    private int $file_index = 0;

    private int $offset = 0;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $cursor = null;

    private int $chunks_streamed = 0;

    private int $payload_bytes_streamed = 0;

    private bool $finalized = false;

    /** @var resource|null */
    private $socket = null;

    private ?string $transport_error = null;

    /**
     * The target started responding (or closed) before this stream ended.
     * Its response decides the request now, so no further bytes are sent.
     */
    private bool $target_replied_early = false;

    /**
     * Response bytes consumed while probing read-readiness during a write;
     * read_response() treats them as the start of the response head.
     */
    private string $early_reply_buffer = "";

    /** @var array{http_code:int,body:string,error:?string}|null */
    private ?array $final_response = null;

    /**
     * @param array<string,string> $request_headers
     * @param array<int,array{artifact_id:string,source_path:string,total_bytes:int}> $files
     * @param array{artifact_id?:string,committed_bytes?:int}|null $start_cursor
     */
    public function __construct(
        string $request_url,
        array $request_headers,
        int $request_timeout,
        array $files,
        int $frame_bytes,
        ?array $start_cursor,
        ?int $max_chunks = null,
        ?int $max_payload_bytes = null
    ) {
        $this->request_url = $request_url;
        $this->request_headers = $request_headers;
        $this->request_timeout = $request_timeout;
        $this->files = $files;
        $this->frame_bytes = max(1, $frame_bytes);
        $this->start_cursor = $start_cursor;
        $this->max_chunks = $max_chunks !== null ? max(1, $max_chunks) : null;
        $this->max_payload_bytes = $max_payload_bytes !== null ? max(1, $max_payload_bytes) : null;

        if ($start_cursor !== null && is_string($start_cursor["artifact_id"] ?? null)) {
            foreach ($files as $index => $file) {
                if ($file["artifact_id"] !== $start_cursor["artifact_id"]) {
                    continue;
                }
                $committed_bytes = min(max(0, (int) ($start_cursor["committed_bytes"] ?? 0)), $file["total_bytes"]);
                if ($committed_bytes >= $file["total_bytes"]) {
                    $this->file_index = $index + 1;
                } else {
                    $this->file_index = $index;
                    $this->offset = $committed_bytes;
                }
                break;
            }
        }
    }

    /**
     * Push one more framed chunk onto the network.
     *
     * Opens the connection on the first call, then writes the frame header
     * and the file's byte range for this frame, reading from disk in 64 KiB
     * pieces. When this returns true, the frame's bytes have been handed to
     * the socket.
     *
     * @return bool Whether a chunk was pushed. False means the request is
     *              full, the processor has no more chunks, or the exchange
     *              already ended (transport failure or an early response);
     *              finish() reports which.
     */
    public function next_chunk(): bool
    {
        if (
            $this->finalized
            || $this->transport_error !== null
            || $this->target_replied_early
            || $this->file_index >= count($this->files)
        ) {
            return false;
        }
        if ($this->max_chunks !== null && $this->chunks_streamed >= $this->max_chunks) {
            return false;
        }
        if ($this->max_payload_bytes !== null && $this->payload_bytes_streamed >= $this->max_payload_bytes) {
            return false;
        }

        if ($this->socket === null && !$this->open_connection()) {
            return false;
        }

        $file = $this->files[$this->file_index];
        $offset = $this->offset;
        $remaining_payload_budget = $this->max_payload_bytes === null
            ? PHP_INT_MAX
            : max(1, $this->max_payload_bytes - $this->payload_bytes_streamed);
        $frame_payload_bytes = $file["total_bytes"] === 0
            ? 0
            : min($this->frame_bytes, $file["total_bytes"] - $offset, $remaining_payload_budget);
        $final = $offset + $frame_payload_bytes >= $file["total_bytes"];

        $frame_header = json_encode([
            "type" => "chunk",
            "artifact_id" => $file["artifact_id"],
            "offset" => $offset,
            "bytes" => $frame_payload_bytes,
            "total_bytes" => $file["total_bytes"],
            "final" => $final,
        ], JSON_UNESCAPED_SLASHES);
        if ($frame_header === false) {
            throw new RuntimeException("Could not encode staged push stream frame header.");
        }

        if (!$this->write_to_socket($this->encode_body_chunk($frame_header . "\n"))) {
            return false;
        }

        if ($frame_payload_bytes > 0) {
            $source_handle = @fopen($file["source_path"], "rb");
            if ($source_handle === false) {
                throw new RuntimeException("Source file is unreadable: " . $file["source_path"]);
            }
            if (fseek($source_handle, $offset) !== 0) {
                fclose($source_handle);
                throw new RuntimeException("Could not seek source file: " . $file["source_path"]);
            }
            $frame_bytes_remaining = $frame_payload_bytes;
            while ($frame_bytes_remaining > 0) {
                $piece = fread($source_handle, min(self::DISK_READ_BYTES, $frame_bytes_remaining));
                if ($piece === false || $piece === "") {
                    fclose($source_handle);
                    throw new RuntimeException("Source file ended before its declared size: " . $file["source_path"]);
                }
                if (!$this->write_to_socket($this->encode_body_chunk($piece))) {
                    fclose($source_handle);
                    return false;
                }
                $this->payload_bytes_streamed += strlen($piece);
                $frame_bytes_remaining -= strlen($piece);
            }
            fclose($source_handle);
        }

        $this->chunks_streamed++;
        $this->offset = $offset + $frame_payload_bytes;
        $this->cursor = [
            "artifact_id" => $file["artifact_id"],
            "committed_bytes" => $this->offset,
        ];
        if ($final) {
            $this->file_index++;
            $this->offset = 0;
        }
        return true;
    }

    /**
     * No more chunks will be pushed into this request.
     */
    public function finalize(): void
    {
        $this->finalized = true;
    }

    /**
     * End the request body and read the target's response.
     *
     * Behavior by how the exchange went:
     *
     * 1. No frame was ever written: returns http_code 0 with no error — no
     *    network exchange happened.
     * 2. Normal end: writes the terminating zero-length chunk so the target
     *    sees a complete body, then reads the response.
     * 3. The target replied early: skips the terminator (the target already
     *    decided) and reads the response that is waiting.
     * 4. A transport failure interrupted the stream: still tries to read a
     *    response — a target that rejected mid-stream (413 from a proxy, auth
     *    failure) breaks our writes but its response carries the reason and a
     *    resume cursor. Falls back to the original write error when no
     *    parseable response arrives.
     *
     * @return array{http_code:int,body:string,error:?string}
     */
    public function finish(): array
    {
        if ($this->final_response !== null) {
            return $this->final_response;
        }
        $this->finalized = true;

        if ($this->socket === null) {
            $this->final_response = [
                "http_code" => 0,
                "body" => "",
                "error" => $this->transport_error,
            ];
            return $this->final_response;
        }

        if ($this->transport_error === null && !$this->target_replied_early) {
            $this->write_to_socket("0\r\n\r\n");
        }

        $write_error = $this->transport_error;
        $response = $this->read_response();
        fclose($this->socket);
        $this->socket = null;

        $this->final_response = $response ?? [
            "http_code" => 0,
            "body" => "",
            "error" => $write_error ?? $this->transport_error,
        ];
        return $this->final_response;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function start_cursor(): ?array
    {
        return $this->start_cursor;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function cursor(): ?array
    {
        return $this->cursor;
    }

    public function bytes_streamed(): int
    {
        return $this->payload_bytes_streamed;
    }

    public function chunks_streamed(): int
    {
        return $this->chunks_streamed;
    }

    /**
     * Connect, negotiate TLS for https, and write the request head.
     *
     * On failure records a transport error and returns false; the connection
     * error surfaces through finish() like any other request failure.
     */
    private function open_connection(): bool
    {
        $url_parts = parse_url($this->request_url);
        $scheme = $url_parts["scheme"] ?? "";
        $host = $url_parts["host"] ?? "";
        if (!in_array($scheme, ["http", "https"], true) || $host === "") {
            throw new InvalidArgumentException("Staged push requires an http:// or https:// base_url; received " . $this->request_url);
        }
        $is_tls = $scheme === "https";
        $port = isset($url_parts["port"]) ? (int) $url_parts["port"] : ($is_tls ? 443 : 80);

        $context_options = [];
        if ($is_tls) {
            $context_options["ssl"] = ["peer_name" => $host, "SNI_enabled" => true];
            // Same escape hatch as the pull client's curl path: Playground's
            // TLS stack may not trust the target's CA. The wizard sets this
            // env when it hands off; nothing else does.
            if (getenv("REPRINT_INSECURE_TLS") === "1") {
                $context_options["ssl"]["verify_peer"] = false;
                $context_options["ssl"]["verify_peer_name"] = false;
            }
        }

        $socket = @stream_socket_client(
            "tcp://" . $host . ":" . $port,
            $connect_errno,
            $connect_error,
            $this->request_timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($context_options)
        );
        if ($socket === false) {
            $this->transport_error = "Could not connect to " . $host . ":" . $port . " — " . ($connect_error !== "" ? $connect_error : ("errno " . $connect_errno));
            return false;
        }
        stream_set_timeout($socket, $this->request_timeout);

        if ($is_tls && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            $last_error = error_get_last();
            $this->transport_error = "TLS handshake with " . $host . ":" . $port . " failed — " . (is_array($last_error) ? $last_error["message"] : "unknown error");
            fclose($socket);
            return false;
        }

        // Non-blocking so body writes can watch for an early response in the
        // same stream_select() that waits for writability.
        stream_set_blocking($socket, false);
        $this->socket = $socket;

        $request_target = ($url_parts["path"] ?? "") !== "" ? $url_parts["path"] : "/";
        if (($url_parts["query"] ?? "") !== "") {
            $request_target .= "?" . $url_parts["query"];
        }
        $default_port = $is_tls ? 443 : 80;
        $head = "POST " . $request_target . " HTTP/1.1\r\n"
            . "Host: " . $host . ($port === $default_port ? "" : ":" . $port) . "\r\n";
        foreach ($this->request_headers as $header_name => $header_value) {
            $head .= $header_name . ": " . $header_value . "\r\n";
        }
        $head .= "\r\n";

        return $this->write_to_socket($head);
    }

    /**
     * Wrap raw bytes as one HTTP chunked-transfer-encoding chunk.
     */
    private function encode_body_chunk(string $bytes): string
    {
        return dechex(strlen($bytes)) . "\r\n" . $bytes . "\r\n";
    }

    /**
     * Write bytes to the socket, waiting for writability as needed.
     *
     * Watches for readability in the same select: the target speaking (or
     * closing) before our stream ends means its response decides this request,
     * so sending stops. Returns false on early reply, timeout, or a dead
     * socket; the specific transport error is recorded for finish().
     */
    private function write_to_socket(string $bytes): bool
    {
        $unwritten_offset = 0;
        $total_bytes = strlen($bytes);
        $deadline = microtime(true) + $this->request_timeout;

        while ($unwritten_offset < $total_bytes) {
            $seconds_remaining = $deadline - microtime(true);
            if ($seconds_remaining <= 0) {
                $this->transport_error = "Timed out after " . $this->request_timeout . "s waiting to write push stream bytes.";
                return false;
            }
            $read_sockets = [$this->socket];
            $write_sockets = [$this->socket];
            $except_sockets = null;
            $ready = @stream_select(
                $read_sockets,
                $write_sockets,
                $except_sockets,
                (int) $seconds_remaining,
                (int) (fmod($seconds_remaining, 1) * 1000000)
            );
            if ($ready === false) {
                $last_error = error_get_last();
                $this->transport_error = "stream_select() failed while sending the push stream — " . (is_array($last_error) ? $last_error["message"] : "unknown error");
                return false;
            }
            if ($ready === 0) {
                continue;
            }
            if ($read_sockets !== []) {
                // Probe whether this is application data. TLS also wakes the
                // socket for protocol-internal records (e.g. TLS 1.3 session
                // tickets); reading those yields no bytes and no EOF, and
                // sending must continue.
                $probed = fread($this->socket, 1);
                if ($probed !== false && $probed !== "") {
                    $this->early_reply_buffer .= $probed;
                    $this->target_replied_early = true;
                    return false;
                }
                if (feof($this->socket)) {
                    $this->target_replied_early = true;
                    return false;
                }
                continue;
            }

            // @phpstan-ignore deadCode.unreachable (stream_select() empties $read_sockets by reference)
            $written = @fwrite($this->socket, $unwritten_offset === 0 ? $bytes : substr($bytes, $unwritten_offset));
            if ($written === false || $written === 0) {
                if (feof($this->socket)) {
                    $this->transport_error = "Connection closed while sending the push stream after " . $this->payload_bytes_streamed . " payload bytes.";
                    return false;
                }
                // A writable-but-zero write happens during TLS renegotiation;
                // retry until the deadline decides.
                continue;
            }
            $unwritten_offset += $written;
        }
        return true;
    }

    /**
     * Read and parse the HTTP response. Returns null and records a transport
     * error when no complete response arrives in time.
     *
     * @return array{http_code:int,body:string,error:?string}|null
     */
    private function read_response(): ?array
    {
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, $this->request_timeout);

        $head = $this->early_reply_buffer;
        while (strpos($head, "\r\n\r\n") === false) {
            $line = fgets($this->socket);
            if ($line === false) {
                $this->transport_error = stream_get_meta_data($this->socket)["timed_out"]
                    ? "Timed out after " . $this->request_timeout . "s waiting for the push stream response."
                    : "Connection closed before a push stream response arrived.";
                return null;
            }
            $head .= $line;
            if (strlen($head) > 65536) {
                $this->transport_error = "Push stream response headers exceeded 64 KiB.";
                return null;
            }
        }

        if (!preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $status_match)) {
            $this->transport_error = "Malformed push stream response status line: " . substr($head, 0, 120);
            return null;
        }
        $http_code = (int) $status_match[1];

        $response_headers = [];
        foreach (explode("\r\n", $head) as $header_line) {
            $colon_position = strpos($header_line, ":");
            if ($colon_position !== false) {
                $response_headers[strtolower(substr($header_line, 0, $colon_position))] = trim(substr($header_line, $colon_position + 1));
            }
        }

        if (stripos($response_headers["transfer-encoding"] ?? "", "chunked") !== false) {
            $body = "";
            while (true) {
                $size_line = fgets($this->socket);
                if ($size_line === false) {
                    $this->transport_error = "Connection closed inside a chunked push stream response.";
                    return null;
                }
                $chunk_size_hex = trim(explode(";", $size_line, 2)[0]);
                if ($chunk_size_hex === "" || !ctype_xdigit($chunk_size_hex)) {
                    $this->transport_error = "Malformed chunked response size line: " . substr($size_line, 0, 40);
                    return null;
                }
                $chunk_size = (int) hexdec($chunk_size_hex);
                if ($chunk_size === 0) {
                    // Drain optional trailers up to the blank line.
                    while (($trailer_line = fgets($this->socket)) !== false && $trailer_line !== "\r\n") {
                        continue;
                    }
                    break;
                }
                $chunk = $this->read_from_socket($chunk_size + 2);
                if ($chunk === null) {
                    return null;
                }
                $body .= substr($chunk, 0, $chunk_size);
            }
            return ["http_code" => $http_code, "body" => $body, "error" => null];
        }

        if (isset($response_headers["content-length"])) {
            $body = $this->read_from_socket((int) $response_headers["content-length"]);
            if ($body === null) {
                return null;
            }
            return ["http_code" => $http_code, "body" => $body, "error" => null];
        }

        // Connection: close without a length — the body ends when the target
        // closes the connection.
        $body = stream_get_contents($this->socket);
        if ($body === false || stream_get_meta_data($this->socket)["timed_out"]) {
            $this->transport_error = "Timed out after " . $this->request_timeout . "s reading the push stream response body.";
            return null;
        }
        return ["http_code" => $http_code, "body" => $body, "error" => null];
    }

    /**
     * Read exactly $bytes from the blocking response socket, or record why not.
     */
    private function read_from_socket(int $bytes): ?string
    {
        $buffer = "";
        while (strlen($buffer) < $bytes) {
            $piece = fread($this->socket, $bytes - strlen($buffer));
            if ($piece === false || $piece === "") {
                $this->transport_error = stream_get_meta_data($this->socket)["timed_out"]
                    ? "Timed out after " . $this->request_timeout . "s reading the push stream response body."
                    : "Connection closed after " . strlen($buffer) . " of " . $bytes . " expected push stream response body bytes.";
                return null;
            }
            $buffer .= $piece;
        }
        return $buffer;
    }
}

/**
 * Cursor-style driver for staged push streams.
 *
 * This mirrors processor and async-client APIs: next_request() opens one
 * network request, the request advances chunk by chunk, finalize_request()
 * sends it, and the caller decides whether to keep looping, persist the
 * cursor, or pause the push.
 */
final class StagedPushStreamPusher
{
    /** Consecutive failed stream requests before the push is abandoned. */
    private const MAX_REQUEST_RETRIES = 5;

    /** Base backoff between retries, in microseconds. */
    private const RETRY_BACKOFF_USEC = 250000;

    /** Longest single backoff, in microseconds. */
    private const MAX_BACKOFF_USEC = 5000000;

    private StagedPushStreamClient $client;

    private StagedPushStreamProcessor $processor;

    /** @var array{max_chunks?:int|null,max_payload_bytes?:int|null} */
    private array $limits;

    /** @var callable(int):void */
    private $sleeper;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $cursor;

    /** @var array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}|null */
    private ?array $result = null;

    private ?StagedPushStreamRequest $request = null;

    private int $request_failures = 0;

    private bool $finished = false;

    /**
     * @param array $options
     *   - max_chunks_per_request (?int): stop each request after this many
     *     framed chunks.
     *   - max_payload_bytes_per_request (?int): stop each request after this
     *     many local file bytes, excluding frame headers.
     *   - sleeper (?callable): fn(int $microseconds), for tests.
     */
    public function __construct(StagedPushStreamClient $client, StagedPushStreamProcessor $processor, array $options = [])
    {
        $this->client = $client;
        $this->processor = $processor;
        $max_chunks = $options["max_chunks_per_request"] ?? null;
        $max_payload_bytes = $options["max_payload_bytes_per_request"] ?? null;
        $this->limits = [
            "max_chunks" => is_numeric($max_chunks) && (int) $max_chunks > 0 ? (int) $max_chunks : null,
            "max_payload_bytes" => is_numeric($max_payload_bytes) && (int) $max_payload_bytes > 0 ? (int) $max_payload_bytes : null,
        ];
        $this->sleeper = $options["sleeper"] ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->cursor = $processor->cursor();
        $this->finished = $processor->is_finished_at($this->cursor);
    }

    /**
     * Open the next staged_push request.
     *
     * @return bool Whether a request is available via get_request().
     */
    public function next_request(): bool
    {
        if ($this->finished || $this->request !== null) {
            return false;
        }

        $this->request = $this->client->create_request($this->processor, $this->cursor, $this->limits);
        $this->result = null;
        return true;
    }

    public function get_request(): ?StagedPushStreamRequest
    {
        return $this->request;
    }

    /**
     * Finalize and send the current request.
     *
     * @return bool Whether a request-level result is available via get_result().
     */
    public function finalize_request(): bool
    {
        if ($this->request === null) {
            return false;
        }

        $result = $this->client->push_request($this->request);
        $this->request = null;
        $this->result = $result;

        if ($result["status"] === "request_complete") {
            $this->request_failures = 0;
            $this->cursor = is_array($result["cursor"] ?? null) ? $result["cursor"] : $this->cursor;
            if ($this->processor->is_finished_at($this->cursor)) {
                $this->result["status"] = "complete";
                $this->finished = true;
            } else {
                $this->result["status"] = "in_progress";
            }
            return true;
        }

        if ($result["status"] === "retry") {
            $this->request_failures++;
            $this->cursor = is_array($result["cursor"] ?? null) ? $result["cursor"] : $this->cursor;
            if ($this->request_failures > self::MAX_REQUEST_RETRIES) {
                $this->result["status"] = "failed";
                $this->finished = true;
            } else {
                $delay = min(self::MAX_BACKOFF_USEC, self::RETRY_BACKOFF_USEC * (2 ** max(0, $this->request_failures - 1)));
                call_user_func($this->sleeper, (int) $delay);
            }
            return true;
        }

        $this->cursor = is_array($result["cursor"] ?? null) ? $result["cursor"] : $this->cursor;
        $this->finished = true;
        return true;
    }

    /** @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}|null */
    public function get_result(): ?array
    {
        return $this->result;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function get_cursor(): ?array
    {
        return $this->cursor;
    }

    public function is_finished(): bool
    {
        return $this->finished;
    }

}

/**
 * Processor-style source for staged push streams.
 *
 * Callers construct this with the local filesystem root, the JSONL list of
 * local paths to push, and the last cursor returned by the target. The client
 * owns HTTP retries and frame sizing; the processor owns local file identity
 * and where a request should start.
 */
final class StagedPushStreamProcessor
{
    private string $local_files_root_path;

    private string $local_paths_to_push_path;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $cursor;

    /** @var array<int,array{artifact_id:string,source_path:string,total_bytes:int}>|null */
    private ?array $files = null;

    /**
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     */
    public function __construct(string $local_files_root_path, string $local_paths_to_push_path, ?array $cursor = null)
    {
        $this->local_files_root_path = rtrim($local_files_root_path, "/");
        $this->local_paths_to_push_path = $local_paths_to_push_path;
        $this->cursor = $cursor;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function cursor(): ?array
    {
        return $this->cursor;
    }

    /**
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     */
    public function is_finished_at(?array $cursor): bool
    {
        $files = $this->files();
        if ($files === []) {
            return true;
        }
        if (!is_string($cursor["artifact_id"] ?? null)) {
            return false;
        }
        $last_file = $files[count($files) - 1];
        return $cursor["artifact_id"] === $last_file["artifact_id"]
            && (int) ($cursor["committed_bytes"] ?? -1) >= $last_file["total_bytes"];
    }

    /**
     * The resolved local files to push, in push order.
     *
     * @return array<int,array{artifact_id:string,source_path:string,total_bytes:int}>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }
        if (!is_file($this->local_paths_to_push_path)) {
            throw new RuntimeException("Cannot open local paths to push: " . $this->local_paths_to_push_path);
        }
        $handle = fopen($this->local_paths_to_push_path, "r");
        if (!$handle) {
            throw new RuntimeException("Cannot open local paths to push: " . $this->local_paths_to_push_path);
        }

        $files = [];
        while (($raw_line = fgets($handle)) !== false) {
            $raw_line = trim($raw_line);
            if ($raw_line === "") {
                continue;
            }
            $artifact_id = $this->decode_artifact_id($raw_line);
            $source_path = $this->local_files_root_path . "/" . $artifact_id;
            $size = @filesize($source_path);
            if ($size === false) {
                fclose($handle);
                throw new RuntimeException("Source file is unreadable: " . $source_path);
            }
            $files[] = [
                "artifact_id" => $artifact_id,
                "source_path" => $source_path,
                "total_bytes" => (int) $size,
            ];
        }
        fclose($handle);

        $this->files = $files;
        return $this->files;
    }

    private function decode_artifact_id(string $raw_line): string
    {
        try {
            $decoded_line = json_decode($raw_line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unexpected local path line, it is not valid JSON: " . substr($raw_line, 0, 120), 0, $exception);
        }
        if (!is_array($decoded_line) || !array_key_exists("path", $decoded_line) || !is_string($decoded_line["path"])) {
            throw new RuntimeException("Invalid local path line: " . substr($raw_line, 0, 120));
        }
        $artifact_id = base64_decode($decoded_line["path"], true);
        if ($artifact_id === false || $artifact_id === "") {
            throw new RuntimeException("Invalid local path line: " . substr($raw_line, 0, 120));
        }
        return $artifact_id;
    }
}
