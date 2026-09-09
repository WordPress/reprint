<?php
/**
 * Build a real network with overlapping content IDs and shared users.
 * Run with wp eval-file after core multisite-convert.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI fixture errors, not HTML.
// phpcs:disable WordPress.DB.SlowDBQuery -- A handful of known fixture rows.
global $wpdb;
$reprint_domain = wp_parse_url(home_url(), PHP_URL_HOST) . ':' . wp_parse_url(home_url(), PHP_URL_PORT);
$wpdb->query("ALTER TABLE {$wpdb->blogs} AUTO_INCREMENT = 7");
// WordPress 6.2 normalizes site domains with sanitize_user(), which drops the
// colon before our local port. Preserve only this fixture's known address while
// creating the two local subsites, before WordPress writes their URL options.
$reprint_preserve_local_port = static function ($sanitized_value, $original_value) use ($reprint_domain) {
    return $original_value === $reprint_domain ? $reprint_domain : $sanitized_value;
};
add_filter('sanitize_user', $reprint_preserve_local_port, 10, 2);
$reprint_shop = wpmu_create_blog($reprint_domain, '/shop/', 'Shop', 1, ['public' => 1], 1);
$reprint_sibling = wpmu_create_blog($reprint_domain, '/sibling/', 'Sibling', 1, ['public' => 1], 1);
remove_filter('sanitize_user', $reprint_preserve_local_port);
$wpdb->insert($wpdb->site, ['id' => 2, 'domain' => 'second-network.test', 'path' => '/']);
// Like populate_network(), seed this row directly: the legacy default filter
// reports true for a missing row, so add/update_network_option cannot create it.
$wpdb->insert($wpdb->sitemeta, ['site_id' => 2, 'meta_key' => 'ms_files_rewriting', 'meta_value' => '0']);
$reprint_other = wpmu_create_blog('second-network.test', '/', 'Other network', 1, ['public' => 1], 2);
foreach ([$reprint_shop, $reprint_sibling, $reprint_other] as $reprint_site_id) {
    if (is_wp_error($reprint_site_id)) {
        throw new RuntimeException($reprint_site_id->get_error_message());
    }
}

$reprint_users = [];
foreach (['chosen', 'empty-member', 'former-author', 'commenter', 'link-author', 'sibling-person', 'other-person'] as $reprint_login) {
    $reprint_id = wp_create_user($reprint_login, 'multisite-password', $reprint_login . '@example.test');
    if (is_wp_error($reprint_id)) {
        throw new RuntimeException($reprint_id->get_error_message());
    }
    $reprint_users[$reprint_login] = $reprint_id;
}
add_user_to_blog($reprint_shop, $reprint_users['chosen'], 'editor');
add_user_to_blog($reprint_sibling, $reprint_users['chosen'], 'administrator');
add_user_to_blog($reprint_shop, $reprint_users['empty-member'], 'subscriber');
add_user_to_blog($reprint_shop, $reprint_users['former-author'], 'author');
add_user_to_blog($reprint_sibling, $reprint_users['sibling-person'], 'author');
add_user_to_blog($reprint_other, $reprint_users['other-person'], 'administrator');
update_user_meta($reprint_users['chosen'], 'first_name', 'Shared profile');
update_user_meta($reprint_users['chosen'], 'session_tokens', ['source-session-secret' => ['expiration' => time() + 86400]]);
update_user_meta($reprint_users['chosen'], '_application_passwords', [['password' => 'source-application-secret']]);
update_user_meta($reprint_users['chosen'], 'source_plugin_private', 'source-unknown-profile');

mkdir(WP_PLUGIN_DIR . '/shared-network', 0755, true);
file_put_contents(WP_PLUGIN_DIR . '/shared-network/shared.php', "<?php\n/*
Plugin Name: Multisite shared network fixture
*/
define('MULTISITE_SHARED_NETWORK_PLUGIN', true);
add_shortcode('network-scope', function () { return 'network-plugin-site-' . get_current_blog_id(); });
");
if (!is_dir(WPMU_PLUGIN_DIR)) {
    mkdir(WPMU_PLUGIN_DIR, 0755, true);
}
file_put_contents(WPMU_PLUGIN_DIR . '/shared.php', "<?php define('MULTISITE_SHARED_MU_PLUGIN', true);\n");
activate_plugin('shared-network/shared.php', '', true);
// WordPress accepts a language setting only when that language is installed.
// A small real catalog keeps the fixture independent of translation downloads.
if (!is_dir(WP_LANG_DIR)) {
    mkdir(WP_LANG_DIR, 0755, true);
}
require_once ABSPATH . WPINC . '/pomo/mo.php';
$reprint_catalog = new MO();
$reprint_catalog->set_header('Language', 'pl_PL');
$reprint_catalog->add_entry(new Translation_Entry([
    'singular' => 'Migration language fixture', 'translations' => ['Test języka migracji'],
]));
$reprint_catalog->export_to_file(WP_LANG_DIR . '/pl_PL.mo');
update_site_option('allowedthemes', [get_stylesheet() => true]);
update_site_option('private_network_plugin_setting', 'must-not-move');

