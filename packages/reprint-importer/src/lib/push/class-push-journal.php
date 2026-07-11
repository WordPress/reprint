<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This command-line library interpolates values only into local exception messages, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Public importer types follow this package's established unprefixed API.

/**
 * Names the per-target baseline used by direct staged pushes.
 *
 * ctime is machine-local, so a sender compares the current local index only
 * with its own last completed push to this target. The direct session driver
 * streams that comparison itself and writes the exact current index into a
 * bounded, resumable next-baseline file. Only after the target completes does
 * it atomically rename that file here:
 *
 *     <state-dir>/push/<site>/last-sync-local-files.jsonl
 *
 * This class deliberately performs no copy or diff. Either would be another
 * unbounded O(input) pass and would duplicate the driver's durable merge.
 */
class PushJournal {

    /** @var string Copy of the local file index from the last completed push. */
    public string $local_files_baseline_path;

    public function __construct(string $state_dir, string $site_url) {
        $site_dir = rtrim($state_dir, '/') . '/push/' . self::site_key($site_url);
        $this->local_files_baseline_path = $site_dir . '/last-sync-local-files.jsonl';
    }

    /**
     * Directory name for a remote site URL: a readable slug plus a short
     * identity hash. Scheme, credentials, query, and fragment do not name a
     * different site's files; host, port, and path do.
     */
    public static function site_key(string $site_url): string {
        $site_url = trim($site_url);
        $parts = parse_url($site_url);
        if (( !is_array($parts) || empty($parts['host']) ) && strpos($site_url, '//') === false) {
            // A bare example.com/blog parses as a path; retry it as a
            // host-relative URL.
            $parts = parse_url('//' . $site_url);
        }
        if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
            throw new RuntimeException('Cannot derive a push site key, the URL has no host: ' . $site_url);
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? rtrim($parts['path'], '/') : '';
        $normalized = $host . $port . $path;

        $slug = trim( (string) preg_replace('/[^a-z0-9.]+/', '-', strtolower($normalized)), '-.');
        // The hash carries identity, so truncating the human-readable part
        // avoids filesystem name limits without creating slug collisions.
        $slug = substr($slug, 0, 60);
        $hash = substr(sha1($normalized), 0, 8);
        return $slug === '' ? $hash : $slug . '-' . $hash;
    }
}
