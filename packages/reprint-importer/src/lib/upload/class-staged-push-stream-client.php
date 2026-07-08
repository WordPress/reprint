<?php
/**
 * Sender for the staged push stream endpoint.
 *
 * A push stream is one authenticated HTTP request whose body is a sequence of
 * framed file chunks. The target commits each frame into
 * Site_Export_Staged_Artifacts as it is read, so a broken connection can be
 * retried from the last cursor the sender has, or from the beginning with the
 * target absorbing duplicate/verified frames. This class exposes request-level
 * control rather than a per-file request API: callers decide how many frames go
 * into one request, finalize it, and resume the next request from the cursor.
 */
class StagedPushStreamClient
{
    private string $base_url;

    private ?Site_Export_HMAC_Client $hmac_client;

    private PushFrameSizer $frame_sizer;

    private int $request_timeout;

    /** @var callable|null */
    private $on_before_request;

    /**
     * @param array $options
     *   - base_url (string, required): the export API URL; endpoint is appended
     *     to its query string.
     *   - hmac_client (?Site_Export_HMAC_Client): envelope request signer.
     *   - frame_sizer (?PushFrameSizer): frame-size decisions; defaults to a
     *     fresh frame sizer. Pass one restored from persisted state to keep
     *     learned limits.
     *   - request_timeout (int): per-request timeout in seconds.
     *   - on_before_request (?callable): test hook called just before curl runs.
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
        $this->on_before_request = $options["on_before_request"] ?? null;
    }

    /**
     * Create one staged_push request that the caller can fill chunk by chunk.
     *
     * The caller owns the nested loop: first start a request, then call
     * StagedPushStreamRequest::next_chunk() until the request budget or the
     * caller's own budget says to stop, then finalize and send the request.
     *
     * @param array{max_chunks?:int|null,max_payload_bytes?:int|null} $limits
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     */
    public function create_request(StagedPushStreamProcessor $processor, ?array $cursor = null, array $limits = []): StagedPushStreamRequest
    {
        $request_start_cursor = $cursor ?? $processor->cursor();
        return new StagedPushStreamRequest(
            $processor->create_request_body(
                $this->frame_sizer->chunk_bytes(),
                $request_start_cursor,
                $limits["max_chunks"] ?? null,
                $limits["max_payload_bytes"] ?? null
            ),
            $request_start_cursor
        );
    }

