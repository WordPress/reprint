<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
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
            new \RuntimeManifest('other'),
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
            new \RuntimeManifest('other'),
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
            new \RuntimeManifest('other'),
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

        // Runtime startup must use the CLI version exercised by the harness.
        $package = json_decode(file_get_contents(__DIR__ . '/../e2e/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($package['dependencies']['@php-wasm/node'], $package['dependencies']['@wp-playground/cli']);
        $this->assertSame($package['dependencies']['@php-wasm/universal'], $package['dependencies']['@wp-playground/cli']);
        $this->assertStringContainsString(
            'npx --yes @wp-playground/cli@' . $package['dependencies']['@wp-playground/cli'] . ' server',
            file_get_contents($canonicalOutputDir . '/start.sh')
        );

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

    /**
     * Playground splits a joined source:target mount at every colon, so a
     * Windows drive in the source breaks it. Each path must arrive as its
     * own argument.
     */
    public function testPlaygroundStartScriptPassesMountPathsAsSeparateArguments(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('start.sh needs Bash.');
        }
        $filesystemRoot = $this->tempDir . '/C:/site';
        mkdir($filesystemRoot, 0755, true);
        $outputDir = $this->tempDir . '/playground';
        $fakeBinDir = $this->tempDir . '/bin';
        mkdir($fakeBinDir);
        file_put_contents($fakeBinDir . '/npx', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
        chmod($fakeBinDir . '/npx', 0755);

        ( new \PlaygroundCliApplier() )->apply(new \RuntimeManifest('other'), $filesystemRoot, $outputDir);
        exec(
            'PATH=' . escapeshellarg($fakeBinDir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($outputDir . '/start.sh'),
            $output,
            $exitCode,
        );

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $arguments = array_slice($output, array_search('server', $output, true) + 1);
        $this->assertSame(
            ['--mount-dir-before-install', realpath($filesystemRoot), '/wordpress'],
            array_slice($arguments, 0, 3),
        );
        $this->assertSame(
            ['--mount-dir', $outputDir . '/runtime.php', '/wordpress/wp-content/mu-plugins/0-playground-runtime.php'],
            array_slice($arguments, 3, 3),
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
