<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use LocalLink;
use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Local links resolve from their own directory on every client OS. */
final class LocalLinkTest extends TestCase
{
    private string $root;

    /** @var string Working directory before the test changed it. */
    private string $working_directory;

    protected function setUp(): void
    {
        $this->working_directory = getcwd();
        $this->root = sys_get_temp_dir() . '/local-link-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files/wordpress/plugins/akismet/latest', 0700, true);
        mkdir($this->root . '/files/wp-content/plugins', 0700, true);
        mkdir($this->root . '/elsewhere', 0700);
        file_put_contents($this->root . '/files/wordpress/plugins/akismet/latest/akismet.php', 'akismet');
        $this->root = Utils::realpath_with_missing_tail($this->root);
        if (!$this->can_create_symlinks()) {
            $this->markTestSkipped('This account cannot create symlinks.');
        }
        // PHP once checked relative targets from here rather than from the link.
        chdir($this->root . '/elsewhere');
    }

    protected function tearDown(): void
    {
        chdir($this->working_directory);
        if (isset($this->root)) {
            $this->remove_tree($this->root);
        }
    }

    public function testADirectoryLinkResolvesFromAnotherWorkingDirectory(): void
    {
        $link_path = $this->root . '/files/wp-content/plugins/akismet';
        $link_value = LocalLink::link_value(dirname($link_path), $this->root . '/files/wordpress/plugins/akismet/latest');

        $this->assertTrue(LocalLink::create_symlink($link_value, $link_path));

        $this->assertTrue(LocalLink::is_link($link_path));
        $this->assertSame('akismet', file_get_contents($link_path . '/akismet.php'));
        $this->assertSame($this->root . '/elsewhere', Utils::normalize_path(getcwd(), Utils::native_path_format()), 'the working directory is restored');
        $this->assertTrue(LocalLink::has_value($link_path, $link_value));
        $this->assertFalse(LocalLink::has_value($link_path, LocalLink::link_value(dirname($link_path), $this->root . '/elsewhere')));
    }

    public function testAFileLinkResolvesFromAnotherWorkingDirectory(): void
    {
        $link_path = $this->root . '/files/wp-content/akismet.php';
        $link_value = LocalLink::link_value(dirname($link_path), $this->root . '/files/wordpress/plugins/akismet/latest/akismet.php');

        $this->assertTrue(LocalLink::create_symlink($link_value, $link_path));

        $this->assertSame('akismet', file_get_contents($link_path));
        $this->assertTrue(LocalLink::has_value($link_path, $link_value));
    }

    /** A Unix source sends forward slashes; the local value must still resolve. */
    public function testASourceLinkValueResolvesOnThisFilesystem(): void
    {
        $link_path = $this->root . '/files/wp-content/plugins/akismet';
        $link_value = LocalLink::native_link_value('../../wordpress/plugins/akismet/latest', $link_path);

        $this->assertTrue(LocalLink::create_symlink($link_value, $link_path));

        $this->assertSame('akismet', file_get_contents($link_path . '/akismet.php'));
        $this->assertTrue(LocalLink::has_value($link_path, $link_value));
    }

    public function testRemovingADirectoryLinkKeepsItsTarget(): void
    {
        $link_path = $this->root . '/files/wp-content/plugins/akismet';
        $link_value = LocalLink::native_link_value('../../wordpress/plugins/akismet/latest', $link_path);
        $this->assertTrue(LocalLink::create_symlink($link_value, $link_path));

        $this->assertTrue(LocalLink::remove_symlink($link_path));

        $this->assertFalse(LocalLink::is_link($link_path));
        $this->assertFileDoesNotExist($link_path);
        $this->assertFileExists($this->root . '/files/wordpress/plugins/akismet/latest/akismet.php');
    }

    /**
     * Records the Windows behavior LocalLink works around.
     *
     * A forward-slash relative value is stored verbatim and does not resolve,
     * and readlink() reports the resolved target instead of a relative value.
     */
    public function testWindowsLinkBehaviorThatLocalLinkWorksAround(): void
    {
        if (Utils::native_path_format() !== 'windows') {
            $this->markTestSkipped('Windows link behavior.');
        }
        chdir($this->root . '/files/wp-content/plugins');
        if (!@symlink('../../wordpress/plugins/akismet/latest', 'forward')) {
            $this->markTestSkipped('PHP cannot create symlinks for this account.');
        }
        $this->assertTrue(symlink('..\\..\\wordpress\\plugins\\akismet\\latest', 'backward'));

        $this->assertFileDoesNotExist($this->root . '/files/wp-content/plugins/forward/akismet.php');
        $this->assertFileExists($this->root . '/files/wp-content/plugins/backward/akismet.php');
        $this->assertSame(
            realpath($this->root . '/files/wordpress/plugins/akismet/latest'),
            readlink($this->root . '/files/wp-content/plugins/backward')
        );
    }

    /** flat-docroot reaches a pulled plugin link through its relative wp-content link. */
    public function testALinkReachedThroughARelativeLinkResolves(): void
    {
        mkdir($this->root . '/site');
        $site_link_path = $this->root . '/site/wp-content';
        $this->assertTrue(LocalLink::create_symlink(LocalLink::native_link_value('../files/wp-content', $site_link_path), $site_link_path));
        $plugin_link_path = $this->root . '/files/wp-content/plugins/akismet';
        $this->assertTrue(LocalLink::create_symlink(LocalLink::native_link_value('../../wordpress/plugins/akismet/latest', $plugin_link_path), $plugin_link_path));

        $this->assertSame('akismet', file_get_contents($this->root . '/site/wp-content/plugins/akismet/akismet.php'));
    }

    /** An account without the symlink privilege gets a junction for a directory. */
    public function testAJunctionStandsInForADirectorySymlink(): void
    {
        if (Utils::native_path_format() !== 'windows') {
            $this->markTestSkipped('Junctions are a Windows substitute.');
        }
        $target_path = $this->root . '/files/wordpress/plugins/akismet/latest';
        $link_path = $this->root . '/files/wp-content/plugins/akismet';
        $link_value = LocalLink::link_value(dirname($link_path), $target_path);

        $this->assertTrue($this->create_substitute($link_value, $link_path));

        $this->assertFalse(is_link($link_path), 'PHP does not report a junction as a link');
        $this->assertTrue(LocalLink::is_link($link_path));
        $this->assertTrue(LocalLink::has_value($link_path, $link_value));
        $this->assertSame('akismet', file_get_contents($link_path . '/akismet.php'));
        LocalLink::remove_without_following_links($link_path);
        $this->assertFalse(LocalLink::is_link($link_path));
        $this->assertFileDoesNotExist($link_path);
        $this->assertSame('akismet', file_get_contents($target_path . '/akismet.php'), 'removing the junction keeps its target');
    }

    /** An account without the symlink privilege gets a hard link for a file. */
    public function testAHardLinkStandsInForAFileSymlink(): void
    {
        if (Utils::native_path_format() !== 'windows') {
            $this->markTestSkipped('Hard links are a Windows substitute.');
        }
        $target_path = $this->root . '/files/wordpress/plugins/akismet/latest/akismet.php';
        $link_path = $this->root . '/files/wp-content/akismet.php';
        $link_value = LocalLink::link_value(dirname($link_path), $target_path);

        $this->assertTrue($this->create_substitute($link_value, $link_path));

        $this->assertSame('akismet', file_get_contents($link_path));
        $this->assertTrue(LocalLink::has_value($link_path, $link_value));
        $this->assertFalse(LocalLink::has_value($link_path, LocalLink::link_value(dirname($link_path), $this->root . '/elsewhere')));
        LocalLink::remove_without_following_links($link_path);
        $this->assertFileDoesNotExist($link_path);
        $this->assertSame('akismet', file_get_contents($target_path));
    }

    /** Calls the substitute directly, so an administrator account covers it too. */
    private function create_substitute(string $link_value, string $link_path): bool
    {
        return ( new \ReflectionMethod(LocalLink::class, 'create_symlink_substitute') )->invoke(null, $link_value, $link_path);
    }

    private function can_create_symlinks(): bool
    {
        $probe = $this->root . '/elsewhere/probe';
        if (!LocalLink::create_symlink($this->root . '/files', $probe)) {
            return false;
        }
        LocalLink::remove_symlink($probe);
        return true;
    }

    private function remove_tree(string $path): void
    {
        if (LocalLink::is_link($path)) {
            LocalLink::remove_symlink($path);
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->remove_tree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
