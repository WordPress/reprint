<?php

/**
 * Reads one multipart/mixed request body without buffering its file contents.
 *
 * This is the target-side, caller-driven stepping interface for staged push
 * uploads. Call next_part() to expose one part's headers, then repeatedly call
 * read_body_piece() until remaining_body_bytes() reaches zero. A part must be
 * drained, or explicitly discarded, before the reader can advance to the next
 * one.
 *
 * Every part must carry Content-Length. That requirement is what makes binary
 * file bodies safe to stream: the reader never searches arbitrary file bytes
 * for a MIME boundary and never needs to retain a complete part. At most one
 * bounded header block and one body piece are held by this class. The outer
 * HTTP request may still use a streaming transfer encoding and need not declare
 * its total length.
 *
 * The input follows the strict form emitted by MultipartPushStreamClient:
 * boundaries and header lines end in CRLF, each header name appears once, and
 * a closing boundary terminates the request. Truncation is reported as malformed
 * input rather than mistaken for a clean end of the upload.
 *
 * Example:
 *
 *     $boundary = Site_Export_Multipart_Stream_Input::boundary_from_content_type(
 *         'multipart/mixed; boundary="reprint-0123"'
 *     );
 *     $multipart = new Site_Export_Multipart_Stream_Input($request_body, $boundary);
 *
 *     while ($multipart->next_part()) {
 *         $headers = $multipart->get_current_headers();
 *         while ($multipart->remaining_body_bytes() > 0) {
 *             $piece = $multipart->read_body_piece(262144);
 *             // Persist this piece before asking for another one.
 *         }
 *     }
 */
final class Site_Export_Multipart_Stream_Input {

    /**
     * Maximum number of bytes accepted in the boundary parameter.
     *
     * RFC 2046 recommends boundaries no longer than 70 characters. Keeping
     * that bound here also limits the amount of syntax retained while parsing
     * a request and makes boundary-line diagnostics predictable.
     */
    private const MAX_BOUNDARY_BYTES = 70;

    /**
     * Maximum bytes accepted in one boundary or part-header line, excluding CRLF.
     *
     * fgets() is called with a fixed allowance above this number, so a peer
     * cannot make the reader accumulate an unbounded unterminated line.
     */
    private const MAX_HEADER_LINE_BYTES = 8192;

    /**
     * Maximum aggregate bytes accepted for one part's headers, including CRLF.
     *
     * This bounds valid collections of individually short headers before the
     * reader exposes or consumes any part body.
     */
    private const MAX_HEADER_BYTES = 32768;

    /**
     * Maximum number of distinct headers accepted on one MIME part.
     *
     * Duplicate names are rejected separately, so this is also an upper bound
     * on the number of entries retained in $current_headers.
     */
    private const MAX_HEADERS = 32;

    /**
     * Maximum body piece returned from a single read_body_piece() call.
     *
     * The staged apply session writes pieces immediately. Capping each read at
     * 256 KiB prevents a caller-supplied read size from turning a streamed part
     * into a large in-memory allocation.
     */
    private const MAX_BODY_PIECE_BYTES = 262144;

    /**
     * Readable stream positioned at the next multipart byte.
     *
     * The stream is owned by the caller and is never rewound or closed here.
     *
     * @var resource
     */
    private $input;

    /**
     * Validated boundary token without the leading MIME `--` delimiter.
     *
     * @var string
     */
    private $boundary;

    /**
     * Whether the opening boundary has already been consumed.
     *
     * Later parts require the CRLF which separates the previous declared body
     * from its following boundary; the opening boundary has no such prefix.
     *
     * @var bool
     */
    private $started = false;

    /**
     * Whether a syntactically valid closing boundary has been consumed.
     *
     * Once true, next_part() remains false and no more stream bytes are read.
     *
     * @var bool
     */
    private $finished = false;

    /**
     * Lowercase header names and their values for the current part.
     *
     * Null means there is no current part: either next_part() has not succeeded
     * yet or the closing boundary has been reached.
     *
     * @var array<string,string>|null
     */
    private $current_headers = null;

    /**
     * Declared current-part bytes not yet returned or discarded.
     *
     * This value, rather than a boundary search, determines where the body
     * ends. It reaches zero before next_part() may consume the following CRLF.
     *
     * @var int
     */
    private $remaining_body_bytes = 0;

