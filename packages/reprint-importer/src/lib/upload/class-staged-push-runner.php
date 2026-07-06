<?php
/**
 * Batch orchestration for staged chunk uploads: streams the upload list the
 * diff produced and drives StagedUploadClient once per artifact, resumably.
 *
 * The upload list is the push counterpart of pull's download list — one line
 * per file the diff decided to send, in path order. The runner adds the two
 * things a multi-artifact push needs on top of the single-artifact client:
 *
 * Batching. Files that fit the sizer's request budget travel together — one
 * HTTP conversation per batch, pull's multipart discipline in the push
 * direction. Files bigger than the budget keep the resumable per-chunk path.
 * A 413 shrinks the budget (monotonically, so this terminates) and the
 * affected files repartition.
 *
 * Failure routing. A reason scoped to one artifact — its source vanished or
 * shrank, its size disagrees with the store — is recorded and the run
 * continues; rerunning retries only the failures. Everything else (auth,
 * transport, chunk-size exhaustion, a busy or erroring store, or any reason
 * this list has never seen) would fail every following artifact the same way,
 * so the run aborts with the reason. Unknown reasons abort rather than
 * continue so a protocol change cannot churn a huge list through per-artifact
 * failures.
 *
 * The runner keeps no done cache. Resume trusts the store the way pull trusts
 * the files already in its fs-root: a file finished in a prior run is still
 * verified in the store, so the client's status/finalize short-circuit
 * re-confirms it in two cheap requests without re-sending bytes; deletions
 * and ownership come from the diff, not from a cache. The only state it
 * persists is .push-state.json (rename-atomic) — the chunk sizer's learned
 * limits plus the last run's counters — written after every artifact so a
 * rerun starts at the limits the wire already taught us.
 */
class StagedPushRunner
{
    /** Failure reasons scoped to one artifact; the run continues past them. */
    private const ARTIFACT_SCOPED_REASONS = [
        "source_unreadable",
        "source_short",
        "size_mismatch",
        "invalid_artifact_id",
        "invalid_offset",
        "invalid_total",
        "invalid_artifact_entry",
        // A source rewritten mid-push fails its artifact; the next run
        // re-diffs with the current mtime and pushes the fresh content.
        "source_changed",
    ];

    /** Per-file allowance for a batch frame's JSON header line. */
    private const BATCH_FRAME_OVERHEAD = 256;

    private string $state_path;

    /** Prefix a store artifact id resolves under to its local source file: the
     * fs-root in relative mode, "" in absolute mode (ids are already rooted). */
    private string $source_root;

    private StagedUploadClient $client;

    private UploadChunkSizer $sizer;

    /** @var callable|null */
    private $on_progress;

