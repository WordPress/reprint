<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileFetchCompressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-fetch-compression-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFileFetchUsesIdentityForBinaryPaths(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/photo.jpg';
        file_put_contents($filePath, 'pretend-jpeg-bytes');

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'binary file_fetch should not be gzip framed');
        $this->assertStringContainsString('pretend-jpeg-bytes', $stdout);
    }

    public function testFileFetchUsesGzipForTextOnlyPaths(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/style.css';
        // Use repetitive content so gzip output is unambiguously smaller than input.
        file_put_contents($filePath, str_repeat("body { color: red; }\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        // gzip framing means the response starts with the deflate magic bytes,
        // not the multipart boundary — the boundary lives inside the
        // compressed stream.
        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'text-only file_fetch should be gzip framed');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'gzip body should decode');
        $this->assertStringStartsWith('--boundary-', $decoded);
        $this->assertStringContainsString('body { color: red; }', $decoded);
    }

    public function testFileFetchGzipsMixedBatchesIfAnyFileIsCompressible(): void
    {
        // A mixed batch (some text + some binary) gets gzipped at the
        // response level. The binary portion passes through deflate as
        // literal stored blocks (~0 % size change, ~4 ms CPU per 200 KB);
        // the text portion compresses 5–60×. Net: a ~50 % wire reduction
        // on a typical wp-content batch with mostly assets.
        //
        // The previous behaviour (fall back to identity if any binary)
        // sacrificed all gzip benefit on these batches.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $textPath = $siteDir . '/style.css';
        $binaryPath = $siteDir . '/photo.jpg';
        file_put_contents($textPath, str_repeat("body { color: red; }\n", 200));
        file_put_contents($binaryPath, 'pretend-jpeg-bytes');

        $stdout = $this->runFileFetch($siteDir, [$textPath, $binaryPath]);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'mixed batch should be gzip framed');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'gzip body should decode');
        $this->assertStringContainsString('body { color: red; }', $decoded);
        $this->assertStringContainsString('pretend-jpeg-bytes', $decoded);
    }

    public function testFileFetchKeepsIdentityWhenAllPathsAreBinary(): void
    {
        // Pure-binary batch — no compressible files at all. Must stay
        // identity; otherwise we'd burn server CPU re-deflating already
        // compressed image bytes for ~0 % wire reduction.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $jpg = $siteDir . '/photo.jpg';
        $png = $siteDir . '/icon.png';
        $mp4 = $siteDir . '/clip.mp4';
        file_put_contents($jpg, 'jpeg-blob');
        file_put_contents($png, 'png-blob');
        file_put_contents($mp4, 'mp4-blob');

        $stdout = $this->runFileFetch($siteDir, [$jpg, $png, $mp4]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'all-binary batch must stay identity');
        $this->assertStringContainsString('jpeg-blob', $stdout);
        $this->assertStringContainsString('png-blob', $stdout);
        $this->assertStringContainsString('mp4-blob', $stdout);
    }

    public function testFileFetchGzipsBatchWhereOnlyOneFileIsCompressible(): void
    {
        // Edge case: 99 binary files + 1 readme. The single text file
        // alone is enough to flip the response to gzip. The 99 binaries
        // ride through deflate as stored blocks (no inflation). This
        // codifies the "any compressible → gzip" rule on a slightly
        // pathological mix — we accept the CPU cost on the binary
        // majority because per-request total size is bounded server-side.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $paths = [];
        for ($i = 0; $i < 8; $i++) {
            $p = $siteDir . sprintf('/blob-%d.jpg', $i);
            file_put_contents($p, 'binary-' . $i);
            $paths[] = $p;
        }
        $readme = $siteDir . '/README';
        file_put_contents($readme, str_repeat("Welcome to the plugin.\n", 100));
        $paths[] = $readme;

        $stdout = $this->runFileFetch($siteDir, $paths);
        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'one compressible file flips the batch to gzip');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('Welcome to the plugin.', $decoded);
        $this->assertStringContainsString('binary-0', $decoded);
        $this->assertStringContainsString('binary-7', $decoded);
    }

    public function testFileFetchGzipsBatchWithUnknownTextAndKnownBinary(): void
    {
        // Mixed: an unknown-extension file with text bytes (sniffer says
        // text) plus a known-binary jpeg. The unknown-text contributes a
        // 'yes' classification → response gzipped.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $unknownText = $siteDir . '/config.neon';
        file_put_contents($unknownText, str_repeat("services: { foo: Foo\\Bar }\n", 100));
        $jpg = $siteDir . '/photo.jpg';
        file_put_contents($jpg, 'jpeg-blob');

        $stdout = $this->runFileFetch($siteDir, [$unknownText, $jpg]);
        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2));
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('services:', $decoded);
        $this->assertStringContainsString('jpeg-blob', $decoded);
    }

    public function testFileFetchKeepsIdentityWithUnknownBinaryAndKnownBinary(): void
    {
        // No compressible file in the batch — even with unknown extensions,
        // sniffer says binary → no gzip.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $unknownBin = $siteDir . '/blob.weirdext';
        file_put_contents($unknownBin, "\x00\xff\x01\xfe" . random_bytes(2048));
        $jpg = $siteDir . '/photo.jpg';
        file_put_contents($jpg, 'jpeg-blob');

        $stdout = $this->runFileFetch($siteDir, [$unknownBin, $jpg]);
        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'no compressible file → identity');
    }

    public function testFileFetchGzipsBatchWithExtensionlessTextAndImage(): void
    {
        // .htaccess + a screenshot — the canonical "theme/plugin
        // distribution" shape. Pre-PR this was identity; now it gzips.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $ht = $siteDir . '/.htaccess';
        file_put_contents($ht, str_repeat("RewriteRule ^index\\.php$ - [L]\n", 200));
        $png = $siteDir . '/screenshot.png';
        file_put_contents($png, 'pretend-png-bytes');

        $stdout = $this->runFileFetch($siteDir, [$ht, $png]);
        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2));
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('RewriteRule', $decoded);
        $this->assertStringContainsString('pretend-png-bytes', $decoded);
    }

    public function testFileFetchDoesNotCrashOnDirectoryInList(): void
    {
        // A directory can appear in a file_fetch batch, and
        // when the name has an unrecognized dotted extension
        // (e.g. a theme dir like "sometheme-2.4.5") then the
        // gzip heuristic probes its bytes: fopen() succeeds on a directory but
        // the unguarded fread() would fatal, crashing the whole request.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $dirPath = $siteDir . '/storefront-2.4.5';
        mkdir($dirPath, 0755, true);

        // runFileFetch asserts the worker exits 0 — without the guard it exits
        // 1 (the fread fatal is escalated by export.php's error handler).
        $stdout = $this->runFileFetch($siteDir, [$dirPath]);

        // The directory is handled (a 0-byte directory chunk), not read; the
        // identity-framed multipart response is well-formed.
        $this->assertStringStartsWith('--boundary-', $stdout);
    }

    public function testFileFetchEmptyListStaysIdentity(): void
    {
        // Empty path list — nothing to compress → identity. (The endpoint
        // itself probably won't accept this, but the heuristic must.)
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';
        $this->assertFalse(file_fetch_paths_should_gzip([]));
    }

    public function testFileFetchHeuristicDirectly(): void
    {
        // Direct unit test of the heuristic — broader coverage than the
        // in-process file_fetch tests above.
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';

        // Single-file cases.
        $this->assertTrue(file_fetch_paths_should_gzip(['a.php']),                     'single text');
        $this->assertFalse(file_fetch_paths_should_gzip(['photo.jpg']),                'single binary');
        $this->assertTrue(file_fetch_paths_should_gzip(['.htaccess']),                 'single extensionless');

        // Multi-file: any-compressible flips on.
        $this->assertTrue(file_fetch_paths_should_gzip(['a.php', 'b.jpg']),            'text + binary');
        $this->assertTrue(file_fetch_paths_should_gzip(['a.jpg', 'b.css', 'c.mp4']),   'majority binary, one text');
        $this->assertFalse(file_fetch_paths_should_gzip(['a.jpg', 'b.png', 'c.mp4']),  'all binary');

        // Bad input — defensive.
        $this->assertFalse(file_fetch_paths_should_gzip([null]),                       /** @phpstan-ignore-line */
            'non-string entry rejects the batch');
        $this->assertFalse(file_fetch_paths_should_gzip([42]),                         /** @phpstan-ignore-line */
            'numeric entry rejects the batch');

        // A non-string AFTER a compressible file must still reject — the
        // any-compressible short-circuit skips classification work but must not
        // skip the is_string check (i.e. it's a loop-skip, not a return-true).
        $this->assertFalse(file_fetch_paths_should_gzip(['a.php', null]),              /** @phpstan-ignore-line */
            'non-string after a compressible entry still rejects the batch');
    }

    public function testUnknownExtensionWithTextContentGetsGzipped(): void
    {
        // A made-up text format the whitelist doesn't know about. The byte
        // sniffer should rescue it: first 64 bytes are clean ASCII, no NULs,
        // valid UTF-8.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/config.neon';
        file_put_contents($filePath, str_repeat("services: { foo: Foo\\Bar }\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'unknown text extension should sniff as text and gzip');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('services:', $decoded);
    }

    public function testUnknownExtensionWithBinaryContentStaysIdentity(): void
    {
        // Same unknown extension, but the bytes are binary (NULs + high-bit
        // junk). The sniffer should reject and the response should be identity.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/blob.weirdext';
        file_put_contents($filePath, "\x00\xff\x01\xfe" . random_bytes(2048));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'unknown binary extension should sniff as binary and stay identity');
    }

    public function testFileFetchTreatsExtensionlessFilesAsCompressible(): void
    {
        // .htaccess, LICENSE, README, etc. — pathinfo() reports an empty
        // extension for these, but they're text files in practice, so we
        // gzip them. Important specifically for WordPress: `.htaccess`
        // ships in nearly every site and is plain text.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/.htaccess';
        file_put_contents($filePath, str_repeat("RewriteRule ^index\\.php$ - [L]\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), '.htaccess should be gzip framed');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('RewriteRule', $decoded);
    }

    /**
     * @dataProvider pathExtensionCases
     */
    public function testPathExtensionClassifier(string $path, bool $expected): void
    {
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';
        $this->assertSame($expected, path_extension_compressibility($path) === 'yes', "classifier for $path");
    }

    public static function pathExtensionCases(): array
    {
        return [
            'php source' => ['/site/wp-content/plugins/foo/foo.php', true],
            'js source' => ['/site/script.js', true],
            'css' => ['/site/style.css', true],
            'json config' => ['/site/composer.json', true],
            'sql dump' => ['/site/dump.sql', true],
            'pot translation' => ['/site/lang/en.pot', true],
            'svg vector' => ['/site/logo.svg', true],
            '.htaccess no extension' => ['/site/.htaccess', true],
            'README' => ['/site/README', true],
            'jpeg upload' => ['/site/uploads/photo.jpg', false],
            'png upload' => ['/site/uploads/icon.png', false],
            'webp upload' => ['/site/uploads/banner.webp', false],
            'mp4 video' => ['/site/uploads/clip.mp4', false],
            'mp3 audio' => ['/site/uploads/podcast.mp3', false],
            'woff2 font' => ['/site/fonts/inter.woff2', false],
            'pdf doc' => ['/site/uploads/brochure.pdf', false],
            'zip archive' => ['/site/backup.zip', false],
            'random binary' => ['/site/uploads/blob.bin', false],
            'gzipped already' => ['/site/dump.sql.gz', false],
            // Case-insensitive — uploads with screaming extensions are real.
            'uppercase JPG' => ['/site/uploads/PHOTO.JPG', false],
            'mixed-case Css' => ['/site/Style.Css', true],
        ];
    }

    private function runFileFetch(string $siteDir, array $paths): string
    {
        $listPath = $this->tempDir . '/file-list.json';
        file_put_contents($listPath, json_encode($paths, JSON_THROW_ON_ERROR));

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode([
            'directory' => $siteDir,
            'file_list_path' => $listPath,
        ], JSON_THROW_ON_ERROR));

        $scriptPath = $this->tempDir . '/run-file-fetch.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_fetch($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/packages/reprint-exporter/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_fetch should exit cleanly.\nstderr: {$stderr}");

        return $stdout;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
