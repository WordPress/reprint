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
 * memory — at most next_chunk_body_bytes(), which is why a chunk is the one thing
 * that may be buffered — and send_chunk() writes the frame header and the
 * payload straight to the request socket before returning:
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
 * Two sizes govern the loop, and they are different dimensions. The chunk
 * is the small fixed in-memory unit of one fread. The request body budget
 * is what hosts and proxies actually limit — post_max_size,
 * client_max_body_size and friends measure the entity body, and nothing
 * compresses request bodies, so the bytes we write are the bytes that get
 * measured. That budget is learned per host by PushRequestSizer and charges
 * everything the body carries: frame header lines, payloads, and the
 * chunked transfer-encoding framing around them. next_chunk_body_bytes()
 * folds both sizes into the one number a caller's fread needs.
 */
class StagedPushStreamClient
{
    /** Largest slice handed to one fwrite; bounds the copy cost of partial writes. */
    private const WRITE_SLICE_BYTES = 1048576;

    private string $base_url;

    private ?Site_Export_HMAC_Client $hmac_client;

    private PushRequestSizer $request_sizer;

    private int $request_timeout;

    /** @var int|float Wall-clock budget per request, in seconds. */
    private $max_request_seconds;

    /** @var int In-memory unit of one caller fread, in bytes. */
    private int $chunk_bytes;

    /** @var resource|null */
    private $socket = null;

    private float $request_started_at = 0.0;

    private int $body_bytes_sent = 0;

    private int $chunks_sent = 0;

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
     *   - request_timeout (int): seconds without socket progress (one write,
     *     or the response read) before the request fails. Default 120.
     *   - max_request_seconds (int|float): wall-clock budget per request;
     *     should_finish_request() turns true once a request is older than
     *     this. Soft — checked between chunks — so set it with margin below
     *     the host's execution/proxy limits. Default 30.
     */
    public function __construct(array $options)
    {
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
     * Open a staged_push request: connect, negotiate TLS for https, and write
     * the signed request head. The request body starts empty; send_chunk()
     * fills it.
     *
     * @return bool False when the connection could not be opened;
     *              get_last_error() says why.
     */
    public function start_push_request(): bool
    {
        if ($this->socket !== null) {
            throw new RuntimeException("A push request is already open; call finish_request() before starting another.");
        }

        $this->body_bytes_sent = 0;
        $this->chunks_sent = 0;
        $this->transport_error = null;
        $this->target_replied_early = false;
        $this->early_reply_buffer = "";
        $this->last_error = null;

        $request_url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(["endpoint" => "staged_push"]);
        $request_headers = $this->hmac_client !== null
            ? $this->hmac_client->get_envelope_auth_headers("POST", $request_url)
            : [];
        $request_headers["Content-Type"] = Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE;
        $request_headers["Transfer-Encoding"] = "chunked";
        $request_headers["Connection"] = "close";

        if (!$this->open_connection($request_url, $request_headers)) {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
            $this->last_error = $this->transport_error;
            $this->transport_error = null;
            return false;
        }

        $this->request_started_at = microtime(true);
        return true;
    }

    /**
     * Write one framed chunk — header line, then the payload — straight to
     * the request socket.
     *
     * The payload's length is the frame's byte count; there is no separate
     * declaration to reconcile. Invalid descriptors throw with the exact
     * violated condition. Remote conditions do not throw: false means the
     * wire died or the target replied early, and finish_request() reports
     * which.
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

        if ($this->socket === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before send_chunk().");
        }
        if ($this->transport_error !== null || $this->target_replied_early) {
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

        // Account the encoded wire bytes — frame header, payload, and the
        // chunked transfer-encoding size lines and CRLFs around each — so
        // body_bytes_sent equals what the request body actually carries.
        $encoded_frame_header = $this->encode_body_chunk($frame_header);
        if (!$this->write_to_socket($encoded_frame_header)) {
            return false;
        }
        $encoded_payload = $payload === "" ? "" : $this->encode_body_chunk($payload);
        if ($encoded_payload !== "" && !$this->write_to_socket($encoded_payload)) {
            return false;
        }

        $this->body_bytes_sent += strlen($encoded_frame_header) + strlen($encoded_payload);
        $this->chunks_sent++;
        return true;
    }

    /**
     * How many bytes the caller's next fread should ask for.
     *
     * The fixed chunk size — the in-memory unit — bounded by what remains of
     * the host-learned request body budget. That budget counts every byte
     * the request body carries: frame header lines, payloads, and the
     * chunked transfer-encoding size lines and CRLFs around them — hence
     * "body bytes", not just payload bytes. Returns 0 when the request is
     * full; should_finish_request() is already true then. Near the end of a
     * file fread simply returns fewer bytes and the smaller frame is
     * correct, so callers need no min() of their own. The budget is soft:
     * the header and framing riding along with the last chunk may overshoot
     * it by one frame's overhead, which the sizer's safety margin absorbs.
     */
    public function next_chunk_body_bytes(): int
    {
        $remaining_body_budget = max(0, $this->request_sizer->request_body_bytes() - $this->body_bytes_sent);
        return min($this->chunk_bytes, $remaining_body_budget);
    }

    /**
     * Whether the current request should end now: its byte or time budget is
     * spent, or the exchange already ended (dead wire or an early response).
     */
    public function should_finish_request(): bool
    {
        if ($this->socket === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before should_finish_request().");
        }
        return $this->transport_error !== null
            || $this->target_replied_early
            || $this->next_chunk_body_bytes() === 0
            || (microtime(true) - $this->request_started_at) > $this->max_request_seconds;
    }

    /**
     * End the request body, read the target's response, and fold it into the
     * retry/cursor decision.
     *
     * Behavior by how the exchange went:
     *
     * 1. Normal end: writes the terminating zero-length chunk so the target
     *    sees a complete body, then reads the response.
     * 2. The target replied early: skips the terminator (the target already
     *    decided) and reads the response that is waiting.
     * 3. A transport failure interrupted the stream: still tries to read a
     *    response — a target that rejected mid-stream (413 from a proxy, auth
     *    failure) breaks our writes but its response carries the reason and a
     *    resume cursor. Falls back to the write error when no parseable
     *    response arrives.
     *
     * The returned cursor is the server-confirmed one from the response, or
     * null when no response arrived — resume from the last persisted cursor
     * or ask staged_status.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int}
     */
    public function finish_request(): array
    {
        if ($this->socket === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before finish_request().");
        }

        if ($this->transport_error === null && !$this->target_replied_early) {
            $this->write_to_socket("0\r\n\r\n");
        }

        $write_error = $this->transport_error;
        $response = $this->read_response();
        fclose($this->socket);
        $this->socket = null;

        if ($response === null) {
            $decision = $this->request_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                $write_error ?? $this->transport_error
            );
        }

        $decoded = json_decode($response["body"], true);
        if (!is_array($decoded)) {
            $decision = $this->request_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                "invalid JSON response (HTTP " . $response["http_code"] . "): " . substr($response["body"], 0, 120)
            );
        }

