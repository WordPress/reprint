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
