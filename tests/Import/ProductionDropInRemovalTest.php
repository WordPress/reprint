<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify that run_apply_runtime removes paths declared in the runtime
 * manifest and logs each removal.
 */
class ProductionDropInRemovalTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/production-drop-in-removal-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        mkdir($this->outputDir, 0755, true);
        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
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
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
                'current_stage' => null,
                'remote_cursor' => null,
            ],
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '/srv/htdocs',
                        'env_names' => ['PRIVACY_MODEL'],
                        'ini_get_all' => [
                            'auto_prepend_file' => '',
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
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => '/wordpress/core/6.7.2/',
                                'content_dir' => '/srv/htdocs/wp-content',
                                'uploads' => [
                                    'baseurl' => 'https://source.example/wp-content/uploads',
                                ],
                                'home_url' => 'https://source.example',
                                'site_url' => 'https://source.example',
                            ],
                        ],
                    ],
                ],
            ],
            'webhost' => 'wpcloud',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        \write_current_pull_state($this->makeClient(), array_replace_recursive($defaults, $state));
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

    private function runApplyRuntime(\ImportClient $client): void
    {
        ob_start();
        try {
            $this->callPrivate($client, 'run_apply_runtime', [[
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
                'flat_document_root' => $this->fsRoot,
            ]]);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Create the production drop-in files that WpcloudHostAnalyzer declares
     * for removal, so we can verify they get deleted.
     */
    private function createProductionDropIns(): void
    {
        // object-cache.php (file)
        $wpContent = $this->fsRoot . '/wp-content';
        mkdir($wpContent, 0755, true);
        file_put_contents($wpContent . '/object-cache.php', "<?php // Memcached object cache\n");

        // advanced-cache.php (file)
        file_put_contents($wpContent . '/advanced-cache.php', "<?php // Advanced page cache\n");

        // wpcomsh directory with files inside
        $muPlugins = $wpContent . '/mu-plugins';
        mkdir($muPlugins . '/wpcomsh', 0755, true);
        file_put_contents($muPlugins . '/wpcomsh/wpcomsh.php', "<?php // wpcomsh\n");
        file_put_contents($muPlugins . '/wpcomsh/functions.php', "<?php // functions\n");

        // wpcomsh-dev directory
        mkdir($muPlugins . '/wpcomsh-dev', 0755, true);
        file_put_contents($muPlugins . '/wpcomsh-dev/wpcomsh-dev.php', "<?php // wpcomsh-dev\n");

        // wpcomsh-loader.php (file)
        file_put_contents($muPlugins . '/wpcomsh-loader.php', "<?php // wpcomsh loader\n");
    }

    public function testApplyRuntimeRemovesObjectCacheFile(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $objectCachePath = $this->fsRoot . '/wp-content/object-cache.php';
        $this->assertFileExists($objectCachePath);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileDoesNotExist($objectCachePath);
    }

    public function testApplyRuntimeRemovesAdvancedCacheFile(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $advancedCachePath = $this->fsRoot . '/wp-content/advanced-cache.php';
        $this->assertFileExists($advancedCachePath);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileDoesNotExist($advancedCachePath);
    }

    public function testApplyRuntimeRemovesWpcomshDirectory(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $wpcomshDir = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh';
        $this->assertDirectoryExists($wpcomshDir);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($wpcomshDir);
    }

    public function testApplyRuntimeRemovesWpcomshDevDirectory(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $wpcomshDevDir = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh-dev';
        $this->assertDirectoryExists($wpcomshDevDir);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($wpcomshDevDir);
    }

    public function testApplyRuntimeRemovesWpcomshLoaderFile(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $loaderPath = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh-loader.php';
        $this->assertFileExists($loaderPath);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileDoesNotExist($loaderPath);
    }

    public function testApplyRuntimeToleratesMissingDropIns(): void
    {
        // Don't create any drop-in files. The removal loop should skip
        // them gracefully without errors.
        $this->writeState([]);
        mkdir($this->fsRoot . '/wp-content', 0755, true);

        $client = $this->makeClient();
        $this->loadClientState($client);

        // Should not throw.
        $this->runApplyRuntime($client);
        $this->assertTrue(true);
    }

    public function testApplyRuntimePreservesUnrelatedFiles(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        // Create a legitimate mu-plugin that should NOT be removed.
        $muPlugins = $this->fsRoot . '/wp-content/mu-plugins';
        file_put_contents($muPlugins . '/my-custom-plugin.php', "<?php // custom\n");

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileExists($muPlugins . '/my-custom-plugin.php');
    }

    public function testApplyRuntimeLogsRemovalsToAuditLog(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        // The audit log records every removal.
        $auditLog = file_get_contents($this->stateDir . '/audit.log');

        $this->assertStringContainsString(
            'removed wp-content/object-cache.php (source-host)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/advanced-cache.php (source-host)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/mu-plugins/wpcomsh (source-host)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/mu-plugins/wpcomsh-loader.php (source-host)',
            $auditLog,
        );
    }

    public function testApplyRuntimePersistsPathsRemovedToState(): void
    {
        $this->writeState([]);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        // Re-read the state file to verify paths_removed was persisted.
        $state = json_decode(
            file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );

        $this->assertArrayHasKey('remote_paths_removed_from_local_site', $state['apply']);
        $this->assertContains('wp-content/object-cache.php', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/advanced-cache.php', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-dev', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-loader.php', $state['apply']['remote_paths_removed_from_local_site']);
    }

    public function testNonWpcloudHostPreservesUnlistedDropIn(): void
    {
        // A shared object-cache.php is not in the global source-host path list.
        $this->writeState([
            'webhost' => 'other',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                ],
            ],
        ]);

        // Create an object-cache.php that a non-wpcloud host should leave alone.
        $wpContent = $this->fsRoot . '/wp-content';
        mkdir($wpContent, 0755, true);
        file_put_contents($wpContent . '/object-cache.php', "<?php // Redis object cache\n");

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileExists($wpContent . '/object-cache.php');
    }

    public function testEveryImportRemovesTheGlobalSourceHostPathList(): void
    {
        $this->writeState([
            'webhost' => 'other',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                ],
            ],
        ]);

        foreach (\source_host_paths_to_remove() as $relative_path) {
            $source_host_path = $this->fsRoot . '/' . $relative_path;
            if (substr($relative_path, -4) === '.php') {
                $parent_directory = dirname($source_host_path);
                if (!is_dir($parent_directory)) {
                    mkdir($parent_directory, 0755, true);
                }
                file_put_contents($source_host_path, "<?php // Source-host file\n");
                continue;
            }

            mkdir($source_host_path, 0755, true);
            file_put_contents($source_host_path . '/plugin.php', "<?php // Source-host plugin\n");
        }

        $unlisted_plugin = $this->fsRoot . '/wp-content/plugins/woocommerce';
        mkdir($unlisted_plugin, 0755, true);
        file_put_contents($unlisted_plugin . '/woocommerce.php', "<?php // WooCommerce\n");

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        foreach (\source_host_paths_to_remove() as $relative_path) {
            $source_host_path = $this->fsRoot . '/' . $relative_path;
            if (substr($relative_path, -4) === '.php') {
                $this->assertFileDoesNotExist($source_host_path);
                continue;
            }
            $this->assertDirectoryDoesNotExist($source_host_path);
        }
        $this->assertDirectoryExists($unlisted_plugin);

        $state = json_decode(
            file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertSame(
            \source_host_paths_to_remove(),
            $state['apply']['remote_paths_removed_from_local_site'],
        );
    }

    // ---- SiteGround-specific tests ----

    private function writeSitegroundState(array $overrides = []): void
    {
        $this->writeState(array_replace_recursive([
            'webhost' => 'siteground',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                    'wp_content' => [
                        'roots' => [
                            [
                                'plugins' => [
                                    ['name' => 'sg-cachepress', 'type' => 'dir'],
                                    ['name' => 'sg-security', 'type' => 'dir'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }

    private function createSitegroundPlugins(): void
    {
        $plugins = $this->fsRoot . '/wp-content/plugins';
        mkdir($plugins . '/sg-cachepress', 0755, true);
        file_put_contents(
            $plugins . '/sg-cachepress/sg-cachepress.php',
            "<?php // SG CachePress\n",
        );
        mkdir($plugins . '/sg-security', 0755, true);
        file_put_contents(
            $plugins . '/sg-security/sg-security.php',
            "<?php // SG Security\n",
        );
    }

    public function testSitegroundRemovesSgCachepressDirectory(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $sgCacheDir = $this->fsRoot . '/wp-content/plugins/sg-cachepress';
        $this->assertDirectoryExists($sgCacheDir);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($sgCacheDir);
    }

    public function testSitegroundRemovesSgSecurityDirectory(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $sgSecurityDir = $this->fsRoot . '/wp-content/plugins/sg-security';
        $this->assertDirectoryExists($sgSecurityDir);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($sgSecurityDir);
    }

    public function testSitegroundPreservesUnrelatedPlugins(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $plugins = $this->fsRoot . '/wp-content/plugins';
        mkdir($plugins . '/woocommerce', 0755, true);
        file_put_contents($plugins . '/woocommerce/woocommerce.php', "<?php // WooCommerce\n");

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryExists($plugins . '/woocommerce');
    }

    public function testSitegroundLogsRemovalsToAuditLog(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $auditLog = file_get_contents($this->stateDir . '/audit.log');

        $this->assertStringContainsString(
            'removed wp-content/plugins/sg-cachepress (source-host)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/plugins/sg-security (source-host)',
            $auditLog,
        );
    }

    public function testSitegroundPersistsPathsRemovedToState(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $state = json_decode(
            file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );

        $this->assertContains(
            'wp-content/plugins/sg-cachepress',
            $state['apply']['remote_paths_removed_from_local_site'],
        );
        $this->assertContains(
            'wp-content/plugins/sg-security',
            $state['apply']['remote_paths_removed_from_local_site'],
        );
    }

    // ---- WP Engine-specific tests ----

    public function testWpengineRemovesPlatformMuPluginsAndPreservesCustomMuPlugins(): void
    {
        $this->writeState([
            'webhost' => 'wpengine',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                ],
            ],
        ]);

        $mu_plugins = $this->fsRoot . '/wp-content/mu-plugins';
        mkdir($mu_plugins . '/wpengine-common', 0755, true);
        mkdir($mu_plugins . '/wpe-update-source-selector', 0755, true);
        file_put_contents($mu_plugins . '/mu-plugin.php', "<?php // WP Engine loader\n");
        file_put_contents($mu_plugins . '/stop-long-comments.php', "<?php // WP Engine comment guard\n");
        file_put_contents($mu_plugins . '/my-custom-plugin.php', "<?php // custom\n");

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($mu_plugins . '/wpengine-common');
        $this->assertDirectoryDoesNotExist($mu_plugins . '/wpe-update-source-selector');
        $this->assertFileDoesNotExist($mu_plugins . '/mu-plugin.php');
        $this->assertFileDoesNotExist($mu_plugins . '/stop-long-comments.php');
        $this->assertFileExists($mu_plugins . '/my-custom-plugin.php');
    }
}
