<?php

namespace Reprint\Importer;

use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions contain CLI filesystem paths, never HTML output.

/**
 * Merges path mutations into the local index file:
 *
 *     current index ----\
 *                        +---> updated index
 *     mutations     ----/
 *
 * For example, these JSONL records use base64-encoded paths for
 * `file.txt` (ZmlsZS50eHQ=), `folder/old.txt` (Zm9sZGVyL29sZC50eHQ=),
 * and `folder/new.txt` (Zm9sZGVyL25ldy50eHQ=):
 *
 *     current index:
 *         {"path":"ZmlsZS50eHQ=","ctime":10,"size":1,"type":"file"}
 *         {"path":"Zm9sZGVyL29sZC50eHQ=","ctime":11,"size":3,"type":"file"}
 *
 *     mutations:
 *         {"op":"+","path":"Zm9sZGVyL25ldy50eHQ=","ctime":12,"size":4,"type":"file"}
 *         {"op":"-","path":"Zm9sZGVyL29sZC50eHQ="}
 *
 *     updated index:
 *         {"path":"ZmlsZS50eHQ=","ctime":10,"size":1,"type":"file"}
 *         {"path":"Zm9sZGVyL25ldy50eHQ=","ctime":12,"size":4,"type":"file"}
 *
 * The merge retains one entry from each input and writes one replacement
 * index.
 *
 * ## Assumptions
 *
 * * Both input files are pre-sorted by the decoded path.
 * * Non-empty do not have their own entries. Only empty directories are listed explicitly.
 *   This keeps entries with the same path prefix together. If empty directories had separate
 *   entries, we'd see sequences such as 1. parent 2. parent-name 3. parent/child. The `parent-name`
 *   between the directory name and its descendants could really complicate the merge logic.
 *   Reprint takes the simple path and does not store non-empty directories in the index.
 *
 * @param  string  $local_index_path  Path of the local index file.
 * @param  string  $sorted_local_index_updates_path  Path of the mutations file. It must be JSONL with
 *                                                 `+` upserts and `-` deletions, already sorted by
 *                                                 decoded path bytes.
 */
