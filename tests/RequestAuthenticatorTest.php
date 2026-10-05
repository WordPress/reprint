<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PublicKeyClient;
use WordPress\Reprint\Server\RequestAuthenticator;
use WordPress\Reprint\Server\Utils;

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
        Utils::override_key_auth_required_for_tests(null);

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

    public function testHmacHostVerifiesTokenAndIgnoresKeys(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('GET', 'https://s.test/?reprint-api');
        Utils::override_key_auth_required_for_tests(false);
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys());

        $this->assertNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertNull($authenticator->authenticated_key_id());
    }

    public function testHmacHostRejectsARequestCarryingAKeyIdBeforeReadingTheToken(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        Utils::override_key_auth_required_for_tests(false);
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys());

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_REQUIRES_TOKEN_AUTH, $authenticator->last_error_reason());
    }

    public function testHmacHostWithoutATokenIsNotConfigured(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('GET', 'https://s.test/?reprint-api');
        Utils::override_key_auth_required_for_tests(false);
        $authenticator = new RequestAuthenticator(null, $this->keys());

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NOT_CONFIGURED, $authenticator->last_error_reason());
    }


    public function testKeyHostVerifiesKeyAndIgnoresToken(): void
    {
        $headers = self::$key_client->get_auth_headers('POST', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys());

        $this->assertNull($authenticator->verify($headers, 'POST', '/?reprint-api', $this->now($headers)));
        $this->assertSame(self::$key_client->get_key_id(), $authenticator->authenticated_key_id());
    }

    public function testKeyHostRejectsAValidTokenEvenWhenItIsTheOnlyCredential(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, $this->keys());

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_REQUIRES_KEY_AUTH, $authenticator->last_error_reason());
    }

    /**
     * A site that upgraded with only a token stored answers no_keys_enrolled
     * to its existing token clients until a key is enrolled. The scheme
     * mismatch is reported only once a key exists.
     */
    public function testKeyHostWithNoKeysAnswersNoKeysEnrolledForATokenRequest(): void
    {
        $hmac = new Site_Export_HMAC_Client(self::SECRET);
        $headers = $hmac->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, []);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NO_KEYS_ENROLLED, $authenticator->last_error_reason());
    }

    public function testKeyHostWithNoKeysAnswersNoKeysEnrolledRegardlessOfToken(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(self::SECRET, []);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_NO_KEYS_ENROLLED, $authenticator->last_error_reason());
    }

    public function testKeyHostReportsUnknownKey(): void
    {
        $headers = self::$key_client->get_auth_headers('GET', 'https://s.test/?reprint-api');
        $authenticator = new RequestAuthenticator(null, ['0000000000000000' => self::$public_key]);

        $this->assertNotNull($authenticator->verify($headers, 'GET', '/?reprint-api', $this->now($headers)));
        $this->assertSame(RequestAuthenticator::REASON_UNKNOWN_KEY, $authenticator->last_error_reason());
    }

    public function testVerifyGlobalsVerifiesAKeySignedPushRequest(): void
    {
        $headers = self::$key_client->get_envelope_auth_headers('POST', 'https://s.test/?reprint-api&endpoint=push_status');
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?reprint-api&endpoint=push_status',
            'HTTP_X_AUTH_KEY_ID' => $headers['X-Auth-Key-Id'],
            'HTTP_X_AUTH_SIGNATURE' => $headers['X-Auth-Signature'],
            'HTTP_X_AUTH_NONCE' => $headers['X-Auth-Nonce'],
            'HTTP_X_AUTH_TIMESTAMP' => $headers['X-Auth-Timestamp'],
        ];
        $_GET = ['reprint-api' => '', 'endpoint' => 'push_status'];
        $_FILES = [];
        $authenticator = new RequestAuthenticator(null, $this->keys());

        $this->assertNull($authenticator->verify_globals($this->now($headers)));
    }

    public function testAReleasedClientIsAskedToUpdateBeforeAnythingElse(): void
    {
        $released_client_headers = [
            'X-Auth-Signature' => str_repeat('0', 64),
            'X-Auth-Nonce' => str_repeat('a', 32),
            'X-Auth-Timestamp' => sprintf('%.6f', microtime(true)),
            'X-Auth-Content-Hash' => hash('sha256', ''),
        ];
        // Token host, key host with keys, key host with none: the content hash wins each time.
        foreach ([[false, $this->keys()], [true, $this->keys()], [true, []]] as [$key_host, $keys]) {
            Utils::override_key_auth_required_for_tests($key_host);
            $authenticator = new RequestAuthenticator(self::SECRET, $keys);

            $this->assertSame('Update the Reprint client to version 0.11.0 or later.', $authenticator->verify($released_client_headers, 'POST', '/?reprint-api'));
            $this->assertSame(RequestAuthenticator::REASON_CLIENT_UPDATE_REQUIRED, $authenticator->last_error_reason());
        }
    }
}
