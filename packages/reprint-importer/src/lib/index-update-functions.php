<?php

namespace Reprint\Importer;

use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\compare_paths;
use function WordPress\Reprint\Exporter\path_sort_key;

/**
 * Merges sorted updates into the absolute-path import index.
 */
function merge_import_index_updates(
    string $base_index,
    string $updates_path,
    string $output_index
): void {
    merge_index_updates(
        $base_index,
        $updates_path,
        $output_index,
        false
    );
}

/**
 * Sorts and merges updates into the document-root-relative previous local index.
 */
function merge_previous_local_index_updates(
    string $base_index,
    string $updates_path,
    string $output_index,
    string $temporary_directory
): void {
    $sorter = new \ExternalMergeSort(
        static function (string $line): string {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode($entry['path'], true);
            if ($path === false) {
                throw new \RuntimeException(
                    'A previous-local-index update path is not valid base64.'
                );
            }
            return path_sort_key($path)
                . "\0\0"
                . sprintf('%020d', (int) $entry['position']);
        },
        8 * 1024 * 1024,
        false,
        $temporary_directory
    );
    $sorter->sort($updates_path);

    merge_index_updates(
        $base_index,
        $updates_path,
        $output_index,
        true
    );
}

/**
 * Streams one sorted F/D update file into one sorted index.
 */
