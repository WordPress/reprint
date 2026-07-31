<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class AbortStateTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/abort-state-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->stateDirectory . '/pull', 0700, true);
        mkdir($this->fileRoot . '/wp-content', 0700, true);
        mkdir($this->root . '/batches', 0700, true);
        mkdir($this->root . '/runtime', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testExplicitImportStateFixtureRoundTrips(): void
    {
        $fixture = $this->populatedState();

        $this->assertSame(
            $fixture,
            \ImportState::from_array($fixture)->to_array(),
        );
    }

    public function testOwnershipMapCoversEveryResumableCommand(): void
    {
        $constant = (
            new \ReflectionClass(\ImportClient::class)
        )->getReflectionConstant('RESUMABLE_COMMAND_SCOPES');
        $this->assertNotFalse($constant);
        $this->assertSame($this->ownershipMap(), $constant->getValue());
    }

    /**
     * @dataProvider abortCommandProvider
     *
     * @param string[] $pipelineStages
     */
    public function testPublicAbortClearsOnlyOwnedStateAndArtifacts(
        string $command,
        string $activeCommand,
        array $pipelineStages,
        ?string $lastCompletedStage
    ): void {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => $activeCommand,
            'completion_state' => 'partial',
            'current_stage' => 'interrupted-stage',
            'remote_cursor' => 'interrupted-cursor',
        ];
        if ($pipelineStages === []) {
            $before['pull_pipeline']['started_by_command'] = 'pull';
            $before['pull_pipeline']['stage_sequence'] = [
                'preflight',
                'files-pull',
                'db-pull',
            ];
            $before['pull_pipeline']['last_completed_stage'] = 'db-pull';
        } else {
            $before['pull_pipeline']['started_by_command'] = $command;
            $before['pull_pipeline']['stage_sequence'] = $pipelineStages;
            $before['pull_pipeline']['last_completed_stage'] =
                $lastCompletedStage;
        }
        \write_current_import_state($client, $before);
        $this->createArtifacts();

        $this->runCommand($client, [
            'command' => $command,
            'abort' => true,
            // Abort ignores normal work options.
            'filter' => 'not-a-filter',
            'fs_root_nonempty_behavior' => 'not-a-behavior',
            'sql_output' => 'not-an-output',
            'target_engine' => 'not-an-engine',
        ]);

        $scopes = $this->ownershipMap()[$command];
        $this->assertSame(
            $this->expectedStateAfterReset(
                $before,
                $scopes,
                true,
                $pipelineStages !== [] ? $command : null,
            ),
            $this->loadPersistedState($client),
        );
        $this->assertArtifactOwnership($scopes);
        $this->assertFileExists(
            $this->stateDirectory . '/pull/local-index.jsonl',
        );
        $this->assertFileExists(
            $this->fileRoot . '/wp-content/downloaded.php',
        );
        $this->assertFileExists($this->root . '/database.sqlite');
        $this->assertFileExists($this->root . '/runtime/runtime.php');
    }

    /** @return array<string,array{string,string,string[],string|null}> */
    public static function abortCommandProvider(): array
    {
        return [
            'files-pull' => ['files-pull', 'files-pull', [], null],
            'files-index' => ['files-index', 'files-index', [], null],
            'db-pull' => ['db-pull', 'db-pull', [], null],
            'db-index' => ['db-index', 'db-index', [], null],
            'db-apply' => ['db-apply', 'db-apply', [], null],
            'pull-files' => [
                'pull-files',
                'files-pull',
                ['preflight', 'files-pull'],
                'preflight',
            ],
            'pull-db' => [
                'pull-db',
                'db-pull',
                ['preflight', 'db-pull', 'db-apply'],
                'preflight',
            ],
            'pull' => [
                'pull',
                'files-pull',
                ['preflight', 'files-pull', 'db-pull'],
                'preflight',
            ],
        ];
    }

    /**
     * @dataProvider unfinishedOwnerProvider
     *
     * @param array<string,mixed> $changes
     */
    public function testUnfinishedOwnerMatrix(
        array $changes,
        ?string $expectedOwner
    ): void {
        $client = $this->client();
        \write_current_import_state($client, $changes);

        $method = (
            new \ReflectionClass(\ImportClient::class)
        )->getMethod('unfinished_import_owner');
        $this->assertSame($expectedOwner, $method->invoke($client));
    }

    /** @return array<string,array{array<string,mixed>,string|null}> */
    public static function unfinishedOwnerProvider(): array
    {
        $unfinishedPull = [
            'pull_pipeline' => [
                'started_by_command' => 'pull',
                'stage_sequence' => [
                    'preflight',
                    'files-pull',
                    'db-pull',
                ],
                'last_completed_stage' => 'preflight',
            ],
        ];

        return [
            'pipeline before lower-level completion' => [
                array_replace_recursive($unfinishedPull, [
                    'active_resumable_command' => [
                        'command_name' => 'files-pull',
                        'completion_state' => 'partial',
                    ],
                ]),
                'pull',
            ],
            'pipeline after lower-level completion' => [
                array_replace_recursive($unfinishedPull, [
                    'active_resumable_command' => [
                        'command_name' => 'files-pull',
                        'completion_state' => 'complete',
                    ],
                ]),
                'pull',
            ],
            'partial standalone after completed pipeline' => [
                [
                    'active_resumable_command' => [
                        'command_name' => 'db-index',
                        'completion_state' => 'partial',
                    ],
                    'pull_pipeline' => [
                        'started_by_command' => 'pull',
                        'stage_sequence' => [
                            'preflight',
                            'files-pull',
                            'db-pull',
                        ],
                        'last_completed_stage' => 'db-pull',
                    ],
                ],
                'db-index',
            ],
            'completed standalone after completed pipeline' => [
                [
                    'active_resumable_command' => [
                        'command_name' => 'db-index',
                        'completion_state' => 'complete',
                    ],
                    'pull_pipeline' => [
                        'started_by_command' => 'pull',
                        'stage_sequence' => [
                            'preflight',
                            'files-pull',
                            'db-pull',
                        ],
                        'last_completed_stage' => 'db-pull',
                    ],
                ],
                null,
            ],
        ];
    }

    /**
     * @dataProvider rejectedCommandProvider
     *
     * @param array<string,mixed> $stateChanges
     */
    public function testRejectedCommandLeavesRawStateAndArtifactsUnchanged(
        string $requestedCommand,
        bool $abort,
        array $stateChanges,
        string $expectedOwner
    ): void {
        $client = $this->client();
        $before = array_replace_recursive(
            $this->populatedState(),
            $stateChanges,
        );
        \write_current_import_state($client, $before);
        $this->createArtifacts();
        $statePath = $this->stateDirectory . '/pull/state.json';
        $rawState = file_get_contents($statePath);
        $artifactSnapshot = $this->artifactSnapshot();

        $caught = null;
        try {
            $this->runCommand($client, [
                'command' => $requestedCommand,
                'abort' => $abort,
                'filter' => 'not-a-filter',
                'fs_root_nonempty_behavior' => 'not-a-behavior',
                'sql_output' => 'not-an-output',
                'target_engine' => 'not-an-engine',
            ]);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $action = $abort ? 'abort' : 'run';
        $this->assertStringContainsString(
            "Cannot {$action} {$requestedCommand} while {$expectedOwner} owns unfinished import state.",
            $caught->getMessage(),
        );
        $this->assertStringContainsString(
            "`reprint {$expectedOwner}`",
            $caught->getMessage(),
        );
        $this->assertStringContainsString(
            "`reprint {$expectedOwner} --abort`",
            $caught->getMessage(),
        );
        $this->assertSame($rawState, file_get_contents($statePath));
        $this->assertSame($artifactSnapshot, $this->artifactSnapshot());
    }

    /** @return array<string,array{string,bool,array<string,mixed>,string}> */
    public static function rejectedCommandProvider(): array
    {
        return [
            'normal command blocked by partial standalone' => [
                'db-index',
                false,
                [
                    'active_resumable_command' => [
                        'command_name' => 'db-apply',
                        'completion_state' => 'partial',
                    ],
                    'pull_pipeline' => [
                        'last_completed_stage' => 'db-pull',
                    ],
                ],
                'db-apply',
            ],
            'abort blocked by in-progress standalone' => [
                'db-index',
                true,
                [
                    'active_resumable_command' => [
                        'command_name' => 'db-apply',
                        'completion_state' => 'in_progress',
                    ],
                    'pull_pipeline' => [
                        'last_completed_stage' => 'db-pull',
                    ],
                ],
                'db-apply',
            ],
            'pipeline takes precedence before stage completion' => [
                'db-index',
                true,
                [
                    'active_resumable_command' => [
                        'command_name' => 'files-pull',
                        'completion_state' => 'partial',
                    ],
                    'pull_pipeline' => [
                        'started_by_command' => 'pull',
                        'stage_sequence' => [
                            'preflight',
                            'files-pull',
                            'db-pull',
                        ],
                        'last_completed_stage' => 'preflight',
                    ],
                ],
                'pull',
            ],
            'pipeline takes precedence after stage completion' => [
                'files-pull',
                true,
                [
                    'active_resumable_command' => [
                        'command_name' => 'files-pull',
                        'completion_state' => 'complete',
                    ],
                    'pull_pipeline' => [
                        'started_by_command' => 'pull',
                        'stage_sequence' => [
                            'preflight',
                            'files-pull',
                            'db-pull',
                        ],
                        'last_completed_stage' => 'preflight',
                    ],
                ],
                'pull',
            ],
            'conflicting pipeline' => [
                'pull-db',
                false,
                [
                    'active_resumable_command' => [
                        'command_name' => 'files-pull',
                        'completion_state' => 'partial',
                    ],
                    'pull_pipeline' => [
                        'started_by_command' => 'pull-files',
                        'stage_sequence' => [
                            'preflight',
                            'files-pull',
                        ],
                        'last_completed_stage' => 'preflight',
                    ],
                ],
                'pull-files',
            ],
        ];
    }

    /**
     * @dataProvider completedCheckpointCollisionProvider
     *
     * @param string[] $effectiveScopes
     */
    public function testAbortPreservesDifferentCompletedCheckpoint(
        string $requestedCommand,
        string $completedCommand,
        array $effectiveScopes
    ): void {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => $completedCommand,
            'completion_state' => 'complete',
            'current_stage' => null,
            'remote_cursor' => null,
        ];
        $before['pull_pipeline']['last_completed_stage'] = 'db-pull';
        \write_current_import_state($client, $before);
        $this->createArtifacts();

        $this->runCommand($client, [
            'command' => $requestedCommand,
            'abort' => true,
        ]);

        $this->assertSame(
            $this->expectedStateAfterReset(
                $before,
                $effectiveScopes,
                false,
                null,
            ),
            $this->loadPersistedState($client),
        );
        $this->assertArtifactOwnership($effectiveScopes);
    }

    /** @return array<string,array{string,string,string[]}> */
    public static function completedCheckpointCollisionProvider(): array
    {
        return [
            'files-pull preserves files-index producer' => [
                'files-pull',
                'files-index',
                ['files-pull'],
            ],
            'files-index preserves files-pull producer' => [
                'files-index',
                'files-pull',
                [],
            ],
            'db-pull preserves db-index producer' => [
                'db-pull',
                'db-index',
                ['db-pull'],
            ],
            'db-index preserves db-pull producer' => [
                'db-index',
                'db-pull',
                [],
            ],
            'pull-db preserves completed db-apply state' => [
                'pull-db',
                'db-apply',
                ['db-index', 'db-pull'],
            ],
        ];
    }

    public function testCompletedPipelineAbortPreservesNewerCompletedCheckpoint(): void
    {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'db-index',
            'completion_state' => 'complete',
            'current_stage' => null,
            'remote_cursor' => null,
        ];
        $before['pull_pipeline'] = [
            'started_by_command' => 'pull-db',
            'stage_sequence' => [
                'preflight',
                'db-pull',
                'db-apply',
            ],
            'last_completed_stage' => 'db-apply',
            'files_filter' => null,
            'skipped_pending' => false,
            'has_completed_once' => true,
        ];
        \write_current_import_state($client, $before);
        $this->createArtifacts();

        $this->runCommand($client, [
            'command' => 'pull-db',
            'abort' => true,
        ]);

        $this->assertSame(
            $this->expectedStateAfterReset(
                $before,
                ['db-pull', 'db-apply'],
                false,
                'pull-db',
            ),
            $this->loadPersistedState($client),
        );
        $this->assertArtifactOwnership(['db-pull', 'db-apply']);
    }

    /**
     * @dataProvider completedPipelineCollisionProvider
     */
    public function testCompletedPipelineCannotStealPartialStandaloneWork(
        bool $abort
    ): void {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'files-pull',
            'completion_state' => 'partial',
            'current_stage' => 'fetch-skipped',
            'remote_cursor' => 'fetch-cursor',
        ];
        $before['pull_pipeline'] = [
            'started_by_command' => 'pull',
            'stage_sequence' => [
                'preflight',
                'files-pull',
                'db-pull',
            ],
            'last_completed_stage' => 'db-pull',
            'files_filter' => 'essential-files',
            'skipped_pending' => true,
            'has_completed_once' => true,
        ];
        \write_current_import_state($client, $before);
        $rawState = file_get_contents(
            $this->stateDirectory . '/pull/state.json',
        );

        $caught = null;
        try {
            $this->runCommand($client, [
                'command' => 'pull',
                'abort' => $abort,
                'runtime' => 'none',
            ]);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString(
            'files-pull owns unfinished import state',
            $caught->getMessage(),
        );
        $this->assertSame(
            $rawState,
            file_get_contents($this->stateDirectory . '/pull/state.json'),
        );
    }

    /** @return array<string,array{bool}> */
    public static function completedPipelineCollisionProvider(): array
    {
        return [
            'normal rerun' => [false],
            'pipeline abort' => [true],
        ];
    }

    /**
     * @dataProvider batchStateProvider
     */
    public function testFileAbortRemovesReachableExternalBatch(
        string $stateKey,
        string $stage
    ): void {
        $client = $this->client();
        $batchPath = $this->root . "/batches/{$stateKey}.jsonl";
        file_put_contents($batchPath, "batch\n");
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'files-pull',
            'completion_state' => 'partial',
            'current_stage' => $stage,
            'remote_cursor' => 'cursor',
        ];
        $before['pull_pipeline']['last_completed_stage'] = 'db-pull';
        $before['fetch']['batch_file'] = null;
        $before['fetch_skipped']['batch_file'] = null;
        $before[$stateKey]['batch_file'] = $batchPath;
        \write_current_import_state($client, $before);

        $this->runCommand($client, [
            'command' => 'files-pull',
            'abort' => true,
        ]);

        $this->assertFileDoesNotExist($batchPath);
    }

    /** @return array<string,array{string,string}> */
    public static function batchStateProvider(): array
    {
        return [
            'fetch batch' => ['fetch', 'fetch'],
            'fetch-skipped batch' => ['fetch_skipped', 'fetch-skipped'],
        ];
    }

    public function testFileAbortReplaysWalAndRemovesScratchWithoutDeletingLocalFiles(): void
    {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'files-pull',
            'completion_state' => 'partial',
            'current_stage' => 'fetch',
            'remote_cursor' => 'cursor',
        ];
        $before['pull_pipeline']['last_completed_stage'] = 'db-pull';
        \write_current_import_state($client, $before);
        file_put_contents(
            $this->stateDirectory . '/pull/local-index.jsonl',
            $this->indexRecord('/site/existing.txt'),
        );
        file_put_contents(
            $this->stateDirectory . '/pull/local-index.wal',
            json_encode([
                'op' => 'F',
                'path' => base64_encode('/site/downloaded.txt'),
                'ctime' => 42,
                'size' => 5,
                'type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        foreach ([
            'pull/remote-index.jsonl.sorted',
            'pull/remote-index.jsonl.keyed',
            'pull/remote-index.jsonl.keyed.sorted',
            'pull/remote-index.jsonl.merge-sorted',
            'pull/merge-chunk-stale',
            'pull/local-index.jsonl.new',
            'pull/local-index.jsonl.swap',
        ] as $artifact) {
            file_put_contents(
                $this->stateDirectory . '/' . $artifact,
                "stale\n",
            );
        }
        file_put_contents(
            $this->fileRoot . '/wp-content/downloaded.php',
            "<?php\n",
        );

        $this->runCommand($client, [
            'command' => 'files-pull',
            'abort' => true,
        ]);
        $firstLocalIndex = file_get_contents(
            $this->stateDirectory . '/pull/local-index.jsonl',
        );
        $this->runCommand($client, [
            'command' => 'files-pull',
            'abort' => true,
        ]);

        $this->assertSame(
            $firstLocalIndex,
            file_get_contents(
                $this->stateDirectory . '/pull/local-index.jsonl',
            ),
        );
        $this->assertStringContainsString(
            base64_encode('/site/existing.txt'),
            $firstLocalIndex,
        );
        $this->assertStringContainsString(
            base64_encode('/site/downloaded.txt'),
            $firstLocalIndex,
        );
        $this->assertFileDoesNotExist(
            $this->stateDirectory . '/pull/local-index.wal',
        );
        $this->assertFileExists(
            $this->fileRoot . '/wp-content/downloaded.php',
        );
        $this->assertFalse(
            $this->loadPersistedState($client)['pull_pipeline'][
                'skipped_pending'
            ],
        );
    }

    /**
     * @dataProvider completionHistoryProvider
     */
    public function testPipelineAbortPreservesCompletionHistory(
        bool $hasCompletedOnce
    ): void {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'db-pull',
            'completion_state' => 'partial',
            'current_stage' => 'sql',
            'remote_cursor' => 'cursor',
        ];
        $before['pull_pipeline'] = [
            'started_by_command' => 'pull-db',
            'stage_sequence' => [
                'preflight',
                'db-pull',
                'db-apply',
            ],
            'last_completed_stage' => 'preflight',
            'files_filter' => null,
            'skipped_pending' => false,
            'has_completed_once' => $hasCompletedOnce,
        ];
        \write_current_import_state($client, $before);

        $this->runCommand($client, [
            'command' => 'pull-db',
            'abort' => true,
        ]);
        $this->runCommand($client, [
            'command' => 'pull-db',
            'abort' => true,
        ]);

        $pipeline = $this->loadPersistedState($client)['pull_pipeline'];
        $this->assertNull($pipeline['started_by_command']);
        $this->assertSame([], $pipeline['stage_sequence']);
        $this->assertNull($pipeline['last_completed_stage']);
        $this->assertSame(
            $hasCompletedOnce,
            $pipeline['has_completed_once'],
        );
    }

    /** @return array<string,array{bool}> */
    public static function completionHistoryProvider(): array
    {
        return [
            'never completed' => [false],
            'completed before' => [true],
        ];
    }

    public function testAbortReportsCorruptArtifactPathAfterSavingResetState(): void
    {
        $client = $this->client();
        $before = $this->populatedState();
        $before['active_resumable_command'] = [
            'command_name' => 'files-index',
            'completion_state' => 'partial',
            'current_stage' => 'index',
            'remote_cursor' => 'cursor',
        ];
        $before['pull_pipeline']['last_completed_stage'] = 'db-pull';
        \write_current_import_state($client, $before);
        $remoteIndex = $this->stateDirectory . '/pull/remote-index.jsonl';
        mkdir($remoteIndex);

        $caught = null;
        try {
            $this->runCommand($client, [
                'command' => 'files-index',
                'abort' => true,
            ]);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString($remoteIndex, $caught->getMessage());
        $this->assertSame(
            ( new \ResumableCommandCheckpointState() )->to_array(),
            $this->loadPersistedState($client)[
                'active_resumable_command'
            ],
        );

        rmdir($remoteIndex);
        $this->runCommand($client, [
            'command' => 'files-index',
            'abort' => true,
        ]);
    }

    /** @return array<string,string[]> */
    private function ownershipMap(): array
    {
        return [
            'files-index' => ['files-index'],
            'files-pull' => ['files-index', 'files-pull'],
            'db-index' => ['db-index'],
            'db-pull' => ['db-index', 'db-pull'],
            'db-apply' => ['db-apply'],
            'pull-files' => ['files-index', 'files-pull'],
            'pull-db' => ['db-index', 'db-pull', 'db-apply'],
            'pull' => [
                'files-index',
                'files-pull',
                'db-index',
                'db-pull',
                'db-apply',
            ],
        ];
    }

    /**
     * Populate every state group so schema additions require an ownership decision.
     *
     * @return array<string,mixed>
     */
    private function populatedState(): array
    {
        return [
            'active_resumable_command' => [
                'command_name' => 'previous-command',
                'completion_state' => 'complete',
                'current_stage' => 'previous-stage',
                'remote_cursor' => 'remote-cursor',
            ],
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
            'remote_protocol_version' => 1,
            'version' => '0.9.3-dev',
            'webhost' => 'wpcloud',
            'follow_symlinks' => false,
            'local_followed_symlinks_root_fingerprint' => 'followed-root',
            'fs_root_nonempty_behavior' => 'preserve-local',
            'filter' => 'essential-files',
            'user_agent' => 'Reprint test',
            'max_allowed_packet' => 1048576,
            'resolved_path_mappings_fingerprint' => 'path-mappings',
            'files_pull_only_fingerprint' => 'only-files',
            'files_pull_summary' => [
                'files_pulled' => 42,
            ],
            'db_index' => [
                'file' => $this->stateDirectory . '/db-tables.jsonl',
                'tables' => 3,
                'rows_estimated' => 120,
                'bytes' => 2048,
                'updated_at' => '1234567890',
            ],
            'diff' => [
                'remote_offset' => 64,
                'local_after' => '/remote/wp-content',
            ],
            'index' => [
                'cursor' => 'file-index-cursor',
            ],
            'fetch' => [
                'offset' => 128,
                'next_offset' => 256,
                'batch_file' => $this->root . '/batches/fetch.jsonl',
                'cursor' => 'fetch-cursor',
                'batch_entries' => 5,
            ],
            'fetch_skipped' => [
                'offset' => 512,
                'next_offset' => 1024,
                'batch_file' => $this->root . '/batches/fetch-skipped.jsonl',
                'cursor' => 'skipped-cursor',
                'batch_entries' => 7,
            ],
            'current_file' =>
                $this->fileRoot . '/wp-content/current.php',
            'current_file_bytes' => 4096,
            'sql_bytes' => 8192,
            'sql_statements_counted' => 99,
            'apply' => [
                'statements_executed' => 17,
                'bytes_read' => 16384,
                'rewrite_url' => [
                    'https://source.example' =>
                        'https://local.example',
                ],
                'target_engine' => 'sqlite',
                'target_db' => 'local_db',
                'target_host' => '127.0.0.1',
                'target_port' => 3307,
                'target_user' => 'local_user',
                'target_pass' => 'local_pass',
                'target_sqlite_path' => $this->root . '/database.sqlite',
                'remote_paths_removed_from_local_site' => [
                    'wp-content/object-cache.php',
                ],
            ],
            'sql_output' => 'mysql',
            'mysql_host' => 'database.example',
            'mysql_port' => 3308,
            'mysql_user' => 'stream_user',
            'mysql_database' => 'stream_db',
            'consecutive_interrupted_responses' => 4,
            'tuning' => [
                'config' => ['enabled' => true],
                'state' => ['file_chunk_bytes' => 2097152],
            ],
            'pull_pipeline' => [
                'started_by_command' => 'pull',
                'stage_sequence' => [
                    'preflight',
                    'files-pull',
                    'db-pull',
                ],
                'last_completed_stage' => 'files-pull',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
                'has_completed_once' => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param string[]            $scopes
     * @return array<string,mixed>
     */
    private function expectedStateAfterReset(
        array $before,
        array $scopes,
        bool $clearCheckpoint,
        ?string $resetPipeline
    ): array {
        $expected = $before;
        if ($clearCheckpoint) {
            $expected['active_resumable_command'] = (
                new \ResumableCommandCheckpointState()
            )->to_array();
            $expected['consecutive_interrupted_responses'] = 0;
        }

        if (in_array('files-pull', $scopes, true)) {
            $expected['local_followed_symlinks_root_fingerprint'] = null;
            $expected['filter'] = 'none';
            $expected['files_pull_only_fingerprint'] = null;
            $expected['files_pull_summary'] = (
                new \FilesPullSummaryState()
            )->to_array();
            $expected['diff'] = (
                new \FileDiffProgressState()
            )->to_array();
            $expected['fetch'] = (
                new \FetchListProgressState()
            )->to_array();
            $expected['fetch_skipped'] = (
                new \FetchListProgressState()
            )->to_array();
            $expected['current_file'] = null;
            $expected['current_file_bytes'] = null;
            $expected['pull_pipeline']['skipped_pending'] = false;
        }
        if (in_array('files-index', $scopes, true)) {
            $expected['index'] = (
                new \RemoteFileIndexCursorState()
            )->to_array();
        }
        if (in_array('db-pull', $scopes, true)) {
            $expected['sql_bytes'] = null;
            $expected['sql_statements_counted'] = 0;
            $expected['sql_output'] = null;
            $expected['mysql_host'] = null;
            $expected['mysql_port'] = null;
            $expected['mysql_user'] = null;
            $expected['mysql_database'] = null;
        }
        if (in_array('db-index', $scopes, true)) {
            $expected['db_index'] = (
                new \DatabaseTableIndexState()
            )->to_array();
        }
        if (in_array('db-apply', $scopes, true)) {
            $expected['apply'] = (
                new \DatabaseApplyCommandState()
            )->to_array();
        }
        if (
            $resetPipeline !== null &&
            $before['pull_pipeline']['started_by_command'] ===
                $resetPipeline
        ) {
            $hasCompletedOnce =
                $before['pull_pipeline']['has_completed_once'];
            $expected['pull_pipeline'] = (
                new \PullPipelineCheckpointState()
            )->to_array();
            $expected['pull_pipeline']['has_completed_once'] =
                $hasCompletedOnce;
        }

        return $expected;
    }

    private function createArtifacts(): void
    {
        foreach ($this->artifactPaths() as $name => $path) {
            if ($name === 'runtime' || $name === 'database') {
                file_put_contents($path, "preserve\n");
                continue;
            }
            if ($name === 'local-index') {
                file_put_contents(
                    $path,
                    $this->indexRecord('/site/existing.txt'),
                );
                continue;
            }
            if ($name === 'local-index-wal') {
                file_put_contents($path, '');
                continue;
            }
            file_put_contents($path, "{$name}\n");
        }
        file_put_contents(
            $this->fileRoot . '/wp-content/downloaded.php',
            "<?php\n",
        );
    }

    /** @return array<string,string> */
    private function artifactPaths(): array
    {
        return [
            'local-index' =>
                $this->stateDirectory . '/pull/local-index.jsonl',
            'local-index-wal' =>
                $this->stateDirectory . '/pull/local-index.wal',
            'local-index-new' =>
                $this->stateDirectory . '/pull/local-index.jsonl.new',
            'local-index-swap' =>
                $this->stateDirectory . '/pull/local-index.jsonl.swap',
            'remote-index' =>
                $this->stateDirectory . '/pull/remote-index.jsonl',
            'remote-index-sorted' =>
                $this->stateDirectory . '/pull/remote-index.jsonl.sorted',
            'remote-index-keyed' =>
                $this->stateDirectory . '/pull/remote-index.jsonl.keyed',
            'remote-index-keyed-sorted' =>
                $this->stateDirectory .
                '/pull/remote-index.jsonl.keyed.sorted',
            'remote-index-merge-sorted' =>
                $this->stateDirectory .
                '/pull/remote-index.jsonl.merge-sorted',
            'merge-chunk' =>
                $this->stateDirectory . '/pull/merge-chunk-stale',
            'fetch-list' =>
                $this->stateDirectory . '/pull/fetch-list.jsonl',
            'skipped-fetch-list' =>
                $this->stateDirectory .
                '/pull/skipped-fetch-list.jsonl',
            'volatile-files' =>
                $this->stateDirectory . '/pull/volatile-files.json',
            'fetch-batch' => $this->root . '/batches/fetch.jsonl',
            'fetch-skipped-batch' =>
                $this->root . '/batches/fetch-skipped.jsonl',
            'sql-buffer' =>
                $this->stateDirectory . '/pull/sql-buffer',
            'sql-stats' =>
                $this->stateDirectory . '/pull/sql-stats.json',
            'sql' => $this->stateDirectory . '/db.sql',
            'db-index' =>
                $this->stateDirectory . '/db-tables.jsonl',
            'domains' =>
                $this->stateDirectory . '/pull/domains.json',
            'database' => $this->root . '/database.sqlite',
            'runtime' => $this->root . '/runtime/runtime.php',
        ];
    }

    /**
     * @param string[] $scopes
     */
    private function assertArtifactOwnership(array $scopes): void
    {
        $removed = [];
        if (in_array('files-pull', $scopes, true)) {
            $removed = array_merge($removed, [
                'local-index-wal',
                'local-index-new',
                'local-index-swap',
                'fetch-list',
                'skipped-fetch-list',
                'volatile-files',
                'fetch-batch',
                'fetch-skipped-batch',
            ]);
        }
        if (in_array('files-index', $scopes, true)) {
            $removed = array_merge($removed, [
                'remote-index',
                'remote-index-sorted',
                'remote-index-keyed',
                'remote-index-keyed-sorted',
                'remote-index-merge-sorted',
                'merge-chunk',
            ]);
        }
        if (in_array('db-pull', $scopes, true)) {
            $removed = array_merge($removed, [
                'sql-buffer',
                'sql-stats',
                'sql',
                'domains',
            ]);
        }
        if (in_array('db-index', $scopes, true)) {
            $removed[] = 'db-index';
        }

        foreach ($this->artifactPaths() as $name => $path) {
            if (in_array($name, $removed, true)) {
                $this->assertFileDoesNotExist($path, $name);
            } else {
                $this->assertFileExists($path, $name);
            }
        }
    }

    /** @return array<string,string> */
    private function artifactSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->artifactPaths() as $name => $path) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $snapshot[$name] = $contents;
        }
        return $snapshot;
    }

    private function indexRecord(string $path): string
    {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => 42,
            'size' => 5,
            'type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<string,mixed> $options
     */
    private function runCommand(
        \ImportClient $client,
        array $options
    ): void {
        $processLock = new \ReprintProcessLock($this->stateDirectory);
        ob_start();
        try {
            $client->run($options, $processLock);
        } finally {
            ob_end_clean();
            $processLock->close();
        }
    }

    private function client(): \ImportClient
    {
        return new \ImportClient(
            'https://example.com/?site-export-api',
            $this->stateDirectory,
            $this->fileRoot,
        );
    }

    /** @return array<string,mixed> */
    private function loadPersistedState(\ImportClient $client): array
    {
        $method = (
            new \ReflectionClass(\ImportClient::class)
        )->getMethod('load_state');
        return $method->invoke($client)->to_array();
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
