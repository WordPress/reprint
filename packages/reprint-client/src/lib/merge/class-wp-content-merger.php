<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Merge failures carry CLI filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Folds one wp-content directory into another.
 *
 * Entries that only exist in the source move to the destination. Entries that
 * already exist in destination are untouched on both sides. Nothing is deleted.
 *
 * ## The unit boundary
 *
 * Plugins, mu-plugins, and themes (so called units) are shallowly merged to
 * ensure we don't mix versions. So, if they are a directory (and not a file),
 * the merge only moves the directory as a whole.
 *
 *   plugins, mu-plugins, themes -> one level, each child whole
 *   uploads                     -> all the way down, each file its own
 *   anything else               -> whole, because its insides are unknown
 *
 * Per entry at the wp-content level:
 *
 *   - absent from the destination -> move it there
 *   - a container above           -> walk it under that container's rule
 *   - anything else               -> leave it, the destination copy wins
 *
 * ## Component directories
 *
 * WordPress lets plugins, mu-plugins and uploads live outside wp-content, and
 * hosts use that: on WP Cloud the uploads base directory sits on its own
 * volume. `$component_destinations` says where each one lives on the
 * destination side, so `uploads` moves to that directory rather than to
 * `<destination>/uploads`. Their basenames also join the container names, so a
 * site that renamed a component directory still gets the right rule.
 */
class WpContentMerger
{
    /**
     * Suffix for the path a cross-filesystem copy writes before it renames the
     * finished entry into place. Derived from the destination rather than
     * random so a later run clears what an interrupted one abandoned.
     */
    private const STAGING_SUFFIX = ".reprint-merge-incomplete";

    /** Rule for an entry whose own children are whole plugins or themes. */
    private const UNIT_CONTAINER = "unit";

    /** Rule for an entry whose contents merge file by file. */
    private const FILE_CONTAINER = "file";

    /** @var string wp-content directory whose entries move away. */
    private string $source_wp_content;

    /** @var string wp-content directory the entries move into. */
    private string $destination_wp_content;

    /**
     * @var array<string,string> Source entry name mapped to the destination
     *                           path that entry moves to, for the components
     *                           which live outside wp-content.
     */
    private array $routed_destinations = [];

    /**
     * How far the merge walks into an entry. An entry named by no rule moves
     * whole, because nothing says what is inside it.
     *
     * The constructor gives each basename preflight reported the rule of the
     * component it belongs to, so a site that renamed a component directory
     * still gets that component's rule.
     *
     * @var array<string,string> Entry name mapped to UNIT_CONTAINER or
     *                           FILE_CONTAINER.
     */
    private array $container_rules = [
        "plugins" => self::UNIT_CONTAINER,
        "mu-plugins" => self::UNIT_CONTAINER,
        "themes" => self::UNIT_CONTAINER,
        "uploads" => self::FILE_CONTAINER,
    ];

    /**
     * @var callable Receives one audit line per moved entry.
     * @phpstan-var callable(string): void
     */
    private $record_move;

    /** @var int Entries moved by the current merge() call. */
    private int $moved = 0;

    /**
     * @param string   $source_wp_content      wp-content directory whose entries move away.
     * @param string   $destination_wp_content wp-content directory the entries move into.
     * @param array    $component_destinations {
     *     Destination directory for each component which may live outside
     *     wp-content. An absent or null key leaves that component at its
     *     conventional name under $destination_wp_content.
     *
     *     @type string|null $plugins    Destination plugins directory.
     *     @type string|null $mu-plugins Destination mu-plugins directory.
     *     @type string|null $uploads    Destination uploads base directory.
     * }
     * @param callable $record_move            Called with one audit line per moved entry.
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
            // Both names route to the same place, and the second inherits the
            // first's rule: the wp-content being merged in may use the name
            // WordPress conventionally uses, or the one the destination site
            // gave that component.
            $this->routed_destinations[$conventional_name] = $destination;
            $this->routed_destinations[basename($destination)] = $destination;
            $this->container_rules[basename($destination)] =
                $this->container_rules[$conventional_name];
        }
    }

    /**
     * Move every entry the destination lacks, and report how many moved.
     *
     * A side which is not a real directory has nothing to compare, so the
     * merge is a no-op. That is what makes a second run harmless once
     * flat-docroot has replaced the source wp-content with a symlink.
     */
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
                // A routed component can sit outside the destination
                // wp-content, and the file pull creates the directories
                // leading to it only when the source site had content there.
                // rename() into a missing parent fails, which would send a
                // whole uploads tree down the copy fallback for want of one
                // mkdir.
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

