<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class PhpBuiltinRuntimeRoutingTest extends TestCase
{
    private string $tempDir;
    private string $docRoot;
    private string $coreRoot;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/php-builtin-routing-' . uniqid('', true);
        $this->docRoot = $this->tempDir . '/docroot';
        $this->coreRoot = $this->tempDir . '/wordpress/core/7.0';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->docRoot . '/wp-content/plugins/example', 0755, true);
        mkdir($this->coreRoot . '/wp-admin/css', 0755, true);

        file_put_contents($this->coreRoot . '/index.php', "<?php echo 'core-index';\n");
        file_put_contents(
            $this->coreRoot . '/wp-admin/install.php',
            "<?php echo 'core-install ' . json_encode([" .
                "'SCRIPT_NAME' => \$_SERVER['SCRIPT_NAME'] ?? null, " .
                "'SCRIPT_FILENAME' => \$_SERVER['SCRIPT_FILENAME'] ?? null" .
            "], JSON_UNESCAPED_SLASHES);\n",
        );
        file_put_contents($this->coreRoot . '/wp-admin/css/install.css', "body{color:#111}\n");
        file_put_contents(
            $this->docRoot . '/wp-content/plugins/example/site.php',
            "<?php echo 'docroot-plugin ' . \$_SERVER['SCRIPT_FILENAME'];\n",
        );

        $manifest = new \RuntimeManifest('other');
        $applier = new \PhpBuiltinApplier();
        $applier->apply($manifest, $this->docRoot, $this->outputDir, [
            'wordpress_index_php' => $this->coreRoot . '/index.php',
        ]);
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

    private function runRuntime(string $requestUri): string
    {
        $oldServer = $_SERVER;
        $bufferLevel = ob_get_level();

        $_SERVER['DOCUMENT_ROOT'] = $this->docRoot;
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['SCRIPT_NAME'] = '/runtime.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->outputDir . '/runtime.php';
        $_SERVER['PHP_SELF'] = '/runtime.php';
        unset($_SERVER['PATH_INFO']);

        ob_start();
        try {
            include $this->outputDir . '/runtime.php';
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_SERVER = $oldServer;
        }
    }

    public function testRoutesCorePhpFilesOutsideDocumentRoot(): void
    {
        $output = $this->runRuntime('/wp-admin/install.php');

        $this->assertStringContainsString('core-install', $output);
        $this->assertStringContainsString('"SCRIPT_NAME":"/wp-admin/install.php"', $output);
        $resolvedInstall = realpath($this->coreRoot . '/wp-admin/install.php');
        $this->assertStringContainsString(
            '"SCRIPT_FILENAME":"' . $resolvedInstall,
            $output,
        );
    }

    public function testDocumentRootPhpFilesTakePrecedenceOverCoreFallback(): void
    {
        $output = $this->runRuntime('/wp-content/plugins/example/site.php');

        $this->assertStringContainsString('docroot-plugin', $output);
        $this->assertStringContainsString(
            $this->docRoot . '/wp-content/plugins/example/site.php',
            $output,
        );
    }

    public function testServesCoreStaticFilesOutsideDocumentRoot(): void
    {
        $output = $this->runRuntime('/wp-admin/css/install.css');

        $this->assertSame("body{color:#111}\n", $output);
    }

    public function testRoutesPhpFilesWithoutTheirQueryString(): void
    {
        $output = $this->runRuntime('/wp-admin/install.php?from=runtime-test');

        $this->assertStringContainsString('core-install', $output);
    }

    public function testRoutesMoreThanOneRequestInTheSameProcess(): void
    {
        $this->assertStringContainsString(
            'core-install',
            $this->runRuntime('/wp-admin/install.php'),
        );
        $this->assertStringContainsString(
            'docroot-plugin',
            $this->runRuntime('/wp-content/plugins/example/site.php'),
        );
    }

    public function testRejectsEncodedParentComponentsBeforeRouting(): void
    {
        $output = $this->runRuntime('/wp-content/%2e%2e/wp-admin/install.php');

        $this->assertSame('', $output);
    }
}
