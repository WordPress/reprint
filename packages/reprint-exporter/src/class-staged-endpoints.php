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
 * Serves the authenticated target-side lifecycle for staged multipart pushes.
 *
 * One instance is configured by the target and exposes five signed operations:
 *
 * - `staged_session_create` derives an idempotent session id from a signed
 *   create token and initializes private storage.
 * - `staged_session_upload` streams many Content-Length MIME parts into that
 *   workspace and reports only bytes which the target durably accepted.
 * - `staged_session_status` reconciles a bounded set of sender paths against
 *   the workspace after a lost or ambiguous upload response.
 * - `staged_session_commit` advances bounded planning, preparation,
 *   live-switch, and cleanup work until `send_next_request` becomes false.
 * - `staged_session_discard` removes abandoned or successfully completed private work
 *   through bounded resumable tombstone cleanup.
 *
 * HMAC authentication covers the HTTP method and exact request target,
 * including endpoint and session parameters. The router must call
 * pre_authenticate_envelope() before opening `php://input`; session_upload()
 * repeats that authentication defensively before constructing the streaming
 * multipart reader. This prevents an unauthenticated request from making the
 * target spend time or storage parsing a large body.
 *
 * Endpoint methods return an HTTP-neutral envelope instead of emitting output:
 *
 *     $result = $endpoints->session_status($route_parameters, $_SERVER);
 *     http_response_code($result['http_code']);
 *     echo json_encode($result['body']);
 *
 * The private workspace, not any sender cursor, is the durable upload source
 * of truth. Upload responses and status probes are built from state already
 * persisted below the target's staging directory. A sender can therefore
 * retry an idempotent create, resume at a target-confirmed file offset, or
 * continue a crash-interrupted commit without asking this class to remember
 * the sender's proposed plan.
 */
final class Site_Export_Staged_Endpoints {

    /**
     * Default maximum Content-Length of one MIME part: 4 MiB.
     *
     * This is a frame/body-piece policy, not the maximum HTTP request body.
     * Many bounded parts may travel in one request up to the remote stack's
     * entity-body limit.
     */
    private const DEFAULT_MAX_FRAME_BYTES = 4194304;

    /**
     * Default number of prepare or live-switch actions performed per commit request.
     *
     * Bounding work lets ordinary PHP HTTP runtimes checkpoint and return
     * before their execution limits while the sender drives later requests.
     */
    private const DEFAULT_COMMIT_STEPS = 8;

    /**
     * Default maximum complete MIME parts accepted from one upload request.
     *
     * The endpoint pauses only between durable parts, never midway through a
     * body, so this cap limits per-request metadata and response cardinality.
     */
    private const DEFAULT_MAX_UPLOAD_PARTS = 128;

    /**
     * Maximum paths whose target-derived state one status request may expose.
     *
     * Status exists to reconcile the first ambiguous sent part, not to list a
     * workspace or mirror the sender's complete plan.
     */
    private const MAX_STATUS_PATHS = 32;

    /**
     * Server-owned private root in which apply-session workspaces are stored.
     *
     * @var string
     */
    private $staging_dir;

    /**
     * Shared HMAC secret, or null when signed endpoints are unavailable.
     *
     * The value is never included in capability or endpoint responses.
     *
     * @var string|null
     */
    private $secret;

    /**
     * Live site root changed by commit, or null until apply is configured.
     *
     * @var string|null
     */
    private $apply_target_root;

    /**
     * Target-relative paths which staged sessions may not replace or traverse.
     *
     * @var string[]
     */
    private $apply_protected_paths;

    /** @var string[] Target-relative plugin/theme containers. */
    private $apply_deployment_roots;

    /** @var bool Whether create, upload, status, commit, and discard are enabled. */
    private $apply_sessions_enabled;

    /** @var int Allowed HMAC timestamp skew in seconds. */
    private $timestamp_tolerance;

    /** @var int Maximum declared body bytes accepted for one MIME part. */
    private $max_frame_bytes;

    /** @var int Maximum deployment actions advanced by one commit request. */
    private $max_commit_steps;

