<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sender failures are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Drives one local-files push through bounded planning and streaming requests.
 *
 * PushFilesSender joins two durable protocols. PushPlan selects local paths in
 * bounded steps and owns the local index from which that selection was made.
 * The target owns the upload cursor for every path and for the deletion list.
 * This class retains only the remote session phase, the next selected-path
 * offset, source evidence for a partial file, retry count, and learned request
 * limits needed to join those protocols after a process restart.
 *
 * ## Usage
 *
 * A sender has the same explicit lifecycle as PushPlan:
 *
 *  1. Start a new sender with `start()`, or continue an unfinished sender with
 *     `resume()`. Both methods acquire the per-site sender lock.
 *  2. Call `next_step()` while the current process has enough time and memory
 *     for another bounded step.
 *  3. Call `close()` to release the sender lock, even when more work remains.
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
 * starts PushPlan with that policy. After planning completes, selected files,
 * symlinks, and empty directories stream through multipart requests. The raw
 * deletion list follows, and repeated `push_commit` calls let the receiver
 * install the work in bounded steps. Only a receiver-confirmed commit publishes
 * the plan's fresh local index as the local index at the previous push.
 *
 * ## Resume and source changes
 *
 * Before sending the selected path at the durable list offset, the sender asks
 * `push_status` what the receiver has accepted. A partial file resumes only
 * when its current type, size, and ctime equal the source token saved after the
 * prior accepted part. Otherwise it starts that path at offset zero. This
 * prevents bytes from different source versions from being joined.
 *
 * The deletion cursor is never copied into sender state. Each step reads it
 * from `push_status` and checks that it belongs to the plan's stable deletion
 * list. If a selected path disappears, the sender removes the upload-only
 * remote session, discards the plan, and returns `restart` so the caller can
 * produce a new local index.
 *
 * ## Streaming and durability
 *
 * Each positive-work or deletion step sends at most one multipart part and
 * holds at most one bounded payload string. Multipart bytes leave for the
 * network before `send_part()` returns. Sender state and PushPlan's cursor are
 * written atomically, and a per-site lock permits only one open sender at a
 * time.
 *
 * @phpstan-type SourceToken array{type:'file'|'directory'|'symlink',size:int,ctime:int}
 * @phpstan-type SelectedSource array{path:string,path_b64:string,local_paths_to_push_byte_offset:int,next_local_paths_to_push_byte_offset:int,source_token:SourceToken|null}
 * @phpstan-type State array{push_session_id:string,phase:'creating'|'planning'|'pushing_paths'|'pushing_deletes'|'committing'|'removing',local_paths_to_push_byte_offset:int,source_token:SourceToken|null,recoverable_failures:int,max_part_bytes:int|null,request_sizer_state:array{request_body_bytes:int,ceiling_bytes:int|null,growth_holdoff_remaining:int}}
 */
final class PushFilesSender
{
    private const MAXIMUM_RECOVERABLE_FAILURES = 5;

    /** @var string Local document root whose selected paths are sent. */
    private string $docroot;

    /** @var string Fresh path-sorted local index used to start a PushPlan. */
    private string $fresh_local_index_path;

    /** @var string Per-target directory shared with PushPlan. */
    private string $site_dir;

    /** @var string Atomic checkpoint for the active remote workflow. */
    private string $state_path;

    /** @var string Advisory lock file for one open lifecycle. */
    private string $lock_path;

    /** @var resource|null Exclusive lock held from start() or resume() through close(). */
    private $lock_handle = null;

    /** @var State|null Active state, or null after terminal completion. */
    private ?array $state = null;

    /** @var bool Whether close() has released the lock. */
    private bool $closed = false;

    /** @var MultipartPushStreamClient Reusable connection and request-sizing context. */
    private MultipartPushStreamClient $client;

    /** @var array<string,mixed> Options used to construct the PushRequestSizer. */
    private array $request_sizer_config;

    /** @var array<string,mixed> Transport options used by start() or resume(). */
    private array $client_options;

