<?php
/**
 * Client for the staged artifact endpoints: uploads one local file into the
 * target's staged store in bounded, resumable chunks.
 *
 * This is the sender side of a push transfer. The target holds all transfer
 * state — Site_Export_Staged_Artifacts tracks committed_bytes, and this
 * client re-reads it from staged_status on every start — so the client
 * persists nothing about its position. Interrupt it anywhere, rerun it, and
 * it continues from whatever the store confirmed. The only client-side state
 * worth carrying across runs is the chunk sizer's learned limits, and that
 * belongs to the caller (UploadChunkSizer::get_state()).
 *
 * The upload loop translates the endpoints' typed responses into the next
 * action and into UploadChunkSizer feedback:
 *
 * - accepted           advance to committed_bytes, record_success()
 * - duplicate          advance to committed_bytes (a retried chunk landed
 *                      before its response was lost); still a success signal
 *                      for the sizer — the host accepted a body of this size
 * - offset_gap         adopt committed_bytes and continue from there; bounded
 *                      by a no-progress counter so a disagreeing server
 *                      cannot loop the client forever
 * - 413 too large      record_too_large(max_request_bytes) and retry the same
 *                      offset with the shrunk chunk; "give_up" from the sizer
 *                      ends the upload with a typed failure
 * - busy               a predecessor request still holds the store's lock;
 *                      short bounded backoff, same offset
 * - io_error           bounded backoff — the target's disk, not the wire
 * - transport failure  record_transport_failure() (halves the chunk, holds
 *                      growth) and retry the same offset, bounded
 * - auth failures      fatal immediately; retrying cannot fix a bad secret
 *
 * Every retry class resets when the transfer makes progress, so the bounds
 * cap consecutive failures, not the transfer length. Every loop iteration
 * either advances committed bytes, strictly shrinks the sizer's ceiling, or
 * consumes one of those bounded counters — the loop terminates.
 *
 * The transport is a callable so tests drive the real endpoint classes
 * in-process; the default is curl, signed per request by
 * Site_Export_HMAC_Client (signature over nonce + timestamp + SHA256(body),
 * which is exactly what staged_upload verifies before reading the body).
 */
class StagedUploadClient
{
    /** Consecutive transport failures before the upload is abandoned. */
    private const MAX_TRANSPORT_RETRIES = 5;

    /** Consecutive "busy" responses before the upload is abandoned. */
    private const MAX_BUSY_RETRIES = 5;

    /** Consecutive server io_error responses before the upload is abandoned. */
    private const MAX_IO_RETRIES = 3;

    /** Resyncs that fail to advance committed_bytes before giving up. */
    private const MAX_STALLED_RESYNCS = 3;

    /** Base backoff between retries, in microseconds. */
    private const RETRY_BACKOFF_USEC = 250000;

    /** Longest single backoff, in microseconds. */
    private const MAX_BACKOFF_USEC = 5000000;

    /** Bytes copied at a time when composing streamed batch bodies. */
    private const BATCH_STREAM_BUFFER_BYTES = 262144;

    /** Batch spools stay in memory up to this size, then php://temp spills. */
    private const BATCH_SPOOL_MEMORY_BYTES = 2097152;

    private string $base_url;

    private ?Site_Export_HMAC_Client $hmac_client;

    private UploadChunkSizer $sizer;

    /** @var callable(string,string,array,mixed,int):array */
    private $transport;

    /** @var callable(int):void */
    private $sleeper;

    private int $request_timeout;

