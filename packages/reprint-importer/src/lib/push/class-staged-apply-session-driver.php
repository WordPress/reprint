<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This command-line library interpolates values only into local exception messages, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Public importer types follow this package's established unprefixed API.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The two private exception types belong only to this driver's lifecycle.

/**
 * Drives one direct staged-apply session from two sorted local indexes.
 *
 * The current index and the per-target baseline are merged once, in decoded
 * path order. Changed entries become typed operations immediately; entries
 * found only in the baseline become deletes. There is no client manifest,
 * file digest, candidate list, or second traversal. While the merge advances,
 * the exact current-index lines are copied into next-baseline.tmp. That file
 * is published only after the target reports that the apply is complete.
 *
 * Each invocation performs one bounded local scan, one bounded upload
 * request, or one target-owned commit/discard step. The merge cursor and at
 * most one pending line from each input are durable. Before an upload request
 * begins, its starting cursor is persisted. If its response is lost, target
 * status supplies the accepted operation count and partial-file cursor; the
 * driver replays only that bounded request's deterministic merge decisions,
 * without sending them again.
 */
final class StagedApplySessionDriver {

    private const STATE_VERSION = 2;

    /** Holds an 8192-byte raw path after base64 and bounded JSON metadata. */
    private const INDEX_MAX_LINE_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES;

    /** Same path bound enforced by the target session store. */
    private const MAX_PATH_BYTES = Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES;

    /** The sole in-memory payload unit remains a few MiB. */
    private const MAX_CHUNK_BYTES = 4194304;

    private const LOCAL_STEP_BYTES = 1048576;

    private const LOCAL_STEP_LINES = 1024;

    private const LOCAL_STATE_MAX_BYTES = 1048576;

    /** Control responses contain only one bounded session-state object. */
    private const CONTROL_RESPONSE_MAX_BYTES = 65536;

    private string $base_url;

    private Site_Export_HMAC_Client $hmac_client;

    private string $source_root;

    private string $current_index_file;

    private PushJournal $push_journal;

    private bool $allow_http;

    private int $chunk_bytes;

    private string $work_dir;

    private string $state_path;

    private string $creating_path;

    private string $next_baseline_path;

    /** @var resource|null */
    private $local_lock = null;

    /** @var resource|object|null */
    private $control_multi_handle = null;

    /** @var resource|object|null */
    private $control_handle = null;

    private bool $remote_state_is_confirmed = false;

