<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class PullStateTest extends TestCase
{
    public function testStateHydratesDocumentedNestedObjects(): void
    {
        $data = (new \PullState())->to_array();
        $data['active_resumable_command'] = [
            'command_name' => 'files-pull',
            'completion_state' => 'partial',
            'current_stage' => 'fetch',
            'remote_cursor' => 'cursor-1',
        ];
        $data['pull_pipeline'] = [
            'started_by_command' => 'pull',
            'stage_sequence' => ['preflight', 'files-pull'],
            'last_completed_stage' => 'preflight',
            'files_filter' => 'essential-files',
            'skipped_pending' => true,
            'has_completed_once' => false,
        ];
        $data['apply']['statements_executed'] = 12;
        $data['apply']['bytes_read'] = 34;
        $data['apply']['target_engine'] = 'sqlite';
        $state = \PullState::from_array($data);

        $this->assertSame('files-pull', $state->active_resumable_command->command_name);
        $this->assertSame('partial', $state->active_resumable_command->completion_state);
        $this->assertSame('preflight', $state->pull_pipeline->last_completed_stage);
        $this->assertSame(['preflight', 'files-pull'], $state->pull_pipeline->stage_sequence);
        $this->assertSame(12, $state->apply->statements_executed);
    }

    public function testStateRoundTripsToPersistedArraySchema(): void
    {
        $state = new \PullState();
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

    public function testStateObjectsDoNotExposeArrayOffsetMutation(): void
    {
        $state = new \PullState();

        $this->assertNotInstanceOf(\ArrayAccess::class, $state);
        $this->assertNotInstanceOf(\ArrayAccess::class, $state->active_resumable_command);
    }

    public function testStateRejectsAnIncompleteSchema(): void
    {
        $data = (new \PullState())->to_array();
        unset($data['webhost']);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('missing webhost');

        \PullState::from_array($data);
    }

    public function testStateRejectsUnexpectedFields(): void
    {
        $data = (new \PullState())->to_array();
        $data['status'] = 'complete';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unexpected status');

        \PullState::from_array($data);
    }

    public function testStatePathRejectsInvalidBase64(): void
    {
        $decode = $this->statePathDecoder();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('contains invalid base64');

        $decode('base64:not base64');
    }

    public function testStatePathRejectsAnUnprefixedString(): void
    {
        $decode = $this->statePathDecoder();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('missing the base64: encoding prefix');

        $decode('/srv/htdocs/wp-config.php');
    }

    private function statePathDecoder(): \Closure
    {
        $client = new \ImportClient(
            'https://source.example/',
            sys_get_temp_dir(),
            sys_get_temp_dir(),
        );
        $method = (new \ReflectionClass($client))->getMethod('decode_state_path_value');
        $method->setAccessible(true);
        return $method->getClosure($client);
    }
}