    /** @var int Maximum complete MIME parts accepted from one upload request. */
    private $max_upload_parts;

    /**
     * PHP's parsed post_max_size in bytes, or null when PHP reports no useful cap.
     *
     * This seeds sender request sizing. A front proxy may enforce a smaller
     * invisible limit, which the sender learns from HTTP 413 responses.
     *
     * @var int|null
     */
    private $post_max_bytes;

    /**
     * Configures signed endpoint policy and snapshots PHP's request-body limit.
     *
     * Supported options are `staging_dir`, `secret`, `apply_target_root`,
     * `apply_protected_paths`, `apply_deployment_roots`,
     * `apply_sessions_enabled`, `timestamp_tolerance`, `max_frame_bytes`,
     * `max_commit_steps`, and `max_upload_parts`. Optional values receive the
     * constants documented above; a supplied invalid value throws instead of
     * silently selecting its default.
     *
     * @param array<string,mixed> $options Server-owned configuration.
     *
     * @throws InvalidArgumentException If a supplied option has the wrong type
     *     or a numeric limit is not positive.
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

        $deployment_roots = $options['apply_deployment_roots'] ?? [];
        if (!is_array($deployment_roots)) {
            throw new InvalidArgumentException('Staged session apply_deployment_roots must be an array.');
        }
        $this->apply_deployment_roots = $deployment_roots;

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
     * Authenticates a signed request target before the dispatcher opens its body.
     *
     * Null means authentication succeeded. Missing target configuration may be
     * returned as 503, while every other authentication failure is deliberately
     * collapsed to a generic 403 so the pre-body gate does not disclose HMAC
     * validation details to an unauthenticated peer.
     *
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @param string $expected_endpoint Endpoint value required in the signed URI.
     * @return array{http_code:int,body:array}|null Rejection envelope, or null.
     */
    public function pre_authenticate_envelope(array $headers, string $expected_endpoint): ?array {
        $response = $this->require_envelope_auth($headers, $expected_endpoint);
        if ($response === null || $response['http_code'] === 503) {
            return $response;
        }
        return $this->rejected(403, 'auth_failed', 'Authentication failed.');
    }

    /**
     * Reports whether current target configuration can accept a staged push.
     *
     * The probe exposes request-sizing hints but never the HMAC secret. It does
     * not create storage: for a missing staging directory it walks upward to an
     * existing real parent and compares that parent's device with the live
     * target. create() repeats the authoritative checks when work actually
     * begins, closing the race between capability inspection and use.
     *
     * @return array<string,mixed> Availability, filesystem compatibility,
     *     reason when unavailable, and target request/part limits.
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

    /**
     * Creates or reopens the deterministic session derived from a signed token.
     *
     * Replaying a request after a lost response therefore finds the same
     * workspace instead of orphaning one session and creating another. The
     * session id is an HMAC-derived target value; the sender chooses only the
     * random create token and cannot select an existing workspace directly.
     *
     * @param array<string,mixed> $config Dispatcher parameters, checked against
     *     the same values in the signed request target.
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @return array{http_code:int,body:array} Creation or stable rejection envelope.
     */
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
                $session_id,
                $this->apply_deployment_roots
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
     * Streams one multipart/mixed request into the session workspace.
     *
     * Authentication and session identity are resolved before the body reader
     * is created. The endpoint then drives one complete part at a time and
     * retains only the target-confirmed result records needed for this response.
     * If the configured part-count cap is reached, parsing stops between parts
     * and `send_next_request` tells the sender to open another request. No part
     * is acknowledged before its contents or metadata have been flushed into
     * private storage.
     *
     * A malformed later part rejects the request, but durable state from earlier
     * accepted parts remains discoverable through session_status().
     *
     * @param array<string,mixed> $config Dispatcher parameters.
     * @param array<string,mixed> $headers Request method, URI, HMAC, and Content-Type.
     * @param resource|null $input
     * @return array{http_code:int,body:array} Accepted changes or stable rejection.
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

