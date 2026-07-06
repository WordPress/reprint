<?php

declare(strict_types=1);

namespace RelayTests;

use PHPUnit\Framework\TestCase;
use RelayExchange;
use RelaySourceWorker;
use RelayTransport;

$relay = __DIR__ . '/../../packages/reprint-importer/src/lib/relay/';
require_once $relay . 'class-transport-yield.php';
require_once $relay . 'class-relay-transport.php';
require_once $relay . 'class-relay-exchange.php';
require_once $relay . 'class-relay-source-worker.php';

/**
 * End-to-end demo of the single-endpoint reverse transport: a source site that
 * only makes OUTBOUND requests, a remote that DRIVES the transfer and owns all
 * state, real file bytes flowing source -> remote, one bounded exchange per
 * command. The relay mechanism (RelayTransport, TransportYield, RelayExchange,
 * RelaySourceWorker) is the real, reusable code under packages/. The tiny file
 * source and mirror driver below stand in for export.php's file_index/
 * file_fetch and the files-pull command respectively — routing the real ones
 * through this same seam is the production follow-up.
 */
final class ReverseTransportDemoTest extends TestCase
{
    private string $sourceRoot;
    private string $destRoot;
    private string $remoteState;

    protected function setUp(): void
    {
        $suffix           = bin2hex(random_bytes(6));
        $this->sourceRoot = sys_get_temp_dir() . '/relay-demo-source-' . $suffix;
        $this->destRoot   = sys_get_temp_dir() . '/relay-demo-dest-' . $suffix;
        $this->remoteState = sys_get_temp_dir() . '/relay-demo-state-' . $suffix;
        mkdir($this->sourceRoot, 0700, true);
        mkdir($this->destRoot, 0700, true);
        mkdir($this->remoteState, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->sourceRoot, $this->destRoot, $this->remoteState] as $dir) {
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

    /** Recursively list regular files under a root, fs-root-relative. */
    private function listFiles(string $root): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
            }
        }
        sort($out);
        return $out;
    }

    // --- Stand-in for export.php: runs against the SOURCE's own roots only. ---
    private function sourceExecute(array $command): array
    {
        switch ($command['op'] ?? '') {
            case 'list':
                return ['files' => $this->listFiles($this->sourceRoot)];
            case 'fetch':
                $rel = (string) ($command['path'] ?? '');
                // Containment: the source only serves within its own root.
                $abs = $this->sourceRoot . '/' . $rel;
                $real = realpath($abs);
                if ($real === false || strpos($real, realpath($this->sourceRoot) . '/') !== 0) {
                    throw new \RuntimeException("source refuses out-of-root path: {$rel}");
                }
                return ['bytes' => base64_encode((string) file_get_contents($abs))];
            default:
                throw new \RuntimeException('source: unknown op');
        }
    }

    // --- Stand-in for files-pull: resumable, remote-side, owns all state. ---
    private function driverStep(RelayTransport $transport): void
    {
        $statePath = $this->remoteState . '/relay-driver.json';
        $state     = is_file($statePath)
            ? (array) json_decode((string) file_get_contents($statePath), true)
            : ['phase' => 'init'];
        $save = function (array $s) use ($statePath): void {
            file_put_contents($statePath, (string) json_encode($s));
        };

        if (($state['phase'] ?? 'init') === 'init') {
            $list  = $transport->request(['op' => 'list']);      // yields, then (next exchange) delivers
            $files = array_values((array) ($list['files'] ?? []));
            sort($files);
            $state = ['phase' => 'fetch', 'files' => $files, 'next' => 0];
            $save($state);
            if ($files === []) {
                $save(['phase' => 'done']);
                return;
            }
            $transport->request(['op' => 'fetch', 'path' => $files[0]]);   // yields the first file
            return;
        }

        if (($state['phase'] ?? '') === 'fetch') {
            $files = $state['files'];
            $path  = $files[$state['next']];
            $result = $transport->request(['op' => 'fetch', 'path' => $path]);  // delivers bytes
            $this->put($this->destRoot, $path, (string) base64_decode((string) $result['bytes']));
            $state['next']++;
            $save($state);
            if ($state['next'] >= count($files)) {
                $save(['phase' => 'done']);
                return;
            }
            $transport->request(['op' => 'fetch', 'path' => $files[$state['next']]]);  // yields the next
            return;
        }
        // done: nothing left to do — the exchange returns "done".
    }

    public function testRemoteDrivenPullMirrorsTheSourceOverOutboundOnlyExchanges(): void
    {
        // A small source tree "on the local machine".
        $this->put($this->sourceRoot, 'index.php', '<?php // home');
        $this->put($this->sourceRoot, 'wp-content/themes/t/style.css', "body{color:red}\n");
        $this->put($this->sourceRoot, 'wp-content/uploads/a.bin', str_repeat("\x00\x01\x02", 400));

        // The remote endpoint drives the (stand-in) importer inline.
        $exchange = new RelayExchange(fn(RelayTransport $t) => $this->driverStep($t));

        // The local worker only makes outbound calls to that endpoint, and
        // executes each returned command against the source in-process.
        $worker = new RelaySourceWorker(
            fn(array $request): array => $exchange->handle($request),
            fn(array $command): array => $this->sourceExecute($command)
        );

        $worker->run();

        // The remote's tree now mirrors the source, byte-for-byte.
        $sourceFiles = $this->listFiles($this->sourceRoot);
        $destFiles   = $this->listFiles($this->destRoot);
        $this->assertSame($sourceFiles, $destFiles, 'every source file landed on the remote');
        foreach ($sourceFiles as $rel) {
            $this->assertSame(
                file_get_contents($this->sourceRoot . '/' . $rel),
                file_get_contents($this->destRoot . '/' . $rel),
                "byte-identical: {$rel}"
            );
        }

        // One command per outbound exchange: list, then one fetch per file.
        $transcript = $worker->transcript();
        $ops        = array_map(static fn(array $t): string => $t['command']['op'], $transcript);
        $this->assertSame(
            ['list', 'fetch', 'fetch', 'fetch'],
            $ops,
            'exactly one command advanced per exchange'
        );

        // Print the demo transcript so `phpunit` shows the flow.
        fwrite(STDERR, "\n--- reverse transport demo: " . count($sourceFiles) . " files in "
            . count($transcript) . " outbound exchanges ---\n");
        foreach ($transcript as $t) {
            $c    = $t['command'];
            $desc = $c['op'] === 'fetch' ? "fetch {$c['path']}" : $c['op'];
            fwrite(STDERR, sprintf("  exchange %d  ->  remote asked local to: %s\n", $t['exchange'], $desc));
        }
        fwrite(STDERR, "  remote now mirrors the source; the local side only ever sent outbound requests.\n");
    }

    public function testResumeAfterTheRemoteLosesTheWorkerMidTransfer(): void
    {
        $this->put($this->sourceRoot, 'a.txt', 'aaa');
        $this->put($this->sourceRoot, 'b.txt', 'bbb');
        $this->put($this->sourceRoot, 'c.txt', 'ccc');

        $exchange = new RelayExchange(fn(RelayTransport $t) => $this->driverStep($t));

        // First worker dies after two exchanges (list + first fetch).
        $steps   = 0;
        $partial = new RelaySourceWorker(
            function (array $request) use ($exchange, &$steps): array {
                if ($steps++ >= 2) {
                    throw new \RuntimeException('worker crashed');
                }
                return $exchange->handle($request);
            },
            fn(array $command): array => $this->sourceExecute($command)
        );
        try {
            $partial->run();
            $this->fail('expected the worker to crash');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker crashed', $e->getMessage());
        }

        // The remote kept its own state. A fresh worker resumes and finishes —
        // the remote never re-asked for the file it already has.
        $resumed = new RelaySourceWorker(
            fn(array $request): array => $exchange->handle($request),
            fn(array $command): array => $this->sourceExecute($command)
        );
        $resumed->run();

        $this->assertSame($this->listFiles($this->sourceRoot), $this->listFiles($this->destRoot));
        foreach ($this->listFiles($this->sourceRoot) as $rel) {
            $this->assertSame(
                file_get_contents($this->sourceRoot . '/' . $rel),
                file_get_contents($this->destRoot . '/' . $rel)
            );
        }
    }
}
