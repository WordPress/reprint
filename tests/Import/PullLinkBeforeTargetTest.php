<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';
require_once __DIR__ . '/LinkOrCopyAssertions.php';

/**
 * A source may send a link before its target. WP Cloud's managed plugins are
 * links into a shared tree that often arrives later in the same pull.
 *
 * Windows PHP can only link to an existing target, so the client creates
 * such a link after the fetch; elsewhere it is created at once.
 */
final class PullLinkBeforeTargetTest extends TestCase
{
    use LinkOrCopyAssertions;

    private string $root;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pull-link-before-target-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0700, true);
        $this->root = \WordPress\Reprint\Server\Utils::realpath_with_missing_tail($this->root);
        if (!$this->can_create_symlinks()) {
            $this->markTestSkipped('This account cannot create symlinks.');
        }
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

    public function testLinksSentBeforeTheirTargetsResolveAfterTheFetch(): void
    {
        $managed_plugin_link = [
            'headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/srv/htdocs/wp-content/plugins/managed'),
                'X-Symlink-Target' => base64_encode('../../../../wordpress/plugins/managed/latest'), 'X-Symlink-Ctime' => '7'],
        ];
        $client = $this->receive([
            $managed_plugin_link,
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/srv/htdocs/wp-config.php'),
                'X-Symlink-Target' => base64_encode('../../config/wp-config.php'), 'X-Symlink-Ctime' => '7']],
            // A resumed fetch replays parts after its last saved cursor.
            $managed_plugin_link,
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/wordpress/plugins/managed/latest/plugin.php'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '7'], 'body' => 'managed'],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/config/wp-config.php'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '6'], 'body' => 'config'],
        ]);

        ( new \ReflectionMethod($client, 'create_deferred_symlinks') )->invoke($client);

        $this->assertLinkedOrCopied($this->root . '/files/srv/htdocs/wp-content/plugins/managed');
        $this->assertSame('managed', file_get_contents($this->root . '/files/srv/htdocs/wp-content/plugins/managed/plugin.php'));
        $this->assertLinkedOrCopied($this->root . '/files/srv/htdocs/wp-config.php');
        $this->assertSame('config', file_get_contents($this->root . '/files/srv/htdocs/wp-config.php'));
        $this->assertFileDoesNotExist($this->root . '/state/remotes/' . md5(rtrim($this->url, '?&')) . '/pull/deferred-symlinks.jsonl');

        // Windows cannot remove the state directory while the client holds its files open.
        unset($client);
        gc_collect_cycles();
    }

    /** WP Cloud's wp-admin link reaches core through the __wp__ link, which may come later. */
    public function testALinkThroughALaterLinkResolvesAfterTheFetch(): void
    {
        $client = $this->receive([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/srv/htdocs/wp-admin'),
                'X-Symlink-Target' => base64_encode('__wp__/wp-admin'), 'X-Symlink-Ctime' => '7']],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/srv/htdocs/__wp__'),
                'X-Symlink-Target' => base64_encode('../../wordpress/core/6.7'), 'X-Symlink-Ctime' => '7']],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/wordpress/core/6.7/wp-admin/index.php'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '5'], 'body' => 'admin'],
        ]);

        ( new \ReflectionMethod($client, 'create_deferred_symlinks') )->invoke($client);

        $this->assertLinkedOrCopied($this->root . '/files/srv/htdocs/wp-admin');
        $this->assertSame('admin', file_get_contents($this->root . '/files/srv/htdocs/wp-admin/index.php'));
        $pull_state_directory = $this->root . '/state/remotes/' . md5(rtrim($this->url, '?&')) . '/pull';
        $this->assertFileDoesNotExist($pull_state_directory . '/deferred-symlinks.jsonl');
        $this->assertFileDoesNotExist($pull_state_directory . '/deferred-symlinks.jsonl.remaining');

        unset($client);
        gc_collect_cycles();
    }

    /** Sends parts through the real multipart reader. */
    private function receive(array $parts): \ImportClient
    {
        $parts[] = ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']];
        file_put_contents($this->root . '/parts.json', json_encode($parts));
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        return $client;
    }

    private function can_create_symlinks(): bool
    {
        $probe = $this->root . '/probe';
        if (!\LocalLink::create_symlink($this->root . '/files', $probe)) {
            return false;
        }
        \WordPress\Reprint\Server\Utils::remove_symlink($probe);
        return true;
    }

    /** Removes test paths without following links. */
    private function remove_tree(string $path): void
    {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (\LocalLink::is_link($child)) {
                \WordPress\Reprint\Server\Utils::remove_symlink($child);
            } elseif (is_dir($child)) {
                $this->remove_tree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
