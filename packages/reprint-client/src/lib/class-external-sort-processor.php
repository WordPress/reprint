<?php
declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors contain private work paths, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer processors use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer processors place braces on the following line.

/**
 * Sorts an immutable line file through bounded, resumable steps.
 *
 * An initial-run step targets a fixed byte count and accepts the one bounded
 * line and key already read across that boundary; its row count remains hard
 * capped. Merge steps read at most two rows and write at most one row. Two
 * framed work slots retain the current and next pass, so handles and work-disk
 * usage do not scale with the number of runs. A caller must durably save every
 * phase transition before taking the next step; `merge_pass_complete` is the
 * boundary after which the obsolete input slot may be removed. Output copying
 * is bounded, and work slots remain until the published output has its own
 * durable cleanup phase.
 * Equal keys use the complete line as a deterministic tiebreak, rather than
 * retaining input order. Every physical line must end in LF. The source,
 * output, work directory, work name, and duplicate policy are fingerprinted.
 * The caller exclusively owns those paths and must supply the same deterministic
 * key extractor throughout the lifecycle.
 *
 * @phpstan-type BuildingCursor array{phase:'building_runs',config_fingerprint:string,source_byte_offset:int,slot_byte_offset:int,run_count:int}
 * @phpstan-type StartingMergeCursor array{phase:'starting_merge',config_fingerprint:string,input_slot:int,input_run_count:int}
 * @phpstan-type MergingCursor array{phase:'merging_runs',config_fingerprint:string,input_slot:int,input_run_count:int,input_run_index:int,input_byte_offset:int,left_byte_offset:int|null,left_end_byte_offset:int|null,right_byte_offset:int|null,right_end_byte_offset:int|null,output_slot:int,output_byte_offset:int,output_run_count:int,output_run_header_byte_offset:int|null,previous_key_b64:string|null}
 * @phpstan-type MergeCompleteCursor array{phase:'merge_pass_complete',config_fingerprint:string,next_input_slot:int,next_input_run_count:int,obsolete_slot:int}
 * @phpstan-type StartingOutputCursor array{phase:'starting_output',config_fingerprint:string,final_slot:int,final_run_count:int}
 * @phpstan-type CopyingCursor array{phase:'copying_output',config_fingerprint:string,final_slot:int,final_run_start_byte_offset:int,final_run_byte_offset:int,final_run_end_byte_offset:int,output_byte_offset:int}
 * @phpstan-type SimpleCursor array{phase:'publishing_output'|'complete',config_fingerprint:string}
 * @phpstan-type CleanupCursor array{phase:'cleaning_work_files',config_fingerprint:string,next_cleanup_slot:int}
 * @phpstan-type Cursor BuildingCursor|StartingMergeCursor|MergingCursor|MergeCompleteCursor|StartingOutputCursor|CopyingCursor|SimpleCursor|CleanupCursor
 */
final class ExternalSortProcessor
{
    private const FRAME_HEADER_BYTES = 17;
    /** Keeps one in-memory sort run near 1 MiB before its one bounded overrun. */
    private const INITIAL_RUN_BYTES = 1048576;
    /** Caps PHP array overhead even when input lines and keys are tiny. */
    private const INITIAL_RUN_ROWS = 4096;
    /** Bounds the one physical line which may cross the run-byte target. */
    private const MAXIMUM_LINE_BYTES = 65536;
    /** Bounds the extracted key retained beside one physical line. */
    private const MAXIMUM_KEY_BYTES = 65536;
    /** Bounds each distinct-output copy step to 1 MiB. */
    private const OUTPUT_COPY_BYTES = 1048576;

    private string $source_file;
    private string $output_file;
    private string $work_directory;
    private string $work_name;
    /** @var callable(string):?string */
    private $key_extractor;
    private bool $deduplicate;
    private string $config_fingerprint;
    private int $source_file_bytes;
    /** @var Cursor */
    private array $cursor;
    /** @var resource|null */
    private $input_handle = null;
    /** @var resource|null */
    private $output_handle = null;
    /** @var array{key:string,line:string,raw_line:string,next_byte_offset:int}|null */
    private ?array $left_entry = null;
    /** @var array{key:string,line:string,raw_line:string,next_byte_offset:int}|null */
    private ?array $right_entry = null;
    private bool $closed = false;

    /**
     * @param callable(string):?string $key_extractor Returns the binary sort key, or null to skip a row.
     */
    public static function start(
        string $source_file,
        string $output_file,
        string $work_directory,
        string $work_name,
        callable $key_extractor,
        bool $deduplicate = true
    ): self {
        self::assert_output_is_not_symlink($output_file);
        $work_directory = self::prepare_work_directory($work_directory);
        $source_file_bytes = self::file_size($source_file, 'source file');
        $config_fingerprint = self::configuration_fingerprint(
            $source_file,
            $output_file,
            $work_directory,
            $work_name,
            $deduplicate,
            $source_file_bytes
        );
        return self::resume(
            $source_file,
            $output_file,
            $work_directory,
            $work_name,
            $key_extractor,
            $deduplicate,
            [
                'phase' => 'building_runs',
                'config_fingerprint' => $config_fingerprint,
                'source_byte_offset' => 0,
                'slot_byte_offset' => 0,
                'run_count' => 0,
            ]
        );
    }

