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

    public function testCommandContextUsesTheTargetStateDirectoryAndCanonicalLocalTree(): void
    {
        $targetUrl = 'https://example.test/?reprint-api=1&&';
        $context = ImportClient::prepare_files_push_context(
            $targetUrl,
            $this->stateDirectory,
            $this->localTree,
            ['secret' => 'token', 'force_http' => false]
        );
        $canonicalLocalTree = realpath($this->localTree);
        $this->assertIsString($canonicalLocalTree);
        $trimmedTargetUrl = rtrim($targetUrl, '?&');
        $expectedTargetHash = hash('sha256', $trimmedTargetUrl);

        $this->assertSame($trimmedTargetUrl, $context['target_url']);
        $this->assertSame($canonicalLocalTree, $context['local_tree']);
        $this->assertSame(
            realpath($this->stateDirectory)
                . '/remote-'
                . $expectedTargetHash,
            $context['remote_state_directory']
        );
        $this->assertSame(
            $context['remote_state_directory'] . '/push',
            $context['push_state_directory']
        );
        $this->assertSame(
            $context['remote_state_directory'] . '/.local-index.jsonl',
            $context['local_index_path']
        );

        $differentQuery = ImportClient::prepare_files_push_context(
            'https://example.test/?reprint-api=1&directory=other',
            $this->stateDirectory,
            $this->localTree,
            ['secret' => 'token', 'force_http' => false]
        );
        $otherTree = $this->root . '/other-tree';
        mkdir($otherTree);
        $differentTree = ImportClient::prepare_files_push_context(
            $targetUrl,
            $this->stateDirectory,
            $otherTree,
            ['secret' => 'token', 'force_http' => false]
        );

        $this->assertNotSame(
            $context['push_state_directory'],
            $differentQuery['push_state_directory']
        );
        $this->assertSame(
            $context['local_index_path'],
            $differentTree['local_index_path']
        );
    }

    public function testPullSecretDoesNotChangeTheLocalIndexPath(): void
    {
        $targetUrl = 'https://example.test/?site-export-api';
        $withoutSecret = ImportClient::prepare_files_command_context(
            $targetUrl,
            $this->stateDirectory,
            $this->localTree,
            'files-diff'
        );
        $withSecret = ImportClient::prepare_files_command_context(
            $targetUrl . '&SECRET_KEY=pull-token',
            $this->stateDirectory,
            $this->localTree,
            'files-diff'
        );

        $this->assertSame(
            $withoutSecret['push_state_directory'],
            $withSecret['push_state_directory']
        );
        $this->assertSame(
            $withoutSecret['local_index_path'],
            $withSecret['local_index_path']
        );
    }

    public function testPullAliasMarkerDoesNotChangeTheLocalIndexPath(): void
    {
        $targetUrl = 'https://example.test/?reprint-api=1';
        $beforePullNormalization = ImportClient::prepare_files_command_context(
            $targetUrl,
            $this->stateDirectory,
            $this->localTree,
            'files-diff'
        );
        $afterPullNormalization = ImportClient::prepare_files_command_context(
            $targetUrl . '&site-export-api',
            $this->stateDirectory,
            $this->localTree,
            'files-diff'
        );

        $this->assertSame(
            $beforePullNormalization['remote_state_directory'],
            $afterPullNormalization['remote_state_directory']
        );
    }

    public function testCommandContextRejectsMappingForAnotherLocalTree(): void
    {
        $targetUrl = 'https://example.test/?reprint-api=1';
        $context = ImportClient::prepare_files_command_context(
            $targetUrl,
            $this->stateDirectory,
            $this->localTree,
            'files-push'
        );
        mkdir($context['remote_state_directory'], 0700, true);
        file_put_contents(
            $context['remote_state_directory'] . '/path-mapping.json',
            json_encode([
                'target_url_fingerprint' => hash('sha256', $targetUrl),
                'filesystem_root_b64' => base64_encode($context['local_tree']),
                'local_tree_b64' => base64_encode($context['local_tree']),
                'target_document_root_b64' => base64_encode('/var/www/html'),
                'prefix_rules' => [[
                    'kind' => 'default',
                    'remote_prefix_b64' => base64_encode('/var/www/html'),
                    'local_prefix_b64' => base64_encode($context['local_tree']),
                ]],
            ], JSON_THROW_ON_ERROR)
        );
        $otherTree = $this->root . '/other-tree';
        mkdir($otherTree);
        $canonicalOtherTree = realpath($otherTree);
        $this->assertIsString($canonicalOtherTree);

        try {
            ImportClient::prepare_files_command_context(
                $targetUrl,
                $this->stateDirectory,
                $otherTree,
                'files-push'
            );
            $this->fail('Expected the state directory to reject another local tree.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString(
                'records local tree '
                    . $context['local_tree']
                    . ', not '
                    . $canonicalOtherTree,
                $error->getMessage()
            );
            $this->assertStringContainsString(
                'Use a different --state-dir for this local tree.',
                $error->getMessage()
            );
        }
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

        $dbApplyResult = $this->runCli([
            'db-apply',
            '-',
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
            '--rewrite-url',
            '--force-http',
            'https://example.test',
        ]);
        $this->assertStringNotContainsString(
            '--force-http is accepted only by files-push.',
            $dbApplyResult['output']
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
            'files-push does not accept SECRET_KEY in the target URL; pass --secret=TOKEN.',
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
            'The local tree does not exist or is not a directory: ' . $missingTree . '.',
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
            'The local tree must not be a symlink: ' . $symlinkedTree . '.',
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
        $this->assertStringContainsString('must be outside the local tree', $nestedStateError['error'] ?? '');
        $this->assertStringContainsString( (string) realpath($this->localTree), $nestedStateError['error'] ?? '' );

        $this->assertNoSenderState($this->stateDirectory);
        $this->assertFileDoesNotExist($this->stateDirectory . '/.import-state.json');
    }

    public function testFilesPushRefusesPersistedRemapsBeforeStartingSender(): void
    {
        $targetUrl = 'https://example.test/?reprint-api=1';
        $context = ImportClient::prepare_files_command_context(
            $targetUrl,
            $this->stateDirectory,
            $this->localTree,
            'files-push'
        );
        mkdir($context['remote_state_directory'], 0700, true);
        file_put_contents(
            $context['remote_state_directory'] . '/path-mapping.json',
            json_encode([
                'target_url_fingerprint' => hash('sha256', $targetUrl),
                'filesystem_root_b64' => base64_encode($context['local_tree']),
                'local_tree_b64' => base64_encode($context['local_tree']),
                'target_document_root_b64' => base64_encode('/var/www/html'),
                'prefix_rules' => [[
                    'kind' => 'remap',
                    'remote_prefix_b64' =>
                        base64_encode('/var/www/html/wp-content'),
                    'local_prefix_b64' =>
                        base64_encode($context['local_tree'] . '/content'),
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        try {
            ImportClient::prepare_files_push_context(
                $targetUrl,
                $this->stateDirectory,
                $this->localTree,
                ['secret' => 'token', 'force_http' => false]
            );
            $this->fail('Expected files-push to reject the persisted remap.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString(
                'contains remapped paths',
                $error->getMessage()
            );
        }

        $this->assertDirectoryDoesNotExist(
            $context['push_state_directory']
        );
    }

    public function testFilesPushMasksTheSharedSecretInOutputAndStateFiles(): void
    {
        $secret = 'shared-secret-' . bin2hex(random_bytes(6));
        $targetUrl = 'https://127.0.0.1:1/?reprint-api=1';
        $result = $this->runFilesPush($targetUrl, ['--secret=' . $secret]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringNotContainsString($secret, $result['output']);
        $this->assertStringNotContainsString($secret, $this->readTree($this->stateDirectory));
        $this->assertStringContainsString('--secret=***', $this->readTree($this->stateDirectory));
        $finalLine = $this->lastJsonLine($result['stdout']);
        $this->assertSame('files-push', $finalLine['command'] ?? null);
        $this->assertSame('failed', $finalLine['status'] ?? null);
        $this->assertSame(1, $result['exit']);
        $this->assertFileDoesNotExist($this->stateDirectory . '/.import-state.json');
    }

    public function testCorruptSenderStateUsesTheStructuredWorkflowErrorResult(): void
    {
        $targetUrl = 'https://127.0.0.1:1/?reprint-api=1';
        $context = ImportClient::prepare_files_push_context(
            $targetUrl,
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
                'max_part_bytes' => null,
                'request_sizer_state' => [
                    'request_body_bytes' => 32 * 1024 * 1024,
                    'ceiling_bytes' => null,
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $result = $this->runFilesPush($targetUrl, ['--secret=token']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $finalLine = $this->lastJsonLine($result['stdout']);
        $this->assertSame('files-push', $finalLine['command'] ?? null);
        $this->assertSame('error', $finalLine['status'] ?? null);
        $this->assertSame('starting_plan', $finalLine['phase'] ?? null);
        $this->assertSame('unexpected_error', $finalLine['reason'] ?? null);
        $this->assertStringContainsString('without its directory', $finalLine['detail'] ?? '');
        $this->assertSame('', $result['stderr']);

        $status = json_decode(
            (string) file_get_contents($this->stateDirectory . '/.import-status.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            [
                'command',
                'status',
                'phase',
                'reason',
                'detail',
                'ts',
            ],
            array_keys($status)
        );
        $this->assertSame('error', $status['status']);
        $audit = (string) file_get_contents($this->stateDirectory . '/.import-audit.log');
        $this->assertSame(1, substr_count($audit, 'ERROR files-push'));
        $this->assertStringContainsString('ERROR files-push | phase=starting_plan', $audit);
    }

    /** @param list<string> $extraOptions */
    private function runFilesPush(string $targetUrl, array $extraOptions): array
    {
        return $this->runCli(array_merge([
            'files-push',
            $targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
        ], $extraOptions));
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
        $senderPaths = glob($stateDirectory . '/push/*/sender.json');
        $this->assertIsArray($senderPaths);
        $this->assertSame([], $senderPaths);
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
