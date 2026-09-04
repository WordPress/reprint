<?php
/** Host analyzer for Bluehost exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class BluehostHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'bluehost';
    protected const SIGNAL_SETS = [
        [
            'plugins' => ['bluehost-wordpress-plugin' => 'dir'],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/plugins/bluehost-wordpress-plugin',
        'wp-content/mu-plugins/endurance-page-cache.php',
        'wp-content/mu-plugins/endurance-browser-cache.php',
    ];
}
