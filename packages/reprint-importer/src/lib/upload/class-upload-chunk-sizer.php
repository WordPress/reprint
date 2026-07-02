<?php
/**
 * Adaptive sizing for bounded local-to-remote upload chunks.
 *
 * Push moves bytes from an outbound-only local site to a remote WordPress
 * site through PHP request bodies, and most hosts buffer an inbound body
 * before userland code runs (nginx fastcgi_request_buffering, PHP multipart
 * handling). Reprint therefore never streams one large request; it uploads
 * bounded chunks that stay small enough for host buffering to be acceptable.
 *
 * This class owns the chunk-size decision:
 *
 * - Remote-reported request limits (post_max_size, upload_max_filesize, and
 *   similar) establish the session ceiling, minus a safety margin for headers
 *   and multipart framing. A reverse proxy or CDN may still reject earlier,
 *   so the ceiling is an upper bound, not a guarantee.
 * - Without a useful reported limit, uploads start at a conservative 32 MiB.
 * - A hard cap (default 1 GiB) bounds every chunk even when the host reports
 *   a larger limit.
 * - Accepted chunks double the size toward the ceiling.
 * - The two rejection kinds back off differently because they carry different
 *   evidence. A rejection known to be size-related — HTTP 413 or a structured
 *   "request too large" response — caps the session ceiling permanently, so
 *   growth never retries a size the server already refused. A transport
 *   failure (timeout, reset, empty response) may have nothing to do with
 *   size, and a push session can run for hours over thousands of chunks —
 *   permanently halving it over one network blip would double the request
 *   count for everything that follows. So a transport failure only halves the
 *   chunk and holds growth back for a few successes. The accepted cost: a
 *   size-related failure that never surfaces as a 413 is re-probed once per
 *   holdoff window instead of converging.
 * - When even the floor size is rejected, decisions report "give_up" so the
 *   caller can stop with a clear error instead of retrying forever.
 *
 * Callers should retry transient transport errors at the same size first and
 * record a failure only once a size is considered rejected. The decision
 * state survives get_state()/constructor round-trips so a resumed session
 * keeps the learned safe chunk size.
 *
 * This is a sibling of AdaptiveTuner, not an extension of it: AdaptiveTuner
 * tunes how much work a pull request asks for so the exporter fits its time
 * budget, driven by server-reported timing and a throughput average. Upload
 * chunks have no timing signal — the only feedback is accept/reject against
 * hard request-size limits — so the two keep separate state and logic.
 */
class UploadChunkSizer
{
    private array $config;

    /** @var int Current chunk size in bytes. */
    private int $chunk_bytes;

    /** @var int|null Session ceiling in bytes, null while no limit is known. */
    private ?int $ceiling_bytes;

    /** @var int Successes to absorb after a failure before growing again. */
    private int $growth_holdoff_remaining;

    /**
     * @param array $config Sizing configuration (merged with defaults, unknown keys ignored).
     * @param array $state  Persisted sizing state from get_state().
     */
    public function __construct(array $config = [], array $state = [])
    {
        $defaults = [
            // Smallest chunk worth attempting; a rejection at this size ends
            // the session instead of shrinking further.
            "floor_bytes" => 1024 * 1024,
            // Conservative bootstrap size used until limits teach us more.
            "start_bytes" => 32 * 1024 * 1024,
            // Hard cap: no chunk may exceed this, even when the host reports
            // a larger limit.
            "max_bytes" => 1024 * 1024 * 1024,
            // Fraction of a reported limit usable for the chunk payload; the
            // rest absorbs headers, multipart framing, and host quirks.
            "limit_safety_ratio" => 0.9,
            // Successful uploads required after a failure before growing again.
            "growth_holdoff_successes" => 3,
        ];

        $config = array_merge($defaults, array_intersect_key($config, $defaults));
        $config["floor_bytes"] = max(1, (int) $config["floor_bytes"]);
        $config["max_bytes"] = max($config["floor_bytes"], (int) $config["max_bytes"]);
        $config["start_bytes"] = max(
            $config["floor_bytes"],
            min($config["max_bytes"], (int) $config["start_bytes"]),
        );
        $config["limit_safety_ratio"] = min(1.0, max(0.1, (float) $config["limit_safety_ratio"]));
        $config["growth_holdoff_successes"] = max(0, (int) $config["growth_holdoff_successes"]);
        $this->config = $config;

        $ceiling = $state["ceiling_bytes"] ?? null;
        $this->ceiling_bytes = is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : null;
        $this->chunk_bytes = $this->clamp_chunk(
            (int) ($state["chunk_bytes"] ?? $config["start_bytes"]),
        );
        $this->growth_holdoff_remaining = max(0, (int) ($state["growth_holdoff_remaining"] ?? 0));
    }

    /**
     * The chunk size the next upload should use, in bytes.
     */
    public function chunk_bytes(): int
    {
        return $this->chunk_bytes;
    }

