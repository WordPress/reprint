<?php
/** Host analyzer for GoDaddy Managed WordPress exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class GodaddyHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'godaddy';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => [
                'gd-system-plugin.php' => 'file',
                'gd-system-plugin' => 'dir',
            ],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/gd-system-plugin.php',
        'wp-content/mu-plugins/gd-system-plugin',
    ];
}
