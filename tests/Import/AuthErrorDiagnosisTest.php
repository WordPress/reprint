<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class AuthErrorDiagnosisTest extends TestCase
{
    /** @var string */
    private $state_dir;

    protected function setUp(): void
    {
        $this->state_dir = sys_get_temp_dir() . '/reprint-diag-' . getmypid() . '-' . uniqid();
        mkdir($this->state_dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->state_dir));
    }

    private function clientWith(array $options): ImportClient
    {
        $client = new ImportClient(
            'https://example.test/?reprint-api',
            $this->state_dir,
            $this->state_dir . '/fs',
            ['signal_handling_command' => 'preflight']
        );
        // The public constructor takes no options; resolve the credential the
        // way run() does so credential, hmac_client, and public_key_client
        // are all set from $options.
        $initialize_credential = new ReflectionMethod(ImportClient::class, 'initialize_credential');
        $initialize_credential->invoke($client, true, $options);
        return $client;
    }

    private function diagnose(ImportClient $client, int $status, array $body): array
    {
        $method = new ReflectionMethod(ImportClient::class, 'diagnose_http_error');
        return $method->invoke($client, $status, json_encode($body));
    }

    public function testRequiresKeyAuthNamesKeygen(): void
    {
        $result = $this->diagnose($this->clientWith(['secret' => 'x']), 403, ['error' => 'msg', 'reason' => 'requires_key_auth']);
        $this->assertSame('AUTH_REQUIRES_KEY', $result['code']);
        // Quoted: an unquoted `?` is a glob in zsh and `&` backgrounds the command.
        $this->assertStringContainsString(
            "reprint keygen 'https://example.test/?reprint-api' --state-dir=" . escapeshellarg($this->state_dir),
            $result['message']
        );
        $this->assertStringContainsString('not accepted', $result['message']);
        // --secret wins over the stored key, so rerunning with it is refused again.
        $this->assertStringContainsString('run the command again without --secret', $result['message']);
    }

    public function testRequiresTokenAuthNamesSecret(): void
    {
        $result = $this->diagnose($this->clientWith([]), 403, ['error' => 'msg', 'reason' => 'requires_token_auth']);
        $this->assertSame('AUTH_REQUIRES_TOKEN', $result['code']);
        $this->assertStringContainsString('--secret=TOKEN', $result['message']);
    }

    public function testNoKeysEnrolledReprintsThePublicKey(): void
    {
        [$private_pem, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $path = $this->state_dir . '/k.pem';
        file_put_contents($path, $private_pem);
        chmod($path, 0600);
        $result = $this->diagnose($this->clientWith(['private_key_path' => $path]), 503, ['error' => 'msg', 'reason' => 'no_keys_enrolled']);
        $this->assertSame('AUTH_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString($public_key, $result['message']);
    }

    public function testNoKeysEnrolledWithATokenNamesKeygenNotTheTokenForm(): void
    {
        $result = $this->diagnose($this->clientWith(['secret' => 'x']), 503, ['error' => 'msg', 'reason' => 'no_keys_enrolled']);
        $this->assertSame('AUTH_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString('requires key authentication and has no keys enrolled', $result['message']);
        $this->assertStringContainsString('The connection token you passed is not accepted there', $result['message']);
        $this->assertStringContainsString('run the command again without --secret', $result['message']);
        // Quoted: an unquoted `?` is a glob in zsh and `&` backgrounds the command.
        $this->assertStringContainsString(
            "reprint keygen 'https://example.test/?reprint-api' --state-dir=" . escapeshellarg($this->state_dir),
            $result['message']
        );
        $this->assertStringNotContainsString('Set one under', $result['message']);
    }

    public function testNotConfiguredRepeatsWhatTheSiteReported(): void
    {
        $result = $this->diagnose(
            $this->clientWith(['secret' => 'x']),
            503,
            ['error' => 'Export not configured: no connection token is stored', 'reason' => 'not_configured']
        );
        $this->assertSame('AUTH_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString('The site reported: Export not configured: no connection token is stored', $result['message']);
        $this->assertStringNotContainsString('reprint keygen', $result['message']);
    }

    public function testNotConfiguredForAKeyDoesNotClaimTheHostRequiresKeys(): void
    {
        // A token host with a broken secret.php answers every request, key-signed ones included.
        [$private_pem, ] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $path = $this->state_dir . '/k.pem';
        file_put_contents($path, $private_pem);
        chmod($path, 0600);
        $result = $this->diagnose(
            $this->clientWith(['private_key_path' => $path]),
            503,
            ['error' => 'Invalid secret.php configuration. Remove it or replace it with a valid connection token.', 'reason' => 'not_configured']
        );
        $this->assertSame('AUTH_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString('Invalid secret.php configuration', $result['message']);
        $this->assertStringNotContainsString('requires key authentication', $result['message']);
    }

    public function testUnknownKeyReprintsThePublicKeyAndId(): void
    {
        [$private_pem, $public_key] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $path = $this->state_dir . '/k.pem';
        file_put_contents($path, $private_pem);
        chmod($path, 0600);
        $client = $this->clientWith(['private_key_path' => $path]);
        $result = $this->diagnose($client, 403, ['error' => 'msg', 'reason' => 'unknown_key']);
        $this->assertSame('AUTH_UNKNOWN_KEY', $result['code']);
        $this->assertStringContainsString($public_key, $result['message']);
        $this->assertStringContainsString(\WordPress\Reprint\Server\Utils::public_key_fingerprint($public_key), $result['message']);
    }

    public function testForbiddenWithoutReasonWhileUsingAKeySuggestsUpdatingThePlugin(): void
    {
        [$private_pem, ] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $path = $this->state_dir . '/k.pem';
        file_put_contents($path, $private_pem);
        chmod($path, 0600);
        $result = $this->diagnose($this->clientWith(['private_key_path' => $path]), 403, ['error' => 'HMAC signature verification failed']);
        $this->assertSame('AUTH_KEY_UNSUPPORTED', $result['code']);
        $this->assertStringContainsString('update the Reprint Server plugin', $result['message']);
    }

    public function testRejectedKeySignatureBlamesARewrittenRequestNotTheKey(): void
    {
        [$private_pem, ] = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
        $path = $this->state_dir . '/k.pem';
        file_put_contents($path, $private_pem);
        chmod($path, 0600);
        $result = $this->diagnose(
            $this->clientWith(['private_key_path' => $path]),
            403,
            ['error' => 'Signature verification failed', 'reason' => 'signature_mismatch']
        );
        $this->assertSame('AUTH_REQUEST_REWRITTEN', $result['code']);
        $this->assertStringContainsString('this machine signed: /?reprint-api', $result['message']);
        $this->assertStringContainsString('enrolling a new one will not help', $result['message']);
    }

    public function testNoCredentialMessageNamesBothOptions(): void
    {
        $result = $this->diagnose($this->clientWith([]), 403, ['error' => 'msg']);
        $this->assertSame('AUTH_NO_CREDENTIAL', $result['code']);
        $this->assertStringContainsString('reprint keygen', $result['message']);
        $this->assertStringContainsString('--secret', $result['message']);
    }
}
