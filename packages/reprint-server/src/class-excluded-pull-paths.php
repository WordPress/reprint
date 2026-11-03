<?php

namespace WordPress\Reprint\Server;

use InvalidArgumentException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol errors, not HTML.

final class ExcludedPullPaths implements PullFileSelection {
    /** @var string[] */
    private $excluded_paths = [];

    /** @var string */
    private $identity;

    /** @param string[] $remote_absolute_paths Host-configured files or directories. */
    public function __construct(array $remote_absolute_paths)
    {
        $path_format = Utils::native_path_format();
        foreach ($remote_absolute_paths as $path) {
            if (!is_string($path) || strpos($path, "\0") !== false || !Utils::is_absolute_path($path, $path_format)) {
                throw new InvalidArgumentException('pull_excluded_paths requires absolute paths without NUL bytes.');
            }
            $this->excluded_paths[] = Utils::normalize_path($path, $path_format);
            clearstatcache(true, $path);
            $resolved = @Utils::source_realpath($path);
            if ($resolved !== false) {
                $this->excluded_paths[] = $resolved;
            }
        }
        $this->excluded_paths = array_values(array_unique($this->excluded_paths));
        sort($this->excluded_paths, SORT_STRING);
        $this->identity = hash('sha256', serialize(['pull-exclusions-v1', $this->excluded_paths]));
    }

    public function get_identity(): string
    {
        return $this->identity;
    }

    public function includes_path(string $remote_absolute_path): bool
    {
        $path_format = Utils::native_path_format();
        if (strpos($remote_absolute_path, "\0") !== false || !Utils::is_absolute_path($remote_absolute_path, $path_format)) {
            return false;
        }

        // Match the requested name and the resolved file. A parent directory
        // can be a symlink even when the final path is a regular file.
        $normalized = Utils::normalize_path($remote_absolute_path, $path_format);
        if (Utils::path_is_same_as_or_descendant_of($normalized, $this->excluded_paths)) {
            return false;
        }
        clearstatcache(true, $remote_absolute_path);
        $resolved = @Utils::source_realpath($remote_absolute_path);
        return $resolved !== false && !Utils::path_is_same_as_or_descendant_of($resolved, $this->excluded_paths);
    }

    public function assert_path_allowed(string $remote_absolute_path): void
    {
        if (!$this->includes_path($remote_absolute_path)) {
            throw new InvalidArgumentException('Path is excluded from pull or could not be resolved: ' . $remote_absolute_path);
        }
    }
}
