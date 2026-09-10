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
 * Filesystem paths are source-side absolute paths with trailing slashes removed.
 * They are not resolved through symlinks here; file selection checks those when
 * indexing or fetching each path. URLs are separate from filesystem paths.
 *
 * @return array {
 *     Trusted source context.
 *
 *     @type int    $site_id Selected site ID.
 *     @type int    $network_id Selected network ID.
 *     @type string $base_prefix Network table prefix, not the selected site's prefix.
 *     @type string $abspath Remote WordPress root.
 *     @type string $content_dir Remote abspath/wp-content directory.
 *     @type string $exporter_dir Actual Reprint plugin directory, excluded from the target.
 *     @type string $uploads_dir Whole-site upload basedir: content_dir/uploads for
 *                               site 1, content_dir/uploads/sites/7 for site 7.
 *                               Never the current year/month directory.
 *     @type string $uploads_url Whole-site uploads base URL, without a year/month suffix.
 *     @type string $home_url Selected home URL.
 *     @type string $site_url Selected WordPress URL.
 *     @type string $content_url Shared content URL.
 *     @type string $network_content_url Shared network content URL.
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
    // Legacy layouts can use blogs.dir and rewritten media URLs. The file
    // selection below assumes uploads/ and uploads/sites/<site_id>/ instead.
    if (get_site_option('ms_files_rewriting') || defined('UPLOADS') || defined('BLOGUPLOADDIR')) {
        throw new \RuntimeException('Legacy multisite uploads require a separate migration rule; this pull supports modern uploads directories.');
    }
    // File traversal starts inside abspath. A relocated content directory needs
    // separate source roots and target path rules, which this mode does not have.
    if (rtrim(WP_CONTENT_DIR, '/') !== rtrim(ABSPATH, '/') . '/wp-content') {
        throw new \RuntimeException('A separate content directory requires a separate multisite migration rule.');
    }
    // Ask WordPress, including upload_dir filters, without creating a directory
    // during an export. Use basedir, not path: path can end in the current month.
    $uploads = wp_upload_dir(null, false);
    // Accept only the layout that MultisiteFileSelection can separate by site.
    // A custom basedir could overlap another site's files. Site 1 is the known
    // overlap: its uploads/ root contains sites/, which file selection excludes.
    $expected_uploads = WP_CONTENT_DIR . '/uploads' . ( $site_id === 1 ? '' : '/sites/' . $site_id );
    if (rtrim($uploads['basedir'], '/') !== $expected_uploads) {
        throw new \RuntimeException('Custom multisite uploads are not supported; observed directory: ' . $uploads['basedir']);
    }

    // Use the exporter's rules, not $wpdb->tables: plugins can append their
    // own tables there. A registered plugin table still needs a migration rule.
    // This runs for each multisite API request, including file requests.
    // get_col() holds the whole database table-name list in memory; this setup
    // cost grows with the number of tables, despite cheap per-path file filters.
    foreach ($wpdb->get_col('SHOW TABLES') as $table) {
        if (strpos($table, $base_prefix) !== 0) {
            continue;
        }
        $suffix = substr($table, strlen($base_prefix));
        $table_site_id = preg_match('/^([1-9][0-9]*)_/', $suffix, $matches) ? (int) $matches[1] : 1;
        $selection = new MultisiteDatabaseSelection($base_prefix, $table_site_id, $network_id);
        if ($table === $selection->get_user_table_name()) {
            continue;
        }
        if (!$selection->includes_table($table)) {
            throw new \RuntimeException('No multisite migration rule exists for table ' . $table . '. It may contain shared plugin data.');
        }
    }

    // Distinct hosts need no exclusions. Preflight separately collects paths
    // for child sites below these bases; ordinary export requests do not.
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
        'network_content_url' => network_site_url('/wp-content'),
    ];
}

