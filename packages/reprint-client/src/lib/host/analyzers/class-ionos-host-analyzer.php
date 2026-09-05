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
}
