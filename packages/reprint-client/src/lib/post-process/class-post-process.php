<?php

namespace Reprint\Importer;

use ImportClient;
use ReprintProcessLock;
use RuntimeException;
use Throwable;

/** Local post-migration tasks. Loading the class does not start a task. */
final class PostProcess {
    public const TASKS = array( 'disable-hosting-plugins', 'disable-failing-plugins' );

    /**
     * Run selected local tasks, stopping at the first failure. Hosting runs first.
     *
     * @param string      $wordpress_root         Local WordPress root containing wp-load.php.
     * @param string      $tasks                  Comma-separated task names, or all.
     * @param string|null $state_directory        Saved migration state; required for hosting cleanup.
     * @param string|null $remote_reprint_api_url Source URL selecting a saved remote, never contacted here.
     * @param bool        $allow_http             Whether an explicit HTTP source URL is allowed.
     * @return array {
     *     @type string $status  Complete or failed.
     *     @type array  $results Task results in execution order. Each has task and status;
     *                           file cleanup has removed_paths, startup recovery has
     *                           the disable_plugins_that_prevent_wordpress_from_loading() fields.
     *                           Failed tasks include message.
     *     @type string $message Reason processing stopped, present on failure.
     * }
     */
    public static function run_selected_tasks( string $wordpress_root, string $tasks = 'all', ?string $state_directory = null, ?string $remote_reprint_api_url = null, bool $allow_http = false ): array {
        $results      = array();
        $process_lock = null;
        $current_task = null;
        $client       = null;
        try {
            ImportClient::validate_remote_reprint_api_url_transport( $remote_reprint_api_url ?? '', $allow_http );
            $selected_tasks = 'all' === $tasks ? self::TASKS : explode( ',', $tasks );
            foreach ( $selected_tasks as $task ) {
                if ( ! in_array( $task, self::TASKS, true ) ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI option error, not HTML.
                    throw new RuntimeException( 'Unknown post-process task "' . $task . '". Use all or ' . implode( ', ', self::TASKS ) . '.' );
                }
            }
            if ( ! is_file( $wordpress_root . '/wp-load.php' ) ) {
                throw new RuntimeException( 'post-process requires --fs-root=WORDPRESS_ROOT containing wp-load.php.' );
            }
            if ( in_array( 'disable-hosting-plugins', $selected_tasks, true ) ) {
                if ( null === $state_directory || ! is_dir( $state_directory ) ) {
                    throw new RuntimeException( 'disable-hosting-plugins requires --state-dir pointing to saved migration state.' );
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
                $client = new ImportClient(
                    $remote_reprint_api_url ?? '',
                    $state_directory,
                    $wordpress_root,
                    array(
                        'allow_http'                      => $allow_http,
                        'signal_handling_command'         => 'post-process',
                        'selected_remote_state_directory' => $remote_directory,
                    )
                );
            }
            if ( in_array( 'disable-hosting-plugins', $selected_tasks, true ) ) {
                $current_task = 'disable-hosting-plugins';
                $results[]    = array(
                    'task'          => $current_task,
                    'status'        => 'complete',
                    'removed_paths' => $client->remove_local_hosting_plugin_files( $wordpress_root ),
                );
                $current_task = null;
            }
            if ( in_array( 'disable-failing-plugins', $selected_tasks, true ) ) {
                $current_task = 'disable-failing-plugins';
                $result       = self::disable_plugins_that_prevent_wordpress_from_loading( $wordpress_root );
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
    public static function disable_plugins_that_prevent_wordpress_from_loading( string $wordpress_root ): array {
        if ( ! is_file( $wordpress_root . '/wp-load.php' ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI error, not HTML.
            throw new RuntimeException( 'No wp-load.php found in ' . $wordpress_root . '.' );
        }
        $result_file = tempnam( sys_get_temp_dir(), 'reprint-recover-' );
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
                    array( PHP_BINARY, '-r', 'array_shift($argv); require $argv[0];', '--', __DIR__ . '/../recover/load-wordpress.php', $wordpress_root, $result_file ),
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
}
