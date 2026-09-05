<?php
/**
 * Host analyzer functions.
 *
 * Registry, detection logic, and shared preflight extraction helpers.
 */

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
        'siteground' => SitegroundHostAnalyzer::class,
        'wpengine' => WpengineHostAnalyzer::class,
        'kinsta' => KinstaHostAnalyzer::class,
        'pantheon' => PantheonHostAnalyzer::class,
        'ionos' => IonosHostAnalyzer::class,
        'pressable' => PressableHostAnalyzer::class,
        'godaddy' => GodaddyHostAnalyzer::class,
        'bluehost' => BluehostHostAnalyzer::class,
        'hostgator' => HostgatorHostAnalyzer::class,
        'hostinger' => HostingerHostAnalyzer::class,
        'nexcess' => NexcessHostAnalyzer::class,
        'rocketnet' => RocketnetHostAnalyzer::class,
        'spinupwp' => SpinupwpHostAnalyzer::class,
        'wpvip' => WpVipHostAnalyzer::class,
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
    $threshold = 0.5;
    $best_host = 'other';
    $best_score = 0.0;

    foreach (host_analyzer_registry() as $name => $class) {
        $score = $class::score($preflight_data);
        if ($score >= $threshold && $score > $best_score) {
            $best_host = $name;
            $best_score = $score;
        }
    }

    return $best_host;
}

/**
 * Build the runtime manifest for a local import.
 *
 * Every manifest includes source-host paths which are removed from all local
 * imports, followed by ambiguous paths declared for the detected source host.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches the existing host helper names.
function runtime_manifest_for(string $webhost, array $preflight_data): RuntimeManifest
{
    $manifest = host_analyzer_for($webhost)->analyze($preflight_data);
    $manifest->paths_to_remove = array_values(array_unique(array_merge(
        source_host_paths_to_remove(),
        $manifest->paths_to_remove,
    )));

    return $manifest;
}

/**
 * Instantiate the right analyzer for a detected host name.
 */
function host_analyzer_for(string $webhost): HostAnalyzer
{
    $registry = host_analyzer_registry();
    if (isset($registry[$webhost])) {
        return new $registry[$webhost]();
    }
    return new DefaultHostAnalyzer();
}

/**
 * Source-host paths removed from every local import.
 *
 * @return string[]
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Matches the existing host helper names.
function source_host_paths_to_remove(): array
{
    return [
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

        // Kinsta's platform MU plugin.
        'wp-content/mu-plugins/kinsta-mu-plugins.php',
        'wp-content/mu-plugins/kinsta-mu-plugins',

        // Pantheon's platform MU-plugin package. Its generic loader.php remains.
        'wp-content/mu-plugins/pantheon-mu-plugin',

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
