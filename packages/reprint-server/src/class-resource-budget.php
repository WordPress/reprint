<?php

// Declared here rather than in export.php so it can live in this package's
// namespace. export.php is a global-namespace file of endpoint functions that
// callers reach by unqualified name.

namespace WordPress\Reprint\Server;

/**
 * Tracks time and memory limits for a single API request.
 *
 * Every export endpoint runs under resource constraints — a maximum
 * execution time and a memory ceiling.  Rather than threading four
 * separate values through every function signature and every
 * should_continue() call, this class bundles them into a single
 * object with a simple has_remaining() check.
 */
class ResourceBudget
{
    /** @var float */
    public $start_time;
    /** @var int */
    public $max_time;
    /** @var int */
    public $max_memory;
    /** @var float */
    public $memory_threshold;

    public function __construct(
        float $start_time,
        int $max_time,
        int $max_memory,
        float $memory_threshold
    ) {
        $this->start_time = $start_time;
        $this->max_time = $max_time;
        $this->max_memory = $max_memory;
        $this->memory_threshold = $memory_threshold;
    }

    /**
     * A budget starting now: $max_execution_time seconds, capped by PHP's own limit, and
     * $memory_threshold of memory_limit.
     */
    public static function from_ini(int $max_execution_time = 15, float $memory_threshold = 0.8): self
    {
        $ini_max_execution_time = (int) ini_get('max_execution_time');
        if ($ini_max_execution_time > 0) {
            $max_execution_time = min($max_execution_time, $ini_max_execution_time);
        }

        $memory_limit = (string) ini_get('memory_limit');
        if ($memory_limit === '-1') {
            $max_memory = PHP_INT_MAX;
        } else {
            try {
                $max_memory = Utils::parse_size($memory_limit);
            } catch (\InvalidArgumentException $e) {
                // Empty, or a form parse_size() doesn't read (e.g. PHP 8.1's hex "0x20000000"):
                // assume PHP's built-in default, which is on the low side.
                $max_memory = 128 * 1024 * 1024;
            }
        }

        return new self(microtime(true), $max_execution_time, $max_memory, $memory_threshold);
    }

    /** Returns false when the request should yield due to time or memory pressure. */
    public function has_remaining(): bool
    {
        if (microtime(true) - $this->start_time >= $this->max_time) {
            return false;
        }

        $memory_used = memory_get_usage(true);
        if ($memory_used >= $this->max_memory * $this->memory_threshold) {
            return false;
        }

        return true;
    }
}
