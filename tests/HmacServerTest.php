<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\EnvelopeSigner;
use WordPress\Reprint\Server\HMACServer;
use WordPress\Reprint\Server\Utils;

final class HmacServerTest extends TestCase {

    private const SECRET = 'hmac-test-secret';
    private const URL = 'https://s.test/?reprint-api&endpoint=preflight';
    private const TARGET = '/?reprint-api&endpoint=preflight';

    protected function setUp(): void
    {
        parent::setUp();
        // The test runtime has OpenSSL, which would refuse every token.
        Utils::override_key_auth_required_for_tests(false);
    }

    protected function tearDown(): void
    {
        Utils::override_key_auth_required_for_tests(null);
        parent::tearDown();
    }

    private function headers(string $method = 'POST', string $url = self::URL): array
    {
        return ( new Site_Export_HMAC_Client(self::SECRET) )->get_auth_headers($method, $url);
    }

    private function now(array $headers): float
    {
        return (float) $headers['X-Auth-Timestamp'] + 1.0;
    }

    public function testValidRequestVerifies(): void
    {
        $headers = $this->headers();
        $server = new HMACServer(self::SECRET);

        $this->assertSame(['X-Auth-Signature', 'X-Auth-Nonce', 'X-Auth-Timestamp'], array_keys($headers));
        $this->assertNull($server->verify($headers, 'POST', self::TARGET, $this->now($headers)));
        $this->assertNull($server->last_error_reason());
    }

    public function testBuildMessageIsNewlineDelimitedInSpecOrder(): void
    {
        $this->assertSame(
            "reprint-hmac-sha256-v2\nn\nt\nPOST\n/?reprint-api&endpoint=sql_chunk",
            Site_Export_HMAC_Client::build_message('n', 't', 'post', '/?reprint-api&endpoint=sql_chunk')
        );
    }

    /** @dataProvider tamperedInputProvider */
    public function testEachSignedInputIsBound(string $method, string $target): void
    {
        $headers = $this->headers();
        $server = new HMACServer(self::SECRET);

        $this->assertSame('HMAC signature verification failed', $server->verify($headers, $method, $target, $this->now($headers)));
        $this->assertSame(HMACServer::REASON_SIGNATURE_MISMATCH, $server->last_error_reason());
    }

    public static function tamperedInputProvider(): array
    {
        return [
            'method' => ['GET', self::TARGET],
            'endpoint' => ['POST', '/?reprint-api&endpoint=sql_chunk'],
            'extra query' => ['POST', self::TARGET . '&x=1'],
            'path' => ['POST', '/blog/?reprint-api&endpoint=preflight'],
        ];
    }

    public function testWrongSecretIsRejected(): void
    {
        $headers = $this->headers();
        $server = new HMACServer('other-secret');

        $this->assertSame('HMAC signature verification failed', $server->verify($headers, 'POST', self::TARGET, $this->now($headers)));
        $this->assertSame(HMACServer::REASON_SIGNATURE_MISMATCH, $server->last_error_reason());
    }

    /** @dataProvider requiredHeaderProvider */
    public function testEachMissingHeaderIsNamed(string $header_name): void
    {
        $headers = $this->headers();
        $now = $this->now($headers);
        unset($headers[$header_name]);
        $server = new HMACServer(self::SECRET);

        $this->assertSame('Missing ' . $header_name . ' header', $server->verify($headers, 'POST', self::TARGET, $now));
        $this->assertSame(HMACServer::REASON_MISSING_HEADER, $server->last_error_reason());
    }

    public static function requiredHeaderProvider(): array
    {
        return [
            'signature' => ['X-Auth-Signature'],
            'nonce' => ['X-Auth-Nonce'],
            'timestamp' => ['X-Auth-Timestamp'],
        ];
    }

    public function testTheContentHashIsCheckedBeforeTheHostRule(): void
    {
        $headers = $this->headers() + ['X-Auth-Content-Hash' => hash('sha256', '')];
        Utils::override_key_auth_required_for_tests(true);
        $server = new HMACServer(self::SECRET);

        $server->verify($headers, 'POST', self::TARGET, $this->now($headers));
        $this->assertSame(HMACServer::REASON_CLIENT_UPDATE_REQUIRED, $server->last_error_reason());
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $headers = $this->headers();
        $server = new HMACServer(self::SECRET);

        $error = $server->verify($headers, 'POST', self::TARGET, (float) $headers['X-Auth-Timestamp'] + 301.0);
        $this->assertStringContainsString('timestamp expired', (string) $error);
        $this->assertSame(HMACServer::REASON_TIMESTAMP_EXPIRED, $server->last_error_reason());
    }

    public function testEnvelopeHeadersAreTheSameSignature(): void
    {
        $client = new Site_Export_HMAC_Client(self::SECRET);
        $url = 'https://s.test/?reprint-api&endpoint=push_create&push_session_id=0123456789abcdef0123456789abcdef';
        $headers = $client->get_envelope_auth_headers('POST', $url);

        $this->assertInstanceOf(EnvelopeSigner::class, $client);
        $this->assertNull(( new HMACServer(self::SECRET) )->verify(
            $headers,
            'POST',
            '/?reprint-api&endpoint=push_create&push_session_id=0123456789abcdef0123456789abcdef',
            $this->now($headers)
        ));
    }

    public function testCurlHeadersAreNameColonValue(): void
    {
        $curl_headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_curl_headers('POST', self::URL);

        $this->assertCount(3, $curl_headers);
        $this->assertStringStartsWith('X-Auth-Signature: ', $curl_headers[0]);
    }

    public function testRequestTargetNormalization(): void
    {
        $this->assertSame('/?reprint-api', Site_Export_HMAC_Client::request_target('https://s.test/?reprint-api'));
        $this->assertSame('/', Site_Export_HMAC_Client::request_target('https://s.test'));
        $this->assertSame('/blog/?reprint-api&endpoint=push_upload', Site_Export_HMAC_Client::request_target('https://s.test/blog/?reprint-api&endpoint=push_upload'));
    }

    public function testRefusesWhenTheHostRequiresKeyAuth(): void
    {
        $headers = $this->headers();
        Utils::override_key_auth_required_for_tests(true);
        $server = new HMACServer(self::SECRET);

        $this->assertSame(
            'This host requires key authentication; connection tokens are not accepted',
            $server->verify($headers, 'POST', self::TARGET, $this->now($headers))
        );
        $this->assertSame(HMACServer::REASON_REQUIRES_KEY_AUTH, $server->last_error_reason());
    }
}
