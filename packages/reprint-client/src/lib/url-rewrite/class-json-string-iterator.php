<?php

/**
 * Cursor-based iterator over string leaf values in a JSON value.
 *
 * Mirrors the PhpSerializationProcessor API: construct with the raw JSON,
 * then loop with next_value()/get_value()/set_value(). Call get_result()
 * to retrieve the (possibly modified) JSON string.
 *
 * Only string values are exposed — numbers, booleans, and nulls are skipped.
 * JSON string scalars are exposed as a single value. For objects and arrays,
 * object values and array elements are visited. Object keys are NOT visited
 * (they're structural, not content), matching the PHP serialization
 * processor's behavior of skipping array keys and property names.
 *
 * Usage:
 *
 *     $iter = new JsonStringIterator($json);
 *     while ($iter->next_value()) {
 *         $iter->set_value(str_replace('old', 'new', $iter->get_value()));
 *     }
 *     $result = $iter->get_result();
 */
class JsonStringIterator
{
    /** @var string The original JSON string. */
    private string $original;

    /** @var mixed The decoded JSON value (object decoded as array). */
    private $decoded;

    /** @var bool Whether decoding succeeded. */
    private bool $valid;

    /** @var bool Whether any value has been modified via set_value(). */
    private bool $changed = false;

    /**
     * Paths to all string leaf values in the decoded structure.
     * Each path is an array of keys/indices leading to a string value.
     * @var array<int, array<int, string|int>>
     */
    private array $paths = [];

    /** @var int Current cursor position. -1 means before the first value. */
    private int $cursor = -1;

    public function __construct(string $json)
    {
        $this->original = $json;
        $this->decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || (!is_array($this->decoded) && !is_string($this->decoded))) {
            $this->valid = false;
            return;
        }

        $this->valid = true;
        $this->enumerate($this->decoded, []);
    }

    /**
     * Whether the input was not valid JSON or had no string leaves.
     *
     * Mirrors PhpSerializationProcessor::is_malformed() so both iterators
     * can be used with the same try-and-fail pattern.
     */
    public function is_malformed(): bool
    {
        return !$this->valid;
    }

    /**
     * Advance to the next string value.
     *
     * @return bool True if there is another value, false if iteration is complete.
     */
    public function next_value(): bool
    {
        if (!$this->valid) {
            return false;
        }
        $this->cursor++;
        return $this->cursor < count($this->paths);
    }

    /**
     * Get the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function get_value(): string
    {
        return $this->navigate($this->paths[$this->cursor]);
    }

    /**
     * Replace the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function set_value(string $new_value): void
    {
        $path = $this->paths[$this->cursor];
        $this->setAtPath($path, $new_value);
        $this->changed = true;
    }

    /**
     * Get the result JSON string.
     *
     * Returns the original string if nothing was modified, otherwise
     * re-encodes the mutated structure.
     */
    public function get_result(): string
    {
        if (!$this->changed) {
            return $this->original;
        }

        return json_encode($this->decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively enumerate all string leaf values and record their paths.
     *
     * @param mixed $data The current node in the decoded structure.
     * @param array<int, string|int> $path The path of keys leading to this node.
     */
    private function enumerate($data, array $path): void
    {
        if (is_string($data)) {
            $this->paths[] = $path;
            return;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $this->enumerate($value, array_merge($path, [$key]));
            }
        }
    }

    /**
     * Navigate the decoded structure to the value at $path.
     *
     * @param array<int, string|int> $path
     * @return string
     */
    private function navigate(array $path): string
    {
        $node = $this->decoded;
        foreach ($path as $key) {
            $node = $node[$key];
        }
        return $node;
    }

    /**
     * Set the value at $path in the decoded structure.
     *
     * @param array<int, string|int> $path
     * @param string $value
     */
    private function setAtPath(array $path, string $value): void
    {
        $ref = &$this->decoded;
        foreach ($path as $key) {
            $ref = &$ref[$key];
        }
        $ref = $value;
    }
}
