<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Merge failures carry CLI filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Moves local-only wp-content entries into a pulled tree.
 *
 * Existing destination entries win. To avoid mixing plugin or theme versions,
 * plugins, mu-plugins, and themes move by child; uploads move by leaf; other
 * entries move whole.
 *
 * Component destinations may be outside wp-content.
 */
class WpContentMerger
{
    /** Temporary sibling used by the cross-filesystem copy fallback. */
    private const STAGING_SUFFIX = ".reprint-merge-incomplete";

    /** Rule for an entry whose own children are whole plugins or themes. */
    private const UNIT_CONTAINER = "unit";

    /** Rule for an entry whose contents merge file by file. */
    private const FILE_CONTAINER = "file";

    private string $source_wp_content;

    private string $destination_wp_content;

    /**
     * Routes components whose preflight destination is outside wp-content.
     *
     * @var array<string,string>
     */
    private array $routed_destinations = [];

    /** @var array<string,string> Traversal rule by source entry name. */
    private array $container_rules = [
        "plugins" => self::UNIT_CONTAINER,
        "mu-plugins" => self::UNIT_CONTAINER,
        "themes" => self::UNIT_CONTAINER,
        "uploads" => self::FILE_CONTAINER,
    ];

    /** @var callable(string):void */
    private $record_move;

    private int $moved = 0;

    /**
     * @param string   $source_wp_content      Source wp-content directory.
     * @param string   $destination_wp_content Destination wp-content directory.
     * @param array    $component_destinations {
     *     @type string|null $plugins    Destination plugins directory.
     *     @type string|null $mu-plugins Destination mu-plugins directory.
     *     @type string|null $uploads    Destination uploads base directory.
     * }
     * @param callable $record_move            Receives moved entries.
     *
     * @phpstan-param array<string,string|null> $component_destinations
     * @phpstan-param callable(string): void   $record_move
     */
    public function __construct(
        string $source_wp_content,
        string $destination_wp_content,
        array $component_destinations,
        callable $record_move
    ) {
        $this->source_wp_content = $source_wp_content;
        $this->destination_wp_content = $destination_wp_content;
        $this->record_move = $record_move;

        foreach ($component_destinations as $conventional_name => $destination) {
            if (!is_string($destination) || $destination === "") {
                continue;
            }
            // Accept both the conventional and destination component names.
            $this->routed_destinations[$conventional_name] = $destination;
            $this->routed_destinations[basename($destination)] = $destination;
            $this->container_rules[basename($destination)] =
                $this->container_rules[$conventional_name];
        }
    }

    public function merge(): int
    {
        $this->moved = 0;
        if (
            !$this->is_real_directory($this->source_wp_content)
            || !$this->is_real_directory($this->destination_wp_content)
        ) {
            return 0;
        }

        foreach (@scandir($this->source_wp_content) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $source_entry = wp_join_unix_paths($this->source_wp_content, $entry);
            $destination_entry = $this->routed_destinations[$entry]
                ?? wp_join_unix_paths($this->destination_wp_content, $entry);

            if (!file_exists($destination_entry) && !is_link($destination_entry)) {
                // A detached component may not yet have a destination parent.
                $this->create_parent_directory($destination_entry);
                $this->move_entry($source_entry, $destination_entry);
                continue;
            }
            $rule = $this->container_rules[$entry] ?? null;
            if ($rule === self::UNIT_CONTAINER) {
                $this->merge_unit_container($source_entry, $destination_entry);
                continue;
            }
            if ($rule === self::FILE_CONTAINER) {
                $this->merge_file_tree($source_entry, $destination_entry);
            }
        }

        return $this->moved;
    }

    /** Move plugin or theme children without mixing their contents. */
    private function merge_unit_container(string $source, string $destination): void
    {
        if (!$this->is_real_directory($source) || !$this->is_real_directory($destination)) {
            return;
        }

        foreach (@scandir($source) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $destination_entry = wp_join_unix_paths($destination, $entry);
            if (file_exists($destination_entry) || is_link($destination_entry)) {
                continue;
            }
            $this->move_entry(wp_join_unix_paths($source, $entry), $destination_entry);
        }
    }

    /** Merge a file container, such as uploads, to the leaf. */
    private function merge_file_tree(string $source, string $destination): void
    {
        if (!$this->is_real_directory($source) || !$this->is_real_directory($destination)) {
            return;
        }

        foreach (@scandir($source) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $source_entry = wp_join_unix_paths($source, $entry);
            $destination_entry = wp_join_unix_paths($destination, $entry);

            if (!file_exists($destination_entry) && !is_link($destination_entry)) {
                $this->move_entry($source_entry, $destination_entry);
                continue;
            }
            $this->merge_file_tree($source_entry, $destination_entry);
        }
    }

