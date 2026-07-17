<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sender failures are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Drives one resumable local-files push through real HTTP requests.
 *
 * Each send_next_request() call performs one create, status, upload, commit,
 * or remove request and persists the receiver-reconcilable boundary before it
 * returns. Upload requests contain as many bounded file chunks and work values
 * as the learned request-body budget permits. Only one payload string is held
 * at a time.
 *
 * The sender does not trust local byte counters after a restart or ambiguous
 * response. It asks push_status for the current path or work-delete cursor,
 * compares the source's type, size, and ctime with its persisted token, and
 * resumes or restarts the same in-flight work. If a selected source disappears
 * or the work-delete cursor cannot belong to the current list, it removes the
 * upload-only push session and returns `restart` so the caller can regenerate
 * the local index.
 */
final class PushFilesSender
{
    private const MAXIMUM_RECOVERABLE_FAILURES = 5;
    private const RECOVERABLE_FAILURE_PAUSE_MICROSECONDS = 100000;

    private string $docroot;
    private string $current_index_file;
    private PushJournal $journal;
    private MultipartPushStreamClient $client;
    private array $request_sizer_config;
    private array $client_options;
    private bool $client_has_authoritative_state = false;
    private ?string $client_state_fingerprint = null;

    /**
     * Configures one sender and restores its learned request size when present.
     *
     * @param array<string,mixed> $options {
     *     Sender, transport, and source configuration.
     *
     *     @type string $docroot Required local document-root directory.
     *     @type string $current_index_file Current local file index required
     *         when no sender checkpoint is active. An active sender resumes
     *         from its stable journal copy instead.
     *     @type PushJournal $journal Required per-target push journal.
     *     @type string $base_url Required exporter API URL.
     *     @type Site_Export_HMAC_Client $hmac_client Required envelope signer.
     *     @type bool $allow_http Explicit plain-HTTP opt-in. Default false.
     *     @type int|float|string $chunk_bytes Maximum one source read. Default
     *         4 MiB.
     *     @type int|float|string $connect_timeout Connect phase seconds.
     *         Default 30.
     *     @type int|float|string $stall_timeout No-upload-progress seconds.
     *         Default 60.
     *     @type int|float|string $response_timeout No-response-progress seconds.
     *         Default 300.
     *     @type array{
     *         floor_bytes?:int|float|string,
     *         start_bytes?:int|float|string,
     *         max_bytes?:int|float|string,
     *         limit_safety_ratio?:int|float|string,
     *         growth_holdoff_successes?:int|float|string
     *     } $request_sizer_config Optional PushRequestSizer bounds; persisted
     *         sizing state still wins.
     * }
     *
     * @throws InvalidArgumentException If source or transport options are invalid.
     * @throws RuntimeException If the journal cannot be read.
     */
    public function __construct(array $options)
    {
        $docroot = $options['docroot'] ?? null;
        $current_index_file = $options['current_index_file'] ?? null;
        $journal = $options['journal'] ?? null;
        if (!is_string($docroot) || !is_dir($docroot) || is_link($docroot)) {
            throw new InvalidArgumentException('PushFilesSender requires a real docroot directory.');
        }
        if (!is_string($current_index_file) || $current_index_file === '') {
            throw new InvalidArgumentException('PushFilesSender requires a current_index_file path.');
        }
        if (!$journal instanceof PushJournal) {
            throw new InvalidArgumentException('PushFilesSender requires a PushJournal.');
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
        $this->current_index_file = $current_index_file;
        $this->journal = $journal;
        $this->request_sizer_config = $request_sizer_config;
        $this->client_options = $client_options;
        $this->client = $this->create_client(null);
    }

    /**
     * Runs requests until the push completes, fails, or needs a new index.
     *
     * Recoverable results pause for 100 milliseconds before the next request,
     * so the convenience loop does not spin on target lock contention.
     *
     * @return array{
     *     status:'complete'|'restart'|'failed',
     *     phase:'complete'|'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     push_session_id:?string,
     *     reason:?string,
     *     detail:?string
     * } Terminal workflow result. `restart` means the abandoned upload-only
     *     push session was removed and the caller must regenerate its index.
     * @throws InvalidArgumentException If the stable index contains an unsafe
     *     document-root-relative path.
     * @throws RuntimeException If durable local sender state cannot be read or
     *     written.
     */
    public function push(): array
    {
        do {
            $result = $this->send_next_request();
            if ($result['status'] === 'continue' && $result['reason'] !== null) {
                usleep(self::RECOVERABLE_FAILURE_PAUSE_MICROSECONDS);
            }
        } while ($result['status'] === 'continue');
        return $result;
    }

    /**
     * Performs the next durable HTTP request in the local-files push.
     *
     * New work first produces journal path lists and a raw work-delete stream.
     * Later calls create or reopen the push session, reconcile any current
     * receiver cursor, fill one multipart request, close work deletes, repeat
     * commit, or remove an abandoned upload-only push session.
     *
     * Returning `continue` means the checkpoint names the request to perform
     * next. `complete` means receiver commit and local baseline capture both
     * finished. `restart` means source selection changed incompatibly and the
     * old push session is gone. `failed` is terminal for this checkpoint.
     *
     * @return array{
     *     status:'continue'|'complete'|'restart'|'failed',
     *     phase:'complete'|'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     push_session_id:?string,
     *     reason:?string,
     *     detail:?string
     * } Result of one real request and its durable local transition.
     * @throws InvalidArgumentException If the stable index contains an unsafe
     *     document-root-relative path.
     * @throws RuntimeException If durable local sender state cannot be read or
     *     written.
     */
    public function send_next_request(): array
    {
        $sender_lock = $this->journal->acquire_sender_lock();
        if ($sender_lock === null) {
            return [
                'status' => 'continue',
                'phase' => 'creating',
                'push_session_id' => null,
                'reason' => 'sender_busy',
                'detail' => 'Another process is advancing this target site\'s local push sender. Retry after that request finishes.',
            ];
        }
        try {
            return $this->send_next_request_under_lock();
        } finally {
            $this->journal->release_sender_lock($sender_lock);
        }
    }

    /**
     * Performs one sender transition while the site journal lock is held.
     *
     * @return array<string,mixed> Result in send_next_request()'s documented shape.
     */
    private function send_next_request_under_lock(): array
    {
        $state = $this->journal->read_sender_state();
        clearstatcache(true, $this->current_index_file);
        if ($state === null && !is_file($this->current_index_file)) {
            throw new InvalidArgumentException('PushFilesSender requires an existing current_index_file when no sender checkpoint is active.');
        }
        $state_fingerprint = $state === null
            ? null
            : json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$this->client_has_authoritative_state || $this->client_state_fingerprint !== $state_fingerprint) {
            $this->client = $this->create_client($state);
            $this->client_has_authoritative_state = true;
            $this->client_state_fingerprint = $state_fingerprint;
        }
        if ($state === null) {
            $this->journal->capture_sender_index($this->current_index_file);
            $state = [
                'push_session_id' => bin2hex(random_bytes(16)),
                'phase' => 'creating',
                'paths_byte_offset' => 0,
                'current_path_b64' => null,
                'next_paths_byte_offset' => 0,
                'source_token' => null,
                'confirmed_bytes' => 0,
                'work_deletes_byte_offset' => 0,
                'recoverable_failures' => 0,
                'max_part_bytes' => null,
                'excluded_paths_b64' => null,
                'request_sizer_state' => $this->client->get_request_sizer_state(),
            ];
            $this->journal->write_sender_state($state);
            $this->client_state_fingerprint = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if ($state['phase'] === 'creating') {
            $request = $this->control_request('POST', 'push_create', [
                'push_session_id' => $state['push_session_id'],
            ], ['created']);
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            $response = $request['response'];
            $excluded_paths = is_array($response)
                ? $this->decode_excluded_paths_b64($response['excluded_paths_b64'] ?? null)
                : null;
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || !is_int($response['max_part_bytes'] ?? null)
                || $response['max_part_bytes'] <= 0
                || !array_key_exists('post_max_bytes', $response)
                || ( $response['post_max_bytes'] !== null
                    && ( !is_int($response['post_max_bytes']) || $response['post_max_bytes'] <= 0 ) )
                || $excluded_paths === null
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_create did not return the matching push session ID, a positive part limit, a positive or unknown request-body limit, and a canonical excluded-path list.');
            }
            $this->journal->diff_local_files($this->journal->sender_index_path, $excluded_paths);
            $this->journal->prepare_work_deletes();
            $this->client->set_max_part_bytes($response['max_part_bytes']);
            $this->client->apply_reported_limits([$response['post_max_bytes'] ?? null]);
            $state['max_part_bytes'] = $response['max_part_bytes'];
            $state['excluded_paths_b64'] = $response['excluded_paths_b64'];
            $state['recoverable_failures'] = 0;
            $state['phase'] = 'reconciling_work';
            $this->persist_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        if ($state['phase'] === 'reconciling_work') {
            $parameters = ['push_session_id' => $state['push_session_id']];
            if ($state['current_path_b64'] !== null) {
                $parameters['path_b64'] = $state['current_path_b64'];
            }
            $request = $this->control_request('GET', 'push_status', $parameters, ['accepted']);
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            $response = $request['response'];
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || ( $response['phase'] ?? null ) !== 'receiving_work'
                || !is_int($response['work_deletes_bytes'] ?? null)
                || $response['work_deletes_bytes'] < 0
                || !is_bool($response['work_deletes_complete'] ?? null)
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_status did not return the matching push session and exact receiving_work state.');
            }
            $state['recoverable_failures'] = 0;
            if ($state['current_path_b64'] === null) {
                if (( $response['path'] ?? null ) !== null) {
                    return $this->step_result('failed', $state, 'unexpected_response', 'push_status returned a path state when none was requested.');
                }
                $state['phase'] = 'uploading_work';
                $this->persist_state($state);
                return $this->step_result('continue', $state, null, null);
            }
            $path = base64_decode($state['current_path_b64'], true);
            if (!is_string($path) || $path === '') {
                return $this->step_result('failed', $state, 'invalid_sender_state', 'The current sender path is not valid base64 text.');
            }
            $source_token = $this->source_token($path);
            $path_status = $response['path'] ?? null;
            if (!is_array($path_status) || ( $path_status['path_b64'] ?? null ) !== $state['current_path_b64']) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_status did not return the requested path state.');
            }
            $path_state = $path_status['state'] ?? null;
            $path_type = $path_status['type'] ?? null;
            $accepted_bytes = $path_status['accepted_bytes'] ?? null;
            if (
                !is_int($accepted_bytes)
                || $accepted_bytes < 0
                || !in_array($path_state, ['missing', 'partial', 'complete'], true)
                || ( $path_state === 'missing'
                    && ( $accepted_bytes !== 0 || array_key_exists('type', $path_status) ) )
                || ( $path_state !== 'missing'
                    && !in_array($path_type, ['file', 'directory', 'symlink'], true) )
                || ( $path_state === 'partial' && $path_type !== 'file' )
                || ( in_array($path_type, ['directory', 'symlink'], true) && $accepted_bytes !== 0 )
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_status returned an invalid receiver-confirmed path state.');
            }
            if ($source_token === null) {
                $state['phase'] = 'removing';
                $this->persist_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'The selected source path disappeared; remove the upload-only push session before regenerating the index.');
            }
            if ($source_token !== $state['source_token']) {
                $state['phase'] = 'uploading_work';
                $this->persist_state($state);
                return $this->step_result('continue', $state, 'source_changed', 'The source token changed; restart the same in-flight work at offset zero.');
            }
            if (
                $path_state === 'complete'
                && $path_type === $source_token['type']
                && ( $source_token['type'] !== 'file' || $accepted_bytes === $source_token['size'] )
            ) {
                $state['paths_byte_offset'] = $state['next_paths_byte_offset'];
                $state['current_path_b64'] = null;
                $state['source_token'] = null;
                $state['confirmed_bytes'] = 0;
                $state['phase'] = 'reconciling_work';
            } else {
                $state['confirmed_bytes'] = $path_state === 'partial' && $path_type === 'file'
                    ? $accepted_bytes
                    : 0;
                if ($state['confirmed_bytes'] > $source_token['size']) {
                    $state['confirmed_bytes'] = 0;
                }
                $state['phase'] = 'uploading_work';
            }
            $this->persist_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        if ($state['phase'] === 'uploading_work') {
            $paths = fopen($this->journal->local_paths_to_push, 'r');
            if ($paths === false) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not open the journal paths selected for push.');
            }
            $path_list_offset = $state['paths_byte_offset'];
            $current = null;
            while ($current === null) {
                if (fseek($paths, $path_list_offset) !== 0) {
                    fclose($paths);
                    return $this->step_result('failed', $state, 'invalid_sender_state', 'The sender path-list cursor is outside the selected path list.');
                }
                $path_line = fgets($paths);
                if ($path_line === false) {
                    if (!feof($paths)) {
                        fclose($paths);
                        return $this->step_result('failed', $state, 'local_io_error', 'Could not read the journal paths selected for push.');
                    }
                    fclose($paths);
                    $state['phase'] = 'reconciling_deletes';
                    $this->persist_state($state);
                    return $this->reconcile_work_deletes($state);
                }
                $next_path_list_offset = ftell($paths);
                if (!is_int($next_path_list_offset)) {
                    fclose($paths);
                    return $this->step_result('failed', $state, 'local_io_error', 'Could not read the next byte offset in the journal paths selected for push.');
                }
                $path_record = json_decode($path_line, true);
                $path = is_array($path_record) && is_string($path_record['path'] ?? null)
                    ? base64_decode($path_record['path'], true)
                    : false;
                if (!is_string($path) || $path === '') {
                    fclose($paths);
                    return $this->step_result('failed', $state, 'invalid_sender_state', 'The selected path list contains an invalid path record.');
                }
                $base64_path = base64_encode($path);
                $is_current_path = $state['current_path_b64'] === $base64_path;
                $source_token = $this->source_token($path);
                if ($source_token === null) {
                    fclose($paths);
                    $state['current_path_b64'] = $base64_path;
                    $state['next_paths_byte_offset'] = $next_path_list_offset;
                    $state['phase'] = 'removing';
                    $this->persist_state($state);
                    return $this->step_result('continue', $state, 'source_changed', 'The selected source path disappeared; remove the upload-only push session before regenerating the index.');
                }
                if ($source_token['type'] === 'directory') {
                    $directory_is_empty = $this->directory_is_empty($path);
                    if ($directory_is_empty === null) {
                        fclose($paths);
                        return $this->step_result('failed', $state, 'local_io_error', 'Could not read the selected source directory: ' . base64_encode($path) . '.');
                    }
                    if ($this->source_token($path) !== $source_token) {
                        continue;
                    }
                    if (!$directory_is_empty && !$is_current_path) {
                        $path_list_offset = $next_path_list_offset;
                        $state['paths_byte_offset'] = $path_list_offset;
                        $state['next_paths_byte_offset'] = $path_list_offset;
                        $state['current_path_b64'] = null;
                        $state['source_token'] = null;
                        $state['confirmed_bytes'] = 0;
                        $this->persist_state($state);
                        continue;
                    }
                }
                $receiver_confirmed_source_token = $is_current_path ? $state['source_token'] : null;
                $receiver_confirmed_bytes = $is_current_path ? $state['confirmed_bytes'] : 0;
                $current = [
                    'path' => $path,
                    'path_b64' => $base64_path,
                    'path_list_offset' => $path_list_offset,
                    'next_path_list_offset' => $next_path_list_offset,
                    'source_token' => $source_token,
                    'confirmed_bytes' => $receiver_confirmed_source_token === $source_token
                        ? $receiver_confirmed_bytes
                        : 0,
                ];
                $state['current_path_b64'] = $current['path_b64'];
                $state['next_paths_byte_offset'] = $next_path_list_offset;
                $state['source_token'] = $receiver_confirmed_source_token;
                $state['confirmed_bytes'] = $receiver_confirmed_bytes;
                $reconciliation_state = $state;
                $reconciliation_state['phase'] = 'reconciling_work';
                $this->persist_state($reconciliation_state);
            }

