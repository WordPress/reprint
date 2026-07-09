<?php

use function WordPress\Reprint\Exporter\parse_size;

/**
 * HTTP dispatcher for the Site Export API.
 */
final class Site_Export_HTTP_Server {

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

    /** @var string[] Endpoints dispatched without a resource budget. */
    private $no_budget_endpoints = ['preflight'];

    public function __construct(array $options = []) {
        $this->handlers = $options['handlers'] ?? $this->default_handlers();
        $this->budget_factory = $options['budget_factory'] ?? [$this, 'default_budget_factory'];
        $this->body_reader = $options['body_reader'] ?? static function (): string {
            $body = file_get_contents('php://input');
            return $body === false ? '' : $body;
        };
        $this->cursor_header_name = $options['cursor_header_name'] ?? 'HTTP_X_EXPORT_CURSOR';
        $this->default_directory = $options['default_directory'] ?? null;

        if (isset($options['staged']) && is_array($options['staged'])) {
            $this->register_staged_handlers(new Site_Export_Staged_Endpoints($options['staged']));
        }
    }

    public function handle_request(array $request = []): void {
        $server = $request['server'] ?? $_SERVER;
        $get = $request['get'] ?? $_GET;
        $post = $request['post'] ?? $_POST;
        if (array_key_exists('body', $request)) {
            $body = (string) $request['body'];
        } else {
            $endpoint = (string) ( $get['endpoint'] ?? $post['endpoint'] ?? '' );
            // Data-plane staged routes carry raw bytes and must only be read
            // by their handlers. Other JSON requests still feed config parsing.
            $body = $endpoint !== 'staged_push' && $this->is_json_content_type($server)
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
     * @return array<string, callable>
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
     * Wire the staged artifact routes to the shared dispatcher.
     *
     * Explicitly-passed handlers win over these, matching how the
     * handlers option replaces the default map.
     */
    private function register_staged_handlers(Site_Export_Staged_Endpoints $endpoints): void {
        $routes = [
            'staged_push' => static function (array $config) use ($endpoints): void {
                $input = @fopen('php://input', 'rb');
                try {
                    self::emit_json_response(
                        $endpoints->push_stream($config, $_SERVER, $input === false ? null : $input)
                    );
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                }
            },
            'staged_finalize' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->finalize($config, $_SERVER));
            },
            'staged_status' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->status($config));
            },
            'staged_discard' => static function (array $config) use ($endpoints): void {
                self::emit_json_response($endpoints->discard($config, $_SERVER));
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
     * @param array{http_code:int,body:array} $response
     */
    private static function emit_json_response(array $response): void {
        http_response_code($response['http_code']);
        header('Content-Type: application/json');
        echo json_encode($response['body']);
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
