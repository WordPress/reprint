<?php

namespace Reprint\Importer\FileSync;

use InvalidArgumentException;
use RuntimeException;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_within_root;

/**
 * Pure file-placement rules for files-pull.
 *
 * This class decides where remote source paths land locally and which source
 * paths should be traversed. It deliberately accepts plain arrays and scalar
 * values so CLI parsing, persisted state, index files, and audit output remain
 * outside the business rules.
 */
final class FilePlacementRules
{
    /**
     * For absolute targets, the target itself must be under $root.
     * For relative targets, resolve against the symlink's parent directory.
     */
    public static function assertSymlinkTargetWithinRoot(
        string $symlink_parent_dir,
        string $target,
        string $root
    ): void {
        $resolved = str_starts_with($target, "/")
            ? normalize_path($target)
            : normalize_path($symlink_parent_dir . "/" . $target);

        if (!path_is_within_root($resolved, $root)) {
            throw new RuntimeException(
                "Security: symlink target escapes filesystem root: {$target} " .
                "(resolves to {$resolved}, root is {$root})"
            );
        }
    }

    /**
     * Resolve a symlink target as a source-site absolute path.
     */
    public static function resolveSymlinkTargetSourcePath(
        string $symlink_source_path,
        string $target
    ): string {
        return str_starts_with($target, "/")
            ? normalize_path($target)
            : normalize_path(dirname($symlink_source_path) . "/" . $target);
    }

    /**
     * Repoint a followed symlink target to the local mirror placement.
     *
     * The caller supplies whether the resolved target exists in the remote
     * index. That keeps index scanning out of the placement rules.
     *
     * @param array<string,string> $remap_rules Source path => target path.
     * @param array<int,string> $original_export_directories Directories selected
     *        before symlink-follow expansion.
     */
    public static function mapSymlinkTargetForLocalMirror(
        string $symlink_source_path,
        string $local_symlink_path,
        string $target,
        bool $follow_symlinks,
        bool $target_is_indexed,
        string $filesystem_root,
        array $remap_rules,
        ?string $symlink_bundle_directory,
        array $original_export_directories
    ): string {
        if (!$follow_symlinks || !$target_is_indexed) {
            return $target;
        }

        $source_target = self::resolveSymlinkTargetSourcePath($symlink_source_path, $target);
        $mapped_absolute = self::remotePathToLocalPathWithinImportRoot(
            $source_target,
            $filesystem_root,
            $remap_rules,
            $symlink_bundle_directory,
            $original_export_directories,
        );

        return self::relativePath(dirname($local_symlink_path), $mapped_absolute);
    }

