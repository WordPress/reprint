<?php

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_Push_Configuration_Exception', false)) {
    require_once __DIR__ . '/class-push-configuration-exception.php';
}

/**
 * HTTP dispatcher for the Site Export API.
 */
final class Site_Export_HTTP_Server {

    private const PUSH_ENDPOINT_METHODS = [
        'push_create' => 'create',
        'push_upload' => 'upload',
        'push_status' => 'status',
        'push_commit' => 'commit',
        'push_remove' => 'remove',
    ];

    /** @var array<string, callable> */
    private $handlers;

    /** @var callable */
    private $budget_factory;

    /** @var callable */
    private $body_reader;

    /** @var string */
    private $cursor_header_name;

    /** @var string|null */
    private $default_directory;

    /** @var Site_Export_Push_Endpoints|null */
    private $push_endpoints;

    public function __construct(array $options = []) {
        $this->budget_factory = $options['budget_factory'] ?? [$this, 'default_budget_factory'];
        $this->body_reader = $options['body_reader'] ?? static function (): string {
            $body = file_get_contents('php://input');
            return $body === false ? '' : $body;
        };
        $this->cursor_header_name = $options['cursor_header_name'] ?? 'HTTP_X_EXPORT_CURSOR';
        $this->default_directory = $options['default_directory'] ?? null;
        $this->push_endpoints = null;
        if (array_key_exists('push', $options)) {
            try {
                if (!is_array($options['push'])) {
                    throw new InvalidArgumentException('The push HTTP server option must be an array.');
                }
                if (!class_exists('Site_Export_Push_Endpoints', false)) {
                    require_once __DIR__ . '/class-push-endpoints.php';
                }
                $this->push_endpoints = new Site_Export_Push_Endpoints($options['push']);
            } catch (InvalidArgumentException $exception) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This rethrows a trusted configuration error; it does not render output.
                throw new Site_Export_Push_Configuration_Exception(
                    $exception->getMessage(),
                    0,
                    $exception
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        $this->handlers = $options['handlers'] ?? $this->default_handlers();
    }

    public function handle_request(array $request = []): void {
        $server = $request['server'] ?? $_SERVER;
        $get = $request['get'] ?? $_GET;
        $post = $request['post'] ?? $_POST;
        // Push parameters travel in the signed query string. push_upload streams
        // php://input itself; reading JSON for any push endpoint would either
        // buffer an upload or let a control request buffer an unused body.
        $endpoint = $get['endpoint'] ?? null;
        $uses_push_request_contract = is_string($endpoint) && strpos($endpoint, 'push_') === 0;
        $body = '';
        if ($uses_push_request_contract) {
            // $_POST must not override the signed query endpoint after the
            // router has applied push authorization to that query endpoint.
            $post = [];
        } else {
            $body = array_key_exists('body', $request)
                ? (string) $request['body']
                : ( $this->is_json_content_type($server) ? call_user_func($this->body_reader) : '' );
        }
        $config = $request['config'] ?? $this->parse_http_config(
            $get,
            $post,
            $server,
            $body
        );
        $config = $this->normalize_config($config, $server);
        $this->dispatch($config);
    }

    /**
     * Emits CORS headers and terminates OPTIONS preflight requests.
     *
     * Must be called BEFORE authentication runs — browsers send
     * preflight OPTIONS without credentials, so the consumer must not
     * require auth headers before this check passes.
     *
     * A wildcard origin ('*') is safe when authentication happens
     * out-of-band (e.g., HMAC with a pre-shared secret) — an attacker
     * without the secret cannot export anything regardless of origin.
     *
     * For OPTIONS requests this terminates the process. For all other
     * methods it just emits the headers and returns so the caller can
     * continue with authentication and dispatch.
     *
     * @param string|true $origin The Access-Control-Allow-Origin value,
     *     or true as a shorthand for '*'.
     * @param string $allow_headers The Access-Control-Allow-Headers value.
     *     Defaults to '*' to permit all headers. Pass a comma-separated
     *     list to restrict (e.g. 'Content-Type, X-Auth-Signature').
     * @param array<string, mixed> $server Request server array (defaults to $_SERVER).
     * @param array<string, callable>|null $io Optional overrides for
     *     'header' (emitter) and 'exit' (preflight terminator). Used
     *     only by tests.
     */
    public static function handle_cors_headers_and_terminate_on_options(
        $origin = '*',
        string $allow_headers = '*',
        array $server = [],
        ?array $io = null
    ): void {
        if ($origin === true) {
            $origin = '*';
        }
        if (!is_string($origin) || $origin === '') {
            throw new InvalidArgumentException(
                'CORS origin must be a non-empty string or true'
            );
        }

        $emit_header = ($io['header'] ?? null) ?? static function (string $h): void {
            header($h);
        };
        $terminate = ($io['exit'] ?? null) ?? static function (): void {
            exit;
        };

        $emit_header('Access-Control-Allow-Origin: ' . $origin);
        $emit_header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        $emit_header('Access-Control-Allow-Headers: ' . $allow_headers);

        $request_server = $server === [] ? $_SERVER : $server;
        $method = isset($request_server['REQUEST_METHOD']) ? (string) $request_server['REQUEST_METHOD'] : '';
        if (strtoupper($method) !== 'OPTIONS') {
            return;
        }

        $emit_header('Allow: GET, POST, OPTIONS');
        $terminate();
    }

    /**
     * One-call convenience entry point: loads export.php, constructs
     * the server, and dispatches the current request.
     *
     * Equivalent to:
     *
     *     require_once __DIR__ . '/export.php';
     *     $server = new Site_Export_HTTP_Server($options);
     *     $server->handle_request();
     *
     * export.php is only required once. Callers that need to run CORS
     * or their own authentication must do that before calling this method.
     *
     * @param array<string, mixed> $options Forwarded to the constructor.
     * @throws Exception If request parsing or endpoint dispatch fails.
     */
    public static function serve(array $options = []): void {
        // endpoint_preflight is defined by export.php — use it as a
        // cheap sentinel to detect whether the runtime is already
        // loaded. require_once would be safe either way, but this
        // avoids re-running the stat() on hot paths.
        if (!function_exists('endpoint_preflight')) {
            require_once __DIR__ . '/export.php';
        }

        $server = new self($options);
        $server->handle_request();
    }

    /**
     * Authenticates and dispatches one push request without loading WordPress.
     *
     * Session creation uses the connection secret supplied by server
     * configuration. Every later request uses the secret stored in that push
     * session's private metadata. A created session therefore remains usable
     * when WordPress cannot boot or new push sessions have been disabled.
     *
     * @param array $options {
     *     Trusted push-route configuration.
     *
     *     @type callable $authenticate Optional push_create authentication.
     *         When absent, connection_secret authenticates the request.
     *     @type string $connection_secret Optional secret required by
     *         push_create when authenticate is absent.
     *     @type string $reprint_directory Private reprint directory. Defaults
     *         to a document-root-specific sibling.
     *     @type string $docroot Required document-root directory.
     *     @type list<string> $excluded_paths Document-root-relative paths which
     *         push must preserve. Default empty.
     *     @type int|float|string $maximum_part_bytes Maximum Content-Length
     *         accepted for one multipart part. Default 4 MiB.
     *     @type int|float|string $maximum_commit_entries Maximum entries one
     *         commit request may process. Default 256.
     * }
     * @phpstan-param array{authenticate?:mixed,connection_secret?:mixed,reprint_directory?:mixed,docroot?:mixed,excluded_paths?:mixed,maximum_part_bytes?:mixed,maximum_commit_entries?:mixed} $options
     */
    public static function serve_push(array $options): void {
        if (!class_exists('Site_Export_Push_Endpoints', false)) {
            require_once __DIR__ . '/class-push-endpoints.php';
        }

        // The HMAC authenticates the exact raw request target without
        // loading the WordPress nonce, slashing, or sanitization helpers.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $endpoint = $_GET['endpoint'] ?? null;
        if (!is_string($endpoint) || !self::is_push_endpoint($endpoint)) {
            Site_Export_Push_Endpoints::emit_response(404, [
                'status' => 'rejected',
                'reason' => 'invalid_request',
                'detail' => 'The push route accepts push_create, push_upload, push_status, push_commit, and push_remove.',
            ]);
            return;
        }

        $connection_secret = $options['connection_secret'] ?? null;
        $reprint_directory = $options['reprint_directory'] ?? null;
        $docroot = $options['docroot'] ?? null;
        if (!is_string($docroot) || $docroot === '') {
            Site_Export_Push_Endpoints::emit_response(503, [
                'status' => 'rejected',
                'reason' => 'not_configured',
                'detail' => 'The push route requires docroot to name an existing directory; observed ' . json_encode($docroot) . '.',
            ]);
            return;
        }
        $canonical_docroot = realpath($docroot);
        if ($canonical_docroot === false || !is_dir($canonical_docroot)) {
            Site_Export_Push_Endpoints::emit_response(503, [
                'status' => 'rejected',
                'reason' => 'not_configured',
                'detail' => 'The push route requires docroot to name an existing directory; observed ' . json_encode($docroot) . '.',
            ]);
            return;
        }
        $docroot = $canonical_docroot === '/' ? '/' : rtrim($canonical_docroot, '/\\');
        if ($reprint_directory === null) {
            $reprint_directory = self::default_reprint_directory($docroot);
        }
        if (!is_string($reprint_directory) || $reprint_directory === '') {
            Site_Export_Push_Endpoints::emit_response(503, [
                'status' => 'rejected',
                'reason' => 'not_configured',
                'detail' => 'The push route requires reprint_directory to be a non-empty string; observed ' . json_encode($reprint_directory) . '.',
            ]);
            return;
        }
        $options['docroot'] = $docroot;
        $options['reprint_directory'] = $reprint_directory;
        if (!array_key_exists('excluded_paths', $options)) {
            $options['excluded_paths'] = [];
        }

        $request_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
        $request_target = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $authentication_secret = null;
        if ($endpoint === 'push_create') {
            $authenticate = $options['authenticate'] ?? null;
            if ($authenticate !== null) {
                if (!is_callable($authenticate)) {
                    Site_Export_Push_Endpoints::emit_response(503, [
                        'status' => 'rejected',
                        'reason' => 'not_configured',
                        'detail' => 'The push route authenticate option must be callable.',
                    ]);
                    return;
                }
                try {
                    call_user_func($authenticate);
                } catch (Throwable $exception) {
                    Site_Export_Push_Endpoints::emit_failure($exception);
                    return;
                }
            } else {
                if (!is_string($connection_secret) || $connection_secret === '') {
                    Site_Export_Push_Endpoints::emit_response(403, [
                        'status' => 'rejected',
                        'reason' => 'push_disabled',
                        'detail' => 'Push access is disabled for new push sessions.',
                    ]);
                    return;
                }
                $authentication_secret = $connection_secret;
            }

            $push_options = $options;
            unset($push_options['authenticate'], $push_options['connection_secret']);
        } else {
            try {
                $push_session_id = Site_Export_Push_Endpoints::read_push_session_id($_GET);
            } catch (Throwable $exception) {
                Site_Export_Push_Endpoints::emit_failure($exception);
                return;
            }

            try {
                $push_session_configuration = Site_Export_Push_Session::load_push_session_configuration(
                    $reprint_directory,
                    $docroot,
                    $push_session_id
                );
            } catch (Throwable $exception) {
                if (
                    $endpoint === 'push_remove'
                    && $exception instanceof Site_Export_Push_Exception
                    && $exception->get_error_code() === Site_Export_Push_Session::ERROR_PUSH_NOT_FOUND
                ) {
                    try {
                        // The final bounded remove deletes its authentication state.
                        // A lost response may therefore repeat remove without a secret.
                        // Only an unfinished tombstone needs another cleanup step.
                        $removal_tombstone = rtrim($reprint_directory, '/\\')
                            . '/.reprint/push/.removing-' . $push_session_id;
                        $removed = true;
                        if (is_dir($removal_tombstone)) {
                            $removed = Site_Export_Push_Session::remove(
                                $reprint_directory,
                                $docroot,
                                $push_session_id,
                                []
                            );
                        }
                        Site_Export_Push_Endpoints::emit_response(200, [
                            'status' => 'accepted',
                            'push_session_id' => $push_session_id,
                            'removed' => $removed,
                        ]);
                    } catch (Throwable $remove_exception) {
                        Site_Export_Push_Endpoints::emit_failure($remove_exception);
                    }
                    return;
                }
                Site_Export_Push_Endpoints::emit_failure($exception);
                return;
            }

            $authentication_secret = $push_session_configuration['push_session_secret'];
            $push_options = [
                'reprint_directory' => $reprint_directory,
                'docroot' => $docroot,
                'excluded_paths' => $push_session_configuration['excluded_paths'],
                'maximum_part_bytes' => $push_session_configuration['maximum_part_bytes'],
                'maximum_commit_entries' => $push_session_configuration['maximum_commit_entries'],
                'expected_push_session_secret' => $push_session_configuration['push_session_secret'],
            ];
        }

        if ($authentication_secret !== null) {
            $authentication_error = ( new Site_Export_HMAC_Server($authentication_secret) )->verify_envelope(
                $_SERVER,
                $request_method,
                $request_target
            );
            if ($authentication_error !== null) {
                Site_Export_Push_Endpoints::emit_response(403, [
                    'status' => 'rejected',
                    'reason' => 'auth_failed',
                    'detail' => $authentication_error,
                ]);
                return;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

        try {
            self::serve(['push' => $push_options]);
        } catch (Site_Export_Push_Configuration_Exception $exception) {
            Site_Export_Push_Endpoints::emit_response(503, [
                'status' => 'rejected',
                'reason' => 'not_configured',
                'detail' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Site_Export_Push_Endpoints::emit_failure($exception);
        }
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function parse_http_config(array $get = [], array $post = [], array $server = [], string $body = ''): array {
        $config = [];
        $params = array_merge($get, $post);

        $content_type = $server['CONTENT_TYPE'] ?? '';
        $content_type_main = strtolower(trim((string) strtok((string) $content_type, ';')));
        if ($content_type_main === 'application/json' && $body !== '') {
            $json_data = json_decode($body, true);
            if (is_array($json_data)) {
                $params = array_merge($json_data, $params);
            }
        }

        foreach ($params as $key => $value) {
            $key = str_replace('-', '_', (string) $key);

            if (
                in_array($key, [
                    'max_execution_time',
                    'min_ctime',
                    'chunk_size',
                    'fragments_per_batch',
                    'batch_size',
                    'db_query_time_limit',
                    'tables_per_batch',
                ], true)
            ) {
                $value = (int) $value;
            } elseif (in_array($key, ['memory_threshold'], true)) {
                $value = (float) $value;
            } elseif (in_array($key, ['create_table_query', 'db_unbuffered', 'follow_symlinks'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($key === 'paths' && is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function normalize_config(array $config, array $server = []): array {
        if (
            $this->default_directory !== null &&
            !isset($config['directory'])
        ) {
            $config['directory'] = $this->default_directory;
        }

        if (!isset($config['cursor']) && isset($server[$this->cursor_header_name])) {
            $config['cursor'] = $server[$this->cursor_header_name];
        }

        if (isset($config['cursor']) && $config['cursor'] !== '' && $config['cursor'] !== null) {
            $config['cursor'] = $this->decode_cursor((string) $config['cursor']);
        }

        $endpoint = $config['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            throw new InvalidArgumentException(
                "endpoint parameter is required. Valid endpoints: " . $this->get_valid_endpoints_message()
            );
        }

        return $config;
    }

    public function decode_cursor(string $cursor_b64): string {
        $cursor_json = base64_decode($cursor_b64, true);
        if ($cursor_json === false) {
            throw new InvalidArgumentException(
                'Cursor must be base64-encoded. Received invalid base64: ' . substr($cursor_b64, 0, 50)
            );
        }

        $cursor_data = json_decode($cursor_json, true);
        if ($cursor_data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Cursor must be valid JSON after base64 decoding. JSON error: ' . json_last_error_msg()
            );
        }

        return $cursor_json;
    }

    /**
     * @param array<string, mixed> $config
     * @return mixed
     */
    public function create_resource_budget(array $config) {
        return call_user_func($this->budget_factory, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @param mixed $budget
     */
    public function dispatch(array $config, $budget = null): void {
        $endpoint = $config['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            throw new InvalidArgumentException(
                "endpoint parameter is required. Valid endpoints: " . $this->get_valid_endpoints_message()
            );
        }

        if (!isset($this->handlers[$endpoint])) {
            throw new InvalidArgumentException(
                "Invalid endpoint: '{$endpoint}'. Valid endpoints: " . $this->get_valid_endpoints_message()
            );
        }

        $handler = $this->handlers[$endpoint];
        if ($endpoint === 'preflight' || self::is_push_endpoint($endpoint)) {
            call_user_func($handler, $config);
            return;
        }

        if ($budget === null) {
            $budget = $this->create_resource_budget($config);
        }

        call_user_func($handler, $config, $budget);
    }

    public static function is_push_endpoint(string $endpoint): bool {
        return isset(self::PUSH_ENDPOINT_METHODS[$endpoint]);
    }

    /** Returns the private sibling directory used by the bundled push route. */
    public static function default_reprint_directory(string $docroot): string {
        return dirname($docroot) . '/.reprint-' . substr(hash('sha256', $docroot), 0, 12);
    }

    /** Loads the connection secret from a private PHP configuration file. */
    public static function load_push_connection_secret(string $configuration_path): ?string {
        if (!is_file($configuration_path) || is_link($configuration_path)) {
            return null;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configuration_path, true);
        }
        ob_start();
        try {
            $configuration = ( static function (string $path) {
                return include $path;
            } )($configuration_path);
        } catch (Throwable $throwable) {
            ob_end_clean();
            return null;
        }
        $output = ob_get_clean();
        if (
            $output !== ''
            || !is_array($configuration)
            || !is_string($configuration['connection_secret'] ?? null)
            || $configuration['connection_secret'] === ''
        ) {
            return null;
        }
        return $configuration['connection_secret'];
    }

    /** @return array<string, callable> */
    private function default_handlers(): array {
        $handlers = [
            'file_index' => 'endpoint_file_index',
            'file_fetch' => 'endpoint_file_fetch',
            'sql_chunk' => 'endpoint_sql_chunk',
            'db_index' => 'endpoint_db_index',
            'preflight' => 'endpoint_preflight',
        ];
        if ($this->push_endpoints !== null) {
            foreach (self::PUSH_ENDPOINT_METHODS as $endpoint => $method) {
                $handlers[$endpoint] = [$this->push_endpoints, $method];
            }
        }
        return $handlers;
    }

    private function is_json_content_type(array $server): bool {
        $content_type = (string) ( $server['CONTENT_TYPE'] ?? '' );
        return strtolower(trim( (string) strtok($content_type, ';'))) === 'application/json';
    }

    /**
     * @param array<string, mixed> $config
     * @return mixed
     */
    private function default_budget_factory(array $config) {
        $max_execution_time = require_int_range(
            'max_execution_time',
            (int) ($config['max_execution_time'] ?? 5),
            1,
            60
        );
        $memory_threshold = require_float_range(
            'memory_threshold',
            (float) ($config['memory_threshold'] ?? 0.8),
            0.1,
            0.95
        );

        $memory_limit = ini_get('memory_limit');
        $max_memory = $memory_limit === '-1' ? PHP_INT_MAX : parse_size((string) $memory_limit);

        return new ResourceBudget(
            microtime(true),
            $max_execution_time,
            $max_memory,
            $memory_threshold
        );
    }

    private function get_valid_endpoints_message(): string {
        return "'" . implode("', '", array_keys($this->handlers)) . "'";
    }
}
