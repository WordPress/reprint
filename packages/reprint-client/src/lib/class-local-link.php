<?php

use WordPress\Reprint\Server\Utils;
use function WordPress\Filesystem\wp_join_unix_paths;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Chooses, compares, and removes symlink values in the local filesystem.
 *
 * Links made on Unix are relative, so a pulled tree still works after it
 * moves. Links made on Windows are absolute, because Windows and PHP there
 * handle relative values differently:
 *
 * - A managed plugin's relative link, reached through flat-docroot's
 *   relative wp-content link, resolved outside the pulled tree in the
 *   Linux-to-Windows end-to-end test. Absolute values resolve there.
 * - Windows does not treat forward slashes in a value as separators.
 * - symlink() looks at a relative target from the current directory to
 *   decide between a file and a directory link.
 * - readlink() returns the resolved target of a relative link rather than
 *   its value. It returns an absolute value as stored.
 *
 * symlink() on Windows also needs the target to exist and an account that
 * may create symlinks, and unlink() cannot remove a link to a directory.
 */
final class LocalLink
{
    /**
     * Returns the value for a link in $link_directory that reaches $target_path.
     *
     * @param string $link_directory Absolute directory that will hold the link.
     * @param string $target_path    Absolute path the link must reach.
     * @return string A relative value on Unix; an absolute path with backslashes on Windows.
     */
    public static function link_value(string $link_directory, string $target_path): string
    {
        if (Utils::native_path_format() === 'windows') {
            return str_replace('/', '\\', Utils::normalize_path($target_path, 'windows'));
        }
        return Utils::relative_link_target($link_directory, $target_path, 'unix');
    }

    /**
     * Spells a relative link value received from the source for this filesystem.
     *
     * @param string $source_link_value Link value as the source reported it.
     * @param string $link_path         Local path of the link.
     * @return string The value unchanged on Unix or when it is not relative;
     *                otherwise the absolute Windows path it names.
     */
    public static function native_link_value(string $source_link_value, string $link_path): string
    {
        if (
            Utils::native_path_format() !== 'windows'
            || Utils::is_absolute_path($source_link_value, 'windows')
            || Utils::str_starts_with($source_link_value, '/')
        ) {
            return $source_link_value;
        }
        return self::link_value(dirname($link_path), wp_join_unix_paths(dirname($link_path), $source_link_value));
    }

    /**
     * Creates a symlink at $link_path with $link_value.
     *
     * Windows PHP resolves a relative value from the current directory to
     * choose between a file and a directory link, so a relative value is
     * created from the link's own directory. Failures return false without
     * a PHP warning; callers report them.
     *
     * @param string $link_value Value to store in the link.
     * @param string $link_path  Path of the new link.
     */
    public static function create_symlink(string $link_value, string $link_path): bool
    {
        if (Utils::native_path_format() !== 'windows') {
            return @symlink($link_value, $link_path);
        }
        if (Utils::is_absolute_path($link_value, 'windows')) {
            $created = @symlink($link_value, $link_path);
        } else {
            $working_directory = getcwd();
            if ($working_directory === false || !@chdir(dirname($link_path))) {
                return false;
            }
            try {
                $created = @symlink($link_value, basename($link_path));
            } finally {
                chdir($working_directory);
            }
        }
        return $created || self::create_symlink_substitute($link_value, $link_path);
    }

    /**
     * Stands in for a symlink that this Windows account may not create.
     *
     * Creating a Windows symlink needs a privilege that standard accounts,
     * and administrators without elevation, do not hold. Any account may
     * create a junction to a directory. A file is copied, so it shows the
     * target's contents as of this call. A hard link would stay current, but
     * PHP sees it as an ordinary file, so a later copy() or download over it
     * would also rewrite the target.
     *
     * @param string $link_value Value the symlink would have had.
     * @param string $link_path  Path of the new entry.
     */
    private static function create_symlink_substitute(string $link_value, string $link_path): bool
    {
        $target_path = str_replace('/', '\\', self::lexical_target($link_value, $link_path));
        if (self::names_directory($target_path)) {
            // PHP has no junction call; mklink is built into cmd and takes no privilege for /J.
            $process = @proc_open(
                'cmd /d /c mklink /J "' . str_replace('/', '\\', $link_path) . '" "' . $target_path . '"',
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                return false;
            }
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            clearstatcache(true, $link_path);
            return proc_close($process) === 0 && self::is_link($link_path);
        }
        if (is_file($target_path)) {
            return @copy($target_path, $link_path);
        }
        return false;
    }

    /**
     * Whether $path is a symlink, or on Windows a junction.
     *
     * Windows PHP reports a junction as neither a link nor a directory, so
     * is_link() alone would let a removal treat it as a file and a traversal
     * miss it.
     *
     * @param string $path Path to inspect without following it.
     */
    public static function is_link(string $path): bool
    {
        if (Utils::native_path_format() !== 'windows') {
            return is_link($path);
        }
        return Utils::source_is_link($path);
    }

    /**
     * Whether symlink() accepts a target that does not exist yet.
     *
     * Windows needs to know whether the target is a directory, and PHP finds
     * out by looking at it, so there a link can only follow its target.
     */
    public static function can_create_symlink_before_target(): bool
    {
        return Utils::native_path_format() !== 'windows';
    }