    /**
     * @param array $options
     *   - base_url (string, required): export API URL.
     *   - hmac_client (Site_Export_HMAC_Client, required): request signer.
     *   - source_root (string, required): root described by the current index.
     *   - current_index_file (string, required): decoded-path-sorted JSONL.
     *   - push_journal (PushJournal, required): owns the per-target baseline.
     *   - allow_http (bool): permit plain HTTP. Default false.
     *   - chunk_bytes (int): bounded source read. Default 4 MiB.
     */
    public function __construct(array $options) {
        $base_url = $options['base_url'] ?? null;
        if (!is_string($base_url) || $base_url === '') {
            throw new InvalidArgumentException('StagedApplySessionDriver requires a base_url option.');
        }
        $scheme = strtolower( (string) parse_url($base_url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Expected option "base_url" to be an http:// or https:// URL; received ' . json_encode($base_url) . '.');
        }
        $allow_http = $options['allow_http'] ?? false;
        if (!is_bool($allow_http)) {
            throw new InvalidArgumentException('Expected option "allow_http" to be a boolean; received ' . json_encode($allow_http) . '.');
        }
        if ($scheme === 'http' && !$allow_http) {
            throw new InvalidArgumentException(
                'Refusing to push over plain HTTP to ' . json_encode($base_url) . '. HTTPS is required so an active attacker cannot read or modify the staged apply.'
            );
        }
        $this->base_url = $base_url;
        $this->allow_http = $allow_http;

        $hmac_client = $options['hmac_client'] ?? null;
        if (!$hmac_client instanceof Site_Export_HMAC_Client) {
            throw new InvalidArgumentException('StagedApplySessionDriver requires an hmac_client option.');
        }
        $this->hmac_client = $hmac_client;

        $source_root = $options['source_root'] ?? null;
        if (!is_string($source_root) || $source_root === '') {
            throw new InvalidArgumentException('StagedApplySessionDriver requires a source_root option.');
        }
        $resolved_source_root = realpath($source_root);
        if ($resolved_source_root === false || !is_dir($resolved_source_root) || $resolved_source_root[0] !== '/') {
            throw new InvalidArgumentException('Expected source_root to be an existing absolute directory; observed ' . json_encode($source_root) . '.');
        }
        $this->source_root = $resolved_source_root === '/' ? '/' : rtrim($resolved_source_root, '/');

        $current_index_file = $options['current_index_file'] ?? null;
        if (!is_string($current_index_file) || $current_index_file === '') {
            throw new InvalidArgumentException('StagedApplySessionDriver requires a current_index_file option.');
        }
        $this->current_index_file = $current_index_file;

        $push_journal = $options['push_journal'] ?? null;
        if (!$push_journal instanceof PushJournal) {
            throw new InvalidArgumentException('StagedApplySessionDriver requires a push_journal option.');
        }
        $this->push_journal = $push_journal;

        $chunk_bytes = $options['chunk_bytes'] ?? 4 * 1024 * 1024;
        if (!is_numeric($chunk_bytes) || (int) $chunk_bytes <= 0 || (int) $chunk_bytes > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('Expected option "chunk_bytes" to be an integer between 1 and ' . self::MAX_CHUNK_BYTES . '; received ' . json_encode($chunk_bytes) . '.');
        }
        $this->chunk_bytes = (int) $chunk_bytes;

        $this->work_dir = dirname($this->push_journal->local_files_baseline_path) . '/staged-apply';
        $this->state_path = $this->work_dir . '/session.json';
        $this->creating_path = $this->work_dir . '/creating.json';
        $this->next_baseline_path = $this->work_dir . '/next-baseline.tmp';
    }

    /**
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    public function run(): array {
        $this->acquire_local_lock();
        try {
            $state = $this->read_state();
            if ($state === null) {
                $state = $this->recover_promoted_session_state();
            }
            $newly_created = false;
            if ($state === null) {
                $started = $this->start_new_session();
                if (isset($started['status'])) {
                    return $started;
                }
                $state = $started;
                $newly_created = true;
            }

            // Discard moves the remote workspace to a cleanup tombstone.
            // Status deliberately cannot open that tombstone, while another
            // discard call can advance its bounded cleanup. Persisting this
            // branch before the first call prevents a 404 status after a lost
            // discard response from being mistaken for completed cleanup.
            if (( $state['discard_pending'] ?? false ) === true) {
                if (( $state['discard_needs_status'] ?? false ) === true) {
                    return $this->refresh_pending_discard($state);
                }
                return ( $state['baseline_published'] ?? false )
                    ? $this->finish_completed_discard($state)
                    : $this->finish_marked_discard($state);
            }

            if (!$newly_created && !$this->remote_state_is_confirmed) {
                $status = $this->session_control('staged_session_status', 'GET', $state);
                if ($status['outcome'] !== 'success') {
                    if ($this->session_is_gone($status) && ( $state['baseline_published'] ?? false )) {
                        $this->remove_local_session_files();
                        return $this->result('complete', null, null, null, $state);
                    }
                    if ($this->session_is_gone($status) && isset($state['discard_reason'])) {
                        $reason = $state['discard_reason'];
                        $this->remove_local_session_files();
                        return $this->result('failed', $reason['reason'], $reason['detail'], null, $state);
                    }
                    return $this->control_result($status, $state);
                }
                $this->adopt_remote_state($state, $status['body']);
                $this->write_state($state);
                $this->remote_state_is_confirmed = true;
            }

            if (isset($state['discard_reason'])) {
                return $this->finish_marked_discard($state);
            }

            try {
                return $this->drive_session($state);
            } catch (StagedApplySessionInputInvalid $exception) {
                $state['discard_reason'] = [
                    'reason' => 'invalid_index',
                    'detail' => $exception->getMessage(),
                ];
                $this->write_state($state);
                return $this->finish_marked_discard($state);
            } catch (StagedApplySessionSourceTreeChanged $exception) {
                $state['discard_reason'] = [
                    'reason' => 'source_changed',
                    'detail' => $exception->getMessage(),
                ];
                $this->write_state($state);
                return $this->finish_marked_discard($state);
            }
        } finally {
            $this->release_local_lock();
        }
    }

    /**
     * @return array<string,mixed>|array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function start_new_session(): array {
        $this->ensure_work_dir();
        $creating = $this->read_creating_state();
        if ($creating === null) {
            $this->remove_local_session_files();
            if (!is_file($this->current_index_file)) {
                throw new InvalidArgumentException('The target-relative current_index_file is missing: ' . $this->current_index_file . '.');
            }
            $creating = [
                'version' => self::STATE_VERSION,
                'source_root_b64' => base64_encode($this->source_root),
                'create_token' => bin2hex(random_bytes(16)),
                'current_index_identity' => $this->file_identity($this->current_index_file, 'the target-relative current index'),
                'baseline_identity' => is_file($this->push_journal->local_files_baseline_path)
                    ? $this->file_identity($this->push_journal->local_files_baseline_path, 'the local push baseline')
                    : null,
            ];
            $this->write_creating_state($creating);
            $this->truncate_next_baseline(0);
        }

        // Once a create token is durable, it must be replayed before any
        // local drift check can retire it. The first create response may have
        // been lost after the server allocated a session; forgetting the
        // token here would orphan that remote workspace.
        $created = $this->session_control(
            'staged_session_create',
            'POST',
            null,
            false,
            ['create_token' => $creating['create_token']]
        );
        if ($created['outcome'] !== 'success') {
            return $this->control_result($created, null);
        }
        $body = $created['body'];
        $session_id = $body['session_id'] ?? null;
        $phase = $body['phase'] ?? null;
        $request_generation = $body['request_generation'] ?? null;
        $max_frame_bytes = $body['max_frame_bytes'] ?? null;
        $max_frames_per_request = $body['max_frames_per_request'] ?? null;
        $post_max_bytes = $body['post_max_bytes'] ?? null;
        if (!is_string($session_id) || preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new RuntimeException('The staged session create response reported an invalid session_id.');
        }
        if ($phase !== 'uploading') {
            throw new RuntimeException('The staged session create response reported phase ' . json_encode($phase) . ' instead of uploading.');
        }
        if (!is_int($request_generation) || $request_generation < 0) {
            throw new RuntimeException('The staged session create response reported an invalid request_generation value.');
        }
        if (!is_int($max_frame_bytes) || $max_frame_bytes <= 0) {
            throw new RuntimeException('The staged session create response reported an invalid max_frame_bytes value.');
        }
        if (!is_int($max_frames_per_request) || $max_frames_per_request <= 0) {
            throw new RuntimeException('The staged session create response reported an invalid max_frames_per_request value.');
        }
        if ($post_max_bytes !== null && ( !is_int($post_max_bytes) || $post_max_bytes <= 0 )) {
            throw new RuntimeException('The staged session create response reported an invalid post_max_bytes value.');
        }
        if (isset($body['operation_count']) && $body['operation_count'] !== 0) {
            throw new RuntimeException('The staged session create response reported a nonzero operation_count.');
        }

        $request_sizer = new PushRequestSizer();
        $sizing_decision = $request_sizer->apply_reported_limits([$post_max_bytes]);
        $state = [
            'version' => self::STATE_VERSION,
            'source_root_b64' => base64_encode($this->source_root),
            'session_id' => $session_id,
            'request_generation' => $request_generation,
            'remote_phase' => 'uploading',
            'max_frame_bytes' => $max_frame_bytes,
            'max_frames_per_request' => min($max_frames_per_request, Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST),
            'request_sizer' => $request_sizer->get_state(),
            'current_index_identity' => $creating['current_index_identity'],
            'baseline_identity' => $creating['baseline_identity'],
            'merge' => $this->new_merge_state($creating['baseline_identity'] === null),
            'request_start' => null,
            'request_progress_file' => null,
            'catch_up' => null,
            'baseline_published' => false,
            'discard_pending' => false,
            'discard_needs_status' => false,
        ];
        try {
            $this->assert_pinned_inputs($creating);
        } catch (StagedApplySessionSourceTreeChanged $exception) {
            $state['discard_reason'] = [
                'reason' => 'source_changed',
                'detail' => $exception->getMessage()
                    . ' The session created for the old pinned indexes must be discarded before a later run pins the new indexes.',
            ];
            $state['discard_pending'] = true;
        }
        if ($sizing_decision['action'] === 'give_up' && !$state['discard_pending']) {
            $state['discard_reason'] = [
                'reason' => 'request_size_exhausted',
                'detail' => 'The target reports post_max_bytes ' . json_encode($post_max_bytes)
                    . ', below the smallest supported staged push request body.',
            ];
            $state['discard_pending'] = true;
        }
        if (!$state['discard_pending']) {
            $this->truncate_next_baseline(0);
        }
        $this->promote_creating_state($state);
        $this->remote_state_is_confirmed = true;
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function drive_session(array &$state): array {
        $phase = $state['remote_phase'];
        if ($phase === 'uploading') {
            if (is_array($state['catch_up'] ?? null)) {
                if (!$this->advance_catch_up($state)) {
                    return $this->local_work_pending($state);
                }
            }
            if (!( $state['merge']['input_complete'] ?? false )) {
                $uploaded = $this->advance_upload($state);
                if ($uploaded !== null) {
                    return $uploaded;
                }
            }
            if (!( $state['merge']['input_complete'] ?? false ) || is_array($state['merge']['active_file'] ?? null)) {
                return $this->local_work_pending($state);
            }

            $advanced = $this->session_control('staged_session_advance', 'POST', $state, true);
            if ($advanced['outcome'] !== 'success') {
                $this->remote_state_is_confirmed = false;
                return $this->control_result($advanced, $state);
            }
            $this->adopt_remote_state($state, $advanced['body']);
            $this->write_state($state);
            $this->remote_state_is_confirmed = true;
            if ($state['remote_phase'] !== 'complete') {
                return $this->remote_work_pending($state);
            }
            $phase = 'complete';
        }

        if ($phase === 'committing') {
            $advanced = $this->session_control('staged_session_advance', 'POST', $state, true);
            if ($advanced['outcome'] !== 'success') {
                $this->remote_state_is_confirmed = false;
                return $this->control_result($advanced, $state);
            }
            $this->adopt_remote_state($state, $advanced['body']);
            $this->write_state($state);
            $this->remote_state_is_confirmed = true;
            if ($state['remote_phase'] !== 'complete') {
                return $this->remote_work_pending($state);
            }
            $phase = 'complete';
        }

        if ($phase === 'complete') {
            $this->publish_baseline($state);
            return $this->finish_completed_discard($state);
        }

        if ($phase === 'failed') {
            if (!isset($state['discard_reason'])) {
                $state['discard_reason'] = [
                    'reason' => 'remote_failed',
                    'detail' => 'The target marked the staged apply session failed; only discard remains.',
                ];
                $this->write_state($state);
            }
            return $this->finish_marked_discard($state);
        }

        if ($phase === 'discarding') {
            return ( $state['baseline_published'] ?? false )
                ? $this->finish_completed_discard($state)
                : $this->finish_marked_discard($state);
        }

        throw new RuntimeException('The staged session status reported an unsupported phase ' . json_encode($phase) . '.');
    }

    /**
     * Advance the streaming merge and, when it finds changes, fill one
     * bounded upload request. Null means the input reached EOF without an
     * open request and the caller may close uploads immediately.
     *
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}|null
     */
    private function advance_upload(array &$state): ?array {
        $this->assert_pinned_inputs($state);
        $current_handle = $this->open_pinned_index($this->current_index_file, $state['current_index_identity'], 'the target-relative current index');
        $baseline_handle = $state['baseline_identity'] === null
            ? null
            : $this->open_pinned_index($this->push_journal->local_files_baseline_path, $state['baseline_identity'], 'the local push baseline');
        $output_handle = $this->open_next_baseline( (int) $state['merge']['output_offset']);

        $request_sizer = new PushRequestSizer([], is_array($state['request_sizer'] ?? null) ? $state['request_sizer'] : []);
        $client = new StagedPushStreamClient([
            'base_url' => $this->base_url,
            'hmac_client' => $this->hmac_client,
            'session_id' => $state['session_id'],
            'request_sizer' => $request_sizer,
            'chunk_bytes' => min($this->chunk_bytes, (int) $state['max_frame_bytes']),
            'allow_http' => $this->allow_http,
        ]);
        $working = $state['merge'];
        $budget = ['bytes' => 0, 'lines' => 0];
        $request_open = false;
        $sent_frames = 0;

        try {
            while ($budget['lines'] < self::LOCAL_STEP_LINES && $budget['bytes'] < self::LOCAL_STEP_BYTES) {
                if (
                    $request_open
                    && ( $client->should_finish_request() || $sent_frames >= (int) $state['max_frames_per_request'] )
                ) {
                    break;
                }
                $action = $this->next_merge_action($working, $current_handle, $baseline_handle, $output_handle, $budget);
                if ($action === null) {
                    $this->finish_merge_input($working, $current_handle, $baseline_handle, $output_handle, $state);
                    if (!$request_open) {
                        $state['merge'] = $working;
                        $this->write_state($state);
                        return null;
                    }
                    break;
                }
                if ($action['type'] === 'unchanged') {
                    $this->consume_merge_action($working, $action, false);
                    continue;
                }

                if (!$request_open) {
                    // Unchanged entries before the first operation are
                    // already durable in next-baseline.tmp. Make that exact
                    // point the replay boundary instead of rescanning them
                    // after a lost upload response.
                    $state['merge'] = $working;
                    $state['request_start'] = [
                        'merge' => $working,
                        'request_generation' => $state['request_generation'],
                        'frames_attempted' => 0,
                    ];
                    $state['request_progress_file'] = null;
                    $this->write_state($state);
                    $client->set_session_request_generation( (int) $state['request_generation']);
                    if (!$client->start_push_request()) {
                        $state['request_sizer'] = $request_sizer->get_state();
                        $this->write_state($state);
                        $this->remote_state_is_confirmed = false;
                        return $this->result('retry', 'request_failed', $client->get_last_error(), $state['session_id'], $state);
                    }
                    $request_open = true;
                }

                if ($client->should_finish_request() || $sent_frames >= (int) $state['max_frames_per_request']) {
                    break;
                }

                $path = $this->pending_path($action['entry']);
                if ($action['operation_type'] === 'file') {
                    $file_result = $this->stream_file_operation($client, $state, $working, $action, $sent_frames);
                    if ($file_result === 'rotate') {
                        break;
                    }
                    if ($file_result === 'connection_ended') {
                        break;
                    }
                    $this->consume_merge_action($working, $action, true);
                    continue;
                }

                $operation = [
                    'type' => $action['operation_type'],
                    'operation_index' => (int) $working['operation_count'],
                    'path' => $path,
                ];
                if ($action['operation_type'] === 'directory') {
                    $this->assert_source_type($path, 'dir');
                } elseif ($action['operation_type'] === 'symlink') {
                    $this->assert_source_type($path, 'link');
                    $target = @readlink($this->source_path($path));
                    if (!is_string($target)) {
                        throw new StagedApplySessionSourceTreeChanged('Could not read the source symlink ' . $this->describe_path($path) . '.');
                    }
                    if ($target === '' || strlen($target) > self::MAX_PATH_BYTES || strpos($target, "\0") !== false) {
                        throw new StagedApplySessionSourceTreeChanged('The source symlink ' . $this->describe_path($path) . ' has an empty, NUL-containing, or overlong target.');
                    }
                    $this->assert_source_type($path, 'link');
                    $operation['target'] = $target;
                }
                if (!$this->send_direct_operation($client, $state, $operation, $path)) {
                    break;
                }
                ++$sent_frames;
                $this->consume_merge_action($working, $action, true);
            }

            if (!$request_open) {
                $state['merge'] = $working;
                $this->write_state($state);
                return $this->local_work_pending($state);
            }

            $stream = $client->finish_request();
            $request_open = false;
            $state['request_sizer'] = $request_sizer->get_state();
            if (isset($stream['max_frame_bytes'])) {
                if (!is_int($stream['max_frame_bytes']) || $stream['max_frame_bytes'] <= 0) {
                    throw new RuntimeException('The staged session push response reported an invalid max_frame_bytes value.');
                }
                $state['max_frame_bytes'] = min( (int) $state['max_frame_bytes'], $stream['max_frame_bytes']);
            }
            if ($stream['status'] !== 'complete') {
                if (is_int($stream['request_generation'] ?? null) && $stream['request_generation'] >= 0) {
                    $state['request_generation'] = $stream['request_generation'];
                }
                if ($stream['status'] === 'failed') {
                    $has_confirmed_generation = is_int($stream['request_generation'] ?? null)
                        && $stream['request_generation'] >= 0;
                    $state['discard_reason'] = [
                        'reason' => is_string($stream['reason'] ?? null) ? $stream['reason'] : 'session_rejected',
                        'detail' => is_string($stream['detail'] ?? null)
                            ? $stream['detail']
                            : 'The target terminally rejected this staged apply upload.',
                    ];
                    $state['request_start'] = null;
                    $state['request_progress_file'] = null;
                    $state['catch_up'] = null;
                    $state['discard_pending'] = true;
                    // A raw 413 or broken terminal response cannot confirm
                    // which generation the target now requires. Status must
                    // refresh it before discard rather than abandoning the
                    // still-uploading remote session.
                    $state['discard_needs_status'] = !$has_confirmed_generation;
                    $this->write_state($state);
                    $this->remote_state_is_confirmed = false;
                    return $this->result('retry', 'discard_pending', $state['discard_reason']['detail'], $state['session_id'], $state);
                }
                $this->write_state($state);
                $this->remote_state_is_confirmed = false;
                return $this->result(
                    $stream['status'] === 'retry' ? 'retry' : 'failed',
                    $stream['reason'] ?? 'request_failed',
                    $stream['detail'] ?? null,
                    $state['session_id'],
                    $state
                );
            }

            $frames_sent = $stream['frames_sent'] ?? null;
            if (!is_int($frames_sent) || $frames_sent < 0) {
                throw new RuntimeException('The staged push client reported an invalid frames_sent count.');
            }
            $this->begin_catch_up($state, $stream, $frames_sent);
            if (!$this->advance_catch_up($state)) {
                return $this->local_work_pending($state);
            }
            return $this->result('retry', 'upload_pending', null, $state['session_id'], $state);
        } catch (Throwable $exception) {
            if ($request_open) {
                $client->abort_push_request();
            }
            throw $exception;
        } finally {
            fclose($current_handle);
            if (is_resource($baseline_handle)) {
                fclose($baseline_handle);
            }
            fclose($output_handle);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $working
     * @param array<string,mixed> $action
     */
    private function stream_file_operation(
        StagedPushStreamClient $client,
        array &$state,
        array &$working,
        array $action,
        int &$sent_frames
    ): string {
        $path = $this->pending_path($action['entry']);
        $source_restarts = 0;
        if (!is_array($working['active_file'] ?? null)) {
            $source_identity = $this->source_file_identity($path);
            $working['active_file'] = [
                'operation_index' => (int) $working['operation_count'],
                'path_b64' => base64_encode($path),
                'revision' => 0,
                'offset' => 0,
                'total_bytes' => $source_identity['size'],
                'source_identity' => $source_identity,
                'restart' => false,
            ];
        }

        while (true) {
            $active = $working['active_file'];
            if (
                $active['operation_index'] !== $working['operation_count']
                || base64_decode($active['path_b64'], true) !== $path
            ) {
                throw new RuntimeException('The persisted active file does not match the next merge operation.');
            }
            $actual_identity = $this->source_file_identity($path);
            if (!$this->same_source_file_identity($actual_identity, $active['source_identity'])) {
                $active = $this->restart_active_file($active, $actual_identity);
                $working['active_file'] = $active;
                ++$source_restarts;
            }

            if ($client->should_finish_request() || $sent_frames >= (int) $state['max_frames_per_request']) {
                return 'rotate';
            }
            $this->persist_request_progress_file($state, $working['active_file']);
            $active = $working['active_file'];
            $source_path = $this->source_path($path);
            $handle = @fopen($source_path, 'rb');
            if ($handle === false) {
                throw new StagedApplySessionSourceTreeChanged('Could not open the source file ' . $this->describe_path($path) . '.');
            }
            try {
                $opened_identity = $this->opened_source_file_identity($handle, $path);
                if ($this->same_source_file_identity($opened_identity, $active['source_identity'])) {
                    if ($active['offset'] > 0 && fseek($handle, $active['offset'], SEEK_SET) !== 0) {
                        throw new RuntimeException('Could not seek source file ' . $this->describe_path($path) . ' to offset ' . $active['offset'] . '.');
                    }
                    if ($active['total_bytes'] === 0) {
                        $payload = '';
                    } else {
                        $wanted = min($client->next_chunk_body_bytes(), $active['total_bytes'] - $active['offset']);
                        if ($wanted <= 0) {
                            return 'rotate';
                        }
                        $payload = fread($handle, $wanted);
                        if ($payload === false) {
                            throw new RuntimeException('Could not read source file ' . $this->describe_path($path) . '.');
                        }
                    }
                    $after_read_identity = $this->opened_source_file_identity($handle, $path);
                } else {
                    $payload = null;
                    $after_read_identity = $opened_identity;
                }
            } finally {
                fclose($handle);
            }
            $path_identity = $this->source_file_identity($path);
            if (
                !$this->same_source_file_identity($after_read_identity, $active['source_identity'])
                || !$this->same_source_file_identity($path_identity, $active['source_identity'])
            ) {
                $active = $this->restart_active_file($active, $path_identity);
                $working['active_file'] = $active;
                ++$source_restarts;
                if ($source_restarts > 1) {
                    $this->persist_request_progress_file($state, $active);
                    return 'rotate';
                }
                continue;
            }
            if ($active['total_bytes'] > 0 && $payload === '') {
                // A short read is same-type drift. The next lstat supplies a
                // fresh revision instead of appending beyond stale bytes.
                $active = $this->restart_active_file($active, $path_identity);
                $working['active_file'] = $active;
                ++$source_restarts;
                if ($source_restarts > 1) {
                    $this->persist_request_progress_file($state, $active);
                    return 'rotate';
                }
                continue;
            }

            if (!$this->send_direct_operation($client, $state, [
                'type' => 'file',
                'operation_index' => (int) $active['operation_index'],
                'path' => $path,
                'revision' => (int) $active['revision'],
                'offset' => (int) $active['offset'],
                'total_bytes' => (int) $active['total_bytes'],
                'restart' => (bool) $active['restart'],
                'payload' => $payload,
            ], $path)) {
                return 'connection_ended';
            }
            ++$sent_frames;
            $active['offset'] += strlen($payload);
            $active['restart'] = false;
            $working['active_file'] = $active;
            if ($active['offset'] === $active['total_bytes']) {
                $working['active_file'] = null;
                return 'complete';
            }
        }
    }

    /** @param array<string,mixed> $state */
    private function send_direct_operation(StagedPushStreamClient $client, array &$state, array $operation, string $path): bool {
        $request_start = $state['request_start'] ?? null;
        if (!is_array($request_start) || !is_int($request_start['frames_attempted'] ?? null)) {
            throw new RuntimeException('Cannot send a direct operation without a persisted request frame cursor.');
        }
        if ($request_start['frames_attempted'] >= (int) $state['max_frames_per_request']) {
            throw new RuntimeException('The direct operation request exceeded its persisted frame allowance.');
        }
        ++$request_start['frames_attempted'];
        $state['request_start'] = $request_start;
        // Reserve the frame before network I/O. If the process dies after
        // bytes leave but before send_operation() returns, status may still
        // confirm this frame and catch-up must know it belonged to this
        // bounded request.
        $this->write_state($state);
        try {
            return $client->send_operation($operation);
        } catch (InvalidArgumentException $exception) {
            throw new StagedApplySessionInputInvalid(
                'Could not encode the direct operation for ' . $this->describe_path($path) . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /** @return array<string,mixed> */
    private function restart_active_file(array $active_file, array $source_identity): array {
        $active_file['revision'] = $active_file['revision'] === PHP_INT_MAX
            ? 0
            : (int) $active_file['revision'] + 1;
        $active_file['offset'] = 0;
        $active_file['total_bytes'] = $source_identity['size'];
        $active_file['source_identity'] = $source_identity;
        $active_file['restart'] = true;
        return $active_file;
    }

    /** @param array<string,mixed> $state */
    private function persist_request_progress_file(array &$state, array $active_file): void {
        $progress = [
            'operation_index' => $active_file['operation_index'],
            'path_b64' => $active_file['path_b64'],
            'revision' => $active_file['revision'],
            'total_bytes' => $active_file['total_bytes'],
            'source_identity' => $active_file['source_identity'],
        ];
        if (( $state['request_progress_file'] ?? null ) == $progress) {
            return;
        }
        $state['request_progress_file'] = $progress;
        $this->write_state($state);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $remote
     */
    private function begin_catch_up(array &$state, array $remote, ?int $actual_frames_sent = null): void {
        $request_start = $state['request_start'] ?? null;
        if (!is_array($request_start) || !is_array($request_start['merge'] ?? null)) {
            throw new RuntimeException('A staged push response arrived without a persisted request-start cursor.');
        }
        $frames_attempted = $request_start['frames_attempted'] ?? null;
        if (!is_int($frames_attempted) || $frames_attempted < 0 || $frames_attempted > (int) $state['max_frames_per_request']) {
            throw new RuntimeException('The persisted staged push request has an invalid frame cursor.');
        }
        if ($actual_frames_sent !== null && ( $actual_frames_sent < 0 || $actual_frames_sent > $frames_attempted )) {
            throw new RuntimeException('The staged push client reported more sent frames than this request attempted.');
        }
        $request_generation = $remote['request_generation'] ?? null;
        $operation_count = $remote['operation_count'] ?? null;
        if (!is_int($request_generation) || $request_generation < 0) {
            throw new RuntimeException('The staged session push response reported an invalid request_generation.');
        }
        if (!is_int($operation_count) || $operation_count < (int) $request_start['merge']['operation_count']) {
            throw new RuntimeException('The staged session push response reported an invalid operation_count.');
        }
        $operation_delta = $operation_count - (int) $request_start['merge']['operation_count'];
        $maximum_operation_delta = $actual_frames_sent ?? $frames_attempted;
        if ($operation_delta > $maximum_operation_delta) {
            throw new RuntimeException(
                'The staged session push response advanced by ' . $operation_delta
                . ' operations after this request sent only ' . $maximum_operation_delta
                . ( $maximum_operation_delta === 1 ? ' frame.' : ' frames.' )
            );
        }
        $current_file = $this->normalize_remote_current_file($remote['current_file'] ?? null);
        $state['request_generation'] = $request_generation;
        $state['remote_phase'] = isset($remote['phase']) ? $remote['phase'] : 'uploading';
        $state['merge'] = $request_start['merge'];
        $state['catch_up'] = [
            'operation_count' => $operation_count,
            'current_file' => $current_file,
        ];
        $this->write_state($state);
        $this->remote_state_is_confirmed = true;
    }

    /** @param array<string,mixed> $state */
    private function advance_catch_up(array &$state): bool {
        $catch_up = $state['catch_up'];
        $target_operation_count = (int) $catch_up['operation_count'];
        $current_handle = $this->open_pinned_index($this->current_index_file, $state['current_index_identity'], 'the target-relative current index');
        $baseline_handle = $state['baseline_identity'] === null
            ? null
            : $this->open_pinned_index($this->push_journal->local_files_baseline_path, $state['baseline_identity'], 'the local push baseline');
        $output_handle = $this->open_next_baseline( (int) $state['merge']['output_offset']);
        $working = $state['merge'];
        $budget = ['bytes' => 0, 'lines' => 0];
        try {
            while (
                $working['operation_count'] < $target_operation_count
                && $budget['lines'] < self::LOCAL_STEP_LINES
                && $budget['bytes'] < self::LOCAL_STEP_BYTES
            ) {
                $action = $this->next_merge_action($working, $current_handle, $baseline_handle, $output_handle, $budget);
                if ($action === null) {
                    throw new RuntimeException('The target confirmed more operations than the pinned index merge contains.');
                }
                $this->consume_merge_action($working, $action, $action['type'] === 'operation');
            }
            if ($working['operation_count'] < $target_operation_count) {
                $state['merge'] = $working;
                $this->write_state($state);
                return false;
            }

            $remote_current_file = $catch_up['current_file'];
            if ($remote_current_file !== null) {
                while ($budget['lines'] < self::LOCAL_STEP_LINES && $budget['bytes'] < self::LOCAL_STEP_BYTES) {
                    $action = $this->next_merge_action($working, $current_handle, $baseline_handle, $output_handle, $budget);
                    if ($action === null) {
                        throw new RuntimeException('The target reported a partial file after the local merge reached end of input.');
                    }
                    if ($action['type'] === 'unchanged') {
                        $this->consume_merge_action($working, $action, false);
                        continue;
                    }
                    if ($action['operation_type'] !== 'file') {
                        throw new RuntimeException('The target reported a partial file where the pinned merge has a ' . $action['operation_type'] . ' operation.');
                    }
                    $path = $this->pending_path($action['entry']);
                    if (
                        $remote_current_file['operation_index'] !== $working['operation_count']
                        || $remote_current_file['path'] !== $path
                    ) {
                        throw new RuntimeException('The target partial-file cursor does not match the pinned merge operation.');
                    }
                    $working['active_file'] = $this->recover_remote_active_file($state, $action, $remote_current_file);
                    break;
                }
                if (!is_array($working['active_file'] ?? null)) {
                    $state['merge'] = $working;
                    $this->write_state($state);
                    return false;
                }
            } else {
                $working['active_file'] = null;
            }

            $state['merge'] = $working;
            $state['catch_up'] = null;
            $state['request_start'] = null;
            $state['request_progress_file'] = null;
            $this->write_state($state);
            return true;
        } finally {
            fclose($current_handle);
            if (is_resource($baseline_handle)) {
                fclose($baseline_handle);
            }
            fclose($output_handle);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $action
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    private function recover_remote_active_file(array $state, array $action, array $remote): array {
        $path = $this->pending_path($action['entry']);
        $identity = $this->source_file_identity($path);
        $progress = $state['request_progress_file'] ?? null;
        if (
            is_array($progress)
            && ( $progress['operation_index'] ?? null ) === $remote['operation_index']
            && ( $progress['path_b64'] ?? null ) === base64_encode($path)
            && ( $progress['revision'] ?? null ) === $remote['revision']
            && ( $progress['total_bytes'] ?? null ) === $remote['total_bytes']
            && is_array($progress['source_identity'] ?? null)
            && $this->same_source_file_identity($progress['source_identity'], $identity)
        ) {
            return [
                'operation_index' => $remote['operation_index'],
                'path_b64' => base64_encode($path),
                'revision' => $remote['revision'],
                'offset' => $remote['committed_bytes'],
                'total_bytes' => $remote['total_bytes'],
                'source_identity' => $identity,
                'restart' => false,
            ];
        }

        $entry = $action['entry']['entry'];
        if (
            $remote['revision'] === 0
            && $identity['size'] === $entry['size']
            && $identity['ctime'] === $entry['ctime']
            && $remote['total_bytes'] === $identity['size']
        ) {
            return [
                'operation_index' => $remote['operation_index'],
                'path_b64' => base64_encode($path),
                'revision' => 0,
                'offset' => $remote['committed_bytes'],
                'total_bytes' => $remote['total_bytes'],
                'source_identity' => $identity,
                'restart' => false,
            ];
        }

        return [
            'operation_index' => $remote['operation_index'],
            'path_b64' => base64_encode($path),
            'revision' => $remote['revision'] === PHP_INT_MAX ? 0 : $remote['revision'] + 1,
            'offset' => 0,
            'total_bytes' => $identity['size'],
            'source_identity' => $identity,
            'restart' => true,
        ];
    }

    /**
     * Return the next merge decision. Unchanged decisions still matter:
     * consuming them advances the durable baseline-copy cursor.
     *
     * @param array<string,mixed> $merge
     * @param resource $current_handle
     * @param resource|null $baseline_handle
     * @param resource $output_handle
     * @param array{bytes:int,lines:int} $budget
     * @return array<string,mixed>|null
     */
    private function next_merge_action(array &$merge, $current_handle, $baseline_handle, $output_handle, array &$budget): ?array {
        $this->load_pending_entry('current', $merge, $current_handle, $output_handle, $budget);
        $this->load_pending_entry('baseline', $merge, $baseline_handle, null, $budget);
        $current = $merge['current_pending'];
        $baseline = $merge['baseline_pending'];
        if ($current === null && $baseline === null) {
            return null;
        }
        if ($baseline === null) {
            $order = -1;
        } elseif ($current === null) {
            $order = 1;
        } else {
            $order = strcmp($this->pending_path($current), $this->pending_path($baseline));
        }
        if ($order < 0) {
            return $this->materialization_action($current, 'current');
        }
        if ($order > 0) {
            return [
                'type' => 'operation',
                'operation_type' => 'delete',
                'entry' => $baseline,
                'consume' => 'baseline',
            ];
        }
        if ($current['entry'] == $baseline['entry']) {
            return [
                'type' => 'unchanged',
                'entry' => $current,
                'consume' => 'both',
            ];
        }
        return $this->materialization_action($current, 'both');
    }

    /** @return array<string,mixed> */
    private function materialization_action(array $entry, string $consume): array {
        $index_type = $entry['entry']['type'];
        $operation_type = $index_type === 'dir' ? 'directory' : ( $index_type === 'link' ? 'symlink' : 'file' );
        return [
            'type' => 'operation',
            'operation_type' => $operation_type,
            'entry' => $entry,
            'consume' => $consume,
        ];
    }

    /** @param array<string,mixed> $merge */
    private function consume_merge_action(array &$merge, array $action, bool $operation): void {
        if ($action['consume'] === 'current' || $action['consume'] === 'both') {
            $merge['current_pending'] = null;
        }
        if ($action['consume'] === 'baseline' || $action['consume'] === 'both') {
            $merge['baseline_pending'] = null;
        }
        if (!$operation) {
            return;
        }
        ++$merge['operation_count'];
        if ($action['operation_type'] === 'delete') {
            ++$merge['deleted'];
        } else {
            ++$merge['changed'];
        }
        $merge['active_file'] = null;
    }

    /**
     * @param resource|null $input_handle
     * @param resource|null $output_handle
     * @param array<string,mixed> $merge
     * @param array{bytes:int,lines:int} $budget
     */
    private function load_pending_entry(
        string $side,
        array &$merge,
        $input_handle,
        $output_handle,
        array &$budget
    ): void {
        $pending_name = $side . '_pending';
        $eof_name = $side . '_eof';
        if ($merge[$pending_name] !== null || $merge[$eof_name]) {
            return;
        }
        if (!is_resource($input_handle)) {
            $merge[$eof_name] = true;
            return;
        }
        $offset_name = $side . '_offset';
        if (ftell($input_handle) !== $merge[$offset_name] && fseek($input_handle, $merge[$offset_name], SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek the ' . $side . ' index to byte offset ' . $merge[$offset_name] . '.');
        }
        $raw_line = fgets($input_handle, self::INDEX_MAX_LINE_BYTES + 2);
        if ($raw_line === false) {
            if (!feof($input_handle)) {
                throw new RuntimeException('Failed to read the ' . $side . ' index before reaching end of file.');
            }
            $merge[$eof_name] = true;
            return;
        }
        $has_line_feed = substr($raw_line, -1) === "\n";
        $content_bytes = strlen($raw_line) - ( $has_line_feed ? 1 : 0 );
        if ($content_bytes > self::INDEX_MAX_LINE_BYTES || ( !$has_line_feed && !feof($input_handle) )) {
            throw new StagedApplySessionInputInvalid('The ' . $side . ' index has a line longer than ' . self::INDEX_MAX_LINE_BYTES . ' bytes at offset ' . $merge[$offset_name] . '.');
        }
        $entry = $this->decode_index_line($raw_line, $side . ' index');
        $path = $this->pending_path($entry);
        $previous_path = $merge[$side . '_previous_path_b64'] === null
            ? null
            : base64_decode($merge[$side . '_previous_path_b64'], true);
        if ($previous_path !== null && strcmp($previous_path, $path) >= 0) {
            throw new StagedApplySessionInputInvalid('The ' . $side . ' index is not strictly sorted by decoded path at ' . $this->describe_path($path) . '.');
        }
        if ($side === 'current') {
            if (!is_resource($output_handle)) {
                throw new RuntimeException('The current index cannot advance without the next-baseline output.');
            }
            $this->write_all($output_handle, $raw_line, 'the next local push baseline');
            if (!fflush($output_handle)) {
                throw new RuntimeException('Could not flush the next local push baseline.');
            }
            $merge['output_offset'] += strlen($raw_line);
        }
        $next_offset = ftell($input_handle);
        if (!is_int($next_offset) || $next_offset < 0) {
            throw new RuntimeException('Could not determine the ' . $side . ' index cursor.');
        }
        $merge[$offset_name] = $next_offset;
        $merge[$pending_name] = $entry;
        $merge[$side . '_previous_path_b64'] = base64_encode($path);
        $budget['bytes'] += strlen($raw_line);
        ++$budget['lines'];
    }

    /** @return array<string,mixed> */
    private function decode_index_line(string $raw_line, string $source): array {
        try {
            $value = json_decode($raw_line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' line is not valid JSON: ' . substr($raw_line, 0, 120) . '.', 0, $exception);
        }
        if (!is_array($value) || !is_string($value['path'] ?? null)) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' line has no base64 string path: ' . substr($raw_line, 0, 120) . '.');
        }
        $path = base64_decode($value['path'], true);
        if ($path === false || $path === '') {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' line has an invalid base64 path: ' . substr($raw_line, 0, 120) . '.');
        }
        $this->assert_target_relative_path($path, $source . ' path');
        if (!is_int($value['ctime'] ?? null) || $value['ctime'] < 0) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' entry for ' . $this->describe_path($path) . ' has an invalid ctime.');
        }
        if (!is_int($value['size'] ?? null) || $value['size'] < 0) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' entry for ' . $this->describe_path($path) . ' has an invalid size.');
        }
        if (!in_array($value['type'] ?? null, ['file', 'dir', 'link'], true)) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' entry for ' . $this->describe_path($path) . ' has an invalid type.');
        }
        if ($value['type'] !== 'file' && $value['size'] !== 0) {
            throw new StagedApplySessionInputInvalid('The ' . $source . ' non-file entry for ' . $this->describe_path($path) . ' must have size 0.');
        }
        return [
            'entry' => $value,
        ];
    }

    private function pending_path(array $pending): string {
        $path = base64_decode($pending['entry']['path'], true);
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('A persisted merge entry has an invalid path.');
        }
        return $path;
    }

