<?php

namespace Reprint\Importer;

use RuntimeException;
use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * Remove local source-host files after the caller saves their push exclusions.
 *
 * @param string[] $excluded_local_paths Paths relative to the local site root.
 * @param string   $local_document_root  Local site root with the standard wp-content layout.
 * @return string[] Paths removed, relative to the local site root. Absent paths are omitted.
 */
function remove_host_plugin_paths( array $excluded_local_paths, string $local_document_root ): array {
	$removed_paths = array();
	foreach ( $excluded_local_paths as $relative_path ) {
		$full_path = wp_join_unix_paths( $local_document_root, $relative_path );
		if ( ! file_exists( $full_path ) && ! is_link( $full_path ) ) {
			continue;
		}
		if ( is_dir( $full_path ) && ! is_link( $full_path ) ) {
			rmdir_recursive( $full_path );
		} else {
			unlink( $full_path );
		}
		clearstatcache( true, $full_path );
		if ( file_exists( $full_path ) || is_link( $full_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI filesystem error, not HTML.
			throw new RuntimeException( "Could not remove source-host path: {$full_path}." );
		}
		$removed_paths[] = $relative_path;
	}
	return $removed_paths;
}

/**
 * Recursively remove a directory and all its contents.
 *
 * Child symlinks are unlinked, not followed. Removal errors are left to callers
 * to check; runtime-file refresh tolerates them, host-plugin cleanup does not.
 *
 * @param string $directory Directory to remove.
 */
function rmdir_recursive( string $directory ): void {
	if ( ! is_dir( $directory ) ) {
		return;
	}
	$entries = scandir( $directory );
	if ( false === $entries ) {
		return;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = wp_join_unix_paths( $directory, $entry );
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			rmdir_recursive( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $directory );
}
