<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sender failures are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Drives one local-files push through bounded planning and streaming requests.
 *
 * PushFilesSender owns the only caller-visible lifecycle. It holds the lock,
 * creates and removes the target push session, drives its internal PushPlan,
 * streams the selected paths, commits the push, and saves the completed fresh
 * local index as the local index at the previous push. The target owns the
 * upload cursor for every path and for the deletion list. Durable sender state
 * retains the top-level phase, the selected path-list cursor, and learned
 * request limits needed after a process restart.
 *
 * ## Usage
 *
 *  1. Start a new sender with `start()`, or continue an unfinished sender with
 *     `resume()`. Both methods acquire the lifecycle lock.
 *  2. Call `next_step()` while the current process has enough time and memory
 *     for another step.
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
 * durable boundary. close() only releases resources and the lock; it never
 * finishes an open request. If a process stops without closing, the next
 * process starts from the preceding durable boundary and reads
 * receiver-confirmed work before sending more data.
 *
 * A new sender calls `push_create` to learn target-owned exclusions before
 * starting PushPlan. The plan builds the fresh local index and merges it with
 * the index at the previous push, one bounded step at a time. After planning
 * completes, local files, symlinks, and empty directories stream through
 * multipart requests. The raw deletion list follows, and repeated `push_commit`
 * calls let the target install the work in bounded steps. A confirmed commit
 * enters another phase which saves the fresh local index as the local index at
 * the previous push through a swap file. Index completion, plan completion,
 * local-index saving, and plan discard each have a separate durable phase. A
 * stopped process therefore repeats only an idempotent boundary action rather
 * than a group of unrelated transitions.
 *
 * sender.json owns the top-level phase. During `planning`, cursor.json owns the
 * plan's internal phase and continuation offsets; sender.json does not duplicate
 * them. A completed plan cursor remains until the target commit and local index
 * save have both finished. A removed target session enters `discarding_plan`
 * before that cursor is deleted.
 *
 * ## Resume after local changes
 *
 * Each selected path to push carries the type, size, and ctime from the fresh
 * local index used for planning. The sender compares the live path with those
 * values before sending and again after each read. A difference removes the
 * upload-only push session, because its work and the planned local index no
 * longer describe the same local tree.
 *
 * Receiver-confirmed file and deletion-list positions are never copied into
 * active state. A newly opened sender reads them from `push_status`; a
 * successful upload keeps them in memory for later steps in the same lifecycle.
 * The sender sends the completed deletion plan without checking the live local
 * tree again. A local path which reappears after planning may therefore be
 * deleted by this push; the next push will send it again. If a local path to
 * push changes, the sender removes the upload-only push session, discards the
 * plan, and changes the sender status to `restart` so the caller can start a
 * new sender which builds a fresh local index.
 *
 * ## Streaming and durability
 *
 * Each local-path upload or deletion step sends at most one multipart part and
 * holds at most one bounded payload string. A deletion part contains one path,
 * except for the empty part which marks the deletion list complete.
 * Multipart bytes leave for the network before `send_part()` returns. One
 * request carries successive parts until its request-body budget is spent or
 * the current path phase ends. An open sender retains that request, its
 * path-list handles, and its current local file handle between steps.
 *
 * Saving a complete local index after commit is the deliberate exception to
 * bounded steps.
 * A representative index entry is about 150 bytes, so one million paths produce
 * roughly 150 MB. Even a 10 MiB/s drive copies that in about 15 seconds. Keeping
 * two copy cursors, two retained handles, and per-chunk state writes for larger
 * installations is not justified until measurements show otherwise. PHP's
 * copy() streams the bytes through a swap file, and rename() atomically moves
 * the completed copy into place. This accepts that a 1 MiB/s drive reaches 30
 * seconds at roughly 200,000 paths. A stopped copy is repeated by the next call.
 *
 * The sender has no overall time limit. The caller decides whether to take
 * another step. Network operations apply connect, no-progress, and response
 * wait limits, while a connection that continues moving bytes may run longer.
 *
 * @phpstan-type LocalPathTypeSizeAndCtime array{type:'file'|'directory'|'symlink',size:int,ctime:int}
 * @phpstan-type LocalPathStat array{type:'file'|'directory'|'symlink'|'unsupported',size:int,ctime:int}
 * @phpstan-type LocalPathToPush array{path:string,path_b64:string,next_local_paths_to_push_byte_offset:int,planned_local_path_type_size_and_ctime:LocalPathTypeSizeAndCtime}
 * @phpstan-type LocalPathToDelete array{path:string,delete_list_byte_offset:int,next_delete_list_byte_offset:int}
 * @phpstan-type State array{push_session_id:string,phase:'creating'|'starting_plan'|'planning'|'pushing_paths'|'pushing_deletes'|'committing'|'saving_local_index_at_previous_push'|'completing'|'removing'|'discarding_plan',local_paths_to_push_byte_offset:int,max_part_bytes:int|null,request_sizer_state:array{request_body_bytes:int,ceiling_bytes:int|null}}
 */
