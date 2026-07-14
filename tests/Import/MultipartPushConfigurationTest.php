<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing import test namespace.
namespace ImportTests;

use InvalidArgumentException;
use MultipartPush;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-multipart-push.php';

final class MultipartPushConfigurationTest extends TestCase {
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/multipart-push-config-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testStateDirectoryCannotEqualTheSourceRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside source_root');
        $this->push($this->root . '/source');
    }

    public function testStateDirectoryCannotBeCreatedBelowTheSourceRoot(): void
    {
        $state = $this->root . '/source/private/push-state';
        try {
            $this->push($state);
            self::fail('A state directory below source_root was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('outside source_root', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($state);
    }

    public function testSourceMayBeAChildOfTheStateRootWithoutScanningState(): void
    {
        $push = $this->push($this->root);
        self::assertInstanceOf(MultipartPush::class, $push);
    }

    public function testPushCliRequiresTheStateDirectoryFlag(): void
    {
        $script = realpath(__DIR__ . '/../../packages/reprint-importer/src/import.php');
        self::assertIsString($script);
        $process = proc_open([
            PHP_BINARY,
            $script,
            'push',
            'https://example.com/',
            '--source-root=' . $this->root . '/source',
            '--secret=test-secret',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process), (string) $stdout . (string) $stderr);
        self::assertStringContainsString('--state-dir=DIR is required', (string) $stderr);
    }

    private function push(string $stateDir): MultipartPush
    {
        return new MultipartPush([
            'base_url' => 'https://example.com/',
            'source_root' => $this->root . '/source',
            'state_dir' => $stateDir,
            'secret' => 'test-secret',
        ]);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
