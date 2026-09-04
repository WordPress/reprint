<?php
/** Host analyzer for Hostinger exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class HostingerHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'hostinger';
    protected const SIGNAL_SETS = [
        [
            'plugins' => ['hostinger' => 'dir'],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/plugins/hostinger',
        'wp-content/plugins/hostinger-easy-onboarding',
        'wp-content/mu-plugins/hostinger-mu-plugin.php',
    ];
}
