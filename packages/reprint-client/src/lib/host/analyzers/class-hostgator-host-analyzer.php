<?php
/** Host analyzer for HostGator exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class HostgatorHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'hostgator';
    protected const SIGNAL_SETS = [
        [
            'plugins' => ['wp-plugin-hostgator' => 'dir'],
        ],
    ];
}
