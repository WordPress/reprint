<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the existing import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/load.php';

class WpengineHostAnalyzerTest extends TestCase {
    private function wpenginePreflight(array $runtime = []): array
    {
        return [
            'runtime' => array_merge([
                'ini_get_all' => [
                    'memory_limit' => '256M',
                ],
            ], $runtime),
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

    public function testScoreIdentifiesCurrentWpengineFilesystemRoot(): void
    {
        $preflight = $this->wpenginePreflight([
            'document_root' => '/nas/content/live/example',
        ]);

        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    public function testScoreIdentifiesOlderWpengineFilesystemRoot(): void
    {
        $preflight = $this->wpenginePreflight([
            'cwd' => '/nas/wp/www/cluster-1234/example',
        ]);

        $this->assertGreaterThanOrEqual(0.5, \WpengineHostAnalyzer::score($preflight));
    }

    public function testDetectHostReturnsWpengine(): void
    {
        $preflight = $this->wpenginePreflight([
            'script_filename' => '/nas/content/live/example/index.php',
        ]);

        $this->assertSame('wpengine', \detect_host($preflight));
    }

    public function testScoreRejectsCopiedWpenginePlugins(): void
    {
        $preflight = $this->wpenginePreflight();
        $preflight['wp_content']['roots'][] = [
            'mu_plugins' => [
                ['name' => 'wpengine-common', 'type' => 'dir'],
                ['name' => 'wpe-cache-plugin', 'type' => 'dir'],
                ['name' => 'wpe-update-source-selector', 'type' => 'dir'],
                ['name' => 'wpengine-security-auditor.php', 'type' => 'file'],
            ],
        ];

        $this->assertSame(0.0, \WpengineHostAnalyzer::score($preflight));
    }

    public function testScoreRejectsUnrelatedFilesystemRoot(): void
    {
        $preflight = $this->wpenginePreflight([
            'document_root' => '/var/www/html',
            'script_filename' => '/var/www/html/index.php',
            'cwd' => '/var/www/html',
        ]);

        $this->assertSame(0.0, \WpengineHostAnalyzer::score($preflight));
    }

    public function testAnalyzeListsAmbiguousWpenginePaths(): void
    {
        $manifest = ( new \WpengineHostAnalyzer() )->analyze($this->wpenginePreflight([]));

        $this->assertSame([
            'wp-content/advanced-cache.php',
            'wp-content/object-cache.php',
            'wp-content/mu-plugins/mu-plugin.php',
        ], $manifest->paths_to_remove);
        $this->assertSame('wpengine', $manifest->source);
    }
}
