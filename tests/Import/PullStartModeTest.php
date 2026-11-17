<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

class PullStartModeTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-pull-start-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
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
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makePull(): \Pull
    {
        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        return new \Pull($client, new \TerminalProgress(false, STDOUT));
    }

    /**
     * @dataProvider stageProvider
     */
    public function testStagesHonorStartRuntimeSelection(array $options, bool $expectsStart): void
    {
        $stages = $this->makePull()->stages($options);

        if ($expectsStart) {
            $this->assertContains('start', $stages);
        } else {
            $this->assertNotContains('start', $stages);
        }
    }

    public static function stageProvider(): array
    {
        // Playground's start.sh needs bash, so pull leaves it to WSL on Windows.
        $can_start_playground = PHP_OS_FAMILY !== 'Windows';
        return [
            'php-builtin defaults to auto start' => [
                ['runtime' => 'php-builtin'],
                true,
            ],
            'playground-cli defaults to auto start where bash runs' => [
                ['runtime' => 'playground-cli'],
                $can_start_playground,
            ],
            'start-runtime=none skips php-builtin start stage' => [
                ['runtime' => 'php-builtin', 'start_runtime' => 'none'],
                false,
            ],
            'start-runtime=none skips playground-cli start stage' => [
                ['runtime' => 'playground-cli', 'start_runtime' => 'none'],
                false,
            ],
            'explicit playground-cli start is supported where bash runs' => [
                ['runtime' => 'playground-cli', 'start_runtime' => 'playground-cli'],
                $can_start_playground,
            ],
            'nginx-fpm never adds start stage' => [
                ['runtime' => 'nginx-fpm'],
                false,
            ],
        ];
    }

    public function testStartRuntimeCanSelectRuntimeWhenRuntimeIsOmitted(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows cannot start playground-cli.');
        }
        $pull = $this->makePull();
        $reflection = new \ReflectionClass($pull);
        $method = $reflection->getMethod('validate_and_default_pull_options');
        $method->setAccessible(true);

        $options = $method->invoke($pull, [
            'start_runtime' => 'playground-cli',
        ]);

        $this->assertSame('playground-cli', $options['runtime']);
        $this->assertSame('playground-cli', $options['start_runtime']);
    }

    public function testWindowsRejectsStartingPlaygroundBeforeNetworkIO(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Only Windows lacks the bash that starts playground-cli.');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Windows cannot start playground-cli: its start.sh needs Bash. ' .
            'Pass --start-runtime=none and run start.sh from WSL, or use --runtime=php-builtin.'
        );

        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'start_runtime' => 'playground-cli',
        ]);
    }

    public function testInvalidStartRuntimeValueIsRejectedBeforeNetworkIO(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid --start-runtime value: later. Valid runtimes: nginx-fpm, php-builtin, playground-cli, none'
        );

        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'start_runtime' => 'later',
        ]);
    }

    public function testUnsupportedStartRuntimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Starting runtime nginx-fpm is not supported yet. Supported start runtimes: php-builtin, playground-cli, none'
        );

        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'runtime' => 'nginx-fpm',
            'start_runtime' => 'nginx-fpm',
        ]);
    }

    public function testMismatchedStartRuntimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--start-runtime=php-builtin requires matching --runtime=php-builtin, or omit --runtime to use php-builtin for both.'
        );

        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'runtime' => 'playground-cli',
            'start_runtime' => 'php-builtin',
        ]);
    }

    /**
     * pull's started server only serves the site. While it runs, another
     * command can take the state directory's lock, and Windows can rename or
     * delete the audit log. Once pull has handed off, it writes nothing more
     * to the state directory, even after the server exits.
     */
    public function testStartedServerLeavesTheStateDirectoryToOtherCommands(): void
    {
        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $process_lock = new \ReprintProcessLock($this->stateDir);
        $process_lock_property = new \ReflectionProperty(\ImportClient::class, 'process_lock');
        $state_file_property = new \ReflectionProperty(\ImportClient::class, 'pull_state_file');
        $start_server = new \ReflectionMethod(\Pull::class, 'start_server');
        if (PHP_VERSION_ID < 80100) {
            $process_lock_property->setAccessible(true);
            $state_file_property->setAccessible(true);
            $start_server->setAccessible(true);
        }
        $process_lock_property->setValue($client, $process_lock);
        $state_file = $state_file_property->getValue($client);
        $client->audit_log('Before the server starts.', false);

        // The stand-in server records what another process may do while it runs.
        $result_file = $this->tempDir . '/server-result.json';
        $server_script = $this->tempDir . '/server.php';
        file_put_contents($server_script, <<<'PHP'
<?php
[, $state_directory, $result_file] = $argv;
$lock = fopen($state_directory . '/process.lock', 'c+b');
$audit_log = $state_directory . '/audit.log';
$renamed = rename($audit_log, $audit_log . '.moved') && rename($audit_log . '.moved', $audit_log);
file_put_contents($result_file, json_encode([
    'lock_acquired' => $lock !== false && flock($lock, LOCK_EX | LOCK_NB),
    'audit_log_renamed' => $renamed,
]));
PHP
        );
        $runtime_directory = $this->stateDir . '/runtime';
        mkdir($runtime_directory);
        if (PHP_OS_FAMILY === 'Windows') {
            $arguments = array_map(static function (string $argument): string {
                return '"' . str_replace('/', '\\', $argument) . '"';
            }, [PHP_BINARY, $server_script, $this->stateDir, $result_file]);
            file_put_contents($runtime_directory . '/start.cmd', "@echo off\r\n" . implode(' ', $arguments) . "\r\n");
        } else {
            file_put_contents($runtime_directory . '/start.sh', implode(' ', array_map(
                'escapeshellarg',
                [PHP_BINARY, $server_script, $this->stateDir, $result_file]
            )) . "\n");
        }

        $pull = new \Pull($client, new \TerminalProgress(false, STDOUT));
        $start_server->invoke($pull, []);
        $state_after_server = file_get_contents($state_file);
        $client->audit_log('After the server exits.', false);
        $client->save_state();

        $this->assertSame(
            ['lock_acquired' => true, 'audit_log_renamed' => true],
            json_decode( (string) file_get_contents($result_file), true)
        );
        $this->assertFalse($process_lock->is_held());
        $this->assertStringContainsString('Before the server starts.', file_get_contents($this->stateDir . '/audit.log'));
        $this->assertStringNotContainsString('After the server exits.', file_get_contents($this->stateDir . '/audit.log'));
        $this->assertSame($state_after_server, file_get_contents($state_file));
    }

    /**
     * A client whose pull handed the state directory to its server may run
     * another command later. That command holds the lock only while it runs,
     * and writes its audit log again.
     */
    public function testLaterRunOwnsTheStateDirectoryOnlyWhileItRuns(): void
    {
        $client = new \ImportClient('https://example.invalid', $this->stateDir, $this->fsRoot);
        $client->release_state_directory();

        ob_start();
        try {
            $client->run(['command' => 'pull-metadata']);
        } finally {
            ob_end_clean();
        }
        $client->audit_log('After a later run.', false);

        $other_command_lock = new \ReprintProcessLock($this->stateDir);
        $this->assertTrue($other_command_lock->is_held());
        $other_command_lock->close();
        $this->assertStringContainsString(
            'After a later run.',
            (string) @file_get_contents($this->stateDir . '/audit.log')
        );
    }
}
