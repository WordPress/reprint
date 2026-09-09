<?php

namespace Reprint\Importer;

use Reprint\Importer\State\FetchListProgressState;
use RuntimeException;

/** Rebuildable file counters. Resume positions remain in the pull checkpoint. */
class FilesPullProgress {
    private ?int $files_total = null;
    private int $files_before_batch = 0;
    private int $files_in_batch = 0;
    private ?int $bytes_total = null;
    private int $bytes_before_batch = 0;
    private int $bytes_in_batch = 0;

    /**
     * Reads the list once per invocation. Totals survive across batches in memory;
     * a new process rebuilds them from the fetch-list byte offset and saved cursor.
     * FileTreeProducer sorts each batch by remote absolute path, regardless of list order.
     */
    public function load_list(string $list_file, FetchListProgressState $fetch): void {
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
        $this->bytes_total = 0;
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
                $this->bytes_total = null;
            } elseif ($this->bytes_total !== null) {
                $this->bytes_total += $size;
            }
            $position = ftell($handle);
            if ($position <= $fetch->offset) {
                ++$this->files_before_batch;
                $this->bytes_before_batch += $size;
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
                    $this->bytes_in_batch += $size;
                }
            }
        }
        fclose($handle);
    }

    public function restart_batch(): void {
        $this->files_in_batch = 0;
        $this->bytes_in_batch = 0;
    }

    public function complete_file(int $file_size): void {
        ++$this->files_in_batch; // Count completed files only.
        $this->bytes_in_batch += $file_size;
    }

    public function complete_batch(int $batch_entries): void {
        // Use the known batch size, including directories and skipped paths.
        // Completed files move into the preceding-batch counters only once.
        $this->files_before_batch += $batch_entries;
        $this->bytes_before_batch += $this->bytes_in_batch;
        $this->restart_batch();
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
    public function get_details(?StreamingContext $context = null): array {
        $progress = ProgressReporter::EMPTY_DETAILS;
        if ($this->files_total === null && $context === null) {
            return $progress;
        }
        $progress['items'] = [
            'unit' => 'files',
            'done' => $this->files_before_batch + $this->files_in_batch,
            'total' => $this->files_total,
        ];
        if ($this->bytes_total !== null) {
            $progress['bytes'] = [
                'done' => $this->bytes_before_batch + $this->bytes_in_batch
                    + ( $context !== null && $context->remote_file_path !== null ? $context->file_bytes_written : 0 ),
                'total' => $this->bytes_total,
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
