<?php

declare(strict_types=1);

/**
 * Sorts a text file line-by-line using an external merge sort when the file
 * is too large to fit in memory.  Works in pure PHP — no exec() or external
 * binaries required.
 *
 * Algorithm:
 *   1. Split the input into chunks that fit within a memory budget.
 *   2. Sort each chunk in memory, write to a temp file.
 *   3. K-way merge the sorted chunks back into a single output file,
 *      optionally deduplicating consecutive lines with the same key.
 *
 * The caller provides a key-extractor callable that turns a raw line into
 * a sort key string (or null to skip the line).  Lines are compared with
 * strcmp() on extracted keys, matching the existing in-memory sort.
 */
class ExternalMergeSort
{
    /**
     * @param callable(string): ?string $key_extractor
     *   Given a raw line (without trailing newline), return the sort key
     *   string, or null to skip the line entirely.
     */
    private $key_extractor;

    /** @var int Maximum bytes of line data to hold in memory per chunk. */
    private int $chunk_budget;

    /** @var bool Whether to drop consecutive lines with the same key. */
    private bool $deduplicate;

    /** @var string Directory for temporary chunk files. */
    private string $tmp_dir;

    /**
     * @param callable(string): ?string $key_extractor
     * @param int  $chunk_budget  Maximum bytes of line data per in-memory chunk.
     * @param bool $deduplicate   Drop consecutive duplicate keys in output.
     * @param string|null $tmp_dir  Directory for temp files (default: sys_get_temp_dir).
     */
    public function __construct(
        callable $key_extractor,
        int $chunk_budget,
        bool $deduplicate = true,
        ?string $tmp_dir = null
    ) {
        if ($chunk_budget < 1024) {
            throw new InvalidArgumentException("chunk_budget must be at least 1024 bytes");
        }
        $this->key_extractor = $key_extractor;
        $this->chunk_budget = $chunk_budget;
        $this->deduplicate = $deduplicate;
        $this->tmp_dir = $tmp_dir ?? sys_get_temp_dir();
    }

    /**
     * Sort $input_path in place (or to $output_path if given).
     *
     * @param string      $input_path   Path to the unsorted file.
     * @param string|null $output_path  Where to write sorted output.
     *                                  When null, replaces $input_path.
     */
    public function sort(string $input_path, ?string $output_path = null): void
    {
        $replace_in_place = ($output_path === null);
        if ($replace_in_place) {
            $output_path = $input_path . '.merge-sorted';
        }

        $chunk_files = [];
        try {
            $chunk_files = $this->split_and_sort_chunks($input_path);

            if (count($chunk_files) === 0) {
                // Empty or all-skipped input — write an empty output.
                file_put_contents($output_path, '');
            } elseif (count($chunk_files) === 1) {
                // Single chunk — already sorted, just move it.
                if (!rename($chunk_files[0], $output_path)) {
                    throw new RuntimeException("Failed to move sorted chunk to output");
                }
                $chunk_files = [];
            } else {
                $this->merge_chunks($chunk_files, $output_path);
            }
        } finally {
            foreach ($chunk_files as $f) {
                @unlink($f);
            }
        }

        if ($replace_in_place) {
            if (!rename($output_path, $input_path)) {
                @unlink($output_path);
                throw new RuntimeException("Failed to replace input with sorted output");
            }
        }
    }

    /**
     * Read the input file in chunks, sort each chunk in memory, and write
     * each to a temp file.  Returns the list of temp file paths.
     *
     * @return string[]
     */
    private function split_and_sort_chunks(string $input_path): array
    {
        $fh = fopen($input_path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Failed to open input file: {$input_path}");
        }

        $chunk_files = [];
        try {
            $extract = $this->key_extractor;

            // Accumulate lines for the current chunk.
            // Each entry: ['key' => string, 'line' => string]
            $chunk = [];
            $chunk_bytes = 0;

            while (($raw = fgets($fh)) !== false) {
                $line = rtrim($raw, "\r\n");
                if ($line === '') {
                    continue;
                }
                $key = $extract($line);
                if ($key === null) {
                    continue;
                }

                $line_bytes = strlen($line);
                // If adding this line would exceed the budget, flush the
                // current chunk first (unless the chunk is empty — a single
                // line that exceeds the budget must still be accepted).
                if ($chunk_bytes > 0 && $chunk_bytes + $line_bytes > $this->chunk_budget) {
                    $chunk_files[] = $this->flush_chunk($chunk);
                    $chunk = [];
                    $chunk_bytes = 0;
                }

                $chunk[] = ['key' => $key, 'line' => $line];
                $chunk_bytes += $line_bytes;
            }

            if (count($chunk) > 0) {
                $chunk_files[] = $this->flush_chunk($chunk);
            }
        } catch (\Throwable $e) {
            // Clean up any chunks we already wrote before re-throwing.
            foreach ($chunk_files as $f) {
                @unlink($f);
            }
            fclose($fh);
            throw $e;
        }

        fclose($fh);
        return $chunk_files;
    }

