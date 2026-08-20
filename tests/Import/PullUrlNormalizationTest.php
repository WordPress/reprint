<?php

declare(strict_types=1);

namespace ImportTests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class PullUrlNormalizationTest extends TestCase {
    /** @return iterable<string,array{string,string}> */
    public static function urls(): iterable
    {
        yield 'bare site URL' => ['https://example.test', 'https://example.test?reprint-api'];
        yield 'existing query' => ['https://example.test?foo=bar', 'https://example.test?foo=bar&reprint-api'];
        yield 'fragment' => ['https://example.test/path#section', 'https://example.test/path?reprint-api#section'];
        yield 'canonical endpoint' => ['https://example.test?reprint-api=1', 'https://example.test?reprint-api=1'];
        yield 'legacy endpoint' => ['https://example.test?site-export-api', 'https://example.test?site-export-api'];
        yield 'legacy text in path' => [
            'https://example.test/site-export-api/info',
            'https://example.test/site-export-api/info?reprint-api',
        ];
        yield 'similar query key' => [
            'https://example.test?not-site-export-api=1',
            'https://example.test?not-site-export-api=1&reprint-api',
        ];
    }

    #[DataProvider('urls')]
    public function testCanonicalQueryKeyIsAddedWithoutChangingExplicitLegacyEndpoints(
        string $input,
        string $expected
    ): void {
        $client = ( new ReflectionClass(\ImportClient::class) )->newInstanceWithoutConstructor();
        $client->remote_reprint_api_url = $input;
        $pull = ( new ReflectionClass(\Pull::class) )->newInstanceWithoutConstructor();
        $client_property = new ReflectionProperty(\Pull::class, 'client');
        $client_property->setValue($pull, $client);
        $normalize_url = new ReflectionMethod(\Pull::class, 'normalize_url');

        $normalize_url->invoke($pull);

        $this->assertSame($expected, $client->remote_reprint_api_url);
    }
}
