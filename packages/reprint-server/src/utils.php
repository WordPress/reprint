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
// (e.g. tests/ pulls in vendor/wp-php-toolkit/reprint-server/src/utils.php
// AND packages/reprint-server/src/utils.php), both copies are loaded.
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
 * Removes trailing slashes without changing the filesystem root into an empty path.
 *
 * Unlike rtrim($path, '/'), this returns `/` for both the filesystem root and
 * an empty input. It only changes the lexical spelling; it does not validate
 * the path or resolve dot segments and symlinks.
 *
 * Examples:
 *
 *     trim_right_slash('/srv/site///'); // '/srv/site'
 *     trim_right_slash('/');            // '/'
 *     trim_right_slash('');             // '/'
 *
 * @param string $path Path whose trailing slashes to remove.
 * @return string A path without trailing slashes, or `/` for the filesystem root.
 */
function trim_right_slash(string $path): string
{
    return rtrim($path, '/') ?: '/';
}

/**
 * Canonicalizes an absolute path through the nearest ancestor realpath() can resolve.
 *
 * The final components need not exist. The function resolves a real ancestor,
 * then appends the missing components without creating them. A broken symlink
 * cannot be resolved safely, so its normalized lexical spelling is retained.
 *
 * Examples:
 *
 *     realpath_with_missing_tail('/srv/site');
 *     // '/srv/site' when /srv/site exists
 *
 *     realpath_with_missing_tail('/srv/site/state/push');
 *     // '/srv/site/state/push' when /srv/site exists but state/push do not
 *
 *     realpath_with_missing_tail('/links/site/state');
 *     // '/srv/site/state' when /links/site is a symlink to /srv/site
 *
 * This does not create, remove, or otherwise modify filesystem entries.
 *
 * @throws InvalidArgumentException When $absolute_path is not absolute.
 */
function realpath_with_missing_tail(string $absolute_path): string
{
    if ($absolute_path === '' || $absolute_path[0] !== '/') {
        throw new InvalidArgumentException('Path must be absolute: ' . $absolute_path);
    }

    $normalized_path = normalize_path($absolute_path);
    $missing_components = [];
    $existing_ancestor = $normalized_path;
    $canonical_existing_ancestor = realpath($existing_ancestor);

    while ($canonical_existing_ancestor === false) {
        // Keep a broken symlink lexical: resolving past it would change what a
        // future replacement of that link means.
        if (is_link($existing_ancestor)) {
            return $normalized_path;
        }

        $parent = dirname($existing_ancestor);
        if ($parent === $existing_ancestor) {
            return $normalized_path;
        }
        array_unshift($missing_components, basename($existing_ancestor));
        $existing_ancestor = $parent;
        $canonical_existing_ancestor = realpath($existing_ancestor);
    }

    if ($missing_components === []) {
        return normalize_path($canonical_existing_ancestor);
    }

    return normalize_path(
        $canonical_existing_ancestor . '/' . implode('/', $missing_components)
    );
}

/**
 * Normalizes document-root-relative excluded paths.
 *
 * Rejects non-string, empty, absolute, NUL-containing, backslash-containing,
 * and empty/dot/parent-component paths, then sorts and deduplicates them.
 *
 * @param string[] $excluded_paths Paths which a push must not change.
 * @phpstan-param array<mixed> $excluded_paths
 * @return list<string> Validated excluded paths in bytewise order.
 */
function normalize_excluded_paths(array $excluded_paths): array
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These validation exceptions are never rendered, and arbitrary path bytes are represented as base64.
    $normalized_excluded_paths = [];
    foreach ($excluded_paths as $path) {
        if (!is_string($path)) {
            throw new InvalidArgumentException('Each excluded path must be a string; observed ' . gettype($path) . '.');
        }
        if ($path !== '' && $path[0] === '/') {
            throw new InvalidArgumentException('Excluded path must be document-root-relative: ' . base64_encode($path) . '.');
        }
        assert_valid_relative_path($path, 'Excluded path');
        $normalized_excluded_paths[] = $path;
    }
    sort($normalized_excluded_paths, SORT_STRING);
    $normalized_excluded_paths = array_values(array_unique($normalized_excluded_paths));
    if (count($normalized_excluded_paths) > 100) {
        throw new InvalidArgumentException(
            'Push supports at most 100 excluded paths; received '
            . count($normalized_excluded_paths)
            . ' after normalization.'
        );
    }
    return $normalized_excluded_paths;
}

