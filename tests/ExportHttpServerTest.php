<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExportHttpServerTest extends TestCase
{
    public function testParsesJsonBodyAndCastsKnownTypes(): void
    {
        $server = new Site_Export_HTTP_Server();
        $config = $server->parse_http_config(
            ['endpoint' => 'file_index'],
            [],
            ['CONTENT_TYPE' => 'application/json; charset=utf-8'],
            json_encode([
                'paths' => ['a', 'b'],
                'max_execution_time' => '7',
                'memory_threshold' => '0.7',
                'create_table_query' => 'true',
            ]) ?: ''
        );

        $this->assertSame('file_index', $config['endpoint']);
        $this->assertSame(['a', 'b'], $config['paths']);
        $this->assertSame(7, $config['max_execution_time']);
        $this->assertSame(0.7, $config['memory_threshold']);
        $this->assertTrue($config['create_table_query']);
    }

    public function testNormalizeConfigAppliesDefaultDirectoryAndDecodesCursorHeader(): void
    {
        $server = new Site_Export_HTTP_Server([
            'default_directory' => '/srv/site',
        ]);
        $cursor = base64_encode(json_encode(['offset' => 10]) ?: '');

        $config = $server->normalize_config(
            ['endpoint' => 'file_index'],
            ['HTTP_X_EXPORT_CURSOR' => $cursor]
        );

        $this->assertSame('/srv/site', $config['directory']);
        $this->assertSame('{"offset":10}', $config['cursor']);
    }

    public function testLargeCursorRoundTripsThroughTheBoundedMultipartHeader(): void
    {
        $server = new Site_Export_HTTP_Server();
        $cursor_json = json_encode([
            'current_row' => str_repeat('compressible WordPress option data ', 2000),
        ]) ?: '';

        $cursor_header = Site_Export_HTTP_Server::encode_cursor($cursor_json);
        $config = $server->normalize_config([
            'endpoint' => 'sql_chunk',
            'cursor' => $cursor_header,
        ]);

        $this->assertLessThanOrEqual(
            Site_Export_Multipart_Processor::MAX_HEADER_LINE_BYTES - strlen('X-Cursor: '),
            strlen($cursor_header)
        );
        $this->assertSame($cursor_json, $config['cursor']);
    }

    public function testCursorWhichCannotFitTheBoundedHeaderIsRejectedBeforeEmission(): void
    {
        $bytes = '';
        for ($index = 0; $index < 400; ++$index) {
            $bytes .= hash('sha256', (string) $index, true);
        }
        $cursor_json = json_encode(['current_row' => base64_encode($bytes)]) ?: '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encoded response cursor requires');
        Site_Export_HTTP_Server::encode_cursor($cursor_json);
    }

    public function testNormalizeConfigAppliesDefaultDirectoryEvenWhenListDirPresent(): void
    {
        $server = new Site_Export_HTTP_Server([
            'default_directory' => '/srv/site',
        ]);

        $config = $server->normalize_config(
            ['endpoint' => 'file_index', 'list_dir' => '/srv/site/wp-content'],
            []
        );

        $this->assertSame('/srv/site', $config['directory']);
        $this->assertSame('/srv/site/wp-content', $config['list_dir']);
    }

    public function testQueryCannotOverrideOrLeakPrivateStagingConfiguration(): void
    {
        $config = $this->captureFileHandlerConfig([
            'get' => [
                'endpoint' => 'file_index',
                'storage_path' => '/client/storage',
                'staging_dir' => '/client/staging',
                'excluded_staging_root' => '/client/excluded',
            ],
            'post' => [],
            'server' => ['REQUEST_METHOD' => 'GET'],
            'body' => '',
        ]);

        $this->assertSame('/srv/site/.reprint-staging', $config['excluded_staging_root']);
        $this->assertArrayNotHasKey('storage_path', $config);
        $this->assertArrayNotHasKey('staging_dir', $config);
    }

    public function testFormCannotOverrideOrLeakPrivateStagingConfiguration(): void
    {
        $config = $this->captureFileHandlerConfig([
            'get' => [],
            'post' => [
                'endpoint' => 'file_fetch',
                'storage_path' => '/client/storage',
                'staging_dir' => '/client/staging',
                'excluded_staging_root' => '/client/excluded',
            ],
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            'body' => '',
        ]);

        $this->assertSame('/srv/site/.reprint-staging', $config['excluded_staging_root']);
        $this->assertArrayNotHasKey('storage_path', $config);
        $this->assertArrayNotHasKey('staging_dir', $config);
    }

    public function testJsonCannotOverrideOrLeakPrivateStagingConfiguration(): void
    {
        $config = $this->captureFileHandlerConfig([
            'get' => [],
            'post' => [],
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            'body' => json_encode([
                'endpoint' => 'file_index',
                'storage_path' => '/client/storage',
                'staging_dir' => '/client/staging',
                'excluded_staging_root' => '/client/excluded',
            ]) ?: '',
        ]);

        $this->assertSame('/srv/site/.reprint-staging', $config['excluded_staging_root']);
        $this->assertArrayNotHasKey('storage_path', $config);
        $this->assertArrayNotHasKey('staging_dir', $config);
    }

    public function testPrivateStagingConfigurationIsNotInjectedIntoOtherHandlers(): void
    {
        $server = new Site_Export_HTTP_Server([
            'staged' => [
                'staging_dir' => '/srv/site/.reprint-staging',
                'secret' => 'test-secret',
            ],
        ]);

        $config = $server->normalize_config([
            'endpoint' => 'sql_chunk',
            'storage_path' => '/client/storage',
            'staging_dir' => '/client/staging',
            'excluded_staging_root' => '/client/excluded',
        ]);

        $this->assertArrayNotHasKey('storage_path', $config);
        $this->assertArrayNotHasKey('staging_dir', $config);
        $this->assertArrayNotHasKey('excluded_staging_root', $config);
    }

    public function testNormalizeConfigRejectsInvalidCursor(): void
    {
        $server = new Site_Export_HTTP_Server();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cursor must be base64-encoded');

        $server->normalize_config(
            ['endpoint' => 'file_index'],
            ['HTTP_X_EXPORT_CURSOR' => '!!!not-base64!!!']
        );
    }

    public function testDispatchRoutesPreflightWithoutBudget(): void
    {
        $calls = [];
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => function (array $config) use (&$calls): void {
                    $calls[] = ['preflight', $config];
                },
            ],
        ]);

        $server->dispatch(['endpoint' => 'preflight']);

        $this->assertCount(1, $calls);
        $this->assertSame('preflight', $calls[0][0]);
        $this->assertSame(['endpoint' => 'preflight'], $calls[0][1]);
    }

    public function testDispatchRoutesStreamingEndpointsWithCreatedBudget(): void
    {
        $calls = [];
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'file_index' => function (array $config, $budget) use (&$calls): void {
                    $calls[] = [$config, $budget];
                },
            ],
            'budget_factory' => static function (array $config): array {
                return ['from' => $config['endpoint']];
            },
        ]);

        $server->dispatch(['endpoint' => 'file_index']);

        $this->assertCount(1, $calls);
        $this->assertSame(['endpoint' => 'file_index'], $calls[0][0]);
        $this->assertSame(['from' => 'file_index'], $calls[0][1]);
    }

    public function testDispatchRejectsUnknownEndpoints(): void
    {
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => static function (): void {},
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid endpoint: 'sql_chunk'. Valid endpoints: 'preflight'");

        $server->dispatch(['endpoint' => 'sql_chunk']);
    }

    public function testHandleRequestUsesParsedConfigAndDispatches(): void
    {
        $calls = [];
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'preflight' => function (array $config) use (&$calls): void {
                    $calls[] = $config;
                },
            ],
        ]);

        $server->handle_request([
            'get' => ['endpoint' => 'preflight'],
            'post' => [],
            'server' => ['REQUEST_METHOD' => 'GET'],
            'body' => '',
        ]);

        $this->assertSame([['endpoint' => 'preflight']], $calls);
    }

    /**
     * Dispatches one request to a file handler and returns its configuration.
     *
     * @param array<string,mixed> $request Request overrides for handle_request().
     * @return array<string,mixed>
     */
    private function captureFileHandlerConfig(array $request): array
    {
        $captured_config = null;
        $handler = static function (array $config) use (&$captured_config): void {
            $captured_config = $config;
        };
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'file_index' => $handler,
                'file_fetch' => $handler,
            ],
            'budget_factory' => static function (): stdClass {
                return new stdClass();
            },
            'staged' => [
                'staging_dir' => '/srv/site/.reprint-staging',
                'secret' => 'test-secret',
            ],
        ]);

        $server->handle_request($request);

        $this->assertIsArray($captured_config);
        return $captured_config;
    }
}