    /**
     * Starts a new sender and acquires exclusive ownership of its site state.
     *
     * The initial `creating` state is written before this method returns. An
     * existing sender state is rejected so unfinished work cannot be replaced.
     * The returned sender retains its lock until close().
     *
     * @param array $options {
     *     Sender, transport, and source configuration.
     *
     *     @type string                  $docroot               Required local document-root directory.
     *     @type string                  $fresh_local_index_path Required fresh local index path.
     *     @type string                  $site_dir               Required per-target durable state directory.
     *     @type string                  $base_url               Required exporter API URL.
     *     @type Site_Export_HMAC_Client $hmac_client            Required envelope signer.
     *     @type bool                    $allow_http             Explicit plain-HTTP opt-in. Default false.
     *     @type int|float|string        $chunk_bytes            Maximum one source read. Default 4 MiB.
     *     @type int|float|string        $connect_timeout        Connect phase seconds. Default 30.
     *     @type int|float|string        $stall_timeout          No-upload-progress seconds. Default 60.
     *     @type int|float|string        $response_timeout       No-response-progress seconds. Default 300.
     *     @type array                   $request_sizer_config   Optional PushRequestSizer bounds.
     * }
     * @phpstan-param array<string,mixed> $options
     * @return self Open sender at its initial durable state.
     */
    public static function start(array $options): self
    {
        $sender = new self($options);
        $sender->lock_handle = $sender->acquire_lock();
        try {
            if ($sender->load_state() !== null) {
                throw new LogicException(
                    'Cannot start a push files sender while unfinished sender state exists: '
                    . $sender->state_path
                );
            }
            clearstatcache(true, $sender->fresh_local_index_path);
            if (!is_file($sender->fresh_local_index_path)) {
                throw new InvalidArgumentException(
                    'PushFilesSender requires an existing fresh_local_index_path when starting a sender.'
                );
            }

            $sender->client = $sender->create_client(null);
            $sender->state = [
                'push_session_id' => bin2hex(random_bytes(16)),
                'phase' => 'creating',
                'local_paths_to_push_byte_offset' => 0,
                'source_token' => null,
                'recoverable_failures' => 0,
                'max_part_bytes' => null,
                'request_sizer_state' => $sender->client->get_request_sizer_state(),
            ];
            $sender->store_state($sender->state);
            return $sender;
        } catch (Throwable $throwable) {
            $sender->close();
            throw $throwable;
        }
    }

