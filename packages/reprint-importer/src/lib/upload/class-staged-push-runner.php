<?php
/**
 * Batch orchestration for staged chunk uploads: walks a transfer plan and
 * drives StagedUploadClient once per artifact, resumably.
 *
 * The runner adds the two things a multi-artifact push needs on top of the
 * single-artifact client:
 *
 * Resume without re-asking the target. The client already resumes one
 * artifact from the store's committed offset, but a resumed 50k-file push
 * must not spend two requests per finished artifact re-discovering that it
 * is finished. The runner appends one line per verified artifact to
 * .push-verified.jsonl and skips cache hits (same id, same size) without
 * any request — trusted the way pull trusts the files already in its
 * fs-root. A torn or missing cache line is never trusted: the artifact goes
 * back through the client, whose status/finalize short-circuit re-confirms
 * it against the store in two cheap requests. Skips only avoid traffic;
 * the store stays the truth.
 *
 * Failure routing. A reason that is scoped to one artifact — its source
 * file vanished or shrank, its plan size disagrees with what the store
 * verified — is recorded and the batch continues; rerunning retries only
 * the failures. Everything else (auth, transport, chunk-size exhaustion,
 * a busy or erroring store, and any reason this list has never seen) would
 * fail every following artifact the same way, so the batch aborts with the
 * reason, and a rerun continues from the cache. Unknown reasons abort
 * rather than continue so a protocol change cannot churn a huge plan
 * through per-artifact failures.
 *
 * State lives in two files under the caller's state dir, pull-style:
 * .push-verified.jsonl is the append-only done cache, and .push-state.json
 * (rename-atomic) carries the chunk sizer's learned limits plus the last
 * run's counters. The sizer state is written after every artifact and on
 * abort, so a rerun starts at the limits the wire already taught us —
 * read_state() restores it before the sizer and client are constructed.
 * Killing the runner anywhere loses at most one cache line and one sizer
 * update, both re-derived cheaply.
 */
class StagedPushRunner
{
    /** Failure reasons scoped to one artifact; the batch continues past them. */
    private const ARTIFACT_SCOPED_REASONS = [
        "source_unreadable",
        "source_short",
        "size_mismatch",
        "invalid_artifact_id",
        "invalid_offset",
        "invalid_total",
        "invalid_artifact_entry",
        // A source rewritten mid-push fails its artifact; the next run
        // re-plans with the current mtime and pushes the fresh content.
        "source_changed",
    ];

    /** Per-file allowance for a batch frame's JSON header line. */
    private const BATCH_FRAME_OVERHEAD = 256;

    private string $state_path;

    private string $verified_path;

    private StagedUploadClient $client;

    private UploadChunkSizer $sizer;

    /** @var callable|null */
    private $on_progress;

    /**
     * @param array $options
     *   - state_dir (string, required): holds .push-state.json and
     *     .push-verified.jsonl; created if missing.
     *   - client (StagedUploadClient, required): the per-artifact uploader.
     *   - sizer (UploadChunkSizer, required): the same instance the client
     *     was built with — the runner persists its learned state.
     *   - on_progress (?callable): fn(array $progress) with files_done,
     *     files_total, artifact_id, committed_bytes, total_bytes; called on
     *     every chunk advance and once per finished artifact.
     */
    public function __construct(array $options)
    {
        $state_dir = $options["state_dir"] ?? null;
        if (!is_string($state_dir) || $state_dir === "") {
            throw new InvalidArgumentException("StagedPushRunner requires a state_dir option.");
        }
        if (!($options["client"] ?? null) instanceof StagedUploadClient) {
            throw new InvalidArgumentException("StagedPushRunner requires a client option.");
        }
        if (!($options["sizer"] ?? null) instanceof UploadChunkSizer) {
            throw new InvalidArgumentException("StagedPushRunner requires a sizer option.");
        }

        $base = rtrim($state_dir, "/");
        $this->state_path = $base . "/.push-state.json";
        $this->verified_path = $base . "/.push-verified.jsonl";
        $this->client = $options["client"];
        $this->sizer = $options["sizer"];
        $this->on_progress = $options["on_progress"] ?? null;
    }

