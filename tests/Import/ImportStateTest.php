<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class ImportStateTest extends TestCase
{
    public function testStateHydratesDocumentedNestedObjects(): void
    {
        $state = \ImportState::from_array([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'fetch',
                'remote_cursor' => 'cursor-1',
            ],
            'pull_pipeline' => [
                'started_by_command' => 'pull',
                'stage_sequence' => ['preflight', 'files-pull'],
                'last_completed_stage' => 'preflight',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
                'has_completed_once' => false,
            ],
            'apply' => [
                'statements_executed' => 12,
                'bytes_read' => 34,
                'target_engine' => 'sqlite',
            ],
        ]);

        $this->assertSame('files-pull', $state->active_resumable_command->command_name);
        $this->assertSame('partial', $state->active_resumable_command->completion_state);
        $this->assertSame('preflight', $state->pull_pipeline->last_completed_stage);
        $this->assertSame(['preflight', 'files-pull'], $state->pull_pipeline->stage_sequence);
        $this->assertSame(12, $state->apply->statements_executed);
    }

    public function testStateRoundTripsToPersistedArraySchema(): void
    {
        $state = \ImportState::from_array([]);
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'complete';
        $state->pull_pipeline->started_by_command = 'pull';
        $state->sql_statements_counted = 99;

        $array = $state->to_array();

        $this->assertSame('db-pull', $array['active_resumable_command']['command_name']);
        $this->assertSame('complete', $array['active_resumable_command']['completion_state']);
        $this->assertSame('pull', $array['pull_pipeline']['started_by_command']);
        $this->assertSame(99, $array['sql_statements_counted']);
    }

    public function testFileDiffStateRoundTripsTheConflictPolicyBinding(): void
    {
        $state = \ImportState::from_array([
            'diff' => [
                'conflict_policy' => 'our-wins',
                'conflict_policy_locked' => true,
                'maintain_previous_local_index' => true,
            ],
        ]);

        $this->assertSame('our-wins', $state->diff->conflict_policy);
        $this->assertTrue($state->diff->conflict_policy_locked);
        $this->assertTrue(
            $state->diff->maintain_previous_local_index
        );
        $array = $state->to_array();
        $this->assertSame(
            'our-wins',
            $array['diff']['conflict_policy'] ?? null
        );
        $this->assertTrue(
            $array['diff']['conflict_policy_locked'] ?? false
        );
        $this->assertTrue(
            $array['diff']['maintain_previous_local_index'] ?? false
        );
    }

    public function testFileDiffStateRoundTripsTheUnreadConflictOffset(): void
    {
        $state = \ImportState::from_array([
            'diff' => [
                'local_offset' => 31,
                'conflict_offset' => 37,
                'retained_local_subtree_top_offset' => 41,
                'retained_local_subtree_stack_offset' => 73,
            ],
        ]);

        $this->assertSame(31, $state->diff->local_offset);
        $this->assertSame(37, $state->diff->conflict_offset);
        $this->assertSame(41, $state->diff->retained_local_subtree_top_offset);
        $this->assertSame(73, $state->diff->retained_local_subtree_stack_offset);
        $this->assertSame(
            37,
            $state->to_array()['diff']['conflict_offset'] ?? null
        );
        $this->assertSame(
            31,
            $state->to_array()['diff']['local_offset'] ?? null
        );
    }

    public function testFileDiffStateDistinguishesALegacyCursorFromByteZero(): void
    {
        $legacy = \FileDiffProgressState::from_array([]);
        $atStart = \FileDiffProgressState::from_array([
            'local_offset' => 0,
        ]);

        $this->assertNull($legacy->local_offset);
        $this->assertNull($legacy->to_array()['local_offset']);
        $this->assertSame(0, $atStart->local_offset);
        $this->assertSame(0, $atStart->to_array()['local_offset']);
    }

    public function testFetchStateRoundTripsPrivateStagingBoundaries(): void
    {
        $stagedFile = [
            'remote_path_b64' =>
                base64_encode('/var/www/html/file.txt'),
            'destination_path_b64' =>
                base64_encode('/tmp/site/file.txt'),
            'staging_path_b64' =>
                base64_encode('/tmp/site/.reprint-1234567890abcdef.part'),
            'staging_dev' => null,
            'staging_ino' => null,
            'staging_bytes' => 0,
            'install_mode' => 0644,
            'remote_ctime' => 41,
            'remote_size' => 43,
            'remote_file_changed' => false,
            'discard_started' => false,
            'validate_local_state' => true,
        ];
        $pendingInstall = array_merge($stagedFile, [
            'staging_dev' => 17,
            'staging_ino' => 29,
            'staging_bytes' => 31,
            'cursor' => 'file-complete',
            'destination_removal' => [
                'quarantine_path_b64' =>
                    base64_encode(
                        '/tmp/site/.reprint-abcdef0123456789.remove'
                    ),
                'directory_dev' => 17,
                'directory_ino' => 23,
                'top_offset' => 7,
                'stack_offset' => 19,
            ],
            'installed_ctime' => null,
            'planned_local_state_offset' => 47,
        ]);
        $state = \ImportState::from_array([
            'fetch' => [
                'staged_file' => $stagedFile,
                'retained_local_subtree_top_offset' => 53,
                'retained_local_subtree_stack_offset' => 89,
            ],
            'fetch_skipped' => [
                'pending_file_install' => $pendingInstall,
            ],
        ]);

        $this->assertSame($stagedFile, $state->fetch->staged_file);
        $this->assertSame(
            53,
            $state->fetch->retained_local_subtree_top_offset
        );
        $this->assertSame(
            89,
            $state->fetch->retained_local_subtree_stack_offset
        );
        $this->assertSame(
            $pendingInstall,
            $state->fetch_skipped->pending_file_install
        );
        $array = $state->to_array();
        $this->assertSame($stagedFile, $array['fetch']['staged_file']);
        $this->assertSame(
            $pendingInstall,
            $array['fetch_skipped']['pending_file_install']
        );
    }

    public function testFileDiffStateRoundTripsAPendingLocalAction(): void
    {
        $path = "/var/www/html/delete-\xff.txt";
        $action = [
            'kind' => 'delete_path',
            'path_b64' => base64_encode($path),
            'accepted_local_state' => [
                'type' => 'file',
                'ctime' => 41,
                'size' => 17,
            ],
        ];
        $state = \ImportState::from_array([
            'diff' => [
                'pending_local_action' => $action,
            ],
        ]);

        $this->assertSame($action, $state->diff->pending_local_action);
        $this->assertSame(
            $action,
            $state->to_array()['diff']['pending_local_action'] ?? null
        );
    }

    public function testFileDiffStateRejectsANoncanonicalPendingActionPath(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('not canonical base64');

        \ImportState::from_array([
            'diff' => [
                'pending_local_action' => [
                    'kind' => 'delete_path',
                    'path_b64' => 'YQ',
                    'accepted_local_state' => null,
                ],
            ],
        ]);
    }

    public function testFileDiffStateRejectsAnIncompletePendingActionState(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid fields');

        \ImportState::from_array([
            'diff' => [
                'pending_local_action' => [
                    'kind' => 'remove_empty_directory',
                    'path_b64' => base64_encode('/var/www/html/empty'),
                    'accepted_local_state' => [
                        'type' => 'dir',
                        'ctime' => 41,
                        'size' => 0,
                    ],
                ],
            ],
        ]);
    }

    public function testStateRoundTripMatchesDefaultStateSchema(): void
    {
        $client = new \ImportClient(
            'http://example.invalid',
            sys_get_temp_dir() . '/reprint-import-state-test-state',
            sys_get_temp_dir() . '/reprint-import-state-test-fs',
        );
        $default_state = $this->defaultStateFor($client);

        $this->assertSame($default_state, \ImportState::from_array($default_state)->to_array());
    }

    public function testStateObjectsDoNotExposeArrayOffsetMutation(): void
    {
        $state = \ImportState::from_array([]);

        $this->assertNotInstanceOf(\ArrayAccess::class, $state);
        $this->assertNotInstanceOf(\ArrayAccess::class, $state->active_resumable_command);
    }

    private function defaultStateFor(\ImportClient $client): array
    {
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('default_state');
        $method->setAccessible(true);
        return $method->invoke($client);
    }
}
