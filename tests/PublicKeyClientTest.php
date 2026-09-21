<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\EnvelopeSigner;
use WordPress\Reprint\Server\PublicKeyClient;
use WordPress\Reprint\Server\Utils;

final class PublicKeyClientTest extends TestCase
{
    /** @var string */
    private static $private_key_pem;

    /** @var string */
    private static $public_key_one_line;

    public static function setUpBeforeClass(): void
    {
        [self::$private_key_pem, self::$public_key_one_line] = PublicKeyClient::generate_keypair();
    }

    public function testGeneratedKeypairIsRsa2048(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::$private_key_pem));
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);
        $this->assertSame(self::$public_key_one_line, Utils::normalize_public_key($details['key']));
    }

    public function testKeyIdMatchesTheFingerprintOfThePublicKey(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $this->assertSame(Utils::public_key_fingerprint(self::$public_key_one_line), $client->get_key_id());
        $this->assertSame(self::$public_key_one_line, $client->get_public_key());
    }

    public function testConstructorRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicKeyClient('not a key');
    }

    public function testConstructorRejectsAPublicKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicKeyClient(Utils::public_key_to_pem(self::$public_key_one_line));
    }

    public function testAuthHeadersHaveTheFiveNamesAndVerifiableSignature(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $body = '{"paths":["/a"]}';
        $headers = $client->get_auth_headers('POST', 'https://example.test/?reprint-api', $body, 'Y3Vyc29y');

        $this->assertSame(
            ['X-Auth-Key-Id', 'X-Auth-Signature', 'X-Auth-Nonce', 'X-Auth-Timestamp', 'X-Auth-Content-Hash'],
            array_keys($headers)
        );
        $this->assertSame($client->get_key_id(), $headers['X-Auth-Key-Id']);
        $this->assertSame(hash('sha256', $body), $headers['X-Auth-Content-Hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['X-Auth-Nonce']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $headers['X-Auth-Timestamp']);

        $message = PublicKeyClient::build_message(
            $headers['X-Auth-Key-Id'],
            $headers['X-Auth-Nonce'],
            $headers['X-Auth-Timestamp'],
            $headers['X-Auth-Content-Hash'],
            'POST',
            '/?reprint-api',
            'Y3Vyc29y'
        );
        $signature = base64_decode($headers['X-Auth-Signature'], true);
        $this->assertNotFalse($signature);
        $this->assertSame(256, strlen($signature));
        $public_key = openssl_pkey_get_public(Utils::public_key_to_pem(self::$public_key_one_line));
        $this->assertSame(1, openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256));
    }

    public function testBuildMessageIsNewlineDelimitedInSpecOrder(): void
    {
        $message = PublicKeyClient::build_message('k', 'n', 't', 'h', 'get', '/x?y=1', null);
        $this->assertSame("reprint-rsa-sha256-v1\nk\nn\nt\nh\nGET\n/x?y=1\n", $message);
    }

    public function testEnvelopeHeadersUseTheUnsignedPayloadLiteral(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $headers = $client->get_envelope_auth_headers('post', 'https://example.test/?reprint-api&endpoint=push_upload');
        $this->assertSame('UNSIGNED-PAYLOAD', $headers['X-Auth-Content-Hash']);
        $this->assertInstanceOf(EnvelopeSigner::class, $client);
    }

    public function testCurlHeadersAreNameColonValue(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $curl_headers = $client->get_curl_headers('GET', 'https://example.test/?reprint-api');
        $this->assertCount(5, $curl_headers);
        $this->assertStringStartsWith('X-Auth-Key-Id: ', $curl_headers[0]);
    }
}