    /**
     * @param array $options
     *   - base_url (string, required): the export API URL; endpoint and
     *     artifact parameters are appended to its query string.
     *   - hmac_client (?Site_Export_HMAC_Client): request signer. Without
     *     one, requests go out unsigned and the target rejects uploads.
     *   - sizer (?UploadChunkSizer): chunk-size decisions; defaults to a
     *     fresh sizer. Pass one restored from persisted state to keep the
     *     limits a previous run learned.
     *   - transport (?callable): fn(method, url, headers, body, timeout) =>
     *     ['http_code' => int, 'body' => string, 'error' => ?string], where
     *     a non-null error means the request never produced an HTTP
     *     response. Defaults to curl.
     *   - sleeper (?callable): fn(int $microseconds), for tests.
     *   - request_timeout (int): per-request timeout in seconds.
     */
    public function __construct(array $options)
    {
        $base_url = $options["base_url"] ?? null;
        if (!is_string($base_url) || $base_url === "") {
            throw new InvalidArgumentException("StagedUploadClient requires a base_url option.");
        }
        $this->base_url = $base_url;
        $this->hmac_client = $options["hmac_client"] ?? null;
        $this->sizer = $options["sizer"] ?? new UploadChunkSizer();
        $this->transport = $options["transport"] ?? [$this, "curl_transport"];
        $this->sleeper = $options["sleeper"] ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $timeout = $options["request_timeout"] ?? null;
        $this->request_timeout = is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 120;
    }

