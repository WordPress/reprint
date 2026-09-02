<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class WpengineHostAnalyzerTest extends TestCase {
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

    public function testScoreIdentifiesWpengineCommonWithItsLoader(): void
    {
        $preflight = $this->wpenginePreflight([
            'mu-plugin.php',
            'wpengine-common',
        ]);

        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    public function testScoreIdentifiesCurrentWpengineMuPlugins(): void
    {
        $preflight = $this->wpenginePreflight([
            'wpe-cache-plugin',
            'wpe-update-source-selector',
            'wpengine-security-auditor.php',
        ]);

        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    public function testDetectHostReturnsWpengine(): void
    {
        $preflight = $this->wpenginePreflight([
            'mu-plugin.php',
            'wpengine-common',
        ]);

        $this->assertSame('wpengine', \detect_host($preflight));
    }

    public function testScoreRejectsOneGenericMuPlugin(): void
    {
        $preflight = $this->wpenginePreflight([
            'force-strong-passwords',
        ]);

        $this->assertLessThan(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    public function testScoreRejectsUnrelatedMuPlugins(): void
    {
        $preflight = $this->wpenginePreflight([
            'my-company-loader.php',
            'my-company-tools',
        ]);

        $this->assertSame(0.0, \WpengineHostAnalyzer::score($preflight));
    }

    public function testAnalyzeListsWpengineFilesRemovedDuringMigration(): void
    {
        $manifest = ( new \WpengineHostAnalyzer() )->analyze($this->wpenginePreflight([]));

        $this->assertSame([
            'wp-content/advanced-cache.php',
            'wp-content/object-cache.php',
            'wp-content/mu-plugins/mu-plugin.php',
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
        ], $manifest->paths_to_remove);
        $this->assertSame('wpengine', $manifest->source);
    }
}
