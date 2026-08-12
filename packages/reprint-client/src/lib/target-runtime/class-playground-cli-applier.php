<?php

use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * Runtime applier for WordPress Playground CLI (@wp-playground/cli).
 *
 * Writes:
 * 1. {output_dir}/runtime.php     — constants, server vars, route handlers
 * 2. {output_dir}/blueprint.json  — minimal Playground Blueprint
 * 3. {output_dir}/start.sh        — shell script with the full CLI invocation
 * 4. {output_dir}/start.json      — structured config for programmatic consumers
 *
 * Unlike nginx-fpm and php-builtin which write server config files,
 * Playground CLI handles most configuration through command-line flags.
 * The start.sh script uses --mount-before-install to place the imported
 * files at /wordpress in the VFS, and --follow-symlinks to let Playground
 * resolve any symlinks within the mounted directories.
 *
 * For WPCloud and similar hosts where WordPress core lives in a separate
 * directory from the document root, the applier creates three mounts:
 * WordPress core at /wordpress, wp-content and wp-config.php from the
 * document root overlaid on top. Symlinks inside wp-content (themes,
 * plugins, mu-plugins pointing to shared host directories) are resolved
 * automatically by Playground's --follow-symlinks flag.
 *
 * runtime.php uses /wordpress as the fs-root (the VFS path inside
 * Playground), not the host filesystem path.
 *
 * SQLite is handled natively by Playground — the applier does NOT
 * generate the custom lazy-loader proxy that other runtimes use.
 *
 * The developer just runs: bash {output_dir}/start.sh
 */
class PlaygroundCliApplier implements RuntimeApplier
{
    /**
     * Playground's internal document root path in the virtual filesystem.
     */
    private const VFS_ROOT = '/wordpress';

