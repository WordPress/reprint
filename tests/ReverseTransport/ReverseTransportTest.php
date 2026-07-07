<?php

declare(strict_types=1);

namespace ReverseTransportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * End-to-end reverse pull against the REAL importer and REAL exporter.
 *
 * An ordinary `files-pull` mirrors a source tree to an fs-root over the reversed
 * single-endpoint channel: the remote (ReverseTransportEndpoint, driving the real
 * ImportClient inline) owns cursors/index/writes; the source (ReverseTransportSource) is
 * outbound-only and runs its own export.php per command. The source↔endpoint
 * boundary is exactly the wire: raw result bytes as a stream in, small JSON
 * out. No curl, no sockets: just the reverse-transport seam.
 *
 * Also covers the failure story: a crashed source is replaced by a fresh one
 * that resumes from the remote's persisted state, and exchange failures are
 * loud errors, never a clean-looking finish.
 */
final class ReverseTransportTest extends TestCase
{
    private string $sourceRoot;
    private string $fsRoot;
    private string $stateDir;

    protected function setUp(): void
    {
        $suffix         = bin2hex(random_bytes(6));
        $this->sourceRoot   = sys_get_temp_dir() . '/reverse-transport-source-' . $suffix;
        $this->fsRoot   = sys_get_temp_dir() . '/reverse-transport-fsroot-' . $suffix;
        $this->stateDir = sys_get_temp_dir() . '/reverse-transport-state-' . $suffix;
        mkdir($this->sourceRoot, 0700, true);
        mkdir($this->fsRoot, 0700, true);
        mkdir($this->stateDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->sourceRoot, $this->fsRoot, $this->stateDir] as $dir) {
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

    private function writeFile(string $root, string $rel, string $body): void
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

    private function seedSourceTreeAndPreflight(): void
    {
        $this->writeFile($this->sourceRoot, 'index.php', "<?php // home\n");
        $this->writeFile($this->sourceRoot, 'wp-content/themes/t/style.css', "body{color:red}\n");
        $this->writeFile($this->sourceRoot, 'wp-content/uploads/a.bin', str_repeat("\x00\x01\x02\x03", 500));
        $this->writeFile($this->sourceRoot, 'readme.txt', "hello reverse transport\n");

        // Seed preflight so the importer knows which root to enumerate.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            (string) json_encode([
                'preflight' => ['data' => ['wp_detect' => ['roots' => [['path' => $this->sourceRoot]]]]],
            ])
        );
    }

    /** The remote endpoint, driving the real importer against this test's dirs. */
    private function newEndpoint(): \ReverseTransportEndpoint
    {
        $stateDir = $this->stateDir;
        $fsRoot   = $this->fsRoot;
        return new \ReverseTransportEndpoint(
            static fn() => new \ImportClient('http://reverse-transport.invalid/export.php', $stateDir, $fsRoot),
            ['command' => 'files-pull', 'follow_symlinks' => false, 'verbose' => false]
        );
    }

    /**
     * The source's own export engine, run in a subprocess per command (the
     * exporter tears down output buffering to stream, so it can only be
     * captured from a real output stream; display_errors=stderr keeps PHP
     * startup notices out of the response). The subprocess writes straight to
     * a temp file — never a PHP string — because a file_fetch response can be
     * many megabytes. Production would loopback into the source's export.php
     * over HTTP instead, spooling the same way.
     */
    private function exportRunner(): callable
    {
        $runner = __DIR__ . '/export-runner.php';
        return static function (array $request) use ($runner): array {
            $stdout = tmpfile(); // deleted automatically when the source fcloses it
            $descriptors = [0 => ['pipe', 'r'], 1 => $stdout, 2 => ['pipe', 'w']];
            $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $runner], $descriptors, $pipes);
            fwrite($pipes[0], (string) json_encode($request));
            fclose($pipes[0]);
            // Drain stderr (PHP startup noise) so the child never blocks on a
            // full pipe before exiting.
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($proc);
            rewind($stdout);
            return ['http_code' => 200, 'stream' => $stdout];
        };
    }

    /** Builds the source↔endpoint wire: the in-process stand-in for the HTTP hop. */
    private function newSource(\ReverseTransportEndpoint $endpoint): \ReverseTransportSource
    {
        return new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            $this->exportRunner()
        );
    }

    /**
     * Every source file landed on the remote, byte-for-byte. Compare by
     * contents so the assertion is independent of path-mapping style.
     */
    private function assertFsRootMirrorsSource(): void
    {
        $fsTree = $this->tree($this->fsRoot);
        $this->assertNotEmpty($fsTree, 'the reverse pull wrote files to the fs-root');
        $this->assertSame(
            array_values($this->tree($this->sourceRoot)),
            array_values($fsTree),
            'the fs-root holds exactly the source file contents'
        );
    }

    public function testFilesPullMirrorsTheSourceOverTheReverseChannel(): void
    {
        $this->seedSourceTreeAndPreflight();
        $source = $this->newSource($this->newEndpoint());

        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }

        $this->assertFsRootMirrorsSource();
    }

    public function testAMultiMegabyteFileStreamsWithoutBeingBufferedWhole(): void
    {
        $this->seedSourceTreeAndPreflight();

        // 24 MB of content that defeats gzip's 32 KB window (a repeated 64 KB
        // random block never matches within the window), so the wire carries
        // ~24 MB and any whole-body buffering shows up as a memory spike of at
        // least that size.
        $bigFile = $this->sourceRoot . '/wp-content/uploads/big.bin';
        $handle  = fopen($bigFile, 'wb');
        $block   = random_bytes(65536);
        for ($i = 0; $i < 384; $i++) {
            fwrite($handle, $block);
        }
        fclose($handle);

        $source = $this->newSource($this->newEndpoint());

        $peakBefore = memory_get_peak_usage(true);
        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }
        $peakDelta = memory_get_peak_usage(true) - $peakBefore;

        // Compare by hash, not tree(), so the assertion itself doesn't load
        // the 24 MB files into memory.
        $mirrored = null;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fsRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === 'big.bin') {
                $mirrored = $file->getPathname();
            }
        }
        $this->assertNotNull($mirrored, 'the 24 MB file landed on the remote');
        $this->assertSame(hash_file('sha256', $bigFile), hash_file('sha256', $mirrored));

        $this->assertLessThan(
            8 * 1024 * 1024,
            $peakDelta,
            "peak memory grew by {$peakDelta} bytes while transferring a 24 MB file — " .
                'a response body is being buffered whole somewhere'
        );
    }

    public function testAFreshSourceResumesAfterTheFirstOneCrashesMidTransfer(): void
    {
        $this->seedSourceTreeAndPreflight();
        $endpoint = $this->newEndpoint();

        // The first source dies before delivering the file_fetch result: the
        // remote has consumed the index and issued the fetch command, but no
        // file bytes have arrived yet.
        $calls   = 0;
        $crashed = new \ReverseTransportSource(
            static function ($resultStream, int $httpCode) use ($endpoint, &$calls): string {
                if (++$calls > 2) {
                    throw new \RuntimeException('source crashed');
                }
                return $endpoint->handle_exchange($resultStream, $httpCode);
            },
            $this->exportRunner()
        );

        ob_start();
        try {
            $crashed->run();
            $this->fail('expected the source to crash');
        } catch (\RuntimeException $e) {
            $this->assertSame('source crashed', $e->getMessage());
        } finally {
            ob_end_clean();
        }
        $this->assertSame([], $this->tree($this->fsRoot), 'no file bytes were delivered before the crash');

        // The remote kept its own persisted state. A fresh source starts with
        // no result, the remote re-asks for the request it is missing, and the
        // transfer completes.
        $resumed = $this->newSource($endpoint);

        ob_start();
        try {
            $resumed->run();
        } finally {
            ob_end_clean();
        }

        $this->assertFsRootMirrorsSource();
    }

    public function testATransportFailureIsAnErrorNotACleanFinish(): void
    {
        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => '<html>502 Bad Gateway</html>',
            static fn(array $request): array => ['http_code' => 200, 'stream' => fopen('php://memory', 'r')]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed exchange response');
        $source->run();
    }

    public function testARemoteImporterFailureSurfacesWithItsMessage(): void
    {
        // The endpoint converts a non-yield importer failure into the error
        // status, and the source surfaces its message instead of finishing.
        $endpoint = new \ReverseTransportEndpoint(
            static function (): \ImportClient {
                throw new \RuntimeException('importer exploded');
            },
            []
        );
        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            static fn(array $request): array => ['http_code' => 200, 'stream' => fopen('php://memory', 'r')]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('importer exploded');
        $source->run();
    }
}
