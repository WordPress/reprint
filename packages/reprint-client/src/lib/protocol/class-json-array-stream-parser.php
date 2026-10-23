<?php

namespace Reprint\Importer\Protocol;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Parser errors are CLI text, not HTML.
use RuntimeException;

/**
 * Reads a JSON array one value at a time, retaining only its unfinished value.
 * Container and string boundaries are scanned once; json_decode validates each
 * complete value. A caller may discard emitted values before feeding more bytes.
 */
class JsonArrayStreamParser {
    private bool $started = false;
    private bool $finished = false;
    private bool $allow_empty = true;
    private bool $in_string = false;
    private bool $escaped = false;
    private int $depth = 0;
    private string $value = '';
    /** @var callable(mixed): void */
    private $on_value;

    /** @param callable(mixed): void $on_value Receives each decoded value before it is discarded. */
    public function __construct(callable $on_value) {
        $this->on_value = $on_value;
    }

    public function feed(string $data): void {
        $length = strlen($data);
        $offset = 0;
        if (!$this->started) {
            $offset = strspn($data, " \t\r\n");
            if ($offset === $length) {
                return;
            }
            if ($data[$offset] !== '[') {
                throw new RuntimeException('Expected a JSON array beginning with [, received ' . json_encode($data[$offset]) . '.');
            }
            $this->started = true;
            ++$offset;
        }
        $start = $offset;
        while ($offset < $length) {
            if ($this->finished) {
                if (strspn($data, " \t\r\n", $offset) !== $length - $offset) {
                    throw new RuntimeException('Unexpected non-whitespace bytes after the JSON array.');
                }
                return;
            }
            if ($this->escaped) {
                $this->escaped = false;
                ++$offset;
                continue;
            }
            $offset += strcspn($data, $this->in_string ? '"\\' : '{}[],"', $offset);
            if ($offset === $length) {
                break;
            }
            $byte = $data[$offset];
            if ($this->in_string) {
                if ($byte === '\\') {
                    $this->escaped = true;
                } else {
                    $this->in_string = false;
                }
            } elseif ($byte === '"') {
                $this->in_string = true;
            } elseif ($this->depth === 0 && ( $byte === ',' || $byte === ']' )) {
                $this->value .= substr($data, $start, $offset - $start);
                $value = trim($this->value, " \t\r\n");
                if ($value === '') {
                    if ($byte !== ']' || !$this->allow_empty) {
                        throw new RuntimeException('Missing JSON array value before ' . $byte . '.');
                    }
                } else {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new RuntimeException('Invalid JSON array value: ' . json_last_error_msg() . '.');
                    }
                    ( $this->on_value )($decoded);
                }
                $this->value = '';
                $this->allow_empty = false;
                $this->finished = $byte === ']';
                $start = $offset + 1;
            } elseif ($byte === '{' || $byte === '[') {
                ++$this->depth;
            } elseif ($byte === '}' || $byte === ']') {
                --$this->depth;
                if ($this->depth < 0) {
                    throw new RuntimeException('Unexpected closing container ' . $byte . ' in JSON array.');
                }
            }
            ++$offset;
        }
        if (!$this->finished) {
            $this->value .= substr($data, $start);
        }
    }

    /** A complete multipart part must also contain a complete JSON array. */
    public function finish(): void {
        if (!$this->finished) {
            throw new RuntimeException('JSON array ended before its closing ].');
        }
    }
}
