<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Endpoint errors are API JSON, never HTML.

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_Multipart_Stream_Input', false)) {
    require_once __DIR__ . '/class-multipart-stream-input.php';
}
if (!class_exists('Site_Export_Staged_Apply_Session', false)) {
    require_once __DIR__ . '/class-staged-apply-session.php';
}

/**
 * The authenticated target half of multipart push.
 *
 * Routes authenticate their signed request target before this class opens a
 * multipart body. The workspace, not a sender cursor, is the durable source
 * of upload truth; control calls expose only state derived from that tree.
 */
final class Site_Export_Staged_Endpoints {

    private const DEFAULT_MAX_FRAME_BYTES = 4194304;

    private const DEFAULT_COMMIT_STEPS = 8;

    private const DEFAULT_MAX_UPLOAD_PARTS = 128;

    private const MAX_STATUS_PATHS = 32;

    /** @var string */
    private $staging_dir;

    /** @var string|null */
    private $secret;

    /** @var string|null */
    private $apply_target_root;

    /** @var string[] */
    private $apply_protected_paths;

    /** @var bool */
    private $apply_sessions_enabled;

    /** @var int */
    private $timestamp_tolerance;

    /** @var int */
    private $max_frame_bytes;

    /** @var int */
    private $max_commit_steps;

    /** @var int */
    private $max_upload_parts;

    /** @var int|null */
    private $post_max_bytes;

    /**
     * @param array<string,mixed> $options Server-owned configuration.
     */
    public function __construct(array $options) {
        $staging_dir = $options['staging_dir'] ?? null;
        if (!is_string($staging_dir) || $staging_dir === '') {
            throw new InvalidArgumentException('Staged session endpoints require a staging_dir option.');
        }
        $this->staging_dir = $staging_dir;

        $secret = $options['secret'] ?? null;
        if ($secret !== null && !is_string($secret)) {
            throw new InvalidArgumentException('Staged session secret must be a string or null.');
        }
        $this->secret = $secret;

        $target_root = $options['apply_target_root'] ?? null;
        if ($target_root !== null && (!is_string($target_root) || $target_root === '')) {
            throw new InvalidArgumentException('Staged session apply_target_root must be a non-empty string when configured.');
        }
        $this->apply_target_root = $target_root;

        $protected_paths = $options['apply_protected_paths'] ?? [];
        if (!is_array($protected_paths)) {
            throw new InvalidArgumentException('Staged session apply_protected_paths must be an array.');
        }
        $this->apply_protected_paths = $protected_paths;

        $enabled = $options['apply_sessions_enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Staged session apply_sessions_enabled must be a boolean.');
        }
        $this->apply_sessions_enabled = $enabled;

        $tolerance = $options['timestamp_tolerance'] ?? 300;
        if (!is_numeric($tolerance) || (int) $tolerance <= 0) {
            throw new InvalidArgumentException('Staged session timestamp_tolerance must be a positive integer.');
        }
        $this->timestamp_tolerance = (int) $tolerance;

        $max_frame_bytes = $options['max_frame_bytes'] ?? self::DEFAULT_MAX_FRAME_BYTES;
        if (!is_numeric($max_frame_bytes) || (int) $max_frame_bytes <= 0) {
            throw new InvalidArgumentException('Staged session max_frame_bytes must be a positive integer.');
        }
        $this->max_frame_bytes = (int) $max_frame_bytes;

        $max_commit_steps = $options['max_commit_steps'] ?? self::DEFAULT_COMMIT_STEPS;
        if (!is_numeric($max_commit_steps) || (int) $max_commit_steps <= 0) {
            throw new InvalidArgumentException('Staged session max_commit_steps must be a positive integer.');
        }
        $this->max_commit_steps = (int) $max_commit_steps;

        $max_upload_parts = $options['max_upload_parts'] ?? self::DEFAULT_MAX_UPLOAD_PARTS;
        if (!is_numeric($max_upload_parts) || (int) $max_upload_parts <= 0) {
            throw new InvalidArgumentException('Staged session max_upload_parts must be a positive integer.');
        }
        $this->max_upload_parts = (int) $max_upload_parts;

