<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/target-runtime/load.php';

class PlaygroundRemoteUploadProxyRuntimeTest extends TestCase
{
    private $tempDir;
    private $fsRoot;
    private $outputDir;
    private $pullStateFile;
    private $skippedFetchListFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/playground-remote-upload-proxy-' . uniqid();
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';
        $stateDir = $this->tempDir . '/state';

        mkdir($this->fsRoot, 0755, true);
        mkdir($this->outputDir, 0755, true);
        mkdir($stateDir . '/.reprint/pull', 0755, true);

        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
        $this->pullStateFile = $stateDir . '/.reprint/pull/state.json';
        $this->skippedFetchListFile = $stateDir . '/.reprint/pull/skipped-fetch-list.jsonl';
        file_put_contents($this->pullStateFile, "{\"command\":\"files-pull\",\"status\":\"partial\"}\n");
        file_put_contents($this->skippedFetchListFile, "{\"path\":\"/wp-content/uploads/test.jpg\"}\n");
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

    public function testPlaygroundMountsProxyStateFilesIntoVfs(): void
    {
        $manifest = new \RuntimeManifest('other');
        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL'] =
            'https://source.example/wp-content/uploads';
        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE'] =
            $this->pullStateFile;
        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_SKIPPED_FILE'] =
            $this->skippedFetchListFile;
        $manifest->routes[] = [
            'handler' => 'remote-upload-proxy',
            'path_pattern' => '/wp-content/uploads/.*',
            'condition' => 'file_not_found',
            'description' => 'Proxy missing uploads',
        ];

        $applier = new \PlaygroundCliApplier();
        $applier->apply($manifest, $this->fsRoot, $this->outputDir, [
            'port' => 9400,
            'wordpress_index_php' => $this->fsRoot . '/index.php',
        ]);

        $runtime = file_get_contents($this->outputDir . '/runtime.php');
        $startSh = file_get_contents($this->outputDir . '/start.sh');

        $this->assertStringContainsString(
            "/tmp/reprint/state.json",
            $runtime,
        );
        $this->assertStringContainsString(
            "/tmp/reprint/skipped-fetch-list.jsonl",
            $runtime,
        );
        $this->assertStringNotContainsString($this->pullStateFile, $runtime);
        $this->assertStringContainsString(
            "--mount='" . $this->pullStateFile . ":/tmp/reprint/state.json'",
            $startSh,
        );
        $this->assertStringContainsString(
            "--mount='" . $this->skippedFetchListFile . ":/tmp/reprint/skipped-fetch-list.jsonl'",
            $startSh,
        );

        // start.json should contain the same mounts as structured data.
        $startJson = json_decode(file_get_contents($this->outputDir . '/start.json'), true);
        $this->assertNotNull($startJson, 'start.json should be valid JSON');

        $mount_sources = array_column($startJson['mounts'], 'source');
        $mount_targets = array_column($startJson['mounts'], 'target');
        $this->assertContains($this->pullStateFile, $mount_sources);
        $this->assertContains($this->skippedFetchListFile, $mount_sources);
        $this->assertContains('/tmp/reprint/state.json', $mount_targets);
        $this->assertContains('/tmp/reprint/skipped-fetch-list.jsonl', $mount_targets);
    }
}