function merge_local_index_mutations(
	string $local_index_path,
	string $sorted_local_index_updates_path
): void {
	$next_local_index_path = $local_index_path . '.next';
	$local_index_directory = dirname( $local_index_path );
	if (
		! is_dir( $local_index_directory )
		&& ! mkdir( $local_index_directory, 0755, true )
	) {
		throw new \RuntimeException(
			'Failed to create the local index directory: '
			. $local_index_directory
			. '.'
		);
	}

	$local_index_handle = null;
	/** @var list<resource> $open_local_index_merge_handles */
	$open_local_index_merge_handles = [];
	try {
		if ( is_file( $local_index_path ) ) {
			$local_index_handle = fopen( $local_index_path, 'rb' );
			if ( ! is_resource( $local_index_handle ) ) {
				throw new \RuntimeException(
					'Failed to open the local index.'
				);
			}
			$open_local_index_merge_handles[] = $local_index_handle;
		}
		$sorted_local_index_updates_handle = fopen(
			$sorted_local_index_updates_path,
			'rb'
		);
		if ( ! is_resource( $sorted_local_index_updates_handle ) ) {
			throw new \RuntimeException(
				'Failed to merge local index updates.'
			);
		}
		$open_local_index_merge_handles[] = $sorted_local_index_updates_handle;
		$local_index_replacement_handle   = fopen(
			$next_local_index_path,
			'wb'
		);
		if ( ! is_resource( $local_index_replacement_handle ) ) {
			throw new \RuntimeException(
				'Failed to merge local index updates.'
			);
		}
		$open_local_index_merge_handles[] = $local_index_replacement_handle;
		$local_index_entries              = read_local_index_entries(
			$local_index_handle
		);
		$sorted_local_index_updates       = read_local_index_updates(
			$sorted_local_index_updates_handle
		);
		$local_index_entries->rewind();
		$sorted_local_index_updates->rewind();

		// Let's merge! We'll iterate over the mutations.
		while ( $sorted_local_index_updates->valid() ) {
			$local_index_update      = $sorted_local_index_updates->current();
			$local_index_update_path = $local_index_update['path'];

			// Copy everything from the old index that preceeds the current mutation.
			while (
				$local_index_entries->valid()
				&& strcmp(
					$local_index_entries->current()['path'],
					$local_index_update_path
				) < 0
			) {
				write_local_index_entry(
					$local_index_replacement_handle,
					$local_index_entries->current()
				);
				$local_index_entries->next();
			}

			// Write upserts into the new index.
			$is_upsert = ! $local_index_update['delete'];
			if ( $is_upsert ) {
				write_local_index_entry(
					$local_index_replacement_handle,
					$local_index_update
				);
			}
			$sorted_local_index_updates->next();

			/*
			 * Skip the old entry at this path. Both streams contain it and
			 * the merged index already has the mutated version (which is either
			 * a path for upserts or no path for deletions).
			 */
			if (
				$local_index_entries->valid()
				&& $local_index_entries->current()['path'] === $local_index_update_path
			) {
				$local_index_entries->next();
			}

			/*
			 * Path replacement! Skip over all the entire old subtree without writing
			 * anything to the merged index.
			 */
			while (
				$local_index_entries->valid()
				&& path_is_same_as_or_descendant_of(
					$local_index_entries->current()['path'],
					$local_index_update_path
				)
			) {
				$local_index_entries->next();
			}
		}

		/*
		 * No mutations remain, so nothing can invalidate the rest of the old
		 * index. Copy that tail unchanged.
		 */
		while ( $local_index_entries->valid() ) {
			write_local_index_entry(
				$local_index_replacement_handle,
				$local_index_entries->current()
			);
			$local_index_entries->next();
		}

		if ( ! fflush( $local_index_replacement_handle ) ) {
			throw new \RuntimeException(
				'Failed to flush the merged index.'
			);
		}

		foreach ( $open_local_index_merge_handles as $open_local_index_merge_handle ) {
			fclose( $open_local_index_merge_handle );
		}
		$open_local_index_merge_handles = [];

		if ( ! rename( $next_local_index_path, $local_index_path ) ) {
			throw new \RuntimeException(
				'Failed to replace the local index: '
				. $local_index_path
				. '.'
			);
		}
	} finally {
		foreach ( $open_local_index_merge_handles as $open_local_index_merge_handle ) {
			fclose( $open_local_index_merge_handle );
		}

		/*
		 * Preserve the original failure if cleanup also fails. Before the final
		 * rename these are only work files; the published index is untouched.
		 */
		if ( is_file( $next_local_index_path ) ) {
			@unlink( $next_local_index_path );
		}
	}
}

/**
 * Decodes one Reprint index entry.
 *
 * @return array {
 * @type string $path Decoded filesystem path.
 * @type int $ctime Indexed change timestamp.
 * @type int $size Indexed size.
 * @type string $type `file`, `link`, or `dir`.
 * @type bool $empty Whether a directory has no descendant entries in
 *                         this index.
 * }
 * @phpstan-return array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool}
 */
function decode_local_index_entry( string $local_index_json_line ): array {
	/** @var array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool} $encoded_local_index_entry */
	$encoded_local_index_entry = json_decode(
		$local_index_json_line,
		true,
		512,
		JSON_THROW_ON_ERROR
	);
	/** @var string $local_index_entry_path */
	$local_index_entry_path = base64_decode(
		$encoded_local_index_entry['path']
	);
	$local_index_entry      = [
		'path'  => $local_index_entry_path,
		'ctime' => $encoded_local_index_entry['ctime'],
		'size'  => $encoded_local_index_entry['size'],
		'type'  => $encoded_local_index_entry['type'],
	];
	if ( array_key_exists( 'empty', $encoded_local_index_entry ) ) {
		$local_index_entry['empty'] =
			$encoded_local_index_entry['empty'];
	}

	return $local_index_entry;
}

/**
 * Reads a raw-path-sorted local index stream.
 *
 * @param  resource|null  $local_index_handle  Open index, or null when no index exists.
 *
 * @return \Generator<int,array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool},mixed,void>
 */
function read_local_index_entries( $local_index_handle ): \Generator {
	if ( ! is_resource( $local_index_handle ) ) {
		return;
	}
	while ( true ) {
		$local_index_json_line = fgets( $local_index_handle );
		if ( $local_index_json_line === false ) {
			break;
		}
		yield decode_local_index_entry( $local_index_json_line );
	}
	if ( ! feof( $local_index_handle ) ) {
		throw new \RuntimeException( 'Failed to read an index entry.' );
	}
}

