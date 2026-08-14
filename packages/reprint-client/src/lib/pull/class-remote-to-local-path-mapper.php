<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Filesystem paths are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Maps remote paths into one local filesystem tree.
 *
 * map_path() is the write-path mapping used by a files pull. An explicit
 * remap wins first. If several remaps match, the longest remote prefix wins.
 * An unmatched path from the original pull scope keeps its remote spelling
 * under the local filesystem root. A followed symlink target outside that
 * scope goes under the configured local followed-symlinks root instead.
 * Copied targets and rewritten symlink destinations both use this mapping,
 * so each rewritten link points to the place where its target was copied.
 *
 *     $mapper = new RemoteToLocalPathMapper(
 *         '/local',
 *         ['/srv/site'],
 *         ['/srv/site/wp-content' => '/local/content']
 *     );
 *
 *     $mapper->map_path('/srv/site/index.php');
 *     // /local/srv/site/index.php
 *
 *     $mapper->map_path('/srv/site/wp-content/plugin.php');
 *     // /local/content/plugin.php
 *
 * Mapped paths remain byte strings and need not be valid UTF-8. get_config()
 * base64-encodes each configured path for storage in a JSON cursor.
 *
 * @phpstan-type Config array{filesystem_root_b64:string,original_remote_roots_b64:list<string>,resolved_path_mappings:list<array{remote_prefix_b64:string,local_prefix_b64:string}>,local_followed_symlinks_root_b64:string|null}
 */
final class RemoteToLocalPathMapper
{
    /** Local filesystem root beneath which pulled paths are written. */
    private string $filesystem_root;

    /** @var list<string> Remote roots selected before following symlinks. */
    private array $original_remote_roots;

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
     * @param string               $filesystem_root             Local filesystem root.
     * @param list<string>         $original_remote_roots       Remote roots selected before following symlinks.
     * @param array<string,string> $resolved_path_mappings      Remote absolute prefix to local absolute prefix.
     * @param string|null          $local_followed_symlinks_root Local root for followed targets outside the original scope.
     */
    public function __construct(
        string $filesystem_root,
        array $original_remote_roots,
        array $resolved_path_mappings = [],
        ?string $local_followed_symlinks_root = null
    ) {
        $this->filesystem_root = $filesystem_root;
        $this->original_remote_roots = $original_remote_roots;
        $this->resolved_path_mappings = $resolved_path_mappings;
        $this->local_followed_symlinks_root = $local_followed_symlinks_root;
    }

    /**
     * Recreates a mapper from get_config().
     *
     * @phpstan-param Config $config
     */
    public static function from_config(array $config): self
    {
        $resolved_path_mappings = [];
        foreach ($config["resolved_path_mappings"] as $resolved_path_mapping) {
            $resolved_path_mappings[
                self::decode_config_path(
                    $resolved_path_mapping["remote_prefix_b64"]
                )
            ] = self::decode_config_path(
                $resolved_path_mapping["local_prefix_b64"]
            );
        }

        return new self(
            self::decode_config_path($config["filesystem_root_b64"]),
            array_map(
                [self::class, "decode_config_path"],
                $config["original_remote_roots_b64"]
            ),
            $resolved_path_mappings,
            $config["local_followed_symlinks_root_b64"] === null
                ? null
                : self::decode_config_path(
                    $config["local_followed_symlinks_root_b64"]
                )
        );
    }