    /** @param array<string,mixed> $merge @param array<string,mixed> $state */
    private function finish_merge_input(array &$merge, $current_handle, $baseline_handle, $output_handle, array $state): void {
        if ($merge['current_pending'] !== null || $merge['baseline_pending'] !== null) {
            throw new RuntimeException('Cannot finish the local index merge while an entry is pending.');
        }
        if ($this->opened_file_identity($current_handle, 'the target-relative current index') !== $state['current_index_identity']) {
            throw new StagedApplySessionSourceTreeChanged('The pinned target-relative current index changed during its final merge step.');
        }
        if (
            $state['baseline_identity'] !== null
            && (
                !is_resource($baseline_handle)
                || $this->opened_file_identity($baseline_handle, 'the local push baseline') !== $state['baseline_identity']
            )
        ) {
            throw new StagedApplySessionSourceTreeChanged('The pinned local push baseline changed during its final merge step.');
        }
        if (!fflush($output_handle)) {
            throw new RuntimeException('Could not flush the complete next local push baseline.');
        }
        $merge['input_complete'] = true;
        $merge['next_baseline_identity'] = $this->opened_rename_stable_file_identity($output_handle, 'the complete next local push baseline');
    }

    /** @return array<string,mixed> */
    private function new_merge_state(bool $baseline_missing): array {
        return [
            'current_offset' => 0,
            'baseline_offset' => 0,
            'output_offset' => 0,
            'current_pending' => null,
            'baseline_pending' => null,
            'current_eof' => false,
            'baseline_eof' => $baseline_missing,
            'current_previous_path_b64' => null,
            'baseline_previous_path_b64' => null,
            'operation_count' => 0,
            'changed' => 0,
            'deleted' => 0,
            'active_file' => null,
            'input_complete' => false,
            'next_baseline_identity' => null,
        ];
    }

