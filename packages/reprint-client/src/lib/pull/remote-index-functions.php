<?php

namespace Reprint\Importer;

use RuntimeException;

use function WordPress\Reprint\Exporter\assert_valid_path;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Parse failures are CLI values, never HTML output.

/**
 * Parses one JSON index line into an array.
 *
 * @param string $line One JSONL line from a remote index file.
 * @return array|null {
 *     Decoded index entry, or null for an empty line.
 *
 *     @type string $path  Decoded absolute path.
 *     @type int    $ctime Change time reported by the exporter.
 *     @type int    $size  Size in bytes.
 *     @type string $type  `file`, `dir`, or `link`.
 * }
 */
function parse_index_line(string $line): ?array
{
    $line = trim($line);
    if ($line === "") {
        return null;
    }
    $data = json_decode($line, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid index line format");
    }
    $path_encoded = $data["path"] ?? "";
    if (!is_string($path_encoded) || $path_encoded === "") {
        throw new RuntimeException("Invalid index path");
    }
    $path = base64_decode($path_encoded, true);
    if ($path === "" || $path === false) {
        throw new RuntimeException("Invalid index path (base64 decode failed)");
    }
    assert_valid_path($path, "index path");
    return [
        "path" => $path,
        "ctime" => (int) ($data["ctime"] ?? 0),
        "size" => (int) ($data["size"] ?? 0),
        "type" => (string) ($data["type"] ?? "file"),
    ];
}

/**
 * Reads the next parseable entry from the remote index.
 *
 * @param resource|null $remote_index_file_handle Open remote index handle,
 *                                                or null when the index does
 *                                                not exist.
 */
function read_remote_index_entry($remote_index_file_handle): ?array
{
    if (!$remote_index_file_handle) {
        return null;
    }
    while (($remote_index_json_line = fgets($remote_index_file_handle)) !== false) {
        $remote_index_entry = parse_index_line($remote_index_json_line);
        if ($remote_index_entry !== null) {
            return $remote_index_entry;
        }
    }
    return null;
}
