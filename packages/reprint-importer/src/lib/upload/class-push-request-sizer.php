<?php
/**
 * Adaptive sizing for push request bodies.
 *
 * Push moves bytes from an outbound-only local site to a remote WordPress
 * site through PHP request bodies. The push stream keeps many files inside
 * one authenticated request, but hosts and proxies cap how much a single
 * request may carry (post_max_size, client_max_body_size, and similar), so
 * each request body needs a bounded size. After a size rejection, a later
 * sender run can continue from the receiver cursor with smaller requests.
 *
 * This class owns the request-body-size decision:
 *
 * - Remote-reported request limits (post_max_size, upload_max_filesize, and
 *   similar) establish the session ceiling, minus a safety margin for host
 *   quirks and the frame that may straddle the budget edge. A reverse proxy
 *   or CDN may still reject earlier, so the ceiling is an upper bound, not a
 *   guarantee.
 * - Without a useful reported limit, request bodies start at a conservative
 *   32 MiB.
 * - A hard cap (default 1 GiB) bounds every request even when the host
 *   reports a larger limit. The body streams through chunk by chunk, so this
 *   is not a memory bound — it only keeps single requests from growing
 *   without end. The in-memory unit is the much smaller chunk the client
 *   reads per frame; that is unrelated to this class.
 * - Accepted requests double the size toward the ceiling.
 * - A rejection known to be size-related — HTTP 413 or a structured
 *   `request_too_large` response — caps the session ceiling permanently, so
 *   growth never retries a size the server already refused. Timeouts,
 *   connection resets, and empty responses leave sizing unchanged because
 *   they do not establish that request size caused the failure.
 * - When even the floor size is rejected, decisions report "give_up" so the
 *   caller can stop with a clear error.
 *
 * This is a sibling of AdaptiveTuner, not an extension of it: AdaptiveTuner
 * tunes how much work a pull request asks for so the exporter fits its time
 * budget, driven by server-reported timing and a throughput average. Push
 * requests have no timing signal — the only feedback is accept/reject against
 * hard size limits — so the two keep separate state and logic.
 */
class PushRequestSizer
{
    private array $config;

    /** @var int Current request body size in bytes. */
    private int $request_body_bytes;

    /** @var int|null Session ceiling in bytes, null while no limit is known. */
    private ?int $ceiling_bytes;

    /**
     * @param array $config Sizing configuration (merged with defaults, unknown keys ignored).
     * @param array $state  Persisted sizing state from get_state().
     */
    public function __construct(array $config = [], array $state = [])
    {
        $defaults = [
            // Smallest request body worth attempting; a rejection at this
            // size ends the session instead of shrinking further.
            "floor_bytes" => 1024 * 1024,
            // Conservative bootstrap size used until limits teach us more.
            "start_bytes" => 32 * 1024 * 1024,
            // Hard cap: no request body may exceed this, even when the host
            // reports a larger limit. Bodies stream, so this is not a memory
            // bound — it only keeps single requests from growing without end.
            "max_bytes" => 1024 * 1024 * 1024,
            // Fraction of a reported limit usable for the request body; the
            // rest absorbs headers, frame metadata, and host quirks.
            "limit_safety_ratio" => 0.9,
        ];

        $config = array_merge($defaults, array_intersect_key($config, $defaults));
        $config["floor_bytes"] = max(1, (int) $config["floor_bytes"]);
        $config["max_bytes"] = max($config["floor_bytes"], (int) $config["max_bytes"]);
        $config["start_bytes"] = max(
            $config["floor_bytes"],
            min($config["max_bytes"], (int) $config["start_bytes"]),
        );
        $config["limit_safety_ratio"] = min(1.0, max(0.1, (float) $config["limit_safety_ratio"]));
        $this->config = $config;

        // Persisted state may come from an older config or version; clamp it
        // through the current floor, ceiling, and hard cap before resuming.
        $ceiling = $state["ceiling_bytes"] ?? null;
        $this->ceiling_bytes = is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : null;
        $state_request_body_bytes = (int) ($state["request_body_bytes"] ?? $config["start_bytes"]);
        $effective_ceiling = min($this->ceiling_bytes ?? PHP_INT_MAX, $config["max_bytes"]);
        $this->request_body_bytes = max(
            $config["floor_bytes"],
            min(max($effective_ceiling, $config["floor_bytes"]), $state_request_body_bytes),
        );
    }

