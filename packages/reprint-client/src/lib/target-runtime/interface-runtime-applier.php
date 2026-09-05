<?php
/**
 * Interface for runtime appliers.
 *
 * A runtime applier takes a RuntimeManifest and writes the configuration
 * files needed to run the imported site on a specific server platform.
 *
 * Use RuntimeAppliers::for_runtime() to instantiate one by name.
 */
interface RuntimeApplier
{
    /**
     * Apply a manifest to the target fs-root.
     *
     * @param RuntimeManifest $manifest   The manifest to apply.
     * @param string          $filesystem_root    Absolute path to the site fs-root.
     * @param string          $output_dir Absolute path to the output directory.
     * @param array           $options    Runtime-specific options (e.g. host, port,
     *                                    wordpress_index_php).
     * @return string[] Human-readable summary lines (printed to the user).
     */
    public function apply(RuntimeManifest $manifest, string $filesystem_root, string $output_dir, array $options = []): array;
}
