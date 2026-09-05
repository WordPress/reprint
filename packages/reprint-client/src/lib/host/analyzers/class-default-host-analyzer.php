<?php
/**
 * Default host analyzer for unrecognized hosting providers.
 *
 * Extracts PHP INI settings and constants from preflight data without
 * making any host-specific assumptions. Used as the fallback when no
 * registered analyzer scores above the detection threshold.
 */
class DefaultHostAnalyzer implements HostAnalyzer
{
    public static function score(array $preflight_data): float
    {
        // The default analyzer never wins detection — it's only used
        // as a fallback when nothing else matches.
        return 0.0;
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('other');
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = extract_constants($preflight_data);
        return $manifest;
    }
}
