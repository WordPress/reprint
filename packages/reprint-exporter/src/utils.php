<?php
/**
 * Shared utility functions used by both export.php and import.php.
 *
 * These helpers live in a namespace so they don't collide with global
 * functions of the same name declared by third-party plugins or
 * WordPress drop-ins. The package is loaded via Composer's "files"
 * autoload, which means every host that pulls in this library (e.g.
 * wpcomsh on WordPress.com) gets these symbols on every request —
 * generic names like parse_size() or normalize_path() are guaranteed
 * to clash sooner or later if they sit in the global namespace.
 *
 * The two str_* polyfills at the top stay global on purpose: they
 * backfill PHP 7.4 built-ins, so callers expect to reach them via
 * the global namespace without a use-statement.
 */

// Polyfill for PHP 7.4 which lacks str_starts_with().
namespace {
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    // Polyfill for PHP 7.4 which lacks str_contains().
    if (!function_exists('str_contains')) {
        function str_contains(string $haystack, string $needle): bool {
            return $needle === '' || strpos($haystack, $needle) !== false;
        }
    }
}

namespace WordPress\Reprint\Exporter {

use InvalidArgumentException;
use RuntimeException;

// Composer's "files" autoload includes this file once per registered
// path. In a monorepo where the same package is mirrored into vendor/
// (e.g. tests/ pulls in vendor/wp-php-toolkit/reprint-exporter/src/utils.php
// AND packages/reprint-exporter/src/utils.php), both copies are loaded.
// `return` from inside a bracketed namespace block does not abort the
// whole file, so guard the declarations themselves.
if (!function_exists(__NAMESPACE__ . '\\build_pdo_dsn')) {

/**
 * Builds a PDO DSN string from a WordPress DB_HOST value.
 *
 * WordPress's DB_HOST supports several non-standard formats that shared
 * hosts commonly use:
 *   - "localhost"              → standard hostname
 *   - "db.host.com:3307"      → hostname with port
 *   - "localhost:/path/sock"   → hostname with Unix socket
 *   - "/path/to/mysql.sock"   → bare Unix socket path
 *   - "::1"                   → IPv6 address
 *   - "[::1]"                 → bracketed IPv6
 *   - "[::1]:3306"            → bracketed IPv6 with port
 *   - "[::1]:/path/to/socket" → bracketed IPv6 with Unix socket
 *
 * PDO needs these broken out into separate DSN parameters (host, port,
 * unix_socket), so we parse the value the same way WordPress core does.
 *
 * @param string $db_host  Raw DB_HOST value.
 * @param string $db_name  Database name.
 * @return string PDO DSN string.
 */
function build_pdo_dsn(string $db_host, string $db_name): string
{
    $socket = '';
    $host   = $db_host;
    $port   = '';

    if (str_starts_with($db_host, '/') && file_exists($db_host)) {
        // Bare socket path: "/var/run/mysqld/mysqld.sock"
        $socket = $db_host;
        $host   = '';
    } elseif (
        str_starts_with($db_host, '[') &&
        ($bracket_end = strpos($db_host, ']')) !== false
    ) {
        // Bracketed IPv6: "[::1]", "[::1]:3306", "[::1]:/path/to/socket"
        $host = substr($db_host, 1, $bracket_end - 1);
        $after = substr($db_host, $bracket_end + 1);
        $candidate_socket = str_starts_with($after, ':/') ? substr($after, 1) : '';
        if ($candidate_socket !== '' && file_exists($candidate_socket)) {
            $socket = $candidate_socket;
        } elseif (str_starts_with($after, ':')) {
            $port = substr($after, 1);
        }
    } elseif (($socket_pos = strpos($db_host, ':/')) !== false) {
        // "host:/path/to/socket" — check before general colon split
        // to avoid misinterpreting IPv6 addresses as host:port
        $candidate_socket = substr($db_host, $socket_pos + 1);
        if (file_exists($candidate_socket)) {
            $host   = substr($db_host, 0, $socket_pos);
            $socket = $candidate_socket;
        } elseif (substr_count($db_host, ':') === 1) {
            // Single colon but not a socket — treat as host:port
            [$host, $port] = explode(':', $db_host, 2);
        }
    } elseif (
        str_contains($db_host, ':') &&
        substr_count($db_host, ':') === 1
    ) {
        // Exactly one colon: "host:port" — not IPv6
        [$host, $port] = explode(':', $db_host, 2);
    }
    // Otherwise (multiple colons, no socket marker): bare IPv6 like "::1"
    // — $host stays as the full value.

    if ($socket !== '') {
        return "mysql:unix_socket={$socket};dbname={$db_name};charset=utf8mb4";
    }

    $dsn = "mysql:host={$host}";
    if ($port !== '') {
        $dsn .= ";port={$port}";
    }
    $dsn .= ";dbname={$db_name};charset=utf8mb4";
    return $dsn;
}

/**
 * Parse a human-readable size string (e.g. "16M", "1G", "512K") into bytes.
 * Accepts plain integers as well.
 */
function parse_size(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg])?[Bb]?$/', $value, $m)) {
        throw new InvalidArgumentException(
            "Invalid size value: '{$value}'. Use a number optionally followed by K, M, or G (e.g. 64M)."
        );
    }
    $num = (float) $m[1];
    $suffix = strtoupper($m[2] ?? "");
    switch ($suffix) {
        case "K":
            return (int) ($num * 1024);
        case "M":
            return (int) ($num * 1024 * 1024);
        case "G":
            return (int) ($num * 1024 * 1024 * 1024);
        default:
            return (int) $num;
    }
}

/**
 * Throws on json_encode failure instead of returning false.
 *
 * Do NOT use inside error/shutdown handlers — those need hardcoded fallback strings.
 */
function json_encode_or_throw($value, int $flags = 0): string
{
    $json = json_encode($value, $flags);
    if ($json === false) {
        throw new RuntimeException("json_encode failed: " . json_last_error_msg());
    }
    return $json;
}

/**
 * Resolve ".." and "." segments in a path without touching the filesystem.
 *
 * Unlike realpath(), this works on paths that don't exist yet.
 */
function normalize_path(string $path): string
{
    $parts = explode("/", $path);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === "" || $part === ".") {
            continue;
        }
        if ($part === "..") {
            array_pop($resolved);
        } else {
            $resolved[] = $part;
        }
    }
    return "/" . implode("/", $resolved);
}

/**
 * Returns true when $path is equal to $root or strictly under it.
 */
function path_is_within_root(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root . "/");
}

/**
 * Validates that a path is a non-empty absolute string without NUL bytes
 * or dot-segments (. or ..).
 *
 * Useful anywhere untrusted or remote paths need to be checked before
 * use — both the exporter (directory config) and the importer (remote
 * paths from the server) share this validation.
 *
 * @param string $path  The path to validate.
 * @param string $label Human-readable label for error messages (e.g. "directory", "remote path").
 * @throws InvalidArgumentException When the path fails any check.
 */
function assert_valid_path(string $path, string $label = "path"): void
{
    $path = trim($path);
    if ($path === "") {
        throw new InvalidArgumentException("{$label} must be a non-empty string");
    }
    if ($path[0] !== "/") {
        throw new InvalidArgumentException("{$label} must be an absolute path: {$path}");
    }
    if (strpos($path, "\0") !== false) {
        throw new InvalidArgumentException("{$label} must not contain NUL bytes");
    }
    foreach (explode("/", $path) as $segment) {
        if ($segment === "." || $segment === "..") {
            throw new InvalidArgumentException(
                "{$label} must not contain dot-segments (. or ..): {$path}"
            );
        }
    }
}

} // !function_exists guard

}
