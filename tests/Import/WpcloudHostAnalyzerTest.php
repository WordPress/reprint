<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/interface-host-analyzer.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/functions.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/analyzers/class-wpcloud-host-analyzer.php';

class WpcloudHostAnalyzerTest extends TestCase
{
    /**
     * Build a minimal preflight_data structure that scores as WP Cloud.
     */
    private function wpcloudPreflight(array $overrides = []): array
    {
        $defaults = [
            'runtime' => [
                'document_root' => '/srv/htdocs',
                'env_names' => ['PRIVACY_MODEL'],
                'ini_get_all' => [
                    'auto_prepend_file' => '',
                    'auto_append_file' => '',
                ],
            ],
            'filesystem' => [
                'directories' => [
                    ['path' => '/srv/htdocs/__wp__', 'exists' => true],
                ],
            ],
            'wp_detect' => [
                'roots' => [
                    ['path' => '/srv/htdocs/__wp__'],
                ],
            ],
            'database' => [
                'wp' => [
                    'paths_urls' => [
                        'abspath' => '/wordpress/core/6.7.2/',
                        'content_dir' => '/srv/htdocs/wp-content',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    public function testAnalyzePopulatesPathsToRemove(): void
    {
        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($this->wpcloudPreflight());

        $this->assertContains('wp-content/object-cache.php', $manifest->paths_to_remove);
        $this->assertContains('wp-content/mu-plugins/wpcomsh', $manifest->paths_to_remove);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-dev', $manifest->paths_to_remove);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-loader.php', $manifest->paths_to_remove);
    }

    public function testAnalyzeDetectsExtraDirectoryFromAutoPrependFile(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/scripts/env.php',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        $this->assertContains('/scripts', $manifest->extra_directories);
    }

    public function testAnalyzeDetectsExtraDirectoryFromAutoAppendFile(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_append_file' => '/logging/tracker.php',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        $this->assertContains('/logging', $manifest->extra_directories);
    }

    public function testAnalyzeDeduplicatesExtraDirectories(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/scripts/env.php',
                    'auto_append_file' => '/scripts/cleanup.php',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        // Both point to /scripts — should appear only once.
        $this->assertCount(1, $manifest->extra_directories);
        $this->assertSame(['/scripts'], $manifest->extra_directories);
    }

    public function testAnalyzeIgnoresEmptyIniValues(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '',
                    'auto_append_file' => '',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        $this->assertEmpty($manifest->extra_directories);
    }

    public function testAnalyzeIgnoresRelativeIniPaths(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => 'relative/path.php',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        $this->assertEmpty($manifest->extra_directories);
    }

    public function testAnalyzeIgnoresRootSlashOnly(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/env.php',
                ],
            ],
        ]);

        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($preflight);

        // dirname('/env.php') is '/' — should be ignored.
        $this->assertEmpty($manifest->extra_directories);
    }

    public function testAnalyzeDeclaresThumbnailerRoute(): void
    {
        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($this->wpcloudPreflight());

        $handlers = array_column($manifest->routes, 'handler');
        $this->assertContains('wpcloud-thumbnail-generator', $handlers);
    }

    public function testAnalyzeSetsWpDirServerVar(): void
    {
        $analyzer = new \WpcloudHostAnalyzer();
        $manifest = $analyzer->analyze($this->wpcloudPreflight());

        $this->assertArrayHasKey('WP_DIR', $manifest->server_vars);
        $this->assertSame('{fs-root}/__wp__/', $manifest->server_vars['WP_DIR']);
    }

    public function testExtractConstantsTreatsASiblingPrefixAsOutsideAbspath(): void
    {
        $constants = \extract_constants([
            'database' => [
                'wp' => [
                    'paths_urls' => [
                        'abspath' => '/srv/site',
                        'content_dir' => '/srv/site-old/wp-content',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['WP_CONTENT_DIR' => '{fs-root}/wp-content'],
            $constants
        );
    }

    public function testScoreIdentifiesWpcloudSite(): void
    {
        $preflight = $this->wpcloudPreflight();
        $score = \WpcloudHostAnalyzer::score($preflight);

        // __wp__ dir (0.5) + WP root at __wp__ (0.4) + PRIVACY_MODEL (0.5) = 1.0 (capped)
        $this->assertGreaterThanOrEqual(0.5, $score);
    }

    public function testScoreMatchesWpcloudMarkersUnderTheFilesystemRoot(): void
    {
        $preflight = $this->wpcloudPreflight([
            'runtime' => [
                'document_root' => '/',
            ],
            'filesystem' => [
                'directories' => [
                    ['path' => '/__wp__', 'exists' => true],
                ],
            ],
            'wp_detect' => [
                'roots' => [
                    ['path' => '/__wp__'],
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(0.9, \WpcloudHostAnalyzer::score($preflight));
    }

    public function testScoreRejectsNonWpcloudSite(): void
    {
        $preflight = [
            'runtime' => [
                'document_root' => '/var/www/html',
                'env_names' => [],
                'ini_get_all' => [],
            ],
            'filesystem' => [
                'directories' => [],
            ],
            'wp_detect' => [
                'roots' => [
                    ['path' => '/var/www/html'],
                ],
            ],
        ];

        $score = \WpcloudHostAnalyzer::score($preflight);
        $this->assertLessThan(0.5, $score);
    }
}