    /**
     * Sort a chunk in memory and write it to a temp file.
     * Returns the path to the temp file.
     *
     * @param array[] $chunk {
     *     Sorted chunk entries.
     *
     *     @type string $key  Sort key.
     *     @type string $line Original line.
     * }
     * @phpstan-param array{key: string, line: string}[] $chunk
     */
    private function flush_chunk(array $chunk): string
    {
        usort($chunk, fn($a, $b) => strcmp($a['key'], $b['key']));

        $tmp = tempnam($this->tmp_dir, 'merge-chunk-');
        if ($tmp === false) {
            throw new RuntimeException("Failed to create temp file for merge sort chunk");
        }

        $fh = fopen($tmp, 'w');
        if ($fh === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to open temp chunk file for writing");
        }

        $prev_key = null;
        foreach ($chunk as $entry) {
            if ($this->deduplicate && $entry['key'] === $prev_key) {
                continue;
            }
            $prev_key = $entry['key'];
            fwrite($fh, $entry['line'] . "\n");
        }
        fclose($fh);
        return $tmp;
    }

    /**
     * K-way merge of sorted chunk files into a single output file.
     *
     * Uses a simple tournament / min-heap approach: maintain one "current
     * line + key" per open chunk, pick the smallest, advance that chunk,
     * repeat.  For the typical number of chunks (< 100) the linear scan
     * is fast enough; a proper heap would help if we had thousands.
     *
     * @param string[] $chunk_files
     */
    private function merge_chunks(array $chunk_files, string $output_path): void
    {
        // Open all chunk files and prime each with its first entry.
        $handles = [];
        $current = []; // index => ['key' => string, 'line' => string] | null
        $extract = $this->key_extractor;

        foreach ($chunk_files as $i => $path) {
            $fh = fopen($path, 'r');
            if ($fh === false) {
                // Close anything we already opened.
                foreach ($handles as $h) {
                    fclose($h);
                }
                throw new RuntimeException("Failed to open chunk file: {$path}");
            }
            $handles[$i] = $fh;
            $current[$i] = $this->read_next($fh, $extract);
        }

        $out = fopen($output_path, 'w');
        if ($out === false) {
            foreach ($handles as $h) {
                fclose($h);
            }
            throw new RuntimeException("Failed to open output file: {$output_path}");
        }

        $prev_key = null;

        while (true) {
            // Find the chunk with the smallest current key.
            $min_idx = null;
            $min_key = null;
            foreach ($current as $i => $entry) {
                if ($entry === null) {
                    continue;
                }
                if ($min_idx === null || strcmp($entry['key'], $min_key) < 0) {
                    $min_idx = $i;
                    $min_key = $entry['key'];
                }
            }
            if ($min_idx === null) {
                break; // All chunks exhausted.
            }

            $entry = $current[$min_idx];
            // Deduplicate across chunks: skip if same key as previous output.
            if (!$this->deduplicate || $entry['key'] !== $prev_key) {
                fwrite($out, $entry['line'] . "\n");
                $prev_key = $entry['key'];
            }

            // Advance the chunk we just consumed from.
            $current[$min_idx] = $this->read_next($handles[$min_idx], $extract);
        }

        fclose($out);
        foreach ($handles as $h) {
            fclose($h);
        }
    }

    /**
     * Read the next valid entry from a chunk file handle.
     *
     * @param resource $fh
     * @param callable(string): ?string $extract
     * @return array|null {
     *     Next keyed line, or null when EOF.
     *
     *     @type string $key  Sort key.
     *     @type string $line Original line.
     * }
     * @phpstan-return array{key: string, line: string}|null
     */
    private function read_next($fh, callable $extract): ?array
    {
        while (($raw = fgets($fh)) !== false) {
            $line = rtrim($raw, "\r\n");
            if ($line === '') {
                continue;
            }
            $key = $extract($line);
            if ($key === null) {
                continue;
            }
            return ['key' => $key, 'line' => $line];
        }
        return null;
    }
}
