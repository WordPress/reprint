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
            $this->recursiveDelete($dir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // scandir, not glob('*'): the state dir is full of dotfiles
        // (.import-state.json, .import-index.jsonl, ...) that glob skips,
        // which would leak the directory on teardown.
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
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
    private function newEndpoint(array $optionOverrides = []): \ReverseTransportEndpoint
    {
        $stateDir = $this->stateDir;
        $fsRoot   = $this->fsRoot;
        return new \ReverseTransportEndpoint(
            static fn() => new \ImportClient('http://reverse-transport.invalid/export.php', $stateDir, $fsRoot),
            array_merge(
                ['command' => 'files-pull', 'follow_symlinks' => false, 'verbose' => false],
                $optionOverrides
            )
        );
    }

    /** @return resource A rewound in-memory stream holding $bytes. */
    private static function memoryStream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
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
            ['command' => 'files-pull']
        );
        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            static fn(array $request): array => ['http_code' => 200, 'stream' => fopen('php://memory', 'r')]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('importer exploded');
        $source->run();
    }

    public function testAGarbage200BodyFailsLoudlyInsteadOfLooping(): void
    {
        // A source whose export deterministically produces a non-multipart
        // body (fatal after headers, HTML error page) must abort with the
        // curl path's message, not re-ask the same command forever.
        $this->seedSourceTreeAndPreflight();
        $endpoint = $this->newEndpoint();

        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            static fn(array $request): array => [
                'http_code' => 200,
                'stream'    => self::memoryStream("<html>502 Bad Gateway</html>\n"),
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing multipart boundary');
        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testATruncatedResultFailsWithMissingCompletion(): void
    {
        // A stream that ends without the completion chunk is a failed
        // request (same contract the curl tail enforces), not a clean partial.
        $this->seedSourceTreeAndPreflight();
        $endpoint   = $this->newEndpoint();
        $realExport = $this->exportRunner();

        $truncating = static function (array $request) use ($realExport): array {
            $result = $realExport($request);
            $body   = (string) stream_get_contents($result['stream']);
            fclose($result['stream']);
            return [
                'http_code' => 200,
                'stream'    => self::memoryStream(substr($body, 0, (int) (strlen($body) * 0.8))),
            ];
        };

        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            $truncating
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing completion chunk');
        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testCorruptGzipFailsLoudlyInsteadOfTruncating(): void
    {
        $this->seedSourceTreeAndPreflight();
        $endpoint   = $this->newEndpoint();
        $realExport = $this->exportRunner();

        // Flip one byte inside the deflate data (the gzip header is the
        // first 10 bytes) — inflate_add() must surface this, not truncate.
        $corrupting = static function (array $request) use ($realExport): array {
            $result = $realExport($request);
            $body   = (string) stream_get_contents($result['stream']);
            fclose($result['stream']);
            $body[20] = chr(ord($body[20]) ^ 0xFF);
            return ['http_code' => 200, 'stream' => self::memoryStream($body)];
        };

        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            $corrupting
        );

        $this->expectException(\RuntimeException::class);
        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testANon200ResultIsNotAcceptedAsFetchJsonData(): void
    {
        // The curl path forces json=>null on non-200 so callers (preflight's
        // "payload !== null" gate) cannot mistake an error body for data.
        // The reverse guard must convert to the exact same shape.
        $client = new \ImportClient('http://reverse-transport.invalid/export.php', $this->stateDir, $this->fsRoot);
        $client->set_reverse_transport(
            new \ReverseTransport(self::memoryStream('{"error":"boom","trace":"t0"}'), 500)
        );

        $fetchJson = new \ReflectionMethod($client, 'fetch_json');
        $fetchJson->setAccessible(true);
        $result = $fetchJson->invoke($client, 'http://reverse-transport.invalid/export.php?endpoint=preflight');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['json'], 'an error body must never surface as data');
        $this->assertSame(500, $result['http_code']);
        $this->assertArrayHasKey('error_code', $result);
    }

    public function testCommandsWithoutPerRequestCheckpointsAreRejected(): void
    {
        // preflight (and the composite pull) issue several requests per
        // invocation with no persisted checkpoint between them, which breaks
        // resume-to-the-same-request; the endpoint refuses them up front.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported command');
        new \ReverseTransportEndpoint(
            static fn() => new \stdClass(),
            ['command' => 'preflight']
        );
    }

    public function testFollowSymlinksIsRefusedOverTheReverseTransport(): void
    {
        // Symlink discovery re-scans without a sub-stage checkpoint; until it
        // has one, silently mis-pairing results would corrupt the index — so
        // the importer refuses loudly (and the refusal reaches the source).
        $this->seedSourceTreeAndPreflight();
        $endpoint = $this->newEndpoint(['follow_symlinks' => true]);

        $source = new \ReverseTransportSource(
            static fn($resultStream, int $httpCode): string => $endpoint->handle_exchange($resultStream, $httpCode),
            $this->exportRunner()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('follow_symlinks');
        ob_start();
        try {
            $source->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testARunawayImporterIsCappedInsteadOfSpinning(): void
    {
        // A pass that neither finishes nor asks for anything must fail the
        // exchange loudly, not spin inside one HTTP request.
        $endpoint = new \ReverseTransportEndpoint(
            static fn() => new class {
                public $exit_code = 2;
                public function set_reverse_transport($transport): void
                {
                }
                public function run(array $options): void
                {
                }
            },
            ['command' => 'files-pull']
        );

        $response = json_decode($endpoint->handle_exchange(null), true);
        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('no outbound progress', $response['message']);
    }
}
