<?php

/**
 * Caller-driven reader for a multipart/mixed request body.
 *
 * Push requires a Content-Length on every part. That lets this reader hand a
 * part body to its caller in bounded pieces without looking for a boundary in
 * arbitrary file bytes or retaining a whole part in memory. The caller must
 * drain a current body before asking for the next part.
 */
final class Site_Export_Multipart_Stream_Input {

    /** A MIME boundary is deliberately small and ASCII-only. */
    private const MAX_BOUNDARY_BYTES = 70;

    /** Header lines and their aggregate are bounded before any body is read. */
    private const MAX_HEADER_LINE_BYTES = 8192;

    private const MAX_HEADER_BYTES = 32768;

    private const MAX_HEADERS = 32;

    /** The upload processor never holds a body read larger than this. */
    private const MAX_BODY_PIECE_BYTES = 262144;

    /** @var resource */
    private $input;

    /** @var string */
    private $boundary;

    /** @var bool */
    private $started = false;

    /** @var bool */
    private $finished = false;

    /** @var array<string,string>|null */
    private $current_headers = null;

    /** @var int */
    private $remaining_body_bytes = 0;

    /**
     * @param resource $input Readable HTTP request body.
     */
    public function __construct($input, string $boundary) {
        if (!is_resource($input)) {
            throw new InvalidArgumentException('Multipart input must be a readable stream resource.');
        }
        self::validate_boundary($boundary);
        $this->input = $input;
        $this->boundary = $boundary;
    }

    /**
     * Parse and validate a multipart/mixed Content-Type header.
     */
    public static function boundary_from_content_type(string $content_type): string {
        $segments = explode(';', $content_type);
        $media_type = strtolower(trim((string) array_shift($segments)));
        if ($media_type !== 'multipart/mixed') {
            throw new InvalidArgumentException(
                'Expected Content-Type multipart/mixed; received ' . json_encode($content_type) . '.'
            );
        }

        $boundary = null;
        foreach ($segments as $segment) {
            $equals = strpos($segment, '=');
            if ($equals === false) {
                continue;
            }
            $name = strtolower(trim(substr($segment, 0, $equals)));
            if ($name !== 'boundary') {
                continue;
            }
            if ($boundary !== null) {
                throw new InvalidArgumentException('Multipart Content-Type contains more than one boundary parameter.');
            }
            $value = trim(substr($segment, $equals + 1));
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            }
            $boundary = $value;
        }

