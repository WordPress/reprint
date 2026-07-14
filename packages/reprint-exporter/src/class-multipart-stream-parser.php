<?php

/**
 * Incrementally parses multipart/mixed response bytes for pull imports.
 *
 * Network callbacks may split a boundary, header, or body at any byte. Each
 * fragment is passed to feed(), which emits body bytes as soon as their part is
 * known and retains only bytes which might belong to an incomplete delimiter or
 * header. This lets the importer write a large export response progressively
 * instead of building the response body in memory.
 *
 * The callback receives two event shapes. Each completed part may produce any
 * number of `body` events followed by exactly one `complete` event:
 *
 *     $parser = new Site_Export_Multipart_Stream_Parser(
 *         'export-0123',
 *         function (array $event) use ($output): void {
 *             if ($event['type'] === 'body') {
 *                 fwrite($output, $event['data']);
 *                 return;
 *             }
 *             // The current part is complete; $event['headers'] identifies it.
 *         }
 *     );
 *
 *     $parser->feed($first_network_fragment);
 *     $parser->feed($next_network_fragment);
 *
 * A Content-Length part is framed by its declared byte count, so boundary-like
 * bytes inside its body are harmless. For compatibility with older pull
 * responses, a part without Content-Length is framed by the next boundary and
 * therefore retains a delimiter-sized tail between feed() calls. This lenient
 * response parser accepts either LF or CRLF. Staged push request bodies use the
 * stricter Site_Export_Multipart_Stream_Input instead.
 *
 * There is no finish() assertion: the HTTP caller is responsible for treating
 * a truncated response as a failed transfer. This parser only reports parts it
 * can complete from bytes supplied to feed().
 */
class Site_Export_Multipart_Stream_Parser
{
    /**
     * Maximum response bytes accepted in the buffer before a parse pass.
     *
     * Normal body streaming retains at most a boundary-sized tail. The 64 MiB
     * ceiling bounds one unexpectedly large network fragment and malformed
     * responses whose header line never supplies a line ending.
     */
    private const MAX_BUFFER_SIZE = 64 * 1024 * 1024;

    /** Parser state which searches for the next opening or closing delimiter. */
    private const STATE_BOUNDARY = 0;

    /** Parser state which accumulates header lines through their blank line. */
    private const STATE_HEADERS = 1;

    /** Parser state which emits the current part's body bytes. */
    private const STATE_BODY = 2;

    /** @var string MIME delimiter including its leading `--`. */
    private $boundary;

    /** @var int Number of bytes in $boundary, used to retain split delimiters. */
    private $boundary_length;

    /**
     * Bytes not yet decidable in the current parser state.
     *
     * @var string
     */
    private $buffer = "";

    /** @var int One of the STATE_* values describing what parse() expects next. */
    private $state = self::STATE_BOUNDARY;

    /** @var array<string,string> Lowercase headers for the part being emitted. */
    private $current_headers = [];

    /** @var int Body bytes emitted for the current part. */
    private $body_length = 0;

    /**
     * Declared Content-Length for the current part, or null for boundary framing.
     *
     * @var int|null
     */
    private $body_target = null;

    /**
     * Receives body and completion events synchronously while feed() advances.
     *
     * @var callable(array<string,mixed>):void
     */
    private $chunk_handler;

    /**
     * Creates a parser positioned before the response's first boundary.
     *
     * The boundary is supplied without leading `--`; the response's trusted
     * Content-Type parser is responsible for extracting it. Events are emitted
     * before feed() returns, so the callback should persist body data rather
     * than retain it.
     *
     * @param string $boundary MIME boundary token without leading `--`.
     * @param callable(array<string,mixed>):void $chunk_handler Receives body
     *     fragments and one completion event for each MIME part.
     */
    public function __construct(string $boundary, callable $chunk_handler)
    {
        $this->boundary = "--" . $boundary;
        $this->boundary_length = strlen($this->boundary);
        $this->chunk_handler = $chunk_handler;
    }

