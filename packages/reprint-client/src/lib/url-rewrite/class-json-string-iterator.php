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

    /** @var Generator|null Traversal paused at the current string leaf. */
    private ?Generator $strings = null;

    /** @var string Reference to the current decoded string, not a copy of its path. */
    private string $current_value = '';

    public function __construct(string $json)
    {
        $this->original = $json;
        $this->decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || (!is_array($this->decoded) && !is_string($this->decoded))) {
            $this->valid = false;
            return;
        }

        $this->valid = true;
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
        if ($this->strings === null) {
            $this->strings = $this->enumerate($this->decoded);
        } else {
            $this->strings->next();
        }
        return $this->strings->valid();
    }

    /**
     * Get the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function get_value(): string
    {
        return $this->current_value;
    }

    /**
     * Replace the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function set_value(string $new_value): void
    {
        $this->current_value = $new_value;
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
     * Visit strings by reference so set_value() edits the decoded tree in place.
     *
     * Retain only the active traversal. Recording a full path for every leaf
     * multiplies memory use by the nesting depth, even for a small JSON value.
     *
     * @param mixed $data The current node in the decoded structure.
     * @return Generator<int, null> Traversal positions, with the current string retained by reference.
     */
    private function enumerate(&$data): Generator
    {
        if (is_string($data)) {
            $this->current_value = &$data;
            yield null;
            return;
        }

        if (is_array($data)) {
            foreach ($data as &$value) {
                yield from $this->enumerate($value);
            }
        }
    }
}
