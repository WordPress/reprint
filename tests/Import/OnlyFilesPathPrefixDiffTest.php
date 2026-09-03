<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Path selection: the diff must reconcile only within the selected path
 * prefixes. --include scopes the downloaded next remote index, while --exclude
 * is applied locally to that index. The delete drains in
 * compare_remote_indexes_and_build_fetch_list() must keep unselected local
 * files and remote index entries while still deleting selected orphans.
 */
class OnlyFilesPathPrefixDiffTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-diff-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $remoteReprintApiUrl = 'http://fake.url';
        $this->pullStateDirectory =
            $this->stateDir
            . '/remotes/'
            . md5(rtrim($remoteReprintApiUrl, '?&'))
            . '/pull';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->pullStateDirectory, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
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
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function indexLine(string $path, int $ctime, int $size, string $type = "file"): string
    {
        return json_encode([
            "path" => base64_encode($path),
            "ctime" => $ctime,
            "size" => $size,
            "type" => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** Create a local file under fs_root for a (source-absolute) index path. */
    private function seedLocalFile(string $path, string $contents = "x"): string
    {
        $full = $this->filesystem_root . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $contents);
        return $full;
    }

    private function writeIndex(string $name, string $contents): void
    {
        file_put_contents($this->pullStateDirectory . '/' . $name, $contents);
    }

    private function readRemoteIndexEntryPaths(): array
    {
        $remoteIndexFile = $this->pullStateDirectory . '/remote-index.jsonl';
        if (!file_exists($remoteIndexFile)) {
            return [];
        }
        $paths = [];
        foreach (file($remoteIndexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data["path"])) {
                $paths[] = base64_decode($data["path"]);
            }
        }
        return $paths;
    }

    /** Load state + preserve-local, then set the file path selection. */
    private function prepareClient(
        array $pull_only_files_with_path_prefixes,
        array $pull_excluded_files_with_path_prefixes = []
    ): array
    {
        $state = [
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "diff",
            ],
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
        ];
        \write_current_pull_state(
            new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root),
            $state
        );

        $client = new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root);
        $r = new \ReflectionClass($client);
        $r->getProperty('state')->setValue($client, $r->getMethod('load_state')->invoke($client));
        $r->getProperty('is_tty')->setValue($client, false);
        $r->getProperty('fs_root_nonempty_behavior')->setValue($client, 'preserve-local');
        $r->getProperty('pull_only_files_with_path_prefixes')->setValue($client, $pull_only_files_with_path_prefixes);
        $r->getProperty('pull_excluded_files_with_path_prefixes')->setValue($client, $pull_excluded_files_with_path_prefixes);
        return [$client, $r];
    }

    /** Load state for an unscoped sync whose enumerated roots come from preflight. */
    private function prepareCompleteSyncClient(array $preflightRoots): array
    {
        $roots = [];
        foreach ($preflightRoots as $path) {
            $roots[] = ["path" => $path];
        }
        [$client, $reflection] = $this->prepareClient([]);
        $client->get_state()->set_preflight_record([
            'data' => ['wp_detect' => ['roots' => $roots]],
        ]);
        $reflection->getProperty('audit_log_file')->setValue($client, $this->tempDir . '/audit.log');
        return [$client, $reflection];
    }

    /**
     * A complete sync is bounded too. When the whole source index comes back empty the
     * deletion must stop at the enumerated root rather than climbing to /var.
     */
    public function testCompleteSyncWithAnEmptyIndexStopsAtTheEnumeratedRoot(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/var/www/html/wp-config.php', 1000, 10)
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $inside = $this->seedLocalFile('/var/www/html/wp-config.php');
        $outside = $this->seedLocalFile('/var/backups/keep.txt');

        [$client, $reflection] = $this->prepareCompleteSyncClient(['/var/www/html']);
        $this->assertSame(
            ['/var/www/html'],
            $reflection->getMethod('get_export_directories')->invoke($client),
            'Test premise: preflight supplies the enumerated scope.'
        );

        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        $this->assertFileDoesNotExist($inside);
        $this->assertFileExists($outside);
    }

    /**
     * Following symlinks indexes targets outside every export directory. Bounding
     * those would stop the climb collapsing a deleted target tree, leaving the
     * emptied directories behind, so they keep the neighbor-only inference.
     */
    public function testDeletedFollowedTargetOutsideExportDirectoriesStillCollapses(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/shared/theme/style.css', 1000, 10)
            . $this->indexLine('/shared/theme/sub/b.css', 1000, 10)
            . $this->indexLine('/var/www/html/wp-config.php', 1000, 10)
        );
        $this->writeIndex(
            'remote-index.next.jsonl',
            $this->indexLine('/var/www/html/wp-config.php', 1000, 10)
        );
        $this->seedLocalFile('/shared/theme/style.css');
        $this->seedLocalFile('/shared/theme/sub/b.css');
        $kept = $this->seedLocalFile('/var/www/html/wp-config.php');

        [$client, $reflection] = $this->prepareCompleteSyncClient(['/var/www/html']);
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')->getValue($client)->apply_pending_records();

        $this->assertDirectoryDoesNotExist(
            $this->filesystem_root . '/shared/theme',
            'The emptied followed-target tree must go, not just its files.'
        );
        $this->assertFileExists($kept);
    }

    public function testOnlyFilesPrefixDiffKeepsUnselectedAndDeletesSelectedOrphan(): void
    {
        // Remote index (sorted): an unselected entry, a matched selected file,
        // and a selected orphan absent from the --include next remote index. The
        // delete drains must reconcile only within the --include file prefixes, so the remote index
        // accumulates as a union across files-pull --include runs.
        $this->writeIndex('remote-index.jsonl',
            $this->indexLine('/wp-config.php', 1000, 10)               // unselected
            . $this->indexLine('/wp-content/keep.txt', 1000, 10)       // matched
            . $this->indexLine('/wp-content/old/orphan.txt', 1000, 10) // selected orphan
        );
        $this->writeIndex('remote-index.next.jsonl',
            $this->indexLine('/wp-content/keep.txt', 1000, 10)
        );

        $unselected = $this->seedLocalFile('/wp-config.php');
        $this->seedLocalFile('/wp-content/keep.txt');
        $orphan = $this->seedLocalFile('/wp-content/old/orphan.txt');

        [$client, $r] = $this->prepareClient(['/wp-content']);
        $r->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $r->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        // Unselected file AND its index entry survive.
        $this->assertFileExists($unselected);
        $this->assertContains('/wp-config.php', $this->readRemoteIndexEntryPaths());
        // Selected orphan file AND its index entry are deleted.
        $this->assertFileDoesNotExist($orphan);
        $this->assertNotContains('/wp-content/old/orphan.txt', $this->readRemoteIndexEntryPaths());
    }

    public function testExcludeFilesPrefixDiffKeepsExcludedOrphan(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/wp-content/uploads/local-only.jpg', 1000, 100)
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $excluded = $this->seedLocalFile('/wp-content/uploads/local-only.jpg');

        [$client, $reflection] = $this->prepareClient(
            [],
            ['/wp-content/uploads']
        );
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        $this->assertFileExists($excluded);
        $this->assertContains(
            '/wp-content/uploads/local-only.jpg',
            $this->readRemoteIndexEntryPaths()
        );
    }

    public function testExcludeFilesPrefixPrunesFetchListFromFullNextRemoteIndex(): void
    {
        $this->writeIndex('remote-index.jsonl', '');
        $this->writeIndex(
            'remote-index.next.jsonl',
            $this->indexLine('/wp-content/themes/flavor/style.css', 1000, 100)
            . $this->indexLine('/wp-content/uploads/photo.jpg', 1000, 100)
        );

        [$client, $reflection] = $this->prepareClient(
            [],
            ['/wp-content/uploads']
        );
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);

        $fetchListLines = file(
            $this->pullStateDirectory . '/fetch-list.jsonl',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $fetchEntries = array_map(
            static function (string $line): array {
                return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            },
            $fetchListLines
        );
        $fetchPaths = array_map(
            static function (array $entry): string {
                return base64_decode($entry['path'], true);
            },
            $fetchEntries
        );

        $this->assertSame(
            ['/wp-content/themes/flavor/style.css'],
            $fetchPaths
        );
        $this->assertSame('file', $fetchEntries[0]['type']);
        $this->assertSame(100, $fetchEntries[0]['size']);
        $this->assertStringContainsString(
            base64_encode('/wp-content/uploads/photo.jpg'),
            file_get_contents($this->pullStateDirectory . '/remote-index.next.jsonl')
        );
    }

    public function testRemoteFollowedPathOutsideOnlyScopeIsFetched(): void
    {
        // The exporter applies the --include roots before building this index, but
        // following a selected symlink may add its target outside those roots.
        // The importer must fetch that target while still using --include to scope
        // deletions from the prior remote index.
        $this->writeIndex('remote-index.jsonl', '');
        $this->writeIndex(
            'remote-index.next.jsonl',
            $this->indexLine('/shared/themes/indice/style.css', 1000, 10)
        );

        [$client, $reflection] = $this->prepareClient(['/wp-content/plugins']);
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);

        $fetch_entry = json_decode(
            trim(file_get_contents($this->pullStateDirectory . '/fetch-list.jsonl')),
            true
        );
        $this->assertSame(
            '/shared/themes/indice/style.css',
            base64_decode($fetch_entry['path'], true)
        );
    }

    public function testOnlyRootItselfSurvivesTheDeleteDrains(): void
    {
        // A next remote index built with --include lists each selected directory's
        // *contents* but never the directory itself, so the --include roots
        // always look deleted-on-remote to the diff. The drains must not
        // delete them: that would recursively remove the very directories
        // the user asked to pull, while the matched children keep the
        // fetch list empty — silent data loss.
        $this->writeIndex('remote-index.jsonl',
            $this->indexLine('/wp-content/themes', 1000, 0, 'dir')
            . $this->indexLine('/wp-content/themes/keep/style.css', 1000, 10)
            . $this->indexLine('/wp-content/themes/old/orphan.css', 1000, 10)
        );
        $this->writeIndex('remote-index.next.jsonl',
            $this->indexLine('/wp-content/themes/keep/style.css', 1000, 10)
        );

        $kept = $this->seedLocalFile('/wp-content/themes/keep/style.css');
        $orphan = $this->seedLocalFile('/wp-content/themes/old/orphan.css');

        [$client, $r] = $this->prepareClient(['/wp-content/themes']);
        $r->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $r->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        // The selected root, its matched contents, and its index entry survive…
        $this->assertDirectoryExists($this->filesystem_root . '/wp-content/themes');
        $this->assertFileExists($kept);
        $this->assertContains('/wp-content/themes', $this->readRemoteIndexEntryPaths());
        // …while a genuine orphan inside it is still drained.
        $this->assertFileDoesNotExist($orphan);
        $this->assertNotContains('/wp-content/themes/old/orphan.css', $this->readRemoteIndexEntryPaths());
    }

    public function testConfirmedAbsentSelectedFileRootIsDeleted(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/wp-config.php', 1000, 10)
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $local = $this->seedLocalFile('/wp-config.php');

        [$client, $reflection] = $this->prepareClient(['/wp-config.php']);
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        $this->assertFileDoesNotExist($local);
        $this->assertNotContains('/wp-config.php', $this->readRemoteIndexEntryPaths());
    }

    public function testExcludedSelectedLinkRootIsNotDeleted(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/mnt/uploads', 1000, 10, 'link')
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $local = $this->seedLocalFile('/mnt/uploads');

        [$client, $reflection] = $this->prepareClient(
            ['/mnt/uploads'],
            ['/mnt/uploads']
        );
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);

        $this->assertFileExists($local);
        $this->assertContains('/mnt/uploads', $this->readRemoteIndexEntryPaths());
    }

    public function testOnlyPathsPulledBeforeMayBeReportedMissing(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/wp-config.php', 1000, 10)
        );

        [$client, $reflection] = $this->prepareClient([
            '/wp-config.php',
            '/new-file.php',
        ]);

        $this->assertSame(
            ['/wp-config.php'],
            $reflection->getMethod('get_selected_paths_pulled_before')->invoke($client)
        );
    }

    /**
     * A selected file that vanishes leaves the next remote index empty, so the
     * deletion root deriver sees no neighboring entry on either side. It must not
     * read that silence as permission to climb to the first path component.
     */
    public function testVanishedSelectedFileRootDeletesNothingAboveItself(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/var/www/html/wp-config.php', 1000, 10)
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $selected = $this->seedLocalFile('/var/www/html/wp-config.php');
        $unrelated = $this->seedLocalFile('/var/www/html/wp-content/themes/x/style.css');

        [$client, $reflection] = $this->prepareClient(['/var/www/html/wp-config.php']);
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        $this->assertFileDoesNotExist($selected);
        $this->assertFileExists($unrelated);
        $this->assertDirectoryExists($this->filesystem_root . '/var/www/html');
    }

    /**
     * The climb still collapses a wholly deleted selected directory into one
     * deletion, since the selected root itself is the floor rather than the wall.
     */
    public function testVanishedSelectedDirectoryRootIsDeletedAsOnePath(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/var/www/html/wp-content/themes/x/style.css', 1000, 10)
        );
        $this->writeIndex('remote-index.next.jsonl', '');
        $inside = $this->seedLocalFile('/var/www/html/wp-content/themes/x/style.css');
        $outside = $this->seedLocalFile('/var/www/html/wp-config.php');

        [$client, $reflection] = $this->prepareClient(['/var/www/html/wp-content']);
        $reflection->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();

        $this->assertFileDoesNotExist($inside);
        $this->assertDirectoryDoesNotExist($this->filesystem_root . '/var/www/html/wp-content');
        $this->assertFileExists($outside);
    }

    /**
     * The index lists a directory root's contents, never the root entry, so a
     * selected directory has to be recognised through what sits under it.
     */
    public function testSelectedDirectoryCountsWhenTheIndexHoldsItsContents(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/var/www/html/wp-content/themes/x/style.css', 1000, 10)
        );

        [$client, $reflection] = $this->prepareClient([
            '/var/www/html/wp-content',
            '/var/www/html/never-indexed',
        ]);

        $this->assertSame(
            ['/var/www/html/wp-content'],
            $reflection->getMethod('get_selected_paths_pulled_before')->invoke($client)
        );
    }
}
