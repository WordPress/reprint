<?php
/** Host analyzer for SpinupWP exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class SpinupwpHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'spinupwp';
    protected const SIGNAL_SETS = [
        [
            'plugins' => ['spinupwp' => 'dir'],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/plugins/spinupwp',
    ];
}
