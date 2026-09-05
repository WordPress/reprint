<?php
/** Host analyzer for Kinsta exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class KinstaHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'kinsta';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => [
                'kinsta-mu-plugins.php' => 'file',
                'kinsta-mu-plugins' => 'dir',
            ],
        ],
    ];
}
