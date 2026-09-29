<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PublicKeyClient;
use WordPress\Reprint\Server\PublicKeyServer;
use WordPress\Reprint\Server\Utils;

final class PublicKeyServerTest extends TestCase
{
    /** @var string */
    private static $private_key_pem;

    /** @var string */
    private static $public_key;

    /** @var PublicKeyClient */
    private static $client;

    /** @var array<string, mixed> */
    private $original_server = [];

    /** @var array<string, mixed> */
    private $original_get = [];

    /** @var array<string, mixed> */
    private $original_files = [];

    public static function setUpBeforeClass(): void
    {
        [self::$private_key_pem, self::$public_key] = PublicKeyClient::generate_keypair();
        self::$client = new PublicKeyClient(self::$private_key_pem);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_server = $_SERVER;
        $this->original_get = $_GET;
        $this->original_files = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->original_server;
        $_GET = $this->original_get;
        $_FILES = $this->original_files;
        Utils::override_key_auth_required_for_tests(null);

        parent::tearDown();
    }

    private function server(): PublicKeyServer
    {
        return new PublicKeyServer([self::$client->get_key_id() => self::$public_key]);
    }

    private function now(array $headers): float
    {
        return (float) $headers['X-Auth-Timestamp'] + 1.0;
    }

    public function testRoundTripVerifies(): void
    {
        $body = '{"endpoint":"preflight"}';
        $headers = self::$client->get_auth_headers('POST', 'https://s.test/?reprint-api', $body, 'abc');
        $server = $this->server();

        $this->assertNull($server->verify($headers, 'POST', '/?reprint-api', $body, [], 'abc', false, $this->now($headers)));
        $this->assertSame(self::$client->get_key_id(), $server->authenticated_key_id());
        $this->assertNull($server->last_error_reason());
    }

    public function testRefusesWhenHostIsHmacOnly(): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        Utils::override_key_auth_required_for_tests(false);
        $server = $this->server();

