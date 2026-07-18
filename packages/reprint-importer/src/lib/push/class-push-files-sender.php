<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sender failures are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Drives one local-files push through bounded planning and streaming requests.
 *
 * PushFilesSender joins two durable protocols. PushPlan selects local paths in
 * bounded steps and owns the local index from which that selection was made.
 * The receiver owns the upload cursor for every path and for the deletion list.
 * Durable sender state retains the push session phase, selected path-list
 * cursor, and learned request limits needed after a process restart.
 *
 * ## Usage
 *
 * A sender has the same explicit lifecycle as PushPlan:
 *
 *  1. Start a new sender with `start()`, or continue an unfinished sender with
 *     `resume()`. Both methods acquire the lifecycle lock.
 *  2. Call `next_step()` while the current process has enough time and memory
 *     for another bounded step.
 *  3. Call `close()` to release the lifecycle lock, even when more work remains.
 *
 * Example:
 *
 *     $sender = $first_run
 *         ? PushFilesSender::start($options)
 *         : PushFilesSender::resume($options);
 *     try {
 *         while ($has_time_remaining() && $has_memory_available()) {
 *             $result = $sender->next_step();
 *             if ($result['status'] !== 'continue') {
 *                 break;
 *             }
 *         }
 *     } finally {
 *         $sender->close();
 *     }
 *
 * Every `continue` result has published a durable boundary before returning.
 * The caller may therefore close after any step and use `resume()` in a later
 * process. If a process stops during a step, the next process starts
 * from the preceding durable boundary and reconciles any receiver-confirmed
 * work before sending more data. The lock remains held until close().
 *
 * A new push first calls `push_create` to learn receiver-owned exclusions, then
 * starts PushPlan with that policy. After planning completes, local files,
 * symlinks, and empty directories stream through multipart requests. The raw
 * deletion list follows, and repeated `push_commit` calls let the receiver
 * install the work in bounded steps. Only a receiver-confirmed commit publishes
 * the plan's fresh local index as the local index at the previous push.
 *
 * ## Resume after local changes
 *
 * Each selected path carries the type, size, and ctime from the fresh local
 * index used for planning. The sender compares the live path with those values
 * before sending and again after each read. A difference removes the
 * upload-only push session, because its work and the planned local index no
 * longer describe the same local tree.
 *
 * The deletion cursor is never copied into active state. Each step reads it
 * from `push_status` and sends only complete planned paths that remain absent
 * locally. If a selected path changes, the sender removes the upload-only push
 * session, discards the plan, and returns `restart` so the caller can produce a
 * new local index.
 *
 * ## Streaming and durability
 *
 * Each local-path upload or deletion step sends at most one multipart part and
 * holds at most one bounded payload string. Multipart bytes leave for the
 * network before `send_part()` returns. An open sender retains its path-list
 * handles and current local file handle between steps; close() releases them.
 * Active state and PushPlan's cursor are written atomically, and the lifecycle
 * lock permits only one open sender at a time.
 *
 * @phpstan-type LocalPathTypeSizeAndCtime array{type:'file'|'directory'|'symlink',size:int,ctime:int}
 * @phpstan-type LocalPathStat array{type:'file'|'directory'|'symlink'|'unsupported',size:int,ctime:int}
 * @phpstan-type LocalPathToPush array{path:string,path_b64:string,next_local_paths_to_push_byte_offset:int,planned_local_path_type_size_and_ctime:LocalPathTypeSizeAndCtime}
 * @phpstan-type FreshLocalIndexEntry array{path:string,local_path_type_size_and_ctime:LocalPathTypeSizeAndCtime}
 * @phpstan-type State array{push_session_id:string,phase:'creating'|'planning'|'pushing_paths'|'pushing_deletes'|'committing'|'removing',local_paths_to_push_byte_offset:int,max_part_bytes:int|null,request_sizer_state:array{request_body_bytes:int,ceiling_bytes:int|null,growth_holdoff_remaining:int}}
 */
final class PushFilesSender
{
    /** @var string Local document root whose local paths to push are sent. */
    private string $docroot;

    /** @var string Fresh path-sorted local index used to start a PushPlan. */
    private string $fresh_local_index_path;

    /** @var string Local push state directory shared with PushPlan. */
    private string $push_state_directory;

    /** @var string Atomic checkpoint for the active push workflow. */
    private string $state_path;

    /** @var string Advisory lock file for one open lifecycle. */
    private string $lock_path;

    /** @var resource|null Exclusive lock held from start() or resume() through close(). */
    private $lock_handle = null;

    /** @var resource|null Open local_paths_to_push list retained while pushing local paths. */
    private $local_paths_to_push_handle = null;

    /** @var resource|null Open local_paths_to_delete list retained while pushing deleted paths. */
    private $local_paths_to_delete_handle = null;

    /** @var resource|null Open local file retained while pushing its chunks. */
    private $local_file_handle = null;

    /** @var resource|null Open fresh local index retained while checking planned replacements. */
    private $fresh_local_index_handle = null;

    /** @var FreshLocalIndexEntry|null Next fresh local index entry during deletion checks. */
    private ?array $fresh_local_index_entry = null;

    /** @var PushPlan Plan retained while its bounded steps run. */
    private PushPlan $plan;

    /** @var State|null Active state, or null after terminal completion. */
    private ?array $state = null;

