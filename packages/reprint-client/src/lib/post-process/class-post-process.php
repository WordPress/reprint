<?php

namespace Reprint\Importer;

use ImportClient;
use PDO;
use Reprint\Importer\Database\DatabaseConnection;
use ReprintProcessLock;
use RuntimeException;
use Throwable;

/** Local post-migration tasks. Loading the class does not start a task. */
final class PostProcess {
    public const TASKS = array( 'disable-hosting-plugins', 'remove-reprint', 'disable-failing-plugins' );

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
     *                           the disable_plugins_that_prevent_wordpress_from_loading() fields.
     *                           Failed tasks include message.
     *     @type string $message Reason processing stopped, present on failure.
     * }
     */
    public static function run_selected_tasks( string $wordpress_root, string $tasks = 'all', ?string $state_directory = null, ?string $remote_reprint_api_url = null ): array {
        $results      = array();
        $process_lock = null;
        $current_task = null;
        $client       = null;
        try {
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
                    'removed_paths' => $client->remove_local_hosting_plugin_files( $wordpress_root ),
                );
                $current_task = null;
            }
            if ( in_array( 'remove-reprint', $selected_tasks, true ) ) {
                $current_task = 'remove-reprint';
                $results[]    = array(
                    'task'          => $current_task,
                    'status'        => 'complete',
                    'removed_paths' => $client->remove_imported_reprint_plugin_files_and_data( $wordpress_root ),
                );
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

    /**
     * Remove the imported Reprint activation entries and connection options.
     *
     * Every update can repeat after an interrupted cleanup. The downloaded SQL
     * stays unchanged, and no plugin deactivation or uninstall hooks run.
     *
     * @param DatabaseConnection $database        Open target connection.
     * @param string             $engine          Target engine: mysql or sqlite.
     * @param string             $plugin_basename  Exact Reprint plugin basename reported by preflight.
     * @param array              $wordpress_database {
     *     Saved source WordPress database settings.
     *
     *     @type string $table_prefix Source site table prefix.
     *     @type string $wpdb_charset Source connection charset, when reported.
     *     @type array  $multisite    Optional selected-site metadata. Its selection
     *                               contains base_prefix, site_id and network_id.
     * }
     */
    public static function remove_reprint_plugin_data_from_the_imported_database(DatabaseConnection $database, string $engine, string $plugin_basename, array $wordpress_database): void
    {
        $network = $wordpress_database['multisite']['selection'] ?? null;
        $site_prefix = $network === null ? ( $wordpress_database['table_prefix'] ?? null )
            : $network['base_prefix'] . ( $network['site_id'] === 1 ? '' : $network['site_id'] . '_' );
        if (!is_string($site_prefix) || $site_prefix === '') {
            throw new RuntimeException('Reprint database cleanup requires the source WordPress table prefix.');
        }
        $activation_options = [[$site_prefix . 'options', 'option_name', 'option_value', 'active_plugins', null]];
        if ($network !== null) {
            $activation_options[] = [$network['base_prefix'] . 'sitemeta', 'meta_key', 'meta_value', 'active_sitewide_plugins', $network['network_id']];
        }
        // Serialized lengths describe the bytes WordPress sent, which need
        // not use the column's charset. Without a declared charset, try raw
        // bytes and still require an exact round trip before editing them.
        $wordpress_charset = $wordpress_database['wpdb_charset'] ?? 'binary';
        $quoted_wordpress_charset = '`' . str_replace('`', '``', $wordpress_charset === '' ? 'binary' : $wordpress_charset) . '`';
        if (!$database->inTransaction()) {
            $database->beginTransaction();
        }
        foreach ($activation_options as [$table, $name_column, $value_column, $option_name, $network_id]) {
            $quoted_table = '`' . str_replace('`', '``', $table) . '`';
            $where = "`{$name_column}` = ?";
            $params = [$option_name];
            if ($network_id !== null) {
                $where .= ' AND site_id = ?';
                $params[] = $network_id;
            }
            $read_expression = "`{$value_column}` AS serialized_value";
            if ($engine === 'mysql') {
                $read_expression = "CAST(CONVERT(`{$value_column}` USING {$quoted_wordpress_charset}) AS BINARY) AS serialized_value, " .
                    "CAST(`{$value_column}` AS BINARY) AS stored_value, CHARSET(`{$value_column}`) AS storage_charset";
            }
            $result = $database->query("SELECT {$read_expression} FROM {$quoted_table} WHERE {$where} LIMIT 1", $params);
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $result->closeCursor();
            if ($row === false) {
                continue;
            }
            $serialized = $row['serialized_value'];
            $plugins = @unserialize($serialized, ['allowed_classes' => false]);
            if (!is_array($plugins) || serialize($plugins) !== $serialized) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed option name in a CLI error.
                throw new RuntimeException('The imported ' . $option_name . ' does not round-trip as a serialized plugin array. Refusing to finish cleanup.');
            }
            $write_expression = 'FROM_BASE64(?)';
            if ($engine === 'mysql') {
                $quoted_storage_charset = '`' . str_replace('`', '``', $row['storage_charset']) . '`';
                $write_expression = "CONVERT(CONVERT(FROM_BASE64(?) USING {$quoted_wordpress_charset}) USING {$quoted_storage_charset})";
                $result = $database->query("SELECT CAST({$write_expression} AS BINARY)", [base64_encode($serialized)]);
                $round_trip = $result->fetchColumn();
                $result->closeCursor();
                if ($round_trip !== $row['stored_value']) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed option name in a CLI error.
                    throw new RuntimeException('The imported ' . $option_name . ' cannot round-trip through the WordPress database charset without changing stored bytes. Refusing to finish cleanup.');
                }
            }
            if ($network_id === null) {
                $plugins = array_values(array_filter($plugins, static function ($active_plugin_basename) use ($plugin_basename) {
                    return $active_plugin_basename !== $plugin_basename;
                }));
            } else {
                unset($plugins[$plugin_basename]);
            }
            $replacement = serialize($plugins);
            if ($replacement !== $serialized) {
                $database->execute("UPDATE {$quoted_table} SET `{$value_column}` = {$write_expression} WHERE {$where}",
                    array_merge([base64_encode($replacement)], $params));
            }
        }
        $connection_options = [
            'reprint_server_connection_token', 'reprint_server_push_authorized_token_fingerprint',
            'site_export_secret', 'site_export_push_authorized_token_fingerprint',
        ];
        $placeholders = implode(', ', array_fill(0, count($connection_options), '?'));
        $options_table = '`' . str_replace('`', '``', $site_prefix . 'options') . '`';
        $database->execute("DELETE FROM {$options_table} WHERE option_name IN ({$placeholders})", $connection_options);
        if ($network !== null) {
            // Reprint uses site options on multisite, which WordPress stores in
            // sitemeta. Only the selected network's copied credentials belong here.
            $network_table = '`' . str_replace('`', '``', $network['base_prefix'] . 'sitemeta') . '`';
            $database->execute("DELETE FROM {$network_table} WHERE site_id = ? AND meta_key IN ({$placeholders})",
                array_merge([$network['network_id']], $connection_options));
        }
        $database->commit();
    }
}