final class PushFilesSender
{
    /** @var string Local document root whose local paths to push are sent. */
    private string $docroot;

    /** @var string Local push state directory shared with PushPlan. */
    private string $push_state_directory;

    /** @var string Path where the serialized sender state is stored. */
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

    /** @var 'closed'|'sending_parts'|'finishing' In-memory stage of the multipart request. */
    private string $upload_request_stage = 'closed';

    /** @var State|null Durable state from before the open upload request. */
    private ?array $state_before_upload_request = null;

    /** @var int|null Next local file byte offset within the open upload request. */
    private ?int $next_file_byte_offset = null;

    /** @var int|null Next deletion-list byte offset within the open upload request. */
    private ?int $next_delete_list_byte_offset = null;

    /** @var int|null Local file byte offset confirmed by the receiver during this lifecycle. */
    private ?int $receiver_confirmed_file_byte_offset = null;

    /** @var int|null Deleted-paths byte offset confirmed by the receiver during this lifecycle. */
    private ?int $receiver_confirmed_deleted_paths_byte_offset = null;

    /** @var bool|null Whether the receiver confirmed the complete deleted-paths list during this lifecycle. */
    private ?bool $receiver_confirmed_deleted_paths_complete = null;

    /** @var array<string,mixed> Options used to construct the PushRequestSizer. */
    private array $request_sizer_options;

    /** @var array<string,mixed> Push stream client options used by start() or resume(). */
    private array $push_stream_client_options;

