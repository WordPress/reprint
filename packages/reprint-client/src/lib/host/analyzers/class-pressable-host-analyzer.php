<?php
/** Host analyzer for Pressable exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class PressableHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'pressable';
    protected const SIGNAL_SETS = [
        [
            'plugins' => [
                'pressable-cache-management' => 'dir',
                'pressable-onepress-login' => 'dir',
            ],
        ],
        [
            'plugins' => ['pressable-cache-management' => 'dir'],
            'mu_plugins' => ['pcm-extend-batcache.php' => 'file'],
        ],
    ];
}
