<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Endpoint errors are returned as API JSON, never rendered as HTML.

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/class-staged-push-stream-protocol.php';
}
if (!class_exists('Site_Export_Staged_Apply', false)) {
    require_once __DIR__ . '/class-staged-apply.php';
}

/**
 * Authenticated HTTP surface for target-owned staged apply sessions.
 *
 * A push request carries typed operations, not an apply manifest. Each frame
 * is validated and materialized in the private session before its server-owned
 * journal record becomes durable. `advance` closes uploads and commits a fixed
 * batch directly from that journal; there is no seal, preparation, or
 * validation pass between upload and commit.
 *
 * All session parameters live in the signed request target. The streaming
 * route authenticates its method and target before reading the body and relies
 * on TLS for body integrity, so it can carry many files in one request without
 * buffering or hashing the entity body. A request-generation fence rejects a
 * lost-response replay before PHP reads its body. Status then reports only the
 * target-confirmed operation count and active file cursor.
 */
final class Site_Export_Staged_Endpoints {

    /** The sender's hard ceiling for one file-frame payload. */
    private const DEFAULT_MAX_FRAME_BYTES = 1073741824;

    /** One durable file-write step per this many request-body bytes. */
    private const DEFAULT_APPEND_BUFFER_BYTES = 262144;

    /** Matches the direct session's largest bounded file-write step. */
    private const MAX_APPEND_BUFFER_BYTES = 4194304;

    /** Bound zero-payload work as well as file chunks in one PHP request. */
    private const DEFAULT_MAX_FRAMES_PER_REQUEST = 1024;

    /** Endpoint-owned failure when live apply recovery is unavailable. */
    private const ERROR_APPLY_NOT_CONFIGURED = 2001;

    private const APPLY_NOT_CONFIGURED_MESSAGE = 'Server configuration has not enabled a safe staged-apply recovery entry point.';

    /** @var string */
    private $staging_dir;

    /** @var string|null */
    private $apply_target_root;

    /** @var string[] */
    private $apply_protected_paths;

    /** @var bool */
    private $apply_sessions_enabled;

    /** @var string|null */
    private $secret;

    /** @var int */
    private $max_frame_bytes;

    /** @var int|null Actual PHP entity-body limit, when the host reports one. */
    private $post_max_bytes;

    /** @var int */
    private $append_buffer_bytes;

    /** @var int */
    private $max_frames_per_request;

    /** @var int */
    private $timestamp_tolerance;

