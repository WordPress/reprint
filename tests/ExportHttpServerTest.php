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

    public function testPushUploadNeverReadsAJsonRequestBody(): void
    {
        $body_reads = 0;
        $calls = [];
        $server = new Site_Export_HTTP_Server([
            'body_reader' => static function () use (&$body_reads): string {
                ++$body_reads;
                return '{"body_parameter":"must-not-be-read"}';
            },
            'handlers' => [
                'push_upload' => static function (array $config) use (&$calls): void {
                    $calls[] = $config;
                },
            ],
        ]);

        $server->handle_request([
            'get' => [
                'endpoint' => 'push_upload',
                'push_session_id' => str_repeat('a', 32),
            ],
            'post' => [],
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
        ]);

        $this->assertSame(0, $body_reads);
        $this->assertSame([[
            'endpoint' => 'push_upload',
            'push_session_id' => str_repeat('a', 32),
        ]], $calls);
    }

    public function testInvalidPushOptionsUseTheConfigurationException(): void
    {
        $this->expectException(Site_Export_Push_Configuration_Exception::class);
        $this->expectExceptionMessage('The push HTTP server option must be an array.');

        new Site_Export_HTTP_Server(['push' => null]);
    }
}