    /**
     * Restore a previous run's persisted state, for constructing the sizer
     * before the client and runner exist.
     *
     * @return array{sizer:array,files_total:int,files_done:int}
     */
    public static function read_state(string $state_dir): array
    {
        $defaults = [
            "sizer" => [],
            "files_total" => 0,
            "files_done" => 0,
        ];
        $raw = @file_get_contents(rtrim($state_dir, "/") . "/.push-state.json");
        if ($raw === false) {
            return $defaults;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            return $defaults;
        }
        return [
            "sizer" => is_array($state["sizer"] ?? null) ? $state["sizer"] : [],
            "files_total" => max(0, (int) ($state["files_total"] ?? 0)),
            "files_done" => max(0, (int) ($state["files_done"] ?? 0)),
        ];
    }

    /**
     * Bytes that a resumed run still needs to stage.
     *
     * Invalid or unreadable plan entries do not count here because push()
     * records them as artifact-scoped failures before any upload attempt.
     */
    public function pending_bytes(array $artifacts): int
    {
        $verified_cache = $this->read_verified_cache();
        $bytes = 0;
        foreach ($artifacts as $entry) {
            $artifact_id = $entry["artifact_id"] ?? null;
            $source_path = $entry["source_path"] ?? null;
            if (!is_string($artifact_id) || $artifact_id === "" || !is_string($source_path) || $source_path === "") {
                continue;
            }

            $total_bytes = $entry["total_bytes"] ?? null;
            if ($total_bytes === null) {
                $size = @filesize($source_path);
                if ($size === false) {
                    continue;
                }
                $total_bytes = $size;
            }
            $total_bytes = (int) $total_bytes;
            $mtime = isset($entry["mtime"]) ? (int) $entry["mtime"] : null;
            if ($this->cache_hit($verified_cache, $artifact_id, $total_bytes, $mtime)) {
                continue;
            }
            $bytes += $total_bytes;
        }
        return $bytes;
    }

