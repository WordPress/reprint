<?php
/**
 * Builds a push transfer plan from a local tree.
 *
 * The plan is the input StagedPushRunner walks: one entry per regular file,
 * with the artifact id being the fs-root-relative path the file will be
 * applied to on the target. Entries come out in deterministic path order so
 * two runs over the same tree produce the same plan and the runner's
 * done-cache lines up across retries.
 *
 * Symlinks are skipped and reported, never followed: following them would
 * push bytes from outside fs-root under an in-root artifact id, and
 * symlinked directories are how hosting layouts create cycles. Anything
 * unreadable is reported the same way instead of failing the whole plan —
 * the caller decides whether a skip list is acceptable for its transfer.
 *
 * --only prefixes filter the plan to selected subtrees. Directories that
 * can neither contain a match nor be contained by one are pruned without
 * being walked, so restricting a push to wp-content does not pay for
 * scanning an unrelated node_modules forest.
 */
class PushPlanBuilder
{
    /**
     * @param string $fs_root Local tree to plan from.
     * @param array $only_prefixes fs-root-relative path prefixes; empty
     *   means everything.
     * @return array{plan:array,skipped:array} plan entries are
     *   ['artifact_id','source_path','total_bytes','mtime']; skipped entries are
     *   ['path','reason'] with reason symlink|special|unreadable|unreadable_dir.
     */
    public static function build(string $fs_root, array $only_prefixes = []): array
    {
        $root = rtrim($fs_root, "/");
        if ($root === "" || !is_dir($root)) {
            throw new InvalidArgumentException("push plan fs-root is not a directory: {$fs_root}");
        }

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

        $plan = [];
        $skipped = [];
        self::walk($root, "", $only, $plan, $skipped);
        return [
            "plan" => $plan,
            "skipped" => $skipped,
        ];
    }

    /**
     * @param string $dir Absolute directory being walked.
     * @param string $rel_dir fs-root-relative path of $dir ("" for the root).
     */
    private static function walk(string $dir, string $rel_dir, array $only, array &$plan, array &$skipped): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            $skipped[] = ["path" => $rel_dir, "reason" => "unreadable_dir"];
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            $rel = $rel_dir === "" ? $entry : $rel_dir . "/" . $entry;

            if (is_link($path)) {
                $skipped[] = ["path" => $rel, "reason" => "symlink"];
                continue;
            }
            if (is_dir($path)) {
                if (self::prunable($rel, $only)) {
                    continue;
                }
                self::walk($path, $rel, $only, $plan, $skipped);
                continue;
            }
            if (!is_file($path)) {
                $skipped[] = ["path" => $rel, "reason" => "special"];
                continue;
            }
            if ($only !== [] && !self::selected($rel, $only)) {
                continue;
            }
            $size = @filesize($path);
            $mtime = @filemtime($path);
            if ($size === false || $mtime === false) {
                $skipped[] = ["path" => $rel, "reason" => "unreadable"];
                continue;
            }
            $plan[] = [
                "artifact_id" => $rel,
                "source_path" => $path,
                "total_bytes" => $size,
                // Size alone cannot see a same-size edit; the runner keys
                // its done-cache on mtime too.
                "mtime" => (int) $mtime,
            ];
        }
    }

    /** A file is selected when it sits at or under one of the prefixes. */
    private static function selected(string $rel, array $only): bool
    {
        foreach ($only as $prefix) {
            if ($rel === $prefix || strpos($rel, $prefix . "/") === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * A directory is prunable when no prefix lies under it and it lies
     * under no prefix — nothing inside can be selected.
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
