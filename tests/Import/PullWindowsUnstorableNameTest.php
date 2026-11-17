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

    public function testIndexLeavesOutNamesWindowsCannotStore(): void
    {
        $newline_name = "/site/file\nwith\nnewlines.txt";
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        // The source reports Unix paths, as a Linux host does.
        $client->get_state()->set_preflight_record([
            'data' => ['path_format' => 'unix', 'wp_detect' => ['roots' => [['path' => '/site']]]],
        ]);
        $source_paths = ['/site/CON', '/site/bad:dir', '/site/bad:dir/inner.txt', $newline_name, '/site/good.txt'];
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'index_batch'], 'body' => json_encode([
                ['path' => base64_encode('/site/CON'), 'type' => 'link', 'target' => base64_encode('good.txt')],
                ['path' => base64_encode('/site/bad:dir'), 'type' => 'dir'],
                ['path' => base64_encode('/site/bad:dir/inner.txt'), 'type' => 'file', 'size' => 5],
                ['path' => base64_encode($newline_name), 'type' => 'file', 'size' => 7],
                ['path' => base64_encode('/site/good.txt'), 'type' => 'file', 'size' => 4],
            ])],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));

        ( new \ReflectionMethod($client, 'fetch_next_remote_index') )->invoke($client);

        $indexed_paths = array_map(static function (string $line): string {
            return base64_decode(json_decode($line, true)['path']);
        }, file($client->pull_state_directory . '/remote-index.next.jsonl', FILE_IGNORE_NEW_LINES));
        $audit_log = file_get_contents($client->state_dir . '/audit.log');
        if (DIRECTORY_SEPARATOR !== '\\') {
            // Linux and macOS clients store every name.
            $this->assertSame($source_paths, $indexed_paths);
            $this->assertStringNotContainsString('WINDOWS UNSTORABLE NAME', $audit_log);
            return;
        }
        // A path left out of the index is never downloaded, so later pulls
        // and source deletions have nothing local to act on.
        $this->assertSame(['/site/good.txt'], $indexed_paths);
        foreach ([
            '"/site/CON": the name "CON" uses the reserved device name CON',
            '"/site/bad:dir": the name "bad:dir" contains the character ":"',
            '"/site/bad:dir/inner.txt": the name "bad:dir" contains the character ":"',
            '"/site/file\\nwith\\nnewlines.txt": the name "file\\nwith\\nnewlines.txt" contains the control character 0x0A',
        ] as $skip) {
            $this->assertStringContainsString('WINDOWS UNSTORABLE NAME | skipped ' . $skip, $audit_log);
        }
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
