<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PublicKeyClient;
use WordPress\Reprint\Server\RequestAuthenticator;

final class RequestAuthenticatorTest extends TestCase
{
    private const SECRET = 'shared-secret';

    /** @var PublicKeyClient */
    private static $key_client;

    /** @var string */
    private static $public_key;

    /** @var array<string, mixed> */
    private $original_server = [];

    /** @var array<string, mixed> */
    private $original_get = [];

    /** @var array<string, mixed> */
    private $original_files = [];

    public static function setUpBeforeClass(): void
    {
        [$private_pem, self::$public_key] = PublicKeyClient::generate_keypair();
        self::$key_client = new PublicKeyClient($private_pem);
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

        parent::tearDown();
    }

    private function keys(): array
    {
        return [self::$key_client->get_key_id() => self::$public_key];
    }

    private function now(array $headers): float
    {
        return (float) $headers['X-Auth-Timestamp'] + 1.0;
    }

    public function testRequiredSchemeFollowsTheSeam(): void
    {
        $this->assertSame('key', (new RequestAuthenticator(null, [], 300, true))->required_scheme());
        $this->assertSame('hmac', (new RequestAuthenticator(null, [], 300, false))->required_scheme());
        $this->assertSame('key', (new RequestAuthenticator(null, [], 300, null))->required_scheme(), 'the test runtime has OpenSSL');
    }

    public function testHmacHostVerifiesTokenAndIgnoresKeys(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys(), 300, false);

        $this->assertNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertNull($authenticator->authenticated_key_id());
    }

    public function testHmacHostRejectsARequestCarryingAKeyIdBeforeReadingTheToken(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys(), 300, false);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_REQUIRES_TOKEN_AUTH, $authenticator->last_error_reason());
    }

    public function testHmacHostWithoutATokenIsNotConfigured(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('');
        $authenticator = new RequestAuthenticator(null, $this->keys(), 300, false);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NOT_CONFIGURED, $authenticator->last_error_reason());
    }

    public function testHmacHostUsesEnvelopeVerificationForPush(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload');
        $authenticator = new RequestAuthenticator(self::SECRET, [], 300, false);

        $this->assertNull($authenticator->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', 'streamed', [], null, true, $this->now($headers)));
    }

    public function testKeyHostVerifiesKeyAndIgnoresToken(): void
    {
        $headers = self::$key_client->get_auth_headers('POST', 'https://s.test/?reprint-api', '{"e":1}', 'c');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys(), 300, true);

        $this->assertNull($authenticator->verify($headers, 'POST', '/?reprint-api', '{"e":1}', [], 'c', false, $this->now($headers)));
        $this->assertSame(self::$key_client->get_key_id(), $authenticator->authenticated_key_id());
    }

    public function testKeyHostRejectsAValidTokenEvenWhenItIsTheOnlyCredential(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys(), 300, true);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_REQUIRES_KEY_AUTH, $authenticator->last_error_reason());
    }

    /**
     * A site that upgraded with only a token stored answers not_configured to
     * its existing token clients until a key is enrolled; the scheme mismatch
     * is reported only once a key exists.
     */
    public function testKeyHostWithNoKeysIsNotConfiguredForATokenRequest(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('');
        $authenticator = new RequestAuthenticator(self::SECRET, [], 300, true);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NOT_CONFIGURED, $authenticator->last_error_reason());
    }

    public function testKeyHostWithNoKeysIsNotConfiguredRegardlessOfToken(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, [], 300, true);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NOT_CONFIGURED, $authenticator->last_error_reason());
    }

    public function testKeyHostReportsUnknownKey(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(null, ['0000000000000000' => self::$public_key], 300, true);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', '', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_UNKNOWN_KEY, $authenticator->last_error_reason());
    }

    public function testKeyHostAllowsUnsignedPayloadOnlyForPush(): void
    {
        $headers = self::$key_client->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_upload');
        $authenticator = new RequestAuthenticator(null, $this->keys(), 300, true);

        $this->assertNull($authenticator->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', 'streamed', [], null, true, $this->now($headers)));
        $this->assertNotNull($authenticator->verify($headers, 'POST', '/?reprint-api&endpoint=push_upload', 'streamed', [], null, false, $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_AUTH_FAILED, $authenticator->last_error_reason());
    }

    /**
     * The reference plugin promises that a push_-prefixed endpoint it does not
     * know authenticates with the envelope and then gets the push error
     * contract, so a newer client can tell an old server from a wrong secret.
     */
    public function testVerifyGlobalsUsesEnvelopeVerificationForAnUnknownPushPrefixedEndpoint(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_future_operation');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?reprint-api&endpoint=push_future_operation',
            'HTTP_X_AUTH_SIGNATURE' => $headers['X-Auth-Signature'],
            'HTTP_X_AUTH_NONCE' => $headers['X-Auth-Nonce'],
            'HTTP_X_AUTH_TIMESTAMP' => $headers['X-Auth-Timestamp'],
            'HTTP_X_AUTH_CONTENT_HASH' => $headers['X-Auth-Content-Hash'],
        ];
        $_GET = ['reprint-api' => '', 'endpoint' => 'push_future_operation'];
        $_FILES = [];
        $authenticator = new RequestAuthenticator(self::SECRET, [], 300, false);

        $this->assertNull($authenticator->verify_globals($this->now($headers)));
    }

    public function testVerifyGlobalsDerivesThePushDecisionFromTheQueryEndpoint(): void
    {
        $headers = self::$key_client->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_status');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?reprint-api&endpoint=push_status',
            'HTTP_X_AUTH_KEY_ID' => $headers['X-Auth-Key-Id'],
            'HTTP_X_AUTH_SIGNATURE' => $headers['X-Auth-Signature'],
            'HTTP_X_AUTH_NONCE' => $headers['X-Auth-Nonce'],
            'HTTP_X_AUTH_TIMESTAMP' => $headers['X-Auth-Timestamp'],
            'HTTP_X_AUTH_CONTENT_HASH' => $headers['X-Auth-Content-Hash'],
        ];
        $_GET = ['reprint-api' => '', 'endpoint' => 'push_status'];
        $_FILES = [];
        $authenticator = new RequestAuthenticator(null, $this->keys(), 300, true);

        $this->assertNull($authenticator->verify_globals($this->now($headers)));
    }
}
