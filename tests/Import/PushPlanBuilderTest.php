<?php

namespace ImportTests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PushPlanBuilder;

require_once __DIR__ . '/../../importer/import.php';

class PushPlanBuilderTest extends TestCase
{
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

    public function testPlansRegularFilesWithRelativeIdsAndSizes(): void
    {
        $this->put('index.php', '<?php');
        $this->put('wp-content/themes/t/style.css', 'body{}');

        $result = PushPlanBuilder::build($this->root);

        $this->assertSame([], $result['skipped']);
        $this->assertSame(
            [
                ['artifact_id' => 'index.php', 'source_path' => $this->root . '/index.php', 'total_bytes' => 5],
                ['artifact_id' => 'wp-content/themes/t/style.css', 'source_path' => $this->root . '/wp-content/themes/t/style.css', 'total_bytes' => 6],
            ],
            $result['plan']
        );
    }

    public function testPlanOrderIsDeterministic(): void
    {
        foreach (['b.txt', 'a.txt', 'c/inner.txt', 'a-dir/z.txt'] as $rel) {
            $this->put($rel, 'x');
        }

        $first = PushPlanBuilder::build($this->root);
        $second = PushPlanBuilder::build($this->root);

        $this->assertSame($first['plan'], $second['plan']);
        $ids = array_column($first['plan'], 'artifact_id');
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids, 'scandir order is alphabetical per directory');
    }

    public function testSymlinksAreSkippedAndReportedNeverFollowed(): void
    {
        $this->put('real/wanted.txt', 'keep');
        $this->put('outside-target.txt', 'outside');
        symlink($this->root . '/outside-target.txt', $this->root . '/link.txt');
        // A directory symlink that would create a cycle if followed.
        symlink($this->root, $this->root . '/cycle');

        $result = PushPlanBuilder::build($this->root);

        $this->assertSame(
            ['outside-target.txt', 'real/wanted.txt'],
            array_column($result['plan'], 'artifact_id')
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

        $result = PushPlanBuilder::build($this->root);
        chmod($this->root . '/locked', 0700);

        $this->assertSame(['ok.txt'], array_column($result['plan'], 'artifact_id'));
        $this->assertSame([['path' => 'locked', 'reason' => 'unreadable_dir']], $result['skipped']);
    }

    public function testOnlyPrefixesFilterAndPrune(): void
    {
        $this->put('wp-content/uploads/a.jpg', 'aa');
        $this->put('wp-content/plugins/p/p.php', 'pp');
        $this->put('wp-includes/version.php', 'vv');
        $this->put('node_modules/dep/dep.js', 'jj');

        $result = PushPlanBuilder::build($this->root, ['wp-content/uploads', 'wp-includes/version.php']);

        $this->assertSame(
            ['wp-content/uploads/a.jpg', 'wp-includes/version.php'],
            array_column($result['plan'], 'artifact_id')
        );
    }

    public function testHostileOnlyPrefixesAreRejected(): void
    {
        foreach (['../outside', 'a/../b', '', '/', 'a\\b'] as $hostile) {
            try {
                PushPlanBuilder::build($this->root, [$hostile]);
                $this->fail("prefix should have been rejected: {$hostile}");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingRootIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PushPlanBuilder::build($this->root . '/never-existed');
    }

    public function testEmptyRootYieldsEmptyPlan(): void
    {
        $this->assertSame(['plan' => [], 'skipped' => []], PushPlanBuilder::build($this->root));
    }
}
