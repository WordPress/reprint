<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The test namespace matches the existing ImportTests suite.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The local client subclass deterministically mutates a source between reads.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test diagnostics are local exception messages, never HTML output.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use PushJournal;
use Site_Export_HMAC_Client;
use StagedApplySessionDriver;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/** Exercises the direct sender against the real generic HTTP surface. */
final class StagedApplySessionDriverTest extends TestCase {

    private const SECRET = 'staged-apply-session-driver-secret';

    private string $temp_dir;
    private string $server_root;
    private string $source_root;
    private string $target_root;
    private string $staging_dir;
    private string $state_dir;
    private string $current_index_path;
    private string $router_path;
    private string $max_frames_path;
    private string $oversized_control_path;
    private string $raw_413_path;
    private string $lost_create_response_path;
    private string $lost_push_response_path;
    private string $lost_advance_response_path;
    private string $tiny_post_max_path;
    private string $base_url;

    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void {
        $this->temp_dir = sys_get_temp_dir() . '/staged-apply-driver-' . bin2hex(random_bytes(8));
        $this->server_root = $this->temp_dir . '/server';
        $this->source_root = $this->temp_dir . '/source';
        $this->target_root = $this->server_root . '/target';
        $this->staging_dir = $this->server_root . '/staging';
        $this->state_dir = $this->temp_dir . '/state';
        $this->current_index_path = $this->temp_dir . '/current-index.jsonl';
        $this->router_path = $this->server_root . '/router.php';
        $this->max_frames_path = $this->server_root . '/max-frames';
        $this->oversized_control_path = $this->server_root . '/oversized-control';
        $this->raw_413_path = $this->server_root . '/raw-413';
        $this->lost_create_response_path = $this->server_root . '/lose-create-response';
        $this->lost_push_response_path = $this->server_root . '/lose-push-response';
        $this->lost_advance_response_path = $this->server_root . '/lose-advance-response';
        $this->tiny_post_max_path = $this->server_root . '/tiny-post-max';
        mkdir($this->source_root, 0700, true);
        mkdir($this->target_root, 0700, true);
        file_put_contents($this->max_frames_path, '1024');
        $this->write_router();
        $this->start_server();
    }