    /**
     * Starts a new sender and acquires exclusive ownership of its push state.
     *
     * The returned sender begins in `creating`. An existing active state is
     * rejected so unfinished work cannot be replaced. The returned sender
     * retains its lock until close().
     *
     * @param array $options {
     *     Push, push stream client, and local-file options.
     *
     *     @type string                  $docroot                Required local document-root directory.
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
     * works from that in-memory state, storing each later durable boundary
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
                $sender->plan = PushPlan::resume($sender->push_state_directory, $sender->docroot);
            }
            return $sender;
        } catch (Throwable $throwable) {
            $sender->close();
            throw $throwable;
        }
    }

    /**
     * Configures the paths and push stream client options shared by start() and resume().
     *
     * @param array<string,mixed> $options Options documented by start().
     *
     * @throws InvalidArgumentException If local path or push stream client options are invalid.
     */
    private function __construct(array $options)
    {
        $docroot = $options['docroot'] ?? null;
        $push_state_directory = $options['push_state_directory'] ?? null;
        if (!is_string($docroot) || !is_dir($docroot) || is_link($docroot)) {
            throw new InvalidArgumentException('PushFilesSender requires a real docroot directory.');
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

        $canonical_docroot = realpath($docroot);
        if ($canonical_docroot === false) {
            throw new InvalidArgumentException('PushFilesSender requires a real docroot directory.');
        }
        $this->docroot = rtrim($canonical_docroot, '/');
        $this->push_state_directory = rtrim($push_state_directory, '/');
        $this->state_path = $this->push_state_directory . '/sender.json';
        $this->lock_path = $this->push_state_directory . '/sender.lock';
        $this->excluded_paths_path = $this->push_state_directory . '/excluded_paths.json';
        $this->request_sizer_options = $request_sizer_options;
        $this->push_stream_client_options = $push_stream_client_options;
    }

    /**
     * Performs the next step for the current phase.
     *
     * start() or resume() has already acquired the lifecycle lock and loaded the
     * durable state, so this method only dispatches its current phase. Every
     * phase step is bounded except the deliberate completed-index copy described
     * in the class documentation. A caller stopping after this method returns
     * true calls cancel() before close(); close() does not finish an open
     * multipart request. A false return directs the caller to get_status(),
     * where `restart` means the old push session and local plan are gone and a
     * new sender is required.
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
            case 'starting_plan':
                $this->start_plan();
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
            case 'saving_local_index_at_previous_push':
                $this->save_local_index_at_previous_push();
                break;
            case 'completing':
                $this->complete_push();
                break;
            case 'removing':
                $this->remove_push_session();
                break;
            case 'discarding_plan':
                $this->discard_plan();
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
     * Returns the sender phase retained in memory.
     *
     * After terminal cleanup removes sender.json, this remains the last phase
     * performed by the open sender.
     *
     * @return string Current phase, or the last phase after terminal cleanup.
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
        if ($this->upload_request_stage === 'closed') {
            return;
        }
        $this->push_stream_client->cancel_request();
        /** @var State $state_before_upload_request */
        $state_before_upload_request = $this->state_before_upload_request;
        $this->state = $state_before_upload_request;
        $this->state_before_upload_request = null;
        $this->upload_request_stage = 'closed';
        $this->next_file_byte_offset = null;
        $this->next_delete_list_byte_offset = null;
        $this->receiver_confirmed_file_byte_offset = null;
        $this->receiver_confirmed_deleted_paths_byte_offset = null;
        $this->receiver_confirmed_deleted_paths_complete = null;
        $this->local_delete_list_complete = false;
        $this->local_path_to_push = null;
        $this->local_path_to_delete = null;
        $this->close_local_file_handle();
        $this->close_local_paths_to_push_handle();
        $this->close_local_paths_to_delete_handle();
    }

    /**
     * Releases open resources and the lifecycle lock without finishing a request.
     *
     * The caller uses cancel() first when stopping with an open multipart
     * request. Durable state remains available to resume unless next_step()
     * already completed or discarded the lifecycle.
     */
    public function close(): void
    {
        if (isset($this->plan)) {
            $this->plan->close();
        }
        $this->close_local_file_handle();
        $this->close_local_paths_to_push_handle();
        $this->close_local_paths_to_delete_handle();
        if (isset($this->push_stream_client)) {
            $this->push_stream_client->close();
        }
        $this->upload_request_stage = 'closed';
        $this->state_before_upload_request = null;
        if (is_resource($this->lock_handle)) {
            $this->release_lock($this->lock_handle);
        }
        $this->lock_handle = null;
    }

    /**
     * Creates the push session and stores the policy returned by the target.
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
        $this->state['phase'] = 'starting_plan';
        $this->store_state($this->state);
    }

    /**
     * Creates or reopens PushPlan after the target exclusions are stored.
     */
    private function start_plan(): void
    {
        if (PushPlan::has_plan($this->push_state_directory)) {
            $this->plan = PushPlan::resume($this->push_state_directory, $this->docroot);
        } else {
            $this->plan = PushPlan::start($this->push_state_directory, $this->docroot);
        }
        $this->state['phase'] = 'planning';
        $this->store_state($this->state);
    }