    /** @var MultipartPushStreamClient Reusable connection and request-sizing context. */
    private MultipartPushStreamClient $push_stream_client;

    /** @var array<string,mixed> Options used to construct the PushRequestSizer. */
    private array $request_sizer_options;

    /** @var array<string,mixed> Transport options used by start() or resume(). */
    private array $push_stream_client_options;

    /**
     * Starts a new sender and acquires exclusive ownership of its push state.
     *
     * The initial `creating` state is written before this method returns. An
     * existing active state is rejected so unfinished work cannot be replaced.
     * The returned sender retains its lock until close().
     *
     * @param array $options {
     *     Push, transport, and local-file options.
     *
     *     @type string                  $docroot                Required local document-root directory.
     *     @type string                  $fresh_local_index_path Required fresh local index path.
     *     @type string                  $push_state_directory    Required local push state directory.
     *     @type string                  $base_url                Required exporter API URL.
     *     @type Site_Export_HMAC_Client $hmac_client             Required envelope signer.
     *     @type bool                    $allow_http              Explicit plain-HTTP opt-in. Default false.
     *     @type int|float|string        $chunk_bytes             Maximum bytes read from one local file. Default 4 MiB.
     *     @type int|float|string        $connect_timeout         Connect phase seconds. Default 30.
     *     @type int|float|string        $stall_timeout           No-upload-progress seconds. Default 60.
     *     @type int|float|string        $response_timeout        No-response-progress seconds. Default 300.
     *     @type array                   $request_sizer_options    Optional PushRequestSizer bounds.
     * }
     * @phpstan-param array<string,mixed> $options
     * @return self Open sender at its initial durable state.
     */
    public static function start(array $options): self
    {
        $sender = new self($options);
        if (!is_dir($sender->push_state_directory) && !@mkdir($sender->push_state_directory, 0755, true) && !is_dir($sender->push_state_directory)) {
            throw new RuntimeException('Failed to create the push state directory: ' . $sender->push_state_directory);
        }
        $sender->lock_handle = $sender->acquire_lock();
        try {
            clearstatcache(true, $sender->state_path);
            if (is_file($sender->state_path)) {
                throw new LogicException(
                    'Cannot start a push files sender while unfinished active state exists: '
                    . $sender->state_path
                );
            }
            clearstatcache(true, $sender->fresh_local_index_path);
            if (!is_file($sender->fresh_local_index_path)) {
                throw new InvalidArgumentException(
                    'PushFilesSender requires an existing fresh_local_index_path when starting a sender.'
                );
            }

            $sender->push_stream_client = $sender->create_push_stream_client(null);
            $sender->state = [
                'push_session_id' => bin2hex(random_bytes(16)),
                'phase' => 'creating',
                'local_paths_to_push_byte_offset' => 0,
                'max_part_bytes' => null,
                'request_sizer_state' => $sender->push_stream_client->get_request_sizer_state(),
            ];
            $sender->store_state($sender->state);
            return $sender;
        } catch (Throwable $throwable) {
            $sender->close();
            throw $throwable;
        }
    }

    /**
     * Resumes an unfinished sender while holding its exclusive lifecycle lock.
     *
     * The active state is read once under the acquired lock. next_step() then
     * works from that in-memory state, publishing each later durable boundary
     * without reopening sender.json.
     *
     * @param array<string,mixed> $options Options documented by start().
     * @return self Open sender at its last durable state.
     */
    public static function resume(array $options): self
    {
        $sender = new self($options);
        if (!is_dir($sender->push_state_directory)) {
            throw new LogicException(
                'Cannot resume a push files sender without unfinished active state: '
                . $sender->state_path
            );
        }
        $sender->lock_handle = $sender->acquire_lock();
        try {
            $state = $sender->load_state();
            if ($state === null) {
                throw new LogicException(
                    'Cannot resume a push files sender without unfinished active state: '
                    . $sender->state_path
                );
            }
            $sender->state = $state;
            $sender->push_stream_client = $sender->create_push_stream_client($state);
            if ($state['phase'] === 'planning') {
                $sender->plan = PushPlan::resume($sender->push_state_directory);
            }
            return $sender;
        } catch (Throwable $throwable) {
            $sender->close();
            throw $throwable;
        }
    }

    /**
     * Configures the paths and transport options shared by start() and resume().
     *
     * @param array<string,mixed> $options Options documented by start().
     *
     * @throws InvalidArgumentException If local path or transport options are invalid.
     */
    private function __construct(array $options)
    {
        $docroot = $options['docroot'] ?? null;
        $fresh_local_index_path = $options['fresh_local_index_path'] ?? null;
        $push_state_directory = $options['push_state_directory'] ?? null;
        if (!is_string($docroot) || !is_dir($docroot) || is_link($docroot)) {
            throw new InvalidArgumentException('PushFilesSender requires a real docroot directory.');
        }
        if (!is_string($fresh_local_index_path) || $fresh_local_index_path === '') {
            throw new InvalidArgumentException('PushFilesSender requires a fresh_local_index_path.');
        }
        if (!is_string($push_state_directory) || $push_state_directory === '') {
            throw new InvalidArgumentException('PushFilesSender requires a push_state_directory.');
        }
        $request_sizer_options = $options['request_sizer_options'] ?? [];
        if (!is_array($request_sizer_options)) {
            throw new InvalidArgumentException('request_sizer_options must be an array.');
        }

        $push_stream_client_options = [
            'base_url' => $options['base_url'] ?? null,
            'hmac_client' => $options['hmac_client'] ?? null,
            'allow_http' => $options['allow_http'] ?? false,
        ];
        foreach (['chunk_bytes', 'connect_timeout', 'stall_timeout', 'response_timeout'] as $option_name) {
            if (array_key_exists($option_name, $options)) {
                $push_stream_client_options[$option_name] = $options[$option_name];
            }
        }

        $this->docroot = rtrim($docroot, '/');
        $this->fresh_local_index_path = $fresh_local_index_path;
        $this->push_state_directory = rtrim($push_state_directory, '/');
        $this->state_path = $this->push_state_directory . '/sender.json';
        $this->lock_path = $this->push_state_directory . '/sender.lock';
        $this->request_sizer_options = $request_sizer_options;
        $this->push_stream_client_options = $push_stream_client_options;
    }

