<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Parser errors are returned as API JSON, never rendered as HTML.

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}

/**
 * Reads one framed staged-push request body without buffering its payloads.
 *
 * Call next_frame() to expose a decoded JSON header, then consume its payload
 * in bounded pieces before advancing again. The parser never reads a later
 * frame while bytes from the current frame remain unread.
 */
final class Site_Export_Staged_Push_Stream_Parser {

    /** @var resource */
    private $input;

    /** @var array<string,mixed>|null */
    private $current_frame = null;

    /** @var int */
    private $remaining_payload_bytes = 0;

    /** @param resource $input Request body stream. */
    public function __construct($input) {
        if (!is_resource($input)) {
            throw new InvalidArgumentException('The staged push stream input must be a readable stream resource.');
        }
        $this->input = $input;
    }

    /**
     * Advances to the next frame header.
     *
     * Returns false only at a clean end of the request body. The caller must
     * consume the current payload before calling this again.
     */
    public function next_frame(): bool {
        if ($this->remaining_payload_bytes !== 0) {
            throw new LogicException(
                'Read or discard the current staged push stream frame payload before reading another frame.'
            );
        }

        // The extra two bytes allow a maximum-sized JSON header to end in
        // CRLF without treating the carriage return as part of the header.
        $line = fgets($this->input, Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES + 3);
        if ($line === false) {
            if (feof($this->input)) {
                $this->current_frame = null;
                return false;
            }
            throw new RuntimeException('Could not read the next staged push stream frame header.');
        }
        if (substr($line, -1) !== "\n") {
            throw new InvalidArgumentException(
                'A staged push stream frame header exceeds '
                . Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES
                . ' bytes or is missing its line feed.'
            );
        }
        $line = substr($line, 0, -1);
        if (substr($line, -1) === "\r") {
            $line = substr($line, 0, -1);
        }
        if (strlen($line) > Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES) {
            throw new InvalidArgumentException(
                'A staged push stream frame header exceeds '
                . Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES . ' bytes.'
            );
        }

        $frame = Site_Export_Staged_Push_Stream_Protocol::decode_frame_header($line);
        $this->current_frame = $frame;
        $this->remaining_payload_bytes = Site_Export_Staged_Push_Stream_Protocol::frame_payload_bytes($frame);
        return true;
    }

    /** Returns the decoded header from the successful next_frame() call. */
    public function get_current_frame(): array {
        if ($this->current_frame === null) {
            throw new LogicException('No staged push stream frame is current; call next_frame() first.');
        }
        return $this->current_frame;
    }

    /**
     * Reads no more than the requested number of bytes from the current payload.
     *
     * Returns an empty string once that payload is complete. A shorter nonempty
     * string is valid: stream reads are allowed to return early.
     */
    public function read_payload_piece(int $maximum_payload_bytes): string {
        if ($this->current_frame === null) {
            throw new LogicException('No staged push stream frame is current; call next_frame() first.');
        }
        if ($maximum_payload_bytes <= 0) {
            throw new InvalidArgumentException('The maximum staged push stream payload piece must be greater than zero.');
        }
        if ($this->remaining_payload_bytes === 0) {
            return '';
        }

        $payload_piece = fread($this->input, min($maximum_payload_bytes, $this->remaining_payload_bytes));
        if ($payload_piece === false || $payload_piece === '') {
            throw new RuntimeException('The staged push stream frame payload ended before its declared byte count.');
        }
        $this->remaining_payload_bytes -= strlen($payload_piece);
        return $payload_piece;
    }

    /** Discards a bounded prefix of the current payload. */
    public function discard_payload_bytes(int $payload_bytes, int $buffer_bytes): void {
        if ($this->current_frame === null) {
            throw new LogicException('No staged push stream frame is current; call next_frame() first.');
        }
        if ($payload_bytes < 0 || $payload_bytes > $this->remaining_payload_bytes) {
            throw new InvalidArgumentException(
                'Cannot discard ' . $payload_bytes . ' staged push stream payload bytes; '
                . $this->remaining_payload_bytes . ' bytes remain in the frame.'
            );
        }
        if ($buffer_bytes <= 0) {
            throw new InvalidArgumentException('The staged push stream discard buffer must be greater than zero.');
        }

        $remaining_bytes = $payload_bytes;
        while ($remaining_bytes > 0) {
            $payload_piece = $this->read_payload_piece(min($buffer_bytes, $remaining_bytes));
            $remaining_bytes -= strlen($payload_piece);
        }
    }
}
