<?php
/** A small real network for the E2E pair at each stack layer. */
global $wpdb;
$domain = wp_parse_url(home_url(), PHP_URL_HOST) . ':' . wp_parse_url(home_url(), PHP_URL_PORT);
$wpdb->query("ALTER TABLE {$wpdb->blogs} AUTO_INCREMENT = 7");
$shop = wpmu_create_blog($domain, '/shop/', 'Shop', 1, ['public' => 1]);
$sibling = wpmu_create_blog($domain, '/sibling/', 'Sibling', 1, ['public' => 1]);
$shared = wp_create_user('shared', 'multisite-password', 'shared@example.test');
$member = wp_create_user('shop-member', 'multisite-password', 'member@example.test');
$sibling_member = wp_create_user('sibling-member', 'multisite-password', 'sibling@example.test');
foreach ([$shop, $sibling, $shared, $member, $sibling_member] as $result) {
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}
add_user_to_blog($shop, $shared, 'editor');
add_user_to_blog($shop, $member, 'subscriber');
add_user_to_blog($sibling, $shared, 'administrator');
add_user_to_blog($sibling, $sibling_member, 'author');
remove_user_from_blog(1, $shop);
update_user_meta($shared, 'first_name', 'Shared profile');
update_user_meta($shared, 'session_tokens', ['private-source-session' => ['expiration' => time() + 86400]]);
$sites = [];
foreach ([1, (int) $shop, (int) $sibling] as $site_id) {
    switch_to_blog($site_id);
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1]) as $post) {
        wp_delete_post($post->ID, true);
    }
    $author = $site_id === (int) $shop ? $shared : ($site_id === (int) $sibling ? $sibling_member : 1);
    $post = wp_insert_post([
        'import_id' => 100, 'post_author' => $author, 'post_status' => 'publish',
        'post_title' => 'Post on site ' . $site_id, 'post_content' => 'Only site ' . $site_id,
    ]);
    $upload = wp_upload_bits('overlap.txt', null, 'Media on site ' . $site_id);
    $attachment = wp_insert_attachment([
        'import_id' => 200, 'post_author' => $author, 'post_status' => 'inherit',
        'post_title' => 'Media on site ' . $site_id, 'post_mime_type' => 'text/plain',
    ], $upload['file'], $post);
    if ($post !== 100 || $attachment !== 200 || $upload['error']) {
        throw new RuntimeException('The fixture requires overlapping post and attachment IDs and a real upload.');
    }
    update_option('permalink_structure', '');
    $sites[$site_id] = ['url' => home_url(), 'media_file' => $upload['file'], 'media_url' => $upload['url']];
    restore_current_blog();
}
file_put_contents(ABSPATH . '.multisite-layer.json', json_encode(['sites' => $sites]));
