<?php

declare(strict_types=1);

namespace RelayTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';
require_once __DIR__ . '/../../packages/reprint-exporter/src/export.php';

/**
 * End-to-end reverse pull against the REAL importer and REAL exporter, with
 * the worker↔endpoint exchange crossing a JSON string boundary.
 *
 * A source tree is mirrored to an fs-root by running an ordinary `files-pull`
 * over the reversed single endpoint, but every exchange is serialized through
 * RelayExchangeEndpoint — the exact JSON wire contract a real HTTP hop would
 * carry (binary result bodies ride base64-encoded). The importer (remote,
 * driven inline by RelayImportDriver) owns cursors/index/writes; the source is
 * outbound-only, executing each export command against the real export.php in
 * process (RelayExportSource).
 */
final class ReverseTransportWireTest extends TestCase
{
    private string $source;
    private string $fsRoot;
    private string $stateDir;

    protected function setUp(): void
    {
        $suffix         = bin2hex(random_bytes(6));
        $this->source   = sys_get_temp_dir() . '/relay-pull-source-' . $suffix;
        $this->fsRoot   = sys_get_temp_dir() . '/relay-pull-fsroot-' . $suffix;
        $this->stateDir = sys_get_temp_dir() . '/relay-pull-state-' . $suffix;
        mkdir($this->source, 0700, true);
        mkdir($this->fsRoot, 0700, true);
        mkdir($this->stateDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->fsRoot, $this->stateDir] as $dir) {
            $this->rmrf($dir);
        }
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->rmrf($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function put(string $root, string $rel, string $body): void
    {
        $path = $root . '/' . $rel;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $body);
    }

    /** @return array<string,string> relative-path => contents */
    private function tree(string $root): array
    {
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');
                $out[$rel] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    public function testFilesPullMirrorsSourceAcrossAJsonWireBoundary(): void
    {
        // A small source tree "on the outbound-only local site".
        $this->put($this->source, 'index.php', "<?php // home\n");
        $this->put($this->source, 'wp-content/themes/t/style.css', "body{color:red}\n");
        $this->put($this->source, 'wp-content/uploads/a.bin', str_repeat("\x00\x01\x02\x03", 500));
        $this->put($this->source, 'readme.txt', "hello reverse transport\n");

        // Seed preflight so the importer knows which root to enumerate — the
        // same data a real preflight would persist.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            (string) json_encode([
                'preflight' => [
                    'data' => [
                        'wp_detect' => ['roots' => [['path' => $this->source]]],
                    ],
                ],
            ])
        );

        // The source's own export engine. Production would loopback into the
        // source's export.php; here a short-lived subprocess plays that role,
        // streaming the response over a pipe (the exporter tears down output
        // buffering, so it can only be captured from a real output stream).
        // display_errors=stderr keeps PHP startup notices out of the response.
        $runner = __DIR__ . '/export-runner.php';
        $run = static function (array $request) use ($runner): string {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $runner], $descriptors, $pipes);
            fwrite($pipes[0], (string) json_encode($request));
            fclose($pipes[0]);
            $body = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $body;
        };
        $exportSource = new \RelayExportSource($run);

        // The remote importer, re-entered fresh each pass like an exit-2 resume.
        $stateDir = $this->stateDir;
        $fsRoot   = $this->fsRoot;
        $factory  = static function () use ($stateDir, $fsRoot) {
            return new \ImportClient('http://relay.invalid/export.php', $stateDir, $fsRoot);
        };
        $driver = new \RelayImportDriver($factory, [
            'command'        => 'files-pull',
            'follow_symlinks' => false,
            'verbose'        => false,
        ]);

        // Wire the single endpoint to the outbound-only worker.
        $endpoint = new \RelayExchangeEndpoint($driver);
        // The worker talks to the endpoint over JSON strings — exactly the wire.
        $jsonExchange = static function (array $request) use ($endpoint): array {
            $responseJson = $endpoint->handle_json(\RelayExchangeEndpoint::encode_request($request));
            return \RelayExchangeEndpoint::decode_response($responseJson);
        };
        $worker = new \RelaySourceWorker(
            $jsonExchange,
            static fn(array $command): array => $exportSource->execute($command)
        );

        ob_start();
        try {
            $worker->run();
        } finally {
            ob_end_clean();
        }

        // Every source file landed on the remote, byte-for-byte. Compare by
        // contents so the assertion is independent of path-mapping style.
        $sourceTree = $this->tree($this->source);
        $fsTree     = $this->tree($this->fsRoot);

        $this->assertNotEmpty($fsTree, 'the reverse pull wrote files to the fs-root');
        $this->assertSame(
            array_values($sourceTree),
            array_values($fsTree),
            'the fs-root holds exactly the source file contents'
        );

        // The transfer really rode the ordinary export endpoints, one command
        // per outbound exchange.
        $transcript = $worker->transcript();
        $endpoints = array_map(static function (array $t): string {
            return preg_match('/endpoint=([a-z_]+)/', (string) ($t['command']['url'] ?? ''), $m)
                ? $m[1]
                : 'unknown';
        }, $transcript);
        $this->assertSame('file_index', $endpoints[0] ?? null, 'first exchange indexes the source');
        $this->assertContains('file_fetch', $endpoints, 'files are fetched over the channel');

        fwrite(STDERR, sprintf(
            "\n--- reverse files-pull: %d files mirrored over %d exchanges across the json wire ---\n",
            count($sourceTree),
            count($transcript)
        ));
        foreach ($transcript as $t) {
            $endpoint = 'unknown';
            if (preg_match('/endpoint=([a-z_]+)/', (string) ($t['command']['url'] ?? ''), $m)) {
                $endpoint = $m[1];
            }
            fwrite(STDERR, sprintf("  exchange %d  ->  %s\n", $t['exchange'], $endpoint));
        }
    }
}
