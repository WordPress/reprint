<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class PlatformFilesHostAnalyzerTest extends TestCase {
    /**
     * @dataProvider detectedHostProvider
     *
     * @param string[] $plugins             Regular plugin entry names.
     * @param string[] $mu_plugins          MU-plugin entry names.
     * @param string[] $expected_removals   Paths removed from the imported site.
     * @param string[] $preserved_fragments Path fragments which must not be removed.
     */
    public function testDetectsHostAndListsOnlyItsPlatformFiles(
        string $expected_host,
        array $plugins,
        array $mu_plugins,
        array $expected_removals,
        array $preserved_fragments = []
    ): void {
        $preflight_data = $this->preflight($plugins, $mu_plugins);

        $this->assertSame($expected_host, \detect_host($preflight_data));

        $manifest = \host_analyzer_for($expected_host)->analyze($preflight_data);
        $this->assertSame($expected_host, $manifest->source);
        $this->assertSame(['memory_limit' => '256M'], $manifest->php_ini);
        $this->assertSame($expected_removals, $manifest->paths_to_remove);

        foreach ($preserved_fragments as $preserved_fragment) {
            foreach ($manifest->paths_to_remove as $path_to_remove) {
                $this->assertStringNotContainsString($preserved_fragment, $path_to_remove);
            }
        }
    }

    public static function detectedHostProvider(): array
    {
        return [
            'Kinsta loader and directory' => [
                'kinsta',
                [],
                ['kinsta-mu-plugins.php', 'kinsta-mu-plugins'],
                [
                    'wp-content/mu-plugins/kinsta-mu-plugins.php',
                    'wp-content/mu-plugins/kinsta-mu-plugins',
                ],
            ],
            'Pantheon loader and directory' => [
                'pantheon',
                [],
                ['loader.php', 'pantheon-mu-plugin'],
                [
                    'wp-content/mu-plugins/pantheon-mu-plugin',
                ],
            ],
            'IONOS core loader and directory' => [
                'ionos',
                ['ionos-essentials', 'ionos-wpdev-caddy'],
                ['ionos-core.php', 'ionos-core'],
                [
                    'wp-content/mu-plugins/ionos-core.php',
                    'wp-content/mu-plugins/ionos-core',
                    'wp-content/mu-plugins/stretch-extra.php',
                    'wp-content/mu-plugins/stretch-extra',
                    'wp-content/plugins/ionos-essentials',
                    'wp-content/plugins/ionos-wpdev-caddy',
                ],
            ],
            'IONOS shared platform loader and directory' => [
                'ionos',
                [],
                ['stretch-extra.php', 'stretch-extra'],
                [
                    'wp-content/mu-plugins/ionos-core.php',
                    'wp-content/mu-plugins/ionos-core',
                    'wp-content/mu-plugins/stretch-extra.php',
                    'wp-content/mu-plugins/stretch-extra',
                    'wp-content/plugins/ionos-essentials',
                    'wp-content/plugins/ionos-wpdev-caddy',
                ],
            ],
            'Pressable cache and sign-on plugins' => [
                'pressable',
                ['pressable-cache-management', 'pressable-onepress-login'],
                ['pcm-extend-batcache.php', 'my-customer-plugin.php'],
                [
                    'wp-content/mu-plugins/pcm-extend-batcache.php',
                    'wp-content/mu-plugins/pcm-exclude-pages-from-batcache.php',
                    'wp-content/plugins/pressable-cache-management',
                    'wp-content/plugins/pressable-onepress-login',
                ],
                ['my-customer-plugin.php'],
            ],
            'GoDaddy system plugin' => [
                'godaddy',
                [],
                ['gd-system-plugin.php', 'gd-system-plugin'],
                [
                    'wp-content/mu-plugins/gd-system-plugin.php',
                    'wp-content/mu-plugins/gd-system-plugin',
                ],
            ],
            'Bluehost control plugin' => [
                'bluehost',
                ['bluehost-wordpress-plugin'],
                ['endurance-page-cache.php', 'customer-tools.php'],
                [
                    'wp-content/plugins/bluehost-wordpress-plugin',
                    'wp-content/mu-plugins/endurance-page-cache.php',
                    'wp-content/mu-plugins/endurance-browser-cache.php',
                ],
                ['customer-tools.php'],
            ],
            'HostGator control plugin' => [
                'hostgator',
                ['wp-plugin-hostgator'],
                ['endurance-page-cache.php'],
                [
                    'wp-content/plugins/wp-plugin-hostgator',
                    'wp-content/mu-plugins/endurance-page-cache.php',
                    'wp-content/mu-plugins/endurance-browser-cache.php',
                ],
            ],
            'Hostinger control plugin' => [
                'hostinger',
                ['hostinger', 'hostinger-easy-onboarding'],
                ['hostinger-mu-plugin.php'],
                [
                    'wp-content/plugins/hostinger',
                    'wp-content/plugins/hostinger-easy-onboarding',
                    'wp-content/mu-plugins/hostinger-mu-plugin.php',
                ],
            ],
            'Nexcess MAPPS loader and directory' => [
                'nexcess',
                [],
                ['nexcess-mapps.php', 'nexcess-mapps', 'customer-loader.php'],
                [
                    'wp-content/mu-plugins/nexcess-mapps.php',
                    'wp-content/mu-plugins/nexcess-mapps',
                ],
                ['customer-loader.php'],
            ],
            'Rocket.net cache plugin' => [
                'rocketnet',
                [],
                ['cdn-cache-management.php'],
                ['wp-content/mu-plugins/cdn-cache-management.php'],
            ],
            'SpinupWP control plugin' => [
                'spinupwp',
                ['spinupwp'],
                [],
                ['wp-content/plugins/spinupwp'],
            ],
            'WordPress VIP platform MU directory' => [
                'wpvip',
                [],
                ['vip-go-mu-plugins', 'client-mu-plugins'],
                ['wp-content/mu-plugins/vip-go-mu-plugins'],
                ['client-mu-plugins'],
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

    /**
     * @dataProvider portablePluginProvider
     *
     * @param string[] $plugins Normal plugins which can occur on many hosts.
     */
    public function testPortablePluginsDoNotSelectAHost(array $plugins): void
    {
        $this->assertSame('other', \detect_host($this->preflight($plugins, [])));
    }

    public static function portablePluginProvider(): array
    {
        return [
            'DreamPress connectors' => [['nginx-helper', 'redis-cache']],
            'Cloudways cache plugins' => [['breeze', 'object-cache-pro']],
            'WPX recommendations' => [['wp-rocket', 'w3-total-cache']],
            'Servebolt optimizer' => [['servebolt-optimizer']],
            'A2 optimizer' => [['a2-optimized-wp']],
            'InMotion plugins' => [['boldgrid-backup', 'w3-total-cache']],
            'GreenGeeks LiteSpeed plugin' => [['litespeed-cache']],
            'Raidboxes LiteSpeed connector' => [['litespeed-cache']],
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
