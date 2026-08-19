<?php

use PHPUnit\Framework\TestCase;

use function WordPress\Reprint\Server\wp_join_unix_paths;

/**
 * Drift tests for the copy of wp_join_unix_paths() in the exporter package.
 *
 * The original lives in wp-php-toolkit/filesystem. reprint-server cannot
 * require that package — see the header comment above the copy in utils.php —
 * so the behaviour is pinned here instead. Change these expectations only
 * when the upstream function changes.
 */
final class JoinUnixPathsTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function pathProvider(): array
    {
        return [
            'two segments'                  => [['/srv/site', 'wp-content'], '/srv/site/wp-content'],
            'three segments'                => [['/srv', 'site', 'wp-content'], '/srv/site/wp-content'],
            'single argument'               => [['/srv/site'], '/srv/site'],
            'no arguments'                  => [[], ''],
            'empty segments interspersed'   => [['', 'wp-content', ''], 'wp-content'],
            'only empty segments'           => [['', ''], ''],
            'leading empty segment'         => [['', '/srv', 'site'], '/srv/site'],
            'empty segment between'         => [['/a', '', '/b'], '/a/b'],
            'duplicate slashes joined'      => [['/srv/site/', '/wp-content'], '/srv/site/wp-content'],
            'duplicate slashes within'      => [['a//b', 'c'], 'a/b/c'],
            'triple slashes within'         => [['a///b'], 'a/b'],
            'leading double slash collapses' => [['//srv', 'site'], '/srv/site'],
            'leading slash preserved'       => [['/srv', 'site'], '/srv/site'],
            'relative stays relative'       => [['srv', 'site'], 'srv/site'],
            'later absolute does not win'   => [['a', '/b'], 'a/b'],
            'trailing slash preserved'      => [['/srv/site/'], '/srv/site/'],
            'trailing slash on last segment' => [['srv', 'site/'], 'srv/site/'],
            'filesystem root'               => [['/'], '/'],
            'root then segment'             => [['/', 'srv'], '/srv'],
            'dot segments are not resolved' => [['.', 'foo'], './foo'],
            'parent segments are not resolved' => [['/srv/site', '..', 'other'], '/srv/site/../other'],
        ];
    }

    /**
     * @dataProvider pathProvider
     * @param list<string> $segments
     */
    public function testJoinsSegments(array $segments, string $expected): void
    {
        $this->assertSame($expected, wp_join_unix_paths(...$segments));
    }

    /**
     * Compares the copy against the original when both are loaded.
     *
     * reprint-server does not require wp-php-toolkit/filesystem, so the
     * original is usually absent. When another package in the same install
     * pulls it in, this catches drift the table above would not.
     *
     * @dataProvider pathProvider
     * @param list<string> $segments
     */
    public function testMatchesTheUpstreamFunctionWhenItIsInstalled(array $segments, string $expected): void
    {
        $upstream = 'WordPress\\Filesystem\\wp_join_unix_paths';
        if (!function_exists($upstream)) {
            $this->markTestSkipped('wp-php-toolkit/filesystem is not installed.');
        }

        $this->assertSame($upstream(...$segments), wp_join_unix_paths(...$segments));
    }
}
