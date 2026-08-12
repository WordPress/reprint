<?php

namespace Reprint\Importer\UrlRewrite;

/**
 * Return whether the first $bytes of a value could be the prefix of valid JSON.
 *
 * A prefix may end inside a JSON token or an open array/object. Bytes after
 * the prefix are deliberately not inspected.
 */
function is_valid_json_prefix(string $document, int $bytes = 100): bool
{
    static $string_terminators = null;

    if ($string_terminators === null) {
        $string_terminators = '"\\' . implode('', array_map('chr', range(0, 31)));
    }

    $length = min(strlen($document), $bytes);
    $pos = strspn($document, " \t\r\n", 0, $length);

    if ($pos === $length) {
        return true;
    }

    if (!str_contains('"{[-0123456789tfn', $document[$pos])) {
        return false;
    }

    $parse_value = null;

    $skip_whitespace = static function () use (&$document, &$pos, $length): void {
        $pos += strspn($document, " \t\r\n", $pos, $length - $pos);
    };

    $parse_string = static function () use (&$document, &$pos, $length, $string_terminators): bool {
        ++$pos;

        while ($pos < $length) {
            $pos += strcspn($document, $string_terminators, $pos, $length - $pos);
            if ($pos === $length) {
                return true;
            }

            $char = $document[$pos++];
            if ($char === '"') {
                return true;
            }
            if (ord($char) < 0x20) {
                return false;
            }
            if ($pos === $length) {
                return true;
            }

            $escape = $document[$pos++];
            if (str_contains('"\\/bfnrt', $escape)) {
                continue;
            }
            if ($escape !== 'u') {
                return false;
            }

            $available = min(4, $length - $pos);
            if (strspn($document, '0123456789abcdefABCDEF', $pos, $available) !== $available) {
                return false;
            }
            if ($available < 4) {
                $pos = $length;
                return true;
            }

            $pos += 4;
        }

        return true;
    };

    $parse_number = static function () use (&$document, &$pos, $length): bool {
        if ($document[$pos] === '-') {
            ++$pos;
            if ($pos === $length) {
                return true;
            }
        }

        if ($document[$pos] === '0') {
            ++$pos;
        } elseif (strspn($document, '123456789', $pos, 1) === 1) {
            $pos += strspn($document, '0123456789', $pos, $length - $pos);
        } else {
            return false;
        }

        if ($pos < $length && $document[$pos] === '.') {
            ++$pos;
            if ($pos === $length) {
                return true;
            }

            $digits = strspn($document, '0123456789', $pos, $length - $pos);
            if ($digits === 0) {
                return false;
            }
            $pos += $digits;
        }

        if ($pos < $length && ( $document[$pos] === 'e' || $document[$pos] === 'E' )) {
            ++$pos;
            if ($pos < $length && str_contains( '+-', $document[$pos] )) {
                ++$pos;
            }
            if ($pos === $length) {
                return true;
            }

            $digits = strspn($document, '0123456789', $pos, $length - $pos);
            if ($digits === 0) {
                return false;
            }
            $pos += $digits;
        }

        return true;
    };

    $parse_value = function () use (&$parse_value, &$document, &$pos, $length, $skip_whitespace, $parse_string, $parse_number): bool {
        $skip_whitespace();
        if ($pos === $length) {
            return true;
        }

        $char = $document[$pos];
        if ($char === '"') {
            return $parse_string();
        }

        if ($char !== '{' && $char !== '[') {
            foreach (['true', 'false', 'null'] as $literal) {
                $available = min(strlen($literal), $length - $pos);
                if (substr_compare($document, $literal, $pos, $available) === 0) {
                    $pos += $available;
                    return true;
                }
            }

            return $parse_number();
        }

        $is_object = $char === '{';
        $close = $is_object ? '}' : ']';
        ++$pos;
        $skip_whitespace();

        if ($pos === $length) {
            return true;
        }
        if ($document[$pos] === $close) {
            ++$pos;
            return true;
        }

        while (true) {
            if ($is_object) {
                if ($document[$pos] !== '"' || !$parse_string()) {
                    return false;
                }

                $skip_whitespace();
                if ($pos === $length) {
                    return true;
                }
                if ($document[$pos++] !== ':') {
                    return false;
                }
            }

            if (!$parse_value()) {
                return false;
            }

            $skip_whitespace();
            if ($pos === $length) {
                return true;
            }

            $separator = $document[$pos++];
            if ($separator === $close) {
                return true;
            }
            if ($separator !== ',') {
                return false;
            }

            $skip_whitespace();
            if ($pos === $length) {
                return true;
            }
        }
    };

    if (!$parse_value()) {
        return false;
    }

    $skip_whitespace();
    return $pos === $length;
}
