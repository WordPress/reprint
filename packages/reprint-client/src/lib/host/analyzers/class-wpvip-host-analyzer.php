<?php
/** Host analyzer for WordPress VIP exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class WpVipHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'wpvip';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => ['vip-go-mu-plugins' => 'dir'],
        ],
    ];
}