    /**
     * Reports only workspace-derived state for a bounded requested path set.
     *
     * Paths travel as base64 request-target values because filesystem names are
     * arbitrary bytes while URLs and JSON are text. The response preserves
     * request order and reports complete, partial, or missing state plus the
     * durable accepted byte count. It never accepts a sender offset as evidence.
     *
     * @param array<string,mixed> $config Dispatcher parameters.
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @return array{http_code:int,body:array} Current phase and requested paths.
     */
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

    /**
     * Advances a commit by the configured number of deployment actions.
     *
     * The sender repeats this endpoint while `send_next_request` is true. Each
     * response reflects the durable checkpoint after that bounded slice, so a
     * timeout or process death can safely retry the same signed operation.
     *
     * @param array<string,mixed> $config Dispatcher parameters.
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @return array{http_code:int,body:array} Durable commit progress or rejection.
     */
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

    /**
     * Discards private work before live mutation or after commit has completed.
     *
     * Uploading and preparing sessions can be abandoned because neither has
     * changed the target. A complete session has no remaining recovery role and
     * is removed as successful cleanup. Switching or cleaning sessions return
     * `commit_required`; those sessions must finish commit so maintenance and
     * recovery backups are handled by the checkpointed lifecycle. An active
     * session is renamed to a private tombstone before bounded entry removal;
     * `send_next_request` remains true until that tombstone is gone. Repeating
     * discard after a lost response resumes the same cleanup.
     *
     * @param array<string,mixed> $config Dispatcher parameters.
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @return array{http_code:int,body:array} Discard confirmation or rejection.
     */
    public function session_discard(array $config, array $headers): array {
        if (($method = $this->require_method($headers, 'POST')) !== null) {
            return $method;
        }
        if (($auth = $this->require_envelope_auth($headers, 'staged_session_discard')) !== null) {
            return $auth;
        }
        return $this->session_action(function () use ($config, $headers): array {
            $this->require_apply_configuration();
            $session_id = $this->session_id_from_headers($headers);
            $this->require_matching_config_parameter($config, 'session_id', $session_id);
            $discarded = Site_Export_Staged_Apply_Session::discard(
                $this->staging_dir,
                (string) $this->apply_target_root,
                $session_id,
                $this->apply_protected_paths,
                $this->apply_deployment_roots
            );
            return [
                'http_code' => 200,
                'body' => [
                    'status' => $discarded ? 'discarded' : 'discarding',
                    'session_id' => $session_id,
                    'send_next_request' => !$discarded,
                ],
            ];
        });
    }

    /**
     * Verifies that the HTTP method, endpoint, and parameters were signed as
     * the exact request target before any upload body is opened.
     *
     * @param array<string,mixed> $headers Request method, URI, and HMAC headers.
     * @param string $endpoint Exact endpoint query value expected by the caller.
     * @return array{http_code:int,body:array}|null Rejection envelope, or null.
     */
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

    /**
     * Requires the target root and staged-apply switch to be configured.
     *
     * Secret availability is checked separately by envelope authentication so
     * callers receive the protocol's `not_configured` reason at that boundary.
     *
     * @throws RuntimeException With endpoint-internal code 2001 when unavailable.
     */
    private function require_apply_configuration(): void {
        if (!$this->apply_sessions_enabled || $this->apply_target_root === null) {
            throw new RuntimeException('Server configuration has not enabled staged apply sessions.', 2001);
        }
    }

    /**
     * Opens the URI's signed session only when any dispatcher copy agrees.
     *
     * Some routers pass decoded query values separately from REQUEST_URI. The
     * signed URI remains authoritative; a differing dispatcher value is
     * rejected before opening private state.
     *
     * @param array<string,mixed> $config Dispatcher parameters.
     * @param array<string,mixed> $headers Request URI and authentication headers.
     * @return Site_Export_Staged_Apply_Session Validated existing session.
     */
    private function open_session(array $config, array $headers): Site_Export_Staged_Apply_Session {
        $this->require_apply_configuration();
        $session_id = $this->session_id_from_headers($headers);
        $this->require_matching_config_parameter($config, 'session_id', $session_id);
        return Site_Export_Staged_Apply_Session::open(
            $this->staging_dir,
            (string) $this->apply_target_root,
            $session_id,
            $this->apply_protected_paths,
            $this->apply_deployment_roots
        );
    }