    /**
     * Resumes an unfinished sender while holding its exclusive site lock.
     *
     * The sender state is read once under the acquired lock. next_step() then
     * works from that in-memory state, publishing each later durable boundary
     * without reopening sender.json.
     *
     * @param array<string,mixed> $options Options documented by start().
     * @return self Open sender at its last durable state.
     */
    public static function resume(array $options): self
    {
        $sender = new self($options);
        $sender->lock_handle = $sender->acquire_lock();
        try {
            $state = $sender->load_state();
            if ($state === null) {
                throw new LogicException(
                    'Cannot resume a push files sender without unfinished sender state: '
                    . $sender->state_path
                );
            }
            $sender->state = $state;
            $sender->client = $sender->create_client($state);
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
     * @throws InvalidArgumentException If source or transport options are invalid.
     * @throws RuntimeException If the site directory cannot be created.
     */
    private function __construct(array $options)
    {
        $docroot = $options['docroot'] ?? null;
        $fresh_local_index_path = $options['fresh_local_index_path'] ?? null;
        $site_dir = $options['site_dir'] ?? null;
        if (!is_string($docroot) || !is_dir($docroot) || is_link($docroot)) {
            throw new InvalidArgumentException('PushFilesSender requires a real docroot directory.');
        }
        if (!is_string($fresh_local_index_path) || $fresh_local_index_path === '') {
            throw new InvalidArgumentException('PushFilesSender requires a fresh_local_index_path.');
        }
        if (!is_string($site_dir) || $site_dir === '') {
            throw new InvalidArgumentException('PushFilesSender requires a site_dir.');
        }
        if (!is_dir($site_dir) && !@mkdir($site_dir, 0755, true) && !is_dir($site_dir)) {
            throw new RuntimeException('Failed to create the push sender directory: ' . $site_dir);
        }
        $request_sizer_config = $options['request_sizer_config'] ?? [];
        if (!is_array($request_sizer_config)) {
            throw new InvalidArgumentException('request_sizer_config must be an array.');
        }

        $client_options = [
            'base_url' => $options['base_url'] ?? null,
            'hmac_client' => $options['hmac_client'] ?? null,
            'allow_http' => $options['allow_http'] ?? false,
        ];
        foreach (['chunk_bytes', 'connect_timeout', 'stall_timeout', 'response_timeout'] as $option_name) {
            if (array_key_exists($option_name, $options)) {
                $client_options[$option_name] = $options[$option_name];
            }
        }

        $this->docroot = rtrim($docroot, '/');
        $this->fresh_local_index_path = $fresh_local_index_path;
        $this->site_dir = rtrim($site_dir, '/');
        $this->state_path = $this->site_dir . '/sender.json';
        $this->lock_path = $this->site_dir . '/sender.lock';
        $this->request_sizer_config = $request_sizer_config;
        $this->client_options = $client_options;
    }

    /**
     * Performs the next bounded step.
     *
     * start() or resume() has already acquired the site lock and loaded the
     * durable state, so this method only dispatches its current phase and
     * publishes the next boundary. `continue` identifies durable work that a
     * later process may resume, and the caller may close the sender immediately
     * after receiving it. `restart` means the old remote session and local plan
     * are gone and a new local index is required.
     *
     * @return array {
     *     Result of one step.
     *
     *     @type string      $status          `continue`, `complete`, `restart`, or `failed`.
     *     @type string      $phase           Durable phase or `complete`.
     *     @type string      $push_session_id Remote push session ID.
     *     @type string|null $reason          Machine-readable result classification.
     *     @type string|null $detail          Human-readable result detail.
     * }
     * @phpstan-return array{status:'continue'|'complete'|'restart'|'failed',phase:string,push_session_id:string,reason:string|null,detail:string|null}
     */
    public function next_step(): array
    {
        if ($this->closed) {
            throw new LogicException('Cannot call next_step() after close().');
        }
        if ($this->state === null) {
            throw new LogicException('Cannot call next_step() after the sender reaches a terminal result.');
        }

        switch ($this->state['phase']) {
            case 'creating':
                $result = $this->create_push($this->state);
                break;
            case 'planning':
                $result = $this->next_plan_step($this->state);
                break;
            case 'pushing_paths':
                $result = $this->next_positive_work_upload_part($this->state);
                break;
            case 'pushing_deletes':
                $result = $this->next_delete_list_upload_part($this->state);
                break;
            case 'committing':
                $result = $this->commit_push($this->state);
                break;
            case 'removing':
                $result = $this->remove_push($this->state);
                break;
            default:
                $result = $this->step_result(
                    'failed',
                    $this->state,
                    'invalid_sender_state',
                    'Sender state contains an unsupported phase.'
                );
        }

        if ($result['status'] === 'complete' || $result['status'] === 'restart') {
            $this->state = null;
        }
        return $result;
    }

    /**
     * Releases the per-site lock and prevents further steps.
     *
     * Durable state remains available to resume unless next_step()
     * already completed or discarded the workflow.
     */
    public function close(): void
    {
        if (is_resource($this->lock_handle)) {
            $this->release_lock($this->lock_handle);
        }
        $this->lock_handle = null;
        $this->closed = true;
    }

    /**
     * Creates the remote session and starts PushPlan with its exclusion policy.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Durable result after the create request.
     */
    private function create_push(array &$state): array
    {
        $request = $this->control_request('POST', 'push_create', [
            'push_session_id' => $state['push_session_id'],
        ], ['created']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }

        /** @var array{max_part_bytes:int,post_max_bytes:?int,excluded_paths_b64:list<string>} $response */
        $response = $request['response'];
        $excluded_paths = [];
        foreach ($response['excluded_paths_b64'] as $encoded_path) {
            $path = base64_decode($encoded_path, true);
            if ($path === false) {
                return $this->step_result('failed', $state, 'unexpected_response', 'Could not decode an excluded path returned by push_create.');
            }
            $excluded_paths[] = $path;
        }

        $this->client->set_max_part_bytes($response['max_part_bytes']);
        $this->client->apply_reported_limits([$response['post_max_bytes']]);
        $state['max_part_bytes'] = $response['max_part_bytes'];
        $state['recoverable_failures'] = 0;
        try {
            if (PushPlan::has_unfinished_plan($this->site_dir)) {
                $plan = PushPlan::resume($this->site_dir);
            } else {
                $plan = PushPlan::start(
                    $this->site_dir,
                    $this->fresh_local_index_path,
                    $excluded_paths
                );
            }
            $plan->close();
        } catch (RuntimeException $exception) {
            clearstatcache(true, $this->fresh_local_index_path);
            if (is_file($this->fresh_local_index_path)) {
                throw $exception;
            }
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result(
                'continue',
                $state,
                'source_changed',
                'The fresh local index disappeared before planning began; remove the upload-only push session before generating another index.'
            );
        }

        $state['phase'] = 'planning';
        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Performs one PushPlan step and moves to selected paths at plan completion.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Durable result after one bounded plan step.
     */
    private function next_plan_step(array &$state): array
    {
        $plan = PushPlan::resume($this->site_dir);
        try {
            $planning = $plan->next_step();
        } finally {
            $plan->close();
        }
        if ($planning['status'] === 'complete') {
            $state['phase'] = 'pushing_paths';
        }
        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Reconciles one selected path and sends at most one positive-work part.
     *
     * A file part contains one bounded source chunk. A directory or symlink
     * part contains that one complete value. The selected path-list cursor
     * advances only after the receiver confirms the value as complete.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Result of one reconciliation or upload part.
     */
    private function next_positive_work_upload_part(array &$state): array
    {
        $local_paths_to_push_path = PushPlan::local_paths_to_push_path($this->site_dir);
        $local_paths_to_push_handle = fopen($local_paths_to_push_path, 'rb');
        if (!is_resource($local_paths_to_push_handle)) {
            return $this->step_result('failed', $state, 'local_io_error', 'Could not open the local paths selected for push.');
        }

        $local_paths_to_push_byte_offset = $state['local_paths_to_push_byte_offset'];
        try {
            $selected_source = $this->next_selected_source(
                $local_paths_to_push_handle,
                $local_paths_to_push_byte_offset
            );
        } catch (RuntimeException $exception) {
            fclose($local_paths_to_push_handle);
            return $this->step_result('failed', $state, 'local_io_error', $exception->getMessage());
        }
        fclose($local_paths_to_push_handle);
        if ($selected_source === null) {
            $state['local_paths_to_push_byte_offset'] = $local_paths_to_push_byte_offset;
            $state['source_token'] = null;
            $state['phase'] = 'pushing_deletes';
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }
        if ($selected_source['source_token'] === null) {
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result('continue', $state, 'source_changed', 'A selected source path disappeared; remove the upload-only push session before generating another index.');
        }

        $request = $this->control_request('GET', 'push_status', [
            'push_session_id' => $state['push_session_id'],
            'path_b64' => $selected_source['path_b64'],
        ], ['accepted']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        /** @var array{path:array{state:'missing'|'partial'|'complete',type?:'file'|'directory'|'symlink',accepted_bytes:int}} $response */
        $response = $request['response'];
        $path_status = $response['path'];
        $path_type = $path_status['type'] ?? null;
        $state['recoverable_failures'] = 0;

        $source_token_after_status = $this->source_token($selected_source['path']);
        if ($source_token_after_status === null) {
            $state['phase'] = 'removing';
            $this->store_state($state);
            return $this->step_result('continue', $state, 'source_changed', 'A selected source path disappeared; remove the upload-only push session before generating another index.');
        }
        $source_token = $source_token_after_status;
        $saved_source_matches = $state['source_token'] === $source_token;
        if (
            $saved_source_matches
            && $path_status['state'] === 'complete'
            && $path_type === $source_token['type']
            && ( $source_token['type'] !== 'file' || $path_status['accepted_bytes'] === $source_token['size'] )
        ) {
            $state['local_paths_to_push_byte_offset'] = $selected_source['next_local_paths_to_push_byte_offset'];
            $state['source_token'] = null;
            $state['recoverable_failures'] = 0;
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }
        $confirmed_bytes = $saved_source_matches
            && $path_status['state'] === 'partial'
            && $path_type === 'file'
            && $path_status['accepted_bytes'] <= $source_token['size']
                ? $path_status['accepted_bytes']
                : 0;

        $upload_part = null;
        $logical_value_complete = false;

        if ($source_token['type'] === 'directory') {
            $directory_is_empty = $this->directory_is_empty($selected_source['path']);
            if ($directory_is_empty === null) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the selected source directory: ' . base64_encode($selected_source['path']) . '.');
            }
            $source_token_after_read = $this->source_token($selected_source['path']);
            if ($source_token_after_read === null) {
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'A selected source path disappeared; remove the upload-only push session before generating another index.');
            }
            if ($source_token_after_read !== $source_token) {
                $this->store_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'The selected source changed while it was being read; retry it from receiver-confirmed state.');
            }
            if (!$directory_is_empty) {
                $state['local_paths_to_push_byte_offset'] = $selected_source['next_local_paths_to_push_byte_offset'];
                $state['source_token'] = null;
                $this->store_state($state);
                return $this->step_result('continue', $state, null, null);
            }
            $upload_part = [
                'type' => 'directory',
                'path' => $selected_source['path'],
                'payload' => '',
            ];
            $logical_value_complete = true;
        } elseif ($source_token['type'] === 'symlink') {
            $symlink_target = @readlink($this->docroot . '/' . $selected_source['path']);
            $source_token_after_read = $this->source_token($selected_source['path']);
            if ($source_token_after_read === null) {
                $state['phase'] = 'removing';
                $this->store_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'A selected source path disappeared; remove the upload-only push session before generating another index.');
            }
            if ($source_token_after_read !== $source_token) {
                $this->store_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'The selected source changed while it was being read; retry it from receiver-confirmed state.');
            }
            if ($symlink_target === false) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the selected source symlink target: ' . base64_encode($selected_source['path']) . '.');
            }
            $upload_part = [
                'type' => 'symlink',
                'path' => $selected_source['path'],
                'target' => $symlink_target,
                'payload' => '',
            ];
            $logical_value_complete = true;
        }

