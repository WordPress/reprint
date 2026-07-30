<?php

namespace Reprint\Importer;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions contain CLI filesystem paths, never HTML output.

/**
 * Sorts one update batch, merges it into the local index, and replaces the
 * index atomically.
 */
function apply_local_index_updates(
    string $local_index_path,
    string $updates_path
): void {
    $prepared_updates_path = $updates_path . '.prepared';
    $swap_path = $local_index_path . '.swap';
    $local_index_directory = dirname($local_index_path);
    if (
        !is_dir($local_index_directory)
        && !mkdir($local_index_directory, 0755, true)
    ) {
        throw new \RuntimeException(
            'Failed to create the local-index directory: '
            . $local_index_directory
            . '.'
        );
    }

    try {
        $updates_handle = null;
        $prepared_updates_handle = null;
        try {
            $updates_handle = fopen($updates_path, 'rb');
            $prepared_updates_handle = fopen($prepared_updates_path, 'wb');
            if (
                !is_resource($updates_handle)
                || !is_resource($prepared_updates_handle)
            ) {
                throw new \RuntimeException(
                    'Failed to prepare the local-index updates.'
                );
            }
            $operation_position = 0;
            while (true) {
                $line = fgets($updates_handle);
                if ($line === false) {
                    break;
                }
                $update = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $update['operation_position'] = $operation_position;
                write_local_index_update($prepared_updates_handle, $update);

                if ($update['op'] === 'F') {
                    /** @var string $path */
                    $path = base64_decode($update['path']);
                    /*
                     * Directory ctime and size do not select push work. Zeroes
                     * let the generated ancestors use the normal index shape.
                     */
                    while (true) {
                        $separator = strrpos($path, '/');
                        if ($separator === false) {
                            break;
                        }
                        $path = substr($path, 0, $separator);
                        write_local_index_update($prepared_updates_handle, [
                            'op' => 'F',
                            'path' => base64_encode($path),
                            'ctime' => 0,
                            'size' => 0,
                            'type' => 'dir',
                            'operation_position' => $operation_position,
                        ]);
                    }
                }
                ++$operation_position;
            }
            if (!feof($updates_handle)) {
                throw new \RuntimeException(
                    'Failed to read the local-index updates.'
                );
            }
            if (!fflush($prepared_updates_handle)) {
                throw new \RuntimeException(
                    'Failed to flush the local-index updates.'
                );
            }
        } finally {
            if (is_resource($updates_handle)) {
                fclose($updates_handle);
            }
            if (is_resource($prepared_updates_handle)) {
                fclose($prepared_updates_handle);
            }
        }

        // Limit each sort run to 8 MiB of update-line bytes.
        $sorter = new \ExternalMergeSort(
            static function (string $line): string {
                /** @var array{path:string,operation_position:int} $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                /** @var string $path */
                $path = base64_decode($entry['path']);
                /*
                 * The fixed-width suffix retains operation order among
                 * updates for the same path after sorting.
                 */
                return local_index_path_sort_key($path)
                    . "\0\0"
                    . sprintf('%020d', $entry['operation_position']);
            },
            8 * 1024 * 1024,
            false,
            dirname($updates_path)
        );
        $sorter->sort($prepared_updates_path);

        $base_handle = null;
        $prepared_updates_handle = null;
        $swap_handle = null;
        try {
            if (is_file($local_index_path)) {
                $base_handle = fopen($local_index_path, 'rb');
                if (!is_resource($base_handle)) {
                    throw new \RuntimeException(
                        'Failed to open the local index.'
                    );
                }
            }
            $prepared_updates_handle = fopen($prepared_updates_path, 'rb');
            $swap_handle = fopen($swap_path, 'wb');
            if (
                !is_resource($prepared_updates_handle)
                || !is_resource($swap_handle)
            ) {
                throw new \RuntimeException(
                    'Failed to merge local-index updates.'
                );
            }
            $base = read_index_entry($base_handle);
            $updates = read_index_updates($prepared_updates_handle);
            $updates->rewind();
            $directory_waiting_for_next_entry = null;
            /*
             * A later deletion or non-directory F update at a parent removes
             * older descendants. Component ordering keeps each subtree
             * contiguous, so this stack holds only the descendant-removal
             * operations which can affect the current entry.
             */
            $descendant_removal_stack = [];

            while ($base !== null || $updates->valid()) {
                $update = $updates->valid() ? $updates->current() : null;
                $comparison =
                    $base !== null && $update !== null
                        ? compare_local_index_paths(
                            $base['path'],
                            $update['path']
                        )
                        : null;

                if (
                    $update === null
                    || ( $comparison !== null && $comparison < 0 )
                ) {
                    $path = $base['path'];
                    $entry = $base;
                    $operation_position = null;
                    $last_descendant_removal_position = null;
                    $base = read_index_entry($base_handle);
                } else {
                    $path = $update['path'];
                    $entry = $update['delete'] ? null : $update;
                    $operation_position = $update['operation_position'];
                    $last_descendant_removal_position =
                        $update['last_descendant_removal_position'];
                    $updates->next();
                    if ($comparison === 0) {
                        $base = read_index_entry($base_handle);
                    }
                }

                while ($descendant_removal_stack !== []) {
                    $ancestor_removal = $descendant_removal_stack[
                        count($descendant_removal_stack) - 1
                    ];
                    if (
                        strpos(
                            $path,
                            $ancestor_removal['path'] . '/'
                        ) === 0
                    ) {
                        break;
                    }
                    array_pop($descendant_removal_stack);
                }
                $active_ancestor_removal_position =
                    $descendant_removal_stack === []
                        ? null
                        : $descendant_removal_stack[
                            count($descendant_removal_stack) - 1
                        ]['operation_position'];
                $entry_survives =
                    $active_ancestor_removal_position === null
                    || (
                        $operation_position !== null
                        && $operation_position
                            >= $active_ancestor_removal_position
                    );

                if ($entry !== null && $entry_survives) {
                    if ($directory_waiting_for_next_entry !== null) {
                        $directory_waiting_for_next_entry['empty'] =
                            strpos(
                                $entry['path'],
                                $directory_waiting_for_next_entry['path'] . '/'
                            ) !== 0;
                        write_index_entry(
                            $swap_handle,
                            $directory_waiting_for_next_entry
                        );
                        $directory_waiting_for_next_entry = null;
                    }
                    if ($entry['type'] === 'dir') {
                        $entry['empty'] = true;
                        $directory_waiting_for_next_entry = $entry;
                    } else {
                        write_index_entry($swap_handle, $entry);
                    }
                }
                if (
                    $last_descendant_removal_position !== null
                    && (
                        $active_ancestor_removal_position === null
                        || $last_descendant_removal_position
                            >= $active_ancestor_removal_position
                    )
                ) {
                    $descendant_removal_stack[] = [
                        'path' => $path,
                        'operation_position' =>
                            $last_descendant_removal_position,
                    ];
                }
            }

            if ($directory_waiting_for_next_entry !== null) {
                write_index_entry(
                    $swap_handle,
                    $directory_waiting_for_next_entry
                );
            }
            if (!fflush($swap_handle)) {
                throw new \RuntimeException(
                    'Failed to flush the merged index.'
                );
            }
        } finally {
            if (is_resource($base_handle)) {
                fclose($base_handle);
            }
            if (is_resource($prepared_updates_handle)) {
                fclose($prepared_updates_handle);
            }
            if (is_resource($swap_handle)) {
                fclose($swap_handle);
            }
        }

        if (!rename($swap_path, $local_index_path)) {
            throw new \RuntimeException(
                'Failed to replace the local index: '
                . $local_index_path
                . '.'
            );
        }
    } finally {
        if (is_file($prepared_updates_path)) {
            @unlink($prepared_updates_path);
        }
        if (is_file($swap_path)) {
            @unlink($swap_path);
        }
    }
}

