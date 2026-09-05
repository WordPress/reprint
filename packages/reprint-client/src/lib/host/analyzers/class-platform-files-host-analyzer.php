<?php
/**
 * Shared analyzer for hosts identified by exact plugin inventory entries.
 *
 * Hosts in this family need no runtime emulation. Their exact platform files
 * identify the source host but are removed from every local import.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
abstract class PlatformFilesHostAnalyzer implements HostAnalyzer {
    protected const SOURCE = '';

    /** @var array<int, array<string, array<string, string>>> */
    protected const SIGNAL_SETS = [];

    /**
     * Match one complete signal set within one WordPress content root.
     *
     * A multi-entry set outranks a single vendor-named plugin. Any incomplete
     * set remains below the host detection threshold.
     */
    public static function score(array $preflight_data): float
    {
        $has_partial_match = false;

        foreach (static::read_plugin_inventories($preflight_data) as $inventory) {
            foreach (static::SIGNAL_SETS as $signal_set) {
                $matches = 0;
                $signal_count = 0;

                foreach ($signal_set as $plugin_type => $expected_plugins) {
                    foreach ($expected_plugins as $expected_name => $expected_type) {
                        ++$signal_count;
                        if (( $inventory[$plugin_type][$expected_name] ?? null ) === $expected_type) {
                            ++$matches;
                        }
                    }
                }

                if ($signal_count > 0 && $matches === $signal_count) {
                    return $signal_count === 1 ? 0.6 : 0.9;
                }
                if ($matches > 0) {
                    $has_partial_match = true;
                }
            }
        }

        return $has_partial_match ? 0.3 : 0.0;
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest(static::SOURCE);
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = extract_constants($preflight_data);
        return $manifest;
    }

    /**
     * @return array<int, array<string, array<string, string>>> Plugin types keyed by root, inventory, and name.
     */
    protected static function read_plugin_inventories(array $preflight_data): array
    {
        $inventories = [];

        foreach ($preflight_data['wp_content']['roots'] ?? [] as $root) {
            $inventory = [
                'plugins' => [],
                'mu_plugins' => [],
            ];
            foreach (array_keys($inventory) as $plugin_type) {
                foreach ($root[$plugin_type] ?? [] as $plugin) {
                    $name = $plugin['name'] ?? null;
                    $type = $plugin['type'] ?? null;
                    if (is_string($name) && is_string($type)) {
                        $inventory[$plugin_type][$name] = $type;
                    }
                }
            }
            $inventories[] = $inventory;
        }

        return $inventories;
    }
}
