<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class HostRuntimeManifestTest extends TestCase {
    public function testRegistryContainsOnlyHostsWithRuntimeSpecificBehavior(): void
    {
        $this->assertSame(
            [
                'wpcloud' => \WpcloudHostAnalyzer::class,
                'wpengine' => \WpengineHostAnalyzer::class,
            ],
            \host_analyzer_registry(),
        );
    }

    public function testPathsRemovedFromEveryImportDoNotSelectAHost(): void
    {
        $preflight_data = $this->preflight(
            [
                'aruba-hispeed-cache',
                'bluehost-wordpress-plugin',
                'hostinger',
                'pressable-cache-management',
                'pressable-onepress-login',
                'sg-cachepress',
                'sg-security',
                'spinupwp',
                'wp-plugin-hostgator',
            ],
            [
                'aruba-wpchecker.php',
                'aruba-wpchecker',
                'gd-system-plugin.php',
                'gd-system-plugin',
                'ionos-core.php',
                'ionos-core',
                'kinsta-mu-plugins.php',
                'kinsta-mu-plugins',
                'loader.php',
                'nexcess-mapps.php',
                'nexcess-mapps',
                'pantheon-mu-plugin',
                'vip-go-mu-plugins',
                'wpengine-common',
                'wpe-cache-plugin',
                'wpe-update-source-selector',
                'wpe-wp-sign-on-plugin',
                'wpengine-security-auditor.php',
            ],
        );

        $this->assertSame('other', \detect_host($preflight_data));
    }

    public function testRuntimeManifestListsEverySourceHostPathRemovedFromAllImports(): void
    {
        $manifest = \runtime_manifest_for($this->preflight([], []));

        $this->assertSame(
            [
                'wp-content/plugins/nginx-helper',
                'wp-content/plugins/redis-cache',
                'wp-content/plugins/breeze',
                'wp-content/plugins/object-cache-pro',
                'wp-content/plugins/wp-rocket',
                'wp-content/plugins/w3-total-cache',
                'wp-content/plugins/servebolt-optimizer',
                'wp-content/plugins/a2-optimized-wp',
                'wp-content/plugins/boldgrid-backup',
                'wp-content/plugins/litespeed-cache',
                'wp-content/plugins/aruba-hispeed-cache',
                'wp-content/mu-plugins/aruba-wpchecker.php',
                'wp-content/mu-plugins/aruba-wpchecker',
                'wp-content/mu-plugins/kinsta-mu-plugins.php',
                'wp-content/mu-plugins/kinsta-mu-plugins',
                'wp-content/mu-plugins/pantheon-mu-plugin',
                'wp-content/mu-plugins/ionos-core.php',
                'wp-content/mu-plugins/ionos-core',
                'wp-content/mu-plugins/stretch-extra.php',
                'wp-content/mu-plugins/stretch-extra',
                'wp-content/plugins/ionos-essentials',
                'wp-content/plugins/ionos-wpdev-caddy',
                'wp-content/mu-plugins/pcm-extend-batcache.php',
                'wp-content/mu-plugins/pcm-exclude-pages-from-batcache.php',
                'wp-content/plugins/pressable-cache-management',
                'wp-content/plugins/pressable-onepress-login',
                'wp-content/mu-plugins/gd-system-plugin.php',
                'wp-content/mu-plugins/gd-system-plugin',
                'wp-content/plugins/bluehost-wordpress-plugin',
                'wp-content/mu-plugins/endurance-page-cache.php',
                'wp-content/mu-plugins/endurance-browser-cache.php',
                'wp-content/plugins/wp-plugin-hostgator',
                'wp-content/plugins/hostinger',
                'wp-content/plugins/hostinger-easy-onboarding',
                'wp-content/mu-plugins/hostinger-mu-plugin.php',
                'wp-content/mu-plugins/nexcess-mapps.php',
                'wp-content/mu-plugins/nexcess-mapps',
                'wp-content/mu-plugins/cdn-cache-management.php',
                'wp-content/plugins/spinupwp',
                'wp-content/mu-plugins/vip-go-mu-plugins',
                'wp-content/plugins/wp-engine-smart-plugin-manager',
                'wp-content/mu-plugins/wpengine-common',
                'wp-content/mu-plugins/slt-force-strong-passwords.php',
                'wp-content/mu-plugins/force-strong-passwords',
                'wp-content/mu-plugins/stop-long-comments.php',
                'wp-content/mu-plugins/wpe-cache-plugin',
                'wp-content/mu-plugins/wpe-cache-plugin.php',
                'wp-content/mu-plugins/wpe-update-source-selector',
                'wp-content/mu-plugins/wpe-update-source-selector.php',
                'wp-content/mu-plugins/wpe-wp-sign-on-plugin',
                'wp-content/mu-plugins/wpe-wp-sign-on-plugin.php',
                'wp-content/mu-plugins/wpengine-security-auditor.php',
                'wp-content/plugins/sg-cachepress',
                'wp-content/plugins/sg-security',
                'wp-content/mu-plugins/wpcomsh',
                'wp-content/mu-plugins/wpcomsh-dev',
                'wp-content/mu-plugins/wpcomsh-loader.php',
            ],
            $manifest->paths_to_remove,
        );
    }

    public function testWpcloudRuntimeAndWpengineCleanupAreAppliedIndependently(): void
    {
        $preflight_data = $this->preflight(
            [],
            [],
        );
        $preflight_data['runtime'] = [
            'document_root' => '/srv/htdocs',
            'script_filename' => '/nas/content/live/example/index.php',
            'env_names' => ['PRIVACY_MODEL'],
            'ini_get_all' => [
                'memory_limit' => '256M',
                'auto_prepend_file' => '/scripts/env.php',
            ],
        ];
        $preflight_data['filesystem']['directories'] = [
            ['path' => '/srv/htdocs/__wp__', 'exists' => true],
        ];
        $preflight_data['wp_detect']['roots'] = [
            ['path' => '/srv/htdocs/__wp__'],
        ];
        $preflight_data['database']['wp']['paths_urls'] = [
            'abspath' => '/wordpress/core/6.7.2/',
            'content_dir' => '/srv/htdocs/wp-content',
        ];

        $this->assertSame('wpcloud', \detect_host($preflight_data));

        $manifest = \runtime_manifest_for($preflight_data);
        $this->assertSame('wpcloud', $manifest->source);
        $this->assertSame('{fs-root}/__wp__/', $manifest->server_vars['WP_DIR']);
        $this->assertSame('{fs-root}/wp-content/themes', $manifest->constants['THEMES_PATH_BASE']);
        $this->assertContains('/scripts', $manifest->extra_directories);
        $this->assertContains(
            'wpcloud-thumbnail-generator',
            array_column($manifest->routes, 'handler'),
        );
        $this->assertContains('wp-content/object-cache.php', $manifest->paths_to_remove);
        $this->assertContains('wp-content/advanced-cache.php', $manifest->paths_to_remove);
        $this->assertContains('wp-content/mu-plugins/mu-plugin.php', $manifest->paths_to_remove);
    }

    public function testNamedHostPluginsUseDefaultRuntimeBehavior(): void
    {
        $preflight_data = $this->preflight(['sg-cachepress', 'sg-security'], []);

        $manifest = \runtime_manifest_for($preflight_data);

        $this->assertSame('other', $manifest->source);
        $this->assertSame(['memory_limit' => '256M'], $manifest->php_ini);
        $this->assertContains('wp-content/plugins/sg-cachepress', $manifest->paths_to_remove);
        $this->assertNotContains('wp-content/object-cache.php', $manifest->paths_to_remove);
        $this->assertNotContains('wp-content/mu-plugins/mu-plugin.php', $manifest->paths_to_remove);
    }

    /**
     * Build the part of preflight which host analyzers read.
     *
     * @param string[] $plugins    Regular plugin entry names.
     * @param string[] $mu_plugins MU-plugin entry names.
     */
    private function preflight(array $plugins, array $mu_plugins): array
    {
        return [
            'runtime' => [
                'ini_get_all' => [
                    'memory_limit' => '256M',
                ],
            ],
            'wp_content' => [
                'roots' => [
                    [
                        'plugins' => $this->inventoryEntries($plugins),
                        'mu_plugins' => $this->inventoryEntries($mu_plugins),
                    ],
                ],
            ],
            'database' => [
                'wp' => [
                    'paths_urls' => [
                        'abspath' => '/var/www/html/',
                        'content_dir' => '/var/www/html/wp-content',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param string[] $names Inventory entry names.
     * @return array<int, array{name: string, type: string}>
     */
    private function inventoryEntries(array $names): array
    {
        return array_map(
            static function (string $name): array {
                return [
                    'name' => $name,
                    'type' => substr($name, -4) === '.php' ? 'file' : 'dir',
                ];
            },
            $names,
        );
    }
}