    /**
     * Upload one artifact until the target records it verified.
     *
     * @param string $artifact_id Target-relative path the artifact applies to.
     * @param string $source_path Local file holding the artifact bytes.
     * @param ?int $total_bytes Plan-declared size; defaults to the file size.
     * @param ?callable $on_progress fn(int $committed_bytes, int $total_bytes)
     *   after every advance.
     * @param ?int $expected_mtime Plan-declared source mtime. A mismatch
     *   before uploading means the plan is stale — the artifact fails
     *   "source_changed" so a re-plan pushes the current content instead
     *   of a mix.
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     *   status "verified" on success, "failed" with a typed reason otherwise.
     */
    public function upload_artifact(
        string $artifact_id,
        string $source_path,
        ?int $total_bytes = null,
        ?callable $on_progress = null,
        ?int $expected_mtime = null
    ): array {
        if ($total_bytes === null) {
            $size = @filesize($source_path);
            if ($size === false) {
                return $this->failed("source_unreadable", $source_path);
            }
            $total_bytes = $size;
        }

        // The store's committed offset is the resume point; the client
        // keeps no position of its own.
        $status = $this->request_json("GET", "staged_status", ["artifact_id" => $artifact_id]);
        if ($status["error"] !== null || !is_array($status["json"])) {
            return $this->failed("status_unavailable", $status["error"] ?? "invalid response");
        }
        if ($this->is_auth_envelope($status)) {
            return $this->failed("auth_failed", $this->envelope_error($status));
        }
        if (!empty($status["json"]["verified"])) {
            // A finished earlier run. Finalize re-confirms the size and is
            // idempotent; a mismatch means this plan disagrees with what
            // was verified, and only a discard can resolve that.
            return $this->finalize($artifact_id, $total_bytes);
        }
        $offset = max(0, (int) ($status["json"]["committed_bytes"] ?? 0));
        $offset = min($offset, $total_bytes);

        $source = @fopen($source_path, "rb");
        if ($source === false) {
            return $this->failed("source_unreadable", $source_path);
        }
        $opened_stat = fstat($source);

        // Every check downstream is a byte count, so a rewrite of the
        // source is invisible to all of them. The exporter re-checks its
        // files after streaming (x-file-changed); the sender owes the same
        // honesty about its own disk, before and after the upload.
        if (
            $expected_mtime !== null
            && is_array($opened_stat)
            && (int) $opened_stat["mtime"] !== $expected_mtime
        ) {
            fclose($source);
            // Committed bytes may belong to the older version; a mixed
            // artifact must never verify.
            $this->discard($artifact_id);
            return $this->failed(
                "source_changed",
                sprintf("planned mtime %d, found %d", $expected_mtime, (int) $opened_stat["mtime"])
            );
        }

        try {
            $transport_failures = 0;
            $busy_responses = 0;
            $io_errors = 0;
            $stalled_resyncs = 0;

            while ($offset < $total_bytes) {
                $chunk_bytes = min($this->sizer->chunk_bytes(), $total_bytes - $offset);
                if (fseek($source, $offset) !== 0) {
                    return $this->failed("source_unreadable", "seek", $offset);
                }
                $chunk = $this->read_exactly($source, $chunk_bytes);
                if ($chunk === null) {
                    // The file ends before the declared total; uploading on
                    // would just move the mismatch to finalize.
                    return $this->failed("source_short", null, $offset);
                }

                $response = $this->request_json("POST", "staged_upload", [
                    "artifact_id" => $artifact_id,
                    "offset" => $offset,
                ], $chunk);

                if ($response["error"] !== null) {
                    $decision = $this->sizer->record_transport_failure();
                    if ($decision["action"] === "give_up") {
                        return $this->failed("transport_failed", $response["error"], $offset);
                    }
                    $transport_failures++;
                    if ($transport_failures > self::MAX_TRANSPORT_RETRIES) {
                        return $this->failed("transport_failed", $response["error"], $offset);
                    }
                    $this->backoff($transport_failures);
                    continue;
                }

                $json = is_array($response["json"]) ? $response["json"] : [];
                $http_code = $response["http_code"];

                if ($http_code === 413) {
                    $reported = $json["max_request_bytes"] ?? null;
                    $decision = $this->sizer->record_too_large(
                        is_numeric($reported) ? (int) $reported : null
                    );
                    if ($decision["action"] === "give_up") {
                        return $this->failed("chunk_size_exhausted", null, $offset);
                    }
                    continue;
                }

                $result_status = $json["status"] ?? null;
                $reason = $json["reason"] ?? null;

                if ($result_status === "accepted" || $result_status === "duplicate") {
                    $committed = (int) ($json["committed_bytes"] ?? $offset);
                    $advanced = $committed > $offset;
                    $offset = max($offset, min($committed, $total_bytes));
                    // Either way the host accepted a request of this size.
                    $this->sizer->record_success();
                    if ($advanced) {
                        $transport_failures = 0;
                        $busy_responses = 0;
                        $io_errors = 0;
                        $stalled_resyncs = 0;
                        if ($on_progress !== null) {
                            $on_progress($offset, $total_bytes);
                        }
                    }
                    continue;
                }

                if ($result_status === "busy") {
                    $busy_responses++;
                    if ($busy_responses > self::MAX_BUSY_RETRIES) {
                        return $this->failed("busy_exhausted", null, $offset);
                    }
                    $this->backoff($busy_responses);
                    continue;
                }

                if ($reason === "offset_gap") {
                    $committed = max(0, (int) ($json["committed_bytes"] ?? 0));
                    if ($committed <= $offset) {
                        // The store is behind us (a discard or another
                        // sender); re-uploading from its offset is the only
                        // way forward, but only while it moves.
                        $stalled_resyncs++;
                        if ($stalled_resyncs > self::MAX_STALLED_RESYNCS) {
                            return $this->failed("resync_exhausted", null, $offset);
                        }
                    }
                    $offset = min($committed, $total_bytes);
                    continue;
                }

                if ($reason === "already_verified") {
                    break;
                }

                if ($reason === "io_error") {
                    $io_errors++;
                    if ($io_errors > self::MAX_IO_RETRIES) {
                        return $this->failed("server_io_error", $json["detail"] ?? null, $offset);
                    }
                    $this->backoff($io_errors);
                    continue;
                }

                if ($reason === "auth_failed" || $reason === "content_hash_mismatch") {
                    return $this->failed("auth_failed", $json["detail"] ?? null, $offset);
                }

                // invalid_artifact_id, invalid_offset, not_configured,
                // method_not_allowed, or something newer: retrying cannot
                // change the answer.
                return $this->failed(
                    is_string($reason) ? $reason : "unexpected_response",
                    "HTTP " . $http_code,
                    $offset
                );
            }
        } finally {
            fclose($source);
        }

        // Post-upload volatility check: a rewrite during the read window
        // can leave staged bytes that match no version of the file. Torn
        // content must fail typed, not verify.
        clearstatcache(true, $source_path);
        $now_stat = @stat($source_path);
        if (
            !is_array($opened_stat)
            || $now_stat === false
            || $now_stat["ino"] !== $opened_stat["ino"]
            || $now_stat["mtime"] !== $opened_stat["mtime"]
            || $now_stat["size"] !== $opened_stat["size"]
        ) {
            $this->discard($artifact_id);
            return $this->failed("source_changed", "source changed while uploading");
        }

        return $this->finalize($artifact_id, $total_bytes, $on_progress);
    }

