<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;
use WordPress\Reprint\Server\Utils;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class PullFileErrorPathTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/pull-error-path-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0700, true);
        mkdir($this->root . '/outside', 0700);
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

    public function testRemoteErrorPathIsDisplayedAsTextAfterHttpDecoding(): void
    {
        $client = $this->new_client();
        $stream = fopen('php://memory', 'w+b');
        ( new \ReflectionProperty($client, 'progress') )->setValue($client, new \TerminalProgress(true, $stream));
        ( new \ReflectionProperty($client, 'progress_output_mode') )->setValue($client, 'tty');
        try {
            $this->receive_error($client, base64_encode("/remote/café\033[2J\007.txt"), 'sql');
            rewind($stream);
            $output = stream_get_contents($stream);
            $this->assertStringNotContainsString("\033[2J", $output);
            $this->assertStringContainsString('café\\x1b[2J\\x07.txt', $output);
            $this->assertStringContainsString("\r\033[K", $output, 'Reprint still refreshes its progress line.');
        } finally {
            fclose($stream);
        }
    }

    /** File errors use the same remap as downloaded file parts. */
    public function testFileErrorRemovesOnlyTheMappedLocalPath(): void
    {
        mkdir($this->root . '/files/mapped');
        file_put_contents($this->root . '/files/mapped/item', 'partial');
        $encoded_path = base64_encode('/remote/item');
        $sibling = $this->root . '/files' . $encoded_path;
        file_put_contents($sibling, 'keep sibling');
        $client = $this->new_client();
        ( new \ReflectionProperty($client, 'resolved_path_mappings') )->setValue(
            $client, ['/remote' => $this->root . '/files/mapped']
        );
        $other_path = base64_encode('/remote/keep');
        // The retained source index comes from an earlier completed pull.
        file_put_contents($client->pull_state_directory . '/remote-index.jsonl',
            json_encode(['path' => $encoded_path, 'ctime' => 10, 'size' => 7, 'type' => 'file']) . "\n" .
            json_encode(['path' => $other_path, 'ctime' => 10, 'size' => 4, 'type' => 'file']) . "\n");
        $this->receive_error($client, $encoded_path, 'files');
        $this->assertFileDoesNotExist($this->root . '/files/mapped/item');
        $this->assertSame('keep sibling', file_get_contents($sibling));
        $entries = file($client->pull_state_directory . '/remote-index.jsonl', FILE_IGNORE_NEW_LINES);
        $this->assertSame([$other_path], array_map(static function (string $line): string {
            return json_decode($line, true)['path'];
        }, $entries));
    }

    /** @dataProvider non_file_phases */
    public function testOtherPhasesDoNotRemoveLocalFiles(string $phase): void
    {
        $encoded_path = base64_encode('/item');
        file_put_contents($this->root . '/files/item', 'keep mapped file');
        file_put_contents($this->root . '/files' . $encoded_path, 'keep sibling');
        $client = $this->new_client();
        $this->receive_error($client, $encoded_path, $phase);
        $this->assertSame('keep mapped file', file_get_contents($this->root . '/files/item'));
        $this->assertSame('keep sibling', file_get_contents($this->root . '/files' . $encoded_path));
        $this->assertFileDoesNotExist($client->pull_state_directory . '/index.wal');
    }

    /** @return array<string,list<string>> Phases which must not change local files. */
    public static function non_file_phases(): array
    {
        return ['SQL' => ['sql'], 'database index' => ['db-index'], 'file index' => ['index']];
    }

    /** Selection and exclusions apply to file errors as well as ordinary paths. */
    public function testUnselectedErrorPathsDoNotRemoveFilesOrChangeTheJournal(): void
    {
        mkdir($this->root . '/files/selected');
        file_put_contents($this->root . '/files/unselected', 'keep');
        file_put_contents($this->root . '/files/selected/excluded', 'keep');
        $client = $this->new_client();
        $client->prepare_files_pull_options(['include' => ['/selected'], 'exclude' => ['/selected/excluded']], false);
        foreach (['/unselected', '/selected/excluded'] as $path) {
            $encoded_path = base64_encode($path);
            $sibling = $this->root . '/files' . $encoded_path;
            file_put_contents($sibling, 'keep sibling');
            $this->receive_error($client, $encoded_path, 'files');
            $this->assertSame('keep', file_get_contents($this->root . '/files' . $path));
            $this->assertSame('keep sibling', file_get_contents($sibling));
        }
        $this->assertFileDoesNotExist($client->pull_state_directory . '/index.wal');
        $this->assertStringContainsString('Skipped file error outside the pull selection:', file_get_contents($client->state_dir . '/audit.log'));
    }

    /**
     * Existing parent links cannot turn invalidation into an external removal.
     * @dataProvider external_parent_targets
     */
    public function testMappedFileErrorChecksThePhysicalParentBeforeRemovingAFile(bool $target_exists): void
    {
        if ($target_exists) {
            file_put_contents($this->root . '/outside/item', 'keep outside');
        }
        symlink($this->root . '/outside', $this->root . '/files/alias');
        $client = $this->new_client();
        $this->receive_error($client, base64_encode('/alias/item'), 'files');
        if ($target_exists) {
            $this->assertSame('keep outside', file_get_contents($this->root . '/outside/item'));
        } else {
            $this->assertFileDoesNotExist($this->root . '/outside/item');
        }
        $this->assertFileDoesNotExist($client->pull_state_directory . '/index.wal');
        $this->assertStringContainsString('Skipped file error cleanup:', file_get_contents($client->state_dir . '/audit.log'));
    }

    /** @return array<string, array{bool}> External parent with and without a surviving target file. */
    public static function external_parent_targets(): array
    {
        return ['existing file' => [true], 'missing file' => [false]];
    }

    /** A path error closes the matching partial file before clearing its local bytes. */
    public function testFileErrorClosesAndClearsTheCurrentMappedPartialFile(): void
    {
        $client = $this->new_client();
        ( new \ReflectionProperty($client, 'resolved_path_mappings') )->setValue(
            $client, ['/remote' => $this->root . '/files/mapped']
        );
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/remote/item'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '0', 'X-File-Size' => '9'], 'body' => 'part'],
            ['headers' => ['X-Chunk-Type' => 'error'], 'body' => json_encode([
                'error_type' => 'file_read', 'path' => base64_encode('/remote/item'), 'message' => 'Read failed',
            ])],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        $this->assertFileDoesNotExist($this->root . '/files/mapped/item');
        $this->assertNull($client->get_state()->current_file);
        $this->assertNull($client->get_state()->current_file_bytes);
    }

    /** Followed targets use the configured local mapping even outside an include prefix. */
    public function testFileErrorUsesTheFollowedTargetMapping(): void
    {
        $client = $this->new_client();
        $client->prepare_files_pull_options(['include' => ['/selected']], false);
        ( new \ReflectionProperty($client, 'follow_symlinks') )->setValue($client, true);
        ( new \ReflectionProperty($client, 'local_followed_symlinks_root') )->setValue(
            $client, $this->root . '/files/followed'
        );
        mkdir($this->root . '/files/followed/shared', 0700, true);
        file_put_contents($this->root . '/files/followed/shared/item', 'partial');
        $encoded_path = base64_encode('/shared/item');
        file_put_contents($client->pull_state_directory . '/remote-index.next.jsonl',
            json_encode(['path' => $encoded_path, 'ctime' => 10, 'size' => 7, 'type' => 'file']) . "\n");
        $this->receive_error($client, $encoded_path, 'files');
        $this->assertFileDoesNotExist($this->root . '/files/followed/shared/item');
    }

    /** Source Windows paths are decoded before the mapper converts their separators. */
    public function testFileErrorUsesTheReportedWindowsPathFormat(): void
    {
        $client = $this->new_client();
        $client->get_state()->set_preflight_record(['data' => ['path_format' => 'windows']]);
        $client->prepare_files_pull_options(['include' => ['D:\\site']], false);
        mkdir($this->root . '/files/D:/site', 0700, true);
        file_put_contents($this->root . '/files/D:/site/item', 'partial');
        $this->receive_error($client, base64_encode('D:\\site\\item'), 'files');
        $this->assertFileDoesNotExist($this->root . '/files/D:/site/item');
    }

    /** @dataProvider invalid_error_paths */
    public function testInvalidErrorPathsAreLoggedAndIgnored(string $path, string $path_format): void
    {
        file_put_contents($this->root . '/outside/item', 'keep outside');
        file_put_contents($this->root . '/files/item', 'keep local');
        $client = $this->new_client();
        $client->get_state()->set_preflight_record(['data' => ['path_format' => $path_format]]);
        $this->receive_error($client, base64_encode($path), 'files');
        $this->assertSame('keep outside', file_get_contents($this->root . '/outside/item'));
        $this->assertSame('keep local', file_get_contents($this->root . '/files/item'));
        $this->assertFileDoesNotExist($client->pull_state_directory . '/index.wal');
        $this->assertStringContainsString('Skipped remote error:', file_get_contents($client->state_dir . '/audit.log'));
    }

    public function testIgnoredErrorDoesNotDiscardTheFollowingFilePart(): void
    {
        file_put_contents($this->root . '/outside/item', 'keep outside');
        $client = $this->new_client();
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'error'], 'body' => json_encode([
                'error_type' => 'file_missing', 'path' => base64_encode('/../outside/item'), 'message' => 'File disappeared',
            ])],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/item'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4'], 'body' => 'keep'],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        $this->assertSame('keep', file_get_contents($this->root . '/files/item'));
        $this->assertSame('keep outside', file_get_contents($this->root . '/outside/item'));
    }

    public function testUnixErrorPathPreservesLiteralBackslashes(): void
    {
        file_put_contents($this->root . '/files/back\\slash', 'partial');
        $client = $this->new_client();
        $this->receive_error($client, base64_encode('/back\\slash'), 'files');
        $this->assertFileDoesNotExist($this->root . '/files/back\\slash');
    }

    /** @return array<string,list<string>> Invalid remote absolute path spellings. */
    public static function invalid_error_paths(): array
    {
        return ['Unix traversal' => ['/../../outside/item', 'unix'], 'Unix relative' => ['../outside/item', 'unix'],
            'Unix NUL' => ["/item\0tail", 'unix'],
            'Windows traversal' => ['D:\\site\\..\\..\\outside\\item', 'windows'],
            'Windows relative' => ['..\\outside\\item', 'windows'],
            'Windows NUL' => ["D:\\site\\item\0tail", 'windows']];
    }

    /** Construct a normal credentialed client for the test source. */
    private function new_client(): \ImportClient
    {
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        return $client;
    }

    /** Receive one source file error through the production multipart parser. */
    private function receive_error(\ImportClient $client, string $encoded_path, string $phase): void
    {
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'error'], 'body' => json_encode([
                'error_type' => 'file_missing', 'path' => $encoded_path, 'message' => 'File disappeared',
            ])],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        if ($phase === 'files') {
            ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
            return;
        }
        $context = new StreamingContext();
        $context->on_chunk = static function (array $chunk) use ($client, $context, $phase): void {
            if ( ( $chunk['headers']['x-chunk-type'] ?? '' ) === 'error') {
                ( new \ReflectionMethod($client, 'handle_error_chunk') )->invoke($client, $chunk, $phase, $context);
            } elseif ( ( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                $context->saw_completion = true;
            }
        };
        ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke(
            $client, Utils::endpoint_url($this->url, 'sql_chunk'), $context, [], 'sql_chunk'
        );
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
