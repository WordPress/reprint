<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --remap / maps the remote filesystem root as a catch-all placement rule.
 */
class RemapRootTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remap-root-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        $this->root = realpath($this->fsRoot);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempDir);
        parent::tearDown();
    }

    public function testRootRemapCatchesUnmatchedRemotePath(): void
    {
        $rules = $this->resolveRemap($this->newClient(), [
            ['/', ':fs-root:/.symlinks'],
        ]);

        $this->assertSame(
            $this->root . '/.symlinks/tmp/shared/plugin.php',
            $this->placeWithRules($rules, '/tmp/shared/plugin.php')
        );
    }

    public function testSpecificRemapWinsOverRootRemapRegardlessOfOrder(): void
    {
        foreach ([
            [
                [':wp-content:', ':fs-root:/wp-content'],
                ['/', ':fs-root:/.symlinks'],
            ],
            [
                ['/', ':fs-root:/.symlinks'],
                [':wp-content:', ':fs-root:/wp-content'],
            ],
        ] as $rawRules) {
            $rules = $this->resolveRemap($this->newClient(), $rawRules);
            $this->assertSame(
                $this->root . '/wp-content/themes/t/style.css',
                $this->placeWithRules($rules, '/srv/site/wp-content/themes/t/style.css')
            );
        }
    }

    public function testRootRemapCanMapTheRootPathItself(): void
    {
        $rules = $this->resolveRemap($this->newClient(), [
            ['/', ':fs-root:/.symlinks'],
        ]);

        $this->assertSame(
            $this->root . '/.symlinks',
            $this->placeWithRules($rules, '/')
        );
    }

    public function testRootRemapDoesNotTurnSiblingPrefixesIntoMatches(): void
    {
        $rules = $this->resolveRemap($this->newClient(), [
            ['/wp', ':fs-root:/wp'],
            ['/', ':fs-root:/.symlinks'],
        ]);

        $this->assertSame(
            $this->root . '/.symlinks/wp-content/a.php',
            $this->placeWithRules($rules, '/wp-content/a.php')
        );
    }

    public function testRootRemapSourceIsNotAddedToExportDirectories(): void
    {
        $client = $this->newClient();
        (new \ReflectionClass($client))->getProperty('remap_rules')->setValue($client, [
            '/' => $this->root . '/.symlinks',
            '/external-plugin' => $this->root . '/wp-content/plugins/external-plugin',
        ]);

        $directories = (new \ReflectionClass($client))->getMethod('get_export_directories')->invoke($client);

        $this->assertNotContains('/', $directories, 'catch-all placement must not scan remote /');
        $this->assertContains('/external-plugin', $directories, 'specific remap sources still get scanned');
    }

    public function testAutoWpContentSubdirectoryRemapWinsOverRootCatchAll(): void
    {
        $client = $this->newClient();
        $this->setPreflight($client, [
            'content_dir' => '/srv/site/wp-content',
            'plugins_dir' => '/plugins',
        ]);
        $rules = $this->resolveRemap($client, [
            [':wp-content:', ':fs-root:/wp-content'],
            ['/', ':fs-root:/.symlinks'],
        ]);

        $this->assertSame(
            $this->root . '/wp-content/plugins/cache/cache.php',
            $this->placeWithRules($rules, '/plugins/cache/cache.php')
        );
        $this->assertSame(
            $this->root . '/.symlinks/other/cache.php',
            $this->placeWithRules($rules, '/other/cache.php')
        );
    }

    private function rrm(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = "$d/$i";
            (is_dir($p) && !is_link($p)) ? $this->rrm($p) : unlink($p);
        }
        rmdir($d);
    }

    private function newClient(): \ImportClient
    {
        $client = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $this->setPreflight($client, [
            'content_dir' => '/srv/site/wp-content',
            'plugins_dir' => '/srv/site/wp-content/plugins',
        ]);
        return $client;
    }

    private function setPreflight(\ImportClient $client, array $paths): void
    {
        $client->state->preflight = [
            'data' => [
                'runtime' => [
                    'document_root' => '/srv/site',
                    'ini_get_all' => [],
                ],
                'wp_detect' => [
                    'roots' => [
                        ['path' => '/srv/site'],
                    ],
                ],
                'database' => [
                    'wp' => [
                        'paths_urls' => [
                            'abspath' => '/srv/site',
                            'content_dir' => $paths['content_dir'],
                            'plugins_dir' => $paths['plugins_dir'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function resolveRemap(\ImportClient $client, array $pairs): array
    {
        return (new \ReflectionClass($client))->getMethod('resolve_remap')->invoke($client, $pairs);
    }

    private function placeWithRules(array $rules, string $path): string
    {
        $client = $this->newClient();
        (new \ReflectionClass($client))->getProperty('remap_rules')->setValue($client, $rules);
        return (new \ReflectionClass($client))->getMethod('remote_path_to_local_path_within_import_root')->invoke($client, $path);
    }
}
