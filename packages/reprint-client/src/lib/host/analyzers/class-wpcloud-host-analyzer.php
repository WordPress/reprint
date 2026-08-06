<?php
/**
 * Host analyzer for WP Cloud (wpcom) sites.
 *
 * WP Cloud sites have a non-standard directory layout: WordPress core lives
 * at a versioned path (/wordpress/core/X.Y.Z/) and wp-content is a separate
 * tree under /srv/htdocs/. After flat-docroot, the local layout uses a
 * __wp__ directory for core.
 *
 * The export only ships original-size uploads, so the manifest declares a
 * route for thumbnail-sized image URLs. The target runtime decides how to
 * implement the handler.
 */
class WpcloudHostAnalyzer implements HostAnalyzer
{
    /**
     * Score how likely the source site is a WP Cloud site.
     *
     * Signals:
     * - __wp__ directory exists in the document root (0.5)
     * - WordPress detected at doc_root/__wp__/ (0.4)
     * - PRIVACY_MODEL environment variable is defined (0.5)
     */
    public static function score(array $preflight_data): float
    {
        $score = 0.0;

        $doc_root = $preflight_data['runtime']['document_root'] ?? null;

        // Signal: __wp__ directory exists in the document root.
        // WP Cloud sites have a __wp__ symlink in the document root pointing
        // to the WordPress core installation.
        if (is_string($doc_root) && $doc_root !== '') {
            $wp_dir = rtrim($doc_root, '/') . '/__wp__';
            $dir_checks = $preflight_data['filesystem']['directories'] ?? [];
            foreach ($dir_checks as $check) {
                $path = $check['path'] ?? '';
                if ($path === $wp_dir && ($check['exists'] ?? false)) {
                    $score += 0.5;
                    break;
                }
            }
        }

        // Signal: WordPress detected at __wp__ inside the document root.
        // WP Cloud typically has WordPress installed at doc_root/__wp__/.
        $wp_roots = $preflight_data['wp_detect']['roots'] ?? [];
        if (is_string($doc_root) && $doc_root !== '') {
            $wp_subdir = rtrim($doc_root, '/') . '/__wp__';
            foreach ($wp_roots as $root) {
                $path = $root['path'] ?? '';
                if ($path === $wp_subdir) {
                    $score += 0.4;
                    break;
                }
            }
        }

        // Signal: PRIVACY_MODEL environment variable is defined.
        // WP Cloud sets this env var; its mere presence is a strong hint.
        $env_names = $preflight_data['runtime']['env_names'] ?? [];
        if (in_array('PRIVACY_MODEL', $env_names, true)) {
            $score += 0.5;
        }

        return min($score, 1.0);
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('wpcloud');
        $manifest->php_ini = extract_php_ini($preflight_data);
        $manifest->constants = $this->build_constants($preflight_data);
        $manifest->server_vars = [
            // WP Cloud uses a __wp__ directory for WordPress core. After
            // flat-docroot, it lives at {fs-root}/__wp__/.
            'WP_DIR' => '{fs-root}/__wp__/',
        ];

        // WP Cloud exports only ship full-size uploads. WordPress post meta
        // references sized variants like image-768x768.jpeg that don't exist
        // on disk. Declare a route so the target runtime can generate them
        // on-the-fly from the originals.
        $manifest->routes[] = [
            'handler' => 'wpcloud-thumbnail-generator',
            'path_pattern' => '/wp-content/uploads/.*-\d+x\d+\.\w+$',
            'condition' => 'file_not_found',
            'description' => 'Generate missing WordPress thumbnail sizes from originals using GD',
        ];

        // Production drop-ins and mu-plugins that depend on WP Cloud
        // infrastructure: object-cache.php and advanced-cache.php talks
        // to a Memcached server that doesn't exist locally, wpcomsh*
        // mu-plugins depend on multisite functions and wp.com API endpoints.
        //
        // TODO: Consider removing all drop-ins unconditionally, not just
        // WP Cloud ones. Drop-ins (object-cache.php, advanced-cache.php,
        // db.php, etc.) typically integrate platform-specific software
        // (Memcached, Redis, custom DB layers) that won't be available
        // in a local environment. This is host-specific today, but the
        // problem is universal.
        $manifest->paths_to_remove = [
            'wp-content/object-cache.php',
            'wp-content/advanced-cache.php',
            'wp-content/mu-plugins/wpcomsh',
            'wp-content/mu-plugins/wpcomsh-dev',
            'wp-content/mu-plugins/wpcomsh-loader.php',
        ];

        // auto_prepend_file on Atomic points to /scripts/env.php — a
        // directory outside the WordPress roots.  Record it so that
        // files-pull downloads it and the runtime applier can mount it.
        $manifest->extra_directories = $this->detect_extra_directories($preflight_data);

        return $manifest;
    }

    /**
     * Detect directories outside the WordPress roots that need to be
     * downloaded and mounted.  Currently reads auto_prepend_file and
     * auto_append_file from PHP INI.
     *
     * @return string[]  Absolute remote directory paths (e.g. ['/scripts'])
     */
    private function detect_extra_directories(array $preflight_data): array
    {
        $ini_all = $preflight_data['runtime']['ini_get_all'] ?? [];
        $dirs = [];

        foreach (['auto_prepend_file', 'auto_append_file'] as $key) {
            $path = $ini_all[$key] ?? '';
            if (!is_string($path) || $path === '' || $path[0] !== '/') {
                continue;
            }
            $dir = rtrim(dirname($path), '/');
            if ($dir !== '' && $dir !== '/') {
                $dirs[] = $dir;
            }
        }

        return array_values(array_unique($dirs));
    }

    private function build_constants(array $preflight_data): array
    {
        $result = extract_constants($preflight_data);

        // WP Cloud always needs THEMES_PATH_BASE since themes are under
        // wp-content, not under the __wp__ ABSPATH.
        $result['THEMES_PATH_BASE'] = '{fs-root}/wp-content/themes';

        return $result;
    }
}