    /** @param array<string,mixed> $state */
    private function publish_baseline(array &$state): void {
        if (( $state['baseline_published'] ?? false ) === true) {
            return;
        }
        $expected = $state['merge']['next_baseline_identity'] ?? null;
        if (!( $state['merge']['input_complete'] ?? false ) || !is_array($expected)) {
            throw new RuntimeException('The target completed before the next local push baseline was finished.');
        }
        if (is_file($this->next_baseline_path)) {
            $this->assert_rename_stable_file_identity($this->next_baseline_path, $expected, 'the next local push baseline');
            if (!rename($this->next_baseline_path, $this->push_journal->local_files_baseline_path)) {
                throw new RuntimeException('Could not publish the completed local push baseline.');
            }
        } else {
            // A rename may have landed just before the process died. Rename
            // preserves these fields even on filesystems that update ctime.
            $this->assert_rename_stable_file_identity($this->push_journal->local_files_baseline_path, $expected, 'the published local push baseline');
        }
        $state['baseline_published'] = true;
        $this->write_state($state);
    }

    /**
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function finish_completed_discard(array &$state): array {
        if (( $state['discard_pending'] ?? false ) !== true) {
            $state['discard_pending'] = true;
            $state['discard_needs_status'] = false;
            $this->write_state($state);
        }
        $discarded = $this->session_control('staged_session_discard', 'POST', $state, true);
        if ($discarded['outcome'] === 'success') {
            $body = $discarded['body'];
            if (( $body['status'] ?? null ) === 'discarded') {
                $this->remove_local_session_files();
                return $this->result('complete', null, null, null, $state);
            }
            if (( $body['status'] ?? null ) === 'discarding') {
                if (is_int($body['request_generation'] ?? null)) {
                    $state['request_generation'] = $body['request_generation'];
                } else {
                    $this->remote_state_is_confirmed = false;
                }
                $state['remote_phase'] = 'discarding';
                $this->write_state($state);
                return $this->result('retry', 'discard_pending', null, $state['session_id'], $state);
            }
            throw new RuntimeException('The staged session discard response reported invalid status ' . json_encode($body['status'] ?? null) . '.');
        }
        if ($this->session_is_gone($discarded)) {
            $this->remove_local_session_files();
            return $this->result('complete', null, null, null, $state);
        }
        if ($discarded['outcome'] === 'retry' && ( $discarded['reason'] ?? null ) !== 'discard_pending') {
            $state['discard_needs_status'] = true;
            $this->write_state($state);
            $this->remote_state_is_confirmed = false;
        }
        return $this->control_result($discarded, $state);
    }

    /**
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function finish_marked_discard(array &$state): array {
        if (( $state['discard_pending'] ?? false ) !== true) {
            $state['discard_pending'] = true;
            $state['discard_needs_status'] = false;
            $this->write_state($state);
        }
        $discarded = $this->session_control('staged_session_discard', 'POST', $state, true);
        if ($discarded['outcome'] === 'success') {
            $body = $discarded['body'];
            if (( $body['status'] ?? null ) === 'discarded') {
                $reason = $state['discard_reason'];
                $this->remove_local_session_files();
                return $this->result('failed', $reason['reason'], $reason['detail'], null, $state);
            }
            if (( $body['status'] ?? null ) === 'discarding') {
                if (is_int($body['request_generation'] ?? null)) {
                    $state['request_generation'] = $body['request_generation'];
                } else {
                    $this->remote_state_is_confirmed = false;
                }
                $state['remote_phase'] = 'discarding';
                $this->write_state($state);
                return $this->result('retry', 'discard_pending', null, $state['session_id'], $state);
            }
            throw new RuntimeException('The staged session discard response reported invalid status ' . json_encode($body['status'] ?? null) . '.');
        }
        if ($this->session_is_gone($discarded)) {
            $reason = $state['discard_reason'];
            $this->remove_local_session_files();
            return $this->result('failed', $reason['reason'], $reason['detail'], null, $state);
        }
        if ($discarded['outcome'] === 'retry' && ( $discarded['reason'] ?? null ) !== 'discard_pending') {
            $state['discard_needs_status'] = true;
            $this->write_state($state);
            $this->remote_state_is_confirmed = false;
        }
        return $this->result(
            $discarded['outcome'] === 'retry' ? 'retry' : 'failed',
            $discarded['outcome'] === 'retry' ? 'discard_pending' : ( $discarded['reason'] ?? 'discard_failed' ),
            $discarded['detail'] ?? null,
            $state['session_id'],
            $state
        );
    }

    /**
     * A lost discard response may leave either the main session with a newer
     * generation or a generation-less cleanup tombstone. One status request
     * distinguishes them without declaring a 404 complete: a found main
     * session refreshes the generation, while a 404 schedules another direct
     * discard call, whose tombstone path does not inspect the old generation.
     *
     * @param array<string,mixed> $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function refresh_pending_discard(array &$state): array {
        $status = $this->session_control('staged_session_status', 'GET', $state);
        if ($status['outcome'] === 'success') {
            $body = $status['body'];
            if (( $body['session_id'] ?? null ) !== $state['session_id']) {
                throw new RuntimeException('The staged session discard status named a different session_id.');
            }
            $request_generation = $body['request_generation'] ?? null;
            $phase = $body['phase'] ?? null;
            if (!is_int($request_generation) || $request_generation < 0) {
                throw new RuntimeException('The staged session discard status reported an invalid request_generation.');
            }
            if (!is_string($phase) || !in_array($phase, ['uploading', 'committing', 'complete', 'failed', 'discarding'], true)) {
                throw new RuntimeException('The staged session discard status reported an invalid phase ' . json_encode($phase) . '.');
            }
            $state['request_generation'] = $request_generation;
            $state['remote_phase'] = $phase;
            $state['discard_needs_status'] = false;
            $this->write_state($state);
            $this->remote_state_is_confirmed = true;
            return $this->result('retry', 'discard_pending', null, $state['session_id'], $state);
        }
        if ($this->session_is_gone($status)) {
            // Status cannot open .discarding-* workspaces. Do not infer that
            // cleanup completed; the next bounded call advances discard.
            $state['discard_needs_status'] = false;
            $this->write_state($state);
            return $this->result('retry', 'discard_pending', null, $state['session_id'], $state);
        }
        return $this->control_result($status, $state);
    }

    /**
     * @param array<string,mixed>|null $state
     * @param array<string,string> $extra_parameters
     * @return array{outcome:string,body?:array<string,mixed>,reason?:?string,detail?:?string,http_code?:int}
     */
    private function session_control(
        string $endpoint,
        string $method,
        ?array $state = null,
        bool $mutating = false,
        array $extra_parameters = []
    ): array {
        $parameters = array_merge(['endpoint' => $endpoint], $extra_parameters);
        if ($state !== null) {
            $parameters['session_id'] = $state['session_id'];
        }
        if ($mutating) {
            if ($state === null || !is_int($state['request_generation']) || $state['request_generation'] < 0) {
                throw new RuntimeException('Cannot send ' . $endpoint . ' without a server-confirmed non-negative request generation.');
            }
            $parameters['expected_request_generation'] = $state['request_generation'];
        }
        $url = $this->endpoint_url($parameters);
        $headers = $this->hmac_client->get_envelope_auth_headers($method, $url);
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        if ($this->control_handle === null) {
            $this->control_handle = curl_init($url);
        } else {
            curl_reset($this->control_handle);
            curl_setopt($this->control_handle, CURLOPT_URL, $url);
        }
        if ($this->control_handle === false || $this->control_handle === null) {
            return ['outcome' => 'retry', 'reason' => 'request_failed', 'detail' => 'Could not initialize cURL for ' . $endpoint . '.'];
        }
        $handle = $this->control_handle;
        if (function_exists('reprint_apply_curl_proxy_from_env')) {
            reprint_apply_curl_proxy_from_env($handle);
        }
        if (function_exists('reprint_apply_curl_ca_bundle')) {
            reprint_apply_curl_ca_bundle($handle);
        }
        $response = '';
        $response_too_large = false;
        $curl_options = [
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_WRITEFUNCTION => function ($curl_handle, string $bytes) use (&$response, &$response_too_large): int {
                if (strlen($response) + strlen($bytes) > self::CONTROL_RESPONSE_MAX_BYTES) {
                    $response_too_large = true;
                    return 0;
                }
                $response .= $bytes;
                return strlen($bytes);
            },
        ];
        if ($method === 'POST') {
            $curl_options[CURLOPT_POST] = true;
            $curl_options[CURLOPT_POSTFIELDS] = '';
        } else {
            $curl_options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($handle, $curl_options);
        if ($this->control_multi_handle === null) {
            $this->control_multi_handle = curl_multi_init();
        }
        curl_multi_add_handle($this->control_multi_handle, $handle);
        $done = false;
        $curl_result = CURLE_OK;
        do {
            do {
                $multi_status = curl_multi_exec($this->control_multi_handle, $running);
            } while ($multi_status === CURLM_CALL_MULTI_PERFORM);
            while (true) {
                $message = curl_multi_info_read($this->control_multi_handle);
                if ($message === false) {
                    break;
                }
                if ($message['msg'] === CURLMSG_DONE) {
                    $done = true;
                    $curl_result = (int) $message['result'];
                    break;
                }
            }
            if (!$done) {
                $selected = curl_multi_select($this->control_multi_handle, 1.0);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while (!$done);
        $error = curl_error($handle);
        $http_code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $redirect_url = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
        curl_multi_remove_handle($this->control_multi_handle, $handle);

        if (in_array($http_code, [301, 302, 303, 307, 308], true)) {
            return [
                'outcome' => 'failed',
                'reason' => 'redirected',
                'detail' => $redirect_url !== ''
                    ? 'The target redirected to ' . json_encode($redirect_url) . '. Use that address as the push base_url.'
                    : 'The target answered HTTP ' . $http_code . ' without a Location header.',
                'http_code' => $http_code,
            ];
        }
        if ($response_too_large) {
            return [
                'outcome' => 'failed',
                'reason' => 'control_response_too_large',
                'detail' => 'The ' . $endpoint . ' response exceeded the bounded ' . self::CONTROL_RESPONSE_MAX_BYTES . '-byte control response limit.',
                'http_code' => $http_code,
            ];
        }
        if ($curl_result !== CURLE_OK) {
            return [
                'outcome' => 'retry',
                'reason' => 'request_failed',
                'detail' => $error !== '' ? $error : 'No response arrived from ' . $endpoint . '.',
                'http_code' => $http_code,
            ];
        }
        $body = json_decode($response, true);
        if (!is_array($body)) {
            return [
                'outcome' => $http_code >= 500 ? 'retry' : 'failed',
                'reason' => 'invalid_response',
                'detail' => 'Expected JSON from ' . $endpoint . ' (HTTP ' . $http_code . '); received ' . substr($response, 0, 120) . '.',
                'http_code' => $http_code,
            ];
        }
        if ($http_code >= 200 && $http_code < 300) {
            return ['outcome' => 'success', 'body' => $body, 'http_code' => $http_code];
        }
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : 'request_rejected';
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : 'HTTP ' . $http_code;
        return [
            'outcome' => (
                in_array($reason, ['busy', 'discard_pending', 'stale_session_state', 'offset_gap'], true)
                || ( $http_code >= 500 && !in_array($reason, ['apply_not_configured', 'apply_storage_not_configured', 'not_configured'], true) )
            ) ? 'retry' : 'failed',
            'reason' => $reason,
            'detail' => $detail,
            'http_code' => $http_code,
        ];
    }

    /** @param array<string,string|int> $parameters */
    private function endpoint_url(array $parameters): string {
        return $this->base_url
            . ( strpos($this->base_url, '?') === false ? '?' : '&' )
            . http_build_query($parameters);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $body
     */
    private function adopt_remote_state(array &$state, array $body): void {
        if (( $body['session_id'] ?? null ) !== $state['session_id']) {
            throw new RuntimeException('The staged session response named a different session_id.');
        }
        $phase = $body['phase'] ?? null;
        if (!is_string($phase) || !in_array($phase, ['uploading', 'committing', 'complete', 'failed', 'discarding'], true)) {
            throw new RuntimeException('The staged session response reported an invalid phase ' . json_encode($phase) . '.');
        }
        $request_generation = $body['request_generation'] ?? null;
        if (!is_int($request_generation) || $request_generation < 0) {
            throw new RuntimeException('The staged session response reported an invalid request_generation.');
        }
        if ($phase === 'uploading') {
            if (is_array($state['request_start'] ?? null)) {
                $this->begin_catch_up($state, $body);
                return;
            }
            $operation_count = $body['operation_count'] ?? null;
            if (!is_int($operation_count) || $operation_count !== (int) $state['merge']['operation_count']) {
                throw new RuntimeException('The staged session status operation_count does not match the persisted local cursor.');
            }
            $remote_current = $this->normalize_remote_current_file($body['current_file'] ?? null);
            $local_active = $state['merge']['active_file'] ?? null;
            if (( $remote_current === null ) !== ( $local_active === null )) {
                throw new RuntimeException('The staged session partial-file status does not match the persisted local cursor.');
            }
            if ($remote_current !== null) {
                $local_path = base64_decode( (string) $local_active['path_b64'], true);
                if (
                    $remote_current['operation_index'] !== $local_active['operation_index']
                    || $remote_current['path'] !== $local_path
                    || $remote_current['revision'] !== $local_active['revision']
                    || $remote_current['total_bytes'] !== $local_active['total_bytes']
                ) {
                    throw new RuntimeException('The staged session partial-file status names a different local file revision.');
                }
                $state['merge']['active_file']['offset'] = $remote_current['committed_bytes'];
            }
        } elseif (in_array($phase, ['committing', 'complete'], true) && !( $state['merge']['input_complete'] ?? false )) {
            throw new RuntimeException('The target left uploading before the pinned local index merge completed.');
        }
        $state['remote_phase'] = $phase;
        $state['request_generation'] = $request_generation;
    }

    /** @return array<string,mixed>|null */
    private function normalize_remote_current_file($value): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new RuntimeException('The staged session response reported an invalid current_file cursor.');
        }
        if (is_string($value['path'] ?? null)) {
            $path = $value['path'];
        } else {
            $path = base64_decode( (string) ( $value['path_b64'] ?? '' ), true);
        }
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('The staged session response current_file has an invalid path.');
        }
        $operation_index = $value['operation_index'] ?? null;
        $revision = $value['revision'] ?? null;
        $committed_bytes = $value['committed_bytes'] ?? null;
        $total_bytes = $value['total_bytes'] ?? null;
        if (
            !is_int($operation_index) || $operation_index < 0
            || !is_int($revision) || $revision < 0
            || !is_int($committed_bytes) || $committed_bytes < 0
            || !is_int($total_bytes) || $total_bytes < 0
            || $committed_bytes > $total_bytes
        ) {
            throw new RuntimeException('The staged session response current_file has invalid numeric cursors.');
        }
        return [
            'operation_index' => $operation_index,
            'path' => $path,
            'revision' => $revision,
            'committed_bytes' => $committed_bytes,
            'total_bytes' => $total_bytes,
        ];
    }

    /** @param array{outcome:string,reason?:?string,http_code?:int} $control */
    private function session_is_gone(array $control): bool {
        return $control['outcome'] === 'failed'
            && ( $control['http_code'] ?? null ) === 404
            && ( $control['reason'] ?? null ) === 'session_not_found';
    }

    /**
     * @param array{outcome:string,reason?:?string,detail?:?string} $control
     * @param array<string,mixed>|null $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function control_result(array $control, ?array $state): array {
        return $this->result(
            $control['outcome'] === 'retry' ? 'retry' : 'failed',
            $control['reason'] ?? 'request_failed',
            $control['detail'] ?? null,
            $state['session_id'] ?? null,
            $state
        );
    }

    /**
     * @param array<string,mixed>|null $state
     * @return array{status:string,reason:?string,detail:?string,session_id:?string,changed:int,deleted:int}
     */
    private function result(string $status, ?string $reason, ?string $detail, ?string $session_id, ?array $state): array {
        $merge = is_array($state['merge'] ?? null) ? $state['merge'] : [];
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'session_id' => $session_id,
            'changed' => (int) ( $merge['changed'] ?? 0 ),
            'deleted' => (int) ( $merge['deleted'] ?? 0 ),
        ];
    }

