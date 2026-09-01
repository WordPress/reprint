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
     * Score the WP Engine-specific MU-plugin names reported by preflight.
     *
     * One name is kept below the detection threshold because a copied plugin
     * may remain after an earlier migration. The standard wpengine-common
     * directory and its mu-plugin.php loader form a strong match together.
     */
    public static function score(array $preflight_data): float
    {
        $mu_plugin_names = [];
        foreach ($preflight_data['wp_content']['roots'] ?? [] as $root) {
            foreach ($root['mu_plugins'] ?? [] as $mu_plugin) {
                $name = $mu_plugin['name'] ?? null;
                if (is_string($name)) {
                    $mu_plugin_names[$name] = true;
                }
            }
        }

        $wpengine_mu_plugin_names = [
            'wpengine-common',
            'wpe-cache-plugin',
            'wpe-cache-plugin.php',
            'wpe-update-source-selector',
            'wpe-update-source-selector.php',
            'wpe-wp-sign-on-plugin',
            'wpe-wp-sign-on-plugin.php',
            'wpengine-security-auditor.php',
        ];
        $matches = 0;
        foreach ($wpengine_mu_plugin_names as $wpengine_mu_plugin_name) {
            if (isset($mu_plugin_names[$wpengine_mu_plugin_name])) {
                ++$matches;
            }
        }

        if (
            $matches >= 2
            || (
                isset($mu_plugin_names['wpengine-common'])
                && isset($mu_plugin_names['mu-plugin.php'])
            )
        ) {
            return 0.9;
        }

        return $matches === 1 ? 0.3 : 0.0;
    }

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
            'wp-content/mu-plugins/mu-plugin.php',
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
        ];

        return $manifest;
    }
}