    public function apply(RuntimeManifest $manifest, string $filesystem_root, string $output_dir, array $options = []): array
    {
        $port = (int) ($options['port'] ?? 9400);

        $summary = [];

        // Playground handles SQLite natively — suppress the custom
        // lazy-loader proxy that other runtimes (nginx-fpm, php-builtin)
        // need. Without this, our loader conflicts with Playground's own
        // SQLite integration and causes "Error connecting to the SQLite
        // database" during boot.
        $saved_sqlite = $manifest->sqlite;
        $manifest->sqlite = null;

        // Route handlers that need importer state must use VFS paths inside
        // Playground. Rewrite those constants and mount the underlying host
        // files into the VM before we generate runtime.php/start.sh.
        $extra_mounts = $this->prepare_extra_mounts($manifest);

        // 1. Write runtime.php using the VFS path (/wordpress) as fs-root.
        //    Inside Playground, the host's fs-root is mounted at /wordpress,
        //    so all resolved {fs-root} paths must reference /wordpress.
        $runtime_path = wp_join_unix_paths($output_dir, 'runtime.php');
        $runtime = generate_runtime_php($manifest, self::VFS_ROOT);

        // Suppress display of PHP warnings and deprecation notices.
        // Imported sites often have broken symlinks to host-specific
        // mu-plugins (e.g. WPCloud's wpcomsh-loader.php) and plugins
        // with deprecated syntax. These are expected in an imported
        // environment and shouldn't clutter the output.
        $runtime = str_replace(
            "<?php\n",
            "<?php\n@ini_set('display_errors', '0');\n",
            $runtime,
        );

        write_runtime_file($runtime_path, $runtime);
        $summary[] = "Wrote {$runtime_path}";

        // Restore sqlite on the manifest so the caller can still report it.
        $manifest->sqlite = $saved_sqlite;

        // 2. Write blueprint.json (minimal — most config is in start.sh flags)
        $blueprint_path = wp_join_unix_paths($output_dir, 'blueprint.json');
        $blueprint = $this->generate_blueprint();
        write_runtime_file($blueprint_path, json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $summary[] = "Wrote {$blueprint_path}";

        // 3. Write start.sh
        $start_path = wp_join_unix_paths($output_dir, 'start.sh');
        $start_script = $this->generate_start_script(
            $manifest,
            $filesystem_root,
            $output_dir,
            $runtime_path,
            $port,
            $options,
            $extra_mounts,
        );
        write_runtime_file($start_path, $start_script);
        chmod($start_path, 0755);
        $summary[] = "Wrote {$start_path}";

        // 4. Write start.json — structured config for programmatic consumers
        // (e.g. Studio). The source paths are as seen by this PHP process;
        // callers running inside a VFS must map them to host paths.
        $config_path = wp_join_unix_paths($output_dir, 'start.json');
        $config = $this->generate_start_config(
            $filesystem_root,
            $output_dir,
            $runtime_path,
            $port,
            $options,
            $extra_mounts,
        );
        write_runtime_file($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $summary[] = "Wrote {$config_path}";

        $summary[] = '';
        $summary[] = 'Start the server:';
        $summary[] = "  bash {$start_path}";
        $summary[] = '';
        $summary[] = "Then open http://127.0.0.1:{$port} in your browser.";

        return $summary;
    }

    /**
     * Generate a minimal Playground Blueprint.
     *
     * Most configuration happens through CLI flags in start.sh rather
     * than Blueprint properties — the Blueprint just sets the landing
     * page and marks this as a schema-valid file.
     */
    private function generate_blueprint(): array
    {
        return [
            '$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
        ];
    }

    /**
     * Rewrite host-side runtime helper files to VFS paths and return the
     * corresponding Playground mounts.
     *
     * Some route handlers need files produced outside the mounted WordPress
     * tree. Playground can only see files that are mounted explicitly, so we
     * map those constants to /tmp paths in the VM.
     *
     * @return string[]
     */
    private function prepare_extra_mounts(RuntimeManifest $manifest): array
    {
        $mounts = [];
        $runtime_file_mounts = [
            'REPRINT_PULL_STATE_FILE'
                => '/tmp/reprint/state.json',
        ];

        foreach ($runtime_file_mounts as $constant_name => $vfs_path) {
            $host_path = $manifest->constants[$constant_name] ?? null;
            if (!is_string($host_path) || $host_path === '') {
                continue;
            }

            $manifest->constants[$constant_name] = $vfs_path;
            if (file_exists($host_path)) {
                $mounts[] = $host_path . ':' . $vfs_path;
            }
        }

        return $mounts;
    }

    /**
     * Build the list of --mount-before-install flags that assemble a
     * standard WordPress layout at /wordpress in Playground's VFS.
     *
     * For standard WordPress sites where the document root IS the
     * WordPress directory, a single mount covers everything.
     *
     * For WPCloud and similar hosts where WordPress core lives in a
     * separate directory (ABSPATH != document_root), we need three
     * mounts to assemble the layout:
     * 1. WordPress core → /wordpress (index.php, wp-load.php, wp-admin/,
     *    wp-includes/, wp-settings.php)
     * 2. Document root's wp-content → /wordpress/wp-content (content,
     *    plugins, themes, uploads)
     * 3. Document root's wp-config.php → /wordpress/wp-config.php
     *
     * Symlinks within wp-content (themes, plugins, mu-plugins pointing
     * to shared host directories) are resolved by Playground's
     * --follow-symlinks flag — no per-symlink mounts needed.
     */
    private function build_mounts(string $filesystem_root, array $options): array
    {
        $mounts = [];

        $wordpress_index_php = $options['wordpress_index_php'] ?? '';
        $wordpress_core_dir = '';

        if ($wordpress_index_php !== '') {
            // Resolve through any symlinks to get the real path.
            $real_index = realpath($wordpress_index_php);
            if ($real_index !== false) {
                $wordpress_core_dir = dirname($real_index);
            }
        }

        $real_fs_root = realpath($filesystem_root) ?: $filesystem_root;

        if ($wordpress_core_dir !== '' && $wordpress_core_dir !== $real_fs_root) {
            // WPCloud-style layout: WordPress core is separate from the
            // document root. Three mounts assemble the standard layout.
            $mounts[] = $wordpress_core_dir . ':/wordpress';

            $wp_content = wp_join_unix_paths($real_fs_root, 'wp-content');
            if (is_dir($wp_content)) {
                $mounts[] = $wp_content . ':/wordpress/wp-content';
            }

            $wp_config = wp_join_unix_paths($real_fs_root, 'wp-config.php');
            if (file_exists($wp_config)) {
                $mounts[] = $wp_config . ':/wordpress/wp-config.php';
            }
        } else {
            // Standard layout: the document root IS the WordPress
            // directory. One mount covers everything.
            $mounts[] = $real_fs_root . ':/wordpress';
        }

        return $mounts;
    }

    /**
     * Build a structured config describing the Playground CLI invocation.
     *
     * Programmatic consumers (e.g. Studio) read this instead of parsing
     * start.sh, and map the source paths from whatever filesystem the
     * importer ran on to the actual host paths.
     */
    private function generate_start_config(
        string $filesystem_root,
        string $output_dir,
        string $runtime_path,
        int $port,
        array $options,
        array $extra_mounts = []
    ): array {
        $config = [
            'document_root' => self::VFS_ROOT,
            'mounts_before_install' => [],
            'mounts' => [],
            'blueprint' => wp_join_unix_paths($output_dir, 'blueprint.json'),
            'port' => $port,
            'follow_symlinks' => true,
            'wordpress_install_mode' => 'do-not-attempt-installing',
        ];

        // WordPress layout mounts. For standard sites this is a single
        // mount. For WPCloud-style sites, three mounts assemble a standard
        // layout from separate directories.
        foreach ($this->build_mounts($filesystem_root, $options) as $mount) {
            [$source, $target] = explode(':', $mount, 2);
            $config['mounts_before_install'][] = [
                'source' => $source,
                'target' => $target,
            ];
        }

        // Runtime mu-plugin. The 0- prefix ensures it loads before other
        // mu-plugins. mu-plugins don't need a Plugin Name header.
        $config['mounts'][] = [
            'source' => $runtime_path,
            'target' => self::VFS_ROOT . '/wp-content/mu-plugins/0-playground-runtime.php',
        ];

        // Additional runtime helper files (e.g. state files for the
        // remote upload proxy).
        foreach ($extra_mounts as $mount) {
            [$source, $target] = explode(':', $mount, 2);
            $config['mounts'][] = [
                'source' => $source,
                'target' => $target,
            ];
        }

        return $config;
    }

    /**
     * Generate a shell script that starts Playground CLI with the right
     * mount points and flags.
     *
     * Derived from generate_start_config() so start.sh and start.json
     * always describe the same invocation.
     */
    private function generate_start_script(
        RuntimeManifest $manifest,
        string $filesystem_root,
        string $output_dir,
        string $runtime_path,
        int $port,
        array $options,
        array $extra_mounts = []
    ): string {
        $config = $this->generate_start_config($filesystem_root, $output_dir, $runtime_path, $port, $options, $extra_mounts);

        $lines = [];
        $lines[] = '#!/usr/bin/env bash';
        $lines[] = '# Start WordPress Playground CLI for the imported site.';
        $lines[] = '# Generated by apply-runtime — do not edit.';
        $lines[] = '#';
        $lines[] = '# Source host: ' . $manifest->source;
        $lines[] = '';
        $lines[] = 'set -euo pipefail';
        $lines[] = '';

        $args = [];
        $args[] = 'npx @wp-playground/cli@latest server';

        // Mount the WordPress directory layout. For standard sites this
        // is a single mount. For WPCloud-style sites, three mounts
        // assemble a standard layout from separate directories.
        foreach ($config['mounts_before_install'] as $mount) {
            $args[] = '--mount-before-install=' . escapeshellarg($mount['source'] . ':' . $mount['target']);
        }

        // Mount runtime.php as a mu-plugin (0- prefix ensures it loads
        // first) and any additional runtime helper files (e.g. state
        // files for the remote upload proxy).
        foreach ($config['mounts'] as $mount) {
            $args[] = '--mount=' . escapeshellarg($mount['source'] . ':' . $mount['target']);
        }

        // The site is already installed — don't run Playground's WordPress
        // installer or download a fresh copy.
        $args[] = '--wordpress-install-mode=' . $config['wordpress_install_mode'];

        // Let Playground resolve symlinks within mounted directories.
        // WPCloud sites have symlinks in wp-content/themes, plugins, and
        // mu-plugins pointing to shared host directories — this flag
        // makes them work without needing individual mounts for each.
        if ($config['follow_symlinks']) {
            $args[] = '--follow-symlinks';
        }

        $args[] = '--blueprint=' . escapeshellarg($config['blueprint']);
        $args[] = '--port=' . $config['port'];

        $lines[] = 'echo "Starting WordPress Playground CLI..."';
        $lines[] = 'echo "  http://127.0.0.1:' . $config['port'] . '"';
        $lines[] = 'echo ""';
        $lines[] = '';
        $lines[] = implode(" \\\n    ", $args);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
