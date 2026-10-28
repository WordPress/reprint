<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class PullSymlinkScopeTest extends TestCase
{
    private string $root;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('These fixtures create Unix symlink targets.');
        }
        $this->root = sys_get_temp_dir() . '/pull-link-scope-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files', 0700, true);
        mkdir($this->root . '/outside', 0700);
        $this->root = realpath($this->root);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/pull-symlink-scope-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes, $this->root);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->root)) {
            $this->remove_tree($this->root);
        }
    }

    /**
     * Existing links must be resolved before the following parent segments.
     * @dataProvider target_tails
     * @param string $tail Existing directory or a missing target below it.
     * @param string $prefix Missing component followed by a parent segment.
     */
    public function testSourceLinkChainKeepsLaterFileBytesInsideTheRoot(string $tail, string $prefix): void
    {
        $chain = implode('/', array_fill(0, 40, 'nested'));
        $parents = str_repeat('../', 41);
        $this->receive([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/' . $chain . '/up'),
                'X-Symlink-Target' => base64_encode(rtrim($parents, '/'))]],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/out'),
                'X-Symlink-Target' => base64_encode($prefix . $chain . '/up/' . $parents . ltrim($this->root . '/outside' . $tail, '/'))]],
            ['headers' => ['X-Chunk-Type' => 'directory', 'X-Directory-Path' => base64_encode('/a/missing')]],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/a/out/new.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'], 'body' => 'new'],
        ]);
        $this->assertFileDoesNotExist($this->root . '/outside' . $tail . '/new.txt');
        $this->assertFalse(is_link($this->root . '/files/a/out'));
        $this->assertSame('new', file_get_contents($this->root . '/files/a/out/new.txt'));
    }

    /** @return array<string,list<string>> Target tail and prefix for each case. */
    public static function target_tails(): array
    {
        return ['existing' => ['', ''], 'missing tail' => ['/not-created', ''],
            'missing component before parent' => ['', 'missing/../']];
    }

    /** A dangling chain inside the root remains usable after its file arrives. */
    public function testDanglingLinkChainInsideTheRootRemainsUsable(): void
    {
        $this->receive([
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/middle'),
                'X-Symlink-Target' => base64_encode('../future')]],
            ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/alias'),
                'X-Symlink-Target' => base64_encode('middle/leaf')]],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/future/leaf'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'], 'body' => 'new'],
        ]);
        $this->assertTrue(is_link($this->root . '/files/a/alias'));
        $this->assertSame('new', file_get_contents($this->root . '/files/a/alias'));
    }

    /** A user can move a partial download to a shared directory between requests. */
    public function testResumeChecksTheCurrentFileBeforeTruncatingOrAppending(): void
    {
        $client = $this->receive([['headers' => ['X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode('/partial/item'), 'X-First-Chunk' => '1',
            'X-Last-Chunk' => '0', 'X-File-Size' => '9'], 'body' => 'new']]);
        rename($this->root . '/files/partial', $this->root . '/outside/partial');
        symlink($this->root . '/outside/partial', $this->root . '/files/partial');
        file_put_contents($this->root . '/outside/partial/item', 'new extended');
        try {
            ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
            $this->fail('Resume must refuse the moved current file.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside filesystem root', $error->getMessage());
        }
        $this->assertSame('new extended', file_get_contents($this->root . '/outside/partial/item'));
    }

    /**
     * Later source links can redirect a retained pathname before its timestamp is set.
     *
     * @dataProvider retained_file_paths
     * @param bool $replace_parent Redirect the parent rather than the file itself.
     * @param bool $start_next_file Close the partial file by starting another file.
     */
    public function testLaterSourceLinksCannotRedirectARetainedFileTimestamp(bool $replace_parent, bool $start_next_file): void
    {
        $retained_path = $replace_parent ? '/a/out/new.txt' : '/old.txt';
        $replacement_path = $replace_parent ? '/a/out' : '/old.txt';
        $replacement_target = $replace_parent ? 'future/../outside' : 'a/future/../outside/new.txt';
        $failure = null;
        try {
            $this->receive([
                ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode($retained_path),
                    'X-First-Chunk' => '1', 'X-Last-Chunk' => '0', 'X-File-Size' => '8',
                    'X-File-Ctime' => '1234567890'], 'body' => 'first'],
                ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode($replacement_path),
                    'X-Symlink-Target' => base64_encode($replacement_target)]],
                ['headers' => ['X-Chunk-Type' => 'symlink', 'X-Symlink-Path' => base64_encode('/a/future'),
                    'X-Symlink-Target' => base64_encode('..')]],
                // The source can name a different in-root path on the closing
                // part too. Only the retained pathname check protects touch().
                ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/next.txt'),
                    'X-First-Chunk' => $start_next_file ? '1' : '0', 'X-Last-Chunk' => '1',
                    'X-File-Size' => $start_next_file ? '4' : '8'], 'body' => $start_next_file ? 'next' : 'end'],
            ]);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        $this->assertFileDoesNotExist($this->root . '/outside/new.txt');
        $this->assertNotNull($failure, 'A redirected retained pathname must stop the request.');
        $this->assertStringContainsString('outside filesystem root', $failure->getMessage());
        $this->assertTrue(is_link($this->root . '/files/a/future'), 'The source installed the link which redirects the earlier path.');
    }

    /** @return array<string,array{bool,bool}> Parent replacement and file-closing mode. */
    public static function retained_file_paths(): array
    {
        return [
            'parent redirected before next file' => [true, true],
            'parent redirected before closing part' => [true, false],
            'leaf redirected before next file' => [false, true],
            'leaf redirected before closing part' => [false, false],
        ];
    }

    /**
     * Preserve-local skips shared paths and continues with the next ordinary file.
     *
     * @dataProvider shared_parent_paths
     * @param string $missing_tail Missing components beneath the shared directory.
     */
    public function testPreserveLocalSkipsAnExternalParentAndKeepsDownloading(string $missing_tail): void
    {
        symlink($this->root . '/outside', $this->root . '/files/shared');
        file_put_contents($this->root . '/outside/new.txt', 'keep existing');
        // More than one cURL body callback must stay skipped without adding file bytes.
        $skipped_body = str_repeat('skip', 32768);
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'file',
                'X-File-Path' => base64_encode('/shared' . $missing_tail . '/new.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1',
                'X-File-Size' => (string) strlen($skipped_body), 'X-File-Ctime' => '1234567890'], 'body' => $skipped_body],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/safe.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4',
                'X-File-Ctime' => '1234567891'], 'body' => 'safe'],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        // run() selects this same mode from --on-fs-root-nonempty=preserve-local.
        ( new \ReflectionProperty($client, 'fs_root_nonempty_behavior') )->setValue($client, 'preserve-local');
        $complete = ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        $this->assertSame('keep existing', file_get_contents($this->root . '/outside/new.txt'));
        $this->assertDirectoryDoesNotExist($this->root . '/outside/missing');
        $this->assertSame('safe', file_get_contents($this->root . '/files/safe.txt'));
        $this->assertTrue($complete);
        $reporter = ( new \ReflectionProperty($client, 'progress_reporter') )->getValue($client);
        $this->assertSame(2, $reporter->get_batch_files_done());
        $this->assertSame(4, $client->get_state()->fetch->file_bytes_in_batch);
        $this->assertStringContainsString('PRESERVE-LOCAL', file_get_contents($this->root . '/state/audit.log'));
    }

    /** @return array<string,list<string>> Existing or missing shared parent components. */
    public static function shared_parent_paths(): array
    {
        return ['existing parent' => [''], 'missing child directory' => ['/missing']];
    }

    /**
     * Accepting an existing root directory must not allow another part to replace it.
     *
     * @dataProvider root_replacement_types
     * @param string $type File or symlink part naming the root itself.
     */
    public function testFileAndSymlinkPartsCannotReplaceTheFilesystemRoot(string $type): void
    {
        $headers = ['X-Chunk-Type' => $type];
        if ($type === 'file') {
            $headers += ['X-File-Path' => base64_encode('/'), 'X-First-Chunk' => '1',
                'X-Last-Chunk' => '1', 'X-File-Size' => '3'];
        } else {
            $headers += ['X-Symlink-Path' => base64_encode('/'), 'X-Symlink-Target' => base64_encode('safe')];
        }
        try {
            $this->receive([
                ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/keep.txt'),
                    'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4',
                    'X-File-Ctime' => '1234567890'], 'body' => 'keep'],
                ['headers' => $headers, 'body' => $type === 'file' ? 'bad' : ''],
            ]);
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside filesystem root', $error->getMessage());
        }
        $this->assertDirectoryExists($this->root . '/files');
        $this->assertFalse(is_link($this->root . '/files'));
        $this->assertSame('keep', file_get_contents($this->root . '/files/keep.txt'));
    }

    /** @return array<string,list<string>> Parts which must not replace the root directory. */
    public static function root_replacement_types(): array
    {
        return ['file' => ['file'], 'symlink' => ['symlink']];
    }

    /** @dataProvider received_types */
    public function testExistingParentLinkIsCheckedBeforeReplacingAPath(string $type): void
    {
        // A user may already have this link when starting an overwrite pull.
        symlink($this->root . '/outside', $this->root . '/files/alias');
        $path = $this->root . '/outside/item';
        if ($type === 'file') {
            mkdir($path);
            file_put_contents($path . '/keep.txt', 'keep');
        } else {
            file_put_contents($path, 'keep');
        }
        $headers = ['X-Chunk-Type' => $type, 'X-' . ucfirst($type) . '-Path' => base64_encode('/alias/item')];
        if ($type === 'file') {
            $headers += ['X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '3'];
        } elseif ($type === 'symlink') {
            $headers['X-Symlink-Target'] = base64_encode('../safe');
        }
        try {
            $this->receive([['headers' => $headers, 'body' => $type === 'file' ? 'new' : '']]);
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside filesystem root', $error->getMessage());
        }
        $this->assertSame('keep', file_get_contents($path . ( $type === 'file' ? '/keep.txt' : '' )));
        $this->assertFalse(is_link($path));
    }

    /** @return array<string,list<string>> Received part types. */
    public static function received_types(): array
    {
        return ['file' => ['file'], 'directory' => ['directory'], 'symlink' => ['symlink']];
    }

    public function testIntermediateIndexLinksCheckTheirParentAsWellAsTheirTarget(): void
    {
        // A local directory alias may already point to a shared sibling tree.
        symlink($this->root . '/outside', $this->root . '/files/alias');
        mkdir($this->root . '/files/files');
        file_put_contents($this->root . '/files/files/value', 'selected target');
        $client = $this->receive([]);
        $client->get_state()->set_preflight_record([
            'data' => ['wp_detect' => ['roots' => [['path' => '/']]]],
        ]);
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'index_batch'], 'body' => json_encode([
                ['path' => base64_encode('/alias/new-link'), 'type' => 'link',
                    'target' => base64_encode('../files/value'), 'intermediate' => true],
                ['path' => base64_encode('/files/value'), 'type' => 'file'],
            ])],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        ( new \ReflectionMethod($client, 'fetch_next_remote_index') )->invoke($client);
        ( new \ReflectionMethod($client, 'recreate_intermediate_symlinks') )->invoke($client);

        $this->assertFalse(is_link($this->root . '/outside/new-link'));
        $this->assertSame('selected target', file_get_contents($this->root . '/files/files/value'));
    }

    /**
     * Existing links may come from the operator or an earlier pull. Only the
     * overwrite mode may replace them or create links through a local alias.
     *
     * @dataProvider intermediate_local_paths
     */
    public function testIntermediateLinksUseTheSelectedLocalPathMode(string $type, bool $preserve): void
    {
        $files = $this->root . '/files';
        file_put_contents($files . '/target', 'selected target');
        file_put_contents($files . '/old-target', 'local target');
        $remote_path = '/link';
        $local_path = $files . '/link';
        if ($type === 'parent alias') {
            mkdir($files . '/shared');
            symlink('shared', $files . '/alias');
            $remote_path = '/alias/link';
            $local_path = $files . '/shared/link';
        } else {
            symlink($type === 'dangling link' ? 'missing-target' : 'old-target', $local_path);
        }
        $client = $this->receive([]);
        if ($preserve) {
            ( new \ReflectionProperty($client, 'fs_root_nonempty_behavior') )->setValue($client, 'preserve-local');
        }
        $client->get_state()->set_preflight_record([
            'data' => ['wp_detect' => ['roots' => [['path' => '/']]]]],
        );
        file_put_contents($this->root . '/parts.json', json_encode([
            ['headers' => ['X-Chunk-Type' => 'index_batch'], 'body' => json_encode([
                ['path' => base64_encode($remote_path), 'type' => 'link',
                    'target' => base64_encode('/target'), 'intermediate' => true],
                ['path' => base64_encode('/new-link'), 'type' => 'link',
                    'target' => base64_encode('/target'), 'intermediate' => true],
                ['path' => base64_encode('/target'), 'type' => 'file'],
            ])],
            ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']],
        ]));
        ( new \ReflectionMethod($client, 'fetch_next_remote_index') )->invoke($client);
        ( new \ReflectionMethod($client, 'recreate_intermediate_symlinks') )->invoke($client);

        $this->assertTrue(is_link($files . '/new-link'), 'A missing ordinary link must still be created.');
        $this->assertSame('selected target', file_get_contents($files . '/new-link'));
        $this->assertSame('local target', file_get_contents($files . '/old-target'));
        if ($preserve && $type === 'parent alias') {
            $this->assertFalse(is_link($local_path), 'Preserve-local must not create links through a local alias.');
        } elseif ($preserve) {
            $this->assertSame($type === 'dangling link' ? 'missing-target' : 'old-target', readlink($local_path));
        } else {
            $this->assertSame('selected target', file_get_contents($local_path));
        }
        if ($preserve) {
            $this->assertStringContainsString('PRESERVE-LOCAL', file_get_contents($this->root . '/state/audit.log'));
        }
    }

    /** @return array<string, array{string, bool}> Local path type and preserve-local setting. */
    public static function intermediate_local_paths(): array
    {
        $cases = [];
        foreach (['existing link', 'dangling link', 'parent alias'] as $type) {
            $cases[$type . ' preserve'] = [$type, true];
            $cases[$type . ' overwrite'] = [$type, false];
        }
        return $cases;
    }

    public function testRemoteDeletionDoesNotFollowARepointedLocalParent(): void
    {
        $client = $this->receive([['headers' => ['X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode('/parent/child/keep.txt'),
            'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4'], 'body' => 'keep']]);
        // The user moves a pulled directory and leaves an alias between pulls.
        rename($this->root . '/files/parent', $this->root . '/outside/parent');
        symlink($this->root . '/outside/parent', $this->root . '/files/parent');

        $removed = ( new \ReflectionMethod($client, 'remove_remote_path_locally') )->invoke($client, '/parent/child');

        $this->assertNull($removed);
        $this->assertSame('keep', file_get_contents($this->root . '/outside/parent/child/keep.txt'));
    }

    /** @dataProvider deletion_path_suffixes */
    public function testRemoteDeletionCanUnlinkAnExternalLeafWithoutFollowingIt(string $suffix): void
    {
        file_put_contents($this->root . '/outside/keep.txt', 'keep');
        symlink($this->root . '/outside', $this->root . '/files/alias');
        $client = $this->receive([]);
        $removed = ( new \ReflectionMethod($client, 'remove_remote_path_locally') )->invoke($client, '/alias' . $suffix);

        $this->assertFileExists($this->root . '/outside/keep.txt');
        $this->assertSame('keep', file_get_contents($this->root . '/outside/keep.txt'));
        $this->assertSame($this->root . '/files/alias', $removed);
        $this->assertFalse(is_link($removed));
    }

    /** @dataProvider deletion_path_suffixes */
    public function testLocalRemovalInspectsTheLinkWithoutTrailingSlashes(string $suffix): void
    {
        file_put_contents($this->root . '/outside/keep.txt', 'keep');
        symlink($this->root . '/outside', $this->root . '/files/alias');
        $client = $this->receive([]);
        $removed = ( new \ReflectionMethod($client, 'remove_local_absolute_path_without_following_symlinks') )
            ->invoke($client, $this->root . '/files/alias' . $suffix);

        $this->assertTrue($removed);
        $this->assertFileExists($this->root . '/outside/keep.txt');
        $this->assertSame('keep', file_get_contents($this->root . '/outside/keep.txt'));
        $this->assertFalse(is_link($this->root . '/files/alias'));
    }

    /** @return array<string,list<string>> Ordinary and trailing-slash deletion paths. */
    public static function deletion_path_suffixes(): array
    {
        return ['plain' => [''], 'trailing slash' => ['/'], 'repeated slashes' => ['///']];
    }

    /**
     * A source entry may spell a pre-existing directory link with trailing slashes.
     *
     * @dataProvider trailing_slash_replacements
     * @param string $type Received replacement type.
     * @param bool $external Whether the existing link points outside the local root.
     * @param string $suffix Trailing separators supplied by the source.
     */
    public function testTrailingSlashesReplaceTheLinkRatherThanItsTarget(string $type, bool $external, string $suffix): void
    {
        $target = $this->root . ( $external ? '/outside' : '/files/target' );
        mkdir($target . '/nested', 0700, true);
        file_put_contents($target . '/keep.txt', 'keep');
        file_put_contents($target . '/nested/keep.txt', 'nested keep');
        symlink($target, $this->root . '/files/alias');
        file_put_contents($this->root . '/files/replacement', 'new target');
        $headers = ['X-Chunk-Type' => $type, 'X-' . ucfirst($type) . '-Path' => base64_encode('/alias' . $suffix)];
        $body = '';
        if ($type === 'file') {
            $body = str_repeat('replacement', 10000);
            $headers += ['X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => (string) strlen($body)];
        } elseif ($type === 'symlink') {
            $headers['X-Symlink-Target'] = base64_encode('replacement');
        }
        $failure = null;
        try {
            $this->receive([['headers' => $headers, 'body' => $body]]);
        } catch (\Throwable $error) {
            $failure = $error;
        }

        $this->assertFileExists($target . '/keep.txt', 'Replacing a link must not remove its target contents.');
        $this->assertSame('keep', file_get_contents($target . '/keep.txt'));
        $this->assertSame('nested keep', file_get_contents($target . '/nested/keep.txt'));
        $this->assertNull($failure, $failure ? $failure->getMessage() : '');
        $local_path = $this->root . '/files/alias';
        $this->assertSame($type === 'symlink', is_link($local_path));
        if ($type === 'directory') {
            $this->assertDirectoryExists($local_path);
            $this->assertFileDoesNotExist($local_path . '/keep.txt');
        } else {
            $this->assertSame($type === 'file' ? $body : 'new target', file_get_contents($local_path));
        }
    }

    /** @return array<string,array{string,bool,string}> Replacement type, target location, and path suffix. */
    public static function trailing_slash_replacements(): array
    {
        $cases = [];
        foreach (['file', 'directory', 'symlink'] as $type) {
            foreach ([false, true] as $external) {
                foreach (['/', '///'] as $suffix) {
                    $cases[$type . ( $external ? ' external ' : ' internal ' ) . $suffix] = [$type, $external, $suffix];
                }
            }
        }
        return $cases;
    }

    /**
     * Preserve-local must protect links even when the source sends an unrequested file.
     *
     * @dataProvider preserved_link_paths
     * @param string $type Existing local path type.
     * @param string $suffix Source spelling of the file path.
     */
    public function testPreserveLocalSkipsFilePartsForLinksAndDirectories(string $type, string $suffix): void
    {
        $target = $this->root . ( $type === 'external link' ? '/outside' : '/files/target' );
        mkdir($target . '/nested', 0700, true);
        file_put_contents($target . '/nested/keep.txt', 'keep');
        $remote_path = '/alias' . $suffix;
        $local_path = $this->root . '/files/alias';
        if ($type === 'directory') {
            rename($target, $local_path);
            $target = $local_path;
        } elseif ($type === 'parent link') {
            symlink('target', $local_path);
            $remote_path = '/alias/new.txt' . $suffix;
        } else {
            symlink($type === 'dangling link' ? 'missing' : $target, $local_path);
        }
        $body = str_repeat('ignored', 20000);
        $client = $this->receive([
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode($remote_path),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '0', 'X-File-Size' => (string) ( strlen($body) + 4 )], 'body' => $body],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode($remote_path),
                'X-First-Chunk' => '0', 'X-Last-Chunk' => '1', 'X-File-Size' => (string) ( strlen($body) + 4 )], 'body' => 'tail'],
            ['headers' => ['X-Chunk-Type' => 'file', 'X-File-Path' => base64_encode('/safe.txt'),
                'X-First-Chunk' => '1', 'X-Last-Chunk' => '1', 'X-File-Size' => '4', 'X-File-Ctime' => '1234567890'], 'body' => 'safe'],
        ], true);

        $this->assertSame($type !== 'directory', is_link($local_path));
        $this->assertSame('keep', file_get_contents($target . '/nested/keep.txt'));
        $this->assertFileDoesNotExist($target . '/new.txt');
        $this->assertSame('safe', file_get_contents($this->root . '/files/safe.txt'));
        $this->assertSame(4, $client->get_state()->fetch->file_bytes_in_batch);
        $this->assertStringContainsString('PRESERVE-LOCAL', file_get_contents($this->root . '/state/audit.log'));
    }

    /** @return array<string,array{string,string}> Existing path type and trailing separators. */
    public static function preserved_link_paths(): array
    {
        $cases = [];
        foreach (['external link', 'internal link', 'dangling link', 'directory', 'parent link'] as $type) {
            foreach (['', '/', '///'] as $suffix) {
                $cases[$type . ' ' . $suffix] = [$type, $suffix];
            }
        }
        return $cases;
    }

    /**
     * Run the production file-fetch dispatcher over a real HTTP response.
     *
     * @param array $parts {
     *     Source multipart parts.
     *     @type array $headers Protocol header strings for each part.
     *     @type string $body Optional part bytes.
     * }
     * @param bool $preserve Whether this pull keeps existing hosting paths.
     * @return \ImportClient Client retaining the saved file-fetch state.
     * @phpstan-param list<array{headers:array<string,string>,body?:string}> $parts
     */
    private function receive(array $parts, bool $preserve = false): \ImportClient
    {
        $parts[] = ['headers' => ['X-Chunk-Type' => 'completion', 'X-Status' => 'complete']];
        file_put_contents($this->root . '/parts.json', json_encode($parts));
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke($client, true, ['secret' => 'test-secret']);
        if ($preserve) {
            ( new \ReflectionProperty($client, 'fs_root_nonempty_behavior') )->setValue($client, 'preserve-local');
        }
        ( new \ReflectionMethod($client, 'fetch_file_batch') )->invoke($client, [], null);
        return $client;
    }

    /** Remove test paths without following links. */
    private function remove_tree(string $path): void
    {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->remove_tree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
