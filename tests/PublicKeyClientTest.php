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

    public function testGeneratesAKeypairWhenTheDefaultOpensslConfigIsMissing(): void
    {
        // PHP resolves OPENSSL_CONF when the extension initialises, so the
        // missing-config host has to be a fresh process.
        $php_code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';'
            . '[$private_key_pem, $public_key] = \\WordPress\\Reprint\\Server\\PublicKeyClient::generate_keypair();'
            . '$stale_error = openssl_error_string();'
            . 'echo json_encode([openssl_pkey_get_details(openssl_pkey_get_private($private_key_pem))["bits"], $public_key, $stale_error]);';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = array_merge(getenv(), ['OPENSSL_CONF' => sys_get_temp_dir() . '/reprint-missing-openssl.cnf']);
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $php_code], $descriptors, $pipes, null, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, $stdout . $stderr);
        $this->assertSame('', $stderr);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, $stdout);
        $this->assertSame(2048, $decoded[0]);
        $this->assertSame($decoded[1], Utils::normalize_public_key(Utils::public_key_to_pem($decoded[1])));
        $this->assertFalse($decoded[2], 'the failed first attempt leaves no OpenSSL error queued');
        $this->assertSame([], glob(sys_get_temp_dir() . '/reprint-openssl-*') ?: []);
    }

    public function testKeyIdMatchesTheFingerprintOfThePublicKey(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $this->assertSame(Utils::public_key_fingerprint(self::$public_key_one_line), $client->get_key_id());
        $this->assertSame(self::$public_key_one_line, $client->get_public_key());
    }

    public function testConstructorRejectsGarbage(): void
    {
        try {
            new PublicKeyClient('not a key');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            $this->assertFalse(openssl_error_string(), 'the parse failure leaves no OpenSSL error queued');
        }
    }

    public function testConstructorRejectsAPublicKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicKeyClient(Utils::public_key_to_pem(self::$public_key_one_line));
    }

    public function testAuthHeadersHaveTheFourNamesAndVerifiableSignature(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $headers = $client->get_auth_headers('POST', 'https://example.test/?reprint-api');

        $this->assertSame(
            ['X-Auth-Key-Id', 'X-Auth-Signature', 'X-Auth-Nonce', 'X-Auth-Timestamp'],
            array_keys($headers)
        );
        $this->assertSame($client->get_key_id(), $headers['X-Auth-Key-Id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['X-Auth-Nonce']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $headers['X-Auth-Timestamp']);

        $message = PublicKeyClient::build_message(
            $headers['X-Auth-Key-Id'],
            $headers['X-Auth-Nonce'],
            $headers['X-Auth-Timestamp'],
            'POST',
            '/?reprint-api'
        );
        $signature = base64_decode($headers['X-Auth-Signature'], true);
        $this->assertNotFalse($signature);
        $this->assertSame(256, strlen($signature));
        $public_key = openssl_pkey_get_public(Utils::public_key_to_pem(self::$public_key_one_line));
        $this->assertSame(1, openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256));
    }

    public function testBuildMessageIsNewlineDelimitedInSpecOrder(): void
    {
        $message = PublicKeyClient::build_message('k', 'n', 't', 'get', '/x?y=1');
        $this->assertSame("reprint-rsa-sha256-v1\nk\nn\nt\nGET\n/x?y=1", $message);
    }

    public function testEnvelopeHeadersAreTheSameKindOfSignature(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $headers = $client->get_envelope_auth_headers('post', 'https://example.test/?reprint-api&endpoint=push_upload');
        $this->assertSame(['X-Auth-Key-Id', 'X-Auth-Signature', 'X-Auth-Nonce', 'X-Auth-Timestamp'], array_keys($headers));
        $this->assertInstanceOf(EnvelopeSigner::class, $client);
    }

    public function testCurlHeadersAreNameColonValue(): void
    {
        $client = new PublicKeyClient(self::$private_key_pem);
        $curl_headers = $client->get_curl_headers('GET', 'https://example.test/?reprint-api');
        $this->assertCount(4, $curl_headers);
        $this->assertStringStartsWith('X-Auth-Key-Id: ', $curl_headers[0]);
    }
}