        if (!$this->client->start_upload_request($state['push_session_id'])) {
            return $this->upload_start_failure($state);
        }

        $source_changed = false;
        $source_disappeared = false;
        $local_failure_detail = null;
        $request_size_failure_detail = null;

        if ($source_token['type'] === 'file') {
            $source_byte_offset = $confirmed_bytes <= $source_token['size']
                ? $confirmed_bytes
                : 0;
            $maximum_payload_bytes = $this->client->next_file_body_bytes(
                $selected_source['path'],
                $source_token['size'],
                $source_byte_offset
            );
            if ($maximum_payload_bytes === 0) {
                $request_size_failure_detail = 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($selected_source['path']) . '.';
            } else {
                $payload = '';
                if ($source_token['size'] > 0) {
                    $source_file_handle = fopen($this->docroot . '/' . $selected_source['path'], 'rb');
                    if (!is_resource($source_file_handle) || fseek($source_file_handle, $source_byte_offset) !== 0) {
                        if (is_resource($source_file_handle)) {
                            fclose($source_file_handle);
                        }
                        $source_token_after_read = $this->source_token($selected_source['path']);
                        if ($source_token_after_read === null) {
                            $source_disappeared = true;
                        } elseif ($source_token_after_read !== $source_token) {
                            $source_changed = true;
                        } else {
                            $local_failure_detail = 'Could not open the selected source file at its receiver-confirmed cursor: ' . base64_encode($selected_source['path']) . '.';
                        }
                    } else {
                        $payload = fread($source_file_handle, $maximum_payload_bytes);
                        fclose($source_file_handle);
                        $source_token_after_read = $this->source_token($selected_source['path']);
                        if ($source_token_after_read === null) {
                            $source_disappeared = true;
                        } elseif ($source_token_after_read !== $source_token) {
                            $source_changed = true;
                        } elseif (!is_string($payload) || ( $payload === '' && $source_byte_offset < $source_token['size'] )) {
                            $local_failure_detail = 'Could not read the selected source file at its receiver-confirmed cursor: ' . base64_encode($selected_source['path']) . '.';
                        } else {
                            $upload_part = [
                                'type' => 'file',
                                'path' => $selected_source['path'],
                                'total_bytes' => $source_token['size'],
                                'offset' => $source_byte_offset,
                                'payload' => $payload,
                            ];
                            $logical_value_complete = $source_byte_offset + strlen($payload) === $source_token['size'];
                        }
                    }
                } else {
                    $upload_part = [
                        'type' => 'file',
                        'path' => $selected_source['path'],
                        'total_bytes' => 0,
                        'offset' => 0,
                        'payload' => '',
                    ];
                    $logical_value_complete = true;
                }
            }
        }