    /**
     * Returns the mapping settings with every path base64-encoded.
     *
     * @return array {
     *     @type string       $filesystem_root_b64              Local filesystem root.
     *     @type list<string> $original_remote_roots_b64        Original remote roots.
     *     @type list<array>  $resolved_path_mappings           Remote and local prefix pairs.
     *     @type string|null  $local_followed_symlinks_root_b64 Local followed-symlinks root.
     * }
     * @phpstan-return Config
     */
    public function get_config(): array
    {
        $resolved_path_mappings = [];
        foreach ($this->resolved_path_mappings as $remote_prefix => $local_prefix) {
            $resolved_path_mappings[] = [
                "remote_prefix_b64" => base64_encode($remote_prefix),
                "local_prefix_b64" => base64_encode($local_prefix),
            ];
        }

        return [
            "filesystem_root_b64" => base64_encode($this->filesystem_root),
            "original_remote_roots_b64" => array_map(
                "base64_encode",
                $this->original_remote_roots
            ),
            "resolved_path_mappings" => $resolved_path_mappings,
            "local_followed_symlinks_root_b64" =>
                $this->local_followed_symlinks_root === null
                    ? null
                    : base64_encode($this->local_followed_symlinks_root),
        ];
    }

    /**
     * Re-expresses a remote-absolute change scope in local-index coordinates.
     */
    public function map_change_scope_to_local_index(
        FileSyncChangeScope $remote_change_scope
    ): FileSyncChangeScope {
        $config = $remote_change_scope->get_config();
        if ($config["index_path_coordinates"] !== "remote_absolute") {
            throw new InvalidArgumentException(
                "Only a remote_absolute file-sync change scope can be mapped to a local index."
            );
        }
        $config["index_path_coordinates"] = "local_relative";
        $config["remote_to_local_path_mapper_config"] = $this->get_config();
        return FileSyncChangeScope::from_config($config);
    }

    /** Returns the local filesystem root used by map_path(). */
    public function get_filesystem_root(): string
    {
        return $this->filesystem_root;
    }

    /**
     * Maps one remote absolute path to its local absolute path.
     *
     * @param string $remote_absolute_path Remote absolute path.
     * @return string Local absolute path under the filesystem root.
     */
    public function map_path(string $remote_absolute_path): string
    {
        assert_valid_path($remote_absolute_path, "remote absolute path");
        $local_absolute_path = null;
        $longest_remote_prefix_length = -1;
        foreach ($this->resolved_path_mappings as $remote_prefix => $local_prefix) {
            $remainder = path_remainder_under($remote_absolute_path, $remote_prefix);
            if (
                $remainder !== null
                && strlen($remote_prefix) > $longest_remote_prefix_length
            ) {
                $local_absolute_path = wp_join_unix_paths($local_prefix, $remainder);
                $longest_remote_prefix_length = strlen($remote_prefix);
            }
        }
        if ($local_absolute_path !== null) {
            return $local_absolute_path;
        }

        if ($this->local_followed_symlinks_root !== null) {
            $path_is_within_original_remote_roots = false;
            foreach ($this->original_remote_roots as $original_remote_root) {
                if (
                    path_is_same_as_or_descendant_of(
                        $remote_absolute_path,
                        $original_remote_root
                    )
                ) {
                    $path_is_within_original_remote_roots = true;
                    break;
                }
            }
            if (!$path_is_within_original_remote_roots) {
                if ($remote_absolute_path === "/") {
                    return $this->local_followed_symlinks_root;
                }
                return wp_join_unix_paths(
                    $this->local_followed_symlinks_root,
                    $remote_absolute_path
                );
            }
        }

        if ($remote_absolute_path === "/") {
            return $this->filesystem_root;
        }
        return wp_join_unix_paths($this->filesystem_root, $remote_absolute_path);
    }

