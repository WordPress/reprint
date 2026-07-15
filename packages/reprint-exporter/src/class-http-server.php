<?php

use function WordPress\Reprint\Exporter\parse_size;

if (!class_exists('Site_Export_Multipart_Processor', false)) {
    require_once __DIR__ . '/class-multipart-processor.php';
}

/**
 * Parses and dispatches HTTP requests for the Site Export API.
 *
 * The server translates query, form, and optional JSON parameters into one
 * normalized endpoint configuration, supplies resource budgets to ordinary
 * export handlers, and invokes the selected handler. Callers may replace the
 * handler map, budget factory, and body reader to embed the API in another
 * runtime without changing endpoint functions.
 *
 * Staged push adds a stricter path through the same dispatcher. Its endpoint,
 * session id, and control parameters live in the request URI because envelope
 * HMAC authenticates the exact method and target. handle_request() recognizes
 * those routes and authenticates them before invoking the configured body
 * reader. The upload handler alone opens `php://input` as a stream; staged
 * control routes intentionally have no parsed JSON body.
 *
 * Example:
 *
 *     $server = new Site_Export_HTTP_Server([
 *         'default_directory' => '/srv/site',
 *         'staged' => [
 *             'staging_dir' => '/srv/reprint-private',
 *             'secret' => getenv('REPRINT_PUSH_SECRET'),
 *             'apply_target_root' => '/srv/site',
 *         ],
 *     ]);
 *     $server->handle_request();
 *
 * Browser CORS handling and any outer authentication which is not part of the
 * staged envelope remain the embedding caller's responsibility and should run
 * before handle_request().
 */
final class Site_Export_HTTP_Server {

    /**
     * Envelope-authenticated routes whose complete parameters live in the URI.
     *
     * The list is shared by body-parsing avoidance, the authentication gate,
     * and export.php's request routing. Keeping one registry prevents a newly
     * added staged route from being authenticated after its body is consumed.
     *
     * @var string[]
     */
    public const STAGED_SESSION_ENDPOINTS = [
        'staged_session_create',
        'staged_session_upload',
        'staged_session_status',
        'staged_session_commit',
        'staged_session_discard',
    ];

    /**
     * Endpoint name to dispatcher callback, including configured staged routes.
     *
     * @var array<string, callable>
     */
    private $handlers;

    /** @var callable Creates a ResourceBudget for ordinary bounded export work. */
    private $budget_factory;

    /**
     * Reads a complete non-streaming request body for JSON configuration.
     *
     * Staged routes bypass this callback so authentication precedes body access
     * and multipart upload retains ownership of its raw stream.
     *
     * @var callable
     */
    private $body_reader;

    /** @var string $_SERVER key from which a pull cursor may be normalized. */
    private $cursor_header_name;

    /** @var string|null Directory inserted when an ordinary request omits one. */
    private $default_directory;

    /** @var string|null Server-owned directory used for staged push storage. */
    private $staging_dir;

    /**
     * Configured staged target endpoints, or null when push is unavailable.
     *
     * The same instance serves preflight capability, pre-body authentication,
     * and route callbacks so server policy cannot drift within one request.
     *
     * @var Site_Export_Staged_Endpoints|null
     */
    private $staged_endpoints;

    /**
     * Endpoints which own their own bounded lifecycle and receive no pull budget.
     *
     * @var string[]
     */
    private $no_budget_endpoints = ['preflight'];