        $part_sent = $upload_part !== null && $this->client->send_part($upload_part);
        if ($upload_part !== null && !$part_sent) {
            $request_size_failure_detail = 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($selected_source['path']) . '.';
        }

        $request = $this->client->finish_request();
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        if (!$part_sent) {
            if ($source_disappeared) {
                $state['phase'] = 'removing';
            }
            $state['recoverable_failures'] = 0;
            $this->store_state($state);
            if ($source_disappeared) {
                return $this->step_result('continue', $state, 'source_changed', 'A selected source path disappeared; remove the upload-only push session before generating another index.');
            }
            if ($source_changed) {
                return $this->step_result('continue', $state, 'source_changed', 'The selected source changed while it was being read; retry it from receiver-confirmed state.');
            }
            if ($local_failure_detail !== null) {
                return $this->step_result('failed', $state, 'local_io_error', $local_failure_detail);
            }
            return $this->step_result('failed', $state, 'request_size_exhausted', $request_size_failure_detail ?? 'The current request-body budget cannot fit one positive-work MIME part.');
        }
        if ($logical_value_complete) {
            $state['local_paths_to_push_byte_offset'] = $selected_source['next_local_paths_to_push_byte_offset'];
            $state['source_token'] = null;
        } else {
            $state['local_paths_to_push_byte_offset'] = $selected_source['local_paths_to_push_byte_offset'];
            $state['source_token'] = $source_token;
        }

