<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Filesystem paths are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Maps remote absolute paths into one local filesystem tree.
 *
 * An explicit resolved path mapping wins first. If several mappings match,
 * the longest remote prefix wins. An unmatched path from the original pull
 * scope keeps its remote spelling under the local filesystem root. A followed
 * symlink target outside that scope goes under the configured local followed
 * symlinks root instead.
 *
 * Copied targets and rewritten symlink destinations both use this mapping, so
 * a rewritten link points to the place where its target was copied.
 *
 * Paths remain byte strings in memory. This class does not encode them as JSON
 * or require them to be valid UTF-8.
 */
final class RemoteToLocalPathMapper
{
    /** Local filesystem root beneath which pulled paths are written. */
    private string $filesystem_root;

    /** @var list<string> Remote absolute path roots selected before following symlinks. */
    private array $original_remote_absolute_path_roots;

    /** @var array<string,string> Remote absolute prefix to local absolute prefix. */
    private array $resolved_path_mappings;

    /** Local root for followed targets outside the original pull scope. */
    private ?string $local_followed_symlinks_root;

    /**
     * Stores the resolved path settings used by one files pull.
     *
     * The caller resolves and validates these settings before constructing the
     * mapper. Local prefixes and the followed-symlinks root must be equal to or
     * below the filesystem root.
     *
     * @param string               $filesystem_root                    Local filesystem root.
     * @param list<string>         $original_remote_absolute_path_roots Remote absolute path roots selected before following symlinks.
     * @param array<string,string> $resolved_path_mappings             Remote absolute prefix to local absolute prefix.
     * @param string|null          $local_followed_symlinks_root       Local root for followed targets outside the original scope.
     */
    public function __construct(
        string $filesystem_root,
        array $original_remote_absolute_path_roots,
        array $resolved_path_mappings = [],
        ?string $local_followed_symlinks_root = null
    ) {
        $this->filesystem_root = $filesystem_root;
        $this->original_remote_absolute_path_roots = $original_remote_absolute_path_roots;
        $this->resolved_path_mappings = $resolved_path_mappings;
        $this->local_followed_symlinks_root = $local_followed_symlinks_root;
    }

    /**
     * Maps one remote absolute path to its local absolute path.
     *
     * @param string $remote_absolute_path Remote absolute path.
     * @return string Local absolute path under the filesystem root.
     */
    public function remote_path_to_local_path(string $remote_absolute_path): string
    {
        assert_valid_path($remote_absolute_path, "remote absolute path");
        $local_absolute_path = null;
        $longest_remote_prefix_length = -1;
        foreach ($this->resolved_path_mappings as $remote_prefix => $local_prefix) {
            $remainder = path_remainder_under(
                $remote_absolute_path,
                $remote_prefix
            );
            if (
                $remainder !== null
                && strlen($remote_prefix) > $longest_remote_prefix_length
            ) {
                $local_absolute_path = wp_join_unix_paths(
                    $local_prefix,
                    $remainder
                );
                $longest_remote_prefix_length = strlen($remote_prefix);
            }
        }
        if ($local_absolute_path !== null) {
            return $local_absolute_path;
        }

        if (
            $this->local_followed_symlinks_root !== null
            && !path_is_same_as_or_descendant_of(
                $remote_absolute_path,
                $this->original_remote_absolute_path_roots
            )
        ) {
            return wp_join_unix_paths(
                $this->local_followed_symlinks_root,
                $remote_absolute_path
            );
        }

        return wp_join_unix_paths($this->filesystem_root, $remote_absolute_path);
    }
}
