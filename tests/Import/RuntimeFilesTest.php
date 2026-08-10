<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Tests for auto_prepend/append script downloading during preflight.
 *
 * The import side reads auto_prepend_file and auto_append_file paths
 * from ini_get_all in the preflight response and downloads them via
 * the file_fetch endpoint into state_dir/runtime_files/.
 *
 * Since unit tests cannot hit a real server, these tests cover:
 * - Directory wipe/create lifecycle of runtime_files/
 * - Graceful handling when no scripts are configured
 * - Tolerance of fetch failures
 */
class RuntimeFilesTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/runtime-files-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
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
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root);
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), array_replace_recursive([
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
        ], $state));
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $p = $reflection->getProperty($property);
        $p->setValue($client, $value);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    /**
     * When no auto_prepend/append scripts are configured, the
     * runtime_files/ directory is not created.
     */
    public function testNoScriptsNoDirectory()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "",
                            "auto_append_file" => "",
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'fetch_runtime_files');

        $this->assertDirectoryDoesNotExist($this->stateDir . '/runtime_files');
    }

    /**
     * fetch_runtime_files() wipes the existing runtime_files/ directory
     * on re-run.
     */
    public function testRuntimeFilesDirWipedOnRerun()
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        mkdir($runtimeDir . '/old', 0755, true);
        file_put_contents($runtimeDir . '/old/stale.php', '<?php // old');

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "/scripts/prepend.php",
                            "auto_append_file" => "",
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'fetch_runtime_files');

        // The old stale file should be gone because the directory was wiped.
        $this->assertFileDoesNotExist($runtimeDir . '/old/stale.php');
    }

    /**
     * fetch_runtime_files() tolerates fetch failures without throwing.
     */
    public function testDownloadToleratesFetchFailure()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "/scripts/prepend.php",
                            "auto_append_file" => "/scripts/append.php",
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);

        // This must not throw — failures are caught internally.
        $this->callPrivate($client, 'fetch_runtime_files');

        // The directory should exist even though no files were downloaded.
        $this->assertDirectoryExists($this->stateDir . '/runtime_files');
    }
}
