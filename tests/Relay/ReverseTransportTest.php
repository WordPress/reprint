<?php

declare(strict_types=1);

namespace RelayTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * End-to-end reverse pull against the REAL importer and REAL exporter.
 *
 * An ordinary `files-pull` mirrors a source tree to an fs-root over the reversed
 * single-endpoint channel: the remote (RelayExchange, driving the real
 * ImportClient inline) owns cursors/index/writes; the source (RelaySource) is
 * outbound-only and runs its own export.php per command. The worker↔endpoint
 * boundary is JSON strings — exactly the wire — so the binary result body rides
 * base64. No curl, no sockets: just the relay seam.
 */
final class ReverseTransportTest extends TestCase
{
    private string $source;
    private string $fsRoot;
    private string $stateDir;

    protected function setUp(): void
    {
        $suffix         = bin2hex(random_bytes(6));
        $this->source   = sys_get_temp_dir() . '/relay-source-' . $suffix;
        $this->fsRoot   = sys_get_temp_dir() . '/relay-fsroot-' . $suffix;
        $this->stateDir = sys_get_temp_dir() . '/relay-state-' . $suffix;
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
                $out[ltrim(substr($file->getPathname(), strlen($root)), '/')] =
                    (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    public function testFilesPullMirrorsTheSourceOverTheReverseChannel(): void
    {
        $this->put($this->source, 'index.php', "<?php // home\n");
        $this->put($this->source, 'wp-content/themes/t/style.css', "body{color:red}\n");
        $this->put($this->source, 'wp-content/uploads/a.bin', str_repeat("\x00\x01\x02\x03", 500));
        $this->put($this->source, 'readme.txt', "hello reverse transport\n");

        // Seed preflight so the importer knows which root to enumerate.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            (string) json_encode([
                'preflight' => ['data' => ['wp_detect' => ['roots' => [['path' => $this->source]]]]],
            ])
        );

        // The source's own export engine, run in a subprocess per command
        // (the exporter tears down output buffering to stream, so it can only be
        // captured from a real output stream; display_errors=stderr keeps PHP
        // startup notices out of the response). Production would loopback into
        // the source's export.php over HTTP instead.
        $runner = __DIR__ . '/export-runner.php';
        $runExport = static function (array $request) use ($runner): string {
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

        // The remote endpoint drives the real importer inline.
        $stateDir = $this->stateDir;
        $fsRoot   = $this->fsRoot;
        $exchange = new \RelayExchange(
            static fn() => new \ImportClient('http://relay.invalid/export.php', $stateDir, $fsRoot),
            ['command' => 'files-pull', 'follow_symlinks' => false, 'verbose' => false]
        );

        // The outbound-only source talks to it over JSON strings.
        $source = new \RelaySource(
            static fn(string $requestJson): string => $exchange->handle_json($requestJson),
            $runExport
        );

        ob_start();
        try {
            $source->run();
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
    }
}