    /**
     * Performs one PushPlan step and moves to local paths to push at plan completion.
     */
    private function next_plan_step(): void
    {
        try {
            $has_next_step = $this->plan->next_step();
        } catch (RuntimeException $exception) {
            $this->fail('local_io_error', $exception->getMessage());
            return;
        }
        if (!$has_next_step) {
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
        // Confirm a request after the transfer ends or its remaining budget cannot fit more work.
        if ($this->upload_request_stage === 'finishing') {
            $this->finish_upload_request();
            return;
        }

        // Keep the planned-path list open across calls while this phase is active.
        if (!is_resource($this->local_paths_to_push_handle)) {
            $local_paths_to_push_path = PushPlan::local_paths_to_push_path($this->push_state_directory);
            $this->local_paths_to_push_handle = fopen($local_paths_to_push_path, 'rb');
            if (!is_resource($this->local_paths_to_push_handle)) {
                $this->fail('local_io_error', 'Could not open the local paths to push.');
                return;
            }
        }

        // Select one planned path and retain it while its file chunks are prepared.
        if ($this->local_path_to_push === null) {
            if ($this->local_paths_to_push_byte_offset !== $this->state['local_paths_to_push_byte_offset']) {
                if (fseek($this->local_paths_to_push_handle, $this->state['local_paths_to_push_byte_offset']) !== 0) {
                    $this->fail('local_io_error', 'Failed to seek to the active byte offset in the local paths to push.');
                    return;
                }
                $this->local_paths_to_push_byte_offset = $this->state['local_paths_to_push_byte_offset'];
            }
            try {
                $this->local_path_to_push = $this->read_next_local_path_to_push($this->local_paths_to_push_handle);
            } catch (RuntimeException $exception) {
                $this->fail('local_io_error', $exception->getMessage());
                return;
            }
            if ($this->local_path_to_push !== null) {
                $this->local_paths_to_push_byte_offset = $this->local_path_to_push['next_local_paths_to_push_byte_offset'];
            }
        }
        $local_path_to_push = $this->local_path_to_push;

        // End this phase only after any request containing its final paths is confirmed.
        if ($local_path_to_push === null) {
            if ($this->upload_request_stage !== 'closed') {
                $this->finish_upload_request();
                return;
            }
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->receiver_confirmed_file_byte_offset = null;
            $this->state['phase'] = 'pushing_deletes';
            $this->store_state($this->state);
            return;
        }

        $planned_local_path_type_size_and_ctime = $local_path_to_push['planned_local_path_type_size_and_ctime'];
        $local_path_type_size_and_ctime = $this->stat_local_path($local_path_to_push['path']);

        // A path which disappeared belongs in a newly generated plan, not this upload-only session.
        if ($local_path_type_size_and_ctime === null) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change();
            return;
        }

        // A type the protocol cannot send requires a new plan rather than partial progress.
        if ($local_path_type_size_and_ctime['type'] === 'unsupported') {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change();
            return;
        }

        // Changed type, size, or ctime means this push no longer describes the completed plan.
        if ($local_path_type_size_and_ctime !== $planned_local_path_type_size_and_ctime) {
            $this->close_local_file_handle();
            $this->close_local_paths_to_push_handle();
            $this->start_removing_push_session_after_local_change();
            return;
        }

        // Continue at the tentative byte offset in an open request, then at the
        // target-confirmed offset cached during this run. Ask the target only
        // when neither byte offset is available. The same call sends one part
        // because target-confirmed offsets are not stored in sender state and
        // even a caller which performs one step per process must make progress.
        if ($this->upload_request_stage !== 'closed') {
            $file_byte_offset = $this->next_file_byte_offset ?? 0;
        } elseif ($this->receiver_confirmed_file_byte_offset !== null) {
            $file_byte_offset = $this->receiver_confirmed_file_byte_offset;
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
            $file_byte_offset = $receiver_path_status['state'] === 'partial'
                && $receiver_path_type === 'file'
                && $receiver_path_status['accepted_bytes'] <= $local_path_type_size_and_ctime['size']
                    ? $receiver_path_status['accepted_bytes']
                    : 0;
            $this->receiver_confirmed_file_byte_offset = $file_byte_offset;
        }
        $this->next_file_byte_offset = $file_byte_offset;

        $upload_part = null;
        $upload_completes_local_path = false;

        // Directory and symlink values each fit in one MIME part and need no byte cursor.
        if ($local_path_type_size_and_ctime['type'] === 'directory') {
            $directory_is_empty = $this->directory_is_empty($local_path_to_push['path']);
            if ($directory_is_empty === null) {
                $this->fail('local_io_error', 'Could not read the local directory to push: ' . base64_encode($local_path_to_push['path']) . '.');
                return;
            }
            $local_path_type_size_and_ctime_after_read = $this->stat_local_path($local_path_to_push['path']);
            if ($local_path_type_size_and_ctime_after_read === null) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change();
                return;
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change();
                return;
            }
            if (!$directory_is_empty) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change();
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
                $this->start_removing_push_session_after_local_change();
                return;
            }
            if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                $this->close_local_paths_to_push_handle();
                $this->start_removing_push_session_after_local_change();
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

