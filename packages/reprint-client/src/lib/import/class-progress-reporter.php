<?php

namespace Reprint\Importer;

/** Keeps the latest screen snapshot separate from the JSONL event log. */
class ProgressReporter {
    public const SCHEMA_VERSION = 1;
    /** Stable screen keys when a phase has no reported counters. */
    public const EMPTY_DETAILS = [
        'items' => null,
        'bytes' => null,
        'current_file' => null,
        'current_table' => null,
    ];
    private const REPORT_INTERVAL = 1.0;

    private string $progress_file;
    private array $snapshot = [];
    /** Timestamp of the last successful progress.json replacement. */
    private float $last_file_write = 0;
    /** Timestamp of the last emitted JSONL record, independent of file writes. */
    private float $last_output = 0;

    public function __construct(string $progress_file) {
        $this->progress_file = $progress_file;
    }

    /**
     * Updates one screen snapshot. Ordinary log messages leave its label alone.
     *
     * @param array $context {
     *     Current command state, independent of the event's legacy field names.
     *     @type string|null $command    Command being reported.
     *     @type string|null $phase      Current phase.
     *     @type string|null $status     Command status; null means cleared.
     *     @type int|null    $step       Optional pipeline position.
     *     @type int|null    $steps      Optional pipeline length.
     *     @type string|null $error      Optional terminal error.
     *     @type string|null $error_code Optional error classification.
     *     @type string|null $reason     Optional files-push terminal reason.
     *     @type string|null $detail     Optional files-push terminal detail.
     * }
     * @param array $event {
     *     JSONL event. Other event-specific keys are not part of the screen snapshot.
     *     @type array  $progress Optional screen counters, replaced together.
     *     @type string $message  Optional screen label on progress or lifecycle events.
     *     @type string $type     Optional event type.
     *     @type string $status   Optional lifecycle status.
     * }
     */
    public function update(array $context, array $event = []): void {
        // A saved label and its counters may be reused only in the same command and phase.
        $same_phase = $this->snapshot !== []
            && $this->snapshot['command'] === $context['command']
            && $this->snapshot['phase'] === $context['phase'];
        $has_progress = isset($event['progress']) && is_array($event['progress']);
        $has_screen_message = $has_progress
            || in_array($event['type'] ?? null, ['lifecycle', 'interrupt'], true)
            || isset($event['status']);
        $this->snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'step' => $context['step'] ?? null,
            'steps' => $context['steps'] ?? null,
            'command' => $context['command'],
            'status' => $context['status'],
            'phase' => $context['phase'],
            'message' => $has_screen_message && isset($event['message'])
                ? $event['message']
                : ( $same_phase ? $this->snapshot['message'] : null ),
            'progress' => $has_progress
                ? $event['progress']
                : ( $same_phase ? $this->snapshot['progress'] : self::EMPTY_DETAILS ),
            'error' => $context['error'] ?? null,
            'error_code' => $context['error_code'] ?? null,
            'reason' => $context['reason'] ?? null,
            'detail' => $context['detail'] ?? null,
            'ts' => microtime(true),
        ];
    }

    /** Active updates are throttled; terminal, cleared and forced updates are immediate. */
    public function write_file(bool $force = false): void {
        if (
            !$force
            && $this->snapshot['status'] === 'in_progress'
            && microtime(true) - $this->last_file_write < self::REPORT_INTERVAL
        ) {
            return;
        }
        // Preserve the existing status label for cleared pull checkpoints, while
        // their null completion state still bypasses the active-write interval.
        $payload = array_replace($this->snapshot, ['status' => $this->snapshot['status'] ?? 'in_progress']);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return; // Best-effort — don't crash the pull over a progress file.
        }
        // Readers must see either complete snapshot, never a partial JSON write.
        $temporary_file = $this->progress_file . '.tmp';
        if (
            file_put_contents($temporary_file, $json) !== false
            && rename($temporary_file, $this->progress_file)
        ) {
            $this->last_file_write = microtime(true);
        }
    }

    /**
     * Emits a JSONL event. False lets the caller save its checkpoint on a broken pipe.
     *
     * @param array $event {
     *     Progress event, retaining its event-specific fields.
     *     @type array  $progress Optional screen counters; adds schema_version when present.
     *     @type string $status   Optional lifecycle status; starting, complete and error bypass throttling.
     * }
     * @param resource $stream Progress output stream.
     * @param bool     $force  Whether this event bypasses JSONL throttling.
     */
    public function output_jsonl(array $event, $stream, bool $force = false): bool {
        $is_status_change = in_array($event['status'] ?? null, ['starting', 'complete', 'error'], true);
        $now = microtime(true);
        if (!$force && !$is_status_change && $now - $this->last_output < self::REPORT_INTERVAL) {
            return true;
        }
        if (isset($event['progress']) && is_array($event['progress'])) {
            $event['schema_version'] = self::SCHEMA_VERSION;
        }
        if (@fwrite($stream, json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE) . "\n") === false) {
            return false;
        }
        @flush();
        $this->last_output = $now;
        return true;
    }
}
