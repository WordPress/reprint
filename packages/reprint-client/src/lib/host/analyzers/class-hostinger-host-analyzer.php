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
}