/**
 * Compares local-index paths component by component.
 */
function compare_local_index_paths(string $left, string $right): int
{
    return strcmp(
        local_index_path_sort_key($left),
        local_index_path_sort_key($right)
    );
}

/**
 * Returns the byte-sortable key for one local-index path.
 *
 * Filesystem paths cannot contain NUL. Using it as the component separator
 * sorts each directory immediately before its descendants.
 */
function local_index_path_sort_key(string $path): string
{
    return str_replace('/', "\0", $path) . "\0";
}

/**
 * Decodes one Reprint index entry.
 *
 * @return array {
 *     @type string $path  Decoded filesystem path.
 *     @type int    $ctime Indexed change timestamp.
 *     @type int    $size  Indexed size.
 *     @type string $type  `file`, `link`, or `dir`.
 *     @type bool   $empty Whether a directory has no descendant entries in
 *                         this index.
 * }
 * @phpstan-return array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool}
 */
function decode_index_entry(string $line): array
{
    /** @var array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool} $data */
    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    /** @var string $path */
    $path = base64_decode($data['path']);
    $entry = [
        'path' => $path,
        'ctime' => $data['ctime'],
        'size' => $data['size'],
        'type' => $data['type'],
    ];
    if (array_key_exists('empty', $data)) {
        $entry['empty'] = $data['empty'];
    }
    return $entry;
}

