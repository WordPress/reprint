<?php
/** Host analyzer for Pantheon exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class PantheonHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'pantheon';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => [
                'loader.php' => 'file',
                'pantheon-mu-plugin' => 'dir',
            ],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/pantheon-mu-plugin',
    ];
}
