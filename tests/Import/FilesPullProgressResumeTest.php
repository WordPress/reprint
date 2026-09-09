<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only stopping client lives with its process-death test.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote literal filesystem paths in the generated test router.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** A process may die after any saved multipart part, including inside a batch. */
class StopDuringFileProgressClient extends \ImportClient {
    public ?string $stop_after = null;

    public function save_state(): void {
        parent::save_state();
        $cursor = json_decode(base64_decode($this->get_state()->fetch->cursor ?? ''), true);
        if (
            ( in_array($this->stop_after, ['file', 'source_grew', 'source_shrank'], true) && basename(base64_decode($cursor['path'] ?? '')) === 'a.bin' && ( $cursor['bytes'] ?? 0 ) === 0 )
            || ( $this->stop_after === 'file_after_error' && basename(base64_decode($cursor['path'] ?? '')) === 'b.bin' && ( $cursor['bytes'] ?? 0 ) === 0 )
            || ( $this->stop_after === 'part' && basename(base64_decode($cursor['path'] ?? '')) === 'b.bin' && ( $cursor['bytes'] ?? 0 ) > 0 )
        ) {
            posix_kill(getmypid(), SIGKILL);
        }
    }

    public function audit_log(string $message, bool $to_console = true): void {
        parent::audit_log($message, $to_console);
        // Stop after production unlink(), before the next batch offset is saved.
        if ($this->stop_after === 'batch_removed' && strpos($message, '| fetch batch complete') !== false) {
            posix_kill(getmypid(), SIGKILL);
        }
    }
}

class FilesPullProgressResumeTest extends TestCase {
    /** @dataProvider interruptionBoundaries */
    public function testProcessDeathInsideABatchRetainsCompletedFileBytes(string $boundary): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Process-death coverage requires PCNTL and POSIX.');
        }
        $root = sys_get_temp_dir() . '/file-progress-resume-' . bin2hex(random_bytes(6));
        mkdir($root . '/source', 0700, true);
        $source = realpath($root . '/source');
        $file_size = 64 * 1024 + 1;
        foreach (['a.bin', 'b.bin', 'c.bin'] as $name) {
            file_put_contents($source . '/' . $name, str_repeat($name[0], $file_size));
        }
        $router = $root . '/router.php';
        file_put_contents($router, '<?php '
            . ( $boundary === 'file_after_error'
                ? 'putenv("REPRINT_SERVER_TEST_MODE=1"); function test_hook_before_file_chunk($path, $offset, &$data) { if (basename($path) === "a.bin" && $offset === 0) { unlink($path); } }'
                : '' )
            . ' require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-server/src/export.php', true)
            . '; (new WordPress\\Reprint\\Server\\HTTPServer())->handle_request();');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertIsResource($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $server = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
            $pipes
        );
        $this->assertIsResource($server);
        fclose($pipes[0]);
        $child_pid = null;
        try {
            $ready = false;
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $connection = @stream_socket_client('tcp://' . $address, $error_number, $error_message, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($ready, (string) file_get_contents($root . '/server.log'));
            $url = 'http://' . $address . '/?chunk_size=16384';
            $client = new StopDuringFileProgressClient($url, $root . '/state', $root . '/local');
            \write_current_pull_state($client, [
                'preflight' => ['data' => ['ok' => true, 'wp_detect' => ['roots' => [['path' => $source]]]], 'http_code' => 200],
                'active_resumable_command' => ['command_name' => 'files-pull', 'completion_state' => 'in_progress', 'current_stage' => 'fetch'],
            ]);
            $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
            $list_handle = fopen($list_file, 'wb');
            $append = ( new \ReflectionClass($client) )->getMethod('append_to_fetch_list');
            foreach (['a.bin', 'b.bin', 'c.bin'] as $name) {
                $append->invoke($client, $source . '/' . $name, 'file', $file_size, $list_handle);
            }
            fclose($list_handle);
            // Source content may change after indexing but before the download starts.
            $source_file_size = $file_size;
            if ($boundary === 'source_grew' || $boundary === 'source_shrank') {
                $source_file_size += $boundary === 'source_grew' ? 32768 : -32768;
                file_put_contents($source . '/a.bin', str_repeat('a', $source_file_size));
            }
            $child_pid = pcntl_fork();
            $this->assertNotSame(-1, $child_pid);
            if ($child_pid === 0) {
                $client->stop_after = $boundary;
                $this->download_list($client, $list_file);
                exit(1);
            }
            pcntl_waitpid($child_pid, $child_status);
            $child_pid = null;
            $this->assertTrue(pcntl_wifsignaled($child_status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($child_status));

            $resumed = new \ImportClient($url, $root . '/state', $root . '/local');
            $reflection = new \ReflectionClass($resumed);
            $reflection->getProperty('state')->setValue($resumed, $reflection->getMethod('load_state')->invoke($resumed));
            $this->assertSame(0, $resumed->get_state()->fetch->offset, 'The first batch is still active.');
            $cursor = json_decode(base64_decode($resumed->get_state()->fetch->cursor), true);
            $expected_file = ['file' => '/a.bin', 'part' => '/b.bin', 'batch_removed' => '/c.bin', 'file_after_error' => '/b.bin', 'source_grew' => '/a.bin', 'source_shrank' => '/a.bin'][$boundary];
            $this->assertSame($source . $expected_file, base64_decode($cursor['path']));
            if ($boundary === 'batch_removed') {
                $this->assertFileDoesNotExist($resumed->get_state()->fetch->batch_file);
            }
            $this->assertSame($boundary === 'part', $cursor['bytes'] > 0);
            $this->download_list($resumed, $list_file);
            $resumed->write_progress_file();
            $snapshot = json_decode(file_get_contents($root . '/state/progress.json'), true);
            $this->assertSame(2 * $file_size + ( $boundary === 'file_after_error' ? 0 : $source_file_size ), $snapshot['progress']['bytes']['done']);
            $this->assertSame(3, $snapshot['progress']['items']['done']);
            foreach (( $boundary === 'file_after_error' ? ['b.bin', 'c.bin'] : ['a.bin', 'b.bin', 'c.bin'] ) as $name) {
                $this->assertSame(
                    hash_file('sha256', $source . '/' . $name),
                    hash_file('sha256', $root . '/local' . $source . '/' . $name)
                );
            }
        } finally {
            if (is_int($child_pid) && $child_pid > 0) {
                posix_kill($child_pid, SIGKILL);
                pcntl_waitpid($child_pid, $child_status);
            }
            proc_terminate($server);
            proc_close($server);
            $this->remove_directory($root);
        }
    }

    public static function interruptionBoundaries(): array {
        return [['file'], ['part'], ['batch_removed'], ['file_after_error'], ['source_grew'], ['source_shrank']];
    }

    private function download_list(\ImportClient $client, string $list_file): void {
        $reflection = new \ReflectionClass($client);
        $download = $reflection->getMethod('fetch_files_from_list');
        for ($request = 0; $request < 10; ++$request) {
            if ($download->invoke($client, $list_file)) {
                return;
            }
        }
        $this->fail('The three-file download did not finish within ten requests.');
    }

    private function remove_directory(string $directory): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
