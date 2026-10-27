<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class PlaygroundRemoteUploadProxyRuntimeTest extends TestCase
{
    private $tempDir;
    private $fsRoot;
    private $outputDir;
    private $pullStateFile;
    private $proxyStatusFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/playground-remote-upload-proxy-' . uniqid();
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';
        $stateDir = $this->tempDir . '/state';

        mkdir($this->fsRoot, 0755, true);
        mkdir($this->outputDir, 0755, true);
        mkdir($stateDir . '/pull', 0755, true);
        mkdir($stateDir . '/runtime', 0755, true);

        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
        $this->pullStateFile = $stateDir . '/pull/state.json';
        file_put_contents($this->pullStateFile, "{\"command\":\"files-pull\",\"status\":\"partial\"}\n");
        $this->proxyStatusFile = $stateDir . '/runtime/upload-proxy.json';
        file_put_contents($this->proxyStatusFile, "{\"proxy_enabled\":true}\n");
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

    public function testPlaygroundMountsOnlyProxyStatusIntoVfs(): void
    {
        $manifest = new \RuntimeManifest('other');
        $manifest->constants['REPRINT_REMOTE_UPLOAD_PROXY_BASE_URL'] =
            'https://source.example/wp-content/uploads';
        $manifest->constants['REPRINT_REMOTE_UPLOAD_PROXY_STATUS_FILE'] =
            $this->proxyStatusFile;
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
            "/tmp/reprint/upload-proxy.json",
            $runtime,
        );
        $this->assertStringNotContainsString($this->pullStateFile, $runtime);
        $this->assertStringContainsString(
            "--mount='" . $this->proxyStatusFile . ":/tmp/reprint/upload-proxy.json'",
            $startSh,
        );
        $this->assertStringNotContainsString($this->pullStateFile, $startSh);
        // start.json should contain the same mount as structured data.
        $startJson = json_decode(file_get_contents($this->outputDir . '/start.json'), true);
        $this->assertNotNull($startJson, 'start.json should be valid JSON');

        $mount_sources = array_column($startJson['mounts'], 'source');
        $mount_targets = array_column($startJson['mounts'], 'target');
        $this->assertContains($this->proxyStatusFile, $mount_sources);
        $this->assertNotContains($this->pullStateFile, $mount_sources);
        $this->assertContains('/tmp/reprint/upload-proxy.json', $mount_targets);
        $this->assertNotContains('/tmp/reprint/state.json', $mount_targets);
    }

    public function testPlaygroundConfigNormalizesATrailingOutputDirectorySlash(): void
    {
        $manifest = new \RuntimeManifest('other');
        $applier = new \PlaygroundCliApplier();
        $applier->apply($manifest, $this->fsRoot, $this->outputDir . '/', [
            'wordpress_index_php' => $this->fsRoot . '/index.php',
        ]);

        $config = json_decode(
            file_get_contents($this->outputDir . '/start.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($this->outputDir . '/blueprint.json', $config['blueprint']);
        $this->assertSame(
            $this->outputDir . '/runtime.php',
            $config['mounts'][0]['source'],
        );
    }
}