    /**
     * Performs the next bounded step.
     *
     * start() or resume() has already acquired the lifecycle lock and loaded the
     * durable state, so this method only dispatches its current phase and
     * publishes the next boundary. `continue` identifies durable work that a
     * later process may resume, and the caller may close the sender immediately
     * after receiving it. `restart` means the old push session and local plan
     * are gone and a new local index is required.
     *
     * @return array {
     *     Result of one step.
     *
     *     @type string      $status          `continue`, `complete`, `restart`, or `failed`.
     *     @type string      $phase           Durable phase or `complete`.
     *     @type string      $push_session_id Push session ID.
     *     @type string|null $reason          Machine-readable result classification.
     *     @type string|null $detail          Human-readable result detail.
     * }
     * @phpstan-return array{status:'continue'|'complete'|'restart'|'failed',phase:string,push_session_id:string,reason:string|null,detail:string|null}
     */
    public function next_step(): array
    {
        if (!is_resource($this->lock_handle)) {
            throw new LogicException('Cannot call next_step() after close().');
        }
        if ($this->state === null) {
            throw new LogicException('Cannot call next_step() after the sender reaches a terminal result.');
        }

        switch ($this->state['phase']) {
            case 'creating':
                $result = $this->create_push_session($this->state);
                break;
            case 'planning':
                $result = $this->next_plan_step($this->state);
                break;
            case 'pushing_paths':
                $result = $this->upload_next_file_chunk($this->state);
                break;
            case 'pushing_deletes':
                $result = $this->upload_next_chunk_of_deleted_paths($this->state);
                break;
            case 'committing':
                $result = $this->commit_push($this->state);
                break;
            case 'removing':
                $result = $this->remove_push_session($this->state);
                break;
        }

        if ($result['status'] !== 'continue') {
            $this->state = null;
        }
        return $result;
    }

    /**
     * Releases the lifecycle lock and prevents further steps.
     *
     * Durable state remains available to resume unless next_step()
     * already completed or discarded the workflow.
     */
    public function close(): void
    {
        if (isset($this->plan)) {
            $this->plan->close();
        }
        $this->close_local_file_handle();
        $this->close_local_paths_to_push_handle();
        $this->close_local_paths_to_delete_handle();
        $this->close_fresh_local_index_handle();
        if (is_resource($this->lock_handle)) {
            $this->release_lock($this->lock_handle);
        }
        $this->lock_handle = null;
    }

