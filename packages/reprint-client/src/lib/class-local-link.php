<?php

use WordPress\Reprint\Server\Utils;
use function WordPress\Filesystem\wp_join_unix_paths;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Creates, compares, and removes symlinks in the local filesystem.
 *
 * PHP's link functions behave differently on Windows:
 *
 * - symlink() checks whether a relative target is a directory from the
 *   current directory rather than from the link's directory. Windows needs
 *   that answer to choose a file or directory link, so a relative link made
 *   from another directory fails, or gets the wrong type.
 * - readlink() returns the resolved absolute path of a relative link, never
 *   the stored value, and fails when that target is missing.
 * - unlink() cannot remove a link to a directory; rmdir() removes the link
 *   without touching its target.
 *
 * Windows resolves backslashes in a relative link value, but not forward
 * slashes, so callers pass Windows values from Utils::relative_link_target().
 */
final class LocalLink
{
    /**
     * Spells a link value received from the source for this filesystem.
     *
     * A relative value from a Unix source separates components with forward
     * slashes, which Windows does not resolve inside a link.
     *
     * @param string $link_value Link value as the source reported it.
     * @return string The value with backslashes on Windows, unchanged elsewhere.
     */
    public static function native_link_value(string $link_value): string
    {
        if (Utils::native_path_format() !== 'windows' || Utils::is_absolute_path($link_value, 'windows')) {
            return $link_value;
        }
        return str_replace('/', '\\', $link_value);
    }

    /**
     * Creates a symlink at $link_path with $link_value.
     *
     * @param string $link_value Relative value resolved from the link's directory, or an absolute path.
     * @param string $link_path  Path of the new link. Nothing may exist there.
     * @return bool Whether the link was created. PHP has raised a warning when it was not.
     */
    public static function create_symlink(string $link_value, string $link_path): bool
    {
        if (Utils::native_path_format() !== 'windows' || Utils::is_absolute_path($link_value, 'windows')) {
            return symlink($link_value, $link_path);
        }
        $working_directory = getcwd();
        if ($working_directory === false || !chdir(dirname($link_path))) {
            return false;
        }
        try {
            return symlink($link_value, basename($link_path));
        } finally {
            chdir($working_directory);
        }
    }

    /**
     * Whether $link_path is a symlink that already has $link_value.
     *
     * On Windows, PHP cannot read a relative value back, so a link counts as
     * matching when it resolves to the existing path that $link_value names.
     *
     * @param string $link_path  Path that may be a symlink.
     * @param string $link_value Value the link should have.
     */
    public static function has_value(string $link_path, string $link_value): bool
    {
        if (!is_link($link_path)) {
            return false;
        }
        $current_link_value = @readlink($link_path);
        if ($current_link_value === $link_value) {
            return true;
        }
        if (Utils::native_path_format() !== 'windows' || $current_link_value === false) {
            return false;
        }
        $expected_target = Utils::is_absolute_path($link_value, 'windows')
            ? $link_value
            : wp_join_unix_paths(dirname($link_path), $link_value);
        $resolved_expected_target = realpath($expected_target);
        $resolved_link = realpath($link_path);
        return $resolved_expected_target !== false && $resolved_expected_target === $resolved_link;
    }

    /**
     * Removes the symlink at $link_path without changing its target.
     *
     * @param string $link_path Path of a symlink.
     * @return bool Whether nothing remains at $link_path.
     */
    public static function remove_symlink(string $link_path): bool
    {
        if (@unlink($link_path)) {
            return true;
        }
        // A Windows link to a directory is removed like a directory.
        if (Utils::native_path_format() === 'windows' && is_link($link_path) && @rmdir($link_path)) {
            return true;
        }
        clearstatcache(true, $link_path);
        return !is_link($link_path) && !file_exists($link_path);
    }
}