    /**
     * Lower the session ceiling from remote-reported request-size limits.
     *
     * Accepts raw limit values in bytes, e.g. the preflight response's
     * post_max_bytes and upload_max_bytes. Null and non-positive entries mean
     * "unknown" and are ignored. The smallest known limit, reduced by the
     * safety margin, becomes the ceiling.
     *
     * @param array $limit_bytes_list List of int|null limit candidates.
     * @return array{action:string,chunk_bytes:int} Decision summary.
     */
    public function apply_reported_limits(array $limit_bytes_list): array
    {
        $smallest = null;
        foreach ($limit_bytes_list as $limit) {
            if (!is_numeric($limit) || (int) $limit <= 0) {
                continue;
            }
            $limit = (int) $limit;
            if ($smallest === null || $limit < $smallest) {
                $smallest = $limit;
            }
        }

        if ($smallest === null) {
            return $this->decision("steady");
        }

        return $this->lower_ceiling((int) ($smallest * $this->config["limit_safety_ratio"]));
    }

    /**
     * Record an accepted chunk; grows toward the ceiling unless a recent
     * failure is still being held off.
     *
     * @return array{action:string,chunk_bytes:int} Decision summary.
     */
    public function record_success(): array
    {
        if ($this->growth_holdoff_remaining > 0) {
            $this->growth_holdoff_remaining--;
            return $this->decision("steady");
        }

        $grown = min($this->chunk_bytes * 2, $this->effective_ceiling());
        if ($grown <= $this->chunk_bytes) {
            return $this->decision("steady");
        }

        $this->chunk_bytes = $grown;
        return $this->decision("grow");
    }

    /**
     * Record a rejection that is known to be size-related: HTTP 413 or a
     * structured request_too_large response.
     *
     * The failed size caps the session ceiling so growth cannot retry it.
     * When the server reported its actual limit, the ceiling drops below that
     * limit directly instead of probing downward.
     *
     * @param int|null $reported_max_bytes Server-reported request limit, if any.
     * @return array{action:string,chunk_bytes:int} Decision summary.
     */
    public function record_too_large(?int $reported_max_bytes = null): array
    {
        $this->growth_holdoff_remaining = $this->config["growth_holdoff_successes"];

        if ($reported_max_bytes !== null && $reported_max_bytes > 0) {
            $decision = $this->lower_ceiling((int) ($reported_max_bytes * $this->config["limit_safety_ratio"]));
            if ($decision["action"] !== "steady") {
                return $decision;
            }
            // The reported limit did not reduce the chunk, yet this size was
            // rejected — a proxy in front of PHP can enforce a lower bound
            // than the one PHP reports. Halve so retries make progress.
        }

        if ($this->chunk_bytes <= $this->config["floor_bytes"]) {
            return $this->decision("give_up");
        }

        return $this->lower_ceiling(max($this->config["floor_bytes"], intdiv($this->chunk_bytes, 2)));
    }

    /**
     * Record a rejection that may or may not be size-related: timeout, empty
     * response, connection reset, or similar during an upload.
     *
     * Halves the chunk and holds growth back, but does not cap the ceiling —
     * after the holdoff the size may grow back, so one transient failure does
     * not permanently clamp the session.
     *
     * @return array{action:string,chunk_bytes:int} Decision summary.
     */
    public function record_transport_failure(): array
    {
        $this->growth_holdoff_remaining = $this->config["growth_holdoff_successes"];

        if ($this->chunk_bytes <= $this->config["floor_bytes"]) {
            return $this->decision("give_up");
        }

        $this->chunk_bytes = max($this->config["floor_bytes"], intdiv($this->chunk_bytes, 2));
        return $this->decision("shrink");
    }

    /**
     * @return array{chunk_bytes:int,ceiling_bytes:?int,growth_holdoff_remaining:int}
     */
    public function get_state(): array
    {
        return [
            "chunk_bytes" => $this->chunk_bytes,
            "ceiling_bytes" => $this->ceiling_bytes,
            "growth_holdoff_remaining" => $this->growth_holdoff_remaining,
        ];
    }

    /**
     * @return array{action:string,chunk_bytes:int}
     */
    private function lower_ceiling(int $ceiling): array
    {
        if ($this->ceiling_bytes === null || $ceiling < $this->ceiling_bytes) {
            $this->ceiling_bytes = $ceiling;
        }

        if ($this->ceiling_bytes < $this->config["floor_bytes"]) {
            $this->chunk_bytes = $this->config["floor_bytes"];
            return $this->decision("give_up");
        }

        if ($this->chunk_bytes <= $this->ceiling_bytes) {
            return $this->decision("steady");
        }

        $this->chunk_bytes = $this->ceiling_bytes;
        return $this->decision("shrink");
    }

    private function effective_ceiling(): int
    {
        return min($this->ceiling_bytes ?? PHP_INT_MAX, $this->config["max_bytes"]);
    }

    private function clamp_chunk(int $chunk_bytes): int
    {
        return max(
            $this->config["floor_bytes"],
            min(max($this->effective_ceiling(), $this->config["floor_bytes"]), $chunk_bytes),
        );
    }

    /**
     * @return array{action:string,chunk_bytes:int}
     */
    private function decision(string $action): array
    {
        return [
            "action" => $action,
            "chunk_bytes" => $this->chunk_bytes,
        ];
    }
}
