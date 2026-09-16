<?php

namespace Reprint\Importer;

use RuntimeException;

/**
 * Load WordPress in fresh PHP processes until it loads or cannot be repaired.
 *
 * @param string $wordpress_root Local absolute WordPress root containing wp-load.php.
 * @return array {
 *     @type string $status           Complete or failed.
 *     @type string $message          Load result or reason the command stopped.
 *     @type array  $disabled_plugins List of records with a string plugin basename
 *                                    and an error array with the fields below.
 *     @type array  $error {
 *         Last fatal error, present when loading failed with a fatal.
 *
 *         @type int    $type    PHP error type.
 *         @type string $message PHP error message.
 *         @type string $file    File where the error occurred.
 *         @type int    $line    Line where the error occurred.
 *     }
 * }
 */
function run_doctor( string $wordpress_root ): array {
	if ( ! is_file( $wordpress_root . '/wp-load.php' ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI error, not HTML.
		throw new RuntimeException( 'No wp-load.php found in ' . $wordpress_root . '.' );
	}
	$result_file = tempnam( sys_get_temp_dir(), 'reprint-doctor-' );
	if ( false === $result_file ) {
		throw new RuntimeException( 'Could not create the WordPress load result file.' );
	}
	$disabled_plugins = array();
	try {
		while ( true ) {
			file_put_contents( $result_file, '' );
			// A separate process keeps WordPress away from the importer's function
			// stubs and lets the next attempt load without the failed plugin.
			// Requiring the worker through -r also works from inside the PHAR.
			$process = proc_open(
				array( PHP_BINARY, '-r', 'array_shift($argv); require $argv[0];', '--', __DIR__ . '/load-wordpress.php', $wordpress_root, $result_file ),
				array( 0 => STDIN, 1 => STDERR, 2 => STDERR ),
				$pipes,
				$wordpress_root
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start PHP to load WordPress.' );
			}
			$exit_code = proc_close( $process );
			$result    = json_decode( (string) file_get_contents( $result_file ), true );
			if ( ! is_array( $result ) ) {
				$result = array( 'status' => 'failed', 'message' => 'PHP stopped before wp-load.php returned; exit code ' . $exit_code . '.' );
			}
			if ( 'disabled' === $result['status'] ) {
				$plugin = $result['plugin'];
				if ( isset( $disabled_plugins[ $plugin ] ) ) {
					$result['status']  = 'failed';
					$result['message'] = 'Plugin ' . $plugin . ' failed again after deactivation.';
				} else {
					$disabled_plugins[ $plugin ] = array( 'plugin' => $plugin, 'error' => $result['error'] );
					continue;
				}
			}
			if ( 'complete' === $result['status'] && 0 !== $exit_code ) {
				$result['status']  = 'failed';
				$result['message'] = 'wp-load.php returned, but PHP exited with code ' . $exit_code . '. See stderr.';
			}
			$result['disabled_plugins'] = array_values( $disabled_plugins );
			unset( $result['plugin'] );
			return $result;
		}
	} finally {
		unlink( $result_file );
	}
}
