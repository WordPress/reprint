<?php
/**
 * Sender for the staged push stream endpoint.
 *
 * A push stream is one authenticated HTTP request whose body is a sequence of
 * framed file chunks. The target commits each frame into
 * Site_Export_Staged_Artifacts as it is read, so a broken connection can be
 * retried from the last cursor the sender has, or from the beginning: the
 * target skips replayed files it already verified and restarts partially
 * staged ones from zero, so a source file that changed between requests can
 * never end up half old version, half new. Callers persist the source token
 * (size and ctime — the signals the push journal's own diff keys on)
 * alongside each cursor and restart a file at offset 0 when the file on disk
 * no longer matches it; a same-size edit within the same timestamp second
 * escapes the token, and the diff layer's change detection is the deeper
 * net. The reference loop in StagedPushStreamClientTest::pushOnce() shows
 * both halves.
 *
 * The client is a pass-through wire. The caller reads a chunk of a file into
 * memory — at most next_chunk_body_bytes(), which is why a chunk is the one
 * thing that may be buffered — and send_chunk() sends it over the network
 * before returning:
 *
 *     // Open the request only once the first chunk exists — an empty
 *     // source should not cost a network exchange (the reference loop in
 *     // StagedPushStreamClientTest::pushOnce() shows the lazy shape).
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
 * returns true the frame has left for the network — what trails behind is
 * libcurl's upload buffer (64 KiB) and the kernel's socket send buffer,
 * never a request body. Between chunks the read callback returns
 * CURL_READFUNC_PAUSE, which PHP's curl extension only supports since
 * PHP 8.1 — on 7.4/8.0 that return is misread as end-of-body and the upload
 * silently truncates, so the constructor refuses to run there. The full
 * story: https://github.com/WordPress/reprint/issues/327
 *
 * That 8.1 requirement is runtime-only on purpose: import.php requires this
 * file for every command, including pull on PHP 7.4, so the file itself must
 * stay parseable by 7.4 — no 8.x syntax (which is also why
 * $max_request_seconds is untyped: int|float property types are PHP 8.0).
 * PushClientPhpCompatibilityTest enforces this.
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

    private Site_Export_HMAC_Client $hmac_client;

    private PushRequestSizer $request_sizer;

    /** Seconds to establish the connection and get the request head out. */
    private int $connect_timeout;

    /**
     * Seconds without a single byte moving before the transfer is declared
     * dead. Only stalls trip this — a slow connection that keeps moving
     * bytes never does, no matter how long the request takes.
     */
    private int $stall_timeout;

    /**
     * Seconds to wait for the response after the body is finished. A
     * separate phase with no progress signal: request-buffering stacks do
     * all their frame processing here, so this needs room proportional to
     * how much one request carries.
     */
    private int $response_timeout;

    /**
     * Wall-clock budget per request, in seconds. Untyped because this file
     * must stay PHP 7.4-parseable (class docblock) and int|float property
     * types are PHP 8.0 syntax.
     *
     * @var int|float
     */
    private $max_request_seconds;

    /** @var int In-memory unit of one caller fread, in bytes. */
    private int $chunk_bytes;

    /** @var resource|object|null curl easy handle for the open request */
    private $curl_handle = null;

    /**
     * curl multi handle driving requests. Outlives individual requests on
     * purpose: libcurl's connection cache lives on the multi handle, so
     * back-to-back requests reuse the TCP/TLS connection instead of paying
     * a fresh handshake per rotation.
     *
     * @var resource|object|null
     */
    private $multi_handle = null;

    /**
     * The current frame, waiting for libcurl to consume it. Held as two
     * fields instead of one concatenated string on purpose: PHP string
     * assignment is copy-on-write, so taking the caller's payload here
     * allocates nothing — the only real copies are the substr() pieces the
     * read callback returns, which is the smallest handoff PHP's callback
     * API allows.
     */
    private string $outbound_frame_header = "";

    private string $outbound_payload = "";

    /** How far into $outbound_payload libcurl's read callback has consumed. */
    private int $outbound_payload_offset = 0;

    /** The read callback signals end-of-body when this is true. */
    private bool $body_complete = false;

    /**
     * libcurl asked the read callback for body bytes — the request head is
     * out and the connection is established.
     */
    private bool $curl_requested_body = false;

    /**
     * Total bytes libcurl has consumed from the read callback; the progress
     * signal the stall timeout watches. libcurl only asks for more after
     * writing the previous piece toward the socket, so consumption tracks
     * actual transmission at upload-buffer granularity.
     */
    private int $outbound_consumed_bytes = 0;

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
     *   - hmac_client (Site_Export_HMAC_Client, required): signs every
     *     request; the staged endpoints reject unsigned requests before
     *     reading the body, so there is no unauthenticated push.
     *   - request_sizer (?PushRequestSizer): request-body-size decisions;
     *     defaults to a fresh sizer. Pass one restored from persisted state
     *     to keep learned limits.
     *   - chunk_bytes (int): in-memory unit of one caller fread; unrelated
     *     to the request body budget. Default 4 MiB.
     *   - connect_timeout (int): seconds to establish the connection and
     *     send the request head. Default 30.
     *   - stall_timeout (int): seconds without a single byte moving while
     *     sending before the request fails. Slow connections that keep
     *     moving bytes never trip this; there is deliberately no total
     *     transfer timeout, so a large request over a slow link runs as
     *     long as it keeps progressing. Default 60.
     *   - response_timeout (int): seconds to wait for the response after
     *     the body is finished — the phase where request-buffering hosts
     *     process every frame at once. Default 300.
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
        $hmac_client = $options["hmac_client"] ?? null;
        if (!$hmac_client instanceof Site_Export_HMAC_Client) {
            throw new InvalidArgumentException("StagedPushStreamClient requires an hmac_client option; the staged endpoints reject unsigned requests.");
        }
        $this->hmac_client = $hmac_client;
        $this->request_sizer = $options["request_sizer"] ?? new PushRequestSizer();

        // Absent options get defaults; present-but-invalid options throw —
        // silently substituting a default would hide the caller's mistake
        // until it surfaces as puzzling runtime behavior.
        $chunk_bytes = $options["chunk_bytes"] ?? null;
        if ($chunk_bytes !== null && (!is_numeric($chunk_bytes) || (int) $chunk_bytes <= 0)) {
            throw new InvalidArgumentException("Expected option \"chunk_bytes\" to be a positive integer; received " . json_encode($chunk_bytes) . ".");
        }
        $this->chunk_bytes = $chunk_bytes !== null ? (int) $chunk_bytes : 4 * 1024 * 1024;
        $connect_timeout = $options["connect_timeout"] ?? null;
        if ($connect_timeout !== null && (!is_numeric($connect_timeout) || (int) $connect_timeout <= 0)) {
            throw new InvalidArgumentException("Expected option \"connect_timeout\" to be a positive integer; received " . json_encode($connect_timeout) . ".");
        }
        $this->connect_timeout = $connect_timeout !== null ? (int) $connect_timeout : 30;
        $stall_timeout = $options["stall_timeout"] ?? null;
        if ($stall_timeout !== null && (!is_numeric($stall_timeout) || (int) $stall_timeout <= 0)) {
            throw new InvalidArgumentException("Expected option \"stall_timeout\" to be a positive integer; received " . json_encode($stall_timeout) . ".");
        }
        $this->stall_timeout = $stall_timeout !== null ? (int) $stall_timeout : 60;
        $response_timeout = $options["response_timeout"] ?? null;
        if ($response_timeout !== null && (!is_numeric($response_timeout) || (int) $response_timeout <= 0)) {
            throw new InvalidArgumentException("Expected option \"response_timeout\" to be a positive integer; received " . json_encode($response_timeout) . ".");
        }
        $this->response_timeout = $response_timeout !== null ? (int) $response_timeout : 300;
        $max_request_seconds = $options["max_request_seconds"] ?? null;
        if ($max_request_seconds !== null && (!is_numeric($max_request_seconds) || $max_request_seconds <= 0)) {
            throw new InvalidArgumentException("Expected option \"max_request_seconds\" to be a positive number; received " . json_encode($max_request_seconds) . ".");
        }
        // The + cast turns numeric strings (CLI flags, state files) into the
        // int|float the property declares.
        $this->max_request_seconds = $max_request_seconds !== null ? +$max_request_seconds : 30;
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

        $this->outbound_frame_header = "";
        $this->outbound_payload = "";
        $this->outbound_payload_offset = 0;
        $this->outbound_consumed_bytes = 0;
        $this->body_complete = false;
        $this->curl_requested_body = false;
        $this->transfer_finished = false;
        $this->transfer_error = null;
        $this->body_bytes_sent = 0;
        $this->chunks_sent = 0;
        $this->last_error = null;

        // Signing covers the method and the URL (path + query), so it can
        // happen before the body exists — the body itself is not signed;
        // the X-Auth-Content-Hash header says so explicitly.
        $request_url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(["endpoint" => "staged_push"]);
        $request_headers = $this->hmac_client->get_envelope_auth_headers("POST", $request_url);
        $request_headers["Content-Type"] = Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE;

        $header_lines = [];
        foreach ($request_headers as $header_name => $header_value) {
            $header_lines[] = $header_name . ": " . $header_value;
        }
        // Suppress Expect: 100-continue (older libcurls add it for chunked
        // uploads). The 100-continue dance would spare a misconfigured push
        // from uploading a body the target is about to refuse — but servers
        // that never answer the interim 100 (php -S among them) cost a full
        // Expect timeout stall on every request, while the wasted body is
        // bounded by one request budget and happens once before the push
        // stops with a pointed error.
        $header_lines[] = "Expect:";

        $this->curl_handle = curl_init($request_url);
        // Same environment knobs as every pull request: ALL_PROXY routes
        // the transfer through a proxy even on hosts that strip the process
        // environment libcurl would otherwise read it from, and the CA
        // helper covers Playground's WASM curl, which cannot see
        // openssl.cafile. Both are documented where they are defined, in
        // import.php.
        if (function_exists("reprint_apply_curl_proxy_from_env")) {
            reprint_apply_curl_proxy_from_env($this->curl_handle);
        }
        if (function_exists("reprint_apply_curl_ca_bundle")) {
            reprint_apply_curl_ca_bundle($this->curl_handle);
        }
        curl_setopt_array($this->curl_handle, [
            // Upload with no declared size: libcurl picks the transfer
            // framing (chunked on HTTP/1.1, DATA frames on HTTP/2). UPLOAD
            // defaults the verb to PUT; the export API routes POST.
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            // Deliberately no CURLOPT_TIMEOUT: a total-transfer cap kills
            // healthy-but-slow bulk uploads. Each phase has its own bound —
            // connect_timeout, the stall watch in send_chunk(), and
            // response_timeout in finish_request().
            //
            // libcurl drives the upload by asking: whenever the socket can
            // take more data, it calls this function for up to $length bytes
            // ($length is libcurl's upload buffer, typically 64 KiB). Three
            // possible answers:
            //   - a piece of the current frame: the header line first, then
            //     the payload, sliced to $length;
            //   - "": the body is complete, libcurl ends the request;
            //   - CURL_READFUNC_PAUSE: nothing to send right now, but the
            //     body is not over — libcurl parks the upload until
            //     send_chunk() supplies the next frame and unpauses. This
            //     constant is why push needs PHP 8.1+ (class docblock).
            CURLOPT_READFUNCTION => function ($curl_handle, $stream, int $length) {
                $this->curl_requested_body = true;
                if ($this->outbound_frame_header !== "") {
                    $piece = substr($this->outbound_frame_header, 0, $length);
                    $this->outbound_frame_header = (string) substr($this->outbound_frame_header, strlen($piece));
                    $this->outbound_consumed_bytes += strlen($piece);
                    return $piece;
                }
                if ($this->outbound_payload_offset < strlen($this->outbound_payload)) {
                    $piece = substr($this->outbound_payload, $this->outbound_payload_offset, $length);
                    $this->outbound_payload_offset += strlen($piece);
                    $this->outbound_consumed_bytes += strlen($piece);
                    if ($this->outbound_payload_offset >= strlen($this->outbound_payload)) {
                        $this->outbound_payload = "";
                        $this->outbound_payload_offset = 0;
                    }
                    return $piece;
                }
                if ($this->body_complete) {
                    return "";
                }
                return CURL_READFUNC_PAUSE;
            },
        ]);

        // One transfer at a time in one long-lived curl_multi handle. Multi
        // is not for concurrency here: unlike curl_exec(), which blocks
        // until the whole exchange is over, curl_multi_exec() performs one
        // small slice of work and returns. That is what lets send_chunk()
        // feed a frame, pump until libcurl consumed it, and hand control
        // back to the caller while the request stays open. The handle
        // itself lives as long as the client so requests reuse connections
        // (see the property docblock).
        if ($this->multi_handle === null) {
            $this->multi_handle = curl_multi_init();
        }
        curl_multi_add_handle($this->multi_handle, $this->curl_handle);

        // Drive the transfer until the head is out — libcurl asking for body
        // bytes proves it — so connection and TLS failures surface here, not
        // in the middle of the caller's chunk loop.
        $deadline = microtime(true) + $this->connect_timeout;
        while (!$this->curl_requested_body && !$this->transfer_finished) {
            if (microtime(true) > $deadline) {
                $this->transfer_error = "Timed out after " . $this->connect_timeout . "s opening the push stream request.";
                break;
            }
            $this->pump_transfer();
        }
        if (!$this->curl_requested_body) {
            $this->last_error = $this->transfer_error ?? "The push stream request ended before the request head was sent.";
            curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
            $this->curl_handle = null;
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
     * libcurl's 64 KiB upload buffer and the kernel's socket send buffer
     * trail behind — buffers, never a request body.
     *
     * The payload's length is the frame's byte count; there is no separate
     * declaration to reconcile. The artifact_id may be arbitrary bytes —
     * file names are not guaranteed UTF-8 — and travels base64-encoded
     * inside the JSON frame header. Invalid descriptors throw with the exact
     * violated condition. Remote conditions do not throw: false means the
     * transfer ended early — dead connection or the target already
     * responded — and finish_request() reports which.
     *
     * @param array{artifact_id:string,offset:int,total_bytes:int,final:bool,payload:string} $chunk
     */
    public function send_chunk(array $chunk): bool
    {
        // This mirrors the target's own frame validation, so a bad
        // descriptor fails here — naming the exact field — instead of after
        // uploading it. The last two checks are protocol invariants no type
        // system covers: a zero-byte non-final frame means the source file
        // shrank under the caller, and a final frame must land exactly on
        // total_bytes or the target will 409 after staging the bytes.
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
            // File paths are arbitrary bytes and JSON strings must be UTF-8,
            // so the id travels base64-encoded — the same convention the
            // journal and the pull cursors use. json_encode would return
            // false on a raw non-UTF-8 name.
            "artifact_id" => base64_encode($artifact_id),
            "offset" => $offset,
            "bytes" => strlen($payload),
            "total_bytes" => $total_bytes,
            "final" => $final,
        ], JSON_UNESCAPED_SLASHES);
        if ($frame_header === false) {
            throw new RuntimeException("Could not encode the staged push stream frame header for \"" . $artifact_id . "\".");
        }
        $frame_header .= "\n";

        // Copy-on-write assignments: neither line copies the payload bytes.
        // Unpausing tells libcurl to start calling the read callback again.
        $this->outbound_frame_header = $frame_header;
        $this->outbound_payload = $payload;
        $this->outbound_payload_offset = 0;
        curl_pause($this->curl_handle, CURLPAUSE_CONT);

        // The stall watch fails only on zero progress: every byte libcurl
        // consumes resets the clock, so a slow connection that keeps moving
        // can take as long as it needs.
        $consumed_at_last_progress = $this->outbound_consumed_bytes;
        $last_progress_at = microtime(true);
        while (($this->outbound_frame_header !== "" || $this->outbound_payload !== "") && !$this->transfer_finished) {
            if ($this->outbound_consumed_bytes !== $consumed_at_last_progress) {
                $consumed_at_last_progress = $this->outbound_consumed_bytes;
                $last_progress_at = microtime(true);
            } elseif (microtime(true) - $last_progress_at > $this->stall_timeout) {
                $this->transfer_error = "The push stream stalled: no bytes moved for " . $this->stall_timeout . "s while sending a chunk of \"" . $artifact_id . "\".";
                $this->transfer_finished = true;
                break;
            }
            $this->pump_transfer();
        }
        if ($this->outbound_frame_header !== "" || $this->outbound_payload !== "") {
            // The transfer ended mid-frame; drop the leftover so the read
            // callback cannot leak stale bytes into a later pump.
            $this->outbound_frame_header = "";
            $this->outbound_payload = "";
            $this->outbound_payload_offset = 0;
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
        if ($this->curl_handle === null) {
            throw new RuntimeException("No push request is open; call start_push_request() before next_chunk_body_bytes().");
        }
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
            $deadline = microtime(true) + $this->response_timeout;
            while (!$this->transfer_finished) {
                if (microtime(true) > $deadline) {
                    $this->transfer_error = "No response arrived within " . $this->response_timeout . "s of finishing the push stream body.";
                    break;
                }
                $this->pump_transfer();
            }
        }

        $http_code = (int) curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
        $redirect_url = (string) curl_getinfo($this->curl_handle, CURLINFO_REDIRECT_URL);
        $response_body = (string) curl_multi_getcontent($this->curl_handle);
        // The easy handle is done; the multi handle stays for the next
        // request so its connection cache can hand the connection back.
        curl_multi_remove_handle($this->multi_handle, $this->curl_handle);
        $this->curl_handle = null;

        // A redirect is a configuration problem, not a transient failure or
        // a size signal: the usual case is an http:// base_url on a site
        // that forces https. Name the target instead of retrying into it —
        // the request cannot replay its streamed body through a redirect.
        if (in_array($http_code, [301, 302, 303, 307, 308], true)) {
            return $this->result(
                "failed",
                "redirected",
                $redirect_url !== ""
                    ? "The target redirected to \"" . $redirect_url . "\". Use that address as the push base_url."
                    : "The target answered HTTP " . $http_code . " without a Location header."
            );
        }

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
        if ($response_cursor !== null) {
            // The wire carries the id base64-encoded (see send_chunk); hand
            // callers the raw path back. A cursor that does not decode is as
            // useless as none — callers fall back to their persisted cursor.
            $decoded_artifact_id = base64_decode((string) ($response_cursor["artifact_id"] ?? ""), true);
            $response_cursor = $decoded_artifact_id !== false && $decoded_artifact_id !== ""
                ? ["artifact_id" => $decoded_artifact_id, "committed_bytes" => (int) ($response_cursor["committed_bytes"] ?? 0)]
                : null;
        }

        if ($http_code === 413 || ($decoded["reason"] ?? null) === "frame_too_large") {
            $reported_limit_bytes = $decoded["max_frame_bytes"] ?? null;
            $decision = $this->request_sizer->record_too_large(
                is_numeric($reported_limit_bytes) ? (int) $reported_limit_bytes : null
            );
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                $decision["action"] === "give_up" ? "request_size_exhausted" : "frame_too_large",
                null,
                $response_cursor
            );
        }

        if (($decoded["status"] ?? null) !== "complete") {
            $reason = is_string($decoded["reason"] ?? null) ? $decoded["reason"] : "unexpected_response";
            // The protocol designs these two as recoverable, not fatal:
            // busy is the store's retry-until-free lock contract, and
            // offset_gap arrives with the store's own cursor to resume
            // from. Neither says anything about request size, so the
            // sizer records nothing.
            $retryable = $reason === "busy" || $reason === "offset_gap";
            return $this->result(
                $retryable ? "retry" : "failed",
                $reason,
                is_string($decoded["detail"] ?? null) ? $decoded["detail"] : ("HTTP " . $http_code),
                $response_cursor,
                (int) ($decoded["files_verified"] ?? 0)
            );
        }

        // A success only teaches the sizer something when the request
        // carried bytes: "the host accepted an empty body" is no evidence
        // that the current size is safe to grow from.
        if ($this->chunks_sent > 0) {
            $this->request_sizer->record_success();
        }
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
     * One curl_multi step. curl_multi_exec() performs whatever transfer
     * work the socket allows right now — writing queued upload bytes,
     * reading response bytes — and returns without blocking. When the
     * transfer ends, curl_multi_info_read() delivers exactly one DONE
     * message carrying the result code; that is the only place libcurl
     * reports how the transfer ended, so it is harvested here into
     * $transfer_finished/$transfer_error. The select waits up to 50 ms for
     * socket readiness so callers can loop on this without busy-spinning.
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
