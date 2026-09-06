<?php

/**
 * Finds literal getenv('NAME') reads without running the copied configuration.
 *
 * This does not decide whether a read is required: it may be conditional or
 * have a fallback. Dynamic names and included files need manual inspection.
 * Callers bound the configuration file size before tokenizing it.
 *
 * @return list<string> Distinct environment variable names, in source order.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches shared runtime helper names.
function config_environment_names(string $config): array
{
    $tokens = array_values(array_filter(token_get_all($config), static function ($token): bool {
        return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    $names = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array(strtolower($token[1]), ['getenv', '\\getenv'], true)) {
            continue;
        }
        $previous = $tokens[$index - 1] ?? null;
        if (is_array($previous) && in_array($previous[1], ['->', '?->', '::', 'function'], true)) {
            continue;
        }
        // PHP 7 represents a qualified function name with separate tokens.
        // Accept \getenv(), but not a function such as vendor\getenv().
        if (is_array($previous) && $previous[0] === T_NS_SEPARATOR) {
            $before_separator = $tokens[$index - 2] ?? null;
            if (is_array($before_separator) && $before_separator[0] === T_STRING) {
                continue;
            }
        }
        $argument = $tokens[$index + 2] ?? null;
        if (( $tokens[$index + 1] ?? null ) !== '('
            || !is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING
            || !in_array($tokens[$index + 3] ?? null, [')', ','], true)) {
            continue;
        }
        if (preg_match('/^[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]$/D', $argument[1], $matches) === 1) {
            $names[$matches[1]] = true;
        }
    }
    return array_keys($names);
}