/**
 * List only child-site paths that a selected home/site URL could rewrite.
 *
 * Selecting network.test/shop needs /shop/news/, but not /sibling/ or sites
 * on other domains. A root selection can need every path on that domain.
 * Read the indexed site directory once per preflight, not per SQL/file request.
 * Keep short strings only: wpdb::get_results() would retain one PHP object per
 * row as well as the path list. MYSQLI_USE_RESULT reads one row at a time.
 *
 * This list intentionally grows with matching sites. One million 20-byte paths
 * cost about 62 MiB as a PHP 8.4 list, before JSON encoding. No page list, URL
 * object, regex or upload-site ID list is created for those sites.
 *
 * @param array $source {
 *     Source context returned by get_multisite_export_context().
 *
 *     @type int    $site_id Selected site ID.
 *     @type string $base_prefix Network table prefix.
 *     @type string $home_url Selected home URL.
 *     @type string $site_url Selected WordPress URL.
 * }
 * @return array<string, string[]> Source HTTP(S) origin => child-site paths.
 */
function get_multisite_nested_site_paths(array $source): array {
    global $wpdb;

    $origins = [];
    foreach (array_unique([$source['home_url'], $source['site_url']]) as $url) {
        $parts = wp_parse_url($url);
        $domain = strtolower($parts['host']) . ( isset($parts['port']) ? ':' . $parts['port'] : '' );
        $default_port = strtolower($parts['scheme']) === 'https' ? 443 : 80;
        $authority = ( $parts['port'] ?? null ) === $default_port ? strtolower($parts['host']) : $domain;
        $origin = strtolower($parts['scheme']) . '://' . $authority;
        $path = rtrim($parts['path'] ?? '', '/') . '/';
        // HTTP home plus HTTPS siteurl on one host still needs one path list.
        $origins[$authority]['origin'] = $origin;
        // A default port in home/siteurl need not appear in blogs.domain.
        $origins[$authority]['domains'][$domain] = true;
        $origins[$authority]['domains'][$authority] = true;
        $origins[$authority]['paths'][] = $path;
    }

    if (!$wpdb->dbh instanceof \mysqli) {
        throw new \RuntimeException('Reading multisite child paths requires a MySQLi WordPress connection; observed ' . gettype($wpdb->dbh) . '.');
    }
    $paths_by_origin = [];
    foreach ($origins as $authority => $selection) {
        $origin = $selection['origin'];
        $conditions = [];
        foreach (array_unique($selection['paths']) as $path) {
            $conditions[] = $wpdb->prepare('(path LIKE %s AND path <> %s)', $wpdb->esc_like($path) . '%', $path);
        }
        // No network ID filter: a site in another network can still have a
        // matching host/path. Archived sites must also keep their old links.
        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL -- WordPress checks the table prefix; each path condition above is prepared, and domains use placeholders.
            "SELECT path FROM `{$source['base_prefix']}blogs` WHERE domain IN (" . implode(',', array_fill(0, count($selection['domains']), '%s')) . ") AND blog_id <> %d AND (" . implode(' OR ', $conditions) . ')',
            array_merge(array_keys($selection['domains']), [$source['site_id']])
        );
        // wpdb normally removes its escaped-percent placeholders in query().
        // The direct unbuffered call must do that too, or LIKE '/shop/%'
        // reaches MySQL with a placeholder string instead of its wildcard.
        $query = $wpdb->remove_placeholder_escape($query);
        $rows = mysqli_query($wpdb->dbh, $query, MYSQLI_USE_RESULT);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read multisite child paths: ' . mysqli_error($wpdb->dbh));
        }
        $paths_by_origin[$origin] = [];
        try {
            // Do not issue another query on this connection until free_result().
            // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- Fetch one row until MySQL reaches EOF.
            while ($row = mysqli_fetch_row($rows)) {
                $paths_by_origin[$origin][] = $row[0];
            }
            if (mysqli_errno($wpdb->dbh) !== 0) {
                throw new \RuntimeException('Reading multisite child paths stopped: ' . mysqli_error($wpdb->dbh));
            }
        } finally {
            mysqli_free_result($rows);
        }
    }
    return $paths_by_origin;
}
