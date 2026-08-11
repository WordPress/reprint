<?php

namespace Reprint\Importer\Filesystem;

use InvalidArgumentException;
use Reprint\Importer\PreserveLocalSkipException;
use RuntimeException;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

/**
 * Projects remote paths and streamed entries onto the local filesystem.
 *
 * This class owns remote-to-local path mapping and streamed entry mutations.
 * Durable pull state, the remote index, audit output, and terminal progress
 * remain caller concerns.
 */
class PulledFilesystem {
	/** @var string */
	private $filesystem_root;

	/** @var array<string,string> */
	private $resolved_path_mappings = array();

	/** @var string|null */
	private $local_followed_symlinks_root;

	/** @var string */
	private $filesystem_root_nonempty_behavior = 'error';

	/** @var list<string> */
	private $original_export_directories = array();

	/** @var list<string> */
	private $operation_messages = array();

	/**
	 * Create the filesystem projection for one immutable pull configuration.
	 *
	 * @param string               $filesystem_root             Local filesystem root.
	 * @param array<string,string> $resolved_path_mappings      Remote-to-local absolute path mappings.
	 * @param string|null          $local_followed_symlinks_root Local root for followed paths outside the original export scope.
	 * @param string               $filesystem_root_nonempty_behavior Either "error" or "preserve-local".
	 * @param list<string>         $original_export_directories Original remote roots selected for pulling.
	 */
	public function __construct(
		string $filesystem_root,
		array $resolved_path_mappings,
		?string $local_followed_symlinks_root,
		string $filesystem_root_nonempty_behavior,
		array $original_export_directories
	) {
		$filesystem_root = trim_right_slash( $filesystem_root );
		if ( ! is_dir( $filesystem_root ) ) {
			if ( ! mkdir( $filesystem_root, 0755, true ) && ! is_dir( $filesystem_root ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new RuntimeException( "Failed to create filesystem root directory: {$filesystem_root}" );
			}
		}
		$resolved_filesystem_root = realpath( $filesystem_root );
		if ( false === $resolved_filesystem_root ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
			throw new RuntimeException( "Failed to resolve filesystem root path: {$filesystem_root}" );
		}
		if ( ! in_array( $filesystem_root_nonempty_behavior, array( 'error', 'preserve-local' ), true ) ) {
			throw new InvalidArgumentException( 'Filesystem-root nonempty behavior must be "error" or "preserve-local".' );
		}

		$this->filesystem_root             = trim_right_slash( $resolved_filesystem_root );
		$this->resolved_path_mappings       = $resolved_path_mappings;
		$this->local_followed_symlinks_root = null === $local_followed_symlinks_root
			? null
			: trim_right_slash( $local_followed_symlinks_root );
		$this->filesystem_root_nonempty_behavior = $filesystem_root_nonempty_behavior;
		$this->original_export_directories   = $original_export_directories;
	}

	/** Return and clear filesystem-operation messages produced since the prior call. */
	public function drain_operation_messages(): array {
		$operation_messages       = $this->operation_messages;
		$this->operation_messages = array();
		return $operation_messages;
	}

	/** Return the resolved absolute filesystem root. */
	public function get_filesystem_root(): string {
		return $this->filesystem_root;
	}

	/** Map one remote absolute path to its local absolute path. */
	public function map_remote_absolute_path_to_local_absolute_path( string $remote_absolute_path ): string {
		assert_valid_path( $remote_absolute_path, 'remote absolute path' );
		$local_absolute_path         = null;
		$longest_remote_prefix_length = -1;
		foreach ( $this->resolved_path_mappings as $remote_prefix => $local_prefix ) {
			$remainder = path_remainder_under( $remote_absolute_path, $remote_prefix );
			if ( null !== $remainder && strlen( $remote_prefix ) > $longest_remote_prefix_length ) {
				$local_absolute_path          = wp_join_unix_paths( $local_prefix, $remainder );
				$longest_remote_prefix_length = strlen( $remote_prefix );
			}
		}
		if ( null !== $local_absolute_path ) {
			return $local_absolute_path;
		}

		if (
			null !== $this->local_followed_symlinks_root
			&& ! $this->is_within_original_export_scope( $remote_absolute_path )
		) {
			return wp_join_unix_paths( $this->local_followed_symlinks_root, $remote_absolute_path );
		}

		return wp_join_unix_paths( $this->get_filesystem_root(), $remote_absolute_path );
	}

	/** Return why a new remote path must be preserved, or null when it may be written. */
	public function preserve_local_skip_reason( string $remote_absolute_path ): ?string {
		if ( 'preserve-local' !== $this->filesystem_root_nonempty_behavior ) {
			return null;
		}

		$local_absolute_path = $this->map_remote_absolute_path_to_local_absolute_path( $remote_absolute_path );
		if ( file_exists( $local_absolute_path ) || is_link( $local_absolute_path ) ) {
			return "PRESERVE-LOCAL skip file (exists): {$remote_absolute_path}";
		}

		$parent_directory = dirname( $local_absolute_path );
		if ( is_dir( $parent_directory ) && ! is_writable( $parent_directory ) ) {
			return "PRESERVE-LOCAL skip file (dir not writable): {$remote_absolute_path}";
		}
		if ( $this->local_path_traverses_symlink( $parent_directory ) ) {
			return "PRESERVE-LOCAL skip file (symlink in path): {$remote_absolute_path}";
		}

		return null;
	}

	/**
	 * Write one streamed file chunk.
	 *
	 * @return array {
	 *     Result of the filesystem operation.
	 *
	 *     @type string|null $remote_absolute_path Decoded remote path, or null for an invalid header.
	 *     @type string|null $local_absolute_path  Mapped local absolute path, or null for an invalid header.
	 *     @type string|null $warning              Invalid-header warning.
	 *     @type array|null  $started              Remote and local size information for a first chunk.
	 *     @type string|null $skip_message         Preserve-local reason when the file was skipped.
	 *     @type bool        $completed            Whether this chunk completed the file.
	 *     @type bool        $file_changed         Whether the remote reported a mid-stream change.
	 *     @type int         $final_size           Local size after a completed write.
	 *     @type array|null  $index_entry          Remote index fields for a stable completed file.
	 * }
	 */
	public function write_local_file_chunk( array $chunk, PulledFileContext $context ): array {
		$result  = array(
			'remote_absolute_path' => null,
			'local_absolute_path'  => null,
			'warning'              => null,
			'started'              => null,
			'skip_message'         => null,
			'completed'            => false,
			'file_changed'         => false,
			'final_size'           => 0,
			'index_entry'          => null,
		);
		$headers = $chunk['headers'];
		$raw_path = $headers['x-file-path'] ?? '';
		$remote_absolute_path = base64_decode( $raw_path, true );
		$remote_file_ctime = (int) ( $headers['x-file-ctime'] ?? 0 );
		$is_first = ( $headers['x-first-chunk'] ?? '0' ) === '1';
		$is_last  = ( $headers['x-last-chunk'] ?? '0' ) === '1';

		if ( false === $remote_absolute_path || '' === $remote_absolute_path ) {
			if ( '' !== $raw_path ) {
				$result['warning'] = 'Warning: base64_decode failed for x-file-path header: ' . substr( $raw_path, 0, 100 );
			}
			return $result;
		}

		$result['remote_absolute_path'] = $remote_absolute_path;
		$local_absolute_path = $this->map_remote_absolute_path_to_local_absolute_path( $remote_absolute_path );
		$result['local_absolute_path'] = $local_absolute_path;

		if ( $is_first ) {
			$context->skip_current_file = false;
			if (
				( file_exists( $local_absolute_path ) || is_link( $local_absolute_path ) )
				&& ( ! is_file( $local_absolute_path ) || is_link( $local_absolute_path ) )
				&& ! $this->remove_local_absolute_path_without_following_symlinks( $local_absolute_path )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new RuntimeException( "Failed to replace path with file: {$remote_absolute_path}" );
			}

			$exists_locally = file_exists( $local_absolute_path );
			$result['started'] = array(
				'remote_size' => (int) ( $headers['x-file-size'] ?? 0 ),
				'ctime'       => $remote_file_ctime,
				'local_exists' => $exists_locally,
				'local_size'  => $exists_locally ? (int) filesize( $local_absolute_path ) : 0,
			);
		}

		if ( $context->skip_current_file ) {
			return $result;
		}

		if ( $is_first ) {
			if ( $context->file_handle ) {
				fclose( $context->file_handle );
				if ( $context->file_ctime && $context->file_path ) {
					touch( $context->file_path, $context->file_ctime );
				}
			}

			$parent_directory = dirname( $local_absolute_path );
			if ( ! is_dir( $parent_directory ) ) {
				try {
					$this->create_local_directory( $parent_directory );
				} catch ( PreserveLocalSkipException $error ) {
					$context->skip_current_file = true;
					$result['skip_message'] = $error->getMessage();
					return $result;
				}
			}

			$context->file_handle = fopen( $local_absolute_path, 'wb' );
			if ( ! $context->file_handle ) {
				$last_error = error_get_last();
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					"Failed to open file for writing: {$local_absolute_path}\n"
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					. "Parent directory: {$parent_directory}\n"
					. 'Directory exists: ' . ( is_dir( $parent_directory ) ? 'yes' : 'no' ) . "\n"
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					. 'Error: ' . ( $last_error['message'] ?? 'unknown' )
				);
			}
			$context->file_path          = $local_absolute_path;
			$context->file_ctime         = $remote_file_ctime;
			$context->file_bytes_written = 0;
		}

		// A resumed files-pull reopens the tracked local file without the remote
		// ctime. Each continuation part repeats that ctime.
		if (
			! $is_first
			&& null === $context->file_ctime
			&& $context->file_handle
			&& $context->file_path === $local_absolute_path
		) {
			$context->file_ctime = $remote_file_ctime;
		}

		if ( isset( $chunk['body'] ) && '' !== $chunk['body'] && $context->file_handle ) {
			$data          = $chunk['body'];
			$bytes_written = fwrite( $context->file_handle, $data );
			if ( false === $bytes_written || $bytes_written !== strlen( $data ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					"Write failed for {$context->file_path}: wrote "
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					. ( false === $bytes_written ? '0' : $bytes_written ) . '/' . strlen( $data )
					. ' bytes (disk full?)'
				);
			}
			$context->file_bytes_written += $bytes_written;
		}

		if ( $is_last && $context->file_handle ) {
			fclose( $context->file_handle );
			if ( $context->file_ctime && $context->file_path ) {
				touch( $context->file_path, $context->file_ctime );
			}

			$result['completed']    = true;
			$result['file_changed'] = ( $headers['x-file-changed'] ?? '0' ) === '1';
			$result['final_size']   = file_exists( $context->file_path ) ? (int) filesize( $context->file_path ) : 0;
			if ( $context->file_ctime && ! $result['file_changed'] ) {
				$result['index_entry'] = array(
					'path'  => $remote_absolute_path,
					'ctime' => $context->file_ctime,
					'size'  => (int) ( $headers['x-file-size'] ?? 0 ),
					'type'  => 'file',
				);
			}

			$context->file_handle          = null;
			$context->file_path            = null;
			$context->file_ctime           = null;
			$context->file_bytes_written   = 0;
		}

		return $result;
	}

