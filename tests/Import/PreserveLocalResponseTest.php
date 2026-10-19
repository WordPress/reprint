<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Receive planned updates and extra source parts through the real CLI and HTTP parser. */
final class PreserveLocalResponseTest extends TestCase
{
    private string $root;
    private string $source;
    private string $url;
    /** @var resource|null Local source process. */
    private $server = null;

    /** Start the real exporter, with optional source-chosen file-fetch replies. */
    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('These fixtures create Unix symlinks.');
        }
        $this->root = sys_get_temp_dir() . '/preserve-response-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/source', 0700, true);
        mkdir($this->root . '/files/site', 0700, true);
        $this->root = realpath($this->root);
        $this->source = $this->root . '/source';
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/preserve-local-response-router.php'],
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

    /** Stop the source before removing only this fixture's paths. */
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

    /**
     * A path skipped by the planner stays unchanged when the source returns it anyway.
     *
     * @dataProvider preserved_path_types
     * @param string $local_type Existing local path type.
     * @param string $received_type Source part type trying to replace it.
     */
    public function testSkippedPathsKeepTheirContentsAndTypes(string $local_type, string $received_type): void
    {
        file_put_contents($this->source . '/keep', 'source keep');
        file_put_contents($this->source . '/safe', 'safe');
        $local_path = $this->root . '/files/site/keep';
        if ($local_type === 'directory') {
            mkdir($local_path);
            file_put_contents($local_path . '/child', 'local child');
        } elseif ($local_type === 'link' || $local_type === 'dangling link') {
            if ($local_type === 'link') {
                file_put_contents($this->root . '/files/site/target', 'local target');
            }
            symlink('target', $local_path);
        } else {
            file_put_contents($local_path, 'local keep');
        }
        $parts = [];
        if ($received_type === 'file') {
            $parts[] = $this->file_part('keep', str_repeat('discard', 32768));
        } elseif ($received_type === 'directory') {
            $parts[] = ['headers' => ['X-Chunk-Type' => 'directory',
                'X-Directory-Path' => base64_encode($this->source . '/keep'), 'X-Directory-Ctime' => '1234567890']];
        } else {
            $parts[] = ['headers' => ['X-Chunk-Type' => 'symlink',
                'X-Symlink-Path' => base64_encode($this->source . '/keep'), 'X-Symlink-Target' => base64_encode('safe')]];
        }
        $parts[] = $this->file_part('safe', 'safe');
        $this->responses([$parts]);
        $this->pull();

        if ($local_type === 'directory') {
            $this->assertDirectoryExists($local_path);
            $this->assertSame('local child', file_get_contents($local_path . '/child'));
        } elseif ($local_type === 'link' || $local_type === 'dangling link') {
            $this->assertTrue(is_link($local_path));
            $this->assertSame('target', readlink($local_path));
            if ($local_type === 'link') {
                $this->assertSame('local target', file_get_contents($local_path));
            } else {
                $this->assertFileDoesNotExist($this->root . '/files/site/target');
            }
        } else {
            $this->assertFalse(is_link($local_path));
            $this->assertSame(strlen('local keep'), filesize($local_path));
            $this->assertSame('local keep', file_get_contents($local_path));
        }
        $this->assertSame('safe', file_get_contents($this->root . '/files/site/safe'));
        $requests = $this->requests();
        $this->assertSame([$this->source . '/safe'], array_column($requests[0]['paths'], 'path'));
    }

    /** @return array<string,list<string>> Local and received path types. */
    public static function preserved_path_types(): array
    {
        return [
            'file received over file' => ['file', 'file'],
            'file received over directory' => ['directory', 'file'],
            'file received over link' => ['link', 'file'],
            'file received over dangling link' => ['dangling link', 'file'],
            'directory received over file' => ['file', 'directory'],
            'directory received over dangling link' => ['dangling link', 'directory'],
            'link received over file' => ['file', 'symlink'],
        ];
    }

    /**
     * Selected updates still replace earlier downloads, including across HTTP request boundaries.
     *
     * @dataProvider update_boundaries
     * @param bool $resume End the first reply with a partial file and continue in another request.
     */
    public function testSelectedUpdatesContinuePastSkippedParts(bool $resume): void
    {
        file_put_contents($this->root . '/files/site/keep', 'local keep');
        foreach (['a', 'b', 'keep'] as $name) {
            file_put_contents($this->source . '/' . $name, 'old ' . $name);
        }
        $this->pull();
        $first = str_repeat('a', 65536);
        $last = str_repeat('z', 65536);
        file_put_contents($this->source . '/a', $first . $last);
        file_put_contents($this->source . '/b', 'updated b');
        $initial = $this->file_part('a', $first, true, false, strlen($first . $last));
        $initial['headers']['X-Cursor'] = base64_encode(json_encode([
            'phase' => 'streaming', 'path' => base64_encode($this->source . '/a'),
            'ctime' => 1234567890, 'bytes' => strlen($first),
        ]));
        // The extra path sorts after both requested files. Discarding it must not
        // consume the pending request entry for b or close the retained handle for a.
        $this->pull(true);
        $remaining = [
            $this->file_part('keep', str_repeat('discard', 32768)),
            ['headers' => ['X-Chunk-Type' => 'error'], 'body' => json_encode([
                'error_type' => 'file_missing', 'path' => base64_encode($this->source . '/keep'), 'message' => 'source missing',
            ])],
            $this->file_part('a', $last, false, true, strlen($first . $last)),
            $this->file_part('b', 'updated b'),
        ];
        $this->responses($resume ? [[$initial], $remaining] : [array_merge([$initial], $remaining)], $resume);
        $this->pull();
        $this->assertSame(hash('sha256', $first . $last), hash_file('sha256', $this->root . '/files/site/a'));
        $this->assertSame('updated b', file_get_contents($this->root . '/files/site/b'));
        $this->assertSame('local keep', file_get_contents($this->root . '/files/site/keep'));
        foreach ($this->requests() as $request) {
            $this->assertSame([$this->source . '/a', $this->source . '/b'], array_column($request['paths'], 'path'));
        }
        $progress = array_values(array_filter(array_map(static function (string $line): array {
            return json_decode($line, true);
        }, file($this->root . '/pull.log', FILE_IGNORE_NEW_LINES)), static function (array $record): bool {
            return ( $record['event'] ?? '' ) === 'complete' && ( $record['command'] ?? '' ) === 'files-pull';
        }));
        $this->assertSame(strlen($first . $last . 'updated b'), end($progress)['progress']['bytes']['done']);
        $this->assertSame(2, end($progress)['progress']['items']['done']);
    }

    /** @return array<string,list<bool>> Same-request and next-request continuation. */
    public static function update_boundaries(): array
    {
        return ['one reply' => [false], 'next request' => [true]];
    }

    /** A path from the next batch cannot be written early and is accepted in its own request. */
    public function testEachBatchAcceptsOnlyItsOwnPaths(): void
    {
        file_put_contents($this->source . '/a', 'first');
        file_put_contents($this->source . '/b', 'late');
        $this->responses([
            [$this->file_part('b', 'early'), $this->file_part('a', 'first')],
            [$this->file_part('b', 'late')],
        ]);
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        $client->get_state()->set_preflight_record(['data' => ['ok' => true,
            'wp_detect' => ['roots' => [['path' => $this->source]]]], 'http_code' => 200]);
        $reflection = new \ReflectionClass($client);
        $reflection->getMethod('initialize_credential')->invoke($client, true, ['secret' => 'test-secret']);
        $reflection->getProperty('fs_root_nonempty_behavior')->setValue($client, 'preserve-local');
        // The existing tuner permits a one-entry batch even when that entry exceeds its byte budget.
        $reflection->getMethod('initialize_tuner')->invoke($client, ['tuning_config' => [
            'file_fetch_request_body_min' => 1, 'file_fetch_request_body_max' => 1,
        ]]);
        $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
        file_put_contents($list_file,
            json_encode(['path' => base64_encode($this->source . '/a'), 'size' => 5]) . "\n" .
            json_encode(['path' => base64_encode($this->source . '/b'), 'size' => 4]) . "\n");
        $download = $reflection->getMethod('fetch_files_from_list');
        $this->assertFalse($download->invoke($client, $list_file));
        $this->assertNull($client->get_state()->fetch->batch_file, 'The first request completed its batch.');
        $this->assertFileDoesNotExist($this->root . '/files' . $this->source . '/b');
        $this->assertSame('first', file_get_contents($this->root . '/files' . $this->source . '/a'));
        $this->assertTrue($download->invoke($client, $list_file));
        $this->assertSame('late', file_get_contents($this->root . '/files' . $this->source . '/b'));
        $requests = $this->requests();
        $this->assertSame([$this->source . '/a'], array_column($requests[0]['paths'], 'path'));
        $this->assertSame([$this->source . '/b'], array_column($requests[1]['paths'], 'path'));
    }

    /**
     * A selected update may change a previously downloaded path's type.
     *
     * @dataProvider selected_type_changes
     * @param string $old_type Source type downloaded by the first pull.
     * @param string $new_type Source type selected by the next pull.
     */
    public function testSelectedUpdatesCanChangePathType(string $old_type, string $new_type): void
    {
        file_put_contents($this->source . '/target', 'target bytes');
        $source_path = $this->source . '/item';
        if ($old_type === 'directory') {
            mkdir($source_path);
        } elseif ($old_type === 'symlink') {
            symlink('target', $source_path);
        } else {
            file_put_contents($source_path, 'old file');
        }
        $this->pull();
        $this->pull(true);
        if ($old_type === 'directory') {
            rmdir($source_path);
        } else {
            unlink($source_path);
        }
        if ($new_type === 'directory') {
            mkdir($source_path);
        } elseif ($new_type === 'symlink') {
            symlink('target', $source_path);
        } else {
            file_put_contents($source_path, 'updated file');
        }
        $this->pull();
        $local_path = $this->root . '/files/site/item';
        if ($new_type === 'directory') {
            $this->assertDirectoryExists($local_path);
            $this->assertFalse(is_link($local_path));
        } elseif ($new_type === 'symlink') {
            $this->assertTrue(is_link($local_path));
            $this->assertSame('target', readlink($local_path));
            $this->assertSame('target bytes', file_get_contents($local_path));
        } else {
            $this->assertFalse(is_link($local_path));
            $this->assertSame('updated file', file_get_contents($local_path));
        }
    }

    /** A directory-to-link update must keep local files until the user removes them. */
    public function testDirectoryToLinkUpdateKeepsLocalFiles(): void
    {
        file_put_contents($this->source . '/target', 'target bytes');
        mkdir($this->source . '/uploads');
        $this->pull();
        $local_directory = $this->root . '/files/site/uploads';
        file_put_contents($local_directory . '/photo.jpg', 'local photo');

        $this->pull(true);
        rmdir($this->source . '/uploads');
        symlink('target', $this->source . '/uploads');
        $this->pull();
        $this->assertFalse(is_link($local_directory), 'The local directory still contains a local-only photo.');
        $this->assertSame('local photo', file_get_contents($local_directory . '/photo.jpg'));
        $this->assertSame('target bytes', file_get_contents($this->root . '/files/site/target'));
        $this->assertStringContainsString('PRESERVE-LOCAL skip symlink (cannot remove directory without deleting contents)',
            file_get_contents($this->root . '/state/audit.log'));

        // A skipped replacement must remain available after the user empties the directory.
        unlink($local_directory . '/photo.jpg');
        $this->pull(true);
        $this->pull();
        $this->assertTrue(is_link($local_directory));
        $this->assertSame('target', readlink($local_directory));
        $this->assertSame('target bytes', file_get_contents($local_directory));
    }

    /** @return array<string,list<string>> Selected file, directory, and link transitions. */
    public static function selected_type_changes(): array
    {
        return [
            'file to directory' => ['file', 'directory'],
            'directory to file' => ['directory', 'file'],
            'file to link' => ['file', 'symlink'],
            'link to directory' => ['symlink', 'directory'],
            'directory to link' => ['directory', 'symlink'],
            'link to file' => ['symlink', 'file'],
        ];
    }

    /**
     * Build one file part with normal source metadata.
     *
     * @param string $name Source-relative file name.
     * @param string $body Bytes carried by this part.
     * @param bool $first Whether this starts a file.
     * @param bool $last Whether this completes a file.
     * @param int|null $size Full file size, or this body's size.
     * @return array {
     *     @type array $headers File path, timestamp, size and chunk boundaries.
     *     @type string $body Part bytes.
     * }
     */
    private function file_part(string $name, string $body, bool $first = true, bool $last = true, ?int $size = null): array
    {
        return ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode($this->source . '/' . $name),
            'X-File-Size' => (string) ( $size ?? strlen($body) ), 'X-File-Ctime' => '1234567890',
            'X-First-Chunk' => $first ? '1' : '0', 'X-Last-Chunk' => $last ? '1' : '0'], 'body' => $body];
    }

    /**
     * Set the source's replies without changing client state or its planned requests.
     *
     * @param array $replies Ordered replies, each containing a list of multipart parts.
     * @param bool $partial_first Continue the same batch in the second reply instead of completing the first batch.
     */
    private function responses(array $replies, bool $partial_first = false): void
    {
        foreach ($replies as $index => &$parts) {
            $parts[] = ['headers' => ['X-Chunk-Type' => 'completion',
                'X-Status' => $partial_first && $index === 0 ? 'partial' : 'complete']];
        }
        unset($parts);
        file_put_contents($this->root . '/responses.json', json_encode($replies));
    }

    /**
     * Run files-pull with real planning, preserve-local selection, and remapping.
     *
     * @param bool $abort Clear the completed command before starting its next delta.
     */
    private function pull(bool $abort = false): void
    {
        if (!is_dir($this->root . '/state')) {
            $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
            \write_current_pull_state($client, ['preflight' => ['data' => ['ok' => true, 'path_format' => 'unix',
                'database' => ['wp' => ['paths_urls' => ['abspath' => $this->source]]],
                'wp_detect' => ['roots' => [['path' => $this->source]]]], 'http_code' => 200]]);
        }
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/packages/reprint-client/src/import.php', 'files-pull', $this->url,
            '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files', '--allow-unsafe-http',
            '--secret=test-secret', '--progress=jsonl', '--on-fs-root-nonempty=preserve-local',
            '--include=' . $this->source, '--remap', $this->source, ':fs-root:/site'];
        if ($abort) {
            $command[] = '--abort';
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/pull.log', 'w'],
            2 => ['file', $this->root . '/pull.log', 'a']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process), file_get_contents($this->root . '/pull.log'));
    }

    /** @return array[] Actual file-list uploads received by the source fixture. */
    private function requests(): array
    {
        return array_map(static function (string $line): array {
            return json_decode($line, true);
        }, file($this->root . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }

    /** Remove fixture paths without following any link targets. */
    private function remove_tree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove_tree($path . '/' . $name);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