    /**
     * Report the store's resume state for an artifact.
     *
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int,exists:bool,verified:bool}|array{status:string,reason:?string,detail:?string,committed_bytes:int}
     */
    public function status(string $artifact_id): array
    {
        $response = $this->request_json("GET", "staged_status", ["artifact_id" => $artifact_id]);
        if ($response["error"] !== null || !is_array($response["json"])) {
            return $this->failed("status_unavailable", $response["error"] ?? "invalid response");
        }
        if ($this->is_auth_envelope($response)) {
            return $this->failed("auth_failed", $this->envelope_error($response));
        }
        return [
            "status" => "ok",
            "reason" => null,
            "detail" => null,
            "committed_bytes" => (int) ($response["json"]["committed_bytes"] ?? 0),
            "exists" => (bool) ($response["json"]["exists"] ?? false),
            "verified" => (bool) ($response["json"]["verified"] ?? false),
        ];
    }

    /**
     * Drop an artifact's staged data; retries while the store reports the
     * discard incomplete, mirroring its retry-until-true contract.
     *
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     */
    public function discard(string $artifact_id): array
    {
        for ($attempt = 1; $attempt <= self::MAX_BUSY_RETRIES; $attempt++) {
            $response = $this->request_json("POST", "staged_discard", ["artifact_id" => $artifact_id]);
            if ($response["error"] !== null) {
                return $this->failed("transport_failed", $response["error"]);
            }
            if ($this->is_auth_envelope($response)) {
                return $this->failed("auth_failed", $this->envelope_error($response));
            }
            $json = is_array($response["json"]) ? $response["json"] : [];
            if (!empty($json["discarded"])) {
                return [
                    "status" => "discarded",
                    "reason" => null,
                    "detail" => null,
                    "committed_bytes" => 0,
                ];
            }
            if ($response["http_code"] !== 423) {
                return $this->failed(
                    is_string($json["reason"] ?? null) ? $json["reason"] : "unexpected_response",
                    "HTTP " . $response["http_code"]
                );
            }
            $this->backoff($attempt);
        }
        return $this->failed("busy_exhausted");
    }

