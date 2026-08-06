<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * The local index used by files-pull, files-push, and files-diff.
 *
 * Files-pull records each non-skipped local path it changes. A later files-diff
 * therefore ignores pulled changes while still reporting unrelated local changes.
 */
final class FilesPullLocalIndexTest extends TestCase
{
    private const REMOTE_CTIME = 41;
    private const PULLED_PATH = 'selected/written-by-files-pull.txt';
    private const PULLED_CONTENTS = 'contents delivered by file_fetch';

    private string $root;
    private string $stateDirectory;
    private string $remoteStateDirectory;
    private string $pullStateDirectory;
    private string $rawFileRoot;
    private string $localTree;
    private string $targetUrl;

    /** @var resource|null */
    private $serverProcess = null;

    /** @var array<int,resource> */
    private array $serverPipes = [];

    /** @var array<string,string> */
    private array $remoteFiles = [
        'deleted.txt' => 'delete me',
        'edited.txt' => 'old',
        'folder/remote-deleted.txt' => 'remote child',
        'unchanged.txt' => 'same',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/files-pull-local-index-' . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->rawFileRoot = $this->root . '/files';
        $this->localTree = $this->rawFileRoot . '/var/www/html';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->rawFileRoot, 0700, true);
        $this->targetUrl = $this->startIndexServer();
        $this->remoteStateDirectory = $this->stateDirectory
            . '/remotes/'
            . md5(rtrim($this->targetUrl, '?&'));
        $this->pullStateDirectory = $this->remoteStateDirectory . '/pull';
        $this->writePullState();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProcess);
        }
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testInitialPullCreatesTheLocalIndex(): void
    {
        $this->completeFilesPull();

        $this->assertSame(
            self::PULLED_CONTENTS,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $this->assertSame(
            [
                $this->localIndexEntryPath('deleted.txt'),
                $this->localIndexEntryPath('edited.txt'),
                $this->localIndexEntryPath('folder/remote-deleted.txt'),
                $this->localIndexEntryPath(self::PULLED_PATH),
                $this->localIndexEntryPath('unchanged.txt'),
            ],
            array_keys($this->readIndex($this->localIndexPath()))
        );

        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testEmptyInitialPullCreatesAnEmptyLocalIndex(): void
    {
        $this->writeRemoteOverrides([
            'removed_paths' => array_merge(
                array_keys($this->remoteFiles),
                [self::PULLED_PATH]
            ),
        ]);

        $this->completeFilesPull();

        $localIndexes = glob($this->remoteStateDirectory . '/local_index.jsonl');
        $this->assertIsArray($localIndexes);
        $this->assertCount(1, $localIndexes);
        $this->assertSame([], $this->readIndex($localIndexes[0]));
        $this->assertSame(
            realpath($localIndexes[0]),
            realpath($this->localIndexPath())
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testPullDoesNotAddDefaultSkippedPathsToTheLocalIndex(): void
    {
        $this->writeRemoteOverrides([
            'added_files' => [
                'node_modules/pulled-package.js' => 'pulled dependency',
            ],
        ]);

        $this->completeFilesPull();

        $this->assertSame(
            'pulled dependency',
            file_get_contents(
                $this->localTree . '/node_modules/pulled-package.js'
            )
        );
        $index = $this->readIndex($this->localIndexPath());
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('node_modules'),
            $index
        );
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('node_modules/pulled-package.js'),
            $index
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame(
            0,
            $this->filesDiffRecords($diff['stdout'])[0][
                'local_paths_to_push'
            ]
        );
    }

    public function testFilesPullRejectsAnUnfinishedFilesPush(): void
    {
        if (PHP_VERSION_ID < 80100 || !function_exists('curl_init')) {
            $this->markTestSkipped(
                'Starting files-push requires PHP 8.1+ with curl.'
            );
        }
        $remoteReprintApiUrl = $this->targetUrl;
        mkdir($this->localTree, 0700, true);
        $pushStateDirectory = \ImportClient::resolve_push_state_directory(
            $remoteReprintApiUrl,
            $this->stateDirectory,
            $this->rawFileRoot,
            'files-push'
        );
        $processLock = new \ReprintProcessLock($this->stateDirectory);
        try {
            $sender = \PushFilesSender::start([
                'filesystem_root' => $this->rawFileRoot,
                'push_root' => '/',
                'push_state_directory' => $pushStateDirectory,
                'remote_reprint_api_url' =>
                    $remoteReprintApiUrl,
                'hmac_client' => new \Site_Export_HMAC_Client('secret'),
                'allow_http' => true,
            ], $processLock);
            $sender->close();
        } finally {
            $processLock->close();
        }

        $pull = $this->runFilesPull();

        $this->assertSame(1, $pull['exit'], $pull['output']);
        $this->assertStringContainsString(
            'Finish the unfinished files-push before running files-pull.',
            $pull['output']
        );
    }

    public function testDeltaPullUpdatesOnlyThePathsItChanges(): void
    {
        $this->completeFilesPull();
        file_put_contents($this->localTree . '/edited.txt', 'longer local edit');
        unlink($this->localTree . '/deleted.txt');
        $pulledContents = 'remote change delivered by the second pull';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($pulledContents),
            'removed_paths' => ['unchanged.txt'],
        ]);

        $this->abortFilesPull();
        $delta = $this->runFilesPull();

        $this->assertSame(0, $delta['exit'], $delta['output']);
        $this->assertSame('longer local edit', file_get_contents($this->localTree . '/edited.txt'));
        $this->assertSame($pulledContents, file_get_contents($this->localTree . '/' . self::PULLED_PATH));
        $this->assertFileDoesNotExist($this->localTree . '/unchanged.txt');

        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $records = $this->filesDiffRecords($diff['stdout']);
        $complete = array_pop($records);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 1,
            'local_paths_to_delete' => 1,
        ], $complete);
        $this->assertSame([
            $this->expectedPushRecord('edited.txt'),
            [
                'command' => 'files-diff',
                'action' => 'delete',
                'path_b64' => base64_encode(
                    $this->localIndexEntryPath('deleted.txt')
                ),
            ],
        ], $records);
    }

    public function testResumeReplaysTheWALIntoTheLocalIndex(): void
    {
        $this->completeFilesPull();
        $pulledContents = 'complete file retained in the WAL before interruption';
        $this->interruptPullAfterFile($pulledContents);
        $walPath = $this->pullStateDirectory . '/index.wal';

        $this->assertFileExists($walPath);
        $blockedDiff = $this->runFilesDiff();
        $this->assertSame(1, $blockedDiff['exit'], $blockedDiff['output']);
        $this->assertStringContainsString(
            'Finish or abort the interrupted files-pull',
            $blockedDiff['output']
        );
        $blockedPush = $this->runCli([
            'files-push',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--secret=secret',
            '--force-http',
        ]);
        $this->assertSame(1, $blockedPush['exit'], $blockedPush['output']);
        $this->assertStringContainsString(
            'Finish or abort the interrupted files-pull',
            $blockedPush['output']
        );

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertFileDoesNotExist($walPath);
        $this->assertLocalIndexMatches(self::PULLED_PATH);
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame(0, $this->filesDiffRecords($diff['stdout'])[0]['local_paths_to_push']);
    }

    public function testAbortReplaysTheWALAndKeepsTheLocalIndexUsable(): void
    {
        $this->completeFilesPull();
        $this->interruptPullAfterFile('complete file retained before abort');
        $walPath = $this->pullStateDirectory . '/index.wal';
        $this->assertFileExists($walPath);

        $abort = $this->runFilesPull(['--abort']);

        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertFileDoesNotExist($walPath);
        $this->assertLocalIndexMatches(self::PULLED_PATH);
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    /**
     * @dataProvider partialPullProvider
     * @param list<string> $arguments
     */
    public function testFilesPullKeepsLocalIndexEntriesForPathsItDoesNotChange(
        array $arguments
    ): void {
        $this->completeFilesPull();
        $before = $this->readIndex($this->localIndexPath());
        $pulledContents = 'partial pull change';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($pulledContents),
        ]);

        $this->abortFilesPull();
        $pull = $this->runFilesPull($arguments);

        $this->assertSame(0, $pull['exit'], $pull['output']);
        $after = $this->readIndex($this->localIndexPath());
        $this->assertSame(
            $before[$this->localIndexEntryPath('unchanged.txt')],
            $after[$this->localIndexEntryPath('unchanged.txt')]
        );
        $this->assertSame(
            $pulledContents,
            file_get_contents(
                $this->localTree . '/' . self::PULLED_PATH
            )
        );
        $this->assertLocalIndexMatches(self::PULLED_PATH);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function partialPullProvider(): iterable
    {
        yield 'selected directory' => [[
            '--only=/var/www/html/selected',
        ]];
        yield 'filtered files' => [[
            '--filter=essential-files',
        ]];
        yield 'preserve local files' => [[
            '--on-fs-root-nonempty=preserve-local',
        ]];
    }

    public function testRemappedPullKeepsUnrelatedLocalIndexEntries(): void
    {
        $arguments = [
            '--remap',
            '/var/www/html',
            ':fs-root:/var/www/html/remapped',
        ];
        $initial = $this->runFilesPull($arguments);
        $this->assertSame(0, $initial['exit'], $initial['output']);
        $remappedUnchangedPath = $this->localIndexEntryPath(
            'remapped/unchanged.txt'
        );
        $before = $this->readIndex(
            $this->localIndexPath()
        )[$remappedUnchangedPath];
        $pulledContents = 'remapped pull change';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($pulledContents),
        ]);

        $this->abortFilesPull();
        $pull = $this->runFilesPull($arguments);

        $this->assertSame(0, $pull['exit'], $pull['output']);
        $this->assertSame(
            $pulledContents,
            file_get_contents($this->localTree . '/remapped/' . self::PULLED_PATH)
        );
        $index = $this->readIndex($this->localIndexPath());
        $this->assertSame($before, $index[$remappedUnchangedPath]);
        $this->assertArrayHasKey(
            $this->localIndexEntryPath('remapped/' . self::PULLED_PATH),
            $index
        );
    }

    public function testPulledDeletionRemovesTheDerivedDirectoryRoot(): void
    {
        $this->completeFilesPull();
        file_put_contents($this->localTree . '/folder/local-added.txt', 'local addition');
        $this->writeRemoteOverrides([
            'removed_paths' => ['folder/remote-deleted.txt'],
        ]);

        $this->abortFilesPull();
        $pull = $this->runFilesPull([
            '--only=/var/www/html/folder',
        ]);

        $this->assertSame(0, $pull['exit'], $pull['output']);
        $this->assertDirectoryDoesNotExist($this->localTree . '/folder');
        $index = $this->readIndex($this->localIndexPath());
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('folder'),
            $index
        );
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('folder/remote-deleted.txt'),
            $index
        );
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('folder/local-added.txt'),
            $index
        );

        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testPulledDirectoryDeletionRemovesItsLocalIndexSubtree(): void
    {
        $this->writeRemoteOverrides([
            'added_directories' => ['removed-tree'],
            'added_files' => [
                'removed-tree/child.txt' => 'remote child',
            ],
        ]);
        $this->completeFilesPull();
        $this->assertFileExists($this->localTree . '/removed-tree/child.txt');

        $this->abortFilesPull();
        $this->writeRemoteOverrides([]);
        $pull = $this->runFilesPull();

        $this->assertSame(0, $pull['exit'], $pull['output']);
        $this->assertDirectoryDoesNotExist($this->localTree . '/removed-tree');
        $index = $this->readIndex($this->localIndexPath());
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('removed-tree'),
            $index
        );
        $this->assertArrayNotHasKey(
            $this->localIndexEntryPath('removed-tree/child.txt'),
            $index
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame(0, $this->filesDiffRecords($diff['stdout'])[0]['local_paths_to_push']);
    }

    private function completeFilesPull(): void
    {
        $result = $this->runFilesPull();
        $this->assertSame(0, $result['exit'], $result['output']);
    }

    private function abortFilesPull(): void
    {
        $result = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $result['exit'], $result['output']);
    }

    private function interruptPullAfterFile(string $contents): void
    {
        $this->abortFilesPull();
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($contents),
            'pause_before_response_end' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $walPath = $this->pullStateDirectory . '/index.wal';
        $deadline = microtime(true) + 10;
        while (
            ( !is_file($readyPath) || !is_file($walPath) || filesize($walPath) === 0 )
            && microtime(true) < $deadline
        ) {
            clearstatcache(true, $walPath);
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        $this->assertFileExists($walPath);
        $this->assertGreaterThan(0, filesize($walPath));

        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        file_put_contents($this->root . '/remote-overrides.json.pause-release', '');
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($contents),
        ]);
    }

    private function assertLocalIndexMatches(string $path): void
    {
        $stat = lstat($this->localTree . '/' . $path);
        $this->assertIsArray($stat);
        $entry = $this->readIndex($this->localIndexPath())[
            $this->localIndexEntryPath($path)
        ];
        $this->assertSame( (int) $stat['ctime'], $entry['ctime']);
        $this->assertSame( (int) $stat['size'], $entry['size']);
    }

    /** @return array{command:string,action:string,path_b64:string,type:string,size:int,ctime:int} */
    private function expectedPushRecord(string $path): array
    {
        $stat = lstat($this->localTree . '/' . $path);
        $this->assertIsArray($stat);
        return [
            'command' => 'files-diff',
            'action' => 'push',
            'path_b64' => base64_encode(
                $this->localIndexEntryPath($path)
            ),
            'type' => 'file',
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function writeRemoteOverrides(array $overrides): void
    {
        file_put_contents(
            $this->root . '/remote-overrides.json',
            json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @param list<string> $extraArguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runFilesPull(array $extraArguments = []): array
    {
        return $this->runCli(array_merge([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ], $extraArguments));
    }

    /** @return array{exit:int,stdout:string,stderr:string,output:string} */
    private function runFilesDiff(): array
    {
        return $this->runCli([
            'files-diff',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
    }

    /** @param list<string> $arguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments): array
    {
        [$process, $pipes] = $this->startCliProcess($arguments);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    /** @param list<string> $arguments
     *  @return array{0:resource,1:array<int,resource>}
     */
    private function startCliProcess(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../importer/import.php'], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @return list<array<string,mixed>> */
    private function filesDiffRecords(string $output): array
    {
        $records = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ( $record['command'] ?? null ) === 'files-diff') {
                $records[] = $record;
            }
        }
        return $records;
    }

    /** @return array<string,array{path:string,type:string,size:int,ctime:int,empty?:bool}> */
    private function readIndex(string $path): array
    {
        $entries = [];
        $lines = file(
            $path,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        foreach ($lines as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $decodedPath = base64_decode($entry['path']);
            $entries[$decodedPath] = [
                'path' => $decodedPath,
                'type' => $entry['type'],
                'size' => $entry['size'],
                'ctime' => $entry['ctime'],
            ];
            if (array_key_exists('empty', $entry)) {
                $entries[$decodedPath]['empty'] = $entry['empty'];
            }
        }
        return $entries;
    }

    private function localIndexPath(): string
    {
        return $this->remoteStateDirectory . '/local_index.jsonl';
    }

    private function localIndexEntryPath(string $siteRelativePath = ''): string
    {
        return 'var/www/html'
            . ( $siteRelativePath === ''
                ? ''
                : '/' . ltrim($siteRelativePath, '/') );
    }

    private function writePullState(): void
    {
        mkdir($this->pullStateDirectory, 0700, true);
        $state = new \PullState();
        $state->preflight = [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'runtime' => [
                    'document_root' =>
                        'base64:'
                        . base64_encode('/var/www/html'),
                ],
                'wp_detect' => [
                    'roots' => [[
                        'path' =>
                            'base64:'
                            . base64_encode('/var/www/html'),
                    ]],
                ],
                'database' => [
                    'wp' => [
                        'paths_urls' => [
                            'content_dir' => '/var/www/html/wp-content',
                            'uploads' => [
                                'basedir' => '/var/www/html/wp-content/uploads',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->pullStateDirectory . '/state.json',
            json_encode(
                $state->to_array(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            ''
        );
    }

    private function startIndexServer(): string
    {
        $remoteFiles = $this->remoteFiles;
        $remoteFiles[self::PULLED_PATH] = self::PULLED_CONTENTS;
        $router = $this->root . '/index-server.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
$base_remote_files = json_decode(base64_decode('%s'), true);
$pulled_path = base64_decode('%s');
$overrides_path = base64_decode('%s');
$overrides = is_file($overrides_path)
    ? json_decode((string) file_get_contents($overrides_path), true)
    : array();
$remote_files = $base_remote_files;
$pulled_ctime = 41;
if (isset($overrides['pulled_contents_b64'])) {
    $remote_files[$pulled_path] = base64_decode($overrides['pulled_contents_b64']);
}
if (isset($overrides['pulled_ctime'])) {
    $pulled_ctime = (int) $overrides['pulled_ctime'];
}
foreach (($overrides['removed_paths'] ?? array()) as $removed_path) {
    unset($remote_files[$removed_path]);
}
foreach (($overrides['added_files'] ?? array()) as $path => $contents) {
    $remote_files[$path] = $contents;
}
$added_directories = array_fill_keys(
    $overrides['added_directories'] ?? array(),
    true
);
$added_files = array_fill_keys(
    array_keys($overrides['added_files'] ?? array()),
    true
);
$remote_index = array();
foreach ($remote_files as $path => $contents) {
    $remote_index['/var/www/html/' . $path] = array(
        'path' => base64_encode('/var/www/html/' . $path),
        'ctime' => $path === $pulled_path
            ? $pulled_ctime
            : (isset($added_files[$path]) ? 43 : 41),
        'size' => strlen($contents),
        'type' => 'file',
    );
}
foreach ($added_directories as $path => $_) {
    $remote_index['/var/www/html/' . $path] = array(
        'path' => base64_encode('/var/www/html/' . $path),
        'ctime' => 43,
        'size' => 0,
        'type' => 'dir',
    );
}
uksort($remote_index, static function (string $left, string $right): int {
    return strcmp(
        str_replace('/', "\0", $left),
        str_replace('/', "\0", $right)
    );
});

$endpoint = $_GET['endpoint'] ?? null;
$request_cursor = $_GET['cursor'] ?? null;
$selected_directories = $_GET['directory'] ?? array();
if ($endpoint === 'file_index' && count($selected_directories) > 0) {
    $remote_index = array_filter(
        $remote_index,
        static function (array $entry) use ($selected_directories): bool {
            $path = base64_decode($entry['path']);
            foreach ($selected_directories as $directory) {
                $directory = rtrim($directory, '/');
                if ($path === $directory || strpos($path, $directory . '/') === 0) {
                    return true;
                }
            }
            return false;
        }
    );
}

if ($endpoint === 'preflight') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'ok' => true,
        'runtime' => array(
            'document_root' => '/var/www/html',
            'ini_get_all' => array(),
        ),
        'wp_detect' => array(
            'roots' => array(array('path' => '/var/www/html')),
        ),
        'database' => array(
            'wp' => array(
                'wp_version' => '6.0-test',
                'table_prefix' => 'wp_',
                'paths_urls' => array(
                    'abspath' => '/var/www/html',
                    'content_dir' => '/var/www/html/wp-content',
                    'uploads' => array(
                        'basedir' => '/var/www/html/wp-content/uploads',
                    ),
                ),
            ),
        ),
        'limits' => array('max_request_bytes' => 4194304),
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

$boundary = 'reprint-files-pull-local-index-test';
header('Content-Type: multipart/mixed; boundary=' . $boundary);
$write_part = static function (array $headers, string $body = '') use ($boundary): void {
    echo "--{$boundary}\r\n";
    foreach ($headers as $name => $value) {
        echo $name . ': ' . $value . "\r\n";
    }
    echo 'Content-Length: ' . strlen($body) . "\r\n\r\n";
    echo $body . "\r\n";
};

if ($endpoint === 'file_index') {
    $write_part(
        array('X-Chunk-Type' => 'index_batch'),
        json_encode(array_values($remote_index), JSON_UNESCAPED_SLASHES)
    );
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Total-Entries' => count($remote_index),
    ));
} elseif ($endpoint === 'file_fetch') {
    $requested_paths = null;
    if (
        isset($_FILES['file_list']['tmp_name'])
        && is_file($_FILES['file_list']['tmp_name'])
    ) {
        $requested_paths = array_fill_keys(
            json_decode((string) file_get_contents($_FILES['file_list']['tmp_name']), true),
            true
        );
    }
    $parts = array();
    foreach ($remote_index as $path => $entry) {
        if ($requested_paths !== null && !isset($requested_paths[$path])) {
            continue;
        }
        $relative_path = substr($path, strlen('/var/www/html/'));
        $parts[] = array(
            'path' => $path,
            'entry' => $entry,
            'contents' => $entry['type'] === 'file' ? $remote_files[$relative_path] : '',
            'cursor' => 'path-' . sha1($path),
        );
    }
    $cursor_reached = $request_cursor === null;
    $files_completed = 0;
    $bytes_processed = 0;
    foreach ($parts as $part) {
        if (!$cursor_reached) {
            if ($part['cursor'] === $request_cursor) {
                $cursor_reached = true;
            }
            continue;
        }
        if ($part['entry']['type'] === 'dir') {
            $write_part(array(
                'X-Chunk-Type' => 'directory',
                'X-Directory-Path' => $part['entry']['path'],
                'X-Directory-Ctime' => $part['entry']['ctime'],
                'X-Cursor' => $part['cursor'],
            ));
        } else {
            $write_part(array(
                'X-Chunk-Type' => 'file',
                'X-File-Path' => $part['entry']['path'],
                'X-File-Ctime' => $part['entry']['ctime'],
                'X-File-Size' => strlen($part['contents']),
                'X-First-Chunk' => 1,
                'X-Last-Chunk' => 1,
                'X-Cursor' => $part['cursor'],
            ), $part['contents']);
            $bytes_processed += strlen($part['contents']);
        }
        ++$files_completed;
    }
    $completion_body = !empty($overrides['pause_before_response_end'])
        ? str_repeat(' ', 65536)
        : '';
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Files-Completed' => $files_completed,
        'X-Bytes-Processed' => $bytes_processed,
    ), $completion_body);
    if (!empty($overrides['pause_before_response_end'])) {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        file_put_contents($overrides_path . '.pause-ready', '');
        while (!is_file($overrides_path . '.pause-release')) {
            usleep(20000);
        }
    }
} else {
    $write_part(array(
        'X-Chunk-Type' => 'error',
        'X-Status' => 'failed',
    ), json_encode(array('message' => 'Unexpected endpoint')));
}
echo "--{$boundary}--\r\n";
PHP,
            base64_encode( (string) json_encode($remoteFiles)),
            base64_encode(self::PULLED_PATH),
            base64_encode($this->root . '/remote-overrides.json')
        ));

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $socketName = stream_socket_get_name($socket, false);
        $this->assertIsString($socketName);
        fclose($socket);
        $port = (int) substr(strrchr($socketName, ':'), 1);

        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->serverPipes,
            $this->root
        );
        $this->assertIsResource($this->serverProcess);
        fclose($this->serverPipes[0]);

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://127.0.0.1:' . $port . '/export.php?site-export-api';
            }
            usleep(100000);
        }
        $this->fail('Local index server did not start.');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
