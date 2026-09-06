<?php

namespace WordPress\Reprint\Server\Plugin;

use WordPress\Reprint\Server\MultisiteDatabaseSelection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These source validation errors are JSON protocol messages, not HTML.

/**
 * Captures the site WordPress bootstrapped for this remote Reprint API URL.
 *
 * No switch_to_blog(): it changes tables but does not load the other site's
 * plugins. Custom shared storage needs a migration rule before we can export it.
 *
 * @return array {
 *     Trusted source context.
 *
 *     @type int    $site_id Selected site ID.
 *     @type int    $network_id Selected network ID.
 *     @type string $base_prefix Network table prefix, not the selected site's prefix.
 *     @type string $abspath Remote WordPress root.
 *     @type string $content_dir Remote content directory.
 *     @type string $exporter_dir Reprint plugin directory excluded from the target.
 *     @type string $uploads_dir Remote selected uploads directory.
 *     @type string $uploads_url Selected uploads URL.
 *     @type string $home_url Selected home URL.
 *     @type string $site_url Selected WordPress URL.
 *     @type string $content_url Shared content URL.
 * }
 */
function get_multisite_export_context(): array {
    global $wpdb;

    if (defined('CUSTOM_USER_TABLE') || defined('CUSTOM_USER_META_TABLE')) {
        throw new \RuntimeException('Custom shared user tables require a separate multisite migration rule.');
    }
    $site_id = (int) get_current_blog_id();
    $network_id = (int) get_current_network_id();
    $base_prefix = $wpdb->base_prefix;
    $site = get_site($site_id);
    if (!$site || $site->archived || $site->spam || $site->deleted) {
        throw new \RuntimeException('The selected multisite site is archived, spam, deleted, or missing.');
    }
    if (get_site_option('ms_files_rewriting') || defined('UPLOADS') || defined('BLOGUPLOADDIR')) {
        throw new \RuntimeException('Legacy multisite uploads require a separate migration rule; this pull supports modern uploads directories.');
    }
    if (rtrim(WP_CONTENT_DIR, '/') !== rtrim(ABSPATH, '/') . '/wp-content') {
        throw new \RuntimeException('A separate content directory requires a separate multisite migration rule.');
    }
    $uploads = wp_upload_dir(null, false);
    $expected_uploads = WP_CONTENT_DIR . '/uploads' . ( $site_id === 1 ? '' : '/sites/' . $site_id );
    if (rtrim($uploads['basedir'], '/') !== $expected_uploads) {
        throw new \RuntimeException('Custom multisite uploads are not supported; observed directory: ' . $uploads['basedir']);
    }

    // Use the exporter's rules, not $wpdb->tables: plugins can append their
    // own tables there. A registered plugin table still needs a migration rule.
    foreach ($wpdb->get_col('SHOW TABLES') as $table) {
        if (strpos($table, $base_prefix) !== 0) {
            continue;
        }
        $suffix = substr($table, strlen($base_prefix));
        $table_site_id = preg_match('/^([1-9][0-9]*)_/', $suffix, $matches) ? (int) $matches[1] : 1;
        $selection = new MultisiteDatabaseSelection($base_prefix, $table_site_id, $network_id);
        if (!$selection->includes_table($table)) {
            throw new \RuntimeException('No multisite migration rule exists for table ' . $table . '. It may contain shared plugin data.');
        }
    }

    return [
        'site_id' => $site_id,
        'network_id' => $network_id,
        'base_prefix' => $base_prefix,
        'abspath' => rtrim(ABSPATH, '/'),
        'content_dir' => rtrim(WP_CONTENT_DIR, '/'),
        'exporter_dir' => rtrim(PLUGIN_DIR, '/'),
        'uploads_dir' => rtrim($uploads['basedir'], '/'),
        'uploads_url' => rtrim($uploads['baseurl'], '/'),
        'home_url' => get_option('home'),
        'site_url' => get_option('siteurl'),
        'content_url' => content_url(),
    ];
}