$reprint_sites = [];
foreach ([1, (int) $reprint_shop, (int) $reprint_sibling, (int) $reprint_other] as $reprint_site_id) {
    switch_to_blog($reprint_site_id);
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1]) as $reprint_post) {
        wp_delete_post($reprint_post->ID, true);
    }
    $reprint_author = $reprint_site_id === (int) $reprint_shop ? $reprint_users['former-author']
        : ( $reprint_site_id === (int) $reprint_sibling ? $reprint_users['sibling-person'] : ( $reprint_site_id === (int) $reprint_other ? $reprint_users['other-person'] : 1 ) );
    $reprint_post_id = wp_insert_post([
        'import_id' => 100, 'post_author' => $reprint_author, 'post_status' => 'publish',
        'post_title' => 'site-' . $reprint_site_id . '-only',
        'post_content' => '<p>site-' . $reprint_site_id . '-content</p>[network-scope]'
            . '<a href="http://' . $reprint_domain . '/sibling/">source sibling link</a>'
            . '<a href="/sibling/?p=100">relative sibling link</a><a href="local-page">relative local link</a>',
    ]);
    if ($reprint_post_id !== 100) {
        throw new RuntimeException('Fixture needs overlapping post ID 100.');
    }
    // Same filename, dimensions, and attachment ID on every site; different bytes.
    $reprint_image = imagecreatetruecolor(640, 480);
    imagefill($reprint_image, 0, 0, imagecolorallocate($reprint_image, $reprint_site_id * 20, 80, 160));
    ob_start();
    imagepng($reprint_image);
    $reprint_image_bytes = ob_get_clean();
    imagedestroy($reprint_image);
    $reprint_upload = wp_upload_bits('overlap.png', null, $reprint_image_bytes);
    $reprint_attachment_id = wp_insert_attachment([
        'import_id' => 200, 'post_author' => $reprint_author, 'post_status' => 'inherit',
        'post_title' => 'media-site-' . $reprint_site_id, 'post_mime_type' => 'image/png',
        'guid' => $reprint_upload['url'],
    ], $reprint_upload['file'], $reprint_post_id);
    update_post_meta($reprint_post_id, '_thumbnail_id', $reprint_attachment_id);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $reprint_metadata = wp_generate_attachment_metadata($reprint_attachment_id, $reprint_upload['file']);
    wp_update_attachment_metadata($reprint_attachment_id, $reprint_metadata);
    if (empty($reprint_metadata['sizes']['thumbnail'])) {
        throw new RuntimeException('Fixture needs a real WordPress thumbnail. Install the GD extension.');
    }
    update_option('site_plugin_settings', ['attachment_id' => $reprint_attachment_id, 'url' => $reprint_upload['url'], 'site_id' => $reprint_site_id]);
    wp_insert_comment(['comment_post_ID' => $reprint_post_id, 'user_id' => $reprint_users['commenter'], 'comment_author' => 'Registered commenter', 'comment_content' => 'site-' . $reprint_site_id . '-comment', 'comment_approved' => 1]);
    $wpdb->insert($wpdb->links, ['link_name' => 'Registered link author', 'link_url' => home_url('/'), 'link_owner' => $reprint_users['link-author']]);
    $reprint_term_ids = wp_set_post_terms($reprint_post_id, ['Shared term name'], 'post_tag');
    update_term_meta($reprint_term_ids[0], 'site_marker', 'site-' . $reprint_site_id);
    update_option('permalink_structure', '');
    if ($reprint_site_id === (int) $reprint_shop) {
        delete_option('WPLANG');
    } else {
        update_option('WPLANG', '');
    }
    $reprint_sites[$reprint_site_id] = [
        'id' => $reprint_site_id, 'url' => home_url(), 'prefix' => $wpdb->prefix,
        'media_file' => $reprint_upload['file'], 'media_url' => $reprint_upload['url'],
        'media_sha256' => hash_file('sha256', $reprint_upload['file']),
        'attachment_id' => $reprint_attachment_id,
        'thumbnail_file' => dirname($reprint_upload['file']) . '/' . $reprint_metadata['sizes']['thumbnail']['file'],
        'thumbnail_sha256' => hash_file('sha256', dirname($reprint_upload['file']) . '/' . $reprint_metadata['sizes']['thumbnail']['file']),
    ];
    restore_current_blog();
}
foreach ($reprint_sites as $reprint_site_id => $reprint_record) {
    switch_to_blog($reprint_site_id);
    $reprint_settings = get_option('site_plugin_settings');
    $reprint_settings['sibling_media'] = $reprint_sites[$reprint_sibling]['media_url'];
    $reprint_settings['cross_site_reference'] = ['site_id' => (int) $reprint_sibling, 'post_id' => 100];
    update_option('site_plugin_settings', $reprint_settings);
    restore_current_blog();
}
remove_user_from_blog($reprint_users['former-author'], $reprint_shop);
remove_user_from_blog(1, $reprint_shop);
remove_user_from_blog(1, $reprint_other);
file_put_contents(ABSPATH . '.multisite-fixture.json', json_encode(['sites' => $reprint_sites, 'users' => $reprint_users]));