    /**
     * Creates a reader positioned before the opening multipart boundary.
     *
     * The boundary is validated independently of Content-Type parsing so
     * callers which obtain it from another trusted HTTP layer receive the same
     * syntax and size checks.
     *
     * @param resource $input Readable HTTP request body.
     * @param string   $boundary Boundary token without the leading `--`.
     *
     * @throws InvalidArgumentException If the stream or boundary is invalid.
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
     * Returns the validated boundary from a multipart/mixed Content-Type value.
     *
     * Media types and parameter names are matched case-insensitively. Both the
     * token and quoted parameter forms emitted by HTTP clients are accepted:
     *
     *     multipart/mixed; boundary=reprint-0123
     *     Multipart/Mixed; boundary="reprint-0123"
     *
     * The returned value is `reprint-0123`, without the delimiter's leading
     * `--`. A missing, empty, repeated, overlong, or non-ASCII boundary is
     * rejected before the request body is read. Restricting its character set
     * prevents embedded whitespace, quotes, and line endings from changing how
     * boundary lines are interpreted.
     *
     * @param string $content_type Complete Content-Type header value.
     * @return string Validated MIME boundary token.
     *
     * @throws InvalidArgumentException If the media type or boundary is invalid.
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
     * Advances to the next part and parses its complete header block.
     *
     * Returns false only after a clean closing boundary. A missing closing
     * boundary and every truncated body are malformed input, not clean EOF.
     * The previous part must have zero remaining body bytes before this method
     * is called.
     *
     * @return bool True when a part is current, false after the closing boundary.
     *
     * @throws LogicException If the previous part has not been drained.
     * @throws InvalidArgumentException If multipart syntax or Content-Length is invalid.
     * @throws RuntimeException If the request stream ends before required syntax.
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

    /**
     * Returns the normalized headers of the current part.
     *
     * Header names are lowercase and each name occurs at most once. Values
     * preserve trailing whitespace and discard leading whitespace following
     * the colon.
     *
     * @return array<string,string> Current part headers keyed by lowercase name.
     *
     * @throws LogicException If next_part() has not made a part current.
     */
    public function get_current_headers(): array {
        if ($this->current_headers === null) {
            throw new LogicException('No multipart part is current; call next_part() first.');
        }
        return $this->current_headers;
    }

    /**
     * Returns how many declared body bytes remain in the current part.
     *
     * @return int Non-negative number of bytes not yet read or discarded.
     *
     * @throws LogicException If next_part() has not made a part current.
     */
    public function remaining_body_bytes(): int {
        if ($this->current_headers === null) {
            throw new LogicException('No multipart part is current; call next_part() first.');
        }
        return $this->remaining_body_bytes;
    }

    /**
     * Reads a bounded piece of the current part body.
     *
     * The result contains at most the smaller of $maximum_bytes, 256 KiB, and
     * the declared bytes remaining. An empty string is returned only after the
     * declared body is complete; premature stream EOF throws instead.
     *
     * @param int $maximum_bytes Positive caller budget, no greater than 256 KiB.
     * @return string Next body bytes, or an empty string when the body is complete.
     *
     * @throws LogicException If no part is current.
     * @throws InvalidArgumentException If the requested piece size is invalid.
     * @throws RuntimeException If the body ends before its Content-Length.
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

    /**
     * Discards all unread bytes in the current part through bounded reads.
     *
     * This preserves the same truncation checks as read_body_piece() and does
     * not materialize the body. It is useful when a caller has validated the
     * headers and deliberately chooses not to persist that part.
     *
     * @param int $buffer_bytes Positive per-read budget, no greater than 256 KiB.
     *
     * @throws LogicException If no part is current.
     * @throws InvalidArgumentException If the buffer size is invalid.
     * @throws RuntimeException If the body is truncated.
     */
    public function discard_current_body(int $buffer_bytes): void {
        if ($buffer_bytes <= 0) {
            throw new InvalidArgumentException('The multipart discard buffer must be greater than zero.');
        }
        while ($this->remaining_body_bytes() > 0) {
            $this->read_body_piece($buffer_bytes);
        }
    }

    /**
     * Validates a boundary token before it is interpolated into delimiter lines.
     *
     * The accepted punctuation is the MIME `bcharsnospace` set. Spaces are
     * intentionally excluded even though MIME permits some quoted boundaries,
     * because the push sender never emits them and the narrower grammar avoids
     * ambiguous line syntax.
     *
     * @throws InvalidArgumentException If the token is empty, overlong, or unsafe.
     */
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

    /**
     * Reads one required CRLF-terminated syntax line within the fixed line cap.
     *
     * @param string $description Human-readable construct named in failures.
     * @return string Line contents without CRLF.
     *
     * @throws InvalidArgumentException If the line is overlong or lacks CRLF.
     * @throws RuntimeException If the stream ends before a line is available.
     */
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

    /**
     * Reads an exact, small amount of multipart syntax from a short-reading stream.
     *
     * This helper is used for fixed delimiters such as the CRLF after a part
     * body, not for body payloads; concatenation is therefore strictly bounded.
     *
     * @param int    $bytes Number of bytes required.
     * @param string $description Human-readable construct named in failures.
     * @return string Exactly $bytes bytes.
     *
     * @throws RuntimeException If the stream ends before all bytes arrive.
     */
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