    /**
     * @param callable(string):?string $key_extractor Returns the binary sort key, or null to skip a row.
     * @phpstan-param Cursor $cursor
     */
    public static function resume(
        string $source_file,
        string $output_file,
        string $work_directory,
        string $work_name,
        callable $key_extractor,
        bool $deduplicate,
        array $cursor
    ): self {
        self::assert_output_is_not_symlink($output_file);
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $work_name) !== 1) {
            throw new InvalidArgumentException('External sort work name must contain lowercase letters, numbers, or hyphens.');
        }
        $work_directory = self::prepare_work_directory($work_directory);
        $processor = new self();
        $processor->source_file = $source_file;
        $processor->output_file = $output_file;
        $processor->work_directory = $work_directory;
        $processor->work_name = $work_name;
        $processor->key_extractor = $key_extractor;
        $processor->deduplicate = $deduplicate;
        $processor->source_file_bytes = self::file_size($source_file, 'source file');
        $processor->config_fingerprint = self::configuration_fingerprint(
            $source_file,
            $output_file,
            $work_directory,
            $work_name,
            $deduplicate,
            $processor->source_file_bytes
        );
        $processor->cursor = $cursor;
        $processor->assert_cursor();
        if ($cursor['config_fingerprint'] !== $processor->config_fingerprint) {
            throw new UnexpectedValueException('External sort configuration changed after the lifecycle started.');
        }
        if ($cursor['phase'] === 'complete' && !is_file($output_file)) {
            throw new UnexpectedValueException('Completed external sort output does not exist.');
        }
        try {
            $processor->open_phase_handles();
        } catch (Throwable $throwable) {
            $processor->close();
            throw $throwable;
        }
        return $processor;
    }

    public function next_step(): bool
    {
        if ($this->cursor['phase'] === 'complete') {
            return false;
        }
        if ($this->closed) {
            throw new LogicException('Cannot take an external sort step after close().');
        }
        $this->open_phase_handles();
        switch ($this->cursor['phase']) {
            case 'building_runs':
                return $this->build_next_run();
            case 'starting_merge':
                return $this->start_merge_or_output();
            case 'merging_runs':
                return $this->merge_next_row();
            case 'merge_pass_complete':
                return $this->remove_obsolete_slot();
            case 'starting_output':
                return $this->start_output_copy();
            case 'copying_output':
                return $this->copy_next_output_chunk();
            case 'publishing_output':
                return $this->publish_output();
            case 'cleaning_work_files':
                return $this->remove_next_work_file();
        }
        throw new LogicException('External sort cursor has an invalid phase.');
    }

    /** @return Cursor */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->close_handles();
        }
    }

    private function build_next_run(): bool
    {
        $entries = [];
        $bytes_read = 0;
        $rows_read = 0;
        $reached_end = false;
        while (
            $bytes_read < self::INITIAL_RUN_BYTES
            && $rows_read < self::INITIAL_RUN_ROWS
        ) {
            $line_start_byte_offset = self::handle_offset($this->input_handle, 'source file');
            $entry = $this->read_line($this->input_handle, null, 'source file');
            if ($entry === null) {
                $reached_end = true;
                break;
            }
            $key = ( $this->key_extractor )($entry['line']);
            if ($key !== null && !is_string($key)) {
                throw new UnexpectedValueException(
                    sprintf(
                        'External sort key extractor returned %s for the source row at byte offset %d; expected string or null.',
                        gettype($key),
                        $line_start_byte_offset
                    )
                );
            }
            if (is_string($key) && strlen($key) > self::MAXIMUM_KEY_BYTES) {
                throw new UnexpectedValueException(
                    sprintf(
                        'External sort key is %d bytes for the source row at byte offset %d; maximum is %d bytes.',
                        strlen($key),
                        $line_start_byte_offset,
                        self::MAXIMUM_KEY_BYTES
                    )
                );
            }
            ++$rows_read;
            // Retain the row which crosses the target so its key is extracted only once.
            $bytes_read += strlen($entry['raw_line'])
                + ( is_string($key) ? strlen($key) : 0 );
            if (is_string($key)) {
                $entries[] = [
                    'key' => $key,
                    'line' => $entry['line'],
                    'raw_line' => $entry['raw_line'],
                ];
            }
        }

        $source_byte_offset = self::handle_offset($this->input_handle, 'source file');
        if ($entries !== []) {
            usort($entries, [self::class, 'compare_entries']);
            $run_bytes = 0;
            $previous_key = null;
            foreach ($entries as $entry) {
                if ($this->deduplicate && $entry['key'] === $previous_key) {
                    continue;
                }
                $previous_key = $entry['key'];
                $run_bytes += strlen($entry['raw_line']);
            }
            $this->write_bytes($this->output_handle, self::frame_header($run_bytes), 'work file');
            $previous_key = null;
            foreach ($entries as $entry) {
                if ($this->deduplicate && $entry['key'] === $previous_key) {
                    continue;
                }
                $previous_key = $entry['key'];
                $this->write_bytes($this->output_handle, $entry['raw_line'], 'work file');
            }
            $this->flush($this->output_handle, 'work file');
            ++$this->cursor['run_count'];
        }
        $slot_byte_offset = self::handle_offset($this->output_handle, 'work file');

        if ($reached_end) {
            $run_count = $this->cursor['run_count'];
            return $this->transition([
                'phase' => 'starting_merge',
                'input_slot' => 0,
                'input_run_count' => $run_count,
            ]);
        }
        $this->cursor['source_byte_offset'] = $source_byte_offset;
        $this->cursor['slot_byte_offset'] = $slot_byte_offset;
        return true;
    }

    private function start_merge_or_output(): bool
    {
        $input_slot = $this->cursor['input_slot'];
        $input_run_count = $this->cursor['input_run_count'];
        if ($input_run_count <= 1) {
            return $this->transition([
                'phase' => 'starting_output',
                'final_slot' => $input_slot,
                'final_run_count' => $input_run_count,
            ]);
        }
        return $this->transition([
            'phase' => 'merging_runs',
            'input_slot' => $input_slot,
            'input_run_count' => $input_run_count,
            'input_run_index' => 0,
            'input_byte_offset' => 0,
            'left_byte_offset' => null,
            'left_end_byte_offset' => null,
            'right_byte_offset' => null,
            'right_end_byte_offset' => null,
            'output_slot' => 1 - $input_slot,
            'output_byte_offset' => 0,
            'output_run_count' => 0,
            'output_run_header_byte_offset' => null,
            'previous_key_b64' => null,
        ]);
    }

    private function merge_next_row(): bool
    {
        if ($this->cursor['left_byte_offset'] === null) {
            if ($this->cursor['input_run_index'] === $this->cursor['input_run_count']) {
                return $this->finish_merge_pass();
            }
            return $this->start_next_run_pair();
        }

        if ($this->left_entry === null) {
            $this->left_entry = $this->read_keyed_run_line(
                $this->cursor['left_byte_offset'],
                $this->cursor['left_end_byte_offset']
            );
        }
        if ($this->cursor['right_byte_offset'] !== null && $this->right_entry === null) {
            $this->right_entry = $this->read_keyed_run_line(
                $this->cursor['right_byte_offset'],
                $this->cursor['right_end_byte_offset']
            );
        }
        $left = $this->left_entry;
        $right = $this->right_entry;
        if ($left === null && $right === null) {
            return $this->finish_run_pair();
        }
        if (
            $right === null
            || ( $left !== null && self::compare_entries($left, $right) <= 0 )
        ) {
            $selected = $left;
            $this->cursor['left_byte_offset'] = $left['next_byte_offset'];
            $this->left_entry = null;
        } else {
            $selected = $right;
            $this->cursor['right_byte_offset'] = $right['next_byte_offset'];
            $this->right_entry = null;
        }
        $previous_key = $this->cursor['previous_key_b64'] === null
            ? null
            : base64_decode($this->cursor['previous_key_b64'], true);
        if (!is_string($previous_key) && $previous_key !== null) {
            throw new UnexpectedValueException('External sort cursor previous key is invalid.');
        }
        if (!$this->deduplicate || $selected['key'] !== $previous_key) {
            $this->write_bytes($this->output_handle, $selected['raw_line'], 'work file');
            $this->flush($this->output_handle, 'work file');
            $this->cursor['output_byte_offset'] = self::handle_offset($this->output_handle, 'merge output');
            $this->cursor['previous_key_b64'] = base64_encode($selected['key']);
        }
        return true;
    }

    private function start_next_run_pair(): bool
    {
        $input_file_bytes = self::handle_size($this->input_handle, 'merge input');
        [$left_start, $left_end] = $this->read_run_range(
            $this->input_handle,
            $this->cursor['input_byte_offset'],
            $input_file_bytes
        );
        $right_start = null;
        $right_end = null;
        if ($this->cursor['input_run_index'] + 1 < $this->cursor['input_run_count']) {
            [$right_start, $right_end] = $this->read_run_range(
                $this->input_handle,
                $left_end,
                $input_file_bytes
            );
        }
        $header_byte_offset = $this->cursor['output_byte_offset'];
        $this->write_bytes($this->output_handle, self::frame_header(0), 'work file');
        $this->flush($this->output_handle, 'work file');
        $this->cursor['left_byte_offset'] = $left_start;
        $this->cursor['left_end_byte_offset'] = $left_end;
        $this->cursor['right_byte_offset'] = $right_start;
        $this->cursor['right_end_byte_offset'] = $right_end;
        $this->cursor['output_byte_offset'] = self::handle_offset($this->output_handle, 'merge output');
        $this->cursor['output_run_header_byte_offset'] = $header_byte_offset;
        return true;
    }

    private function finish_run_pair(): bool
    {
        $header_byte_offset = $this->cursor['output_run_header_byte_offset'];
        $run_bytes = $this->cursor['output_byte_offset']
            - $header_byte_offset - self::FRAME_HEADER_BYTES;
        if (
            fseek($this->output_handle, $header_byte_offset) !== 0
        ) {
            throw new RuntimeException('Failed to seek to the external sort run header.');
        }
        $this->write_bytes($this->output_handle, self::frame_header($run_bytes), 'work file');
        $this->flush($this->output_handle, 'work file');
        if (fseek($this->output_handle, $this->cursor['output_byte_offset']) !== 0) {
            throw new RuntimeException('Failed to restore the external sort merge output offset.');
        }
        $right_end = $this->cursor['right_end_byte_offset'];
        $this->cursor['input_byte_offset'] = $right_end ?? $this->cursor['left_end_byte_offset'];
        $this->cursor['input_run_index'] += $right_end === null ? 1 : 2;
        ++$this->cursor['output_run_count'];
        $this->cursor['left_byte_offset'] = null;
        $this->cursor['left_end_byte_offset'] = null;
        $this->cursor['right_byte_offset'] = null;
        $this->cursor['right_end_byte_offset'] = null;
        $this->cursor['output_run_header_byte_offset'] = null;
        $this->cursor['previous_key_b64'] = null;
        $this->left_entry = null;
        $this->right_entry = null;
        return true;
    }

    private function finish_merge_pass(): bool
    {
        if (
            $this->cursor['input_byte_offset']
                !== self::handle_size($this->input_handle, 'merge input')
        ) {
            throw new UnexpectedValueException('External sort merge input has trailing bytes.');
        }
        $output_slot = $this->cursor['output_slot'];
        $output_run_count = $this->cursor['output_run_count'];
        $input_slot = $this->cursor['input_slot'];
        if ($output_run_count === 1) {
            return $this->transition([
                'phase' => 'starting_output',
                'final_slot' => $output_slot,
                'final_run_count' => 1,
            ]);
        }
        return $this->transition([
            'phase' => 'merge_pass_complete',
            'next_input_slot' => $output_slot,
            'next_input_run_count' => $output_run_count,
            'obsolete_slot' => $input_slot,
        ]);
    }

    private function remove_obsolete_slot(): bool
    {
        $obsolete_file = $this->slot_file($this->cursor['obsolete_slot']);
        if (is_file($obsolete_file) && !unlink($obsolete_file)) {
            throw new RuntimeException("Failed to remove obsolete external sort slot: {$obsolete_file}.");
        }
        $input_slot = $this->cursor['next_input_slot'];
        $input_run_count = $this->cursor['next_input_run_count'];
        return $this->transition([
            'phase' => 'starting_merge',
            'input_slot' => $input_slot,
            'input_run_count' => $input_run_count,
        ]);
    }

    private function start_output_copy(): bool
    {
        $final_run_start_byte_offset = 0;
        $final_run_end_byte_offset = 0;
        if ($this->cursor['final_run_count'] === 1) {
            $input_handle = @fopen($this->slot_file($this->cursor['final_slot']), 'rb');
            if (!is_resource($input_handle)) {
                throw new RuntimeException('Failed to open the final external sort run.');
            }
            try {
                $file_bytes = self::handle_size($input_handle, 'final run');
                [$final_run_start_byte_offset, $final_run_end_byte_offset] =
                    $this->read_run_range($input_handle, 0, $file_bytes);
                if ($final_run_end_byte_offset !== $file_bytes) {
                    throw new UnexpectedValueException('Final external sort slot contains more than one run.');
                }
            } finally {
                fclose($input_handle);
            }
        }
        return $this->transition([
            'phase' => 'copying_output',
            'final_slot' => $this->cursor['final_slot'],
            'final_run_start_byte_offset' => $final_run_start_byte_offset,
            'final_run_byte_offset' => $final_run_start_byte_offset,
            'final_run_end_byte_offset' => $final_run_end_byte_offset,
            'output_byte_offset' => 0,
        ]);
    }

    private function copy_next_output_chunk(): bool
    {
        $remaining_bytes = $this->cursor['final_run_end_byte_offset']
            - $this->cursor['final_run_byte_offset'];
        if ($remaining_bytes === 0) {
            return $this->transition([
                'phase' => 'publishing_output',
            ]);
        }
        $chunk = fread($this->input_handle, min(self::OUTPUT_COPY_BYTES, $remaining_bytes));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Failed to read the next external sort output chunk.');
        }
        $this->write_bytes($this->output_handle, $chunk, 'output swap');
        $this->flush($this->output_handle, 'output swap');
        $this->cursor['final_run_byte_offset'] += strlen($chunk);
        $this->cursor['output_byte_offset'] += strlen($chunk);
        return true;
    }

    private function publish_output(): bool
    {
        $this->close_handles();
        $swap_file = $this->output_swap_file();
        if (is_file($swap_file)) {
            if (!@rename($swap_file, $this->output_file)) {
                throw new RuntimeException("Failed to publish external sort output: {$this->output_file}.");
            }
        } elseif (!is_file($this->output_file)) {
            throw new RuntimeException('External sort output disappeared before publish completed.');
        }
        return $this->transition([
            'phase' => 'cleaning_work_files',
            'next_cleanup_slot' => 0,
        ]);
    }

    private function remove_next_work_file(): bool
    {
        $slot = $this->cursor['next_cleanup_slot'];
        if ($slot < 2) {
            $slot_file = $this->slot_file($slot);
            if (is_file($slot_file) && !unlink($slot_file)) {
                throw new RuntimeException("Failed to remove external sort work file: {$slot_file}.");
            }
            ++$this->cursor['next_cleanup_slot'];
            return true;
        }
        $this->transition([
            'phase' => 'complete',
        ]);
        return false;
    }

    private function open_phase_handles(): void
    {
        if ($this->input_handle !== null || $this->output_handle !== null) {
            return;
        }
        if ($this->cursor['phase'] === 'building_runs') {
            $this->input_handle = $this->open_file(
                $this->source_file,
                $this->cursor['source_byte_offset'],
                'source file',
                false
            );
            $this->output_handle = $this->open_file(
                $this->slot_file(0),
                $this->cursor['slot_byte_offset'],
                'work file',
                true
            );
        } elseif ($this->cursor['phase'] === 'merging_runs') {
            $this->input_handle = $this->open_file(
                $this->slot_file($this->cursor['input_slot']),
                0,
                'merge input',
                false
            );
            $this->output_handle = $this->open_file(
                $this->slot_file($this->cursor['output_slot']),
                $this->cursor['output_byte_offset'],
                'merge output',
                true
            );
        } elseif ($this->cursor['phase'] === 'copying_output') {
            if ($this->cursor['final_run_end_byte_offset'] > 0) {
                $this->input_handle = $this->open_file(
                    $this->slot_file($this->cursor['final_slot']),
                    $this->cursor['final_run_byte_offset'],
                    'final run',
                    false
                );
            }
            $this->output_handle = $this->open_file(
                $this->output_swap_file(),
                $this->cursor['output_byte_offset'],
                'output swap',
                true
            );
        }
    }

    /** @return resource */
    private function open_file(
        string $file,
        int $byte_offset,
        string $name,
        bool $for_output
    )
    {
        $handle = @fopen($file, $for_output ? 'c+b' : 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Failed to open external sort {$name}: {$file}.");
        }
        if ($byte_offset > self::handle_size($handle, $name)) {
            fclose($handle);
            throw new UnexpectedValueException("External sort {$name} cursor exceeds its file: {$file}.");
        }
        if (
            ( $for_output && !ftruncate($handle, $byte_offset) )
            || fseek($handle, $byte_offset) !== 0
        ) {
            fclose($handle);
            throw new RuntimeException("Failed to resume external sort {$name}: {$file}.");
        }
        return $handle;
    }

    private function close_handles(): void
    {
        $close_failed = false;
        foreach (['input_handle', 'output_handle'] as $property) {
            if (is_resource($this->{$property}) && !@fclose($this->{$property})) {
                $close_failed = true;
            }
            $this->{$property} = null;
        }
        if ($close_failed) {
            throw new RuntimeException('Failed to close an external sort file.');
        }
    }

    /** @param Cursor $cursor */
    private function transition(array $cursor): bool
    {
        $this->close_handles();
        $this->left_entry = null;
        $this->right_entry = null;
        $cursor['config_fingerprint'] = $this->config_fingerprint;
        $this->cursor = $cursor;
        return true;
    }

    /** @return array{line:string,raw_line:string,next_byte_offset:int}|null */
    private function read_line($handle, ?int $range_end_byte_offset, string $name): ?array
    {
        $byte_offset = self::handle_offset($handle, $name);
        if ($range_end_byte_offset !== null) {
            if ($byte_offset === $range_end_byte_offset) {
                return null;
            }
            if ($byte_offset > $range_end_byte_offset) {
                throw new UnexpectedValueException("External sort {$name} cursor passed its run boundary.");
            }
        }
        $raw_line = fgets($handle, self::MAXIMUM_LINE_BYTES + 1);
        if ($raw_line === false) {
            if (feof($handle) && $range_end_byte_offset === null) {
                return null;
            }
            throw new RuntimeException("Failed to read the next external sort {$name} row.");
        }
        $next_byte_offset = self::handle_offset($handle, $name);
        $raw_line_bytes = strlen($raw_line);
        if (substr($raw_line, -1) !== "\n") {
            if (feof($handle)) {
                throw new UnexpectedValueException(
                    "External sort {$name} row at byte offset {$byte_offset} is unterminated after {$raw_line_bytes} bytes."
                );
            }
            throw new UnexpectedValueException(
                "External sort {$name} row at byte offset {$byte_offset} exceeds the maximum of "
                . self::MAXIMUM_LINE_BYTES . " bytes; read {$raw_line_bytes} bytes without LF."
            );
        }
        if ($range_end_byte_offset !== null && $next_byte_offset > $range_end_byte_offset) {
            throw new UnexpectedValueException(
                "External sort {$name} row at byte offset {$byte_offset} ends at byte offset "
                . "{$next_byte_offset}, beyond run boundary {$range_end_byte_offset}."
            );
        }
        return [
            'line' => substr($raw_line, 0, -1),
            'raw_line' => $raw_line,
            'next_byte_offset' => $next_byte_offset,
        ];
    }

    /** @return array{key:string,line:string,raw_line:string,next_byte_offset:int}|null */
    private function read_keyed_run_line(int $byte_offset, int $end_byte_offset): ?array
    {
        if (fseek($this->input_handle, $byte_offset) !== 0) {
            throw new RuntimeException('Failed to seek to the next external sort merge row.');
        }
        $entry = $this->read_line($this->input_handle, $end_byte_offset, 'merge input');
        if ($entry === null) {
            return null;
        }
        $key = ( $this->key_extractor )($entry['line']);
        if (!is_string($key)) {
            throw new UnexpectedValueException(
                sprintf(
                    'External sort key extractor returned %s for the retained work row at byte offset %d; expected string.',
                    gettype($key),
                    $byte_offset
                )
            );
        }
        if (strlen($key) > self::MAXIMUM_KEY_BYTES) {
            throw new UnexpectedValueException(
                sprintf(
                    'External sort key is %d bytes for the retained work row at byte offset %d; maximum is %d bytes.',
                    strlen($key),
                    $byte_offset,
                    self::MAXIMUM_KEY_BYTES
                )
            );
        }
        return ['key' => $key] + $entry;
    }

    /** @param array{key:string,line:string} $left @param array{key:string,line:string} $right */
    private static function compare_entries(array $left, array $right): int
    {
        $key_order = strcmp($left['key'], $right['key']);
        return $key_order !== 0 ? $key_order : strcmp($left['line'], $right['line']);
    }

    /** @return array{int,int} */
    private function read_run_range($handle, int $header_byte_offset, int $file_bytes): array
    {
        if (fseek($handle, $header_byte_offset) !== 0) {
            throw new RuntimeException('Failed to seek to an external sort run header.');
        }
        $header = fread($handle, self::FRAME_HEADER_BYTES);
        if (
            !is_string($header)
            || strlen($header) !== self::FRAME_HEADER_BYTES
            || preg_match('/^[0-7][0-9a-f]{15}\n$/D', $header) !== 1
        ) {
            throw new UnexpectedValueException('External sort run header is invalid.');
        }
        $run_bytes = intval(substr($header, 0, 16), 16);
        if ($run_bytes <= 0) {
            throw new UnexpectedValueException('External sort run must contain at least one row.');
        }
        $start_byte_offset = $header_byte_offset + self::FRAME_HEADER_BYTES;
        $end_byte_offset = $start_byte_offset + $run_bytes;
        if ($end_byte_offset < $start_byte_offset || $end_byte_offset > $file_bytes) {
            throw new UnexpectedValueException('External sort run exceeds its work file.');
        }
        return [$start_byte_offset, $end_byte_offset];
    }

    private static function frame_header(int $run_bytes): string
    {
        if ($run_bytes < 0) {
            throw new LogicException('External sort run size cannot be negative.');
        }
        $header = sprintf('%016x', $run_bytes) . "\n";
        if (strlen($header) !== self::FRAME_HEADER_BYTES) {
            throw new RuntimeException('External sort run is too large to frame.');
        }
        return $header;
    }

    private function write_bytes($handle, string $bytes, string $name): void
    {
        $written_bytes = 0;
        $total_bytes = strlen($bytes);
        while ($written_bytes < $total_bytes) {
            $result = @fwrite($handle, substr($bytes, $written_bytes));
            if (!is_int($result) || $result <= 0) {
                throw new RuntimeException("Failed to write external sort {$name}.");
            }
            $written_bytes += $result;
        }
    }

    private function flush($handle, string $name): void
    {
        if (!fflush($handle)) {
            throw new RuntimeException("Failed to flush external sort {$name}.");
        }
    }

    private function slot_file(int $slot): string
    {
        return rtrim($this->work_directory, '/') . '/' . $this->work_name . '.slot-' . $slot;
    }

    private function output_swap_file(): string
    {
        return $this->output_file . '.external-sort.swap';
    }

    private static function file_size(string $file, string $name): int
    {
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Failed to open external sort {$name}: {$file}.");
        }
        try {
            return self::handle_size($handle, $name);
        } finally {
            fclose($handle);
        }
    }

    private static function prepare_work_directory(string $work_directory): string
    {
        if (
            !is_dir($work_directory)
            && !mkdir($work_directory, 0777, true)
            && !is_dir($work_directory)
        ) {
            throw new RuntimeException("Failed to create external sort work directory: {$work_directory}.");
        }
        $trimmed_directory = rtrim($work_directory, '/');
        return $trimmed_directory === '' ? '/' : $trimmed_directory;
    }

    private static function assert_output_is_not_symlink(string $output_file): void
    {
        if (is_link($output_file)) {
            throw new InvalidArgumentException('External sort output must not be a symbolic link.');
        }
    }

    private static function configuration_fingerprint(
        string $source_file,
        string $output_file,
        string $work_directory,
        string $work_name,
        bool $deduplicate,
        int $source_file_bytes
    ): string {
        $source_entry = self::directory_entry_location($source_file);
        $source_target = realpath($source_file);
        if (!is_string($source_target)) {
            throw new InvalidArgumentException("External sort source file does not exist: {$source_file}.");
        }
        $destination_entries = [
            self::directory_entry_location($output_file),
            self::directory_entry_location($output_file . '.external-sort.swap'),
            self::directory_entry_location($work_directory . '/' . $work_name . '.slot-0'),
            self::directory_entry_location($work_directory . '/' . $work_name . '.slot-1'),
        ];
        $locations = array_merge([$source_entry], $destination_entries);
        if (count($locations) !== count(array_unique($locations))) {
            throw new InvalidArgumentException('External sort source, output, swap, and work slots must be distinct.');
        }
        if (in_array($source_target, $destination_entries, true)) {
            throw new InvalidArgumentException('External sort source target must be distinct from output, swap, and work slots.');
        }
        $framed_configuration = '';
        foreach ([
            $source_entry,
            $source_target,
            $destination_entries[0],
            (string) realpath($work_directory),
            $work_name,
            $deduplicate ? "\x01" : "\x00",
            (string) $source_file_bytes,
        ] as $value) {
            $framed_configuration .= pack('N', strlen($value)) . $value;
        }
        return hash('sha256', $framed_configuration);
    }

    /** Identifies the directory entry without following its final symlink. */
    private static function directory_entry_location(string $file): string
    {
        $resolved_directory = realpath(dirname($file));
        if (!is_string($resolved_directory)) {
            throw new InvalidArgumentException("External sort file directory does not exist: {$file}.");
        }
        return $resolved_directory . '/' . basename($file);
    }

    private static function handle_size($handle, string $name): int
    {
        $stat = fstat($handle);
        if (!is_array($stat) || !isset($stat['size']) || !is_int($stat['size'])) {
            throw new RuntimeException("Failed to read external sort {$name} size.");
        }
        return $stat['size'];
    }

    private static function handle_offset($handle, string $name): int
    {
        $byte_offset = ftell($handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException("Failed to read external sort {$name} offset.");
        }
        return $byte_offset;
    }

    /** Strictly validates the JSON-safe cursor before opening work files. */
    private function assert_cursor(): void
    {
        if (!isset($this->cursor['phase']) || !is_string($this->cursor['phase'])) {
            throw new InvalidArgumentException('External sort cursor phase is invalid.');
        }
        $phase = $this->cursor['phase'];
        $keys_by_phase = [
            'building_runs' => ['phase', 'source_byte_offset', 'slot_byte_offset', 'run_count'],
            'starting_merge' => ['phase', 'input_slot', 'input_run_count'],
            'merging_runs' => ['phase', 'input_slot', 'input_run_count', 'input_run_index', 'input_byte_offset', 'left_byte_offset', 'left_end_byte_offset', 'right_byte_offset', 'right_end_byte_offset', 'output_slot', 'output_byte_offset', 'output_run_count', 'output_run_header_byte_offset', 'previous_key_b64'],
            'merge_pass_complete' => ['phase', 'next_input_slot', 'next_input_run_count', 'obsolete_slot'],
            'starting_output' => ['phase', 'final_slot', 'final_run_count'],
            'copying_output' => ['phase', 'final_slot', 'final_run_start_byte_offset', 'final_run_byte_offset', 'final_run_end_byte_offset', 'output_byte_offset'],
            'publishing_output' => ['phase'],
            'cleaning_work_files' => ['phase', 'next_cleanup_slot'],
            'complete' => ['phase'],
        ];
        if (!isset($keys_by_phase[$phase])) {
            throw new InvalidArgumentException('External sort cursor phase is invalid.');
        }
        $actual_keys = array_keys($this->cursor);
        $expected_keys = $keys_by_phase[$phase];
        $expected_keys[] = 'config_fingerprint';
        sort($actual_keys);
        sort($expected_keys);
        if ($actual_keys !== $expected_keys) {
            throw new InvalidArgumentException("External sort {$phase} cursor fields are invalid.");
        }
        foreach ($this->cursor as $field => $value) {
            if (
                $field !== 'phase'
                && $field !== 'config_fingerprint'
                && $field !== 'previous_key_b64'
                && $value !== null
            ) {
                if (!is_int($value) || $value < 0) {
                    throw new InvalidArgumentException("External sort cursor {$field} must be a nonnegative integer.");
                }
            }
        }
        if (
            !is_string($this->cursor['config_fingerprint'])
            || preg_match('/^[0-9a-f]{64}$/D', $this->cursor['config_fingerprint']) !== 1
        ) {
            throw new InvalidArgumentException('External sort cursor configuration fingerprint is invalid.');
        }
        foreach (['input_slot', 'output_slot', 'next_input_slot', 'obsolete_slot', 'final_slot'] as $field) {
            if (isset($this->cursor[$field]) && !in_array($this->cursor[$field], [0, 1], true)) {
                throw new InvalidArgumentException("External sort cursor {$field} is invalid.");
            }
        }
        if ($phase === 'building_runs') {
            if ($this->cursor['source_byte_offset'] > $this->source_file_bytes) {
                throw new InvalidArgumentException('External sort source cursor exceeds the source size.');
            }
            return;
        }
        if ($phase === 'starting_merge') {
            return;
        }
        if ($phase === 'merging_runs') {
            $this->assert_merging_cursor();
            return;
        }
        if ($phase === 'merge_pass_complete') {
            if (
                $this->cursor['next_input_run_count'] <= 1
                || $this->cursor['next_input_slot'] === $this->cursor['obsolete_slot']
            ) {
                throw new InvalidArgumentException('External sort completed-pass cursor is inconsistent.');
            }
            return;
        }
        if ($phase === 'starting_output') {
            if (!in_array($this->cursor['final_run_count'], [0, 1], true)) {
                throw new InvalidArgumentException('External sort final run count is invalid.');
            }
            return;
        }
        if ($phase === 'copying_output') {
            if (
                $this->cursor['final_run_start_byte_offset'] > $this->cursor['final_run_byte_offset']
                || $this->cursor['final_run_byte_offset'] > $this->cursor['final_run_end_byte_offset']
                || $this->cursor['output_byte_offset']
                    !== $this->cursor['final_run_byte_offset']
                        - $this->cursor['final_run_start_byte_offset']
            ) {
                throw new InvalidArgumentException('External sort output-copy cursor is inconsistent.');
            }
            return;
        }
        if (
            $phase === 'cleaning_work_files'
            && $this->cursor['next_cleanup_slot'] > 2
        ) {
            throw new InvalidArgumentException('External sort cleanup cursor is invalid.');
        }
    }

    private function assert_merging_cursor(): void
    {
        if (
            $this->cursor['input_run_count'] <= 1
            || $this->cursor['input_run_index'] > $this->cursor['input_run_count']
            || $this->cursor['input_slot'] === $this->cursor['output_slot']
            || $this->cursor['output_run_count']
                !== intdiv($this->cursor['input_run_index'] + 1, 2)
        ) {
            throw new InvalidArgumentException('External sort merge cursor counts are inconsistent.');
        }
        $has_pair = $this->cursor['left_byte_offset'] !== null;
        if (
            $has_pair !== ( $this->cursor['left_end_byte_offset'] !== null )
            || $has_pair !== ( $this->cursor['output_run_header_byte_offset'] !== null )
            || ( $this->cursor['right_byte_offset'] === null )
                !== ( $this->cursor['right_end_byte_offset'] === null )
            || ( !$has_pair && (
                $this->cursor['right_byte_offset'] !== null
                || $this->cursor['previous_key_b64'] !== null
            ) )
        ) {
            throw new InvalidArgumentException('External sort merge cursor ranges are inconsistent.');
        }
        foreach ([
            ['left_byte_offset', 'left_end_byte_offset'],
            ['right_byte_offset', 'right_end_byte_offset'],
        ] as [$offset_field, $end_field]) {
            if (
                $this->cursor[$offset_field] !== null
                && $this->cursor[$offset_field] > $this->cursor[$end_field]
            ) {
                throw new InvalidArgumentException('External sort merge cursor passed a run boundary.');
            }
        }
        if (
            $this->cursor['output_run_header_byte_offset'] !== null
            && $this->cursor['output_run_header_byte_offset']
                + self::FRAME_HEADER_BYTES > $this->cursor['output_byte_offset']
        ) {
            throw new InvalidArgumentException('External sort merge output cursor is inconsistent.');
        }
        $previous_key = $this->cursor['previous_key_b64'];
        if ($previous_key !== null) {
            if (
                !is_string($previous_key)
                || base64_encode( (string) base64_decode($previous_key, true)) !== $previous_key
            ) {
                throw new InvalidArgumentException('External sort cursor previous key is not canonical base64.');
            }
        }
    }
}
