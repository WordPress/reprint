<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

final class UtilsRequestHeaderTest extends TestCase {
    /** @var array<string, mixed> */
    private $original_server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->original_server;
        parent::tearDown();
    }

    /** @dataProvider apiOriginProvider */
    public function testApiOriginUsesSchemeHostAndNonDefaultPort(string $url, string $expected): void
    {
        $this->assertSame($expected, Utils::url_origin($url));
    }

    public static function apiOriginProvider(): array
    {
        return [
            'HTTP' => ['http://example.test/blog/?reprint-api', 'http://example.test'],
            'HTTPS and case' => ['HTTPS://Example.TEST/path', 'https://example.test'],
            'default HTTPS port' => ['https://example.test:443/', 'https://example.test'],
            'default HTTP port' => ['http://example.test:80/', 'http://example.test'],
            'non-default port' => ['https://example.test:8443/', 'https://example.test:8443'],
            'credentials are separate' => ['https://user:pass@example.test/', 'https://example.test'],
            'IPv6' => ['http://[::1]:8080/', 'http://[::1]:8080'],
        ];
    }

    /** @dataProvider invalidApiUrlProvider */
    public function testApiOriginRequiresAnHttpUrlWithAHost(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The API URL must use http or https and name a host');
        Utils::url_origin($url);
    }

    public static function invalidApiUrlProvider(): array
    {
        return [['/relative'], ['example.test'], ['ftp://example.test'], ['https:///missing'], ['http://example.test:99999']];
    }

    public function testRequestHeaderReadsEitherConventionAndTreatsEmptyAsAbsent(): void
    {
        $this->assertSame('abc', Utils::request_header(['X-Auth-Key-Id' => 'abc'], 'X-Auth-Key-Id'));
        $this->assertSame('abc', Utils::request_header(['x-auth-key-id' => 'abc'], 'X-Auth-Key-Id'));
        $this->assertSame('abc', Utils::request_header(['HTTP_X_AUTH_KEY_ID' => 'abc'], 'X-Auth-Key-Id'));
        $this->assertNull(Utils::request_header([], 'X-Auth-Key-Id'));
        $this->assertNull(Utils::request_header(['X-Auth-Key-Id' => ''], 'X-Auth-Key-Id'));
        $this->assertNull(Utils::request_header(['X-Auth-Key-Id' => ['abc']], 'X-Auth-Key-Id'));
    }

    public function testRequestHeadersCollectsTheHttpEntriesOfServer(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_AUTH_NONCE' => 'abc',
            'HTTP_X_EMPTY' => '',
            'HTTP_X_NOT_A_STRING' => ['abc'],
        ];

        $headers = Utils::request_headers();

        $this->assertSame('abc', $headers['HTTP_X_AUTH_NONCE']);
        $this->assertSame('', $headers['HTTP_X_EMPTY']);
        $this->assertArrayNotHasKey('REQUEST_METHOD', $headers);
        $this->assertArrayNotHasKey('HTTP_X_NOT_A_STRING', $headers);
        $this->assertSame('abc', Utils::request_header($headers, 'X-Auth-Nonce'));
    }
}