        $post_max_size = ini_get('post_max_size');
        $post_max_bytes = is_string($post_max_size) && $post_max_size !== '' ? parse_size($post_max_size) : 0;
        $this->post_max_bytes = $post_max_bytes > 0 ? $post_max_bytes : null;
    }

    /**
     * Authenticate before the dispatcher opens or parses a request body.
     *
     * @return array{http_code:int,body:array}|null
     */
    public function pre_authenticate_envelope(array $headers, string $expected_endpoint): ?array {
        $response = $this->require_envelope_auth($headers, $expected_endpoint);
        if ($response === null || $response['http_code'] === 503) {
            return $response;
        }
        return $this->rejected(403, 'auth_failed', 'Authentication failed.');
    }

    /**
     * Report whether this server can accept a staged push without exposing
     * its secret or creating session storage as a side effect.
     *
     * @return array<string,mixed>
     */
    public function get_preflight_capability(): array {
        $capability = [
            'available' => false,
            'filesystem_ok' => false,
            'reason' => null,
            'max_frame_bytes' => $this->max_frame_bytes,
            'max_upload_parts' => $this->max_upload_parts,
            'post_max_bytes' => $this->post_max_bytes,
        ];
        if (!$this->apply_sessions_enabled) {
            $capability['reason'] = 'Staged apply sessions are disabled by server configuration.';
            return $capability;
        }
        if ($this->secret === null || $this->secret === '') {
            $capability['reason'] = 'The staged push shared secret is not configured.';
            return $capability;
        }
        if ($this->apply_target_root === null) {
            $capability['reason'] = 'The staged push apply target root is not configured.';
            return $capability;
        }
        if ($this->staging_dir === '' || $this->staging_dir[0] !== '/') {
            $capability['reason'] = 'The staged push session storage must be an absolute directory.';
            return $capability;
        }

        $target = @lstat($this->apply_target_root);
        if (!is_array($target) || (((int) ($target['mode'] ?? 0)) & 0170000) !== 0040000 || is_link($this->apply_target_root)) {
            $capability['reason'] = 'The staged push apply target root is not a real directory.';
            return $capability;
        }
        $storage_probe = $this->staging_dir;
        while (!file_exists($storage_probe) && !is_link($storage_probe)) {
            $parent = dirname($storage_probe);
            if ($parent === $storage_probe) {
                $capability['reason'] = 'Could not locate an existing parent filesystem for staged push storage.';
                return $capability;
            }
            $storage_probe = $parent;
        }
        $storage = @lstat($storage_probe);
        if (!is_array($storage) || (((int) ($storage['mode'] ?? 0)) & 0170000) !== 0040000 || is_link($storage_probe)) {
            $capability['reason'] = 'The staged push storage path has no real directory parent.';
            return $capability;
        }
        if (!isset($target['dev'], $storage['dev']) || (int) $target['dev'] !== (int) $storage['dev']) {
            $capability['reason'] = 'Staged push storage and the apply target root are on different filesystems.';
            return $capability;
        }

        $capability['available'] = true;
        $capability['filesystem_ok'] = true;
        return $capability;
    }

    /** @return array{http_code:int,body:array} */
    public function session_create(array $config, array $headers): array {
        if (($method = $this->require_method($headers, 'POST')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_create')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $this->require_apply_configuration();
            $parameters = $this->request_target_parameters($headers);
            $create_token = $parameters['create_token'] ?? null;
            if (!is_string($create_token) || preg_match('/^[a-f0-9]{32}$/D', $create_token) !== 1) {
                throw new InvalidArgumentException('staged_session_create requires a 32-character lowercase hexadecimal create_token in its signed request target.');
            }
            $this->require_matching_config_parameter($config, 'create_token', $create_token);
            $session_id = substr(hash_hmac('sha256', 'reprint-multipart-push-create-v1:' . $create_token, (string) $this->secret), 0, 32);
            $session = Site_Export_Staged_Apply_Session::create(
                $this->staging_dir,
                (string) $this->apply_target_root,
                $this->apply_protected_paths,
                $session_id
            );
            $status = $session->get_status();
            return [
                'http_code' => 201,
                'body' => [
                    'status' => 'created',
                    'session_id' => $session->get_session_id(),
                    'phase' => $status['phase'],
                    // This bounds one MIME part, not the HTTP entity body.
                    'max_frame_bytes' => $this->max_frame_bytes,
                    'max_upload_parts' => $this->max_upload_parts,
                    'post_max_bytes' => $this->post_max_bytes,
                    'send_next_request' => false,
                ],
            ];
        });
    }

    /**
     * Stream one multipart/mixed request into the session workspace.
     *
     * @param resource|null $input
     * @return array{http_code:int,body:array}
     */
    public function session_upload(array $config, array $headers, $input): array {
        if (($method = $this->require_method($headers, 'POST')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_upload')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers, $input): array {
            if (!is_resource($input)) {
                throw new RuntimeException('Could not open the multipart upload request body.', Site_Export_Staged_Apply_Session::ERROR_RETRYABLE_IO);
            }
            $session = $this->open_session($config, $headers);
            $content_type = $this->header($headers, 'Content-Type');
            if ($content_type === null) {
                throw new InvalidArgumentException('staged_session_upload requires a multipart/mixed Content-Type header.');
            }
            $input_reader = new Site_Export_Multipart_Stream_Input(
                $input,
                Site_Export_Multipart_Stream_Input::boundary_from_content_type($content_type)
            );
            $accepted = [];
            $paused = false;
            $session->accept_upload($input_reader, $this->max_frame_bytes, $this->max_upload_parts);
            try {
                while ($session->next_change()) {
                    $change = $session->get_current_change();
                    if (is_array($change)) {
                        $accepted[] = $change;
                    }
                    // Stop only between complete durable parts. The sender
                    // uses the same cap, so normal requests close here; a
                    // larger client request is deliberately left for its
                    // next request rather than buffering or parsing ahead.
                    if (count($accepted) >= $this->max_upload_parts) {
                        $paused = true;
                        break;
                    }
                }
            } finally {
                $session->finish_upload();
            }
            $status = $session->get_status();
            return [
                'http_code' => 200,
                'body' => [
                    'status' => 'accepted',
                    'session_id' => $session->get_session_id(),
                    'phase' => $status['phase'],
                    'accepted' => $accepted,
                    'send_next_request' => $paused,
                ],
            ];
        });
    }

    /** @return array{http_code:int,body:array} */
    public function session_status(array $config, array $headers): array {
        if (($method = $this->require_method($headers, 'GET')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_status')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $session = $this->open_session($config, $headers);
            $parameters = $this->request_target_parameters($headers);
            $paths = $this->status_paths($parameters);
            $status = $session->get_status($paths);
            $status['status'] = 'ok';
            $status['send_next_request'] = false;
            return ['http_code' => 200, 'body' => $status];
        });
    }

    /** @return array{http_code:int,body:array} */
    public function session_commit(array $config, array $headers): array {
        if (($method = $this->require_method($headers, 'POST')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_commit')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $session = $this->open_session($config, $headers);
            $result = $session->commit($this->max_commit_steps);
            $result['status'] = 'ok';
            return ['http_code' => 200, 'body' => $result];
        });
    }

    /** @return array{http_code:int,body:array} */
    public function session_discard(array $config, array $headers): array {
        if (($method = $this->require_method($headers, 'POST')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_discard')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $session = $this->open_session($config, $headers);
            $session->discard_workspace();
            return [
                'http_code' => 200,
                'body' => [
                    'status' => 'discarded',
                    'session_id' => $this->session_id_from_headers($headers),
                    'send_next_request' => false,
                ],
            ];
        });
    }

    /** @return array{http_code:int,body:array}|null */
    private function require_envelope_auth(array $headers, string $endpoint): ?array {
        if ($this->secret === null || $this->secret === '') {
            return $this->rejected(503, 'not_configured', 'The shared secret is not configured.');
        }
        $method = $headers['REQUEST_METHOD'] ?? null;
        $request_target = $headers['REQUEST_URI'] ?? null;
        if (!is_string($method) || !is_string($request_target) || $request_target === '') {
            return $this->rejected(400, 'invalid_request_target', 'The request method and target are required for envelope authentication.');
        }
        $parameters = $this->request_target_parameters($headers);
        if (($parameters['endpoint'] ?? null) !== $endpoint) {
            return $this->rejected(400, 'invalid_request_target', 'The signed request target does not select ' . $endpoint . '.');
        }
        $error = (new Site_Export_HMAC_Server((string) $this->secret, $this->timestamp_tolerance))
            ->verify_envelope($headers, $method, $request_target);
        return $error === null ? null : $this->rejected(403, 'auth_failed', $error);
    }

    private function require_apply_configuration(): void {
        if (!$this->apply_sessions_enabled || $this->apply_target_root === null) {
            throw new RuntimeException('Server configuration has not enabled staged apply sessions.', 2001);
        }
    }

    private function open_session(array $config, array $headers): Site_Export_Staged_Apply_Session {
        $this->require_apply_configuration();
        $session_id = $this->session_id_from_headers($headers);
        $this->require_matching_config_parameter($config, 'session_id', $session_id);
        return Site_Export_Staged_Apply_Session::open(
            $this->staging_dir,
            (string) $this->apply_target_root,
            $session_id,
            $this->apply_protected_paths
        );
    }

    private function session_id_from_headers(array $headers): string {
        $parameters = $this->request_target_parameters($headers);
        $session_id = $parameters['session_id'] ?? null;
        if (!is_string($session_id) || preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Staged session requests require a 32-character lowercase hexadecimal session_id in the signed request target.');
        }
        return $session_id;
    }

    /** @param array<string,mixed> $parameters @return string[] */
    private function status_paths(array $parameters): array {
        $encoded_paths = [];
        if (isset($parameters['path'])) {
            $encoded_paths[] = $parameters['path'];
        }
        if (isset($parameters['paths'])) {
            $decoded = is_string($parameters['paths']) ? json_decode($parameters['paths'], true) : null;
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('staged_session_status paths must be a JSON array in the signed request target.');
            }
            $encoded_paths = array_merge($encoded_paths, $decoded);
        }
        if (count($encoded_paths) > self::MAX_STATUS_PATHS) {
            throw new InvalidArgumentException('staged_session_status accepts at most ' . self::MAX_STATUS_PATHS . ' paths.');
        }
        $paths = [];
        foreach ($encoded_paths as $encoded_path) {
            if (!is_string($encoded_path)) {
                throw new InvalidArgumentException('staged_session_status path values must be base64 strings.');
            }
            $path = base64_decode($encoded_path, true);
            if ($path === false || $path === '') {
                throw new InvalidArgumentException('staged_session_status path is not valid non-empty base64.');
            }
            $paths[] = $path;
        }
        return $paths;
    }

    /** @param array<string,mixed> $config */
    private function require_matching_config_parameter(array $config, string $name, string $signed_value): void {
        if (array_key_exists($name, $config) && $config[$name] !== $signed_value) {
            throw new InvalidArgumentException('Request parameter ' . $name . ' must match its signed request-target value.');
        }
    }

    /** @return array<string,mixed> */
    private function request_target_parameters(array $headers): array {
        $request_target = $headers['REQUEST_URI'] ?? null;
        if (!is_string($request_target) || $request_target === '') {
            throw new InvalidArgumentException('The request target is required.');
        }
        $query = parse_url($request_target, PHP_URL_QUERY);
        if ($query === false || $query === null) {
            return [];
        }
        $parameters = [];
        parse_str($query, $parameters);
        return $parameters;
    }

    /** @return array{http_code:int,body:array}|null */
    private function require_method(array $headers, string $expected_method): ?array {
        $actual_method = strtoupper((string) ($headers['REQUEST_METHOD'] ?? ''));
        if ($actual_method === $expected_method) {
            return null;
        }
        return $this->rejected(405, 'method_not_allowed', 'Expected ' . $expected_method . '; received ' . ($actual_method === '' ? 'no method' : $actual_method) . '.');
    }

    private function header(array $headers, string $name): ?string {
        $server_name = strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (
                strcasecmp((string) $key, $name) === 0
                || strcasecmp((string) $key, $server_name) === 0
                || strcasecmp((string) $key, 'HTTP_' . $server_name) === 0
            ) {
                return $value;
            }
        }
        return null;
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
                case 2001:
                    return $this->rejected(503, 'apply_not_configured', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_BUSY:
                    return $this->rejected(423, 'busy', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_SESSION_NOT_FOUND:
                    return $this->rejected(404, 'session_not_found', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_COMMIT_REQUIRED:
                    return $this->rejected(409, 'commit_required', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_LIVE_TREE_CHANGED:
                    return $this->rejected(409, 'live_tree_changed', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_INVALID_STATE:
                    return $this->rejected(409, 'invalid_session_state', $exception->getMessage());
                case Site_Export_Staged_Apply_Session::ERROR_RETRYABLE_IO:
                    return $this->rejected(500, 'retryable_io_error', $exception->getMessage());
                default:
                    return $this->rejected(409, 'session_rejected', $exception->getMessage());
            }
        }
    }

    /** @return array{http_code:int,body:array} */
    private function rejected(int $http_code, string $reason, ?string $detail = null): array {
        return [
            'http_code' => $http_code,
            'body' => [
                'status' => 'error',
                'reason' => $reason,
                'detail' => $detail,
                'send_next_request' => false,
            ],
        ];
    }
}
