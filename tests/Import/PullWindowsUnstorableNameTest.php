<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * A Unix source can hold names that Windows cannot store. A Windows client
 * skips them and keeps pulling. Linux and macOS clients store them unchanged.
 */
final class PullWindowsUnstorableNameTest extends TestCase
{
    private const SOURCE_CTIME = 1700000000;

    private string $root;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pull-unstorable-name-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0700, true);
        $this->root = realpath($this->root);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/pull-file-error-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes, $this->root);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->root)) {
            $this->remove_tree($this->root);
        }
    }

    public function testFetchSkipsNamesWindowsCannotStoreAndCompletesTheRest(): void
    {
        $client = $this->pull_source_once();
        $newline_name = "/site/file\nwith\nnewlines.txt";
        $local_site = $this->root . '/files/site';
        $this->assertSame('good', file_get_contents($local_site . '/good.txt'), 'A stored name after a skipped one is still pulled.');
        $remote_index_entries = [];
        foreach (file($client->pull_state_directory . '/remote-index.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true);
            $remote_index_entries[base64_decode($entry['path'])] = $entry['type'];
        }
        // Skipped paths stay in the remote index, so a delta pull compares
        // them like any other path instead of fetching them every time.
        $this->assertSame([
            '/site/CON' => 'link',
            '/site/bad:dir' => 'dir',
            '/site/bad:dir/inner.txt' => 'file',
            $newline_name => 'file',
            '/site/good.txt' => 'file',
        ], $remote_index_entries);
        $local_index_paths = array_map(static function (string $line): string {
            return base64_decode(json_decode($line, true)['path']);
        }, file(dirname($client->pull_state_directory) . '/local_index.jsonl', FILE_IGNORE_NEW_LINES));
        $audit_log = file_get_contents($client->state_dir . '/audit.log');

        if (DIRECTORY_SEPARATOR === '\\') {
            sort($local_index_paths);
            $this->assertSame(['site/good.txt'], $local_index_paths);
            $this->assertStringContainsString(
                'WINDOWS UNSTORABLE NAME | skipped file "/site/file\nwith\nnewlines.txt": the name "file\nwith\nnewlines.txt" contains the control character 0x0A',
                $audit_log
            );
            $this->assertStringContainsString(
                'WINDOWS UNSTORABLE NAME | skipped dir "/site/bad:dir": the name "bad:dir" contains the character ":"',
                $audit_log
            );
            $this->assertStringContainsString(
                'WINDOWS UNSTORABLE NAME | skipped link "/site/CON": the name "CON" uses the reserved device name CON',
                $audit_log
            );
            $this->assertSame(['good.txt'], array_values(array_diff(scandir($local_site), ['.', '..'])));
            return;
        }

        // Unix clients store every name unchanged.
        $this->assertSame('inner', file_get_contents($local_site . '/bad:dir/inner.txt'));
        $this->assertSame('newline', file_get_contents($this->root . '/files' . $newline_name));
        $this->assertSame('good.txt', readlink($local_site . '/CON'));
        $this->assertStringNotContainsString('WINDOWS UNSTORABLE NAME', $audit_log);
    }

    /**
     * A delta pull compares skipped paths like stored ones. An unchanged
     * skipped path is not fetched again, a changed one is fetched and skipped
     * again, and a source deletion removes only its remote index entry.
     */
    public function testDeltaPullFetchesOnlyChangedSkippedPathsAndForgetsDeletedOnes(): void
    {
        $this->pull_source_once();
        $newline_name = "/site/file\nwith\nnewlines.txt";
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        \write_current_pull_state($client, [
            'preflight' => ['http_code' => 200, 'data' => [
                'ok' => true,
                'path_format' => 'unix',
                'wp_detect' => ['roots' => [['path' => '/site']]],
            ]],
            'active_resumable_command' => ['command_name' => 'files-pull', 'completion_state' => 'in_progress', 'current_stage' => 'diff'],
            'follow_symlinks' => false,
        ]);
        // The source deleted bad:dir and changed the newline file.
        $next_remote_index = [
            ['/site/CON', self::SOURCE_CTIME, 0, 'link'],
            [$newline_name, self::SOURCE_CTIME + 1, 8, 'file'],
            ['/site/good.txt', self::SOURCE_CTIME, 4, 'file'],
        ];
        file_put_contents($client->pull_state_directory . '/remote-index.next.jsonl', implode('', array_map(
            static function (array $entry): string {
                return json_encode(['path' => base64_encode($entry[0]), 'ctime' => $entry[1], 'size' => $entry[2], 'type' => $entry[3]]) . "\n";
            },
            $next_remote_index
        )));

        $diff = new \ReflectionMethod(\ImportClient::class, 'compare_remote_indexes_and_build_fetch_list');
        $this->assertTrue($diff->invoke($client));
        $journal = ( new \ReflectionProperty(\ImportClient::class, 'pull_index_journal') )->getValue($client);
        $journal->apply_pending_records('unix');

        $fetch_paths = array_map(static function (string $line): string {
            return base64_decode(json_decode($line, true)['path']);
        }, file($client->pull_state_directory . '/fetch-list.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame([$newline_name], $fetch_paths);
        $remote_index_paths = array_map(static function (string $line): string {
            return base64_decode(json_decode($line, true)['path']);
        }, file($client->pull_state_directory . '/remote-index.jsonl', FILE_IGNORE_NEW_LINES));
        sort($remote_index_paths, SORT_STRING);
        $this->assertSame(['/site/CON', $newline_name, '/site/good.txt'], $remote_index_paths);
        $this->assertFileDoesNotExist($this->root . '/files/site/bad:dir');
        $this->assertSame('good', file_get_contents($this->root . '/files/site/good.txt'));
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertStringContainsString(
                'WINDOWS UNSTORABLE NAME | source deleted "/site/bad:dir", which was never stored locally',
                file_get_contents($client->state_dir . '/audit.log')
            );
        }
    }

    /**
     * Runs one first-pull fetch of a Unix source holding names Windows cannot store.
     *
     * The source sends a directory and its file, a file split over two parts,
     * a link named after a device, and one ordinary file.
     */
    private function pull_source_once(): \ImportClient
    {
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        // The source reports Unix paths, as a Linux host does.
        $client->get_state()->set_preflight_record(['data' => ['path_format' => 'unix']]);
        file_put_contents($client->pull_state_directory . '/remote-index.jsonl', '');
        $newline_name = "/site/file\nwith\nnewlines.txt";
        // The source sends paths in the sorted order of the fetch list.
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/site/CON'),
                'X-Symlink-Target' => base64_encode('good.txt'), 'X-Symlink-Ctime' => (string) self::SOURCE_CTIME]],
            $this->directory_part('/site/bad:dir'),
            $this->file_part('/site/bad:dir/inner.txt', 'inner', true, true, 5),
            // One file in two parts: the second part must not open a file either.
            $this->file_part($newline_name, 'new', true, false, 7),
            $this->file_part($newline_name, 'line', false, true, 7),
            $this->file_part('/site/good.txt', 'good', true, true, 4),
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));

        $this->assertTrue(( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null));
        $journal = ( new \ReflectionProperty(\ImportClient::class, 'pull_index_journal') )->getValue($client);
        $journal->apply_pending_records('unix');
        return $client;
    }

    /**
     * Builds one file part.
     *
     * @return array{headers: array<string,string>, body: string}
     */
    private function file_part(string $remote_absolute_path, string $body, bool $is_first, bool $is_last, int $file_size): array
    {
        return ['headers' => [
            'X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode($remote_absolute_path),
            'X-First-Chunk' => $is_first ? '1' : '0',
            'X-Last-Chunk' => $is_last ? '1' : '0',
            'X-File-Size' => (string) $file_size,
            'X-File-Ctime' => (string) self::SOURCE_CTIME,
        ], 'body' => $body];
    }

    /**
     * Builds one directory part.
     *
     * @return array{headers: array<string,string>}
     */
    private function directory_part(string $remote_absolute_path): array
    {
        return ['headers' => [
            'X-Chunk-Type' => 'directory',
            'X-Directory-Path' => base64_encode($remote_absolute_path),
            'X-Directory-Ctime' => (string) self::SOURCE_CTIME,
        ]];
    }

    /** Remove test paths without following links. */
    private function remove_tree(string $path): void
    {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->remove_tree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