        $this->assertNotNull($server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_REQUIRES_TOKEN_AUTH, $server->last_error_reason());
    }

    public function testHostRuleRunsBeforeAnyHeaderIsRead(): void
    {
        Utils::override_key_auth_required_for_tests(false);
        $server = $this->server();

        $error = $server->verify([], 'GET', '/?reprint-api', '', [], null, false, microtime(true));

        $this->assertSame('This host accepts connection-token authentication only', $error);
        $this->assertSame(PublicKeyServer::REASON_REQUIRES_TOKEN_AUTH, $server->last_error_reason());
    }

    public function testFollowsTheUtilsHostRule(): void
    {
        // The test runtime has OpenSSL, so the host rule accepts keys.
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $this->assertNull($this->server()->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));

        // The rule is read on every call, not when the server is built.
        $server = $this->server();
        Utils::override_key_auth_required_for_tests(false);
        $this->assertNotNull($server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_REQUIRES_TOKEN_AUTH, $server->last_error_reason());
    }

    public function testUnknownKeyIdIsRejectedBeforeSignatureCheck(): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $server = new PublicKeyServer(['0000000000000000' => self::$public_key]);

        $this->assertSame('Key ' . self::$client->get_key_id() . ' is not enrolled on this site', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_UNKNOWN_KEY, $server->last_error_reason());
    }

    /** @dataProvider tamperedInputProvider */
    public function testEachSignedInputIsBound(string $method, string $target, string $body, ?string $cursor): void
    {
        $headers = self::$client->get_auth_headers('POST', 'https://s.test/?reprint-api', '{"a":1}', 'c1');
        $server = $this->server();

        $this->assertNotNull($server->verify($headers, $method, $target, $body, [], $cursor, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public static function tamperedInputProvider(): array
    {
        return [
            'method' => ['GET', '/?reprint-api', '{"a":1}', 'c1'],
            'target' => ['POST', '/?reprint-api&x=1', '{"a":1}', 'c1'],
            'body' => ['POST', '/?reprint-api', '{"a":2}', 'c1'],
            'cursor' => ['POST', '/?reprint-api', '{"a":1}', 'c2'],
        ];
    }

    /** @dataProvider freshnessFieldProvider */
    public function testChangingASignedFreshnessFieldBreaksTheSignature(string $header_name): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $now = $this->now($headers);
        $headers[$header_name] = $header_name === 'X-Auth-Nonce'
            ? str_repeat('0', 32)
            : sprintf('%.6f', (float) $headers['X-Auth-Timestamp'] + 1.0);
        $server = $this->server();

        $this->assertSame('Signature verification failed', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $now));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public static function freshnessFieldProvider(): array
    {
        return [
            'nonce' => ['X-Auth-Nonce'],
            'timestamp' => ['X-Auth-Timestamp'],
        ];
    }

    /** @dataProvider requiredHeaderProvider */
    public function testEachMissingHeaderIsNamed(string $header_name): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $now = $this->now($headers);
        unset($headers[$header_name]);
        $server = $this->server();

        $this->assertSame('Missing ' . $header_name . ' header', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $now));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public static function requiredHeaderProvider(): array
    {
        return [
            'key id' => ['X-Auth-Key-Id'],
            'signature' => ['X-Auth-Signature'],
            'nonce' => ['X-Auth-Nonce'],
            'timestamp' => ['X-Auth-Timestamp'],
            'content hash' => ['X-Auth-Content-Hash'],
        ];
    }

    public function testFutureTimestampIsRejected(): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $server = $this->server();

        $this->assertStringContainsString('expired', (string) $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, (float) $headers['X-Auth-Timestamp'] - 301.0));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public function testNonNumericTimestampIsRejected(): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $now = $this->now($headers);
        $headers['X-Auth-Timestamp'] = 'yesterday';
        $server = $this->server();

        $this->assertSame('Invalid timestamp format', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $now));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    /** @dataProvider malformedSignatureProvider */
    public function testMalformedSignatureIsRejected(string $signature, string $expected_error): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $headers['X-Auth-Signature'] = $signature;
        $server = $this->server();

        $this->assertSame($expected_error, $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
        $this->assertNull($server->authenticated_key_id());
    }

    public static function malformedSignatureProvider(): array
    {
        return [
            'not base64' => ['!!not base64!!', 'Malformed signature'],
            'truncated' => [base64_encode('short'), 'Signature verification failed'],
        ];
    }

    public function testStaleTimestampIsRejected(): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $server = $this->server();

        $this->assertStringContainsString('expired', (string) $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, (float) $headers['X-Auth-Timestamp'] + 301.0));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    /** @dataProvider invalidNonceProvider */
    public function testInvalidNonceIsRejected(string $nonce): void
    {
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $headers['X-Auth-Nonce'] = $nonce;
        $server = $this->server();

        $this->assertSame('Nonce must be at least 16 hexadecimal characters', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public static function invalidNonceProvider(): array
    {
        return [
            'short' => ['abc'],
            'not hex' => [str_repeat('z', 32)],
            'trailing newline' => [str_repeat('a', 32) . "\n"],
        ];
    }

    /**
     * Keys are validated at enrollment, but verify() must not trust a key
     * that reached the map another way.
     *
     * @dataProvider weakStoredKeyProvider
     */
    public function testStoredKeyMustBeRsaOfAtLeast2048Bits(array $keypair_options): void
    {
        $weak_key = openssl_pkey_new($keypair_options);
        $weak_public_key = Utils::normalize_public_key(openssl_pkey_get_details($weak_key)['key']);
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $server = new PublicKeyServer([self::$client->get_key_id() => $weak_public_key]);

        $this->assertSame(
            'Stored public key ' . self::$client->get_key_id() . ' is not an RSA key of at least 2048 bits',
            $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers))
        );
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
    }

    public static function weakStoredKeyProvider(): array
    {
        return [
            '1024-bit RSA' => [['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]],
            'EC' => [['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']],
        ];
    }

    public function testWrongKeyIsRejected(): void
    {
        [$other_private, ] = PublicKeyClient::generate_keypair();
        $other_client = new PublicKeyClient($other_private);
        $headers = $other_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        // Enroll the other client's id but with OUR public key, so the id is known and the signature is wrong.
        $server = new PublicKeyServer([$other_client->get_key_id() => self::$public_key]);

        $this->assertSame('Signature verification failed', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
    }

    public function testHmacHeadersCannotPassTheKeyPath(): void
    {
        $hmac = new Site_Export_HMAC_Client('secret');
        $headers = $hmac->get_auth_headers('');
        $server = $this->server();

        $this->assertSame('Missing X-Auth-Key-Id header', $server->verify($headers, 'GET', '/?reprint-api', '', [], null, false, (float) $headers['X-Auth-Timestamp'] + 1.0));
    }

    public function testUnsignedPayloadIsOnlyAcceptedForPush(): void
    {
        $headers = self::$client->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload');
        $server = $this->server();

        $this->assertNull($server->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', 'streamed bytes', [], null, true, $this->now($headers)));
        $this->assertSame('Unsigned payloads are accepted only for push endpoints', $server->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', 'streamed bytes', [], null, false, $this->now($headers)));
    }

    /**
     * Push bodies are streamed by the endpoint and never read during
     * authentication, so a body-hashed signature cannot be checked there.
     */
    public function testPushEndpointRejectsABodyHashedSignature(): void
    {
        $headers = self::$client->get_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload', 'streamed bytes');
        $server = $this->server();

        $this->assertSame(
            'Push endpoints require the literal UNSIGNED-PAYLOAD content hash',
            $server->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', null, [], null, true, $this->now($headers))
        );
        $this->assertSame(PublicKeyServer::REASON_AUTH_FAILED, $server->last_error_reason());
        $this->assertNull($server->authenticated_key_id());
    }

    public function testMultipartUploadsAreHashedFromTmpFiles(): void
    {
        $temporary_upload_path = tempnam(sys_get_temp_dir(), 'pk');
        try {
            file_put_contents($temporary_upload_path, '[{"path":"L2E="}]');
            $files = ['file_list' => ['tmp_name' => $temporary_upload_path, 'name' => 'file_list']];
            $headers = self::$client->get_auth_headers('POST', 'https://s.test/?reprint-api', '[{"path":"L2E="}]');
            $server = $this->server();

            $this->assertNull($server->verify($headers, 'POST', '/?reprint-api', '', $files, null, false, $this->now($headers)));
        } finally {
            unlink($temporary_upload_path);
        }
    }

    public function testVerifyGlobalsReadsSuperglobals(): void
    {
        $body = '';
        $headers = self::$client->get_auth_headers('GET', 'https://s.test/?reprint-api', $body, 'cur');
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/?reprint-api',
            'HTTP_X_EXPORT_CURSOR' => 'cur',
            'HTTP_X_AUTH_KEY_ID' => $headers['X-Auth-Key-Id'],
            'HTTP_X_AUTH_SIGNATURE' => $headers['X-Auth-Signature'],
            'HTTP_X_AUTH_NONCE' => $headers['X-Auth-Nonce'],
            'HTTP_X_AUTH_TIMESTAMP' => $headers['X-Auth-Timestamp'],
            'HTTP_X_AUTH_CONTENT_HASH' => $headers['X-Auth-Content-Hash'],
        ];
        $_FILES = [];
        $_GET = [];

        $this->assertNull($this->server()->verify_globals($this->now($headers)));
    }

    /**
     * A push request verifies from superglobals through the envelope alone.
     * php://input is empty under CLI PHPUnit, so the proof is structural: the
     * declared body length plays no part, and a body-hashed signature for the
     * same request is refused before any body would be hashed.
     */
    public function testVerifyGlobalsLeavesAPushBodyUnread(): void
    {
        $headers = self::$client->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?reprint-api&endpoint=push_upload',
            'CONTENT_LENGTH' => '4194304',
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_AUTH_KEY_ID' => $headers['X-Auth-Key-Id'],
            'HTTP_X_AUTH_SIGNATURE' => $headers['X-Auth-Signature'],
            'HTTP_X_AUTH_NONCE' => $headers['X-Auth-Nonce'],
            'HTTP_X_AUTH_TIMESTAMP' => $headers['X-Auth-Timestamp'],
            'HTTP_X_AUTH_CONTENT_HASH' => $headers['X-Auth-Content-Hash'],
        ];
        $_FILES = [];
        $_GET = ['reprint-api' => '', 'endpoint' => 'push_upload'];

        $this->assertNull($this->server()->verify_globals($this->now($headers)));

        $body_hashed_headers = self::$client->get_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload', '');
        $_SERVER['HTTP_X_AUTH_SIGNATURE'] = $body_hashed_headers['X-Auth-Signature'];
        $_SERVER['HTTP_X_AUTH_NONCE'] = $body_hashed_headers['X-Auth-Nonce'];
        $_SERVER['HTTP_X_AUTH_TIMESTAMP'] = $body_hashed_headers['X-Auth-Timestamp'];
        $_SERVER['HTTP_X_AUTH_CONTENT_HASH'] = $body_hashed_headers['X-Auth-Content-Hash'];

        $this->assertSame(
            'Push endpoints require the literal UNSIGNED-PAYLOAD content hash',
            $this->server()->verify_globals($this->now($body_hashed_headers))
        );
    }

    public function testRequestedKeyIdReadsEitherHeaderConvention(): void
    {
        $this->assertSame('abc', PublicKeyServer::requested_key_id(['X-Auth-Key-Id' => 'abc']));
        $this->assertSame('abc', PublicKeyServer::requested_key_id(['HTTP_X_AUTH_KEY_ID' => 'abc']));
        $this->assertNull(PublicKeyServer::requested_key_id([]));
    }

    public function testAssertValidPublicKeyAcceptsPemAndOneLine(): void
    {
        $this->assertSame(self::$public_key, PublicKeyServer::assert_valid_public_key(self::$public_key));
        $this->assertSame(self::$public_key, PublicKeyServer::assert_valid_public_key(Utils::public_key_to_pem(self::$public_key)));
    }

    /**
     * Trailing bytes after the DER structure still parse, so the stored key
     * must be OpenSSL's encoding or its id would differ from the client's.
     */
    public function testAssertValidPublicKeyReturnsTheCanonicalEncoding(): void
    {
        $padded_public_key = base64_encode(base64_decode(self::$public_key, true) . "\0\0\0");

        $this->assertSame(self::$public_key, PublicKeyServer::assert_valid_public_key($padded_public_key));
    }

    /** Only keys printed by reprint keygen are supported; a PKCS#1 key gets a pointer there. */
    public function testAssertValidPublicKeyRejectsAPkcs1Key(): void
    {
        // A 2048-bit SubjectPublicKeyInfo is a fixed 24-byte prefix followed by the PKCS#1 RSAPublicKey.
        $subject_public_key_info = base64_decode(self::$public_key, true);
        $this->assertSame('30820122300d06092a864886f70d0101010500038201', bin2hex(substr($subject_public_key_info, 0, 22)));
        $pkcs1_pem = "-----BEGIN RSA PUBLIC KEY-----\n"
            . chunk_split(base64_encode(substr($subject_public_key_info, 24)), 64, "\n")
            . "-----END RSA PUBLIC KEY-----\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('That is a PKCS#1 RSA public key. Paste the public key printed by "reprint keygen" instead.');
        PublicKeyServer::assert_valid_public_key($pkcs1_pem);
    }

    /** @dataProvider invalidPublicKeyProvider */
    public function testAssertValidPublicKeyRejects(string $candidate, string $expected_message_fragment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected_message_fragment);
        PublicKeyServer::assert_valid_public_key($candidate);
    }

    public static function invalidPublicKeyProvider(): array
    {
        $weak = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $weak_public = openssl_pkey_get_details($weak)['key'];
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec_public = openssl_pkey_get_details($ec)['key'];
        $private_pem = '';
        openssl_pkey_export($weak, $private_pem);

        return [
            'garbage' => ['hello', 'base64'],
            '1024-bit' => [$weak_public, '2048'],
            'EC' => [$ec_public, 'RSA'],
            'private key' => [$private_pem, 'private'],
        ];
    }
}
