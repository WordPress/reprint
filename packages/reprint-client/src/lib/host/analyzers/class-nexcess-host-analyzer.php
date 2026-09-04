<?php
/** Host analyzer for Nexcess Managed WordPress exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class NexcessHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'nexcess';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => [
                'nexcess-mapps.php' => 'file',
                'nexcess-mapps' => 'dir',
            ],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/nexcess-mapps.php',
        'wp-content/mu-plugins/nexcess-mapps',
    ];
}
