<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PublicKeyClient;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class CredentialResolutionTest extends TestCase
{
    /** @var string */
    private $temp_dir;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . '/reprint-cred-' . getmypid() . '-' . uniqid();
        mkdir($this->temp_dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp_dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temp_dir);
    }

    private function writeKey(string $name, int $mode = 0600): string
    {
        [$private_pem, ] = PublicKeyClient::generate_keypair();
        $path = $this->temp_dir . '/' . $name;
        file_put_contents($path, $private_pem);
        chmod($path, $mode);
        return $path;
    }

    public function testSecretWinsAndYieldsHmac(): void
    {
        $this->writeKey('key.pem');
        $credential = ImportClient::resolve_credential(['secret' => 'tok'], $this->temp_dir);
        $this->assertSame(['scheme' => 'hmac', 'secret' => 'tok'], $credential);
    }

    public function testPrivateKeyFlagYieldsKeyFromThatPath(): void
    {
        $flag_path = $this->writeKey('elsewhere.pem');
        $this->writeKey('key.pem');
        $credential = ImportClient::resolve_credential(['private_key' => $flag_path], $this->temp_dir);
        $this->assertSame('key', $credential['scheme']);
        $this->assertSame($flag_path, $credential['path']);
        $this->assertSame('flag', $credential['source']);
        $this->assertSame(file_get_contents($flag_path), $credential['private_key_pem']);
    }

    public function testKeyFileInStateDirectoryIsFoundWithoutAFlag(): void
    {
        $state_path = $this->writeKey('key.pem');
        $credential = ImportClient::resolve_credential([], $this->temp_dir);
        $this->assertSame('key', $credential['scheme']);
        $this->assertSame($state_path, $credential['path']);
        $this->assertSame('state', $credential['source']);
    }

    public function testNothingFoundYieldsNullScheme(): void
    {
        $this->assertSame(['scheme' => null], ImportClient::resolve_credential([], $this->temp_dir));
    }

    public function testBothFlagsIsAnError(): void
    {
        $flag_path = $this->writeKey('k.pem');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--secret and --private-key');
        ImportClient::resolve_credential(['secret' => 'tok', 'private_key' => $flag_path], $this->temp_dir);
    }

    public function testEmptySecretFlagIsAnErrorNotAnAbsentCredential(): void
    {
        // pull --secret=$UNSET must stop here rather than generate a key.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--secret was given without a value.');
        ImportClient::resolve_credential(['secret' => ''], $this->temp_dir);
    }

    public function testEmptyPrivateKeyFlagIsAnErrorNotAnAbsentCredential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--private-key was given without a value.');
        ImportClient::resolve_credential(['private_key' => ''], $this->temp_dir);
    }

    public function testAbsentSecretStoredAsNullIsNotACredential(): void
    {
        // _cli_parse_options() seeds 'secret' => null before reading argv.
        $this->assertSame(['scheme' => null], ImportClient::resolve_credential(['secret' => null], $this->temp_dir));
    }

    public function testGroupReadableKeyIsRefused(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions only');
        }
        $path = $this->writeKey('loose.pem', 0640);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chmod 600');
        ImportClient::resolve_credential(['private_key' => $path], $this->temp_dir);
    }

    public function testMissingFlagPathIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be read');
        ImportClient::resolve_credential(['private_key' => $this->temp_dir . '/nope.pem'], $this->temp_dir);
    }

    public function testKeyFilePathIsUnderTheRemoteStateDirectory(): void
    {
        $path = ImportClient::key_file_path('https://example.test/?reprint-api', '/state');
        $this->assertSame('/state/remotes/' . md5('https://example.test/?reprint-api') . '/key.pem', $path);
    }
}