    /**
     * @param array $options Server-owned configuration:
     *   - staging_dir (string, required): private session storage.
     *   - secret (?string): shared secret for request authentication.
     *   - max_frame_bytes (int): maximum file payload in one frame.
     *   - append_buffer_bytes (int): one target write step.
     *   - max_frames_per_request (int): bounds metadata-only work.
     *   - timestamp_tolerance (int): HMAC freshness window in seconds.
     *   - apply_target_root (?string): server-owned live target.
     *   - apply_protected_paths (string[]): paths sessions never mutate.
     *   - apply_sessions_enabled (bool): whether a boot-independent recovery
     *     entry point can finish a live apply.
     */
    public function __construct(array $options) {
        $staging_dir = $options['staging_dir'] ?? null;
        if (!is_string($staging_dir) || $staging_dir === '') {
            throw new InvalidArgumentException('Staged endpoints require a staging_dir option.');
        }
        $this->staging_dir = $staging_dir;

        $apply_target_root = $options['apply_target_root'] ?? null;
        if ($apply_target_root !== null && ( !is_string($apply_target_root) || $apply_target_root === '' )) {
            throw new InvalidArgumentException('Staged apply target root must be a non-empty string when configured.');
        }
        $this->apply_target_root = $apply_target_root;

        $protected_paths = $options['apply_protected_paths'] ?? [];
        if (!is_array($protected_paths)) {
            throw new InvalidArgumentException('Staged apply protected paths must be an array of target-relative paths.');
        }
        $this->apply_protected_paths = $protected_paths;

        $apply_sessions_enabled = $options['apply_sessions_enabled'] ?? true;
        if (!is_bool($apply_sessions_enabled)) {
            throw new InvalidArgumentException('Staged apply_sessions_enabled must be a boolean.');
        }
        $this->apply_sessions_enabled = $apply_sessions_enabled;

        $secret = $options['secret'] ?? null;
        if ($secret !== null && ( !is_string($secret) || $secret === '' )) {
            throw new InvalidArgumentException('Staged secret must be a non-empty string or null when configured.');
        }
        $this->secret = $secret;

        $post_max_size = ini_get('post_max_size');
        $post_max_bytes = is_string($post_max_size) && $post_max_size !== ''
            ? parse_size($post_max_size)
            : 0;
        $this->post_max_bytes = $post_max_bytes > 0 ? $post_max_bytes : null;

        $max_frame_bytes = $options['max_frame_bytes'] ?? null;
        if ($max_frame_bytes === null) {
            $max_frame_bytes = $this->post_max_bytes ?? self::DEFAULT_MAX_FRAME_BYTES;
        } elseif (!is_numeric($max_frame_bytes) || (int) $max_frame_bytes <= 0) {
            throw new InvalidArgumentException('Staged max_frame_bytes must be a positive integer when configured.');
        }
        $this->max_frame_bytes = (int) $max_frame_bytes;

        $append_buffer_bytes = $options['append_buffer_bytes'] ?? self::DEFAULT_APPEND_BUFFER_BYTES;
        if (
            !is_numeric($append_buffer_bytes)
            || (int) $append_buffer_bytes <= 0
            || (int) $append_buffer_bytes > self::MAX_APPEND_BUFFER_BYTES
        ) {
            throw new InvalidArgumentException(
                'Staged append_buffer_bytes must be between 1 and ' . self::MAX_APPEND_BUFFER_BYTES . ' when configured.'
            );
        }
        $this->append_buffer_bytes = (int) $append_buffer_bytes;

        $max_frames_per_request = $options['max_frames_per_request'] ?? self::DEFAULT_MAX_FRAMES_PER_REQUEST;
        if (
            !is_numeric($max_frames_per_request)
            || (int) $max_frames_per_request <= 0
            || (int) $max_frames_per_request > Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST
        ) {
            throw new InvalidArgumentException(
                'Staged max_frames_per_request must be between 1 and '
                . Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST . ' when configured.'
            );
        }
        $this->max_frames_per_request = (int) $max_frames_per_request;

        $timestamp_tolerance = $options['timestamp_tolerance'] ?? 300;
        if (!is_numeric($timestamp_tolerance) || (int) $timestamp_tolerance <= 0) {
            throw new InvalidArgumentException('Staged timestamp_tolerance must be a positive integer when configured.');
        }
        $this->timestamp_tolerance = (int) $timestamp_tolerance;
    }

    /** @return array{http_code:int,body:array} */
    public function session_create(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        $auth_error = $this->require_envelope_auth($headers, 'staged_session_create');
        if ($auth_error !== null) {
            return $auth_error;
        }

        return $this->session_action(function () use ($config, $headers): array {
            $parameters = $this->request_target_parameters($headers);
            $create_token = $parameters['create_token'] ?? null;
            if (!is_string($create_token) || !preg_match('/^[a-f0-9]{32}$/D', $create_token)) {
                throw new InvalidArgumentException('Staged apply session creation requires a 32-character lowercase hexadecimal create_token in the signed request target.');
            }
            if (array_key_exists('create_token', $config) && $config['create_token'] !== $create_token) {
                throw new InvalidArgumentException('Staged apply create_token must match the signed request target.');
            }
            if (!$this->apply_sessions_enabled || $this->apply_target_root === null) {
                return $this->rejected(503, 'apply_not_configured', self::APPLY_NOT_CONFIGURED_MESSAGE);
            }

            // A client persists this token before sending create. The server
            // derives the id, so replay after a lost response opens the same
            // workspace without accepting a caller-chosen session id.
            $server_session_id = substr(hash_hmac('sha256', 'reprint-staged-apply-create-v2:' . $create_token, (string) $this->secret), 0, 32);
            $retired_session_seconds = $this->timestamp_tolerance > intdiv(PHP_INT_MAX - 1, 2)
                ? PHP_INT_MAX
                : $this->timestamp_tolerance * 2 + 1;
            $session = Site_Export_Staged_Apply::create(
                $this->staging_dir,
                $this->apply_target_root,
                $this->apply_protected_paths,
                $server_session_id,
                $retired_session_seconds
            );
            $state = $session->get_status();
            $body = $this->session_state_body($state);
            $body['status'] = 'created';
            $body['max_frame_bytes'] = $this->max_frame_bytes;
            $body['max_frames_per_request'] = $this->max_frames_per_request;
            // This is the decoded entity-body budget PHP reports. A proxy's
            // hidden limit is learned later from actual 413 responses.
            $body['post_max_bytes'] = $this->post_max_bytes;
            return ['http_code' => 201, 'body' => $body];
        });
    }