    /**
     * Whether the path that $link_value names from $link_path's directory exists.
     *
     * @param string $link_value Relative value resolved from the link's directory, or an absolute path.
     * @param string $link_path  Path of the link, which need not exist.
     */
    public static function target_exists(string $link_value, string $link_path): bool
    {
        return file_exists(self::lexical_target($link_value, $link_path));
    }

    /**
     * Whether $link_path is a symlink that already has $link_value.
     *
     * Windows PHP reads a relative link back as its resolved target, so there
     * a value matches when the link and the value name the same directory
     * entry. A Windows file that stands in for a file symlink matches when it
     * has the target's contents.
     *
     * @param string $link_path  Path that may be a symlink.
     * @param string $link_value Value the link should have.
     */
    public static function has_value(string $link_path, string $link_value): bool
    {
        if (!self::is_link($link_path)) {
            // A copy made in place of a file symlink has its target's contents.
            $target_path = self::lexical_target($link_value, $link_path);
            return Utils::native_path_format() === 'windows'
                && is_file($link_path)
                && is_file($target_path)
                && filesize($link_path) === filesize($target_path)
                && sha1_file($link_path) === sha1_file($target_path);
        }
        $current_link_value = @readlink($link_path);
        if ($current_link_value === $link_value) {
            return true;
        }
        if (Utils::native_path_format() !== 'windows' || $current_link_value === false) {
            return false;
        }
        // One directory can be spelled with its short 8.3 name or its long name.
        return Utils::realpath_with_missing_tail($current_link_value)
            === Utils::realpath_with_missing_tail(self::lexical_target($link_value, $link_path));
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
        if (Utils::native_path_format() === 'windows' && self::is_link($link_path) && @rmdir($link_path)) {
            return true;
        }
        clearstatcache(true, $link_path);
        return !self::is_link($link_path) && !file_exists($link_path);
    }

    /**
     * Removes $path and, for a directory, everything in it, without following links.
     *
     * A link is removed as a link, so its target is left alone.
     *
     * @param string $path Path to remove; nothing happens when it does not exist.
     * @throws RuntimeException When an entry cannot be removed, naming that
     *                          entry and PHP's reason.
     */
    public static function remove_without_following_links(string $path): void
    {
        if (self::is_link($path)) {
            if (!self::remove_symlink($path)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI path error, not HTML.
                throw new RuntimeException("Could not remove the symlink at {$path}: " . self::last_php_error());
            }
            return;
        }
        if (is_dir($path)) {
            $entries = @scandir($path);
            if ($entries === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI path error, not HTML.
                throw new RuntimeException("Could not list the directory {$path} for removal: " . self::last_php_error());
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove_without_following_links(wp_join_unix_paths($path, $entry));
                }
            }
            if (!@rmdir($path)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI path error, not HTML.
                throw new RuntimeException("Could not remove the directory {$path}: " . self::last_php_error());
            }
            return;
        }
        if (file_exists($path) && !@unlink($path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI path error, not HTML.
            throw new RuntimeException("Could not remove the file {$path}: " . self::last_php_error());
        }
    }

    /**
     * Returns the Windows value a link needs after it moves from $source_link_path
     * to $destination_link_path.
     *
     * $read_link_value is what readlink() returned: the resolved absolute
     * target of a relative link, or the stored value of an absolute one.
     * A target inside $moving_tree moves with it, so the new value keeps the
     * link's position relative to that target. Any other target stays where
     * it is.
     *
     * @param string $source_link_path      Link path before the move.
     * @param string $destination_link_path Link path after the move.
     * @param string $read_link_value       readlink() of the link before the move.
     * @param string $moving_tree           Directory whose contents move together.
     */
    public static function windows_moved_link_value(
        string $source_link_path,
        string $destination_link_path,
        string $read_link_value,
        string $moving_tree
    ): string {
        $resolved_target = Utils::realpath_with_missing_tail(
            Utils::is_absolute_path($read_link_value, 'windows')
                ? $read_link_value
                : wp_join_unix_paths(dirname($source_link_path), $read_link_value)
        );
        if (Utils::path_is_same_as_or_descendant_of($resolved_target, Utils::realpath_with_missing_tail($moving_tree))) {
            $resolved_target = Utils::normalize_path(
                wp_join_unix_paths(
                    Utils::realpath_with_missing_tail(dirname($destination_link_path)),
                    Utils::relative_link_target(Utils::realpath_with_missing_tail(dirname($source_link_path)), $resolved_target, 'windows')
                ),
                'windows'
            );
        }
        return self::link_value(dirname($destination_link_path), $resolved_target);
    }

    /** Returns PHP's message for the last suppressed failure. */
    private static function last_php_error(): string
    {
        return error_get_last()['message'] ?? 'PHP reported no reason';
    }

    /** Whether $path is a directory, including one reached through a junction. */
    private static function names_directory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        // is_dir() is false for a junction even though its directory can be read.
        $directory_handle = @opendir($path);
        if ($directory_handle === false) {
            return false;
        }
        closedir($directory_handle);
        return true;
    }

    /** Returns the path $link_value names from the directory holding $link_path. */
    private static function lexical_target(string $link_value, string $link_path): string
    {
        $path_format = Utils::native_path_format();
        if (Utils::is_absolute_path($link_value, $path_format)) {
            return $link_value;
        }
        return Utils::normalize_path(wp_join_unix_paths(dirname($link_path), $link_value), $path_format);
    }
}