    /**
     * Upload every artifact in the plan that is not already verified.
     *
     * @param array $artifacts Each entry: ['artifact_id' => string,
     *   'source_path' => string, 'total_bytes' => ?int (defaults to the
     *   file size)].
     * @return array{status:string,files_total:int,files_done:int,failed:array,abort_reason:?string,abort_detail:?string}
     *   status "completed" when the plan was walked to the end (failed may
     *   still list artifact-scoped failures), "aborted" when a
     *   transfer-scoped failure stopped the walk.
     */
    public function push(array $artifacts): array
    {
        if (!$this->ensure_state_dir()) {
            return $this->aborted(0, 0, [], "state_dir_unwritable", dirname($this->state_path));
        }

        $files_total = count($artifacts);
        $verified_cache = $this->read_verified_cache();
        $files_done = 0;
        $failed = [];

        // Collect the work first; batching decisions need the whole list.
        $queue = [];
        foreach ($artifacts as $entry) {
            $artifact_id = $entry["artifact_id"] ?? null;
            $source_path = $entry["source_path"] ?? null;
            if (!is_string($artifact_id) || $artifact_id === "" || !is_string($source_path) || $source_path === "") {
                $failed[] = [
                    "artifact_id" => is_string($artifact_id) ? $artifact_id : "",
                    "reason" => "invalid_artifact_entry",
                    "detail" => null,
                ];
                continue;
            }

            $total_bytes = $entry["total_bytes"] ?? null;
            if ($total_bytes === null) {
                $size = @filesize($source_path);
                if ($size === false) {
                    $failed[] = [
                        "artifact_id" => $artifact_id,
                        "reason" => "source_unreadable",
                        "detail" => $source_path,
                    ];
                    continue;
                }
                $total_bytes = $size;
            }
            $total_bytes = (int) $total_bytes;

            // A cache hit at the planned size and mtime is done — no
            // request. The mtime matters because every other check in this
            // pipeline is a byte count: a same-size edit is invisible to
            // the store's verification, so the cache must not vouch for it.
            $mtime = isset($entry["mtime"]) ? (int) $entry["mtime"] : null;
            $cached = $verified_cache[$artifact_id] ?? null;
            if ($this->cache_hit($verified_cache, $artifact_id, $total_bytes, $mtime)) {
                $files_done++;
                $this->report_progress($files_done, $files_total, $artifact_id, $total_bytes, $total_bytes);
                continue;
            }
            if ($cached !== null) {
                // The source changed since this artifact verified. The
                // store's size checks cannot see a same-size edit, so the
                // stale server copy must go before the fresh bytes come —
                // its verified short-circuit would otherwise vouch for it.
                $this->client->discard($artifact_id);
            }

            $queue[] = [
                "artifact_id" => $artifact_id,
                "source_path" => $source_path,
                "total_bytes" => $total_bytes,
                "mtime" => $mtime,
            ];
        }

        // Process the queue: files that fit the sizer's request budget
        // travel together — one HTTP conversation per batch, pull's
        // multipart discipline in the push direction. Files bigger than
        // the budget keep the resumable per-chunk path. A 413 shrinks the
        // budget (monotonically, so this terminates) and the affected
        // batch repartitions.
        while ($queue !== []) {
            $budget = max(1, $this->sizer->chunk_bytes());

            if ($queue[0]["total_bytes"] + self::BATCH_FRAME_OVERHEAD > $budget) {
                $entry = array_shift($queue);
                $result = $this->client->upload_artifact(
                    $entry["artifact_id"],
                    $entry["source_path"],
                    $entry["total_bytes"],
                    function (int $committed, int $total) use ($files_done, $files_total, $entry): void {
                        $this->report_progress($files_done, $files_total, $entry["artifact_id"], $committed, $total);
                    },
                    $entry["mtime"]
                );

                if ($result["status"] === "verified") {
                    $files_done++;
                    $this->append_verified($entry["artifact_id"], $entry["total_bytes"], $entry["mtime"]);
                    $this->write_state($files_total, $files_done);
                    $this->report_progress($files_done, $files_total, $entry["artifact_id"], $entry["total_bytes"], $entry["total_bytes"]);
                    continue;
                }
                $reason = is_string($result["reason"]) ? $result["reason"] : "unexpected_response";
                if (in_array($reason, self::ARTIFACT_SCOPED_REASONS, true)) {
                    $failed[] = [
                        "artifact_id" => $entry["artifact_id"],
                        "reason" => $reason,
                        "detail" => $result["detail"],
                    ];
                    $this->write_state($files_total, $files_done);
                    continue;
                }
                $this->write_state($files_total, $files_done);
                return $this->aborted($files_total, $files_done, $failed, $reason, $result["detail"]);
            }

            $batch = [];
            $batch_bytes = 0;
            while (
                $queue !== []
                && $queue[0]["total_bytes"] + self::BATCH_FRAME_OVERHEAD <= $budget
                && $batch_bytes + $queue[0]["total_bytes"] + self::BATCH_FRAME_OVERHEAD <= $budget
            ) {
                $entry = array_shift($queue);
                $batch[$entry["artifact_id"]] = $entry;
                $batch_bytes += $entry["total_bytes"] + self::BATCH_FRAME_OVERHEAD;
            }

            $batch_result = $this->client->upload_batch(array_values($batch));

            if ($batch_result["status"] === "failed") {
                if ($batch_result["reason"] === "batch_too_large") {
                    // The sizer already shrank; repartition these entries.
                    foreach ($batch as $entry) {
                        $queue[] = $entry;
                    }
                    continue;
                }
                $this->write_state($files_total, $files_done);
                return $this->aborted($files_total, $files_done, $failed, $batch_result["reason"], $batch_result["detail"]);
            }

            foreach ($batch_result["per_file"] as $artifact_id => $outcome) {
                $entry = $batch[$artifact_id] ?? null;
                if ($entry === null) {
                    continue;
                }
                if ($outcome["status"] === "verified") {
                    $files_done++;
                    $this->append_verified($artifact_id, $entry["total_bytes"], $entry["mtime"]);
                    $this->report_progress($files_done, $files_total, $artifact_id, $entry["total_bytes"], $entry["total_bytes"]);
                    continue;
                }
                if ($outcome["status"] === "not_attempted") {
                    // The server stopped at an earlier frame; retry these.
                    $queue[] = $entry;
                    continue;
                }
                $reason = is_string($outcome["reason"]) ? $outcome["reason"] : "unexpected_response";
                if (in_array($reason, self::ARTIFACT_SCOPED_REASONS, true)) {
                    $failed[] = [
                        "artifact_id" => $artifact_id,
                        "reason" => $reason,
                        "detail" => null,
                    ];
                    continue;
                }
                $this->write_state($files_total, $files_done);
                return $this->aborted($files_total, $files_done, $failed, $reason, $artifact_id);
            }
            $this->write_state($files_total, $files_done);
        }

        $this->write_state($files_total, $files_done);
        return [
            "status" => "completed",
            "files_total" => $files_total,
            "files_done" => $files_done,
            "failed" => $failed,
            "abort_reason" => null,
            "abort_detail" => null,
        ];
    }

