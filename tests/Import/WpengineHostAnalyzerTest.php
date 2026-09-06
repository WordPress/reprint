<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class WpengineHostAnalyzerTest extends TestCase {
    /** Minimal source inventory; plugin names alone do not identify a host. */
    private function wpenginePreflight(array $mu_plugins): array
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
                        'mu_plugins' => array_map(
                            static function (string $name): array {
                                return ['name' => $name, 'type' => substr($name, -4) === '.php' ? 'file' : 'dir'];
                            },
                            $mu_plugins,
                        ),
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

    /** Score Identifies Current Wpengine Document Root. */
    public function testScoreIdentifiesCurrentWpengineDocumentRoot(): void
    {
        $preflight = $this->wpenginePreflight([
            'mu-plugin.php',
            'wpengine-common',
        ]);

        $preflight['runtime']['document_root'] = '/nas/content/live/my-site';
        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    /** Score Identifies Current Wpengine Wordpress Root. */
    public function testScoreIdentifiesCurrentWpengineWordpressRoot(): void
    {
        $preflight = $this->wpenginePreflight([
            'wpe-cache-plugin',
            'wpe-update-source-selector',
            'wpengine-security-auditor.php',
        ]);

        $preflight['database']['wp']['paths_urls']['abspath'] = '/nas/wp/www/my-site/';
        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    /** Detect Host Returns Wpengine. */
    public function testDetectHostReturnsWpengine(): void
    {
        $preflight = $this->wpenginePreflight([
            'mu-plugin.php',
            'wpengine-common',
        ]);

        $preflight['runtime']['document_root'] = '/nas/content/live/my-site';
        $this->assertSame('wpengine', \detect_host($preflight));
    }

    /** Score Rejects One Generic Mu Plugin. */
    public function testScoreRejectsOneGenericMuPlugin(): void
    {
        $preflight = $this->wpenginePreflight([
            'force-strong-passwords',
        ]);

        $this->assertLessThan(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    /** Score Rejects Unrelated Mu Plugins. */
    public function testScoreRejectsUnrelatedMuPlugins(): void
    {
        $preflight = $this->wpenginePreflight([
            'my-company-loader.php',
            'my-company-tools',
        ]);

        $this->assertSame(0.0, \WpengineHostAnalyzer::score($preflight));
    }

    /** Analyze Lists Wpengine Files Removed During Migration. */
    public function testAnalyzeListsWpengineFilesRemovedDuringMigration(): void
    {
        $manifest = ( new \WpengineHostAnalyzer() )->analyze($this->wpenginePreflight([]));

        $this->assertSame([
            'wp-content/advanced-cache.php',
            'wp-content/object-cache.php',
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
        ], $manifest->paths_to_remove);
        $this->assertSame('wpengine', $manifest->source);
    }
    /** A shared MU-plugin filename does not identify its contents. */
    public function testCustomerMuPluginIsNotRemovedOnWpengine(): void
    {
        $preflight = $this->wpenginePreflight(['mu-plugin.php', 'wpengine-common']);
        $preflight['wp_content']['roots'][0]['mu_plugins'][0]['headers'] = ['name' => 'Customer checkout rules'];
        $manifest = ( new \WpengineHostAnalyzer() )->analyze($preflight);
        $this->assertNotContains('wp-content/mu-plugins/mu-plugin.php', $manifest->paths_to_remove);
        $this->assertNotContains('wp-content/mu-plugins/wpengine-common', $manifest->paths_to_remove);
    }

    /** The documented WP Engine System header identifies a renamed loader. */
    public function testRecognizedLoaderIsRemovedUnderItsActualFilename(): void
    {
        $preflight = $this->wpenginePreflight(['platform-loader.php', 'wpengine-common']);
        $preflight['wp_content']['roots'][0]['mu_plugins'][0]['headers'] = ['name' => 'WP Engine System'];
        $manifest = ( new \WpengineHostAnalyzer() )->analyze($preflight);
        $this->assertContains('wp-content/mu-plugins/platform-loader.php', $manifest->paths_to_remove);
    }

    /** Plugins copied off the source host do not identify the current host. */
    public function testOldWpenginePluginsDoNotIdentifyAnotherHost(): void
    {
        $preflight = $this->wpenginePreflight(['mu-plugin.php', 'wpengine-common', 'wpe-cache-plugin']);
        $this->assertSame(0.0, \WpengineHostAnalyzer::score($preflight));
    }
}
