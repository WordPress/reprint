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
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/pcm-extend-batcache.php',
        'wp-content/mu-plugins/pcm-exclude-pages-from-batcache.php',
        'wp-content/plugins/pressable-cache-management',
        'wp-content/plugins/pressable-onepress-login',
    ];
}