    /**
     * Authenticate before the HTTP server parses or buffers other input.
     *
     * @return array{http_code:int,body:array}|null
     */
    public function pre_authenticate_envelope(array $headers, string $expected_endpoint): ?array {
        $error = $this->require_envelope_auth($headers, $expected_endpoint);
        if ($error === null || $error['http_code'] === 503) {
            return $error;
        }
        return $this->rejected(403, 'auth_failed', 'Authentication failed.');
    }

    /**
     * Stream typed operations into one isolated target session.
     *
     * @param resource|null $input
     * @return array{http_code:int,body:array}
     */
    public function session_push_stream(array $config, array $headers, $input): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        $auth_error = $this->require_envelope_auth($headers, 'staged_session_push');
        if ($auth_error !== null) {
            return $auth_error;
        }

        return $this->session_action(function () use ($config, $headers, $input): array {
            $expected_request_generation = $this->expected_session_request_generation($headers);
            $session = $this->open_session($config, $headers);
            return $session->while_uploading(
                $expected_request_generation,
                function (Site_Export_Staged_Apply $locked_session) use ($input): array {
                    return $this->stream_operation_frames($locked_session, $input);
                }
            );
        });
    }

    /** Close uploads and commit the next fixed server-owned batch. */
    public function session_advance(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        $auth_error = $this->require_envelope_auth($headers, 'staged_session_advance');
        if ($auth_error !== null) {
            return $auth_error;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $session = $this->open_session($config, $headers);
            $state = $session->advance($this->expected_session_request_generation($headers));
            return $this->session_state_response($state);
        });
    }

    /** @return array{http_code:int,body:array} */
    public function session_status(array $config, array $headers): array {
        $method_error = $this->require_get($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        $auth_error = $this->require_envelope_auth($headers, 'staged_session_status');
        if ($auth_error !== null) {
            return $auth_error;
        }
        return $this->session_action(function () use ($config, $headers): array {
            return $this->session_state_response($this->open_session($config, $headers)->get_status());
        });
    }

    /** @return array{http_code:int,body:array} */
    public function session_discard(array $config, array $headers): array {
        $method_error = $this->require_post($headers);
        if ($method_error !== null) {
            return $method_error;
        }
        $auth_error = $this->require_envelope_auth($headers, 'staged_session_discard');
        if ($auth_error !== null) {
            return $auth_error;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $session = $this->open_session($config, $headers);
            $discarded = $session->discard($this->expected_session_request_generation($headers));
            return [
                'http_code' => 200,
                'body' => [
                    'status' => $discarded ? 'discarded' : 'discarding',
                    'session_id' => $session->get_session_id(),
                ],
            ];
        });
    }

    /**
     * Process at most the server-owned frame cap. Each file piece is passed to
     * the session before the next piece is read, so a truncated request loses
     * at most one bounded in-memory piece and resumes at the persisted cursor.
     *
     * @param resource|null $input
     * @return array{http_code:int,body:array}
     */
    private function stream_operation_frames(Site_Export_Staged_Apply $session, $input): array {
        if (!is_resource($input)) {
            return $this->rejected(500, 'io_error', 'Could not open the staged push request body.');
        }

        $frames_processed = 0;
        while ($frames_processed < $this->max_frames_per_request && !feof($input)) {
            try {
                $line = Site_Export_Staged_Push_Stream_Protocol::read_header_line($input);
            } catch (InvalidArgumentException $exception) {
                $session->fail_upload($exception->getMessage());
                return $this->stream_rejected(400, 'invalid_frame', $exception->getMessage(), $session);
            } catch (RuntimeException $exception) {
                // The stream itself failed, rather than carrying a malformed
                // header. A later request can resume from durable session
                // state, so this must not turn the session terminal.
                return $this->stream_rejected(400, 'body_read_failed', $exception->getMessage(), $session);
            }
            if ($line === null) {
                break;
            }
            try {
                $frame = Site_Export_Staged_Push_Stream_Protocol::decode_operation_header($line);
            } catch (InvalidArgumentException $exception) {
                $session->fail_upload($exception->getMessage());
                return $this->stream_rejected(400, 'invalid_frame', $exception->getMessage(), $session);
            }

            ++$frames_processed;
            if ($frame['type'] === 'directory') {
                $result = $session->accept_directory($frame['operation_index'], $frame['path']);
                $rejection = $this->accept_result_rejection($result, $session);
                if ($rejection !== null) {
                    return $rejection;
                }
                continue;
            }
            if ($frame['type'] === 'symlink') {
                $result = $session->accept_symlink($frame['operation_index'], $frame['path'], $frame['target']);
                $rejection = $this->accept_result_rejection($result, $session);
                if ($rejection !== null) {
                    return $rejection;
                }
                continue;
            }
            if ($frame['type'] === 'delete') {
                $result = $session->accept_delete($frame['operation_index'], $frame['path']);
                $rejection = $this->accept_result_rejection($result, $session);
                if ($rejection !== null) {
                    return $rejection;
                }
                continue;
            }

            if ($frame['bytes'] > $this->max_frame_bytes) {
                $response = $this->stream_rejected(413, 'frame_too_large', 'The file frame payload exceeds this target\'s limit.', $session);
                $response['body']['max_frame_bytes'] = $this->max_frame_bytes;
                return $response;
            }

            $remaining = $frame['bytes'];
            $piece_offset = $frame['offset'];
            $restart = $frame['restart'];
            if ($remaining === 0) {
                $result = $session->append_file_chunk(
                    $frame['operation_index'],
                    $frame['path'],
                    $frame['revision'],
                    $piece_offset,
                    '',
                    $frame['total_bytes'],
                    $restart
                );
                $rejection = $this->accept_result_rejection($result, $session);
                if ($rejection !== null) {
                    return $rejection;
                }
                continue;
            }

            while ($remaining > 0) {
                $piece_bytes = min($remaining, $this->append_buffer_bytes);
                $payload = Site_Export_Staged_Push_Stream_Protocol::read_exactly($input, $piece_bytes);
                if ($payload === null) {
                    return $this->stream_rejected(
                        400,
                        'body_read_failed',
                        'The request body ended before the declared file-frame payload was complete.',
                        $session
                    );
                }
                $result = $session->append_file_chunk(
                    $frame['operation_index'],
                    $frame['path'],
                    $frame['revision'],
                    $piece_offset,
                    $payload,
                    $frame['total_bytes'],
                    $restart
                );
                $rejection = $this->accept_result_rejection($result, $session);
                if ($rejection !== null) {
                    return $rejection;
                }
                $piece_offset += strlen($payload);
                $remaining -= strlen($payload);
                $restart = false;
            }
        }

        $body = $this->session_state_body($session->get_status());
        $body['status'] = 'complete';
        $body['frames_processed'] = $frames_processed;
        return ['http_code' => 200, 'body' => $body];
    }

    /** @return array{http_code:int,body:array}|null */
    private function accept_result_rejection(array $result, Site_Export_Staged_Apply $session): ?array {
        if ($result['status'] !== 'rejected') {
            return null;
        }
        $reason = (string) $result['reason'];
        $http_code = $reason === 'operation_gap' || $reason === 'offset_gap'
            ? 409
            : 400;
        return $this->stream_rejected($http_code, $reason, null, $session);
    }

    private function open_session(array $config, array $headers): Site_Export_Staged_Apply {
        if (!$this->apply_sessions_enabled || $this->apply_target_root === null) {
            throw new RuntimeException(self::APPLY_NOT_CONFIGURED_MESSAGE, self::ERROR_APPLY_NOT_CONFIGURED);
        }
        $parameters = $this->request_target_parameters($headers);
        $session_id = $parameters['session_id'] ?? null;
        if (!is_string($session_id) || $session_id === '') {
            throw new InvalidArgumentException('Staged apply session requests require a session_id in the signed request target.');
        }
        if (array_key_exists('session_id', $config) && $config['session_id'] !== $session_id) {
            throw new InvalidArgumentException('Staged apply session_id must match the signed request target.');
        }
        return Site_Export_Staged_Apply::open(
            $this->staging_dir,
            $this->apply_target_root,
            $session_id,
            $this->apply_protected_paths
        );
    }

    private function expected_session_request_generation(array $headers): int {
        $parameters = $this->request_target_parameters($headers);
        $value = $parameters['expected_request_generation'] ?? null;
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            throw new InvalidArgumentException('Staged apply session requests require a non-negative expected_request_generation in the signed request target.');
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || ( strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0 )) {
            throw new InvalidArgumentException('The staged apply expected_request_generation exceeds this server\'s integer range: ' . $value . '.');
        }
        return (int) $value;
    }

    /** @return array{http_code:int,body:array} */
    private function session_state_response(array $state): array {
        return ['http_code' => 200, 'body' => $this->session_state_body($state)];
    }

    /** @return array<string,mixed> */
    private function session_state_body(array $state): array {
        return [
            'status' => 'ok',
            'session_id' => $state['session_id'],
            'phase' => $state['phase'],
            'request_generation' => $state['request_generation'],
            'operation_count' => $state['operation_count'],
            'current_file' => $state['current_file'] ?? null,
            'commit_offset' => $state['commit_offset'] ?? 0,
            'committed_operations' => $state['commit_count'] ?? 0,
            'failure' => $state['failure'] ?? null,
        ];
    }

    /**
     * @param callable():array{http_code:int,body:array} $callback
     * @return array{http_code:int,body:array}
     */
    private function session_action(callable $callback): array {
        try {
            return $callback();
        } catch (InvalidArgumentException $exception) {
            return $this->rejected(400, 'invalid_session_request', $exception->getMessage());
        } catch (RuntimeException $exception) {
            switch ($exception->getCode()) {
                case self::ERROR_APPLY_NOT_CONFIGURED:
                    return $this->rejected(503, 'apply_not_configured', $exception->getMessage());
                case Site_Export_Staged_Apply::ERROR_RETRYABLE_IO:
                    return $this->rejected(500, 'retryable_io_error', $exception->getMessage());
                case Site_Export_Staged_Apply::ERROR_BUSY:
                    return $this->rejected(423, 'busy', $exception->getMessage());
                case Site_Export_Staged_Apply::ERROR_DISCARD_PENDING:
                    return $this->rejected(423, 'discard_pending', $exception->getMessage());
                case Site_Export_Staged_Apply::ERROR_STALE_GENERATION:
                    return $this->rejected(409, 'stale_session_state', $exception->getMessage());
                case Site_Export_Staged_Apply::ERROR_SESSION_NOT_FOUND:
                    return $this->rejected(404, 'session_not_found', $exception->getMessage());
                default:
                    return $this->rejected(409, 'session_rejected', $exception->getMessage());
            }
        }
    }

    /** @return array{http_code:int,body:array}|null */
    private function require_post(array $headers): ?array {
        if (strtoupper( (string) ( $headers['REQUEST_METHOD'] ?? '' )) === 'POST') {
            return null;
        }
        return $this->rejected(405, 'method_not_allowed');
    }

    /** @return array{http_code:int,body:array}|null */
    private function require_get(array $headers): ?array {
        if (strtoupper( (string) ( $headers['REQUEST_METHOD'] ?? '' )) === 'GET') {
            return null;
        }
        return $this->rejected(405, 'method_not_allowed');
    }

    /** @return array{http_code:int,body:array}|null */
    private function require_envelope_auth(array $headers, string $expected_endpoint): ?array {
        if ($this->secret === null) {
            return $this->rejected(503, 'not_configured', 'Staged session authentication is unavailable because no shared secret is configured.');
        }
        $request_target = $headers['REQUEST_URI'] ?? null;
        if (!is_string($request_target) || $request_target === '') {
            return $this->rejected(400, 'missing_request_target');
        }
        $error = ( new Site_Export_HMAC_Server($this->secret, $this->timestamp_tolerance) )->verify_envelope(
            $headers,
            (string) ( $headers['REQUEST_METHOD'] ?? '' ),
            $request_target
        );
        if ($error !== null) {
            return $this->rejected(403, 'auth_failed', $error);
        }
        try {
            $parameters = $this->request_target_parameters($headers);
        } catch (InvalidArgumentException $exception) {
            return $this->rejected(400, 'invalid_request_target', $exception->getMessage());
        }
        if (( $parameters['endpoint'] ?? null ) !== $expected_endpoint) {
            return $this->rejected(400, 'invalid_request_target', 'The signed request target must name endpoint=' . $expected_endpoint . '.');
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function request_target_parameters(array $headers): array {
        $request_target = $headers['REQUEST_URI'] ?? null;
        if (!is_string($request_target) || $request_target === '') {
            throw new InvalidArgumentException('The request target is missing.');
        }
        $query = parse_url($request_target, PHP_URL_QUERY);
        if ($query === false) {
            throw new InvalidArgumentException('The request target is malformed.');
        }
        $parameters = [];
        parse_str(is_string($query) ? $query : '', $parameters);
        return $parameters;
    }

    /** @return array{http_code:int,body:array} */
    private function stream_rejected(int $http_code, string $reason, ?string $detail, Site_Export_Staged_Apply $session): array {
        $body = $this->session_state_body($session->get_status());
        $body['status'] = 'rejected';
        $body['reason'] = $reason;
        $body['detail'] = $detail;
        return ['http_code' => $http_code, 'body' => $body];
    }

    /** @return array{http_code:int,body:array} */
    private function rejected(int $http_code, string $reason, ?string $detail = null): array {
        return [
            'http_code' => $http_code,
            'body' => [
                'status' => 'rejected',
                'reason' => $reason,
                'detail' => $detail,
            ],
        ];
    }
}