        $response_cursor = is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : null;

        if ($response["http_code"] === 413 || ($decoded["reason"] ?? null) === "frame_too_large") {
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
                is_string($decoded["detail"] ?? null) ? $decoded["detail"] : ("HTTP " . $response["http_code"]),
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

    /**
     * Connect, negotiate TLS for https, and write the request head.
     *
     * On failure records a transport error and returns false.
     *
     * @param array<string,string> $request_headers
     */
    private function open_connection(string $request_url, array $request_headers): bool
    {
        $url_parts = parse_url($request_url);
        $scheme = $url_parts["scheme"] ?? "";
        $host = $url_parts["host"] ?? "";
        if (!in_array($scheme, ["http", "https"], true) || $host === "") {
            throw new InvalidArgumentException("Staged push requires an http:// or https:// base_url; received " . $request_url);
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
        foreach ($request_headers as $header_name => $header_value) {
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
     * socket; the specific transport error is recorded for finish_request().
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

            // The socket fired; probe for an early reply before writing. Any
            // buffered application byte means the target's response decides
            // this request now. TLS also wakes the read side for protocol-
            // internal records (e.g. TLS 1.3 session tickets); those yield no
            // bytes and no EOF, and sending continues.
            $probed = fread($this->socket, 1);
            if (is_string($probed) && $probed !== "") {
                $this->early_reply_buffer .= $probed;
                $this->target_replied_early = true;
                return false;
            }
            if (feof($this->socket)) {
                $this->target_replied_early = true;
                return false;
            }

            $written = @fwrite($this->socket, substr($bytes, $unwritten_offset, self::WRITE_SLICE_BYTES));
            if ($written === false || $written === 0) {
                if (feof($this->socket)) {
                    $this->transport_error = "Connection closed while sending the push stream after " . $this->body_bytes_sent . " body bytes.";
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
     * @return array{http_code:int,body:string}|null
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
            return ["http_code" => $http_code, "body" => $body];
        }

        if (isset($response_headers["content-length"])) {
            $body = $this->read_from_socket((int) $response_headers["content-length"]);
            if ($body === null) {
                return null;
            }
            return ["http_code" => $http_code, "body" => $body];
        }

        // Connection: close without a length — the body ends when the target
        // closes the connection.
        $body = stream_get_contents($this->socket);
        if ($body === false || stream_get_meta_data($this->socket)["timed_out"]) {
            $this->transport_error = "Timed out after " . $this->request_timeout . "s reading the push stream response body.";
            return null;
        }
        return ["http_code" => $http_code, "body" => $body];
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
