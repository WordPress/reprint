<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that get_export_directories() auto-includes directories from
 * auto_prepend_file and auto_append_file INI values when they point
 * outside the WordPress roots.
 */
class ExportDirectoryAutoDetectTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/export-dir-autodetect-' . uniqid();
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

    private function writeState(array $state): void
    {
        $defaults = [
            'command' => null,
            'status' => null,
            'cursor' => null,
            'stage' => null,
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '/srv/htdocs',
                        'ini_get_all' => [],
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => '/srv/htdocs/',
                                'content_dir' => '/srv/htdocs/wp-content',
                                'uploads' => [
                                    'baseurl' => 'https://source.example/wp-content/uploads',
                                ],
                                'home_url' => 'https://source.example',
                                'site_url' => 'https://source.example',
                            ],
                        ],
                    ],
                    'wp_detect' => [
                        'roots' => [
                            ['path' => '/srv/htdocs'],
                        ],
                    ],
                ],
            ],
            'webhost' => 'other',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_replace_recursive($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $method_reflection = $reflection->getMethod($method);
        return $method_reflection->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setValue($client, $value);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    private function getExportDirectories(\ImportClient $client): array
    {
        return $this->callPrivate($client, 'get_export_directories');
    }

    public function testAutoIncludesAutoPrependFileDirectory(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => '/scripts/env.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        $this->assertContains('/scripts', $dirs);
    }

    public function testAutoIncludesAutoAppendFileDirectory(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_append_file' => '/logging/tracker.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        $this->assertContains('/logging', $dirs);
    }

    public function testDoesNotDuplicateDirectoryAlreadyCoveredByRoots(): void
    {
        // auto_prepend_file points inside a directory that is already a
        // wp_detect root — it should not be added again.
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => '/srv/htdocs/wp-config.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        // /srv/htdocs is already a root, so the directory derived from
        // auto_prepend_file (/srv/htdocs) should not be added twice.
        $count = array_count_values($dirs);
        $this->assertSame(1, $count['/srv/htdocs'] ?? 0);
    }

    public function testIgnoresEmptyAutoPrependFile(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => '',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        // Only the wp_detect root should be present.
        $this->assertSame(['/srv/htdocs'], $dirs);
    }

    public function testIgnoresRelativeAutoPrependPath(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => 'relative/env.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        $this->assertSame(['/srv/htdocs'], $dirs);
    }

    public function testIgnoresRootSlashDirectory(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => '/env.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        // dirname('/env.php') = '/' — should not be added.
        $this->assertNotContains('/', $dirs);
    }

    public function testRemoteRootRemapSourceIsPlacementOnly(): void
    {
        $this->writeState([]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->setPrivate($client, 'remap_rules', [
            '/' => $this->fsRoot . '/.remote-root',
            '/shared' => $this->fsRoot . '/shared',
        ]);
        $dirs = $this->getExportDirectories($client);

        $this->assertNotContains('/', $dirs, 'remote root remap must not scan remote /');
        $this->assertContains('/shared', $dirs, 'specific remap sources still get scanned');
    }

    public function testIncludesBothPrependAndAppendDirectories(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'ini_get_all' => [
                            'auto_prepend_file' => '/scripts/env.php',
                            'auto_append_file' => '/logging/tracker.php',
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $dirs = $this->getExportDirectories($client);

        $this->assertContains('/scripts', $dirs);
        $this->assertContains('/logging', $dirs);
    }
}
