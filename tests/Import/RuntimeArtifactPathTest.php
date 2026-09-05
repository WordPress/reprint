<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-configuration.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class RuntimeArtifactPathTest extends TestCase
{
    private string $tempDir;
    private string $filesystemRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/runtime-artifact-paths-' . uniqid('', true);
        $this->filesystemRoot = $this->tempDir . '/filesystem-root';
        mkdir($this->filesystemRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testNginxRuntimeArtifactsUseOneSeparator(): void
    {
        $outputDir = $this->tempDir . '/nginx///';
        $canonicalOutputDir = $this->tempDir . '/nginx';

        $applier = new \NginxFpmApplier();
        $summary = $applier->apply(
            new \RuntimeConfiguration('other'),
            $this->filesystemRoot,
            $outputDir,
        );

        $this->assertSame([
            "Wrote {$canonicalOutputDir}/runtime.php",
            "Wrote {$canonicalOutputDir}/nginx.conf",
        ], array_slice($summary, 0, 2));
        $this->assertFileExists($canonicalOutputDir . '/runtime.php');
        $this->assertFileExists($canonicalOutputDir . '/nginx.conf');
    }

    public function testPhpBuiltinRuntimeArtifactsUseOneSeparator(): void
    {
        $outputDir = $this->tempDir . '/php-builtin///';
        $canonicalOutputDir = $this->tempDir . '/php-builtin';

        $applier = new \PhpBuiltinApplier();
        $summary = $applier->apply(
            new \RuntimeConfiguration('other'),
            $this->filesystemRoot,
            $outputDir,
        );

        $this->assertSame([
            "Wrote {$canonicalOutputDir}/runtime.php",
            "Wrote {$canonicalOutputDir}/start.sh",
        ], array_slice($summary, 0, 2));
        $this->assertFileExists($canonicalOutputDir . '/runtime.php');
        $this->assertFileExists($canonicalOutputDir . '/start.sh');
    }

    public function testPlaygroundRuntimeArtifactsUseOneSeparator(): void
    {
        $outputDir = $this->tempDir . '/playground///';
        $canonicalOutputDir = $this->tempDir . '/playground';

        $applier = new \PlaygroundCliApplier();
        $summary = $applier->apply(
            new \RuntimeConfiguration('other'),
            $this->filesystemRoot,
            $outputDir,
        );

        $this->assertSame([
            "Wrote {$canonicalOutputDir}/runtime.php",
            "Wrote {$canonicalOutputDir}/blueprint.json",
            "Wrote {$canonicalOutputDir}/start.sh",
            "Wrote {$canonicalOutputDir}/start.json",
        ], array_slice($summary, 0, 4));
        $this->assertFileExists($canonicalOutputDir . '/runtime.php');
        $this->assertFileExists($canonicalOutputDir . '/blueprint.json');
        $this->assertFileExists($canonicalOutputDir . '/start.sh');
        $this->assertFileExists($canonicalOutputDir . '/start.json');

        $startConfig = json_decode(
            file_get_contents($canonicalOutputDir . '/start.json'),
            true,
        );
        $this->assertIsArray($startConfig);
        $this->assertSame(
            $canonicalOutputDir . '/blueprint.json',
            $startConfig['blueprint'],
        );
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

            $this->recursiveDelete($path);
        }

        rmdir($dir);
    }
}
