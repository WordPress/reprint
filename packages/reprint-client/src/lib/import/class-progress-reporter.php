<?php

namespace Reprint\Importer;

use Reprint\Importer\State\FetchListProgressState;
use RuntimeException;

/** Tracks file-pull counters and keeps the latest screen snapshot separate from the JSONL event log. */
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

    /** Live counters; completed bytes are copied to the fetch checkpoint only at durable fetch boundaries. */
    private ?int $files_total = null;
    private int $files_before_batch = 0;
    private int $files_in_batch = 0;
    private ?int $file_bytes_total = null;
    private int $file_bytes_before_batch = 0;
    private int $file_bytes_in_batch = 0;

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

    /**
     * Reads the list once per invocation. Totals survive across batches in memory;
     * a new process rebuilds path counts from the fetch-list byte offset and saved cursor.
     * Completed bytes come from the checkpoint: a passed path may have failed, or changed size.
     * FileTreeProducer sorts each batch by remote absolute path, regardless of list order.
     */
    public function load_file_list(string $list_file, FetchListProgressState $fetch): void {
        if ($this->files_total !== null) {
            return;
        }
        $handle = fopen($list_file, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Failed to open the fetch list for progress totals.');
        }
        $cursor = json_decode(base64_decode($fetch->cursor ?? '', true) ?: '', true);
        $cursor_path = isset($cursor['path']) ? base64_decode($cursor['path'], true) : false;
        $cursor_finishes_file = ( $cursor['bytes'] ?? 0 ) === 0;
        $this->files_total = 0;
        $this->file_bytes_total = 0;
        $this->file_bytes_before_batch = $fetch->file_bytes_before_batch;
        $this->file_bytes_in_batch = $fetch->file_bytes_in_batch;
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            ++$this->files_total;
            $size = (int) ( $entry['size'] ?? 0 );
            if (!isset($entry['size'])) {
                $this->file_bytes_total = null;
            } elseif ($this->file_bytes_total !== null) {
                $this->file_bytes_total += $size;
            }
            $position = ftell($handle);
            if ($position <= $fetch->offset) {
                ++$this->files_before_batch;
            } elseif ($position <= $fetch->next_offset) {
                $entry_path = base64_decode($entry['path'], true);
                if (
                    ( $cursor['phase'] ?? null ) === 'finished'
                    || ( $cursor_path !== false && (
                        strcmp($entry_path, $cursor_path) < 0
                        || ( $entry_path === $cursor_path && $cursor_finishes_file )
                    ) )
                ) {
                    ++$this->files_in_batch;
                }
            }
        }
        fclose($handle);
    }

    /** Clears counters for a new pull without changing the screen snapshot or write timers. */
    public function reset_file_counters(): void {
        $this->files_total = null;
        $this->files_before_batch = 0;
        $this->file_bytes_total = null;
        $this->file_bytes_before_batch = 0;
        $this->restart_file_batch();
    }

    public function complete_file(int $file_size): void {
        ++$this->files_in_batch; // Count completed files only.
        $this->file_bytes_in_batch += $file_size;
    }

    public function complete_file_batch(int $batch_entries): void {
        // Use the known batch size, including directories and skipped paths.
        // Completed files move into the preceding-batch counters only once.
        $this->files_before_batch += $batch_entries;
        $this->file_bytes_before_batch += $this->file_bytes_in_batch;
        $this->restart_file_batch();
    }

    /** Copies byte counts into the checkpoint together with the cursor that confirms those files. */
    public function checkpoint_file_bytes(FetchListProgressState $fetch): void {
        $fetch->file_bytes_before_batch = $this->file_bytes_before_batch;
        $fetch->file_bytes_in_batch = $this->file_bytes_in_batch;
    }

    public function restart_file_batch(): void {
        $this->files_in_batch = 0;
        $this->file_bytes_in_batch = 0;
    }

    public function get_batch_files_done(): int {
        return $this->files_in_batch;
    }

    /**
     * @return array {
     *     Current file-download progress; totals stay unknown until the list is loaded.
     *     @type array|null $items         Files processed and selected path count.
     *     @type array|null $bytes         Completed file bytes plus the open file position.
     *     @type array|null $current_file  Remote path and current file byte position and size.
     *     @type null       $current_table Unused during files-pull.
     * }
     */
    public function get_file_details(?StreamingContext $context = null): array {
        $progress = self::EMPTY_DETAILS;
        if ($this->files_total === null && $context === null) {
            return $progress;
        }
        $progress['items'] = [
            'unit' => 'files',
            'done' => $this->files_before_batch + $this->files_in_batch,
            'total' => $this->files_total,
        ];
        if ($this->file_bytes_total !== null) {
            $progress['bytes'] = [
                'done' => $this->file_bytes_before_batch + $this->file_bytes_in_batch
                    + ( $context !== null && $context->remote_file_path !== null ? $context->file_bytes_written : 0 ),
                'total' => $this->file_bytes_total,
            ];
        }
        if ($context !== null && $context->remote_file_path !== null && $context->remote_file_size !== null) {
            $progress['current_file'] = [
                'path_b64' => base64_encode($context->remote_file_path),
                'bytes_done' => $context->file_bytes_written,
                'bytes_total' => $context->remote_file_size,
            ];
        }
        return $progress;
    }
}
