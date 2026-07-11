<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation errors are API/CLI messages, never HTML output.

/**
 * Shared wire format helpers for staged push operation streams.
 *
 * Each frame is one bounded JSON header line. File frames alone carry raw
 * payload bytes immediately after the line; the header's bytes field says
 * exactly how many. Paths and symlink targets travel as base64 because file
 * system names are bytes while JSON strings must be UTF-8.
 */
final class Site_Export_Staged_Push_Stream_Protocol {

    public const CONTENT_TYPE = 'application/x-reprint-staged-push-stream';

    /** Maximum raw bytes accepted for one path or symlink target. */
    public const MAX_PATH_BYTES = 8192;

    /** Holds two maximum base64 fields plus bounded JSON metadata. */
    public const MAX_HEADER_BYTES = 32768;

    /** Bounds metadata-only work even when request bodies are tiny. */
    public const MAX_FRAMES_PER_REQUEST = 1024;

    public const OPERATION_DIRECTORY = 'directory';

    public const OPERATION_FILE = 'file';

    public const OPERATION_SYMLINK = 'symlink';

    public const OPERATION_DELETE = 'delete';

    /**
     * Read one LF-terminated header without ever buffering an unbounded line.
     *
     * A clean EOF before another header returns null; EOF in a header is a
     * truncated frame, not a shorter valid line.
     *
     * @param resource $input
     */
    public static function read_header_line(
        $input,
        int $max_header_bytes = self::MAX_HEADER_BYTES
    ): ?string {
        if (!is_resource($input)) {
            throw new InvalidArgumentException('Expected the staged push stream input to be a resource.');
        }
        if ($max_header_bytes <= 0) {
            throw new InvalidArgumentException(
                'Expected the staged push stream header limit to be positive; received ' . $max_header_bytes . '.'
            );
        }

        $line = @fgets($input, $max_header_bytes + 2);
        if ($line === false) {
            if (feof($input)) {
                return null;
            }
            throw new RuntimeException('Could not read the next staged push stream frame header.');
        }

        if (substr($line, -1) !== "\n") {
            if (strlen($line) > $max_header_bytes) {
                throw new InvalidArgumentException(
                    'Staged push stream frame header exceeds ' . $max_header_bytes . ' bytes.'
                );
            }
            throw new InvalidArgumentException('Staged push stream frame header ended before its LF terminator.');
        }

        $line = substr($line, 0, -1);
        if (substr($line, -1) === "\r") {
            $line = substr($line, 0, -1);
        }
        if (strlen($line) > $max_header_bytes) {
            throw new InvalidArgumentException(
                'Staged push stream frame header exceeds ' . $max_header_bytes . ' bytes.'
            );
        }
        return $line;
    }

    /**
     * Encode one sender operation as its LF-terminated wire header.
     *
     * The file payload is not concatenated here. The streaming client holds
     * it separately and hands both strings to curl using copy-on-write.
     */
    public static function encode_operation_header(array $operation): string {
        $type = self::require_operation_type($operation);
        $operation_index = self::require_non_negative_integer_field($operation, 'operation_index');
        $path = self::require_raw_path_field($operation, 'path', false);

        $frame = [
            'type' => $type,
            'operation_index' => $operation_index,
            'path' => base64_encode($path),
        ];

        if ($type === self::OPERATION_SYMLINK) {
            self::require_exact_fields($operation, ['type', 'operation_index', 'path', 'target']);
            $frame['target'] = base64_encode(self::require_raw_path_field($operation, 'target', true));
        } elseif ($type === self::OPERATION_FILE) {
            self::require_exact_fields(
                $operation,
                ['type', 'operation_index', 'path', 'revision', 'offset', 'total_bytes', 'restart', 'payload']
            );
            if (!is_string($operation['payload'] ?? null)) {
                throw new InvalidArgumentException(
                    'Expected staged push operation field "payload" to be a string; received ' .
                    self::describe_value($operation['payload'] ?? null) . '.'
                );
            }
            $frame['revision'] = self::require_non_negative_integer_field($operation, 'revision');
            $frame['offset'] = self::require_non_negative_integer_field($operation, 'offset');
            $frame['bytes'] = strlen($operation['payload']);
            $frame['total_bytes'] = self::require_non_negative_integer_field($operation, 'total_bytes');
            $frame['restart'] = self::require_boolean_field($operation, 'restart');
        } else {
            self::require_exact_fields($operation, ['type', 'operation_index', 'path']);
        }

        $header = json_encode($frame, JSON_UNESCAPED_SLASHES);
        if ($header === false) {
            throw new RuntimeException(
                'Could not encode the staged push operation header for base64 path "' . base64_encode($path) . '".'
            );
        }

        // Apply the same range and restart checks on both sides of the wire.
        self::decode_operation_header($header);
        if (strlen($header) > self::MAX_HEADER_BYTES) {
            throw new InvalidArgumentException(
                'Staged push stream frame header exceeds ' . self::MAX_HEADER_BYTES . ' bytes.'
            );
        }
        return $header . "\n";
    }

