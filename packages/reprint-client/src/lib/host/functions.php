<?php
/**
 * Host analyzer functions.
 *
 * Registry, detection logic, and shared preflight extraction helpers.
 */

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Server\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Server\trim_right_slash;

/**
 * All known host analyzers.
 *
 * @return array<string, class-string<HostAnalyzer>>
 */
function host_analyzer_registry(): array
{
    return [
        'wpcloud' => WpcloudHostAnalyzer::class,
        'wpengine' => WpengineHostAnalyzer::class,
    ];
}

/**
 * Detect the source host from preflight data using likelihood scoring.
 *
 * Each registered host analyzer scores the preflight data independently.
 * The host with the highest score wins, provided it reaches the minimum
 * threshold of 0.5. Returns "other" if no host qualifies.
 */
function detect_host(array $preflight_data): string
{
    $best_host = 'other';
    $best_score = 0.0;

    foreach (matching_host_analyzer_scores($preflight_data) as $name => $score) {
        if ($score > $best_score) {
            $best_host = $name;
            $best_score = $score;
        }
    }

    return $best_host;
}

/**
 * Build the runtime manifest for a local import.
 *
 * @param array $preflight_data The preflight response data.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches the existing host helper names.
function runtime_manifest_for(array $preflight_data): RuntimeManifest
{
    $matching_hosts = matching_host_analyzer_scores($preflight_data);

    if (isset($matching_hosts['wpcloud'])) {
        $manifest = ( new WpcloudHostAnalyzer() )->analyze($preflight_data);
    } elseif (isset($matching_hosts['wpengine'])) {
        $manifest = ( new WpengineHostAnalyzer() )->analyze($preflight_data);
    } else {
        $manifest = ( new DefaultHostAnalyzer() )->analyze($preflight_data);
    }

    return $manifest;
}

/**
 * Score every runtime-specific host which reaches the detection threshold.
 *
 * @return array<string, float>
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches the existing host helper names.
function matching_host_analyzer_scores(array $preflight_data): array
{
    $matching_hosts = [];

    foreach (host_analyzer_registry() as $name => $class) {
        $score = $class::score($preflight_data);
        if ($score >= 0.5) {
            $matching_hosts[$name] = $score;
        }
    }

    return $matching_hosts;
}

/**
 * Resolve plugins, MU plugins, and drop-ins excluded from a local import.
 *
 * Named plugin paths are excluded from every import. Generic drop-ins are
 * included only when current preflight paths identify WP Cloud or WP Engine.
 * Source paths use the actual WordPress directories reported by preflight,
 * including custom plugin and MU-plugin locations.
 * Pantheon's package stays because its generic loader.php requires it even
 * outside Pantheon. Its platform features require PANTHEON_ENVIRONMENT.
 *
 * @param array $preflight_data The preflight response data.
 * @return array<int, array{
 *     source_path: string|null,
 *     local_path: string,
 *     regular_plugin_directory: string|null
 * }>
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches the existing host helper names.
function excluded_plugins(array $preflight_data): array
{
    $local_paths = [
        // Plugins commonly installed or recommended by several hosts.
        'wp-content/plugins/nginx-helper',
        'wp-content/plugins/redis-cache',
        'wp-content/plugins/breeze',
        'wp-content/plugins/object-cache-pro',
        'wp-content/plugins/wp-rocket',
        'wp-content/plugins/w3-total-cache',
        'wp-content/plugins/servebolt-optimizer',
        'wp-content/plugins/a2-optimized-wp',
        'wp-content/plugins/boldgrid-backup',
        'wp-content/plugins/litespeed-cache',

        // Aruba's cache plugin and managed hosting checker.
        'wp-content/plugins/aruba-hispeed-cache',
        'wp-content/mu-plugins/aruba-wpchecker.php',
        'wp-content/mu-plugins/aruba-wpchecker',

        // Kinsta's platform MU plugin.
        'wp-content/mu-plugins/kinsta-mu-plugins.php',
        'wp-content/mu-plugins/kinsta-mu-plugins',

        // IONOS platform and setup plugins.
        'wp-content/mu-plugins/ionos-core.php',
        'wp-content/mu-plugins/ionos-core',
        'wp-content/mu-plugins/stretch-extra.php',
        'wp-content/mu-plugins/stretch-extra',
        'wp-content/plugins/ionos-essentials',
        'wp-content/plugins/ionos-wpdev-caddy',

        // Pressable cache and dashboard sign-on plugins.
        'wp-content/mu-plugins/pcm-extend-batcache.php',
        'wp-content/mu-plugins/pcm-exclude-pages-from-batcache.php',
        'wp-content/plugins/pressable-cache-management',
        'wp-content/plugins/pressable-onepress-login',

        // GoDaddy's Managed WordPress system plugin.
        'wp-content/mu-plugins/gd-system-plugin.php',
        'wp-content/mu-plugins/gd-system-plugin',

        // Bluehost's control plugin and Endurance cache plugins.
        'wp-content/plugins/bluehost-wordpress-plugin',
        'wp-content/mu-plugins/endurance-page-cache.php',
        'wp-content/mu-plugins/endurance-browser-cache.php',

        // HostGator's control plugin. The shared Endurance files are listed above.
        'wp-content/plugins/wp-plugin-hostgator',

        // Hostinger's control, onboarding, and setup plugins.
        'wp-content/plugins/hostinger',
        'wp-content/plugins/hostinger-easy-onboarding',
        'wp-content/mu-plugins/hostinger-mu-plugin.php',

        // Nexcess's managed application plugin and loader.
        'wp-content/mu-plugins/nexcess-mapps.php',
        'wp-content/mu-plugins/nexcess-mapps',

        // Rocket.net's CDN cache plugin.
        'wp-content/mu-plugins/cdn-cache-management.php',

        // SpinupWP's server cache plugin.
        'wp-content/plugins/spinupwp',

        // WordPress VIP's platform MU-plugin package.
        'wp-content/mu-plugins/vip-go-mu-plugins',

        // WP Engine tells sites moving away to remove its installed plugin and
        // platform MU plugins. The cache and update-source files include its
        // current plugin layout.
        'wp-content/plugins/wp-engine-smart-plugin-manager',
        'wp-content/mu-plugins/wpengine-common',
        'wp-content/mu-plugins/slt-force-strong-passwords.php',
        'wp-content/mu-plugins/force-strong-passwords',
        'wp-content/mu-plugins/stop-long-comments.php',
        'wp-content/mu-plugins/wpe-cache-plugin',
        'wp-content/mu-plugins/wpe-cache-plugin.php',
        'wp-content/mu-plugins/wpe-update-source-selector',
        'wp-content/mu-plugins/wpe-update-source-selector.php',
        'wp-content/mu-plugins/wpe-wp-sign-on-plugin',
        'wp-content/mu-plugins/wpe-wp-sign-on-plugin.php',
        'wp-content/mu-plugins/wpengine-security-auditor.php',

        // SiteGround's cache and security plugins.
        'wp-content/plugins/sg-cachepress',
        'wp-content/plugins/sg-security',

        // WP Cloud's MU plugins depend on multisite functions and wp.com APIs.
        'wp-content/mu-plugins/wpcomsh',
        'wp-content/mu-plugins/wpcomsh-dev',
        'wp-content/mu-plugins/wpcomsh-loader.php',
    ];

    $matching_hosts = matching_host_analyzer_scores($preflight_data);
    // WP Cloud and WP Engine cache drop-ins talk to platform services which
    // are unavailable locally. These generic filenames can belong to another
    // cache implementation, so exclude them only for a current host match.
    if (isset($matching_hosts['wpcloud']) || isset($matching_hosts['wpengine'])) {
        $local_paths[] = 'wp-content/object-cache.php';
        $local_paths[] = 'wp-content/advanced-cache.php';
    }
    // mu-plugin.php is WP Engine's loader, but its name is otherwise generic.
    if (isset($matching_hosts['wpengine'])) {
        $local_paths[] = 'wp-content/mu-plugins/mu-plugin.php';
    }

    $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
    $clean_absolute_directory = static function ($path): ?string {
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }
        return trim_right_slash($path);
    };
    $wordpress_absolute_path = $clean_absolute_directory($paths_urls['abspath'] ?? null);
    $content_directory = $clean_absolute_directory($paths_urls['content_dir'] ?? null);
    if ($content_directory === null && $wordpress_absolute_path !== null) {
        $content_directory = wp_join_unix_paths($wordpress_absolute_path, 'wp-content');
    }
    $plugins_directory = $clean_absolute_directory($paths_urls['plugins_dir'] ?? null);
    $mu_plugins_directory = $clean_absolute_directory($paths_urls['mu_plugins_dir'] ?? null);
    if ($content_directory !== null) {
        $plugins_directory = $plugins_directory
            ?? wp_join_unix_paths($content_directory, 'plugins');
        $mu_plugins_directory = $mu_plugins_directory
            ?? wp_join_unix_paths($content_directory, 'mu-plugins');
    }

    $excluded_plugins = [];
    foreach (array_values(array_unique($local_paths)) as $local_path) {
        $source_directory = $content_directory;
        $path_within_source_directory = substr($local_path, strlen('wp-content/'));
        $regular_plugin_directory = null;

        if (strpos($local_path, 'wp-content/plugins/') === 0) {
            $source_directory = $plugins_directory;
            $path_within_source_directory = substr($local_path, strlen('wp-content/plugins/'));
            $regular_plugin_directory = $path_within_source_directory;
        } elseif (strpos($local_path, 'wp-content/mu-plugins/') === 0) {
            $source_directory = $mu_plugins_directory;
            $path_within_source_directory = substr($local_path, strlen('wp-content/mu-plugins/'));
        }

        $excluded_plugins[] = [
            'source_path' => $source_directory === null
                ? null
                : wp_join_unix_paths($source_directory, $path_within_source_directory),
            'local_path' => $local_path,
            'regular_plugin_directory' => $regular_plugin_directory,
        ];
    }

    return $excluded_plugins;
}

/**
 * Extract selected INI directives from preflight's ini_get_all.
 * Only includes values that are likely to affect whether a migrated
 * site works or breaks.
 */