function merge_index_updates(
    string $base_index,
    string $updates_path,
    string $output_index,
    bool $previous_local_index
): void {
    $base_handle = is_file($base_index) ? fopen($base_index, 'rb') : null;
    $updates_handle = fopen($updates_path, 'rb');
    $output_handle = fopen($output_index, 'wb');
    if (
        ( is_file($base_index) && !is_resource($base_handle) )
        || !is_resource($updates_handle)
        || !is_resource($output_handle)
    ) {
        throw new \RuntimeException('Failed to merge index updates.');
    }

    $read_index_entry = static function ($handle) use (
        $previous_local_index
    ): ?array {
        if (!is_resource($handle)) {
            return null;
        }
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                if (!feof($handle)) {
                    throw new \RuntimeException('Failed to read an index entry.');
                }
                return null;
            }
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode($data['path'], true);
            if ($path === false) {
                throw new \RuntimeException('An index path is not valid base64.');
            }
            if (!$previous_local_index) {
                assert_valid_path($path, 'index path');
            }
            $entry = [
                'path' => $path,
                'ctime' => (int) $data['ctime'],
                'size' => (int) $data['size'],
                'type' => (string) $data['type'],
            ];
            if (array_key_exists('empty', $data)) {
                $entry['empty'] = (bool) $data['empty'];
            }
            return $entry;
        }
    };
    $read_update_entry_raw = static function ($handle): ?array {
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                if (!feof($handle)) {
                    throw new \RuntimeException(
                        'Failed to read an index-update entry.'
                    );
                }
                return null;
            }
            if (substr($line, -1) !== "\n" && feof($handle)) {
                return null;
            }
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode($data['path'], true);
            if ($path === false) {
                throw new \RuntimeException(
                    'An index-update path is not valid base64.'
                );
            }
            if ($data['op'] === 'D') {
                return [
                    'path' => $path,
                    'delete' => true,
                    'ctime' => 0,
                    'size' => 0,
                    'type' => null,
                    'replace_subtree' => !empty($data['replace_subtree']),
                ];
            }
            return [
                'path' => $path,
                'delete' => false,
                'ctime' => (int) $data['ctime'],
                'size' => (int) $data['size'],
                'type' => (string) $data['type'],
                'replace_subtree' => !empty($data['replace_subtree']),
            ];
        }
    };
    $update_carry = null;
    $read_update_entry = static function () use (
        $updates_handle,
        $read_update_entry_raw,
        &$update_carry
    ): ?array {
        $current = $update_carry ?? $read_update_entry_raw($updates_handle);
        $update_carry = null;
        if ($current === null) {
            return null;
        }
        while (true) {
            $next = $read_update_entry_raw($updates_handle);
            if ($next === null) {
                return $current;
            }
            if ($next['path'] !== $current['path']) {
                $update_carry = $next;
                return $current;
            }
            $replace_subtree =
                $current['replace_subtree'] || $next['replace_subtree'];
            $current = $next;
            $current['replace_subtree'] = $replace_subtree;
        }
    };
    $write_index_entry = static function ($handle, array $entry): void {
        $encoded = [
            'path' => base64_encode($entry['path']),
            'ctime' => (int) $entry['ctime'],
            'size' => (int) $entry['size'],
            'type' => (string) $entry['type'],
        ];
        if ($entry['type'] === 'dir' && array_key_exists('empty', $entry)) {
            $encoded['empty'] = (bool) $entry['empty'];
        }
        $line = json_encode(
            $encoded,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new \RuntimeException('Failed to write a merged index entry.');
        }
    };

    $pending_directory = null;
    $last_entry_path = null;
    $write_entry = static function (array $entry) use (
        $output_handle,
        $previous_local_index,
        $write_index_entry,
        &$pending_directory,
        &$last_entry_path
    ): void {
        if ($entry['path'] === $last_entry_path) {
            return;
        }
        $last_entry_path = $entry['path'];
        if ($previous_local_index && $pending_directory !== null) {
            if (
                strpos(
                    $entry['path'],
                    $pending_directory['path'] . '/'
                ) === 0
            ) {
                $pending_directory['empty'] = false;
            }
            $write_index_entry($output_handle, $pending_directory);
            $pending_directory = null;
        }
        if ($previous_local_index && $entry['type'] === 'dir') {
            $entry['empty'] = true;
            $pending_directory = $entry;
            return;
        }
        $write_index_entry($output_handle, $entry);
    };

    try {
        $base = $read_index_entry($base_handle);
        $update = $read_update_entry();
        $replaced_subtree = null;
        while ($base !== null || $update !== null) {
            if ($base !== null && $replaced_subtree !== null) {
                if (
                    $base['path'] === $replaced_subtree
                    || strpos(
                        $base['path'],
                        $replaced_subtree . '/'
                    ) === 0
                ) {
                    $base = $read_index_entry($base_handle);
                    continue;
                }
                $subtree_comparison = $previous_local_index
                    ? compare_paths($base['path'], $replaced_subtree)
                    : strcmp($base['path'], $replaced_subtree);
                if ($subtree_comparison > 0) {
                    $replaced_subtree = null;
                }
            }

            if ($update === null) {
                $write_entry($base);
                $base = $read_index_entry($base_handle);
                continue;
            }
            if ($base === null) {
                if (!$update['delete']) {
                    $write_entry($update);
                }
                if (
                    $update['replace_subtree']
                    && (
                        $replaced_subtree === null
                        || strpos(
                            $update['path'],
                            $replaced_subtree . '/'
                        ) !== 0
                    )
                ) {
                    $replaced_subtree = $update['path'];
                }
                $update = $read_update_entry();
                continue;
            }

            $comparison = $previous_local_index
                ? compare_paths($base['path'], $update['path'])
                : strcmp($base['path'], $update['path']);
            if ($comparison < 0) {
                $write_entry($base);
                $base = $read_index_entry($base_handle);
                continue;
            }
            if (!$update['delete']) {
                $write_entry($update);
            }
            if (
                $update['replace_subtree']
                && (
                    $replaced_subtree === null
                    || strpos(
                        $update['path'],
                        $replaced_subtree . '/'
                    ) !== 0
                )
            ) {
                $replaced_subtree = $update['path'];
            }
            $update = $read_update_entry();
            if ($comparison === 0) {
                $base = $read_index_entry($base_handle);
            }
        }
        if ($pending_directory !== null) {
            $write_index_entry($output_handle, $pending_directory);
        }
        if (!fflush($output_handle)) {
            throw new \RuntimeException('Failed to flush the merged index.');
        }
    } finally {
        if (is_resource($base_handle)) {
            fclose($base_handle);
        }
        fclose($updates_handle);
        fclose($output_handle);
    }
}