            if (!$this->client->start_upload_request($state['push_session_id'])) {
                fclose($paths);
                $request = [
                    'status' => 'retry',
                    'reason' => 'request_failed',
                    'detail' => $this->client->get_last_error(),
                    'response' => null,
                    'parts_sent' => 0,
                    'body_bytes_sent' => 0,
                ];
                $failure = $this->handle_request_failure($request, $state);
                return $failure ?? $this->step_result('failed', $state, 'unexpected_response', 'The work upload failed without a classified result.');
            }

            $last_sent = null;
            $next_unsent_path_list_offset = null;
            $local_failure_detail = null;
            $request_size_failure_detail = null;
            while ($current !== null) {
                $path = $current['path'];
                $source_token = $this->source_token($path);
                if ($source_token === null) {
                    $next_unsent_path_list_offset = $current['path_list_offset'];
                    break;
                }
                if ($source_token !== $current['source_token']) {
                    $this->record_source_restart($current, $source_token);
                }

                $part = null;
                $logical_value_complete = false;
                if ($source_token['type'] === 'file') {
                    $offset = $current['confirmed_bytes'];
                    if ($offset > $source_token['size']) {
                        $offset = 0;
                        $current['confirmed_bytes'] = 0;
                    }
                    $maximum = $this->client->next_file_body_bytes($path, $source_token['size'], $offset);
                    if ($maximum === 0) {
                        $next_unsent_path_list_offset = $current['path_list_offset'];
                        if ($last_sent === null) {
                            $request_size_failure_detail = 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($path) . '.';
                        }
                        break;
                    }
                    $payload = '';
                    if ($source_token['size'] > 0) {
                        $file = fopen($this->docroot . '/' . $path, 'rb');
                        if ($file === false || fseek($file, $offset) !== 0) {
                            if (is_resource($file)) {
                                fclose($file);
                            }
                            $after_open_token = $this->source_token($path);
                            if ($after_open_token !== $source_token) {
                                $this->record_source_restart($current, $after_open_token);
                                continue;
                            }
                            $next_unsent_path_list_offset = $current['path_list_offset'];
                            $local_failure_detail = 'Could not open the selected source file at its receiver-confirmed cursor: ' . base64_encode($path) . '.';
                            break;
                        }
                        $payload = fread($file, $maximum);
                        fclose($file);
                        $after_read_token = $this->source_token($path);
                        if ($after_read_token !== $source_token) {
                            $this->record_source_restart($current, $after_read_token);
                            continue;
                        }
                        if (!is_string($payload) || ( $payload === '' && $offset < $source_token['size'] )) {
                            $next_unsent_path_list_offset = $current['path_list_offset'];
                            $local_failure_detail = 'Could not read the selected source file at its receiver-confirmed cursor: ' . base64_encode($path) . '.';
                            break;
                        }
                    }
                    $part = [
                        'type' => 'file',
                        'path' => $path,
                        'total_bytes' => $source_token['size'],
                        'offset' => $offset,
                        'payload' => $payload,
                    ];
                    $logical_value_complete = $offset + strlen($payload) === $source_token['size'];
                } elseif ($source_token['type'] === 'directory') {
                    $part = ['type' => 'directory', 'path' => $path, 'payload' => ''];
                    $logical_value_complete = true;
                } else {
                    $target = @readlink($this->docroot . '/' . $path);
                    $after_read_token = $this->source_token($path);
                    if ($after_read_token !== $source_token) {
                        $this->record_source_restart($current, $after_read_token);
                        continue;
                    }
                    if (!is_string($target) || $target === '') {
                        $next_unsent_path_list_offset = $current['path_list_offset'];
                        $local_failure_detail = 'Could not read the selected source symlink target: ' . base64_encode($path) . '.';
                        break;
                    }
                    $part = ['type' => 'symlink', 'path' => $path, 'target' => $target, 'payload' => ''];
                    $logical_value_complete = true;
                }

                if (!$this->client->send_part($part)) {
                    $next_unsent_path_list_offset = $current['path_list_offset'];
                    if ($last_sent === null) {
                        $request_size_failure_detail = 'The current request-body budget cannot fit one MIME part for path ' . base64_encode($path) . '.';
                    }
                    break;
                }
                $current['confirmed_bytes'] = $source_token['type'] === 'file'
                    ? $part['offset'] + strlen($part['payload'])
                    : 0;
                $last_sent = $current;
                $last_sent['complete'] = $logical_value_complete;
                if (!$logical_value_complete) {
                    if ($this->client->should_finish_request()) {
                        break;
                    }
                    continue;
                }
                if ($this->client->should_finish_request()) {
                    break;
                }

                $path_list_offset = $current['next_path_list_offset'];
                $current = null;
                while ($current === null) {
                    if (fseek($paths, $path_list_offset) !== 0) {
                        $next_unsent_path_list_offset = $path_list_offset;
                        break 2;
                    }
                    $path_line = fgets($paths);
                    if ($path_line === false) {
                        if (!feof($paths)) {
                            $next_unsent_path_list_offset = $path_list_offset;
                            $local_failure_detail = 'Could not read the journal paths selected for push.';
                        }
                        break 2;
                    }
                    $next_path_list_offset = ftell($paths);
                    if (!is_int($next_path_list_offset)) {
                        $next_unsent_path_list_offset = $path_list_offset;
                        $local_failure_detail = 'Could not read the next byte offset in the journal paths selected for push.';
                        break 2;
                    }
                    $path_record = json_decode($path_line, true);
                    $path = is_array($path_record) && is_string($path_record['path'] ?? null)
                        ? base64_decode($path_record['path'], true)
                        : false;
                    if (!is_string($path) || $path === '') {
                        $next_unsent_path_list_offset = $path_list_offset;
                        break 2;
                    }
                    $base64_path = base64_encode($path);
                    $is_current_path = $state['current_path_b64'] === $base64_path;
                    $source_token = $this->source_token($path);
                    if ($source_token === null) {
                        $next_unsent_path_list_offset = $path_list_offset;
                        break 2;
                    }
                    if ($source_token['type'] === 'directory') {
                        $directory_is_empty = $this->directory_is_empty($path);
                        if ($directory_is_empty === null) {
                            $next_unsent_path_list_offset = $path_list_offset;
                            $local_failure_detail = 'Could not read the selected source directory: ' . base64_encode($path) . '.';
                            break 2;
                        }
                        if ($this->source_token($path) !== $source_token) {
                            continue;
                        }
                        if (!$directory_is_empty && !$is_current_path) {
                            $path_list_offset = $next_path_list_offset;
                            continue;
                        }
                    }
                    $current = [
                        'path' => $path,
                        'path_b64' => $base64_path,
                        'path_list_offset' => $path_list_offset,
                        'next_path_list_offset' => $next_path_list_offset,
                        'source_token' => $source_token,
                        'confirmed_bytes' => 0,
                    ];
                    $state['paths_byte_offset'] = $path_list_offset;
                    $state['current_path_b64'] = $current['path_b64'];
                    $state['next_paths_byte_offset'] = $next_path_list_offset;
                    $state['source_token'] = null;
                    $state['confirmed_bytes'] = 0;
                    $reconciliation_state = $state;
                    $reconciliation_state['phase'] = 'reconciling_work';
                    $this->persist_state($reconciliation_state);
                }
            }
            fclose($paths);
            $request = $this->client->finish_request();
            $state['phase'] = 'reconciling_work';
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            $state['recoverable_failures'] = 0;
            $response = $request['response'];
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || !is_int($response['changes_accepted'] ?? null)
                || $response['changes_accepted'] !== $request['parts_sent']
                || !array_key_exists('last_change', $response)
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_upload did not return the matching push session and exact accepted-part count.');
            }
            $last_change = $response['last_change'];
            if ($last_sent === null) {
                if ($last_change !== null) {
                    return $this->step_result('failed', $state, 'unexpected_response', 'push_upload returned a positive-work change when no part was sent.');
                }
                $state['paths_byte_offset'] = $path_list_offset;
                $state['current_path_b64'] = null;
                $state['source_token'] = null;
                $state['confirmed_bytes'] = 0;
            } elseif (
                !is_array($last_change)
                || ( $last_change['path_b64'] ?? null ) !== $last_sent['path_b64']
                || ( $last_change['type'] ?? null ) !== $last_sent['source_token']['type']
                || ( $last_change['state'] ?? null ) !== ( $last_sent['complete'] ? 'complete' : 'partial' )
                || !is_int($last_change['accepted_bytes'] ?? null)
                || $last_change['accepted_bytes'] !== $last_sent['confirmed_bytes']
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_upload did not confirm the exact latest positive-work state.');
            } elseif (( $last_change['state'] ?? null ) === 'complete') {
                $state['paths_byte_offset'] = $next_unsent_path_list_offset ?? $last_sent['next_path_list_offset'];
                $state['current_path_b64'] = null;
                $state['source_token'] = null;
                $state['confirmed_bytes'] = 0;
            } else {
                $state['paths_byte_offset'] = $last_sent['path_list_offset'];
                $state['next_paths_byte_offset'] = $last_sent['next_path_list_offset'];
                $state['current_path_b64'] = $last_sent['path_b64'];
                $state['source_token'] = $last_sent['source_token'];
                $state['confirmed_bytes'] = $last_change['accepted_bytes'];
            }
            $this->persist_state($state);
            if ($local_failure_detail !== null) {
                return $this->step_result('failed', $state, 'local_io_error', $local_failure_detail);
            }
            if ($request_size_failure_detail !== null) {
                return $this->step_result('failed', $state, 'request_size_exhausted', $request_size_failure_detail);
            }
            return $this->step_result('continue', $state, null, null);
        }

        if ($state['phase'] === 'reconciling_deletes') {
            return $this->reconcile_work_deletes($state);
        }

        if ($state['phase'] === 'uploading_deletes') {
            $deletes = fopen($this->journal->work_deletes_path, 'rb');
            if ($deletes === false || fseek($deletes, $state['work_deletes_byte_offset']) !== 0) {
                if (is_resource($deletes)) {
                    fclose($deletes);
                }
                return $this->step_result('failed', $state, 'local_io_error', 'Could not open the raw work-delete stream at its receiver-confirmed cursor.');
            }
            $reconciliation_state = $state;
            $reconciliation_state['phase'] = 'reconciling_deletes';
            $this->persist_state($reconciliation_state);
            if (!$this->client->start_upload_request($state['push_session_id'])) {
                fclose($deletes);
                $request = [
                    'status' => 'retry',
                    'reason' => 'request_failed',
                    'detail' => $this->client->get_last_error(),
                    'response' => null,
                    'parts_sent' => 0,
                    'body_bytes_sent' => 0,
                ];
                $failure = $this->handle_request_failure($request, $state);
                return $failure ?? $this->step_result('failed', $state, 'unexpected_response', 'The work-delete upload failed without a classified result.');
            }
            $offset = $state['work_deletes_byte_offset'];
            $sent_completion = false;
            $delete_read_failed = false;
            while (true) {
                $maximum = $this->client->next_delete_body_bytes($offset);
                if ($maximum === 0) {
                    break;
                }
                $payload = fread($deletes, $maximum);
                if (!is_string($payload)) {
                    $delete_read_failed = true;
                    break;
                }
                $complete = $payload === '' && feof($deletes);
                if (!$this->client->send_part([
                    'type' => 'delete-list',
                    'offset' => $offset,
                    'complete' => $complete,
                    'payload' => $payload,
                ])) {
                    break;
                }
                $offset += strlen($payload);
                if ($complete) {
                    $sent_completion = true;
                    break;
                }
                if ($this->client->should_finish_request()) {
                    break;
                }
            }
            fclose($deletes);
            $request = $this->client->finish_request();
            $state['phase'] = 'reconciling_deletes';
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            if ($delete_read_failed) {
                return $this->step_result('failed', $state, 'local_io_error', 'Could not read the raw work-delete stream at its receiver-confirmed cursor.');
            }
            $response = $request['response'];
            if ($request['parts_sent'] === 0) {
                return $this->step_result('failed', $state, 'request_size_exhausted', 'The current request-body budget cannot fit one work-delete MIME part.');
            }
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || !is_int($response['changes_accepted'] ?? null)
                || $response['changes_accepted'] !== $request['parts_sent']
                || !is_array($response['last_change'] ?? null)
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_upload did not return the matching push session and exact accepted work-delete part count.');
            }
            $last_change = $response['last_change'];
            if (
                ( $last_change['type'] ?? null ) !== 'delete-list'
                || ( $last_change['state'] ?? null ) !== ( $sent_completion ? 'complete' : 'partial' )
                || !is_int($last_change['accepted_bytes'] ?? null)
                || $last_change['accepted_bytes'] !== $offset
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_upload did not confirm the exact work-delete cursor and completion state.');
            }
            $state['recoverable_failures'] = 0;
            $state['work_deletes_byte_offset'] = $last_change['accepted_bytes'];
            $this->persist_state($state);
            return $this->step_result('continue', $state, null, null);
        }

        if ($state['phase'] === 'committing') {
            $request = $this->control_request('POST', 'push_commit', [
                'push_session_id' => $state['push_session_id'],
            ], ['accepted']);
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            $response = $request['response'];
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || !in_array($response['phase'] ?? null, ['deleting_files', 'installing_files', 'complete'], true)
                || !is_bool($response['send_next_request'] ?? null)
                || !is_int($response['entries_processed'] ?? null)
                || $response['entries_processed'] < 0
                || ( $response['send_next_request'] === ( $response['phase'] === 'complete' ) )
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_commit did not return the matching push session and exact bounded continuation state.');
            }
            $state['recoverable_failures'] = 0;
            if ($response['send_next_request']) {
                $this->persist_state($state);
                return $this->step_result('continue', $state, null, null);
            }
            $push_session_id = $state['push_session_id'];
            $excluded_paths = $this->decode_excluded_paths_b64($state['excluded_paths_b64'] ?? null);
            if ($excluded_paths === null) {
                return $this->step_result('failed', $state, 'invalid_sender_state', 'Sender state does not contain the receiver exclusion policy for this push session.');
            }
            $this->journal->capture_local_files_baseline($this->journal->sender_index_path, $excluded_paths);
            $this->journal->clear_sender_state();
            $this->client_state_fingerprint = null;
            return [
                'status' => 'complete',
                'phase' => 'complete',
                'push_session_id' => $push_session_id,
                'reason' => null,
                'detail' => null,
            ];
        }

        if ($state['phase'] === 'removing') {
            $request = $this->control_request('POST', 'push_remove', [
                'push_session_id' => $state['push_session_id'],
            ], ['accepted']);
            $failure = $this->handle_request_failure($request, $state);
            if ($failure !== null) {
                return $failure;
            }
            $response = $request['response'];
            if (
                !is_array($response)
                || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
                || !is_bool($response['removed'] ?? null)
            ) {
                return $this->step_result('failed', $state, 'unexpected_response', 'push_remove did not return the matching push session and exact bounded completion state.');
            }
            $state['recoverable_failures'] = 0;
            if (!$response['removed']) {
                $this->persist_state($state);
                return $this->step_result('continue', $state, null, null);
            }
            $push_session_id = $state['push_session_id'];
            $this->journal->clear_sender_state();
            $this->client_state_fingerprint = null;
            return [
                'status' => 'restart',
                'phase' => 'complete',
                'push_session_id' => $push_session_id,
                'reason' => 'source_changed',
                'detail' => 'The abandoned upload-only push session was removed. Regenerate the local index before retrying.',
            ];
        }

        return $this->step_result('failed', $state, 'invalid_sender_state', 'Sender state contains an unsupported phase.');
    }

    /**
     * Restores transport limits from the checkpoint read under the sender lock.
     *
     * @param array<string,mixed>|null $state Authoritative sender checkpoint.
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
     * Decodes the receiver's normalized excluded-path policy.
     *
     * @param mixed $encoded_paths Candidate list from push_create or sender state.
     * @return list<string>|null Raw paths, or null when the policy is not the
     *     canonical sorted, deduplicated server shape.
     */
    private function decode_excluded_paths_b64($encoded_paths): ?array
    {
        if (!is_array($encoded_paths) || array_values($encoded_paths) !== $encoded_paths) {
            return null;
        }
        $decoded_paths = [];
        foreach ($encoded_paths as $encoded_path) {
            if (!is_string($encoded_path)) {
                return null;
            }
            $path = base64_decode($encoded_path, true);
            if (
                !is_string($path)
                || base64_encode($path) !== $encoded_path
                || !$this->is_safe_document_root_relative_path($path)
            ) {
                return null;
            }
            $decoded_paths[] = $path;
        }
        $normalized_paths = $decoded_paths;
        sort($normalized_paths, SORT_STRING);
        $normalized_paths = array_values(array_unique($normalized_paths));
        return $normalized_paths === $decoded_paths ? $decoded_paths : null;
    }

    /**
     * Reports whether raw path bytes name one safe document-root-relative path.
     */
    private function is_safe_document_root_relative_path(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Reconciles the raw work-delete stream with the target cursor.
     *
     * This request runs both when positive work reaches its path-list end and
     * after every work-delete upload. A cursor beyond the stable local stream
     * cannot be resumed, so the upload-only push session is removed before the
     * caller regenerates its local index.
     *
     * @param array<string,mixed> $state Complete sender checkpoint in the exact
     *     PushJournal::write_sender_state() shape.
     * @return array{
     *     status:'continue'|'failed',
     *     phase:'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     push_session_id:string,
     *     reason:?string,
     *     detail:?string
     * } Result of the real push_status request and durable transition.
     */
    private function reconcile_work_deletes(array &$state): array
    {
        $request = $this->control_request('GET', 'push_status', [
            'push_session_id' => $state['push_session_id'],
        ], ['accepted']);
        $failure = $this->handle_request_failure($request, $state);
        if ($failure !== null) {
            return $failure;
        }
        $response = $request['response'];
        $work_deletes_bytes = is_array($response) ? ( $response['work_deletes_bytes'] ?? null ) : null;
        $work_deletes_complete = is_array($response) ? ( $response['work_deletes_complete'] ?? null ) : null;
        $local_work_deletes_bytes = @filesize($this->journal->work_deletes_path);
        if (
            !is_array($response)
            || ( $response['push_session_id'] ?? null ) !== $state['push_session_id']
            || ( $response['phase'] ?? null ) !== 'receiving_work'
            || ( $response['path'] ?? null ) !== null
            || !is_int($work_deletes_bytes)
            || $work_deletes_bytes < 0
            || !is_bool($work_deletes_complete)
        ) {
            return $this->step_result('failed', $state, 'unexpected_response', 'push_status did not return the exact receiver-confirmed work-delete state.');
        }
        if (!is_int($local_work_deletes_bytes)) {
            return $this->step_result('failed', $state, 'local_io_error', 'Could not read the stable local work-delete stream size.');
        }
        if ($work_deletes_bytes > $local_work_deletes_bytes) {
            $state['phase'] = 'removing';
            $this->persist_state($state);
            return $this->step_result('continue', $state, 'source_changed', 'The receiver work-delete cursor cannot belong to the current local deletion list.');
        }
        $state['recoverable_failures'] = 0;
        $state['work_deletes_byte_offset'] = $work_deletes_bytes;
        $state['phase'] = $work_deletes_complete ? 'committing' : 'uploading_deletes';
        $this->persist_state($state);
        return $this->step_result('continue', $state, null, null);
    }

    /**
     * Records a changed source token for the open request.
     *
     * The durable checkpoint deliberately retains the previous source token
     * until an upload response confirms a part from the new token. If the
     * process dies before that response, the mismatch forces another
     * offset-zero restart instead of accepting an old-version receiver cursor.
     * A null token likewise leaves the previous evidence in place; if the path
     * remains absent, status reconciliation removes the upload-only push
     * session.
     *
     * @param array{
     *     path:string,
     *     path_b64:string,
     *     path_list_offset:int,
     *     next_path_list_offset:int,
     *     source_token:array{type:'file'|'directory'|'symlink',size:int,ctime:int}|null,
     *     confirmed_bytes:int
     * } $current Selected positive-work value for the open request.
     * @param array{type:'file'|'directory'|'symlink',size:int,ctime:int}|null $source_token
     *     New source token, or null when the selected path disappeared.
     */
    private function record_source_restart(array &$current, ?array $source_token): void
    {
        $current['source_token'] = $source_token;
        $current['confirmed_bytes'] = 0;
    }

    /**
     * Sends a signed control request and classifies transport exceptions.
     *
     * Redirects are permanent because the HMAC covers the original target,
     * and invalid JSON is a terminal protocol response. A missing response is
     * recoverable within the sender's fixed retry bound; repeating create,
     * status, commit, or remove is idempotent.
     *
     * @param string $method GET or POST.
     * @param string $endpoint Push protocol endpoint.
     * @param array<string,mixed> $parameters Request-target parameters.
     * @param list<string> $expected_statuses Successful response statuses.
     * @return array{
     *     status:'complete'|'retry'|'failed',
     *     reason:?string,
     *     detail:?string,
     *     response:?array<string,mixed>,
     *     parts_sent:int,
     *     body_bytes_sent:int
     * } Classified control result.
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
     * Indicates whether a selected source directory contains no child entry.
     *
     * The directory handle stops at the first name other than `.` or `..`, so
     * checking a large non-empty directory never materializes its entry list.
     *
     * @param string $path Raw document-root-relative directory path.
     * @return bool|null True for an empty directory, false for a non-empty
     *     directory, or null when the directory cannot be opened.
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
                    break;
                }
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }
            return true;
        } finally {
            closedir($directory);
        }
    }

    /**
     * Returns the current source evidence used to decide resume versus restart.
     *
     * Paths are document-root-relative byte strings. Regular files,
     * directories, and symlinks are the only sendable types. Size and ctime
     * match PushJournal's diff evidence; a changed token restarts the same
     * in-flight work at offset zero. A same-size edit within one ctime second
     * remains the documented timestamp-resolution gap.
     *
     * @param string $path Raw document-root-relative path.
     * @return array{type:'file'|'directory'|'symlink',size:int,ctime:int}|null
     *     Current source token, or null when the path is absent or unsupported.
     */
    private function source_token(string $path): ?array
    {
        if (!$this->is_safe_document_root_relative_path($path)) {
            throw new InvalidArgumentException('A selected push path is not a safe document-root-relative path: ' . base64_encode($path) . '.');
        }
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
     * Complete results return null for phase-specific handling. Recoverable
     * results increment the durable failure count and become terminal after
     * five consecutive failures. Permanent protocol results fail immediately.
     *
     * @param array{
     *     status:'complete'|'retry'|'failed',reason:?string,detail:?string,
     *     response:?array<string,mixed>,parts_sent:int,body_bytes_sent:int
     * } $request Classified client request result.
     * @param array<string,mixed> $state Complete sender checkpoint, updated
     *     and persisted when sizing or retry evidence changes.
     * @return array{
     *     status:'continue'|'failed',
     *     phase:'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     push_session_id:string,
     *     reason:?string,
     *     detail:?string
     * }|null Null when the request completed successfully.
     */
    private function handle_request_failure(array $request, array &$state): ?array
    {
        if ($request['status'] === 'complete') {
            return null;
        }
        if ($request['status'] === 'retry') {
            ++$state['recoverable_failures'];
            $this->persist_state($state);
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
        $this->persist_state($state);
        return $this->step_result('failed', $state, $request['reason'], $request['detail']);
    }

    /**
     * Persists a complete checkpoint with current request-sizing evidence.
     *
     * @param array<string,mixed> $state Complete sender state in the exact
     *     PushJournal::write_sender_state() shape.
     */
    private function persist_state(array $state): void
    {
        $state['request_sizer_state'] = $this->client->get_request_sizer_state();
        $this->journal->write_sender_state($state);
        $this->client_has_authoritative_state = true;
        $this->client_state_fingerprint = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Builds one exact workflow result without changing durable state.
     *
     * @param 'continue'|'failed' $status Step disposition.
     * @param array<string,mixed> $state Complete sender checkpoint.
     * @param string|null $reason Machine-readable classification.
     * @param string|null $detail Human-readable condition.
     * @return array{
     *     status:'continue'|'failed',
     *     phase:'creating'|'reconciling_work'|'uploading_work'|'reconciling_deletes'|'uploading_deletes'|'committing'|'removing',
     *     push_session_id:string,
     *     reason:?string,
     *     detail:?string
     * } Step result.
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
