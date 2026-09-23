<?php
/** Remove stored plugin settings only when WordPress uninstalls the plugin. */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Do not load lib.php: uninstall must not migrate old settings or route requests.
$reprint_server_delete_site_settings = static function () {
    delete_option('reprint_server_connection_token');
    delete_option('reprint_server_push_authorized_token_fingerprint');
    delete_option('reprint_server_public_keys');
    delete_option('site_export_secret');
    delete_option('site_export_push_authorized_token_fingerprint');
    delete_transient('reprint_server_activated');
    delete_transient('site_export_activated');
};

if (!is_multisite()) {
    $reprint_server_delete_site_settings();
    return;
}

// Plugin files are shared by every site and network. Read IDs in small batches.
$reprint_server_offset = 0;
do {
    $reprint_server_site_ids = get_sites([
        'fields' => 'ids', 'number' => 100, 'offset' => $reprint_server_offset,
        'orderby' => 'id', 'order' => 'ASC',
    ]);
    foreach ($reprint_server_site_ids as $reprint_server_site_id) {
        switch_to_blog($reprint_server_site_id);
        $reprint_server_delete_site_settings();
        restore_current_blog();
    }
    $reprint_server_batch_count = count($reprint_server_site_ids);
    $reprint_server_offset += $reprint_server_batch_count;
} while ($reprint_server_batch_count === 100);

$reprint_server_offset = 0;
do {
    $reprint_server_network_ids = get_networks([
        'fields' => 'ids', 'number' => 100, 'offset' => $reprint_server_offset,
        'orderby' => 'id', 'order' => 'ASC',
    ]);
    foreach ($reprint_server_network_ids as $reprint_server_network_id) {
        delete_network_option($reprint_server_network_id, 'reprint_server_connection_token');
        delete_network_option($reprint_server_network_id, 'reprint_server_public_keys');
    }
    $reprint_server_batch_count = count($reprint_server_network_ids);
    $reprint_server_offset += $reprint_server_batch_count;
} while ($reprint_server_batch_count === 100);
