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
    public string $observation_file;
    public string $stop_before_file = '';

    public function save_state(): void {
        parent::save_state();
        $cursor = json_decode(base64_decode($this->get_state()->fetch->cursor ?? ''), true);
        if (
            ( in_array($this->stop_after, ['file', 'source_grew', 'source_shrank'], true) && basename(base64_decode($cursor['path'] ?? '')) === 'a.bin' && ( $cursor['bytes'] ?? 0 ) === 0 )
            || ( in_array($this->stop_after, ['file_after_error', 'file_after_directory', 'file_after_symlink', 'file_after_missing'], true) && basename(base64_decode($cursor['path'] ?? '')) === 'b.bin' && ( $cursor['bytes'] ?? 0 ) === 0 )
            || ( $this->stop_after === 'part' && basename(base64_decode($cursor['path'] ?? '')) === 'b.bin' && ( $cursor['bytes'] ?? 0 ) > 0 )
        ) {
            $this->stop_at_current_progress();
        }
    }

    public function audit_log(string $message, bool $to_console = true): void {
        parent::audit_log($message, $to_console);
        // Stop after production unlink(), before the next batch offset is saved.
        if ($this->stop_after === 'batch_removed' && strpos($message, '| fetch batch complete') !== false) {
            $this->stop_at_current_progress();
        }
        // Stop as a chosen file starts, after parts whose cursor is unsaved.
        // A full stream buffer can write their log records before a save, so
        // flush them: resume must then replay records past its saved cursor.
        if ($this->stop_after === 'between_saves' && strpos($message, '/' . $this->stop_before_file . ' (remote_size=') !== false) {
            ( new \ReflectionClass(\ImportClient::class) )
                ->getProperty('pull_index_journal')->getValue($this)->flush();
            $this->stop_at_current_progress();
        }
        // Ctrl-C saves the state held in memory, which may be newer than the
        // last saved cursor.
        if ($this->stop_after === 'shutdown_between_saves' && strpos($message, '/' . $this->stop_before_file . ' (remote_size=') !== false) {
            $this->handle_shutdown(SIGTERM);
        }
    }
    /** Records live counters before process death without changing the pull checkpoint. */
    private function stop_at_current_progress(): void {
        $reporter = ( new \ReflectionClass(\ImportClient::class) )
            ->getProperty('progress_reporter')->getValue($this);
        file_put_contents($this->observation_file, json_encode($reporter->get_file_details()));
        posix_kill(getmypid(), SIGKILL);
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
        // The client saves its cursor at the end of every response, but not
        // after every file. Ending the response after a file's last part makes
        // it save the cursor that marks that file finished.
        $response_end_file = [
            'file' => 'a.bin', 'source_grew' => 'a.bin', 'source_shrank' => 'a.bin',
            'file_after_error' => 'b.bin', 'file_after_directory' => 'b.bin',
            'file_after_symlink' => 'b.bin', 'file_after_missing' => 'b.bin',
        ][$boundary] ?? '';
        [$server, $url] = $this->start_source_server($root, $response_end_file, $boundary === 'file_after_error');
        $child_pid = null;
        try {
            $client = new StopDuringFileProgressClient($url, $root . '/state', $root . '/local', ['allow_http' => true]);
            $client->observation_file = $root . '/before-stop.json';
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
            if (in_array($boundary, ['file_after_directory', 'file_after_symlink', 'file_after_missing'], true)) {
                unlink($source . '/a.bin');
                if ($boundary === 'file_after_directory') {
                    mkdir($source . '/a.bin');
                } elseif ($boundary === 'file_after_symlink') {
                    symlink('b.bin', $source . '/a.bin');
                }
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

            $resumed = new \ImportClient($url, $root . '/state', $root . '/local', ['allow_http' => true]);
            $reflection = new \ReflectionClass($resumed);
            $reflection->getProperty('state')->setValue($resumed, $reflection->getMethod('load_state')->invoke($resumed));
            $this->assertSame(0, $resumed->get_state()->fetch->offset, 'The first batch is still active.');
            $cursor = json_decode(base64_decode($resumed->get_state()->fetch->cursor), true);
            $before_stop = json_decode(file_get_contents($root . '/before-stop.json'), true);
            $reporter = $reflection->getProperty('progress_reporter')->getValue($resumed);
            $reporter->load_file_list($list_file, $resumed->get_state()->fetch);
            $this->assertSame(
                $before_stop['items'],
                $reporter->get_file_details()['items'],
                'Resume alone must not change the completed path count.'
            );
            $expected_file = ['file' => '/a.bin', 'part' => '/b.bin', 'batch_removed' => '/c.bin', 'file_after_error' => '/b.bin', 'file_after_directory' => '/b.bin', 'file_after_symlink' => '/b.bin', 'file_after_missing' => '/b.bin', 'source_grew' => '/a.bin', 'source_shrank' => '/a.bin'][$boundary];
            $this->assertSame($source . $expected_file, base64_decode($cursor['path']));
            if ($boundary === 'batch_removed') {
                $this->assertFileDoesNotExist($resumed->get_state()->fetch->batch_file);
            }
            $this->assertSame($boundary === 'part', $cursor['bytes'] > 0);
            $this->download_list($resumed, $list_file);
            $resumed->write_progress_file();
            $snapshot = json_decode(file_get_contents($root . '/state/progress.json'), true);
            $this->assertSame(2 * $file_size + ( strpos($boundary, 'file_after_') === 0 ? 0 : $source_file_size ), $snapshot['progress']['bytes']['done']);
            $this->assertSame(3, $snapshot['progress']['items']['done']);
            foreach (( strpos($boundary, 'file_after_') === 0 ? ['b.bin', 'c.bin'] : ['a.bin', 'b.bin', 'c.bin'] ) as $name) {
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
        return [['file'], ['part'], ['batch_removed'], ['file_after_error'], ['file_after_directory'], ['file_after_symlink'], ['file_after_missing'], ['source_grew'], ['source_shrank']];
    }

    /**
     * A process can die after parts whose cursor it has not saved yet.
     * Resume downloads those parts again and still finishes with every file
     * whole and recorded once in the local index.
     */
    public function testProcessDeathBetweenSavesRepeatsTheUnsavedParts(): void {
        // a.bin spans five 16 KiB parts, and each of them is saved. The
        // one-part files after it are not.
        $file_sizes = ['a.bin' => 64 * 1024 + 1, 'b.bin' => 1024, 'c.bin' => 1024, 'd.bin' => 1024, 'e.bin' => 1024, 'f.bin' => 1024];
        $this->with_source_files($file_sizes, function (string $root, string $url, string $source) use ($file_sizes): void {
            [$resumed, $list_file, $cursor] = $this->pull_until_death_before($root, $url, $source, $file_sizes, 16384, 'f.bin');
            $this->assertSame($source . '/a.bin', base64_decode($cursor['path']), 'The last save finished a.bin.');
            $this->assertSame(0, $cursor['bytes']);
            $logged_names = [];
            foreach (file($resumed->pull_state_directory . '/index.wal', FILE_IGNORE_NEW_LINES) as $line) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $logged_names[] = basename(base64_decode($record['remote_absolute_path_b64']));
            }
            $this->assertContains('e.bin', $logged_names, 'The log holds a record past the saved cursor.');

            $this->download_list($resumed, $list_file);
            $local_index_sizes = [];
            foreach (file(dirname($resumed->pull_state_directory) . '/local_index.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $local_index_sizes[] = [basename(base64_decode($entry['path'])), $entry['size']];
            }
            sort($local_index_sizes);
            $expected_sizes = [];
            foreach ($file_sizes as $name => $size) {
                $expected_sizes[] = [$name, $size];
                $this->assertSame(
                    hash_file('sha256', $source . '/' . $name),
                    hash_file('sha256', $root . '/local' . $source . '/' . $name)
                );
            }
            $this->assertSame($expected_sizes, $local_index_sizes);
        });
    }

    /**
     * Ctrl-C after the last part of a file that spans several parts saves the
     * state in memory. The resumed pull must still count that file's bytes.
     */
    public function testShutdownAfterAFileThatSpansPartsKeepsItsBytes(): void {
        $file_sizes = ['a.bin' => 64 * 1024 + 1, 'b.bin' => 1024];
        $this->with_source_files($file_sizes, function (string $root, string $url, string $source) use ($file_sizes): void {
            [$resumed, $list_file] = $this->pull_until_death_before($root, $url, $source, $file_sizes, 16384, 'b.bin', 'shutdown_between_saves');
            $reporter = ( new \ReflectionClass($resumed) )->getProperty('progress_reporter')->getValue($resumed);
            $reporter->load_file_list($list_file, $resumed->get_state()->fetch);
            $this->download_list($resumed, $list_file);
            $resumed->write_progress_file();
            $snapshot = json_decode(file_get_contents($root . '/state/progress.json'), true);
            $this->assertSame(array_sum($file_sizes), $snapshot['progress']['bytes']['done']);
            $this->assertSame(2, $snapshot['progress']['items']['done']);
            foreach (array_keys($file_sizes) as $name) {
                $this->assertSame(
                    hash_file('sha256', $source . '/' . $name),
                    hash_file('sha256', $root . '/local' . $source . '/' . $name)
                );
            }
        });
    }

    /** One-part files are saved again once their parts reach the part limit. */
    public function testManyOnePartFilesAreSavedAfterThePartLimit(): void {
        $file_sizes = [];
        for ($index = 0; $index < 60; ++$index) {
            $file_sizes[sprintf('file-%02d.bin', $index)] = 1;
        }
        $this->with_source_files($file_sizes, function (string $root, string $url, string $source) use ($file_sizes): void {
            [, , $cursor] = $this->pull_until_death_before($root, $url, $source, $file_sizes, 16384, 'file-59.bin');
            $this->assertIsArray($cursor, 'A save happened before the process died.');
            $this->assertSame(0, $cursor['bytes'], 'The save follows a finished file.');
            // A save after every file would leave the cursor at file-58.bin.
            $this->assertNotContains(
                base64_decode($cursor['path']),
                [$source . '/file-58.bin', $source . '/file-59.bin'],
                'The last save came from the part limit, not from a finished file.'
            );
        });
    }

    /** Large one-part files are saved again once their bodies reach 16 MiB. */
    public function testLargeOnePartFilesAreSavedAfterSixteenMebibytes(): void {
        // Six 3 MiB files pass 16 MiB with the sixth. Each fits one 4 MiB part.
        // The count starts again after that save, so g.bin is not saved.
        $file_sizes = ['a.bin' => 3 << 20, 'b.bin' => 3 << 20, 'c.bin' => 3 << 20, 'd.bin' => 3 << 20, 'e.bin' => 3 << 20, 'f.bin' => 3 << 20, 'g.bin' => 1, 'h.bin' => 1];
        $this->with_source_files($file_sizes, function (string $root, string $url, string $source) use ($file_sizes): void {
            [, , $cursor] = $this->pull_until_death_before($root, $url, $source, $file_sizes, 4 << 20, 'h.bin');
            $this->assertIsArray($cursor, 'A save happened before the process died.');
            $this->assertSame($source . '/f.bin', base64_decode($cursor['path']));
            $this->assertSame(0, $cursor['bytes'], 'The save follows the finished f.bin.');
        });
    }

    /**
     * Creates the source files, runs the test against a real source router,
     * and removes everything afterwards.
     *
     * @param array<string,int> $file_sizes Source file names and sizes.
     * @param callable(string,string,string):void $test Receives the test
     *        directory, the source URL, and the source directory.
     */
    private function with_source_files(array $file_sizes, callable $test): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Process-death coverage requires PCNTL and POSIX.');
        }
        $root = sys_get_temp_dir() . '/file-progress-unsaved-' . bin2hex(random_bytes(6));
        mkdir($root . '/source', 0700, true);
        $source = realpath($root . '/source');
        foreach ($file_sizes as $name => $size) {
            file_put_contents($source . '/' . $name, str_repeat($name[0], $size));
        }
        [$server, $url] = $this->start_source_server($root, '', false);
        try {
            $test($root, $url, $source);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->remove_directory($root);
        }
    }

    /**
     * Pulls the files in a child process that dies as one file starts, then
     * loads the state that process saved.
     *
     * @param array<string,int> $file_sizes Source file names and sizes, in fetch order.
     * @param string $stop_before_file Name of the file whose start ends the child.
     * @param string $stop_after       How the child ends: killed, or after the shutdown handler.
     * @return array{0: \ImportClient, 1: string, 2: array<string,mixed>|null}
     *         Resuming client, fetch list path, and the saved fetch cursor.
     */
    private function pull_until_death_before(string $root, string $url, string $source, array $file_sizes, int $chunk_size, string $stop_before_file, string $stop_after = 'between_saves'): array {
        $client = new StopDuringFileProgressClient($url, $root . '/state', $root . '/local', ['allow_http' => true]);
        $client->observation_file = $root . '/before-stop.json';
        \write_current_pull_state($client, [
            'preflight' => ['data' => ['ok' => true, 'wp_detect' => ['roots' => [['path' => $source]]]], 'http_code' => 200],
            'active_resumable_command' => ['command_name' => 'files-pull', 'completion_state' => 'in_progress', 'current_stage' => 'fetch'],
        ]);
        $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
        $list_handle = fopen($list_file, 'wb');
        $append = ( new \ReflectionClass($client) )->getMethod('append_to_fetch_list');
        foreach ($file_sizes as $name => $size) {
            $append->invoke($client, $source . '/' . $name, 'file', $size, $list_handle);
        }
        fclose($list_handle);
        $child_pid = pcntl_fork();
        $this->assertNotSame(-1, $child_pid);
        if ($child_pid === 0) {
            $client->stop_after = $stop_after;
            $client->stop_before_file = $stop_before_file;
            $this->download_list($client, $list_file, $chunk_size);
            exit(1);
        }
        pcntl_waitpid($child_pid, $child_status);
        $this->assertTrue(pcntl_wifsignaled($child_status), 'The child must die as ' . $stop_before_file . ' starts.');
        $this->assertSame(SIGKILL, pcntl_wtermsig($child_status));

        $resumed = new \ImportClient($url, $root . '/state', $root . '/local', ['allow_http' => true]);
        $reflection = new \ReflectionClass($resumed);
        $reflection->getProperty('state')->setValue($resumed, $reflection->getMethod('load_state')->invoke($resumed));
        $saved_cursor = $resumed->get_state()->fetch->cursor;
        return [$resumed, $list_file, $saved_cursor === null ? null : json_decode(base64_decode($saved_cursor), true)];
    }

    /**
     * Starts the real source router.
     *
     * @param string $root               Test directory for the router and its log.
     * @param string $response_end_file  Name of the file whose last part ends a
     *                                   response, or an empty string.
     * @param bool   $unlink_first_file  Remove a.bin as its first part is read.
     * @return array{0: resource, 1: string} Server process and its URL.
     */
    private function start_source_server(string $root, string $response_end_file, bool $unlink_first_file): array {
        $router = $root . '/router.php';
        file_put_contents($router, strtr(<<<'PHP'
            <?php
            putenv('REPRINT_SERVER_TEST_MODE=1');
            $GLOBALS['response_may_continue'] = true;
            function test_hook_before_file_chunk($path, $offset, &$data) {
                if (UNLINK_FIRST_FILE && basename($path) === 'a.bin' && $offset === 0) {
                    unlink($path);
                    return;
                }
                clearstatcache(true, $path);
                if (basename($path) === RESPONSE_END_FILE && $offset + strlen($data) >= filesize($path)) {
                    $GLOBALS['response_may_continue'] = false;
                }
            }
            require AUTOLOAD_PATH;
            require EXPORT_PATH;
            ( new WordPress\Reprint\Server\HTTPServer(['budget_factory' => static function () {
                return new class (microtime(true), 15, PHP_INT_MAX, 0.8) extends WordPress\Reprint\Server\ResourceBudget {
                    public function has_remaining(): bool {
                        return $GLOBALS['response_may_continue'] && parent::has_remaining();
                    }
                };
            }]) )->handle_request();
            PHP, [
            'UNLINK_FIRST_FILE' => var_export($unlink_first_file, true),
            'RESPONSE_END_FILE' => var_export($response_end_file, true),
            'AUTOLOAD_PATH' => var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            'EXPORT_PATH' => var_export(dirname(__DIR__, 2) . '/packages/reprint-server/src/export.php', true),
        ]));
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
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return [$server, 'http://' . $address . '/'];
            }
            usleep(10000);
        }
        proc_terminate($server);
        proc_close($server);
        $this->fail('The source router did not accept connections: ' . file_get_contents($root . '/server.log'));
    }

    private function download_list(\ImportClient $client, string $list_file, int $chunk_size = 16384): void {
        $reflection = new \ReflectionClass($client);
        $reflection->getMethod('initialize_tuner')->invoke($client, [
            'tuning_config' => ['file_chunk_start' => $chunk_size, 'file_chunk_min' => $chunk_size, 'file_chunk_max' => $chunk_size],
        ]);
        $download = $reflection->getMethod('fetch_files_from_list');
        for ($request = 0; $request < 10; ++$request) {
            if ($download->invoke($client, $list_file)) {
                return;
            }
        }
        $this->fail('The download did not finish within ten requests.');
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
