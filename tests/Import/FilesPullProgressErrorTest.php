<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only progress observer lives with its endpoint test.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote literal filesystem paths in the generated test router.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Records the first progress update for the file after the source error. */
class FileErrorProgressClient extends \ImportClient {
    public ?array $next_file_progress = null;

    public function output_progress(array $data, bool $force = false): void {
        parent::output_progress($data, $force);
        if (
            $this->next_file_progress === null
            && basename($data['path'] ?? '') === 'b.bin'
            && isset($data['progress']['current_file'])
        ) {
            $this->next_file_progress = $data['progress']['current_file'];
        }
    }
}

class FilesPullProgressErrorTest extends TestCase {
    /** @dataProvider interruptedResponses */
    public function testNextFileHasItsOwnProgressAfterSourceFileDisappears(bool $interrupt_response): void {
        $root = sys_get_temp_dir() . '/file-progress-error-' . bin2hex(random_bytes(6));
        mkdir($root . '/source', 0700, true);
        $source = realpath($root . '/source');
        $file_size = 64 * 1024 + 1;
        foreach (['a.bin', 'b.bin'] as $name) {
            file_put_contents($source . '/' . $name, str_repeat($name[0], $name === 'a.bin' ? 32769 : $file_size));
        }
        $router = $root . '/router.php';
        // Model a source file removed after its first chunk has been read.
        // The real producer detects the missing file on its next read.
        file_put_contents($router, '<?php putenv("REPRINT_SERVER_TEST_MODE=1");'
            . 'function test_hook_before_file_chunk($path, $offset, &$data) {'
            . 'if (basename($path) === "a.bin" && $offset === 0) { unlink($path); }'
            . '}'
            // Flush the data but omit completion once, after the directory part.
            . ( $interrupt_response
                ? 'function test_hook_before_completion($status, $gz, $boundary) { if (!file_exists(__DIR__ . "/cut-once")) { file_put_contents(__DIR__ . "/cut-once", "1"); $gz->finish(); exit; } }'
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
            $client = new FileErrorProgressClient($url, $root . '/state', $root . '/local');
            \write_current_pull_state($client, [
                'preflight' => ['data' => ['ok' => true, 'wp_detect' => ['roots' => [['path' => $source]]]], 'http_code' => 200],
                'active_resumable_command' => ['command_name' => 'files-pull', 'completion_state' => 'in_progress', 'current_stage' => 'fetch'],
            ]);
            $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
            $list_handle = fopen($list_file, 'wb');
            $append = ( new \ReflectionClass($client) )->getMethod('append_to_fetch_list');
            foreach (['a.bin', 'b.bin'] as $name) {
                $append->invoke($client, $source . '/' . $name, 'file', filesize($source . '/' . $name), $list_handle);
            }
            if ($interrupt_response) {
                mkdir($source . '/c-directory');
                $append->invoke($client, $source . '/c-directory', 'dir', 0, $list_handle);
            }
            fclose($list_handle);
            $download = ( new \ReflectionClass($client) )->getMethod('fetch_files_from_list');
            for ($request = 0; $request < 10; ++$request) {
                try {
                    if ($download->invoke($client, $list_file)) {
                        break;
                    }
                } catch (\Reprint\Importer\TransientInterruptionException $exception) {
                    // The CLI exits 3 here. This caller explicitly starts the next attempt.
                    $this->assertTrue($interrupt_response);
                }
                // A broken response may have delivered directory or error parts after its last saved cursor.
                // The next request replays those paths in this same client object.
                $reporter = ( new \ReflectionClass(\ImportClient::class) )
                    ->getProperty('progress_reporter')->getValue($client);
                $reloaded = new \Reprint\Importer\ProgressReporter($root . '/unused-progress.json');
                $reloaded->load_file_list($list_file, $client->get_state()->fetch);
                $this->assertSame(
                    $reloaded->get_file_details()['items'],
                    $reporter->get_file_details()['items'],
                    'A partial response must leave live counts at the saved cursor before replay.'
                );
            }
            $this->assertLessThan(10, $request, 'The two-file download did not finish within ten requests.');
            $this->assertFileDoesNotExist($source . '/a.bin');
            $this->assertStringContainsString('type=file_missing', file_get_contents($root . '/state/audit.log'));
            $this->assertNotNull($client->next_file_progress);
            $this->assertSame($source . '/b.bin', base64_decode($client->next_file_progress['path_b64']));
            $this->assertSame($file_size, $client->next_file_progress['bytes_total']);
            $this->assertGreaterThan(0, $client->next_file_progress['bytes_done']);
            $reflection = new \ReflectionClass(\ImportClient::class);
            $reporter = $reflection->getProperty('progress_reporter')->getValue($client);
            $before_resume = $reporter->get_file_details();
            $this->assertSame($file_size, $before_resume['bytes']['done']);
            $resumed = new \ImportClient($url, $root . '/state', $root . '/local');
            $reflection->getProperty('state')->setValue($resumed, $reflection->getMethod('load_state')->invoke($resumed));
            $resumed_reporter = $reflection->getProperty('progress_reporter')->getValue($resumed);
            $resumed_reporter->load_file_list($list_file, $resumed->get_state()->fetch);
            $this->assertSame($before_resume, $resumed_reporter->get_file_details(), 'Resume must not count the missing source file as completed bytes.');

            $this->assertSame(
                hash_file('sha256', $source . '/b.bin'),
                hash_file('sha256', $root . '/local' . $source . '/b.bin')
            );
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->remove_directory($root);
        }
    }

    public static function interruptedResponses(): array {
        return [[false], [true]];
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