    /**
     * Stable fingerprint for resolved remap rules. Rule order does not matter.
     *
     * @param array<string,string> $rules
     */
    public static function remapFingerprint(array $rules): string
    {
        ksort($rules, SORT_STRING);
        return hash("sha256", json_encode($rules, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Decide whether the current remap fingerprint can reuse the files index.
     *
     * Returns true when the caller should persist $fingerprint as the initial
     * tracked value.
     */
    public static function assertFilesRemapConsistent(
        string $fingerprint,
        ?string $previous_fingerprint,
        bool $has_existing_index,
        bool $has_remap_rules
    ): bool {
        if ($previous_fingerprint === null && $has_existing_index && $has_remap_rules) {
            throw new RuntimeException(
                "Cannot use --remap with an existing files index that was created before remap tracking. " .
                    "Use a new --state-dir or clear the existing files index first.",
            );
        }

        if ($previous_fingerprint !== null && $previous_fingerprint !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change --remap rules while reusing the same files index. " .
                    "Use the original --remap rules, or use a new --state-dir for a fresh files-pull.",
            );
        }

        return $previous_fingerprint === null;
    }

    /**
     * Stable fingerprint for resolved --only file path prefixes.
     *
     * @param array<int,string> $prefixes
     */
    public static function pullOnlyFingerprint(array $prefixes): string
    {
        return hash("sha256", json_encode($prefixes, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Decide whether an in-progress files-pull can resume with these prefixes.
     *
     * Returns true when the caller should persist $fingerprint as the initial
     * tracked value.
     */
    public static function assertPullOnlyUnchangedWhileResuming(
        bool $has_progress,
        string $fingerprint,
        ?string $previous_fingerprint
    ): bool {
        if (!$has_progress) {
            return false;
        }

        if ($previous_fingerprint !== null && $previous_fingerprint !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change --only while resuming files-pull. " .
                    "Use the original --only values, or use --abort to start a new files-pull.",
            );
        }

        return $previous_fingerprint === null;
    }

    /**
     * Fingerprint of the effective bundle placement root.
     */
    public static function symlinkBundleDirectoryFingerprint(
        ?string $bundle_directory,
        string $filesystem_root
    ): string {
        $effective = $bundle_directory ?? rtrim($filesystem_root, "/");
        return hash("sha256", $effective);
    }

    /**
     * Decide whether files-pull can continue with this symlink bundle placement.
     *
     * Returns true when the caller should persist $fingerprint as the initial
     * tracked value.
     */
    public static function assertSymlinkBundleDirectoryUnchanged(
        string $fingerprint,
        ?string $previous_fingerprint
    ): bool {
        if ($previous_fingerprint !== null && $previous_fingerprint !== $fingerprint) {
            throw new RuntimeException(
                "Cannot change the --follow-symlinks bundle directory for an existing files-pull. " .
                    "Use the original value, or use --abort to start a new files-pull.",
            );
        }

        return $previous_fingerprint === null;
    }

    /**
     * Resolve the --follow-symlinks=<dir> bundle destination.
     */
    public static function resolveSymlinkBundleDirectory(
        string $raw,
        string $filesystem_root
    ): string {
        $fs_root = rtrim($filesystem_root, "/");
        $directory = self::resolveTokenPath($raw, ["fs-root" => $fs_root]);

        if (!path_is_within_root($directory, $fs_root)) {
            throw new InvalidArgumentException(
                "--follow-symlinks bundle directory \"{$directory}\" resolves outside --fs-root ({$fs_root}); " .
                    "it must stay within the destination root",
            );
        }

        return $directory;
    }

    /**
     * Build resolved remap rules from raw SOURCE TARGET pairs and preflight data.
     *
     * @param array<int,array{0:string,1:string}> $remap_raw
     * @param array<string,mixed> $preflight_data
     * @return array<string,string>
     */
    public static function resolveRemap(
        array $remap_raw,
        array $preflight_data,
        string $filesystem_root
    ): array {
        $fs_root = rtrim($filesystem_root, "/");
        $source_tokens = self::wpSourcePathTokens($preflight_data);
        $target_tokens = ["fs-root" => $fs_root];

        $rules = [];
        $wp_content_target = null;
        foreach ($remap_raw as [$source_raw, $target_raw]) {
            $source = self::resolveTokenPath($source_raw, $source_tokens);
            $target = self::resolveTokenPath($target_raw, $target_tokens);

            if (!path_is_within_root($target, $fs_root)) {
                throw new InvalidArgumentException(
                    "--remap target \"{$target}\" resolves outside --fs-root ({$fs_root}); " .
                        "targets must stay within the destination root",
                );
            }

            $rules[$source] = $target;
            if ($source === $source_tokens["wp-content"]) {
                $wp_content_target = $target;
            }
        }

        if ($wp_content_target !== null) {
            foreach (self::contentDirectoriesOutsideWpContent($source_tokens) as $name => $source) {
                if (!isset($rules[$source])) {
                    $rules[$source] = wp_join_unix_paths($wp_content_target, $name);
                }
            }
        }

        return $rules;
    }

    /**
     * Find plugins, mu-plugins, and uploads directories outside WP_CONTENT_DIR.
     *
     * @param array<string,string|null> $source_tokens
     * @return array<string,string> Directory name => source path.
     */
    public static function contentDirectoriesOutsideWpContent(array $source_tokens): array
    {
        $content = $source_tokens["wp-content"] ?? null;
        if ($content === null) {
            return [];
        }

        $directories = [];
        foreach (["wp-plugins" => "plugins", "wp-mu-plugins" => "mu-plugins", "wp-uploads" => "uploads"] as $token => $name) {
            $source = $source_tokens[$token] ?? null;
            if ($source !== null && !path_is_within_root($source, $content)) {
                $directories[$name] = $source;
            }
        }

        return $directories;
    }

    /**
     * Resolve raw --only sources into deduped real source absolute prefixes.
     *
     * @param array<int,string> $only_raw
     * @param array<string,mixed> $preflight_data
     * @return array<int,string>
     */
    public static function resolvePullOnlyFilesWithPathPrefixes(
        array $only_raw,
        array $preflight_data
    ): array {
        $source_tokens = self::wpSourcePathTokens($preflight_data);

        $prefixes = [];
        foreach ($only_raw as $src) {
            if ($src === "") {
                throw new InvalidArgumentException("--only source cannot be empty");
            }

            $resolved = self::resolveTokenPath($src, $source_tokens);
            $prefixes[$resolved] = true;

            if ($resolved === $source_tokens["wp-content"]) {
                foreach (self::contentDirectoriesOutsideWpContent($source_tokens) as $source) {
                    $prefixes[$source] = true;
                }
            }
        }

        $sources = array_keys($prefixes);
        $minimal = [];
        foreach ($sources as $path) {
            $covered = false;

            foreach ($sources as $other) {
                if ($other !== $path && path_is_within_root($path, $other)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $minimal[] = $path;
            }
        }

        return $minimal;
    }

    /**
     * Whether a path is selected by the active --only file path prefixes.
     *
     * @param array<int,string> $prefixes
     */
    public static function isFilePathSelectedByPullOnlyFiles(
        string $path,
        array $prefixes
    ): bool {
        if (empty($prefixes)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            $remainder = self::pathRemainderUnder($path, $prefix);
            if ($remainder === "") {
                return false;
            }
            if ($remainder !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build source-site WordPress path tokens from preflight data.
     *
     * @param array<string,mixed> $preflight_data
     * @return array<string,string|null>
     */
    public static function wpSourcePathTokens(array $preflight_data): array
    {
        $paths = $preflight_data["database"]["wp"]["paths_urls"] ?? [];
        if (!is_array($paths)) {
            $paths = [];
        }

        $content_dir = self::cleanPreflightPath($paths["content_dir"] ?? null);

        $abspath = self::cleanPreflightPath($paths["abspath"] ?? null);
        if ($abspath === null) {
            $abspath = self::cleanPreflightPath($preflight_data["wp_detect"]["roots"][0]["path"] ?? null);
        }

        $plugins_dir = self::cleanPreflightPath($paths["plugins_dir"] ?? null);
        $mu_plugins_dir = self::cleanPreflightPath($paths["mu_plugins_dir"] ?? null);
        $uploads_dir = self::cleanPreflightPath($paths["uploads"]["basedir"] ?? null);

        if ($content_dir !== null) {
            $plugins_dir = $plugins_dir ?? wp_join_unix_paths($content_dir, "plugins");
            $mu_plugins_dir = $mu_plugins_dir ?? wp_join_unix_paths($content_dir, "mu-plugins");
            $uploads_dir = $uploads_dir ?? wp_join_unix_paths($content_dir, "uploads");
        }

        return [
            "abspath" => $abspath,
            "wp-content" => $content_dir,
            "wp-plugins" => $plugins_dir,
            "wp-mu-plugins" => $mu_plugins_dir,
            "wp-uploads" => $uploads_dir,
        ];
    }

    /**
     * Resolve a --remap/--only path argument into an absolute path.
     *
     * @param array<string,string|null> $tokens
     */
    public static function resolveTokenPath(string $raw, array $tokens): string
    {
        $resolved = $raw;
        foreach ($tokens as $name => $value) {
            $token = ":{$name}:";
            $token_offset = strpos($resolved, $token);
            if ($token_offset === false) {
                continue;
            }

            if ($token_offset !== 0 || strpos($resolved, $token, strlen($token)) !== false) {
                throw new InvalidArgumentException(
                    "token \"{$token}\" must appear only at the beginning of the path"
                );
            }

            if ($value === null) {
                throw new InvalidArgumentException(
                    "Cannot resolve token \"{$token}\": not available in preflight data. Run preflight first."
                );
            }

            $resolved = $value . substr($resolved, strlen($token));
        }

        $resolved = rtrim($resolved, "/");
        assert_valid_path($resolved, "path \"{$raw}\"");

        return $resolved;
    }

    /**
     * Map a source absolute path through remap rules, using the deepest match.
     *
     * @param array<string,string> $remap_rules
     */
    public static function remapSourcePathToTarget(
        string $source_path,
        array $remap_rules
    ): ?string {
        $best = null;
        $best_source_length = -1;

        foreach ($remap_rules as $source => $target) {
            $rest = self::pathRemainderUnder($source_path, $source);
            if ($rest !== null && strlen($source) > $best_source_length) {
                $best = wp_join_unix_paths($target, $rest);
                $best_source_length = strlen($source);
            }
        }

        return $best;
    }

    /**
     * Resolve a remote absolute path into a local path under the fs root.
     *
     * @param array<string,string> $remap_rules
     * @param array<int,string> $original_export_directories
     */
    public static function remotePathToLocalPathWithinImportRoot(
        string $path,
        string $filesystem_root,
        array $remap_rules,
        ?string $symlink_bundle_directory,
        array $original_export_directories
    ): string {
        assert_valid_path($path, "remote path");

        if (!empty($remap_rules)) {
            $target = self::remapSourcePathToTarget($path, $remap_rules);
            if ($target !== null) {
                return $target;
            }
        }

        if (
            $symlink_bundle_directory !== null &&
            !self::pathIsWithinOriginalExportScope($path, $original_export_directories)
        ) {
            return rtrim($symlink_bundle_directory, "/") . $path;
        }

        return rtrim($filesystem_root, "/") . $path;
    }

    /**
     * Whether a path falls under the original export directories.
     *
     * @param array<int,string> $export_directories
     */
    public static function pathIsWithinOriginalExportScope(
        string $path,
        array $export_directories
    ): bool {
        foreach ($export_directories as $root) {
            if (path_is_within_root($path, $root)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the server traversal directories and explain which extras were added.
     *
     * @param array<string,mixed> $preflight_data
     * @param array<string,string> $remap_rules
     * @param array<int,string> $pull_only_prefixes
     * @return array{directories:array<int,string>,root_directories:array<int,string>,auto_detected:array<string,string>}
     */
    public static function resolveExportDirectories(
        array $preflight_data,
        ?string $extra_directory,
        array $remap_rules,
        array $pull_only_prefixes
    ): array {
        if (!empty($pull_only_prefixes)) {
            return [
                "directories" => $pull_only_prefixes,
                "root_directories" => [],
                "auto_detected" => [],
            ];
        }

        $dirs = self::rootDirectoriesFromPreflight($preflight_data);
        if (empty($dirs)) {
            return [
                "directories" => [],
                "root_directories" => [],
                "auto_detected" => [],
            ];
        }

        $extra_paths = [
            "document_root" => rtrim((string) ($preflight_data["runtime"]["document_root"] ?? ""), "/"),
            "content_dir" => rtrim((string) ($preflight_data["database"]["wp"]["paths_urls"]["content_dir"] ?? ""), "/"),
        ];

        if ($extra_directory !== null && $extra_directory !== "") {
            $extra_paths["extra_directory"] = rtrim($extra_directory, "/");
        }

        $remap_index = 0;
        foreach (array_keys($remap_rules) as $source) {
            $extra_paths["remap_source_{$remap_index}"] = $source;
            $remap_index++;
        }

        $ini_all = $preflight_data["runtime"]["ini_get_all"] ?? [];
        if (!is_array($ini_all)) {
            $ini_all = [];
        }
        foreach (["auto_prepend_file", "auto_append_file"] as $ini_key) {
            $ini_path = $ini_all[$ini_key] ?? "";
            if (is_string($ini_path) && $ini_path !== "" && $ini_path[0] === "/") {
                $ini_dir = rtrim(dirname($ini_path), "/");
                if ($ini_dir !== "" && $ini_dir !== "/") {
                    $extra_paths[$ini_key] = $ini_dir;
                }
            }
        }

        $auto_detected = [];
        foreach ($extra_paths as $label => $path) {
            if ($path === "") {
                continue;
            }
            $covered = false;
            foreach ($dirs as $root) {
                if ($path === $root || str_starts_with($path, $root . "/")) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $dirs[] = $path;
                $auto_detected[$label] = $path;
            }
        }

        return [
            "directories" => $dirs,
            "root_directories" => self::rootDirectoriesFromPreflight($preflight_data),
            "auto_detected" => $auto_detected,
        ];
    }

    /**
     * Extract root directories from preflight wp_detect data.
     *
     * @param array<string,mixed> $preflight_data
     * @return array<int,string>
     */
    public static function rootDirectoriesFromPreflight(array $preflight_data): array
    {
        $roots = $preflight_data["wp_detect"]["roots"] ?? [];
        if (!is_array($roots) || empty($roots)) {
            return [];
        }

        $dirs = [];
        foreach ($roots as $root) {
            $path = is_array($root) ? ($root["path"] ?? null) : null;
            if (is_string($path) && $path !== "") {
                $dirs[] = rtrim($path, "/");
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * Returns the remainder of $path underneath $prefix.
     */
    public static function pathRemainderUnder(string $path, string $prefix): ?string
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
     * Clean a path value from preflight data: trim, strip trailing slash.
     */
    public static function cleanPreflightPath($value): ?string
    {
        if (!is_string($value) || trim($value) === "") {
            return null;
        }
        return rtrim($value, "/");
    }

    /**
     * Compute a relative path from $from to $to.
     */
    public static function relativePath(string $from, string $to): string
    {
        $from_parts = explode("/", trim($from, "/"));
        $to_parts = explode("/", trim($to, "/"));

        $common = 0;
        $max = min(count($from_parts), count($to_parts));
        while ($common < $max && $from_parts[$common] === $to_parts[$common]) {
            $common++;
        }

        $up = count($from_parts) - $common;
        $down = array_slice($to_parts, $common);

        $parts = array_merge(array_fill(0, $up, ".."), $down);
        return implode("/", $parts) ?: ".";
    }
}
