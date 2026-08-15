<?php
declare(strict_types=1);

namespace Reprint\Importer;

use RuntimeException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI errors contain local paths, never HTML.

/**
 * Appends rows whose live URL rewrite result could not be confirmed.
 *
 * One process writes a job file at a time. A stable row ID and a comparison
 * with the final complete line make replay after an interrupted append
 * idempotent without rescanning the file.
 */
class DatabaseUrlRewriteReviewLog {

    private string $path;
    private string $job_id;

    /** @var resource|null */
    private $handle;

    /** @var array<string,mixed>|null */
    private ?array $last_record = null;

    /**
     * @param array<string,string> $rewrite_url URL replacements for this job.
     * @param array<string,mixed>  $target      Database identity without its password.
     */
    public function __construct(
        string $path,
        string $job_id,
        array $rewrite_url,
        array $target
    ) {
        if (preg_match('/^[a-f0-9]{32}$/', $job_id) !== 1) {
            throw new RuntimeException('The db-rewrite-urls review job ID is invalid.');
        }

        $this->path = $path;
        $this->job_id = $job_id;
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Failed to open the db-rewrite-urls review file: {$path}");
        }
        $this->handle = $handle;

        try {
            if (!flock($this->handle, LOCK_EX)) {
                throw new RuntimeException("Failed to lock the db-rewrite-urls review file: {$path}");
            }
            if (!chmod($path, 0600)) {
                throw new RuntimeException("Failed to restrict the db-rewrite-urls review file: {$path}");
            }

            $this->remove_incomplete_final_line();
            $header = [
                'type' => 'job',
                'version' => 1,
                'job_id' => $job_id,
                'command' => 'db-rewrite-urls',
                'rewrite_url' => array_map(
                    static function (string $from, string $to): array {
                        return [
                            'from' => $from,
                            'to' => $to,
                        ];
                    },
                    array_keys($rewrite_url),
                    array_values($rewrite_url)
                ),
                'target' => $target,
            ];

            if ($this->file_size() === 0) {
                $this->append_record($header);
            } else {
                rewind($this->handle);
                $header_line = fgets($this->handle);
                $saved_header = is_string($header_line)
                    ? json_decode(rtrim($header_line, "\r\n"), true)
                    : null;
                if ($saved_header !== $header) {
                    throw new RuntimeException(
                        "The db-rewrite-urls review file does not match the saved job: {$path}"
                    );
                }
            }
            $this->last_record = $this->read_last_complete_record();
        } catch (\Throwable $throwable) {
            $this->close();
            throw $throwable;
        }
    }

    /**
     * @param array $row {
     *     Row whose conditional update changed no records.
     *
     *     @type string $table       Database table name.
     *     @type array  $primary_key Primary-key values keyed by column name.
     *     @type array  $columns     Original and intended SHA-256 hashes keyed by column name.
     * }
     * @return bool Whether a new line was appended.
     */
    public function append_row_to_verify(array $row): bool
    {
        $primary_key = [];
        foreach ($row['primary_key'] as $column => $value) {
            $primary_key[$column] = $this->format_database_value($value);
        }
        $record_without_id = [
            'type' => 'row_to_verify',
            'reason' => 'conditional_update_changed_no_rows',
            'table' => $row['table'],
            'primary_key' => $primary_key,
            'columns' => $row['columns'],
        ];
        $record_id = hash(
            'sha256',
            $this->job_id . "\n" . $this->encode_record($record_without_id)
        );
        if (
            ( $this->last_record['type'] ?? null ) === 'row_to_verify'
            && ( $this->last_record['id'] ?? null ) === $record_id
        ) {
            return false;
        }

        $this->append_record([
            'type' => 'row_to_verify',
            'id' => $record_id,
            'reason' => 'conditional_update_changed_no_rows',
            'table' => $row['table'],
            'primary_key' => $primary_key,
            'columns' => $row['columns'],
        ]);
        return true;
    }

    public function close(): void
    {
        if (!is_resource($this->handle)) {
            $this->handle = null;
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param mixed $value */
    private function format_database_value($value): array
    {
        if ($value === null) {
            return ['type' => 'null'];
        }
        if (is_int($value)) {
            return [
                'type' => 'integer',
                'value' => $value,
            ];
        }
        if (is_float($value)) {
            return [
                'type' => 'float',
                'value' => $value,
            ];
        }
        if (is_string($value) && preg_match('//u', $value) === 1) {
            return [
                'type' => 'string',
                'value' => $value,
            ];
        }
        if (is_string($value)) {
            return [
                'type' => 'bytes',
                'base64' => base64_encode($value),
            ];
        }
        throw new RuntimeException(
            'Cannot record a db-rewrite-urls primary-key value of type ' . gettype($value) . '.'
        );
    }

    /** @param array<string,mixed> $record */
    private function append_record(array $record): void
    {
        $line = $this->encode_record($record) . "\n";
        fseek($this->handle, 0, SEEK_END);
        $offset = 0;
        $length = strlen($line);
        while ($offset < $length) {
            $bytes_written = fwrite($this->handle, substr($line, $offset));
            if ($bytes_written === false || $bytes_written === 0) {
                throw new RuntimeException(
                    "Failed to append the db-rewrite-urls review file: {$this->path}"
                );
            }
            $offset += $bytes_written;
        }
        if (!fflush($this->handle)) {
            throw new RuntimeException(
                "Failed to flush the db-rewrite-urls review file: {$this->path}"
            );
        }
        if (function_exists('fsync') && !fsync($this->handle)) {
            throw new RuntimeException(
                "Failed to sync the db-rewrite-urls review file: {$this->path}"
            );
        }
        $this->last_record = $record;
    }

    /** @param array<string,mixed> $record */
    private function encode_record(array $record): string
    {
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException(
                'Failed to encode a db-rewrite-urls review record: ' . json_last_error_msg()
            );
        }
        return $encoded;
    }

    private function remove_incomplete_final_line(): void
    {
        $size = $this->file_size();
        if ($size === 0) {
            return;
        }
        fseek($this->handle, -1, SEEK_END);
        if (fread($this->handle, 1) === "\n") {
            return;
        }

        $last_newline = $this->find_last_newline_offset($size);
        $complete_size = $last_newline === null ? 0 : $last_newline + 1;
        if (!ftruncate($this->handle, $complete_size) || !fflush($this->handle)) {
            throw new RuntimeException(
                "Failed to discard an incomplete db-rewrite-urls review line: {$this->path}"
            );
        }
        if (function_exists('fsync') && !fsync($this->handle)) {
            throw new RuntimeException(
                "Failed to sync the repaired db-rewrite-urls review file: {$this->path}"
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function read_last_complete_record(): ?array
    {
        $size = $this->file_size();
        if ($size === 0) {
            return null;
        }

        $previous_newline = $this->find_last_newline_offset($size - 1);
        $start = $previous_newline === null ? 0 : $previous_newline + 1;
        $length = $size - 1 - $start;
        fseek($this->handle, $start);
        $line = $length > 0 ? fread($this->handle, $length) : '';
        $record = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($record)) {
            throw new RuntimeException(
                "The final db-rewrite-urls review line is invalid: {$this->path}"
            );
        }
        return $record;
    }

    private function find_last_newline_offset(int $before_offset): ?int
    {
        $position = $before_offset;
        while ($position > 0) {
            $length = min(4096, $position);
            $position -= $length;
            fseek($this->handle, $position);
            $chunk = fread($this->handle, $length);
            if (!is_string($chunk)) {
                throw new RuntimeException(
                    "Failed to read the db-rewrite-urls review file: {$this->path}"
                );
            }
            $newline = strrpos($chunk, "\n");
            if ($newline !== false) {
                return $position + $newline;
            }
        }
        return null;
    }

    private function file_size(): int
    {
        fseek($this->handle, 0, SEEK_END);
        $size = ftell($this->handle);
        if ($size === false) {
            throw new RuntimeException(
                "Failed to inspect the db-rewrite-urls review file: {$this->path}"
            );
        }
        return $size;
    }
}