    /**
     * Decode and validate one typed operation header.
     *
     * @return array<string,mixed> Raw-byte path and target values are decoded.
     */
    public static function decode_operation_header(string $line): array {
        $decoded = json_decode($line);
        if (!is_object($decoded)) {
            throw new InvalidArgumentException('Expected staged push stream frame header to be a JSON object.');
        }
        $frame = (array) $decoded;

        $type = self::require_operation_type($frame);
        $operation_index = self::require_non_negative_integer_field($frame, 'operation_index');
        $path = self::require_base64_field($frame, 'path', false);

        $operation = [
            'type' => $type,
            'operation_index' => $operation_index,
            'path' => $path,
        ];

        if ($type === self::OPERATION_SYMLINK) {
            self::require_exact_fields($frame, ['type', 'operation_index', 'path', 'target']);
            $operation['target'] = self::require_base64_field($frame, 'target', true);
            return $operation;
        }

        if ($type !== self::OPERATION_FILE) {
            self::require_exact_fields($frame, ['type', 'operation_index', 'path']);
            return $operation;
        }

        self::require_exact_fields(
            $frame,
            ['type', 'operation_index', 'path', 'revision', 'offset', 'bytes', 'total_bytes', 'restart']
        );
        $operation['revision'] = self::require_non_negative_integer_field($frame, 'revision');
        $operation['offset'] = self::require_non_negative_integer_field($frame, 'offset');
        $operation['bytes'] = self::require_non_negative_integer_field($frame, 'bytes');
        $operation['total_bytes'] = self::require_non_negative_integer_field($frame, 'total_bytes');
        $operation['restart'] = self::require_boolean_field($frame, 'restart');

        if ($operation['offset'] > $operation['total_bytes'] ||
            $operation['bytes'] > $operation['total_bytes'] - $operation['offset']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Staged push file frame declares offset %d and %d payload bytes, which exceeds total_bytes %d.',
                    $operation['offset'],
                    $operation['bytes'],
                    $operation['total_bytes']
                )
            );
        }
        if ($operation['bytes'] === 0 && $operation['offset'] !== $operation['total_bytes']) {
            throw new InvalidArgumentException(
                'Staged push file frames with zero payload bytes must be positioned at total_bytes.'
            );
        }
        if ($operation['restart'] && $operation['offset'] !== 0) {
            throw new InvalidArgumentException(
                'Staged push file frame field "restart" may be true only when offset is 0.'
            );
        }

        return $operation;
    }

    /**
     * @param resource $input
     */
    public static function read_exactly($input, int $bytes): ?string {
        $data = '';
        $remaining = $bytes;
        while ($remaining > 0) {
            $piece = fread($input, $remaining);
            if ($piece === false || $piece === '') {
                return null;
            }
            $data .= $piece;
            $remaining -= strlen($piece);
        }
        return $data;
    }

    private static function require_operation_type(array $frame): string {
        if (!array_key_exists('type', $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "type".');
        }
        if (!is_string($frame['type']) || !in_array(
            $frame['type'],
            [self::OPERATION_DIRECTORY, self::OPERATION_FILE, self::OPERATION_SYMLINK, self::OPERATION_DELETE],
            true
        )) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "type" to be "directory", "file", "symlink", or "delete"; received ' .
                self::describe_value($frame['type']) . '.'
            );
        }
        return $frame['type'];
    }

    private static function require_non_negative_integer_field(array $frame, string $field): int {
        if (!array_key_exists($field, $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "' . $field . '".');
        }
        if (!is_int($frame[$field]) || $frame[$field] < 0) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "' . $field . '" to be a non-negative integer; received ' .
                self::describe_value($frame[$field]) . '.'
            );
        }
        return $frame[$field];
    }

    private static function require_boolean_field(array $frame, string $field): bool {
        if (!array_key_exists($field, $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "' . $field . '".');
        }
        if (!is_bool($frame[$field])) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "' . $field . '" to be a boolean; received ' .
                self::describe_value($frame[$field]) . '.'
            );
        }
        return $frame[$field];
    }

    private static function require_raw_path_field(array $operation, string $field, bool $allow_empty): string {
        if (!array_key_exists($field, $operation)) {
            throw new InvalidArgumentException('Missing staged push operation field "' . $field . '".');
        }
        if (!is_string($operation[$field]) || ( !$allow_empty && $operation[$field] === '' )) {
            throw new InvalidArgumentException(
                'Expected staged push operation field "' . $field . '" to be ' .
                ( $allow_empty ? 'a byte string' : 'a non-empty byte string' ) . '; received ' .
                self::describe_value($operation[$field]) . '.'
            );
        }
        if (strlen($operation[$field]) > self::MAX_PATH_BYTES) {
            throw new InvalidArgumentException(
                'Staged push operation field "' . $field . '" exceeds ' . self::MAX_PATH_BYTES
                . ' raw bytes; observed ' . strlen($operation[$field]) . '.'
            );
        }
        return $operation[$field];
    }

    private static function require_base64_field(array $frame, string $field, bool $allow_empty): string {
        if (!array_key_exists($field, $frame)) {
            throw new InvalidArgumentException('Missing staged push stream frame field "' . $field . '".');
        }
        if (!is_string($frame[$field]) || ( !$allow_empty && $frame[$field] === '' )) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "' . $field . '" to be base64 of ' .
                ( $allow_empty ? 'a byte string' : 'a non-empty path' ) . '; received ' .
                self::describe_value($frame[$field]) . '.'
            );
        }
        $decoded = base64_decode($frame[$field], true);
        if ($decoded === false || ( !$allow_empty && $decoded === '' )) {
            throw new InvalidArgumentException(
                'Expected staged push stream frame field "' . $field . '" to be base64 of ' .
                ( $allow_empty ? 'a byte string' : 'a non-empty path' ) . '; received ' .
                self::describe_value($frame[$field]) . '.'
            );
        }
        if (strlen($decoded) > self::MAX_PATH_BYTES) {
            throw new InvalidArgumentException(
                'Staged push stream frame field "' . $field . '" exceeds ' . self::MAX_PATH_BYTES
                . ' decoded bytes; observed ' . strlen($decoded) . '.'
            );
        }
        return $decoded;
    }

    private static function require_exact_fields(array $frame, array $expected_fields): void {
        foreach ($frame as $field => $_value) {
            if (!in_array($field, $expected_fields, true)) {
                throw new InvalidArgumentException(
                    'Unexpected staged push stream frame field "' . $field . '" for operation type "' .
                    ( is_string($frame['type'] ?? null) ? $frame['type'] : 'unknown' ) . '".'
                );
            }
        }
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
