<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the Import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** Exercises saved settings through the real CLI, including separate processes. */
final class SavedRemoteCommandTest extends TestCase {
    private string $directory;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reprint-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->removeTree($this->directory);
    }

    public function testAddSavesSettingsWithoutContactingTheRemoteOrCreatingTheFilesystemRoot(): void
    {
        $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', '--target-engine=sqlite', '--remap', ':wp-content:', ':fs-root:/content', '--rewrite-url', 'https://example.invalid', 'http://localhost:8881']);
        $this->assertSame(0, $result['code'], $result['output']);
        $config = $this->config();
        $this->assertSame('source', $config['remote']['name']);
        $this->assertSame('https://example.invalid', $config['remote']['remote_reprint_api_url']);
        $this->assertSame([[':wp-content:', ':fs-root:/content']], $config['remote']['options']['remap']);
        $this->assertSame('sqlite', $config['local']['target-engine']);
        $this->assertDirectoryDoesNotExist($this->directory . '/files');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes');
    }

    public function testSecondRemoteDoesNotReplaceTheFirst(): void
    {
        $this->addRemote();
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['remote', 'add', 'staging', 'https://staging.invalid', '--fs-root=./other']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('already has remote', $result['output']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
    }

    public function testShowResolvesSavedPathsFromAnotherWorkingDirectoryAndDoesNotReadSecrets(): void
    {
        $this->addRemote(['--secret-file=./not-created.secret']);
        mkdir($this->directory . '/elsewhere');
        $result = $this->runCli(['config', 'show', '--command=files-pull', '--config=' . $this->directory . '/.reprint/config.json'], $this->directory . '/elsewhere');
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertSame($this->directory . '/files', $effective['filesystem_root']);
        $this->assertSame($this->directory . '/.reprint/state/remotes/source', $effective['remote_state_directory']);
        $this->assertSame($this->directory . '/not-created.secret', $effective['options']['secret_file']);
    }

    public function testOverridesReplaceSavedListsWithoutEditingConfig(): void
    {
        $this->addRemote(['--include', ':wp-content:', '--include', ':wp-plugins:']);
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['config', 'show', '--command=files-pull', '--include', ':wp-uploads:']);
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertSame([':wp-uploads:'], $effective['options']['include']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
    }

    public function testLocalCommandsDoNotLoadRemoteCredentialsOrPullOptions(): void
    {
        $this->addRemote(['--secret-file=./absent.secret', '--include', ':wp-content:', '--target-engine=sqlite']);
        mkdir($this->directory . '/files');
        $result = $this->runCli(['files-diff']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('local_index.jsonl', $result['output']);
        $this->assertStringNotContainsString('absent.secret', $result['output']);
        $this->assertStringNotContainsString('does not accept', $result['output']);
    }

    public function testSetUrlKeepsTheRemoteNameAndRewriteRules(): void
    {
        $this->addRemote(['--rewrite-url', 'https://example.invalid', 'http://localhost:8881']);
        $result = $this->runCli(['remote', 'set-url', 'source', 'https://www.example.invalid']);
        $this->assertSame(0, $result['code'], $result['output']);
        $config = $this->config();
        $this->assertSame('source', $config['remote']['name']);
        $this->assertSame('https://www.example.invalid', $config['remote']['remote_reprint_api_url']);
        $this->assertSame([['https://example.invalid', 'http://localhost:8881']], $config['remote']['options']['rewrite-url']);
    }

    public function testActionFlagsAndInlineSecretsCannotBecomeDefaults(): void
    {
        foreach (['--abort', '--force', '--insecure', '--secret=do-not-save', '--new-site-url=http://localhost'] as $argument) {
            $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', $argument]);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
            $this->assertStringNotContainsString('do-not-save', $result['output']);
        }
    }

    public function testExplicitUrlAndNoConfigKeepTheExistingRequiredArguments(): void
    {
        $this->addRemote();
        $result = $this->runCli(['preflight', 'https://other.invalid']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--state-dir=DIR is required', $result['output']);
        $result = $this->runCli(['preflight', '--no-config']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('<remote-reprint-api-url> is required', $result['output']);
    }

    public function testRedirectDoesNotContactTheNewAddressOrChangeConfig(): void
    {
        $url = $this->startServer();
        $result = $this->runCli(['remote', 'add', 'source', $url . '/redirect', '--fs-root=./files', '--secret-file=./secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('REDIRECT', $result['output']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
        foreach (file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $this->assertSame('/redirect', json_decode($line, true)['path']);
        }
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/new']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringNotContainsString('tls-test-secret', $result['output']);
    }

    public function testCompletedPullAndPushKeepTheirIndexAcrossAnExplicitAddressChange(): void
    {
        $url = $this->startServer();
        $remote_directory = $this->directory . '/remote';
        $result = $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret', '--include', $remote_directory]);
        $this->assertSame(0, $result['code'], $result['output']);
        foreach (['preflight', 'files-pull'] as $command) {
            $result = $this->runCli([$command, '--insecure']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        $local_file = $this->directory . '/files' . $remote_directory . '/example.txt';
        $this->assertSame('remote contents', file_get_contents($local_file));
        $index_file = $this->directory . '/.reprint/state/remotes/source/local_index.jsonl';
        $index = file_get_contents($index_file);
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/new']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('--same-remote', $result['output']);
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/new', '--same-remote']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame($index, file_get_contents($index_file));
        $result = $this->runCli(['files-push', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('Run preflight', $result['output']);
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        file_put_contents($local_file, 'local contents to push');
        $result = $this->runCli(['files-diff', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('"action":"push"', $result['output']);
        $result = $this->runCli(['files-push', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame('local contents to push', file_get_contents($remote_directory . '/example.txt'));
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5($url . '/new'));
    }

    public function testSavedRemapIsAppliedOnEachPullWithoutRepeatingFlags(): void
    {
        $url = $this->startServer();
        $remote_directory = $this->directory . '/remote';
        $result = $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./secret', '--include', $remote_directory, '--remap', $remote_directory, ':fs-root:/site']);
        $this->assertSame(0, $result['code'], $result['output']);
        foreach (['preflight', 'files-pull'] as $command) {
            $result = $this->runCli([$command, '--insecure']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        $local_file = $this->directory . '/files/site/example.txt';
        $this->assertSame('remote contents', file_get_contents($local_file));
        file_put_contents($remote_directory . '/example.txt', 'changed remote contents');
        $result = $this->runCli(['files-pull', '--abort', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['files-pull', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame('changed remote contents', file_get_contents($local_file));
    }

    public function testProcessDeathDuringAnIndexRequestPreventsAnAddressChange(): void
    {
        $url = $this->startServer();
        $result = $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret', '--include', $this->directory . '/remote']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        file_put_contents($this->directory . '/slow-index', '1');
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'files-index', '--insecure'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/interrupted.log', 'a'], 2 => ['redirect', 1]], $pipes, $this->directory);
        fclose($pipes[0]);
        $observed = false;
        try {
            $deadline = microtime(true) + 5;
            do {
                foreach (file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
                    if (json_decode($line, true)['endpoint'] === 'file_index') {
                        $observed = true;
                        break 2;
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
        $this->assertTrue($observed, file_get_contents($this->directory . '/interrupted.log'));
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/new', '--same-remote']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('unfinished', $result['output']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
    }

    public function testSecretOverrideDoesNotReadTheSavedFileOrPrintTheToken(): void
    {
        $url = $this->startServer();
        $this->assertSame(0, $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./absent.secret'])['code']);
        $result = $this->runCli(['config', 'show', '--command=preflight', '--secret=tls-test-secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertArrayNotHasKey('secret_file', $effective['options']);
        $this->assertSame('[redacted]', $effective['options']['secret']);
        $result = $this->runCli(['preflight', '--insecure', '--secret=tls-test-secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringNotContainsString('tls-test-secret', $result['output']);
    }

    public function testDatabasePushRequiresItsOwnRewriteDirection(): void
    {
        $this->addRemote(['--rewrite-url', 'https://example.invalid', 'http://localhost:8881']);
        $result = $this->runCli(['config', 'show', '--command=db-push']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertArrayNotHasKey('rewrite_url', json_decode($result['output'], true)['options']);
        $result = $this->runCli(['config', 'show', '--command=db-push', '--rewrite-url', 'http://localhost:8881', 'https://example.invalid']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame([['http://localhost:8881', 'https://example.invalid']], json_decode($result['output'], true)['options']['rewrite_url']);
    }

    public function testFlatRuntimeDoesNotAlsoReceiveTheSavedFilesystemRoot(): void
    {
        $this->addRemote();
        $result = $this->runCli(['config', 'show', '--command=apply-runtime', '--flat-document-root=./flat']);
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertNull($effective['filesystem_root']);
        $this->assertSame('./flat', $effective['options']['flat_document_root']);
    }

    public function testRelativeConfigPathsUseTheConfigDirectoryWithoutParentDiscovery(): void
    {
        $this->addRemote(['--secret-file=./secret']);
        $config = $this->config();
        $config['local']['fs-root'] = '../files';
        $config['remote']['options']['secret-file'] = '../secret';
        file_put_contents($this->directory . '/.reprint/config.json', json_encode($config));
        mkdir($this->directory . '/child');
        $result = $this->runCli(['preflight'], $this->directory . '/child');
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('<remote-reprint-api-url> is required', $result['output']);
        $result = $this->runCli(['config', 'show', '--config=../.reprint/config.json'], $this->directory . '/child');
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertSame($this->directory . '/files', $effective['filesystem_root']);
        $this->assertSame($this->directory . '/secret', $effective['options']['secret_file']);
    }

    public function testSavedRootCannotBeOverriddenAndStateCannotBeSiteContent(): void
    {
        $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=.', '--secret-file=./secret']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('outside the filesystem root', $result['output']);
        $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
        $this->addRemote();
        foreach (['--fs-root=./other', '--state-dir=./other'] as $override) {
            $result = $this->runCli(['config', 'show', $override]);
            $this->assertSame(1, $result['code']);
            $this->assertStringContainsString('Cannot override', $result['output']);
        }
    }

    public function testMalformedConfigAndInvalidValuesDoNotFallBackToDefaults(): void
    {
        foreach (['--target-port=oops', '--target-port=70000', '--mode=typo'] as $argument) {
            $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', $argument]);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
        }
        $this->addRemote();
        file_put_contents($this->directory . '/.reprint/config.json', '{');
        $result = $this->runCli(['preflight']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Syntax error', $result['output']);
    }

    public function testConfigAndStateMayShareADirectoryWithoutLockingThemselvesOut(): void
    {
        $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', '--state-dir=./.reprint']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['remote', 'set-url', 'source', 'https://www.example.invalid']);
        $this->assertSame(0, $result['code'], $result['output']);
    }

    public function testConfigLockPreventsAnAddressChange(): void
    {
        $this->addRemote();
        $lock = fopen($this->directory . '/.reprint/config.json.lock', 'c+b');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->runCli(['remote', 'set-url', 'source', 'https://other.invalid']);
            $this->assertSame(1, $result['code']);
            $this->assertStringContainsString('Another Reprint process', $result['output']);
            $this->assertSame('https://example.invalid', $this->config()['remote']['remote_reprint_api_url']);
        } finally {
            fclose($lock);
        }
    }

    public function testFailedPreflightAtNewAddressDoesNotForgetPreviousSitePaths(): void
    {
        $url = $this->startServer();
        $this->assertSame(0, $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret'])['code']);
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $state_file = $this->directory . '/.reprint/state/remotes/source/pull/state.json';
        $preflight = json_decode(file_get_contents($state_file), true)['preflight'];
        mkdir($this->directory . '/another/remote', 0700, true);
        file_put_contents($this->directory . '/another/remote/wp-load.php', '<?php define("ABSPATH", __DIR__ . "/");');
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/different-site']);
        $this->assertSame(0, $result['code'], $result['output']);
        file_put_contents($this->directory . '/unavailable', '1');
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertSame($preflight, json_decode(file_get_contents($state_file), true)['preflight']);
        unlink($this->directory . '/unavailable');
        $result = $this->runCli(['preflight', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('different site paths', $result['output']);
        $this->assertSame($preflight, json_decode(file_get_contents($state_file), true)['preflight']);
    }

    public function testKeygenUsesTheNamedRemoteDirectoryAndFilesPushFindsItsKey(): void
    {
        $this->addRemote();
        mkdir($this->directory . '/files');
        $result = $this->runCli(['keygen']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertFileExists($this->directory . '/.reprint/state/remotes/source/key.pem');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5('https://example.invalid'));
        $result = $this->runCli(['files-push']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No preflight data found', $result['output']);
        $this->assertStringNotContainsString('No credential', $result['output']);
    }

    public function testPullGeneratesItsEnrollmentKeyInTheNamedRemoteDirectory(): void
    {
        $this->addRemote();
        $result = $this->runCli(['pull']);
        $this->assertSame(4, $result['code'], $result['output']);
        $this->assertFileExists($this->directory . '/.reprint/state/remotes/source/key.pem');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5('https://example.invalid'));
    }

    public function testMissingKeyInstructionsSelectTheNamedRemoteKeyFile(): void
    {
        $this->addRemote();
        $result = $this->runCli(['preflight']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--out=' . escapeshellarg($this->directory . '/.reprint/state/remotes/source/key.pem'), $result['output']);
    }

    public function testExplicitPrivateKeyReplacesTheSavedSecretFile(): void
    {
        $this->addRemote(['--secret-file=./absent.secret']);
        $result = $this->runCli(['config', 'show', '--command=files-push', '--private-key-path=./enrolled.pem']);
        $this->assertSame(0, $result['code'], $result['output']);
        $options = json_decode($result['output'], true)['options'];
        $this->assertSame('./enrolled.pem', $options['private_key_path']);
        $this->assertArrayNotHasKey('secret_file', $options);
    }

    private function startServer(): string
    {
        mkdir($this->directory . '/remote');
        file_put_contents($this->directory . '/remote/example.txt', 'remote contents');
        file_put_contents($this->directory . '/remote/wp-load.php', '<?php define("ABSPATH", __DIR__ . "/");');
        file_put_contents($this->directory . '/secret', "tls-test-secret\n");
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/saved-remote-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/server.log', 'a'], 2 => ['redirect', 1]], $pipes, $this->directory);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail(file_get_contents($this->directory . '/server.log'));
    }

    private function addRemote(array $arguments = []): void
    {
        $result = $this->runCli(array_merge(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files'], $arguments));
        $this->assertSame(0, $result['code'], $result['output']);
    }

    private function config(): array
    {
        return json_decode(file_get_contents($this->directory . '/.reprint/config.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function runCli(array $arguments, ?string $directory = null): array
    {
        $command = array_merge([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory ?? $this->directory);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['code' => proc_close($process), 'output' => $output];
    }

    private function removeTree(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