    /**
     * Creates the push session and starts PushPlan with its exclusion policy.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Durable result after the create request.
     */
    private function create_push_session(array &$state): array
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_create', [
            'push_session_id' => $state['push_session_id'],
        ], ['created']);
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }

        /** @var array{max_part_bytes:int,post_max_bytes:?int,excluded_paths_b64:list<string>} $response */
        $response = $request_result['response'];
        $excluded_paths = [];
        foreach ($response['excluded_paths_b64'] as $encoded_path) {
            $path = base64_decode($encoded_path, true);
            if ($path === false) {
                return $this->step_result('failed', $state, 'unexpected_response', 'Could not decode an excluded path returned by push_create.');
            }
            $excluded_paths[] = $path;
        }

        $this->push_stream_client->set_max_part_bytes($response['max_part_bytes']);
        $this->push_stream_client->apply_reported_limits([$response['post_max_bytes']]);
        $state['max_part_bytes'] = $response['max_part_bytes'];
        $state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
        clearstatcache(true, $this->fresh_local_index_path);
        if (!is_file($this->fresh_local_index_path)) {
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result(
                'continue',
                $state,
                'local_path_changed',
                'The fresh local index disappeared before planning began; remove the upload-only push session before generating another index.'
            );
        }
        if (PushPlan::has_plan($this->push_state_directory)) {
            $this->plan = PushPlan::resume($this->push_state_directory);
        } else {
            $this->plan = PushPlan::start(
                $this->push_state_directory,
                $this->fresh_local_index_path,
                $excluded_paths
            );
        }

        $state['phase'] = 'planning';
        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Performs one PushPlan step and moves to local paths to push at plan completion.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Durable result after one bounded plan step.
     */
    private function next_plan_step(array &$state): array
    {
        $plan_result = $this->plan->next_step();
        if ($plan_result['status'] === 'complete') {
            $this->plan->close();
            $state['phase'] = 'pushing_paths';
            $this->store_state($state);
        }
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Reconciles one local path to push and sends at most one upload part.
     *
     * A file part contains one bounded local file chunk. A directory or symlink
     * part contains that one complete value. The local-path-list cursor
     * advances only after the receiver confirms the value as complete.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Result of one reconciliation or upload part.
     */
    private function upload_next_file_chunk(array &$state): array
    {
        if (!is_resource($this->local_paths_to_push_handle)) {
            $local_paths_to_push_path = PushPlan::local_paths_to_push_path($this->push_state_directory);
            $this->local_paths_to_push_handle = fopen($local_paths_to_push_path, 'rb');
            if (!is_resource($this->local_paths_to_push_handle)) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not open the local paths to push.');
            }
        }

        try {
            $local_path_to_push = $this->read_local_path_to_push(
                $this->local_paths_to_push_handle,
                $state['local_paths_to_push_byte_offset']
            );
        } catch (RuntimeException $exception) {
            return $this->step_result('failed', $state, 'local_io_error', $exception->getMessage());
        }
        if ($local_path_to_push === null) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $state['phase'] = 'pushing_deletes';
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        $planned_local_path_type_size_and_ctime = $local_path_to_push['planned_local_path_type_size_and_ctime'];
        $local_path_type_size_and_ctime = $this->stat_local_path($local_path_to_push['path']);
        if ($local_path_type_size_and_ctime === null) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push disappeared; remove the upload-only push session before generating another index.');
        }
        if ($local_path_type_size_and_ctime['type'] === 'unsupported') {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push changed to a file type that cannot be pushed; remove the upload-only push session before generating another index.');
        }
        if ($local_path_type_size_and_ctime !== $planned_local_path_type_size_and_ctime) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push changed after planning; remove the upload-only push session before generating another index.');
        }

        $request_result = $this->push_stream_client->send_push_request('GET', 'push_status', [
            'push_session_id' => $state['push_session_id'],
            'path_b64' => $local_path_to_push['path_b64'],
        ], ['accepted']);
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        /** @var array{path:array{state:'missing'|'partial'|'complete',type?:'file'|'directory'|'symlink',accepted_bytes:int}} $response */
        $response = $request_result['response'];
        $receiver_path_status = $response['path'];
        $receiver_path_type = $receiver_path_status['type'] ?? null;

        if (
            $receiver_path_status['state'] === 'complete'
            && $receiver_path_type === $local_path_type_size_and_ctime['type']
            && ( $local_path_type_size_and_ctime['type'] !== 'file' || $receiver_path_status['accepted_bytes'] === $local_path_type_size_and_ctime['size'] )
        ) {
            $this->close_local_file_handle();
            $state['local_paths_to_push_byte_offset'] = $local_path_to_push['next_local_paths_to_push_byte_offset'];
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }
        $receiver_confirmed_bytes = $receiver_path_status['state'] === 'partial'
            && $receiver_path_type === 'file'
            && $receiver_path_status['accepted_bytes'] <= $local_path_type_size_and_ctime['size']
                ? $receiver_path_status['accepted_bytes']
                : 0;

        $upload_part = null;
        $upload_completes_local_path = false;

        if ($local_path_type_size_and_ctime['type'] === 'directory') {
            $directory_is_empty = $this->directory_is_empty($local_path_to_push['path']);
            if ($directory_is_empty === null) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the local directory to push: ' . base64_encode($local_path_to_push['path']) . '.');
            }
            $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
            if ($local_path_type_size_and_ctime_after_read === null) {
                $this->close_local_paths_to_push_handle();
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push disappeared; remove the upload-only push session before generating another index.');
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'local_path_changed', 'The local path to push changed while its directory was being read; remove the upload-only push session before generating another index.');
            }
            if (!$directory_is_empty) {
                $this->close_local_paths_to_push_handle();
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'local_path_changed', 'A directory selected as empty now contains a local path; remove the upload-only push session before generating another index.');
            }
            $upload_part = [
                'type' => 'directory',
                'path' => $local_path_to_push['path'],
                'payload' => '',
            ];
            $upload_completes_local_path = true;
        } elseif ($local_path_type_size_and_ctime['type'] === 'symlink') {
            $symlink_target = @readlink($this->docroot . '/' . $local_path_to_push['path']);
            $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
            if ($local_path_type_size_and_ctime_after_read === null) {
                $this->close_local_paths_to_push_handle();
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push disappeared; remove the upload-only push session before generating another index.');
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'local_path_changed', 'The local path to push changed while its symlink was being read; remove the upload-only push session before generating another index.');
            }
            if ($symlink_target === false) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the local symlink target to push: ' . base64_encode($local_path_to_push['path']) . '.');
            }
            $upload_part = [
                'type' => 'symlink',
                'path' => $local_path_to_push['path'],
                'target' => $symlink_target,
                'payload' => '',
            ];
            $upload_completes_local_path = true;
        }

        if ($local_path_type_size_and_ctime['type'] === 'file') {
            $file_byte_offset = $receiver_confirmed_bytes;
            $maximum_file_payload_bytes = $this->push_stream_client->next_file_body_bytes(
                $local_path_to_push['path'],
                $local_path_type_size_and_ctime['size'],
                $file_byte_offset
            );
            if ($maximum_file_payload_bytes === 0) {
                return $this->step_result(
                    'failed',
                    $state,
                    'request_size_exhausted',
                    'The current request-body budget cannot fit one MIME part for path ' . base64_encode($local_path_to_push['path']) . '.'
                );
            }

            $payload = '';
            if ($local_path_type_size_and_ctime['size'] > 0) {
                $local_io_failure_detail = null;
                if (!is_resource($this->local_file_handle)) {
                    $this->local_file_handle = fopen(
                        $this->docroot . '/' . $local_path_to_push['path'],
                        'rb'
                    );
                }
                if (!is_resource($this->local_file_handle)) {
                    $local_io_failure_detail = 'Could not open the local file to push: ' . base64_encode($local_path_to_push['path']) . '.';
                } elseif (fseek($this->local_file_handle, $file_byte_offset) !== 0) {
                    $this->close_local_file_handle();
                    $local_io_failure_detail = 'Could not seek to the receiver-confirmed cursor in the local file to push: ' . base64_encode($local_path_to_push['path']) . '.';
                } else {
                    $payload = fread($this->local_file_handle, $maximum_file_payload_bytes);
                }

                $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
                if ($local_path_type_size_and_ctime_after_read === null) {
                    $this->close_local_file_handle();
                    $this->close_local_paths_to_push_handle();
                    $state['phase'] = 'removing';
                    $this->store_state($state);
                    return $this->step_result('continue', $state, 'local_path_changed', 'A local path to push disappeared; remove the upload-only push session before generating another index.');
                }
                if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                    $this->close_local_file_handle();
                    $this->close_local_paths_to_push_handle();
                    $state['phase'] = 'removing';
                    $this->store_state($state);
                    return $this->step_result('continue', $state, 'local_path_changed', 'The local path to push changed while its file chunk was being read; remove the upload-only push session before generating another index.');
                }
                if ($local_io_failure_detail !== null) {
                    return $this->step_result('failed', $state, 'local_io_error', $local_io_failure_detail);
                }
                if (!is_string($payload) || ( $payload === '' && $file_byte_offset < $local_path_type_size_and_ctime['size'] )) {
                    $this->close_local_file_handle();
                    return $this->step_result('failed', $state, 'local_io_error', 'Could not read the local file to push at its receiver-confirmed cursor: ' . base64_encode($local_path_to_push['path']) . '.');
                }
            }

            $upload_part = [
                'type' => 'file',
                'path' => $local_path_to_push['path'],
                'total_bytes' => $local_path_type_size_and_ctime['size'],
                'offset' => $file_byte_offset,
                'payload' => $payload,
            ];
            $upload_completes_local_path = $file_byte_offset + strlen($payload) === $local_path_type_size_and_ctime['size'];
        }

        if (!$this->push_stream_client->start_upload_request($state['push_session_id'])) {
            return $this->step_result(
                'failed',
                $state,
                'request_failed',
                $this->push_stream_client->get_last_error()
            );
        }

        /** @var array<string,mixed> $upload_part */
        $part_sent = $this->push_stream_client->send_part($upload_part);
        $request_result = $this->push_stream_client->finish_request();
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        $state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
        if (!$part_sent) {
            return $this->step_result(
                'failed',
                $state,
                'request_size_exhausted',
                'The current request-body budget cannot fit one MIME part for path ' . base64_encode($local_path_to_push['path']) . '.'
            );
        }
        if ($upload_completes_local_path) {
            $this->close_local_file_handle();
            $state['local_paths_to_push_byte_offset'] = $local_path_to_push['next_local_paths_to_push_byte_offset'];
        }

        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Reads the receiver's deletion cursor and sends at most one list part.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Result of one reconciliation or list part.
     */
    private function upload_next_chunk_of_deleted_paths(array &$state): array
    {
        $request_result = $this->push_stream_client->send_push_request('GET', 'push_status', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        /** @var array{work_deletes_bytes:int,work_deletes_complete:bool} $response */
        $response = $request_result['response'];
        $work_deletes_bytes = $response['work_deletes_bytes'];
        $work_deletes_complete = $response['work_deletes_complete'];

        if ($work_deletes_complete) {
            $this->close_local_paths_to_delete_handle();
            $this->close_fresh_local_index_handle();
            $state['phase'] = 'committing';
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        if (!is_resource($this->local_paths_to_delete_handle)) {
            $local_paths_to_delete_path = PushPlan::local_paths_to_delete_path($this->push_state_directory);
            $this->local_paths_to_delete_handle = fopen($local_paths_to_delete_path, 'rb');
            if (!is_resource($this->local_paths_to_delete_handle)) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not open the local deletion list.');
            }
        }
        if (fseek($this->local_paths_to_delete_handle, $work_deletes_bytes) !== 0) {
            $this->close_local_paths_to_delete_handle();
            return $this->step_result('failed', $state, 'local_io_error', 'Could not seek to the receiver-confirmed cursor in the local deletion list.');
        }
        $maximum_delete_list_payload_bytes = $this->push_stream_client->next_delete_body_bytes($work_deletes_bytes);
        if ($maximum_delete_list_payload_bytes === 0) {
            return $this->step_result('failed', $state, 'request_size_exhausted', 'The current request-body budget cannot fit one deletion-list MIME part.');
        }
        $payload = fread($this->local_paths_to_delete_handle, $maximum_delete_list_payload_bytes);
        if (!is_string($payload) || ( $payload === '' && !feof($this->local_paths_to_delete_handle) )) {
            return $this->step_result('failed', $state, 'local_io_error', 'Could not read the local deletion list at the receiver-confirmed cursor.');
        }
        if ($payload !== '') {
            $last_path_end = strrpos($payload, "\0");
            if ($last_path_end === false) {
                return $this->step_result('failed', $state, 'request_size_exhausted', 'The current request-body budget cannot fit one complete local path to delete.');
            }
            $payload = substr($payload, 0, $last_path_end + 1);
            if (fseek($this->local_paths_to_delete_handle, $work_deletes_bytes + strlen($payload)) !== 0) {
                $this->close_local_paths_to_delete_handle();
                return $this->step_result('failed', $state, 'local_io_error', 'Could not position the local deletion list after the next complete paths.');
            }
            $local_paths_to_delete = explode("\0", $payload);
            array_pop($local_paths_to_delete);
            foreach ($local_paths_to_delete as $local_path_to_delete) {
                $local_path_type_size_and_ctime = $this->stat_local_path($local_path_to_delete);
                if ($local_path_type_size_and_ctime === null) {
                    continue;
                }
                try {
                    $planned_local_path_type_size_and_ctime = $this->get_planned_local_path_type_size_and_ctime(
                        $local_path_to_delete
                    );
                } catch (RuntimeException $exception) {
                    return $this->step_result('failed', $state, 'local_io_error', $exception->getMessage());
                }
                if ($local_path_type_size_and_ctime !== $planned_local_path_type_size_and_ctime) {
                    $this->close_local_paths_to_delete_handle();
                    $this->close_fresh_local_index_handle();
                    $state['phase'] = 'removing';
                    $this->store_state($state);
                    return $this->step_result('continue', $state, 'local_path_changed', 'A local path selected for deletion now exists; remove the upload-only push session before generating another index.');
                }
            }
        }
        $local_delete_list_complete = $payload === '';

        if (!$this->push_stream_client->start_upload_request($state['push_session_id'])) {
            return $this->step_result(
                'failed',
                $state,
                'request_failed',
                $this->push_stream_client->get_last_error()
            );
        }

        $part_sent = $this->push_stream_client->send_part([
            'type' => 'delete-list',
            'offset' => $work_deletes_bytes,
            'complete' => $local_delete_list_complete,
            'payload' => $payload,
        ]);
        $request_result = $this->push_stream_client->finish_request();
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        $state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
        if (!$part_sent) {
            return $this->step_result('failed', $state, 'request_size_exhausted', 'The current request-body budget cannot fit one deletion-list MIME part.');
        }

        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Closes the current local file when its upload or this lifecycle ends.
     */
    private function close_local_file_handle(): void
    {
        if (is_resource($this->local_file_handle)) {
            fclose($this->local_file_handle);
        }
        $this->local_file_handle = null;
    }

    /**
     * Closes the local paths-to-push list when that phase or this lifecycle ends.
     */
    private function close_local_paths_to_push_handle(): void
    {
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        $this->local_paths_to_push_handle = null;
    }

    /**
     * Closes the deleted-path list when that phase or this lifecycle ends.
     */
    private function close_local_paths_to_delete_handle(): void
    {
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        $this->local_paths_to_delete_handle = null;
    }

    /**
     * Closes the fresh local index used to distinguish replacements from reappeared paths.
     */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
        $this->fresh_local_index_entry = null;
    }

    /**
     * Requests one bounded receiver commit step and publishes a completed plan.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Commit continuation or terminal completion.
     */
    private function commit_push(array &$state): array
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_commit', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        /** @var array{send_next_request:bool} $response */
        $response = $request_result['response'];

        if ($response['send_next_request']) {
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        // A prior process may have published the plan after the receiver
        // completed but stopped before it removed sender.json.
        if (PushPlan::has_plan($this->push_state_directory)) {
            if (!isset($this->plan)) {
                $this->plan = PushPlan::resume($this->push_state_directory);
            }
            $this->plan->close();
            $this->plan->after_successful_push();
        }
        $push_session_id = $state['push_session_id'];
        $this->delete_state();
        return [
            'status' => 'complete',
            'phase' => 'complete',
            'push_session_id' => $push_session_id,
            'reason' => null,
            'detail' => null,
        ];
    }

    /**
     * Removes an upload-only push session and discards its local PushPlan.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Removal continuation or terminal restart.
     */
    private function remove_push_session(array &$state): array
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_remove', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure_result = $this->handle_request_failure($request_result, $state);
        if ($failure_result !== null) {
            return $failure_result;
        }
        /** @var array{removed:bool} $response */
        $response = $request_result['response'];
        if (!$response['removed']) {
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        // A repeated remove may follow a process that discarded the plan but
        // stopped before it removed sender.json.
        if (PushPlan::has_plan($this->push_state_directory)) {
            if (!isset($this->plan)) {
                $this->plan = PushPlan::resume($this->push_state_directory);
            }
            $this->plan->close();
            $this->plan->discard();
        }
        $push_session_id = $state['push_session_id'];
        $this->delete_state();
        return [
            'status' => 'restart',
            'phase' => 'complete',
            'push_session_id' => $push_session_id,
            'reason' => 'local_path_changed',
            'detail' => 'The upload-only push session was removed. Generate a new local index before retrying.',
        ];
    }

    /**
     * Builds a streaming client from the sizing state in the durable checkpoint.
     *
     * @param State|null $state Current state, or null before a push starts.
     */
    private function create_push_stream_client(?array $state): MultipartPushStreamClient
    {
        $request_sizer = new PushRequestSizer(
            $this->request_sizer_options,
            $state === null ? [] : $state['request_sizer_state']
        );
        $push_stream_client_options = $this->push_stream_client_options;
        $push_stream_client_options['request_sizer'] = $request_sizer;
        $push_stream_client = new MultipartPushStreamClient($push_stream_client_options);
        if ($state !== null && $state['max_part_bytes'] !== null) {
            $push_stream_client->set_max_part_bytes($state['max_part_bytes']);
        }
        return $push_stream_client;
    }

    /**
     * Reads one local path to push at an exact durable byte offset.
     *
     * @param resource $local_paths_to_push_handle Open local_paths_to_push file.
     * @param int $local_paths_to_push_byte_offset Byte offset of the path to read.
     * @return LocalPathToPush|null Local path to push, or null at EOF.
     */
    private function read_local_path_to_push($local_paths_to_push_handle, int $local_paths_to_push_byte_offset): ?array
    {
        if (fseek($local_paths_to_push_handle, $local_paths_to_push_byte_offset) !== 0) {
            throw new RuntimeException('Failed to seek to the active byte offset in the local paths to push.');
        }
        $line = fgets($local_paths_to_push_handle);
        if ($line === false) {
            if (feof($local_paths_to_push_handle)) {
                return null;
            }
            throw new RuntimeException('Failed to read the local paths to push.');
        }
        $next_local_paths_to_push_byte_offset = ftell($local_paths_to_push_handle);
        if (!is_int($next_local_paths_to_push_byte_offset)) {
            throw new RuntimeException('Failed to determine the next byte offset in the local paths to push.');
        }
        try {
            $decoded_local_path = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode a local path to push.', 0, $exception);
        }
        /** @var array{path:string,type:'file'|'directory'|'symlink',size:int,ctime:int} $decoded_local_path */
        $path_b64 = $decoded_local_path['path'];
        $path = base64_decode($path_b64, true);
        if ($path === false) {
            throw new RuntimeException('Failed to decode a path in the local paths-to-push file.');
        }
        return [
            'path' => $path,
            'path_b64' => $path_b64,
            'next_local_paths_to_push_byte_offset' => $next_local_paths_to_push_byte_offset,
            'planned_local_path_type_size_and_ctime' => [
                'type' => $decoded_local_path['type'],
                'size' => $decoded_local_path['size'],
                'ctime' => $decoded_local_path['ctime'],
            ],
        ];
    }

    /**
     * Returns planned type, size, and ctime when the fresh local index contains the path.
     *
     * Deletion paths are sorted, so one retained index handle only moves
     * forward while replacement paths are checked.
     *
     * @param string $path Raw document-root-relative path.
     * @return LocalPathTypeSizeAndCtime|null Planned values, or null when the path was absent during planning.
     */
    private function get_planned_local_path_type_size_and_ctime(string $path): ?array
    {
        if (!is_resource($this->fresh_local_index_handle)) {
            $this->fresh_local_index_handle = fopen(
                $this->push_state_directory . '/fresh_local_index.jsonl',
                'rb'
            );
            if (!is_resource($this->fresh_local_index_handle)) {
                throw new RuntimeException('Could not open the fresh local index while checking a planned replacement.');
            }
        }

        while (true) {
            if ($this->fresh_local_index_entry === null) {
                $line = fgets($this->fresh_local_index_handle);
                if ($line === false) {
                    if (feof($this->fresh_local_index_handle)) {
                        return null;
                    }
                    throw new RuntimeException('Could not read the fresh local index while checking a planned replacement.');
                }
                try {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Could not decode the fresh local index while checking a planned replacement.', 0, $exception);
                }
                /** @var array{path:string,type:'file'|'link'|'dir',size:int,ctime:int} $entry */
                $entry_path = base64_decode($entry['path'], true);
                if ($entry_path === false) {
                    throw new RuntimeException('Could not decode a path in the fresh local index while checking a planned replacement.');
                }
                $this->fresh_local_index_entry = [
                    'path' => $entry_path,
                    'local_path_type_size_and_ctime' => [
                        'type' => $entry['type'] === 'link' ? 'symlink' : ($entry['type'] === 'dir' ? 'directory' : 'file'),
                        'size' => $entry['size'],
                        'ctime' => $entry['ctime'],
                    ],
                ];
            }

            $path_comparison = strcmp($this->fresh_local_index_entry['path'], $path);
            if ($path_comparison < 0) {
                $this->fresh_local_index_entry = null;
                continue;
            }
            if ($path_comparison > 0) {
                return null;
            }
            $local_path_type_size_and_ctime = $this->fresh_local_index_entry['local_path_type_size_and_ctime'];
            $this->fresh_local_index_entry = null;
            return $local_path_type_size_and_ctime;
        }
    }

    /**
     * Reports whether a local directory to push has no child entry.
     *
     * @param string $path Raw document-root-relative directory path.
     * @return bool|null True when empty, false when non-empty, or null when unreadable.
     */
    private function directory_is_empty(string $path): ?bool
    {
        $directory_handle = @opendir($this->docroot . '/' . $path);
        if ($directory_handle === false) {
            return null;
        }
        try {
            while (true) {
                $entry = readdir($directory_handle);
                if ($entry === false) {
                    return true;
                }
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }
        } finally {
            closedir($directory_handle);
        }
    }

    /**
     * Reads the type, size, and ctime used to detect a changed local path.
     *
     * Regular files, directories, and symlinks are the only sendable types.
     * The type, size, and ctime match PushPlan's file-change comparison.
     * A same-size edit within one ctime second remains the timestamp-resolution
     * gap documented for local change detection.
     *
     * @param string $path Raw document-root-relative path.
     * @return LocalPathStat|null Current type, size, and ctime, or null when absent.
     */
    private function stat_local_path(string $path): ?array
    {
        $absolute_path = $this->docroot . '/' . $path;
        clearstatcache(true, $absolute_path);
        $path_stat = @lstat($absolute_path);
        if (!is_array($path_stat)) {
            return null;
        }
        $file_type_bits = $path_stat['mode'] & 0170000;
        if ($file_type_bits === 0100000) {
            $type = 'file';
        } elseif ($file_type_bits === 0040000) {
            $type = 'directory';
        } elseif ($file_type_bits === 0120000) {
            $type = 'symlink';
        } else {
            $type = 'unsupported';
        }
        return [
            'type' => $type,
            'size' => (int) $path_stat['size'],
            'ctime' => (int) $path_stat['ctime'],
        ];
    }

    /**
     * Stops the current sender run after a request failure.
     *
     * @param array{status:'complete'|'retry'|'failed',reason:string|null,detail:string|null,response:array<string,mixed>|null,parts_sent:int,body_bytes_sent:int} $request_result Classified request result.
     * @param State $state Active state, persisted when request sizing changes.
     * @return array<string,mixed>|null Null when the request completed successfully.
     */
    private function handle_request_failure(array $request_result, array &$state): ?array
    {
        if ($request_result['status'] === 'complete') {
            return null;
        }
        $request_sizer_state = $this->push_stream_client->get_request_sizer_state();
        if ($state['request_sizer_state'] !== $request_sizer_state) {
            $state['request_sizer_state'] = $request_sizer_state;
            $this->store_state($state);
        }
        return $this->step_result('failed', $state, $request_result['reason'], $request_result['detail']);
    }

    /**
     * Loads the active state from its atomic JSON file.
     *
     * The writer owns the schema. Reading retains only file and JSON failure
     * handling rather than maintaining a second schema validator.
     *
     * @return State|null Active state, or null when none exists.
     */
    private function load_state(): ?array
    {
        clearstatcache(true, $this->state_path);
        if (!is_file($this->state_path)) {
            return null;
        }
        $json = file_get_contents($this->state_path);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to read active state: ' . $this->state_path);
        }
        try {
            $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode active state: ' . $this->state_path, 0, $exception);
        }
        /** @var State $state */
        return $state;
    }

    /**
     * Atomically stores the complete active state.
     *
     * @param State $state Active state.
     */
    private function store_state(array $state): void
    {
        try {
            $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode active state.', 0, $exception);
        }
        $temporary_path = $this->state_path . '.tmp';
        if (file_put_contents($temporary_path, $json) !== strlen($json)) {
            throw new RuntimeException('Failed to write active state: ' . $temporary_path);
        }
        if (!rename($temporary_path, $this->state_path)) {
            throw new RuntimeException('Failed to move active state into place: ' . $this->state_path);
        }
    }

    /**
     * Removes the state after terminal push work is durable.
     */
    private function delete_state(): void
    {
        clearstatcache(true, $this->state_path);
        if (is_file($this->state_path) && !unlink($this->state_path)) {
            throw new RuntimeException('Failed to remove active state: ' . $this->state_path);
        }
    }

    /**
     * Acquires non-blocking exclusive ownership of one lifecycle.
     *
     * @return resource Open locked handle retained until close().
     */
    private function acquire_lock()
    {
        $lock_handle = fopen($this->lock_path, 'c+');
        if (!is_resource($lock_handle)) {
            throw new RuntimeException('Failed to open the lifecycle lock: ' . $this->lock_path);
        }
        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            fclose($lock_handle);
            throw new RuntimeException(
                'Cannot start or resume this push files sender while another process holds its lock: '
                . $this->lock_path
            );
        }
        return $lock_handle;
    }

    /**
     * Releases and closes a lock returned by acquire_lock().
     *
     * @param resource $lock_handle Open locked handle.
     */
    private function release_lock($lock_handle): void
    {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }

    /**
     * Builds one workflow result without changing durable state.
     *
     * @param 'continue'|'failed' $status Step disposition.
     * @param State $state Active state.
     * @param string|null $reason Machine-readable classification.
     * @param string|null $detail Human-readable condition.
     * @return array{status:'continue'|'failed',phase:string,push_session_id:string,reason:string|null,detail:string|null}
     */
    private function step_result(string $status, array $state, ?string $reason, ?string $detail): array
    {
        return [
            'status' => $status,
            'phase' => $state['phase'],
            'push_session_id' => $state['push_session_id'],
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
