<?php

declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing test class style.

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\compare_paths;
use function WordPress\Reprint\Exporter\path_sort_key;

final class PathComparisonTest extends TestCase
{
    public function testComparesPathsInDepthFirstComponentOrder(): void
    {
        $paths = ['directory-sibling', 'directory/child', 'directory', 'next'];

        usort($paths, static function (string $left_path, string $right_path): int {
            return compare_paths($left_path, $right_path);
        });

        $this->assertSame(
            ['directory', 'directory/child', 'directory-sibling', 'next'],
            $paths
        );
    }

    public function testBuildsPathSortKeyFromComponents(): void
    {
        $this->assertSame("directory\0child", path_sort_key('directory/child'));
    }
}
