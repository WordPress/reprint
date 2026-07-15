<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * End-to-end coverage intentionally uses php -S, the real endpoint router,
 * the CLI, and curl. A fake transport would miss the streaming and signed URI
 * boundary this feature exists to protect.
 */
final class MultipartPushTest extends TestCase {

    private const SECRET = 'multipart-push-e2e-secret';

    private static string $server_root;
    private static string $router_path;
    private static string $config_path;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process;

    private string $case_root;
    private string $source;
    private string $target;
    private string $storage;
    private string $state;
    private string $request_log;
    private ?string $other_filesystem_storage = null;
    private int $max_frame_bytes = 128;
    private ?string $drop_upload_response_marker = null;
    private ?string $drop_delete_completion_response_marker = null;
    private ?string $inflate_delete_response_marker = null;
    private ?string $negative_delete_status_marker = null;
    private ?string $pause_status_response_marker = null;
    private ?string $resume_status_response_marker = null;
    private ?string $capture_upload_body_path = null;
    private ?string $pause_after_upload_marker = null;
    private ?string $resume_upload_response_marker = null;
    private bool $return_paused_upload_response = false;
    private ?string $reject_upload_marker = null;
    private ?string $drop_discard_response_marker = null;
    private bool $make_checkpoint_unremovable_after_discard = false;
    /** @var array<string,int> */
    private array $busy_response_limits = [];
    private string $server_secret = self::SECRET;
    private ?string $control_fault = null;
    private int $too_large_upload_responses = 0;
    /** @var string[] */
    private array $apply_protected_paths = [];