/**
 * Reads a raw-path-sorted update stream.
 *
 * @param  resource  $sorted_local_index_updates_handle  Open raw-path-sorted update file.
 *
 * @return \Generator<int,array{path:string,delete:bool,ctime:int,size:int,type:string|null,empty?:bool},mixed,void>
 */
function read_local_index_updates( $sorted_local_index_updates_handle ): \Generator {
	while ( true ) {
		$local_index_update_json_line = fgets(
			$sorted_local_index_updates_handle
		);
		if ( $local_index_update_json_line === false ) {
			break;
		}
		/** @var array{op:'-'|'+',path:string,ctime?:int,size?:int,type?:'file'|'link'|'dir'} $encoded_local_index_update */
		$encoded_local_index_update = json_decode(
			$local_index_update_json_line,
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		/** @var string $local_index_entry_path */
		$local_index_entry_path       = base64_decode(
			$encoded_local_index_update['path']
		);
		$local_index_entry_is_deleted =
			$encoded_local_index_update['op'] === '-';
		$local_index_update           = [
			'path'   => $local_index_entry_path,
			'delete' => $local_index_entry_is_deleted,
			'ctime'  => $local_index_entry_is_deleted
				? 0
				: $encoded_local_index_update['ctime'],
			'size'   => $local_index_entry_is_deleted
				? 0
				: $encoded_local_index_update['size'],
			'type'   => $local_index_entry_is_deleted
				? null
				: $encoded_local_index_update['type'],
		];
		if (
			! $local_index_entry_is_deleted
			&& $encoded_local_index_update['type'] === 'dir'
		) {
			$local_index_update['empty'] = true;
		}
		yield $local_index_update;
	}
	if ( ! feof( $sorted_local_index_updates_handle ) ) {
		throw new \RuntimeException( 'Failed to read an index-update entry.' );
	}
}

/**
 * Writes one JSONL index entry.
 *
 * @param  resource  $local_index_output_handle  Open index output.
 * @param  array  $local_index_entry  {
 *     Decoded index entry.
 *
 * @type string $path Path bytes.
 * @type int $ctime Entry ctime.
 * @type int $size Entry size.
 * @type string $type `file`, `link`, or `dir`.
 * @type bool $empty Whether a directory has no descendant entries in
 *                         this index.
 * }
 */
function write_local_index_entry(
	$local_index_output_handle,
	array $local_index_entry
): void {
	$encoded_local_index_entry = [
		'path'  => base64_encode( $local_index_entry['path'] ),
		'ctime' => $local_index_entry['ctime'],
		'size'  => $local_index_entry['size'],
		'type'  => $local_index_entry['type'],
	];
	if (
		$local_index_entry['type'] === 'dir'
		&& array_key_exists( 'empty', $local_index_entry )
	) {
		$encoded_local_index_entry['empty'] = $local_index_entry['empty'];
	}
	$local_index_json_line = json_encode(
		                         $encoded_local_index_entry,
		                         JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	                         ) . "\n";
	if (
		fwrite( $local_index_output_handle, $local_index_json_line )
		!== strlen( $local_index_json_line )
	) {
		throw new \RuntimeException( 'Failed to write an index entry.' );
	}
}

/**
 * Writes one JSONL local index update.
 *
 * @param  resource  $local_index_updates_handle  Open update output.
 * @param  array  $local_index_update  {
 *     One local index operation.
 *
 * @type string $op `+` or `-`.
 * @type string $path Base64-encoded path.
 * @type int $ctime Local ctime for a `+` operation.
 * @type int $size Local size for a `+` operation.
 * @type string $type Local type for a `+` operation.
 * }
 */
function write_local_index_update(
	$local_index_updates_handle,
	array $local_index_update
): void {
	$local_index_update_json_line = json_encode(
		                                $local_index_update,
		                                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	                                ) . "\n";
	if (
		fwrite( $local_index_updates_handle, $local_index_update_json_line )
		!== strlen( $local_index_update_json_line )
	) {
		throw new \RuntimeException(
			'Failed to write a local index update.'
		);
	}
}