    /**
     * Returns the validated session id carried by the signed request target.
     *
     * @param array<string,mixed> $headers Request data containing REQUEST_URI.
     * @return string 32-character lowercase hexadecimal target session id.
     *
     * @throws InvalidArgumentException If the URI omits or malforms session_id.
     */
    private function session_id_from_headers(array $headers): string {
        $parameters = $this->request_target_parameters($headers);
        $session_id = $parameters['session_id'] ?? null;
        if (!is_string($session_id) || preg_match('/^[a-f0-9]{32}$/D', $session_id) !== 1) {
            throw new InvalidArgumentException('Staged session requests require a 32-character lowercase hexadecimal session_id in the signed request target.');
        }
        return $session_id;
    }

    /**
     * Decodes the bounded path set whose workspace state may be exposed.
     *
     * A single `path` and a JSON `paths` array may be combined. Values remain
     * base64 in the signed URL and are decoded only after count and type checks.
     *
     * @param array<string,mixed> $parameters
     * @return string[] Raw target-relative path byte strings in request order.
     */
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

    /**
     * Rejects an unsigned dispatcher override of a signed URI parameter.
     *
     * @param array<string,mixed> $config Dispatcher-decoded parameters.
     * @param string $name Parameter name being compared.
     * @param string $signed_value Authoritative value parsed from REQUEST_URI.
     */
    private function require_matching_config_parameter(array $config, string $name, string $signed_value): void {
        if (array_key_exists($name, $config) && $config[$name] !== $signed_value) {
            throw new InvalidArgumentException('Request parameter ' . $name . ' must match its signed request-target value.');
        }
    }

    /**
     * Parses query parameters from the exact request target used for HMAC.
     *
     * @param array<string,mixed> $headers Request data containing REQUEST_URI.
     * @return array<string,mixed> PHP-decoded query parameters, or an empty array.
     *
     * @throws InvalidArgumentException If no request target is available.
     */
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

    /**
     * Returns a method-not-allowed envelope unless the exact method matches.
     *
     * @param array<string,mixed> $headers Request data containing REQUEST_METHOD.
     * @param string $expected_method Uppercase method required by the endpoint.
     * @return array{http_code:int,body:array}|null Rejection envelope, or null.
     */
    private function require_method(array $headers, string $expected_method): ?array {
        $actual_method = strtoupper((string) ($headers['REQUEST_METHOD'] ?? ''));
        if ($actual_method === $expected_method) {
            return null;
        }
        return $this->rejected(405, 'method_not_allowed', 'Expected ' . $expected_method . '; received ' . ($actual_method === '' ? 'no method' : $actual_method) . '.');
    }

    /**
     * Finds a header across raw, normalized, and `HTTP_` SAPI key forms.
     *
     * export.php is invoked by more than one server adapter. Normalizing at this
     * boundary keeps multipart validation independent of each SAPI's header-key
     * convention.
     *
     * @param array<string,mixed> $headers Server and request headers.
     * @param string $name Canonical HTTP header name.
     * @return string|null Header value when found.
     */
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
     * Converts session exceptions into the protocol's stable HTTP reasons.
     *
     * Endpoint implementations throw precise domain exceptions; this is the
     * single mapping to public status codes and machine-readable reasons. That
     * keeps upload, status, commit, and discard error classification aligned.
     *
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

    /**
     * Builds the common terminal rejection shape used by every endpoint.
     *
     * Rejections always set `send_next_request` false. Recoverable classifications
     * such as `busy` are interpreted by the sender from `reason`, not by asking
     * the target to prescribe retry policy.
     *
     * @param int $http_code HTTP status emitted by the router.
     * @param string $reason Stable machine-readable protocol reason.
     * @param string|null $detail Human-readable condition, when available.
     * @return array{http_code:int,body:array} Router response envelope.
     */
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