    /**
     * Move whole plugins or themes the destination lacks.
     *
     * Each child is one unit. A name the destination also has stays the
     * destination's, down to the last file, so two versions of the same plugin
     * never merge.
     */
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

    /**
     * Move the files the destination lacks, to the leaf.
     *
     * For uploads, where each file stands alone: a photo the destination does
     * not have is its own thing, not part of some larger version.
     */
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
     * Move one entry to its place on the destination side.
     *
     * A symlink is recreated rather than moved, and a relative value is kept
     * or recomputed depending on where it points. One that resolves inside the
     * tree being merged keeps its value, because the whole tree moves
     * together. One that resolves outside it is read against a parent which
     * has changed depth, so it is recomputed to reach the same target. An
     * absolute value resolves the same from either parent and moves untouched.
     *
     * rename() reports EXDEV when the two sides are on different filesystems,
     * which nothing stops the caller from choosing, so a copy is the fallback
     * rather than the error.
     *
     * Only the moved entry's own value is rewritten. A relative symlink deeper
     * in a moved directory keeps its value, which still resolves when it
     * points within that directory and breaks when it points out of it. No
     * move can preserve the second kind.
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
                // A target inside the tree being merged keeps its value: it
                // either moves in this same run, or the destination already
                // holds its own copy at that name, and the value finds
                // whichever is there. A target outside the tree stays where it
                // is while the link's parent changes depth, so the value has to
                // be recomputed to still reach it.
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
            // Copy beside the destination and rename it into place, never into
            // the destination itself. A copy that dies partway — a full disk,
            // an unreadable file, a signal during a large one — would otherwise
            // leave a partial entry that the next run reads as the destination
            // copy and skips. Both paths sit in the same directory, so the
            // rename is atomic.
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

    /**
     * Copy a file, symlink, or directory tree to a path that does not exist yet.
     *
     * This is the cross-filesystem half of a move, so it reproduces a symlink
     * as a symlink instead of following it into its target. Values are kept
     * verbatim: an entry and everything below it move together, so a relative
     * value that resolves within the tree still resolves after the copy.
     *
     * copy() creates the destination under the umask and mkdir() takes the
     * mode it is given, so both are followed by a chmod to the source's own
     * permissions. Without it an executable script or a deliberately 0700
     * directory would come out different on this path and unchanged on the
     * rename() path.
     */
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

        // A failed copy or mkdir here is reported as an exception naming both
        // paths, so the raw PHP warning would only duplicate it.
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

    /** Give $to the permission bits $from carries. */
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

    /**
     * Create the directories leading to $path.
     *
     * mkdir() is given the conventional directory mode rather than one copied
     * from the source: these directories exist on the destination side only,
     * and no source directory corresponds to them.
     */
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

    /**
     * Delete a file, symlink, or directory tree without following symlinks.
     *
     * Returns false rather than throwing so the caller can name the path in
     * an error which says what it had already done.
     */
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

    /**
     * Compute a relative path from $from to $to.
     *
     * Both paths must be absolute. Returns a relative path such that a symlink
     * at $from/$name pointing to the result resolves to $to.
     *
     * Example: compute_relative_path('/a/b/c', '/a/d/e') => '../../d/e'
     */
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
