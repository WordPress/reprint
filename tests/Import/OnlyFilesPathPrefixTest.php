<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * File-prefix resolution and enumeration (pure, preflight-injected):
 *   - resolve_remote_paths(): :token: templates / absolute paths → remote absolute
 *     prefixes (sharing --remap's WordPress path token table), with expansion for plugins, mu-plugins, and uploads
 *     directories outside WP_CONTENT_DIR and covered-prefix collapse.
 *   - get_export_directories(): with --include, a *replace* of the export roots.
 * Orthogonal to --remap (--include file prefixes decide what gets pulled, not where it lands).
 */
class OnlyFilesPathPrefixTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-files-prefix-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $remoteReprintApiUrl = 'https://src.example/export.php';
        $this->pullStateDirectory =
            $this->stateDir
            . '/remotes/'
            . md5(rtrim($remoteReprintApiUrl, '?&'))
            . '/pull';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->pullStateDirectory, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
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
            (is_dir($path) && !is_link($path)) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function call($c, string $m, array $a = array())
    {
        return (new \ReflectionClass($c))->getMethod($m)->invoke($c, ...$a);
    }

    private function set($c, string $p, $v): void
    {
        (new \ReflectionClass($c))->getProperty($p)->setValue($c, $v);
    }

    private function client(array $preflightData): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $c->get_state()->set_preflight_record(array('data' => $preflightData));
        $this->set($c, 'audit_log_file', $this->tempDir . '/audit.log');
        return $c;
    }

    /** Preflight carrying only the wp paths_urls needed by the --include file-prefix helpers. */
    private function withPaths(array $pathsUrls): \ImportClient
    {
        return $this->client(array('database' => array('wp' => array('paths_urls' => $pathsUrls))));
    }

    private function pathSelectionFingerprint(
        array $included,
        array $excluded = array(),
        bool $followSymlinks = false,
        bool $includeCaches = false,
        ?string $extraDirectory = null
    ): string
    {
        sort($excluded, SORT_STRING);
        return hash('sha256', json_encode(array(
            'only_path_prefixes_b64' => array_map('base64_encode', $included),
            'excluded_path_prefixes_b64' => array_map('base64_encode', $excluded),
            'extra_directory_b64' => $extraDirectory === null
                ? null
                : base64_encode($extraDirectory),
            'follow_symlinks' => $followSymlinks,
            'include_caches' => $includeCaches,
        ), JSON_UNESCAPED_SLASHES));
    }

    private function writeFilesPullState(array $state): void
    {
        $defaults = array(
            'active_resumable_command' => array(
                'command_name' => 'files-pull',
                'completion_state' => 'in_progress',
                'current_stage' => 'diff',
                'remote_cursor' => null,
            ),
            'preflight' => array(
                'data' => array(
                    'database' => array(
                        'wp' => array(
                            'paths_urls' => array(
                                'content_dir' => '/var/www/html/wp-content',
                                'uploads' => array('basedir' => '/var/www/html/wp-content/uploads'),
                            ),
                        ),
                    ),
                    'wp_detect' => array(
                        'roots' => array(array('path' => '/var/www/html')),
                    ),
                ),
                'http_code' => 200,
            ),
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
            'filter' => 'none',
        );

        \write_current_pull_state(
            new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot),
            array_replace_recursive($defaults, $state)
        );
    }

    /** @param list<string> $remoteRoots */
    private function writeCompletableFilesPullState(
        array $state,
        array $remoteRoots
    ): void {
        $state['files_pull_ownership'] = array(
            'active_snapshot_id' => reprint_test_write_root_ownership_snapshot(
                $this->pullStateDirectory,
                $remoteRoots
            ),
        );
        $this->writeFilesPullState($state);
    }

    private function runFilesPull(array $fileSelectionOptions): \ImportClient
    {
        return $this->runCommand('files-pull', $fileSelectionOptions);
    }

    private function runCommand(string $command, array $options): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $output = fopen('php://temp', 'w');
        $clientReflection = new \ReflectionClass($c);
        $clientReflection->getProperty('progress_fd')->setValue($c, $output);
        $progress = $clientReflection->getProperty('progress')->getValue($c);
        (new \ReflectionClass($progress))->getProperty('progress_fd')->setValue($progress, $output);

        try {
            $c->run(array_merge(
                array('command' => $command),
                $options
            ));
        } finally {
            fclose($output);
        }
        return $c;
    }

    private function readState(): array
    {
        return json_decode(file_get_contents($this->pullStateDirectory . '/state.json'), true);
    }

    public function testResolvePullOnlyFilesPrefixAddsDirectoriesOutsideWpContent(): void
    {
        // Selecting :wp-content: with --include yields WP_CONTENT_DIR plus any plugins,
        // mu-plugins, or uploads directory outside it (uploads here); a nested
        // directory is already covered.
        $c = $this->withPaths(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/var/www/html/wp-content/plugins', // nested → not added
            'uploads' => array('basedir' => '/mnt/uploads'),     // outside WP_CONTENT_DIR → added
        ));
        $pull_only_files_with_path_prefixes = $this->call($c, 'resolve_remote_paths', array(array(':wp-content:'), 'include'));
        sort($pull_only_files_with_path_prefixes);
        $this->assertSame(array('/mnt/uploads', '/var/www/html/wp-content'), $pull_only_files_with_path_prefixes);
    }

    public function testResolvePullOnlyFilesPrefixCollapsesNestedPrefixes(): void
    {
        // :wp-content:/plugins is nested under :wp-content: → dropped, so the
        // exporter never walks the subtree twice.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $pull_only_files_with_path_prefixes = $this->call($c, 'resolve_remote_paths', array(array(':wp-content:', ':wp-content:/plugins'), 'include'));
        $this->assertSame(array('/var/www/html/wp-content'), $pull_only_files_with_path_prefixes);
    }

    public function testResolveMapsTokensToTheirRealRelocatedLocations(): void
    {
        // A token resolves to its real (possibly relocated) location: the moved
        // plugins dir via :wp-plugins:. A token narrower than wp-content does
        // not add sibling directories outside WP_CONTENT_DIR (no /external/uploads
        // pulled in).
        $c = $this->client(array('database' => array('wp' => array(
            'paths_urls' => array(
                'content_dir' => '/srv/wp-content',
                'plugins_dir' => '/custom/plugins',
                'abspath' => '/var/www/html',
                'uploads' => array('basedir' => '/external/uploads'),
            ),
        ))));
        $this->assertSame(
            array('/custom/plugins/woocommerce'),
            $this->call($c, 'resolve_remote_paths', array(array(':wp-plugins:/woocommerce'), 'include'))
        );
    }

    public function testResolvePullOnlyFilesPrefixAcceptsRawAbsolutePath(): void
    {
        // Like --remap, a raw absolute source is taken literally (no tokens).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->assertSame(
            array('/var/custom/data'),
            $this->call($c, 'resolve_remote_paths', array(array('/var/custom/data'), 'include'))
        );
    }

    public function testResolvePullOnlyFilesPrefixRejectsBlankSource(): void
    {
        // Strict input hygiene: a blank source (e.g. `--include ""`) is an error,
        // not silently ignored.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->call($c, 'resolve_remote_paths', array(array(':wp-content:', ''), 'include'));
    }

    public function testResolvePullOnlyFilesPrefixRejectsUnavailableToken(): void
    {
        // A token preflight didn't determine yields a clear, preflight-naming
        // error (shared with --remap's resolver).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preflight');
        $this->call($c, 'resolve_remote_paths', array(array(':abspath:/wp-admin'), 'include'));
    }

    public function testFilterModesRewriteToUploadPathSelections(): void
    {
        $c = $this->withPaths(array(
            'content_dir' => '/var/www/html/wp-content',
            'uploads' => array('basedir' => '/mnt/uploads'),
        ));

        $this->set($c, 'filter', 'essential-files');
        $this->call($c, 'prepare_files_pull_options', array(array(), false));
        $this->assertSame(array(), (new \ReflectionClass($c))->getProperty('pull_only_files_with_path_prefixes')->getValue($c));
        $this->assertSame(array('/mnt/uploads'), (new \ReflectionClass($c))->getProperty('pull_excluded_files_with_path_prefixes')->getValue($c));

        $this->set($c, 'filter', 'skipped-earlier');
        $this->call($c, 'prepare_files_pull_options', array(array(), false));
        $this->assertSame(array('/mnt/uploads'), (new \ReflectionClass($c))->getProperty('pull_only_files_with_path_prefixes')->getValue($c));
        $this->assertSame(array(), (new \ReflectionClass($c))->getProperty('pull_excluded_files_with_path_prefixes')->getValue($c));
    }

    public function testChangingOnlyPrefixesWhileResumingFilesPullIsRejected(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));

        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/plugins'));
        $original_fingerprint = $this->call($c, 'files_pull_path_selection_fingerprint');

        $c->get_state()->files_pull_path_selection_fingerprint = $original_fingerprint;
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/uploads'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --include or --exclude while resuming files-pull');
        $this->call($c, 'assert_files_pull_path_selection_unchanged_while_resuming', array(true));
    }

    public function testChangingExcludePrefixesWhileResumingFilesPullIsRejected(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));

        $this->set($c, 'pull_excluded_files_with_path_prefixes', array('/var/www/html/wp-content/uploads'));
        $c->get_state()->files_pull_path_selection_fingerprint =
            $this->call($c, 'files_pull_path_selection_fingerprint');
        $this->set($c, 'pull_excluded_files_with_path_prefixes', array('/var/www/html/wp-content/plugins'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --include or --exclude while resuming files-pull');
        $this->call($c, 'assert_files_pull_path_selection_unchanged_while_resuming', array(true));
    }

    public function testChangingOnlyPrefixesAfterCompletedFilesPullIsAllowed(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));

        $c->get_state()->files_pull_path_selection_fingerprint = 'different';
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/uploads'));

        $this->call($c, 'assert_files_pull_path_selection_unchanged_while_resuming', array(false));
        $this->addToAssertionCount(1);
    }


    public function testRunRejectsChangingOnlyPrefixesWhileFilesPullIsInProgress(): void
    {
        $this->writeFilesPullState(array(
            'files_pull_path_selection_fingerprint' => $this->pathSelectionFingerprint(array('/var/www/html/wp-content/plugins')),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --include or --exclude while resuming files-pull');
        $this->runFilesPull(array('include' => array(':wp-uploads:')));
    }

    public function testRunAllowsSameOnlyPrefixesWhileFilesPullIsInProgress(): void
    {
        file_put_contents($this->pullStateDirectory . '/remote-index.next.jsonl', '');
        $this->writeCompletableFilesPullState(array(
            'files_pull_path_selection_fingerprint' => $this->pathSelectionFingerprint(array('/var/www/html/wp-content/plugins')),
        ), array('/var/www/html/wp-content/plugins'));

        $this->runFilesPull(array('include' => array(':wp-content:/plugins')));

        $state = $this->readState();
        $this->assertSame('complete', $state['active_resumable_command']['completion_state'] ?? null);
        $this->assertSame(
            $this->pathSelectionFingerprint(array('/var/www/html/wp-content/plugins')),
            $state['files_pull_path_selection_fingerprint'] ?? null
        );
    }

    public function testRunRestoresIncludeCachesWhileFilesPullIsInProgress(): void
    {
        file_put_contents($this->pullStateDirectory . '/remote-index.next.jsonl', '');
        $this->writeCompletableFilesPullState(array(
            'include_caches' => true,
            'files_pull_path_selection_fingerprint' =>
                $this->pathSelectionFingerprint(array(), array(), false, true),
        ), array('/var/www/html'));

        $this->runFilesPull(array());

        $state = $this->readState();
        $this->assertTrue($state['include_caches']);
        $this->assertSame(
            'complete',
            $state['active_resumable_command']['completion_state'] ?? null
        );
    }

    public function testRunRejectsChangingIncludeCachesWithoutChangingState(): void
    {
        file_put_contents($this->pullStateDirectory . '/remote-index.next.jsonl', '');
        $this->writeFilesPullState(array(
            'include_caches' => false,
            'files_pull_path_selection_fingerprint' =>
                $this->pathSelectionFingerprint(array()),
        ));

        try {
            $this->runFilesPull(array('include_caches' => true));
            $this->fail('Expected an active files-pull to reject a cache-selection change.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --include-caches while resuming files-pull',
                $exception->getMessage()
            );
        }
        $this->assertFalse($this->readState()['include_caches']);
    }

    public function testOmittedIncludeCachesDoesNotReuseCompletedPullValue(): void
    {
        $this->writeFilesPullState(array(
            'active_resumable_command' => array(
                'completion_state' => 'complete',
                'current_stage' => null,
            ),
            'include_caches' => true,
        ));

        $client = $this->runFilesPull(array());

        $include_caches = ( new \ReflectionClass( $client ) )
            ->getProperty('include_caches')
            ->getValue($client);
        $this->assertFalse($include_caches);
        $this->assertTrue($this->readState()['include_caches']);
    }

    public function testAbortClearsIncludeCachesBeforeTheNextPull(): void
    {
        $this->writeFilesPullState(array('include_caches' => true));

        $this->runFilesPull(array('abort' => true));

        $this->assertFalse($this->readState()['include_caches']);
    }

    public function testRunRestoresByteSafeExtraDirectoryWhileFilesPullIsInProgress(): void
    {
        $extraDirectory = "/srv/extra-\x80";
        file_put_contents($this->pullStateDirectory . '/remote-index.next.jsonl', '');
        $this->writeCompletableFilesPullState(array(
            'extra_directory' => $extraDirectory,
            'files_pull_path_selection_fingerprint' =>
                $this->pathSelectionFingerprint(
                    array(),
                    array(),
                    false,
                    false,
                    $extraDirectory
                ),
        ), array('/var/www/html', $extraDirectory));

        $client = $this->runFilesPull(array());

        $savedState = $this->readState();
        $this->assertSame(
            'base64:' . base64_encode($extraDirectory),
            $savedState['extra_directory']
        );
        $this->assertSame(
            $extraDirectory,
            ( new \ReflectionClass( $client ) )
                ->getProperty('extra_directory')
                ->getValue($client)
        );
    }

    public function testRunRejectsChangingExtraDirectoryWithoutChangingState(): void
    {
        $extraDirectory = '/srv/original-extra';
        $this->writeFilesPullState(array(
            'extra_directory' => $extraDirectory,
            'files_pull_path_selection_fingerprint' =>
                $this->pathSelectionFingerprint(
                    array(),
                    array(),
                    false,
                    false,
                    $extraDirectory
                ),
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runFilesPull(array('extra_directory' => '/srv/different-extra'));
            $this->fail('Expected an active files-pull to reject an extra-directory change.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --extra-directory while resuming files-pull',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    public function testConflictingPipelineCommandLeavesStateBytesUnchanged(): void
    {
        $this->writeFilesPullState(array(
            'pull_pipeline' => array(
                'started_by_command' => 'pull-files',
                'stage_sequence' => array('preflight', 'files-pull'),
                'last_completed_stage' => null,
                'has_completed_once' => false,
            ),
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runCommand('pull', array(
                'follow_symlinks' => true,
                'include_caches' => true,
                'extra_directory' => '/srv/different-extra',
                'fs_root_nonempty_behavior' => 'error',
            ));
            $this->fail('Expected pull to reject the active pull-files pipeline.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Another command is already in progress: pull-files',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    /**
     * @dataProvider directFileIndexCommandProvider
     */
    public function testDirectFileIndexCommandRejectsHighLevelOwnerWithoutChangingState(
        string $command
    ): void {
        $this->writeFilesPullState(array(
            'pull_pipeline' => array(
                'started_by_command' => 'pull-files',
                'stage_sequence' => array('preflight', 'files-pull'),
                'last_completed_stage' => 'preflight',
                'has_completed_once' => false,
            ),
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runCommand($command, array(
                'follow_symlinks' => true,
                'include_caches' => true,
                'extra_directory' => '/srv/different-extra',
            ));
            $this->fail("Expected {$command} to reject the active pull-files pipeline.");
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Another command is already in progress: pull-files',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    /** @return array<string,array{string}> */
    public static function directFileIndexCommandProvider(): array
    {
        return array(
            'files-pull' => array('files-pull'),
            'files-index' => array('files-index'),
        );
    }

    public function testHighLevelPullRejectsActiveDirectFilesIndexWithoutChangingState(): void
    {
        $this->writeFilesPullState(array(
            'active_resumable_command' => array(
                'command_name' => 'files-index',
                'completion_state' => 'in_progress',
                'current_stage' => 'index',
                'remote_cursor' => null,
            ),
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runCommand('pull-files', array(
                'follow_symlinks' => true,
                'include_caches' => true,
                'extra_directory' => '/srv/different-extra',
            ));
            $this->fail('Expected pull-files to reject the active files-index command.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Another command is already in progress: files-index',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    public function testHighLevelOwnerRejectsIncompatibleActiveCommandWithoutChangingState(): void
    {
        $this->writeFilesPullState(array(
            'active_resumable_command' => array(
                'command_name' => 'files-index',
                'completion_state' => 'in_progress',
                'current_stage' => 'index',
                'remote_cursor' => null,
            ),
            'pull_pipeline' => array(
                'started_by_command' => 'pull-files',
                'stage_sequence' => array('preflight', 'files-pull'),
                'last_completed_stage' => 'preflight',
                'has_completed_once' => false,
            ),
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runCommand('pull-files', array(
                'follow_symlinks' => true,
                'include_caches' => true,
                'extra_directory' => '/srv/different-extra',
            ));
            $this->fail('Expected pull-files to reject its incompatible active command.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Another command is already in progress: files-index',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    public function testFilesIndexRejectsChangingCacheSelectionWithoutChangingState(): void
    {
        $this->writeFilesPullState(array(
            'active_resumable_command' => array(
                'command_name' => 'files-index',
                'completion_state' => 'in_progress',
                'current_stage' => 'index',
                'remote_cursor' => null,
            ),
            'include_caches' => true,
        ));
        $stateBefore = file_get_contents($this->pullStateDirectory . '/state.json');

        try {
            $this->runCommand('files-index', array('include_caches' => false));
            $this->fail('Expected files-index to reject a cache-selection change.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --include-caches while resuming files-index',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            $stateBefore,
            file_get_contents($this->pullStateDirectory . '/state.json')
        );
    }

    public function testRunAllowsChangingOnlyPrefixesAfterCompletedFilesPull(): void
    {
        $this->writeFilesPullState(array(
            'active_resumable_command' => array(
                'completion_state' => 'complete',
                'current_stage' => null,
            ),
            'files_pull_path_selection_fingerprint' => $this->pathSelectionFingerprint(array('/var/www/html/wp-content/plugins')),
        ));

        $this->runFilesPull(array('include' => array(':wp-uploads:')));

        $state = $this->readState();
        $this->assertSame('complete', $state['active_resumable_command']['completion_state'] ?? null);
    }

    public function testRunRejectsAddingExclusionsToInProgressOnlyState(): void
    {
        $this->writeFilesPullState(array(
            'files_pull_path_selection_fingerprint' => $this->pathSelectionFingerprint(array()),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --include or --exclude while resuming files-pull');
        $this->runFilesPull(array('exclude' => array(':wp-uploads:')));
    }

    public function testRunRejectsSkippedFieldsFromThePreviousStateSchema(): void
    {
        file_put_contents($this->pullStateDirectory . '/remote-index.next.jsonl', '');
        $fingerprint = $this->pathSelectionFingerprint(
            array(),
            array('/var/www/html/wp-content/uploads')
        );
        $this->writeFilesPullState(array(
            'filter' => 'essential-files',
            'files_pull_path_selection_fingerprint' => $fingerprint,
        ));
        $state = $this->readState();
        $state['fetch_skipped'] = (new \Reprint\Importer\State\FetchListProgressState())->to_array();
        $state['pull_pipeline']['files_filter'] = 'essential-files';
        $state['pull_pipeline']['skipped_pending'] = false;
        file_put_contents(
            $this->pullStateDirectory . '/state.json',
            json_encode($state, JSON_PRETTY_PRINT)
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unexpected fetch_skipped');
        $this->runFilesPull(array('filter' => 'essential-files'));
    }

    public function testPullOnlyFilesPrefixesReplaceRootsAndIgnoreUnselectedRemap(): void
    {
        // With --include, export roots ARE the selected file prefixes: core/abspath/document_root
        // are dropped, and an unselected --remap source stays inert.
        $c = $this->client(array(
            'wp_detect' => array('roots' => array(array('path' => '/var/www/html'))),
            'runtime' => array('document_root' => '/var/www/html'),
            'database' => array('wp' => array('paths_urls' => array(
                'content_dir' => '/var/www/html/wp-content',
            ))),
        ));
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content'));
        $this->set($c, 'resolved_path_mappings', array(
            '/var/www/html/wp-admin' => '/srv/htdocs/wp-admin',
        ));
        $dirs = $this->call($c, 'get_export_directories');
        $this->assertSame(array('/var/www/html/wp-content'), $dirs);
    }
}