        // A file contributes at most one bounded chunk during this call and remains open for the next.
        if ($local_path_type_size_and_ctime['type'] === 'file') {
            $maximum_file_payload_bytes = $this->push_stream_client->next_file_body_bytes(
                $local_path_to_push['path'],
                $local_path_type_size_and_ctime['size'],
                $file_byte_offset
            );
            if ($maximum_file_payload_bytes === 0) {
                if ($this->upload_request_stage !== 'closed' && $this->push_stream_client->has_sent_parts()) {
                    $this->upload_request_stage = 'finishing';
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
                    $this->start_removing_push_session_after_local_change();
                    return;
                }
                if ($local_path_type_size_and_ctime_after_read !== $planned_local_path_type_size_and_ctime) {
                    $this->close_local_file_handle();
                    $this->close_local_paths_to_push_handle();
                    $this->start_removing_push_session_after_local_change();
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

        // Open a request only after its first part is ready. The snapshot lets
        // cancellation return to the last target-confirmed sender boundary.
        if ($this->upload_request_stage === 'closed') {
            $this->state_before_upload_request = $this->state;
            if (!$this->push_stream_client->start_upload_request($this->state['push_session_id'])) {
                $this->state_before_upload_request = null;
                $this->fail('request_failed', $this->push_stream_client->get_last_error());
                return;
            }
            $this->upload_request_stage = 'sending_parts';
        }

        /** @var array<string,mixed> $upload_part */
        $part_sent = $this->push_stream_client->send_part($upload_part);

        // Confirm an existing request before retrying this part in a new one.
        // An empty request which cannot fit the part cannot make progress.
        if (!$part_sent) {
            if ($this->push_stream_client->has_sent_parts()) {
                $this->upload_request_stage = 'finishing';
                return;
            }
            $this->finish_upload_request();
            if ($this->status !== 'continue') {
                return;
            }
            $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($local_path_to_push['path']) . '.');
            return;
        }

        // The next path may be prepared once this path is complete in the open request.
        // Its list cursor remains tentative until the target confirms that request.
        if ($upload_completes_local_path) {
            $this->close_local_file_handle();
            $this->state['local_paths_to_push_byte_offset'] = $local_path_to_push['next_local_paths_to_push_byte_offset'];
            $this->next_file_byte_offset = null;
            $this->local_path_to_push = null;
        } else {
            $this->next_file_byte_offset = $file_byte_offset + strlen($upload_part['payload']);
        }
        if ($this->push_stream_client->should_finish_request()) {
            $this->upload_request_stage = 'finishing';
        }
    }

    /**
     * Sends at most one path from the completed deletion plan.
     */
    private function upload_next_chunk_of_deleted_paths(): void
    {
        // Confirm a request after the deletion list ends or its remaining budget is spent.
        if ($this->upload_request_stage === 'finishing') {
            $this->finish_upload_request();
            return;
        }

        // Select one complete path at the tentative or target-confirmed list position.
        if ($this->local_path_to_delete === null && !$this->local_delete_list_complete) {
            if ($this->upload_request_stage !== 'closed') {
                $delete_list_byte_offset = $this->next_delete_list_byte_offset ?? 0;
            } else {
                // Without a cached position, ask how much of the list the target accepted.
                // The same call then sends one part so even a one-step process makes progress.
                if ($this->receiver_confirmed_deleted_paths_byte_offset === null) {
                    $request_result = $this->push_stream_client->send_push_request('GET', 'push_status', [
                        'push_session_id' => $this->state['push_session_id'],
                    ], ['accepted']);
                    if ($this->handle_request_failure($request_result)) {
                        return;
                    }
                    /** @var array{work_deletes_bytes:int,work_deletes_complete:bool} $response */
                    $response = $request_result['response'];
                    $this->receiver_confirmed_deleted_paths_byte_offset = $response['work_deletes_bytes'];
                    $this->receiver_confirmed_deleted_paths_complete = $response['work_deletes_complete'];
                }
                $delete_list_byte_offset = $this->receiver_confirmed_deleted_paths_byte_offset;
                if ($this->receiver_confirmed_deleted_paths_complete) {
                    $this->close_local_paths_to_delete_handle();
                    $this->receiver_confirmed_deleted_paths_byte_offset = null;
                    $this->receiver_confirmed_deleted_paths_complete = null;
                    $this->state['phase'] = 'committing';
                    $this->store_state($this->state);
                    return;
                }
                $this->next_delete_list_byte_offset = $delete_list_byte_offset;
            }

            // Finish a request which cannot fit another complete deleted path.
            $maximum_delete_list_payload_bytes = $this->push_stream_client->next_delete_body_bytes(
                $delete_list_byte_offset
            );
            if ($maximum_delete_list_payload_bytes === 0) {
                if ($this->upload_request_stage !== 'closed' && $this->push_stream_client->has_sent_parts()) {
                    $this->upload_request_stage = 'finishing';
                    return;
                }
                $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one local path to delete.');
                return;
            }

            // Keep the completed deletion plan open while successive calls consume it.
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
                $this->local_path_to_delete = $this->read_next_local_path_to_delete(
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
        }

        // EOF becomes the empty part which marks the deletion list complete.
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
                if ($this->upload_request_stage !== 'closed' && $this->push_stream_client->has_sent_parts()) {
                    $this->upload_request_stage = 'finishing';
                    return;
                }
                $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one local path to delete.');
                return;
            }
        }

        // Open a request only after the next complete part is ready.
        if ($this->upload_request_stage === 'closed') {
            $this->state_before_upload_request = $this->state;
            if (!$this->push_stream_client->start_upload_request($this->state['push_session_id'])) {
                $this->state_before_upload_request = null;
                $this->fail('request_failed', $this->push_stream_client->get_last_error());
                return;
            }
            $this->upload_request_stage = 'sending_parts';
        }

        $part_sent = $this->push_stream_client->send_part([
            'type' => 'delete-list',
            'offset' => $delete_list_byte_offset,
            'complete' => $this->local_delete_list_complete,
            'payload' => $payload,
        ]);

        // Confirm an existing request before retrying this path in a new one.
        // An empty request which cannot fit the part cannot make progress.
        if (!$part_sent) {
            if ($this->push_stream_client->has_sent_parts()) {
                $this->local_path_to_delete = null;
                $this->upload_request_stage = 'finishing';
                return;
            }
            $this->finish_upload_request();
            if ($this->status !== 'continue') {
                return;
            }
            $this->fail('request_size_exhausted', 'The current request-body budget cannot fit one deletion-list MIME part.');
            return;
        }

        // Keep the next list position tentative until the target confirms the request.
        $this->next_delete_list_byte_offset = $delete_list_byte_offset + strlen($payload);
        $this->local_path_to_delete = null;
        if ($this->local_delete_list_complete || $this->push_stream_client->should_finish_request()) {
            $this->upload_request_stage = 'finishing';
        }
    }

