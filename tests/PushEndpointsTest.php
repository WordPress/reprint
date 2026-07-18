<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-importer/src/import.php';
require_once __DIR__ . '/../packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php';

final class PushEndpointsTest extends TestCase {

    private const SECRET = 'real-push-endpoint-test-secret';
    private const POST_MAX_BYTES = 8192;

    /** @var resource|null */
    private $server_process;

    /** @var resource[] */
    private array $server_pipes = [];

    private string $root;
    private string $docroot;
    private string $wordpress_root;
    private string $reprint_directory;
    private string $secret_configuration_path;
    private string $push_authorization_configuration_path;
    private string $managed_push_configuration_path;
    private string $custom_auth_configuration_path;
    private string $reprint_configuration_path;
    private string $docroot_configuration_path;
    private string $excluded_paths_configuration_path;
    private string $base_url;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Push endpoint E2E requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $this->root = sys_get_temp_dir() . '/push-endpoints-' . bin2hex(random_bytes(6));
        $this->docroot = $this->root . '/site';
        $this->wordpress_root = $this->docroot . '/__wp__';
        $this->reprint_directory = $this->root . '/reprint';
        $this->secret_configuration_path = $this->root . '/secret';
        $this->push_authorization_configuration_path = $this->root . '/push-authorization';
        $this->managed_push_configuration_path = $this->root . '/managed-push';
        $this->custom_auth_configuration_path = $this->root . '/custom-auth';
        $this->reprint_configuration_path = $this->root . '/reprint-directory';
        $this->docroot_configuration_path = $this->root . '/docroot-configuration.json';
        $this->excluded_paths_configuration_path = $this->root . '/excluded-paths.json';
        mkdir($this->wordpress_root, 0700, true);
        mkdir($this->reprint_directory, 0700, true);
        file_put_contents($this->secret_configuration_path, self::SECRET);
        file_put_contents($this->push_authorization_configuration_path, hash('sha256', self::SECRET));
        file_put_contents($this->managed_push_configuration_path, '');
        file_put_contents($this->custom_auth_configuration_path, '');
        file_put_contents($this->reprint_configuration_path, $this->reprint_directory);
        $this->writeDocrootConfiguration([
            'document_root' => $this->docroot,
        ]);
        $this->writeExcludedPaths(['preserved']);
        file_put_contents($this->docroot . '/remove.txt', 'old');
        mkdir($this->docroot . '/preserved');
        file_put_contents($this->docroot . '/preserved/value.txt', 'keep');
        [$this->server_process, $this->server_pipes, $this->base_url] = $this->startServer(self::POST_MAX_BYTES);
    }

    protected function tearDown(): void
    {
        $this->stopServer($this->server_process, $this->server_pipes);
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testExistingTokenCannotUsePushEndpointsWithoutAuthorization(): void
    {
        file_put_contents($this->push_authorization_configuration_path, '');
        $push_session_id = str_repeat('0', 32);

        $authentication = $this->requestPushEndpoint(
            'not-the-server-secret',
            'POST',
            'push_create',
            $push_session_id
        );
        $this->assertSame(403, $authentication['http_code']);
        $this->assertSame('auth_failed', $authentication['response']['reason']);

        $requests = [
            ['POST', 'push_create', null, null],
            ['POST', 'push_upload', 'not a multipart body', 'application/octet-stream'],
            ['GET', 'push_status', null, null],
            ['POST', 'push_commit', null, null],
            ['POST', 'push_remove', null, null],
        ];
        foreach ($requests as [$method, $endpoint, $body, $content_type]) {
            $response = $this->requestPushEndpoint(
                self::SECRET,
                $method,
                $endpoint,
                $push_session_id,
                $body,
                $content_type
            );
            $this->assertSame(403, $response['http_code'], $endpoint . ': ' . $response['body']);
            $this->assertSame('rejected', $response['response']['status']);
            $this->assertSame('push_disabled', $response['response']['reason']);
        }

        $future_endpoint = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_future_operation',
            $push_session_id
        );
        $this->assertSame(403, $future_endpoint['http_code']);
        $this->assertSame('push_disabled', $future_endpoint['response']['reason']);

        $overridden_commit = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_commit',
            $push_session_id,
            'endpoint=push_create&push_session_id=' . str_repeat('1', 32),
            'application/x-www-form-urlencoded'
        );
        $this->assertSame(403, $overridden_commit['http_code'], $overridden_commit['body']);
        $this->assertSame('push_disabled', $overridden_commit['response']['reason']);
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push');
        $this->assertSame('old', file_get_contents($this->docroot . '/remove.txt'));
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));
    }

    public function testAuthorizedFuturePushEndpointUsesPushErrorContract(): void
    {
        $response = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_future_operation',
            str_repeat('f', 32)
        );

        $this->assertSame(400, $response['http_code'], $response['body']);
        $this->assertSame('rejected', $response['response']['status']);
        $this->assertSame('invalid_request', $response['response']['reason']);
        $this->assertStringContainsString('Invalid endpoint', $response['response']['detail']);
        $this->assertArrayNotHasKey('error', $response['response']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testCustomAuthenticationCannotBypassPushAuthorization(): void
    {
        file_put_contents($this->push_authorization_configuration_path, '');
        file_put_contents($this->custom_auth_configuration_path, 'enabled');

        $response = $this->requestPushEndpoint(
            null,
            'POST',
            'push_create',
            str_repeat('1', 32)
        );

        $this->assertSame(403, $response['http_code'], $response['body']);
        $this->assertSame('push_disabled', $response['response']['reason']);
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push');
    }

    public function testPersonalConsentDoesNotSurviveTokenRotation(): void
    {
        $first_push_session_id = str_repeat('2', 32);
        $first = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_create',
            $first_push_session_id
        );
        $this->assertSame(200, $first['http_code'], $first['body']);

        $rotated_secret = 'rotated-push-endpoint-test-secret';
        file_put_contents($this->secret_configuration_path, $rotated_secret);
        $second_push_session_id = str_repeat('3', 32);
        $second = $this->requestPushEndpoint(
            $rotated_secret,
            'POST',
            'push_create',
            $second_push_session_id
        );

        $this->assertSame(403, $second['http_code'], $second['body']);
        $this->assertSame('push_disabled', $second['response']['reason']);
        $this->assertDirectoryDoesNotExist(
            $this->reprint_directory . '/.reprint/push/' . $second_push_session_id
        );
    }

    public function testManagedStateOverridesPersonalConsent(): void
    {
        file_put_contents($this->push_authorization_configuration_path, '');
        file_put_contents($this->managed_push_configuration_path, 'true');
        $managed_enabled = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_create',
            str_repeat('4', 32)
        );
        $this->assertSame(200, $managed_enabled['http_code'], $managed_enabled['body']);

        file_put_contents($this->push_authorization_configuration_path, hash('sha256', self::SECRET));
        file_put_contents($this->managed_push_configuration_path, 'false');
        $managed_disabled = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_create',
            str_repeat('5', 32)
        );
        $this->assertSame(403, $managed_disabled['http_code'], $managed_disabled['body']);
        $this->assertSame('push_disabled', $managed_disabled['response']['reason']);
        $this->assertSame(
            'Push access is disabled by the hosting provider through SITE_EXPORT_PUSH_ENABLED.',
            $managed_disabled['response']['detail']
        );
    }

    public function testRevokedAuthorizationCannotStartCommit(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('6', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));

        $upload = $this->sendUploadRequest($client, $push_session_id, [
            [
                'type' => 'file',
                'path' => 'must-not-install.txt',
                'total_bytes' => 4,
                'offset' => 0,
                'payload' => 'deny',
            ],
            [
                'type' => 'delete-list',
                'offset' => 0,
                'complete' => true,
                'payload' => '',
            ],
        ]);
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));

        file_put_contents($this->push_authorization_configuration_path, '');
        $commit = $this->requestPushEndpoint(
            self::SECRET,
            'POST',
            'push_commit',
            $push_session_id
        );

        $this->assertSame(403, $commit['http_code'], $commit['body']);
        $this->assertSame('push_disabled', $commit['response']['reason']);
        $push_directory = $this->reprint_directory . '/.reprint/push/' . $push_session_id;
        $this->assertFileDoesNotExist($push_directory . '/commit.json');
        $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        $this->assertFileDoesNotExist($this->docroot . '/must-not-install.txt');
        $this->assertSame('old', file_get_contents($this->docroot . '/remove.txt'));
    }

    public function testRevokedAuthorizationAllowsDurableCommitRecovery(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('7', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));

        $upload = $this->sendUploadRequest($client, $push_session_id, [
            [
                'type' => 'file',
                'path' => 'installed.txt',
                'total_bytes' => 4,
                'offset' => 0,
                'payload' => 'new!',
            ],
            [
                'type' => 'delete-list',
                'offset' => 0,
                'complete' => true,
                'payload' => '',
            ],
        ]);
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));

        $commit_before_revocation = null;
        for ($request = 0; $request < 10; ++$request) {
            $commit_before_revocation = $client->send_push_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit_before_revocation['status'], (string) json_encode($commit_before_revocation));
            if (is_file($this->docroot . '/installed.txt')) {
                break;
            }
        }
        $this->assertIsArray($commit_before_revocation);
        $this->assertTrue($commit_before_revocation['response']['send_next_request']);
        $this->assertSame('new!', file_get_contents($this->docroot . '/installed.txt'));
        $this->assertFileExists($this->docroot . '/.maintenance');

        file_put_contents($this->push_authorization_configuration_path, '');
        do {
            $commit = $client->send_push_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit['status'], (string) json_encode($commit));
        } while ($commit['response']['send_next_request']);

        $this->assertSame('complete', $commit['response']['phase']);
        $this->assertFileDoesNotExist($this->docroot . '/.maintenance');
        $this->assertFileDoesNotExist($this->reprint_directory . '/.reprint/push/commit-state');
    }

    public function testPersonalOptInEnablesSignedEndpointsReceiveManyChangesCommitAndRemove(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('a', 32);

        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame('created', $create['response']['status']);
        $this->assertSame($push_session_id, $create['response']['push_session_id']);
        $this->assertSame(4, $create['response']['max_part_bytes']);
        $this->assertSame(self::POST_MAX_BYTES, $create['response']['post_max_bytes']);
        $this->assertSame(200, $create['response']['http_code']);
        $client->set_max_part_bytes($create['response']['max_part_bytes']);
        $client->apply_reported_limits([$create['response']['post_max_bytes']]);

        $this->assertTrue($client->start_upload_request($push_session_id));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 0,
            'payload' => "ab\0c",
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 4,
            'payload' => 'defg',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'directory',
            'path' => 'empty-directory',
            'payload' => '',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'symlink',
            'path' => 'file-link',
            'target' => 'nested/file.bin',
            'payload' => '',
        ]));
        $delete_payload = "remove.txt\0";
        $delete_offset = 0;
        foreach (str_split($delete_payload, 4) as $delete_piece) {
            $this->assertTrue($client->send_part([
                'type' => 'delete-list',
                'offset' => $delete_offset,
                'payload' => $delete_piece,
            ]));
            $delete_offset += strlen($delete_piece);
        }
        $this->assertTrue($client->send_part([
            'type' => 'delete-list',
            'offset' => $delete_offset,
            'complete' => true,
            'payload' => '',
        ]));
        $upload = $client->finish_request();
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'changes_accepted' => 8,
            'last_change' => [
                'state' => 'complete',
                'type' => 'delete-list',
                'accepted_bytes' => strlen($delete_payload),
            ],
            'http_code' => 200,
        ], $upload['response']);

        $status = $client->send_push_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
            'path_b64' => base64_encode('nested/file.bin'),
        ], ['accepted']);
        $this->assertSame('complete', $status['status'], (string) json_encode($status));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'phase' => 'receiving_work',
            'work_deletes_bytes' => strlen($delete_payload),
            'work_deletes_complete' => true,
            'path' => [
                'path_b64' => base64_encode('nested/file.bin'),
                'state' => 'complete',
                'type' => 'file',
                'accepted_bytes' => 8,
            ],
            'http_code' => 200,
        ], $status['response']);

        $commit_requests = 0;
        do {
            $commit = $client->send_push_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit['status'], (string) json_encode($commit));
            $this->assertSame([
                'status',
                'push_session_id',
                'phase',
                'send_next_request',
                'entries_processed',
                'http_code',
            ], array_keys($commit['response']));
            $this->assertSame('accepted', $commit['response']['status']);
            $this->assertSame($push_session_id, $commit['response']['push_session_id']);
            $this->assertContains($commit['response']['phase'], ['deleting_files', 'installing_files', 'complete']);
            $this->assertIsBool($commit['response']['send_next_request']);
            $this->assertIsInt($commit['response']['entries_processed']);
            $this->assertSame(200, $commit['response']['http_code']);
            ++$commit_requests;
        } while ($commit['response']['send_next_request']);

        $this->assertGreaterThan(1, $commit_requests, 'The one-entry endpoint budget must require repeated commit calls.');
        $this->assertSame('complete', $commit['response']['phase']);
        $this->assertFileDoesNotExist($this->docroot . '/remove.txt');
        $this->assertSame("ab\0cdefg", file_get_contents($this->docroot . '/nested/file.bin'));
        $this->assertDirectoryExists($this->docroot . '/empty-directory');
        $this->assertSame([], array_values(array_diff(scandir($this->docroot . '/empty-directory') ?: [], ['.', '..'])));
        $this->assertTrue(is_link($this->docroot . '/file-link'));
        $this->assertSame('nested/file.bin', readlink($this->docroot . '/file-link'));
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));
        $this->assertFileDoesNotExist($this->wordpress_root . '/nested/file.bin');
        $this->assertDirectoryDoesNotExist($this->wordpress_root . '/empty-directory');
        $this->assertDirectoryExists($this->reprint_directory . '/.reprint/push/' . $push_session_id);
        $this->assertSame(dirname($this->docroot), dirname($this->reprint_directory));

        do {
            $remove = $client->send_push_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $remove['status'], (string) json_encode($remove));
            $this->assertSame([
                'status' => 'accepted',
                'push_session_id' => $push_session_id,
                'removed' => $remove['response']['removed'],
                'http_code' => 200,
            ], $remove['response']);
        } while (!$remove['response']['removed']);
        $push_directory = $this->reprint_directory . '/.reprint/push/' . $push_session_id;
        clearstatcache(true, $push_directory);
        $this->assertDirectoryDoesNotExist($push_directory);
    }

    public function testPushRemoveReportsCreateRemoveLockContentionWithoutRenamingTheLiveDirectory(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('c', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $push_directory = $this->reprint_directory . '/.reprint/push/' . $push_session_id;
        $push_sessions_directory = dirname($push_directory);
        $lock_process = $this->startLockProcess($push_sessions_directory . '/push-create.lock');

        try {
            $remove = $client->send_push_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
        } finally {
            $this->stopLockProcess($lock_process);
        }

        $this->assertSame('retry', $remove['status'], (string) json_encode($remove));
        $this->assertSame('lock_acquisition_failure', $remove['reason']);
        $this->assertSame(423, $remove['response']['http_code']);
        clearstatcache(true, $push_directory);
        $this->assertDirectoryExists($push_directory);
        $this->assertDirectoryDoesNotExist($push_sessions_directory . '/.removing-' . $push_session_id);
    }

    public function testRemovalTombstoneBlocksCreateAndConvergesThroughHttpEndpoints(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('d', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));

        $directory_parts = [];
        for ($index = 0; $index < 270; ++$index) {
            $directory_parts[] = [
                'type' => 'directory',
                'path' => 'remove-entry-' . $index,
                'payload' => '',
            ];
            if (count($directory_parts) === 15 || $index === 269) {
                $upload = $this->sendUploadRequest($client, $push_session_id, $directory_parts);
                $this->assertSame('complete', $upload['status'], (string) json_encode($upload));
                $this->assertSame(count($directory_parts), $upload['parts_sent']);
                $directory_parts = [];
            }
        }

        $push_directory = $this->reprint_directory . '/.reprint/push/' . $push_session_id;
        $push_sessions_directory = dirname($push_directory);
        $tombstone = $push_sessions_directory . '/.removing-' . $push_session_id;
        $first_remove = $client->send_push_request('POST', 'push_remove', [
            'push_session_id' => $push_session_id,
        ], ['accepted']);
        $this->assertSame('complete', $first_remove['status'], (string) json_encode($first_remove));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'removed' => false,
            'http_code' => 200,
        ], $first_remove['response']);
        clearstatcache(true, $push_directory);
        clearstatcache(true, $tombstone);
        $this->assertDirectoryDoesNotExist($push_directory);
        $this->assertDirectoryExists($tombstone);

        $blocked_create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('retry', $blocked_create['status'], (string) json_encode($blocked_create));
        $this->assertSame('lock_acquisition_failure', $blocked_create['reason']);
        $this->assertSame(423, $blocked_create['response']['http_code']);
        clearstatcache(true, $push_directory);
        clearstatcache(true, $tombstone);
        $this->assertDirectoryDoesNotExist($push_directory);
        $this->assertDirectoryExists($tombstone);

        $lock_process = $this->startLockProcess($push_sessions_directory . '/push-create.lock');
        try {
            $blocked_remove = $client->send_push_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
        } finally {
            $this->stopLockProcess($lock_process);
        }
        $this->assertSame('retry', $blocked_remove['status'], (string) json_encode($blocked_remove));
        $this->assertSame('lock_acquisition_failure', $blocked_remove['reason']);
        $this->assertSame(423, $blocked_remove['response']['http_code']);
        clearstatcache(true, $tombstone);
        $this->assertDirectoryExists($tombstone);

        $remove_requests = 0;
        do {
            $remove = $client->send_push_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            ++$remove_requests;
            $this->assertLessThan(10, $remove_requests, 'Bounded HTTP removal did not converge.');
            $this->assertSame('complete', $remove['status'], (string) json_encode($remove));
            $this->assertSame([
                'status' => 'accepted',
                'push_session_id' => $push_session_id,
                'removed' => $remove['response']['removed'],
                'http_code' => 200,
            ], $remove['response']);
        } while (!$remove['response']['removed']);

        $repeated_remove = $client->send_push_request('POST', 'push_remove', [
            'push_session_id' => $push_session_id,
        ], ['accepted']);
        $this->assertSame('complete', $repeated_remove['status'], (string) json_encode($repeated_remove));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'removed' => true,
            'http_code' => 200,
        ], $repeated_remove['response']);
        clearstatcache(true, $tombstone);
        $this->assertDirectoryDoesNotExist($tombstone);

        $recreated = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $recreated['status'], (string) json_encode($recreated));
        $this->assertSame(200, $recreated['response']['http_code']);
        clearstatcache(true, $push_directory);
        $this->assertDirectoryExists($push_directory);
    }

    public function testPushCreateReportsNormalizedExcludedPathsWhenCreatingAndReopening(): void
    {
        $excluded_paths = [
            'z-last',
            "non-utf8-\xff",
            'a-first',
            'z-last',
        ];
        $this->writeExcludedPaths($excluded_paths);
        $expected_excluded_paths_b64 = array_map('base64_encode', [
            'a-first',
            "non-utf8-\xff",
            'z-last',
        ]);
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('1', 32);

        $created = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $reopened = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);

        $expected_response = [
            'status' => 'created',
            'push_session_id' => $push_session_id,
            'max_part_bytes' => 4,
            'post_max_bytes' => self::POST_MAX_BYTES,
            'excluded_paths_b64' => $expected_excluded_paths_b64,
            'http_code' => 200,
        ];
        $this->assertSame('complete', $created['status'], (string) json_encode($created));
        $this->assertSame($expected_response, $created['response']);
        $this->assertSame('complete', $reopened['status'], (string) json_encode($reopened));
        $this->assertSame($expected_response, $reopened['response']);
        $push_metadata = json_decode(
            (string) file_get_contents($this->reprint_directory . '/.reprint/push/' . $push_session_id . '/push.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame($expected_excluded_paths_b64, $push_metadata['excluded_paths_b64']);
    }

    public function testUploadAndMissingPathStatusExposeOnlyDocumentedFields(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('b', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));

        $upload = $this->sendUploadRequest($client, $push_session_id, [[
            'type' => 'file',
            'path' => 'value.txt',
            'total_bytes' => 1,
            'offset' => 0,
            'payload' => 'x',
        ]]);
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'changes_accepted' => 1,
            'last_change' => [
                'path_b64' => base64_encode('value.txt'),
                'state' => 'complete',
                'type' => 'file',
                'accepted_bytes' => 1,
            ],
            'http_code' => 200,
        ], $upload['response']);

        $status = $client->send_push_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
            'path_b64' => base64_encode('missing.txt'),
        ], ['accepted']);
        $this->assertSame('complete', $status['status'], (string) json_encode($status));
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'phase' => 'receiving_work',
            'work_deletes_bytes' => 0,
            'work_deletes_complete' => false,
            'path' => [
                'path_b64' => base64_encode('missing.txt'),
                'state' => 'missing',
                'accepted_bytes' => 0,
            ],
            'http_code' => 200,
        ], $status['response']);
    }

    public function testUploadReportsDeclaredRequestBodyLimitOn413(): void
    {
        $push_session_id = str_repeat('d', 32);
        $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: multipart/mixed; boundary=oversized-endpoint-test'];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => str_repeat('x', self::POST_MAX_BYTES + 1),
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(413, curl_getinfo($handle, CURLINFO_HTTP_CODE), $body);
        curl_close($handle);

        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'request_too_large',
            'detail' => 'The decoded request body declares 8193 bytes, exceeding the target post_max_size of 8192 bytes.',
            'post_max_bytes' => self::POST_MAX_BYTES,
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testChunkedUploadEnforcesDecodedRequestBodyLimitWhileStreaming(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('f', 32);
        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame(self::POST_MAX_BYTES, $create['response']['post_max_bytes']);

        // MultipartPushStreamClient uses CURLOPT_UPLOAD without a known body
        // length, so this reaches the production router as chunked transport.
        $this->assertTrue($client->start_upload_request($push_session_id));
        for ($part = 0; $part < 200; ++$part) {
            if (!$client->send_part([
                'type' => 'directory',
                'path' => 'request-limit-' . $part,
                'payload' => '',
            ])) {
                break;
            }
        }

        $upload = $client->finish_request();
        $this->assertGreaterThan(self::POST_MAX_BYTES, $upload['body_bytes_sent']);
        $this->assertSame('request_too_large', $upload['reason'], (string) json_encode($upload));
        $this->assertSame('The decoded request body reached 8193 bytes, exceeding the target post_max_size of 8192 bytes.', $upload['detail']);
        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'request_too_large',
            'detail' => 'The decoded request body reached 8193 bytes, exceeding the target post_max_size of 8192 bytes.',
            'observed_request_body_bytes' => self::POST_MAX_BYTES + 1,
            'post_max_bytes' => self::POST_MAX_BYTES,
        ], $upload['response']);
    }

    public function testChunkedUploadDistinguishesEofTrailingDataAndRequestLimitAtTheFragmentBoundary(): void
    {
        $maximum_request_body_bytes = Site_Export_Multipart_Processor::MAX_INPUT_FRAGMENT_BYTES;
        $this->writeDocrootConfiguration([
            'document_root' => $this->docroot,
            'maximum_part_bytes' => $maximum_request_body_bytes,
        ]);
        [$server_process, $server_pipes, $base_url] = $this->startServer($maximum_request_body_bytes);

        try {
            $client = $this->newClient(self::SECRET, $base_url);
            $clean_push_session_id = str_repeat('0', 32);
            $clean_create = $client->send_push_request('POST', 'push_create', [
                'push_session_id' => $clean_push_session_id,
            ], ['created']);
            $this->assertSame('complete', $clean_create['status'], (string) json_encode($clean_create));
            $this->assertSame($maximum_request_body_bytes, $clean_create['response']['post_max_bytes']);

            $boundary = 'endpoint-fragment-limit';
            $suffix = "\r\n--" . $boundary . "--\r\n";
            $payload_bytes = $maximum_request_body_bytes;
            do {
                $previous_payload_bytes = $payload_bytes;
                $prefix = '--' . $boundary . "\r\n"
                    . "X-Chunk-Type: file\r\n"
                    . 'X-File-Path: ' . base64_encode('fragment-limit.bin') . "\r\n"
                    . 'X-File-Size: ' . $payload_bytes . "\r\n"
                    . "X-Chunk-Offset: 0\r\n"
                    . 'Content-Length: ' . $payload_bytes . "\r\n\r\n";
                $payload_bytes = $maximum_request_body_bytes - strlen($prefix) - strlen($suffix);
            } while ($payload_bytes !== $previous_payload_bytes);
            $multipart_body = $prefix . str_repeat('x', $payload_bytes) . $suffix;
            $this->assertSame($maximum_request_body_bytes, strlen($multipart_body));

            $clean_upload = $this->sendChunkedUploadRequest(
                $base_url,
                $clean_push_session_id,
                $boundary,
                $multipart_body
            );
            $this->assertSame(200, $clean_upload['http_code'], (string) json_encode($clean_upload));
            $this->assertSame('accepted', $clean_upload['response']['status']);

            $over_limit_push_session_id = str_repeat('1', 32);
            $over_limit_create = $client->send_push_request('POST', 'push_create', [
                'push_session_id' => $over_limit_push_session_id,
            ], ['created']);
            $this->assertSame('complete', $over_limit_create['status'], (string) json_encode($over_limit_create));
            $over_limit_upload = $this->sendChunkedUploadRequest(
                $base_url,
                $over_limit_push_session_id,
                $boundary,
                $multipart_body,
                'y'
            );
            $this->assertSame(413, $over_limit_upload['http_code'], (string) json_encode($over_limit_upload));
            $this->assertSame('rejected', $over_limit_upload['response']['status']);
            $this->assertSame('request_too_large', $over_limit_upload['response']['reason']);
            $this->assertSame($maximum_request_body_bytes + 1, $over_limit_upload['response']['observed_request_body_bytes']);
            $this->assertSame($maximum_request_body_bytes, $over_limit_upload['response']['post_max_bytes']);
        } finally {
            $this->stopServer($server_process, $server_pipes);
        }

        $larger_maximum_request_body_bytes = $maximum_request_body_bytes * 2;
        [$server_process, $server_pipes, $base_url] = $this->startServer($larger_maximum_request_body_bytes);
        try {
            $client = $this->newClient(self::SECRET, $base_url);
            $trailing_byte_push_session_id = str_repeat('2', 32);
            $create = $client->send_push_request('POST', 'push_create', [
                'push_session_id' => $trailing_byte_push_session_id,
            ], ['created']);
            $this->assertSame('complete', $create['status'], (string) json_encode($create));
            $this->assertSame($larger_maximum_request_body_bytes, $create['response']['post_max_bytes']);

            $trailing_byte_upload = $this->sendChunkedUploadRequest(
                $base_url,
                $trailing_byte_push_session_id,
                $boundary,
                $multipart_body,
                'y'
            );
            $this->assertSame(400, $trailing_byte_upload['http_code'], (string) json_encode($trailing_byte_upload));
            $this->assertSame('rejected', $trailing_byte_upload['response']['status']);
            $this->assertSame('invalid_request', $trailing_byte_upload['response']['reason']);
            $this->assertStringContainsString(
                'bytes after the closing boundary',
                $trailing_byte_upload['response']['detail']
            );
        } finally {
            $this->stopServer($server_process, $server_pipes);
        }
    }

    public function testLogicExceptionUsesGenericServerFailureResponse(): void
    {
        $endpoints = new Site_Export_Push_Endpoints([
            'reprint_directory' => $this->reprint_directory,
            'docroot' => $this->docroot,
            'excluded_paths' => [],
        ]);
        $respond_to_failure = new ReflectionMethod($endpoints, 'respond_to_failure');
        $respond_to_failure->setAccessible(true);
        http_response_code(200);
        ob_start();
        $respond_to_failure->invoke($endpoints, new LogicException('Internal multipart invariant failed.'));
        $body = (string) ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'filesystem_error',
            'detail' => 'The push endpoint failed while processing the request.',
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('invariant', $body);
    }

    public function testFailureResponseDoesNotExposeInternalExceptionContext(): void
    {
        $endpoints = new Site_Export_Push_Endpoints([
            'reprint_directory' => $this->reprint_directory,
            'docroot' => $this->docroot,
            'excluded_paths' => [],
        ]);
        $respond_to_failure = new ReflectionMethod($endpoints, 'respond_to_failure');
        $respond_to_failure->setAccessible(true);
        http_response_code(200);
        ob_start();
        $respond_to_failure->invoke(
            $endpoints,
            new Site_Export_Push_Exception(
                Site_Export_Push_Session::ERROR_SAME_DEVICE,
                'The work and document-root filesystems differ.',
                [
                    'status' => 'context-status',
                    'reason' => 'context-reason',
                    'detail' => 'context-detail',
                    'operation' => 'install',
                    'path_b64' => base64_encode('private-path'),
                ]
            )
        );
        $body = (string) ob_get_clean();

        $this->assertSame(409, http_response_code());
        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'same_device',
            'detail' => 'The work and document-root filesystems differ.',
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testInvalidBase64CursorReturnsInvalidRequestBeforePushDispatch(): void
    {
        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => str_repeat('9', 32),
            'cursor' => 'not-base64',
        ], ['created']);

        $this->assertSame('failed', $response['status']);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertSame('Cursor must be base64-encoded. Received invalid base64: not-base64', $response['detail']);
        $this->assertSame(400, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testEndpointConfigurationRejectsReprintDirectoryInsideDocumentRoot(): void
    {
        $symlink_parent = $this->root . '/symlink-parent';
        mkdir($symlink_parent, 0700);
        symlink($this->docroot, $symlink_parent . '/docroot-link');
        $reprint_directories = [
            $this->docroot,
            $this->docroot . '/missing-reprint-directory',
            $symlink_parent . '/docroot-link/missing-reprint-directory',
        ];

        foreach ($reprint_directories as $reprint_directory) {
            try {
                new Site_Export_Push_Endpoints([
                    'reprint_directory' => $reprint_directory,
                    'docroot' => $this->docroot,
                    'excluded_paths' => [],
                ]);
                $this->fail('Push endpoints accepted reprint_directory ' . json_encode($reprint_directory) . ' inside the document root.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Push endpoints require reprint_directory ' . json_encode($reprint_directory)
                    . ' to be outside docroot ' . json_encode($this->docroot) . '; observed it inside that document root.',
                    $exception->getMessage()
                );
            }
        }

        $configured_reprint_directory = $this->docroot . '/router-reprint-directory';
        file_put_contents($this->reprint_configuration_path, $configured_reprint_directory);
        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => str_repeat('e', 32),
        ], ['created']);
        $this->assertSame('failed', $response['status']);
        $this->assertSame('not_configured', $response['reason']);
        $canonical_docroot = realpath($this->docroot);
        $this->assertIsString($canonical_docroot);
        $this->assertSame(
            'Push endpoints require reprint_directory ' . json_encode($configured_reprint_directory)
            . ' to be outside docroot ' . json_encode($canonical_docroot) . '; observed it inside that document root.',
            $response['detail']
        );
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testPushCreateRejectsNonexistentDocumentRootServerVariable(): void
    {
        $this->writeDocrootConfiguration([
            'document_root' => $this->root . '/missing-document-root',
        ]);

        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => str_repeat('4', 32),
        ], ['created']);

        $this->assertSame('failed', $response['status'], (string) json_encode($response));
        $this->assertSame('not_configured', $response['reason']);
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testPushCreateRejectsMissingServerDocumentRootWithoutFallingBackToWordPressRoot(): void
    {
        $this->writeDocrootConfiguration([]);
        $push_session_id = str_repeat('a', 32);

        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);

        $this->assertSame('failed', $response['status'], (string) json_encode($response));
        $this->assertSame('not_configured', $response['reason']);
        $this->assertSame(
            'Push endpoints require docroot or DOCUMENT_ROOT to name an existing directory; observed null.',
            $response['detail']
        );
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertDirectoryExists($this->wordpress_root);
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    public function testPlatformDocrootOptionOverridesDocumentRootServerVariable(): void
    {
        $explicit_docroot = $this->root . '/explicit-document-root';
        mkdir($explicit_docroot, 0700);
        $this->writeDocrootConfiguration([
            'document_root' => $this->root . '/missing-server-document-root',
            'docroot' => $explicit_docroot,
        ]);
        $push_session_id = str_repeat('5', 32);

        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);

        $this->assertSame('complete', $response['status'], (string) json_encode($response));
        $push_metadata = json_decode(
            (string) file_get_contents($this->reprint_directory . '/.reprint/push/' . $push_session_id . '/push.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(realpath($explicit_docroot), base64_decode($push_metadata['docroot_b64'], true));
        $this->assertNotSame(realpath($this->docroot), base64_decode($push_metadata['docroot_b64'], true));
    }

    public function testDefaultPushDirectoryIsSiblingOfCanonicalDocumentRoot(): void
    {
        file_put_contents($this->reprint_configuration_path, '');
        $document_root_link = $this->root . '/document-root-link';
        symlink($this->docroot, $document_root_link);
        $this->writeDocrootConfiguration([
            'document_root' => $document_root_link,
        ]);
        $push_session_id = str_repeat('7', 32);
        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);

        $this->assertSame('complete', $response['status'], (string) json_encode($response));
        $canonical_docroot = realpath($this->docroot);
        $this->assertIsString($canonical_docroot);
        $default_reprint_directory = dirname($canonical_docroot)
            . '/.reprint-' . substr(hash('sha256', $canonical_docroot), 0, 12);
        $this->assertDirectoryExists($default_reprint_directory . '/.reprint/push/' . $push_session_id);
        $lexical_document_root_reprint_directory = dirname($document_root_link)
            . '/.reprint-' . substr(hash('sha256', $document_root_link), 0, 12);
        $this->assertDirectoryDoesNotExist($lexical_document_root_reprint_directory);
        $wordpress_root_reprint_directory = $this->docroot
            . '/.reprint-' . substr(hash('sha256', (string) realpath($this->wordpress_root)), 0, 12);
        $this->assertDirectoryDoesNotExist($wordpress_root_reprint_directory);
    }

    public function testParentSegmentInExcludedPathReturnsNotConfigured(): void
    {
        $this->writeExcludedPaths(['../bad']);
        $response = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => str_repeat('8', 32),
        ], ['created']);

        $this->assertSame('failed', $response['status']);
        $this->assertSame('not_configured', $response['reason']);
        $this->assertSame('Excluded path must not contain a parent component: Li4vYmFk.', $response['detail']);
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testSymlinkedPluginLogicalPathIsExcludedFromPush(): void
    {
        $physical_plugin_directory = realpath(dirname(__DIR__) . '/reprint-exporter-wp');
        $this->assertIsString($physical_plugin_directory);
        $plugins_directory = $this->docroot . '/wp-content/plugins';
        $plugins_directory_alias = $this->root . '/plugins-directory-alias';
        $logical_plugin_path = 'wp-content/plugins/reprint';
        $logical_plugin_directory = $this->docroot . '/' . $logical_plugin_path;
        mkdir($plugins_directory, 0700, true);
        symlink($plugins_directory, $plugins_directory_alias);
        symlink($physical_plugin_directory, $logical_plugin_directory);
        $this->assertNotSame($plugins_directory_alias, $plugins_directory);
        $this->assertNotSame($plugins_directory_alias, realpath($this->docroot));
        $canonical_plugins_directory = realpath($plugins_directory);
        $this->assertIsString($canonical_plugins_directory);
        $this->assertSame($canonical_plugins_directory, realpath($plugins_directory_alias));
        $plugin_entrypoint_hash = hash_file('sha256', $physical_plugin_directory . '/index.php');
        $this->assertIsString($plugin_entrypoint_hash);
        $this->writeExcludedPaths([]);
        $this->writeDocrootConfiguration([
            'document_root' => $this->docroot,
            'wp_plugin_dir' => $plugins_directory_alias,
            'plugin_basename' => 'reprint/index.php',
            'maximum_part_bytes' => 1024,
        ]);
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('6', 32);

        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame([
            'status' => 'created',
            'push_session_id' => $push_session_id,
            'max_part_bytes' => 1024,
            'post_max_bytes' => self::POST_MAX_BYTES,
            'excluded_paths_b64' => [base64_encode($logical_plugin_path)],
            'http_code' => 200,
        ], $create['response']);

        $upload_plugin = $this->sendUploadRequest($client, $push_session_id, [[
            'type' => 'directory',
            'path' => $logical_plugin_path,
            'payload' => '',
        ]]);
        $this->assertSame('failed', $upload_plugin['status'], (string) json_encode($upload_plugin));
        $this->assertSame('invalid_request', $upload_plugin['reason']);
        $this->assertStringContainsString('Excluded document-root-relative path', $upload_plugin['detail']);

        $delete_plugin = $this->sendUploadRequest($client, $push_session_id, [[
            'type' => 'delete-list',
            'offset' => 0,
            'complete' => true,
            'payload' => $logical_plugin_path . "\0",
        ]]);
        $this->assertSame('failed', $delete_plugin['status'], (string) json_encode($delete_plugin));
        $this->assertSame('invalid_request', $delete_plugin['reason']);
        $this->assertStringContainsString('Excluded document-root-relative path', $delete_plugin['detail']);

        $upload_plugin_parent = $this->sendUploadRequest($client, $push_session_id, [[
            'type' => 'directory',
            'path' => 'wp-content/plugins',
            'payload' => '',
        ]]);
        $this->assertSame('failed', $upload_plugin_parent['status'], (string) json_encode($upload_plugin_parent));
        $this->assertSame('invalid_request', $upload_plugin_parent['reason']);
        $this->assertStringContainsString('Excluded document-root-relative path', $upload_plugin_parent['detail']);

        $sibling_path = 'wp-content/plugins/other/file.php';
        $upload_sibling = $this->sendUploadRequest($client, $push_session_id, [
            [
                'type' => 'file',
                'path' => $sibling_path,
                'total_bytes' => 4,
                'offset' => 0,
                'payload' => 'safe',
            ],
            [
                'type' => 'delete-list',
                'offset' => 0,
                'complete' => true,
                'payload' => '',
            ],
        ]);
        $this->assertSame('complete', $upload_sibling['status'], (string) json_encode($upload_sibling));
        $this->assertSame(2, $upload_sibling['parts_sent']);
        do {
            $commit = $client->send_push_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit['status'], (string) json_encode($commit));
        } while ($commit['response']['send_next_request']);

        $this->assertSame('safe', file_get_contents($this->docroot . '/' . $sibling_path));
        $this->assertTrue(is_link($logical_plugin_directory));
        $this->assertSame($physical_plugin_directory, readlink($logical_plugin_directory));
        $this->assertSame($physical_plugin_directory, realpath($logical_plugin_directory));
        $this->assertSame($plugin_entrypoint_hash, hash_file('sha256', $physical_plugin_directory . '/index.php'));
    }

    public function testPreflightDoesNotConstructPushEndpoints(): void
    {
        file_put_contents($this->push_authorization_configuration_path, '');
        file_put_contents($this->reprint_configuration_path, $this->docroot . '/invalid-push-directory');
        $url = $this->base_url . '&endpoint=preflight';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_curl_headers();
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(200, curl_getinfo($handle, CURLINFO_HTTP_CODE), $body);
        curl_close($handle);

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ok', $response);
        $this->assertStringNotContainsString('Push endpoints require', $body);
    }

    public function testSuccessfulPushResponseSendsNoCacheHeaders(): void
    {
        $push_session_id = str_repeat('2', 32);
        $create = $this->newClient(self::SECRET)->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));

        $status = $this->sendPushRequestWithHeaders('GET', 'push_status', [
            'push_session_id' => $push_session_id,
        ], self::SECRET);

        $this->assertSame(200, $status['http_code'], $status['body']);
        $this->assertSame([
            'status' => 'accepted',
            'push_session_id' => $push_session_id,
            'phase' => 'receiving_work',
            'work_deletes_bytes' => 0,
            'work_deletes_complete' => false,
            'path' => null,
        ], json_decode($status['body'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertNoCacheHeaders($status['headers']);
    }

    public function testPushAuthenticationFailureSendsNoCacheHeaders(): void
    {
        $authentication_failure = $this->sendPushRequestWithHeaders('POST', 'push_create', [
            'push_session_id' => str_repeat('3', 32),
        ], 'incorrect-secret');

        $this->assertSame(403, $authentication_failure['http_code'], $authentication_failure['body']);
        $this->assertSame('auth_failed', json_decode($authentication_failure['body'], true, 512, JSON_THROW_ON_ERROR)['reason']);
        $this->assertNoCacheHeaders($authentication_failure['headers']);
    }

    public function testEndpointGuardsRejectWrongMethodContentTypeAndAuthentication(): void
    {
        $push_session_id = str_repeat('b', 32);
        $client = $this->newClient(self::SECRET);

        $wrong_method = $client->send_push_request('GET', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $wrong_method['status']);
        $this->assertSame('invalid_request', $wrong_method['reason']);
        $this->assertSame('Push endpoint requires POST; observed GET.', $wrong_method['detail']);

        $wrong_secret = $this->newClient('not-the-server-secret');
        $authentication = $wrong_secret->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $authentication['status']);
        $this->assertSame('auth_failed', $authentication['reason']);
        $this->assertStringContainsString('HMAC signature verification failed', $authentication['detail']);

        $create = $client->send_push_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status']);
        $push_lock = fopen($this->reprint_directory . '/.reprint/push/' . $push_session_id . '/push.lock', 'c+b');
        $this->assertIsResource($push_lock);
        $this->assertTrue(flock($push_lock, LOCK_EX | LOCK_NB));
        $lock_contention = $client->send_push_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
        ], ['accepted']);
        $this->assertSame('retry', $lock_contention['status']);
        $this->assertSame('lock_acquisition_failure', $lock_contention['reason']);
        flock($push_lock, LOCK_UN);
        fclose($push_lock);

        $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart/mixed', $response['detail']);

        $boundary = 'truncated-endpoint-test';
        $truncated_body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('truncated.txt') . "\r\n"
            . "X-File-Size: 1\r\n"
            . "X-Chunk-Offset: 0\r\n"
            . "Content-Length: 1\r\n\r\n"
            . 'x';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: multipart/mixed; boundary=' . $boundary];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $truncated_body,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart body ended before', $response['detail']);
    }

    /**
     * Completes mixed local work while reconstructing the sender each step.
     *
     * Each sender step carries at most one part and resumes from the durable
     * boundary left by the preceding sender.
     */
    public function testHighLevelSenderStreamsAndResumesFromDurableBoundaries(): void
    {
        $local_docroot = $this->root . '/local-docroot';
        mkdir($local_docroot . '/nested', 0700, true);
        mkdir($local_docroot . '/empty-directory', 0700, true);
        file_put_contents($local_docroot . '/nested/large.bin', str_repeat('A', 2000));
        file_put_contents($local_docroot . '/same-size.txt', 'new!');
        symlink('nested/large.bin', $local_docroot . '/file-link');
        file_put_contents($this->docroot . '/same-size.txt', 'old!');
        mkdir($this->docroot . '/replace-directory');
        file_put_contents($this->docroot . '/replace-directory/old.txt', 'old');
        file_put_contents($local_docroot . '/replace-directory', 'replacement');

        $fresh_local_index_path = $this->root . '/fresh-local-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'empty-directory' => $this->indexEntry($local_docroot . '/empty-directory', 'dir', true),
            'file-link' => $this->indexEntry($local_docroot . '/file-link', 'link'),
            'nested' => $this->indexEntry($local_docroot . '/nested', 'dir', false),
            'nested/large.bin' => $this->indexEntry($local_docroot . '/nested/large.bin', 'file'),
            'replace-directory' => $this->indexEntry($local_docroot . '/replace-directory', 'file'),
            'same-size.txt' => $this->indexEntry($local_docroot . '/same-size.txt', 'file'),
        ]);
        $previous_local_index_path = $this->root . '/previous-local-index.jsonl';
        $this->writeIndex($previous_local_index_path, [
            'remove.txt' => [1, 3, 'file'],
            'replace-directory' => [1, 0, 'dir', false],
            'replace-directory/old.txt' => [1, 3, 'file'],
            'same-size.txt' => [1, 4, 'file'],
        ]);
        $push_state_directory = $this->root . '/sender-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        $removed_caller_index = false;
        $commit_advances = 0;
        for ($step = 0; $step < 200; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (
                !$removed_caller_index
                && is_array($state)
                && !in_array($state['phase'], ['creating', 'planning'], true)
            ) {
                unlink($fresh_local_index_path);
                $removed_caller_index = true;
            }
            if (is_array($state) && $state['phase'] === 'committing') {
                ++$commit_advances;
            }
            if ($result['status'] !== 'continue') {
                break;
            }
        }

        $this->assertTrue($removed_caller_index, 'PushPlan must own the fresh index after planning starts.');
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertGreaterThan(1, $commit_advances, 'The endpoint work budget must require repeated commit requests.');
        $this->assertSame(str_repeat('A', 2000), file_get_contents($this->docroot . '/nested/large.bin'));
        $this->assertSame('new!', file_get_contents($this->docroot . '/same-size.txt'));
        $this->assertSame('replacement', file_get_contents($this->docroot . '/replace-directory'));
        $this->assertDirectoryExists($this->docroot . '/empty-directory');
        $this->assertTrue(is_link($this->docroot . '/file-link'));
        $this->assertFileDoesNotExist($this->docroot . '/remove.txt');
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));
        $this->assertNull($this->loadActiveState($push_state_directory));
        $this->assertFileDoesNotExist($push_state_directory . '/cursor.json');
        $this->assertSame(
            file_get_contents($push_state_directory . '/fresh_local_index.jsonl'),
            file_get_contents($push_state_directory . '/local_index_at_previous_push.jsonl')
        );
    }

    /**
     * Sends one local path to delete and returns before reading the next one.
     */
    public function testHighLevelSenderSendsOneDeletionListPartPerStep(): void
    {
        $local_docroot = $this->root . '/single-delete-part-local-docroot';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/single-delete-part-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);
        $previous_local_index_path = $this->root . '/single-delete-part-previous-index.jsonl';
        $previous_entries = [];
        for ($index = 0; $index < 20; ++$index) {
            $previous_entries[sprintf('delete-%02d.txt', $index)] = [1, 1, 'file'];
        }
        $this->writeIndex($previous_local_index_path, $previous_entries);
        $push_state_directory = $this->root . '/single-delete-part-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_deletes') {
                break;
            }
        }
        $this->assertIsArray($state);

        $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
        $this->assertSame('continue', $result['status']);
        $this->assertSame('pushing_deletes', $result['phase']);
        $status = $this->sendPushRequestWithHeaders(
            'GET',
            'push_status',
            ['push_session_id' => $state['push_session_id']],
            self::SECRET
        );
        $status_response = json_decode($status['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(14, $status_response['work_deletes_bytes']);
        $this->assertFalse($status_response['work_deletes_complete']);
    }

    /**
     * Removes a push session when a planned deletion reappears locally.
     */
    public function testHighLevelSenderDoesNotDeleteAPathThatReappearedAfterPlanning(): void
    {
        $local_docroot = $this->root . '/reappeared-delete-local-docroot';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/reappeared-delete-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);
        $previous_local_index_path = $this->root . '/reappeared-delete-previous-index.jsonl';
        $this->writeIndex($previous_local_index_path, [
            'returned.txt' => [1, 3, 'file'],
        ]);
        $push_state_directory = $this->root . '/reappeared-delete-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);
        $options = $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory);

        $sender = PushFilesSender::start($options);
        try {
            $this->assertTrue($sender->next_step());
            $this->assertSame('planning', $sender->get_phase());
            $this->assertTrue($sender->next_step());
            $this->assertSame('pushing_paths', $sender->get_phase());
            $this->assertTrue($sender->next_step());
            $this->assertSame('pushing_deletes', $sender->get_phase());
            file_put_contents($local_docroot . '/returned.txt', 'new');

            $this->assertTrue($sender->next_step());

            $result = $this->senderResult($sender);
        } finally {
            $sender->close();
        }

        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);
    }

    /**
     * Checks one fresh local index entry per step before rejecting a reappeared deletion.
     */
    public function testHighLevelSenderResumesADeletionCheckAtTheNextFreshIndexEntry(): void
    {
        $local_docroot = $this->root . '/bounded-delete-check-local-docroot';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/bounded-delete-check-index.jsonl';
        $fresh_index_entries = [];
        for ($index = 0; $index < 4; ++$index) {
            $path = sprintf('earlier-%02d.txt', $index);
            file_put_contents($local_docroot . '/' . $path, 'value');
            $fresh_index_entries[$path] = $this->indexEntry($local_docroot . '/' . $path, 'file');
        }
        $this->writeIndex($fresh_local_index_path, $fresh_index_entries);
        $previous_local_index_path = $this->root . '/bounded-delete-check-previous-index.jsonl';
        $this->writeIndex($previous_local_index_path, [
            'returned.txt' => [1, 3, 'file'],
        ]);
        $push_state_directory = $this->root . '/bounded-delete-check-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_deletes') {
                break;
            }
        }
        $this->assertIsArray($state);
        file_put_contents($local_docroot . '/returned.txt', 'new');

        $fresh_index_lines = file($push_state_directory . '/fresh_local_index.jsonl');
        $this->assertIsArray($fresh_index_lines);
        $expected_fresh_local_index_byte_offset = 0;
        foreach ($fresh_index_lines as $fresh_index_line) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertSame('continue', $result['status']);
            $this->assertSame('pushing_deletes', $result['phase']);
            $state = $this->loadActiveState($push_state_directory);
            $this->assertIsArray($state);
            $expected_fresh_local_index_byte_offset += strlen($fresh_index_line);
            $this->assertSame(
                $expected_fresh_local_index_byte_offset,
                $state['fresh_local_index_byte_offset']
            );
        }

        $status = $this->sendPushRequestWithHeaders(
            'GET',
            'push_status',
            ['push_session_id' => $state['push_session_id']],
            self::SECRET
        );
        $status_response = json_decode($status['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status_response['work_deletes_bytes']);

        $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);
    }

    /**
     * Rejects a planned replacement that disappears before its deletion is sent.
     */
    public function testHighLevelSenderRejectsAPlannedReplacementThatDisappearsBeforeDeletion(): void
    {
        $local_docroot = $this->root . '/disappeared-replacement-local-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/replace.txt', 'new');
        $fresh_local_index_path = $this->root . '/disappeared-replacement-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'replace.txt' => $this->indexEntry($local_docroot . '/replace.txt', 'file'),
        ]);
        $previous_local_index_path = $this->root . '/disappeared-replacement-previous-index.jsonl';
        $this->writeIndex($previous_local_index_path, [
            'replace.txt' => [1, 0, 'dir', false],
            'replace.txt/old.txt' => [1, 3, 'file'],
        ]);
        $push_state_directory = $this->root . '/disappeared-replacement-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_deletes') {
                break;
            }
        }
        $this->assertIsArray($state);
        unlink($local_docroot . '/replace.txt');

        $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);

        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);
        $status = $this->sendPushRequestWithHeaders(
            'GET',
            'push_status',
            ['push_session_id' => $state['push_session_id']],
            self::SECRET
        );
        $status_response = json_decode($status['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status_response['work_deletes_bytes']);
    }

    /**
     * Retains one open PushPlan and leaves sender state untouched while planning continues.
     */
    public function testHighLevelSenderRetainsPushPlanAcrossPlanningSteps(): void
    {
        $local_docroot = $this->root . '/retained-plan-local-docroot';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/retained-plan-index.jsonl';
        $index_entries = [];
        for ($index = 0; $index < 2500; ++$index) {
            $index_entries[sprintf('file-%04d.txt', $index)] = [1, 1, 'file'];
        }
        $this->writeIndex($fresh_local_index_path, $index_entries);
        $push_state_directory = $this->root . '/retained-plan-state';
        $state_path = $push_state_directory . '/sender.json';
        $plan_property = new ReflectionProperty(PushFilesSender::class, 'plan');
        $fresh_local_index_handle_property = new ReflectionProperty(
            PushPlan::class,
            'fresh_local_index_handle'
        );
        $fresh_local_index_handle = null;

        $sender = PushFilesSender::start(
            $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory)
        );
        try {
            $sender->next_step();
            $create_result = $this->senderResult($sender);
            $this->assertSame('planning', $create_result['phase']);
            $plan = $plan_property->getValue($sender);
            $this->assertInstanceOf(PushPlan::class, $plan);
            $fresh_local_index_handle = $fresh_local_index_handle_property->getValue($plan);
            $this->assertIsResource($fresh_local_index_handle);

            clearstatcache(true, $state_path);
            $state_inode = fileinode($state_path);
            $this->assertIsInt($state_inode);

            $sender->next_step();

            $first_plan_result = $this->senderResult($sender);
            $this->assertSame('planning', $first_plan_result['phase']);
            $this->assertSame($plan, $plan_property->getValue($sender));
            clearstatcache(true, $state_path);
            $this->assertSame($state_inode, fileinode($state_path));

            $sender->next_step();

            $second_plan_result = $this->senderResult($sender);
            $this->assertSame('planning', $second_plan_result['phase']);
            $this->assertSame($plan, $plan_property->getValue($sender));
            clearstatcache(true, $state_path);
            $this->assertSame($state_inode, fileinode($state_path));
        } finally {
            $sender->close();
        }

        $this->assertFalse(is_resource($fresh_local_index_handle));
    }

    /**
     * Retains the path lists and current local file until close().
     */
    public function testHighLevelSenderRetainsOpenFilesUntilClose(): void
    {
        $local_docroot = $this->root . '/retained-handles-local-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/file.bin', str_repeat('A', 130));
        $fresh_local_index_path = $this->root . '/retained-handles-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'file.bin' => $this->indexEntry($local_docroot . '/file.bin', 'file'),
        ]);
        $previous_local_index_path = $this->root . '/retained-handles-previous-index.jsonl';
        $previous_entries = [];
        for ($index = 0; $index < 20; ++$index) {
            $previous_entries[sprintf('delete-%02d.txt', $index)] = [1, 1, 'file'];
        }
        $this->writeIndex($previous_local_index_path, $previous_entries);
        $push_state_directory = $this->root . '/retained-handles-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        $local_paths_to_push_handle_property = new ReflectionProperty(
            PushFilesSender::class,
            'local_paths_to_push_handle'
        );
        $local_file_handle_property = new ReflectionProperty(PushFilesSender::class, 'local_file_handle');
        $local_paths_to_delete_handle_property = new ReflectionProperty(
            PushFilesSender::class,
            'local_paths_to_delete_handle'
        );
        $fresh_local_index_handle_property = new ReflectionProperty(
            PushFilesSender::class,
            'fresh_local_index_handle'
        );
        $push_stream_client_property = new ReflectionProperty(PushFilesSender::class, 'push_stream_client');
        $curl_handle_property = new ReflectionProperty(MultipartPushStreamClient::class, 'curl_handle');
        $options = $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory);
        $sender = PushFilesSender::start($options);
        try {
            do {
                $sender->next_step();
                $planning_result = $this->senderResult($sender);
            } while ($planning_result['phase'] === 'planning');
            $this->assertSame('pushing_paths', $planning_result['phase']);
            clearstatcache(true, $push_state_directory . '/sender.json');
            $state_inode_before_upload = fileinode($push_state_directory . '/sender.json');
            $this->assertIsInt($state_inode_before_upload);

            $sender->next_step();

            $first_file_chunk = $this->senderResult($sender);
            $this->assertSame('pushing_paths', $first_file_chunk['phase']);

            $local_paths_to_push_handle = $local_paths_to_push_handle_property->getValue($sender);
            $local_file_handle = $local_file_handle_property->getValue($sender);
            $this->assertIsResource($local_paths_to_push_handle);
            $this->assertIsResource($local_file_handle);
            $local_file_position = ftell($local_file_handle);
            $this->assertIsInt($local_file_position);
            $local_paths_to_push_position = ftell($local_paths_to_push_handle);
            $this->assertIsInt($local_paths_to_push_position);
            $push_stream_client = $push_stream_client_property->getValue($sender);
            $curl_handle = $curl_handle_property->getValue($push_stream_client);
            $this->assertNotNull($curl_handle);

            $sender->next_step();

            $second_file_chunk = $this->senderResult($sender);
            $this->assertSame('pushing_paths', $second_file_chunk['phase']);
            $this->assertSame($local_paths_to_push_handle, $local_paths_to_push_handle_property->getValue($sender));
            $this->assertSame($local_file_handle, $local_file_handle_property->getValue($sender));
            $this->assertGreaterThan($local_file_position, ftell($local_file_handle));
            $this->assertSame($local_paths_to_push_position, ftell($local_paths_to_push_handle));
            $this->assertSame($curl_handle, $curl_handle_property->getValue($push_stream_client));
            clearstatcache(true, $push_state_directory . '/sender.json');
            $this->assertSame($state_inode_before_upload, fileinode($push_state_directory . '/sender.json'));

        } finally {
            $sender->close();
        }
        $this->assertNull($local_paths_to_push_handle_property->getValue($sender));
        $this->assertNull($local_file_handle_property->getValue($sender));
        $this->assertFalse(is_resource($local_paths_to_push_handle));
        $this->assertFalse(is_resource($local_file_handle));
        $this->assertNull($curl_handle_property->getValue($push_stream_client));
        clearstatcache(true, $push_state_directory . '/sender.json');
        $this->assertNotSame($state_inode_before_upload, fileinode($push_state_directory . '/sender.json'));

        $sender = PushFilesSender::resume($options);
        try {
            $sender->next_step();
            $sender->next_step();
            $sender->next_step();
            $sender->next_step();
            $first_delete_chunk = $this->senderResult($sender);
            $this->assertSame('pushing_deletes', $first_delete_chunk['phase']);
            $local_paths_to_delete_handle = $local_paths_to_delete_handle_property->getValue($sender);
            $fresh_local_index_handle = $fresh_local_index_handle_property->getValue($sender);
            $this->assertIsResource($local_paths_to_delete_handle);
            $this->assertIsResource($fresh_local_index_handle);
            $local_paths_to_delete_position = ftell($local_paths_to_delete_handle);
            $this->assertIsInt($local_paths_to_delete_position);

            $sender->next_step();

            $second_delete_chunk = $this->senderResult($sender);
            $this->assertSame('pushing_deletes', $second_delete_chunk['phase']);
            $this->assertSame(
                $local_paths_to_delete_handle,
                $local_paths_to_delete_handle_property->getValue($sender)
            );
            $this->assertSame(
                $fresh_local_index_handle,
                $fresh_local_index_handle_property->getValue($sender)
            );
            $this->assertGreaterThan(
                $local_paths_to_delete_position,
                ftell($local_paths_to_delete_handle)
            );
        } finally {
            $sender->close();
        }
        $this->assertNull($local_paths_to_delete_handle_property->getValue($sender));
        $this->assertNull($fresh_local_index_handle_property->getValue($sender));
        $this->assertFalse(is_resource($local_paths_to_delete_handle));
        $this->assertFalse(is_resource($fresh_local_index_handle));
    }

    /**
     * Removes a push session when a local path to push disappears.
     */
    public function testHighLevelSenderRemovesSessionWhenLocalPathToPushDisappears(): void
    {
        $local_docroot = $this->root . '/deleted-local-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/large.bin', str_repeat('A', 2000));
        $fresh_local_index_path = $this->root . '/deleted-local-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'large.bin' => $this->indexEntry($local_docroot . '/large.bin', 'file'),
        ]);
        $push_state_directory = $this->root . '/deleted-local-state';

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_paths' && $step >= 2) {
                break;
            }
        }
        $this->assertIsArray($state);
        $push_session_id = $state['push_session_id'];
        unlink($local_docroot . '/large.bin');

        for (; $step < 60; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            if ($result['status'] !== 'continue') {
                break;
            }
        }
        $this->assertSame('restart', $result['status'], (string) json_encode($result));
        $this->assertSame('local_path_changed', $result['reason']);
        $this->assertNull($this->loadActiveState($push_state_directory));
        $this->assertFileDoesNotExist($push_state_directory . '/cursor.json');
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    /**
     * Removes work selected from an older local-file version.
     */
    public function testHighLevelSenderRemovesSessionWhenPlannedFileChangesDuringUpload(): void
    {
        $local_docroot = $this->root . '/changed-planned-file-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/large.bin', str_repeat('A', 2000));
        $fresh_local_index_path = $this->root . '/changed-planned-file-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'large.bin' => $this->indexEntry($local_docroot . '/large.bin', 'file'),
        ]);
        $push_state_directory = $this->root . '/changed-planned-file-state';

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_paths' && $step >= 2) {
                break;
            }
        }
        $this->assertIsArray($state);
        $push_session_id = $state['push_session_id'];

        sleep(1);
        file_put_contents($local_docroot . '/large.bin', str_repeat('B', 2000));
        clearstatcache(true, $local_docroot . '/large.bin');

        $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);

        for (; $step < 60; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            if ($result['status'] !== 'continue') {
                break;
            }
        }
        $this->assertSame('restart', $result['status']);
        $this->assertNull($this->loadActiveState($push_state_directory));
        $this->assertFileDoesNotExist($push_state_directory . '/cursor.json');
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    /**
     * Rejects an empty-directory selection after that directory gains a child.
     */
    public function testHighLevelSenderRemovesSessionWhenPlannedEmptyDirectoryChanges(): void
    {
        $local_docroot = $this->root . '/changed-empty-directory-docroot';
        mkdir($local_docroot . '/empty', 0700, true);
        $fresh_local_index_path = $this->root . '/changed-empty-directory-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'empty' => $this->indexEntry($local_docroot . '/empty', 'dir', true),
        ]);
        $push_state_directory = $this->root . '/changed-empty-directory-state';
        $options = $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory);

        $sender = PushFilesSender::start($options);
        try {
            $this->assertTrue($sender->next_step());
            $this->assertSame('planning', $sender->get_phase());
            $this->assertTrue($sender->next_step());
            $this->assertSame('pushing_paths', $sender->get_phase());
            file_put_contents($local_docroot . '/empty/child.txt', 'new');

            $sender->next_step();

            $result = $this->senderResult($sender);
        } finally {
            $sender->close();
        }

        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);
    }

    /**
     * Distinguishes an unpushable local file type from a path that disappeared.
     */
    public function testHighLevelSenderReportsWhenLocalPathChangesToUnsupportedType(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo() is unavailable.');
        }

        $local_docroot = $this->root . '/unsupported-local-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/value.bin', str_repeat('A', 2000));
        $fresh_local_index_path = $this->root . '/unsupported-local-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'value.bin' => $this->indexEntry($local_docroot . '/value.bin', 'file'),
        ]);
        $push_state_directory = $this->root . '/unsupported-local-state';

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_paths' && $step >= 2) {
                break;
            }
        }
        $this->assertIsArray($state);

        unlink($local_docroot . '/value.bin');
        $this->assertTrue(posix_mkfifo($local_docroot . '/value.bin', 0600));

        $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
        $this->assertSame('continue', $result['status']);
        $this->assertSame('removing', $result['phase']);
        $this->assertSame('local_path_changed', $result['reason']);
        $this->assertStringContainsString('file type that cannot be pushed', $result['detail']);
    }

    /**
     * Applies receiver exclusions without maintaining a second baseline model.
     */
    public function testHighLevelSenderUsesReceiverExclusionsInPushPlan(): void
    {
        $local_docroot = $this->root . '/excluded-local-docroot';
        mkdir($local_docroot . '/preserved', 0700, true);
        file_put_contents($local_docroot . '/preserved/value.txt', 'local-change');
        file_put_contents($local_docroot . '/public.txt', 'public-change');
        $fresh_local_index_path = $this->root . '/excluded-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'preserved' => $this->indexEntry($local_docroot . '/preserved', 'dir', false),
            'preserved/value.txt' => $this->indexEntry($local_docroot . '/preserved/value.txt', 'file'),
            'public.txt' => $this->indexEntry($local_docroot . '/public.txt', 'file'),
        ]);
        $push_state_directory = $this->root . '/excluded-state';

        $result = $this->runSender($local_docroot, $fresh_local_index_path, $push_state_directory);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));
        $this->assertSame('public-change', file_get_contents($this->docroot . '/public.txt'));
        $this->assertSame(
            file_get_contents($push_state_directory . '/fresh_local_index.jsonl'),
            file_get_contents($push_state_directory . '/local_index_at_previous_push.jsonl'),
            'Exclusions suppress remote work but do not create a parallel retained-index representation.'
        );
    }

    /**
     * Holds the lifecycle lock while open and resumes the last returned boundary.
     */
    public function testHighLevelSenderOwnsLifecycleLockAndResumesAfterClose(): void
    {
        $local_docroot = $this->root . '/locked-local-docroot';
        $push_state_directory = $this->root . '/locked-state';
        mkdir($local_docroot, 0700, true);
        mkdir($push_state_directory, 0700, true);
        $fresh_local_index_path = $this->root . '/locked-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);
        $lock = fopen($push_state_directory . '/sender.lock', 'c+');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            try {
                PushFilesSender::start(
                    $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory)
                );
                $this->fail('Starting a sender must fail while another process owns its lock.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('another process holds its lock', $exception->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $options = $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory);
        $sender = PushFilesSender::start($options);
        try {
            $sender->next_step();
            $first = $this->senderResult($sender);
            $sender->next_step();
            $second = $this->senderResult($sender);
            $this->assertSame('continue', $first['status']);
            $this->assertSame('continue', $second['status']);
            try {
                PushFilesSender::resume($options);
                $this->fail('Resuming a sender must fail until the open sender is closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('another process holds its lock', $exception->getMessage());
            }
        } finally {
            $sender->close();
        }
        $state_at_caller_stop = $this->loadActiveState($push_state_directory);
        $this->assertIsArray($state_at_caller_stop);
        $this->assertSame('pushing_paths', $state_at_caller_stop['phase']);

        try {
            $sender->next_step();
            $this->fail('A closed sender must reject another step.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('after close()', $exception->getMessage());
        }

        $resumed_sender = PushFilesSender::resume($options);
        try {
            $resumed_sender->next_step();
            $result_after_resume = $this->senderResult($resumed_sender);
            $this->assertSame('continue', $result_after_resume['status']);
            $this->assertSame('pushing_deletes', $result_after_resume['phase']);
        } finally {
            $resumed_sender->close();
        }

        try {
            PushFilesSender::start($options);
            $this->fail('Starting a sender must not replace unfinished active state.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('unfinished active state exists', $exception->getMessage());
        }

        $this->assertSame(
            'complete',
            $this->runSender($local_docroot, $fresh_local_index_path, $push_state_directory)['status']
        );
    }

    /**
     * Leaves a missing push state directory absent when there is nothing to resume.
     */
    public function testHighLevelSenderDoesNotCreateStateDirectoryWhenResumeHasNoState(): void
    {
        $local_docroot = $this->root . '/missing-state-local-docroot';
        $push_state_directory = $this->root . '/missing-state';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/missing-state-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);

        try {
            PushFilesSender::resume(
                $this->senderOptions($local_docroot, $fresh_local_index_path, $push_state_directory)
            );
            $this->fail('Resuming without active state must fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('without unfinished active state', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($push_state_directory);
    }

    /**
     * Leaves active state for a later command after a request failure.
     */
    public function testHighLevelSenderLeavesRequestFailureForTheNextCommand(): void
    {
        $local_docroot = $this->root . '/retry-local-docroot';
        mkdir($local_docroot, 0700, true);
        file_put_contents($local_docroot . '/value.txt', 'value');
        $fresh_local_index_path = $this->root . '/retry-index.jsonl';
        $this->writeIndex($fresh_local_index_path, [
            'value.txt' => $this->indexEntry($local_docroot . '/value.txt', 'file'),
        ]);
        $push_state_directory = $this->root . '/retry-state';

        for ($step = 0; $step < 20; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_paths') {
                break;
            }
        }
        $this->assertIsArray($state);
        $push_lock = fopen(
            $this->reprint_directory . '/.reprint/push/' . $state['push_session_id'] . '/push.lock',
            'r+b'
        );
        $this->assertIsResource($push_lock);
        $this->assertTrue(flock($push_lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
        } finally {
            flock($push_lock, LOCK_UN);
            fclose($push_lock);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('lock_acquisition_failure', $result['reason']);
        $this->assertIsArray($this->loadActiveState($push_state_directory));
        $this->assertSame(
            'complete',
            $this->runSender($local_docroot, $fresh_local_index_path, $push_state_directory)['status']
        );
    }

    /**
     * Treats a complete non-JSON push response as a terminal protocol error.
     */
    public function testHighLevelSenderTreatsMalformedPushResponseAsTerminal(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Malformed push-response coverage requires pcntl.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertNotFalse($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 3);
            if ($connection === false) {
                exit(2);
            }
            $request_head = '';
            while (strpos($request_head, "\r\n\r\n") === false && !feof($connection)) {
                $piece = fread($connection, 4096);
                if (!is_string($piece) || $piece === '') {
                    break;
                }
                $request_head .= $piece;
            }
            $body = '<html>not a push response</html>';
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        $local_docroot = $this->root . '/malformed-local-docroot';
        $push_state_directory = $this->root . '/malformed-state';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/malformed-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);
        $sender = PushFilesSender::start([
            'docroot' => $local_docroot,
            'fresh_local_index_path' => $fresh_local_index_path,
            'push_state_directory' => $push_state_directory,
            'base_url' => 'http://' . $address . '/?reprint-api=1',
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'connect_timeout' => 2,
            'response_timeout' => 2,
        ]);
        try {
            $this->assertFalse($sender->next_step());
            $result = $this->senderResult($sender);
        } finally {
            $sender->close();
        }
        pcntl_waitpid($child, $status);
        fclose($listener);

        $this->assertSame('failed', $result['status'], (string) json_encode($result));
        $this->assertSame('malformed_response', $result['reason']);
        $this->assertIsArray($this->loadActiveState($push_state_directory));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    /**
     * Reconciles deletion close and commit after their responses are lost.
     */
    public function testHighLevelSenderRepeatsDeletionAndCommitAfterLostResponses(): void
    {
        file_put_contents($this->docroot . '/delete-after-lost-response.txt', 'old');
        $local_docroot = $this->root . '/lost-response-local-docroot';
        mkdir($local_docroot, 0700, true);
        $fresh_local_index_path = $this->root . '/lost-response-index.jsonl';
        $this->writeIndex($fresh_local_index_path, []);
        $previous_local_index_path = $this->root . '/lost-response-previous-index.jsonl';
        $this->writeIndex($previous_local_index_path, [
            'delete-after-lost-response.txt' => [1, 3, 'file'],
        ]);
        $push_state_directory = $this->root . '/lost-response-state';
        $this->seedPreviousLocalIndex($push_state_directory, $previous_local_index_path);

        for ($step = 0; $step < 30; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'pushing_deletes') {
                break;
            }
        }
        $this->assertIsArray($state);
        $deletions = (string) file_get_contents($push_state_directory . '/local_paths_to_delete');
        $this->assertSame("delete-after-lost-response.txt\0", $deletions);
        $boundary = 'reprint-lost-delete-response';
        $delete_body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . "X-Delete-Offset: 0\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($deletions) . "\r\n\r\n"
            . $deletions . "\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: delete-list\r\n"
            . 'X-Delete-Offset: ' . strlen($deletions) . "\r\n"
            . "X-Delete-Complete: 1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 0\r\n\r\n\r\n"
            . '--' . $boundary . "--\r\n";
        $this->sendPostAndDiscardResponse(
            $this->base_url . '&endpoint=push_upload&push_session_id=' . $state['push_session_id'],
            'multipart/mixed; boundary=' . $boundary,
            $delete_body
        );

        for (; $step < 70; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            $state = $this->loadActiveState($push_state_directory);
            if (is_array($state) && $state['phase'] === 'committing') {
                break;
            }
        }
        $this->assertIsArray($state);
        $this->sendPostAndDiscardResponse(
            $this->base_url . '&endpoint=push_commit&push_session_id=' . $state['push_session_id'],
            null,
            ''
        );

        for (; $step < 140; ++$step) {
            $result = $this->nextSenderStep($local_docroot, $fresh_local_index_path, $push_state_directory);
            $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
            if ($result['status'] !== 'continue') {
                break;
            }
        }
        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertFileDoesNotExist($this->docroot . '/delete-after-lost-response.txt');
        $this->assertNull($this->loadActiveState($push_state_directory));
    }

    /**
     * @return array{http_code:int,response:array<string,mixed>} Decoded raw HTTP response.
     */
    private function sendChunkedUploadRequest(
        string $base_url,
        string $push_session_id,
        string $boundary,
        string $multipart_body,
        string $trailing_bytes = ''
    ): array {
        $request_url = $base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $url = parse_url($request_url);
        $this->assertIsArray($url);
        $this->assertSame('http', $url['scheme'] ?? null);
        $host = (string) ( $url['host'] ?? '' );
        $port = (int) ( $url['port'] ?? 0 );
        $request_target = (string) ( $url['path'] ?? '/' ) . '?' . (string) ( $url['query'] ?? '' );
        $authentication_headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $request_url);
        $request_headers = '';
        foreach ($authentication_headers as $name => $value) {
            $request_headers .= $name . ': ' . $value . "\r\n";
        }
        $socket = stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $connect_error,
            $connect_error_message,
            3
        );
        $this->assertIsResource($socket, $connect_error_message);
        stream_set_timeout($socket, 5);
        $request_head = 'POST ' . $request_target . " HTTP/1.1\r\n"
            . 'Host: ' . $host . ':' . $port . "\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . 'Content-Type: multipart/mixed; boundary=' . $boundary . "\r\n"
            . "Connection: close\r\n"
            . $request_headers
            . "\r\n";
        $chunked_body = dechex(strlen($multipart_body)) . "\r\n"
            . $multipart_body . "\r\n";
        if ($trailing_bytes !== '') {
            $chunked_body .= dechex(strlen($trailing_bytes)) . "\r\n"
                . $trailing_bytes . "\r\n";
        }
        $request = $request_head . $chunked_body . "0\r\n\r\n";
        $request_bytes = strlen($request);
        $written_bytes = 0;
        while ($written_bytes < $request_bytes) {
            $written = fwrite($socket, substr($request, $written_bytes));
            $this->assertNotFalse($written);
            $this->assertGreaterThan(0, $written);
            $written_bytes += $written;
        }
        $raw_response = stream_get_contents($socket);
        fclose($socket);
        $this->assertIsString($raw_response);
        $status_matches = [];
        $this->assertSame(1, preg_match('/^HTTP\/1\.[01] ([0-9]{3}) /', $raw_response, $status_matches));
        $body_offset = strrpos($raw_response, "\r\n\r\n");
        $this->assertIsInt($body_offset);
        $response = json_decode(
            substr($raw_response, $body_offset + 4),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($response);

        return [
            'http_code' => (int) $status_matches[1],
            'response' => $response,
        ];
    }

    /** @return resource */
    private function startLockProcess(string $lock_path) {
        $ready_path = $this->root . '/lock-ready-' . bin2hex(random_bytes(4));
        $script = '$lock = fopen($argv[1], "c+b");'
            . 'if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(2); }'
            . 'file_put_contents($argv[2], "ready");'
            . 'sleep(30);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $lock_path, $ready_path],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($ready_path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($ready_path);
        unlink($ready_path);
        return $process;
    }

    /** @param resource $process */
    private function stopLockProcess($process): void
    {
        @proc_terminate($process, 9);
        proc_close($process);
    }

    /** @return array{0:resource,1:array<int,resource>,2:string} */
    private function startServer(int $post_max_bytes): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);
        $router = realpath(__DIR__ . '/fixtures/push-endpoint-router.php');
        $this->assertNotFalse($router);
        $environment = array_merge($_ENV, [
            'REPRINT_PUSH_TEST_SECRET_CONFIG' => $this->secret_configuration_path,
            'REPRINT_PUSH_TEST_AUTHORIZATION_CONFIG' => $this->push_authorization_configuration_path,
            'REPRINT_PUSH_TEST_MANAGED_PUSH_CONFIG' => $this->managed_push_configuration_path,
            'REPRINT_PUSH_TEST_CUSTOM_AUTH_CONFIG' => $this->custom_auth_configuration_path,
            'REPRINT_PUSH_TEST_ABSPATH' => $this->wordpress_root,
            'REPRINT_PUSH_TEST_DOCROOT_CONFIG' => $this->docroot_configuration_path,
            'REPRINT_PUSH_TEST_DIRECTORY_CONFIG' => $this->reprint_configuration_path,
            'REPRINT_PUSH_TEST_EXCLUDED_PATHS_CONFIG' => $this->excluded_paths_configuration_path,
        ]);
        $server_log_path = $this->root . '/server-' . $post_max_bytes . '-' . bin2hex(random_bytes(4)) . '.log';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $server_log_path, 'a'],
            2 => ['file', $server_log_path, 'a'],
        ];
        // PHP enforces post_max_size before the router can disable display_errors,
        // so disable it at startup to exercise the application-controlled JSON
        // 413 path under the documented deployment configuration. PHP or a web
        // server may otherwise reject a request before the endpoint can respond.
        // Send process output to a file so repeated suppressed filesystem
        // notices cannot fill a pipe and block the server response.
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'post_max_size=' . $post_max_bytes, '-S', $address, $router],
            $descriptors,
            $pipes,
            dirname($router),
            $environment
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $connect_error, $connect_error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return [$process, $pipes, 'http://' . $address . '/?reprint-api=1'];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $server_log = file_get_contents($server_log_path);
        $this->stopServer($process, $pipes);
        $this->fail('Push endpoint test server did not start: ' . $server_log);
    }

    /**
     * Builds the production sender options used at each lifecycle boundary.
     *
     * @return array<string,mixed> PushFilesSender start or resume options.
     */
    private function senderOptions(
        string $local_docroot,
        string $fresh_local_index_path,
        string $push_state_directory
    ): array
    {
        $this->writeDocrootConfiguration([
            'document_root' => $this->docroot,
            'maximum_part_bytes' => 64,
        ]);
        return [
            'docroot' => $local_docroot,
            'fresh_local_index_path' => $fresh_local_index_path,
            'push_state_directory' => $push_state_directory,
            'base_url' => $this->base_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 64,
            'request_sizer_options' => [
                'floor_bytes' => 2048,
                'start_bytes' => 2048,
                'max_bytes' => 2048,
            ],
            'connect_timeout' => 3,
            'stall_timeout' => 3,
            'response_timeout' => 5,
        ];
    }

    /**
     * Starts or resumes one sender, takes its next step, and explicitly closes it.
     *
     * @return array<string,mixed> Result of the one sender step.
     */
    private function nextSenderStep(
        string $local_docroot,
        string $fresh_local_index_path,
        string $push_state_directory
    ): array {
        $options = $this->senderOptions(
            $local_docroot,
            $fresh_local_index_path,
            $push_state_directory
        );
        $sender = is_file($push_state_directory . '/sender.json')
            ? PushFilesSender::resume($options)
            : PushFilesSender::start($options);
        try {
            $sender->next_step();
            return $this->senderResult($sender);
        } finally {
            $sender->close();
        }
    }

    /**
     * Reads the current sender outcome for focused assertions.
     *
     * @return array {
     *     Current sender outcome.
     *
     *     @type string      $status Current status.
     *     @type string      $phase  Current durable phase.
     *     @type string|null $reason Current classification, if any.
     *     @type string|null $detail Current explanation, if any.
     * }
     * @phpstan-return array{status:string,phase:string,reason:string|null,detail:string|null}
     */
    private function senderResult(PushFilesSender $sender): array
    {
        return [
            'status' => $sender->get_status(),
            'phase' => $sender->get_phase(),
            'reason' => $sender->get_reason(),
            'detail' => $sender->get_detail(),
        ];
    }

    /**
     * Runs sender steps until the workflow reaches a terminal result.
     *
     * @return array<string,mixed> Terminal sender result.
     */
    private function runSender(
        string $local_docroot,
        string $fresh_local_index_path,
        string $push_state_directory
    ): array {
        $options = $this->senderOptions(
            $local_docroot,
            $fresh_local_index_path,
            $push_state_directory
        );
        $sender = is_file($push_state_directory . '/sender.json')
            ? PushFilesSender::resume($options)
            : PushFilesSender::start($options);
        try {
            for ($step = 0; $step < 200; ++$step) {
                $has_more_steps = $sender->next_step();
                $result = $this->senderResult($sender);
                $this->assertNotSame('failed', $result['status'], (string) json_encode($result));
                $this->assertSame($result['status'] === 'continue', $has_more_steps);
                if (!$has_more_steps) {
                    return $result;
                }
            }
        } finally {
            $sender->close();
        }
        $this->fail('The high-level sender did not reach a terminal result in 200 steps.');
    }

    /**
     * Reads sender.json without adding a production accessor for tests.
     *
     * @return array<string,mixed>|null Decoded active state, or null when absent.
     */
    private function loadActiveState(string $push_state_directory): ?array
    {
        $state_path = $push_state_directory . '/sender.json';
        clearstatcache(true, $state_path);
        if (!is_file($state_path)) {
            return null;
        }
        $json = file_get_contents($state_path);
        $this->assertIsString($json);
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($state);
        return $state;
    }

    /**
     * Seeds PushPlan's local index at the previous successful push.
     */
    private function seedPreviousLocalIndex(string $push_state_directory, string $index_path): void
    {
        if (!is_dir($push_state_directory)) {
            mkdir($push_state_directory, 0700, true);
        }
        $this->assertTrue(copy($index_path, $push_state_directory . '/local_index_at_previous_push.jsonl'));
    }

    /**
     * Builds one index record from a real local filesystem value.
     *
     * @param 'file'|'dir'|'link' $type Index entry type.
     * @return array{0:int,1:int,2:'file'|'dir'|'link',3?:bool} Index test record.
     */
    private function indexEntry(
        string $absolute_path,
        string $type,
        ?bool $directory_is_empty = null
    ): array
    {
        $identity = lstat($absolute_path);
        $this->assertIsArray($identity);
        $entry = [ (int) $identity['ctime'], (int) $identity['size'], $type];
        if ($directory_is_empty !== null) {
            $entry[] = $directory_is_empty;
        }
        return $entry;
    }

    /**
     * @param resource|null $process PHP built-in server process.
     * @param array<int,resource> $pipes Open process pipes.
     */
    private function stopServer($process, array $pipes): void
    {
        if (!is_resource($process)) {
            return;
        }
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }

    private function newClient(string $secret, ?string $base_url = null): MultipartPushStreamClient
    {
        return new MultipartPushStreamClient([
            'base_url' => $base_url ?? $this->base_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client($secret),
            'chunk_bytes' => 4,
            'connect_timeout' => 3,
            'stall_timeout' => 3,
            'response_timeout' => 5,
        ]);
    }

    /** @param array<string,mixed> $configuration Trusted router configuration. */
    private function writeDocrootConfiguration(array $configuration): void
    {
        file_put_contents(
            $this->docroot_configuration_path,
            json_encode($configuration, JSON_THROW_ON_ERROR)
        );
    }

    /** @param list<string> $excluded_paths Raw document-root-relative paths. */
    private function writeExcludedPaths(array $excluded_paths): void
    {
        file_put_contents(
            $this->excluded_paths_configuration_path,
            json_encode(array_map('base64_encode', $excluded_paths), JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param list<array<string,mixed>> $parts Multipart parts sent in one request.
     * @return array<string,mixed> Classified client result.
     */
    private function sendUploadRequest(MultipartPushStreamClient $client, string $push_session_id, array $parts): array
    {
        $this->assertTrue($client->start_upload_request($push_session_id));
        foreach ($parts as $part) {
            $client->send_part($part);
        }
        return $client->finish_request();
    }

    /**
     * @param array<string,string> $parameters Signed query parameters.
     * @return array{http_code:int,headers:array<string,list<string>>,body:string} Raw HTTP result.
     */
    private function sendPushRequestWithHeaders(string $method, string $endpoint, array $parameters, string $secret): array
    {
        $query = http_build_query(
            array_merge(['endpoint' => $endpoint], $parameters),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $url = $this->base_url . '&' . $query;
        $authentication_headers = ( new Site_Export_HMAC_Client($secret) )->get_envelope_auth_headers($method, $url);
        $request_headers = [];
        foreach ($authentication_headers as $name => $value) {
            $request_headers[] = $name . ': ' . $value;
        }
        $response_headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $request_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl_handle, string $line) use (&$response_headers): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $response_headers[$name][] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $http_code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return [
            'http_code' => $http_code,
            'headers' => $response_headers,
            'body' => $body,
        ];
    }

    /** @param array<string,list<string>> $headers Response headers by lowercase name. */
    private function assertNoCacheHeaders(array $headers): void
    {
        $this->assertSame(
            ['no-store, no-cache, must-revalidate, max-age=0'],
            $headers['cache-control'] ?? []
        );
        $this->assertSame(['no-cache'], $headers['pragma'] ?? []);
        $this->assertSame(['0'], $headers['expires'] ?? []);
    }

    /**
     * @return array{http_code:int,body:string,response:array<string,mixed>}
     */
    private function requestPushEndpoint(
        ?string $secret,
        string $method,
        string $endpoint,
        string $push_session_id,
        ?string $body = null,
        ?string $content_type = null
    ): array {
        $url = $this->base_url
            . '&endpoint=' . rawurlencode($endpoint)
            . '&push_session_id=' . rawurlencode($push_session_id);
        $curl_headers = [];
        if ($content_type !== null) {
            $curl_headers[] = 'Content-Type: ' . $content_type;
        }
        if ($secret !== null) {
            $headers = ( new Site_Export_HMAC_Client($secret) )->get_envelope_auth_headers($method, $url);
            foreach ($headers as $name => $value) {
                $curl_headers[] = $name . ': ' . $value;
            }
        }

        $handle = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $response_body = curl_exec($handle);
        $this->assertIsString($response_body);
        $http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return [
            'http_code' => $http_code,
            'body' => $response_body,
            'response' => json_decode($response_body, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Sends one complete signed POST request and discards its response.
     *
     * Closing the socket immediately after the terminating transfer chunk
     * reproduces a sender that cannot know whether the target completed the
     * operation. The caller must reconcile from target state afterward.
     *
     * @param string $url Exact push endpoint URL to authenticate and request.
     * @param string|null $content_type Request Content-Type, or null when the
     *     endpoint has no body format.
     * @param string $body Decoded HTTP entity body.
     */
    private function sendPostAndDiscardResponse(string $url, ?string $content_type, string $body): void
    {
        $target = parse_url($url);
        $this->assertIsArray($target);
        $this->assertIsString($target['host'] ?? null);
        $this->assertIsInt($target['port'] ?? null);
        $authentication_headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $connection = stream_socket_client(
            'tcp://' . $target['host'] . ':' . $target['port'],
            $error_number,
            $error_message,
            3
        );
        $this->assertIsResource($connection, $error_message);
        $request = 'POST ' . $target['path'] . '?' . $target['query'] . " HTTP/1.1\r\n"
            . 'Host: ' . $target['host'] . ':' . $target['port'] . "\r\n"
            . "Transfer-Encoding: chunked\r\nConnection: close\r\n";
        if ($content_type !== null) {
            $request .= 'Content-Type: ' . $content_type . "\r\n";
        }
        foreach ($authentication_headers as $header_name => $header_value) {
            $request .= $header_name . ': ' . $header_value . "\r\n";
        }
        $request .= "\r\n";
        if ($body !== '') {
            $request .= dechex(strlen($body)) . "\r\n" . $body . "\r\n";
        }
        $request .= "0\r\n\r\n";
        $this->assertSame(strlen($request), fwrite($connection, $request));
        fclose($connection);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
    /**
     * Writes a path-sorted current index or enriched baseline JSONL.
     *
     * @param array<string,array{0:int,1:int,2:'file'|'dir'|'link',3?:bool}> $entries
     *     Path to `[ctime, size, type, optional directory emptiness]` records.
     */
    private function writeIndex(string $path, array $entries): void
    {
        uksort($entries, 'strcmp');
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        foreach ($entries as $entry_path => $index_entry) {
            [$ctime, $size, $type] = $index_entry;
            $record = [
                'path' => base64_encode($entry_path),
                'ctime' => $ctime,
                'size' => $size,
                'type' => $type,
            ];
            if (array_key_exists(3, $index_entry)) {
                $record['empty'] = $index_entry[3];
            }
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $this->assertSame(strlen($line), fwrite($handle, $line));
        }
        fclose($handle);
    }
}
