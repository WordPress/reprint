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
     * Top-level path segment reprint reserves for its own staged bookkeeping.
     * A sender's file artifacts may never land here — see
     * is_reserved_sender_artifact_id() — so the apply step can trust that
     * everything under it is reprint's own, not pushed site content.
     */
    public const RESERVED_NAMESPACE_SEGMENT = '.reprint';

    /**
     * The one artifact id inside the reserved namespace a sender is allowed
     * to write: the list of paths deleted locally since the last push, staged
     * like any other artifact so apply can unlink them in its window. Content
     * is the local-paths-to-delete.jsonl the push journal produces.
     */
    public const DELETION_MANIFEST_ARTIFACT_ID = '.reprint/deletions.jsonl';

    /**
     * Whether a sender-named artifact id intrudes on reprint's reserved
     * namespace. True for the bare reserved segment and anything beneath it,
     * except the one deletion-manifest id a sender may legitimately write.
     *
     * The check is on the first path segment, not a raw string prefix, so
     * a real site file like ".reprintfoo" or "wp-content/.reprint/x" is not
     * caught — only the top-level ".reprint" namespace is.
     */
    public static function is_reserved_sender_artifact_id(string $artifact_id): bool {
        if ($artifact_id === self::DELETION_MANIFEST_ARTIFACT_ID) {
            return false;
        }
        return explode('/', $artifact_id, 2)[0] === self::RESERVED_NAMESPACE_SEGMENT;
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
     * The artifact_id in the wire frame is base64: file paths are arbitrary
     * bytes and JSON strings must be UTF-8, so ids travel encoded — the same
     * convention the pull cursors and the local journal use for paths. The
     * returned artifact_id is the decoded raw path.
     *
     * @return array{artifact_id:string,offset:int,bytes:int,total_bytes:int,final:bool}
     */
    public static function decode_chunk_header(string $line): array {
        $frame = json_decode($line, true);
        if (!is_array($frame)) {
            throw new InvalidArgumentException('Expected staged push stream frame header to be a JSON object.');
        }

        if (!array_key_exists('type', $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "type".');
        }
        if ($frame['type'] !== 'chunk') {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "type" to be "chunk"; received ' .
                self::describe_value($frame['type']) .
                '.'
            );
        }

        if (!array_key_exists('artifact_id', $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "artifact_id".');
        }
        if (!is_string($frame['artifact_id']) || $frame['artifact_id'] === '') {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "artifact_id" to be base64 of a non-empty path; received ' .
                self::describe_value($frame['artifact_id']) .
                '.'
            );
        }
        $artifact_id = base64_decode($frame['artifact_id'], true);
        if ($artifact_id === false || $artifact_id === '') {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "artifact_id" to be base64 of a non-empty path; received ' .
                self::describe_value($frame['artifact_id']) .
                '.'
            );
        }

        $offset = self::require_non_negative_integer_field($frame, 'offset');
        $bytes = self::require_non_negative_integer_field($frame, 'bytes');
        $total_bytes = self::require_non_negative_integer_field($frame, 'total_bytes');

        if (!array_key_exists('final', $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "final".');
        }
        if (!is_bool($frame['final'])) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "final" to be a boolean; received ' .
                self::describe_value($frame['final']) .
                '.'
            );
        }

        if ($offset + $bytes > $total_bytes) {
            throw new InvalidArgumentException(
                sprintf(
                    'Staged push stream frame declares offset %d and %d payload bytes, which exceeds total_bytes %d.',
                    $offset,
                    $bytes,
                    $total_bytes
                )
            );
        }

        return [
            'artifact_id' => $artifact_id,
            'offset' => $offset,
            'bytes' => $bytes,
            'total_bytes' => $total_bytes,
            'final' => $frame['final'],
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

    private static function require_non_negative_integer_field(array $frame, string $field): int {
        if (!array_key_exists($field, $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "' . $field . '".');
        }
        if (!is_int($frame[$field]) || $frame[$field] < 0) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "' . $field . '" to be a non-negative integer; received ' .
                self::describe_value($frame[$field]) .
                '.'
            );
        }
        return $frame[$field];
    }

    private static function describe_value($value): string {
        if (is_string($value)) {
            return 'string "' . $value . '"';
        }
        if (is_int($value)) {
            return 'integer ' . $value;
        }
        if (is_float($value)) {
            return 'float ' . $value;
        }
        if (is_bool($value)) {
            return $value ? 'boolean true' : 'boolean false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array';
        }
        return gettype($value);
    }
}