    /**
     * Upload a batch of complete small files in one request.
     *
     * One HTTP conversation carries many files — push's answer to pull's
     * multipart batching. The request body is composed into a temp stream,
     * not one PHP string: each source is copied through a bounded per-file
     * spool, checked for volatility, then appended to the outgoing stream.
     * Files that changed underfoot fail "source_changed" locally and are
     * excluded from the wire.
     *
     * @param array $files Entries: ['artifact_id','source_path','total_bytes','mtime'?].
     * @return array{status:string,reason:?string,detail:?string,per_file:array<string,array{status:string,reason:?string}>}
     *   "ok" means the request cycle finished and per_file holds each
     *   file's outcome — "verified", "failed" (typed), or "not_attempted"
     *   (the server stopped at an earlier frame; retry those). "failed"
     *   carries a transfer-scoped reason; "batch_too_large" specifically
     *   means the sizer shrank and the caller should repartition.
     */
    public function upload_batch(array $files): array
    {
        $per_file = [];
        $body = $this->open_batch_spool();
        if ($body === false) {
            return ["status" => "failed", "reason" => "source_unreadable", "detail" => "open_batch_spool", "per_file" => $per_file];
        }
        $hash = hash_init("sha256");
        $sendable = [];
        foreach ($files as $file) {
            $artifact_id = (string) $file["artifact_id"];
            $total_bytes = (int) $file["total_bytes"];
            $source = @fopen((string) $file["source_path"], "rb");
            if ($source === false) {
                $per_file[$artifact_id] = ["status" => "failed", "reason" => "source_unreadable"];
                continue;
            }
            $opened = fstat($source);
            $expected_mtime = isset($file["mtime"]) ? (int) $file["mtime"] : null;
            if (
                $expected_mtime !== null
                && is_array($opened)
                && (int) $opened["mtime"] !== $expected_mtime
            ) {
                fclose($source);
                $this->discard($artifact_id);
                $per_file[$artifact_id] = ["status" => "failed", "reason" => "source_changed"];
                continue;
            }
            $payload = $this->copy_source_to_spool($source, $total_bytes);
            fclose($source);
            clearstatcache(true, (string) $file["source_path"]);
            $now_stat = @stat((string) $file["source_path"]);
            if ($payload === null) {
                $per_file[$artifact_id] = ["status" => "failed", "reason" => "source_short"];
                continue;
            }
            if (
                !is_array($opened)
                || $now_stat === false
                || $now_stat["ino"] !== $opened["ino"]
                || $now_stat["mtime"] !== $opened["mtime"]
                || $now_stat["size"] !== $opened["size"]
            ) {
                fclose($payload);
                $this->discard($artifact_id);
                $per_file[$artifact_id] = ["status" => "failed", "reason" => "source_changed"];
                continue;
            }

            $header = json_encode([
                "artifact_id" => $artifact_id,
                "offset" => 0,
                "length" => $total_bytes,
                "total_bytes" => $total_bytes,
                "final" => true,
            ]) . "\n";
            if (
                !$this->write_batch_bytes($body, $hash, $header)
                || !$this->write_batch_stream($body, $hash, $payload)
            ) {
                fclose($payload);
                fclose($body);
                return ["status" => "failed", "reason" => "source_unreadable", "detail" => "write_batch_spool", "per_file" => $per_file];
            }
            fclose($payload);
            $sendable[] = $artifact_id;
        }

        if ($sendable === []) {
            fclose($body);
            return ["status" => "ok", "reason" => null, "detail" => null, "per_file" => $per_file];
        }

        rewind($body);
        $content_hash = hash_final($hash);
        $transport_failures = 0;
        for ($attempt = 1; $attempt <= self::MAX_BUSY_RETRIES + self::MAX_TRANSPORT_RETRIES; $attempt++) {
            $response = $this->request_json("POST", "staged_upload_batch", [], $body, $content_hash);
            if ($response["error"] !== null) {
                $decision = $this->sizer->record_transport_failure();
                $transport_failures++;
                if ($decision["action"] === "give_up" || $transport_failures > self::MAX_TRANSPORT_RETRIES) {
                    fclose($body);
                    return ["status" => "failed", "reason" => "transport_failed", "detail" => $response["error"], "per_file" => $per_file];
                }
                $this->backoff($transport_failures);
                continue;
            }
            if ($this->is_auth_envelope($response)) {
                fclose($body);
                return ["status" => "failed", "reason" => "auth_failed", "detail" => $this->envelope_error($response), "per_file" => $per_file];
            }
            $json = is_array($response["json"]) ? $response["json"] : [];
            if ($response["http_code"] === 413) {
                $reported = $json["max_request_bytes"] ?? null;
                $decision = $this->sizer->record_too_large(is_numeric($reported) ? (int) $reported : null);
                if ($decision["action"] === "give_up") {
                    fclose($body);
                    return ["status" => "failed", "reason" => "chunk_size_exhausted", "detail" => null, "per_file" => $per_file];
                }
                fclose($body);
                return ["status" => "failed", "reason" => "batch_too_large", "detail" => null, "per_file" => $per_file];
            }
            if (($json["status"] ?? null) === "busy") {
                $this->backoff($attempt);
                continue;
            }

            foreach (($json["results"] ?? []) as $result) {
                if (!is_array($result) || !is_string($result["artifact_id"] ?? null)) {
                    continue;
                }
                if (($result["status"] ?? null) === "verified") {
                    $per_file[$result["artifact_id"]] = ["status" => "verified", "reason" => null];
                } else {
                    $reason = $result["reason"] ?? null;
                    $per_file[$result["artifact_id"]] = [
                        "status" => "failed",
                        "reason" => is_string($reason) ? $reason : "unexpected_response",
                    ];
                }
            }
            if (($json["status"] ?? null) === "rejected") {
                $detail = $json["detail"] ?? null;
                $reported = is_string($detail) ? ($per_file[$detail] ?? null) : null;
                if (!is_array($reported) || $reported["status"] !== "failed") {
                    $reason = $json["reason"] ?? null;
                    fclose($body);
                    return [
                        "status" => "failed",
                        "reason" => is_string($reason) ? $reason : "unexpected_response",
                        "detail" => is_string($detail) ? $detail : null,
                        "per_file" => $per_file,
                    ];
                }
            }
            foreach ($sendable as $artifact_id) {
                if (!isset($per_file[$artifact_id])) {
                    // The server stopped at an earlier frame; these retry.
                    $per_file[$artifact_id] = ["status" => "not_attempted", "reason" => null];
                }
            }
            if (($json["status"] ?? null) === "ok") {
                $this->sizer->record_success();
            }
            fclose($body);
            return ["status" => "ok", "reason" => null, "detail" => null, "per_file" => $per_file];
        }
        fclose($body);
        return ["status" => "failed", "reason" => "busy_exhausted", "detail" => null, "per_file" => $per_file];
    }

