<?php
/** Build filesystem, route, update, and quota caches through real WordPress APIs. */
mkdir(ABSPATH . 'shared-themes/cache-parent', 0755, true);
mkdir(ABSPATH . 'shared-themes/cache-child', 0755, true);
mkdir(WPMU_PLUGIN_DIR, 0755, true);
file_put_contents(WPMU_PLUGIN_DIR . '/cache-theme-directory.php',
    "<?php register_theme_directory(ABSPATH . 'shared-themes');"
);
register_theme_directory(ABSPATH . 'shared-themes');
file_put_contents(ABSPATH . 'shared-themes/cache-parent/style.css', "/*\nTheme Name: Cache parent\nVersion: 1.0\n*/");
file_put_contents(ABSPATH . 'shared-themes/cache-child/style.css', "/*\nTheme Name: Cache child\nTemplate: cache-parent\nVersion: 1.0\n*/");
file_put_contents(ABSPATH . 'shared-themes/cache-child/marker.txt', 'Source child theme');
file_put_contents(ABSPATH . 'shared-themes/cache-parent/index.php', '<?php
echo "Source parent theme ";
echo file_get_contents(get_stylesheet_directory() . "/marker.txt") . " ";
echo get_theme_mod("accent") . " ";
while (have_posts()) { the_post(); the_content(); }
');
wp_clean_themes_cache();
switch_theme('cache-child');
set_theme_mod('accent', 'blue');
wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'shop', 'post_title' => 'Shop', 'post_content' => 'Local shop page']);
wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'host-route', 'post_title' => 'Host', 'post_content' => 'Hosting-only route']);
mkdir(WP_PLUGIN_DIR . '/pressable-cache-management');
file_put_contents(WP_PLUGIN_DIR . '/pressable-cache-management/pressable-cache-management.php', '<?php
/* Plugin Name: Source hosting route
Version: 1.0
*/
function cache_fixture_host_route() {
    add_rewrite_rule("^shop/?$", "index.php?pagename=host-route", "top");
}
add_action("init", "cache_fixture_host_route");
');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('pressable-cache-management/pressable-cache-management.php');
cache_fixture_host_route();
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);

$reprint_cache_uploads = wp_upload_dir();
file_put_contents($reprint_cache_uploads['basedir'] . '/source-only.bin', str_repeat('s', 128));
get_dirsize($reprint_cache_uploads['basedir']);
set_site_transient('update_plugins', (object) [
    'last_checked' => time(),
    'checked' => ['pressable-cache-management/pressable-cache-management.php' => '1.0'],
    'response' => ['pressable-cache-management/pressable-cache-management.php' => (object) ['slug' => 'pressable-cache-management', 'new_version' => '99.0']],
]);
set_site_transient('update_themes', (object) [
    'last_checked' => time(), 'checked' => ['cache-parent' => '1.0'],
    'response' => ['cache-parent' => ['new_version' => '99.0']],
]);
set_site_transient('update_core', (object) [
    'last_checked' => time(), 'version_checked' => $GLOBALS['wp_version'],
    'updates' => [ (object) ['response' => 'upgrade', 'current' => '99.0', 'locale' => 'en_US']],
]);
file_put_contents(ABSPATH . 'cache-probe.php', '<?php
require __DIR__ . "/wp-load.php";
require_once ABSPATH . "wp-admin/includes/update.php";
wp_set_current_user(1);
header("Content-Type: application/json");
echo json_encode([
    "updates" => wp_get_update_data()["counts"],
    "size" => get_dirsize(wp_upload_dir()["basedir"]),
    "stylesheet" => get_stylesheet(), "template" => get_template(),
]);
');