    /**
     * Move an entry, falling back to a staged copy across filesystems.
     *
     * A moved link keeps its relative value when the target is inside the
     * source tree, which moves with it; an external target is rebased.
     * Absolute values, and links nested inside a moved entry, travel
     * unchanged.
     */
    private function move_entry(string $source_entry, string $destination_entry): void
    {
        if (is_link($source_entry)) {
            $link_value = readlink($source_entry);
            if ($link_value === false) {
                throw new RuntimeException(
                    "Could not read the symlink value at {$source_entry}.",
                );
            }
            if (strpos($link_value, "/") !== 0) {
                $resolved_target = normalize_path(
                    wp_join_unix_paths(dirname($source_entry), $link_value)
                );
                // The source tree moves; an external target does not.
                if (
                    !path_is_same_as_or_descendant_of(
                        $resolved_target,
                        $this->source_wp_content
                    )
                ) {
                    $link_value = self::compute_relative_path(
                        realpath_with_missing_tail(dirname($destination_entry)),
                        $resolved_target
                    );
                }
            }
            if (!symlink($link_value, $destination_entry)) {
                throw new RuntimeException(
                    "Failed to create symlink: {$destination_entry} -> {$link_value}",
                );
            }
            if (!unlink($source_entry)) {
                throw new RuntimeException(
                    "Created {$destination_entry} but could not remove the original " .
                        "symlink at {$source_entry}.",
                );
            }
        } elseif (!@rename($source_entry, $destination_entry)) {
            // A sibling rename prevents a partial copy from occupying the destination.
            $staging_path = $destination_entry . self::STAGING_SUFFIX;
            $this->remove_path_without_following_symlinks($staging_path);
            try {
                $this->copy_path($source_entry, $staging_path);
                if (!@rename($staging_path, $destination_entry)) {
                    throw new RuntimeException(
                        "Copied {$source_entry} to {$staging_path} but could not move it " .
                            "to {$destination_entry}.",
                    );
                }
            } catch (Throwable $copy_failure) {
                $this->remove_path_without_following_symlinks($staging_path);
                throw $copy_failure;
            }
            if (!$this->remove_path_without_following_symlinks($source_entry)) {
                throw new RuntimeException(
                    "Copied {$source_entry} to {$destination_entry} but could not remove " .
                        "the original at {$source_entry}.",
                );
            }
        }

        ++$this->moved;
        call_user_func(
            $this->record_move,
            "Moved: {$source_entry} -> {$destination_entry}"
        );
    }

    /** Copy an entry without following symlinks and preserve its permissions. */
    private function copy_path(string $from, string $to): void
    {
        if (is_link($from)) {
            $link_value = readlink($from);
            if ($link_value === false) {
                throw new RuntimeException(
                    "Could not read the symlink value at {$from}.",
                );
            }
            if (!symlink($link_value, $to)) {
                throw new RuntimeException(
                    "Failed to create symlink: {$to} -> {$link_value}",
                );
            }
            return;
        }

        if (!is_dir($from)) {
            if (!@copy($from, $to)) {
                throw new RuntimeException("Failed to copy {$from} to {$to}.");
            }
            $this->copy_permissions($from, $to);
            return;
        }

        if (!@mkdir($to, 0755, true)) {
            throw new RuntimeException("Failed to create directory: {$to}");
        }
        $this->copy_permissions($from, $to);
        foreach (@scandir($from) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $this->copy_path(
                wp_join_unix_paths($from, $entry),
                wp_join_unix_paths($to, $entry)
            );
        }
    }

    /** Copy permission bits. */
    private function copy_permissions(string $from, string $to): void
    {
        $permissions = @fileperms($from);
        if ($permissions === false) {
            throw new RuntimeException("Could not read the permissions of {$from}.");
        }
        if (!@chmod($to, $permissions & 0777)) {
            throw new RuntimeException("Failed to set the permissions of {$to}.");
        }
    }

    /** Create a destination parent directory. */
    private function create_parent_directory(string $path): void
    {
        $parent = dirname($path);
        if (is_dir($parent)) {
            return;
        }
        if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException("Failed to create directory: {$parent}");
        }
    }

    /** Whether $path is a directory rather than a symlink to one. */
    private function is_real_directory(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }

    /** Remove a path without following symlinks. */
    private function remove_path_without_following_symlinks(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }

        if (is_link($path) || !is_dir($path)) {
            return true === @unlink($path);
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            if (
                !$this->remove_path_without_following_symlinks(
                    wp_join_unix_paths($path, $entry)
                )
            ) {
                return false;
            }
        }
        return true === @rmdir($path);
    }

    /** Compute a relative path between absolute paths. */
    private static function compute_relative_path(string $from, string $to): string
    {
        $from_parts = explode("/", trim($from, "/"));
        $to_parts = explode("/", trim($to, "/"));

        $common = 0;
        $max = min(count($from_parts), count($to_parts));
        while ($common < $max && $from_parts[$common] === $to_parts[$common]) {
            ++$common;
        }

        $up = count($from_parts) - $common;
        $down = array_slice($to_parts, $common);

        $parts = array_merge(array_fill(0, $up, ".."), $down);
        return implode("/", $parts) ?: ".";
    }
}

// phpcs:enable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