    /**
     * Ask the target to validate (check_only) or apply a staged transfer.
     *
     * check_only is the sender's early gate: an environment that can never
     * apply — cross-device staging above all — answers "rejected" here,
     * before any chunk has been uploaded.
     *
     * @return array{status:string,reason:?string,detail:?string,applied:int,already_applied:int,skipped:int,deleted:int,staging_free_bytes:?int,max_request_bytes:?int}
     *   status "applied"|"ready"|"failed". "ready" carries the target's
     *   preflight facts: free staging space and its request cap.
     */
    public function apply(string $manifest_id, bool $check_only = false): array
    {
        for ($attempt = 1; $attempt <= self::MAX_BUSY_RETRIES; $attempt++) {
            $params = ["manifest_id" => $manifest_id];
            if ($check_only) {
                $params["check_only"] = 1;
            }
            $response = $this->request_json("POST", "staged_apply", $params);
            if ($response["error"] !== null) {
                return $this->apply_failed("transport_failed", $response["error"]);
            }
            if ($this->is_auth_envelope($response)) {
                return $this->apply_failed("auth_failed", $this->envelope_error($response));
            }
            $json = is_array($response["json"]) ? $response["json"] : [];
            $status = $json["status"] ?? null;
            if ($status === "applied" || $status === "ready") {
                return [
                    "status" => $status,
                    "reason" => null,
                    "detail" => null,
                    "applied" => (int) ($json["applied"] ?? 0),
                    "already_applied" => (int) ($json["already_applied"] ?? 0),
                    "skipped" => (int) ($json["skipped"] ?? 0),
                    "deleted" => (int) ($json["deleted"] ?? 0),
                    "staging_free_bytes" => is_numeric($json["staging_free_bytes"] ?? null)
                        ? (int) $json["staging_free_bytes"]
                        : null,
                    "max_request_bytes" => is_numeric($json["max_request_bytes"] ?? null)
                        ? (int) $json["max_request_bytes"]
                        : null,
                ];
            }
            if ($status === "busy") {
                $this->backoff($attempt);
                continue;
            }
            return $this->apply_failed(
                is_string($json["reason"] ?? null) ? $json["reason"] : "unexpected_response",
                $json["detail"] ?? ("HTTP " . $response["http_code"])
            );
        }
        return $this->apply_failed("busy_exhausted", null);
    }

    /**
     * The embedding layer (lib.php in the WordPress wiring) answers
     * control-plane auth failures with its own {error, code} envelope
     * before any endpoint runs. Surface those as auth_failed — retrying
     * cannot fix a bad secret — instead of an unexpected response.
     */
    private function is_auth_envelope(array $response): bool
    {
        return in_array($response["http_code"], [401, 403], true)
            && is_array($response["json"])
            && !isset($response["json"]["status"]);
    }

    private function envelope_error(array $response): string
    {
        $error = $response["json"]["error"] ?? null;
        return is_string($error) ? $error : ("HTTP " . $response["http_code"]);
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,applied:int,already_applied:int,skipped:int,deleted:int,staging_free_bytes:?int,max_request_bytes:?int}
     */
    private function apply_failed(string $reason, ?string $detail): array
    {
        return [
            "status" => "failed",
            "reason" => $reason,
            "detail" => $detail,
            "applied" => 0,
            "already_applied" => 0,
            "skipped" => 0,
            "deleted" => 0,
            "staging_free_bytes" => null,
            "max_request_bytes" => null,
        ];
    }

