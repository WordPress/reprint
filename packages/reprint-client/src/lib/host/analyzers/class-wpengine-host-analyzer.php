<?php
/**
 * Host analyzer for WP Engine.
 *
 * WP Engine installs platform MU plugins for caching, updates, sign-on,
 * security logging, and its wp-admin integration. The analyzer removes them
 * after a site moves away from WP Engine.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class WpengineHostAnalyzer implements HostAnalyzer {
    /**
     * Detect the current WP Engine filesystem layout, not copied plugin names.
     * Platform plugins may remain on a site after it has moved elsewhere.
     */
    public static function score(array $preflight_data): float
    {
        $paths = [
            $preflight_data['runtime']['document_root'] ?? '',
            $preflight_data['database']['wp']['paths_urls']['abspath'] ?? '',
        ];
        foreach ($paths as $path) {
            if (is_string($path) && preg_match('~^/nas/(?:content/live|wp/www)/[^/]+(?:/|$)~', $path) === 1) {
                return 1.0;
            }
        }
        return 0.0;
    }

    /** Preserve a generic loader unless its header identifies WP Engine System. */
    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('wpengine');
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = extract_constants($preflight_data);

        // WP Engine tells sites moving to another host to remove its cache
        // drop-ins and platform MU plugins. The cache and update-source files
        // extend that published list with the current layout reported by a
        // WP Engine site.
        $manifest->paths_to_remove = [
            'wp-content/advanced-cache.php',
            'wp-content/object-cache.php',
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
        ];

        // WP Engine documents this display name for its common-platform
        // loader. A customer can also call a file mu-plugin.php, so the shared
        // filename alone must never authorize removing it. Older exporters
        // without headers leave that ambiguous file and its common-platform
        // directory in place, rather than keeping a loader with a missing dependency.
        foreach ($preflight_data['wp_content']['roots'] ?? [] as $root) {
            foreach ($root['mu_plugins'] ?? [] as $plugin) {
                $name = $plugin['name'] ?? '';
                if (( $plugin['type'] ?? '' ) === 'file'
                    && ( $plugin['headers']['name'] ?? '' ) === 'WP Engine System'
                    && is_string($name) && basename($name) === $name
                    && substr($name, -4) === '.php') {
                    $manifest->paths_to_remove[] = 'wp-content/mu-plugins/wpengine-common';
                    $manifest->paths_to_remove[] = 'wp-content/mu-plugins/' . $name;
                }
            }
        }
        $manifest->paths_to_remove = array_values(array_unique($manifest->paths_to_remove));
        return $manifest;
    }
}
