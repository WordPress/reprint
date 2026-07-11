<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExportHttpServerTest extends TestCase {
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

    public function testParseHttpConfigRejectsAnEndpointBodyOverride(): void
    {
        $server = new Site_Export_HTTP_Server();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match in the request target and body');

        $server->parse_http_config(
            ['endpoint' => 'staged_session_push'],
            ['endpoint' => 'file_index']
        );
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

    public function testNormalizeConfigRejectsAClientChosenStoragePath(): void
    {
        $server = new Site_Export_HTTP_Server();

        $config = $server->normalize_config([
            'endpoint' => 'file_index',
            'storage_path' => '/srv/site/client-hidden-path',
        ]);

        $this->assertArrayNotHasKey('storage_path', $config);
    }

    public function testNormalizeConfigUsesTheServerStagingDirectoryAsStoragePath(): void
    {
        $server = new Site_Export_HTTP_Server([
            'staged' => [
                'staging_dir' => '/srv/site/.reprint-staging',
                'secret' => 'test-secret',
            ],
        ]);

        $config = $server->normalize_config([
            'endpoint' => 'file_index',
            'storage_path' => '/srv/site/client-hidden-path',
        ]);

        $this->assertSame('/srv/site/.reprint-staging', $config['storage_path']);
    }

    public function testConstructorRejectsConflictingServerStoragePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_path must match staged.staging_dir');

        new Site_Export_HTTP_Server([
            'storage_path' => '/srv/site/other-storage',
            'staged' => [
                'staging_dir' => '/srv/site/.reprint-staging',
                'secret' => 'test-secret',
            ],
        ]);
    }

    public function testConstructorRejectsRootAndDotSegmentStoragePaths(): void
    {
        foreach (['/', '/srv/site/../private'] as $storage_path) {
            try {
                new Site_Export_HTTP_Server(['storage_path' => $storage_path]);
                self::fail('Expected unsafe storage_path to be rejected: ' . $storage_path);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('HTTP server storage_path', $exception->getMessage());
            }
        }
    }

    public function testConstructorRejectsASymlinkStoragePath(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $target = sys_get_temp_dir() . '/http-server-storage-target-' . $suffix;
        $link = sys_get_temp_dir() . '/http-server-storage-link-' . $suffix;
        mkdir($target, 0700);
        if (!symlink($target, $link)) {
            rmdir($target);
            self::fail('Could not create the storage_path symlink fixture.');
        }

        try {
            new Site_Export_HTTP_Server(['storage_path' => $link]);
            self::fail('Expected a symlink storage_path to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not be a symlink', $exception->getMessage());
            $this->assertStringContainsString(base64_encode($link), $exception->getMessage());
            $this->assertNotFalse(json_encode(['detail' => $exception->getMessage()]));
        } finally {
            unlink($link);
            rmdir($target);
        }
    }

    public function testConstructorRejectsStoragePathsBlockedByARegularFile(): void
    {
        $storage_file = tempnam(sys_get_temp_dir(), 'http-server-storage-file-');
        $this->assertIsString($storage_file);

        try {
            foreach ([$storage_file, $storage_file . '/child'] as $storage_path) {
                try {
                    new Site_Export_HTTP_Server(['storage_path' => $storage_path]);
                    self::fail('Expected a storage_path blocked by a regular file to be rejected.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('must not be blocked by a non-directory path', $exception->getMessage());
                    $this->assertStringContainsString(base64_encode($storage_file), $exception->getMessage());
                }
            }
        } finally {
            unlink($storage_file);
        }
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

    public function testHandleRequestNeverBuffersSessionOrRetiredPushBodies(): void
    {
        $session_endpoints = [
            'staged_session_create',
            'staged_session_push',
            'staged_session_advance',
            'staged_session_status',
            'staged_session_discard',
        ];
        $this->assertSame($session_endpoints, Site_Export_HTTP_Server::STAGED_SESSION_ENDPOINTS);

        $body_reads = 0;
        $handlers = [];
        $unbuffered_endpoints = array_merge($session_endpoints, ['staged_push']);
        foreach ($unbuffered_endpoints as $endpoint) {
            $handlers[$endpoint] = static function (): void {};
        }
        $server = new Site_Export_HTTP_Server([
            'handlers' => $handlers,
            'budget_factory' => static function (): array {
                return [];
            },
            'body_reader' => static function () use (&$body_reads): string {
                ++$body_reads;
                return str_repeat('untrusted', 1024);
            },
        ]);

        foreach ($unbuffered_endpoints as $endpoint) {
            $server->handle_request([
                'get' => ['endpoint' => $endpoint],
                'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'],
            ]);
        }

        $this->assertSame(0, $body_reads);
    }

    public function testHandleRequestAuthenticatesAStagedQueryBeforeParsingConflictingInput(): void
    {
        $body_reads = 0;
        $server = new Site_Export_HTTP_Server([
            'staged' => [
                'staging_dir' => sys_get_temp_dir() . '/export-http-pre-auth-' . bin2hex(random_bytes(8)),
                'secret' => 'export-http-pre-auth-secret',
            ],
            'body_reader' => static function () use (&$body_reads): string {
                ++$body_reads;
                return 'endpoint=file_index';
            },
        ]);
        $request_server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/?endpoint=staged_session_create',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        $previous_response_code = http_response_code();
        $buffer_level = ob_get_level();
        ob_start();
        try {
            $server->handle_request([
                'get' => ['endpoint' => 'staged_session_create'],
                'post' => ['endpoint' => 'file_index'],
                'server' => $request_server,
            ]);
            $output = ob_get_clean();
            $response_code = http_response_code();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            http_response_code($previous_response_code === false ? 200 : $previous_response_code);
        }

        $this->assertSame(0, $body_reads);
        $this->assertSame(403, $response_code);
        $this->assertSame(
            [
                'status' => 'rejected',
                'reason' => 'auth_failed',
                'detail' => 'Authentication failed.',
            ],
            json_decode( (string) $output, true)
        );
    }

    public function testReservedHandlerOverrideCannotBeSelectedFromFormOrJsonBody(): void
    {
        $storage_dir = sys_get_temp_dir() . '/export-http-reserved-body-' . bin2hex(random_bytes(8));
        $calls = 0;
        $server = new Site_Export_HTTP_Server([
            'handlers' => [
                'staged_session_create' => static function () use (&$calls): void {
                    ++$calls;
                },
            ],
            'staged' => [
                'staging_dir' => $storage_dir,
                'secret' => 'reserved-body-secret',
            ],
        ]);

        $requests = [
            [
                'get' => [],
                'post' => ['endpoint' => 'staged_session_create'],
                'server' => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
                'body' => '',
            ],
            [
                'get' => [],
                'post' => [],
                'server' => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_TYPE' => 'application/json'],
                'body' => '{"endpoint":"staged_session_create"}',
            ],
        ];
        $previous_response_code = http_response_code();
        try {
            foreach ($requests as $request) {
                ob_start();
                $server->handle_request($request);
                $response = json_decode( (string) ob_get_clean(), true);
                self::assertSame(400, http_response_code());
                self::assertSame('endpoint_not_in_query', $response['reason']);
            }
        } finally {
            http_response_code($previous_response_code === false ? 200 : $previous_response_code);
            @unlink($storage_dir . '/.htaccess');
            @unlink($storage_dir . '/index.php');
            @rmdir($storage_dir);
        }

        self::assertSame(0, $calls);
    }

    public function testHandleRequestEmitsJsonFallbackWhenAResponseContainsInvalidUtf8(): void
    {
        $storage_dir = sys_get_temp_dir() . '/export-http-invalid-json-' . bin2hex(random_bytes(8));
        $missing_target = sys_get_temp_dir() . "/export-http-missing-\xff-" . bin2hex(random_bytes(8));
        $secret = 'export-http-invalid-json-secret';
        $create_token = str_repeat('a', 32);
        $request_target = '/?endpoint=staged_session_create&create_token=' . $create_token;
        $request_server = ( new Site_Export_HMAC_Client($secret) )->get_envelope_auth_headers('POST', $request_target) + [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $request_target,
        ];
        $server = new Site_Export_HTTP_Server([
            'staged' => [
                'staging_dir' => $storage_dir,
                'secret' => $secret,
                'apply_target_root' => $missing_target,
            ],
        ]);
        $previous_server = $_SERVER;
        $previous_response_code = http_response_code();
        $_SERVER = $request_server;
        $buffer_level = ob_get_level();
        ob_start();
        try {
            $server->handle_request([
                'get' => ['endpoint' => 'staged_session_create', 'create_token' => $create_token],
                'server' => $request_server,
                'body' => '',
            ]);
            $output = ob_get_clean();
            $response_code = http_response_code();
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            $_SERVER = $previous_server;
            http_response_code($previous_response_code === false ? 200 : $previous_response_code);
            @rmdir($storage_dir);
        }

        $this->assertSame(500, $response_code);
        $this->assertSame(
            [
                'status' => 'rejected',
                'reason' => 'response_encoding_failed',
                'detail' => 'The server could not encode its response as JSON.',
                'committed_bytes' => 0,
            ],
            json_decode( (string) $output, true)
        );
    }
}
