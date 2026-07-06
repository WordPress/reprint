<?php

namespace ImportTests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PushPlanBuilder;

require_once __DIR__ . '/../../importer/import.php';

class PushPlanBuilderTest extends TestCase {

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/push-plan-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_link($entry) || !is_dir($entry)) {
                @unlink($entry);
            } else {
                $this->removeDir($entry);
            }
        }
        @rmdir($dir);
    }

    private function put(string $rel, string $body): void
    {
        $path = $this->root . '/' . $rel;
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $body);
    }

    /**
     * Run build_index into a temp file and read it back as decoded entries
     * (path-sorted, mirroring the sort_index_file the caller applies) plus
     * the raw emission order.
     *
     * @return array{entries:array,skipped:array,order:array}
     */
    private function buildIndex(array $only = [], string $prefix = ''): array
    {
        $file = sys_get_temp_dir() . '/push-idx-' . bin2hex(random_bytes(6));
        $handle = fopen($file, 'wb');
        $build = PushPlanBuilder::build_index($this->root, $only, $handle, $prefix);
        fclose($handle);

        $entries = [];
        $order = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $d = json_decode($line, true);
            $path = base64_decode($d['path']);
            $order[] = $path;
            $entries[] = [
                'path' => $path,
                'ctime' => $d['ctime'],
                'size' => $d['size'],
                'type' => $d['type'],
            ];
        }
        @unlink($file);
        usort($entries, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return ['entries' => $entries, 'skipped' => $build['skipped'], 'order' => $order];
    }

    public function testIndexesRegularFilesInTheSharedFormat(): void
    {
        $this->put('index.php', '<?php');
        $this->put('wp-content/themes/t/style.css', 'body{}');
        touch($this->root . '/index.php', 1000);
        touch($this->root . '/wp-content/themes/t/style.css', 2000);

        $result = $this->buildIndex();

        $this->assertSame([], $result['skipped']);
        $this->assertSame(
            [
                ['path' => 'index.php', 'ctime' => 1000, 'size' => 5, 'type' => 'file'],
                ['path' => 'wp-content/themes/t/style.css', 'ctime' => 2000, 'size' => 6, 'type' => 'file'],
            ],
            $result['entries']
        );
    }

    public function testAbsolutePrefixMakesPathsWholeFilesystem(): void
    {
        $this->put('wp-content/x.php', 'x');
        touch($this->root . '/wp-content/x.php', 1000);
        $prefix = rtrim( (string) realpath($this->root), '/');

        $result = $this->buildIndex([], $prefix);

        $this->assertSame(
            [['path' => $prefix . '/wp-content/x.php', 'ctime' => 1000, 'size' => 1, 'type' => 'file']],
            $result['entries']
        );
    }

    public function testEmissionOrderIsDeterministic(): void
    {
        foreach (['b.txt', 'a.txt', 'c/inner.txt', 'a-dir/z.txt'] as $rel) {
            $this->put($rel, 'x');
        }

        $first = $this->buildIndex();
        $second = $this->buildIndex();

        $this->assertSame($first['order'], $second['order'], 'two walks of the same tree emit identically');
        $sorted = $first['order'];
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $first['order'], 'scandir order is alphabetical per directory');
    }

    public function testSymlinksAreSkippedAndReportedNeverFollowed(): void
    {
        $this->put('real/wanted.txt', 'keep');
        $this->put('outside-target.txt', 'outside');
        symlink($this->root . '/outside-target.txt', $this->root . '/link.txt');
        // A directory symlink that would create a cycle if followed.
        symlink($this->root, $this->root . '/cycle');

        $result = $this->buildIndex();

        $this->assertSame(
            ['outside-target.txt', 'real/wanted.txt'],
            array_column($result['entries'], 'path')
        );
        $skipped = $result['skipped'];
        usort($skipped, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $this->assertSame(
            [
                ['path' => 'cycle', 'reason' => 'symlink'],
                ['path' => 'link.txt', 'reason' => 'symlink'],
            ],
            $skipped
        );
    }

    public function testUnreadableDirectoryIsReportedNotFatal(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks do not bind as root.');
        }
        $this->put('ok.txt', 'fine');
        $this->put('locked/secret.txt', 'hidden');
        chmod($this->root . '/locked', 0000);

        $result = $this->buildIndex();
        chmod($this->root . '/locked', 0700);

        $this->assertSame(['ok.txt'], array_column($result['entries'], 'path'));
        $this->assertSame([['path' => 'locked', 'reason' => 'unreadable_dir']], $result['skipped']);
    }

    public function testOnlyPrefixesFilterAndPrune(): void
    {
        $this->put('wp-content/uploads/a.jpg', 'aa');
        $this->put('wp-content/plugins/p/p.php', 'pp');
        $this->put('wp-includes/version.php', 'vv');
        $this->put('node_modules/dep/dep.js', 'jj');

        $result = $this->buildIndex(['wp-content/uploads', 'wp-includes/version.php']);

        $this->assertSame(
            ['wp-content/uploads/a.jpg', 'wp-includes/version.php'],
            array_column($result['entries'], 'path')
        );
    }

    public function testHostileOnlyPrefixesAreRejected(): void
    {
        foreach (['../outside', 'a/../b', '', '/', 'a\\b'] as $hostile) {
            try {
                PushPlanBuilder::normalize_only([$hostile]);
                $this->fail("prefix should have been rejected: {$hostile}");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingRootIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $handle = fopen('php://memory', 'wb');
        PushPlanBuilder::build_index($this->root . '/never-existed', [], $handle);
    }

    public function testEmptyRootYieldsEmptyIndex(): void
    {
        $result = $this->buildIndex();
        $this->assertSame([], $result['entries']);
        $this->assertSame([], $result['skipped']);
    }
}
