<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol errors are returned as API JSON, never rendered as HTML.

/**
 * Shared wire format helpers for staged push streams.
 *
 * A staged push stream is a sequence of JSON header lines followed by exactly
 * the number of raw payload bytes declared by each header. Both the importer
 * and the exporter use this class so frame shape and validation stay in one
 * place. Site_Export_Staged_Push_Stream_Parser reads those frames.
 */
final class Site_Export_Staged_Push_Stream_Protocol {

    public const CONTENT_TYPE = 'application/x-reprint-staged-push-stream';

    /** Maximum raw bytes accepted for one path or symlink target. */
    public const MAX_PATH_BYTES = 8192;

    /** Maximum JSON header bytes in one framed request-body record. */
    public const MAX_HEADER_BYTES = 32768;

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

    /** Decodes the JSON object shared by every staged push frame header. */
    public static function decode_frame_header(string $line): array {
        $frame = json_decode($line, true);
        if (!is_array($frame)) {
            throw new InvalidArgumentException('Expected staged push stream frame header to be a JSON object.');
        }
        return $frame;
    }

    /** Returns the raw payload byte count declared by a decoded frame header. */
    public static function frame_payload_bytes(array $frame): int {
        return self::require_non_negative_integer_field($frame, 'bytes');
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
        return self::decode_chunk_frame(self::decode_frame_header($line));
    }

    /**
     * Decodes a chunk header already read by Site_Export_Staged_Push_Stream_Parser.
     *
     * @return array{artifact_id:string,offset:int,bytes:int,total_bytes:int,final:bool}
     */
    public static function decode_chunk_frame(array $frame): array {

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
        $bytes = self::frame_payload_bytes($frame);
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
     * Encodes one file chunk header for the importer to send.
     *
     * The artifact id is base64 because file names are arbitrary bytes while
     * JSON strings must be UTF-8.
     *
     * @param string $artifact_id Raw-byte path.
     */
    public static function encode_chunk_header(string $artifact_id, int $offset, int $payload_bytes, int $total_bytes, bool $is_final): string {
        return self::encode_header([
            'type' => 'chunk',
            'artifact_id' => base64_encode($artifact_id),
            'offset' => $offset,
            'bytes' => $payload_bytes,
            'total_bytes' => $total_bytes,
            'final' => $is_final,
        ]);
    }

    /**
     * Encodes one payload-free directory, symlink, or delete change header.
     *
     * @param array<string,mixed> $change type, path, and target for a symlink.
     */
    public static function encode_apply_change_header(array $change): string {
        $type = $change['type'] ?? null;
        $path = $change['path'] ?? null;
        if (!in_array($type, ['directory', 'symlink', 'delete'], true)) {
            throw new InvalidArgumentException('Expected staged apply change field "type" to be "directory", "symlink", or "delete".');
        }
        if (!is_string($path)) {
            throw new InvalidArgumentException('Expected staged apply change field "path" to be a string.');
        }
        $frame = [
            'type' => $type,
            'path_b64' => base64_encode($path),
            'bytes' => 0,
        ];
        if ($type === 'symlink') {
            $target = $change['target'] ?? null;
            if (!is_string($target)) {
                throw new InvalidArgumentException('Expected staged apply symlink change field "target" to be a string.');
            }
            $frame['target_b64'] = base64_encode($target);
        }
        return self::encode_header($frame);
    }

    /**
     * Decodes one payload-free directory, symlink, or delete change frame.
     *
     * @return array{type:string,path:string,target?:string}
     */
    public static function decode_apply_change_frame(array $frame): array {
        if (!array_key_exists('type', $frame)) {
            throw new InvalidArgumentException('Missing staged apply change frame field "type".');
        }
        if (!in_array($frame['type'], ['directory', 'symlink', 'delete'], true)) {
            throw new InvalidArgumentException(
                'Expected staged apply change frame field "type" to be "directory", "symlink", or "delete"; received '
                . self::describe_value($frame['type']) . '.'
            );
        }
        if (self::frame_payload_bytes($frame) !== 0) {
            throw new InvalidArgumentException('A staged apply change frame must not declare payload bytes.');
        }

        $path = self::decode_non_empty_base64_path($frame, 'path_b64', 'staged apply change');
        if ($frame['type'] !== 'symlink') {
            return ['type' => $frame['type'], 'path' => $path];
        }
        if (!array_key_exists('target_b64', $frame) || !is_string($frame['target_b64'])) {
            throw new InvalidArgumentException('Expected staged apply symlink change frame field "target_b64" to be base64 text.');
        }
        $target = base64_decode($frame['target_b64'], true);
        if ($target === false) {
            throw new InvalidArgumentException('Expected staged apply symlink change frame field "target_b64" to be valid base64 text.');
        }
        return ['type' => 'symlink', 'path' => $path, 'target' => $target];
    }

    /** @param array<string,mixed> $frame */
    private static function encode_header(array $frame): string {
        $encoded_frame = json_encode($frame, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded_frame)) {
            throw new RuntimeException('Could not encode a staged push stream frame header.');
        }
        return $encoded_frame . "\n";
    }

    /** @param array<string,mixed> $frame */
    private static function decode_non_empty_base64_path(array $frame, string $field, string $frame_description): string {
        if (!array_key_exists($field, $frame) || !is_string($frame[$field]) || $frame[$field] === '') {
            throw new InvalidArgumentException(
                'Expected ' . $frame_description . ' frame field "' . $field . '" to be base64 of a non-empty path.'
            );
        }
        $path = base64_decode($frame[$field], true);
        if ($path === false || $path === '') {
            throw new InvalidArgumentException(
                'Expected ' . $frame_description . ' frame field "' . $field . '" to be base64 of a non-empty path.'
            );
        }
        return $path;
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
