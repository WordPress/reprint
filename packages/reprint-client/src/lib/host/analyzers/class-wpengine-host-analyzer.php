<?php
/**
 * Host analyzer for WP Engine.
 *
 * WP Engine uses platform filesystem roots under /nas/content/live/ and the
 * older /nas/wp/www/ layout. Unlike installed plugin names, those paths
 * describe the server which answered preflight rather than files which may
 * remain after a site moves to another host.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class WpengineHostAnalyzer implements HostAnalyzer {
    /**
     * Score WP Engine's current and older platform filesystem roots.
     */
    public static function score(array $preflight_data): float
    {
        $runtime = $preflight_data['runtime'] ?? [];
        foreach (['document_root', 'script_filename', 'cwd'] as $path_name) {
            $path = $runtime[$path_name] ?? null;
            if (!is_string($path)) {
                continue;
            }

            if (
                strncmp($path, '/nas/content/live/', 18) === 0
                || strncmp($path, '/nas/wp/www/', 12) === 0
            ) {
                return 0.9;
            }
        }

        return 0.0;
    }

    public function analyze(array $preflight_data): RuntimeConfiguration
    {
        $configuration = new RuntimeConfiguration('wpengine');
        $configuration->php_ini = extract_php_ini($preflight_data);
        $configuration->constants = extract_constants($preflight_data);

        return $configuration;
    }
}
