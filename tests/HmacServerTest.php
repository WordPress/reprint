<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HmacServerTest extends TestCase
{
    private const SECRET = 'shared-secret';

    public function testValidRequestVerifiesSuccessfully(): void
    {
        $body = '{"paths":["/wp-content/uploads/image.jpg"]}';
        $timestamp = '1700000000.123456';
        $nonce = '0123456789abcdef0123456789abcdef';
        $content_hash = hash('sha256', $body);
        $client = new Site_Export_HMAC_Client(self::SECRET);

        $headers = [
            'X-Auth-Signature' => $client->compute_signature($nonce, $timestamp, $content_hash),
            'X-Auth-Nonce' => $nonce,
            'X-Auth-Timestamp' => $timestamp,
            'X-Auth-Content-Hash' => $content_hash,
        ];

        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertNull($server->verify($headers, $body, [], 1700000001.0));
    }

    public function testMissingHeaderIsRejected(): void
    {
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Missing X-Auth-Signature header',
            $server->verify([], '', [], 1700000001.0)
        );
    }

    public function testInvalidTimestampFormatIsRejected(): void
    {
        $headers = $this->buildHeadersForBody('', 'not-a-number');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Invalid timestamp format',
            $server->verify($headers, '', [], 1700000001.0)
        );
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $headers = $this->buildHeadersForBody('', '1700000000.000000');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertStringContainsString(
            'Request timestamp expired',
            (string) $server->verify($headers, '', [], 1700000401.0)
        );
    }

    public function testShortNonceIsRejected(): void
    {
        $headers = $this->buildHeadersForBody('', '1700000000.000000', 'shortnonce');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Nonce must be at least 16 characters',
            $server->verify($headers, '', [], 1700000001.0)
        );
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $headers = $this->buildHeadersForBody('');
        $headers['X-Auth-Signature'] = str_repeat('0', 64);
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'HMAC signature verification failed',
            $server->verify($headers, '', [], 1700000001.0)
        );
    }

    public function testContentHashMismatchIsRejected(): void
    {
        $headers = $this->buildHeadersForBody('signed-body');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Content hash mismatch: body was modified in transit',
            $server->verify($headers, 'different-body', [], 1700000001.0)
        );
    }

    public function testServerHeaderConventionIsSupported(): void
    {
        $body = 'payload';
        $headers = $this->buildHeadersForBody($body);
        $server_headers = [];

        foreach ($headers as $name => $value) {
            $server_headers['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertNull($server->verify($server_headers, $body, [], 1700000001.0));
    }

    public function testMultipartUploadsAreVerifiedFromUploadedFileContents(): void
    {
        $tmp_a = tempnam(sys_get_temp_dir(), 'hmac-a-');
        $tmp_b = tempnam(sys_get_temp_dir(), 'hmac-b-');
        file_put_contents($tmp_a, 'first-file');
        file_put_contents($tmp_b, 'second-file');

        try {
            $content_hash = hash('sha256', 'first-filesecond-file');
            $nonce = 'fedcba9876543210fedcba9876543210';
            $timestamp = '1700000000.000000';
            $client = new Site_Export_HMAC_Client(self::SECRET);

            $headers = [
                'X-Auth-Signature' => $client->compute_signature($nonce, $timestamp, $content_hash),
                'X-Auth-Nonce' => $nonce,
                'X-Auth-Timestamp' => $timestamp,
                'X-Auth-Content-Hash' => $content_hash,
            ];

            $files = [
                'b_file' => ['tmp_name' => $tmp_b],
                'a_file' => ['tmp_name' => $tmp_a],
            ];

            $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

            $this->assertNull($server->verify($headers, 'ignored-body', $files, 1700000001.0));
        } finally {
            @unlink($tmp_a);
            @unlink($tmp_b);
        }
    }

    public function testInvalidSignatureDoesNotReadUploadedFiles(): void
    {
        $headers = $this->buildHeadersForBody('signed-body');
        $headers['X-Auth-Signature'] = str_repeat('0', 64);
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'HMAC signature verification failed',
            $server->verify($headers, 'ignored-body', [
                'bad_upload' => ['tmp_name' => __DIR__],
            ], 1700000001.0)
        );
    }

    public function testUploadedFileHashFailureReturnsVerificationError(): void
    {
        $headers = $this->buildHeadersForBody('signed-body');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Cannot hash uploaded file.',
            $server->verify($headers, 'ignored-body', [
                'bad_upload' => ['tmp_name' => __DIR__],
            ], 1700000001.0)
        );
    }

    public function testEnvelopeSignedRequestVerifies(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $url = 'https://example.com/?reprint-api&endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $headers = $client->get_envelope_auth_headers('POST', $url);
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertNull($server->verify_envelope(
            $headers,
            'post', // Method casing must not matter.
            '/?reprint-api&endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            (float) $headers['X-Auth-Timestamp']
        ));
    }

    public function testEnvelopeRejectsAnotherRouteOrMethod(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $client->get_envelope_auth_headers('POST', 'https://example.com/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);
        $now = (float) $headers['X-Auth-Timestamp'];

        $this->assertSame(
            'HMAC signature verification failed',
            $server->verify_envelope($headers, 'POST', '/?endpoint=push_commit&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $now),
            'A captured envelope must not replay against a different route.'
        );
        $this->assertSame(
            'HMAC signature verification failed',
            $server->verify_envelope($headers, 'DELETE', '/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $now)
        );
    }

    public function testEnvelopeRequiresTheUnsignedPayloadHeader(): void
    {
        $headers = $this->buildHeadersForBody('{"command":"push"}');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'Envelope verification requires the literal UNSIGNED-PAYLOAD content hash',
            $server->verify_envelope($headers, 'POST', '/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1700000001.0)
        );
    }

    public function testEnvelopeHeadersDoNotPassBodyVerification(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $client->get_envelope_auth_headers('POST', 'https://example.com/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame(
            'HMAC signature verification failed',
            $server->verify($headers, 'any-body', [], (float) $headers['X-Auth-Timestamp'])
        );
    }

    public function testEnvelopeExpiredTimestampIsRejected(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $client->get_envelope_auth_headers('POST', 'https://example.com/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $result = $server->verify_envelope(
            $headers,
            'POST',
            '/?endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            (float) $headers['X-Auth-Timestamp'] + 301.0
        );

        $this->assertStringContainsString('Request timestamp expired', (string) $result);
    }

    public function testRequestTargetNormalization(): void
    {
        $this->assertSame(
            '/?reprint-api&endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            Site_Export_HMAC_Client::request_target('https://example.com/?reprint-api&endpoint=push_upload&push_session_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        );
        $this->assertSame(
            '/wp/index.php?a=1',
            Site_Export_HMAC_Client::request_target('http://example.com:8080/wp/index.php?a=1#frag')
        );
        $this->assertSame('/', Site_Export_HMAC_Client::request_target('https://example.com'));
    }

    public function testHmacClientIsAnEnvelopeSigner(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $this->assertInstanceOf(\WordPress\Reprint\Server\EnvelopeSigner::class, $client);
    }

    public function testDefaultSeamRefusesOnAnOpensslHost(): void
    {
        // The test runtime has OpenSSL, so the two-argument constructor refuses.
        $headers = $this->buildHeadersForBody('', '1700000000.000000');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET);
        $this->assertNotNull($server->verify($headers, '', [], 1700000001.0));
        $this->assertSame(\WordPress\Reprint\Server\HMACServer::REASON_REQUIRES_KEY_AUTH, $server->last_error_reason());
    }

    public function testDefaultSeamFollowsTheUtilsOverride(): void
    {
        \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
        try {
            $headers = $this->buildHeadersForBody('', '1700000000.000000');
            $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET);
            $this->assertNull($server->verify($headers, '', [], 1700000001.0));
        } finally {
            \WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(null);
        }
    }

    public function testRefusesWhenTheHostRequiresKeyAuth(): void
    {
        $headers = $this->buildHeadersForBody('', '1700000000.000000');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, true);

        $this->assertSame(
            'This host requires key authentication; connection tokens are not accepted',
            $server->verify($headers, '', [], 1700000001.0)
        );
        $this->assertSame(\WordPress\Reprint\Server\HMACServer::REASON_REQUIRES_KEY_AUTH, $server->last_error_reason());
    }

    public function testEnvelopeAlsoRefusesWhenTheHostRequiresKeyAuth(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $client->get_envelope_auth_headers('POST', 'https://s.test/?endpoint=push_upload');
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, true);

        $this->assertNotNull($server->verify_envelope($headers, 'POST', '/?endpoint=push_upload', (float) $headers['X-Auth-Timestamp'] + 1.0));
        $this->assertSame(\WordPress\Reprint\Server\HMACServer::REASON_REQUIRES_KEY_AUTH, $server->last_error_reason());
    }

    public function testSignatureFailureReportsTheGenericReason(): void
    {
        $headers = $this->buildHeadersForBody('', '1700000000.000000');
        $headers['X-Auth-Signature'] = str_repeat('0', 64);
        $server = new \WordPress\Reprint\Server\HMACServer(self::SECRET, 300, false);

        $this->assertSame('HMAC signature verification failed', $server->verify($headers, '', [], 1700000001.0));
        $this->assertSame(\WordPress\Reprint\Server\HMACServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    private function buildHeadersForBody(
        string $body,
        string $timestamp = '1700000000.000000',
        string $nonce = '0123456789abcdef0123456789abcdef'
    ): array {
        $content_hash = hash('sha256', $body);
        $client = new Site_Export_HMAC_Client(self::SECRET);

        return [
            'X-Auth-Signature' => $client->compute_signature($nonce, $timestamp, $content_hash),
            'X-Auth-Nonce' => $nonce,
            'X-Auth-Timestamp' => $timestamp,
            'X-Auth-Content-Hash' => $content_hash,
        ];
    }
}
