<?php
/**
 * Streams a push "source index" from a local tree — the inverted-pull
 * counterpart of the remote index the exporter streams for a pull.
 *
 * One line per regular file in the shared index format
 * ({path, ctime, size, type}), written unsorted for the caller to sort with
 * sort_index_file(). The caller then diffs it against the shipped index (what
 * the target already holds) to derive the upload list and deletions, exactly
 * the way pull diffs the remote index against its local index — so push does
 * not carry its own plan/done-cache data model.
 *
 * "ctime" carries the file's mtime: on the sender that is the change signal a
 * same-size edit needs, and the field is only ever compared against another
 * source/shipped line written the same way.
 *
 * Symlinks are skipped and reported, never followed: following them would
 * push bytes from outside fs-root under an in-root artifact id, and symlinked
 * directories are how hosting layouts create cycles. Anything unreadable is
 * reported the same way instead of failing the whole walk — the caller
 * decides whether a skip list is acceptable, and (as with pull) a skipped
 * path is "not read this run", never "removed", so it is excluded from
 * deletion derivation.
 *
 * --only prefixes filter the index to selected subtrees. Directories that can
 * neither contain a match nor be contained by one are pruned without being
 * walked, so restricting a push to wp-content does not pay for scanning an
 * unrelated node_modules forest.
 */
class PushPlanBuilder
{
    /**
     * Walk $fs_root and write one shared-format index line per regular file
     * to $index_handle (unsorted; the caller sorts with sort_index_file).
     *
     * @param resource $index_handle
     * @param string $path_prefix Prepended to every emitted path (index and
     *   skipped): "" keeps paths fs-root-relative (document-root mode); an
     *   absolute prefix like realpath(fs-root) makes them absolute
     *   (filesystem mode), so index paths and the skip list share one space.
     * @return array{skipped:array<int,array{path:string,reason:string}>}
     *   skip reasons: symlink|special|unreadable|unreadable_dir.
     */
    public static function build_index(string $fs_root, array $only_prefixes, $index_handle, string $path_prefix = ""): array
    {
        $root = rtrim($fs_root, "/");
        if ($root === "" || !is_dir($root)) {
            throw new InvalidArgumentException("push plan fs-root is not a directory: {$fs_root}");
        }

        $only = self::normalize_only($only_prefixes);

        $skipped = [];
        self::walk($root, "", $only, $index_handle, $skipped, rtrim($path_prefix, "/"));
        return ["skipped" => $skipped];
    }

    /** Prepend the path prefix (filesystem mode) or leave relative. */
    private static function prefixed(string $prefix, string $rel): string
    {
        return $prefix === "" ? $rel : $prefix . "/" . $rel;
    }

    /**
     * Validate and normalize --only prefixes. Hostile forms (absolute, empty,
     * backslash, or a "." / ".." segment) are rejected before anything runs.
     *
     * @return array<int,string>
     */
    public static function normalize_only(array $only_prefixes): array
    {
        $only = [];
        foreach ($only_prefixes as $prefix) {
            if (!is_string($prefix)) {
                throw new InvalidArgumentException("--only prefix must be a string");
            }
            $prefix = trim($prefix, "/");
            if ($prefix === "" || strpos($prefix, "\\") !== false) {
                throw new InvalidArgumentException("Invalid --only prefix: {$prefix}");
            }
            foreach (explode("/", $prefix) as $segment) {
                if ($segment === "" || $segment === "." || $segment === "..") {
                    throw new InvalidArgumentException("Invalid --only prefix: {$prefix}");
                }
            }
            $only[] = $prefix;
        }
        return $only;
    }

    /**
     * @param string $dir Absolute directory being walked.
     * @param string $rel_dir fs-root-relative path of $dir ("" for the root).
     * @param resource $index_handle
     */
    private static function walk(string $dir, string $rel_dir, array $only, $index_handle, array &$skipped, string $path_prefix): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            $skipped[] = ["path" => self::prefixed($path_prefix, $rel_dir), "reason" => "unreadable_dir"];
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            // --only prefixes are always fs-root-relative, so pruning and
            // selection stay in the relative space; only the emitted path
            // carries the prefix.
            $rel = $rel_dir === "" ? $entry : $rel_dir . "/" . $entry;

            if (is_link($path)) {
                $skipped[] = ["path" => self::prefixed($path_prefix, $rel), "reason" => "symlink"];
                continue;
            }
            if (is_dir($path)) {
                if (self::prunable($rel, $only)) {
                    continue;
                }
                self::walk($path, $rel, $only, $index_handle, $skipped, $path_prefix);
                continue;
            }
            if (!is_file($path)) {
                $skipped[] = ["path" => self::prefixed($path_prefix, $rel), "reason" => "special"];
                continue;
            }
            if ($only !== [] && !self::selected($rel, $only)) {
                continue;
            }
            $size = @filesize($path);
            $mtime = @filemtime($path);
            if ($size === false || $mtime === false) {
                $skipped[] = ["path" => self::prefixed($path_prefix, $rel), "reason" => "unreadable"];
                continue;
            }
            // Shared index format: base64 path so any filename survives, and
            // mtime in the "ctime" field (see the class docblock).
            $line = json_encode([
                "path" => base64_encode(self::prefixed($path_prefix, $rel)),
                "ctime" => (int) $mtime,
                "size" => (int) $size,
                "type" => "file",
            ], JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                fwrite($index_handle, $line . "\n");
            }
        }
    }

    /** A path is selected when it sits at or under one of the prefixes. */
    public static function selected(string $rel, array $only): bool
    {
        foreach ($only as $prefix) {
            if ($rel === $prefix || strpos($rel, $prefix . "/") === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * A directory is prunable when no prefix lies under it and it lies under
     * no prefix — nothing inside can be selected.
     */
    private static function prunable(string $rel_dir, array $only): bool
    {
        if ($only === []) {
            return false;
        }
        foreach ($only as $prefix) {
            $under_prefix = $rel_dir === $prefix || strpos($rel_dir, $prefix . "/") === 0;
            $contains_prefix = strpos($prefix, $rel_dir . "/") === 0;
            if ($under_prefix || $contains_prefix) {
                return false;
            }
        }
        return true;
    }
}
