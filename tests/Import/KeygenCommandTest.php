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
        $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir]);
        $second = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir]);
        $this->assertNotSame(0, $second['exit_code']);
        $this->assertStringContainsString('--force', $second['output']);

        $forced = $this->runCli(['keygen', $url, '--state-dir=' . $this->state_dir, '--force']);
        $this->assertSame(0, $forced['exit_code'], $forced['output']);
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
}
