<?php

namespace Reprint\Importer;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_segments;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;

/**
 * Reports whether a sync operation may change an index path.
 *
 * The path must be inside an included root. It must not be an excluded root,
 * sit below one, or contain one. The last case prevents a parent deletion or
 * replacement from changing an excluded descendant.
 *
 * @param string       $index_path                Path in index coordinates.
 * @param list<string> $included_index_path_roots Roots within which changes are allowed.
 * @param list<string> $excluded_index_path_roots Roots which changes must not affect.
 */
function file_sync_index_path_may_change(
    string $index_path,
    array $included_index_path_roots,
    array $excluded_index_path_roots
): bool {
    $is_included = false;
    foreach ($included_index_path_roots as $included_index_path_root) {
        if (
            $included_index_path_root === ""
            || path_is_same_as_or_descendant_of(
                $index_path,
                $included_index_path_root
            )
        ) {
            $is_included = true;
            break;
        }
    }
    if (!$is_included) {
        return false;
    }

    foreach ($excluded_index_path_roots as $excluded_index_path_root) {
        if (
            $excluded_index_path_root === ""
            || path_is_same_as_or_descendant_of(
                $index_path,
                $excluded_index_path_root
            )
            || path_is_same_as_or_descendant_of(
                $excluded_index_path_root,
                $index_path
            )
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Finds the shallowest missing path which the caller may delete.
 *
 * The missing path was present in the starting index and is absent from the
 * result index. The adjacent result paths show whether each parent still has
 * a result descendant. The callback keeps the result within the caller's path
 * selection and mapping boundaries. The function returns null when no missing
 * candidate may be deleted.
 *
 * A missing empty-directory entry needs no deletion when a result descendant
 * now represents that directory.
 *
 * @param string                $missing_index_path                Path missing from the result index.
 * @param string|null           $preceding_result_index_path       Adjacent result path before the missing path.
 * @param string|null           $following_result_index_path       Adjacent result path after the missing path.
 * @param callable(string):bool $candidate_index_path_may_change   Reports whether one missing candidate may be deleted.
 * @param bool                  $missing_path_is_empty_directory   Whether the missing entry described an empty directory.
 * @return string|null Shallowest missing path safe to delete, or null when no deletion is needed or allowed.
 */
function find_file_sync_deletion_root(
    string $missing_index_path,
    ?string $preceding_result_index_path,
    ?string $following_result_index_path,
    callable $candidate_index_path_may_change,
    bool $missing_path_is_empty_directory = false
): ?string {
    if (
        $missing_path_is_empty_directory
        && file_sync_result_contains_path_or_descendant(
            $missing_index_path,
            $preceding_result_index_path,
            $following_result_index_path
        )
    ) {
        return null;
    }

    $deletion_root = $candidate_index_path_may_change($missing_index_path)
        ? $missing_index_path
        : null;
    $missing_path_components = wp_unix_path_segments($missing_index_path);
    $candidate_path_components = [];
    $index_path_root = strpos($missing_index_path, "/") === 0 ? "/" : "";
    for (
        $component_index = 0,
        $component_count = count($missing_path_components) - 1;
        $component_index < $component_count;
        ++$component_index
    ) {
        $candidate_path_components[] =
            $missing_path_components[$component_index];
        $candidate_index_path = wp_join_unix_paths(
            $index_path_root,
            ...$candidate_path_components
        );
        if (
            !file_sync_result_contains_path_or_descendant(
                $candidate_index_path,
                $preceding_result_index_path,
                $following_result_index_path
            )
            && $candidate_index_path_may_change($candidate_index_path)
        ) {
            return $candidate_index_path;
        }
    }
    return $deletion_root;
}

/** Checks adjacent result entries for a path or its descendant. */
function file_sync_result_contains_path_or_descendant(
    string $index_path,
    ?string $preceding_result_index_path,
    ?string $following_result_index_path
): bool {
    // NUL cannot occur in an index path and cannot match either test.
    $preceding_result_index_path = $preceding_result_index_path ?? "\0";
    $following_result_index_path = $following_result_index_path ?? "\0";

    return path_is_same_as_or_descendant_of(
        $preceding_result_index_path,
        $index_path
    ) || path_is_same_as_or_descendant_of(
        $following_result_index_path,
        $index_path
    );
}
