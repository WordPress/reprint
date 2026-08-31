<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options —
 * the static helper consumers call before authentication to emit CORS response
 * headers and short-circuit OPTIONS preflight.
 */
final class HttpServerCorsTest extends TestCase
{
    public function testEmitsWildcardOriginByDefault(): void
    {
        $emitted = [];
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            '*',
            '*',
            ['REQUEST_METHOD' => 'GET'],
            ['header' => static function (string $h) use (&$emitted): void {
                $emitted[] = $h;
            }]
        );

        $this->assertContains('Access-Control-Allow-Origin: *', $emitted);
        $this->assertContains('Access-Control-Allow-Methods: GET, POST, OPTIONS', $emitted);
        $this->assertContains('Access-Control-Allow-Headers: *', $emitted);
    }

    public function testEmitsSpecificOrigin(): void
    {
        $emitted = [];
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            'https://playground.wordpress.net',
            '*',
            ['REQUEST_METHOD' => 'GET'],
            ['header' => static function (string $h) use (&$emitted): void {
                $emitted[] = $h;
            }]
        );

        $this->assertContains(
            'Access-Control-Allow-Origin: https://playground.wordpress.net',
            $emitted
        );
    }

    public function testTrueMeansWildcard(): void
    {
        $emitted = [];
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            true,
            '*',
            ['REQUEST_METHOD' => 'GET'],
            ['header' => static function (string $h) use (&$emitted): void {
                $emitted[] = $h;
            }]
        );

        $this->assertContains('Access-Control-Allow-Origin: *', $emitted);
    }

    public function testEmitsCustomAllowHeaders(): void
    {
        $emitted = [];
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            '*',
            'Content-Type, X-Auth-Signature, X-Auth-Nonce, X-Auth-Timestamp',
            ['REQUEST_METHOD' => 'GET'],
            ['header' => static function (string $h) use (&$emitted): void {
                $emitted[] = $h;
            }]
        );

        $this->assertContains(
            'Access-Control-Allow-Headers: Content-Type, X-Auth-Signature, X-Auth-Nonce, X-Auth-Timestamp',
            $emitted
        );
    }

    public function testOptionsPreflightEmitsAllowHeaderAndTerminates(): void
    {
        $emitted = [];
        $terminated = false;
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            '*',
            '*',
            ['REQUEST_METHOD' => 'OPTIONS'],
            [
                'header' => static function (string $h) use (&$emitted): void {
                    $emitted[] = $h;
                },
                'exit' => static function () use (&$terminated): void {
                    $terminated = true;
                },
            ]
        );

        $this->assertTrue($terminated, 'OPTIONS preflight must terminate the request');
        $this->assertContains('Allow: GET, POST, OPTIONS', $emitted);
    }

    public function testNonOptionsRequestDoesNotTerminate(): void
    {
        $terminated = false;
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(
            '*',
            '*',
            ['REQUEST_METHOD' => 'POST'],
            [
                'header' => static function (): void {},
                'exit' => static function () use (&$terminated): void {
                    $terminated = true;
                },
            ]
        );

        $this->assertFalse($terminated, 'Non-OPTIONS requests must not terminate');
    }

    public function testRejectsEmptyOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options('', '*', ['REQUEST_METHOD' => 'GET']);
    }

    public function testRejectsInvalidOriginType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentionally passing wrong type */
        \WordPress\Reprint\Server\HTTPServer::handle_cors_headers_and_terminate_on_options(false, '*', ['REQUEST_METHOD' => 'GET']);
    }
}
