<?php

namespace Reprint\Importer\Protocol;

use RuntimeException;

/**
 * Incrementally parses multipart/mixed response bodies.
 *
 * The parser owns only protocol state: boundary detection, part headers, and
 * body framing. It does not know about cURL, files, progress output, or import
 * commands. Callers receive streamed events through the supplied handler:
 *
 * - body:     ["type" => "body", "headers" => array, "data" => string]
 * - complete: ["type" => "complete", "headers" => array]
 */
class MultipartStreamParser
{
    public const EVENT_BODY = "body";
    public const EVENT_COMPLETE = "complete";

    private const MAX_BUFFER_SIZE = 64 * 1024 * 1024;
    private const STATE_BOUNDARY = 0;
    private const STATE_HEADERS = 1;
    private const STATE_BODY = 2;

    private string $boundary;
    private int $boundary_length;
    private string $buffer = "";
    private int $state = self::STATE_BOUNDARY;
    /** @var array<string, string> */
    private array $current_headers = [];
    private int $body_length = 0;
    private ?int $body_target = null;
    /** @var callable(array<string, mixed>): void */
    private $chunk_handler;

    /**
     * @param callable(array<string, mixed>): void $chunk_handler
     */
    public function __construct(string $boundary, callable $chunk_handler)
    {
        $this->boundary = "--" . $boundary;
        $this->boundary_length = strlen($this->boundary);
        $this->chunk_handler = $chunk_handler;
    }

    public function feed(string $data): void
    {
        $this->buffer .= $data;
        if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
            throw new RuntimeException(
                "Multipart parser buffer exceeded 64MB - response may be malformed (missing boundary delimiter)."
            );
        }

        $this->parse();
    }

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

    private function parse_boundary(): bool
    {
        $pos = strpos($this->buffer, $this->boundary);
        if ($pos === false) {
            if (strlen($this->buffer) > $this->boundary_length) {
                $this->buffer = substr($this->buffer, -$this->boundary_length);
            }
            return false;
        }

        $after_boundary = $pos + $this->boundary_length;
        if ($after_boundary + 2 <= strlen($this->buffer)) {
            $next_chars = substr($this->buffer, $after_boundary, 2);
            if ($next_chars === "--") {
                $this->buffer = "";
                return false;
            }
        }

        $line_end = $this->find_line_end($after_boundary);
        if ($line_end === false) {
            return false;
        }

        $this->buffer = substr($this->buffer, $line_end);
        $this->state = self::STATE_HEADERS;
        $this->current_headers = [];
        return true;
    }

    private function parse_headers(): bool
    {
        while (true) {
            if (strlen($this->buffer) >= 2) {
                if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                    $this->buffer = substr($this->buffer, 2);
                    $this->prepare_body();
                    return true;
                } elseif ($this->buffer[0] === "\n") {
                    $this->buffer = substr($this->buffer, 1);
                    $this->prepare_body();
                    return true;
                }
            }

            $line_end = $this->find_line_end(0);
            if ($line_end === false) {
                return false;
            }

            $line = substr($this->buffer, 0, $line_end);
            $this->buffer = substr($this->buffer, $line_end);
            $line = rtrim($line, "\r\n");

            if ($line === "") {
                $this->prepare_body();
                return true;
            }

            $colon_pos = strpos($line, ":");
            if ($colon_pos !== false) {
                $name = substr($line, 0, $colon_pos);
                $value = substr($line, $colon_pos + 1);

                $this->current_headers[strtolower(trim($name))] = ltrim($value);
            }
        }
    }

    private function prepare_body(): void
    {
        $this->state = self::STATE_BODY;
        $this->body_length = 0;
        $this->body_target = isset($this->current_headers["content-length"])
            ? (int) $this->current_headers["content-length"]
            : null;
    }

    private function parse_body(): bool
    {
        if ($this->body_target !== null) {
            return $this->parse_sized_body();
        }

        return $this->parse_boundary_terminated_body();
    }

    private function parse_sized_body(): bool
    {
        $remaining = $this->body_target - $this->body_length;

        if (strlen($this->buffer) < $remaining) {
            if (strlen($this->buffer) > 0) {
                $this->emit_body_chunk($this->buffer);
                $this->body_length += strlen($this->buffer);
                $this->buffer = "";
            }
            return false;
        }

        $body_data = substr($this->buffer, 0, $remaining);
        $this->buffer = substr($this->buffer, $remaining);

        $this->emit_body_chunk($body_data);
        $this->body_length += strlen($body_data);
        $this->skip_crlf();
        $this->complete_part();
        return true;
    }

    private function parse_boundary_terminated_body(): bool
    {
        $boundary_pos = strpos($this->buffer, "\r\n" . $this->boundary);
        if ($boundary_pos === false) {
            $boundary_pos = strpos($this->buffer, "\n" . $this->boundary);
        }

        if ($boundary_pos === false) {
            $safe_length = strlen($this->buffer) - $this->boundary_length - 2;
            if ($safe_length > 0) {
                $body_data = substr($this->buffer, 0, $safe_length);
                $this->buffer = substr($this->buffer, $safe_length);
                $this->emit_body_chunk($body_data);
                $this->body_length += strlen($body_data);
            }
            return false;
        }

        $body_data = substr($this->buffer, 0, $boundary_pos);
        $this->buffer = substr($this->buffer, $boundary_pos);

        $this->emit_body_chunk($body_data);
        $this->body_length += strlen($body_data);
        $this->skip_crlf();
        $this->complete_part();
        return true;
    }

    private function complete_part(): void
    {
        $this->state = self::STATE_BOUNDARY;
        $this->emit_chunk_complete();
    }

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
     * @return int|false
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

    private function emit_body_chunk(string $data): void
    {
        if ($data === "") {
            return;
        }

        ($this->chunk_handler)([
            "type" => self::EVENT_BODY,
            "headers" => $this->current_headers,
            "data" => $data,
        ]);
    }

    private function emit_chunk_complete(): void
    {
        ($this->chunk_handler)([
            "type" => self::EVENT_COMPLETE,
            "headers" => $this->current_headers,
        ]);
    }
}