    /**
     * Consumes one arbitrary response fragment and emits every decidable event.
     *
     * The fragment may end in the middle of any delimiter, header, or body.
     * Empty fragments are permitted. An oversized input buffer indicates a
     * malformed or unreasonable response fragment and throws before parsing.
     *
     * @param string $data Next bytes received from the HTTP response.
     *
     * @throws RuntimeException If undecidable bytes exceed the safety ceiling.
     */
    public function feed(string $data): void
    {
        $this->buffer .= $data;
        if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
            throw new RuntimeException(
                "Multipart parser buffer exceeded 64MB — response may be malformed (missing boundary delimiter)."
            );
        }
        $this->parse();
    }

    /**
     * Advances the state machine until the buffered bytes are insufficient.
     *
     * State methods return true only after making a complete transition. A
     * false result leaves the smallest useful tail for a later feed() call.
     */
    private function parse(): void
    {
        while (true) {
            if ($this->state === self::STATE_BOUNDARY) {
                if (!$this->parse_boundary()) {
                    break;
                }
            } elseif ($this->state === self::STATE_HEADERS) {
                if (!$this->parse_headers()) {
                    break;
                }
            } elseif ($this->state === self::STATE_BODY) {
                if (!$this->parse_body()) {
                    break;
                }
            }
        }
    }

    /**
     * Consumes one boundary line, retaining a split delimiter for the next feed.
     *
     * @return bool True after entering header state, false when more bytes are needed
     *     or the closing delimiter has been consumed.
     */
    private function parse_boundary(): bool
    {
        // Look for boundary
        $pos = strpos($this->buffer, $this->boundary);
        if ($pos === false) {
            // Keep only last boundary_length bytes in case boundary is split
            if (strlen($this->buffer) > $this->boundary_length) {
                $this->buffer = substr($this->buffer, -$this->boundary_length);
            }
            return false;
        }

        // Check if this is the closing boundary (--boundary--)
        $after_boundary = $pos + $this->boundary_length;
        if ($after_boundary + 2 <= strlen($this->buffer)) {
            $next_chars = substr($this->buffer, $after_boundary, 2);
            if ($next_chars === "--") {
                // Closing boundary - done
                $this->buffer = "";
                return false;
            }
        }

        // Find end of line after boundary (\r\n or \n)
        $line_end = $this->find_line_end($after_boundary);
        if ($line_end === false) {
            return false; // Need more data
        }

        // Consume boundary line
        $this->buffer = substr($this->buffer, $line_end);
        $this->state = self::STATE_HEADERS;
        $this->current_headers = [];
        return true;
    }

    /**
     * Consumes complete header lines and enters body state at the blank line.
     *
     * Header names are normalized to lowercase for callback consumers. This
     * compatibility parser ignores malformed lines without a colon rather than
     * rejecting an otherwise usable legacy pull response.
     *
     * @return bool True after entering body state, false when a line is incomplete.
     */
    private function parse_headers(): bool
    {
        while (true) {
            // Check for blank line (end of headers)
            if (strlen($this->buffer) >= 2) {
                if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                    // \r\n - blank line
                    $this->buffer = substr($this->buffer, 2);
                    $this->prepare_body();
                    return true;
                } elseif ($this->buffer[0] === "\n") {
                    // \n - blank line
                    $this->buffer = substr($this->buffer, 1);
                    $this->prepare_body();
                    return true;
                }
            }

            // Find end of line
            $line_end = $this->find_line_end(0);
            if ($line_end === false) {
                return false; // Need more data
            }

            // Extract header line
            $line = substr($this->buffer, 0, $line_end);
            $this->buffer = substr($this->buffer, $line_end);

            // Trim line endings
            $line = rtrim($line, "\r\n");

            if ($line === "") {
                // Blank line - end of headers
                $this->prepare_body();
                return true;
            }

            // Parse header (find first colon)
            $colon_pos = strpos($line, ":");
            if ($colon_pos !== false) {
                $name = substr($line, 0, $colon_pos);
                $value = substr($line, $colon_pos + 1);

                // Trim spaces
                $name = trim($name);
                $value = ltrim($value); // Only left trim value

                // Store header (lowercase key)
                $key = strtolower($name);
                $this->current_headers[$key] = $value;
            }
        }
    }

    /**
     * Selects declared-length framing when present, or boundary framing otherwise.
     *
     * Body accounting is reset here, exactly once after each complete header
     * block, before any body event can be emitted.
     */
    private function prepare_body(): void
    {
        $this->state = self::STATE_BODY;
        $this->body_length = 0;

        // Determine target length if Content-Length is specified
        $this->body_target = isset($this->current_headers["content-length"])
            ? (int) $this->current_headers["content-length"]
            : null;
    }

    /**
     * Emits decidable body bytes and returns true only when the part is complete.
     *
     * Declared-length bodies can be emitted through their exact final byte.
     * Boundary-framed bodies keep enough trailing bytes to recognize a delimiter
     * split across the next feed() call.
     *
     * @return bool True after emitting the part's completion event, false when
     *     more response bytes are required.
     */
    private function parse_body(): bool
    {
        // If we know the content length, read exactly that many bytes
        if ($this->body_target !== null) {
            $remaining = $this->body_target - $this->body_length;

            if (strlen($this->buffer) < $remaining) {
                // Need more data
                if (strlen($this->buffer) > 0) {
                    // Process what we have
                    $this->emit_body_chunk(substr($this->buffer, 0));
                    $this->body_length += strlen($this->buffer);
                    $this->buffer = "";
                }
                return false;
            }

            // We have enough data
            $body_data = substr($this->buffer, 0, $remaining);
            $this->buffer = substr($this->buffer, $remaining);

            $this->emit_body_chunk($body_data);
            $this->body_length += strlen($body_data);

            // Skip trailing \r\n after body
            $this->skip_crlf();

            // Complete - move to next boundary
            $this->state = self::STATE_BOUNDARY;
            $this->emit_chunk_complete();
            return true;
        }

        // No content-length - read until next boundary
        // Look for boundary in buffer
        $boundary_pos = strpos($this->buffer, "\r\n" . $this->boundary);
        if ($boundary_pos === false) {
            $boundary_pos = strpos($this->buffer, "\n" . $this->boundary);
        }

        if ($boundary_pos === false) {
            // No boundary yet - process all but last boundary_length+2 bytes
            $safe_length = strlen($this->buffer) - $this->boundary_length - 2;
            if ($safe_length > 0) {
                $body_data = substr($this->buffer, 0, $safe_length);
                $this->buffer = substr($this->buffer, $safe_length);
                $this->emit_body_chunk($body_data);
                $this->body_length += strlen($body_data);
            }
            return false;
        }

        // Found boundary - emit remaining body
        $body_data = substr($this->buffer, 0, $boundary_pos);
        $this->buffer = substr($this->buffer, $boundary_pos);

        $this->emit_body_chunk($body_data);
        $this->body_length += strlen($body_data);

        // Skip \r\n before boundary
        $this->skip_crlf();

        // Complete - move to next boundary
        $this->state = self::STATE_BOUNDARY;
        $this->emit_chunk_complete();
        return true;
    }

    /**
     * Consumes the optional MIME line ending before the next boundary.
     *
     * Both CRLF and LF are accepted because this parser reads historical pull
     * responses. The staged push request reader deliberately requires CRLF.
     */
    private function skip_crlf(): void
    {
        if (
            strlen($this->buffer) >= 2 &&
            $this->buffer[0] === "\r" &&
            $this->buffer[1] === "\n"
        ) {
            $this->buffer = substr($this->buffer, 2);
        } elseif (strlen($this->buffer) >= 1 && $this->buffer[0] === "\n") {
            $this->buffer = substr($this->buffer, 1);
        }
    }

    /**
     * Finds the first line ending at or after an offset.
     *
     * @param int $offset First buffer position which may terminate the line.
     * @return int|false Position after the line ending, or false when incomplete.
     */
    private function find_line_end(int $offset)
    {
        $len = strlen($this->buffer);

        for ($i = $offset; $i < $len; $i++) {
            if ($this->buffer[$i] === "\n") {
                return $i + 1;
            }
            if (
                $this->buffer[$i] === "\r" &&
                $i + 1 < $len &&
                $this->buffer[$i + 1] === "\n"
            ) {
                return $i + 2;
            }
        }

        return false;
    }

    /**
     * Emits one non-empty body fragment with a snapshot of its part headers.
     *
     * @param string $data Decidable body bytes to pass through immediately.
     */
    private function emit_body_chunk(string $data): void
    {
        if ($data === "") {
            return;
        }

        ($this->chunk_handler)([
            "type" => "body",
            "headers" => $this->current_headers,
            "data" => $data,
        ]);
    }

    /**
     * Signals that every body byte for the current part has been emitted.
     *
     * Completion is a separate event so zero-length parts and a final short
     * body fragment have the same lifecycle as every other part.
     */
    private function emit_chunk_complete(): void
    {
        ($this->chunk_handler)([
            "type" => "complete",
            "headers" => $this->current_headers,
        ]);
    }
}
