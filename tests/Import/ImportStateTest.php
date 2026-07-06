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

    /**
     * The staged-pull resume path checkpoints the in-flight file's ownership
     * so a file that straddles a request boundary keeps its preserve-local
     * override at apply time. That only works if the flag survives the
     * state round-trip.
     */
    public function testCurrentFileOwnedRoundTrips(): void
    {
        foreach ([true, false, null] as $owned) {
            $state = \ImportState::from_array([]);
            $state->current_file = 'wp-content/plugins/a/a.php';
            $state->current_file_bytes = 4096;
            $state->current_file_owned = $owned;

            $restored = \ImportState::from_array($state->to_array());

            $this->assertSame($owned, $restored->current_file_owned, var_export($owned, true));
            $this->assertSame('wp-content/plugins/a/a.php', $restored->current_file);
            $this->assertSame(4096, $restored->current_file_bytes);
        }
    }

    /** State written before this field existed reads as null (owned=false). */
    public function testMissingCurrentFileOwnedDefaultsToNull(): void
    {
        $restored = \ImportState::from_array(['current_file' => 'x', 'current_file_bytes' => 1]);

        $this->assertNull($restored->current_file_owned);
    }

    /**
     * The path style persists like the sync flags so a resumed transfer keeps
     * the representation it started with (absolute vs relative).
     */
    public function testIndexPathStyleRoundTrips(): void
    {
        foreach (['absolute', 'relative', null] as $style) {
            $state = \ImportState::from_array([]);
            $state->index_path_style = $style;

            $restored = \ImportState::from_array($state->to_array());

            $this->assertSame($style, $restored->index_path_style, var_export($style, true));
        }
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