    /** @param array<string,mixed> $state */
    private function local_work_pending(array $state): array {
        return $this->result('retry', 'local_work_pending', null, $state['session_id'], $state);
    }

    /** @param array<string,mixed> $state */
    private function remote_work_pending(array $state): array {
        return $this->result('retry', 'remote_work_pending', null, $state['session_id'], $state);
    }

    /** @param array<string,mixed> $owner */
    private function assert_pinned_inputs(array $owner): void {
        $this->assert_file_identity($this->current_index_file, $owner['current_index_identity'], 'the target-relative current index');
        if ($owner['baseline_identity'] === null) {
            if (is_file($this->push_journal->local_files_baseline_path)) {
                throw new StagedApplySessionSourceTreeChanged('The local push baseline appeared after this session pinned an empty baseline.');
            }
            return;
        }
        $this->assert_file_identity($this->push_journal->local_files_baseline_path, $owner['baseline_identity'], 'the local push baseline');
    }

    /** @return array{dev:int,ino:int,size:int,ctime:int,mtime:int} */
    private function file_identity(string $path, string $description): array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ( (int) $stat['mode'] & 0170000 ) !== 0100000) {
            throw new RuntimeException('Could not stat ' . $description . ': ' . $path . '.');
        }
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
            'mtime' => (int) $stat['mtime'],
        ];
    }

    private function assert_file_identity(string $path, array $expected, string $description): void {
        try {
            $actual = $this->file_identity($path, $description);
        } catch (RuntimeException $exception) {
            throw new StagedApplySessionSourceTreeChanged($exception->getMessage(), 0, $exception);
        }
        if ($actual !== $expected) {
            throw new StagedApplySessionSourceTreeChanged('The pinned ' . $description . ' changed while the staged apply was in progress.');
        }
    }

    /** @return resource */
    private function open_pinned_index(string $path, array $identity, string $description) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new StagedApplySessionSourceTreeChanged('Could not open ' . $description . ': ' . $path . '.');
        }
        $opened = $this->opened_file_identity($handle, $description);
        if ($opened !== $identity) {
            fclose($handle);
            throw new StagedApplySessionSourceTreeChanged('The pinned ' . $description . ' changed while it was being opened.');
        }
        return $handle;
    }

    /** @param resource $handle @return array{dev:int,ino:int,size:int,ctime:int,mtime:int} */
    private function opened_file_identity($handle, string $description): array {
        $stat = fstat($handle);
        if (!is_array($stat) || ( (int) ( $stat['mode'] ?? 0 ) & 0170000 ) !== 0100000) {
            throw new RuntimeException('Could not stat ' . $description . ' through its open handle.');
        }
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
            'mtime' => (int) $stat['mtime'],
        ];
    }

    /** @param resource $handle @return array{dev:int,ino:int,size:int,mtime:int} */
    private function opened_rename_stable_file_identity($handle, string $description): array {
        $identity = $this->opened_file_identity($handle, $description);
        return [
            'dev' => $identity['dev'],
            'ino' => $identity['ino'],
            'size' => $identity['size'],
            'mtime' => $identity['mtime'],
        ];
    }

    /** @param array{dev:int,ino:int,size:int,mtime:int} $expected */
    private function assert_rename_stable_file_identity(string $path, array $expected, string $description): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ( (int) $stat['mode'] & 0170000 ) !== 0100000) {
            throw new RuntimeException('Could not stat ' . $description . ': ' . $path . '.');
        }
        $actual = [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
        ];
        if ($actual !== $expected) {
            throw new RuntimeException('The ' . $description . ' does not match the completed next-baseline file.');
        }
    }

    /** @return array{dev:int,ino:int,size:int,ctime:int} */
    private function source_file_identity(string $path): array {
        $this->assert_source_ancestors($path);
        $source_path = $this->source_path($path);
        clearstatcache(true, $source_path);
        $stat = @lstat($source_path);
        if ($stat === false) {
            throw new StagedApplySessionSourceTreeChanged('The source file ' . $this->describe_path($path) . ' was deleted.');
        }
        if (( (int) $stat['mode'] & 0170000 ) !== 0100000) {
            throw new StagedApplySessionSourceTreeChanged('The source path ' . $this->describe_path($path) . ' is no longer a regular file.');
        }
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    /** @param resource $handle @return array{dev:int,ino:int,size:int,ctime:int} */
    private function opened_source_file_identity($handle, string $path): array {
        $stat = fstat($handle);
        if (!is_array($stat) || ( (int) ( $stat['mode'] ?? 0 ) & 0170000 ) !== 0100000) {
            throw new StagedApplySessionSourceTreeChanged('The opened source path ' . $this->describe_path($path) . ' is no longer a regular file.');
        }
        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    private function same_source_file_identity(array $first, array $second): bool {
        return $first['dev'] === $second['dev']
            && $first['ino'] === $second['ino']
            && $first['size'] === $second['size']
            && $first['ctime'] === $second['ctime'];
    }

    private function assert_source_type(string $path, string $expected_type): void {
        $this->assert_source_ancestors($path);
        $source_path = $this->source_path($path);
        clearstatcache(true, $source_path);
        $stat = @lstat($source_path);
        if ($stat === false) {
            throw new StagedApplySessionSourceTreeChanged('The source path ' . $this->describe_path($path) . ' was deleted.');
        }
        $mode = (int) $stat['mode'] & 0170000;
        $matches = ( $expected_type === 'dir' && $mode === 0040000 )
            || ( $expected_type === 'link' && $mode === 0120000 );
        if (!$matches) {
            throw new StagedApplySessionSourceTreeChanged('The source path ' . $this->describe_path($path) . ' changed type; expected ' . $expected_type . '.');
        }
    }

    private function assert_source_ancestors(string $path): void {
        $segments = explode('/', $path);
        array_pop($segments);
        $ancestor = $this->source_root;
        foreach ($segments as $segment) {
            $ancestor = ( $ancestor === '/' ? '' : $ancestor ) . '/' . $segment;
            clearstatcache(true, $ancestor);
            $stat = @lstat($ancestor);
            if ($stat === false || ( (int) $stat['mode'] & 0170000 ) !== 0040000) {
                throw new StagedApplySessionSourceTreeChanged(
                    'The source path ' . $this->describe_path($path) . ' has a missing or non-directory ancestor ' . $this->describe_path(substr($ancestor, strlen($this->source_root) + 1)) . '.'
                );
            }
        }
    }

    private function source_path(string $target_relative_path): string {
        return ( $this->source_root === '/' ? '' : $this->source_root ) . '/' . $target_relative_path;
    }

    private function assert_target_relative_path(string $path, string $label): void {
        if (
            $path === ''
            || strlen($path) > self::MAX_PATH_BYTES
            || $path[0] === '/'
            || strpos($path, "\0") !== false
            || strpos($path, '\\') !== false
        ) {
            throw new StagedApplySessionInputInvalid('The ' . $label . ' is not a safe target-relative path: ' . $this->describe_path($path) . '.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StagedApplySessionInputInvalid('The ' . $label . ' has an empty, dot, or dot-dot segment: ' . $this->describe_path($path) . '.');
            }
        }
    }

    private function describe_path(string $path): string {
        return json_encode(base64_encode($path));
    }

    /** @return resource */
    private function open_next_baseline(int $offset) {
        $handle = @fopen($this->next_baseline_path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open the next local push baseline.');
        }
        if (ftruncate($handle, $offset) === false || fseek($handle, $offset, SEEK_SET) !== 0) {
            fclose($handle);
            throw new RuntimeException('Could not restore the next local push baseline to byte offset ' . $offset . '.');
        }
        return $handle;
    }

    private function truncate_next_baseline(int $offset): void {
        $handle = $this->open_next_baseline($offset);
        $this->finish_writing($handle, 'the restored next local push baseline');
    }

    /** @return array<string,mixed>|null */
    private function recover_promoted_session_state(): ?array {
        if (!is_file($this->creating_path)) {
            return null;
        }
        $contents = $this->read_local_state_contents($this->creating_path, 'local staged apply transition state');
        $value = json_decode($contents, true);
        if (!is_array($value) || !isset($value['session_id'])) {
            return null;
        }
        if (!rename($this->creating_path, $this->state_path)) {
            throw new RuntimeException('Could not finish promoting the local staged apply session state.');
        }
        return $this->read_state();
    }

    /** @param array<string,mixed> $state */
    private function promote_creating_state(array $state): void {
        $encoded = $this->encode_local_state($state, 'promoted staged apply session state');
        $temporary = $this->creating_path . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write the promoted staged apply session state.');
        }
        $this->write_all($handle, $encoded, 'the promoted staged apply session state');
        $this->finish_writing($handle, 'the promoted staged apply session state');
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->creating_path) || !rename($this->creating_path, $this->state_path)) {
            throw new RuntimeException('Could not atomically promote the local staged apply create state.');
        }
    }

    /** @return array<string,mixed>|null */
    private function read_creating_state(): ?array {
        if (!is_file($this->creating_path)) {
            return null;
        }
        $value = $this->decode_local_state($this->creating_path, 'local staged apply create state');
        if (isset($value['session_id'])) {
            return null;
        }
        $this->validate_common_local_state($value, 'create state');
        if (!is_string($value['create_token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $value['create_token']) !== 1) {
            throw new RuntimeException('The local staged apply create state has an invalid create_token.');
        }
        if (!is_array($value['current_index_identity'] ?? null) || !array_key_exists('baseline_identity', $value)) {
            throw new RuntimeException('The local staged apply create state has invalid pinned index identities.');
        }
        return $value;
    }

    /** @param array<string,mixed> $creating */
    private function write_creating_state(array $creating): void {
        $this->write_local_state_file($this->creating_path, $creating, 'local staged apply create state');
    }

    /** @return array<string,mixed>|null */
    private function read_state(): ?array {
        if (!is_file($this->state_path)) {
            return null;
        }
        $state = $this->decode_local_state($this->state_path, 'local staged apply session state');
        $this->validate_common_local_state($state, 'session state');
        if (!is_string($state['session_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['session_id']) !== 1) {
            throw new RuntimeException('The local staged apply session state has an invalid session_id.');
        }
        if (!is_int($state['request_generation'] ?? null) || $state['request_generation'] < 0) {
            throw new RuntimeException('The local staged apply session state has an invalid request_generation.');
        }
        if (!is_int($state['max_frame_bytes'] ?? null) || $state['max_frame_bytes'] <= 0) {
            throw new RuntimeException('The local staged apply session state has an invalid max_frame_bytes.');
        }
        if (
            !is_int($state['max_frames_per_request'] ?? null)
            || $state['max_frames_per_request'] <= 0
            || $state['max_frames_per_request'] > Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST
        ) {
            throw new RuntimeException('The local staged apply session state has an invalid max_frames_per_request.');
        }
        if (!is_array($state['merge'] ?? null)) {
            throw new RuntimeException('The local staged apply session state has no merge cursor.');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function write_state(array $state): void {
        $this->write_local_state_file($this->state_path, $state, 'local staged apply session state');
    }

    private function validate_common_local_state(array $state, string $description): void {
        if (( $state['version'] ?? null ) !== self::STATE_VERSION) {
            throw new RuntimeException('The local staged apply ' . $description . ' has an unsupported version.');
        }
        $source_root = base64_decode( (string) ( $state['source_root_b64'] ?? '' ), true);
        if ($source_root !== $this->source_root) {
            throw new RuntimeException('The local staged apply ' . $description . ' belongs to a different source_root.');
        }
    }

    /** @return array<string,mixed> */
    private function decode_local_state(string $path, string $description): array {
        $contents = $this->read_local_state_contents($path, $description);
        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The ' . $description . ' is not valid JSON: ' . $path . '.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('The ' . $description . ' is not a JSON object: ' . $path . '.');
        }
        return $value;
    }

    private function read_local_state_contents(string $path, string $description): string {
        $contents = @file_get_contents($path, false, null, 0, self::LOCAL_STATE_MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read the ' . $description . ': ' . $path . '.');
        }
        if (strlen($contents) > self::LOCAL_STATE_MAX_BYTES) {
            throw new RuntimeException('The ' . $description . ' exceeds ' . self::LOCAL_STATE_MAX_BYTES . ' bytes.');
        }
        return $contents;
    }

    /** @param array<string,mixed> $value */
    private function write_local_state_file(string $path, array $value, string $description): void {
        $this->ensure_work_dir();
        $encoded = $this->encode_local_state($value, $description);
        $temporary = $path . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write the ' . $description . ': ' . $temporary . '.');
        }
        $this->write_all($handle, $encoded, 'the ' . $description);
        $this->finish_writing($handle, 'the ' . $description);
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Could not move the ' . $description . ' into place: ' . $path . '.');
        }
    }

    /** @param array<string,mixed> $value */
    private function encode_local_state(array $value, string $description): string {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode the ' . $description . '.');
        }
        if (strlen($encoded) > self::LOCAL_STATE_MAX_BYTES) {
            throw new RuntimeException('The ' . $description . ' exceeds ' . self::LOCAL_STATE_MAX_BYTES . ' bytes.');
        }
        return $encoded;
    }

    /** @param resource $handle */
    private function write_all($handle, string $bytes, string $description): void {
        $offset = 0;
        $byte_count = strlen($bytes);
        while ($offset < $byte_count) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Writing ' . $description . ' made no progress.');
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    private function finish_writing($handle, string $description): void {
        $flushed = fflush($handle);
        $closed = fclose($handle);
        if (!$flushed || !$closed) {
            throw new RuntimeException('Could not finish writing ' . $description . '.');
        }
    }

    private function ensure_work_dir(): void {
        if (!is_dir($this->work_dir) && !@mkdir($this->work_dir, 0700, true) && !is_dir($this->work_dir)) {
            throw new RuntimeException('Could not create the local staged apply state directory: ' . $this->work_dir . '.');
        }
        @chmod($this->work_dir, 0700);
    }

    private function remove_local_session_files(): void {
        foreach ([
            $this->creating_path,
            $this->creating_path . '.tmp',
            $this->state_path,
            $this->state_path . '.tmp',
            $this->next_baseline_path,
        ] as $path) {
            if (( is_file($path) || is_link($path) ) && !@unlink($path)) {
                throw new RuntimeException('Could not remove local staged apply session file: ' . $path . '.');
            }
        }
    }

    private function acquire_local_lock(): void {
        $this->ensure_work_dir();
        $this->local_lock = @fopen($this->work_dir . '/session.lock', 'c+b');
        if ($this->local_lock === false) {
            throw new RuntimeException('Could not open the local staged apply session lock.');
        }
        if (!flock($this->local_lock, LOCK_EX | LOCK_NB)) {
            fclose($this->local_lock);
            $this->local_lock = null;
            throw new RuntimeException('Another local process is already driving this staged apply session.');
        }
    }

    private function release_local_lock(): void {
        if (!is_resource($this->local_lock)) {
            return;
        }
        flock($this->local_lock, LOCK_UN);
        fclose($this->local_lock);
        $this->local_lock = null;
    }
}

/** Malformed or unsorted pinned input that makes the upload discard-only. */
final class StagedApplySessionInputInvalid extends RuntimeException {
}

/** Source/index drift that requires the remote upload session to be discarded. */
final class StagedApplySessionSourceTreeChanged extends RuntimeException {
}
