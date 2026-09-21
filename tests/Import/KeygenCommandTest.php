<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class KeygenCommandTest extends TestCase
{
    /** @var string */
    private $state_dir;

    protected function setUp(): void
    {
        $this->state_dir = sys_get_temp_dir() . '/reprint-keygen-' . getmypid() . '-' . uniqid();
        mkdir($this->state_dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->state_dir));
    }

    private function runCli(array $arguments): array
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        exec($command . ' 2>&1', $output_lines, $exit_code);
        return ['output' => implode("\n", $output_lines), 'exit_code' => $exit_code];
    }

    public function testGenerateKeyFileWritesPrivateKeyWithMode600AndReturnsPublicHalf(): void
    {
        $path = $this->state_dir . '/nested/key.pem';
        $generated = ImportClient::generate_key_file($path, false);

        $this->assertSame($path, $generated['path']);
        $this->assertFileExists($path);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generated['key_id']);
        $this->assertStringNotContainsString("\n", $generated['public_key']);
        $client = new \WordPress\Reprint\Server\PublicKeyClient(file_get_contents($path));
        $this->assertSame($generated['key_id'], $client->get_key_id());
        $this->assertSame($generated['public_key'], $client->get_public_key());
    }

    public function testGenerateKeyFileRefusesToOverwriteWithoutForce(): void
    {
        $path = $this->state_dir . '/key.pem';
        ImportClient::generate_key_file($path, false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--force');
        ImportClient::generate_key_file($path, false);
    }

    public function testKeygenCommandWritesIntoTheRemoteStateDirectoryAndPrintsTheKey(): void
    {
        $url = 'https://example.test/?reprint-api';
        $result = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir]);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $expected_path = ImportClient::key_file_path($url, $this->state_dir);
        $this->assertFileExists($expected_path);
        $this->assertStringContainsString('Key id:', $result['output']);
        $this->assertStringContainsString('Stored at:', $result['output']);
        $this->assertStringContainsString('Tools → Reprint Server', $result['output']);
        $this->assertMatchesRegularExpression('/^MII[A-Za-z0-9+\/]+=*$/m', $result['output'], 'prints the one-line public key');
    }

    public function testKeygenRefusesASecondRunWithoutForce(): void
    {
        $url = 'https://example.test/?reprint-api';
        $first = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir]);
        $second = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir]);
        $this->assertNotSame(0, $second['exit_code']);
        $this->assertStringContainsString('--force', $second['output']);

        // A loosened mode on the old file must not survive the replacement:
        // the new key is written as a fresh 0600 file, not into the old inode.
        $key_path = ImportClient::key_file_path($url, $this->state_dir);
        chmod($key_path, 0644);
        $forced = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir, '--force']);
        $this->assertSame(0, $forced['exit_code'], $forced['output']);
        $this->assertNotSame($this->printedKeyId($first['output']), $this->printedKeyId($forced['output']));
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($key_path) & 0777);
        }
        $this->assertSame(
            $this->printedKeyId($forced['output']),
            (new \WordPress\Reprint\Server\PublicKeyClient(file_get_contents($key_path)))->get_key_id()
        );
    }

    private function printedKeyId(string $output): string
    {
        $this->assertMatchesRegularExpression('/^  Key id:\s+([0-9a-f]{16})$/m', $output);
        preg_match('/^  Key id:\s+([0-9a-f]{16})$/m', $output, $match);
        return $match[1];
    }

    public function testKeygenOutWritesElsewhere(): void
    {
        $out = $this->state_dir . '/mine.pem';
        $result = $this->runCli(['keygen', 'https://example.test/?reprint-api', '--state-dir=' . $this->state_dir, '--out=' . $out]);
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertFileExists($out);
        $this->assertStringContainsString('--private-key=' . $out, $result['output']);
    }

    public function testKeygenHelpIsDocumented(): void
    {
        $result = $this->runCli(['keygen', '--help']);
        $this->assertStringContainsString('--out=PATH', $result['output']);
        $this->assertStringContainsString('--force', $result['output']);
    }

    public function testPullWithNoCredentialGeneratesAKeyAndStopsWithExitFour(): void
    {
        $url = 'https://example.test/?reprint-api';
        $fs_root = $this->state_dir . '/site';
        mkdir($fs_root);
        $result = $this->runCli(['pull', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root]);

        $this->assertSame(4, $result['exit_code'], $result['output']);
        $this->assertFileExists(ImportClient::key_file_path($url, $this->state_dir));
        $this->assertStringContainsString('No credential found for this site', $result['output']);
        $this->assertStringContainsString('run the same command again', $result['output']);
        $this->assertMatchesRegularExpression('/^\s+MII[A-Za-z0-9+\/]+=*$/m', $result['output']);
    }

    public function testPullEnrollmentStopReportsItsStatusInTheCommandReport(): void
    {
        $url = 'https://example.test/?reprint-api';
        $fs_root = $this->state_dir . '/site';
        mkdir($fs_root);
        $result = $this->runCli(['pull', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root, '--progress=jsonl']);

        $this->assertSame(4, $result['exit_code'], $result['output']);
        $output_lines = explode("\n", $result['output']);
        $report = json_decode((string) end($output_lines), true);
        $this->assertIsArray($report, 'the last line is the command report');
        $this->assertSame('reprint_report', $report['type']);
        $this->assertSame('enrollment_needed', $report['status']);
        $this->assertSame(4, $report['exit_code']);
        $this->assertNull($report['error']);
        $this->assertSame(ImportClient::key_file_path($url, $this->state_dir), $report['key_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $report['key_id']);
        $this->assertStringStartsWith('MII', $report['public_key']);
    }

    public function testPreflightWithNoCredentialRefusesAndNamesKeygen(): void
    {
        $url = 'https://example.test/?reprint-api';
        $fs_root = $this->state_dir . '/site';
        mkdir($fs_root);
        $result = $this->runCli(['preflight', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertNotSame(4, $result['exit_code']);
        $this->assertStringContainsString('reprint keygen', $result['output']);
        $this->assertFileDoesNotExist(ImportClient::key_file_path($url, $this->state_dir), 'granular commands never generate');
    }

    public function testAbortNeedsNoCredentialAndGeneratesNoKey(): void
    {
        $url = 'https://example.test/?reprint-api';
        $fs_root = $this->state_dir . '/site';
        mkdir($fs_root);

        // --abort clears local state without a request, so neither the
        // one-stop command nor a granular one may stop at the credential gate.
        $pull = $this->runCli(['pull', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root, '--abort']);
        $this->assertSame(0, $pull['exit_code'], $pull['output']);
        $this->assertStringNotContainsString('No credential', $pull['output']);
        $this->assertFileDoesNotExist(ImportClient::key_file_path($url, $this->state_dir));

        // files-pull --abort asks for a saved preflight after the credential
        // gate, so reaching that message shows the gate let it through.
        $files_pull = $this->runCli(['files-pull', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root, '--abort']);
        $this->assertStringNotContainsString('No credential', $files_pull['output']);
        $this->assertStringContainsString('No preflight data found', $files_pull['output']);
    }

    public function testLocalOnlyCommandIgnoresAGroupReadableKeyFile(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('File mode checks do not apply on Windows.');
        }
        $url = 'https://example.test/?reprint-api';
        $fs_root = $this->state_dir . '/site';
        mkdir($fs_root);
        $key_path = ImportClient::key_file_path($url, $this->state_dir);
        ImportClient::generate_key_file($key_path, false);
        chmod($key_path, 0640);

        // preflight-assert reads only the saved preflight report. It must reach
        // its own "run preflight first" result, not the key-file check that
        // remote commands perform on this key.
        $result = $this->runCli(['preflight-assert', $url, '--state-dir=' . $this->state_dir, '--fs-root=' . $fs_root]);

        $this->assertStringNotContainsString('readable by other users', $result['output']);
        $this->assertStringContainsString('No preflight data found', $result['output']);
    }
}
