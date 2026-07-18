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
 *             if (!$sender->next_step()) {
 *                 break;
 *             }
 *         }
 *         $status = $sender->get_status();
 *     } finally {
 *         if ($sender->get_status() === 'continue') {
 *             $sender->cancel();
 *         }
 *         $sender->close();
 *     }
 *
 * The caller may cancel whenever next_step() returns true. cancel() abandons an
 * open multipart request and returns the in-memory sender to its preceding
 * durable boundary. close() otherwise finishes the request before releasing
 * the lock. If a process stops without closing, the next process starts from
 * the preceding durable boundary and reads receiver-confirmed work before
 * sending more data.
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
 * from `push_status` and sends complete planned paths only while they remain
 * absent or match the replacement in the fresh local index. If a selected path
 * changes, the sender removes the upload-only push session, discards the plan,
 * and changes the sender status to `restart` so the caller can produce a new
 * local index.
 *
 * ## Streaming and durability
 *
 * Each local-path upload or deletion step sends at most one multipart part and
 * holds at most one bounded payload string. A deletion part contains one path,
 * so checking that path never scans the whole deletion list. Multipart bytes
 * leave for the network before `send_part()` returns. One request carries
 * successive parts until its request-body budget is spent or close() finishes
 * it. An open sender retains that request, its path-list handles, and its
 * current local file handle between steps.
 *
 * @phpstan-type LocalPathTypeSizeAndCtime array{type:'file'|'directory'|'symlink',size:int,ctime:int}
 * @phpstan-type LocalPathStat array{type:'file'|'directory'|'symlink'|'unsupported',size:int,ctime:int}
 * @phpstan-type LocalPathToPush array{path:string,path_b64:string,next_local_paths_to_push_byte_offset:int,planned_local_path_type_size_and_ctime:LocalPathTypeSizeAndCtime}
 * @phpstan-type LocalPathToDelete array{path:string,delete_list_byte_offset:int,next_delete_list_byte_offset:int}
 * @phpstan-type FreshLocalIndexEntry array{path:string,local_path_type_size_and_ctime:LocalPathTypeSizeAndCtime,next_fresh_local_index_byte_offset:int}
 * @phpstan-type State array{push_session_id:string,phase:'creating'|'planning'|'pushing_paths'|'pushing_deletes'|'committing'|'removing',local_paths_to_push_byte_offset:int,fresh_local_index_byte_offset:int,max_part_bytes:int|null,request_sizer_state:array{request_body_bytes:int,ceiling_bytes:int|null,growth_holdoff_remaining:int}}
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

    /** @var string Target exclusions stored once for the active push. */
    private string $excluded_paths_path;

    /** @var resource|null Exclusive lock held from start() or resume() through close(). */
    private $lock_handle = null;

    /** @var resource|null Open local_paths_to_push list retained while pushing local paths. */
    private $local_paths_to_push_handle = null;

    /** @var int|null Current byte offset of the retained local paths-to-push handle. */
    private ?int $local_paths_to_push_byte_offset = null;

    /** @var resource|null Open local_paths_to_delete list retained while pushing deleted paths. */
    private $local_paths_to_delete_handle = null;

    /** @var int|null Current byte offset of the retained deletion-list handle. */
    private ?int $local_paths_to_delete_byte_offset = null;

    /** @var LocalPathToDelete|null Current local path to delete retained until it is sent. */
    private ?array $local_path_to_delete = null;

    /** @var bool Whether the retained deletion-list handle reached EOF. */
    private bool $local_delete_list_complete = false;

    /** @var resource|null Open local file retained while pushing its chunks. */
    private $local_file_handle = null;

    /** @var int|null Current byte offset of the retained local file handle. */
    private ?int $local_file_byte_offset = null;

    /** @var LocalPathToPush|null Current selected path retained between its chunks. */
    private ?array $local_path_to_push = null;

    /** @var resource|null Open fresh local index retained while checking planned deletion paths. */
    private $fresh_local_index_handle = null;

    /** @var FreshLocalIndexEntry|null Next fresh local index entry during a deletion check. */
    private ?array $fresh_local_index_entry = null;

    /** @var int|null Current byte offset of the retained fresh local index handle. */
    private ?int $fresh_local_index_byte_offset = null;

    /** @var LocalPathTypeSizeAndCtime|null Planned values for the current local path to delete. */
    private ?array $planned_local_path_type_size_and_ctime = null;

    /** @var int|null Fresh local index offset to publish after sending the current path. */
    private ?int $next_fresh_local_index_byte_offset = null;

    /** @var PushPlan Plan retained while its bounded steps run. */
    private PushPlan $plan;

    /** @var State State retained for the open sender lifecycle. */
    private array $state;

    /** @var 'continue'|'complete'|'restart'|'failed' Outcome of the open sender lifecycle. */
    private string $status = 'continue';

    /** @var string|null Machine-readable classification for the current outcome. */
    private ?string $reason = null;

    /** @var string|null Human-readable explanation for the current outcome. */
    private ?string $detail = null;

    /** @var MultipartPushStreamClient Reusable connection and request-sizing context. */
    private MultipartPushStreamClient $push_stream_client;

    /** @var bool Whether the open upload request must finish before another part. */
    private bool $upload_request_should_finish = false;

    /** @var bool Whether the open upload request contains at least one complete part. */
    private bool $upload_request_has_parts = false;

    /** @var State|null Durable state from before the open upload request. */
    private ?array $state_before_upload_request = null;

    /** @var int|null Next local file byte offset within the open upload request. */
    private ?int $next_file_byte_offset = null;

    /** @var int|null Next deletion-list byte offset within the open upload request. */
    private ?int $next_delete_list_byte_offset = null;

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
                'fresh_local_index_byte_offset' => 0,
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
        $this->excluded_paths_path = $this->push_state_directory . '/excluded_paths.json';
        $this->request_sizer_options = $request_sizer_options;
        $this->push_stream_client_options = $push_stream_client_options;
    }

    /**
     * Performs the next bounded step.
     *
     * start() or resume() has already acquired the lifecycle lock and loaded the
     * durable state, so this method only dispatches its current phase. The
     * caller may close the sender after this method returns true; close()
     * confirms any open multipart request before publishing its local
     * boundary. A false return directs the caller to get_status(), where
     * `restart` means the old push session and local plan are gone and a new
     * local index is required.
     *
     * @return bool Whether the sender can perform another step.
     */
    public function next_step(): bool
    {
        if ($this->status !== 'continue') {
            return false;
        }
        if (!is_resource($this->lock_handle)) {
            throw new LogicException('Cannot call next_step() after close().');
        }

        switch ($this->state['phase']) {
            case 'creating':
                $this->create_push_session();
                break;
            case 'planning':
                $this->next_plan_step();
                break;
            case 'pushing_paths':
                $this->upload_next_file_chunk();
                break;
            case 'pushing_deletes':
                $this->upload_next_chunk_of_deleted_paths();
                break;
            case 'committing':
                $this->commit_push();
                break;
            case 'removing':
                $this->remove_push_session();
                break;
        }

        return $this->status === 'continue';
    }

    /**
     * Returns whether the sender can continue or why it stopped.
     *
     * @return 'continue'|'complete'|'restart'|'failed' Current sender status.
     */
    public function get_status(): string
    {
        return $this->status;
    }

    /**
     * Returns the current durable sender phase.
     *
     * @return string Current phase.
     */
    public function get_phase(): string
    {
        return $this->state['phase'];
    }

    /**
     * Returns the machine-readable classification for the current outcome.
     *
     * @return string|null Current classification, or null when none applies.
     */
    public function get_reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Returns the human-readable explanation for the current outcome.
     *
     * @return string|null Current explanation, or null when none applies.
     */
    public function get_detail(): ?string
    {
        return $this->detail;
    }

    /**
     * Cancels the open multipart request and returns to its preceding boundary.
     *
     * The target may have received complete parts before the connection closed,
     * so a later step asks for target-confirmed cursors before sending them again.
     * No request is opened or finished by this method.
     */
    public function cancel(): void
    {
        if ($this->state_before_upload_request === null) {
            return;
        }
        $this->push_stream_client->cancel_request();
        $this->state = $this->state_before_upload_request;
        $this->state_before_upload_request = null;
        $this->upload_request_should_finish = false;
        $this->upload_request_has_parts = false;
        $this->next_file_byte_offset = null;
        $this->next_delete_list_byte_offset = null;
        $this->local_delete_list_complete = false;
        $this->local_path_to_push = null;
        $this->local_path_to_delete = null;
        $this->close_local_file_handle();
        $this->close_local_paths_to_push_handle();
        $this->close_local_paths_to_delete_handle();
        $this->close_fresh_local_index_handle();
    }

    /**
     * Releases the lifecycle lock and prevents further steps.
     *
     * Durable state remains available to resume unless next_step()
     * already completed or discarded the workflow.
     */
    public function close(): void
    {
        $close_failure = null;
        if ($this->state_before_upload_request !== null && isset($this->state)) {
            try {
                $this->finish_upload_request();
                if ($this->status === 'failed') {
                    $close_failure = new RuntimeException($this->detail ?? 'The multipart upload request failed while closing the sender.');
                }
            } catch (Throwable $throwable) {
                $close_failure = $throwable;
            }
        }
        if (isset($this->plan)) {
            $this->plan->close();
        }
        $this->close_local_file_handle();
        $this->close_local_paths_to_push_handle();
        $this->close_local_paths_to_delete_handle();
        $this->close_fresh_local_index_handle();
        if (isset($this->push_stream_client)) {
            $this->push_stream_client->close();
        }
        if (is_resource($this->lock_handle)) {
            $this->release_lock($this->lock_handle);
        }
        $this->lock_handle = null;
        if ($close_failure !== null) {
            throw $close_failure;
        }
    }

    /**
     * Creates the push session and starts PushPlan with its exclusion policy.
     */
    private function create_push_session(): void
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_create', [
            'push_session_id' => $this->state['push_session_id'],
        ], ['created']);
        if ($this->handle_request_failure($request_result)) {
            return;
        }

        /** @var array{max_part_bytes:int,post_max_bytes:?int,excluded_paths_b64:list<string>} $response */
        $response = $request_result['response'];
        if (count($response['excluded_paths_b64']) > 100) {
            $this->fail(
                'unexpected_response',
                'push_create returned ' . count($response['excluded_paths_b64']) . ' excluded paths; the maximum is 100.'
            );
            return;
        }
        foreach ($response['excluded_paths_b64'] as $encoded_path) {
            $path = base64_decode($encoded_path, true);
            if ($path === false) {
                $this->fail('unexpected_response', 'Could not decode an excluded path returned by push_create.');
                return;
            }
        }
        $this->store_excluded_paths($response['excluded_paths_b64']);

        $this->push_stream_client->set_max_part_bytes($response['max_part_bytes']);
        $this->push_stream_client->apply_reported_limits([$response['post_max_bytes']]);
        $this->state['max_part_bytes'] = $response['max_part_bytes'];
        $this->state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
        clearstatcache(true, $this->fresh_local_index_path);
        if (!is_file($this->fresh_local_index_path)) {
            $this->start_removing_push_session_after_local_change(
                'The fresh local index disappeared before planning began; remove the upload-only push session before generating another index.'
            );
            return;
        }
        if (PushPlan::has_plan($this->push_state_directory)) {
            $this->plan = PushPlan::resume($this->push_state_directory);
        } else {
            $this->plan = PushPlan::start(
                $this->push_state_directory,
                $this->fresh_local_index_path
            );
        }

        $this->state['phase'] = 'planning';
        $this->store_state($this->state);
    }

    /**
     * Performs one PushPlan step and moves to local paths to push at plan completion.
     */
    private function next_plan_step(): void
    {
        if (!$this->plan->next_step()) {
            $this->plan->close();
            $this->state['phase'] = 'pushing_paths';
            $this->store_state($this->state);
        }
    }

    /**
     * Checks one local path against planned and receiver state, then sends at most one part.
     *
     * A file part contains one bounded local file chunk. A directory or symlink
     * part contains that one complete value. The durable local-path-list cursor
     * advances only after the containing request is confirmed.
     */
    private function upload_next_file_chunk(): void
    {
        if ($this->upload_request_should_finish) {
            $this->finish_upload_request();
            return;
        }

        if (!is_resource($this->local_paths_to_push_handle)) {
            $local_paths_to_push_path = PushPlan::local_paths_to_push_path($this->push_state_directory);
            $this->local_paths_to_push_handle = fopen($local_paths_to_push_path, 'rb');
            if (!is_resource($this->local_paths_to_push_handle)) {
                $this->fail('local_io_error', 'Could not open the local paths to push.');
                return;
            }
        }

        if ($this->local_path_to_push === null) {
            if ($this->local_paths_to_push_byte_offset !== $this->state['local_paths_to_push_byte_offset']) {
                if (fseek($this->local_paths_to_push_handle, $this->state['local_paths_to_push_byte_offset']) !== 0) {
                    $this->fail('local_io_error', 'Failed to seek to the active byte offset in the local paths to push.');
                    return;
                }
                $this->local_paths_to_push_byte_offset = $this->state['local_paths_to_push_byte_offset'];
            }
            try {
                $this->local_path_to_push = $this->read_local_path_to_push($this->local_paths_to_push_handle);
            } catch (RuntimeException $exception) {
                $this->fail('local_io_error', $exception->getMessage());
                return;
            }
            if ($this->local_path_to_push !== null) {
                $this->local_paths_to_push_byte_offset = $this->local_path_to_push['next_local_paths_to_push_byte_offset'];
            }
        }
        $local_path_to_push = $this->local_path_to_push;
        if ($local_path_to_push === null) {
            if ($this->state_before_upload_request !== null) {
                $this->finish_upload_request();
                return;
            }
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->state['phase'] = 'pushing_deletes';
            $this->store_state($this->state);
            return;
        }

        $planned_local_path_type_size_and_ctime = $local_path_to_push['planned_local_path_type_size_and_ctime'];
        $local_path_type_size_and_ctime = $this->stat_local_path($local_path_to_push['path']);
        if ($local_path_type_size_and_ctime === null) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change(
                'A local path to push disappeared; remove the upload-only push session before generating another index.'
            );
            return;
        }
        if ($local_path_type_size_and_ctime['type'] === 'unsupported') {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change(
                'A local path to push changed to a file type that cannot be pushed; remove the upload-only push session before generating another index.'
            );
            return;
        }
        if ($local_path_type_size_and_ctime !== $planned_local_path_type_size_and_ctime) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change(
                'A local path to push changed after planning; remove the upload-only push session before generating another index.'
            );
            return;
        }

        if ($this->state_before_upload_request !== null) {
            $receiver_confirmed_bytes = $this->next_file_byte_offset ?? 0;
        } else {
            $request_result = $this->push_stream_client->send_push_request('GET', 'push_status', [
                'push_session_id' => $this->state['push_session_id'],
                'path_b64' => $local_path_to_push['path_b64'],
            ], ['accepted']);
            if ($this->handle_request_failure($request_result)) {
                return;
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
                $this->state['local_paths_to_push_byte_offset'] = $local_path_to_push['next_local_paths_to_push_byte_offset'];
                $this->local_path_to_push = null;
                $this->store_state($this->state);
                return;
            }
            $receiver_confirmed_bytes = $receiver_path_status['state'] === 'partial'
                && $receiver_path_type === 'file'
                && $receiver_path_status['accepted_bytes'] <= $local_path_type_size_and_ctime['size']
                    ? $receiver_path_status['accepted_bytes']
                    : 0;
            $this->next_file_byte_offset = $receiver_confirmed_bytes;
        }

        $upload_part = null;
        $upload_completes_local_path = false;

        if ($local_path_type_size_and_ctime['type'] === 'directory') {
            $directory_is_empty = $this->directory_is_empty($local_path_to_push['path']);
            if ($directory_is_empty === null) {
                $this->fail('local_io_error', 'Could not read the local directory to push: ' . base64_encode($local_path_to_push['path']) . '.');
                return;
            }
            $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
            if ($local_path_type_size_and_ctime_after_read === null) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change(
                    'A local path to push disappeared; remove the upload-only push session before generating another index.'
                );
                return;
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change(
                    'The local path to push changed while its directory was being read; remove the upload-only push session before generating another index.'
                );
                return;
            }
            if (!$directory_is_empty) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change(
                    'A directory selected as empty now contains a local path; remove the upload-only push session before generating another index.'
                );
                return;
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
                $this->start_removing_push_session_after_local_change(
                    'A local path to push disappeared; remove the upload-only push session before generating another index.'
                );
                return;
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change(
                    'The local path to push changed while its symlink was being read; remove the upload-only push session before generating another index.'
                );
                return;
            }
            if ($symlink_target === false) {
                $this->fail('local_io_error', 'Could not read the local symlink target to push: ' . base64_encode($local_path_to_push['path']) . '.');
                return;
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
                if ($this->state_before_upload_request !== null && $this->upload_request_has_parts) {
                    $this->upload_request_should_finish = true;
                    return;
                }
                $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($local_path_to_push['path']) . '.');
                return;
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
                } else {
                    if ($this->local_file_byte_offset !== $file_byte_offset) {
                        if (fseek($this->local_file_handle, $file_byte_offset) !== 0) {
                            $this->close_local_file_handle();
                            $local_io_failure_detail = 'Could not seek to the receiver-confirmed cursor in the local file to push: ' . base64_encode($local_path_to_push['path']) . '.';
                        } else {
                            $this->local_file_byte_offset = $file_byte_offset;
                        }
                    }
                    if ($local_io_failure_detail === null) {
                        $payload = fread($this->local_file_handle, $maximum_file_payload_bytes);
                        if (is_string($payload)) {
                            $this->local_file_byte_offset += strlen($payload);
                        }
                    }
                }

                $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
                if ($local_path_type_size_and_ctime_after_read === null) {
                    $this->close_local_file_handle();
                    $this->close_local_paths_to_push_handle();
                    $this->start_removing_push_session_after_local_change(
                        'A local path to push disappeared; remove the upload-only push session before generating another index.'
                    );
                    return;
                }
                if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                    $this->close_local_file_handle();
                    $this->close_local_paths_to_push_handle();
                    $this->start_removing_push_session_after_local_change(
                        'The local path to push changed while its file chunk was being read; remove the upload-only push session before generating another index.'
                    );
                    return;
                }
                if ($local_io_failure_detail !== null) {
                    $this->fail('local_io_error', $local_io_failure_detail);
                    return;
                }
                if (!is_string($payload) || ( $payload === '' && $file_byte_offset < $local_path_type_size_and_ctime['size'] )) {
                    $this->close_local_file_handle();
                    $this->fail('local_io_error', 'Could not read the local file to push at its receiver-confirmed cursor: ' . base64_encode($local_path_to_push['path']) . '.');
                    return;
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

        if ($this->state_before_upload_request === null) {
            $this->state_before_upload_request = $this->state;
            if (!$this->push_stream_client->start_upload_request($this->state['push_session_id'])) {
                $this->state_before_upload_request = null;
                $this->fail('request_failed', $this->push_stream_client->get_last_error());
                return;
            }
            $this->upload_request_has_parts = false;
        }

        /** @var array<string,mixed> $upload_part */
        $part_sent = $this->push_stream_client->send_part($upload_part);
        if (!$part_sent) {
            if ($this->upload_request_has_parts) {
                $this->upload_request_should_finish = true;
                return;
            }
            $this->finish_upload_request();
            if ($this->status !== 'continue') {
                return;
            }
            $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($local_path_to_push['path']) . '.');
            return;
        }
        $this->upload_request_has_parts = true;
        if ($upload_completes_local_path) {
            $this->close_local_file_handle();
            $this->state['local_paths_to_push_byte_offset'] = $local_path_to_push['next_local_paths_to_push_byte_offset'];
            $this->next_file_byte_offset = null;
            $this->local_path_to_push = null;
        } else {
            $this->next_file_byte_offset = $receiver_confirmed_bytes + strlen($upload_part['payload']);
        }
        $this->upload_request_should_finish = $this->push_stream_client->should_finish_request();
    }

    /**
     * Checks at most one fresh index entry and sends at most one deletion path.
     */
    private function upload_next_chunk_of_deleted_paths(): void
    {
        if ($this->upload_request_should_finish) {
            $this->finish_upload_request();
            return;
        }

        if ($this->local_path_to_delete === null && !$this->local_delete_list_complete) {
            if ($this->state_before_upload_request !== null) {
                $delete_list_byte_offset = $this->next_delete_list_byte_offset ?? 0;
            } else {
                $request_result = $this->push_stream_client->send_push_request('GET', 'push_status', [
                    'push_session_id' => $this->state['push_session_id'],
                ], ['accepted']);
                if ($this->handle_request_failure($request_result)) {
                    return;
                }
                /** @var array{work_deletes_bytes:int,work_deletes_complete:bool} $response */
                $response = $request_result['response'];
                $delete_list_byte_offset = $response['work_deletes_bytes'];
                if ($response['work_deletes_complete']) {
                    $this->close_local_paths_to_delete_handle();
                    $this->close_fresh_local_index_handle();
                    $this->state['phase'] = 'committing';
                    $this->store_state($this->state);
                    return;
                }
                $this->next_delete_list_byte_offset = $delete_list_byte_offset;
            }

            $maximum_delete_list_payload_bytes = $this->push_stream_client->next_delete_body_bytes(
                $delete_list_byte_offset
            );
            if ($maximum_delete_list_payload_bytes === 0) {
                if ($this->state_before_upload_request !== null && $this->upload_request_has_parts) {
                    $this->upload_request_should_finish = true;
                    return;
                }
                $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one local path to delete.');
                return;
            }

            if (!is_resource($this->local_paths_to_delete_handle)) {
                $local_paths_to_delete_path = PushPlan::local_paths_to_delete_path($this->push_state_directory);
                $this->local_paths_to_delete_handle = fopen($local_paths_to_delete_path, 'rb');
                if (!is_resource($this->local_paths_to_delete_handle)) {
                    $this->fail('local_io_error', 'Could not open the local paths to delete.');
                    return;
                }
            }
            if ($this->local_paths_to_delete_byte_offset !== $delete_list_byte_offset) {
                if (fseek($this->local_paths_to_delete_handle, $delete_list_byte_offset) !== 0) {
                    $this->close_local_paths_to_delete_handle();
                    $this->fail('local_io_error', 'Could not seek to the receiver-confirmed cursor in the local paths to delete.');
                    return;
                }
                $this->local_paths_to_delete_byte_offset = $delete_list_byte_offset;
            }

            try {
                $this->local_path_to_delete = $this->read_local_path_to_delete(
                    $this->local_paths_to_delete_handle,
                    $delete_list_byte_offset,
                    $maximum_delete_list_payload_bytes
                );
            } catch (LengthException $exception) {
                $this->fail('request_size_exhausted', $exception->getMessage());
                return;
            } catch (RuntimeException $exception) {
                $this->fail('local_io_error', $exception->getMessage());
                return;
            }
            if ($this->local_path_to_delete === null) {
                $this->local_delete_list_complete = true;
            } else {
                $this->local_paths_to_delete_byte_offset = $this->local_path_to_delete['next_delete_list_byte_offset'];
            }
            $this->planned_local_path_type_size_and_ctime = null;
            $this->next_fresh_local_index_byte_offset = null;
        }

        if ($this->local_path_to_delete === null) {
            $delete_list_byte_offset = $this->next_delete_list_byte_offset ?? 0;
            $payload = '';
        } else {
            $delete_list_byte_offset = $this->local_path_to_delete['delete_list_byte_offset'];
            $payload = $this->local_path_to_delete['path'] . "\0";
            $maximum_delete_list_payload_bytes = $this->push_stream_client->next_delete_body_bytes(
                $delete_list_byte_offset
            );
            if (strlen($payload) > $maximum_delete_list_payload_bytes) {
                if ($this->state_before_upload_request !== null && $this->upload_request_has_parts) {
                    $this->upload_request_should_finish = true;
                    return;
                }
                $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one local path to delete.');
                return;
            }

            $local_path_type_size_and_ctime = $this->stat_local_path($this->local_path_to_delete['path']);
            try {
                $planned_local_path_check_complete = $this->next_planned_local_path_check(
                    $this->local_path_to_delete['path']
                );
            } catch (RuntimeException $exception) {
                $this->fail('local_io_error', $exception->getMessage());
                return;
            }
            if (!$planned_local_path_check_complete) {
                return;
            }
            if ($local_path_type_size_and_ctime !== $this->planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_delete_handle();
                $this->close_fresh_local_index_handle();
                $this->start_removing_push_session_after_local_change(
                    'A local path selected for deletion changed after planning; remove the upload-only push session before generating another index.'
                );
                return;
            }
        }

        if ($this->state_before_upload_request === null) {
            $this->state_before_upload_request = $this->state;
            if (!$this->push_stream_client->start_upload_request($this->state['push_session_id'])) {
                $this->state_before_upload_request = null;
                $this->fail('request_failed', $this->push_stream_client->get_last_error());
                return;
            }
            $this->upload_request_has_parts = false;
        }

        $part_sent = $this->push_stream_client->send_part([
            'type' => 'delete-list',
            'offset' => $delete_list_byte_offset,
            'complete' => $this->local_delete_list_complete,
            'payload' => $payload,
        ]);
        if (!$part_sent) {
            if ($this->upload_request_has_parts) {
                $this->local_path_to_delete = null;
                $this->planned_local_path_type_size_and_ctime = null;
                $this->next_fresh_local_index_byte_offset = null;
                $this->upload_request_should_finish = true;
                return;
            }
            $this->finish_upload_request();
            if ($this->status !== 'continue') {
                return;
            }
            $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one deletion-list MIME part.');
            return;
        }

        $this->upload_request_has_parts = true;
        if ($this->next_fresh_local_index_byte_offset !== null) {
            $this->state['fresh_local_index_byte_offset'] = $this->next_fresh_local_index_byte_offset;
        }
        $this->next_delete_list_byte_offset = $delete_list_byte_offset + strlen($payload);
        $this->local_path_to_delete = null;
        $this->planned_local_path_type_size_and_ctime = null;
        $this->next_fresh_local_index_byte_offset = null;
        $this->upload_request_should_finish = $this->local_delete_list_complete
            || $this->push_stream_client->should_finish_request();
    }

    /**
     * Finishes the retained upload request and publishes its local boundary.
     */
    private function finish_upload_request(): void
    {
        $request_result = $this->push_stream_client->finish_request();
        $request_failed = $this->handle_request_failure($request_result);
        $this->upload_request_should_finish = false;
        $this->upload_request_has_parts = false;
        $this->next_file_byte_offset = null;
        $this->next_delete_list_byte_offset = null;
        $this->local_delete_list_complete = false;
        if (!$request_failed) {
            $this->state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
            $this->store_state($this->state);
        }
        $this->state_before_upload_request = null;
    }

    /**
     * Moves a changed local tree to push-session removal.
     *
     * @param string $detail Human-readable description of the local change.
     */
    private function start_removing_push_session_after_local_change(string $detail): void
    {
        $this->cancel();
        $this->state['phase'] = 'removing';
        $this->store_state($this->state);
        $this->reason = 'local_path_changed';
        $this->detail = $detail;
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
        $this->local_file_byte_offset = null;
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
        $this->local_paths_to_push_byte_offset = null;
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
        $this->local_paths_to_delete_byte_offset = null;
        $this->local_path_to_delete = null;
        $this->local_delete_list_complete = false;
    }

    /**
     * Closes the fresh local index used to check planned deletion paths.
     */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_byte_offset = null;
        $this->planned_local_path_type_size_and_ctime = null;
        $this->next_fresh_local_index_byte_offset = null;
    }

    /**
     * Requests one bounded receiver commit step and publishes a completed plan.
     */
    private function commit_push(): void
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_commit', [
            'push_session_id' => $this->state['push_session_id'],
        ], ['accepted']);
        if ($this->handle_request_failure($request_result)) {
            return;
        }
        /** @var array{send_next_request:bool} $response */
        $response = $request_result['response'];

        if ($response['send_next_request']) {
            return;
        }

        // A prior process may have published the plan after the receiver
        // completed but stopped before it removed sender.json.
        if (PushPlan::has_plan($this->push_state_directory)) {
            if (!isset($this->plan)) {
                $this->plan = PushPlan::load_retained($this->push_state_directory);
            }
            $this->plan->after_successful_push();
        }
        $this->delete_state();
        $this->status = 'complete';
    }

    /**
     * Removes an upload-only push session and discards its local PushPlan.
     */
    private function remove_push_session(): void
    {
        $request_result = $this->push_stream_client->send_push_request('POST', 'push_remove', [
            'push_session_id' => $this->state['push_session_id'],
        ], ['accepted']);
        if ($this->handle_request_failure($request_result)) {
            return;
        }
        /** @var array{removed:bool} $response */
        $response = $request_result['response'];
        if (!$response['removed']) {
            return;
        }

        // A repeated remove may follow a process that discarded the plan but
        // stopped before it removed sender.json.
        if (PushPlan::has_plan($this->push_state_directory)) {
            if (!isset($this->plan)) {
                $this->plan = PushPlan::load_retained($this->push_state_directory);
            }
            $this->plan->discard();
        }
        $this->delete_state();
        $this->status = 'restart';
        $this->reason = 'local_path_changed';
        $this->detail = 'The upload-only push session was removed. Generate a new local index before retrying.';
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
     * @param resource $local_paths_to_push_handle Open local_paths_to_push file at the next path.
     * @return LocalPathToPush|null Local path to push, or null at EOF.
     */
    private function read_local_path_to_push($local_paths_to_push_handle): ?array
    {
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
     * Reads one complete local path to delete within the next part limit.
     *
     * @param resource $local_paths_to_delete_handle Open local paths-to-delete file.
     * @param int      $delete_list_byte_offset      Byte offset at the start of the path.
     * @param int      $maximum_delete_list_payload_bytes Maximum bytes available for the path and NUL delimiter.
     * @return LocalPathToDelete|null One local path to delete, or null at EOF.
     *
     * @throws LengthException When the next complete path does not fit.
     * @throws RuntimeException When the deletion list cannot be read.
     */
    private function read_local_path_to_delete(
        $local_paths_to_delete_handle,
        int $delete_list_byte_offset,
        int $maximum_delete_list_payload_bytes
    ): ?array {
        $path = stream_get_line($local_paths_to_delete_handle, $maximum_delete_list_payload_bytes, "\0");
        if ($path === false) {
            if (feof($local_paths_to_delete_handle)) {
                return null;
            }
            throw new RuntimeException('Could not read the next local path to delete.');
        }
        $next_delete_list_byte_offset = ftell($local_paths_to_delete_handle);
        if (!is_int($next_delete_list_byte_offset)) {
            throw new RuntimeException('Could not determine the next byte offset in the local paths to delete.');
        }
        if ($next_delete_list_byte_offset !== $delete_list_byte_offset + strlen($path) + 1) {
            throw new LengthException('The current request-body budget cannot fit one complete local path to delete.');
        }
        return [
            'path' => $path,
            'delete_list_byte_offset' => $delete_list_byte_offset,
            'next_delete_list_byte_offset' => $next_delete_list_byte_offset,
        ];
    }

    /**
     * Checks at most one fresh local index entry against a local path to delete.
     *
     * Deletion paths are sorted, so one retained index handle only moves
     * forward. False means another sender step must check the next index entry.
     *
     * @param string $path Raw document-root-relative path.
     * @return bool Whether the fresh local index reached this path or passed it.
     */
    private function next_planned_local_path_check(string $path): bool
    {
        if (!is_resource($this->fresh_local_index_handle)) {
            $this->fresh_local_index_handle = fopen(
                $this->push_state_directory . '/fresh_local_index.jsonl',
                'rb'
            );
            if (!is_resource($this->fresh_local_index_handle)) {
                throw new RuntimeException('Could not open the fresh local index while checking a planned replacement.');
            }
            $this->fresh_local_index_byte_offset = 0;
        }

        if (
            $this->fresh_local_index_entry === null
            && $this->fresh_local_index_byte_offset !== $this->state['fresh_local_index_byte_offset']
        ) {
            if (fseek($this->fresh_local_index_handle, $this->state['fresh_local_index_byte_offset']) !== 0) {
                throw new RuntimeException('Could not seek to the active byte offset in the fresh local index.');
            }
            $this->fresh_local_index_byte_offset = $this->state['fresh_local_index_byte_offset'];
        }

        if ($this->fresh_local_index_entry === null) {
            $line = fgets($this->fresh_local_index_handle);
            if ($line === false) {
                if (feof($this->fresh_local_index_handle)) {
                    $this->planned_local_path_type_size_and_ctime = null;
                    $this->next_fresh_local_index_byte_offset = null;
                    return true;
                }
                throw new RuntimeException('Could not read the fresh local index while checking a planned replacement.');
            }
            $next_fresh_local_index_byte_offset = ftell($this->fresh_local_index_handle);
            if (!is_int($next_fresh_local_index_byte_offset)) {
                throw new RuntimeException('Could not determine the next byte offset in the fresh local index.');
            }
            $this->fresh_local_index_byte_offset = $next_fresh_local_index_byte_offset;
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
                'next_fresh_local_index_byte_offset' => $next_fresh_local_index_byte_offset,
            ];
        }

        $path_comparison = strcmp($this->fresh_local_index_entry['path'], $path);
        if ($path_comparison < 0) {
            $this->state['fresh_local_index_byte_offset'] = $this->fresh_local_index_entry['next_fresh_local_index_byte_offset'];
            $this->fresh_local_index_entry = null;
            if ($this->state_before_upload_request === null) {
                $this->store_state($this->state);
            }
            return false;
        }
        if ($path_comparison > 0) {
            $this->planned_local_path_type_size_and_ctime = null;
            $this->next_fresh_local_index_byte_offset = null;
            return true;
        }
        $this->planned_local_path_type_size_and_ctime = $this->fresh_local_index_entry['local_path_type_size_and_ctime'];
        $this->next_fresh_local_index_byte_offset = $this->fresh_local_index_entry['next_fresh_local_index_byte_offset'];
        $this->fresh_local_index_entry = null;
        return true;
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
     * @return bool Whether the request failed.
     */
    private function handle_request_failure(array $request_result): bool
    {
        if ($request_result['status'] === 'complete') {
            return false;
        }
        $durable_state = $this->state_before_upload_request ?? $this->state;
        $request_sizer_state = $this->push_stream_client->get_request_sizer_state();
        if ($durable_state['request_sizer_state'] !== $request_sizer_state) {
            $durable_state['request_sizer_state'] = $request_sizer_state;
            $this->store_state($durable_state);
        }
        $this->fail($request_result['reason'], $request_result['detail']);
        return true;
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
     * Atomically stores the target exclusions for the active push.
     *
     * @param list<string> $excluded_paths_b64 Base64-encoded excluded paths.
     */
    private function store_excluded_paths(array $excluded_paths_b64): void
    {
        try {
            $json = json_encode($excluded_paths_b64, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode excluded paths.', 0, $exception);
        }
        $temporary_path = $this->excluded_paths_path . '.tmp';
        if (file_put_contents($temporary_path, $json) !== strlen($json)) {
            throw new RuntimeException('Failed to write excluded paths: ' . $temporary_path);
        }
        if (!rename($temporary_path, $this->excluded_paths_path)) {
            throw new RuntimeException('Failed to move excluded paths into place: ' . $this->excluded_paths_path);
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
     * Stops the sender after a failed step.
     *
     * @param string|null $reason Machine-readable failure classification.
     * @param string|null $detail Human-readable failure explanation.
     */
    private function fail(?string $reason, ?string $detail): void
    {
        $this->status = 'failed';
        $this->reason = $reason;
        $this->detail = $detail;
    }
}
