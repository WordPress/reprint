<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --remap: resolving template-string SOURCE TARGET mappings into remap rules.
 *
 * Each argument has its known `:token:`s substituted wherever they appear, then
 * must resolve to an absolute path. Source tokens are remote WP-layout locations
 * (:wp-uploads:, :abspath:, …); the target token is :fs-root:. Targets must stay
 * within --fs-root. Anything that doesn't resolve to an absolute path is an error.
 */
class RemapResolveTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root; // realpath of fsRoot (targets are rooted under it)

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remap-resolve-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/pull', 0755, true);
        mkdir($this->fsRoot, 0755, true);
        $this->root = realpath($this->fsRoot);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempDir);
        parent::tearDown();
    }

    private function rrm(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = "$d/$i";
            (is_dir($p) && !is_link($p)) ? $this->rrm($p) : unlink($p);
        }
        rmdir($d);
    }

    private function call($c, string $m, array $a = array())
    {
        return (new \ReflectionClass($c))->getMethod($m)->invoke($c, ...$a);
    }

    private function set($c, string $p, $v): void
    {
        (new \ReflectionClass($c))->getProperty($p)->setValue($c, $v);
    }

    private function client(array $pathsUrls): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $c->get_import_state()->preflight = array('data' => array(
            'database' => array('wp' => array('paths_urls' => $pathsUrls)),
        ));
        return $c;
    }

    private function resolve($c, array ...$mappings): array
    {
        return $this->call($c, 'resolve_remap', array($mappings));
    }

    private function assertRemapConsistent($c): void
    {
        $this->call($c, 'assert_resolved_path_mappings_consistent');
    }

    private function writeLocalIndex(): void
    {
        file_put_contents($this->stateDir . '/pull/local-index.jsonl', "{}\n");
    }

    // --- resolution: SOURCE TARGET → one source => target rule -------------

    /**
     * Each case resolves a single --remap mapping and asserts the resulting rule.
     * The expected target is given as a suffix appended to the (per-test) fs root.
     *
     * @dataProvider provideResolutionCases
     */
    public function testResolvesRuleToExpectedSourceAndTarget(
        array $pathsUrls,
        string $source,
        string $target,
        string $expectedSource,
        string $expectedTargetSuffix
    ): void {
        $rules = $this->resolve($this->client($pathsUrls), array($source, $target));
        $this->assertSame($this->root . $expectedTargetSuffix, $rules[$expectedSource]);
    }

    public static function provideResolutionCases(): array
    {
        return array(
            'wp-content token → content dir' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                ':wp-content:', ':fs-root:/wp-content',
                '/var/www/html/wp-content', '/wp-content',
            ),
            'component token + subpath resolves via preflight' => array(
                array('content_dir' => '/srv/wp-content', 'plugins_dir' => '/custom/plugins'),
                ':wp-plugins:/woocommerce', ':fs-root:/wp-content/plugins/woocommerce',
                '/custom/plugins/woocommerce', '/wp-content/plugins/woocommerce',
            ),
            'abspath token resolves non-content paths' => array(
                array('abspath' => '/var/www/html', 'content_dir' => '/var/www/html/wp-content'),
                ':abspath:/wp-admin', ':fs-root:/wp-admin',
                '/var/www/html/wp-admin', '/wp-admin',
            ),
            'raw absolute source used literally' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                '/var/log/site', ':fs-root:/logs',
                '/var/log/site', '/logs',
            ),
            'fs-root token + subpath' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                ':wp-content:', ':fs-root:/media',
                '/var/www/html/wp-content', '/media',
            ),
            'bare fs-root token is the root itself' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                ':wp-content:', ':fs-root:',
                '/var/www/html/wp-content', '',
            ),
            'trailing slashes trimmed (leading kept)' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                ':wp-content:/', ':fs-root:/media/',
                '/var/www/html/wp-content', '/media',
            ),
            'substitution is literal — no separator enforced' => array(
                array('content_dir' => '/var/www/html/wp-content', 'plugins_dir' => '/p'),
                ':wp-plugins:jetpack', ':fs-root:/x',
                '/pjetpack', '/x',
            ),
            'whitespace in a raw path is preserved' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                '/var/data/odd ', ':fs-root:/odd',
                '/var/data/odd ', '/odd',
            ),
        );
    }

    public function testRawAbsoluteTargetWithinRootUsedLiterally(): void
    {
        // An absolute target already inside --fs-root is used verbatim (the
        // :fs-root: token is sugar for exactly this).
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $rules = $this->resolve($c, array(':wp-content:', $this->root . '/wp-content'));
        $this->assertSame($this->root . '/wp-content', $rules['/var/www/html/wp-content']);
    }

    // --- detached-component expansion --------------------------------------

    public function testDetachedComponentsExpandUnderTarget(): void
    {
        // Remapping wp-content auto-follows components that live OUTSIDE
        // content_dir (uploads, mu-plugins here); a nested one (plugins) is
        // already covered by the wp-content rule and gets no separate rule.
        $c = $this->client(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/var/www/html/wp-content/plugins', // nested
            'mu_plugins_dir' => '/opt/muplugins',                // detached
            'uploads' => array('basedir' => '/mnt/blogs/uploads'), // detached
        ));
        $rules = $this->resolve($c, array(':wp-content:', ':fs-root:/wp-content'));
        $byTarget = array_flip($rules);
        $this->assertCount(3, $rules);
        $this->assertSame('/var/www/html/wp-content', $byTarget[$this->root . '/wp-content']);
        $this->assertSame('/mnt/blogs/uploads', $byTarget[$this->root . '/wp-content/uploads']);
        $this->assertSame('/opt/muplugins', $byTarget[$this->root . '/wp-content/mu-plugins']);
        $this->assertArrayNotHasKey($this->root . '/wp-content/plugins', $byTarget);
    }

    public function testExplicitComponentOverridesExpansion(): void
    {
        // A detached component sent somewhere explicitly wins over the
        // whole-wp-content expansion. Explicit rule listed FIRST to prove the
        // override is order-independent.
        $c = $this->client(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/detached/plugins',
        ));
        $rules = $this->resolve(
            $c,
            array(':wp-plugins:', ':fs-root:/special-plugins'),
            array(':wp-content:', ':fs-root:/wp-content')
        );
        $this->assertSame($this->root . '/special-plugins', $rules['/detached/plugins']);
    }

    // --- state consistency -------------------------------------------------

    public function testRejectsChangedRemapRulesForSameState(): void
    {
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $this->set($c, 'resolved_path_mappings', array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $this->assertRemapConsistent($c);

        $this->set($c, 'resolved_path_mappings', array(
            '/var/www/html/wp-content' => $this->root . '/content',
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --remap rules');
        $this->assertRemapConsistent($c);
    }

    public function testRejectsRemapWithUntrackedExistingFilesIndex(): void
    {
        $this->writeLocalIndex();
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $this->set($c, 'resolved_path_mappings', array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('existing files index');
        $this->assertRemapConsistent($c);
    }

    // --- errors ------------------------------------------------------------

    /**
     * Every case must resolve to a valid absolute path; these don't, so each is
     * rejected with InvalidArgumentException.
     *
     * @dataProvider provideInvalidArguments
     */
    public function testRejectsInvalidArgument(string $source, string $target): void
    {
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->resolve($c, array($source, $target));
    }

    public static function provideInvalidArguments(): array
    {
        return array(
            'bare relative source' => array('wp-content/uploads', ':fs-root:/uploads'),
            'bare relative target' => array(':wp-content:', 'media'),
            'unclosed token (not substituted)' => array(':wp-plugins', ':fs-root:/p'),
            'unknown source token' => array(':bogus:', ':fs-root:/x'),
            'source token not at beginning' => array('/tmp/:wp-content:', ':fs-root:/x'),
            'same source token repeated after beginning' => array(':wp-content:/:wp-content:', ':fs-root:/x'),
            'fs-root token invalid as source' => array(':fs-root:/x', ':fs-root:/x'),
            'remote token invalid as target' => array(':wp-content:', ':wp-content:/x'),
            'absolute target outside fs-root' => array(':wp-content:', '/media'),
            'target climbs out via ".."' => array(':wp-content:', ':fs-root:/safe/../../../etc'),
            'empty source' => array('', ':fs-root:/x'),
        );
    }

    public function testRejectsTargetTokenNotAtBeginning(): void
    {
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->resolve($c, array(':wp-content:', $this->root . '/:fs-root:'));
    }

    /**
     * A token whose value preflight didn't determine yields a clear,
     * preflight-naming error — from the single place that resolves tokens,
     * regardless of which token it is.
     *
     * @dataProvider provideUnavailableTokens
     */
    public function testUnavailableTokenNamesPreflight(array $pathsUrls, string $source): void
    {
        $c = $this->client($pathsUrls);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preflight');
        $this->resolve($c, array($source, ':fs-root:/x'));
    }

    public static function provideUnavailableTokens(): array
    {
        return array(
            ':abspath: with no abspath in preflight' => array(
                array('content_dir' => '/var/www/html/wp-content'),
                ':abspath:/wp-admin',
            ),
            ':wp-content: with no content_dir in preflight' => array(
                array(),
                ':wp-content:',
            ),
        );
    }

    public function testRawOnlyRemapNeedsNoPreflightComponents(): void
    {
        // Raw absolute source + target reference no tokens, so they resolve even
        // when preflight provided no component locations at all.
        $c = $this->client(array());
        $rules = $this->resolve($c, array('/var/www/site', $this->root . '/site'));
        $this->assertSame($this->root . '/site', $rules['/var/www/site']);
    }
}