    /**
     * The body size the next push stream request should stay within, in bytes.
     */
    public function request_body_bytes(): int
    {
        return $this->request_body_bytes;
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
     * @return array {
     *     Decision summary.
     *
     *     @type string $action             Sizing action.
     *     @type int    $request_body_bytes Current decoded entity-body budget.
     * }
     * @phpstan-return array{action:string,request_body_bytes:int}
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
            return ["action" => "steady", "request_body_bytes" => $this->request_body_bytes];
        }

        // Limits only lower the ceiling; a later, higher preflight value
        // cannot erase a smaller limit learned earlier in the session.
        return $this->lower_ceiling((int) ($smallest * $this->config["limit_safety_ratio"]));
    }

    /**
     * Record an accepted request and grow toward the ceiling.
     *
     * @return array {
     *     Decision summary.
     *
     *     @type string $action             Sizing action.
     *     @type int    $request_body_bytes Current decoded entity-body budget.
     * }
     * @phpstan-return array{action:string,request_body_bytes:int}
     */
    public function record_success(): array
    {
        $grown = min($this->request_body_bytes * 2, min($this->ceiling_bytes ?? PHP_INT_MAX, $this->config["max_bytes"]));
        if ($grown <= $this->request_body_bytes) {
            return ["action" => "steady", "request_body_bytes" => $this->request_body_bytes];
        }

        $this->request_body_bytes = $grown;
        return ["action" => "grow", "request_body_bytes" => $this->request_body_bytes];
    }

    /**
     * Record a rejection that is known to be size-related: HTTP 413 or a
     * structured request_too_large response.
     *
     * The failed size caps the session ceiling so growth cannot retry it.
     * When the server reported its actual limit — the push_upload endpoint's
     * 413 carries post_max_bytes, the decoded request-body limit — the ceiling
     * drops below that limit directly instead of probing downward.
     *
     * @param int|null $reported_max_bytes Server-reported request limit, if any.
     * @return array {
     *     Decision summary.
     *
     *     @type string $action             Sizing action.
     *     @type int    $request_body_bytes Current decoded entity-body budget.
     * }
     * @phpstan-return array{action:string,request_body_bytes:int}
     */
    public function record_too_large(?int $reported_max_bytes = null): array
    {
        if ($reported_max_bytes !== null && $reported_max_bytes > 0) {
            $decision = $this->lower_ceiling((int) ($reported_max_bytes * $this->config["limit_safety_ratio"]));
            if ($decision["action"] !== "steady") {
                return $decision;
            }
            // The reported limit did not reduce the size, yet this size was
            // rejected — a proxy in front of PHP can enforce a lower bound
            // than the one PHP reports. Halve so retries make progress.
        }

        if ($this->request_body_bytes <= $this->config["floor_bytes"]) {
            return ["action" => "give_up", "request_body_bytes" => $this->request_body_bytes];
        }

        return $this->lower_ceiling(max($this->config["floor_bytes"], intdiv($this->request_body_bytes, 2)));
    }

    /**
     * @return array {
     *     Serializable request-sizing state.
     *
     *     @type int      $request_body_bytes       Current decoded entity-body
     *                                             budget.
     *     @type int|null $ceiling_bytes            Learned session ceiling, or
     *                                             null while unknown.
     * }
     * @phpstan-return array{request_body_bytes:int,ceiling_bytes:?int}
     */
    public function get_state(): array
    {
        return [
            "request_body_bytes" => $this->request_body_bytes,
            "ceiling_bytes" => $this->ceiling_bytes,
        ];
    }

    /**
     * Lowers the learned per-session ceiling and shrinks the current size
     * when it no longer fits.
     *
     * @return array {
     *     Decision summary.
     *
     *     @type string $action             Sizing action.
     *     @type int    $request_body_bytes Current decoded entity-body budget.
     * }
     * @phpstan-return array{action:string,request_body_bytes:int}
     */
    private function lower_ceiling(int $ceiling): array
    {
        if ($this->ceiling_bytes === null || $ceiling < $this->ceiling_bytes) {
            $this->ceiling_bytes = $ceiling;
        }

        if ($this->ceiling_bytes < $this->config["floor_bytes"]) {
            $this->request_body_bytes = $this->config["floor_bytes"];
            return ["action" => "give_up", "request_body_bytes" => $this->request_body_bytes];
        }

        if ($this->request_body_bytes <= $this->ceiling_bytes) {
            return ["action" => "steady", "request_body_bytes" => $this->request_body_bytes];
        }

        $this->request_body_bytes = $this->ceiling_bytes;
        return ["action" => "shrink", "request_body_bytes" => $this->request_body_bytes];
    }

}