    protected function tearDown(): void {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
            $this->server_process = null;
        }
        $this->remove_tree($this->temp_dir);
    }

    public function testStreamsOneMergeAndPublishesItsPinnedBaselineAfterApply(): void {
        mkdir($this->source_root . '/content', 0700, true);
        file_put_contents($this->source_root . '/content/new.txt', str_repeat('new-content-', 1000));
        if (!@symlink('new.txt', $this->source_root . '/content/current-link')) {
            self::markTestSkipped('The test filesystem does not permit symlinks.');
        }
        file_put_contents($this->target_root . '/obsolete.txt', 'old');

        $current_index = $this->write_index($this->current_index_path, [
            'content',
            'content/current-link',
            'content/new.txt',
        ]);
        $baseline_path = $this->temp_dir . '/old-baseline.jsonl';
        $this->write_literal_index($baseline_path, [
            'obsolete.txt' => ['ctime' => 1, 'size' => 3, 'type' => 'file'],
        ]);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $this->seed_baseline($journal, $baseline_path);

        $driver = $this->make_driver($journal, 37);
        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame(3, $result['changed']);
        self::assertSame(1, $result['deleted']);
        self::assertSame(str_repeat('new-content-', 1000), file_get_contents($this->target_root . '/content/new.txt'));
        self::assertTrue(is_link($this->target_root . '/content/current-link'));
        self::assertSame('new.txt', readlink($this->target_root . '/content/current-link'));
        self::assertFileDoesNotExist($this->target_root . '/obsolete.txt');
        self::assertSame($current_index, file_get_contents($journal->local_files_baseline_path));
    }

    public function testLargeUnchangedIndexAdvancesInBoundedDurableStepsWithoutUploadingOperations(): void {
        $paths = [];
        for ($index = 0; $index < 1100; $index++) {
            $path = sprintf('file-%04d.txt', $index);
            file_put_contents($this->source_root . '/' . $path, 'x');
            $paths[] = $path;
        }
        $contents = $this->write_index($this->current_index_path, $paths);
        $baseline_path = $this->temp_dir . '/same-baseline.jsonl';
        file_put_contents($baseline_path, $contents);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $this->seed_baseline($journal, $baseline_path);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status']);
        self::assertSame('local_work_pending', $first['reason']);
        $state_path = dirname($journal->local_files_baseline_path) . '/staged-apply/session.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        self::assertGreaterThan(0, $state['merge']['current_offset']);
        self::assertLessThan(strlen($contents), $state['merge']['current_offset']);
        self::assertSame(
            1024,
            substr_count(substr($contents, 0, $state['merge']['current_offset']), "\n")
                + substr_count(substr($contents, 0, $state['merge']['baseline_offset']), "\n")
        );
        self::assertSame($state['merge']['current_offset'], $state['merge']['baseline_offset']);
        self::assertSame($state['merge']['output_offset'], filesize(dirname($state_path) . '/next-baseline.tmp'));
        self::assertSame(0, $state['merge']['operation_count']);
        self::assertFalse($state['merge']['input_complete']);

        $result = $this->run_to_terminal($driver);
        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame(0, $result['changed']);
        self::assertSame(0, $result['deleted']);
        self::assertSame($contents, file_get_contents($journal->local_files_baseline_path));
    }

    public function testGrowingAFileBetweenRequestsRestartsItsRemoteRevision(): void {
        $original = str_repeat('a', 3000);
        file_put_contents($this->source_root . '/changing.bin', $original);
        $pinned_index = $this->write_index($this->current_index_path, ['changing.bin']);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        $state_path = dirname($journal->local_files_baseline_path) . '/staged-apply/session.json';
        $state = json_decode( (string) file_get_contents($state_path), true);
        self::assertSame(0, $state['merge']['operation_count']);
        self::assertSame(1024, $state['merge']['active_file']['offset']);
        self::assertSame(0, $state['merge']['active_file']['revision']);

        $replacement = str_repeat('b', 3001);
        file_put_contents($this->source_root . '/changing.bin', $replacement);
        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame($replacement, file_get_contents($this->target_root . '/changing.bin'));
        self::assertSame(
            $pinned_index,
            file_get_contents($journal->local_files_baseline_path),
            'The newer source version must remain visible to the next index diff.'
        );
    }

    public function testShrinkingAFileBetweenRequestsRestartsItsRemoteRevision(): void {
        $original = str_repeat('a', 3000);
        file_put_contents($this->source_root . '/shrinking.bin', $original);
        $pinned_index = $this->write_index($this->current_index_path, ['shrinking.bin']);
        file_put_contents($this->max_frames_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        $replacement = str_repeat('b', 900);
        file_put_contents($this->source_root . '/shrinking.bin', $replacement);

        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame($replacement, file_get_contents($this->target_root . '/shrinking.bin'));
        self::assertSame($pinned_index, file_get_contents($journal->local_files_baseline_path));
    }

    public function testSameSizeReplacementBetweenRequestsRestartsItsRemoteRevision(): void {
        $original = str_repeat('a', 3000);
        $source_path = $this->source_root . '/same-size.bin';
        file_put_contents($source_path, $original);
        $pinned_index = $this->write_index($this->current_index_path, ['same-size.bin']);
        file_put_contents($this->max_frames_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        $replacement = str_repeat('b', strlen($original));
        $replacement_path = $this->source_root . '/same-size-replacement.tmp';
        file_put_contents($replacement_path, $replacement);
        rename($replacement_path, $source_path);

        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame($replacement, file_get_contents($this->target_root . '/same-size.bin'));
        self::assertSame($pinned_index, file_get_contents($journal->local_files_baseline_path));
    }

    public function testDeletingAFileBetweenRequestsDiscardsItsStagedPrefix(): void {
        file_put_contents($this->source_root . '/deleted.bin', str_repeat('a', 3000));
        file_put_contents($this->target_root . '/deleted.bin', 'live-before-push');
        $this->write_index($this->current_index_path, ['deleted.bin']);
        file_put_contents($this->max_frames_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        unlink($this->source_root . '/deleted.bin');

        $result = $this->run_to_terminal($driver);

        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('source_changed', $result['reason']);
        self::assertStringContainsString('was deleted', $result['detail']);
        self::assertSame('live-before-push', file_get_contents($this->target_root . '/deleted.bin'));
        self::assertFileDoesNotExist($journal->local_files_baseline_path);
    }

    public function testLostPushResponseResumesFromTheTargetConfirmedOperationCount(): void {
        mkdir($this->source_root . '/accepted-before-response-loss', 0700);
        $current_index = $this->write_index($this->current_index_path, ['accepted-before-response-loss']);
        file_put_contents($this->lost_push_response_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $state_path = dirname($journal->local_files_baseline_path) . '/staged-apply/session.json';

        $lost = $driver->run();

        self::assertSame('retry', $lost['status'], (string) json_encode($lost));
        self::assertSame('request_failed', $lost['reason'], (string) json_encode($lost));
        $local_state = json_decode( (string) file_get_contents($state_path), true);
        self::assertIsArray($local_state);
        self::assertSame(0, $local_state['merge']['operation_count']);
        self::assertSame(1, $local_state['request_start']['frames_attempted']);
        $remote_state_path = $this->staging_dir . '/apply-sessions/' . $local_state['session_id'] . '/state.json';
        $remote_state = json_decode( (string) file_get_contents($remote_state_path), true);
        self::assertIsArray($remote_state);
        self::assertSame(1, $remote_state['operation_count']);

        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertDirectoryExists($this->target_root . '/accepted-before-response-loss');
        self::assertSame($current_index, file_get_contents($journal->local_files_baseline_path));
    }

    public function testLostAdvanceResponseRecoversTheCompletedRemoteCommit(): void {
        mkdir($this->source_root . '/committed-before-response-loss', 0700);
        $current_index = $this->write_index($this->current_index_path, ['committed-before-response-loss']);
        file_put_contents($this->lost_advance_response_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $state_path = dirname($journal->local_files_baseline_path) . '/staged-apply/session.json';

        $uploaded = $driver->run();
        self::assertSame('retry', $uploaded['status'], (string) json_encode($uploaded));
        self::assertSame('upload_pending', $uploaded['reason']);
        $lost = $driver->run();

        self::assertSame('retry', $lost['status'], (string) json_encode($lost));
        self::assertSame('invalid_response', $lost['reason'], (string) json_encode($lost));
        self::assertDirectoryExists($this->target_root . '/committed-before-response-loss');
        self::assertFileExists($state_path, 'The baseline cannot publish until status confirms the lost advance response.');

        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame($current_index, file_get_contents($journal->local_files_baseline_path));
        self::assertFileDoesNotExist($state_path);
    }

    public function testFileToDirectoryDriftDiscardsBeforeAnyLiveMutation(): void {
        file_put_contents($this->source_root . '/changing', str_repeat('a', 3000));
        file_put_contents($this->target_root . '/changing', 'live-before-push');
        $this->write_index($this->current_index_path, ['changing']);
        $baseline_path = $this->temp_dir . '/old-type-baseline.jsonl';
        $this->write_literal_index($baseline_path, [
            'changing' => ['ctime' => 1, 'size' => 16, 'type' => 'file'],
        ]);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $this->seed_baseline($journal, $baseline_path);
        $driver = $this->make_driver($journal, 1);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        unlink($this->source_root . '/changing');
        mkdir($this->source_root . '/changing', 0700);

        $result = $this->run_to_terminal($driver);
        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('source_changed', $result['reason']);
        self::assertStringContainsString('no longer a regular file', $result['detail']);
        self::assertSame('live-before-push', file_get_contents($this->target_root . '/changing'));
        self::assertSame(file_get_contents($baseline_path), file_get_contents($journal->local_files_baseline_path));
    }

    public function testCompletedDiscardKeepsAdvancingAWorkspaceLargerThanOneCleanupBatch(): void {
        $paths = [];
        for ($index = 0; $index < 300; $index++) {
            $path = sprintf('directory-%03d', $index);
            mkdir($this->source_root . '/' . $path, 0700);
            $paths[] = $path;
        }
        $this->write_index($this->current_index_path, $paths);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $saw_discard_pending = false;
        for ($step = 0; $step < 200; $step++) {
            $result = $driver->run();
            if ($result['reason'] === 'discard_pending') {
                $saw_discard_pending = true;
            }
            if ($result['status'] !== 'retry') {
                break;
            }
        }

        self::assertTrue($saw_discard_pending, 'The fixture must exceed the target cleanup batch.');
        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertFileDoesNotExist(dirname($journal->local_files_baseline_path) . '/staged-apply/session.json');
    }

    public function testBaselinePublishRecoversAfterTheRenameLandedBeforeLocalState(): void {
        file_put_contents($this->current_index_path, '');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $work_directory = dirname($journal->local_files_baseline_path) . '/staged-apply';
        mkdir($work_directory, 0700, true);
        $next_baseline = $work_directory . '/next-baseline.tmp';
        file_put_contents($next_baseline, "pinned baseline\n");
        $stat = lstat($next_baseline);
        self::assertIsArray($stat);
        $expected = [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
        ];
        rename($next_baseline, $journal->local_files_baseline_path);
        // ctime is not rename-stable on every filesystem. chmod models that
        // metadata-only change while leaving the proof fields untouched.
        chmod($journal->local_files_baseline_path, 0600);
        $state = [
            'version' => 2,
            'source_root_b64' => base64_encode( (string) realpath($this->source_root)),
            'merge' => [
                'input_complete' => true,
                'next_baseline_identity' => $expected,
                'changed' => 0,
                'deleted' => 0,
            ],
            'baseline_published' => false,
        ];

        $method = new \ReflectionMethod(StagedApplySessionDriver::class, 'publish_baseline');
        $arguments = [&$state];
        $method->invokeArgs($driver, $arguments);

        self::assertTrue($state['baseline_published']);
        self::assertSame("pinned baseline\n", file_get_contents($journal->local_files_baseline_path));
    }

    public function testFinalMergeStepRejectsAnInPlaceCurrentIndexRewrite(): void {
        $this->assert_final_merge_rejects_mutation('current');
    }

    public function testFinalMergeStepRejectsAnInPlaceBaselineRewrite(): void {
        $this->assert_final_merge_rejects_mutation('baseline');
    }

    public function testLostDiscardResponseBeforeTombstoneRenameRefreshesGeneration(): void {
        file_put_contents($this->current_index_path, '');
        $created = $this->request_json(
            'staged_session_create&create_token=' . bin2hex(random_bytes(16)),
            'POST'
        );
        self::assertSame(201, $created['http_code']);
        $session_id = $created['body']['session_id'];
        $remote_state_path = $this->staging_dir . '/apply-sessions/' . $session_id . '/state.json';
        $remote_state = json_decode( (string) file_get_contents($remote_state_path), true);
        self::assertIsArray($remote_state);
        // This is the durable point inside discard after generation/phase
        // changed but before the workspace was renamed to .discarding-*.
        $remote_state['phase'] = 'discarding';
        $remote_state['discarding_complete'] = false;
        ++$remote_state['request_generation'];
        file_put_contents($remote_state_path, json_encode($remote_state, JSON_UNESCAPED_SLASHES));

        $journal = new PushJournal($this->state_dir, $this->base_url);
        $local_state_directory = dirname($journal->local_files_baseline_path) . '/staged-apply';
        mkdir($local_state_directory, 0700, true);
        file_put_contents($local_state_directory . '/session.json', json_encode([
            'version' => 2,
            'source_root_b64' => base64_encode( (string) realpath($this->source_root)),
            'session_id' => $session_id,
            'request_generation' => $created['body']['request_generation'],
            'remote_phase' => 'uploading',
            'max_frame_bytes' => $created['body']['max_frame_bytes'],
            'max_frames_per_request' => $created['body']['max_frames_per_request'],
            'request_sizer' => [],
            'current_index_identity' => $this->regular_file_identity($this->current_index_path),
            'baseline_identity' => null,
            'merge' => ['changed' => 0, 'deleted' => 0, 'input_complete' => false],
            'request_start' => null,
            'request_progress_file' => null,
            'catch_up' => null,
            'baseline_published' => false,
            'discard_pending' => true,
            'discard_needs_status' => true,
            'discard_reason' => [
                'reason' => 'source_changed',
                'detail' => 'Simulated lost discard response.',
            ],
        ], JSON_UNESCAPED_SLASHES));
        $driver = $this->make_driver($journal, 1024);

        $refreshed = $driver->run();
        self::assertSame('retry', $refreshed['status'], (string) json_encode($refreshed));
        self::assertSame('discard_pending', $refreshed['reason']);
        $persisted = json_decode( (string) file_get_contents($local_state_directory . '/session.json'), true);
        self::assertSame($remote_state['request_generation'], $persisted['request_generation']);
        self::assertFalse($persisted['discard_needs_status']);

        $result = $this->run_to_terminal($driver);
        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('source_changed', $result['reason']);
        self::assertFileDoesNotExist($local_state_directory . '/session.json');
    }

    public function testAdvertisedMetadataFrameCapRotatesTheUploadRequest(): void {
        foreach (['alpha', 'beta', 'gamma'] as $directory) {
            mkdir($this->source_root . '/' . $directory, 0700);
        }
        $this->write_index($this->current_index_path, ['alpha', 'beta', 'gamma']);
        file_put_contents($this->max_frames_path, '2');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        $state = json_decode(
            (string) file_get_contents(dirname($journal->local_files_baseline_path) . '/staged-apply/session.json'),
            true
        );
        self::assertSame(2, $state['max_frames_per_request']);
        self::assertSame(2, $state['merge']['operation_count']);

        $result = $this->run_to_terminal($driver);
        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertSame(3, $result['changed']);
    }

    public function testTargetRootReplacementTurnsUploadIntoADiscardObligation(): void {
        file_put_contents($this->source_root . '/large.bin', str_repeat('a', 3000));
        file_put_contents($this->target_root . '/live.txt', 'original target');
        $this->write_index($this->current_index_path, ['large.bin']);
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        $old_target_root = $this->server_root . '/target-before-replacement';
        rename($this->target_root, $old_target_root);
        mkdir($this->target_root, 0700);

        $rejected = $driver->run();
        self::assertSame('retry', $rejected['status'], (string) json_encode($rejected));
        self::assertSame('discard_pending', $rejected['reason']);
        $result = $this->run_to_terminal($driver);

        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('session_rejected', $result['reason']);
        self::assertStringContainsString('target root was replaced', $result['detail']);
        self::assertFileDoesNotExist($this->target_root . '/large.bin');
        self::assertSame('original target', file_get_contents($old_target_root . '/live.txt'));
    }

    public function testMalformedIndexAfterAnAcceptedOperationDiscardsTheSession(): void {
        mkdir($this->source_root . '/accepted-first', 0700);
        $this->write_index($this->current_index_path, ['accepted-first']);
        file_put_contents($this->current_index_path, "{not-json\n", FILE_APPEND);
        file_put_contents($this->max_frames_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        self::assertSame('upload_pending', $first['reason']);
        $state = json_decode(
            (string) file_get_contents(dirname($journal->local_files_baseline_path) . '/staged-apply/session.json'),
            true
        );
        self::assertSame(1, $state['merge']['operation_count']);

        $result = $this->run_to_terminal($driver);
        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('invalid_index', $result['reason']);
        self::assertStringContainsString('not valid JSON', $result['detail']);
        self::assertDirectoryDoesNotExist($this->target_root . '/accepted-first');
    }

    public function testRecoversAZeroByteFileCursorPersistedBeforeFinalization(): void {
        file_put_contents($this->source_root . '/empty.txt', '');
        $this->write_index($this->current_index_path, ['empty.txt']);
        $created = $this->request_json(
            'staged_session_create&create_token=' . bin2hex(random_bytes(16)),
            'POST'
        );
        self::assertSame(201, $created['http_code']);
        $session_id = $created['body']['session_id'];
        $remote_state_path = $this->staging_dir . '/apply-sessions/' . $session_id . '/state.json';
        $remote_state = json_decode( (string) file_get_contents($remote_state_path), true);
        self::assertIsArray($remote_state);
        ++$remote_state['request_generation'];
        $remote_state['current_file'] = [
            'operation_index' => 0,
            'path_b64' => base64_encode('empty.txt'),
            'revision' => 0,
            'committed_bytes' => 0,
            'total_bytes' => 0,
        ];
        file_put_contents($remote_state_path, json_encode($remote_state, JSON_UNESCAPED_SLASHES));

        $journal = new PushJournal($this->state_dir, $this->base_url);
        $local_state_directory = dirname($journal->local_files_baseline_path) . '/staged-apply';
        mkdir($local_state_directory, 0700, true);
        $initial_merge = [
            'current_offset' => 0,
            'baseline_offset' => 0,
            'output_offset' => 0,
            'current_pending' => null,
            'baseline_pending' => null,
            'current_eof' => false,
            'baseline_eof' => true,
            'current_previous_path_b64' => null,
            'baseline_previous_path_b64' => null,
            'operation_count' => 0,
            'changed' => 0,
            'deleted' => 0,
            'active_file' => null,
            'input_complete' => false,
            'next_baseline_identity' => null,
        ];
        $source_identity = $this->regular_file_identity($this->source_root . '/empty.txt');
        unset($source_identity['mtime']);
        file_put_contents($local_state_directory . '/session.json', json_encode([
            'version' => 2,
            'source_root_b64' => base64_encode( (string) realpath($this->source_root)),
            'session_id' => $session_id,
            'request_generation' => $created['body']['request_generation'],
            'remote_phase' => 'uploading',
            'max_frame_bytes' => $created['body']['max_frame_bytes'],
            'max_frames_per_request' => $created['body']['max_frames_per_request'],
            'request_sizer' => [],
            'current_index_identity' => $this->regular_file_identity($this->current_index_path),
            'baseline_identity' => null,
            'merge' => $initial_merge,
            'request_start' => [
                'merge' => $initial_merge,
                'request_generation' => $created['body']['request_generation'],
                'frames_attempted' => 1,
            ],
            'request_progress_file' => [
                'operation_index' => 0,
                'path_b64' => base64_encode('empty.txt'),
                'revision' => 0,
                'total_bytes' => 0,
                'source_identity' => $source_identity,
            ],
            'catch_up' => null,
            'baseline_published' => false,
            'discard_pending' => false,
            'discard_needs_status' => false,
        ], JSON_UNESCAPED_SLASHES));
        $driver = $this->make_driver($journal, 1024);

        $first = $driver->run();
        self::assertSame('retry', $first['status'], (string) json_encode($first));
        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertFileExists($this->target_root . '/empty.txt');
        self::assertSame(0, filesize($this->target_root . '/empty.txt'));
    }

    public function testRepeatedSourceDriftYieldsAfterOneRestartInsteadOfSpinning(): void {
        file_put_contents($this->source_root . '/hot.bin', 'a');
        file_put_contents($this->current_index_path, '');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1);
        $mutations = 0;
        $client = new StagedApplySessionDriverMutatingClient(function () use (&$mutations): void {
            ++$mutations;
            file_put_contents($this->source_root . '/hot.bin', 'b', FILE_APPEND);
        });
        $state = [
            'max_frames_per_request' => 1024,
            'request_progress_file' => null,
        ];
        $working = [
            'operation_count' => 0,
            'active_file' => null,
        ];
        $action = [
            'entry' => [
                'entry' => ['path' => base64_encode('hot.bin')],
            ],
        ];
        $sent_frames = 0;
        $method = new \ReflectionMethod(StagedApplySessionDriver::class, 'stream_file_operation');
        $arguments = [$client, &$state, &$working, $action, &$sent_frames];

        $outcome = $method->invokeArgs($driver, $arguments);

        self::assertSame('rotate', $outcome);
        self::assertSame(2, $mutations);
        self::assertSame(0, $sent_frames);
        self::assertSame(2, $working['active_file']['revision']);
        self::assertTrue($working['active_file']['restart']);
        self::assertSame(2, $state['request_progress_file']['revision']);
    }

    public function testIndexLineBoundFitsTheTargetsMaximumRawPath(): void {
        file_put_contents($this->current_index_path, '');
        $driver = $this->make_driver(new PushJournal($this->state_dir, $this->base_url), 1024);
        $path = str_repeat('a', 8192);
        $line = json_encode([
            'path' => base64_encode($path),
            'ctime' => 1,
            'size' => 0,
            'type' => 'dir',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        self::assertGreaterThan(8192, strlen($line));
        self::assertLessThanOrEqual(\Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES, strlen($line));
        $method = new \ReflectionMethod(StagedApplySessionDriver::class, 'decode_index_line');

        $decoded = $method->invoke($driver, $line, 'boundary index');

        self::assertSame(base64_encode($path), $decoded['entry']['path']);
    }

    public function testChunkOptionCannotCreateAnUnboundedInMemoryPayload(): void {
        file_put_contents($this->current_index_path, '');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 4194304');
        $this->make_driver(new PushJournal($this->state_dir, $this->base_url), 4194305);
    }

    public function testOversizedControlResponseIsRejectedWithoutBufferingIt(): void {
        file_put_contents($this->current_index_path, '');
        file_put_contents($this->oversized_control_path, '1');
        $driver = $this->make_driver(new PushJournal($this->state_dir, $this->base_url), 1024);

        $result = $driver->run();

        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('control_response_too_large', $result['reason']);
        self::assertStringContainsString('65536-byte control response limit', $result['detail']);
    }

    public function testCatchUpRejectsMoreAcceptedOperationsThanTheRequestSentFrames(): void {
        file_put_contents($this->current_index_path, '');
        $driver = $this->make_driver(new PushJournal($this->state_dir, $this->base_url), 1024);
        $request_merge = ['operation_count' => 4];
        $state = [
            'max_frames_per_request' => 10,
            'request_start' => [
                'merge' => $request_merge,
                'request_generation' => 7,
                'frames_attempted' => 3,
            ],
        ];
        $remote = [
            'request_generation' => 8,
            'operation_count' => 6,
            'current_file' => null,
        ];
        $method = new \ReflectionMethod(StagedApplySessionDriver::class, 'begin_catch_up');
        $arguments = [&$state, $remote, 1];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('advanced by 2 operations after this request sent only 1 frame');
        $method->invokeArgs($driver, $arguments);
    }

    public function testTerminalRaw413RefreshesGenerationAndDiscardsTheRemoteSession(): void {
        mkdir($this->source_root . '/never-applied', 0700);
        $this->write_index($this->current_index_path, ['never-applied']);
        file_put_contents($this->raw_413_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $state_path = dirname($journal->local_files_baseline_path) . '/staged-apply/session.json';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $rejected = $driver->run();
            if ($rejected['reason'] === 'discard_pending') {
                break;
            }
            self::assertSame('retry', $rejected['status'], (string) json_encode($rejected));
            self::assertSame('request_too_large', $rejected['reason'], (string) json_encode($rejected));
        }

        self::assertSame('retry', $rejected['status'], (string) json_encode($rejected));
        self::assertSame('discard_pending', $rejected['reason'], (string) json_encode($rejected));
        $state = json_decode( (string) file_get_contents($state_path), true);
        self::assertIsArray($state);
        self::assertTrue($state['discard_pending']);
        self::assertTrue($state['discard_needs_status']);
        self::assertSame('request_size_exhausted', $state['discard_reason']['reason']);
        self::assertDirectoryExists($this->staging_dir . '/apply-sessions/' . $state['session_id']);

        $result = $this->run_to_terminal($driver);

        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('request_size_exhausted', $result['reason']);
        self::assertDirectoryDoesNotExist($this->staging_dir . '/apply-sessions/' . $state['session_id']);
        self::assertDirectoryDoesNotExist($this->target_root . '/never-applied');
        self::assertFileDoesNotExist($state_path);
    }

    public function testLostCreateResponseWithIndexDriftDiscardsTheOldSessionBeforeRepinning(): void {
        mkdir($this->source_root . '/first-version', 0700);
        $this->write_index($this->current_index_path, ['first-version']);
        file_put_contents($this->lost_create_response_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $local_work_directory = dirname($journal->local_files_baseline_path) . '/staged-apply';

        $lost = $driver->run();

        self::assertSame('retry', $lost['status'], (string) json_encode($lost));
        self::assertSame('invalid_response', $lost['reason'], (string) json_encode($lost));
        self::assertFileExists($local_work_directory . '/creating.json');
        self::assertFileDoesNotExist($local_work_directory . '/session.json');
        $remote_sessions = array_values(array_filter(
            glob($this->staging_dir . '/apply-sessions/*') ?: [],
            static function (string $path): bool {
                return is_dir($path) && preg_match('/^[a-f0-9]{32}$/D', basename($path)) === 1;
            }
        ));
        self::assertCount(1, $remote_sessions, 'The lost response must have left one real remote session to recover.');
        $old_session_path = $remote_sessions[0];

        mkdir($this->source_root . '/second-version', 0700);
        $current_index = $this->write_index($this->current_index_path, ['first-version', 'second-version']);
        $discarded = $driver->run();

        self::assertSame('failed', $discarded['status'], (string) json_encode($discarded));
        self::assertSame('source_changed', $discarded['reason']);
        self::assertStringContainsString('must be discarded before a later run pins the new indexes', $discarded['detail']);
        self::assertDirectoryDoesNotExist($old_session_path);
        self::assertFileDoesNotExist($local_work_directory . '/creating.json');
        self::assertFileDoesNotExist($local_work_directory . '/session.json');
        self::assertDirectoryDoesNotExist($this->target_root . '/first-version');

        $result = $this->run_to_terminal($driver);

        self::assertSame('complete', $result['status'], (string) json_encode($result));
        self::assertDirectoryExists($this->target_root . '/first-version');
        self::assertDirectoryExists($this->target_root . '/second-version');
        self::assertSame($current_index, file_get_contents($journal->local_files_baseline_path));
    }

    public function testTinyAdvertisedPostMaxDiscardsTheCreatedSessionBeforeFailing(): void {
        mkdir($this->source_root . '/never-uploaded', 0700);
        $this->write_index($this->current_index_path, ['never-uploaded']);
        file_put_contents($this->tiny_post_max_path, '1');
        $journal = new PushJournal($this->state_dir, $this->base_url);
        $driver = $this->make_driver($journal, 1024);
        $local_work_directory = dirname($journal->local_files_baseline_path) . '/staged-apply';

        $result = $driver->run();

        self::assertSame('failed', $result['status'], (string) json_encode($result));
        self::assertSame('request_size_exhausted', $result['reason']);
        self::assertStringContainsString('post_max_bytes 524288', $result['detail']);
        $created_session_id = trim( (string) file_get_contents($this->tiny_post_max_path . '.session'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $created_session_id);
        self::assertDirectoryDoesNotExist($this->staging_dir . '/apply-sessions/' . $created_session_id);
        self::assertFileDoesNotExist($local_work_directory . '/creating.json');
        self::assertFileDoesNotExist($local_work_directory . '/session.json');
        self::assertDirectoryDoesNotExist($this->target_root . '/never-uploaded');
        self::assertFileDoesNotExist($journal->local_files_baseline_path);
    }

    private function make_driver(PushJournal $journal, int $chunk_bytes): StagedApplySessionDriver {
        return new StagedApplySessionDriver([
            'base_url' => $this->base_url,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'source_root' => $this->source_root,
            'current_index_file' => $this->current_index_path,
            'push_journal' => $journal,
            'chunk_bytes' => $chunk_bytes,
            'allow_http' => true,
        ]);
    }

    private function seed_baseline(PushJournal $journal, string $source): void {
        $directory = dirname($journal->local_files_baseline_path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        copy($source, $journal->local_files_baseline_path);
    }

    private function assert_final_merge_rejects_mutation(string $mutated_side): void {
        file_put_contents($this->current_index_path, "current\n");
        $baseline_path = $this->temp_dir . '/final-step-baseline';
        file_put_contents($baseline_path, "baseline\n");
        $output_path = $this->temp_dir . '/final-step-output';
        $current_handle = fopen($this->current_index_path, 'rb');
        $baseline_handle = fopen($baseline_path, 'rb');
        $output_handle = fopen($output_path, 'w+b');
        self::assertIsResource($current_handle);
        self::assertIsResource($baseline_handle);
        self::assertIsResource($output_handle);
        $state = [
            'current_index_identity' => $this->regular_file_identity($this->current_index_path),
            'baseline_identity' => $this->regular_file_identity($baseline_path),
        ];
        file_put_contents(
            $mutated_side === 'current' ? $this->current_index_path : $baseline_path,
            str_repeat('changed', 20)
        );
        $merge = ['current_pending' => null, 'baseline_pending' => null];
        $method = new \ReflectionMethod(StagedApplySessionDriver::class, 'finish_merge_input');
        $arguments = [&$merge, $current_handle, $baseline_handle, $output_handle, $state];

        try {
            $method->invokeArgs($this->make_driver(new PushJournal($this->state_dir, $this->base_url), 1024), $arguments);
            self::fail('The final merge step accepted a rewritten ' . $mutated_side . ' index.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertInstanceOf(\StagedApplySessionSourceTreeChanged::class, $exception);
            self::assertStringContainsString($mutated_side, $exception->getMessage());
        } finally {
            fclose($current_handle);
            fclose($baseline_handle);
            fclose($output_handle);
        }
    }

    /** @return array{dev:int,ino:int,size:int,ctime:int,mtime:int} */
    private function regular_file_identity(string $path): array {
        $stat = lstat($path);
        self::assertIsArray($stat);
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
            'mtime' => (int) $stat['mtime'],
        ];
    }

    /** @return array{http_code:int,body:array<string,mixed>} */
    private function request_json(string $query, string $method): array {
        $url = $this->base_url . '?endpoint=' . $query;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers($method, $url);
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? '' : null,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($handle);
        $http_code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response)) {
            throw new \RuntimeException('Driver control request failed: ' . $error);
        }
        $body = json_decode($response, true);
        if (!is_array($body)) {
            throw new \RuntimeException('Driver control request returned invalid JSON: ' . $response);
        }
        return ['http_code' => $http_code, 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function run_to_terminal(StagedApplySessionDriver $driver): array {
        for ($step = 0; $step < 200; $step++) {
            $result = $driver->run();
            if ($result['status'] !== 'retry') {
                return $result;
            }
        }
        self::fail('The direct staged apply did not finish in 200 bounded steps: ' . json_encode($result));
    }

    /** @param string[] $paths */
    private function write_index(string $path, array $paths): string {
        $entries = [];
        foreach ($paths as $relative_path) {
            $stat = lstat($this->source_root . '/' . $relative_path);
            if (!is_array($stat)) {
                self::fail('Could not stat test source path ' . $relative_path . '.');
            }
            $mode = (int) $stat['mode'] & 0170000;
            $entries[$relative_path] = [
                'ctime' => (int) $stat['ctime'],
                'size' => $mode === 0100000 ? (int) $stat['size'] : 0,
                'type' => $mode === 0040000 ? 'dir' : ( $mode === 0120000 ? 'link' : 'file' ),
            ];
        }
        return $this->write_literal_index($path, $entries);
    }

    /** @param array<string,array{ctime:int,size:int,type:string}> $entries */
    private function write_literal_index(string $path, array $entries): string {
        uksort($entries, 'strcmp');
        $contents = '';
        foreach ($entries as $relative_path => $entry) {
            $contents .= json_encode([
                'path' => base64_encode($relative_path),
                'ctime' => $entry['ctime'],
                'size' => $entry['size'],
                'type' => $entry['type'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $contents);
        return $contents;
    }

    private function write_router(): void {
        $autoload_path = addslashes( (string) realpath(__DIR__ . '/../../vendor/autoload.php'));
        $target_root = addslashes($this->target_root);
        $staging_dir = addslashes($this->staging_dir);
        $max_frames_path = addslashes($this->max_frames_path);
        $oversized_control_path = addslashes($this->oversized_control_path);
        $raw_413_path = addslashes($this->raw_413_path);
        $lost_create_response_path = addslashes($this->lost_create_response_path);
        $lost_push_response_path = addslashes($this->lost_push_response_path);
        $lost_advance_response_path = addslashes($this->lost_advance_response_path);
        $tiny_post_max_path = addslashes($this->tiny_post_max_path);
        file_put_contents($this->router_path, <<<PHP_ROUTER
<?php
if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}
if (is_file('{$oversized_control_path}') && (\$_GET['endpoint'] ?? '') === 'staged_session_create') {
    header('Content-Type: application/json');
    echo str_repeat('x', 70000);
    return true;
}
if (is_file('{$raw_413_path}') && (\$_GET['endpoint'] ?? '') === 'staged_session_push') {
    http_response_code(413);
    header('Content-Type: text/html');
    echo '<p>Request body rejected before PHP handled the push.</p>';
    return true;
}
require '{$autoload_path}';
\$server_options = [
    'default_directory' => '{$target_root}',
    'staged' => [
        'staging_dir' => '{$staging_dir}',
        'secret' => 'staged-apply-session-driver-secret',
        'apply_target_root' => '{$target_root}',
        'apply_sessions_enabled' => true,
        'max_frames_per_request' => (int) trim((string) file_get_contents('{$max_frames_path}')),
    ],
];
\$lost_response_markers = [
    'staged_session_create' => '{$lost_create_response_path}',
    'staged_session_push' => '{$lost_push_response_path}',
    'staged_session_advance' => '{$lost_advance_response_path}',
];
\$endpoint = \$_GET['endpoint'] ?? '';
if (is_file('{$tiny_post_max_path}') && \$endpoint === 'staged_session_create') {
    ob_start();
    Site_Export_HTTP_Server::serve(\$server_options);
    \$response = ob_get_clean();
    \$body = json_decode((string) \$response, true);
    if (!is_array(\$body) || !is_string(\$body['session_id'] ?? null)) {
        throw new RuntimeException('Could not capture the real create response for the tiny post_max_bytes test.');
    }
    file_put_contents('{$tiny_post_max_path}.session', \$body['session_id']);
    unlink('{$tiny_post_max_path}');
    \$body['post_max_bytes'] = 524288;
    echo json_encode(\$body, JSON_UNESCAPED_SLASHES);
    return true;
}
if (isset(\$lost_response_markers[\$endpoint]) && is_file(\$lost_response_markers[\$endpoint])) {
    ob_start();
    Site_Export_HTTP_Server::serve(\$server_options);
    ob_end_clean();
    unlink(\$lost_response_markers[\$endpoint]);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'The response was lost after the request became durable.';
    return true;
}
Site_Export_HTTP_Server::serve(\$server_options);
return true;
PHP_ROUTER
        );
    }

    private function start_server(): void {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        if ($socket === false) {
            self::fail('Could not reserve driver server port: ' . $error_message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr( (string) $address, ':'), 1);
        $this->base_url = 'http://127.0.0.1:' . $port . '/';
        $this->server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', $this->server_root, $this->router_path],
            [0 => ['pipe', 'r'], 1 => ['file', $this->server_root . '/server.log', 'a'], 2 => ['file', $this->server_root . '/server.log', 'a']],
            $pipes,
            $this->server_root
        );
        if (!is_resource($this->server_process)) {
            self::fail('Could not start the driver endpoint test server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents($this->base_url . '__ping') === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::fail('The driver endpoint test server did not start: ' . (string) @file_get_contents($this->server_root . '/server.log'));
    }

    private function remove_tree(string $path): void {
        if (is_link($path) || !is_dir($path)) {
            if (( file_exists($path) || is_link($path) ) && !@unlink($path)) {
                throw new \RuntimeException('Could not remove driver test path: ' . $path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

/** Mutates the source exactly when the driver is about to read a chunk. */
final class StagedApplySessionDriverMutatingClient extends \StagedPushStreamClient {

    /** @var callable */
    private $mutate_source;

    public function __construct(callable $mutate_source) {
        $this->mutate_source = $mutate_source;
    }

    public function should_finish_request(): bool {
        return false;
    }

    public function next_chunk_body_bytes(): int {
        ( $this->mutate_source )();
        return 1;
    }

    public function send_operation(array $operation): bool {
        throw new \LogicException('Repeated drift must yield before a frame is sent.');
    }
}