    /**
     * Confirm the assembled artifact; retried only for "busy".
     *
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     */
    private function finalize(string $artifact_id, int $total_bytes, ?callable $on_progress = null): array
    {
        for ($attempt = 1; $attempt <= self::MAX_BUSY_RETRIES; $attempt++) {
            $response = $this->request_json("POST", "staged_finalize", [
                "artifact_id" => $artifact_id,
                "total_bytes" => $total_bytes,
            ]);
            if ($response["error"] !== null) {
                return $this->failed("transport_failed", $response["error"], $total_bytes);
            }
            if ($this->is_auth_envelope($response)) {
                return $this->failed("auth_failed", $this->envelope_error($response));
            }
            $json = is_array($response["json"]) ? $response["json"] : [];
            if (($json["status"] ?? null) === "verified") {
                if ($on_progress !== null) {
                    $on_progress($total_bytes, $total_bytes);
                }
                return [
                    "status" => "verified",
                    "reason" => null,
                    "detail" => null,
                    "committed_bytes" => (int) ($json["committed_bytes"] ?? $total_bytes),
                ];
            }
            if (($json["status"] ?? null) === "busy") {
                $this->backoff($attempt);
                continue;
            }
            return $this->failed(
                is_string($json["reason"] ?? null) ? $json["reason"] : "unexpected_response",
                $json["detail"] ?? ("HTTP " . $response["http_code"]),
                (int) ($json["committed_bytes"] ?? 0)
            );
        }
        return $this->failed("busy_exhausted", null);
    }

    /**
     * Sign and send one request, decoding the JSON response.
     *
     * @return array{http_code:int,json:mixed,error:?string}
     */
    private function request_json(string $method, string $endpoint, array $params, $body = "", ?string $content_hash = null): array
    {
        $url = $this->base_url
            . (strpos($this->base_url, "?") === false ? "?" : "&")
            . http_build_query(array_merge(["endpoint" => $endpoint], $params));

        if (is_resource($body)) {
            rewind($body);
        }
        $headers = [];
        if ($this->hmac_client !== null) {
            if ($content_hash === null) {
                $content_hash = is_resource($body) ? $this->hash_stream($body) : hash("sha256", (string) $body);
            }
            $headers = $this->auth_headers_for_hash($content_hash);
        }
        $headers["Content-Type"] = "application/octet-stream";
        if (is_resource($body)) {
            $headers["Content-Length"] = (string) $this->stream_size($body);
            rewind($body);
        }

        $response = call_user_func($this->transport, $method, $url, $headers, $body, $this->request_timeout);

        $error = $response["error"] ?? null;
        if ($error !== null) {
            return ["http_code" => 0, "json" => null, "error" => (string) $error];
        }

        $decoded = json_decode((string) ($response["body"] ?? ""), true);
        if (!is_array($decoded)) {
            // An HTTP response without the protocol's JSON body — a proxy
            // error page, a truncated reply. A bare 413 still means "too
            // large", so preserve the code and let the caller classify.
            $code = (int) ($response["http_code"] ?? 0);
            if ($code === 413) {
                return ["http_code" => 413, "json" => [], "error" => null];
            }
            return ["http_code" => $code, "json" => null, "error" => "invalid JSON response (HTTP {$code})"];
        }

        return [
            "http_code" => (int) ($response["http_code"] ?? 0),
            "json" => $decoded,
            "error" => null,
        ];
    }

    /** @return resource|false */
    private function open_batch_spool()
    {
        return fopen("php://temp/maxmemory:" . self::BATCH_SPOOL_MEMORY_BYTES, "w+b");
    }

    /** @return resource|null */
    private function copy_source_to_spool($source, int $bytes)
    {
        $spool = $this->open_batch_spool();
        if ($spool === false) {
            return null;
        }
        $remaining = $bytes;
        while ($remaining > 0) {
            $chunk = fread($source, min(self::BATCH_STREAM_BUFFER_BYTES, $remaining));
            if ($chunk === false || $chunk === "") {
                fclose($spool);
                return null;
            }
            $written = fwrite($spool, $chunk);
            if ($written !== strlen($chunk)) {
                fclose($spool);
                return null;
            }
            $remaining -= strlen($chunk);
        }
        rewind($spool);
        return $spool;
    }