    /**
     * Ids the done cache remembers that the current plan no longer lists —
     * files previous pushes shipped and the local tree has since removed.
     * These become the transfer's deletions, mirroring pull's delta drain.
     */
    public function cached_ids_not_in(array $artifact_ids): array
    {
        $planned = array_fill_keys($artifact_ids, true);
        $stale = [];
        foreach (array_keys($this->read_verified_cache()) as $cached_id) {
            if (!isset($planned[$cached_id])) {
                $stale[] = $cached_id;
            }
        }
        return $stale;
    }

    /** Drops ids from the done cache once their deletion applied. */
    public function forget_cached(array $artifact_ids): void
    {
        if ($artifact_ids === []) {
            return;
        }
        $drop = array_fill_keys($artifact_ids, true);
        $lines = "";
        foreach ($this->read_verified_cache() as $artifact_id => $record) {
            if (isset($drop[$artifact_id])) {
                continue;
            }
            $lines .= json_encode([
                "artifact_id" => $artifact_id,
                "size" => $record["size"],
                "mtime" => $record["mtime"],
            ]) . "\n";
        }
        $tmp_path = $this->verified_path . ".tmp";
        if (@file_put_contents($tmp_path, $lines) === strlen($lines)) {
            @rename($tmp_path, $this->verified_path);
        }
        @unlink($tmp_path);
    }

    /**
     * The done cache keys on size and, when the plan supplies one, mtime.
     * A same-size edit is invisible to the store's verification, so any
     * mtime movement must invalidate the cache entry before upload/apply.
     */
    private function cache_hit(array $verified_cache, string $artifact_id, int $total_bytes, ?int $mtime): bool
    {
        $cached = $verified_cache[$artifact_id] ?? null;
        return $cached !== null
            && $cached["size"] === $total_bytes
            && ($mtime === null || $cached["mtime"] === null || $cached["mtime"] === $mtime);
    }

    private function report_progress(int $files_done, int $files_total, string $artifact_id, int $committed, int $total): void
    {
        if ($this->on_progress === null) {
            return;
        }
        call_user_func($this->on_progress, [
            "files_done" => $files_done,
            "files_total" => $files_total,
            "artifact_id" => $artifact_id,
            "committed_bytes" => $committed,
            "total_bytes" => $total,
        ]);
    }

    /**
     * @return array<string,array{size:int,mtime:?int}> keyed by artifact id.
     */
    private function read_verified_cache(): array
    {
        $raw = @file_get_contents($this->verified_path);
        if ($raw === false || $raw === "") {
            return [];
        }
        $cache = [];
        foreach (explode("\n", $raw) as $line) {
            $record = json_decode($line, true);
            if (
                !is_array($record)
                || !is_string($record["artifact_id"] ?? null)
                || !is_int($record["size"] ?? null)
                || $record["size"] < 0
            ) {
                // A torn line from a kill mid-append; the artifact just
                // takes the client's short-circuit path instead.
                continue;
            }
            $cache[$record["artifact_id"]] = [
                "size" => $record["size"],
                "mtime" => is_int($record["mtime"] ?? null) ? $record["mtime"] : null,
            ];
        }
        return $cache;
    }

    private function append_verified(string $artifact_id, int $size, ?int $mtime): void
    {
        // The leading newline seals any torn tail from a killed writer onto
        // its own (skipped) line, as the staged store does for its records.
        $line = "\n" . json_encode([
            "artifact_id" => $artifact_id,
            "size" => $size,
            "mtime" => $mtime,
        ]) . "\n";
        @file_put_contents($this->verified_path, $line, FILE_APPEND);
    }

    private function write_state(int $files_total, int $files_done): void
    {
        // Write-then-rename, like every cursor file in this codebase: a
        // kill leaves the previous state, never a torn one.
        $tmp_path = $this->state_path . ".tmp";
        $json = json_encode([
            "sizer" => $this->sizer->get_state(),
            "files_total" => $files_total,
            "files_done" => $files_done,
        ]);
        if (@file_put_contents($tmp_path, $json) !== strlen($json) || !@rename($tmp_path, $this->state_path)) {
            // Best effort: stale sizer state costs a relearn, nothing more.
            @unlink($tmp_path);
        }
    }

    private function ensure_state_dir(): bool
    {
        $dir = dirname($this->state_path);
        return is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /**
     * @return array{status:string,files_total:int,files_done:int,failed:array,abort_reason:?string,abort_detail:?string}
     */
    private function aborted(int $files_total, int $files_done, array $failed, string $reason, ?string $detail): array
    {
        return [
            "status" => "aborted",
            "files_total" => $files_total,
            "files_done" => $files_done,
            "failed" => $failed,
            "abort_reason" => $reason,
            "abort_detail" => $detail,
        ];
    }
}
