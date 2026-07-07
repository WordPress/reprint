<?php

/**
 * Shared wire format helpers for staged push streams.
 *
 * A staged push stream is a sequence of JSON header lines followed by exactly
 * the number of raw payload bytes declared by each header. Both the importer
 * and the exporter use this class so frame shape, validation, and bounded
 * stream reads stay in one place.
 */
final class Site_Export_Staged_Push_Stream_Protocol {

    public const CONTENT_TYPE = 'application/x-reprint-staged-push-stream';

    /**
     * @return string JSON header line terminated by "\n".
     */
    public static function encode_chunk_header(string $artifact_id, int $offset, int $bytes, int $total_bytes, bool $final): string {
        $line = json_encode([
            'type' => 'chunk',
            'artifact_id' => $artifact_id,
            'offset' => $offset,
            'bytes' => $bytes,
            'total_bytes' => $total_bytes,
            'final' => $final,
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new RuntimeException('Could not encode staged push stream frame header.');
        }
        return $line . "\n";
    }

    /**
     * Read the next frame header line from a stream.
     *
     * @param resource $input
     */
    public static function read_header_line($input): ?string {
        $line = fgets($input);
        if ($line === false) {
            return null;
        }
        return rtrim($line, "\r\n");
    }

    /**
     * Decode and validate one chunk frame header.
     *
     * @return array{artifact_id:string,offset:int,bytes:int,total_bytes:int,final:bool}
     */
    public static function decode_chunk_header(string $line): array {
        $frame = json_decode($line, true);
        if (!is_array($frame)) {
            throw new InvalidArgumentException('header_json');
        }

        $artifact_id = $frame['artifact_id'] ?? null;
        $offset = $frame['offset'] ?? null;
        $bytes = $frame['bytes'] ?? null;
        $total_bytes = $frame['total_bytes'] ?? null;
        if (
            ($frame['type'] ?? null) !== 'chunk'
            || !is_string($artifact_id)
            || $artifact_id === ''
            || !is_numeric($offset)
            || (int) $offset < 0
            || !is_numeric($bytes)
            || (int) $bytes < 0
            || !is_numeric($total_bytes)
            || (int) $total_bytes < 0
        ) {
            throw new InvalidArgumentException('fields');
        }

        $offset = (int) $offset;
        $bytes = (int) $bytes;
        $total_bytes = (int) $total_bytes;
        if ($offset + $bytes > $total_bytes) {
            throw new InvalidArgumentException('range_exceeds_total');
        }

        return [
            'artifact_id' => $artifact_id,
            'offset' => $offset,
            'bytes' => $bytes,
            'total_bytes' => $total_bytes,
            'final' => !empty($frame['final']),
        ];
    }

    /**
     * @param resource $input
     */
    public static function read_exactly($input, int $bytes): ?string {
        $data = '';
        while (strlen($data) < $bytes) {
            $piece = fread($input, $bytes - strlen($data));
            if ($piece === false || $piece === '') {
                return null;
            }
            $data .= $piece;
        }
        return $data;
    }

    /**
     * @param resource $input
     */
    public static function discard_exactly($input, int $bytes, int $buffer_bytes): bool {
        $remaining = $bytes;
        while ($remaining > 0) {
            $piece = fread($input, min($buffer_bytes, $remaining));
            if ($piece === false || $piece === '') {
                return false;
            }
            $remaining -= strlen($piece);
        }
        return true;
    }
}
