<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Importer tests share the ImportTests namespace.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Paths must be quoted as PHP literals in generated child scripts.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Checks CSS rewriting through the production file_index and file_fetch endpoints.
 *
 * A separate importer process exits before or after saving a multipart part.
 * Resuming must produce the same bytes as an uninterrupted download and must
 * not report rewritten files as local edits in the next file comparison.
 */
class CssFileDownloadTest extends TestCase {

    private string $root;
    private string $source;
    private string $url;
    /** @var resource|null PHP server process retained until tearDown(). */
    private $server;

    /** Serves the production router with small parts so each CSS file spans several checkpoints. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-css-' . bin2hex(random_bytes(6));
        $this->source = $this->root . '/source';
        mkdir($this->source . '/wp-content/uploads/elementor/css', 0700, true);
        mkdir($this->root . '/files', 0700, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        $this->assertIsResource($listener, (string) $message);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-server/src/export.php', true)
            . '; $_GET["chunk_size"] = 16384; (new WordPress\\Reprint\\Server\\HTTPServer())->handle_request();');
        $this->server = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes
        );
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $this->url = 'http://' . $address . '/';
                return;
            }
            usleep(20000);
        }
        $this->fail('File endpoint did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    /** Stops the server before deleting the source files and download state it used. */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->remove_tree($this->root);
    }

    /**
     * Checks the complete output, untouched non-CSS files, and the next file diff.
     *
     * @dataProvider checkpoint_boundaries
     */
    public function testCssDownloadResumesWithoutLosingOrRewritingBytesTwice(string $stop): void
    {
        $relative = '/wp-content/uploads/elementor/css/post-1.css';
        $css = str_repeat('.a{background:url(https://old.example/photo.jpg)}', 4000);
        file_put_contents($this->source . $relative, $css);
        file_put_contents($this->source . '/unchanged.txt', $css);
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files');
        \write_current_pull_state($client, [
            'preflight' => ['http_code' => 200, 'data' => [
                'ok' => true,
                'runtime' => ['document_root' => $this->source],
                'wp_detect' => ['roots' => [['path' => $this->source]]],
            ]],
        ]);
        $script = $this->root . '/client.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-client/src/import.php', true) . ';' . <<<'CODE'
class InterruptedCssDownload extends ImportClient {
    public function save_state(): void {
        $state = $this->get_state();
        $at_css_part = $state->current_css_cursor !== null && $state->current_file_bytes > 0;
        if ($at_css_part && $GLOBALS['argv'][4] === 'before') {
            exit(99);
        }
        parent::save_state();
        if ($at_css_part && $GLOBALS['argv'][4] === 'after') {
            exit(99);
        }
    }
}
$client = new InterruptedCssDownload($argv[1], $argv[2] . '/state', $argv[2] . '/files');
$client->run([
    'command' => $argv[3],
    'rewrite_url' => [['https://old.example', 'http://old.example/local']],
    'progress' => 'jsonl',
]);
exit($client->exit_code);
CODE
        );
        $first = $this->run_client($script, 'files-pull', $stop);
        $this->assertSame($stop === 'none' ? 0 : 99, $first['exit'], $first['output']);
        if ($stop !== 'none') {
            $second = $this->run_client($script, 'files-pull', 'none');
            $this->assertSame(0, $second['exit'], $second['output']);
        }
        $local_root = $this->root . '/files' . $this->source;
        $this->assertSame(
            hash('sha256', str_replace('https://old.example', 'http://old.example/local', $css)),
            hash_file('sha256', $local_root . $relative),
            'The downloaded CSS must contain the mapped URL exactly once.'
        );
        $this->assertSame($css, file_get_contents($local_root . '/unchanged.txt'));
        $this->assertSame($css, file_get_contents($this->source . $relative));
        $diff = $this->run_client($script, 'files-diff', 'none');
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertStringContainsString('"local_paths_to_push":0', $diff['output']);
    }

    /** Covers normal completion and process exit immediately before or after save_state(). */
    public static function checkpoint_boundaries(): array
    {
        return [['none'], ['before'], ['after']];
    }

    /**
     * Runs one importer command in a new process against the same download state.
     *
     * @return array {
     *     Result after the child exits.
     *     @type int    $exit   Process exit code; 99 marks the test's deliberate stop.
     *     @type string $output Captured standard output and error for assertions.
     * }
     * @phpstan-return array{exit:int,output:string}
     */
    private function run_client(string $script, string $command, string $stop): array
    {
        $process = proc_open([PHP_BINARY, $script, $this->url, $this->root, $command, $stop],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/client.log', 'w'], 2 => ['file', $this->root . '/client.log', 'a']], $pipes);
        fclose($pipes[0]);
        return ['exit' => proc_close($process), 'output' => file_get_contents($this->root . '/client.log')];
    }

    /** Deletes a test directory recursively, unlinking symlinks rather than following them. */
    private function remove_tree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove_tree($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
