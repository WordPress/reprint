<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

final class UtilsPublicKeyTest extends TestCase
{
    private const ONE_LINE = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuNC2YvuyFYtCkBjeWwW7mhwjVKL5EfPuuE8pF3U3vhQ4DyxFJdy3NBb8Ju+Ow8PFVOQP6K0qr2y1Be+ApuTzgDg/OJNxLMvuu+kxRmVvY2cJY81SRkylIQAIhxFLb9Uy+dmTHjxHfZEICux08fOu/o5IBXxTnRRsFUxmiy1LF+ruqSKMRIRqPObJQWMwMASDJ7s+/pmEtX8zJdMiX8JsR/QhDPMAt/ijGN+OyYw7OP67YPl4XhQzMw05c2xYgUdavmR+PL9nqxQb5i50enyGRpLvsqCNbEAAYAdLq1l7jk4x8Ri2O4+tFV5EUIc1VvcOG4oM5DXhvFc7aiWZ8sQgXQIDAQAB';

    protected function tearDown(): void
    {
        Utils::override_key_auth_required_for_tests(null);
    }

    public function testRuleFollowsOpensslVerifyAvailability(): void
    {
        $this->assertSame(function_exists('openssl_verify'), Utils::key_auth_required());
        $this->assertTrue(Utils::key_auth_required(), 'the test runtime has OpenSSL');
    }

    public function testTestOverrideForcesEitherBranchAndClears(): void
    {
        Utils::override_key_auth_required_for_tests(false);
        $this->assertFalse(Utils::key_auth_required());
        Utils::override_key_auth_required_for_tests(true);
        $this->assertTrue(Utils::key_auth_required());
        Utils::override_key_auth_required_for_tests(null);
        $this->assertSame(function_exists('openssl_verify'), Utils::key_auth_required());
    }

    public function testNormalizeStripsArmourAndWhitespace(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split(self::ONE_LINE, 64, "\r\n") . "-----END PUBLIC KEY-----\r\n";
        $this->assertSame(self::ONE_LINE, Utils::normalize_public_key($pem));
    }

    public function testNormalizeAcceptsTheOneLineFormUnchanged(): void
    {
        $this->assertSame(self::ONE_LINE, Utils::normalize_public_key("  " . self::ONE_LINE . "\n"));
    }

    public function testNormalizeRejectsNonBase64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Utils::normalize_public_key('not base64 !!');
    }

    public function testNormalizeRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Utils::normalize_public_key("-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n");
    }

    /** @dataProvider unsupportedArmourProvider */
    public function testNormalizeAcceptsOnlyOnePublicKeyBlock(string $candidate): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one BEGIN PUBLIC KEY block');
        Utils::normalize_public_key($candidate);
    }

    public static function unsupportedArmourProvider(): array
    {
        $block = Utils::public_key_to_pem(self::ONE_LINE);
        $body = chunk_split(self::ONE_LINE, 64, "\n");
        return [
            'two blocks' => [$block . $block],
            'mismatched labels' => ["-----BEGIN PUBLIC KEY-----\n" . $body . "-----END CERTIFICATE-----\n"],
            'another label' => ["-----BEGIN CERTIFICATE-----\n" . $body . "-----END CERTIFICATE-----\n"],
            'text around the block' => ['key: ' . $block],
        ];
    }

    public function testPemRoundTripsThroughNormalize(): void
    {
        $pem = Utils::public_key_to_pem(self::ONE_LINE);
        $this->assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        $this->assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);
        $this->assertSame(self::ONE_LINE, Utils::normalize_public_key($pem));
    }

    public function testFingerprintIsSixteenHexCharacters(): void
    {
        $fingerprint = Utils::public_key_fingerprint(self::ONE_LINE);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
    }

    public function testFingerprintIsStableAcrossFormats(): void
    {
        $pem_lf = Utils::public_key_to_pem(self::ONE_LINE);
        $pem_crlf = str_replace("\n", "\r\n", $pem_lf);
        $this->assertSame(
            Utils::public_key_fingerprint(self::ONE_LINE),
            Utils::public_key_fingerprint($pem_lf)
        );
        $this->assertSame(
            Utils::public_key_fingerprint(self::ONE_LINE),
            Utils::public_key_fingerprint($pem_crlf)
        );
    }

    public function testFingerprintMatchesHandComputation(): void
    {
        $expected = substr(hash('sha256', base64_decode(self::ONE_LINE, true)), 0, 16);
        $this->assertSame($expected, Utils::public_key_fingerprint(self::ONE_LINE));
    }
}