        if (!is_string($boundary) || $boundary === '') {
            throw new InvalidArgumentException('Multipart Content-Type requires a non-empty boundary parameter.');
        }
        self::validate_boundary($boundary);
        return $boundary;
    }

    /**
     * Advance to the next part header.
     *
     * Returns false only after a clean closing boundary. A missing closing
     * boundary and every truncated body are malformed input, not clean EOF.
     */
    public function next_part(): bool {
        if ($this->finished) {
            return false;
        }
        if ($this->current_headers !== null && $this->remaining_body_bytes !== 0) {
            throw new LogicException('Read or discard the current multipart body before reading another part.');
        }

        if ($this->started) {
            $separator = $this->read_exactly(2, 'the multipart part terminator');
            if ($separator !== "\r\n") {
                throw new InvalidArgumentException('A multipart part body must be followed by CRLF before its boundary.');
            }
        }

        $boundary_line = $this->read_line('the multipart boundary');
        $opening_boundary = '--' . $this->boundary;
        if ($boundary_line === $opening_boundary . '--') {
            $this->finished = true;
            $this->current_headers = null;
            return false;
        }
        if ($boundary_line !== $opening_boundary) {
            throw new InvalidArgumentException(
                'Expected multipart boundary "' . $opening_boundary . '"; received ' . json_encode($boundary_line) . '.'
            );
        }
        $this->started = true;

        $headers = [];
        $header_bytes = 0;
        while (true) {
            $line = $this->read_line('a multipart part header');
            $header_bytes += strlen($line) + 2;
            if ($header_bytes > self::MAX_HEADER_BYTES) {
                throw new InvalidArgumentException(
                    'Multipart part headers exceed ' . self::MAX_HEADER_BYTES . ' bytes.'
                );
            }
            if ($line === '') {
                break;
            }
            if (count($headers) >= self::MAX_HEADERS) {
                throw new InvalidArgumentException(
                    'Multipart part has more than ' . self::MAX_HEADERS . ' headers.'
                );
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new InvalidArgumentException('Malformed multipart part header ' . json_encode($line) . '.');
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = ltrim(substr($line, $colon + 1));
            if ($name === '' || isset($headers[$name])) {
                throw new InvalidArgumentException('Multipart part repeats or has an invalid header ' . json_encode($name) . '.');
            }
            $headers[$name] = $value;
        }

        $content_length = $headers['content-length'] ?? null;
        if (!is_string($content_length) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $content_length)) {
            throw new InvalidArgumentException('Every multipart push part requires a non-negative integer Content-Length header.');
        }
        if (strlen($content_length) > strlen((string) PHP_INT_MAX)
            || (strlen($content_length) === strlen((string) PHP_INT_MAX) && strcmp($content_length, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('Multipart part Content-Length exceeds this server\'s integer range: ' . $content_length . '.');
        }

        $this->current_headers = $headers;
        $this->remaining_body_bytes = (int) $content_length;
        return true;
    }

    /** @return array<string,string> */
    public function get_current_headers(): array {
        if ($this->current_headers === null) {
            throw new LogicException('No multipart part is current; call next_part() first.');
        }
        return $this->current_headers;
    }

    public function remaining_body_bytes(): int {
        if ($this->current_headers === null) {
            throw new LogicException('No multipart part is current; call next_part() first.');
        }
        return $this->remaining_body_bytes;
    }

    /**
     * Read at most $maximum_bytes of the current part body.
     *
     * An empty string is returned only after the declared body is complete.
     */
    public function read_body_piece(int $maximum_bytes): string {
        if ($this->current_headers === null) {
            throw new LogicException('No multipart part is current; call next_part() first.');
        }
        if ($maximum_bytes <= 0) {
            throw new InvalidArgumentException('The maximum multipart body piece must be greater than zero.');
        }
        if ($maximum_bytes > self::MAX_BODY_PIECE_BYTES) {
            throw new InvalidArgumentException(
                'The maximum multipart body piece exceeds ' . self::MAX_BODY_PIECE_BYTES . ' bytes.'
            );
        }
        if ($this->remaining_body_bytes === 0) {
            return '';
        }

        $piece = fread($this->input, min($maximum_bytes, $this->remaining_body_bytes));
        if ($piece === false || $piece === '') {
            throw new RuntimeException(
                'The multipart part body ended before its declared Content-Length of '
                . ($this->remaining_body_bytes) . ' remaining bytes.'
            );
        }
        $this->remaining_body_bytes -= strlen($piece);
        return $piece;
    }

    /** Drain the current body without holding it in memory. */
    public function discard_current_body(int $buffer_bytes): void {
        if ($buffer_bytes <= 0) {
            throw new InvalidArgumentException('The multipart discard buffer must be greater than zero.');
        }
        while ($this->remaining_body_bytes() > 0) {
            $this->read_body_piece($buffer_bytes);
        }
    }

    private static function validate_boundary(string $boundary): void {
        if ($boundary === '' || strlen($boundary) > self::MAX_BOUNDARY_BYTES) {
            throw new InvalidArgumentException(
                'Multipart boundary must contain between 1 and ' . self::MAX_BOUNDARY_BYTES . ' bytes.'
            );
        }
        if (!preg_match("/^[0-9A-Za-z'()+_,.\/:=?-]+$/D", $boundary)) {
            throw new InvalidArgumentException('Multipart boundary contains unsupported characters.');
        }
    }

    private function read_line(string $description): string {
        $line = fgets($this->input, self::MAX_HEADER_LINE_BYTES + 3);
        if ($line === false || $line === '') {
            throw new RuntimeException('The request ended while reading ' . $description . '.');
        }
        if (substr($line, -2) !== "\r\n") {
            throw new InvalidArgumentException(
                'Multipart ' . $description . ' exceeds ' . self::MAX_HEADER_LINE_BYTES . ' bytes or is missing CRLF.'
            );
        }
        $line = substr($line, 0, -2);
        if (strlen($line) > self::MAX_HEADER_LINE_BYTES) {
            throw new InvalidArgumentException('Multipart ' . $description . ' exceeds ' . self::MAX_HEADER_LINE_BYTES . ' bytes.');
        }
        return $line;
    }

    private function read_exactly(int $bytes, string $description): string {
        $result = '';
        while (strlen($result) < $bytes) {
            $piece = fread($this->input, $bytes - strlen($result));
            if ($piece === false || $piece === '') {
                throw new RuntimeException('The request ended while reading ' . $description . '.');
            }
            $result .= $piece;
        }
        return $result;
    }
}
