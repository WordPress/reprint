<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class RemoteUploadProxyRuntimeTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remote-upload-proxy-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
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
                'command_name' => null,
                'completion_state' => null,
                'current_stage' => null,
                'remote_cursor' => null,
            ],
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => $this->fsRoot,
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
            'webhost' => 'other',
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

    private function runApplyRuntime(\ImportClient $client): string
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

        return file_get_contents($this->outputDir . '/runtime.php');
    }

    public function testApplyRuntimeAddsProxyForEssentialFilesFilter(): void
    {
        $client = $this->makeClient();
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'db-apply',
                'completion_state' => 'complete',
            ],
            'filter' => 'essential-files',
        ]);
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);
        $resolvedPullStateDirectory =
            realpath($client->pull_state_directory)
            ?: $client->pull_state_directory;

        $this->assertStringContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringContainsString(
            "define('REPRINT_PULL_STATE_FILE', '"
                . $resolvedPullStateDirectory
                . "/state.json');",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "REPRINT_PULL_SKIPPED_FETCH_LIST_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "https://source.example/wp-content/uploads",
            $runtime,
        );
        $this->assertStringContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }

    public function testApplyRuntimeAddsProxyWhileFilesPullIsIncomplete(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'fetch',
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringContainsString(
            "REPRINT_PULL_STATE_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "Proxy missing uploads from the source site until files-pull completes.",
            $runtime,
        );
    }

    public function testApplyRuntimeOmitsProxyAfterFilesPullCompletes(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
            ],
            'filter' => 'none',
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringNotContainsString(
            "REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "REPRINT_PULL_STATE_FILE",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }
}