	/**
	 * Materialize one remote directory in the local filesystem.
	 *
	 * @return array {
	 *     Result of the directory operation.
	 *
	 *     @type string|null $remote_absolute_path Decoded remote path.
	 *     @type string|null $local_absolute_path  Mapped local absolute path.
	 *     @type string|null $warning              Invalid-header warning.
	 *     @type string|null $skip_message         Preserve-local reason.
	 *     @type int         $ctime                Remote ctime.
	 * }
	 */
	public function create_local_directory_from_remote_directory_part( array $part ): array {
		$headers = $part['headers'];
		$raw_path = $headers['x-directory-path'] ?? '';
		$remote_absolute_path = base64_decode( $raw_path, true );
		$ctime = (int) ( $headers['x-directory-ctime'] ?? 0 );
		$result = array(
			'remote_absolute_path' => null,
			'local_absolute_path'  => null,
			'warning'              => null,
			'skip_message'         => null,
			'ctime'                => $ctime,
		);

		if ( false === $remote_absolute_path || '' === $remote_absolute_path ) {
			if ( '' !== $raw_path ) {
				$result['warning'] = 'Warning: base64_decode failed for x-directory-path header: ' . substr( $raw_path, 0, 100 );
			}
			return $result;
		}

		$result['remote_absolute_path'] = $remote_absolute_path;
		$local_absolute_path = $this->map_remote_absolute_path_to_local_absolute_path( $remote_absolute_path );
		$result['local_absolute_path'] = $local_absolute_path;
		if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior ) {
			if ( is_dir( $local_absolute_path ) ) {
				$result['skip_message'] = "PRESERVE-LOCAL skip directory (exists): {$remote_absolute_path}";
				return $result;
			}
			if ( $this->local_path_traverses_symlink( $local_absolute_path ) ) {
				$result['skip_message'] = "PRESERVE-LOCAL skip directory (symlink in path): {$remote_absolute_path}";
				return $result;
			}
		}

