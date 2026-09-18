<?php

namespace Reprint\Importer;

use PDO;
use RuntimeException;
use Reprint\Importer\Database\DatabaseConnection;

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
function remove_reprint_plugin_data_from_the_imported_database(DatabaseConnection $database, string $engine, string $plugin_basename, array $wordpress_database): void
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