function extract_php_ini(array $preflight_data): array
{
    $ini_all = $preflight_data['runtime']['ini_get_all'] ?? [];
    if (empty($ini_all)) {
        return [];
    }

    $interesting_keys = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_vars',
        'max_input_time',
    ];

    $result = [];
    foreach ($interesting_keys as $key) {
        if (isset($ini_all[$key]) && $ini_all[$key] !== '') {
            $result[$key] = (string) $ini_all[$key];
        }
    }
    return $result;
}

/**
 * Extract PHP constants from preflight that need to be defined on the
 * target. Reads paths_urls from the preflight response.
 *
 * Returns only constants where the source value is a path that differs
 * from the standard WordPress layout (meaning WordPress won't derive
 * the right value on its own).
 */
function extract_constants(array $preflight_data): array
{
    $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
    $abspath = $paths_urls['abspath'] ?? '';
    if ($abspath !== '') {
        $abspath = trim_right_slash($abspath);
    }
    $content_dir = $paths_urls['content_dir'] ?? '';

    $result = [];

    // WP_CONTENT_DIR: if wp-content lives outside ABSPATH on the source
    // (e.g. wpcloud has ABSPATH at /wordpress/core/X.Y.Z/ but wp-content
    // at /srv/htdocs/wp-content), we need to explicitly set it.
    if (
        $content_dir !== ''
        && $abspath !== ''
        && !path_is_same_as_or_descendant_of($content_dir, $abspath)
    ) {
        $result['WP_CONTENT_DIR'] = '{fs-root}/wp-content';
    }

    return $result;
}
