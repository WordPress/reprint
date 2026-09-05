<?php

namespace WordPress\Reprint\Server\Plugin;

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
    $uploads = wp_upload_dir(null, false);
    $expected_uploads = WP_CONTENT_DIR . '/uploads' . ( $site_id === 1 ? '' : '/sites/' . $site_id );
    if (rtrim($uploads['basedir'], '/') !== $expected_uploads) {
        throw new \RuntimeException('Custom multisite uploads are not supported; observed directory: ' . $uploads['basedir']);
    }

    // Match WordPress core's own table lists, including older installations
    // without blogmeta. A plugin table is reported, never silently omitted.
    $blog_tables = array_values($wpdb->tables('blog', false));
    $global_tables = array_values($wpdb->tables('global', false));
    $global_tables = array_merge($global_tables, ['site', 'sitemeta', 'blogs', 'blogmeta', 'signups', 'registration_log']);
    foreach ($wpdb->get_col('SHOW TABLES') as $table) {
        if (strpos($table, $base_prefix) !== 0) {
            continue;
        }
        $suffix = substr($table, strlen($base_prefix));
        $site_suffix = preg_replace('/^[0-9]+_/', '', $suffix);
        if (!in_array($suffix, $global_tables, true) && !in_array($site_suffix, $blog_tables, true)) {
            throw new \RuntimeException('No multisite migration rule exists for table ' . $table . '. It may contain shared plugin data.');
        }
    }

    return [
        'site_id' => $site_id,
        'network_id' => $network_id,
        'base_prefix' => $base_prefix,
        'abspath' => rtrim(ABSPATH, '/'),
        'content_dir' => rtrim(WP_CONTENT_DIR, '/'),
        'uploads_dir' => rtrim($uploads['basedir'], '/'),
        'uploads_url' => rtrim($uploads['baseurl'], '/'),
        'home_url' => get_option('home'),
        'site_url' => get_option('siteurl'),
        'content_url' => content_url(),
    ];
}
