<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the Import test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** Exercises saved settings through the real CLI, including separate processes. */
final class SavedRemoteCommandTest extends TestCase {
    private string $directory;
    /** @var resource|null */
    private $server = null;

    /** Keep each CLI invocation away from checkout settings and credentials. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reprint-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
    }

    /** Stop the real endpoint before removing its private fixture tree. */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->removeTree($this->directory);
    }

    /** Setup must work before DNS, credentials, or the local copy are ready. */
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

    /** A mistaken second setup command must leave the existing config intact. */
    public function testSecondRemoteDoesNotReplaceTheFirst(): void
    {
        $this->addRemote();
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['remote', 'add', 'staging', 'https://staging.invalid', '--fs-root=./other']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('already has remote', $result['output']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
    }

    /** Inspection must not depend on the caller directory or an available credential file. */
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

    /** A one-run selection must neither append saved entries nor become a new default. */
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

    /** Local diff must reach its index check even when the remote credential is unavailable. */
    public function testLocalCommandsDoNotLoadRemoteCredentialsOrPullOptions(): void
    {
        $this->addRemote(['--secret-file=./absent.secret', '--include', ':wp-content:', '--target-engine=sqlite']);
        mkdir($this->directory . '/files');
        $result = $this->runCli(['files-diff', '--remote=source']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('local_index.jsonl', $result['output']);
        $this->assertStringNotContainsString('absent.secret', $result['output']);
        $this->assertStringNotContainsString('does not accept', $result['output']);
        $result = $this->runCli(['preflight-assert', '--remote=source']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No preflight data', $result['output']);
        $this->assertStringNotContainsString('absent.secret', $result['output']);
        $result = $this->runCli(['files-diff', '--remote=source', '--secret-file=./absent.secret']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('does not accept --secret-file', $result['output']);
    }

    /** An API address change must not reinterpret the local copy URL mapping. */
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

    /** Saving setup must not turn destructive actions or raw credentials into later defaults. */
    public function testActionFlagsAndInlineSecretsCannotBecomeDefaults(): void
    {
        foreach (['--abort', '--force', '--insecure', '--secret=do-not-save', '--new-site-url=http://localhost'] as $argument) {
            $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', $argument]);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
            $this->assertStringNotContainsString('do-not-save', $result['output']);
        }
    }

    /** Full commands must not borrow required arguments from a nearby config. */
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

    /** The caller must select the redirect destination before any request reaches it. */
    public function testRedirectDoesNotContactTheNewAddressOrChangeConfig(): void
    {
        $url = $this->startServer();
        $result = $this->runCli(['remote', 'add', 'source', $url . '/redirect', '--fs-root=./files', '--secret-file=./secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('REDIRECT', $result['output']);
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
        foreach (file($this->directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $this->assertSame('/redirect', json_decode($line, true)['path']);
        }
        $result = $this->runCli(['remote', 'set-url', 'source', $url . '/new']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringNotContainsString('tls-test-secret', $result['output']);
    }

    /** Completed sync work stays usable after confirming a new address for the same site. */
    public function testCompletedPullAndPushKeepTheirIndexAcrossAnExplicitAddressChange(): void
    {
        $url = $this->startServer();
        $remote_directory = $this->directory . '/remote';
        $result = $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret', '--include', $remote_directory]);
        $this->assertSame(0, $result['code'], $result['output']);
        foreach (['preflight', 'files-pull'] as $command) {
            $result = $this->runCli([$command, '--remote=source', '--insecure']);
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
        $result = $this->runCli(['files-push', '--remote=source', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('Run preflight', $result['output']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        file_put_contents($local_file, 'local contents to push');
        $result = $this->runCli(['files-diff', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('"action":"push"', $result['output']);
        $result = $this->runCli(['files-push', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame('local contents to push', file_get_contents($remote_directory . '/example.txt'));
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5($url . '/new'));
    }

    /** Restarting a low-level pull must retain the explicitly selected path mapping. */
    public function testSavedRemapIsAppliedOnEachPullWithoutRepeatingFlags(): void
    {
        $url = $this->startServer();
        $remote_directory = $this->directory . '/remote';
        $result = $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./secret', '--include', $remote_directory, '--remap', $remote_directory, ':fs-root:/site']);
        $this->assertSame(0, $result['code'], $result['output']);
        foreach (['preflight', 'files-pull'] as $command) {
            $result = $this->runCli([$command, '--remote=source', '--insecure']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        $local_file = $this->directory . '/files/site/example.txt';
        $this->assertSame('remote contents', file_get_contents($local_file));
        file_put_contents($remote_directory . '/example.txt', 'changed remote contents');
        $result = $this->runCli(['files-pull', '--remote=source', '--abort', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['files-pull', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame('changed remote contents', file_get_contents($local_file));
    }

    /** An open index request leaves real resumable work that cannot move to another address. */
    public function testProcessDeathDuringAnIndexRequestPreventsAnAddressChange(): void
    {
        $url = $this->startServer();
        $result = $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret', '--include', $this->directory . '/remote']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        file_put_contents($this->directory . '/slow-index', '1');
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client', 'files-index', '--remote=source', '--insecure'],
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

    /** An explicit credential must take precedence without exposing its contents. */
    public function testSecretOverrideDoesNotReadTheSavedFileOrPrintTheToken(): void
    {
        $url = $this->startServer();
        $this->assertSame(0, $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./absent.secret'])['code']);
        $result = $this->runCli(['config', 'show', '--command=preflight', '--secret=tls-test-secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertArrayNotHasKey('secret_file', $effective['options']);
        $this->assertSame('[redacted]', $effective['options']['secret']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure', '--secret=tls-test-secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringNotContainsString('tls-test-secret', $result['output']);
    }

    /** Pull URL mappings must not silently become hosted database replacements. */
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

    /** The flat runtime alternative must not receive two conflicting root arguments. */
    public function testFlatRuntimeDoesNotAlsoReceiveTheSavedFilesystemRoot(): void
    {
        $this->addRemote();
        $result = $this->runCli(['config', 'show', '--command=apply-runtime', '--flat-document-root=./flat']);
        $this->assertSame(0, $result['code'], $result['output']);
        $effective = json_decode($result['output'], true);
        $this->assertNull($effective['filesystem_root']);
        $this->assertSame('./flat', $effective['options']['flat_document_root']);
    }

    /** Moving the caller directory must not change saved path meanings or search parent configs. */
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

    /** A config must not redirect its existing indexes to another root or into transferred content. */
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

    /** Invalid saved inputs must fail instead of becoming valid-looking defaults. */
    public function testMalformedConfigAndInvalidValuesDoNotFallBackToDefaults(): void
    {
        foreach (['--target-port=oops', '--target-port=70000', '--target-port=12.5', '--mode=typo'] as $argument) {
            $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', $argument]);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
        }
        $this->addRemote();
        file_put_contents($this->directory . '/.reprint/config.json', '{');
        $result = $this->runCli(['preflight', '--remote=source']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Syntax error', $result['output']);
    }

    /** Config locking and command-state locking must remain independent. */
    public function testConfigAndStateMayShareADirectoryWithoutLockingThemselvesOut(): void
    {
        $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', '--state-dir=./.reprint']);
        $this->assertSame(0, $result['code'], $result['output']);
        $result = $this->runCli(['remote', 'set-url', 'source', 'https://www.example.invalid']);
        $this->assertSame(0, $result['code'], $result['output']);
    }

    /** Concurrent settings edits must fail before changing the saved address. */
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

    /**
     * A temporary refusal must invalidate the report without losing the path check.
     *
     * @dataProvider failedPreflightAddressProvider
     */
    public function testFailedPreflightDoesNotForgetPreviousSitePaths(bool $fail_at_old_address): void
    {
        $url = $this->startServer();
        $this->assertSame(0, $this->runCli(['remote', 'add', 'source', $url . '/old', '--fs-root=./files', '--secret-file=./secret'])['code']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $state_file = $this->directory . '/.reprint/state/remotes/source/pull/state.json';
        $preflight = json_decode(file_get_contents($state_file), true)['preflight'];
        mkdir($this->directory . '/another/remote', 0700, true);
        file_put_contents($this->directory . '/another/remote/wp-load.php', '<?php define("ABSPATH", __DIR__ . "/");');
        if (!$fail_at_old_address) {
            $result = $this->runCli(['remote', 'set-url', 'source', $url . '/different-site']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        file_put_contents($this->directory . '/unavailable', '1');
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(3, $result['code'], $result['output']);
        $failed_preflight = json_decode(file_get_contents($state_file), true)['preflight'];
        $this->assertSame(503, $failed_preflight['http_code']);
        $this->assertEmpty($failed_preflight['ok']);
        if ($fail_at_old_address) {
            $result = $this->runCli(['remote', 'set-url', 'source', $url . '/different-site']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        unlink($this->directory . '/unavailable');
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('different site paths', $result['output']);
        $this->assertSame($preflight['successful_site_paths'], $failed_preflight['successful_site_paths']);
        $this->assertSame($failed_preflight, json_decode(file_get_contents($state_file), true)['preflight']);
    }

    /** @return array<string,array{bool}> Refuse before or after the explicit address change. */
    public static function failedPreflightAddressProvider(): array
    {
        return ['old address' => [true], 'new address' => [false]];
    }

    /** Key setup and push validation must agree on the selected state directory. */
    public function testKeygenUsesTheNamedRemoteDirectoryAndFilesPushFindsItsKey(): void
    {
        $this->addRemote();
        mkdir($this->directory . '/files');
        $result = $this->runCli(['keygen', '--remote=source']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertFileExists($this->directory . '/.reprint/state/remotes/source/key.pem');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5('https://example.invalid'));
        $result = $this->runCli(['files-push', '--remote=source']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No preflight data found', $result['output']);
        $this->assertStringNotContainsString('No credential', $result['output']);
    }

    /** The initial enrollment stop must create a key that the configured next run can find. */
    public function testPullGeneratesItsEnrollmentKeyInTheNamedRemoteDirectory(): void
    {
        $this->addRemote();
        $result = $this->runCli(['pull', '--remote=source']);
        $this->assertSame(4, $result['code'], $result['output']);
        $this->assertFileExists($this->directory . '/.reprint/state/remotes/source/key.pem');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state/remotes/' . md5('https://example.invalid'));
    }

    /** Pasted keygen instructions must not switch back to URL-hashed state. */
    public function testMissingKeyInstructionsSelectTheNamedRemoteKeyFile(): void
    {
        $this->addRemote();
        $result = $this->runCli(['preflight', '--remote=source']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--out=' . escapeshellarg($this->directory . '/.reprint/state/remotes/source/key.pem'), $result['output']);
    }

    /** Selecting a key must not also load a saved legacy token. */
    public function testExplicitPrivateKeyReplacesTheSavedSecretFile(): void
    {
        $this->addRemote(['--secret-file=./absent.secret']);
        $result = $this->runCli(['config', 'show', '--command=files-push', '--private-key-path=./enrolled.pem']);
        $this->assertSame(0, $result['code'], $result['output']);
        $options = json_decode($result['output'], true)['options'];
        $this->assertSame('./enrolled.pem', $options['private_key_path']);
        $this->assertArrayNotHasKey('secret_file', $options);
    }

    /** Credential defaults are file references; explicit credentials replace them. */
    public function testSavedPrivateKeyCanBeOverriddenWithoutReadingIt(): void
    {
        $this->addRemote(['--private-key-path=./external.pem']);
        $result = $this->runCli(['config', 'show', '--command=preflight']);
        $this->assertSame(0, $result['code'], $result['output']);
        $options = json_decode($result['output'], true)['options'];
        $this->assertSame($this->directory . '/external.pem', $options['private_key_path']);
        foreach (['--secret-file=./other.secret', '--secret=do-not-print'] as $override) {
            $result = $this->runCli(['config', 'show', '--command=preflight', $override]);
            $this->assertSame(0, $result['code'], $result['output']);
            $this->assertArrayNotHasKey('private_key_path', json_decode($result['output'], true)['options']);
            $this->assertStringNotContainsString('do-not-print', $result['output']);
        }
    }

    /** Setup cannot save two credential files that every later command would reject. */
    public function testConflictingCredentialFilesCannotBeSaved(): void
    {
        $result = $this->runCli(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files', '--secret-file=./token', '--private-key-path=./key']);
        $this->assertSame(1, $result['code']);
        $this->assertFileDoesNotExist($this->directory . '/.reprint/config.json');
    }

    /** An omitted URL must fail before reading credentials or sending a request. */
    public function testOmittedUrlNeverLoadsSavedSettings(): void
    {
        $url = $this->startServer();
        $result = $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./secret']);
        $this->assertSame(0, $result['code'], $result['output']);
        $before = file_get_contents($this->directory . '/.reprint/config.json');
        foreach ([['preflight'], ['preflight', '--target-engine=sqlite']] as $arguments) {
            $result = $this->runCli($arguments);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertStringContainsString('<remote-reprint-api-url> is required', $result['output']);
        }
        $this->assertFileDoesNotExist($this->directory . '/requests.jsonl');
        $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state');
        $this->assertSame($before, file_get_contents($this->directory . '/.reprint/config.json'));
    }

    /** A named selector must load the expected remote and sign a real preflight. */
    public function testExplicitRemoteSelectorUsesTheSavedAddressAndCredentials(): void
    {
        $url = $this->startServer();
        $this->assertSame(0, $this->runCli(['remote', 'add', 'source', $url, '--fs-root=./files', '--secret-file=./secret'])['code']);
        $result = $this->runCli(['preflight', '--remote=source', '--insecure']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertFileExists($this->directory . '/.reprint/state/remotes/source/pull/state.json');
        $requests = file_get_contents($this->directory . '/requests.jsonl');
        $result = $this->runCli(['preflight', '--remote=staging', '--insecure']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No saved remote named staging', $result['output']);
        $this->assertSame($requests, file_get_contents($this->directory . '/requests.jsonl'));
    }

    /** Conflicting or empty selectors must not fall back to a saved remote. */
    public function testSelectorsRejectAmbiguousAndEmptyRequests(): void
    {
        $this->addRemote();
        foreach ([
            ['preflight', 'https://example.invalid', '--remote=source'],
            ['preflight', 'https://example.invalid', '--config=./.reprint/config.json'],
            ['preflight', '--remote=source', '--no-config'],
            ['preflight', '--config=./.reprint/config.json', '--no-config'],
            ['preflight', '--remote='],
            ['preflight', '--remote=source', '--remote=source'],
        ] as $arguments) {
            $result = $this->runCli($arguments);
            $this->assertSame(1, $result['code'], $result['output']);
            $this->assertDirectoryDoesNotExist($this->directory . '/.reprint/state');
        }
    }

    /** Config inspection can also verify the requested name without reading secrets. */
    public function testRemoteSelectorCanBeCombinedWithAnExplicitConfigFile(): void
    {
        $this->addRemote(['--secret-file=./missing.secret']);
        mkdir($this->directory . '/child');
        $result = $this->runCli(['config', 'show', '--remote=source', '--config=../.reprint/config.json'], $this->directory . '/child');
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertSame('source', json_decode($result['output'], true)['remote']['name']);
        $result = $this->runCli(['preflight', '--remote=source'], $this->directory . '/child');
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No Reprint config', $result['output']);
    }

    /** Earlier clients put the endpoint in the body and saved the bare API URL. */
    public function testBaseUrlPreflightFromEarlierClientsRemainsUsable(): void
    {
        require_once __DIR__ . '/../../packages/reprint-client/src/import.php';
        $url = 'https://example.invalid/api';
        $client = new \ImportClient($url, $this->directory . '/state', $this->directory . '/files');
        $require_preflight = new \ReflectionMethod(\ImportClient::class, 'require_preflight');
        foreach ([$url, \WordPress\Reprint\Server\Utils::endpoint_url($url, 'preflight')] as $saved_url) {
            $record = ['url' => $saved_url, 'data' => ['ok' => true], 'error' => null];
            $client->get_state()->set_preflight_record($record);
            $require_preflight->invoke($client);
            $this->assertSame($record, $client->get_state()->preflight_record());
        }
        $client->get_state()->set_preflight_record(['url' => 'https://other.invalid/api', 'data' => ['ok' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The remote Reprint API URL changed');
        $require_preflight->invoke($client);
    }

    /** Separate config files must not generate or replace each other's key files. */
    public function testConfigsInOneDirectoryHaveSeparateDefaultState(): void
    {
        foreach (['production', 'staging'] as $site) {
            $result = $this->runCli(['remote', 'add', 'source', 'https://' . $site . '.invalid', '--fs-root=./' . $site, '--config=./' . $site . '.json']);
            $this->assertSame(0, $result['code'], $result['output']);
        }
        $state_directories = [];
        foreach (['production', 'staging'] as $site) {
            $result = $this->runCli(['keygen', '--config=./' . $site . '.json']);
            $this->assertSame(0, $result['code'], $result['output']);
            $result = $this->runCli(['config', 'show', '--config=./' . $site . '.json']);
            $this->assertSame(0, $result['code'], $result['output']);
            $state_directories[] = json_decode($result['output'], true)['state_dir'];
        }
        $this->assertNotSame($state_directories[0], $state_directories[1]);
    }

    /** Serve real SQLite-backed endpoints on a fresh local port. */
    /** The path-valued symlink option must not replace the independent follow switch. */
    public function testSymlinkDestinationOverrideKeepsTheSavedFollowChoice(): void
    {
        $this->addRemote(['--no-follow-symlinks']);
        $result = $this->runCli(['config', 'show', '--command=files-pull', '--follow-symlinks=./linked-files']);
        $this->assertSame(0, $result['code'], $result['output']);
        $options = json_decode($result['output'], true)['options'];
        $this->assertFalse($options['follow_symlinks'] ?? true);
        $this->assertSame('./linked-files', $options['local_followed_symlinks_root']);
        $result = $this->runCli(['config', 'show', '--command=files-pull', '--follow-symlinks']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertTrue(json_decode($result['output'], true)['options']['follow_symlinks']);
    }

    /** Local cancellation needs no credential; remote database discard still does. */
    public function testLocalAbortDoesNotReadAnUnavailableSavedCredentialFile(): void
    {
        $this->addRemote(['--secret-file=./missing.secret']);
        mkdir($this->directory . '/files');
        $result = $this->runCli(['files-push', '--remote=source', '--abort']);
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringNotContainsString('Cannot read', $result['output']);
        $result = $this->runCli(['db-push', '--remote=source', '--abort']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Cannot read a non-empty secret', $result['output']);
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

    /**
     * Save offline defaults without creating transfer state.
     *
     * @param string[] $arguments Additional reusable CLI settings.
     */
    private function addRemote(array $arguments = []): void
    {
        $result = $this->runCli(array_merge(['remote', 'add', 'source', 'https://example.invalid', '--fs-root=./files'], $arguments));
        $this->assertSame(0, $result['code'], $result['output']);
    }

    /**
     * Read setup as saved by the CLI, without rebuilding expected defaults.
     *
     * @return array {
     *     @type array $remote Name, remote_reprint_api_url, and reusable options.
     *     @type array $local  Filesystem root, state directory, and local command settings.
     * }
     */
    private function config(): array
    {
        return json_decode(file_get_contents($this->directory . '/.reprint/config.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Run a fresh real CLI process and collect its exit code and combined output.
     *
     * @param string[] $arguments CLI arguments after the executable.
     * @param string|null $directory Working directory; defaults to this test's private directory.
     * @return array {
     *     @type int $code Process exit code.
     *     @type string $output Combined stdout and stderr, including errors before initialization.
     * }
     */
    private function runCli(array $arguments, ?string $directory = null): array
    {
        $command = array_merge([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory ?? $this->directory);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['code' => proc_close($process), 'output' => $output];
    }

    /** Remove only the temporary tree created for this test. */
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
