<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/interface-host-analyzer.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/functions.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/analyzers/class-siteground-host-analyzer.php';

class SitegroundHostAnalyzerTest extends TestCase
{
    /**
     * Build a minimal preflight_data structure that scores as SiteGround.
     */
    private function sitegroundPreflight(array $overrides = []): array
    {
        $defaults = [
            'runtime' => [
                'document_root' => '/var/www/html',
                'env_names' => [],
                'ini_get_all' => [
                    'memory_limit' => '256M',
                    'upload_max_filesize' => '64M',
                ],
            ],
            'filesystem' => [
                'directories' => [],
            ],
            'wp_detect' => [
                'roots' => [
                    ['path' => '/var/www/html'],
                ],
            ],
            'wp_content' => [
                'roots' => [
                    [
                        'root' => '/var/www/html',
                        'plugins' => [
                            ['name' => 'sg-cachepress', 'type' => 'dir'],
                            ['name' => 'sg-security', 'type' => 'dir'],
                            ['name' => 'woocommerce', 'type' => 'dir'],
                        ],
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

        return array_replace_recursive($defaults, $overrides);
    }

    public function testScoreIdentifiesSitegroundSite(): void
    {
        $score = \SitegroundHostAnalyzer::score($this->sitegroundPreflight());

        $this->assertGreaterThanOrEqual(0.5, $score);
    }

    public function testScoreWeakWithSingleSgPlugin(): void
    {
        $preflight = $this->sitegroundPreflight();
        // Replace plugins array entirely — only one sg-* plugin.
        $preflight['wp_content']['roots'][0]['plugins'] = [
            ['name' => 'sg-security', 'type' => 'dir'],
        ];

        $score = \SitegroundHostAnalyzer::score($preflight);
        $this->assertLessThan(0.5, $score);
    }

    public function testScoreRejectsNonSitegroundSite(): void
    {
        $preflight = $this->sitegroundPreflight();
        $preflight['wp_content']['roots'][0]['plugins'] = [
            ['name' => 'woocommerce', 'type' => 'dir'],
            ['name' => 'jetpack', 'type' => 'dir'],
        ];

        $score = \SitegroundHostAnalyzer::score($preflight);
        $this->assertSame(0.0, $score);
    }

    public function testScoreRejectsUnrelatedPluginsWithSgPrefix(): void
    {
        $preflight = $this->sitegroundPreflight();
        $preflight['wp_content']['roots'][0]['plugins'] = [
            ['name' => 'sg-customer-tools', 'type' => 'dir'],
            ['name' => 'sg-example-plugin', 'type' => 'dir'],
        ];

        $this->assertSame(0.0, \SitegroundHostAnalyzer::score($preflight));
    }

    public function testAnalyzePopulatesPathsToRemove(): void
    {
        $analyzer = new \SitegroundHostAnalyzer();
        $manifest = $analyzer->analyze($this->sitegroundPreflight());

        $this->assertSame(
            ['wp-content/plugins/sg-cachepress'],
            $manifest->paths_to_remove,
        );
    }

    public function testAnalyzeSetsSourceToSiteground(): void
    {
        $analyzer = new \SitegroundHostAnalyzer();
        $manifest = $analyzer->analyze($this->sitegroundPreflight());

        $this->assertSame('siteground', $manifest->source);
    }
}
