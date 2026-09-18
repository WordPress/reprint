<?php

namespace Reprint\Importer;

use ImportClient;
use ReprintProcessLock;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../recover/functions.php';

const POST_PROCESS_TASKS = array( 'disable-hosting-plugins', 'remove-reprint', 'disable-failing-plugins' );

/**
 * Run selected local tasks, stopping at the first failure. Hosting runs first.
 *
 * @param string      $wordpress_root         Local WordPress root containing wp-load.php.
 * @param string      $tasks                  Comma-separated task names, or all.
 * @param string|null $state_directory        Saved migration state; required for hosting or Reprint cleanup.
 * @param string|null $remote_reprint_api_url Source URL selecting a saved remote, never contacted here.
 * @return array {
 *     @type string $status  Complete or failed.
 *     @type array  $results Task results in execution order. Each has task and status;
 *                           file cleanup has removed_paths, startup recovery has
 *                           the run_recover() fields. Failed tasks include message.
 *     @type string $message Reason processing stopped, present on failure.
 * }
 */
function run_post_process( string $wordpress_root, string $tasks = 'all', ?string $state_directory = null, ?string $remote_reprint_api_url = null ): array {
	$results      = array();
	$process_lock = null;
	$current_task = null;
	$client       = null;
	try {
		$selected_tasks = 'all' === $tasks ? POST_PROCESS_TASKS : explode( ',', $tasks );
		foreach ( $selected_tasks as $task ) {
			if ( ! in_array( $task, POST_PROCESS_TASKS, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI option error, not HTML.
				throw new RuntimeException( 'Unknown post-process task "' . $task . '". Use all or ' . implode( ', ', POST_PROCESS_TASKS ) . '.' );
			}
		}
		if ( ! is_file( $wordpress_root . '/wp-load.php' ) ) {
			throw new RuntimeException( 'post-process requires --fs-root=WORDPRESS_ROOT containing wp-load.php.' );
		}
		if ( in_array( 'disable-hosting-plugins', $selected_tasks, true ) || in_array( 'remove-reprint', $selected_tasks, true ) ) {
			if ( null === $state_directory || ! is_dir( $state_directory ) ) {
				throw new RuntimeException( 'disable-hosting-plugins and remove-reprint require --state-dir pointing to saved migration state.' );
			}
			$process_lock = new ReprintProcessLock( $state_directory );
			if ( null !== $remote_reprint_api_url ) {
				$remote_directory = ImportClient::remote_state_directory_path( $remote_reprint_api_url, $state_directory );
			} else {
				$saved_states = glob( $state_directory . '/remotes/*/pull/state.json' );
				$saved_states = false === $saved_states ? array() : array_values( array_filter( $saved_states, 'is_file' ) );
				if ( count( $saved_states ) > 1 ) {
					throw new RuntimeException( '--state-dir contains more than one saved remote. Provide <remote-reprint-api-url> to select one.' );
				}
				if ( array() === $saved_states ) {
					throw new RuntimeException( 'No saved migration state found in --state-dir.' );
				}
				$remote_directory = dirname( $saved_states[0], 2 );
			}
			if ( ! is_file( $remote_directory . '/pull/state.json' ) ) {
				throw new RuntimeException( 'No saved migration state found for the selected source URL.' );
			}
			$client = new ImportClient( $remote_reprint_api_url ?? '', $state_directory, $wordpress_root, 'post-process', $remote_directory );
		}
		if ( in_array( 'disable-hosting-plugins', $selected_tasks, true ) ) {
			$current_task = 'disable-hosting-plugins';
			$results[]    = array(
				'task'          => $current_task,
				'status'        => 'complete',
				'removed_paths' => $client->run_disable_hosting_plugins( $wordpress_root ),
			);
			$current_task = null;
		}
		if ( in_array( 'remove-reprint', $selected_tasks, true ) ) {
			$current_task = 'remove-reprint';
			$results[]    = array(
				'task'          => $current_task,
				'status'        => 'complete',
				'removed_paths' => $client->run_remove_reprint( $wordpress_root ),
			);
		}
		if ( in_array( 'disable-failing-plugins', $selected_tasks, true ) ) {
			$current_task = 'disable-failing-plugins';
			$result       = run_recover( $wordpress_root );
			$results[]    = array_merge( array( 'task' => $current_task ), $result );
			if ( 'failed' === $result['status'] ) {
				return array( 'status' => 'failed', 'results' => $results, 'message' => $result['message'] );
			}
		}
		return array( 'status' => 'complete', 'results' => $results );
	} catch ( Throwable $error ) {
		if ( null !== $current_task ) {
			$results[] = array( 'task' => $current_task, 'status' => 'failed', 'message' => $error->getMessage() );
		}
		return array( 'status' => 'failed', 'results' => $results, 'message' => $error->getMessage() );
	} finally {
		if ( null !== $process_lock ) {
			$process_lock->close();
		}
	}
}
