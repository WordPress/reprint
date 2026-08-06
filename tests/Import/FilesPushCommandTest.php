<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use ImportClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class FilesPushCommandTest extends TestCase
{
    private string $root;
    private string $localTree;
    private string $stateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/files-push-command-' . bin2hex(random_bytes(6));
        $this->localTree = $this->root . '/local-tree';
        $this->stateDirectory = $this->root . '/state';
        mkdir($this->localTree, 0700, true);
        mkdir($this->stateDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testCallerBudgetDecisionUsesFiniteAndUnlimitedLimits(): void
    {
        $chunkBytes = 20;

        $this->assertNull(ImportClient::files_push_stop_cause(7.99, 59, 10, 100, $chunkBytes));
        $this->assertSame('time_limit', ImportClient::files_push_stop_cause(8.0, 0, 10, -1, $chunkBytes));
        $this->assertNull(ImportClient::files_push_stop_cause(1000.0, 59, 0, 100, $chunkBytes));
        $this->assertSame('memory_limit', ImportClient::files_push_stop_cause(0.0, 60, 0, 100, $chunkBytes));
        $this->assertNull(ImportClient::files_push_stop_cause(1000.0, PHP_INT_MAX, 0, -1, $chunkBytes));
    }

    public function testPushStateDirectoryUsesTheStateDirectoryAndTrimmedRemoteReprintApiUrl(): void
    {
        $remoteReprintApiUrl = 'https://example.test/?reprint-api=1&&';
        $context = ImportClient::prepare_files_push_context(
            $remoteReprintApiUrl,
            $this->stateDirectory,
            $this->localTree,
            ['secret' => 'token', 'force_http' => false]
        );
        $trimmedRemoteReprintApiUrl = rtrim($remoteReprintApiUrl, '?&');
        $expectedPushStateDirectory =
            realpath($this->stateDirectory)
            . '/remotes/'
            . md5($trimmedRemoteReprintApiUrl)
            . '/push';

        $this->assertSame($trimmedRemoteReprintApiUrl, $context['remote_reprint_api_url']);
        $this->assertSame(realpath($this->localTree), $context['filesystem_root']);
        $this->assertSame($expectedPushStateDirectory, $context['push_state_directory']);

        $differentQuery = ImportClient::prepare_files_push_context(
            'https://example.test/?reprint-api=1&directory=other',
            $this->stateDirectory,
            $this->localTree,
            ['secret' => 'token', 'force_http' => false]
        );
        $otherFilesystemRoot = $this->root . '/other-filesystem-root';
        $otherStateDirectory = $this->root . '/other-state';
        mkdir($otherFilesystemRoot);
        mkdir($otherStateDirectory);
        $differentFilesystemRootContext = ImportClient::prepare_files_push_context(
            $remoteReprintApiUrl,
            $otherStateDirectory,
            $otherFilesystemRoot,
            ['secret' => 'token', 'force_http' => false]
        );

        $this->assertNotSame(
            $context['push_state_directory'],
            $differentQuery['push_state_directory']
        );
        $this->assertSame(
            realpath($otherStateDirectory)
                . '/remotes/'
                . md5($trimmedRemoteReprintApiUrl)
                . '/push',
            $differentFilesystemRootContext['push_state_directory']
        );
    }

    public function testFilesPushRejectsOptionsOutsideItsExactAllowlist(): void
    {
        foreach (['--abort', '--filter=none', '--docroot=' . $this->localTree, '--duty=0.5'] as $rejectedOption) {
            $result = $this->runCli([
                'files-push',
                'https://example.test/?reprint-api=1',
                '--state-dir=' . $this->stateDirectory,
                '--fs-root=' . $this->localTree,
                '--secret=token',
                $rejectedOption,
            ]);

            $this->assertSame(1, $result['exit'], $rejectedOption . ': ' . $result['output']);
            $this->assertStringContainsString('files-push does not accept', $result['output']);
            $this->assertStringContainsString(strtok($rejectedOption, '='), $result['output']);
        }

        $olderCommand = $this->runCli([
            'files-pull',
            'https://example.test/?reprint-api=1',
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
            '--force-http',
        ]);
        $this->assertSame(1, $olderCommand['exit'], $olderCommand['output']);
        $this->assertStringContainsString('--force-http is accepted only by files-push.', $olderCommand['output']);

        $rewriteUrlWithForceHttpSource = $this->runCli([
            'db-apply',
            'https://example.test/?reprint-api=1',
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
            '--rewrite-url',
            '--force-http',
            'https://example.test',
        ]);
        $this->assertStringNotContainsString(
            '--force-http is accepted only by files-push.',
            $rewriteUrlWithForceHttpSource['output']
        );
    }

    public function testFilesPushRejectsInvalidInputsBeforeStartingSender(): void
    {
        $missingSecret = $this->runFilesPush('https://example.test/?reprint-api=1', []);
        $this->assertSame(1, $missingSecret['exit']);
        $this->assertStringContainsString('files-push requires --secret=TOKEN.', $missingSecret['output']);
        $missingSecretError = $this->lastJsonLine($missingSecret['stderr']);
        $this->assertArrayHasKey('error', $missingSecretError);
        $this->assertArrayNotHasKey('command', $missingSecretError);
        $this->assertArrayNotHasKey('phase', $missingSecretError);

        $querySecret = $this->runFilesPush(
            'https://example.test/?reprint-api=1&SECRET_KEY=query-secret',
            ['--secret=token']
        );
        $this->assertSame(1, $querySecret['exit']);
        $this->assertStringContainsString(
            'files-push does not accept SECRET_KEY in the remote Reprint API URL; pass --secret=TOKEN.',
            $querySecret['output']
        );
        $this->assertStringNotContainsString('query-secret', $querySecret['output']);

        $fragment = $this->runFilesPush(
            'https://example.test/?reprint-api=1#section',
            ['--secret=token']
        );
        $this->assertSame(1, $fragment['exit']);
        $this->assertStringContainsString('must not contain a fragment', $fragment['output']);

        $urlPassword = 'url-password-' . bin2hex(random_bytes(4)) . '@inner';
        $userInfo = $this->runFilesPush(
            'https://operator:' . $urlPassword . '@example.test/?reprint-api=1',
            ['--secret=token']
        );
        $this->assertSame(1, $userInfo['exit']);
        $this->assertStringContainsString('must not contain URL user-info', $userInfo['output']);
        $this->assertStringNotContainsString($urlPassword, $userInfo['output']);
        $this->assertStringNotContainsString($urlPassword, $this->readTree($this->stateDirectory));

        $plainHttp = $this->runFilesPush(
            'http://example.test/?reprint-api=1',
            ['--secret=token']
        );
        $this->assertSame(1, $plainHttp['exit']);
        $this->assertStringContainsString('must use HTTPS', $plainHttp['output']);
        $this->assertStringContainsString('--force-http', $plainHttp['output']);

        $missingTree = $this->root . '/missing-tree';
        $missingTreeResult = $this->runCli([
            'files-push',
            'https://example.test/?reprint-api=1',
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $missingTree,
            '--secret=token',
        ]);
        $this->assertSame(1, $missingTreeResult['exit']);
        $missingTreeError = $this->lastJsonLine($missingTreeResult['stderr']);
        $this->assertSame(
            'The filesystem root does not exist or is not a directory: ' . $missingTree . '.',
            $missingTreeError['error'] ?? null
        );
        $this->assertDirectoryDoesNotExist($missingTree);

        $symlinkedTree = $this->root . '/symlinked-tree';
        symlink($this->localTree, $symlinkedTree);
        $symlinkResult = $this->runCli([
            'files-push',
            'https://example.test/?reprint-api=1',
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $symlinkedTree,
            '--secret=token',
        ]);
        $this->assertSame(1, $symlinkResult['exit']);
        $symlinkError = $this->lastJsonLine($symlinkResult['stderr']);
        $this->assertSame(
            'The filesystem root must not be a symlink: ' . $symlinkedTree . '.',
            $symlinkError['error'] ?? null
        );

        $nestedState = $this->localTree . '/state';
        $nestedStateResult = $this->runCli([
            'files-push',
            'https://example.test/?reprint-api=1',
            '--state-dir=' . $nestedState,
            '--fs-root=' . $this->localTree,
            '--secret=token',
        ]);
        $this->assertSame(1, $nestedStateResult['exit']);
        $nestedStateError = $this->lastJsonLine($nestedStateResult['stderr']);
        $this->assertStringContainsString('must be outside the filesystem root', $nestedStateError['error'] ?? '');
        $this->assertStringContainsString( (string) realpath($this->localTree), $nestedStateError['error'] ?? '' );

        $this->assertNoSenderState($this->stateDirectory);
        $this->assertFileDoesNotExist(
            $this->pullStateFileForRemoteReprintApiUrl(
                'https://example.test/?reprint-api=1'
            )
        );
    }

    public function testFilesPushRequiresPreflightBeforeStartingSender(): void
    {
        $remoteReprintApiUrl = 'https://127.0.0.1:1/?reprint-api=1';

        $result = $this->runFilesPush($remoteReprintApiUrl, ['--secret=token']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('No preflight data found', $result['output']);
        $this->assertNoSenderState($remoteReprintApiUrl);
    }

    public function testFilesPushMasksTheSharedSecretInOutputAndStateFiles(): void
    {
        $secret = 'shared-secret-' . bin2hex(random_bytes(6));
        $remoteReprintApiUrl = 'https://127.0.0.1:1/?reprint-api=1';
        $this->writePreflightState($remoteReprintApiUrl);
        $result = $this->runFilesPush($remoteReprintApiUrl, ['--secret=' . $secret]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringNotContainsString($secret, $result['output']);
        $this->assertStringNotContainsString($secret, $this->readTree($this->stateDirectory));
        $this->assertStringContainsString('--secret=***', $this->readTree($this->stateDirectory));
        $finalLine = $this->lastJsonLine($result['stdout']);
        $this->assertSame('files-push', $finalLine['command'] ?? null);
        $this->assertSame('failed', $finalLine['status'] ?? null);
        $this->assertSame(1, $result['exit']);
        $this->assertFileExists(
            $this->pullStateFileForRemoteReprintApiUrl($remoteReprintApiUrl)
        );
    }

    public function testCorruptSenderStateUsesTheStructuredWorkflowErrorResult(): void
    {
        $remoteReprintApiUrl = 'https://127.0.0.1:1/?reprint-api=1';
        $this->writePreflightState($remoteReprintApiUrl);
        $context = ImportClient::prepare_files_push_context(
            $remoteReprintApiUrl,
            $this->stateDirectory,
            $this->localTree,
            ['secret' => 'token', 'force_http' => false]
        );
        mkdir($context['push_state_directory'], 0700, true);
        file_put_contents(
            $context['push_state_directory'] . '/sender.json',
            json_encode([
                'push_session_id' => str_repeat('1', 32),
                'phase' => 'starting_plan',
                'push_plan_cursor' => null,
                'local_paths_to_push_byte_offset' => 0,
                'local_paths_to_push_count' => null,
                'local_paths_pushed' => 0,
                'max_part_bytes' => null,
                'request_sizer_state' => [
                    'request_body_bytes' => 32 * 1024 * 1024,
                    'ceiling_bytes' => null,
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $result = $this->runFilesPush($remoteReprintApiUrl, ['--secret=token']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $finalLine = $this->lastJsonLine($result['stdout']);
        $this->assertSame('files-push', $finalLine['command'] ?? null);
        $this->assertSame('error', $finalLine['status'] ?? null);
        $this->assertSame('starting_plan', $finalLine['phase'] ?? null);
        $this->assertSame('unexpected_error', $finalLine['reason'] ?? null);
        $this->assertStringContainsString('without its directory', $finalLine['detail'] ?? '');
        $this->assertSame('', $result['stderr']);

        $progress = json_decode(
            (string) file_get_contents($this->stateDirectory . '/progress.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            ['command', 'status', 'phase', 'reason', 'detail', 'ts'],
            array_keys($progress)
        );
        $this->assertSame('error', $progress['status']);
        $audit = (string) file_get_contents($this->stateDirectory . '/audit.log');
        $this->assertSame(1, substr_count($audit, 'ERROR files-push'));
        $this->assertStringContainsString(
            'ERROR files-push | phase=starting_plan | reason=unexpected_error',
            $audit
        );
    }

    /** @param list<string> $extraOptions */
    private function runFilesPush(string $remoteReprintApiUrl, array $extraOptions): array
    {
        return $this->runCli(array_merge([
            'files-push',
            $remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
        ], $extraOptions));
    }

    private function writePreflightState(
        string $remoteReprintApiUrl,
        string $pushRoot = '/'
    ): void {
        $pullStateFile = $this->pullStateFileForRemoteReprintApiUrl($remoteReprintApiUrl);
        $pullStateDirectory = dirname($pullStateFile);
        if (!is_dir($pullStateDirectory)) {
            mkdir($pullStateDirectory, 0700, true);
        }
        $pullState = new \PullState();
        $pullState->preflight = [
            'http_code' => 200,
            'data' => [
                'runtime' => [
                    'document_root' => 'base64:' . base64_encode($pushRoot),
                ],
            ],
        ];
        file_put_contents(
            $pullStateFile,
            json_encode($pullState->to_array(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /** @param list<string> $arguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments): array
    {
        $command = array_merge(
            [PHP_BINARY, __DIR__ . '/../../importer/import.php'],
            $arguments
        );
        $process = proc_open(
            $command,
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

    /** @return array<string,mixed> */
    private function lastJsonLine(string $output): array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $this->fail('No JSON line was found in output: ' . $output);
    }

    private function assertNoSenderState(string $stateDirectory): void
    {
        $senderPaths = glob($stateDirectory . '/remotes/*/push/sender.json');
        $this->assertIsArray($senderPaths);
        $this->assertSame([], $senderPaths);
    }

    private function pullStateFileForRemoteReprintApiUrl(
        string $remoteReprintApiUrl
    ): string
    {
        return $this->stateDirectory
            . '/remotes/'
            . md5(rtrim($remoteReprintApiUrl, '?&'))
            . '/pull/state.json';
    }

    private function readTree(string $path): string
    {
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
        if (!is_dir($path)) {
            return '';
        }
        $contents = '';
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $contents .= $this->readTree($path . '/' . $entry);
            }
        }
        return $contents;
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
