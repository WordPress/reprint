<?php
/**
 * Host analyzer for SiteGround.
 *
 * SiteGround sites use a standard WordPress directory layout. The main
 * value is preserving PHP INI settings (memory limits, upload sizes) since
 * the target runtime may have different defaults.
 */
class SitegroundHostAnalyzer implements HostAnalyzer
{
    /**
     * Score how likely the source site is on SiteGround.
     *
     * Signals:
     * - Plugins prefixed with "sg-" (e.g. sg-cachepress, sg-security).
     *   Two or more is a strong signal (0.9), one is weak (0.3).
     */
    public static function score(array $preflight_data): float
    {
        $roots = $preflight_data['wp_content']['roots'] ?? [];
        $sg_count = 0;
        foreach ($roots as $root) {
            $plugins = $root['plugins'] ?? [];
            foreach ($plugins as $plugin) {
                $name = $plugin['name'] ?? '';
                if (strpos($name, 'sg-') === 0) {
                    $sg_count++;
                }
            }
        }

        if ($sg_count >= 2) {
            return 0.9;
        }
        if ($sg_count === 1) {
            return 0.3;
        }
        return 0.0;
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('siteground');
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = extract_constants($preflight_data);

        // SiteGround ships two hosting-specific plugins that depend on
        // SiteGround server infrastructure (their custom caching layer,
        // server-level security rules).  They won't work on a local
        // environment and produce warnings in wp-admin, so strip them
        // from disk.  Plugins under wp-content/plugins/ are also
        // automatically deactivated in the database by db-apply.
        $manifest->paths_to_remove = [
            'wp-content/plugins/sg-cachepress',
            'wp-content/plugins/sg-security',
        ];

        return $manifest;
    }
}