    /**
     * Finishes the retained upload request and stores any changed local boundary.
     */
    private function finish_upload_request(): void
    {
        $request_phase = $this->state_before_upload_request['phase'];
        $request_result = $this->push_stream_client->finish_request();
        $request_had_parts = $request_result['parts_sent'] > 0;
        $request_failed = $this->handle_request_failure($request_result);
        if ($request_failed) {
            /** @var State $state_before_upload_request */
            $state_before_upload_request = $this->state_before_upload_request;
            $this->state = $state_before_upload_request;
            $this->state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
            $this->receiver_confirmed_file_byte_offset = null;
            $this->receiver_confirmed_deleted_paths_byte_offset = null;
            $this->receiver_confirmed_deleted_paths_complete = null;
        } elseif ($request_had_parts && $request_phase === 'pushing_paths') {
            $this->receiver_confirmed_file_byte_offset = $this->next_file_byte_offset ?? 0;
        } elseif ($request_had_parts && $request_phase === 'pushing_deletes') {
            $this->receiver_confirmed_deleted_paths_byte_offset = $this->next_delete_list_byte_offset ?? 0;
            $this->receiver_confirmed_deleted_paths_complete = $this->local_delete_list_complete;
        }
        $this->upload_request_stage = 'closed';
        $this->next_file_byte_offset = null;
        $this->next_delete_list_byte_offset = null;
        $this->local_delete_list_complete = false;
        if (!$request_failed) {
            $this->state['request_sizer_state'] = $this->push_stream_client->get_request_sizer_state();
            if ($this->state !== $this->state_before_upload_request) {
                $this->store_state($this->state);
            }
        }
        $this->state_before_upload_request = null;
    }

