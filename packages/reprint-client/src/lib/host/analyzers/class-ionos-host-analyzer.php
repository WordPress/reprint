<?php
/** Host analyzer for IONOS exports. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Host analyzers use established global class names.
class IonosHostAnalyzer extends PlatformFilesHostAnalyzer {
    protected const SOURCE = 'ionos';
    protected const SIGNAL_SETS = [
        [
            'mu_plugins' => [
                'ionos-core.php' => 'file',
                'ionos-core' => 'dir',
            ],
        ],
        [
            'mu_plugins' => [
                'stretch-extra.php' => 'file',
                'stretch-extra' => 'dir',
            ],
        ],
    ];
    protected const PATHS_TO_REMOVE = [
        'wp-content/mu-plugins/ionos-core.php',
        'wp-content/mu-plugins/ionos-core',
        'wp-content/mu-plugins/stretch-extra.php',
        'wp-content/mu-plugins/stretch-extra',
        'wp-content/plugins/ionos-essentials',
        'wp-content/plugins/ionos-wpdev-caddy',
    ];
}
