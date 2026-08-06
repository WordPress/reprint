<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../client/import.php';

/**
 * Verify run_flat_document_root() picks up wp-config.php when it lives
 * in ABSPATH's parent directory (the WordPress one-directory-up convention).
 */
class FlatDocrootWpConfigTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/flat-docroot-wpconfig-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    /**
     * WP Cloud layout: wp-config.php in /srv/htdocs/ (parent of ABSPATH),
     * ABSPATH at /srv/htdocs/wordpress/. Phase 1c should symlink it.
     */
    public function testSymlinksWpConfigFromAbspathParent(): void
    {
        $abspath = '/srv/htdocs/wordpress/';
        $parentDir = '/srv/htdocs';

        // Create the filesystem layout under fsRoot
        $localAbspath = $this->fsRoot . $abspath;
        $localParent = $this->fsRoot . $parentDir;
        mkdir($localAbspath, 0755, true);

        // wp-config.php lives in the parent of ABSPATH
        file_put_contents($localParent . '/wp-config.php', '<?php // wp-config from parent');

        // Put a minimal wp-load.php in ABSPATH so the directory isn't empty
        file_put_contents($localAbspath . 'wp-load.php', '<?php // wp-load');

        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => [
                                'abspath' => $abspath,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $flattenTo = $this->tempDir . '/flat';
        $client = $this->makeClient();
        $this->loadClientState($client);

        $this->callPrivate($client, 'run_flat_document_root', [
            ['flatten_to' => $flattenTo, 'force' => false],
        ]);

        $wpConfigFlat = $flattenTo . '/wp-config.php';
        $this->assertFileExists($wpConfigFlat, 'wp-config.php should exist in flattened output');
        $this->assertTrue(is_link($wpConfigFlat), 'wp-config.php should be a symlink');
        $this->assertStringContainsString(
            'wp-config from parent',
            file_get_contents($wpConfigFlat),
            'wp-config.php should contain the parent directory content',
        );
    }

    /**
     * When wp-config.php exists in BOTH ABSPATH and its parent, the ABSPATH
     * version should win (Phase 1 already placed it).
     */
    public function testDoesNotOverwriteWpConfigAlreadyInAbspath(): void
    {
        $abspath = '/srv/htdocs/wordpress/';
        $parentDir = '/srv/htdocs';

        $localAbspath = $this->fsRoot . $abspath;
        $localParent = $this->fsRoot . $parentDir;
        mkdir($localAbspath, 0755, true);

        // wp-config.php in both locations
        file_put_contents($localParent . '/wp-config.php', '<?php // wp-config from parent');
        file_put_contents($localAbspath . 'wp-config.php', '<?php // wp-config from abspath');
        file_put_contents($localAbspath . 'wp-load.php', '<?php // wp-load');

        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => [
                                'abspath' => $abspath,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $flattenTo = $this->tempDir . '/flat';
        $client = $this->makeClient();
        $this->loadClientState($client);

        $this->callPrivate($client, 'run_flat_document_root', [
            ['flatten_to' => $flattenTo, 'force' => false],
        ]);

        $wpConfigFlat = $flattenTo . '/wp-config.php';
        $this->assertFileExists($wpConfigFlat);
        $this->assertStringContainsString(
            'wp-config from abspath',
            file_get_contents($wpConfigFlat),
            'ABSPATH wp-config.php should take precedence over parent',
        );
    }

    // ---- helpers ----

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), $state);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('state');
        $property->setValue($client, $state);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }
        rmdir($dir);
    }
}
