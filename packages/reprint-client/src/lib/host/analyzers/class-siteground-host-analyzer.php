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
     * - The exact sg-cachepress and sg-security plugin pair is a strong
     *   signal (0.9). One plugin is a weak signal (0.3).
     */
    public static function score(array $preflight_data): float
    {
        $roots = $preflight_data['wp_content']['roots'] ?? [];
        $siteground_plugins = [];
        foreach ($roots as $root) {
            $plugins = $root['plugins'] ?? [];
            foreach ($plugins as $plugin) {
                $name = $plugin['name'] ?? '';
                if ($name === 'sg-cachepress' || $name === 'sg-security') {
                    $siteground_plugins[$name] = true;
                }
            }
        }

        if (count($siteground_plugins) === 2) {
            return 0.9;
        }
        if (count($siteground_plugins) === 1) {
            return 0.3;
        }
        return 0.0;
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('siteground');
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = extract_constants($preflight_data);

        return $manifest;
    }
}
