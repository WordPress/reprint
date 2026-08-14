<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- PHPUnit fixture and test use the repository test namespace and style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Records intent checkpoints without replacing production option handling. */
final class FilesPullIntentFakeClient extends \ImportClient
{
    public ?string $intent_at_preflight = null;
    public ?string $intent_at_files_pull = null;
    public int $preflight_runs = 0;
    public int $files_pull_runs = 0;
    public int $bounded_mirror_steps_remaining = 0;
    public int $alternating_mirror_steps_remaining = 0;
    public int $stalled_mirror_steps_remaining = 0;
    public bool $stop_at_preflight = false;

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    public function output_progress(array $data, bool $force = false): void
    {
    }

    public function write_progress_file(?string $error = null): void
    {
    }

    public function run_preflight(): void
    {
        ++$this->preflight_runs;
        $this->intent_at_preflight = $this->get_state()->files_pull_intent;
        if ($this->stop_at_preflight) {
            throw new \RuntimeException('Stop at preflight.');
        }
        $this->get_state()->set_preflight_record([
            'http_code' => 200,
            'data' => ['ok' => true],
        ]);
        $this->get_state()->remote_protocol_version = PULL_PROTOCOL_VERSION;
        $this->get_state()->active_resumable_command->completion_state =
            'complete';
        $this->save_state();
    }

    public function run_files_pull(): void
    {
        ++$this->files_pull_runs;
        $this->intent_at_files_pull = $this->get_state()->files_pull_intent;
        $this->get_state()->active_resumable_command->command_name =
            'files-pull';
        if ($this->alternating_mirror_steps_remaining > 0) {
            --$this->alternating_mirror_steps_remaining;
            $this->get_state()->active_resumable_command->completion_state =
                'partial';
            $this->get_state()->active_resumable_command->current_stage =
                'local_scope';
            $this->get_state()->files_pull_processor_cursor = [
                'step' => intdiv($this->files_pull_runs + 1, 2),
            ];
            $this->save_state();
            return;
        }
        if ($this->stalled_mirror_steps_remaining > 0) {
            --$this->stalled_mirror_steps_remaining;
            $this->get_state()->active_resumable_command->completion_state =
                'partial';
            $this->get_state()->active_resumable_command->current_stage =
                'local_scope';
            $this->get_state()->files_pull_processor_cursor = [
                'step' => 'stalled',
            ];
            $this->save_state();
            return;
        }
        if ($this->bounded_mirror_steps_remaining > 0) {
            --$this->bounded_mirror_steps_remaining;
            $this->get_state()->active_resumable_command->completion_state =
                'partial';
            $this->get_state()->active_resumable_command->current_stage =
                'local_scope';
            $this->get_state()->files_pull_processor_cursor = [
                'step' => $this->files_pull_runs,
            ];
            $this->save_state();
            return;
        }
        $this->get_state()->active_resumable_command->completion_state =
            'complete';
        $this->get_state()->active_resumable_command->current_stage = null;
        $this->get_state()->files_pull_processor_cursor = null;
        $this->save_state();
    }
}

final class FilesPullIntentTest extends TestCase
{
    private string $temporary_directory;
    private string $state_directory;
    private string $filesystem_root;
    private string $pull_state_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/files-pull-intent-'
            . bin2hex(random_bytes(6));
        $this->state_directory = $this->temporary_directory . '/state';
        $this->filesystem_root = $this->temporary_directory . '/filesystem';
        mkdir($this->state_directory, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
        $this->pull_state_file = $this->state_directory
            . '/remotes/'
            . md5('http://fake.invalid')
            . '/pull/state.json';
    }

    protected function tearDown(): void
    {
        $this->remove_directory($this->temporary_directory);
        parent::tearDown();
    }

    public function testNewHighLevelPullDefaultsToMirrorBeforePreflight(): void
    {
        $client = $this->new_client();
        $client->stop_at_preflight = true;

        try {
            $client->run([
                'command' => 'pull-files',
            ]);
            $this->fail('Expected the test preflight boundary.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stop at preflight.', $exception->getMessage());
        }

        $this->assertSame('mirror', $client->intent_at_preflight);
        $this->assertSame('mirror', $this->read_state()['files_pull_intent']);
        $this->assertSame(0, $client->files_pull_runs);
    }

