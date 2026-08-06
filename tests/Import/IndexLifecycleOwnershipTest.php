<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../client/import.php';

final class IndexLifecycleOwnershipTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $filesystemRoot;
    private string $remoteReprintApiUrl =
        'https://example.test/export.php?site-export-api';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/index-lifecycle-ownership-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->filesystemRoot = $this->root . '/filesystem-root';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->filesystemRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    /**
     * @dataProvider commandBlockedByFilesPullProvider
     * @param list<string> $extraArguments
     */
    public function testUnfinishedFilesPullBlocksLocalIndexCommands(
        string $command,
        array $extraArguments
    ): void {
        mkdir($this->pullStateDirectory(), 0700, true);
        file_put_contents(
            $this->pullStateDirectory() . '/index.wal',
            ''
        );

        $result = $this->runCli(array_merge([
            $command,
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
        ], $extraArguments));

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString(
            'Finish or abort the interrupted files-pull before running '
                . $command,
            $result['output']
        );
    }

    /**
     * @return array<string,array{string,list<string>}>
     */
    public static function commandBlockedByFilesPullProvider(): array
    {
        return [
            'files-diff' => ['files-diff', []],
            'files-push' => [
                'files-push',
                ['--secret=secret', '--force-http'],
            ],
        ];
    }

    public function testUnfinishedFilesPushBlocksFilesPull(): void
    {
        $pushStateDirectory = $this->remoteStateDirectory() . '/push';
        mkdir($pushStateDirectory, 0700, true);
        file_put_contents($pushStateDirectory . '/sender.json', "{}\n");

        $client = new \ImportClient(
            $this->remoteReprintApiUrl,
            $this->stateDirectory,
            $this->filesystemRoot
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Finish the unfinished files-push before running files-pull.'
        );
        $client->run_files_pull();
    }

    private function pullStateDirectory(): string
    {
        return $this->remoteStateDirectory() . '/pull';
    }

    private function remoteStateDirectory(): string
    {
        return realpath($this->stateDirectory)
            . '/remotes/'
            . md5(rtrim($this->remoteReprintApiUrl, '?&'));
    }

    /**
     * @param list<string> $arguments
     * @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            array_merge([
                PHP_BINARY,
                __DIR__ . '/../../client/import.php',
            ], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
