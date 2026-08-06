<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

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
        $state->diff->next_remote_index_byte_offset = 123;
        $state->diff->last_consumed_remote_index_entry_path = '/wp-content/index.php';
        $state->diff->last_processed_next_remote_index_entry_path = '/wp-content/themes/twentytwenty/style.css';
        $state->sql_statements_counted = 99;

        $array = $state->to_array();

        $this->assertSame('db-pull', $array['active_resumable_command']['command_name']);
        $this->assertSame('complete', $array['active_resumable_command']['completion_state']);
        $this->assertSame('pull', $array['pull_pipeline']['started_by_command']);
        $this->assertSame(123, $array['diff']['next_remote_index_byte_offset']);
        $this->assertSame('/wp-content/index.php', $array['diff']['last_consumed_remote_index_entry_path']);
        $this->assertSame(
            '/wp-content/themes/twentytwenty/style.css',
            $array['diff']['last_processed_next_remote_index_entry_path']
        );
        $this->assertSame(99, $array['sql_statements_counted']);
    }

    public function testGetAppliesDefaultsOverVerbatimPreflight(): void
    {
        $state = new \PullState();

        // Preflight has not run yet.
        $this->assertSame(4 * 1024 * 1024, $state->get('preflight.limits.max_request_bytes'));

        // Server reported a usable limit.
        $state->set_preflight_record(['data' => ['limits' => ['max_request_bytes' => 8 * 1024 * 1024]]]);
        $this->assertSame(8 * 1024 * 1024, $state->get('preflight.limits.max_request_bytes'));

        // Server reported 0 (host without a request-size limit): the effective
        // value falls back to the default while the record keeps the 0.
        $state->set_preflight_record(['data' => ['limits' => ['max_request_bytes' => 0]]]);
        $this->assertSame(4 * 1024 * 1024, $state->get('preflight.limits.max_request_bytes'));
        $this->assertSame(0, $state->preflight_record()['data']['limits']['max_request_bytes']);

        // String and array paths default the same way; null-default paths
        // report absence as null for the caller to handle.
        $state = new \PullState();
        $this->assertSame('wp_', $state->get('preflight.database.wp.table_prefix'));
        $this->assertSame([], $state->get('preflight.wp_detect.roots'));
        $this->assertNull($state->get('preflight.database.wp.paths_urls.abspath'));

        // A value of the wrong type is as unusable as a missing one.
        $state->set_preflight_record(['data' => ['database' => ['wp' => ['table_prefix' => 123]]]]);
        $this->assertSame('wp_', $state->get('preflight.database.wp.table_prefix'));
    }

    public function testGetRejectsUnregisteredConfigPaths(): void
    {
        $state = new \PullState();

        $this->expectException(\UnexpectedValueException::class);
        $state->get('preflight.limits.max_request_bytez');
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

    public function testStateRejectsDiffFieldsFromThePreviousSchema(): void
    {
        $data = (new \PullState())->to_array();
        unset($data['diff']['last_consumed_remote_index_entry_path']);
        $data['diff']['last_consumed_local_index_entry_path'] =
            '/wp-content/index.php';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'missing last_consumed_remote_index_entry_path; unexpected last_consumed_local_index_entry_path'
        );

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