    /**
     * Configures request parsing, dispatch, and optional staged push routes.
     *
     * `handlers` replaces the ordinary default map. `budget_factory`,
     * `body_reader`, `cursor_header_name`, and `default_directory` customize
     * embedding behavior. A `staged` array constructs one
     * Site_Export_Staged_Endpoints and registers only staged route names which
     * an explicit handler did not already claim.
     *
     * @param array<string,mixed> $options Dispatcher and staged-target options.
     */
    public function __construct(array $options = []) {
        $this->handlers = $options['handlers'] ?? $this->default_handlers();
        $this->budget_factory = $options['budget_factory'] ?? [$this, 'default_budget_factory'];
        $this->body_reader = $options['body_reader'] ?? static function (): string {
            $body = file_get_contents('php://input');
            return $body === false ? '' : $body;
        };
        $this->cursor_header_name = $options['cursor_header_name'] ?? 'HTTP_X_EXPORT_CURSOR';
        $this->default_directory = $options['default_directory'] ?? null;
        $this->staging_dir = null;
        $this->staged_endpoints = null;

        if (isset($options['staged']) && is_array($options['staged'])) {
            if (isset($options['staged']['staging_dir']) && is_string($options['staged']['staging_dir'])) {
                $this->staging_dir = $options['staged']['staging_dir'];
            }
            $this->staged_endpoints = new Site_Export_Staged_Endpoints($options['staged']);
            $this->register_staged_handlers($this->staged_endpoints);
        }
    }

    /**
     * Authenticates, parses, normalizes, and dispatches one HTTP request.
     *
     * With no overrides, request data comes from $_SERVER, $_GET, $_POST, and
     * the configured body reader. Tests and embedding runtimes may supply those
     * arrays through $request. A staged query is authenticated before body
     * selection. When staged push is configured, preflight replaces any client
     * value with server-derived staged capability.
     *
     * @param array<string,mixed> $request Optional server/get/post/body/config overrides.
     */
    public function handle_request(array $request = []): void {
        $server = $request['server'] ?? $_SERVER;
        $get = $request['get'] ?? $_GET;
        $post = $request['post'] ?? $_POST;
        if (!$this->pre_authenticate_staged_query($get, $server)) {
            return;
        }
        if (array_key_exists('body', $request)) {
            $body = (string) $request['body'];
        } else {
            $endpoint = (string) ( $get['endpoint'] ?? $post['endpoint'] ?? '' );
            // Session routes authenticate their signed request target before
            // any untrusted body is opened. Upload owns its raw stream; the
            // control routes intentionally have no JSON request body.
            $body = !self::is_staged_session_endpoint($endpoint) && $this->is_json_content_type($server)
                ? call_user_func($this->body_reader)
                : '';
        }
        $config = $request['config'] ?? $this->parse_http_config(
            $get,
            $post,
            $server,
            $body
        );
        $config = $this->normalize_config($config, $server);
        if (($config['endpoint'] ?? null) === 'preflight' && $this->staged_endpoints !== null) {
            // This is server-derived configuration, not a client option. The
            // report lets an operator see why a push would be refused before
            // it creates its first private session.
            $config['staged_push'] = $this->staged_endpoints->get_preflight_capability();
        }
        $this->dispatch($config);
    }

    /**
     * Indicates whether an endpoint uses staged envelope authentication.
     *
     * @param string $endpoint Normalized endpoint selector.
     * @return bool True when the endpoint belongs to STAGED_SESSION_ENDPOINTS.
     */
    public static function is_staged_session_endpoint(string $endpoint): bool {
        return in_array($endpoint, self::STAGED_SESSION_ENDPOINTS, true);
    }