    /**
     * @param array $options
     *   - state_dir (string, required): holds .push-state.json; created if missing.
     *   - source_root (string, required): prefix the upload-list ids resolve
     *     their local source files under ("" in absolute mode).
     *   - client (StagedUploadClient, required): the per-artifact uploader.
     *   - sizer (UploadChunkSizer, required): the same instance the client
     *     was built with — the runner persists its learned state.
     *   - on_progress (?callable): fn(array $progress) with files_done,
     *     files_total, artifact_id, committed_bytes, total_bytes.
     */
    public function __construct(array $options)
    {
        $state_dir = $options["state_dir"] ?? null;
        if (!is_string($state_dir) || $state_dir === "") {
            throw new InvalidArgumentException("StagedPushRunner requires a state_dir option.");
        }
        $source_root = $options["source_root"] ?? null;
        if (!is_string($source_root)) {
            throw new InvalidArgumentException("StagedPushRunner requires a source_root option.");
        }
        if (!($options["client"] ?? null) instanceof StagedUploadClient) {
            throw new InvalidArgumentException("StagedPushRunner requires a client option.");
        }
        if (!($options["sizer"] ?? null) instanceof UploadChunkSizer) {
            throw new InvalidArgumentException("StagedPushRunner requires a sizer option.");
        }

        $this->state_path = rtrim($state_dir, "/") . "/.push-state.json";
        $this->source_root = rtrim($source_root, "/");
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
     * Upload every file the upload list names, streaming it so memory stays
     * bounded regardless of transfer size.
     *
     * @param string $upload_list_file JSONL {path (base64), size, ctime}.
     * @return array{status:string,files_total:int,files_done:int,failed:array,abort_reason:?string,abort_detail:?string}
     */
    public function push(string $upload_list_file): array
    {
        if (!$this->ensure_state_dir()) {
            return $this->aborted(0, 0, [], "state_dir_unwritable", dirname($this->state_path));
        }

        $files_total = $this->count_upload_entries($upload_list_file);
        $handle = @fopen($upload_list_file, "r");
        if ($handle === false) {
            // No upload list means the diff found nothing to send.
            $this->write_state($files_total, 0);
            return $this->completed($files_total, 0, []);
        }

        $files_done = 0;
        $failed = [];
        // Entries requeued by a 413 repartition or a partial batch buffer
        // here; the main pull takes from this before reading new lines.
        $pending = [];
        $eof = false;

        try {
            while (true) {
                $entry = $this->next_entry($handle, $pending, $eof);
                if ($entry === null) {
                    break;
                }

                $budget = max(1, $this->sizer->chunk_bytes());

                if ($entry["total_bytes"] + self::BATCH_FRAME_OVERHEAD > $budget) {
                    $abort = $this->upload_one($entry, $files_done, $files_total, $failed);
                    if ($abort !== null) {
                        return $abort;
                    }
                    continue;
                }

                // Pack files up to the budget into one batch conversation.
                $batch = [$entry["artifact_id"] => $entry];
                $batch_bytes = $entry["total_bytes"] + self::BATCH_FRAME_OVERHEAD;
                while (true) {
                    $peek = $this->next_entry($handle, $pending, $eof);
                    if ($peek === null) {
                        break;
                    }
                    if ($batch_bytes + $peek["total_bytes"] + self::BATCH_FRAME_OVERHEAD > $budget) {
                        array_unshift($pending, $peek);
                        break;
                    }
                    $batch[$peek["artifact_id"]] = $peek;
                    $batch_bytes += $peek["total_bytes"] + self::BATCH_FRAME_OVERHEAD;
                }

                $abort = $this->upload_batch($batch, $pending, $files_done, $files_total, $failed);
                if ($abort !== null) {
                    return $abort;
                }
            }
        } finally {
            fclose($handle);
        }

        $this->write_state($files_total, $files_done);
        return $this->completed($files_total, $files_done, $failed);
    }

    /**
     * Drive one big file down the resumable per-chunk path.
     *
     * @return array|null An aborted result to return, or null to continue.
     */
    private function upload_one(array $entry, int &$files_done, int $files_total, array &$failed): ?array
    {
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
            $this->write_state($files_total, $files_done);
            $this->report_progress($files_done, $files_total, $entry["artifact_id"], $entry["total_bytes"], $entry["total_bytes"]);
            return null;
        }
        $reason = is_string($result["reason"]) ? $result["reason"] : "unexpected_response";
        if (in_array($reason, self::ARTIFACT_SCOPED_REASONS, true)) {
            $failed[] = [
                "artifact_id" => $entry["artifact_id"],
                "reason" => $reason,
                "detail" => $result["detail"],
            ];
            $this->write_state($files_total, $files_done);
            return null;
        }
        $this->write_state($files_total, $files_done);
        return $this->aborted($files_total, $files_done, $failed, $reason, $result["detail"]);
    }

    /**
     * Send one packed batch in a single conversation.
     *
     * @return array|null An aborted result to return, or null to continue.
     */
    private function upload_batch(array $batch, array &$pending, int &$files_done, int $files_total, array &$failed): ?array
    {
        $batch_result = $this->client->upload_batch(array_values($batch));

        if ($batch_result["status"] === "failed") {
            if ($batch_result["reason"] === "batch_too_large") {
                // The sizer already shrank; repartition these files.
                foreach ($batch as $entry) {
                    $pending[] = $entry;
                }
                return null;
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
                $this->report_progress($files_done, $files_total, $artifact_id, $entry["total_bytes"], $entry["total_bytes"]);
                continue;
            }
            if ($outcome["status"] === "not_attempted") {
                // The server stopped at an earlier frame; retry these.
                $pending[] = $entry;
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
        return null;
    }

    /**
     * Next artifact to process — requeued entries first, then the stream.
     *
     * @return array{artifact_id:string,source_path:string,total_bytes:int,mtime:?int}|null
     */
    private function next_entry($handle, array &$pending, bool &$eof): ?array
    {
        if ($pending !== []) {
            return array_shift($pending);
        }
        if ($eof) {
            return null;
        }
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            $encoded = isset($data["path"]) ? (string) $data["path"] : "";
            $index_path = base64_decode($encoded, true);
            if ($index_path === false || $index_path === "") {
                continue;
            }
            // The store artifact id is the index path without any leading
            // slash (a no-op in relative mode); the source file sits under
            // source_root (fs-root in relative mode, "" so ids are already
            // absolute in filesystem mode).
            $artifact_id = ltrim($index_path, "/");
            $size = isset($data["size"]) ? (int) $data["size"] : 0;
            $ctime = isset($data["ctime"]) ? (int) $data["ctime"] : null;
            return [
                "artifact_id" => $artifact_id,
                "source_path" => $this->source_root . "/" . $artifact_id,
                "total_bytes" => $size,
                "mtime" => $ctime,
            ];
        }
        $eof = true;
        return null;
    }

    private function count_upload_entries(string $upload_list_file): int
    {
        $handle = @fopen($upload_list_file, "r");
        if ($handle === false) {
            return 0;
        }
        $count = 0;
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            if (trim($line) !== "") {
                $count++;
            }
        }
        fclose($handle);
        return $count;
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

    private function write_state(int $files_total, int $files_done): void
    {
        // Write-then-rename, like every cursor file in this codebase: a kill
        // leaves the previous state, never a torn one.
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
    private function completed(int $files_total, int $files_done, array $failed): array
    {
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
