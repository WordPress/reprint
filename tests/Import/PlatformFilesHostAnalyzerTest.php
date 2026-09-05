<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class PlatformFilesHostAnalyzerTest extends TestCase {
    /**
     * @dataProvider detectedHostProvider
     *
     * @param string[] $plugins    Regular plugin entry names.
     * @param string[] $mu_plugins MU-plugin entry names.
     */
    public function testDetectsHostAndBuildsRuntimeManifest(
        string $expected_host,
        array $plugins,
        array $mu_plugins
    ): void {
        $preflight_data = $this->preflight($plugins, $mu_plugins);

        $this->assertSame($expected_host, \detect_host($preflight_data));

        $manifest = \host_analyzer_for($expected_host)->analyze($preflight_data);
        $this->assertSame($expected_host, $manifest->source);
        $this->assertSame(['memory_limit' => '256M'], $manifest->php_ini);
        $this->assertSame([], $manifest->paths_to_remove);
    }

    public static function detectedHostProvider(): array
    {
        return [
            'Kinsta loader and directory' => [
                'kinsta',
                [],
                ['kinsta-mu-plugins.php', 'kinsta-mu-plugins'],
            ],
            'Pantheon loader and directory' => [
                'pantheon',
                [],
                ['loader.php', 'pantheon-mu-plugin'],
            ],
            'IONOS core loader and directory' => [
                'ionos',
                ['ionos-essentials', 'ionos-wpdev-caddy'],
                ['ionos-core.php', 'ionos-core'],
            ],
            'IONOS shared platform loader and directory' => [
                'ionos',
                [],
                ['stretch-extra.php', 'stretch-extra'],
            ],
            'Pressable cache and sign-on plugins' => [
                'pressable',
                ['pressable-cache-management', 'pressable-onepress-login'],
                ['pcm-extend-batcache.php', 'my-customer-plugin.php'],
            ],
            'GoDaddy system plugin' => [
                'godaddy',
                [],
                ['gd-system-plugin.php', 'gd-system-plugin'],
            ],
            'Bluehost control plugin' => [
                'bluehost',
                ['bluehost-wordpress-plugin'],
                ['endurance-page-cache.php', 'customer-tools.php'],
            ],
            'HostGator control plugin' => [
                'hostgator',
                ['wp-plugin-hostgator'],
                ['endurance-page-cache.php'],
            ],
            'Hostinger control plugin' => [
                'hostinger',
                ['hostinger', 'hostinger-easy-onboarding'],
                ['hostinger-mu-plugin.php'],
            ],
            'Nexcess MAPPS loader and directory' => [
                'nexcess',
                [],
                ['nexcess-mapps.php', 'nexcess-mapps', 'customer-loader.php'],
            ],
            'Rocket.net cache plugin' => [
                'rocketnet',
                [],
                ['cdn-cache-management.php'],
            ],
            'SpinupWP control plugin' => [
                'spinupwp',
                ['spinupwp'],
                [],
            ],
            'WordPress VIP platform MU directory' => [
                'wpvip',
                [],
                ['vip-go-mu-plugins', 'client-mu-plugins'],
            ],
        ];
    }

    /**
     * @dataProvider incompletePlatformSignalProvider
     *
     * @param string[] $plugins    Regular plugin entry names.
     * @param string[] $mu_plugins MU-plugin entry names.
     */
    public function testIncompletePlatformFilePairsDoNotSelectAHost(
        array $plugins,
        array $mu_plugins
    ): void {
        $this->assertSame('other', \detect_host($this->preflight($plugins, $mu_plugins)));
    }

    public static function incompletePlatformSignalProvider(): array
    {
        return [
            'generic loader without Pantheon directory' => [[], ['loader.php']],
            'Kinsta directory without loader' => [[], ['kinsta-mu-plugins']],
            'IONOS loader without directory' => [[], ['ionos-core.php']],
            'GoDaddy directory without loader' => [[], ['gd-system-plugin']],
            'Nexcess loader without directory' => [[], ['nexcess-mapps.php']],
            'Pressable prefix without a platform plugin' => [[], ['pcm-customer-code.php']],
        ];
    }

    public function testSignalsFromDifferentWordpressRootsDoNotFormAPlatformPair(): void
    {
        $preflight_data = $this->preflight([], []);
        $preflight_data['wp_content']['roots'] = [
            [
                'plugins' => [],
                'mu_plugins' => $this->inventoryEntries(['kinsta-mu-plugins.php']),
            ],
            [
                'plugins' => [],
                'mu_plugins' => $this->inventoryEntries(['kinsta-mu-plugins']),
            ],
        ];

        $this->assertSame('other', \detect_host($preflight_data));
    }

    public function testSignalWithWrongPathTypeDoesNotSelectAHost(): void
    {
        $preflight_data = $this->preflight([], ['kinsta-mu-plugins.php', 'kinsta-mu-plugins']);
        $preflight_data['wp_content']['roots'][0]['mu_plugins'][0]['type'] = 'dir';

        $this->assertSame('other', \detect_host($preflight_data));
    }

    public function testCompletePlatformPairOutranksOnePlatformPlugin(): void
    {
        $preflight_data = $this->preflight(
            ['spinupwp'],
            ['kinsta-mu-plugins.php', 'kinsta-mu-plugins'],
        );

        $this->assertSame('kinsta', \detect_host($preflight_data));
    }

    public function testRegistryKeysMatchManifestSources(): void
    {
        $preflight_data = $this->preflight([], []);

        foreach (\host_analyzer_registry() as $host => $analyzer_class) {
            $manifest = ( new $analyzer_class() )->analyze($preflight_data);
            $this->assertSame($host, $manifest->source);
        }
    }

    public function testRuntimeManifestListsEverySourceHostPathRemovedFromAllImports(): void
    {
        $manifest = \runtime_manifest_for('other', $this->preflight([], []));

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

    public function testRuntimeManifestRemovesStaleVendorPathsFromAnotherHost(): void
    {
        $preflight_data = $this->preflight(
            [],
            ['kinsta-mu-plugins.php', 'kinsta-mu-plugins', 'wpe-cache-plugin.php'],
        );

        $this->assertSame('kinsta', \detect_host($preflight_data));

        $manifest = \runtime_manifest_for('kinsta', $preflight_data);
        $this->assertContains(
            'wp-content/mu-plugins/kinsta-mu-plugins.php',
            $manifest->paths_to_remove,
        );
        $this->assertContains(
            'wp-content/mu-plugins/wpe-cache-plugin.php',
            $manifest->paths_to_remove,
        );
        $this->assertNotContains('wp-content/object-cache.php', $manifest->paths_to_remove);
        $this->assertNotContains('wp-content/mu-plugins/mu-plugin.php', $manifest->paths_to_remove);
    }

    /**
     * @dataProvider sharedPluginNameProvider
     *
     * @param string[] $plugins Plugin names which can occur on many hosts.
     */
    public function testSharedPluginNamesDoNotSelectAHost(array $plugins): void
    {
        $this->assertSame('other', \detect_host($this->preflight($plugins, [])));
    }

    public static function sharedPluginNameProvider(): array
    {
        return [
            'DreamPress connectors' => [['nginx-helper', 'redis-cache']],
            'Cloudways cache plugins' => [['breeze', 'object-cache-pro']],
            'WPX recommendations' => [['wp-rocket', 'w3-total-cache']],
            'Servebolt optimizer' => [['servebolt-optimizer']],
            'A2 optimizer' => [['a2-optimized-wp']],
            'InMotion plugins' => [['boldgrid-backup', 'w3-total-cache']],
            'GreenGeeks LiteSpeed plugin' => [['litespeed-cache']],
            'GridPane cache connectors' => [['nginx-helper', 'redis-cache', 'litespeed-cache']],
        ];
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
