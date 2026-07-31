<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --only: the diff must reconcile only within the --only file path prefixes. A remote index built
 * with --only lists selected paths only, so the delete drains in
 * compare_remote_indexes_and_build_fetch_list() would otherwise wrongly delete every
 * unselected remote index entry. Guard both drains so unselected local
 * files and remote index entries survive, while selected orphans are still
 * deleted.
 */
class OnlyFilesPathPrefixDiffTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-diff-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/pull', 0755, true);
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
        file_put_contents($this->stateDir . '/' . $name, $contents);
    }

    private function readRemoteIndexPaths(): array
    {
        $file = $this->stateDir . '/pull/remote-index.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data["path"])) {
                $paths[] = base64_decode($data["path"]);
            }
        }
        return $paths;
    }

    /** Mirror FilesPullStateTest: load state + preserve-local, then set the --only file path prefixes. */
    private function prepareClient(array $pull_only_files_with_path_prefixes): array
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
        return [$client, $r];
    }

    public function testOnlyFilesPrefixDiffKeepsUnselectedAndDeletesSelectedOrphan(): void
    {
        // Remote index (sorted): an unselected entry, a matched selected file,
        // and a selected orphan absent from the --only remote index. The
        // delete drains must reconcile only within the --only file prefixes, so the remote index
        // accumulates as a union across files-pull --only runs.
        $this->writeIndex('pull/remote-index.jsonl',
            $this->indexLine('/wp-config.php', 1000, 10)               // unselected
            . $this->indexLine('/wp-content/keep.txt', 1000, 10)       // matched
            . $this->indexLine('/wp-content/old/orphan.txt', 1000, 10) // selected orphan
        );
        $this->writeIndex('pull/remote-index.next.jsonl',
            $this->indexLine('/wp-content/keep.txt', 1000, 10)
        );

        $unselected = $this->seedLocalFile('/wp-config.php');
        $this->seedLocalFile('/wp-content/keep.txt');
        $orphan = $this->seedLocalFile('/wp-content/old/orphan.txt');

        [$client, $r] = $this->prepareClient(['/wp-content']);
        $r->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);

        // Unselected file AND its index entry survive.
        $this->assertFileExists($unselected);
        $this->assertContains('/wp-config.php', $this->readRemoteIndexPaths());
        // Selected orphan file AND its index entry are deleted.
        $this->assertFileDoesNotExist($orphan);
        $this->assertNotContains('/wp-content/old/orphan.txt', $this->readRemoteIndexPaths());
    }

    public function testOnlyRootItselfSurvivesTheDeleteDrains(): void
    {
        // A remote index built with --only lists each selected directory's
        // *contents* but never the directory itself, so the --only roots
        // always look deleted-on-remote to the diff. The drains must not
        // delete them: that would recursively remove the very directories
        // the user asked to pull, while the matched children keep the
        // fetch list empty — silent data loss.
        $this->writeIndex('pull/remote-index.jsonl',
            $this->indexLine('/wp-content/themes', 1000, 0, 'dir')
            . $this->indexLine('/wp-content/themes/keep/style.css', 1000, 10)
            . $this->indexLine('/wp-content/themes/old/orphan.css', 1000, 10)
        );
        $this->writeIndex('pull/remote-index.next.jsonl',
            $this->indexLine('/wp-content/themes/keep/style.css', 1000, 10)
        );

        $kept = $this->seedLocalFile('/wp-content/themes/keep/style.css');
        $orphan = $this->seedLocalFile('/wp-content/themes/old/orphan.css');

        [$client, $r] = $this->prepareClient(['/wp-content/themes']);
        $r->getMethod('compare_remote_indexes_and_build_fetch_list')->invoke($client);

        // The selected root, its matched contents, and its index entry survive…
        $this->assertDirectoryExists($this->filesystem_root . '/wp-content/themes');
        $this->assertFileExists($kept);
        $this->assertContains('/wp-content/themes', $this->readRemoteIndexPaths());
        // …while a genuine orphan inside it is still drained.
        $this->assertFileDoesNotExist($orphan);
        $this->assertNotContains('/wp-content/themes/old/orphan.css', $this->readRemoteIndexPaths());
    }
}
