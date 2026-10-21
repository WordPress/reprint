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
     * References to the arrays enclosing the current position, outermost first.
     *
     * Each array's internal pointer marks the element being visited. Only the
     * current path is held, so memory stays bounded by the nesting depth.
     * Storing a key path per leaf would grow with leaves x nesting depth, so a
     * deeply nested array of many short strings could exhaust memory_limit.
     * @var array<int, array<int|string, mixed>>
     */
    private array $stack = [];

    /** @var bool Whether next_value() has been called at least once. */
    private bool $started = false;

    /** @var bool Whether the cursor is on a string value. */
    private bool $on_value = false;

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

        if (!$this->started) {
            $this->started = true;
            if (is_string($this->decoded)) {
                $this->on_value = true;
                return true;
            }
            $this->stack[] = &$this->decoded;
            reset($this->decoded);
        } elseif ($this->on_value && $this->stack !== []) {
            next($this->stack[count($this->stack) - 1]);
        }
        $this->on_value = false;

        while ($this->stack !== []) {
            $node = &$this->stack[count($this->stack) - 1];
            $key = key($node);
            if ($key === null) {
                unset($node);
                array_pop($this->stack);
                if ($this->stack !== []) {
                    next($this->stack[count($this->stack) - 1]);
                }
                continue;
            }
            if (is_string($node[$key])) {
                $this->on_value = true;
                return true;
            }
            if (is_array($node[$key]) && $node[$key] !== []) {
                $this->stack[] = &$node[$key];
                reset($node[$key]);
                unset($node);
                continue;
            }
            next($node);
            unset($node);
        }

        return false;
    }

    /**
     * Get the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function get_value(): string
    {
        if ($this->stack === []) {
            return $this->decoded;
        }
        $node = &$this->stack[count($this->stack) - 1];
        return $node[key($node)];
    }

    /**
     * Replace the current string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function set_value(string $new_value): void
    {
        if ($this->stack === []) {
            $this->decoded = $new_value;
        } else {
            $node = &$this->stack[count($this->stack) - 1];
            $node[key($node)] = $new_value;
        }
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
}
