<?php

namespace Reprint\Importer;

use RuntimeException;
use function WordPress\Reprint\Server\assert_valid_path;
use function WordPress\Reprint\Server\parse_size;

require_once __DIR__ . '/external-merge-sort.php';

/**
 * Sort an index file by path or a caller-provided key.
 *
 * The fast path prepends a hex-encoded sort key to each line, shells out to
 * `sort(1)`, then strips the keys. This handles arbitrarily large files with
 * no PHP memory pressure. When exec() is unavailable or the command fails,
 * the fallback uses an external merge sort with bounded memory.
 * Paths may be remote absolute paths or local relative paths. A caller may
 * provide another key when an index row needs more than its `path` field to
 * define its sort order.
 *
 * Duplicate keys are removed during the final write pass. Duplicate paths
 * arise when overlapping symlink targets index the same files.
 *
 * @param string                        $path         The JSONL index file to sort in place.
 * @param callable(string):?string|null $get_sort_key Returns the sort key for a
 *                                                    JSONL line. Defaults to
 *                                                    its decoded path.
 * @return bool True when the index file was sorted or was empty, false when
 *              the index file does not exist.
 */
function sort_index_file(
    string $path,
    ?callable $get_sort_key = null
): bool
{
    if (!file_exists($path)) {
        return false;
    }
    if (filesize($path) === 0) {
        return true;
    }

    if ($get_sort_key === null) {
        $get_sort_key = static function (string $line): ?string {
            $line = trim($line);
            if ($line === '') {
                return null;
            }

            $data = json_decode($line, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid index line format');
            }

            $path_encoded = $data['path'] ?? '';
            if (!is_string($path_encoded) || $path_encoded === '') {
                throw new RuntimeException('Invalid index path');
            }

            $index_path = base64_decode($path_encoded, true);
            if ($index_path === '' || $index_path === false) {
                throw new RuntimeException('Invalid index path (base64 decode failed)');
            }

            assert_valid_path(
                $index_path[0] === '/' ? $index_path : '/' . $index_path,
                'index path'
            );
            return $index_path;
        };
    }

    $tmp = $path . '.sorted';

    // Fast path: shell out to `sort` for O(n log n) with no memory pressure.
    // If anything goes wrong, fall through to the pure-PHP external merge
    // sort below.
    if (
        try_exec_sort_index_file(
            $path,
            $tmp,
            $get_sort_key
        )
    ) {
        return true;
    }

    // Pure-PHP fallback: external merge sort. Splits the file into
    // memory-sized chunks, sorts each in memory, then streams a k-way merge.
    // Handles files of any size without exec().
    $memory_limit_raw = ini_get('memory_limit');
    $memory_limit = ($memory_limit_raw === '-1' || $memory_limit_raw === '' || $memory_limit_raw === '0')
        ? 0
        : parse_size($memory_limit_raw);
    $memory_used = memory_get_usage(true);
    $available_memory = $memory_limit > 0
        ? (int) (($memory_limit - $memory_used) * 0.6)
        : 256 * 1024 * 1024;

    $sorter = new \ExternalMergeSort(
        $get_sort_key,
        max(1024, (int) ($available_memory * 0.8)),
        true,
        dirname($path),
    );
    $sorter->sort($path);
    return true;
}

/**
 * Attempt to sort an index file with the system `sort(1)` command.
 *
 * @param string                   $path             The JSONL index file to sort.
 * @param string                   $temporary_output Path to write before replacing $path.
 * @param callable(string):?string $get_sort_key     Returns the sort key for a JSONL line.
 * @return bool True when the system command sorted and replaced the file.
 */
function try_exec_sort_index_file(
    string $path,
    string $temporary_output,
    callable $get_sort_key
): bool {
    $exec_is_available = function_exists('exec') && !in_array(
        'exec',
        array_map('trim', explode(',', (string) ini_get('disable_functions'))),
        true
    );
    if (!$exec_is_available) {
        return false;
    }

    $keyed = $path . '.keyed';
    $sorted_keyed = $path . '.keyed.sorted';
    $input = fopen($path, 'r');
    $output = fopen($keyed, 'w');
    if (!$input || !$output) {
        if ($input) {
            fclose($input);
        }
        if ($output) {
            fclose($output);
        }
        return false;
    }

    while (($line = fgets($input)) !== false) {
        $line = rtrim($line, "\r\n");
        $sort_key = $get_sort_key($line);
        if ($sort_key === null) {
            continue;
        }
        fwrite($output, bin2hex($sort_key) . "\t" . $line . "\n");
    }
    fclose($input);
    fclose($output);

    $command =
        "LC_ALL=C sort -t '\t' -k1,1 " .
        escapeshellarg($keyed) .
        ' > ' .
        escapeshellarg($sorted_keyed);
    $command_output = [];
    $command_exit_code = 0;
    exec($command, $command_output, $command_exit_code);
    if ($command_exit_code !== 0) {
        @unlink($keyed);
        @unlink($sorted_keyed);
        return false;
    }

    $sorted_input = fopen($sorted_keyed, 'r');
    $sorted_output = fopen($temporary_output, 'w');
    if (!$sorted_input || !$sorted_output) {
        if ($sorted_input) {
            fclose($sorted_input);
        }
        if ($sorted_output) {
            fclose($sorted_output);
        }
        @unlink($keyed);
        @unlink($sorted_keyed);
        return false;
    }

    $previous_key = null;
    while (($line = fgets($sorted_input)) !== false) {
        $tab_position = strpos($line, "\t");
        if ($tab_position === false) {
            continue;
        }
        $key = substr($line, 0, $tab_position);
        $data = substr($line, $tab_position + 1);
        if ($data === '') {
            continue;
        }
        if ($key === $previous_key) {
            continue;
        }
        $previous_key = $key;
        fwrite($sorted_output, $data);
    }
    fclose($sorted_input);
    fclose($sorted_output);
    @unlink($keyed);
    @unlink($sorted_keyed);
    if (!rename($temporary_output, $path)) {
        throw new RuntimeException('Failed to replace sorted index file');
    }
    return true;
}
