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