        $state['recoverable_failures'] = 0;
        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Reads the receiver's deletion cursor and sends at most one list part.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Result of one reconciliation or list part.
     */
    private function next_delete_list_upload_part(array &$state): array
    {
        $request = $this->control_request('GET', 'push_status', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        /** @var array{work_deletes_bytes:int,work_deletes_complete:bool} $response */
        $response = $request['response'];
        $work_deletes_bytes = $response['work_deletes_bytes'];
        $work_deletes_complete = $response['work_deletes_complete'];

        $local_paths_to_delete_path = PushPlan::local_paths_to_delete_path($this->site_dir);
        $state['recoverable_failures'] = 0;
        if ($work_deletes_complete) {
            $state['phase'] = 'committing';
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        $local_paths_to_delete_handle = fopen($local_paths_to_delete_path, 'rb');
        if (!is_resource($local_paths_to_delete_handle) || fseek($local_paths_to_delete_handle, $work_deletes_bytes) !== 0) {
            if (is_resource($local_paths_to_delete_handle)) {
                fclose($local_paths_to_delete_handle);
            }
            return $this->step_result('failed', $state, 'local_io_error', 'Could not open the local deletion list at the receiver-confirmed cursor.');
        }
        if (!$this->client->start_upload_request($state['push_session_id'])) {
            fclose($local_paths_to_delete_handle);
            return $this->upload_start_failure($state);
        }

        $maximum_payload_bytes = $this->client->next_delete_body_bytes($work_deletes_bytes);
        $payload = $maximum_payload_bytes > 0
            ? fread($local_paths_to_delete_handle, $maximum_payload_bytes)
            : false;
        $local_paths_to_delete_complete = is_string($payload)
            && $payload === ''
            && feof($local_paths_to_delete_handle);
        $part_sent = is_string($payload)
            && $this->client->send_part([
                'type' => 'delete-list',
                'offset' => $work_deletes_bytes,
                'complete' => $local_paths_to_delete_complete,
                'payload' => $payload,
            ]);
        fclose($local_paths_to_delete_handle);

        $request = $this->client->finish_request();
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        if (!$part_sent) {
            if ($maximum_payload_bytes > 0) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the local deletion list at the receiver-confirmed cursor.');
            }
            return $this->step_result('failed', $state, 'request_size_exhausted', 'The current request-body budget cannot fit one deletion-list MIME part.');
        }

        $state['recoverable_failures'] = 0;
        $this->store_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Requests one bounded receiver commit step and publishes a completed plan.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Commit continuation or terminal completion.
     */
    private function commit_push(array &$state): array
    {
        $request = $this->control_request('POST', 'push_commit', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        /** @var array{send_next_request:bool} $response */
        $response = $request['response'];

        $state['recoverable_failures'] = 0;
        if ($response['send_next_request']) {
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        // A prior process may have published the plan after the receiver
        // completed but stopped before it removed sender.json.
        if (PushPlan::has_unfinished_plan($this->site_dir)) {
            $plan = PushPlan::resume($this->site_dir);
            $plan->close();
            $plan->after_successful_push();
        }
        $push_session_id = $state['push_session_id'];
        $this->clear_state();
        return [
            'status' => 'complete',
            'phase' => 'complete',
            'push_session_id' => $push_session_id,
            'reason' => null,
            'detail' => null,
        ];
    }

    /**
     * Removes an upload-only remote session and discards its local PushPlan.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Removal continuation or terminal restart.
     */
    private function remove_push(array &$state): array
    {
        $request = $this->control_request('POST', 'push_remove', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        /** @var array{removed:bool} $response */
        $response = $request['response'];
        if (!$response['removed']) {
            $state['recoverable_failures'] = 0;
            $this->store_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        // A repeated remove may follow a process that discarded the plan but
        // stopped before it removed sender.json.
        if (PushPlan::has_unfinished_plan($this->site_dir)) {
            $plan = PushPlan::resume($this->site_dir);
            $plan->close();
            $plan->discard();
        }
        $push_session_id = $state['push_session_id'];
        $this->clear_state();
        return [
            'status' => 'restart',
            'phase' => 'complete',
            'push_session_id' => $push_session_id,
            'reason' => 'source_changed',
            'detail' => 'The upload-only push session was removed. Generate a new local index before retrying.',
        ];
    }

    /**
     * Builds a streaming client from the sizing evidence in durable state.
     *
     * @param State|null $state Current state, or null before a push starts.
     */
    private function create_client(?array $state): MultipartPushStreamClient
    {
        $request_sizer = new PushRequestSizer(
            $this->request_sizer_config,
            $state === null ? [] : $state['request_sizer_state']
        );
        $client_options = $this->client_options;
        $client_options['request_sizer'] = $request_sizer;
        $client = new MultipartPushStreamClient($client_options);
        if ($state !== null && $state['max_part_bytes'] !== null) {
            $client->set_max_part_bytes($state['max_part_bytes']);
        }
        return $client;
    }

    /**
     * Finds the next selected source that still represents sendable work.
     *
     * A directory selected as empty may have gained children after the index
     * was written. Its descendants belong to a later plan, so this skips the
     * directory instead of sending an empty-directory operation which could
     * remove them. A null source token is returned rather than skipped because
     * a vanished selected path requires the remote session to be removed.
     *
     * @param resource $local_paths_to_push_handle Open local_paths_to_push file.
     * @param int $local_paths_to_push_byte_offset Byte offset updated past skipped directories.
     * @return SelectedSource|null Selected source, or null at the end of the path list.
     */
    private function next_selected_source(
        $local_paths_to_push_handle,
        int &$local_paths_to_push_byte_offset
    ): ?array
    {
        $selected_path = $this->read_selected_path(
            $local_paths_to_push_handle,
            $local_paths_to_push_byte_offset
        );
        if ($selected_path === null) {
            return null;
        }
        return [
            'path' => $selected_path['path'],
            'path_b64' => base64_encode($selected_path['path']),
            'local_paths_to_push_byte_offset' => $local_paths_to_push_byte_offset,
            'next_local_paths_to_push_byte_offset' => $selected_path['next_local_paths_to_push_byte_offset'],
            'source_token' => $this->source_token($selected_path['path']),
        ];
    }

    /**
     * Reads one selected-path JSONL record at an exact durable byte offset.
     *
     * @param resource $local_paths_to_push_handle Open local_paths_to_push file.
     * @param int $local_paths_to_push_byte_offset Byte offset of the record to read.
     * @return array{path:string,next_local_paths_to_push_byte_offset:int}|null Decoded path record, or null at EOF.
     */
    private function read_selected_path($local_paths_to_push_handle, int $local_paths_to_push_byte_offset): ?array
    {
        if (fseek($local_paths_to_push_handle, $local_paths_to_push_byte_offset) !== 0) {
            throw new RuntimeException('The local paths-to-push cursor is outside the selected path list.');
        }
        $line = fgets($local_paths_to_push_handle);
        if ($line === false) {
            if (feof($local_paths_to_push_handle)) {
                return null;
            }
            throw new RuntimeException('Failed to read the local paths selected for push.');
        }
        $next_local_paths_to_push_byte_offset = ftell($local_paths_to_push_handle);
        if (!is_int($next_local_paths_to_push_byte_offset)) {
            throw new RuntimeException('Failed to determine the next byte offset in the local paths selected for push.');
        }
        try {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode a local path selected for push.', 0, $exception);
        }
        /** @var array{path:string} $record */
        $path = base64_decode($record['path'], true);
        if ($path === false) {
            throw new RuntimeException('Failed to decode a path in the local paths-to-push file.');
        }
        return ['path' => $path, 'next_local_paths_to_push_byte_offset' => $next_local_paths_to_push_byte_offset];
    }

    /**
     * Converts upload setup failure into the bounded retry policy.
     *
     * @param State $state Active state.
     * @return array<string,mixed> Recoverable or terminal step result.
     */
    private function upload_start_failure(array &$state): array
    {
        $request = [
            'status' => 'retry',
            'reason' => 'request_failed',
            'detail' => $this->client->get_last_error(),
            'response' => null,
            'parts_sent' => 0,
            'body_bytes_sent' => 0,
        ];
        $failure = $this->handle_request_failure($request, $state);
        /** @var array<string,mixed> $failure */
        return $failure;
    }

    /**
     * Sends a signed control request and classifies transport exceptions.
     *
     * Redirects and malformed JSON are terminal. A missing response is
     * recoverable within the fixed retry bound because create, status,
     * commit, and remove requests are idempotent.
     *
     * @param string $method GET or POST.
     * @param string $endpoint Push protocol endpoint.
     * @param array<string,mixed> $parameters Request-target parameters.
     * @param list<string> $expected_statuses Successful response statuses.
     * @return array{status:'complete'|'retry'|'failed',reason:string|null,detail:string|null,response:array<string,mixed>|null,parts_sent:int,body_bytes_sent:int}
     */
    private function control_request(string $method, string $endpoint, array $parameters, array $expected_statuses): array
    {
        try {
            return $this->client->control_request($method, $endpoint, $parameters, $expected_statuses);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (strpos($message, 'The target redirected') === 0) {
                $status = 'failed';
                $reason = 'redirected';
            } elseif (strpos($message, 'Push control request returned invalid JSON') === 0) {
                $status = 'failed';
                $reason = 'malformed_response';
            } elseif (strpos($message, 'Push control request failed:') === 0) {
                $status = 'retry';
                $reason = 'request_failed';
            } else {
                $status = 'failed';
                $reason = 'client_error';
            }
            return [
                'status' => $status,
                'reason' => $reason,
                'detail' => $message,
                'response' => null,
                'parts_sent' => 0,
                'body_bytes_sent' => 0,
            ];
        }
    }

    /**
     * Reports whether a selected source directory has no child entry.
     *
     * @param string $path Raw document-root-relative directory path.
     * @return bool|null True when empty, false when non-empty, or null when unreadable.
     */
    private function directory_is_empty(string $path): ?bool
    {
        $directory = @opendir($this->docroot . '/' . $path);
        if ($directory === false) {
            return null;
        }
        try {
            while (true) {
                $entry = readdir($directory);
                if ($entry === false) {
                    return true;
                }
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }
        } finally {
            closedir($directory);
        }
    }

    /**
     * Returns the source evidence used to decide file resume versus restart.
     *
     * Regular files, directories, and symlinks are the only sendable types.
     * The type, size, and ctime fields match PushPlan's file-change evidence.
     * A same-size edit within one ctime second remains the timestamp-resolution
     * gap documented for local change detection.
     *
     * @param string $path Raw document-root-relative path.
     * @return SourceToken|null Current evidence, or null when absent or unsupported.
     */
    private function source_token(string $path): ?array
    {
        $absolute_path = $this->docroot . '/' . $path;
        clearstatcache(true, $absolute_path);
        $identity = @lstat($absolute_path);
        if (!is_array($identity)) {
            return null;
        }
        $kind = $identity['mode'] & 0170000;
        if ($kind === 0100000) {
            $type = 'file';
        } elseif ($kind === 0040000) {
            $type = 'directory';
        } elseif ($kind === 0120000) {
            $type = 'symlink';
        } else {
            return null;
        }
        return [
            'type' => $type,
            'size' => (int) $identity['size'],
            'ctime' => (int) $identity['ctime'],
        ];
    }

    /**
     * Converts a failed request into a bounded workflow result.
     *
     * @param array{status:'complete'|'retry'|'failed',reason:string|null,detail:string|null,response:array<string,mixed>|null,parts_sent:int,body_bytes_sent:int} $request Classified request result.
     * @param State $state Active state, persisted when evidence changes.
     * @return array<string,mixed>|null Null when the request completed successfully.
     */
    private function handle_request_failure(array $request, array &$state): ?array
    {
        if ($request['status'] === 'complete') {
            return null;
        }
        if ($request['status'] === 'retry') {
            ++$state['recoverable_failures'];
            $this->store_state($state);
            if ($state['recoverable_failures'] >= self::MAXIMUM_RECOVERABLE_FAILURES) {
                return $this->step_result(
                    'failed',
                    $state,
                    'retry_exhausted',
                    'Push stopped after ' . self::MAXIMUM_RECOVERABLE_FAILURES . ' consecutive recoverable failures. Last failure: ' . ( $request['detail'] ?? $request['reason'] )
                );
            }
            return $this->step_result('continue', $state, $request['reason'], $request['detail']);
        }
        $this->store_state($state);
        return $this->step_result('failed', $state, $request['reason'], $request['detail']);
    }

    /**
     * Loads the active state from its atomic JSON file.
     *
     * The writer owns the schema. Reading retains only file and JSON failure
     * handling rather than maintaining a second field-by-field validator.
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
            throw new RuntimeException('Failed to read sender state: ' . $this->state_path);
        }
        try {
            $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode sender state: ' . $this->state_path, 0, $exception);
        }
        /** @var State $state */
        return $state;
    }

    /**
     * Atomically stores the complete state and current sizing evidence.
     *
     * @param State $state Active state.
     */
    private function store_state(array &$state): void
    {
        $state['request_sizer_state'] = $this->client->get_request_sizer_state();
        $json = $this->encode_json($state, 'sender state');
        $temporary_path = $this->state_path . '.tmp';
        if (file_put_contents($temporary_path, $json) !== strlen($json)) {
            throw new RuntimeException('Failed to write sender state: ' . $temporary_path);
        }
        if (!rename($temporary_path, $this->state_path)) {
            throw new RuntimeException('Failed to move sender state into place: ' . $this->state_path);
        }
    }

    /**
     * Removes the state after local and remote terminal work is durable.
     */
    private function clear_state(): void
    {
        clearstatcache(true, $this->state_path);
        if (is_file($this->state_path) && !unlink($this->state_path)) {
            throw new RuntimeException('Failed to remove sender state: ' . $this->state_path);
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
            throw new RuntimeException('Failed to open the sender lock: ' . $this->lock_path);
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
     * Encodes a durable JSON object and names the operation if encoding fails.
     *
     * @param array<string,mixed> $value Value to encode.
     * @param string $description Human-readable value description.
     */
    private function encode_json(array $value, string $description): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode ' . $description . '.', 0, $exception);
        }
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
