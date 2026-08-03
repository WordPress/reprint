<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\assert_valid_relative_path;
use function WordPress\Reprint\Exporter\path_is_descendant_of;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * --remap: the PulledFilesystem write seam
 * routes remote absolute paths to local absolute paths and leaves the rest nested.
 */
class RemapSeamTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remap-seam-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
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

    private function invokePrivateMethod($instance, string $method_name, array $arguments = array())
    {
        return ( new \ReflectionClass($instance) )
            ->getMethod($method_name)
            ->invoke($instance, ...$arguments);
    }

    private function setPrivateProperty($instance, string $property_name, $value): void
    {
        ( new \ReflectionClass($instance) )
            ->getProperty($property_name)
            ->setValue($instance, $value);
    }

    private function newClientWithResolvedPathMappings(array $resolved_path_mappings): \ImportClient
    {
        $client = new \ImportClient(
            'https://src.example/export.php',
            $this->stateDir,
            $this->fsRoot
        );
        $this->setPrivateProperty(
            $client,
            'resolved_path_mappings',
            $resolved_path_mappings
        );
        return $client;
    }

    private function filesystemWithRules(array $rules): \Reprint\Importer\Filesystem\PulledFilesystem
    {
        return new \Reprint\Importer\Filesystem\PulledFilesystem(
            $this->fsRoot,
            $rules,
            null,
            'error',
            [],
        );
    }

    public function testRemoteAbsolutePathMapsToLocalAbsolutePath(): void
    {
        $filesystem = $this->filesystemWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local_absolute_path = $filesystem
            ->map_remote_absolute_path_to_local_absolute_path(
                '/var/www/html/wp-content/plugins/woo/woo.php'
            );
        $this->assertSame($this->root . '/wp-content/plugins/woo/woo.php', $local_absolute_path);
    }

    public function testDeeperRemotePrefixWinsRegardlessOfLocalPrefixLength(): void
    {
        // Two nested remote prefixes; the deeper (more specific) one has the
        // shorter local prefix. It must still win — specificity is ranked by
        // remote-prefix length, not local-prefix length.
        $filesystem = $this->filesystemWithRules(array(
            '/srv/wp-content' => $this->root . '/archive-of-everything',
            '/srv/wp-content/plugins' => $this->root . '/p',
        ));
        $local_absolute_path = $filesystem
            ->map_remote_absolute_path_to_local_absolute_path(
                '/srv/wp-content/plugins/woo/woo.php'
            );
        $this->assertSame($this->root . '/p/woo/woo.php', $local_absolute_path);
    }

    public function testLocalAbsolutePrefixPlacesFilesAtItsRoot(): void
    {
        // A local absolute prefix that is the filesystem root: files land directly at the root,
        // no double slash.
        $filesystem = $this->filesystemWithRules(array(
            '/var/www/html/wp-content' => $this->root,
        ));
        $local_absolute_path = $filesystem
            ->map_remote_absolute_path_to_local_absolute_path(
                '/var/www/html/wp-content/plugins/woo/woo.php'
            );
        $this->assertSame($this->root . '/plugins/woo/woo.php', $local_absolute_path);
    }

    public function testOutOfScopePathFallsBackToNestedIdentity(): void
    {
        $filesystem = $this->filesystemWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local_absolute_path = $filesystem
            ->map_remote_absolute_path_to_local_absolute_path(
                '/var/www/html/wp-admin/index.php'
            );
        $this->assertSame($this->root . '/var/www/html/wp-admin/index.php', $local_absolute_path);
    }

    public function testNoRulesIsLegacyMapping(): void
    {
        $filesystem = $this->filesystemWithRules(array());
        $local_absolute_path = $filesystem
            ->map_remote_absolute_path_to_local_absolute_path(
                '/var/www/html/wp-content/x.txt'
            );
        $this->assertSame($this->root . '/var/www/html/wp-content/x.txt', $local_absolute_path);
    }

    /**
     * @dataProvider provideMappedCleanupErrorTypes
     */
    public function testErrorPartDecodesRemoteAbsolutePathBeforeMappedCleanup(
        string $error_type,
        bool $tracks_volatile_file
    ): void {
        $remote_absolute_path = '/srv/site/wp-content/partial.bin';
        $local_mapping_root = $this->root . '/mapped-content';
        mkdir($local_mapping_root);
        $local_absolute_path = $local_mapping_root . '/partial.bin';
        file_put_contents($local_absolute_path, 'partial');

        $resolved_path_mappings = array(
            '/srv/site/wp-content' => $local_mapping_root,
        );
        $client = $this->newClientWithResolvedPathMappings($resolved_path_mappings);
        $this->setPrivateProperty(
            $client,
            'pulled_filesystem',
            $this->filesystemWithRules($resolved_path_mappings)
        );
        $progress_stream = fopen('php://memory', 'w+');
        $this->assertIsResource($progress_stream);
        $this->setPrivateProperty($client, 'progress_fd', $progress_stream);

        $context = new \Reprint\Importer\StreamingContext();
        $context->file_handle = fopen($local_absolute_path, 'ab');
        $this->assertIsResource($context->file_handle);
        $context->file_path = $local_absolute_path;
        $context->file_ctime = 1234567890;
        $context->file_bytes_written = 7;

        $this->invokePrivateMethod(
            $client,
            'handle_error_part',
            array(
                array(
                    'body' => json_encode(array(
                        'error_type' => $error_type,
                        'path' => base64_encode($remote_absolute_path),
                        'message' => 'Remote file error',
                    )),
                ),
                'files',
                $context,
            )
        );

        $this->assertFileDoesNotExist($local_absolute_path);
        $this->assertNull($context->file_handle);
        $this->assertNull($context->file_path);
        $this->assertNull($context->file_ctime);
        $this->assertSame(0, $context->file_bytes_written);

        $client_reflection = new \ReflectionClass($client);
        $pull_index_journal = $client_reflection
            ->getProperty('pull_index_journal')
            ->getValue($client);
        $pull_index_journal->flush();
        $pull_index_wal_path = $this->stateDir . '/remotes/'
            . md5('https://src.example/export.php') . '/pull/index.wal';
        $pull_index_wal_record = json_decode(
            trim(file_get_contents($pull_index_wal_path)),
            true
        );
        $this->assertSame(
            base64_encode($remote_absolute_path),
            $pull_index_wal_record['remote_absolute_path_b64']
        );

        $volatile_files_path = $this->stateDir . '/remotes/'
            . md5('https://src.example/export.php') . '/pull/volatile-files.json';
        if ($tracks_volatile_file) {
            $volatile_files = json_decode(file_get_contents($volatile_files_path), true);
            $this->assertSame(1, $volatile_files[base64_encode($remote_absolute_path)]);
        } else {
            $this->assertFileDoesNotExist($volatile_files_path);
        }

        $audit_log = file_get_contents($this->stateDir . '/audit.log');
        $this->assertStringContainsString(
            "type={$error_type} | path={$remote_absolute_path}",
            $audit_log
        );

        rewind($progress_stream);
        $progress_record = json_decode(trim(stream_get_contents($progress_stream)), true);
        $this->assertSame($error_type, $progress_record['error_type']);
        $this->assertSame(base64_encode($remote_absolute_path), $progress_record['path']);
        fclose($progress_stream);
    }

    public static function provideMappedCleanupErrorTypes(): array
    {
        return array(
            'changed file is tracked as volatile' => array('file_changed', true),
            'failed seek is not tracked as volatile' => array('file_seek', false),
        );
    }

    public function testErrorPartRejectsInvalidRemoteAbsolutePathBeforeDurableState(): void
    {
        $client = $this->newClientWithResolvedPathMappings(array());
        $this->setPrivateProperty(
            $client,
            'pulled_filesystem',
            $this->filesystemWithRules(array())
        );

        try {
            $this->invokePrivateMethod(
                $client,
                'handle_error_part',
                array(
                    array(
                        'body' => json_encode(array(
                            'error_type' => 'file_changed',
                            'path' => base64_encode('relative/path'),
                            'message' => 'File changed during stream',
                        )),
                    ),
                    'files',
                    new \Reprint\Importer\StreamingContext(),
                )
            );
            $this->fail('The invalid remote absolute path was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'remote absolute path must be an absolute path',
                $exception->getMessage()
            );
        }

        $pull_state_directory = $this->stateDir . '/remotes/'
            . md5('https://src.example/export.php') . '/pull';
        $this->assertFileDoesNotExist($pull_state_directory . '/index.wal');
        $this->assertFileDoesNotExist($pull_state_directory . '/volatile-files.json');
    }

    /**
     * The path-prefix helper underpinning rule matching. A trailing slash on
     * either argument is path-equivalent and must be ignored.
     *
     * @dataProvider providePathRemainderCases
     */
    public function testPathRemainderUnder(?string $expected, string $path, string $prefix): void
    {
        $this->assertSame($expected, path_remainder_under($path, $prefix));
    }

    public static function providePathRemainderCases(): array
    {
        return array(
            'exact match' => array('', '/a/b', '/a/b'),
            'under prefix' => array('/c', '/a/b/c', '/a/b'),
            'not under (prefix is not a path boundary)' => array(null, '/a/bc', '/a/b'),
            'trailing slash on prefix' => array('', '/home/adam', '/home/adam/'),
            'trailing slash on path' => array('', '/home/adam/', '/home/adam'),
            'trailing slash on both' => array('', '/home/adam/', '/home/adam/'),
            'under, prefix has trailing slash' => array('/c', '/a/b/c', '/a/b/'),
        );
    }

    public function testAssertValidRelativePathAllowsDocumentRootDescendants(): void
    {
        foreach (array('index.php', 'wp-content/plugins/example.php', 'leading space/file') as $path) {
            assert_valid_relative_path($path, 'Document-root-relative path');
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider provideInvalidRelativePathCases
     */
    public function testAssertValidRelativePathRejectsReservedForms(string $path, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        assert_valid_relative_path($path, 'Document-root-relative path');
    }

    public static function provideInvalidRelativePathCases(): array
    {
        return array(
            'empty path' => array('', 'Document-root-relative path must not be empty.'),
            'absolute path' => array('/index.php', 'Document-root-relative path must not be absolute: L2luZGV4LnBocA==.'),
            'NUL byte' => array("nul\0byte", 'Document-root-relative path must not contain a NUL byte: bnVsAGJ5dGU=.'),
            'backslash' => array('windows\\path', 'Document-root-relative path must not contain a backslash: d2luZG93c1xwYXRo.'),
            'empty component' => array('wp-content//plugins', 'Document-root-relative path must not contain an empty component: d3AtY29udGVudC8vcGx1Z2lucw==.'),
            'dot component' => array('./index.php', 'Document-root-relative path must not contain a dot component: Li9pbmRleC5waHA=.'),
            'parent component' => array('wp-content/../index.php', 'Document-root-relative path must not contain a parent component: d3AtY29udGVudC8uLi9pbmRleC5waHA=.'),
        );
    }

    /**
     * @dataProvider provideTrailingSlashPathCases
     */
    public function testTrimRightSlash(string $expected, string $path): void
    {
        $this->assertSame($expected, trim_right_slash($path));
    }

    public static function provideTrailingSlashPathCases(): array
    {
        return array(
            'path without trailing slashes' => array('/srv/site', '/srv/site'),
            'path with trailing slashes' => array('/srv/site', '/srv/site///'),
            'filesystem root' => array('/', '/'),
            'empty input becomes filesystem root' => array('/', ''),
        );
    }

    /**
     * @dataProvider provideRelativePathCases
     */
    public function testRelativePathUnder(?string $expected, string $path, string $root): void
    {
        $this->assertSame($expected, relative_path_under($path, $root));
    }

    public static function provideRelativePathCases(): array
    {
        return array(
            'empty relative root itself' => array('', '', ''),
            'empty relative root child' => array('child/path', 'child/path', ''),
            'empty relative root child with trailing slash' => array('child/path', 'child/path/', ''),
            'absolute path outside empty relative root' => array(null, '/child/path', ''),
            'relative path outside filesystem root' => array(null, 'child/path', '/'),
            'filesystem root itself' => array('', '/', '/'),
            'filesystem root child' => array('child', '/child', '/'),
            'exact non-root match' => array('', '/a', '/a'),
            'non-root descendant' => array('b', '/a/b', '/a'),
            'sibling prefix' => array(null, '/ab', '/a'),
            'trailing slashes' => array('b', '/a/b/', '/a/'),
        );
    }

    /**
     * @dataProvider providePathSameAsOrDescendantOfCases
     */
    public function testPathIsSameAsOrDescendantOf(bool $expected, string $path, string $ancestor): void
    {
        $this->assertSame($expected, path_is_same_as_or_descendant_of($path, $ancestor));
    }

    public static function providePathSameAsOrDescendantOfCases(): array
    {
        return array(
            'filesystem root itself' => array(true, '/', '/'),
            'filesystem root child' => array(true, '/child', '/'),
            'relative path is outside filesystem root' => array(false, 'child', '/'),
            'exact non-root match' => array(true, '/a', '/a'),
            'non-root descendant' => array(true, '/a/b', '/a'),
            'sibling prefix' => array(false, '/ab', '/a'),
        );
    }

    public function testPathIsSameAsOrDescendantOfMatchesAnyPathAndAncestor(): void
    {
        $this->assertTrue(path_is_same_as_or_descendant_of('/a/b', ['/elsewhere', '/a']));
        $this->assertTrue(path_is_same_as_or_descendant_of(['/elsewhere', '/a/b'], '/a'));
        $this->assertTrue(path_is_same_as_or_descendant_of(
            ['/elsewhere', '/a/b'],
            ['/not-this-one', '/a']
        ));
        $this->assertFalse(path_is_same_as_or_descendant_of(
            ['/elsewhere', '/other'],
            ['/not-this-one', '/a']
        ));
    }

    /**
     * @dataProvider providePathDescendantOfCases
     */
    public function testPathIsDescendantOf(bool $expected, string $path, string $ancestor): void
    {
        $this->assertSame($expected, path_is_descendant_of($path, $ancestor));
    }

    public static function providePathDescendantOfCases(): array
    {
        return array(
            'filesystem root itself' => array(false, '/', '/'),
            'filesystem root child' => array(true, '/child', '/'),
            'relative path is outside filesystem root' => array(false, 'child', '/'),
            'exact non-root match' => array(false, '/a', '/a'),
            'non-root descendant' => array(true, '/a/b', '/a'),
            'sibling prefix' => array(false, '/ab', '/a'),
            'relative logical descendant' => array(true, 'a/b', 'a'),
        );
    }

    public function testPathIsDescendantOfMatchesAnyPathAndAncestor(): void
    {
        $this->assertTrue(path_is_descendant_of('/a/b', ['/elsewhere', '/a']));
        $this->assertTrue(path_is_descendant_of(['/elsewhere', '/a/b'], '/a'));
        $this->assertTrue(path_is_descendant_of(
            ['/elsewhere', '/a/b'],
            ['/not-this-one', '/a']
        ));
        $this->assertFalse(path_is_descendant_of(
            ['/elsewhere', '/a'],
            ['/not-this-one', '/a']
        ));
    }

    public function testRealpathWithMissingTail(): void
    {
        $existing_directory = $this->tempDir . '/existing';
        mkdir($existing_directory);
        $canonical_existing_directory = realpath($existing_directory);
        $this->assertIsString($canonical_existing_directory);

        $this->assertSame(
            $canonical_existing_directory,
            realpath_with_missing_tail($existing_directory)
        );
        $this->assertSame(
            $canonical_existing_directory . '/missing',
            realpath_with_missing_tail($existing_directory . '/missing')
        );
        $this->assertSame(
            $canonical_existing_directory . '/missing/child',
            realpath_with_missing_tail($existing_directory . '/missing/child')
        );
        $this->assertSame('/', realpath_with_missing_tail('/'));

        $symlink = $this->tempDir . '/existing-link';
        symlink($existing_directory, $symlink);
        $this->assertSame(
            $canonical_existing_directory . '/missing-through-link',
            realpath_with_missing_tail($symlink . '/missing-through-link')
        );

        $broken_symlink = $this->tempDir . '/broken-link';
        symlink($this->tempDir . '/missing-target', $broken_symlink);
        $this->assertSame(
            $broken_symlink . '/child',
            realpath_with_missing_tail($broken_symlink . '/child')
        );
    }

    public function testNextRemoteIndexMatchesFilesystemRootAndPathBoundaries(): void
    {
        $client = $this->newClientWithResolvedPathMappings(array());
        $next_remote_index_file = $this->tempDir . '/remote-index.next.jsonl';
        file_put_contents(
            $next_remote_index_file,
            json_encode([
                'path' => base64_encode('/srv/site/child.txt'),
                'ctime' => 0,
                'size' => 1,
                'type' => 'file',
            ]) . "\n"
        );
        $this->setPrivateProperty(
            $client,
            'next_remote_index_file',
            $next_remote_index_file
        );

        $this->assertTrue($this->invokePrivateMethod(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/')
        ));
        $this->assertTrue($this->invokePrivateMethod(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/srv/site')
        ));
        $this->assertFalse($this->invokePrivateMethod(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/srv/site-old')
        ));
    }
}
