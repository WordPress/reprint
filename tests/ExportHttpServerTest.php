<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExportHttpServerTest extends TestCase
{
    public function testParsesJsonBodyAndCastsKnownTypes(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer();
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

    public function testParsesBase64EncodedPathParameters(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer();
        $config = $server->parse_http_config([
            'endpoint' => 'file_index',
            'directory' => [
                base64_encode('/srv/site'),
                base64_encode("/srv/binary-\xff"),
            ],
            'list_dir' => base64_encode('/srv/site'),
            'pulled_before' => [base64_encode('/srv/site/removed')],
        ]);

        $this->assertSame(['/srv/site', "/srv/binary-\xff"], $config['directory']);
        $this->assertSame('/srv/site', $config['list_dir']);
        $this->assertSame(['/srv/site/removed'], $config['pulled_before']);
    }

    public function testRejectsInvalidBase64EncodedPathParameter(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'directory entry 1 must be an absolute path or a base64-encoded absolute path; observed "not base64".'
        );

        $server->parse_http_config([
            'endpoint' => 'file_index',
            'directory' => [base64_encode('/srv/site'), 'not base64'],
        ]);
    }

    public function testKeepsLegacyRawPathParameterForProtocolNegotiation(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer();
        $config = $server->parse_http_config([
            'endpoint' => 'preflight',
            'directory' => ['/tmp', '/srv/site'],
        ]);

        $this->assertSame(['/tmp', '/srv/site'], $config['directory']);
    }

    public function testNormalizeConfigAppliesDefaultDirectoryAndDecodesCursorHeader(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer([
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
        $server = new \WordPress\Reprint\Server\HTTPServer([
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
        $server = new \WordPress\Reprint\Server\HTTPServer();

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
        $server = new \WordPress\Reprint\Server\HTTPServer([
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
        $server = new \WordPress\Reprint\Server\HTTPServer([
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

    public function testDefaultResourceBudgetAllowsFifteenSecondsWhenPhpIsUnlimited(): void
    {
        require_once __DIR__ . '/../packages/reprint-server/src/export.php';
        $previous_max_execution_time = ini_get('max_execution_time');
        $this->assertNotFalse(ini_set('max_execution_time', '0'));

        try {
            $server = new \WordPress\Reprint\Server\HTTPServer();

            $this->assertSame(15, $server->create_resource_budget([])->max_time);
        } finally {
            ini_set('max_execution_time', (string) $previous_max_execution_time);
        }
    }

    public function testResourceBudgetDoesNotExceedPhpExecutionLimit(): void
    {
        require_once __DIR__ . '/../packages/reprint-server/src/export.php';
        $previous_max_execution_time = ini_get('max_execution_time');
        $this->assertNotFalse(ini_set('max_execution_time', '7'));

        try {
            $server = new \WordPress\Reprint\Server\HTTPServer();

            $this->assertSame(7, $server->create_resource_budget([])->max_time);
        } finally {
            ini_set('max_execution_time', (string) $previous_max_execution_time);
        }
    }

    public function testClassifiesOnlyTheRegisteredPushEndpoints(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer();

        $this->assertTrue(
            is_callable([$server, 'is_push_endpoint']),
            'The HTTP server must expose its push endpoint classification.'
        );

        foreach (['push_create', 'push_upload', 'push_status', 'push_commit', 'push_remove'] as $endpoint) {
            $this->assertTrue($server->is_push_endpoint($endpoint), $endpoint);
        }
        foreach (['preflight', 'file_index', 'unknown'] as $endpoint) {
            $this->assertFalse($server->is_push_endpoint($endpoint), $endpoint);
        }
    }

    public function testDefaultHandlersMatchPushEndpointMethodRegistry(): void
    {
        $root = sys_get_temp_dir() . '/export-http-server-' . bin2hex(random_bytes(6));
        $docroot = $root . '/docroot';
        $reprint_directory = $root . '/reprint';
        mkdir($docroot, 0700, true);
        mkdir($reprint_directory, 0700);

        try {
            $server = new \WordPress\Reprint\Server\HTTPServer([
                'push' => [
                    'reprint_directory' => $reprint_directory,
                    'docroot' => $docroot,
                    'excluded_paths' => [],
                ],
            ]);
            $handlers_property = new ReflectionProperty(\WordPress\Reprint\Server\HTTPServer::class, 'handlers');
            $handlers_property->setAccessible(true);
            $handlers = $handlers_property->getValue($server);
            $registered_push_endpoint_methods = [];
            foreach ($handlers as $endpoint => $handler) {
                if (strpos($endpoint, 'push_') !== 0) {
                    continue;
                }
                $this->assertIsArray($handler);
                $this->assertInstanceOf(\WordPress\Reprint\Server\PushEndpoints::class, $handler[0]);
                $registered_push_endpoint_methods[$endpoint] = $handler[1];
            }

            $this->assertSame([
                'push_create' => 'create',
                'push_upload' => 'upload',
                'push_status' => 'status',
                'push_commit' => 'commit',
                'push_remove' => 'remove',
            ], $registered_push_endpoint_methods);
        } finally {
            rmdir($reprint_directory);
            rmdir($docroot);
            rmdir($root);
        }
    }

    public function testDispatchRoutesEveryPushEndpointWithoutCreatingABudget(): void
    {
        $push_endpoints = ['push_create', 'push_upload', 'push_status', 'push_commit', 'push_remove'];
        $calls = [];
        $handlers = [];
        foreach ($push_endpoints as $endpoint) {
            $handlers[$endpoint] = static function (array $config) use (&$calls): void {
                $calls[] = $config['endpoint'];
            };
        }
        $budget_creations = 0;
        $server = new \WordPress\Reprint\Server\HTTPServer([
            'handlers' => $handlers,
            'budget_factory' => static function () use (&$budget_creations): array {
                ++$budget_creations;
                return [];
            },
        ]);

        foreach ($push_endpoints as $endpoint) {
            $server->dispatch(['endpoint' => $endpoint]);
        }

        $this->assertSame($push_endpoints, $calls);
        $this->assertSame(0, $budget_creations);
    }

    public function testDispatchRejectsUnknownEndpoints(): void
    {
        $server = new \WordPress\Reprint\Server\HTTPServer([
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
        $server = new \WordPress\Reprint\Server\HTTPServer([
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

    public function testPushEndpointsNeverReadAJsonRequestBody(): void
    {
        foreach (['push_create', 'push_upload', 'push_status', 'push_commit', 'push_remove', 'push_future_operation'] as $endpoint) {
            $body_reads = 0;
            $calls = [];
            $server = new \WordPress\Reprint\Server\HTTPServer([
                'budget_factory' => static function (): stdClass {
                    return new stdClass();
                },
                'body_reader' => static function () use (&$body_reads): string {
                    ++$body_reads;
                    return '{"body_parameter":"must-not-be-read"}';
                },
                'handlers' => [
                    $endpoint => static function (array $config) use (&$calls): void {
                        $calls[] = $config;
                    },
                ],
            ]);

            $server->handle_request([
                'get' => [
                    'endpoint' => $endpoint,
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
                'endpoint' => $endpoint,
                'push_session_id' => str_repeat('a', 32),
            ]], $calls);
        }
    }

    public function testPushQueryParametersCannotBeOverriddenByPostData(): void
    {
        $calls = [];
        $server = new \WordPress\Reprint\Server\HTTPServer([
            'handlers' => [
                'push_commit' => static function (array $config) use (&$calls): void {
                    $calls[] = $config;
                },
                'push_create' => static function (): void {
                    throw new RuntimeException('POST data changed the dispatched push endpoint.');
                },
            ],
        ]);

        $server->handle_request([
            'get' => [
                'endpoint' => 'push_commit',
                'push_session_id' => str_repeat('a', 32),
            ],
            'post' => [
                'endpoint' => 'push_create',
                'push_session_id' => str_repeat('b', 32),
            ],
            'server' => ['REQUEST_METHOD' => 'POST'],
        ]);

        $this->assertSame([[
            'endpoint' => 'push_commit',
            'push_session_id' => str_repeat('a', 32),
        ]], $calls);
    }

    public function testNonArrayPushOptionsThrowConfigurationException(): void
    {
        $this->expectException(\WordPress\Reprint\Server\PushConfigurationException::class);
        $this->expectExceptionMessage('The push HTTP server option must be an array.');

        new \WordPress\Reprint\Server\HTTPServer(['push' => null]);
    }
}
