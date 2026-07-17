<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class DownloadList
{
    public static function countLines(string $file, int $upToByte = -1): int
    {
        if (!is_file($file)) {
            return 0;
        }

        $handle = fopen($file, "r");
        if (!$handle) {
            return 0;
        }

        $count = 0;
        $chunkSize = 65536;
        $remaining = $upToByte >= 0 ? $upToByte : PHP_INT_MAX;
        while ($remaining > 0 && !feof($handle)) {
            $data = fread($handle, min($chunkSize, $remaining));
            if ($data === false || $data === "") {
                break;
            }
            $count += substr_count($data, "\n");
            $remaining -= strlen($data);
        }

        fclose($handle);
        return $count;
    }

    public static function readPath(string $line): ?string
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }

        $decoded = json_decode($line, true);
        if (is_string($decoded)) {
            return $decoded !== "" ? $decoded : null;
        }

        if (!is_array($decoded) || !isset($decoded["path"])) {
            return null;
        }

        $encodedPath = $decoded["path"];
        if (!is_string($encodedPath) || $encodedPath === "") {
            return null;
        }

        $path = base64_decode($encodedPath);
        return is_string($path) && $path !== "" ? $path : null;
    }

    /**
     * @param resource $handle
     */
    public static function appendPath(string $path, $handle): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            fwrite($handle, $line . "\n");
        }
    }

    /**
     * Builds a JSON batch file listing the next set of paths to download.
     *
     * @return array{file: string, offset: int, next_offset: int, entries: int}|null
     */
    public static function prepareBatch(
        string $listFile,
        int $offset,
        int $maxRequestBytes
    ): ?array {
        $limit = self::batchPayloadLimit($maxRequestBytes);

        $handle = fopen($listFile, "r");
        if (!$handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $tmp = tempnam(sys_get_temp_dir(), "file-fetch-");
        if ($tmp === false) {
            fclose($handle);
            throw new RuntimeException("Failed to create fetch batch file");
        }

        $out = fopen($tmp, "w");
        if (!$out) {
            fclose($handle);
            @unlink($tmp);
            throw new RuntimeException("Failed to open fetch batch file");
        }

        $bytes = 1;
        $entries = 0;
        $first = true;
        fwrite($out, "[");

        while (true) {
            $lineStart = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $path = self::readPath($line);
            if ($path === null) {
                continue;
            }

            $jsonPath = json_encode(
                $path,
                JSON_UNESCAPED_SLASHES,
            );
            if ($jsonPath === false) {
                continue;
            }

            $prefix = $first ? "" : ",";
            $chunk = $prefix . $jsonPath;
            $needed = $bytes + strlen($chunk) + 1;

            if (!$first && $needed > $limit) {
                fseek($handle, $lineStart);
                break;
            }

            if (!self::writeChunk($out, $chunk)) {
                throw new RuntimeException("Failed to write fetch batch file (disk full?)");
            }

            $bytes += strlen($chunk);
            $entries++;
            $first = false;

            if ($needed > $limit) {
                break;
            }
        }

        fwrite($out, "]");
        $bytes++;

        $nextOffset = ftell($handle);
        fclose($handle);
        fclose($out);

        if ($bytes <= 2) {
            @unlink($tmp);
            return null;
        }

        return [
            "file" => $tmp,
            "offset" => $offset,
            "next_offset" => $nextOffset,
            "entries" => $entries,
        ];
    }

    public static function countBatchEntriesThroughCursor(
        string $batchFile,
        ?string $cursor
    ): int {
        $cursorPath = self::pathFromCursor($cursor);
        if ($cursorPath === null || !is_file($batchFile)) {
            return 0;
        }

        $raw = file_get_contents($batchFile);
        if ($raw === false) {
            return 0;
        }

        $paths = json_decode($raw, true);
        if (!is_array($paths)) {
            return 0;
        }

        $paths = array_values(array_filter(
            $paths,
            static fn($path): bool => is_string($path) && $path !== "",
        ));
        if (!in_array($cursorPath, $paths, true)) {
            return 0;
        }

        sort($paths, SORT_STRING);

        $low = 0;
        $high = count($paths);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if (strcmp($paths[$mid], $cursorPath) <= 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private static function batchPayloadLimit(int $maxRequestBytes): int
    {
        return (int) max(256 * 1024, $maxRequestBytes * 0.8);
    }

    /**
     * @param resource $handle
     */
    private static function writeChunk($handle, string $chunk): bool
    {
        return fwrite($handle, $chunk) !== false;
    }

    private static function pathFromCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === "") {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        $json = $decoded !== false ? $decoded : $cursor;
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $encodedPath = $data["path"] ?? null;
        if (!is_string($encodedPath) || $encodedPath === "") {
            return null;
        }

        $path = base64_decode($encodedPath, true);
        return is_string($path) && $path !== "" ? $path : null;
    }
}
