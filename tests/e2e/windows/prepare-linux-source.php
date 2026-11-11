<?php
/** Installs WordPress in the linked Linux source and enrolls a client key. */

if (PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('The source must run Linux PHP.');
}
[$script, $document_root, $private_key_path, $site_url] = $argv;

$plugin_directory = $document_root . '/wp-content/plugins/reprint-server';
require $plugin_directory . '/vendor/autoload.php';
[$private_key_pem, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
file_put_contents($plugin_directory . '/public-keys.php', "<?php return [\n    " . var_export($public_key, true) . ",\n];\n");
file_put_contents($private_key_path, $private_key_pem);

define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = parse_url($site_url, PHP_URL_HOST) . ':' . parse_url($site_url, PHP_URL_PORT);
require $document_root . '/wp-config.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_install('Linked Linux source', 'source', 'source@example.test', true, '', 'source-password');
update_option('home', $site_url);
update_option('siteurl', $site_url);
foreach (['reprint-server/index.php', 'managed-plugin/managed-plugin.php'] as $plugin) {
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        throw new RuntimeException($plugin . ': ' . $result->get_error_message());
    }
}
$post_id = wp_insert_post([
    'post_title' => 'Linked Linux source post',
    'post_status' => 'publish',
    'post_content' => 'Pulled to a native Windows client.',
]);
if (!$post_id || is_wp_error($post_id)) {
    throw new RuntimeException('The source post could not be created.');
}
echo "Prepared the linked Linux source.\n";
