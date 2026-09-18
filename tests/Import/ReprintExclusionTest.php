<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class ReprintExclusionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reprint-exclusion-' . uniqid();
        mkdir($this->directory . '/files', 0755, true);
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod(\ImportClient::class, 'rmdir_recursive'))->invoke(null, $this->directory);
    }

    public function testExcludesActualPluginPathsWithoutExcludingSimilarNames(): void
    {
        $client = $this->client();
        $client->get_state()->exclude_reprint = true;
        $client->prepare_files_pull_options([], false);
        foreach (['/srv/custom-plugins/renamed', '/opt/packages/reprint'] as $directory) {
            $this->assertFalse($this->call($client, 'is_selected_for_pulling', [$directory . '/secret.php', true, 'file']));
            $this->assertFalse($this->call($client, 'is_selected_for_pulling', [$directory, true, 'link']));
            $this->assertTrue($this->call($client, 'is_selected_for_pulling', [$directory . '-other/index.php', true, 'file']));
        }
    }

    public function testIncludingReprintKeepsItsFiles(): void
    {
        $client = $this->client();
        $client->get_state()->exclude_reprint = false;
        $client->prepare_files_pull_options([], false);
        $this->assertTrue($this->call($client, 'is_selected_for_pulling', ['/srv/custom-plugins/renamed/secret.php', true, 'file']));
    }

    public function testKeepsReprintInTheDownloadedSql(): void
    {
        $client = $this->client();
        $client->get_state()->exclude_reprint = true;
        $this->call($client, 'initialize_tuner', [[]]);
        $params = $this->call($client, 'get_tuned_params', ['sql_chunk']);
        $this->assertArrayNotHasKey('exclude_reprint', $params);
        $this->assertSame('_edit_lock', base64_decode($params['skip_rows'][0]['value_base64']));
        $this->assertCount(1, $params['skip_rows']);
    }

    public function testOlderSourceCannotSilentlyIgnoreExclusion(): void
    {
        $client = $this->client();
        $client->get_state()->set_preflight_record(['data' => ['path_format' => 'unix']]);
        $client->get_state()->exclude_reprint = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Update the source Reprint Server');
        $client->prepare_files_pull_options([], false);
    }

    public function testStandaloneServerReportsNoPlugin(): void
    {
        $client = $this->client();
        $client->get_state()->set_preflight_record(['data' => ['path_format' => 'unix', 'reprint_plugin' => null]]);
        $client->get_state()->exclude_reprint = true;
        $client->prepare_files_pull_options([], false);
        $this->assertTrue($this->call($client, 'is_selected_for_pulling', ['/srv/custom-plugins/renamed/index.php', true, 'file']));
    }

    public function testSavedChoiceSurvivesRoundTripAndCommandReset(): void
    {
        $client = $this->client();
        $client->get_state()->exclude_reprint = true;
        $this->assertTrue(\PullState::from_array($client->get_state()->to_array())->exclude_reprint);
        $this->call($client, 'reset_state');
        $this->assertTrue($client->get_state()->exclude_reprint);
    }

    public function testOldStateKeepsItsPreviousSelection(): void
    {
        $data = (new \PullState())->to_array();
        unset($data['exclude_reprint']);
        $this->assertFalse(\PullState::from_array($data)->exclude_reprint);
    }

    public function testNewStateIncludesReprintByDefault(): void
    {
        $this->assertFalse((new \PullState())->exclude_reprint);
    }

    public function testCannotChangeSelectionBetweenPipelineStages(): void
    {
        $client = $this->client();
        $state = $client->get_state();
        $state->exclude_reprint = false;
        $state->active_resumable_command->command_name = 'files-pull';
        $state->active_resumable_command->completion_state = 'complete';
        $state->pull_pipeline->started_by_command = 'pull';
        $state->pull_pipeline->stage_sequence = ['files-pull', 'db-pull'];
        $state->pull_pipeline->last_completed_stage = 'files-pull';
        $this->call($client, 'save_state');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --exclude-reprint/--include-reprint while a pull is in progress');
        $client->run(['command' => 'db-pull', 'exclude_reprint' => true]);
    }

    public function testInvalidLibraryChoiceIsRejectedBeforeSavingState(): void
    {
        $client = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exclude_reprint must be a boolean; received string.');
        $client->run(['command' => 'pull', 'exclude_reprint' => 'true']);
    }

    public function testConflictingCliFlagsAreRejected(): void
    {
        $entry = dirname(__DIR__, 2) . '/packages/reprint-client/bin/reprint-client';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry)
            . ' pull https://source.test --exclude-reprint --include-reprint 2>&1', $output, $status);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('--exclude-reprint and --include-reprint cannot be combined.', implode("\n", $output));
    }

    private function client(): \ImportClient
    {
        $client = new \ImportClient('https://source.test/?reprint-api', $this->directory . '/state', $this->directory . '/files');
        $client->get_state()->set_preflight_record(['data' => [
            'path_format' => 'unix',
            'reprint_plugin' => [
                'paths_b64' => array_map('base64_encode', ['/srv/custom-plugins/renamed', '/opt/packages/reprint']),
            ],
            'database' => ['wp' => ['table_prefix' => 'custom_', 'paths_urls' => [
                'abspath' => '/srv/wordpress',
                'content_dir' => '/srv/content',
                'plugins_dir' => '/srv/custom-plugins',
            ]]],
        ]]);
        return $client;
    }

    private function call(\ImportClient $client, string $method, array $arguments = [])
    {
        return (new \ReflectionMethod($client, $method))->invoke($client, ...$arguments);
    }
}
