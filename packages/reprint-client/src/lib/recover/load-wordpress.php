<?php
/**
 * Recovery child process. Load WordPress at global scope, as wp-config expects.
 * The result file keeps plugin output separate from the load result.
 */

$reprint_recover_result_file = $argv[2];

register_shutdown_function(
	static function () use ( $reprint_recover_result_file ): void {
		$error = error_get_last();
		if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$result = array(
			'status'  => 'failed',
			'message' => 'WordPress failed outside an identifiable active regular plugin.',
			'error'   => $error,
		);
		// Preserve the original fatal even if reading or updating options fails.
		file_put_contents( $reprint_recover_result_file, json_encode( $result, JSON_INVALID_UTF8_SUBSTITUTE ) );
		if ( defined( 'WP_PLUGIN_DIR' ) && function_exists( 'get_option' ) && function_exists( 'update_option' ) && function_exists( 'is_multisite' ) && ! is_multisite() ) {
			$active_plugins   = get_option( 'active_plugins', array() );
			$matching_plugins = array();
			$error_file       = realpath( $error['file'] );
			foreach ( $active_plugins as $plugin ) {
				$plugin_parts = explode( '/', $plugin );
				$plugin_root  = realpath( WP_PLUGIN_DIR . '/' . $plugin_parts[0] );
				if ( false === $error_file || false === $plugin_root ) {
					continue;
				}
				if ( count( $plugin_parts ) === 1 ? $error_file === $plugin_root : strpos( $error_file, $plugin_root . DIRECTORY_SEPARATOR ) === 0 ) {
					$matching_plugins[] = $plugin;
				}
			}
			if ( count( $matching_plugins ) === 1 ) {
				$plugin            = $matching_plugins[0];
				$result['message'] = 'Could not deactivate plugin ' . $plugin . '.';
				file_put_contents( $reprint_recover_result_file, json_encode( $result, JSON_INVALID_UTF8_SUBSTITUTE ) );
				// Update activation and its caches through WordPress, but do not
				// call the failed plugin's deactivation hooks.
				if ( update_option( 'active_plugins', array_values( array_diff( $active_plugins, array( $plugin ) ) ) ) ) {
					$result['status']  = 'disabled';
					$result['plugin']  = $plugin;
					$result['message'] = 'Deactivated plugin ' . $plugin . '.';
				}
			}
		}
		file_put_contents( $reprint_recover_result_file, json_encode( $result, JSON_INVALID_UTF8_SUBSTITUTE ) );
		// Do not send recovery emails or run more WordPress shutdown callbacks
		// after handling this failed load. The parent starts a fresh process.
		exit( 1 );
	}
);

require $argv[1] . '/wp-load.php';

file_put_contents(
	$reprint_recover_result_file,
	json_encode( array( 'status' => 'complete', 'message' => 'wp-load.php loaded successfully.' ) )
);