    /**
     * Send one finalized staged_push request and return the request-level result.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}
     */
    public function push_request(StagedPushStreamRequest $request): array
    {
        $request->finalize();
        if (!$request->has_chunks()) {
            return $this->result("request_complete", null, null, $request->cursor(), 0, 0, 0);
        }

        $body = $request->body();
        $request_start_cursor = $request->start_cursor();
        $response = $this->send_stream_request($body);

        if ($response["error"] !== null) {
            $decision = $this->frame_sizer->record_request_failure();
            return $this->result(
                $decision["action"] === "give_up" ? "failed" : "retry",
                "request_failed",
                $response["error"],
                $request_start_cursor,
                0,
                $body->bytes_streamed(),
                $body->chunks_streamed()
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
                $body->bytes_streamed(),
                $body->chunks_streamed()
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
                $body->bytes_streamed(),
                $body->chunks_streamed()
            );
        }

        if (($decoded["status"] ?? null) !== "complete") {
            return $this->result(
                "failed",
                is_string($decoded["reason"] ?? null) ? $decoded["reason"] : "unexpected_response",
                is_string($decoded["detail"] ?? null) ? $decoded["detail"] : ("HTTP " . (int) $response["http_code"]),
                is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : $body->cursor(),
                (int) ($decoded["files_verified"] ?? 0),
                $body->bytes_streamed(),
                $body->chunks_streamed()
            );
        }

        $this->frame_sizer->record_success();
        return $this->result(
            "request_complete",
            null,
            null,
            is_array($decoded["cursor"] ?? null) ? $decoded["cursor"] : null,
            (int) ($decoded["files_verified"] ?? 0),
            $body->bytes_streamed(),
            $body->chunks_streamed()
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
        if (!$processor->has_files()) {
            return $this->result("complete", null, null, null, 0, 0, 0);
        }

        $request = $this->create_request($processor, $cursor, $limits);
        while ($request->next_chunk()) {
            // Fill this request up to the configured request budget.
        }
        return $this->push_request($request);
    }

    /** @return array{http_code:int,body:string,error:?string} */
    private function send_stream_request(StagedPushRequestBody $body): array
    {
        $request_url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(["endpoint" => "staged_push"]);

        $request_headers = $this->hmac_client !== null
            ? $this->hmac_client->get_envelope_auth_headers("POST", $request_url)
            : [];
        $request_headers["Content-Type"] = Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE;
        $request_headers["Transfer-Encoding"] = "chunked";
        $request_headers["Expect"] = "";

        $header_lines = [];
        foreach ($request_headers as $name => $value) {
            $header_lines[] = "{$name}: {$value}";
        }

        $curl_handle = curl_init($request_url);
        if (function_exists("reprint_apply_curl_proxy_from_env")) {
            reprint_apply_curl_proxy_from_env($curl_handle);
        }
        if (function_exists("reprint_apply_curl_ca_bundle")) {
            reprint_apply_curl_ca_bundle($curl_handle);
        }

        curl_setopt_array($curl_handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->request_timeout,
            CURLOPT_READFUNCTION => static function ($curl_handle, $stream, int $length) use ($body): string {
                return $body->read($length);
            },
        ]);

        if ($this->on_before_request !== null) {
            call_user_func($this->on_before_request, $body);
        }

        $response_body = curl_exec($curl_handle);
        if ($response_body === false) {
            $error = curl_error($curl_handle) ?: ("curl errno " . curl_errno($curl_handle));
            curl_close($curl_handle);
            return ["http_code" => 0, "body" => "", "error" => $error];
        }
        $http_status_code = (int) curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        curl_close($curl_handle);

        return ["http_code" => $http_status_code, "body" => (string) $response_body, "error" => null];
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
 * Caller-controlled request builder for one staged push stream request.
 */
final class StagedPushStreamRequest
{
    private StagedPushRequestBody $body;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $start_cursor;

    private bool $finalized = false;

    /**
     * @param array{artifact_id?:string,committed_bytes?:int}|null $start_cursor
     */
    public function __construct(StagedPushRequestBody $body, ?array $start_cursor)
    {
        $this->body = $body;
        $this->start_cursor = $start_cursor;
    }

    /**
     * Add one more framed chunk to this request.
     *
     * @return bool Whether a chunk was added. False means the request is full
     *              or the processor has no more chunks to send.
     */
    public function next_chunk(): bool
    {
        if ($this->finalized) {
            return false;
        }
        return $this->body->next_chunk();
    }

    public function finalize(): void
    {
        $this->finalized = true;
        $this->body->finalize();
    }

    public function is_finalized(): bool
    {
        return $this->finalized;
    }

    public function has_chunks(): bool
    {
        return $this->body->chunks_planned() > 0;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function start_cursor(): ?array
    {
        return $this->start_cursor;
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function cursor(): ?array
    {
        return $this->body->cursor();
    }

    public function body(): StagedPushRequestBody
    {
        return $this->body;
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
        $this->limits = [
            "max_chunks" => $this->positive_int_or_null($options["max_chunks_per_request"] ?? null),
            "max_payload_bytes" => $this->positive_int_or_null($options["max_payload_bytes_per_request"] ?? null),
        ];
        $this->sleeper = $options["sleeper"] ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->cursor = $processor->cursor();
        $this->finished = !$processor->has_files() || $processor->is_finished_at($this->cursor);
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
                $this->backoff($this->request_failures);
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

    private function backoff(int $attempt): void
    {
        $delay = min(self::MAX_BACKOFF_USEC, self::RETRY_BACKOFF_USEC * (2 ** max(0, $attempt - 1)));
        call_user_func($this->sleeper, (int) $delay);
    }

    private function positive_int_or_null($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
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

    public function has_files(): bool
    {
        return $this->files() !== [];
    }

    /** @return array{artifact_id?:string,committed_bytes?:int}|null */
    public function cursor(): ?array
    {
        return $this->cursor;
    }

    /**
     * @param array{artifact_id?:string,committed_bytes?:int}|null $cursor
     */
    public function create_request_body(int $frame_bytes, ?array $cursor = null, ?int $max_chunks = null, ?int $max_payload_bytes = null): StagedPushRequestBody
    {
        return new StagedPushRequestBody($this->files(), $frame_bytes, $cursor ?? $this->cursor, $max_chunks, $max_payload_bytes);
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

    /** @return array<int,array{artifact_id:string,source_path:string,total_bytes:int}> */
    private function files(): array
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

/**
 * Pull-based request-body producer for one staged push stream.
 */
final class StagedPushRequestBody
{
    /** @var array<int,array{artifact_id:string,source_path:string,total_bytes:int}> */
    private array $files;

    private int $frame_bytes;

    /** @var array{artifact_id?:string,committed_bytes?:int}|null */
    private ?array $initial_cursor;

    /** @var array<int,array{file_index:int,offset:int,bytes:int,final:bool,header:string}> */
    private array $frames = [];

    /** @var array<int,string> */
    private array $pending = [];

    private int $file_index = 0;

    private int $offset = 0;

    private int $stream_frame_index = 0;

    /** @var resource|null */
    private $source_handle = null;

    private int $current_frame_remaining_bytes = 0;

    private int $current_frame_file_index = 0;

    private bool $finished = false;

    private bool $finalized = false;

    private int $payload_bytes_planned = 0;

    private int $bytes_streamed = 0;

    private int $chunks_streamed = 0;

    private ?int $max_chunks;

    private ?int $max_payload_bytes;

    private ?array $cursor = null;

    /** @param array<int,array{artifact_id:string,source_path:string,total_bytes:int}> $files */
    public function __construct(array $files, int $frame_bytes, ?array $cursor, ?int $max_chunks = null, ?int $max_payload_bytes = null)
    {
        $this->files = $files;
        $this->frame_bytes = max(1, $frame_bytes);
        $this->initial_cursor = $cursor;
        $this->max_chunks = $max_chunks !== null ? max(1, $max_chunks) : null;
        $this->max_payload_bytes = $max_payload_bytes !== null ? max(1, $max_payload_bytes) : null;
        $this->apply_cursor($cursor);
    }

    /**
     * Plan one more frame for this request body.
     *
     * The request still streams from disk through curl later. Planning a frame
     * only records its header, source path, and byte range so the caller can
     * decide where a request boundary belongs before the HTTP request starts.
     */
    public function next_chunk(): bool
    {
        if ($this->finalized || $this->file_index >= count($this->files) || $this->request_limit_reached()) {
            return false;
        }

        $file = $this->files[$this->file_index];
        if ($file["total_bytes"] === 0) {
            $this->frames[] = $this->frame($this->file_index, 0, 0, true);
            $this->cursor = ["artifact_id" => $file["artifact_id"], "committed_bytes" => 0];
            $this->file_index++;
            return true;
        }

        $bytes = min($this->frame_bytes, $file["total_bytes"] - $this->offset, $this->remaining_payload_bytes());
        $final = $this->offset + $bytes >= $file["total_bytes"];
        $this->frames[] = $this->frame($this->file_index, $this->offset, $bytes, $final);
        $this->payload_bytes_planned += $bytes;
        $this->offset += $bytes;
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

    public function finalize(): void
    {
        $this->finalized = true;
    }

    public function read(int $length): string
    {
        $out = "";
        while (strlen($out) < $length && !$this->finished) {
            $remaining_output_bytes = $length - strlen($out);
            if ($this->pending !== []) {
                $piece = array_shift($this->pending);
                if (strlen($piece) > $remaining_output_bytes) {
                    $out .= substr($piece, 0, $remaining_output_bytes);
                    array_unshift($this->pending, substr($piece, $remaining_output_bytes));
                } else {
                    $out .= $piece;
                }
                continue;
            }

            if ($this->current_frame_remaining_bytes > 0) {
                $piece_bytes = min($remaining_output_bytes, $this->current_frame_remaining_bytes);
                $piece = $this->read_exactly($this->source_handle, $piece_bytes);
                if ($piece === null) {
                    throw new RuntimeException("Source file ended before declared size: " . $this->files[$this->current_frame_file_index]["source_path"]);
                }
                $out .= $piece;
                $this->bytes_streamed += strlen($piece);
                $this->current_frame_remaining_bytes -= strlen($piece);
                $this->cursor = [
                    "artifact_id" => $this->files[$this->current_frame_file_index]["artifact_id"],
                    "committed_bytes" => $this->frames[$this->stream_frame_index - 1]["offset"] + $this->frames[$this->stream_frame_index - 1]["bytes"] - $this->current_frame_remaining_bytes,
                ];

                if ($this->current_frame_remaining_bytes === 0) {
                    $this->close_source();
                }
                continue;
            }

            if ($this->stream_frame_index >= count($this->frames)) {
                if ($this->finalized) {
                    $this->finished = true;
                }
                break;
            }

            $this->begin_streaming_frame($this->frames[$this->stream_frame_index]);
            $this->stream_frame_index++;
        }
        return $out;
    }

    public function bytes_streamed(): int
    {
        return $this->bytes_streamed;
    }

    public function chunks_streamed(): int
    {
        return $this->chunks_streamed;
    }

    public function chunks_planned(): int
    {
        return count($this->frames);
    }

    public function cursor(): ?array
    {
        return $this->cursor;
    }

    private function apply_cursor(?array $cursor): void
    {
        $start = $this->cursor_start();
        $this->file_index = $start["file_index"];
        $this->offset = $start["offset"];
    }

    /** @return array{file_index:int,offset:int} */
    private function cursor_start(): array
    {
        if ($this->initial_cursor === null || !is_string($this->initial_cursor["artifact_id"] ?? null)) {
            return ["file_index" => 0, "offset" => 0];
        }
        foreach ($this->files as $index => $file) {
            if ($file["artifact_id"] === $this->initial_cursor["artifact_id"]) {
                $offset = min(max(0, (int) ($this->initial_cursor["committed_bytes"] ?? 0)), $file["total_bytes"]);
                if ($offset >= $file["total_bytes"]) {
                    return ["file_index" => $index + 1, "offset" => 0];
                }
                return [
                    "file_index" => $index,
                    "offset" => $offset,
                ];
            }
        }
        return ["file_index" => 0, "offset" => 0];
    }

    /** @return array{file_index:int,offset:int,bytes:int,final:bool,header:string} */
    private function frame(int $file_index, int $offset, int $bytes, bool $final): array
    {
        return [
            "file_index" => $file_index,
            "offset" => $offset,
            "bytes" => $bytes,
            "final" => $final,
            "header" => $this->frame_header($this->files[$file_index], $offset, $bytes, $final),
        ];
    }

    /** @param array{file_index:int,offset:int,bytes:int,final:bool,header:string} $frame */
    private function begin_streaming_frame(array $frame): void
    {
        $this->pending[] = $frame["header"];
        $this->chunks_streamed++;
        $this->current_frame_file_index = $frame["file_index"];
        $this->cursor = [
            "artifact_id" => $this->files[$frame["file_index"]]["artifact_id"],
            "committed_bytes" => $frame["offset"],
        ];

        if ($frame["bytes"] === 0) {
            return;
        }

        $file = $this->files[$frame["file_index"]];
        $this->source_handle = @fopen($file["source_path"], "rb");
        if ($this->source_handle === false) {
            throw new RuntimeException("Source file is unreadable: " . $file["source_path"]);
        }
        if (fseek($this->source_handle, $frame["offset"]) !== 0) {
            $this->close_source();
            throw new RuntimeException("Could not seek source file: " . $file["source_path"]);
        }
        $this->current_frame_remaining_bytes = $frame["bytes"];
    }

    private function request_limit_reached(): bool
    {
        if ($this->max_chunks !== null && count($this->frames) >= $this->max_chunks) {
            return true;
        }
        return $this->max_payload_bytes !== null && $this->payload_bytes_planned >= $this->max_payload_bytes;
    }

    private function remaining_payload_bytes(): int
    {
        if ($this->max_payload_bytes === null) {
            return PHP_INT_MAX;
        }
        return max(1, $this->max_payload_bytes - $this->payload_bytes_planned);
    }

    /** @param array{artifact_id:string,source_path:string,total_bytes:int} $file */
    private function frame_header(array $file, int $offset, int $bytes, bool $final): string
    {
        return Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header(
            $file["artifact_id"],
            $offset,
            $bytes,
            $file["total_bytes"],
            $final
        );
    }

    private function read_exactly($source_handle, int $bytes): ?string
    {
        return Site_Export_Staged_Push_Stream_Protocol::read_exactly($source_handle, $bytes);
    }

    private function close_source(): void
    {
        if (is_resource($this->source_handle)) {
            fclose($this->source_handle);
        }
        $this->source_handle = null;
    }
}