/**
 * Validates a document-root-relative path carried as raw bytes.
 *
 * A valid path has one or more slash-delimited components. It cannot be
 * absolute, use Windows separators, include a NUL byte, or contain empty,
 * current-directory, or parent-directory components. It deliberately does
 * not trim whitespace: spaces and other non-reserved bytes are valid file
 * name bytes.
 *
 * Examples:
 *
 *     assert_valid_relative_path('wp-content/plugins', 'Excluded path');
 *     assert_valid_relative_path('index.php', 'Document-root-relative path');
 *
 * @param string $path Raw path bytes to validate.
 * @param string $label Human-readable name at the start of validation errors.
 * @throws InvalidArgumentException When the path has a reserved form.
 */
function assert_valid_relative_path(string $path, string $label): void
{
    if ($path === '') {
        throw new InvalidArgumentException("{$label} must not be empty.");
    }
    if ($path[0] === '/') {
        throw new InvalidArgumentException("{$label} must not be absolute: " . base64_encode($path) . '.');
    }
    if (strpos($path, "\0") !== false) {
        throw new InvalidArgumentException("{$label} must not contain a NUL byte: " . base64_encode($path) . '.');
    }
    if (strpos($path, '\\') !== false) {
        throw new InvalidArgumentException("{$label} must not contain a backslash: " . base64_encode($path) . '.');
    }
    foreach (explode('/', $path) as $component) {
        if ($component === '') {
            throw new InvalidArgumentException("{$label} must not contain an empty component: " . base64_encode($path) . '.');
        }
        if ($component === '.') {
            throw new InvalidArgumentException("{$label} must not contain a dot component: " . base64_encode($path) . '.');
        }
        if ($component === '..') {
            throw new InvalidArgumentException("{$label} must not contain a parent component: " . base64_encode($path) . '.');
        }
    }
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Indicates whether a candidate path is the same as or a descendant of an
 * ancestor.
 *
 * Either argument may be a list. The result is true when any candidate-and-
 * ancestor pair matches. The filesystem root matches every absolute path and
 * cannot use the normal ancestor-plus-slash prefix because that would produce
 * `//`.
 *
 * Examples:
 *
 *     path_is_same_as_or_descendant_of('/srv/site', '/srv/site');            // true
 *     path_is_same_as_or_descendant_of('/srv/site/wp-content', '/srv/site'); // true
 *     path_is_same_as_or_descendant_of('/srv/site-old', '/srv/site');        // false
 *     path_is_same_as_or_descendant_of('/', '/');                             // true
 *
 * @param string|list<string> $path Candidate path or paths.
 * @param string|list<string> $ancestor Ancestor path or paths.
 * @return bool Whether a candidate is the same as or a descendant of an
 *              ancestor.
 * @throws InvalidArgumentException If either scalar value is not a string.
 */
function path_is_same_as_or_descendant_of($path, $ancestor): bool
{
    if (is_array($path)) {
        foreach ($path as $candidate_path) {
            if (path_is_same_as_or_descendant_of($candidate_path, $ancestor)) {
                return true;
            }
        }
        return false;
    }
    if (is_array($ancestor)) {
        foreach ($ancestor as $candidate_ancestor) {
            if (path_is_same_as_or_descendant_of($path, $candidate_ancestor)) {
                return true;
            }
        }
        return false;
    }
    if (!is_string($path) || !is_string($ancestor)) {
        throw new InvalidArgumentException('Path containment expects strings or lists of strings.');
    }
    if ($ancestor === "/") {
        return str_starts_with($path, "/");
    }
    return $path === $ancestor || str_starts_with($path, $ancestor . "/");
}

/**
 * Indicates whether a candidate path is a descendant of an ancestor.
 *
 * Either argument may be a list. The result is true when any candidate-and-
 * ancestor pair has a component-boundary match below the ancestor. Unlike
 * path_is_same_as_or_descendant_of(), equal paths do not match. The
 * filesystem root contains every absolute descendant, but not itself.
 *
 * Examples:
 *
 *     path_is_descendant_of('/srv/site/wp-content', '/srv/site'); // true
 *     path_is_descendant_of('/srv/site', '/srv/site');            // false
 *     path_is_descendant_of('/srv/site-old', '/srv/site');        // false
 *     path_is_descendant_of('/wp-content', '/');                  // true
 *     path_is_descendant_of('/', '/');                             // false
 *
 * @param string|list<string> $path Candidate path or paths.
 * @param string|list<string> $ancestor Ancestor path or paths.
 * @return bool Whether a candidate is a descendant of an ancestor.
 * @throws InvalidArgumentException If either scalar value is not a string.
 */
function path_is_descendant_of($path, $ancestor): bool
{
    if (is_array($path)) {
        foreach ($path as $candidate_path) {
            if (path_is_descendant_of($candidate_path, $ancestor)) {
                return true;
            }
        }
        return false;
    }
    if (is_array($ancestor)) {
        foreach ($ancestor as $candidate_ancestor) {
            if (path_is_descendant_of($path, $candidate_ancestor)) {
                return true;
            }
        }
        return false;
    }
    if (!path_is_same_as_or_descendant_of($path, $ancestor)) {
        return false;
    }
    return $path !== $ancestor;
}

/**
 * Returns the remainder of $path underneath $prefix.
 *
 * An exact match returns an empty string. A descendant returns the remainder
 * beginning with "/". A path outside $prefix returns null.
 */
function path_remainder_under(string $path, string $prefix): ?string
{
    $path = rtrim($path, "/");
    $prefix = rtrim($prefix, "/");

    if ($path === $prefix) {
        return "";
    }

    if (str_starts_with($path, $prefix . "/")) {
        return substr($path, strlen($prefix));
    }

    return null;
}

/**
 * Returns a path relative to a slash-delimited root, or null when it is not
 * equal to or below that root.
 *
 * Use this when a caller needs a path for a root-relative field. It performs
 * the component-boundary test and removes the separating slash in one step,
 * rather than letting a byte-offset slice treat `/srv/site-old` as below
 * `/srv/site`.
 *
 * Examples:
 *
 *     relative_path_under('/srv/site/wp-content', '/srv/site'); // 'wp-content'
 *     relative_path_under('/srv/site', '/srv/site');            // ''
 *     relative_path_under('/srv/site-old', '/srv/site');        // null
 *     relative_path_under('/wp-content', '/');                  // 'wp-content'
 *     relative_path_under('wp-content/plugins', '');            // 'wp-content/plugins'
 *
 * Trailing slashes do not change the result. This is a lexical operation: it
 * does not resolve dot segments or symlinks, and it also accepts relative
 * slash-delimited paths. An empty root contains every relative path, but no
 * absolute path.
 *
 * @param string $path Candidate path to make relative.
 * @param string $root Root that must contain the candidate path.
 * @return string|null A path without a leading slash, an empty string for an
 *                     exact match, or null when the path is outside the root.
 */
function relative_path_under(string $path, string $root): ?string
{
    if ($root === "") {
        return str_starts_with($path, "/") ? null : rtrim($path, "/");
    }
    $remainder = path_remainder_under($path, $root);
    return $remainder === null ? null : ltrim($remainder, "/");
}

/**
 * Builds one file-index entry from a completed lstat() call.
 *
 * File and link sizes participate in change detection. A directory's stat
 * size does not describe its descendants, so directory size is always zero.
 * Symlink targets and directory contents are left to callers because neither
 * comes from the stat record.
 *
 * @param string $path      Absolute path inspected by lstat().
 * @param array  $path_stat Value returned by lstat().
 * @return array {
 *     File-index entry.
 *
 *     @type string $path  Absolute path.
 *     @type int    $ctime Local ctime.
 *     @type int    $size  File or link size. Other types use zero.
 *     @type string $type  `file`, `dir`, `link`, or `other`.
 * }
 * @phpstan-param array{mode:int,ctime?:int,size?:int} $path_stat
 * @phpstan-return array{path:string,ctime:int,size:int,type:'file'|'dir'|'link'|'other'}
 */
function read_file_index_entry_from_stat(string $path, array $path_stat): array
{
    $path_type_bits = $path_stat["mode"] & 0170000;
    if ($path_type_bits === 0120000) {
        $path_type = "link";
    } elseif ($path_type_bits === 0040000) {
        $path_type = "dir";
    } elseif ($path_type_bits === 0100000) {
        $path_type = "file";
    } else {
        $path_type = "other";
    }

    $entry = [
        "path" => $path,
        "ctime" => (int) ( $path_stat["ctime"] ?? 0 ),
        "size" => $path_type === "file" || $path_type === "link"
            ? (int) ( $path_stat["size"] ?? 0 )
            : 0,
        "type" => $path_type,
    ];
    return $entry;
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
