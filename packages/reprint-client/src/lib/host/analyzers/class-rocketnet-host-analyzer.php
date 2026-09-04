<?php
/** Host analyzer for Rocket.net exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class RocketnetHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'rocketnet';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => ['cdn-cache-management.php' => 'file'],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/cdn-cache-management.php',
    ];
}