    /**
     * Keeps authentication ahead of body parsing for every session route.
     *
     * A session endpoint is valid only when it appears in the query string:
     * the endpoint selector is part of the request target the envelope signs.
     * Returning false means a JSON error has already been emitted.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     * @return bool True when normal parsing may continue; false after rejection.
     */
    private function pre_authenticate_staged_query(array $get, array $server): bool {
        $endpoint = $get['endpoint'] ?? null;
        if (!is_string($endpoint) || !self::is_staged_session_endpoint($endpoint)) {
            return true;
        }
        if ($this->staged_endpoints === null) {
            self::emit_json_response([
                'http_code' => 404,
                'body' => [
                    'status' => 'error',
                    'reason' => 'staged_sessions_not_configured',
                    'detail' => 'Staged session endpoints are not configured on this server.',
                    'send_next_request' => false,
                ],
            ]);
            return false;
        }
        $response = $this->staged_endpoints->pre_authenticate_envelope($server, $endpoint);
        if ($response === null) {
            return true;
        }
        self::emit_json_response($response);
        return false;
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
     * Merges HTTP parameters into the endpoint configuration shape.
     *
     * JSON object members are the lowest-precedence input; query values and
     * then form values override them. Hyphenated names become underscore names
     * before the known numeric, boolean, and `paths` values are normalized.
     * Invalid or non-object JSON contributes no parameters, leaving endpoint
     * validation to normalize_config() and individual handlers.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param string $body Complete body supplied for an application/json request.
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
     * Adds server defaults and validates configuration shared by every endpoint.
     *
     * A cursor in the configured request header is used only when no parameter
     * cursor exists. Non-empty cursors are strict base64-encoded JSON and are
     * replaced with their decoded JSON text for endpoint handlers. This method
     * requires an endpoint name but dispatch() remains responsible for proving
     * that the configured handler map contains it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException If the endpoint or cursor is malformed.
     */
    public function normalize_config(array $config, array $server = []): array {
        unset($config['storage_path'], $config['staging_dir'], $config['excluded_staging_root']);
        if (
            $this->staging_dir !== null &&
            in_array($config['endpoint'] ?? null, ['file_index', 'file_fetch'], true)
        ) {
            $config['excluded_staging_root'] = $this->staging_dir;
        }

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

    /**
     * Encodes one opaque response cursor without exceeding a multipart header line.
     *
     * Importers return this value unchanged. Every cursor uses gzip followed by
     * base64, so ordinary in-flight SQL rows fit the strict multipart grammar
     * without selecting between two output representations. decode_cursor()
     * continues to accept historical uncompressed cursors.
     *
     * @param string $cursor_json Endpoint-specific cursor JSON.
     * @return string Base64 transport value for X-Cursor.
     *
     * @throws RuntimeException If compression fails or the encoded cursor
     *     cannot fit.
     */
    public static function encode_cursor(string $cursor_json): string {
        $maximum_value_bytes = Site_Export_Multipart_Processor::MAX_HEADER_LINE_BYTES - strlen('X-Cursor: ');
        $compressed_cursor = gzencode($cursor_json);
        if ($compressed_cursor === false) {
            throw new RuntimeException('Failed to compress the response cursor for its multipart header.');
        }
        $cursor_b64 = base64_encode($compressed_cursor);
        if (strlen($cursor_b64) > $maximum_value_bytes) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Byte counts are CLI/API diagnostics, never HTML output.
            throw new RuntimeException(
                'The encoded response cursor requires ' . strlen($cursor_b64) . ' bytes, but X-Cursor permits at most '
                . $maximum_value_bytes . ' bytes.'
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $cursor_b64;
    }

    /**
     * Decodes one transport cursor while verifying that it contains JSON.
     *
     * The JSON text, rather than its decoded PHP value, is returned because
     * endpoint-specific cursor readers own its schema. Strict base64 decoding
     * rejects corrupt transport data before an endpoint attempts that parse.
     *
     * @param string $cursor_b64 Base64-encoded JSON cursor from a parameter or header.
     * @return string Decoded JSON text.
     *
     * @throws InvalidArgumentException If either encoding layer is invalid.
     */
    public function decode_cursor(string $cursor_b64): string {
        $cursor_json = base64_decode($cursor_b64, true);
        if ($cursor_json === false) {
            throw new InvalidArgumentException(
                'Cursor must be base64-encoded. Received invalid base64: ' . substr($cursor_b64, 0, 50)
            );
        }

        if (strncmp($cursor_json, "\x1f\x8b", 2) === 0) {
            $decoded_cursor = @gzdecode($cursor_json);
            if ($decoded_cursor === false) {
                throw new InvalidArgumentException('Cursor contains an invalid gzip stream.');
            }
            $cursor_json = $decoded_cursor;
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
     * Creates the pull-work budget selected by the embedding server.
     *
     * Staged and other no-budget endpoints bypass this factory in dispatch()
     * because their own protocols bound work in a different dimension.
     *
     * @param array<string, mixed> $config
     * @return mixed Budget value accepted by the configured endpoint handlers.
     */
    public function create_resource_budget(array $config) {
        return call_user_func($this->budget_factory, $config);
    }

    /**
     * Invokes the configured handler for one normalized endpoint request.
     *
     * Endpoints in $no_budget_endpoints receive only the configuration. Every
     * other handler receives the configuration and either the supplied budget
     * or a value created lazily by create_resource_budget().
     *
     * @param array<string, mixed> $config
     * @param mixed $budget Optional caller-created pull-work budget.
     *
     * @throws InvalidArgumentException If the endpoint is absent or unregistered.
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
        if (in_array($endpoint, $this->no_budget_endpoints, true)) {
            call_user_func($handler, $config);
            return;
        }

        if ($budget === null) {
            $budget = $this->create_resource_budget($config);
        }

        call_user_func($handler, $config, $budget);
    }

    /**
     * Returns the built-in pull and preflight handler map.
     *
     * Staged routes are registered separately because they exist only when the
     * constructor receives target-side staged configuration.
     *
     * @return array<string, callable> Endpoint names and their global callbacks.
     */
    private function default_handlers(): array {
        return [
            'file_index' => 'endpoint_file_index',
            'file_fetch' => 'endpoint_file_fetch',
            'sql_chunk' => 'endpoint_sql_chunk',
            'db_index' => 'endpoint_db_index',
            'preflight' => 'endpoint_preflight',
        ];
    }

    /**
     * Wire the multipart session routes to the shared dispatcher.
     *
     * Explicitly-passed handlers win over these, matching how the handlers
     * option replaces the default map. The old staged artifact routes are not
     * registered: there is one push protocol, not a compatibility fork.
     *
     * Every registered route also bypasses the pull ResourceBudget because its
     * own stream/session layer bounds parts, request work, and commit steps.
     *
     * @param Site_Export_Staged_Endpoints $endpoints Configured target lifecycle.
     */
    private function register_staged_handlers(Site_Export_Staged_Endpoints $endpoints): void {
        $routes = [
            'staged_session_create' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->session_create($config, $_SERVER));
            },
            'staged_session_upload' => static function (array $config) use ($endpoints): void {
                $input = @fopen('php://input', 'rb');
                try {
                    self::emit_json_response(
                        $endpoints->session_upload($config, $_SERVER, $input === false ? null : $input)
                    );
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                }
            },
            'staged_session_status' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->session_status($config, $_SERVER));
            },
            'staged_session_commit' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->session_commit($config, $_SERVER));
            },
            'staged_session_discard' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->session_discard($config, $_SERVER));
            },
        ];

        foreach ($routes as $endpoint => $handler) {
            if (!isset($this->handlers[$endpoint])) {
                $this->handlers[$endpoint] = $handler;
            }
            $this->no_budget_endpoints[] = $endpoint;
        }
    }

    /**
     * Emits an endpoint response envelope as application/json.
     *
     * @param array{http_code:int,body:array} $response Validated endpoint result.
     */
    private static function emit_json_response(array $response): void {
        http_response_code($response['http_code']);
        header('Content-Type: application/json');
        echo json_encode($response['body']);
    }

    /**
     * Indicates whether CONTENT_TYPE declares an application/json entity.
     *
     * Matching is case-insensitive and ignores parameters such as `charset`.
     *
     * @param array<string,mixed> $server Request server values.
     * @return bool True for application/json, otherwise false.
     */
    private function is_json_content_type(array $server): bool {
        $content_type = (string) ( $server['CONTENT_TYPE'] ?? '' );
        return strtolower(trim( (string) strtok($content_type, ';'))) === 'application/json';
    }

    /**
     * Creates the default time-and-memory budget for ordinary pull handlers.
     *
     * Request values are restricted to the server's supported ranges before
     * the current php.ini memory limit is converted to bytes. An unlimited
     * memory setting is represented by PHP_INT_MAX.
     *
     * @param array<string, mixed> $config
     * @return ResourceBudget Validated budget beginning at the current time.
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

    /**
     * Formats registered endpoint names for validation error messages.
     *
     * @return string Comma-separated names, each enclosed in single quotes.
     */
    private function get_valid_endpoints_message(): string {
        return "'" . implode("', '", array_keys($this->handlers)) . "'";
    }
}
