<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the following line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\State\FilesPullOwnershipState;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/state/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-files-pull-ownership-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-change-scope.php';

final class FilesPullOwnershipStateTest extends TestCase
{
    private const FIRST_SELECTION =
        '1111111111111111111111111111111111111111111111111111111111111111';
    private const SECOND_SELECTION =
        '2222222222222222222222222222222222222222222222222222222222222222';
    private const THIRD_SELECTION =
        '3333333333333333333333333333333333333333333333333333333333333333';
    private const FIRST_SNAPSHOT =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SECOND_SNAPSHOT =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const THIRD_SNAPSHOT =
        'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const FOURTH_SNAPSHOT =
        'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testCreatesRemoteScopeFromActivePriorAndProtectedOwnership(): void
    {
        $ownership_directory = sys_get_temp_dir()
            . "/files-pull-ownership-state-\n-"
            . bin2hex(random_bytes(6));
        mkdir($ownership_directory . '/snapshots', 0700, true);
        foreach (
            [
                self::FIRST_SNAPSHOT,
                self::SECOND_SNAPSHOT,
                self::THIRD_SNAPSHOT,
                self::FOURTH_SNAPSHOT,
            ] as $snapshot_id
        ) {
            file_put_contents(
                "{$ownership_directory}/snapshots/{$snapshot_id}.paths.jsonl",
                ''
            );
            file_put_contents(
                "{$ownership_directory}/snapshots/{$snapshot_id}.lookup",
                ''
            );
        }
        $state = FilesPullOwnershipState::from_array([
            'committed_snapshot_ids_by_selection_fingerprint' => [
                self::FIRST_SELECTION => [self::FIRST_SNAPSHOT],
                self::SECOND_SELECTION => [
                    self::SECOND_SNAPSHOT,
                    self::THIRD_SNAPSHOT,
                ],
                self::THIRD_SELECTION => [self::SECOND_SNAPSHOT],
            ],
            'active_snapshot_id' => self::FOURTH_SNAPSHOT,
            'processor_cursor' => null,
            'snapshot_ids_pending_removal' => [],
        ]);
        $excluded_remote_absolute_path_roots = [
            "/z-\xFF",
            "/a\npath",
            "/z-\xFF",
        ];

        try {
            $scope = $state->create_remote_change_scope(
                $ownership_directory,
                self::FIRST_SELECTION,
                $excluded_remote_absolute_path_roots,
                true
            );
            $this->assertSame([
                'index_path_coordinates' => 'remote_absolute',
                'ownership_directory_b64' => base64_encode(
                    $ownership_directory
                ),
                'current_snapshot_id' => self::FOURTH_SNAPSHOT,
                'prior_snapshot_ids' => [self::FIRST_SNAPSHOT],
                'protected_snapshot_ids' => [
                    self::SECOND_SNAPSHOT,
                    self::THIRD_SNAPSHOT,
                ],
                'excluded_remote_absolute_path_roots_b64' => [
                    base64_encode("/a\npath"),
                    base64_encode("/z-\xFF"),
                ],
                'include_caches' => true,
            ], $scope->get_config());
            $scope->close();
        } finally {
            foreach (glob($ownership_directory . '/snapshots/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($ownership_directory . '/snapshots');
            rmdir($ownership_directory);
        }
    }

    public function testRemoteScopeRequiresAnActiveSnapshot(): void
    {
        $state = new FilesPullOwnershipState();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no active snapshot');
        $state->create_remote_change_scope(
            sys_get_temp_dir(),
            self::FIRST_SELECTION,
            [],
            false
        );
    }

    public function testSuccessReplacesOnlyItsSelectionAndQueuesUnreferencedSnapshots(): void
    {
        $state = FilesPullOwnershipState::from_array([
            'committed_snapshot_ids_by_selection_fingerprint' => [
                self::FIRST_SELECTION => [
                    self::FIRST_SNAPSHOT,
                    self::SECOND_SNAPSHOT,
                ],
                self::SECOND_SELECTION => [self::FIRST_SNAPSHOT],
            ],
            'active_snapshot_id' => self::THIRD_SNAPSHOT,
            'processor_cursor' => null,
            'snapshot_ids_pending_removal' => [],
        ]);

        $state->commit_active_snapshot(self::FIRST_SELECTION);

        $this->assertSame(
            [self::THIRD_SNAPSHOT],
            $state->committed_snapshot_ids_by_selection_fingerprint[
                self::FIRST_SELECTION
            ]
        );
        $this->assertSame(
            [self::FIRST_SNAPSHOT],
            $state->committed_snapshot_ids_by_selection_fingerprint[
                self::SECOND_SELECTION
            ]
        );
        $this->assertSame(
            [self::SECOND_SNAPSHOT],
            $state->snapshot_ids_pending_removal
        );
        $this->assertNull($state->active_snapshot_id);
    }

    public function testAbortBeforeDiffDiscardsCandidate(): void
    {
        $state = $this->stateWithActiveSnapshot();

        $state->abort_active_snapshot(self::FIRST_SELECTION, false, null);

        $this->assertSame(
            [self::FIRST_SNAPSHOT],
            $state->committed_snapshot_ids_by_selection_fingerprint[
                self::FIRST_SELECTION
            ]
        );
        $this->assertSame(
            [self::SECOND_SNAPSHOT],
            $state->snapshot_ids_pending_removal
        );
    }

    public function testAbortAtDiffRetainsCandidateBesideCommittedOwnership(): void
    {
        $state = $this->stateWithActiveSnapshot();

        $state->abort_active_snapshot(self::FIRST_SELECTION, true, null);

        $this->assertSame(
            [self::FIRST_SNAPSHOT, self::SECOND_SNAPSHOT],
            $state->committed_snapshot_ids_by_selection_fingerprint[
                self::FIRST_SELECTION
            ]
        );
        $this->assertSame([], $state->snapshot_ids_pending_removal);
    }

    public function testIncompleteProcessorHasNoActiveSnapshot(): void
    {
        $state = new FilesPullOwnershipState();
        $cursor = \FilesPullOwnershipProcessor::initial_cursor();
        $cursor['phase'] = 'complete';
        $cursor['snapshot_id'] = self::FIRST_SNAPSHOT;
        $cursor['next_work_file_cleanup_index'] = 2;

        $state->processor_cursor = $cursor;

        $this->assertSame($cursor, $state->processor_cursor);
        $this->assertNull($state->active_snapshot_id);
        $state->complete_processor(self::FIRST_SNAPSHOT);
        $this->assertNull($state->processor_cursor);
        $this->assertSame(self::FIRST_SNAPSHOT, $state->active_snapshot_id);
    }

    public function testAbortQueuesAllocatedProcessorIdBeforeClearingCursor(): void
    {
        $state = new FilesPullOwnershipState();
        $cursor = \FilesPullOwnershipProcessor::initial_cursor();
        $cursor['phase'] = 'paths_published';
        $cursor['snapshot_id'] = self::SECOND_SNAPSHOT;
        $state->processor_cursor = $cursor;

        $state->abort_active_snapshot(
            self::FIRST_SELECTION,
            false,
            \FilesPullOwnershipProcessor::snapshot_id_from_cursor($cursor)
        );

        $this->assertNull($state->processor_cursor);
        $this->assertNull($state->active_snapshot_id);
        $this->assertSame(
            [self::SECOND_SNAPSHOT],
            $state->snapshot_ids_pending_removal
        );
    }

    public function testConfirmsOnlyTheNextPendingSnapshotRemoval(): void
    {
        $state = FilesPullOwnershipState::from_array([
            'committed_snapshot_ids_by_selection_fingerprint' => [],
            'active_snapshot_id' => null,
            'processor_cursor' => null,
            'snapshot_ids_pending_removal' => [
                self::FIRST_SNAPSHOT,
                self::SECOND_SNAPSHOT,
            ],
        ]);

        $this->assertSame(
            self::SECOND_SNAPSHOT,
            $state->next_snapshot_id_pending_removal()
        );
        $state->confirm_snapshot_removed(self::SECOND_SNAPSHOT);
        $this->assertSame(
            self::FIRST_SNAPSHOT,
            $state->next_snapshot_id_pending_removal()
        );
        $state->confirm_snapshot_removed(self::FIRST_SNAPSHOT);
        $this->assertNull($state->next_snapshot_id_pending_removal());
    }

    /** @dataProvider invalidStateProvider */
    public function testRejectsInvalidPersistentState(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);
        FilesPullOwnershipState::from_array($data);
    }

    public static function invalidStateProvider(): array
    {
        $valid = [
            'committed_snapshot_ids_by_selection_fingerprint' => [],
            'active_snapshot_id' => null,
            'processor_cursor' => null,
            'snapshot_ids_pending_removal' => [],
        ];
        $unsortedSnapshots = $valid;
        $unsortedSnapshots[
            'committed_snapshot_ids_by_selection_fingerprint'
        ] = [
            self::FIRST_SELECTION => [
                self::SECOND_SNAPSHOT,
                self::FIRST_SNAPSHOT,
            ],
        ];
        $activeAndCursor = $valid;
        $activeAndCursor['active_snapshot_id'] = self::FIRST_SNAPSHOT;
        $activeAndCursor['processor_cursor'] =
            \FilesPullOwnershipProcessor::initial_cursor();
        $referencedRemoval = $valid;
        $referencedRemoval[
            'committed_snapshot_ids_by_selection_fingerprint'
        ] = [self::FIRST_SELECTION => [self::FIRST_SNAPSHOT]];
        $referencedRemoval['snapshot_ids_pending_removal'] =
            [self::FIRST_SNAPSHOT];
        return [
            'invalid fingerprint' => [array_replace($valid, [
                'committed_snapshot_ids_by_selection_fingerprint' => [
                    'not-a-fingerprint' => [self::FIRST_SNAPSHOT],
                ],
            ])],
            'uppercase snapshot' => [array_replace($valid, [
                'active_snapshot_id' => strtoupper(self::FIRST_SNAPSHOT),
            ])],
            'unsorted committed list' => [$unsortedSnapshots],
            'active and processor' => [$activeAndCursor],
            'referenced pending removal' => [$referencedRemoval],
            'unexpected key' => [array_replace($valid, ['old' => true])],
        ];
    }

    private function stateWithActiveSnapshot(): FilesPullOwnershipState
    {
        return FilesPullOwnershipState::from_array([
            'committed_snapshot_ids_by_selection_fingerprint' => [
                self::FIRST_SELECTION => [self::FIRST_SNAPSHOT],
            ],
            'active_snapshot_id' => self::SECOND_SNAPSHOT,
            'processor_cursor' => null,
            'snapshot_ids_pending_removal' => [],
        ]);
    }
}
