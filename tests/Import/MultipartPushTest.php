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
    private ?string $drop_upload_response_marker = null;
    private ?string $pause_after_upload_marker = null;
    private ?string $resume_upload_response_marker = null;
    private ?string $reject_upload_marker = null;

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

    public function testPushMakesTheTargetTreeExactlyMatchAStandaloneSourceSnapshot(): void {
        $this->write_source('ordinary.txt', 'first contents');
        $this->write_source('zero.bin', '');
        $this->write_source('empty/.placeholder', '');
        unlink($this->source . '/empty/.placeholder');
        // APFS configured with a UTF-8-only volume rejects this name, while
        // Linux filesystems accept it. Exercise the arbitrary-byte path when
        // the host supports it without making portability a test warning.
        @file_put_contents($this->source . "/raw-\xff-name", "\0binary\xff");
        $has_link = @symlink('ordinary.txt', $this->source . '/link');

        $first = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $first['exit_code'], $first['stderr']);
        $this->assertSame($this->lstat_tree($this->source), $this->lstat_tree($this->target));

        unlink($this->source . '/ordinary.txt');
        mkdir($this->source . '/ordinary.txt', 0700, true);
        file_put_contents($this->source . '/ordinary.txt/nested.php', 'replacement tree');
        unlink($this->source . '/zero.bin');
        $this->write_source('added.txt', 'new file with a different size');
        if ($has_link) {
            unlink($this->source . '/link');
            $this->write_source('link', 'a symlink became a file');
        }

        $second = $this->run_cli('push', ['--source-root=' . $this->source]);
        $this->assertSame(0, $second['exit_code'], $second['stderr']);
        $this->assertSame($this->lstat_tree($this->source), $this->lstat_tree($this->target));
    }

    public function testLargeFileUsesSeveralMultipartRequestsBeforeItIsPromoted(): void {
        $contents = str_repeat("part\0", 700);
        $this->write_source('multipart-large.bin', $contents);

        $result = $this->run_cli('push', ['--source-root=' . $this->source]);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('complete', $result['json']['status'] ?? null);
        $this->assertSame($contents, file_get_contents($this->target . '/multipart-large.bin'));
        $this->assertGreaterThanOrEqual(2, $this->endpoint_count('staged_session_upload'));
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

    public function testSameSizeSourceEditDuringLostResponseRestartsAtZeroInsteadOfBuildingAHybridFile(): void {
        $this->pause_after_upload_marker = $this->case_root . '/upload-accepted';
        $this->resume_upload_response_marker = $this->case_root . '/resume-upload-response';
        $this->configure_server();
        $source_path = $this->source . '/changed-during-response.bin';
        $old_contents = str_repeat('old-', 200);
        $new_contents = str_repeat('new-', 200);
        file_put_contents($source_path, $old_contents);
        $old_ctime = (int) lstat($source_path)['ctime'];

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
        $new_ctime = $old_ctime;
        for ($attempt = 0; $attempt < 4 && $new_ctime === $old_ctime; ++$attempt) {
            sleep(1);
            file_put_contents($source_path, $new_contents);
            clearstatcache(true, $source_path);
            $new_ctime = (int) lstat($source_path)['ctime'];
        }
        $this->assertNotSame($old_ctime, $new_ctime, 'The filesystem did not expose a ctime change for the same-size edit.');
        file_put_contents($this->resume_upload_response_marker, "resume\n");

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $lines = array_values(array_filter(preg_split('/\R/', trim((string) $stdout)) ?: [], static function (string $line): bool {
            return $line !== '';
        }));
        $result = $lines === [] ? null : json_decode((string) end($lines), true);

        $this->assertSame(0, $exit_code, (string) $stderr);
        $this->assertSame('complete', is_array($result) ? ($result['status'] ?? null) : null);
        $this->assertSame($new_contents, file_get_contents($this->target . '/changed-during-response.bin'));
        $this->assertNotSame($old_contents, file_get_contents($this->target . '/changed-during-response.bin'));
        $this->assertGreaterThanOrEqual(1, $this->endpoint_count('staged_session_status'));
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
        $this->assertStringContainsString('one filesystem', $result['stderr']);
        $this->assertFileDoesNotExist($this->target . '/must-not-arrive.txt');
        $this->assertSame(1, $this->endpoint_count('staged_session_create'));
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

    private function configure_server(): void {
        file_put_contents(self::$config_path, json_encode([
            'staging_dir' => $this->storage,
            'secret' => self::SECRET,
            'apply_target_root' => $this->target,
            'apply_sessions_enabled' => true,
            // Make the real CLI split the large file into several MIME parts.
            'max_frame_bytes' => 128,
            // Make those parts cross real HTTP request boundaries as well as
            // the multipart reader's bounded body-piece boundary.
            'max_upload_parts' => 2,
            'max_commit_steps' => 1,
            'request_log' => $this->request_log,
            'drop_upload_response_marker' => $this->drop_upload_response_marker,
            'pause_after_upload_marker' => $this->pause_after_upload_marker,
            'resume_upload_response_marker' => $this->resume_upload_response_marker,
            'reject_upload_marker' => $this->reject_upload_marker,
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
require_once '{$root}/packages/reprint-exporter/src/class-multipart-stream-input.php';
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
    && is_string(\$config['pause_after_upload_marker'] ?? null)
    && \$config['pause_after_upload_marker'] !== ''
    && !file_exists(\$config['pause_after_upload_marker'])) {
    ob_start();
    (new Site_Export_HTTP_Server(['staged' => \$config]))->handle_request();
    ob_end_clean();
    file_put_contents(\$config['pause_after_upload_marker'], "accepted before source edit\\n");
    \$resume_marker = \$config['resume_upload_response_marker'] ?? null;
    \$deadline = microtime(true) + 15;
    while (is_string(\$resume_marker) && !file_exists(\$resume_marker) && microtime(true) < \$deadline) {
        usleep(10000);
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