		if (
			( file_exists( $local_absolute_path ) || is_link( $local_absolute_path ) )
			&& ( ! is_dir( $local_absolute_path ) || is_link( $local_absolute_path ) )
			&& ! $this->remove_local_absolute_path_without_following_symlinks( $local_absolute_path )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
			throw new RuntimeException( "Failed to replace path with directory: {$remote_absolute_path}" );
		}

		try {
			$this->create_local_directory( $local_absolute_path );
		} catch ( PreserveLocalSkipException $error ) {
			$result['skip_message'] = $error->getMessage();
		}

		return $result;
	}

	/**
	 * Materialize one remote symlink in the local filesystem.
	 *
	 * @return array {
	 *     Result of the symlink operation.
	 *
	 *     @type string|null $remote_absolute_path Decoded remote path.
	 *     @type string|null $local_absolute_path  Mapped local absolute path.
	 *     @type string|null $remote_target        Original decoded target.
	 *     @type string|null $local_target         Target used for the local symlink.
	 *     @type string|null $warning              Invalid-header warning.
	 *     @type string|null $skip_message         Preserve-local reason.
	 *     @type string|null $error                Non-fatal creation error.
	 *     @type int         $ctime                Remote ctime.
	 * }
	 */
	public function create_local_symlink_from_remote_symlink_part( array $part, string $local_target ): array {
		$headers = $part['headers'];
		$raw_path = $headers['x-symlink-path'] ?? '';
		$remote_absolute_path = base64_decode( $raw_path, true );
		$remote_target = base64_decode( $headers['x-symlink-target'] ?? '', true );
		$result = array(
			'remote_absolute_path' => null,
			'local_absolute_path'  => null,
			'remote_target'        => false === $remote_target ? null : $remote_target,
			'local_target'         => $local_target,
			'warning'              => null,
			'skip_message'         => null,
			'error'                => null,
			'ctime'                => (int) ( $headers['x-symlink-ctime'] ?? 0 ),
		);

		if ( false === $remote_absolute_path || '' === $remote_absolute_path || false === $remote_target || '' === $remote_target ) {
			if ( '' !== $raw_path && ( false === $remote_absolute_path || '' === $remote_absolute_path ) ) {
				$result['warning'] = 'Warning: base64_decode failed for x-symlink-path header: ' . substr( $raw_path, 0, 100 );
			}
			return $result;
		}

		$result['remote_absolute_path'] = $remote_absolute_path;
		$local_absolute_path = $this->map_remote_absolute_path_to_local_absolute_path( $remote_absolute_path );
		$result['local_absolute_path'] = $local_absolute_path;
		if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior ) {
			if ( file_exists( $local_absolute_path ) || is_link( $local_absolute_path ) ) {
				$result['skip_message'] = "PRESERVE-LOCAL skip symlink (path exists): {$remote_absolute_path} -> {$remote_target}";
				return $result;
			}
			if ( $this->local_path_traverses_symlink( dirname( $local_absolute_path ) ) ) {
				$result['skip_message'] = "PRESERVE-LOCAL skip symlink (symlink in path): {$remote_absolute_path} -> {$remote_target}";
				return $result;
			}
		}

		try {
			$this->assert_local_symlink_target_within_filesystem_root( dirname( $local_absolute_path ), $local_target );
		} catch ( RuntimeException $error ) {
			$result['error'] = $error->getMessage();
			return $result;
		}

		if ( file_exists( $local_absolute_path ) || is_link( $local_absolute_path ) ) {
			if ( ! $this->remove_local_absolute_path_without_following_symlinks( $local_absolute_path ) ) {
				$result['error'] = 'Failed to replace existing path';
				return $result;
			}
		}

		$parent_directory = dirname( $local_absolute_path );
		if ( ! is_dir( $parent_directory ) ) {
			try {
				$this->create_local_directory( $parent_directory );
			} catch ( PreserveLocalSkipException $error ) {
				$result['skip_message'] = $error->getMessage();
				return $result;
			} catch ( RuntimeException $error ) {
				$result['error'] = 'Failed to create parent directory';
				return $result;
			}
		}

		if ( true !== symlink( $local_target, $local_absolute_path ) || ! is_link( $local_absolute_path ) ) {
			$result['error'] = 'Failed to create symlink';
			return $result;
		}
		if ( $result['ctime'] > 0 ) {
			@touch( $local_absolute_path, $result['ctime'] );
		}

		return $result;
	}

	/** Remove a local absolute path recursively without following symlink targets. */
	public function remove_local_absolute_path_without_following_symlinks( string $local_absolute_path ): bool {
		if ( ! file_exists( $local_absolute_path ) && ! is_link( $local_absolute_path ) ) {
			return true;
		}
		if ( is_link( $local_absolute_path ) || is_file( $local_absolute_path ) ) {
			return true === @unlink( $local_absolute_path );
		}
		if ( is_dir( $local_absolute_path ) ) {
			$entries = @scandir( $local_absolute_path );
			if ( false === $entries ) {
				return false;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if (
					! $this->remove_local_absolute_path_without_following_symlinks(
						wp_join_unix_paths( $local_absolute_path, $entry )
					)
				) {
					return false;
				}
			}
			return true === @rmdir( $local_absolute_path );
		}
		return true === @unlink( $local_absolute_path );
	}

	/** Create a local directory path, removing blockers without traversing symlinks. */
	public function create_local_directory( string $local_directory ): void {
		$filesystem_root = $this->get_filesystem_root();
		$resolved_local_directory = realpath_with_missing_tail( $local_directory );
		if ( ! path_is_same_as_or_descendant_of( $resolved_local_directory, $filesystem_root ) ) {
			if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new PreserveLocalSkipException( "PRESERVE-LOCAL: path resolves outside filesystem root via symlink: {$local_directory}" );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
			throw new RuntimeException( "Security: Refusing to create directory outside filesystem root: {$local_directory}" );
		}

		if ( is_dir( $local_directory ) && ! is_link( $local_directory ) ) {
			if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior && ! is_writable( $local_directory ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new PreserveLocalSkipException( "PRESERVE-LOCAL: directory not writable: {$local_directory}" );
			}
			return;
		}

		$local_relative_path = relative_path_under( $local_directory, $filesystem_root );
		if ( null === $local_relative_path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
			throw new RuntimeException( "Security: Refusing to create directory outside filesystem root: {$local_directory}" );
		}
		if ( '' === $local_relative_path ) {
			return;
		}

		$current_local_path = $filesystem_root;
		foreach ( explode( '/', $local_relative_path ) as $path_component ) {
			if ( '' === $path_component ) {
				continue;
			}
			$current_local_path = wp_join_unix_paths( $current_local_path, $path_component );
			if ( is_link( $current_local_path ) ) {
				if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					throw new PreserveLocalSkipException( "PRESERVE-LOCAL: symlink in directory path: {$current_local_path}" );
				}
				if ( ! unlink( $current_local_path ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					throw new RuntimeException( "Failed to remove symlink blocking directory: {$current_local_path}" );
				}
				$this->operation_messages[] = "Removing symlink blocking directory: {$current_local_path}";
				clearstatcache( true, $current_local_path );
			}
			if ( is_file( $current_local_path ) ) {
				if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					throw new PreserveLocalSkipException( "PRESERVE-LOCAL: file blocks directory creation: {$current_local_path}" );
				}
				if ( ! unlink( $current_local_path ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					throw new RuntimeException( "Failed to remove file blocking directory: {$current_local_path}" );
				}
				$this->operation_messages[] = "Removing file blocking directory: {$current_local_path}";
			}
			if ( is_dir( $current_local_path ) ) {
				if ( 'preserve-local' === $this->filesystem_root_nonempty_behavior && ! is_writable( $current_local_path ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
					throw new PreserveLocalSkipException( "PRESERVE-LOCAL: directory not writable: {$current_local_path}" );
				}
			} elseif ( ! mkdir( $current_local_path, 0755 ) && ! is_dir( $current_local_path ) ) {
				$last_error = error_get_last();
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new RuntimeException( "Failed to create directory: {$current_local_path}\nError: " . ( $last_error['message'] ?? 'unknown' ) );
			}

			$resolved_local_path = realpath( $current_local_path );
			if ( false === $resolved_local_path || ! path_is_same_as_or_descendant_of( $resolved_local_path, $filesystem_root ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				throw new RuntimeException( "Security: Refusing to create directory outside filesystem root: {$current_local_path}" );
			}
		}
	}

	/** Assert that a local symlink target remains inside the filesystem root. */
	public function assert_local_symlink_target_within_filesystem_root(
		string $local_symlink_parent_directory,
		string $local_target
	): void {
		$resolved_local_target = str_starts_with( $local_target, '/' )
			? normalize_path( $local_target )
			: normalize_path( wp_join_unix_paths( $local_symlink_parent_directory, $local_target ) );
		$filesystem_root = $this->get_filesystem_root();
		if ( ! path_is_same_as_or_descendant_of( $resolved_local_target, $filesystem_root ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				"Security: symlink target escapes filesystem root: {$local_target} "
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception, not HTML output.
				. "(resolves to {$resolved_local_target}, root is {$filesystem_root})"
			);
		}
	}

	private function local_path_traverses_symlink( string $local_absolute_path ): bool {
		$filesystem_root = $this->get_filesystem_root();
		$local_relative_path = relative_path_under( $local_absolute_path, $filesystem_root );
		if ( null === $local_relative_path || '' === $local_relative_path ) {
			return false;
		}
		$current_local_path = $filesystem_root;
		foreach ( explode( '/', $local_relative_path ) as $path_component ) {
			if ( '' === $path_component ) {
				continue;
			}
			$current_local_path = wp_join_unix_paths( $current_local_path, $path_component );
			if ( is_link( $current_local_path ) ) {
				return true;
			}
			if ( ! file_exists( $current_local_path ) ) {
				break;
			}
		}
		return false;
	}

	private function is_within_original_export_scope( string $remote_absolute_path ): bool {
		foreach ( $this->original_export_directories as $remote_root ) {
			if ( path_is_same_as_or_descendant_of( $remote_absolute_path, $remote_root ) ) {
				return true;
			}
		}
		return false;
	}
}
