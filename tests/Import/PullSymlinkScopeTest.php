<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class PullSymlinkScopeTest extends TestCase
{
    private string $root;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('These fixtures create Unix symlink targets.');
        }
        $this->root = sys_get_temp_dir() . '/pull-link-scope-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0700, true);
        mkdir($this->root . '/outside', 0700);
        $this->root = realpath($this->root);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/pull-symlink-scope-router.php'],
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

    /**
     * Existing links must be resolved before the following parent segments.
     * @dataProvider target_tails
     * @param string $tail Existing directory or a missing target below it.
     * @param string $prefix Missing component followed by a parent segment.
     */
    public function testSourceLinkChainKeepsLaterFileBytesInsideTheRoot(string $tail, string $prefix): void
    {
        $chain = implode('/', array_fill(0, 40, 'nested'));
        $parents = str_repeat('../', 41);
        $this->receive([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/' . $chain . '/up'),
                'X-Symlink-Target' => base64_encode(rtrim($parents, '/'))]],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/out'),
                'X-Symlink-Target' => base64_encode($prefix . $chain . '/up/' . $parents . ltrim($this->root . '/outside' . $tail, '/'))]],
            ['headers' => ['X-Chunk-Type' => 'directory', 'X-Directory-Path' => base64_encode('/a/missing')]],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/a/out/new.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'], 'body' => 'new'],
        ]);
        $this->assertFileDoesNotExist($this->root . '/outside' . $tail . '/new.txt');
        $this->assertFalse(is_link($this->root . '/files/a/out'));
        $this->assertSame('new', file_get_contents($this->root . '/files/a/out/new.txt'));
    }

    /** @return array<string,list<string>> Target tail and prefix for each case. */
    public static function target_tails(): array
    {
        return ['existing' => ['', ''], 'missing tail' => ['/not-created', ''],
            'missing component before parent' => ['', 'missing/../']];
    }

    /** A dangling chain inside the root remains usable after its file arrives. */
    public function testDanglingLinkChainInsideTheRootRemainsUsable(): void
    {
        $this->receive([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/middle'),
                'X-Symlink-Target' => base64_encode('../future')]],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/alias'),
                'X-Symlink-Target' => base64_encode('middle/leaf')]],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/future/leaf'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'], 'body' => 'new'],
        ]);
        $this->assertTrue(is_link($this->root . '/files/a/alias'));
        $this->assertSame('new', file_get_contents($this->root . '/files/a/alias'));
    }

    /** A user can move a partial download to a shared directory between requests. */
    public function testResumeChecksTheCurrentFileBeforeTruncatingOrAppending(): void
    {
        $client = $this->receive([['headers' => ['X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode('/partial/item'), 'X-First-Chunk' => '1',
            'X-Last-Chunk' => '0', 'X-File-Size' => '9'], 'body' => 'new']]);
        rename($this->root . '/files/partial', $this->root . '/outside/partial');
        symlink($this->root . '/outside/partial', $this->root . '/files/partial');
        file_put_contents($this->root . '/outside/partial/item', 'new extended');
        try {
            ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
            $this->fail('Resume must refuse the moved current file.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside filesystem root', $error->getMessage());
        }
        $this->assertSame('new extended', file_get_contents($this->root . '/outside/partial/item'));
    }

    /**
     * Later source links can redirect a retained pathname before its timestamp is set.
     *
     * @dataProvider retained_file_paths
     * @param bool $replace_parent Redirect the parent rather than the file itself.
     * @param bool $start_next_file Close the partial file by starting another file.
     */
    public function testLaterSourceLinksCannotRedirectARetainedFileTimestamp(bool $replace_parent, bool $start_next_file): void
    {
        $retained_path = $replace_parent ? '/a/out/new.txt' : '/old.txt';
        $replacement_path = $replace_parent ? '/a/out' : '/old.txt';
        $replacement_target = $replace_parent ? 'future/../outside' : 'a/future/../outside/new.txt';
        $failure = null;
        try {
            $this->receive([
                ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode($retained_path),
                    'X-First-Chunk' => '1', 'X-Last-Chunk' => '0', 'X-File-Size' => '8',
                    'X-File-Ctime' => '1234567890'], 'body' => 'first'],
                ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode($replacement_path),
                    'X-Symlink-Target' => base64_encode($replacement_target)]],
                ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/future'),
                    'X-Symlink-Target' => base64_encode('..')]],
                // The source can name a different in-root path on the closing
                // part too. Only the retained pathname check protects touch().
                ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/next.txt'),
                    'X-First-Chunk' => $start_next_file ? '1' : '0', 'X-Last-Chunk' => '1',
                    'X-File-Size' => $start_next_file ? '4' : '8'], 'body' => $start_next_file ? 'next' : 'end'],
            ]);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        $this->assertFileDoesNotExist($this->root . '/outside/new.txt');
        $this->assertNotNull($failure, 'A redirected retained pathname must stop the request.');
        $this->assertStringContainsString('outside filesystem root', $failure->getMessage());
        $this->assertTrue(is_link($this->root . '/files/a/future'), 'The source installed the link which redirects the earlier path.');
    }

    /** @return array<string,array{bool,bool}> Parent replacement and file-closing mode. */
    public static function retained_file_paths(): array
    {
        return [
            'parent redirected before next file' => [true, true],
            'parent redirected before closing part' => [true, false],
            'leaf redirected before next file' => [false, true],
            'leaf redirected before closing part' => [false, false],
        ];
    }

    /**
     * Preserve-local skips shared paths and continues with the next ordinary file.
     *
     * @dataProvider shared_parent_paths
     * @param string $missing_tail Missing components beneath the shared directory.
     */
    public function testPreserveLocalSkipsAnExternalParentAndKeepsDownloading(string $missing_tail): void
    {
        symlink($this->root . '/outside', $this->root . '/files/shared');
        file_put_contents($this->root . '/outside/new.txt', 'keep existing');
        // More than one cURL body callback must stay skipped without adding file bytes.
        $skipped_body = str_repeat('skip', 32768);
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('/shared' . $missing_tail . '/new.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1',
                'X-File-Size' => (string) strlen($skipped_body), 'X-File-Ctime' => '1234567890'], 'body' => $skipped_body],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/safe.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4',
                'X-File-Ctime' => '1234567891'], 'body' => 'safe'],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        // run() selects this same mode from --on-fs-root-nonempty=preserve-local.
        ( new \ReflectionProperty($client, 'fs_root_nonempty_behavior') )->setValue($client, 'preserve-local');
        $complete = ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        $this->assertSame('keep existing', file_get_contents($this->root . '/outside/new.txt'));
        $this->assertDirectoryDoesNotExist($this->root . '/outside/missing');
        $this->assertSame('safe', file_get_contents($this->root . '/files/safe.txt'));
        $this->assertTrue($complete);
        $reporter = ( new \ReflectionProperty($client, 'progress_reporter') )->getValue($client);
        $this->assertSame(2, $reporter->get_batch_files_done());
        $this->assertSame(4, $client->get_state()->fetch->file_bytes_in_batch);
        $this->assertStringContainsString('PRESERVE-LOCAL', file_get_contents($this->root . '/state/audit.log'));
    }

    /** @return array<string,list<string>> Existing or missing shared parent components. */
    public static function shared_parent_paths(): array
    {
        return ['existing parent' => [''], 'missing child directory' => ['/missing']];
    }

    /** @dataProvider received_types */
    public function testExistingParentLinkIsCheckedBeforeReplacingAPath(string $type): void
    {
        // A user may already have this link when starting an overwrite pull.
        symlink($this->root . '/outside', $this->root . '/files/alias');
        $path = $this->root . '/outside/item';
        if ($type === 'file') {
            mkdir($path);
            file_put_contents($path . '/keep.txt', 'keep');
        } else {
            file_put_contents($path, 'keep');
        }
        $headers = ['X-Chunk-Type' => $type, 'X-' . ucfirst($type) . '-Path' => base64_encode('/alias/item')];
        if ($type === 'file') {
            $headers += ['X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'];
        } elseif ($type === 'symlink') {
            $headers['X-Symlink-Target'] = base64_encode('../safe');
        }
        try {
            $this->receive([['headers' => $headers, 'body' => $type === 'file' ? 'new' : '']]);
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside filesystem root', $error->getMessage());
        }
        $this->assertSame('keep', file_get_contents($path . ( $type === 'file' ? '/keep.txt' : '' )));
        $this->assertFalse(is_link($path));
    }

    /** @return array<string,list<string>> Received part types. */
    public static function received_types(): array
    {
        return ['file' => ['file'], 'directory' => ['directory'], 'symlink' => ['symlink']];
    }

    /**
     * Run the production file-fetch dispatcher over a real HTTP response.
     *
     * @param array $parts {
     *     Source multipart parts.
     *     @type array $headers Protocol header strings for each part.
     *     @type string $body Optional part bytes.
     * }
     * @return \ImportClient Client retaining the saved file-fetch state.
     * @phpstan-param list<array{headers:array<string,string>,body?:string}> $parts
     */
    private function receive(array $parts): \ImportClient
    {
        $parts[] = ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']];
        file_put_contents($this->root . '/parts.json', json_encode($parts));
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        return $client;
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
