<?php

namespace Reprint\Importer\Tuning;

/**
 * Decides export request sizes and pacing from observed server behavior.
 *
 * The exporter runs until its server-side budgets expire, so the client-side
 * objective is to maximize useful work per request without pushing a host into
 * timeouts or response buffering. The tuner tracks endpoint throughput, applies
 * additive increase / multiplicative decrease, shrinks immediately on request
 * errors, and computes duty-cycle sleep between requests.
 */
class AdaptiveTuner
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $state;

    /**
     * Endpoint lookup table: maps endpoint name to its size state key,
     * throughput EMA state key, HTTP parameter name, AIMD increase config key,
     * min/max config keys, and work metric key.
     *
     * @var array<string, array<string, string>>
     */
    private const ENDPOINTS = [
        "file_fetch" => [
            "size_key" => "file_chunk_size",
            "ema_key" => "file_throughput_ema",
            "param" => "chunk_size",
            "increase_key" => "aimd_increase_file_bytes",
            "min_key" => "file_chunk_min",
            "max_key" => "file_chunk_max",
            "start_key" => "file_chunk_start",
            "work_metric" => "bytes_processed",
        ],
        "file_index" => [
            "size_key" => "index_batch_size",
            "ema_key" => "index_throughput_ema",
            "param" => "batch_size",
            "increase_key" => "aimd_increase_index_entries",
            "min_key" => "index_batch_min",
            "max_key" => "index_batch_max",
            "start_key" => "index_batch_start",
            "work_metric" => "entries_processed",
            "work_metric_alt" => "total_entries",
        ],
        "sql_chunk" => [
            "size_key" => "sql_fragments_per_batch",
            "ema_key" => "sql_throughput_ema",
            "param" => "fragments_per_batch",
            "increase_key" => "aimd_increase_sql_fragments",
            "min_key" => "sql_fragments_min",
            "max_key" => "sql_fragments_max",
            "start_key" => "sql_fragments_start",
            "work_metric" => "sql_bytes",
        ],
    ];

    /**
     * @param array<string, mixed> $config Tuning configuration. Unknown keys are ignored.
     * @param array<string, mixed> $state Persisted tuner state.
     */
    public function __construct(array $config, array $state = [])
    {
        $config = $this->normalize_config($config);

        $this->config = $config;
        $this->state = $this->normalize_state($state, $config);
    }

    /**
     * @return array<string, mixed>
     */
    public function get_config(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_state(): array
    {
        return $this->state;
    }

    /**
     * Build query parameters for a specific exporter endpoint.
     *
     * @return array<string, mixed>
     */
    public function get_request_params(string $endpoint): array
    {
        if (!$this->config["enabled"]) {
            return [];
        }

        $params = [
            "max_execution_time" => $this->config["max_execution_time"],
            "memory_threshold" => $this->config["memory_threshold"],
        ];

        $ep = self::ENDPOINTS[$endpoint] ?? null;
        if ($ep === null) {
            return $params;
        }

        $size = max(
            (int) $this->config[$ep["min_key"]],
            min((int) $this->config[$ep["max_key"]], (int) $this->state[$ep["size_key"]]),
        );
        $this->state[$ep["size_key"]] = $size;
        $params[$ep["param"]] = $size;

        if ($endpoint === "sql_chunk") {
            if ($this->config["db_unbuffered"]) {
                $params["db_unbuffered"] = 1;
            }
            if ($this->config["db_query_time_limit"] > 0) {
                $params["db_query_time_limit"] = (int) $this->config["db_query_time_limit"];
            }
        }

        return $params;
    }

    /**
     * Record a successful request and update endpoint sizing state.
     *
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    public function record_result(string $endpoint, array $metrics): array
    {
        if (!$this->config["enabled"]) {
            return [
                "decision" => "disabled",
                "sleep_seconds" => 0.0,
                "duty" => $this->state["duty"],
            ];
        }

        $elapsed = $this->elapsed_time($metrics);
        if ($elapsed === null) {
            return [
                "decision" => "no_server_time",
                "sleep_seconds" => 0.0,
                "duty" => $this->state["duty"],
                "elapsed" => 0.0,
                "wall_time" => (float) ($metrics["wall_time"] ?? 0),
                "server_time" => (float) ($metrics["server_time"] ?? 0),
            ];
        }

        $decision = $this->tune_endpoint($endpoint, $metrics, $elapsed);
        $this->decay_error_backoff();

        $sleep = $this->sleep_seconds($elapsed);
        if (($metrics["status"] ?? null) === "complete") {
            $sleep = 0.0;
        }

        return array_merge($decision, [
            "sleep_seconds" => $sleep,
            "duty" => $this->state["duty"],
            "elapsed" => $elapsed,
            "status" => $metrics["status"] ?? null,
            "wall_time" => (float) ($metrics["wall_time"] ?? 0),
            "server_time" => (float) ($metrics["server_time"] ?? 0),
            "error_backoff_remaining" => $this->state["error_backoff_remaining"],
        ]);
    }

    /**
     * Record a request-level error and trigger temporary backoff.
     *
     * @param array<string, mixed> $error
     * @return array<string, mixed>
     */
    public function record_error(string $endpoint, array $error): array
    {
        $http_code = (int) ($error["http_code"] ?? 0);
        $timeout = (bool) ($error["timeout"] ?? false);
        $curl_errno = (int) ($error["curl_errno"] ?? 0);

        $should_backoff =
            $timeout ||
            ($http_code >= 400 && $http_code < 600) ||
            $http_code >= 600;

        if (!$should_backoff) {
            return [
                "decision" => "ignore",
                "http_code" => $http_code,
                "timeout" => $timeout,
                "curl_errno" => $curl_errno,
                "error_backoff_remaining" => $this->state["error_backoff_remaining"],
            ];
        }

        $this->state["error_backoff_remaining"] = max(
            $this->state["error_backoff_remaining"],
            (int) $this->config["error_backoff_requests"],
        );

        $ep = self::ENDPOINTS[$endpoint] ?? null;
        $size_key = $ep["size_key"] ?? null;
        if ($ep !== null) {
            $this->state[$size_key] = $this->clamp_endpoint_size(
                $ep,
                (int) round((int) $this->state[$size_key] * (float) $this->config["error_decrease_factor"]),
            );
        }

        return [
            "decision" => "backoff",
            "http_code" => $http_code,
            "timeout" => $timeout,
            "curl_errno" => $curl_errno,
            "error_backoff_remaining" => $this->state["error_backoff_remaining"],
            "size_key" => $size_key,
            "size_value" => $size_key ? $this->state[$size_key] : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalize_config(array $config): array
    {
        $defaults = [
            "enabled" => true,
            "use_server_time" => true,
            "max_execution_time" => 5,
            "memory_threshold" => 0.8,
            "duty" => 0.5,
            "duty_min" => 0.35,
            "duty_max" => 1.0,
            "min_sleep" => 0.2,
            "max_sleep" => 10.0,
            "throughput_ema_alpha" => 0.2,
            "aimd_drop_ratio" => 0.9,
            "aimd_decrease_factor" => 0.7,
            "error_decrease_factor" => 0.5,
            "aimd_increase_file_bytes" => 256 * 1024,
            "aimd_increase_index_entries" => 500,
            "aimd_increase_sql_fragments" => 100,
            "error_backoff_requests" => 3,
            "file_chunk_start" => 5 * 1024 * 1024,
            "file_chunk_min" => 256 * 1024,
            "file_chunk_max" => 16 * 1024 * 1024,
            "index_batch_start" => 5000,
            "index_batch_min" => 500,
            "index_batch_max" => 50000,
            "sql_fragments_start" => 1000,
            "sql_fragments_min" => 100,
            "sql_fragments_max" => 5000,
            "db_unbuffered" => false,
            "db_query_time_limit" => 0,
        ];

        $config = array_merge($defaults, array_intersect_key($config, $defaults));
        $config["enabled"] = (bool) $config["enabled"];
        $config["use_server_time"] = (bool) $config["use_server_time"];
        $config["max_execution_time"] = max(1, (int) $config["max_execution_time"]);
        $config["memory_threshold"] = $this->clamp((float) $config["memory_threshold"], 0.1, 0.95);
        $config["duty"] = $this->clamp((float) $config["duty"], 0.1, 1.0);
        $config["duty_min"] = $this->clamp((float) $config["duty_min"], 0.1, 1.0);
        $config["duty_max"] = $this->clamp((float) $config["duty_max"], 0.1, 1.0);
        $config["min_sleep"] = max(0.0, (float) $config["min_sleep"]);
        $config["max_sleep"] = max($config["min_sleep"], (float) $config["max_sleep"]);
        $config["throughput_ema_alpha"] = $this->clamp((float) $config["throughput_ema_alpha"], 0.05, 0.5);
        $config["aimd_drop_ratio"] = $this->clamp((float) $config["aimd_drop_ratio"], 0.5, 0.99);
        $config["aimd_decrease_factor"] = $this->clamp((float) $config["aimd_decrease_factor"], 0.1, 0.95);
        $config["error_decrease_factor"] = $this->clamp((float) $config["error_decrease_factor"], 0.1, 0.95);
        $config["error_backoff_requests"] = max(1, min(20, (int) $config["error_backoff_requests"]));
        $config["db_unbuffered"] = (bool) $config["db_unbuffered"];
        $config["db_query_time_limit"] = max(0, (int) $config["db_query_time_limit"]);

        foreach (self::ENDPOINTS as $endpoint) {
            $config[$endpoint["increase_key"]] = max(
                1,
                min((int) $config[$endpoint["max_key"]], (int) $config[$endpoint["increase_key"]]),
            );
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalize_state(array $state, array $config): array
    {
        $state_defaults = [
            "duty" => $config["duty"],
            "error_backoff_remaining" => 0,
        ];

        foreach (self::ENDPOINTS as $endpoint) {
            $state_defaults[$endpoint["size_key"]] = $config[$endpoint["start_key"]];
            $state_defaults[$endpoint["ema_key"]] = null;
        }

        $state = array_merge($state_defaults, $state);

        foreach (self::ENDPOINTS as $endpoint) {
            $state[$endpoint["size_key"]] = max(
                (int) $config[$endpoint["min_key"]],
                min((int) $config[$endpoint["max_key"]], (int) $state[$endpoint["size_key"]]),
            );

            $ema = $state[$endpoint["ema_key"]] ?? null;
            $state[$endpoint["ema_key"]] = ($ema !== null && (float) $ema > 0) ? (float) $ema : null;
        }

        $state["duty"] = $this->clamp((float) $state["duty"], $config["duty_min"], $config["duty_max"]);
        $state["error_backoff_remaining"] = max(0, (int) ($state["error_backoff_remaining"] ?? 0));

        return $state;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function elapsed_time(array $metrics): ?float
    {
        $wall_time = (float) ($metrics["wall_time"] ?? 0);
        $server_time = (float) ($metrics["server_time"] ?? 0);

        if (!$this->config["use_server_time"]) {
            return $wall_time > 0 ? $wall_time : 0.001;
        }

        return $server_time > 0 ? $server_time : null;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function tune_endpoint(string $endpoint, array $metrics, float $elapsed): array
    {
        $ep = self::ENDPOINTS[$endpoint] ?? null;
        $work_done = $this->work_done($ep, $metrics);
        $size_key = $ep["size_key"] ?? null;

        if ($work_done === null || $work_done <= 0 || $ep === null) {
            return [
                "decision" => "no_work",
                "work_done" => $work_done,
                "throughput" => null,
                "throughput_ema" => null,
                "throughput_ratio" => null,
                "size_key" => $size_key,
                "size_value" => $size_key ? $this->state[$size_key] : null,
            ];
        }

        $throughput = $work_done / max(0.0001, $elapsed);
        $prev_ema = $this->state[$ep["ema_key"]] ?? null;
        $throughput_ratio = ($prev_ema !== null && $prev_ema > 0)
            ? $throughput / $prev_ema
            : null;

        $throughput_ema = $this->updated_ema($prev_ema, $throughput);
        $this->state[$ep["ema_key"]] = $throughput_ema;

        $decision = $this->adjust_endpoint_size($ep, $prev_ema, $throughput_ratio);

        return [
            "decision" => $decision,
            "work_done" => $work_done,
            "throughput" => $throughput,
            "throughput_ema" => $throughput_ema,
            "throughput_ratio" => $throughput_ratio,
            "size_key" => $size_key,
            "size_value" => $this->state[$size_key],
        ];
    }

    private function updated_ema(?float $previous, float $current): float
    {
        if ($previous === null || $previous <= 0) {
            return $current;
        }

        $alpha = (float) $this->config["throughput_ema_alpha"];
        return $previous * (1.0 - $alpha) + $current * $alpha;
    }

    /**
     * @param array<string, string> $endpoint
     */
    private function adjust_endpoint_size(array $endpoint, ?float $previous_ema, ?float $throughput_ratio): string
    {
        if ($this->state["error_backoff_remaining"] > 0) {
            return "error_backoff";
        }

        if ($previous_ema === null || $previous_ema <= 0) {
            return "warmup";
        }

        $size = (int) $this->state[$endpoint["size_key"]];
        if (
            $throughput_ratio !== null &&
            $throughput_ratio < (float) $this->config["aimd_drop_ratio"]
        ) {
            $size = (int) round($size * (float) $this->config["aimd_decrease_factor"]);
            $decision = "decrease";
        } else {
            $size += (int) $this->config[$endpoint["increase_key"]];
            $decision = "increase";
        }

        $this->state[$endpoint["size_key"]] = $this->clamp_endpoint_size($endpoint, $size);

        return $decision;
    }

    /**
     * @param array<string, string>|null $endpoint
     * @param array<string, mixed> $metrics
     */
    private function work_done(?array $endpoint, array $metrics): ?int
    {
        if ($endpoint === null) {
            return null;
        }
        if (isset($metrics[$endpoint["work_metric"]])) {
            return (int) $metrics[$endpoint["work_metric"]];
        }
        if (isset($endpoint["work_metric_alt"]) && isset($metrics[$endpoint["work_metric_alt"]])) {
            return (int) $metrics[$endpoint["work_metric_alt"]];
        }
        return null;
    }

    /**
     * @param array<string, string> $endpoint
     */
    private function clamp_endpoint_size(array $endpoint, int $size): int
    {
        return max(
            (int) $this->config[$endpoint["min_key"]],
            min((int) $this->config[$endpoint["max_key"]], $size),
        );
    }

    private function decay_error_backoff(): void
    {
        if ($this->state["error_backoff_remaining"] > 0) {
            $this->state["error_backoff_remaining"]--;
        }
    }

    private function sleep_seconds(float $elapsed): float
    {
        $duty = $this->clamp(
            (float) $this->state["duty"],
            $this->config["duty_min"],
            $this->config["duty_max"],
        );
        $this->state["duty"] = $duty;

        if ($duty >= 1.0 || $elapsed <= 0) {
            return 0.0;
        }

        $sleep = $elapsed * (1.0 / max(0.01, $duty) - 1.0);
        return $this->clamp($sleep, $this->config["min_sleep"], $this->config["max_sleep"]);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}