    /** @dataProvider filesPullCommandProvider */
    public function testExplicitCatchUpReachesEveryFilesPullEntryPoint(
        string $command
    ): void {
        $client = $this->new_client();
        if ($command === 'pull') {
            $client->stop_at_preflight = true;
        }
        if ($command === 'files-pull') {
            $this->write_state([]);
        }

        try {
            $options = [
                'command' => $command,
                'intent' => 'catch-up',
            ];
            if ($command === 'pull') {
                $options['runtime'] = 'none';
            }
            $client->run($options);
        } catch (\RuntimeException $exception) {
            if ($command !== 'pull') {
                throw $exception;
            }
            $this->assertSame('Stop at preflight.', $exception->getMessage());
        }

        $observed_intent = $command === 'pull'
            ? $client->intent_at_preflight
            : $client->intent_at_files_pull;
        $this->assertSame('catch-up', $observed_intent);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    public static function filesPullCommandProvider(): array
    {
        return [
            'pull' => ['pull'],
            'pull-files' => ['pull-files'],
            'files-pull' => ['files-pull'],
        ];
    }

    public function testOmittedIntentRestoresTheActiveCatchUpLifecycle(): void
    {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'index',
            ],
        ]);
        $client = $this->new_client();

        $client->run(['command' => 'files-pull']);

        $this->assertSame('catch-up', $client->intent_at_files_pull);
    }

    public function testHighLevelRetryCarriesTheSavedCatchUpIntent(): void
    {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => 'preflight',
                'completion_state' => 'complete',
            ],
            'pull_pipeline' => [
                'started_by_command' => 'pull-files',
                'stage_sequence' => ['preflight', 'files-pull'],
                'last_completed_stage' => 'preflight',
            ],
        ]);
        $client = $this->new_client();

        $client->run(['command' => 'pull-files']);

        $this->assertSame('catch-up', $client->intent_at_files_pull);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    public function testBoundedMirrorProgressDoesNotConsumeTheRetryCeiling(): void
    {
        $client = $this->new_client();
        $client->bounded_mirror_steps_remaining = 1001;

        $client->run(['command' => 'pull-files']);

        $this->assertSame(1002, $client->files_pull_runs);
        $this->assertSame(
            'complete',
            $this->read_state()['active_resumable_command']['completion_state']
        );
    }

    public function testDurableMirrorProgressResetsTheRetryCeiling(): void
    {
        $client = $this->new_client();
        $client->alternating_mirror_steps_remaining = 2001;

        $client->run(['command' => 'pull-files']);

        $this->assertSame(2002, $client->files_pull_runs);
        $this->assertSame(
            'complete',
            $this->read_state()['active_resumable_command']['completion_state']
        );
    }

    public function testStalledMirrorPartialsStillConsumeTheRetryCeiling(): void
    {
        $client = $this->new_client();
        $client->stalled_mirror_steps_remaining = 1001;

        try {
            $client->run(['command' => 'pull-files']);
            $this->fail('Expected stalled partial retries to reach the ceiling.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'after 1000 retry attempts',
                $exception->getMessage()
            );
        }

        $this->assertSame(1001, $client->files_pull_runs);
    }

    public function testCompletedCatchUpRetainsItsIntentWhenOmitted(): void
    {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
                'current_stage' => null,
            ],
        ]);
        $client = $this->new_client();

        $client->run(['command' => 'files-pull']);

        $this->assertSame('catch-up', $client->intent_at_files_pull);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    public function testCompletedCatchUpRejectsExplicitIntentDrift(): void
    {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
                'current_stage' => null,
            ],
        ]);
        $client = $this->new_client();

        try {
            $client->run([
                'command' => 'files-pull',
                'intent' => 'mirror',
            ]);
            $this->fail('Expected completed intent drift to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --intent from catch-up to mirror',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    /** @dataProvider completedHighLevelPipelineProvider */
    public function testCompletedHighLevelCatchUpRetainsItsOmittedIntent(
        string $command,
        array $options,
        array $stages
    ): void {
        $this->write_completed_high_level_catch_up(
            $command,
            $stages
        );
        $client = $this->new_client();
        $client->stop_at_preflight = true;

        try {
            $client->run($options);
            $this->fail('Expected the test preflight boundary.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stop at preflight.', $exception->getMessage());
        }

        $this->assertSame('catch-up', $client->intent_at_preflight);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    /** @dataProvider completedHighLevelPipelineProvider */
    public function testCompletedHighLevelCatchUpRejectsIntentDriftBeforeWrite(
        string $command,
        array $options,
        array $stages
    ): void {
        $this->write_completed_high_level_catch_up(
            $command,
            $stages
        );
        $state_before = file_get_contents($this->pull_state_file);
        $this->assertIsString($state_before);
        $client = $this->new_client();
        $options['intent'] = 'mirror';

        try {
            $client->run($options);
            $this->fail('Expected completed intent drift to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --intent from catch-up to mirror',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $client->preflight_runs);
        $this->assertSame(
            $state_before,
            file_get_contents($this->pull_state_file)
        );
    }

    public static function completedHighLevelPipelineProvider(): array
    {
        return [
            'pull-files' => [
                'pull-files',
                ['command' => 'pull-files'],
                ['preflight', 'files-pull'],
            ],
            'pull' => [
                'pull',
                ['command' => 'pull', 'runtime' => 'none'],
                ['preflight', 'files-pull', 'db-pull', 'db-apply'],
            ],
        ];
    }

    public function testExplicitIntentDriftIsRejectedBeforeFilesPullRuns(): void
    {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'index',
            ],
        ]);
        $client = $this->new_client();

        try {
            $client->run([
                'command' => 'files-pull',
                'intent' => 'mirror',
            ]);
            $this->fail('Expected intent drift to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Cannot change --intent from catch-up to mirror',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $client->files_pull_runs);
        $this->assertSame('catch-up', $this->read_state()['files_pull_intent']);
    }

    public function testProgrammaticInvalidIntentIsRejectedBeforeStateWrite(): void
    {
        $client = $this->new_client();

        try {
            $client->run([
                'command' => 'pull-files',
                'intent' => ['mirror'],
            ]);
            $this->fail('Expected an invalid programmatic intent.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'Invalid --intent value: array. Valid values: mirror, catch-up',
                $exception->getMessage()
            );
        }

        $this->assertFileDoesNotExist($this->pull_state_file);
        $this->assertSame(0, $client->preflight_runs);
    }

    public function testMirrorRejectsPreserveLocalBeforePreflight(): void
    {
        $client = $this->new_client();

        try {
            $client->run([
                'command' => 'pull-files',
                'intent' => 'mirror',
                'fs_root_nonempty_behavior' => 'preserve-local',
            ]);
            $this->fail('Expected mirror and preserve-local to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Use --intent=catch-up to preserve existing local content.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $client->preflight_runs);
        $this->assertFileDoesNotExist($this->pull_state_file);
    }

    public function testDirectLifecycleRunnerAlsoRejectsMirrorPreserveLocal(): void
    {
        $this->write_state([
            'files_pull_intent' => 'mirror',
            'fs_root_nonempty_behavior' => 'preserve-local',
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'index',
            ],
        ]);
        $client = new \ImportClient(
            'http://fake.invalid',
            $this->state_directory,
            $this->filesystem_root
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Use --intent=catch-up to preserve existing local content.'
        );

        $client->run_files_pull();
    }

    public function testAbortClearsIntentCursorAndMirrorArtifacts(): void
    {
        $this->write_state([
            'files_pull_intent' => 'mirror',
            'files_pull_processor_cursor' => ['phase' => 'scanning_atoms'],
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'partial',
                'current_stage' => 'local_scope',
            ],
        ]);
        $mirror_work_directory = dirname($this->pull_state_file)
            . '/files-pull-mirror/local-scope';
        mkdir($mirror_work_directory, 0755, true);
        file_put_contents($mirror_work_directory . '/work', 'transient');

        $this->new_client()->run([
            'command' => 'files-pull',
            'abort' => true,
        ]);

        $state = $this->read_state();
        $this->assertNull($state['files_pull_intent']);
        $this->assertNull($state['files_pull_processor_cursor']);
        $this->assertDirectoryDoesNotExist(
            dirname($this->pull_state_file) . '/files-pull-mirror'
        );
    }

    public function testStateRejectsUnknownIntent(): void
    {
        $state = ( new \PullState() )->to_array();
        $state['files_pull_intent'] = 'merge';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'files_pull_intent must be mirror, catch-up, or null'
        );

        \PullState::from_array($state);
    }

    public function testStateRejectsNonArrayProcessorCursor(): void
    {
        $state = ( new \PullState() )->to_array();
        $state['files_pull_processor_cursor'] = 'cursor';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'files_pull_processor_cursor must be an array or null'
        );

        \PullState::from_array($state);
    }

    public function testStateRoundTripsAnOpaqueMirrorProcessorCursor(): void
    {
        $state = ( new \PullState() )->to_array();
        $state['files_pull_intent'] = 'mirror';
        $state['files_pull_processor_cursor'] = [
            'phase' => 'processor-owned',
        ];
        $state['active_resumable_command']['command_name'] = 'files-pull';
        $state['active_resumable_command']['completion_state'] = 'partial';
        $state['active_resumable_command']['current_stage'] = 'local_scope';

        $round_trip = \PullState::from_array($state)->to_array();

        $this->assertSame(
            ['phase' => 'processor-owned'],
            $round_trip['files_pull_processor_cursor']
        );
    }

    /** @dataProvider invalidProcessorCursorOwnerProvider */
    public function testStateRejectsProcessorCursorOutsideAMirrorProcessorStage(
        ?string $intent,
        ?string $command,
        ?string $stage,
        string $completion_state = 'partial'
    ): void {
        $state = ( new \PullState() )->to_array();
        $state['files_pull_intent'] = $intent;
        $state['files_pull_processor_cursor'] = ['phase' => 'processing'];
        $state['active_resumable_command']['command_name'] = $command;
        $state['active_resumable_command']['completion_state'] =
            $completion_state;
        $state['active_resumable_command']['current_stage'] = $stage;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'files_pull_processor_cursor requires an active mirror processor stage'
        );

        \PullState::from_array($state);
    }

    public static function invalidProcessorCursorOwnerProvider(): array
    {
        return [
            'catch-up' => ['catch-up', 'files-pull', 'local_scope'],
            'another command' => ['mirror', 'files-index', 'local_scope'],
            'non-processor stage' => ['mirror', 'files-pull', 'fetch'],
            'completed lifecycle' => [
                'mirror',
                'files-pull',
                'local_scope',
                'complete',
            ],
        ];
    }

    private function new_client(): FilesPullIntentFakeClient
    {
        return new FilesPullIntentFakeClient(
            'http://fake.invalid',
            $this->state_directory,
            $this->filesystem_root
        );
    }

    private function write_completed_high_level_catch_up(
        string $command,
        array $stages
    ): void {
        $this->write_state([
            'files_pull_intent' => 'catch-up',
            'active_resumable_command' => [
                'command_name' => $stages[count($stages) - 1],
                'completion_state' => 'complete',
                'current_stage' => null,
            ],
            'pull_pipeline' => [
                'started_by_command' => $command,
                'stage_sequence' => $stages,
                'last_completed_stage' => $stages[count($stages) - 1],
                'has_completed_once' => true,
            ],
        ]);
    }

    private function write_state(array $changes): void
    {
        $defaults = [
            'preflight' => [
                'http_code' => 200,
                'data' => ['ok' => true],
            ],
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'fs_root_nonempty_behavior' => 'error',
        ];
        \write_current_pull_state(
            $this->new_client(),
            array_replace_recursive($defaults, $changes)
        );
    }

    private function read_state(): array
    {
        $contents = file_get_contents($this->pull_state_file);
        $this->assertIsString($contents);
        $state = json_decode($contents, true);
        $this->assertIsArray($state);
        return $state;
    }

    private function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
