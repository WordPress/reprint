<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

final class AuthProtocolTest extends TestCase {

	/** Fragments released clients match in an authentication message. */
	private const RELEASED_CLIENT_FRAGMENTS = [
		'HMAC signature verification failed',
		'timestamp expired',
		'Content hash mismatch',
		'Missing X-Auth-',
	];

	/** @dataProvider requestWithoutAContentHashProvider */
	public function testARequestWithoutAContentHashIsLeftToTheVerifier(array $headers): void
	{
		$this->assertNull(Utils::client_update_error($headers));
	}

	public static function requestWithoutAContentHashProvider(): array
	{
		return [
			'no headers' => [[]],
			'a key request' => [['X-Auth-Key-Id' => 'k', 'X-Auth-Signature' => 's', 'X-Auth-Nonce' => str_repeat('a', 32), 'X-Auth-Timestamp' => '1.0']],
		];
	}

	public function testAnythingButADecimalTimestampIsInvalid(): void
	{
		foreach (['abc', '', '1e3', "1000.5\n", " 1000.5", '-5'] as $timestamp) {
			$this->assertSame(
				['auth_failed', 'Invalid timestamp format'],
				Utils::freshness_error(str_repeat('a', 16), $timestamp, 300, 1000.0),
				var_export($timestamp, true)
			);
		}
	}

	public function testAnExpiredTimestampNamesTheDifference(): void
	{
		$this->assertSame(
			['timestamp_expired', 'Request timestamp expired. Difference: 500.00 seconds, max allowed: 300 seconds'],
			Utils::freshness_error(str_repeat('a', 16), '1000.000000', 300, 1500.0)
		);
	}

	public function testAShortOrNonHexNonceIsInvalid(): void
	{
		$expected = ['auth_failed', 'Nonce must be at least 16 hexadecimal characters'];
		$this->assertSame($expected, Utils::freshness_error('abc', '1000.0', 300, 1000.0));
		$this->assertSame($expected, Utils::freshness_error(str_repeat('g', 16), '1000.0', 300, 1000.0));
		$this->assertSame($expected, Utils::freshness_error(str_repeat('a', 16) . "\n", '1000.0', 300, 1000.0));
	}

	public function testTheTimestampIsCheckedBeforeTheNonce(): void
	{
		$this->assertSame('Invalid timestamp format', Utils::freshness_error('x', 'abc', 300, 1000.0)[1]);
		$this->assertSame('timestamp_expired', Utils::freshness_error('x', '1.0', 300, 1000.0)[0]);
	}

	/** @dataProvider contentHashHeaderProvider */
	public function testAContentHashMarksAReleasedTokenClient(array $headers): void
	{
		$error = Utils::client_update_error($headers);

		$this->assertSame('Update the Reprint client to version ' . Utils::AUTH_VERSION_CLIENT_RELEASE . ' or later.', $error);
		foreach (self::RELEASED_CLIENT_FRAGMENTS as $fragment) {
			$this->assertStringNotContainsString($fragment, $error);
		}
		$this->assertStringNotContainsString(';', $error);
	}

	public static function contentHashHeaderProvider(): array
	{
		$hash = hash('sha256', '');
		return [
			'the server variable convention' => [['HTTP_X_AUTH_SIGNATURE' => 's', 'HTTP_X_AUTH_CONTENT_HASH' => $hash]],
			'the hash alone' => [['X-Auth-Content-Hash' => $hash]],
		];
	}

	/** @dataProvider endpointUrlProvider */
	public function testEndpointUrlAppendsTheEndpointToTheApiUrlQuery(string $api_url, string $endpoint, array $parameters, string $expected): void
	{
		$url = Utils::endpoint_url($api_url, $endpoint, $parameters);

		$this->assertSame($expected, $url);
		// The server dispatches what PHP parses from this query.
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$this->assertSame($endpoint, $query['endpoint']);
	}

	public static function endpointUrlProvider(): array
	{
		return [
			'subdirectory with routing parameter' => ['https://example.com/blog/?reprint-api&lang=en', 'file_fetch', [], 'https://example.com/blog/?reprint-api&lang=en&endpoint=file_fetch'],
			'push parameters' => [
				'https://example.com/?reprint-api',
				'push_status',
				['push_session_id' => '0123456789abcdef0123456789abcdef', 'path_b64' => 'YQ=='],
				'https://example.com/?reprint-api&endpoint=push_status&push_session_id=0123456789abcdef0123456789abcdef&path_b64=YQ%3D%3D',
			],
		];
	}
}