    public static function setUpBeforeClass(): void {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('Multipart push requires the curl extension.');
        }
        $suffix = bin2hex(random_bytes(8));
        self::$server_root = sys_get_temp_dir() . '/multipart-push-http-' . $suffix;
        self::$router_path = self::$server_root . '/router.php';
        self::$config_path = self::$server_root . '/config.json';
        mkdir(self::$server_root, 0700, true);
        self::write_router();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::fail('Could not reserve a local HTTP port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $address, ':'), 1);
        self::$base_url = 'http://127.0.0.1:' . $port . '/?reprint-api=1';
        self::$server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', self::$server_root, self::$router_path],
            [0 => ['pipe', 'r'], 1 => ['file', self::$server_root . '/server.log', 'a'], 2 => ['file', self::$server_root . '/server.log', 'a']],
            $pipes,
            self::$server_root
        );
        if (!is_resource(self::$server_process)) {
            self::fail('Could not start the local multipart endpoint server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            if (@file_get_contents('http://127.0.0.1:' . $port . '/__ping') === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::tearDownAfterClass();
        self::fail('The local multipart endpoint server did not start.');
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$server_process)) {
            proc_terminate(self::$server_process);
            proc_close(self::$server_process);
            self::$server_process = null;
        }
        if (isset(self::$server_root)) {
            self::remove_tree(self::$server_root);
        }
    }

    protected function setUp(): void {
        $this->case_root = self::$server_root . '/case-' . bin2hex(random_bytes(8));
        $this->source = $this->case_root . '/source';
        $this->target = $this->case_root . '/target';
        $this->storage = $this->case_root . '/storage';
        $this->state = $this->case_root . '/state';
        $this->request_log = $this->case_root . '/requests.jsonl';
        mkdir($this->source, 0700, true);
        mkdir($this->target, 0700, true);
        mkdir($this->storage, 0700, true);
        $this->configure_server();
    }

    protected function tearDown(): void {
        self::remove_tree($this->case_root);
        if ($this->other_filesystem_storage !== null) {
            self::remove_tree($this->other_filesystem_storage);
        }
    }

    public function testSharedSnapshotWriterKeepsStructuralMarkersOutOfTheUploadPlan(): void {
        $this->write_source('wp-content/plugins/demo/plugin.php', '<?php');
        mkdir($this->source . '/empty', 0700, true);
        $snapshot = $this->state . '/pulled-local-files.jsonl';
        mkdir($this->state, 0700, true);

        \MultipartPush::write_local_snapshot($this->source, $snapshot, $this->state);

        $entries = array_map(static function (string $line): array {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entry['path'] = base64_decode($entry['path'], true);
            return $entry;
        }, file($snapshot, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $types = [];
        foreach ($entries as $entry) {
            $types[$entry['path']] = $entry['type'];
        }
        $this->assertSame('tree-directory', $types['wp-content'] ?? null);
        $this->assertSame('tree-directory', $types['wp-content/plugins'] ?? null);
        $this->assertSame('file', $types['wp-content/plugins/demo/plugin.php'] ?? null);
        $this->assertSame('directory', $types['empty'] ?? null);

        $journal = new \PushJournal($this->state, self::$base_url, $this->source);
        $journal->diff_local_files($snapshot);
        $plannedTypes = array_map(static function (string $line): ?string {
            $entry = json_decode($line, true);
            return is_array($entry) ? ( $entry['type'] ?? null ) : null;
        }, file($journal->local_paths_to_push, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertNotContains('tree-directory', $plannedTypes);
    }

    public function testNakedFullRootPushExplainsHowToSeedTheBaselineAfterProtectedRejection(): void {
        $this->apply_protected_paths = ['wp-content/plugins/site-export'];
        $this->configure_server();
        $this->write_source('wp-content/plugins/site-export/index.php', 'sender copy');
        mkdir($this->target . '/wp-content/plugins/site-export', 0700, true);
        file_put_contents($this->target . '/wp-content/plugins/site-export/index.php', 'live exporter');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('no compatible full-root baseline', $result['stderr']);
        $this->assertStringContainsString('unfiltered files-pull', $result['stderr']);
        $this->assertStringContainsString('same --state-dir', $result['stderr']);
        $this->assertSame('live exporter', file_get_contents($this->target . '/wp-content/plugins/site-export/index.php'));
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testBusySessionCreationRetriesUntilTheTargetBecomesAvailable(): void {
        $this->busy_response_limits = ['staged_session_create' => 2];
        $this->configure_server();
        $this->write_source('created-after-contention.txt', 'created');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('created', file_get_contents($this->target . '/created-after-contention.txt'));
        $this->assertSame(3, $this->endpoint_count('staged_session_create'));
    }

    public function testBusyUploadRetriesUntilTheTargetBecomesAvailable(): void {
        $this->busy_response_limits = ['staged_session_upload' => 2];
        $this->configure_server();
        $this->write_source('uploaded-after-contention.txt', 'uploaded');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('uploaded', file_get_contents($this->target . '/uploaded-after-contention.txt'));
        $this->assertGreaterThanOrEqual(4, $this->endpoint_count('staged_session_upload'));
    }

    public function testBusyCommitRetriesUntilTheTargetBecomesAvailable(): void {
        $this->busy_response_limits = ['staged_session_commit' => 2];
        $this->configure_server();
        $this->write_source('committed-after-contention.txt', 'committed');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('committed', file_get_contents($this->target . '/committed-after-contention.txt'));
        $this->assertGreaterThanOrEqual(3, $this->endpoint_count('staged_session_commit'));
    }

    public function testBusyDiscardRetriesUntilTheTargetBecomesAvailable(): void {
        $this->busy_response_limits = ['staged_session_discard' => 2];
        $this->configure_server();
        $this->write_source('cleaned-after-contention.txt', 'cleaned');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('cleaned', file_get_contents($this->target . '/cleaned-after-contention.txt'));
        $this->assertSame(3, $this->endpoint_count('staged_session_discard'));
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);
    }

    public function testPersistentBusyUploadFailsBoundedlyAndTheNextProcessResumes(): void {
        $this->busy_response_limits = ['staged_session_upload' => 6];
        $this->configure_server();
        $this->write_source('resumed-after-contention.txt', 'resumed');

        $failed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $failed['exit_code']);
        $this->assertStringContainsString('remained busy after 5 attempts', $failed['stderr']);
        $this->assertCount(1, glob($this->state . '/push/*/session.json') ?: []);
        $this->assertFileDoesNotExist($this->target . '/resumed-after-contention.txt');

        $this->busy_response_limits = [];
        $this->configure_server();
        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('resumed', file_get_contents($this->target . '/resumed-after-contention.txt'));
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);
    }

    public function testAuthenticationFailureIsTerminalWithoutRetrying(): void {
        $this->server_secret = 'different-target-secret';
        $this->configure_server();
        $this->write_source('must-not-authenticate.txt', 'no');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('authentication', strtolower($result['stderr']));
        $this->assertSame(1, $this->endpoint_count('staged_session_create'));
        $this->assertFileDoesNotExist($this->target . '/must-not-authenticate.txt');
    }

    public function testControlRedirectIsTerminalWithTargetGuidance(): void {
        $this->control_fault = 'redirect';
        $this->configure_server();
        $this->write_source('must-not-redirect.txt', 'no');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('redirected to', $result['stderr']);
        $this->assertStringContainsString('Use that address as the push base_url', $result['stderr']);
        $this->assertSame(1, $this->endpoint_count('staged_session_create'));
        $this->assertFileDoesNotExist($this->target . '/must-not-redirect.txt');
    }

    public function testMalformedControlResponseIsTerminalWithoutRetrying(): void {
        $this->control_fault = 'malformed';
        $this->configure_server();
        $this->write_source('must-not-accept-malformed.txt', 'no');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('invalid JSON', $result['stderr']);
        $this->assertSame(1, $this->endpoint_count('staged_session_create'));
        $this->assertFileDoesNotExist($this->target . '/must-not-accept-malformed.txt');
    }

    public function testHttp413ShrinksTheRequestAndResumesFromTargetStatus(): void {
        $this->too_large_upload_responses = 1;
        $this->configure_server();
        $this->write_source('accepted-after-413.txt', 'right-sized retry');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('right-sized retry', file_get_contents($this->target . '/accepted-after-413.txt'));
        $this->assertGreaterThanOrEqual(3, $this->endpoint_count('staged_session_upload'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
    }

    public function testOffsetGapResumesFromTheTargetConfirmedFileCursor(): void {
        $this->reject_upload_marker = $this->case_root . '/rejected-before-offset-gap';
        $this->configure_server();
        $this->write_source('offset-gap.bin', 'target starts with no bytes');

        $interrupted = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $interrupted['exit_code']);
        $checkpoints = glob($this->state . '/push/*/session.json') ?: [];
        $this->assertCount(1, $checkpoints);
        $checkpoint = json_decode( (string) file_get_contents($checkpoints[0]), true);
        $this->assertIsArray($checkpoint);
        $this->assertSame(base64_encode('offset-gap.bin'), $checkpoint['current']['path_b64'] ?? null);

        // Simulate a sender checkpoint ahead of the target. The rejection must
        // reconcile from status rather than trusting this attempted cursor.
        $checkpoint['current']['accepted_bytes'] = 1;
        file_put_contents($checkpoints[0], json_encode($checkpoint));
        $status_requests_before_resume = $this->endpoint_count('staged_session_status');

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('target starts with no bytes', file_get_contents($this->target . '/offset-gap.bin'));
        $this->assertGreaterThan($status_requests_before_resume, $this->endpoint_count('staged_session_status'));
    }

    public function testOffsetGapResumesFromTheTargetConfirmedDeleteCursor(): void {
        $this->write_source('delete-offset-gap.txt', 'delete this');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);

        unlink($this->source . '/delete-offset-gap.txt');
        $this->reject_upload_marker = $this->case_root . '/rejected-before-delete-offset-gap';
        $this->configure_server();
        $interrupted = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $interrupted['exit_code']);
        $checkpoints = glob($this->state . '/push/*/session.json') ?: [];
        $this->assertCount(1, $checkpoints);
        $checkpoint = json_decode( (string) file_get_contents($checkpoints[0]), true);
        $this->assertIsArray($checkpoint);

        // Simulate a sender checkpoint one byte ahead of an empty target
        // delete stream. Status must rewind the one local/wire cursor.
        $checkpoint['delete_offset'] = 1;
        file_put_contents($checkpoints[0], json_encode($checkpoint));
        $status_requests_before_resume = $this->endpoint_count('staged_session_status');

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertFileDoesNotExist($this->target . '/delete-offset-gap.txt');
        $this->assertGreaterThan($status_requests_before_resume, $this->endpoint_count('staged_session_status'));
    }

    public function testCheckpointAtDeleteEofReconcilesBeforeCompletion(): void {
        $path = 'delete-completion-offset-gap.txt';
        $this->write_source($path, 'delete this too');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);

        unlink($this->source . '/' . $path);
        $this->reject_upload_marker = $this->case_root . '/rejected-before-delete-completion-offset-gap';
        $this->configure_server();
        $interrupted = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $interrupted['exit_code']);
        $checkpoints = glob($this->state . '/push/*/session.json') ?: [];
        $delete_streams = glob($this->state . '/push/*/local-delete-stream.bin') ?: [];
        $this->assertCount(1, $checkpoints);
        $this->assertCount(1, $delete_streams);
        $checkpoint = json_decode( (string) file_get_contents($checkpoints[0]), true);
        $this->assertIsArray($checkpoint);

        // Simulate a checkpoint which falsely says the complete delete stream
        // reached the target. Signed status must rewind to zero and upload the
        // pending raw bytes before completion can be declared.
        $checkpoint['delete_offset'] = filesize($delete_streams[0]);
        file_put_contents($checkpoints[0], json_encode($checkpoint));

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertFileDoesNotExist($this->target . '/' . $path);
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
    }

    public function testCliPushDeliversInitialAndDeltaTreesThroughTheRealRouter(): void {
        $this->write_source('large.bin', str_repeat('payload-', 80));
        $this->write_source('delete-on-delta.txt', 'delete me');
        $this->write_source('swap', 'first a file');
        $this->write_source('becomes-tree', 'first a file');
        $this->write_source('vanished-tree/child.txt', 'remove this complete tree');
        $this->write_source('wp-content/plugins/demo/new.php', 'first plugin version');
        $this->write_source('empty-dir/.keep', '');
        unlink($this->source . '/empty-dir/.keep');
        $has_link = @symlink('large.bin', $this->source . '/current-link');
        mkdir($this->target . '/wp-content/plugins/demo', 0700, true);
        file_put_contents($this->target . '/wp-content/plugins/demo/old.php', 'preserved existing file');

        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $this->assertSame('complete', $first['json']['status'] ?? null);
        $this->assertSame(str_repeat('payload-', 80), file_get_contents($this->target . '/large.bin'));
        $this->assertSame('first plugin version', file_get_contents($this->target . '/wp-content/plugins/demo/new.php'));
        $this->assertSame('preserved existing file', file_get_contents($this->target . '/wp-content/plugins/demo/old.php'));
        $this->assertTrue(is_dir($this->target . '/empty-dir'));
        if ($has_link) {
            $this->assertSame('large.bin', readlink($this->target . '/current-link'));
        }
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_upload'));

        file_put_contents($this->source . '/large.bin', 'new bytes after the baseline');
        unlink($this->source . '/delete-on-delta.txt');
        unlink($this->source . '/swap');
        mkdir($this->source . '/swap', 0700, true);
        unlink($this->source . '/becomes-tree');
        mkdir($this->source . '/becomes-tree', 0700, true);
        file_put_contents($this->source . '/becomes-tree/child.txt', 'a file became a complete directory tree');
        self::remove_tree($this->source . '/vanished-tree');
        file_put_contents($this->source . '/wp-content/plugins/demo/new.php', 'second plugin version');
        if ($has_link) {
            unlink($this->source . '/current-link');
            symlink('wp-content/plugins/demo/new.php', $this->source . '/current-link');
        }

        $second = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertSame('complete', $second['json']['status'] ?? null);
        $this->assertSame('new bytes after the baseline', file_get_contents($this->target . '/large.bin'));
        $this->assertFileDoesNotExist($this->target . '/delete-on-delta.txt');
        $this->assertTrue(is_dir($this->target . '/swap'));
        $this->assertSame('a file became a complete directory tree', file_get_contents($this->target . '/becomes-tree/child.txt'));
        $this->assertFileDoesNotExist($this->target . '/vanished-tree');
        $this->assertSame('second plugin version', file_get_contents($this->target . '/wp-content/plugins/demo/new.php'));
        if ($has_link) {
            $this->assertSame('wp-content/plugins/demo/new.php', readlink($this->target . '/current-link'));
        }
        $this->assertFileDoesNotExist($this->target . '/.maintenance');

        $status = $this->run_cli('push-status');
        $this->assertSame(0, $status['exit_code'], $status['stderr']);
        $this->assertSame('no_active_push', $status['json']['status'] ?? null);
    }

    public function testZeroDeltaPushCompletesWithoutContactingTarget(): void {
        $this->write_source('unchanged.txt', 'same bytes');
        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $requests_before = file_get_contents($this->request_log);
        $this->assertIsString($requests_before);

        $second = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertSame('complete', $second['json']['status'] ?? null);
        $this->assertSame(0, $second['json']['changed'] ?? null);
        $this->assertSame(0, $second['json']['deleted'] ?? null);
        $this->assertSame($requests_before, file_get_contents($this->request_log));
        $this->assertFileDoesNotExist($this->target . '/.maintenance');
    }

    public function testPushMakesTheTargetTreeExactlyMatchAStandaloneSourceSnapshot(): void {
        $this->write_source('ordinary.txt', 'first contents');
        $this->write_source('zero.bin', '');
        $this->write_source('directory-to-file/old.txt', 'old tree');
        $this->write_source('directory-to-link/old.txt', 'old tree');
        $this->write_source('file-to-link', 'old file');
        $this->write_source('wp-content/plugins/directory-to-file/old.php', 'old plugin tree');
        $this->write_source('wp-content/plugins/directory-to-link/old.php', 'old plugin tree');
        $this->write_source('wp-content/plugins/file-to-directory', 'old plugin file');
        $this->write_source('empty/.placeholder', '');
        unlink($this->source . '/empty/.placeholder');
        $has_link = @symlink('ordinary.txt', $this->source . '/link');
        if ($has_link) {
            symlink('ordinary.txt', $this->source . '/link-to-directory');
        }

        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $this->assertSame($this->lstat_tree($this->source), $this->lstat_tree($this->target));

        unlink($this->source . '/ordinary.txt');
        mkdir($this->source . '/ordinary.txt', 0700, true);
        file_put_contents($this->source . '/ordinary.txt/nested.php', 'replacement tree');
        unlink($this->source . '/zero.bin');
        $this->write_source('added.txt', 'new file with a different size');
        self::remove_tree($this->source . '/directory-to-file');
        $this->write_source('directory-to-file', 'a directory became a file');
        self::remove_tree($this->source . '/wp-content/plugins/directory-to-file');
        $this->write_source('wp-content/plugins/directory-to-file', 'a plugin directory became a file');
        unlink($this->source . '/wp-content/plugins/file-to-directory');
        $this->write_source('wp-content/plugins/file-to-directory/new.php', 'a plugin file became a directory');
        if ($has_link) {
            unlink($this->source . '/link');
            $this->write_source('link', 'a symlink became a file');
            self::remove_tree($this->source . '/directory-to-link');
            symlink('added.txt', $this->source . '/directory-to-link');
            unlink($this->source . '/file-to-link');
            symlink('added.txt', $this->source . '/file-to-link');
            unlink($this->source . '/link-to-directory');
            mkdir($this->source . '/link-to-directory', 0700);
            file_put_contents($this->source . '/link-to-directory/new.txt', 'a link became a directory');
            self::remove_tree($this->source . '/wp-content/plugins/directory-to-link');
            symlink('../../../added.txt', $this->source . '/wp-content/plugins/directory-to-link');
        }

        $second = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertSame($this->lstat_tree($this->source), $this->lstat_tree($this->target));
    }

    public function testDeleteUploadPreservesArbitraryPathBytes(): void {
        $relative_path = "raw-\xff-name";
        $source_path = $this->source . '/' . $relative_path;
        if (@file_put_contents($source_path, 'delete me') === false) {
            if (PHP_OS_FAMILY === 'Linux') {
                self::fail('The Linux test filesystem rejected an arbitrary-byte filename.');
            }
            $this->markTestSkipped('This filesystem requires UTF-8 filenames.');
        }

        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        $this->assertSame('delete me', file_get_contents($this->target . '/' . $relative_path));
        unlink($source_path);

        $deleted = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $deleted['exit_code'], $deleted['stderr']);
        $this->assertFalse(file_exists($this->target . '/' . $relative_path));
        $delete_streams = glob($this->state . '/push/*/local-delete-stream.bin') ?: [];
        $this->assertCount(1, $delete_streams);
        $this->assertSame($relative_path . "\0", file_get_contents($delete_streams[0]));
    }

    public function testPushUsesTargetLocalModesAndIgnoresModeOnlyChanges(): void {
        $this->write_source('mode-tree/child.txt', 'child');
        chmod($this->source . '/mode-tree', 0751);

        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $target_mode = fileperms($this->target . '/mode-tree') & 07777;
        $this->assertSame('child', file_get_contents($this->target . '/mode-tree/child.txt'));

        $baseline = glob($this->state . '/push/*/last-sync-local-files.jsonl') ?: [];
        $this->assertCount(1, $baseline);
        $this->assertStringNotContainsString('"mode"', (string) file_get_contents($baseline[0]));

        chmod($this->source . '/mode-tree', 0711);
        $second = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        clearstatcache(true, $this->target . '/mode-tree');
        $this->assertSame($target_mode, fileperms($this->target . '/mode-tree') & 07777);
        $this->assertSame('child', file_get_contents($this->target . '/mode-tree/child.txt'));
    }

    public function testLargeFileUsesSeveralMultipartRequestsBeforeItIsPromoted(): void {
        $contents = str_repeat("part\0", 4000);
        $this->write_source('multipart-large.bin', $contents);

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertSame($contents, file_get_contents($this->target . '/multipart-large.bin'));
        $this->assertGreaterThanOrEqual(2, $this->endpoint_count('staged_session_upload'));
    }

    public function testDeleteRecordsSpanPartsAndShareRequests(): void {
        $first_path = str_repeat('a', 180);
        $second_path = str_repeat('b', 180);
        $this->write_source($first_path, 'first');
        $this->write_source($second_path, 'second');
        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $requests_before_delete = $this->endpoint_count('staged_session_upload');

        unlink($this->source . '/' . $first_path);
        unlink($this->source . '/' . $second_path);
        $second = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertFileDoesNotExist($this->target . '/' . $first_path);
        $this->assertFileDoesNotExist($this->target . '/' . $second_path);
        // Four bounded data parts share one request; the second request carries
        // only the explicit delete-stream completion declaration.
        $this->assertSame(2, $this->endpoint_count('staged_session_upload') - $requests_before_delete);
    }

    public function testLostBatchedDeleteResponseResumesFromTheTargetRawSize(): void {
        $first_path = str_repeat('c', 180);
        $second_path = str_repeat('d', 180);
        $this->write_source($first_path, 'first');
        $this->write_source($second_path, 'second');
        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);

        $this->drop_upload_response_marker = $this->case_root . '/dropped-delete-response';
        $this->configure_server();
        $status_requests_before_delete = $this->endpoint_count('staged_session_status');
        unlink($this->source . '/' . $first_path);
        unlink($this->source . '/' . $second_path);

        $second = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertFileExists($this->drop_upload_response_marker);
        $this->assertFileDoesNotExist($this->target . '/' . $first_path);
        $this->assertFileDoesNotExist($this->target . '/' . $second_path);
        $this->assertGreaterThan(
            $status_requests_before_delete,
            $this->endpoint_count('staged_session_status')
        );
    }

    public function testSenderDeathAfterTargetAcceptsDeletesResumesFromSignedStatus(): void {
        $this->write_source('delete-before-sender-death.txt', 'delete me');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        unlink($this->source . '/delete-before-sender-death.txt');

        $this->pause_after_upload_marker = $this->case_root . '/delete-accepted-before-sender-death';
        $this->resume_upload_response_marker = $this->case_root . '/release-dead-sender-response';
        $this->configure_server();
        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $process = proc_open([
            PHP_BINARY,
            $entry,
            'push',
            self::$base_url,
            '--state-dir=' . $this->state,
            '--secret=' . self::SECRET,
            '--allow-http',
            '--source-root=' . $this->source,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (is_file($this->pause_after_upload_marker)) {
                break;
            }
            usleep(100000);
        }
        $this->assertFileExists($this->pause_after_upload_marker, 'The target never accepted the delete stream before sender interruption.');
        $target_stream_paths = glob($this->storage . '/apply-sessions/[a-f0-9]*/work/deletes') ?: [];
        $this->assertCount(1, $target_stream_paths);
        $this->assertSame("delete-before-sender-death.txt\0", file_get_contents($target_stream_paths[0]));
        $checkpoint_paths = glob($this->state . '/push/*/session.json') ?: [];
        $this->assertCount(1, $checkpoint_paths);
        $checkpoint = json_decode( (string) file_get_contents($checkpoint_paths[0]), true);
        $this->assertIsArray($checkpoint);
        $this->assertSame(0, $checkpoint['delete_offset'] ?? null);

        $process_status = proc_get_status($process);
        $this->assertTrue($process_status['running'] ?? false, 'The sender exited before it could be killed after target acceptance.');
        $this->assertTrue(proc_terminate($process, 9));
        file_put_contents($this->resume_upload_response_marker, "release\n");
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertFileDoesNotExist($this->target . '/delete-before-sender-death.txt');
    }

    public function testDeleteUploadDeclaresActualLengthAfterAShortReadAndContinuesAtThatOffset(): void {
        $interrupted = $this->begin_rejected_delete_push();
        $padding_path = 'missing-short-read-root';
        $padding_segment = str_repeat('p', 200);
        $padding_path_bytes = strlen($padding_path);
        $padding_segment_bytes = strlen($padding_segment);
        while ($padding_path_bytes + $padding_segment_bytes + 1 <= 4090) {
            $padding_path .= '/' . $padding_segment;
            $padding_path_bytes += $padding_segment_bytes + 1;
        }
        $padding_record = $padding_path . "\0";
        // A 1 MiB request fits three 256 KiB payloads plus 261432 bytes after
        // reserving their MIME headers and the close. Ending one byte earlier
        // makes the fourth fread() short while still exhausting this request.
        $short_stream_size = 1047863;
        $padding_records = intdiv(
            $short_stream_size + strlen($padding_record) - strlen($interrupted['local_stream']),
            strlen($padding_record)
        ) + 1;
        $original_stream = $interrupted['local_stream'] . str_repeat($padding_record, $padding_records);
        $this->assertGreaterThan($short_stream_size, strlen($original_stream));
        file_put_contents($interrupted['local_stream_path'], $original_stream);

        $checkpoint = json_decode( (string) file_get_contents($interrupted['checkpoint_path']), true);
        $this->assertIsArray($checkpoint);
        $checkpoint['delete_offset'] = 0;
        $checkpoint['max_frame_bytes'] = 2 * 1024 * 1024;
        $checkpoint['sizer'] = [
            'request_body_bytes' => 1024 * 1024,
            'ceiling_bytes' => 1024 * 1024,
            'growth_holdoff_remaining' => 0,
        ];
        file_put_contents($interrupted['checkpoint_path'], json_encode($checkpoint, JSON_UNESCAPED_SLASHES));

        $this->reject_upload_marker = null;
        $this->max_frame_bytes = 2 * 1024 * 1024;
        $this->pause_status_response_marker = $this->case_root . '/delete-status-paused';
        $this->resume_status_response_marker = $this->case_root . '/release-delete-status';
        $this->pause_after_upload_marker = $this->case_root . '/short-delete-upload-accepted';
        $this->resume_upload_response_marker = $this->case_root . '/release-short-delete-response';
        $this->return_paused_upload_response = true;
        $this->configure_server();

        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $process = proc_open([
            PHP_BINARY,
            $entry,
            'push',
            self::$base_url,
            '--state-dir=' . $this->state,
            '--secret=' . self::SECRET,
            '--allow-http',
            '--source-root=' . $this->source,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (is_file($this->pause_status_response_marker)) {
                break;
            }
            usleep(100000);
        }
        $this->assertFileExists($this->pause_status_response_marker, 'The sender did not pause after sampling the local delete-stream size.');
        $status = json_decode( (string) file_get_contents($this->pause_status_response_marker), true);
        $this->assertIsArray($status);
        $this->assertSame(0, $status['delete_bytes'] ?? null);

        $stream_identity = lstat($interrupted['local_stream_path']);
        $this->assertIsArray($stream_identity);
        $stream_handle = fopen($interrupted['local_stream_path'], 'r+b');
        $this->assertIsResource($stream_handle);
        $this->assertTrue(ftruncate($stream_handle, $short_stream_size));
        $this->assertTrue(fflush($stream_handle));
        fclose($stream_handle);
        clearstatcache(true, $interrupted['local_stream_path']);
        $this->assertSame($short_stream_size, filesize($interrupted['local_stream_path']));
        file_put_contents($this->resume_status_response_marker, "release\n");

        for ($attempt = 0; $attempt < 150; ++$attempt) {
            if (is_file($this->pause_after_upload_marker)) {
                break;
            }
            usleep(100000);
        }
        $this->assertFileExists($this->pause_after_upload_marker, 'The target did not accept the short-read delete request.');
        $response = json_decode( (string) file_get_contents($this->pause_after_upload_marker), true);
        $this->assertIsArray($response);
        $accepted = is_array($response['accepted'] ?? null) ? $response['accepted'] : [];
        $this->assertSame(
            [262144, 524288, 786432, $short_stream_size],
            array_map(static function (array $confirmation): int {
                return (int) ( $confirmation['accepted_bytes'] ?? -1 );
            }, $accepted)
        );
        $this->assertSame(substr($original_stream, 0, $short_stream_size), file_get_contents($interrupted['target_stream_path']));

        $checkpoint = json_decode( (string) file_get_contents($interrupted['checkpoint_path']), true);
        $this->assertIsArray($checkpoint);
        $this->assertSame(0, $checkpoint['delete_offset'] ?? null);
        $this->assertArrayNotHasKey('delete_plan_offset', $checkpoint);
        $this->assertArrayNotHasKey('delete_record_offset', $checkpoint);

        $stream_handle = fopen($interrupted['local_stream_path'], 'r+b');
        $this->assertIsResource($stream_handle);
        $this->assertTrue(ftruncate($stream_handle, 0));
        $written_bytes = 0;
        $original_stream_size = strlen($original_stream);
        while ($written_bytes < $original_stream_size) {
            $written = fwrite($stream_handle, substr($original_stream, $written_bytes));
            if (!is_int($written) || $written === 0) {
                self::fail('Could not restore the local delete stream in place after the short read.');
            }
            $written_bytes += $written;
        }
        $this->assertTrue(fflush($stream_handle));
        fclose($stream_handle);
        clearstatcache(true, $interrupted['local_stream_path']);
        $restored_identity = lstat($interrupted['local_stream_path']);
        $this->assertIsArray($restored_identity);
        $this->assertSame($stream_identity['ino'] ?? null, $restored_identity['ino'] ?? null);
        $this->assertSame($original_stream_size, filesize($interrupted['local_stream_path']));
        file_put_contents($this->resume_upload_response_marker, "release\n");

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $lines = array_values(array_filter(preg_split('/\R/', trim( (string) $stdout)) ?: [], static function (string $line): bool {
            return $line !== '';
        }));
        $result = $lines === [] ? null : json_decode( (string) end($lines), true);

        $this->assertSame(0, $exit_code, (string) $stderr);
        $this->assertSame('complete', is_array($result) ? ( $result['status'] ?? null ) : null);
        $this->assertFileDoesNotExist($this->target . '/first-delete.txt');
        $this->assertFileDoesNotExist($this->target . '/second-delete.txt');
    }

    public function testProcessRestartSeeksToATargetOffsetInTheMiddleOfADeleteRecord(): void {
        $interrupted = $this->begin_rejected_delete_push();
        file_put_contents($interrupted['target_stream_path'], substr($interrupted['local_stream'], 0, 3));
        $request_lines_before = count(file($this->request_log, FILE_IGNORE_NEW_LINES) ?: []);
        $this->reject_upload_marker = null;
        $this->capture_upload_body_path = $this->case_root . '/mid-record-resume-body';
        $this->configure_server();

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertStringContainsString(
            "X-Chunk-Type: delete-list\r\nX-Delete-Offset: 3\r\n",
            (string) file_get_contents($this->capture_upload_body_path)
        );
        $new_requests = array_slice(file($this->request_log, FILE_IGNORE_NEW_LINES) ?: [], $request_lines_before);
        $new_endpoints = array_map(static function (string $line): ?string {
            $request = json_decode($line, true);
            return is_array($request) && is_string($request['endpoint'] ?? null) ? $request['endpoint'] : null;
        }, $new_requests);
        $this->assertSame(['staged_session_status', 'staged_session_upload'], array_slice($new_endpoints, 0, 2));
        $this->assertFileDoesNotExist($this->target . '/first-delete.txt');
        $this->assertFileDoesNotExist($this->target . '/second-delete.txt');
    }

    public function testProcessRestartRewindsATargetBehindTheCheckpointAtARecordBoundary(): void {
        $interrupted = $this->begin_rejected_delete_push();
        $boundary_offset = strlen("first-delete.txt\0");
        file_put_contents($interrupted['target_stream_path'], substr($interrupted['local_stream'], 0, $boundary_offset));
        $checkpoint = json_decode( (string) file_get_contents($interrupted['checkpoint_path']), true);
        $this->assertIsArray($checkpoint);
        $checkpoint['delete_offset'] = strlen($interrupted['local_stream']);
        file_put_contents($interrupted['checkpoint_path'], json_encode($checkpoint, JSON_UNESCAPED_SLASHES));
        $this->reject_upload_marker = null;
        $this->capture_upload_body_path = $this->case_root . '/record-boundary-resume-body';
        $this->configure_server();

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertStringContainsString(
            "X-Chunk-Type: delete-list\r\nX-Delete-Offset: " . $boundary_offset . "\r\n",
            (string) file_get_contents($this->capture_upload_body_path)
        );
        $this->assertFileDoesNotExist($this->target . '/first-delete.txt');
        $this->assertFileDoesNotExist($this->target . '/second-delete.txt');
    }

    public function testProcessRestartAcceptsATargetDeleteOffsetAtLocalEof(): void {
        $interrupted = $this->begin_rejected_delete_push();
        file_put_contents($interrupted['target_stream_path'], $interrupted['local_stream']);
        $this->reject_upload_marker = null;
        $this->capture_upload_body_path = $this->case_root . '/eof-resume-body';
        $this->configure_server();

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $captured_body = (string) file_get_contents($this->capture_upload_body_path);
        $this->assertSame(1, substr_count($captured_body, 'X-Chunk-Type: delete-list'));
        $this->assertStringContainsString(
            "X-Delete-Offset: " . strlen($interrupted['local_stream']) . "\r\nX-Delete-Complete: 1\r\n",
            $captured_body
        );
        $this->assertStringContainsString("Content-Length: 0\r\n", $captured_body);
        $this->assertFileDoesNotExist($this->target . '/first-delete.txt');
        $this->assertFileDoesNotExist($this->target . '/second-delete.txt');
    }

    public function testProcessRestartRejectsATargetDeleteOffsetBeyondLocalEof(): void {
        $interrupted = $this->begin_rejected_delete_push();
        file_put_contents($interrupted['target_stream_path'], $interrupted['local_stream'] . "unexpected\0");

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $resumed['exit_code']);
        $this->assertStringContainsString('beyond the', $resumed['stderr']);
        $this->assertStringContainsString('local delete stream', $resumed['stderr']);
        $this->assertFileExists($this->target . '/first-delete.txt');
        $this->assertFileExists($this->target . '/second-delete.txt');
    }

    public function testMissingRawDeleteStreamRequiresAbortWithoutPreventingIt(): void {
        $interrupted = $this->begin_rejected_delete_push();
        unlink($interrupted['local_stream_path']);

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $resumed['exit_code']);
        $this->assertStringContainsString('The local delete stream is missing or unreadable', $resumed['stderr']);
        $this->assertStringContainsString('Run push with --abort, then start it again.', $resumed['stderr']);

        $aborted = $this->run_cli('push', ['--source-root=' . $this->source, '--abort']);
        $this->assertSame(0, $aborted['exit_code'], $aborted['stderr']);
        $this->assertSame('aborted', $aborted['json']['status'] ?? null);
    }

    public function testSignedStatusRejectsANegativeDeleteOffsetBeforeUpload(): void {
        $this->write_source('delete-after-negative-status.txt', 'delete me');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        unlink($this->source . '/delete-after-negative-status.txt');

        $this->negative_delete_status_marker = $this->case_root . '/negative-delete-status';
        $this->configure_server();
        $upload_requests_before = $this->endpoint_count('staged_session_upload');
        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertFileExists($this->negative_delete_status_marker);
        $this->assertStringContainsString('Target status delete_bytes must be a nonnegative integer; observed -1.', $result['stderr']);
        $this->assertSame($upload_requests_before, $this->endpoint_count('staged_session_upload'));
        $this->assertFileExists($this->target . '/delete-after-negative-status.txt');
    }

    public function testProcessRestartRejectsCompletedTargetDeleteStateAtTheWrongSize(): void {
        $interrupted = $this->begin_rejected_delete_push();
        $metadata_path = dirname($interrupted['target_stream_path'], 2) . '/session.json';
        $metadata = json_decode( (string) file_get_contents($metadata_path), true);
        $this->assertIsArray($metadata);
        $metadata['delete_upload_complete'] = true;
        file_put_contents($metadata_path, json_encode($metadata, JSON_UNESCAPED_SLASHES));

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $resumed['exit_code']);
        $this->assertStringContainsString('delete_upload_complete is true', $resumed['stderr']);
        $this->assertStringContainsString('local delete stream contains', $resumed['stderr']);
        $this->assertFileExists($this->target . '/first-delete.txt');
        $this->assertFileExists($this->target . '/second-delete.txt');
    }

    public function testAcceptedDeleteResponseAheadOfTheSentChunkReconcilesThroughSignedStatus(): void {
        $this->write_source('delete-after-ahead-response.txt', 'delete me');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        unlink($this->source . '/delete-after-ahead-response.txt');

        $this->inflate_delete_response_marker = $this->case_root . '/inflated-delete-response';
        $this->configure_server();
        $status_requests_before = $this->endpoint_count('staged_session_status');
        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertFileExists($this->inflate_delete_response_marker);
        $this->assertFileDoesNotExist($this->target . '/delete-after-ahead-response.txt');
        $this->assertGreaterThanOrEqual(
            $status_requests_before + 2,
            $this->endpoint_count('staged_session_status')
        );
    }

    public function testLostDeleteCompletionResponseReplaysTheIdempotentDeclaration(): void {
        $this->write_source('delete-before-lost-completion.txt', 'delete me');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        unlink($this->source . '/delete-before-lost-completion.txt');

        $this->drop_delete_completion_response_marker = $this->case_root . '/dropped-delete-completion';
        $this->configure_server();
        $upload_requests_before = $this->endpoint_count('staged_session_upload');
        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertFileExists($this->drop_delete_completion_response_marker);
        $this->assertFileDoesNotExist($this->target . '/delete-before-lost-completion.txt');
        $this->assertGreaterThanOrEqual(
            $upload_requests_before + 3,
            $this->endpoint_count('staged_session_upload')
        );
    }

    public function testDryRunDoesNotCreateARemoteSessionOrPublishABaseline(): void {
        $this->write_source('dry-run.txt', 'not uploaded');
        $dry_run = $this->run_cli('push', ['--source-root=' . $this->source, '--dry-run']);

        $this->assertSame(0, $dry_run['exit_code'], $dry_run['stderr']);
        $this->assertSame('dry_run', $dry_run['json']['status'] ?? null);
        $this->assertFileDoesNotExist($this->target . '/dry-run.txt');
        $this->assertSame(0, $this->endpoint_count('staged_session_create'));
        $this->assertSame([], glob($this->state . '/push/*/last-sync-local-files.jsonl') ?: []);
    }

    public function testPushAfterDryRunRescansAndAppliesTheCurrentDelta(): void {
        $this->write_source('rescanned.txt', 'baseline');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);

        $this->write_source('rescanned.txt', 'dry-run contents');
        $dry_run = $this->run_cli('push', ['--source-root=' . $this->source, '--dry-run']);
        $this->assertSame(0, $dry_run['exit_code'], $dry_run['stderr']);
        $this->assertSame('dry_run', $dry_run['json']['status'] ?? null);
        $this->assertSame('baseline', file_get_contents($this->target . '/rescanned.txt'));

        $this->write_source('rescanned.txt', 'confirmed contents');
        $this->write_source('added-after-dry-run.txt', 'newly confirmed');
        $pushed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $pushed['exit_code'], $pushed['stderr']);
        $this->assertSame('complete', $pushed['json']['status'] ?? null);
        $this->assertSame(2, $pushed['json']['changed'] ?? null);
        $this->assertSame('confirmed contents', file_get_contents($this->target . '/rescanned.txt'));
        $this->assertSame('newly confirmed', file_get_contents($this->target . '/added-after-dry-run.txt'));
    }

    public function testDryRunRejectsAnActivePushWithoutAdvancingIt(): void {
        $this->reject_upload_marker = $this->case_root . '/rejected-upload';
        $this->configure_server();
        $this->write_source('active.txt', 'leave this push resumable');

        $failed = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $failed['exit_code']);
        $this->assertFileExists($this->reject_upload_marker);
        $requests_before_dry_run = is_file($this->request_log)
            ? file_get_contents($this->request_log)
            : '';

        $dry_run = $this->run_cli('push', ['--source-root=' . $this->source, '--dry-run']);

        $this->assertNotSame(0, $dry_run['exit_code']);
        $this->assertStringContainsString('dry-run', $dry_run['stderr']);
        $this->assertStringContainsString('active push', $dry_run['stderr']);
        $this->assertSame($requests_before_dry_run, file_get_contents($this->request_log));
        $checkpoints = glob($this->state . '/push/*/session.json') ?: [];
        $this->assertCount(1, $checkpoints);
        $checkpoint = json_decode( (string) file_get_contents($checkpoints[0]), true);
        $this->assertIsArray($checkpoint);
        $this->assertArrayHasKey('delete_offset', $checkpoint);
        $this->assertArrayNotHasKey('delete_plan_offset', $checkpoint);
        $this->assertArrayNotHasKey('delete_record_offset', $checkpoint);
        $this->assertFileDoesNotExist($this->target . '/active.txt');
    }

    public function testSuccessfulSessionCleanupResumesAfterItsResponseIsLost(): void {
        $this->drop_discard_response_marker = $this->case_root . '/discarded-without-response';
        $this->configure_server();
        $this->write_source('cleanup.txt', 'committed before cleanup');

        $interrupted = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $interrupted['exit_code']);
        $this->assertFileExists($this->drop_discard_response_marker);
        $this->assertSame('committed before cleanup', file_get_contents($this->target . '/cleanup.txt'));
        $this->assertSame([], glob($this->storage . '/apply-sessions/[a-f0-9]*', GLOB_ONLYDIR) ?: []);
        $this->assertCount(1, glob($this->state . '/push/*/session.json') ?: []);
        $this->assertCount(1, glob($this->state . '/push/*/last-sync-local-files.jsonl') ?: []);

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertSame([], glob($this->storage . '/apply-sessions/[a-f0-9]*', GLOB_ONLYDIR) ?: []);
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);
        $this->assertGreaterThanOrEqual(2, $this->endpoint_count('staged_session_discard'));
    }

    public function testFailedLocalCheckpointRemovalIsReportedAndRetryable(): void {
        $this->make_checkpoint_unremovable_after_discard = true;
        $this->configure_server();
        $this->write_source('checked-unlink.txt', 'already deployed');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);
        try {
            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('Could not remove local push session checkpoint', $result['stderr']);
            $this->assertSame('already deployed', file_get_contents($this->target . '/checked-unlink.txt'));
            $this->assertCount(1, glob($this->state . '/push/*/session.json') ?: []);
            $this->assertSame([], glob($this->storage . '/apply-sessions/[a-f0-9]*', GLOB_ONLYDIR) ?: []);
        } finally {
            foreach (glob($this->state . '/push/*', GLOB_ONLYDIR) ?: [] as $site_state) {
                chmod($site_state, 0700);
            }
            $this->make_checkpoint_unremovable_after_discard = false;
            $this->configure_server();
        }

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);
    }

    public function testMissingPushRequirementsDoNotCreateTheStateDirectory(): void {
        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $unused_state = $this->case_root . '/state-must-not-exist';
        $process = proc_open([
            PHP_BINARY,
            $entry,
            'push',
            self::$base_url,
            '--state-dir=' . $unused_state,
            '--allow-http',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        $this->assertNotSame(0, $exit_code);
        $this->assertStringContainsString('--secret=TOKEN', (string) $stderr);
        $this->assertDirectoryDoesNotExist($unused_state);
    }

    public function testLostUploadResponseIsReconciledFromTargetStagingBeforeTheBaselineAdvances(): void {
        $this->drop_upload_response_marker = $this->case_root . '/dropped-upload-response';
        $this->configure_server();
        $this->write_source('lost-response.bin', str_repeat("a\0b", 40));

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertFileExists($this->drop_upload_response_marker);
        $this->assertSame(str_repeat("a\0b", 40), file_get_contents($this->target . '/lost-response.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
        $this->assertCount(1, glob($this->state . '/push/*/last-sync-local-files.jsonl') ?: []);
    }

    public function testFullPartialFileAfterInterruptedPromotionCompletesOnResume(): void {
        $this->pause_after_upload_marker = $this->case_root . '/upload-accepted';
        $this->resume_upload_response_marker = $this->case_root . '/resume-upload-response';
        $this->configure_server();
        $contents = str_repeat('promotion-crash-', 4);
        $this->write_source('promotion.bin', $contents);

        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $process = proc_open([
            PHP_BINARY,
            $entry,
            'push',
            self::$base_url,
            '--state-dir=' . $this->state,
            '--secret=' . self::SECRET,
            '--allow-http',
            '--source-root=' . $this->source,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (is_file($this->pause_after_upload_marker)) {
                break;
            }
            usleep(100000);
        }
        $sessions = glob($this->storage . '/apply-sessions/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $sessions, 'The target never completed the file upload before its response was interrupted.');
        $complete = $sessions[0] . '/work/files/promotion.bin';
        $partial = $sessions[0] . '/work/partial/promotion.bin';
        $this->assertFileExists($complete);
        $this->assertTrue(rename($complete, $partial), 'Could not simulate a crash between the final write and promotion rename.');
        file_put_contents($this->resume_upload_response_marker, "resume\n");

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $lines = array_values(array_filter(preg_split('/\R/', trim($stdout)) ?: [], static function (string $line): bool {
            return $line !== '';
        }));
        $last_line = end($lines);
        $result = is_string($last_line) ? json_decode($last_line, true) : null;
        $status = is_array($result) ? $result['status'] ?? null : null;

        $this->assertSame(0, $exit_code, $stderr);
        $this->assertSame('complete', $status);
        $this->assertSame($contents, file_get_contents($this->target . '/promotion.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
        $this->assertGreaterThanOrEqual(2, $this->endpoint_count('staged_session_upload'));
    }

    public function testSameSizeSourceEditDuringLostResponseRestartsAtZeroInsteadOfBuildingAHybridFile(): void {
        $source_path = $this->source . '/changed-during-response.bin';
        $old_contents = str_repeat('old-', 200);
        $new_contents = str_repeat('new-', 200);
        file_put_contents($source_path, $old_contents);
        $old_ctime = (int) lstat($source_path)['ctime'];

        $result = $this->run_push_while_first_upload_response_is_paused(function () use ($source_path, $new_contents, $old_ctime): void {
            $new_ctime = $old_ctime;
            for ($attempt = 0; $attempt < 4 && $new_ctime === $old_ctime; ++$attempt) {
                sleep(1);
                file_put_contents($source_path, $new_contents);
                clearstatcache(true, $source_path);
                $new_ctime = (int) lstat($source_path)['ctime'];
            }
            $this->assertNotSame($old_ctime, $new_ctime, 'The filesystem did not expose a ctime change for the same-size edit.');
        });

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertSame($new_contents, file_get_contents($this->target . '/changed-during-response.bin'));
        $this->assertNotSame($old_contents, file_get_contents($this->target . '/changed-during-response.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
    }

    public function testSourceGrowthDuringLostResponseRestartsAtZeroInsteadOfAppendingVersions(): void {
        $source_path = $this->source . '/grown-during-response.bin';
        $old_contents = str_repeat('old-', 200);
        $new_contents = str_repeat('new-version-', 120);
        file_put_contents($source_path, $old_contents);

        $result = $this->run_push_while_first_upload_response_is_paused(static function () use ($source_path, $new_contents): void {
            file_put_contents($source_path, $new_contents);
        });

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertSame($new_contents, file_get_contents($this->target . '/grown-during-response.bin'));
        $this->assertNotSame($old_contents, file_get_contents($this->target . '/grown-during-response.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
    }

    public function testSourceShrinkDuringLostResponseRestartsAtZeroInsteadOfKeepingAnOldSuffix(): void {
        $source_path = $this->source . '/shrunk-during-response.bin';
        $old_contents = str_repeat('old-version-', 120);
        $new_contents = str_repeat('new-', 100);
        file_put_contents($source_path, $old_contents);

        $result = $this->run_push_while_first_upload_response_is_paused(static function () use ($source_path, $new_contents): void {
            file_put_contents($source_path, $new_contents);
        });

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertSame($new_contents, file_get_contents($this->target . '/shrunk-during-response.bin'));
        $this->assertNotSame($old_contents, file_get_contents($this->target . '/shrunk-during-response.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
    }

    public function testSourceDeletionDuringLostResponseDiscardsTheStalePrivateSession(): void {
        $source_path = $this->source . '/deleted-during-response.bin';
        file_put_contents($source_path, str_repeat('delete-me-', 100));

        $result = $this->run_push_while_first_upload_response_is_paused(static function () use ($source_path): void {
            unlink($source_path);
        });

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('Source changed structurally during push', $result['stderr']);
        $this->assertFileDoesNotExist($this->target . '/deleted-during-response.bin');
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);

        $resumed = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $resumed['exit_code'], $resumed['stderr']);
        $this->assertSame('complete', $resumed['json']['status'] ?? null);
        $this->assertFileDoesNotExist($this->target . '/deleted-during-response.bin');
    }

    public function testAbortKeepsLocalStateUntilTheTargetConfirmsDiscard(): void {
        $this->reject_upload_marker = $this->case_root . '/rejected-upload';
        $this->configure_server();
        $this->write_source('abort-me.txt', 'this request fails after create');

        $failed = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $failed['exit_code']);
        $this->assertFileExists($this->reject_upload_marker);
        $this->assertCount(1, glob($this->state . '/push/*/session.json') ?: []);
        $this->assertFileDoesNotExist($this->target . '/abort-me.txt');

        $aborted = $this->run_cli('push', ['--source-root=' . $this->source, '--abort']);
        $this->assertSame(0, $aborted['exit_code'], $aborted['stderr']);
        $this->assertSame('aborted', $aborted['json']['status'] ?? null);
        $this->assertSame([], glob($this->state . '/push/*/session.json') ?: []);
        $this->assertFileDoesNotExist($this->target . '/abort-me.txt');
        $this->assertSame([], glob($this->state . '/push/*/last-sync-local-files.jsonl') ?: []);
    }

    public function testCreateRefusesCrossFilesystemSessionStorageBeforeAnyLiveMutation(): void {
        $other_root = $this->other_filesystem_root();
        if ($other_root === null) {
            $this->markTestSkipped('No separately mounted filesystem is available for the real EXDEV refusal test.');
        }
        $this->other_filesystem_storage = $other_root . '/reprint-cross-device-' . bin2hex(random_bytes(8));
        mkdir($this->other_filesystem_storage, 0700, true);
        $this->storage = $this->other_filesystem_storage;
        $this->configure_server();
        $this->write_source('must-not-arrive.txt', 'live tree must stay untouched');

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('same-filesystem rename', $result['stderr']);
        $this->assertFileDoesNotExist($this->target . '/must-not-arrive.txt');
        $this->assertSame(1, $this->endpoint_count('staged_session_create'));
    }

    /** @return array{exit_code:int,stdout:string,stderr:string,json:array<string,mixed>} */
    private function run_push_while_first_upload_response_is_paused(callable $mutate_source): array {
        $this->pause_after_upload_marker = $this->case_root . '/upload-accepted';
        $this->resume_upload_response_marker = $this->case_root . '/resume-upload-response';
        $this->configure_server();
        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $process = proc_open([
            PHP_BINARY,
            $entry,
            'push',
            self::$base_url,
            '--state-dir=' . $this->state,
            '--secret=' . self::SECRET,
            '--allow-http',
            '--source-root=' . $this->source,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);

        for ($attempt = 0; $attempt < 100 && !is_file($this->pause_after_upload_marker); ++$attempt) {
            usleep(100000);
        }
        $this->assertFileExists($this->pause_after_upload_marker, 'The target never accepted the old source version.');
        $mutation_error = null;
        try {
            $mutate_source();
        } catch (\Throwable $error) {
            $mutation_error = $error;
        }
        file_put_contents($this->resume_upload_response_marker, "resume\n");

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        if ($mutation_error !== null) {
            throw $mutation_error;
        }
        $lines = array_values(array_filter(preg_split('/\R/', trim( (string) $stdout)) ?: [], static function (string $line): bool {
            return $line !== '';
        }));
        $decoded = $lines === [] ? null : json_decode( (string) end($lines), true);
        return [
            'exit_code' => $exit_code,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    /** @param string[] $extra_options @return array{exit_code:int,stdout:string,stderr:string,json:array<string,mixed>} */
    private function run_cli(string $command, array $extra_options = []): array {
        $entry = realpath(__DIR__ . '/../../importer/import.php');
        $this->assertNotFalse($entry);
        $arguments = array_merge([
            PHP_BINARY,
            $entry,
            $command,
            self::$base_url,
            '--state-dir=' . $this->state,
            '--secret=' . self::SECRET,
            '--allow-http',
        ], $extra_options);
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->case_root);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $lines = array_values(array_filter(preg_split('/\R/', trim((string) $stdout)) ?: [], static function (string $line): bool {
            return $line !== '';
        }));
        $decoded = $lines === [] ? null : json_decode((string) end($lines), true);
        return [
            'exit_code' => $exit_code,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function write_source(string $relative_path, string $contents): void {
        $path = $this->source . '/' . $relative_path;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return array{local_stream:string,local_stream_path:string,target_stream_path:string,checkpoint_path:string}
     */
    private function begin_rejected_delete_push(): array {
        $this->write_source('first-delete.txt', 'first');
        $this->write_source('second-delete.txt', 'second');
        $initial = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $initial['exit_code'], $initial['stderr']);
        unlink($this->source . '/first-delete.txt');
        unlink($this->source . '/second-delete.txt');

        $this->reject_upload_marker = $this->case_root . '/rejected-delete-upload';
        $this->configure_server();
        $interrupted = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertNotSame(0, $interrupted['exit_code']);
        $this->assertFileExists($this->reject_upload_marker);

        $local_stream_paths = glob($this->state . '/push/*/local-delete-stream.bin') ?: [];
        $this->assertCount(1, $local_stream_paths);
        $local_stream = file_get_contents($local_stream_paths[0]);
        $this->assertSame("first-delete.txt\0second-delete.txt\0", $local_stream);
        $session_directories = glob($this->storage . '/apply-sessions/[a-f0-9]*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $session_directories);
        $checkpoint_paths = glob($this->state . '/push/*/session.json') ?: [];
        $this->assertCount(1, $checkpoint_paths);
        $target_stream_path = $session_directories[0] . '/work/deletes';
        $this->assertSame('', file_get_contents($target_stream_path));

        return [
            'local_stream' => $local_stream,
            'local_stream_path' => $local_stream_paths[0],
            'target_stream_path' => $target_stream_path,
            'checkpoint_path' => $checkpoint_paths[0],
        ];
    }

    private function configure_server(): void {
        file_put_contents(self::$config_path, json_encode([
            'staging_dir' => $this->storage,
            'secret' => $this->server_secret,
            'apply_target_root' => $this->target,
            // Make the real CLI split the large file into several MIME parts.
            'max_frame_bytes' => $this->max_frame_bytes,
            'request_log' => $this->request_log,
            'drop_upload_response_marker' => $this->drop_upload_response_marker,
            'drop_delete_completion_response_marker' => $this->drop_delete_completion_response_marker,
            'inflate_delete_response_marker' => $this->inflate_delete_response_marker,
            'negative_delete_status_marker' => $this->negative_delete_status_marker,
            'pause_status_response_marker' => $this->pause_status_response_marker,
            'resume_status_response_marker' => $this->resume_status_response_marker,
            'capture_upload_body_path' => $this->capture_upload_body_path,
            'pause_after_upload_marker' => $this->pause_after_upload_marker,
            'resume_upload_response_marker' => $this->resume_upload_response_marker,
            'return_paused_upload_response' => $this->return_paused_upload_response,
            'reject_upload_marker' => $this->reject_upload_marker,
            'drop_discard_response_marker' => $this->drop_discard_response_marker,
            'make_checkpoint_unremovable_after_discard' => $this->make_checkpoint_unremovable_after_discard,
            'busy_response_limits' => $this->busy_response_limits,
            'busy_response_counter_dir' => $this->case_root,
            'control_fault' => $this->control_fault,
            'redirect_url' => self::$base_url,
            'too_large_upload_responses' => $this->too_large_upload_responses,
            'apply_protected_paths' => $this->apply_protected_paths,
            'local_state_dir' => $this->state,
        ]));
    }

    private function endpoint_count(string $endpoint): int {
        if (!is_file($this->request_log)) {
            return 0;
        }
        $count = 0;
        foreach (file($this->request_log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $request = json_decode($line, true);
            if (is_array($request) && ($request['endpoint'] ?? null) === $endpoint) {
                ++$count;
            }
        }
        return $count;
    }

    private function other_filesystem_root(): ?string {
        $candidates = [];
        $configured = getenv('REPRINT_TEST_OTHER_FILESYSTEM');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = '/dev/shm';
        $target_stat = @lstat($this->target);
        if (!is_array($target_stat) || !isset($target_stat['dev'])) {
            return null;
        }
        foreach ($candidates as $candidate) {
            $candidate_stat = @lstat($candidate);
            if (is_array($candidate_stat) && isset($candidate_stat['dev'])
                && (int) $candidate_stat['dev'] !== (int) $target_stat['dev']) {
                return rtrim($candidate, '/');
            }
        }
        return null;
    }

    /** @return array<int,array<string,string>> */
    private function lstat_tree(string $root): array {
        $entries = [];
        $this->collect_lstat_tree($root, '', $entries);
        usort($entries, static function (array $left, array $right): int {
            return strcmp($left['path_b64'], $right['path_b64']);
        });
        return $entries;
    }

    /** @param array<int,array<string,string>> $entries */
    private function collect_lstat_tree(string $directory, string $prefix, array &$entries): void {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            $relative_path = $prefix === '' ? $name : $prefix . '/' . $name;
            $stat = lstat($path);
            $this->assertIsArray($stat);
            $type_bits = ((int) $stat['mode']) & 0170000;
            $entry = ['path_b64' => base64_encode($relative_path)];
            if ($type_bits === 0040000) {
                $entry['type'] = 'directory';
                $entries[] = $entry;
                $this->collect_lstat_tree($path, $relative_path, $entries);
            } elseif ($type_bits === 0100000) {
                $entry['type'] = 'file';
                $entry['contents_b64'] = base64_encode((string) file_get_contents($path));
                $entries[] = $entry;
            } elseif ($type_bits === 0120000) {
                $entry['type'] = 'symlink';
                $entry['target_b64'] = base64_encode((string) readlink($path));
                $entries[] = $entry;
            } else {
                self::fail('Unexpected source or target entry type at ' . base64_encode($relative_path));
            }
        }
    }

    private static function write_router(): void {
        $root = addslashes((string) realpath(__DIR__ . '/../..'));
        $config = addslashes(self::$config_path);
        file_put_contents(self::$router_path, <<<PHP_ROUTER
<?php
require_once '{$root}/packages/reprint-exporter/src/utils.php';
require_once '{$root}/packages/reprint-exporter/src/class-hmac-client.php';
require_once '{$root}/packages/reprint-exporter/src/class-hmac-server.php';
require_once '{$root}/packages/reprint-exporter/src/class-multipart-processor.php';
require_once '{$root}/packages/reprint-exporter/src/class-staged-apply-session.php';
require_once '{$root}/packages/reprint-exporter/src/class-staged-endpoints.php';
require_once '{$root}/packages/reprint-exporter/src/class-http-server.php';
if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}
\$config = json_decode((string) file_get_contents('{$config}'), true);
if (!is_array(\$config)) {
    http_response_code(500);
    echo 'missing config';
    return true;
}
file_put_contents((string) \$config['request_log'], json_encode(['endpoint' => \$_GET['endpoint'] ?? null]) . "\\n", FILE_APPEND);
if ((\$_GET['endpoint'] ?? null) === 'staged_session_status'
    && is_string(\$config['pause_status_response_marker'] ?? null)
    && \$config['pause_status_response_marker'] !== ''
    && !file_exists(\$config['pause_status_response_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response_body = ob_get_clean();
    file_put_contents(\$config['pause_status_response_marker'], (string) \$response_body);
    \$resume_marker = \$config['resume_status_response_marker'] ?? null;
    \$deadline = microtime(true) + 15;
    while (is_string(\$resume_marker) && !file_exists(\$resume_marker) && microtime(true) < \$deadline) {
        usleep(10000);
    }
    echo \$response_body;
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_status'
    && is_string(\$config['negative_delete_status_marker'] ?? null)
    && \$config['negative_delete_status_marker'] !== ''
    && !file_exists(\$config['negative_delete_status_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response_body = ob_get_clean();
    \$response = json_decode((string) \$response_body, true);
    if (is_array(\$response)) {
        \$response['delete_bytes'] = -1;
        \$response_body = json_encode(\$response);
    }
    file_put_contents(\$config['negative_delete_status_marker'], "returned negative delete_bytes\\n");
    echo \$response_body;
    return true;
}
\$endpoint = \$_GET['endpoint'] ?? null;
\$busy_response_limits = \$config['busy_response_limits'] ?? [];
if (is_string(\$endpoint) && is_array(\$busy_response_limits) && isset(\$busy_response_limits[\$endpoint])) {
    \$counter_path = (string) \$config['busy_response_counter_dir'] . '/busy-' . \$endpoint . '.count';
    \$attempt = (int) @file_get_contents(\$counter_path) + 1;
    file_put_contents(\$counter_path, (string) \$attempt);
    if (\$attempt <= (int) \$busy_response_limits[\$endpoint]) {
        http_response_code(423);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'reason' => 'busy',
            'detail' => 'The test endpoint is busy.',
            'send_next_request' => false,
        ]);
        return true;
    }
}
if (\$endpoint === 'staged_session_create' && (\$config['control_fault'] ?? null) === 'redirect') {
    http_response_code(307);
    header('Location: ' . (string) \$config['redirect_url']);
    return true;
}
if (\$endpoint === 'staged_session_create' && (\$config['control_fault'] ?? null) === 'malformed') {
    header('Content-Type: text/plain');
    echo 'not a JSON response';
    return true;
}
if (\$endpoint === 'staged_session_upload' && (int) (\$config['too_large_upload_responses'] ?? 0) > 0) {
    \$counter_path = (string) \$config['busy_response_counter_dir'] . '/forced-413.count';
    \$attempt = (int) @file_get_contents(\$counter_path) + 1;
    file_put_contents(\$counter_path, (string) \$attempt);
    if (\$attempt <= (int) \$config['too_large_upload_responses']) {
        http_response_code(413);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'reason' => 'request_too_large',
            'post_max_bytes' => 4 * 1024 * 1024,
        ]);
        return true;
    }
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['reject_upload_marker'] ?? null)
    && \$config['reject_upload_marker'] !== ''
    && !file_exists(\$config['reject_upload_marker'])) {
    file_put_contents(\$config['reject_upload_marker'], "rejected after create\\n");
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'reason' => 'test_upload_rejected', 'detail' => 'The test router rejected this upload.']);
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['capture_upload_body_path'] ?? null)
    && \$config['capture_upload_body_path'] !== ''
    && !file_exists(\$config['capture_upload_body_path'])) {
    \$endpoints = new Site_Export_Staged_Endpoints(\$config);
    \$response = \$endpoints->pre_authenticate_envelope(\$_SERVER, 'staged_session_upload');
    if (\$response === null) {
        \$request_body = file_get_contents('php://input');
        file_put_contents(\$config['capture_upload_body_path'], (string) \$request_body);
        \$input = fopen('php://temp', 'w+b');
        fwrite(\$input, (string) \$request_body);
        rewind(\$input);
        \$response = \$endpoints->session_upload(\$config, \$_SERVER, \$input);
        fclose(\$input);
    }
    http_response_code((int) (\$response['http_code'] ?? 500));
    header('Content-Type: application/json');
    echo json_encode(\$response['body'] ?? []);
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['inflate_delete_response_marker'] ?? null)
    && \$config['inflate_delete_response_marker'] !== ''
    && !file_exists(\$config['inflate_delete_response_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response_body = ob_get_clean();
    \$response = json_decode((string) \$response_body, true);
    if (is_array(\$response) && is_array(\$response['accepted'] ?? null)) {
        foreach (\$response['accepted'] as &\$accepted) {
            if (is_array(\$accepted) && (\$accepted['type'] ?? null) === 'delete-list') {
                \$accepted['accepted_bytes'] = (int) (\$accepted['accepted_bytes'] ?? 0) + 1;
                break;
            }
        }
        unset(\$accepted);
        \$response_body = json_encode(\$response);
    }
    file_put_contents(\$config['inflate_delete_response_marker'], "inflated accepted_bytes\\n");
    echo \$response_body;
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['drop_delete_completion_response_marker'] ?? null)
    && \$config['drop_delete_completion_response_marker'] !== ''
    && !file_exists(\$config['drop_delete_completion_response_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response_body = ob_get_clean();
    \$response = json_decode((string) \$response_body, true);
    \$accepted = is_array(\$response) && is_array(\$response['accepted'] ?? null)
        ? \$response['accepted']
        : [];
    foreach (\$accepted as \$confirmation) {
        if (is_array(\$confirmation)
            && (\$confirmation['type'] ?? null) === 'delete-list'
            && (\$confirmation['state'] ?? null) === 'complete') {
            file_put_contents(\$config['drop_delete_completion_response_marker'], "dropped completion response\\n");
            return true;
        }
    }
    echo \$response_body;
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['pause_after_upload_marker'] ?? null)
    && \$config['pause_after_upload_marker'] !== ''
    && !file_exists(\$config['pause_after_upload_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response_body = ob_get_clean();
    file_put_contents(\$config['pause_after_upload_marker'], (string) \$response_body);
    \$resume_marker = \$config['resume_upload_response_marker'] ?? null;
    \$deadline = microtime(true) + 15;
    while (is_string(\$resume_marker) && !file_exists(\$resume_marker) && microtime(true) < \$deadline) {
        usleep(10000);
    }
    if (!empty(\$config['return_paused_upload_response'])) {
        echo \$response_body;
    }
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_upload'
    && is_string(\$config['drop_upload_response_marker'] ?? null)
    && \$config['drop_upload_response_marker'] !== ''
    && !file_exists(\$config['drop_upload_response_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    ob_end_clean();
    file_put_contents(\$config['drop_upload_response_marker'], "accepted without response\\n");
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_discard'
    && is_string(\$config['drop_discard_response_marker'] ?? null)
    && \$config['drop_discard_response_marker'] !== ''
    && !file_exists(\$config['drop_discard_response_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    ob_end_clean();
    file_put_contents(\$config['drop_discard_response_marker'], "discarded without response\\n");
    return true;
}
if ((\$_GET['endpoint'] ?? null) === 'staged_session_discard'
    && !empty(\$config['make_checkpoint_unremovable_after_discard'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    \$response = ob_get_clean();
    foreach (glob((string) \$config['local_state_dir'] . '/push/*/session.json') ?: [] as \$checkpoint) {
        chmod(dirname(\$checkpoint), 0500);
    }
    echo \$response;
    return true;
}
(new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
return true;
PHP_ROUTER);
    }

    private static function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