    /**
     * Returns every remote path which maps to one local absolute path.
     *
     * The identity placement, each explicit remap target, and the followed-
     * symlinks placement may overlap. Candidates are returned only when the
     * forward mapping confirms the requested local path.
     *
     * @param string $local_absolute_path Local absolute path.
     * @return list<string> Remote absolute paths mapping to the local path.
     */
    public function remote_paths_mapping_to(string $local_absolute_path): array
    {
        assert_valid_path($local_absolute_path, "local absolute path");
        $remote_absolute_path_candidates = [];

        $remainder = path_remainder_under(
            $local_absolute_path,
            $this->filesystem_root
        );
        if ($remainder !== null) {
            $remote_absolute_path_candidates[] =
                $remainder === "" ? "/" : $remainder;
        }

        foreach ($this->resolved_path_mappings as $remote_prefix => $local_prefix) {
            $remainder = path_remainder_under($local_absolute_path, $local_prefix);
            if ($remainder !== null) {
                $remote_absolute_path_candidates[] = wp_join_unix_paths(
                    $remote_prefix,
                    $remainder
                );
            }
        }

        if ($this->local_followed_symlinks_root !== null) {
            $remainder = path_remainder_under(
                $local_absolute_path,
                $this->local_followed_symlinks_root
            );
            if ($remainder !== null) {
                $remote_absolute_path_candidates[] =
                    $remainder === "" ? "/" : $remainder;
            }
        }

        $remote_absolute_paths = [];
        foreach ($remote_absolute_path_candidates as $remote_absolute_path) {
            if (
                !in_array($remote_absolute_path, $remote_absolute_paths, true)
                && $this->map_path($remote_absolute_path) === $local_absolute_path
            ) {
                $remote_absolute_paths[] = $remote_absolute_path;
            }
        }
        return $remote_absolute_paths;
    }

    /**
     * Whether one remote path owns its mapped local subtree.
     *
     * Every remote descendant must retain the same suffix below the mapped
     * local path. A nested remap or a transition between the original and
     * followed-symlink placements must not leave a local hole. A separately
     * selected remote subtree must not map to an intersecting local subtree.
     */
    public function remote_path_owns_mapped_local_subtree(
        string $remote_absolute_path
    ): bool
    {
        $local_absolute_path = $this->map_path($remote_absolute_path);
        foreach (
            $this->remote_paths_mapping_to($local_absolute_path)
            as $mapped_remote_path
        ) {
            if (
                (
                    $this->local_followed_symlinks_root !== null
                    || path_is_same_as_or_descendant_of(
                        $mapped_remote_path,
                        $this->original_remote_roots
                    )
                )
                && !path_is_same_as_or_descendant_of(
                    $mapped_remote_path,
                    $remote_absolute_path
                )
            ) {
                return false;
            }
        }

        $remote_mapping_boundaries = array_merge(
            array_keys($this->resolved_path_mappings),
            $this->original_remote_roots
        );
        foreach ($remote_mapping_boundaries as $remote_mapping_boundary) {
            if (
                path_is_same_as_or_descendant_of(
                    $remote_absolute_path,
                    $remote_mapping_boundary
                )
            ) {
                continue;
            }

            $mapped_boundary = $this->map_path($remote_mapping_boundary);
            $remainder = path_remainder_under(
                $remote_mapping_boundary,
                $remote_absolute_path
            );
            if ($remainder !== null) {
                if (
                    $mapped_boundary
                    !== wp_join_unix_paths($local_absolute_path, $remainder)
                ) {
                    return false;
                }
            } elseif (
                path_is_same_as_or_descendant_of(
                    $local_absolute_path,
                    $mapped_boundary
                )
                || path_is_same_as_or_descendant_of(
                    $mapped_boundary,
                    $local_absolute_path
                )
            ) {
                return false;
            }
        }

        if (
            $this->local_followed_symlinks_root !== null
            && $this->local_followed_symlinks_root !== $local_absolute_path
            && path_is_same_as_or_descendant_of(
                $this->local_followed_symlinks_root,
                $local_absolute_path
            )
        ) {
            foreach (
                $this->remote_paths_mapping_to($this->local_followed_symlinks_root)
                as $mapped_remote_path
            ) {
                if (
                    !path_is_same_as_or_descendant_of(
                        $mapped_remote_path,
                        $remote_absolute_path
                    )
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Decodes one arbitrary-byte path from a stored configuration. */
    private static function decode_config_path(string $path_b64): string
    {
        $path = base64_decode($path_b64, true);
        if ($path === false) {
            throw new InvalidArgumentException(
                "Remote-to-local path mapper configuration contains an invalid base64 path."
            );
        }
        return $path;
    }
}