    /**
     * Moves a changed local tree to push-session removal.
     */
    private function start_removing_push_session_after_local_change(): void
    {
        $this->cancel();
        $this->state['phase'] = 'removing';
        $this->store_state($this->state);
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
     * Requests one bounded receiver commit step.
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
        $this->state['phase'] = 'saving_local_index_at_previous_push';
        $this->store_state($this->state);
    }

    /**
     * Saves the committed fresh local index as the local index at the previous push.
     *
     * This uses the same deliberate whole-index copy as the pre-plan snapshot.
     * If the process stops before the next phase is stored, repeating the copy
     * is safe and leaves readers on either the old or complete new index.
     */
    private function save_local_index_at_previous_push(): void
    {
        $fresh_local_index = PushPlan::fresh_local_index_path($this->push_state_directory);
        $local_index_at_previous_push = PushPlan::local_index_at_previous_push_path(
            $this->push_state_directory
        );
        try {
            $this->copy_through_swap_file(
                $fresh_local_index,
                $local_index_at_previous_push
            );
        } catch (RuntimeException $exception) {
            $this->fail('local_io_error', $exception->getMessage());
            return;
        }
        $this->state['phase'] = 'completing';
        $this->store_state($this->state);
    }

    /**
     * Copies one complete index through a swap file and moves it into place.
     *
     * copy() streams without holding the complete index in memory. Only the
     * final rename is atomic: interruption during copy leaves the existing
     * index untouched and a later call overwrites the partial swap file.
     *
     * @param string $source_path Complete index to copy.
     * @param string $target_path Final path for the copied index.
     */
    private function copy_through_swap_file(string $source_path, string $target_path): void
    {
        $swap_index = $target_path . '.swap';
        if (!@copy($source_path, $swap_index)) {
            throw new RuntimeException('Failed to copy the index to its swap file: ' . $swap_index);
        }
        if (!@rename($swap_index, $target_path)) {
            throw new RuntimeException('Failed to move the copied index into place: ' . $target_path);
        }
    }

    /**
     * Completes the sender after removing the completed planning cursor.
     */
    private function complete_push(): void
    {
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
     * Requests one bounded removal step for an upload-only push session.
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
        $this->state['phase'] = 'discarding_plan';
        $this->store_state($this->state);
    }

    /**
     * Restarts the sender after removing the discarded planning cursor.
     */
    private function discard_plan(): void
    {
        if (PushPlan::has_plan($this->push_state_directory)) {
            if (!isset($this->plan)) {
                $this->plan = PushPlan::load_retained($this->push_state_directory);
            }
            $this->plan->discard();
        }
        $this->delete_state();
        $this->status = 'restart';
        $this->reason = 'local_path_changed';
        $this->detail = 'The upload-only push session was removed. Run the push command again to build a fresh local index.';
    }

    /**
     * Builds a streaming client from the sizing state in active sender state.
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
    private function read_next_local_path_to_push($local_paths_to_push_handle): ?array
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
    private function read_next_local_path_to_delete(
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
            'size' => $type === 'directory' ? 0 : (int) $path_stat['size'],
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