    private function write_batch_bytes($body, $hash, string $bytes): bool
    {
        hash_update($hash, $bytes);
        return fwrite($body, $bytes) === strlen($bytes);
    }

    private function write_batch_stream($body, $hash, $source): bool
    {
        rewind($source);
        while (!feof($source)) {
            $chunk = fread($source, self::BATCH_STREAM_BUFFER_BYTES);
            if ($chunk === false) {
                return false;
            }
            if ($chunk === "") {
                break;
            }
            if (!$this->write_batch_bytes($body, $hash, $chunk)) {
                return false;
            }
        }
        return true;
    }

    private function auth_headers_for_hash(string $content_hash): array
    {
        $client = $this->hmac_client;
        if ($client === null) {
            return [];
        }
        $nonce = $client->generate_nonce();
        $timestamp = $client->get_timestamp();
        return [
            "X-Auth-Signature" => $client->compute_signature($nonce, $timestamp, $content_hash),
            "X-Auth-Nonce" => $nonce,
            "X-Auth-Timestamp" => $timestamp,
            "X-Auth-Content-Hash" => $content_hash,
        ];
    }

    private function hash_stream($stream): string
    {
        rewind($stream);
        $context = hash_init("sha256");
        while (!feof($stream)) {
            $chunk = fread($stream, self::BATCH_STREAM_BUFFER_BYTES);
            if ($chunk === false || $chunk === "") {
                break;
            }
            hash_update($context, $chunk);
        }
        rewind($stream);
        return hash_final($context);
    }

    private function stream_size($stream): int
    {
        $stat = fstat($stream);
        return is_array($stat) && isset($stat["size"]) ? (int) $stat["size"] : 0;
    }

    /**
     * Read exactly $bytes from the source, or null when the file ends first.
     */
    private function read_exactly($source, int $bytes): ?string
    {
        $data = "";
        while (strlen($data) < $bytes) {
            $piece = fread($source, $bytes - strlen($data));
            if ($piece === false || $piece === "") {
                return null;
            }
            $data .= $piece;
        }
        return $data;
    }

    private function backoff(int $attempt): void
    {
        $delay = min(self::MAX_BACKOFF_USEC, self::RETRY_BACKOFF_USEC * (2 ** max(0, $attempt - 1)));
        call_user_func($this->sleeper, (int) $delay);
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,committed_bytes:int}
     */
    private function failed(string $reason, ?string $detail = null, int $committed_bytes = 0): array
    {
        return [
            "status" => "failed",
            "reason" => $reason,
            "detail" => $detail,
            "committed_bytes" => $committed_bytes,
        ];
    }

    /**
     * Default transport: one curl request, honoring the proxy and CA bundle
     * environment helpers when the importer runtime is loaded.
     *
     * @return array{http_code:int,body:string,error:?string}
     */
    private function curl_transport(string $method, string $url, array $headers, $body, int $timeout): array
    {
        $ch = curl_init($url);
        if (function_exists("reprint_apply_curl_proxy_from_env")) {
            reprint_apply_curl_proxy_from_env($ch);
        }
        if (function_exists("reprint_apply_curl_ca_bundle")) {
            reprint_apply_curl_ca_bundle($ch);
        }

        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($method === "POST") {
            if (is_resource($body)) {
                rewind($body);
                $options[CURLOPT_UPLOAD] = true;
                $options[CURLOPT_INFILESIZE] = $this->stream_size($body);
                $options[CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($body): string {
                    $chunk = fread($body, $length);
                    return $chunk === false ? "" : $chunk;
                };
            } else {
                $options[CURLOPT_POSTFIELDS] = (string) $body;
            }
        }

        curl_setopt_array($ch, $options);

        $response_body = curl_exec($ch);
        if ($response_body === false) {
            $error = curl_error($ch) ?: ("curl errno " . curl_errno($ch));
            curl_close($ch);
            return ["http_code" => 0, "body" => "", "error" => $error];
        }
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ["http_code" => $http_code, "body" => (string) $response_body, "error" => null];
    }
}