/**
 * Reads one Reprint index entry.
 *
 * @param resource|null $handle Open index, or null when no index exists.
 * @return array|null Decoded index entry, or null at EOF.
 * @phpstan-return array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool}|null
 */
function read_index_entry($handle): ?array
{
    if (!is_resource($handle)) {
        return null;
    }
    $line = fgets($handle);
    if ($line === false) {
        if (!feof($handle)) {
            throw new \RuntimeException('Failed to read an index entry.');
        }
        return null;
    }
    return decode_index_entry($line);
}

/**
 * Yields the final update for each path and the latest operation which removed
 * its descendants.
 *
 * @param resource $handle       Open path-sorted update file. Local-index
 *                               update batches also carry their operation
 *                               positions.
 * @param string   $field_prefix Field prefix in a pull WAL projection.
 * @return \Generator<int,array{path:string,delete:bool,ctime:int,size:int,type:string|null,operation_position:int|null,last_descendant_removal_position:int|null},mixed,void>
 */
function read_index_updates($handle, string $field_prefix = ''): \Generator
{
    $path_key = $field_prefix === '' ? 'path' : $field_prefix . 'path_b64';
    $ctime_key = $field_prefix . 'ctime';
    $size_key = $field_prefix . 'size';
    $type_key = $field_prefix . 'type';
    $current = null;
    while (true) {
        $line = fgets($handle);
        if ($line === false) {
            break;
        }
        /*
         * A process may stop while appending the final WAL record.
         * Only newline-terminated records were written completely.
         */
        if (substr($line, -1) !== "\n" && feof($handle)) {
            break;
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        /** @var string $path */
        $path = base64_decode($data[$path_key]);
        $delete = $data['op'] === 'D';
        $operation_position = $data['operation_position'] ?? null;
        $last_descendant_removal_position =
            $operation_position !== null
            && ( $delete || $data[$type_key] !== 'dir' )
                ? $operation_position
                : null;
        $entry = [
            'path' => $path,
            'delete' => $delete,
            'ctime' => $delete ? 0 : $data[$ctime_key],
            'size' => $delete ? 0 : $data[$size_key],
            'type' => $delete ? null : $data[$type_key],
            'operation_position' => $operation_position,
            'last_descendant_removal_position' =>
                $last_descendant_removal_position,
        ];

        if ($current !== null && $entry['path'] !== $current['path']) {
            yield $current;
            $current = null;
        }
        if ($current !== null) {
            if (
                $current['last_descendant_removal_position'] !== null
                && (
                    $entry['last_descendant_removal_position'] === null
                    || $current['last_descendant_removal_position']
                        > $entry['last_descendant_removal_position']
                )
            ) {
                $entry['last_descendant_removal_position'] =
                    $current['last_descendant_removal_position'];
            }
        }
        $current = $entry;
    }
    if (!feof($handle)) {
        throw new \RuntimeException('Failed to read an index-update entry.');
    }
    if ($current !== null) {
        yield $current;
    }
}

/**
 * Writes one JSONL index entry.
 *
 * @param resource $handle Open index output.
 * @param array    $entry {
 *     Decoded index entry.
 *
 *     @type string $path  Path bytes.
 *     @type int    $ctime Entry ctime.
 *     @type int    $size  Entry size.
 *     @type string $type  `file`, `link`, or `dir`.
 *     @type bool   $empty Whether a directory has no descendant entries in
 *                         this index.
 * }
 */
function write_index_entry($handle, array $entry): void
{
    $encoded = [
        'path' => base64_encode($entry['path']),
        'ctime' => $entry['ctime'],
        'size' => $entry['size'],
        'type' => $entry['type'],
    ];
    if ($entry['type'] === 'dir' && array_key_exists('empty', $entry)) {
        $encoded['empty'] = $entry['empty'];
    }
    $line = json_encode(
        $encoded,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    if (fwrite($handle, $line) !== strlen($line)) {
        throw new \RuntimeException('Failed to write an index entry.');
    }
}

/**
 * Writes one JSONL local-index update.
 *
 * @param resource $handle Open update output.
 * @param array    $update {
 *     One local-index operation.
 *
 *     @type string $op       `F` or `D`.
 *     @type string $path     Base64-encoded path.
 *     @type int    $ctime    Local ctime for an `F` operation.
 *     @type int    $size     Local size for an `F` operation.
 *     @type string $type     Local type for an `F` operation.
 *     @type int    $operation_position Operation order present only in the
 *                                      prepared batch.
 * }
 */
function write_local_index_update($handle, array $update): void
{
    $line = json_encode(
        $update,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    if (fwrite($handle, $line) !== strlen($line)) {
        throw new \RuntimeException('Failed to write a local-index update.');
    }
}
